<?php
/**
 * PNG analyzer.
 *
 * @package LimitlessArtworkAnalyzer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads PNG metadata and samples pixels for print-readiness checks.
 *
 * This class is intentionally independent from WooCommerce. If V2 moves heavy
 * work to an external service, this class is the natural boundary to replace.
 */
class LAA_Analyzer {
	/**
	 * Maximum pixels to sample when looking for transparency issues.
	 *
	 * @var int
	 */
	private $max_samples = 250000;

	/**
	 * Analyze a saved PNG file.
	 *
	 * @param string $file_path          Local PNG file path.
	 * @param string $original_file_name Original upload name.
	 * @param array  $settings           Plugin settings.
	 * @return array|WP_Error
	 */
	public function analyze( $file_path, $original_file_name, $settings ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'laa_file_missing',
				__( 'The uploaded PNG could not be read.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'The saved upload file does not exist or is not readable.',
					'file_path'         => $file_path,
				)
			);
		}

		if ( ! function_exists( 'getimagesize' ) ) {
			return new WP_Error(
				'laa_getimagesize_missing',
				__( 'This server cannot read image dimensions because the PHP image functions are unavailable.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'The PHP getimagesize() function is not available.',
				)
			);
		}

		$image_size = @getimagesize( $file_path );

		if ( false === $image_size || IMAGETYPE_PNG !== $image_size[2] ) {
			return new WP_Error(
				'laa_invalid_png',
				__( 'The uploaded file is not a readable PNG image.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'getimagesize() failed or did not return IMAGETYPE_PNG after the file was saved.',
					'file_path'         => $file_path,
				)
			);
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return new WP_Error(
				'laa_gd_missing',
				__( 'The PHP GD image library is required to check PNG transparency.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'imagecreatefrompng() is not available. The PHP GD extension may be missing or disabled.',
				)
			);
		}

		$pixel_width  = (int) $image_size[0];
		$pixel_height = (int) $image_size[1];

		if ( $pixel_width <= 0 || $pixel_height <= 0 ) {
			return new WP_Error(
				'laa_invalid_dimensions',
				__( 'The PNG dimensions could not be detected.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'The PNG dimensions were zero or negative after getimagesize().',
					'image_size'        => $image_size,
				)
			);
		}

		$dpi_data      = $this->read_png_dpi( $file_path );
		$dpi_assumed   = false;
		$dpi_message   = '';
		$dpi_x         = 300;
		$dpi_y         = 300;
		$dpi_used      = 300;

		if ( is_array( $dpi_data ) ) {
			$dpi_x    = $dpi_data['x'];
			$dpi_y    = $dpi_data['y'];
			$dpi_used = round( ( $dpi_x + $dpi_y ) / 2, 2 );
		} else {
			$dpi_assumed = true;
			$dpi_message = __( 'DPI metadata not found, assuming 300 DPI.', 'limitless-artwork-analyzer' );
		}

		$width_inches  = round( $pixel_width / $dpi_x, 2 );
		$height_inches = round( $pixel_height / $dpi_y, 2 );
		$quality       = $this->get_quality_rating( $dpi_used, $settings );

		$image = @imagecreatefrompng( $file_path );

		if ( ! $image ) {
			return new WP_Error(
				'laa_corrupt_png',
				__( 'The PNG appears to be corrupt or could not be opened.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'imagecreatefrompng() returned false. ' . $this->get_last_php_error_message(),
					'file_path'         => $file_path,
					'pixel_width'       => $pixel_width,
					'pixel_height'      => $pixel_height,
				)
			);
		}

		$transparency = $this->inspect_transparency( $image, $pixel_width, $pixel_height, (int) $settings['semi_transparent_alpha_threshold'] );
		imagedestroy( $image );

		$dimension_warning = ! $this->has_dimension_between(
			$width_inches,
			$height_inches,
			(float) $settings['min_dimension_inches'],
			(float) $settings['max_print_width_inches']
		);
		$long_file_warning = $width_inches > (float) $settings['long_png_warning_inches'] || $height_inches > (float) $settings['long_png_warning_inches'];
		$warnings          = array();

		if ( $dimension_warning ) {
			$warnings[] = sprintf(
				/* translators: 1: minimum inches, 2: maximum inches. */
				__( 'One dimension should be between %1$s inches and %2$s inches for this product.', 'limitless-artwork-analyzer' ),
				$this->format_inches_for_message( $settings['min_dimension_inches'] ),
				$this->format_inches_for_message( $settings['max_print_width_inches'] )
			);
		}

		if ( $long_file_warning ) {
			$warnings[] = sprintf(
				/* translators: %s: long file threshold in inches. */
				__( 'This file is over %s inches long. You can still upload it, but we may not be able to run our artwork edge cleanup tool on this file.', 'limitless-artwork-analyzer' ),
				$this->format_inches_for_message( $settings['long_png_warning_inches'] )
			);
		}

		if ( $transparency['semi_transparent_pixels_detected'] ) {
			$warnings[] = __( 'Semi-transparent pixels were detected. These can sometimes create unexpected results with DTF printing, especially around fades, shadows, and soft edges.', 'limitless-artwork-analyzer' );
		}

		if ( ! $transparency['transparent_background'] ) {
			$warnings[] = __( 'A transparent background was not detected around the artwork edges. Please make sure the PNG is saved with transparency.', 'limitless-artwork-analyzer' );
		}

		return array(
			'original_file_name'                  => sanitize_file_name( $original_file_name ),
			'pixel_width'                         => $pixel_width,
			'pixel_height'                        => $pixel_height,
			'dpi_used'                            => $dpi_used,
			'dpi_x'                               => round( $dpi_x, 2 ),
			'dpi_y'                               => round( $dpi_y, 2 ),
			'dpi_assumed'                         => $dpi_assumed,
			'dpi_message'                         => $dpi_message,
			'width_inches'                        => $width_inches,
			'height_inches'                       => $height_inches,
			'quality_rating'                      => $quality,
			'has_transparency'                    => $transparency['has_transparency'],
			'transparent_background'              => $transparency['transparent_background'],
			'transparent_background_ratio'        => $transparency['transparent_background_ratio'],
			'semi_transparent_pixels_detected'    => $transparency['semi_transparent_pixels_detected'],
			'sampled_pixel_count'                 => $transparency['sampled_pixel_count'],
			'dimension_warning'                   => $dimension_warning,
			'long_file_warning'                   => $long_file_warning,
			'warnings'                            => $warnings,
			'success_message'                     => empty( $warnings ) ? __( 'Success: this PNG passed the V1 artwork checks.', 'limitless-artwork-analyzer' ) : '',
		);
	}

	/**
	 * Read the PNG pHYs chunk and convert pixels-per-meter to DPI.
	 *
	 * @param string $file_path PNG path.
	 * @return array|null
	 */
	private function read_png_dpi( $file_path ) {
		$handle = @fopen( $file_path, 'rb' );

		if ( ! $handle ) {
			return null;
		}

		$signature = fread( $handle, 8 );

		if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
			fclose( $handle );
			return null;
		}

		while ( ! feof( $handle ) ) {
			$length_data = fread( $handle, 4 );

			if ( strlen( $length_data ) < 4 ) {
				break;
			}

			$length_parts = unpack( 'Nlength', $length_data );
			$length       = (int) $length_parts['length'];
			$type         = fread( $handle, 4 );

			if ( 'pHYs' === $type ) {
				$data = fread( $handle, $length );

				if ( strlen( $data ) >= 9 ) {
					$parts = unpack( 'Nx/Ny/Cunit', $data );

					if ( 1 === (int) $parts['unit'] && $parts['x'] > 0 && $parts['y'] > 0 ) {
						fclose( $handle );

						return array(
							'x' => round( $parts['x'] * 0.0254, 2 ),
							'y' => round( $parts['y'] * 0.0254, 2 ),
						);
					}
				}

				fclose( $handle );
				return null;
			}

			if ( 0 !== fseek( $handle, $length + 4, SEEK_CUR ) ) {
				break;
			}
		}

		fclose( $handle );
		return null;
	}

	/**
	 * Inspect transparency using sampled pixels.
	 *
	 * PNG alpha in this plugin is normalized so 0 is fully transparent and
	 * 255 is fully opaque, matching common design-tool language.
	 *
	 * @param resource|GdImage $image           GD image.
	 * @param int             $width           Pixel width.
	 * @param int             $height          Pixel height.
	 * @param int             $alpha_threshold Semi-transparent threshold.
	 * @return array
	 */
	private function inspect_transparency( $image, $width, $height, $alpha_threshold ) {
		$step                      = $this->get_sampling_step( $width, $height );
		$sampled_count             = 0;
		$has_transparency          = false;
		$semi_transparent_detected = false;

		for ( $y = 0; $y < $height; $y += $step ) {
			for ( $x = 0; $x < $width; $x += $step ) {
				$opacity = $this->get_pixel_opacity( $image, $x, $y );
				$sampled_count++;

				if ( $opacity < 255 ) {
					$has_transparency = true;
				}

				if ( $opacity > 0 && $opacity < $alpha_threshold ) {
					$semi_transparent_detected = true;
				}

				if ( $has_transparency && $semi_transparent_detected ) {
					break 2;
				}
			}
		}

		$edge_result = $this->inspect_edges_for_transparency( $image, $width, $height );

		return array(
			'has_transparency'                 => $has_transparency || $edge_result['has_transparency'],
			'transparent_background'           => $edge_result['transparent_background'],
			'transparent_background_ratio'     => $edge_result['transparent_background_ratio'],
			'semi_transparent_pixels_detected' => $semi_transparent_detected,
			'sampled_pixel_count'              => $sampled_count + $edge_result['sampled_pixel_count'],
		);
	}

	/**
	 * Check edge and corner pixels to estimate whether the background is transparent.
	 *
	 * @param resource|GdImage $image  GD image.
	 * @param int             $width  Pixel width.
	 * @param int             $height Pixel height.
	 * @return array
	 */
	private function inspect_edges_for_transparency( $image, $width, $height ) {
		$points_per_edge    = 25;
		$coordinates        = array();
		$transparent_count  = 0;
		$sampled_count      = 0;
		$has_transparency   = false;
		$transparent_cutoff = 5;

		for ( $i = 0; $i < $points_per_edge; $i++ ) {
			$x = (int) round( $i * ( $width - 1 ) / max( 1, $points_per_edge - 1 ) );
			$y = (int) round( $i * ( $height - 1 ) / max( 1, $points_per_edge - 1 ) );

			$coordinates[] = array( $x, 0 );
			$coordinates[] = array( $x, $height - 1 );
			$coordinates[] = array( 0, $y );
			$coordinates[] = array( $width - 1, $y );
		}

		$coordinates = $this->unique_coordinates( $coordinates );

		foreach ( $coordinates as $coordinate ) {
			$opacity = $this->get_pixel_opacity( $image, $coordinate[0], $coordinate[1] );
			$sampled_count++;

			if ( $opacity < 255 ) {
				$has_transparency = true;
			}

			if ( $opacity <= $transparent_cutoff ) {
				$transparent_count++;
			}
		}

		$ratio = $sampled_count > 0 ? $transparent_count / $sampled_count : 0;

		return array(
			'has_transparency'             => $has_transparency,
			'transparent_background'       => $ratio >= 0.75,
			'transparent_background_ratio' => round( $ratio, 2 ),
			'sampled_pixel_count'          => $sampled_count,
		);
	}

	/**
	 * Get normalized PNG opacity from a GD pixel.
	 *
	 * @param resource|GdImage $image GD image.
	 * @param int             $x     X coordinate.
	 * @param int             $y     Y coordinate.
	 * @return int 0 transparent, 255 opaque.
	 */
	private function get_pixel_opacity( $image, $x, $y ) {
		$color_index = imagecolorat( $image, $x, $y );
		$colors      = imagecolorsforindex( $image, $color_index );
		$gd_alpha    = isset( $colors['alpha'] ) ? (int) $colors['alpha'] : 0;

		return 255 - (int) round( $gd_alpha * 255 / 127 );
	}

	/**
	 * Choose a sampling step that keeps huge files from being scanned pixel-by-pixel.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return int
	 */
	private function get_sampling_step( $width, $height ) {
		$total_pixels = max( 1, $width * $height );

		if ( $total_pixels <= $this->max_samples ) {
			return 1;
		}

		return max( 1, (int) floor( sqrt( $total_pixels / $this->max_samples ) ) );
	}

	/**
	 * Remove duplicate coordinates.
	 *
	 * @param array $coordinates Coordinates.
	 * @return array
	 */
	private function unique_coordinates( $coordinates ) {
		$seen   = array();
		$unique = array();

		foreach ( $coordinates as $coordinate ) {
			$key = $coordinate[0] . ':' . $coordinate[1];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $coordinate;
		}

		return $unique;
	}

	/**
	 * Check whether either print dimension is in the target range.
	 *
	 * @param float $width_inches  Width in inches.
	 * @param float $height_inches Height in inches.
	 * @param float $min           Minimum inches.
	 * @param float $max           Maximum inches.
	 * @return bool
	 */
	private function has_dimension_between( $width_inches, $height_inches, $min, $max ) {
		$width_ok  = $width_inches >= $min && $width_inches <= $max;
		$height_ok = $height_inches >= $min && $height_inches <= $max;

		return $width_ok || $height_ok;
	}

	/**
	 * Rate DPI using the configured ranges.
	 *
	 * @param float $dpi      DPI used.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function get_quality_rating( $dpi, $settings ) {
		if ( $dpi < (int) $settings['poor_dpi_threshold'] ) {
			return __( 'Poor', 'limitless-artwork-analyzer' );
		}

		if ( $dpi >= (int) $settings['fair_dpi_min'] && $dpi <= (int) $settings['fair_dpi_max'] ) {
			return __( 'Fair', 'limitless-artwork-analyzer' );
		}

		if ( $dpi >= (int) $settings['good_dpi_min'] && $dpi <= (int) $settings['good_dpi_max'] ) {
			return __( 'Good', 'limitless-artwork-analyzer' );
		}

		if ( $dpi >= (int) $settings['excellent_dpi_threshold'] ) {
			return __( 'Excellent', 'limitless-artwork-analyzer' );
		}

		return __( 'Fair', 'limitless-artwork-analyzer' );
	}

	/**
	 * Format inch values for messages without unnecessary zeroes.
	 *
	 * @param float $value Inch value.
	 * @return string
	 */
	private function format_inches_for_message( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Read the most recent PHP error for logging.
	 *
	 * @return string
	 */
	private function get_last_php_error_message() {
		$error = error_get_last();

		if ( empty( $error['message'] ) ) {
			return 'No PHP error message was available.';
		}

		return 'Last PHP error: ' . $error['message'];
	}
}
