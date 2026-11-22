<?php defined( 'ABSPATH' ) || exit; ?>
<section class="bsc-player" data-bsc-player="<?php echo esc_url( rest_url( 'bsc-tv/v1/player' ) ); ?>" data-autoplay="<?php echo 'yes' === $settings['autoplay'] ? '1' : '0'; ?>" data-muted="<?php echo 'yes' === $settings['muted'] ? '1' : '0'; ?>" data-music-meta="<?php echo 'yes' === $settings['show_music_meta'] ? '1' : '0'; ?>" aria-label="<?php echo esc_attr( $settings['title'] ); ?>">
	<div class="bsc-player__header">
		<?php if ( $logo && 'yes' === $settings['show_logo'] ) : ?><img class="bsc-player__logo" src="<?php echo esc_url( $logo ); ?>" alt="Logo BSC TV" decoding="async"><?php endif; ?>
		<?php if ( $settings['title'] ) : ?><h2 class="bsc-player__heading"><?php echo esc_html( $settings['title'] ); ?></h2><?php endif; ?>
		<div class="bsc-player__badges">
			<?php foreach ( array( 'on_air' => 'ON AIR', 'live' => 'LIVE', 'new' => 'NOUVEAUTÉ', 'premiere' => 'PREMIÈRE', 'replay' => 'REDIFFUSION', 'music' => 'MUSIC BREAK' ) as $badge => $label ) : ?>
				<?php if ( 'yes' === $settings[ 'show_' . $badge ] ) : ?><span class="bsc-player__badge bsc-player__badge--<?php echo esc_attr( $badge ); ?>" data-player-badge="<?php echo esc_attr( $badge ); ?>" hidden><?php echo esc_html( $label ); ?></span><?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="bsc-player__screen-wrap"><div class="bsc-player__screen"><?php if ( 'yes' === $settings['show_poster'] ) : ?><img class="bsc-player__poster" data-player-poster alt="" decoding="async"<?php if ( $poster ) : ?> src="<?php echo esc_url( $poster ); ?>"<?php else : ?> hidden<?php endif; ?>><?php endif; ?><div class="bsc-player__mount" data-player-mount></div>
		<?php if ( 'yes' === $settings['transition_enabled'] ) : ?><div class="bsc-player__transition" data-player-transition data-timeout="<?php echo esc_attr( $transition_timeout ); ?>" aria-hidden="true" hidden><img src="<?php echo esc_url( BSC_TV_URL . 'assets/media/transition-poster.jpg' ); ?>" alt="" decoding="async"><video data-player-transition-video data-src="<?php echo esc_url( $transition_url ); ?>" poster="<?php echo esc_url( BSC_TV_URL . 'assets/media/transition-poster.jpg' ); ?>" muted loop playsinline preload="none" tabindex="-1" disablepictureinpicture></video></div><?php endif; ?>
		<div class="bsc-player__music-screen" data-music-screen hidden><video data-music-video playsinline preload="none" tabindex="-1" disablepictureinpicture hidden></video><div class="bsc-player__music-identity" data-music-identity><img data-music-logo alt="Logo officiel Brewers Social Club" hidden><p data-music-text hidden></p></div></div>
	</div></div>
	<div class="bsc-player__controls" data-player-controls role="group" aria-label="Commandes de la diffusion" hidden>
		<button type="button" data-player-pause data-state="pause" aria-label="Pause" title="Pause"><svg data-control-icon="pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5v14M16 5v14"/></svg><svg data-control-icon="play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m8 5 11 7-11 7Z"/></svg></button>
		<button type="button" data-player-mute aria-pressed="false" data-state="sound" aria-label="Couper le son" title="Couper le son"><svg data-control-icon="sound" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m11 5-5 4H3v6h3l5 4Zm4 3a5 5 0 0 1 0 8m3-11a9 9 0 0 1 0 14"/></svg><svg data-control-icon="muted" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m11 5-5 4H3v6h3l5 4Zm5 4 5 6m0-6-5 6"/></svg></button>
		<label class="bsc-player__volume">Volume <input type="range" data-player-volume min="0" max="100" step="1" value="100"></label>
		<button type="button" data-player-fullscreen data-state="expand" aria-label="Plein écran" title="Plein écran" hidden><svg data-control-icon="expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m8 0h5v-5"/></svg><svg data-control-icon="collapse" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 8h5V3m8 0v5h5M8 21v-5H3m18 0h-5v5"/></svg></button>
	</div>
	<div class="bsc-player__actions">
		<button class="bsc-player__join" type="button" data-player-join aria-label="<?php echo esc_attr( $join_label ?: 'Lire le programme BSC TV' ); ?>"><svg class="bsc-player__play-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M7 4v16l13-8z"/></svg><?php if ( $join_label ) : ?><span><?php echo esc_html( $join_label ); ?></span><?php endif; ?></button>
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
