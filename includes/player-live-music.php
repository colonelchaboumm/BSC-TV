<?php
defined( 'ABSPATH' ) || exit;

/** A bounded musical overlay after a missed live; the original plan stays untouched. */
final class BSC_TV_Player_Live_Music {
	const WAIT_MINUTES = 20;

	const MAX_RELAY_MINUTES = 5;

	public static function fallback( $state, $pool, $settings, $now ) {
		if ( empty( $settings['music']['enabled'] ) ) { return null; }
		$events = array_values( $settings['_announcements'] ?? array() );
		usort( $events, static function ( $a, $b ) { return $b['expected_start'] <=> $a['expected_start'] ?: $b['priority'] <=> $a['priority']; } );
		foreach ( $events as $event ) {
			$cutoff = $event['expected_start'] + self::WAIT_MINUTES * MINUTE_IN_SECONDS;
			$finished = $state['live_finished'][ BSC_TV_Player::resource_key( $event['resource'] ) ] ?? 0;
			if ( ! empty( $state['live_wait_done'][ $event['key'] ] ) || $cutoff > $now || $event['expected_start'] <= $finished ) { continue; }
			$until = $cutoff + self::MAX_RELAY_MINUTES * MINUTE_IN_SECONDS;
			foreach ( array_merge( array_filter( array( $state['vod'] ?? null ) ), $state['plan'] ?? array() ) as $row ) {
				if ( empty( $row['music'] ) && $row['start'] >= $cutoff ) { $until = min( $until, $row['start'] ); }
			}
			foreach ( $events as $next ) { if ( $next['start'] > $cutoff ) { $until = min( $until, $next['start'] ); } }
			if ( $now >= $until ) { continue; }
			$saved = $state['standby'] ?? array();
			$row = null;
			if ( ( $saved['key'] ?? '' ) === $event['key'] && ! empty( $saved['relay_slots'] ) ) {
				$row = $saved['relay_slots'][0];
				$media = $pool[ $row['key'] ] ?? array();
				$category = BSC_TV_Music::category( $row['start'], $settings['music'] );
				if ( $row['start'] !== $cutoff || $row['end'] <= $now || $row['end'] > $until || empty( $media['music'] ) || ! $category || ! in_array( $category, $media['music']['categories'] ?? array(), true ) ) { return null; }
			} else {
				$media = BSC_TV_Music::waiting( $pool, $state, $settings, $cutoff, $until );
				if ( ! $media || $cutoff + $media['duration'] <= $now ) { return null; }
				$row = array_intersect_key( $media, array_flip( array( 'key', 'resource', 'channel_id', 'show_id', 'music' ) ) );
				$row += array( 'start' => $cutoff, 'end' => $cutoff + $media['duration'], 'waiting_music' => true );
			}
			$event['relay_start'] = $cutoff; $event['relay_until'] = $row['end']; $event['relay_slots'] = array( $row );
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
