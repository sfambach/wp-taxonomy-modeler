/**
 * Model versions admin — focus host row from deep-link (?host_id=).
 *
 * @package WP_Taxonomy_Tree
 */
(function () {
	'use strict';

	var cfg = window.wttModelVersions || {};
	var hostId = parseInt(cfg.focusHostId, 10) || 0;
	if (!hostId) {
		return;
	}

	function focusRow() {
		var row = document.getElementById('wtt-mv-host-' + String(hostId));
		if (!row) {
			return;
		}
		row.classList.add('wtt-model-versions__row--focus');
		if (typeof row.scrollIntoView === 'function') {
			row.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
		try {
			row.focus({ preventScroll: true });
		} catch (e) {
			/* older browsers */
			row.focus();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', focusRow);
	} else {
		focusRow();
	}
})();
