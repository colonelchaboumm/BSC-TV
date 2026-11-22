<?php
/** php tests/music-integration.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-music-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0;
function music_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function music_config( $changes = array() ) { return BSC_TV_Music::normalize( $changes + array( 'enabled' => true, 'bundled_animation' => false ) ); }
function music_clip( $id, $artist, $region = 'us', $changes = array() ) {
	return $changes + array( 'artist' => $artist, 'title' => 'Clip test ' . $id, 'year' => 1995, 'youtube_id' => $id, 'url' => 'https://www.youtube.com/watch?v=' . $id, 'duration' => 240, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'region' => $region, 'image' => 'https://example.com/music.jpg', 'channel' => 'Official test source', 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Fixture only, no real music authorization.', 'availability' => 'available' );
}
foreach ( get_posts( array( 'post_type' => array( BSC_TV_Music::POST, BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ), 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
delete_option( BSC_TV_Music::SETTINGS ); delete_option( BSC_TV_Player::STATE ); delete_transient( BSC_TV_Music::CACHE );
$queries = 0;
add_filter( 'posts_pre_query', static function ( $pre, $query ) use ( &$queries ) { if ( BSC_TV_Music::POST === $query->get( 'post_type' ) ) { ++$queries; } return $pre; }, 10, 2 );
music_check( ! BSC_TV_Music::settings()['enabled'], 'Music is disabled on an existing or new installation' );
music_check( ! BSC_TV_Music::pool() && 0 === $queries, 'Disabled music performs no library query' );
music_check( 40 === BSC_TV_Music::settings()['threshold'] && ! BSC_TV_Music::settings()['flexible'], 'Default threshold and gap-only insertion are conservative' );
$config = music_config();
music_check( BSC_TV_Music::valid_bands( $config ), 'Default bands cover Paris without overlaps' );
foreach ( array( '05:59' => 'night', '06:00' => 'morning', '09:59' => 'morning', '10:00' => 'day', '17:59' => 'day', '18:00' => 'evening', '23:59' => 'evening', '00:00' => 'night' ) as $hour => $category ) {
	$time = new DateTimeImmutable( '2026-11-22 ' . $hour, new DateTimeZone( 'Europe/Paris' ) );
	music_check( $category === BSC_TV_Music::category( $time->getTimestamp(), $config ), 'Correct half-open Paris band at ' . $hour );
}
$night_off = $config; $night_off['bands']['night']['enabled'] = false;
music_check( '' === BSC_TV_Music::category( strtotime( '2026-11-22T02:00:00Z' ), $night_off ), 'Night can be disabled' );
$night_off['bands']['night']['enabled'] = true; $night_off['bands']['night']['category'] = 'morning';
music_check( 'morning' === BSC_TV_Music::category( strtotime( '2026-11-22T02:00:00Z' ), $night_off ), 'Night can reuse another category' );
$overlap = $config; $overlap['bands']['morning']['end'] = '11:00';
music_check( ! BSC_TV_Music::valid_bands( $overlap ), 'Overlapping active bands are rejected' );
foreach ( array( '2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z', '2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z' ) as $date ) {
	music_check( 'night' === BSC_TV_Music::category( strtotime( $date ), $config ), 'DST transition uses Paris rather than a fixed UTC offset: ' . $date );
}
$valid = music_clip( 'aaaaaaaaaaa', 'Artiste A' );
foreach ( array( array( 'rights' => 'pending' ), array( 'rights_note' => '' ), array( 'official' => false ), array( 'active' => false ), array( 'availability' => 'blocked' ), array( 'duration' => 0 ), array( 'duration' => -1 ), array( 'duration' => 4000 ) ) as $invalid ) { music_check( ! BSC_TV_Music::approved( $invalid + $valid ), 'Invalid clip cannot enter the player: ' . json_encode( $invalid ) ); }
$clip_ids = array();
foreach ( array( $valid, music_clip( 'bbbbbbbbbbb', 'Artiste B', 'fr' ), music_clip( 'ccccccccccc', 'Artiste C', 'us', array( 'rights' => 'pending' ) ) ) as $clip ) {
	$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => $clip['title'] ) ); update_post_meta( $id, BSC_TV_Music::META, $clip ); $clip_ids[] = $id;
}
update_option( BSC_TV_Music::SETTINGS, $config, false ); BSC_TV_Music::invalidate();
$music = BSC_TV_Music::pool();
music_check( 2 === count( $music ), 'Only approved available clips enter the music pool' );
$a = 'music:' . $clip_ids[0] . ':aaaaaaaaaaa'; $b = 'music:' . $clip_ids[1] . ':bbbbbbbbbbb';
$beer = array( 'key' => 'youtube:M7lc1UVf-VE', 'show_id' => 100, 'resource' => array( 'platform' => 'youtube', 'id' => 'M7lc1UVf-VE' ), 'channel_id' => 1, 'creator_key' => 'beer1', 'title' => 'Beer show', 'duration' => 2460, 'published_at' => 1, 'premiere' => false );
$other = array_replace( $beer, array( 'key' => 'youtube:aqz-KE-bpKQ', 'show_id' => 101, 'resource' => array( 'platform' => 'youtube', 'id' => 'aqz-KE-bpKQ' ), 'channel_id' => 2, 'creator_key' => 'beer2' ) );
$pool = array( $beer['key'] => $beer, $other['key'] => $other ) + $music;
$settings = array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'rotation', 'timezone' => 'Europe/Paris', 'music' => $config );
$t = strtotime( '2026-11-22T13:00:00Z' );
function music_state( $duration, $gap, $fixed = true ) {
	global $t, $beer, $other;
	$state = array(); $media = $beer; $media['duration'] = $duration; $media['available'] = $t - $duration;
	$state['vod'] = BSC_TV_Player::begin( $media, $state );
	$state['plan'] = array( array( 'id' => 'next-slot', 'key' => $other['key'], 'show_id' => $other['show_id'], 'resource' => $other['resource'], 'channel_id' => 2, 'premiere' => false, 'start' => $t + $gap, 'end' => $t + $gap + $other['duration'], 'fixed' => $fixed ) );
	return $state;
}
foreach ( array( 120, 900, 2100, 2399 ) as $duration ) { $next = BSC_TV_Player::select_next( $pool, music_state( $duration, 400 ), $settings, $t ); music_check( empty( $next['music'] ), 'No music after ' . $duration . ' seconds' ); }
foreach ( array( 2400, 2460, 5400 ) as $duration ) { $next = BSC_TV_Player::select_next( $pool, music_state( $duration, 400 ), $settings, $t ); music_check( ! empty( $next['music'] ), 'Long programme is eligible: ' . $duration ); }
$off = $settings; $off['music']['enabled'] = false;
music_check( empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 400 ), $off, $t )['music'] ), 'Disabled module adds no interlude' );
music_check( empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 245 ), $settings, $t )['music'] ), 'A gap one second too short does not truncate the clip' );
music_check( ! empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 246 ), $settings, $t )['music'] ), 'Exact entry plus full clip plus exit fits' );
music_check( empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 0 ), $settings, $t )['music'] ), 'Adjacent programmes remain adjacent in gap-only mode' );
music_check( empty( BSC_TV_Player::select_next( array_diff_key( $pool, $music ), music_state( 5400, 400 ), $settings, $t )['music'] ), 'No approved clip means the existing programme is kept' );
$flex = $settings; $flex['music']['flexible'] = true;
music_check( ! empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 0, false ), $flex, $t )['music'] ), 'Explicitly enabled automatic rotation can insert a break' );
music_check( empty( BSC_TV_Player::select_next( $pool, music_state( 5400, 0, true ), $flex, $t )['music'] ), 'Flexible mode cannot move a fixed programme' );
$with_fixed = music_state( 5400, 0, false ); $with_fixed['plan'][] = array_replace( $with_fixed['plan'][0], array( 'id' => 'later-fixed', 'start' => $t + 10000, 'end' => $t + 12000, 'fixed' => true ) );
music_check( ! empty( BSC_TV_Player::select_next( $pool, $with_fixed, $flex, $t )['music'] ), 'A later fixed programme permits a break when the automatic prefix has enough slack' );
$state = music_state( 5400, 400 ); $next = BSC_TV_Player::select_next( $pool, $state, $settings, $t ); $slot = BSC_TV_Player::begin( $next, $state ); $state['vod'] = $slot;
music_check( $state['plan'][0]['start'] === $t + 400 && 246 === $slot['end'] - $slot['start'], 'Gap insertion preserves the exact fixed start time' );
music_check( empty( BSC_TV_Player::select_next( $pool, $state, $settings, $slot['end'] )['music'] ), 'An interlude cannot be followed by another interlude' );
foreach ( array( 1 => array( 'entry', 1 ), 13 => array( 'clip', 10 ), 244 => array( 'exit', 1 ) ) as $elapsed => $expected ) {
	$public = BSC_TV_Music::decorate( $slot, $next, $t + $elapsed );
	music_check( $expected[0] === $public['music_phase'] && $expected[1] === $public['offset'], 'Late arrival joins correct persisted phase: ' . $expected[0] );
	music_check( ! $public['live'] && '' === $public['profile_url'] && '' === $public['avatar'], 'Music is not a real live or an invented BDD association' );
}
$first = BSC_TV_Music::decorate( $slot, $next, $t + 30 ); $second = BSC_TV_Music::decorate( $slot, $next, $t + 30 );
music_check( $first === $second, 'Two visitors receive the same music resource and offset' );
music_check( false === strpos( wp_json_encode( $first ), 'Fixture only' ), 'Private authorization note is absent from public metadata' );
BSC_TV_Music::tally( $state, $slot, $t + 1 ); music_check( empty( $state['music_stats'] ), 'Entry animation does not count a clip as played' );
BSC_TV_Music::tally( $state, $slot, $t + 4 ); BSC_TV_Music::tally( $state, $slot, $t + 40 );
music_check( 1 === $state['music_stats'][ $slot['music']['clip_id'] ]['plays'], 'Repeated requests count an actual clip only once' );
$selection = music_state( 5400, 400 ); $selection['music_last_artist'] = strtolower( $music[ $a ]['music']['artist'] ); $selection['music_last_region'] = 'us';
music_check( $b === BSC_TV_Player::select_next( $pool, $selection, $settings, $t )['key'], 'Different artist and French hip-hop follow an American clip' );
$selection['music_last_artist'] = strtolower( $music[ $b ]['music']['artist'] ); $selection['music_last_region'] = 'fr';
music_check( $a === BSC_TV_Player::select_next( $pool, $selection, $settings, $t )['key'], 'Hip-hop can alternate back to an American artist' );
$selection['music_rotation'] = array( $a => $t - 10 );
music_check( empty( BSC_TV_Player::select_next( $pool, $selection, $settings, $t )['music'] ), 'Clip cooldown and consecutive artist exclusion are both applied' );
$blocked = $pool; foreach ( $music as $key => $row ) { $blocked[ $key ]['music_deadlines'] = array( $t + 200 ); }
music_check( empty( BSC_TV_Player::select_next( $blocked, music_state( 5400, 0, false ), $flex, $t )['music'] ), 'A known forthcoming live cannot be straddled by a new interlude' );
$finish = BSC_TV_Music::reconcile( $state, $pool, $off, $t + 20 );
music_check( ! empty( $finish['vod']['music'] ), 'Disable defaults to letting the current approved break finish' );
$off['music']['disable_policy'] = 'interrupt'; $interrupted = BSC_TV_Music::reconcile( $state, $pool, $off, $t + 20 );
music_check( null === $interrupted['vod'] && $interrupted['plan'][0]['start'] === $t + 400, 'Immediate disable removes music without moving a fixed programme' );
$automatic = $state; $automatic['plan'][0]['fixed'] = false; $automatic['plan'][0]['start'] = $slot['end'];
$interrupted = BSC_TV_Music::reconcile( $automatic, $pool, $off, $t + 20 );
music_check( $interrupted['plan'][0]['start'] === $t + 20, 'Interrupting a flexible break immediately releases the next automatic programme' );
$revoked = $pool; unset( $revoked[ $slot['key'] ] );
music_check( null === BSC_TV_Music::reconcile( $state, $revoked, $settings, $t + 20 )['vod'], 'Revoking a clip interrupts it even with finish-on-disable configured' );
$live = array( 'live:twitch:test' => array( 'key' => 'live:twitch:test', 'resource' => array( 'platform' => 'twitch', 'id' => 'beerstream' ), 'show_id' => 0, 'channel_id' => 1, 'priority' => 100, 'start' => $t, 'end' => 0 ) );
$priority = BSC_TV_Player::advance( $state, $pool, $live, $settings, $t + 20 );
music_check( ! empty( $priority['live'] ) && empty( $priority['vod']), 'A priority live interrupts music by default without resuming it later' );
$finish_live = $settings; $finish_live['music']['live_policy'] = 'finish';
$priority = BSC_TV_Player::advance( $state, $pool, $live, $finish_live, $t + 20 );
music_check( empty( $priority['live'] ) && ! empty( $priority['vod']['music'] ), 'The explicit finish-before-live policy is honored' );
$priority = BSC_TV_Player::advance( music_state( 5400, 400 ), $pool, $live, $settings, $t );
music_check( ! empty( $priority['live'] ) && empty( $priority['vod']['music'] ), 'A confirmed live prevents starting a new interlude' );
$forecast = BSC_TV_Player_Schedule::build( music_state( 5400, 0, false ), $pool, $flex, $t );
music_check( ! empty( $forecast[0]['music'] ) && $forecast[1]['start'] === $forecast[0]['end'], 'Existing scheduler persists break and next programme together' );
$stable = music_state( 5400, 400 ); $disabled_forecast = BSC_TV_Player_Schedule::build( $stable, $pool, $off, $t );
music_check( $disabled_forecast[0]['start'] === $t + 400 && empty( $disabled_forecast[0]['music'] ), 'Disabled module preserves the existing fixed grid' );
$beer_only = array_diff_key( $pool, $music ); $legacy_selection = array();
foreach ( $beer_only as $key => $media ) { $legacy_selection[ $key ] = array( $media['show_id'], $media['duration'], $media['published_at'], $media['channel_id'], $media['creator_key'], ! empty( $media['premiere'] ) ); } ksort( $legacy_selection );
$legacy_signature = hash( 'sha256', json_encode( array( 'mixed-v1', $legacy_selection, $off['recent'], $off['repeat_hours'], $off['timezone'] ) ) );
music_check( $legacy_signature === BSC_TV_Player_Schedule::signature( $beer_only, $off ), 'Disabled music preserves the previous plugin queue signature byte-for-byte' );
$invalid_url = BSC_TV_Music_Admin::sanitize_clip( array( 'url' => 'https://www.twitch.tv/example' ) ); music_check( is_wp_error( $invalid_url ), 'Manual library rejects non-YouTube resources' );
$unconfirmed = BSC_TV_Music_Admin::sanitize_clip( array( 'url' => $valid['url'], 'rights' => 'confirmed', 'rights_note' => 'test' ) ); music_check( 'pending' === $unconfirmed['rights'], 'A newly supplied URL cannot inherit approval without explicit confirmation' );
$changed = BSC_TV_Music_Admin::sanitize_clip( array( 'url' => 'https://youtu.be/bbbbbbbbbbb', 'rights' => 'confirmed', 'rights_note' => 'test' ), $valid ); music_check( 'pending' === $changed['rights'], 'Changing a URL resets approval unless explicitly revalidated' );
$old_blocked = $valid; $old_blocked['availability'] = 'blocked'; $changed = BSC_TV_Music_Admin::sanitize_clip( array( 'url' => $valid['url'], 'manual_available' => 1 ), $old_blocked ); music_check( 'blocked' === $changed['availability'], 'Manual availability cannot override an authoritative blocked video' );
$http_calls = 0; $api_mode = 'good';
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$http_calls, &$api_mode ) {
	++$http_calls;
	if ( 'error' === $api_mode ) { return new WP_Error( 'test_failure', 'API unavailable in fixture' ); }
	$details = array( 'duration' => 'PT4M' );
	if ( 'age' === $api_mode ) { $details['contentRating'] = array( 'ytRating' => 'ytAgeRestricted' ); }
	if ( 'region' === $api_mode ) { $details['regionRestriction'] = array( 'blocked' => array( 'FR' ) ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'items' => 'blocked' === $api_mode ? array() : array( array( 'id' => 'aaaaaaaaaaa', 'snippet' => array( 'title' => 'Official title', 'channelTitle' => 'Official channel', 'channelId' => 'UCfixture', 'liveBroadcastContent' => 'none', 'publishedAt' => '1995-01-01T00:00:00Z' ), 'status' => array( 'embeddable' => true, 'privacyStatus' => 'public' ), 'contentDetails' => $details ) ) ) ) );
}, 10, 3 );
$sync = BSC_TV_Sync::settings(); $sync['youtube_key'] = 'fixture-only-not-a-real-key'; update_option( BSC_TV_Sync::SETTINGS, $sync );
BSC_TV_Music::refresh( array( $clip_ids[0] => $valid ) ); $fresh = get_post_meta( $clip_ids[0], BSC_TV_Music::META, true );
music_check( 'available' === $fresh['availability'] && 240 === $fresh['duration'] && 'Official channel' === $fresh['channel'], 'Explicit metadata request reuses existing YouTube credentials and parsed duration' );
music_check( 'confirmed' === $fresh['rights'] && $valid['rights_note'] === $fresh['rights_note'], 'Metadata never grants or changes rights approval' );
foreach ( array( 'age', 'region' ) as $mode ) {
	$api_mode = $mode; BSC_TV_Music::refresh( array( $clip_ids[0] => $fresh ) );
	music_check( 'blocked' === get_post_meta( $clip_ids[0], BSC_TV_Music::META, true )['availability'], 'Known restriction excludes music: ' . $mode );
}
$api_mode = 'blocked'; BSC_TV_Music::refresh( array( $clip_ids[0] => $fresh ) ); $fresh = get_post_meta( $clip_ids[0], BSC_TV_Music::META, true );
music_check( 'blocked' === $fresh['availability'], 'A missing/private/deleted API video is excluded' );
$api_mode = 'error'; BSC_TV_Music::refresh( array( $clip_ids[0] => $fresh ) );
music_check( 'blocked' === get_post_meta( $clip_ids[0], BSC_TV_Music::META, true )['availability'], 'A failed metadata request never lifts a known restriction' );
$before = $http_calls; BSC_TV_Music::settings(); BSC_TV_Music::pool();
music_check( $http_calls === $before, 'Reading settings and cached library performs no API requests' );
update_option( BSC_TV_Music::SETTINGS, array(), false ); do_action( 'bsc_tv_player_daily_music' );
music_check( $http_calls === $before, 'Disabled module adds no work to daily Infomaniak import' );
music_check( ! wp_next_scheduled( 'bsc_tv_music_daily' ), 'The module adds no music-specific cron task' );
$config['flexible'] = true; update_option( BSC_TV_Music::SETTINGS, $config, false ); update_post_meta( $clip_ids[0], BSC_TV_Music::META, $valid ); BSC_TV_Music::invalidate();
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'rotation', 'live_priority' => true ) );
$now = time();
$channel_id = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_status' => 'publish', 'post_title' => 'Existing beer channel', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/musicbeerfixture' ) ) ) ) );
$show_id = wp_insert_post( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => 'publish', 'post_title' => 'Existing long beer show', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::show_data( array( 'url' => 'https://youtu.be/M7lc1UVf-VE', 'starts_at' => gmdate( DATE_ATOM, $now - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ), 'status' => 'ended', 'channel_id' => $channel_id ) ), BSC_TV_Player::MEDIA => array( 'mode' => 'yes', 'duration' => 2460, 'published_at' => $now - DAY_IN_SECONDS ) ) ) );
delete_transient( BSC_TV_Player::CACHE );
$real_pool = BSC_TV_Player::media(); $real_beer = $real_pool['youtube:M7lc1UVf-VE'];
$clock = array(); $clock['vod'] = BSC_TV_Player::begin( $real_beer + array( 'available' => $now - 2460 ), $clock );
$clock['plan'] = array( array( 'id' => 'fixed-real', 'key' => $real_beer['key'], 'show_id' => $show_id, 'channel_id' => $channel_id, 'resource' => $real_beer['resource'], 'start' => $now + 500, 'end' => $now + 2960, 'fixed' => true, 'premiere' => false ) );
update_option( BSC_TV_Player::STATE, $clock, false );
$before = $http_calls;
$entry = BSC_TV_Player::snapshot( $now + 1 );
music_check( ! empty( $entry['current']['music'] ) && 'entry' === $entry['current']['music_phase'], 'Real public snapshot starts the scheduled entry screen' );
$clip = BSC_TV_Player::snapshot( $now + 10 ); $visitor = BSC_TV_Player::snapshot( $now + 10 );
$playing_clock = (array) get_option( BSC_TV_Player::STATE, array() );
music_check( $clip === $visitor && 'clip' === $clip['current']['music_phase'] && 7 === $clip['current']['offset'], 'Public visitors use one persisted clip and matching late-join offset' );
music_check( ! $clip['current']['live'] && '' === $clip['current']['profile_url'] && ! isset( $clip['current']['rights_note'] ), 'Public clip is distinct from a real live and exposes no rights note or invented profile' );
$daily = BSC_TV_Player::day_snapshot( $now + 10 );
music_check( count( array_filter( $daily['items'], static function ( $item ) { return ! empty( $item['music'] ) && $item['on_air']; } ) ) === 1, 'The existing daily widget recognizes the common interlude as on air' );
$priority_settings = BSC_TV_Player::settings(); $priority_settings['live_priority'] = false; update_option( BSC_TV_Player::SETTINGS, $priority_settings, false );
$sync['live_enabled'] = true; update_option( BSC_TV_Sync::SETTINGS, $sync, false );
update_post_meta( $channel_id, BSC_TV_Content::STATE, array( 'key' => BSC_TV_Content::channel_key( 'twitch', 'https://www.twitch.tv/musicbeerfixture' ), 'live_at' => $now + 20, 'live' => array( array( 'id' => 'live-actual-music-fixture', 'url' => 'https://www.twitch.tv/musicbeerfixture', 'title' => 'Real priority live', 'start' => $now, 'end' => 0 ) ) ) );
$real_live = BSC_TV_Player::snapshot( $now + 20 );
music_check( ! empty( $real_live['current']['live'] ) && empty( $real_live['current']['music'] ), 'Music still yields to a confirmed live when optional replay live-priority was disabled' );
$same_live = BSC_TV_Player::snapshot( $now + 21 );
music_check( $same_live['current']['key'] === $real_live['current']['key'] && $same_live['current']['live'], 'An established true live cannot be interrupted by a music insertion' );
update_post_meta( $channel_id, BSC_TV_Content::STATE, array() ); update_option( BSC_TV_Player::STATE, $playing_clock, false );
$exit = BSC_TV_Player::snapshot( $now + 244 );
music_check( 'exit' === $exit['current']['music_phase'] && 1 === $exit['current']['offset'], 'Real public snapshot follows the exit screen at its shared time' );
$after = BSC_TV_Player::snapshot( $now + 247 );
music_check( null === $after['current'] && $after['next'][0]['start'] === $now + 500, 'Finishing the music does not advance the fixed next beer programme' );
$next_programme = BSC_TV_Player::snapshot( $now + 500 );
music_check( $show_id === $next_programme['current']['show_id'] && empty( $next_programme['current']['music'] ), 'The next beer show automatically starts at its original fixed time' );
music_check( $http_calls === $before, 'Public interlude snapshots and daily widget perform zero metadata API calls' );
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { $users = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $users[0]->ID ); }
update_option( BSC_TV_Player::STATE, $playing_clock, false );
$skip = BSC_TV_Player_Schedule::queue( 'skip', 0, $playing_clock['vod']['key'] . ':' . $playing_clock['vod']['start'], $now + 20 );
$skipped_clock = get_option( BSC_TV_Player::STATE );
music_check( true === $skip && $skipped_clock['plan'][0]['start'] === $now + 500, 'Skipping music from Le live preserves the next fixed start' );
music_check( is_wp_error( BSC_TV_Player_Schedule::queue( 'next', 0 ) ), 'A music reference cannot bypass eligibility through the manual replay queue' );
ob_start(); BSC_TV_Music_Admin::page(); $admin = ob_get_clean();
music_check( false !== strpos( $admin, 'Music Interludes' ) && false !== strpos( $admin, 'value="stop"' ) && false !== strpos( $admin, 'clip[rights_note]' ) && false !== strpos( $admin, 'clip[categories][]" value="day"' ), 'One existing BSC TV admin section exposes stop, private approvals and working category values' );
$GLOBALS['music_test_die'] = false;
add_filter( 'wp_die_handler', static function () { return static function () { throw new RuntimeException( 'music_wp_die' ); }; } );
$admin_user = get_current_user_id(); wp_set_current_user( 0 ); $_REQUEST = array();
try { BSC_TV_Music_Admin::settings(); music_check( false, 'Anonymous settings request must fail' ); } catch ( RuntimeException $error ) { music_check( 'music_wp_die' === $error->getMessage(), 'Music settings reject unauthorized users' ); }
wp_set_current_user( $admin_user );
try { BSC_TV_Music_Admin::clip(); music_check( false, 'Missing clip nonce must fail' ); } catch ( RuntimeException $error ) { music_check( 'music_wp_die' === $error->getMessage(), 'Clip mutations require a valid WordPress nonce' ); }
add_filter( 'wp_redirect', static function () { throw new RuntimeException( 'music_redirect' ); } );
function music_submit( $method, $action, $fields ) {
	$_POST = $fields; $_POST['_wpnonce'] = wp_create_nonce( $action ); $_REQUEST = $_POST;
	try { call_user_func( array( 'BSC_TV_Music_Admin', $method ) ); } catch ( RuntimeException $error ) { if ( 'music_redirect' !== $error->getMessage() ) { throw $error; } }
}
$save_input = array( 'url' => 'https://youtu.be/ddddddddddd', 'artist' => 'Handler fixture artist', 'title' => 'Handler clip', 'duration' => 200, 'categories' => array( 'day', 'night' ), 'active' => 1, 'official' => 1, 'manual_available' => 1, 'rights' => 'confirmed', 'rights_note' => 'Explicit fixture validation, not real rights.', 'confirm' => 1 );
music_submit( 'clip', 'bsc_tv_music_clip', array( 'operation' => 'save', 'clip' => $save_input ) );
$created = array_filter( BSC_TV_Music::clips(), static function ( $item ) { return 'ddddddddddd' === $item['youtube_id']; } ); $created = reset( $created );
music_check( $created && BSC_TV_Music::approved( $created ) && array( 'day', 'night' ) === $created['categories'], 'Valid admin POST stores manual clip approval and multiple category identifiers' );
music_submit( 'clip', 'bsc_tv_music_clip', array( 'operation' => 'deactivate', 'clip_id' => $created['id'] ) );
music_check( ! get_post_meta( $created['id'], BSC_TV_Music::META, true )['active'], 'Admin deactivation leaves the record but excludes playback' );
music_submit( 'clip', 'bsc_tv_music_clip', array( 'operation' => 'activate', 'clip_id' => $created['id'] ) );
music_check( get_post_meta( $created['id'], BSC_TV_Music::META, true )['active'], 'Admin activation restores an already validated clip' );
music_submit( 'clip', 'bsc_tv_music_clip', array( 'operation' => 'delete', 'clip_id' => $created['id'] ) );
music_check( 'trash' === get_post_status( $created['id'] ), 'Admin deletion uses the WordPress trash' );
$real_beer = BSC_TV_Player::media()['youtube:M7lc1UVf-VE']; $clock = array(); $clock['vod'] = BSC_TV_Player::begin( $real_beer + array( 'available' => time() - 2460 ), $clock );
$clock['plan'] = array( array( 'id' => 'new-auto', 'key' => $real_beer['key'], 'resource' => $real_beer['resource'], 'start' => time(), 'end' => time() + 2460, 'fixed' => false, 'premiere' => false, 'show_id' => $show_id, 'channel_id' => $channel_id ) ); update_option( BSC_TV_Player::STATE, $clock, false );
BSC_TV_Player::snapshot();
music_submit( 'settings', 'bsc_tv_music_settings', array( 'operation' => 'stop' ) );
$stopped = (array) get_option( BSC_TV_Player::STATE, array() );
music_check( ! BSC_TV_Music::settings()['enabled'] && ! count( array_filter( $stopped['plan'], static function ( $row ) { return ! empty( $row['music'] ); } ) ), 'The admin stop button atomically removes all future interludes' );
music_check( ! empty( $stopped['vod']['music'] ), 'The admin stop button honors the configured finish-current policy' );
$config['enabled'] = false; $config['disable_policy'] = 'interrupt'; music_submit( 'settings', 'bsc_tv_music_settings', array( 'music' => $config ) );
music_check( empty( get_option( BSC_TV_Player::STATE )['vod']['music'] ), 'A valid admin settings POST can interrupt the current interlude cleanly' );
$config['enabled'] = true; update_option( BSC_TV_Music::SETTINGS, $config, false );
$api_mode = 'good'; delete_option( BSC_TV_Player_Daily::REPORT );
$first_daily = BSC_TV_Player_Daily::execute( 1, 30 ); $daily_calls = $http_calls; $second_daily = BSC_TV_Player_Daily::execute( 1, 30 );
music_check( ! is_wp_error( $first_daily ) && 'already_run' === $second_daily['status'] && $daily_calls === $http_calls, 'The existing daily claim also prevents a second music metadata batch on the same day' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-player' ); $controls = $widget->get_stack( false ); $controls = $controls['controls'] + ( $controls['style_controls'] ?? array() );
music_check( isset( $controls['show_music'], $controls['show_music_meta'], $controls['music_background'], $controls['music_logo_size'], $controls['music_badge'] ), 'Music styles and toggles extend the existing Elementor player' );
$html = BSC_TV_Player::render(); music_check( false !== strpos( $html, 'data-music-screen hidden' ) && false !== strpos( $html, 'data-player-badge="music" hidden' ), 'Existing widget contains a lazy distinct identity screen and MUSIC BREAK badge' );
$qa = dirname( __DIR__, 2 ) . '/tv-music-qa'; if ( ! is_dir( $qa ) ) { mkdir( $qa ); }
file_put_contents( $qa . '/index.html', '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bsc-tv/assets/player.css"><style>body{margin:24px auto;max-width:1000px;background:#0b0b0e;padding:12px}</style><script defer src="/bsc-tv/assets/player.js"></script>' . $html );
file_put_contents( $qa . '/phases.json', wp_json_encode( array( 'entry' => $entry, 'clip' => $clip, 'exit' => $exit, 'programme' => $next_programme ) ) );
file_put_contents( $qa . '/admin.html', $admin );
echo 'OK ' . $checks . " music interlude WordPress and scheduler checks.\n";
