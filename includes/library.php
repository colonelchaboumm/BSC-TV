<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Library {
	const ORIGIN = '_bsc_tv_origin';
	const FEED = '_bsc_tv_feed';
	const AUTO = '_bsc_tv_auto_publish';
	const FALLBACK_DURATION = 6 * HOUR_IN_SECONDS;

	public static function expired( $event, $now ) {
		return $event['start'] && $now >= ( $event['end'] ?: $event['start'] + self::FALLBACK_DURATION );
	}
	public static function near( $event, $now ) {
		return $event['start'] && $event['start'] <= $now + 30 * MINUTE_IN_SECONDS && ! self::expired( $event, $now );
	}

	private static function key( $source, $channel, $id ) {
		return 'bsc-tv-' . hash( 'sha256', $source . ':' . $channel . ':' . $id );
	}

	private static function find( $key ) {
		$posts = get_posts( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'trash', BSC_TV_Content::ARCHIVED ), 'meta_key' => self::ORIGIN, 'meta_value' => $key, 'numberposts' => 1 ) );
		return $posts ? $posts[0] : null;
	}

	public static function show_identity( $event ) {
		$video = BSC_TV_Content::youtube_video_id( $event['url'] );
		return $video ? 'youtube:' . $video : untrailingslashit( $event['url'] ) . ':' . $event['start'];
	}

	public static function archive_snapshot( $channel, $event, $reason ) {
		if ( empty( $event['start'] ) || empty( $event['title'] ) ) {
			return;
		}
		$key = self::key( $channel['data']['platform'], $channel['id'], $event['id'] );
		$post = self::find( $key );
		$data = array_merge( $event, array( 'channel_id' => $channel['id'], 'platform' => $channel['data']['platform'], 'source' => $channel['data']['url'], 'bdd_id' => 0, 'creator' => get_the_title( $channel['id'] ) ) );
		$data['status'] = $reason;
		if ( $post && ( BSC_TV_Content::ARCHIVED !== $post->post_status || BSC_TV_Content::data( $post->ID ) === $data ) ) {
			return;
		}
		wp_insert_post( array(
			'ID' => $post ? $post->ID : 0,
			'post_type' => BSC_TV_Content::SHOW, 'post_status' => BSC_TV_Content::ARCHIVED, 'post_title' => wp_slash( $event['title'] ),
			'meta_input' => array( self::ORIGIN => $key, BSC_TV_Content::DATA => wp_slash( $data ) ),
		) );
	}

	public static function feed_index( $channel, $source ) {
		$index = array( 'keys' => array(), 'identities' => array() );
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW, array( 'publish', 'draft', 'pending', 'private', 'trash', BSC_TV_Content::ARCHIVED ) ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$key = get_post_meta( $post->ID, self::ORIGIN, true );
			if ( $key && $source === get_post_meta( $post->ID, self::FEED, true ) && $channel['id'] === ( $data['origin_channel'] ?? $data['channel_id'] ?? 0 ) ) {
				$index['keys'][ $key ] = $post;
			}
			if ( ! empty( $data['url'] ) && BSC_TV_Content::ARCHIVED !== $post->post_status ) {
				$index['identities'][ self::show_identity( $data ) ] = true;
			}
		}
		return $index;
	}

	public static function feed_event( $channel, $source, $event, $now, &$index ) {
		$key = self::key( 'feed:' . $source, $channel['id'], $event['id'] );
		$post = $index['keys'][ $key ] ?? null;
		if ( $post && in_array( $post->post_status, array( 'trash', 'private' ), true ) ) {
			return;
		}
		$cancelled = 'cancelled' === $event['status'];
		$ended = self::expired( $event, $now );
		if ( ! $post && ( $cancelled || $ended || $event['start'] < $now || $event['start'] > $now + BSC_TV_Sync::SCHEDULE_WINDOW ) ) {
			return;
		}
		$existing = $post ? BSC_TV_Content::data( $post->ID ) : array();
		if ( $cancelled || $ended ) {
			$existing['status'] = $cancelled ? 'cancelled' : 'ended';
			update_post_meta( $post->ID, BSC_TV_Content::DATA, wp_slash( $existing ) );
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => BSC_TV_Content::ARCHIVED ) );
			return;
		}
		$identity = self::show_identity( $event );
		if ( ! $post && isset( $index['identities'][ $identity ] ) ) {
			return;
		}
		$official = ! empty( $channel['data']['feed_official'] ) && 'upcoming' === $event['status'];
		$approved = $post && 'publish' === $post->post_status && ! get_post_meta( $post->ID, self::AUTO, true );
		$status = $official || $approved ? 'publish' : 'draft';
		$data = $event + array( 'channel_id' => $channel['id'], 'origin_channel' => $channel['id'], 'source' => $source, 'bdd_id' => 0, 'creator' => '' );
		$data['status'] = 'live' === ( $existing['status'] ?? '' ) && ! self::expired( $existing, $now ) ? 'live' : 'upcoming';
		$data['platform'] = BSC_TV_Content::platform_for_url( $data['url'] );
		$data['bdd_id'] = $existing['bdd_id'] ?? 0;
		$data['creator'] = $existing['creator'] ?? '';
		$data['channel_id'] = $existing['channel_id'] ?? $channel['id'];
		if ( $existing && $existing === $data && $post->post_status === $status && $post->post_title === $event['title'] ) {
			return;
		}
		$values = array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => $status, 'post_title' => wp_slash( $event['title'] ), 'meta_input' => array(
			self::ORIGIN => $key, self::FEED => $source, self::AUTO => $official && ! $approved ? 1 : 0,
			BSC_TV_Content::DATA => wp_slash( $data ),
		) );
		if ( $post ) {
			$values['ID'] = $post->ID;
		}
		$id = wp_insert_post( $values );
		if ( $id ) {
			$index['keys'][ $key ] = get_post( $id );
			$index['identities'][ $identity ] = true;
		}
	}

	public static function archive_shows( $now ) {
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW, array( 'publish', 'draft', 'pending' ) ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			if ( empty( $data['start'] ) ) {
				continue;
			}
			if ( 'publish' !== $post->post_status && ! get_post_meta( $post->ID, self::FEED, true ) ) {
				continue;
			}
			$video = BSC_TV_Content::youtube_video_id( $data['url'] );
			$state = BSC_TV_Sync::state( $post->ID, $video );
			if ( $video && isset( $state['video'] ) ) {
				$video_data = $state['video'];
				$reason = $video_data['status'];
				if ( in_array( $reason, array( 'ended', 'cancelled' ), true ) && 'live' !== $data['status'] ) {
					$data['status'] = $reason;
				} elseif ( 'live' === $reason && $now - ( $state['live_at'] ?? 0 ) < BSC_TV_Sync::MAX_LIVE_AGE ) {
					continue;
				} elseif ( 'upcoming' === $reason ) {
					$data['start'] = $video_data['start'];
					$data['end'] = $video_data['end'] ?? 0;
				}
			}
			if ( 'upcoming' === $data['status'] || 'live' === $data['status'] ) {
				if ( ! self::expired( $data, $now ) ) {
					continue;
				}
				$data['status'] = 'ended';
			}
			update_post_meta( $post->ID, BSC_TV_Content::DATA, wp_slash( $data ) );
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => BSC_TV_Content::ARCHIVED ) );
		}
	}
}
