<?php
/** php tests/player-live-integration.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-live-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite'; copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ); define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
$checks = 0; $requests = 0;
function live_check( $ok, $label ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } }
function live_post( $type, $title, $data, $status = 'publish' ) { return wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'meta_input' => array( BSC_TV_Content::DATA => $data ) ) ); }
add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_network', 'No platform request from public snapshots.' ); } );
delete_option( BSC_TV_Player::STATE ); delete_transient( BSC_TV_Player::CACHE );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'live_priority' => true, 'repeat_hours' => 0, 'recent' => 'rotation' ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => true ) );
update_option( BSC_TV_Music::SETTINGS, array( 'enabled' => false ) );
$now = time();
$channel = live_post( BSC_TV_Content::CHANNEL, 'Beer live fixture', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/beerclockqa' ) ) );
foreach ( array( 'M7lc1UVf-VE', 'aqz-KE-bpKQ', 'QA123abcXYZ' ) as $id ) {
	$show = live_post( BSC_TV_Content::SHOW, 'Replay ' . $id, array( 'url' => 'https://youtu.be/' . $id, 'platform' => 'youtube', 'status' => 'ended', 'channel_id' => $channel, 'start' => $now - DAY_IN_SECONDS, 'end' => $now - DAY_IN_SECONDS + 3600 ) );
	update_post_meta( $show, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 3600, 'published_at' => $now - DAY_IN_SECONDS ) );
}
$event = array( 'id' => 'scheduled-tasting', 'url' => 'https://www.twitch.tv/beerclockqa', 'title' => 'Dégustation annoncée', 'status' => 'upcoming', 'start' => $now + 600, 'end' => $now + 1200 );
$source = array( 'key' => 'beerclockqa', 'schedule' => array( $event ), 'live' => array(), 'live_at' => $now );
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$before = BSC_TV_Player::snapshot( $now );
$initial = get_option( BSC_TV_Player::STATE );
live_check( 480 === $before['current']['end'] - $now && empty( $before['current']['standby'] ), 'Public replay boundary stops two minutes before the announcement' );
live_check( 3600 === $initial['vod']['end'] - $now, 'Shortened public playback does not shorten the stored base programme' );
$wait = BSC_TV_Player::snapshot( $now + 480 );
live_check( ! empty( $wait['current']['standby'] ) && 'identity' === $wait['current']['resource']['platform'], 'A channel announcement creates standby without a manual show' );
live_check( false === $wait['current']['live'] && 5 === $wait['poll_after'], 'Standby is explicitly unconfirmed and reads the local clock every five seconds' );
live_check( $initial['plan'] === get_option( BSC_TV_Player::STATE )['plan'], 'Waiting preserves the original future programmes exactly' );
$next_animation = BSC_TV_Player::snapshot( $now + 491 );
live_check( $next_animation['current']['identity']['entry_video'] !== $wait['current']['identity']['entry_video'], 'Waiting automatically changes the local BSC animation at its shared boundary' );
$day = BSC_TV_Player::day_snapshot( $now + 491 );
live_check( 1 === count( array_filter( $day['items'], static function ( $item ) { return $item['on_air'] && ! empty( $item['standby'] ); } ) ), 'The daily list identifies waiting as the current antenna' );
$event['status'] = 'live'; $event['id'] = 'actual-stream'; $event['start'] = $now + 650;
$source['live'] = array( $event ); $source['live_at'] = $now + 700;
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$on = BSC_TV_Player::snapshot( $now + 700 );
live_check( $on['current']['live'] && empty( $on['current']['standby'] ), 'A confirmed Twitch stream replaces standby immediately on the next clock read' );
live_check( 0 === $on['current']['end'] && 5 === $on['poll_after'], 'Actual live has no estimated end deadline in the player' );
$source['live_at'] = $now + 1500;
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( BSC_TV_Player::snapshot( $now + 1500 )['current']['live'], 'Estimated schedule end never stops a freshly confirmed live' );
$source['live_error'] = 'Temporary API outage';
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( BSC_TV_Player::snapshot( $now + 1800 )['current']['live'], 'A temporary API outage does not invent the end of the current live' );
live_check( $initial['plan'] === array_slice( get_option( BSC_TV_Player::STATE )['plan'], 0, count( $initial['plan'] ) ), 'A live overrun does not shift the original grid' );
$source['live'] = array(); $source['live_at'] = $now + 8100; $source['live_error'] = '';
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$resumed = BSC_TV_Player::snapshot( $now + 8100 );
$expected = array_values( array_filter( $initial['plan'], static function ( $row ) use ( $now ) { return $row['start'] <= $now + 8100 && $row['end'] > $now + 8100; } ) )[0];
live_check( empty( $resumed['current']['live'] ) && empty( $resumed['current']['standby'] ), 'A successful offline confirmation ends live without re-entering a stale announcement' );
live_check( $resumed['current']['key'] === $expected['key'] && $resumed['current']['start'] === $expected['start'] && 900 === $resumed['current']['offset'], 'The antenna rejoins fifteen minutes into the original programme' );
live_check( $resumed['next'][0]['start'] === $expected['end'], 'The next programme retains its original wall-clock time' );
live_check( empty( $resumed['next'][0]['after_live'] ), 'Future programmes are no longer presented as delayed until after live' );
// Old cache data may not start a new live after it has already finished.
$source['live'] = array( $event ); $source['live_at'] = $now + 1500;
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( ! BSC_TV_Player::snapshot( $now + 8101 )['current']['live'], 'An expired confirmation cannot promote a new live' );
// Cancelled, excluded and restricted announcements cannot interrupt normal playback.
delete_option( BSC_TV_Player::STATE );
$event['id'] = 'cancelled'; $event['status'] = 'cancelled'; $event['start'] = $now + 9000;
$source = array( 'key' => 'beerclockqa', 'schedule' => array( $event ) );
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( empty( BSC_TV_Player::snapshot( $now + 9000 )['current']['standby'] ), 'A cancelled announcement never reserves the antenna' );
$event['status'] = 'upcoming'; $event['embeddable'] = false; $source['schedule'] = array( $event );
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( empty( BSC_TV_Player::snapshot( $now + 9001 )['current']['standby'] ), 'A platform refusing embedding cannot reserve the antenna' );
$event['embeddable'] = true; $source['schedule'] = array( $event );
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
update_post_meta( $channel, BSC_TV_Player::CHANNEL, array( 'live' => false ) );
live_check( empty( BSC_TV_Player::snapshot( $now + 9002 )['current']['standby'] ), 'Channel live opt-out applies to standby as well' );
live_check( 0 === $requests, 'Every transition uses only stored local data, with no import or platform API request' );
echo "OK $checks WordPress live priority checks, zero external API calls.\n";
