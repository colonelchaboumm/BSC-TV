<?php
/** php tests/player-diversity.php /path/to/disposable-wordpress */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-diversity-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/player-admin.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0;
function diversity_check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $users[0]->ID ); $admin = get_current_user_id();
set_transient( Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );
foreach ( get_posts( array( 'post_type' => array( BSC_TV_Music::POST, BSC_TV_Content::CHANNEL, BSC_TV_Content::SHOW ), 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'max_channel_streak' => 0 ) );
$music = BSC_TV_Music::normalize( array( 'enabled' => true, 'flexible' => false, 'repeat_hours' => 0 ) ); $music['bands']['evening']['name'] = 'BSC Legends personnalisé';
update_option( BSC_TV_Music::SETTINGS, $music );
update_option( BSC_TV_Sync::SETTINGS, array( 'youtube_key' => 'fixture-only-key' ) );
$now = time(); $channels = array(); $shows = array();
global $wpdb; $wpdb->query( 'START TRANSACTION' );
for ( $n = 0; $n < 50; ++$n ) {
	$remote = 'UC' . str_pad( (string) $n, 22, '0', STR_PAD_LEFT );
	$id = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_status' => 'publish', 'post_title' => 'Craft beer source ' . $n, 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::channel_data( array( 'url' => 'https://www.youtube.com/channel/' . $remote ) ) ) ) ); $channels[] = $id;
	update_post_meta( $id, BSC_TV_Content::STATE, array( 'key' => $remote, 'channel_id' => $remote, 'uploads_playlist' => 'UU' . substr( $remote, 2 ) ) );
	for ( $episode = 0; $episode < ( 0 === $n ? 8 : 2 ); ++$episode ) {
		$video = 'v' . str_pad( (string) ( 100 * $n + $episode ), 10, '0', STR_PAD_LEFT );
		$show = wp_insert_post( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => 'publish', 'post_title' => 'Émission ' . $n . ' / ' . $episode, 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::show_data( array( 'url' => 'https://youtu.be/' . $video, 'channel_id' => $id, 'status' => 'ended', 'starts_at' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ) ) ), BSC_TV_Player::MEDIA => array( 'mode' => 'yes', 'duration' => 3000, 'published_at' => $now - DAY_IN_SECONDS ) ) ) ); $shows[] = $show;
	}
}
for ( $n = 0; $n < 4; ++$n ) {
	$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Musique de test ' . $n ) );
	update_post_meta( $id, BSC_TV_Music::META, array( 'artist' => 'Artiste ' . $n, 'title' => 'Clip de test ' . $n, 'youtube_id' => str_repeat( chr( 97 + $n ), 11 ), 'url' => 'https://youtu.be/' . str_repeat( chr( 97 + $n ), 11 ), 'duration' => 200, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Disposable fixture only, not real rights.', 'availability' => 'available' ) );
}
$wpdb->query( 'COMMIT' );
delete_option( BSC_TV_Player::STATE ); delete_transient( BSC_TV_Player::CACHE ); BSC_TV_Music::invalidate();
BSC_TV_Player::snapshot(); $before = get_option( BSC_TV_Player::STATE );
diversity_check( 50 === count( array_unique( array_column( array_filter( BSC_TV_Player::media(), static function ( $m ) { return empty( $m['music'] ); } ), 'channel_id' ) ) ), 'Fifty different replay sources can populate the existing catalogue' );
diversity_check( ! array_filter( $before['plan'], static function ( $r ) { return ! empty( $r['music'] ); } ), 'Adjacent programmes cannot receive music in gap-only mode' );
$before['plan'][3]['fixed'] = true; $anchor = $before['plan'][3]; $before['manual'] = array( 999999999 ); update_option( BSC_TV_Player::STATE, $before, false );
$clips_before = BSC_TV_Music::clips();
wp_set_current_user( 0 ); diversity_check( is_wp_error( BSC_TV_Player_Admin::compose_programme() ), 'Anonymous users cannot recompose the site programme' ); wp_set_current_user( $admin );
$die = static function () { return static function () { throw new RuntimeException( 'compose-denied' ); }; };
add_filter( 'wp_die_handler', $die ); $_REQUEST['_wpnonce'] = 'invalid-nonce'; $denied = false;
try { BSC_TV_Player_Admin::compose(); } catch ( RuntimeException $error ) { $denied = 'compose-denied' === $error->getMessage(); }
remove_filter( 'wp_die_handler', $die ); unset( $_REQUEST['_wpnonce'] );
diversity_check( $denied && $before === get_option( BSC_TV_Player::STATE ), 'The HTTP action rejects an invalid nonce before changing the clock' );
$busy = BSC_TV_Content::lock( BSC_TV_Player::LOCK ); $blocked = BSC_TV_Player_Admin::compose_programme(); BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $busy );
diversity_check( is_wp_error( $blocked ) && $before === get_option( BSC_TV_Player::STATE ), 'A concurrent clock update blocks recomposition without altering its state' );
diversity_check( true === BSC_TV_Player_Admin::compose_programme(), 'The admin can jointly rebuild programme diversity and musical insertion' );
$after = get_option( BSC_TV_Player::STATE ); $settings = BSC_TV_Player::settings();
diversity_check( $before['vod']['key'] === $after['vod']['key'] && $before['vod']['start'] === $after['vod']['start'] && $before['vod']['end'] === $after['vod']['end'], 'Recomposition does not restart or replace the current programme' );
$saved = array_values( array_filter( $after['plan'], static function ( $r ) use ( $anchor ) { return ( $r['id'] ?? '' ) === $anchor['id']; } ) );
diversity_check( $saved && $saved[0]['start'] === $anchor['start'] && $saved[0]['end'] === $anchor['end'], 'Explicitly fixed start and end times remain intact' );
$musical = array_values( array_filter( $after['plan'], static function ( $r ) { return ! empty( $r['music'] ); } ) );
diversity_check( count( $musical ) > 0 && $settings['music']['flexible'], 'Validated music is inserted even when the old programme had no natural gaps or had an obsolete priority' );
diversity_check( $music['bands'] === $settings['music']['bands'], 'Saved category names and time bands are preserved exactly' );
diversity_check( $clips_before === BSC_TV_Music::clips() && $settings['enabled'], 'Recomposition preserves clip metadata and confirmations as well as player activation' );
diversity_check( ! array_filter( $musical, static function ( $r ) use ( $settings ) { return $r['music']['category'] !== BSC_TV_Music::category( $r['start'], $settings['music'] ) || $r['end'] - $r['start'] !== $r['music']['entry'] + $r['music']['duration'] + $r['music']['exit']; } ), 'Every musical slot uses its saved time band and full animation durations' );
diversity_check( 11 === $musical[0]['music']['entry'] && $musical[0]['music']['identity']['entry_video'] !== $musical[0]['music']['identity']['exit_video'], 'Both supplied BSC identities are programmed with the music' );
$coverage = array_unique( array_column( array_filter( $after['plan'], static function ( $r ) { return empty( $r['music'] ); } ), 'channel_id' ) );
diversity_check( count( $coverage ) >= 40, 'The three-day queue includes a broad range of the fifty available channels' );
$pool = BSC_TV_Player::media(); $a = reset( $pool ); $b = next( $pool ); $a['key'] = 'a'; $a['channel_id'] = 1; $a['creator_key'] = 'a'; $b['key'] = 'b'; $b['channel_id'] = 2; $b['creator_key'] = 'b';
$mix = array( 'last_channel' => 'channel:1', 'channel_streak' => 3, 'aired' => array( array( 'key' => 'b', 'channel_id' => 2, 'start' => $now - 7200, 'end' => $now ) ) );
diversity_check( 'b' === BSC_TV_Player::select( array( 'a' => $a, 'b' => $b ), $mix, $settings, $now )['key'], 'A long concurrent channel cannot cause unlimited consecutive short programmes from the first channel' );
diversity_check( 'a' === BSC_TV_Player::select( array( 'a' => $a ), $mix, $settings, $now )['key'], 'The antenna continues when only one channel is actually eligible' );
$b['published_at'] = $now + 100;
diversity_check( 'a' === BSC_TV_Player::select( array( 'a' => $a, 'b' => $b ), $mix, $settings, $now )['key'], 'A variety limit never creates a wait for an unavailable channel' );
$sparse_remote = 'UC' . str_repeat( 'z', 22 );
$sparse = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_status' => 'publish', 'post_title' => 'Source sans replay récent', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::channel_data( array( 'url' => 'https://www.youtube.com/channel/' . $sparse_remote ) ) ) ) );
update_post_meta( $sparse, BSC_TV_Content::STATE, array( 'key' => $sparse_remote, 'channel_id' => $sparse_remote, 'uploads_playlist' => 'UU' . str_repeat( 'z', 22 ) ) );
$api_calls = 0; $unexpected = 0; $old_date = gmdate( DATE_ATOM, $now - 180 * DAY_IN_SECONDS );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$api_calls, &$unexpected, $sparse_remote, $old_date ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( '/youtube/v3/playlistItems' === $path ) { ++$api_calls; return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'items' => array( array( 'contentDetails' => array( 'videoId' => 'old00000001', 'videoPublishedAt' => $old_date ) ) ) ) ) ); }
	if ( '/youtube/v3/videos' === $path ) { ++$api_calls; return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'items' => array( array( 'id' => 'old00000001', 'snippet' => array( 'channelId' => $sparse_remote, 'title' => 'Archive brassicole à enrichir', 'publishedAt' => $old_date, 'liveBroadcastContent' => 'none' ), 'contentDetails' => array( 'duration' => 'PT45M' ), 'status' => array( 'privacyStatus' => 'public', 'embeddable' => true ) ) ) ) ) ); }
	++$unexpected; return new WP_Error( 'unexpected_http', 'No network allowed.' );
}, 10, 3 );
delete_option( BSC_TV_Player_Import::STATE ); delete_transient( BSC_TV_Player::CACHE );
$progress = BSC_TV_Player_Import::run( true );
diversity_check( ! is_wp_error( $progress ) && isset( BSC_TV_Player::media()['youtube:old00000001'] ), 'An underrepresented channel receives an eligible six-month-old episode during enrichment' );
diversity_check( $progress['pending'][ $channels[0] ]['since'] === $progress['since'], 'A sufficiently stocked channel retains the normal two-month import window' );
diversity_check( get_post_meta( $sparse, BSC_TV_Player_Import::REPORT, true )['since'] < $progress['since'], 'The per-channel report records its wider enrichment window' );
diversity_check( $api_calls === 2 && 0 === $unexpected && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Enrichment uses only the normal mocked API page and creates no WP-Cron import' );
ob_start(); BSC_TV_Player_Admin::page(); $html = ob_get_clean();
diversity_check( false !== strpos( $html, 'Recomposer avec les chaînes et la musique' ) && false !== strpos( $html, 'Source sans replay récent' ) && false !== strpos( $html, 'Replays utilisables' ), 'The private admin page explains registered sources, usable replays and future appearances' );
$qa = dirname( __DIR__, 2 ) . '/tv-diversity-qa'; wp_mkdir_p( $qa ); file_put_contents( $qa . '/admin.html', $html );
echo "OK {$checks} catalogue diversity, enrichment and composition checks.\n";
