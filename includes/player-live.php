<?php
defined( 'ABSPATH' ) || exit;

/** Live is an overlay on the saved wall clock, never a pause of the replay queue. */
final class BSC_TV_Player_Live {

	public static function announcements( $catalogue, $settings ) {
		if ( empty( $settings['live_priority'] ) || empty( BSC_TV_Sync::settings()['live_enabled'] ) ) { return array(); }
		$entries = $catalogue['manual_upcoming'] ?? array();
		foreach ( $catalogue['channels'] as $channel ) {
			foreach ( $channel['state']['schedule'] ?? array() as $event ) { $entries[] = array( 'channel_id' => $channel['id'], 'event' => $event ); }
		}
		$result = array();
		foreach ( $entries as $entry ) {
			$channel = $catalogue['channels'][ $entry['channel_id'] ] ?? null;
			$event = $entry['event'];
			if ( ! $channel || ( isset( $channel['config']['live'] ) && ! $channel['config']['live'] ) || 'upcoming' !== ( $event['status'] ?? '' ) || empty( $event['start'] ) || ! empty( $event['age_restricted'] ) || false === ( $event['embeddable'] ?? null ) ) { continue; }
			$resource = BSC_TV_Player::resource( $event['url'] ?? '', true );
			if ( ! $resource || isset( $catalogue['blocked'][ BSC_TV_Player::resource_key( $resource ) ] ) ) { continue; }
			$key = 'waiting:' . BSC_TV_Player::resource_key( $resource ) . ':' . $event['start'];
			$result[ $key ] = array( 'key' => $key, 'resource' => $resource, 'channel_id' => $channel['id'], 'show_id' => $entry['show_id'] ?? 0, 'title' => $event['title'] ?? 'Direct BSC TV', 'image' => $event['image'] ?? '', 'expected_start' => (int) $event['start'], 'start' => (int) $event['start'] - (int) ( ( $settings['live_lead'] ?? 2 ) * MINUTE_IN_SECONDS ), 'end' => 0, 'priority' => (int) ( $channel['config']['priority'] ?? 50 ), 'standby' => true );
		}
		return $result;
	}

	public static function upcoming( $state, $settings, $now, $due = false ) {
		$rows = array_filter( $settings['_announcements'] ?? array(), static function ( $row ) use ( $state, $settings, $now, $due ) {
			$finished = $state['live_finished'][ BSC_TV_Player::resource_key( $row['resource'] ) ] ?? 0;
			$timeout = BSC_TV_Player_Live_Music::WAIT_MINUTES * MINUTE_IN_SECONDS;
			return $row['expected_start'] > $finished && $now < $row['expected_start'] + $timeout && ( ! $due || $row['start'] <= $now );
		} );
		usort( $rows, static function ( $a, $b ) { return $a['start'] <=> $b['start'] ?: $b['priority'] <=> $a['priority'] ?: strcmp( $a['key'], $b['key'] ); } );
		return $rows ? $rows[0] : null;
	}

	private static function close( &$state, $now ) {
		foreach ( $state['aired'] ?? array() as $index => $row ) {
			if ( $row['start'] <= $now && ( ! $row['end'] || $row['end'] > $now ) ) { $state['aired'][ $index ]['end'] = $now; }
		}
	}

