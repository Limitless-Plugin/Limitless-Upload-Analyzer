<?php
/**
 * Admin settings page.
 *
 * @package LimitlessArtworkAnalyzer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Artwork Analyzer settings page.
 */
class LAA_Admin_Settings {
	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'laa-artwork-analyzer';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the page under WooCommerce when available, otherwise under Settings.
	 */
	public function add_settings_page() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Artwork Analyzer', 'limitless-artwork-analyzer' ),
				__( 'Artwork Analyzer', 'limitless-artwork-analyzer' ),
				'manage_woocommerce',
				$this->page_slug,
				array( $this, 'render_settings_page' )
			);

			return;
		}

		add_options_page(
			__( 'Artwork Analyzer', 'limitless-artwork-analyzer' ),
			__( 'Artwork Analyzer', 'limitless-artwork-analyzer' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			'laa_settings_group',
			LAA_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Limitless_Artwork_Analyzer::get_default_settings(),
			)
		);

		add_settings_section(
			'laa_main_section',
			__( 'Analyzer Settings', 'limitless-artwork-analyzer' ),
			array( $this, 'render_section_intro' ),
			$this->page_slug
		);

		add_settings_field(
			'enabled',
			__( 'Enable Analyzer', 'limitless-artwork-analyzer' ),
			array( $this, 'render_enabled_field' ),
			$this->page_slug,
			'laa_main_section'
		);

		add_settings_field(
			'product_ids',
			__( 'Product IDs', 'limitless-artwork-analyzer' ),
			array( $this, 'render_product_ids_field' ),
			$this->page_slug,
			'laa_main_section'
		);

		$number_fields = array(
			'max_upload_size_mb'               => array(
				'label'       => __( 'Max Upload File Size in MB', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'min'         => '1',
				'description' => __( 'Default: 200. This is the plugin limit; PHP/LocalWP upload limits must also allow this size.', 'limitless-artwork-analyzer' ),
			),
			'min_dimension_inches'             => array(
				'label'       => __( 'Minimum Required Dimension in Inches', 'limitless-artwork-analyzer' ),
				'step'        => '0.1',
				'description' => __( 'Default: 19. One dimension should be at least this size.', 'limitless-artwork-analyzer' ),
			),
			'max_print_width_inches'           => array(
				'label'       => __( 'Maximum Allowed Print Width in Inches', 'limitless-artwork-analyzer' ),
				'step'        => '0.1',
				'description' => __( 'Default: 22.5. One dimension should be no larger than this value.', 'limitless-artwork-analyzer' ),
			),
			'long_png_warning_inches'          => array(
				'label'       => __( 'Long PNG Warning Threshold in Inches', 'limitless-artwork-analyzer' ),
				'step'        => '0.1',
				'description' => __( 'Default: 100. Files longer than this show a cleanup-tool warning.', 'limitless-artwork-analyzer' ),
			),
			'max_full_scan_pixels'             => array(
				'label'       => __( 'Max Pixel Count for Full Scan', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'min'         => '1',
				'description' => __( 'Default: 50000000. Images at or below this pixel count can use a full transparency scan when memory allows.', 'limitless-artwork-analyzer' ),
			),
			'max_sampled_scan_pixels'          => array(
				'label'       => __( 'Max Pixel Count for Sampled Scan', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'min'         => '1',
				'description' => __( 'Default: 250000000. Images above the full scan limit and at or below this limit use sampled transparency checks when memory allows.', 'limitless-artwork-analyzer' ),
			),
			'semi_transparent_alpha_threshold' => array(
				'label'       => __( 'Semi-Transparent Pixel Alpha Threshold', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'min'         => '1',
				'max'         => '255',
				'description' => __( 'Default: 254. Uses normal PNG alpha where 0 is transparent and 255 is opaque.', 'limitless-artwork-analyzer' ),
			),
			'poor_dpi_threshold'               => array(
				'label'       => __( 'Poor DPI Threshold', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: below 150 DPI is Poor.', 'limitless-artwork-analyzer' ),
			),
			'fair_dpi_min'                     => array(
				'label'       => __( 'Fair DPI Minimum', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: 150.', 'limitless-artwork-analyzer' ),
			),
			'fair_dpi_max'                     => array(
				'label'       => __( 'Fair DPI Maximum', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: 224.', 'limitless-artwork-analyzer' ),
			),
			'good_dpi_min'                     => array(
				'label'       => __( 'Good DPI Minimum', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: 225.', 'limitless-artwork-analyzer' ),
			),
			'good_dpi_max'                     => array(
				'label'       => __( 'Good DPI Maximum', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: 299.', 'limitless-artwork-analyzer' ),
			),
			'excellent_dpi_threshold'          => array(
				'label'       => __( 'Excellent DPI Threshold', 'limitless-artwork-analyzer' ),
				'step'        => '1',
				'description' => __( 'Default: 300 DPI and above is Excellent.', 'limitless-artwork-analyzer' ),
			),
		);

		foreach ( $number_fields as $key => $args ) {
			add_settings_field(
				$key,
				$args['label'],
				array( $this, 'render_number_field' ),
				$this->page_slug,
				'laa_main_section',
				array_merge(
					array(
						'key' => $key,
						'min' => '0',
						'max' => '',
					),
					$args
				)
			);
		}
	}

	/**
	 * Clean all settings before saving.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Limitless_Artwork_Analyzer::get_default_settings();
		$output   = array();

		$output['enabled']     = isset( $input['enabled'] ) && 'yes' === $input['enabled'] ? 'yes' : 'no';
		$output['product_ids'] = $this->sanitize_product_ids( isset( $input['product_ids'] ) ? $input['product_ids'] : '' );

		$output['max_upload_size_mb']               = $this->sanitize_float( $input, 'max_upload_size_mb', $defaults['max_upload_size_mb'], 1 );
		$output['min_dimension_inches']             = $this->sanitize_float( $input, 'min_dimension_inches', $defaults['min_dimension_inches'] );
		$output['max_print_width_inches']           = $this->sanitize_float( $input, 'max_print_width_inches', $defaults['max_print_width_inches'] );
		$output['long_png_warning_inches']          = $this->sanitize_float( $input, 'long_png_warning_inches', $defaults['long_png_warning_inches'] );
		$output['max_full_scan_pixels']             = $this->sanitize_int( $input, 'max_full_scan_pixels', $defaults['max_full_scan_pixels'], 1 );
		$output['max_sampled_scan_pixels']          = $this->sanitize_int( $input, 'max_sampled_scan_pixels', $defaults['max_sampled_scan_pixels'], 1 );
		$output['semi_transparent_alpha_threshold'] = $this->sanitize_int( $input, 'semi_transparent_alpha_threshold', $defaults['semi_transparent_alpha_threshold'], 1, 255 );
		$output['poor_dpi_threshold']               = $this->sanitize_int( $input, 'poor_dpi_threshold', $defaults['poor_dpi_threshold'], 1 );
		$output['fair_dpi_min']                     = $this->sanitize_int( $input, 'fair_dpi_min', $defaults['fair_dpi_min'], 1 );
		$output['fair_dpi_max']                     = $this->sanitize_int( $input, 'fair_dpi_max', $defaults['fair_dpi_max'], 1 );
		$output['good_dpi_min']                     = $this->sanitize_int( $input, 'good_dpi_min', $defaults['good_dpi_min'], 1 );
		$output['good_dpi_max']                     = $this->sanitize_int( $input, 'good_dpi_max', $defaults['good_dpi_max'], 1 );
		$output['excellent_dpi_threshold']          = $this->sanitize_int( $input, 'excellent_dpi_threshold', $defaults['excellent_dpi_threshold'], 1 );

		if ( $output['fair_dpi_min'] > $output['fair_dpi_max'] ) {
			$temp                   = $output['fair_dpi_min'];
			$output['fair_dpi_min'] = $output['fair_dpi_max'];
			$output['fair_dpi_max'] = $temp;
		}

		if ( $output['good_dpi_min'] > $output['good_dpi_max'] ) {
			$temp                   = $output['good_dpi_min'];
			$output['good_dpi_min'] = $output['good_dpi_max'];
			$output['good_dpi_max'] = $temp;
		}

		if ( $output['max_full_scan_pixels'] > $output['max_sampled_scan_pixels'] ) {
			$output['max_sampled_scan_pixels'] = $output['max_full_scan_pixels'];
		}

		return $output;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Artwork Analyzer', 'limitless-artwork-analyzer' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'laa_settings_group' );
				do_settings_sections( $this->page_slug );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render section intro text.
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Configure where the analyzer appears and how PNG files are rated. Leave Product IDs blank to show the analyzer on all WooCommerce products while enabled.', 'limitless-artwork-analyzer' ) . '</p>';
	}

	/**
	 * Render enabled checkbox.
	 */
	public function render_enabled_field() {
		$settings = Limitless_Artwork_Analyzer::get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( LAA_OPTION_NAME ); ?>[enabled]" value="yes" <?php checked( 'yes', $settings['enabled'] ); ?> />
			<?php esc_html_e( 'Show the analyzer on enabled product pages.', 'limitless-artwork-analyzer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render product IDs input.
	 */
	public function render_product_ids_field() {
		$settings = Limitless_Artwork_Analyzer::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( LAA_OPTION_NAME ); ?>[product_ids]" value="<?php echo esc_attr( $settings['product_ids'] ); ?>" placeholder="123, 456, 789" />
		<p class="description"><?php esc_html_e( 'Comma-separated WooCommerce product IDs. Leave blank to show on all products.', 'limitless-artwork-analyzer' ); ?></p>
		<?php
	}

	/**
	 * Render a number input.
	 *
	 * @param array $args Field args.
	 */
	public function render_number_field( $args ) {
		$settings = Limitless_Artwork_Analyzer::get_settings();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		?>
		<input
			type="number"
			name="<?php echo esc_attr( LAA_OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			step="<?php echo esc_attr( $args['step'] ); ?>"
			min="<?php echo esc_attr( $args['min'] ); ?>"
			<?php echo '' !== $args['max'] ? 'max="' . esc_attr( $args['max'] ) . '"' : ''; ?>
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sanitize comma-separated product IDs.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_product_ids( $value ) {
		if ( is_array( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $value ) );
		$ids   = array();

		foreach ( explode( ',', $value ) as $piece ) {
			$id = absint( trim( $piece ) );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return implode( ', ', array_values( array_unique( $ids ) ) );
	}

	/**
	 * Sanitize a float setting.
	 *
	 * @param array  $input   Raw settings.
	 * @param string $key     Setting key.
	 * @param float  $default Default value.
	 * @return float
	 */
	private function sanitize_float( $input, $key, $default, $min = 0 ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || is_array( $input[ $key ] ) ) {
			return $default;
		}

		$value = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		$value = (float) str_replace( ',', '.', $value );

		return max( $min, $value );
	}

	/**
	 * Sanitize an integer setting.
	 *
	 * @param array  $input   Raw settings.
	 * @param string $key     Setting key.
	 * @param int    $default Default value.
	 * @param int    $min     Minimum allowed value.
	 * @param int    $max     Maximum allowed value.
	 * @return int
	 */
	private function sanitize_int( $input, $key, $default, $min = 0, $max = null ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || is_array( $input[ $key ] ) ) {
			return $default;
		}

		$value = absint( $input[ $key ] );
		$value = max( $min, $value );

		if ( null !== $max ) {
			$value = min( $max, $value );
		}

		return $value;
	}
}
