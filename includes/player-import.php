<?php

defined( 'ABSPATH' ) || exit;

/** Seed the existing show catalogue with two months of public, completed videos. */
final class BSC_TV_Player_Import {
	const STATE = 'bsc_tv_player_replay_import';
	const JOB = 'bsc_tv_player_replay_import_batch';
	const REVISION = 'bsc_tv_player_replay_request';
	const ORIGIN = '_bsc_tv_player_replay_origin';
	const REPORT = '_bsc_tv_player_replay_report';
	const MAX_PAGES = 100;

	public static function register() {
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		add_action( self::JOB, array( __CLASS__, 'run' ) );
		foreach ( array( BSC_TV_Sync::DAILY, BSC_TV_Sync::DAILY_NOW ) as $hook ) { add_action( $hook, array( __CLASS__, 'request' ), 20 ); }
		foreach ( array( 'added_option', 'updated_option' ) as $hook ) { add_action( $hook, array( __CLASS__, 'settings_changed' ) ); }
		add_action( 'save_post_' . BSC_TV_Content::CHANNEL, array( __CLASS__, 'channel_changed' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) { add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 3 ); }
	}

	public static function schedule() {
		if ( ! BSC_TV_Player::settings()['enabled'] ) { self::deactivate(); return; }
		$state = (array) get_option( self::STATE, array() );
		if ( ! empty( $state['pending'] ) || time() - ( $state['checked_at'] ?? 0 ) >= DAY_IN_SECONDS ) { BSC_TV_Sync::queue( self::JOB ); }
	}

	public static function deactivate() {
		if ( wp_next_scheduled( self::JOB ) ) { wp_clear_scheduled_hook( self::JOB ); }
	}

	public static function request() {
		if ( BSC_TV_Player::settings()['enabled'] ) {
			update_option( self::REVISION, wp_generate_uuid4(), false );
			BSC_TV_Sync::queue( self::JOB );
		}
	}

	public static function settings_changed( $option ) {
		if ( BSC_TV_Player::SETTINGS === $option ) { self::schedule(); self::request(); }
		if ( BSC_TV_Sync::SETTINGS === $option ) { self::request(); }
	}

	public static function channel_changed( $id ) {
		if ( 'publish' === get_post_status( $id ) ) { self::request(); }
	}

	public static function meta_changed( $meta_id, $id, $key ) {
		if ( in_array( $key, array( BSC_TV_Content::DATA, BSC_TV_Player::CHANNEL ), true ) && BSC_TV_Content::CHANNEL === get_post_type( $id ) ) { self::channel_changed( $id ); }
	}

	public static function channels() {
		return array_filter( BSC_TV_Sync::discovery_channels( BSC_TV_Sync::settings() ), static function ( $channel ) {
			$config = (array) get_post_meta( $channel['id'], BSC_TV_Player::CHANNEL, true );
			return $channel['api'] && ( ! isset( $config['replays'] ) || $config['replays'] );
		} );
	}

	public static function since( $now ) {
		$date = new DateTimeImmutable( '@' . $now );
		$month = $date->modify( 'first day of this month' )->modify( '-2 months' );
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
		if ( ! $resource || empty( $event['playable'] ) || 'ended' !== $event['status'] || $event['duration'] < 1 || $event['duration'] > DAY_IN_SECONDS ) { return 'skipped'; }
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

	/** One page per job; cursor and retry counts survive interrupted cron workers. */
	public static function run() {
		if ( ! BSC_TV_Player::settings()['enabled'] ) { return; }
		$lock = BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
		if ( ! $lock ) { BSC_TV_Sync::queue( self::JOB, 60 ); return; }
		$changed = false;
		try {
			wp_cache_delete( self::STATE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$state = (array) get_option( self::STATE, array() );
			$revision = get_option( self::REVISION, '' );
			$channels = self::channels();
			$now = time();
			if ( empty( $state['pending'] ) ) {
				$state = array( 'revision' => $revision, 'since' => self::since( $now ), 'until' => $now, 'checked_at' => $now, 'pending' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => count( $channels ) );
				foreach ( $channels as $id => $channel ) { $state['pending'][ $id ] = array( 'key' => $channel['key'], 'cursor' => '', 'pages' => 0, 'attempts' => 0, 'seen' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0 ); }
				update_option( self::STATE, $state, false );
			}
			if ( $state['pending'] ) {
				BSC_TV_Sync::queue( self::JOB, 30 );
				$id = array_key_first( $state['pending'] );
				$job = $state['pending'][ $id ];
				if ( ! isset( $channels[ $id ] ) || $channels[ $id ]['key'] !== $job['key'] ) {
					unset( $state['pending'][ $id ] );
				} else {
					$channel = $channels[ $id ];
					$channel['state'] = BSC_TV_Sync::state( $id, $channel['key'] );
					$page = BSC_TV_Sync::replay_page( $channel, BSC_TV_Sync::settings(), $state['since'], $state['until'], $job['cursor'] );
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
						$cursor = $page['cursor'];
						if ( $cursor && ( isset( $job['seen'][ $cursor ] ) || $job['pages'] >= self::MAX_PAGES ) ) { $error = 'Import partiel : pagination répétée ou limite de 100 pages atteinte.'; ++$state['errors']; $cursor = ''; }
						if ( $cursor ) {
							$job['seen'][ $cursor ] = true;
							$job['cursor'] = $cursor;
							$job['attempts'] = 0;
							$state['pending'][ $id ] = $job;
						} else { unset( $state['pending'][ $id ] ); }
					}
					update_post_meta( $id, self::REPORT, array( 'at' => $now, 'error' => $error, 'pages' => $job['pages'], 'done' => ! isset( $state['pending'][ $id ] ), 'created' => $job['created'], 'updated' => $job['updated'], 'skipped' => $job['skipped'] ) );
				}
			}
			if ( ! $state['pending'] ) {
				$state['finished_at'] = $now;
				self::deactivate();
				// A source or credential changed during this scan: do not lose that request.
				wp_cache_delete( self::REVISION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( get_option( self::REVISION, '' ) !== $state['revision'] ) { BSC_TV_Sync::queue( self::JOB ); }
			}
			update_option( self::STATE, $state, false );
		} finally { BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $lock ); }
		if ( $changed ) { BSC_TV_Player_Schedule::refresh(); }
	}
}
