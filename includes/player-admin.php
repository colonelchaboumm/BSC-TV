<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Player_Admin {
	public static function register() {
		add_action( 'bsc_tv_admin_player', array( __CLASS__, 'section' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_media' ), 30, 2 );
		add_action( 'admin_post_bsc_tv_player_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_bsc_tv_player_queue', array( __CLASS__, 'queue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
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
		update_post_meta( $id, BSC_TV_Player::MEDIA, array( 'mode' => $mode, 'url' => $url, 'duration' => $duration, 'published_at' => $published, 'premiere' => ! empty( $input['premiere'] ) ) );
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action réservée aux administrateurs.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=bsc-tv#bsc-tv-player-settings' ) );
		exit;
	}

	public static function save_settings() {
		self::authorize( 'bsc_tv_player_settings' );
		$previous = BSC_TV_Player::settings();
		$input = (array) wp_unslash( $_POST['bsc_player'] ?? array() );
		$settings = array( 'enabled' => ! empty( $input['enabled'] ), 'live_priority' => ! empty( $input['live_priority'] ), 'recent' => in_array( $input['recent'] ?? '', array( 'strong', 'balanced', 'rotation' ), true ) ? $input['recent'] : 'strong', 'repeat_hours' => max( 0, min( 168, (float) ( $input['repeat_hours'] ?? 6 ) ) ), 'tolerance' => max( 3, min( 30, (int) ( $input['tolerance'] ?? 8 ) ) ) );
		update_option( BSC_TV_Player::SETTINGS, $settings, false );
		if ( $settings['enabled'] && ! $previous['enabled'] ) {
			BSC_TV_Sync::queue( BSC_TV_Sync::DAILY_NOW );
		}
		self::message( 'Réglages du lecteur 24/7 enregistrés.' );
		self::redirect();
	}

	public static function queue() {
		self::authorize( 'bsc_tv_player_queue' );
		$id = absint( $_POST['show_id'] ?? 0 );
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		$pool = BSC_TV_Player::media();
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) {
			self::message( 'La programmation est en cours de mise à jour. Réessayez.' );
			self::redirect();
		}
		try {
			wp_cache_delete( BSC_TV_Player::STATE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$state = (array) get_option( BSC_TV_Player::STATE, array() );
			if ( 'next' === $operation && in_array( $id, array_column( $pool, 'show_id' ), true ) ) {
				$manual = array_values( array_diff( $state['manual'] ?? array(), array( $id ) ) );
				array_unshift( $manual, $id );
				$state['manual'] = array_slice( $manual, 0, 20 );
			} elseif ( 'clear' === $operation ) {
				$state['manual'] = array();
			} elseif ( 'skip' === $operation && empty( $state['live'] ) && ! empty( $state['vod'] ) && hash_equals( $state['vod']['key'] . ':' . $state['vod']['start'], sanitize_text_field( wp_unslash( $_POST['current_key'] ?? '' ) ) ) ) {
				$state['vod']['end'] = time();
				$state['history'][ $state['vod']['key'] ]['end'] = time();
			}
			update_option( BSC_TV_Player::STATE, $state, false );
		} finally {
			BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock );
		}
		self::message( 'Programmation enregistrée. Les visiteurs la recevront au prochain rafraîchissement.' );
		self::redirect();
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
		$state = (array) get_option( BSC_TV_Player::STATE, array() );
		?>
		<section id="bsc-tv-player-settings">
			<h2>BSC TV LIVE 24/7</h2>
			<p>Cette programmation utilise les émissions BSC TV existantes. Elle n’ajoute aucune collecte ni aucun stockage vidéo.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsc_tv_player_settings"><?php wp_nonce_field( 'bsc_tv_player_settings' ); ?>
				<p><label><input type="checkbox" name="bsc_player[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> Activer le lecteur 24/7</label></p>
				<p><label><input type="checkbox" name="bsc_player[live_priority]" value="1" <?php checked( $settings['live_priority'] ); ?>> Donner la priorité aux véritables directs confirmés par BSC TV</label></p>
				<p class="description">Le contrôle des directs existant doit être activé. Ses restrictions de découverte restent identiques : les directs spontanés peuvent ne pas être détectés.</p>
				<p><label for="bsc-player-recent">Priorité aux nouveautés</label><br><select id="bsc-player-recent" name="bsc_player[recent]">
					<?php foreach ( array( 'strong' => 'Forte · 24 h, 3 jours, 7 jours, 14 jours, 30 jours, archives', 'balanced' => 'Équilibrée · une semaine puis archives', 'rotation' => 'Rotation · la moins récemment diffusée' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['recent'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></p>
				<?php self::field( 'repeat_hours', 'Délai minimum après la fin d’un replay avant sa rediffusion (heures)', $settings['repeat_hours'], 'number', 'min="0" max="168" step="0.5"' ); ?>
				<?php self::field( 'tolerance', 'Tolérance de synchronisation (secondes)', $settings['tolerance'], 'number', 'min="3" max="30"' ); ?>
				<?php submit_button( 'Enregistrer le lecteur 24/7' ); ?>
			</form>
			<h3>À l’antenne</h3>
			<p><?php echo esc_html( ! $settings['enabled'] ? 'Lecteur désactivé.' : ( $snapshot['current'] ? ( $snapshot['current']['live'] ? 'LIVE · ' : '' ) . $snapshot['current']['title'] . ' · ' . $snapshot['current']['creator'] : 'Aucun programme disponible maintenant.' ) ); ?></p>
			<?php if ( $snapshot['current'] && ! $snapshot['current']['live'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bsc_tv_player_queue"><input type="hidden" name="operation" value="skip"><input type="hidden" name="current_key" value="<?php echo esc_attr( $snapshot['current']['key'] . ':' . $snapshot['current']['start'] ); ?>"><?php wp_nonce_field( 'bsc_tv_player_queue' ); ?><button class="button" type="submit">Passer au programme suivant</button></form>
			<?php endif; ?>
			<h3>Prochains programmes estimés</h3>
			<ol><?php foreach ( $snapshot['next'] as $item ) : ?><li><?php echo esc_html( ( ! empty( $item['after_live'] ) ? 'Après le direct' : wp_date( 'd/m H:i', $item['start'] ) ) . ' · ' . $item['title'] ); ?></li><?php endforeach; ?></ol>
			<p><?php echo esc_html( sprintf( '%d replay(s) utilisable(s).', count( $pool ) ) ); ?> Pour ajouter un replay ou ajuster sa durée, ouvrez une <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BSC_TV_Content::SHOW ) ); ?>">émission existante</a>, y compris dans les archives, puis utilisez le bloc « Diffusion BSC TV 24/7 ».</p>
			<?php if ( $pool ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bsc_tv_player_queue"><?php wp_nonce_field( 'bsc_tv_player_queue' ); ?>
					<label for="bsc-player-queue">Mettre en priorité à la prochaine transition</label><br><select id="bsc-player-queue" name="show_id"><?php foreach ( $pool as $item ) : ?><option value="<?php echo esc_attr( $item['show_id'] ); ?>"><?php echo esc_html( $item['title'] ); ?></option><?php endforeach; ?></select>
					<button class="button" type="submit" name="operation" value="next">Programmer ensuite</button> <button class="button" type="submit" name="operation" value="clear">Effacer la file prioritaire</button>
				</form>
			<?php endif; ?>
			<?php if ( ! empty( $state['manual'] ) ) : ?><p>File prioritaire : <?php echo esc_html( implode( ' → ', array_map( 'get_the_title', $state['manual'] ) ) ); ?>.</p><?php endif; ?>
			<p class="description">L’émission en cours reste stable quand un nouveau replay arrive. La file prioritaire respecte les disponibilités et le délai de rediffusion. Les créateurs alternent lorsque le catalogue le permet. S’il manque des replays pour couvrir ce délai, l’antenne attend le prochain créneau disponible.</p>
		</section><hr>
		<?php
	}
}
