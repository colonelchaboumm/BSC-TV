<?php

defined( 'ABSPATH' ) || exit;

/** A list view of the same day that drives Le live, with no platform API requests. */
final class BSC_TV_Day {
	public static function register() {
		wp_register_style( 'bsc-tv-day', BSC_TV_URL . 'assets/day.css', array(), BSC_TV_VERSION );
		wp_register_script( 'bsc-tv-day', BSC_TV_URL . 'assets/day.js', array(), BSC_TV_VERSION, true );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_shortcode( 'bsc_tv_day', static function ( $attributes ) { return self::render( (array) $attributes ); } );
	}

	public static function config( $input ) {
		$input = (array) $input;
		$result = array();
		foreach ( array( 'show_past', 'show_date', 'show_times', 'show_images', 'show_channel', 'show_creator', 'show_profile', 'show_badges' ) as $key ) {
			$result[ $key ] = ! isset( $input[ $key ] ) || in_array( $input[ $key ], array( 'yes', '1', 1, true ), true ) ? 'yes' : 'no';
		}
		foreach ( array( 'current_label' => 'En cours', 'live_label' => 'LIVE', 'past_label' => 'Diffusé', 'future_label' => 'À venir', 'empty_label' => 'Aucun programme prévu aujourd’hui.', 'disabled_label' => 'Le lecteur est désactivé.' ) as $key => $default ) {
			$result[ $key ] = is_scalar( $input[ $key ] ?? null ) ? wp_html_excerpt( sanitize_text_field( $input[ $key ] ), 200, '' ) : $default;
		}
		$result['link_mode'] = in_array( $input['link_mode'] ?? '', array( 'source', 'watch', 'none' ), true ) ? $input['link_mode'] : 'source';
		$watch = $input['watch_url'] ?? '';
		$value = is_array( $watch ) ? ( $watch['url'] ?? '' ) : $watch;
		$result['watch_url'] = is_scalar( $value ) ? BSC_TV_Content::url( $value ) : '';
		$result['watch_external'] = ! empty( is_array( $watch ) ? ( $watch['is_external'] ?? false ) : ( $input['watch_external'] ?? false ) ) ? 1 : 0;
		return $result;
	}

	public static function programme( $config, $now = null ) {
		$data = BSC_TV_Player::day_snapshot( $now );
		if ( 'yes' !== $config['show_past'] ) { $data['items'] = array_values( array_filter( $data['items'], static function ( $item ) { return 'past' !== $item['phase']; } ) ); }
		ob_start();
		require BSC_TV_DIR . 'includes/day-view.php';
		return array( 'html' => ob_get_clean(), 'date' => $data['date'], 'server_time' => $data['server_time'], 'poll_after' => $data['poll_after'], 'enabled' => $data['enabled'] );
	}

	public static function rest() {
		$args = array();
		foreach ( self::config( array() ) as $name => $value ) { $args[ $name ] = array( 'type' => is_int( $value ) ? 'integer' : 'string' ); }
		register_rest_route( 'bsc-tv/v1', '/day', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'args' => $args, 'callback' => static function ( $request ) {
			$response = new WP_REST_Response( self::programme( self::config( $request->get_query_params() ) ) );
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			return $response;
		} ) );
	}

	public static function render( $settings = array() ) {
		$settings = (array) $settings;
		wp_enqueue_style( 'bsc-tv-day' );
		wp_enqueue_script( 'bsc-tv-day' );
		$config = self::config( $settings );
		$programme = self::programme( $config );
		$title = is_scalar( $settings['title'] ?? null ) ? sanitize_text_field( $settings['title'] ) : 'Au programme aujourd’hui';
		$url = add_query_arg( $config, rest_url( 'bsc-tv/v1/day' ) );
		ob_start();
		?>
		<section class="bsc-day" data-bsc-day="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $title ?: 'Programme TV du jour' ); ?>">
			<?php if ( $title ) : ?><h2 class="bsc-day__heading"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
			<div data-day-content><?php echo $programme['html']; // Escaped by day-view.php. ?></div>
			<p class="bsc-day__status" role="status" data-day-status></p>
		</section>
		<?php
		return ob_get_clean();
	}
}
