<?php

defined( 'ABSPATH' ) || exit;

/** A bounded, editable future queue inside the existing shared player clock. */
final class BSC_TV_Player_Schedule {
	const MAX_SLOTS = 500;
	const HOURS = 72;
	const HOURLY = 'bsc_tv_player_schedule_hourly';
	const NOW = 'bsc_tv_player_schedule_now';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOURLY, array( __CLASS__, 'refresh' ) );
		add_action( self::NOW, array( __CLASS__, 'refresh' ) );
		add_action( 'bsc_tv_player_catalogue_changed', array( __CLASS__, 'request' ) );
		add_action( 'updated_option', array( __CLASS__, 'configuration_changed' ) );
		add_action( 'added_option', array( __CLASS__, 'configuration_changed' ) );
	}

	public static function schedule() {
		if ( ! BSC_TV_Player::settings()['enabled'] ) { self::deactivate(); return; }
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
			self::request();
		}
	}

	public static function deactivate() {
		foreach ( array( self::HOURLY, self::NOW ) as $hook ) {
			if ( wp_next_scheduled( $hook ) ) { wp_clear_scheduled_hook( $hook ); }
		}
	}

	public static function request() {
		if ( BSC_TV_Player::settings()['enabled'] && ! wp_next_scheduled( self::NOW ) ) {
			wp_schedule_single_event( time() + 15, self::NOW );
		}
	}

	public static function configuration_changed( $option ) {
		if ( BSC_TV_Player::SETTINGS === $option ) { self::schedule(); self::request(); }
	}

	public static function refresh() {
		if ( ! BSC_TV_Player::settings()['enabled'] ) { return; }
		$result = self::prepare();
		if ( is_wp_error( $result ) ) { self::request(); }
	}

	/** Only values which affect selection; presentation updates do not reshuffle the queue. */
	public static function signature( $pool, $settings ) {
		$selection = array();
		$has_music = false;
		foreach ( $pool as $key => $media ) {
			$has_music = $has_music || ! empty( $media['music'] );
			$selection[ $key ] = array( $media['show_id'], $media['duration'], $media['published_at'], $media['channel_id'], $media['creator_key'], ! empty( $media['premiere'] ) );
		}
		ksort( $selection );
		$signature = array( 'mixed-v1', $selection, $settings['recent'], $settings['repeat_hours'], $settings['timezone'] ?? 'UTC' );
		// An untouched, disabled music module must preserve pre-existing automatic choices.
		if ( ! empty( $settings['music']['enabled'] ) || $has_music ) { $signature[] = array( 'random-music-v3-animations', $settings['music'] ?? array() ); }
		return hash( 'sha256', json_encode( $signature ) );
	}

	public static function needs_refresh( $state, $pool, $settings, $now ) {
		if ( empty( $settings['enabled'] ) || ! $pool ) { return false; }
		if ( ( $state['plan_source'] ?? '' ) !== self::signature( $pool, $settings ) ) { return true; }
		if ( $now - ( $state['plan_checked_at'] ?? 0 ) < 5 * MINUTE_IN_SECONDS || count( $state['plan'] ?? array() ) >= self::MAX_SLOTS ) { return false; }
		$rows = $state['plan'] ?? array();
		$last = $rows ? end( $rows ) : array();
		return ( $last['end'] ?? 0 ) < $now + 48 * HOUR_IN_SECONDS;
	}

	/** Refresh new catalogue choices before a transition, never after a saved slot has already begun. */
	public static function before_advance( $state, $pool, $settings, $now ) {
		$safe = ! empty( $state['live'] ) || ( ! empty( $state['vod'] ) && $state['vod']['end'] >= $now ) || ( empty( $state['vod'] ) && ! empty( $state['plan'] ) && $state['plan'][0]['start'] >= $now );
		if ( $safe && ! empty( $settings['enabled'] ) && ( $state['plan_source'] ?? '' ) !== self::signature( $pool, $settings ) ) {
			return self::replenish( $state, $pool, $settings, $now );
		}
		return $state;
	}

	/** Pure automatic fill. A catalogue change may refresh only the tail after the last manual choice. */
	public static function replenish( $state, $pool, $settings, $now, $force = false ) {
		if ( ! $force && ! self::needs_refresh( $state, $pool, $settings, $now ) ) { return $state; }
		$source = self::signature( $pool, $settings );
		if ( ( $state['plan_source'] ?? '' ) !== $source ) {
			$keep = 0;
			foreach ( $state['plan'] ?? array() as $index => $row ) {
				// Entries saved by older versions have no marker: preserve them conservatively.
				if ( $row['fixed'] ?? true ) { $keep = $index + 1; }
			}
			$state['plan'] = array_slice( $state['plan'] ?? array(), 0, $keep );
		}
		$state['plan'] = self::build( $state, $pool, $settings, $now );
		$state['plan_source'] = $source;
		$state['plan_checked_at'] = $now;
		return $state;
	}

	public static function fingerprint( $state ) {
		return hash( 'sha256', json_encode( $state['plan'] ?? array() ) );
	}

	/** Three calendar days, including actual earlier broadcasts and programmes spanning midnight. */
	public static function days( $state, $now, $timezone ) {
		$bounds = BSC_TV_Player::day_bounds( $now, $timezone, 3 );
		$date = ( new DateTimeImmutable( '@' . $bounds[0] ) )->setTimezone( new DateTimeZone( $timezone ?: 'UTC' ) );
		$groups = array();
		$rows = $state['aired'] ?? array();
		$current = $state['live'] ?? $state['vod'] ?? null;
		$found = false;
		foreach ( $rows as $row ) { if ( $current && $row['key'] === $current['key'] && ( ! $row['end'] || $row['end'] > $now ) ) { $found = true; } }
		if ( $current && ! $found ) { $rows[] = $current + array( 'live' => ! empty( $state['live'] ) ); }
		foreach ( $state['plan'] ?? array() as $row ) { $rows[] = $row + array( 'scheduled' => true ); }
		for ( $day = 0; $day < 3; ++$day ) {
			$start = $date->getTimestamp();
			$date = $date->modify( '+1 day' );
			$end = $date->getTimestamp();
			$key = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( new DateTimeZone( $timezone ?: 'UTC' ) )->format( 'Y-m-d' );
			$groups[ $key ] = array( 'start' => $start, 'end' => $end, 'rows' => array() );
			$seen = array();
			foreach ( $rows as $row ) {
				if ( $row['end'] && $row['end'] <= $row['start'] ) { continue; }
				$row_end = $row['end'] ?: $now + 1;
				if ( $row['start'] >= $end || $row_end <= $start ) { continue; }
				$id = $row['key'] . ':' . $row['start'];
				if ( isset( $seen[ $id ] ) ) { continue; }
				$seen[ $id ] = true;
				$row['display_start'] = max( $start, $row['start'] );
				$row['display_end'] = min( $end, $row_end );
				$row['continued'] = $row['start'] < $start;
				$row['phase'] = ! empty( $row['scheduled'] ) ? 'future' : ( $row['end'] && $row['end'] <= $now ? 'past' : 'current' );
				$groups[ $key ]['rows'][] = $row;
			}
			usort( $groups[ $key ]['rows'], static function ( $a, $b ) { return $a['display_start'] <=> $b['display_start']; } );
		}
		return $groups;
	}

	/** Pure projection: use the same selection and transition rules as the real player. */
	public static function build( $state, $pool, $settings, $now ) {
		if ( class_exists( 'BSC_TV_Music' ) ) { $state = BSC_TV_Music::reconcile( $state, $pool, $settings, $now ); }
		$forecast = $state;
		$forecast['plan'] = array_values( array_filter( $forecast['plan'] ?? array(), static function ( $row ) use ( $pool ) { return ! empty( $row['id'] ) && isset( $pool[ $row['key'] ] ); } ) );
		// A live freezes the replay clock. Merely viewing the admin must not add its pause twice.
		$cursor = ! empty( $state['live'] ) ? ( $state['vod']['end'] ?? $state['plan'][0]['start'] ?? $state['paused_at'] ?? $now ) : max( $now, $state['vod']['end'] ?? $now );
		$until = $now + self::HOURS * HOUR_IN_SECONDS;
		$rows = array();
		while ( count( $rows ) < self::MAX_SLOTS && ( $cursor < $until || $forecast['plan'] ) ) {
			$media = BSC_TV_Player::select_next( $pool, $forecast, $settings, $cursor );
			if ( ! $media || ( ! $forecast['plan'] && $media['available'] >= $until ) ) { break; }
			$slot = BSC_TV_Player::begin( $media, $forecast );
			$slot['id'] = $slot['id'] ?? substr( hash( 'sha256', $slot['key'] . ':' . $slot['start'] . ':' . count( $rows ) ), 0, 24 );
			$rows[] = $slot;
			$cursor = $slot['end'];
		}
		return $rows;
	}

	/** Pure edit; reject stale forms before touching a single slot. */
	public static function change( $state, $pool, $settings, $now, $operation, $slot_id, $value, $fingerprint ) {
		if ( ! hash_equals( self::fingerprint( $state ), $fingerprint ) ) {
			throw new InvalidArgumentException( 'La grille a changé depuis son affichage. Rechargez-la avant de modifier ce programme.' );
		}
		$index = array_search( $slot_id, array_column( $state['plan'] ?? array(), 'id' ), true );
		if ( false === $index || ( empty( $state['live'] ) && $state['plan'][ $index ]['start'] < $now ) ) {
			throw new InvalidArgumentException( 'Ce programme n’est plus modifiable : il a commencé ou a quitté la grille.' );
		}
		$row = $state['plan'][ $index ];
		if ( ! empty( $row['music'] ) ) { throw new InvalidArgumentException( 'Gérez les interludes depuis BSC TV → Music Interludes.' ); }
		if ( 'move' === $operation ) {
			$minimum = max( $now, $index ? $state['plan'][ $index - 1 ]['end'] : ( $state['vod']['end'] ?? $now ) );
			if ( $value < $minimum || $value >= $now + self::HOURS * HOUR_IN_SECONDS ) {
				throw new InvalidArgumentException( 'Choisissez une heure dans les 72 prochaines heures, après la fin du programme précédent.' );
			}
			$delta = $value - $row['start'];
			$state['plan'][ $index ]['start'] = $value;
			$state['plan'][ $index ]['end'] += $delta;
		} elseif ( 'replace' === $operation ) {
			$matches = array_filter( $pool, static function ( $media ) use ( $value ) { return empty( $media['music'] ) && $value > 0 && $media['show_id'] === $value; } );
			if ( ! $matches ) { throw new InvalidArgumentException( 'Choisissez un replay disponible dans le catalogue du lecteur.' ); }
			$media = reset( $matches );
			$replacement = array_intersect_key( $media, array_fill_keys( array( 'show_id', 'key', 'resource', 'channel_id', 'premiere' ), true ) );
			$state['plan'][ $index ] = array_replace( $row, $replacement, array( 'end' => $row['start'] + $media['duration'] ) );
			$delta = $state['plan'][ $index ]['end'] - $row['end'];
		} else {
			throw new InvalidArgumentException( 'Action de programmation inconnue.' );
		}
		$state['plan'][ $index ]['fixed'] = true;
		for ( $next = $index + 1; $next < count( $state['plan'] ); ++$next ) {
			$state['plan'][ $next ]['start'] += $delta;
			$state['plan'][ $next ]['end'] += $delta;
		}
		$state['plan'] = self::build( $state, $pool, $settings, $now );
		if ( 'move' === $operation && $state['plan'][ $index ]['start'] !== $value ) {
			throw new InvalidArgumentException( 'Cet horaire ne respecte pas la disponibilité ou le délai entre rediffusions. Choisissez une heure plus tardive.' );
		}
		return $state;
	}

	private static function clock() {
		wp_cache_delete( BSC_TV_Player::STATE, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (array) get_option( BSC_TV_Player::STATE, array() );
	}

	/** Administration and background refresh share the same locked clock and catalogue. */
	public static function prepare() {
		$pool = BSC_TV_Player::media();
		$settings = BSC_TV_Player::settings();
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) { return new WP_Error( 'schedule_busy', 'La programmation est en cours de mise à jour. Réessayez.' ); }
		try {
			$now = time();
			$stored = self::clock();
			$state = BSC_TV_Player::advance_clock( $stored, $now );
			$state = self::replenish( $state, $pool, $settings, $now, true );
			if ( $state !== $stored ) {
				update_option( BSC_TV_Player::STATE, $state, false );
			}
			return $state;
		} finally {
			BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock );
		}
	}

	public static function queue( $operation, $id, $current_key = '', $now = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'schedule_forbidden', 'Action réservée aux administrateurs.' ); }
		$pool = BSC_TV_Player::media();
		$settings = BSC_TV_Player::settings();
		if ( ! in_array( $operation, array( 'next', 'clear', 'skip' ), true ) || ( 'next' === $operation && ( $id < 1 || ! in_array( $id, array_column( $pool, 'show_id' ), true ) ) ) ) {
			return new WP_Error( 'schedule_invalid', 'Action ou replay de programmation indisponible.' );
		}
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) { return new WP_Error( 'schedule_busy', 'La programmation est en cours de mise à jour. Réessayez.' ); }
		try {
			$now = $now ?? time();
			// Consume overdue slots before changing priorities, even if the admin page was left open.
			$state = BSC_TV_Player::advance_clock( self::clock(), $now );
			if ( 'next' === $operation ) {
				$manual = array_values( array_diff( $state['manual'] ?? array(), array( $id ) ) );
				array_unshift( $manual, $id );
				$state['manual'] = array_slice( $manual, 0, 20 );
			} elseif ( 'clear' === $operation ) {
				$state['manual'] = array();
				$state['plan'] = array();
				$state['rotation_seed'] = bin2hex( random_bytes( 16 ) );
			} else {
				if ( ! empty( $state['live'] ) || empty( $state['vod'] ) || ! hash_equals( $state['vod']['key'] . ':' . $state['vod']['start'], $current_key ) ) {
					update_option( BSC_TV_Player::STATE, $state, false );
					return new WP_Error( 'schedule_stale', 'Le programme en cours a changé. Rechargez la page avant de passer au suivant.' );
				}
				if ( ! empty( $state['vod']['music'] ) ) { BSC_TV_Music::interrupt( $state, $now ); }
				else {
					BSC_TV_Player::shift_plan( $state, $now - $state['vod']['end'] );
					$state['vod']['end'] = $now;
					$state['history'][ $state['vod']['key'] ]['end'] = $now;
					foreach ( $state['aired'] ?? array() as $index => $row ) { if ( empty( $row['live'] ) && $row['start'] <= $now && $row['end'] > $now ) { $state['aired'][ $index ]['end'] = $now; } }
				}
			}
			$state['plan'] = self::build( $state, $pool, $settings, $now );
			update_option( BSC_TV_Player::STATE, $state, false );
			return true;
		} finally {
			BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock );
		}
	}

	public static function update( $operation, $slot_id, $value, $fingerprint ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'schedule_forbidden', 'Action réservée aux administrateurs.' ); }
		$pool = BSC_TV_Player::media();
		$settings = BSC_TV_Player::settings();
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) { return new WP_Error( 'schedule_busy', 'La programmation est en cours de mise à jour. Réessayez.' ); }
		try {
			$now = time();
			$stored = self::clock();
			$state = BSC_TV_Player::advance_clock( $stored, $now );
			if ( $state !== $stored ) { update_option( BSC_TV_Player::STATE, $state, false ); }
			$state = self::change( $state, $pool, $settings, $now, $operation, $slot_id, $value, $fingerprint );
			update_option( BSC_TV_Player::STATE, $state, false );
			return true;
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'schedule_invalid', $exception->getMessage() );
		} finally {
			BSC_TV_Content::unlock( BSC_TV_Player::LOCK, $lock );
		}
	}
}
