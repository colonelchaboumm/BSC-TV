<?php

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

final class BSC_TV_Day_Widget extends Elementor\Widget_Base {
	use BSC_TV_Widget_Style_Controls;
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
		$this->add_control( 'show_music', array( 'label' => 'Afficher les musiques programmées', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'show_music_timing', array( 'label' => 'Détailler les horaires des clips et les animations BSC', 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes', 'condition' => array( 'show_music' => 'yes' ) ) );
		$this->add_control( 'music_label', array( 'label' => 'Libellé des interludes musicaux', 'type' => Controls_Manager::TEXT, 'default' => 'MUSIC BREAK', 'condition' => array( 'show_music' => 'yes' ) ) );
		$this->add_control( 'time_precision', array( 'label' => 'Précision des horaires', 'type' => Controls_Manager::SELECT, 'default' => 'auto', 'options' => array( 'auto' => 'Secondes pour la musique, minutes pour les émissions', 'seconds' => 'Toujours afficher les secondes', 'minutes' => 'Toujours afficher les minutes' ) ) );
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
		$this->add_control( 'day_color_music', array( 'label' => 'Badge et repère musical', 'type' => Controls_Manager::COLOR, 'selectors' => array( $root => '--bsc-day-music: {{VALUE}};' ) ) );
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
		$this->bsc_dimensions( 'day_container_radius', 'Arrondis du widget', $root, 'border-radius', array( 'px', '%' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'day_rows_style', array( 'label' => 'Lignes des programmes', 'tab' => Controls_Manager::TAB_STYLE ) );
		$row = $root . ' .bsc-day__item';
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'day_row_border', 'selector' => $row ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'day_row_shadow', 'selector' => $row ) );
		$this->bsc_color( 'day_row_hover_background', 'Fond au survol et au focus', $row . ':is(:hover, :focus-within)', 'background-color' );
		$this->bsc_color( 'day_row_hover_border', 'Contour au survol et au focus', $row . ':is(:hover, :focus-within)', 'border-color' );
		$current = $row . '.bsc-day__item--current';
		$this->add_control( 'day_current_heading', array( 'label' => 'Programme en cours', 'type' => Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'day_current_border', 'selector' => $current ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'day_current_shadow', 'selector' => $current ) );
		$this->bsc_dimensions( 'day_current_radius', 'Arrondis du programme en cours', $current, 'border-radius', array( 'px', '%' ) );
		$this->bsc_color( 'day_current_title_color', 'Titre du programme en cours', $current . ' .bsc-day__title', 'color' );
		$this->end_controls_section();

		$this->start_controls_section( 'day_images_style', array( 'label' => 'Vignettes et icône de remplacement', 'tab' => Controls_Manager::TAB_STYLE ) );
		$image = $root . ' .bsc-day__image';
		$this->bsc_color( 'day_image_background', 'Fond des vignettes', $image, 'background-color' );
		$this->bsc_color( 'day_image_icon_color', 'Couleur de l’icône sans image', $image . ' span', 'color' );
		$this->add_responsive_control( 'day_image_icon_size', array( 'label' => 'Taille de l’icône', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 10, 'max' => 80 ) ), 'selectors' => array( $image . ' span' => 'font-size: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'day_image_ratio', array( 'label' => 'Format des vignettes', 'type' => Controls_Manager::SELECT, 'options' => array( '16 / 9' => '16:9', '4 / 3' => '4:3', '1 / 1' => 'Carré' ), 'selectors' => array( $image => 'aspect-ratio: {{VALUE}};' ) ) );
		$this->add_control( 'day_image_fit', array( 'label' => 'Cadrage des images', 'type' => Controls_Manager::SELECT, 'options' => array( 'cover' => 'Remplir', 'contain' => 'Image entière' ), 'selectors' => array( $image . ' img' => 'object-fit: {{VALUE}};' ) ) );
		$this->bsc_dimensions( 'day_image_radius', 'Arrondis', $image, 'border-radius', array( 'px', '%' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'day_image_border', 'selector' => $image ) );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), array( 'name' => 'day_image_shadow', 'selector' => $image ) );
		$this->end_controls_section();

		$this->start_controls_section( 'day_links_style', array( 'label' => 'Textes et liens', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'heading' => 'Titre du widget', 'title' => 'Titre de l’émission sans lien', 'date' => 'Date et fuseau', 'channel' => 'Chaîne', 'creator' => 'Créateur sans lien', 'times' => 'Heure de fin', 'note' => 'Informations et musique' ) as $key => $label ) {
			$this->bsc_color( 'day_text_' . $key, $label, $root . ' .bsc-day__' . $key, 'color' );
		}
		$this->bsc_color( 'day_text_time_start', 'Heure de début', $root . ' .bsc-day__times time', 'color' );
		foreach ( array( 'title' => 'Titre de l’émission', 'creator' => 'Créateur BSC BDD' ) as $key => $label ) {
			$this->bsc_color( 'day_link_' . $key . '_color', $label . ' · normal', $root . ' .bsc-day__' . $key . ' a', 'color' );
			$this->bsc_color( 'day_link_' . $key . '_hover', $label . ' · survol et focus', $root . ' .bsc-day__' . $key . ' a:is(:hover, :focus-visible)', 'color' );
		}
		$this->end_controls_section();

		$this->start_controls_section( 'day_badges_style', array( 'label' => 'Badges et point LIVE', 'tab' => Controls_Manager::TAB_STYLE ) );
		$badge = $root . ' .bsc-day__badge';
		foreach ( array( 'current' => 'En cours', 'live' => 'LIVE', 'future' => 'À venir', 'past' => 'Diffusé', 'music' => 'MUSIC BREAK' ) as $key => $label ) {
			$this->bsc_color( 'day_badge_' . $key . '_color', $label . ' · texte', $badge . '--' . $key, 'color' );
			$this->bsc_color( 'day_badge_' . $key . '_background', $label . ' · fond', $badge . '--' . $key, 'background-color' );
		}
		$this->bsc_dimensions( 'day_badge_padding', 'Marges intérieures', $badge, 'padding' );
		$this->bsc_dimensions( 'day_badge_radius', 'Arrondis', $badge, 'border-radius', array( 'px', '%' ) );
		$this->add_group_control( Group_Control_Border::get_type(), array( 'name' => 'day_badge_border', 'selector' => $badge ) );
		$this->bsc_color( 'day_live_dot_color', 'Couleur du point LIVE', $badge . '--live::before', 'background-color' );
		$this->add_responsive_control( 'day_live_dot_size', array( 'label' => 'Taille du point LIVE', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 3, 'max' => 24 ) ), 'selectors' => array( $badge . '--live::before' => 'inline-size: {{SIZE}}{{UNIT}}; block-size: {{SIZE}}{{UNIT}};' ) ) );
		$this->end_controls_section();

		$this->bsc_button_style( 'day_more_button', 'Bouton Afficher plus', $root . ' .bsc-day__more', '--bsc-day-button' );
		$this->start_controls_section( 'day_more_layout', array( 'label' => 'Position du bouton Afficher plus', 'tab' => Controls_Manager::TAB_STYLE ) );
		$this->add_responsive_control( 'day_more_align', array( 'label' => 'Alignement', 'type' => Controls_Manager::SELECT, 'options' => array( 'left' => 'À gauche', 'center' => 'Centré', 'right' => 'À droite' ), 'selectors_dictionary' => array( 'left' => '0 auto 0 0', 'center' => '0 auto', 'right' => '0 0 0 auto' ), 'selectors' => array( $root . ' .bsc-day__more' => 'margin: {{VALUE}}; margin-block-start: var(--bsc-day-more-gap, 16px);' ) ) );
		$this->add_responsive_control( 'day_more_gap', array( 'label' => 'Espace avant le bouton', 'type' => Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 80 ) ), 'selectors' => array( $root => '--bsc-day-more-gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'day_more_width', array( 'label' => 'Largeur du bouton', 'type' => Controls_Manager::SLIDER, 'size_units' => array( 'px', '%' ), 'range' => array( 'px' => array( 'min' => 80, 'max' => 700 ), '%' => array( 'min' => 10, 'max' => 100 ) ), 'selectors' => array( $root . ' .bsc-day__more' => 'width: {{SIZE}}{{UNIT}}; max-width: 100%;' ) ) );
		$this->end_controls_section();

		$this->start_controls_section( 'day_typography', array( 'label' => 'Typographies', 'tab' => Controls_Manager::TAB_STYLE ) );
		foreach ( array( 'heading' => 'Titre du widget', 'date' => 'Date du jour', 'title' => 'Titres des émissions', 'times' => 'Horaires', 'channel' => 'Chaînes', 'creator' => 'Créateurs', 'badge' => 'Badges', 'more' => 'Bouton Afficher plus' ) as $key => $label ) {
			$selector = $root . ' .bsc-day__' . $key;
			if ( 'times' === $key ) { $selector .= ', ' . $root . ' .bsc-day__times time'; }
			$this->add_group_control( Group_Control_Typography::get_type(), array( 'name' => 'day_' . $key . '_typography', 'label' => $label, 'selector' => $selector ) );
		}
		$this->end_controls_section();
	}

	protected function render() {
		echo BSC_TV_Day::render( $this->get_settings_for_display() ); // Escaped by day-view.php.
	}
}