	/** Join a saved slot at its original offset. Skipped slots are never logged as broadcast. */
	public static function seek( $state, $pool, $settings, $now, $resume = false ) {
		$state['plan'] = array_values( array_filter( $state['plan'] ?? array(), static function ( $row ) use ( $pool, $now ) { return isset( $pool[ $row['key'] ] ) && $row['end'] > $now; } ) );
		$vod = $state['vod'] ?? null;
		if ( $vod && isset( $pool[ $vod['key'] ] ) && $vod['end'] > $now ) {
			if ( $resume && $vod['start'] <= $now ) { BSC_TV_Player::log_slot( $state, $vod, $now ); }
			if ( class_exists( 'BSC_TV_Music' ) && $vod['start'] <= $now ) { BSC_TV_Music::tally( $state, $vod, $now ); }
			return $state;
		}
		$state['vod'] = null;
		foreach ( $state['plan'] as $row ) {
			if ( $row['start'] > $now ) { return $state; }
			$media = $pool[ $row['key'] ];
			$media['available'] = $row['start'];
			$media['duration'] = $row['end'] - $row['start'];
			$media['plan_id'] = $row['id'];
			$media['plan_end'] = $row['end'];
			$media['fixed'] = $row['fixed'] ?? true;
			if ( ! empty( $row['music'] ) ) { $media['music'] = $row['music']; }
			$state['vod'] = BSC_TV_Player::begin( $media, $state );
			if ( $resume ) { $state['aired'][ count( $state['aired'] ) - 1 ]['start'] = $now; }
			if ( class_exists( 'BSC_TV_Music' ) ) { BSC_TV_Music::tally( $state, $state['vod'], $now ); }
			return $state;
		}
		// After an exceptionally long live beyond the prepared horizon, start a new rotation now.
		$settings['live_clock'] = false;
		return BSC_TV_Player::advance( $state, $pool, array(), $settings, $now );
	}

	public static function advance( $state, $pool, $lives, $settings, $now ) {
		$state += array( 'vod' => null, 'live' => null, 'standby' => null, 'paused_at' => 0, 'history' => array(), 'manual' => array(), 'plan' => array(), 'premiered' => array() );
		$state['live_finished'] = array_slice( array_filter( $state['live_finished'] ?? array(), static function ( $at ) use ( $now ) { return $at >= $now - 8 * DAY_IN_SECONDS; } ), -500, null, true );
		$was_overlay = $state['live'] || $state['standby'];
		if ( $state['live'] && isset( $lives[ $state['live']['key'] ] ) ) {
			// Fresh confirmations update metadata, but never restart or cap an ongoing broadcast.
			$state['live'] = array_replace( $state['live'], $lives[ $state['live']['key'] ] );
			return $state;
		}
		if ( $state['live'] ) {
			$state['live_finished'][ BSC_TV_Player::resource_key( $state['live']['resource'] ) ] = $now;
			self::close( $state, $now );
			$state['live'] = null;
		}
		$waiting = self::upcoming( $state, $settings, $now, true );
		if ( ! $waiting ) { $waiting = BSC_TV_Player_Live_Music::fallback( $state, $pool, $settings, $now ); }
		$live = null;
		if ( $lives ) {
			usort( $lives, static function ( $a, $b ) { return $b['priority'] <=> $a['priority'] ?: $a['start'] <=> $b['start'] ?: strcmp( $a['key'], $b['key'] ); } );
			$live = $lives[0];
		}
		if ( $state['standby'] && ! $live && $waiting && $state['standby']['key'] === $waiting['key'] ) {
			$state['standby'] = array_replace( $state['standby'], $waiting );
			return ! empty( $waiting['relay_until'] ) ? BSC_TV_Player_Live_Music::sync( $state, $now ) : $state;
		}
		if ( $state['standby'] ) { self::close( $state, $now ); $state['standby'] = null; }
		$overlay = $live ?: $waiting;
		if ( $overlay ) {
			$at = $live || $was_overlay ? $now : $waiting['start'];
			if ( ! $was_overlay ) {
				$base = $settings; $base['live_clock'] = false;
				$state = BSC_TV_Player::advance( $state, $pool, array(), $base, $at );
				if ( ! $state['plan'] ) { $state['plan'] = BSC_TV_Player_Schedule::build( $state, $pool, $base, $at ); }
			}
			self::close( $state, $at );
			$tail = $state['plan'] ? end( $state['plan'] ) : array();
			$state['live_grid_until'] = max( $state['live_grid_until'] ?? 0, $tail['end'] ?? $now + 72 * HOUR_IN_SECONDS );
			$state['paused_at'] = 0;
			$overlay['start'] = $live ? $overlay['start'] : $at;
			$state[ $live ? 'live' : 'standby' ] = $overlay;
			if ( empty( $overlay['relay_until'] ) ) { BSC_TV_Player::log_slot( $state, $overlay, $at, 0, (bool) $live ); }
			return ! empty( $overlay['relay_until'] ) ? BSC_TV_Player_Live_Music::sync( $state, $now ) : $state;
		}
		if ( $was_overlay || ( $state['live_grid_until'] ?? 0 ) > $now ) {
			$state['paused_at'] = 0;
			return self::seek( $state, $pool, $settings, $now, (bool) $was_overlay );
		}
		$settings['live_clock'] = false;
		return BSC_TV_Player::advance( $state, $pool, array(), $settings, $now );
	}

