<?php defined( 'ABSPATH' ) || exit;
$image = $config['image'] ? $current['image'] : '';
?>
<h2 class="bsc-tv-section">En ce moment sur BSC TV</h2>
<article class="bsc-tv-card bsc-tv-badge__current<?php echo $image ? ' bsc-tv-card--image' : ''; ?>" data-bsc-tv-current="<?php echo esc_attr( $current['key'] ); ?>">
	<?php if ( $image ) : ?><img class="bsc-tv-card__media" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
	<div class="bsc-tv-card__content">
		<div class="bsc-tv-card__flags"><span class="bsc-tv-live"><span aria-hidden="true"></span> À L’ANTENNE</span><span class="bsc-tv-platform"><?php echo $current['live'] ? 'DIRECT' : 'REDIFFUSION'; ?></span></div>
		<h3 class="bsc-tv-card__title"><a href="<?php echo esc_url( $config['watch_url'] ); ?>"><?php echo esc_html( $current['title'] ); ?></a></h3>
		<div class="bsc-tv-card__meta">
			<time datetime="<?php echo esc_attr( $highlight['datetime'] ); ?>"><?php echo esc_html( $highlight['date'] ); ?></time>
			<?php if ( $current['creator'] ) : ?>
				<?php if ( $current['profile_url'] ) : ?><a class="bsc-tv-creator" href="<?php echo esc_url( $current['profile_url'] ); ?>"><?php echo esc_html( $current['creator'] ); ?></a><?php else : ?><span><?php echo esc_html( $current['creator'] ); ?></span><?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</article>
