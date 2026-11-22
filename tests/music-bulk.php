<?php
/** php tests/music-bulk.php /path/to/disposable-wordpress */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-bulk-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
BSC_TV_Music_Admin::register();
$checks = 0;
function bulk_check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
foreach ( get_posts( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
delete_option( BSC_TV_Player::STATE ); delete_option( BSC_TV_Music::SETTINGS ); BSC_TV_Music::invalidate();
$requests = 0;
add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_http', 'No external calls in a bulk validation.' ); } );
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $users[0]->ID ); $admin = get_current_user_id();
$ids = array();
for ( $i = 0; $i < 31; ++$i ) {
	$video = str_pad( (string) $i, 11, '0', STR_PAD_LEFT );
	$data = array( 'youtube_id' => $video, 'url' => 'https://www.youtube.com/watch?v=' . $video, 'artist' => 'Fixture artist ' . $i, 'title' => 'Fixture clip ' . $i, 'categories' => array( 30 === $i ? 'night' : 'day' ), 'duration' => 240, 'active' => false, 'official' => false, 'rights' => 'pending', 'rights_note' => '', 'availability' => 'available' );
	$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => $data['title'] ) ); update_post_meta( $id, BSC_TV_Music::META, $data ); $ids[] = $id;
}
$read = static function ( $id ) { return get_post_meta( $id, BSC_TV_Music::META, true ); };
$input = array( 'operation' => 'approve', 'scope' => 'selected', 'category' => '', 'ids' => array( $ids[0], $ids[1] ), 'confirm_rights' => 1, 'confirm_official' => 1, 'rights_note' => 'Fixture only: "BSC" \\ dossier, aucune autorisation réelle.', 'activate' => 1 );
$before = $read( $ids[0] );
wp_set_current_user( 0 ); bulk_check( is_wp_error( BSC_TV_Music_Admin::bulk_apply( $input ) ), 'Anonymous bulk requests are rejected' ); wp_set_current_user( $admin );
foreach ( array( array( 'confirm_rights' => 0 ), array( 'confirm_official' => 0 ), array( 'rights_note' => '' ), array( 'rights_note' => str_repeat( 'é', 4001 ) ), array( 'operation' => 'delete' ), array( 'scope' => 'unexpected' ), array( 'category' => 'unknown' ), array( 'category' => array( 'night' ) ), array( 'ids' => array() ), array( 'confirm_rights' => array( 1 ) ) ) as $invalid ) { bulk_check( is_wp_error( BSC_TV_Music_Admin::bulk_apply( array_replace( $input, $invalid ) ) ), 'Invalid or unconfirmed batch cannot mutate clips' ); }
bulk_check( $before === $read( $ids[0] ), 'Rejected requests leave metadata unchanged' );
$report = BSC_TV_Music_Admin::bulk_apply( $input );
bulk_check( 2 === $report['updated'] && 2 === $report['ready'] && 2 === $report['targets'], 'Only selected clips are approved and activated' );
bulk_check( BSC_TV_Music::approved( $read( $ids[0] ) ) && 'pending' === $read( $ids[2] )['rights'], 'Unselected clips do not inherit approval' );
bulk_check( $input['rights_note'] === $read( $ids[0] )['rights_note'], 'Common notes preserve literal quotes and backslashes' );
$repeat = BSC_TV_Music_Admin::bulk_apply( $input );
bulk_check( 0 === $repeat['updated'] && 2 === $repeat['unchanged'] && $input['rights_note'] === $read( $ids[0] )['rights_note'], 'Repeated validation does not append duplicate notes or change clips' );
$replacement = array_replace( $input, array( 'ids' => array( $ids[0] ), 'rights_note' => 'Other batch reference' ) ); BSC_TV_Music_Admin::bulk_apply( $replacement );
bulk_check( $input['rights_note'] === $read( $ids[0] )['rights_note'], 'An existing confirmed authorization note is preserved' );
$validation_only = array_replace( $input, array( 'ids' => array( $ids[2] ), 'activate' => 0 ) ); BSC_TV_Music_Admin::bulk_apply( $validation_only );
bulk_check( 'confirmed' === $read( $ids[2] )['rights'] && ! $read( $ids[2] )['active'], 'Approval can be stored without activating the clip' );
$activation = BSC_TV_Music_Admin::bulk_apply( array_replace( $input, array( 'operation' => 'activate', 'ids' => array( $ids[2], $ids[3] ), 'confirm_rights' => 0, 'confirm_official' => 0, 'rights_note' => '' ) ) );
bulk_check( 1 === $activation['updated'] && 1 === $activation['skipped'] && ! $read( $ids[3] )['active'], 'Activation cannot bypass missing rights or official-source confirmation' );
$invalid_rows = array( 'availability' => array( 'availability' => 'blocked' ), 'unchecked' => array( 'availability' => 'unchecked' ), 'duration' => array( 'duration' => 0 ), 'metadata' => array( 'artist' => '' ), 'category' => array( 'categories' => array() ), 'url' => array( 'url' => 'https://www.youtube.com/watch?v=ZZZZZZZZZZZ' ) );
$blocked = array(); $index = 4;
foreach ( $invalid_rows as $name => $changes ) { $id = $ids[ $index++ ]; $blocked[ $id ] = array_replace( $read( $id ), $changes ); update_post_meta( $id, BSC_TV_Music::META, $blocked[ $id ] ); }
BSC_TV_Music::clips(); // Prime the cache; the locked operation must still read fresh metadata.
$other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Not a music clip' ) );
$blocked_report = BSC_TV_Music_Admin::bulk_apply( array_replace( $input, array( 'ids' => array_merge( array_keys( $blocked ), array( $other, $ids[4], 'bad', array( 1 ) ) ) ) ) );
bulk_check( 6 === $blocked_report['targets'] && 6 === $blocked_report['skipped'] && 0 === $blocked_report['updated'], 'Blocked, unknown, incomplete and inconsistent clips are skipped with deduplicated IDs' );
foreach ( $blocked as $id => $original ) { bulk_check( $original === $read( $id ), 'Skipped clips keep their restrictions and approval metadata' ); }
bulk_check( ! get_post_meta( $other, BSC_TV_Music::META, true ), 'A non-music post cannot be changed by forged IDs' );
$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
$busy_before = $read( $ids[10] ); $busy = BSC_TV_Music_Admin::bulk_apply( array_replace( $input, array( 'ids' => array( $ids[10] ) ) ) );
BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock );
bulk_check( is_wp_error( $busy ) && $busy_before === $read( $ids[10] ), 'A busy antenna lock blocks the whole batch before any clip mutation' );
$night = BSC_TV_Music_Admin::bulk_apply( array_replace( $input, array( 'scope' => 'all', 'category' => 'night', 'ids' => array() ) ) );
bulk_check( 1 === $night['targets'] && BSC_TV_Music::approved( $read( $ids[30] ) ) && 'pending' === $read( $ids[29] )['rights'], 'Category filtering applies across the library without touching other categories' );
$all = BSC_TV_Music_Admin::bulk_apply( array_replace( $input, array( 'scope' => 'all', 'ids' => array() ) ) );
bulk_check( 31 === $all['targets'] && 6 === $all['skipped'] && BSC_TV_Music::approved( $read( $ids[29] ) ), 'Whole-library validation reaches clips beyond the first 25-row page' );
bulk_check( ! BSC_TV_Music::settings()['enabled'], 'Approval never switches the music module on implicitly' );
$deactivated = BSC_TV_Music_Admin::bulk_apply( array( 'operation' => 'deactivate', 'scope' => 'all' ) );
bulk_check( 0 === $deactivated['ready'] && ! $read( $ids[29] )['active'] && 'confirmed' === $read( $ids[29] )['rights'], 'Mass deactivation preserves the clips and their authorization records' );
add_filter( 'wp_die_handler', static function () { return static function () { throw new RuntimeException( 'bulk_die' ); }; } );
$_REQUEST = array();
try { BSC_TV_Music_Admin::bulk(); bulk_check( false, 'Nonce must be required' ); } catch ( RuntimeException $error ) { bulk_check( 'bulk_die' === $error->getMessage(), 'The actual bulk endpoint rejects a missing nonce' ); }
add_filter( 'wp_redirect', static function () { throw new RuntimeException( 'bulk_redirect' ); } );
$_POST = array( 'bulk' => wp_slash( $input ), '_wpnonce' => wp_create_nonce( 'bsc_tv_music_bulk' ) ); $_REQUEST = $_POST;
try { BSC_TV_Music_Admin::bulk(); } catch ( RuntimeException $error ) { if ( 'bulk_redirect' !== $error->getMessage() ) { throw $error; } }
$notice = get_transient( 'bsc_tv_music_notice_' . $admin );
bulk_check( BSC_TV_Music::approved( $read( $ids[0] ) ) && false !== strpos( $notice[0], 'Action groupée terminée' ), 'A valid admin POST applies the batch and returns a summary' );
bulk_check( false === strpos( $notice[0], $input['rights_note'] ), 'The result summary does not echo private authorization notes' );
ob_start(); BSC_TV_Music_Admin::page(); $html = ob_get_clean();
bulk_check( 25 === substr_count( $html, 'data-music-selection' ) && false !== strpos( $html, 'form="bsc-music-bulk"' ) && false !== strpos( $html, 'name="bulk[confirm_rights]"' ), 'The library exposes a single bulk form with 25 associated row checkboxes and explicit confirmation' );
bulk_check( false !== strpos( $html, 'Toute la bibliothèque, toutes les pages' ) && has_action( 'admin_post_bsc_tv_music_bulk' ), 'Whole-library selection and the authenticated action are registered' );
$qa = dirname( __DIR__, 2 ) . '/tv-music-bulk-qa'; wp_mkdir_p( $qa ); file_put_contents( $qa . '/admin.html', $html );
bulk_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Bulk validation makes no platform API requests and adds no import cron' );
echo "OK {$checks} bulk music validation checks.\n";
