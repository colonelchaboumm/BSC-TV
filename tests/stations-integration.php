<?php
/** php tests/stations-integration.php /path/to/disposable-wordpress */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-stations-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/player-admin.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0; $requests = array();
function station_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function station_post( $type, $title, $data, $status = 'publish' ) { return wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'meta_input' => array( BSC_TV_Content::DATA => $data ) ) ); }
function in_fr( $callback ) { return BSC_TV_Stations::run( 'fr', $callback ); }
foreach ( get_posts( array( 'post_type' => array( BSC_TV_Music::POST, BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ), 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
foreach ( BSC_TV_Stations::choices() as $station => $label ) { BSC_TV_Stations::run( $station, static function () { foreach ( array( BSC_TV_Player::STATE, BSC_TV_Player::SETTINGS, BSC_TV_Music::SETTINGS ) as $option ) { delete_option( BSC_TV_Stations::key( $option ) ); } BSC_TV_Music::invalidate(); } ); }
delete_option( 'bsc_tv_station_sources_fr' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $admins[0]->ID );
station_check( 'bsc_tv_player_clock' === BSC_TV_Stations::key( BSC_TV_Player::STATE ), 'Legacy antenna retains the exact storage key' );
station_check( 'bsc_tv_player_clock_station_fr' === in_fr( static function () { return BSC_TV_Stations::key( BSC_TV_Player::STATE ); } ), 'French antenna has an independent clock key' );
station_check( ! in_fr( static function () { return BSC_TV_Player::settings()['enabled']; } ), 'Second antenna waits for explicit activation' );
station_check( ! in_fr( static function () { return BSC_TV_Music::settings()['enabled']; } ), 'Second music library starts disabled and empty' );
foreach ( array( 'FR', 'fr-FR', 'fr_CA', 'Français', 'French', 'fra' ) as $language ) { station_check( BSC_TV_Stations::french( $language ), 'Recognize declared French: ' . $language ); }
foreach ( array( '', 'en', 'France', 'en-fr', 'fr,en', array( 'fr' ) ) as $language ) { station_check( ! BSC_TV_Stations::french( $language ), 'Do not infer French from ambiguous or malformed declarations' ); }
try { in_fr( static function () { throw new RuntimeException( 'fixture' ); } ); } catch ( RuntimeException $error ) {}
station_check( 'main' === BSC_TV_Stations::current(), 'Exceptions restore the enclosing antenna context' );
station_check( is_wp_error( BSC_TV_Stations::run( '../../other', static function () { return true; } ) ), 'Unknown station cannot select arbitrary storage' );
$fr1 = station_post( BSC_TV_Content::CHANNEL, 'Source francophone A', array( 'platform' => 'youtube', 'url' => 'https://www.youtube.com/@frfixturea', 'language' => 'fr-FR', 'bdd_id' => 0 ) );
$fr2 = station_post( BSC_TV_Content::CHANNEL, 'Source francophone B', array( 'platform' => 'youtube', 'url' => 'https://www.youtube.com/@frfixtureb', 'language' => 'Français', 'bdd_id' => 0 ) );
$en = station_post( BSC_TV_Content::CHANNEL, 'English source', array( 'platform' => 'youtube', 'url' => 'https://www.youtube.com/@enfixture', 'language' => 'en', 'bdd_id' => 0 ) );
$unknown = station_post( BSC_TV_Content::CHANNEL, 'Langue inconnue', array( 'platform' => 'youtube', 'url' => 'https://www.youtube.com/@unknownfixture', 'country' => 'France', 'bdd_id' => 0 ) );
$now = time(); $shows = array();
foreach ( array( array( $fr1, '' ), array( $fr1, 'fr' ), array( $fr2, '' ), array( $fr2, 'fr_CA' ), array( $fr1, 'en' ), array( $en, 'en' ), array( $unknown, '' ) ) as $i => $row ) {
	$id = station_post( BSC_TV_Content::SHOW, 'Programme fixture ' . $i, array( 'channel_id' => $row[0], 'platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=showfix00' . sprintf( '%02d', $i ), 'language' => $row[1], 'status' => 'ended', 'bdd_id' => 0, 'creator' => '' ) );
	update_post_meta( $id, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 3000 + $i * 60, 'published_at' => $now - DAY_IN_SECONDS ) ); $shows[] = $id;
}
BSC_TV_Stations::invalidate_catalogues();
station_check( 7 === count( BSC_TV_Player::media() ), 'International station keeps all existing eligible programmes' );
$frpool = in_fr( array( 'BSC_TV_Player', 'media' ) );
station_check( array_slice( $shows, 0, 4 ) === array_values( array_intersect( $shows, array_column( $frpool, 'show_id' ) ) ), 'French station includes only declared French sources and excludes English episodes' );
station_check( ! in_fr( static function () use ( $unknown ) { return BSC_TV_Stations::allows_channel( $unknown, array( 'country' => 'France' ) ); } ), 'A French country never substitutes for language' );
update_option( 'bsc_tv_station_sources_fr', array( 'ids' => array( $fr2 ) ), false ); BSC_TV_Stations::invalidate_catalogues();
station_check( 2 === count( in_fr( array( 'BSC_TV_Player', 'media' ) ) ) && 7 === count( BSC_TV_Player::media() ), 'Manual French selection cannot filter the international pool' );
update_option( 'bsc_tv_station_sources_fr', array( 'ids' => array() ), false ); BSC_TV_Stations::invalidate_catalogues();
station_check( ! in_fr( array( 'BSC_TV_Player', 'media' ) ), 'An intentionally empty French selection never falls back to international programmes' );
delete_option( 'bsc_tv_station_sources_fr' ); BSC_TV_Stations::invalidate_catalogues();
$main_config = array( 'enabled' => true, 'live_priority' => true, 'recent' => 'strong', 'repeat_hours' => 0 );
update_option( BSC_TV_Player::SETTINGS, $main_config, false );
in_fr( static function () { update_option( BSC_TV_Stations::key( BSC_TV_Player::SETTINGS ), array( 'enabled' => true, 'live_priority' => true, 'recent' => 'rotation', 'repeat_hours' => 0 ), false ); } );
$main = BSC_TV_Player::snapshot( $now ); $fr = BSC_TV_Player::snapshot( $now, 'fr' );
station_check( 'main' === $main['station'] && 'fr' === $fr['station'], 'Snapshots identify the requested antenna' );
station_check( in_array( $fr['current']['show_id'], array_slice( $shows, 0, 4 ), true ), 'French clock starts on a French replay' );
$main_clock = get_option( BSC_TV_Player::STATE ); $fr_clock = in_fr( static function () { return get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) ); } );
station_check( $main_clock['rotation_seed'] !== $fr_clock['rotation_seed'], 'Independent seeds give each station its own random rotation' );
station_check( 'strong' === BSC_TV_Player::settings()['recent'] && 'rotation' === in_fr( static function () { return BSC_TV_Player::settings()['recent']; } ), 'Player settings remain independent' );
$slot = $fr_clock['plan'][0];
$edited = in_fr( static function () use ( $slot, $fr_clock ) { return BSC_TV_Player_Schedule::update( 'replace', $slot['id'], $slot['show_id'] === $GLOBALS['shows'][0] ? $GLOBALS['shows'][1] : $GLOBALS['shows'][0], BSC_TV_Player_Schedule::fingerprint( $fr_clock ) ); } );
station_check( ! is_wp_error( $edited ), 'French future programmes can be replaced' );
station_check( $main_clock === get_option( BSC_TV_Player::STATE ), 'Replacing a French slot does not modify the first clock' );
$fr_clock = in_fr( static function () { return get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) ); } );
$slot = $fr_clock['plan'][0];
$rejected = in_fr( static function () use ( $slot, $fr_clock, $shows ) { return BSC_TV_Player_Schedule::update( 'replace', $slot['id'], $shows[5], BSC_TV_Player_Schedule::fingerprint( $fr_clock ) ); } );
station_check( is_wp_error( $rejected ), 'An English replay cannot be inserted through a French schedule action' );
$fr_lock = in_fr( static function () { return BSC_TV_Content::lock( BSC_TV_Stations::key( BSC_TV_Player::LOCK ) ); } );
$main_lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
station_check( $fr_lock && $main_lock, 'Each antenna can lock its own clock independently' );
BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $main_lock ); in_fr( static function () use ( $fr_lock ) { BSC_TV_Content::unlock( BSC_TV_Stations::key( BSC_TV_Player::LOCK ), $fr_lock ); } );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => true ), false );
$fr_live = array( 'id' => 'livefr00001', 'url' => 'https://www.youtube.com/watch?v=livefr00001', 'title' => 'Direct français', 'status' => 'live', 'start' => $now - 120, 'end' => $now + 60, 'language' => 'fr', 'image' => '' );
$en_live = array_replace( $fr_live, array( 'id' => 'liveen00001', 'url' => 'https://www.youtube.com/watch?v=liveen00001', 'title' => 'English live', 'language' => 'en' ) );
update_post_meta( $fr1, BSC_TV_Content::STATE, array( 'key' => '@frfixturea', 'live_at' => $now, 'live' => array( $fr_live ) ) );
update_post_meta( $en, BSC_TV_Player::CHANNEL, array( 'priority' => 100 ) );
update_post_meta( $en, BSC_TV_Content::STATE, array( 'key' => '@enfixture', 'live_at' => $now, 'live' => array( $en_live ) ) );
$on_main = BSC_TV_Player::snapshot( $now ); $on_fr = BSC_TV_Player::snapshot( $now, 'fr' );
station_check( 'English live' === $on_main['current']['title'] && 'Direct français' === $on_fr['current']['title'], 'Simultaneous live streams are prioritized inside each station eligible sources' );
station_check( $on_fr['current']['live'] && 0 === $on_fr['current']['end'], 'A French live retains priority beyond its estimated duration' );
$main_on_air = get_option( BSC_TV_Player::STATE );
update_post_meta( $fr1, BSC_TV_Content::STATE, array( 'key' => '@frfixturea', 'live_at' => $now + 2, 'live' => array() ) );
$resumed_fr = BSC_TV_Player::snapshot( $now + 2, 'fr' );
station_check( ! $resumed_fr['current']['live'] && $resumed_fr['current']['start'] === $fr['current']['start'] && 2 === $resumed_fr['current']['offset'], 'After live the French station rejoins its own original programme at the wall-clock offset' );
station_check( $main_on_air === get_option( BSC_TV_Player::STATE ), 'French live completion cannot rewrite channel-one live state or grid' );
update_post_meta( $en, BSC_TV_Content::STATE, array( 'key' => '@enfixture', 'live_at' => $now + 2, 'live' => array() ) );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => false ), false );
foreach ( array( 'main', 'fr' ) as $station ) {
	BSC_TV_Stations::run( $station, static function () use ( $station ) {
		for ( $i = 0; $i < 2; ++$i ) {
			$clip = array( 'artist' => $station . ' artist ' . $i, 'title' => 'Clip ' . $i, 'youtube_id' => 'musicfix0' . sprintf( '%02d', $i ), 'url' => 'https://www.youtube.com/watch?v=musicfix0' . sprintf( '%02d', $i ), 'duration' => 240, 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'region' => 'fr', 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Disposable test fixture only.', 'availability' => 'available' );
			if ( 'fr' === $station ) { $clip['station'] = 'fr'; $clip['year'] = 1995; }
			wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => $clip['artist'], 'meta_input' => array( BSC_TV_Music::META => $clip ) ) );
		}
		BSC_TV_Music::invalidate();
		update_option( BSC_TV_Stations::key( BSC_TV_Music::SETTINGS ), BSC_TV_Music::normalize( array( 'enabled' => true, 'flexible' => true ) ), false );
	} );
}
station_check( 2 === count( BSC_TV_Music::clips() ) && 2 === count( in_fr( array( 'BSC_TV_Music', 'clips' ) ) ), 'Legacy clips belong only to channel one; French clips use their own library' );
station_check( ! array_intersect( array_keys( BSC_TV_Music::clips() ), array_keys( in_fr( array( 'BSC_TV_Music', 'clips' ) ) ) ), 'Identical video URLs can have independent records, categories and approvals' );
$main_music = get_option( BSC_TV_Music::SETTINGS );
in_fr( static function () { $config = BSC_TV_Music::settings(); $config['enabled'] = false; update_option( BSC_TV_Stations::key( BSC_TV_Music::SETTINGS ), $config, false ); } );
station_check( $main_music === get_option( BSC_TV_Music::SETTINGS ) && BSC_TV_Music::settings()['enabled'], 'Disabling French music preserves the first music settings' );
$other_ids = array_keys( BSC_TV_Music::clips() );
$bulk = in_fr( static function () use ( $other_ids ) { return BSC_TV_Music_Admin::bulk_apply( array( 'scope' => 'selected', 'ids' => $other_ids, 'operation' => 'deactivate' ) ); } );
station_check( is_wp_error( $bulk ) || 0 === $bulk['targets'], 'French bulk validation cannot target clips from channel one' );
$main_clips = BSC_TV_Music::clips();
station_check( BSC_TV_Music::approved( reset( $main_clips ) ), 'Cross-station bulk request leaves original clips active' );
$fr_day = BSC_TV_Day::programme( BSC_TV_Day::config( array( 'station' => 'fr' ) ), $now );
station_check( false === strpos( $fr_day['html'], 'Programme fixture 5' ), 'French day list excludes English programmes' );
$player_html = BSC_TV_Player::render( array( 'station' => 'fr', 'show_poster' => 'no' ) );
$day_html = BSC_TV_Day::render( array( 'station' => 'fr' ) );
station_check( false !== strpos( $player_html, 'station=fr' ) && false !== strpos( $day_html, 'station=fr' ), 'Player and paginated day widget keep French polling endpoints' );
station_check( false !== strpos( do_shortcode( '[bsc_tv_player station="fr" show_poster="no"]' ), 'station=fr' ), 'The French player works through its shortcode' );
station_check( '' === BSC_TV_Stations::watch_url( BSC_TV_Display::LIVE_PAGE, 'fr' ), 'French badge never redirects by default to the first channel page' );
in_fr( static function () { $settings = BSC_TV_Player::settings(); $settings['watch_url'] = 'https://example.com/tv-fr/'; update_option( BSC_TV_Stations::key( BSC_TV_Player::SETTINGS ), $settings, false ); } );
station_check( 'https://example.com/tv-fr/' === BSC_TV_Stations::watch_url( BSC_TV_Display::LIVE_PAGE, 'fr' ), 'French badge can use the separate viewing page configured in administration' );
do_action( 'rest_api_init' );
foreach ( array( '/player', '/day', '/programme' ) as $route ) {
	$request = new WP_REST_Request( 'GET', '/bsc-tv/v1' . $route ); $request->set_param( 'station', 'fr' );
	station_check( 200 === rest_do_request( $request )->get_status(), 'French public route is available: ' . $route );
	$request->set_param( 'station', 'invalid' ); station_check( 400 === rest_do_request( $request )->get_status(), 'Unknown station is rejected by REST schema: ' . $route );
}
ob_start(); in_fr( array( 'BSC_TV_Player_Admin', 'page' ) ); $admin_html = ob_get_clean();
station_check( false !== strpos( $admin_html, 'Chaîne 2 · BSC TV Français' ) && false !== strpos( $admin_html, 'name="station" value="fr"' ), 'French administration and mutation forms visibly identify channel two' );
station_check( false !== strpos( $admin_html, 'page=bsc-tv-live-fr' ) && false !== strpos( $admin_html, 'page=bsc-tv-music-fr' ), 'French editing and music navigation stay on the same antenna' );
station_check( false === strpos( $admin_html, 'name="action" value="bsc_tv_player_import_key"' ), 'French screen links to the shared importer instead of duplicating execution credentials' );
station_check( wp_create_nonce( 'bsc_tv_player_queue' ) !== in_fr( static function () { return wp_create_nonce( BSC_TV_Stations::nonce( 'bsc_tv_player_queue' ) ); } ), 'A form from channel one cannot authorize a channel-two mutation' );
ob_start(); in_fr( array( 'BSC_TV_Music_Admin', 'page' ) ); $music_html = ob_get_clean();
station_check( false !== strpos( $music_html, 'data-station="fr"' ) && false !== strpos( $music_html, 'name="station" value="fr"' ), 'Playlist AJAX and bulk forms preserve French station scope' );
update_option( BSC_TV_Sync::SETTINGS, array( 'youtube_key' => 'fixture-only' ), false );
$job_main = BSC_TV_Music_Playlist::start( 'PLfixture0123456789', 'morning' );
$job_fr = in_fr( static function () { return BSC_TV_Music_Playlist::start( 'PLfixture9876543210', 'morning' ); } );
station_check( ! is_wp_error( $job_main ) && ! is_wp_error( $job_fr ) && $job_main['token'] !== $job_fr['token'], 'Each antenna can prepare its own playlist job for the same administrator' );
station_check( $job_main['token'] === BSC_TV_Music_Playlist::status()['token'], 'Creating a French playlist job does not replace channel-one progress' );
station_check( is_wp_error( in_fr( static function () use ( $job_main ) { return BSC_TV_Music_Playlist::step( $job_main['token'], 0 ); } ) ), 'Playlist continuation tokens cannot cross antenna contexts' );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$requests ) {
	$path = wp_parse_url( $url, PHP_URL_PATH ); $requests[] = $path;
	if ( false !== strpos( $path, '/playlistItems' ) ) { $body = array( 'items' => array( array( 'contentDetails' => array( 'videoId' => 'sharedclip0' ), 'snippet' => array( 'title' => 'Official fixture - New track' ), 'status' => array( 'privacyStatus' => 'public' ) ) ), 'pageInfo' => array( 'totalResults' => 1 ) ); }
	elseif ( false !== strpos( $path, '/videos' ) ) { $body = array( 'items' => array( array( 'id' => 'sharedclip0', 'snippet' => array( 'title' => 'Official fixture - New track', 'channelTitle' => 'Fixture uploader', 'channelId' => 'UCfixture', 'liveBroadcastContent' => 'none', 'publishedAt' => '2020-01-01T00:00:00Z' ), 'contentDetails' => array( 'duration' => 'PT4M' ), 'status' => array( 'privacyStatus' => 'public', 'embeddable' => true ) ) ) ); }
	else { throw new RuntimeException( 'Unexpected platform endpoint in station test.' ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
}, 10, 3 );
$import_main = BSC_TV_Music_Playlist::step( $job_main['token'], 0 );
$import_fr = in_fr( static function () use ( $job_fr ) { return BSC_TV_Music_Playlist::step( $job_fr['token'], 0 ); } );
station_check( ! is_wp_error( $import_main ) && ! is_wp_error( $import_fr ) && 1 === $import_main['created'] && 1 === $import_fr['created'], 'The same playlist video imports independently into both music libraries' );
station_check( 3 === count( BSC_TV_Music::clips() ) && 3 === count( in_fr( array( 'BSC_TV_Music', 'clips' ) ) ), 'Playlist save assigns every new clip to its destination antenna' );
$fr_imports = in_fr( array( 'BSC_TV_Music', 'clips' ) ); $fr_import = end( $fr_imports );
station_check( 'fr' === $fr_import['station'] && 'pending' === $fr_import['rights'] && ! $fr_import['active'], 'French playlist import does not inherit first-channel approval' );
$fr_band = in_fr( static function () { delete_option( BSC_TV_Stations::key( BSC_TV_Music::SETTINGS ) ); return BSC_TV_Music::settings()['bands']['morning']; } );
station_check( 'BSC FR · Hip-hop 90 · Matin' === $fr_band['name'], 'Second station has clearly named hip-hop and rap 90s music bands' );
$fr_music_config = in_fr( array( 'BSC_TV_Music', 'settings' ) );
station_check( 1990 === $fr_music_config['year_min'] && 1999 === $fr_music_config['year_max'] && 'fr' === $fr_music_config['region_filter'], 'French music defaults to verified 1990s French hip-hop / rap' );
foreach ( array( 1989 => false, 1990 => true, 1995 => true, 1999 => true, 2000 => false, 0 => false ) as $year => $eligible ) { station_check( $eligible === BSC_TV_Music::in_selection( array( 'year' => $year, 'region' => 'fr' ), $fr_music_config ), 'The 1990s selection honors year boundary ' . $year ); }
station_check( ! BSC_TV_Music::in_selection( array( 'year' => 1995, 'region' => 'us' ), $fr_music_config ), 'French rap selection does not guess a track region' );
station_check( BSC_TV_Music::in_selection( array( 'year' => 0 ), BSC_TV_Music::settings() ), 'No decade filter is imposed on the first music library' );
station_check( 2 === in_fr( static function () { return BSC_TV_Music::diagnostic()['counts']['ready']; } ), 'Diagnostics distinguish approved 1990s tracks from imported incomplete references' );
$main_before = get_option( BSC_TV_Player::STATE );
$clock_keys = array();
add_action( 'updated_option', static function ( $key ) use ( &$clock_keys ) { if ( in_array( $key, array( BSC_TV_Player::STATE, BSC_TV_Player::STATE . '_station_fr' ), true ) ) { $clock_keys[] = $key; } } );
update_option( BSC_TV_Sync::SETTINGS, array( 'live_enabled' => false ), false );
delete_option( BSC_TV_Server::TICK ); BSC_TV_Server::set_mode( true );
in_fr( static function () { delete_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) ); } );
$before_tick = count( $requests );
$tick = BSC_TV_Server::tick();
station_check( ! is_wp_error( $tick ) && 'disabled' === $tick['status'], 'Existing server task can reconcile both clocks without enabled platform checks' );
station_check( in_fr( static function () { return ! empty( get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ) )['plan'] ); } ), 'The shared Infomaniak tick builds the second clock without a separate job' );
station_check( $before_tick === count( $requests ), 'Reconciling both antenna clocks does not double platform imports' );
foreach ( array( BSC_TV_Sync::DAILY, BSC_TV_Sync::LIVE, BSC_TV_Player_Schedule::HOURLY, BSC_TV_Player_Schedule::NOW ) as $hook ) { station_check( ! wp_next_scheduled( $hook ), 'Two antennas do not recreate WP-Cron work: ' . $hook ); }
station_check( 'main' === BSC_TV_Stations::current(), 'All administration, playlist and server operations restore the original context' );
echo "OK $checks independent-station integration checks.\n";
