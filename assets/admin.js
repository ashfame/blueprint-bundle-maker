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
		updateCancelState();
	}

	function updateCancelState() {
		$('#bbm-cancel').prop('disabled', !running || !currentJob);
	}

	function render(job) {
		var percent = Math.max(0, Math.min(100, parseInt(job.percent, 10) || 0));
		var counts = job.counts || {};

		$('#bbm-progress-bar').css('width', percent + '%');
		$('#bbm-status').text(job.message || '');
		$('#bbm-stage').text(job.stage || '-');
		$('#bbm-files').text(counts.scanned_files || 0);
		$('#bbm-zipped').text(counts.zipped_files || 0);
		$('#bbm-skipped').text(counts.skipped_files || 0);

		if (job.warnings && job.warnings.length) {
			$('#bbm-warnings')
				.prop('hidden', false)
				.html('<strong>Warnings</strong><ul>' + job.warnings.map(function (warning) {
					return '<li>' + $('<div>').text(warning).html() + '</li>';
				}).join('') + '</ul>');
		} else {
			$('#bbm-warnings').prop('hidden', true).empty();
		}

		if (job.status === 'completed') {
			if (job.bundle) {
				renderBundleRow(job.bundle);
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

	function renderBundleRow(bundle) {
		var $existing = $('tr[data-bbm-bundle-id="' + bundle.id + '"]');
		var $row = $('<tr>').attr('data-bbm-bundle-id', bundle.id);
		var $urlCell = $('<td>');
		var $actions = $('<td>').addClass('bbm-row-actions');

		$('<td>').text(bundle.created).appendTo($row);
		$('<td>').append($('<code>').text(bundle.filename)).appendTo($row);
		$('<td>').text(bundle.size_label).appendTo($row);

		if (bundle.public_url) {
			$('<input>')
				.attr({ type: 'url', readonly: 'readonly' })
				.addClass('regular-text code bbm-table-url')
				.val(bundle.public_url)
				.appendTo($urlCell);

			$('<button>')
				.attr({ type: 'button' })
				.addClass('button bbm-copy-url')
				.data('url', bundle.public_url)
				.text(BlueprintBundleMaker.i18n.copyUrl)
				.appendTo($urlCell);
		} else {
			$('<span>').addClass('description').text(BlueprintBundleMaker.i18n.notPublished).appendTo($urlCell);
		}

		$urlCell.appendTo($row);

		$('<a>')
			.addClass('button')
			.attr('href', bundle.download_url)
			.text(BlueprintBundleMaker.i18n.download)
			.appendTo($actions);

		if (bundle.public_url) {
			$('<a>')
				.addClass('button button-primary')
				.attr({
					href: bundle.playground_url,
					target: '_blank',
					rel: 'noopener'
				})
				.text(BlueprintBundleMaker.i18n.openPlayground)
				.appendTo($actions);
		} else {
			$('<button>')
				.attr({ type: 'button' })
				.addClass('button button-primary bbm-publish-bundle')
				.data('bundle-id', bundle.id)
				.text(BlueprintBundleMaker.i18n.getUrl)
				.appendTo($actions);
		}

		$('<a>')
			.addClass('button-link-delete bbm-delete-bundle')
			.attr('href', bundle.delete_url)
			.text(BlueprintBundleMaker.i18n.delete)
			.appendTo($actions);

		$actions.appendTo($row);

		if ($existing.length) {
			$existing.replaceWith($row);
		} else {
			$('#bbm-no-generated-bundles').remove();
			$('#bbm-generated-bundles-body').prepend($row);
		}
	}

	$(function () {
		$('#bbm-start').on('click', function () {
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
				updateCancelState();
				render(currentJob);
				runNextStep();
			}).fail(fail);
		});

		$(document).on('click', '.bbm-publish-bundle', function () {
			var $button = $(this);
			var bundleId = $button.data('bundle-id');

			if (!bundleId) {
				return;
			}

			$button.prop('disabled', true);

			post('blueprint_bundle_maker_publish_bundle', {
				bundle_id: bundleId
			}).done(function (response) {
				if (!response || !response.success || !response.data || !response.data.bundle) {
					fail();
					$button.prop('disabled', false);
					return;
				}

				renderBundleRow(response.data.bundle);
			}).fail(function (response) {
				fail(response);
				$button.prop('disabled', false);
			});
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

		$(document).on('click', '.bbm-delete-bundle', function (event) {
			if (!window.confirm(BlueprintBundleMaker.i18n.confirmDelete)) {
				event.preventDefault();
			}
		});
	});
})(jQuery);
