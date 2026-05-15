<?php
/**
 * Plugin Name: Limitless Artwork Analyzer
 * Description: Adds a PNG artwork upload and analyzer box to selected WooCommerce product pages.
 * Version: 1.0.6
 * Author: Limitless
 * Text Domain: limitless-artwork-analyzer
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAA_VERSION', '1.0.6' );
define( 'LAA_PLUGIN_FILE', __FILE__ );
define( 'LAA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LAA_OPTION_NAME', 'laa_settings' );
define( 'LAA_UPLOAD_SUBDIR', 'limitless-artwork-analyzer' );

/**
 * Main plugin coordinator.
 *
 * This class stays small on purpose. It loads the feature classes and keeps
 * shared helpers, defaults, and upload-folder setup in one easy-to-find place.
 */
final class Limitless_Artwork_Analyzer {
	/**
	 * Singleton instance.
	 *
	 * @var Limitless_Artwork_Analyzer|null
	 */
	private static $instance = null;

	/**
	 * Get the plugin instance.
	 *
	 * @return Limitless_Artwork_Analyzer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Start the plugin after WordPress and other plugins have loaded.
	 */
	private function __construct() {
		$this->includes();

		if ( is_admin() ) {
			new LAA_Admin_Settings();
		}

		if ( ! self::is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		new LAA_Frontend();
		new LAA_Ajax_Handler();
		new LAA_Cart_Meta();
	}

	/**
	 * Load class files.
	 */
	private function includes() {
		require_once LAA_PLUGIN_DIR . 'includes/class-admin-settings.php';
		require_once LAA_PLUGIN_DIR . 'includes/class-analyzer.php';
		require_once LAA_PLUGIN_DIR . 'includes/class-ajax-handler.php';
		require_once LAA_PLUGIN_DIR . 'includes/class-frontend.php';
		require_once LAA_PLUGIN_DIR . 'includes/class-cart-meta.php';
	}

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		if ( false === get_option( LAA_OPTION_NAME ) ) {
			add_option( LAA_OPTION_NAME, self::get_default_settings() );
		}

		self::ensure_upload_directory();
	}

	/**
	 * Default settings for a fresh install.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'                          => 'yes',
			'product_ids'                      => '',
			'max_upload_size_mb'               => 200,
			'min_dimension_inches'             => 19,
			'max_print_width_inches'           => 22.5,
			'long_png_warning_inches'          => 100,
			'max_full_scan_pixels'             => 50000000,
			'max_sampled_scan_pixels'          => 250000000,
			'semi_transparent_alpha_threshold'            => 254,
			'semi_transparent_alpha_lower_threshold'      => 20,
			'semi_transparent_alpha_upper_threshold'      => 235,
			'semi_transparent_pixel_percentage_threshold' => 0.25,
			'ignore_edge_antialiasing'                    => 'yes',
			'poor_dpi_threshold'                          => 150,
			'fair_dpi_min'                                => 150,
			'fair_dpi_max'                                => 224,
			'good_dpi_min'                                => 225,
			'good_dpi_max'                                => 299,
			'excellent_dpi_threshold'                     => 300,
		);
	}

	/**
	 * Get saved settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( LAA_OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::get_default_settings() );
	}

	/**
	 * Convert the comma-separated product IDs setting into integers.
	 *
	 * @return array
	 */
	public static function get_selected_product_ids() {
		$settings = self::get_settings();
		$raw_ids  = explode( ',', (string) $settings['product_ids'] );
		$ids      = array();

		foreach ( $raw_ids as $raw_id ) {
			$id = absint( trim( $raw_id ) );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Get the configured plugin upload size in bytes.
	 *
	 * This is separate from PHP/WordPress server limits. If the server limit is
	 * lower than this setting, PHP may still reject the upload before the plugin
	 * receives it.
	 *
	 * @return int
	 */
	public static function get_configured_max_upload_bytes() {
		$settings = self::get_settings();
		$mb       = isset( $settings['max_upload_size_mb'] ) ? (float) $settings['max_upload_size_mb'] : 200;

		return max( 1, (int) round( $mb * MB_IN_BYTES ) );
	}

	/**
	 * Decide whether the analyzer should appear for a product.
	 *
	 * If product IDs are left blank, the analyzer appears on all products while
	 * globally enabled. This makes local testing fast, while still allowing a
	 * specific product allow-list when IDs are entered.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function should_show_for_product( $product_id ) {
		$settings = self::get_settings();

		if ( 'yes' !== $settings['enabled'] ) {
			return false;
		}

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		$selected_ids = self::get_selected_product_ids();

		return empty( $selected_ids ) || in_array( $product_id, $selected_ids, true );
	}

	/**
	 * Create and protect the plugin upload folder.
	 *
	 * @return array|WP_Error
	 */
	public static function ensure_upload_directory() {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'laa_upload_dir_error', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . LAA_UPLOAD_SUBDIR;
		$url = trailingslashit( $upload_dir['baseurl'] ) . LAA_UPLOAD_SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'laa_upload_dir_create_failed', __( 'The artwork upload folder could not be created.', 'limitless-artwork-analyzer' ) );
		}

		self::write_upload_protection_files( $dir );

		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'laa_upload_dir_not_writable', __( 'The artwork upload folder is not writable.', 'limitless-artwork-analyzer' ) );
		}

		return array(
			'dir' => $dir,
			'url' => $url,
		);
	}

	/**
	 * Add simple files that discourage directory browsing and script execution.
	 *
	 * @param string $dir Upload directory.
	 */
	private static function write_upload_protection_files( $dir ) {
		$index_file = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			$rules = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n\tDeny from all\n</FilesMatch>\n";
			@file_put_contents( $htaccess_file, $rules );
		}

		$web_config_file = trailingslashit( $dir ) . 'web.config';

		if ( ! file_exists( $web_config_file ) ) {
			$web_config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<handlers>\n\t\t\t<remove name=\"PHP_via_FastCGI\" />\n\t\t</handlers>\n\t\t<directoryBrowse enabled=\"false\" />\n\t</system.webServer>\n</configuration>\n";
			@file_put_contents( $web_config_file, $web_config );
		}
	}

	/**
	 * Check whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Limitless Artwork Analyzer requires WooCommerce to display on product pages.', 'limitless-artwork-analyzer' );
		echo '</p></div>';
	}
}

register_activation_hook( __FILE__, array( 'Limitless_Artwork_Analyzer', 'activate' ) );
add_action( 'plugins_loaded', array( 'Limitless_Artwork_Analyzer', 'instance' ), 20 );
