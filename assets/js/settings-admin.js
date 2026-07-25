(function () {
	'use strict';

	var cfg = window.wttSettings || {};
	var saved = cfg.saved || {};
	var fields = cfg.fields || {};
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
		}
	}

	function currentState() {
		return {
			testMode: checkboxValue(fields.testMode || 'wtt_test_mode'),
			showTypeInTree: checkboxValue(fields.showTypeInTree || 'wtt_show_type_in_tree'),
			showSetChildProps: checkboxValue(fields.showSetChildProps || 'wtt_show_set_child_props'),
			saveViaButton: checkboxValue(fields.saveViaButton || 'wtt_save_via_button'),
		};
	}

	function applySaved() {
		setCheckbox(fields.testMode || 'wtt_test_mode', !!saved.testMode);
		setCheckbox(fields.showTypeInTree || 'wtt_show_type_in_tree', !!saved.showTypeInTree);
		setCheckbox(fields.showSetChildProps || 'wtt_show_set_child_props', !!saved.showSetChildProps);
		setCheckbox(fields.saveViaButton || 'wtt_save_via_button', !!saved.saveViaButton);
		updateDirty();
	}

	function isDirty() {
		var current = currentState();
		return (
			current.testMode !== !!saved.testMode ||
			current.showTypeInTree !== !!saved.showTypeInTree ||
			current.showSetChildProps !== !!saved.showSetChildProps ||
			current.saveViaButton !== !!saved.saveViaButton
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

	form.addEventListener('change', updateDirty);
	form.addEventListener('input', updateDirty);
	updateDirty();
})();
