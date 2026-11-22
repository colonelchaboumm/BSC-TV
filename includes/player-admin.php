<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Player_Admin {
	public static function register() {
		add_action( 'bsc_tv_admin_player', array( __CLASS__, 'link' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_media' ), 30, 2 );
		add_action( 'admin_post_bsc_tv_player_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_bsc_tv_player_compose', array( __CLASS__, 'compose' ) );
		add_action( 'admin_post_bsc_tv_player_queue', array( __CLASS__, 'queue' ) );
		add_action( 'admin_post_bsc_tv_player_schedule', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_bsc_tv_player_import', array( __CLASS__, 'import_replays' ) );
		add_action( 'admin_post_bsc_tv_player_import_key', array( __CLASS__, 'renew_import_key' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'bsc-tv', 'Le live', 'Le live', 'manage_options', 'bsc-tv-live', array( __CLASS__, 'page' ) );
	}

	public static function link() {
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=bsc-tv-live' ) ) . '">Le live · programmation et import Infomaniak</a></p>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>Le live</h1><p><a href="#bsc-player-grid">Programmation</a> · <a href="#bsc-player-diversity">Diversité et musique</a> · <a href="#bsc-tv-player-settings">Réglages du lecteur</a> · <a href="#bsc-player-import">Import Infomaniak</a></p>';
		self::section();
		echo '</div>';
	}

	public static function boxes() {
		add_meta_box( 'bsc-tv-player', 'Diffusion BSC TV 24/7', array( __CLASS__, 'box' ), array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ), 'normal', 'default' );
	}

	private static function field( $name, $label, $value, $type = 'text', $extra = '' ) {
		printf( '<p><label for="bsc-player-%1$s">%2$s</label><br><input class="regular-text" type="%3$s" id="bsc-player-%1$s" name="bsc_player[%1$s]" value="%4$s" %5$s></p>', esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), $extra );
	}

	public static function box( $post ) {
		wp_nonce_field( 'bsc_tv_player_media', 'bsc_player_nonce' );
		if ( BSC_TV_Content::CHANNEL === $post->post_type ) {
			$values = (array) get_post_meta( $post->ID, BSC_TV_Player::CHANNEL, true );
			foreach ( array( 'live' => 'Autoriser ses véritables directs dans le lecteur', 'replays' => 'Autoriser les replays de ses émissions' ) as $key => $label ) {
				printf( '<p><label><input type="checkbox" name="bsc_player[%s]" value="1" %s> %s</label></p>', esc_attr( $key ), checked( $values[ $key ] ?? 1, 1, false ), esc_html( $label ) );
			}
			self::field( 'priority', 'Priorité en cas de plusieurs directs (0 à 100)', $values['priority'] ?? 50, 'number', 'min="0" max="100"' );
			echo '<p class="description">Une fois un direct choisi, il reste à l’antenne jusqu’à sa fin ou à l’expiration de sa confirmation. Les réglages du lecteur ne modifient pas la grille TV.</p>';
			return;
		}
		$values = (array) get_post_meta( $post->ID, BSC_TV_Player::MEDIA, true );
		$data = BSC_TV_Content::data( $post->ID );
		$video = BSC_TV_Sync::state( $post->ID, BSC_TV_Content::youtube_video_id( $data['url'] ?? '' ) )['video'] ?? $data;
		echo '<p><label for="bsc-player-mode">Participation à la rotation</label><br><select id="bsc-player-mode" name="bsc_player[mode]">';
		foreach ( array( 'auto' => 'Automatique · replay terminé confirmé par le collecteur', 'yes' => 'Inclure ce replay dans la programmation', 'no' => 'Exclure du lecteur 24/7' ) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $values['mode'] ?? 'auto', $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		self::field( 'url', 'Lien du replay YouTube ou Twitch /videos/… (facultatif si le lien de l’émission est déjà une vidéo)', $values['url'] ?? '', 'url' );
		self::field( 'duration', 'Durée exacte du replay en secondes', ( $values['duration'] ?? 0 ) ?: ( $video['duration'] ?? '' ), 'number', 'min="1" max="86400" step="1"' );
		$published = ( $values['published_at'] ?? 0 ) ?: ( $video['published_at'] ?? 0 );
		self::field( 'published_at', 'Publication du replay · fuseau du site', $published ? wp_date( 'Y-m-d\TH:i', $published ) : '', 'datetime-local' );
		printf( '<p><label><input type="checkbox" name="bsc_player[premiere]" value="1" %s> Signaler la première diffusion sur BSC TV comme PREMIÈRE</label></p>', checked( ! empty( $values['premiere'] ), true, false ) );
		$resource = BSC_TV_Player::resource( ( $values['url'] ?? '' ) ?: ( $data['url'] ?? '' ) );
		$manual_restriction = $resource && ! empty( $values['age_restricted'] ) && ( $values['restriction_key'] ?? '' ) === BSC_TV_Player::resource_key( $resource );
		printf( '<p><label><input type="checkbox" name="bsc_player[age_restricted]" value="1" %s> Exclure cette vidéo du lecteur : limite d’âge ou lecture bloquée</label></p>', checked( $manual_restriction, true, false ) );
		$access = (array) get_post_meta( $post->ID, BSC_TV_Player::ACCESS, true );
		if ( $resource && ( $access['key'] ?? '' ) === BSC_TV_Player::resource_key( $resource ) && ! empty( $access['age_restricted'] ) ) { echo '<p class="description">YouTube signale une limite d’âge : cette vidéo est automatiquement exclue du lecteur 24/7, même en mode Inclure.</p>'; }
		echo '<p class="description">La durée et la date proviennent du collecteur YouTube lorsqu’elles sont disponibles. Pour Twitch, renseignez le lien du VOD et sa durée. Une URL de chaîne Twitch seule ne permet pas une rediffusion synchronisée. Les brouillons restent exclus. Aucun fichier vidéo n’est importé.</p>';
	}

	public static function save_media( $id, $post ) {
		if ( ! in_array( $post->post_type, array( BSC_TV_Content::SHOW, BSC_TV_Content::CHANNEL ), true ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsc_player_nonce'] ?? '' ) ), 'bsc_tv_player_media' ) ) {
			return;
		}
		$input = (array) wp_unslash( $_POST['bsc_player'] ?? array() );
		if ( BSC_TV_Content::CHANNEL === $post->post_type ) {
			update_post_meta( $id, BSC_TV_Player::CHANNEL, array( 'live' => ! empty( $input['live'] ) ? 1 : 0, 'replays' => ! empty( $input['replays'] ) ? 1 : 0, 'priority' => max( 0, min( 100, (int) ( $input['priority'] ?? 50 ) ) ) ) );
			return;
		}
		$mode = in_array( $input['mode'] ?? '', array( 'auto', 'yes', 'no' ), true ) ? $input['mode'] : 'auto';
		$url = BSC_TV_Content::url( $input['url'] ?? '' );
		$duration = max( 0, min( DAY_IN_SECONDS, (int) ( $input['duration'] ?? 0 ) ) );
		$published = BSC_TV_Content::parse_date( $input['published_at'] ?? '' );
		$actual_url = $url ?: ( BSC_TV_Content::data( $id )['url'] ?? '' );
		if ( ( ! empty( $input['url'] ) && ! $url ) || ( 'yes' === $mode && ( ! BSC_TV_Player::resource( $actual_url ) || ! $duration || ! $published ) ) ) {
			self::message( 'Programmation 24/7 non enregistrée : indiquez un lien vidéo YouTube ou VOD Twitch, une durée et une date de publication valides.' );
			return;
		}
		$resource = BSC_TV_Player::resource( $actual_url );
		update_post_meta( $id, BSC_TV_Player::MEDIA, array( 'mode' => $mode, 'url' => $url, 'duration' => $duration, 'published_at' => $published, 'premiere' => ! empty( $input['premiere'] ), 'age_restricted' => ! empty( $input['age_restricted'] ), 'restriction_key' => $resource && ! empty( $input['age_restricted'] ) ? BSC_TV_Player::resource_key( $resource ) : '' ) );
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( $anchor = 'bsc-tv-player-settings' ) {
		wp_safe_redirect( admin_url( 'admin.php?page=bsc-tv-live#' . $anchor ) );
		exit;
	}

	public static function save_settings() {
		self::authorize( 'bsc_tv_player_settings' );
		$previous = BSC_TV_Player::settings();
		$input = (array) wp_unslash( $_POST['bsc_player'] ?? array() );
		$settings = array( 'enabled' => ! empty( $input['enabled'] ), 'live_priority' => ! empty( $input['live_priority'] ), 'recent' => in_array( $input['recent'] ?? '', array( 'strong', 'balanced', 'rotation' ), true ) ? $input['recent'] : 'strong', 'repeat_hours' => max( 0, min( 168, (float) ( $input['repeat_hours'] ?? 6 ) ) ), 'tolerance' => max( 3, min( 30, (int) ( $input['tolerance'] ?? 8 ) ) ) );
		$settings['max_channel_streak'] = max( 0, min( 10, (int) ( $input['max_channel_streak'] ?? 3 ) ) );
		$settings['enrich_archives'] = ! empty( $input['enrich_archives'] );
		update_option( BSC_TV_Player::SETTINGS, $settings, false );
		if ( $settings['enabled'] && ! $previous['enabled'] ) {
			BSC_TV_Sync::queue( BSC_TV_Sync::DAILY_NOW );
		}
		self::message( 'Réglages du lecteur 24/7 enregistrés.' );
		self::redirect();
	}
	public static function compose_programme() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'compose_forbidden', 'Action réservée aux administrateurs.' ); }
		require_once __DIR__ . '/music-admin.php';
		$ready = count( array_filter( BSC_TV_Music::clips(), array( 'BSC_TV_Music', 'approved' ) ) );
		return BSC_TV_Music_Admin::change( static function () use ( $ready ) {
			$player = (array) get_option( BSC_TV_Player::SETTINGS, array() );
			$player['recent'] = 'rotation'; $player['max_channel_streak'] = 3; $player['enrich_archives'] = true;
			update_option( BSC_TV_Player::SETTINGS, $player, false );
			$music = BSC_TV_Music::settings();
			if ( $ready ) { $music['enabled'] = true; $music['flexible'] = true; update_option( BSC_TV_Music::SETTINGS, $music, false ); }
			delete_transient( BSC_TV_Player::CACHE );
		}, true, true, true );
	}
	public static function compose() {
		self::authorize( 'bsc_tv_player_compose' );
		$result = self::compose_programme();
		self::message( is_wp_error( $result ) ? $result->get_error_message() : 'Grille recomposée : chaînes et archives variées, clips validés selon leurs horaires et animations BSC. Le programme courant et les horaires fixés sont conservés. L’enrichissement des replays se fera au prochain import quotidien Infomaniak.' );
		self::redirect( 'bsc-player-diversity' );
	}
	private static function diversity( $pool, $state, $settings ) {
		$counts = array(); $planned = array(); $music_slots = 0;
		foreach ( $pool as $media ) { if ( empty( $media['music'] ) ) { $counts[ $media['channel_id'] ] = ( $counts[ $media['channel_id'] ] ?? 0 ) + 1; } }
		$bounds = BSC_TV_Player::day_bounds( time(), wp_timezone_string(), 3 );
		foreach ( array_merge( ! empty( $state['vod'] ) ? array( $state['vod'] ) : array(), $state['plan'] ?? array() ) as $row ) {
			if ( $row['start'] >= $bounds[1] || ( $row['end'] ?? 0 ) <= time() ) { continue; }
			if ( ! empty( $row['music'] ) ) { ++$music_slots; continue; }
			$id = $pool[ $row['key'] ]['channel_id'] ?? $row['channel_id'] ?? 0; $planned[ $id ] = ( $planned[ $id ] ?? 0 ) + 1;
		}
		$channels = get_posts( array( 'post_type' => BSC_TV_Content::CHANNEL, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$music = BSC_TV_Music::diagnostic( $state );
		echo '<section id="bsc-player-diversity"><h2>Diversité du catalogue et musique</h2><p>' . esc_html( count( $channels ) . ' chaîne(s) enregistrée(s), ' . count( array_filter( $counts ) ) . ' source(s) avec des replays utilisables. ' . $music['counts']['ready'] . ' clip(s) musical(aux) prêt(s), ' . $music_slots . ' interlude(s) prévu(s) sur les trois journées affichées.' ) . '</p>';
		echo '<p>' . esc_html( $settings['music']['flexible'] ? 'Les clips peuvent être insérés dans les programmes automatiques en respectant leurs catégories et horaires.' : 'La musique utilise uniquement les espaces libres : une grille sans pause ne peut donc pas accueillir de clip.' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsc_tv_player_compose">'; wp_nonce_field( 'bsc_tv_player_compose' );
		echo '<p><button class="button button-primary">Recomposer avec les chaînes et la musique</button></p><p class="description">Utilise toutes les archives disponibles, limite les séries à trois programmes d’une même chaîne quand une autre est disponible, et active l’insertion des clips déjà validés. Les tranches musicales, animations, validations et horaires fixés sont conservés.</p></form>';
		echo '<details><summary>Comprendre pourquoi certaines chaînes ne passent pas</summary><div class="bsc-player-grid-scroll"><table class="widefat striped"><thead><tr><th scope="col">Chaîne</th><th scope="col">Replays utilisables</th><th scope="col">Passages prévus</th><th scope="col">Diagnostic</th></tr></thead><tbody>';
		$api = BSC_TV_Sync::settings();
		foreach ( $channels as $post ) {
			$data = BSC_TV_Content::data( $post->ID ); $config = (array) get_post_meta( $post->ID, BSC_TV_Player::CHANNEL, true ); $report = (array) get_post_meta( $post->ID, BSC_TV_Player_Import::REPORT, true ); $reason = '';
			if ( 'publish' !== $post->post_status || $post->post_password ) { $reason = 'Chaîne non publique : exclue de la diffusion.'; }
			elseif ( ! in_array( $data['platform'] ?? '', array( 'youtube', 'twitch' ), true ) ) { $reason = 'Cette plateforme ne fournit pas de replays intégrables au lecteur.'; }
			elseif ( ! BSC_TV_Content::channel_key( $data['platform'], $data['url'] ?? '' ) ) { $reason = 'URL ou identifiant de chaîne non reconnu.'; }
			elseif ( isset( $config['replays'] ) && ! $config['replays'] ) { $reason = 'Replays désactivés pour cette chaîne.'; }
			elseif ( empty( $counts[ $post->ID ] ) ) {
				$missing = 'youtube' === $data['platform'] ? empty( $api['youtube_key'] ) : empty( $api['twitch_id'] ) || empty( $api['twitch_secret'] );
				$reason = $missing ? 'Identifiants API de la plateforme manquants.' : ( $report['error'] ?? '' );
				$reason = $reason ?: ( empty( $report['at'] ) ? 'Aucun import de replays terminé pour cette chaîne.' : ( empty( $report['done'] ) ? 'Import encore en cours : la progression est conservée pour le prochain passage quotidien.' : 'Aucun replay éligible dans le catalogue : vérifier disponibilité, durée et autorisations de la chaîne.' ) );
			} else { $reason = 'Disponible pour la rotation.'; }
			echo '<tr><td>' . esc_html( get_the_title( $post ) ) . '</td><td>' . esc_html( $counts[ $post->ID ] ?? 0 ) . '</td><td>' . esc_html( $planned[ $post->ID ] ?? 0 ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
		}
		echo '</tbody></table></div></details></section>';
	}

	public static function queue() {
		self::authorize( 'bsc_tv_player_queue' );
		$id = absint( $_POST['show_id'] ?? 0 );
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		$current_key = sanitize_text_field( wp_unslash( $_POST['current_key'] ?? '' ) );
		$result = BSC_TV_Player_Schedule::queue( $operation, $id, $current_key );
		self::message( is_wp_error( $result ) ? $result->get_error_message() : 'Programmation enregistrée. Les visiteurs la recevront au prochain rafraîchissement.' );
		self::redirect();
	}

	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'toplevel_page_bsc-tv', 'bsc-tv_page_bsc-tv-live' ), true ) ) {
			wp_enqueue_style( 'bsc-tv-player-admin', BSC_TV_URL . 'assets/player-admin.css', array(), BSC_TV_VERSION );
		}
	}

	public static function import_replays() {
		self::authorize( 'bsc_tv_player_import' );
		self::message( 'L’import est désormais déclenché une fois par jour par votre tâche Infomaniak. Copiez le lien réservé à cette tâche ci-dessous.' );
		self::redirect( 'bsc-player-import' );
	}

	public static function renew_import_key() {
		self::authorize( 'bsc_tv_player_import_key' );
		BSC_TV_Player_Daily::key( true );
		self::message( 'Le lien réservé à Infomaniak a été renouvelé. Mettez à jour votre tâche quotidienne avec le nouveau lien.' );
		self::redirect( 'bsc-player-import' );
	}

	private static function imports() {
		$state = (array) get_option( BSC_TV_Player_Import::STATE, array() );
		$daily = (array) get_option( BSC_TV_Player_Daily::REPORT, array() );
		if ( 'running' === ( $daily['status'] ?? '' ) && time() - $daily['started_at'] > 10 * MINUTE_IN_SECONDS ) { $daily['status'] = 'partial'; }
		$channels = BSC_TV_Player_Import::channels();
		$key = BSC_TV_Player_Daily::key();
		$url = $key ? add_query_arg( 'key', $key, set_url_scheme( rest_url( 'bsc-tv/v1/import-daily' ), 'https' ) ) : '';
		$labels = array( 'running' => 'En cours', 'completed' => 'Terminé', 'completed_with_errors' => 'Terminé avec des erreurs', 'partial' => 'Partiel · la progression reprendra au prochain passage quotidien' );
		?>
		<div id="bsc-player-import">
			<h2>Import quotidien · Infomaniak</h2>
			<p>Infomaniak récupère les vidéos des deux derniers mois une fois par jour. Cet import ne crée plus de tâche WP-Cron et ne redémarre pas à chaque modification de chaîne. Les contrôles des vrais directs restent indépendants.</p>
			<p>Dans le Manager Infomaniak : site → Outils avancés → Planificateur de tâches. Créez une tâche avec ce lien, <strong>une fois par jour</strong>, par exemple à 03:00 dans le fuseau du site. Le plugin refuse un deuxième import le même jour.</p>
			<p><label for="bsc-player-import-url">Lien confidentiel à copier dans Infomaniak</label><br><input id="bsc-player-import-url" class="large-text code" type="text" readonly value="<?php echo esc_attr( $url ); ?>" autocomplete="off" spellcheck="false"></p>
			<p class="description">Conservez ce lien dans votre tâche Infomaniak. La tâche exécute les lots successifs dans le même passage ; si une limite serveur interrompt le traitement, sa progression est conservée pour le lendemain.</p>
			<details><summary>Commande pour un Serveur Cloud avec WP-CLI</summary><p><code>wp --path=/chemin/vers/wordpress bsc-tv import</code> · à programmer une fois par jour. Cette commande utilise la même limite quotidienne.</p></details>
			<p><?php echo esc_html( sprintf( '%d chaîne(s) compatible(s), avec un accès API et les replays autorisés.', count( $channels ) ) ); ?> <?php if ( ! $channels ) : ?>Vérifiez la clé YouTube, le Client ID et le secret Twitch, ainsi que la publication des chaînes.<?php endif; ?></p>
			<?php if ( $daily ) : ?><p><strong><?php echo esc_html( $labels[ $daily['status'] ] ?? $daily['status'] ); ?></strong> · <?php echo esc_html( wp_date( 'd/m/Y H:i', $daily['started_at'] ) ); ?>. <?php echo esc_html( sprintf( '%d lot(s) traité(s), %d chaîne(s) restante(s).', $daily['pages'] ?? 0, $daily['remaining'] ?? 0 ) ); ?></p><?php else : ?><p>Aucun passage Infomaniak enregistré. Le lecteur utilisera les replays déjà disponibles en attendant le premier import.</p><?php endif; ?>
			<?php if ( $state ) : ?><p><?php echo esc_html( sprintf( '%d émission(s) ajoutée(s), %d actualisée(s), %d conservée(s) sans modification ; %d chaîne(s) en erreur.', $state['created'], $state['updated'], $state['skipped'], $state['errors'] ) ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bsc_tv_player_import_key"><?php wp_nonce_field( 'bsc_tv_player_import_key' ); ?><button class="button" type="submit">Renouveler le lien confidentiel</button></form>
			<?php if ( $channels ) : ?><details><summary>Résultats par chaîne</summary>
				<?php foreach ( $channels as $channel ) : $report = (array) get_post_meta( $channel['id'], BSC_TV_Player_Import::REPORT, true ); ?>
					<p><strong><?php echo esc_html( get_the_title( $channel['id'] ) ); ?></strong> : <?php echo esc_html( ! $report ? 'En attente du passage quotidien.' : sprintf( '%d ajoutée(s), %d actualisée(s), %d ignorée(s).', $report['created'] ?? 0, $report['updated'] ?? 0, $report['skipped'] ?? 0 ) ); ?> <?php echo esc_html( $report['error'] ?? '' ); ?></p>
				<?php endforeach; ?>
			</details><?php endif; ?>
		</div>
		<?php
	}

	public static function schedule() {
		self::authorize( 'bsc_tv_player_schedule' );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$id = sanitize_key( wp_unslash( $_POST['slot_id'] ?? '' ) );
		$fingerprint = sanitize_text_field( wp_unslash( $_POST['plan_fingerprint'] ?? '' ) );
		$value = 'move' === $operation ? BSC_TV_Content::parse_date( wp_unslash( $_POST['starts_at'] ?? '' ) ) : absint( is_scalar( $_POST['show_id'] ?? null ) ? $_POST['show_id'] : 0 );
		$result = BSC_TV_Player_Schedule::update( $operation, $id, $value, $fingerprint );
		self::message( is_wp_error( $result ) ? $result->get_error_message() : 'Grille enregistrée. Les programmes suivants sont recalés selon les durées, disponibilités et délais de rediffusion. L’émission en cours est conservée.' );
		self::redirect( 'bsc-player-grid' );
	}

	private static function grid_url( $days, $id = '', $mode = '' ) {
		$args = array( 'page' => 'bsc-tv-live', 'player_days' => $days );
		if ( $id ) { $args += array( 'bsc_player_slot' => $id, 'bsc_player_edit' => $mode ); }
		return add_query_arg( $args, admin_url( 'admin.php' ) ) . ( $id ? '#bsc-player-edit-slot' : '#bsc-player-grid' );
	}

	private static function editor( $state, $pool, $days, $now ) {
		$id = sanitize_key( wp_unslash( $_GET['bsc_player_slot'] ?? '' ) );
		$mode = sanitize_key( wp_unslash( $_GET['bsc_player_edit'] ?? '' ) );
		if ( ! $id || ! in_array( $mode, array( 'move', 'replace' ), true ) ) { return; }
		$index = array_search( $id, array_column( $state['plan'] ?? array(), 'id' ), true );
		if ( false === $index || ( empty( $state['live'] ) && $state['plan'][ $index ]['start'] < $now ) ) {
			echo '<p class="notice notice-warning">Ce programme a commencé ou a quitté la grille. Choisissez un programme à venir.</p>';
			return;
		}
		$row = $state['plan'][ $index ];
		$media = $pool[ $row['key'] ];
		$minimum = max( $now, $index ? $state['plan'][ $index - 1 ]['end'] : ( $state['vod']['end'] ?? $now ) );
		?>
		<div id="bsc-player-edit-slot" class="bsc-player-editor">
			<h4><?php echo esc_html( ( 'move' === $mode ? 'Décaler · ' : 'Remplacer · ' ) . $media['title'] ); ?></h4>
			<p><?php echo esc_html( wp_date( 'd/m H:i:s', $row['start'] ) . ' – ' . wp_date( 'H:i:s', $row['end'] ) . ' · ' . wp_timezone_string() ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsc_tv_player_schedule"><input type="hidden" name="operation" value="<?php echo esc_attr( $mode ); ?>"><input type="hidden" name="slot_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="plan_fingerprint" value="<?php echo esc_attr( BSC_TV_Player_Schedule::fingerprint( $state ) ); ?>"><?php wp_nonce_field( 'bsc_tv_player_schedule' ); ?>
				<?php if ( 'move' === $mode ) : ?>
					<p><label for="bsc-player-slot-start">Nouvelle heure de début · fuseau du site</label><br><input id="bsc-player-slot-start" type="datetime-local" name="starts_at" step="1" required value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i:s', $row['start'] ) ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i:s', $minimum ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i:s', $now + 72 * HOUR_IN_SECONDS - 1 ) ); ?>"></p>
					<p class="description">Ce programme et les suivants seront décalés. Il peut être avancé dans l’espace libre après le précédent. Un décalage plus tardif peut créer une pause de programmation.</p>
				<?php else : ?>
					<p><label for="bsc-player-slot-replacement">Replay de remplacement</label><br><select id="bsc-player-slot-replacement" name="show_id" required><?php foreach ( $pool as $candidate ) : if ( ! empty( $candidate['music'] ) ) { continue; } ?><option value="<?php echo esc_attr( $candidate['show_id'] ); ?>" <?php selected( $candidate['show_id'], $row['show_id'] ); ?>><?php echo esc_html( $candidate['title'] . ' · ' . sprintf( '%dh %02dmin %02ds', intdiv( $candidate['duration'], 3600 ), intdiv( $candidate['duration'] % 3600, 60 ), $candidate['duration'] % 60 ) ); ?></option><?php endforeach; ?></select></p>
					<p class="description">La durée du nouveau replay recale les programmes suivants. Sa disponibilité et le délai entre rediffusions restent respectés.</p>
				<?php endif; ?>
				<button class="button button-primary" type="submit"><?php echo 'move' === $mode ? 'Enregistrer le décalage' : 'Enregistrer le remplacement'; ?></button> <a class="button" href="<?php echo esc_url( self::grid_url( $days ) ); ?>">Annuler</a>
			</form>
		</div>
		<?php
	}

	private static function grid( $prepared, $pool, $snapshot ) {
		$now = time();
		?>
		<div id="bsc-player-grid" class="bsc-player-admin-grid">
			<h2>Programmation · aujourd’hui et les deux journées à venir</h2>
			<p><?php echo esc_html( 'Fuseau : ' . wp_timezone_string() ); ?>. La grille est enregistrée et suivie par le lecteur. Les chaînes et les émissions sont mélangées ; les programmes à venir peuvent être décalés ou remplacés.</p>
			<?php if ( ! $snapshot['enabled'] ) : ?><p class="notice notice-info">Le lecteur est désactivé. Cette programmation est préparée pour son activation.</p><?php endif; ?>
			<?php if ( ! empty( $snapshot['current']['live'] ) ) : ?><p class="notice notice-warning">Un véritable direct est en cours. Les horaires suivants sont prévisionnels et seront décalés à sa fin.</p><?php endif; ?>
			<?php if ( is_wp_error( $prepared ) ) : ?><p><?php echo esc_html( $prepared->get_error_message() ); ?></p><?php else : ?>
				<?php self::editor( $prepared, $pool, 3, $now ); ?>
				<?php $offset = 0; foreach ( BSC_TV_Player_Schedule::days( $prepared, $now, wp_timezone_string() ) as $group ) :
					$channel_ids = array_unique( array_filter( array_column( $group['rows'], 'channel_id' ) ) ); ?>
					<details class="bsc-player-grid-day" open><summary><?php echo esc_html( array( 'Aujourd’hui', 'Demain', 'Après-demain' )[ $offset++ ] . ' · ' . wp_date( 'l j F Y', $group['start'] ) . ' · ' . count( $channel_ids ) . ' chaîne(s)' ); ?></summary>
					<?php if ( ! $group['rows'] ) : ?><p class="bsc-player-empty-day">Aucun programme disponible pour cette journée. Vérifiez le catalogue, les exclusions et le délai entre rediffusions.</p><?php else : ?>
					<div class="bsc-player-grid-scroll"><table class="widefat striped"><thead><tr><th scope="col">Diffusion</th><th scope="col">Émission et chaîne</th><th scope="col">État</th><th scope="col">Actions</th></tr></thead><tbody>
					<?php $previous_end = null; foreach ( $group['rows'] as $row ) :
						$media = $pool[ $row['key'] ] ?? array();
						$title = $row['title'] ?? $media['title'] ?? get_the_title( $row['show_id'] ?? 0 );
						$channel = ! empty( $row['channel_id'] ) ? get_the_title( $row['channel_id'] ) : ( $media['creator'] ?? 'Créateur non renseigné' );
						if ( ! empty( $row['music'] ) ) {
							$title = 'MUSIC BREAK · ' . $row['music']['artist'] . ' — ' . $row['music']['title'];
							$channel = sprintf( 'Animation BSC %d s + clip %d s + animation BSC %d s', $row['music']['entry'], $row['music']['duration'], $row['music']['exit'] );
						}
						$editable = 'future' === $row['phase'] && ! empty( $row['id'] ) && empty( $row['music'] ); ?>
						<?php if ( null !== $previous_end && $row['display_start'] > max( $previous_end, $now ) && empty( $snapshot['current']['live'] ) ) : ?><tr class="bsc-player-grid-gap"><td colspan="4"><?php echo esc_html( 'Pause de programmation · ' . wp_date( 'H:i:s', max( $previous_end, $now ) ) . ' → ' . wp_date( 'H:i:s', $row['display_start'] ) ); ?></td></tr><?php endif; ?>
						<tr class="bsc-player-phase-<?php echo esc_attr( $row['phase'] ); ?>"><td class="bsc-player-grid-time"><?php echo esc_html( wp_date( 'H:i:s', $row['display_start'] ) . ' → ' . ( $row['end'] ? wp_date( 'H:i:s', $row['display_end'] ) : 'En direct' ) ); ?><?php if ( $row['continued'] ) : ?><br><small>Suite du programme précédent</small><?php endif; ?></td><td><strong><?php echo esc_html( $title ?: 'Émission retirée' ); ?></strong><br><?php echo esc_html( $channel ); ?></td><td><?php echo esc_html( 'current' === $row['phase'] ? ( ! $snapshot['enabled'] ? 'Préparé' : ( ! empty( $row['live'] ) ? '● LIVE' : 'En cours' ) ) : ( 'past' === $row['phase'] ? 'Diffusé' : 'À venir' ) ); ?></td><td class="bsc-player-grid-actions"><?php if ( $editable ) : ?><a class="button" href="<?php echo esc_url( self::grid_url( 3, $row['id'], 'move' ) ); ?>">Décaler</a> <a class="button" href="<?php echo esc_url( self::grid_url( 3, $row['id'], 'replace' ) ); ?>">Remplacer</a><?php else : ?>—<?php endif; ?></td></tr>
					<?php $previous_end = max( $previous_end ?? 0, $row['display_end'] ); endforeach; ?>
					</tbody></table></div><?php endif; ?></details>
				<?php endforeach; ?>
				<?php if ( count( $prepared['plan'] ?? array() ) >= BSC_TV_Player_Schedule::MAX_SLOTS ) : ?><p class="description">Grille limitée à 500 programmes. Des vidéos très courtes peuvent réduire la période couverte.</p><?php endif; ?>
				<p class="description">Les programmes déjà diffusés sont conservés à partir de cette version. Les émissions antérieures à l’installation ne sont pas reconstituées.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function message( $text ) {
		set_transient( 'bsc_tv_player_notice_' . get_current_user_id(), $text, MINUTE_IN_SECONDS );
	}

	public static function notice() {
		$key = 'bsc_tv_player_notice_' . get_current_user_id();
		$text = get_transient( $key );
		if ( $text ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $text ) );
		}
	}

	public static function section() {
		$settings = BSC_TV_Player::settings();
		$snapshot = BSC_TV_Player::snapshot();
		$pool = BSC_TV_Player::media();
		$prepared = BSC_TV_Player_Schedule::prepare();
		$state = (array) get_option( BSC_TV_Player::STATE, array() );
		?>
		<h2>À l’antenne</h2>
		<p><?php echo esc_html( ! $settings['enabled'] ? 'Lecteur désactivé.' : ( $snapshot['current'] ? ( $snapshot['current']['live'] ? '● LIVE · ' : '' ) . $snapshot['current']['title'] . ' · ' . $snapshot['current']['creator'] : 'Aucun programme disponible maintenant.' ) ); ?></p>
		<?php if ( $snapshot['current'] && ! $snapshot['current']['live'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bsc_tv_player_queue"><input type="hidden" name="operation" value="skip"><input type="hidden" name="current_key" value="<?php echo esc_attr( $snapshot['current']['key'] . ':' . ( $snapshot['current']['interlude_start'] ?? $snapshot['current']['start'] ) ); ?>"><?php wp_nonce_field( 'bsc_tv_player_queue' ); ?><button class="button" type="submit">Passer au programme suivant</button></form>
		<?php endif; ?>
		<?php self::grid( $prepared, $pool, $snapshot ); ?>
		<?php self::diversity( $pool, $prepared, $settings ); ?>
		<p><?php echo esc_html( sprintf( '%d replay(s) utilisable(s).', count( array_filter( $pool, static function ( $item ) { return empty( $item['music'] ); } ) ) ) ); ?> <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BSC_TV_Content::SHOW ) ); ?>">Gérer les émissions et les archives</a></p>
		<?php if ( count( array_unique( array_map( array( 'BSC_TV_Player', 'mix_channel' ), $pool ) ) ) < 2 ) : ?><p class="notice notice-info">L’alternance nécessite au moins deux chaînes avec des replays utilisables. Consultez les résultats d’import par chaîne ci-dessous.</p><?php endif; ?>
		<?php if ( $pool ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bsc_tv_player_queue"><?php wp_nonce_field( 'bsc_tv_player_queue' ); ?><label for="bsc-player-queue">Priorité à la prochaine transition</label><br><select id="bsc-player-queue" name="show_id"><?php foreach ( $pool as $item ) : if ( ! empty( $item['music'] ) ) { continue; } ?><option value="<?php echo esc_attr( $item['show_id'] ); ?>"><?php echo esc_html( $item['title'] ); ?></option><?php endforeach; ?></select> <button class="button" type="submit" name="operation" value="next">Programmer ensuite</button> <button class="button" type="submit" name="operation" value="clear">Remélanger la programmation automatique</button></form>
			<p class="description">Remélanger remplace les choix futurs, y compris les modifications manuelles. L’émission en cours reste stable.</p>
		<?php endif; ?>
		<section id="bsc-tv-player-settings">
			<h2>Réglages du lecteur 24/7</h2>
			<p>La rotation mélange les chaînes et les épisodes disponibles et équilibre leur temps de présence dans la journée. Les choix manuels, les dates de publication et les délais entre rediffusions sont respectés. La grille reste commune à tous les visiteurs.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsc_tv_player_settings"><?php wp_nonce_field( 'bsc_tv_player_settings' ); ?>
				<p><label><input type="checkbox" name="bsc_player[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> Activer le lecteur 24/7</label></p>
				<p><label><input type="checkbox" name="bsc_player[live_priority]" value="1" <?php checked( $settings['live_priority'] ); ?>> Donner la priorité aux véritables directs confirmés</label></p>
				<p class="description">Le contrôle des directs existant doit être activé dans Réglages et imports. Il reste indépendant de l’import quotidien des replays.</p>
				<p><label for="bsc-player-recent">Priorité aux nouveautés parmi les programmes disponibles</label><br><select id="bsc-player-recent" name="bsc_player[recent]">
					<?php foreach ( array( 'strong' => 'Forte · priorité aux nouveautés', 'balanced' => 'Équilibrée · une semaine puis archives', 'rotation' => 'Rotation · toutes les archives' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['recent'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></p>
				<?php self::field( 'repeat_hours', 'Délai minimum avant la rediffusion d’un même replay (heures)', $settings['repeat_hours'], 'number', 'min="0" max="168" step="0.5"' ); ?>
				<?php self::field( 'tolerance', 'Tolérance de synchronisation (secondes)', $settings['tolerance'], 'number', 'min="3" max="30"' ); ?>
				<?php self::field( 'max_channel_streak', 'Maximum de programmes consécutifs de la même chaîne (0 : sans limite)', $settings['max_channel_streak'], 'number', 'min="0" max="10"' ); ?>
				<p><label><input type="checkbox" name="bsc_player[enrich_archives]" value="1" <?php checked( $settings['enrich_archives'] ); ?>> Enrichir les chaînes peu fournies : jusqu’à 12 mois d’archives pour atteindre environ huit replays utilisables par chaîne.</label></p>
				<?php submit_button( 'Enregistrer le lecteur 24/7' ); ?>
			</form>
		</section>
		<?php self::imports(); ?>
		<?php
	}
}
