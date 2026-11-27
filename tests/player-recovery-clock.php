<?php
/** Deterministic regression cases for missed lives, musical loops and saved anchors. */
require __DIR__ . '/player-live-clock.php';
$checks = 0;
$beer_pool = array_column( array( live_media( 10, $now ), live_media( 11, $now ), live_media( 12, $now ) ), null, 'key' );
$options = array( 'enabled' => true, 'recent' => 'rotation', 'repeat_hours' => 0, 'live_clock' => true, 'live_lead' => 2, 'timezone' => 'UTC', 'music' => array( 'enabled' => false ) );
$base = BSC_TV_Player::advance( array( 'rotation_seed' => 'recovery-fixture' ), $beer_pool, array(), $options, $now );
$base = BSC_TV_Player_Schedule::replenish( $base, $beer_pool, $options, $now, true );
$anchor = $base['plan'][0];
$bands = array();
foreach ( array( 'morning' => array( '06:00', '10:00' ), 'day' => array( '10:00', '18:00' ), 'evening' => array( '18:00', '00:00' ), 'night' => array( '00:00', '06:00' ) ) as $id => $hours ) { $bands[ $id ] = array( 'enabled' => true, 'category' => $id, 'name' => $id, 'start' => $hours[0], 'end' => $hours[1] ); }
$options['music'] = array( 'enabled' => true, 'repeat_hours' => 0, 'threshold' => 40, 'flexible' => true, 'bands' => $bands );
$music_pool = array();
foreach ( array( 120, 180, 240 ) as $id => $seconds ) {
	$clip = live_media( 100 + $id, $now ); $clip['key'] = 'music:' . $id; $clip['duration'] = $seconds + 22;
	$clip['music'] = array( 'clip_id' => 100 + $id, 'artist' => 'Fixture ' . $id, 'title' => 'Clip ' . $id, 'youtube_id' => str_repeat( chr( 97 + $id ), 11 ), 'duration' => $seconds, 'entry' => 11, 'exit' => 11, 'identity' => array(), 'categories' => array_keys( $bands ) );
	$music_pool[ $clip['key'] ] = $clip;
}
$all = $beer_pool + $music_pool;
$announcement['key'] = 'waiting:fixture'; $options['_announcements'] = array( $announcement );
$cutoff = $announcement['expected_start'] + 1200;
$waiting = BSC_TV_Player::advance( $base, $all, array(), $options, $cutoff - 1 );
$relay = BSC_TV_Player::advance( $waiting, $all, array(), $options, $cutoff );
live_check( 1 === count( $relay['standby']['relay_slots'] ) && $relay['standby']['relay_until'] <= $cutoff + 300, 'An absent live receives at most one complete clip within five minutes' );
$row = $relay['standby']['relay_slots'][0];
live_check( $row['end'] - $row['start'] === $row['music']['duration'] + 22 && $relay['plan'] === $base['plan'], 'The full clip and identities fit without moving any saved programme' );
$after = BSC_TV_Player::advance( $relay, $all, array(), $options, $row['end'] );
live_check( empty( $after['standby'] ) && empty( $after['vod']['music'] ), 'The antenna returns to beer immediately when the single relay clip ends' );
$again = BSC_TV_Player::advance( $after, $all, array(), $options, $row['end'] + 1 );
live_check( empty( $again['standby'] ) && ! empty( $again['live_wait_done'][ $announcement['key'] ] ), 'A stale announcement cannot select a second relay clip on the next read' );
live_check( $again === BSC_TV_Player::advance( $again, $all, array(), $options, $row['end'] + 1 ), 'Repeated reads do not keep rewriting the missed-live marker' );
$late_live = $live; $late_live['confirmed_at'] = $row['end'] + 1;
$confirmed = BSC_TV_Player::advance( $again, $all, array( $late_live['key'] => $late_live ), $options, $row['end'] + 1 );
live_check( ! empty( $confirmed['live'] ) && empty( $confirmed['standby'] ), 'A genuinely confirmed late live still preempts the rescue programme' );

