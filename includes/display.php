<?php

defined( 'ABSPATH' ) || exit;

final class BSC_TV_Display {
	const MAX_DAYS = 180;
	const LIVE_PAGE = 'https://brewerssocialclub.com/le-live/';

	public static function register() {
		wp_register_style( 'bsc-tv', BSC_TV_URL . 'assets/programme.css', array(), BSC_TV_VERSION );
		wp_register_script( 'bsc-tv', BSC_TV_URL . 'assets/programme.js', array(), BSC_TV_VERSION, true );
		wp_register_style( 'bsc-tv-guide', BSC_TV_URL . 'assets/guide.css', array(), BSC_TV_VERSION );
		wp_register_script( 'bsc-tv-guide', BSC_TV_URL . 'assets/guide.js', array(), BSC_TV_VERSION, true );
		add_shortcode( 'bsc_tv', static function ( $attributes ) {
			$attributes = (array) $attributes;
			$format = $attributes['format'] ?? 'programme';
			$compact = in_array( $format, array( 'menu', 'badge' ), true );
			return self::render( shortcode_atts( array( 'format' => 'programme', 'days' => 'menu' === $format ? 7 : 45, 'limit' => $compact ? 3 : 12, 'platform' => '', 'image' => 1, 'title' => 'menu' === $format ? 'BSC TV' : 'Programme TV', 'agenda' => '', 'pulse' => 'yes', 'show_player_current' => 'yes', 'watch_url' => self::LIVE_PAGE, 'watch_label' => 'Regarder Le live', 'show_watch_link' => 'yes' ), $attributes ) );
		} );
	}

	private static function config( $values ) {
		$format = $values['format'] ?? 'programme';
		$compact = in_array( $format, array( 'menu', 'badge' ), true );
		$platform = sanitize_key( $values['platform'] ?? '' );
		$config = array(
			'format' => in_array( $format, array( 'menu', 'guide', 'badge' ), true ) ? $format : 'programme',
			'days' => max( 1, min( self::MAX_DAYS, absint( $values['days'] ?? 45 ) ) ),
			'limit' => 'guide' === $format ? null : max( 1, min( $compact ? 12 : 60, absint( $values['limit'] ?? 12 ) ) ),
			'platform' => isset( BSC_TV_Content::platforms()[ $platform ] ) ? $platform : '',
			'image' => empty( $values['image'] ) ? 0 : 1,
		);
		if ( 'badge' === $format ) {
			$config['player'] = in_array( $values['player'] ?? $values['show_player_current'] ?? 'yes', array( 'yes', '1', 1, true ), true ) ? 1 : 0;
			$watch = $values['watch_url'] ?? self::LIVE_PAGE;
			$watch = is_array( $watch ) ? ( $watch['url'] ?? '' ) : $watch;
			$config['watch_url'] = ( is_scalar( $watch ) ? BSC_TV_Content::url( $watch ) : '' ) ?: self::LIVE_PAGE;
		}
		return $config;
	}

