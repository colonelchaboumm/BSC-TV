<?php
/** php tests/day-integration.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture_root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! is_file( $fixture_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$database_dir = sys_get_temp_dir() . '/bsc-tv-day-' . bin2hex( random_bytes( 6 ) );
mkdir( $database_dir );
$database = $database_dir . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture_root . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Cannot copy disposable database.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture_root . '/wp-load.php';
$checks = 0;
function day_check( $value, $label ) {
	global $checks;
	++$checks;
	if ( ! $value ) { throw new RuntimeException( $label ); }
}
function day_post( $type, $title, $data, $status = 'publish', $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_title' => wp_slash( $title ), 'post_status' => $status, 'meta_input' => array( BSC_TV_Content::DATA => wp_slash( $data ) ) ), $extra ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	return $id;
}
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
delete_option( BSC_TV_Player::STATE );
delete_transient( BSC_TV_Player::CACHE );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'live_priority' => true ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => true, 'youtube_key' => 'private-api-value' ) );
update_option( BSC_TV_Player_Daily::KEY, 'private-import-value', false );
// Elementor's editor consults its own product catalogue when controls are first
// initialized. Supply that unrelated cache locally so every subsequent HTTP
// attempt remains an error, including during CSS generation and public refresh.
set_transient( Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );
$requests = 0;
$request_hosts = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requests, &$request_hosts ) { ++$requests; $request_hosts[] = wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH ); return new WP_Error( 'unexpected_http', 'No network allowed in the daily list.' ); }, 10, 3 );
$now = time();
$bounds = BSC_TV_Player::day_bounds( $now, 'Europe/Paris' );
$at = $bounds[0] + 12 * HOUR_IN_SECONDS + 45;
$profile = day_post( BSC_BDD_Content::POST_TYPE, 'Créateur bière du jour', array() );
$avatar = wp_insert_attachment( array( 'guid' => 'https://example.com/avatar-day.jpg', 'post_title' => 'Avatar du jour', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ) );
update_post_meta( $profile, '_thumbnail_id', $avatar );
add_filter( 'image_downsize', static function ( $value, $id ) use ( $avatar ) { return $id === $avatar ? array( 'https://example.com/avatar-day.jpg', 100, 100, false ) : $value; }, 10, 2 );
$channel = day_post( BSC_TV_Content::CHANNEL, 'Brasserie A', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/daybeerqa', 'bdd_id' => $profile ) ) );
$channel_b = day_post( BSC_TV_Content::CHANNEL, 'Brasserie B', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/daybeerqb' ) ) );
$data = array( 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'platform' => 'youtube', 'start' => $bounds[0] - DAY_IN_SECONDS, 'end' => $bounds[0] - DAY_IN_SECONDS + 1800, 'status' => 'ended', 'channel_id' => $channel, 'bdd_id' => 0, 'creator' => '', 'image' => 'https://example.com/ipa-day.jpg' );
$show = day_post( BSC_TV_Content::SHOW, 'IPA <em>du jour</em> & café', $data );
update_post_meta( $show, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => $bounds[0] - 2 * DAY_IN_SECONDS ) );
$data_b = array_replace( $data, array( 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'channel_id' => $channel_b, 'image' => '' ) );
$show_b = day_post( BSC_TV_Content::SHOW, 'Émission sans image', $data_b );
update_post_meta( $show_b, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => $bounds[0] - DAY_IN_SECONDS ) );
$draft_data = array_replace( $data, array( 'url' => 'https://youtu.be/QA123abcXYZ' ) );
$draft = day_post( BSC_TV_Content::SHOW, 'DRAFT SECRET', $draft_data, 'draft' );
update_post_meta( $draft, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => $bounds[0] - DAY_IN_SECONDS ) );
$settings = BSC_TV_Player::settings();
$pool = BSC_TV_Player::media();
$seed = BSC_TV_Player::advance( array( 'rotation_seed' => 'day-view' ), $pool, array(), $settings, $bounds[0] );
$seed = BSC_TV_Player_Schedule::replenish( $seed, $pool, $settings, $bounds[0] );
update_option( BSC_TV_Player::STATE, $seed, false );
$day = BSC_TV_Player::day_snapshot( $at );
day_check( count( $day['items'] ) > 5, 'The daily list returns the full day rather than the five-item player forecast' );
day_check( count( array_filter( $day['items'], static function ( $item ) { return 'past' === $item['phase']; } ) ) > 0 && count( array_filter( $day['items'], static function ( $item ) { return 'future' === $item['phase']; } ) ) > 0, 'Past and future programmes coexist in the daily list' );
day_check( 1 === count( array_filter( $day['items'], static function ( $item ) { return $item['on_air']; } ) ), 'Exactly one programme is marked on air' );
day_check( ! array_filter( $day['items'], static function ( $item ) use ( $bounds ) { return $item['display_start'] < $bounds[0] || $item['display_end'] > $bounds[1]; } ), 'Only the current calendar day is exposed' );
$with_profile = array_values( array_filter( $day['items'], static function ( $item ) use ( $show ) { return $item['show_id'] === $show; } ) )[0];
day_check( $with_profile['profile_url'] && 'Créateur bière du jour' === $with_profile['creator'] && 'https://example.com/ipa-day.jpg' === $with_profile['image'], 'The show image and inherited BSC BDD profile are reused' );
$default = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'show_past' => 'yes', 'page_size' => 50 ) ), $at );
$initial = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at );
day_check( 6 === $initial['count'] && 5 === substr_count( $initial['html'], 'bsc-day__item--future' ) && 1 === substr_count( $initial['html'], 'aria-current="true"' ) && false === strpos( $initial['html'], 'bsc-day__item--past' ), 'The default view contains the current programme and exactly five upcoming programmes' );
$expanded = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at, 2, $initial['date'] );
day_check( 11 === $expanded['count'] && $expanded['has_more'] && 10 === substr_count( $expanded['html'], 'bsc-day__item--future' ), 'Loading another page cumulatively adds the next five entries without repeating the current programme' );
preg_match_all( '/data-day-key="([^"]+)"/', $expanded['html'], $expanded_keys );
day_check( count( $expanded_keys[1] ) === count( array_unique( $expanded_keys[1] ) ), 'Expanded daily rows have no duplicate programme keys' );
$last_page = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at, 500 );
day_check( ! $last_page['has_more'] && $last_page['count'] > $expanded['count'], 'The final page indicates that no more programmes remain today' );
$new_day = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at, 4, '2000-01-01' );
day_check( 1 === $new_day['page'] && 6 === $new_day['count'], 'An expanded page from an earlier date resets to five upcoming rows' );
day_check( 1 === BSC_TV_Day::config( array( 'page_size' => -7 ) )['page_size'] && 50 === BSC_TV_Day::config( array( 'page_size' => 999999 ) )['page_size'], 'The server bounds the number of rows requested per load' );
day_check( false !== strpos( $default['html'], '<ol class="bsc-day__list' ) && false !== strpos( $default['html'], 'aria-current="true"' ), 'The list uses semantic ordered rows and identifies the current programme' );
day_check( false === strpos( $default['html'], '<em>' ) && false !== strpos( $default['html'], 'IPA du jour &amp; café' ), 'Programme titles are rendered as escaped text' );
day_check( false !== strpos( $default['html'], 'https://example.com/ipa-day.jpg' ) && false !== strpos( $default['html'], 'aria-hidden="true">▶' ), 'Rows display available images and a fallback for missing images' );
$compact_config = BSC_TV_Day::config( array( 'show_past' => 'no', 'show_images' => 'no', 'show_profile' => 'no', 'show_times' => 'no', 'show_date' => 'no', 'show_badges' => 'no', 'link_mode' => 'none' ) );
$compact = BSC_TV_Day::programme( $compact_config, $at );
day_check( substr_count( $compact['html'], 'data-day-key=' ) < substr_count( $default['html'], 'data-day-key=' ) && false === strpos( $compact['html'], 'bsc-day__item--past' ), 'Hiding already broadcast programmes keeps only the current and upcoming rows' );
day_check( false === strpos( $compact['html'], '<img' ) && false === strpos( $compact['html'], '<a ' ) && false === strpos( $compact['html'], 'bsc-day__times' ) && false === strpos( $compact['html'], 'bsc-day__date' ), 'Image, link, clock and date visibility settings affect actual markup' );
$watch = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'link_mode' => 'watch', 'watch_url' => array( 'url' => 'https://example.com/le-live/', 'is_external' => true ) ) ), $at );
day_check( false !== strpos( $watch['html'], 'href="https://example.com/le-live/" target="_blank" rel="noopener noreferrer"' ), 'Programme titles can link to the configured live page' );
$hostile = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'current_label' => '<script>alert(1)</script>À l’antenne', 'link_mode' => 'watch', 'watch_url' => 'javascript:alert(1)' ) ), $at );
day_check( false === strpos( $hostile['html'], '<script' ) && false === strpos( $hostile['html'], 'javascript:' ), 'Custom labels and links cannot inject executable markup' );
$private_state = get_option( BSC_TV_Player::STATE );
$private_state['aired'][] = array( 'show_id' => $draft, 'channel_id' => $channel, 'key' => 'youtube:QA123abcXYZ', 'resource' => array( 'platform' => 'youtube', 'id' => 'QA123abcXYZ' ), 'start' => $bounds[0] + 30, 'end' => $bounds[0] + 90, 'live' => false );
$private_state['aired'][] = array( 'show_id' => $draft, 'channel_id' => $channel, 'key' => 'live:draft', 'resource' => array( 'platform' => 'twitch', 'id' => 'daybeerqa' ), 'title' => 'DRAFT SECRET', 'start' => $bounds[0] + 100, 'end' => $bounds[0] + 150, 'live' => true );
update_option( BSC_TV_Player::STATE, $private_state, false );
$public = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at );
day_check( false === strpos( $public['html'], 'DRAFT SECRET' ) && false === strpos( $public['html'], 'QA123abcXYZ' ), 'Private replay and live records cannot leak through public history' );
$live_state = array( 'key' => 'daybeerqa', 'live_at' => $at, 'live' => array( array( 'id' => 'today-live', 'title' => 'Dégustation en direct', 'url' => 'https://www.twitch.tv/daybeerqa', 'start' => $at - 60, 'end' => 0 ) ) );
update_post_meta( $channel, BSC_TV_Content::STATE, $live_state );
$live = BSC_TV_Day::programme( BSC_TV_Day::config( array() ), $at );
day_check( false !== strpos( $live['html'], 'bsc-day__badge--live' ) && false !== strpos( $live['html'], '>LIVE</span>' ), 'A confirmed real live receives the LIVE indicator' );
update_post_meta( $channel, BSC_TV_Content::STATE, array( 'key' => 'daybeerqa', 'live_at' => $at + 300, 'live' => array() ) );
$after_live = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'show_past' => 'yes', 'page_size' => 50 ) ), $at + 300 );
day_check( false !== strpos( $after_live['html'], 'Dégustation en direct' ) && false === strpos( $after_live['html'], 'bsc-day__badge--live' ), 'An ended live stays in the day history without a stale LIVE badge' );
wp_update_post( array( 'ID' => $channel, 'post_status' => 'draft' ) );
$hidden = BSC_TV_Player::day_snapshot( $at + 300 );
day_check( ! array_filter( $hidden['items'], static function ( $item ) { return 'Brasserie A' === $item['channel']; } ), 'Unpublishing a channel removes its future rows and broadcast history from the public list' );
wp_update_post( array( 'ID' => $channel, 'post_status' => 'publish' ) );
$cross = BSC_TV_Player::advance( array( 'rotation_seed' => 'cross-midnight' ), BSC_TV_Player::media(), array(), $settings, $bounds[0] - 900 );
update_option( BSC_TV_Player::STATE, $cross, false );
$cross_day = BSC_TV_Player::day_snapshot( $bounds[0] + 60 );
day_check( $cross_day['items'][0]['continued'] && $bounds[0] === $cross_day['items'][0]['display_start'], 'A broadcast started yesterday appears as a continuation today' );
update_option( BSC_TV_Player::STATE, $seed, false );
$before = BSC_TV_Player::snapshot( $now );
$html = BSC_TV_Day::render();
$after = BSC_TV_Player::snapshot( $now );
day_check( $before['current'] === $after['current'] && $before['next'] === $after['next'], 'Rendering the daily widget does not reshuffle the shared programme' );
day_check( false !== strpos( $html, 'data-bsc-day=' ) && false === strpos( $html, '<iframe' ) && ! wp_script_is( 'bsc-tv-player', 'enqueued' ) && wp_script_is( 'bsc-tv-day', 'enqueued' ), 'The list loads its own light script without a video SDK or player' );
day_check( false !== strpos( $html, 'data-day-more aria-controls="bsc-day-content-' ) && false !== strpos( $html, '>Afficher plus</button>' ), 'The rendered widget includes an accessible load-more button outside the refreshed list' );
$request = new WP_REST_Request( 'GET', '/bsc-tv/v1/day' );
$response = rest_do_request( $request );
day_check( 200 === $response->get_status() && 'no-store, max-age=0' === $response->get_headers()['Cache-Control'], 'The public daily refresh route is readable without authentication and uncached' );
day_check( false === strpos( wp_json_encode( $response->get_data() ), 'private-import-value' ) && false === strpos( wp_json_encode( $response->get_data() ), 'private-api-value' ), 'The public refresh response contains no scheduler or platform credentials' );
$request->set_param( 'page', 2 );
$ajax = rest_do_request( $request )->get_data();
day_check( 2 === $ajax['page'] && isset( $ajax['has_more'], $ajax['count'] ), 'The actual public AJAX route accepts a page and returns pagination metadata' );
$request->set_param( 'page', 0 );
day_check( 400 === rest_do_request( $request )->get_status(), 'Invalid page numbers are rejected by the REST schema' );
$request->set_param( 'page', 1 );
$request->set_param( 'watch_url', array( 'unexpected' ) );
day_check( 400 === rest_do_request( $request )->get_status(), 'Malformed public rendering parameters fail schema validation' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-day' );
day_check( $widget && array( 'bsc-tv-day' ) === $widget->get_script_depends() && array( 'bsc-tv-day' ) === $widget->get_style_depends(), 'Elementor registers the supplemental daily-list widget with independent assets' );
$controls = $widget->get_stack( false );
$controls = $controls['controls'] + ( $controls['style_controls'] ?? array() );
day_check( isset( $controls['show_past'], $controls['show_images'], $controls['watch_url'], $controls['day_color_current'], $controls['day_title_typography_font_size'] ) && ( ! empty( $controls['day_image_width']['is_responsive'] ) || isset( $controls['day_image_width_mobile'] ) ), 'Content, typography, current-programme color and responsive thumbnail controls are available' );
$page = day_post( 'page', 'Programme du jour Elementor', array() );
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'd100001', 'elType' => 'widget', 'widgetType' => 'bsc-tv-day', 'settings' => array( 'day_color_current' => '#345678', 'day_image_width' => array( 'size' => 124, 'unit' => 'px' ), 'day_image_width_mobile' => array( 'size' => 48, 'unit' => 'px' ) ), 'elements' => array() ) ) ) ) );
$css = ( new Elementor\Core\Files\CSS\Post( $page ) )->get_content();
day_check( false !== strpos( $css, '--bsc-day-current:#345678;' ) && false !== strpos( $css, '--bsc-day-image-width:124px;' ) && preg_match( '/@media[^{}]+max-width:[^{}]+\{[^@]*--bsc-day-image-width:48px;/s', $css ), 'Saved Elementor styles include the selected color and distinct mobile thumbnail width' );
day_check( false !== strpos( do_shortcode( '[bsc_tv_day show_past="no"]' ), 'data-bsc-day=' ), 'The daily list also works through its shortcode' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => false ) );
$disabled = BSC_TV_Day::programme( BSC_TV_Day::config( array() ) );
day_check( false !== strpos( $disabled['html'], 'Le lecteur est désactivé.' ) && false === strpos( $disabled['html'], 'data-day-key=' ), 'A disabled antenna does not expose an administration-only prepared programme' );
day_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Rendering, Elementor and refresh perform no external API calls and create no import cron: ' . implode( ', ', $request_hosts ) );
echo "OK {$checks} daily-list WordPress/Elementor checks.\n";
