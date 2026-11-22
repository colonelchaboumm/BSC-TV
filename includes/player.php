<?php

defined( 'ABSPATH' ) || exit;

/** A shared clock and references to existing shows. No video collection or storage. */
final class BSC_TV_Player {
	const SETTINGS = 'bsc_tv_player_settings';
	const STATE = 'bsc_tv_player_clock';
	const MEDIA = '_bsc_tv_player_media';
	const CHANNEL = '_bsc_tv_player_channel';
	const APPROVED = '_bsc_tv_player_was_public';
	const ACCESS = '_bsc_tv_player_access';
	const CACHE = 'bsc_tv_player_catalogue';
	const LOCK = 'bsc_tv_player_lock';

	public static function register() {
		wp_register_style( 'bsc-tv-player', BSC_TV_URL . 'assets/player.css', array(), BSC_TV_VERSION );
		wp_register_script( 'bsc-tv-player', BSC_TV_URL . 'assets/player.js', array(), BSC_TV_VERSION, true );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'save_post', array( __CLASS__, 'invalidate_post' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 10, 3 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate_meta' ), 10, 3 );
		}
		add_shortcode( 'bsc_tv_player', array( __CLASS__, 'render' ) );
	}

	public static function settings() {
		$input = (array) get_option( self::SETTINGS, array() );
		return array(
			'enabled' => ! empty( $input['enabled'] ),
			'recent' => in_array( $input['recent'] ?? '', array( 'strong', 'balanced', 'rotation' ), true ) ? $input['recent'] : 'strong',
			'live_priority' => ! empty( $input['live_priority'] ),
			'repeat_hours' => max( 0, min( 168, (float) ( $input['repeat_hours'] ?? 6 ) ) ),
			'tolerance' => max( 3, min( 30, (int) ( $input['tolerance'] ?? 8 ) ) ),
			'timezone' => wp_timezone_string(),
			'music' => class_exists( 'BSC_TV_Music' ) ? BSC_TV_Music::settings() : array(),
		);
	}

