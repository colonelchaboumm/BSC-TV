<?php
/** php tests/music-animations.php /path/to/disposable-wordpress */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$fixture = rtrim( $argv[1] ?? '', '/\\' );
if ( ! is_file( $fixture . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide the disposable WordPress fixture.' ); }
$directory = sys_get_temp_dir() . '/bsc-tv-animations-' . bin2hex( random_bytes( 6 ) ); mkdir( $directory );
$database = $directory . '/bsc-tv-integration.sqlite';
if ( ! copy( $fixture . '/wp-content/database/bsc-tv-integration.sqlite', $database ) ) { throw new RuntimeException( 'Fixture copy failed.' ); }
define( 'DB_PATH', $database );
$wp_filter['option_active_plugins'][10][] = array( 'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bsc-tv/bsc-tv.php' ) ) ); }, 'accepted_args' => 1 );
$wp_filter['plugins_loaded'][0][] = array( 'function' => static function () { require dirname( __DIR__ ) . '/bsc-tv.php'; }, 'accepted_args' => 0 );
require $fixture . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once dirname( __DIR__ ) . '/includes/music-admin.php';
$checks = 0;
function animation_check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
$requests = 0; add_filter( 'pre_http_request', static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_http', 'No API calls for local BSC animations.' ); } );
foreach ( get_posts( array( 'post_type' => BSC_TV_Music::POST, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
$config = BSC_TV_Music::normalize( array( 'enabled' => true, 'repeat_hours' => 0 ) );
update_option( BSC_TV_Music::SETTINGS, $config ); BSC_TV_Music::invalidate();
$id = wp_insert_post( array( 'post_type' => BSC_TV_Music::POST, 'post_title' => 'Animation fixture clip', 'post_status' => 'publish' ) );
update_post_meta( $id, BSC_TV_Music::META, array( 'youtube_id' => 'aaaaaaaaaaa', 'url' => 'https://youtu.be/aaaaaaaaaaa', 'artist' => 'Fixture artist', 'title' => 'Fixture clip', 'categories' => array( 'morning', 'day', 'evening', 'night' ), 'duration' => 240, 'active' => true, 'official' => true, 'rights' => 'confirmed', 'rights_note' => 'Test fixture, no real musical rights.', 'availability' => 'available' ) );
BSC_TV_Music::invalidate();
$t = strtotime( '2026-11-22T13:00:00Z' );
$state = array( 'music_seed' => 'shared-animation-seed', 'vod' => array( 'key' => 'beer:previous', 'start' => $t - 2400, 'end' => $t ) );
$next = array( 'key' => 'beer:next', 'available' => $t + 400, 'fixed' => true );
$settings = array( 'music' => $config, 'repeat_hours' => 0 );
$pool = BSC_TV_Music::pool();
$pick = static function ( $copy = null, $options = null, $catalogue = null, $following = null ) use ( &$state, &$settings, &$pool, &$next, $t ) { return BSC_TV_Music::between( $following ?? $next, $catalogue ?? $pool, $copy ?? $state, $options ?? $settings, $t ); };
$one = $pick(); $two = $pick();
animation_check( 2 === count( BSC_TV_Music::animations() ), 'The two supplied BSC films replace the old single bundled identity' );
animation_check( null !== $one && 262 === $one['duration'] && 11 === $one['music']['entry'] && 11 === $one['music']['exit'], 'Exactly 40 minutes qualifies and the complete films add 22 reserved seconds' );
animation_check( $one['music']['identity'] === $two['music']['identity'], 'Every viewer gets identical saved animation choices for the same seed and slot' );
animation_check( $one['music']['identity']['entry_video'] !== $one['music']['identity']['exit_video'], 'The same animation is not immediately used for both phases when alternatives exist' );
$choices = array(); for ( $n = 0; $n < 30; ++$n ) { $copy = $state; $copy['music_seed'] = 'variant-' . $n; $choice = $pick( $copy ); $choices[ $choice['music']['identity']['entry_video'] ] = true; }
animation_check( 2 === count( $choices ), 'Different shared seeds can choose either supplied film' );
$copy = $state; $copy['music_last_entry_animation'] = $one['music']['identity']['entry_video'];
animation_check( $pick( $copy )['music']['identity']['entry_video'] !== $one['music']['identity']['entry_video'], 'Later breaks avoid repeating the previous entry animation' );
$short = $state; $short['vod']['start'] = $t - 2399; animation_check( null === $pick( $short ), 'An episode shorter than 40 minutes does not trigger a break' );
animation_check( null === $pick( null, null, null, array_replace( $next, array( 'available' => $t + 261 ) ) ), 'A gap one second too short cannot truncate an animation' );
animation_check( 262 === $pick( null, null, null, array_replace( $next, array( 'available' => $t + 262 ) ) )['duration'], 'Both full animations and the full music clip fit an exact gap' );
$slot_state = $state; $slot = BSC_TV_Player::begin( $one, $slot_state );
animation_check( 'entry' === BSC_TV_Music::programme( $slot, $t + 10 )['music_phase'] && 'clip' === BSC_TV_Music::programme( $slot, $t + 11 )['music_phase'] && 'exit' === BSC_TV_Music::programme( $slot, $t + 251 )['music_phase'], 'Public phase boundaries use the saved full animation lengths' );
animation_check( ! isset( $slot['music']['animation_choices'] ) && false === strpos( wp_json_encode( BSC_TV_Music::programme( $slot, $t ) ), 'rights_note' ), 'The queue and public snapshot retain selected identities rather than the whole library or private approvals' );
$video = static function ( $name, $length, $mime = 'video/mp4' ) {
	$id = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $mime, 'post_status' => 'inherit', 'guid' => 'https://example.com/' . sanitize_title( $name ) . '.mp4' ) );
	if ( null !== $length ) { wp_update_attachment_metadata( $id, array( 'length' => $length ) ); }
	return $id;
};
$first = $video( 'Future animation A', 12.25 ); $second = $video( 'Future animation B', 20 ); $unknown = $video( 'Missing duration', null ); $long = $video( 'Too long', 121 ); $audio = $video( 'Audio file', 12, 'audio/mpeg' );
$old_logo = wp_insert_attachment( array( 'post_title' => 'Legacy imported logo', 'post_mime_type' => 'video/mp4', 'post_status' => 'inherit', 'guid' => 'https://example.com/uploads/gemini_generated_video_ec824878-1.mp4' ) );
wp_update_attachment_metadata( $old_logo, array( 'length' => 10 ) );
animation_check( null === BSC_TV_Music::animation_asset( $old_logo ), 'An imported copy of the old logo is excluded from random and fixed animation phases' );
$old_config = $config; $old_config['animation_ids'] = array( $old_logo );
animation_check( 2 === count( BSC_TV_Music::animations( $old_config ) ), 'Only Lune and Manège remain when an old logo attachment is saved in the library' );
animation_check( 13 === BSC_TV_Music::animation_asset( $first )['duration'] && 21 === BSC_TV_Music::animation_asset( $second )['duration'], 'Custom film lengths round up and protect the final frame when WordPress stores whole seconds' );
animation_check( null === BSC_TV_Music::animation_asset( $unknown ) && null === BSC_TV_Music::animation_asset( $long ) && null === BSC_TV_Music::animation_asset( $audio ), 'Unknown lengths, overlong files and non-video attachments cannot enter the random library' );
$custom = BSC_TV_Music::normalize( array( 'enabled' => true, 'bundled_animation' => false, 'animation_ids' => array( $first, $second, $first, 0, $unknown, $long, $audio ) ) );
animation_check( 5 === count( $custom['animation_ids'] ) && 2 === count( BSC_TV_Music::animations( $custom ) ), 'Attachment IDs are deduplicated and only usable movies are selected' );
$loading_config = $custom; $loading_config['enabled'] = false;
update_option( BSC_TV_Music::SETTINGS, $loading_config );
$loading_urls = BSC_TV_Player::loading_animations();
animation_check( array( BSC_TV_Music::animation_asset( $first )['url'], BSC_TV_Music::animation_asset( $second )['url'] ) === $loading_urls, 'Loading uses added BSC films even when music is disabled and rejects invalid attachments' );
$loading_html = BSC_TV_Player::render( array( 'show_poster' => 'no', 'transition_video' => array( 'url' => 'https://example.com/old-logo.mp4' ) ) );
animation_check( false === strpos( $loading_html, 'old-logo.mp4' ) && false !== strpos( $loading_html, 'data-fixed="0"' ), 'The library replaces the former unique film by default, including old saved media selections' );
preg_match( '/data-sources="([^"]+)"/', $loading_html, $loading_match );
animation_check( $loading_urls === json_decode( html_entity_decode( $loading_match[1], ENT_QUOTES, 'UTF-8' ), true ), 'Only escaped public video references are embedded in the loading library' );
update_option( BSC_TV_Player::SETTINGS, array( 'enabled' => true ) );
animation_check( $loading_urls === BSC_TV_Player::snapshot()['loading_animations'], 'The existing public snapshot carries the up-to-date animation library' );
$third = $video( 'Future animation C', 16.5 ); $loading_config['animation_ids'][] = $third;
update_option( BSC_TV_Music::SETTINGS, $loading_config );
animation_check( in_array( BSC_TV_Music::animation_asset( $third )['url'], BSC_TV_Player::snapshot()['loading_animations'], true ), 'A saved new BSC film reaches active viewers through normal polling' );
$fr_urls = BSC_TV_Stations::run( 'fr', array( 'BSC_TV_Player', 'loading_animations' ) );
animation_check( 2 === count( $fr_urls ) && false !== strpos( $fr_urls[0], 'ident-circus.mp4' ) && ! array_intersect( $loading_urls, $fr_urls ), 'Each antenna uses its own animation library' );
$empty_config = $loading_config; $empty_config['animation_ids'] = array();
update_option( BSC_TV_Music::SETTINGS, $empty_config );
animation_check( $fr_urls === BSC_TV_Player::loading_animations(), 'An empty custom library safely falls back to Lune and Manège' );
animation_check( false === strpos( wp_json_encode( BSC_TV_Player::loading_animations() ), 'transition.mp4' ), 'The historic unique logo film is no longer a default candidate' );
$fixed_loading = BSC_TV_Player::render( array( 'show_poster' => 'no', 'transition_mode' => 'custom', 'transition_video' => array( 'url' => 'https://example.com/fixed.webm' ) ) );
animation_check( false !== strpos( $fixed_loading, 'data-fixed="1"' ) && false !== strpos( $fixed_loading, 'data-src="https://example.com/fixed.webm"' ), 'An explicitly selected fixed Elementor mode stays available' );
update_option( BSC_TV_Music::SETTINGS, $custom ); $custom_pool = BSC_TV_Music::pool(); $custom_settings = $settings; $custom_settings['music'] = $custom;
$selected = $pick( null, $custom_settings, $custom_pool );
animation_check( 274 === $selected['duration'] && in_array( $selected['music']['entry'], array( 13, 21 ), true ), 'Random custom movies reserve both complete durations in the common schedule' );
$fixed = $custom; $fixed['entry_video'] = $first; update_option( BSC_TV_Music::SETTINGS, $fixed ); $fixed_pool = BSC_TV_Music::pool(); $fixed_settings = $settings; $fixed_settings['music'] = $fixed;
animation_check( BSC_TV_Music::animation_asset( $first )['url'] === $pick( null, $fixed_settings, $fixed_pool )['music']['identity']['entry_video'], 'An explicitly chosen fixed entry keeps priority over the random library' );
animation_check( $one['music']['identity'] === BSC_TV_Music::programme( $slot, $t )['identity'], 'Changing future settings does not rewrite a saved current identity' );
update_post_meta( $second, '_wp_trash_meta_status', 'inherit' ); wp_update_post( array( 'ID' => $second, 'post_status' => 'trash' ) );
animation_check( null === BSC_TV_Music::animation_asset( $second ), 'Trashed videos stop being candidates' );
$many = BSC_TV_Music::normalize( array( 'animation_ids' => range( 1, 70 ) ) ); animation_check( 50 === count( $many['animation_ids'] ), 'The custom movie library is bounded at 50 attachments' );
update_option( BSC_TV_Music::SETTINGS, $config );
animation_check( 0 === $requests && ! wp_next_scheduled( BSC_TV_Player_Import::JOB ), 'Choosing and serving BSC animations makes no platform request or new import cron' );
echo "OK {$checks} random BSC animation checks.\n";
