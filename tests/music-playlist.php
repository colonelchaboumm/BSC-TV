<?php
/** php tests/music-playlist.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-playlist-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0;
function playlist_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { $users = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $users[0]->ID ); }
$admin = get_current_user_id();
foreach ( get_posts( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
delete_transient( BSC_TV_Music::CACHE );
$playlist = 'PLvLT_VV4Sfx3mwWY9T3Zn5OLLhVo-f7Vo'; $url = 'https://www.youtube.com/playlist?list=' . $playlist;
foreach ( array( $url, 'https://music.youtube.com/playlist?list=' . $playlist, 'https://youtu.be/aaaaaaaaaaa?list=' . $playlist, $playlist ) as $input ) { playlist_check( $playlist === BSC_TV_Music_Playlist::playlist_id( $input ), 'Accepts canonical YouTube playlist reference' ); }
foreach ( array( 'https://evil.example/?list=' . $playlist, 'https://www.youtube.com.evil.example/playlist?list=' . $playlist, 'https://user@www.youtube.com/playlist?list=' . $playlist, 'https://www.youtube.com/watch?v=aaaaaaaaaaa', 'file:///test?list=' . $playlist, 'https://youtube.com/playlist?list[]=bad', array(), '' ) as $input ) { playlist_check( '' === BSC_TV_Music_Playlist::playlist_id( $input ), 'Rejects untrusted or malformed reference' ); }
$sync = BSC_TV_Sync::settings(); $sync['youtube_key'] = ''; update_option( BSC_TV_Sync::SETTINGS, $sync );
playlist_check( is_wp_error( BSC_TV_Music_Playlist::start( $url, 'morning' ) ), 'Missing API key is explained before creating a job' );
$sync['youtube_key'] = 'playlist-fixture-key-not-real'; update_option( BSC_TV_Sync::SETTINGS, $sync );
playlist_check( is_wp_error( BSC_TV_Music_Playlist::start( $url, 'bad-category' ) ), 'Unknown categories cannot be imported' );
$requests = array(); $mode = 'normal'; $api_lock_free = true;
function playlist_item( $id, $title, $private = false ) { return array( 'contentDetails' => array( 'videoId' => $id ), 'snippet' => array( 'title' => $title, 'videoOwnerChannelTitle' => 'Actual uploader', 'channelTitle' => 'Playlist curator', 'thumbnails' => array( 'high' => array( 'url' => 'https://example.com/' . $id . '.jpg' ) ) ), 'status' => array( 'privacyStatus' => $private ? 'private' : 'public' ) ); }
add_filter( 'pre_http_request', static function ( $pre, $args, $request_url ) use ( &$requests, &$mode, &$api_lock_free ) {
	parse_str( wp_parse_url( $request_url, PHP_URL_QUERY ), $query ); $path = wp_parse_url( $request_url, PHP_URL_PATH );
	$requests[] = array( $path, $query );
	$clock = BSC_TV_Content::lock( BSC_TV_Player::LOCK ); $api_lock_free = $api_lock_free && (bool) $clock; if ( $clock ) { BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $clock ); }
	if ( 'playlist-error' === $mode || ( 'metadata-error' === $mode && str_ends_with( $path, '/videos' ) ) ) { return new WP_Error( 'fixture_http_error', 'Network unavailable in fixture' ); }
	if ( str_ends_with( $path, '/playlistItems' ) ) {
		if ( 'empty' === $mode ) { $data = array( 'items' => array() ); }
		elseif ( 'capacity' === $mode ) { $data = array( 'items' => array( playlist_item( 'eeeeeeeeeee', 'Extra - Track' ), playlist_item( 'aaaaaaaaaaa', 'Keep - Existing' ) ) ); }
		elseif ( 'cycle' === $mode ) { $data = array( 'items' => array( playlist_item( 'aaaaaaaaaaa', 'Artist A - Track A' ) ), 'nextPageToken' => 'cycle' ); }
		elseif ( 'metadata-error' === $mode ) { $data = array( 'items' => array( playlist_item( 'ddddddddddd', 'Unverified - Metadata missing' ) ) ); }
		elseif ( ! empty( $query['pageToken'] ) ) { $data = array( 'items' => array( playlist_item( 'aaaaaaaaaaa', 'Duplicate A' ), playlist_item( 'bbbbbbbbbbb', 'Artist B – Track B' ) ) ); }
		else { $data = array( 'items' => array( playlist_item( 'aaaaaaaaaaa', 'Artist A - Track A' ), playlist_item( 'aaaaaaaaaaa', 'Duplicate A' ), playlist_item( 'ccccccccccc', 'Deleted video' ), playlist_item( 'private0000', 'Private video', true ), playlist_item( 'bad-id', 'Invalid' ) ), 'nextPageToken' => 'second', 'pageInfo' => array( 'totalResults' => 7 ) ); }
	} else {
		$data = array( 'items' => array() );
		foreach ( explode( ',', $query['id'] ?? '' ) as $id ) {
			if ( 'ccccccccccc' === $id ) { continue; }
			$data['items'][] = array( 'id' => $id, 'snippet' => array( 'title' => 'bbbbbbbbbbb' === $id ? 'Artist B – Track B' : 'Artist A - Track A', 'channelTitle' => 'Official uploader', 'channelId' => 'UCfixture', 'liveBroadcastContent' => 'none', 'publishedAt' => '1998-01-01T00:00:00Z', 'thumbnails' => array( 'high' => array( 'url' => 'https://example.com/' . $id . '.jpg' ) ) ), 'contentDetails' => array( 'duration' => 'PT4M' ), 'status' => array( 'privacyStatus' => 'public', 'embeddable' => true ) );
		}
		if ( 'age' === $mode ) { foreach ( $data['items'] as &$video ) { $video['contentDetails']['contentRating'] = array( 'ytRating' => 'ytAgeRestricted' ); } unset( $video ); }
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
}, 10, 3 );
$job = BSC_TV_Music_Playlist::start( $url, 'morning' );
playlist_check( 0 === count( $requests ) && 0 === $job['step'], 'Starting a job does no network work yet' );
$job = BSC_TV_Music_Playlist::step( $job['token'], 0 );
playlist_check( ! is_wp_error( $job ) && 1 === $job['created'] && ! $job['done'] && 1 === $job['step'], 'First page creates a clip and saves resumable progress' );
playlist_check( 3 === $job['unavailable'] && 1 === $job['duplicates'], 'Missing, private, malformed and duplicate playlist entries are accounted for' );
playlist_check( $api_lock_free && 2 === count( $requests ), 'Playlist and metadata use two bounded requests outside the shared antenna lock' );
playlist_check( 50 === (int) $requests[0][1]['maxResults'] && $playlist === $requests[0][1]['playlistId'], 'Uses official playlistItems pagination with the extracted ID' );
$clips = BSC_TV_Music::clips(); $a = reset( $clips ); $a_id = $a['id'];
playlist_check( array( 'morning' ) === $a['categories'] && 'Artist A' === $a['artist'] && 'Track A' === $a['title'], 'Imports into Morning Chill and suggests artist/title from a clear separator' );
playlist_check( 240 === $a['duration'] && 'Official uploader' === $a['channel'] && str_ends_with( $a['image'], 'aaaaaaaaaaa.jpg' ), 'Fetches real video duration, image and source channel rather than playlist curator' );
playlist_check( ! $a['active'] && ! $a['official'] && 'pending' === $a['rights'] && '' === $a['rights_note'] && ! BSC_TV_Music::approved( $a ), 'Import never activates or approves a new clip' );
playlist_check( $a['url'] === 'https://www.youtube.com/watch?v=aaaaaaaaaaa' && $a['origin_playlist'] === $playlist, 'Stores canonical video and playlist provenance' );
$before = count( $requests ); $old_step = BSC_TV_Music_Playlist::step( $job['token'], 0 );
playlist_check( $old_step === $job && $before === count( $requests ), 'Retry after a lost response returns saved progress without repeating a page' );
playlist_check( is_wp_error( BSC_TV_Music_Playlist::step( $job['token'], 3 ) ) && is_wp_error( BSC_TV_Music_Playlist::step( 'invalid-token', 1 ) ), 'Rejects future steps and unrelated job tokens' );
$job = BSC_TV_Music_Playlist::step( $job['token'], 1 );
playlist_check( $job['done'] && 2 === $job['created'] && 2 === $job['duplicates'] && 2 === count( BSC_TV_Music::clips() ), 'Second page completes without duplicate references across pages' );
playlist_check( 'second' === $requests[2][1]['pageToken'], 'Uses the exact saved page token' );
$before = count( $requests ); BSC_TV_Music_Playlist::step( $job['token'], 2 );
playlist_check( $before === count( $requests ), 'Completed jobs never call the API again' );
$a = get_post_meta( $a_id, BSC_TV_Music::META, true ); $a['artist'] = 'Manually curated artist'; $a['active'] = true; $a['official'] = true; $a['rights'] = 'confirmed'; $a['rights_note'] = 'Fixture only, not real authorization.'; $a['region'] = 'fr'; update_post_meta( $a_id, BSC_TV_Music::META, $a ); BSC_TV_Music::invalidate();
$job = BSC_TV_Music_Playlist::start( $url, 'day' ); do { $job = BSC_TV_Music_Playlist::step( $job['token'], $job['step'] ); } while ( ! $job['done'] );
$assigned = get_post_meta( $a_id, BSC_TV_Music::META, true );
playlist_check( 0 === $job['created'] && 2 === $job['assigned'] && 2 === count( BSC_TV_Music::clips() ), 'Reimporting assigns a different category to existing clips instead of copying them' );
playlist_check( array( 'morning', 'day' ) === $assigned['categories'] && $assigned['artist'] === $a['artist'] && $assigned['rights_note'] === $a['rights_note'] && $assigned['active'] && 'confirmed' === $assigned['rights'] && 'fr' === $assigned['region'], 'Reimport preserves editorial metadata, activity and authorization' );
$mode = 'age'; $restricted = BSC_TV_Music_Playlist::start( $url, 'morning' ); $restricted = BSC_TV_Music_Playlist::step( $restricted['token'], 0 ); $restricted_clip = get_post_meta( $a_id, BSC_TV_Music::META, true );
playlist_check( 'blocked' === $restricted_clip['availability'] && $restricted_clip['rights_note'] === $a['rights_note'] && ! BSC_TV_Music::approved( $restricted_clip ), 'A newly known restriction blocks an existing clip without overwriting editorial approval notes' );
$mode = 'normal';
$lock = BSC_TV_Content::lock( BSC_TV_Music_Playlist::LOCK );
playlist_check( is_wp_error( BSC_TV_Music_Playlist::start( $url, 'night' ) ), 'Concurrent imports cannot overwrite an active job' ); BSC_TV_Content::unlock( BSC_TV_Music_Playlist::LOCK, $lock );
$job = BSC_TV_Music_Playlist::start( $url, 'night' ); $mode = 'playlist-error';
playlist_check( is_wp_error( BSC_TV_Music_Playlist::step( $job['token'], 0 ) ) && 0 === BSC_TV_Music_Playlist::status()['step'], 'Playlist API failure keeps the page cursor for a manual retry' );
$mode = 'normal'; $job = BSC_TV_Music_Playlist::step( $job['token'], 0 );
playlist_check( ! is_wp_error( $job ) && 1 === $job['step'], 'A failed request can be resumed without restarting the import' );
$busy = BSC_TV_Music_Playlist::start( $url, 'morning' ); $clock_lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
$failure = BSC_TV_Music_Playlist::step( $busy['token'], 0 ); $status = BSC_TV_Music_Playlist::status();
playlist_check( is_wp_error( $failure ) && 0 === $status['step'] && 0 === $status['unavailable'] && 0 === $status['duplicates'], 'A busy antenna leaves page counters and cursor unchanged' );
BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $clock_lock ); $retry = BSC_TV_Music_Playlist::step( $busy['token'], 0 );
playlist_check( ! is_wp_error( $retry ) && 3 === $retry['unavailable'], 'Retry after antenna contention counts unavailable entries only once' );
$mode = 'metadata-error'; $job = BSC_TV_Music_Playlist::start( $url, 'morning' ); $job = BSC_TV_Music_Playlist::step( $job['token'], 0 );
$unverified = array_filter( BSC_TV_Music::clips(), static function ( $clip ) { return 'ddddddddddd' === $clip['youtube_id']; } ); $unverified = reset( $unverified );
playlist_check( $job['done'] && $job['warning'] && $unverified && 'unchecked' === $unverified['availability'] && 0 === $unverified['duration'] && ! BSC_TV_Music::approved( $unverified ), 'Metadata errors retain imported links as inactive unverified references' );
$mode = 'cycle'; $job = BSC_TV_Music_Playlist::start( $url, 'morning' ); $job = BSC_TV_Music_Playlist::step( $job['token'], 0 ); $job = BSC_TV_Music_Playlist::step( $job['token'], 1 );
playlist_check( $job['done'] && $job['warning'] && 2 === $job['step'], 'Repeated page tokens stop instead of looping indefinitely' );
$mode = 'empty'; $job = BSC_TV_Music_Playlist::start( $url, 'morning' ); $before = count( $requests ); $job = BSC_TV_Music_Playlist::step( $job['token'], 0 );
playlist_check( $job['done'] && 0 === $job['created'] && count( $requests ) === $before + 1, 'Empty playlists complete without a useless videos metadata request' );
wp_set_current_user( 0 ); $before = count( $requests );
playlist_check( is_wp_error( BSC_TV_Music_Playlist::start( $url, 'morning' ) ) && is_wp_error( BSC_TV_Music_Playlist::step( $job['token'], 0 ) ) && ! BSC_TV_Music_Playlist::status() && count( $requests ) === $before, 'Anonymous users cannot start, resume or view imports' );
wp_set_current_user( $admin );
$other_admin = wp_insert_user( array( 'user_login' => 'playlist-fixture-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) ); wp_set_current_user( $other_admin );
playlist_check( ! BSC_TV_Music_Playlist::status() && is_wp_error( BSC_TV_Music_Playlist::step( $job['token'], 0 ) ), 'Jobs belong to the initiating administrator' ); wp_set_current_user( $admin );
$wpdb->query( 'START TRANSACTION' );
try {
	for ( $n = count( BSC_TV_Music::clips() ); $n < 500; ++$n ) { wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Capacity fixture', 'meta_input' => array( BSC_TV_Music::META => array( 'youtube_id' => sprintf( 'cap%08d', $n ), 'artist' => 'Fixture', 'title' => 'Never approved', 'categories' => array() ) ) ) ); }
} finally { $wpdb->query( 'COMMIT' ); }
BSC_TV_Music::invalidate(); $mode = 'capacity'; $job = BSC_TV_Music_Playlist::start( $url, 'evening' ); $job = BSC_TV_Music_Playlist::step( $job['token'], 0 );
playlist_check( $job['done'] && $job['limited'] && 500 === count( BSC_TV_Music::clips() ), 'Library capacity stops new additions without deleting existing clips' );
playlist_check( in_array( 'evening', get_post_meta( $a_id, BSC_TV_Music::META, true )['categories'], true ), 'Existing references can still receive a category on a full library page' );
playlist_check( ! wp_next_scheduled( 'bsc_tv_music_playlist_import' ), 'Manual playlist import adds no cron event' );
$before = count( $requests ); BSC_TV_Music::pool(); BSC_TV_Music_Playlist::status();
playlist_check( $before === count( $requests ), 'Rendering the library or saved progress never fetches YouTube' );
BSC_TV_Music_Playlist::register();
playlist_check( has_action( 'wp_ajax_bsc_tv_music_playlist' ) && ! has_action( 'wp_ajax_nopriv_bsc_tv_music_playlist' ), 'The AJAX import is registered only for authenticated administration' );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new RuntimeException( 'playlist_json' ); }; } );
function playlist_ajax( $fields ) {
	$_POST = $fields; $_REQUEST = $_POST; ob_start();
	try { BSC_TV_Music_Playlist::ajax(); } catch ( RuntimeException $error ) { if ( 'playlist_json' !== $error->getMessage() ) { throw $error; } }
	return json_decode( ob_get_clean(), true );
}
wp_set_current_user( 0 ); $answer = playlist_ajax( array( 'operation' => 'start', 'url' => $url, 'category' => 'morning' ) );
playlist_check( false === $answer['success'] && ! isset( $answer['data']['token'] ), 'Actual AJAX handler rejects anonymous calls without disclosing job data' );
wp_set_current_user( $admin ); $answer = playlist_ajax( array( 'operation' => 'start', 'url' => $url, 'category' => 'morning', 'nonce' => 'invalid' ) );
playlist_check( false === $answer['success'], 'Actual AJAX handler requires a valid administrator nonce' );
$answer = playlist_ajax( array( 'operation' => 'start', 'url' => $url, 'category' => 'morning', 'nonce' => wp_create_nonce( 'bsc_tv_music_playlist' ) ) );
playlist_check( true === $answer['success'] && ! empty( $answer['data']['token'] ) && ! isset( $answer['data']['cursor'], $answer['data']['seen'] ), 'Valid AJAX start exposes safe progress without private API cursors' );
$qa = dirname( __DIR__, 2 ) . '/tv-music-playlist-qa'; if ( ! is_dir( $qa ) ) { mkdir( $qa ); }
ob_start(); BSC_TV_Music_Admin::page(); $html = ob_get_clean(); file_put_contents( $qa . '/admin.html', $html );
playlist_check( false !== strpos( $html, 'data-music-playlist' ) && false !== strpos( $html, 'name="playlist_category"' ) && false !== strpos( $html, 'BSC Morning Chill' ), 'The existing music admin exposes URL, category selection and resumable progress' );
echo 'OK ' . $checks . " music playlist import WordPress/API checks.\n";