	public static function invalidate_post( $id, $post ) {
		if ( in_array( $post->post_type, array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ), true ) ) {
			delete_transient( self::CACHE );
			do_action( 'bsc_tv_player_catalogue_changed' );
		}
	}

	public static function invalidate_meta( $meta_id, $post_id, $key ) {
		if ( in_array( $key, array( self::MEDIA, self::CHANNEL, self::APPROVED, self::ACCESS, BSC_TV_Content::DATA, BSC_TV_Content::STATE ), true ) ) {
			delete_transient( self::CACHE );
			do_action( 'bsc_tv_player_catalogue_changed' );
		}
	}

	public static function transition( $new, $old, $post ) {
		if ( BSC_TV_Content::SHOW === $post->post_type && 'publish' === $old && BSC_TV_Content::ARCHIVED === $new ) {
			update_post_meta( $post->ID, self::APPROVED, 1 );
		}
		if ( BSC_TV_Content::SHOW === $post->post_type && in_array( $new, array( 'draft', 'pending', 'private', 'trash' ), true ) ) {
			delete_post_meta( $post->ID, self::APPROVED );
		}
		self::invalidate_post( $post->ID, $post );
	}

	public static function resource( $url, $live = false ) {
		$id = BSC_TV_Content::youtube_video_id( $url );
		if ( $id ) {
			return array( 'platform' => 'youtube', 'id' => $id );
		}
		if ( 'twitch' === BSC_TV_Content::platform_for_url( $url ) ) {
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( ! $live && preg_match( '~^videos/([0-9]{1,20})$~D', $path, $match ) ) {
				return array( 'platform' => 'twitch', 'id' => 'v' . $match[1] );
			}
			$channel = $live ? BSC_TV_Content::channel_key( 'twitch', $url ) : '';
			if ( $channel ) {
				return array( 'platform' => 'twitch', 'id' => $channel );
			}
		}
		return array();
	}

	public static function resource_key( $resource ) {
		return $resource['platform'] . ':' . $resource['id'];
	}

	/** Store official API flags without changing editorial content or creating a new show. */
	public static function remember_access( $videos, $now ) {
		if ( ! $videos ) { return; }
		foreach ( get_posts( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => array( 'publish', BSC_TV_Content::ARCHIVED ), 'posts_per_page' => 1000, 'has_password' => false, 'orderby' => 'modified', 'order' => 'DESC', 'update_post_term_cache' => false ) ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$media = (array) get_post_meta( $post->ID, self::MEDIA, true );
			$resource = self::resource( ( $media['url'] ?? '' ) ?: ( $data['url'] ?? '' ) );
			if ( ! $resource || 'youtube' !== $resource['platform'] || ! isset( $videos[ $resource['id'] ]['age_restricted'] ) ) { continue; }
			$video = $videos[ $resource['id'] ];
			update_post_meta( $post->ID, self::ACCESS, array( 'key' => self::resource_key( $resource ), 'age_restricted' => (bool) $video['age_restricted'], 'embeddable' => $video['embeddable'] ?? null, 'checked_at' => $now ) );
		}
	}

	private static function catalogue() {
		$catalogue = self::base_catalogue();
		if ( class_exists( 'BSC_TV_Music' ) ) {
			$music = BSC_TV_Music::pool();
			if ( $music ) {
				$deadlines = array();
				foreach ( $catalogue['channels'] as $channel ) {
					foreach ( $channel['state']['schedule'] ?? array() as $event ) { if ( 'upcoming' === ( $event['status'] ?? '' ) && ! empty( $event['start'] ) ) { $deadlines[] = (int) $event['start']; } }
				}
				foreach ( $music as &$item ) { $item['music_deadlines'] = array_slice( $deadlines, 0, 200 ); } unset( $item );
			}
			$catalogue['pool'] += $music;
			$catalogue['music_active'] = (bool) $music;
		}
		return $catalogue;
	}

	private static function base_catalogue() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$channels = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$key = BSC_TV_Content::channel_key( $data['platform'] ?? '', $data['url'] ?? '' );
			if ( ! $key || ! in_array( $data['platform'], array( 'youtube', 'twitch' ), true ) ) {
				continue;
			}
			$channels[ $post->ID ] = array(
				'id' => $post->ID, 'key' => $key, 'name' => get_the_title( $post ), 'data' => $data,
				'config' => (array) get_post_meta( $post->ID, self::CHANNEL, true ), 'state' => BSC_TV_Sync::state( $post->ID, $key ),
			);
		}
		$pool = array();
		$blocked = array();
		$manual_live = array();
		// Only the existing catalogue is queried. Drafts, private and trash never participate.
		$shows = get_posts( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => array( 'publish', BSC_TV_Content::ARCHIVED ), 'has_password' => false, 'posts_per_page' => 1000, 'orderby' => 'modified', 'order' => 'DESC', 'update_post_term_cache' => false ) );
		foreach ( $shows as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$media = (array) get_post_meta( $post->ID, self::MEDIA, true );
			$url = $media['url'] ?? '';
			$url = $url ?: ( $data['url'] ?? '' );
			$resource = self::resource( $url );
			$video_id = BSC_TV_Content::youtube_video_id( $data['url'] ?? '' );
			$source = self::resource( $data['url'] ?? '' );
			$state = BSC_TV_Sync::state( $post->ID, $video_id ?: ( $source ? self::resource_key( $source ) : '' ) );
			$video = $state['video'] ?? $data;
			$same_video = ! $media || empty( $media['url'] ) || self::resource( $data['url'] ?? '' ) === $resource;
			if ( $resource ) {
				$access = (array) get_post_meta( $post->ID, self::ACCESS, true );
				$resource_key = self::resource_key( $resource );
				$same_access = ( $access['key'] ?? '' ) === $resource_key;
				$manual_restriction = ! empty( $media['age_restricted'] ) && ( $media['restriction_key'] ?? '' ) === $resource_key;
				$age_restricted = $same_access ? ! empty( $access['age_restricted'] ) : ( $same_video && ! empty( $video['age_restricted'] ) );
				$embedding_denied = $same_access && isset( $access['embeddable'] ) ? false === $access['embeddable'] : ( $same_video && false === ( $video['embeddable'] ?? null ) );
				if ( $manual_restriction || $age_restricted || $embedding_denied ) { $blocked[ $resource_key ] = true; }
			}
			$channel_id = (int) ( $data['channel_id'] ?? $data['origin_channel'] ?? 0 );
			if ( ! isset( $channels[ $channel_id ] ) ) {
				$matches = array_filter( $channels, static function ( $channel ) use ( $data, $video ) {
					return BSC_TV_Content::channel_identity( $channel['data']['platform'], $channel['data']['url'] ) === BSC_TV_Content::channel_identity( $data['platform'] ?? '', $data['url'] ?? '' ) ||
						( ! empty( $video['channel_id'] ) && $video['channel_id'] === ( $channel['state']['channel_id'] ?? '' ) );
				} );
				$channel_id = 1 === count( $matches ) ? key( $matches ) : 0;
			}
			$channel = $channels[ $channel_id ] ?? array();
			if ( ! empty( $data['channel_id'] ) && ! $channel ) {
				continue;
			}
			$base = array( 'show_id' => $post->ID, 'channel_id' => $channel_id, 'title' => get_the_title( $post ), 'creator' => $data['creator'] ?? '', 'bdd_id' => $data['bdd_id'] ?? 0 );
			if ( 'publish' === $post->post_status && $channel && 'live' === ( $video['status'] ?? '' ) && ! empty( $state['live_at'] ) && $video_id ) {
				$manual_live[] = $base + array( 'event' => $video, 'confirmed_at' => $state['live_at'] );
			}
			$mode = $media['mode'] ?? 'auto';
			if ( ! $resource || 'no' === $mode || 'cancelled' === ( $data['status'] ?? '' ) || ( $channel && isset( $channel['config']['replays'] ) && ! $channel['config']['replays'] ) ) {
				continue;
			}
			// An old archived draft must not become public through the new widget.
			$approved = 'publish' === $post->post_status || get_post_meta( $post->ID, self::APPROVED, true ) || ( get_post_meta( $post->ID, BSC_TV_Library::ORIGIN, true ) && ! get_post_meta( $post->ID, BSC_TV_Library::FEED, true ) && $channel );
			$automatic = $approved && ! empty( $video['playable'] ) && 'ended' === ( $video['status'] ?? '' );
			if ( 'yes' !== $mode && ! $automatic ) {
				continue;
			}
			if ( isset( $blocked[ self::resource_key( $resource ) ] ) ) { continue; }
			$duration = (int) ( $media['duration'] ?? 0 );
			$duration = $duration ?: ( $same_video ? (int) ( $video['duration'] ?? 0 ) : 0 );
			$published = (int) ( $media['published_at'] ?? 0 );
			$published = $published ?: ( $same_video ? (int) ( $video['published_at'] ?? 0 ) : 0 );
			if ( $duration < 1 || $duration > DAY_IN_SECONDS || ! $published ) {
				continue;
			}
			$key = self::resource_key( $resource );
			if ( isset( $pool[ $key ] ) ) {
				continue;
			}
			$pool[ $key ] = $base + array( 'key' => $key, 'resource' => $resource, 'duration' => $duration, 'published_at' => $published, 'premiere' => ! empty( $media['premiere'] ), 'creator_key' => (string) ( $base['bdd_id'] ?: ( $channel['data']['bdd_id'] ?? 0 ) ?: ( $channel_id ?: sanitize_title( $base['creator'] ) ?: $key ) ) );
		}
		$pool = array_diff_key( $pool, $blocked );
		$result = array( 'pool' => $pool, 'channels' => $channels, 'manual_live' => $manual_live, 'blocked' => $blocked );
		set_transient( self::CACHE, $result, MINUTE_IN_SECONDS );
		return $result;
	}

	public static function media() {
		return self::catalogue()['pool'];
	}

	/** Enrich at most 50 already-known archives in the existing daily videos.list batch. */
	public static function enrichment_ids( $now ) {
		if ( ! self::settings()['enabled'] ) {
			return array();
		}
		$pending = array();
		foreach ( get_posts( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => array( 'publish', BSC_TV_Content::ARCHIVED ), 'posts_per_page' => 1000, 'has_password' => false, 'orderby' => 'modified', 'order' => 'DESC' ) ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$media = (array) get_post_meta( $post->ID, self::MEDIA, true );
			$video_id = BSC_TV_Content::youtube_video_id( $data['url'] ?? '' );
			$channel_id = (int) ( $data['channel_id'] ?? $data['origin_channel'] ?? 0 );
			$channel = BSC_TV_Content::public_channel( $channel_id );
			$approved = 'publish' === $post->post_status || get_post_meta( $post->ID, self::APPROVED, true ) || ( $channel && get_post_meta( $post->ID, BSC_TV_Library::ORIGIN, true ) && ! get_post_meta( $post->ID, BSC_TV_Library::FEED, true ) );
			$allowed = $channel ? (array) get_post_meta( $channel_id, self::CHANNEL, true ) : array();
			$state = BSC_TV_Sync::state( $post->ID, $video_id );
			if ( $video_id && $approved && 'ended' === ( $data['status'] ?? '' ) && 'no' !== ( $media['mode'] ?? 'auto' ) && empty( $media['url'] ) && ( ! isset( $allowed['replays'] ) || $allowed['replays'] ) && ( ! $channel_id || $channel ) && $now - ( $state['live_at'] ?? 0 ) >= DAY_IN_SECONDS ) {
				$pending[ $post->ID ] = array( 'id' => $video_id, 'checked' => $state['live_at'] ?? 0 );
			}
		}
		uasort( $pending, static function ( $a, $b ) { return $a['checked'] <=> $b['checked']; } );
		return array_map( static function ( $entry ) { return $entry['id']; }, array_slice( $pending, 0, 50, true ) );
	}

	private static function lives( $catalogue, $settings, $now ) {
		$music_active = ! empty( $settings['music']['enabled'] ) || ! empty( $catalogue['music_active'] );
		if ( ( ! $settings['live_priority'] && ! $music_active ) || empty( BSC_TV_Sync::settings()['live_enabled'] ) ) {
			return array();
		}
		$lives = array();
		$entries = $catalogue['manual_live'];
		foreach ( $catalogue['channels'] as $channel ) {
			foreach ( $channel['state']['live'] ?? array() as $event ) {
				$entries[] = array( 'channel_id' => $channel['id'], 'event' => $event, 'confirmed_at' => $channel['state']['live_at'] ?? 0 );
			}
		}
		foreach ( $entries as $entry ) {
			$channel = $catalogue['channels'][ $entry['channel_id'] ] ?? null;
			$event = $entry['event'];
			if ( ! empty( $event['age_restricted'] ) || false === ( $event['embeddable'] ?? null ) ) { continue; }
			if ( ! $channel || ( isset( $channel['config']['live'] ) && ! $channel['config']['live'] ) || $now - $entry['confirmed_at'] >= BSC_TV_Sync::MAX_LIVE_AGE || $entry['confirmed_at'] > $now + 30 || empty( $event['start'] ) || $event['start'] > $now || ( ! empty( $event['end'] ) && $event['end'] <= $now ) ) {
				continue;
			}
			$resource = self::resource( $event['url'] ?? '', true );
			if ( ! $resource || isset( $catalogue['blocked'][ self::resource_key( $resource ) ] ) ) {
				continue;
			}
			$key = 'live:' . self::resource_key( $resource ) . ':' . ( $event['id'] ?? $event['start'] );
			$lives[ $key ] = array( 'key' => $key, 'resource' => $resource, 'channel_id' => $channel['id'], 'show_id' => $entry['show_id'] ?? 0, 'title' => $event['title'], 'image' => BSC_TV_Content::url( $event['image'] ?? '' ), 'start' => (int) $event['start'], 'end' => 0, 'priority' => (int) ( $channel['config']['priority'] ?? 50 ) );
		}
		return $lives;
	}

	private static function bucket( $published, $now, $mode ) {
		$age = max( 0, $now - $published );
		if ( 'rotation' === $mode ) {
			return 0;
		}
		if ( 'balanced' === $mode ) {
			return $age <= WEEK_IN_SECONDS ? 0 : 1;
		}
		foreach ( array( DAY_IN_SECONDS, 3 * DAY_IN_SECONDS, WEEK_IN_SECONDS, 14 * DAY_IN_SECONDS, 30 * DAY_IN_SECONDS ) as $bucket => $limit ) {
			if ( $age <= $limit ) {
				return $bucket;
			}
		}
		return 5;
	}

	/** Pure selection; forecast and transitions use exactly the same rules. */
	public static function select( $pool, $state, $settings, $now ) {
		$candidates = array();
		foreach ( $pool as $key => $media ) {
			if ( ! empty( $media['music'] ) ) { continue; }
			$last = $state['history'][ $key ]['end'] ?? 0;
			$available = max( $media['published_at'], $last ? $last + (int) ( $settings['repeat_hours'] * HOUR_IN_SECONDS ) : 0 );
			$candidates[] = $media + array( 'available' => max( $now, $available ), 'last' => $last );
		}
		if ( ! $candidates ) {
			return null;
		}
		$first_time = min( array_column( $candidates, 'available' ) );
		$candidates = array_values( array_filter( $candidates, static function ( $item ) use ( $first_time ) { return $item['available'] === $first_time; } ) );
		foreach ( $state['manual'] ?? array() as $show_id ) {
			foreach ( $candidates as $candidate ) {
				if ( $show_id === $candidate['show_id'] ) {
					return $candidate + array( 'fixed' => true );
				}
			}
		}
		$bounds = self::day_bounds( $first_time, $settings['timezone'] ?? 'UTC' );
		$usage = array();
		$plays = array();
		$records = $state['aired'] ?? array();
		if ( ! $records && ! empty( $state['vod'] ) ) { $records[] = $state['vod']; }
		foreach ( $records as $row ) {
			if ( ! empty( $row['live'] ) ) { continue; }
			$seconds = max( 0, min( $row['end'], $bounds[1], $first_time ) - max( $row['start'], $bounds[0] ) );
			if ( ! $seconds ) { continue; }
			$channel = $row['mix_channel'] ?? self::mix_channel( $pool[ $row['key'] ] ?? $row );
			$usage[ $channel ] = ( $usage[ $channel ] ?? 0 ) + $seconds;
			$plays[ $row['key'] ] = ( $plays[ $row['key'] ] ?? 0 ) + 1;
		}
		$seed = ( $state['rotation_seed'] ?? 'bsc-tv' ) . ':' . $bounds[0] . ':' . $first_time;
		$ranks = array();
		foreach ( $candidates as $candidate ) { $ranks[ $candidate['key'] ] = hash( 'sha256', $seed . $candidate['key'] ); }
		$last_show = $state['last_show'] ?? $state['vod']['key'] ?? '';
		usort( $candidates, static function ( $a, $b ) use ( $settings, $first_time, $plays, $ranks, $last_show ) {
			return ( $plays[ $a['key'] ] ?? 0 ) <=> ( $plays[ $b['key'] ] ?? 0 ) ?: ( $a['key'] === $last_show ) <=> ( $b['key'] === $last_show ) ?: ( $a['last'] > 0 ) <=> ( $b['last'] > 0 ) ?: self::bucket( $a['published_at'], $first_time, $settings['recent'] ) <=> self::bucket( $b['published_at'], $first_time, $settings['recent'] ) ?: strcmp( $ranks[ $a['key'] ], $ranks[ $b['key'] ] );
		} );
		$channels = array();
		foreach ( $candidates as $candidate ) { $channel = self::mix_channel( $candidate ); if ( ! isset( $channels[ $channel ] ) ) { $channels[ $channel ] = $candidate; } }
		uksort( $channels, static function ( $a, $b ) use ( $usage, $channels, $settings, $first_time, $seed, $state ) {
			return ( $usage[ $a ] ?? 0 ) <=> ( $usage[ $b ] ?? 0 ) ?: ( $a === ( $state['last_channel'] ?? '' ) ) <=> ( $b === ( $state['last_channel'] ?? '' ) ) ?: ( $channels[ $a ]['creator_key'] === ( $state['last_creator'] ?? '' ) ) <=> ( $channels[ $b ]['creator_key'] === ( $state['last_creator'] ?? '' ) ) ?: self::bucket( $channels[ $a ]['published_at'], $first_time, $settings['recent'] ) <=> self::bucket( $channels[ $b ]['published_at'], $first_time, $settings['recent'] ) ?: ( $channels[ $a ]['last'] > 0 ) <=> ( $channels[ $b ]['last'] > 0 ) ?: strcmp( hash( 'sha256', $seed . $a ), hash( 'sha256', $seed . $b ) );
		} );
		return reset( $channels );
	}

	public static function mix_channel( $media ) {
		return ! empty( $media['channel_id'] ) ? 'channel:' . $media['channel_id'] : 'creator:' . ( $media['creator_key'] ?? $media['key'] );
	}

	public static function day_bounds( $timestamp, $timezone = 'UTC', $days = 1 ) {
		$date = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( $timezone ?: 'UTC' ) )->setTime( 0, 0 );
		return array( $date->getTimestamp(), $date->modify( '+' . (int) $days . ' days' )->getTimestamp() );
	}

	private static function log_slot( &$state, $slot, $start = null, $end = null, $live = false ) {
		$row = $slot;
		$row['start'] = $start ?? $slot['start'];
		$row['end'] = $end ?? $slot['end'];
		$row['live'] = $live;
		$row['mix_channel'] = $slot['mix_channel'] ?? self::mix_channel( $slot );
		$state['aired'][] = $row;
		$cutoff = $row['start'] - 3 * DAY_IN_SECONDS;
		$state['aired'] = array_slice( array_values( array_filter( $state['aired'], static function ( $entry ) use ( $cutoff ) { return ! $entry['end'] || $entry['end'] >= $cutoff; } ) ), -500 );
	}

	/** A persisted future slot has priority over the automatic rotation. */
	public static function select_next( $pool, $state, $settings, $cursor ) {
		$next = self::select_programme( $pool, $state, $settings, $cursor );
		if ( ! empty( $settings['music']['enabled'] ) && class_exists( 'BSC_TV_Music' ) ) {
			$music = BSC_TV_Music::between( $next, $pool, $state, $settings, $cursor );
			if ( $music ) { return $music; }
		}
		return $next;
	}

	private static function select_programme( $pool, $state, $settings, $cursor ) {
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( empty( $row['id'] ) || ! isset( $pool[ $row['key'] ] ) ) {
				continue;
			}
			$media = $pool[ $row['key'] ];
			if ( ! empty( $row['music'] ) ) { $media['music'] = $row['music']; $media['duration'] = $row['end'] - $row['start']; }
			$fixed = $row['fixed'] ?? true;
			$last = $state['history'][ $row['key'] ]['end'] ?? 0;
			$repeat = ! empty( $media['music'] ) ? ( $settings['music']['repeat_hours'] ?? 6 ) : $settings['repeat_hours'];
			$available = max( $cursor, $row['start'], $media['published_at'], $last ? $last + (int) ( $repeat * HOUR_IN_SECONDS ) : 0 );
			foreach ( $state['manual'] ?? array() as $show_id ) {
				foreach ( $pool as $priority ) {
					if ( $priority['show_id'] !== $show_id ) { continue; }
					$previous = $state['history'][ $priority['key'] ]['end'] ?? 0;
					$start = max( $cursor, $priority['published_at'], $previous ? $previous + (int) ( $settings['repeat_hours'] * HOUR_IN_SECONDS ) : 0 );
					if ( $start <= $available && $priority['key'] !== $row['key'] ) {
						return $priority + array( 'available' => $start, 'fixed' => true );
					}
					if ( $start <= $available ) { $fixed = true; break 2; }
				}
			}
			return $media + array( 'available' => $available, 'plan_id' => $row['id'], 'plan_end' => $row['end'], 'fixed' => $fixed );
		}
		return self::select( $pool, $state, $settings, $cursor );
	}

	public static function shift_plan( &$state, $seconds ) {
		foreach ( $state['plan'] ?? array() as $index => $row ) {
			$state['plan'][ $index ]['start'] += $seconds;
			$state['plan'][ $index ]['end'] += $seconds;
		}
	}

	public static function begin( $media, &$state ) {
		$slot = array_intersect_key( $media, array_fill_keys( array( 'show_id', 'key', 'resource', 'channel_id', 'premiere', 'music' ), true ) );
		$slot['start'] = $media['available'];
		$slot['end'] = $slot['start'] + $media['duration'];
		$slot['fixed'] = ! empty( $media['fixed'] );
		$slot['mix_channel'] = self::mix_channel( $media );
		if ( class_exists( 'BSC_TV_Music' ) ) { BSC_TV_Music::begun( $state, $slot ); }
		if ( isset( $media['plan_id'] ) ) {
			$slot['id'] = $media['plan_id'];
			$index = array_search( $media['plan_id'], array_column( $state['plan'], 'id' ), true );
			$state['plan'] = array_slice( $state['plan'], $index + 1 );
			self::shift_plan( $state, max( 0, $slot['end'] - $media['plan_end'] ) );
		} elseif ( ! empty( $state['plan'] ) ) {
			self::shift_plan( $state, max( 0, $slot['end'] - $state['plan'][0]['start'] ) );
		}
		$slot['premiere'] = $slot['premiere'] && empty( $state['premiered'][ $media['key'] ] );
		$state['premiered'][ $media['key'] ] = true;
		$state['history'][ $media['key'] ] = array( 'end' => $slot['end'] );
		if ( empty( $media['music'] ) ) {
			$state['last_creator'] = $media['creator_key'];
			$state['last_channel'] = self::mix_channel( $media );
			$state['last_show'] = $media['key'];
		}
		self::log_slot( $state, $slot );
		$state['manual'] = array_values( array_diff( $state['manual'] ?? array(), array( $media['show_id'] ) ) );
		return $slot;
	}

	/** Only this state, stored under a database lock, determines the common antenna. */
	public static function advance( $state, $pool, $lives, $settings, $now ) {
		if ( class_exists( 'BSC_TV_Music' ) ) { $state = BSC_TV_Music::reconcile( $state, $pool, $settings, $now ); }
		$selection_settings = $settings;
		if ( $lives ) {
			$selection_settings['music']['enabled'] = false;
			$state['plan'] = array_values( array_filter( $state['plan'] ?? array(), static function ( $row ) { return empty( $row['music'] ); } ) );
		}
		$state += array( 'vod' => null, 'live' => null, 'paused_at' => 0, 'history' => array(), 'manual' => array(), 'plan' => array(), 'last_creator' => '', 'premiered' => array() );
		if ( empty( $state['rotation_seed'] ) ) { $state['rotation_seed'] = bin2hex( random_bytes( 16 ) ); }
		if ( empty( $state['aired'] ) && ! empty( $state['vod'] ) ) { self::log_slot( $state, $state['vod'] ); }
		$state['plan'] = array_values( array_filter( $state['plan'], static function ( $row ) use ( $pool ) { return isset( $pool[ $row['key'] ] ); } ) );
		if ( $state['vod'] && ! isset( $pool[ $state['vod']['key'] ] ) ) {
			foreach ( $state['aired'] ?? array() as $index => $row ) { if ( $row['key'] === $state['vod']['key'] && $row['end'] > $now ) { $state['aired'][ $index ]['end'] = $now; } }
			$state['vod'] = null;
		}
		if ( $state['live'] && ! isset( $lives[ $state['live']['key'] ] ) ) {
			foreach ( $state['aired'] ?? array() as $index => $row ) {
				if ( ! empty( $row['live'] ) && $row['key'] === $state['live']['key'] && ! $row['end'] ) { $state['aired'][ $index ]['end'] = $now; }
			}
			$pause = $state['paused_at'] ? max( 0, $now - $state['paused_at'] ) : 0;
			self::shift_plan( $state, $pause );
			if ( $state['vod'] && $state['paused_at'] ) {
				$state['vod']['start'] += $pause;
				$state['vod']['end'] += $pause;
				$state['history'][ $state['vod']['key'] ]['end'] = $state['vod']['end'];
				self::log_slot( $state, $state['vod'], $now );
				if ( class_exists( 'BSC_TV_Music' ) ) { BSC_TV_Music::begun( $state, $state['vod'] ); }
			}
			$state['live'] = null;
			$state['paused_at'] = 0;
			if ( ! empty( $settings['music']['enabled'] ) ) { $state['plan_source'] = ''; }
		}
		if ( $state['live'] ) {
			return $state;
		}
		// After an intentional gap, a late visitor must join at the planned offset.
		if ( class_exists( 'BSC_TV_Music' ) && ! empty( $state['vod'] ) ) { BSC_TV_Music::tally( $state, $state['vod'], $now ); }
		$cursor = $state['vod']['end'] ?? ( $state['plan'][0]['start'] ?? $now );
		for ( $count = 0; $count < 200 && ( ! $state['vod'] || $state['vod']['end'] <= $now ); ++$count ) {
			$media = self::select_next( $pool, $state, $selection_settings, $cursor );
			if ( $media && $media['available'] > $now ) {
				$state['vod'] = null;
				break;
			}
			$state['vod'] = $media ? self::begin( $media, $state ) : null;
			if ( class_exists( 'BSC_TV_Music' ) && $state['vod'] ) { BSC_TV_Music::tally( $state, $state['vod'], $now ); }
			if ( ! $state['vod'] ) {
				break;
			}
			$cursor = $state['vod']['end'];
		}
		// Bound work after months without a visitor or cron; never replay a stale slot.
		if ( $state['vod'] && $state['vod']['end'] <= $now ) {
			$state['plan'] = array_values( array_filter( $state['plan'], static function ( $row ) use ( $now ) { return $row['end'] > $now; } ) );
			$media = self::select_next( $pool, $state, $selection_settings, $now );
			$state['vod'] = $media ? self::begin( $media, $state ) : null;
		}
		if ( $lives ) {
			if ( ! empty( $state['vod']['music'] ) ) {
				if ( 'finish' === ( $settings['music']['live_policy'] ?? '' ) && $state['vod']['end'] > $now ) { return $state; }
				BSC_TV_Music::interrupt( $state, $now );
			}
			usort( $lives, static function ( $a, $b ) { return $b['priority'] <=> $a['priority'] ?: $a['start'] <=> $b['start'] ?: strcmp( $a['key'], $b['key'] ); } );
			$state['live'] = $lives[0];
			$state['paused_at'] = $now;
			foreach ( $state['aired'] ?? array() as $index => $row ) {
				if ( empty( $row['live'] ) && $row['start'] <= $now && $row['end'] > $now ) { $state['aired'][ $index ]['end'] = $now; }
			}
			self::log_slot( $state, $state['live'], $now, 0, true );
		}
		$state['history'] = array_filter( $state['history'], static function ( $entry ) use ( $now ) { return $entry['end'] >= $now - 8 * DAY_IN_SECONDS; } );
		return $state;
	}

	private static function decorate( $item, $catalogue, $now, $live = false ) {
		if ( ! $item ) {
			return null;
		}
		$media = $catalogue['pool'][ $item['key'] ] ?? array();
		if ( ! empty( $item['music'] ) || ! empty( $media['music'] ) ) { return BSC_TV_Music::decorate( $item, $media, $now ); }
		$channel = $catalogue['channels'][ $item['channel_id'] ?? 0 ] ?? array();
		$show = ! empty( $item['show_id'] ) ? BSC_TV_Content::data( $item['show_id'] ) : array();
		$profile = BSC_TV_Content::profile( $media['bdd_id'] ?? $show['bdd_id'] ?? 0 ) ?: BSC_TV_Content::profile( $channel['data']['bdd_id'] ?? 0 );
		$image = ! empty( $item['show_id'] ) ? get_the_post_thumbnail_url( $item['show_id'], 'large' ) : '';
		$image = $image ?: ( $show['image'] ?? '' ) ?: ( $item['image'] ?? '' );
		$image = $image ?: ( $channel ? get_the_post_thumbnail_url( $channel['id'], 'large' ) : '' );
		$resource = $item['resource'];
		$url = 'youtube' === $resource['platform'] ? 'https://www.youtube.com/watch?v=' . $resource['id'] : ( $live ? 'https://www.twitch.tv/' . $resource['id'] : 'https://www.twitch.tv/videos/' . substr( $resource['id'], 1 ) );
		return array(
			'key' => $item['key'], 'show_id' => $item['show_id'] ?? 0, 'resource' => $resource, 'url' => $url, 'live' => $live,
			'title' => html_entity_decode( wp_strip_all_tags( $item['title'] ?? $media['title'] ?? 'BSC TV' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'creator' => html_entity_decode( wp_strip_all_tags( $profile['name'] ?? ( ( $media['creator'] ?? '' ) ?: ( $channel['name'] ?? '' ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'avatar' => BSC_TV_Content::url( $profile['avatar']['thumbnail'] ?? '' ), 'profile_url' => BSC_TV_Content::url( $profile['permalink'] ?? '' ),
			'image' => BSC_TV_Content::url( $image ?: ( $profile['avatar']['thumbnail'] ?? '' ) ),
			'start' => $item['start'], 'end' => $item['end'], 'offset' => $live ? 0 : max( 0, $now - $item['start'] ),
			'new' => ! $live && $now >= ( $media['published_at'] ?? PHP_INT_MAX ) && $now - ( $media['published_at'] ?? 0 ) < DAY_IN_SECONDS,
			'premiere' => ! $live && ! empty( $item['premiere'] ),
		);
	}

	/** Reconcile a freshly read clock while the caller holds the shared database lock. */
	public static function advance_clock( $state, $now ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) { return $state; }
		$catalogue = self::catalogue();
		$state = BSC_TV_Player_Schedule::before_advance( $state, $catalogue['pool'], $settings, $now );
		return self::advance( $state, $catalogue['pool'], self::lives( $catalogue, $settings, $now ), $settings, $now );
	}

	public static function snapshot( $now = null ) {
		$now = $now ?? time();
		$settings = self::settings();
		$result = array( 'enabled' => $settings['enabled'], 'server_time' => $now, 'timezone' => wp_timezone_string(), 'tolerance' => $settings['tolerance'], 'poll_after' => 60, 'current' => null, 'next' => array() );
		if ( ! $settings['enabled'] ) {
			return $result;
		}
		$catalogue = self::catalogue();
		$lives = self::lives( $catalogue, $settings, $now );
		$state = (array) get_option( self::STATE, array() );
		$updated = self::advance( $state, $catalogue['pool'], $lives, $settings, $now );
		if ( $updated !== $state || BSC_TV_Player_Schedule::needs_refresh( $updated, $catalogue['pool'], $settings, $now ) ) {
			$lock = BSC_TV_Content::lock( self::LOCK );
			if ( $lock ) {
				try {
					// Another request may have filled the clock after our first read.
					wp_cache_delete( self::STATE, 'options' );
					wp_cache_delete( 'notoptions', 'options' );
					$state = BSC_TV_Player_Schedule::before_advance( (array) get_option( self::STATE, array() ), $catalogue['pool'], $settings, $now );
					$state = self::advance( $state, $catalogue['pool'], $lives, $settings, $now );
					$state = BSC_TV_Player_Schedule::replenish( $state, $catalogue['pool'], $settings, $now );
					update_option( self::STATE, $state, false );
				} finally {
					BSC_TV_Content::unlock( self::LOCK, $lock );
				}
			}
		}
		$live = $state['live'] ?? null;
		$vod = $state['vod'] ?? null;
		if ( $live && isset( $lives[ $live['key'] ] ) ) {
			$live['image'] = $lives[ $live['key'] ]['image'] ?? '';
			$result['current'] = self::decorate( $live, $catalogue, $now, true );
		} elseif ( $vod && isset( $catalogue['pool'][ $vod['key'] ] ) && $vod['start'] <= $now && $vod['end'] > $now ) {
			$result['current'] = self::decorate( $vod, $catalogue, $now );
		}
		$forecast = $state;
		$cursor = max( $now, $vod['end'] ?? $now );
		if ( $vod && ( $live || $vod['start'] > $now ) && isset( $catalogue['pool'][ $vod['key'] ] ) ) {
			$next = self::decorate( $vod, $catalogue, $now );
			$next['after_live'] = (bool) $live;
			$result['next'][] = $next;
		}
		for ( $count = count( $result['next'] ); $count < 5; ++$count ) {
			$media = self::select_next( $catalogue['pool'], $forecast, $settings, $cursor );
			if ( ! $media ) {
				break;
			}
			$slot = self::begin( $media, $forecast );
			$next = self::decorate( $slot, $catalogue, $now );
			$next['after_live'] = (bool) $live;
			$result['next'][] = $next;
			$cursor = $slot['end'];
		}
		return $result;
	}

	public static function rest() {
		register_rest_route( 'bsc-tv/v1', '/player', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => static function () {
			$response = new WP_REST_Response( self::snapshot() );
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			return $response;
		} ) );
	}

	/** Public, approved programmes for today, using the shared clock without collection. */
	public static function day_snapshot( $now = null ) {
		$now = $now ?? time();
		$snapshot = self::snapshot( $now );
		$bounds = self::day_bounds( $now, $snapshot['timezone'] );
		$result = array( 'enabled' => $snapshot['enabled'], 'server_time' => $now, 'timezone' => $snapshot['timezone'], 'date' => wp_date( 'Y-m-d', $now ), 'date_label' => wp_date( 'l j F Y', $now ), 'day_start' => $bounds[0], 'day_end' => $bounds[1], 'poll_after' => 60, 'items' => array() );
		if ( ! $snapshot['enabled'] ) { return $result; }
		$catalogue = self::catalogue();
		$state = (array) get_option( self::STATE, array() );
		$days = BSC_TV_Player_Schedule::days( $state, $now, $snapshot['timezone'] );
		$today = reset( $days );
		$boundary = $bounds[1];
		foreach ( $today['rows'] as $row ) {
			$live = ! empty( $row['live'] );
			if ( ! $live && isset( $catalogue['pool'][ $row['key'] ] ) ) { $row['channel_id'] = $catalogue['pool'][ $row['key'] ]['channel_id']; }
			$channel = $catalogue['channels'][ $row['channel_id'] ?? 0 ] ?? array();
			if ( ! $live && ! isset( $catalogue['pool'][ $row['key'] ] ) ) { continue; }
			if ( $live ) {
				if ( ! $channel || ( isset( $channel['config']['live'] ) && ! $channel['config']['live'] ) ) { continue; }
				if ( ! empty( $row['show_id'] ) ) {
					$post = get_post( $row['show_id'] );
					$approved = $post && ( 'publish' === $post->post_status || ( BSC_TV_Content::ARCHIVED === $post->post_status && ( get_post_meta( $post->ID, self::APPROVED, true ) || ( get_post_meta( $post->ID, BSC_TV_Library::ORIGIN, true ) && ! get_post_meta( $post->ID, BSC_TV_Library::FEED, true ) ) ) ) );
					if ( ! $approved || $post->post_password ) { continue; }
				}
			}
			$item = self::decorate( $row, $catalogue, $now, $live );
			if ( ! empty( $row['music'] ) ) { $item['start'] = $row['start']; $item['end'] = $row['end']; }
			$item['phase'] = $row['phase'];
			$item['on_air'] = 'current' === $row['phase'] && ( $snapshot['current']['key'] ?? '' ) === $row['key'];
			if ( $live && 'current' === $row['phase'] && ! $item['on_air'] ) { continue; }
			$item['display_start'] = $row['display_start'];
			$item['display_end'] = $row['display_end'];
			$item['continued'] = $row['continued'];
			$item['provisional'] = 'future' === $row['phase'] && ! empty( $snapshot['current']['live'] );
			$item['channel'] = html_entity_decode( wp_strip_all_tags( $channel['name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$show = ! empty( $row['show_id'] ) ? BSC_TV_Content::data( $row['show_id'] ) : array();
			$image = ! empty( $row['show_id'] ) ? get_the_post_thumbnail_url( $row['show_id'], 'medium' ) : '';
			$image = $image ?: ( $show['image'] ?? '' );
			$image = $image ?: ( $row['music']['image'] ?? '' );
			$image = $image ?: ( $item['on_air'] && $live ? ( $snapshot['current']['image'] ?? '' ) : ( $row['image'] ?? '' ) );
			$image = $image ?: ( $channel ? get_the_post_thumbnail_url( $channel['id'], 'thumbnail' ) : '' );
			$item['image'] = BSC_TV_Content::url( $image ?: $item['avatar'] );
			$result['items'][] = $item;
			foreach ( array( $row['start'], $row['end'] ) as $transition ) { if ( $transition > $now ) { $boundary = min( $boundary, $transition ); } }
		}
		$result['poll_after'] = max( 5, min( 60, $boundary - $now + 1 ) );
		return $result;
	}

	public static function render( $settings = array() ) {
		wp_enqueue_style( 'bsc-tv-player' );
		wp_enqueue_script( 'bsc-tv-player' );
		$settings = (array) $settings;
		// Empty legacy autoplay settings follow the new automatic default.
		$settings['start_mode'] = $settings['start_mode'] ?? ( in_array( $settings['autoplay'] ?? null, array( 'no', '0', 0, false ), true ) ? 'click' : 'auto' );
		$defaults = array_fill_keys( array( 'show_logo', 'show_avatar', 'show_poster', 'show_creator', 'show_title', 'show_profile', 'show_next', 'show_progress', 'show_times', 'show_on_air', 'show_live', 'show_new', 'show_premiere', 'show_replay', 'show_music', 'show_music_meta' ), 'yes' );
		$settings += $defaults + array( 'title' => 'BSC TV · Live 24/7', 'muted' => '', 'join_label' => 'Rejoindre le direct TV', 'logo' => array(), 'transition_enabled' => 'yes', 'transition_video' => array(), 'transition_timeout' => 20 );
		$settings['autoplay'] = 'click' === $settings['start_mode'] ? '' : 'yes';
		$join_label = is_scalar( $settings['join_label'] ) ? sanitize_text_field( $settings['join_label'] ) : '';
		$logo = BSC_TV_Content::url( $settings['logo']['url'] ?? '' );
		$poster = 'yes' === $settings['show_poster'] ? ( self::snapshot()['current']['image'] ?? '' ) : '';
		$transition_media = $settings['transition_video'];
		$transition_value = is_array( $transition_media ) ? ( $transition_media['url'] ?? '' ) : $transition_media;
		$transition_url = is_scalar( $transition_value ) ? BSC_TV_Content::url( $transition_value ) : '';
		$transition_url = $transition_url ?: BSC_TV_URL . 'assets/media/transition.mp4';
		$transition_timeout = is_numeric( $settings['transition_timeout'] ) ? max( 5, min( 60, (int) $settings['transition_timeout'] ) ) : 20;
		ob_start();
		require BSC_TV_DIR . 'includes/player-view.php';
		return ob_get_clean();
	}
}
