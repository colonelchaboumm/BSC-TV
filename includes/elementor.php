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
	protected function badge() { return false; }

	protected function register_controls() {
		$menu = $this->compact();
		$badge = $this->badge();
		$this->start_controls_section( 'content', array( 'label' => 'Programme' ) );
		if ( $badge ) { $this->add_control( 'badge_stations', array( 'label' => 'Chaînes affichées', 'type' => Controls_Manager::SELECT, 'options' => array( 'both' => 'World et France', 'single' => 'Une seule chaîne' ), 'default' => 'both' ) ); }
		$station_control = array( 'label' => 'Chaîne TV', 'type' => Controls_Manager::SELECT, 'options' => BSC_TV_Stations::choices(), 'default' => 'main' );
		if ( $badge ) { $station_control['condition'] = array( 'badge_stations' => 'single' ); }
		$this->add_control( 'station', $station_control );
		$this->add_control( 'title', array( 'label' => $badge ? 'Titre du panneau' : 'Titre', 'type' => Controls_Manager::TEXT, 'default' => $menu && ! $badge ? 'BSC TV' : 'Programme TV' ) );
		$this->add_control( 'days', array( 'label' => 'Période (jours)', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => BSC_TV_Display::MAX_DAYS, 'default' => $menu && ! $badge ? 7 : 45 ) );
		$this->add_control( 'limit', array( 'label' => 'Nombre d’émissions par section', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => $menu ? 12 : 60, 'default' => $menu ? 3 : 12 ) );
		$this->add_control( 'platform', array( 'label' => 'Plateforme', 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => array( '' => 'Toutes' ) + BSC_TV_Content::platforms() ) );
		$this->add_control( 'image', array( 'label' => 'Afficher les images', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'agenda', array( 'label' => 'Lien vers le programme complet', 'type' => Controls_Manager::URL, 'options' => array( 'url' ) ) );
		$this->end_controls_section();
		if ( $badge ) {
			$this->badge_content_controls();
		}

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
		if ( $badge ) {
			$this->badge_style_controls();
		}
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$settings['format'] = $this->badge() ? 'badge' : ( $this->compact() ? 'menu' : 'programme' );
		$settings['agenda'] = $settings['agenda']['url'] ?? '';
		$settings['image'] = 'yes' === ( $settings['image'] ?? '' );
		echo BSC_TV_Display::render( $settings ); // The rendering method escapes the output.
	}

	private function badge_content_controls() {
		$this->start_controls_section( 'badge_stations_identity', array( 'label' => 'Logos et pages des chaînes' ) );
		$this->add_control( 'show_station_logo', array( 'label' => 'Afficher les logos', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'show_station_name', array( 'label' => 'Afficher le nom de la chaîne', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		foreach ( array( 'world' => 'main', 'fr' => 'fr' ) as $prefix => $id ) {
			$brand = BSC_TV_Stations::brand( $id );
			$this->add_control( $prefix . '_logo', array( 'label' => 'Logo ' . $brand['name'], 'type' => Controls_Manager::MEDIA, 'default' => array( 'url' => $brand['logo'] ), 'condition' => array( 'show_station_logo' => 'yes' ) ) );
			$this->add_control( $prefix . '_watch_url', array( 'label' => 'Page ' . $brand['name'], 'type' => Controls_Manager::URL, 'options' => array( 'url' ), 'default' => array( 'url' => $brand['url'] ), 'condition' => array( 'badge_stations' => 'both' ) ) );
		}
		$this->end_controls_section();
		$this->start_controls_section( 'badge_content', array( 'label' => 'Bouton et ouverture' ) );
		foreach ( array( 'live' => array( 'Libellé en direct', 'LIVE' ), 'upcoming' => array( 'Libellé de la prochaine émission', 'À venir' ), 'empty' => array( 'Libellé sans émission', 'BSC TV' ) ) as $key => $label ) {
			$this->add_control( $key . '_label', array( 'label' => $label[0], 'type' => Controls_Manager::TEXT, 'default' => $label[1] ) );
		}
		foreach ( array( 'show_highlight_title' => 'Titre de l’émission dans le bouton', 'show_highlight_date' => 'Date et heure dans le bouton', 'pulse' => 'Animer le point du direct' ) as $key => $label ) {
			$this->add_control( $key, array( 'label' => $label, 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		}
		$this->add_control( 'panel_mode', array( 'label' => 'Ouverture', 'type' => Controls_Manager::SELECT, 'default' => 'dropdown', 'options' => array( 'dropdown' => 'Panneau déroulant', 'inline' => 'Déplier sous le bouton' ) ) );
		$this->add_control( 'show_player_current', array( 'label' => 'Afficher l’émission BSC TV en cours', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes', 'description' => 'Priorité à l’émission diffusée dans Le live, y compris un replay. Sans programme en cours, reprend les directs et annonces des plateformes.' ) );
		$this->add_control( 'show_watch_link', array( 'label' => 'Bouton pour regarder Le live', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'watch_url', array( 'label' => 'Lien vers la page Le live', 'type' => Controls_Manager::URL, 'options' => array( 'url' ), 'default' => array( 'url' => BSC_TV_Display::LIVE_PAGE ), 'condition' => array( 'badge_stations' => 'single' ) ) );
		$this->add_control( 'watch_label', array( 'label' => 'Texte du bouton Le live', 'type' => Controls_Manager::TEXT, 'default' => 'Regarder Le live' ) );
		$this->end_controls_section();
	}

	private function badge_style_controls() {
		$root = '{{WRAPPER}} .bsc-tv--badge';
		$this->start_controls_section( 'badge_station_style', array( 'label' => 'Logos et disposition des chaînes', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_responsive_control( 'badge_stations_direction', array( 'label' => 'Disposition', 'type' => Controls_Manager::SELECT, 'options' => array( '320px' => 'Côte à côte si la place le permet', '100%' => 'L’une sous l’autre' ), 'selectors' => array( '{{WRAPPER}} .bsc-tv-stations' => '--bsc-tv-station-min: {{VALUE}};' ) ) );
		$this->add_responsive_control( 'badge_stations_gap', array( 'label' => 'Espace entre les chaînes', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 80 ) ), 'selectors' => array( '{{WRAPPER}} .bsc-tv-stations' => '--bsc-tv-stations-gap: {{SIZE}}{{UNIT}};' ) ) );
		foreach ( array( 'logo-size' => array( 'Taille du logo', 24, 180 ), 'logo-gap' => array( 'Espace logo / programme', 0, 48 ), 'logo-radius' => array( 'Arrondi du logo', 0, 90 ), 'logo-padding' => array( 'Marge intérieure du logo', 0, 24 ), 'logo-border-width' => array( 'Épaisseur du contour du logo', 0, 10 ) ) as $key => $control ) { $this->badge_slider( $root, $key, $control[0], $control[1], $control[2] ); }
		foreach ( array( 'logo-background' => 'Fond du logo', 'logo-border' => 'Contour du logo', 'station-name' => 'Nom de la chaîne' ) as $key => $label ) { $this->add_control( 'badge_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-tv-badge-' . $key . ': {{VALUE}};' ) ) ); }
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'badge_station_name_typography', 'label' => 'Nom de la chaîne', 'selector' => $root . ' .bsc-tv-badge__station-name' ) );
		$this->end_controls_section();
		$this->start_controls_section( 'badge_style', array( 'label' => 'Bouton et pastille', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_responsive_control( 'badge_alignment', array(
			'label' => 'Alignement du bouton', 'type' => Controls_Manager::SELECT,
			'options' => array( 'start' => 'Début', 'center' => 'Centre', 'end' => 'Fin' ),
			'selectors' => array( $root => '--bsc-tv-badge-align: {{VALUE}};' ),
		) );
		foreach ( array( 'button-background' => 'Fond du bouton', 'button-text' => 'Texte du bouton', 'button-border' => 'Bordure du bouton', 'live-background' => 'Fond de la pastille LIVE', 'live-text' => 'Texte LIVE', 'next-background' => 'Fond de la pastille à venir', 'next-text' => 'Texte à venir' ) as $key => $label ) {
			$this->add_control( 'badge_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-tv-badge-' . $key . ': {{VALUE}};' ) ) );
		}
		foreach ( array( 'dot' => array( 'Taille du point', 4, 20 ), 'button-radius' => array( 'Arrondi du bouton', 0, 60 ), 'button-gap' => array( 'Espacement du bouton', 4, 32 ) ) as $key => $control ) {
			$this->badge_slider( $root, $key, $control[0], $control[1], $control[2] );
		}
		$this->add_responsive_control( 'badge_button_padding', array(
			'label' => 'Marges intérieures du bouton', 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ),
			'selectors' => array( $root . ' .bsc-tv-badge__trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );
		foreach ( array( 'label' => 'Typographie des libellés', 'title' => 'Typographie du titre', 'date' => 'Typographie de la date', 'watch' => 'Bouton Regarder Le live' ) as $key => $label ) {
			$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'badge_' . $key . '_typography', 'label' => $label, 'selector' => $root . ' .bsc-tv-badge__' . $key ) );
		}
		$this->end_controls_section();
		$this->start_controls_section( 'badge_panel', array( 'label' => 'Panneau déroulant', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->badge_slider( $root, 'panel-width', 'Largeur', 240, 680 );
		$this->badge_slider( $root, 'panel-height', 'Hauteur maximale', 160, 900 );
		$this->badge_slider( $root, 'panel-radius', 'Arrondi', 0, 48 );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'badge_panel_shadow', 'label' => 'Ombre', 'selector' => $root . ' .bsc-tv-badge__panel' ) );
		$this->end_controls_section();
	}

	private function badge_slider( $root, $key, $label, $min, $max ) {
		$this->add_responsive_control( 'badge_' . $key, array(
			'label' => $label, 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $min, 'max' => $max ) ),
			'selectors' => array( $root => '--bsc-tv-badge-' . $key . ': {{SIZE}}{{UNIT}};' ),
		) );
	}
}

final class BSC_TV_Menu_Widget extends BSC_TV_Programme_Widget {
	public function get_name() { return 'bsc-tv-mega-menu'; }
	public function get_title() { return 'BSC TV – Méga-menu'; }
	public function get_icon() { return 'eicon-menu-bar'; }
	protected function compact() { return true; }
}

final class BSC_TV_Badge_Widget extends BSC_TV_Programme_Widget {
	public function get_name() { return 'bsc-tv-badge'; }
	public function get_title() { return 'BSC TV – Pastille Live'; }
	public function get_icon() { return 'eicon-play-o'; }
	protected function compact() { return true; }
	protected function badge() { return true; }
}

final class BSC_TV_Guide_Widget extends Elementor\Widget_Base {
	public function get_name() { return 'bsc-tv-guide'; }
	public function get_title() { return 'BSC TV – Grille TV'; }
	public function get_icon() { return 'eicon-calendar'; }
	public function get_categories() { return array( 'general' ); }
	public function get_style_depends() { return array( 'bsc-tv-guide' ); }
	public function get_script_depends() { return array( 'bsc-tv-guide' ); }
	protected function is_dynamic_content(): bool { return true; }

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => 'Grille TV' ) );
		$this->add_control( 'station', array( 'label' => 'Chaîne TV', 'type' => Controls_Manager::SELECT, 'options' => BSC_TV_Stations::choices(), 'default' => 'main', 'description' => 'La pastille suit l’antenne sélectionnée. Renseignez le lien de sa page de lecture.' ) );
		$this->add_control( 'title', array( 'label' => 'Titre', 'type' => Controls_Manager::TEXT, 'default' => 'Programme TV' ) );
		$this->add_control( 'days', array( 'label' => 'Période consultable (jours)', 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => BSC_TV_Display::MAX_DAYS, 'default' => 45 ) );
		$this->add_control( 'platform', array( 'label' => 'Plateforme', 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => array( '' => 'Toutes' ) + BSC_TV_Content::platforms() ) );
		$this->add_control( 'image', array( 'label' => 'Vignettes des émissions', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style', array( 'label' => 'Mise en page et couleurs', 'tab' => Controls_Manager::TAB_STYLE ) );
		$root = '{{WRAPPER}} .bsc-guide';
		foreach ( array( 'channel-width' => array( 'Largeur de la colonne des chaînes', 80, 300 ), 'hour-width' => array( 'Largeur d’une heure', 140, 360 ), 'logo-size' => array( 'Taille des logos', 20, 64 ), 'image-width' => array( 'Largeur des vignettes', 32, 160 ), 'radius' => array( 'Arrondi des angles', 0, 24 ) ) as $key => list( $label, $min, $max ) ) {
			$this->add_responsive_control( 'guide_' . $key, array(
				'label' => $label, 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $min, 'max' => $max ) ),
				'selectors' => array( $root => '--bsc-guide-' . $key . ': {{SIZE}}{{UNIT}};' ),
			) );
		}
		foreach ( array( 'surface' => 'Fond de la grille', 'card' => 'Fond des émissions', 'ink' => 'Titres', 'muted' => 'Horaires et libellés', 'line' => 'Traits de la grille', 'accent' => 'Accent', 'live' => 'Indicateur LIVE' ) as $key => $label ) {
			$this->add_control( 'color_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-guide-' . $key . ': {{VALUE}};' ) ) );
		}
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'guide_title_typography', 'label' => 'Titre du widget', 'selector' => $root . ' .bsc-guide__title' ) );
		$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'guide_show_typography', 'label' => 'Titres des émissions', 'selector' => $root . ' .bsc-guide__programme-title' ) );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$settings['format'] = 'guide';
		$settings['image'] = 'yes' === ( $settings['image'] ?? '' );
		echo BSC_TV_Display::render( $settings ); // The rendering method escapes the output.
	}
}
