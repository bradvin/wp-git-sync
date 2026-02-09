(function () {
	var btn = document.getElementById('wpgs-export-all-btn');
	if (!btn) {
		return;
	}

	var config = window.wpgsOverviewConfig || {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var i18n = (config && config.i18n) ? config.i18n : {};
	if (!ajaxUrl || !nonce) {
		return;
	}

	var progressWrap = document.getElementById('wpgs-export-progress');
	var progressFill = document.getElementById('wpgs-export-progress-fill');
	var progressText = document.getElementById('wpgs-export-progress-text');
	var repoStatusDot = document.getElementById('wpgs-repo-status-dot');
	var rateLimitSummary = document.getElementById('wpgs-rate-limit-summary');
	var controlsWrap = document.getElementById('wpgs-export-controls');
	var resumeBtn = document.getElementById('wpgs-export-resume-btn');
	var stopBtn = document.getElementById('wpgs-export-stop-btn');
	var setupRepoToggleBtn = document.getElementById('wpgs-setup-repo-toggle-btn');
	var setupRepoConfirmWrap = document.getElementById('wpgs-setup-repo-confirm');
	var setupRepoCancelBtn = document.getElementById('wpgs-setup-repo-cancel-btn');
	var typeTabsNav = document.getElementById('wpgs-type-tabs-nav');
	var typeTabLinks = typeTabsNav ? typeTabsNav.querySelectorAll('[data-type-tab]') : [];
	var typePanels = document.querySelectorAll('.wpgs-type-panel');
	var typeButtons = document.querySelectorAll('.wpgs-export-type-btn');
	var retryErrorButtons = document.querySelectorAll('.wpgs-retry-errors-btn');
	var onlyErrorButtons = document.querySelectorAll('.wpgs-only-errors-btn');
	var syncButtons = document.querySelectorAll('.wpgs-sync-post-btn');
	var isRunning = false;
	var isPaused = false;
	var isStopping = false;
	var stopRequested = false;
	var currentScopeLabel = t('allPostTypes');
	var pendingResumeData = null;
	var latestProgressData = null;

	function t(key) {
		return (Object.prototype.hasOwnProperty.call(i18n, key) && i18n[key]) ? String(i18n[key]) : String(key || '');
	}

	function toBody(action, postType, onlyErrors) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce);
		if (postType) {
			body.append('post_type', postType);
		}
		if (onlyErrors) {
			body.append('only_errors', '1');
		}
		return body.toString();
	}

	function request(action, postType, onlyErrors) {
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: toBody(action, postType, onlyErrors)
		}).then(function (res) {
			return res.json();
		});
	}

	function requestPostSync(postId) {
		var body = new URLSearchParams();
		body.append('action', 'wpgs_export_post_ajax');
		body.append('nonce', nonce);
		body.append('post_id', String(postId));
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function setExportButtonsEnabled(enabled) {
		btn.disabled = !enabled;
		for (var i = 0; i < typeButtons.length; i++) {
			typeButtons[i].disabled = !enabled;
		}
		for (var j = 0; j < retryErrorButtons.length; j++) {
			retryErrorButtons[j].disabled = !enabled;
		}
		for (var k = 0; k < syncButtons.length; k++) {
			syncButtons[k].disabled = !enabled;
		}
	}

	function setControlVisible(el, visible) {
		if (!el) {
			return;
		}
		el.hidden = !visible;
		el.style.display = visible ? '' : 'none';
	}

	function parseRateLimit(value) {
		if (!value || typeof value !== 'object') {
			return null;
		}
		var limit = Math.max(0, parseInt(value.limit || 0, 10) || 0);
		var used = Math.max(0, parseInt(value.used || 0, 10) || 0);
		var remaining = Math.max(0, parseInt(value.remaining || 0, 10) || 0);
		var reset = Math.max(0, parseInt(value.reset || 0, 10) || 0);
		var resource = value.resource ? String(value.resource) : '';
		if (limit < 1 && used < 1 && remaining < 1 && reset < 1 && !resource) {
			return null;
		}
		return {
			limit: limit,
			used: used,
			remaining: remaining,
			reset: reset,
			resource: resource
		};
	}

	function rateLimitSeverity(rate) {
		if (!rate || rate.limit < 1) {
			return 'is-green';
		}
		if (rate.remaining <= 0 || rate.used >= rate.limit) {
			return 'is-red';
		}
		if (rate.used > (rate.limit / 2)) {
			return 'is-orange';
		}
		return 'is-green';
	}

	function formatRateLimitSummary(rate) {
		if (!rate || rate.limit < 1) {
			return t('rateLimitNoData');
		}
		var parts = [t('rateLimitPrefix') + ' ' + rate.used + ' / ' + rate.limit + ', ' + t('remainingLabel') + ' ' + rate.remaining];
		if (rate.reset > 0) {
			var resetDate = new Date(rate.reset * 1000);
			parts.push(t('resetsAtLabel') + ' ' + resetDate.toUTCString());
		}
		if (rate.resource) {
			parts.push(t('resourceLabel') + ': ' + rate.resource);
		}
		return parts.join(' | ');
	}

	function renderRateLimit(rateLimit) {
		var parsed = parseRateLimit(rateLimit);
		if (repoStatusDot) {
			repoStatusDot.classList.remove('is-green', 'is-orange', 'is-red');
			repoStatusDot.classList.add(rateLimitSeverity(parsed));
		}
		if (rateLimitSummary) {
			rateLimitSummary.textContent = formatRateLimitSummary(parsed);
		}
	}

	function updateErrorSummary(panel) {
		if (!panel) {
			return;
		}
		var summary = panel.querySelector('.wpgs-type-error-summary');
		var countNode = panel.querySelector('.wpgs-type-error-count');
		var retryErrorsBtn = panel.querySelector('.wpgs-retry-errors-btn');
		var onlyErrorsBtn = panel.querySelector('.wpgs-only-errors-btn');
		if (!summary || !countNode) {
			return;
		}
		var errorRows = panel.querySelectorAll('tbody tr[data-has-error="1"]');
		var errorCount = errorRows ? errorRows.length : 0;
		countNode.textContent = String(errorCount);
		summary.hidden = errorCount < 1;
		if (retryErrorsBtn) {
			var hasRetryErrors = errorCount > 0;
			retryErrorsBtn.hidden = !hasRetryErrors;
			retryErrorsBtn.style.display = hasRetryErrors ? '' : 'none';
		}
		if (onlyErrorsBtn) {
			var hasErrors = errorCount > 0;
			onlyErrorsBtn.hidden = !hasErrors;
			onlyErrorsBtn.style.display = hasErrors ? '' : 'none';
			if (!hasErrors && panel.getAttribute('data-only-errors') === '1') {
				panel.setAttribute('data-only-errors', '0');
				onlyErrorsBtn.classList.remove('is-active');
				onlyErrorsBtn.setAttribute('aria-pressed', 'false');
					onlyErrorsBtn.textContent = t('onlyShowErrors');
			}
		}
	}

	function applyOnlyErrorsFilter(panel) {
		if (!panel) {
			return;
		}
		var onlyErrors = panel.getAttribute('data-only-errors') === '1';
		var rows = panel.querySelectorAll('tbody tr[data-post-id]');
		var visibleCount = 0;
		for (var i = 0; i < rows.length; i++) {
			var hasError = rows[i].getAttribute('data-has-error') === '1';
			var show = !onlyErrors || hasError;
			rows[i].hidden = !show;
			if (show) {
				visibleCount++;
			}
		}

		var emptyOnlyErrors = panel.querySelector('.wpgs-only-errors-empty');
		if (emptyOnlyErrors) {
			emptyOnlyErrors.hidden = !onlyErrors || visibleCount > 0;
		}
	}

	function updatePanelErrorState(panel) {
		updateErrorSummary(panel);
		applyOnlyErrorsFilter(panel);
	}

	function refreshControlButtons() {
		if (!controlsWrap) {
			return;
		}
		var hasPendingResume = !isRunning && !!pendingResumeData;
		var showControls = isRunning || isPaused || hasPendingResume;
		controlsWrap.hidden = !showControls;
		controlsWrap.style.display = showControls ? 'inline-flex' : 'none';
		setControlVisible(resumeBtn, isPaused || hasPendingResume);
		setControlVisible(stopBtn, isRunning || isPaused || hasPendingResume);
	}

	function setSetupRepoConfirmVisible(visible) {
		if (!setupRepoConfirmWrap) {
			return;
		}
		setupRepoConfirmWrap.hidden = !visible;
		setupRepoConfirmWrap.style.display = visible ? 'block' : 'none';
		if (setupRepoToggleBtn) {
			setupRepoToggleBtn.setAttribute('aria-expanded', visible ? 'true' : 'false');
		}
	}

	function renderProgress(data) {
		if (data.scope_label) {
			currentScopeLabel = data.scope_label;
		}
		if (data && Object.prototype.hasOwnProperty.call(data, 'rate_limit')) {
			renderRateLimit(data.rate_limit);
		}
		latestProgressData = data;
		var pct = typeof data.percent === 'number' ? data.percent : 0;
		pct = Math.max(0, Math.min(100, pct));
		progressFill.style.width = pct + '%';
		var bar = progressWrap.querySelector('.wpgs-export-progress-bar');
		if (bar) {
			bar.setAttribute('aria-valuenow', String(pct));
		}

		var base = currentScopeLabel + ' ' + t('exportProgressPrefix') + ' ' + data.processed + '/' + data.total + ' steps (' + pct + '%). ';
		if (data.last_step && data.last_step.message) {
			base += data.last_step.message + ' ';
		}
		base += t('succeededLabel') + ' ' + data.succeeded + '. ' + t('failedLabel') + ' ' + data.failed + '.';
		progressText.textContent = base;
	}

	function setRowSyncState(postId, state) {
		var row = document.querySelector('tr[data-post-id="' + String(postId) + '"]');
		if (!row) {
			return;
		}
		var panel = row.closest('.wpgs-type-panel');
		var stateCell = row.querySelector('.wpgs-row-state');
		var syncedCell = row.querySelector('.wpgs-row-last-synced');
		var errorCell = row.querySelector('.wpgs-row-last-error');
		var hasError = state && state.last_error;
		var hasSyncedField = state && Object.prototype.hasOwnProperty.call(state, 'last_synced_at');
		var hasErrorField = state && Object.prototype.hasOwnProperty.call(state, 'last_error');

			if (stateCell) {
				stateCell.innerHTML = hasError
					? '<span class="wpgs-pill wpgs-row-state-pill is-error">' + t('rowStateError') + '</span>'
					: '<span class="wpgs-pill wpgs-row-state-pill is-synced">' + t('rowStateSynced') + '</span>';
		}
		if (syncedCell && hasSyncedField) {
			syncedCell.textContent = state && state.last_synced_at ? String(state.last_synced_at) : '—';
		}
		if (errorCell && hasErrorField) {
			errorCell.textContent = hasError ? String(state.last_error) : '—';
		}
		row.setAttribute('data-has-error', hasError ? '1' : '0');
		updatePanelErrorState(panel);
	}

	function finishRun(data) {
		isRunning = false;
		isPaused = false;
		pendingResumeData = null;
		latestProgressData = data || latestProgressData;
		setExportButtonsEnabled(true);
		btn.textContent = t('exportAllButton');
		refreshControlButtons();
		renderProgress(data);
	}

	function beginPollingWithCurrentState(data) {
		stopRequested = false;
		isRunning = true;
		isPaused = false;
		pendingResumeData = null;
		setExportButtonsEnabled(false);
		btn.textContent = t('exportingButton');
		progressWrap.hidden = false;
		refreshControlButtons();
		renderProgress(data);
		window.setTimeout(pollStep, 200);
	}

	function pauseByServer(data) {
		isRunning = false;
		isPaused = true;
		pendingResumeData = data || latestProgressData || {};
		latestProgressData = pendingResumeData;
		btn.textContent = t('exportPausedButton');
		renderProgress(pendingResumeData);
		refreshControlButtons();
	}

	function pollStep() {
		if (!isRunning || isPaused) {
			return;
		}
		request('wpgs_export_batch_step')
			.then(function (res) {
				if (!res.success) {
					throw new Error((res.data && res.data.message) ? res.data.message : t('batchStepFailed'));
				}
				if (stopRequested) {
					return;
				}
				var data = res.data || {};
				renderProgress(data);
				if (data.paused) {
					pauseByServer(data);
					return;
				}
				if (data.done) {
					finishRun(data);
					return;
				}
				if (isPaused || !isRunning) {
					pendingResumeData = data;
					refreshControlButtons();
					return;
				}
				window.setTimeout(pollStep, 350);
			})
			.catch(function (err) {
				if (stopRequested) {
					return;
				}
				isRunning = false;
				isPaused = false;
				pendingResumeData = null;
				setExportButtonsEnabled(true);
					btn.textContent = t('exportAllButton');
				refreshControlButtons();
					progressText.textContent = t('batchFailedPrefix') + (err && err.message ? err.message : t('unknownError'));
			});
	}

	function startBatch(postType, scopeLabel, onlyErrors) {
		if (isRunning || isPaused || isStopping) {
			return;
		}
		progressWrap.hidden = false;
		progressFill.style.width = '0%';
		progressText.textContent = t('startingBatch');
		currentScopeLabel = scopeLabel || t('allPostTypes');
		pendingResumeData = null;
		latestProgressData = null;
		stopRequested = false;
		isRunning = true;
		isPaused = false;
		setExportButtonsEnabled(false);
		btn.textContent = t('exportingButton');
		refreshControlButtons();

		request('wpgs_export_batch_start', postType, !!onlyErrors)
			.then(function (res) {
				if (!res.success) {
					throw new Error((res.data && res.data.message) ? res.data.message : t('unableStartBatch'));
				}
				var data = res.data || {};
				if (Object.prototype.hasOwnProperty.call(data, 'rate_limit')) {
					renderRateLimit(data.rate_limit);
				}
				beginPollingWithCurrentState(data);
			})
			.catch(function (err) {
				isRunning = false;
				isPaused = false;
				setExportButtonsEnabled(true);
					btn.textContent = t('exportAllButton');
				refreshControlButtons();
					progressText.textContent = t('unableToStartBatchPrefix') + (err && err.message ? err.message : t('unknownError'));
			});
	}

	function stopBatch() {
		if (isStopping) {
			return;
		}
		isStopping = true;
		stopRequested = true;
		isRunning = false;
		isPaused = false;
		refreshControlButtons();
		request('wpgs_export_batch_stop')
			.then(function (res) {
				if (!res.success) {
					throw new Error((res.data && res.data.message) ? res.data.message : t('unableStopExport'));
				}
				var data = res.data || {};
				pendingResumeData = null;
				latestProgressData = null;
					btn.textContent = t('exportAllButton');
				setExportButtonsEnabled(true);
				refreshControlButtons();
				progressWrap.hidden = false;
				progressFill.style.width = '0%';
				progressText.textContent = (data.last_step && data.last_step.message)
					? data.last_step.message
						: t('exportStopped');
			})
			.catch(function (err) {
				setExportButtonsEnabled(false);
					progressText.textContent = t('unableToStopBatchPrefix') + (err && err.message ? err.message : t('unknownError'));
			})
			.finally(function () {
				isStopping = false;
			});
	}

	btn.addEventListener('click', function () {
		startBatch('', t('allPostTypes'), false);
	});

	for (var typeIdx = 0; typeIdx < typeButtons.length; typeIdx++) {
		typeButtons[typeIdx].addEventListener('click', function () {
			var postType = this.getAttribute('data-post-type') || '';
			var label = this.getAttribute('data-post-label') || postType;
			startBatch(postType, label, false);
		});
	}

	for (var r = 0; r < retryErrorButtons.length; r++) {
		retryErrorButtons[r].addEventListener('click', function () {
			var postType = this.getAttribute('data-post-type') || '';
			var label = this.getAttribute('data-post-label') || postType;
			startBatch(postType, label + ' ' + t('errorsSuffix'), true);
		});
	}

	for (var e = 0; e < onlyErrorButtons.length; e++) {
		onlyErrorButtons[e].addEventListener('click', function () {
			var panel = this.closest('.wpgs-type-panel');
			if (!panel) {
				return;
			}
			var onlyErrors = panel.getAttribute('data-only-errors') === '1';
			var nextOnlyErrors = !onlyErrors;
			panel.setAttribute('data-only-errors', nextOnlyErrors ? '1' : '0');
			this.setAttribute('aria-pressed', nextOnlyErrors ? 'true' : 'false');
			this.classList.toggle('is-active', nextOnlyErrors);
			this.textContent = nextOnlyErrors ? t('showAll') : t('onlyShowErrors');
			applyOnlyErrorsFilter(panel);
		});
	}

	for (var s = 0; s < syncButtons.length; s++) {
		syncButtons[s].addEventListener('click', function () {
			if (isRunning || isPaused || isStopping) {
				return;
			}
			var self = this;
			var postId = parseInt(self.getAttribute('data-post-id') || '0', 10);
			if (!postId) {
				return;
			}
			var prevText = self.textContent;
			self.disabled = true;
			self.textContent = t('exportingPostButton');

			requestPostSync(postId)
				.then(function (res) {
					if (!res.success) {
							var err = new Error((res.data && res.data.message) ? res.data.message : t('failedSyncingPost'));
						err.state = (res.data && res.data.state) ? res.data.state : null;
						throw err;
					}
					var data = res.data || {};
					setRowSyncState(postId, data.state || {});
				})
				.catch(function (err) {
					var state = (err && err.state) ? err.state : {};
					if (!state || typeof state !== 'object') {
						state = {};
					}
					if (!Object.prototype.hasOwnProperty.call(state, 'last_error')) {
							state.last_error = err && err.message ? err.message : t('unknownError');
					}
					setRowSyncState(postId, state);
				})
				.finally(function () {
					self.disabled = false;
					self.textContent = prevText;
				});
		});
	}

	function activateTypeTab(slug) {
		if (!typeTabLinks.length) {
			return;
		}
		for (var i = 0; i < typeTabLinks.length; i++) {
			var active = typeTabLinks[i].getAttribute('data-type-tab') === slug;
			typeTabLinks[i].classList.toggle('nav-tab-active', active);
			typeTabLinks[i].setAttribute('aria-selected', active ? 'true' : 'false');
		}
		for (var j = 0; j < typePanels.length; j++) {
			var panelActive = typePanels[j].id === ('wpgs-type-tab-' + slug);
			if (panelActive) {
				typePanels[j].removeAttribute('hidden');
			} else {
				typePanels[j].setAttribute('hidden', 'hidden');
			}
		}
	}

	for (var n = 0; n < typeTabLinks.length; n++) {
		typeTabLinks[n].addEventListener('click', function (event) {
			event.preventDefault();
			activateTypeTab(this.getAttribute('data-type-tab'));
		});
	}

	if (resumeBtn) {
		resumeBtn.addEventListener('click', function () {
			if (!pendingResumeData || isRunning) {
				return;
			}
			beginPollingWithCurrentState(pendingResumeData);
		});
	}
	if (stopBtn) {
		stopBtn.addEventListener('click', stopBatch);
	}
	if (setupRepoToggleBtn) {
		setupRepoToggleBtn.addEventListener('click', function () {
			var nextVisible = !setupRepoConfirmWrap || setupRepoConfirmWrap.hidden;
			setSetupRepoConfirmVisible(nextVisible);
		});
	}
	if (setupRepoCancelBtn) {
		setupRepoCancelBtn.addEventListener('click', function () {
			setSetupRepoConfirmVisible(false);
		});
	}

	request('wpgs_export_batch_status')
		.then(function (res) {
			if (!res.success) {
				return;
			}
			var data = res.data || {};
			if (Object.prototype.hasOwnProperty.call(data, 'rate_limit')) {
				renderRateLimit(data.rate_limit);
			}
			if (!data.active) {
				return;
			}
			pendingResumeData = data;
			progressWrap.hidden = false;
			renderProgress(data);
			if (data.paused) {
					progressText.textContent += ' ' + t('waitThenResumeHint');
					isPaused = true;
					isRunning = false;
					btn.textContent = t('exportPausedButton');
				} else {
					progressText.textContent += ' ' + t('clickResumeHint');
				}
			refreshControlButtons();
			setExportButtonsEnabled(false);
		})
		.catch(function () {
			// Keep manual start action available.
		});

	renderRateLimit(config.initialRateLimit || null);

	for (var p = 0; p < typePanels.length; p++) {
		updatePanelErrorState(typePanels[p]);
	}
	setSetupRepoConfirmVisible(false);
})();
