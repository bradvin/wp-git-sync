(function () {
	var btn = document.getElementById('wpgs-export-all-btn');
	if (!btn) {
		return;
	}

	var config = window.wpgsOverviewConfig || {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	if (!ajaxUrl || !nonce) {
		return;
	}

	var progressWrap = document.getElementById('wpgs-export-progress');
	var progressFill = document.getElementById('wpgs-export-progress-fill');
	var progressText = document.getElementById('wpgs-export-progress-text');
	var controlsWrap = document.getElementById('wpgs-export-controls');
	var resumeBtn = document.getElementById('wpgs-export-resume-btn');
	var pauseBtn = document.getElementById('wpgs-export-pause-btn');
	var stopBtn = document.getElementById('wpgs-export-stop-btn');
	var typeTabsNav = document.getElementById('wpgs-type-tabs-nav');
	var typeTabLinks = typeTabsNav ? typeTabsNav.querySelectorAll('[data-type-tab]') : [];
	var typePanels = document.querySelectorAll('.wpgs-type-panel');
	var typeButtons = document.querySelectorAll('.wpgs-export-type-btn');
	var syncButtons = document.querySelectorAll('.wpgs-sync-post-btn');
	var isRunning = false;
	var isPaused = false;
	var isStopping = false;
	var stopRequested = false;
	var currentScopeLabel = 'All post types';
	var pendingResumeData = null;
	var latestProgressData = null;

	function toBody(action, postType) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', nonce);
		if (postType) {
			body.append('post_type', postType);
		}
		return body.toString();
	}

	function request(action, postType) {
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: toBody(action, postType)
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
		for (var j = 0; j < syncButtons.length; j++) {
			syncButtons[j].disabled = !enabled;
		}
	}

	function setControlVisible(el, visible) {
		if (!el) {
			return;
		}
		el.hidden = !visible;
		el.style.display = visible ? '' : 'none';
	}

	function refreshControlButtons() {
		if (!controlsWrap) {
			return;
		}
		var hasPendingResume = !isRunning && !!pendingResumeData;
		var showControls = isRunning || isPaused || hasPendingResume;
		controlsWrap.hidden = !showControls;
		controlsWrap.style.display = showControls ? 'flex' : 'none';
		setControlVisible(resumeBtn, isPaused || hasPendingResume);
		setControlVisible(pauseBtn, isRunning && !isPaused && !isStopping);
		setControlVisible(stopBtn, isRunning || isPaused || hasPendingResume);
	}

	function renderProgress(data) {
		if (data.scope_label) {
			currentScopeLabel = data.scope_label;
		}
		latestProgressData = data;
		var pct = typeof data.percent === 'number' ? data.percent : 0;
		pct = Math.max(0, Math.min(100, pct));
		progressFill.style.width = pct + '%';
		var bar = progressWrap.querySelector('.wpgs-export-progress-bar');
		if (bar) {
			bar.setAttribute('aria-valuenow', String(pct));
		}

		var base = currentScopeLabel + ' export: ' + data.processed + '/' + data.total + ' steps (' + pct + '%). ';
		if (data.last_step && data.last_step.message) {
			base += data.last_step.message + ' ';
		}
		base += 'Succeeded: ' + data.succeeded + '. Failed: ' + data.failed + '.';
		progressText.textContent = base;
	}

	function setRowSyncState(postId, state) {
		var row = document.querySelector('tr[data-post-id="' + String(postId) + '"]');
		if (!row) {
			return;
		}
		var stateCell = row.querySelector('.wpgs-row-state');
		var syncedCell = row.querySelector('.wpgs-row-last-synced');
		var errorCell = row.querySelector('.wpgs-row-last-error');
		var hasError = state && state.last_error;
		var hasSyncedField = state && Object.prototype.hasOwnProperty.call(state, 'last_synced_at');
		var hasErrorField = state && Object.prototype.hasOwnProperty.call(state, 'last_error');

		if (stateCell) {
			stateCell.innerHTML = hasError
				? '<span class="wpgs-pill wpgs-row-state-pill is-error">Error</span>'
				: '<span class="wpgs-pill wpgs-row-state-pill is-synced">Synced</span>';
		}
		if (syncedCell && hasSyncedField) {
			syncedCell.textContent = state && state.last_synced_at ? String(state.last_synced_at) : '—';
		}
		if (errorCell && hasErrorField) {
			errorCell.textContent = hasError ? String(state.last_error) : '—';
		}
	}

	function finishRun(data) {
		isRunning = false;
		isPaused = false;
		pendingResumeData = null;
		latestProgressData = data || latestProgressData;
		setExportButtonsEnabled(true);
		btn.textContent = 'Export All Posts';
		refreshControlButtons();
		renderProgress(data);
	}

	function beginPollingWithCurrentState(data) {
		stopRequested = false;
		isRunning = true;
		isPaused = false;
		pendingResumeData = null;
		setExportButtonsEnabled(false);
		btn.textContent = 'Exporting...';
		progressWrap.hidden = false;
		refreshControlButtons();
		renderProgress(data);
		window.setTimeout(pollStep, 200);
	}

	function pollStep() {
		if (!isRunning || isPaused) {
			return;
		}
		request('wpgs_export_batch_step')
			.then(function (res) {
				if (!res.success) {
					throw new Error((res.data && res.data.message) ? res.data.message : 'Batch step failed.');
				}
				if (stopRequested) {
					return;
				}
				var data = res.data || {};
				renderProgress(data);
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
				btn.textContent = 'Export All Posts';
				refreshControlButtons();
				progressText.textContent = 'Batch failed: ' + (err && err.message ? err.message : 'Unknown error');
			});
	}

	function startBatch(postType, scopeLabel) {
		if (isRunning || isPaused || isStopping) {
			return;
		}
		progressWrap.hidden = false;
		progressFill.style.width = '0%';
		progressText.textContent = 'Starting export batch...';
		currentScopeLabel = scopeLabel || 'All post types';
		pendingResumeData = null;
		latestProgressData = null;
		stopRequested = false;
		isRunning = true;
		isPaused = false;
		setExportButtonsEnabled(false);
		btn.textContent = 'Exporting...';
		refreshControlButtons();

		request('wpgs_export_batch_start', postType)
			.then(function (res) {
				if (!res.success) {
					throw new Error((res.data && res.data.message) ? res.data.message : 'Unable to start batch.');
				}
				var data = res.data || {};
				beginPollingWithCurrentState(data);
			})
			.catch(function (err) {
				isRunning = false;
				isPaused = false;
				setExportButtonsEnabled(true);
				btn.textContent = 'Export All Posts';
				refreshControlButtons();
				progressText.textContent = 'Unable to start batch: ' + (err && err.message ? err.message : 'Unknown error');
			});
	}

	function pauseBatch() {
		if (!isRunning) {
			return;
		}
		isPaused = true;
		isRunning = false;
		pendingResumeData = latestProgressData;
		btn.textContent = 'Export Paused';
		refreshControlButtons();
		progressText.textContent = progressText.textContent.replace(/\s*Export paused\.$/, '') + ' Export paused.';
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
					throw new Error((res.data && res.data.message) ? res.data.message : 'Unable to stop export.');
				}
				var data = res.data || {};
				pendingResumeData = null;
				latestProgressData = null;
				btn.textContent = 'Export All Posts';
				setExportButtonsEnabled(true);
				refreshControlButtons();
				progressWrap.hidden = false;
				progressFill.style.width = '0%';
				progressText.textContent = (data.last_step && data.last_step.message)
					? data.last_step.message
					: 'Export stopped.';
			})
			.catch(function (err) {
				setExportButtonsEnabled(false);
				progressText.textContent = 'Unable to stop export: ' + (err && err.message ? err.message : 'Unknown error');
			})
			.finally(function () {
				isStopping = false;
			});
	}

	btn.addEventListener('click', function () {
		startBatch('', 'All post types');
	});

	for (var t = 0; t < typeButtons.length; t++) {
		typeButtons[t].addEventListener('click', function () {
			var postType = this.getAttribute('data-post-type') || '';
			var label = this.textContent ? this.textContent.replace(/^Export all\s+/i, '') : postType;
			startBatch(postType, label);
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
			self.textContent = 'Exporting...';

			requestPostSync(postId)
				.then(function (res) {
					if (!res.success) {
						var err = new Error((res.data && res.data.message) ? res.data.message : 'Failed syncing post.');
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
						state.last_error = err && err.message ? err.message : 'Unknown error';
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
	if (pauseBtn) {
		pauseBtn.addEventListener('click', pauseBatch);
	}
	if (stopBtn) {
		stopBtn.addEventListener('click', stopBatch);
	}

	request('wpgs_export_batch_status')
		.then(function (res) {
			if (!res.success) {
				return;
			}
			var data = res.data || {};
			if (!data.active) {
				return;
			}
			pendingResumeData = data;
			progressWrap.hidden = false;
			renderProgress(data);
			progressText.textContent += ' Click Resume Export to continue.';
			refreshControlButtons();
			setExportButtonsEnabled(false);
		})
		.catch(function () {
			// Keep manual start action available.
		});
})();
