/**
 * Fill Model Data admin UI — instance CRUD against structure hosts.
 * Uses WTTNodeRender for attribute fields; WTTSampleData for Fill samples.
 *
 * Identity is automatic (seq / id / created / version / modified) — no free-text name.
 * Below the editor: automatic **list view** of instances for the selected structure
 * (identity columns; click a row to open in the form above).
 *
 * @package WP_Taxonomy_Tree
 */
(function () {
	'use strict';

	var cfg = window.wttModelData || {};
	var i18n = cfg.i18n || {};
	var state = {
		taxonomy: cfg.taxonomy || '',
		structureId: parseInt(cfg.focusHostId, 10) || 0,
		structure: null,
		instances: [],
		editingId: '',
		meta: null,
		values: {},
		dirty: false,
		relatedField: null,
		relatedRows: [],
		relatedSaveTimer: null,
		conflictCount: 0,
	};

	function t(key, fallback) {
		return i18n[key] != null && i18n[key] !== '' ? String(i18n[key]) : fallback || key;
	}

	/**
	 * Q107: Settings `dialogOnValidationWarnings` (default OFF).
	 * Data entry may save with errors (keep red !) and always with warnings.
	 * When a future save path returns warnings[], call this before continuing.
	 *
	 * @param {{ warnings?: string[] }|null|undefined} result
	 * @param {string} [message]
	 * @returns {boolean} true = proceed
	 */
	function confirmValidationWarningsIfNeeded(result, message) {
		if (!cfg.dialogOnValidationWarnings) {
			return true;
		}
		var warnings = result && Array.isArray(result.warnings) ? result.warnings : [];
		if (!warnings.length) {
			return true;
		}
		var msg =
			message ||
			t(
				'dialogOnValidationWarnings',
				'Validation warnings are present. Continue anyway?'
			);
		return window.confirm(msg);
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

	function structureTreeForTaxonomy() {
		var trees = cfg.structureTrees || {};
		var payload = trees[state.taxonomy] || {};
		return {
			roots: Array.isArray(payload.roots) ? payload.roots : [],
			rootId: parseInt(payload.rootId, 10) || 0,
			focusId: parseInt(payload.focusId, 10) || 0,
		};
	}

	function hostPathForId(id) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return '';
		}
		var hosts = hostsForTaxonomy();
		var i;
		for (i = 0; i < hosts.length; i++) {
			if ((parseInt(hosts[i].id, 10) || 0) === id) {
				return hosts[i].path || hosts[i].name || '';
			}
		}
		return '';
	}

	/**
	 * Structure node = TreeChooser (shared WTTNodePicker), not a path <select>.
	 */
	function fillStructureSelect() {
		var host = $('wtt-md-structure');
		if (!host) {
			return;
		}
		host.innerHTML = '';
		if (!window.WTTNodePicker || typeof window.WTTNodePicker.render !== 'function') {
			host.appendChild(
				document.createTextNode(t('error', 'TreeChooser unavailable'))
			);
			return;
		}
		var tree = structureTreeForTaxonomy();
		var selectedLabel = hostPathForId(state.structureId);
		host.appendChild(
			window.WTTNodePicker.render({
				roots: tree.roots,
				selectedId: state.structureId || 0,
				selectedLabel: selectedLabel,
				focusId: tree.focusId || tree.rootId || 0,
				expandFocusBranch: true,
				preferFocus: !state.structureId,
				treePickerMode: cfg.treePickerMode || 'popup',
				allowClear: true,
				showPickedLabel: cfg.treePickerMode === 'inline',
				placeholder: t('chooseStructure', 'Choose a structure…'),
				dialogTitle: t('nodePickerTitle', 'Choose structure node'),
				expandKey: 'model-data-structure:' + String(state.taxonomy || ''),
				i18n: i18n,
				selectable: function (node) {
					return !!(node && (node.selectable || (node.attributeCount || 0) > 0));
				},
				onSelect: function (id) {
					state.structureId = parseInt(id, 10) || 0;
					state.editingId = '';
					state.meta = null;
					fillStructureSelect();
					loadStructure();
				},
			})
		);
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

	function dash(value) {
		var s = value != null ? String(value).trim() : '';
		return s !== '' ? s : '—';
	}

	function pendingLabel() {
		return t('assignedOnSave', 'Assigned on save');
	}

	function formatSeq(seq) {
		var n = parseInt(seq, 10) || 0;
		return n > 0 ? '#' + n : pendingLabel();
	}

	function formatVersion(version) {
		var n = parseInt(version, 10) || 0;
		if (n <= 0) {
			return pendingLabel();
		}
		return t('versionShort', 'v') + n;
	}

	function formatModified(inst) {
		var when = dash(inst.modifiedAtLabel || inst.modifiedAt);
		var who = (inst.modifiedByName || '').trim();
		if (who) {
			return when + ' · ' + who;
		}
		return when;
	}

	/**
	 * UR-S1: red circle + ! → Model versions focused on this host (click only).
	 */
	function renderConflictBadge() {
		var slot = $('wtt-md-conflict-badge');
		if (!slot) {
			return;
		}
		slot.innerHTML = '';
		var count = parseInt(state.conflictCount, 10) || 0;
		var hostId = parseInt(state.structureId, 10) || 0;
		if (!hostId || count <= 0) {
			slot.hidden = true;
			return;
		}
		var base =
			cfg.modelVersionsUrl ||
			'admin.php?page=wp-taxonomy-tree-model-versions';
		var url;
		try {
			url = new URL(base, window.location.origin);
		} catch (e) {
			url = new URL(
				'admin.php?page=wp-taxonomy-tree-model-versions',
				window.location.origin
			);
		}
		url.searchParams.set('host_id', String(hostId));
		var labelTpl =
			t(
				'modelVersionConflictCount',
				'%d model version conflicts — open Conflict resolver'
			) ||
			t(
				'modelVersionConflictBadge',
				'Model version conflicts — open Conflict resolver'
			);
		var label = String(labelTpl).replace('%d', String(count));
		var link = document.createElement('a');
		link.className = 'wtt-conflict-badge';
		link.href = url.toString();
		link.title = label;
		link.setAttribute('aria-label', label);
		link.textContent = '!';
		slot.appendChild(link);
		slot.hidden = false;
	}

	function showInstancesPanel(show) {
		var panel = $('wtt-md-instances');
		if (panel) {
			panel.hidden = !show;
		}
	}

	/**
	 * List view of instances for the current structure (below the form editor).
	 * Columns: Number, Id, Created, Version, Last modified (date · user).
	 */
	function renderInstanceList() {
		var tbody = $('wtt-md-list-tbody');
		if (!tbody) {
			return;
		}
		tbody.innerHTML = '';

		if (!state.structureId) {
			showInstancesPanel(false);
			return;
		}

		showInstancesPanel(true);

		if (!state.instances.length) {
			var emptyRow = document.createElement('tr');
			emptyRow.className = 'wtt-model-data__list-empty';
			var emptyCell = document.createElement('td');
			emptyCell.colSpan = 6;
			emptyCell.className = 'wtt-model-data__empty';
			emptyCell.textContent = t(
				'noInstances',
				'No instances yet. Create one to fill attribute values.'
			);
			emptyRow.appendChild(emptyCell);
			tbody.appendChild(emptyRow);
			return;
		}

		state.instances.forEach(function (inst) {
			var isActive = inst.id === state.editingId;
			var tr = document.createElement('tr');
			tr.className =
				'wtt-model-data__list-row' + (isActive ? ' is-active' : '');
			tr.tabIndex = 0;
			tr.setAttribute('role', 'button');
			tr.setAttribute(
				'aria-label',
				t('openInstance', 'Open instance') +
					' ' +
					formatSeq(inst.seq)
			);
			if (isActive) {
				tr.setAttribute('aria-current', 'true');
			}

			var tdActive = document.createElement('td');
			tdActive.className = 'wtt-model-data__col-active';
			if (isActive) {
				var mark = document.createElement('span');
				mark.className = 'dashicons dashicons-yes-alt';
				mark.setAttribute('aria-hidden', 'true');
				mark.title = t('activeInstance', 'Editing');
				tdActive.appendChild(mark);
			}
			tr.appendChild(tdActive);

			var tdSeq = document.createElement('td');
			tdSeq.className = 'wtt-model-data__col-number';
			tdSeq.textContent = formatSeq(inst.seq);
			tr.appendChild(tdSeq);

			var tdId = document.createElement('td');
			tdId.className = 'wtt-model-data__col-id';
			tdId.textContent = dash(inst.id);
			tr.appendChild(tdId);

			var tdCreated = document.createElement('td');
			tdCreated.textContent = dash(inst.createdAtLabel || inst.createdAt);
			tr.appendChild(tdCreated);

			var tdVersion = document.createElement('td');
			tdVersion.textContent = formatVersion(inst.version);
			tr.appendChild(tdVersion);

			var tdModified = document.createElement('td');
			tdModified.textContent = formatModified(inst);
			tr.appendChild(tdModified);

			function selectRow() {
				openInstance(inst);
			}
			tr.addEventListener('click', selectRow);
			tr.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter' || ev.key === ' ') {
					ev.preventDefault();
					selectRow();
				}
			});

			tbody.appendChild(tr);
		});
	}

	function appendIdentityRow(dl, label, value, isPending) {
		var dt = document.createElement('dt');
		dt.textContent = label;
		var dd = document.createElement('dd');
		dd.textContent = value;
		if (isPending) {
			dd.className = 'is-pending';
		}
		dl.appendChild(dt);
		dl.appendChild(dd);
	}

	function renderIdentity() {
		var dl = $('wtt-md-identity');
		if (!dl) {
			return;
		}
		dl.innerHTML = '';
		var meta = state.meta;
		var pending = !meta || !meta.id;

		appendIdentityRow(
			dl,
			t('runningNumber', 'Number'),
			pending ? pendingLabel() : formatSeq(meta.seq),
			pending
		);
		appendIdentityRow(
			dl,
			t('instanceId', 'Id'),
			pending ? pendingLabel() : dash(meta.id),
			pending
		);
		appendIdentityRow(
			dl,
			t('createdAt', 'Created'),
			pending ? pendingLabel() : dash(meta.createdAtLabel || meta.createdAt),
			pending
		);
		appendIdentityRow(
			dl,
			t('version', 'Version'),
			pending ? pendingLabel() : formatVersion(meta.version),
			pending
		);
		appendIdentityRow(
			dl,
			t('modifiedAt', 'Last modified'),
			pending ? pendingLabel() : formatModified(meta),
			pending
		);
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

	/**
	 * First Mult many structured attribute (Bauteilliste → Position, …).
	 * Those rows live in links[] — not as a host scalar / global orphan list.
	 */
	function relatedDatasetField() {
		var fields =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];
		var i;
		for (i = 0; i < fields.length; i++) {
			if (fields[i] && fields[i].isRelatedDataset) {
				return fields[i];
			}
		}
		return null;
	}

	function scalarFields() {
		var fields =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];
		return fields.filter(function (field) {
			return !(field && field.isRelatedDataset);
		});
	}

	/**
	 * Q106: scalar default templates → Model_Data store string.
	 * Mult-many → full list (JSON array when >1); Mult-1 → first only.
	 * Nested maps (related Mult) are skipped — those materialize via links.
	 *
	 * @param {object} field
	 * @returns {string}
	 */
	function encodeFixedSeed(field) {
		if (!field || !Array.isArray(field.fixedValues) || !field.fixedValues.length) {
			return '';
		}
		var scalars = [];
		field.fixedValues.forEach(function (v) {
			if (v != null && typeof v === 'object') {
				return;
			}
			var s = String(v == null ? '' : v).trim();
			if (s !== '') {
				scalars.push(s);
			}
		});
		if (!scalars.length) {
			return '';
		}
		var many =
			!!field.allowsMany ||
			field.multiplicity === '0..*' ||
			field.multiplicity === '1..*';
		if (!many) {
			return scalars[0];
		}
		if (scalars.length === 1) {
			return scalars[0];
		}
		try {
			return JSON.stringify(scalars);
		} catch (e) {
			return scalars[0];
		}
	}

	/**
	 * Seed empty scalar slots from schema default templates (open-new / fill).
	 *
	 * @param {object} [values]
	 * @returns {object}
	 */
	function applyScalarDefaults(values) {
		var out = Object.assign({}, values || {});
		scalarFields().forEach(function (field) {
			var attrId = String(field.id);
			var cur = out[attrId] != null ? String(out[attrId]).trim() : '';
			if (cur !== '') {
				return;
			}
			var seed = encodeFixedSeed(field);
			if (seed !== '') {
				out[attrId] = seed;
			}
		});
		return out;
	}

	function relatedAttrs(field) {
		if (!field) {
			return [];
		}
		if (Array.isArray(field.typeProperties) && field.typeProperties.length) {
			/* Pass embed / CatalogChoice chrome through for UR-B6 Wert→Bauteil cells. */
			return field.typeProperties.map(function (p) {
				return {
					id: p.id,
					name: p.name || '',
					typeKey: p.typeKey || p.typeName || 'text',
					typeName: p.typeName || '',
					typeId: p.typeId || 0,
					fixedRootId: p.fixedRootId || p.typeId || 0,
					fixedMode: p.fixedMode || '',
					fixedOptions: Array.isArray(p.fixedOptions)
						? p.fixedOptions
						: [],
					choiceDepth: p.choiceDepth != null ? p.choiceDepth : 0,
					typeProperties: Array.isArray(p.typeProperties)
						? p.typeProperties
						: [],
					typePreferredRender: p.typePreferredRender || '',
					preferredRender:
						p.preferredRender || p.typePreferredRender || '',
					multiplicity: p.multiplicity || '1',
					allowsMany: !!p.allowsMany,
					allowsEmpty:
						p.allowsEmpty != null
							? !!p.allowsEmpty
							: String(p.multiplicity || '1') === '0..1' ||
							  String(p.multiplicity || '1') === '0..*',
					readonly: !!p.readonly,
				};
			});
		}
		return [];
	}

	function relatedInstancesForTable(field, rows) {
		var attrs = relatedAttrs(field);
		return (rows || []).map(function (linkRow) {
			var inst = (linkRow && linkRow.instance) || {};
			var vals = {};
			var raw = inst.values && typeof inst.values === 'object' ? inst.values : {};
			Object.keys(raw).forEach(function (k) {
				vals[String(k)] = raw[k] == null ? '' : String(raw[k]);
			});
			return {
				id: String(inst.id || linkRow.instanceId || ''),
				attributes: attrs,
				values: vals,
				structureId:
					parseInt(linkRow.structureId || field.typeId, 10) || 0,
			};
		});
	}

	function showRelatedPanel(show) {
		var panel = $('wtt-md-related');
		if (panel) {
			panel.hidden = !show;
		}
	}

	function updateAddLineButton() {
		var btn = $('wtt-md-add-line');
		if (!btn) {
			return;
		}
		var field = state.relatedField;
		var can =
			!!field &&
			!!state.editingId &&
			(parseInt(field.typeId, 10) || 0) > 0;
		btn.disabled = !can;
		btn.title = can
			? t('addLine', 'Add line')
			: t(
					'addLineNeedSave',
					'Save the parent instance before adding related lines.'
				);
	}

	function renderRelatedLines() {
		var field = relatedDatasetField();
		state.relatedField = field;
		var host = $('wtt-md-related-table');
		var title = $('wtt-md-related-title');
		var hint = $('wtt-md-related-hint');
		var ObjectRender = window.WTTObjectRender;

		if (!field) {
			showRelatedPanel(false);
			state.relatedRows = [];
			if (host) {
				host.innerHTML = '';
			}
			updateAddLineButton();
			return;
		}

		showRelatedPanel(true);
		if (title) {
			title.textContent =
				field.name || t('relatedLines', 'Related lines');
		}
		if (hint) {
			hint.textContent = t(
				'relatedLinesHint',
				'Composition/aggregation Mult many rows for this instance (not a global orphan list).'
			);
		}
		updateAddLineButton();

		if (!host) {
			return;
		}
		host.innerHTML = '';

		if (!state.editingId) {
			var need = document.createElement('p');
			need.className = 'description';
			need.textContent = t(
				'addLineNeedSave',
				'Save the parent instance before adding related lines.'
			);
			host.appendChild(need);
			return;
		}

		var attrs = relatedAttrs(field);
		var instances = relatedInstancesForTable(field, state.relatedRows);

		if (
			!ObjectRender ||
			typeof ObjectRender.renderTable !== 'function' ||
			!attrs.length
		) {
			var empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = t('noRelatedLines', 'No related lines yet.');
			host.appendChild(empty);
			return;
		}

		if (!instances.length) {
			var none = document.createElement('p');
			none.className = 'description';
			none.textContent = t('noRelatedLines', 'No related lines yet.');
			host.appendChild(none);
		}

		host.appendChild(
			ObjectRender.renderTable(instances, {
				readonly: false,
				attributes: attrs,
				className: 'wtt-object-view__table wtt-model-data__bom-table',
				onFieldInput: function (col, next, instance) {
					if (!instance || !instance.id) {
						return;
					}
					var idKey =
						col && col.id != null
							? String(col.id)
							: String((col && col.name) || '');
					if (!instance.values) {
						instance.values = {};
					}
					instance.values[idKey] = next == null ? '' : String(next);
					scheduleSaveLine(instance);
				},
			})
		);
	}

	function loadRelatedLines() {
		var field = relatedDatasetField();
		state.relatedField = field;
		if (!field || !state.editingId) {
			state.relatedRows = [];
			renderRelatedLines();
			return Promise.resolve();
		}
		return post('wtt_model_data_related', {
			id: state.editingId,
			child_structure_id: parseInt(field.typeId, 10) || 0,
			relation: field.binding || 'besteht_aus',
		})
			.then(function (json) {
				if (!json || !json.success) {
					state.relatedRows = [];
					renderRelatedLines();
					return;
				}
				state.relatedRows = Array.isArray(json.data.related)
					? json.data.related
					: [];
				renderRelatedLines();
			})
			.catch(function () {
				state.relatedRows = [];
				renderRelatedLines();
			});
	}

	function scheduleSaveLine(instance) {
		if (state.relatedSaveTimer) {
			clearTimeout(state.relatedSaveTimer);
		}
		state.relatedSaveTimer = setTimeout(function () {
			state.relatedSaveTimer = null;
			saveRelatedLine(instance);
		}, 400);
	}

	function saveRelatedLine(instance) {
		var field = state.relatedField || relatedDatasetField();
		if (!field || !instance || !instance.id) {
			return;
		}
		post('wtt_model_data_save_line', {
			id: state.editingId,
			child_structure_id: parseInt(field.typeId, 10) || 0,
			child_instance_id: String(instance.id),
			relation: field.binding || 'besteht_aus',
			values: instance.values || {},
		}).then(function (json) {
			if (!json || !json.success) {
				setStatus(
					(json && json.data && json.data.message) || t('error', 'Error'),
					true
				);
				return;
			}
			if (Array.isArray(json.data.related)) {
				state.relatedRows = json.data.related;
				renderRelatedLines();
			}
			setStatus(t('lineSaved', 'Line saved.'));
		}).catch(function () {
			setStatus(t('error', 'Error'), true);
		});
	}

	function onAddLine() {
		var field = state.relatedField || relatedDatasetField();
		if (!field || !state.editingId) {
			setStatus(
				t(
					'addLineNeedSave',
					'Save the parent instance before adding related lines.'
				),
				true
			);
			return;
		}
		setStatus(t('loading', 'Loading…'));
		post('wtt_model_data_create_line', {
			id: state.editingId,
			child_structure_id: parseInt(field.typeId, 10) || 0,
			relation: field.binding || 'besteht_aus',
			values: {},
		})
			.then(function (json) {
				if (!json || !json.success) {
					setStatus(
						(json && json.data && json.data.message) ||
							t('error', 'Error'),
						true
					);
					return;
				}
				state.relatedRows = Array.isArray(json.data.related)
					? json.data.related
					: [];
				renderRelatedLines();
				setStatus(t('lineCreated', 'Line created.'));
			})
			.catch(function () {
				setStatus(t('error', 'Error'), true);
			});
	}

	function renderFields() {
		var host = $('wtt-md-fields');
		if (!host) {
			return;
		}
		host.innerHTML = '';
		var fields = scalarFields();

		if (!fields.length) {
			var all =
				state.structure && Array.isArray(state.structure.fields)
					? state.structure.fields
					: [];
			if (!all.length) {
				var hint = document.createElement('p');
				hint.className = 'description';
				hint.textContent = t(
					'noAttributes',
					'This node has no attributes yet.'
				);
				host.appendChild(hint);
			}
			return;
		}

		var api = window.WTTNodeRender;
		fields.forEach(function (field) {
			var attrId = String(field.id);
			/* Q106: defaults are templates/seeds, not locks (RO is separate — OQ-A3). */
			var seed = encodeFixedSeed(field);
			var isReadonly = !!field.readonly;
			var value =
				state.values[attrId] != null && String(state.values[attrId]) !== ''
					? String(state.values[attrId])
					: seed;

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
			if (seed !== '' && !isReadonly) {
				var defaultBadge = document.createElement('span');
				defaultBadge.className = 'wtt-object-view__badge';
				defaultBadge.textContent = t('defaultValue', 'Default');
				label.appendChild(defaultBadge);
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
									state.values[attrId] = String(
										next == null ? '' : next
									);
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
				/*
				 * OQ-R6 lean B: unregistered scalars use Registry default path when
				 * present; otherwise a plain text control (no parallel Form UI).
				 */
				var defaultRendered =
					api &&
					api.Registry &&
					typeof api.Registry.renderContent === 'function'
						? api.Registry.renderContent(
								fieldNode(field),
								{
									name: 'form',
									mode: 'edit',
									value: value,
									onInput: function (next) {
										state.values[attrId] = String(
											next == null ? '' : next
										);
										state.dirty = true;
									},
								},
								false
							)
						: null;
				if (defaultRendered) {
					valueHost.appendChild(defaultRendered);
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

	function applyInstanceMeta(inst) {
		if (!inst) {
			state.meta = null;
			return;
		}
		state.meta = {
			id: inst.id || '',
			seq: inst.seq || 0,
			createdAt: inst.createdAt || '',
			createdAtLabel: inst.createdAtLabel || '',
			version: inst.version || 0,
			modifiedAt: inst.modifiedAt || '',
			modifiedAtLabel: inst.modifiedAtLabel || '',
			modifiedBy: inst.modifiedBy || 0,
			modifiedByName: inst.modifiedByName || '',
		};
	}

	function openNew() {
		state.editingId = '';
		state.meta = null;
		/* Q106: seed all scalar default templates into the draft (Mult-many = full list). */
		state.values = applyScalarDefaults({});
		state.dirty = false;
		state.relatedRows = [];
		var title = $('wtt-md-editor-title');
		if (title) {
			title.textContent = t('newInstance', 'New instance');
		}
		showEditor(true);
		renderIdentity();
		renderFields();
		renderRelatedLines();
		renderInstanceList();
		setStatus('');
	}

	function openInstance(inst) {
		state.editingId = inst.id || '';
		applyInstanceMeta(inst);
		state.values = Object.assign({}, inst.values || {});
		state.dirty = false;
		var title = $('wtt-md-editor-title');
		if (title) {
			title.textContent =
				t('editInstance', 'Edit instance') +
				' ' +
				formatSeq(inst.seq);
		}
		showEditor(true);
		renderIdentity();
		renderFields();
		renderInstanceList();
		loadRelatedLines();
		setStatus('');
	}

	function loadStructure() {
		var newBtn = $('wtt-md-new');
		if (!state.structureId) {
			state.structure = null;
			state.instances = [];
			state.editingId = '';
			state.meta = null;
			state.conflictCount = 0;
			if (newBtn) {
				newBtn.disabled = true;
			}
			showEditor(false);
			showInstancesPanel(false);
			renderConflictBadge();
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
			state.conflictCount = parseInt(json.data.conflictCount, 10) || 0;
			if (newBtn) {
				newBtn.disabled = !(
					state.structure &&
					Array.isArray(state.structure.fields) &&
					state.structure.fields.length
				);
			}
			renderConflictBadge();
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
		var out = applyScalarDefaults(state.values);
		/* Strip related-dataset slots — lines are links[], not host values. */
		var all =
			state.structure && Array.isArray(state.structure.fields)
				? state.structure.fields
				: [];
		all.forEach(function (field) {
			if (field && field.isRelatedDataset) {
				delete out[String(field.id)];
			}
		});
		return out;
	}

	function onSave() {
		setStatus(t('loading', 'Loading…'));
		post('wtt_model_data_save', {
			id: state.editingId || '',
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
			state.conflictCount = parseInt(json.data.conflictCount, 10) || 0;
			if (json.data.instance) {
				state.editingId = json.data.instance.id;
				applyInstanceMeta(json.data.instance);
				state.values = Object.assign({}, json.data.instance.values || {});
			}
			state.dirty = false;
			renderIdentity();
			renderConflictBadge();
			renderInstanceList();
			loadRelatedLines();
			var title = $('wtt-md-editor-title');
			if (title && state.meta) {
				title.textContent =
					t('editInstance', 'Edit instance') +
					' ' +
					formatSeq(state.meta.seq);
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
			state.conflictCount = parseInt(json.data.conflictCount, 10) || 0;
			state.editingId = '';
			state.meta = null;
			renderConflictBadge();
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
		var fields = scalarFields();
		var filledLocal = false;
		var beforeDefaults = JSON.stringify(state.values || {});
		state.values = applyScalarDefaults(state.values);
		if (JSON.stringify(state.values || {}) !== beforeDefaults) {
			filledLocal = true;
		}
		if (Sample && typeof Sample.forType === 'function') {
			fields.forEach(function (field) {
				var attrId = String(field.id);
				var cur = state.values[attrId] != null ? String(state.values[attrId]).trim() : '';
				if (cur !== '') {
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
				state.meta = null;
				configureObjectRenderApi();
				fillStructureSelect();
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
		var addLineBtn = $('wtt-md-add-line');
		if (addLineBtn) {
			addLineBtn.addEventListener('click', onAddLine);
		}
	}

	/**
	 * Wire shared ObjectRender embed (UR-B6) to Fill Model Data AJAX.
	 */
	function configureObjectRenderApi() {
		var ObjectRender = window.WTTObjectRender;
		if (!ObjectRender) {
			return;
		}
		if (typeof ObjectRender.setSchemaLoader === 'function') {
			ObjectRender.setSchemaLoader(function (termId) {
				var id = parseInt(termId, 10) || 0;
				if (id <= 0) {
					return Promise.resolve({ attributes: [] });
				}
				/* Reuse model-data get against the kind structure id. */
				var body = new FormData();
				body.append('action', 'wtt_model_data_get');
				body.append('nonce', cfg.nonce || '');
				body.append('taxonomy', state.taxonomy);
				body.append('structure_id', String(id));
				return fetch(cfg.ajaxUrl || ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				})
					.then(function (res) {
						return res.json();
					})
					.then(function (json) {
						var fields =
							json &&
							json.success &&
							json.data &&
							json.data.structure &&
							Array.isArray(json.data.structure.fields)
								? json.data.structure.fields
								: [];
						return { attributes: fields };
					});
			});
		}
		if (typeof ObjectRender.configure === 'function') {
			ObjectRender.configure({
				taxonomy: state.taxonomy,
				i18n: i18n,
				modelDataApi: {
					taxonomy: state.taxonomy,
					listInstances: function (structureId, taxonomy) {
						var body = new FormData();
						body.append('action', 'wtt_model_data_get');
						body.append('nonce', cfg.nonce || '');
						body.append(
							'taxonomy',
							taxonomy || state.taxonomy || ''
						);
						body.append(
							'structure_id',
							String(parseInt(structureId, 10) || 0)
						);
						return fetch(cfg.ajaxUrl || ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: body,
						})
							.then(function (res) {
								return res.json();
							})
							.then(function (json) {
								if (!json || !json.success) {
									return [];
								}
								return Array.isArray(json.data.instances)
									? json.data.instances
									: [];
							});
					},
					createInstance: function (structureId, values, taxonomy) {
						var body = new FormData();
						body.append('action', 'wtt_model_data_save');
						body.append('nonce', cfg.nonce || '');
						body.append(
							'taxonomy',
							taxonomy || state.taxonomy || ''
						);
						body.append(
							'structure_id',
							String(parseInt(structureId, 10) || 0)
						);
						body.append('id', '');
						body.append(
							'values',
							JSON.stringify(values && typeof values === 'object' ? values : {})
						);
						return fetch(cfg.ajaxUrl || ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: body,
						})
							.then(function (res) {
								return res.json();
							})
							.then(function (json) {
								if (!json || !json.success) {
									throw new Error(
										(json &&
											json.data &&
											json.data.message) ||
											t('error', 'Error')
									);
								}
								return json.data.instance || null;
							});
					},
				},
			});
		}
	}

	function init() {
		if (!$('wtt-model-data-app')) {
			return;
		}
		configureObjectRenderApi();
		fillTaxonomySelect();
		fillStructureSelect();
		bind();
		showEditor(false);
		renderInstanceList();
		if (state.structureId > 0) {
			loadStructure();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
