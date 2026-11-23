<?php
/** php tests/music-grid.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-music-grid-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite'; copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ); define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/template.php'; require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0; function grid_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
foreach ( get_posts( array( 'post_type' => array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL, BSC_TV_Music::POST ), 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
delete_option( BSC_TV_Player::STATE ); delete_transient( BSC_TV_Music::CACHE ); delete_transient( BSC_TV_Player::CACHE );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'rotation', 'live_priority' => true ) );
$config = BSC_TV_Music::normalize( array( 'enabled' => true, 'flexible' => true, 'repeat_hours' => 0 ) ); update_option( BSC_TV_Music::SETTINGS, $config, false );
$clip_ids = array();
foreach ( array( 181, 240, 301, 359 ) as $n => $duration ) {
	$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Track ' . $n ) ); $clip_ids[] = $id;
	update_post_meta( $id, BSC_TV_Music::META, array( 'artist' => 'Artist ' . $n, 'title' => 'Track ' . $n, 'youtube_id' => str_repeat( chr( 97 + $n ), 11 ), 'url' => 'https://www.youtube.com/watch?v=' . str_repeat( chr( 97 + $n ), 11 ), 'duration' => $duration, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'region' => 'other', 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Disposable fixture only, not real rights.', 'availability' => 'available', 'image' => 'https://example.com/music-' . $n . '.jpg' ) );
}
$pending = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => 'Imported, pending clip' ) );
update_post_meta( $pending, BSC_TV_Music::META, array( 'artist' => '', 'title' => 'Pending', 'youtube_id' => 'pending0000', 'duration' => 0, 'categories' => array( 'morning' ), 'active' => false, 'official' => false, 'rights' => 'pending', 'rights_note' => '', 'availability' => 'unchecked' ) );
BSC_TV_Music::invalidate(); $music = BSC_TV_Music::pool(); $first = reset( $music ); $duration = $first['duration'];
$beer = array( 'key' => 'youtube:M7lc1UVf-VE', 'show_id' => 100, 'channel_id' => 1, 'creator_key' => 'beer-a', 'title' => 'Beer A', 'resource' => array( 'platform' => 'youtube', 'id' => 'M7lc1UVf-VE' ), 'duration' => 3000, 'published_at' => 1, 'premiere' => false );
$other = array_replace( $beer, array( 'key' => 'youtube:aqz-KE-bpKQ', 'show_id' => 101, 'channel_id' => 2, 'creator_key' => 'beer-b', 'title' => 'Beer B', 'resource' => array( 'platform' => 'youtube', 'id' => 'aqz-KE-bpKQ' ) ) );
$pool = array( $beer['key'] => $beer, $other['key'] => $other ) + $music;
$settings = array( 'enabled' => true, 'repeat_hours' => 0, 'recent' => 'rotation', 'timezone' => 'Europe/Paris', 'music' => $config ); $t = strtotime( '2026-11-22T13:00:00Z' );
function grid_state( $anchor = null ) {
	global $beer, $other, $t;
	$state = array( 'music_seed' => 'shared-seed', 'rotation_seed' => 'tv-seed' );
	$state['vod'] = BSC_TV_Player::begin( $beer + array( 'available' => $t - $beer['duration'] ), $state );
	$state['plan'] = array( array( 'id' => 'automatic-next', 'key' => $other['key'], 'resource' => $other['resource'], 'show_id' => $other['show_id'], 'channel_id' => 2, 'start' => $t, 'end' => $t + 3000, 'premiere' => false, 'fixed' => false ) );
	if ( null !== $anchor ) { $state['plan'][] = array( 'id' => 'fixed-anchor', 'key' => $beer['key'], 'resource' => $beer['resource'], 'show_id' => $beer['show_id'], 'channel_id' => 1, 'start' => $anchor, 'end' => $anchor + 3000, 'premiere' => false, 'fixed' => true ); }
	return $state;
}
$state = grid_state(); $one = BSC_TV_Player::select_next( $pool, $state, $settings, $t ); $two = BSC_TV_Player::select_next( $pool, $state, $settings, $t );
grid_check( $one['key'] === $two['key'] && ! empty( $one['music'] ), 'The same shared seed and cursor choose the same music for every visitor' );
$choices = array(); for ( $n = 0; $n < 30; ++$n ) { $copy = $state; $copy['music_seed'] = 'seed-' . $n; $choice = BSC_TV_Player::select_next( $pool, $copy, $settings, $t ); $choices[ $choice['key'] ] = true; }
grid_check( count( $choices ) >= 3, 'Different saved seeds produce varied choices rather than always the smallest clip ID' );
$copy = $state; $copy['music_last_artist'] = strtolower( $one['music']['artist'] );
grid_check( BSC_TV_Player::select_next( $pool, $copy, $settings, $t )['key'] !== $one['key'], 'Randomization still excludes the last artist' );
$copy = $state; $settings_repeat = $settings; $settings_repeat['music']['repeat_hours'] = 6; $copy['music_rotation'][ $one['key'] ] = $t - 1;
grid_check( BSC_TV_Player::select_next( $pool, $copy, $settings_repeat, $t )['key'] !== $one['key'], 'Randomization still respects the clip cooldown' );
grid_check( $one['music']['category'] === 'day' && $one['music']['category_label'] === 'BSC Hip-Hop Classics', 'Chosen musical category is saved at the interlude start time' );
grid_check( str_ends_with( $one['music']['identity']['entry_video'], '.mp4' ) && $one['music']['identity']['entry_video'] !== $one['music']['identity']['exit_video'] && 11 === $one['music']['entry'] && 11 === $one['music']['exit'], 'Both phases choose distinct supplied BSC animations and reserve their complete lengths' );
$identity_only = $config; $identity_only['bundled_animation'] = false; update_option( BSC_TV_Music::SETTINGS, $identity_only, false ); $static_pool = BSC_TV_Music::pool(); $static = reset( $static_pool );
grid_check( '' === $static['music']['identity']['entry_video'] && '' === $static['music']['identity']['exit_video'] && '' === $static['music']['identity']['logo'], 'The bundled video can be disabled for a neutral identity or configured logo' ); update_option( BSC_TV_Music::SETTINGS, $config, false );
$single = array( $beer['key'] => $beer, $other['key'] => $other, $first['key'] => $first );
$tight = grid_state( $t + 3000 + $duration - 1 );
grid_check( empty( BSC_TV_Player::select_next( $single, $tight, $settings, $t )['music'] ), 'A fixed anchor one second too close blocks the interlude without trimming it' );
$exact = grid_state( $t + 3000 + $duration ); $anchor = $exact['plan'][1];
$choice = BSC_TV_Player::select_next( $single, $exact, $settings, $t ); $slot = BSC_TV_Player::begin( $choice, $exact );
grid_check( ! empty( $slot['music'] ) && $slot['end'] - $slot['start'] === $duration, 'Exact slack includes the whole clip and both complete animations' );
grid_check( $exact['plan'][0]['start'] === $slot['end'] && $exact['plan'][0]['end'] === $anchor['start'], 'Only the automatic prefix is delayed by the complete interlude duration' );
grid_check( $exact['plan'][1] === $anchor, 'The fixed programme keeps its exact timestamps and metadata' );
$repeat_settings = $settings; $repeat_settings['repeat_hours'] = 6;
$repeat_state = grid_state( $t + 3000 + 6 * HOUR_IN_SECONDS );
$repeat_state['plan'][1] = array_replace( $repeat_state['plan'][1], array( 'key' => $other['key'], 'resource' => $other['resource'], 'show_id' => $other['show_id'] ) );
grid_check( empty( BSC_TV_Player::select_next( $single, $repeat_state, $repeat_settings, $t )['music'] ), 'Music cannot consume the repeat-cooldown margin of a fixed replay' );
$repeat_state['plan'][1]['start'] += $duration; $repeat_state['plan'][1]['end'] += $duration;
grid_check( ! empty( BSC_TV_Player::select_next( $single, $repeat_state, $repeat_settings, $t )['music'] ), 'Music fits when the anchor has enough time beyond the replay cooldown' );
$later_repeat = grid_state( $t + 6000 ); $later_repeat['plan'][] = array_replace( $repeat_state['plan'][1], array( 'id' => 'later-repeat', 'start' => $t + 3000 + 6 * HOUR_IN_SECONDS, 'end' => $t + 6000 + 6 * HOUR_IN_SECONDS ) );
grid_check( empty( BSC_TV_Player::select_next( $single, $later_repeat, $repeat_settings, $t )['music'] ), 'A repeat anchor beyond the first fixed programme is protected too' );
$tail = array_replace( $anchor, array( 'id' => 'tail', 'start' => $anchor['end'], 'end' => $anchor['end'] + 3000, 'fixed' => false ) ); $exact['plan'][] = $tail;
BSC_TV_Music::delay_automatic( $exact, 0 );
grid_check( $exact['plan'][2] === $tail, 'Delaying a prefix never moves rows beyond the fixed anchor' );
$forecast = BSC_TV_Player_Schedule::build( grid_state(), $pool, $settings, $t );
$music_rows = array_values( array_filter( $forecast, static function ( $row ) { return ! empty( $row['music'] ); } ) );
grid_check( count( $music_rows ) > 2, 'The existing scheduler persists multiple upcoming tracks in the common queue' );
$consistent = true; foreach ( $forecast as $index => $row ) { if ( $index && $row['start'] < $forecast[ $index - 1 ]['end'] ) { $consistent = false; } if ( ! empty( $row['music'] ) && $row['end'] - $row['start'] !== $row['music']['entry'] + $row['music']['duration'] + $row['music']['exit'] ) { $consistent = false; } }
grid_check( $consistent, 'All automatic programmes follow complete music blocks without overlap' );
$stored = grid_state(); $stored['plan'] = $forecast; $stored['plan_source'] = BSC_TV_Player_Schedule::signature( $pool, $settings );
$rebuilt = BSC_TV_Music::reset_future( $stored, $t );
grid_check( $rebuilt['vod'] === $stored['vod'] && ! count( array_filter( $rebuilt['plan'], static function ( $row ) { return ! empty( $row['music'] ); } ) ), 'Rebuild removes future music choices while preserving the current programme' );
grid_check( $rebuilt['plan'][0]['start'] === $t, 'Removing future music releases its reserved time for recalculation' );
$mixed = grid_state( $t + 10000 ); $mixed['plan'] = BSC_TV_Player_Schedule::build( $mixed, $single, $settings, $t ); $clean = BSC_TV_Music::reset_future( $mixed, $t );
$fixed = array_values( array_filter( $clean['plan'], static function ( $row ) { return 'fixed-anchor' === ( $row['id'] ?? '' ); } ) );
grid_check( count( $fixed ) === 1 && $fixed[0]['start'] === $t + 10000 && $fixed[0]['end'] === $t + 13000, 'Removing old music never pulls a fixed anchor forward' );
$longer = $config; $longer['bundled_animation'] = false; $longer['entry'] = 7; $longer['exit'] = 5; update_option( BSC_TV_Music::SETTINGS, $longer, false );
$settings_longer = $settings; $settings_longer['music'] = $longer; $pool_longer = array( $beer['key'] => $beer, $other['key'] => $other ) + BSC_TV_Music::pool();
$with_new_times = BSC_TV_Player_Schedule::build( $rebuilt, $pool_longer, $settings_longer, $t );
grid_check( $with_new_times[0]['end'] - $with_new_times[0]['start'] === $with_new_times[0]['music']['duration'] + 12 && $with_new_times[1]['start'] === $with_new_times[0]['end'], 'Rebuilt times include changed entry and exit durations without retaining old offsets' );
update_option( BSC_TV_Music::SETTINGS, $config, false );
$diagnostic = BSC_TV_Music::diagnostic( array( 'plan' => $forecast ) );
grid_check( 5 === $diagnostic['total'] && 4 === $diagnostic['counts']['ready'], 'Diagnostics distinguish all imported references from genuinely ready clips' );
grid_check( 1 === $diagnostic['counts']['inactive'] && 1 === $diagnostic['counts']['rights'] && 1 === $diagnostic['counts']['official'] && 1 === $diagnostic['counts']['availability'] && 1 === $diagnostic['counts']['duration'] && 1 === $diagnostic['counts']['artist'], 'Diagnostics report missing activation, rights, official source, availability, duration and artist separately' );
grid_check( $diagnostic['planned'] === count( $music_rows ) && $diagnostic['categories']['morning'] === 4 && $diagnostic['categories']['day'] === 4, 'Diagnostics report exact planned and per-category eligible counts' );
$pending_media = get_post_meta( $pending, BSC_TV_Music::META, true );
grid_check( ! BSC_TV_Music::approved( $pending_media ), 'Neither a rebuild nor diagnostics validate imported music automatically' );
$now = time();
$channel_id = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_status' => 'publish', 'post_title' => 'Existing beer channel', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/gridbeerfixture' ) ) ) ) );
$show_id = wp_insert_post( array( 'post_type' => BSC_TV_Content::SHOW, 'post_status' => 'publish', 'post_title' => 'Long beer programme', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::show_data( array( 'url' => 'https://youtu.be/M7lc1UVf-VE', 'starts_at' => gmdate( DATE_ATOM, $now - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ), 'status' => 'ended', 'channel_id' => $channel_id ) ), BSC_TV_Player::MEDIA => array( 'mode' => 'yes', 'duration' => 3000, 'published_at' => $now - DAY_IN_SECONDS ) ) ) );
delete_transient( BSC_TV_Player::CACHE ); $actual_pool = BSC_TV_Player::media(); $actual_beer = $actual_pool['youtube:M7lc1UVf-VE'];
$actual = array( 'music_seed' => 'persisted-real-seed', 'rotation_seed' => 'persisted-tv-seed' );
$actual['vod'] = BSC_TV_Player::begin( $actual_beer + array( 'available' => $now - 100 ), $actual );
$actual['plan'] = BSC_TV_Player_Schedule::build( $actual, $actual_pool, BSC_TV_Player::settings(), $now ); $actual['plan_source'] = BSC_TV_Player_Schedule::signature( $actual_pool, BSC_TV_Player::settings() ); update_option( BSC_TV_Player::STATE, $actual, false );
$requests = 0; add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_grid_network', 'Unexpected metadata request' ); } );
$day = BSC_TV_Player::day_snapshot( $now ); $upcoming = array_values( array_filter( $day['items'], static function ( $item ) { return ! empty( $item['music'] ) && 'future' === $item['phase']; } ) );
grid_check( count( $upcoming ) > 1, 'The real daily snapshot contains upcoming music from the shared saved queue' );
$upcoming_one = $upcoming[0];
grid_check( 'youtube' === $upcoming_one['resource']['platform'] && str_starts_with( $upcoming_one['url'], 'https://www.youtube.com/watch?v=' ), 'A future music card links to the real video rather than a future identity screen' );
grid_check( $upcoming_one['clip_start'] - $upcoming_one['start'] === 11 && $upcoming_one['end'] - $upcoming_one['clip_end'] === 11, 'Daily metadata exposes exact clip start/end and both animation durations' );
grid_check( ! $upcoming_one['live'] && '' === $upcoming_one['avatar'] && '' === $upcoming_one['profile_url'], 'Upcoming music is never tagged LIVE or assigned a fake BDD profile' );
$config_day = BSC_TV_Day::config( array( 'page_size' => 50 ) ); $programme = BSC_TV_Day::programme( $config_day, $now );
grid_check( strpos( $programme['html'], 'MUSIC BREAK' ) !== false && strpos( $programme['html'], 'bsc-day__item--music' ) !== false, 'The existing day widget renders music cards with a distinct badge and style' );
grid_check( strpos( $programme['html'], 'animations BSC : 11 s + 11 s' ) !== false && strpos( $programme['html'], wp_date( 'H:i:s', $upcoming_one['clip_start'] ) ) !== false, 'The daily widget explains exact clip and logo-animation timings' );
$without = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'show_music' => 'no', 'page_size' => 50 ) ), $now );
grid_check( strpos( $without['html'], 'MUSIC BREAK' ) === false && strpos( $without['html'], 'Long beer programme' ) !== false, 'Elementor can hide music without changing beer programme times' );
$simple = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'show_music_timing' => 'no', 'page_size' => 50 ) ), $now );
grid_check( strpos( $simple['html'], 'MUSIC BREAK' ) !== false && strpos( $simple['html'], 'bsc-day__music-timing' ) === false, 'The detailed animation note is independently customizable' );
$custom = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'music_label' => '<script>bad</script>Music & Chill', 'page_size' => 50 ) ), $now );
grid_check( strpos( $custom['html'], '<script>' ) === false && strpos( $custom['html'], 'Music &amp; Chill' ) !== false, 'Custom music labels are escaped like the existing widget labels' );
$paged = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $now, 1 ); $more = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $now, 2 );
grid_check( $paged['count'] <= 6 && ( $paged['has_more'] ? $more['count'] > $paged['count'] : $more['count'] === $paged['count'] ) && strpos( $more['html'], 'MUSIC BREAK' ) !== false, 'Five-item batches expand when more programmes remain today, including persisted tracks' );
grid_check( 0 === $requests, 'Day lists, music planning and diagnostics make no external API requests' );
$snapshot_one = BSC_TV_Player::snapshot( $now ); $snapshot_two = BSC_TV_Player::snapshot( $now );
grid_check( $snapshot_one === $snapshot_two, 'Repeated public reads keep identical future musical choices' );
$midnight = $music_rows[0]; $midnight['start'] = ( new DateTimeImmutable( '2026-11-22 23:59:58', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp(); $midnight['end'] = $midnight['start'] + $midnight['music']['duration'] + 6;
$groups = BSC_TV_Player_Schedule::days( array( 'vod' => $midnight ), $midnight['start'], 'Europe/Paris' ); $groups = array_values( $groups );
grid_check( count( $groups[0]['rows'] ) === 1 && count( $groups[1]['rows'] ) === 1 && $groups[1]['rows'][0]['continued'], 'A music block crossing midnight appears on both affected calendar days' );
foreach ( array( '2026-03-29T00:59:58Z', '2026-10-25T00:59:58Z' ) as $date ) { $dst = $midnight; $dst['start'] = strtotime( $date ); $dst['end'] = $dst['start'] + $dst['music']['duration'] + 6; $card = BSC_TV_Music::programme( $dst, $dst['start'] ); grid_check( $card['clip_end'] - $card['clip_start'] === $dst['music']['duration'], 'DST changes presentation time without altering the full clip duration' ); }
wp_set_current_user( 1 ); if ( ! current_user_can( 'manage_options' ) ) { $users = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $users[0]->ID ); }
add_filter( 'wp_redirect', static function () { throw new RuntimeException( 'grid_redirect' ); } );
$_POST = array( 'operation' => 'rebuild', '_wpnonce' => wp_create_nonce( 'bsc_tv_music_settings' ) ); $_REQUEST = $_POST;
$before = (array) get_option( BSC_TV_Player::STATE );
try { BSC_TV_Music_Admin::settings(); } catch ( RuntimeException $error ) { if ( 'grid_redirect' !== $error->getMessage() ) { throw $error; } }
$after = get_option( BSC_TV_Player::STATE );
grid_check( BSC_TV_Music::settings()['enabled'] && BSC_TV_Music::settings()['flexible'], 'One authenticated admin action enables music scheduling and rebuilds the automatic grid' );
grid_check( $after['vod'] === $before['vod'] && $after['music_seed'] !== $before['music_seed'], 'Rebuild changes only future random choices, preserving the on-air programme' );
grid_check( count( array_filter( $after['plan'], static function ( $row ) { return ! empty( $row['music'] ); } ) ) > 0, 'The admin action persists the recalculated musical grid for all visitors' );
grid_check( ! BSC_TV_Music::approved( get_post_meta( $pending, BSC_TV_Music::META, true ) ), 'The scheduling action does not bypass per-clip authorization' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-day' ); $controls = $widget->get_stack( false ); $controls = $controls['controls'] + ( $controls['style_controls'] ?? array() );
grid_check( isset( $controls['show_music'], $controls['show_music_timing'], $controls['time_precision'], $controls['day_color_music'] ), 'The existing day widget exposes music, timing, precision and badge color controls' );
ob_start(); BSC_TV_Music_Admin::page(); $admin_html = ob_get_clean();
grid_check( strpos( $admin_html, 'value="rebuild"' ) !== false && strpos( $admin_html, 'Diagnostic de la programmation musicale' ) !== false && strpos( $admin_html, '4 / 5' ) !== false, 'The admin screen shows the rebuild action and actual ready/total diagnostic' );
$qa = dirname( __DIR__, 2 ) . '/tv-music-grid-qa'; if ( ! is_dir( $qa ) ) { mkdir( $qa ); }
file_put_contents( $qa . '/admin.html', $admin_html );
file_put_contents( $qa . '/day.html', BSC_TV_Day::render( array( 'page_size' => 10 ) ) );
file_put_contents( $qa . '/day.json', wp_json_encode( BSC_TV_Day::programme( BSC_TV_Day::config( array( 'page_size' => 10 ) ) ) ) );
file_put_contents( $qa . '/day-more.json', wp_json_encode( BSC_TV_Day::programme( BSC_TV_Day::config( array( 'page_size' => 10 ) ), null, 2 ) ) );
echo 'OK ' . $checks . " music randomization, schedule and daily-list checks.\n";
