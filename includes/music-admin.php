<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/music-playlist.php';

/** One section in the BSC TV administration; clip references are private WordPress records. */
final class BSC_TV_Music_Admin {
	public static function register() {
		BSC_TV_Music_Playlist::register();
		add_action( 'admin_menu', static function () { add_submenu_page( 'bsc-tv', 'Music Interludes', 'Music Interludes', 'manage_options', 'bsc-tv-music', array( __CLASS__, 'page' ) ); }, 20 );
		add_action( 'admin_post_bsc_tv_music_settings', array( __CLASS__, 'settings' ) );
		add_action( 'admin_post_bsc_tv_music_clip', array( __CLASS__, 'clip' ) );
		add_action( 'admin_enqueue_scripts', static function ( $hook ) {
			if ( false === strpos( $hook, 'bsc-tv-music' ) ) { return; }
			wp_enqueue_media();
			wp_enqueue_script( 'bsc-tv-music-admin', BSC_TV_URL . 'assets/music-admin.js', array(), BSC_TV_VERSION, true );
			wp_enqueue_style( 'bsc-tv-music-admin', BSC_TV_URL . 'assets/music-admin.css', array(), BSC_TV_VERSION );
		} );
	}
	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}
	private static function finish( $message, $error = false ) {
		set_transient( 'bsc_tv_music_notice_' . get_current_user_id(), array( $message, $error ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=bsc-tv-music' ) ); exit;
	}
	/** Administrative changes and public playback use the same database lock. */
	public static function change( $callback ) {
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) { return new WP_Error( 'music_busy', 'L’antenne se met à jour. Réessayez.' ); }
		try {
			wp_cache_delete( BSC_TV_Player::STATE, 'options' );
			$state = BSC_TV_Player::advance_clock( (array) get_option( BSC_TV_Player::STATE, array() ), time() );
			$result = $callback();
			if ( is_wp_error( $result ) ) { return $result; }
			BSC_TV_Music::invalidate();
			// Persist the reconciled old clock before computing the disabled/finish pool.
			update_option( BSC_TV_Player::STATE, $state, false );
			$state = BSC_TV_Player::advance_clock( $state, time() );
			$state = BSC_TV_Player_Schedule::replenish( $state, BSC_TV_Player::media(), BSC_TV_Player::settings(), time(), true );
			update_option( BSC_TV_Player::STATE, $state, false );
			return true;
		} finally { BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock ); }
	}
	public static function settings() {
		self::authorize( 'bsc_tv_music_settings' );
		$input = (array) wp_unslash( $_POST['music'] ?? array() );
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		if ( 'stop' === $operation ) { $input = BSC_TV_Music::settings(); $input['enabled'] = false; }
		$config = BSC_TV_Music::normalize( $input );
		if ( ! BSC_TV_Music::valid_bands( $config ) ) { self::finish( 'Les tranches horaires actives ne doivent pas se chevaucher ni avoir la même heure de début et de fin.', true ); }
		$result = self::change( static function () use ( $config ) { update_option( BSC_TV_Music::SETTINGS, $config, false ); } );
		self::finish( is_wp_error( $result ) ? $result->get_error_message() : ( 'stop' === $operation ? 'Futurs interludes désactivés. La politique choisie s’applique au clip courant.' : 'Réglages musicaux enregistrés dans l’antenne commune.' ), is_wp_error( $result ) );
	}
	public static function sanitize_clip( $input, $old = array() ) {
		$url = BSC_TV_Content::url( $input['url'] ?? '' );
		$resource = BSC_TV_Player::resource( $url );
		if ( ! $resource || 'youtube' !== $resource['platform'] ) { return new WP_Error( 'music_url', 'Indiquez une URL de vidéo YouTube valide.' ); }
		$same = ( $old['youtube_id'] ?? '' ) === $resource['id'];
		$rights = in_array( $input['rights'] ?? '', array( 'pending', 'confirmed', 'revoked' ), true ) ? $input['rights'] : 'pending';
		$note = sanitize_textarea_field( $input['rights_note'] ?? '' );
		if ( 'confirmed' === $rights && ( ! $note || ( ! ( $same && 'confirmed' === ( $old['rights'] ?? '' ) ) && empty( $input['confirm'] ) ) ) ) { $rights = 'pending'; }
		$availability = $same ? ( $old['availability'] ?? 'unchecked' ) : 'unchecked';
		if ( 'blocked' !== $availability ) { $availability = ! empty( $input['manual_available'] ) ? 'available' : 'unchecked'; }
		return array(
			'artist' => sanitize_text_field( $input['artist'] ?? '' ), 'title' => sanitize_text_field( $input['title'] ?? '' ),
			'year' => empty( $input['year'] ) ? 0 : max( 1900, min( 2100, (int) $input['year'] ) ),
			'categories' => array_values( array_intersect( (array) ( $input['categories'] ?? array() ), array( 'morning', 'day', 'evening', 'night' ) ) ),
			'region' => in_array( $input['region'] ?? '', array( 'us', 'fr', 'other' ), true ) ? $input['region'] : 'other',
			'youtube_id' => $resource['id'], 'url' => 'https://www.youtube.com/watch?v=' . $resource['id'],
			'channel' => sanitize_text_field( $input['channel'] ?? '' ), 'duration' => max( 0, min( 3600, (int) ( $input['duration'] ?? 0 ) ) ),
			'image' => BSC_TV_Content::url( $input['image'] ?? '' ), 'active' => ! empty( $input['active'] ), 'official' => ! empty( $input['official'] ),
			'rights' => $rights, 'rights_note' => $note, 'availability' => $availability, 'checked_at' => $same ? ( $old['checked_at'] ?? 0 ) : 0,
		);
	}
	public static function clip() {
		self::authorize( 'bsc_tv_music_clip' );
		$id = absint( $_POST['clip_id'] ?? 0 ); $operation = sanitize_key( $_POST['operation'] ?? 'save' );
		if ( $id && BSC_TV_Music::POST !== get_post_type( $id ) ) { self::finish( 'Clip inconnu.', true ); }
		$old = $id ? (array) get_post_meta( $id, BSC_TV_Music::META, true ) : array();
		if ( 'refresh' === $operation && $id ) { BSC_TV_Music::refresh( array( $id => $old ) ); self::finish( 'Vérification demandée. Consultez la disponibilité et le bilan ci-dessous.' ); }
		$clip = 'save' === $operation ? self::sanitize_clip( (array) wp_unslash( $_POST['clip'] ?? array() ), $old ) : $old;
		if ( is_wp_error( $clip ) ) { self::finish( $clip->get_error_message(), true ); }
		$result = self::change( static function () use ( &$id, $operation, $clip ) {
			if ( in_array( $operation, array( 'activate', 'deactivate' ), true ) && $id ) { $clip['active'] = 'activate' === $operation; update_post_meta( $id, BSC_TV_Music::META, $clip ); return; }
			if ( 'delete' === $operation && $id ) { wp_trash_post( $id ); return; }
			if ( 'save' !== $operation ) { return new WP_Error( 'music_action', 'Action inconnue.' ); }
			$library = BSC_TV_Music::clips();
			if ( ! $id && count( $library ) >= 500 ) { return new WP_Error( 'music_limit', 'La bibliothèque est limitée à 500 clips.' ); }
			foreach ( $library as $other_id => $other ) { if ( $other_id !== $id && ( $other['youtube_id'] ?? '' ) === $clip['youtube_id'] ) { return new WP_Error( 'music_duplicate', 'Cette vidéo est déjà dans la bibliothèque. Modifiez ses catégories.' ); } }
			$id = wp_insert_post( array( 'ID' => $id, 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => trim( $clip['artist'] . ' — ' . $clip['title'] ) ), true );
			if ( is_wp_error( $id ) ) { return $id; }
			update_post_meta( $id, BSC_TV_Music::META, $clip );
		} );
		if ( ! is_wp_error( $result ) && 'save' === $operation && ! empty( $_POST['fetch_metadata'] ) ) { BSC_TV_Music::refresh( array( $id => $clip ) ); }
		self::finish( is_wp_error( $result ) ? $result->get_error_message() : 'Bibliothèque musicale mise à jour. Seuls les clips actifs, disponibles et validés peuvent être diffusés.', is_wp_error( $result ) );
	}
	private static function form( $action ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( $action ); }
	private static function input( $name, $label, $value, $type = 'text', $extra = '' ) {
		echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" ' . $extra . '></label></p>';
	}
	private static function checkbox( $name, $label, $checked ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
	private static function select( $name, $label, $value, $choices ) {
		echo '<p><label>' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $id => $text ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $value, $id, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label></p>';
	}
	private static function media( $name, $label, $value, $type ) {
		echo '<p data-music-media data-type="' . esc_attr( $type ) . '" data-url="' . esc_url( $value ? wp_get_attachment_url( $value ) : '' ) . '"><strong>' . esc_html( $label ) . '</strong><br><input type="hidden" name="music[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"><span data-media-name>' . esc_html( $value ? get_the_title( $value ) : 'Aucun fichier' ) . '</span> <button type="button" class="button" data-media-choose>Choisir</button> <button type="button" class="button" data-media-clear>Retirer</button></p>';
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) ); }
		$config = BSC_TV_Music::settings(); $clips = BSC_TV_Music::clips(); $snapshot = BSC_TV_Player::snapshot(); $state = (array) get_option( BSC_TV_Player::STATE, array() );
		$notice = get_transient( 'bsc_tv_music_notice_' . get_current_user_id() );
		echo '<div class="wrap bsc-music-admin"><h1>BSC TV — Music Interludes</h1><nav><a href="#music-general">Général</a> · <a href="#music-schedule">Programmation musicale</a> · <a href="#music-library">Clips</a> · <a href="#music-identity">Identité visuelle</a> · <a href="#music-status">État actuel</a></nav>';
		if ( $notice ) { delete_transient( 'bsc_tv_music_notice_' . get_current_user_id() ); echo '<div class="notice notice-' . ( $notice[1] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice[0] ) . '</p></div>'; }
		self::form( 'bsc_tv_music_settings' ); echo '<button class="button" name="operation" value="stop">Désactiver immédiatement les futurs interludes</button></form>';
		self::form( 'bsc_tv_music_settings' );
		echo '<section id="music-general"><h2>Général</h2><p>Un seul clip au maximum après une émission strictement plus longue que le seuil. Aucun clip n’est fourni ou validé automatiquement.</p>';
		self::checkbox( 'music[enabled]', 'Activer les interludes musicaux', $config['enabled'] );
		self::input( 'music[threshold]', 'Seuil de durée d’émission, en minutes (strictement supérieur)', $config['threshold'], 'number', 'min="1" max="600" required' );
		self::checkbox( 'music[flexible]', 'Autoriser l’insertion dans une rotation entièrement automatique sans horaire fixé', $config['flexible'] );
		echo '<p class="description">Sans cette option, seuls les espaces libres assez longs sont utilisés. Avec cette option, les choix automatiques sont repoussés du temps de l’interlude ; toute présence d’un programme fixé dans la file empêche cette insertion flexible.</p>';
		self::input( 'music[repeat_hours]', 'Délai minimal entre deux diffusions du même clip (heures)', $config['repeat_hours'], 'number', 'min="0" max="720" step="0.5"' );
		self::select( 'music[disable_policy]', 'En cas de désactivation pendant un interlude', $config['disable_policy'], array( 'finish' => 'Laisser terminer l’interlude courant', 'interrupt' => 'Interrompre et rendre l’antenne aux émissions' ) );
		self::select( 'music[live_policy]', 'Lorsqu’un véritable direct prioritaire est confirmé', $config['live_policy'], array( 'interrupt' => 'Interrompre l’interlude immédiatement (recommandé)', 'finish' => 'Terminer l’interlude avant de rejoindre le direct' ) );
		echo '<p class="description">Le choix Terminer peut retarder l’accès au vrai direct de la durée restante du clip et de sa sortie. Un véritable direct déjà à l’antenne ne sera jamais interrompu par la musique. Les artistes consécutifs identiques sont exclus ; le hip-hop alterne les références US/France lorsque le catalogue le permet.</p></section>';
		echo '<section id="music-schedule"><h2>Programmation musicale · Europe/Paris</h2><p>La tranche est choisie à l’heure de début de l’interlude, avec les changements d’heure été/hiver. Les tranches peuvent traverser minuit ; aucun chevauchement actif n’est accepté.</p>';
		$names = array_map( static function ( $band ) { return $band['name']; }, $config['bands'] );
		foreach ( $config['bands'] as $id => $band ) {
			echo '<fieldset><legend>' . esc_html( $names[ $id ] ) . '</legend>';
			self::checkbox( 'music[bands][' . $id . '][enabled]', 'Activer cette tranche', $band['enabled'] );
			self::input( 'music[bands][' . $id . '][name]', 'Nom de la catégorie', $band['name'] );
			self::input( 'music[bands][' . $id . '][start]', 'Début', $band['start'], 'time', 'required' ); self::input( 'music[bands][' . $id . '][end]', 'Fin', $band['end'], 'time', 'required' );
			self::select( 'music[bands][' . $id . '][category]', 'Jouer les clips de la catégorie', $band['category'], $names ); echo '</fieldset>';
		}
		echo '</section><section id="music-identity"><h2>Identité visuelle</h2><p>Les transitions sont des écrans distincts du lecteur YouTube. Sans logo configuré, un fond neutre est affiché.</p>';
		self::media( 'logo', 'Logo officiel BSC', $config['logo'], 'image' ); self::media( 'entry_video', 'Vidéo d’entrée (facultative)', $config['entry_video'], 'video' ); self::media( 'exit_video', 'Vidéo de sortie (facultative)', $config['exit_video'], 'video' );
		foreach ( array( 'entry' => 'Durée d’entrée (secondes)', 'exit' => 'Durée de sortie (secondes)' ) as $key => $label ) { self::input( 'music[' . $key . ']', $label, $config[ $key ], 'number', 'min="1" max="30" required' ); }
		self::input( 'music[background]', 'Fond', $config['background'], 'color' ); self::input( 'music[color]', 'Couleur du texte', $config['color'], 'color' );
		self::select( 'music[animation]', 'Animation CSS', $config['animation'], array( 'fade' => 'Apparition douce', 'slide' => 'Glissement doux', 'none' => 'Sans mouvement' ) );
		self::input( 'music[entry_text]', 'Texte d’entrée', $config['entry_text'] ); self::input( 'music[exit_text]', 'Texte de sortie', $config['exit_text'] );
		echo '<input type="hidden" name="music[show_text]" value="0">'; self::checkbox( 'music[show_text]', 'Afficher le texte de transition', $config['show_text'] ); self::checkbox( 'music[sound]', 'Autoriser le son du fichier d’animation (un clic peut être requis)', $config['sound'] );
		echo '<p><button type="button" class="button" data-music-preview="entry">Prévisualiser l’entrée</button> <button type="button" class="button" data-music-preview="exit">Prévisualiser la sortie</button></p><div data-music-preview-screen hidden role="status"></div></section>';
		submit_button( 'Enregistrer les réglages musicaux' ); echo '</form>';
		self::library( $clips, $names, $state ); self::status( $config, $state, $snapshot ); echo '</div>';
	}
	private static function library( $clips, $names, $state ) {
		$id = absint( $_GET['clip_id'] ?? 0 ); $clip = $clips[ $id ] ?? array();
		echo '<section id="music-library"><h2>Clips musicaux</h2><p>Ajoutez uniquement les URL officielles que vous avez vérifiées. L’intégration disponible sur YouTube ne vaut pas autorisation de programmation. Les références d’autorisation restent privées.</p>';
		self::playlist( $names );
		self::form( 'bsc_tv_music_clip' ); echo '<h3>' . ( $clip ? 'Modifier le clip' : 'Ajouter un clip' ) . '</h3><input type="hidden" name="clip_id" value="' . esc_attr( $clip ? $id : 0 ) . '"><input type="hidden" name="operation" value="save">';
		if ( ! empty( $clip['origin_playlist'] ) ) { echo '<p class="description">Clip importé depuis une playlist. Vérifiez le titre, l’artiste éventuellement suggéré, la source officielle et les autorisations avant activation.</p>'; }
		foreach ( array( 'url' => 'URL officielle YouTube', 'artist' => 'Artiste', 'title' => 'Titre', 'year' => 'Année', 'channel' => 'Chaîne YouTube d’origine', 'duration' => 'Durée exacte (secondes, obligatoire pour diffuser)', 'image' => 'URL de la miniature' ) as $key => $label ) { self::input( 'clip[' . $key . ']', $label, $clip[ $key ] ?? '', in_array( $key, array( 'year', 'duration' ), true ) ? 'number' : ( in_array( $key, array( 'url', 'image' ), true ) ? 'url' : 'text' ), 'url' === $key ? 'required' : '' ); }
		foreach ( $names as $key => $name ) { echo '<p><label><input type="checkbox" name="clip[categories][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $clip['categories'] ?? array(), true ), true, false ) . '> ' . esc_html( $name ) . '</label></p>'; }
		self::select( 'clip[region]', 'Alternance hip-hop', $clip['region'] ?? 'other', array( 'us' => 'Rap américain', 'fr' => 'Rap français', 'other' => 'Autre' ) );
		self::checkbox( 'clip[active]', 'Clip actif', ! empty( $clip['active'] ) ); self::checkbox( 'clip[official]', 'J’ai vérifié que cette URL appartient à une source officielle', ! empty( $clip['official'] ) );
		self::checkbox( 'clip[manual_available]', 'Lecture et durée vérifiées manuellement (sans vérification API disponible)', 'available' === ( $clip['availability'] ?? '' ) );
		echo '<p>Disponibilité connue : <strong>' . esc_html( $clip['availability'] ?? 'non vérifiée' ) . '</strong>. Une restriction confirmée par l’API ne peut pas être levée par la case manuelle.</p>';
		self::select( 'clip[rights]', 'Droits et conditions de programmation', $clip['rights'] ?? 'pending', array( 'pending' => 'En attente de validation', 'confirmed' => 'Autorisations confirmées', 'revoked' => 'Autorisations retirées / refusées' ) );
		echo '<p><label>Référence ou note sur les autorisations obtenues<br><textarea class="large-text" name="clip[rights_note]" rows="3">' . esc_textarea( $clip['rights_note'] ?? '' ) . '</textarea></label></p>';
		self::checkbox( 'clip[confirm]', 'Je confirme explicitement la validation des autorisations pour ce clip (obligatoire pour une nouvelle validation)', false );
		self::checkbox( 'fetch_metadata', 'Récupérer les métadonnées via la clé YouTube déjà configurée', true ); submit_button( 'Enregistrer le clip' ); echo '</form>';
		$page = max( 1, absint( $_GET['music_page'] ?? 1 ) );
		echo '<h3>Bibliothèque · ' . esc_html( count( $clips ) ) . ' / 500 clips</h3><div class="bsc-music-library"><table class="widefat striped"><thead><tr><th>Clip</th><th>Catégories</th><th>État</th><th>Diffusions</th><th>Actions</th></tr></thead><tbody>';
		foreach ( array_slice( $clips, ( $page - 1 ) * 25, 25, true ) as $clip_id => $row ) {
			$stat = $state['music_stats'][ $clip_id ] ?? array();
			echo '<tr><td><strong>' . esc_html( $row['artist'] . ' — ' . $row['title'] ) . '</strong><br>' . esc_html( ( $row['year'] ?? '' ) . ' · ' . ( $row['duration'] ?? 0 ) . ' s · ' . ( $row['channel'] ?? '' ) ) . '</td><td>' . esc_html( implode( ', ', array_intersect_key( $names, array_flip( $row['categories'] ?? array() ) ) ) ) . '</td><td>' . esc_html( BSC_TV_Music::approved( $row ) ? 'Prêt à diffuser' : 'Non diffusable' ) . '<br>' . esc_html( ( ! empty( $row['active'] ) ? 'Actif' : 'Inactif' ) . ' · ' . ( $row['rights'] ?? 'pending' ) . ' · ' . ( $row['availability'] ?? 'unchecked' ) ) . '</td><td>' . esc_html( ( $stat['plays'] ?? 0 ) . ' diffusion(s)' ) . '<br>' . esc_html( empty( $stat['last'] ) ? 'Jamais' : wp_date( 'd/m/Y H:i', $stat['last'], new DateTimeZone( 'Europe/Paris' ) ) ) . '</td><td><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'bsc-tv-music', 'clip_id' => $clip_id ), admin_url( 'admin.php' ) ) . '#music-library' ) . '">Modifier</a> <a class="button" href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener">Prévisualiser sur YouTube</a>';
			self::form( 'bsc_tv_music_clip' ); echo '<input type="hidden" name="clip_id" value="' . esc_attr( $clip_id ) . '"><button class="button" name="operation" value="' . ( empty( $row['active'] ) ? 'activate' : 'deactivate' ) . '">' . ( empty( $row['active'] ) ? 'Activer' : 'Désactiver' ) . '</button> <button class="button" name="operation" value="refresh">Vérifier les métadonnées</button> <button class="button" name="operation" value="delete" data-music-delete>Supprimer</button></form></td></tr>';
		}
		echo '</tbody></table></div><p>';
		for ( $n = 1; $n <= ceil( count( $clips ) / 25 ); ++$n ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'bsc-tv-music', 'music_page' => $n ), admin_url( 'admin.php' ) ) . '#music-library' ) . '">' . esc_html( $n ) . '</a> '; }
		echo '</p></section>';
	}
	private static function playlist( $names ) {
		$job = BSC_TV_Music_Playlist::status();
		echo '<form class="bsc-music-playlist" data-music-playlist data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'bsc_tv_music_playlist' ) ) . '" data-job="' . esc_attr( wp_json_encode( $job ) ) . '" data-library="' . esc_url( admin_url( 'admin.php?page=bsc-tv-music' ) ) . '"><h3>Importer une playlist YouTube</h3><p>Collez son lien puis choisissez la catégorie de destination, par exemple BSC Morning Chill. Les nouveaux clips restent inactifs et en attente de validation. Les clips déjà présents reçoivent la catégorie sans perdre leurs réglages.</p>';
		self::input( 'playlist_url', 'Lien de la playlist', $job['url'] ?? '', 'url', 'required placeholder="https://www.youtube.com/playlist?list=…"' );
		self::select( 'playlist_category', 'Catégorie de destination', $job['category'] ?? 'morning', $names );
		echo '<p><button class="button button-primary" type="submit" data-playlist-start>Importer les clips</button> <button class="button" type="button" data-playlist-resume hidden>Reprendre l’import</button></p><p data-playlist-status role="status" aria-live="polite"></p><progress data-playlist-progress value="0" max="100" hidden></progress><p><a class="button" data-playlist-library hidden>Voir les clips importés</a></p><noscript>Activez JavaScript pour lancer cet import par lots.</noscript></form>';
	}

	private static function status( $config, $state, $snapshot ) {
		echo '<section id="music-status"><h2>État actuel</h2><p>Interludes : <strong>' . esc_html( $config['enabled'] ? 'activés' : 'désactivés' ) . '</strong>. Lecteur : ' . esc_html( $snapshot['enabled'] ? 'activé' : 'désactivé' ) . '.</p>';
		if ( $config['enabled'] && empty( BSC_TV_Sync::settings()['live_enabled'] ) ) { echo '<p class="notice notice-warning">Activez la vérification des directs dans BSC TV → Réglages et imports pour permettre leur détection et leur priorité sur la musique.</p>'; }
		$stats = $state['music_stats'] ?? array(); uasort( $stats, static function ( $a, $b ) { return $b['last'] <=> $a['last']; } ); $last = reset( $stats );
		echo '<p>Dernier clip diffusé : ' . esc_html( $last ? $last['artist'] . ' — ' . $last['title'] . ' · ' . wp_date( 'd/m H:i', $last['last'], new DateTimeZone( 'Europe/Paris' ) ) : 'aucun' ) . '</p>';
		$next = null; foreach ( $state['plan'] ?? array() as $row ) { if ( ! empty( $row['music'] ) ) { $next = $row; break; } }
		echo '<p>Prochain interlude : ' . esc_html( $next ? $next['music']['artist'] . ' — ' . $next['music']['title'] . ' · ' . wp_date( 'd/m H:i:s', $next['start'], new DateTimeZone( 'Europe/Paris' ) ) : 'aucun créneau compatible dans la grille préparée' ) . '</p><p>Programme suivant : ' . esc_html( $snapshot['next'][0]['title'] ?? 'aucun' ) . '</p>';
		$report = (array) get_option( BSC_TV_Music::REPORT, array() ); echo '<p>Vérification des métadonnées : ' . esc_html( empty( $report['at'] ) ? 'pas encore exécutée' : wp_date( 'd/m/Y H:i', $report['at'] ) ) . '. ' . esc_html( $report['error'] ?? '' ) . '</p><p>Sans clip éligible, sans deuxième artiste disponible ou sans place suffisante, les émissions continuent normalement. Aucun clip n’est ajouté pour remplir à tout prix.</p></section>';
	}
}
