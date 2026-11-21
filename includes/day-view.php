<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( 'yes' === $config['show_date'] ) : ?><p class="bsc-day__date"><time datetime="<?php echo esc_attr( $data['date'] ); ?>"><?php echo esc_html( $data['date_label'] ); ?></time><span><?php echo esc_html( $data['timezone'] ); ?></span></p><?php endif; ?>
<?php if ( ! $data['enabled'] || ! $data['items'] ) : ?>
	<p class="bsc-day__empty"><?php echo esc_html( $data['enabled'] ? $config['empty_label'] : $config['disabled_label'] ); ?></p>
<?php else : ?>
	<ol class="bsc-day__list<?php echo 'yes' !== $config['show_times'] ? ' bsc-day__list--no-times' : ''; ?>">
	<?php foreach ( $data['items'] as $item ) :
		$phase = $item['on_air'] ? 'current' : $item['phase'];
		$badge = $item['on_air'] && $item['live'] ? 'live' : $phase;
		$label = $config[ ( 'live' === $badge ? 'live' : $badge ) . '_label' ];
		$href = 'watch' === $config['link_mode'] ? $config['watch_url'] : ( 'source' === $config['link_mode'] ? $item['url'] : '' );
		$external = 'source' === $config['link_mode'] || $config['watch_external']; ?>
		<li class="bsc-day__item bsc-day__item--<?php echo esc_attr( $phase ); ?>"<?php echo $item['on_air'] ? ' aria-current="true"' : ''; ?> data-day-key="<?php echo esc_attr( $item['key'] . ':' . $item['start'] ); ?>">
			<?php if ( 'yes' === $config['show_times'] ) : ?><div class="bsc-day__times"><time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $item['display_start'] ) ); ?>"><?php echo esc_html( wp_date( 'H:i', $item['display_start'] ) ); ?></time><span><?php echo esc_html( $item['end'] ? wp_date( 'H:i', $item['display_end'] ) : 'En direct' ); ?></span></div><?php endif; ?>
			<div class="bsc-day__body">
				<?php if ( 'yes' === $config['show_images'] ) : ?><div class="bsc-day__image"><?php if ( $item['image'] ) : ?><img src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy" decoding="async"><?php else : ?><span aria-hidden="true">▶</span><?php endif; ?></div><?php endif; ?>
				<div class="bsc-day__details">
					<?php if ( 'yes' === $config['show_badges'] ) : ?><span class="bsc-day__badge bsc-day__badge--<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span><?php endif; ?>
					<h3 class="bsc-day__title"><?php if ( $href ) : ?><a href="<?php echo esc_url( $href ); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $item['title'] ); ?></a><?php else : ?><?php echo esc_html( $item['title'] ); ?><?php endif; ?></h3>
					<?php if ( 'yes' === $config['show_channel'] && $item['channel'] ) : ?><p class="bsc-day__channel"><?php echo esc_html( $item['channel'] ); ?></p><?php endif; ?>
					<?php if ( 'yes' === $config['show_creator'] && $item['creator'] && ( 'yes' !== $config['show_channel'] || $item['creator'] !== $item['channel'] || ( 'yes' === $config['show_profile'] && $item['profile_url'] ) ) ) : ?><p class="bsc-day__creator"><?php if ( 'yes' === $config['show_profile'] && $item['profile_url'] ) : ?><a href="<?php echo esc_url( $item['profile_url'] ); ?>"><?php echo esc_html( $item['creator'] ); ?></a><?php else : ?><?php echo esc_html( $item['creator'] ); ?><?php endif; ?></p><?php endif; ?>
					<?php if ( $item['continued'] || $item['provisional'] ) : ?><p class="bsc-day__note"><?php echo esc_html( $item['provisional'] ? 'Horaire prévisionnel après le direct' : 'Suite du programme précédent' ); ?></p><?php endif; ?>
				</div>
			</div>
		</li>
	<?php endforeach; ?>
	</ol>
<?php endif; ?>
