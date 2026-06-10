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

	$(function () {
		$('#bbm-start').on('click', function () {
			$('#bbm-download').prop('hidden', true).attr('href', '#');
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
	});
})(jQuery);
