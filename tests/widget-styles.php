<?php
/** php tests/widget-styles.php /path/to/disposable-wordpress */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$database_dir = sys_get_temp_dir() . '/bsc-tv-styles-' . bin2hex( random_bytes( 6 ) );
mkdir( $database_dir );
$database = $database_dir . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Cannot copy disposable database.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
set_transient( Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );
$requests = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_http', 'Styles must not request platform data.' ); } );
$checks = 0;
function style_check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
$dimensions = static function ( $value ) { return array( 'top' => $value, 'right' => $value, 'bottom' => $value, 'left' => $value, 'unit' => 'px', 'isLinked' => true ); };
$player_settings = array(
	'start_mode' => 'click', 'transition_enabled' => '', 'color_surface' => '#172539',
	'player_border_border' => 'solid', 'player_border_width' => $dimensions( 2 ), 'player_border_color' => '#52789a',
	'player_buttons_normal_color' => '#ffffff', 'player_buttons_normal_background' => '#27415e',
	'player_buttons_border_border' => 'solid', 'player_buttons_border_width' => $dimensions( 2 ), 'player_buttons_border_color' => '#8bc8ee',
	'player_buttons_hover_background' => '#436c97', 'player_buttons_hover_color' => '#ffe599', 'player_buttons_hover_border' => '#ffcc33',
	'player_buttons_active_background' => '#6154a4', 'player_buttons_active_color' => '#f5ddff',
	'player_buttons_disabled_background' => '#313947', 'player_buttons_disabled_color' => '#91a0b6',
	'player_buttons_size' => array( 'size' => 48, 'unit' => 'px' ), 'player_buttons_icon_size' => array( 'size' => 24, 'unit' => 'px' ),
	'player_buttons_size_mobile' => array( 'size' => 40, 'unit' => 'px' ), 'player_buttons_icon_size_mobile' => array( 'size' => 18, 'unit' => 'px' ),
	'player_buttons_radius' => $dimensions( 12 ), 'player_buttons_disabled_opacity' => array( 'size' => .8 ),
	'player_button_pause_normal_color' => '#ffcc33', 'player_button_pause_normal_background' => '#483d1a',
	'player_button_pause_hover_color' => '#ffffff', 'player_button_pause_hover_background' => '#90651c',
	'toolbar_background' => '#101b2b', 'toolbar_padding' => $dimensions( 8 ), 'controls_gap' => array( 'size' => 8, 'unit' => 'px' ),
	'volume_color' => '#ffcc33', 'volume_width_mobile' => array( 'size' => 64, 'unit' => 'px' ),
	'player_source_normal_color' => '#a9d8fa', 'player_source_normal_background' => '#203349',
	'player_source_hover_color' => '#ffffff', 'player_source_hover_background' => '#375675',
	'player_source_radius' => $dimensions( 8 ), 'player_source_padding' => $dimensions( 5 ),
	'provider_size' => array( 'size' => 24, 'unit' => 'px' ), 'provider_youtube_color' => '#ff6688',
	'progress_color' => '#ffcc33', 'progress_background' => '#476079', 'progress_height' => array( 'size' => 8, 'unit' => 'px' ),
);
$day_settings = array(
	'day_color_surface' => '#182a25', 'day_color_row' => '#233e36', 'day_color_current' => '#364c29',
	'day_border_border' => 'solid', 'day_border_width' => $dimensions( 2 ), 'day_border_color' => '#598c74',
	'day_row_border_border' => 'solid', 'day_row_border_width' => $dimensions( 2 ), 'day_row_border_color' => '#598c74',
	'day_current_border_border' => 'solid', 'day_current_border_width' => $dimensions( 3 ), 'day_current_border_color' => '#e1ba62',
	'day_row_hover_background' => '#395b4e', 'day_row_hover_border' => '#a8dfbe',
	'day_more_button_normal_background' => '#d9b958', 'day_more_button_normal_color' => '#1d281c',
	'day_more_button_hover_background' => '#e8d793', 'day_more_button_hover_color' => '#132319', 'day_more_button_hover_border' => '#ffffff',
	'day_more_button_active_background' => '#8caa69', 'day_more_button_disabled_background' => '#586e61', 'day_more_button_disabled_color' => '#c6d4cc',
	'day_more_button_border_border' => 'solid', 'day_more_button_border_width' => $dimensions( 2 ), 'day_more_button_border_color' => '#eedb9b',
	'day_more_button_padding' => $dimensions( 14 ), 'day_more_button_padding_mobile' => $dimensions( 8 ), 'day_more_button_radius' => $dimensions( 18 ),
	'day_more_align' => 'right', 'day_more_align_mobile' => 'center', 'day_more_width_mobile' => array( 'size' => 100, 'unit' => '%' ),
	'day_more_gap' => array( 'size' => 22, 'unit' => 'px' ),
	'day_image_background' => '#674674', 'day_image_icon_color' => '#ffdd88', 'day_image_icon_size' => array( 'size' => 30, 'unit' => 'px' ), 'day_image_radius' => $dimensions( 12 ),
	'day_link_creator_color' => '#e4d991', 'day_link_creator_hover' => '#ffffff',
	'day_text_times' => '#b6d4d1', 'day_text_time_start' => '#ffe7b2', 'day_times_typography_typography' => 'custom', 'day_times_typography_font_weight' => '500',
	'day_badge_current_background' => '#66531a', 'day_badge_current_color' => '#ffe59c', 'day_badge_padding' => $dimensions( 4 ),
);
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'BSC TV widget styles fixture' ) );
$variants = array( 'p100001' => array( 'bsc-tv-player', $player_settings ), 'p100002' => array( 'bsc-tv-player', array( 'start_mode' => 'click', 'transition_enabled' => '' ) ), 'd100001' => array( 'bsc-tv-day', $day_settings ), 'd100002' => array( 'bsc-tv-day', array() ) );
$elements = array();
foreach ( $variants as $id => $variant ) { $elements[] = array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $variant[0], 'settings' => $variant[1], 'elements' => array() ); }
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
$css = ( new Elementor\Core\Files\CSS\Post( $page ) )->get_content();
style_check( false !== strpos( $css, '--bsc-player-control-color:#ffffff;' ) && false !== strpos( $css, '--bsc-player-control-background:#27415e;' ), 'Elementor emits the saved common button colors' );
style_check( false !== strpos( $css, '[data-player-pause]' ) && false !== strpos( $css, '--bsc-player-control-background:#483d1a;' ), 'A single command can override common styles' );
style_check( false !== strpos( $css, '--bsc-player-control-hover-background:#436c97;' ) && false !== strpos( $css, ':is(:hover, :focus-visible)' ) && false !== strpos( $css, 'border-color:#ffcc33;' ), 'Hover and keyboard focus styles are emitted' );
style_check( false !== strpos( $css, '--bsc-player-control-active-background:#6154a4;' ) && false !== strpos( $css, '--bsc-player-control-disabled-color:#91a0b6;' ) && false !== strpos( $css, 'opacity:0.8;' ), 'Active and disabled states have independent settings' );
style_check( preg_match( '/@media[^{}]+max-width:[^{}]+\{[^@]*--bsc-player-control-size:40px;[^@]*--bsc-player-control-icon-size:18px;/s', $css ), 'Saved mobile button and icon sizes become responsive CSS' );
style_check( false !== strpos( $css, '--bsc-player-source-color:#a9d8fa;' ) && false !== strpos( $css, 'fill:#ff6688;' ), 'The source link and provider logo can be styled independently' );
style_check( false !== strpos( $css, '--bsc-player-progress-color:#ffcc33;' ) && false !== strpos( $css, 'background-color:#101b2b;' ), 'Toolbar and progress styles use saved settings' );
style_check( false !== strpos( $css, '--bsc-day-button-background:#d9b958;' ) && false !== strpos( $css, '--bsc-day-button-hover-background:#e8d793;' ) && false !== strpos( $css, '--bsc-day-button-disabled-background:#586e61;' ), 'The daily AJAX button has separate normal, hover and loading styles' );
style_check( false !== strpos( $css, '.bsc-day__item.bsc-day__item--current' ) && false !== strpos( $css, 'border-color:#e1ba62;' ), 'The current programme contour can override common rows' );
style_check( false !== strpos( $css, 'background-color:#674674;' ) && false !== strpos( $css, 'font-size:30px;' ) && false !== strpos( $css, 'color:#ffdd88;' ), 'Thumbnails and their fallback icon accept separate colors and sizes' );
style_check( false !== strpos( $css, 'background-color:#66531a;' ) && false !== strpos( $css, 'color:#e4d991;' ), 'Badges and BSC BDD creator links have their own colors' );
style_check( preg_match( '/@media[^{}]+max-width:[^{}]+\{[^@]*padding:8px 8px 8px 8px;[^@]*width:100%;/s', $css ), 'Daily button padding, width and placement support mobile overrides' );
style_check( ! preg_match( '/elementor-element-(?:p100002|d100002)[^{}]*\{[^}]*#(?:27415e|d9b958|674674|ffcc33)/i', $css ), 'Custom settings do not leak to unstyled widgets on the same page' );

