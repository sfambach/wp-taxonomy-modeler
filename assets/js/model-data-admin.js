/**
 * Fill Model Data admin UI — instance CRUD against structure hosts.
 * Uses WTTNodeRender for attribute fields; WTTSampleData for Fill samples.
 *
 * @package WP_Taxonomy_Tree
 */
(function () {
	'use strict';

	var cfg = window.wttModelData || {};
	var i18n = cfg.i18n || {};
	var state = {
		taxonomy: cfg.taxonomy || '',
		structureId: 0,
		structure: null,
		instances: [],
		editingId: '',
		values: {},
		name: '',
		dirty: false,
	};

	function t(key, fallback) {
		return i18n[key] != null && i18n[key] !== '' ? String(i18n[key]) : fallback || key;
	}

	function $(id) {
		return document.getElementById(id);
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		body.append('taxonomy', state.taxonomy);
		body.append('structure_id', String(state.structureId || 0));
		Object.keys(data || {}).forEach(function (key) {
			var val = data[key];
			if (val == null) {
				return;
			}
			body.append(key, typeof val === 'object' ? JSON.stringify(val) : String(val));
		});
		return fetch(cfg.ajaxUrl || ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(function (res) {
			return res.json();
		});
	}

	function setStatus(msg, isError) {
		var el = $('wtt-md-status');
		if (!el) {
			return;
		}
		el.textContent = msg || '';
		el.className = 'wtt-model-data__status' + (isError ? ' is-error' : msg ? ' is-ok' : '');
	}

	function hostsForTaxonomy() {
		var hosts = Array.isArray(cfg.hosts) ? cfg.hosts : [];
		return hosts.filter(function (h) {
			return String(h.taxonomy) === String(state.taxonomy);
		});
	}

	function fillTaxonomySelect() {
		var sel = $('wtt-md-taxonomy');
		if (!sel) {
			return;
		}
		sel.innerHTML = '';
		(cfg.taxonomies || []).forEach(function (tax) {
			var opt = document.createElement('option');
			opt.value = tax.slug;
			opt.textContent = tax.label || tax.slug;
			if (tax.slug === state.taxonomy) {
				opt.selected = true;
			}
			sel.appendChild(opt);
		});
	}

	function fillStructureSelect() {
		var sel = $('wtt-md-structure');
		if (!sel) {
			return;
		}
		sel.innerHTML = '';
		var placeholder = document.createElement('option');
		placeholder.value = '0';
		placeholder.textContent = t('chooseStructure', 'Choose a structure…');
		sel.appendChild(placeholder);

		hostsForTaxonomy().forEach(function (h) {
			var opt = document.createElement('option');
			opt.value = String(h.id);
			var label = h.path || h.name || String(h.id);
			if (h.attributeCount > 0) {
				label +=
					' (' +
					h.attributeCount +
					' ' +
					t('attrsLabel', 'attrs') +
					')';
			}
			opt.textContent = label;
			if (h.attributeCount <= 0) {
				opt.className = 'wtt-model-data__opt--empty';
			}
			sel.appendChild(opt);
		});
		sel.value = String(state.structureId || 0);
	}

	function renderInstanceList() {
		var list = $('wtt-md-instance-list');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		if (!state.structureId) {
			return;
		}
		if (!state.instances.length) {
			var empty = document.createElement('li');
			empty.className = 'wtt-model-data__empty';
			empty.textContent = t('noInstances', 'No instances yet.');
			list.appendChild(empty);
			return;
		}
		state.instances.forEach(function (inst) {
			var li = document.createElement('li');
			li.className =
				'wtt-model-data__instance' +
				(inst.id === state.editingId ? ' is-active' : '');
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button-link wtt-model-data__instance-btn';
			btn.textContent = inst.name || t('unnamed', '(unnamed)');
			btn.addEventListener('click', function () {
				openInstance(inst);
			});
			li.appendChild(btn);
			list.appendChild(li);
		});
	}

	function fieldNode(field) {
		return {
			id: field.id,
			name: field.name,
			typeKey: field.typeKey,
			type: { name: field.typeKey },
			typeId: field.typeId,
			typeName: field.typeName,
		};
	}

	function renderFields() {
		var host = $('wtt-md-fields');
		if (!host) {
			return;
		}
		host.innerHTML = '';
		var fields =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];

		if (!fields.length) {
			var hint = document.createElement('p');
			hint.className = 'description';
			hint.textContent = t(
				'noAttributes',
				'This node has no attributes yet.'
			);
			host.appendChild(hint);
			return;
		}

		var api = window.WTTNodeRender;
		fields.forEach(function (field) {
			var attrId = String(field.id);
			var fixed =
				Array.isArray(field.fixedValues) && field.fixedValues.length
					? String(field.fixedValues[0])
					: '';
			var isReadonly = !!field.readonly || fixed !== '';
			var value =
				fixed !== ''
					? fixed
					: state.values[attrId] != null
						? String(state.values[attrId])
						: '';

			var row = document.createElement('div');
			row.className = 'wtt-object-view__row wtt-model-data__field-row';
			row.setAttribute('role', 'listitem');

			var label = document.createElement('div');
			label.className = 'wtt-object-view__label';
			var labelText = document.createElement('span');
			labelText.className = 'wtt-object-view__label-text';
			labelText.textContent =
				field.name +
				(field.typeName ? ' (' + field.typeName + ')' : '');
			label.appendChild(labelText);
			if (field.inherited) {
				var badge = document.createElement('span');
				badge.className = 'wtt-object-view__badge';
				badge.textContent = t('inherited', 'Inherited');
				label.appendChild(badge);
			}
			if (fixed !== '') {
				var fixedBadge = document.createElement('span');
				fixedBadge.className = 'wtt-object-view__badge';
				fixedBadge.textContent = t('fixed', 'Fixed');
				label.appendChild(fixedBadge);
			}
			row.appendChild(label);

			var valueHost = document.createElement('div');
			valueHost.className = 'wtt-object-view__value';

			var rendered = null;
			if (
				api &&
				api.Registry &&
				field.typeKey &&
				api.isRegisteredType &&
				api.isRegisteredType(field.typeKey)
			) {
				rendered = api.Registry.renderContent(
					fieldNode(field),
					{
						name: 'form',
						mode: isReadonly ? 'display' : 'edit',
						value: value,
						onInput: isReadonly
							? null
							: function (next) {
									state.values[attrId] = String(next == null ? '' : next);
									state.dirty = true;
								},
					},
					isReadonly
				);
			}

			if (rendered) {
				valueHost.appendChild(rendered);
			} else if (isReadonly) {
				valueHost.textContent = value || '—';
			} else {
				var input = document.createElement('input');
				input.type = 'text';
				input.className = 'regular-text';
				input.value = value;
				input.addEventListener('input', function () {
					state.values[attrId] = input.value;
					state.dirty = true;
				});
				valueHost.appendChild(input);
			}

			row.appendChild(valueHost);
			host.appendChild(row);
		});
	}

	function showEditor(show) {
		var editor = $('wtt-md-editor');
		var placeholder = $('wtt-md-placeholder');
		if (editor) {
			editor.hidden = !show;
		}
		if (placeholder) {
			placeholder.hidden = show;
		}
	}

	function openNew() {
		state.editingId = '';
		state.name = '';
		state.values = {};
		state.dirty = false;
		var nameEl = $('wtt-md-name');
		if (nameEl) {
			nameEl.value = '';
		}
		var title = $('wtt-md-editor-title');
		if (title) {
			title.textContent = t('newInstance', 'New instance');
		}
		showEditor(true);
		renderFields();
		renderInstanceList();
		setStatus('');
	}

	function openInstance(inst) {
		state.editingId = inst.id || '';
		state.name = inst.name || '';
		state.values = Object.assign({}, inst.values || {});
		state.dirty = false;
		var nameEl = $('wtt-md-name');
		if (nameEl) {
			nameEl.value = state.name;
		}
		var title = $('wtt-md-editor-title');
		if (title) {
			title.textContent = t('editInstance', 'Edit instance');
		}
		showEditor(true);
		renderFields();
		renderInstanceList();
		setStatus('');
	}

	function loadStructure() {
		var newBtn = $('wtt-md-new');
		if (!state.structureId) {
			state.structure = null;
			state.instances = [];
			state.editingId = '';
			if (newBtn) {
				newBtn.disabled = true;
			}
			showEditor(false);
			renderInstanceList();
			setStatus('');
			return;
		}
		setStatus(t('loading', 'Loading…'));
		post('wtt_model_data_get', {}).then(function (json) {
			if (!json || !json.success) {
				setStatus(
					(json && json.data && json.data.message) || t('error', 'Error'),
					true
				);
				return;
			}
			state.structure = json.data.structure || null;
			state.instances = json.data.instances || [];
			if (newBtn) {
				newBtn.disabled = !(
					state.structure &&
					Array.isArray(state.structure.fields) &&
					state.structure.fields.length
				);
			}
			renderInstanceList();
			if (
				state.structure &&
				(!state.structure.fields || !state.structure.fields.length)
			) {
				showEditor(false);
				var placeholder = $('wtt-md-placeholder');
				if (placeholder) {
					placeholder.hidden = false;
					placeholder.textContent = t(
						'noAttributes',
						'This node has no attributes yet.'
					);
				}
			} else if (!state.editingId && state.instances.length) {
				openInstance(state.instances[0]);
			} else if (!state.editingId) {
				openNew();
			} else {
				var current = state.instances.filter(function (i) {
					return i.id === state.editingId;
				})[0];
				if (current) {
					openInstance(current);
				} else {
					openNew();
				}
			}
			setStatus('');
		}).catch(function () {
			setStatus(t('error', 'Error'), true);
		});
	}

	function collectValues() {
		var out = Object.assign({}, state.values);
		var fields =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];
		fields.forEach(function (field) {
			var attrId = String(field.id);
			if (
				Array.isArray(field.fixedValues) &&
				field.fixedValues.length &&
				(out[attrId] == null || out[attrId] === '')
			) {
				out[attrId] = String(field.fixedValues[0]);
			}
		});
		return out;
	}

	function onSave() {
		var nameEl = $('wtt-md-name');
		var name = nameEl ? nameEl.value.trim() : '';
		if (!name) {
			setStatus(t('instanceName', 'Instance name') + '…', true);
			if (nameEl) {
				nameEl.focus();
			}
			return;
		}
		setStatus(t('loading', 'Loading…'));
		post('wtt_model_data_save', {
			id: state.editingId || '',
			name: name,
			values: collectValues(),
		}).then(function (json) {
			if (!json || !json.success) {
				setStatus(
					(json && json.data && json.data.message) || t('error', 'Error'),
					true
				);
				return;
			}
			state.instances = json.data.instances || [];
			if (json.data.instance) {
				state.editingId = json.data.instance.id;
				state.name = json.data.instance.name;
				state.values = Object.assign({}, json.data.instance.values || {});
			}
			state.dirty = false;
			renderInstanceList();
			var title = $('wtt-md-editor-title');
			if (title) {
				title.textContent = t('editInstance', 'Edit instance');
			}
			setStatus(t('saved', 'Instance saved.'));
		}).catch(function () {
			setStatus(t('error', 'Error'), true);
		});
	}

	function onDelete() {
		if (!state.editingId) {
			openNew();
			return;
		}
		if (!window.confirm(t('confirmDelete', 'Delete this instance?'))) {
			return;
		}
		setStatus(t('loading', 'Loading…'));
		post('wtt_model_data_delete', { id: state.editingId }).then(function (json) {
			if (!json || !json.success) {
				setStatus(
					(json && json.data && json.data.message) || t('error', 'Error'),
					true
				);
				return;
			}
			state.instances = json.data.instances || [];
			state.editingId = '';
			renderInstanceList();
			if (state.instances.length) {
				openInstance(state.instances[0]);
			} else {
				openNew();
			}
			setStatus(t('deleted', 'Instance deleted.'));
		}).catch(function () {
			setStatus(t('error', 'Error'), true);
		});
	}

	function onFillSamples() {
		/* Prefer client map when available; fall back to PHP Sample_Data via AJAX. */
		var Sample = window.WTTSampleData;
		var fields =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];
		var filledLocal = false;
		if (Sample && typeof Sample.forType === 'function') {
			fields.forEach(function (field) {
				var attrId = String(field.id);
				var cur = state.values[attrId] != null ? String(state.values[attrId]).trim() : '';
				if (cur !== '') {
					return;
				}
				if (Array.isArray(field.fixedValues) && field.fixedValues.length) {
					state.values[attrId] = String(field.fixedValues[0]);
					filledLocal = true;
					return;
				}
				var sample = Sample.forType(field.typeKey || field);
				if (sample != null && String(sample) !== '') {
					state.values[attrId] = String(sample);
					filledLocal = true;
				}
			});
			if (filledLocal) {
				state.dirty = true;
				renderFields();
				setStatus(t('fillSamplesHint', 'Filled empty fields from samples.'));
				return;
			}
		}

		post('wtt_model_data_samples', { values: collectValues() }).then(function (json) {
			if (!json || !json.success) {
				setStatus(
					(json && json.data && json.data.message) || t('error', 'Error'),
					true
				);
				return;
			}
			state.values = Object.assign({}, json.data.values || {});
			state.dirty = true;
			renderFields();
			setStatus(t('fillSamplesHint', 'Filled empty fields from samples.'));
		}).catch(function () {
			setStatus(t('error', 'Error'), true);
		});
	}

	function bind() {
		var taxSel = $('wtt-md-taxonomy');
		if (taxSel) {
			taxSel.addEventListener('change', function () {
				state.taxonomy = taxSel.value;
				state.structureId = 0;
				state.editingId = '';
				fillStructureSelect();
				loadStructure();
			});
		}
		var structSel = $('wtt-md-structure');
		if (structSel) {
			structSel.addEventListener('change', function () {
				state.structureId = parseInt(structSel.value, 10) || 0;
				state.editingId = '';
				loadStructure();
			});
		}
		var newBtn = $('wtt-md-new');
		if (newBtn) {
			newBtn.addEventListener('click', openNew);
		}
		var saveBtn = $('wtt-md-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', onSave);
		}
		var delBtn = $('wtt-md-delete');
		if (delBtn) {
			delBtn.addEventListener('click', onDelete);
		}
		var samplesBtn = $('wtt-md-samples');
		if (samplesBtn) {
			samplesBtn.addEventListener('click', onFillSamples);
		}
		var nameEl = $('wtt-md-name');
		if (nameEl) {
			nameEl.addEventListener('input', function () {
				state.name = nameEl.value;
				state.dirty = true;
			});
		}
	}

	function init() {
		if (!$('wtt-model-data-app')) {
			return;
		}
		fillTaxonomySelect();
		fillStructureSelect();
		bind();
		showEditor(false);
		renderInstanceList();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
