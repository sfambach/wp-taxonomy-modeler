/**
 * Model versions admin — focus current version card on detail deep-link.
 *
 * @package WP_Taxonomy_Tree
 */
(function () {
	'use strict';

	var cfg = window.wttModelVersions || {};
	var hostId = parseInt(cfg.focusHostId, 10) || 0;

	function focusEl(el) {
		if (!el) {
			return;
		}
		el.classList.add('wtt-model-versions__card--focus');
		if (typeof el.scrollIntoView === 'function') {
			el.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
		try {
			el.focus({ preventScroll: true });
		} catch (e) {
			/* older browsers */
			if (typeof el.focus === 'function') {
				el.focus();
			}
		}
	}

	function onReady() {
		var current = document.querySelector(
			'.wtt-model-versions__card--current[data-current="1"]'
		);
		if (current) {
			focusEl(current);
			return;
		}
		if (!hostId) {
			return;
		}
		var row = document.getElementById('wtt-mv-host-' + String(hostId));
		if (row) {
			row.classList.add('wtt-model-versions__row--focus');
			if (typeof row.scrollIntoView === 'function') {
				row.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', onReady);
	} else {
		onReady();
	}
})();
