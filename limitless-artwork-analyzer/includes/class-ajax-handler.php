<?php
/**
 * AJAX upload and analysis handler.
 *
 * @package LimitlessArtworkAnalyzer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles PNG uploads from the product page.
 */
class LAA_Ajax_Handler {
	/**
	 * Analyzer instance.
	 *
	 * @var LAA_Analyzer
	 */
	private $analyzer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->analyzer = new LAA_Analyzer();

		add_action( 'wp_ajax_laa_analyze_artwork', array( $this, 'handle_analyze_request' ) );
		add_action( 'wp_ajax_nopriv_laa_analyze_artwork', array( $this, 'handle_analyze_request' ) );
	}

	/**
	 * Receive, validate, store, and analyze an uploaded PNG.
	 */
	public function handle_analyze_request() {
		try {
			$this->process_analyze_request();
		} catch ( Throwable $throwable ) {
			$this->send_error(
				__( 'The upload failed. Please try again. If it keeps happening, please contact us.', 'limitless-artwork-analyzer' ),
				500,
				'laa_unhandled_exception',
				$throwable->getMessage(),
				array(
					'file' => $throwable->getFile(),
					'line' => $throwable->getLine(),
				)
			);
		}
	}

	/**
	 * Internal upload handler.
	 *
	 * Keeping this separate lets handle_analyze_request() catch unexpected PHP
	 * errors without wrapping every small validation step in its own try/catch.
	 */
	private function process_analyze_request() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'laa_analyze_artwork' ) ) {
			$this->send_error(
				__( 'Security check failed. Please refresh the page and try again.', 'limitless-artwork-analyzer' ),
				403,
				'laa_nonce_failed',
				'wp_verify_nonce() failed for laa_analyze_artwork.'
			);
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$max_upload_size = wp_max_upload_size();

		if ( ! Limitless_Artwork_Analyzer::should_show_for_product( $product_id ) ) {
			$this->send_error(
				__( 'The artwork analyzer is not enabled for this product.', 'limitless-artwork-analyzer' ),
				403,
				'laa_product_not_enabled',
				'The product ID was not enabled for analysis.',
				array(
					'product_id' => $product_id,
				)
			);
		}

		if ( empty( $_FILES['artwork'] ) || ! is_array( $_FILES['artwork'] ) ) {
			$content_length = $this->get_request_content_length();

			if ( $content_length > $max_upload_size ) {
				$this->send_error(
					sprintf(
						/* translators: %s: upload size. */
						__( 'The PNG is larger than the current WordPress upload limit of %s. Please choose a smaller PNG or increase the upload limit.', 'limitless-artwork-analyzer' ),
						size_format( $max_upload_size )
					),
					413,
					'laa_request_too_large',
					'No $_FILES data was available. The request body may have exceeded upload_max_filesize or post_max_size.',
					array(
						'content_length_bytes' => $content_length,
						'server_limits'        => $this->get_server_limits(),
					)
				);
			}

			$this->send_error(
				__( 'No file was selected. Please choose a PNG file.', 'limitless-artwork-analyzer' ),
				400,
				'laa_no_file',
				'$_FILES["artwork"] was missing or invalid.',
				array(
					'content_length_bytes' => $content_length,
					'server_limits'        => $this->get_server_limits(),
				)
			);
		}

		$file = $_FILES['artwork'];
		$required_file_keys = array( 'name', 'tmp_name', 'error', 'size' );

		foreach ( $required_file_keys as $file_key ) {
			if ( ! array_key_exists( $file_key, $file ) ) {
				$this->send_error(
					__( 'The upload data was incomplete. Please choose a PNG file and try again.', 'limitless-artwork-analyzer' ),
					400,
					'laa_incomplete_upload_data',
					'The uploaded file array was missing a required key.',
					array(
						'missing_key' => $file_key,
						'file_keys'   => array_keys( $file ),
					)
				);
			}
		}

		if (
			is_array( $file['name'] )
			|| is_array( $file['tmp_name'] )
			|| is_array( $file['error'] )
			|| is_array( $file['size'] )
		) {
			$this->send_error(
				__( 'Please upload one PNG file at a time.', 'limitless-artwork-analyzer' ),
				400,
				'laa_multiple_files',
				'The uploaded file array looked like a multi-file upload.'
			);
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$upload_error_code = (int) $file['error'];

			$this->send_error(
				$this->get_upload_error_message( $upload_error_code, $max_upload_size ),
				$this->get_upload_error_status( $upload_error_code ),
				'laa_php_upload_error',
				'PHP reported upload error code ' . $upload_error_code . '.',
				array(
					'upload_error_code' => $upload_error_code,
					'file_name'         => sanitize_file_name( wp_unslash( $file['name'] ) ),
					'file_size_bytes'   => (int) $file['size'],
					'server_limits'     => $this->get_server_limits(),
				)
			);
		}

		$file_size       = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $file_size <= 0 ) {
			$this->send_error(
				__( 'The selected file is empty. Please choose a PNG file.', 'limitless-artwork-analyzer' ),
				400,
				'laa_empty_file',
				'The uploaded file size was zero bytes.',
				array(
					'file_name' => sanitize_file_name( wp_unslash( $file['name'] ) ),
				)
			);
		}

		if ( $file_size > $max_upload_size ) {
			$this->send_error(
				sprintf(
					/* translators: %s: upload size. */
					__( 'The PNG is larger than the current WordPress upload limit of %s. Please choose a smaller PNG or increase the upload limit.', 'limitless-artwork-analyzer' ),
					size_format( $max_upload_size )
				),
				413,
				'laa_file_too_large',
				'The uploaded file size exceeded wp_max_upload_size().',
				array(
					'file_name'       => sanitize_file_name( wp_unslash( $file['name'] ) ),
					'file_size_bytes' => $file_size,
					'server_limits'   => $this->get_server_limits(),
				)
			);
		}

		$tmp_name      = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$original_name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : 'artwork.png';

		if ( '' === $original_name ) {
			$original_name = 'artwork.png';
		}

		if ( ! is_uploaded_file( $tmp_name ) ) {
			$this->send_error(
				__( 'Upload validation failed. Please choose the PNG again and retry.', 'limitless-artwork-analyzer' ),
				400,
				'laa_uploaded_file_check_failed',
				'is_uploaded_file() returned false for the temporary upload path.',
				array(
					'tmp_name'  => $tmp_name,
					'file_name' => $original_name,
				)
			);
		}

		$validation = $this->validate_png_upload( $tmp_name, $original_name );

		if ( is_wp_error( $validation ) ) {
			$this->send_wp_error( $validation );
		}

		$upload_location = Limitless_Artwork_Analyzer::ensure_upload_directory();

		if ( is_wp_error( $upload_location ) ) {
			$this->send_wp_error( $upload_location, 500 );
		}

		$stored_file = $this->move_uploaded_png( $tmp_name, $original_name, $upload_location );

		if ( is_wp_error( $stored_file ) ) {
			$this->send_wp_error( $stored_file, 500 );
		}

		try {
			$analysis = $this->analyzer->analyze( $stored_file['path'], $original_name, Limitless_Artwork_Analyzer::get_settings() );
		} catch ( Throwable $throwable ) {
			@unlink( $stored_file['path'] );

			$this->send_error(
				__( 'This PNG could not be analyzed. It may be corrupted, too large, or saved in an unsupported PNG format.', 'limitless-artwork-analyzer' ),
				500,
				'laa_analyzer_exception',
				$throwable->getMessage(),
				array(
					'file_name' => $original_name,
					'file'      => $throwable->getFile(),
					'line'      => $throwable->getLine(),
				)
			);
		}

		if ( is_wp_error( $analysis ) ) {
			@unlink( $stored_file['path'] );
			$this->send_analysis_error( $analysis, $original_name );
		}

		$analysis['stored_file_name'] = $stored_file['name'];
		$analysis['file_path']        = $stored_file['path'];
		$analysis['file_url']         = $stored_file['url'];
		$analysis['product_id']       = $product_id;
		$analysis['created_at']       = current_time( 'mysql' );

		$token = wp_generate_password( 40, false, false );
		set_transient( 'laa_analysis_' . $token, $analysis, DAY_IN_SECONDS );

		wp_send_json_success(
			array(
				'token'    => $token,
				'analysis' => $analysis,
			)
		);
	}

	/**
	 * Validate that the upload really is a PNG.
	 *
	 * @param string $tmp_name      Temporary file path.
	 * @param string $original_name Original file name.
	 * @return true|WP_Error
	 */
	private function validate_png_upload( $tmp_name, $original_name ) {
		$signature = @file_get_contents( $tmp_name, false, null, 0, 8 );

		if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
			return new WP_Error(
				'laa_bad_png_signature',
				__( 'Only PNG files are supported for V1.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'The file did not start with the standard PNG signature.',
					'file_name'         => $original_name,
					'signature_length'  => is_string( $signature ) ? strlen( $signature ) : 0,
				)
			);
		}

		$filetype = wp_check_filetype_and_ext(
			$tmp_name,
			$original_name,
			array(
				'png' => 'image/png',
			)
		);

		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || 'png' !== $filetype['ext'] || 'image/png' !== $filetype['type'] ) {
			return new WP_Error(
				'laa_invalid_file_type',
				__( 'Only valid PNG files are supported. PDF, AI, SVG, JPG, and ZIP files are not supported yet.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'wp_check_filetype_and_ext() did not confirm a valid image/png file.',
					'file_name'         => $original_name,
					'filetype_result'   => $filetype,
				)
			);
		}

		$image_size = @getimagesize( $tmp_name );

		if ( false === $image_size || IMAGETYPE_PNG !== $image_size[2] ) {
			return new WP_Error(
				'laa_corrupt_png',
				__( 'This PNG could not be analyzed. It may be corrupted, too large, or saved in an unsupported PNG format.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'getimagesize() could not read the PNG as IMAGETYPE_PNG during upload validation.',
					'file_name'         => $original_name,
				)
			);
		}

		return true;
	}

	/**
	 * Move the uploaded PNG to the plugin upload folder.
	 *
	 * @param string $tmp_name        Temporary file path.
	 * @param string $original_name   Original file name.
	 * @param array  $upload_location Upload folder data.
	 * @return array|WP_Error
	 */
	private function move_uploaded_png( $tmp_name, $original_name, $upload_location ) {
		$base_name = pathinfo( $original_name, PATHINFO_FILENAME );
		$base_name = sanitize_file_name( $base_name );

		if ( '' === $base_name ) {
			$base_name = 'artwork';
		}

		$candidate_name = $base_name . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ) . '.png';
		$file_name      = wp_unique_filename( $upload_location['dir'], $candidate_name );
		$destination    = trailingslashit( $upload_location['dir'] ) . $file_name;

		if ( ! @move_uploaded_file( $tmp_name, $destination ) ) {
			return new WP_Error(
				'laa_move_failed',
				__( 'The upload failed while saving the PNG. Please try again.', 'limitless-artwork-analyzer' ),
				array(
					'technical_message' => 'move_uploaded_file() returned false.',
					'destination'       => $destination,
					'upload_dir'        => $upload_location['dir'],
					'is_writable'       => is_writable( $upload_location['dir'] ),
				)
			);
		}

		@chmod( $destination, 0644 );

		return array(
			'name' => $file_name,
			'path' => $destination,
			'url'  => trailingslashit( $upload_location['url'] ) . rawurlencode( $file_name ),
		);
	}

	/**
	 * Convert PHP upload error codes into friendly messages.
	 *
	 * @param int $error_code Upload error code.
	 * @param int $max_upload_size Maximum upload size in bytes.
	 * @return string
	 */
	private function get_upload_error_message( $error_code, $max_upload_size ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: upload size. */
					__( 'The selected PNG is too large for the current server upload limit of %s. Please choose a smaller PNG or increase the upload limit.', 'limitless-artwork-analyzer' ),
					size_format( $max_upload_size )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The PNG only uploaded partially. Please try again.', 'limitless-artwork-analyzer' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was selected. Please choose a PNG file.', 'limitless-artwork-analyzer' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'The server is missing a temporary upload folder.', 'limitless-artwork-analyzer' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded PNG to disk.', 'limitless-artwork-analyzer' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension stopped the upload.', 'limitless-artwork-analyzer' );
			default:
				return __( 'The PNG upload failed. Please try again.', 'limitless-artwork-analyzer' );
		}
	}

	/**
	 * Pick a suitable HTTP status for PHP upload errors.
	 *
	 * @param int $error_code Upload error code.
	 * @return int
	 */
	private function get_upload_error_status( $error_code ) {
		if ( UPLOAD_ERR_INI_SIZE === $error_code || UPLOAD_ERR_FORM_SIZE === $error_code ) {
			return 413;
		}

		if ( UPLOAD_ERR_NO_TMP_DIR === $error_code || UPLOAD_ERR_CANT_WRITE === $error_code || UPLOAD_ERR_EXTENSION === $error_code ) {
			return 500;
		}

		return 400;
	}

	/**
	 * Send a WP_Error through the standard AJAX error path.
	 *
	 * @param WP_Error $error  Error object.
	 * @param int      $status HTTP status.
	 */
	private function send_wp_error( $error, $status = 400 ) {
		$data = $error->get_error_data();

		$this->send_error(
			$error->get_error_message(),
			$status,
			$error->get_error_code(),
			$this->get_wp_error_technical_message( $error ),
			is_array( $data ) ? $data : array()
		);
	}

	/**
	 * Send analyzer-specific failures with the requested customer-facing copy.
	 *
	 * @param WP_Error $error              Analyzer error.
	 * @param string   $original_file_name Original upload name.
	 */
	private function send_analysis_error( $error, $original_file_name ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		$data['file_name'] = $original_file_name;

		$this->send_error(
			__( 'This PNG could not be analyzed. It may be corrupted, too large, or saved in an unsupported PNG format.', 'limitless-artwork-analyzer' ),
			400,
			$error->get_error_code(),
			$this->get_wp_error_technical_message( $error ),
			$data
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
	 * Get request body size from the server environment.
	 *
	 * @return int
	 */
	private function get_request_content_length() {
		if ( empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) );
	}

	/**
	 * Collect upload-related PHP limits for debugging.
	 *
	 * @return array
	 */
	private function get_server_limits() {
		$max_upload_size = wp_max_upload_size();

		return array(
			'wp_max_upload_size'       => size_format( $max_upload_size ),
			'wp_max_upload_size_bytes' => $max_upload_size,
			'upload_max_filesize'      => ini_get( 'upload_max_filesize' ),
			'post_max_size'            => ini_get( 'post_max_size' ),
			'memory_limit'             => ini_get( 'memory_limit' ),
			'max_file_uploads'         => ini_get( 'max_file_uploads' ),
		);
	}

	/**
	 * Send an AJAX error response.
	 *
	 * @param string $message           Customer-facing error message.
	 * @param int    $status            HTTP status.
	 * @param string $code              Error code.
	 * @param string $technical_message Technical detail for logs.
	 * @param array  $context           Extra debugging context.
	 */
	private function send_error( $message, $status = 400, $code = 'laa_upload_failed', $technical_message = '', $context = array() ) {
		$this->log_technical_error(
			$code,
			array(
				'customer_message'  => wp_strip_all_tags( $message ),
				'technical_message' => $technical_message,
				'status'            => $status,
				'context'           => $context,
			)
		);

		$response = array(
			'message' => $message,
			'code'    => $code,
			'debug'   => array(
				'technicalMessage' => $technical_message,
				'context'          => $context,
			),
		);

		wp_send_json_error(
			$response,
			$status
		);
	}

	/**
	 * Write technical details to the WordPress debug log.
	 *
	 * When WP_DEBUG_LOG is enabled, this goes to wp-content/debug.log.
	 *
	 * @param string $code    Error code.
	 * @param array  $context Error context.
	 */
	private function log_technical_error( $code, $context ) {
		$log_context = wp_json_encode( $context );

		if ( false === $log_context ) {
			$log_context = 'Unable to JSON encode error context.';
		}

		error_log( '[Limitless Artwork Analyzer] ' . $code . ' ' . $log_context );
	}
}