	/** Finite, shared animation phases let every viewer loop the same BSC identities. */
	public static function decorate( $item, $settings, $now ) {
		$clip = BSC_TV_Player_Live_Music::current( $item, $now );
		if ( $clip ) { return BSC_TV_Music::decorate( $clip, array(), $now ) + array( 'waiting_music' => true, 'expected_start' => $item['expected_start'] ); }
		if ( ! empty( $item['relay_until'] ) ) {
			list( $item['start'], $item['relay_until'] ) = BSC_TV_Player_Live_Music::gap( $item, $now );
		}
		$music = $settings['music'] ?? array();
		$animations = BSC_TV_Music::animations( $music + array( 'bundled_animation' => true, 'animation_ids' => array() ) );
		foreach ( array( 'entry_video', 'exit_video' ) as $phase ) {
			$asset = ! empty( $music[ $phase ] ) ? BSC_TV_Music::animation_asset( $music[ $phase ] ) : null;
			if ( $asset && ! in_array( $asset['url'], array_column( $animations, 'url' ), true ) ) { $animations[] = $asset; }
		}
		if ( ! $animations ) { $animations = BSC_TV_Music::animations( array( 'bundled_animation' => true, 'animation_ids' => array() ) ); }
		$cycle = max( 1, array_sum( array_column( $animations, 'duration' ) ) );
		$elapsed = max( 0, $now - $item['start'] );
		$start = $item['start'] + intdiv( $elapsed, $cycle ) * $cycle;
		foreach ( $animations as $animation ) {
			if ( $now < $start + $animation['duration'] ) { break; }
			$start += $animation['duration'];
		}
		$identity = array_intersect_key( $music, array_fill_keys( array( 'background', 'color', 'animation', 'show_text', 'sound' ), true ) );
		$identity['entry_video'] = $animation['url'];
		$identity['entry_text'] = ! empty( $item['relay_until'] ) ? 'Pause musicale · reprise au programme suivant' : 'Le direct va commencer';
		$identity['standby_key'] = $item['key'];
		$identity['standby_start'] = $item['start'];
		$identity['logo'] = ! empty( $music['logo'] ) ? wp_get_attachment_image_url( $music['logo'], 'large' ) : '';
		$deadline = $item['relay_until'] ?? $item['expected_start'] + BSC_TV_Player_Live_Music::WAIT_MINUTES * MINUTE_IN_SECONDS;
		return array( 'key' => $item['key'] . ':' . $start, 'title' => ! empty( $item['relay_until'] ) ? 'Pause musicale BSC' : $item['title'], 'creator' => '', 'avatar' => '', 'profile_url' => '', 'image' => $item['image'] ?? '', 'url' => '', 'resource' => array( 'platform' => 'identity', 'id' => 'waiting' ), 'standby' => true, 'waiting_music' => ! empty( $item['relay_until'] ), 'live' => false, 'music' => false, 'music_phase' => 'entry', 'identity' => $identity, 'start' => $start, 'end' => min( $start + $animation['duration'], $deadline ), 'expected_start' => $item['expected_start'], 'offset' => $now - $start, 'new' => false, 'premiere' => false );
	}
}
