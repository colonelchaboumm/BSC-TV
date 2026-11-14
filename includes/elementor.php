<?php

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

class BSC_TV_Programme_Widget extends Elementor\Widget_Base {
	public function get_name() { return 'bsc-tv-programme'; }
	public function get_title() { return 'BSC TV – Programme'; }
	public function get_icon() { return 'eicon-posts-grid'; }
	public function get_categories() { return array( 'general' ); }
	public function get_style_depends() { return array( 'bsc-tv' ); }
	public function get_script_depends() { return array( 'bsc-tv' ); }
	protected function is_dynamic_content(): bool { return true; }
	protected function compact() { return false; }

	protected function register_controls() {
		$menu = $this->compact();
		$this->start_controls_section( 'content', array( 'label' => 'Programme' ) );
		$this->add_control( 'title', array( 'label' => 'Titre', 'type' => Controls_Manager::TEXT, 'default' => $menu ? 'BSC TV' : 'Programme TV' ) );
		$this->add_control( 'days', array( 'label' => 'Période (jours)', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => BSC_TV_Display::MAX_DAYS, 'default' => $menu ? 7 : 45 ) );
		$this->add_control( 'limit', array( 'label' => 'Nombre d’émissions par section', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => $menu ? 12 : 60, 'default' => $menu ? 3 : 12 ) );
		$this->add_control( 'platform', array( 'label' => 'Plateforme', 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => array( '' => 'Toutes' ) + BSC_TV_Content::platforms() ) );
		$this->add_control( 'image', array( 'label' => 'Afficher les images', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'agenda', array( 'label' => 'Lien vers le programme complet', 'type' => Controls_Manager::URL, 'options' => array( 'url' ) ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style', array( 'label' => 'Mise en page et couleurs', 'tab' => Controls_Manager::TAB_STYLE ) );
		if ( ! $menu ) {
			$this->add_responsive_control( 'columns', array(
				'label' => 'Colonnes', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 6, 'default' => 3, 'tablet_default' => 2, 'mobile_default' => 1,
				'selectors' => array( '{{WRAPPER}} .bsc-tv' => '--bsc-tv-columns: {{VALUE}};' ),
			) );
			$this->add_responsive_control( 'feature_height', array(
				'label' => 'Hauteur minimale de la une', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 180, 'max' => 900 ) ),
				'selectors' => array( '{{WRAPPER}} .bsc-tv' => '--bsc-tv-feature: {{SIZE}}{{UNIT}};' ),
			) );
		}
		foreach ( array( 'gap' => 'Espacement', 'radius' => 'Arrondi' ) as $key => $label ) {
			$this->add_responsive_control( $key, array(
				'label' => $label, 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 64 ) ),
				'selectors' => array( '{{WRAPPER}} .bsc-tv' => '--bsc-tv-' . $key . ': {{SIZE}}{{UNIT}};' ),
			) );
		}
		foreach ( array( 'accent' => 'Accent', 'ink' => 'Titres et texte du menu', 'surface' => 'Fond du méga-menu', 'card' => 'Fond des cartes sans image', 'card-text' => 'Texte des cartes', 'live' => 'Point rouge du direct' ) as $key => $label ) {
			$this->add_control( 'color_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .bsc-tv' => '--bsc-tv-' . $key . ': {{VALUE}};' ) ) );
		}
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'section_typography', 'label' => 'Titre du widget', 'selector' => '{{WRAPPER}} .bsc-tv-heading' ) );
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'card_typography', 'label' => 'Titres des émissions', 'selector' => '{{WRAPPER}} .bsc-tv-card__title' ) );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$settings['format'] = $this->compact() ? 'menu' : 'programme';
		$settings['agenda'] = $settings['agenda']['url'] ?? '';
		$settings['image'] = 'yes' === ( $settings['image'] ?? '' );
		echo BSC_TV_Display::render( $settings ); // The rendering method escapes the output.
	}
}

final class BSC_TV_Menu_Widget extends BSC_TV_Programme_Widget {
	public function get_name() { return 'bsc-tv-mega-menu'; }
	public function get_title() { return 'BSC TV – Méga-menu'; }
	public function get_icon() { return 'eicon-menu-bar'; }
	protected function compact() { return true; }
}