// Reproduce France: no current media, a future orphan clip, and a later beer anchor.
$gap = $base; $gap['vod'] = null; $gap['plan'] = array(); $gap['live_grid_until'] = $now + DAY_IN_SECONDS;
$music = reset( $music_pool );
$orphan = array( 'id' => 'orphan', 'key' => $music['key'], 'resource' => $music['resource'], 'channel_id' => 0, 'show_id' => 0, 'start' => $now + 2200, 'end' => $now + 2200 + $music['duration'], 'music' => $music['music'], 'fixed' => false );
$future = $anchor; $future['id'] = 'original-future'; $future['start'] = $now + 10400; $future['end'] = $future['start'] + 3600; $future['fixed'] = true;
$gap['plan'] = array( $orphan, $future ); $normal = $options; $normal['_announcements'] = array();
$filled = BSC_TV_Player::advance( $gap, $all, array(), $normal, $now );
live_check( ! empty( $filled['vod']['recovery'] ) && empty( $filled['vod']['music'] ) && $filled['vod']['start'] === $now, 'An empty current slot starts a beer programme instead of waiting for a distant musical slot' );
live_check( $filled['plan'] === array( $future ) && $filled['vod']['end'] <= $future['start'], 'The orphan clip is removed while the original beer anchor remains byte-for-byte unchanged' );
$prepared = BSC_TV_Player_Schedule::replenish( $filled, $all, $normal, $now, true );
$cursor = $filled['vod']['end']; $creators = array( $filled['vod']['key'] );
foreach ( $prepared['plan'] as $slot ) {
	if ( $slot['start'] >= $future['start'] ) { break; }
	live_check( empty( $slot['music'] ) && $slot['start'] === $cursor, 'Repaired future gaps contain real programmes with contiguous timings' );
	$cursor = $slot['end']; $creators[] = $slot['key'];
}
live_check( $cursor === $future['start'] && count( array_unique( $creators ) ) >= 2, 'The repaired gap is filled up to the original anchor with varied programmes' );
$saved_future = array_values( array_filter( $prepared['plan'], static function ( $slot ) { return 'original-future' === $slot['id']; } ) );
live_check( array( $future ) === $saved_future, 'Preparing the repaired grid never shifts the original fixed programme' );
$resume = BSC_TV_Player::advance( $prepared, $all, array(), $normal, $future['start'] + 600 );
live_check( $resume['vod']['start'] === $future['start'] && $resume['vod']['key'] === $future['key'], 'The original programme is rejoined at its original offset after gap fillers' );

// A premiere published later is still an original wall-clock boundary.
$premiere = live_media( 13, $now ); $premiere['published_at'] = $now + 600;
$premiere_pool = $all + array( $premiere['key'] => $premiere );
$premiere_row = $future; $premiere_row['key'] = $premiere['key']; $premiere_row['resource'] = $premiere['resource']; $premiere_row['start'] = $now + 600; $premiere_row['end'] = $premiere_row['start'] + $premiere['duration'];
$before_premiere = $gap; $before_premiere['plan'] = array( $premiere_row );
$bounded = BSC_TV_Player_Recovery::ensure( $before_premiere, $premiere_pool, $normal, $now );
live_check( $bounded['vod']['key'] !== $premiere['key'] && $bounded['vod']['end'] === $premiere_row['start'] && $bounded['plan'] === array( $premiere_row ), 'A published replacement stops at the future-publication anchor without moving or prematurely selecting it' );
$before_premiere['vod'] = $premiere_row; $before_premiere['plan'] = array();
$bounded = BSC_TV_Player_Recovery::ensure( $before_premiere, $premiere_pool, $normal, $now );
live_check( $bounded['vod']['end'] === $premiere_row['start'] && $bounded['plan'] === array( $premiere_row ), 'A future current premiere is preserved as an anchor before selecting a gap replacement' );

// Reproduce World: an old relay whose programmed deadline was many hours away.
$old = $announcement; $old['expected_start'] = $now - 7200; $old['start'] = $old['expected_start'] - 120; $old['key'] = 'waiting:old';
$legacy = $gap; $legacy['standby'] = $old + array( 'relay_start' => $now - 6000, 'relay_until' => $now + DAY_IN_SECONDS, 'relay_slots' => array( $orphan ) );
$old_options = $normal; $old_options['_announcements'] = array( $old );
$healed = BSC_TV_Player::advance( $legacy, $all, array(), $old_options, $now );
live_check( empty( $healed['standby'] ) && empty( $healed['vod']['music'] ), 'An existing hours-long relay is released immediately after upgrading' );
$broken = $gap; $bad = $orphan; $bad['id'] = 'music-again'; $bad['start'] = $orphan['end']; $bad['end'] = $bad['start'] + $music['duration'];
$broken['plan'] = array( $orphan, $bad, $future );
live_check( 2 === count( BSC_TV_Player_Recovery::repair( $broken, $all, $now )['plan'] ), 'A legacy consecutive-music chain is repaired' );
$bad['end'] += 6000; $broken['plan'] = array( $bad, $future );
live_check( array( $future ) === BSC_TV_Player_Recovery::repair( $broken, $all, $now )['plan'], 'A corrupt musical reservation cannot occupy hours beyond its clip duration' );
$empty = BSC_TV_Player_Recovery::ensure( array( 'vod' => null, 'plan' => array() ), $music_pool, $normal, $now );
live_check( empty( $empty['vod'] ) && 'no_programme' === $empty['recovery_notice']['reason'], 'An empty approved programme catalogue is diagnosed instead of inventing a replacement' );

// A client backup is stable and never writes fictional broadcasts into the shared clock.
$backup = BSC_TV_Player::advance( $gap, $all, array( $late_live['key'] => $late_live ), $normal, $now );
$second = BSC_TV_Player::advance( $backup, $all, array( $late_live['key'] => $late_live ), $normal, $now + 10 );
live_check( $backup['live_backup'] === $second['live_backup'] && empty( $backup['live_backup']['music'] ), 'Every affected visitor receives the same stable beer backup' );
live_check( $gap['plan'] === $backup['plan'] && $gap['vod'] === $backup['vod'] && count( $backup['aired'] ) === count( $gap['aired'] ) + 1, 'Preparing a client backup preserves the original grid and only logs the real live' );
echo "OK $checks programme recovery clock checks.\n";
