<?php
/** Run with: php tests/player-clock.php. No WordPress or network needed. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
require dirname( __DIR__ ) . '/includes/player.php';

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
echo "OK {$checks} player clock checks.\n";
