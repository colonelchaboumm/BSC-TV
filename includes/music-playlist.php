<?php
defined( 'ABSPATH' ) || exit;

/** Administrator-requested, resumable import. No public endpoint, playlist cron or playback. */
final class BSC_TV_Music_Playlist {
	const LOCK = 'bsc_tv_music_playlist_import';
	const MAX_PAGES = 50;
	public static function register() { add_action( 'wp_ajax_bsc_tv_music_playlist', array( __CLASS__, 'ajax' ) ); }
	private static function job_key() { return BSC_TV_Stations::key( 'bsc_tv_music_playlist_' . get_current_user_id() ); }
	public static function playlist_id( $value ) {
		if ( ! is_string( $value ) ) { return ''; }
		$value = trim( $value );
		if ( preg_match( '/^[A-Za-z0-9_-]{10,100}$/D', $value ) ) { return $value; }
		$url = wp_parse_url( $value );
		if ( ! is_array( $url ) || ! in_array( strtolower( $url['host'] ?? '' ), array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be' ), true ) || ! in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $url['user'] ) || isset( $url['pass'] ) ) { return ''; }
		parse_str( $url['query'] ?? '', $query );
		return is_string( $query['list'] ?? null ) && preg_match( '/^[A-Za-z0-9_-]{10,100}$/D', $query['list'] ) ? $query['list'] : '';
	}
	public static function status() {
		return current_user_can( 'manage_options' ) ? self::view( get_transient( self::job_key() ) ?: array() ) : array();
	}
	private static function view( $job ) { return array_intersect_key( $job, array_flip( array( 'token', 'playlist', 'url', 'category', 'step', 'done', 'created', 'assigned', 'duplicates', 'unavailable', 'processed', 'total', 'warning', 'error', 'limited' ) ) ); }
	public static function start( $url, $category ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'playlist_forbidden', 'Action réservée aux administrateurs.' ); }
		$id = self::playlist_id( $url );
		if ( ! $id || ! isset( BSC_TV_Music::settings()['bands'][ $category ] ) ) { return new WP_Error( 'playlist_invalid', 'Indiquez une playlist YouTube et une catégorie musicale valide.' ); }
		if ( empty( BSC_TV_Sync::settings()['youtube_key'] ) ) { return new WP_Error( 'playlist_key', 'Configurez la clé YouTube dans BSC TV → Réglages et imports.' ); }
		$lock = BSC_TV_Content::lock( BSC_TV_Stations::key( self::LOCK ) );
		if ( ! $lock ) { return new WP_Error( 'playlist_busy', 'Un import musical est en cours. Réessayez dans quelques secondes.' ); }
		try {
			$job = array( 'token' => bin2hex( random_bytes( 16 ) ), 'playlist' => $id, 'url' => 'https://www.youtube.com/playlist?list=' . $id, 'category' => $category, 'cursor' => '', 'step' => 0, 'done' => false, 'created' => 0, 'assigned' => 0, 'duplicates' => 0, 'unavailable' => 0, 'processed' => 0, 'total' => 0, 'seen' => array(), 'tokens' => array(), 'warning' => '', 'error' => '', 'limited' => false );
			set_transient( self::job_key(), $job, DAY_IN_SECONDS );
			return self::view( $job );
		} finally { BSC_TV_Content::unlock( BSC_TV_Stations::key( self::LOCK ), $lock ); }
	}
	public static function step( $token, $expected ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'playlist_forbidden', 'Action réservée aux administrateurs.' ); }
		$lock = BSC_TV_Content::lock( BSC_TV_Stations::key( self::LOCK ) );
		if ( ! $lock ) { return new WP_Error( 'playlist_busy', 'Un import musical est en cours. Vous pourrez reprendre cet import.' ); }
		try {
			$job = get_transient( self::job_key() );
			if ( ! is_array( $job ) || ! is_string( $token ) || ! hash_equals( $job['token'], $token ) ) { return new WP_Error( 'playlist_expired', 'Cet import a expiré ou appartient à une autre session. Relancez-le avec la même playlist : les doublons sont évités.' ); }
			if ( $expected < $job['step'] || $job['done'] ) { return self::view( $job ); }
			if ( $expected !== $job['step'] ) { return new WP_Error( 'playlist_step', 'La progression a changé. Rechargez la page pour reprendre.' ); }
			$page = BSC_TV_Sync::music_playlist_page( $job['playlist'], $job['cursor'] );
			if ( is_wp_error( $page ) ) { return self::failure( $job, 'Playlist inaccessible ou API indisponible. Vérifiez qu’elle est publique ou accessible avec la clé configurée. ' . $page->get_error_message() ); }
			if ( count( $page['items'] ) > 50 || ! is_string( $page['nextPageToken'] ?? '' ) || strlen( $page['nextPageToken'] ?? '' ) > 2048 ) { return self::failure( $job, 'La pagination YouTube est invalide.' ); }
			$before_save = $job;
			$items = array();
			foreach ( $page['items'] as $item ) {
				$id = $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '';
				if ( ! is_string( $id ) || ! preg_match( '/^[\w-]{11}$/D', $id ) || 'private' === ( $item['status']['privacyStatus'] ?? '' ) ) { ++$job['unavailable']; continue; }
				if ( isset( $job['seen'][ $id ] ) || isset( $items[ $id ] ) ) { ++$job['duplicates']; continue; }
				$items[ $id ] = $item;
			}
			$details = $items ? BSC_TV_Sync::music_metadata( array_keys( $items ) ) : array( 'videos' => array(), 'failed' => array() );
			if ( is_wp_error( $details ) || ! empty( $details['failed'] ) ) { $job['warning'] = 'Certains liens sont importés sans métadonnées complètes. Vérifiez les clips avant validation.'; }
			// Network calls above never hold the shared antenna lock. Only the bounded save does.
			$result = BSC_TV_Music_Admin::change( static function () use ( &$job, $items, $details ) { return self::save_items( $job, $items, $details ); } );
			if ( is_wp_error( $result ) ) { return self::failure( $before_save, $result->get_error_message() ); }
			$job['processed'] += count( $page['items'] ); $job['total'] = max( $job['total'], absint( $page['pageInfo']['totalResults'] ?? 0 ) );
			$job['tokens'][] = $job['cursor']; $job['cursor'] = $page['nextPageToken'] ?? ''; ++$job['step']; $job['error'] = '';
			$cycle = $job['cursor'] && in_array( $job['cursor'], $job['tokens'], true );
			if ( $cycle ) { $job['warning'] = 'La pagination YouTube se répète : import arrêté pour éviter une boucle.'; }
			if ( $job['step'] >= self::MAX_PAGES && $job['cursor'] ) { $job['limited'] = true; $job['warning'] = 'Import arrêté après 2 500 éléments au maximum. Utilisez une playlist plus petite pour les éléments suivants.'; }
			$job['done'] = ! $job['cursor'] || $job['limited'] || $cycle;
			set_transient( self::job_key(), $job, DAY_IN_SECONDS );
			return self::view( $job );
		} finally { BSC_TV_Content::unlock( BSC_TV_Stations::key( self::LOCK ), $lock ); }
	}
	private static function failure( $job, $message ) {
		$job['error'] = $message; set_transient( self::job_key(), $job, DAY_IN_SECONDS );
		return new WP_Error( 'playlist_import', $message, array( 'job' => self::view( $job ) ) );
	}
	private static function save_items( &$job, $items, $details ) {
		delete_transient( BSC_TV_Stations::key( BSC_TV_Music::CACHE ) ); $library = BSC_TV_Music::clips(); $index = array();
		foreach ( $library as $id => $clip ) { if ( is_string( $clip['youtube_id'] ?? null ) ) { $index[ $clip['youtube_id'] ] = $id; } }
		foreach ( $items as $video_id => $item ) {
			if ( isset( $index[ $video_id ] ) ) {
				$id = $index[ $video_id ]; $clip = $library[ $id ];
				if ( ! in_array( $job['category'], $clip['categories'] ?? array(), true ) ) {
					$clip['categories'][] = $job['category']; ++$job['assigned'];
				} else { ++$job['duplicates']; }
				if ( ! is_wp_error( $details ) && ! isset( $details['failed'][ $video_id ] ) ) {
					$event = $details['videos'][ $video_id ] ?? null;
					$clip['availability'] = $event && ! empty( $event['music_available'] ) ? 'available' : 'blocked'; $clip['checked_at'] = time();
				}
				update_post_meta( $id, BSC_TV_Music::META, $clip );
				$job['seen'][ $video_id ] = true; continue;
			}
			$event = is_wp_error( $details ) ? null : ( $details['videos'][ $video_id ] ?? null );
			$known = ! is_wp_error( $details ) && ! isset( $details['failed'][ $video_id ] );
			if ( $known && ! $event ) { ++$job['unavailable']; $job['seen'][ $video_id ] = true; continue; }
			if ( count( $library ) >= 500 ) { $job['limited'] = true; $job['warning'] = 'La bibliothèque est limitée à 500 clips. Les références déjà présentes ont pu recevoir la catégorie choisie ; aucun clip existant n’est supprimé.'; continue; }
			$title = sanitize_text_field( html_entity_decode( $event['title'] ?? $item['snippet']['title'] ?? 'Clip à vérifier', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$parts = preg_split( '/\s+(?:-|–|—)\s+/u', $title, 2 ); $artist = 2 === count( $parts ) ? trim( $parts[0] ) : '';
			$thumbnails = $item['snippet']['thumbnails'] ?? array();
			$clip = array( 'station' => BSC_TV_Stations::current(), 'artist' => $artist, 'title' => $artist ? trim( $parts[1] ) : $title, 'year' => 0, 'youtube_id' => $video_id, 'url' => 'https://www.youtube.com/watch?v=' . $video_id, 'categories' => array( $job['category'] ), 'region' => 'other', 'duration' => (int) ( $event['duration'] ?? 0 ), 'image' => BSC_TV_Content::url( $event['image'] ?? $thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? '' ), 'channel' => sanitize_text_field( $event['channel_title'] ?? $item['snippet']['videoOwnerChannelTitle'] ?? '' ), 'active' => false, 'official' => false, 'rights' => 'pending', 'rights_note' => '', 'availability' => ! $known ? 'unchecked' : ( ! empty( $event['music_available'] ) ? 'available' : 'blocked' ), 'checked_at' => $known ? time() : 0, 'origin_playlist' => $job['playlist'], 'artist_suggested' => (bool) $artist );
			$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'publish', 'post_title' => $title, 'meta_input' => array( BSC_TV_Music::META => $clip ) ), true );
			if ( is_wp_error( $id ) ) { return $id; }
			$library[ $id ] = $clip; $index[ $video_id ] = $id; $job['seen'][ $video_id ] = true; ++$job['created'];
		}
		return true;
	}
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Accès refusé ou session expirée.' ), 403 ); }
		$station = wp_unslash( $_POST['station'] ?? 'main' );
		if ( ! BSC_TV_Stations::valid( $station ) ) { wp_send_json_error( array( 'message' => 'Chaîne TV inconnue.' ), 400 ); }
		BSC_TV_Stations::run( $station, static function () { self::ajax_current(); } );
	}
	private static function ajax_current() {
		if ( ! check_ajax_referer( BSC_TV_Stations::nonce( 'bsc_tv_music_playlist' ), 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'Session expirée.' ), 403 ); }
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		if ( 'start' === $operation ) { $result = self::start( wp_unslash( $_POST['url'] ?? '' ), sanitize_key( $_POST['category'] ?? '' ) ); }
		elseif ( 'step' === $operation ) { $result = self::step( wp_unslash( $_POST['token'] ?? '' ), absint( $_POST['step'] ?? 0 ) ); }
		else { $result = new WP_Error( 'playlist_action', 'Action inconnue.' ); }
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) + (array) $result->get_error_data(), 400 ); }
		wp_send_json_success( $result );
	}
}
