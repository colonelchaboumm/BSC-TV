<?php
/** Run with: php tests/player-clock.php. No WordPress or network needed. */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
require dirname( __DIR__ ) . '/includes/player.php';
require dirname( __DIR__ ) . '/includes/player-schedule.php';

$checks = 0;
function tv_check( $condition, $label ) {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
}
function tv_media( $id, $creator, $published, $duration = 3600 ) {
	return array( 'show_id' => $id, 'key' => 'youtube:video' . $id, 'resource' => array( 'platform' => 'youtube', 'id' => 'video' . $id ), 'channel_id' => $id, 'duration' => $duration, 'published_at' => $published, 'premiere' => true, 'creator_key' => $creator );
}
$now = 1805806800;
$settings = array( 'recent' => 'strong', 'repeat_hours' => 6 );
$a = tv_media( 1, 'a', $now - 1800 );
$b = tv_media( 2, 'b', $now - 2 * DAY_IN_SECONDS );
$c = tv_media( 3, 'a', $now - 40 * DAY_IN_SECONDS );
$pool = array_column( array( $a, $b, $c ), null, 'key' );
$state = BSC_TV_Player::advance( array(), $pool, array(), $settings, $now );
tv_check( 1 === $state['vod']['show_id'], 'Newest bucket starts first' );
tv_check( $now === $state['vod']['start'] && $now + 3600 === $state['vod']['end'], 'Common clock has exact bounds' );
tv_check( $state['vod']['premiere'], 'First premiere flag' );
$visitor = BSC_TV_Player::advance( $state, $pool, array(), $settings, $now + 900 );
tv_check( $visitor === $state && 900 === $now + 900 - $visitor['vod']['start'], 'Second visitor joins at 15 minutes without resetting the clock' );
$newest = tv_media( 4, 'c', $now + 600 );
$larger = $pool + array( $newest['key'] => $newest );
tv_check( BSC_TV_Player::advance( $state, $larger, array(), $settings, $now + 1000 )['vod'] === $state['vod'], 'New discovery does not interrupt current programme' );
$next = BSC_TV_Player::advance( $state, $pool, array(), $settings, $now + 3600 );
tv_check( 2 === $next['vod']['show_id'] && $now + 3600 === $next['vod']['start'], 'Exact boundary advances and alternates creators' );
$next2 = BSC_TV_Player::advance( $next, $pool, array(), $settings, $now + 7200 );
tv_check( 3 === $next2['vod']['show_id'], 'Older archives fill while recent videos cool down' );
$live1 = array( 'key' => 'live:twitch:a', 'resource' => array( 'platform' => 'twitch', 'id' => 'channel_a' ), 'channel_id' => 1, 'show_id' => 0, 'title' => 'Live A', 'priority' => 10, 'start' => $now - 100, 'end' => 0 );
$live2 = $live1;
$live2['key'] = 'live:twitch:b';
$live2['priority'] = 90;
$live2['channel_id'] = 2;
$interrupted = BSC_TV_Player::advance( $state, $pool, array( $live1['key'] => $live1, $live2['key'] => $live2 ), $settings, $now + 1200 );
tv_check( $live2['key'] === $interrupted['live']['key'], 'Configured priority selects among simultaneous lives' );
$live3 = $live1;
$live3['key'] = 'live:twitch:c';
$live3['priority'] = 100;
$stable = BSC_TV_Player::advance( $interrupted, $pool, array( $live2['key'] => $live2, $live3['key'] => $live3 ), $settings, $now + 1500 );
tv_check( $live2['key'] === $stable['live']['key'], 'No repeated switching when a higher-priority live appears' );
$resumed = BSC_TV_Player::advance( $interrupted, $pool, array(), $settings, $now + 2400 );
tv_check( null === $resumed['live'] && $resumed['vod']['show_id'] === 1, 'Replay resumes after live' );
tv_check( 1200 === $now + 2400 - $resumed['vod']['start'] && $now + 4800 === $resumed['vod']['end'], 'Replay position is preserved through the interruption' );
$manual = $state;
$manual['manual'] = array( 3, 2 );
tv_check( 3 === BSC_TV_Player::advance( $manual, $pool, array(), $settings, $now + 3600 )['vod']['show_id'], 'Manual queue affects next programme only' );
$removed = BSC_TV_Player::advance( $state, array( $b['key'] => $b ), array(), $settings, $now + 100 );
tv_check( 2 === $removed['vod']['show_id'], 'Excluded or unpublished current source stops participating' );
$single = array( $a['key'] => $a );
$waiting = BSC_TV_Player::advance( $state, $single, array(), $settings, $now + 3600 );
tv_check( null === $waiting['vod'], 'Insufficient catalogue does not bypass repeat cooldown' );
$available = BSC_TV_Player::select( $single, $waiting, $settings, $now + 3600 );
tv_check( $now + 7 * 3600 === $available['available'], 'Waiting audience sees real next availability' );
$zero_cooldown = $settings;
$zero_cooldown['repeat_hours'] = 0;
$repeated = BSC_TV_Player::advance( $state, $single, array(), $zero_cooldown, $now + 3600 );
tv_check( ! $repeated['vod']['premiere'], 'Premiere is not repeated on replay' );
$future = tv_media( 9, 'z', $now + 86400 );
tv_check( null === BSC_TV_Player::advance( array(), array( $future['key'] => $future ), array(), $settings, $now )['vod'], 'A future publication is never played early' );
$rotation = $settings;
$rotation['recent'] = 'rotation';
$rotation_state = array( 'history' => array( $a['key'] => array( 'end' => $now - DAY_IN_SECONDS ) ) );
tv_check( 2 === BSC_TV_Player::select( $pool, $rotation_state, $rotation, $now )['show_id'], 'Rotation favors an unseen source over a recent repeat' );
foreach ( array( 2, 5, 10, 20, 40 ) as $days ) {
	$age_media = tv_media( 10 + $days, 'other', $now - $days * DAY_IN_SECONDS );
	$age_pool = array( $age_media['key'] => $age_media, $a['key'] => $a );
	tv_check( 1 === BSC_TV_Player::select( $age_pool, array(), $settings, $now )['show_id'], '24h priority ahead of age bucket ' . $days );
}
$long_gap = BSC_TV_Player::advance( $state, $single, array(), $zero_cooldown, $now + 600 * DAY_IN_SECONDS );
tv_check( $long_gap['vod']['start'] <= $now + 600 * DAY_IN_SECONDS && $long_gap['vod']['end'] > $now + 600 * DAY_IN_SECONDS, 'Long inactivity recovery is bounded and produces a current common slot' );
$planned = $state;
$planned['plan'] = BSC_TV_Player_Schedule::build( $planned, $pool, $zero_cooldown, $now );
tv_check( count( $planned['plan'] ) > 5 && end( $planned['plan'] )['end'] >= $now + 72 * HOUR_IN_SECONDS, 'Admin projection covers 72 hours, not only five programmes' );
tv_check( $planned['plan'] === BSC_TV_Player_Schedule::build( $planned, $pool, $zero_cooldown, $now ), 'Refreshing a saved grid preserves its slots and IDs' );
$original_plan = $planned['plan'];
$move = BSC_TV_Player_Schedule::change( $planned, $pool, $zero_cooldown, $now, 'move', $original_plan[0]['id'], $original_plan[0]['start'] + 1800, BSC_TV_Player_Schedule::fingerprint( $planned ) );
tv_check( $move['vod'] === $state['vod'] && $move['plan'][0]['start'] === $original_plan[0]['start'] + 1800 && $move['plan'][1]['start'] === $original_plan[1]['start'] + 1800, 'Delay shifts subsequent slots and never touches the on-air programme' );
$gap = BSC_TV_Player::advance( $move, $pool, array(), $zero_cooldown, $now + 3600 );
tv_check( null === $gap['vod'], 'An explicit delay produces the advertised programming pause' );
$moved_start = BSC_TV_Player::advance( $gap, $pool, array(), $zero_cooldown, $move['plan'][0]['start'] );
tv_check( $move['plan'][0]['show_id'] === $moved_start['vod']['show_id'] && $move['plan'][0]['start'] === $moved_start['vod']['start'], 'Saved delayed slot is actually broadcast at its new time' );
$late_start = BSC_TV_Player::advance( $gap, $pool, array(), $zero_cooldown, $move['plan'][0]['start'] + 900 );
tv_check( $late_start['vod']['start'] === $move['plan'][0]['start'], 'A late visitor after a scheduled gap joins at the planned offset instead of restarting the slot' );
$earlier = BSC_TV_Player_Schedule::change( $move, $pool, $zero_cooldown, $now, 'move', $move['plan'][0]['id'], $original_plan[0]['start'], BSC_TV_Player_Schedule::fingerprint( $move ) );
tv_check( $earlier['plan'][0]['start'] === $original_plan[0]['start'], 'A programme can move earlier into an available gap' );
$replacement_pool = $pool;
$replacement_pool[ $c['key'] ]['duration'] = 1800;
$replacement = BSC_TV_Player_Schedule::change( $planned, $replacement_pool, $zero_cooldown, $now, 'replace', $original_plan[0]['id'], 3, BSC_TV_Player_Schedule::fingerprint( $planned ) );
tv_check( 3 === $replacement['plan'][0]['show_id'] && $original_plan[0]['id'] === $replacement['plan'][0]['id'] && 1800 === $replacement['plan'][0]['end'] - $replacement['plan'][0]['start'] && $replacement['plan'][1]['start'] === $original_plan[1]['start'] - 1800, 'Replacement retains slot identity and shifts following times by its duration' );
$broadcast = BSC_TV_Player::advance( $replacement, $replacement_pool, array(), $zero_cooldown, $replacement['plan'][0]['start'] );
$broadcast_visitor = BSC_TV_Player::advance( $broadcast, $replacement_pool, array(), $zero_cooldown, $replacement['plan'][0]['start'] + 900 );
tv_check( 3 === $broadcast['vod']['show_id'] && $broadcast_visitor['vod'] === $broadcast['vod'] && 900 === $replacement['plan'][0]['start'] + 900 - $broadcast_visitor['vod']['start'], 'Replacement reaches the common player clock and visitors share its offset' );
foreach ( array(
	array( $planned, 'move', $original_plan[0]['id'], $now, BSC_TV_Player_Schedule::fingerprint( $planned ), 'Overlap with the current programme is rejected' ),
	array( $planned, 'move', $original_plan[0]['id'], $now + 73 * HOUR_IN_SECONDS, BSC_TV_Player_Schedule::fingerprint( $planned ), 'A date outside the editable horizon is rejected' ),
	array( $planned, 'replace', $original_plan[0]['id'], 999, BSC_TV_Player_Schedule::fingerprint( $planned ), 'An unavailable or draft replacement is rejected' ),
	array( $move, 'replace', $original_plan[0]['id'], 3, BSC_TV_Player_Schedule::fingerprint( $planned ), 'A stale administrator form cannot overwrite another edit' ),
	array( $planned, 'replace', 'missing-slot', 3, BSC_TV_Player_Schedule::fingerprint( $planned ), 'A consumed slot cannot be edited' ),
) as $case ) {
	$rejected = false;
	try { BSC_TV_Player_Schedule::change( $case[0], $pool, $zero_cooldown, $now, $case[1], $case[2], $case[3], $case[4] ); } catch ( InvalidArgumentException $error ) { $rejected = true; }
	tv_check( $rejected, $case[5] );
}
$cooldown_plan = $state;
$cooldown_plan['plan'] = BSC_TV_Player_Schedule::build( $state, $pool, $settings, $now );
$cooldown_replacement = BSC_TV_Player_Schedule::change( $cooldown_plan, $pool, $settings, $now, 'replace', $cooldown_plan['plan'][0]['id'], 1, BSC_TV_Player_Schedule::fingerprint( $cooldown_plan ) );
tv_check( $cooldown_replacement['plan'][0]['start'] >= $now + 7 * HOUR_IN_SECONDS, 'Replacing with a recent replay still respects the repeat cooldown' );
$plan_live = BSC_TV_Player::advance( $planned, $pool, array( $live1['key'] => $live1 ), $zero_cooldown, $now + 1200 );
$plan_resume = BSC_TV_Player::advance( $plan_live, $pool, array(), $zero_cooldown, $now + 2400 );
tv_check( $plan_resume['plan'][0]['start'] === $original_plan[0]['start'] + 1200 && $plan_resume['vod']['show_id'] === $planned['vod']['show_id'], 'A real live pauses both the current replay and all future slots' );
$plan_live['plan'] = BSC_TV_Player_Schedule::build( $plan_live, $pool, $zero_cooldown, $now + 4 * HOUR_IN_SECONDS );
tv_check( $plan_live['plan'][0]['start'] === $original_plan[0]['start'], 'Viewing the grid during a long live does not rebase the frozen replay clock' );
$long_live_resume = BSC_TV_Player::advance( $plan_live, $pool, array(), $zero_cooldown, $now + 4 * HOUR_IN_SECONDS );
tv_check( $long_live_resume['plan'][0]['start'] === $original_plan[0]['start'] + 4 * HOUR_IN_SECONDS - 1200, 'A long live shifts the saved grid exactly once at its end' );
$limited = BSC_TV_Player_Schedule::build( array(), array( $a['key'] => array_replace( $a, array( 'duration' => 1 ) ) ), $zero_cooldown, $now );
tv_check( BSC_TV_Player_Schedule::MAX_SLOTS === count( $limited ), 'Very short videos cannot create an unbounded administrator page' );
$priority = $planned;
$priority['manual'] = array( 3 );
$priority['plan'] = BSC_TV_Player_Schedule::build( $priority, $pool, $zero_cooldown, $now );
tv_check( 3 === $priority['plan'][0]['show_id'] && $priority['plan'][1]['id'] === $original_plan[0]['id'], 'Legacy programme-next inserts before the saved queue without losing edits' );
$after_priority = BSC_TV_Player::advance( $priority, $pool, array(), $zero_cooldown, $now + 3600 );
tv_check( 3 === $after_priority['vod']['show_id'] && empty( $after_priority['manual'] ), 'A queued priority is consumed once when actually broadcast' );
$automatic_settings = $zero_cooldown + array( 'enabled' => true );
$automatic = BSC_TV_Player_Schedule::replenish( $state, $pool, $automatic_settings, $now );
tv_check( count( $automatic['plan'] ) > 5 && end( $automatic['plan'] )['end'] >= $now + 72 * HOUR_IN_SECONDS && $automatic['vod'] === $state['vod'], 'Automatic filling prepares three days while retaining the current replay' );
tv_check( $automatic === BSC_TV_Player_Schedule::replenish( $automatic, $pool, $automatic_settings, $now + 60 ), 'A normal visit does not rebuild an already full queue' );
$tomorrow = BSC_TV_Player::advance( $automatic, $pool, array(), $automatic_settings, $now + 25 * HOUR_IN_SECONDS );
$tomorrow_filled = BSC_TV_Player_Schedule::replenish( $tomorrow, $pool, $automatic_settings, $now + 25 * HOUR_IN_SECONDS );
tv_check( $tomorrow_filled['vod'] === $tomorrow['vod'] && $tomorrow_filled['plan'][0]['id'] === $tomorrow['plan'][0]['id'] && end( $tomorrow_filled['plan'] )['end'] >= $now + 97 * HOUR_IN_SECONDS, 'A consumed queue is extended back to a rolling three-day horizon' );
$discovered = tv_media( 80, 'new-creator', $now - 60 );
$discovered_pool = $pool + array( $discovered['key'] => $discovered );
$discovery_refresh = BSC_TV_Player_Schedule::before_advance( $automatic, $discovered_pool, $automatic_settings, $now + 60 );
tv_check( 80 === $discovery_refresh['plan'][0]['show_id'] && $discovery_refresh['vod'] === $automatic['vod'], 'A new eligible replay refreshes automatic choices without interrupting the current programme' );
$fixed = BSC_TV_Player_Schedule::change( $automatic, $pool, $automatic_settings, $now, 'replace', $automatic['plan'][2]['id'], 3, BSC_TV_Player_Schedule::fingerprint( $automatic ) );
$fixed_refreshed = BSC_TV_Player_Schedule::replenish( $fixed, $discovered_pool, $automatic_settings, $now + 60 );
tv_check( ! empty( $fixed_refreshed['plan'][2]['fixed'] ) && array_slice( $fixed_refreshed['plan'], 0, 3 ) === array_slice( $fixed['plan'], 0, 3 ), 'Catalogue refresh preserves the manual replacement and every preceding slot' );
$fixed_move = BSC_TV_Player_Schedule::change( $automatic, $pool, $automatic_settings, $now, 'move', $automatic['plan'][0]['id'], $automatic['plan'][0]['start'] + 900, BSC_TV_Player_Schedule::fingerprint( $automatic ) );
$fixed_move_refreshed = BSC_TV_Player_Schedule::replenish( $fixed_move, $discovered_pool, $automatic_settings, $now + 60 );
tv_check( $fixed_move_refreshed['plan'][0] === $fixed_move['plan'][0], 'Automatic refresh preserves an explicitly delayed programme and its gap' );
tv_check( ! empty( $priority['plan'][0]['fixed'] ), 'Programme-next priorities are marked as manual choices' );
$unplanned_priority = $state;
$unplanned_priority['manual'] = array( 3 );
$unplanned_priority = BSC_TV_Player_Schedule::replenish( $unplanned_priority, $pool, $automatic_settings, $now );
$unplanned_priority_refreshed = BSC_TV_Player_Schedule::replenish( $unplanned_priority, $discovered_pool, $automatic_settings, $now + 60 );
tv_check( 3 === $unplanned_priority_refreshed['plan'][0]['show_id'] && ! empty( $unplanned_priority_refreshed['plan'][0]['fixed'] ) && $unplanned_priority_refreshed['plan'][0] === $unplanned_priority['plan'][0], 'A manual priority in an initially empty queue survives new catalogue discoveries' );
$legacy = $automatic;
unset( $legacy['plan_source'] );
foreach ( $legacy['plan'] as &$legacy_row ) { unset( $legacy_row['fixed'] ); }
unset( $legacy_row );
$legacy_refreshed = BSC_TV_Player_Schedule::replenish( $legacy, $discovered_pool, $automatic_settings, $now + 60 );
tv_check( array_column( array_slice( $legacy_refreshed['plan'], 0, count( $legacy['plan'] ) ), 'id' ) === array_column( $legacy['plan'], 'id' ), 'Saved schedules from older versions are preserved during migration' );
$late_discovery = BSC_TV_Player_Schedule::before_advance( $automatic, $discovered_pool, $automatic_settings, $now + 4500 );
$late_discovery = BSC_TV_Player::advance( $late_discovery, $discovered_pool, array(), $automatic_settings, $now + 4500 );
tv_check( $late_discovery['vod']['start'] === $automatic['plan'][0]['start'], 'Late catalogue refresh does not restart an already-started saved programme' );
$short_pool = array( $a['key'] => array_replace( $a, array( 'duration' => 1 ) ) );
$short_filled = BSC_TV_Player_Schedule::replenish( array(), $short_pool, $automatic_settings, $now );
tv_check( 500 === count( $short_filled['plan'] ) && ! BSC_TV_Player_Schedule::needs_refresh( $short_filled, $short_pool, $automatic_settings, $now + 60 ), 'The short-video cap prevents repeated full queue builds' );
$short_consumed = BSC_TV_Player::advance( $short_filled, $short_pool, array(), $automatic_settings, $now + 60 );
tv_check( ! BSC_TV_Player_Schedule::needs_refresh( $short_consumed, $short_pool, $automatic_settings, $now + 60 ), 'Fallback refilling is throttled after consuming very short programmes' );
tv_check( $state === BSC_TV_Player_Schedule::replenish( $state, $pool, array_replace( $automatic_settings, array( 'enabled' => false ) ), $now ), 'Automatic filling does nothing when the player is disabled' );
tv_check( array() === BSC_TV_Player_Schedule::replenish( array(), array(), $automatic_settings, $now ), 'An empty catalogue never invents programmes or durations' );
tv_check( BSC_TV_Player_Schedule::signature( $pool, $automatic_settings ) === BSC_TV_Player_Schedule::signature( array_reverse( $pool, true ), $automatic_settings ), 'Unchanged catalogue ordering does not reshuffle the schedule' );
echo "OK {$checks} player clock and schedule checks.\n";
