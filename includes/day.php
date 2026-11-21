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
			$result[ $key ] = in_array( $input[ $key ] ?? ( 'show_past' === $key ? 'no' : 'yes' ), array( 'yes', '1', 1, true ), true ) ? 'yes' : 'no';
		}
		$result['page_size'] = is_numeric( $input['page_size'] ?? null ) ? max( 1, min( 50, (int) $input['page_size'] ) ) : 5;
		foreach ( array( 'current_label' => 'En cours', 'live_label' => 'LIVE', 'past_label' => 'Diffusé', 'future_label' => 'À venir', 'more_label' => 'Afficher plus', 'empty_label' => 'Aucun programme prévu aujourd’hui.', 'disabled_label' => 'Le lecteur est désactivé.' ) as $key => $default ) {
			$result[ $key ] = is_scalar( $input[ $key ] ?? null ) ? wp_html_excerpt( sanitize_text_field( $input[ $key ] ), 200, '' ) : $default;
		}
		$result['link_mode'] = in_array( $input['link_mode'] ?? '', array( 'source', 'watch', 'none' ), true ) ? $input['link_mode'] : 'source';
		$watch = $input['watch_url'] ?? '';
		$value = is_array( $watch ) ? ( $watch['url'] ?? '' ) : $watch;
		$result['watch_url'] = is_scalar( $value ) ? BSC_TV_Content::url( $value ) : '';
		$result['watch_external'] = ! empty( is_array( $watch ) ? ( $watch['is_external'] ?? false ) : ( $input['watch_external'] ?? false ) ) ? 1 : 0;
		return $result;
	}

	public static function programme( $config, $now = null, $page = 1, $date = '' ) {
		$data = BSC_TV_Player::day_snapshot( $now );
		$page = $date && $date !== $data['date'] ? 1 : max( 1, min( 500, (int) $page ) );
		if ( 'yes' !== $config['show_past'] ) { $data['items'] = array_values( array_filter( $data['items'], static function ( $item ) { return 'past' !== $item['phase']; } ) ); }
		$current = array_filter( $data['items'], static function ( $item ) { return $item['on_air']; } );
		$others = array_filter( $data['items'], static function ( $item ) { return ! $item['on_air']; } );
		$limit = $page * $config['page_size'];
		$has_more = count( $others ) > $limit;
		$data['items'] = array_merge( array_values( $current ), array_slice( array_values( $others ), 0, $limit ) );
		usort( $data['items'], static function ( $a, $b ) { return $a['display_start'] <=> $b['display_start']; } );
		ob_start();
		require BSC_TV_DIR . 'includes/day-view.php';
		return array( 'html' => ob_get_clean(), 'date' => $data['date'], 'server_time' => $data['server_time'], 'poll_after' => $data['poll_after'], 'enabled' => $data['enabled'], 'page' => $page, 'has_more' => $has_more, 'count' => count( $data['items'] ) );
	}

	public static function rest() {
		$args = array();
		foreach ( self::config( array() ) as $name => $value ) { $args[ $name ] = array( 'type' => is_int( $value ) ? 'integer' : 'string' ); }
		$args['page'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 1 );
		$args['date'] = array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' );
		register_rest_route( 'bsc-tv/v1', '/day', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'args' => $args, 'callback' => static function ( $request ) {
			$response = new WP_REST_Response( self::programme( self::config( $request->get_query_params() ), null, $request['page'], $request['date'] ) );
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
		$content_id = wp_unique_id( 'bsc-day-content-' );
		ob_start();
		?>
		<section class="bsc-day" data-bsc-day="<?php echo esc_url( $url ); ?>" data-day-date="<?php echo esc_attr( $programme['date'] ); ?>" aria-label="<?php echo esc_attr( $title ?: 'Programme TV du jour' ); ?>">
			<?php if ( $title ) : ?><h2 class="bsc-day__heading"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
			<div id="<?php echo esc_attr( $content_id ); ?>" data-day-content><?php echo $programme['html']; // Escaped by day-view.php. ?></div>
			<button type="button" class="bsc-day__more" data-day-more aria-controls="<?php echo esc_attr( $content_id ); ?>"<?php echo $programme['has_more'] ? '' : ' hidden'; ?>><?php echo esc_html( $config['more_label'] ); ?></button>
			<p class="bsc-day__status" role="status" data-day-status></p>
		</section>
		<?php
		return ob_get_clean();
	}
}
