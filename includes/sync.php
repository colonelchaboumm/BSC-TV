<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Sync {
	const SETTINGS = 'bsc_tv_settings';
	const MAX_LIVE_AGE = 20 * MINUTE_IN_SECONDS;
	const SCHEDULE_WINDOW = 45 * DAY_IN_SECONDS;
	const DAILY = 'bsc_tv_discover_daily';
	const CONTINUE_DAILY = 'bsc_tv_discover_batch';
	const LIVE = 'bsc_tv_check_live';
	const DAILY_NOW = 'bsc_tv_discover_now';
	const LIVE_NOW = 'bsc_tv_live_now';
	const QUEUE = 'bsc_tv_discovery_queue';
	const LAST_DAILY = 'bsc_tv_last_discovery';
	const LAST_LIVE = 'bsc_tv_last_live_check';
	const LOCK = 'bsc_tv_sync_lock';
	const QUOTA = 'bsc_tv_youtube_search_quota';
	const CRON_VERSION = 4;

	public static function settings() {
		$values = (array) get_option( self::SETTINGS, array() );
		foreach ( array( 'youtube_key', 'twitch_id', 'twitch_secret', 'kick_id', 'kick_secret' ) as $key ) {
			$constant = 'BSC_TV_' . strtoupper( $key );
			$values[ $key ] = defined( $constant ) ? (string) constant( $constant ) : (string) ( $values[ $key ] ?? '' );
		}
		if ( ! array_key_exists( 'live_enabled', $values ) ) {
			$values['live_enabled'] = (bool) get_option( 'bsc_tv_last_sync' );
		}
		return $values;
	}

	public static function schedules( $schedules ) {
		$schedules['bsc_tv_five_minutes'] = array( 'interval' => 300, 'display' => 'BSC TV · Toutes les 5 minutes' );
		return $schedules;
	}

	public static function activate() {
		wp_unschedule_hook( 'bsc_tv_sync' );
		self::schedule();
		$next = wp_next_scheduled( self::DAILY );
		if ( ! $next || $next > time() + MINUTE_IN_SECONDS ) {
			self::queue( self::DAILY_NOW );
		}
		update_option( 'bsc_tv_cron_version', self::CRON_VERSION, false );
	}

	public static function deactivate() {
		foreach ( array( 'bsc_tv_sync', self::DAILY, self::CONTINUE_DAILY, self::LIVE, self::DAILY_NOW, self::LIVE_NOW ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public static function upgrade() {
		if ( self::CRON_VERSION !== (int) get_option( 'bsc_tv_cron_version' ) ) {
			self::activate();
			return;
		}
		self::schedule();
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 30, 'daily', self::DAILY );
		}
		self::schedule_live();
		if ( get_option( self::QUEUE ) ) {
			self::queue( self::CONTINUE_DAILY );
		}
	}

	public static function can_check_live( $settings ) {
		return ! empty( $settings['live_enabled'] ) && (
			$settings['youtube_key'] ||
			( $settings['twitch_id'] && $settings['twitch_secret'] ) ||
			( $settings['kick_id'] && $settings['kick_secret'] )
		);
	}

	private static function schedule_live() {
		$next = wp_next_scheduled( self::LIVE );
		if ( self::can_check_live( self::settings() ) ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 45, 'bsc_tv_five_minutes', self::LIVE );
			}
			return;
		}
		if ( $next ) {
			wp_unschedule_hook( self::LIVE );
		}
		if ( wp_next_scheduled( self::LIVE_NOW ) ) {
			wp_unschedule_hook( self::LIVE_NOW );
		}
	}

	public static function queue( $hook, $delay = 15 ) {
		return wp_next_scheduled( $hook ) || wp_schedule_single_event( time() + $delay, $hook ) || wp_next_scheduled( $hook );
	}

	public static function daily() {
		self::discover( true );
	}

	public static function continue_daily() {
		self::discover( false );
	}

	public static function discovery_channels( $settings ) {
		$available = array(
			'youtube' => (bool) $settings['youtube_key'],
			'twitch'  => $settings['twitch_id'] && $settings['twitch_secret'],
		);
		$channels = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			if ( empty( $data['url'] ) ) {
				continue;
			}
			$platform = $data['platform'] ?? '';
			$key = BSC_TV_Content::channel_key( $platform, $data['url'] );
			$data = BSC_TV_Feeds::with_catalogue_source( $data );
			$api = $key && ! empty( $available[ $platform ] ) ? $platform : '';
			$channels[ $post->ID ] = array(
				'id' => $post->ID, 'key' => $key, 'data' => $data, 'api' => $api,
				'ready' => $api || ! empty( $data['feed_url'] ),
			);
		}
		return $channels;
	}

	private static function discover( $begin ) {
		$lock = BSC_TV_Content::lock( self::LOCK );
		if ( ! $lock ) {
			self::queue( $begin ? self::DAILY_NOW : self::CONTINUE_DAILY, 60 );
			return;
		}

		try {
			$settings = self::settings();
			$now = time();
			$queue = (array) get_option( self::QUEUE, array() );
			if ( $queue && $begin ) {
				// Keep the new scan scheduled while the previous batch finishes.
				self::queue( self::DAILY_NOW, 5 * MINUTE_IN_SECONDS );
				self::queue( self::CONTINUE_DAILY );
				return;
			}
			if ( ! $queue && ! $begin ) {
				return;
			}
			$channels = array_filter( self::discovery_channels( $settings ), static function ( $channel ) {
				return $channel['ready'];
			} );
			$starting = ! $queue;
			if ( $starting ) {
				foreach ( $channels as $id => $channel ) {
					$channels[ $id ]['state'] = self::state( $id, $channel['key'] );
				}
				uasort( $channels, static function ( $a, $b ) { return ( $a['state']['schedule_attempt'] ?? 0 ) <=> ( $b['state']['schedule_attempt'] ?? 0 ); } );
				$queue = array_keys( $channels );
				update_option( self::QUEUE, $queue, false );
			}
			if ( $queue ) {
				self::queue( self::CONTINUE_DAILY, 30 );
			}
			if ( $starting && $settings['youtube_key'] ) {
				self::manual_youtube( $settings['youtube_key'], $now, false );
			}
			$deadline = microtime( true ) + 15;
			for ( $count = 0; $queue && $count < 4 && microtime( true ) < $deadline; ++$count ) {
				$id = reset( $queue );
				if ( isset( $channels[ $id ] ) ) {
					$channel = $channels[ $id ];
					$channel['state'] = $channel['state'] ?? self::state( $id, $channel['key'] );
					self::discover_channel( $channel, $settings, $now );
				}
				array_shift( $queue );
				update_option( self::QUEUE, $queue, false );
			}
			if ( $queue ) {
				self::queue( self::CONTINUE_DAILY );
			} else {
				BSC_TV_Library::archive_shows( $now );
				delete_option( self::QUEUE );
				wp_unschedule_hook( self::CONTINUE_DAILY );
				update_option( self::LAST_DAILY, $now, false );
			}
		} finally {
			BSC_TV_Content::unlock( self::LOCK, $lock );
		}
	}

	private static function discover_channel( $channel, $settings, $now ) {
		$data = $channel['data'];
		if ( 'twitch' === $channel['api'] ) {
			self::discover_twitch( $channel, $settings, $now );
		}
		if ( 'youtube' === $channel['api'] ) {
			self::discover_youtube( $channel, $settings['youtube_key'], $now );
		}
		if ( ! empty( $data['feed_url'] ) ) {
			$result = BSC_TV_Feeds::fetch( $channel, $now );
			update_post_meta( $channel['id'], '_bsc_tv_feed_report', is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%d annonce(s) lue(s).', $result ) );
		}
	}

	public static function live() {
		$settings = self::settings();
		if ( ! self::can_check_live( $settings ) ) {
			return;
		}
		$lock = BSC_TV_Content::lock( self::LOCK );
		if ( ! $lock ) {
			return;
		}
		try {
			$now = time();
			$channels = array();
			$near = array();
			foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW ) as $show ) {
				$data = BSC_TV_Content::data( $show->ID );
				if ( ! empty( $data['url'] ) && 'cancelled' !== $data['status'] && ( empty( $data['origin_channel'] ) || BSC_TV_Content::public_channel( $data['origin_channel'] ) ) && BSC_TV_Library::near( $data, $now ) ) {
					$key = BSC_TV_Content::channel_key( $data['platform'], $data['url'] );
					if ( $key && in_array( $data['platform'], array( 'kick', 'twitch' ), true ) ) {
						$near[ $data['platform'] ][ $key ] = true;
					}
				}
			}
			foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
				$data = BSC_TV_Content::data( $post->ID );
				$platform = $data['platform'] ?? '';
				$key = BSC_TV_Content::channel_key( $platform, $data['url'] ?? '' );
				if ( ! $key ) {
					continue;
				}
				$state = self::state( $post->ID, $key );
				$due = ! empty( $state['live'] ) || isset( $near[ $platform ][ $key ] );
				foreach ( $state['schedule'] ?? array() as $event ) {
					$due = $due || 'live' === ( $event['status'] ?? '' ) || BSC_TV_Library::near( $event, $now );
				}
				if ( $due ) {
					$channels[ $platform ][ $post->ID ] = array( 'id' => $post->ID, 'key' => $key, 'data' => $data, 'state' => $state );
				}
			}
			foreach ( array( 'twitch', 'kick' ) as $platform ) {
				if ( ! empty( $channels[ $platform ] ) && $settings[ $platform . '_id' ] && $settings[ $platform . '_secret' ] ) {
					self::$platform( $channels[ $platform ], $settings, $now );
				}
			}
			if ( $settings['youtube_key'] ) {
				self::live_youtube( $channels['youtube'] ?? array(), $settings['youtube_key'], $now );
			}
			update_option( self::LAST_LIVE, $now, false );
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

	private static function require_items( $result, $field ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result[ $field ] ) && is_array( $result[ $field ] ) ? $result : new WP_Error( 'bsc_tv_api_shape', 'Réponse API incomplète.' );
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

	public static function matches( $channel, $text ) {
		$keywords = array_filter( array_map( 'trim', explode( ',', remove_accents( $channel['data']['keywords'] ?? '' ) ) ) );
		foreach ( $keywords as $keyword ) {
			if ( false !== stripos( remove_accents( $text ), $keyword ) ) {
				return true;
			}
		}
		return ! $keywords;
	}

	private static function twitch( $channels, $settings, $now ) {
		$token = self::oauth( 'twitch', $settings );
		if ( is_wp_error( $token ) ) {
			self::error( $channels, $token, 'live_error' );
			return;
		}
		$headers = array( 'Authorization' => 'Bearer ' . $token, 'Client-Id' => $settings['twitch_id'] );
		$api = 'https://api.twitch.tv/helix/';
		foreach ( array_chunk( $channels, 100, true ) as $group ) {
			$logins = array_column( $group, 'key' );
			$streams = self::require_items( self::request( $api . 'streams?first=100&' . self::repeated( 'user_login', $logins ), $headers ), 'data' );
			if ( is_wp_error( $streams ) ) {
				self::error( $group, $streams, 'live_error' );
				self::forget_oauth( 'twitch', $settings, $streams );
				continue;
			}
			$streams = self::indexed( $streams['data'] ?? array(), 'user_login' );
			foreach ( $group as $channel ) {
				$stream = $streams[ $channel['key'] ] ?? array();
				$live = array();
				if ( 'live' === ( $stream['type'] ?? '' ) && self::matches( $channel, $stream['title'] . ' ' . ( $stream['game_name'] ?? '' ) ) ) {
					$image = strtr( $stream['thumbnail_url'] ?? '', array( '{width}' => '1280', '{height}' => '720' ) );
					$live[] = self::event( $stream['id'], $stream['title'], 'https://www.twitch.tv/' . $channel['key'], $image, BSC_TV_Content::parse_date( $stream['started_at'], false ) );
				}
				self::archive_disappeared_live( $channel, $live );
				self::save( $channel, array( 'live' => $live, 'live_at' => $now, 'live_error' => '' ) );
			}
		}
	}

	private static function discover_twitch( $channel, $settings, $now ) {
		$token = self::oauth( 'twitch', $settings );
		if ( is_wp_error( $token ) ) {
			self::error( array( $channel ), $token, 'schedule_error' );
			return;
		}
		$headers = array( 'Authorization' => 'Bearer ' . $token, 'Client-Id' => $settings['twitch_id'] );
		$api = 'https://api.twitch.tv/helix/';
		$user = $channel['state']['twitch_user'] ?? array();
		if ( ! $user || $now - ( $channel['state']['twitch_user_at'] ?? 0 ) >= WEEK_IN_SECONDS ) {
			$users = self::request( $api . 'users?' . self::repeated( 'login', array( $channel['key'] ) ), $headers );
			if ( is_wp_error( $users ) ) {
				self::forget_oauth( 'twitch', $settings, $users );
				self::error( array( $channel ), $users, 'schedule_error' );
				return;
			}
			$user = $users['data'][0] ?? array();
			if ( ! $user ) {
				self::save( $channel, array( 'schedule_error' => 'Chaîne Twitch introuvable.' ) );
				return;
			}
			$channel['state'] = self::save( $channel, array( 'twitch_user' => $user, 'twitch_user_at' => $now ) );
		}
		$events = self::twitch_schedule( $api, $headers, $channel, $user, $now );
		if ( is_wp_error( $events ) ) {
			self::forget_oauth( 'twitch', $settings, $events );
			self::error( array( $channel ), $events, 'schedule_error' );
			return;
		}
		$previous = array_column( $channel['state']['schedule'] ?? array(), null, 'id' );
		foreach ( $events as $id => $event ) {
			if ( 'cancelled' === ( $event['status'] ?? '' ) ) {
				BSC_TV_Library::archive_snapshot( $channel, $previous[ $id ] ?? $event, 'cancelled' );
				unset( $previous[ $id ], $events[ $id ] );
			}
		}
		foreach ( array_diff_key( $previous, array_column( $events, null, 'id' ) ) as $event ) {
			BSC_TV_Library::archive_snapshot( $channel, $event, $event['start'] <= $now ? 'ended' : 'cancelled' );
		}
		self::save( $channel, array( 'schedule' => array_values( $events ), 'schedule_error' => '', 'schedule_attempt' => $now ) );
	}

	private static function twitch_schedule( $api, $headers, $channel, $user, $now ) {
		$events = array();
		$after = '';
		$base = add_query_arg( array( 'broadcaster_id' => $user['id'], 'first' => 25, 'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', $now ) ), $api . 'schedule' );
		for ( $page = 0; $page < 4; ++$page ) {
			$result = self::require_items( self::request( $after ? add_query_arg( 'after', rawurlencode( $after ), $base ) : $base, $headers, null, true ), 'data' );
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
				if ( ! $start || $end <= $now ) {
					continue;
				}
				$title = $segment['title'] ?: 'Direct Twitch';
				if ( self::matches( $channel, $title . ' ' . ( $segment['category']['name'] ?? '' ) ) ) {
					$cancelled = ! empty( $segment['canceled_until'] ) || ( $vacation && $start >= BSC_TV_Content::parse_date( $vacation['start_time'], false ) && $start < BSC_TV_Content::parse_date( $vacation['end_time'], false ) );
					$events[ $segment['id'] ] = self::event( $segment['id'], $title, 'https://www.twitch.tv/' . $channel['key'], ( $user['offline_image_url'] ?? '' ) ?: ( $user['profile_image_url'] ?? '' ), $start, $end ) + array( 'status' => $cancelled ? 'cancelled' : 'upcoming' );
				}
			}
			$after = $result['pagination']['cursor'] ?? '';
			if ( ! $after ) {
				break;
			}
		}
		return $after ? new WP_Error( 'bsc_tv_twitch_pages', 'Planning Twitch incomplet après quatre pages.' ) : $events;
	}

	private static function kick( $channels, $settings, $now ) {
		$token = self::oauth( 'kick', $settings );
		if ( is_wp_error( $token ) ) {
			self::error( $channels, $token, 'live_error' );
			return;
		}
		foreach ( array_chunk( $channels, 50 ) as $group ) {
			$result = self::require_items( self::request( 'https://api.kick.com/public/v1/channels?' . self::repeated( 'slug', array_column( $group, 'key' ) ), array( 'Authorization' => 'Bearer ' . $token ) ), 'data' );
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
				self::archive_disappeared_live( $channel, $live );
				self::save( $channel, array( 'live' => $live, 'live_at' => $now, 'live_error' => $item ? '' : 'Chaîne Kick introuvable.' ) );
			}
		}
	}

	private static function archive_disappeared_live( $channel, $live ) {
		foreach ( array_diff_key( array_column( $channel['state']['live'] ?? array(), null, 'id' ), array_column( $live, null, 'id' ) ) as $event ) {
			BSC_TV_Library::archive_snapshot( $channel, $event, 'ended' );
		}
	}

	private static function yt_request( $endpoint, $key, $params ) {
		return self::require_items( self::request( add_query_arg( $params + array( 'key' => rawurlencode( $key ) ), 'https://www.googleapis.com/youtube/v3/' . $endpoint ) ), 'items' );
	}

	private static function manual_youtube( $key, $now, $live_only ) {
		$shows = self::manual_youtube_ids( $now, $live_only );
		list( $videos, $failed ) = self::yt_details( $key, $shows );
		self::save_manual_youtube( $shows, $videos, $failed, $now );
	}

	private static function manual_youtube_ids( $now, $live_only ) {
		$manual_shows = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			$video = BSC_TV_Content::youtube_video_id( $data['url'] ?? '' );
			if ( ! $video || 'upcoming' !== ( $data['status'] ?? '' ) || ( ! empty( $data['origin_channel'] ) && ! BSC_TV_Content::public_channel( $data['origin_channel'] ) ) ) {
				continue;
			}
			$previous = self::state( $post->ID, $video )['video'] ?? array();
			$window = 'upcoming' === ( $previous['status'] ?? '' ) ? $previous : $data;
			$was_live = 'live' === ( $previous['status'] ?? '' );
			$needs_check = $live_only ? BSC_TV_Library::near( $window, $now ) : $window['start'] >= $now - 2 * DAY_IN_SECONDS && $window['start'] <= $now + self::SCHEDULE_WINDOW;
			if ( $was_live || $needs_check ) {
				$manual_shows[ $post->ID ] = $video;
			}
		}
		return $manual_shows;
	}

	private static function save_manual_youtube( $manual_shows, $videos, $failed, $now ) {
		foreach ( $manual_shows as $post_id => $video ) {
			if ( ! isset( $failed[ $video ] ) ) {
				$event = $videos[ $video ] ?? array( 'status' => 'cancelled' );
				update_post_meta( $post_id, BSC_TV_Content::STATE, wp_slash( array( 'key' => $video, 'live_at' => $now, 'video' => $event ) ) );
			}
		}
	}

	private static function yt_details( $key, $video_ids ) {
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
		return array( $videos, $failed );
	}

	private static function live_youtube( $channels, $key, $now ) {
		$manual_shows = self::manual_youtube_ids( $now, true );
		$checks = array();
		$all_ids = array_values( $manual_shows );
		foreach ( $channels as $channel ) {
			foreach ( $channel['state']['schedule'] ?? array() as $event ) {
				if ( 'live' === ( $event['status'] ?? '' ) || BSC_TV_Library::near( $event, $now ) ) {
					$checks[ $channel['id'] ][ $event['id'] ] = $event;
				}
			}
			foreach ( $channel['state']['live'] ?? array() as $event ) {
				$checks[ $channel['id'] ][ $event['id'] ] = $event;
			}
			$all_ids = array_merge( $all_ids, array_keys( $checks[ $channel['id'] ] ?? array() ) );
		}
		list( $videos, $failed ) = self::yt_details( $key, $all_ids );
		self::save_manual_youtube( $manual_shows, $videos, $failed, $now );
		foreach ( $channels as $channel ) {
			$tracked = $checks[ $channel['id'] ] ?? array();
			$ids = array_keys( $tracked );
			$errors = array_intersect_key( $failed, array_fill_keys( $ids, true ) );
			if ( $errors ) {
				self::save( $channel, array( 'live_error' => reset( $errors ) ) );
				continue;
			}
			$live = array();
			$schedule = array_column( $channel['state']['schedule'] ?? array(), null, 'id' );
			foreach ( $tracked as $id => $old ) {
				$event = $videos[ $id ] ?? null;
				if ( ! $event || in_array( $event['status'], array( 'ended', 'cancelled' ), true ) ) {
					BSC_TV_Library::archive_snapshot( $channel, $event && $event['start'] ? $event : $old, $event['status'] ?? 'cancelled' );
					unset( $schedule[ $id ] );
					continue;
				}
				if ( ! self::matches( $channel, $event['title'] ) ) {
					unset( $schedule[ $id ] );
					continue;
				}
				if ( 'live' === $event['status'] ) {
					$live[] = $event;
				} elseif ( 'upcoming' === $event['status'] ) {
					$schedule[ $id ] = $event;
				}
			}
			self::save( $channel, array( 'live' => $live, 'schedule' => array_values( $schedule ), 'live_at' => $now, 'live_error' => '' ) );
		}
	}

	private static function discover_youtube( $channel, $key, $now ) {
		$state = $channel['state'];
		$previous = array_column( array_merge( $state['schedule'] ?? array(), $state['live'] ?? array() ), null, 'id' );
		$channel_id = self::yt_channel_id( $channel, $key );
		if ( is_wp_error( $channel_id ) ) {
			self::error( array( $channel ), $channel_id, 'schedule_error' );
			return;
		}
		$ids = array_unique( array_merge( array_keys( $previous ), $state['ids'] ?? array() ) );
		$cursor = '';
		$error = '';
		$attempt = $state['schedule_attempt'] ?? 0;
		for ( $page = 0; $page < 3; ++$page ) {
			if ( ! self::yt_budget( $now, 1 ) ) {
				$error = 'Budget quotidien YouTube épuisé.';
				break;
			}
			$params = array( 'part' => 'snippet', 'type' => 'video', 'channelId' => $channel_id, 'eventType' => 'upcoming', 'maxResults' => 50 );
			if ( $cursor ) {
				$params['pageToken'] = rawurlencode( $cursor );
			}
			$attempt = $now;
			$result = self::yt_request( 'search', $key, $params );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				break;
			}
			foreach ( $result['items'] ?? array() as $item ) {
				if ( ! empty( $item['id']['videoId'] ) ) {
					$ids[] = $item['id']['videoId'];
				}
			}
			$cursor = $result['nextPageToken'] ?? '';
			if ( ! $cursor ) {
				break;
			}
		}
		if ( $cursor && ! $error ) {
			$error = 'Recherche YouTube partielle après trois pages.';
		}
		list( $videos, $failed ) = self::yt_details( $key, $ids );
		$schedule = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( isset( $failed[ $id ] ) ) {
				if ( isset( $previous[ $id ] ) ) {
					$schedule[] = $previous[ $id ];
				}
				continue;
			}
			$event = $videos[ $id ] ?? null;
			$status = $event['status'] ?? 'cancelled';
			if ( 'ended' === $status || 'cancelled' === $status ) {
				if ( isset( $previous[ $id ] ) ) {
					BSC_TV_Library::archive_snapshot( $channel, $event && $event['start'] ? $event : $previous[ $id ], $status );
				}
				continue;
			}
			if ( $event['channel_id'] === $channel_id && self::matches( $channel, $event['title'] ) && $event['start'] <= $now + self::SCHEDULE_WINDOW ) {
				if ( 'live' !== $status && BSC_TV_Library::expired( $event, $now ) ) {
					BSC_TV_Library::archive_snapshot( $channel, $event, 'ended' );
				} else {
					$schedule[] = $event;
				}
			}
		}
		$active = array_filter( $state['live'] ?? array(), static function ( $event ) use ( $videos, $failed ) {
			return isset( $failed[ $event['id'] ] ) || 'live' === ( $videos[ $event['id'] ]['status'] ?? '' );
		} );
		self::save( $channel, array( 'channel_id' => $channel_id, 'schedule' => $schedule, 'live' => array_values( $active ), 'ids' => array_keys( $failed ), 'schedule_attempt' => $attempt, 'schedule_error' => $error ?: ( $failed ? reset( $failed ) : '' ) ) );
	}

	private static function yt_budget( $now, $cost ) {
		$date = wp_date( 'Y-m-d', $now, new DateTimeZone( 'America/Los_Angeles' ) );
		$quota = (array) get_option( self::QUOTA, array() );
		$count = $date === ( $quota['date'] ?? '' ) ? (int) $quota['count'] : 0;
		$limit = min( 10000, max( 1, absint( self::settings()['youtube_search_limit'] ?? 90 ) ) );
		if ( $count + $cost > $limit ) {
			return false;
		}
		update_option( self::QUOTA, array( 'date' => $date, 'count' => $count + $cost ), false );
		return true;
	}

	private static function yt_channel_id( $channel, $key ) {
		$state = $channel['state'];
		$channel_id = $state['channel_id'] ?? '';
		if ( ! $channel_id ) {
			$identity = $channel['key'];
			if ( preg_match( '/^UC[\w-]{22}$/D', $identity ) ) {
				return $identity;
			}
			if ( 0 === strpos( $identity, '@' ) ) {
				$param = array( 'forHandle' => rawurlencode( $identity ) );
			} elseif ( 0 === strpos( $identity, 'user:' ) ) {
				$param = array( 'forUsername' => rawurlencode( substr( $identity, 5 ) ) );
			} else {
				return new WP_Error( 'bsc_tv_youtube_channel', 'Identifiant de chaîne YouTube invalide.' );
			}
			$result = self::yt_request( 'channels', $key, $param + array( 'part' => 'id' ) );
			$channel_id = $result['items'][0]['id'] ?? '';
			if ( is_wp_error( $result ) || ! $channel_id ) {
				return is_wp_error( $result ) ? $result : new WP_Error( 'bsc_tv_youtube_channel', 'Chaîne YouTube introuvable.' );
			}
		}
		return $channel_id;
	}

	private static function yt_event( $item ) {
		$detail = $item['liveStreamingDetails'] ?? array();
		$snippet = $item['snippet'] ?? array();
		$flag = $snippet['liveBroadcastContent'] ?? '';
		$status = ! empty( $detail['actualEndTime'] ) ? 'ended' : 'cancelled';
		$start = BSC_TV_Content::parse_date( $detail['scheduledStartTime'] ?? '', false );
		$end = BSC_TV_Content::parse_date( $detail['actualEndTime'] ?? $detail['scheduledEndTime'] ?? '', false );
		if ( ! empty( $detail['actualStartTime'] ) && empty( $detail['actualEndTime'] ) && 'live' === $flag ) {
			$status = 'live';
			$start = BSC_TV_Content::parse_date( $detail['actualStartTime'], false );
		} elseif ( $start && 'upcoming' === $flag && empty( $detail['actualEndTime'] ) ) {
			$status = 'upcoming';
		}
		$thumbnails = $snippet['thumbnails'] ?? array();
		$thumbnail = $thumbnails['maxres']['url'] ?? $thumbnails['standard']['url'] ?? $thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? '';
		return self::event( $item['id'], $snippet['title'] ?? 'Direct YouTube', 'https://www.youtube.com/watch?v=' . $item['id'], $thumbnail, $start, $end > $start ? $end : 0 ) + array( 'status' => $status, 'channel_id' => $snippet['channelId'] ?? '' );
	}
}
