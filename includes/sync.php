<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Sync {
	const SETTINGS = 'bsc_tv_settings';
	const MAX_LIVE_AGE = 20 * MINUTE_IN_SECONDS;
	const SCHEDULE_WINDOW = 30 * DAY_IN_SECONDS;
	const DISCOVERY_INTERVAL = 6 * HOUR_IN_SECONDS;
	const LOCK = 'bsc_tv_sync_lock';
	const QUOTA = 'bsc_tv_youtube_search_quota';

	public static function settings() {
		$values = (array) get_option( self::SETTINGS, array() );
		foreach ( array( 'youtube_key', 'twitch_id', 'twitch_secret', 'kick_id', 'kick_secret' ) as $key ) {
			$constant = 'BSC_TV_' . strtoupper( $key );
			$values[ $key ] = defined( $constant ) ? (string) constant( $constant ) : (string) ( $values[ $key ] ?? '' );
		}
		return $values;
	}

	public static function schedules( $schedules ) {
		$schedules['bsc_tv_five_minutes'] = array( 'interval' => 300, 'display' => 'BSC TV · Toutes les 5 minutes' );
		return $schedules;
	}

	public static function activate() {
		if ( ! wp_next_scheduled( 'bsc_tv_sync' ) ) {
			wp_schedule_event( time() + 30, 'bsc_tv_five_minutes', 'bsc_tv_sync' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'bsc_tv_sync' );
	}

	public static function run( $manual = false ) {
		$lock = BSC_TV_Content::lock( self::LOCK );
		if ( ! $lock ) {
			return false;
		}

		try {
			$settings = self::settings();
			$now = time();
			$channels = array();
			foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
				$data = BSC_TV_Content::data( $post->ID );
				$key = BSC_TV_Content::channel_key( $data['platform'] ?? '', $data['url'] ?? '' );
				if ( $key ) {
					$state = self::state( $post->ID, $key );
					$channels[ $data['platform'] ][ $post->ID ] = array( 'id' => $post->ID, 'key' => $key, 'data' => $data, 'state' => $state );
				}
			}
			foreach ( array( 'twitch', 'kick' ) as $platform ) {
				if ( ! empty( $channels[ $platform ] ) && $settings[ $platform . '_id' ] && $settings[ $platform . '_secret' ] ) {
					self::$platform( $channels[ $platform ], $settings, $now, $manual );
				}
			}
			if ( $settings['youtube_key'] ) {
				self::youtube( $channels['youtube'] ?? array(), $settings['youtube_key'], $now, $manual );
			}
			update_option( 'bsc_tv_last_sync', $now, false );
			return true;
		} finally {
			BSC_TV_Content::unlock( self::LOCK, $lock );
		}
	}

	public static function state( $id, $key ) {
		$state = (array) get_post_meta( $id, BSC_TV_Content::STATE, true );
		return $key && $key === ( $state['key'] ?? null ) ? $state : array( 'key' => $key );
	}

	private static function save( $channel, $changes ) {
		$state = array_merge( $channel['state'], $changes );
		update_post_meta( $channel['id'], BSC_TV_Content::STATE, wp_slash( $state ) );
		return $state;
	}

	private static function request( $url, $headers = array(), $body = null, $empty_on_404 = false ) {
		$args = array( 'timeout' => 7, 'redirection' => 0, 'headers' => $headers );
		$response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args + array( 'body' => $body ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bsc_tv_api', 'Connexion API indisponible.' );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $empty_on_404 && 404 === $code ) {
			return array( 'data' => array() );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			return new WP_Error( 'bsc_tv_api', sprintf( 'Réponse API HTTP %d.', $code ), $code );
		}
		return $json;
	}

	private static function oauth( $platform, $settings ) {
		$id = $settings[ $platform . '_id' ];
		$secret = $settings[ $platform . '_secret' ];
		$cache = 'bsc_tv_token_' . $platform . '_' . md5( $id . ':' . $secret );
		$token = get_transient( $cache );
		if ( $token ) {
			return $token;
		}
		$url = 'twitch' === $platform ? 'https://id.twitch.tv/oauth2/token' : 'https://id.kick.com/oauth/token';
		$response = self::request( $url, array(), array( 'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret ) );
		if ( is_wp_error( $response ) || empty( $response['access_token'] ) ) {
			return is_wp_error( $response ) ? $response : new WP_Error( 'bsc_tv_token', 'Jeton API absent.' );
		}
		$token = $response['access_token'];
		set_transient( $cache, $token, max( 1, (int) ( $response['expires_in'] ?? 3600 ) - 90 ) );
		return $token;
	}

	private static function forget_oauth( $platform, $settings, $error ) {
		if ( 401 === $error->get_error_data() ) {
			delete_transient( 'bsc_tv_token_' . $platform . '_' . md5( $settings[ $platform . '_id' ] . ':' . $settings[ $platform . '_secret' ] ) );
		}
	}

	private static function indexed( $items, $field ) {
		$result = array();
		foreach ( (array) $items as $item ) {
			if ( isset( $item[ $field ] ) ) {
				$result[ strtolower( (string) $item[ $field ] ) ] = $item;
			}
		}
		return $result;
	}

	private static function repeated( $parameter, $values ) {
		return implode( '&', array_map( static function ( $value ) use ( $parameter ) {
			return $parameter . '=' . rawurlencode( $value );
		}, array_unique( $values ) ) );
	}

	private static function error( $channels, $error, $field ) {
		foreach ( $channels as $channel ) {
			self::save( $channel, array( $field => $error->get_error_message() ) );
		}
	}

	private static function event( $id, $title, $url, $image, $start, $end = 0 ) {
		return array(
			'id' => (string) $id, 'title' => sanitize_text_field( $title ), 'url' => BSC_TV_Content::url( $url ),
			'image' => BSC_TV_Content::url( $image ), 'start' => (int) $start, 'end' => (int) $end,
		);
	}

	private static function matches( $channel, $text ) {
		$keywords = array_filter( array_map( 'trim', explode( ',', remove_accents( $channel['data']['keywords'] ?? '' ) ) ) );
		foreach ( $keywords as $keyword ) {
			if ( false !== stripos( remove_accents( $text ), $keyword ) ) {
				return true;
			}
		}
		return ! $keywords;
	}

	private static function twitch( $channels, $settings, $now, $manual ) {
		$token = self::oauth( 'twitch', $settings );
		if ( is_wp_error( $token ) ) {
			self::error( $channels, $token, 'live_error' );
			return;
		}
		$headers = array( 'Authorization' => 'Bearer ' . $token, 'Client-Id' => $settings['twitch_id'] );
		$api = 'https://api.twitch.tv/helix/';
		foreach ( array_chunk( $channels, 100, true ) as $group ) {
			$logins = array_column( $group, 'key' );
			$streams = self::request( $api . 'streams?first=100&' . self::repeated( 'user_login', $logins ), $headers );
			if ( is_wp_error( $streams ) ) {
				self::error( $group, $streams, 'live_error' );
				self::forget_oauth( 'twitch', $settings, $streams );
				continue;
			}
			$streams = self::indexed( $streams['data'] ?? array(), 'user_login' );
			foreach ( $group as $id => $channel ) {
				$stream = $streams[ $channel['key'] ] ?? array();
				$live = array();
				if ( 'live' === ( $stream['type'] ?? '' ) && self::matches( $channel, $stream['title'] . ' ' . ( $stream['game_name'] ?? '' ) ) ) {
					$image = strtr( $stream['thumbnail_url'] ?? '', array( '{width}' => '1280', '{height}' => '720' ) );
					$live[] = self::event( $stream['id'], $stream['title'], 'https://www.twitch.tv/' . $channel['key'], $image, BSC_TV_Content::parse_date( $stream['started_at'], false ) );
				}
				$channels[ $id ]['state'] = self::save( $channel, array( 'live' => $live, 'live_at' => $now, 'live_error' => '' ) );
			}
		}
		uasort( $channels, static function ( $left, $right ) {
			return ( $left['state']['schedule_attempt'] ?? 0 ) <=> ( $right['state']['schedule_attempt'] ?? 0 );
		} );
		$due = array_slice( array_filter( $channels, static function ( $channel ) use ( $now, $manual ) {
			return $manual || $now - ( $channel['state']['schedule_attempt'] ?? 0 ) >= 30 * MINUTE_IN_SECONDS;
		} ), 0, 5 );
		if ( ! $due ) {
			return;
		}
		$users = self::request( $api . 'users?' . self::repeated( 'login', array_column( $due, 'key' ) ), $headers );
		if ( is_wp_error( $users ) ) {
			self::error( $due, $users, 'schedule_error' );
			return;
		}
		$users = self::indexed( $users['data'] ?? array(), 'login' );
		foreach ( $due as $channel ) {
			$user = $users[ $channel['key'] ] ?? array();
			$changes = array( 'schedule_attempt' => $now );
			if ( ! $user ) {
				self::save( $channel, $changes + array( 'schedule' => array(), 'schedule_error' => 'Chaîne Twitch introuvable.' ) );
				continue;
			}
			$events = self::twitch_schedule( $api, $headers, $channel, $user, $now );
			self::save( $channel, is_wp_error( $events ) ? $changes + array( 'schedule_error' => $events->get_error_message() ) : $changes + array( 'schedule' => $events, 'schedule_error' => '' ) );
		}
	}

	private static function twitch_schedule( $api, $headers, $channel, $user, $now ) {
		$events = array();
		$after = '';
		$base = add_query_arg( array( 'broadcaster_id' => $user['id'], 'first' => 25, 'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', $now ) ), $api . 'schedule' );
		for ( $page = 0; $page < 3; ++$page ) {
			$result = self::request( $after ? add_query_arg( 'after', rawurlencode( $after ), $base ) : $base, $headers, null, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$vacation = $result['data']['vacation'] ?? array();
			foreach ( (array) ( $result['data']['segments'] ?? array() ) as $segment ) {
				$start = BSC_TV_Content::parse_date( $segment['start_time'], false );
				$end = BSC_TV_Content::parse_date( $segment['end_time'], false );
				if ( $start >= $now + self::SCHEDULE_WINDOW ) {
					return $events;
				}
				if ( $start < $now || ! empty( $segment['canceled_until'] ) || ( $vacation && $start >= BSC_TV_Content::parse_date( $vacation['start_time'], false ) && $start < BSC_TV_Content::parse_date( $vacation['end_time'], false ) ) ) {
					continue;
				}
				$title = $segment['title'] ?: 'Direct Twitch';
				if ( self::matches( $channel, $title . ' ' . ( $segment['category']['name'] ?? '' ) ) ) {
				$events[] = self::event( $segment['id'], $title, 'https://www.twitch.tv/' . $channel['key'], ( $user['offline_image_url'] ?? '' ) ?: ( $user['profile_image_url'] ?? '' ), $start, $end );
				}
			}
			$after = $result['pagination']['cursor'] ?? '';
			if ( ! $after ) {
				break;
			}
		}
		return $events;
	}

	private static function kick( $channels, $settings, $now ) {
		$token = self::oauth( 'kick', $settings );
		if ( is_wp_error( $token ) ) {
			self::error( $channels, $token, 'live_error' );
			return;
		}
		foreach ( array_chunk( $channels, 50 ) as $group ) {
			$result = self::request( 'https://api.kick.com/public/v1/channels?' . self::repeated( 'slug', array_column( $group, 'key' ) ), array( 'Authorization' => 'Bearer ' . $token ) );
			if ( is_wp_error( $result ) ) {
				self::error( $group, $result, 'live_error' );
				self::forget_oauth( 'kick', $settings, $result );
				continue;
			}
			$items = self::indexed( $result['data'] ?? array(), 'slug' );
			foreach ( $group as $channel ) {
				$item = $items[ $channel['key'] ] ?? array();
				$live = array();
				if ( ! empty( $item['stream']['is_live'] ) && self::matches( $channel, $item['stream_title'] . ' ' . ( $item['category']['name'] ?? '' ) ) ) {
					$start = BSC_TV_Content::parse_date( $item['stream']['start_time'], false );
					$live[] = self::event( $channel['key'] . ':' . $start, $item['stream_title'], 'https://kick.com/' . $channel['key'], $item['stream']['thumbnail'] ?? $item['banner_picture'] ?? '', $start );
				}
				self::save( $channel, array( 'live' => $live, 'live_at' => $now, 'live_error' => $item ? '' : 'Chaîne Kick introuvable.' ) );
			}
		}
	}

	private static function yt_request( $endpoint, $key, $params ) {
		return self::request( add_query_arg( $params + array( 'key' => rawurlencode( $key ) ), 'https://www.googleapis.com/youtube/v3/' . $endpoint ) );
	}

	private static function youtube( $channels, $key, $now, $manual ) {
		uasort( $channels, static function ( $left, $right ) {
			return ( $left['state']['schedule_attempt'] ?? 0 ) <=> ( $right['state']['schedule_attempt'] ?? 0 );
		} );
		$attempts = 0;
		foreach ( $channels as $id => $channel ) {
			if ( ! $manual && $now - ( $channel['state']['schedule_attempt'] ?? 0 ) < self::DISCOVERY_INTERVAL ) {
				continue;
			}
			if ( ++$attempts > 3 || ! self::yt_budget( $now, 2 ) ) {
				break;
			}
			$changes = self::yt_discover( $channel, $key );
			$channels[ $id ]['state'] = self::save( $channel, $changes + array( 'schedule_attempt' => $now ) );
		}

		$video_ids = array();
		$manual_shows = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$video = BSC_TV_Content::youtube_video_id( $data['url'] ?? '' );
			if ( $video && 'cancelled' !== ( $data['status'] ?? '' ) && ( $data['start'] ?? 0 ) >= $now - 2 * DAY_IN_SECONDS && $data['start'] < $now + self::SCHEDULE_WINDOW ) {
				$video_ids[] = $video;
				$manual_shows[ $post->ID ] = $video;
			}
		}
		foreach ( $channels as $channel ) {
			$video_ids = array_merge( $video_ids, $channel['state']['ids'] ?? array() );
		}
		$videos = array();
		$failed = array();
		foreach ( array_chunk( array_unique( $video_ids ), 50 ) as $group ) {
			$result = self::yt_request( 'videos', $key, array( 'part' => 'snippet,liveStreamingDetails', 'id' => implode( ',', $group ) ) );
			if ( is_wp_error( $result ) ) {
				$failed += array_fill_keys( $group, $result->get_error_message() );
				continue;
			}
			foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
				$videos[ $item['id'] ] = self::yt_event( $item );
			}
		}
		foreach ( $manual_shows as $post_id => $video ) {
			if ( ! isset( $failed[ $video ] ) ) {
				$event = $videos[ $video ] ?? array( 'status' => 'ended' );
				update_post_meta( $post_id, BSC_TV_Content::STATE, wp_slash( array( 'key' => $video, 'live_at' => $now, 'video' => $event ) ) );
			}
		}
		foreach ( $channels as $channel ) {
			$ids = $channel['state']['ids'] ?? array();
			$errors = array_intersect_key( $failed, array_fill_keys( $ids, true ) );
			if ( $errors ) {
				self::save( $channel, array( 'live_error' => reset( $errors ) ) );
				continue;
			}
			$live = array();
			$schedule = array();
			$tracked = array();
			foreach ( $ids as $id ) {
				$event = $videos[ $id ] ?? null;
				if ( ! $event || 'ended' === $event['status'] ) {
					continue;
				}
				$tracked[] = $id;
				if ( empty( $channel['state']['channel_id'] ) || $event['channel_id'] !== $channel['state']['channel_id'] || ! self::matches( $channel, $event['title'] ) ) {
					continue;
				}
				if ( 'live' === $event['status'] ) {
					$live[] = $event;
				} elseif ( 'upcoming' === $event['status'] ) {
					$schedule[] = $event;
				}
			}
			self::save( $channel, array( 'live' => $live, 'schedule' => $schedule, 'ids' => $tracked, 'live_at' => $now, 'live_error' => '' ) );
		}
	}

	private static function yt_budget( $now, $cost ) {
		$date = wp_date( 'Y-m-d', $now, new DateTimeZone( 'America/Los_Angeles' ) );
		$quota = (array) get_option( self::QUOTA, array() );
		$count = $date === ( $quota['date'] ?? '' ) ? (int) $quota['count'] : 0;
		$limit = min( 10000, max( 2, absint( self::settings()['youtube_search_limit'] ?? 90 ) ) );
		if ( $count + $cost > $limit ) {
			return false;
		}
		update_option( self::QUOTA, array( 'date' => $date, 'count' => $count + $cost ), false );
		return true;
	}

	private static function yt_discover( $channel, $key ) {
		$state = $channel['state'];
		$channel_id = $state['channel_id'] ?? '';
		if ( ! $channel_id ) {
			$identity = $channel['key'];
			if ( 0 === strpos( $identity, '@' ) ) {
				$param = array( 'forHandle' => rawurlencode( $identity ) );
			} elseif ( 0 === strpos( $identity, 'user:' ) ) {
				$param = array( 'forUsername' => rawurlencode( substr( $identity, 5 ) ) );
			} else {
				$param = array( 'id' => $identity );
			}
			$result = self::yt_request( 'channels', $key, $param + array( 'part' => 'id' ) );
			$channel_id = $result['items'][0]['id'] ?? '';
			if ( is_wp_error( $result ) || ! $channel_id ) {
				return array( 'schedule_error' => is_wp_error( $result ) ? $result->get_error_message() : 'Chaîne YouTube introuvable.' );
			}
		}
		$ids = $state['ids'] ?? array();
		$changes = array( 'channel_id' => $channel_id, 'schedule_error' => '' );
		foreach ( array( 'upcoming', 'live' ) as $type ) {
			$result = self::yt_request( 'search', $key, array( 'part' => 'snippet', 'type' => 'video', 'channelId' => $channel_id, 'eventType' => $type, 'maxResults' => 50 ) );
			if ( is_wp_error( $result ) ) {
				$changes['schedule_error'] = $result->get_error_message();
				break;
			}
			foreach ( $result['items'] ?? array() as $result_item ) {
				if ( ! empty( $result_item['id']['videoId'] ) ) {
					$ids[] = $result_item['id']['videoId'];
				}
			}
		}
		return $changes + array( 'ids' => array_values( array_unique( $ids ) ) );
	}

	private static function yt_event( $item ) {
		$detail = $item['liveStreamingDetails'] ?? array();
		$snippet = $item['snippet'] ?? array();
		$flag = $snippet['liveBroadcastContent'] ?? '';
		$status = 'ended';
		$start = BSC_TV_Content::parse_date( $detail['scheduledStartTime'] ?? '', false );
		if ( ! empty( $detail['actualStartTime'] ) && empty( $detail['actualEndTime'] ) && 'live' === $flag ) {
			$status = 'live';
			$start = BSC_TV_Content::parse_date( $detail['actualStartTime'], false );
		} elseif ( $start && 'upcoming' === $flag && empty( $detail['actualEndTime'] ) ) {
			$status = 'upcoming';
		}
		$thumbnails = $snippet['thumbnails'] ?? array();
		$thumbnail = $thumbnails['maxres']['url'] ?? $thumbnails['standard']['url'] ?? $thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? '';
		return self::event( $item['id'], $snippet['title'] ?? 'Direct YouTube', 'https://www.youtube.com/watch?v=' . $item['id'], $thumbnail, $start ) + array( 'status' => $status, 'channel_id' => $snippet['channelId'] ?? '' );
	}
}
