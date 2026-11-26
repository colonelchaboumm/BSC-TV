<?php
/** php tests/badge-integration.php /path/to/disposable-wordpress-installation */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture_root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! is_file( $fixture_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$database_dir = sys_get_temp_dir() . '/bsc-tv-badge-' . bin2hex( random_bytes( 6 ) );
mkdir( $database_dir );
$database = $database_dir . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture_root . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Cannot copy disposable database.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture_root . '/wp-load.php';
$checks = 0;
function badge_check( $value, $label ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $label ); } }
function badge_post( $type, $title, $data, $status = 'publish' ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'meta_input' => array( BSC_TV_Content::DATA => $data ) ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	return $id;
}
function badge_response( $parameters = array() ) {
	$request = new WP_REST_Request( 'GET', '/bsc-tv/v1/programme' );
	$request->set_query_params( $parameters + array( 'format' => 'badge', 'image' => 1 ) );
	return rest_do_request( $request );
}
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
delete_option( BSC_TV_Player::STATE );
delete_transient( BSC_TV_Player::CACHE );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'live_priority' => true ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => true, 'youtube_key' => 'private-badge-api-value' ) );
update_option( BSC_TV_Player_Daily::KEY, 'private-badge-import-value', false );
set_transient( Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );
$requests = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_http', 'The badge only reads local metadata.' ); } );
$now = time();
$profile = badge_post( BSC_BDD_Content::POST_TYPE, 'Créateur BSC du test', array() );
$channel = badge_post( BSC_TV_Content::CHANNEL, 'Chaîne BSC du test', BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/badgebeerqa', 'bdd_id' => $profile ) ) );
$data = array( 'url' => 'https://youtu.be/M7lc1UVf-VE', 'platform' => 'youtube', 'start' => $now - DAY_IN_SECONDS, 'end' => $now - DAY_IN_SECONDS + 1800, 'status' => 'ended', 'channel_id' => $channel, 'bdd_id' => 0, 'creator' => '', 'image' => 'https://example.com/badge-show.jpg' );
$show = badge_post( BSC_TV_Content::SHOW, 'Programme de l’antenne BSC', $data );
update_post_meta( $show, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 30, 'published_at' => $now - DAY_IN_SECONDS ) );
$show_b = badge_post( BSC_TV_Content::SHOW, 'Programme B de l’antenne', array_replace( $data, array( 'url' => 'https://youtu.be/aqz-KE-bpKQ' ) ) );
update_post_meta( $show_b, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => $now - DAY_IN_SECONDS ) );
$future = badge_post( BSC_TV_Content::SHOW, 'Futur direct de la plateforme', array_replace( $data, array( 'url' => 'https://youtu.be/QA123abcXYZ', 'status' => 'upcoming', 'start' => $now + HOUR_IN_SECONDS, 'end' => $now + 2 * HOUR_IN_SECONDS ) ) );
$pool = BSC_TV_Player::media();
update_option( BSC_TV_Player::STATE, BSC_TV_Player::advance( array(), array( 'youtube:M7lc1UVf-VE' => $pool['youtube:M7lc1UVf-VE'] ), array(), BSC_TV_Player::settings(), $now ), false );
$render_settings = array( 'format' => 'badge', 'image' => 1 );
$before = BSC_TV_Player::snapshot();
$html = BSC_TV_Display::render( $render_settings );
badge_check( false !== strpos( $html, 'data-bsc-tv-state="live"' ) && false === strpos( $html, 'bsc-tv-badge__status' ) && false === strpos( $html, 'bsc-tv-badge__dot' ) && preg_match( '/bsc-tv-badge__title[^>]*>Programme de l’antenne BSC</', $html ), 'The programme trigger has no LIVE label or dot and keeps the current antenna title' );
badge_check( false !== strpos( $html, 'data-bsc-tv-current="youtube:M7lc1UVf-VE"' ) && false !== strpos( $html, 'REDIFFUSION' ), 'The dropdown identifies a current replay without calling it an original live' );
badge_check( false !== strpos( $html, 'https://example.com/badge-show.jpg' ) && false !== strpos( $html, get_permalink( $profile ) ), 'The current programme card retains its image and BSC BDD creator link' );
badge_check( preg_match( '/class="bsc-tv-agenda bsc-tv-watch[^>]+href="https:\/\/brewerssocialclub.com\/le-live\/"[^>]*>Regarder Le live/', $html ), 'The dropdown includes a persistent watch button to the requested live page' );
badge_check( false !== strpos( $html, 'Futur direct de la plateforme' ), 'Platform announcements remain available beneath the current antenna programme' );
badge_check( ! wp_script_is( 'bsc-tv-player', 'enqueued' ) && false === strpos( $html, '<iframe' ) && false === strpos( $html, '<video' ), 'The badge does not load the player, transition media or any video SDK' );
$response = badge_response(); $content = $response->get_data();
badge_check( 200 === $response->get_status() && 'no-store, max-age=0' === $response->get_headers()['Cache-Control'], 'The badge refresh route is publicly readable and uncached' );
badge_check( 'Programme de l’antenne BSC' === $content['highlight']['title'] && 'live' === $content['highlight']['state'] && 1 === $content['live'], 'AJAX uses the same current programme as initial rendering' );
badge_check( $content['poll_after'] >= 5 && $content['poll_after'] <= 31, 'Refresh approaches the current programme transition' );
badge_check( false === strpos( wp_json_encode( $content ), 'private-badge-' ), 'The public badge response exposes no API or scheduler credentials' );
$after = BSC_TV_Player::snapshot();
badge_check( $before['current']['key'] === $after['current']['key'] && $before['current']['start'] === $after['current']['start'] && $before['next'] === $after['next'], 'Viewing and refreshing the badge does not reshuffle the shared clock' );
$custom = BSC_TV_Display::render( $render_settings + array( 'watch_url' => array( 'url' => 'https://example.com/tv/' ), 'watch_label' => 'Voir notre TV' ) );
badge_check( false !== strpos( $custom, 'href="https://example.com/tv/"' ) && false !== strpos( $custom, '>Voir notre TV ' ), 'The watch page and button label are customizable' );
$without_link = BSC_TV_Display::render( $render_settings + array( 'show_watch_link' => '' ) );
badge_check( false === strpos( $without_link, 'bsc-tv-watch' ), 'The additional watch button can be hidden independently' );
$hostile = BSC_TV_Display::render( $render_settings + array( 'watch_url' => 'javascript:alert(1)', 'watch_label' => '<script>alert(1)</script>Voir' ) );
badge_check( false === strpos( $hostile, 'javascript:' ) && false === strpos( $hostile, '<script' ) && false !== strpos( $hostile, BSC_TV_Display::LIVE_PAGE ), 'Custom watch URLs and labels cannot inject script markup' );
badge_check( 400 === badge_response( array( 'watch_url' => array( 'invalid' ) ) )->get_status(), 'Malformed public watch parameters fail schema validation' );
$platform_only = badge_response( array( 'player' => 0 ) )->get_data();
badge_check( 'upcoming' === $platform_only['highlight']['state'] && false === strpos( $platform_only['html'], 'data-bsc-tv-current' ), 'The original platform-only badge mode remains available' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-badge' );
$stack = $widget->get_stack( false ); $controls = $stack['controls'] + ( $stack['style_controls'] ?? array() );
badge_check( 'yes' === $controls['show_player_current']['default'] && 'yes' === $controls['show_watch_link']['default'] && BSC_TV_Display::LIVE_PAGE === $controls['watch_url']['default']['url'] && isset( $controls['badge_watch_typography_font_size'] ), 'Elementor provides enabled defaults, the watch destination and button typography' );
badge_check( false !== strpos( do_shortcode( '[bsc_tv format="badge" watch_label="Voir BSC"]' ), '>Voir BSC ' ), 'The badge shortcode forwards the watch button setting' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => false ) );
$disabled = badge_response()->get_data();
badge_check( 'upcoming' === $disabled['highlight']['state'] && false === strpos( $disabled['html'], 'data-bsc-tv-current' ), 'A disabled antenna removes its current title and falls back to platform announcements' );
$live_event = array( 'id' => 'badge-live', 'title' => 'Vrai direct du créateur', 'url' => 'https://www.twitch.tv/badgebeerqa', 'start' => time() - 60, 'end' => 0, 'image' => 'https://example.com/badge-live.jpg' );
update_post_meta( $channel, BSC_TV_Content::STATE, array( 'key' => 'badgebeerqa', 'live_at' => time(), 'live' => array( $live_event ) ) );
$fallback_live = badge_response()->get_data();
badge_check( 'live' === $fallback_live['highlight']['state'] && 'Vrai direct du créateur' === $fallback_live['highlight']['title'], 'Existing confirmed platform lives remain a fallback when the antenna is disabled' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'live_priority' => true ) );
$true_live = badge_response()->get_data();
badge_check( 1 === $true_live['live'] && 1 === substr_count( $true_live['html'], 'Vrai direct du créateur' ) && false !== strpos( $true_live['html'], '>DIRECT</span>' ), 'A true live on the antenna appears once with the correct status' );
update_post_meta( $channel, BSC_TV_Content::STATE, array( 'key' => 'badgebeerqa', 'live_at' => time(), 'live' => array() ) );
wp_update_post( array( 'ID' => $show, 'post_status' => 'draft' ) );
$withdrawn = badge_response()->get_data();
badge_check( 'Programme de l’antenne BSC' !== $withdrawn['highlight']['title'] && false === strpos( $withdrawn['html'], 'Programme de l’antenne BSC' ), 'A withdrawn current source disappears immediately from the public badge' );
delete_option( BSC_TV_Player::STATE );
$changed = badge_response()->get_data();
badge_check( 'Programme B de l’antenne' === $changed['highlight']['title'] && false === strpos( $changed['html'], 'Programme de l’antenne BSC' ), 'A withdrawn current source disappears and the next shared programme is highlighted' );
wp_update_post( array( 'ID' => $show_b, 'post_status' => 'draft' ) );
$empty = badge_response()->get_data();
badge_check( 'upcoming' === $empty['highlight']['state'] && false === strpos( $empty['html'], 'data-bsc-tv-current' ), 'No stale antenna title survives an empty eligible catalogue' );
badge_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'The complete badge workflow makes no platform API calls and adds no import job' );
echo "OK {$checks} WordPress/Elementor badge checks.\n";
