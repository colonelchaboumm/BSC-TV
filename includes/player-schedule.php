<?php

defined( 'ABSPATH' ) || exit;

/** A bounded, editable future queue inside the existing shared player clock. */
final class BSC_TV_Player_Schedule {
	const MAX_SLOTS = 500;
	const HOURS = 72;

	public static function fingerprint( $state ) {
		return hash( 'sha256', json_encode( $state['plan'] ?? array() ) );
	}

	/** Pure projection: use the same selection and transition rules as the real player. */
	public static function build( $state, $pool, $settings, $now ) {
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
		if ( 'move' === $operation ) {
			$minimum = max( $now, $index ? $state['plan'][ $index - 1 ]['end'] : ( $state['vod']['end'] ?? $now ) );
			if ( $value < $minimum || $value >= $now + self::HOURS * HOUR_IN_SECONDS ) {
				throw new InvalidArgumentException( 'Choisissez une heure dans les 72 prochaines heures, après la fin du programme précédent.' );
			}
			$delta = $value - $row['start'];
			$state['plan'][ $index ]['start'] = $value;
			$state['plan'][ $index ]['end'] += $delta;
		} elseif ( 'replace' === $operation ) {
			$matches = array_filter( $pool, static function ( $media ) use ( $value ) { return $media['show_id'] === $value; } );
			if ( ! $matches ) { throw new InvalidArgumentException( 'Choisissez un replay disponible dans le catalogue du lecteur.' ); }
			$media = reset( $matches );
			$replacement = array_intersect_key( $media, array_fill_keys( array( 'show_id', 'key', 'resource', 'channel_id', 'premiere' ), true ) );
			$state['plan'][ $index ] = array_replace( $row, $replacement, array( 'end' => $row['start'] + $media['duration'] ) );
			$delta = $state['plan'][ $index ]['end'] - $row['end'];
		} else {
			throw new InvalidArgumentException( 'Action de programmation inconnue.' );
		}
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

	/** Called only by the administration. Public visitors consume the saved queue. */
	public static function prepare() {
		$pool = BSC_TV_Player::media();
		$settings = BSC_TV_Player::settings();
		$lock = BSC_TV_Content::lock( BSC_TV_Player::LOCK );
		if ( ! $lock ) { return new WP_Error( 'schedule_busy', 'La programmation est en cours de mise à jour. Réessayez.' ); }
		try {
			$now = time();
			$stored = self::clock();
			$state = BSC_TV_Player::advance_clock( $stored, $now );
			$state['plan'] = self::build( $state, $pool, $settings, $now );
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
		if ( ! in_array( $operation, array( 'next', 'clear', 'skip' ), true ) || ( 'next' === $operation && ! in_array( $id, array_column( $pool, 'show_id' ), true ) ) ) {
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
			} else {
				if ( ! empty( $state['live'] ) || empty( $state['vod'] ) || ! hash_equals( $state['vod']['key'] . ':' . $state['vod']['start'], $current_key ) ) {
					update_option( BSC_TV_Player::STATE, $state, false );
					return new WP_Error( 'schedule_stale', 'Le programme en cours a changé. Rechargez la page avant de passer au suivant.' );
				}
				BSC_TV_Player::shift_plan( $state, $now - $state['vod']['end'] );
				$state['vod']['end'] = $now;
				$state['history'][ $state['vod']['key'] ]['end'] = $now;
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
