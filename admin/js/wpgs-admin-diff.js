(function () {
	var tabWrapper = document.getElementById('wpgs-diff-tabs');
	if (!tabWrapper) {
		return;
	}
	var tabs = tabWrapper.querySelectorAll('[data-tab]');
	var panels = document.querySelectorAll('.wpgs-tab-panel');

	function activate(tabId, updateHash) {
		var hasMatch = false;
		for (var x = 0; x < tabs.length; x++) {
			if (tabs[x].getAttribute('data-tab') === tabId) {
				hasMatch = true;
				break;
			}
		}
		if (!hasMatch && tabs.length) {
			tabId = tabs[0].getAttribute('data-tab');
		}

		for (var i = 0; i < tabs.length; i++) {
			var isActive = tabs[i].getAttribute('data-tab') === tabId;
			tabs[i].classList.toggle('nav-tab-active', isActive);
			tabs[i].setAttribute('aria-selected', isActive ? 'true' : 'false');
		}

		for (var j = 0; j < panels.length; j++) {
			var panelIsActive = panels[j].id === tabId;
			panels[j].classList.toggle('is-active', panelIsActive);
			if (panelIsActive) {
				panels[j].removeAttribute('hidden');
			} else {
				panels[j].setAttribute('hidden', 'hidden');
			}
		}

		if (updateHash) {
			window.location.hash = tabId;
		}
	}

	for (var k = 0; k < tabs.length; k++) {
		tabs[k].addEventListener('click', function (event) {
			event.preventDefault();
			activate(this.getAttribute('data-tab'), true);
		});
	}

	var initialHash = window.location.hash ? window.location.hash.substring(1) : '';
	if (initialHash) {
		activate(initialHash, false);
	}
})();
