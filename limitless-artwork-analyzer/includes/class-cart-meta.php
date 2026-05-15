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
			'key'   => __( 'Print Size', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->format_print_size( $data ) ),
		);

		$item_data[] = array(
			'key'   => __( 'DPI', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $this->format_dpi( $data ) ),
		);

		$item_data[] = array(
			'key'   => __( 'Quality', 'limitless-artwork-analyzer' ),
			'value' => esc_html( $data['quality_rating'] ),
		);

		$warnings = $this->get_customer_warnings( $data );

		if ( ! empty( $warnings ) ) {
			$item_data[] = array(
				'key'   => __( 'Artwork Warnings', 'limitless-artwork-analyzer' ),
				'value' => esc_html( implode( ' | ', $warnings ) ),
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
		$item->add_meta_data( __( 'Artwork Analysis Mode', 'limitless-artwork-analyzer' ), isset( $data['scan_mode_label'] ) ? $data['scan_mode_label'] : '', true );
		$item->add_meta_data( __( 'Artwork Has Transparency', 'limitless-artwork-analyzer' ), $this->yes_no( array_key_exists( 'has_transparency', $data ) ? $data['has_transparency'] : null ), true );
		$item->add_meta_data( __( 'Artwork Transparent Background', 'limitless-artwork-analyzer' ), $this->yes_no( array_key_exists( 'transparent_background', $data ) ? $data['transparent_background'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Pixels', 'limitless-artwork-analyzer' ), $this->yes_no( array_key_exists( 'semi_transparent_pixels_detected', $data ) ? $data['semi_transparent_pixels_detected'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Pixel Count', 'limitless-artwork-analyzer' ), $this->format_nullable_number( isset( $data['semi_transparent_pixel_count'] ) ? $data['semi_transparent_pixel_count'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Pixel Percentage', 'limitless-artwork-analyzer' ), $this->format_nullable_percentage( isset( $data['semi_transparent_pixel_percentage'] ) ? $data['semi_transparent_pixel_percentage'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Edge Pixels', 'limitless-artwork-analyzer' ), $this->format_nullable_number( isset( $data['semi_transparent_edge_pixels'] ) ? $data['semi_transparent_edge_pixels'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Interior Pixels', 'limitless-artwork-analyzer' ), $this->format_nullable_number( isset( $data['semi_transparent_interior_pixels'] ) ? $data['semi_transparent_interior_pixels'] : null ), true );
		$item->add_meta_data( __( 'Artwork Semi-Transparent Interior Percentage', 'limitless-artwork-analyzer' ), $this->format_nullable_percentage( isset( $data['semi_transparent_interior_percentage'] ) ? $data['semi_transparent_interior_percentage'] : null ), true );
		$item->add_meta_data( __( 'Artwork Alpha Thresholds Used', 'limitless-artwork-analyzer' ), $this->format_alpha_thresholds( isset( $data['alpha_thresholds_used'] ) ? $data['alpha_thresholds_used'] : array() ), true );
		$item->add_meta_data( __( 'Artwork Long File Warning', 'limitless-artwork-analyzer' ), $this->yes_no( ! empty( $data['long_file_warning'] ) ), true );
		$item->add_meta_data( __( 'Artwork File URL', 'limitless-artwork-analyzer' ), $data['file_url'], true );
		$item->add_meta_data( __( 'Artwork File Path', 'limitless-artwork-analyzer' ), $data['file_path'], true );

		if ( ! empty( $data['warnings'] ) ) {
			$item->add_meta_data( __( 'Artwork Warnings', 'limitless-artwork-analyzer' ), implode( ' | ', $data['warnings'] ), true );
		}

		if ( ! empty( $data['skipped_checks'] ) ) {
			$item->add_meta_data( __( 'Artwork Skipped Checks', 'limitless-artwork-analyzer' ), implode( ' | ', $data['skipped_checks'] ), true );
			$item->add_meta_data( __( 'Artwork Skipped Check Reason', 'limitless-artwork-analyzer' ), $data['skipped_check_reason'], true );
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

		$skipped_checks = array();

		if ( ! empty( $analysis['skipped_checks'] ) && is_array( $analysis['skipped_checks'] ) ) {
			foreach ( $analysis['skipped_checks'] as $skipped_check ) {
				$skipped_checks[] = sanitize_text_field( $skipped_check );
			}
		}

		return array(
			'original_file_name'               => sanitize_file_name( $analysis['original_file_name'] ),
			'pixel_width'                      => isset( $analysis['pixel_width'] ) ? absint( $analysis['pixel_width'] ) : 0,
			'pixel_height'                     => isset( $analysis['pixel_height'] ) ? absint( $analysis['pixel_height'] ) : 0,
			'pixel_count'                      => isset( $analysis['pixel_count'] ) ? absint( $analysis['pixel_count'] ) : 0,
			'dpi_used'                         => isset( $analysis['dpi_used'] ) ? (float) $analysis['dpi_used'] : 0,
			'dpi_assumed'                      => ! empty( $analysis['dpi_assumed'] ),
			'width_inches'                     => isset( $analysis['width_inches'] ) ? (float) $analysis['width_inches'] : 0,
			'height_inches'                    => isset( $analysis['height_inches'] ) ? (float) $analysis['height_inches'] : 0,
			'quality_rating'                   => isset( $analysis['quality_rating'] ) ? sanitize_text_field( $analysis['quality_rating'] ) : '',
			'scan_mode'                        => isset( $analysis['scan_mode'] ) ? sanitize_text_field( $analysis['scan_mode'] ) : '',
			'scan_mode_label'                  => isset( $analysis['scan_mode_label'] ) ? sanitize_text_field( $analysis['scan_mode_label'] ) : '',
			'skipped_checks'                   => $skipped_checks,
			'skipped_check_reason'             => isset( $analysis['skipped_check_reason'] ) ? sanitize_text_field( $analysis['skipped_check_reason'] ) : '',
			'has_transparency'                 => $this->normalize_nullable_bool( $analysis, 'has_transparency' ),
			'transparent_background'           => $this->normalize_nullable_bool( $analysis, 'transparent_background' ),
			'semi_transparent_pixels_detected' => $this->normalize_nullable_bool( $analysis, 'semi_transparent_pixels_detected' ),
			'semi_transparent_pixel_count'     => $this->normalize_nullable_int( $analysis, 'semi_transparent_pixel_count' ),
			'semi_transparent_pixel_percentage' => $this->normalize_nullable_float( $analysis, 'semi_transparent_pixel_percentage' ),
			'semi_transparent_edge_pixels'     => $this->normalize_nullable_int( $analysis, 'semi_transparent_edge_pixels' ),
			'semi_transparent_interior_pixels' => $this->normalize_nullable_int( $analysis, 'semi_transparent_interior_pixels' ),
			'semi_transparent_interior_percentage' => $this->normalize_nullable_float( $analysis, 'semi_transparent_interior_percentage' ),
			'alpha_thresholds_used'            => $this->normalize_alpha_thresholds( isset( $analysis['alpha_thresholds_used'] ) ? $analysis['alpha_thresholds_used'] : array() ),
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
		if ( null === $value ) {
			return __( 'Skipped', 'limitless-artwork-analyzer' );
		}

		return $value ? __( 'Yes', 'limitless-artwork-analyzer' ) : __( 'No', 'limitless-artwork-analyzer' );
	}

	/**
	 * Format a nullable whole number for admin order metadata.
	 *
	 * @param int|null $value Number or null when skipped.
	 * @return string
	 */
	private function format_nullable_number( $value ) {
		if ( null === $value ) {
			return __( 'Skipped', 'limitless-artwork-analyzer' );
		}

		return number_format_i18n( (int) $value );
	}

	/**
	 * Format a nullable percentage for admin order metadata.
	 *
	 * @param float|null $value Percentage or null when skipped.
	 * @return string
	 */
	private function format_nullable_percentage( $value ) {
		if ( null === $value ) {
			return __( 'Skipped', 'limitless-artwork-analyzer' );
		}

		return rtrim( rtrim( number_format_i18n( (float) $value, 4 ), '0' ), '.' ) . '%';
	}

	/**
	 * Format alpha detection thresholds for admin order metadata.
	 *
	 * @param array $thresholds Threshold data.
	 * @return string
	 */
	private function format_alpha_thresholds( $thresholds ) {
		if ( empty( $thresholds ) || ! is_array( $thresholds ) ) {
			return '';
		}

		$lower      = isset( $thresholds['lower'] ) ? (int) $thresholds['lower'] : 20;
		$upper      = isset( $thresholds['upper'] ) ? (int) $thresholds['upper'] : 235;
		$percentage = isset( $thresholds['percentage'] ) ? (float) $thresholds['percentage'] : 0.25;
		$ignore_edge_antialiasing = ! empty( $thresholds['ignore_edge_antialiasing'] );

		return sprintf(
			/* translators: 1: lower alpha, 2: upper alpha, 3: percentage, 4: yes/no value. */
			__( 'Lower %1$d, upper %2$d, warning above %3$s%%, ignore edge anti-aliasing: %4$s', 'limitless-artwork-analyzer' ),
			$lower,
			$upper,
			rtrim( rtrim( number_format_i18n( $percentage, 4 ), '0' ), '.' ),
			$ignore_edge_antialiasing ? __( 'Yes', 'limitless-artwork-analyzer' ) : __( 'No', 'limitless-artwork-analyzer' )
		);
	}

	/**
	 * Format print dimensions for customer-facing cart and checkout output.
	 *
	 * @param array $data Stored analysis data.
	 * @return string
	 */
	private function format_print_size( $data ) {
		return sprintf(
			'%1$s″ × %2$s″',
			isset( $data['width_inches'] ) ? $data['width_inches'] : 0,
			isset( $data['height_inches'] ) ? $data['height_inches'] : 0
		);
	}

	/**
	 * Format DPI for customer-facing cart and checkout output.
	 *
	 * @param array $data Stored analysis data.
	 * @return string
	 */
	private function format_dpi( $data ) {
		$dpi = isset( $data['dpi_used'] ) ? $data['dpi_used'] : 0;

		if ( ! empty( $data['dpi_assumed'] ) ) {
			return $dpi . ' (' . __( 'assumed', 'limitless-artwork-analyzer' ) . ')';
		}

		return (string) $dpi;
	}

	/**
	 * Keep customer warnings useful and hide internal skipped-check details.
	 *
	 * @param array $data Stored analysis data.
	 * @return array
	 */
	private function get_customer_warnings( $data ) {
		$warnings = array();

		if ( ! empty( $data['warnings'] ) && is_array( $data['warnings'] ) ) {
			foreach ( $data['warnings'] as $warning ) {
				if ( ! $this->is_skipped_analysis_warning( $warning ) ) {
					$warnings[] = $warning;
				}
			}
		}

		if ( ! empty( $data['skipped_checks'] ) ) {
			$warnings[] = __( 'This file is very large, so advanced transparency checks were skipped. Basic size and DPI checks were completed.', 'limitless-artwork-analyzer' );
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Detect internal skipped-analysis warning text.
	 *
	 * @param string $warning Warning text.
	 * @return bool
	 */
	private function is_skipped_analysis_warning( $warning ) {
		$warning = strtolower( (string) $warning );

		return false !== strpos( $warning, 'too large to fully analyze' ) || false !== strpos( $warning, 'transparency checks were skipped' );
	}

	/**
	 * Preserve skipped check values as null instead of converting them to false.
	 *
	 * @param array  $analysis Analysis data.
	 * @param string $key      Analysis key.
	 * @return bool|null
	 */
	private function normalize_nullable_bool( $analysis, $key ) {
		if ( ! array_key_exists( $key, $analysis ) || null === $analysis[ $key ] ) {
			return null;
		}

		return ! empty( $analysis[ $key ] );
	}

	/**
	 * Preserve skipped integer fields as null.
	 *
	 * @param array  $analysis Analysis data.
	 * @param string $key      Analysis key.
	 * @return int|null
	 */
	private function normalize_nullable_int( $analysis, $key ) {
		if ( ! array_key_exists( $key, $analysis ) || null === $analysis[ $key ] ) {
			return null;
		}

		return absint( $analysis[ $key ] );
	}

	/**
	 * Preserve skipped float fields as null.
	 *
	 * @param array  $analysis Analysis data.
	 * @param string $key      Analysis key.
	 * @return float|null
	 */
	private function normalize_nullable_float( $analysis, $key ) {
		if ( ! array_key_exists( $key, $analysis ) || null === $analysis[ $key ] ) {
			return null;
		}

		return (float) $analysis[ $key ];
	}

	/**
	 * Sanitize alpha threshold metadata before cart storage.
	 *
	 * @param array $thresholds Raw threshold data.
	 * @return array
	 */
	private function normalize_alpha_thresholds( $thresholds ) {
		if ( ! is_array( $thresholds ) ) {
			return array();
		}

		return array(
			'lower'                    => isset( $thresholds['lower'] ) ? min( 255, absint( $thresholds['lower'] ) ) : 20,
			'upper'                    => isset( $thresholds['upper'] ) ? min( 255, absint( $thresholds['upper'] ) ) : 235,
			'percentage'               => isset( $thresholds['percentage'] ) ? min( 100, max( 0, (float) $thresholds['percentage'] ) ) : 0.25,
			'ignore_edge_antialiasing' => ! empty( $thresholds['ignore_edge_antialiasing'] ),
		);
	}
}
