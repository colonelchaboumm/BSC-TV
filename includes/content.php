<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Content {
	const CHANNEL = 'bsc_tv_channel';
	const SHOW = 'bsc_tv_show';
	const DATA = '_bsc_tv_data';
	const STATE = '_bsc_tv_state';
	const ARCHIVED = 'bsc_tv_archived';

	public static function platforms() {
		return array(
			'youtube'     => 'YouTube',
			'twitch'      => 'Twitch',
			'kick'        => 'Kick',
			'facebook'    => 'Facebook',
			'instagram'   => 'Instagram',
			'tiktok'      => 'TikTok',
			'dailymotion' => 'Dailymotion',
			'vimeo'       => 'Vimeo',
			'beertube'    => 'BeerTube',
			'zoom'        => 'Zoom',
			'other'       => 'Autre',
		);
	}

	public static function register() {
		register_post_status( self::ARCHIVED, array(
			'label' => 'Archives BSC TV', 'public' => false, 'protected' => true,
			'show_in_admin_all_list' => false, 'show_in_admin_status_list' => true,
			'label_count' => _n_noop( 'Archive BSC TV <span class="count">(%s)</span>', 'Archives BSC TV <span class="count">(%s)</span>' ),
		) );
		register_post_type( self::CHANNEL, array(
			'labels' => array( 'name' => 'Chaînes', 'menu_name' => 'Sources vidéo · communes', 'singular_name' => 'Chaîne', 'add_new_item' => 'Ajouter une chaîne', 'featured_image' => 'Logo de la chaîne', 'set_featured_image' => 'Choisir le logo' ),
			'public' => false, 'show_ui' => true, 'show_in_menu' => 'bsc-tv',
			'supports' => array( 'title', 'thumbnail' ), 'capability_type' => 'post', 'map_meta_cap' => true,
		) );
		register_post_type( self::SHOW, array(
			'labels' => array( 'name' => 'Émissions', 'menu_name' => 'Catalogue · commun', 'singular_name' => 'Émission', 'add_new_item' => 'Ajouter une émission' ),
			'public' => false, 'show_ui' => true, 'show_in_menu' => 'bsc-tv',
			'supports' => array( 'title', 'thumbnail' ), 'capability_type' => 'post', 'map_meta_cap' => true,
		) );
	}

	public static function url( $value ) {
		$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		return preg_match( '~^https?://~i', $url ) && wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	public static function platform_for_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$domains = array( 'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'twitch.tv' => 'twitch', 'kick.com' => 'kick', 'facebook.com' => 'facebook', 'fb.watch' => 'facebook', 'instagram.com' => 'instagram', 'tiktok.com' => 'tiktok', 'dailymotion.com' => 'dailymotion', 'dai.ly' => 'dailymotion', 'vimeo.com' => 'vimeo', 'beertube.tv' => 'beertube', 'zoom.us' => 'zoom' );
		foreach ( $domains as $domain => $platform ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return $platform;
			}
		}
		return 'other';
	}

	public static function channel_key( $platform, $url ) {
		if ( $platform !== self::platform_for_url( $url ) ) {
			return '';
		}
		$path = trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
		if ( 'youtube' === $platform ) {
			if ( preg_match( '~^channel/(UC[\w-]{22})(?:/(?:streams|live|videos|featured|about))?$~', $path, $match ) ) {
				return $match[1];
			}
			if ( preg_match( '~^(@[\p{L}\p{M}\p{N}_.-]{1,100})(?:/(?:streams|live|videos|featured|about))?$~u', $path, $match ) ) {
				return $match[1];
			}
			if ( preg_match( '~^user/([\w.-]{1,100})(?:/(?:streams|live|videos|featured|about))?$~', $path, $match ) ) {
				return 'user:' . $match[1];
			}
			return '';
		}
		if ( 'twitch' === $platform || 'kick' === $platform ) {
			if ( ! preg_match( '~^([a-z0-9_-]{2,25})(?:/(?:schedule|videos|about|clips))?$~i', $path, $match ) ) {
				return '';
			}
			$key = strtolower( $match[1] );
			return in_array( $key, array( 'directory', 'videos', 'search', 'following', 'browse', 'dashboard', 'categories', 'api', 'settings' ), true ) ? '' : $key;
		}
		return '';
	}

	public static function youtube_video_id( $url ) {
		if ( 'youtube' !== self::platform_for_url( $url ) ) {
			return '';
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( 'youtu.be' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$id = $path;
		} elseif ( preg_match( '~^(?:live|embed)/([\w-]{11})$~', $path, $match ) ) {
			$id = $match[1];
		} else {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$id = $query['v'] ?? '';
		}
		return is_string( $id ) && preg_match( '/^[\w-]{11}$/D', $id ) ? $id : '';
	}

	public static function parse_date( $value, $local = true ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d(?::\d\d(?:\.\d{1,9})?)?(?:Z|[+-]\d\d:\d\d)?$/D', $value ) ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $value, $local ? wp_timezone() : new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
				return 0;
			}
			// Refuse a local time normalized through a spring DST gap.
			if ( ! preg_match( '/(?:Z|[+-]\d\d:\d\d)$/', $value ) && $date->format( 'Y-m-d H:i' ) !== str_replace( 'T', ' ', substr( $value, 0, 16 ) ) ) {
				return 0;
			}
			return max( 0, $date->getTimestamp() );
		} catch ( Exception $exception ) {
			return 0;
		}
	}

	public static function data( $id ) {
		return (array) get_post_meta( $id, self::DATA, true );
	}

	public static function public_channel( $id ) {
		$post = $id ? get_post( $id ) : null;
		return $post && self::CHANNEL === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password && ! empty( self::data( $id )['url'] ) ? $post : null;
	}

	public static function channel_identity( $platform, $url ) {
		$key = self::channel_key( $platform, $url );
		if ( $key ) {
			// YouTube channel IDs are case-sensitive, unlike handles and usernames.
			return $platform . ':' . ( 'youtube' === $platform && 0 === strpos( $key, 'UC' ) ? $key : strtolower( $key ) );
		}
		return $url ? $platform . ':' . untrailingslashit( $url ) : '';
	}

	public static function lock( $name ) {
		global $wpdb;
		$lock = ( time() + 10 * MINUTE_IN_SECONDS ) . ':' . wp_generate_uuid4();
		$previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $previous ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $lock ) );
			return 1 === $inserted ? $lock : false;
		}
		if ( (int) $previous >= time() ) {
			return false;
		}
		$acquired = $wpdb->update( $wpdb->options, array( 'option_value' => $lock ), array( 'option_name' => $name, 'option_value' => $previous ) );
		return $acquired ? $lock : false;
	}

	public static function unlock( $name, $lock ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $lock ) );
	}

	public static function profile( $id ) {
		static $profiles = array();
		if ( ! $id || ! function_exists( 'bsc_bdd_get_entity' ) ) {
			return array();
		}
		if ( ! isset( $profiles[ $id ] ) ) {
			$entity = bsc_bdd_get_entity( $id );
			$profiles[ $id ] = 'publish' === ( $entity['status'] ?? '' ) && ! post_password_required( $entity['id'] ) && ! get_post_field( 'post_password', $entity['id'] ) ? $entity : array();
		}
		return $profiles[ $id ];
	}

	public static function posts( $type, $statuses = 'publish' ) {
		return get_posts( array(
			'post_type' => $type, 'post_status' => $statuses, 'posts_per_page' => -1,
			'orderby' => 'ID', 'order' => 'ASC', 'update_post_term_cache' => false,
			'has_password' => false,
		) );
	}

	public static function channel_data( $input ) {
		$url = self::url( $input['url'] ?? '' );
		$platform = sanitize_key( $input['platform'] ?? '' );
		$detected = self::platform_for_url( $url );
		$official = strtolower( trim( (string) ( $input['feed_official'] ?? '' ) ) );
		return array(
			'url' => $url, 'platform' => isset( self::platforms()[ $platform ] ) ? $platform : $detected,
			'bdd_id' => absint( $input['bdd_id'] ?? 0 ), 'source' => self::url( $input['source'] ?? '' ),
			'country' => sanitize_text_field( $input['country'] ?? '' ), 'language' => sanitize_text_field( $input['language'] ?? '' ),
			'activity' => sanitize_text_field( $input['activity'] ?? '' ), 'notes' => sanitize_textarea_field( $input['notes'] ?? '' ),
			'keywords' => sanitize_text_field( $input['keywords'] ?? '' ),
			'feed_url' => self::url( $input['feed_url'] ?? '' ), 'feed_official' => in_array( $official, array( '1', 'yes', 'oui', 'true' ), true ) ? 1 : 0,
			'feed_timezone' => sanitize_text_field( $input['feed_timezone'] ?? '' ),
		);
	}

	public static function show_dates_valid( $start, $end, $status ) {
		$now = time();
		return $start > 0 && ( ! $end || $end > $start ) && ( 'live' !== $status || ( $start <= $now && $end > $now ) );
	}

	public static function show_data( $input ) {
		$url = self::url( $input['url'] ?? '' );
		$start = self::parse_date( $input['starts_at'] ?? '' );
		$end = self::parse_date( $input['ends_at'] ?? '' );
		$platform = sanitize_key( $input['platform'] ?? '' );
		$status = sanitize_key( $input['status'] ?? '' );
		if ( ! $url || ( ! empty( $input['ends_at'] ) && ! $end ) || ! self::show_dates_valid( $start, $end, $status ) ) {
			return new WP_Error( 'invalid_show', 'Saisissez un lien et un début valides. Une émission déclarée en direct doit avoir commencé et avoir une heure de fin future.' );
		}
		return array(
			'url' => $url, 'platform' => isset( self::platforms()[ $platform ] ) ? $platform : self::platform_for_url( $url ),
			'start' => $start, 'end' => $end, 'status' => in_array( $status, array( 'live', 'cancelled', 'ended' ), true ) ? $status : 'upcoming',
			'channel_id' => absint( $input['channel_id'] ?? 0 ),
			'bdd_id' => absint( $input['bdd_id'] ?? 0 ), 'creator' => sanitize_text_field( $input['creator'] ?? '' ),
			'image' => self::url( $input['image'] ?? '' ),
			'source' => self::url( $input['source'] ?? '' ),
		);
	}
}
