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
		add_action( 'bsc_tv_player_daily_music', array( 'BSC_TV_Stations', 'refresh_music' ) );
	}

	public static function normalize( $input ) {
		$input = (array) $input;
		$animation_ids = array();
		foreach ( array_slice( (array) ( $input['animation_ids'] ?? array() ), 0, 50 ) as $id ) { if ( is_scalar( $id ) && absint( $id ) ) { $animation_ids[ absint( $id ) ] = absint( $id ); } }
		$bands = array();
		$defaults = array( 'morning' => array( 'BSC Morning Chill', '06:00', '10:00' ), 'day' => array( 'BSC Hip-Hop Classics', '10:00', '18:00' ), 'evening' => array( 'BSC MTV Legends', '18:00', '00:00' ), 'night' => array( 'BSC Night Sessions', '00:00', '06:00' ) );
		if ( 'fr' === BSC_TV_Stations::current() ) {
			foreach ( array( 'morning' => 'BSC FR · Hip-hop 90 · Matin', 'day' => 'BSC FR · Rap 90 · Journée', 'evening' => 'BSC FR · Classiques 90 · Soirée', 'night' => 'BSC FR · Underground 90 · Nuit' ) as $id => $name ) { $defaults[ $id ][0] = $name; }
		}
		foreach ( $defaults as $id => $values ) {
			$row = (array) ( $input['bands'][ $id ] ?? array() );
			$bands[ $id ] = array( 'name' => sanitize_text_field( $row['name'] ?? $values[0] ), 'start' => self::hour( $row['start'] ?? $values[1] ), 'end' => self::hour( $row['end'] ?? $values[2] ), 'category' => isset( $defaults[ $row['category'] ?? '' ] ) ? $row['category'] : $id, 'enabled' => ! isset( $input['bands'] ) || ! empty( $row['enabled'] ) );
		}
		return array(
			'enabled' => ! empty( $input['enabled'] ), 'threshold' => max( 1, min( 600, (int) ( $input['threshold'] ?? 40 ) ) ),
			'year_min' => empty( $input['year_min'] ?? ( 'fr' === BSC_TV_Stations::current() ? 1990 : 0 ) ) ? 0 : max( 1900, min( 2100, (int) ( $input['year_min'] ?? 1990 ) ) ),
			'year_max' => empty( $input['year_max'] ?? ( 'fr' === BSC_TV_Stations::current() ? 1999 : 0 ) ) ? 0 : max( 1900, min( 2100, (int) ( $input['year_max'] ?? 1999 ) ) ),
			'region_filter' => in_array( $input['region_filter'] ?? '', array( 'all', 'fr', 'us', 'other' ), true ) ? $input['region_filter'] : ( 'fr' === BSC_TV_Stations::current() ? 'fr' : 'all' ),
			'flexible' => ! empty( $input['flexible'] ), 'repeat_hours' => max( 0, min( 720, (float) ( $input['repeat_hours'] ?? 6 ) ) ),
			'disable_policy' => 'interrupt' === ( $input['disable_policy'] ?? '' ) ? 'interrupt' : 'finish',
			'live_policy' => 'finish' === ( $input['live_policy'] ?? '' ) ? 'finish' : 'interrupt',
			'entry' => max( 1, min( 30, (int) ( $input['entry'] ?? 3 ) ) ), 'exit' => max( 1, min( 30, (int) ( $input['exit'] ?? 3 ) ) ),
			'bundled_animation' => ! isset( $input['bundled_animation'] ) || ! empty( $input['bundled_animation'] ),
			'animation_ids' => array_values( $animation_ids ),
			'animation_audio_enabled' => ! isset( $input['animation_audio_enabled'] ) || ! empty( $input['animation_audio_enabled'] ),
			'animation_audio_id' => absint( $input['animation_audio_id'] ?? 0 ),
			'animation_audio_volume' => max( 0, min( 100, (int) ( $input['animation_audio_volume'] ?? 100 ) ) ),
			'logo' => absint( $input['logo'] ?? 0 ), 'entry_video' => absint( $input['entry_video'] ?? 0 ), 'exit_video' => absint( $input['exit_video'] ?? 0 ),
			'background' => sanitize_hex_color( $input['background'] ?? '' ) ?: '#111116', 'color' => sanitize_hex_color( $input['color'] ?? '' ) ?: '#ffffff',
			'animation' => in_array( $input['animation'] ?? '', array( 'fade', 'slide', 'none' ), true ) ? $input['animation'] : 'fade',
			'show_text' => ! isset( $input['show_text'] ) || ! empty( $input['show_text'] ), 'sound' => ! empty( $input['sound'] ),
			'entry_text' => sanitize_text_field( $input['entry_text'] ?? 'BSC Music Break' ), 'exit_text' => sanitize_text_field( $input['exit_text'] ?? 'Back to BSC TV' ), 'bands' => $bands,
		);
	}
	private static function hour( $value ) { return is_string( $value ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value ) ? $value : '00:00'; }
	public static function settings() { return self::normalize( get_option( BSC_TV_Stations::key( self::SETTINGS ), array() ) ) + array( 'revision' => (int) get_option( BSC_TV_Stations::key( self::REVISION ), 0 ) ); }
	/** Local BSC identity bed, independent of the curated musical clip library. */
	public static function soundtrack( $settings = null ) {
		$settings = self::normalize( $settings ?? self::settings() );
		$url = '';
		if ( $settings['animation_audio_enabled'] ) {
			$id = $settings['animation_audio_id'];
			$post = $id ? get_post( $id ) : null;
			if ( $post && 'attachment' === $post->post_type && 'inherit' === $post->post_status && ! $post->post_password && 0 === strpos( (string) get_post_mime_type( $id ), 'audio/' ) ) { $url = BSC_TV_Content::url( wp_get_attachment_url( $id ) ); }
			if ( ! $url ) { $url = BSC_TV_URL . 'assets/media/animation-soundtrack.mp3'; }
		}
		return array( 'url' => $url, 'volume' => $settings['animation_audio_volume'] );
	}
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
		delete_transient( BSC_TV_Stations::key( self::CACHE ) );
		update_option( BSC_TV_Stations::key( self::REVISION ), (int) get_option( BSC_TV_Stations::key( self::REVISION ), 0 ) + 1, false );
		BSC_TV_Player_Schedule::request();
	}
	public static function clips() {
		$cached = get_transient( BSC_TV_Stations::key( self::CACHE ) );
		if ( is_array( $cached ) ) { return $cached; }
		$clips = array();
		foreach ( get_posts( array( 'post_type' => self::POST, 'post_status' => 'publish', 'posts_per_page' => 1000, 'orderby' => 'ID', 'order' => 'ASC', 'update_post_term_cache' => false ) ) as $post ) {
			$clip = (array) get_post_meta( $post->ID, self::META, true );
			if ( ( $clip['station'] ?? 'main' ) !== BSC_TV_Stations::current() ) { continue; }
			$clips[ $post->ID ] = $clip + array( 'id' => $post->ID );
		}
		set_transient( BSC_TV_Stations::key( self::CACHE ), $clips, MINUTE_IN_SECONDS );
		return $clips;
	}
	public static function approved( $clip ) {
		return ! empty( $clip['active'] ) && 'confirmed' === ( $clip['rights'] ?? '' ) && ! empty( $clip['rights_note'] ) && ! empty( $clip['official'] ) && 'available' === ( $clip['availability'] ?? '' ) && ( $clip['duration'] ?? 0 ) > 0 && ( $clip['duration'] ?? 0 ) <= HOUR_IN_SECONDS && ! empty( $clip['artist'] ) && ! empty( $clip['title'] ) && preg_match( '/^[\w-]{11}$/D', $clip['youtube_id'] ?? '' );
	}
	public static function in_selection( $clip, $settings = null ) {
		$settings = $settings ?? self::settings();
		$year = (int) ( $clip['year'] ?? 0 );
		return ( ! $settings['year_min'] || $year >= $settings['year_min'] ) && ( ! $settings['year_max'] || ( $year && $year <= $settings['year_max'] ) ) && ( 'all' === $settings['region_filter'] || $settings['region_filter'] === ( $clip['region'] ?? 'other' ) );
	}
	private static function media_url( $id, $type ) {
		return $id && 0 === strpos( (string) get_post_mime_type( $id ), $type . '/' ) ? BSC_TV_Content::url( wp_get_attachment_url( $id ) ) : '';
	}
	public static function animation_asset( $id, $fallback = 0 ) {
		if ( ! $id || 'attachment' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) { return null; }
		$url = self::media_url( $id, 'video' );
		if ( BSC_TV_Player::obsolete_animation( $url ) ) { return null; }
		$metadata = $id ? (array) wp_get_attachment_metadata( $id ) : array();
		$length = is_numeric( $metadata['length'] ?? null ) ? (float) $metadata['length'] : 0;
		// WordPress may round video lengths to whole seconds; reserve the final frame too.
		$duration = $length > 0 ? (int) ceil( $length ) + ( floor( $length ) === $length ? 1 : 0 ) : (int) $fallback;
		return $url && $duration > 0 && $length <= 120 ? array( 'id' => $id, 'url' => $url, 'duration' => $duration, 'name' => get_the_title( $id ) ) : null;
	}
	public static function animations( $settings = null ) {
		$settings = $settings ?? self::settings();
		$choices = array();
		if ( $settings['bundled_animation'] ) {
			$choices[] = array( 'id' => 'circus', 'url' => BSC_TV_URL . 'assets/media/ident-circus.mp4', 'duration' => 11, 'name' => 'BSC · Manège' );
			$choices[] = array( 'id' => 'moon', 'url' => BSC_TV_URL . 'assets/media/ident-moon.mp4', 'duration' => 11, 'name' => 'BSC · Lune' );
		}
		foreach ( $settings['animation_ids'] ?? array() as $id ) {
			$asset = self::animation_asset( $id );
			if ( $asset && ! in_array( $asset['url'], array_column( $choices, 'url' ), true ) ) { $choices[] = $asset; }
		}
		return $choices;
	}
	private static function with_animations( $media, $state, $cursor, $seed ) {
		$music = $media['music']; $choices = $music['animation_choices'] ?? array();
		foreach ( array( 'entry', 'exit' ) as $phase ) {
			if ( ! empty( $music['identity'][ $phase . '_video' ] ) || ! $choices ) { continue; }
			$avoid = 'entry' === $phase ? ( $state['music_last_entry_animation'] ?? '' ) : ( $music['identity']['entry_video'] ?? '' );
			$candidates = array_values( array_filter( $choices, static function ( $asset ) use ( $avoid ) { return $asset['url'] !== $avoid; } ) ) ?: $choices;
			$asset = null; $best_rank = null; $salt = $seed . '|' . $cursor . '|' . $phase . '|' . $media['key'];
			foreach ( $candidates as $candidate ) { $rank = hash( 'sha256', $salt . '|' . $candidate['url'] ); if ( null === $best_rank || strcmp( $rank, $best_rank ) < 0 ) { $asset = $candidate; $best_rank = $rank; } }
			$music[ $phase ] = $asset['duration']; $music['identity'][ $phase . '_video' ] = $asset['url']; $music['identity'][ $phase . '_name' ] = $asset['name'];
		}
		unset( $music['animation_choices'] );
		$media['music'] = $music; $media['duration'] = $music['duration'] + $music['entry'] + $music['exit'];
		return $media;
	}
	public static function pool( $state = null ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			$state = $state ?? (array) get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), array() );
			if ( empty( $state['vod']['music'] ) || 'finish' !== $settings['disable_policy'] ) { return array(); }
		}
		$identity = array_intersect_key( $settings, array_flip( array( 'background', 'color', 'animation', 'show_text', 'sound', 'entry_text', 'exit_text' ) ) );
		$entry = self::animation_asset( $settings['entry_video'], $settings['entry'] ); $exit = self::animation_asset( $settings['exit_video'], $settings['exit'] );
		$identity += array( 'logo' => self::media_url( $settings['logo'], 'image' ), 'entry_video' => $entry['url'] ?? '', 'exit_video' => $exit['url'] ?? '' );
		$choices = self::animations( $settings );
		$minimum = $choices ? min( array_column( $choices, 'duration' ) ) : null;
		$entry_duration = $entry['duration'] ?? $minimum ?? $settings['entry']; $exit_duration = $exit['duration'] ?? $minimum ?? $settings['exit'];
		$pool = array();
		foreach ( self::clips() as $id => $clip ) {
			if ( ! self::approved( $clip ) || ! self::in_selection( $clip, $settings ) ) { continue; }
			$key = 'music:' . $id . ':' . $clip['youtube_id'];
			if ( ! $settings['enabled'] && ( $state['vod']['key'] ?? '' ) !== $key ) { continue; }
			$music = array_intersect_key( $clip, array_flip( array( 'artist', 'title', 'year', 'youtube_id', 'channel', 'image', 'duration', 'categories', 'region' ) ) );
			$music += array( 'clip_id' => $id, 'entry' => $entry_duration, 'exit' => $exit_duration, 'identity' => $identity, 'animation_choices' => $choices );
			$pool[ $key ] = array( 'key' => $key, 'show_id' => 0, 'channel_id' => 0, 'creator_key' => 'music:' . strtolower( $clip['artist'] ), 'creator' => $clip['artist'], 'title' => $clip['title'], 'resource' => array( 'platform' => 'youtube', 'id' => $clip['youtube_id'] ), 'image' => $clip['image'] ?? '', 'duration' => $clip['duration'] + $entry_duration + $exit_duration, 'published_at' => 0, 'premiere' => false, 'music' => $music );
		}
		return $pool;
	}
	/** Choose inside a gap, or delay an automatic prefix within its fixed-anchor budget. */
	public static function between( $next, $pool, $state, $settings, $cursor ) {
		$config = $settings['music'] ?? array();
		$previous = $state['music_previous'] ?? $state['vod'] ?? null;
		if ( ! $next || empty( $config['enabled'] ) || ! $previous || ! empty( $previous['music'] ) || ! empty( $next['music'] ) || ! empty( $state['live'] ) || $previous['end'] !== $cursor || $previous['end'] - $previous['start'] < $config['threshold'] * MINUTE_IN_SECONDS ) { return null; }
		$category = self::category( $cursor, $config );
		if ( ! $category ) { return null; }
		$space = self::space( $next, $state, $config, $cursor, $settings['repeat_hours'] ?? 0 );
		$seed = $state['music_seed'] ?? $state['rotation_seed'] ?? 'bsc-music';
		$candidates = array();
		foreach ( $pool as $key => $media ) {
			$clip = $media['music'] ?? null;
			if ( ! $clip || ! in_array( $category, $clip['categories'] ?? array(), true ) ) { continue; }
			$artist = strtolower( trim( $clip['artist'] ) ); $last = $state['music_rotation'][ $key ] ?? 0;
			if ( $artist === ( $state['music_last_artist'] ?? '' ) || ( $last && $last + $config['repeat_hours'] * HOUR_IN_SECONDS > $cursor ) ) { continue; }
			$media = self::with_animations( $media, $state, $cursor, $seed );
			if ( $media['duration'] > $space ) { continue; }
			$overlap = false;
			foreach ( $media['music_deadlines'] ?? array() as $deadline ) { if ( $deadline >= $cursor && $deadline < $cursor + $media['duration'] ) { $overlap = true; break; } }
			if ( $overlap ) { continue; }
			$prefer = 'day' === $category && in_array( $clip['region'] ?? '', array( 'us', 'fr' ), true ) && ( $clip['region'] ?? '' ) !== ( $state['music_last_region'] ?? '' );
			$media['music']['category'] = $category;
			$media['music']['category_label'] = $config['bands'][ $category ]['name'];
			$candidates[] = $media + array( 'available' => $cursor, 'music_prefer' => $prefer, 'music_last' => $last, 'music_rank' => hash( 'sha256', $seed . '|' . $cursor . '|' . $previous['key'] . '|' . $key ) );
		}
		usort( $candidates, static function ( $a, $b ) { return $b['music_prefer'] <=> $a['music_prefer'] ?: ( $a['music_last'] > 0 ) <=> ( $b['music_last'] > 0 ) ?: strcmp( $a['music_rank'], $b['music_rank'] ); } );
		return $candidates[0] ?? null;
	}
	/** Only automatic rows before the next fixed anchor may be delayed. */
	public static function space( $next, $state, $config, $cursor, $repeat_hours = 0 ) {
		$gap = max( 0, $next['available'] - $cursor );
		if ( empty( $config['flexible'] ) || ! empty( $next['fixed'] ) ) { return $gap; }
		$end = $cursor; $last = array(); $anchored = false; $slack = PHP_INT_MAX;
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( $row['fixed'] ?? true ) {
				if ( ! $anchored ) { $slack = max( 0, $row['start'] - $end ); $anchored = true; }
				// Delaying a repeat must not make a later fixed replay miss its availability window.
				if ( isset( $last[ $row['key'] ] ) ) { $slack = min( $slack, max( 0, $row['start'] - $last[ $row['key'] ] - $repeat_hours * HOUR_IN_SECONDS ) ); }
			} elseif ( ! $anchored ) { $end = max( $end, $row['end'] ); $last[ $row['key'] ] = max( $last[ $row['key'] ] ?? 0, $row['end'] ); }
		}
		return $anchored ? $gap + $slack : PHP_INT_MAX;
	}
	public static function delay_automatic( &$state, $seconds ) {
		foreach ( $state['plan'] ?? array() as $i => $row ) {
			if ( $row['fixed'] ?? true ) { break; }
			$state['plan'][ $i ]['start'] += $seconds; $state['plan'][ $i ]['end'] += $seconds;
		}
	}
	/** Rebuild future breaks with their new durations while keeping fixed programmes and the current slot. */
	public static function reset_future( $state, $now ) {
		$cursor = ! empty( $state['live'] ) ? ( $state['vod']['end'] ?? $state['plan'][0]['start'] ?? $now ) : max( $now, $state['vod']['end'] ?? $now );
		$release = 0; $rows = array();
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( ! empty( $row['music'] ) ) { $release += $row['end'] - $row['start']; continue; }
			if ( $row['fixed'] ?? true ) { $release = 0; }
			else {
				$start = max( $cursor, $row['start'] - $release );
				$row['end'] += $start - $row['start']; $row['start'] = $start;
			}
			$cursor = $row['end']; $rows[] = $row;
		}
		$state['plan'] = $rows; $state['plan_source'] = '';
		return $state;
	}
	public static function begun( &$state, $slot ) {
		$state['music_previous'] = $slot;
		if ( empty( $slot['music'] ) ) { return; }
		$state['music_rotation'][ $slot['key'] ] = $slot['end'];
		$state['music_last_artist'] = strtolower( trim( $slot['music']['artist'] ) );
		$state['music_last_region'] = $slot['music']['region'] ?? '';
		$state['music_last_entry_animation'] = $slot['music']['identity']['entry_video'] ?? '';
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
	/** A daily-list row describes the complete persisted break, with the clip's real source and timings. */
	public static function programme( $slot, $now ) {
		$item = self::decorate( $slot, array(), $now ); $music = $slot['music'];
		$item['start'] = $slot['start']; $item['end'] = $slot['end'];
		$item['url'] = 'https://www.youtube.com/watch?v=' . $music['youtube_id'];
		$item['resource']['platform'] = 'youtube';
		$item['clip_start'] = $slot['start'] + $music['entry']; $item['clip_end'] = $item['clip_start'] + $music['duration'];
		$item['music_entry'] = $music['entry']; $item['music_exit'] = $music['exit'];
		$item['music_category'] = $music['category_label'] ?? '';
		return $item;
	}
	public static function diagnostic( $state = null ) {
		$config = self::settings(); $clips = self::clips();
		$counts = array_fill_keys( array( 'ready', 'inactive', 'rights', 'official', 'availability', 'duration', 'artist', 'selection' ), 0 );
		$categories = array_fill_keys( array_keys( $config['bands'] ), 0 );
		foreach ( $clips as $clip ) {
			if ( self::approved( $clip ) && self::in_selection( $clip, $config ) ) { ++$counts['ready']; foreach ( $clip['categories'] ?? array() as $category ) { if ( isset( $categories[ $category ] ) ) { ++$categories[ $category ]; } } }
			$counts['selection'] += self::in_selection( $clip, $config ) ? 0 : 1;
			$counts['inactive'] += empty( $clip['active'] ) ? 1 : 0;
			$counts['rights'] += 'confirmed' !== ( $clip['rights'] ?? '' ) || empty( $clip['rights_note'] ) ? 1 : 0;
			$counts['official'] += empty( $clip['official'] ) ? 1 : 0;
			$counts['availability'] += 'available' !== ( $clip['availability'] ?? '' ) ? 1 : 0;
			$counts['duration'] += ( $clip['duration'] ?? 0 ) < 1 || ( $clip['duration'] ?? 0 ) > 3600 ? 1 : 0;
			$counts['artist'] += empty( $clip['artist'] ) || empty( $clip['title'] ) ? 1 : 0;
		}
		$state = $state ?? (array) get_option( BSC_TV_Stations::key( BSC_TV_Player::STATE ), array() );
		return array( 'total' => count( $clips ), 'counts' => $counts, 'categories' => $categories, 'planned' => count( array_filter( $state['plan'] ?? array(), static function ( $row ) { return ! empty( $row['music'] ); } ) ), 'enabled' => $config['enabled'], 'flexible' => $config['flexible'], 'entry' => $config['entry'], 'exit' => $config['exit'], 'bundled_animation' => $config['bundled_animation'] );
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
		if ( is_wp_error( $result ) ) { update_option( BSC_TV_Stations::key( self::REPORT ), array( 'at' => time(), 'error' => $result->get_error_message() ), false ); return; }
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
		update_option( BSC_TV_Stations::key( self::REPORT ), array( 'at' => time(), 'error' => $result['failed'] ? 'Certaines métadonnées n’ont pas pu être vérifiées.' : '' ), false );
		self::invalidate();
	}
}
