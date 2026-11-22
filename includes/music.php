<?php
defined( 'ABSPATH' ) || exit;

/** Optional interludes inside the existing locked TV clock, never a second scheduler. */
final class BSC_TV_Music {
	const SETTINGS = 'bsc_tv_music_settings';
	const POST = 'bsc_tv_music_clip';
	const META = '_bsc_tv_music';
	const CACHE = 'bsc_tv_music_library';
	const REVISION = 'bsc_tv_music_revision';
	const REPORT = 'bsc_tv_music_metadata_report';

	public static function register() {
		add_action( 'init', static function () { register_post_type( self::POST, array( 'label' => 'Clips musicaux BSC TV', 'public' => false, 'show_ui' => false, 'supports' => array( 'title' ), 'can_export' => true ) ); } );
		add_action( 'bsc_tv_player_daily_music', array( __CLASS__, 'daily' ) );
	}

	public static function normalize( $input ) {
		$input = (array) $input;
		$bands = array();
		$defaults = array( 'morning' => array( 'BSC Morning Chill', '06:00', '10:00' ), 'day' => array( 'BSC Hip-Hop Classics', '10:00', '18:00' ), 'evening' => array( 'BSC MTV Legends', '18:00', '00:00' ), 'night' => array( 'BSC Night Sessions', '00:00', '06:00' ) );
		foreach ( $defaults as $id => $values ) {
			$row = (array) ( $input['bands'][ $id ] ?? array() );
			$bands[ $id ] = array( 'name' => sanitize_text_field( $row['name'] ?? $values[0] ), 'start' => self::hour( $row['start'] ?? $values[1] ), 'end' => self::hour( $row['end'] ?? $values[2] ), 'category' => isset( $defaults[ $row['category'] ?? '' ] ) ? $row['category'] : $id, 'enabled' => ! isset( $input['bands'] ) || ! empty( $row['enabled'] ) );
		}
		return array(
			'enabled' => ! empty( $input['enabled'] ), 'threshold' => max( 1, min( 600, (int) ( $input['threshold'] ?? 40 ) ) ),
			'flexible' => ! empty( $input['flexible'] ), 'repeat_hours' => max( 0, min( 720, (float) ( $input['repeat_hours'] ?? 6 ) ) ),
			'disable_policy' => 'interrupt' === ( $input['disable_policy'] ?? '' ) ? 'interrupt' : 'finish',
			'live_policy' => 'finish' === ( $input['live_policy'] ?? '' ) ? 'finish' : 'interrupt',
			'entry' => max( 1, min( 30, (int) ( $input['entry'] ?? 3 ) ) ), 'exit' => max( 1, min( 30, (int) ( $input['exit'] ?? 3 ) ) ),
			'logo' => absint( $input['logo'] ?? 0 ), 'entry_video' => absint( $input['entry_video'] ?? 0 ), 'exit_video' => absint( $input['exit_video'] ?? 0 ),
			'background' => sanitize_hex_color( $input['background'] ?? '' ) ?: '#111116', 'color' => sanitize_hex_color( $input['color'] ?? '' ) ?: '#ffffff',
			'animation' => in_array( $input['animation'] ?? '', array( 'fade', 'slide', 'none' ), true ) ? $input['animation'] : 'fade',
			'show_text' => ! isset( $input['show_text'] ) || ! empty( $input['show_text'] ), 'sound' => ! empty( $input['sound'] ),
			'entry_text' => sanitize_text_field( $input['entry_text'] ?? 'BSC Music Break' ), 'exit_text' => sanitize_text_field( $input['exit_text'] ?? 'Back to BSC TV' ), 'bands' => $bands,
		);
	}
	private static function hour( $value ) { return is_string( $value ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value ) ? $value : '00:00'; }
	public static function settings() { return self::normalize( get_option( self::SETTINGS, array() ) ) + array( 'revision' => (int) get_option( self::REVISION, 0 ) ); }
	public static function category( $timestamp, $settings ) {
		$hour = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'Europe/Paris' ) )->format( 'H:i' );
		foreach ( $settings['bands'] as $row ) {
			if ( ! $row['enabled'] || $row['start'] === $row['end'] ) { continue; }
			$inside = $row['start'] < $row['end'] ? $hour >= $row['start'] && $hour < $row['end'] : $hour >= $row['start'] || $hour < $row['end'];
			if ( $inside ) { return $row['category']; }
		}
		return '';
	}
	public static function valid_bands( $settings ) {
		for ( $minute = 0; $minute < 1440; ++$minute ) {
			$hour = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 ); $matches = 0;
			foreach ( $settings['bands'] as $band ) {
				if ( ! $band['enabled'] ) { continue; }
				if ( $band['start'] === $band['end'] ) { return false; }
				$matches += (int) ( $band['start'] < $band['end'] ? $hour >= $band['start'] && $hour < $band['end'] : $hour >= $band['start'] || $hour < $band['end'] );
			}
			if ( $matches > 1 ) { return false; }
		}
		return true;
	}
	public static function invalidate() {
		delete_transient( self::CACHE );
		update_option( self::REVISION, (int) get_option( self::REVISION, 0 ) + 1, false );
		BSC_TV_Player_Schedule::request();
	}
	public static function clips() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) { return $cached; }
		$clips = array();
		foreach ( get_posts( array( 'post_type' => self::POST, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'ID', 'order' => 'ASC', 'update_post_term_cache' => false ) ) as $post ) {
			$clip = (array) get_post_meta( $post->ID, self::META, true );
			$clips[ $post->ID ] = $clip + array( 'id' => $post->ID );
		}
		set_transient( self::CACHE, $clips, MINUTE_IN_SECONDS );
		return $clips;
	}
	public static function approved( $clip ) {
		return ! empty( $clip['active'] ) && 'confirmed' === ( $clip['rights'] ?? '' ) && ! empty( $clip['rights_note'] ) && ! empty( $clip['official'] ) && 'available' === ( $clip['availability'] ?? '' ) && ( $clip['duration'] ?? 0 ) > 0 && ( $clip['duration'] ?? 0 ) <= HOUR_IN_SECONDS && ! empty( $clip['artist'] ) && ! empty( $clip['title'] ) && preg_match( '/^[\w-]{11}$/D', $clip['youtube_id'] ?? '' );
	}
	private static function media_url( $id, $type ) {
		return $id && 0 === strpos( (string) get_post_mime_type( $id ), $type . '/' ) ? BSC_TV_Content::url( wp_get_attachment_url( $id ) ) : '';
	}
	public static function pool( $state = null ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			$state = $state ?? (array) get_option( BSC_TV_Player::STATE, array() );
			if ( empty( $state['vod']['music'] ) || 'finish' !== $settings['disable_policy'] ) { return array(); }
		}
		$identity = array_intersect_key( $settings, array_flip( array( 'background', 'color', 'animation', 'show_text', 'sound', 'entry_text', 'exit_text' ) ) );
		$identity += array( 'logo' => self::media_url( $settings['logo'], 'image' ), 'entry_video' => self::media_url( $settings['entry_video'], 'video' ), 'exit_video' => self::media_url( $settings['exit_video'], 'video' ) );
		$pool = array();
		foreach ( self::clips() as $id => $clip ) {
			if ( ! self::approved( $clip ) ) { continue; }
			$key = 'music:' . $id . ':' . $clip['youtube_id'];
			if ( ! $settings['enabled'] && ( $state['vod']['key'] ?? '' ) !== $key ) { continue; }
			$music = array_intersect_key( $clip, array_flip( array( 'artist', 'title', 'year', 'youtube_id', 'channel', 'image', 'duration', 'categories', 'region' ) ) );
			$music += array( 'clip_id' => $id, 'entry' => $settings['entry'], 'exit' => $settings['exit'], 'identity' => $identity );
			$pool[ $key ] = array( 'key' => $key, 'show_id' => 0, 'channel_id' => 0, 'creator_key' => 'music:' . strtolower( $clip['artist'] ), 'creator' => $clip['artist'], 'title' => $clip['title'], 'resource' => array( 'platform' => 'youtube', 'id' => $clip['youtube_id'] ), 'image' => $clip['image'] ?? '', 'duration' => $clip['duration'] + $settings['entry'] + $settings['exit'], 'published_at' => 0, 'premiere' => false, 'music' => $music );
		}
		return $pool;
	}
	/** Choose only inside a genuine gap, or an explicitly enabled wholly automatic tail. */
	public static function between( $next, $pool, $state, $settings, $cursor ) {
		$config = $settings['music'] ?? array();
		$previous = $state['music_previous'] ?? $state['vod'] ?? null;
		if ( ! $next || empty( $config['enabled'] ) || ! $previous || ! empty( $previous['music'] ) || ! empty( $next['music'] ) || ! empty( $state['live'] ) || ! empty( $state['manual'] ) || $previous['end'] !== $cursor || $previous['end'] - $previous['start'] <= $config['threshold'] * MINUTE_IN_SECONDS ) { return null; }
		$category = self::category( $cursor, $config );
		if ( ! $category ) { return null; }
		$space = $next ? max( 0, $next['available'] - $cursor ) : PHP_INT_MAX;
		$flexible = ! empty( $config['flexible'] ) && $next && empty( $next['fixed'] );
		foreach ( $state['plan'] ?? array() as $row ) { if ( $row['fixed'] ?? true ) { $flexible = false; break; } }
		$candidates = array();
		foreach ( $pool as $key => $media ) {
			$clip = $media['music'] ?? null;
			if ( ! $clip || ! in_array( $category, $clip['categories'] ?? array(), true ) || ( ! $flexible && $media['duration'] > $space ) ) { continue; }
			$overlap = false;
			foreach ( $media['music_deadlines'] ?? array() as $deadline ) { if ( $deadline >= $cursor && $deadline < $cursor + $media['duration'] ) { $overlap = true; break; } }
			if ( $overlap ) { continue; }
			$artist = strtolower( trim( $clip['artist'] ) );
			$last = $state['music_rotation'][ $key ] ?? 0;
			if ( $artist === ( $state['music_last_artist'] ?? '' ) || ( $last && $last + $config['repeat_hours'] * HOUR_IN_SECONDS > $cursor ) ) { continue; }
			$prefer = 'day' === $category && in_array( $clip['region'] ?? '', array( 'us', 'fr' ), true ) && ( $clip['region'] ?? '' ) !== ( $state['music_last_region'] ?? '' );
			$candidates[] = $media + array( 'available' => $cursor, 'music_prefer' => $prefer, 'music_last' => $last );
		}
		usort( $candidates, static function ( $a, $b ) { return $b['music_prefer'] <=> $a['music_prefer'] ?: $a['music_last'] <=> $b['music_last'] ?: strcmp( $a['key'], $b['key'] ); } );
		return $candidates[0] ?? null;
	}
	public static function begun( &$state, $slot ) {
		$state['music_previous'] = $slot;
		if ( empty( $slot['music'] ) ) { return; }
		$state['music_rotation'][ $slot['key'] ] = $slot['end'];
		$state['music_last_artist'] = strtolower( trim( $slot['music']['artist'] ) );
		$state['music_last_region'] = $slot['music']['region'] ?? '';
		$state['music_rotation'] = array_slice( $state['music_rotation'], -500, null, true );
	}
	public static function tally( &$state, $slot, $now ) {
		if ( empty( $slot['music'] ) ) { return; }
		$start = $slot['start'] + $slot['music']['entry']; $id = $slot['music']['clip_id'];
		if ( $now < $start || ( $state['music_stats'][ $id ]['last'] ?? 0 ) >= $start ) { return; }
		$state['music_stats'][ $id ] = array( 'last' => $start, 'plays' => 1 + ( $state['music_stats'][ $id ]['plays'] ?? 0 ), 'title' => $slot['music']['title'], 'artist' => $slot['music']['artist'] );
		$state['music_stats'] = array_slice( $state['music_stats'], -500, null, true );
	}
	/** Revocation is immediate; disabling can optionally let the current approved break finish. */
	public static function reconcile( $state, $pool, $settings, $now ) {
		$config = $settings['music'] ?? array();
		$state['plan'] = array_values( array_filter( $state['plan'] ?? array(), static function ( $row ) use ( $config ) { return empty( $row['music'] ) || ! empty( $config['enabled'] ); } ) );
		$vod = $state['vod'] ?? null;
		if ( $vod && ! empty( $vod['music'] ) && ( ! isset( $pool[ $vod['key'] ] ) || ( empty( $config['enabled'] ) && 'interrupt' === ( $config['disable_policy'] ?? '' ) ) ) ) {
			self::interrupt( $state, $now );
		}
		return $state;
	}
	public static function interrupt( &$state, $now ) {
		$vod = $state['vod'];
		foreach ( $state['aired'] ?? array() as $i => $row ) { if ( $row['key'] === $vod['key'] && $row['start'] === $vod['start'] ) { $state['aired'][ $i ]['end'] = min( $row['end'], $now ); } }
		$release = max( 0, min( $vod['end'] - $now, ( $state['plan'][0]['start'] ?? $now ) - $now ) );
		foreach ( $state['plan'] ?? array() as $i => $row ) {
			if ( $row['fixed'] ?? true ) { break; }
			$state['plan'][ $i ]['start'] -= $release; $state['plan'][ $i ]['end'] -= $release;
		}
		$state['vod'] = null; $state['music_previous'] = null;
	}
	public static function decorate( $slot, $media, $now ) {
		$music = $slot['music'] ?? $media['music'];
		$entry_end = $slot['start'] + $music['entry']; $clip_end = $entry_end + $music['duration'];
		$phase = $now < $entry_end ? 'entry' : ( $now < $clip_end ? 'clip' : 'exit' );
		$start = 'entry' === $phase ? $slot['start'] : ( 'clip' === $phase ? $entry_end : $clip_end );
		$end = 'entry' === $phase ? $entry_end : ( 'clip' === $phase ? $clip_end : $slot['end'] );
		return array( 'key' => $slot['key'], 'show_id' => 0, 'resource' => array( 'platform' => 'clip' === $phase ? 'youtube' : 'identity', 'id' => $music['youtube_id'] ), 'url' => 'clip' === $phase ? 'https://www.youtube.com/watch?v=' . $music['youtube_id'] : '', 'title' => $music['title'], 'creator' => $music['artist'], 'avatar' => '', 'profile_url' => '', 'image' => $music['image'] ?? '', 'live' => false, 'new' => false, 'premiere' => false, 'music' => true, 'music_phase' => $phase, 'identity' => 'clip' === $phase ? null : $music['identity'], 'start' => $start, 'end' => $end, 'offset' => max( 0, $now - $start ), 'interlude_start' => $slot['start'], 'interlude_end' => $slot['end'] );
	}
	/** At most one existing-credentials batch during the already authenticated daily worker. */
	public static function daily() {
		if ( ! self::settings()['enabled'] ) { return; }
		$clips = self::clips();
		uasort( $clips, static function ( $a, $b ) { return ( $a['checked_at'] ?? 0 ) <=> ( $b['checked_at'] ?? 0 ); } );
		$clips = array_slice( $clips, 0, 50, true );
		self::refresh( $clips );
	}
	public static function refresh( $clips ) {
		if ( ! $clips ) { return; }
		$result = BSC_TV_Sync::music_metadata( array_column( $clips, 'youtube_id' ) );
		if ( is_wp_error( $result ) ) { update_option( self::REPORT, array( 'at' => time(), 'error' => $result->get_error_message() ), false ); return; }
		foreach ( $clips as $id => $clip ) {
			if ( isset( $result['failed'][ $clip['youtube_id'] ] ) ) { continue; }
			$event = $result['videos'][ $clip['youtube_id'] ] ?? null;
			$clip['availability'] = $event && ! empty( $event['music_available'] ) ? 'available' : 'blocked';
			$clip['checked_at'] = time();
			if ( $event ) {
				$clip['duration'] = (int) $event['duration']; $clip['image'] = $event['image']; $clip['channel'] = $event['channel_title'] ?? $event['channel_id'];
				if ( empty( $clip['title'] ) ) { $clip['title'] = $event['title']; }
			}
			update_post_meta( $id, self::META, $clip );
		}
		update_option( self::REPORT, array( 'at' => time(), 'error' => $result['failed'] ? 'Certaines métadonnées n’ont pas pu être vérifiées.' : '' ), false );
		self::invalidate();
	}
}
