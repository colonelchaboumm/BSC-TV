<?php
/** php tests/server-jobs.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-server-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite'; copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ); define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
$checks = 0; $calls = array(); $now = time(); $live_mode = false; $fail = false;
function server_check( $ok, $label ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } }
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) { foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); } }
foreach ( array( BSC_TV_Server::REPORT, BSC_TV_Server::TICK, BSC_TV_Sync::QUEUE, BSC_TV_Sync::LAST_DAILY, BSC_TV_Player::STATE, BSC_TV_Player_Daily::REPORT, BSC_TV_Player_Import::STATE ) as $key ) { delete_option( $key ); }
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0 ) );
update_option( BSC_TV_Music::SETTINGS, array( 'enabled' => false ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'youtube_key' => 'fixture-key', 'twitch_id' => 'fixture-id', 'twitch_secret' => 'fixture-secret', 'live_enabled' => true ) );
$ids = array();
foreach ( range( 1, 5 ) as $n ) {
	$url = $n <= 2 ? 'https://www.youtube.com/channel/UC' . str_repeat( 'a', 21 ) . $n : 'https://www.twitch.tv/beerjob' . $n;
	$data = BSC_TV_Content::channel_data( array( 'url' => $url, 'feed_url' => 5 === $n ? 'https://example.com/beer-agenda.ics' : '', 'feed_official' => 1 ) );
	if ( 5 === $n ) { $data['platform'] = 'other'; $data['url'] = 'https://example.com/beer-channel'; }
	$ids[] = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_title' => 'Fixture beer ' . $n, 'post_status' => 'publish', 'meta_input' => array( BSC_TV_Content::DATA => $data ) ) );
}
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$calls, &$live_mode, &$fail, $now ) {
	$host = wp_parse_url( $url, PHP_URL_HOST ); $path = wp_parse_url( $url, PHP_URL_PATH ); parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
	$calls[] = $host . $path; $body = null;
	if ( 'www.googleapis.com' === $host ) {
		if ( '/youtube/v3/search' === $path ) { $body = array( 'items' => array( array( 'id' => array( 'videoId' => 'future0000' . substr( $q['channelId'], -1 ) ) ) ) ); }
		if ( '/youtube/v3/channels' === $path ) { $body = array( 'items' => array( array( 'id' => $q['id'] ?? 'UC' . str_repeat( 'a', 21 ) . '1', 'contentDetails' => array( 'relatedPlaylists' => array( 'uploads' => 'UUfixture' ) ) ) ) ); }
		if ( '/youtube/v3/playlistItems' === $path ) { $body = array( 'items' => array() ); }
		if ( '/youtube/v3/videos' === $path ) {
			$items = array(); foreach ( explode( ',', $q['id'] ) as $id ) {
				$detail = array( 'scheduledStartTime' => gmdate( DATE_ATOM, $now + 900 ) );
				if ( $live_mode ) { $detail['actualStartTime'] = gmdate( DATE_ATOM, $now - 60 ); }
				$items[] = array( 'id' => $id, 'snippet' => array( 'title' => 'Craft beer upcoming ' . $id, 'channelId' => 'UC' . str_repeat( 'a', 21 ) . substr( $id, -1 ), 'publishedAt' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ), 'liveBroadcastContent' => $live_mode ? 'live' : 'upcoming' ), 'liveStreamingDetails' => $detail, 'status' => array( 'privacyStatus' => 'public', 'embeddable' => true, 'uploadStatus' => 'processed' ), 'contentDetails' => array( 'duration' => 'PT0S' ) );
			} $body = array( 'items' => $items );
		}
	}
	if ( 'id.twitch.tv' === $host ) { $body = array( 'access_token' => 'fixture-token', 'expires_in' => 3600 ); }
	if ( 'api.twitch.tv' === $host ) {
		if ( '/helix/users' === $path ) { $login = $q['login']; $body = array( 'data' => $fail && 'beerjob4' === $login ? array() : array( array( 'id' => substr( $login, -1 ), 'login' => $login ) ) ); }
		if ( '/helix/schedule' === $path ) { $body = array( 'data' => array( 'segments' => array( array( 'id' => 'planned-' . $q['broadcaster_id'], 'title' => 'Craft beer tasting', 'start_time' => gmdate( DATE_ATOM, $now + 900 ), 'end_time' => gmdate( DATE_ATOM, $now + 4500 ) ) ) ) ); }
		if ( in_array( $path, array( '/helix/streams', '/helix/videos' ), true ) ) { $body = array( 'data' => array(), 'pagination' => array() ); }
	}
	if ( 'example.com' === $host ) { return array( 'response' => array( 'code' => 200 ), 'body' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fixture-beer\r\nDTSTART:" . gmdate( 'Ymd\THis\Z', $now + 1200 ) . "\r\nDTEND:" . gmdate( 'Ymd\THis\Z', $now + 4800 ) . "\r\nSUMMARY:Craft beer official feed\r\nURL:https://example.com/tasting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" ); }
	if ( null === $body ) { throw new RuntimeException( 'Unexpected endpoint ' . $host . $path ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
}, 10, 3 );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $admins[0]->ID );
$key = BSC_TV_Player_Daily::key(); $_SERVER['HTTPS'] = 'on';
$request = new WP_REST_Request( 'GET', '/bsc-tv/v1/server-daily' );
server_check( 403 === rest_do_request( $request )->get_status() && ! $calls, 'Daily hosting job requires the existing import credential before any API request' );
$request->set_param( 'key', array( 'bad' ) ); server_check( 403 === rest_do_request( $request )->get_status(), 'Malformed credential is rejected' );
$request->set_param( 'key', $key ); $_SERVER['HTTPS'] = 'off'; server_check( 403 === rest_do_request( $request )->get_status() && ! $calls, 'Hosting job cannot expose its credential over HTTP' ); $_SERVER['HTTPS'] = 'on';
wp_schedule_single_event( time() + 60, 'fixture_other_plugin_job' );
foreach ( array( BSC_TV_Sync::DAILY, BSC_TV_Sync::CONTINUE_DAILY, BSC_TV_Sync::DAILY_NOW, BSC_TV_Sync::LIVE, BSC_TV_Sync::LIVE_NOW, BSC_TV_Player_Schedule::HOURLY, BSC_TV_Player_Schedule::NOW ) as $hook ) { wp_schedule_single_event( time() + 30, $hook ); }
BSC_TV_Server::set_mode( true );
foreach ( array( BSC_TV_Sync::DAILY, BSC_TV_Sync::CONTINUE_DAILY, BSC_TV_Sync::DAILY_NOW, BSC_TV_Sync::LIVE, BSC_TV_Sync::LIVE_NOW, BSC_TV_Player_Schedule::HOURLY, BSC_TV_Player_Schedule::NOW ) as $hook ) { server_check( ! wp_next_scheduled( $hook ), 'Server mode removes BSC TV event ' . $hook ); }
server_check( wp_next_scheduled( 'fixture_other_plugin_job' ), 'Other WordPress extensions retain their scheduled jobs' );
BSC_TV_Sync::activate(); BSC_TV_Sync::schedule(); BSC_TV_Player_Schedule::request();
server_check( ! wp_next_scheduled( BSC_TV_Sync::DAILY_NOW ) && ! wp_next_scheduled( BSC_TV_Player_Schedule::NOW ), 'Activation and catalogue changes cannot recreate BSC TV cron events' );
do_action( BSC_TV_Sync::DAILY ); do_action( BSC_TV_Sync::CONTINUE_DAILY ); do_action( BSC_TV_Sync::LIVE );
server_check( ! $calls, 'Stale WP-Cron callbacks cannot start platform work in server mode' );
$response = rest_do_request( $request ); $report = $response->get_data();
server_check( 200 === $response->get_status() && 'completed' === $report['status'], 'One authenticated daily request completes future discovery and replay phases' );
server_check( 5 === $report['discovery']['processed'] && 0 === $report['discovery']['remaining'] && 0 === $report['discovery']['errors'], 'All five API and calendar channels are drained without cron continuations' );
server_check( $report['discovery']['announcements'] >= 4 && ! get_option( BSC_TV_Sync::QUEUE ) && get_option( BSC_TV_Sync::LAST_DAILY ), 'Upcoming announcements reach stored channel agendas and the completed discovery timestamp' );
server_check( 'completed' === $report['replays']['status'] && $report['replays']['pages'] > 1, 'Replay pagination is still handled in the same hosting passage' );
server_check( 'no-store, private, max-age=0' === $response->get_headers()['Cache-Control'] && false === strpos( wp_json_encode( $report ), $key ), 'Hosting response is uncached and contains no execution secret' );
$before = count( $calls ); $again = rest_do_request( $request )->get_data();
server_check( 'already_run' === $again['status'] && count( $calls ) === $before, 'Repeated daily calls cannot restart either collection phase' );
$live_request = new WP_REST_Request( 'GET', '/bsc-tv/v1/server-live' );
server_check( 403 === rest_do_request( $live_request )->get_status(), 'The light job is authenticated too' );
$live_request->set_param( 'key', $key ); $live_mode = true;
$live_response = rest_do_request( $live_request )->get_data(); $light = array_slice( $calls, $before );
server_check( 'completed' === $live_response['status'] && get_option( BSC_TV_Sync::LAST_LIVE ), 'Hosting light job checks official live status' );
server_check( ! array_filter( $light, static function ( $url ) { return preg_match( '~/(search|playlistItems|schedule|videos)$~', $url ) && false === strpos( $url, 'youtube/v3/videos' ); } ), 'Light job never searches announcements or imports replay pages' );
server_check( count( array_filter( $light, static function ( $url ) { return false !== strpos( $url, 'youtube/v3/videos' ); } ) ) > 0, 'Known upcoming YouTube videos receive their live-state check' );
$before = count( $calls ); server_check( 'already_run' === rest_do_request( $live_request )->get_data()['status'] && $before === count( $calls ), 'The light job cannot spend more quota through repeated calls within five minutes' );
$saved_tick = get_option( BSC_TV_Server::TICK ); $saved_tick['started_at'] -= 301; update_option( BSC_TV_Server::TICK, $saved_tick, false );
server_check( 'completed' === BSC_TV_Server::tick()['status'], 'The next eligible light passage can run without cron' );
$old = get_option( BSC_TV_Server::REPORT ); $old['day'] = '2000-01-01'; update_option( BSC_TV_Server::REPORT, $old, false ); update_option( BSC_TV_Sync::LAST_DAILY, $now - DAY_IN_SECONDS, false );
$partial = BSC_TV_Server::daily( 1 ); $pending = get_option( BSC_TV_Sync::QUEUE );
server_check( 'partial' === $partial['status'] && 1 === $partial['discovery']['processed'] && 4 === count( $pending ), 'A bounded passage checkpoints the remaining channels for the next day' );
$before = count( $calls ); server_check( 'already_run' === BSC_TV_Server::daily()['status'] && count( $calls ) === $before, 'A partial passage cannot be repeated the same day' );
$partial['day'] = '2000-01-01'; update_option( BSC_TV_Server::REPORT, $partial, false );
$resumed = BSC_TV_Server::daily();
server_check( 4 === $resumed['discovery']['processed'] && 0 === $resumed['discovery']['remaining'], 'Next-day passage resumes the saved queue instead of rescanning processed channels' );
$resumed['day'] = '2000-01-01'; update_option( BSC_TV_Server::REPORT, $resumed, false );
$before = count( $calls ); $adopt = BSC_TV_Server::daily();
server_check( 'already_run' === $adopt['discovery']['status'] && $before === count( $calls ), 'An already completed legacy discovery and replay passage is adopted without duplicate API work' );
$adopt['day'] = '2000-01-01'; update_option( BSC_TV_Server::REPORT, $adopt, false );
$held = BSC_TV_Content::lock( BSC_TV_Sync::LOCK ); $before = count( $calls );
server_check( is_wp_error( BSC_TV_Server::daily() ) && '2000-01-01' === get_option( BSC_TV_Server::REPORT )['day'] && $before === count( $calls ), 'A competing live check does not consume a daily claim or start duplicate work' ); BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $held );
$reject = static function ( $new, $old ) { return $old; }; add_filter( 'pre_update_option_' . BSC_TV_Server::REPORT, $reject, 10, 2 );
server_check( 'server_claim' === BSC_TV_Server::daily()->get_error_code() && $before === count( $calls ), 'Failure to save the daily claim prevents all API work' ); remove_filter( 'pre_update_option_' . BSC_TV_Server::REPORT, $reject );
server_check( ! get_option( BSC_TV_Server::LOCK ) && ! get_option( BSC_TV_Sync::LOCK ), 'Every early return releases the server and sync locks' );
update_option( BSC_TV_Sync::LAST_DAILY, $now - DAY_IN_SECONDS, false ); $fail = true;
$stale_user = (array) get_post_meta( $ids[3], BSC_TV_Content::STATE, true ); unset( $stale_user['twitch_user'] ); $stale_user['twitch_user_at'] = 0; update_post_meta( $ids[3], BSC_TV_Content::STATE, $stale_user );
$error_report = BSC_TV_Server::daily();
server_check( 'completed_with_errors' === $error_report['status'] && 1 === $error_report['discovery']['errors'] && 0 === $error_report['discovery']['remaining'], 'A missing channel is reported without preventing other agendas from updating' );
$before = count( $calls ); $legacy = new WP_REST_Request( 'GET', '/bsc-tv/v1/import-daily' ); $legacy->set_param( 'key', $key );
server_check( 'already_run' === rest_do_request( $legacy )->get_data()['status'] && count( $calls ) === $before, 'Legacy hosting URLs share the complete daily guard after migration' );
BSC_TV_Server::set_mode( false );
server_check( wp_next_scheduled( BSC_TV_Sync::DAILY ) && wp_next_scheduled( BSC_TV_Sync::LIVE ) && wp_next_scheduled( BSC_TV_Player_Schedule::HOURLY ), 'Disabling server mode reversibly restores the previous scheduler' );
echo "OK $checks Infomaniak server-job integration checks (mocked platforms).\n";
