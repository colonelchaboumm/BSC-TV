<?php
/**
 * Plugin Name: BSC TV
 * Description: Programme des directs craft beer, fiches BSC BDD et widgets Elementor.
 * Version: 1.27.4
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: BSC
 * Text Domain: bsc-tv
 */

defined( 'ABSPATH' ) || exit;

define( 'BSC_TV_VERSION', '1.27.4' );
define( 'BSC_TV_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSC_TV_URL', plugin_dir_url( __FILE__ ) );

require_once BSC_TV_DIR . 'includes/content.php';
require_once BSC_TV_DIR . 'includes/library.php';
require_once BSC_TV_DIR . 'includes/feeds.php';
require_once BSC_TV_DIR . 'includes/sync.php';
require_once BSC_TV_DIR . 'includes/display.php';
require_once BSC_TV_DIR . 'includes/guide.php';
require_once BSC_TV_DIR . 'includes/stations.php';
BSC_TV_Stations::register();
require_once BSC_TV_DIR . 'includes/player.php';
require_once BSC_TV_DIR . 'includes/music.php';
BSC_TV_Music::register();
require_once BSC_TV_DIR . 'includes/player-schedule.php';
BSC_TV_Player_Schedule::register();
require_once BSC_TV_DIR . 'includes/player-import.php';
BSC_TV_Player_Import::register();
require_once BSC_TV_DIR . 'includes/player-daily.php';
BSC_TV_Player_Daily::register();
require_once BSC_TV_DIR . 'includes/server.php';
BSC_TV_Server::register();
require_once BSC_TV_DIR . 'includes/day.php';
add_action( 'init', array( 'BSC_TV_Day', 'register' ) );

add_action( 'init', array( 'BSC_TV_Content', 'register' ) );
add_action( 'init', array( 'BSC_TV_Display', 'register' ) );
add_action( 'init', array( 'BSC_TV_Player', 'register' ) );
add_action( 'rest_api_init', array( 'BSC_TV_Display', 'rest' ) );
add_filter( 'cron_schedules', array( 'BSC_TV_Sync', 'schedules' ) );
add_action( 'init', array( 'BSC_TV_Sync', 'upgrade' ) );
add_action( BSC_TV_Sync::DAILY, array( 'BSC_TV_Sync', 'daily' ) );
add_action( BSC_TV_Sync::CONTINUE_DAILY, array( 'BSC_TV_Sync', 'continue_daily' ) );
add_action( BSC_TV_Sync::LIVE, array( 'BSC_TV_Sync', 'live' ) );
add_action( BSC_TV_Sync::DAILY_NOW, array( 'BSC_TV_Sync', 'daily' ) );
add_action( BSC_TV_Sync::LIVE_NOW, array( 'BSC_TV_Sync', 'live' ) );
register_activation_hook( __FILE__, array( 'BSC_TV_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BSC_TV_Sync', 'deactivate' ) );
register_activation_hook( __FILE__, array( 'BSC_TV_Player_Schedule', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'BSC_TV_Player_Schedule', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'BSC_TV_Player_Import', 'deactivate' ) );

add_action( 'elementor/widgets/register', static function ( $manager ) {
	require_once BSC_TV_DIR . 'includes/widget-style-controls.php';
	require_once BSC_TV_DIR . 'includes/elementor.php';
	require_once BSC_TV_DIR . 'includes/player-widget.php';
	require_once BSC_TV_DIR . 'includes/day-widget.php';
	$manager->register( new BSC_TV_Programme_Widget() );
	$manager->register( new BSC_TV_Menu_Widget() );
	$manager->register( new BSC_TV_Badge_Widget() );
	$manager->register( new BSC_TV_Guide_Widget() );
	$manager->register( new BSC_TV_Player_Widget() );
	$manager->register( new BSC_TV_Day_Widget() );
} );

if ( is_admin() ) {
	require_once BSC_TV_DIR . 'includes/admin.php';
	BSC_TV_Admin::register();
	require_once BSC_TV_DIR . 'includes/player-admin.php';
	BSC_TV_Player_Admin::register();
	require_once BSC_TV_DIR . 'includes/music-admin.php';
	BSC_TV_Music_Admin::register();
}
