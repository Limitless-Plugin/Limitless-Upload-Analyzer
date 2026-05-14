<?php
/**
 * WooCommerce cart and order metadata.
 *
 * @package LimitlessArtworkAnalyzer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves analyzed artwork data into cart and order items.
 */
class LAA_Cart_Meta {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
	}

	/**
	 * Attach analysis results to the cart item.
	 *
	 * @param array $cart_item_data Existing cart data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( empty( $_POST['laa_analysis_token'] ) ) {
			return $cart_item_data;
		}

		$nonce = isset( $_POST['laa_cart_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['laa_cart_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'laa_add_to_cart' ) ) {
			return $cart_item_data;
		}

		$token    = sanitize_text_field( wp_unslash( $_POST['laa_analysis_token'] ) );
		$analysis = get_transient( 'laa_analysis_' . $token );

		if ( ! is_array( $analysis ) ) {
			return $cart_item_data;
		}

		$expected_product_id = isset( $analysis['product_id'] ) ? absint( $analysis['product_id'] ) : 0;

		if ( $expected_product_id && $expected_product_id !== absint( $product_id ) ) {
			return $cart_item_data;
		}

		$cart_item_data['limitless_artwork_analyzer'] = $this->normalize_analysis_for_storage( $analysis );

		// Make two cart lines unique when the same product has different artwork.
		$cart_item_data['laa_unique_key'] = md5( $token . microtime() );

		return $cart_item_data;
	}

	/**
	 * Display artwork details in cart and checkout.
	 *
	 * @param array $item_data Existing display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['limitless_artwork_analyzer'] ) || ! is_array( $cart_item['limitless_artwork_analyzer'] ) ) {
			return $item_data;
		}

		$data = $cart_item['limitless_artwork_analyzer'];

		$item_data[] = array(
			'key'   => __( 'Artwork File', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['original_file_name'] ),
		);

		$item_data[] = array(
			'key'   => __( 'Pixel Size', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['pixel_width'] . ' x ' . $data['pixel_height'] . ' px' ),
		);

		$item_data[] = array(
			'key'   => __( 'Print Size', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['width_inches'] . '" x ' . $data['height_inches'] . '"' ),
		);

		$item_data[] = array(
			'key'   => __( 'DPI Used', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['dpi_used'] . ( ! empty( $data['dpi_assumed'] ) ? ' (assumed)' : '' ) ),
		);

		$item_data[] = array(
			'key'   => __( 'Quality', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['quality_rating'] ),
		);

		$item_data[] = array(
			'key'   => __( 'Transparent Background', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->yes_no( ! empty( $data['transparent_background'] ) ) ),
		);

		$item_data[] = array(
			'key'   => __( 'Has Transparency', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->yes_no( ! empty( $data['has_transparency'] ) ) ),
		);

		$item_data[] = array(
			'key'   => __( 'Semi-Transparent Pixels', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->yes_no( ! empty( $data['semi_transparent_pixels_detected'] ) ) ),
		);

		$item_data[] = array(
			'key'   => __( 'Long File Warning', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->yes_no( ! empty( $data['long_file_warning'] ) ) ),
		);

		if ( ! empty( $data['file_url'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Uploaded PNG', 'limitless-artwork-analyzer' ),
				'value' => wp_kses_post(
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( $data['file_url'] ),
						esc_html__( 'View uploaded file', 'limitless-artwork-analyzer' )
					)
				),
			);
		}

		return $item_data;
	}

	/**
	 * Save analysis details to the WooCommerce order item.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['limitless_artwork_analyzer'] ) || ! is_array( $values['limitless_artwork_analyzer'] ) ) {
			return;
		}

		$data = $values['limitless_artwork_analyzer'];

		$item->add_meta_data( __( 'Artwork File Name', 'limitless-artwork-analyzer' ), $data['original_file_name'], true );
		$item->add_meta_data( __( 'Artwork Pixel Width', 'limitless-artwork-analyzer' ), $data['pixel_width'], true );
		$item->add_meta_data( __( 'Artwork Pixel Height', 'limitless-artwork-analyzer' ), $data['pixel_height'], true );
		$item->add_meta_data( __( 'Artwork DPI Used', 'limitless-artwork-analyzer' ), $data['dpi_used'], true );
		$item->add_meta_data( __( 'Artwork Width Inches', 'limitless-artwork-analyzer' ), $data['width_inches'], true );
		$item->add_meta_data( __( 'Artwork Height Inches', 'limitless-artwork-analyzer' ), $data['height_inches'], true );
		$item->add_meta_data( __( 'Artwork Quality Rating', 'limitless-artwork-analyzer' ), $data['quality_rating'], true );
		$item->add_meta_data( __( 'Artwork Has Transparency', 'limitless-artwork-analyzer' ), $this->yes_no( ! empty( $data['has_transparency'] ) ), true );
		$item->add_meta_data( __( 'Artwork Transparent Background', 'limitless-artwork-analyzer' ), $this->yes_no( ! empty( $data['transparent_background'] ) ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Pixels', 'limitless-artwork-analyzer' ), $this->yes_no( ! empty( $data['semi_transparent_pixels_detected'] ) ), true );
		$item->add_meta_data( __( 'Artwork Long File Warning', 'limitless-artwork-analyzer' ), $this->yes_no( ! empty( $data['long_file_warning'] ) ), true );
		$item->add_meta_data( __( 'Artwork File URL', 'limitless-artwork-analyzer' ), $data['file_url'], true );
		$item->add_meta_data( __( 'Artwork File Path', 'limitless-artwork-analyzer' ), $data['file_path'], true );

		if ( ! empty( $data['warnings'] ) ) {
			$item->add_meta_data( __( 'Artwork Warnings', 'limitless-artwork-analyzer' ), implode( ' | ', $data['warnings'] ), true );
		}
	}

	/**
	 * Keep only the fields we need and sanitize before cart storage.
	 *
	 * @param array $analysis Raw analysis data.
	 * @return array
	 */
	private function normalize_analysis_for_storage( $analysis ) {
		$warnings = array();

		if ( ! empty( $analysis['warnings'] ) && is_array( $analysis['warnings'] ) ) {
			foreach ( $analysis['warnings'] as $warning ) {
				$warnings[] = sanitize_text_field( $warning );
			}
		}

		return array(
			'original_file_name'               => sanitize_file_name( $analysis['original_file_name'] ),
			'pixel_width'                      => isset( $analysis['pixel_width'] ) ? absint( $analysis['pixel_width'] ) : 0,
			'pixel_height'                     => isset( $analysis['pixel_height'] ) ? absint( $analysis['pixel_height'] ) : 0,
			'dpi_used'                         => isset( $analysis['dpi_used'] ) ? (float) $analysis['dpi_used'] : 0,
			'dpi_assumed'                      => ! empty( $analysis['dpi_assumed'] ),
			'width_inches'                     => isset( $analysis['width_inches'] ) ? (float) $analysis['width_inches'] : 0,
			'height_inches'                    => isset( $analysis['height_inches'] ) ? (float) $analysis['height_inches'] : 0,
			'quality_rating'                   => isset( $analysis['quality_rating'] ) ? sanitize_text_field( $analysis['quality_rating'] ) : '',
			'has_transparency'                 => ! empty( $analysis['has_transparency'] ),
			'transparent_background'           => ! empty( $analysis['transparent_background'] ),
			'semi_transparent_pixels_detected' => ! empty( $analysis['semi_transparent_pixels_detected'] ),
			'long_file_warning'                => ! empty( $analysis['long_file_warning'] ),
			'file_url'                         => isset( $analysis['file_url'] ) ? esc_url_raw( $analysis['file_url'] ) : '',
			'file_path'                        => isset( $analysis['file_path'] ) ? sanitize_text_field( $analysis['file_path'] ) : '',
			'warnings'                         => $warnings,
		);
	}

	/**
	 * Human yes/no value.
	 *
	 * @param bool $value Boolean-like value.
	 * @return string
	 */
	private function yes_no( $value ) {
		return $value ? __( 'Yes', 'limitless-artwork-analyzer' ) : __( 'No', 'limitless-artwork-analyzer' );
	}
}
