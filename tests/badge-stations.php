<?php
/** php tests/badge-stations.php /disposable/wordpress [preview-output-directory] */
require __DIR__ . '/badge-integration.php';
$checks = 0;
foreach ( array( $show, $show_b ) as $id ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); }
update_post_meta( $channel, BSC_TV_Content::STATE, array( 'key' => 'badgebeerqa', 'live_at' => time(), 'live' => array() ) );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true, 'repeat_hours' => 0, 'live_priority' => true ) );
$fr_channel = badge_post( BSC_TV_Content::CHANNEL, 'Créateur français', BSC_TV_Content::channel_data( array( 'url' => 'https://www.youtube.com/@fixturefr', 'language' => 'fr' ) ) );
$fr_ids = array();
foreach ( array( 'franc000001' => 'Dégustation française en cours', 'franc000002' => 'Rencontre française à venir' ) as $video => $name ) {
	$id = badge_post( BSC_TV_Content::SHOW, $name, array_replace( $data, array( 'channel_id' => $fr_channel, 'url' => 'https://youtu.be/' . $video, 'language' => 'fr', 'start' => time() - DAY_IN_SECONDS, 'end' => time() - DAY_IN_SECONDS + 1800 ) ) );
	update_post_meta( $id, BSC_TV_Player::MEDIA, array( 'mode' => 'yes', 'duration' => 1800, 'published_at' => time() - DAY_IN_SECONDS ) );
	$fr_ids[] = $id;
}
update_option( 'bsc_tv_station_sources_fr', array( 'ids' => array( $fr_channel ) ) );
BSC_TV_Stations::run( 'fr', static function () {
	update_option( BSC_TV_Stations::key( BSC_TV_Player::SETTINGS ), array( 'enabled' => true, 'repeat_hours' => 0 ) );
	update_option( BSC_TV_Stations::key( BSC_TV_Music::SETTINGS ), array( 'enabled' => false ) );
} );
BSC_TV_Stations::invalidate_catalogues();
$now = time();
foreach ( array( 'main' => 'youtube:M7lc1UVf-VE', 'fr' => 'youtube:franc000001' ) as $station => $key ) {
	BSC_TV_Stations::run( $station, static function () use ( $key, $now ) {
		$pool = BSC_TV_Player::media();
		$state = BSC_TV_Player::advance( array(), array( $key => $pool[ $key ] ), array(), BSC_TV_Player::settings(), $now );
		update_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), $state, false );
	} );
}
$settings = array( 'format' => 'badge', 'badge_stations' => 'both', 'image' => 1 );
$html = BSC_TV_Display::render( $settings );
$dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); $xpath = new DOMXPath( $dom );
badge_check( 2 === $xpath->query( '//div[@data-bsc-tv-station]' )->length, 'A single widget renders both station badges' );
foreach ( array( 'main' => BSC_TV_Display::LIVE_PAGE, 'fr' => BSC_TV_Display::FR_PAGE ) as $id => $url ) {
	$root = '//div[@data-bsc-tv-station="' . $id . '"]';
	$logo = $xpath->query( $root . '/a[contains(@class,"station-link")]' )->item( 0 );
	badge_check( $logo && $logo->getAttribute( 'href' ) === $url, 'The logo links to its exact station viewing page: ' . $id );
	badge_check( $xpath->query( $root . '//summary//a' )->length === 0, 'Navigation is outside the dropdown summary: ' . $id );
	badge_check( false !== strpos( $logo->textContent . $logo->getAttribute( 'aria-label' ), BSC_TV_Stations::brand( $id )['name'] ), 'The station logo link has an accessible station name: ' . $id );
	badge_check( BSC_TV_Stations::brand( $id )['logo'] === $logo->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'src' ), 'The supplied logo is used by default: ' . $id );
	badge_check( false !== strpos( html_entity_decode( $xpath->query( $root )->item( 0 )->getAttribute( 'data-bsc-tv' ) ), 'station=' . $id ), 'Each station polls its own local endpoint: ' . $id );
}
$world_summary = $xpath->query( '//div[@data-bsc-tv-station="main"]//summary' )->item( 0 )->textContent;
$fr_summary = $xpath->query( '//div[@data-bsc-tv-station="fr"]//summary' )->item( 0 )->textContent;
badge_check( false !== strpos( $world_summary, 'Programme de l’antenne BSC' ) && false === strpos( $world_summary, 'Dégustation française en cours' ), 'World shows the programme on its own clock' );
badge_check( false !== strpos( $fr_summary, 'Dégustation française en cours' ) && false === strpos( $fr_summary, 'Programme de l’antenne BSC' ), 'France shows the programme on its own clock' );
$fr = badge_response( array( 'station' => 'fr' ) )->get_data();
badge_check( 'Dégustation française en cours' === $fr['highlight']['title'] && false !== strpos( $fr['html'], 'Rencontre française à venir' ), 'The French refresh includes its current and next scheduled programmes' );
badge_check( false === strpos( $fr['html'], 'Programme de l’antenne BSC' ) && false === strpos( $fr['html'], 'Futur direct de la plateforme' ), 'English-only source schedules do not leak into the French dropdown' );
$single = BSC_TV_Display::render( array( 'format' => 'badge', 'badge_stations' => 'single', 'station' => 'fr' ) );
badge_check( 1 === substr_count( $single, 'data-bsc-tv-station=' ) && false !== strpos( $single, BSC_TV_Display::FR_PAGE ), 'Single-station mode remains available and uses the French page' );
$hidden = BSC_TV_Display::render( $settings + array( 'show_station_logo' => '' ) );
badge_check( false === strpos( $hidden, 'station-logo' ), 'Logos can be hidden without removing station programmes' );
$custom = BSC_TV_Display::render( $settings + array( 'world_logo' => array( 'url' => 'https://example.com/world.png' ), 'fr_logo' => array( 'url' => 'https://example.com/fr.png' ), 'world_watch_url' => array( 'url' => 'https://example.com/world/' ), 'fr_watch_url' => array( 'url' => 'https://example.com/fr/' ) ) );
badge_check( false !== strpos( $custom, 'src="https://example.com/world.png"' ) && false !== strpos( $custom, 'src="https://example.com/fr.png"' ) && false !== strpos( $custom, 'href="https://example.com/fr/"' ), 'Elementor media and URL values customize the two station identities independently' );
$hostile = BSC_TV_Display::render( $settings + array( 'fr_logo' => array( 'url' => 'javascript:alert(1)' ), 'fr_watch_url' => array( 'url' => 'javascript:alert(1)' ) ) );
badge_check( false === strpos( $hostile, 'javascript:' ) && false !== strpos( $hostile, BSC_TV_Display::FR_PAGE ), 'Invalid station media and destinations fall back to safe defaults' );
badge_check( 2 === substr_count( do_shortcode( '[bsc_tv format="badge" badge_stations="both"]' ), 'data-bsc-tv-station=' ), 'Both station badges also work through the shortcode' );
$widget = Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'bsc-tv-badge' ); $stack = $widget->get_stack( false ); $controls = $stack['controls'] + ( $stack['style_controls'] ?? array() );
badge_check( 'both' === $controls['badge_stations']['default'] && 'yes' === $controls['show_station_logo']['default'], 'Elementor enables both station logos by default' );
badge_check( isset( $controls['badge_logo-size'], $controls['badge_logo-border'], $controls['badge_stations_gap'], $controls['badge_station_name_typography_font_size'] ), 'Logo size, border, spacing and station typography are customizable' );
badge_check( 0 === $requests && ! wp_script_is( 'bsc-tv-player', 'enqueued' ), 'Two station badges make no platform requests and load no player SDK' );

