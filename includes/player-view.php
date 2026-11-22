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
	<div class="bsc-player__controls" data-player-toolbar hidden>
		<div class="bsc-player__playback-controls" data-player-controls role="group" aria-label="Commandes de la diffusion" hidden>
			<button type="button" data-player-pause data-state="pause" aria-label="Pause" title="Pause"><svg data-control-icon="pause" data-icon-solid viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="4" width="5" height="16" rx="1.5"/><rect x="14" y="4" width="5" height="16" rx="1.5"/></svg><svg data-control-icon="play" data-icon-solid viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.3 3.9C7.3 3.3 6 4 6 5.2v13.6c0 1.2 1.3 1.9 2.3 1.3l11.4-6.8c1-.6 1-2 0-2.6Z"/></svg></button>
			<button type="button" data-player-mute aria-pressed="false" data-state="sound" aria-label="Couper le son" title="Couper le son"><svg data-control-icon="sound" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path data-icon-solid d="M11 4.5 5.8 9H3a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2.8l5.2 4.5Z"/><path d="M15 8a5 5 0 0 1 0 8m3-11a9 9 0 0 1 0 14"/></svg><svg data-control-icon="muted" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path data-icon-solid d="M11 4.5 5.8 9H3a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2.8l5.2 4.5Z"/><path d="m16 9 5 6m0-6-5 6"/></svg></button>
			<label class="bsc-player__volume" data-player-volume-control>Volume <input type="range" data-player-volume min="0" max="100" step="1" value="100"></label>
		</div>
		<div class="bsc-player__platform-actions">
			<button type="button" data-player-fullscreen data-state="expand" aria-label="Plein écran" title="Plein écran" hidden><svg data-control-icon="expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m4 4 6 6m10-6-6 6M4 20l6-6m10 6-6-6"/><path data-icon-solid d="m2 2 7 1-6 6Zm20 0-1 7-6-6ZM2 22l1-7 6 6Zm20 0-7-1 6-6Z"/></svg><svg data-control-icon="collapse" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 6 6m12-6-6 6M3 21l6-6m12 6-6-6"/><path data-icon-solid d="m10 10-7-1 6-6Zm4 0 1-7 6 6Zm-4 4-1 7-6-6Zm4 0 7 1-6 6Z"/></svg></button>
			<a class="bsc-player__source" data-player-source target="_blank" rel="noopener" hidden><svg class="bsc-player__provider-icon" data-player-provider="youtube" viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden><path fill="#ff0033" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31.3 31.3 0 0 0 0 12a31.3 31.3 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31.3 31.3 0 0 0 24 12a31.3 31.3 0 0 0-.5-5.8Z"/><path fill="#fff" d="m9.6 15.6 6.3-3.6-6.3-3.6Z"/></svg><svg class="bsc-player__provider-icon" data-player-provider="twitch" viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden><path fill="#9146ff" d="M11.571 4.714h1.715v5.143h-1.715Zm4.715 0H18v5.143h-1.714ZM6 0 1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0Zm14.571 11.143-3.428 3.428h-3.429L10.286 18v-3.429H6.857V1.714h13.714Z"/></svg><span>Regarder sur la plateforme et soutenir le créateur</span></a>
		</div>
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