	public static function rest() {
		register_rest_route( 'bsc-tv/v1', '/programme', array(
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'args' => array(
				'format' => array( 'type' => 'string', 'enum' => array( 'programme', 'menu', 'guide', 'badge' ) ),
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DAYS ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60 ),
				'platform' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( BSC_TV_Content::platforms() ) ) ),
				'image' => array( 'type' => 'integer', 'enum' => array( 0, 1 ) ),
				'player' => array( 'type' => 'integer', 'enum' => array( 0, 1 ) ),
				'watch_url' => array( 'type' => 'string' ),
			),
			'callback' => static function ( $request ) {
				$config = self::config( $request->get_query_params() );
				$content = 'guide' === $config['format'] ? array( 'html' => self::guide( $config ) ) : ( 'badge' === $config['format'] ? self::badge_programme( $config ) : self::programme( $config ) );
				$response = new WP_REST_Response( $content );
				$response->header( 'Cache-Control', 'no-store, max-age=0' );
				return $response;
			},
		) );
	}

	private static function match_manual( $event, $jobs ) {
		$video = BSC_TV_Content::youtube_video_id( $event['url'] );
		$matching_channel = array();
		foreach ( $jobs as $job ) {
			$job_video = BSC_TV_Content::youtube_video_id( $job['url'] );
			if ( $video && $video === $job_video ) {
				return $job;
			}
			if ( ! $matching_channel && $job['resolved_channel_id'] === $event['channel_id'] && ( $event['platform'] === $job['platform'] || ! empty( $job['origin_channel'] ) ) && ! $job_video && abs( $event['start'] - $job['start'] ) <= 15 * MINUTE_IN_SECONDS ) {
				$matching_channel = $job;
			}
		}
		return $matching_channel;
	}

	private static function channels() {
		$channels = array();
		$identities = array();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::CHANNEL ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			if ( empty( $data['url'] ) ) {
				continue;
			}
			$data['creator'] = get_the_title( $post );
			$channels[ $post->ID ] = $data;
			$identity = BSC_TV_Content::channel_identity( $data['platform'], $data['url'] );
			$identities[ $identity ][ $post->ID ] = true;
			if ( 'youtube' === $data['platform'] ) {
				$key = BSC_TV_Content::channel_key( 'youtube', $data['url'] );
				$state = BSC_TV_Sync::state( $post->ID, $key );
				if ( ! empty( $state['channel_id'] ) ) {
					$identities[ 'youtube:' . $state['channel_id'] ][ $post->ID ] = true;
				}
			}
		}
		return array( $channels, $identities );
	}

	private static function resolve_channel( $show, $state, $identities ) {
		if ( ! empty( $show['channel_id'] ) ) {
			return $show['channel_id'];
		}
		$identity = BSC_TV_Content::channel_identity( $show['platform'], $show['url'] );
		if ( 'youtube' === $show['platform'] && ! empty( $state['video']['channel_id'] ) ) {
			$identity = 'youtube:' . $state['video']['channel_id'];
		}
		$matches = $identities[ $identity ] ?? array();
		return 1 === count( $matches ) ? key( $matches ) : 0;
	}

	private static function events( $config, $until = null ) {
		$now = time();
		$until = $until ?? $now + $config['days'] * DAY_IN_SECONDS;
		$live_enabled = ! empty( BSC_TV_Sync::settings()['live_enabled'] );
		$live = array();
		$upcoming = array();
		$manual = array();
		list( $channels, $identities ) = self::channels();
		foreach ( BSC_TV_Content::posts( BSC_TV_Content::SHOW ) as $post ) {
			$data = BSC_TV_Content::data( $post->ID );
			if ( empty( $data['url'] ) || ( ! empty( $data['origin_channel'] ) && ! isset( $channels[ $data['origin_channel'] ] ) ) ) {
				continue;
			}
			$thumbnail = get_the_post_thumbnail_url( $post, 'large' ) ?: '';
			$data['title'] = get_the_title( $post );
			$data['image'] = $thumbnail ?: $data['image'];
			$state = BSC_TV_Sync::state( $post->ID, BSC_TV_Content::youtube_video_id( $data['url'] ) );
			$channel_id = self::resolve_channel( $data, $state, $identities );
			$channel = $channels[ $channel_id ] ?? array();
			$data['bdd_id'] = BSC_TV_Content::profile( $data['bdd_id'] ) ? $data['bdd_id'] : ( $channel['bdd_id'] ?? 0 );
			$data['creator'] = $data['creator'] ?: ( $channel['creator'] ?? '' );
			$data['channel_id'] = $channel_id;
			if ( isset( $state['video'] ) && 'upcoming' === $data['status'] ) {
				$video = $state['video'];
				if ( in_array( $video['status'], array( 'ended', 'cancelled' ), true ) ) {
					continue;
				}
				if ( 'upcoming' === $video['status'] || ( $live_enabled && $now - $state['live_at'] < BSC_TV_Sync::MAX_LIVE_AGE ) ) {
					$data['status'] = $video['status'];
					$data['start'] = $video['start'];
					$data['end'] = $video['end'] ?? 0;
					$data['image'] = $data['image'] ?: $video['image'];
				}
			}
			if ( 'live' === $data['status'] && $data['start'] <= $now && ( ! $data['end'] || $now < $data['end'] ) ) {
				$live[ $post->ID ] = $data;
			} elseif ( 'upcoming' === $data['status'] ) {
				$upcoming[ $post->ID ] = $data;
			}
			$manual[ $post->ID ] = $data + array( 'post_id' => $post->ID, 'resolved_channel_id' => $channel_id );
		}
		$seen = array();
		foreach ( $channels as $channel_id => $data ) {
			$platform = $data['platform'];
			$key = BSC_TV_Content::channel_key( $platform, $data['url'] );
			if ( ! $key ) {
				continue;
			}
			$state = BSC_TV_Sync::state( $channel_id, $key );
			$current = $live_enabled && $now - ( $state['live_at'] ?? 0 ) < BSC_TV_Sync::MAX_LIVE_AGE ? ( $state['live'] ?? array() ) : array();
			$current_ids = array_fill_keys( array_column( $current, 'id' ), true );
			$broadcasts = array();
			foreach ( $current as $event ) {
				$broadcasts[] = $event['start'];
			}
			foreach ( array( 'live' => $current, 'upcoming' => $state['schedule'] ?? array() ) as $status => $events ) {
				foreach ( $events as $event ) {
					if ( 'upcoming' === $status && ( isset( $current_ids[ $event['id'] ] ) || 'live' === ( $event['status'] ?? '' ) ) ) {
						continue;
					}
					if ( 'upcoming' === $status && 'twitch' === $platform && self::overlaps_live( $event, $broadcasts ) ) {
						continue;
					}
					$event += array( 'platform' => $platform, 'creator' => $data['creator'], 'bdd_id' => $data['bdd_id'], 'channel_id' => $channel_id );
					$identity = $platform . ':' . $status . ':' . $event['id'];
					if ( isset( $seen[ $identity ] ) ) {
						continue;
					}
					$seen[ $identity ] = true;
					$editorial = self::match_manual( $event, $manual );
					if ( $editorial ) {
						if ( 'cancelled' === $editorial['status'] || isset( $live[ $editorial['post_id'] ] ) || ( 'upcoming' === $status && ! isset( $upcoming[ $editorial['post_id'] ] ) ) ) {
							continue;
						}
						unset( $upcoming[ $editorial['post_id'] ] );
						$event['title'] = $editorial['title'];
						$event['image'] = $editorial['image'] ?: $event['image'];
						$event['creator'] = $editorial['creator'] ?: $event['creator'];
						$event['bdd_id'] = $editorial['bdd_id'] ?: $event['bdd_id'];
						if ( 'upcoming' === $status && $editorial['platform'] !== $platform && $config['platform'] === $editorial['platform'] ) {
							$event['platform'] = $editorial['platform'];
							$event['url'] = $editorial['url'];
						}
					}
					if ( 'live' === $status ) {
						$live[] = $event;
					} else {
						$upcoming[] = $event;
					}
				}
			}
		}
		$platform_filter = static function ( $event ) use ( $config ) {
			return ! $config['platform'] || $config['platform'] === $event['platform'];
		};
		$live = array_filter( $live, $platform_filter );
		$upcoming = array_filter( $upcoming, static function ( $event ) use ( $now, $until, $platform_filter ) {
			return $event['start'] >= $now && $event['start'] <= $until && $platform_filter( $event );
		} );
		usort( $live, static function ( $left, $right ) { return $right['start'] <=> $left['start']; } );
		usort( $upcoming, static function ( $left, $right ) { return $left['start'] <=> $right['start']; } );
		return array( array_slice( $live, 0, $config['limit'] ), array_slice( $upcoming, 0, $config['limit'] ), $channels );
	}

	private static function guide( $config ) {
		$now = time();
		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$until = $today->modify( '+' . $config['days'] . ' days' )->getTimestamp();
		list( $live, $upcoming, $channels ) = self::events( $config, $until - 1 );
		return BSC_TV_Guide::schedule( $live, $upcoming, $channels, $today, $until, $now, $config['image'] );
	}

	private static function overlaps_live( $event, $starts ) {
		foreach ( $starts as $start ) {
			if ( $start >= $event['start'] - 15 * MINUTE_IN_SECONDS && $start <= ( $event['end'] ?: $event['start'] + 15 * MINUTE_IN_SECONDS ) ) {
				return true;
			}
		}
		return false;
	}

	private static function card( $event, $config, $live = false, $featured = false ) {
		$menu = in_array( $config['format'], array( 'menu', 'badge' ), true );
		$profile = BSC_TV_Content::profile( $event['bdd_id'] );
		$creator = $profile['name'] ?? $event['creator'];
		$avatar = $profile['avatar']['thumbnail'] ?? '';
		$image = $config['image'] ? ( $event['image'] ?: $avatar ) : '';
		$platform = BSC_TV_Content::platforms()[ $event['platform'] ] ?? 'Autre';
		?>
		<article class="bsc-tv-card<?php echo $featured ? ' bsc-tv-card--featured' : ''; ?><?php echo $image ? ' bsc-tv-card--image' : ''; ?>">
			<?php if ( $image ) : ?>
				<img class="bsc-tv-card__media" src="<?php echo esc_url( $image ); ?>" alt="" loading="<?php echo $featured ? 'eager' : 'lazy'; ?>" decoding="async">
			<?php endif; ?>
			<div class="bsc-tv-card__content">
				<div class="bsc-tv-card__flags">
					<?php if ( $live ) : ?><span class="bsc-tv-live"><span aria-hidden="true"></span> EN DIRECT</span><?php endif; ?>
					<span class="bsc-tv-platform"><?php echo esc_html( $platform ); ?></span>
				</div>
				<h3 class="bsc-tv-card__title"><a href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $event['title'] ?: 'Regarder le direct' ); ?></a></h3>
				<div class="bsc-tv-card__meta">
					<?php if ( ! $live ) : ?>
						<time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $event['start'] ) ); ?>"><?php echo esc_html( wp_date( $menu ? 'D j M · H:i' : 'l j F · H:i', $event['start'] ) ); ?></time>
					<?php endif; ?>
					<?php if ( $creator && ! empty( $profile['permalink'] ) ) : ?>
						<a class="bsc-tv-creator" href="<?php echo esc_url( $profile['permalink'] ); ?>">
							<?php if ( $avatar ) : ?><img src="<?php echo esc_url( $avatar ); ?>" alt="" loading="lazy" width="30" height="30"><?php endif; ?>
							<?php echo esc_html( $creator ); ?>
						</a>
					<?php elseif ( $creator ) : ?>
						<span><?php echo esc_html( $creator ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	private static function programme( $config, $exclude_resource = null ) {
		list( $live, $upcoming ) = self::events( $config );
		if ( $exclude_resource ) { $live = array_values( array_filter( $live, static function ( $event ) use ( $exclude_resource ) { return BSC_TV_Player::resource( $event['url'], true ) !== $exclude_resource; } ) ); }
		$hero = 'programme' === $config['format'];
		ob_start();
		if ( $live ) {
			echo '<h2 class="bsc-tv-section">En ce moment</h2>';
			self::cards( $live, $config, true, $hero );
		}
		echo '<h2 class="bsc-tv-section">À venir</h2>';
		if ( $upcoming ) {
			self::cards( $upcoming, $config, false, $hero && ! $live );
		} else {
			echo '<p class="bsc-tv-empty">Aucun direct annoncé sur cette période.</p>';
		}
		return array( 'html' => ob_get_clean(), 'live' => count( $live ), 'highlight' => self::highlight( $live[0] ?? $upcoming[0] ?? array(), ! empty( $live ) ) );
	}

	/** The badge follows the local shared antenna; platform announcements remain below. */
	private static function badge_programme( $config ) {
		$snapshot = $config['player'] ? BSC_TV_Player::snapshot() : null;
		$current = ! empty( $snapshot['enabled'] ) ? $snapshot['current'] : null;
		$programme = self::programme( $config, $current['resource'] ?? null );
		$programme['poll_after'] = 60;
		if ( ! $current ) { return $programme; }
		$highlight = self::highlight( $current, true );
		$highlight['datetime'] = gmdate( DATE_ATOM, $current['start'] );
		$highlight['date'] = $current['end'] ? wp_date( 'H:i', $current['start'] ) . ' – ' . wp_date( 'H:i', $current['end'] ) : 'Depuis ' . wp_date( 'H:i', $current['start'] );
		$programme['highlight'] = $highlight;
		$programme['live']++;
		$programme['announcement'] = 'À l’antenne sur BSC TV : ' . $highlight['title'];
		$programme['poll_after'] = $current['end'] ? max( 5, min( 60, $current['end'] - $snapshot['server_time'] + 1 ) ) : 60;
		ob_start();
		require BSC_TV_DIR . 'includes/badge-current.php';
		$programme['html'] = ob_get_clean() . $programme['html'];
		return $programme;
	}

	private static function highlight( $event, $live ) {
		if ( ! $event ) {
			return array( 'state' => 'empty', 'title' => '', 'datetime' => '', 'date' => '' );
		}
		return array(
			'state' => $live ? 'live' : 'upcoming',
			'title' => html_entity_decode( wp_strip_all_tags( $event['title'] ?: 'Émission en direct' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'datetime' => $live ? '' : gmdate( DATE_ATOM, $event['start'] ),
			'date' => $live ? '' : wp_date( 'D j M · H:i', $event['start'] ),
		);
	}

	private static function cards( $events, $config, $live, $featured ) {
		echo '<div class="bsc-tv-grid">';
		foreach ( $events as $index => $event ) {
			self::card( $event, $config, $live, 0 === $index && $featured );
		}
		echo '</div>';
	}

	public static function render( $settings ) {
		if ( 'guide' === ( $settings['format'] ?? '' ) ) {
			$config = self::config( $settings );
			wp_enqueue_style( 'bsc-tv-guide' );
			wp_enqueue_script( 'bsc-tv-guide' );
			$url = add_query_arg( array_diff_key( $config, array( 'limit' => true ) ), rest_url( 'bsc-tv/v1/programme' ) );
			return BSC_TV_Guide::render( sanitize_text_field( $settings['title'] ?? '' ), $url, self::guide( $config ) );
		}
		wp_enqueue_style( 'bsc-tv' );
		wp_enqueue_script( 'bsc-tv' );
		$badge = 'badge' === ( $settings['format'] ?? '' );
		$config = self::config( $settings );
		$programme = $badge ? self::badge_programme( $config ) : self::programme( $config );
		$title = sanitize_text_field( $settings['title'] ?? '' );
		$agenda = BSC_TV_Content::url( $settings['agenda'] ?? '' );
		$refresh_url = add_query_arg( $config, rest_url( 'bsc-tv/v1/programme' ) );
		if ( $badge ) {
			return self::badge( $settings, $programme, $title, $agenda, $refresh_url );
		}
		ob_start();
		?>
		<div class="bsc-tv bsc-tv--<?php echo esc_attr( $config['format'] ); ?>" data-bsc-tv="<?php echo esc_url( $refresh_url ); ?>" data-live="<?php echo esc_attr( $programme['live'] ); ?>">
			<?php if ( $title ) : ?><p class="bsc-tv-heading"><?php echo esc_html( $title ); ?></p><?php endif; ?>
			<?php self::programme_body( $programme, $agenda ); ?>
			<span class="bsc-tv-announcement" role="status"></span>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function programme_body( $programme, $agenda ) {
		?>
		<div class="bsc-tv-programme"><?php echo $programme['html']; // Generated and escaped by card(). ?></div>
		<?php if ( $agenda ) : ?><a class="bsc-tv-agenda" href="<?php echo esc_url( $agenda ); ?>">Voir le programme <span aria-hidden="true">→</span></a><?php endif;
	}

	private static function badge( $settings, $programme, $title, $agenda, $refresh_url ) {
		$highlight = $programme['highlight'];
		$labels = array(
			'live' => sanitize_text_field( $settings['live_label'] ?? '' ) ?: 'LIVE',
			'upcoming' => sanitize_text_field( $settings['upcoming_label'] ?? '' ) ?: 'À venir',
			'empty' => sanitize_text_field( $settings['empty_label'] ?? '' ) ?: 'BSC TV',
		);
		$show_title = ! isset( $settings['show_highlight_title'] ) || 'yes' === $settings['show_highlight_title'];
		$show_date = ! isset( $settings['show_highlight_date'] ) || 'yes' === $settings['show_highlight_date'];
		$inline = 'inline' === ( $settings['panel_mode'] ?? '' );
		$animate = 'yes' === ( $settings['pulse'] ?? 'yes' );
		ob_start();
		?>
		<div class="bsc-tv bsc-tv--badge" data-bsc-tv="<?php echo esc_url( $refresh_url ); ?>" data-live="<?php echo esc_attr( $programme['live'] ); ?>" data-bsc-tv-state="<?php echo esc_attr( $highlight['state'] ); ?>">
			<details class="bsc-tv-badge<?php echo $inline ? ' bsc-tv-badge--inline' : ''; ?><?php echo $animate ? ' bsc-tv-badge--pulse' : ''; ?>">
				<summary class="bsc-tv-badge__trigger">
					<span class="bsc-tv-badge__status" data-bsc-tv-live-label="<?php echo esc_attr( $labels['live'] ); ?>" data-bsc-tv-upcoming-label="<?php echo esc_attr( $labels['upcoming'] ); ?>" data-bsc-tv-empty-label="<?php echo esc_attr( $labels['empty'] ); ?>">
						<span class="bsc-tv-badge__dot" aria-hidden="true"></span>
						<span class="bsc-tv-badge__label"><?php echo esc_html( $labels[ $highlight['state'] ] ); ?></span>
					</span>
					<?php if ( $show_title || $show_date ) : ?>
						<span class="bsc-tv-badge__details" <?php echo ( $show_title && $highlight['title'] ) || ( $show_date && $highlight['date'] ) ? '' : 'hidden'; ?>>
							<?php if ( $show_title ) : ?><span class="bsc-tv-badge__title" <?php echo $highlight['title'] ? '' : 'hidden'; ?>><?php echo esc_html( $highlight['title'] ); ?></span><?php endif; ?>
							<?php if ( $show_date ) : ?><time class="bsc-tv-badge__date" <?php echo $highlight['datetime'] ? 'datetime="' . esc_attr( $highlight['datetime'] ) . '"' : 'hidden'; ?>><?php echo esc_html( $highlight['date'] ); ?></time><?php endif; ?>
						</span>
					<?php endif; ?>
					<span class="bsc-tv-badge__chevron" aria-hidden="true"></span>
				</summary>
				<div class="bsc-tv-badge__panel bsc-tv--menu">
					<?php if ( $title ) : ?><p class="bsc-tv-heading"><?php echo esc_html( $title ); ?></p><?php endif; ?>
					<?php self::programme_body( $programme, $agenda ); ?>
					<?php if ( ! isset( $settings['show_watch_link'] ) || 'yes' === $settings['show_watch_link'] ) : $watch_config = self::config( $settings ); $watch_label = sanitize_text_field( $settings['watch_label'] ?? '' ) ?: 'Regarder Le live'; ?><a class="bsc-tv-agenda bsc-tv-watch bsc-tv-badge__watch" href="<?php echo esc_url( $watch_config['watch_url'] ); ?>"><?php echo esc_html( $watch_label ); ?> <span aria-hidden="true">→</span></a><?php endif; ?>
				</div>
			</details>
			<span class="bsc-tv-announcement" role="status"></span>
		</div>
		<?php
		return ob_get_clean();
	}
}
