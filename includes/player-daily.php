<?php

defined( 'ABSPATH' ) || exit;

/** Infomaniak invokes this worker once a day, independently from WP-Cron. */
final class BSC_TV_Player_Daily {
	const KEY = 'bsc_tv_player_import_key';
	const REPORT = 'bsc_tv_player_import_daily';
	const LOCK = 'bsc_tv_player_import_daily_lock';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bsc-tv import', static function () {
				$result = BSC_TV_Server::enabled() ? BSC_TV_Server::daily( 10000, 480 ) : self::execute( 10000, 480 );
				if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
				WP_CLI::line( wp_json_encode( $result ) );
				if ( in_array( $result['status'], array( 'partial', 'completed_with_errors' ), true ) ) { WP_CLI::warning( 'Consultez le bilan dans BSC TV → Le live.' ); }
			} );
		}
	}

	public static function key( $renew = false ) {
		if ( ! current_user_can( 'manage_options' ) ) { return ''; }
		$key = (string) get_option( self::KEY, '' );
		if ( $renew || ! $key ) {
			$value = bin2hex( random_bytes( 32 ) );
			if ( $renew ) { update_option( self::KEY, $value, false ); }
			else { add_option( self::KEY, $value, '', false ); }
			$key = (string) get_option( self::KEY, '' );
		}
		return $key;
	}

	public static function authorize( $request ) {
		$key = (string) get_option( self::KEY, '' );
		$provided = $request->get_header( 'x-bsc-tv-import-key' ) ?: $request->get_param( 'key' );
		if ( ! $key || ! is_string( $provided ) || ! hash_equals( $key, $provided ) ) { return new WP_Error( 'import_forbidden', 'Accès à la tâche quotidienne refusé.', array( 'status' => 403 ) ); }
		if ( ! is_ssl() && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { return new WP_Error( 'import_https', 'La tâche quotidienne nécessite HTTPS.', array( 'status' => 403 ) ); }
		return true;
	}

	public static function rest() {
		register_rest_route( 'bsc-tv/v1', '/import-daily', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'authorize' ), 'callback' => static function () {
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 270 ); }
			$result = BSC_TV_Server::enabled() ? BSC_TV_Server::daily() : self::execute();
			$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : new WP_REST_Response( $result );
			$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
			return $response;
		} ) );
	}

	/** The calendar-day claim is saved before API work, so repeat calls and crashes cannot start another scan today. */
	public static function execute( $max_pages = 10000, $budget = 240, $held_lock = null ) {
		if ( ! BSC_TV_Stations::any_enabled() ) { return array( 'status' => 'disabled', 'message' => 'Le lecteur est désactivé.' ); }
		$lock = BSC_TV_Content::lock( self::LOCK );
		if ( ! $lock ) { return new WP_Error( 'import_busy', 'Un import quotidien est déjà en cours.', array( 'status' => 409 ) ); }
		$sync_lock = null;
		try {
			wp_cache_delete( self::REPORT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$previous = (array) get_option( self::REPORT, array() );
			$day = wp_date( 'Y-m-d' );
			if ( ( $previous['day'] ?? '' ) === $day ) { return array( 'status' => 'already_run', 'day' => $day, 'message' => 'L’import du jour a déjà été lancé. Prochain passage demain.' ); }
			$sync_lock = $held_lock ?: BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
			if ( ! $sync_lock ) { return new WP_Error( 'import_sync_busy', 'Une autre synchronisation est en cours. Aucun import quotidien n’a été lancé.', array( 'status' => 409 ) ); }
			$report = array( 'day' => $day, 'started_at' => time(), 'finished_at' => 0, 'status' => 'running', 'pages' => 0 );
			if ( ! update_option( self::REPORT, $report, false ) ) { return new WP_Error( 'import_claim', 'Impossible d’enregistrer le passage quotidien. Aucun import n’a été lancé.', array( 'status' => 503 ) ); }
			$deadline = microtime( true ) + max( 30, min( 480, $budget ) );
			$state = array();
			try {
				do_action( 'bsc_tv_player_daily_music' );
				do {
					$state = BSC_TV_Player_Import::run( true, $sync_lock );
					if ( is_wp_error( $state ) ) { $report['error'] = $state->get_error_message(); break; }
					++$report['pages'];
					$report['remaining'] = count( $state['pending'] );
					foreach ( array( 'created', 'updated', 'skipped', 'errors' ) as $field ) { $report[ $field ] = $state[ $field ]; }
					update_option( self::REPORT, $report, false );
				} while ( $state['pending'] && $report['pages'] < max( 1, min( 10000, $max_pages ) ) && microtime( true ) + 30 < $deadline );
				$report['status'] = is_wp_error( $state ) || ! empty( $state['pending'] ) ? 'partial' : ( empty( $state['errors'] ) ? 'completed' : 'completed_with_errors' );
			} catch ( Throwable $error ) {
				$report['status'] = 'partial';
				$report['error'] = 'Le traitement a été interrompu. La progression est conservée pour le prochain passage quotidien.';
			} finally {
				BSC_TV_Player_Schedule::refresh();
				$report['finished_at'] = time();
				update_option( self::REPORT, $report, false );
			}
			return $report;
		} finally {
			if ( $sync_lock && ! $held_lock ) { BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $sync_lock ); }
			BSC_TV_Content::unlock( self::LOCK, $lock );
		}
	}
}
