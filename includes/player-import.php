<?php

defined( 'ABSPATH' ) || exit;

/** Enrich the existing show catalogue with public, completed videos during the daily import. */
final class BSC_TV_Player_Import {
	const STATE = 'bsc_tv_player_replay_import';
	const JOB = 'bsc_tv_player_replay_import_batch';
	const REVISION = 'bsc_tv_player_replay_request';
	const ORIGIN = '_bsc_tv_player_replay_origin';
	const REPORT = '_bsc_tv_player_replay_report';
	const MAX_PAGES = 100;

	public static function register() {
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function schedule() {
		// Migration: remove the old import continuation without touching other WordPress tasks.
		self::deactivate();
	}

	public static function deactivate() {
		if ( wp_next_scheduled( self::JOB ) ) { wp_clear_scheduled_hook( self::JOB ); }
	}

	public static function request() {
		return false; // Kept for older callers: importing is now exclusively an external daily job.
	}

	public static function channels() {
		return array_filter( BSC_TV_Sync::discovery_channels( BSC_TV_Sync::settings() ), static function ( $channel ) {
			$config = (array) get_post_meta( $channel['id'], BSC_TV_Player::CHANNEL, true );
			return $channel['api'] && ( ! isset( $config['replays'] ) || $config['replays'] );
		} );
	}

	public static function since( $now, $months = 2 ) {
		$date = new DateTimeImmutable( '@' . $now );
		$month = $date->modify( 'first day of this month' )->modify( '-' . max( 2, min( 12, (int) $months ) ) . ' months' );
		return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), min( (int) $date->format( 'j' ), (int) $month->format( 't' ) ) )->getTimestamp();
	}

	/** Include nonpublic and password-protected posts so an import cannot recreate an excluded video. */
	public static function index() {
		$index = array();
		foreach ( get_posts( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', BSC_TV_Content::ARCHIVED ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'update_post_term_cache' => false ) ) as $post ) {
			$resource = BSC_TV_Player::resource( BSC_TV_Content::data( $post->ID )['url'] ?? '' );
			$origin = get_post_meta( $post->ID, self::ORIGIN, true );
			if ( $resource ) { $index[ BSC_TV_Player::resource_key( $resource ) ][] = $post; }
			if ( $origin && ( ! $resource || $origin !== BSC_TV_Player::resource_key( $resource ) ) ) { $index[ $origin ][] = $post; }
		}
		return $index;
	}

	public static function store( $channel, $event, $now, &$index ) {
		$resource = BSC_TV_Player::resource( $event['url'] ?? '' );
		if ( ! $resource || ! empty( $event['age_restricted'] ) || empty( $event['playable'] ) || 'ended' !== $event['status'] || $event['duration'] < 1 || $event['duration'] > DAY_IN_SECONDS ) { return 'skipped'; }
		$key = BSC_TV_Player::resource_key( $resource );
		$matches = $index[ $key ] ?? array();
		foreach ( $matches as $candidate ) {
			$media = (array) get_post_meta( $candidate->ID, BSC_TV_Player::MEDIA, true );
			if ( ! in_array( $candidate->post_status, array( 'publish', BSC_TV_Content::ARCHIVED ), true ) || $candidate->post_password || 'no' === ( $media['mode'] ?? '' ) ) { return 'skipped'; }
		}
		$post = $matches ? reset( $matches ) : null;
		$existing = $post ? BSC_TV_Content::data( $post->ID ) : array();
		if ( $post && ( 'cancelled' === ( $existing['status'] ?? '' ) || BSC_TV_Player::resource( $existing['url'] ?? '' ) !== $resource || ( BSC_TV_Content::ARCHIVED === $post->post_status && ! get_post_meta( $post->ID, BSC_TV_Player::APPROVED, true ) && ( ! get_post_meta( $post->ID, BSC_TV_Library::ORIGIN, true ) || get_post_meta( $post->ID, BSC_TV_Library::FEED, true ) ) ) ) ) { return 'skipped'; }
		$data = $existing ?: $event + array( 'channel_id' => $channel['id'], 'bdd_id' => 0, 'creator' => '', 'platform' => $channel['api'], 'source' => $channel['data']['url'] );
		// The channel ID in a YouTube event is remote; show data always references the local channel.
		if ( ! $post ) { $data['channel_id'] = $channel['id']; }
		$data['status'] = 'ended';
		if ( empty( $data['image'] ) ) { $data['image'] = $event['image']; }
		if ( empty( $data['channel_id'] ) ) { $data['channel_id'] = $channel['id']; }
		$values = array( 'ID' => $post ? $post->ID : 0, 'post_type' => BSC_TV_Content::SHOW, 'post_status' => BSC_TV_Content::ARCHIVED, 'post_title' => wp_slash( $post ? $post->post_title : $event['title'] ), 'meta_input' => array( BSC_TV_Content::DATA => wp_slash( $data ), BSC_TV_Player::APPROVED => 1, self::ORIGIN => $key ) );
		$id = $post ? wp_update_post( $values, true ) : wp_insert_post( $values, true );
		if ( is_wp_error( $id ) ) { return $id; }
		$video_key = 'youtube' === $resource['platform'] ? $resource['id'] : $key;
		$state = BSC_TV_Sync::state( $id, $video_key );
		$state['video'] = $event;
		$state['live_at'] = $now;
		update_post_meta( $id, BSC_TV_Content::STATE, wp_slash( $state ) );
		$index[ $key ] = array( get_post( $id ) );
		return $post ? 'updated' : 'created';
	}

	/** One internal page; only the authenticated daily worker may call this from the application. */
	public static function run( $external = false, $held_lock = null ) {
		if ( ! $external ) { return new WP_Error( 'import_external', 'Utilisez la tâche quotidienne Infomaniak.' ); }
		if ( ! BSC_TV_Stations::any_enabled() ) { return new WP_Error( 'import_disabled', 'Le lecteur est désactivé.' ); }
		$lock = $held_lock ?: BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
		if ( ! $lock ) { return new WP_Error( 'import_busy', 'Une synchronisation est déjà en cours.' ); }
		$changed = false;
		try {
			wp_cache_delete( self::STATE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$state = (array) get_option( self::STATE, array() );
			$channels = self::channels();
			$now = time();
			if ( empty( $state['pending'] ) ) {
				$state = array( 'since' => self::since( $now ), 'until' => $now, 'checked_at' => $now, 'pending' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => count( $channels ) );
				$counts = array_count_values( array_column( array_filter( BSC_TV_Player::media(), static function ( $media ) { return empty( $media['music'] ); } ), 'channel_id' ) );
				$enrich_archives = BSC_TV_Player::settings()['enrich_archives'];
				uksort( $channels, static function ( $a, $b ) use ( $counts ) { return ( $counts[ $a ] ?? 0 ) <=> ( $counts[ $b ] ?? 0 ) ?: $a <=> $b; } );
				foreach ( $channels as $id => $channel ) {
					$enrich = $enrich_archives && ( $counts[ $id ] ?? 0 ) < 8;
					$state['pending'][ $id ] = array( 'key' => $channel['key'], 'cursor' => '', 'pages' => 0, 'attempts' => 0, 'seen' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'since' => $enrich ? self::since( $now, 12 ) : $state['since'], 'usable_before' => $counts[ $id ] ?? 0 );
				}
				update_option( self::STATE, $state, false );
			}
			if ( $state['pending'] ) {
				$id = array_key_first( $state['pending'] );
				$job = $state['pending'][ $id ];
				if ( ! isset( $channels[ $id ] ) || $channels[ $id ]['key'] !== $job['key'] ) {
					unset( $state['pending'][ $id ] );
				} else {
					$channel = $channels[ $id ];
					$channel['state'] = BSC_TV_Sync::state( $id, $channel['key'] );
					$page_since = $job['since'] ?? $state['since'];
					$page = BSC_TV_Sync::replay_page( $channel, BSC_TV_Sync::settings(), $page_since, $state['until'], $job['cursor'] );
					$error = is_wp_error( $page ) ? $page->get_error_message() : '';
					if ( ! $error ) {
						$index = self::index();
						foreach ( $page['events'] as $event ) {
							$result = self::store( $channel, $event, $now, $index );
							if ( is_wp_error( $result ) ) { $error = $result->get_error_message(); break; }
							++$state[ $result ];
							++$job[ $result ];
							$changed = $changed || 'skipped' !== $result;
						}
					}
					if ( $error ) {
						if ( ++$job['attempts'] >= 3 ) { ++$state['errors']; unset( $state['pending'][ $id ] ); }
						else { $state['pending'][ $id ] = $job; }
					} else {
						++$job['pages'];
						if ( max( $job['usable_before'] ?? 0, $job['created'] + $job['updated'] ) >= 8 ) { $job['since'] = $state['since']; }
						$cursor = $page['cursor'];
						if ( $cursor && ( isset( $job['seen'][ $cursor ] ) || $job['pages'] >= self::MAX_PAGES ) ) { $error = 'Import partiel : pagination répétée ou limite de 100 pages atteinte.'; ++$state['errors']; $cursor = ''; }
						if ( $cursor ) {
							$job['seen'][ $cursor ] = true;
							$job['cursor'] = $cursor;
							$job['attempts'] = 0;
							$state['pending'][ $id ] = $job;
						} else { unset( $state['pending'][ $id ] ); }
					}
					if ( isset( $state['pending'][ $id ] ) ) { $remaining = $state['pending'][ $id ]; unset( $state['pending'][ $id ] ); $state['pending'][ $id ] = $remaining; }
					update_post_meta( $id, self::REPORT, array( 'at' => $now, 'error' => $error, 'pages' => $job['pages'], 'since' => $page_since, 'done' => ! isset( $state['pending'][ $id ] ), 'created' => $job['created'], 'updated' => $job['updated'], 'skipped' => $job['skipped'] ) );
				}
			}
			if ( ! $state['pending'] ) {
				$state['finished_at'] = $now;
			}
			update_option( self::STATE, $state, false );
		} finally { if ( ! $held_lock ) { BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $lock ); } }
		if ( $changed && ! $held_lock ) { BSC_TV_Player_Schedule::refresh(); }
		return $state;
	}
}
