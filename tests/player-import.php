<?php
/** php tests/player-import.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture_root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! is_file( $fixture_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$database_dir = sys_get_temp_dir() . '/bsc-tv-import-' . bin2hex( random_bytes( 6 ) );
mkdir( $database_dir );
$database = $database_dir . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture_root . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Cannot copy disposable database.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/player-admin.php';
$checks = 0;
function import_check( $value, $label ) {
	global $checks;
	++$checks;
	if ( ! $value ) { throw new RuntimeException( $label ); }
}
function import_post( $type, $title, $data, $status = 'publish', $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'meta_input' => array( BSC_TV_Content::DATA => $data ) ), $extra ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	return $id;
}
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
delete_option( BSC_TV_Player_Import::STATE );
delete_option( BSC_TV_Player::STATE );
delete_transient( BSC_TV_Player::CACHE );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => false, 'repeat_hours' => 0, 'recent' => 'strong', 'enrich_archives' => false ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'youtube_key' => 'fixture-youtube-key', 'twitch_id' => 'fixture-client-id', 'twitch_secret' => 'fixture-client-secret', 'live_enabled' => false ) );
$now = time();
$remote_channel = 'UC' . str_repeat( 'a', 22 );
$profile = import_post( BSC_BDD_Content::POST_TYPE, 'Profil brassicole existant', array() );
$youtube = import_post( BSC_TV_Content::CHANNEL, 'Chaîne bière YouTube', BSC_TV_Content::channel_data( array( 'url' => 'https://www.youtube.com/@beerqa', 'bdd_id' => $profile, 'keywords' => 'beer' ) ) );
$twitch = import_post( BSC_TV_Content::CHANNEL, 'Chaîne bière Twitch', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/beerqa', 'bdd_id' => $profile, 'keywords' => 'beer' ) ) );
$draft_channel = import_post( BSC_TV_Content::CHANNEL, 'Chaîne brouillon', BSC_TV_Content::channel_data( array( 'url' => 'https://www.youtube.com/@draftbeer' ) ), 'draft' );
$excluded_channel = import_post( BSC_TV_Content::CHANNEL, 'Chaîne exclue', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/excludedbeer' ) ) );
update_post_meta( $excluded_channel, BSC_TV_Player::CHANNEL, array( 'replays' => 0 ) );
function youtube_fixture( $number, $age = 1 ) {
	global $now, $remote_channel;
	return array( 'id' => sprintf( 'beer%07d', $number ), 'snippet' => array( 'title' => "Beer's émission " . $number, 'publishedAt' => gmdate( DATE_ATOM, $now - $age * DAY_IN_SECONDS ), 'channelId' => $remote_channel, 'liveBroadcastContent' => 'none', 'thumbnails' => array( 'high' => array( 'url' => 'https://example.com/beer-' . $number . '.jpg' ) ) ), 'contentDetails' => array( 'duration' => 'PT20M' ), 'status' => array( 'privacyStatus' => 'public', 'embeddable' => true, 'uploadStatus' => 'processed' ) );
}
$videos = array();
foreach ( range( 1, 19 ) as $number ) { $video = youtube_fixture( $number ); $videos[ $video['id'] ] = $video; }
$videos['beer0000002']['liveStreamingDetails'] = array( 'actualStartTime' => gmdate( DATE_ATOM, $now - 12 * DAY_IN_SECONDS - 3600 ), 'actualEndTime' => gmdate( DATE_ATOM, $now - 12 * DAY_IN_SECONDS ) );
$videos['beer0000002']['contentDetails']['duration'] = 'PT1H';
$videos['beer0000005']['status']['privacyStatus'] = 'private';
$videos['beer0000006']['snippet']['liveBroadcastContent'] = 'upcoming';
$videos['beer0000006']['liveStreamingDetails'] = array( 'scheduledStartTime' => gmdate( DATE_ATOM, $now + DAY_IN_SECONDS ) );
$videos['beer0000007']['contentDetails']['duration'] = 'PT0S';
$videos['beer0000008']['status']['embeddable'] = false;
$videos['beer0000009']['snippet']['title'] = 'Unrelated gaming';
$videos['beer0000010'] = youtube_fixture( 10, 90 );
$videos['beer0000011']['status']['uploadStatus'] = 'uploaded';
$videos['beer0000013']['snippet']['channelId'] = 'UC' . str_repeat( 'b', 22 );
$videos['beer0000018'] = youtube_fixture( 18, 30 );
$videos['beer0000019'] = youtube_fixture( 19, 100 );
$videos['beer0000020'] = youtube_fixture( 20 );
$videos['beer0000020']['contentDetails']['contentRating']['ytRating'] = 'ytAgeRestricted';
$existing = array();
foreach ( array( 3 => 'draft', 4 => 'publish', 12 => 'publish', 14 => BSC_TV_Content::ARCHIVED, 15 => 'publish', 16 => 'trash', 17 => BSC_TV_Content::ARCHIVED ) as $number => $status ) {
	$data = array( 'url' => 'https://youtu.be/' . sprintf( 'beer%07d', $number ), 'platform' => 'youtube', 'channel_id' => $youtube, 'bdd_id' => 0, 'creator' => '', 'start' => $now - DAY_IN_SECONDS, 'end' => 0, 'status' => 17 === $number ? 'cancelled' : 'upcoming', 'image' => 'https://example.com/editorial.jpg' );
	$existing[ $number ] = import_post( BSC_TV_Content::SHOW, 'Titre éditorial ' . $number, $data, $status, 15 === $number ? array( 'post_password' => 'protected' ) : array() );
}
update_post_meta( $existing[4], BSC_TV_Player::MEDIA, array( 'mode' => 'no' ) );
update_post_meta( $existing[12], BSC_TV_Player::MEDIA, array( 'mode' => 'auto', 'duration' => 4444 ) );
wp_update_post( array( 'ID' => $existing[12], 'post_content' => 'Contenu éditorial à conserver.', 'post_excerpt' => 'Introduction éditoriale.', 'post_name' => 'emission-editoriale' ) );
update_post_meta( $existing[14], BSC_TV_Library::ORIGIN, 'old-feed-entry' );
update_post_meta( $existing[14], BSC_TV_Library::FEED, 'https://example.com/feed' );
update_post_meta( $existing[17], BSC_TV_Player::APPROVED, 1 );
import_check( ! BSC_TV_Player::media(), 'A new installation can have zero usable replays before backfill' );
import_check( 2 === count( BSC_TV_Player_Import::channels() ), 'Only published compatible channels with credentials and replay permission are scanned' );
import_check( gmdate( 'Y-m-d H:i:s', BSC_TV_Player_Import::since( strtotime( '2026-04-30 12:34:56 UTC' ) ) ) === '2026-02-28 12:34:56', 'Two calendar months clamp correctly at a shorter target month' );
$requests = array();
$fail_youtube = false;
$fail_resolve = false;
$repeat_cursor = false;
$unexpected = 0;
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requests, &$fail_youtube, &$fail_resolve, &$repeat_cursor, &$unexpected, &$videos, $remote_channel, $now ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$path = wp_parse_url( $url, PHP_URL_PATH );
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
	$requests[] = $path;
	$body = null;
	if ( 'www.googleapis.com' === $host ) {
		if ( $fail_resolve && '/youtube/v3/channels' === $path ) { return array( 'response' => array( 'code' => 403 ), 'body' => '{}' ); }
		if ( '/youtube/v3/channels' === $path ) { $body = array( 'items' => array( array( 'id' => $remote_channel, 'contentDetails' => array( 'relatedPlaylists' => array( 'uploads' => 'UU' . str_repeat( 'a', 22 ) ) ) ) ) ); }
		if ( '/youtube/v3/playlistItems' === $path ) {
			if ( $fail_youtube ) { return array( 'response' => array( 'code' => 503 ), 'body' => '{}' ); }
			$page = $query['pageToken'] ?? '';
			$numbers = 'page-two' === $page ? array( 18 ) : ( 'old-page' === $page ? array( 19 ) : array_merge( range( 1, 17 ), array( 20 ) ) );
			$items = array();
			foreach ( $numbers as $number ) { $id = sprintf( 'beer%07d', $number ); $items[] = array( 'contentDetails' => array( 'videoId' => $id, 'videoPublishedAt' => $videos[ $id ]['snippet']['publishedAt'] ) ); }
			$body = array( 'items' => $items, 'nextPageToken' => $repeat_cursor ? 'page-two' : ( 'page-two' === $page ? 'old-page' : ( 'old-page' === $page ? 'should-not-fetch' : 'page-two' ) ) );
		}
		if ( '/youtube/v3/videos' === $path ) { $body = array( 'items' => array_values( array_intersect_key( $videos, array_fill_keys( explode( ',', $query['id'] ), true ) ) ) ); }
	}
	if ( 'id.twitch.tv' === $host && '/oauth2/token' === $path ) { $body = array( 'access_token' => 'fixture-token', 'expires_in' => 3600 ); }
	if ( 'api.twitch.tv' === $host ) {
		if ( '/helix/users' === $path ) { $body = array( 'data' => array( array( 'id' => '123', 'login' => 'beerqa' ) ) ); }
		if ( '/helix/streams' === $path ) { $body = array( 'data' => array( array( 'id' => 'active-stream' ) ) ); }
		if ( '/helix/videos' === $path ) {
			$vod = array( 'id' => '101', 'user_id' => '123', 'type' => 'archive', 'viewable' => 'public', 'title' => 'Craft beer Twitch', 'duration' => '1h2m3s', 'published_at' => gmdate( DATE_ATOM, $now - 3 * DAY_IN_SECONDS ), 'created_at' => gmdate( DATE_ATOM, $now - 3 * DAY_IN_SECONDS ), 'stream_id' => 'completed-stream', 'thumbnail_url' => 'https://example.com/beer-%{width}x%{height}.jpg' );
			$items = array( $vod, array_replace( $vod, array( 'id' => '102', 'published_at' => gmdate( DATE_ATOM, $now - 100 * DAY_IN_SECONDS ) ) ), array_replace( $vod, array( 'id' => '103', 'stream_id' => 'active-stream' ) ), array_replace( $vod, array( 'id' => '104', 'duration' => '0s' ) ), array_replace( $vod, array( 'id' => '105', 'user_id' => '999' ) ), array_replace( $vod, array( 'id' => '106', 'viewable' => 'private' ) ), array_replace( $vod, array( 'id' => '107', 'published_at' => gmdate( DATE_ATOM, $now + DAY_IN_SECONDS ) ) ), array_replace( $vod, array( 'id' => '108', 'published_at' => '' ) ) );
			$body = ! empty( $query['after'] ) ? array( 'data' => array(), 'pagination' => array() ) : array( 'data' => $items, 'pagination' => array( 'cursor' => 'twitch-two' ) );
		}
	}
	if ( null === $body ) { ++$unexpected; throw new RuntimeException( 'Unexpected HTTP endpoint ' . $host . $path ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
}, 10, 3 );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'strong', 'enrich_archives' => false ) );
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Activation no longer schedules historical import in WP-Cron' );
wp_clear_scheduled_hook( BSC_TV_Player_Import::JOB );
BSC_TV_Player_Import::schedule();
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Existing installations remove the old import event during migration' );
BSC_TV_Player_Import::run( true );
$state = get_option( BSC_TV_Player_Import::STATE );
import_check( 2 === $state['created'] && 1 === $state['updated'] && 'page-two' === $state['pending'][ $youtube ]['cursor'], 'First page imports public uploads and completed lives and persists its next cursor' );
import_check( 1 === count( array_filter( $requests, static function ( $path ) { return '/youtube/v3/playlistItems' === $path; } ) ), 'A background job processes just one source page' );
$pool = BSC_TV_Player::media();
import_check( 3 === count( $pool ) && 1200 === $pool['youtube:beer0000001']['duration'] && 3600 === $pool['youtube:beer0000002']['duration'], 'Only complete eligible videos with official durations reach the catalogue' );
import_check( ! isset( BSC_TV_Player_Import::index()['youtube:beer0000020'] ), 'A public embeddable video with ytAgeRestricted is not imported as a replay' );
import_check( 4444 === $pool['youtube:beer0000012']['duration'] && 'Titre éditorial 12' === get_the_title( $existing[12] ) && 'https://example.com/editorial.jpg' === BSC_TV_Content::data( $existing[12] )['image'] && 'Contenu éditorial à conserver.' === get_post( $existing[12] )->post_content && 'Introduction éditoriale.' === get_post( $existing[12] )->post_excerpt && 'emission-editoriale' === get_post( $existing[12] )->post_name, 'Existing editorial title, content, excerpt, slug, image and duration override are retained' );
foreach ( array( 3, 4, 14, 15, 16, 17 ) as $number ) { import_check( ! isset( $pool[ 'youtube:' . sprintf( 'beer%07d', $number ) ] ), 'Draft, opt-out, unapproved feed archive, password, trash and cancellation remain excluded: ' . $number ); }
import_check( 'draft' === get_post_status( $existing[3] ) && 'trash' === get_post_status( $existing[16] ), 'Recovery never republishes existing drafts or trash' );
$snapshot = BSC_TV_Player::snapshot();
import_check( $snapshot['current'] && ! $snapshot['current']['live'] && $snapshot['current']['profile_url'] && count( get_option( BSC_TV_Player::STATE )['plan'] ) > 5, 'First imported batch starts the antenna and fills its schedule with inherited BDD profile' );
$on_air = get_option( BSC_TV_Player::STATE )['vod'];
$request_count = count( $requests );
BSC_TV_Player::snapshot();
import_check( $request_count === count( $requests ), 'Public playback still makes no platform API calls' );
BSC_TV_Player_Import::run( true );
import_check( isset( BSC_TV_Player::media()['twitch:v101'] ), 'Other channels are scanned before the first channel consumes its next page' );
BSC_TV_Player_Import::run( true );
import_check( isset( BSC_TV_Player::media()['youtube:beer0000018'] ) && get_option( BSC_TV_Player_Import::STATE )['pending'][ $youtube ]['cursor'] === 'old-page', 'Next cron worker resumes the saved page and imports an older video inside the two-month window' );
BSC_TV_Player_Import::run( true );
BSC_TV_Player_Import::run( true );
import_check( ! isset( get_option( BSC_TV_Player_Import::STATE )['pending'][ $youtube ] ) && ! isset( BSC_TV_Player::media()['youtube:beer0000019'] ), 'A page older than the two-month window ends that channel scan' );
$pool = BSC_TV_Player::media();
import_check( isset( $pool['twitch:v101'] ) && 3723 === $pool['twitch:v101']['duration'], 'Twitch VODs receive exact official durations and participate in automatic playback' );
import_check( 5 === count( $pool ) && ! isset( $pool['twitch:v103'] ), 'Active Twitch broadcasts, expired dates, private items, wrong owner and invalid durations are not imported' );
$twitch_show = $pool['twitch:v101']['show_id'];
import_check( 'https://example.com/beer-320x180.jpg' === BSC_TV_Content::data( $twitch_show )['image'], 'Twitch image placeholders become a supported thumbnail URL' );
$edited = BSC_TV_Content::data( $twitch_show );
foreach ( array( 'duration', 'published_at', 'playable', 'embeddable' ) as $field ) { unset( $edited[ $field ] ); }
update_post_meta( $twitch_show, BSC_TV_Content::DATA, $edited );
import_check( isset( BSC_TV_Player::media()['twitch:v101'] ), 'A later editorial save does not lose Twitch replay metadata' );
$state = get_option( BSC_TV_Player_Import::STATE );
import_check( ! $state['pending'] && ! empty( $state['finished_at'] ) && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Completed recovery clears its continuation event and records a report' );
import_check( $on_air === get_option( BSC_TV_Player::STATE )['vod'], 'Later batches never interrupt or restart the already broadcasting programme' );
$show_count = count( BSC_TV_Player_Import::index() );
for ( $step = 0; $step < 5; ++$step ) { BSC_TV_Player_Import::run( true ); }
import_check( $show_count === count( BSC_TV_Player_Import::index() ) && 0 === get_option( BSC_TV_Player_Import::STATE )['created'], 'Rerunning the complete two-month scan updates matches without creating duplicates' );
import_check( ! in_array( '/youtube/v3/search', $requests, true ), 'History recovery uses uploads playlists without expensive search.list requests' );
$metadata_refresh = new ReflectionMethod( BSC_TV_Sync::class, 'manual_youtube' );
$metadata_refresh->setAccessible( true );
$metadata_refresh->invoke( null, 'fixture-youtube-key', time(), false );
BSC_TV_Library::archive_shows( time() );
import_check( isset( BSC_TV_Player::media()['youtube:beer0000001'] ) && 'ended' === BSC_TV_Content::data( $pool['youtube:beer0000001']['show_id'] )['status'], 'The existing daily metadata refresh keeps regular uploaded videos eligible instead of cancelling them' );
BSC_TV_Player_Import::schedule();
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Normal visits do not start another scan after a completed run today' );
BSC_TV_Player_Import::request();
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Legacy manual requests do not recreate a WP-Cron import' );
delete_option( BSC_TV_Player_Import::STATE );
$fail_youtube = true;
for ( $step = 0; $step < 8; ++$step ) { $progress = BSC_TV_Player_Import::run( true ); if ( ! isset( $progress['pending'][ $youtube ] ) ) { break; } }
$report = get_post_meta( $youtube, BSC_TV_Player_Import::REPORT, true );
import_check( ! empty( $report['error'] ) && 1 === get_option( BSC_TV_Player_Import::STATE )['errors'] && ! isset( get_option( BSC_TV_Player_Import::STATE )['pending'][ $youtube ] ), 'API failures retry three times and report the failure without blocking the other channels' );
$fail_youtube = false;
$fail_resolve = true;
$unresolved = BSC_TV_Player_Import::channels()[ $youtube ];
$unresolved['state'] = array( 'key' => $unresolved['key'] );
$resolve_error = BSC_TV_Sync::replay_page( $unresolved, BSC_TV_Sync::settings(), BSC_TV_Player_Import::since( $now ), $now );
import_check( is_wp_error( $resolve_error ) && 403 === $resolve_error->get_error_data(), 'A failed handle lookup returns an API error rather than accessing an error object as an array' );
$fail_resolve = false;
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $administrators[0] );
ob_start();
BSC_TV_Player_Admin::section();
$admin_html = ob_get_clean();
import_check( false !== strpos( $admin_html, 'bsc_tv_player_import_key' ) && false !== strpos( $admin_html, 'HTTP 503' ) && false !== strpos( $admin_html, 'Résultats par chaîne' ), 'The live administration exposes the Infomaniak configuration and per-channel API diagnostics' );
$die_handler = static function () { return static function () { throw new RuntimeException( 'blocked-admin-import' ); }; };
add_filter( 'wp_die_handler', $die_handler );
foreach ( array( 0, $administrators[0] ) as $user_id ) {
	wp_set_current_user( $user_id );
	$_REQUEST['_wpnonce'] = 'invalid-nonce';
	$blocked = false;
	ob_start();
	try { BSC_TV_Player_Admin::import_replays(); } catch ( RuntimeException $error ) { $blocked = 'blocked-admin-import' === $error->getMessage(); }
	ob_end_clean();
	import_check( $blocked, $user_id ? 'An administrator with an invalid nonce cannot launch an import' : 'An anonymous visitor cannot launch an import through the admin action' );
	$protected_key = get_option( BSC_TV_Player_Daily::KEY );
	$blocked_key = false;
	ob_start();
	try { BSC_TV_Player_Admin::renew_import_key(); } catch ( RuntimeException $error ) { $blocked_key = 'blocked-admin-import' === $error->getMessage(); }
	ob_end_clean();
	import_check( $blocked_key && $protected_key === get_option( BSC_TV_Player_Daily::KEY ), 'The dedicated credential-rotation action requires administrator rights and its own valid nonce: ' . $user_id );
}
remove_filter( 'wp_die_handler', $die_handler );
unset( $_REQUEST['_wpnonce'] );
wp_set_current_user( 0 );
$disabled_test = get_option( BSC_TV_Player_Import::STATE );
$disabled_test['pending'] = array( $twitch => array( 'key' => BSC_TV_Player_Import::channels()[ $twitch ]['key'], 'cursor' => '', 'pages' => 0, 'attempts' => 0, 'seen' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0 ) );
update_option( BSC_TV_Player_Import::STATE, $disabled_test, false );
$lock = BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
$before_lock = get_option( BSC_TV_Player_Import::STATE );
$request_count = count( $requests );
BSC_TV_Player_Import::run( true );
import_check( $before_lock === get_option( BSC_TV_Player_Import::STATE ) && $request_count === count( $requests ), 'An existing synchronization lock defers recovery without duplicate API work' );
BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $lock );
update_post_meta( $twitch, BSC_TV_Player::CHANNEL, array( 'replays' => 0 ) );
BSC_TV_Player_Import::run( true );
import_check( ! get_option( BSC_TV_Player_Import::STATE )['pending'] && $request_count === count( $requests ), 'A channel disabled between pages is removed without another API request' );
delete_option( BSC_TV_Player_Import::STATE );
$repeat_cursor = true;
BSC_TV_Player_Import::run( true );
BSC_TV_Player_Import::run( true );
import_check( ! get_option( BSC_TV_Player_Import::STATE )['pending'] && 1 === get_option( BSC_TV_Player_Import::STATE )['errors'], 'A repeated API cursor stops with a partial-import report rather than looping indefinitely' );
$repeat_cursor = false;
$request_count = count( $requests );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => false ) );
BSC_TV_Player_Import::run( true );
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ) && $request_count === count( $requests ), 'Disabling the player cancels historical recovery and prevents external requests' );
update_option( BSC_TV_Sync::SETTINGS, array() );
import_check( ! BSC_TV_Player_Import::channels(), 'Missing API credentials are not treated as a successful compatible source' );
import_check( 0 === $unexpected, 'All source API responses were mocked; no real API traffic was used' );
update_option( BSC_TV_Sync::SETTINGS, array( 'youtube_key' => 'fixture-youtube-key', 'twitch_id' => 'fixture-client-id', 'twitch_secret' => 'fixture-client-secret', 'live_enabled' => false ) );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'strong', 'enrich_archives' => false ) );
update_post_meta( $twitch, BSC_TV_Player::CHANNEL, array( 'replays' => 1 ) );
delete_option( BSC_TV_Player_Import::STATE );
delete_option( BSC_TV_Player_Daily::REPORT );
$request_count = count( $requests );
do_action( BSC_TV_Player_Import::JOB );
import_check( ! has_action( BSC_TV_Player_Import::JOB ) && $request_count === count( $requests ), 'The old WP-Cron hook cannot execute the historical import' );
import_check( is_wp_error( BSC_TV_Player_Import::run() ) && $request_count === count( $requests ), 'Historical import rejects callers outside the external worker' );
wp_schedule_single_event( time() + 15, BSC_TV_Player_Import::JOB );
BSC_TV_Player_Import::schedule();
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Migration removes an existing legacy import event' );
wp_set_current_user( $administrators[0] );
$key = BSC_TV_Player_Daily::key();
import_check( 64 === strlen( $key ), 'An administrator receives a dedicated random import key' );
wp_set_current_user( 0 );
import_check( '' === BSC_TV_Player_Daily::key( true ) && $key === get_option( BSC_TV_Player_Daily::KEY ), 'Anonymous users cannot read or rotate the import credential' );
$_SERVER['HTTPS'] = 'on';
$request = new WP_REST_Request( 'GET', '/bsc-tv/v1/import-daily' );
$denied = rest_do_request( $request );
import_check( 403 === $denied->get_status() && $request_count === count( $requests ), 'The daily endpoint refuses requests without its credential before any API work' );
$request->set_param( 'key', array( 'invalid' ) );
import_check( 403 === rest_do_request( $request )->get_status(), 'Malformed credentials are rejected safely' );
$request->set_param( 'key', $key );
$_SERVER['HTTPS'] = 'off';
import_check( 403 === rest_do_request( $request )->get_status() && $request_count === count( $requests ), 'The authenticated external endpoint refuses cleartext HTTP in production' );
$_SERVER['HTTPS'] = 'on';
$response = rest_do_request( $request );
$daily = $response->get_data();
import_check( 200 === $response->get_status() && 'completed' === $daily['status'] && 5 === $daily['pages'] && 0 === $daily['remaining'], 'One authenticated Infomaniak request drains all five source pages in the same daily run' );
import_check( 'no-store, private, max-age=0' === $response->get_headers()['Cache-Control'] && false === strpos( wp_json_encode( $daily ), $key ), 'Daily responses are private, uncached and contain no execution secret' );
$request_count = count( $requests );
for ( $attempt = 0; $attempt < 5; ++$attempt ) { $again = rest_do_request( $request )->get_data(); }
import_check( 'already_run' === $again['status'] && $request_count === count( $requests ), 'Repeated calls on the same calendar day perform zero additional platform requests' );
update_post_meta( $youtube, BSC_TV_Player::CHANNEL, array( 'replays' => 1 ) );
BSC_TV_Player_Import::request();
import_check( 'already_run' === BSC_TV_Player_Daily::execute()['status'] && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Editing channels does not bypass the daily limit or restart WP-Cron imports' );
wp_set_current_user( $administrators[0] );
$renewed = BSC_TV_Player_Daily::key( true );
wp_set_current_user( 0 );
import_check( 403 === rest_do_request( $request )->get_status(), 'Rotating the credential revokes the previous import URL' );
$request->set_param( 'key', $renewed );
import_check( 'already_run' === rest_do_request( $request )->get_data()['status'] && $request_count === count( $requests ), 'A credential rotation cannot bypass the daily limit' );
delete_option( BSC_TV_Player_Daily::REPORT );
$partial = BSC_TV_Player_Daily::execute( 1 );
$checkpoint = get_option( BSC_TV_Player_Import::STATE );
import_check( 'partial' === $partial['status'] && $checkpoint['pending'] && count( array_filter( $checkpoint['pending'], static function ( $job ) { return ! empty( $job['pages'] ) && ! empty( $job['cursor'] ); } ) ) === 1, 'A bounded daily run saves the processed channel cursor and remaining channels for the next day' );
$request_count = count( $requests );
import_check( 'already_run' === BSC_TV_Player_Daily::execute()['status'] && $request_count === count( $requests ), 'An interrupted or partial run still consumes the one permitted daily execution' );
$partial['day'] = '2000-01-01';
update_option( BSC_TV_Player_Daily::REPORT, $partial, false );
$resumed = BSC_TV_Player_Daily::execute();
import_check( 'completed' === $resumed['status'] && 4 === $resumed['pages'] && get_option( BSC_TV_Player_Import::STATE )['since'] === $checkpoint['since'], 'The next permitted day resumes the saved cursor rather than restarting the full scan' );
update_option( BSC_TV_Player_Daily::REPORT, array( 'day' => '2000-01-01' ), false );
$sync_lock = BSC_TV_Content::lock( BSC_TV_Sync::LOCK );
$request_count = count( $requests );
$busy = BSC_TV_Player_Daily::execute();
import_check( is_wp_error( $busy ) && $request_count === count( $requests ) && '2000-01-01' === get_option( BSC_TV_Player_Daily::REPORT )['day'], 'A competing collector causes no duplicate work and does not consume a daily run that never started' );
BSC_TV_Content::unlock( BSC_TV_Sync::LOCK, $sync_lock );
$reject_claim = static function ( $value, $old ) { return $old; };
add_filter( 'pre_update_option_' . BSC_TV_Player_Daily::REPORT, $reject_claim, 10, 2 );
$claim_error = BSC_TV_Player_Daily::execute();
remove_filter( 'pre_update_option_' . BSC_TV_Player_Daily::REPORT, $reject_claim );
import_check( is_wp_error( $claim_error ) && 'import_claim' === $claim_error->get_error_code() && $request_count === count( $requests ), 'Failure to persist the daily claim prevents all API work rather than weakening the once-a-day guarantee' );
wp_set_current_user( $administrators[0] );
BSC_TV_Player_Admin::menu();
global $submenu;
$live_menu = array_filter( $submenu['bsc-tv'], static function ( $row ) { return 'bsc-tv-live' === $row[2] && '1 · Le live Monde' === $row[0] && 'manage_options' === $row[1]; } );
import_check( 1 === count( $live_menu ), 'BSC TV has a dedicated administrator-only Le live submenu' );
ob_start(); BSC_TV_Player_Admin::page(); $live_html = ob_get_clean();
import_check( 3 === substr_count( $live_html, 'class="bsc-player-grid-day"' ) && false !== strpos( $live_html, 'Import quotidien' ) && false !== strpos( $live_html, 'bsc-tv-live' ), 'The Le live page renders the saved three-day schedule and daily Infomaniak settings together' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => false ) );
$request_count = count( $requests );
import_check( 'disabled' === BSC_TV_Player_Daily::execute()['status'] && $request_count === count( $requests ), 'Disabling the antenna prevents external import work' );
// Playback safety also applies to existing, manually included and overridden sources.
$fail_youtube = $fail_resolve = false;
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'live_priority' => true ) );
$source = BSC_TV_Player_Import::index()['youtube:beer0000001'][0];
$editorial = BSC_TV_Content::data( $source->ID );
$manual = array( 'mode' => 'yes', 'duration' => 1200, 'published_at' => $now - DAY_IN_SECONDS );
update_post_meta( $source->ID, BSC_TV_Player::MEDIA, $manual );
$pool = BSC_TV_Player::media();
update_option( BSC_TV_Player::STATE, BSC_TV_Player::advance( array(), array( 'youtube:beer0000001' => $pool['youtube:beer0000001'] ), array(), BSC_TV_Player::settings(), time() ), false );
$videos['beer0000001']['contentDetails']['contentRating']['ytRating'] = 'ytAgeRestricted';
$details = new ReflectionMethod( BSC_TV_Sync::class, 'yt_details' );
list( $restricted ) = $details->invoke( null, 'fixture-youtube-key', array( 'beer0000001' ) );
import_check( $restricted['beer0000001']['age_restricted'] && ! $restricted['beer0000001']['playable'] && 'ended' === $restricted['beer0000001']['status'], 'YouTube age ratings prevent playback without cancelling the editorial programme' );
import_check( get_post_meta( $source->ID, BSC_TV_Player::ACCESS, true )['age_restricted'] && ! isset( BSC_TV_Player::media()['youtube:beer0000001'] ), 'An official rating removes an already imported manually included replay from the pool' );
$complete_details = $videos['beer0000001']['contentDetails'];
unset( $videos['beer0000001']['contentDetails'] );
$details->invoke( null, 'fixture-youtube-key', array( 'beer0000001' ) );
import_check( get_post_meta( $source->ID, BSC_TV_Player::ACCESS, true )['age_restricted'], 'Missing content details cannot clear a previously confirmed age restriction' );
$videos['beer0000001']['contentDetails'] = $complete_details;
$safe_snapshot = BSC_TV_Player::snapshot();
import_check( 'youtube:beer0000001' !== ( $safe_snapshot['current']['key'] ?? '' ) && ! array_filter( $safe_snapshot['next'], static function ( $item ) { return 'youtube:beer0000001' === $item['key']; } ), 'The shared clock and saved queue drop a newly restricted current source' );
import_check( $editorial === BSC_TV_Content::data( $source->ID ) && BSC_TV_Content::ARCHIVED === get_post_status( $source ), 'Playback restrictions preserve the existing show, creator association and editorial status' );
ob_start(); BSC_TV_Player_Admin::box( get_post( $source->ID ) ); $age_box = ob_get_clean();
import_check( false !== strpos( $age_box, 'YouTube signale une limite d’âge' ), 'Administration explains why a known age-restricted video is excluded' );
unset( $videos['beer0000001']['contentDetails']['contentRating'] );
$details->invoke( null, 'fixture-youtube-key', array( 'beer0000001' ) );
import_check( isset( BSC_TV_Player::media()['youtube:beer0000001'] ), 'A later official removal of the rating clears the automatic restriction' );
$old_source_state = get_post_meta( $source->ID, BSC_TV_Content::STATE, true );
$stale_source_state = $old_source_state; $stale_source_state['video']['age_restricted'] = true;
update_post_meta( $source->ID, BSC_TV_Content::STATE, $stale_source_state );
import_check( isset( BSC_TV_Player::media()['youtube:beer0000001'] ), 'The latest official access result takes precedence over an older stored age flag' );
update_post_meta( $source->ID, BSC_TV_Content::STATE, $old_source_state );
$videos['beer0000021'] = youtube_fixture( 21 );
$manual['url'] = 'https://youtu.be/beer0000021';
update_post_meta( $source->ID, BSC_TV_Player::MEDIA, $manual );
$videos['beer0000001']['contentDetails']['contentRating']['ytRating'] = 'ytAgeRestricted';
$details->invoke( null, 'fixture-youtube-key', array( 'beer0000001' ) );
import_check( isset( BSC_TV_Player::media()['youtube:beer0000021'] ), 'An old source restriction cannot block a different replacement URL' );
$videos['beer0000021']['contentDetails']['contentRating']['ytRating'] = 'ytAgeRestricted';
$details->invoke( null, 'fixture-youtube-key', array( 'beer0000021' ) );
import_check( ! isset( BSC_TV_Player::media()['youtube:beer0000021'] ), 'A restriction on the actual overridden video cannot be bypassed by manual inclusion' );
unset( $videos['beer0000021']['contentDetails']['contentRating'] );
$details->invoke( null, 'fixture-youtube-key', array( 'beer0000021' ) );
$_POST = array( 'bsc_player_nonce' => wp_create_nonce( 'bsc_tv_player_media' ), 'bsc_player' => $manual + array( 'age_restricted' => 1 ) );
$_POST['bsc_player']['published_at'] = wp_date( 'Y-m-d\TH:i', $now - DAY_IN_SECONDS );
BSC_TV_Player_Admin::save_media( $source->ID, get_post( $source->ID ) );
$_POST = array();
import_check( ! isset( BSC_TV_Player::media()['youtube:beer0000021'] ) && 'youtube:beer0000021' === get_post_meta( $source->ID, BSC_TV_Player::MEDIA, true )['restriction_key'], 'The administrator can exclude a blocked video independently of API flags' );
$live_settings = BSC_TV_Sync::settings(); $live_settings['live_enabled'] = true;
update_option( BSC_TV_Sync::SETTINGS, $live_settings );
$youtube_data = BSC_TV_Content::data( $youtube );
$live_key = BSC_TV_Content::channel_key( 'youtube', $youtube_data['url'] );
$age_live = array( 'id' => 'beer0000021', 'title' => 'Beer age-restricted live', 'url' => 'https://youtu.be/beer0000021', 'start' => time() - 60, 'end' => 0, 'age_restricted' => true, 'embeddable' => true );
update_post_meta( $youtube, BSC_TV_Content::STATE, array( 'key' => $live_key, 'live_at' => time(), 'live' => array( $age_live ) ) );
import_check( empty( BSC_TV_Player::snapshot()['current']['live'] ), 'An age-restricted true live cannot preempt the replay clock' );
$age_live['age_restricted'] = false;
update_post_meta( $youtube, BSC_TV_Content::STATE, array( 'key' => $live_key, 'live_at' => time(), 'live' => array( $age_live ) ) );
import_check( empty( BSC_TV_Player::snapshot()['current']['live'] ), 'A manually blocked video stays excluded even if an older channel cache describes it as unrestricted live' );
import_check( ! wp_next_scheduled( BSC_TV_Player_Import::JOB ) && 0 === $unexpected, 'Restriction checks add no import job and use only existing official metadata endpoints' );

echo "OK {$checks} historical replay and daily import checks (mocked source APIs).\n";
