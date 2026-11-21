<?php

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

final class BSC_TV_Player_Widget extends Elementor\Widget_Base {
	public function get_name() { return 'bsc-tv-player'; }
	public function get_title() { return 'BSC TV — Live Player 24/7'; }
	public function get_icon() { return 'eicon-youtube'; }
	public function get_categories() { return array( 'general' ); }
	public function get_style_depends() { return array( 'bsc-tv-player' ); }
	public function get_script_depends() { return array( 'bsc-tv-player' ); }
	protected function is_dynamic_content(): bool { return true; }

	private function size( $name, $label, $variable, $min, $max, $default = null ) {
		$args = array( 'label' => $label, 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $min, 'max' => $max ) ), 'selectors' => array( '{{WRAPPER}} .bsc-player' => $variable . ': {{SIZE}}{{UNIT}};' ) );
		if ( null !== $default ) {
			$args['default'] = array( 'size' => $default, 'unit' => 'px' );
		}
		$this->add_responsive_control( $name, $args );
	}

	protected function register_controls() {
		$root = '{{WRAPPER}} .bsc-player';
		$this->start_controls_section( 'player_content', array( 'label' => 'BSC TV 24/7' ) );
		$this->add_control( 'title', array( 'label' => 'Titre du widget', 'type' => Controls_Manager::TEXT, 'default' => 'BSC TV · Live 24/7' ) );
		$this->add_control( 'join_label', array( 'label' => 'Bouton de synchronisation', 'type' => Controls_Manager::TEXT, 'default' => 'Rejoindre le direct TV' ) );
		$this->add_control( 'logo', array( 'label' => 'Logo fixe BSC TV', 'type' => Controls_Manager::MEDIA ) );
		foreach ( array( 'logo' => 'Logo BSC TV', 'avatar' => 'Avatar BSC BDD', 'creator' => 'Nom du créateur', 'profile' => 'Lien vers sa fiche BSC BDD', 'title' => 'Titre de l’émission', 'next' => 'Programme suivant', 'progress' => 'Progression', 'times' => 'Horaires', 'on_air' => 'Badge ON AIR', 'live' => 'Badge LIVE', 'new' => 'Badge NOUVEAUTÉ', 'premiere' => 'Badge PREMIÈRE', 'replay' => 'Badge REDIFFUSION' ) as $key => $label ) {
			$this->add_control( 'show_' . $key, array( 'label' => $label, 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		}
		$this->add_control( 'autoplay', array( 'label' => 'Tenter la lecture automatique quand le widget est visible', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => '' ) );
		$this->add_control( 'muted', array( 'label' => 'Démarrer sans son', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'player_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'La programmation et les autorisations se règlent dans BSC TV → Réglages et imports. Les logos et métadonnées restent hors du lecteur officiel. Le bouton Lecture peut être nécessaire selon le navigateur.', 'content_classes' => 'elementor-panel-alert elementor-panel-alert-info' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'player_style', array( 'label' => 'Lecteur et habillage', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_responsive_control( 'ratio', array( 'label' => 'Format du lecteur', 'type' => Controls_Manager::SELECT, 'options' => array( '16 / 9' => '16:9', '4 / 3' => '4:3', '21 / 9' => '21:9' ), 'default' => '16 / 9', 'selectors' => array( $root => '--bsc-player-ratio: {{VALUE}};' ) ) );
		$this->size( 'gap', 'Espacements', '--bsc-player-gap', 0, 48 );
		$this->size( 'radius', 'Arrondi du conteneur', '--bsc-player-radius', 0, 48 );
		foreach ( array( 'surface' => 'Fond du widget', 'ink' => 'Titres et créateur', 'muted' => 'Horaires et messages', 'accent' => 'Bouton, liens et progression', 'live' => 'Badge et point LIVE' ) as $key => $label ) {
			$this->add_control( 'color_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-player-' . $key . ': {{VALUE}};' ) ) );
		}
		foreach ( array( 'heading' => 'Titre du widget', 'show' => 'Titre de l’émission', 'creator' => 'Créateur', 'next' => 'Programme suivant', 'times' => 'Horaires', 'join' => 'Bouton' ) as $key => $label ) {
			$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'player_' . $key . '_typography', 'label' => $label, 'selector' => $root . ' .bsc-player__' . $key ) );
		}
		$this->add_responsive_control( 'padding', array( 'label' => 'Marges intérieures', 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', '%' ), 'selectors' => array( $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
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
		foreach ( array( 'on_air' => 'ON AIR', 'live' => 'LIVE', 'new' => 'NOUVEAUTÉ', 'premiere' => 'PREMIÈRE', 'replay' => 'REDIFFUSION' ) as $badge => $label ) {
			foreach ( array( 'color' => 'Texte', 'background' => 'Fond' ) as $property => $property_label ) {
				$this->add_control( 'badge_' . $badge . '_' . $property, array( 'label' => $label . ' · ' . $property_label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root . ' .bsc-player__badge--' . $badge => $property . ': {{VALUE}};' ) ) );
			}
		}
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'player_badges_typography', 'label' => 'Typographie des badges', 'selector' => $root . ' .bsc-player__badge' ) );
		$this->end_controls_section();
	}

	protected function render() {
		echo BSC_TV_Player::render( $this->get_settings_for_display() ); // Escaped by the view.
	}
}
