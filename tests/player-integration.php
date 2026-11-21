<?php
/** php tests/player-integration.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture_root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! is_file( $fixture_root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Provide the local WordPress integration fixture path.' );
}
$database_dir = sys_get_temp_dir() . '/bsc-tv-player-' . bin2hex( random_bytes( 6 ) );
mkdir( $database_dir );
$database = $database_dir . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture_root . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) {
	throw new RuntimeException( 'Cannot copy the disposable fixture database.' );
}
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/player-admin.php';
if ( ! has_action( 'bsc_tv_admin_player' ) ) {
	BSC_TV_Player_Admin::register();
}
$checks = 0;
function player_check( $value, $label ) {
	global $checks;
	++$checks;
	if ( ! $value ) { throw new RuntimeException( $label ); }
}
function player_post( $type, $title, $data, $status = 'publish' ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'meta_input' => array( BSC_TV_Content::DATA => $data ) ), true );
	player_check( is_int( $id ) && $id > 0, 'Fixture inserted: ' . $title );
	return $id;
}
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) {
	foreach ( BSC_TV_Content::posts( $type, array( 'publish', 'draft', 'pending', 'private', BSC_TV_Content::ARCHIVED ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
delete_option( BSC_TV_Player::STATE );
delete_transient( BSC_TV_Player::CACHE );
update_option( 'timezone_string', 'Europe/Paris' );
$now = time();
$profile = player_post( BSC_BDD_Content::POST_TYPE, 'Créateur test BSC TV', array() );
$avatar = wp_insert_attachment( array( 'guid' => 'https://example.com/avatar.png', 'post_title' => 'Avatar test', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
update_post_meta( $profile, '_thumbnail_id', $avatar );
add_filter( 'image_downsize', static function ( $result, $id ) use ( $avatar ) { return $avatar === $id ? array( 'https://example.com/avatar.png', 100, 100, false ) : $result; }, 10, 2 );
$channel = player_post( BSC_TV_Content::CHANNEL, 'Chaîne existante', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/beerplayerqa', 'bdd_id' => $profile ) ) );
$data = BSC_TV_Content::show_data( array( 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'starts_at' => gmdate( DATE_ATOM, $now - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ), 'status' => 'ended', 'creator' => 'Créateur test', 'channel_id' => $channel ) );
$show = player_post( BSC_TV_Content::SHOW, 'Émission existante', $data, BSC_TV_Content::ARCHIVED );
$media = array( 'mode' => 'yes', 'duration' => 3600, 'published_at' => $now - DAY_IN_SECONDS, 'premiere' => true, 'url' => '' );
update_post_meta( $show, BSC_TV_Player::MEDIA, $media );
$draft_data = $data;
$draft_data['url'] = 'https://youtu.be/QA123abcXYZ';
$draft = player_post( BSC_TV_Content::SHOW, 'Brouillon exclu', $draft_data, 'draft' );
update_post_meta( $draft, BSC_TV_Player::MEDIA, $media );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'live_priority' => true, 'recent' => 'strong', 'repeat_hours' => 0, 'tolerance' => 8 ) );
$settings = BSC_TV_Sync::settings();
$settings['live_enabled'] = 1;
update_option( BSC_TV_Sync::SETTINGS, $settings );
$requests = 0;
$request_hosts = array();
add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) use ( &$requests, &$request_hosts ) { ++$requests; $request_hosts[] = wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH ); return new WP_Error( 'unexpected_network', 'Unexpected external request in the player.' ); }, 10, 3 );
$one = BSC_TV_Player::snapshot( $now );
$two = BSC_TV_Player::snapshot( $now + 900 );
player_check( $show === $one['current']['show_id'] && $one['current']['key'] === $two['current']['key'] && 900 === $two['current']['offset'], 'Two visitors share the same offset clock' );
player_check( 'https://example.com/avatar.png' === $one['current']['avatar'] && ! empty( $one['current']['profile_url'] ), 'BDD avatar and public profile reused' );
player_check( 1 === count( BSC_TV_Player::media() ), 'Draft never exposed through player' );
player_check( 'Europe/Paris' === $one['timezone'], 'Site timezone is returned' );
$state = array( 'key' => 'beerplayerqa', 'live_at' => $now + 1000, 'live' => array( array( 'id' => 'live-qa', 'url' => 'https://www.twitch.tv/beerplayerqa', 'title' => 'Vrai direct confirmé', 'start' => $now - 100, 'end' => 0 ) ) );
update_post_meta( $channel, BSC_TV_Content::STATE, $state );
$live = BSC_TV_Player::snapshot( $now + 1000 );
player_check( $live['current']['live'] && 'twitch' === $live['current']['resource']['platform'], 'Fresh existing Twitch cache has priority' );
player_check( ! empty( $live['next'][0]['after_live'] ), 'Forecast does not promise a fixed end for a live' );
update_post_meta( $channel, BSC_TV_Content::STATE, array( 'key' => 'beerplayerqa', 'live_at' => $now + 1600, 'live' => array() ) );
$resume = BSC_TV_Player::snapshot( $now + 1600 );
player_check( ! $resume['current']['live'] && $show === $resume['current']['show_id'] && 1000 === $resume['current']['offset'], 'Same replay resumes at its paused position' );
$state['live_at'] = $now - BSC_TV_Sync::MAX_LIVE_AGE;
update_post_meta( $channel, BSC_TV_Content::STATE, $state );
player_check( ! BSC_TV_Player::snapshot( $now + 1601 )['current']['live'], 'Expired live confirmation never hijacks antenna' );
update_post_meta( $channel, BSC_TV_Player::CHANNEL, array( 'replays' => 0, 'live' => 0 ) );
player_check( null === BSC_TV_Player::snapshot( $now + 1602 )['current'], 'Channel opt-out immediately applies to an existing slot' );
update_post_meta( $channel, BSC_TV_Player::CHANNEL, array( 'replays' => 1, 'live' => 1 ) );
player_check( BSC_TV_Player::resource( 'https://www.twitch.tv/videos/123456789' )['id'] === 'v123456789' && ! BSC_TV_Player::resource( 'https://www.twitch.tv/beerplayerqa' ), 'Replay URL parser distinguishes Twitch VOD from channel' );
player_check( ! BSC_TV_Player::resource( 'https://youtube.com.evil.example/watch?v=M7lc1UVf-VE' ) && ! BSC_TV_Player::resource( 'javascript:alert(1)' ), 'Embed parser rejects unrelated hosts and unsafe URLs' );
$request = new WP_REST_Request( 'GET', '/bsc-tv/v1/player' );
$response = rest_do_request( $request );
player_check( 200 === $response->get_status() && 'no-store, max-age=0' === $response->get_headers()['Cache-Control'], 'Public light REST endpoint is not cached by the page cache' );
player_check( 0 === $requests, 'Player, catalogue and REST do not make external API calls' );
$html = BSC_TV_Player::render( array( 'logo' => array( 'url' => 'https://example.com/bsc-logo.png' ) ) );
player_check( false !== strpos( $html, 'bsc-logo.png' ) && false !== strpos( $html, 'data-player-avatar' ) && false === strpos( $html, '<iframe' ), 'View has fixed branding and avatar; official SDK loads lazily' );
player_check( wp_script_is( 'bsc-tv-player', 'enqueued' ) && wp_style_is( 'bsc-tv-player', 'enqueued' ), 'Assets load when the player is rendered' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-player' );
player_check( $widget instanceof BSC_TV_Player_Widget, 'One new Elementor player widget registered' );
$controls = $widget->get_stack( false );
$controls = $controls['controls'] + ( $controls['style_controls'] ?? array() );
player_check( isset( $controls['logo'], $controls['logo_size'], $controls['avatar_size'], $controls['player_avatar_border_border'], $controls['show_premiere'] ) && ( ! empty( $controls['logo_size']['is_responsive'] ) || isset( $controls['logo_size_mobile'] ) ) && ( ! empty( $controls['avatar_size']['is_responsive'] ) || isset( $controls['avatar_size_tablet'] ) ), 'Responsive, branding, BDD and badges controls registered' );
player_check( '' === $controls['curve_enabled']['default'] && '' === $controls['crt_enabled']['default'], 'Retro effects are off on existing widgets' );
$retro_settings = array( 'curve_enabled' => 'yes', 'crt_enabled' => 'yes', 'curve_depth' => array( 'size' => 22, 'unit' => 'px' ), 'curve_depth_mobile' => array( 'size' => 12, 'unit' => 'px' ), 'curve_radius' => array( 'size' => 44, 'unit' => 'px' ), 'curve_color' => '#292c30', 'curve_corner_color' => '#a53e71', 'curve_radius_mobile' => array( 'size' => 24, 'unit' => 'px' ), 'crt_intensity' => array( 'size' => 55, 'unit' => '%' ), 'crt_intensity_mobile' => array( 'size' => 25, 'unit' => '%' ), 'crt_spacing' => array( 'size' => 4, 'unit' => 'px' ), 'crt_glow' => array( 'size' => 24, 'unit' => 'px' ), 'crt_color' => '#66efbc', 'logo' => array( 'url' => 'https://example.com/bsc-logo.png' ) );
$retro_variants = array( 'a100001' => array( 'curve_enabled' => '', 'crt_enabled' => '' ), 'a100002' => array( 'curve_enabled' => 'yes', 'crt_enabled' => '' ), 'a100003' => array( 'curve_enabled' => '', 'crt_enabled' => 'yes' ), 'a100004' => array( 'curve_enabled' => 'yes', 'crt_enabled' => 'yes' ) );
$elements = array();
foreach ( $retro_variants as $id => $toggles ) {
	$elements[] = array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'bsc-tv-player', 'settings' => array_replace( $retro_settings, $toggles ), 'elements' => array() );
}
$retro_page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Elementor retro effects fixture', 'post_status' => 'publish' ) );
update_post_meta( $retro_page, '_elementor_edit_mode', 'builder' );
update_post_meta( $retro_page, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
$retro_css = ( new Elementor\Core\Files\CSS\Post( $retro_page ) )->get_content();
$styles_for = static function ( $id ) use ( $retro_css ) {
	preg_match_all( '/\.elementor-element-' . $id . ' \.bsc-player\{([^}]*)\}/', $retro_css, $rules );
	return implode( '', $rules[1] );
};
player_check( false === strpos( $styles_for( 'a100001' ), '--bsc-player-curve:' ) && false === strpos( $styles_for( 'a100001' ), '--bsc-player-crt:' ), 'Saved disabled effects emit no activation CSS' );
player_check( false !== strpos( $styles_for( 'a100002' ), '--bsc-player-curve:1;' ) && false === strpos( $styles_for( 'a100002' ), '--bsc-player-crt:' ), 'Saved curved glass activates independently in Elementor CSS' );
player_check( false === strpos( $styles_for( 'a100003' ), '--bsc-player-curve:' ) && false !== strpos( $styles_for( 'a100003' ), '--bsc-player-crt:1;' ), 'Saved CRT activates independently in Elementor CSS' );
player_check( false !== strpos( $styles_for( 'a100004' ), '--bsc-player-corner-color:#a53e71;' ) && false !== strpos( $styles_for( 'a100004' ), '--bsc-player-curve-color:#292c30;' ) && false !== strpos( $styles_for( 'a100004' ), '--bsc-player-crt-color:#66efbc;' ) && false !== strpos( $styles_for( 'a100004' ), '--bsc-player-crt-spacing:4px;' ) && false !== strpos( $styles_for( 'a100004' ), '--bsc-player-crt-glow:24px;' ), 'Saved effect colours, scanline spacing and glow render through Elementor' );
player_check( preg_match( '/@media[^{}]+max-width:[^{}]+\{[^@]*--bsc-player-curve-depth:12px;[^@]*--bsc-player-crt-intensity:25;/s', $retro_css ), 'Elementor emits separate mobile relief and intensity settings' );
foreach ( array( 'bsc-tv-programme', 'bsc-tv-mega-menu', 'bsc-tv-badge', 'bsc-tv-guide' ) as $name ) { player_check( Elementor\Plugin::instance()->widgets_manager->get_widget_types( $name ), 'Existing widget retained: ' . $name ); }
$requests = 0;
$request_hosts = array();
player_check( ! wp_next_scheduled( 'bsc_tv_player_tick' ), 'No extra minute cron introduced' );
ob_start();
BSC_TV_Player_Admin::section();
$admin = ob_get_clean();
player_check( false !== strpos( $admin, 'bsc_tv_player_queue' ) && false !== strpos( $admin, 'Programmer ensuite' ), 'Programming controls integrated into existing admin page' );
$reflection = new ReflectionMethod( BSC_TV_Sync::class, 'yt_event' );
$video = $reflection->invoke( null, array( 'id' => 'M7lc1UVf-VE', 'snippet' => array( 'title' => 'Archive', 'channelId' => 'UCtest', 'publishedAt' => gmdate( DATE_ATOM, $now - DAY_IN_SECONDS ), 'liveBroadcastContent' => 'none' ), 'liveStreamingDetails' => array( 'actualStartTime' => gmdate( DATE_ATOM, $now - 7200 ), 'actualEndTime' => gmdate( DATE_ATOM, $now - 3600 ) ), 'contentDetails' => array( 'duration' => 'PT1H2M3S' ), 'status' => array( 'embeddable' => true, 'privacyStatus' => 'public' ) ) );
player_check( 3723 === $video['duration'] && $video['playable'] && $video['published_at'] > 0, 'Existing YouTube details response supplies duration without extra collection' );
update_post_meta( $show, BSC_TV_Player::APPROVED, 1 );
player_check( isset( BSC_TV_Player::enrichment_ids( $now )[ $show ] ), 'Known approved archive participates in the existing daily enrichment batch' );
update_post_meta( $show, BSC_TV_Content::STATE, array( 'key' => 'M7lc1UVf-VE', 'live_at' => $now, 'video' => $video ) );
player_check( ! isset( BSC_TV_Player::enrichment_ids( $now )[ $show ] ), 'Fresh metadata is not fetched again on each daily pass' );
$before_media = get_post_meta( $show, BSC_TV_Player::MEDIA, true );
$_POST = array( 'bsc_player_nonce' => wp_create_nonce( 'bsc_tv_player_media' ), 'bsc_player' => array( 'mode' => 'no' ) );
wp_set_current_user( 0 );
BSC_TV_Player_Admin::save_media( $show, get_post( $show ) );
player_check( $before_media === get_post_meta( $show, BSC_TV_Player::MEDIA, true ), 'Anonymous media changes are rejected' );
$_POST = array();
$hidden_data = $data + array( 'duration' => 120, 'published_at' => $now - 100, 'playable' => true, 'embeddable' => true );
$hidden_data['url'] = 'https://youtu.be/QA123abcABC';
$hidden = player_post( BSC_TV_Content::SHOW, 'Ancien brouillon archivé', $hidden_data, BSC_TV_Content::ARCHIVED );
update_post_meta( $hidden, BSC_TV_Library::ORIGIN, 'old-feed-draft' );
update_post_meta( $hidden, BSC_TV_Library::FEED, 'https://example.com/feed.ics' );
player_check( 1 === count( BSC_TV_Player::media() ), 'An archived feed draft does not become an approved replay' );
delete_option( BSC_TV_Player::STATE );
$first_avatar = BSC_TV_Player::snapshot( $now );
$profile_b = player_post( BSC_BDD_Content::POST_TYPE, 'Créateur B', array() );
$avatar_b = wp_insert_attachment( array( 'guid' => 'https://example.com/avatar-b.png', 'post_title' => 'Avatar B', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
update_post_meta( $profile_b, '_thumbnail_id', $avatar_b );
add_filter( 'image_downsize', static function ( $result, $id ) use ( $avatar_b ) { return $avatar_b === $id ? array( 'https://example.com/avatar-b.png', 100, 100, false ) : $result; }, 11, 2 );
$channel_b = player_post( BSC_TV_Content::CHANNEL, 'Chaîne B', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/beerplayerqb', 'bdd_id' => $profile_b ) ) );
$data_b = $data;
$data_b['url'] = 'https://youtu.be/aqz-KE-bpKQ';
$data_b['channel_id'] = $channel_b;
$show_b = player_post( BSC_TV_Content::SHOW, 'Deuxième émission', $data_b, BSC_TV_Content::ARCHIVED );
update_post_meta( $show_b, BSC_TV_Player::MEDIA, $media );
$second_avatar = BSC_TV_Player::snapshot( $now + 3600 );
player_check( 'https://example.com/avatar.png' === $first_avatar['current']['avatar'] && 'https://example.com/avatar-b.png' === $second_avatar['current']['avatar'], 'BDD avatar changes automatically at the programme boundary' );
player_check( 0 === $requests, 'All additional programming checks remain local: ' . implode( ', ', $request_hosts ) );

delete_option( BSC_TV_Player::STATE );
$schedule_current = BSC_TV_Player::snapshot();
$prepared = BSC_TV_Player_Schedule::prepare();
player_check( ! is_wp_error( $prepared ) && count( $prepared['plan'] ) >= 70, 'Administrator prepares and stores three days in the existing clock' );
$plan_before = $prepared['plan'];
$plan_hash = BSC_TV_Player_Schedule::fingerprint( $prepared );
$public_plan = rest_do_request( new WP_REST_Request( 'GET', '/bsc-tv/v1/player' ) )->get_data();
player_check( $public_plan['next'][0]['show_id'] === $plan_before[0]['show_id'] && $public_plan['next'][0]['start'] === $plan_before[0]['start'], 'REST previews the same saved queue shown in administration' );
$forbidden = BSC_TV_Player_Schedule::update( 'replace', $plan_before[0]['id'], $show, $plan_hash );
player_check( is_wp_error( $forbidden ) && 'schedule_forbidden' === $forbidden->get_error_code() && $plan_before === get_option( BSC_TV_Player::STATE )['plan'], 'Anonymous schedule edits are rejected without changing the queue' );
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
player_check( ! empty( $administrators ), 'Disposable fixture contains a test administrator' );
wp_set_current_user( $administrators[0] );
$saved = BSC_TV_Player_Schedule::update( 'replace', $plan_before[0]['id'], $show, $plan_hash );
player_check( true === $saved && $show === get_option( BSC_TV_Player::STATE )['plan'][0]['show_id'], 'Authorized replacement persists in WordPress' );
$public_replacement = BSC_TV_Player::snapshot();
player_check( $show === $public_replacement['next'][0]['show_id'] && $schedule_current['current']['key'] === $public_replacement['current']['key'] && $schedule_current['current']['start'] === $public_replacement['current']['start'], 'Public readers receive the replacement while the current programme remains stable' );
$stale = BSC_TV_Player_Schedule::update( 'move', $plan_before[0]['id'], $plan_before[0]['start'] + 120, $plan_hash );
player_check( is_wp_error( $stale ) && 'schedule_invalid' === $stale->get_error_code(), 'WordPress rejects an obsolete editor revision' );
$fresh = get_option( BSC_TV_Player::STATE );
$moved = BSC_TV_Player_Schedule::update( 'move', $fresh['plan'][0]['id'], $fresh['plan'][0]['start'] + 120, BSC_TV_Player_Schedule::fingerprint( $fresh ) );
player_check( true === $moved && $fresh['plan'][0]['start'] + 120 === BSC_TV_Player::snapshot()['next'][0]['start'], 'A saved time shift immediately appears in the shared REST forecast' );
$security_before = get_option( BSC_TV_Player::STATE );
$die_handler = static function () { return static function () { throw new RuntimeException( 'schedule_authorization_rejected' ); }; };
add_filter( 'wp_die_handler', $die_handler );
$_POST = $_REQUEST = array( 'operation' => 'replace', 'slot_id' => $fresh['plan'][0]['id'], 'show_id' => $show, '_wpnonce' => 'invalid' );
$nonce_rejected = false;
ob_start();
try { do_action( 'admin_post_bsc_tv_player_schedule' ); } catch ( RuntimeException $error ) { $nonce_rejected = 'schedule_authorization_rejected' === $error->getMessage(); }
ob_end_clean();
remove_filter( 'wp_die_handler', $die_handler );
$_POST = $_REQUEST = array();
player_check( $nonce_rejected && $security_before === get_option( BSC_TV_Player::STATE ), 'Actual administrator POST handler rejects an invalid nonce without writing' );
$editor_state = get_option( BSC_TV_Player::STATE );
$_GET = array( 'bsc_player_slot' => $editor_state['plan'][0]['id'], 'bsc_player_edit' => 'replace', 'player_days' => 2 );
ob_start(); BSC_TV_Player_Admin::section(); $schedule_admin = ob_get_clean();
player_check( false !== strpos( $schedule_admin, 'prochaines 48 heures' ) && false !== strpos( $schedule_admin, 'name="plan_fingerprint"' ) && false !== strpos( $schedule_admin, 'Enregistrer le remplacement' ), 'Actual admin view offers a secured replacement editor and two-day grid' );
$_GET['bsc_player_edit'] = 'move';
ob_start(); BSC_TV_Player_Admin::section(); $schedule_move = ob_get_clean();
player_check( false !== strpos( $schedule_move, 'type="datetime-local" name="starts_at"' ) && false !== strpos( $schedule_move, 'Enregistrer le décalage' ), 'Actual admin view offers the time editor in the site timezone' );
$_GET = array();
player_check( 0 === $requests, 'Three-day admin projection and edits make no external API calls' );
$fixture_dir = dirname( __DIR__, 2 ) . '/tv-player-qa';
if ( ! is_dir( $fixture_dir ) ) { mkdir( $fixture_dir ); }
delete_option( BSC_TV_Player::STATE );
$preview = str_replace( rest_url( 'bsc-tv/v1/player' ), '/tv-player-qa/state.json', $html );
file_put_contents( $fixture_dir . '/index.html', '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BSC TV 24/7 · aperçu local</title><link rel="stylesheet" href="/bsc-tv/assets/player.css"><style>body{margin:24px auto;max-width:1000px;background:#0b0b0e;padding:12px}</style><script defer src="/bsc-tv/assets/player.js"></script>' . $preview . '</html>' );
$retro_preview = str_replace( rest_url( 'bsc-tv/v1/player' ), '/tv-player-qa/state.json', BSC_TV_Player::render( $retro_settings ) );
file_put_contents( $fixture_dir . '/retro.html', '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BSC TV · écran bombé et CRT</title><link rel="stylesheet" href="/bsc-tv/assets/player.css"><style>body{margin:24px auto;max-width:1000px;background:#0b0b0e;padding:12px}' . $retro_css . '</style><script defer src="/bsc-tv/assets/player.js"></script><main class="elementor-' . $retro_page . '"><div class="elementor-element elementor-element-a100004">' . $retro_preview . '</div></main></html>' );
file_put_contents( $fixture_dir . '/state.json', wp_json_encode( BSC_TV_Player::snapshot() ) );
$schedule_fixture_dir = dirname( __DIR__, 2 ) . '/tv-schedule-qa';
if ( ! is_dir( $schedule_fixture_dir ) ) { mkdir( $schedule_fixture_dir ); }
$source_database = new SQLite3( $database, SQLITE3_OPEN_READONLY );
$browser_database = new SQLite3( $schedule_fixture_dir . '/fixture.sqlite' );
if ( ! $source_database->backup( $browser_database ) ) { throw new RuntimeException( 'Cannot export the disposable browser fixture.' ); }
$browser_database->close();
$source_database->close();
echo "OK {$checks} WordPress/Elementor player checks, zero external API calls.\n";
