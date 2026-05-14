<?php
/**
 * Frontend product page output.
 *
 * @package LimitlessArtworkAnalyzer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the analyzer UI to WooCommerce product pages.
 */
class LAA_Frontend {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_analyzer_box' ), 15 );
	}

	/**
	 * Enqueue CSS and JS only on product pages where the analyzer is enabled.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();

		if ( ! Limitless_Artwork_Analyzer::should_show_for_product( $product_id ) ) {
			return;
		}

		$configured_max_upload_bytes = Limitless_Artwork_Analyzer::get_configured_max_upload_bytes();

		wp_enqueue_style(
			'laa-analyzer',
			LAA_PLUGIN_URL . 'assets/css/analyzer.css',
			array(),
			LAA_VERSION
		);

		wp_enqueue_script(
			'laa-analyzer',
			LAA_PLUGIN_URL . 'assets/js/analyzer.js',
			array( 'jquery' ),
			LAA_VERSION,
			true
		);

		wp_localize_script(
			'laa-analyzer',
			'LAAAnalyzer',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'laa_analyze_artwork' ),
				'productId'      => absint( $product_id ),
				'maxUploadBytes' => $configured_max_upload_bytes,
				'maxUploadLabel' => size_format( $configured_max_upload_bytes ),
				'i18n'           => array(
					'choosePng'       => __( 'Please choose a PNG file.', 'limitless-artwork-analyzer' ),
					'pngOnly'         => __( 'Only PNG files are supported for V1.', 'limitless-artwork-analyzer' ),
					'tooLarge'        => __( 'This file is larger than the configured PNG upload limit.', 'limitless-artwork-analyzer' ),
					'analyzing'       => __( 'Uploading and analyzing your PNG...', 'limitless-artwork-analyzer' ),
					'ready'           => __( 'Artwork analysis complete.', 'limitless-artwork-analyzer' ),
					'uploadFailed'    => __( 'The upload failed. Please try again.', 'limitless-artwork-analyzer' ),
					'waitForAnalysis' => __( 'Please wait for the artwork analysis to finish before adding this product to the cart.', 'limitless-artwork-analyzer' ),
				),
			)
		);
	}

	/**
	 * Render the analyzer box above the Add to Cart button.
	 */
	public function render_analyzer_box() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! Limitless_Artwork_Analyzer::should_show_for_product( $product_id ) ) {
			return;
		}
		?>
		<div class="laa-analyzer" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<div class="laa-analyzer__header">
				<h3><?php esc_html_e( 'Artwork Analyzer', 'limitless-artwork-analyzer' ); ?></h3>
				<p><?php esc_html_e( 'Upload a PNG to check dimensions, DPI, and transparency before adding this product to your cart.', 'limitless-artwork-analyzer' ); ?></p>
			</div>

			<div class="laa-dropzone" tabindex="0">
				<input class="laa-file-input" type="file" accept="image/png" />
				<div class="laa-dropzone__content">
					<strong><?php esc_html_e( 'Drag and drop your PNG here', 'limitless-artwork-analyzer' ); ?></strong>
					<span><?php esc_html_e( 'or', 'limitless-artwork-analyzer' ); ?></span>
					<button type="button" class="button laa-browse-button"><?php esc_html_e( 'Browse PNG', 'limitless-artwork-analyzer' ); ?></button>
				</div>
			</div>

			<div class="laa-file-summary" hidden>
				<img class="laa-preview" src="" alt="<?php esc_attr_e( 'Artwork preview', 'limitless-artwork-analyzer' ); ?>" />
				<div>
					<div class="laa-file-name"></div>
					<div class="laa-status" role="status" aria-live="polite"></div>
				</div>
			</div>

			<div class="laa-results" hidden></div>

			<input type="hidden" name="laa_analysis_token" class="laa-analysis-token" value="" />
			<?php wp_nonce_field( 'laa_add_to_cart', 'laa_cart_nonce' ); ?>
		</div>
		<?php
	}
}
