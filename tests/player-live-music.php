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
live_check( ! empty( $relay['current']['music'] ) && ! empty( $relay['current']['waiting_music'] ) && ! $relay['current']['live'], 'Approved music takes over at exactly 20 minutes without fabricating live status' );
live_check( $initial['plan'] === $saved['plan'] && $initial['vod'] === $saved['vod'], 'The relay never consumes, delays or truncates the original plan' );
live_check( $until === $saved['standby']['relay_until'], 'The relay stops at the next original beer programme' );
$rows = $saved['standby']['relay_slots']; $last_artist = '';
foreach ( $rows as $row ) {
 live_check( $row['end'] <= $until && $row['end'] - $row['start'] === $row['music']['duration'] + $row['music']['entry'] + $row['music']['exit'], 'Every clip fits with its complete BSC animations' );
 live_check( $row['music']['clip_id'] !== $pending && $row['music']['artist'] !== $last_artist, 'Pending clips and consecutive identical artists are excluded' );
 $last_artist = $row['music']['artist'];
}
$count = count( $saved['aired'] ); BSC_TV_Player::snapshot( $cutoff );
live_check( count( get_option( BSC_TV_Player::STATE )['aired'] ) === $count, 'Repeated clock reads do not log the same relay clip twice' );
$clip_at = $cutoff + $rows[0]['music']['entry'] + 1;
$clip = BSC_TV_Player::snapshot( $clip_at );
live_check( 'youtube' === $clip['current']['resource']['platform'] && 'clip' === $clip['current']['music_phase'] && 1 === $clip['current']['offset'], 'The official YouTube clip begins after the full entry animation' );
live_check( ! empty( $clip['next'][0]['music'] ), 'The public forecast includes the next relay clips' );
$day = BSC_TV_Player::day_snapshot( $clip_at );
$on_air = array_values( array_filter( $day['items'], static function ( $item ) { return $item['on_air']; } ) );
live_check( 1 === count( $on_air ) && ! empty( $on_air[0]['music'] ), 'The daily widget identifies exactly the currently playing music' );
$days = BSC_TV_Player_Schedule::days( get_option( BSC_TV_Player::STATE ), $clip_at, wp_timezone_string() );
$shown = array_merge( ...array_column( $days, 'rows' ) );
live_check( count( array_filter( $shown, static function ( $row ) { return ! empty( $row['music'] ) && ! empty( $row['waiting_music'] ); } ) ) >= count( $rows ), 'Administration and daily lists expose the prepared relay music' );
$end = BSC_TV_Player::snapshot( $until );
live_check( empty( $end['current']['waiting_music'] ) && $end['current']['start'] === $until && 0 === $end['current']['offset'], 'The next programme starts on its original timestamp' );
// A late viewer joins the shared music clock, without marking skipped clips as broadcast.
update_option( BSC_TV_Player::STATE, $initial );
$late_at = $rows[1]['start'] + $rows[1]['music']['entry'] + 10;
$late = BSC_TV_Player::snapshot( $late_at ); $late_state = get_option( BSC_TV_Player::STATE );
live_check( $late['current']['key'] === $rows[1]['key'] && 10 === $late['current']['offset'], 'A first late visit joins the deterministic relay at its original offset' );
live_check( 1 === count( array_filter( $late_state['aired'], static function ( $row ) { return ! empty( $row['waiting_music'] ); } ) ), 'Skipped relay clips are not logged as actually broadcast' );
// Confirmed late live always preempts the relay and still may overrun.
$live = $event; $live['status'] = 'live'; $live['id'] = 'delayed-live'; $live['start'] = $late_at;
$source['live'] = array( $live ); $source['live_at'] = $late_at;
update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$on = BSC_TV_Player::snapshot( $late_at );
live_check( $on['current']['live'] && empty( $on['current']['music'] ), 'A late confirmed live immediately preempts music' );
$source['live_at'] = $until + 900; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
live_check( BSC_TV_Player::snapshot( $until + 900 )['current']['live'], 'The live remains on air after the relay deadline and estimated end' );
$source['live'] = array(); $source['live_at'] = $until + 901; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
$back = BSC_TV_Player::snapshot( $until + 901 );
live_check( ! $back['current']['live'] && empty( $back['current']['waiting_music'] ) && 901 === $back['current']['offset'], 'After actual live ends, the original programme resumes at its wall-clock offset' );
// Revocation is immediate; disabled or empty libraries resume the original grid.
$source['live_at'] = $clip_at; update_post_meta( $channel, BSC_TV_Content::STATE, $source );
update_option( BSC_TV_Player::STATE, $saved );
$revoked = $rows[0]['music']['clip_id']; $meta = get_post_meta( $revoked, BSC_TV_Music::META, true );
$meta['rights'] = 'pending'; update_post_meta( $revoked, BSC_TV_Music::META, $meta ); BSC_TV_Music::invalidate();
$revocation = BSC_TV_Player::snapshot( $clip_at );
live_check( ( $revocation['current']['key'] ?? '' ) !== $rows[0]['key'], 'Revocation removes a current relay clip immediately' );
$disabled = $config; $disabled['enabled'] = false; update_option( BSC_TV_Music::SETTINGS, $disabled ); BSC_TV_Music::invalidate();
update_option( BSC_TV_Player::STATE, $saved );
live_check( empty( BSC_TV_Player::snapshot( $clip_at )['current']['waiting_music'] ), 'A disabled music module resumes the original programme instead of holding forever' );
// A deliberately odd-sized window cannot be filled by the even-length fixture clips.
update_option( BSC_TV_Music::SETTINGS, $config ); BSC_TV_Music::invalidate();
$gap_state = $initial; $gap_state['plan'][0]['start'] = $cutoff + 1801;
update_option( BSC_TV_Player::STATE, $gap_state ); BSC_TV_Player::snapshot( $cutoff );
$gap_state = get_option( BSC_TV_Player::STATE ); $tail = end( $gap_state['standby']['relay_slots'] );
$gap = BSC_TV_Player::snapshot( $tail['end'] );
live_check( ! empty( $gap['current']['standby'] ) && ! empty( $gap['current']['waiting_music'] ) && $gap['current']['end'] <= $cutoff + 1801, 'An unfillable tail keeps bounded BSC animations with their local soundtrack' );
// The next announced live can interrupt this relay before the next replay.
update_option( BSC_TV_Player::STATE, $saved );
$second = $event; $second['id'] = 'second-live'; $second['url'] = 'https://www.twitch.tv/anotherbeer'; $second['start'] = $cutoff + 720;
$source['schedule'] = array( $event, $second ); update_post_meta( $channel, BSC_TV_Content::STATE, $source );
BSC_TV_Player::snapshot( $cutoff );
live_check( $cutoff + 600 === get_option( BSC_TV_Player::STATE )['standby']['relay_until'], 'The next pre-live waiting boundary also limits musical filling' );
$next_wait = BSC_TV_Player::snapshot( $cutoff + 600 );
live_check( ! empty( $next_wait['current']['standby'] ) && empty( $next_wait['current']['waiting_music'] ) && $next_wait['current']['expected_start'] === $second['start'], 'The next announced live takes over its own waiting slot' );
$source['schedule'] = array(); update_post_meta( $channel, BSC_TV_Content::STATE, $source );
update_option( BSC_TV_Player::STATE, $saved );
live_check( empty( BSC_TV_Player::snapshot( $clip_at )['current']['waiting_music'] ), 'Cancelling the original announcement releases its musical relay immediately' );
// Separate antenna library, approval and repeat/category restrictions remain authoritative.
update_option( BSC_TV_Music::SETTINGS, $config ); BSC_TV_Music::invalidate();
$pool = BSC_TV_Music::pool(); $settings = BSC_TV_Player::settings();
$blocked = $settings; foreach ( $blocked['music']['bands'] as &$band ) { $band['enabled'] = false; } unset( $band );
live_check( null === BSC_TV_Music::waiting( $pool, array(), $blocked, $cutoff, $until ), 'Disabled time bands cannot supply relay clips' );
live_check( null === BSC_TV_Music::waiting( $pool, array(), $settings, $cutoff, $cutoff + 1 ), 'A one second gap never truncates a clip' );
$one = reset( $pool ); $state = array( 'music_last_artist' => strtolower( $one['music']['artist'] ) );
live_check( null === BSC_TV_Music::waiting( array( $one['key'] => $one ), $state, $settings, $cutoff, $until ), 'The last artist is excluded even during a relay' );
$cooldown = $settings; $cooldown['music']['repeat_hours'] = 6;
live_check( null === BSC_TV_Music::waiting( array( $one['key'] => $one ), array( 'music_rotation' => array( $one['key'] => $cutoff - 1 ) ), $cooldown, $cutoff, $until ), 'Clip repetition limits also apply to a relay' );
live_check( array() === BSC_TV_Stations::run( 'fr', array( 'BSC_TV_Music', 'pool' ) ), 'World relay clips cannot leak into the French antenna' );
live_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Missed-live music adds no API request or import cron' );
echo "OK $checks missed-live music checks, zero external API calls.\n";
