(function ($) {
	'use strict';

	var currentJob = null;
	var running = false;

	function post(action, data) {
		return $.post(BlueprintBundleMaker.ajaxUrl, $.extend({
			action: action,
			nonce: BlueprintBundleMaker.nonce
		}, data || {}));
	}

	function setBusy(isBusy) {
		running = isBusy;
		$('#bbm-start').prop('disabled', isBusy);
		$('#bbm-cancel').prop('disabled', !isBusy || !currentJob);
	}

	function render(job) {
		var percent = Math.max(0, Math.min(100, parseInt(job.percent, 10) || 0));
		var counts = job.counts || {};

		$('#bbm-progress-bar').css('width', percent + '%');
		$('#bbm-status').text(job.message || '');
		$('#bbm-stage').text(job.stage || '-');
		$('#bbm-files').text(counts.scanned_files || 0);
		$('#bbm-zipped').text((counts.zipped_files || 0) + ' / ' + (counts.processed_files || 0));
		$('#bbm-skipped').text((counts.skipped_files || 0) + ' / ' + (counts.excluded || 0));

		if (job.warnings && job.warnings.length) {
			$('#bbm-warnings')
				.prop('hidden', false)
				.html('<strong>Warnings</strong><ul>' + job.warnings.map(function (warning) {
					return '<li>' + $('<div>').text(warning).html() + '</li>';
				}).join('') + '</ul>');
		} else {
			$('#bbm-warnings').prop('hidden', true).empty();
		}

		if (job.status === 'completed' && job.download_url) {
			$('#bbm-download').attr('href', job.download_url).prop('hidden', false);
			if (job.public_export) {
				renderPublicExport(job.public_export);
			}
			setBusy(false);
		}

		if (job.status === 'failed' || job.status === 'canceled') {
			setBusy(false);
		}
	}

	function fail(response) {
		var message = BlueprintBundleMaker.i18n.failed;

		if (response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
			message = response.responseJSON.data.message;
		}

		$('#bbm-status').text(message);
		setBusy(false);
	}

	function runNextStep() {
		if (!running || !currentJob) {
			return;
		}

		post('blueprint_bundle_maker_run_step', {
			job_id: currentJob.id
		}).done(function (response) {
			if (!response || !response.success) {
				fail();
				return;
			}

			currentJob = response.data;
			render(currentJob);

			if (currentJob.status === 'completed' || currentJob.status === 'failed' || currentJob.status === 'canceled') {
				return;
			}

			window.setTimeout(runNextStep, 400);
		}).fail(fail);
	}

	function getCopyText($button) {
		var target = $button.data('copy-target');

		if (target) {
			return $(target).val() || '';
		}

		return $button.data('url') || '';
	}

	function copyText(text) {
		if (window.navigator.clipboard && window.isSecureContext) {
			return window.navigator.clipboard.writeText(text);
		}

		var deferred = $.Deferred();
		var textarea = document.createElement('textarea');

		textarea.value = text;
		textarea.setAttribute('readonly', 'readonly');
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		document.body.appendChild(textarea);
		textarea.select();

		try {
			document.execCommand('copy');
			deferred.resolve();
		} catch (error) {
			deferred.reject(error);
		}

		document.body.removeChild(textarea);

		return deferred.promise();
	}

	function renderPublicExport(exportData) {
		$('#bbm-public-url').val(exportData.url);
		$('#bbm-open-playground').attr('href', exportData.playground_url).prop('hidden', false);
		$('#bbm-public-links').prop('hidden', false);

		if ($('tr[data-bbm-file="' + exportData.filename + '"]').length) {
			return;
		}

		$('#bbm-no-public-bundles').remove();

		var $row = $('<tr>').attr('data-bbm-file', exportData.filename);
		var $urlCell = $('<td>');
		var $actions = $('<td>').addClass('bbm-row-actions');

		$('<td>').text(exportData.created).appendTo($row);
		$('<td>').append($('<code>').text(exportData.filename)).appendTo($row);
		$('<td>').text(exportData.size_label).appendTo($row);

		$('<input>')
			.attr({ type: 'url', readonly: 'readonly' })
			.addClass('regular-text code bbm-table-url')
			.val(exportData.url)
			.appendTo($urlCell);

		$('<button>')
			.attr({ type: 'button' })
			.addClass('button bbm-copy-url')
			.data('url', exportData.url)
			.text(BlueprintBundleMaker.i18n.copyUrl)
			.appendTo($urlCell);

		$urlCell.appendTo($row);

		$('<a>')
			.addClass('button')
			.attr('href', exportData.url)
			.text(BlueprintBundleMaker.i18n.download)
			.appendTo($actions);

		$('<a>')
			.addClass('button button-primary')
			.attr({
				href: exportData.playground_url,
				target: '_blank',
				rel: 'noopener'
			})
			.text(BlueprintBundleMaker.i18n.openPlayground)
			.appendTo($actions);

		$('<a>')
			.addClass('button-link-delete bbm-delete-public-bundle')
			.attr('href', exportData.delete_url)
			.text(BlueprintBundleMaker.i18n.delete)
			.appendTo($actions);

		$actions.appendTo($row);
		$('#bbm-public-bundles-body').prepend($row);
	}

	$(function () {
		$('#bbm-start').on('click', function () {
			$('#bbm-download').prop('hidden', true).attr('href', '#');
			$('#bbm-public-links').prop('hidden', true);
			$('#bbm-public-url').val('');
			$('#bbm-open-playground').prop('hidden', true).attr('href', '#');
			$('#bbm-warnings').prop('hidden', true).empty();
			$('#bbm-status').text(BlueprintBundleMaker.i18n.working);
			$('#bbm-progress-bar').css('width', '0%');
			currentJob = null;
			setBusy(true);

			post('blueprint_bundle_maker_create_job').done(function (response) {
				if (!response || !response.success) {
					fail();
					return;
				}

				currentJob = response.data;
				render(currentJob);
				runNextStep();
			}).fail(fail);
		});

		$('#bbm-cancel').on('click', function () {
			if (!currentJob) {
				return;
			}

			post('blueprint_bundle_maker_cancel_job', {
				job_id: currentJob.id
			}).done(function (response) {
				if (response && response.success) {
					currentJob = response.data;
					render(currentJob);
				}
				setBusy(false);
			}).fail(fail);
		});

		$(document).on('click', '.bbm-copy-url', function () {
			var $button = $(this);
			var originalText = $button.text();
			var text = getCopyText($button);

			if (!text) {
				return;
			}

			$.when(copyText(text)).done(function () {
				$button.text(BlueprintBundleMaker.i18n.copied);
				window.setTimeout(function () {
					$button.text(originalText);
				}, 1400);
			});
		});

		$(document).on('click', '.bbm-delete-public-bundle', function (event) {
			if (!window.confirm(BlueprintBundleMaker.i18n.confirmDelete)) {
				event.preventDefault();
			}
		});
	});
})(jQuery);
