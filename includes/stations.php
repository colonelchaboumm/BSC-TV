<?php
defined( 'ABSPATH' ) || exit;

/** A request-local antenna context. Source discovery remains shared; clocks never are. */
final class BSC_TV_Stations {
	private static $current = 'main';
	public static function choices() { return array( 'main' => 'Chaîne 1 · BSC TV Monde', 'fr' => 'Chaîne 2 · BSC TV Français' ); }
	public static function valid( $id ) { return is_string( $id ) && isset( self::choices()[ $id ] ); }
	public static function current() { return self::$current; }
	public static function brand( $id ) {
		$french = 'fr' === $id;
		return array( 'name' => 'Brewers Television ' . ( $french ? 'France' : 'World' ), 'logo' => BSC_TV_URL . 'assets/media/station-' . ( $french ? 'fr' : 'world' ) . '.png', 'url' => $french ? BSC_TV_Display::FR_PAGE : BSC_TV_Display::LIVE_PAGE );
	}
	public static function key( $key ) { return 'main' === self::$current ? $key : $key . '_station_' . self::$current; }
	public static function run( $id, $callback ) {
		if ( ! self::valid( $id ) ) { return new WP_Error( 'station_unknown', 'Chaîne TV inconnue.', array( 'status' => 400 ) ); }
		$previous = self::$current;
		self::$current = $id;
		try { return $callback(); } finally { self::$current = $previous; }
	}
	public static function any_enabled() {
		foreach ( self::choices() as $id => $label ) { if ( self::run( $id, static function () { return BSC_TV_Player::settings()['enabled']; } ) ) { return true; } }
		return false;
	}
	public static function snapshots() {
		$result = array();
		foreach ( self::choices() as $id => $label ) { $result[ $id ] = self::run( $id, static function () { return BSC_TV_Player::snapshot(); } ); }
		return $result;
	}
	public static function refresh_music() {
		foreach ( self::choices() as $id => $label ) { self::run( $id, array( 'BSC_TV_Music', 'daily' ) ); }
	}
	public static function invalidate_catalogues() {
		foreach ( self::choices() as $id => $label ) { self::run( $id, static function () { delete_transient( self::key( BSC_TV_Player::CACHE ) ); } ); }
	}
	public static function french( $language ) {
		if ( ! is_string( $language ) ) { return false; }
		$language = strtolower( remove_accents( trim( $language ) ) );
		return (bool) preg_match( '/^(fr(?:[-_][a-z]{2})?|fra|fre|francais|french)$/D', $language );
	}
	public static function sources() { return (array) get_option( 'bsc_tv_station_sources_fr', array() ); }
	public static function allows_channel( $id, $data ) {
		if ( 'main' === self::$current ) { return true; }
		$config = self::sources();
		return isset( $config['ids'] ) ? in_array( (int) $id, (array) $config['ids'], true ) : self::french( $data['language'] ?? '' );
	}
	public static function allows_show( $data ) {
		return 'main' === self::$current || empty( $data['language'] ) || self::french( $data['language'] );
	}
	public static function url( $kind = 'live', $id = null ) {
		$id = $id ?? self::$current;
		return admin_url( 'admin.php?page=bsc-tv-' . $kind . ( 'fr' === $id ? '-fr' : '' ) );
	}
	public static function nonce( $action ) { return 'main' === self::$current ? $action : $action . ':' . self::$current; }
	public static function watch_url( $value, $station ) {
		$url = is_scalar( $value ) ? BSC_TV_Content::url( $value ) : '';
		if ( $url && ( 'main' === $station || BSC_TV_Display::LIVE_PAGE !== $url ) ) { return $url; }
		return self::run( $station, static function () { return BSC_TV_Player::settings()['watch_url']; } ) ?: self::brand( $station )['url'];
	}
	public static function field( $action ) {
		echo '<input type="hidden" name="station" value="' . esc_attr( self::$current ) . '">';
		wp_nonce_field( self::nonce( $action ) );
	}
	public static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) ); }
		$id = wp_unslash( $_POST['station'] ?? 'main' );
		if ( ! self::valid( $id ) ) { wp_die( 'Chaîne TV inconnue.', '', array( 'response' => 400 ) ); }
		self::$current = $id;
		check_admin_referer( self::nonce( $action ) );
	}
	public static function register() { add_action( 'admin_post_bsc_tv_station_sources', array( __CLASS__, 'save_sources' ) ); }
	public static function banner( $kind ) {
		$id = self::$current; $color = 'fr' === $id ? '#bd009f' : '#008c7a';
		echo '<div class="bsc-station-admin" style="border-left:6px solid ' . esc_attr( $color ) . ';padding:16px 20px;background:#fff;margin:16px 0"><h2 style="margin-top:0">' . esc_html( self::choices()[ $id ] ) . '</h2><p>Vous gérez la programmation, les réglages et la bibliothèque musicale de cette chaîne uniquement.</p><nav aria-label="Choisir la chaîne BSC TV">';
		foreach ( self::choices() as $target => $label ) { echo '<a class="button ' . ( $target === $id ? 'button-primary' : '' ) . '" href="' . esc_url( self::url( $kind, $target ) ) . '"' . ( $target === $id ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a> '; }
		echo '</nav><p><a href="' . esc_url( self::url( 'live' ) ) . '">Programmation et lecteur</a> · <a href="' . esc_url( self::url( 'music' ) ) . '">Musique et playlists</a></p><code>[bsc_tv_player station="' . esc_attr( $id ) . '"]</code> <code>[bsc_tv_day station="' . esc_attr( $id ) . '"]</code></div>';
	}
	public static function sources_form() {
		if ( 'fr' !== self::$current ) { return; }
		$count = 0;
		echo '<section id="bsc-station-sources"><details><summary><strong>Chaîne 2 · Choisir les sources des émissions françaises</strong></summary><p>Par défaut, seules les sources dont la langue est renseignée en français sont retenues. Vérifiez les sources ci-dessous puis enregistrez votre sélection. Une émission explicitement déclarée dans une autre langue reste exclue. Le pays seul ne permet pas de déduire la langue.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsc_tv_station_sources">';
		self::field( 'bsc_tv_station_sources' );
		echo '<table class="widefat striped"><thead><tr><th>Diffuser sur la chaîne 2</th><th>Source</th><th>Langue déclarée</th></tr></thead><tbody>';
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID ); $allowed = self::allows_channel( $post->ID, $data ); $count += (int) $allowed;
			echo '<tr><td><label><input type="checkbox" name="sources[]" value="' . esc_attr( $post->ID ) . '" ' . checked( $allowed, true, false ) . '> Émissions en français</label></td><td>' . esc_html( get_the_title( $post ) ) . '</td><td>' . esc_html( ( $data['language'] ?? '' ) ?: 'À renseigner / vérifier' ) . '</td></tr>';
		}
		echo '</tbody></table><p>' . esc_html( $count ) . ' source(s) sélectionnée(s). Les brouillons, restrictions d’âge et exclusions du lecteur restent respectés.</p><p><button class="button button-primary" name="operation" value="save">Enregistrer les sources françaises</button> <button class="button" name="operation" value="auto">Revenir à la sélection automatique par langue</button></p></form></details></section>';
	}
	public static function save_sources() {
		self::authorize( 'bsc_tv_station_sources' );
		if ( 'fr' !== self::$current ) { wp_die( 'Cette sélection concerne la chaîne 2.', '', array( 'response' => 400 ) ); }
		require_once __DIR__ . '/music-admin.php';
		$result = BSC_TV_Music_Admin::change( static function () {
			if ( 'auto' === ( $_POST['operation'] ?? '' ) ) { delete_option( 'bsc_tv_station_sources_fr' ); }
			else {
				$public = array_map( 'intval', wp_list_pluck( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ), 'ID' ) );
				$ids = array_values( array_intersect( $public, array_map( 'absint', (array) ( $_POST['sources'] ?? array() ) ) ) );
				update_option( 'bsc_tv_station_sources_fr', array( 'ids' => $ids ), false );
			}
			self::invalidate_catalogues();
		}, true, false, true );
		set_transient( self::key( 'bsc_tv_player_notice_' . get_current_user_id() ), is_wp_error( $result ) ? $result->get_error_message() : 'Sources de la chaîne 2 enregistrées et grille recalculée.', MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() . '#bsc-station-sources' ); exit;
	}
}
