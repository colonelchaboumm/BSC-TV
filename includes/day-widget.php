<?php

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

final class BSC_TV_Day_Widget extends Elementor\Widget_Base {
	public function get_name() { return 'bsc-tv-day'; }
	public function get_title() { return 'BSC TV — Programme du jour (liste)'; }
	public function get_icon() { return 'eicon-post-list'; }
	public function get_categories() { return array( 'general' ); }
	public function get_style_depends() { return array( 'bsc-tv-day' ); }
	public function get_script_depends() { return array( 'bsc-tv-day' ); }
	protected function is_dynamic_content(): bool { return true; }

	protected function register_controls() {
		$root = '{{WRAPPER}} .bsc-day';
		$this->start_controls_section( 'day_content', array( 'label' => 'Programme de la journée' ) );
		$this->add_control( 'title', array( 'label' => 'Titre du widget', 'type' => Controls_Manager::TEXT, 'default' => 'Au programme aujourd’hui' ) );
		$this->add_control( 'page_size', array( 'label' => 'Programmes par chargement', 'type' => Controls_Manager::NUMBER, 'default' => 5, 'min' => 1, 'max' => 50, 'description' => 'Le programme en cours reste visible en plus de cette limite.' ) );
		foreach ( array( 'show_past' => 'Programmes déjà diffusés', 'show_date' => 'Date et fuseau du site', 'show_times' => 'Horaires', 'show_images' => 'Vignettes des émissions', 'show_channel' => 'Nom de la chaîne', 'show_creator' => 'Nom du créateur', 'show_profile' => 'Lien vers sa fiche BSC BDD', 'show_badges' => 'État de diffusion et point LIVE' ) as $key => $label ) {
			$this->add_control( $key, array( 'label' => $label, 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'show_past' === $key ? '' : 'yes' ) );
		}
		$this->add_control( 'link_mode', array( 'label' => 'Lien sur le titre de l’émission', 'type' => Controls_Manager::SELECT, 'default' => 'source', 'options' => array( 'source' => 'Plateforme d’origine', 'watch' => 'Page du live sur votre site', 'none' => 'Aucun lien' ) ) );
		$this->add_control( 'watch_url', array( 'label' => 'Page du live', 'type' => Controls_Manager::URL, 'condition' => array( 'link_mode' => 'watch' ) ) );
		foreach ( array( 'current_label' => 'En cours', 'live_label' => 'LIVE', 'past_label' => 'Diffusé', 'future_label' => 'À venir', 'more_label' => 'Afficher plus', 'empty_label' => 'Aucun programme prévu aujourd’hui.', 'disabled_label' => 'Le lecteur est désactivé.' ) as $key => $value ) {
			$this->add_control( $key, array( 'label' => $value, 'type' => Controls_Manager::TEXT, 'default' => $value ) );
		}
		$this->add_control( 'day_help', array( 'type' => Controls_Manager::RAW_HTML, 'raw' => 'Cette liste suit BSC TV → Le live dans le fuseau du site. Elle s’actualise automatiquement sans rechercher de nouvelles vidéos.', 'content_classes' => 'elementor-panel-alert elementor-panel-alert-info' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'day_style', array( 'label' => 'Liste et couleurs', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'surface' => 'Fond du widget', 'row' => 'Fond des lignes', 'current' => 'Fond du programme en cours', 'ink' => 'Titres', 'muted' => 'Horaires et informations', 'accent' => 'Liens et programme en cours', 'line' => 'Séparateurs', 'live' => 'Point et badge LIVE' ) as $key => $label ) {
			$this->add_control( 'day_color_' . $key, array( 'label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-day-' . $key . ': {{VALUE}};' ) ) );
		}
		foreach ( array( 'gap' => array( 'Espacement des lignes', 0, 48 ), 'row_gap' => array( 'Espacement dans une ligne', 0, 48 ), 'image_width' => array( 'Largeur des vignettes', 40, 240 ), 'time_width' => array( 'Largeur des horaires', 45, 160 ), 'radius' => array( 'Arrondi des lignes', 0, 40 ) ) as $name => $values ) {
			$this->add_responsive_control( 'day_' . $name, array( 'label' => $values[0], 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => $values[1], 'max' => $values[2] ) ), 'selectors' => array( $root => '--bsc-day-' . str_replace( '_', '-', $name ) . ': {{SIZE}}{{UNIT}};' ) ) );
		}
		foreach ( array( 'padding' => 'Marges du widget', 'row_padding' => 'Marges des lignes' ) as $name => $label ) {
			$this->add_responsive_control( 'day_' . $name, array( 'label' => $label, 'type' => Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', '%' ), 'selectors' => array( 'row_padding' === $name ? $root . ' .bsc-day__item' : $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		}
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'day_border', 'selector' => $root ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'day_shadow', 'selector' => $root ) );
		$this->end_controls_section();

		$this->start_controls_section( 'day_typography', array( 'label' => 'Typographies', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'heading' => 'Titre du widget', 'date' => 'Date du jour', 'title' => 'Titres des émissions', 'times' => 'Horaires', 'channel' => 'Chaînes', 'creator' => 'Créateurs', 'badge' => 'Badges', 'more' => 'Bouton Afficher plus' ) as $key => $label ) {
			$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'day_' . $key . '_typography', 'label' => $label, 'selector' => $root . ' .bsc-day__' . $key ) );
		}
		$this->end_controls_section();
	}

	protected function render() {
		echo BSC_TV_Day::render( $this->get_settings_for_display() ); // Escaped by day-view.php.
	}
}