if ( ! empty( $argv[2] ) ) {
	$output = rtrim( $argv[2], '/\\' ); if ( ! is_dir( $output ) ) { mkdir( $output, 0777, true ); }
	$preview = str_replace( BSC_TV_URL . 'assets/', 'assets/', $html );
	$preview = preg_replace_callback( '/data-bsc-tv="([^"]+)"/', static function ( $match ) { return 'data-bsc-tv="' . ( false !== strpos( $match[1], 'station=fr' ) ? 'fr.json' : 'world.json' ) . '"'; }, $preview );
	$page = '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pastilles World et France</title><link rel="stylesheet" href="assets/programme.css"><style>body{margin:0;padding:30px;font-family:Arial,sans-serif;background:#f3f2ed}main{max-width:1000px;margin:auto}h1{font-size:22px;margin:0 0 28px}.mobile{max-width:390px}a{color:inherit}</style><main><h1>Brewers Television · World et France</h1>' . $preview . '</main><script src="assets/programme.js"></script></html>';
	file_put_contents( $output . '/index.html', $page );
	file_put_contents( $output . '/narrow.html', str_replace( '<main>', '<main class="mobile">', $page ) );
	foreach ( array( 'main' => 'world', 'fr' => 'fr' ) as $id => $filename ) {
		$payload = badge_response( array( 'station' => $id ) )->get_data();
		$payload['html'] = str_replace( BSC_TV_URL . 'assets/', 'assets/', $payload['html'] );
		file_put_contents( $output . '/' . $filename . '.json', wp_json_encode( $payload ) );
	}
}
echo "OK $checks dual-station badge checks.\n";
