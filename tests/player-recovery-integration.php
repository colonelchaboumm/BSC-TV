<?php
/** php tests/player-recovery-integration.php /path/to/disposable-wordpress-installation */
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
foreach ( array( BSC_TV_Content::CHANNEL, BSC_TV_Content::SHOW, BSC_TV_Music::POST ) as $type ) {
 foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => true, 'youtube_key' => 'private-recovery-fixture' ) );
$now = time(); $channels = array(); $initials = array();
foreach ( array( 'main' => 'en', 'fr' => 'fr' ) as $station => $language ) {
 $channel = live_post( BSC_TV_Content::CHANNEL, 'Source ' . $station, BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/recover' . $station, 'language' => $language ) ) );
 $channels[ $station ] = $channel;
 foreach ( array( 1, 2, 3 ) as $index ) {
  $video = ( 'fr' === $station ? 'frtest0000' : 'entest0000' ) . $index;
  $show = live_post( BSC_TV_Content::SHOW, 'Programme ' . $station . ' ' . $index, array( 'url' => 'https://youtu.be/' . $video, 'platform' => 'youtube', 'channel_id' => $channel, 'language' => $language, 'status' => 'ended', 'start' => $now - DAY_IN_SECONDS, 'end' => $now - DAY_IN_SECONDS + 3600 ) );
  update_post_meta( $show, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 3600, 'published_at' => $now - DAY_IN_SECONDS ) );
 }
 BSC_TV_Stations::run( $station, static function () use ( $station ) {
  update_option( BSC_TV_Stations::key( BSC_TV_Player::SETTINGS ), array( 'enabled' => true, 'live_priority' => true, 'repeat_hours' => 0, 'recent' => 'rotation' ) );
  $music = BSC_TV_Music::normalize( array( 'enabled' => true, 'flexible' => true, 'repeat_hours' => 0, 'year_min' => 0, 'year_max' => 0, 'region_filter' => 'all' ) );
  update_option( BSC_TV_Stations::key( BSC_TV_Music::SETTINGS ), $music );
  foreach ( array( 120, 180 ) as $index => $duration ) {
   $id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Clip ' . $station . $index ) );
   update_post_meta( $id, BSC_TV_Music::META, array( 'station' => $station, 'youtube_id' => str_repeat( ( 'fr' === $station ? 'f' : 'e' ), 10 ) . $index, 'duration' => $duration, 'artist' => 'Artist ' . $station . $index, 'title' => 'Track ' . $station . $index, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Disposable fixture only.', 'availability' => 'available' ) );
  }
  BSC_TV_Music::invalidate();
 } );
}
update_option( 'bsc_tv_station_sources_fr', array( 'ids' => array( $channels['fr'] ) ) );
BSC_TV_Stations::invalidate_catalogues();
foreach ( array_keys( $channels ) as $station ) {
 BSC_TV_Stations::run( $station, static function () use ( &$initials, $station, $channels, $now ) {
  $settings = BSC_TV_Player::settings(); $pool = BSC_TV_Player::media();
  $key = 'youtube:' . ( 'fr' === $station ? 'frtest00001' : 'entest00001' );
  $state = BSC_TV_Player::advance( array( 'rotation_seed' => 'station-' . $station ), array( $key => $pool[ $key ] ), array(), $settings, $now );
  $state = BSC_TV_Player_Schedule::replenish( $state, $pool, $settings, $now, true );
  update_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), $state, false ); $initials[ $station ] = $state;
 } );
}
foreach ( $channels as $station => $channel ) {
 BSC_TV_Stations::run( $station, static function () use ( $station, $channel, $initials, $now ) {
  $initial = $initials[ $station ];
  $source_key = 'recover' . $station;
  $live = array( 'id' => 'actual-' . $station, 'url' => 'https://www.twitch.tv/' . $source_key, 'title' => 'Direct ' . $station, 'status' => 'live', 'start' => $now - 600, 'end' => $now + 30 );
  $source = array( 'key' => $source_key, 'live' => array( $live ), 'schedule' => array(), 'live_at' => $now );
  update_post_meta( $channel, BSC_TV_Content::STATE, $source );
  $on = BSC_TV_Player::snapshot( $now ); $live_state = get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) );
  live_check( ! empty( $on['current']['live'] ) && $now === $on['current']['confirmed_at'], 'The station exposes its latest actual confirmation: ' . $station );
  live_check( ! empty( $on['fallback'] ) && empty( $on['fallback']['music'] ) && ! $on['fallback']['live'], 'The active live has an approved beer backup: ' . $station );
  if ( 'fr' === $station ) { live_check( 0 === strpos( $on['fallback']['title'], 'Programme fr ' ), 'The French backup stays inside its source selection' ); }
  $source['live_error'] = 'Temporary network failure'; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
  live_check( BSC_TV_Player::snapshot( $now + 2399 )['current']['live'], 'A temporary verification outage retains live within the grace window: ' . $station );
  $expired = BSC_TV_Player::snapshot( $now + 2400 );
  live_check( empty( $expired['current']['live'] ) && empty( $expired['current']['music'] ), 'Forty minutes without confirmation releases live to beer: ' . $station );
  $settings = BSC_TV_Player::settings();
  $pool = BSC_TV_Player::media();
  $beer = array_values( array_filter( $initial['plan'], static function ( $row ) use ( $now ) { return empty( $row['music'] ) && $row['start'] > $now + 12 * HOUR_IN_SECONDS; } ) )[0];
  $overrun_at = $beer['start'] + 100; $end_at = $beer['start'] + 900;
  update_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), $live_state );
  $source['live_error'] = ''; $source['live_at'] = $overrun_at; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
  $long = BSC_TV_Player::snapshot( $overrun_at );
  live_check( ! empty( $long['current']['live'] ), 'A freshly confirmed twelve-hour overrun continues despite its estimate: ' . $station );
  $kept = get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) )['plan'];
  live_check( $initial['plan'] === array_slice( $kept, 0, count( $initial['plan'] ) ), 'An actual overrun keeps every original programme timestamp: ' . $station );
  $source['live'] = array(); $source['live_at'] = $end_at; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
  $ended = BSC_TV_Player::snapshot( $end_at );
  live_check( $ended['current']['key'] === $beer['key'] && $ended['current']['start'] === $beer['start'] && 900 === $ended['current']['offset'], 'The real end rejoins the initial programme fifteen minutes in: ' . $station );
  // Persist the production-shaped empty gap and future musical rows.
  $pool = BSC_TV_Player::media();
  $music = array_values( array_filter( $pool, static function ( $item ) { return ! empty( $item['music'] ); } ) )[0];
  $anchor = $beer; $anchor['id'] = 'unaltered-' . $station; $anchor['start'] = $now + 10400; $anchor['end'] = $anchor['start'] + 3600; $anchor['fixed'] = true;
  $orphan = array( 'id' => 'future-music', 'key' => $music['key'], 'resource' => $music['resource'], 'show_id' => 0, 'channel_id' => 0, 'start' => $now + 2200, 'end' => $now + 2200 + $music['duration'], 'music' => $music['music'], 'fixed' => false );
  $gap = $initial; $gap['vod'] = null; $gap['live'] = null; $gap['standby'] = null; $gap['live_finished'] = array(); $gap['plan'] = array( $orphan, $anchor ); $gap['live_grid_until'] = $now + DAY_IN_SECONDS; $gap['plan_source'] = BSC_TV_Player_Schedule::signature( $pool, $settings );
  $source['live_at'] = $now; update_post_meta( $channel, BSC_TV_Content::STATE, $source ); update_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), $gap );
  $repaired = BSC_TV_Player::snapshot( $now );
  live_check( ! empty( $repaired['current']['recovery'] ) && empty( $repaired['current']['music'] ) && ! $repaired['current']['live'], 'A current beer programme repairs the empty gap: ' . $station );
  $prepared = get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) );
  $found = array_values( array_filter( $prepared['plan'], static function ( $row ) use ( $anchor ) { return $row['id'] === $anchor['id']; } ) );
  live_check( array( $anchor ) === $found, 'Automatic recovery preserves the original fixed anchor: ' . $station );
  $day = BSC_TV_Player::day_snapshot( $now );
  live_check( 1 === count( array_filter( $day['items'], static function ( $item ) { return $item['on_air'] && empty( $item['music'] ); } ) ), 'The day widget identifies the recovery programme as on air: ' . $station );
  $request = new WP_REST_Request( 'GET', '/bsc-tv/v1/player' ); $request->set_param( 'station', $station ); $payload = rest_do_request( $request )->get_data();
  live_check( $station === $payload['station'] && empty( $payload['current']['music'] ), 'The station-specific public endpoint uses the repaired clock: ' . $station );
  live_check( false === strpos( wp_json_encode( $payload ), 'private-recovery-fixture' ), 'The public recovery projection exposes no API credentials: ' . $station );
 } );
}
live_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Recovery does not import videos, call platform APIs or schedule another import' );
echo "OK $checks dual-station recovery integration checks.\n";
