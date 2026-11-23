<?php
defined( 'ABSPATH' ) || exit;

/** Authenticated hosting jobs. No visitor or WP-Cron starts a collection in server mode. */
final class BSC_TV_Server {
	const MODE = 'bsc_tv_server_mode';
	const REPORT = 'bsc_tv_server_daily';
	const LOCK = 'bsc_tv_server_daily_lock';
	const TICK = 'bsc_tv_server_tick';
	const TICK_LOCK = 'bsc_tv_server_tick_lock';

	public static function enabled() { return (bool) get_option( self::MODE, false ); }

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'admin_post_bsc_tv_server_mode', array( __CLASS__, 'save_mode' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bsc-tv daily', static function () { $r = self::daily( 10000, 480 ); if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); } WP_CLI::line( wp_json_encode( $r ) ); } );
			WP_CLI::add_command( 'bsc-tv live', static function () { $r = self::tick(); if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); } WP_CLI::line( wp_json_encode( $r ) ); } );
		}
	}

	public static function rest() {
		foreach ( array( 'server-daily' => 'daily', 'server-live' => 'tick' ) as $path => $method ) {
			register_rest_route( 'bsc-tv/v1', '/' . $path, array( 'methods' => 'GET', 'permission_callback' => array( 'BSC_TV_Player_Daily', 'authorize' ), 'callback' => static function () use ( $method ) {
				ignore_user_abort( true );
				if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 'daily' === $method ? 270 : 90 ); }
				$result = self::$method();
				$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : new WP_REST_Response( $result );
				$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
				$response->header( 'Referrer-Policy', 'no-referrer' );
				return $response;
			} ) );
		}
	}

	public static function set_mode( $enabled ) {
		update_option( self::MODE, (bool) $enabled, false );
		BSC_TV_Sync::schedule();
		BSC_TV_Player_Schedule::schedule();
	}

	public static function save_mode() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'bsc_tv_server_mode' );
		self::set_mode( ! empty( $_POST['server_mode'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bsc-tv-live#bsc-player-import' ) ); exit;
	}

	/** Claim the whole passage before work. A retry cannot restart either phase today. */
	public static function daily( $max_channels = 10000, $budget = 240 ) {
		if ( ! self::enabled() && ! BSC_TV_Player::settings()['enabled'] ) { return array( 'status' => 'disabled' ); }
		$lock = BSC_TV_Content::lock( self::LOCK );
		if ( ! $lock ) { return new WP_Error( 'server_busy', 'Le passage quotidien est déjà en cours.', array( 'status' => 409 ) ); }
		$claimed = false;
		$sync = null;
		try {
			wp_cache_delete( self::REPORT, 'options' ); wp_cache_delete( 'notoptions', 'options' );
			$day = wp_date( 'Y-m-d' );
			$previous = (array) get_option( self::REPORT, array() );
			if ( $day === ( $previous['day'] ?? '' ) ) { return array( 'status' => 'already_run', 'day' => $day, 'message' => 'Le passage du jour a déjà été lancé. Prochaine recherche demain.' ); }
			// A busy live check must not consume the daily claim before work can begin.
			$sync = BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
			if ( ! $sync ) { return new WP_Error( 'server_sync_busy', 'Un contrôle des plateformes est en cours. Réessayez plus tard.', array( 'status' => 409 ) ); }
			$report = array( 'day' => $day, 'started_at' => time(), 'finished_at' => 0, 'status' => 'running' );
			if ( ! update_option( self::REPORT, $report, false ) ) { return new WP_Error( 'server_claim', 'Impossible de réserver le passage quotidien.', array( 'status' => 503 ) ); }
			$claimed = true;
			$budget = max( 30, min( 480, $budget ) ); $deadline = microtime( true ) + $budget;
			$report['discovery'] = BSC_TV_Sync::discover_server( min( $deadline - 15, microtime( true ) + max( 15, $budget / 2 ) ), $max_channels, static function ( $progress ) use ( &$report ) { $report['discovery'] = $progress; update_option( self::REPORT, $report, false ); } );
			update_option( self::REPORT, $report, false );
			$remaining = (int) ( $deadline - microtime( true ) );
			if ( $remaining >= 30 ) { $report['replays'] = BSC_TV_Player_Daily::execute( 10000, $remaining, $sync ); }
			else { $report['replays'] = array( 'status' => 'deferred', 'message' => 'Budget du passage atteint ; les replays reprendront au prochain passage quotidien.' ); }
			if ( is_wp_error( $report['replays'] ) ) { $report['replays'] = array( 'status' => 'partial', 'error' => $report['replays']->get_error_message() ); }
			$statuses = array( $report['discovery']['status'], $report['replays']['status'] );
			$report['status'] = array_intersect( $statuses, array( 'partial', 'deferred' ) ) ? 'partial' : ( in_array( 'completed_with_errors', $statuses, true ) ? 'completed_with_errors' : 'completed' );
		} catch ( Throwable $error ) {
			$report['status'] = 'partial'; $report['error'] = 'Passage interrompu. Les progressions sont conservées pour le prochain jour.';
		} finally {
			try {
				if ( $claimed ) {
					$report['finished_at'] = time(); update_option( self::REPORT, $report, false );
					BSC_TV_Player::snapshot();
				}
			} finally { if ( $sync ) { BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $sync ); } BSC_TV_Content::unlock( self::LOCK, $lock ); }
		}
		return $report;
	}

	/** Only live status and the local clock; never searches or replay imports. */
	public static function tick() {
		$lock = BSC_TV_Content::lock( self::TICK_LOCK );
		if ( ! $lock ) { return new WP_Error( 'server_tick_busy', 'Le contrôle des directs est en cours.', array( 'status' => 409 ) ); }
		$sync = null;
		try {
			wp_cache_delete( self::TICK, 'options' ); wp_cache_delete( 'notoptions', 'options' );
			$previous = (array) get_option( self::TICK, array() ); $now = time();
			if ( $now - ( $previous['started_at'] ?? 0 ) < 5 * MINUTE_IN_SECONDS ) { return array( 'status' => 'already_run', 'next_allowed' => $previous['started_at'] + 5 * MINUTE_IN_SECONDS ); }
			$sync = BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
			if ( ! $sync ) { return new WP_Error( 'server_tick_sync_busy', 'L’import quotidien est en cours. Nouveau contrôle au prochain passage.', array( 'status' => 409 ) ); }
			$report = array( 'status' => 'running', 'started_at' => $now );
			if ( ! update_option( self::TICK, $report, false ) ) { return new WP_Error( 'server_tick_claim', 'Impossible de réserver le contrôle.', array( 'status' => 503 ) ); }
			BSC_TV_Sync::live( $sync );
			BSC_TV_Player::snapshot();
			$report['status'] = BSC_TV_Sync::can_check_live( BSC_TV_Sync::settings() ) ? 'completed' : 'disabled';
			$report['finished_at'] = time(); update_option( self::TICK, $report, false );
			return $report;
		} catch ( Throwable $error ) {
			if ( isset( $report ) ) { $report['status'] = 'partial'; $report['error'] = 'Contrôle interrompu ; nouvel essai au prochain passage.'; $report['finished_at'] = time(); update_option( self::TICK, $report, false ); }
			return new WP_Error( 'server_tick_failed', 'Contrôle interrompu ; nouvel essai au prochain passage.', array( 'status' => 503 ) );
		} finally {
			if ( $sync ) { BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $sync ); }
			BSC_TV_Content::unlock( self::TICK_LOCK, $lock );
		}
	}
}
