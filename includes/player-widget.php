<?php

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

final class BSC_TV_Player_Widget extends Elementor\Widget_Base {
	use BSC_TV_Widget_Style_Controls;
	public function get_name() { return 'bsc-tv-player'; }
	public function get_title() { return 'BSC TV — Live Player 24/7'; }
	public function get_icon() { return 'eicon-youtube'; }
	public function get_categories() { return array( 'general' ); }
	public function get_style_depends() { return array( 'bsc-tv-player' ); }
	public function get_script_depends() { return array( 'bsc-tv-player' ); }
	protected function is_dynamic_content(): bool { return true; }

	private function size( $name, $label, $variable, $min, $max, $default = null, $condition = array() ) {
		$args = array( 'label' => $label, 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $min, 'max' => $max ) ), 'selectors' => array( '{{WRAPPER}} .bsc-player' => $variable . ': {{SIZE}}{{UNIT}};' ) );
		if ( $condition ) {
			$args['condition'] = $condition;
		}
		if ( null !== $default ) {
			$args['default'] = array( 'size' => $default, 'unit' => 'px' );
		}
		$this->add_responsive_control( $name, $args );
	}

	protected function register_controls() {
		$root = '{{WRAPPER}} .bsc-player';
		$this->start_controls_section( 'player_content', array( 'label' => 'BSC TV 24/7' ) );
		$this->add_control( 'title', array( 'label' => 'Titre du widget', 'type' => Controls_Manager::TEXT, 'default' => 'BSC TV · Live 24/7' ) );
		$this->add_control( 'logo', array( 'label' => 'Logo fixe BSC TV', 'type' => Controls_Manager::MEDIA ) );
		foreach ( array( 'logo' => 'Logo BSC TV', 'avatar' => 'Avatar BSC BDD', 'poster' => 'Image de l’émission avant lecture', 'creator' => 'Nom du créateur', 'profile' => 'Lien vers sa fiche BSC BDD', 'title' => 'Titre de l’émission', 'next' => 'Programme suivant', 'progress' => 'Progression', 'times' => 'Horaires', 'on_air' => 'Badge ON AIR', 'live' => 'Badge LIVE', 'new' => 'Badge NOUVEAUTÉ', 'premiere' => 'Badge PREMIÈRE', 'replay' => 'Badge REDIFFUSION' ) as $key => $label ) {
			$this->add_control( 'show_' . $key, array( 'label' => $label, 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		}
		$this->add_control( 'show_publication', array( 'label' => 'Date de parution à côté de REDIFFUSION', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes', 'condition' => array( 'show_replay' => 'yes' ) ) );
		$this->add_control( 'start_mode', array( 'label' => 'Démarrage de la vidéo', 'type' => Controls_Manager::SELECT, 'options' => array( 'auto' => 'Automatique quand le lecteur est visible', 'click' => 'Après un clic sur Lecture' ), 'default' => 'auto', 'description' => 'Le navigateur peut demander un clic pour autoriser la lecture avec le son.' ) );
		$this->add_control( 'muted', array( 'label' => 'Démarrer sans son', 'description' => 'Désactivé par défaut : le son est actif. Un clic peut être nécessaire pour autoriser la lecture.', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '' ) );
		$this->add_control( 'player_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'La programmation se règle dans BSC TV → Le live et les autorisations dans les fiches des chaînes et émissions. Les logos et métadonnées restent hors du lecteur officiel. L’icône Lecture permet de démarrer si le navigateur bloque la lecture automatique.', 'content_classes' => 'elementor-panel-alert elementor-panel-alert-info' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_transition', array( 'label' => 'Animation entre les programmes' ) );
		$this->add_control( 'transition_enabled', array( 'label' => 'Afficher l’animation pendant le chargement', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'transition_video', array( 'label' => 'Animation personnalisée', 'type' => Controls_Manager::MEDIA, 'media_types' => array( 'video' ), 'condition' => array( 'transition_enabled' => 'yes' ), 'description' => 'Sans vidéo choisie, utilise l’animation Brewers Social Club incluse. Lecture en boucle sans son jusqu’au démarrage du programme.' ) );
		$this->add_control( 'transition_timeout', array( 'label' => 'Attente maximale avant de montrer le lecteur (secondes)', 'type' => Controls_Manager::NUMBER, 'min' => 5, 'max' => 60, 'default' => 20, 'condition' => array( 'transition_enabled' => 'yes' ), 'description' => 'L’animation s’efface aussi immédiatement en cas de lecture bloquée ou d’erreur.' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_music', array( 'label' => 'Interludes musicaux' ) );
		$this->add_control( 'show_music', array( 'label' => 'Afficher le badge MUSIC BREAK', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'show_music_meta', array( 'label' => 'Afficher le titre et l’artiste du clip', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'music_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'Activation, validation des clips, horaires et identité se règlent dans BSC TV → Music Interludes. Le même lecteur et la même antenne sont conservés.' ) );
		$this->end_controls_section();
		$this->start_controls_section( 'player_music_style', array( 'label' => 'Style des interludes musicaux', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'background' => 'Fond des transitions', 'color' => 'Texte des transitions', 'badge' => 'Badge MUSIC BREAK' ) as $key => $label ) { $this->add_control( 'music_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-player-music-' . $key . ': {{VALUE}};' ) ) ); }
		$this->size( 'music_logo_size', 'Taille du logo de transition', '--bsc-player-music-logo-size', 40, 400 );
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'music_text_typography', 'label' => 'Texte des transitions', 'selector' => $root . ' [data-music-text]' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_style', array( 'label' => 'Lecteur et habillage', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_responsive_control( 'ratio', array( 'label' => 'Format du lecteur', 'type' => Controls_Manager::SELECT, 'options' => array( '16 / 9' => '16:9', '4 / 3' => '4:3', '21 / 9' => '21:9' ), 'default' => '16 / 9', 'selectors' => array( $root => '--bsc-player-ratio: {{VALUE}};' ) ) );
		$this->add_control( 'poster_fit', array( 'label' => 'Cadrage de l’image avant lecture', 'type' => Controls_Manager::SELECT, 'options' => array( 'cover' => 'Remplir l’écran', 'contain' => 'Afficher l’image entière' ), 'default' => 'cover', 'condition' => array( 'show_poster' => 'yes' ), 'selectors' => array( $root => '--bsc-player-poster-fit: {{VALUE}};' ) ) );
		$this->add_control( 'transition_fit', array( 'label' => 'Cadrage de l’animation', 'type' => Controls_Manager::SELECT, 'options' => array( 'contain' => 'Afficher toute l’animation', 'cover' => 'Remplir l’écran' ), 'default' => 'contain', 'condition' => array( 'transition_enabled' => 'yes' ), 'selectors' => array( $root => '--bsc-player-transition-fit: {{VALUE}};' ) ) );
		$this->size( 'gap', 'Espacements', '--bsc-player-gap', 0, 48 );
		$this->size( 'radius', 'Arrondi du conteneur', '--bsc-player-radius', 0, 48 );
		foreach ( array( 'surface' => 'Fond du widget', 'ink' => 'Titres et créateur', 'muted' => 'Horaires et messages', 'accent' => 'Commandes, liens et progression', 'live' => 'Badge et point LIVE' ) as $key => $label ) {
			$this->add_control( 'color_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-player-' . $key . ': {{VALUE}};' ) ) );
		}
		foreach ( array( 'heading' => 'Titre du widget', 'show' => 'Titre de l’émission', 'creator' => 'Créateur', 'next' => 'Programme suivant', 'times' => 'Horaires', 'source' => 'Lien vers la plateforme' ) as $key => $label ) {
			$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'player_' . $key . '_typography', 'label' => $label, 'selector' => $root . ' .bsc-player__' . $key ) );
		}
		$this->add_responsive_control( 'padding', array( 'label' => 'Marges intérieures', 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', '%' ), 'selectors' => array( $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'player_border', 'selector' => $root ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'player_shadow', 'selector' => $root ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_toolbar_style', array( 'label' => 'Barre de commandes et volume', 'tab' => Controls_Manager::TAB_STYLE ) );
		$toolbar = $root . ' .bsc-player__controls';
		$this->bsc_color( 'toolbar_background', 'Fond de la barre', $toolbar, 'background-color' );
		$this->bsc_dimensions( 'toolbar_padding', 'Marges intérieures', $toolbar, 'padding' );
		$this->bsc_dimensions( 'toolbar_radius', 'Arrondis', $toolbar, 'border-radius', array( 'px', '%' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'toolbar_border', 'selector' => $toolbar ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'toolbar_shadow', 'selector' => $toolbar ) );
		$this->size( 'controls_gap', 'Espacement entre les commandes', '--bsc-player-controls-gap', 0, 48 );
		$this->add_responsive_control( 'toolbar_align', array( 'label' => 'Alignement', 'type' => Controls_Manager::SELECT, 'options' => array( 'flex-start' => 'À gauche', 'center' => 'Centré', 'flex-end' => 'À droite', 'space-between' => 'Réparti' ), 'selectors' => array( $toolbar => 'justify-content: {{VALUE}};' ) ) );
		$this->bsc_color( 'volume_color', 'Couleur du curseur de volume', $root, '--bsc-player-volume-color' );
		$this->bsc_color( 'volume_label_color', 'Couleur du texte Volume', $root . ' .bsc-player__volume', 'color' );
		$this->size( 'volume_width', 'Largeur du curseur de volume', '--bsc-player-volume-width', 40, 240 );
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'volume_typography', 'label' => 'Texte Volume', 'selector' => $root . ' .bsc-player__volume' ) );
		$this->end_controls_section();
		$this->bsc_button_style( 'player_buttons', 'Boutons · style commun', $toolbar . ' button', '--bsc-player-control', true );
		foreach ( array( 'pause' => 'Bouton Lecture / Pause', 'mute' => 'Bouton Son', 'fullscreen' => 'Bouton Plein écran' ) as $key => $label ) {
			$this->bsc_button_style( 'player_button_' . $key, $label, $toolbar . ' [data-player-' . $key . ']', '--bsc-player-control', true );
		}
		$this->bsc_button_style( 'player_source', 'Lien vers la plateforme', $root . ' .bsc-player__source', '--bsc-player-source', false, false );
		$this->start_controls_section( 'player_provider_style', array( 'label' => 'Logo de la plateforme', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->size( 'provider_size', 'Taille du logo', '--bsc-player-provider-size', 12, 64 );
		$this->bsc_color( 'provider_youtube_color', 'Couleur du logo YouTube', $root . ' [data-player-provider="youtube"] path:first-child', 'fill' );
		$this->bsc_color( 'provider_twitch_color', 'Couleur du logo Twitch', $root . ' [data-player-provider="twitch"] path', 'fill' );
		$this->end_controls_section();

		$this->start_controls_section( 'player_crt_style', array( 'label' => 'Écran bombé et CRT', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'curve_enabled', array( 'label' => 'Activer le verre bombé sur l’image', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '', 'selectors' => array( $root => '--bsc-player-curve: 1;' ) ) );
		$this->size( 'curve_depth', 'Profondeur du relief', '--bsc-player-curve-depth', 4, 48, 22, array( 'curve_enabled' => 'yes' ) );
		$this->size( 'curve_radius', 'Courbure du verre', '--bsc-player-curve-radius', 0, 100, 44, array( 'curve_enabled' => 'yes' ) );
		$this->add_control( 'curve_corner_color', array( 'label' => 'Couleur à l’extérieur des arrondis', 'description' => 'Rogne l’image dans les coins selon la courbure choisie. Sans couleur définie, reprend le fond du widget.', 'type' => Controls_Manager::COLOR, 'condition' => array( 'curve_enabled' => 'yes' ), 'selectors' => array( $root => '--bsc-player-corner-color: {{VALUE}};' ) ) );
		$this->add_control( 'curve_color', array( 'label' => 'Teinte de l’ombre du verre', 'type' => Controls_Manager::COLOR, 'condition' => array( 'curve_enabled' => 'yes' ), 'selectors' => array( $root => '--bsc-player-curve-color: {{VALUE}};' ) ) );
		$this->add_control( 'crt_enabled', array( 'label' => 'Activer le filtre CRT sur l’image', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '', 'selectors' => array( $root => '--bsc-player-crt: 1;' ) ) );
		$this->add_responsive_control( 'crt_intensity', array( 'label' => 'Intensité de l’effet', 'type' => Controls_Manager::SLIDER, 'size_units' => array( '%' ), 'range' => array( '%' => array( 'min' => 0, 'max' => 100 ) ), 'default' => array( 'size' => 35, 'unit' => '%' ), 'condition' => array( 'crt_enabled' => 'yes' ), 'selectors' => array( $root => '--bsc-player-crt-intensity: {{SIZE}};' ) ) );
		$this->size( 'crt_spacing', 'Espacement des lignes', '--bsc-player-crt-spacing', 2, 10, 4, array( 'crt_enabled' => 'yes' ) );
		$this->size( 'crt_glow', 'Rayon de la lueur', '--bsc-player-crt-glow', 0, 60, 18, array( 'crt_enabled' => 'yes' ) );
		$this->add_control( 'crt_color', array( 'label' => 'Teinte de la lueur', 'type' => Controls_Manager::COLOR, 'condition' => array( 'crt_enabled' => 'yes' ), 'selectors' => array( $root => '--bsc-player-crt-color: {{VALUE}};' ) ) );
		$this->add_control( 'crt_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'Effets fixes superposés à l’image, désactivés par défaut. Le verre bombé ajoute des reflets et des ombres ; le filtre CRT ajoute des lignes et une trame RVB. Les clics traversent ces effets pour atteindre les commandes du lecteur. Le plein écran propre à YouTube ou Twitch ne conserve pas ces calques.', 'content_classes' => 'elementor-panel-alert elementor-panel-alert-info' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_logo_style', array( 'label' => 'Logo fixe BSC TV', 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_logo' => 'yes' ) ) );
		$this->size( 'logo_size', 'Taille', '--bsc-player-logo-size', 24, 240 );
		$this->add_responsive_control( 'logo_position', array( 'label' => 'Position dans l’en-tête', 'type' => Controls_Manager::SELECT, 'default' => '0', 'options' => array( '0' => 'Avant le titre', '3' => 'Après le titre et les badges' ), 'selectors' => array( $root . ' .bsc-player__logo' => 'order: {{VALUE}};' ) ) );
		$this->add_control( 'logo_opacity', array( 'label' => 'Opacité', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 1, 'step' => 0.05 ) ), 'selectors' => array( $root => '--bsc-player-logo-opacity: {{SIZE}};' ) ) );
		$this->add_control( 'logo_background', array( 'label' => 'Fond', 'type' => Controls_Manager::COLOR, 'selectors' => array( $root . ' .bsc-player__logo' => 'background: {{VALUE}};' ) ) );
		$this->add_responsive_control( 'logo_margin', array( 'label' => 'Marges', 'type' => Controls_Manager::DIMENSIONS, 'selectors' => array( $root . ' .bsc-player__logo' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'player_logo_border', 'selector' => $root . ' .bsc-player__logo' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_avatar_style', array( 'label' => 'Avatar du créateur', 'tab' => Controls_Manager::TAB_STYLE, 'condition' => array( 'show_avatar' => 'yes' ) ) );
		$this->size( 'avatar_size', 'Taille', '--bsc-player-avatar-size', 20, 140 );
		$this->add_control( 'avatar_shape', array( 'label' => 'Forme', 'type' => Controls_Manager::SELECT, 'default' => '50%', 'options' => array( '50%' => 'Ronde', '0' => 'Carrée' ), 'selectors' => array( $root => '--bsc-player-avatar-radius: {{VALUE}};' ) ) );
		$this->add_responsive_control( 'avatar_position', array( 'label' => 'Position', 'type' => Controls_Manager::SELECT, 'default' => '0', 'options' => array( '0' => 'Avant le créateur', '2' => 'Après le créateur' ), 'selectors' => array( $root . ' .bsc-player__avatar' => 'order: {{VALUE}};' ) ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'player_avatar_border', 'selector' => $root . ' .bsc-player__avatar' ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'player_avatar_shadow', 'selector' => $root . ' .bsc-player__avatar' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_badges_style', array( 'label' => 'Badges de diffusion', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'on_air' => 'ON AIR', 'live' => 'LIVE', 'new' => 'NOUVEAUTÉ', 'premiere' => 'PREMIÈRE', 'replay' => 'REDIFFUSION', 'publication' => 'Date de parution' ) as $badge => $label ) {
			foreach ( array( 'color' => 'Texte', 'background' => 'Fond' ) as $property => $property_label ) {
				$this->add_control( 'badge_' . $badge . '_' . $property, array( 'label' => $label . ' · ' . $property_label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root . ' .bsc-player__badge--' . $badge => $property . ': {{VALUE}};' ) ) );
			}
		}
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'player_badges_typography', 'label' => 'Typographie des badges', 'selector' => $root . ' .bsc-player__badge' ) );
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'player_publication_typography', 'label' => 'Typographie de la date de parution', 'selector' => $root . ' .bsc-player__badge--publication' ) );
		$this->bsc_dimensions( 'player_badges_padding', 'Marges intérieures', $root . ' .bsc-player__badge', 'padding' );
		$this->bsc_dimensions( 'player_badges_radius', 'Arrondis', $root . ' .bsc-player__badge', 'border-radius', array( 'px', '%' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'player_badges_border', 'selector' => $root . ' .bsc-player__badge' ) );
		$this->bsc_color( 'player_live_dot_color', 'Couleur du point LIVE', $root . ' .bsc-player__badge--live::before', 'background-color' );
		$this->end_controls_section();

		$this->start_controls_section( 'player_screen_style', array( 'label' => 'Cadre de l’écran', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->bsc_color( 'screen_background', 'Fond de l’écran sans vidéo', $root . ' .bsc-player__screen', 'background-color' );
		$this->bsc_color( 'screen_frame_background', 'Fond du cadre', $root . ' .bsc-player__screen-wrap', 'background-color' );
		$this->bsc_dimensions( 'screen_frame_padding', 'Marges intérieures du cadre', $root . ' .bsc-player__screen-wrap', 'padding' );
		$this->bsc_dimensions( 'screen_frame_radius', 'Arrondis du cadre', $root . ' .bsc-player__screen-wrap', 'border-radius', array( 'px', '%' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'screen_frame_border', 'selector' => $root . ' .bsc-player__screen-wrap' ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'screen_frame_shadow', 'selector' => $root . ' .bsc-player__screen-wrap' ) );
		$this->add_control( 'controls_style_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'Ces styles personnalisent les commandes et l’habillage BSC TV. L’intérieur des lecteurs YouTube et Twitch reste géré par les plateformes.' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_text_style', array( 'label' => 'Textes et progression', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'heading' => 'Titre du widget', 'show' => 'Titre de l’émission', 'creator' => 'Créateur', 'times' => 'Horaires', 'message' => 'Messages' ) as $key => $label ) {
			$this->bsc_color( 'player_text_' . $key, $label, $root . ' .bsc-player__' . $key, 'color' );
		}
		$this->bsc_color( 'player_profile_color', 'Lien BSC BDD · normal', $root . ' .bsc-player__profile', 'color' );
		$this->bsc_color( 'player_profile_hover_color', 'Lien BSC BDD · survol et focus', $root . ' .bsc-player__profile:is(:hover, :focus-visible)', 'color' );
		$this->bsc_color( 'player_next_color', 'Titre du programme suivant', $root . ' .bsc-player__next strong', 'color' );
		$this->bsc_color( 'progress_color', 'Couleur de la progression', $root, '--bsc-player-progress-color' );
		$this->bsc_color( 'progress_background', 'Fond de la progression', $root, '--bsc-player-progress-background' );
		$this->size( 'progress_height', 'Hauteur de la progression', '--bsc-player-progress-height', 2, 24 );
		$this->end_controls_section();
	}

	protected function render() {
		echo BSC_TV_Player::render( $this->get_settings_for_display() ); // Escaped by the view.
	}
}
