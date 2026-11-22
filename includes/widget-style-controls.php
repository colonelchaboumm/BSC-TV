<?php

defined( 'ABSPATH' ) || exit;

/** Shared appearance controls; no defaults means existing widgets retain their design. */
trait BSC_TV_Widget_Style_Controls {
	private function bsc_dimensions( $name, $label, $selector, $property, $units = array( 'px', 'em', '%' ) ) {
		$this->add_responsive_control( $name, array( 'label' => $label, 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => $units, 'selectors' => array( $selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
	}

	private function bsc_color( $name, $label, $selector, $property ) {
		$this->add_control( $name, array( 'label' => $label, 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( $selector => $property . ': {{VALUE}};' ) ) );
	}

	private function bsc_button_style( $name, $label, $selector, $variable, $icons = false, $disabled = true ) {
		$this->start_controls_section( $name . '_style', array( 'label' => $label, 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->start_controls_tabs( $name . '_states' );
		$states = array( 'normal' => 'Normal', 'hover' => 'Survol et focus', 'active' => 'Actif' );
		if ( $disabled ) { $states['disabled'] = 'Désactivé / chargement'; }
		foreach ( $states as $state => $state_label ) {
			$this->start_controls_tab( $name . '_' . $state . '_tab', array( 'label' => $state_label ) );
			$suffix = 'normal' === $state ? '' : '-' . $state;
			$this->bsc_color( $name . '_' . $state . '_color', $icons ? 'Couleur de l’icône' : 'Couleur du texte', $selector, $variable . $suffix . '-color' );
			$this->bsc_color( $name . '_' . $state . '_background', 'Couleur du fond', $selector, $variable . $suffix . '-background' );
			if ( 'normal' === $state ) {
				$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => $name . '_border', 'selector' => $selector ) );
				$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => $name . '_shadow', 'selector' => $selector ) );
			} else {
				$pseudo = array( 'hover' => ':is(:hover, :focus-visible)', 'active' => ':is(:active, [aria-pressed="true"])', 'disabled' => ':disabled' );
				$this->bsc_color( $name . '_' . $state . '_border', 'Couleur du contour', $selector . $pseudo[ $state ], 'border-color' );
			}
			if ( 'disabled' === $state ) {
				$this->add_control( $name . '_disabled_opacity', array( 'label' => 'Opacité', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 1, 'step' => .05 ) ), 'selectors' => array( $selector . ':disabled' => 'opacity: {{SIZE}};' ) ) );
			}
			$this->end_controls_tab();
		}
		$this->end_controls_tabs();
		$this->bsc_dimensions( $name . '_padding', 'Marges intérieures', $selector, 'padding' );
		$this->bsc_dimensions( $name . '_radius', 'Arrondis', $selector, 'border-radius', array( 'px', '%' ) );
		$this->bsc_color( $name . '_focus_color', 'Repère du focus clavier', $selector . ':focus-visible', 'outline-color' );
		if ( $icons ) {
			foreach ( array( 'size' => array( 'Taille du bouton', 24, 120 ), 'icon_size' => array( 'Taille de l’icône', 10, 64 ) ) as $key => $args ) {
				$this->add_responsive_control( $name . '_' . $key, array( 'label' => $args[0], 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $args[1], 'max' => $args[2] ) ), 'selectors' => array( $selector => '--bsc-player-control-' . str_replace( '_', '-', $key ) . ': {{SIZE}}{{UNIT}};' ) ) );
			}
		}
		$this->end_controls_section();
	}
}
