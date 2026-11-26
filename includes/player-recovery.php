<?php
defined( 'ABSPATH' ) || exit;

/** Repair gaps without moving any surviving programme's original wall-clock times. */
final class BSC_TV_Player_Recovery {
	public static function repair( $state, $pool, $now ) {
		$rows = array(); $previous_music = ! empty( $state['vod']['music'] ) && ( $state['vod']['end'] ?? 0 ) > $now;
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( ! isset( $pool[ $row['key'] ] ) || empty( $row['id'] ) || $row['end'] <= $row['start'] ) { continue; }
			$music = ! empty( $row['music'] ) || ! empty( $pool[ $row['key'] ]['music'] );
			if ( $row['end'] > $now && $music ) {
				$clip = $row['music'] ?? $pool[ $row['key'] ]['music'];
				$duration = ( $clip['duration'] ?? 0 ) + ( $clip['entry'] ?? 0 ) + ( $clip['exit'] ?? 0 );
				if ( $previous_music || $duration < 1 || $row['end'] - $row['start'] != $duration ) { continue; }
			}
			$rows[] = $row;
			if ( $row['end'] > $now ) { $previous_music = $music; }
		}
		if ( $rows !== ( $state['plan'] ?? array() ) ) {
			$state['plan'] = $rows; $state['plan_source'] = '';
			$state['recovery_notice'] = array( 'at' => $now, 'reason' => 'queue_repaired' );
		}
		return $state;
	}

	/** An approved beer programme fills a hole; musical clips are never candidates. */
	public static function ensure( $state, $pool, $settings, $now, $prefer_programme = false ) {
		$vod = $state['vod'] ?? null;
		if ( $vod && isset( $pool[ $vod['key'] ] ) && $vod['start'] <= $now && $vod['end'] > $now && ( ! $prefer_programme || empty( $vod['music'] ) ) ) { return $state; }
		$programmes = array_filter( $pool, static function ( $media ) use ( $now ) { return empty( $media['music'] ) && $media['duration'] > 0 && $media['published_at'] <= $now; } );
		if ( ! $programmes ) {
			if ( 'no_programme' !== ( $state['recovery_notice']['reason'] ?? '' ) ) { $state['recovery_notice'] = array( 'at' => $now, 'reason' => 'no_programme' ); }
			return $state;
		}
		// Keep a future current slot as an anchor when migrating an older clock.
		if ( $vod && $vod['start'] > $now && empty( $vod['music'] ) && isset( $programmes[ $vod['key'] ] ) ) {
			$exists = array_filter( $state['plan'] ?? array(), static function ( $row ) use ( $vod ) { return $row['key'] === $vod['key'] && $row['start'] === $vod['start']; } );
			if ( ! $exists ) { $vod['id'] = $vod['id'] ?? substr( hash( 'sha256', $vod['key'] . ':' . $vod['start'] ), 0, 24 ); $state['plan'][] = $vod; }
		}
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( empty( $row['music'] ) && isset( $programmes[ $row['key'] ] ) && $row['start'] <= $now && $row['end'] > $now ) {
				$state['vod'] = null;
				$state['plan'] = array_values( array_filter( $state['plan'], static function ( $candidate ) use ( $now ) { return $candidate['end'] > $now && ( empty( $candidate['music'] ) || $candidate['start'] > $now ); } ) );
				usort( $state['plan'], static function ( $a, $b ) { return $a['start'] <=> $b['start']; } );
				return BSC_TV_Player_Live::seek( $state, $pool, $settings, $now, true );
			}
		}
		$until = PHP_INT_MAX;
		foreach ( $state['plan'] ?? array() as $row ) {
			if ( empty( $row['music'] ) && isset( $programmes[ $row['key'] ] ) && $row['start'] > $now ) { $until = min( $until, $row['start'] ); }
		}
		$waiting = BSC_TV_Player_Live::upcoming( $state, $settings, $now );
		if ( $waiting && $waiting['start'] > $now ) { $until = min( $until, $waiting['start'] ); }
		if ( $until - $now < 30 ) { return $state; }
		$selection = $settings; $selection['repeat_hours'] = 0; $selection['music']['enabled'] = false;
		$media = BSC_TV_Player::select( $programmes, $state, $selection, $now );
		if ( ! $media ) { return $state; }
		$media['duration'] = min( $media['duration'], $until - $now );
		$media['available'] = $now;
		// Orphaned music may be replaced, but no future beer programme is shifted.
		$state['plan'] = array_values( array_filter( $state['plan'] ?? array(), static function ( $row ) use ( $now, $until ) { return $row['end'] > $now && ( empty( $row['music'] ) || $row['start'] >= $until ); } ) );
		usort( $state['plan'], static function ( $a, $b ) { return $a['start'] <=> $b['start']; } );
		foreach ( $state['aired'] ?? array() as $i => $row ) { if ( $row['start'] <= $now && ( ! $row['end'] || $row['end'] > $now ) ) { $state['aired'][ $i ]['end'] = $now; } }
		$state['vod'] = BSC_TV_Player::begin( $media, $state );
		$state['vod']['recovery'] = true;
		$tail = $state['plan'] ? end( $state['plan'] ) : array();
		$state['live_grid_until'] = max( $state['live_grid_until'] ?? 0, $tail['end'] ?? $now + 72 * HOUR_IN_SECONDS );
		$state['recovery_notice'] = array( 'at' => $now, 'reason' => 'programme_started', 'title' => $media['title'] ?? '' );
		$state['plan_source'] = '';
		return $state;
	}

	/** A stable, station-local backup for clients whose official live player fails. */
	public static function backup( $state, $pool, $settings, $now ) {
		$active = $state['live']['resource'] ?? null;
		if ( $active ) { $pool = array_filter( $pool, static function ( $media ) use ( $active ) { return BSC_TV_Player::resource_key( $media['resource'] ) !== BSC_TV_Player::resource_key( $active ); } ); }
		$forecast = $state; $forecast['live'] = null; $forecast['standby'] = null;
		$forecast = BSC_TV_Player_Live::seek( $forecast, $pool, $settings, $now );
		$slot = $forecast['vod'] ?? null;
		if ( ! $slot || ! empty( $slot['music'] ) || $slot['start'] > $now || $slot['end'] <= $now ) {
			$backup = $state['live_backup'] ?? null;
			if ( $backup && isset( $pool[ $backup['key'] ] ) && empty( $pool[ $backup['key'] ]['music'] ) && $backup['start'] <= $now && $backup['end'] > $now ) { return $state; }
			if ( $backup ) { $forecast['last_show'] = $backup['key']; $forecast['history'][ $backup['key'] ] = array( 'end' => $backup['end'] ); }
			$forecast = self::ensure( $forecast, $pool, $settings, $now, true );
			$slot = $forecast['vod'] ?? null;
		}
		$state['live_backup'] = $slot && empty( $slot['music'] ) && $slot['start'] <= $now && $slot['end'] > $now ? $slot : null;
		return $state;
	}

	/** Project real programmes into repaired holes, preserving every surviving anchor. */
	public static function fill_gaps( $rows, $state, $pool, $settings, $now ) {
		if ( empty( $state['recovery_notice'] ) ) { return $rows; }
		$forecast = $state; $forecast['live'] = null; $forecast['standby'] = null; $forecast['plan'] = array();
		$cursor = max( $now, $state['vod']['end'] ?? $now ); $result = array();
		foreach ( $rows as $index => $row ) {
			if ( $row['end'] <= $now ) { $result[] = $row; continue; }
			if ( $row['start'] > $cursor ) {
				if ( ! empty( $row['music'] ) ) { continue; }
				$forecast['vod'] = null; $forecast['plan'] = array( $row );
				while ( $cursor + 30 <= $row['start'] && count( $result ) + count( $rows ) - $index < BSC_TV_Player_Schedule::MAX_SLOTS ) {
					$forecast = self::ensure( $forecast, $pool, $settings, $cursor );
					$slot = $forecast['vod'] ?? null;
					if ( ! $slot || $slot['start'] !== $cursor || $slot['end'] <= $cursor || $slot['end'] > $row['start'] || ! empty( $slot['music'] ) ) { break; }
					$slot['id'] = substr( hash( 'sha256', 'recovery:' . $slot['key'] . ':' . $cursor ), 0, 24 );
					$result[] = $slot; $cursor = $slot['end'];
				}
				$forecast['plan'] = array();
			}
			$result[] = $row; $cursor = max( $cursor, $row['end'] );
			$forecast['vod'] = $row; $forecast['history'][ $row['key'] ] = array( 'end' => $row['end'] );
			$forecast['aired'][] = $row;
			BSC_TV_Music::begun( $forecast, $row );
		}
		return $result;
	}
}
