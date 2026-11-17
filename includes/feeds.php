<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Feeds {
	const MAX_BYTES = 1048576;

	public static function with_catalogue_source( $data ) {
		if ( ! empty( $data['feed_url'] ) || empty( $data['url'] ) ) {
			return $data;
		}
		static $sources;
		if ( null === $sources ) {
			$sources = array();
			$file = fopen( BSC_TV_DIR . 'data/createurs.csv', 'rb' );
			if ( $file ) {
				$columns = fgetcsv( $file, 0, ',', '"', '' ) ?: array();
				while ( false !== ( $row = fgetcsv( $file, 0, ',', '"', '' ) ) ) {
					if ( count( $row ) !== count( $columns ) ) {
						continue;
					}
					$entry = BSC_TV_Content::channel_data( array_combine( $columns, $row ) );
					if ( $entry['url'] && $entry['feed_url'] ) {
						$key = BSC_TV_Content::channel_identity( $entry['platform'], $entry['url'] );
						$sources[ $key ] = array_intersect_key( $entry, array( 'feed_url' => true, 'feed_timezone' => true, 'feed_official' => true ) );
					}
				}
				fclose( $file );
			}
		}
		$key = BSC_TV_Content::channel_identity( $data['platform'] ?? BSC_TV_Content::platform_for_url( $data['url'] ), $data['url'] );
		return array_replace( $data, $sources[ $key ] ?? array() );
	}

	public static function fetch( $channel, $now ) {
		$url = $channel['data']['feed_url'];
		$result = wp_safe_remote_get( $url, array( 'timeout' => 7, 'redirection' => 2, 'limit_response_size' => self::MAX_BYTES + 1 ) );
		if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return new WP_Error( 'bsc_tv_feed_http', 'Agenda ou flux public inaccessible.' );
		}
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', trim( wp_remote_retrieve_body( $result ) ) );
		if ( ! $body || strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'bsc_tv_feed_size', 'Source vide ou trop volumineuse (1 Mo maximum).' );
		}
		if ( 0 === stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			try {
				$zone = empty( $channel['data']['feed_timezone'] ) ? wp_timezone() : new DateTimeZone( $channel['data']['feed_timezone'] );
			} catch ( Exception $exception ) {
				return new WP_Error( 'bsc_tv_feed_timezone', 'Fuseau de l’agenda invalide.' );
			}
			$events = self::calendar( $body, $zone );
		} elseif ( preg_match( '~<script\b[^>]*\btype\s*=\s*["\x27]application/ld\+json["\x27]~i', $body ) ) {
			$events = self::structured( $body, $url );
		} else {
			$events = self::rss( $body );
		}
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		if ( ! $events ) {
			return 0;
		}
		$existing = BSC_TV_Library::feed_index( $channel, $url );
		foreach ( $events as $event ) {
			if ( 'cancelled' === $event['status'] || BSC_TV_Sync::matches( $channel, $event['title'] ) ) {
				BSC_TV_Library::feed_event( $channel, $url, $event, $now, $existing );
			}
		}
		return count( $events );
	}

	private static function normalize( $id, $title, $url, $image, $start, $end, $status ) {
		$title = sanitize_text_field( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$url = BSC_TV_Content::url( $url );
		if ( ! $id || ( 'cancelled' !== $status && ( ! $title || ! $url || ! $start ) ) ) {
			return null;
		}
		return array( 'id' => $id, 'title' => $title, 'url' => $url, 'image' => BSC_TV_Content::url( $image ), 'start' => $start, 'end' => $end > $start ? $end : 0, 'status' => $status );
	}

	private static function calendar( $body, $zone ) {
		$lines = preg_split( '/\r\n|\n|\r/', preg_replace( '/(?:\r\n|\n|\r)[ \t]/', '', $body ) );
		$events = array();
		$item = null;
		$nested = 0;
		foreach ( $lines as $line ) {
			if ( 'BEGIN:VEVENT' === strtoupper( $line ) ) {
				$item = array();
				$nested = 0;
			} elseif ( 'END:VEVENT' === strtoupper( $line ) && null !== $item ) {
				$event = self::calendar_event( $item, $zone );
				if ( $event ) {
					$events[] = $event;
				}
				$item = null;
			} elseif ( null !== $item ) {
				if ( 0 === stripos( $line, 'BEGIN:' ) ) {
					++$nested;
				} elseif ( 0 === stripos( $line, 'END:' ) ) {
					--$nested;
				} elseif ( 0 === $nested && preg_match( '/^([A-Z0-9-]+)((?:;(?:[^":]|"[^"]*")+)?)\:(.*)$/i', $line, $parts ) ) {
					$item[ strtoupper( $parts[1] ) ] = array( $parts[3], $parts[2] );
				}
			}
		}
		return preg_match( '/^END:VCALENDAR\s*$/i', end( $lines ) ) ? $events : new WP_Error( 'bsc_tv_feed_calendar', 'Calendrier iCalendar incomplet.' );
	}

	private static function calendar_event( $item, $zone ) {
		// Recurrence expansion requires the full calendar rule and exceptions.
		if ( isset( $item['RRULE'] ) || isset( $item['RDATE'] ) ) {
			return null;
		}
		$start = self::calendar_date( $item['DTSTART'] ?? array( '', '' ), $zone );
		$end = self::calendar_date( $item['DTEND'] ?? array( '', '' ), $zone );
		$uid = $item['UID'][0] ?? '';
		if ( $uid && isset( $item['RECURRENCE-ID'] ) ) {
			$uid .= ':' . $item['RECURRENCE-ID'][0];
		}
		$flag = strtoupper( $item['STATUS'][0] ?? '' );
		$status = array( 'CANCELLED' => 'cancelled', 'TENTATIVE' => 'tentative' )[ $flag ] ?? 'upcoming';
		$title = strtr( $item['SUMMARY'][0] ?? '', array( '\\n' => ' ', '\\N' => ' ', '\\,' => ',', '\\;' => ';', '\\\\' => '\\' ) );
		return self::normalize( $uid, $title, $item['URL'][0] ?? '', $item['IMAGE'][0] ?? '', $start, $end, $status );
	}

	private static function calendar_date( $field, $default_zone ) {
		list( $value, $params ) = $field;
		if ( ! preg_match( '/^(\d{4})(\d\d)(\d\d)T(\d\d)(\d\d)(\d\d)(Z?)$/D', $value, $parts ) ) {
			return 0;
		}
		$date = "{$parts[1]}-{$parts[2]}-{$parts[3]}T{$parts[4]}:{$parts[5]}:{$parts[6]}";
		if ( 'Z' === $parts[7] ) {
			return BSC_TV_Content::parse_date( $date . 'Z' );
		}
		try {
			$zone = preg_match( '/(?:^|;)TZID="?([^";]+)"?/i', $params, $matched_zone ) ? new DateTimeZone( $matched_zone[1] ) : $default_zone;
			$time = new DateTimeImmutable( $date, $zone );
			return $time->format( 'Y-m-d\TH:i:s' ) === $date ? $time->getTimestamp() : 0;
		} catch ( Exception $exception ) {
			return 0;
		}
	}

	private static function rss( $body ) {
		if ( ! function_exists( 'simplexml_load_string' ) || preg_match( '/<!DOCTYPE|<!ENTITY/i', $body ) ) {
			return new WP_Error( 'bsc_tv_feed_xml', 'Ce flux XML ne peut pas être analysé.' );
		}
		$xml = @simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( ! $xml || 'rss' !== $xml->getName() ) {
			return new WP_Error( 'bsc_tv_feed_format', 'Formats pris en charge : iCalendar, RSS avec dates d’événement ou agenda HTML avec événements JSON-LD.' );
		}
		$events = array();
		foreach ( $xml->channel->item as $item ) {
			$dates = $item->children( 'http://purl.org/rss/1.0/modules/event/' );
			$media = $item->children( 'http://search.yahoo.com/mrss/' );
			$url = (string) $item->link;
			$status = array( 'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'tentative' => 'tentative' )[ strtolower( (string) $dates->status ) ] ?? 'upcoming';
			$event = self::normalize( (string) $item->guid ?: $url, (string) $item->title, $url, (string) $media->thumbnail['url'], self::rss_date( (string) $dates->startdate ), self::rss_date( (string) $dates->enddate ), $status );
			if ( $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	private static function rss_date( $value ) {
		$time = BSC_TV_Content::parse_date( $value );
		if ( $time || ! preg_match( '/^(?:[A-Za-z]{3},\s+)?\d{1,2} [A-Za-z]{3} \d{4} \d\d:\d\d(?::\d\d)? (?:[+-]\d{4}|GMT|UTC)$/D', $value ) ) {
			return $time;
		}
		return max( 0, (int) strtotime( $value ) );
	}

	private static function structured( $body, $source ) {
		$events = array();
		preg_match_all( '~<script\b[^>]*\btype\s*=\s*["\x27]application/ld\+json["\x27][^>]*>(.*?)</script\s*>~is', $body, $matches );
		foreach ( $matches[1] as $json ) {
			self::structured_nodes( json_decode( $json, true, 64 ), $source, $events );
		}
		return $events;
	}

	private static function schema_name( $value ) {
		return is_string( $value ) ? basename( $value ) : '';
	}

	private static function structured_nodes( $node, $source, &$events ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$mode = self::schema_name( $node['eventAttendanceMode'] ?? '' );
		$flag = self::schema_name( $node['eventStatus'] ?? '' );
		$is_online = in_array( $mode, array( 'OnlineEventAttendanceMode', 'MixedEventAttendanceMode' ), true ) || 'VirtualLocation' === self::schema_name( $node['location']['@type'] ?? '' );
		if ( $is_online || in_array( $flag, array( 'EventCancelled', 'EventPostponed' ), true ) ) {
			$status = array( 'EventCancelled' => 'cancelled', 'EventPostponed' => 'cancelled', 'EventRescheduled' => 'upcoming', 'EventScheduled' => 'upcoming', 'EventMovedOnline' => 'upcoming' )[ $flag ] ?? 'tentative';
			$event_url = is_string( $node['url'] ?? null ) ? $node['url'] : '';
			$url = $node['location']['url'] ?? $event_url;
			$image = $node['image'] ?? '';
			if ( is_array( $image ) ) {
				$image = $image['url'] ?? $image[0] ?? '';
			}
			if ( is_array( $image ) ) {
				$image = $image['url'] ?? $image['contentUrl'] ?? '';
			}
			$id = $node['@id'] ?? ( $event_url ?: $url );
			if ( is_string( $id ) && is_string( $url ) && is_string( $image ) && is_string( $node['name'] ?? '' ) ) {
				$start = is_string( $node['startDate'] ?? null ) ? BSC_TV_Content::parse_date( $node['startDate'] ) : 0;
				$end = is_string( $node['endDate'] ?? null ) ? BSC_TV_Content::parse_date( $node['endDate'] ) : 0;
				$event = self::normalize( $id ? $source . ':' . $id : '', $node['name'] ?? '', $url, $image, $start, $end, $status );
				if ( $event ) {
					$events[] = $event;
				}
			}
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::structured_nodes( $value, $source, $events );
			}
		}
	}
}