// Render real programme HTML for isolated browser checks without any platform calls.
foreach ( array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
}
update_option( 'timezone_string', 'Europe/Paris' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0 ) );
$profile = wp_insert_post( array( 'post_type' => BSC_BDD_Content::POST_TYPE, 'post_title' => 'Créateur craft beer', 'post_status' => 'publish' ) );
$channel = wp_insert_post( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_title' => 'Créateur craft beer', 'post_status' => 'publish', 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::channel_data( array( 'url' => 'https://www.twitch.tv/stylebeerqa', 'bdd_id' => $profile ) ) ) ) );
foreach ( array( 'M7lc1UVf-VE' => 'Rencontre avec les brasseurs', 'aqz-KE-bpKQ' => 'Dégustation de bières artisanales' ) as $video => $title ) {
	$show = wp_insert_post( array( 'post_type' => BSC_TV_Content::SHOW, 'post_title' => $title, 'post_status' => BSC_TV_Content::ARCHIVED, 'meta_input' => array( BSC_TV_Content::DATA => BSC_TV_Content::show_data( array( 'url' => 'https://www.youtube.com/watch?v=' . $video, 'channel_id' => $channel, 'starts_at' => gmdate( DATE_ATOM, time() - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( DATE_ATOM, time() - DAY_IN_SECONDS ), 'status' => 'ended' ) ) ) ) );
	update_post_meta( $show, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => time() - DAY_IN_SECONDS ) );
}
delete_option( BSC_TV_Player::STATE );
delete_transient( BSC_TV_Player::CACHE );
$snapshot = BSC_TV_Player::snapshot();
style_check( ! empty( $snapshot['current'] ), 'The style preview has a real local programme' );
$html = '<main class="elementor elementor-' . $page . ' qa-grid">';
foreach ( $variants as $id => $variant ) {
	$html .= '<div class="elementor-element elementor-element-' . $id . '">' . ( 'bsc-tv-player' === $variant[0] ? BSC_TV_Player::render( $variant[1] ) : BSC_TV_Day::render( $variant[1] ) ) . '</div>';
}
$html .= '</main>';
$dir = dirname( __DIR__, 2 ) . '/tv-widget-style-qa';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/elementor.css', $css );
file_put_contents( $dir . '/page.html', $html );
file_put_contents( $dir . '/player.json', wp_json_encode( $snapshot ) );
file_put_contents( $dir . '/day.json', wp_json_encode( BSC_TV_Day::programme( BSC_TV_Day::config( array() ) ) ) );
file_put_contents( $dir . '/day-more.json', wp_json_encode( BSC_TV_Day::programme( BSC_TV_Day::config( array() ), null, 2 ) ) );
style_check( 0 === $requests, 'Generating CSS and rendering both widgets makes no external API calls' );
echo "OK {$checks} Elementor appearance integration checks.\n";
