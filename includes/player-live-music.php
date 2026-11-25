<?php
defined( 'ABSPATH' ) || exit;

/** A bounded musical overlay after a missed live; the original plan stays untouched. */
final class BSC_TV_Player_Live_Music {
	const WAIT_MINUTES = 20;

	public static function fallback( $state, $pool, $settings, $now ) {
		if ( empty( $settings['music']['enabled'] ) || ! array_filter( $pool, static function ( $media ) { return ! empty( $media['music'] ); } ) ) { return null; }
		$events = array_values( $settings['_announcements'] ?? array() );
		usort( $events, static function ( $a, $b ) { return $b['expected_start'] <=> $a['expected_start'] ?: $b['priority'] <=> $a['priority']; } );
		foreach ( $events as $event ) {
			$cutoff = $event['expected_start'] + self::WAIT_MINUTES * MINUTE_IN_SECONDS;
			$finished = $state['live_finished'][ BSC_TV_Player::resource_key( $event['resource'] ) ] ?? 0;
			if ( $cutoff > $now || $event['expected_start'] <= $finished ) { continue; }
			$until = PHP_INT_MAX;
			foreach ( array_merge( array_filter( array( $state['vod'] ?? null ) ), $state['plan'] ?? array() ) as $row ) {
				if ( empty( $row['music'] ) && $row['start'] >= $cutoff ) { $until = min( $until, $row['start'] ); }
			}
			foreach ( $events as $next ) { if ( $next['start'] > $cutoff ) { $until = min( $until, $next['start'] ); } }
			if ( PHP_INT_MAX === $until || $now >= $until ) { continue; }
			$saved = $state['standby'] ?? array();
			$rows = ( $saved['key'] ?? '' ) === $event['key'] && ( $saved['relay_until'] ?? 0 ) === $until ? ( $saved['relay_slots'] ?? array() ) : array();
			// A revoked clip must disappear immediately, including from an already prepared relay.
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $pool, $settings ) {
				$media = $pool[ $row['key'] ] ?? array();
				$category = BSC_TV_Music::category( $row['start'], $settings['music'] );
				return ! empty( $media['music'] ) && $category && in_array( $category, $media['music']['categories'] ?? array(), true );
			} ) );
			$cursor = $cutoff;
			$forecast = $state;
			foreach ( $rows as $row ) { $cursor = max( $cursor, $row['end'] ); BSC_TV_Music::begun( $forecast, $row ); }
			if ( ! $rows && ! empty( $saved['relay_until'] ) ) { $cursor = max( $cursor, $now ); }
			while ( count( $rows ) < 100 && $cursor < $until ) {
				$media = BSC_TV_Music::waiting( $pool, $forecast, $settings, $cursor, $until );
				if ( ! $media ) { break; }
				$row = array_intersect_key( $media, array_flip( array( 'key', 'resource', 'channel_id', 'show_id', 'music' ) ) );
				$row += array( 'start' => $cursor, 'end' => $cursor + $media['duration'], 'waiting_music' => true );
				$rows[] = $row;
				BSC_TV_Music::begun( $forecast, $row );
				$cursor = $row['end'];
			}
			if ( ! $rows ) { return null; }
			$event['relay_start'] = $cutoff; $event['relay_until'] = $until; $event['relay_slots'] = $rows;
			$event['start'] = ( $saved['key'] ?? '' ) === $event['key'] ? $saved['start'] : $cutoff;
			$event['relay_current'] = ( $saved['key'] ?? '' ) === $event['key'] ? ( $saved['relay_current'] ?? '' ) : '';
			return $event;
		}
		return null;
	}

	public static function current( $waiting, $now ) {
		foreach ( $waiting['relay_slots'] ?? array() as $row ) { if ( $row['start'] <= $now && $now < $row['end'] ) { return $row; } }
		return null;
	}
	public static function gap( $waiting, $now ) {
		$start = $waiting['relay_start']; $end = $waiting['relay_until'];
		foreach ( $waiting['relay_slots'] as $row ) {
			if ( $row['end'] <= $now ) { $start = max( $start, $row['end'] ); }
			if ( $row['start'] > $now ) { $end = min( $end, $row['start'] ); }
		}
		return array( $start, $end );
	}

	/** Log only the slot actually reached, keeping its music phase on the shared clock. */
	public static function sync( $state, $now ) {
		$waiting = $state['standby'];
		$row = self::current( $waiting, $now );
		if ( ! $row ) { return $state; }
		$id = $row['key'] . ':' . $row['start'];
		if ( ( $waiting['relay_current'] ?? '' ) !== $id ) {
			foreach ( $state['aired'] ?? array() as $i => $aired ) {
				if ( $aired['start'] <= $now && ( ! $aired['end'] || $aired['end'] > $now ) ) { $state['aired'][ $i ]['end'] = min( $now, $row['start'] ); }
			}
			$row['music_origin'] = $row['start'];
			BSC_TV_Player::log_slot( $state, $row, $now );
			BSC_TV_Music::begun( $state, $row );
			$state['standby']['relay_current'] = $id;
		}
		BSC_TV_Music::tally( $state, $row, $now );
		return $state;
	}
}
