<?php defined( 'ABSPATH' ) || exit; ?>
<section class="bsc-player" data-bsc-player="<?php echo esc_url( rest_url( 'bsc-tv/v1/player' ) ); ?>" data-autoplay="<?php echo 'yes' === $settings['autoplay'] ? '1' : '0'; ?>" data-muted="<?php echo 'yes' === $settings['muted'] ? '1' : '0'; ?>" aria-label="<?php echo esc_attr( $settings['title'] ); ?>">
	<div class="bsc-player__header">
		<?php if ( $logo && 'yes' === $settings['show_logo'] ) : ?><img class="bsc-player__logo" src="<?php echo esc_url( $logo ); ?>" alt="Logo BSC TV" decoding="async"><?php endif; ?>
		<?php if ( $settings['title'] ) : ?><h2 class="bsc-player__heading"><?php echo esc_html( $settings['title'] ); ?></h2><?php endif; ?>
		<div class="bsc-player__badges">
			<?php foreach ( array( 'on_air' => 'ON AIR', 'live' => 'LIVE', 'new' => 'NOUVEAUTÉ', 'premiere' => 'PREMIÈRE', 'replay' => 'REDIFFUSION' ) as $badge => $label ) : ?>
				<?php if ( 'yes' === $settings[ 'show_' . $badge ] ) : ?><span class="bsc-player__badge bsc-player__badge--<?php echo esc_attr( $badge ); ?>" data-player-badge="<?php echo esc_attr( $badge ); ?>" hidden><?php echo esc_html( $label ); ?></span><?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="bsc-player__screen-wrap"><div class="bsc-player__screen"><div class="bsc-player__mount" data-player-mount></div></div></div>
	<div class="bsc-player__actions">
		<button class="bsc-player__join" type="button" data-player-join><?php echo esc_html( $settings['join_label'] ); ?></button>
		<a class="bsc-player__source" data-player-source hidden>Regarder sur la plateforme</a>
	</div>
	<p class="bsc-player__message" data-player-message role="status" aria-live="polite">Chargement du programme…</p>
	<div class="bsc-player__meta" data-player-meta hidden>
		<?php if ( 'yes' === $settings['show_avatar'] ) : ?><img class="bsc-player__avatar" data-player-avatar alt="" hidden decoding="async"><?php endif; ?>
		<div class="bsc-player__details">
			<?php if ( 'yes' === $settings['show_title'] ) : ?><h3 class="bsc-player__show" data-player-title></h3><?php endif; ?>
			<?php if ( 'yes' === $settings['show_creator'] ) : ?><p class="bsc-player__creator" data-player-creator></p><?php endif; ?>
			<?php if ( 'yes' === $settings['show_profile'] ) : ?><a class="bsc-player__profile" data-player-profile hidden>Voir la fiche du créateur</a><?php endif; ?>
			<?php if ( 'yes' === $settings['show_times'] ) : ?><p class="bsc-player__times" data-player-times></p><?php endif; ?>
		</div>
	</div>
	<?php if ( 'yes' === $settings['show_progress'] ) : ?><progress class="bsc-player__progress" data-player-progress max="100" value="0" aria-label="Progression du programme TV" hidden></progress><?php endif; ?>
	<?php if ( 'yes' === $settings['show_next'] ) : ?><div class="bsc-player__next" data-player-next hidden><span>À suivre</span><strong data-player-next-title></strong><span data-player-next-time></span></div><?php endif; ?>
</section>
