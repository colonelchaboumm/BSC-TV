<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Admin {
	const MAX_IMPORT_BYTES = 1048576;
	const MAX_IMPORT_ROWS = 500;

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . BSC_TV_Content::CHANNEL, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'save_post_' . BSC_TV_Content::SHOW, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_post_bsc_tv_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_bsc_tv_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_bsc_tv_sync_now', array( __CLASS__, 'sync_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_bsc_tv_find_bdd', array( __CLASS__, 'find_bdd' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . BSC_TV_Content::CHANNEL . '_posts_columns', array( __CLASS__, 'channel_columns' ) );
		add_action( 'manage_' . BSC_TV_Content::CHANNEL . '_posts_custom_column', array( __CLASS__, 'channel_column' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page( 'BSC TV', 'BSC TV', 'manage_options', 'bsc-tv', array( __CLASS__, 'page' ), 'dashicons-video-alt3' );
		add_submenu_page( 'bsc-tv', 'Réglages et imports', 'Réglages et imports', 'manage_options', 'bsc-tv', array( __CLASS__, 'page' ) );
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( $screen && 'post' === $screen->base && in_array( $screen->post_type, array( BSC_TV_Content::CHANNEL, BSC_TV_Content::SHOW ), true ) ) {
			wp_enqueue_script( 'bsc-tv-admin', BSC_TV_URL . 'assets/admin.js', array(), BSC_TV_VERSION, true );
		}
	}

	public static function boxes() {
		add_meta_box( 'bsc-tv-data', 'Détails BSC TV', array( __CLASS__, 'box' ), array( BSC_TV_Content::CHANNEL, BSC_TV_Content::SHOW ), 'normal', 'high' );
	}

	public static function channel_columns( $columns ) {
		return array_slice( $columns, 0, 2, true ) + array( 'bsc_tv_bdd' => 'Fiche créateur BSC BDD' ) + $columns;
	}

	public static function channel_column( $column, $post_id ) {
		if ( 'bsc_tv_bdd' !== $column ) {
			return;
		}
		$bdd_id = BSC_TV_Content::data( $post_id )['bdd_id'] ?? 0;
		$profile = BSC_TV_Content::profile( $bdd_id );
		if ( $profile ) {
			printf( '<a href="%s">%s</a>', esc_url( $profile['permalink'] ), esc_html( $profile['name'] ) );
		} elseif ( $bdd_id ) {
			echo esc_html( sprintf( 'Fiche #%d indisponible', $bdd_id ) );
		} else {
			echo 'Aucune association';
		}
		if ( $edit = get_edit_post_link( $post_id, 'raw' ) ) {
			printf( '<div class="row-actions"><a href="%s">%s</a></div>', esc_url( $edit . '#bsc-tv-profile' ), $profile ? 'Modifier l’association' : 'Associer une fiche' );
		}
	}

	private static function field( $key, $label, $value, $type = 'text' ) {
		?>
		<p><label for="bsc-tv-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
		<input class="regular-text" id="bsc-tv-<?php echo esc_attr( $key ); ?>" name="bsc_tv[<?php echo esc_attr( $key ); ?>]" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>"></p>
		<?php
	}

	private static function choice( $key, $label, $value, $options ) {
		?>
		<p><label for="bsc-tv-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
		<select id="bsc-tv-<?php echo esc_attr( $key ); ?>" name="bsc_tv[<?php echo esc_attr( $key ); ?>]">
			<?php foreach ( $options as $option => $text ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $option, $value ); ?>><?php echo esc_html( $text ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<?php
	}

	public static function box( $post ) {
		$data = BSC_TV_Content::data( $post->ID );
		$channel = BSC_TV_Content::CHANNEL === $post->post_type;
		wp_nonce_field( 'bsc_tv_save', 'bsc_tv_nonce' );
		if ( ! $channel ) {
			self::channel_field( $data['channel_id'] ?? 0 );
		}
		self::field( 'url', $channel ? 'URL officielle de la chaîne' : 'URL pour regarder le direct', $data['url'] ?? '', 'url' );
		self::choice( 'platform', 'Plateforme', $data['platform'] ?? '', array( '' => 'Détecter depuis l’URL' ) + BSC_TV_Content::platforms() );
		self::field( 'source', 'Lien de vérification ou d’annonce', $data['source'] ?? '', 'url' );
		if ( $channel ) {
			self::field( 'country', 'Pays', $data['country'] ?? '' );
			self::field( 'language', 'Langue', $data['language'] ?? '' );
			self::field( 'activity', 'Activité observée', $data['activity'] ?? '' );
			self::field( 'notes', 'Notes de vérification', $data['notes'] ?? '' );
			self::field( 'keywords', 'Filtre facultatif, mots-clés séparés par des virgules', $data['keywords'] ?? '' );
			self::field( 'feed_url', 'URL de l’agenda ou du flux public', $data['feed_url'] ?? '', 'url' );
			echo '<p class="description">Formats compatibles : iCalendar (.ics), RSS avec dates d’événement ou page d’agenda structurée en JSON-LD.</p>';
			self::field( 'feed_timezone', 'Fuseau de l’agenda pour les horaires sans indication (ex. Europe/Berlin)', $data['feed_timezone'] ?? '' );
			if ( current_user_can( 'manage_options' ) ) {
				printf( '<p><label><input type="checkbox" name="bsc_tv[feed_official]" value="1" %s> Publier automatiquement les annonces confirmées de cette source officielle</label></p>', checked( 1, $data['feed_official'] ?? 0, false ) );
			}
			$report = get_post_meta( $post->ID, '_bsc_tv_feed_report', true );
			if ( $report ) {
				printf( '<p>Dernière lecture de l’agenda : %s</p>', esc_html( $report ) );
			}
			$key = BSC_TV_Content::channel_key( $data['platform'] ?? '', $data['url'] ?? '' );
			$state = BSC_TV_Sync::state( $post->ID, $key );
			echo '<p>' . esc_html( $key ? 'Publiez cette chaîne pour l’inclure dans la synchronisation de sa plateforme.' : 'Les émissions de cette chaîne peuvent être ajoutées manuellement.' ) . '</p>';
			foreach ( array( 'schedule_error', 'live_error' ) as $field ) {
				if ( ! empty( $state[ $field ] ) ) {
					echo '<p><strong>' . esc_html( $state[ $field ] ) . '</strong></p>';
				}
			}
		} else {
			self::field( 'creator', 'Nom du créateur affiché sans fiche liée', $data['creator'] ?? '' );
			self::field( 'starts_at', 'Début', empty( $data['start'] ) ? '' : wp_date( 'Y-m-d\TH:i', $data['start'] ), 'datetime-local' );
			self::field( 'ends_at', 'Fin', empty( $data['end'] ) ? '' : wp_date( 'Y-m-d\TH:i', $data['end'] ), 'datetime-local' );
			echo '<p class="description">Fuseau du site ' . esc_html( wp_timezone_string() ) . '.</p>';
			$statuses = array( 'upcoming' => 'À venir', 'live' => 'En direct (jusqu’à l’heure de fin)', 'cancelled' => 'Annulée' );
			if ( BSC_TV_Content::ARCHIVED === $post->post_status ) {
				$statuses['ended'] = 'Terminée';
			}
			self::choice( 'status', 'État éditorial', $data['status'] ?? 'upcoming', $statuses );
			self::field( 'image', 'URL de l’image si aucun visuel WordPress n’est sélectionné', $data['image'] ?? '', 'url' );
		}
		self::profile_field( $data['bdd_id'] ?? 0, $channel );
	}

	private static function channel_field( $selected_id ) {
		$selected_id = absint( $selected_id );
		$platforms = BSC_TV_Content::platforms();
		$options = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $channel ) {
			$data = BSC_TV_Content::data( $channel->ID );
			if ( ! empty( $data['url'] ) ) {
				$options[ $channel->ID ] = sprintf( '%s · %s (#%d)', get_the_title( $channel ), $platforms[ $data['platform'] ] ?? 'Autre', $channel->ID );
			}
		}
		natcasesort( $options );
		if ( $selected_id && ! isset( $options[ $selected_id ] ) ) {
			$options[ $selected_id ] = sprintf( 'Chaîne #%d indisponible', $selected_id );
		}
		self::choice( 'channel_id', 'Chaîne du créateur', $selected_id, array( 0 => 'Détecter la chaîne à partir du lien' ) + $options );
		echo '<p class="description">Les émissions reprennent la fiche BSC BDD de leur chaîne. Vous pouvez choisir une autre fiche pour cette émission.</p>';
		if ( isset( $options[ $selected_id ] ) && BSC_TV_Content::public_channel( $selected_id ) ) {
			$profile = BSC_TV_Content::profile( BSC_TV_Content::data( $selected_id )['bdd_id'] ?? 0 );
			if ( $profile ) {
				printf( '<p>Fiche de la chaîne enregistrée <a href="%s">%s</a></p>', esc_url( $profile['permalink'] ), esc_html( $profile['name'] ) );
			} else {
				echo '<p>Cette chaîne n’a pas de fiche BSC BDD accessible.</p>';
			}
		}
	}

	private static function profile_field( $bdd_id, $channel ) {
		$profile = BSC_TV_Content::profile( $bdd_id );
		?>
		<div id="bsc-tv-profile">
			<h3><?php echo $channel ? 'Fiche créateur BSC BDD' : 'Fiche BSC BDD propre à cette émission'; ?></h3>
			<p class="description"><?php echo $channel ? 'Associez une fiche publiée pour afficher son nom, son avatar et son lien dans le programme. Plusieurs chaînes peuvent partager la même fiche.' : 'Laissez ce champ vide pour reprendre la fiche de la chaîne.'; ?></p>
			<?php if ( function_exists( 'bsc_bdd_query_entities' ) ) : ?>
				<p><label for="bsc-tv-bdd-search">Rechercher une fiche publiée par son nom</label><br>
				<input class="regular-text" type="search" id="bsc-tv-bdd-search" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bsc_tv_bdd' ) ); ?>" autocomplete="off">
				<br><select id="bsc-tv-bdd-results" aria-label="Fiches BSC BDD trouvées" hidden></select><br><span id="bsc-tv-bdd-status" role="status"></span></p>
			<?php else : ?>
				<p class="description">Activez BSC BDD pour rechercher une fiche et afficher son lien.</p>
			<?php endif; ?>
			<?php self::field( 'bdd_id', 'Identifiant de la fiche BSC BDD', $bdd_id ?: '', 'number' ); ?>
			<p id="bsc-tv-bdd-current" aria-live="polite">
				<?php if ( $profile ) : ?>
					Fiche liée <a href="<?php echo esc_url( $profile['permalink'] ); ?>"><?php echo esc_html( $profile['name'] ); ?></a>
				<?php elseif ( $bdd_id ) : ?>
					<?php echo esc_html( sprintf( 'Fiche #%d indisponible.', $bdd_id ) ); ?>
				<?php endif; ?>
			</p>
			<p <?php echo $bdd_id ? '' : 'hidden'; ?>><button class="button" type="button" id="bsc-tv-bdd-clear">Retirer la fiche</button></p>
		</div>
		<?php
	}

	public static function find_bdd() {
		check_ajax_referer( 'bsc_tv_bdd', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) || ! function_exists( 'bsc_bdd_query_entities' ) ) {
			wp_send_json_error( null, 403 );
		}
		$term = substr( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ), 0, 120 );
		$results = array();
		if ( strlen( $term ) >= 2 ) {
			foreach ( bsc_bdd_query_entities( array( 'search' => $term, 'status' => 'publish', 'limit' => 20 ) ) as $row ) {
				if ( ! get_post_field( 'post_password', $row['id'] ) ) {
					$results[] = array( 'id' => $row['id'], 'name' => wp_strip_all_tags( $row['name'] ), 'type' => implode( ', ', $row['type_names'] ?? array() ) );
				}
			}
		}
		wp_send_json_success( $results );
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['bsc_tv_nonce'], $_POST['bsc_tv'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsc_tv_nonce'] ) ), 'bsc_tv_save' ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = (array) wp_unslash( $_POST['bsc_tv'] );
		$data = BSC_TV_Content::SHOW === $post->post_type ? BSC_TV_Content::show_data( $input ) : BSC_TV_Content::channel_data( $input );
		if ( is_wp_error( $data ) || empty( $data['url'] ) ) {
			self::message( is_wp_error( $data ) ? $data->get_error_message() : 'Une URL de chaîne valide est nécessaire.', 'error' );
			return;
		}
		$previous = BSC_TV_Content::data( $post_id );
		if ( BSC_TV_Content::CHANNEL === $post->post_type && ! current_user_can( 'manage_options' ) ) {
			$data['feed_official'] = $data['feed_url'] === ( $previous['feed_url'] ?? '' ) ? ( $previous['feed_official'] ?? 0 ) : 0;
		}
		$channel_id = $data['channel_id'] ?? 0;
		if ( $channel_id && $channel_id !== ( $previous['channel_id'] ?? 0 ) && ! BSC_TV_Content::public_channel( $channel_id ) ) {
			self::message( 'Sélectionnez une chaîne BSC TV publiée.', 'error' );
			return;
		}
		$bdd_id = $data['bdd_id'];
		if ( $bdd_id && $bdd_id !== ( $previous['bdd_id'] ?? 0 ) && function_exists( 'bsc_bdd_get_entity' ) && ! BSC_TV_Content::profile( $bdd_id ) ) {
			self::message( 'Sélectionnez une fiche BSC BDD publique.', 'error' );
			return;
		}
		if ( isset( $previous['origin_channel'] ) ) {
			$data['origin_channel'] = $previous['origin_channel'];
		}
		update_post_meta( $post_id, BSC_TV_Content::DATA, wp_slash( $data ) );
		if ( 'publish' === $post->post_status && get_post_meta( $post_id, BSC_TV_Library::FEED, true ) ) {
			update_post_meta( $post_id, BSC_TV_Library::AUTO, 0 );
		}
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=bsc-tv' ) );
		exit;
	}

	private static function message( $message, $type = 'success' ) {
		set_transient( 'bsc_tv_notice_' . get_current_user_id(), array( $message, $type ), MINUTE_IN_SECONDS );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ( 'toplevel_page_bsc-tv' !== $screen->id && ! in_array( $screen->post_type, array( BSC_TV_Content::CHANNEL, BSC_TV_Content::SHOW ), true ) ) ) {
			return;
		}
		$key = 'bsc_tv_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}
	}

	private static function secrets() {
		return array( 'youtube_key' => 'Clé YouTube Data API', 'twitch_id' => 'Client ID Twitch', 'twitch_secret' => 'Secret Twitch', 'kick_id' => 'Client ID Kick', 'kick_secret' => 'Secret Kick' );
	}

	public static function save_settings() {
		self::authorize( 'bsc_tv_settings' );
		$current = (array) get_option( BSC_TV_Sync::SETTINGS, array() );
		$input = (array) wp_unslash( $_POST['bsc_tv'] ?? array() );
		foreach ( self::secrets() as $key => $label ) {
			if ( defined( 'BSC_TV_' . strtoupper( $key ) ) ) {
				continue;
			}
			if ( ! empty( $input[ 'clear_' . $key ] ) ) {
				unset( $current[ $key ] );
			} elseif ( ! empty( $input[ $key ] ) ) {
				$current[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		$current['youtube_search_limit'] = min( 10000, max( 1, absint( $input['youtube_search_limit'] ?? 90 ) ) );
		$current['live_enabled'] = empty( $input['live_enabled'] ) ? 0 : 1;
		update_option( BSC_TV_Sync::SETTINGS, $current, false );
		BSC_TV_Sync::schedule();
		self::message( 'Réglages enregistrés.' );
		self::redirect();
	}

	public static function sync_now() {
		self::authorize( 'bsc_tv_sync_now' );
		$live = 'live' === ( $_POST['sync_mode'] ?? '' );
		if ( $live && empty( BSC_TV_Sync::settings()['live_enabled'] ) ) {
			self::message( 'Activez d’abord la vérification des directs.', 'error' );
		} elseif ( BSC_TV_Sync::queue( $live ? BSC_TV_Sync::LIVE_NOW : BSC_TV_Sync::DAILY_NOW, 0 ) ) {
			spawn_cron();
			self::message( $live ? 'Vérification des directs mise en file d’attente.' : 'Collecte du programme mise en file d’attente.' );
		} else {
			self::message( 'WP-Cron n’a pas pu planifier la synchronisation.', 'error' );
		}
		self::redirect();
	}

	private static function identity( $type, $data ) {
		if ( BSC_TV_Content::CHANNEL === $type ) {
			return BSC_TV_Content::channel_identity( $data['platform'], $data['url'] );
		}
		return BSC_TV_Library::show_identity( $data );
	}

	public static function import_csv( $contents, $kind, $publish ) {
		if ( strlen( $contents ) > self::MAX_IMPORT_BYTES ) {
			return new WP_Error( 'bsc_tv_import_size', 'Limite de 1 Mo dépassée.' );
		}
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$sample = strtok( $contents, "\r\n" ) ?: '';
		$separator = ',';
		foreach ( array( ';', "\t" ) as $candidate ) {
			if ( substr_count( $sample, $candidate ) > substr_count( $sample, $separator ) ) {
				$separator = $candidate;
			}
		}
		$file = fopen( 'php://temp', 'w+b' );
		fwrite( $file, $contents );
		rewind( $file );
		$headers = array_map( 'sanitize_key', fgetcsv( $file, 0, $separator, '"', '' ) ?: array() );
		$type = 'shows' === $kind ? BSC_TV_Content::SHOW : BSC_TV_Content::CHANNEL;
		$required = BSC_TV_Content::SHOW === $type ? array( 'title', 'url', 'starts_at' ) : array( 'name', 'url' );
		if ( array_diff( $required, $headers ) || count( $headers ) !== count( array_unique( $headers ) ) ) {
			fclose( $file );
			return new WP_Error( 'bsc_tv_import_headers', 'Colonnes obligatoires manquantes ou en double. Colonnes requises ' . implode( ', ', $required ) . '.' );
		}
		$rows = array();
		while ( false !== ( $row = fgetcsv( $file, 0, $separator, '"', '' ) ) ) {
			if ( array( null ) !== $row ) {
				$rows[] = $row;
				if ( count( $rows ) > self::MAX_IMPORT_ROWS ) {
					fclose( $file );
					return new WP_Error( 'bsc_tv_import_rows', 'Limite de 500 lignes dépassée.' );
				}
			}
		}
		fclose( $file );
		$lock = BSC_TV_Content::lock( 'bsc_tv_csv_lock' );
		if ( ! $lock ) {
			return new WP_Error( 'bsc_tv_import_busy', 'Un autre import est en cours. Relancez le vôtre après sa fin.' );
		}
		try {
			return self::insert_rows( $rows, $headers, $required[0], $type, $publish );
		} finally {
			BSC_TV_Content::unlock( 'bsc_tv_csv_lock', $lock );
		}
	}

	private static function insert_rows( $rows, $headers, $title_field, $type, $publish ) {
		$seen = array();
		foreach ( BSC_TV_Content::posts( $type, array( 'publish', 'draft', 'pending', 'private', 'future', BSC_TV_Content::ARCHIVED ) ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			if ( ! empty( $data['url'] ) ) {
				$seen[ self::identity( $type, $data ) ] = true;
			}
		}
		$counts = array( 'added' => 0, 'duplicate' => 0, 'invalid' => array() );
		foreach ( $rows as $index => $row ) {
			$input = count( $headers ) === count( $row ) ? array_combine( $headers, $row ) : array();
			$title = sanitize_text_field( $input[ $title_field ] ?? '' );
			$data = BSC_TV_Content::SHOW === $type ? BSC_TV_Content::show_data( $input ) : BSC_TV_Content::channel_data( $input );
			if ( ! $title || is_wp_error( $data ) || empty( $data['url'] ) ) {
				$counts['invalid'][] = $index + 2;
				continue;
			}
			if ( ! empty( $data['channel_id'] ) && ! BSC_TV_Content::public_channel( $data['channel_id'] ) ) {
				$counts['invalid'][] = $index + 2;
				continue;
			}
			$id = self::identity( $type, $data );
			if ( isset( $seen[ $id ] ) ) {
				++$counts['duplicate'];
				continue;
			}
			$post = wp_insert_post( array( 'post_type' => $type, 'post_status' => $publish ? 'publish' : 'draft', 'post_title' => wp_slash( $title ), 'meta_input' => array( BSC_TV_Content::DATA => wp_slash( $data ) ) ), true );
			if ( is_wp_error( $post ) || ! $post ) {
				$counts['invalid'][] = $index + 2;
				continue;
			}
			$seen[ $id ] = true;
			++$counts['added'];
		}
		return $counts;
	}

	public static function import() {
		self::authorize( 'bsc_tv_import' );
		$kind = sanitize_key( $_POST['kind'] ?? '' );
		$publish = ! empty( $_POST['publish'] );
		$contents = '';
		if ( in_array( $kind, array( 'catalogue', 'selection' ), true ) ) {
			$contents = file_get_contents( BSC_TV_DIR . 'data/' . ( 'catalogue' === $kind ? 'createurs.csv' : 'programmes.csv' ) );
			$kind = 'catalogue' === $kind ? 'channels' : 'shows';
			$publish = false;
		} elseif ( isset( $_FILES['csv']['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['csv']['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $_FILES['csv']['error'] || (int) $_FILES['csv']['size'] > self::MAX_IMPORT_BYTES || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
				self::message( 'Le fichier n’a pas pu être importé. Vérifiez la limite de 1 Mo et réessayez.', 'error' );
				self::redirect();
			}
			$contents = file_get_contents( $_FILES['csv']['tmp_name'], false, null, 0, self::MAX_IMPORT_BYTES + 1 );
		} else {
			$contents = wp_unslash( $_POST['csv_text'] ?? '' );
		}
		if ( ! is_string( $contents ) || ! in_array( $kind, array( 'channels', 'shows' ), true ) ) {
			self::message( 'Import invalide.', 'error' );
			self::redirect();
		}
		$result = self::import_csv( $contents, $kind, $publish );
		if ( is_wp_error( $result ) ) {
			self::message( $result->get_error_message(), 'error' );
		} else {
			$message = sprintf( '%d ajout(s), %d doublon(s), %d ligne(s) invalide(s).', $result['added'], $result['duplicate'], count( $result['invalid'] ) );
			if ( $result['invalid'] ) {
				$message .= ' Lignes concernées (20 premières au maximum) ' . implode( ', ', array_slice( $result['invalid'], 0, 20 ) ) . '.';
			}
			self::message( $message, $result['invalid'] ? 'warning' : 'success' );
		}
		self::redirect();
	}

	public static function page() {
		$settings = BSC_TV_Sync::settings();
		$last = (int) get_option( BSC_TV_Sync::LAST_DAILY );
		$last_live = (int) get_option( BSC_TV_Sync::LAST_LIVE );
		$remaining = count( (array) get_option( BSC_TV_Sync::QUEUE, array() ) );
		$quota = (array) get_option( BSC_TV_Sync::QUOTA, array() );
		?>
		<div class="wrap">
			<h1>BSC TV</h1>
			<p>Dans Elementor, ajoutez <strong>BSC TV – Programme</strong> à la page et <strong>BSC TV – Méga-menu</strong> au conteneur de votre menu.</p>
			<h2>Synchronisation</h2>
			<p>Collecte quotidienne sur 45 jours pour YouTube, Twitch et les agendas publics configurés. Les annonces à vérifier apparaissent dans les brouillons des émissions.</p>
			<p><?php echo $last ? esc_html( 'Dernière collecte terminée ' . wp_date( 'j F Y · H:i', $last ) . '.' ) : 'Première collecte en attente.'; ?>
			<?php if ( $remaining ) : echo esc_html( sprintf( ' Chaînes restant à traiter : %d.', $remaining ) ); endif; ?></p>
			<?php if ( $last_live ) : ?><p><?php echo esc_html( 'Dernier contrôle des directs ' . wp_date( 'j F Y · H:i', $last_live ) . '.' ); ?></p><?php endif; ?>
			<?php if ( $quota ) : ?><p><?php echo esc_html( sprintf( 'YouTube, recherches réservées au %s (heure du Pacifique) %d.', $quota['date'], $quota['count'] ) ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsc_tv_settings">
				<?php wp_nonce_field( 'bsc_tv_settings' ); ?>
				<?php foreach ( self::secrets() as $key => $label ) : ?>
					<?php if ( defined( 'BSC_TV_' . strtoupper( $key ) ) ) : ?>
						<p><?php echo esc_html( $label . ' configuré dans wp-config.php.' ); ?></p>
					<?php else : ?>
						<p><label for="bsc-tv-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><br>
						<input type="password" class="regular-text" id="bsc-tv-<?php echo esc_attr( $key ); ?>" name="bsc_tv[<?php echo esc_attr( $key ); ?>]" autocomplete="new-password" placeholder="<?php echo $settings[ $key ] ? 'Enregistré' : 'À renseigner'; ?>">
						<?php if ( $settings[ $key ] ) : ?><label><input type="checkbox" name="bsc_tv[clear_<?php echo esc_attr( $key ); ?>]" value="1"> Effacer</label><?php endif; ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php self::field( 'youtube_search_limit', 'Budget quotidien de requêtes search.list YouTube', $settings['youtube_search_limit'] ?? 90, 'number' ); ?>
				<p><label><input type="checkbox" name="bsc_tv[live_enabled]" value="1" <?php checked( ! empty( $settings['live_enabled'] ) ); ?>> Vérifier le statut des directs toutes les cinq minutes</label></p>
				<?php submit_button( 'Enregistrer les réglages' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsc_tv_sync_now">
				<?php wp_nonce_field( 'bsc_tv_sync_now' ); ?>
				<button type="submit" class="button" name="sync_mode" value="daily">Collecter le programme maintenant</button>
				<?php if ( ! empty( $settings['live_enabled'] ) ) : ?><button type="submit" class="button" name="sync_mode" value="live">Vérifier les directs maintenant</button><?php endif; ?>
			</form>
			<hr>
			<h2>Catalogue et import CSV</h2>
			<p>Le catalogue et la sélection éditoriale sont importés en brouillon. Publiez les chaînes à suivre et renseignez les fiches BSC BDD à associer.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="bsc_tv_import">
				<?php wp_nonce_field( 'bsc_tv_import' ); ?>
				<p><label for="bsc-tv-import">Type d’import</label><br><select id="bsc-tv-import" name="kind">
					<option value="catalogue">Catalogue inclus · créateurs</option><option value="selection">Catalogue inclus · émissions annoncées</option>
					<option value="channels">Mon CSV de chaînes</option><option value="shows">Mon CSV d’émissions</option>
				</select></p>
				<p><label for="bsc-tv-file">Fichier CSV ou TSV</label><br><input type="file" id="bsc-tv-file" name="csv" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values"></p>
				<p><label for="bsc-tv-csv">Ou coller le tableau avec ses en-têtes</label><br><textarea class="large-text code" id="bsc-tv-csv" name="csv_text" rows="5"></textarea></p>
				<p class="description">Chaînes <code>name,url,platform,bdd_id,source,country,language,activity,notes,keywords,feed_url,feed_timezone,feed_official</code><br>Émissions <code>title,url,starts_at,ends_at,platform,creator,channel_id,bdd_id,image,status,source</code></p>
				<p><label><input type="checkbox" name="publish" value="1"> Publier les entrées de mon CSV au lieu de les enregistrer en brouillon</label></p>
				<?php submit_button( 'Importer' ); ?>
			</form>
		</div>
		<?php
	}
}
