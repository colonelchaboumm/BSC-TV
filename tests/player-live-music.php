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
// A fresh local fixture: no real uploads, rights approvals or external requests.
$config = BSC_TV_Music::normalize( array( 'enabled' => true, 'repeat_hours' => 0 ) );
update_option( BSC_TV_Music::SETTINGS, $config );
foreach ( array( 120, 180, 240 ) as $n => $duration ) {
 $id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Relay track ' . $n ) );
 update_post_meta( $id, BSC_TV_Music::META, array( 'artist' => 'Relay artist ' . $n, 'title' => 'Relay track ' . $n, 'youtube_id' => str_repeat( chr( 97 + $n ), 11 ), 'duration' => $duration, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Disposable fixture only.', 'availability' => 'available', 'region' => 'us' ) );
}
$pending = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Pending music' ) );
update_post_meta( $pending, BSC_TV_Music::META, array( 'artist' => 'Pending', 'title' => 'Pending', 'youtube_id' => 'pending0000', 'duration' => 120, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'active' => true, 'official' => true, 'rights' => 'pending', 'availability' => 'available' ) );
BSC_TV_Music::invalidate();
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'live_priority' => true, 'repeat_hours' => 0, 'recent' => 'rotation', 'live_wait' => 120 ) );
delete_option( BSC_TV_Player::STATE );
$before = BSC_TV_Player::snapshot( $now ); $initial = get_option( BSC_TV_Player::STATE );
$cutoff = $event['start'] + 1200; $until = $initial['plan'][0]['start'];
live_check( 20 === BSC_TV_Player::settings()['live_wait'], 'Legacy 120 minute settings become a 20 minute limit' );
$waiting = BSC_TV_Player::snapshot( $cutoff - 1 );
live_check( ! empty( $waiting['current']['standby'] ) && empty( $waiting['current']['music'] ), 'Waiting lasts 20 minutes after the advertised start, not after the pre-live lead' );
$relay = BSC_TV_Player::snapshot( $cutoff ); $saved = get_option( BSC_TV_Player::STATE );
$rows = $saved['standby']['relay_slots']; $row = $rows[0]; $relay_end = $saved['standby']['relay_until'];
live_check( ! empty( $relay['current']['music'] ) && ! empty( $relay['current']['waiting_music'] ) && ! $relay['current']['live'], 'One approved transition clip follows the missed live' );
live_check( 1 === count( $rows ) && $relay_end === $row['end'] && $relay_end <= $cutoff + 300, 'The relay has one clip and an absolute five minute budget' );
live_check( $initial['plan'] === $saved['plan'] && $initial['vod'] === $saved['vod'], 'The relay preserves the original clock' );
live_check( $row['end'] - $row['start'] === $row['music']['duration'] + $row['music']['entry'] + $row['music']['exit'] && $row['music']['clip_id'] !== $pending, 'The approved clip includes both complete animations' );
$count = count( $saved['aired'] ); BSC_TV_Player::snapshot( $cutoff );
live_check( count( get_option( BSC_TV_Player::STATE )['aired'] ) === $count, 'Repeated reads do not log the same clip twice' );
$clip_at = $cutoff + $row['music']['entry'] + 1; $clip = BSC_TV_Player::snapshot( $clip_at );
live_check( 'youtube' === $clip['current']['resource']['platform'] && 'clip' === $clip['current']['music_phase'] && 1 === $clip['current']['offset'], 'The official clip follows its complete entry animation' );
live_check( empty( $clip['next'][0]['waiting_music'] ), 'The public forecast does not advertise an endless musical relay' );
$day = BSC_TV_Player::day_snapshot( $clip_at ); $on_air = array_values( array_filter( $day['items'], static function ( $item ) { return $item['on_air']; } ) );
live_check( 1 === count( $on_air ) && ! empty( $on_air[0]['music'] ), 'The daily list still identifies the actual short musical transition' );
$back = BSC_TV_Player::snapshot( $relay_end );
live_check( empty( $back['current']['music'] ) && empty( $back['current']['waiting_music'] ) && ! $back['current']['live'], 'A beer programme immediately replaces the completed transition clip' );
live_check( empty( BSC_TV_Player::snapshot( $relay_end + 1 )['current']['waiting_music'] ), 'The same missed announcement cannot start another clip' );
update_option( BSC_TV_Player::STATE, $initial );
$late_at = $row['start'] + $row['music']['entry'] + 10;
$late = BSC_TV_Player::snapshot( $late_at );
live_check( $late['current']['key'] === $row['key'] && 10 === $late['current']['offset'], 'A late visitor joins the same one-clip transition at its shared position' );
$live = $event; $live['status'] = 'live'; $live['id'] = 'delayed-live'; $live['start'] = $late_at;
$source['live'] = array( $live ); $source['live_at'] = $late_at; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$on = BSC_TV_Player::snapshot( $late_at );
live_check( $on['current']['live'] && empty( $on['current']['music'] ), 'A late confirmed live preempts the transition' );
live_check( ! empty( $on['fallback'] ) && ! $on['fallback']['live'] && empty( $on['fallback']['music'] ), 'The public live includes an approved beer backup for player failures' );
$source['live_at'] = $until + 900; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( BSC_TV_Player::snapshot( $until + 900 )['current']['live'], 'A freshly confirmed overrun is never capped by the schedule estimate' );
$source['live'] = array(); $source['live_at'] = $until + 901; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$back = BSC_TV_Player::snapshot( $until + 901 );
live_check( ! $back['current']['live'] && empty( $back['current']['waiting_music'] ) && 901 === $back['current']['offset'], 'The original programme resumes at its original offset when live really ends' );
$source['live_at'] = $clip_at; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
update_option( BSC_TV_Player::STATE, $saved );
$revoked = $row['music']['clip_id']; $meta = get_post_meta( $revoked, BSC_TV_Music::META, true ); $meta['rights'] = 'pending';
update_post_meta( $revoked, BSC_TV_Music::META, $meta ); BSC_TV_Music::invalidate();
live_check( empty( BSC_TV_Player::snapshot( $clip_at )['current']['music'] ), 'Revocation releases the transition immediately to a programme' );
$disabled = $config; $disabled['enabled'] = false; update_option( BSC_TV_Music::SETTINGS, $disabled ); BSC_TV_Music::invalidate();
update_option( BSC_TV_Player::STATE, $saved );
live_check( empty( BSC_TV_Player::snapshot( $clip_at )['current']['waiting_music'] ), 'Disabling music releases its short transition' );
update_option( BSC_TV_Music::SETTINGS, $config ); BSC_TV_Music::invalidate();
$source['schedule'] = array(); update_post_meta( $channel, BSC_TV_Content::STATE, $source ); update_option( BSC_TV_Player::STATE, $saved );
live_check( empty( BSC_TV_Player::snapshot( $clip_at )['current']['waiting_music'] ), 'Cancellation releases the transition' );
$pool = BSC_TV_Music::pool(); $settings = BSC_TV_Player::settings(); $blocked = $settings;
foreach ( $blocked['music']['bands'] as &$band ) { $band['enabled'] = false; } unset( $band );
live_check( null === BSC_TV_Music::waiting( $pool, array(), $blocked, $cutoff, $until ), 'Disabled musical bands stay excluded' );
live_check( null === BSC_TV_Music::waiting( $pool, array(), $settings, $cutoff, $cutoff + 1 ), 'Short gaps never truncate a clip' );
$one = reset( $pool ); $last_artist = array( 'music_last_artist' => strtolower( $one['music']['artist'] ) );
live_check( null === BSC_TV_Music::waiting( array( $one['key'] => $one ), $last_artist, $settings, $cutoff, $until ), 'The last artist remains excluded' );
$cooldown = $settings; $cooldown['music']['repeat_hours'] = 6;
live_check( null === BSC_TV_Music::waiting( array( $one['key'] => $one ), array( 'music_rotation' => array( $one['key'] => $cutoff - 1 ) ), $cooldown, $cutoff, $until ), 'Clip repetition limits remain authoritative' );
live_check( array() === BSC_TV_Stations::run( 'fr', array( 'BSC_TV_Music', 'pool' ) ), 'World clips do not leak into the French antenna' );
live_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Recovery adds no API requests or import cron' );
echo "OK $checks bounded missed-live music checks.\n";
