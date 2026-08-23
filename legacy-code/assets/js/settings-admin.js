(function () {
	'use strict';

	var cfg = window.wttSettings || {};
	var saved = cfg.saved || {};
	var fields = cfg.fields || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById('wtt-settings-form');
	var undoBtn = document.getElementById('wtt-settings-undo');

	if (!form || !undoBtn) {
		return;
	}

	function checkboxValue(optionName) {
		var input = form.querySelector('input[name="' + optionName + '"][type="checkbox"]');
		return !!(input && input.checked);
	}

	function setCheckbox(optionName, value) {
		var input = form.querySelector('input[name="' + optionName + '"][type="checkbox"]');
		if (input) {
			input.checked = !!value;
			var switchLabel = input.closest('.wtt-switch');
			if (switchLabel) {
				switchLabel.classList.toggle('is-on', !!value);
			}
		}
	}

	function selectValue(optionName) {
		var input = form.querySelector('select[name="' + optionName + '"]');
		return input ? String(input.value || '') : '';
	}

	function setSelect(optionName, value) {
		var input = form.querySelector('select[name="' + optionName + '"]');
		if (input) {
			input.value = String(value || '');
		}
	}

	function numberValue(optionName, fallback) {
		var input = form.querySelector('input[name="' + optionName + '"][type="number"]');
		if (!input) {
			return typeof fallback === 'number' ? fallback : 0;
		}
		var n = parseInt(input.value, 10);
		if (isNaN(n)) {
			return typeof fallback === 'number' ? fallback : 0;
		}
		if (n < 0) {
			n = 0;
		}
		if (n > 5) {
			n = 5;
		}
		return n;
	}

	function setNumber(optionName, value) {
		var input = form.querySelector('input[name="' + optionName + '"][type="number"]');
		if (input) {
			var n = parseInt(value, 10);
			if (isNaN(n)) {
				n = 1;
			}
			if (n < 0) {
				n = 0;
			}
			if (n > 5) {
				n = 5;
			}
			input.value = String(n);
		}
	}

	function bindingSelects() {
		return form.querySelectorAll('select[data-wtt-binding="1"]');
	}

	function catalogBindingsState() {
		var out = {};
		bindingSelects().forEach(function (sel) {
			var tax = sel.getAttribute('data-taxonomy') || '';
			var key = sel.getAttribute('data-key') || '';
			if (!tax || !key) {
				return;
			}
			if (!out[tax]) {
				out[tax] = {};
			}
			out[tax][key] = String(sel.value || '0');
		});
		return out;
	}

	function setCatalogBindings(state) {
		var map = state || {};
		bindingSelects().forEach(function (sel) {
			var tax = sel.getAttribute('data-taxonomy') || '';
			var key = sel.getAttribute('data-key') || '';
			var next = '0';
			if (map[tax] && map[tax][key] != null) {
				next = String(map[tax][key]);
			}
			sel.value = next;
			syncBindingViewRow(sel);
		});
	}

	function bindingsEqual(a, b) {
		return JSON.stringify(a || {}) === JSON.stringify(b || {});
	}

	function syncBindingViewRow(sel) {
		var row = sel.closest('tr');
		if (!row) {
			return;
		}
		var idEl = row.querySelector('.wtt-catalog-bindings__view-id');
		var nameEl = row.querySelector('.wtt-catalog-bindings__view-name');
		if (!idEl || !nameEl) {
			return;
		}
		var id = String(sel.value || '0');
		var unbound = i18n.bindingsUnbound || '(unbound)';
		if (id === '0') {
			idEl.textContent = unbound;
			if (idEl.tagName === 'CODE') {
				var em = document.createElement('em');
				em.className = 'wtt-catalog-bindings__view-id';
				em.textContent = unbound;
				idEl.replaceWith(em);
			}
			nameEl.textContent = '—';
			return;
		}
		var opt = sel.options[sel.selectedIndex];
		var label = opt ? String(opt.textContent || '') : '';
		var nodeName = label;
		var hash = label.lastIndexOf('(#');
		if (hash !== -1) {
			nodeName = label.slice(0, hash).replace(/\s+$/, '');
			var slash = nodeName.lastIndexOf('/');
			if (slash !== -1) {
				nodeName = nodeName.slice(slash + 1).replace(/^\s+/, '');
			}
		}
		if (idEl.tagName !== 'CODE') {
			var code = document.createElement('code');
			code.className = 'wtt-catalog-bindings__view-id';
			code.textContent = id;
			idEl.replaceWith(code);
		} else {
			idEl.textContent = id;
		}
		nameEl.textContent = nodeName || '—';
	}

	function setBindingsMode(wrap, mode) {
		wrap.setAttribute('data-mode', mode);
		var btn = wrap.querySelector('[data-wtt-bindings-toggle]');
		if (btn) {
			btn.textContent =
				mode === 'edit'
					? i18n.bindingsDone || 'Done'
					: i18n.bindingsChange || 'Change';
		}
	}

	function treeIconKeysState() {
		var optionName = fields.treeIconKeys || 'wtt_tree_icon_keys';
		var out = [];
		form.querySelectorAll('input[type="checkbox"][name="' + optionName + '[]"]').forEach(function (input) {
			if (input.checked) {
				out.push(String(input.value || ''));
			}
		});
		return out;
	}

	function setTreeIconKeys(keys) {
		var optionName = fields.treeIconKeys || 'wtt_tree_icon_keys';
		var set = {};
		(keys || []).forEach(function (key) {
			set[String(key)] = true;
		});
		form.querySelectorAll('input[type="checkbox"][name="' + optionName + '[]"]').forEach(function (input) {
			input.checked = !!set[String(input.value || '')];
		});
	}

	function iconKeysEqual(a, b) {
		var left = (a || []).slice().map(String).sort();
		var right = (b || []).slice().map(String).sort();
		return JSON.stringify(left) === JSON.stringify(right);
	}

	function currentState() {
		return {
			testMode: checkboxValue(fields.testMode || 'wtt_test_mode'),
			hideRootNode: checkboxValue(fields.hideRootNode || 'wtt_hide_root_node'),
			showTypeInTree: checkboxValue(fields.showTypeInTree || 'wtt_show_type_in_tree'),
			showModelDataCounts: checkboxValue(
				fields.showModelDataCounts || 'wtt_show_model_data_counts'
			),
			showSetChildProps: checkboxValue(fields.showSetChildProps || 'wtt_show_set_child_props'),
			saveViaButton: checkboxValue(fields.saveViaButton || 'wtt_save_via_button'),
			treePickerMode: selectValue(fields.treePickerMode || 'wtt_tree_picker_mode') || 'popup',
			confirmNodeDelete: checkboxValue(fields.confirmNodeDelete || 'wtt_confirm_node_delete'),
			warnStructuralModelChange: checkboxValue(
				fields.warnStructuralModelChange || 'wtt_warn_structural_model_change'
			),
			dialogOnValidationWarnings: checkboxValue(
				fields.dialogOnValidationWarnings || 'wtt_dialog_on_validation_warnings'
			),
			defaultRenderDepth: numberValue(
				fields.defaultRenderDepth || 'wtt_default_render_depth',
				1
			),
			developmentMode: checkboxValue(fields.developmentMode || 'wtt_development_mode'),
			treeIconKeys: treeIconKeysState(),
			catalogBindings: catalogBindingsState(),
		};
	}

	function applySaved() {
		setCheckbox(fields.testMode || 'wtt_test_mode', !!saved.testMode);
		setCheckbox(fields.hideRootNode || 'wtt_hide_root_node', !!saved.hideRootNode);
		setCheckbox(fields.showTypeInTree || 'wtt_show_type_in_tree', !!saved.showTypeInTree);
		setCheckbox(
			fields.showModelDataCounts || 'wtt_show_model_data_counts',
			!!saved.showModelDataCounts
		);
		setCheckbox(fields.showSetChildProps || 'wtt_show_set_child_props', !!saved.showSetChildProps);
		setCheckbox(fields.saveViaButton || 'wtt_save_via_button', !!saved.saveViaButton);
		setSelect(fields.treePickerMode || 'wtt_tree_picker_mode', saved.treePickerMode || 'popup');
		setCheckbox(fields.confirmNodeDelete || 'wtt_confirm_node_delete', !!saved.confirmNodeDelete);
		setCheckbox(
			fields.warnStructuralModelChange || 'wtt_warn_structural_model_change',
			!!saved.warnStructuralModelChange
		);
		setCheckbox(
			fields.dialogOnValidationWarnings || 'wtt_dialog_on_validation_warnings',
			!!saved.dialogOnValidationWarnings
		);
		setNumber(
			fields.defaultRenderDepth || 'wtt_default_render_depth',
			saved.defaultRenderDepth != null ? saved.defaultRenderDepth : 1
		);
		setCheckbox(fields.developmentMode || 'wtt_development_mode', !!saved.developmentMode);
		setTreeIconKeys(saved.treeIconKeys || []);
		setCatalogBindings(saved.catalogBindings || {});
		updateDirty();
	}

	function isDirty() {
		var current = currentState();
		return (
			current.testMode !== !!saved.testMode ||
			current.hideRootNode !== !!saved.hideRootNode ||
			current.showTypeInTree !== !!saved.showTypeInTree ||
			current.showModelDataCounts !== !!saved.showModelDataCounts ||
			current.showSetChildProps !== !!saved.showSetChildProps ||
			current.saveViaButton !== !!saved.saveViaButton ||
			current.treePickerMode !== String(saved.treePickerMode || 'popup') ||
			current.confirmNodeDelete !== !!saved.confirmNodeDelete ||
			current.warnStructuralModelChange !== !!saved.warnStructuralModelChange ||
			current.dialogOnValidationWarnings !== !!saved.dialogOnValidationWarnings ||
			current.defaultRenderDepth !==
				(saved.defaultRenderDepth != null ? Number(saved.defaultRenderDepth) : 1) ||
			current.developmentMode !== !!saved.developmentMode ||
			!iconKeysEqual(current.treeIconKeys, saved.treeIconKeys || []) ||
			!bindingsEqual(current.catalogBindings, saved.catalogBindings || {})
		);
	}

	function updateDirty() {
		var dirty = isDirty();
		undoBtn.disabled = !dirty;
		form.classList.toggle('wtt-settings-form--dirty', dirty);
	}

	undoBtn.addEventListener('click', function (e) {
		e.preventDefault();
		applySaved();
	});

	form.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-wtt-bindings-toggle]');
		if (!btn || !form.contains(btn)) {
			return;
		}
		e.preventDefault();
		var wrap = btn.closest('.wtt-catalog-bindings');
		if (!wrap) {
			return;
		}
		var mode = wrap.getAttribute('data-mode') === 'edit' ? 'view' : 'edit';
		if (mode === 'view') {
			bindingSelects().forEach(syncBindingViewRow);
		}
		setBindingsMode(wrap, mode);
	});

	form.addEventListener('change', function (e) {
		var t = e.target;
		if (t && t.matches && t.matches('select[data-wtt-binding="1"]')) {
			syncBindingViewRow(t);
		}
		updateDirty();
		syncResetCaseButton();
	});
	form.addEventListener('input', updateDirty);
	updateDirty();
	syncResetCaseButton();
	bindResetCaseTree();

	function syncResetCaseButton() {
		var btn = document.getElementById('wtt-settings-reset-case');
		if (!btn) {
			return;
		}
		/* Server gates on saved option — require Development mode already saved ON. */
		btn.disabled = !saved.developmentMode;
	}

	function bindResetCaseTree() {
		var btn = document.getElementById('wtt-settings-reset-case');
		var status = document.getElementById('wtt-settings-reset-case-status');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var i18n = cfg.i18n || {};
			if (!saved.developmentMode) {
				if (status) {
					status.textContent = i18n.resetCaseNeedDev || 'Enable Development mode and save settings first.';
					status.className = 'wtt-settings-reset-status is-error';
				}
				return;
			}
			var msg =
				i18n.confirmResetCase ||
				'Delete all Fallstudie terms and reinstall? This cannot be undone.';
			if (!window.confirm(msg)) {
				return;
			}
			btn.disabled = true;
			if (status) {
				status.textContent = i18n.resetCaseWorking || 'Resetting…';
				status.className = 'wtt-settings-reset-status';
			}
			var body = new FormData();
			body.append('action', 'wtt_reset_demo');
			body.append('nonce', cfg.nonce || '');
			body.append('taxonomy', cfg.taxonomy || 'wtt_fs');
			fetch(cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''), {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (json) {
					syncResetCaseButton();
					if (!json || !json.success) {
						var err =
							(json && json.data && json.data.message) ||
							i18n.error ||
							'Error';
						if (status) {
							status.textContent = err;
							status.className = 'wtt-settings-reset-status is-error';
						}
						return;
					}
					if (status) {
						status.textContent =
							(json.data && json.data.message) ||
							i18n.resetCaseDone ||
							'Case tree reset and reinstalled.';
						status.className = 'wtt-settings-reset-status is-ok';
					}
				})
				.catch(function () {
					syncResetCaseButton();
					if (status) {
						status.textContent = i18n.error || 'Error';
						status.className = 'wtt-settings-reset-status is-error';
					}
				});
		});
	}
})();
