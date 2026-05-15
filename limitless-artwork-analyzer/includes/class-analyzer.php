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
	 * Files at or below this size should normally receive full analysis.
	 *
	 * This protects small customer files from being downgraded because a local
	 * WordPress request happens to have conservative reported free memory.
	 *
	 * @var int
	 */
	private $small_file_full_scan_pixels = 10000000;

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

		$this->log_step(
			'image info read',
			array(
				'file_path'    => $file_path,
				'image_width'  => isset( $image_size[0] ) ? (int) $image_size[0] : 0,
				'image_height' => isset( $image_size[1] ) ? (int) $image_size[1] : 0,
				'image_type'   => isset( $image_size[2] ) ? (int) $image_size[2] : 0,
				'mime'         => isset( $image_size['mime'] ) ? $image_size['mime'] : '',
			)
		);

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

		$pixel_count = (int) ( (float) $pixel_width * (float) $pixel_height );
		$scan_plan   = $this->get_scan_plan( $pixel_count, $settings );

		$this->log_step(
			'scan threshold decision',
			array(
				'pixel_width'            => $pixel_width,
				'pixel_height'           => $pixel_height,
				'calculated_pixel_count' => $pixel_count,
				'full_scan_threshold'    => $scan_plan['full_limit'],
				'sampled_scan_threshold' => $scan_plan['sampled_limit'],
				'selected_scan_path'     => $scan_plan['mode'],
			)
		);

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
		$warnings      = array();
		$skipped_checks = array();
		$skip_reason    = '';
		$alpha_settings = $this->get_semi_transparent_alpha_settings( $settings );
		$transparency   = $this->get_skipped_transparency_result( array(), '', $alpha_settings );

		if ( 'skipped' === $scan_plan['mode'] ) {
			$warnings[]      = __( 'This PNG uploaded successfully, but it is too large to fully analyze in this local test environment.', 'limitless-artwork-analyzer' );
			$skipped_checks  = $this->get_transparency_check_names();
			$skip_reason     = __( 'File pixel count exceeds the sampled scan limit.', 'limitless-artwork-analyzer' );
			$transparency    = $this->get_skipped_transparency_result( $skipped_checks, $skip_reason, $alpha_settings );
			$this->log_skipped_sampling( $pixel_width, $pixel_height, $scan_plan, $skipped_checks, $skip_reason );
		} else {
			$scan_skip = $this->get_scan_skip_reason( $pixel_width, $pixel_height, $pixel_count );

			if ( '' !== $scan_skip ) {
				$warnings[]      = $scan_skip;
				$skipped_checks  = $this->get_transparency_check_names();
				$skip_reason     = $scan_skip;
				$transparency    = $this->get_skipped_transparency_result( $skipped_checks, $skip_reason, $alpha_settings );
				$this->log_skipped_sampling( $pixel_width, $pixel_height, $scan_plan, $skipped_checks, $skip_reason );
			} else {
				$image = @imagecreatefrompng( $file_path );

				if ( ! $image ) {
					$warnings[]      = __( 'Transparency checks were skipped because this PNG could not be opened for pixel analysis.', 'limitless-artwork-analyzer' );
					$skipped_checks  = $this->get_transparency_check_names();
					$skip_reason     = 'imagecreatefrompng() returned false. ' . $this->get_last_php_error_message();
					$transparency    = $this->get_skipped_transparency_result( $skipped_checks, $skip_reason, $alpha_settings );
					$this->log_skipped_sampling( $pixel_width, $pixel_height, $scan_plan, $skipped_checks, $skip_reason );
				} else {
					$transparency = $this->inspect_transparency( $image, $pixel_width, $pixel_height, $alpha_settings, $scan_plan );
					imagedestroy( $image );
				}
			}
		}

		$dimension_warning = ! $this->has_dimension_between(
			$width_inches,
			$height_inches,
			(float) $settings['min_dimension_inches'],
			(float) $settings['max_print_width_inches']
		);
		$long_file_warning = $width_inches > (float) $settings['long_png_warning_inches'] || $height_inches > (float) $settings['long_png_warning_inches'];

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

		if ( false === $transparency['transparent_background'] ) {
			$warnings[] = __( 'A transparent background was not detected around the artwork edges. Please make sure the PNG is saved with transparency.', 'limitless-artwork-analyzer' );
		}

		return array(
			'original_file_name'                  => sanitize_file_name( $original_file_name ),
			'pixel_width'                         => $pixel_width,
			'pixel_height'                        => $pixel_height,
			'pixel_count'                         => $pixel_count,
			'dpi_used'                            => $dpi_used,
			'dpi_x'                               => round( $dpi_x, 2 ),
			'dpi_y'                               => round( $dpi_y, 2 ),
			'dpi_assumed'                         => $dpi_assumed,
			'dpi_message'                         => $dpi_message,
			'width_inches'                        => $width_inches,
			'height_inches'                       => $height_inches,
			'quality_rating'                      => $quality,
			'scan_mode'                           => $scan_plan['mode'],
			'scan_mode_label'                     => $scan_plan['label'],
			'skipped_checks'                      => $skipped_checks,
			'skipped_check_reason'                => $skip_reason,
			'has_transparency'                    => $transparency['has_transparency'],
			'transparent_background'              => $transparency['transparent_background'],
			'transparent_background_ratio'        => $transparency['transparent_background_ratio'],
			'semi_transparent_pixels_detected'    => $transparency['semi_transparent_pixels_detected'],
			'semi_transparent_pixel_count'        => $transparency['semi_transparent_pixel_count'],
			'semi_transparent_pixel_percentage'   => $transparency['semi_transparent_pixel_percentage'],
			'semi_transparent_edge_pixels'        => $transparency['semi_transparent_edge_pixels'],
			'semi_transparent_interior_pixels'    => $transparency['semi_transparent_interior_pixels'],
			'semi_transparent_interior_percentage' => $transparency['semi_transparent_interior_percentage'],
			'alpha_thresholds_used'               => $transparency['alpha_thresholds_used'],
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
	 * Normalize semi-transparent alpha settings.
	 *
	 * Opacity is normalized so 0 is transparent and 255 is opaque. The detector
	 * counts only pixels between the lower and upper thresholds, which avoids
	 * treating normal transparent/opaque anti-aliasing as a DTF warning.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function get_semi_transparent_alpha_settings( $settings ) {
		$lower      = isset( $settings['semi_transparent_alpha_lower_threshold'] ) ? absint( $settings['semi_transparent_alpha_lower_threshold'] ) : 20;
		$upper      = isset( $settings['semi_transparent_alpha_upper_threshold'] ) ? absint( $settings['semi_transparent_alpha_upper_threshold'] ) : 235;
		$percentage = isset( $settings['semi_transparent_pixel_percentage_threshold'] ) ? (float) $settings['semi_transparent_pixel_percentage_threshold'] : 0.25;
		$ignore_edge_antialiasing = ! isset( $settings['ignore_edge_antialiasing'] ) || 'yes' === $settings['ignore_edge_antialiasing'];

		$lower      = min( 255, max( 0, $lower ) );
		$upper      = min( 255, max( 0, $upper ) );
		$percentage = min( 100, max( 0, $percentage ) );

		if ( $lower > $upper ) {
			$temp  = $lower;
			$lower = $upper;
			$upper = $temp;
		}

		if ( $lower === $upper ) {
			if ( $upper < 255 ) {
				$upper++;
			} else {
				$lower--;
			}
		}

		return array(
			'lower'                    => $lower,
			'upper'                    => $upper,
			'percentage'               => $percentage,
			'ignore_edge_antialiasing' => $ignore_edge_antialiasing,
		);
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
	 * @param array           $alpha_settings  Semi-transparent detection settings.
	 * @return array
	 */
	private function inspect_transparency( $image, $width, $height, $alpha_settings, $scan_plan ) {
		$step                                  = $this->get_sampling_step( $width, $height, $scan_plan['mode'] );
		$sampled_count                         = 0;
		$has_transparency                      = false;
		$semi_transparent_pixel_count          = 0;
		$semi_transparent_edge_pixels          = 0;
		$semi_transparent_interior_pixels      = 0;
		$lower_threshold                       = $alpha_settings['lower'];
		$upper_threshold                       = $alpha_settings['upper'];
		$percentage_threshold                  = $alpha_settings['percentage'];
		$ignore_edge_antialiasing              = ! empty( $alpha_settings['ignore_edge_antialiasing'] );

		$this->log_step(
			'pixel sampling started',
			array(
				'pixel_width'                            => $width,
				'pixel_height'                           => $height,
				'scan_mode'                              => $scan_plan['mode'],
				'sampling_step'                          => $step,
				'semi_transparent_lower_alpha_threshold' => $lower_threshold,
				'semi_transparent_upper_alpha_threshold' => $upper_threshold,
				'semi_transparent_percentage_threshold'  => $percentage_threshold,
				'ignore_edge_antialiasing'               => $ignore_edge_antialiasing,
			)
		);

		for ( $y = 0; $y < $height; $y += $step ) {
			for ( $x = 0; $x < $width; $x += $step ) {
				$opacity = $this->get_pixel_opacity( $image, $x, $y );
				$sampled_count++;

				if ( $opacity < 255 ) {
					$has_transparency = true;
				}

				if ( $opacity > $lower_threshold && $opacity < $upper_threshold ) {
					$semi_transparent_pixel_count++;

					if (
						$ignore_edge_antialiasing
						&& $this->is_edge_antialiasing_pixel( $image, $x, $y, $width, $height, $lower_threshold, $upper_threshold )
					) {
						$semi_transparent_edge_pixels++;
					} else {
						$semi_transparent_interior_pixels++;
					}
				}
			}
		}

		$semi_transparent_percentage          = $sampled_count > 0 ? round( ( $semi_transparent_pixel_count / $sampled_count ) * 100, 4 ) : 0;
		$semi_transparent_interior_percentage = $sampled_count > 0 ? round( ( $semi_transparent_interior_pixels / $sampled_count ) * 100, 4 ) : 0;
		$semi_transparent_detected            = $semi_transparent_interior_percentage > $percentage_threshold;
		$edge_result                          = $this->inspect_edges_for_transparency( $image, $width, $height );
		$result                               = array(
			'has_transparency'                 => $has_transparency || $edge_result['has_transparency'],
			'transparent_background'           => $edge_result['transparent_background'],
			'transparent_background_ratio'     => $edge_result['transparent_background_ratio'],
			'semi_transparent_pixels_detected' => $semi_transparent_detected,
			'semi_transparent_pixel_count'     => $semi_transparent_pixel_count,
			'semi_transparent_pixel_percentage' => $semi_transparent_percentage,
			'semi_transparent_edge_pixels'     => $semi_transparent_edge_pixels,
			'semi_transparent_interior_pixels' => $semi_transparent_interior_pixels,
			'semi_transparent_interior_percentage' => $semi_transparent_interior_percentage,
			'alpha_thresholds_used'            => $alpha_settings,
			'sampled_pixel_count'              => $sampled_count + $edge_result['sampled_pixel_count'],
		);

		$this->log_step(
			'pixel sampling completed',
			array(
				'sampled_pixel_count'              => $result['sampled_pixel_count'],
				'scan_mode'                        => $scan_plan['mode'],
				'has_transparency'                 => $result['has_transparency'],
				'transparent_background'           => $result['transparent_background'],
				'semi_transparent_pixels_detected' => $result['semi_transparent_pixels_detected'],
				'semi_transparent_pixel_count'     => $result['semi_transparent_pixel_count'],
				'semi_transparent_pixel_percentage' => $result['semi_transparent_pixel_percentage'],
				'semi_transparent_edge_pixels'     => $result['semi_transparent_edge_pixels'],
				'semi_transparent_interior_pixels' => $result['semi_transparent_interior_pixels'],
				'semi_transparent_interior_percentage' => $result['semi_transparent_interior_percentage'],
				'alpha_thresholds_used'            => $result['alpha_thresholds_used'],
			)
		);

		return $result;
	}

	/**
	 * Decide whether a semi-transparent pixel looks like normal edge smoothing.
	 *
	 * A typical anti-aliased artwork edge has semi-transparent pixels directly
	 * between transparent background pixels and opaque artwork pixels. Interior
	 * fades, shadows, glows, and overlays usually have nearby semi-transparent
	 * pixels without both sides of that transparent-to-opaque edge transition.
	 *
	 * @param resource|GdImage $image           GD image.
	 * @param int             $x               X coordinate.
	 * @param int             $y               Y coordinate.
	 * @param int             $width           Pixel width.
	 * @param int             $height          Pixel height.
	 * @param int             $lower_threshold Transparent-like cutoff.
	 * @param int             $upper_threshold Opaque-like cutoff.
	 * @return bool
	 */
	private function is_edge_antialiasing_pixel( $image, $x, $y, $width, $height, $lower_threshold, $upper_threshold ) {
		$radius          = 2;
		$has_transparent = false;
		$has_opaque      = false;

		for ( $offset_y = -$radius; $offset_y <= $radius; $offset_y++ ) {
			for ( $offset_x = -$radius; $offset_x <= $radius; $offset_x++ ) {
				if ( 0 === $offset_x && 0 === $offset_y ) {
					continue;
				}

				$neighbor_x = $x + $offset_x;
				$neighbor_y = $y + $offset_y;

				if ( $neighbor_x < 0 || $neighbor_y < 0 || $neighbor_x >= $width || $neighbor_y >= $height ) {
					continue;
				}

				$neighbor_opacity = $this->get_pixel_opacity( $image, $neighbor_x, $neighbor_y );

				if ( $neighbor_opacity <= $lower_threshold ) {
					$has_transparent = true;
				}

				if ( $neighbor_opacity >= $upper_threshold ) {
					$has_opaque = true;
				}

				if ( $has_transparent && $has_opaque ) {
					return true;
				}
			}
		}

		return false;
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
	private function get_sampling_step( $width, $height, $scan_mode ) {
		if ( 'full' === $scan_mode ) {
			return 1;
		}

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
	 * Decide how deeply to scan the image based on pixel count settings.
	 *
	 * @param int   $pixel_count Pixel count.
	 * @param array $settings    Plugin settings.
	 * @return array
	 */
	private function get_scan_plan( $pixel_count, $settings ) {
		$full_limit    = isset( $settings['max_full_scan_pixels'] ) ? max( 1, absint( $settings['max_full_scan_pixels'] ) ) : 50000000;
		$sampled_limit = isset( $settings['max_sampled_scan_pixels'] ) ? max( 1, absint( $settings['max_sampled_scan_pixels'] ) ) : 250000000;
		$full_limit    = max( $full_limit, $this->small_file_full_scan_pixels );

		if ( $sampled_limit < $full_limit ) {
			$sampled_limit = $full_limit;
		}

		if ( $pixel_count <= $full_limit ) {
			return array(
				'mode'          => 'full',
				'label'         => __( 'Full scan', 'limitless-artwork-analyzer' ),
				'full_limit'    => $full_limit,
				'sampled_limit' => $sampled_limit,
			);
		}

		if ( $pixel_count <= $sampled_limit ) {
			return array(
				'mode'          => 'sampled',
				'label'         => __( 'Sampled scan', 'limitless-artwork-analyzer' ),
				'full_limit'    => $full_limit,
				'sampled_limit' => $sampled_limit,
			);
		}

		return array(
			'mode'          => 'skipped',
			'label'         => __( 'Basic dimensions only', 'limitless-artwork-analyzer' ),
			'full_limit'    => $full_limit,
			'sampled_limit' => $sampled_limit,
		);
	}

	/**
	 * Names of expensive checks that require opening the PNG pixel data.
	 *
	 * @return array
	 */
	private function get_transparency_check_names() {
		return array(
			__( 'Transparent background check', 'limitless-artwork-analyzer' ),
			__( 'Semi-transparent pixel check', 'limitless-artwork-analyzer' ),
		);
	}

	/**
	 * Build a consistent transparency result when pixel checks are skipped.
	 *
	 * @param array  $skipped_checks Skipped check names.
	 * @param string $reason         Skip reason.
	 * @param array  $alpha_settings Semi-transparent detection settings.
	 * @return array
	 */
	private function get_skipped_transparency_result( $skipped_checks = array(), $reason = '', $alpha_settings = array() ) {
		return array(
			'has_transparency'                   => null,
			'transparent_background'             => null,
			'transparent_background_ratio'       => null,
			'semi_transparent_pixels_detected'   => null,
			'semi_transparent_pixel_count'       => null,
			'semi_transparent_pixel_percentage'  => null,
			'semi_transparent_edge_pixels'       => null,
			'semi_transparent_interior_pixels'   => null,
			'semi_transparent_interior_percentage' => null,
			'alpha_thresholds_used'              => $alpha_settings,
			'sampled_pixel_count'                => 0,
			'skipped_checks'                     => $skipped_checks,
			'skipped_check_reason'               => $reason,
		);
	}

	/**
	 * Decide whether the server can safely open the PNG for pixel checks.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return string Empty when scanning can proceed, otherwise customer-facing skip reason.
	 */
	private function get_scan_skip_reason( $width, $height, $pixel_count ) {
		if ( ! extension_loaded( 'gd' ) ) {
			$this->log_scan_skip_reason( $width, $height, $pixel_count, 'GD extension is not loaded.' );
			return __( 'Transparency checks were skipped because PHP GD is not available in this local environment.', 'limitless-artwork-analyzer' );
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			$this->log_scan_skip_reason( $width, $height, $pixel_count, 'imagecreatefrompng() is not available.' );
			return __( 'Transparency checks were skipped because PNG pixel analysis is not available in this local environment.', 'limitless-artwork-analyzer' );
		}

		$memory_check = $this->check_image_memory_safety( $width, $height, $pixel_count );

		if ( is_wp_error( $memory_check ) ) {
			$this->log_scan_skip_reason( $width, $height, $pixel_count, $this->get_wp_error_technical_message( $memory_check ), $memory_check->get_error_data() );
			return __( 'Transparency checks were skipped because this PNG is too large for this local test environment to scan safely.', 'limitless-artwork-analyzer' );
		}

		$this->log_step(
			'scan skip decision',
			array(
				'pixel_width'            => $width,
				'pixel_height'           => $height,
				'calculated_pixel_count' => $pixel_count,
				'skip_reason'            => '',
				'will_skip_checks'       => false,
			)
		);

		return '';
	}

	/**
	 * Log why the transparency checks are being skipped.
	 *
	 * @param int    $width       Pixel width.
	 * @param int    $height      Pixel height.
	 * @param int    $pixel_count Pixel count.
	 * @param string $reason      Technical reason.
	 * @param mixed  $details     Optional details.
	 */
	private function log_scan_skip_reason( $width, $height, $pixel_count, $reason, $details = array() ) {
		$this->log_step(
			'scan skip decision',
			array(
				'pixel_width'            => $width,
				'pixel_height'           => $height,
				'calculated_pixel_count' => $pixel_count,
				'skip_reason'            => $reason,
				'skip_details'           => $details,
				'will_skip_checks'       => true,
			)
		);
	}

	/**
	 * Get a useful technical message from a WP_Error.
	 *
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	private function get_wp_error_technical_message( $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['technical_message'] ) ) {
			return (string) $data['technical_message'];
		}

		return $error->get_error_message();
	}

	/**
	 * Log skipped sampling with the same start/completed milestones as real scans.
	 *
	 * @param int    $width          Pixel width.
	 * @param int    $height         Pixel height.
	 * @param array  $scan_plan      Scan plan.
	 * @param array  $skipped_checks Skipped checks.
	 * @param string $reason         Skip reason.
	 */
	private function log_skipped_sampling( $width, $height, $scan_plan, $skipped_checks, $reason ) {
		$this->log_step(
			'pixel sampling started',
			array(
				'pixel_width'    => $width,
				'pixel_height'   => $height,
				'scan_mode'      => $scan_plan['mode'],
				'will_skip_scan' => true,
				'reason'         => $reason,
			)
		);

		$this->log_step(
			'pixel sampling completed',
			array(
				'scan_mode'           => $scan_plan['mode'],
				'sampled_pixel_count' => 0,
				'skipped_checks'      => $skipped_checks,
				'reason'              => $reason,
			)
		);
	}

	/**
	 * Check whether GD can safely expand this PNG into memory.
	 *
	 * Compressed PNG files can be small on disk but very large once GD opens
	 * them. This preflight prevents common LocalWP memory-limit crashes.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return true|WP_Error
	 */
	private function check_image_memory_safety( $width, $height, $pixel_count ) {
		$memory_limit_bytes = $this->parse_size_to_bytes( ini_get( 'memory_limit' ) );

		if ( $memory_limit_bytes < 0 ) {
			return true;
		}

		if ( $memory_limit_bytes <= 0 ) {
			return true;
		}

		$current_usage_bytes = memory_get_usage( true );
		$available_bytes     = max( 0, $memory_limit_bytes - $current_usage_bytes );
		$estimated_bytes     = $this->estimate_gd_memory_bytes( $width, $height );
		$reserve_bytes       = 16 * MB_IN_BYTES;
		$safe_available      = max( 0, $available_bytes - $reserve_bytes );
		$hard_limit_bytes    = (int) floor( $memory_limit_bytes * 0.85 );

		$this->log_step(
			'memory safety estimate',
			array(
				'pixel_width'            => $width,
				'pixel_height'           => $height,
				'calculated_pixel_count' => $pixel_count,
				'estimated_bytes'        => $estimated_bytes,
				'memory_limit_bytes'     => $memory_limit_bytes,
				'current_usage_bytes'    => $current_usage_bytes,
				'available_bytes'        => $available_bytes,
				'safe_available_bytes'   => $safe_available,
				'hard_limit_bytes'       => $hard_limit_bytes,
				'small_file_floor'       => $this->small_file_full_scan_pixels,
			)
		);

		if ( $pixel_count <= $this->small_file_full_scan_pixels && $estimated_bytes <= $hard_limit_bytes ) {
			return true;
		}

		if ( $estimated_bytes > $safe_available ) {
			return new WP_Error(
				'laa_image_too_large_for_memory',
				__( 'Transparency checks were skipped because this PNG is too large for this local test environment to scan safely.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message'     => 'Estimated GD memory usage is higher than the safety-adjusted available PHP memory.',
					'pixel_width'           => $width,
					'pixel_height'          => $height,
					'estimated_bytes'       => $estimated_bytes,
					'memory_limit_bytes'    => $memory_limit_bytes,
					'current_usage_bytes'   => $current_usage_bytes,
					'available_bytes'       => $available_bytes,
					'safe_available_bytes'  => $safe_available,
					'hard_limit_bytes'      => $hard_limit_bytes,
					'pixel_count'           => $pixel_count,
					'php_memory_limit'      => ini_get( 'memory_limit' ),
				)
			);
		}

		return true;
	}

	/**
	 * Estimate memory needed for GD to decode and inspect the PNG.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return int
	 */
	private function estimate_gd_memory_bytes( $width, $height ) {
		$pixels = (float) $width * (float) $height;

		return (int) ceil( ( $pixels * 5 ) + ( 16 * MB_IN_BYTES ) );
	}

	/**
	 * Convert PHP shorthand sizes like 256M into bytes.
	 *
	 * @param string|false $size Size value.
	 * @return int
	 */
	private function parse_size_to_bytes( $size ) {
		if ( false === $size ) {
			return 0;
		}

		$size = trim( (string) $size );

		if ( '-1' === $size ) {
			return -1;
		}

		if ( '' === $size ) {
			return 0;
		}

		$unit  = strtolower( substr( $size, -1 ) );
		$value = (float) $size;

		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
				break;
		}

		return (int) $value;
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

	/**
	 * Write analyzer step details to the WordPress debug log.
	 *
	 * @param string $step    Step name.
	 * @param array  $context Extra context.
	 */
	private function log_step( $step, $context = array() ) {
		$log_context = wp_json_encode( $context );

		if ( false === $log_context ) {
			$log_context = 'Unable to JSON encode analyzer step context.';
		}

		error_log( '[Limitless Artwork Analyzer] step: ' . $step . ' ' . $log_context );
	}
}
