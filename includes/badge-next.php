<?php defined( 'ABSPATH' ) || exit; ?>
<h2 class="bsc-tv-section">À venir sur l’antenne</h2>
<ol class="bsc-tv-badge__schedule">
	<?php foreach ( $next as $item ) : ?>
		<li>
			<time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $item['start'] ) ); ?>"><?php echo esc_html( wp_date( 'D j · H:i', $item['start'] ) ); ?></time>
			<div><a href="<?php echo esc_url( $config['watch_url'] ?: $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a><?php if ( $item['creator'] ) : ?><span><?php echo esc_html( $item['creator'] ); ?></span><?php endif; ?></div>
		</li>
	<?php endforeach; ?>
</ol>
