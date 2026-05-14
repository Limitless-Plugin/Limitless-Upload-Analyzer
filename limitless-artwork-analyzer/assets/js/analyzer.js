(function ($) {
	'use strict';

	/**
	 * Small helper to keep rendered AJAX data safe in the browser.
	 *
	 * @param {string|number|boolean} value Value to escape.
	 * @return {string}
	 */
	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Format true/false values for display.
	 *
	 * @param {boolean} value Boolean value.
	 * @return {string}
	 */
	function yesNo(value) {
		return value ? 'Yes' : 'No';
	}

	/**
	 * Create one result row.
	 *
	 * @param {string} label Result label.
	 * @param {string} value Result value.
	 * @return {string}
	 */
	function resultRow(label, value) {
		return '<div class="laa-result-row"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
	}

	/**
	 * Log failed upload details for local debugging.
	 *
	 * @param {string} label Log label.
	 * @param {*} details AJAX response details.
	 */
	function logUploadFailure(label, details) {
		if (window.console && window.console.error) {
			window.console.error('[Limitless Artwork Analyzer] ' + label, details);
		}
	}

	/**
	 * Render analysis results into the UI.
	 *
	 * @param {jQuery} $results Results container.
	 * @param {Object} analysis Analysis response.
	 */
	function renderResults($results, analysis) {
		var html = '';
		var warnings = Array.isArray(analysis.warnings) ? analysis.warnings : [];

		html += '<div class="laa-result-grid">';
		html += resultRow('Pixel dimensions', analysis.pixel_width + ' x ' + analysis.pixel_height + ' px');
		html += resultRow('Print dimensions', analysis.width_inches + '" x ' + analysis.height_inches + '"');
		html += resultRow('DPI used', analysis.dpi_used + (analysis.dpi_assumed ? ' (assumed)' : ''));
		html += resultRow('Quality rating', analysis.quality_rating);
		html += resultRow('Has transparency', yesNo(analysis.has_transparency));
		html += resultRow('Transparent background', yesNo(analysis.transparent_background));
		html += resultRow('Semi-transparent pixels', yesNo(analysis.semi_transparent_pixels_detected));
		html += '</div>';

		if (analysis.dpi_message) {
			html += '<div class="laa-notice laa-notice--info">' + escapeHtml(analysis.dpi_message) + '</div>';
		}

		if (warnings.length) {
			html += '<div class="laa-notice laa-notice--warning"><strong>Warnings</strong><ul>';

			warnings.forEach(function (warning) {
				html += '<li>' + escapeHtml(warning) + '</li>';
			});

			html += '</ul></div>';
		} else if (analysis.success_message) {
			html += '<div class="laa-notice laa-notice--success">' + escapeHtml(analysis.success_message) + '</div>';
		}

		$results.html(html).prop('hidden', false);
	}

	/**
	 * Start the upload/analyze request.
	 *
	 * @param {File} file Selected PNG file.
	 * @param {jQuery} $box Analyzer box.
	 */
	function analyzeFile(file, $box) {
		var $summary = $box.find('.laa-file-summary');
		var $status = $box.find('.laa-status');
		var $results = $box.find('.laa-results');
		var $token = $box.find('.laa-analysis-token');
		var $browseButton = $box.find('.laa-browse-button');
		var productId = $box.data('product-id') || LAAAnalyzer.productId;
		var formData = new FormData();

		$token.val('');
		$results.empty().prop('hidden', true);
		$status.removeClass('is-error is-success').addClass('is-loading').text(LAAAnalyzer.i18n.analyzing);
		$summary.prop('hidden', false);
		$browseButton.prop('disabled', true);
		$box.addClass('is-analyzing');

		formData.append('action', 'laa_analyze_artwork');
		formData.append('nonce', LAAAnalyzer.nonce);
		formData.append('product_id', productId);
		formData.append('artwork', file);

		$.ajax({
			url: LAAAnalyzer.ajaxUrl,
			type: 'POST',
			data: formData,
			contentType: false,
			processData: false
		})
			.done(function (response) {
				if (!response || !response.success) {
					var message = response && response.data && response.data.message ? response.data.message : LAAAnalyzer.i18n.uploadFailed;
					logUploadFailure('AJAX request returned an error response.', response);
					$status.removeClass('is-loading is-success').addClass('is-error').text(message);
					return;
				}

				$token.val(response.data.token);
				$status.removeClass('is-loading is-error').addClass('is-success').text(LAAAnalyzer.i18n.ready);
				renderResults($results, response.data.analysis);
			})
			.fail(function (xhr) {
				var message = LAAAnalyzer.i18n.uploadFailed;
				var failureDetails = {
					status: xhr.status,
					statusText: xhr.statusText,
					responseJSON: xhr.responseJSON || null,
					responseText: xhr.responseText || ''
				};

				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}

				logUploadFailure('AJAX request failed.', failureDetails);
				$status.removeClass('is-loading is-success').addClass('is-error').text(message);
			})
			.always(function () {
				$browseButton.prop('disabled', false);
				$box.removeClass('is-analyzing');
			});
	}

	/**
	 * Validate a selected file before sending it to PHP.
	 *
	 * @param {File} file Selected file.
	 * @return {string}
	 */
	function getClientFileError(file) {
		if (!file) {
			return LAAAnalyzer.i18n.choosePng;
		}

		if (file.type && file.type !== 'image/png' && file.type !== 'image/x-png') {
			return LAAAnalyzer.i18n.pngOnly;
		}

		if (!file.name.toLowerCase().endsWith('.png')) {
			return LAAAnalyzer.i18n.pngOnly;
		}

		if (file.size > Number(LAAAnalyzer.maxUploadBytes)) {
			return LAAAnalyzer.i18n.tooLarge + ' (' + LAAAnalyzer.maxUploadLabel + ')';
		}

		return '';
	}

	/**
	 * Display the selected file name and preview image.
	 *
	 * @param {File} file Selected PNG.
	 * @param {jQuery} $box Analyzer box.
	 */
	function showFilePreview(file, $box) {
		var reader = new FileReader();
		var $summary = $box.find('.laa-file-summary');
		var $preview = $box.find('.laa-preview');

		$box.find('.laa-file-name').text(file.name);
		$summary.prop('hidden', false);

		reader.onload = function (event) {
			$preview.attr('src', event.target.result);
		};

		reader.readAsDataURL(file);
	}

	/**
	 * Handle a newly selected or dropped file.
	 *
	 * @param {File} file Selected PNG.
	 * @param {jQuery} $box Analyzer box.
	 */
	function handleFile(file, $box) {
		var error = getClientFileError(file);
		var $summary = $box.find('.laa-file-summary');
		var $status = $box.find('.laa-status');

		$box.find('.laa-analysis-token').val('');

		if (error) {
			$summary.prop('hidden', false);
			$status.removeClass('is-loading is-success').addClass('is-error').text(error);
			return;
		}

		showFilePreview(file, $box);
		analyzeFile(file, $box);
	}

	$(function () {
		$('.laa-analyzer').each(function () {
			var $box = $(this);
			var $dropzone = $box.find('.laa-dropzone');
			var $fileInput = $box.find('.laa-file-input');
			var $browseButton = $box.find('.laa-browse-button');
			var $form = $box.closest('form.cart');

			$browseButton.on('click', function () {
				$fileInput.trigger('click');
			});

			$fileInput.on('change', function () {
				handleFile(this.files[0], $box);
			});

			$dropzone.on('dragenter dragover', function (event) {
				event.preventDefault();
				event.stopPropagation();
				$dropzone.addClass('is-dragging');
			});

			$dropzone.on('dragleave dragend drop', function (event) {
				event.preventDefault();
				event.stopPropagation();
				$dropzone.removeClass('is-dragging');
			});

			$dropzone.on('drop', function (event) {
				var files = event.originalEvent.dataTransfer.files;

				if (files && files.length) {
					handleFile(files[0], $box);
				}
			});

			$dropzone.on('keydown', function (event) {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					$fileInput.trigger('click');
				}
			});

			$form.on('submit', function (event) {
				if ($box.hasClass('is-analyzing')) {
					event.preventDefault();
					$box.find('.laa-status').removeClass('is-success').addClass('is-error').text(LAAAnalyzer.i18n.waitForAnalysis);
				}
			});
		});
	});
})(jQuery);
