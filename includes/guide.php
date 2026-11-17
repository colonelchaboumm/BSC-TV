<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Guide {
	const DAYS_PER_GROUP = 7;

	public static function render( $title, $url, $schedule ) {
		ob_start();
		?>
		<div class="bsc-guide" data-bsc-tv-guide="<?php echo esc_url( $url ); ?>">
			<?php if ( $title ) : ?><h2 class="bsc-guide__title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
			<div data-guide-content><?php echo $schedule; // Already escaped by schedule(). ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function schedule( $live, $upcoming, $channels, DateTimeImmutable $today, $until, $now, $images ) {
		$timezone = $today->getTimezone();
		$today_start = $today->getTimestamp();
		$dates = array();
		$rows = array();
		$channel_names = array();
		foreach ( $channels as $id => $channel ) {
			$name = $channel['platform'] . ':' . sanitize_title( $channel['creator'] );
			$channel_names[ $name ][] = $id;
		}

		foreach ( array( array( $live, true ), array( $upcoming, false ) ) as list( $events, $is_live ) ) {
			foreach ( $events as $event ) {
				$start = (int) $event['start'];
				$end = (int) ( $event['end'] ?? 0 );
				$day = ( new DateTimeImmutable( '@' . max( $start, $today_start ) ) )->setTimezone( $timezone )->setTime( 0, 0 );
				$next_day = $day->modify( '+1 day' )->getTimestamp();
				$slot_end = $end > $start ? $end : min( $next_day, $start + HOUR_IN_SECONDS );
				if ( $is_live && $end <= $start ) {
					$slot_end = max( $slot_end, $now + MINUTE_IN_SECONDS );
				}
				$slot_end = min( $slot_end, $until );
				if ( ! $start || $slot_end <= max( $start, $today_start ) ) {
					continue;
				}

				$row = self::channel( $event, $channels, $channel_names, $rows );
				$event['live'] = $is_live;
				for ( ; $day->getTimestamp() < $slot_end; $day = $day->modify( '+1 day' ) ) {
					$day_start = $day->getTimestamp();
					$day_end = $day->modify( '+1 day' )->getTimestamp();
					$visible_start = max( $day_start, $start );
					$visible_end = max( min( $day_end, $slot_end ), $visible_start + 30 * MINUTE_IN_SECONDS );
					$dates[ $day_start ][ $row ][] = $event + array( 'visible_start' => $visible_start, 'visible_end' => $visible_end );
				}
			}
		}

		if ( ! $dates ) {
			return '<p class="bsc-guide__empty">Aucune émission annoncée sur cette période.</p>';
		}
		ksort( $dates, SORT_NUMERIC );
		$groups = array_chunk( $dates, self::DAYS_PER_GROUP, true );
		uasort( $rows, static function ( $left, $right ) {
			return strnatcasecmp( $left['name'], $right['name'] ) ?: strcmp( $left['platform'], $right['platform'] );
		} );

		ob_start();
		?>
		<?php foreach ( $groups as $index => $group ) : ?>
			<div class="bsc-guide__week" data-guide-week<?php if ( $index ) : ?> hidden<?php endif; ?>>
				<?php foreach ( $group as $date => $day_rows ) : ?>
					<?php self::day( $date, $day_rows, $rows, $today_start, $timezone, $now, $images ); ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php if ( count( $groups ) > 1 ) : ?>
			<button class="bsc-guide__more" type="button" data-guide-more hidden>Afficher les prochaines dates</button>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private static function channel( $event, $channels, $channel_names, &$rows ) {
		$creator = $event['creator'] ?? '';
		$identity = $event['platform'] . ':' . sanitize_title( $creator );
		$matches = $creator ? ( $channel_names[ $identity ] ?? array() ) : array();
		$id = $event['channel_id'] ?? 0;
		if ( ! isset( $channels[ $id ] ) ) {
			$id = 1 === count( $matches ) ? $matches[0] : 0;
		}
		$channel = $channels[ $id ] ?? array();
		$profile = BSC_TV_Content::profile( $channel['bdd_id'] ?? 0 ) ?: BSC_TV_Content::profile( $event['bdd_id'] ?? 0 );
		$key = $id ? 'channel:' . $id : $event['platform'] . ':' . ( $profile['id'] ?? $identity );
		if ( ! isset( $rows[ $key ] ) ) {
			$platform = BSC_TV_Content::platforms()[ $channel['platform'] ?? $event['platform'] ] ?? 'Autre';
			$rows[ $key ] = array(
				'name' => ( $channel['creator'] ?? $creator ) ?: ( $profile['name'] ?? $platform ),
				'platform' => $platform,
				'url' => $profile['permalink'] ?? ( $channel['url'] ?? '' ),
				'logo' => ( $id ? get_the_post_thumbnail_url( $id, 'thumbnail' ) : '' ) ?: ( $profile['avatar']['thumbnail'] ?? '' ),
			);
		}
		return $key;
	}

	private static function date_label( $date, $today, $timezone ) {
		$label = wp_date( 'l j F Y', $date, $timezone );
		return $date === $today ? 'Aujourd’hui · ' . $label : $label;
	}

	private static function day( $date, $day_rows, $rows, $today, $timezone, $now, $images ) {
		$label = self::date_label( $date, $today, $timezone );
		?>
		<section class="bsc-guide__day" data-guide-day="<?php echo esc_attr( $date ); ?>">
			<h3 class="bsc-guide__date" data-guide-date tabindex="-1"><?php echo esc_html( $label ); ?></h3>
			<?php self::timeline( $date, $label, $day_rows, $rows, $timezone, $now, $images ); ?>
		</section>
		<?php
	}

	private static function timeline( $date, $label, $day_rows, $rows, $timezone, $now, $images ) {
		$hour = HOUR_IN_SECONDS;
		$first = PHP_INT_MAX;
		$last = 0;
		foreach ( $day_rows as $programmes ) {
			$first = min( $first, min( array_column( $programmes, 'visible_start' ) ) );
			$last = max( $last, max( array_column( $programmes, 'visible_end' ) ) );
		}
		$day_end = ( new DateTimeImmutable( '@' . $date ) )->setTimezone( $timezone )->modify( '+1 day' )->getTimestamp();
		$start = min( $date + intdiv( $first - $date, $hour ) * $hour, $day_end - 4 * $hour );
		$end = max( $start + 4 * $hour, $date + (int) ceil( ( $last - $date ) / $hour ) * $hour );
		$duration = max( min( $end, $day_end ), $last ) - $start;
		$hours = $duration / $hour;
		$has_now = $now >= $start && $now < $start + $duration;
		$style = '--guide-hours:' . $hours . ';' . ( $has_now ? '--guide-now:' . self::percent( $now - $start, $duration ) . ';' : '' );
		?>
		<div class="bsc-guide__scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( 'Grille des horaires · ' . $label ); ?>">
			<div class="bsc-guide__grid" style="<?php echo esc_attr( $style ); ?>">
				<div class="bsc-guide__row bsc-guide__ruler">
					<div class="bsc-guide__channel bsc-guide__corner">Chaînes</div>
					<div class="bsc-guide__track">
						<?php for ( $offset = 0; $offset < $duration; $offset += $hour ) : ?>
							<time class="bsc-guide__tick" datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $start + $offset ) ); ?>" style="--guide-position:<?php echo esc_attr( self::percent( $offset, $duration ) ); ?>"><?php echo esc_html( wp_date( 'H:i', $start + $offset, $timezone ) ); ?></time>
						<?php endfor; ?>
						<?php if ( $has_now ) : ?><time class="bsc-guide__now" datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $now ) ); ?>" title="Heure actuelle"><?php echo esc_html( wp_date( 'H:i', $now, $timezone ) ); ?></time><?php endif; ?>
					</div>
				</div>
				<?php foreach ( array_intersect_key( $rows, $day_rows ) as $key => $row ) : ?>
					<?php self::row( $row, $day_rows[ $key ], $start, $duration, $timezone, $images ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function row( $row, $programmes, $start, $duration, $timezone, $images ) {
		usort( $programmes, static function ( $left, $right ) {
			return $left['visible_start'] <=> $right['visible_start'];
		} );
		$lane_ends = array();
		foreach ( $programmes as &$programme ) {
			$lane = 0;
			while ( isset( $lane_ends[ $lane ] ) && $lane_ends[ $lane ] > $programme['visible_start'] ) {
				++$lane;
			}
			$lane_ends[ $lane ] = $programme['visible_end'];
			$programme['lane'] = $lane;
		}
		unset( $programme );
		$tag = $row['url'] ? 'a' : 'div';
		preg_match_all( '/\b[\p{L}\p{N}]/u', html_entity_decode( $row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $initials );
		?>
		<div class="bsc-guide__row">
			<div class="bsc-guide__channel">
				<<?php echo $tag; ?> class="bsc-guide__station"<?php if ( $row['url'] ) : ?> href="<?php echo esc_url( $row['url'] ); ?>"<?php endif; ?>>
					<span class="bsc-guide__logo" aria-hidden="true">
						<?php echo esc_html( implode( array_slice( $initials[0], 0, 2 ) ) ); ?>
						<?php if ( $row['logo'] ) : ?><img src="<?php echo esc_url( $row['logo'] ); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
					</span>
					<span class="bsc-guide__station-info"><strong><?php echo esc_html( $row['name'] ); ?></strong><span><?php echo esc_html( $row['platform'] ); ?></span></span>
				</<?php echo $tag; ?>>
			</div>
			<div class="bsc-guide__track bsc-guide__slots" style="--guide-lanes:<?php echo esc_attr( count( $lane_ends ) ); ?>">
				<?php foreach ( $programmes as $programme ) : ?>
					<?php self::programme( $programme, $start, $duration, $timezone, $images ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function programme( $event, $start, $duration, $timezone, $images ) {
		$title = $event['title'] ?: 'Regarder le direct';
		$left = self::percent( $event['visible_start'] - $start, $duration );
		$width = self::percent( $event['visible_end'] - $event['visible_start'], $duration );
		$style = '--guide-position:' . $left . ';--guide-width:' . $width . ';--guide-lane:' . $event['lane'];
		$end = $event['end'] ?? 0;
		$label = wp_date( 'H:i', $event['start'], $timezone );
		if ( $end > $event['start'] ) {
			$label .= ' – ' . wp_date( 'H:i', $end, $timezone );
		}
		?>
		<a class="bsc-guide__programme<?php echo $event['live'] ? ' bsc-guide__programme--live' : ''; ?>" href="<?php echo esc_url( $event['url'] ); ?>" style="<?php echo esc_attr( $style ); ?>" title="<?php echo esc_attr( $title . ' · ' . $label ); ?>">
			<?php if ( $images && ! empty( $event['image'] ) ) : ?><img class="bsc-guide__image" src="<?php echo esc_url( $event['image'] ); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
			<span class="bsc-guide__programme-content">
				<?php if ( $event['live'] ) : ?><span class="bsc-guide__live">LIVE</span><?php endif; ?>
				<strong class="bsc-guide__programme-title"><?php echo esc_html( $title ); ?></strong>
				<span class="bsc-guide__time"><time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $event['start'] ) ); ?>"><?php echo esc_html( wp_date( 'H:i', $event['start'], $timezone ) ); ?></time><?php if ( $end > $event['start'] ) : ?> – <time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $end ) ); ?>"><?php echo esc_html( wp_date( 'H:i', $end, $timezone ) ); ?></time><?php endif; ?></span>
			</span>
		</a>
		<?php
	}

	private static function percent( $offset, $duration ) {
		return number_format( 100 * $offset / $duration, 4, '.', '' ) . '%';
	}
}
