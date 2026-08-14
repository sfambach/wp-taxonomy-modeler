(function () {
	/** Q123: attribute id = Relation edge id (string); legacy numeric slot ids still work. */
	function wttAttrId(attrOrId) {
		if (attrOrId && typeof attrOrId === 'object') {
			return attrOrId.id != null ? String(attrOrId.id) : '';
		}
		return attrOrId != null && attrOrId !== '' ? String(attrOrId) : '';
	}


	'use strict';

	var cfg = window.wttTree || {};
	var i18n = cfg.i18n || {};

	/** Shared Config / Settings chrome (Q114 / Q126) — one ConfigPage everywhere. */
	function settingsRender() {
		return window.WTTConfigRender || window.WTTSettingsRender || null;
	}
	function configRender() {
		return settingsRender();
	}
	var state = {
		taxonomy: cfg.taxonomy || 'wtt_fs',
		tree: Array.isArray(cfg.tree) ? cfg.tree : [],
		selectedId: null,
		selectedIds: {},
		selectionAnchorId: null,
		selectedNode: null,
		draft: null,
		savedDraft: null,
		settingsSaving: false,
		enumValuesSaving: false,
		enumValuesDraft: null,
		enumValuesDirty: false,
		autosaving: false,
		expanded: {},
		error: '',
		/* Interactive Editable preview samples (not persisted to terms). */
		previewValues: {},
		previewFocus: null,
		/* Deferred Choices ticks (flush on leave — not per checkbox). */
		choiceFilterDrafts: {},
		/* Walk hydrate arrived while Options control focused — paint after leave. */
		attrWalkRenderDeferred: false,
		/* Explicit node_embed picks (survives scope/key mismatches). */
		embedPicks: {},
		/* Cached get_node payloads for dynamic node_embed field panels. */
		dynamicRefCache: {},
		dynamicRefLoading: 0,
		/* Expand / open maps for unified node tree picker (survive render). */
		nodePickerExpanded: {},
		nodePickerOpen: {},
		nodePickerQuery: {},
		/* Drag-and-drop reparent: term ids currently being moved. */
		dragMoveIds: null,
		/* Relations UI: hide synthetic child_of rows (default on). */
		hideChildOfRelations: true,
		/* Relations panel: collapsed by default; stay open across re-renders until node change. */
		relationsPanelOpen: false,
		/* Attributes panel: expanded by default; stay open across re-renders until node change. */
		attributesPanelOpen: true,
		/* Taxonomy-wide RelationType catalog (lazy via wtt_get_relation_types). */
		relationCatalog: null,
		relationCatalogLoading: null,
		/* Attribute Options detail rows: expand per attr id (UI session only). */
		attrDetailExpanded: {},
		/* In-flight Settings-walk hydrations (attr id → Promise). */
		attrWalkLoading: {},
		/* Session cache: taxonomy-wide datatype tree (omit rebuild noise across selects). */
		datatypeTreeCache: null,
		/* Hide project root in tree column (from settings; default on). */
		hideRootNode: cfg.hideRootNode !== false && cfg.hideRootNode !== 0 && cfg.hideRootNode !== '0',
		/* Show Model_Data instance counts on structure-host labels (default off). */
		showModelDataCounts:
			cfg.showModelDataCounts === true ||
			cfg.showModelDataCounts === 1 ||
			cfg.showModelDataCounts === '1',
	};
	var autosaveTimer = null;
	var autosaveSeq = 0;
	/* True only after an actual drag move (not mere HTML5 dragstart on click). */
	var dragDidMove = false;
	/* Ignore stale leaveFlush / get_node responses from superseded selects. */
	var selectSeq = 0;
	/* Serialize settings saves; latest draft per term wins (no cross-term overwrite). */
	var settingsSaveChain = Promise.resolve();
	var settingsSavePending = {};

	/**
	 * @param {unknown} a
	 * @param {unknown} b
	 * @return {boolean}
	 */
	function sameTermId(a, b) {
		var left = parseInt(a, 10) || 0;
		var right = parseInt(b, 10) || 0;
		return left > 0 && left === right;
	}

	var DEFAULT_TABLE_TYPE_PROPS = [
		{ id: 'kopf', key: 'kopf', name: 'Kopf', valueType: 'subnode', required: false },
		{ id: 'zeile', key: 'zeile', name: 'Zeile', valueType: 'subnode', required: true },
		{ id: 'fuss', key: 'fuss', name: 'Fuss', valueType: 'subnode', required: false },
	];

	/**
	 * Band bindings must be a plain object. PHP encodes empty bindings as JSON [],
	 * and assigning keys onto an Array + JSON.stringify/deepClone drops them ("[]").
	 * That looked Bom-only when Bom was unbound while Partner already had {"zeile":â€¦}.
	 *
	 * @param {unknown} raw
	 * @return {Object<string, number>}
	 */
	function normalizePropBindings(raw) {
		var out = {};
		if (!raw || typeof raw !== 'object') {
			return out;
		}
		Object.keys(raw).forEach(function (k) {
			var key = String(k || '');
			var id = parseInt(raw[k], 10) || 0;
			if (!key || id <= 0) {
				return;
			}
			out[key] = id;
		});
		return out;
	}

	/**
	 * Ensure table draft has band props so Bindings UI appears as soon as type=table.
	 */
	function ensureTableDraftChrome(draft) {
		if (!draft || !(draft.isTable || draft.isTableTypeCatalog)) {
			return;
		}
		if (!Array.isArray(draft.effectiveTypeProps) || !draft.effectiveTypeProps.length) {
			draft.effectiveTypeProps = deepClone(DEFAULT_TABLE_TYPE_PROPS);
		}
		draft.propBindings = normalizePropBindings(draft.propBindings);
		if (!Array.isArray(draft.directChildren)) {
			draft.directChildren = [];
		}
		if (
			!draft.directChildren.length &&
			state.selectedNode &&
			Array.isArray(state.selectedNode.directChildren)
		) {
			draft.directChildren = deepClone(state.selectedNode.directChildren);
		}
	}

	function saveViaButtonEnabled() {
		return !!cfg.saveViaButton;
	}

	/**
	 * Trial layout: flags + static meta as form rows (label left, chips right).
	 * Flip via window.wttTree.flagsAsFormRow = false (PHP: flagsAsFormRow).
	 */
	function flagsAsFormRowEnabled() {
		return cfg.flagsAsFormRow !== false;
	}

	function caseStudyMode() {
		return !!cfg.caseStudyMode || state.taxonomy === 'wtt_fs';
	}

	function deepClone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function uiStorageKey(taxonomy) {
		return 'wtt.treeUi.v1.' + String(taxonomy || 'wtt_fs');
	}

	function collectTreeIds(nodes, out) {
		out = out || {};
		(nodes || []).forEach(function (node) {
			if (!node || node.id == null) {
				return;
			}
			out[String(node.id)] = true;
			if (node.children && node.children.length) {
				collectTreeIds(node.children, out);
			}
		});
		return out;
	}

	function persistTreeUi() {
		try {
			if (!window.localStorage) {
				return;
			}
			var expandedIds = [];
			Object.keys(state.expanded || {}).forEach(function (id) {
				if (state.expanded[id]) {
					expandedIds.push(String(id));
				}
			});
			window.localStorage.setItem(
				uiStorageKey(state.taxonomy),
				JSON.stringify({
					expanded: expandedIds,
					selectedId: state.selectedId ? String(state.selectedId) : null,
					hideChildOfRelations: !!state.hideChildOfRelations,
				})
			);
		} catch (err) {
			/* ignore quota / private mode */
		}
	}

	function restoreTreeUi() {
		try {
			if (!window.localStorage) {
				return;
			}
			var raw = window.localStorage.getItem(uiStorageKey(state.taxonomy));
			if (!raw) {
				return;
			}
			var data = JSON.parse(raw);
			if (!data || typeof data !== 'object') {
				return;
			}
			var known = collectTreeIds(state.tree, {});
			state.expanded = {};
			(Array.isArray(data.expanded) ? data.expanded : []).forEach(function (id) {
				var key = String(id);
				if (known[key]) {
					state.expanded[key] = true;
					state.expanded[parseInt(key, 10)] = true;
				}
			});
			if (data.selectedId != null && known[String(data.selectedId)]) {
				state.selectedId = parseInt(data.selectedId, 10) || data.selectedId;
				state.selectedIds = {};
				state.selectedIds[state.selectedId] = true;
				state.selectionAnchorId = state.selectedId;
			}
			if (typeof data.hideChildOfRelations === 'boolean') {
				state.hideChildOfRelations = data.hideChildOfRelations;
			}
		} catch (err) {
			/* ignore bad JSON */
		}
	}

	/* Q113: Preferred / object layout wire ids (legacy form|table|… accepted on read). */
	var PREFERRED_LAYOUTS = [
		{
			value: 'FormRenderer',
			labelKey: 'preferredRenderForm',
			fallback: 'Form',
		},
		{
			value: 'TableRenderer',
			labelKey: 'preferredRenderTable',
			fallback: 'Table',
		},
		{
			value: 'CompactRenderer',
			labelKey: 'preferredRenderCompact',
			fallback: 'Compact (horizontal)',
		},
		{
			value: 'CompactVerticalRenderer',
			labelKey: 'preferredRenderCompactVertical',
			fallback: 'Compact (vertical)',
		},
		{
			value: 'EmbeddedRenderer',
			labelKey: 'preferredRenderEmbed',
			fallback: 'Embedded renderer',
		},
		{
			value: 'ChildListRenderer',
			labelKey: 'preferredRenderChildList',
			fallback: 'Child list',
		},
	];

	/**
	 * Normalize preferred render: object layouts → FormRenderer|…;
	 * field paint ids stay short (int, bool, …) for Registry; IntRenderer→int.
	 */
	function normalizePreferredRender(raw) {
		var s = String(raw == null || raw === '' ? 'FormRenderer' : raw).trim();
		var key = s.toLowerCase();
		var objectMap = {
			form: 'FormRenderer',
			formrenderer: 'FormRenderer',
			table: 'TableRenderer',
			tablerenderer: 'TableRenderer',
			list: 'TableRenderer',
			compact: 'CompactRenderer',
			compactrenderer: 'CompactRenderer',
			'compact-horizontal': 'CompactRenderer',
			'compact-h': 'CompactRenderer',
			'compact-vertical': 'CompactVerticalRenderer',
			compactverticalrenderer: 'CompactVerticalRenderer',
			'compact-v': 'CompactVerticalRenderer',
			embed: 'EmbeddedRenderer',
			embeddedrenderer: 'EmbeddedRenderer',
			'pick-fill': 'EmbeddedRenderer',
			pick_fill: 'EmbeddedRenderer',
			'compact-embed': 'EmbeddedRenderer',
			child_list: 'ChildListRenderer',
			childlist: 'ChildListRenderer',
			childlistrenderer: 'ChildListRenderer',
		};
		if (objectMap[key]) {
			return objectMap[key];
		}
		/* PHP field wire ids → Registry short paint ids (do not rename Registry). */
		var fieldWireToShort = {
			intrenderer: 'int',
			integer: 'int',
			doublerenderer: 'double',
			float: 'double',
			textrenderer: 'text',
			textarearenderer: 'textarea',
			charrenderer: 'char',
			boolrenderer: 'bool',
			boolean: 'bool',
			boolswitchrenderer: 'bool',
			bool_checkbox: 'bool_checkbox',
			boolcheckboxrenderer: 'bool_checkbox',
			bool_radio: 'bool_radio',
			boolradiorenderer: 'bool_radio',
			emailrenderer: 'email',
			daterenderer: 'date',
			timerenderer: 'time',
			datetimerenderer: 'datetime',
			datetime: 'datetime',
			colorrenderer: 'color',
			int_spinner: 'int_spinner',
			intspinnerrenderer: 'int_spinner',
			int_range: 'int_range',
			intrangerenderer: 'int_range',
			double_spinner: 'double_spinner',
			doublespinnerrenderer: 'double_spinner',
			double_range: 'double_range',
			doublerangerenderer: 'double_range',
			mediarenderer: 'media',
			displaynodenamerenderer: 'node_presentation',
			display_node_name: 'node_presentation',
			nodepresentationrenderer: 'node_presentation',
			node_presentation: 'node_presentation',
			quantityrenderer: 'quantity',
			unitrenderer: 'unit',
			unit: 'unit',
			basiseinheit: 'unit',
			noderefrenderer: 'node_ref',
			node_ref: 'node_ref',
		};
		if (fieldWireToShort[key]) {
			return fieldWireToShort[key];
		}
		/* Field renderer ids (int, bool, …) — keep when well-formed. */
		if (/^[a-z][a-z0-9_-]*$/.test(key)) {
			return key;
		}
		return 'FormRenderer';
	}

	function isNodePresentationTypeKey(raw) {
		var key = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (!key) {
			return false;
		}
		return (
			key === 'node_presentation' ||
			key === 'display_node_name' ||
			key.indexOf('node_presentation') !== -1 ||
			key.indexOf('display_node_name') !== -1 ||
			key === 'nodepresentationrenderer' ||
			key === 'displaynodenamerenderer'
		);
	}

	function normalizePresentationContext(raw) {
		var ctx = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (ctx === 'name') {
			return 'form';
		}
		var allowed = {
			form: 1,
			table: 1,
			select: 1,
			symbol: 1,
			help: 1,
			icon: 1,
		};
		return allowed[ctx] ? ctx : 'form';
	}

	function normalizeTextareaConfig(cfg, typeHint) {
		var hint = String(typeHint == null ? '' : typeHint)
			.trim()
			.toLowerCase();
		if (!cfg && hint !== 'textarea') {
			return null;
		}
		var cols = parseInt(cfg && cfg.cols, 10);
		var rows = parseInt(cfg && cfg.rows, 10);
		if (!isFinite(cols) || cols < 1) {
			cols = 40;
		}
		if (cols > 200) {
			cols = 200;
		}
		if (!isFinite(rows) || rows < 1) {
			rows = 4;
		}
		if (rows > 100) {
			rows = 100;
		}
		return { cols: cols, rows: rows };
	}

	function presentationContextOptions() {
		return [
			{ value: 'form', label: i18n.presentationForm || 'Form' },
			{ value: 'table', label: i18n.presentationTable || 'Table' },
			{ value: 'select', label: i18n.presentationSelect || 'Select' },
			{ value: 'symbol', label: i18n.presentationSymbol || 'Symbol' },
			{ value: 'help', label: i18n.presentationHelp || 'Help' },
			{ value: 'icon', label: i18n.presentationIcon || 'Icon' },
		];
	}

	function presentationContextFromAttr(attr) {
		if (!attr || typeof attr !== 'object') {
			return 'form';
		}
		var extras =
			typeof attributeOptionsExtras === 'function'
				? attributeOptionsExtras(attr)
				: attr.typeExtras || {};
		if (extras && extras.presentationContext) {
			return normalizePresentationContext(extras.presentationContext);
		}
		if (attr.presentationConfig && attr.presentationConfig.context) {
			return normalizePresentationContext(attr.presentationConfig.context);
		}
		if (attr.presentationConfig && attr.presentationConfig.typeContext) {
			return normalizePresentationContext(attr.presentationConfig.typeContext);
		}
		return 'form';
	}

	/**
	 * Flat context→text map from a host / draft node.
	 * get_node sends `{ form, table, symbol, … }`; draft Presentation UI nests
	 * the same keys under `.values` after setDraftPresentationFromServer.
	 */
	function presentationMapFromHost(host) {
		var p = host && host.presentation;
		if (!p || typeof p !== 'object') {
			return null;
		}
		if (p.values && typeof p.values === 'object') {
			return p.values;
		}
		if (
			Object.prototype.hasOwnProperty.call(p, 'form') ||
			Object.prototype.hasOwnProperty.call(p, 'symbol') ||
			Object.prototype.hasOwnProperty.call(p, 'table') ||
			Object.prototype.hasOwnProperty.call(p, 'select')
		) {
			return p;
		}
		return null;
	}

	function resolveHostPresentationValue(host, context) {
		var ctx = normalizePresentationContext(context);
		var map = presentationMapFromHost(host);
		if (map && map[ctx] != null && String(map[ctx]).trim() !== '') {
			return String(map[ctx]).trim();
		}
		if (ctx === 'symbol' || ctx === 'table') {
			var short = String((host && host.shortDescription) || '').trim();
			if (short) {
				return short;
			}
			/* Symbol/table must not fall back to the form name (e.g. Tolerance). */
			return '';
		}
		if (ctx === 'icon') {
			return '';
		}
		return String((host && host.name) || '').trim();
	}

	/**
	 * Preferred render options: only renderers/layouts that can paint this node.
	 */
	function listCompatiblePreferredOptions(n) {
		var opts = [];
		var seen = {};
		function add(value, label) {
			value = normalizePreferredRender(value);
			if (!value || seen[value]) {
				return;
			}
			seen[value] = true;
			opts.push({ value: value, label: label || value });
		}
		var hasAttrs =
			n && Array.isArray(n.attributes) && n.attributes.length > 0;
		/*
		 * Probe must look like a field of this type. Catalog leaves (int) inherit
		 * Q88 type = parent branch (Simple) — resolveNodeRenderTypeKey maps name→int.
		 */
		var resolvedKey = n ? resolveNodeRenderTypeKey(n) : '';
		var probe = n
			? {
					id: n.id,
					name: n.name,
					typeKey: resolvedKey || n.typeKey || typeKeyFromMember(n) || '',
					type: resolvedKey
						? { name: resolvedKey }
						: n.type || (n.typeKey ? { name: n.typeKey } : null),
					typeId: n.typeId,
					quantitySchema: n.quantitySchema,
					setMembers: n.setMembers,
					preferredRender: n.preferredRender,
					attributes: n.attributes,
					embedChoiceOptions: n.embedChoiceOptions,
					isTableTypeCatalog: n.isTableTypeCatalog,
					isTable: n.isTable,
			  }
			: null;
		var NR = window.WTTNodeRender;
		var fieldOpts =
			NR && NR.Registry && typeof NR.Registry.listCompatible === 'function' && probe
				? NR.Registry.listCompatible(probe, { name: 'form', mode: 'edit' })
				: [];
		(fieldOpts || []).forEach(function (o) {
			if (o && o.id) {
				add(o.id, o.label || o.id);
			}
		});
		/*
		 * Always surface the resolved field renderer (e.g. MediaRenderer) even when
		 * Registry.listCompatible briefly misses canRender (typeId = Simple parent).
		 */
		if (resolvedKey && NR && NR.Registry) {
			var byId =
				typeof NR.Registry.getById === 'function'
					? NR.Registry.getById(resolvedKey)
					: null;
			if (byId && byId.id) {
				add(byId.id, byId.label || byId.id);
			} else if (NR.isRegisteredType && NR.isRegisteredType(resolvedKey)) {
				add(resolvedKey, preferredRenderOptionLabel(resolvedKey));
			}
		}
		var curPref = normalizePreferredRender(n && n.preferredRender);
		if (
			curPref &&
			curPref !== 'FormRenderer' &&
			curPref !== 'TableRenderer' &&
			curPref !== 'CompactRenderer' &&
			curPref !== 'CompactVerticalRenderer' &&
			curPref !== 'EmbeddedRenderer' &&
			curPref !== 'ChildListRenderer'
		) {
			add(curPref, preferredRenderOptionLabel(curPref));
		}
		function nodeHasChildListOptions() {
			return (
				!!(n && n.isKonstantenHost) ||
				!!(n && n.hasChildren) ||
				(n && Array.isArray(n.children) && n.children.length > 0) ||
				choiceCatalogPickRoots(n).length > 0
			);
		}
		if (hasAttrs || !(fieldOpts && fieldOpts.length)) {
			PREFERRED_LAYOUTS.forEach(function (o) {
				if (o.value === 'EmbeddedRenderer') {
					/*
					 * Aggregation pick+create: allow for Model hosts with attributes
					 * even without specialization children (Kontakt = type itself).
					 * Kind tree children still preferred when present (Bauteil).
					 */
					var choices = (n && n.embedChoiceOptions) || [];
					if (!choices.length && !hasAttrs && !(n && n.hasModelInstances)) {
						return;
					}
				}
				if (o.value === 'ChildListRenderer' && !nodeHasChildListOptions()) {
					return;
				}
				add(o.value, (i18n && i18n[o.labelKey]) || o.fallback);
			});
		} else if (nodeHasChildListOptions()) {
			/* Field-only hosts still get Child list when they have hierarchy children. */
			add(
				'ChildListRenderer',
				(i18n && i18n.preferredRenderChildList) || 'Child list'
			);
		}
		if (!opts.length) {
			add(
				'FormRenderer',
				(i18n && i18n.preferredRenderForm) || 'Form'
			);
		}
		return opts;
	}

	function defaultPreferredRenderForNode(n) {
		var opts = listCompatiblePreferredOptions(n);
		var cur = normalizePreferredRender(n && n.preferredRender);
		var i;
		for (i = 0; i < opts.length; i++) {
			if (opts[i].value === cur) {
				return cur;
			}
		}
		/* Konstanten hosts with children → Child list when Preferred unset/legacy. */
		if (n && n.isKonstantenHost) {
			return 'ChildListRenderer';
		}
		if (!opts.length) {
			return 'FormRenderer';
		}
		return opts[0].value;
	}

	/**
	 * Effective host Preferred for Settings + Preview (one SoT).
	 * Prefer live draft only when set; never invent Form over a stored Table/Unit/….
	 *
	 * @param {Object} n
	 * @return {string}
	 */
	function effectiveHostPreferredRender(n) {
		if (state.draft && state.draft.preferredRenderInherit) {
			return normalizePreferredRender(
				(n && n.preferredRender) || 'FormRenderer'
			);
		}
		if (
			state.draft &&
			state.draft.preferredRender != null &&
			String(state.draft.preferredRender).trim() !== ''
		) {
			return normalizePreferredRender(state.draft.preferredRender);
		}
		return normalizePreferredRender(
			(n && n.preferredRender) || 'FormRenderer'
		);
	}

	function converterRegistry() {
		return (
			(window.WTTConverter && window.WTTConverter.Registry) || null
		);
	}

	function converterProbeForNode(n) {
		if (!n) {
			return null;
		}
		var resolvedKey = resolveNodeRenderTypeKey(n);
		return {
			id: n.id,
			name: n.name,
			typeKey: resolvedKey || n.typeKey || typeKeyFromMember(n) || '',
			type: resolvedKey
				? { name: resolvedKey }
				: n.type || (n.typeKey ? { name: n.typeKey } : null),
			typeId: n.typeId,
			preferredConverter: n.preferredConverter,
			displayFormat: n.displayFormat,
			intConfig: n.intConfig,
		};
	}

	/**
	 * Preferred converter options: only Registry converters that canConvert this node.
	 */
	function listCompatiblePreferredConverterOptions(n) {
		var reg = converterRegistry();
		var probe = converterProbeForNode(n);
		var fieldOpts =
			reg && typeof reg.listCompatible === 'function' && probe
				? reg.listCompatible(probe)
				: [];
		return (fieldOpts || []).map(function (o) {
			return {
				value: String(o.id || ''),
				label: preferredConverterOptionLabel(o.id, o.label),
			};
		}).filter(function (o) {
			return !!o.value;
		});
	}

	function preferredConverterOptionLabel(id, fallback) {
		var key = String(id || '')
			.trim()
			.toLowerCase();
		var map = {
			glyph: i18n.charFormatGlyph || 'Character (glyph)',
			arabic: i18n.intFormatArabic || 'Arabic (decimal)',
			roman: i18n.intFormatRoman || 'Roman',
			binary: i18n.intFormatBinary || 'Binary',
			octal: i18n.intFormatOctal || 'Octal',
			hex: i18n.intFormatHex || 'Hexadecimal',
			ascii: i18n.charFormatAscii || 'ASCII',
			unicode: i18n.charFormatUnicode || 'Unicode (U+)',
		};
		return map[key] || fallback || key;
	}

	function normalizePreferredConverter(raw) {
		var key = String(raw || '')
			.trim()
			.toLowerCase();
		if (!key) {
			return '';
		}
		if (window.WTTIntValue && typeof window.WTTIntValue.normalizeFormatId === 'function') {
			var known = window.WTTIntValue.FORMAT_IDS || [];
			if (known.indexOf(key) >= 0) {
				return window.WTTIntValue.normalizeFormatId(key);
			}
		}
		if (/^[a-z][a-z0-9_-]*$/.test(key)) {
			return key;
		}
		return '';
	}

	function defaultPreferredConverterForNode(n) {
		var opts = listCompatiblePreferredConverterOptions(n);
		if (!opts.length) {
			return '';
		}
		var cur = normalizePreferredConverter(
			(n && n.preferredConverter) ||
				(n && n.intConfig && n.intConfig.displayFormat) ||
				''
		);
		var i;
		for (i = 0; i < opts.length; i++) {
			if (opts[i].value === cur) {
				return cur;
			}
		}
		var typeKey = resolveNodeRenderTypeKey(n);
		var typeDefault =
			typeKey === 'int' ? 'arabic' : typeKey === 'char' ? 'glyph' : '';
		if (typeDefault) {
			for (i = 0; i < opts.length; i++) {
				if (opts[i].value === typeDefault) {
					return typeDefault;
				}
			}
		}
		return opts[0].value;
	}

	/** @deprecated Use normalizePreferredConverter — kept for attribute extras BC. */
	function normalizeIntDisplayFormat(raw) {
		var key = normalizePreferredConverter(raw);
		return key || 'arabic';
	}

	function intDisplayFormatLabel(formatId) {
		return preferredConverterOptionLabel(formatId, formatId);
	}

	function isIntCatalogNode(n) {
		if (!n) {
			return false;
		}
		var name = String(n.name || '')
			.trim()
			.toLowerCase();
		if (name !== 'int' && name !== 'integer') {
			return false;
		}
		return !!n.intConfig || !!n.isTemplate || name === 'int' || name === 'integer';
	}

	function settingsFromNode(n) {
		return {
			name: n.name != null ? String(n.name) : '',
			slug: n.slug != null ? String(n.slug) : '',
			description: n.description != null ? String(n.description) : '',
			shortDescription: n.shortDescription != null ? String(n.shortDescription) : '',
			icon: n.icon != null ? String(n.icon) : '',
			typeId: n.typeId || 0,
			ownTypeId: n.ownTypeId != null ? n.ownTypeId : n.typeId || 0,
			typeInheriting: !!n.typeInheriting,
			typeOverride: !!n.typeOverride,
			canInheritType: !!n.canInheritType,
			inheritedTypeId: n.inheritedTypeId || 0,
			typeIsParent: !!n.typeIsParent,
			freeTypeLocked: !!n.freeTypeLocked,
			isTemplate: !!n.isTemplate,
			datatypeTree: Array.isArray(n.datatypeTree) ? n.datatypeTree : [],
			required: !!n.required,
			readonly: !!n.readonly,
			isAttributeSlot: !!n.isAttributeSlot,
			isModelDataHost: !!n.isModelDataHost,
			fixedEnabled: !!n.fixedEnabled,
			fixedLiteral: n.fixedLiteral != null ? String(n.fixedLiteral) : '',
			fixedNodeId: n.fixedNodeId || 0,
			refScopeId: n.refScopeId || 0,
			fieldMultiplicity:
				n.fieldMultiplicity != null ? String(n.fieldMultiplicity) : '0..1',
			allowedRefIds: Array.isArray(n.allowedRefIds) ? n.allowedRefIds.slice() : [],
			hasFooter: !!n.hasFooter,
			footerOp: n.fussFieldContext
				? String(n.fussFieldContext.footerOp || '')
				: n.footerOp != null
					? String(n.footerOp)
					: '',
			fussFieldContext: n.fussFieldContext ? deepClone(n.fussFieldContext) : null,
			setSeparator: n.setSeparator != null ? String(n.setSeparator) : '/',
			setJoinUnits: n.setJoinUnits !== false,
			setLabelChildren: n.setLabelChildren !== false,
			type: n.type || null,
			fixed: n.fixed || null,
			isTable: !!n.isTable,
			isTableTypeCatalog: !!n.isTableTypeCatalog,
			tableValidation: n.tableValidation ? deepClone(n.tableValidation) : null,
			attributeValidation: n.attributeValidation
				? deepClone(n.attributeValidation)
				: null,
			typeProps: Array.isArray(n.typeProps) ? deepClone(n.typeProps) : [],
			effectiveTypeProps: Array.isArray(n.effectiveTypeProps)
				? deepClone(n.effectiveTypeProps)
				: [],
			propBindings: normalizePropBindings(n.propBindings),
			directChildren: Array.isArray(n.directChildren) ? deepClone(n.directChildren) : [],
			isSet: !!n.isSet,
			isConcreteEnum: !!n.isConcreteEnum,
			enumOptions: Array.isArray(n.enumOptions) ? deepClone(n.enumOptions) : [],
			mediaConfig: n.mediaConfig
				? {
						allowUpload: n.mediaConfig.allowUpload !== false,
						allowUrl: !!n.mediaConfig.allowUrl,
						allowedKinds: normalizeAllowedKinds(
							n.mediaConfig.allowedKinds != null
								? n.mediaConfig.allowedKinds
								: []
						),
				  }
				: null,
			dateConfig: n.dateConfig
				? {
						mode:
							n.dateConfig.mode === 'datetime' ? 'datetime' : 'date',
				  }
				: String(n.name || '')
						.trim()
						.toLowerCase() === 'date'
					? { mode: 'date' }
					: null,
			textareaConfig: normalizeTextareaConfig(
				n.textareaConfig,
				n.name || n.typeKey
			),
			presentationConfig: n.presentationConfig
				? {
						context: normalizePresentationContext(
							n.presentationConfig.context
						),
				  }
				: isNodePresentationTypeKey(n.typeKey || (n.type && n.type.name) || n.name)
					? { context: 'form' }
					: null,
			presentation:
				n.presentation && typeof n.presentation === 'object'
					? Object.assign({}, n.presentation)
					: null,
			intConfig: n.intConfig
				? {
						displayFormat: normalizeIntDisplayFormat(
							n.intConfig.displayFormat
						),
				  }
				: isIntCatalogNode(n)
					? { displayFormat: 'arabic' }
					: null,
			preferredConverter: normalizePreferredConverter(
				n.preferredConverter ||
					(n.intConfig && n.intConfig.displayFormat) ||
					''
			),
			validators: normalizeValidatorsList(n.validators, n),
			preferredRender: normalizePreferredRender(n.preferredRender),
			preferredRenderInherit: !!n.preferredRenderInherited,
			preferredRenderOwn: n.preferredRenderOwn
				? String(n.preferredRenderOwn)
				: '',
			embedChoiceOptions: Array.isArray(n.embedChoiceOptions)
				? n.embedChoiceOptions.slice()
				: [],
			typeBranch: n.typeBranch ? deepClone(n.typeBranch) : null,
			isBasiseinheitUnit: !!n.isBasiseinheitUnit,
			prefixAllowlist: n.prefixAllowlist ? deepClone(n.prefixAllowlist) : null,
			prefixRootToSi: n.prefixRootToSi != null ? n.prefixRootToSi : null,
			quantitySchema: n.quantitySchema ? deepClone(n.quantitySchema) : null,
			quantityPreviewExample: n.quantityPreviewExample
				? deepClone(n.quantityPreviewExample)
				: null,
			/* Child extras on parent (e.g. Meter): Praefix allowlist + factors â€” not name/description. */
			prefixBranch: extractPrefixBranchFromNode(n),
			presentation: emptyPresentationDraft(),
		};
	}

	function emptyPresentationDraft() {
		return {
			loaded: false,
			locale: '',
			listUrl: '',
			values: {
				form: '',
				table: '',
				select: '',
				symbol: '',
				help: '',
			},
		};
	}

	function normalizePresentationValues(raw) {
		raw = raw && typeof raw === 'object' ? raw : {};
		return {
			form: raw.form != null ? String(raw.form) : '',
			table: raw.table != null ? String(raw.table) : '',
			select: raw.select != null ? String(raw.select) : '',
			symbol: raw.symbol != null ? String(raw.symbol) : '',
			help: raw.help != null ? String(raw.help) : '',
		};
	}

	function setDraftPresentationFromServer(data) {
		var bag = {
			loaded: true,
			locale: data && data.locale != null ? String(data.locale) : '',
			listUrl: data && data.listUrl != null ? String(data.listUrl) : '',
			values: normalizePresentationValues(data && data.raw),
		};
		if (state.draft) {
			state.draft.presentation = deepClone(bag);
		}
		if (state.savedDraft) {
			state.savedDraft.presentation = deepClone(bag);
		}
		return bag;
	}

	function setDraftPresentationValue(key, value) {
		if (!state.draft) {
			return;
		}
		if (!state.draft.presentation) {
			state.draft.presentation = emptyPresentationDraft();
		}
		state.draft.presentation.loaded = true;
		if (!state.draft.presentation.values) {
			state.draft.presentation.values = normalizePresentationValues(null);
		}
		state.draft.presentation.values[key] = value != null ? String(value) : '';
		afterDraftMutation({ silent: true });
	}

	function savePresentationDraft(termId, payloadDraft) {
		termId = parseInt(termId, 10) || 0;
		var pres = payloadDraft && payloadDraft.presentation;
		if (termId <= 0 || !pres || !pres.loaded) {
			return Promise.resolve();
		}
		return post('wtt_save_node_presentation', {
			term_id: String(termId),
			locale: pres.locale != null ? String(pres.locale) : '',
			values: JSON.stringify(normalizePresentationValues(pres.values)),
		}).then(function (json) {
			if (!json || !json.success) {
				throw new Error(
					(json && json.data && json.data.message) ||
						i18n.presentationFoldError ||
						'Could not save presentation.'
				);
			}
			return json;
		});
	}

	function extractPrefixBranchFromNode(n) {
		if (!n || !n.isBasiseinheitUnit || !Array.isArray(n.setMembers)) {
			return null;
		}
		for (var i = 0; i < n.setMembers.length; i++) {
			var m = n.setMembers[i];
			if (
				m &&
				memberNameKey(m) === 'praefix' &&
				m.typeBranch &&
				m.typeBranch.unitAllowlistEdit
			) {
				return deepClone(m.typeBranch);
			}
		}
		return null;
	}

	function applyLoadedNode(node) {
		if (node && Array.isArray(node.datatypeTree) && node.datatypeTree.length) {
			state.datatypeTreeCache = node.datatypeTree;
		} else if (node && state.datatypeTreeCache) {
			node.datatypeTree = state.datatypeTreeCache;
		}
		state.selectedNode = node;
		state.draft = settingsFromNode(node);
		ensureTableDraftChrome(state.draft);
		state.savedDraft = settingsFromNode(node);
		ensureTableDraftChrome(state.savedDraft);
		state.settingsSaving = false;
		state.enumValuesSaving = false;
		state.enumValuesDraft = null;
		state.enumValuesDirty = false;
		state.error = '';
		if (node && node._timing && window.console && console.debug) {
			console.debug('[wtt] get_node attrs', node._timing);
		}
	}

	/**
	 * Merge Settings-walk summary onto the selected node's attribute row.
	 *
	 * @param {string} attrId
	 * @param {Object} payload
	 */
	function mergeAttrSettingsWalk(attrId, payload) {
		attrId = wttAttrId(attrId);
		if (!attrId || !payload) {
			return;
		}
		function patchList(list) {
			if (!Array.isArray(list)) {
				return;
			}
			list.forEach(function (row) {
				if (!row || wttAttrId(row) !== attrId) {
					return;
				}
				row.settingsWalk = Array.isArray(payload.settingsWalk)
					? payload.settingsWalk
					: [];
				row.settingsWalkMeta =
					payload.settingsWalkMeta &&
					typeof payload.settingsWalkMeta === 'object'
						? payload.settingsWalkMeta
						: row.settingsWalkMeta || {};
				row.settingsWalkLazy = false;
				row.settingsWalkCached = !!payload.cached;
			});
		}
		if (state.selectedNode) {
			patchList(state.selectedNode.attributes);
		}
		if (state.draft) {
			patchList(state.draft.attributes);
		}
	}

	/**
	 * Hydrate Options walk from persisted cache (or rebuild). Skips when already present.
	 *
	 * @param {Object} hostNode
	 * @param {Object} attr
	 * @return {Promise}
	 */
	function ensureAttrSettingsWalk(hostNode, attr) {
		var hostId = parseInt(hostNode && hostNode.id, 10) || 0;
		var attrId = wttAttrId(attr);
		if (hostId <= 0 || !attrId) {
			return Promise.resolve(null);
		}
		if (
			Array.isArray(attr.settingsWalk) &&
			attr.settingsWalk.length &&
			!attr.settingsWalkLazy &&
			!settingsWalkMissingCatalogChoices(attr.settingsWalk)
		) {
			return Promise.resolve(attr);
		}
		if (!attr.settingsWalkLazy && !attr.settingsWalkCached) {
			var meta = attr.settingsWalkMeta || {};
			var nestedHint =
				(parseInt(meta.nodeCount, 10) || 0) > 1 ||
				(parseInt(meta.depth, 10) || 0) > 0 ||
				!!meta.lazy;
			if (!nestedHint && !settingsWalkMissingCatalogChoices(attr.settingsWalk)) {
				return Promise.resolve(attr);
			}
		}
		if (state.attrWalkLoading[attrId]) {
			return state.attrWalkLoading[attrId];
		}
		var seq = selectSeq;
		state.attrWalkLoading[attrId] = post('wtt_get_attribute_settings_walk', {
			term_id: hostId,
			attr_id: attrId,
		})
			.then(function (json) {
				state.attrWalkLoading[attrId] = null;
				if (seq !== selectSeq) {
					return null;
				}
				if (!json || !json.success || !json.data) {
					return null;
				}
				mergeAttrSettingsWalk(attrId, json.data);
				scheduleRenderAfterAttrWalk();
				return json.data;
			})
			.catch(function () {
				state.attrWalkLoading[attrId] = null;
				return null;
			});
		return state.attrWalkLoading[attrId];
	}

	/**
	 * CatalogChoice walk edges (Base unit / Praefix / Unit) without choice payload → refetch.
	 *
	 * @param {Array} levels
	 * @return {boolean}
	 */
	function settingsWalkMissingCatalogChoices(levels) {
		return (levels || []).some(function (lv) {
			if (!lv || typeof lv !== 'object') {
				return false;
			}
			var edge = String(lv.edgeName || '')
				.toLowerCase()
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe')
				.replace(/ü/g, 'ue');
			if (
				edge !== 'base unit' &&
				edge !== 'praefix' &&
				edge !== 'unit' &&
				edge !== 'with prefix'
			) {
				return false;
			}
			if (lv.supportsChoiceFilter) {
				return false;
			}
			return !(
				Array.isArray(lv.choiceOptions) && lv.choiceOptions.length > 0
			);
		});
	}

	/**
	 * Walk row label: prefer Relation edge name; avoid "Praefix → Präfixe" twins.
	 *
	 * @param {Object} level
	 * @return {string}
	 */
	function settingsWalkLevelLabel(level) {
		var edgeName = level && level.edgeName ? String(level.edgeName) : '';
		var name = level && level.name ? String(level.name) : '';
		if (!edgeName) {
			return name || '—';
		}
		if (!name || settingsWalkNamesEquivalent(edgeName, name)) {
			return edgeName;
		}
		return edgeName + ' → ' + name;
	}

	/**
	 * @param {string} a
	 * @param {string} b
	 * @return {boolean}
	 */
	function settingsWalkNamesEquivalent(a, b) {
		function norm(s) {
			return String(s || '')
				.toLowerCase()
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe')
				.replace(/ü/g, 'ue')
				.replace(/ß/g, 'ss')
				.replace(/[^a-z0-9]+/g, '');
		}
		var na = norm(a);
		var nb = norm(b);
		if (!na || !nb) {
			return false;
		}
		if (na === nb) {
			return true;
		}
		/* Praefix / Präfixe, Unit / Units */
		if (na + 'e' === nb || nb + 'e' === na) {
			return true;
		}
		if (na + 's' === nb || nb + 's' === na) {
			return true;
		}
		return (
			na.replace(/e$/, '') === nb.replace(/e$/, '') ||
			na.replace(/s$/, '') === nb.replace(/s$/, '')
		);
	}

	function viewNode() {
		var n = state.selectedNode;
		var d = state.draft;
		if (!n || !d) {
			return n;
		}
		return Object.assign({}, n, {
			name: d.name,
			slug: d.slug != null ? String(d.slug) : n.slug || '',
			description: d.description,
			shortDescription: d.shortDescription != null ? String(d.shortDescription) : '',
			icon: d.icon != null ? String(d.icon) : '',
			typeId: d.typeId,
			ownTypeId: d.ownTypeId != null ? d.ownTypeId : d.typeId || 0,
			typeInheriting: !!d.typeInheriting,
			typeOverride: !!d.typeOverride,
			canInheritType: !!d.canInheritType,
			inheritedTypeId: d.inheritedTypeId || 0,
			typeIsParent: !!d.typeIsParent,
			freeTypeLocked: !!d.freeTypeLocked,
			isTemplate: !!d.isTemplate,
			datatypeTree: Array.isArray(d.datatypeTree) ? d.datatypeTree : [],
			required: d.required,
			readonly: !!d.readonly,
			isAttributeSlot: !!d.isAttributeSlot || !!n.isAttributeSlot,
			isModelDataHost: !!d.isModelDataHost || !!n.isModelDataHost,
			fixedEnabled: d.fixedEnabled,
			fixedLiteral: d.fixedLiteral,
			fixedNodeId: d.fixedNodeId,
			refScopeId: d.refScopeId || 0,
			fieldMultiplicity:
				d.fieldMultiplicity != null ? String(d.fieldMultiplicity) : '0..1',
			allowedRefIds: Array.isArray(d.allowedRefIds) ? d.allowedRefIds.slice() : [],
			hasFooter: d.hasFooter,
			footerOp: d.footerOp != null ? String(d.footerOp) : '',
			fussFieldContext: d.fussFieldContext || n.fussFieldContext || null,
			setSeparator: d.setSeparator != null ? String(d.setSeparator) : '/',
			setJoinUnits: d.setJoinUnits !== false,
			setLabelChildren: d.setLabelChildren !== false,
			type: d.type,
			fixed: draftFixedDisplay(d),
			isTable: d.isTable,
			isTableTypeCatalog: !!d.isTableTypeCatalog,
			tableValidation: d.tableValidation || n.tableValidation || null,
			attributeValidation:
				d.attributeValidation || n.attributeValidation || null,
			typeProps: Array.isArray(d.typeProps) ? d.typeProps : [],
			effectiveTypeProps: Array.isArray(d.effectiveTypeProps) ? d.effectiveTypeProps : [],
			propBindings: normalizePropBindings(d.propBindings),
			directChildren: Array.isArray(d.directChildren)
				? d.directChildren
				: Array.isArray(n.directChildren)
					? n.directChildren
					: [],
			isSet: d.isSet,
			isConcreteEnum: !!(d.isConcreteEnum != null ? d.isConcreteEnum : n.isConcreteEnum),
			enumOptions: Array.isArray(d.enumOptions)
				? d.enumOptions
				: Array.isArray(n.enumOptions)
					? n.enumOptions
					: [],
			mediaConfig: d.mediaConfig
				? {
						allowUpload: d.mediaConfig.allowUpload !== false,
						allowUrl: !!d.mediaConfig.allowUrl,
						allowedKinds: normalizeAllowedKinds(
							d.mediaConfig.allowedKinds != null
								? d.mediaConfig.allowedKinds
								: []
						),
				  }
				: n.mediaConfig || null,
			dateConfig: d.dateConfig
				? {
						mode:
							d.dateConfig.mode === 'datetime' ? 'datetime' : 'date',
				  }
				: n.dateConfig || null,
			textareaConfig: normalizeTextareaConfig(
				d.textareaConfig != null ? d.textareaConfig : n.textareaConfig,
				d.name || n.name || n.typeKey
			),
			presentationConfig: d.presentationConfig
				? {
						context: normalizePresentationContext(
							d.presentationConfig.context
						),
				  }
				: n.presentationConfig || null,
			presentation:
				d.presentation && typeof d.presentation === 'object'
					? d.presentation
					: n.presentation && typeof n.presentation === 'object'
						? n.presentation
						: null,
			intConfig: d.intConfig
				? {
						displayFormat: normalizeIntDisplayFormat(
							d.intConfig.displayFormat
						),
				  }
				: n.intConfig || null,
			preferredConverter: normalizePreferredConverter(
				d.preferredConverter != null
					? d.preferredConverter
					: n.preferredConverter ||
							(d.intConfig && d.intConfig.displayFormat) ||
							(n.intConfig && n.intConfig.displayFormat) ||
							''
			),
			validators: normalizeValidatorsList(
				d.validators != null ? d.validators : n.validators,
				Object.assign({}, n, d)
			),
			preferredRender: normalizePreferredRender(
				d.preferredRender != null ? d.preferredRender : n.preferredRender
			),
			typeBranch: d.typeBranch,
			isBasiseinheitUnit: d.isBasiseinheitUnit,
			prefixAllowlist: d.prefixAllowlist,
			prefixRootToSi: d.prefixRootToSi,
			prefixBranch: d.prefixBranch,
			quantitySchema: d.quantitySchema,
			quantityPreviewExample: d.quantityPreviewExample,
		});
	}

	function isSimpleDataType(type) {
		var key = typeKeyFromMember({ type: type });
		return (
			key === 'int' ||
			key === 'double' ||
			key === 'text' ||
			key === 'textarea' ||
			key === 'char' ||
			key === 'bool' ||
			key === 'email' ||
			key === 'date' ||
			key === 'quantity' ||
			key === 'node_presentation' ||
			key === 'display_node_name' ||
			key === 'media'
		);
	}

	function supportsFixedLiteral(type) {
		var key = typeKeyFromMember({ type: type });
		return (
			isSimpleDataType(type) &&
			!isNodePresentationTypeKey(key) &&
			key !== 'media'
		);
	}

	function draftFixedDisplay(draft) {
		if (!draft || !draft.fixedEnabled) {
			return null;
		}
		if (isSimpleDataType(draft.type)) {
			var lit = draft.fixedLiteral != null ? String(draft.fixedLiteral) : '';
			if (typeKeyFromMember({ type: draft.type }) === 'bool') {
				lit = lit === '1' || lit === 'true' ? '1' : lit === '' ? '' : '0';
			}
			if (lit === '') {
				return null;
			}
			return { id: 0, name: lit, path: lit };
		}
		return draft.fixed || null;
	}

	function isSettingsDirty() {
		if (!state.draft || !state.savedDraft) {
			return false;
		}
		return JSON.stringify(state.draft) !== JSON.stringify(state.savedDraft);
	}

	function resolveTypeFromOptions(typeId, typeOptions) {
		if (!typeId) {
			return null;
		}
		var found = (typeOptions || []).find(function (opt) {
			return opt && String(opt.id) === String(typeId);
		});
		if (!found) {
			return state.draft && state.draft.type && String(state.draft.type.id) === String(typeId)
				? state.draft.type
				: null;
		}
		return {
			id: found.id,
			name: found.name || '',
			path: found.path || found.name || '',
		};
	}

	function resolveFixedFromOptions(fixedNodeId, fixedOptions) {
		if (!fixedNodeId) {
			return null;
		}
		var found = (fixedOptions || []).find(function (opt) {
			return opt && String(opt.id) === String(fixedNodeId);
		});
		if (!found) {
			return state.draft && state.draft.fixed && String(state.draft.fixed.id) === String(fixedNodeId)
				? state.draft.fixed
				: null;
		}
		return {
			id: found.id,
			name: found.name || '',
			path: found.path || found.name || '',
		};
	}

	function typeNameIs(type, name) {
		return !!(type && String(type.name || '').toLowerCase() === String(name).toLowerCase());
	}

	function disabledIdsFromBranch(branch) {
		var ids = [];
		if (!branch || !Array.isArray(branch.children)) {
			return ids;
		}
		// Read-only filter from sibling Einheit â€” do not persist local disables.
		if (branch.unitFilter && !branch.unitAllowlistEdit) {
			return ids;
		}
		branch.children.forEach(function (child) {
			if (child && child.id != null && child.enabled === false) {
				ids.push(parseInt(child.id, 10) || 0);
			}
		});
		return ids.filter(function (id) {
			return id > 0;
		});
	}

	function disabledBranchIdsFromDraft(draft) {
		if (!draft) {
			return [];
		}
		/* Prefer prefix extras on unit parent; else type-branch on current node. */
		if (draft.prefixBranch && draft.prefixBranch.unitAllowlistEdit) {
			return disabledIdsFromBranch(draft.prefixBranch);
		}
		return disabledIdsFromBranch(draft.typeBranch);
	}

	/**
	 * Visible dropdown label: path or name only (no shortDescription inline).
	 * Short text goes on option/select title â€” see formatSelectTitle / syncSelectTitle.
	 */
	function formatSelectLabel(opt) {
		if (!opt) {
			return '';
		}
		var name = opt.name != null ? String(opt.name) : '';
		var path = opt.path != null ? String(opt.path) : '';
		var label = path || name || String(opt.id != null ? opt.id : '') || '';
		/* Canonical type name after subtree → node_embed rename. */
		if (/^subtree$/i.test(label) || /(^|\/)\s*subtree\s*$/i.test(label)) {
			label = label.replace(/subtree/gi, 'node_embed');
		}
		return label;
	}

	/**
	 * Label context for CatalogChoice / ChildList from host node_presentation attrs.
	 * e.g. Bauformen + Bauform (Symbol) → list shows Presentation.symbol texts.
	 *
	 * @param {Object|null} host
	 * @return {string}
	 */
	function hostChoiceLabelContext(host) {
		var attrs = host && Array.isArray(host.attributes) ? host.attributes : [];
		var i;
		for (i = 0; i < attrs.length; i++) {
			var a = attrs[i];
			if (!a || a.hidden) {
				continue;
			}
			var tk = String(a.typeKey || a.typeName || a.typeLabel || '')
				.trim()
				.toLowerCase();
			if (tk.indexOf('/') !== -1) {
				var parts = tk.split('/');
				tk = String(parts[parts.length - 1] || '')
					.trim()
					.toLowerCase();
			}
			if (
				tk === 'node_presentation' ||
				tk === 'display_node_name' ||
				tk.indexOf('node_presentation') !== -1 ||
				tk.indexOf('display_node_name') !== -1
			) {
				return presentationContextFromAttr(a);
			}
		}
		return 'form';
	}

	/**
	 * Choice option label by Presentation context (Q117).
	 *
	 * @param {Object|null} opt
	 * @param {string} ctx
	 * @return {string}
	 */
	function formatChoiceOptionLabel(opt, ctx) {
		ctx = normalizePresentationContext(ctx || 'form');
		if (!opt) {
			return '';
		}
		var pres =
			opt.presentation && typeof opt.presentation === 'object'
				? opt.presentation
				: null;
		if (pres) {
			var fromCtx = pres[ctx] != null ? String(pres[ctx]).trim() : '';
			if (fromCtx) {
				return fromCtx;
			}
			if (ctx === 'symbol' || ctx === 'table') {
				var sym = pres.symbol != null ? String(pres.symbol).trim() : '';
				if (sym) {
					return sym;
				}
			}
			if (ctx === 'select') {
				var sel = pres.select != null ? String(pres.select).trim() : '';
				if (sel) {
					return sel;
				}
			}
		}
		if (ctx === 'symbol' || ctx === 'table') {
			var short =
				opt.shortDescription != null
					? String(opt.shortDescription).trim()
					: '';
			if (short) {
				return short;
			}
		}
		return formatSelectLabel(opt);
	}

	/**
	 * SI / compact symbol for list options (Präfix letter, unit short).
	 * Prefer shortDescription when ≤ 3 chars; else letter heuristic on name.
	 */
	function formatSelectSymbolLabel(opt) {
		if (!opt) {
			return '';
		}
		var short =
			opt.shortDescription != null
				? String(opt.shortDescription).trim()
				: '';
		if (short && short.length <= 3) {
			return short;
		}
		var name = opt.name != null ? String(opt.name) : '';
		if (!name) {
			return formatSelectLabel(opt);
		}
		if (name === 'Mega') {
			return 'M';
		}
		if (name === 'Micro' || name === 'µ' || name === 'μ') {
			return 'µ';
		}
		if (name.length <= 2) {
			return name;
		}
		return name.charAt(0).toLowerCase();
	}

	/**
	 * Wider quantity unit/prefix option: "Ω · Ohm" / "M · Mega".
	 */
	function formatSelectLabeledSymbol(opt) {
		if (!opt) {
			return '';
		}
		var sym = formatSelectSymbolLabel(opt);
		var name = formatSelectLabel(opt) || String(opt.name || '');
		if (sym && name && sym !== name) {
			return sym + ' · ' + name;
		}
		return name || sym || '';
	}

	/**
	 * Path "A / B / C" for a term id (name-only leaf when unknown).
	 */
	function buildNodePathLabel(termId, roots) {
		termId = parseInt(termId, 10) || 0;
		if (!termId) {
			return '';
		}
		function walk(nodes, chain) {
			var i;
			var n;
			var next;
			var found;
			for (i = 0; i < (nodes || []).length; i++) {
				n = nodes[i];
				if (!n || n.id == null) {
					continue;
				}
				next = chain.concat([displayNodeName(n) || String(n.id)]);
				if ((parseInt(n.id, 10) || 0) === termId) {
					return next;
				}
				found = walk(n.children || [], next);
				if (found) {
					return found;
				}
			}
			return null;
		}
		var parts =
			walk(roots || [], []) ||
			walk(state.tree || [], []);
		return parts ? parts.join(' / ') : '';
	}

	function nodeNameOnly(termId, roots) {
		termId = parseInt(termId, 10) || 0;
		if (!termId) {
			return '';
		}
		var n =
			findNodeInTree(roots || [], termId) ||
			findNodeInTree(state.tree, termId);
		return n && n.name ? displayNodeName(n) : '#' + termId;
	}

	/**
	 * Show path when it fits; otherwise fall back to name (ellipsis-friendly).
	 */
	function applyAdaptiveNodeLabel(el, name, path) {
		if (!el) {
			return;
		}
		name = name != null ? String(name) : '';
		path = path != null ? String(path) : '';
		el.setAttribute('data-wtt-name', name);
		el.setAttribute('data-wtt-path', path);
		el.title = path || name || '';

		function fit() {
			var n = el.getAttribute('data-wtt-name') || '';
			var p = el.getAttribute('data-wtt-path') || '';
			if (!p || p === n) {
				el.textContent = n || p || '';
				return;
			}
			el.textContent = p;
			/* Path too wide for the box â†’ name only. */
			if (el.scrollWidth > el.clientWidth + 1) {
				el.textContent = n || p;
			}
		}

		if (typeof window.requestAnimationFrame === 'function') {
			window.requestAnimationFrame(fit);
		} else {
			fit();
		}
		if (typeof window.ResizeObserver === 'function') {
			if (el._wttLabelRo) {
				el._wttLabelRo.disconnect();
			}
			el._wttLabelRo = new window.ResizeObserver(fit);
			el._wttLabelRo.observe(el);
		}
	}

	/**
	 * Focus id for a picker.
	 * Contract: caller-owned focusId first when preferFocus; else selectedId, then
	 * focusId, then last tree selection. Settings chooser_focus is never read here â€”
	 * callers that want that fallback (attribute type picker) pass it as focusId.
	 */
	function resolvePickerFocusId(opts) {
		opts = opts || {};
		var explicit = opts.focusId != null ? parseInt(opts.focusId, 10) || 0 : 0;
		/* preferFocus: expand around caller focusId even when a value is already selected. */
		if (opts.preferFocus && explicit > 0) {
			return explicit;
		}
		var selected = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		if (selected > 0) {
			return selected;
		}
		if (explicit > 0) {
			return explicit;
		}
		/* Do not steal focus from the host node (e.g. Bom Zeile) for catalog pickers. */
		if (opts.ignoreLastSelection) {
			return 0;
		}
		var last = parseInt(state.selectedId, 10) || 0;
		if (last <= 0) {
			return 0;
		}
		if (opts.blockedIds && opts.blockedIds[String(last)]) {
			return 0;
		}
		var roots = opts.roots || [];
		/* Restricted pickers (e.g. type tree): last node must appear in those roots. */
		var node = roots.length
			? findNodeInTree(roots, last)
			: findNodeInTree(state.tree, last);
		if (!node) {
			return 0;
		}
		if (typeof opts.selectable === 'function' && !opts.selectable(node)) {
			return 0;
		}
		return last;
	}

	function treePickerPresentation(opts) {
		opts = opts || {};
		if (opts.presentation === 'inline' || opts.embedded) {
			return 'inline';
		}
		if (opts.presentation === 'popup') {
			return 'popup';
		}
		/* Table cells / compact fields always use the small trigger + dialog. */
		if (opts.compact) {
			return 'popup';
		}
		var mode = String(cfg.treePickerMode || 'popup').toLowerCase();
		return mode === 'inline' ? 'inline' : 'popup';
	}

	/** Tooltip text for a select option (shortDescription when present). */
	function formatSelectTitle(opt) {
		if (!opt || opt.shortDescription == null) {
			return '';
		}
		return String(opt.shortDescription).trim();
	}

	/** Keep closed-select title in sync with the selected option's title. */
	function syncSelectTitle(control) {
		if (!control || !control.options || !control.options.length) {
			if (control) {
				control.removeAttribute('title');
			}
			return;
		}
		var opt = control.options[control.selectedIndex];
		var tip = opt && opt.title ? String(opt.title) : '';
		if (tip) {
			control.title = tip;
		} else {
			control.removeAttribute('title');
		}
	}

	/**
	 * One select builder for option lists (branch children, type/fixed pickers, â€¦).
	 * No blank placeholder option â€” first real option is selected when nothing matches.
	 * shortDescription â†’ option title (+ select title for closed hover).
	 *
	 * @param {Array<{id?:*,name?:string,path?:string,shortDescription?:string}>} options
	 * @param {{
	 *   className?: string,
	 *   disabled?: boolean,
	 *   selectedValue?: *,
	 *   getValue?: function,
	 *   onChange?: function,
	 *   emptyLabel?: string
	 * }} opts
	 */
	/**
	 * Q116 — Sole required list-select lock.
	 * When a ListChooser / <select> is required (no zero-lower / allowEmpty) and
	 * exactly one real option exists: keep that value selected and disable (gray).
	 * Multiple options → leave selectable. Optional fields stay selectable even with one choice.
	 *
	 * @param {HTMLSelectElement} control
	 * @param {number} realOptionCount Options with non-empty value
	 * @param {{
	 *   allowEmpty?: boolean,
	 *   disabled?: boolean,
	 *   title?: string
	 * }} [opts]
	 * @return {{ locked: boolean }}
	 */
	function applySoleRequiredListLock(control, realOptionCount, opts) {
		opts = opts || {};
		if (!control) {
			return { locked: false };
		}
		control.classList.remove('is-sole-locked');
		if (opts.disabled) {
			control.disabled = true;
			return { locked: false };
		}
		var allowEmpty = opts.allowEmpty === true;
		var count = parseInt(realOptionCount, 10) || 0;
		if (!allowEmpty && count === 1) {
			control.disabled = true;
			control.classList.add('is-sole-locked');
			control.title =
				opts.title ||
				(i18n && i18n.soleSelectLockedHint) ||
				'Only one choice — selected automatically.';
			return { locked: true };
		}
		control.disabled = false;
		return { locked: false };
	}

	/**
	 * Count <option> entries with a non-empty value (placeholders / “none” excluded).
	 * @param {HTMLSelectElement} control
	 * @return {number}
	 */
	function countRealSelectOptions(control) {
		if (!control || !control.options) {
			return 0;
		}
		var n = 0;
		var i;
		for (i = 0; i < control.options.length; i++) {
			if (String(control.options[i].value || '') !== '') {
				n += 1;
			}
		}
		return n;
	}

	/**
	 * Q116 — zero-lower Mult / explicit optional → ListChooser may stay empty.
	 * @param {Object|null} member
	 * @return {boolean}
	 */
	function memberListSelectAllowsEmpty(member) {
		if (!member) {
			return false;
		}
		if (member.allowsEmpty === true) {
			return true;
		}
		if (member.allowsEmpty === false) {
			return false;
		}
		if (member.required === true || member.required === 1 || member.required === '1') {
			return false;
		}
		if (member.required === false) {
			return true;
		}
		var m = String(
			member.multiplicity || member.fieldMultiplicity || '1'
		).trim();
		return m === '0' || m === '0..1' || m === '0..*';
	}

	function renderOptionsSelect(options, opts) {
		opts = opts || {};
		var list = (options || []).filter(function (opt) {
			return !!opt;
		});
		var control = el('select', {
			className: opts.className || 'wtt-type-select',
		});
		var allowEmpty =
			opts.allowEmpty === true ||
			(opts.allowEmpty !== false && !!opts.emptyLabel);
		/* Explicit false wins over emptyLabel (Q116 Mult-driven empty). */
		if (opts.allowEmpty === false) {
			allowEmpty = false;
		}
		if (allowEmpty) {
			control.appendChild(
				el('option', {
					value: opts.emptyValue != null ? String(opts.emptyValue) : '',
					text: String(
						opts.emptyLabel ||
							(i18n && i18n.catalogChoiceNone) ||
							(i18n && i18n.unitConvNone) ||
							'—'
					),
					title: String(
						opts.emptyTitle ||
							(i18n && i18n.unitConvNoneTitle) ||
							''
					),
				})
			);
		}
		if (!list.length) {
			if (!allowEmpty && opts.emptyLabel) {
				control.appendChild(
					el('option', {
						value: opts.emptyValue != null ? String(opts.emptyValue) : '',
						text: String(opts.emptyLabel),
					})
				);
			}
			if (typeof opts.onChange === 'function') {
				control.addEventListener('change', opts.onChange);
			}
			applySoleRequiredListLock(control, 0, {
				allowEmpty: allowEmpty,
				disabled: !!opts.disabled,
				title: opts.soleLockTitle,
			});
			return control;
		}
		var selected = false;
		var symbolLabels = !!opts.symbolLabels;
		var labeledSymbols = !!opts.labeledSymbols;
		list.forEach(function (opt) {
			var value =
				typeof opts.getValue === 'function'
					? String(opts.getValue(opt))
					: opt.id != null
						? String(opt.id)
						: String(opt.name || '');
			if (value === '') {
				return;
			}
			var tip =
				typeof opts.getTitle === 'function'
					? opts.getTitle(opt)
					: labeledSymbols || symbolLabels
						? formatSelectLabel(opt) || String(opt.name || '')
						: formatSelectTitle(opt);
			var optionAttrs = {
				value: value,
				text:
					typeof opts.getLabel === 'function'
						? String(opts.getLabel(opt) || value)
						: labeledSymbols
							? formatSelectLabeledSymbol(opt) || value
							: symbolLabels
								? formatSelectSymbolLabel(opt) || value
								: formatSelectLabel(opt) || value,
			};
			if (tip) {
				optionAttrs.title = tip;
			}
			var option = el('option', optionAttrs);
			if (
				!selected &&
				opts.selectedValue != null &&
				String(opts.selectedValue) === value
			) {
				option.selected = true;
				selected = true;
			}
			control.appendChild(option);
		});
		if (
			!selected &&
			allowEmpty &&
			(opts.selectedValue == null ||
				String(opts.selectedValue) === '' ||
				String(opts.selectedValue) === '0')
		) {
			control.options[0].selected = true;
			selected = true;
		}
		if (!selected && !allowEmpty && control.options.length) {
			control.options[0].selected = true;
		}
		syncSelectTitle(control);
		control.addEventListener('change', function () {
			syncSelectTitle(control);
		});
		if (typeof opts.onChange === 'function') {
			control.addEventListener('change', opts.onChange);
		}
		applySoleRequiredListLock(control, countRealSelectOptions(control), {
			allowEmpty: allowEmpty,
			disabled: !!opts.disabled,
			title: opts.soleLockTitle,
		});
		return control;
	}

	/**
	 * Icon list chooser (ListChooser + icon chrome).
	 * Native <select> cannot paint icons in the open list — this dropdown does.
	 * Item paint uses WTTNodeRender.paintIcon (same as tree rows).
	 *
	 * @param {Array<{key:string,label:string}>} options
	 * @param {{
	 *   id?: string,
	 *   selectedKey?: string,
	 *   disabled?: boolean,
	 *   noneLabel?: string,
	 *   onChange?: function(string)
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderIconListChooser(options, opts) {
		opts = opts || {};
		var list = Array.isArray(options) ? options.slice() : [];
		var selectedKey = opts.selectedKey != null ? String(opts.selectedKey) : '';
		var noneLabel = opts.noneLabel || i18n.iconNone || 'No icon';
		var open = false;

		function labelFor(key) {
			if (!key) {
				return noneLabel;
			}
			var i;
			for (i = 0; i < list.length; i++) {
				if (list[i] && String(list[i].key) === key) {
					return String(list[i].label || list[i].key);
				}
			}
			return key + ' (not allowed)';
		}

		function paintIconEl(key, extraClass) {
			var NR = window.WTTNodeRender;
			if (NR && typeof NR.paintIcon === 'function') {
				return NR.paintIcon(key, extraClass || '');
			}
			key = key != null ? String(key).replace(/^dashicons-/, '') : '';
			key = key.replace(/[^a-z0-9\-]/gi, '').toLowerCase();
			if (!key) {
				return null;
			}
			return el('span', {
				className:
					'dashicons dashicons-' +
					key +
					' wtt-tree__icon' +
					(extraClass ? ' ' + extraClass : ''),
				'aria-hidden': 'true',
			});
		}

		function fillTriggerFace(face, key) {
			face.textContent = '';
			var icon = paintIconEl(key, 'wtt-icon-list-chooser__icon');
			if (icon) {
				face.appendChild(icon);
			} else {
				face.appendChild(
					el('span', {
						className: 'wtt-icon-list-chooser__icon-slot',
						'aria-hidden': 'true',
					})
				);
			}
			face.appendChild(
				el('span', {
					className: 'wtt-icon-list-chooser__label',
					text: labelFor(key),
				})
			);
		}

		var wrap = el('div', {
			className: 'wtt-icon-list-chooser',
			id: opts.id || '',
		});
		var triggerFace = el('span', {
			className: 'wtt-icon-list-chooser__face',
		});
		fillTriggerFace(triggerFace, selectedKey);
		var trigger = el('button', {
			type: 'button',
			className: 'button wtt-icon-list-chooser__trigger',
			disabled: !!opts.disabled,
			'aria-haspopup': 'listbox',
			'aria-expanded': 'false',
		});
		trigger.appendChild(triggerFace);
		trigger.appendChild(
			el('span', {
				className: 'dashicons dashicons-arrow-down-alt2 wtt-icon-list-chooser__caret',
				'aria-hidden': 'true',
			})
		);

		var panel = el('ul', {
			className: 'wtt-icon-list-chooser__panel',
			role: 'listbox',
			hidden: true,
		});

		function closePanel() {
			open = false;
			panel.hidden = true;
			trigger.setAttribute('aria-expanded', 'false');
			document.removeEventListener('mousedown', onDocDown, true);
			document.removeEventListener('keydown', onDocKey, true);
		}

		function onDocDown(e) {
			if (!wrap.contains(e.target)) {
				closePanel();
			}
		}

		function onDocKey(e) {
			if (e.key === 'Escape') {
				closePanel();
			}
		}

		function openPanel() {
			if (opts.disabled) {
				return;
			}
			open = true;
			panel.hidden = false;
			trigger.setAttribute('aria-expanded', 'true');
			document.addEventListener('mousedown', onDocDown, true);
			document.addEventListener('keydown', onDocKey, true);
		}

		function selectKey(key) {
			selectedKey = key != null ? String(key) : '';
			fillTriggerFace(triggerFace, selectedKey);
			Array.prototype.forEach.call(panel.querySelectorAll('[role="option"]'), function (li) {
				li.setAttribute(
					'aria-selected',
					String(li.getAttribute('data-key') || '') === selectedKey
						? 'true'
						: 'false'
				);
				li.classList.toggle(
					'is-selected',
					String(li.getAttribute('data-key') || '') === selectedKey
				);
			});
			closePanel();
			if (typeof opts.onChange === 'function') {
				opts.onChange(selectedKey);
			}
		}

		function appendOption(key, label) {
			var li = el('li', {
				className: 'wtt-icon-list-chooser__option',
				role: 'option',
				tabIndex: -1,
			});
			li.setAttribute('data-key', key);
			li.setAttribute(
				'aria-selected',
				key === selectedKey ? 'true' : 'false'
			);
			if (key === selectedKey) {
				li.classList.add('is-selected');
			}
			var icon = paintIconEl(key, 'wtt-icon-list-chooser__icon');
			if (icon) {
				li.appendChild(icon);
			} else {
				li.appendChild(
					el('span', {
						className: 'wtt-icon-list-chooser__icon-slot',
						'aria-hidden': 'true',
					})
				);
			}
			li.appendChild(
				el('span', {
					className: 'wtt-icon-list-chooser__label',
					text: label,
				})
			);
			li.addEventListener('click', function (e) {
				e.preventDefault();
				selectKey(key);
			});
			panel.appendChild(li);
		}

		appendOption('', noneLabel);
		list.forEach(function (opt) {
			if (!opt || !opt.key) {
				return;
			}
			appendOption(String(opt.key), String(opt.label || opt.key));
		});
		if (
			selectedKey &&
			!list.some(function (o) {
				return o && String(o.key) === selectedKey;
			})
		) {
			appendOption(selectedKey, selectedKey + ' (not allowed)');
		}

		trigger.addEventListener('click', function (e) {
			e.preventDefault();
			if (open) {
				closePanel();
			} else {
				openPanel();
			}
		});

		wrap.appendChild(trigger);
		wrap.appendChild(panel);
		return wrap;
	}

	/**
	 * CatalogChoice (Q90): max nesting under a type host.
	 * Depth 0 = empty; 1 = only direct children â†’ List; â‰¥2 â†’ Tree.
	 * Mirrors src/blocks/shared/build-path-tree.js maxChoiceDepth for nested roots.
	 *
	 * @param {Array} roots Direct children of the type (or option folders).
	 * @return {number}
	 */
	function maxChoiceDepthFromRoots(roots) {
		function height(nodes) {
			var max = 0;
			(nodes || []).forEach(function (n) {
				if (!n) {
					return;
				}
				var kids = Array.isArray(n.children) ? n.children : [];
				var hasKids = !!(kids.length || n.hasChildren);
				if (hasKids && kids.length) {
					max = Math.max(max, 1 + height(kids));
				} else if (hasKids) {
					/* Unloaded deeper children â€” treat as nested (tree). */
					max = Math.max(max, 2);
				} else {
					max = Math.max(max, 1);
				}
			});
			return max;
		}
		if (!roots || !roots.length) {
			return 0;
		}
		return height(roots);
	}

	/**
	 * Max choice depth from flat fixedOptions with path strings ("A / B" or "A/B").
	 * Same product rule as build-path-tree.js maxChoiceDepth.
	 *
	 * @param {Array<{id?:*,path?:string,name?:string}>} options
	 * @return {number}
	 */
	function maxChoiceDepthFromOptions(options) {
		var list = (options || []).filter(function (o) {
			return o && (o.id != null || o.name);
		});
		if (!list.length) {
			return 0;
		}
		var paths = list.map(function (item) {
			var path = String(item.path || item.name || '')
				.trim()
				.replace(/\s*\/\s*/g, '/')
				.replace(/^\/+|\/+$/g, '');
			return path
				? path.split('/').filter(Boolean)
				: [String(item.name || item.id)];
		});
		var common = paths[0].slice();
		var i;
		for (i = 1; i < paths.length; i++) {
			var parts = paths[i];
			var n = 0;
			while (
				n < common.length &&
				n < parts.length &&
				common[n] === parts[n]
			) {
				n++;
			}
			common = common.slice(0, n);
		}
		var maxRel = 0;
		paths.forEach(function (parts) {
			var rel = Math.max(0, parts.length - common.length);
			var depth = Math.max(1, rel);
			if (depth > maxRel) {
				maxRel = depth;
			}
		});
		return maxRel;
	}

	/**
	 * @param {Array} roots
	 * @param {Array} [options]
	 * @param {'tree'|'flat'|'auto'} [mode]
	 * @return {'tree'|'flat'}
	 */
	function resolveCatalogChooserMode(roots, options, mode) {
		var m = String(mode || 'auto').toLowerCase();
		if (m === 'tree' || m === 'flat') {
			return m;
		}
		var fromRoots = maxChoiceDepthFromRoots(roots || []);
		var fromOpts = maxChoiceDepthFromOptions(options || []);
		var depth = Math.max(fromRoots, fromOpts);
		return depth >= 2 ? 'tree' : 'flat';
	}

	/**
	 * Flatten selectable choice leaves for a List Chooser (<select> / checklist).
	 *
	 * @param {Array} roots
	 * @return {Array<{id:number,name:string,path:string,shortDescription?:string}>}
	 */
	function flattenChoiceLeaves(roots) {
		var out = [];
		function walk(nodes, trail) {
			(nodes || []).forEach(function (n) {
				if (!n || n.id == null) {
					return;
				}
				var id = parseInt(n.id, 10) || 0;
				if (!id) {
					return;
				}
				var name = n.name || String(id);
				var pathParts = trail.concat([name]);
				var kids = Array.isArray(n.children) ? n.children : [];
				if (!kids.length) {
					out.push({
						id: id,
						name: name,
						path: pathParts.join(' / '),
						shortDescription: n.shortDescription || '',
						presentation:
							n.presentation && typeof n.presentation === 'object'
								? n.presentation
								: null,
					});
					return;
				}
				/* Intermediate nodes with children stay folders for tree mode;
				 * for flat list also include leaf-only â€” skip folders. */
				walk(kids, pathParts);
			});
		}
		walk(roots, []);
		return out;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'className') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'htmlFor') {
				node.htmlFor = attrs[key];
			} else if (key.indexOf('on') === 0 && typeof attrs[key] === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (key === 'draggable') {
				/* Must be "true"/"false" â€” boolean true was written as attribute "draggable" (invalid). */
				node.draggable = !!attrs[key];
				node.setAttribute('draggable', attrs[key] ? 'true' : 'false');
			} else if (
				key === 'checked' ||
				key === 'disabled' ||
				key === 'selected' ||
				key === 'readOnly' ||
				key === 'multiple'
			) {
				/* Boolean IDL properties â€” setAttribute alone is unreliable for checkboxes. */
				node[key] = !!attrs[key];
			} else if (attrs[key] === false || attrs[key] == null) {
				return;
			} else if (attrs[key] === true) {
				node.setAttribute(key, key);
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		if (children != null) {
			var list = Array.isArray(children) ? children : [children];
			list.forEach(function (child) {
				if (child) {
					node.appendChild(child);
				}
			});
		}
		return node;
	}

	function post(action, data) {
		var body = new window.URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');
		body.set('taxonomy', state.taxonomy);
		Object.keys(data || {}).forEach(function (key) {
			body.set(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).then(function (res) {
			return res.json();
		});
	}

	function decodeHtmlEntities(value) {
		var s = value != null ? String(value) : '';
		if (!s) {
			return '';
		}
		if (s.indexOf('&') === -1) {
			return s;
		}
		var ta = document.createElement('textarea');
		ta.innerHTML = s;
		return ta.value;
	}

	function setError(message) {
		state.error = decodeHtmlEntities(message || '');
		render();
	}

	function expandAncestorsOf(termId) {
		var target = parseInt(termId, 10) || 0;
		if (target <= 0) {
			return;
		}
		function walk(nodes, ancestors) {
			var list = nodes || [];
			for (var i = 0; i < list.length; i++) {
				var node = list[i];
				if (!node || node.id == null) {
					continue;
				}
				if (parseInt(node.id, 10) === target) {
					ancestors.forEach(function (id) {
						state.expanded[id] = true;
					});
					return true;
				}
				if (node.children && node.children.length) {
					if (walk(node.children, ancestors.concat([node.id]))) {
						return true;
					}
				}
			}
			return false;
		}
		walk(state.tree, []);
	}

	function scrollSelectedIntoTreeView() {
		window.requestAnimationFrame(function () {
			var row = document.querySelector('.wtt-tree__row.is-active');
			if (row && typeof row.scrollIntoView === 'function') {
				row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
		});
	}

	function capturePaneScroll() {
		var tree = document.querySelector('.wtt-tree-pane');
		var detail = document.querySelector('.wtt-detail-pane');
		return {
			tree: tree ? tree.scrollTop : 0,
			detail: detail ? detail.scrollTop : 0,
		};
	}

	function restorePaneScroll(scroll) {
		if (!scroll) {
			return;
		}
		function apply() {
			var tree = document.querySelector('.wtt-tree-pane');
			var detail = document.querySelector('.wtt-detail-pane');
			if (tree) {
				tree.scrollTop = scroll.tree || 0;
			}
			if (detail) {
				detail.scrollTop = scroll.detail || 0;
			}
		}
		/* Two frames + short delay: after DOM replace and tall Options/walk layout. */
		window.requestAnimationFrame(function () {
			apply();
			window.requestAnimationFrame(function () {
				apply();
				window.setTimeout(apply, 50);
			});
		});
	}

	/**
	 * True when focus is on Attributes Options / walk chrome (selects, ticks, …).
	 * Full re-render then would close native dropdowns and jump the pane.
	 */
	function isAttributesInteractTarget(el) {
		if (!el || typeof el.closest !== 'function') {
			return false;
		}
		return !!el.closest(
			[
				'.wtt-attributes__detail',
				'.wtt-attributes__detail-select',
				'.wtt-attributes__options-bar',
				'.wtt-attributes__walk-summary',
				'.wtt-attributes__choice-tree',
				'.wtt-attributes__table select',
				'.wtt-attributes__table input',
				'.wtt-attributes__table textarea',
			].join(',')
		);
	}

	function renderPreservingDetailScroll() {
		var scroll = capturePaneScroll();
		render();
		restorePaneScroll(scroll);
	}

	/**
	 * After Settings-walk AJAX: re-paint, but defer while the user is in Options
	 * (e.g. Presentation field dropdown) so the control stays usable.
	 */
	function scheduleRenderAfterAttrWalk() {
		if (isAttributesInteractTarget(document.activeElement)) {
			state.attrWalkRenderDeferred = true;
			ensureAttrWalkDeferredFlushHooks();
			return;
		}
		renderPreservingDetailScroll();
	}

	function flushDeferredAttrWalkRender() {
		if (!state.attrWalkRenderDeferred) {
			return;
		}
		if (isAttributesInteractTarget(document.activeElement)) {
			return;
		}
		state.attrWalkRenderDeferred = false;
		renderPreservingDetailScroll();
	}

	var attrWalkDeferredHooksBound = false;
	function ensureAttrWalkDeferredFlushHooks() {
		if (attrWalkDeferredHooksBound) {
			return;
		}
		attrWalkDeferredHooksBound = true;
		document.addEventListener(
			'focusin',
			function () {
				window.setTimeout(flushDeferredAttrWalkRender, 0);
			},
			true
		);
		document.addEventListener(
			'mousedown',
			function (e) {
				if (
					!state.attrWalkRenderDeferred ||
					!e.target ||
					isAttributesInteractTarget(e.target)
				) {
					return;
				}
				window.setTimeout(flushDeferredAttrWalkRender, 0);
			},
			true
		);
	}

	function selectNode(id, opts) {
		opts = opts || {};
		var termId = parseInt(id, 10) || 0;
		if (termId <= 0) {
			return;
		}
		var additive = !!opts.additive;
		var range = !!opts.range;
		var prevId = parseInt(state.selectedId, 10) || 0;
		var alreadyLoaded =
			sameTermId(prevId, termId) &&
			state.selectedNode &&
			sameTermId(state.selectedNode.id, termId) &&
			state.draft;

		if (range) {
			applySelectionRange(termId);
		} else if (additive) {
			toggleSelectionId(termId);
			state.selectionAnchorId = termId;
		} else if (opts.selectionIds) {
			setSelectionIds(opts.selectionIds);
			state.selectionAnchorId = termId;
		} else {
			state.selectedIds = {};
			state.selectedIds[termId] = true;
			state.selectionAnchorId = termId;
		}

		/*
		 * Already showing this primary node: only refresh multi-select chrome.
		 * Avoid clear→loading→get_node (stale responses / fake double-click).
		 */
		if (alreadyLoaded && !opts.forceReload) {
			persistTreeUi();
			render();
			scrollSelectedIntoTreeView();
			return;
		}

		/*
		 * Snapshot dirty settings into the save queue before clearing draft.
		 * flushPendingNodeSettings clones immediately; we only wait for the network after painting.
		 * Also flush deferred Choices ticks so exclusions are not lost on navigate.
		 */
		var leaveFlush = Promise.resolve();
		if (prevId && !sameTermId(prevId, termId)) {
			leaveFlush = Promise.resolve(flushPendingNodeSettings() || null)
				.then(function () {
					return flushPendingChoiceFilterDrafts();
				})
				.catch(function () {
					return flushPendingChoiceFilterDrafts();
				});
		} else {
			if (autosaveTimer) {
				window.clearTimeout(autosaveTimer);
				autosaveTimer = null;
			}
			leaveFlush = flushPendingChoiceFilterDrafts();
		}

		selectSeq += 1;
		var seq = selectSeq;

		/* Optimistic selection: highlight + loading on the first click (do not wait for flush). */
		state.previewValues = {};
		state.previewFocus = null;
		state.attrWalkLoading = {};
		expandAncestorsOf(termId);
		state.selectedId = termId;
		state.selectedNode = null;
		state.relationsPanelOpen = false;
		state.attributesPanelOpen = true;
		state.draft = null;
		state.savedDraft = null;
		state.settingsSaving = false;
		state.error = '';
		persistTreeUi();
		render();
		scrollSelectedIntoTreeView();

		leaveFlush
			.then(function () {
				if (seq !== selectSeq) {
					return null;
				}
				return post('wtt_get_node', { term_id: termId });
			})
			.then(function (json) {
				if (seq !== selectSeq) {
					return;
				}
				if (!json) {
					return;
				}
				if (!json.success || !json.data || !json.data.node) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (!sameTermId(state.selectedId, termId)) {
					return;
				}
				applyLoadedNode(json.data.node);
				render();
				scrollSelectedIntoTreeView();
			})
			.catch(function () {
				if (seq !== selectSeq) {
					return;
				}
				setError(i18n.error);
			});
	}

	function toggleSelectionId(termId) {
		termId = parseInt(termId, 10) || 0;
		if (termId <= 0) {
			return;
		}
		if (state.selectedIds[termId]) {
			delete state.selectedIds[termId];
			if (Object.keys(state.selectedIds).length === 0) {
				state.selectedIds[termId] = true;
			}
		} else {
			state.selectedIds[termId] = true;
		}
	}

	function setSelectionIds(ids) {
		state.selectedIds = {};
		(ids || []).forEach(function (id) {
			var n = parseInt(id, 10) || 0;
			if (n > 0) {
				state.selectedIds[n] = true;
			}
		});
	}

	function getSelectedIdList() {
		var wanted = state.selectedIds || {};
		var ordered = [];
		function walk(nodes) {
			(nodes || []).forEach(function (node) {
				if (!node || node.id == null) {
					return;
				}
				var id = parseInt(node.id, 10) || 0;
				if (wanted[id] || wanted[String(id)]) {
					ordered.push(id);
				}
				if (node.children && node.children.length) {
					walk(node.children);
				}
			});
		}
		walk(state.tree);
		return ordered;
	}

	/** Visible (expanded) tree rows in render order. */
	function flattenVisibleTreeIds(nodes, out) {
		out = out || [];
		(nodes || []).forEach(function (node) {
			if (!node || node.id == null) {
				return;
			}
			out.push(parseInt(node.id, 10) || 0);
			var hasChildren = node.hasChildren || (node.children && node.children.length);
			if (hasChildren && state.expanded[node.id] && node.children && node.children.length) {
				flattenVisibleTreeIds(node.children, out);
			}
		});
		return out;
	}

	function applySelectionRange(toId) {
		toId = parseInt(toId, 10) || 0;
		var fromId = parseInt(state.selectionAnchorId, 10) || toId;
		var visible = flattenVisibleTreeIds(getDisplayTreeRoots(), []);
		var iFrom = visible.indexOf(fromId);
		var iTo = visible.indexOf(toId);
		if (iFrom < 0 && iTo < 0) {
			state.selectedIds = {};
			state.selectedIds[toId] = true;
			return;
		}
		if (iFrom < 0) {
			iFrom = iTo;
		}
		if (iTo < 0) {
			iTo = iFrom;
		}
		var start = Math.min(iFrom, iTo);
		var end = Math.max(iFrom, iTo);
		state.selectedIds = {};
		for (var i = start; i <= end; i++) {
			if (visible[i]) {
				state.selectedIds[visible[i]] = true;
			}
		}
	}

	function isIdSelected(id) {
		id = parseInt(id, 10) || 0;
		return !!(state.selectedIds[id] || state.selectedIds[String(id)]);
	}

	function refreshTree(tree) {
		state.tree = tree || [];
		if (state.selectedId) {
			selectNode(state.selectedId, { forceReload: true });
		} else {
			render();
		}
	}

	function createTerm(parent) {
		var promptText = parent ? i18n.promptChild : i18n.promptRoot;
		var name = window.prompt(promptText, '');
		if (name === null) {
			return;
		}
		name = String(name).trim();
		if (!name) {
			return;
		}
		post('wtt_create_term', { name: name, parent: parent || 0 })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				if (json.data.node && json.data.node.id) {
					applyLoadedNode(json.data.node);
					state.selectedId = json.data.node.id;
					state.selectedIds = {};
					state.selectedIds[json.data.node.id] = true;
					state.selectionAnchorId = json.data.node.id;
					if (parent) {
						state.expanded[parent] = true;
					}
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function copySelectedAsSibling() {
		copySelected();
	}

	/**
	 * Duplicate one tree node as next sibling (same parent, name + " (copy)").
	 *
	 * @param {number} termId
	 */
	function copyNodeById(termId) {
		var id = parseInt(termId, 10) || 0;
		if (!id) {
			return;
		}
		post('wtt_copy_terms', { term_ids: JSON.stringify([id]) })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				var nodes = Array.isArray(json.data.nodes) ? json.data.nodes : [];
				var primary =
					nodes[0] && nodes[0].id != null
						? parseInt(nodes[0].id, 10) || 0
						: 0;
				if (primary > 0) {
					setSelectionIds([primary]);
					state.selectionAnchorId = primary;
					applyLoadedNode(nodes[0]);
					state.selectedId = primary;
					var parent =
						(nodes[0] && nodes[0].parent) ||
						(json.data.node && json.data.node.parent);
					if (parent) {
						state.expanded[parent] = true;
					}
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function copySelected() {
		var ids = getSelectedIdList();
		if (!ids.length && state.selectedId) {
			ids = [parseInt(state.selectedId, 10) || 0].filter(function (id) {
				return id > 0;
			});
		}
		if (!ids.length) {
			return;
		}
		post('wtt_copy_terms', { term_ids: JSON.stringify(ids) })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				var nodes = Array.isArray(json.data.nodes) ? json.data.nodes : [];
				var newIds = nodes
					.map(function (n) {
						return n && n.id != null ? parseInt(n.id, 10) || 0 : 0;
					})
					.filter(function (id) {
						return id > 0;
					});
				setSelectionIds(newIds);
				var primary =
					json.data.node && json.data.node.id
						? parseInt(json.data.node.id, 10) || 0
						: newIds.length
							? newIds[newIds.length - 1]
							: 0;
				if (primary > 0) {
					state.selectionAnchorId = primary;
					applyLoadedNode(
						json.data.node && json.data.node.id === primary
							? json.data.node
							: nodes[nodes.length - 1]
					);
					state.selectedId = primary;
					var parent = json.data.node && json.data.node.parent;
					if (parent) {
						state.expanded[parent] = true;
					}
					newIds.forEach(function (id) {
						var n = nodes.find(function (x) {
							return x && String(x.id) === String(id);
						});
						if (n && n.parent) {
							state.expanded[n.parent] = true;
						}
					});
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function deleteSelected(mode) {
		if (!state.selectedId) {
			return;
		}
		deleteNodeById(
			state.selectedId,
			!!(state.selectedNode && state.selectedNode.hasChildren),
			{
				deletable: state.selectedNode ? state.selectedNode.deletable : undefined,
				mode: mode || 'node',
			}
		);
	}

	function confirmNodeDeleteEnabled() {
		if (typeof cfg.confirmNodeDelete === 'boolean') {
			return cfg.confirmNodeDelete;
		}
		/* Fallback: off while Test mode is on. */
		return !cfg.testMode;
	}

	/**
	 * UR-S1: confirm structural attribute edits that bump model version.
	 * Popup only when setting ON and the host has ≥1 Model_Data instance.
	 *
	 * @param {object} [n] Host node (uses hasModelInstances from get_node).
	 * @returns {boolean} true = proceed
	 */
	function confirmStructuralModelChange(n) {
		if (!cfg.warnStructuralModelChange) {
			return true;
		}
		if (!n || !n.hasModelInstances) {
			return true;
		}
		var msg =
			i18n.warnStructuralModelChange ||
			'This structural change creates a new model generation and may cause data conflicts with existing Model Data instances. Continue?';
		return window.confirm(msg);
	}

	/**
	 * Q107: Settings `dialogOnValidationWarnings` (default OFF).
	 * Save with warnings is always allowed. When a save path has result.warnings[],
	 * call this before continuing; return true = user may proceed (no dialog or confirmed).
	 * No consumer yet — expose for later data-entry / schema save wiring.
	 *
	 * @param {{ warnings?: string[] }|null|undefined} result Validation envelope.
	 * @param {string} [message] Optional confirm text.
	 * @returns {boolean}
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
			i18n.dialogOnValidationWarnings ||
			'Validation warnings are present. Continue anyway?';
		return window.confirm(msg);
	}

	function isDevelopmentMode() {
		return !!cfg.developmentMode;
	}

	/** Protected relation rows unlock when Development mode is on (except typeLocked / parked bands). */
	function isRelationRowLocked(row) {
		if (row && (row.typeLocked || row.parkedTableBand)) {
			return true;
		}
		return !!(row && row.protected && !isDevelopmentMode());
	}

	/** Q90 parked Zeile/Kopf/Fuss leftovers — legacy table chrome, not product attrs. */
	function isParkedTableBandRelationRow(row) {
		return !!(row && row.parkedTableBand);
	}

	/**
	 * @param {string|number} termId
	 * @param {boolean} hasChildren
	 * @param {{deletable?:boolean, mode?:'node'|'branch'|'leaf'|'promote'|'cascade'}} [opts]
	 *   Soft-delete: node/promote = children move up then trash node only;
	 *   branch/cascade = trash node + descendants.
	 */
	function deleteNodeById(termId, hasChildren, opts) {
		opts = opts || {};
		if (!termId) {
			return;
		}
		var n =
			(state.selectedNode && String(state.selectedNode.id) === String(termId) && state.selectedNode) ||
			findNodeInTree(state.tree, termId);
		var deletable = opts.deletable;
		if (deletable === undefined && n) {
			deletable = n.deletable;
		}
		if (n && n.isTrash) {
			setError(i18n.trashCannotDelete || 'The Trash node cannot be deleted.');
			return;
		}
		if (n && n.isHiddenBin) {
			setError(i18n.hiddenBinCannotHide || 'The Hidden nodes bin cannot be deleted.');
			return;
		}
		if (deletable === false) {
			setError(i18n.notDeletable || 'This system or catalog type cannot be deleted.');
			return;
		}

		var uiMode = opts.mode || 'node';
		var apiMode =
			uiMode === 'branch' || uiMode === 'cascade'
				? 'cascade'
				: uiMode === 'leaf'
					? 'leaf'
					: 'promote';

		var ask = confirmNodeDeleteEnabled();
		var msg;
		if (apiMode === 'cascade') {
			msg =
				i18n.confirmMoveToTrashBranch ||
				i18n.confirmBranch ||
				'Move this node and all descendants to Trash? Parent/child links are kept.';
		} else if (hasChildren) {
			msg =
				i18n.confirmPromoteToTrash ||
				i18n.confirmNodeOnly ||
				'Move this node to Trash? Children move up one level.';
		} else {
			msg = i18n.confirmMoveToTrash || i18n.confirmLeaf || 'Move this node to Trash?';
		}
		if (ask && !window.confirm(msg)) {
			return;
		}
		runDelete(apiMode, termId);
	}

	function runDelete(mode, termId) {
		termId = termId || state.selectedId;
		if (!termId) {
			return;
		}
		var subtreeBefore = null;
		if (mode === 'cascade') {
			subtreeBefore = collectSubtreeIds(findNodeInTree(state.tree, termId), {});
		}
		post('wtt_delete_term', { term_id: termId, mode: mode })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (subtreeBefore) {
					Object.keys(subtreeBefore).forEach(function (id) {
						if (String(state.selectedId) === String(id)) {
							state.selectedId = null;
							state.selectedNode = null;
							state.draft = null;
							state.savedDraft = null;
						}
						if (state.selectedIds) {
							delete state.selectedIds[id];
							delete state.selectedIds[parseInt(id, 10)];
						}
					});
				} else if (String(state.selectedId) === String(termId)) {
					state.selectedId = null;
					state.selectedNode = null;
					state.draft = null;
					state.savedDraft = null;
				}
				if (state.selectedIds) {
					delete state.selectedIds[termId];
					delete state.selectedIds[String(termId)];
				}
				state.tree = json.data.tree || [];
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function collectSubtreeIds(node, out) {
		out = out || {};
		if (!node || node.id == null) {
			return out;
		}
		out[String(node.id)] = true;
		(node.children || []).forEach(function (child) {
			collectSubtreeIds(child, out);
		});
		return out;
	}

	function hideNodeById(termId) {
		if (!termId) {
			return;
		}
		var n =
			(state.selectedNode && String(state.selectedNode.id) === String(termId) && state.selectedNode) ||
			findNodeInTree(state.tree, termId);
		if (n && (n.isTrash || n.isHiddenBin)) {
			setError(
				n.isHiddenBin
					? i18n.hiddenBinCannotHide || 'The Hidden nodes bin cannot be hidden.'
					: i18n.trashCannotDelete || 'The Trash node cannot be deleted.'
			);
			return;
		}
		if (n && n.hidden) {
			unhideNodeById(termId);
			return;
		}
		if (
			!window.confirm(
				i18n.confirmHide ||
					'Hide this node from the tree? It stays in the database and appears under Hidden nodes.'
			)
		) {
			return;
		}
		post('wtt_hide_term', { term_id: termId })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				var binId = json.data && json.data.binId ? parseInt(json.data.binId, 10) || 0 : 0;
				if (binId > 0) {
					state.expanded[binId] = true;
					return selectNode(binId);
				}
				state.selectedId = null;
				state.selectedNode = null;
				state.draft = null;
				state.savedDraft = null;
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function unhideNodeById(termId) {
		if (!termId) {
			return;
		}
		post('wtt_unhide_term', { term_id: termId })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				var restoredId = parseInt(termId, 10) || 0;
				if (restoredId > 0 && findNodeInTree(state.tree, restoredId)) {
					return selectNode(restoredId);
				}
				state.selectedId = null;
				state.selectedNode = null;
				state.draft = null;
				state.savedDraft = null;
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function findNodeInTree(nodes, id) {
		var i;
		var found;
		id = parseInt(id, 10) || 0;
		for (i = 0; i < (nodes || []).length; i++) {
			if ((parseInt(nodes[i].id, 10) || 0) === id) {
				return nodes[i];
			}
			found = findNodeInTree(nodes[i].children || [], id);
			if (found) {
				return found;
			}
		}
		return null;
	}

	function expandAncestorsInMap(nodes, targetId, expandedMap, trail) {
		trail = trail || [];
		expandedMap = expandedMap || {};
		for (var i = 0; i < (nodes || []).length; i++) {
			var n = nodes[i];
			if (!n) {
				continue;
			}
			if (String(n.id) === String(targetId)) {
				trail.forEach(function (aid) {
					expandedMap[aid] = true;
				});
				return true;
			}
			if (n.children && n.children.length) {
				if (expandAncestorsInMap(n.children, targetId, expandedMap, trail.concat([n.id]))) {
					return true;
				}
			}
		}
		return false;
	}


	/**
	 * Shared TreeChooser — delegates to window.WTTNodePicker (assets/js/wtt-node-picker.js).
	 */
	function augmentNodePickerOpts(opts) {
		opts = opts || {};
		var lookup = Array.isArray(opts.lookupTrees) ? opts.lookupTrees.slice() : [];
		if (state && state.tree) {
			lookup.push(state.tree);
		}
		return Object.assign({}, opts, {
			i18n: i18n,
			showTypeInTree: !!(cfg && cfg.showTypeInTree),
			treePickerMode:
				opts.treePickerMode ||
				(cfg && cfg.treePickerMode) ||
				'popup',
			lookupTrees: lookup,
		});
	}

	function renderNodeTreePicker(opts) {
		if (!window.WTTNodePicker || typeof window.WTTNodePicker.render !== 'function') {
			return el('p', {
				className: 'wtt-field-hint',
				text: 'TreeChooser unavailable',
			});
		}
		return window.WTTNodePicker.render(augmentNodePickerOpts(opts));
	}

	function openNodeTreePickerDialog(opts, onDone) {
		if (!window.WTTNodePicker || typeof window.WTTNodePicker.openDialog !== 'function') {
			return;
		}
		window.WTTNodePicker.openDialog(augmentNodePickerOpts(opts), onDone);
	}

	function showReparentDialog(termId) {
		termId = termId || state.selectedId;
		if (!termId) {
			return;
		}
		var node = findNodeInTree(state.tree, termId);
		var blocked = collectSubtreeIds(node, {});
		var currentParent = node ? parseInt(node.parent, 10) || 0 : 0;
		var pickedParent = currentParent;
		var expandKey = 'reparent:' + String(termId);
		/* Start collapsed; only open path to current parent. */
		state.nodePickerExpanded = state.nodePickerExpanded || {};
		state.nodePickerExpanded[expandKey] = {};
		if (currentParent) {
			expandAncestorsInMap(state.tree, currentParent, state.nodePickerExpanded[expandKey], []);
		}
		state.nodePickerOpen[expandKey] = true;

		var pickerHost = el('div');
		function mountPicker() {
			var prevTree = pickerHost.querySelector('.wtt-node-picker__tree');
			var scrollTop = prevTree ? prevTree.scrollTop : 0;
			pickerHost.innerHTML = '';
			pickerHost.appendChild(
				renderNodeTreePicker({
					roots: state.tree,
					selectedId: pickedParent,
					currentId: currentParent,
					allowRoot: true,
					allowClear: false,
					blockedIds: blocked,
					expandKey: expandKey,
					defaultOpen: true,
					compact: false,
					presentation: 'inline',
					showPickedLabel: true,
					restoreScrollTop: scrollTop,
					pickedPrefix: i18n.reparentPicked || 'New parent:',
					rootLabel: i18n.reparentRoot || 'Root (no parent)',
					onSelect: function (id) {
						pickedParent = id;
						mountPicker();
					},
				})
			);
		}
		mountPicker();

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog wtt-dialog--reparent', role: 'dialog' }, [
				el('h2', { text: i18n.reparentTitle || 'Change parent' }),
				el('p', {
					text:
						i18n.reparentText ||
						'Choose a new parent for this term. Children stay attached.',
				}),
				pickerHost,
				el('div', { className: 'wtt-dialog__actions' }, [
					el('button', {
						type: 'button',
						className: 'button button-primary',
						text: i18n.reparentApply || 'Move',
						onClick: function () {
							document.body.removeChild(backdrop);
							if ((parseInt(pickedParent, 10) || 0) === (parseInt(currentParent, 10) || 0)) {
								return;
							}
							reparentTerm(termId, pickedParent);
						},
					}),
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.cancel,
						onClick: function () {
							document.body.removeChild(backdrop);
						},
					}),
				]),
			]),
		]);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) {
				document.body.removeChild(backdrop);
			}
		});
		document.body.appendChild(backdrop);
	}

	function reparentTerm(termId, parentId) {
		reparentTerms([termId], parentId);
	}

	function getDragMoveIds(sourceId) {
		sourceId = parseInt(sourceId, 10) || 0;
		if (sourceId <= 0) {
			return [];
		}
		if (isIdSelected(sourceId)) {
			return getSelectedIdList();
		}
		return [sourceId];
	}

	function buildBlockedDropIds(moveIds) {
		var blocked = {};
		(moveIds || []).forEach(function (id) {
			collectSubtreeIds(findNodeInTree(state.tree, id), blocked);
		});
		return blocked;
	}

	function isDropAllowed(targetId, moveIds) {
		targetId = parseInt(targetId, 10) || 0;
		if (targetId <= 0 || !moveIds || !moveIds.length) {
			return false;
		}
		var blocked = buildBlockedDropIds(moveIds);
		return !blocked[String(targetId)];
	}

	function clearTreeDropClasses() {
		var root = document.getElementById('wtt-app');
		if (!root) {
			return;
		}
		root
			.querySelectorAll(
				'.wtt-tree__row.is-dragging, .wtt-tree__row.is-drop-target, .wtt-tree__row.is-drop-blocked, .wtt-tree__row.is-drop-before, .wtt-tree__row.is-drop-after, .wtt-tree__row.is-drop-into'
			)
			.forEach(function (row) {
				row.classList.remove(
					'is-dragging',
					'is-drop-target',
					'is-drop-blocked',
					'is-drop-before',
					'is-drop-after',
					'is-drop-into'
				);
			});
	}

	/**
	 * Drop zone from pointer Y on a row: before / into / after.
	 * @return {'before'|'into'|'after'}
	 */
	function dropZoneFromEvent(e, rowEl) {
		if (!e || !rowEl || !rowEl.getBoundingClientRect) {
			return 'into';
		}
		var rect = rowEl.getBoundingClientRect();
		var h = rect.height || 1;
		var ratio = (e.clientY - rect.top) / h;
		if (ratio < 0.28) {
			return 'before';
		}
		if (ratio > 0.72) {
			return 'after';
		}
		return 'into';
	}

	function applyDropZoneClass(rowEl, zone, allowed) {
		if (!rowEl) {
			return;
		}
		rowEl.classList.remove(
			'is-drop-target',
			'is-drop-blocked',
			'is-drop-before',
			'is-drop-after',
			'is-drop-into'
		);
		if (!allowed) {
			rowEl.classList.add('is-drop-blocked');
			return;
		}
		rowEl.classList.add('is-drop-target');
		if (zone === 'before') {
			rowEl.classList.add('is-drop-before');
		} else if (zone === 'after') {
			rowEl.classList.add('is-drop-after');
		} else {
			rowEl.classList.add('is-drop-into');
		}
	}

	/**
	 * Resolve parent + optional insert-before sibling for a drop zone on target.
	 * into â†’ child of target (append). before/after â†’ sibling of target.
	 */
	function resolveDropPlacement(targetId, zone) {
		targetId = parseInt(targetId, 10) || 0;
		zone = zone || 'into';
		var target = findNodeInTree(state.tree, targetId);
		if (!target) {
			return null;
		}
		if (zone === 'into') {
			return { parentId: targetId, beforeId: 0 };
		}
		var parentId = parseInt(target.parent, 10) || 0;
		if (zone === 'before') {
			return { parentId: parentId, beforeId: targetId };
		}
		/* after: insert before the next sibling, or append. */
		var siblings =
			parentId > 0
				? (findNodeInTree(state.tree, parentId) || {}).children || []
				: state.tree || [];
		var nextId = 0;
		for (var i = 0; i < siblings.length; i++) {
			if (parseInt(siblings[i].id, 10) === targetId) {
				if (i + 1 < siblings.length) {
					nextId = parseInt(siblings[i + 1].id, 10) || 0;
				}
				break;
			}
		}
		return { parentId: parentId, beforeId: nextId };
	}

	function reparentTerms(ids, parentId, beforeId) {
		ids = (ids || [])
			.map(function (id) {
				return parseInt(id, 10) || 0;
			})
			.filter(function (id) {
				return id > 0;
			});
		parentId = parseInt(parentId, 10) || 0;
		beforeId = parseInt(beforeId, 10) || 0;
		if (!ids.length) {
			return;
		}
		if (parentId > 0 && !isDropAllowed(parentId, ids)) {
			setError(
				i18n.reparentBlockedDrop ||
					i18n.reparentBlocked ||
					'Cannot move under own descendant.'
			);
			return;
		}
		if (beforeId > 0 && ids.indexOf(beforeId) !== -1) {
			beforeId = 0;
		}
		post('wtt_reparent_terms', {
			term_ids: JSON.stringify(ids),
			parent: parentId,
			before: beforeId,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || state.tree;
				var moved = Array.isArray(json.data.moved) ? json.data.moved : ids;
				var primary = moved.length ? moved[moved.length - 1] : ids[0];
				if (parentId > 0) {
					state.expanded[parentId] = true;
				}
				moved.forEach(function (id) {
					state.expanded[id] = true;
				});
				state.error = '';
				persistTreeUi();
				if (primary) {
					selectNode(primary, { selectionIds: moved, forceReload: true });
				} else {
					render();
				}
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function markDraggingRows(moveIds) {
		var wanted = {};
		(moveIds || []).forEach(function (id) {
			wanted[String(id)] = true;
		});
		var root = document.getElementById('wtt-app');
		if (!root) {
			return;
		}
		root.querySelectorAll('.wtt-tree__row[data-term-id]').forEach(function (row) {
			var id = row.getAttribute('data-term-id');
			if (wanted[String(id)]) {
				row.classList.add('is-dragging');
			}
		});
	}

	function endTreeDrag() {
		state.dragMoveIds = null;
		clearTreeDropClasses();
	}

	function moveNode(termId, direction) {
		post('wtt_move_term', { term_id: termId, direction: direction })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				if (state.selectedId) {
					selectNode(state.selectedId, { forceReload: true });
				} else {
					render();
				}
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function collectExpandableIds(nodes, out) {
		out = out || [];
		(nodes || []).forEach(function (node) {
			if (!node) {
				return;
			}
			var hasChildren = node.hasChildren || (node.children && node.children.length);
			if (hasChildren) {
				out.push(node.id);
				collectExpandableIds(node.children || [], out);
			}
		});
		return out;
	}

	function expandAllTree() {
		state.expanded = {};
		var project = getProjectRootNode();
		if (state.hideRootNode && project) {
			state.expanded[project.id] = true;
		}
		collectExpandableIds(getDisplayTreeRoots()).forEach(function (id) {
			state.expanded[id] = true;
		});
		persistTreeUi();
		render();
	}

	function collapseAllTree() {
		state.expanded = {};
		var project = getProjectRootNode();
		if (state.hideRootNode && project) {
			state.expanded[project.id] = true;
		}
		persistTreeUi();
		render();
	}

	function treeActionButton(icon, title, onClick, disabled, extraClass) {
		var btn = el('button', {
			type: 'button',
			className:
				'wtt-tree__action' +
				(extraClass ? ' ' + extraClass : '') +
				(disabled ? ' is-disabled' : ''),
			title: title || '',
			'aria-label': title || '',
			draggable: false,
			onClick: function (e) {
				e.stopPropagation();
				if (disabled) {
					return;
				}
				onClick();
			},
			html: '<span class="dashicons dashicons-' + icon + '"></span>',
		});
		if (disabled) {
			btn.disabled = true;
		}
		return btn;
	}

	function toolbarIconButton(icon, title, onClick, extraClass) {
		return el('button', {
			type: 'button',
			className: 'wtt-toolbar__icon' + (extraClass ? ' ' + extraClass : ''),
			title: title || '',
			'aria-label': title || '',
			onClick: onClick,
			html: '<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span>',
		});
	}

	/**
	 * Project root (Fallstudie / sole taxonomy root) â€” still stored under that name.
	 */
	function getProjectRootNode() {
		var roots = state.tree || [];
		var i;
		for (i = 0; i < roots.length; i++) {
			if (roots[i] && roots[i].name === 'Fallstudie') {
				return roots[i];
			}
		}
		for (i = 0; i < roots.length; i++) {
			if (roots[i] && roots[i].name === 'BOM Testprojekt') {
				return roots[i];
			}
		}
		if (roots.length === 1) {
			return roots[0];
		}
		return null;
	}

	function displayNodeName(node) {
		if (!node) {
			return '';
		}
		if (node.name === 'Fallstudie') {
			return i18n.taxonomyRootLabel || 'Taxonomy';
		}
		return node.name;
	}

	/**
	 * Nodes shown at the top of the tree column (optionally skip project root).
	 */
	function getDisplayTreeRoots() {
		var roots = state.tree || [];
		if (!state.hideRootNode) {
			return roots;
		}
		var project = getProjectRootNode();
		if (!project) {
			return roots;
		}
		state.expanded[project.id] = true;
		return project.children || [];
	}

	/**
	 * Hide unavailable actions (clearer than faded) but keep slot width for column align.
	 */
	function treeActionSlot(btnOrNull) {
		var slot = el('span', { className: 'wtt-tree__action-slot' });
		if (btnOrNull) {
			slot.appendChild(btnOrNull);
		} else {
			slot.className += ' is-empty';
			slot.setAttribute('aria-hidden', 'true');
		}
		return slot;
	}

	function renderTreeNodes(nodes, list, depth) {
		depth = depth == null ? 0 : depth;
		nodes.forEach(function (node, index) {
			var hasChildren = node.hasChildren || (node.children && node.children.length);
			var isExpanded = !!state.expanded[node.id];
			var canUp = node.canMoveUp != null ? !!node.canMoveUp : index > 0;
			var canDown =
				node.canMoveDown != null ? !!node.canMoveDown : index < nodes.length - 1;
			var termId = parseInt(node.id, 10) || 0;
			var row = el('div', {
				className:
					'wtt-tree__row' +
					(sameTermId(state.selectedId, node.id) ? ' is-active' : '') +
					(isIdSelected(node.id) ? ' is-selected' : '') +
					(node.isTrash ? ' is-trash' : '') +
					(node.isHiddenBin ? ' is-hidden-bin' : '') +
					(node.trashed ? ' is-trashed' : '') +
					(node.hidden ? ' is-hidden-node' : ''),
				draggable: !node.isTrash && !node.trashed && !node.isHiddenBin && !node.hidden,
				'data-term-id': String(termId),
				'data-depth': String(depth),
				style: '--wtt-depth:' + String(depth),
				title:
					i18n.reparentDragHint ||
					'Drag: top edge = before, middle = into (last child), bottom edge = after',
				onDragStart: function (e) {
					var t = e.target;
					if (
						t &&
						t.closest &&
						(t.closest('.wtt-tree__action') ||
							t.closest('.wtt-tree__actions') ||
							t.closest('.wtt-tree__toggle') ||
							t.closest('.wtt-tree__model-count'))
					) {
						e.preventDefault();
						return;
					}
					if (node.isTrash || node.trashed || node.isHiddenBin || node.hidden) {
						e.preventDefault();
						return;
					}
					var moveIds = getDragMoveIds(termId);
					if (!moveIds.length) {
						e.preventDefault();
						return;
					}
					/* Do not set dragDidMove here — HTML5 fires dragstart on many plain clicks. */
					dragDidMove = false;
					state.dragMoveIds = moveIds;
					try {
						e.dataTransfer.effectAllowed = 'move';
						e.dataTransfer.setData('text/plain', JSON.stringify(moveIds));
					} catch (err) {
						/* some browsers require setData */
					}
					clearTreeDropClasses();
					markDraggingRows(moveIds);
				},
				onDragEnd: function () {
					endTreeDrag();
					window.setTimeout(function () {
						dragDidMove = false;
					}, 0);
				},
				onDragOver: function (e) {
					var moveIds = state.dragMoveIds;
					if (!moveIds || !moveIds.length) {
						return;
					}
					/* Real pointer travel over a drop target — suppress the trailing click. */
					dragDidMove = true;
					e.preventDefault();
					var zone = dropZoneFromEvent(e, row);
					var placement = resolveDropPlacement(termId, zone);
					var allowed =
						!!placement &&
						(placement.parentId <= 0 || isDropAllowed(placement.parentId, moveIds)) &&
						isDropAllowed(termId, moveIds);
					try {
						e.dataTransfer.dropEffect = allowed ? 'move' : 'none';
					} catch (err) {
						/* ignore */
					}
					applyDropZoneClass(row, zone, allowed);
				},
				onDragEnter: function (e) {
					var moveIds = state.dragMoveIds;
					if (!moveIds || !moveIds.length) {
						return;
					}
					e.preventDefault();
					var zone = dropZoneFromEvent(e, row);
					var placement = resolveDropPlacement(termId, zone);
					var allowed =
						!!placement &&
						(placement.parentId <= 0 || isDropAllowed(placement.parentId, moveIds)) &&
						isDropAllowed(termId, moveIds);
					applyDropZoneClass(row, zone, allowed);
				},
				onDragLeave: function (e) {
					var related = e.relatedTarget;
					if (related && row.contains(related)) {
						return;
					}
					row.classList.remove(
						'is-drop-target',
						'is-drop-blocked',
						'is-drop-before',
						'is-drop-after',
						'is-drop-into'
					);
				},
				onDrop: function (e) {
					e.preventDefault();
					e.stopPropagation();
					var moveIds = state.dragMoveIds;
					if (!moveIds || !moveIds.length) {
						try {
							var raw = e.dataTransfer.getData('text/plain');
							var parsed = JSON.parse(raw);
							if (Array.isArray(parsed)) {
								moveIds = parsed;
							}
						} catch (err) {
							moveIds = [];
						}
					}
					var zone = dropZoneFromEvent(e, row);
					var placement = resolveDropPlacement(termId, zone);
					endTreeDrag();
					if (!moveIds || !moveIds.length || !placement) {
						return;
					}
					if (!isDropAllowed(termId, moveIds)) {
						return;
					}
					if (placement.parentId > 0 && !isDropAllowed(placement.parentId, moveIds)) {
						return;
					}
					reparentTerms(moveIds, placement.parentId, placement.beforeId);
				},
			});

			var main = el('div', { className: 'wtt-tree__main' });

			if (hasChildren) {
				main.appendChild(
					el('button', {
						type: 'button',
						className: 'wtt-tree__toggle',
						draggable: false,
						'aria-expanded': isExpanded ? 'true' : 'false',
						onClick: function (e) {
							e.stopPropagation();
							state.expanded[node.id] = !state.expanded[node.id];
							persistTreeUi();
							render();
						},
						html:
							'<span class="dashicons dashicons-arrow-' +
							(isExpanded ? 'down' : 'right') +
							'"></span>',
					})
				);
			} else {
				main.appendChild(
					el('span', { className: 'wtt-tree__toggle wtt-tree__toggle--spacer' })
				);
			}

			var label = displayNodeName(node);
			var paintNode = node;
			if (state.draft && sameTermId(state.selectedId, node.id)) {
				paintNode = Object.assign({}, node, {
					name: state.draft.name != null ? String(state.draft.name) : node.name,
					icon: state.draft.icon != null ? String(state.draft.icon) : '',
				});
				label = displayNodeName(paintNode);
			}
			var nameBtn = null;
			if (
				window.WTTNodeRender &&
				window.WTTNodeRender.Registry &&
				typeof window.WTTNodeRender.Registry.renderTreeNode === 'function'
			) {
				nameBtn = window.WTTNodeRender.Registry.renderTreeNode(paintNode, {
					name: 'tree',
					mode: 'display',
					showType: !!cfg.showTypeInTree,
					displayName: label,
					depth: depth,
				});
			}
			if (!nameBtn) {
				var fallbackLabel = label;
				if (cfg.showTypeInTree && paintNode.typeLabel) {
					fallbackLabel += ' [' + paintNode.typeLabel + ']';
				}
				var fallbackChildren = [];
				var fallbackIcon =
					paintNode.icon != null
						? String(paintNode.icon).replace(/^dashicons-/, '').replace(/[^a-z0-9\-]/gi, '')
						: '';
				fallbackIcon = fallbackIcon.toLowerCase();
				if (fallbackIcon) {
					fallbackChildren.push(
						el('span', {
							className: 'dashicons dashicons-' + fallbackIcon + ' wtt-tree__icon',
							'aria-hidden': 'true',
						})
					);
				}
				fallbackChildren.push(el('span', { className: 'wtt-tree__name-text', text: fallbackLabel }));
				nameBtn = el('span', { className: 'wtt-tree__name' }, fallbackChildren);
				if (paintNode.shortDescription) {
					nameBtn.title = String(paintNode.shortDescription);
				} else if (paintNode.description) {
					nameBtn.title = String(paintNode.description);
				}
			}
			if (!nameBtn.classList.contains('wtt-tree__name')) {
				nameBtn.classList.add('wtt-tree__name');
			}
			nameBtn.setAttribute('role', 'button');
			nameBtn.tabIndex = 0;
			nameBtn.addEventListener('click', function (e) {
				if (dragDidMove) {
					dragDidMove = false;
					return;
				}
				if (e && e.target && e.target.closest && e.target.closest('.wtt-tree__model-count')) {
					return;
				}
				var additive = !!(e && (e.ctrlKey || e.metaKey));
				var range = !!(e && e.shiftKey);
				selectNode(node.id, { additive: additive && !range, range: range });
			});
			nameBtn.addEventListener('keydown', function (e) {
				if (e.key !== 'Enter' && e.key !== ' ') {
					return;
				}
				e.preventDefault();
				selectNode(node.id, {
					additive: !!(e.ctrlKey || e.metaKey),
					range: !!e.shiftKey,
				});
			});
			main.appendChild(nameBtn);
			var countLink = renderModelDataCountLink(node);
			if (countLink) {
				/* Keep (N) immediately after the name, not flush-right in the row. */
				nameBtn.style.flex = '0 1 auto';
				main.appendChild(countLink);
			}

			if (treeNodeHasTableRuleError(node)) {
				var hint =
					treeNodeTableErrorHint(node) ||
					i18n.tableTreeInvalid ||
					'Table definition invalid';
				main.appendChild(
					el('span', {
						className: 'wtt-tree__rule-alert',
						text: '!',
						title: hint,
						'aria-label': hint,
					})
				);
				row.classList.add('has-rule-error');
			}

			row.appendChild(main);

			var actions = el('div', { className: 'wtt-tree__actions', draggable: false });
			var canDelete = node.deletable !== false;
			var canHide =
				!node.isTrash && !node.trashed && !node.isHiddenBin && !node.hidden;
			var canUnhide = !!node.hidden && !node.isHiddenBin;

			actions.appendChild(
				treeActionSlot(
					treeActionButton('plus', i18n.addChild || 'Add child', function () {
						state.expanded[node.id] = true;
						createTerm(node.id);
					})
				)
			);
			actions.appendChild(
				treeActionSlot(
					!node.isTrash && !node.trashed && !node.isHiddenBin
						? treeActionButton(
								'admin-page',
								i18n.duplicateNodeHint ||
									i18n.duplicateNode ||
									i18n.copy ||
									'Duplicate',
								function () {
									copyNodeById(node.id);
								}
						  )
						: null
				)
			);
			actions.appendChild(
				treeActionSlot(
					canUp && !node.hidden && !node.isHiddenBin
						? treeActionButton(
								'arrow-up-alt2',
								i18n.moveUp || 'Move up',
								function () {
									moveNode(node.id, 'up');
								}
						  )
						: null
				)
			);
			actions.appendChild(
				treeActionSlot(
					canDown && !node.hidden && !node.isHiddenBin
						? treeActionButton(
								'arrow-down-alt2',
								i18n.moveDown || 'Move down',
								function () {
									moveNode(node.id, 'down');
								}
						  )
						: null
				)
			);
			actions.appendChild(
				treeActionSlot(
					canHide
						? treeActionButton(
								'hidden',
								i18n.hideNode || 'Hide node',
								function () {
									hideNodeById(node.id);
								}
						  )
						: canUnhide
							? treeActionButton(
									'visibility',
									i18n.unhideNode || 'Show again',
									function () {
										unhideNodeById(node.id);
									}
							  )
							: null
				)
			);
			actions.appendChild(
				treeActionSlot(
					canDelete
						? treeActionButton(
								'trash',
								i18n.deleteNode || 'Delete node',
								function () {
									deleteNodeById(node.id, !!hasChildren, {
										deletable: node.deletable,
										mode: 'node',
									});
								}
						  )
						: null
				)
			);
			actions.appendChild(
				treeActionSlot(
					hasChildren && canDelete
						? treeActionButton(
								'networking',
								i18n.deleteBranch || 'Delete branch',
								function () {
									deleteNodeById(node.id, true, {
										deletable: node.deletable,
										mode: 'branch',
									});
								},
								false,
								'wtt-tree__action--branch-delete'
						  )
						: null
				)
			);
			row.appendChild(actions);

			var li = el('li', { className: 'wtt-tree__node' }, [row]);
			if (hasChildren) {
				var childList = el('ul', {
					className: 'wtt-tree__children' + (isExpanded ? '' : ' is-collapsed'),
				});
				renderTreeNodes(node.children || [], childList, depth + 1);
				li.appendChild(childList);
			}
			list.appendChild(li);
		});
	}

	function mergeNodeTypeIntoTree(nodes, updated) {
		if (!updated || !updated.id) {
			return false;
		}
		var found = false;
		(nodes || []).forEach(function (node) {
			if (node.id === updated.id) {
				node.type = updated.type || null;
				node.typeLabel = updated.type && updated.type.name ? updated.type.name : '';
				if (updated.name != null) {
					node.name = String(updated.name);
				}
				if (updated.icon != null) {
					node.icon = String(updated.icon);
				} else if (Object.prototype.hasOwnProperty.call(updated, 'icon')) {
					node.icon = '';
				}
				if (typeof updated.isTable === 'boolean') {
					node.isTable = updated.isTable;
				}
				if (updated.tableValidation) {
					node.tableInvalid = !updated.tableValidation.ok;
					node.tableErrorHint = (updated.tableValidation.errors || [])
						.slice(0, 2)
						.join(' ');
				} else if (typeof updated.tableInvalid === 'boolean') {
					node.tableInvalid = updated.tableInvalid;
					node.tableErrorHint = updated.tableErrorHint || '';
				}
				found = true;
				return;
			}
			if (node.children && node.children.length && mergeNodeTypeIntoTree(node.children, updated)) {
				found = true;
			}
		});
		return found;
	}

	function applyTypePresetsToDraft(typeNode) {
		if (!state.draft || !typeNode) {
			return;
		}
		state.draft.required = !!typeNode.required;
		state.draft.fixedEnabled = !!typeNode.fixedEnabled;
		state.draft.fixedLiteral =
			typeNode.fixedLiteral != null ? String(typeNode.fixedLiteral) : '';
		state.draft.fixedNodeId = typeNode.fixedNodeId || 0;
		state.draft.fixed = typeNode.fixed || null;
		if (typeUsesRefScope(state.draft.type)) {
			state.draft.refScopeId = typeNode.refScopeId || 0;
			state.draft.allowedRefIds = Array.isArray(typeNode.allowedRefIds)
				? typeNode.allowedRefIds.slice()
				: [];
		} else {
			state.draft.refScopeId = 0;
			state.draft.allowedRefIds = [];
		}
		if (typeNameIs(state.draft.type, 'table')) {
			state.draft.hasFooter = !!typeNode.hasFooter;
			state.draft.isTable = true;
			state.draft.typeInheriting = false;
			/* Props live on the catalog `table` type â€” copy for band-binding UI. */
			var props =
				Array.isArray(typeNode.typeProps) && typeNode.typeProps.length
					? deepClone(typeNode.typeProps)
					: Array.isArray(typeNode.effectiveTypeProps) && typeNode.effectiveTypeProps.length
						? deepClone(typeNode.effectiveTypeProps)
						: deepClone(DEFAULT_TABLE_TYPE_PROPS);
			state.draft.effectiveTypeProps = props;
			if (!state.draft.typeProps || !state.draft.typeProps.length) {
				state.draft.typeProps = deepClone(props);
			}
			state.draft.propBindings = normalizePropBindings(state.draft.propBindings);
			if (
				(!state.draft.directChildren || !state.draft.directChildren.length) &&
				state.selectedNode &&
				Array.isArray(state.selectedNode.directChildren)
			) {
				state.draft.directChildren = deepClone(state.selectedNode.directChildren);
			}
		}
		if (typeNameIs(state.draft.type, 'set')) {
			state.draft.setSeparator =
				typeNode.setSeparator != null ? String(typeNode.setSeparator) : '/';
			state.draft.setJoinUnits = typeNode.setJoinUnits !== false;
			state.draft.setLabelChildren = typeNode.setLabelChildren !== false;
		}
		if (typeKeyFromMember({ type: state.draft.type }) === 'media') {
			state.draft.mediaConfig = typeNode.mediaConfig
				? {
						allowUpload: typeNode.mediaConfig.allowUpload !== false,
						allowUrl: !!typeNode.mediaConfig.allowUrl,
						allowedKinds: normalizeAllowedKinds(
							typeNode.mediaConfig.allowedKinds != null
								? typeNode.mediaConfig.allowedKinds
								: []
						),
				  }
				: {
						allowUpload: true,
						allowUrl: false,
						allowedKinds: [],
				  };
		} else {
			state.draft.mediaConfig = null;
		}
		if (typeKeyFromMember({ type: state.draft.type }) === 'date') {
			state.draft.dateConfig = typeNode.dateConfig
				? {
						mode:
							typeNode.dateConfig.mode === 'datetime'
								? 'datetime'
								: 'date',
				  }
				: { mode: 'date' };
		} else if (
			String(state.draft.name || '')
				.trim()
				.toLowerCase() === 'date'
		) {
			state.draft.dateConfig = typeNode.dateConfig
				? {
						mode:
							typeNode.dateConfig.mode === 'datetime'
								? 'datetime'
								: 'date',
				  }
				: state.draft.dateConfig || { mode: 'date' };
		} else {
			state.draft.dateConfig = null;
		}
		if (typeKeyFromMember({ type: state.draft.type }) === 'textarea') {
			state.draft.textareaConfig = normalizeTextareaConfig(
				typeNode.textareaConfig,
				'textarea'
			);
		} else if (
			String(state.draft.name || '')
				.trim()
				.toLowerCase() === 'textarea'
		) {
			state.draft.textareaConfig = normalizeTextareaConfig(
				typeNode.textareaConfig || state.draft.textareaConfig,
				'textarea'
			);
		} else {
			state.draft.textareaConfig = null;
		}
		if (isNodePresentationTypeKey(typeKeyFromMember({ type: state.draft.type }))) {
			state.draft.presentationConfig = typeNode.presentationConfig
				? {
						context: normalizePresentationContext(
							typeNode.presentationConfig.context
						),
				  }
				: { context: 'form' };
			state.draft.required = false;
		} else {
			state.draft.presentationConfig = null;
		}
	}

	function setDraftIsTemplate(checked) {
		if (!state.draft) {
			return;
		}
		if (!isDevelopmentMode()) {
			return;
		}
		state.draft.isTemplate = !!checked;
		afterDraftMutation();
	}

	function setDraftTypeInheriting(checked) {
		if (!state.draft) {
			return;
		}
		/* Under an inheriting ancestor without Override: locked (display-only). */
		if (state.draft.canInheritType && !state.draft.typeOverride) {
			return;
		}
		/* Table is a structural container â€” never push type onto bands/fields. */
		if (state.draft.isTable || typeNameIs(state.draft.type, 'table')) {
			state.draft.typeInheriting = false;
			afterDraftMutation();
			return;
		}
		state.draft.typeInheriting = !!checked;
		afterDraftMutation();
	}

	function setDraftTypeOverride(checked) {
		if (!state.draft || !state.selectedNode) {
			return;
		}
		checked = !!checked;
		state.draft.typeOverride = checked;
		if (checked) {
			state.draft.typeId = state.draft.ownTypeId || 0;
			state.draft.type = resolveTypeFromOptions(
				state.draft.typeId,
				state.selectedNode.typeOptions
			);
		} else {
			var inheritedId =
				state.draft.inheritedTypeId ||
				state.selectedNode.inheritedTypeId ||
				0;
			state.draft.typeId = inheritedId;
			state.draft.type = resolveTypeFromOptions(
				inheritedId,
				state.selectedNode.typeOptions
			);
			if (!state.draft.type && state.selectedNode.type && !state.selectedNode.typeOverride) {
				state.draft.type = state.selectedNode.type;
			}
		}
		state.draft.isTable = typeNameIs(state.draft.type, 'table');
		state.draft.isSet = typeNameIs(state.draft.type, 'set');
		afterDraftMutation();
	}

	function setDraftType(typeId) {
		if (!state.draft || !state.selectedNode) {
			return;
		}
		if (state.draft.typeIsParent || state.draft.freeTypeLocked) {
			return;
		}
		if (state.draft.canInheritType && !state.draft.typeOverride) {
			return;
		}
		typeId = typeId || 0;
		state.draft.typeId = typeId;
		state.draft.ownTypeId = typeId;
		state.draft.type = resolveTypeFromOptions(typeId, state.selectedNode.typeOptions);
		state.draft.isTable = typeNameIs(state.draft.type, 'table');
		state.draft.isSet = typeNameIs(state.draft.type, 'set');
		if (state.draft.isTable) {
			state.draft.typeInheriting = false;
			ensureTableDraftChrome(state.draft);
		} else {
			state.draft.effectiveTypeProps = [];
		}
		state.draft.typeBranch = null;
		state.draft.quantitySchema = null;
		state.draft.fixedEnabled = false;
		state.draft.fixedLiteral = '';
		state.draft.fixedNodeId = 0;
		state.draft.fixed = null;
		state.draft.refScopeId = 0;
		state.draft.required = false;
		state.draft.hasFooter = false;
		state.draft.mediaConfig = null;
		state.draft.dateConfig = null;
		state.draft.textareaConfig = null;
		state.draft.presentationConfig = null;
		if (typeKeyFromMember({ type: state.draft.type }) === 'media') {
			state.draft.mediaConfig = {
				allowUpload: true,
				allowUrl: false,
				allowedKinds: [],
			};
		}
		if (typeKeyFromMember({ type: state.draft.type }) === 'date') {
			state.draft.dateConfig = { mode: 'date' };
		}
		if (typeKeyFromMember({ type: state.draft.type }) === 'textarea') {
			state.draft.textareaConfig = { cols: 40, rows: 4 };
		}
		if (isNodePresentationTypeKey(typeKeyFromMember({ type: state.draft.type }))) {
			state.draft.presentationConfig = { context: 'form' };
			state.draft.required = false;
		}
		if (!typeId) {
			afterDraftMutation();
			return;
		}
		afterDraftMutation();
		post('wtt_get_node', { term_id: typeId })
			.then(function (json) {
				if (!state.draft || state.draft.typeId !== typeId) {
					return;
				}
				if (json && json.success && json.data) {
					applyTypePresetsToDraft(json.data);
					afterDraftMutation();
				}
			})
			.catch(function () {
				/* presets optional */
			});
		post('wtt_get_type_branch', { type_id: typeId })
			.then(function (json) {
				if (!state.draft || state.draft.typeId !== typeId) {
					return;
				}
				if (!json || !json.success) {
					return;
				}
				if (json.data && json.data.isSet) {
					state.draft.isSet = true;
				}
				if (json.data && json.data.quantitySchema) {
					state.draft.quantitySchema = json.data.quantitySchema;
					state.draft.typeBranch = null;
					if (state.selectedNode) {
						state.selectedNode.quantitySchema = json.data.quantitySchema;
						state.selectedNode.typeBranch = null;
					}
				} else {
					state.draft.typeBranch = json.data.typeBranch || null;
					state.draft.quantitySchema = null;
					if (state.selectedNode) {
						state.selectedNode.quantitySchema = null;
					}
				}
				afterDraftMutation();
			})
			.catch(function () {
				/* draft type still applied; branch optional */
			});
	}

	/**
	 * Preview slug from a display name (server uses sanitize_title + unique slug on save).
	 */
	function slugifyPreview(name) {
		var s = String(name || '')
			.toLowerCase()
			.replace(/Ã¤/g, 'ae')
			.replace(/Ã¶/g, 'oe')
			.replace(/Ã¼/g, 'ue')
			.replace(/ÃŸ/g, 'ss')
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '');
		return s;
	}

	function setDraftName(name, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.name = name != null ? String(name) : '';
		state.draft.slug = slugifyPreview(state.draft.name);
		refreshSlugMetaDisplay();
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftDescription(description, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.description = description != null ? String(description) : '';
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftShortDescription(shortDescription, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.shortDescription = shortDescription != null ? String(shortDescription) : '';
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftIcon(iconKey, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		var key = iconKey != null ? String(iconKey).replace(/^dashicons-/, '') : '';
		key = key.replace(/[^a-z0-9\-]/gi, '').toLowerCase();
		state.draft.icon = key;
		afterDraftMutation({ silent: !!opts.silent });
	}

	/**
	 * Q117/Q118: Presentation texts + icon in one always-visible Display row.
	 * Saves with node settings (autosave or toolbar Save) — no separate button.
	 *
	 * @param {object} node
	 * @param {HTMLElement} iconControl
	 * @param {boolean} controlsLocked
	 * @return {HTMLElement}
	 */
	function renderDisplayIconPresentationRow(node, iconControl, controlsLocked) {
		var termId = node && node.id != null ? parseInt(node.id, 10) || 0 : 0;
		var nodeName = node && node.name != null ? String(node.name) : '';
		controlsLocked = !!controlsLocked;

		var grid = el('div', {
			className: 'wtt-display-presentation-row',
		});
		grid.appendChild(
			el('div', {
				className:
					'wtt-display-presentation-row__item wtt-display-presentation-row__item--icon',
			}, [
				el('span', {
					className: 'wtt-display-presentation-row__label',
					text: i18n.icon || 'Icon',
				}),
				iconControl,
			])
		);
		var fieldsHost = el('div', {
			className: 'wtt-display-presentation-row__fields',
		});
		grid.appendChild(fieldsHost);
		var helpHost = el('div', {
			className: 'wtt-display-presentation-row__help-host',
		});
		grid.appendChild(helpHost);

		var status = el('p', {
			className: 'wtt-presentation-fold__status description',
			text:
				termId > 0
					? i18n.presentationFoldLoading || 'Loading…'
					: '',
		});

		var fieldMeta = [
			{ key: 'form', label: i18n.presentationForm || 'Form' },
			{ key: 'table', label: i18n.presentationTable || 'Table' },
			{ key: 'select', label: i18n.presentationSelect || 'Select' },
			{ key: 'symbol', label: i18n.presentationSymbol || 'Symbol' },
		];
		var helpMeta = {
			key: 'help',
			label: i18n.presentationHelp || 'Help',
		};

		function followsPlaceholder(key) {
			if (key === 'symbol') {
				return '';
			}
			return (
				(i18n.presentationFollowsName || 'Follows node name') +
				(nodeName ? ': ' + nodeName : '')
			);
		}

		function presentationOpenHref(listUrl) {
			if (listUrl) {
				return String(listUrl);
			}
			var base =
				cfg.presentationPageUrl != null
					? String(cfg.presentationPageUrl)
					: '';
			if (!base || termId <= 0) {
				return '#';
			}
			var sep = base.indexOf('?') >= 0 ? '&' : '?';
			return (
				base +
				sep +
				'term_id=' +
				encodeURIComponent(String(termId)) +
				'&return=tree'
			);
		}

		var openLink = el('a', {
			className: 'wtt-form__label-link',
			href: '#',
			text:
				i18n.presentationEditLinkShort ||
				i18n.presentationEditLink ||
				'Open presentation…',
			title:
				i18n.presentationEditLink ||
				'Open full presentation page…',
		});
		openLink.addEventListener('click', function (e) {
			var href = openLink.getAttribute('href') || '#';
			if (href === '#') {
				e.preventDefault();
			}
		});

		function paintFields(bag) {
			fieldsHost.textContent = '';
			helpHost.textContent = '';
			bag = bag || emptyPresentationDraft();
			openLink.href = presentationOpenHref(bag.listUrl);
			status.textContent = bag.locale
				? (i18n.locale || 'Locale') + ': ' + bag.locale
				: '';

			fieldMeta.forEach(function (meta) {
				var rawVal =
					bag.values && bag.values[meta.key] != null
						? String(bag.values[meta.key])
						: '';
				var input = el('input', {
					type: 'text',
					className: 'regular-text wtt-presentation-fold__input',
				});
				input.value = rawVal;
				input.placeholder = followsPlaceholder(meta.key);
				input.disabled = !!controlsLocked;
				input.addEventListener('input', function () {
					setDraftPresentationValue(meta.key, input.value);
				});
				fieldsHost.appendChild(
					el('div', { className: 'wtt-display-presentation-row__item' }, [
						el('span', {
							className: 'wtt-display-presentation-row__label',
							text: meta.label,
						}),
						input,
					])
				);
			});

			var helpVal =
				bag.values && bag.values[helpMeta.key] != null
					? String(bag.values[helpMeta.key])
					: '';
			var helpArea = el('textarea', {
				className:
					'regular-text wtt-presentation-fold__input wtt-display-presentation-row__textarea',
				rows: '2',
			});
			helpArea.value = helpVal;
			helpArea.placeholder = followsPlaceholder(helpMeta.key);
			helpArea.disabled = !!controlsLocked;
			helpArea.addEventListener('input', function () {
				setDraftPresentationValue(helpMeta.key, helpArea.value);
			});
			helpHost.appendChild(
				el(
					'div',
					{
						className:
							'wtt-display-presentation-row__item wtt-display-presentation-row__item--help',
					},
					[
						el('span', {
							className: 'wtt-display-presentation-row__label',
							text: helpMeta.label,
						}),
						helpArea,
					]
				)
			);
			refreshSettingsActionState();
		}

		if (termId > 0) {
			var existing =
				state.draft &&
				state.draft.presentation &&
				state.draft.presentation.loaded
					? state.draft.presentation
					: null;
			if (existing) {
				if (nodeName && state.draft) {
					/* keep draft values */
				}
				paintFields(existing);
			} else {
				post('wtt_get_node_presentation', { term_id: String(termId) })
					.then(function (json) {
						if (!json || !json.success || !json.data) {
							status.textContent =
								i18n.presentationFoldError ||
								'Could not load presentation.';
							return;
						}
						if (json.data.nodeName) {
							nodeName = String(json.data.nodeName);
						}
						paintFields(setDraftPresentationFromServer(json.data));
					})
					.catch(function () {
						status.textContent =
							i18n.presentationFoldError ||
							'Could not load presentation.';
					});
			}
		}

		var wrap = el('div', {
			className: 'wtt-presentation-fold wtt-presentation-fold--inline',
		});
		wrap.appendChild(grid);
		wrap.appendChild(status);

		return formRow(i18n.presentationFoldTitle || 'Presentation texts', [wrap], {
			className: 'wtt-form__row--presentation-inline',
			labelExtra: openLink,
			help:
				i18n.presentationFoldHint ||
				'Empty fields follow the node name (and update on rename). Saved values stay until you change or clear them.',
		});
	}

	/** @deprecated Use renderDisplayIconPresentationRow. */
	function renderPresentationFoldable(node, controlsLocked) {
		return renderDisplayIconPresentationRow(
			node,
			el('span', { className: 'description', text: '—' }),
			controlsLocked
		);
	}

	function setDraftPropBinding(propKey, childId) {
		if (!state.draft) {
			return;
		}
		propKey = String(propKey || '');
		if (!propKey) {
			return;
		}
		ensureTableDraftChrome(state.draft);
		var next = normalizePropBindings(state.draft.propBindings);
		childId = parseInt(childId, 10) || 0;
		if (childId <= 0) {
			delete next[propKey];
		} else {
			next[propKey] = childId;
		}
		state.draft.propBindings = next;
		afterDraftMutation();
		/* Persist band bindings immediately â€” snapshot draft so later stale saves cannot drop it. */
		saveNodeSettings({
			autosave: true,
			force: true,
			termId: state.selectedId,
			draft: deepClone(state.draft),
		});
	}

	function renderTableBandBindings(n, controlsLocked) {
		if (!n || !(n.isTable || n.isTableTypeCatalog)) {
			return null;
		}
		var props = (n.effectiveTypeProps && n.effectiveTypeProps.length)
			? n.effectiveTypeProps
			: n.typeProps && n.typeProps.length
				? n.typeProps
				: DEFAULT_TABLE_TYPE_PROPS;
		var bandProps = props.filter(function (p) {
			if (!p) {
				return false;
			}
			var k = String(p.key || p.id || '').toLowerCase();
			return k === 'zeile' || k === 'kopf' || k === 'fuss';
		});
		if (!bandProps.length) {
			bandProps = DEFAULT_TABLE_TYPE_PROPS.slice();
		}
		var children = Array.isArray(n.directChildren) ? n.directChildren : [];
		if (
			!children.length &&
			state.selectedNode &&
			Array.isArray(state.selectedNode.directChildren)
		) {
			children = state.selectedNode.directChildren;
		}
		var wrap = el('div', { className: 'wtt-panel wtt-table-band-bindings' });
		var head = el('div', { className: 'wtt-panel__head' });
		head.appendChild(
			el('h3', {
				className: 'wtt-panel__title',
				text: i18n.tableBandBindingsTitle || 'Bindings (type properties)',
			})
		);
		wrap.appendChild(head);
		wrap.appendChild(
			el('p', {
				className: 'wtt-panel__hint',
				text:
					i18n.tableBandBindingsHint ||
					'Bindings map type property â†’ child node (not by the child\'s display name).',
			})
		);
		var body = el('div', { className: 'wtt-panel__body wtt-table-band-bindings__rows' });
		bandProps.forEach(function (prop) {
			var key = String(prop.id || prop.key || '');
			var label = String(prop.name || prop.key || key);
			var required = !!prop.required;
			var current =
				parseInt((n.propBindings && (n.propBindings[prop.id] || n.propBindings[prop.key])) || 0, 10) || 0;
			var opts = [{ id: 0, name: i18n.tableBandUnbound || 'â€” not bound â€”' }].concat(
				children.map(function (c) {
					return { id: c.id, name: c.name || ('#' + c.id) };
				})
			);
			var sel = el('select', {
				className: 'wtt-table-band-binding',
				disabled: !!controlsLocked,
			});
			opts.forEach(function (o) {
				var opt = el('option', {
					value: String(o.id),
					text: o.name,
				});
				if (parseInt(o.id, 10) === current) {
					opt.selected = true;
				}
				sel.appendChild(opt);
			});
			sel.addEventListener('change', function (e) {
				setDraftPropBinding(key, e.target.value);
			});
			body.appendChild(
				formRow(
					label + (required ? ' *' : ''),
					[sel],
					{
						className: 'wtt-form__row--band',
						help:
							i18n.tableBandBindingHelp ||
							'Bind this slot to a direct child. Columns come from that child\'s fields.',
					}
				)
			);
		});
		wrap.appendChild(body);
		return wrap;
	}
	function setDraftRequired(required) {
		if (!state.draft) {
			return;
		}
		state.draft.required = !!required;
		afterDraftMutation();
	}

	function setDraftPreferredRender(layout) {
		if (!state.draft) {
			return;
		}
		var raw = layout != null ? String(layout).trim() : '';
		if (
			raw === 'inherit' ||
			raw === 'clear' ||
			raw.toLowerCase() === 'inherit'
		) {
			state.draft.preferredRenderInherit = true;
			/* Keep effective value for preview until reload; save sends inherit. */
			state.draft.preferredRender = normalizePreferredRender(
				(viewNode() && viewNode().preferredRender) ||
					state.draft.preferredRender ||
					'FormRenderer'
			);
		} else {
			state.draft.preferredRenderInherit = false;
			state.draft.preferredRender = normalizePreferredRender(raw);
		}
		afterDraftMutation();
	}

	function setDraftPreferredConverter(converterId) {
		if (!state.draft) {
			return;
		}
		var id = normalizePreferredConverter(converterId);
		state.draft.preferredConverter = id;
		if (!state.draft.intConfig) {
			state.draft.intConfig = { displayFormat: id || 'arabic' };
		} else {
			state.draft.intConfig.displayFormat = id || 'arabic';
		}
		afterDraftMutation();
	}

	/** @deprecated Prefer setDraftPreferredConverter. */
	function setDraftIntDisplayFormat(formatId) {
		setDraftPreferredConverter(formatId);
	}

	function setDraftFooterOp(opKey) {
		if (!state.draft) {
			return;
		}
		state.draft.footerOp = opKey != null ? String(opKey) : '';
		if (state.draft.fussFieldContext) {
			state.draft.fussFieldContext.footerOp = state.draft.footerOp;
		}
		afterDraftMutation();
	}

	function renderFooterOpPicker(n, controlsLocked) {
		var ctx = n && n.fussFieldContext;
		if (!ctx) {
			return null;
		}
		var options = Array.isArray(ctx.footerOpOptions) ? ctx.footerOpOptions : [];
		if (!options.length) {
			var api = nodeRenderApi();
			if (api && typeof api.footerOpList === 'function') {
				options = api.footerOpList({ typeKey: ctx.zeileTypeKey || 'text' });
			}
		}
		if (!options.length) {
			return null;
		}
		var current = String(n.footerOp || ctx.footerOp || '');
		var select = el('select', {
			id: 'wtt-footer-op',
			className: 'wtt-footer-op-select',
			disabled: !!controlsLocked,
			onChange: function (e) {
				setDraftFooterOp(e.target.value);
			},
		});
		options.forEach(function (opt) {
			var key = String(opt.key || '');
			var label = String(opt.label || key);
			var symbol = opt.symbol != null ? String(opt.symbol) : '';
			var option = el('option', {
				value: key,
				text: symbol && symbol !== 'â€”' ? label + ' (' + symbol + ')' : label,
			});
			if (key === current) {
				option.selected = true;
			}
			select.appendChild(option);
		});
		return formRow(i18n.footerOp || 'Aggregate', [select], {
			htmlFor: 'wtt-footer-op',
			className: 'wtt-form__row--footer-op',
			help:
				i18n.footerOpHint ||
				'Fuss cell operation for this column (aligned with Zeile by index). Type stays the value type; the op lives on the Fuss slot.',
		});
	}

	function setDraftSetSeparator(separator) {
		if (!state.draft) {
			return;
		}
		state.draft.setSeparator = separator != null ? String(separator) : '/';
		afterDraftMutation({ silent: true });
	}

	function setDraftSetJoinUnits(joinUnits) {
		if (!state.draft) {
			return;
		}
		state.draft.setJoinUnits = !!joinUnits;
		afterDraftMutation();
	}

	function setDraftSetLabelChildren(includeChildren) {
		if (!state.draft) {
			return;
		}
		state.draft.setLabelChildren = !!includeChildren;
		afterDraftMutation();
	}

	function setDraftMediaAllowUpload(allow) {
		if (!state.draft) {
			return;
		}
		if (!state.draft.mediaConfig) {
			state.draft.mediaConfig = {
				allowUpload: true,
				allowUrl: false,
				allowedKinds: [],
			};
		}
		state.draft.mediaConfig.allowUpload = !!allow;
		if (!state.draft.mediaConfig.allowUpload && !state.draft.mediaConfig.allowUrl) {
			state.draft.mediaConfig.allowUrl = true;
		}
		afterDraftMutation();
	}

	function setDraftMediaAllowUrl(allow) {
		if (!state.draft) {
			return;
		}
		if (!state.draft.mediaConfig) {
			state.draft.mediaConfig = {
				allowUpload: true,
				allowUrl: false,
				allowedKinds: [],
			};
		}
		state.draft.mediaConfig.allowUrl = !!allow;
		if (!state.draft.mediaConfig.allowUpload && !state.draft.mediaConfig.allowUrl) {
			state.draft.mediaConfig.allowUpload = true;
		}
		afterDraftMutation();
	}

	function setDraftMediaAllowedKind(kind, enabled) {
		if (!state.draft) {
			return;
		}
		if (!state.draft.mediaConfig) {
			state.draft.mediaConfig = {
				allowUpload: true,
				allowUrl: false,
				allowedKinds: [],
			};
		}
		var key = String(kind || '').toLowerCase();
		var current = normalizeAllowedKinds(state.draft.mediaConfig.allowedKinds);
		var next;
		if (enabled) {
			next = current.indexOf(key) === -1 ? current.concat([key]) : current;
		} else {
			next = current.filter(function (k) {
				return k !== key;
			});
		}
		state.draft.mediaConfig.allowedKinds = normalizeAllowedKinds(next);
		afterDraftMutation();
	}

	function setDraftFixedEnabled(enabled) {
		if (!state.draft) {
			return;
		}
		state.draft.fixedEnabled = !!enabled;
		/* Keep literal/node while off so the grayed field still shows the last value. */
		if (
			state.draft.fixedEnabled &&
			supportsFixedLiteral(state.draft.type) &&
			typeKeyFromMember({ type: state.draft.type }) === 'bool'
		) {
			if (state.draft.fixedLiteral !== '1' && state.draft.fixedLiteral !== '0') {
				state.draft.fixedLiteral = '0';
			}
			state.draft.fixed = draftFixedDisplay(state.draft);
		}
		afterDraftMutation();
	}

	function refreshSettingsActionState() {
		var wrap = document.querySelector('.wtt-detail-toolbar');
		if (!wrap) {
			return;
		}
		var dirty = isSettingsDirty();
		var viaButton = saveViaButtonEnabled();
		wrap.classList.toggle('is-dirty', viaButton && dirty);
		var locked = !!state.settingsSaving;
		Array.prototype.forEach.call(wrap.querySelectorAll('[data-wtt-settings-action]'), function (btn) {
			btn.disabled = locked || !dirty;
		});
		var status = wrap.querySelector('.wtt-settings-status');
		if (!viaButton) {
			if (hintRemove(wrap, '.wtt-settings-unsaved')) {
				/* removed */
			}
			var statusText = '';
			if (state.autosaving) {
				statusText = i18n.settingsSaving || 'Savingâ€¦';
			} else if (!dirty && state.selectedId) {
				statusText = i18n.settingsSaved || 'Saved';
			}
			if (statusText) {
				if (!status) {
					var group = wrap.querySelector('.wtt-detail-toolbar__group--settings');
					if (group) {
						status = el('span', { className: 'wtt-settings-status', text: statusText });
						group.appendChild(status);
					}
				} else {
					status.textContent = statusText;
				}
			} else if (status && status.parentNode) {
				status.parentNode.removeChild(status);
			}
			return;
		}
		if (status && status.parentNode) {
			status.parentNode.removeChild(status);
		}
		var hint = wrap.querySelector('.wtt-settings-unsaved');
		if (dirty && i18n.settingsUnsavedHint) {
			if (!hint) {
				var settingsGroup = wrap.querySelector('.wtt-detail-toolbar__group--settings');
				if (settingsGroup) {
					settingsGroup.appendChild(
						el('span', {
							className: 'wtt-settings-unsaved',
							text: i18n.settingsUnsavedHint,
						})
					);
				}
			}
		} else if (hint) {
			hint.parentNode.removeChild(hint);
		}
	}

	function hintRemove(wrap, selector) {
		var node = wrap.querySelector(selector);
		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
			return true;
		}
		return false;
	}

	function scheduleAutosave() {
		if (saveViaButtonEnabled()) {
			return;
		}
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
		}
		autosaveTimer = window.setTimeout(function () {
			autosaveTimer = null;
			runAutosave();
		}, 450);
		refreshSettingsActionState();
	}

	function runAutosave() {
		if (saveViaButtonEnabled() || !state.selectedId || !state.draft) {
			return;
		}
		if (!isSettingsDirty()) {
			refreshSettingsActionState();
			return;
		}
		if (state.autosaving || state.settingsSaving) {
			scheduleAutosave();
			return;
		}
		saveNodeSettings({ autosave: true });
	}

	function afterDraftMutation(opts) {
		opts = opts || {};
		if (saveViaButtonEnabled()) {
			if (opts.silent) {
				refreshSettingsActionState();
			} else {
				render();
			}
			return;
		}
		if (opts.silent) {
			refreshSettingsActionState();
		} else {
			render();
		}
		scheduleAutosave();
	}

	function renderDetailToolbar(n, dirty, controlsLocked) {
		var viaButton = saveViaButtonEnabled();
		var bar = el('div', {
			className:
				'wtt-detail-toolbar' + (viaButton && dirty ? ' is-dirty' : ''),
		});

		var settings = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--settings',
		});
		if (viaButton) {
			var saveBtn = el('button', {
				type: 'button',
				className: 'button button-primary',
				text: i18n.saveSettings || 'Save settings',
				onClick: function () {
					saveNodeSettings();
				},
			});
			saveBtn.setAttribute('data-wtt-settings-action', 'save');
			var undoBtn = el('button', {
				type: 'button',
				className: 'button',
				text: i18n.undoSettings || 'Undo',
				onClick: undoNodeSettings,
			});
			undoBtn.setAttribute('data-wtt-settings-action', 'undo');
			if (controlsLocked || !dirty) {
				saveBtn.disabled = true;
				undoBtn.disabled = true;
			}
			settings.appendChild(saveBtn);
			settings.appendChild(undoBtn);
			if (dirty && i18n.settingsUnsavedHint) {
				settings.appendChild(
					el('span', {
						className: 'wtt-settings-unsaved',
						text: i18n.settingsUnsavedHint,
					})
				);
			}
		} else {
			var statusText = '';
			if (state.autosaving) {
				statusText = i18n.settingsSaving || 'Savingâ€¦';
			} else if (state.selectedId && !dirty) {
				statusText = i18n.settingsSaved || 'Saved';
			}
			if (statusText) {
				settings.appendChild(
					el('span', {
						className: 'wtt-settings-status',
						text: statusText,
					})
				);
			}
		}
		bar.appendChild(settings);

		var structure = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--structure',
		});
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.addChild,
				onClick: function () {
					createTerm(n.id);
				},
			})
		);
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.copy || 'Copy',
				title: i18n.copyHint || 'Copy selection (Ctrl+C). Child links only if both ends are selected.',
				onClick: copySelected,
			})
		);
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.reparent || 'Reparent',
				onClick: function () {
					showReparentDialog(n.id);
				},
			})
		);
		if (n.hidden && !n.isHiddenBin) {
			structure.appendChild(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.unhideNode || 'Show again',
					title:
						i18n.unhideNodeHint ||
						'Restore this node to the tree under its parent.',
					onClick: function () {
						unhideNodeById(n.id);
					},
				})
			);
		} else if (!n.isTrash && !n.trashed && !n.isHiddenBin && !n.hidden) {
			structure.appendChild(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.hideNode || 'Hide node',
					title:
						i18n.hideNodeHint ||
						'Keep this node but hide it from the tree. Find it again under Hidden nodes.',
					onClick: function () {
						hideNodeById(n.id);
					},
				})
			);
		}
		bar.appendChild(structure);

		var danger = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--danger',
		});
		var canDeleteDetail = n.deletable !== false;
		var hasKids = !!(n.hasChildren || (n.children && n.children.length));
		danger.appendChild(
			el('button', {
				type: 'button',
				className: 'button button-link-delete wtt-detail-toolbar__trash',
				title: canDeleteDetail
					? i18n.deleteNodeHint ||
					  'Delete this node only (children move up)'
					: i18n.notDeletable || 'This system or catalog type cannot be deleted.',
				'aria-label': canDeleteDetail
					? i18n.deleteNode || 'Delete node'
					: i18n.notDeletable || 'This system or catalog type cannot be deleted.',
				disabled: !canDeleteDetail,
				html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
				onClick: function () {
					deleteSelected('node');
				},
			})
		);
		if (hasKids) {
			danger.appendChild(
				el('button', {
					type: 'button',
					className:
						'button button-link-delete wtt-detail-toolbar__trash wtt-detail-toolbar__trash--branch',
					title: canDeleteDetail
						? i18n.deleteBranchHint ||
						  'Delete this node and its entire branch'
						: i18n.notDeletable ||
						  'This system or catalog type cannot be deleted.',
					'aria-label': canDeleteDetail
						? i18n.deleteBranch || 'Delete branch'
						: i18n.notDeletable ||
						  'This system or catalog type cannot be deleted.',
					disabled: !canDeleteDetail,
					html: '<span class="dashicons dashicons-networking" aria-hidden="true"></span>',
					onClick: function () {
						deleteSelected('branch');
					},
				})
			);
		}
		bar.appendChild(danger);

		return bar;
	}

	function setDraftFixedLiteral(value, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.fixedEnabled = true;
		state.draft.fixedLiteral = value != null ? String(value) : '';
		state.draft.fixedNodeId = 0;
		state.draft.fixed = draftFixedDisplay(state.draft);
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftFixed(fixedNodeId) {
		if (!state.draft || !state.selectedNode) {
			return;
		}
		fixedNodeId = fixedNodeId || 0;
		state.draft.fixedEnabled = fixedNodeId > 0;
		state.draft.fixedNodeId = fixedNodeId;
		state.draft.fixedLiteral = '';
		state.draft.fixed =
			resolveFixedFromOptions(fixedNodeId, state.selectedNode.fixedOptions) ||
			(function () {
				if (!fixedNodeId) {
					return null;
				}
				var node = findNodeInTree(state.tree, fixedNodeId);
				return node
					? { id: node.id, name: node.name || String(fixedNodeId), path: node.name || '' }
					: { id: fixedNodeId, name: String(fixedNodeId) };
			})();
		afterDraftMutation();
	}

	function setDraftRefScope(scopeId) {
		if (!state.draft) {
			return;
		}
		var next = scopeId || 0;
		var prev = parseInt(state.draft.refScopeId, 10) || 0;
		state.draft.refScopeId = next;
		if (next !== prev) {
			/* New catalog root â†’ reset allowlist (default = all children). */
			state.draft.allowedRefIds = [];
		}
		afterDraftMutation();
	}

	function setDraftFieldMultiplicity(mult) {
		if (!state.draft) {
			return;
		}
		state.draft.fieldMultiplicity = String(mult || '0..1');
		afterDraftMutation();
	}

	function setDraftAllowedRefIds(ids) {
		if (!state.draft) {
			return;
		}
		var clean = [];
		(ids || []).forEach(function (id) {
			id = parseInt(id, 10) || 0;
			if (id > 0) {
				clean.push(id);
			}
		});
		state.draft.allowedRefIds = clean;
		afterDraftMutation();
	}

	function flattenTreeOptions(nodes, depth, out, excludeId) {
		out = out || [];
		depth = depth || 0;
		(nodes || []).forEach(function (n) {
			if (!n || n.id == null) {
				return;
			}
			if (!excludeId || String(n.id) !== String(excludeId)) {
				var pad = depth > 0 ? new Array(depth + 1).join('\u00a0\u00a0') : '';
				out.push({
					id: n.id,
					name: pad + (n.name || String(n.id)),
					rawName: n.name || String(n.id),
					shortDescription: n.shortDescription || '',
				});
			}
			if (n.children && n.children.length) {
				flattenTreeOptions(n.children, depth + 1, out, excludeId);
			}
		});
		return out;
	}

	function typeUsesRefScope(typeOrMember) {
		var key =
			typeOrMember && typeOrMember.type
				? typeKeyFromMember(typeOrMember)
				: typeKeyFromMember({ type: typeOrMember });
		return key === 'node_embed' || key === 'node_ref' || key === 'node_pick';
	}

	function typeIsNodeEmbed(typeOrMember) {
		var key =
			typeOrMember && typeOrMember.type
				? typeKeyFromMember(typeOrMember)
				: typeKeyFromMember({ type: typeOrMember });
		return key === 'node_embed';
	}

	function isUnderTypenBranch(termId) {
		termId = parseInt(termId, 10) || 0;
		if (!termId) {
			return false;
		}
		var cur = findNodeInTree(state.tree, termId);
		var guard = 0;
		while (cur && guard++ < 64) {
			if (String(cur.name || '') === 'Typen') {
				return true;
			}
			var parentId = parseInt(cur.parent, 10) || 0;
			if (!parentId) {
				return false;
			}
			cur = findNodeInTree(state.tree, parentId);
		}
		return false;
	}

	/** Relationstypen folder (Relation::ROOT_NAME) or any descendant â€” no Preview panel. */
	function isUnderRelationstypenBranch(termId) {
		termId = parseInt(termId, 10) || 0;
		if (!termId) {
			return false;
		}
		var cur = findNodeInTree(state.tree, termId);
		var guard = 0;
		while (cur && guard++ < 64) {
			if (String(cur.name || '') === 'Relationstypen') {
				return true;
			}
			var parentId = parseInt(cur.parent, 10) || 0;
			if (!parentId) {
				return false;
			}
			cur = findNodeInTree(state.tree, parentId);
		}
		return false;
	}

	/** Direct children of catalog root â€” `node_embed` pick list. */
	function nodeEmbedPickRoots(scopeId, allowedIds) {
		scopeId = parseInt(scopeId, 10) || 0;
		if (!scopeId) {
			return [];
		}
		var root = findNodeInTree(state.tree, scopeId);
		if (!root) {
			return [];
		}
		var kids = root.children || [];
		var allowMap = allowedRefIdMap(allowedIds);
		if (!allowMap) {
			return kids;
		}
		return kids.filter(function (c) {
			return c && allowMap[String(c.id)];
		});
	}

	/** Descendants under catalog root â€” `node_ref` pick roots (full subtree). */
	function nodeRefPickRoots(scopeId) {
		scopeId = parseInt(scopeId, 10) || 0;
		if (!scopeId) {
			return [];
		}
		var root = findNodeInTree(state.tree, scopeId);
		if (!root) {
			return [];
		}
		return root.children || [];
	}

	/** null = all allowed; else map of allowed direct-child ids. */
	function allowedRefIdMap(allowedIds) {
		if (!allowedIds || !allowedIds.length) {
			return null;
		}
		var map = {};
		allowedIds.forEach(function (id) {
			id = parseInt(id, 10) || 0;
			if (id > 0) {
				map[String(id)] = true;
			}
		});
		return Object.keys(map).length ? map : null;
	}

	/**
	 * node_ref: allowlisted catalog children + their descendants (Q73).
	 * Empty allowlist = everything under scope.
	 */
	function isAllowedRefCandidate(node, scopeId, allowedIds) {
		var allowMap = allowedRefIdMap(allowedIds);
		if (!allowMap) {
			return true;
		}
		var cur = node;
		var guard = 0;
		while (cur && guard++ < 64) {
			if (allowMap[String(cur.id)]) {
				return true;
			}
			var pid = parseInt(cur.parent, 10) || 0;
			if (!pid || pid === parseInt(scopeId, 10)) {
				return false;
			}
			cur = findNodeInTree(state.tree, pid);
		}
		return false;
	}

	function catalogChildCandidates(scopeId) {
		scopeId = parseInt(scopeId, 10) || 0;
		if (!scopeId) {
			return [];
		}
		var root = findNodeInTree(state.tree, scopeId);
		return root && root.children ? root.children : [];
	}

	function findNamedInTree(nodes, name) {
		name = String(name || '');
		var found = null;
		(nodes || []).some(function (node) {
			if (!node) {
				return false;
			}
			if (String(node.name || '') === name) {
				found = node;
				return true;
			}
			if (node.children && node.children.length) {
				found = findNamedInTree(node.children, name);
				return !!found;
			}
			return false;
		});
		return found;
	}

	function typenPickerRoots() {
		var typen = findNamedInTree(state.tree, 'Typen');
		return typen ? [typen] : state.tree;
	}

	function datatypePickerRoots(n) {
		if (n && Array.isArray(n.datatypeTree) && n.datatypeTree.length) {
			state.datatypeTreeCache = n.datatypeTree;
			return n.datatypeTree;
		}
		if (
			state.selectedNode &&
			Array.isArray(state.selectedNode.datatypeTree) &&
			state.selectedNode.datatypeTree.length
		) {
			state.datatypeTreeCache = state.selectedNode.datatypeTree;
			return state.selectedNode.datatypeTree;
		}
		if (Array.isArray(state.datatypeTreeCache) && state.datatypeTreeCache.length) {
			return state.datatypeTreeCache;
		}
		return filterDatatypeForest(state.tree);
	}

	/**
	 * Resolve a catalog node by binding id, then by name (fallback).
	 * @param {number} id
	 * @param {Array} forest
	 * @return {object|null}
	 */
	function findCatalogNodeById(id, forest) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return null;
		}
		return (
			findNodeInTree(forest || [], id) ||
			findNodeInTree(state.tree, id) ||
			null
		);
	}

	/**
	 * Attribute type chooser: two bindings â€” branch root (ast) + focus node.
	 * Example Fallstudie: root=Fallstudie (full tree), focus=Data Types.
	 * @return {Array}
	 */
	function attributeTypePickerRoots(n) {
		var bindings = cfg.catalogBindings || {};
		var rootId = parseInt(bindings.chooser_root, 10) || 0;
		var root = findCatalogNodeById(rootId, state.tree);
		if (!root) {
			root = findNamedInTree(state.tree, 'Fallstudie');
		}
		if (root) {
			return [root];
		}
		/* Fallback: whole admin tree. */
		return Array.isArray(state.tree) && state.tree.length
			? state.tree
			: datatypePickerRoots(n);
	}

	/**
	 * Fallback focus for attribute type chooser when the picker has no own focus.
	 * Reads catalog binding chooser_focus (legacy data_types). Object View / Model
	 * table must NOT use this - they pass modelId as focusId instead.
	 * @return {number}
	 */
	function attributeTypeChooserFocusId(n) {
		var bindings = cfg.catalogBindings || {};
		var focusId =
			parseInt(bindings.chooser_focus, 10) ||
			parseInt(bindings.data_types, 10) ||
			0;
		var roots = attributeTypePickerRoots(n);
		if (focusId > 0) {
			if (
				findNodeInTree(roots, focusId) ||
				findCatalogNodeById(focusId, state.tree)
			) {
				return focusId;
			}
		}
		var hit =
			findNamedInTree(roots, 'Data Types') ||
			findNamedInTree(roots, 'Datentypen') ||
			findNamedInTree(state.tree, 'Data Types') ||
			findNamedInTree(state.tree, 'Datentypen');
		return hit && hit.id ? parseInt(hit.id, 10) || 0 : 0;
	}

	function filterDatatypeForest(nodes) {
		/* Chooser forest = nodes under type anchors (Q92); no is_datatype flag. */
		function clone(list) {
			return (list || [])
				.filter(function (node) {
					return !!node;
				})
				.map(function (node) {
					var kids = clone(node.children || []);
					return Object.assign({}, node, {
						children: kids,
						hasChildren: kids.length > 0 || !!node.hasChildren,
					});
				});
		}
		return clone(nodes);
	}

	function assignableTypeIds(typeOptions) {
		var map = {};
		(typeOptions || []).forEach(function (opt) {
			if (opt && opt.id != null) {
				map[String(opt.id)] = true;
			}
		});
		return map;
	}

	function catalogFixedPickerRoots(n, key) {
		var scopeId = parseInt(n && n.refScopeId, 10) || 0;
		var allowed = (n && n.allowedRefIds) || [];
		if (scopeId > 0) {
			if (key === 'node_embed') {
				return nodeEmbedPickRoots(scopeId, allowed);
			}
			var scopeNode = findNodeInTree(state.tree, scopeId);
			return scopeNode ? [scopeNode] : nodeRefPickRoots(scopeId);
		}
		return state.tree;
	}

	function resolveRefPickLabel(member, raw) {
		var id = parseInt(raw, 10) || 0;
		if (!id) {
			return raw && String(raw) !== '' ? String(raw) : 'â€”';
		}
		var node = findNodeInTree(state.tree, id);
		if (node && node.name) {
			return node.name;
		}
		return String(id);
	}

	function treeNodeToPreviewMember(node) {
		if (!node) {
			return null;
		}
		return {
			id: node.id,
			name: node.name || '',
			displayName: node.name || '',
			description: node.description || '',
			type: node.type || (node.typeLabel ? { name: node.typeLabel } : { name: 'text' }),
			required: !!node.required,
			refScopeId: node.refScopeId || 0,
			allowedRefIds: Array.isArray(node.allowedRefIds) ? node.allowedRefIds.slice() : [],
			fixed: node.fixed || null,
			fixedLiteral: node.fixedLiteral || '',
			typeBranch: node.typeBranch || null,
			quantitySchema: node.quantitySchema || null,
			mediaConfig: node.mediaConfig || null,
		};
	}

	function previewMemberScope(member, optsScope) {
		if (optsScope != null && String(optsScope) !== '') {
			return optsScope;
		}
		if (member && member.id != null) {
			return member.id;
		}
		return member && member.name;
	}

	function findPickedRefId(members) {
		var list = members || [];
		var sid = String(state.selectedId || 0);
		for (var i = 0; i < list.length; i++) {
			var member = list[i];
			if (!typeIsNodeEmbed(member)) {
				continue;
			}

			var remembered = readEmbedPick(member);
			if (remembered > 0) {
				return remembered;
			}

			var fixedId =
				parseInt(member.fixedNodeId, 10) ||
				(member.fixed && member.fixed.id != null ? parseInt(member.fixed.id, 10) : 0) ||
				0;
			if (fixedId > 0) {
				return fixedId;
			}

			var scopes = [];
			var primary = previewMemberScope(member);
			scopes.push(primary);
			if (member && member.id != null && member.name) {
				scopes.push(member.name);
			}
			for (var s = 0; s < scopes.length; s++) {
				var raw = getPreviewValue(scopes[s], member, '');
				var id = parseInt(raw, 10) || 0;
				if (id > 0) {
					return id;
				}
			}

			/* Scan all preview keys for this selected node + member (scope mismatches). */
			var needle =
				member && member.id != null
					? '|id:' + member.id
					: '|name:' + String((member && member.name) || 'field');
			var keys = Object.keys(state.previewValues || {});
			for (var k = 0; k < keys.length; k++) {
				var key = keys[k];
				if (key.indexOf(sid + '|') !== 0 || key.indexOf(needle) === -1) {
					continue;
				}
				var scanned = parseInt(state.previewValues[key], 10) || 0;
				if (scanned > 0) {
					return scanned;
				}
			}
		}
		return 0;
	}

	function ensureDynamicRefLoaded(pickedId) {
		pickedId = parseInt(pickedId, 10) || 0;
		if (!pickedId) {
			return;
		}
		if (state.dynamicRefCache[pickedId] || state.dynamicRefLoading === pickedId) {
			return;
		}
		state.dynamicRefLoading = pickedId;
		post('wtt_get_node', { term_id: pickedId })
			.then(function (json) {
				if (state.dynamicRefLoading !== pickedId) {
					return;
				}
				state.dynamicRefLoading = 0;
				if (json && json.success && json.data) {
					state.dynamicRefCache[pickedId] = json.data;
					renderKeepingPreviewChrome();
				}
			})
			.catch(function () {
				if (state.dynamicRefLoading === pickedId) {
					state.dynamicRefLoading = 0;
				}
			});
	}

	function resolveEmbedPickedId(member, scope, sample) {
		var currentVal = parseInt(getPreviewValue(scope, member, ''), 10) || 0;
		if (!currentVal) {
			currentVal = readEmbedPick(member);
		}
		if (!currentVal) {
			currentVal =
				parseInt(member.fixedNodeId, 10) ||
				(member.fixed && member.fixed.id != null
					? parseInt(member.fixed.id, 10)
					: 0) ||
				0;
		}
		if (
			!currentVal &&
			sample &&
			sample !== 'â€”' &&
			sample !== '' &&
			/^\d+$/.test(String(sample))
		) {
			currentVal = parseInt(sample, 10) || 0;
		}
		return currentVal;
	}

	function collectEmbedMembers(pickedId) {
		pickedId = parseInt(pickedId, 10) || 0;
		if (!pickedId) {
			return { members: [], isSet: false, titleName: '', cached: null, treeNode: null };
		}
		ensureDynamicRefLoaded(pickedId);
		var cached = state.dynamicRefCache[pickedId] || null;
		var treeNode = findNodeInTree(state.tree, pickedId);
		var titleName =
			(cached && cached.name) || (treeNode && treeNode.name) || String(pickedId);
		var members = [];
		var isSet = false;
		if (cached && cached.isSet && Array.isArray(cached.setMembers) && cached.setMembers.length) {
			members = cached.setMembers;
			isSet = true;
		} else if (cached && Array.isArray(cached.setMembers) && cached.setMembers.length) {
			members = cached.setMembers;
		} else {
			var children = (treeNode && treeNode.children) || [];
			members = children
				.map(treeNodeToPreviewMember)
				.filter(function (m) {
					return !!m;
				});
			isSet =
				(treeNode && typeNameIs(treeNode.type, 'set')) ||
				String((treeNode && treeNode.typeLabel) || '')
					.trim()
					.toLowerCase() === 'set';
		}
		return {
			members: members,
			isSet: isSet,
			titleName: titleName,
			cached: cached,
			treeNode: treeNode,
			loading: !cached && state.dynamicRefLoading === pickedId,
		};
	}

	/**
	 * Catalog picks first, then interactive scalars; bools and fixed/statics trail.
	 */
	function membersPickFirst(members) {
		var parts = partitionSetMembers(members);
		return parts.primary.concat(parts.bools, parts.statics);
	}

	/**
	 * Split set members for compact layout:
	 * primary (picks + inputs) â†’ bools â†’ static/fixed (wrap groups).
	 */
	function partitionSetMembers(members) {
		var picks = [];
		var main = [];
		var bools = [];
		var statics = [];
		(members || []).forEach(function (m) {
			if (!m) {
				return;
			}
			var key = typeKeyFromMember(m);
			var fixedCat = !!(m.fixed && m.fixed.name);
			var fixedLit =
				m.fixedLiteral != null && String(m.fixedLiteral) !== '' && !fixedCat;
			if (key === 'node_embed' || key === 'node_ref') {
				picks.push(m);
				return;
			}
			if (key === 'bool') {
				bools.push(m);
				return;
			}
			if (isNodePresentationTypeKey(key) || fixedCat || fixedLit) {
				statics.push(m);
				return;
			}
			main.push(m);
		});
		return {
			primary: picks.concat(main),
			bools: bools,
			statics: statics,
		};
	}

	function appendSetSep(host, separator) {
		host.appendChild(
			el('span', {
				className: 'wtt-set-preview__sep',
				text: separator,
				'aria-hidden': 'true',
			})
		);
	}

	function renderSetMemberStrip(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var separator = opts.separator != null ? String(opts.separator) : '/';
		var showPartLabels = opts.showPartLabels !== false;
		var strip = el('div', {
			className: 'wtt-set-preview__strip ' + (opts.className || ''),
		});
		(members || []).forEach(function (member, index) {
			if (index > 0) {
				appendSetSep(strip, separator);
			}
			var part = el('span', { className: 'wtt-set-preview__cell-part' });
			var key = typeKeyFromMember(member);
			var showLabel =
				showPartLabels &&
				!(member.fixed && member.fixed.name) &&
				key !== 'praefixe';
			if (showLabel && member.name) {
				part.appendChild(
					el('span', {
						className: 'wtt-set-preview__cell-label',
						text: member.name,
					})
				);
			}
			part.appendChild(renderFieldView(member, { compact: true, mode: mode }));
			strip.appendChild(part);
		});
		return strip;
	}

	function renderSetBoolStrip(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var strip = el('div', {
			className: 'wtt-set-preview__strip wtt-set-preview__strip--bools',
		});
		(members || []).forEach(function (member) {
			var item = el('label', {
				className: 'wtt-set-preview__bool-item',
				title: member.name || '',
			});
			var scope =
				member && (member.id != null ? member.id : member.name);
			var sample = livePreviewText(scope, member);
			var on =
				sample === '1' ||
				sample === 'true' ||
				sample === (i18n.boolTrue || 'true');
			if (mode === 'display') {
				item.appendChild(
					el('span', {
						className: 'wtt-set-preview__bool-mark',
						text: on ? 'â˜‘' : 'â˜',
						'aria-hidden': 'true',
					})
				);
				item.appendChild(
					document.createTextNode(
						' ' + (member.name || (on ? i18n.boolTrue || 'true' : i18n.boolFalse || 'false'))
					)
				);
			} else {
				var box = el('input', {
					type: 'checkbox',
					className: 'wtt-preview-check',
					checked: on ? 'checked' : undefined,
				});
				bindPreviewControl(box, scope, member, {
					event: 'change',
					readValue: function (el) {
						return el.checked ? '1' : '0';
					},
				});
				item.appendChild(box);
				item.appendChild(document.createTextNode(' ' + (member.name || '')));
			}
			strip.appendChild(item);
		});
		return strip;
	}

	function renderSetStaticStrip(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var strip = el('div', {
			className: 'wtt-set-preview__strip wtt-set-preview__strip--static',
		});
		(members || []).forEach(function (member) {
			var parts = fieldDisplayParts(member, null);
			var name = member.name ? String(member.name) : '';
			var text =
				(member.fixed && member.fixed.name) ||
				(member.fixedLiteral != null && String(member.fixedLiteral) !== ''
					? String(member.fixedLiteral)
					: '') ||
				(parts && parts.full) ||
				'â€”';
			var item = el('span', {
				className: 'wtt-set-preview__static-item',
				title: name ? name + ': ' + text : text,
			});
			if (name) {
				item.appendChild(
					el('span', {
						className: 'wtt-set-preview__static-label',
						text: name,
					})
				);
			}
			item.appendChild(
				el('span', {
					className: 'wtt-set-preview__static-value',
					text: text,
				})
			);
			strip.appendChild(item);
			/* Keep edit path wired for consistency (static still read-only). */
			if (mode === 'edit') {
				/* no interactive control â€” value is schema-fixed */
			}
		});
		return strip;
	}

	/**
	 * Embedded property fields of a picked catalog node â€” same row as the picker.
	 */
	function renderEmbeddedRefFields(pickedId, mode, opts) {
		opts = opts || {};
		mode = mode === 'display' ? 'display' : 'edit';
		var host = el('div', {
			className:
				'wtt-preview-embed__fields' +
				(opts.compact ? ' wtt-preview-embed__fields--compact' : ''),
		});
		pickedId = parseInt(pickedId, 10) || 0;
		if (!pickedId) {
			return host;
		}
		var info = collectEmbedMembers(pickedId);
		if (info.loading) {
			host.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text: i18n.loading || 'Loadingâ€¦',
				})
			);
			return host;
		}
		if (!info.members.length) {
			host.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text: i18n.dynamicRefEmpty || 'Selected node has no child fields.',
				})
			);
			return host;
		}
		var separator = (info.cached && info.cached.setSeparator) || '/';
		var joinUnits = info.cached ? info.cached.setJoinUnits !== false : true;
		var ordered = membersPickFirst(info.members);
		if (ordered.length > 1) {
			host.appendChild(
				renderSetTableCell(ordered, {
					mode: mode,
					showPartLabels: false,
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		} else {
			host.appendChild(
				renderFieldView(ordered[0], { compact: true, mode: mode })
			);
		}
		return host;
	}

	function wrapNodeEmbedControl(pickControl, pickedId, mode, opts) {
		opts = opts || {};
		var wrap = el('div', {
			className:
				'wtt-preview-embed' + (opts.compact ? ' wtt-preview-embed--compact' : ''),
		});
		var pickHost = el('div', { className: 'wtt-preview-embed__pick' });
		pickHost.appendChild(pickControl);
		wrap.appendChild(pickHost);
		if (pickedId) {
			wrap.appendChild(renderEmbeddedRefFields(pickedId, mode, opts));
		}
		return wrap;
	}

	/** @deprecated Separate panel removed â€” embed fields render inline with the picker. */
	function withDynamicRefFields(baseNode) {
		return baseNode;
	}

	function draftPrefixBranch() {
		if (!state.draft) {
			return null;
		}
		if (state.draft.prefixBranch && state.draft.prefixBranch.unitAllowlistEdit) {
			return state.draft.prefixBranch;
		}
		if (state.draft.typeBranch && state.draft.typeBranch.unitAllowlistEdit) {
			return state.draft.typeBranch;
		}
		return null;
	}

	function formatFactor(value) {
		var n = Number(value);
		if (!isFinite(n)) {
			return '';
		}
		if (n === 0) {
			return '0';
		}
		var abs = Math.abs(n);
		if (abs >= 1e6 || (abs > 0 && abs < 1e-6)) {
			return n.toExponential(0).replace(/e\+?/, 'e');
		}
		var s = n.toPrecision(12);
		if (s.indexOf('e') !== -1 || s.indexOf('E') !== -1) {
			return s;
		}
		if (s.indexOf('.') !== -1) {
			s = s.replace(/\.?0+$/, '');
		}
		return s;
	}

	function setDraftBranchChild(childId, enabled) {
		var branch = draftPrefixBranch();
		if (!branch || !Array.isArray(branch.children)) {
			if (!state.draft || !state.draft.typeBranch || !Array.isArray(state.draft.typeBranch.children)) {
				return;
			}
			branch = state.draft.typeBranch;
		}
		branch.children.forEach(function (child) {
			if (child && String(child.id) === String(childId)) {
				child.enabled = !!enabled;
			}
		});
		afterDraftMutation();
	}

	function setDraftBranchMultiplikator(childId, value) {
		var branch = draftPrefixBranch();
		if (!branch || !Array.isArray(branch.children)) {
			return;
		}
		var parsed = parseFloat(String(value).replace(',', '.'));
		branch.children.forEach(function (child) {
			if (child && String(child.id) === String(childId)) {
				child.multiplikator = isFinite(parsed) && parsed > 0 ? parsed : null;
			}
		});
		afterDraftMutation({ silent: true });
	}

	function setDraftUnitPrefixRootToSi(value) {
		var branch = draftPrefixBranch();
		if (!branch) {
			return;
		}
		var parsed = parseFloat(String(value).replace(',', '.'));
		branch.unitPrefixRootToSi = isFinite(parsed) && parsed > 0 ? parsed : 1;
		afterDraftMutation({ silent: true });
	}

	function prefixMultiplikatorsFromDraft(draft) {
		var map = {};
		if (!draft) {
			return map;
		}
		var branch =
			draft.prefixBranch && draft.prefixBranch.unitAllowlistEdit
				? draft.prefixBranch
				: draft.typeBranch && draft.typeBranch.unitAllowlistEdit
					? draft.typeBranch
					: null;
		if (!branch || !Array.isArray(branch.children)) {
			return map;
		}
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var m = child.multiplikator;
			if (m != null && isFinite(Number(m)) && Number(m) > 0) {
				map[String(child.id)] = Number(m);
			}
		});
		return map;
	}

	function undoNodeSettings() {
		if (!saveViaButtonEnabled() || !state.savedDraft) {
			return;
		}
		state.draft = deepClone(state.savedDraft);
		state.error = '';
		render();
	}

	function flushPendingNodeSettings() {
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
			autosaveTimer = null;
		}
		if (!state.selectedId || !state.draft || !isSettingsDirty()) {
			return Promise.resolve();
		}
		/* Snapshot now â€” selectNode clears draft after this promise. */
		return saveNodeSettings({
			autosave: true,
			force: true,
			termId: state.selectedId,
			draft: deepClone(state.draft),
		});
	}

	/**
	 * Queue a settings save. Concurrent calls coalesce to the latest draft per term.
	 *
	 * @param {{autosave?:boolean,force?:boolean,termId?:number,draft?:object}} opts
	 * @return {Promise<void>}
	 */
	function saveNodeSettings(opts) {
		opts = opts || {};
		var autosave = !!opts.autosave;
		var force = !!opts.force;
		var termId = opts.termId != null ? parseInt(opts.termId, 10) || 0 : state.selectedId;
		var payloadDraft = opts.draft ? deepClone(opts.draft) : state.draft ? deepClone(state.draft) : null;
		if (!termId || !payloadDraft) {
			return Promise.resolve();
		}
		if (autosave && saveViaButtonEnabled() && !force) {
			return Promise.resolve();
		}
		if (!autosave && !saveViaButtonEnabled() && !force) {
			return Promise.resolve();
		}
		if (!opts.draft && !isSettingsDirty() && autosave && !force) {
			refreshSettingsActionState();
			return Promise.resolve();
		}

		settingsSavePending[String(termId)] = {
			termId: termId,
			draft: payloadDraft,
			autosave: autosave,
			force: force,
		};
		autosaveSeq += 1;

		settingsSaveChain = settingsSaveChain
			.catch(function () {
				/* Keep the chain alive after a failed save. */
			})
			.then(function () {
				return drainSettingsSaves();
			});

		return settingsSaveChain;
	}

	function drainSettingsSaves() {
		var keys = Object.keys(settingsSavePending);
		if (!keys.length) {
			return Promise.resolve();
		}
		var key = keys[0];
		var job = settingsSavePending[key];
		delete settingsSavePending[key];
		if (!job) {
			return drainSettingsSaves();
		}
		return postNodeSettingsSave(job.termId, job.draft, {
			autosave: job.autosave,
			force: job.force,
		}).then(function () {
			return drainSettingsSaves();
		});
	}

	function buildSettingsSavePayload(termId, payloadDraft) {
		var savePayload = {
			term_id: termId,
			name: payloadDraft.name || '',
			description: payloadDraft.description || '',
			short_description: payloadDraft.shortDescription || '',
			icon: payloadDraft.icon != null ? String(payloadDraft.icon) : '',
			type_id:
				payloadDraft.canInheritType && !payloadDraft.typeOverride
					? payloadDraft.ownTypeId || 0
					: payloadDraft.typeId || 0,
			type_inheriting: payloadDraft.typeInheriting ? '1' : '0',
			type_override: payloadDraft.typeOverride ? '1' : '0',
			required: payloadDraft.required ? '1' : '0',
			has_footer: payloadDraft.hasFooter ? '1' : '0',
			set_separator: payloadDraft.setSeparator != null ? String(payloadDraft.setSeparator) : '/',
			set_join_units: payloadDraft.setJoinUnits !== false ? '1' : '0',
			set_label_children: payloadDraft.setLabelChildren !== false ? '1' : '0',
			fixed_enabled:
				payloadDraft.isAttributeSlot || payloadDraft.isModelDataHost
					? '0'
					: payloadDraft.fixedEnabled
						? '1'
						: '0',
			fixed_literal: payloadDraft.fixedLiteral || '',
			fixed_node_id: payloadDraft.fixedNodeId || 0,
			readonly: payloadDraft.readonly ? '1' : '0',
			ref_scope_id: payloadDraft.refScopeId || 0,
			field_multiplicity:
				payloadDraft.fieldMultiplicity != null
					? String(payloadDraft.fieldMultiplicity)
					: '0..1',
			allowed_ref_ids: JSON.stringify(
				Array.isArray(payloadDraft.allowedRefIds) ? payloadDraft.allowedRefIds : []
			),
			disabled_branch_ids: JSON.stringify(disabledBranchIdsFromDraft(payloadDraft)),
			prefix_multiplikators: JSON.stringify(prefixMultiplikatorsFromDraft(payloadDraft)),
		};
		/* is_template: only send when Development mode (server also enforces). */
		if (isDevelopmentMode()) {
			savePayload.is_template = payloadDraft.isTemplate ? '1' : '0';
		}
		if (payloadDraft.fussFieldContext) {
			savePayload.footer_op = payloadDraft.footerOp != null ? String(payloadDraft.footerOp) : '';
		}
		if (
			payloadDraft.isTableTypeCatalog ||
			(String(payloadDraft.name || '').toLowerCase() === 'table')
		) {
			savePayload.type_props = JSON.stringify(
				Array.isArray(payloadDraft.typeProps) ? payloadDraft.typeProps : []
			);
		}
		/* Always send bindings for table nodes (and whenever draft already has any). */
		if (
			payloadDraft.isTable ||
			payloadDraft.isTableTypeCatalog ||
			(payloadDraft.propBindings && typeof payloadDraft.propBindings === 'object')
		) {
			savePayload.prop_bindings = JSON.stringify(normalizePropBindings(payloadDraft.propBindings));
		}
		if (payloadDraft.mediaConfig) {
			savePayload.media_allow_upload =
				payloadDraft.mediaConfig.allowUpload !== false ? '1' : '0';
			savePayload.media_allow_url = payloadDraft.mediaConfig.allowUrl ? '1' : '0';
			savePayload.media_allowed_kinds = JSON.stringify(
				normalizeAllowedKinds(payloadDraft.mediaConfig.allowedKinds)
			);
		}
		if (payloadDraft.dateConfig) {
			savePayload.date_mode =
				payloadDraft.dateConfig.mode === 'datetime' ? 'datetime' : 'date';
		}
		if (payloadDraft.textareaConfig) {
			savePayload.textarea_cols = String(
				parseInt(payloadDraft.textareaConfig.cols, 10) || 40
			);
			savePayload.textarea_rows = String(
				parseInt(payloadDraft.textareaConfig.rows, 10) || 4
			);
		}
		if (payloadDraft.presentationConfig) {
			savePayload.presentation_context = normalizePresentationContext(
				payloadDraft.presentationConfig.context
			);
		}
		var preferredConverter = normalizePreferredConverter(
			payloadDraft.preferredConverter ||
				(payloadDraft.intConfig && payloadDraft.intConfig.displayFormat) ||
				''
		);
		if (preferredConverter) {
			savePayload.preferred_converter = preferredConverter;
		}
		savePayload.validators = JSON.stringify(
			normalizeValidatorsList(payloadDraft.validators, payloadDraft)
		);
		if (payloadDraft.preferredRenderInherit) {
			/* Keep meta empty — heir follows father Preferred (Q66-style). */
			savePayload.preferred_render = 'inherit';
		} else {
			savePayload.preferred_render = normalizePreferredRender(
				payloadDraft.preferredRender
			);
		}
		var prefixBranch =
			payloadDraft.prefixBranch && payloadDraft.prefixBranch.unitAllowlistEdit
				? payloadDraft.prefixBranch
				: payloadDraft.typeBranch && payloadDraft.typeBranch.unitAllowlistEdit
					? payloadDraft.typeBranch
					: null;
		if (prefixBranch && prefixBranch.unitPrefixRootToSi != null) {
			savePayload.prefix_root_to_si = String(prefixBranch.unitPrefixRootToSi);
		}
		return savePayload;
	}

	function postNodeSettingsSave(termId, payloadDraft, opts) {
		opts = opts || {};
		var autosave = !!opts.autosave;
		var seq = autosaveSeq;

		if (autosave) {
			state.autosaving = true;
			refreshSettingsActionState();
		} else {
			state.settingsSaving = true;
			state.error = '';
			render();
		}

		return post('wtt_save_node_settings', buildSettingsSavePayload(termId, payloadDraft))
			.then(function (json) {
				if (!json || !json.success) {
					if (autosave) {
						state.autosaving = false;
					} else {
						state.settingsSaving = false;
					}
					setError((json && json.data && json.data.message) || i18n.error);
					return null;
				}
				return savePresentationDraft(termId, payloadDraft)
					.then(function () {
						return json;
					})
					.catch(function (err) {
						if (autosave) {
							state.autosaving = false;
						} else {
							state.settingsSaving = false;
						}
						setError(
							(err && err.message) ||
								i18n.presentationFoldError ||
								i18n.error
						);
						return null;
					});
			})
			.then(function (json) {
				if (!json) {
					return;
				}
				if (autosave) {
					state.autosaving = false;
				} else {
					state.settingsSaving = false;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				if (!sameTermId(state.selectedId, termId)) {
					return;
				}
				/*
				 * Stale response: a newer save was queued after this request started.
				 * Never applyLoadedNode here — that wiped fresher propBindings in the draft.
				 */
				if (seq !== autosaveSeq) {
					return;
				}
				if (
					autosave &&
					state.draft &&
					JSON.stringify(state.draft) !== JSON.stringify(payloadDraft)
				) {
					state.savedDraft = payloadDraft;
					if (json.data.node) {
						state.selectedNode = json.data.node;
						/* Keep live draft bindings over stale server snapshot. */
						if (
							state.draft.propBindings &&
							typeof state.draft.propBindings === 'object'
						) {
							state.selectedNode.propBindings = deepClone(state.draft.propBindings);
						}
					}
					mergeNodeTypeIntoTree(state.tree, json.data.node || {});
					refreshSlugMetaDisplay();
					scheduleAutosave();
					return;
				}
				if (json.data.node) {
					applyLoadedNode(json.data.node);
					mergeNodeTypeIntoTree(state.tree, json.data.node);
					if (
						payloadDraft.presentation &&
						payloadDraft.presentation.loaded
					) {
						state.draft.presentation = deepClone(
							payloadDraft.presentation
						);
						state.savedDraft.presentation = deepClone(
							payloadDraft.presentation
						);
					}
				}
				refreshSlugMetaDisplay();
				if (autosave) {
					refreshSettingsActionState();
				} else {
					render();
				}
			})
			.catch(function () {
				if (autosave) {
					state.autosaving = false;
				} else {
					state.settingsSaving = false;
				}
				setError(i18n.error);
			});
	}

	/**
	 * Praefix allowlist + conversion factors (editable).
	 * Same view on the Praefix child and under the unit parent (child extras).
	 */
	function renderPrefixAllowlistEditor(branch, container) {
		if (!branch || !Array.isArray(branch.children)) {
			return;
		}
		var rootToSi =
			branch.unitPrefixRootToSi != null && isFinite(Number(branch.unitPrefixRootToSi))
				? Number(branch.unitPrefixRootToSi)
				: 1;
		var block = el('div', { className: 'wtt-type-branch wtt-type-branch--embedded' });
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.praefixChildSettingsHint ||
					'Enable prefixes and enter each factor vs the prefix root. to_si = Typ × factor × unit root factor.',
			})
		);
		var rootRow = el('div', { className: 'wtt-type-branch__root-factor' });
		rootRow.appendChild(
			el('label', {
				className: 'wtt-type-branch__factor-label',
				text: i18n.prefixRootToSi || 'Unit: prefix root → SI base',
			})
		);
		var rootInput = el('input', {
			type: 'text',
			className: 'wtt-type-branch__factor-input',
			value: formatFactor(rootToSi) || '1',
			title: i18n.prefixRootToSiHint || 'Usually 1; Kilogramm uses 0.001 (g → kg).',
		});
		if (state.settingsSaving) {
			rootInput.disabled = true;
		}
		rootInput.addEventListener('input', function (e) {
			setDraftUnitPrefixRootToSi(e.target.value);
		});
		rootRow.appendChild(rootInput);
		block.appendChild(rootRow);

		var list = el('ul', {
			className: 'wtt-type-branch__list wtt-type-branch__list--factors',
		});
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var item = el('li', {
				className:
					'wtt-type-branch__item' + (child.enabled ? '' : ' is-disabled'),
			});
			var label = el('label', { className: 'wtt-checkbox-label' });
			var check = el('input', { type: 'checkbox', className: 'wtt-branch-check' });
			if (child.enabled) {
				check.checked = true;
			}
			if (state.settingsSaving) {
				check.disabled = true;
			}
			check.addEventListener('change', function (e) {
				setDraftBranchChild(parseInt(child.id, 10) || 0, !!e.target.checked);
			});
			label.appendChild(check);
			label.appendChild(document.createTextNode(' ' + (child.name || String(child.id))));
			item.appendChild(label);

			var factorWrap = el('span', { className: 'wtt-type-branch__factor' });
			factorWrap.appendChild(
				el('span', { className: 'wtt-type-branch__factor-mark', text: '×' })
			);
			var factorVal =
				child.multiplikator != null && child.multiplikator !== ''
					? Number(child.multiplikator)
					: null;
			var factorInput = el('input', {
				type: 'text',
				className: 'wtt-type-branch__factor-input',
				value: factorVal != null && isFinite(factorVal) ? formatFactor(factorVal) : '',
				placeholder: i18n.multiplikatorPlaceholder || 'e.g. 0.001',
				title: i18n.multiplikatorHint || 'Factor vs prefix root (SI powers).',
			});
			if (state.settingsSaving) {
				factorInput.disabled = true;
			}
			factorInput.addEventListener('input', function (e) {
				setDraftBranchMultiplikator(parseInt(child.id, 10) || 0, e.target.value);
			});
			factorWrap.appendChild(factorInput);
			if (child.enabled && factorVal != null && isFinite(factorVal) && factorVal > 0) {
				factorWrap.appendChild(
					el('span', {
						className: 'wtt-type-branch__to-si',
						text: '→ SI × ' + formatFactor(factorVal * rootToSi),
					})
				);
			}
			item.appendChild(factorWrap);
			list.appendChild(item);
		});
		block.appendChild(list);
		container.appendChild(block);
	}

	/**
	 * Unit settings wizard: Allowed prefixes (catalog marriage). Visible in Fallstudie too —
	 * Child extras / Type branch are skipped in caseStudyMode.
	 *
	 * @param {object} n
	 * @param {HTMLElement} pane
	 */
	function renderAllowedPrefixesWizard(n, pane) {
		if (!n || !n.isBasiseinheitUnit) {
			return;
		}
		var branch = null;
		if (
			state.draft &&
			state.draft.prefixBranch &&
			state.draft.prefixBranch.unitAllowlistEdit
		) {
			branch = state.draft.prefixBranch;
		} else if (n.prefixBranch && n.prefixBranch.unitAllowlistEdit) {
			branch = n.prefixBranch;
		} else if (Array.isArray(n.setMembers)) {
			for (var i = 0; i < n.setMembers.length; i++) {
				var m = n.setMembers[i];
				if (
					m &&
					memberNameKey(m) === 'praefix' &&
					m.typeBranch &&
					m.typeBranch.unitAllowlistEdit
				) {
					branch = m.typeBranch;
					break;
				}
			}
		}

		var block = el('div', {
			className: 'wtt-panel wtt-allowed-prefixes',
		});
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title',
				text: i18n.allowedPrefixesTitle || 'Allowed prefixes',
			})
		);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.allowedPrefixesHint ||
					'Which SI prefixes this unit may use (catalog marriage). Empty = value + unit only, no prefix.',
			})
		);
		if (!branch || !Array.isArray(branch.children)) {
			block.appendChild(
				el('p', {
					className: 'description',
					text:
						i18n.allowedPrefixesMissing ||
						'This unit has no editable prefix slot yet (typical for Without-prefix catalog units).',
				})
			);
			pane.appendChild(block);
			return;
		}
		/* Keep draft in sync so checkbox edits persist through save. */
		if (state.draft && !state.draft.prefixBranch) {
			state.draft.prefixBranch = deepClone(branch);
			branch = state.draft.prefixBranch;
		} else if (
			state.draft &&
			state.draft.prefixBranch &&
			state.draft.prefixBranch.unitAllowlistEdit
		) {
			branch = state.draft.prefixBranch;
		}
		renderPrefixAllowlistEditor(branch, block);
		pane.appendChild(block);
	}

	/**
	 * On set parents: show each child’s extras (not name/description) under parent settings.
	 */
	function renderChildExtrasOnParent(n, pane) {
		if (!n.isSet || !Array.isArray(n.setMembers) || !n.setMembers.length) {
			return;
		}

		var block = el('div', { className: 'wtt-child-extras' });
		block.appendChild(
			el('h3', {
				className: 'wtt-child-extras__title',
				text: i18n.childExtras || 'Child extras',
			})
		);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.childExtrasHint ||
					'Extras for set members (type, required, fixed, prefix conversion). Name and description stay on the child node.',
			})
		);

		n.setMembers.forEach(function (member) {
			if (!member) {
				return;
			}
			var card = el('div', { className: 'wtt-child-extras__member' });
			var head = el('div', { className: 'wtt-child-extras__head' });
			head.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-child-extras__link',
					text: member.name || String(member.id || ''),
					onClick: function () {
						if (member.id) {
							selectNode(member.id);
						}
					},
				})
			);
			card.appendChild(head);

			var meta = el('p', { className: 'wtt-child-extras__meta' });
			var bits = [];
			bits.push(
				(i18n.setMemberType || 'Type') +
					': ' +
					((member.type && (member.type.path || member.type.name)) ||
						i18n.setMemberUntyped ||
						'â€”')
			);
			if (member.required) {
				bits.push(i18n.required || 'Required');
			}
			if (member.fixed && member.fixed.name) {
				bits.push((i18n.fixedValue || 'Fixed') + ': ' + member.fixed.name);
			} else if (member.fixedLiteral) {
				bits.push((i18n.fixedValue || 'Fixed') + ': ' + member.fixedLiteral);
			}
			meta.textContent = bits.join(' · ');
			card.appendChild(meta);

			/* Prefix allowlist lives in Allowed prefixes wizard on the unit — not duplicated here. */

			block.appendChild(card);
		});

		pane.appendChild(block);
	}

	function renderTypeBranch(n, pane) {
		var branch = n.typeBranch;
		if (!branch || !Array.isArray(branch.children) || !branch.children.length) {
			return;
		}

		/* Same Praefix allowlist+factors view as on the unit parent (child extras). */
		if (branch.unitAllowlistEdit) {
			var allowWrap = el('div', { className: 'wtt-type-branch' });
			var allowTitle = i18n.typeBranch || 'Type branch';
			if (branch.typeName) {
				allowTitle += ': ' + branch.typeName;
			}
			allowWrap.appendChild(
				el('h3', {
					className: 'wtt-type-branch__title',
					text: allowTitle,
				})
			);
			renderPrefixAllowlistEditor(branch, allowWrap);
			pane.appendChild(allowWrap);
			return;
		}

		var unitLocked = !!branch.unitFilter;
		var block = el('div', { className: 'wtt-type-branch' });
		var title = i18n.typeBranch || 'Type branch';
		if (branch.typeName) {
			title += ': ' + branch.typeName;
		}
		block.appendChild(
			el('h3', {
				className: 'wtt-type-branch__title',
				text: title,
			})
		);
		if (unitLocked) {
			var unitHint = i18n.prefixFilteredByUnit || 'Filtered by Basiseinheit allowlist';
			if (branch.unitName) {
				unitHint += ': ' + branch.unitName;
			}
			block.appendChild(el('p', { className: 'wtt-field-hint', text: unitHint }));
		} else if (i18n.typeBranchHint) {
			block.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.typeBranchHint }));
		}

		var list = el('ul', { className: 'wtt-type-branch__list' });
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var item = el('li', {
				className:
					'wtt-type-branch__item' + (child.enabled ? '' : ' is-disabled'),
			});
			var label = el('label', { className: 'wtt-checkbox-label' });
			var check = el('input', {
				type: 'checkbox',
				className: 'wtt-branch-check',
			});
			if (child.enabled) {
				check.checked = true;
			}
			if (state.settingsSaving || unitLocked) {
				check.disabled = true;
			}
			check.addEventListener('change', function (e) {
				setDraftBranchChild(parseInt(child.id, 10) || 0, !!e.target.checked);
			});
			label.appendChild(check);
			label.appendChild(document.createTextNode(' ' + (child.name || String(child.id))));
			item.appendChild(label);
			list.appendChild(item);
		});
		block.appendChild(list);
		pane.appendChild(block);
	}

	function enabledBranchOptions(member) {
		var branch = member && member.typeBranch;
		if (branch && Array.isArray(branch.children)) {
			return branch.children.filter(function (child) {
				return child && child.enabled !== false;
			});
		}
		/* CatalogChoice attrs (e.g. Präfix / Einheit) expose options as fixedOptions. */
		if (member && Array.isArray(member.fixedOptions) && member.fixedOptions.length) {
			return member.fixedOptions.filter(function (child) {
				return child && child.enabled !== false;
			});
		}
		return [];
	}

	function previewValueKey(scope, member) {
		var scopePart = scope != null && String(scope) !== '' ? String(scope) : '_';
		var memberPart =
			member && member.id != null
				? 'id:' + member.id
				: 'name:' + String((member && member.name) || 'field');
		return String(state.selectedId || 0) + '|' + scopePart + '|' + memberPart;
	}

	function getPreviewValue(scope, member, fallback) {
		var key = previewValueKey(scope, member);
		if (Object.prototype.hasOwnProperty.call(state.previewValues, key)) {
			return state.previewValues[key];
		}
		return fallback;
	}

	function rememberPreviewFocus(control, key) {
		var start = null;
		var end = null;
		try {
			if (typeof control.selectionStart === 'number') {
				start = control.selectionStart;
			}
			if (typeof control.selectionEnd === 'number') {
				end = control.selectionEnd;
			}
		} catch (err) {
			/* type=email/number may throw InvalidStateError â€” caret cannot be read */
			start = null;
			end = null;
		}
		state.previewFocus = {
			key: key,
			start: start,
			end: end,
		};
	}

	function restorePreviewFocus() {
		var focus = state.previewFocus;
		if (!focus || !focus.key) {
			return;
		}
		var node = null;
		var nodes = document.querySelectorAll('[data-wtt-pv]');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].getAttribute('data-wtt-pv') === focus.key) {
				node = nodes[i];
				break;
			}
		}
		if (!node || typeof node.focus !== 'function') {
			return;
		}
		/*
		 * preventScroll: default focus() scrolls .wtt-detail-pane after our
		 * restorePaneScroll rAFs → scrollbar jumps on every preview keystroke.
		 */
		try {
			node.focus({ preventScroll: true });
		} catch (errFocus) {
			node.focus();
		}
		if (
			focus.start != null &&
			focus.end != null &&
			typeof node.setSelectionRange === 'function'
		) {
			var t = String(node.type || '').toLowerCase();
			var supportsCaret =
				node.tagName === 'TEXTAREA' ||
				t === '' ||
				t === 'text' ||
				t === 'search' ||
				t === 'email' ||
				t === 'url' ||
				t === 'tel' ||
				t === 'password' ||
				t === 'number';
			if (supportsCaret) {
				try {
					node.setSelectionRange(focus.start, focus.end);
				} catch (err) {
					/* ignore unsupported input types */
				}
			}
		}
	}

	/**
	 * Full re-paint while keeping detail/tree scroll + preview caret (Properties).
	 */
	function renderKeepingPreviewChrome() {
		var scroll = capturePaneScroll();
		render();
		restorePreviewFocus();
		restorePaneScroll(scroll);
	}

	function setPreviewValue(scope, member, value) {
		var key = previewValueKey(scope, member);
		state.previewValues[key] = value;
		rememberEmbedPick(member, value);
		renderKeepingPreviewChrome();
	}

	function bindPreviewControl(control, scope, member, opts) {
		opts = opts || {};
		var key = previewValueKey(scope, member);
		control.setAttribute('data-wtt-pv', key);
		var eventName = opts.event || 'input';
		control.addEventListener(eventName, function () {
			rememberPreviewFocus(control, key);
			var next = opts.readValue ? opts.readValue(control) : control.value;
			var maxAttr = control.getAttribute('maxlength');
			if (maxAttr === '1' && typeof next === 'string' && next.length > 1) {
				next = next.charAt(0);
				if (control.value !== next) {
					control.value = next;
				}
			}
			setPreviewValue(scope, member, next);
		});
		return control;
	}

	/**
	 * Default Editable preview value when state.previewValues has no key yet.
	 * Uses WTTSampleData (name heuristics â†’ type fallback) â€” does not overwrite session edits.
	 */
	function previewSampleText(member) {
		if (member && member.sample != null && String(member.sample) !== '') {
			return String(member.sample);
		}
		if (member.fixed && member.fixed.name) {
			return String(member.fixed.name);
		}
		if (member.fixedLiteral != null && String(member.fixedLiteral) !== '') {
			return String(member.fixedLiteral);
		}
		var key = typeKeyFromMember(member);
		if (isNodePresentationTypeKey(key)) {
			return member.displayName || member.name || '—';
		}
		if (key === 'praefixe' || key === 'basiseinheit') {
			var opts = enabledBranchOptions(member);
			if (opts.length) {
				var pick = opts[0];
				for (var i = 0; i < opts.length; i++) {
					if (opts[i] && opts[i].name === 'm') {
						pick = opts[i];
						break;
					}
				}
				return (pick && pick.name) || 'â€”';
			}
			return 'â€”';
		}
		if (key === 'node_ref' || key === 'node_embed') {
			return '';
		}
		var Sample = window.WTTSampleData;
		if (Sample) {
			var mapped = '';
			if (typeof Sample.forAttribute === 'function') {
				mapped = Sample.forAttribute(member, key);
			} else if (typeof Sample.forType === 'function') {
				mapped = Sample.forType(key, {
					name: member && member.name,
					shortDescription: member && member.shortDescription,
					displayName: member && member.displayName,
				});
			}
			if (mapped != null && String(mapped) !== '') {
				if (key === 'bool') {
					return mapped === 'true' || mapped === '1'
						? i18n.boolTrue || 'true'
						: i18n.boolFalse || 'false';
				}
				return String(mapped);
			}
		}
		if (key === 'media') {
			var sampleImg = mediaKindSampleEntries()[0];
			return sampleImg ? mediaRefToStore(sampleImg.ref) : '';
		}
		return i18n.previewSampleText || 'Sample';
	}

	function livePreviewText(scope, member) {
		return String(getPreviewValue(scope, member, previewSampleText(member)));
	}

	function isPraefixMemberName(member) {
		var key = memberNameKey(member);
		return (
			key === 'praefix' ||
			key === 'prefix' ||
			key === 'prafix' ||
			key === 'prufix'
		);
	}

	function renderBranchSelect(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var editable = !!opts.editable;
		var scope = opts.scope;
		var sample = opts.sample != null ? String(opts.sample) : '';
		var options = enabledBranchOptions(member);
		var symbolLabels =
			opts.symbolLabels === true ||
			(opts.symbolLabels !== false &&
				!opts.labeledSymbols &&
				isPraefixMemberName(member));
		var labeledSymbols = !!opts.labeledSymbols;
		var control = renderOptionsSelect(options, {
			className:
				'wtt-preview-input' +
				(compact
					? labeledSymbols
						? ' wtt-preview-input--unit-labeled'
						: symbolLabels
							? ' wtt-preview-input--prefix'
							: ' wtt-preview-input--compact'
					: ''),
			disabled: !editable,
			selectedValue: sample,
			symbolLabels: symbolLabels,
			labeledSymbols: labeledSymbols,
			emptyLabel: opts.emptyLabel,
			emptyValue: opts.emptyValue != null ? opts.emptyValue : '',
			allowEmpty:
				opts.allowEmpty === true
					? true
					: opts.allowEmpty === false
						? false
						: opts.emptyLabel
							? true
							: memberListSelectAllowsEmpty(member),
			getValue: function (child) {
				return String(child.name || child.id);
			},
		});
		if (editable) {
			/*
			 * Optional beforeChange(oldVal, newVal) — e.g. Q109 Präfix rescale
			 * of sibling Typ magnitudes before writing the new prefix.
			 */
			if (typeof opts.beforeChange === 'function') {
				var prevPrefix = control.value;
				control.setAttribute('data-wtt-pv', previewValueKey(scope, member));
				control.addEventListener('change', function () {
					var next = control.value;
					rememberPreviewFocus(control, previewValueKey(scope, member));
					try {
						opts.beforeChange(prevPrefix, next);
					} catch (err) {
						/* keep writing prefix even if rescale fails */
					}
					prevPrefix = next;
					setPreviewValue(scope, member, next);
				});
			} else {
				bindPreviewControl(control, scope, member, { event: 'change' });
			}
		}
		return control;
	}

	/**
	 * Q109 — rescale Typ preview values when a Praefix select changes (same Basiseinheit).
	 *
	 * @param {Array} members Set / join-units members that share this Praefix.
	 * @param {object} praefixMem Praefix member (typeBranch.children carry multiplikator).
	 * @param {string} oldKey
	 * @param {string} newKey
	 * @param {number} [prefixRootToSi]
	 */
	function rescaleQuantityTypsOnPrefixChange(
		members,
		praefixMem,
		oldKey,
		newKey,
		prefixRootToSi
	) {
		var qtyApi =
			window.WTTConverter && window.WTTConverter.Quantity
				? window.WTTConverter.Quantity
				: null;
		if (!qtyApi || typeof qtyApi.rescaleOnPrefixChange !== 'function') {
			return;
		}
		var prefixOpts = enabledBranchOptions(praefixMem);
		var root =
			prefixRootToSi != null && isFinite(Number(prefixRootToSi))
				? Number(prefixRootToSi)
				: 1;
		(members || []).forEach(function (member) {
			var normalized = asPreviewField(member) || member;
			var qty = resolveQuantityMembers(normalized);
			if (!qty) {
				return;
			}
			var typ =
				findSetMemberByKey(qty, 'typ') || findSetMemberByKey(qty, 'wert');
			if (!typ) {
				return;
			}
			var scope = member.id != null ? member.id : member.name;
			var key = previewValueKey(scope, typ);
			var current = Object.prototype.hasOwnProperty.call(state.previewValues, key)
				? state.previewValues[key]
				: getPreviewValue(scope, typ, previewSampleText(typ));
			var next = qtyApi.rescaleOnPrefixChange(
				current,
				oldKey,
				newKey,
				prefixOpts,
				root
			);
			if (next != null) {
				state.previewValues[key] = next;
			}
		});
	}

	/**
	 * Q96: this term is a Registry builtin Simple/Complex catalog leaf
	 * (bound as builtin.<id> → term id), not a specialization or field slot.
	 */
	function isBuiltinCatalogLeaf(n) {
		if (!n) {
			return false;
		}
		if (registryIdFromCatalogBindings(n.id)) {
			return true;
		}
		/* Soft fallback when bindings lag: template leaf named like a registry id. */
		var selfKey = String(n.name || '')
			.trim()
			.toLowerCase();
		if (!selfKey || !n.isTemplate) {
			return false;
		}
		var known = {
			int: 1,
			integer: 1,
			double: 1,
			float: 1,
			text: 1,
			textarea: 1,
			char: 1,
			bool: 1,
			boolean: 1,
			email: 1,
			date: 1,
			datetime: 1,
			media: 1,
			quantity: 1,
			display_node_name: 1,
			node_presentation: 1,
			node_ref: 1,
			node_embed: 1,
			node_pick: 1,
		};
		if (!known[selfKey]) {
			return false;
		}
		return typeof isUnderTypenBranch === 'function'
			? !!isUnderTypenBranch(n.id)
			: true;
	}

	/**
	 * Q115: Read-only is editable only on fillable attribute slots (OQ-A3).
	 * Elsewhere the control is shown grayed for chrome consistency.
	 */
	function canEditReadonly(n) {
		return !!(n && n.isAttributeSlot);
	}

	function shouldShowReadonly(n) {
		if (!n || n.isTrash || n.isHiddenBin) {
			return false;
		}
		return !!(
			n.isAttributeSlot ||
			n.isModelDataHost ||
			isBuiltinCatalogLeaf(n) ||
			n.typeId
		);
	}

	/**
	 * Node Default value (legacy `_wtt_fixed_*`): presets / field constants — not a lock.
	 * Attribute instance defaults stay on the host Attributes panel (Q106).
	 */
	function canEditDefaultValue(n) {
		if (!n || !n.typeId || n.isSet || n.isTable) {
			return false;
		}
		if (n.isAttributeSlot || n.isModelDataHost) {
			return false;
		}
		if (isBuiltinCatalogLeaf(n)) {
			return false;
		}
		if (isNodePresentationTypeKey(typeKeyFromMember(n)) || typeKeyFromMember(n) === 'media') {
			return false;
		}
		return true;
	}

	function shouldShowDefaultValue(n) {
		if (!n || n.isAttributeSlot || n.isModelDataHost || n.isSet || n.isTable) {
			return false;
		}
		if (isBuiltinCatalogLeaf(n)) {
			return true;
		}
		return canEditDefaultValue(n);
	}

	/** @deprecated Use canEditDefaultValue — kept as alias for older call sites. */
	function canEditFixedValue(n) {
		return canEditDefaultValue(n);
	}

	function setDraftReadonly(on) {
		if (!state.draft) {
			return;
		}
		if (!canEditReadonly(state.draft)) {
			return;
		}
		state.draft.readonly = !!on;
		/* Readonly replaces Fixed-as-lock — do not keep writing fixedEnabled locks. */
		if (state.draft.isAttributeSlot) {
			state.draft.fixedEnabled = false;
		}
		afterDraftMutation();
	}

	/**
	 * One flag as slide switch via shared Settings renderer (fallback local).
	 */
	function renderMetaFlagSwitch(opts) {
		opts = opts || {};
		var SR = settingsRender();
		if (SR && typeof SR.renderFlagSwitch === 'function') {
			return SR.renderFlagSwitch(opts);
		}
		return el(
			'div',
			{
				className:
					'wtt-form__meta-flag wtt-settings-flag' +
					(opts.disabled ? ' is-muted' : ''),
				title: opts.title || '',
			},
			[
				renderSlideSwitch({
					checked: !!opts.checked,
					disabled: !!opts.disabled,
					title: opts.title || '',
					text: opts.label || '',
					onChange: opts.onChange,
				}),
			]
		);
	}

	/**
	 * Canonical Flags chrome: shared 5-per-row strip (slide switches only).
	 * Accepts flag descriptors or prebuilt flag cells — not checkboxes.
	 *
	 * @param {Array<object|HTMLElement|null|undefined>} flags
	 * @param {{className?: string, inRow?: boolean, columns?: number}} [opts]
	 * @return {HTMLElement}
	 */
	function renderFlagsStrip(flags, opts) {
		opts = opts || {};
		var SR = settingsRender();
		if (SR && typeof SR.renderFlagsStrip === 'function') {
			return SR.renderFlagsStrip({
				flags: flags || [],
				columns:
					opts.columns ||
					(SR.FLAGS_COLUMNS != null ? SR.FLAGS_COLUMNS : 5),
				className: opts.className || '',
				inRow: !!opts.inRow,
			});
		}
		var strip = el('div', {
			className:
				'wtt-form__meta-strip wtt-form__meta-strip--flags wtt-settings-flags' +
				(opts.inRow ? ' wtt-form__meta-strip--in-row' : '') +
				(opts.className ? ' ' + opts.className : ''),
		});
		strip.style.setProperty(
			'--wtt-flags-cols',
			String(opts.columns || 5)
		);
		(flags || []).forEach(function (flag) {
			if (!flag) {
				return;
			}
			if (flag.nodeType === 1) {
				strip.appendChild(flag);
				return;
			}
			strip.appendChild(renderMetaFlagSwitch(flag));
		});
		return strip;
	}

	/**
	 * Flags form row (label | 5-col strip | help) — same on every node surface.
	 *
	 * @param {Array<object|HTMLElement|null|undefined>} flags
	 * @param {{label?: string, help?: string|Node, className?: string}} [opts]
	 * @return {HTMLElement|null}
	 */
	function renderNodeFlagsRow(flags, opts) {
		opts = opts || {};
		var list = (flags || []).filter(function (f) {
			return !!f;
		});
		if (!list.length) {
			return null;
		}
		var strip = renderFlagsStrip(list, { inRow: true });
		if (flagsAsFormRowEnabled()) {
			return formRow(
				opts.label || i18n.nodeFlags || 'Flags',
				[strip],
				{
					className:
						'wtt-form__row--flags' +
						(opts.className ? ' ' + opts.className : ''),
					help:
						opts.help != null
							? opts.help
							: i18n.nodeFlagsHint || '',
				}
			);
		}
		return strip;
	}

	/**
	 * Compact wrap strip for static chips (or legacy callers).
	 * Flags MUST use renderFlagsStrip / renderNodeFlagsRow (slide switches, 5/row).
	 * @param {'flags'|'static'} kind
	 * @param {Array<Node|null|undefined>} items
	 * @param {string} [extraClass]
	 */
	function renderMetaStrip(kind, items, extraClass) {
		if (kind === 'flags') {
			return renderFlagsStrip(items || [], {
				className: extraClass || '',
			});
		}
		var stripFallback = el('div', {
			className:
				'wtt-form__meta-strip wtt-form__meta-strip--static' +
				(extraClass ? ' ' + extraClass : ''),
		});
		(items || []).forEach(function (item) {
			if (item) {
				stripFallback.appendChild(item);
			}
		});
		return stripFallback;
	}

	/**
	 * One checkbox in a meta strip.
	 * @param {{
	 *   id?: string,
	 *   label: string,
	 *   checked?: boolean,
	 *   disabled?: boolean,
	 *   title?: string,
	 *   className?: string,
	 *   onChange?: function(boolean): void
	 * }} opts
	 */
	function renderMetaCheck(opts) {
		opts = opts || {};
		var id = opts.id || '';
		var inputAttrs = {
			type: 'checkbox',
			className: opts.className || 'wtt-required-check',
		};
		if (id) {
			inputAttrs.id = id;
		}
		if (opts.checked) {
			inputAttrs.checked = 'checked';
		}
		if (opts.disabled) {
			inputAttrs.disabled = 'disabled';
		}
		var input = el('input', inputAttrs);
		if (typeof opts.onChange === 'function') {
			input.addEventListener('change', function (e) {
				opts.onChange(!!e.target.checked);
			});
		}
		var labelAttrs = {
			className:
				'wtt-form__meta-check' +
				(opts.labelClass ? ' ' + opts.labelClass : ''),
			title: opts.title || '',
		};
		if (id) {
			labelAttrs.htmlFor = id;
		}
		var label = el('label', labelAttrs);
		label.appendChild(input);
		label.appendChild(document.createTextNode(' ' + (opts.label || '')));
		return label;
	}

	/**
	 * Keep the Slug meta chip in sync without a full detail re-render
	 * (name input uses silent draft updates so the cursor is preserved).
	 */
	function refreshSlugMetaDisplay() {
		var chip = document.querySelector('[data-wtt-meta="slug"]');
		if (!chip) {
			return;
		}
		var label = i18n.slug || 'Slug';
		var value =
			state.draft && state.draft.slug != null && String(state.draft.slug) !== ''
				? String(state.draft.slug)
				: 'â€”';
		chip.textContent = label + ': ' + value;
	}

	/**
	 * One read-only chip in a static meta strip.
	 * Optional onClick turns the value into a navigation link.
	 * @param {{ label: string, value?: string|number, title?: string, metaKey?: string, onClick?: function(): void }} opts
	 */
	function renderMetaStatic(opts) {
		opts = opts || {};
		var label = opts.label != null ? String(opts.label) : '';
		var value = opts.value != null ? String(opts.value) : 'â€”';
		var title = opts.title || '';
		var metaKey = opts.metaKey ? String(opts.metaKey) : '';
		if (typeof opts.onClick === 'function') {
			var chip = el('span', {
				className: 'wtt-form__meta-static wtt-form__meta-static--link',
				title: title,
			});
			if (metaKey) {
				chip.setAttribute('data-wtt-meta', metaKey);
			}
			if (label) {
				chip.appendChild(document.createTextNode(label + ': '));
			}
			chip.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-form__meta-static-btn',
					text: value,
					title: title,
					onClick: opts.onClick,
				})
			);
			return chip;
		}
		var staticChip = el('span', {
			className: 'wtt-form__meta-static',
			title: title,
			text: label ? label + ': ' + value : value,
		});
		if (metaKey) {
			staticChip.setAttribute('data-wtt-meta', metaKey);
		}
		return staticChip;
	}

	function formRow(labelText, controlNodes, opts) {
		opts = opts || {};
		var row = el('div', {
			className: 'wtt-form__row' + (opts.className ? ' ' + opts.className : ''),
		});
		var labelCol = el('div', { className: 'wtt-form__label' });
		if (opts.htmlFor) {
			labelCol.appendChild(
				el('label', {
					text: labelText || '',
					htmlFor: opts.htmlFor,
				})
			);
		} else {
			labelCol.appendChild(
				el('span', {
					className: 'wtt-form__label-text',
					text: labelText || '',
				})
			);
		}
		if (opts.labelExtra) {
			if (opts.labelExtra.nodeType) {
				labelCol.appendChild(opts.labelExtra);
			} else if (Array.isArray(opts.labelExtra)) {
				opts.labelExtra.forEach(function (n) {
					if (n) {
						labelCol.appendChild(n);
					}
				});
			}
		}
		var controlCol = el('div', { className: 'wtt-form__control' });
		(Array.isArray(controlNodes) ? controlNodes : [controlNodes]).forEach(function (node) {
			if (node) {
				controlCol.appendChild(node);
			}
		});
		var helpCol = el('div', { className: 'wtt-form__help' });
		var helpNode = null;
		if (opts.help != null && opts.help !== '') {
			if (opts.help.nodeType) {
				helpNode = opts.help;
			} else {
				helpNode = renderHelpHint(opts.help);
			}
		}
		if (helpNode) {
			helpCol.appendChild(helpNode);
		}
		row.appendChild(labelCol);
		row.appendChild(controlCol);
		row.appendChild(helpCol);
		return row;
	}

	function defaultValueFieldHelpText(n) {
		var parts = [];
		if (isBuiltinCatalogLeaf(n)) {
			parts.push(
				i18n.nodeDefaultGrayHint ||
					'Builtin Simple leaves do not store a default here. Specializations and typed fields may set Default value; attribute defaults stay in the Attributes panel.'
			);
			return parts.join('\n\n');
		}
		if (i18n.nodeDefaultHint || i18n.fixedValueHint) {
			parts.push(
				i18n.nodeDefaultHint ||
					i18n.fixedValueHint ||
					'Optional default seed for this node (not a lock). Read-only is separate.'
			);
		}
		if (n && n.fixedEnabled) {
			if (supportsFixedLiteral(n.type)) {
				if (i18n.nodeDefaultLiteralHint || i18n.fixedLiteralHint) {
					parts.push(i18n.nodeDefaultLiteralHint || i18n.fixedLiteralHint);
				}
			} else if (i18n.nodeDefaultCatalogHint || i18n.fixedCatalogHint) {
				parts.push(i18n.nodeDefaultCatalogHint || i18n.fixedCatalogHint);
			}
		}
		return parts.join('\n\n');
	}

	/** @deprecated Use defaultValueFieldHelpText. */
	function fixedFieldHelpText(n) {
		return defaultValueFieldHelpText(n);
	}

	/**
	 * Whether node Settings Default should mount type paint (Registry).
	 *
	 * @param {object} n
	 * @return {boolean}
	 */
	function nodeShouldTypePaintDefault(n) {
		if (!n) {
			return false;
		}
		var pref = normalizePreferredRender(n.preferredRender || '')
			.toLowerCase();
		if (pref === 'quantity' || pref === 'unit') {
			return true;
		}
		if (
			n.quantitySchema &&
			Array.isArray(n.quantitySchema.members) &&
			n.quantitySchema.members.length
		) {
			return true;
		}
		if (n.isBasiseinheitUnit) {
			return true;
		}
		if (Array.isArray(n.attributes) && n.attributes.length >= 2) {
			return true;
		}
		return supportsFixedLiteral(n.type);
	}

	/**
	 * Field probe for node Settings Default type paint.
	 *
	 * @param {object} n
	 * @return {object}
	 */
	function nodeToDefaultField(n) {
		var pref = normalizePreferredRender(n.preferredRender || '');
		var attrs = Array.isArray(n.attributes) ? n.attributes : [];
		var ObjectRender = window.WTTObjectRender;
		var typeProperties = attrs;
		if (ObjectRender && typeof ObjectRender.normalizeAttributes === 'function') {
			typeProperties = ObjectRender.normalizeAttributes(attrs);
		}
		return {
			id: n.id,
			name: n.name || '',
			displayName: n.name || '',
			typeKey: typeKeyFromMember(n),
			typeName: typeKeyFromMember(n),
			preferredRender: pref,
			typePreferredRender: pref,
			quantitySchema: n.quantitySchema || null,
			typeProperties: typeProperties,
			attributes: typeProperties,
			fixedOptions: Array.isArray(n.fixedOptions) ? n.fixedOptions : [],
			fixedValues: n.fixedLiteral ? [String(n.fixedLiteral)] : [],
			isBasiseinheitUnit: !!n.isBasiseinheitUnit,
			fixedMode: n.fixedMode || '',
		};
	}

	/**
	 * Default value editor (legacy fixed meta). When forceMuted, show gray stub only.
	 * Layout: slide switch + value field (field always visible; grayed when switch off).
	 */
	function renderFixedValueField(n, controlsLocked, forceMuted) {
		var muted = !!forceMuted || !canEditDefaultValue(n);
		if (muted && !shouldShowDefaultValue(n)) {
			return null;
		}
		if (muted) {
			var stub = el('div', {
				className: 'wtt-fixed-control wtt-fixed-control--muted',
			});
			stub.appendChild(
				el('span', {
					className: 'wtt-muted-label',
					text:
						i18n.nodeDefaultUnavailable ||
						i18n.fixedValueUnavailable ||
						'Default value is not editable on builtin Simple types.',
				})
			);
			return stub;
		}

		var enabled = !!n.fixedEnabled;
		var fieldLocked = !!controlsLocked || !enabled;
		var wrap = el('div', {
			className:
				'wtt-fixed-control' + (enabled ? '' : ' wtt-fixed-control--off'),
		});
		wrap.appendChild(
			renderSlideSwitch({
				checked: enabled,
				disabled: !!controlsLocked,
				title:
					i18n.nodeDefaultOn ||
					i18n.fixedValueOn ||
					'Use default value',
				onChange: function (on) {
					setDraftFixedEnabled(on);
				},
			})
		);
		var valueHost = el('div', {
			className:
				'wtt-fixed-control__value' +
				(fieldLocked ? ' is-disabled' : ''),
		});
		wrap.appendChild(valueHost);

		var key = typeKeyFromMember(n);

		/* Type paint (Preferred + settings) — same chrome as attribute Default. */
		if (
			key !== 'node_embed' &&
			key !== 'node_ref' &&
			window.WTTObjectRender &&
			typeof window.WTTObjectRender.paintFieldContent === 'function' &&
			nodeShouldTypePaintDefault(n)
		) {
			var nodeField = nodeToDefaultField(n);
			var paintHost = el('div', {
				className: 'wtt-fixed-type-paint',
				id: 'wtt-node-fixed-literal-host',
			});
			var nodePainted = window.WTTObjectRender.paintFieldContent(
				nodeField,
				n.fixedLiteral || '',
				{
					readonly: fieldLocked,
					contextName: 'form',
					noSampleFill: true,
					onInput: fieldLocked
						? null
						: function (next) {
								setDraftFixedLiteral(
									next == null ? '' : String(next),
									{ silent: true }
								);
						  },
				}
			);
			if (nodePainted) {
				paintHost.appendChild(nodePainted);
				valueHost.appendChild(paintHost);
				return wrap;
			}
		}

		if (supportsFixedLiteral(n.type)) {
			if (key === 'bool') {
				var boolSelect = el('select', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-type-select',
				});
				if (fieldLocked) {
					boolSelect.disabled = true;
				}
				[
					{ v: '0', t: i18n.boolFalse || 'false' },
					{ v: '1', t: i18n.boolTrue || 'true' },
				].forEach(function (opt) {
					var option = el('option', { value: opt.v, text: opt.t });
					if (String(n.fixedLiteral || '0') === opt.v) {
						option.selected = true;
					}
					boolSelect.appendChild(option);
				});
				boolSelect.addEventListener('change', function (e) {
					setDraftFixedLiteral(e.target.value);
				});
				valueHost.appendChild(boolSelect);
			} else if (key === 'textarea') {
				var area = el('textarea', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-fixed-literal wtt-fixed-literal--textarea',
					rows: '3',
				});
				area.value = n.fixedLiteral || '';
				if (fieldLocked) {
					area.disabled = true;
				}
				area.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				valueHost.appendChild(area);
			} else {
				var input = el('input', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-fixed-literal',
					type: key === 'int' || key === 'double' || key === 'quantity' ? 'number' : 'text',
					step: key === 'int' ? '1' : key === 'double' || key === 'quantity' ? 'any' : undefined,
					maxlength: key === 'char' ? '1' : undefined,
					value: n.fixedLiteral || '',
					placeholder: i18n.fixedLiteralPlaceholder || 'Constant value…',
				});
				if (fieldLocked) {
					input.disabled = true;
				}
				input.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				valueHost.appendChild(input);
			}
			return wrap;
		}

		if (key === 'node_embed' || key === 'node_ref') {
			var fixedRoots = catalogFixedPickerRoots(n, key);
			var fixedSelectable = null;
			if (key === 'node_embed' && (parseInt(n.refScopeId, 10) || 0) > 0) {
				fixedSelectable = {};
				fixedRoots.forEach(function (c) {
					if (c && c.id != null) {
						fixedSelectable[String(c.id)] = true;
					}
				});
			}
			var fixedPicker = renderNodeTreePicker({
				roots: fixedRoots,
				selectedId: n.fixedNodeId || 0,
				selectedLabel: (n.fixed && n.fixed.name) || '',
				compact: true,
				defaultOpen: !!(n.fixedNodeId || 0),
				expandKey: 'fixed:' + String(n.id || 0),
				allowRoot: false,
				allowClear: !fieldLocked,
				disabled: fieldLocked,
				pickedPrefix: i18n.nodePickerSelected || 'Selected:',
				placeholder:
					i18n.nodeDefaultChoose ||
					i18n.fixedValueChoose ||
					'Choose node…',
				dialogTitle:
					i18n.attributesFixedTitle ||
					i18n.fixedValue ||
					'Default value',
				emptyText:
					(parseInt(n.refScopeId, 10) || 0)
						? i18n.subtreeEmpty || 'No children under catalog root'
						: i18n.relationsEmpty || 'None',
				selectable: function (node) {
					var scopeId = parseInt(n.refScopeId, 10) || 0;
					var allowed = n.allowedRefIds || [];
					if (key === 'node_ref') {
						if (!scopeId) {
							return true;
						}
						return isAllowedRefCandidate(node, scopeId, allowed);
					}
					return !!(fixedSelectable && fixedSelectable[String(node.id)]);
				},
				onSelect: function (id) {
					setDraftFixed(id || 0);
				},
			});
			valueHost.appendChild(fixedPicker);
			if (!(parseInt(n.refScopeId, 10) || 0)) {
				valueHost.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text:
							i18n.fixedCatalogWholeTreeHint ||
							'No catalog root (ref_scope) yet — pick any node in the tree, or set ref_scope first to limit to catalog children.',
					})
				);
			}
			return wrap;
		}

		var fixedSelect = renderOptionsSelect(
			[
				{
					id: 0,
					name:
						i18n.nodeDefaultChoose ||
						i18n.fixedValueChoose ||
						'Choose node',
				},
			].concat(
				(Array.isArray(n.fixedOptions) ? n.fixedOptions : []).filter(function (opt) {
					return opt && opt.id != null;
				})
			),
			{
				className: 'wtt-type-select',
				disabled: fieldLocked,
				selectedValue: n.fixedNodeId || 0,
				allowEmpty: false,
				getValue: function (opt) {
					var id = parseInt(opt.id, 10) || 0;
					return id > 0 ? String(id) : '';
				},
				onChange: function (e) {
					setDraftFixed(parseInt(e.target.value, 10) || 0);
				},
			}
		);
		fixedSelect.id = 'wtt-node-fixed';
		if (
			n.fixedNodeId &&
			!(Array.isArray(n.fixedOptions) ? n.fixedOptions : []).some(function (opt) {
				return opt && String(opt.id) === String(n.fixedNodeId);
			})
		) {
			(function () {
				var fixedOpt = n.fixed || { name: String(n.fixedNodeId) };
				var tip = formatSelectTitle(fixedOpt);
				var attrs = {
					value: String(n.fixedNodeId),
					text: formatSelectLabel(fixedOpt),
					selected: true,
				};
				if (tip) {
					attrs.title = tip;
				}
				fixedSelect.appendChild(el('option', attrs));
				syncSelectTitle(fixedSelect);
			})();
		}
		valueHost.appendChild(fixedSelect);
		return wrap;
	}


	function membersShareSameType(members) {
		if (!members || members.length < 2) {
			return false;
		}
		var first = memberTypeIdentity(members[0]);
		if (!first) {
			return false;
		}
		for (var i = 1; i < members.length; i++) {
			if (memberTypeIdentity(members[i]) !== first) {
				return false;
			}
		}
		return true;
	}

	/** True when the memberâ€™s quantity schema includes a Praefix slot. */
	function memberHasPraefixSlot(member) {
		if (!member || !member.quantitySchema || !Array.isArray(member.quantitySchema.members)) {
			return false;
		}
		for (var i = 0; i < member.quantitySchema.members.length; i++) {
			var m = member.quantitySchema.members[i];
			var key = String((m && m.name) || '')
				.toLowerCase()
				.replace(/Ã¼/g, 'ue')
				.replace(/Ã¤/g, 'ae')
				.replace(/Ã¶/g, 'oe');
			if (key === 'praefix') {
				return true;
			}
		}
		return false;
	}

	/** Join-units UI/preview: same type + every member is a quantity with Praefix. */
	function canJoinSetUnits(members) {
		return membersShareSameType(members) && (members || []).every(memberHasPraefixSlot);
	}

	function memberTypeIdentity(member) {
		if (!member) {
			return '';
		}
		if (member.typeId) {
			return 'id:' + String(member.typeId);
		}
		if (member.type && member.type.id) {
			return 'id:' + String(member.type.id);
		}
		if (member.type && member.type.name) {
			return 'name:' + String(member.type.name).toLowerCase();
		}
		if (member.quantitySchema && member.quantitySchema.unitId) {
			return 'unit:' + String(member.quantitySchema.unitId);
		}
		return '';
	}

	function renderSetSettings(n, pane) {
		if (!n.isSet) {
			return;
		}

		var block = el('div', { className: 'wtt-panel wtt-set-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-set-settings__title',
				text: i18n.setSettings || 'Set settings',
			})
		);

		var sepField = el('div', { className: 'wtt-field wtt-field--set-separator wtt-field--inline' });
		sepField.appendChild(
			el('label', {
				className: 'wtt-field__label',
				htmlFor: 'wtt-set-separator',
				text: i18n.setSeparator || 'Separator',
			})
		);
		var sepInput = el('input', {
			type: 'text',
			id: 'wtt-set-separator',
			className: 'wtt-set-separator-input',
			value: n.setSeparator != null ? String(n.setSeparator) : '/',
			maxlength: '16',
			title: i18n.setSeparatorHint || '',
		});
		if (state.settingsSaving) {
			sepInput.disabled = true;
		}
		sepInput.addEventListener('input', function (e) {
			setDraftSetSeparator(e.target.value);
		});
		sepField.appendChild(sepInput);
		block.appendChild(sepField);

		var members = n.setMembers || [];
		var sameType = membersShareSameType(members);
		var canJoin = canJoinSetUnits(members);
		var joinHint =
			i18n.setJoinUnitsHint ||
			'When all members share the same type with Praefix, choose Praefix once for all (e.g. 10.5/20/5mm).';
		if (!canJoin) {
			joinHint = !sameType
				? i18n.setJoinUnitsUnavailable ||
					'Takes effect in preview when every set member has the same data type.'
				: i18n.setJoinUnitsNoPrefix ||
					'Takes effect in preview when that shared type includes a Praefix.';
		}

		block.appendChild(
			renderFlagsStrip(
				[
					{
						label:
							i18n.setLabelChildren ||
							'Include composition in label',
						checked: n.setLabelChildren !== false,
						disabled: !!state.settingsSaving,
						title: i18n.setLabelChildrenHint || '',
						onChange: setDraftSetLabelChildren,
					},
					{
						label: i18n.setJoinUnits || 'Join units',
						checked: n.setJoinUnits !== false,
						disabled: !!state.settingsSaving || !canJoin,
						title: joinHint,
						onChange: setDraftSetJoinUnits,
					},
				],
				{ className: 'wtt-set-settings__flags' }
			)
		);
		pane.appendChild(block);
	}

	/**
	 * Festwerte editor for concrete enum nodes (Q52 Option column leaves).
	 */
	function renderEnumValuesSettings(n, pane, controlsLocked) {
		if (!n || !n.isConcreteEnum) {
			return;
		}

		var locked = !!controlsLocked || !!state.settingsSaving || !!state.enumValuesSaving;
		var rows = Array.isArray(state.enumValuesDraft)
			? state.enumValuesDraft.slice()
			: (Array.isArray(n.enumOptions) ? n.enumOptions : []).map(function (o) {
					return String((o && o.name) || '');
			  });

		function setDraftRows(next) {
			state.enumValuesDraft = next.slice();
			state.enumValuesDirty = true;
			render();
		}

		function collectFromDom(host) {
			var inputs = host.querySelectorAll('.wtt-enum-values__input');
			var vals = [];
			Array.prototype.forEach.call(inputs, function (inp) {
				vals.push(String(inp.value || ''));
			});
			return vals;
		}

		var block = el('div', { className: 'wtt-panel wtt-enum-values' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title',
				text: i18n.enumValuesTitle || 'Enum values',
			})
		);
		block.appendChild(
			el('p', {
				className: 'description',
				text:
					i18n.enumValuesHint ||
					'Closed Festwerte for this concrete enum.',
			})
		);

		var table = el('table', {
			className: 'wtt-enum-values__table wtt-row-edit-table widefat striped',
		});
		table.appendChild(
			el('thead', null, [
				el('tr', null, [
					el('th', {
						className: 'wtt-col-value',
						text: i18n.enumValuesValue || 'Value',
					}),
					el('th', {
						className: 'wtt-col-actions',
						text: i18n.enumValuesActions || 'Actions',
					}),
				]),
			])
		);
		var tbody = el('tbody');

		if (!rows.length) {
			tbody.appendChild(
				el('tr', null, [
					el('td', {
						colSpan: 2,
						className: 'description',
						text:
							i18n.enumValuesEmpty ||
							'No values yet. Add the allowed Festwerte for this enum.',
					}),
				])
			);
		}

		rows.forEach(function (val, idx) {
			var tr = el('tr');
			var tdVal = el('td', { className: 'wtt-col-value' });
			var inp = el('input', {
				type: 'text',
				className: 'regular-text wtt-enum-values__input',
				value: val,
				disabled: locked,
			});
			inp.addEventListener('input', function () {
				state.enumValuesDirty = true;
			});
			inp.addEventListener('change', function () {
				var next = collectFromDom(tbody);
				state.enumValuesDraft = next;
				state.enumValuesDirty = true;
			});
			tdVal.appendChild(inp);
			tr.appendChild(tdVal);

			var tdAct = el('td', { className: 'wtt-col-actions' });
			var upBtn = el('button', {
				type: 'button',
				className: 'button-link wtt-row-edit-table__action',
				title: i18n.enumValuesMoveUp || 'Move up',
				'aria-label': i18n.enumValuesMoveUp || 'Move up',
				disabled: locked || idx === 0,
				onClick: function () {
					var next = collectFromDom(tbody);
					if (idx <= 0 || idx >= next.length) {
						return;
					}
					var tmp = next[idx - 1];
					next[idx - 1] = next[idx];
					next[idx] = tmp;
					setDraftRows(next);
				},
			});
			upBtn.appendChild(
				el('span', {
					className: 'dashicons dashicons-arrow-up-alt2',
					'aria-hidden': 'true',
				})
			);
			var downBtn = el('button', {
				type: 'button',
				className: 'button-link wtt-row-edit-table__action',
				title: i18n.enumValuesMoveDown || 'Move down',
				'aria-label': i18n.enumValuesMoveDown || 'Move down',
				disabled: locked || idx >= rows.length - 1,
				onClick: function () {
					var next = collectFromDom(tbody);
					if (idx < 0 || idx >= next.length - 1) {
						return;
					}
					var tmp = next[idx + 1];
					next[idx + 1] = next[idx];
					next[idx] = tmp;
					setDraftRows(next);
				},
			});
			downBtn.appendChild(
				el('span', {
					className: 'dashicons dashicons-arrow-down-alt2',
					'aria-hidden': 'true',
				})
			);
			var trashBtn = el('button', {
				type: 'button',
				className:
					'button-link-delete wtt-row-edit-table__trash',
				title: i18n.enumValuesRemove || 'Remove',
				'aria-label': i18n.enumValuesRemove || 'Remove',
				disabled: locked,
				html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
				onClick: function () {
					var next = collectFromDom(tbody);
					next.splice(idx, 1);
					setDraftRows(next);
				},
			});
			tdAct.appendChild(upBtn);
			tdAct.appendChild(downBtn);
			tdAct.appendChild(trashBtn);
			tr.appendChild(tdAct);
			tbody.appendChild(tr);
		});

		table.appendChild(tbody);
		block.appendChild(table);

		var actions = el('div', { className: 'wtt-enum-values__actions' });
		var addBtn = el('button', {
			type: 'button',
			className: 'button',
			text: i18n.enumValuesAdd || 'Add value',
			disabled: locked,
			onClick: function () {
				var next = collectFromDom(tbody);
				next.push('');
				setDraftRows(next);
			},
		});
		var saveBtn = el('button', {
			type: 'button',
			className: 'button button-primary',
			text: state.enumValuesSaving
				? i18n.enumValuesSaving || 'Savingâ€¦'
				: i18n.enumValuesSave || 'Save enum values',
			disabled: locked,
			onClick: function () {
				var next = collectFromDom(tbody)
					.map(function (v) {
						return String(v || '').trim();
					})
					.filter(function (v) {
						return !!v;
					});
				state.enumValuesSaving = true;
				state.error = '';
				render();
				post('wtt_set_enum_values', {
					term_id: n.id,
					values: JSON.stringify(next),
				})
					.then(function (json) {
						state.enumValuesSaving = false;
						state.enumValuesDraft = null;
						state.enumValuesDirty = false;
						return applyRelationMutation(json);
					})
					.catch(function () {
						state.enumValuesSaving = false;
						setError(i18n.error);
						render();
					});
			},
		});
		actions.appendChild(addBtn);
		actions.appendChild(saveBtn);
		block.appendChild(actions);
		pane.appendChild(block);
	}

	function walkTreeNodes(nodes, fn) {
		(nodes || []).forEach(function (node) {
			if (!node) {
				return;
			}
			fn(node);
			if (node.children && node.children.length) {
				walkTreeNodes(node.children, fn);
			}
		});
	}

	function findRelationTypeIdByName(n, name) {
		name = String(name || '')
			.trim()
			.toLowerCase();
		if (!name) {
			return 0;
		}
		var opts = (n && n.relationTypeOptions) || [];
		for (var i = 0; i < opts.length; i++) {
			var o = opts[i];
			if (
				o &&
				String(o.name || '')
					.trim()
					.toLowerCase() === name
			) {
				return parseInt(o.id, 10) || 0;
			}
		}
		var roots = (n && n.relationTypeTree) || [];
		var found = 0;
		function walk(nodes) {
			(nodes || []).forEach(function (node) {
				if (found || !node) {
					return;
				}
				if (
					String(node.name || '')
						.trim()
						.toLowerCase() === name
				) {
					found = parseInt(node.id, 10) || 0;
					return;
				}
				if (node.children && node.children.length) {
					walk(node.children);
				}
			});
		}
		walk(roots);
		return found;
	}

	function isHasTypeRelationRow(row) {
		/* has_type RelationType removed — type_id only. */
		return false;
	}

	function isChildOfRelationRow(row) {
		if (!row) {
			return false;
		}
		return (
			String(row.typeKey || row.type || '')
				.trim()
				.toLowerCase() === 'child_of'
		);
	}

	function isRefScopeRelationRow(row) {
		if (!row) {
			return false;
		}
		return (
			String(row.typeKey || row.type || '')
				.trim()
				.toLowerCase() === 'ref_scope'
		);
	}

	/**
	 * Q123 attribute Bindung — Relation.name is the attribute label.
	 * @param {object|null} row
	 * @param {string} [typeKeyHint]
	 * @returns {boolean}
	 */
	function isAttributeBindingRelationRow(row, typeKeyHint) {
		var key = String(
			typeKeyHint ||
				(row && (row.typeKey || row.type)) ||
				''
		)
			.trim()
			.toLowerCase();
		return (
			key === 'besteht_aus' ||
			key === 'aggregation' ||
			key === 'composition'
		);
	}

	/**
	 * Q125 calc / Q124 defaultvalue_from — Relation.name = consumer attribute name.
	 * @param {object|null} row
	 * @param {string} [typeKeyHint]
	 * @returns {boolean}
	 */
	function isDefaultValueFromRelationRow(row, typeKeyHint) {
		var key = String(
			typeKeyHint ||
				(row && (row.typeKey || row.type)) ||
				''
		)
			.trim()
			.toLowerCase();
		if (key === 'defaultvalue_from' || key === 'calc') {
			return true;
		}
		var op = String(
			(row && row.calcOp) ||
				(row &&
					row.settings &&
					row.settings.data &&
					row.settings.data.op) ||
				''
		)
			.trim()
			.toLowerCase();
		return key === 'calc' && (op === '' || op === 'default_from');
	}

	/**
	 * Display label for RelationType (calc → Calculation).
	 * @param {object|null} row
	 * @param {object|null} [typeOpt]
	 * @returns {string}
	 */
	function relationTypeDisplayLabel(row, typeOpt) {
		if (typeOpt && typeOpt.label) {
			return String(typeOpt.label);
		}
		if (row && row.typeLabel) {
			return String(row.typeLabel);
		}
		var key = String(
			(row && (row.typeKey || row.typeName || row.type)) ||
				(typeOpt && typeOpt.name) ||
				''
		)
			.trim()
			.toLowerCase();
		if (key === 'calc' || key === 'defaultvalue_from') {
			return (i18n && i18n.relationTypeCalc) || 'Calculation';
		}
		return (
			(row && (row.typeName || row.typeKey)) ||
			(typeOpt && typeOpt.name) ||
			''
		);
	}

	/**
	 * Relation.name required for attribute Bindungen and defaultvalue_from.
	 * @param {object|null} row
	 * @param {string} [typeKeyHint]
	 * @returns {boolean}
	 */
	function relationTypeRequiresName(row, typeKeyHint) {
		return (
			isAttributeBindingRelationRow(row, typeKeyHint) ||
			isDefaultValueFromRelationRow(row, typeKeyHint)
		);
	}

	function isRelationMultiplicityLocked(row) {
		return (
			isChildOfRelationRow(row) ||
			isRefScopeRelationRow(row)
		);
	}

	/**
	 * To endpoint is pickable except child_of (reparent) and when To is this node.
	 */
	function canPickRelationTarget(row, opts) {
		opts = opts || {};
		if (!opts.editable || !row) {
			return false;
		}
		if (isChildOfRelationRow(row)) {
			return false;
		}
		if (row.direction === 'an') {
			return false;
		}
		if (isRefScopeRelationRow(row)) {
			return true;
		}
		return !!(row.stored && row.id);
	}

	/**
	 * Interim Relations for Node UI (Q54): derive from WP parent, type, ref_scope, children.
	 * von = from=this; an = to=this.
	 *
	 * @return {{ von: Array<{type:string,otherId:number,otherName:string,notes:string,protected:boolean}>, an: Array<...> }}
	 */
	function collectSyntheticRelations(n) {
		var von = [];
		var an = [];
		var selfId = parseInt(n.id, 10) || 0;
		var parentId = parseInt(n.parent, 10) || 0;

		if (parentId > 0) {
			von.push({
				type: 'child_of',
				otherId: parentId,
				otherName: n.parentName || String(parentId),
				multiplicity: '1',
				notes: i18n.relationsProtected || 'protected â€” reparent only',
				protected: true,
			});
		}


		if (n.refScopeId) {
			von.push({
				type: 'ref_scope',
				typeKey: 'ref_scope',
				typeId: findRelationTypeIdByName(n, 'ref_scope'),
				otherId: parseInt(n.refScopeId, 10) || 0,
				otherName:
					(n.refScope && n.refScope.name) ||
					(function () {
						var scope = findNodeInTree(state.tree, n.refScopeId);
						return scope ? scope.name : String(n.refScopeId);
					})(),
				multiplicity: '0..1',
				notes:
					i18n.relationsRefScopeNote ||
					'Catalog root â€” click To to change',
				protected: true,
				synthetic: true,
				stored: false,
			});
		}

		if (n.prefixAllowlist && Array.isArray(n.prefixAllowlist.prefixes)) {
			n.prefixAllowlist.prefixes.forEach(function (p) {
				if (!p || !p.enabled || !p.id) {
					return;
				}
				von.push({
					type: 'allows_prefix',
					otherId: parseInt(p.id, 10) || 0,
					otherName: p.name || String(p.id),
					multiplicity: '0..*',
					notes: '',
					protected: false,
				});
			});
		}

		var treeNode = findNodeInTree(state.tree, selfId);
		(treeNode && treeNode.children ? treeNode.children : []).forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			an.push({
				type: 'child_of',
				otherId: parseInt(child.id, 10) || 0,
				otherName: child.name || String(child.id),
				multiplicity: '1',
				notes: i18n.relationsProtected || 'protected â€” reparent only',
				protected: true,
			});
		});

		walkTreeNodes(state.tree, function (node) {
			var nid = parseInt(node.id, 10) || 0;
			if (!nid || nid === selfId) {
				return;
			}
			if ((parseInt(node.refScopeId, 10) || 0) === selfId) {
				an.push({
					type: 'ref_scope',
					otherId: nid,
					otherName: node.name || String(nid),
					multiplicity: '0..*',
					notes: i18n.relationsRefScopeNote || 'derived â€” change Catalog root',
					protected: true,
					stored: false,
				});
			}
		});

		var stored = n.relationsStored || {};
		(stored.von || []).forEach(function (row) {
			if (row) {
				von.push(
					Object.assign({}, row, {
						stored: true,
						protected: !!row.protected,
					})
				);
			}
		});
		(stored.an || []).forEach(function (row) {
			if (row) {
				an.push(
					Object.assign({}, row, {
						stored: true,
						protected: !!row.protected,
					})
				);
			}
		});

		return { von: von, an: an };
	}

	function applyRelationMutation(json) {
		if (!json || !json.success) {
			setError((json && json.data && json.data.message) || i18n.error);
			return;
		}
		if (json.data.tree) {
			state.tree = json.data.tree;
		}
		if (json.data.node) {
			applyLoadedNode(json.data.node);
			/* Reseed Unit/Quantity Preferred preview from new attribute Defaults. */
			var nid = parseInt(json.data.node.id, 10) || 0;
			if (nid > 0) {
				delete state.previewValues['obj-qty::' + String(nid)];
				delete state.previewValues['obj-qty::qty'];
				Object.keys(state.previewValues || {}).forEach(function (k) {
					if (String(k).indexOf('obj-qty') === 0) {
						delete state.previewValues[k];
					}
				});
			}
		}
		render();
	}

	function assignableRelationTypes(n) {
		var opts = (n && n.relationTypeOptions) || [];
		var out = [];
		opts.forEach(function (o) {
			if (!o || o.protected) {
				return;
			}
			var id = parseInt(o.id, 10) || 0;
			if (!id) {
				return;
			}
			out.push({
				id: id,
				name: String(o.name || ''),
				path: String(o.path || o.name || ''),
			});
		});
		return out;
	}

	function relationEdgeEndpoints(n, row, direction) {
		var selfId = parseInt(n.id, 10) || 0;
		var partnerId = parseInt(row && row.otherId, 10) || 0;
		if (direction === 'an') {
			return { viewId: selfId, fromId: partnerId, toId: selfId };
		}
		return { viewId: selfId, fromId: selfId, toId: partnerId };
	}

	function refreshViewedNode(viewId) {
		return post('wtt_get_node', { term_id: viewId }).then(function (nodeJson) {
			if (nodeJson && nodeJson.success && nodeJson.data && nodeJson.data.node) {
				applyLoadedNode(nodeJson.data.node);
			}
			render();
		});
	}

	function updateStoredRelationType(n, row, typeId, direction) {
		var ends = relationEdgeEndpoints(n, row, direction || 'von');
		var edgeId = row && row.id ? String(row.id) : '';
		typeId = parseInt(typeId, 10) || 0;
		if (!ends.fromId || !edgeId || !typeId) {
			return;
		}
		if (typeId === (parseInt(row.typeId, 10) || 0)) {
			return;
		}
		post('wtt_update_relation_type', {
			term_id: ends.fromId,
			edge_id: edgeId,
			type_id: typeId,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					render();
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				return refreshViewedNode(ends.viewId);
			})
			.catch(function () {
				setError(i18n.error);
				render();
			});
	}

	function updateStoredRelationMultiplicity(n, row, multiplicity, direction) {
		var ends = relationEdgeEndpoints(n, row, direction || 'von');
		var edgeId = row && row.id ? String(row.id) : '';
		multiplicity = String(multiplicity || '').trim();
		if (!ends.fromId || !edgeId || !multiplicity) {
			return;
		}
		if (multiplicity === String(row.multiplicity || '')) {
			return;
		}
		post('wtt_update_relation_multiplicity', {
			term_id: ends.fromId,
			edge_id: edgeId,
			multiplicity: multiplicity,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					render();
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				return refreshViewedNode(ends.viewId);
			})
			.catch(function () {
				setError(i18n.error);
				render();
			});
	}

	function updateStoredRelationName(n, row, name, direction) {
		var ends = relationEdgeEndpoints(n, row, direction || 'von');
		var edgeId = row && row.id ? String(row.id) : '';
		name = String(name || '').trim();
		if (!ends.fromId || !edgeId) {
			return;
		}
		if (name === String(row.name || '').trim()) {
			return;
		}
		if (relationTypeRequiresName(row) && !name) {
			setError(
				isDefaultValueFromRelationRow(row)
					? i18n.relationsCalcNameRequired ||
							i18n.relationsDefaultValueFromNameRequired ||
							'Calculation requires a name (consumer attribute for default_from).'
					: i18n.relationsNameRequired ||
							'Attribute relations (besteht_aus / aggregation) require a name.'
			);
			render();
			return;
		}
		post('wtt_update_relation_name', {
			term_id: ends.fromId,
			edge_id: edgeId,
			name: name,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					render();
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				return refreshViewedNode(ends.viewId);
			})
			.catch(function () {
				setError(i18n.error);
				render();
			});
	}

	function updateRelationTarget(n, row, newToId) {
		var selfId = parseInt(n.id, 10) || 0;
		newToId = parseInt(newToId, 10) || 0;
		if (!selfId || !newToId || newToId === selfId) {
			return;
		}
		var currentTo = parseInt(row.toId || row.otherId, 10) || 0;
		if (newToId === currentTo) {
			return;
		}
		var payload = {
			term_id: selfId,
			to_id: newToId,
			edge_id: row.stored && row.id ? String(row.id) : '',
			type_id: parseInt(row.typeId, 10) || 0,
			type_key: String(row.typeKey || row.type || '')
				.trim()
				.toLowerCase(),
		};
		post('wtt_update_relation_to', payload)
			.then(applyRelationMutation)
			.catch(function () {
				setError(i18n.error);
			});
	}

	function openChangeRelationTarget(n, row) {
		var selfId = parseInt(n.id, 10) || 0;
		var currentTo = parseInt(row.toId || row.otherId, 10) || 0;
		var typeId = parseInt(row.typeId, 10) || 0;
		var isHasType = isHasTypeRelationRow(row);
		var isRefScope = isRefScopeRelationRow(row);
		var blocked = {};
		if (!isHasType && !isRefScope && typeId) {
			blocked = blockedToIdsForType(n, typeId);
			delete blocked[String(currentTo)];
		}
		var typeIdMap = assignableTypeIds(
			Array.isArray(n.typeOptions) ? n.typeOptions : []
		);
		openNodeTreePickerDialog(
			{
				roots: isHasType ? datatypePickerRoots(n) : state.tree,
				selectedId: currentTo,
				focusId: isHasType ? currentTo : selfId,
				currentId: selfId,
				allowRoot: false,
				allowClear: false,
				expandKey:
					'chg-rel-to:' +
					String(selfId) +
					':' +
					String(row.id || row.type || '') +
					':' +
					String(currentTo),
				dialogTitle: isHasType
					? i18n.relationsPickHasTypeTarget ||
					  'Choose type node'
					: i18n.relationsChangeTarget || 'Change target node',
				placeholder: isHasType
					? i18n.relationsPickHasTypeTarget ||
					  'Choose type node'
					: i18n.relationsPickTarget || 'Choose target node',
				blockedIds: isHasType || isRefScope ? {} : blocked,
				selectable: function (node) {
					var id = node && node.id ? parseInt(node.id, 10) || 0 : 0;
					if (!id || id === selfId) {
						return false;
					}
					if (isHasType) {
						if (typeIdMap[String(id)]) {
							return true;
						}
						return attributeTypeSelectable(node, typeIdMap);
					}
					if (isRefScope) {
						return true;
					}
					return !blocked[String(id)];
				},
			},
			function (toId) {
				updateRelationTarget(n, row, toId);
			}
		);
	}

	function renderRelationTypeCell(n, row, opts) {
		opts = opts || {};
		var canEdit =
			!!opts.editable &&
			row.stored &&
			!isRelationRowLocked(row) &&
			row.id &&
			typeof opts.onTypeChange === 'function';
		if (!canEdit) {
			return el('td', {
				className: 'wtt-relations__type',
				text: relationTypeDisplayLabel(row) || row.type || '—',
			});
		}

		var currentId = parseInt(row.typeId, 10) || 0;
		var fromId =
			parseInt(row.fromId, 10) ||
			(row.direction === 'an'
				? parseInt(row.otherId, 10) || 0
				: parseInt(n && n.id, 10) || 0);
		var toId =
			parseInt(row.toId, 10) ||
			(row.direction === 'an'
				? parseInt(n && n.id, 10) || 0
				: parseInt(row.otherId, 10) || 0);
		var types = filterRelationTypesForPair(
			opts.relationTypes || [],
			n,
			fromId,
			toId,
			currentId
		);
		var hasCurrent = false;
		types.forEach(function (t) {
			if (t.id === currentId) {
				hasCurrent = true;
			}
		});
		if (!hasCurrent && currentId && row.type) {
			types = types.slice();
			types.unshift({
				id: currentId,
				name: row.typeKey || row.type,
				label: row.typeLabel || row.type,
				path: row.type,
			});
		}

		var select = el('select', {
			className: 'wtt-relations__type-select',
			title: i18n.relationsTypeHint || 'Relation type (not a node)',
			onChange: function (e) {
				opts.onTypeChange(row, e.target.value);
			},
		});
		if (!types.length) {
			select.appendChild(
				el('option', {
					value: String(currentId || ''),
					text: relationTypeDisplayLabel(row) || row.type || '—',
					selected: true,
				})
			);
		} else {
			types.forEach(function (t) {
				select.appendChild(
					el('option', {
						value: String(t.id),
						text: relationTypeDisplayLabel(row, t) || t.name || String(t.id),
						title: t.path || t.name || '',
						selected: t.id === currentId,
					})
				);
			});
		}
		var td = el('td', { className: 'wtt-relations__type' });
		td.appendChild(select);
		return td;
	}

	function renderRelationEndpointCell(selfId, nodeId, nodeName, opts) {
		opts = opts || {};
		var id = parseInt(nodeId, 10) || 0;
		var td = el('td', { className: 'wtt-relations__endpoint' });
		if (id && id === selfId) {
			td.appendChild(
				el('span', {
					className: 'wtt-relations__current',
					text: nodeName || String(id),
					title:
						i18n.relationsThisHint ||
						'Current node (this endpoint of the relation)',
				})
			);
			return td;
		}
		if (opts.canPick && typeof opts.onPick === 'function') {
			td.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-relations__link wtt-relations__target-pick',
					text: nodeName || String(id) || 'â€”',
					title:
						i18n.relationsChangeTarget ||
						'Change target node',
					onClick: function () {
						opts.onPick();
					},
				})
			);
			return td;
		}
		if (id) {
			td.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-relations__link',
					text: nodeName || String(id),
					onClick: function () {
						selectNode(id);
					},
				})
			);
			return td;
		}
		td.appendChild(document.createTextNode(nodeName || 'â€”'));
		return td;
	}

	function relationMultiplicityOptions(n) {
		if (n && Array.isArray(n.relationMultiplicityOptions) && n.relationMultiplicityOptions.length) {
			return n.relationMultiplicityOptions;
		}
		return [
			{ value: '0..1', label: '0..1' },
			{ value: '1', label: '1' },
			{ value: '0..*', label: '0..*' },
			{ value: '1..*', label: '1..*' },
		];
	}

	function renderRelationNameCell(n, row, opts) {
		opts = opts || {};
		var td = el('td', { className: 'wtt-relations__name' });
		var needsName = relationTypeRequiresName(row);
		var isDefaultFrom = isDefaultValueFromRelationRow(row);
		var isParked = isParkedTableBandRelationRow(row);
		var current = String(row.name || '');
		var nameHint = isDefaultFrom
			? i18n.relationsDefaultValueFromNameHint ||
				'Consumer attribute name (calc default_from)'
			: i18n.relationsNameHint || 'Attribute label (Relation.name)';
		var canEdit =
			!!opts.editable &&
			needsName &&
			!isParked &&
			row.stored &&
			!isRelationRowLocked(row) &&
			row.id &&
			typeof opts.onNameChange === 'function';
		if (!canEdit) {
			if (isParked) {
				var parkedWrap = el('span', {
					className: 'wtt-relations__parked-name',
				});
				parkedWrap.appendChild(
					document.createTextNode(current || row.toName || row.otherName || '—')
				);
				parkedWrap.appendChild(document.createTextNode(' '));
				parkedWrap.appendChild(
					el('span', {
						className: 'wtt-badge wtt-relations__parked-badge',
						text:
							i18n.relationsParkedBandBadge ||
							'Q90 parked',
						title:
							row.notes ||
							i18n.relationsParkedBandHint ||
							'Legacy table band (Zeile/Kopf/Fuss) — not a product attribute',
					})
				);
				td.appendChild(parkedWrap);
			} else if (needsName && current) {
				td.appendChild(document.createTextNode(current));
			} else if (needsName) {
				td.appendChild(
					el('span', {
						className: 'wtt-field-hint',
						text: '—',
						title: nameHint,
					})
				);
			} else {
				td.appendChild(
					el('span', {
						className: 'wtt-field-hint',
						text: '—',
						title:
							i18n.relationsNameOptionalHint ||
							'Name is optional for this relation type',
					})
				);
			}
			return td;
		}
		var nameInput = el('input', {
			type: 'text',
			className: 'regular-text wtt-relations__name-input',
			value: current,
			title: nameHint,
			onChange: function (e) {
				nameInput._pending = e.target.value;
			},
			onBlur: function () {
				var next = String(
					nameInput._pending != null
						? nameInput._pending
						: nameInput.value || ''
				).trim();
				if (next === current) {
					return;
				}
				opts.onNameChange(row, next);
			},
		});
		td.appendChild(nameInput);
		return td;
	}

	function renderRelationMultiplicityCell(n, row, opts) {
		opts = opts || {};
		var locked = isRelationMultiplicityLocked(row);
		var current;
		if (isChildOfRelationRow(row)) {
			current = '1';
		} else if (locked) {
			current = String(row.multiplicity || '0..1');
		} else {
			current = String(row.multiplicity || '0..*');
		}
		var canEdit =
			!!opts.editable &&
			row.stored &&
			!isRelationRowLocked(row) &&
			!locked &&
			row.id &&
			typeof opts.onMultiplicityChange === 'function';
		if (!canEdit) {
			return el('td', {
				className: 'wtt-relations__mult',
				text: current,
				title:
					locked || row.synthetic || isRelationRowLocked(row)
						? i18n.relationsMultLockedHint ||
						  'Locked: child_of is always 1; ref_scope are always 0..1.'
						: i18n.relationsMultHint || '',
			});
		}
		var select = el('select', {
			className: 'wtt-relations__mult-select',
			title: i18n.relationsMultHint || 'Definition multiplicity',
			onChange: function (e) {
				opts.onMultiplicityChange(row, e.target.value);
			},
		});
		relationMultiplicityOptions(n).forEach(function (opt) {
			select.appendChild(
				el('option', {
					value: opt.value,
					text: opt.value,
					title: opt.label || opt.value,
					selected: opt.value === current,
				})
			);
		});
		var td = el('td', { className: 'wtt-relations__mult' });
		td.appendChild(select);
		return td;
	}

	/**
	 * Always: From node â†’ Relation type â†’ To node. Current node shown by name.
	 */
	function renderRelationsTable(rows, opts) {
		opts = opts || {};
		var emptyText = i18n.relationsEmpty || 'None';
		var editable = !!opts.editable;
		var allowReorder = editable && opts.allowReorder !== false;
		var canDupType = typeof opts.onDuplicateAsType === 'function';
		var showActions = editable || canDupType;
		var selfId = opts.node ? parseInt(opts.node.id, 10) || 0 : 0;

		if (!rows || !rows.length) {
			return el('p', { className: 'wtt-field-hint', text: emptyText });
		}

		var table = el('table', { className: 'wtt-relations__table' });
		var thead = el('thead');
		var headRow = el('tr');
		headRow.appendChild(
			el('th', { text: i18n.relationsFrom || 'From', scope: 'col' })
		);
		headRow.appendChild(
			el('th', {
				text: i18n.relationsType || 'Relation type',
				scope: 'col',
			})
		);
		headRow.appendChild(
			el('th', {
				text: i18n.relationsName || 'Name',
				scope: 'col',
				title:
					i18n.relationsNameHint ||
					'Attribute label (Relation.name) for besteht_aus / aggregation',
			})
		);
		headRow.appendChild(
			el('th', { text: i18n.relationsTo || 'To', scope: 'col' })
		);
		headRow.appendChild(
			el('th', {
				text: i18n.relationsMult || 'Mult.',
				scope: 'col',
				title: i18n.relationsMultHint || '',
			})
		);
		if (showActions) {
			headRow.appendChild(
				el('th', {
					text: '',
					scope: 'col',
					className: 'wtt-relations__actions-h',
				})
			);
		}
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		rows.forEach(function (row, rowIndex) {
			var tr = el('tr', {
				className:
					(isRelationRowLocked(row) ? 'wtt-relations__row--protected' : '') +
					(isParkedTableBandRelationRow(row)
						? ' wtt-relations__row--parked-band'
						: '') +
					(row.stored ? ' wtt-relations__row--stored' : '') +
					(row.direction === 'an' ? ' wtt-relations__row--an' : ''),
				title: isParkedTableBandRelationRow(row)
					? row.notes ||
					  i18n.relationsParkedBandHint ||
					  'Legacy table band (Zeile/Kopf/Fuss) — not a product attribute'
					: '',
			});
			tr.appendChild(
				renderRelationEndpointCell(selfId, row.fromId, row.fromName)
			);
			tr.appendChild(renderRelationTypeCell(opts.node || null, row, opts));
			tr.appendChild(renderRelationNameCell(opts.node || null, row, opts));
			tr.appendChild(
				renderRelationEndpointCell(selfId, row.toId, row.toName, {
					canPick:
						canPickRelationTarget(row, opts) &&
						!isParkedTableBandRelationRow(row),
					onPick: function () {
						if (typeof opts.onTargetChange === 'function') {
							opts.onTargetChange(row);
						}
					},
				})
			);
			tr.appendChild(
				renderRelationMultiplicityCell(opts.node || null, row, opts)
			);
			if (showActions) {
				var act = el('td', { className: 'wtt-relations__actions' });
				var actGroup = el('div', { className: 'wtt-relations__action-group' });
				var canEditStored =
					editable &&
					row.stored &&
					!isRelationRowLocked(row) &&
					row.id &&
					row.typeId &&
					(row.fromId || row.toId);
				var canRemoveHasType = false;
				var canRemoveChildOf =
					editable &&
					isDevelopmentMode() &&
					isChildOfRelationRow(row) &&
					row.direction !== 'an' &&
					!!(opts.node && opts.node.id);
				var canRemove = canEditStored || canRemoveHasType || canRemoveChildOf;
				var canDup =
					canDupType &&
					!isHasTypeRelationRow(row) &&
					!isParkedTableBandRelationRow(row) &&
					!!(parseInt(row.fromId, 10) || parseInt(row.toId, 10));
				var canReorderRow =
					canEditStored &&
					allowReorder &&
					row.direction === 'von' &&
					!isParkedTableBandRelationRow(row);
				if (canReorderRow) {
					var reorderPeers = rows.filter(function (r) {
						return (
							r &&
							r.direction === 'von' &&
							r.stored &&
							!r.protected &&
							r.id
						);
					});
					var peerIndex = -1;
					reorderPeers.forEach(function (r, i) {
						if (r === row || (r.id && r.id === row.id)) {
							peerIndex = i;
						}
					});
					var canUp =
						typeof row.canMoveUp === 'boolean'
							? row.canMoveUp
							: peerIndex > 0;
					var canDown =
						typeof row.canMoveDown === 'boolean'
							? row.canMoveDown
							: peerIndex >= 0 &&
								peerIndex < reorderPeers.length - 1;
					actGroup.appendChild(
						el('button', {
							type: 'button',
							className: 'button button-small wtt-relations__icon-btn',
							title: i18n.relationsMoveUp || 'Move up',
							'aria-label': i18n.relationsMoveUp || 'Move up',
							disabled: !canUp,
							html: '<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>',
							onClick: function () {
								if (typeof opts.onMove === 'function') {
									opts.onMove(row, -1);
								}
							},
						})
					);
					actGroup.appendChild(
						el('button', {
							type: 'button',
							className: 'button button-small wtt-relations__icon-btn',
							title: i18n.relationsMoveDown || 'Move down',
							'aria-label': i18n.relationsMoveDown || 'Move down',
							disabled: !canDown,
							html: '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>',
							onClick: function () {
								if (typeof opts.onMove === 'function') {
									opts.onMove(row, 1);
								}
							},
						})
					);
				}
				if (canDup) {
					actGroup.appendChild(
						el('button', {
							type: 'button',
							className: 'button button-small wtt-relations__icon-btn',
							title:
								i18n.relationsDuplicateAsType ||
								'Duplicate with another relation type',
							'aria-label':
								i18n.relationsDuplicateAsType ||
								'Duplicate with another relation type',
							html: '<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>',
							onClick: function () {
								opts.onDuplicateAsType(row);
							},
						})
					);
				}
				if (canRemove) {
					actGroup.appendChild(
						el('button', {
							type: 'button',
							className:
								'button button-small wtt-relations__icon-btn wtt-relations__icon-btn--danger',
							title: i18n.relationsRemove || 'Remove relation',
							'aria-label': i18n.relationsRemove || 'Remove relation',
							html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
							onClick: function () {
								if (typeof opts.onRemove === 'function') {
									opts.onRemove(row);
								}
							},
						})
					);
				}
				if (actGroup.childNodes.length) {
					act.appendChild(actGroup);
				}
				tr.appendChild(act);
			}
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		return table;
	}

	function relationTypePickerRoots(n) {
		if (n && Array.isArray(n.relationTypeTree) && n.relationTypeTree.length) {
			return n.relationTypeTree;
		}
		var folder = findNamedInTree(state.tree, 'Relationstypen');
		return folder ? [folder] : [];
	}

	/**
	 * Type ids already used for From â†’ To (stored edges only).
	 * @return Object.<string, boolean>
	 */
	function usedRelationTypeIdsForPair(n, fromId, toId) {
		var used = {};
		var selfId = parseInt(n && n.id, 10) || 0;
		fromId = parseInt(fromId, 10) || 0;
		toId = parseInt(toId, 10) || 0;
		if (!selfId || !fromId || !toId) {
			return used;
		}
		var stored = (n && n.relationsStored) || {};
		if (fromId === selfId) {
			(stored.von || []).forEach(function (row) {
				if (!row) {
					return;
				}
				if ((parseInt(row.otherId, 10) || 0) !== toId) {
					return;
				}
				var tid = parseInt(row.typeId, 10) || 0;
				if (tid) {
					used[String(tid)] = true;
				}
			});
			return used;
		}
		if (toId === selfId) {
			(stored.an || []).forEach(function (row) {
				if (!row) {
					return;
				}
				if ((parseInt(row.otherId, 10) || 0) !== fromId) {
					return;
				}
				var tid = parseInt(row.typeId, 10) || 0;
				if (tid) {
					used[String(tid)] = true;
				}
			});
		}
		return used;
	}

	/** Targets already linked from self with this RelationType. */
	function blockedToIdsForType(n, typeId) {
		var blocked = {};
		var selfId = parseInt(n && n.id, 10) || 0;
		typeId = parseInt(typeId, 10) || 0;
		if (selfId) {
			blocked[String(selfId)] = true;
		}
		if (!typeId) {
			return blocked;
		}
		var von = (n && n.relationsStored && n.relationsStored.von) || [];
		von.forEach(function (row) {
			if (!row) {
				return;
			}
			if ((parseInt(row.typeId, 10) || 0) !== typeId) {
				return;
			}
			var oid = parseInt(row.otherId, 10) || 0;
			if (oid) {
				blocked[String(oid)] = true;
			}
		});
		return blocked;
	}

	function filterRelationTypesForPair(types, n, fromId, toId, keepTypeId) {
		var used = usedRelationTypeIdsForPair(n, fromId, toId);
		keepTypeId = parseInt(keepTypeId, 10) || 0;
		return (types || []).filter(function (t) {
			var id = parseInt(t && t.id, 10) || 0;
			if (!id) {
				return false;
			}
			if (keepTypeId && id === keepTypeId) {
				return true;
			}
			return !used[String(id)];
		});
	}

	function openAddRelationFlow(n) {
		var selfId = parseInt(n.id, 10) || 0;
		var typeRoots = relationTypePickerRoots(n);
		if (!typeRoots.length) {
			setError(
				i18n.relationsNoTypes ||
					'No Relationstypen found. Reload the page or reset the demo tree.'
			);
			return;
		}
		var freeTypeLocked = !!(n && (n.freeTypeLocked || n.typeIsParent));
		openNodeTreePickerDialog(
			{
				roots: typeRoots,
				selectedId: 0,
				allowRoot: false,
				allowClear: false,
				expandKey: 'add-rel-type:' + String(selfId),
				dialogTitle: i18n.relationsPickType || 'Choose relation type',
				placeholder: i18n.relationsPickType || 'Choose relation type',
				selectable: function (node) {
					if (!node || node.protected) {
						return false;
					}
					var name = String(node.name || '').toLowerCase();
					if (name === 'child_of' || name === 'relationstypen') {
						return false;
					}
					if (name === 'defaultvalue_from') {
						return false;
					}
					/* Q88 / root seed: no free has_type assign from Relations. */
					if (name === 'has_type') {
						return false;
					}
					return !node.hasChildren;
				},
			},
			function (typeId) {
				typeId = parseInt(typeId, 10) || 0;
				if (!typeId) {
					return;
				}
				var typeName = '';
				(n.relationTypeOptions || []).forEach(function (o) {
					if (o && parseInt(o.id, 10) === typeId) {
						typeName = String(o.name || '').toLowerCase();
					}
				});
				var isHasType = typeName === 'has_type';
				var isAttrBinding = isAttributeBindingRelationRow(null, typeName);
				var isDefaultFrom = isDefaultValueFromRelationRow(null, typeName);
				var needsEdgeName = relationTypeRequiresName(null, typeName);
				/* Named edges may share the same To when names differ (Q123 / Q124). */
				var blocked = needsEdgeName ? {} : blockedToIdsForType(n, typeId);
				if (needsEdgeName && selfId) {
					blocked[String(selfId)] = true;
				}
				var typeIdMap = assignableTypeIds(
					Array.isArray(n.typeOptions) ? n.typeOptions : []
				);
				openNodeTreePickerDialog(
					{
						roots: isHasType ? datatypePickerRoots(n) : state.tree,
						selectedId: 0,
						focusId: isHasType ? 0 : selfId,
						currentId: selfId,
						allowRoot: false,
						allowClear: false,
						expandKey: 'add-rel-to:' + String(selfId) + ':' + String(typeId),
						dialogTitle: isHasType
							? i18n.relationsPickHasTypeTarget ||
							  'Choose type node'
							: i18n.relationsPickTarget || 'Choose target node',
						placeholder: isHasType
							? i18n.relationsPickHasTypeTarget ||
							  'Choose type node'
							: i18n.relationsPickTarget || 'Choose target node',
						blockedIds: blocked,
						selectable: function (node) {
							var id = node && node.id ? parseInt(node.id, 10) || 0 : 0;
							if (!id || id === selfId) {
								return false;
							}
							if (isHasType) {
								if (typeIdMap[String(id)]) {
									return true;
								}
								return attributeTypeSelectable(node, typeIdMap);
							}
							if (isAttrBinding) {
								return true;
							}
							return !blocked[String(id)];
						},
					},
					function (toId) {
						toId = parseInt(toId, 10) || 0;
						if (!toId) {
							return;
						}
						if (!isHasType && !needsEdgeName && blocked[String(toId)]) {
							setError(
								i18n.relationsDuplicateExists ||
									'This relation already exists (same From, Relation type, and To).'
							);
							return;
						}
						var edgeName = '';
						if (needsEdgeName) {
							var prompted = window.prompt(
								isDefaultFrom
									? i18n.relationsDefaultValueFromNamePrompt ||
											'Consumer attribute name (e.g. Bauart)'
									: i18n.relationsNamePrompt ||
											'Attribute name (Relation.name)',
								''
							);
							if (prompted === null) {
								return;
							}
							edgeName = String(prompted || '').trim();
							if (!edgeName) {
								setError(
									isDefaultFrom
										? i18n.relationsCalcNameRequired ||
												i18n.relationsDefaultValueFromNameRequired ||
												'Calculation requires a name (consumer attribute for default_from).'
										: i18n.relationsNameRequired ||
												'Attribute relations (besteht_aus / aggregation) require a name.'
								);
								return;
							}
						}
						var payload = {
							term_id: selfId,
							type_id: typeId,
							to_id: toId,
							multiplicity: isHasType
								? '0..1'
								: isDefaultFrom
									? '0..1'
									: undefined,
						};
						if (edgeName) {
							payload.name = edgeName;
						}
						post('wtt_add_relation', payload)
							.then(function (json) {
								if (!json || !json.success) {
									setError(
										(json && json.data && json.data.message) ||
											i18n.error
									);
									return;
								}
								applyRelationMutation(json);
							})
							.catch(function () {
								setError(i18n.error);
							});
					}
				);
			}
		);
	}

	function removeStoredRelation(n, row, direction) {
		var ends = relationEdgeEndpoints(n, row, direction || 'von');
		var typeId = parseInt(row.typeId, 10) || 0;
		var edgeId = row.id ? String(row.id) : '';
		if (isHasTypeRelationRow(row) && !typeId) {
			typeId = findRelationTypeIdByName(n, 'has_type');
		}

		/* Development mode: removing child_of = reparent to root (no parent). */
		if (isChildOfRelationRow(row) && isDevelopmentMode()) {
			var nodeId = parseInt(n && n.id, 10) || ends.fromId || 0;
			if (!nodeId) {
				return;
			}
			var msgChild =
				i18n.relationsRemoveChildOfConfirm ||
				'Remove child_of (move this node to the root)? Development mode only.';
			if (!window.confirm(msgChild)) {
				return;
			}
			post('wtt_reparent_term', {
				term_id: nodeId,
				parent_id: 0,
			})
				.then(function (json) {
					if (!json || !json.success) {
						setError((json && json.data && json.data.message) || i18n.error);
						return;
					}
					if (json.data.tree) {
						state.tree = json.data.tree;
					}
					return refreshViewedNode(nodeId);
				})
				.catch(function () {
					setError(i18n.error);
				});
			return;
		}

		if (!ends.fromId || (!edgeId && (!typeId || !ends.toId))) {
			return;
		}
		var msg = i18n.relationsRemoveConfirm || 'Remove this relation?';
		if (!window.confirm(msg)) {
			return;
		}
		post('wtt_remove_relation', {
			term_id: ends.fromId,
			type_id: typeId,
			to_id: ends.toId,
			edge_id: edgeId,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				return refreshViewedNode(ends.viewId);
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	/**
	 * Same partner Node, pick another Relation type.
	 * direction 'von': self â†’ partner; 'an': partner â†’ self (edge stored on partner).
	 */
	function duplicateRelationWithOtherType(n, row, direction) {
		var selfId = parseInt(n.id, 10) || 0;
		var partnerId = parseInt(row && row.otherId, 10) || 0;
		var currentTypeId = parseInt(row && row.typeId, 10) || 0;
		var isIncoming = direction === 'an';
		if (!selfId || !partnerId) {
			return;
		}
		var fromId = isIncoming ? partnerId : selfId;
		var toId = isIncoming ? selfId : partnerId;
		var usedTypes = usedRelationTypeIdsForPair(n, fromId, toId);
		var typeRoots = relationTypePickerRoots(n);
		if (!typeRoots.length) {
			setError(
				i18n.relationsNoTypes ||
					'No Relationstypen found. Reload the page or reset the demo tree.'
			);
			return;
		}
		openNodeTreePickerDialog(
			{
				roots: typeRoots,
				selectedId: 0,
				allowRoot: false,
				allowClear: false,
				expandKey:
					'dup-rel-type:' +
					String(direction || 'von') +
					':' +
					String(fromId) +
					':' +
					String(toId),
				dialogTitle:
					i18n.relationsDuplicatePickType ||
					'Choose relation type for the same connection',
				placeholder:
					i18n.relationsDuplicatePickType ||
					'Choose relation type for the same connection',
				selectable: function (node) {
					if (!node || node.protected) {
						return false;
					}
					var name = String(node.name || '').toLowerCase();
					if (
						name === 'child_of' ||
						name === 'has_type' ||
						name === 'ref_scope' ||
						name === 'relationstypen'
					) {
						return false;
					}
					var id = parseInt(node.id, 10) || 0;
					if (!id || usedTypes[String(id)]) {
						return false;
					}
					return !node.hasChildren;
				},
			},
			function (typeId) {
				typeId = parseInt(typeId, 10) || 0;
				if (!typeId || usedTypes[String(typeId)]) {
					setError(
						i18n.relationsDuplicateExists ||
							'This relation already exists (same From, Relation type, and To).'
					);
					return;
				}
				post('wtt_add_relation', {
					term_id: fromId,
					type_id: typeId,
					to_id: toId,
				})
					.then(function (json) {
						if (!json || !json.success) {
							setError(
								(json && json.data && json.data.message) || i18n.error
							);
							return;
						}
						if (json.data.tree) {
							state.tree = json.data.tree;
						}
						/* Always refresh the node we are viewing (esp. for "an"). */
						return post('wtt_get_node', { term_id: selfId }).then(function (
							nodeJson
						) {
							if (nodeJson && nodeJson.success && nodeJson.data.node) {
								applyLoadedNode(nodeJson.data.node);
							} else if (json.data.node && !isIncoming) {
								applyLoadedNode(json.data.node);
							}
							render();
						});
					})
					.catch(function () {
						setError(i18n.error);
					});
			}
		);
	}

	function moveStoredRelation(n, row, delta) {
		var selfId = parseInt(n.id, 10) || 0;
		var edgeId = row && row.id ? String(row.id) : '';
		if (!selfId || !edgeId || !delta) {
			return;
		}
		post('wtt_move_relation', {
			term_id: selfId,
			edge_id: edgeId,
			delta: delta,
		})
			.then(applyRelationMutation)
			.catch(function () {
				setError(i18n.error);
			});
	}

	/**
	 * Flatten von/an into directed edges: from â†’ type â†’ to (current node = this).
	 */
	function buildDirectedRelationRows(n, rel) {
		var selfId = parseInt(n.id, 10) || 0;
		var selfName = n.name || String(selfId);
		var rows = [];
		(rel.von || []).forEach(function (row) {
			if (!row) {
				return;
			}
			rows.push(
				Object.assign({}, row, {
					direction: 'von',
					fromId: selfId,
					fromName: selfName,
					toId: parseInt(row.otherId, 10) || 0,
					toName: row.otherName || '',
					otherId: parseInt(row.otherId, 10) || 0,
					otherName: row.otherName || '',
				})
			);
		});
		(rel.an || []).forEach(function (row) {
			if (!row) {
				return;
			}
			rows.push(
				Object.assign({}, row, {
					direction: 'an',
					fromId: parseInt(row.otherId, 10) || 0,
					fromName: row.otherName || '',
					toId: selfId,
					toName: selfName,
					otherId: parseInt(row.otherId, 10) || 0,
					otherName: row.otherName || '',
				})
			);
		});
		return rows;
	}

	function renderRelationsSectionTitle(titleText, helpText, extraClass) {
		var wrap = el('div', {
			className:
				'wtt-relations__title-wrap' +
				(extraClass ? ' ' + extraClass : ''),
		});
		wrap.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-relations__title',
				text: titleText,
			})
		);
		var help = renderHelpHint(helpText);
		if (help) {
			wrap.appendChild(help);
		}
		return wrap;
	}

	/**
	 * Fill Model Data deep-link for a structure host.
	 *
	 * @param {number} hostId
	 * @returns {string}
	 */
	function modelDataPageUrl(hostId) {
		hostId = parseInt(hostId, 10) || 0;
		var base =
			cfg.modelDataUrl || 'admin.php?page=wp-taxonomy-tree-model-data';
		var url;
		try {
			url = new URL(base, window.location.origin);
		} catch (e) {
			url = new URL(
				'admin.php?page=wp-taxonomy-tree-model-data',
				window.location.origin
			);
		}
		if (hostId > 0) {
			url.searchParams.set('host_id', String(hostId));
		}
		if (state.taxonomy) {
			url.searchParams.set('taxonomy', String(state.taxonomy));
		}
		return url.toString();
	}

	/**
	 * Linked (N) after a structure-host tree label → Fill Model Data.
	 *
	 * @param {object} node
	 * @returns {HTMLElement|null}
	 */
	function renderModelDataCountLink(node) {
		if (!node || !state.showModelDataCounts) {
			return null;
		}
		if (node.modelDataCount == null && !node.isModelDataHost) {
			return null;
		}
		var hostId = parseInt(node.id, 10) || 0;
		if (!hostId) {
			return null;
		}
		var count = parseInt(node.modelDataCount, 10);
		if (isNaN(count)) {
			count = 0;
		}
		var labelTpl =
			i18n.modelDataCountLink || '%d instances — open Fill Model Data';
		var label = String(labelTpl).replace('%d', String(count));
		return el('a', {
			className: 'wtt-tree__model-count',
			href: modelDataPageUrl(hostId),
			title: label,
			'aria-label': label,
			text: '(' + String(count) + ')',
			onClick: function (e) {
				e.stopPropagation();
			},
		});
	}

	/**
	 * UR-S1: red circle + ! → Model versions focused on this host (click only).
	 *
	 * @param {number} hostId
	 * @param {number} conflictCount
	 * @returns {HTMLElement|null}
	 */
	function renderModelVersionConflictBadge(hostId, conflictCount) {
		hostId = parseInt(hostId, 10) || 0;
		conflictCount = parseInt(conflictCount, 10) || 0;
		if (!hostId || conflictCount <= 0) {
			return null;
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
			i18n.modelVersionConflictCount ||
			'%d model version conflicts — open Conflict resolver';
		var label = labelTpl.replace('%d', String(conflictCount));
		return el('a', {
			className: 'wtt-conflict-badge',
			href: url.toString(),
			title: label,
			'aria-label': label,
			text: '!',
		});
	}

	function renderNodeAttributes(n, pane, controlsLocked) {
		var hostId = parseInt(n.id, 10) || 0;
		if (!hostId) {
			return;
		}
		var attrs = Array.isArray(n.attributes) ? n.attributes : [];
		var editable = !controlsLocked;
		var showInherited = attrs.some(function (a) {
			return !!(a && a.inherited);
		});

		var block = el('div', { className: 'wtt-panel wtt-attributes' });
		var fold = el('details', { className: 'wtt-attributes-fold' });
		if (state.attributesPanelOpen) {
			fold.open = true;
		}

		var summary = el('summary', {
			className: 'wtt-attributes-fold__summary',
		});
		summary.appendChild(
			document.createTextNode(i18n.attributesTitle || 'Attributes')
		);
		if (attrs.length > 0) {
			summary.appendChild(
				el('span', {
					className: 'wtt-badge wtt-attributes-fold__count',
					text: String(attrs.length),
					title:
						i18n.attributesFoldCountHint ||
						'Attribute rows (own + inherited)',
				})
			);
		}
		var conflictBadge = renderModelVersionConflictBadge(
			hostId,
			n.conflictCount
		);
		if (conflictBadge) {
			summary.appendChild(conflictBadge);
		}
		var attrHelp = renderHelpHint(
			i18n.attributesHelp ||
				'Name + type + multiplicity (besteht_aus members).'
		);
		if (attrHelp) {
			summary.appendChild(attrHelp);
		}
		fold.appendChild(summary);

		var body = el('div', { className: 'wtt-attributes-fold__body' });
		fold.appendChild(body);
		block.appendChild(fold);
		pane.appendChild(block);

		fold.addEventListener('toggle', function () {
			var next = !!fold.open;
			if (next === state.attributesPanelOpen) {
				return;
			}
			state.attributesPanelOpen = next;
			render();
		});

		if (!state.attributesPanelOpen) {
			body.appendChild(
				el('p', {
					className: 'wtt-attributes-fold__status description',
					text:
						i18n.attributesFoldCollapsedHint ||
						'Expand to edit attributes.',
				})
			);
			return;
		}

		var shadowCount = attrs.filter(function (a) {
			return a && a.shadowsInherited && !a.inherited;
		}).length;
		if (shadowCount > 0) {
			body.appendChild(
				el('p', {
					className: 'wtt-attributes__shadow-banner notice notice-warning inline',
					text:
						i18n.attributesShadowsBanner ||
						'Some local attributes shadow inherited ones (same name). Remove the local copy to inherit from the parent — keep local only when the field is specialization-specific.',
				})
			);
		}

		var attrVal = resolveAttributeValidation(n);
		if (attrVal && !attrVal.ok) {
			body.appendChild(renderAttributeValidationBanner(attrVal, n));
		}

		/* Column order: data… → Inherited? → Actions (Actions always last). */
		var columns = [
			{ label: i18n.attributesName || 'Name', className: 'wtt-col-name' },
			{ label: i18n.attributesType || 'Type', className: 'wtt-col-type' },
			{ label: i18n.attributesMult || 'Mult.', className: 'wtt-col-mult' },
			{
				label: i18n.attributesBinding || 'Bindung',
				className: 'wtt-col-binding',
			},
			{
				label: i18n.attributesFixed || 'Default',
				className: 'wtt-col-fixed',
				title: i18n.attributesFixedTitle || 'Default value',
			},
			{
				label: i18n.attributesReadonly || 'RO',
				className: 'wtt-col-readonly',
				title: i18n.attributesReadonlyTitle || 'Readonly',
			},
			{
				label: i18n.attributesHideLabel || 'Hide',
				className: 'wtt-col-hide',
			},
		];
		if (showInherited) {
			columns.push({
				label: i18n.attributesInherited || 'Inherited',
				className: 'wtt-col-inherited',
				title:
					i18n.attributesInheritedTitle ||
					'Inherited attributes: Hide / RO / Default on this row are host-local overrides (not the parent Relation edge).',
			});
		}
		columns.push({
			label: i18n.attributesActions || 'Actions',
			className: 'wtt-col-actions',
		});
		var colCount = columns.length;
		/* Display order peers = full effective list (own + inherited) on this host. */
		var reorderPeers = attrs.filter(function (a) {
			return !!a;
		});
		var table = el('table', {
			className: 'wtt-attributes__table wtt-row-edit-table widefat striped',
		});
		var thead = el('thead');
		var headRow = el('tr');
		columns.forEach(function (col) {
			headRow.appendChild(
				el('th', {
					text: col.label,
					className: col.className,
					scope: 'col',
					title: col.title || col.label,
				})
			);
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		if (!attrs.length) {
			tbody.appendChild(
				el('tr', null, [
					el('td', {
						colspan: colCount,
						className: 'wtt-attributes__empty',
						text: i18n.attributesEmpty || 'No attributes yet.',
					}),
				])
			);
		} else {
			attrs.forEach(function (attr) {
				var frag = renderAttributeRow(n, attr, editable, {
					showInherited: showInherited,
					reorderPeers: reorderPeers,
					colCount: colCount,
				});
				if (frag) {
					tbody.appendChild(frag);
				}
			});
		}
		table.appendChild(tbody);

		if (editable) {
			var tfoot = el('tfoot');
			var addTr = el('tr', { className: 'wtt-attributes__add-row' });
			var nameInput = el('input', {
				type: 'text',
				className: 'regular-text wtt-attributes__name-input',
				placeholder: i18n.attributesName || 'Name',
			});
			var typeState = { id: 0, name: '' };
			var addState = {
				pendingFixed: [],
				readonly: false,
			};
			var typeCell = el('td', { className: 'wtt-col-type' });
			function mountAddTypePicker() {
				typeCell.textContent = '';
				typeCell.appendChild(
					renderAttributeTypePicker(n, {
						selectedId: typeState.id,
						selectedLabel: typeState.name,
						expandKey: 'attr-type-add:' + String(hostId),
						allowClear: false,
						onSelect: function (typeId) {
							typeId = parseInt(typeId, 10) || 0;
							if (!typeId) {
								return;
							}
							typeState.id = typeId;
							typeState.name = attributeTypeLabel(n, typeId);
							addState.pendingFixed = [];
							mountAddTypePicker();
							mountAddFixedCell();
						},
					})
				);
			}
			mountAddTypePicker();
			var multSelect = el('select', {
				className: 'wtt-attributes__mult-select',
			});
			relationMultiplicityOptions(n).forEach(function (opt) {
				multSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.value,
						selected: opt.value === '1',
					})
				);
			});
			multSelect.addEventListener('change', function () {
				var m = String(multSelect.value || '1');
				var many = m === '0..*' || m === '1..*';
				if (!many && addState.pendingFixed.length > 1) {
					addState.pendingFixed = addState.pendingFixed.slice(0, 1);
					mountAddFixedCell();
				}
			});
			var bindSelect = el('select', {
				className: 'wtt-attributes__binding-select',
				title:
					i18n.attributesBindingTitle ||
					'Composition or aggregation binding',
			});
			attributeBindingOptions().forEach(function (opt) {
				bindSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label,
						selected: opt.value === 'aggregation',
					})
				);
			});
			applySoleRequiredListLock(
				bindSelect,
				countRealSelectOptions(bindSelect),
				{ allowEmpty: false }
			);
			var fixedCell = el('td', {
				className: 'wtt-col-fixed wtt-attributes__fixed',
			});
			var readonlyCell = el('td', {
				className: 'wtt-col-readonly wtt-attributes__readonly',
			});

			function formatPendingFixedLabel(values) {
				return (values || [])
					.map(function (v) {
						v = String(v || '');
						if (/^\d+$/.test(v)) {
							var hit = findNodeInTree(state.tree, parseInt(v, 10));
							if (hit && hit.name) {
								return hit.name;
							}
						}
						return v;
					})
					.filter(Boolean)
					.join(', ');
			}

			function buildAddAttributeDraft() {
				var typeId = typeState.id;
				var typeNode = typeId
					? findNodeInTree(state.tree, typeId)
					: null;
				var typeKey = typeNode
					? String(typeNode.name || '').toLowerCase()
					: '';
				var typeName =
					typeState.name ||
					(typeNode && typeNode.name) ||
					'';
				var isUnit = !!(typeNode && typeNode.isBasiseinheitUnit);
				var isEnum = !!(typeNode && typeNode.isConcreteEnum);
				var scalarKeys = {
					int: true,
					double: true,
					text: true,
					email: true,
					textarea: true,
					char: true,
					bool: true,
					date: true,
					quantity: true,
					media: true,
					display_node_name: true,
					node_presentation: true,
				};
				var fixedMode = 'literal';
				if (typeId && (isEnum || (!isUnit && !scalarKeys[typeKey]))) {
					fixedMode = 'catalog';
				}
				var mult = String(multSelect.value || '1');
				var draftName = String(nameInput.value || '').trim();
				return {
					id: 0,
					name:
						draftName ||
						(i18n.attributesName || 'Attribute'),
					typeId: typeId,
					typeKey: typeKey,
					typeName: typeName,
					multiplicity: mult,
					allowsMany: mult === '0..*' || mult === '1..*',
					allowsEmpty: mult === '0..1' || mult === '0..*',
					fixedMode: fixedMode,
					fixedRootId: typeId,
					fixedOptions: [],
					fixedValues: addState.pendingFixed.slice(),
				};
			}

			function mountAddFixedCell() {
				fixedCell.textContent = '';
				var hasFixed = addState.pendingFixed.length > 0;
				var fixedLabel = formatPendingFixedLabel(addState.pendingFixed);
				var fixedBtnAttrs = {
					type: 'button',
					className:
						'button-link wtt-attributes__fixed-btn' +
						(hasFixed ? ' has-value' : ' is-empty'),
					title:
						(hasFixed
							? fixedLabel
							: i18n.attributesFixedAdd || 'Set default') +
						(i18n.attributesFixedHint
							? ' â€” ' + i18n.attributesFixedHint
							: ''),
					'aria-label': hasFixed
						? fixedLabel
						: i18n.attributesFixedAdd || 'Set default',
					onClick: function () {
						if (!typeState.id) {
							setError(
								i18n.attributesTypeRequired ||
									'Attribute type is required.'
							);
							return;
						}
						openAttributeFixedValueDialog(
							n,
							buildAddAttributeDraft(),
							function () {},
							{
								onSave: function (values) {
									addState.pendingFixed = Array.isArray(values)
										? values.slice()
										: [];
									mountAddFixedCell();
								},
							}
						);
					},
				};
				if (hasFixed) {
					fixedBtnAttrs.text = fixedLabel;
				} else {
					fixedBtnAttrs.html =
						'<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>';
				}
				fixedCell.appendChild(el('button', fixedBtnAttrs));
			}
			mountAddFixedCell();

			function mountAddReadonlyCell() {
				readonlyCell.textContent = '';
				readonlyCell.appendChild(
					renderSlideSwitch({
						checked: !!addState.readonly,
						disabled: false,
						title:
							i18n.attributesReadonlyHint ||
							'When on, the attribute is not editable in forms.',
						onChange: function (on) {
							addState.readonly = !!on;
						},
					})
				);
			}
			mountAddReadonlyCell();

			var addBtn = el('button', {
				type: 'button',
				className: 'button button-primary button-small',
				text: i18n.attributesAdd || 'Add attribute',
				onClick: function () {
					var name = String(nameInput.value || '').trim();
					if (!name) {
						setError(
							i18n.attributesNameRequired ||
								'Attribute name is required.'
						);
						return;
					}
					if (!typeState.id) {
						setError(
							i18n.attributesTypeRequired ||
								'Attribute type is required.'
						);
						return;
					}
					if (!confirmStructuralModelChange(n)) {
						return;
					}
					var payload = {
						term_id: hostId,
						name: name,
						type_id: typeState.id,
						multiplicity: multSelect.value || '1',
						binding: bindSelect.value || 'aggregation',
						readonly: addState.readonly ? '1' : '0',
					};
					if (addState.pendingFixed.length) {
						payload.fixed_values = JSON.stringify(
							addState.pendingFixed
						);
					}
					post('wtt_add_attribute', payload)
						.then(function (json) {
							nameInput.value = '';
							typeState.id = 0;
							typeState.name = '';
							addState.pendingFixed = [];
							addState.readonly = false;
							mountAddTypePicker();
							mountAddFixedCell();
							mountAddReadonlyCell();
							multSelect.value = '1';
							bindSelect.value = 'aggregation';
							return applyRelationMutation(json);
						})
						.catch(function () {
							setError(i18n.error);
						});
				},
			});
			addTr.appendChild(
				el('td', { className: 'wtt-col-name' }, [nameInput])
			);
			addTr.appendChild(typeCell);
			addTr.appendChild(
				el('td', { className: 'wtt-col-mult' }, [multSelect])
			);
			addTr.appendChild(
				el('td', { className: 'wtt-col-binding' }, [bindSelect])
			);
			addTr.appendChild(fixedCell);
			addTr.appendChild(readonlyCell);
			addTr.appendChild(
				el('td', { className: 'wtt-col-hide' }, [
					renderSlideSwitch({
						checked: false,
						disabled: true,
						title:
							i18n.attributesHideOwnHint ||
							'Background-only (Hide) on own attributes: set Mult to 0..1 or 1 after create, then toggle Hide.',
					}),
				])
			);
			if (showInherited) {
				addTr.appendChild(
					el('td', {
						className: 'wtt-col-inherited',
						text: i18n.attributesInheritedNo || '\u2014',
					})
				);
			}
			addTr.appendChild(
				el('td', { className: 'wtt-col-actions' }, [addBtn])
			);
			tfoot.appendChild(addTr);
			table.appendChild(tfoot);
		}

		var tableWrap = el('div', { className: 'wtt-attributes__table-wrap' });
		tableWrap.appendChild(table);
		body.appendChild(tableWrap);
	}

	function attributeBindingOptions() {
		return [
			{
				value: 'aggregation',
				label: i18n.attributesBindingAggregation || 'Aggregation',
			},
			{
				value: 'besteht_aus',
				label:
					i18n.attributesBindingComposition ||
					'Composition (besteht_aus)',
			},
		];
	}

	function bindingLabelForKey(key) {
		key = String(key || 'aggregation');
		if (key === 'composition') {
			key = 'besteht_aus';
		}
		var opts = attributeBindingOptions();
		for (var i = 0; i < opts.length; i++) {
			if (opts[i].value === key) {
				return opts[i].label;
			}
		}
		return key;
	}

	/**
	 * Shared selectable predicate for attribute Type (Q92 chooser scope).
	 */
	function attributeTypeSelectable(node, typeIdMap) {
		var id = node && node.id != null ? parseInt(node.id, 10) || 0 : 0;
		if (!id) {
			return false;
		}
		if (typeIdMap && typeIdMap[String(id)]) {
			return true;
		}
		return true;
	}

	function attributeTypeLabel(n, typeId) {
		typeId = parseInt(typeId, 10) || 0;
		if (!typeId) {
			return '';
		}
		var typeName = '';
		(n.typeOptions || []).forEach(function (o) {
			if (o && parseInt(o.id, 10) === typeId) {
				typeName = String(o.path || o.name || '');
			}
		});
		if (typeName) {
			return typeName;
		}
		var roots = datatypePickerRoots(n);
		var hit =
			findNodeInTree(roots, typeId) || findNodeInTree(state.tree, typeId);
		return hit ? String(hit.name || typeId) : String(typeId);
	}

	/**
	 * Attribute Type column: same popup tree chooser as node Data type / Festwert.
	 */
	function renderAttributeTypePicker(n, opts) {
		opts = opts || {};
		var typeIdMap = assignableTypeIds(
			Array.isArray(n.typeOptions) ? n.typeOptions : []
		);
		var selectedId = parseInt(opts.selectedId, 10) || 0;
		var selectedLabel =
			opts.selectedLabel || attributeTypeLabel(n, selectedId);
		var focusId = attributeTypeChooserFocusId(n);
		return renderNodeTreePicker({
			roots: attributeTypePickerRoots(n),
			selectedId: selectedId,
			selectedLabel: selectedLabel,
			compact: true,
			expandKey: opts.expandKey || 'attr-type',
			allowRoot: false,
			allowClear: !!opts.allowClear,
			disabled: !!opts.disabled,
			className: 'wtt-attributes__type-picker',
			dialogTitle: i18n.attributesPickType || 'Choose attribute type',
			placeholder: i18n.attributesPickType || 'Choose typeâ€¦',
			pickedPrefix: i18n.nodePickerSelected || 'Selected:',
			focusId: focusId,
			preferFocus: true,
			expandFocusBranch: true,
			ignoreLastSelection: true,
			selectable: function (node) {
				return attributeTypeSelectable(node, typeIdMap);
			},
			onSelect: typeof opts.onSelect === 'function' ? opts.onSelect : function () {},
		});
	}

	function openAttributeTypePicker(n, selectedId, expandKey, onPicked) {
		var typeIdMap = assignableTypeIds(
			Array.isArray(n.typeOptions) ? n.typeOptions : []
		);
		openNodeTreePickerDialog(
			{
				roots: attributeTypePickerRoots(n),
				selectedId: selectedId || 0,
				allowRoot: false,
				allowClear: false,
				expandKey: expandKey || 'attr-type',
				dialogTitle: i18n.attributesPickType || 'Choose attribute type',
				placeholder: i18n.attributesPickType || 'Choose attribute type',
				focusId: attributeTypeChooserFocusId(n),
				preferFocus: true,
				expandFocusBranch: true,
				ignoreLastSelection: true,
				selectable: function (node) {
					return attributeTypeSelectable(node, typeIdMap);
				},
			},
			function (typeId) {
				typeId = parseInt(typeId, 10) || 0;
				if (!typeId) {
					return;
				}
				if (typeof onPicked === 'function') {
					onPicked(typeId, attributeTypeLabel(n, typeId));
				}
			}
		);
	}

	function formatInheritedFrom(attr) {
		var src = String(attr.definedOnName || '');
		var tpl = i18n.attributesInheritedFrom || 'Inherited from %s';
		return tpl.replace('%s', src || String(attr.definedOnId || ''));
	}

	/** Compact boolean slide switch (preferred for hide / similar flags). */
	function renderSlideSwitch(opts) {
		opts = opts || {};
		var checked = !!opts.checked;
		var disabled = !!opts.disabled;
		var input = el('input', {
			type: 'checkbox',
			className: 'wtt-switch__input',
			checked: checked,
			disabled: disabled,
		});
		if (typeof opts.onChange === 'function') {
			input.addEventListener('change', function () {
				opts.onChange(!!input.checked);
			});
		}
		var label = el('label', {
			className:
				'wtt-switch' +
				(disabled ? ' is-disabled' : '') +
				(checked ? ' is-on' : ''),
			title: opts.title || '',
		});
		label.appendChild(input);
		label.appendChild(el('span', { className: 'wtt-switch__track' }, [
			el('span', { className: 'wtt-switch__thumb' }),
		]));
		if (opts.text) {
			label.appendChild(
				el('span', { className: 'wtt-switch__text', text: opts.text })
			);
		}
		return label;
	}

	/**
	 * Choice-filter tree roots for an attribute (catalog specialization children).
	 *
	 * @param {Object} attr
	 * @return {Array<Object>}
	 */
	function attributeChoiceFilterRoots(attr) {
		var rootId = parseInt(attr.fixedRootId || attr.typeId, 10) || 0;
		var roots = rootId > 0 ? nodeRefPickRoots(rootId) : [];
		if ((!roots || !roots.length) && Array.isArray(attr.fixedOptions) && attr.fixedOptions.length) {
			roots = buildChoiceTreeFromFixedOptions(attr.fixedOptions);
		}
		return Array.isArray(roots) ? roots : [];
	}

	/**
	 * View-bag string (camelCase preferred; accept legacy lower-case keys).
	 *
	 * @param {Object} view
	 * @param {string} key
	 * @return {string}
	 */
	function settingsViewString(view, key) {
		if (!view || typeof view !== 'object' || !key) {
			return '';
		}
		if (view[key] != null && String(view[key]) !== '') {
			return String(view[key]);
		}
		var lower = String(key).toLowerCase();
		var k;
		for (k in view) {
			if (
				Object.prototype.hasOwnProperty.call(view, k) &&
				String(k).toLowerCase() === lower &&
				view[k] != null &&
				String(view[k]) !== ''
			) {
				return String(view[k]);
			}
		}
		return '';
	}

	/**
	 * typeExtras-shaped override bag from Relation edge Settings deltas.
	 * Uses attr.settings only (not settingsResolved — that includes type live).
	 *
	 * @param {Object} attr
	 * @return {Object}
	 */
	function typeExtrasFromEdgeSettings(attr) {
		var out = {};
		if (!attr || typeof attr !== 'object') {
			return out;
		}
		var settings =
			attr.settings && typeof attr.settings === 'object' ? attr.settings : null;
		if (!settings) {
			return out;
		}
		var data =
			settings.data && typeof settings.data === 'object' ? settings.data : {};
		var view =
			settings.view && typeof settings.view === 'object' ? settings.view : {};

		if (Object.prototype.hasOwnProperty.call(data, 'dateMode')) {
			out.dateMode = data.dateMode;
		}
		if (Object.prototype.hasOwnProperty.call(data, 'textareaCols')) {
			out.textareaCols = data.textareaCols;
		}
		if (Object.prototype.hasOwnProperty.call(data, 'textareaRows')) {
			out.textareaRows = data.textareaRows;
		}
		if (Object.prototype.hasOwnProperty.call(data, 'presentationContext')) {
			out.presentationContext = data.presentationContext;
		}
		if (
			Object.prototype.hasOwnProperty.call(data, 'validators') &&
			Array.isArray(data.validators)
		) {
			out.validators = data.validators;
		}
		if (
			Object.prototype.hasOwnProperty.call(data, 'choiceFilter') &&
			data.choiceFilter &&
			typeof data.choiceFilter === 'object'
		) {
			out.choiceFilter = data.choiceFilter;
		}
		if (
			Object.prototype.hasOwnProperty.call(data, 'compute') &&
			data.compute &&
			typeof data.compute === 'object'
		) {
			out.compute = data.compute;
		}
		var conv = settingsViewString(view, 'preferredConverter');
		if (conv) {
			out.preferredConverter = normalizePreferredConverter(conv);
			out.displayFormat = out.preferredConverter;
		}
		return out;
	}

	/**
	 * Options paint bag (Q123): edge Settings deltas win; host typeExtras fills gaps.
	 * settingsResolved is walk hybrid (type live + deltas) — not used for override chrome.
	 *
	 * @param {Object} attr
	 * @return {Object}
	 */
	function attributeOptionsExtras(attr) {
		var host =
			attr && attr.typeExtras && typeof attr.typeExtras === 'object'
				? Object.assign({}, attr.typeExtras)
				: {};
		var edge = typeExtrasFromEdgeSettings(attr);
		var merged = Object.assign({}, host, edge);
		if (attr && attr.compute && !merged.compute) {
			merged.compute = attr.compute;
		}
		return merged;
	}

	/**
	 * Which Options panels apply for an attribute (hide N/A chrome).
	 *
	 * @param {Object} attr
	 * @return {{isDate:boolean,isNodePresentation:boolean,hasChoice:boolean,showCompute:boolean,hasAny:boolean}}
	 */
	function attributeDetailSections(attr) {
		var extras = attributeOptionsExtras(attr);
		var compute = extras.compute || attr.compute;
		var typeKey = String(attr.typeKey || '').toLowerCase();
		var isDate = typeKey === 'date';
		var isTextarea = typeKey === 'textarea';
		var isNodePresentation = isNodePresentationTypeKey(typeKey);
		var isInt = typeKey === 'int' || typeKey === 'integer';
		var hasChoice =
			String(attr.fixedMode || '') === 'catalog' &&
			attributeChoiceFilterRoots(attr).length > 0;
		var isNumeric =
			typeKey === 'int' ||
			typeKey === 'integer' ||
			typeKey === 'double' ||
			typeKey === 'float';
		var hasCompute = !!(compute && compute.op);
		var showCompute = isNumeric || hasCompute;
		return {
			isDate: isDate,
			isTextarea: isTextarea,
			isNodePresentation: isNodePresentation,
			isInt: isInt,
			hasChoice: hasChoice,
			showCompute: showCompute,
			hasPreferred: true,
			hasAny: true,
		};
	}

	/**
	 * Default-value button label for an attribute row.
	 * Bool uses WTTNodeRender.formatBoolLabel (same as Form/Table display) —
	 * Attributes panel does not mount the bool control here.
	 *
	 * @param {object} attr
	 * @return {string}
	 */
	function formatAttributeFixedLabel(attr) {
		if (!attr || typeof attr !== 'object') {
			return '';
		}
		var typeKey = String(attr.typeKey || attr.typeName || '')
			.trim()
			.toLowerCase();
		var vals = Array.isArray(attr.fixedValues) ? attr.fixedValues : [];
		if (typeKey === 'bool' && vals.length) {
			return vals
				.map(function (v) {
					if (
						window.WTTNodeRender &&
						typeof window.WTTNodeRender.formatBoolLabel === 'function'
					) {
						return window.WTTNodeRender.formatBoolLabel(v);
					}
					var s = String(v == null ? '' : v)
						.trim()
						.toLowerCase();
					var on =
						s === '1' ||
						s === 'true' ||
						s === 'yes' ||
						s === 'on';
					return on
						? i18n.boolTrue || 'true'
						: i18n.boolFalse || 'false';
				})
				.join(', ');
		}
		if (vals.length) {
			var painted = vals
				.map(function (v) {
					return formatFixedWireDisplayLabel(attr, v);
				})
				.filter(Boolean);
			if (painted.length) {
				return painted.join(', ');
			}
		}
		if (attr.fixedLabel) {
			return String(attr.fixedLabel);
		}
		return vals.length ? vals.join(', ') : '';
	}

	function renderAttributeRow(n, attr, editable, rowOpts) {
		rowOpts = rowOpts || {};
		var showInherited = !!rowOpts.showInherited;
		var reorderPeers = Array.isArray(rowOpts.reorderPeers)
			? rowOpts.reorderPeers
			: Array.isArray(rowOpts.ownPeers)
			  ? rowOpts.ownPeers
			  : [];
		var colCount = parseInt(rowOpts.colCount, 10) || 8;
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = wttAttrId(attr);
		var inherited = !!attr.inherited;
		var hidden = !!attr.hidden;
		var shadows = !inherited && !!attr.shadowsInherited;
		var roNeedsDefault = attributeRowReadonlyNeedsDefault(attr);
		var ownEditable = editable && !inherited;
		var detailSections = attributeDetailSections(attr);
		var detailExpanded = !!state.attrDetailExpanded[attrId];
		var frag = document.createDocumentFragment();
		var tr = el('tr', {
			className:
				'wtt-attributes__row' +
				(inherited ? ' wtt-attributes__row--inherited' : '') +
				(hidden ? ' wtt-attributes__row--hidden' : '') +
				(shadows ? ' wtt-attributes__row--shadows' : '') +
				(roNeedsDefault ? ' wtt-attributes__row--rule-error' : '') +
				(attr.computed ? ' wtt-attributes__row--computed' : '') +
				(detailSections.hasAny && detailExpanded
					? ' is-options-open'
					: ''),
		});

		var peerIndex = -1;
		reorderPeers.forEach(function (a, i) {
			if (a && wttAttrId(a) === attrId) {
				peerIndex = i;
			}
		});
		/* Host display order: own or inherited may move relative to neighbors. */
		var canReorderUp = editable && peerIndex > 0;
		var canReorderDown =
			editable && peerIndex >= 0 && peerIndex < reorderPeers.length - 1;

		/* Name */
		var shadowHint = '';
		if (shadows) {
			var fromName = String(attr.shadowsDefinedOnName || '').trim();
			var tpl =
				i18n.attributesShadowsHint ||
				'Local copy hides the inherited “%s” definition. Remove this attribute to use inheritance from the parent.';
			shadowHint = fromName
				? tpl.replace('%s', fromName)
				: i18n.attributesShadowsTitle || 'Shadows inherited';
		}
		var shadowBadge = shadows
			? el('span', {
					className: 'wtt-attributes__shadow-badge',
					title: shadowHint,
					'aria-label': shadowHint,
					text: '!',
			  })
			: null;

		if (ownEditable) {
			var nameInput = el('input', {
				type: 'text',
				className: 'regular-text wtt-attributes__name-input',
				value: attr.name || '',
				onChange: function (e) {
					nameInput._pending = e.target.value;
				},
				onBlur: function () {
					var next = String(
						nameInput._pending != null
							? nameInput._pending
							: nameInput.value || ''
					).trim();
					if (!next || next === String(attr.name || '')) {
						return;
					}
					post('wtt_update_attribute', {
						term_id: hostId,
						attr_id: attrId,
						name: next,
					})
						.then(applyRelationMutation)
						.catch(function () {
							setError(i18n.error);
						});
				},
			});
			var nameWrapKids = [nameInput];
			if (shadowBadge) {
				nameWrapKids.push(shadowBadge);
			}
			tr.appendChild(
				el('td', { className: 'wtt-col-name' }, [
					el('div', { className: 'wtt-attributes__name-wrap' }, nameWrapKids),
				])
			);
		} else {
			var nameReadKids = [el('span', { text: attr.name || '' })];
			if (shadowBadge) {
				nameReadKids.push(shadowBadge);
			}
			tr.appendChild(
				el('td', { className: 'wtt-col-name wtt-attributes__name' }, [
					el('div', { className: 'wtt-attributes__name-wrap' }, nameReadKids),
				])
			);
		}

		/* Type â€” popup tree chooser (own or inherited local override). */
		var typeLabel =
			attr.typeName ||
			(attr.typeId
				? String(attr.typeId)
				: i18n.attributesUntyped || 'not typed');
		if (isNodePresentationTypeKey(attr.typeKey || attr.typeName || typeLabel)) {
			typeLabel = 'node_presentation';
		}
		if (editable) {
			tr.appendChild(
				el('td', { className: 'wtt-col-type' }, [
					renderAttributeTypePicker(n, {
						selectedId: parseInt(attr.typeId, 10) || 0,
						selectedLabel: typeLabel,
						expandKey:
							'attr-type-edit:' +
							String(hostId) +
							':' +
							String(attrId),
						allowClear: false,
						onSelect: function (typeId) {
							typeId = parseInt(typeId, 10) || 0;
							if (
								!typeId ||
								typeId === parseInt(attr.typeId, 10)
							) {
								return;
							}
							if (!confirmStructuralModelChange(n)) {
								renderDetail();
								return;
							}
							post('wtt_set_attribute_type', {
								term_id: hostId,
								attr_id: attrId,
								type_id: typeId,
							})
								.then(applyRelationMutation)
								.catch(function () {
									setError(i18n.error);
								});
						},
					}),
				])
			);
		} else {
			tr.appendChild(el('td', { className: 'wtt-col-type', text: typeLabel }));
		}

		/* Mult. â€” own update or inherited local override. */
		var currentMult = String(attr.multiplicity || '1');
		if (editable) {
			var select = el('select', {
				className: 'wtt-attributes__mult-select',
				title:
					(i18n.attributesMultTitle || 'Multiplicity') +
					': ' +
					currentMult,
				'aria-label':
					(i18n.attributesMult || 'Mult.') + ' ' + currentMult,
				onChange: function (e) {
					var next = e.target.value;
					if (next === currentMult) {
						return;
					}
					if (!confirmStructuralModelChange(n)) {
						e.target.value = currentMult;
						return;
					}
					var action = inherited
						? 'wtt_set_attribute_multiplicity'
						: 'wtt_update_attribute';
					var payload = {
						term_id: hostId,
						attr_id: attrId,
						multiplicity: next,
					};
					post(action, payload)
						.then(applyRelationMutation)
						.catch(function () {
							setError(i18n.error);
						});
				},
			});
			relationMultiplicityOptions(n).forEach(function (opt) {
				select.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label || opt.value,
						selected: opt.value === currentMult,
					})
				);
			});
			tr.appendChild(el('td', { className: 'wtt-col-mult' }, [select]));
		} else {
			tr.appendChild(
				el('td', {
					className: 'wtt-col-mult',
					text: currentMult,
					title:
						(i18n.attributesMultTitle || 'Multiplicity') +
						': ' +
						currentMult,
				})
			);
		}

		/* Bindung â€” aggregation | besteht_aus (local override when inherited). */
		var currentBinding = String(attr.binding || 'aggregation');
		if (currentBinding === 'composition') {
			currentBinding = 'besteht_aus';
		}
		if (editable) {
			var bindSelect = el('select', {
				className: 'wtt-attributes__binding-select',
				onChange: function (e) {
					var next = e.target.value;
					if (next === currentBinding) {
						return;
					}
					post('wtt_set_attribute_binding', {
						term_id: hostId,
						attr_id: attrId,
						binding: next,
					})
						.then(applyRelationMutation)
						.catch(function () {
							setError(i18n.error);
						});
				},
			});
			attributeBindingOptions().forEach(function (opt) {
				bindSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label,
						selected: opt.value === currentBinding,
					})
				);
			});
			applySoleRequiredListLock(
				bindSelect,
				countRealSelectOptions(bindSelect),
				{ allowEmpty: false }
			);
			tr.appendChild(
				el('td', { className: 'wtt-col-binding' }, [bindSelect])
			);
		} else {
			tr.appendChild(
				el('td', {
					className: 'wtt-col-binding',
					text:
						attr.bindingLabel ||
						bindingLabelForKey(currentBinding),
				})
			);
		}

		/* Default — empty = +, else show value (bool via shared label formatter). */
		var fixedCell = el('td', { className: 'wtt-col-fixed wtt-attributes__fixed' });
		var fixedLabel = formatAttributeFixedLabel(attr);
		var hasFixed =
			!!fixedLabel ||
			(Array.isArray(attr.fixedValues) && attr.fixedValues.length > 0);
		var fixedBtnAttrs = {
			type: 'button',
			className:
				'button-link wtt-attributes__fixed-btn' +
				(hasFixed ? ' has-value' : ' is-empty'),
			disabled: !editable,
			title:
				(hasFixed
					? fixedLabel
					: i18n.attributesFixedAdd || 'Set default') +
				(i18n.attributesFixedHint
					? ' â€” ' + i18n.attributesFixedHint
					: ''),
			'aria-label':
				hasFixed
					? fixedLabel
					: i18n.attributesFixedAdd || 'Set default',
			onClick: function () {
				if (!editable) {
					return;
				}
				openAttributeFixedValueDialog(n, attr, function () {});
			},
		};
		if (hasFixed) {
			fixedBtnAttrs.text = fixedLabel;
		} else {
			fixedBtnAttrs.html =
				'<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>';
		}
		fixedCell.appendChild(el('button', fixedBtnAttrs));
		tr.appendChild(fixedCell);

		/* Readonly — host-scoped; own and inherited. Forced on when computed. */
		var isComputed = !!(attr.computed || (attr.compute && attr.compute.op));
		var roDisabled = !editable || isComputed;
		var readonlyCell = el('td', {
			className:
				'wtt-col-readonly wtt-attributes__readonly' +
				(roDisabled ? ' is-muted' : ''),
		});
		readonlyCell.appendChild(
			renderSlideSwitch({
				checked: isComputed || !!attr.readonly,
				disabled: roDisabled,
				title: isComputed
					? i18n.attributesComputedRoHint ||
					  'Computed attributes are always read-only.'
					: i18n.attributesReadonlyHint ||
					  'When on, the attribute is not editable in forms.',
				onChange: function (on) {
					if (isComputed) {
						return;
					}
					post('wtt_set_attribute_readonly', {
						term_id: hostId,
						attr_id: attrId,
						readonly: on ? '1' : '0',
					})
						.then(applyRelationMutation)
						.catch(function () {
							setError(i18n.error);
						});
				},
			})
		);
		tr.appendChild(readonlyCell);

		/* Hide / BO — inherited cover-up always; own edge when Mult is single (Q105). */
		var multKey = String(attr.multiplicity || '');
		var canHideOwn = !inherited && (multKey === '0..1' || multKey === '1');
		var hideEnabled = editable && (inherited || canHideOwn);
		var hideCell = el('td', { className: 'wtt-col-hide wtt-attributes__hide' });
		hideCell.appendChild(
			renderSlideSwitch({
				checked: !!hidden,
				disabled: !hideEnabled,
				title: inherited
					? i18n.attributesHideHint ||
					  'Hide this inherited attribute on this node.'
					: canHideOwn
					  ? i18n.attributesHideBoHint ||
					    'Background-only: hide from user forms (Mult 0..1 or 1).'
					  : i18n.attributesHideOwnHint ||
					    'Background-only (Hide) requires multiplicity 0..1 or 1.',
				onChange: function (on) {
					if (!hideEnabled) {
						return;
					}
					post('wtt_set_attribute_hidden', {
						term_id: hostId,
						attr_id: attrId,
						hidden: on ? '1' : '0',
					})
						.then(applyRelationMutation)
						.catch(function () {
							setError(i18n.error);
						});
				},
			})
		);
		tr.appendChild(hideCell);

		/* Inherited — only when this host has any inherited attrs. */
		if (showInherited) {
			var inhCell = el('td', {
				className: 'wtt-col-inherited wtt-attributes__inherited',
			});
			if (inherited) {
				inhCell.appendChild(
					el('span', {
						className: 'wtt-attributes__inherited-badge',
						text: i18n.attributesInheritedYes || 'Yes',
					})
				);
				inhCell.appendChild(
					el('span', {
						className: 'wtt-attributes__inherited-from',
						text: formatInheritedFrom(attr),
					})
				);
				var ov = attr && attr.inheritedHostOverride;
				if (ov && ov.any) {
					var parts = [];
					if (ov.hidden) {
						parts.push(i18n.attributesInheritedOverrideHide || 'Hide');
					}
					if (ov.readonly) {
						parts.push(i18n.attributesInheritedOverrideRo || 'RO');
					}
					if (ov.default) {
						parts.push(i18n.attributesInheritedOverrideDefault || 'Default');
					}
					if (ov.typeExtras) {
						parts.push(i18n.attributesInheritedOverrideExtras || 'Options');
					}
					var ovHint = (
						i18n.attributesInheritedOverrideHint ||
						'Host-local override on this node: %s.'
					).replace('%s', parts.join(', '));
					inhCell.title = ovHint;
					inhCell.appendChild(
						el('span', {
							className: 'wtt-attributes__inherited-override',
							text: i18n.attributesInheritedOverrideBadge || 'override',
							title: ovHint,
						})
					);
				}
			} else {
				inhCell.appendChild(
					el('span', {
						className: 'wtt-attributes__inherited-no',
						text: i18n.attributesInheritedNo || '\u2014',
					})
				);
			}
			tr.appendChild(inhCell);
		}

		/* Actions — reorder ↑↓ (host display), hierarchy move / duplicate / trash (own only). */
		var actions = el('td', {
			className: 'wtt-col-actions wtt-attributes__actions',
		});
		if (editable) {
			actions.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-row-edit-table__action wtt-attributes__reorder-up',
					disabled: !canReorderUp,
					title: i18n.attributesReorderUp || 'Move up',
					'aria-label': i18n.attributesReorderUp || 'Move up',
					html:
						'<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>',
					onClick: function () {
						if (!canReorderUp) {
							return;
						}
						post('wtt_reorder_attribute', {
							term_id: hostId,
							attr_id: attrId,
							delta: -1,
						})
							.then(applyRelationMutation)
							.catch(function () {
								setError(i18n.error);
							});
					},
				})
			);
			actions.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-row-edit-table__action wtt-attributes__reorder-down',
					disabled: !canReorderDown,
					title: i18n.attributesReorderDown || 'Move down',
					'aria-label': i18n.attributesReorderDown || 'Move down',
					html:
						'<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>',
					onClick: function () {
						if (!canReorderDown) {
							return;
						}
						post('wtt_reorder_attribute', {
							term_id: hostId,
							attr_id: attrId,
							delta: 1,
						})
							.then(applyRelationMutation)
							.catch(function () {
								setError(i18n.error);
							});
					},
				})
			);

			if (ownEditable) {
				var parentId = parseInt(n.parent, 10) || 0;
				var parentNode =
					parentId > 0 ? findNodeInTree(state.tree, parentId) : null;
				var canMoveToParent =
					parentId > 0 && !(parentNode && parentNode.isTrash);
				var moveChildRoots = attributeMoveChildRoots(n);
				var canMoveToChild = moveChildRoots.length > 0;
				if (canMoveToParent) {
					actions.appendChild(
						el('button', {
							type: 'button',
							className:
								'button-link wtt-row-edit-table__action wtt-attributes__move-parent',
							title:
								i18n.attributesMoveToParentHint ||
								i18n.attributesMoveToParent ||
								'Move to parent',
							'aria-label':
								i18n.attributesMoveToParent || 'Move to parent',
							html:
								'<span class="wtt-attributes__hier-move" aria-hidden="true">' +
								'<span class="dashicons dashicons-networking"></span>' +
								'<span class="dashicons dashicons-arrow-up-alt2"></span>' +
								'</span>',
							onClick: function () {
								if (
									!window.confirm(
										i18n.attributesMoveToParentConfirm ||
											'Move this attribute to the parent node?'
									)
								) {
									return;
								}
								post('wtt_move_attribute_to_parent', {
									term_id: hostId,
									attr_id: attrId,
								})
									.then(applyRelationMutation)
									.catch(function () {
										setError(i18n.error);
									});
							},
						})
					);
				}
				if (canMoveToChild) {
					actions.appendChild(
						el('button', {
							type: 'button',
							className:
								'button-link wtt-row-edit-table__action wtt-attributes__move-child',
							title:
								i18n.attributesMoveToChildHint ||
								i18n.attributesMoveToChild ||
								'Move to child',
							'aria-label':
								i18n.attributesMoveToChild || 'Move to child',
							html:
								'<span class="wtt-attributes__hier-move" aria-hidden="true">' +
								'<span class="dashicons dashicons-networking"></span>' +
								'<span class="dashicons dashicons-arrow-down-alt2"></span>' +
								'</span>',
							onClick: function () {
								openAttributeMoveToChildPicker(
									n,
									attrId,
									moveChildRoots
								);
							},
						})
					);
				}
			}

			/* Duplicate: own or inherited â†’ new own attribute on this host. */
			actions.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-row-edit-table__action wtt-attributes__duplicate',
					title: i18n.attributesDuplicate || 'Duplicate',
					'aria-label': i18n.attributesDuplicate || 'Duplicate',
					html:
						'<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>',
					onClick: function () {
						post('wtt_duplicate_attribute', {
							term_id: hostId,
							attr_id: attrId,
						})
							.then(applyRelationMutation)
							.catch(function () {
								setError(i18n.error);
							});
					},
				})
			);

			if (ownEditable) {
				actions.appendChild(
					el('button', {
						type: 'button',
						className:
							'button-link-delete wtt-row-edit-table__trash wtt-attributes__remove',
						title: i18n.attributesRemove || 'Remove',
						'aria-label': i18n.attributesRemove || 'Remove',
						html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
						onClick: function () {
							/* Structural warn (when instances) replaces the simple remove confirm. */
							if (
								cfg.warnStructuralModelChange &&
								n &&
								n.hasModelInstances
							) {
								if (!confirmStructuralModelChange(n)) {
									return;
								}
							} else if (
								!window.confirm(
									i18n.attributesRemoveConfirm ||
										'Remove this attribute?'
								)
							) {
								return;
							}
							post('wtt_remove_attribute', {
								term_id: hostId,
								attr_id: attrId,
							})
								.then(applyRelationMutation)
								.catch(function () {
									setError(i18n.error);
								});
						},
					})
				);
			}
		} else {
			actions.appendChild(
				el('span', {
					className: 'wtt-attributes__actions-na',
					text: 'â€”',
				})
			);
		}
		tr.appendChild(actions);

		frag.appendChild(tr);
		if (detailSections.hasAny) {
			var detail = renderAttributeDetailRow(n, attr, editable, {
				colCount: colCount,
				inherited: inherited,
				sections: detailSections,
				expanded: detailExpanded,
			});
			if (detail) {
				frag.appendChild(detail);
			}
		}
		return frag;
	}

	/**
	 * True when attribute Options differ from pure type defaults.
	 *
	 * @param {Object} attr
	 * @return {boolean}
	 */
	function attributeDetailHasOverrides(attr) {
		if (!attr) {
			return false;
		}
		var extras = attributeOptionsExtras(attr);
		if (attr.preferredRenderOverride) {
			return true;
		}
		if (attr.validatorsOverride) {
			return true;
		}
		if (extras.preferredConverter || extras.displayFormat) {
			return true;
		}
		if (extras.dateMode) {
			return true;
		}
		if (extras.presentationContext) {
			return true;
		}
		if (
			attr.presentationConfig &&
			attr.presentationConfig.hasOverride
		) {
			return true;
		}
		if (
			extras.choiceFilter &&
			Array.isArray(extras.choiceFilter.ids) &&
			extras.choiceFilter.ids.length
		) {
			return true;
		}
		if (extras.compute && extras.compute.op) {
			return true;
		}
		if (
			attr.dateConfig &&
			attr.dateConfig.hasOverride
		) {
			return true;
		}
		if (
			attr.intConfig &&
			attr.intConfig.hasOverride
		) {
			return true;
		}
		return false;
	}

	/**
	 * Options bar + detail under an attribute: choices | render/converter/validators.
	 * Always shows a full-width rule with a right chevron; panels only when expanded.
	 * Configured overrides keep the Options control visually active when collapsed.
	 */
	function renderAttributeDetailRow(n, attr, editable, opts) {
		opts = opts || {};
		var colCount = parseInt(opts.colCount, 10) || 8;
		var inherited = !!opts.inherited;
		var expanded = !!opts.expanded;
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = wttAttrId(attr);
		/* Q123: edge Settings deltas preferred; typeExtras host map = fallback. */
		var extras = attributeOptionsExtras(attr);

		var sections = opts.sections || attributeDetailSections(attr);
		if (!sections.hasAny) {
			return null;
		}

		var configured = attributeDetailHasOverrides(attr);
		var optionsLabel = configured
			? i18n.attributesOptionsConfigured || 'Settings'
			: i18n.attributesOptions || 'Options';
		var bar = el('div', {
			className:
				'wtt-attributes__options-bar' +
				(configured ? ' is-configured' : ''),
		}, [
			el('hr', { className: 'wtt-attributes__options-line' }),
			el('button', {
				type: 'button',
				className:
					'wtt-attributes__options-toggle' +
					(configured ? ' is-configured' : ''),
				'aria-expanded': expanded ? 'true' : 'false',
				'data-attr-id': String(attrId),
				title: optionsLabel,
				'aria-label': optionsLabel,
				html:
					(configured
						? '<span class="wtt-attributes__options-badge">' +
						  String(optionsLabel) +
						  '</span>'
						: '') +
					'<span class="dashicons dashicons-arrow-' +
					(expanded ? 'up' : 'down') +
					'" aria-hidden="true"></span>',
				onClick: function (e) {
					e.preventDefault();
					e.stopPropagation();
					var scroll = capturePaneScroll();
					state.attrDetailExpanded[attrId] = !expanded;
					if (!expanded) {
						/* Opening Options: hydrate walk from write-time cache if needed. */
						ensureAttrSettingsWalk(n, attr);
					}
					render();
					restorePaneScroll(scroll);
				},
			}),
		]);

		var cellChildren = [bar];
		if (expanded) {
			/*
			 * Hydrate full walk when slim/lazy or when we already claim walk
			 * coverage but levels are empty (avoid blank Settings panel).
			 */
			var walkLevels = Array.isArray(attr.settingsWalk)
				? attr.settingsWalk
				: [];
			if (
				attr.settingsWalkLazy ||
				state.attrWalkLoading[attrId] ||
				(attributeSettingsWalkCovers(attr) && !walkLevels.length)
			) {
				ensureAttrSettingsWalk(n, attr);
			}
			/*
			 * Host-map overrides (choiceFilter, typeExtras, Preferred / Walk Settings)
			 * work for inherited attrs without mutating the father’s edge (Q66 / OQ-W5).
			 * Own attrs write Relation Settings deltas directly.
			 */
			var hostOverrideEditable = !!editable;
			var settingsOverrideEditable = hostOverrideEditable;
			var wrap = el('div', { className: 'wtt-attributes__detail' });
			/*
			 * Settings UI parity: when the Settings walk covers this attribute,
			 * it is the only Settings surface (depth 0…leaf). Do not also paint
			 * legacy Choices + depth-0 Relation-overrides chrome (duplicate).
			 * Fallback: legacy chrome only if walk is absent.
			 * See docs/plans/settings-ui-parity.md.
			 */
			var walkCoversSettings = attributeSettingsWalkCovers(attr);

			if (!walkCoversSettings) {
				var layout = el('div', {
					className: 'wtt-attributes__detail-layout',
				});
				var left = el('div', {
					className:
						'wtt-attributes__detail-col wtt-attributes__detail-col--choices',
				});
				var right = el('div', {
					className:
						'wtt-attributes__detail-col wtt-attributes__detail-col--chrome',
				});

				if (sections.hasChoice) {
					left.appendChild(
						renderAttrChoiceFilterDetail(
							n,
							attr,
							extras,
							hostOverrideEditable,
							hostId,
							attrId
						)
					);
				}

				right.appendChild(
					el(
						'div',
						{
							className: 'wtt-attributes__relation-overrides-intro',
						},
						[
							el('div', {
								className: 'wtt-attributes__detail-label',
								text:
									i18n.attributesRelationOverrides ||
									'Relation overrides',
							}),
							el('p', {
								className: 'wtt-field-hint',
								text: inherited
									? i18n.attributesRelationOverridesInheritedHint ||
									  'Overrides on this child host only (Settings deltas stored on the heir). The parent Relation edge is not mutated. Reset deletes the heir override.'
									: i18n.attributesRelationOverridesHint ||
									  'Hybrid Settings.view / Settings.data deltas on this attribute Relation (depth 0). Nested walk levels below use the same law with path-keyed deltas. Reset deletes the delta key.',
							}),
						]
					)
				);
				right.appendChild(
					renderAttrSettingsPreferredChrome(
						n,
						attr,
						extras,
						settingsOverrideEditable,
						hostOverrideEditable,
						hostId,
						attrId
					)
				);
				if (sections.isDate) {
					right.appendChild(
						renderAttrDateModeDetail(
							n,
							attr,
							extras,
							hostOverrideEditable,
							hostId,
							attrId
						)
					);
				}
				if (sections.isTextarea) {
					right.appendChild(
						renderAttrTextareaLayoutDetail(
							n,
							attr,
							extras,
							hostOverrideEditable,
							hostId,
							attrId
						)
					);
				}

				if (left.childNodes.length) {
					layout.appendChild(left);
				}
				layout.appendChild(right);
				wrap.appendChild(layout);
			}

			/* Not yet in Settings walk — keep outside until walk owns them. */
			if (sections.isNodePresentation) {
				wrap.appendChild(
					renderAttrPresentationContextDetail(
						n,
						attr,
						extras,
						hostOverrideEditable,
						hostId,
						attrId
					)
				);
			}

			if (sections.showCompute) {
				wrap.appendChild(
					renderAttrComputeDetail(
						n,
						attr,
						extras,
						hostOverrideEditable,
						hostId,
						attrId
					)
				);
			}
			/*
			 * Settings walk (OQ-W16 / Attribut-Walk): per-level Settings.view +
			 * Settings.data overrides as path-keyed deltas on this attribute
			 * Relation — never writes nested type nodes. Inherited → host map.
			 */
			var walkSummaryEl = renderSettingsWalkSummary(
				n,
				attr,
				settingsOverrideEditable,
				hostId,
				attrId
			);
			if (walkSummaryEl) {
				wrap.appendChild(walkSummaryEl);
			}
			cellChildren.push(wrap);
		}

		return el(
			'tr',
			{
				className:
					'wtt-attributes__detail-row' +
					(inherited ? ' is-inherited' : '') +
					(expanded ? ' is-expanded' : '') +
					(configured ? ' is-configured' : ''),
				'data-attr-id': String(attrId),
			},
			[
				el(
					'td',
					{
						colSpan: colCount,
						className: 'wtt-attributes__detail-cell',
					},
					cellChildren
				),
			]
		);
	}

	function saveAttributeTypeExtras(hostId, attrId, extras) {
		return post('wtt_set_attribute_type_extras', {
			term_id: hostId,
			attr_id: attrId,
			extras: JSON.stringify(extras || {}),
		})
			.then(applyRelationMutation)
			.catch(function () {
				setError(i18n.error);
			});
	}

	function saveAttributePreferredRender(hostId, attrId, layout) {
		return post('wtt_set_attribute_preferred_render', {
			term_id: hostId,
			attr_id: attrId,
			preferred_render: layout == null || layout === '' ? '' : String(layout),
		})
			.then(applyRelationMutation)
			.catch(function () {
				setError(i18n.error);
			});
	}

	/**
	 * Walk-Wizard: set/clear one Settings.view|data key on the attribute edge.
	 * path "" = depth 0; nested = `/`-joined child Relation edge UUIDs.
	 */
	function saveAttributeWalkSettings(hostId, attrId, path, namespace, key, value, clear) {
		var payload = {
			term_id: hostId,
			attr_id: attrId,
			path: path == null ? '' : String(path),
			namespace: namespace,
			key: key,
		};
		if (clear) {
			payload.clear = '1';
		} else if (typeof value === 'string') {
			payload.value = value;
		} else {
			payload.value = JSON.stringify(value == null ? null : value);
		}
		return post('wtt_set_attribute_walk_settings', payload)
			.then(applyRelationMutation)
			.catch(function () {
				setError(i18n.error);
			});
	}

	/**
	 * Label + optional Relation-override badge / Reset (delete Settings delta key).
	 *
	 * @param {string}   label
	 * @param {boolean}  hasOverride
	 * @param {Function|null} onReset
	 * @param {boolean}  editable
	 * @return {HTMLElement}
	 */
	function renderAttrRelationOverrideHead(label, hasOverride, onReset, editable) {
		var head = el('div', {
			className: 'wtt-attributes__relation-override-head',
		});
		head.appendChild(
			el('span', {
				className: 'wtt-attributes__detail-label',
				text: label,
			})
		);
		if (hasOverride) {
			head.appendChild(
				el('span', {
					className: 'wtt-attributes__relation-override-badge',
					text:
						i18n.attributesRelationOverrideBadge ||
						'Relation override',
					title:
						i18n.attributesRelationOverridesHint ||
						'Hybrid Settings delta on this attribute Relation.',
				})
			);
		}
		if (hasOverride && editable && typeof onReset === 'function') {
			head.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-attributes__relation-override-reset',
					text:
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					title:
						i18n.attributesRelationOverrideResetHint ||
						'Delete this Relation Settings delta key and inherit the type default.',
					'aria-label':
						(i18n.attributesRelationOverrideReset ||
							'Reset override') +
						': ' +
						label,
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						onReset();
					},
				})
			);
		}
		return head;
	}

	function preferredRenderOptionLabel(value) {
		value = normalizePreferredRender(value);
		var i;
		for (i = 0; i < PREFERRED_LAYOUTS.length; i++) {
			var o = PREFERRED_LAYOUTS[i];
			if (o.value === value) {
				return (i18n && i18n[o.labelKey]) || o.fallback || value;
			}
		}
		/* Field paint — prefer Registry label (MediaRenderer, Int, …). */
		var NR = window.WTTNodeRender;
		if (NR && NR.Registry && typeof NR.Registry.getById === 'function') {
			var r = NR.Registry.getById(value);
			if (r && r.label) {
				return String(r.label);
			}
		}
		var fieldLabels = {
			media: (i18n && i18n.preferredRenderMedia) || 'MediaRenderer',
			int: 'IntRenderer',
			int_spinner: 'IntSpinnerRenderer',
			int_range: 'IntRangeRenderer',
			double: 'DoubleRenderer',
			double_spinner: 'DoubleSpinnerRenderer',
			double_range: 'DoubleRangeRenderer',
			text: 'TextRenderer',
			textarea: 'TextareaRenderer',
			char: 'CharRenderer',
			bool: 'BoolRenderer',
			bool_checkbox: 'BoolCheckboxRenderer',
			bool_radio: 'BoolRadioRenderer',
			email: 'EmailRenderer',
			date: 'DateRenderer',
			time: 'TimeRenderer',
			datetime: 'DateTimeRenderer',
			color: 'ColorRenderer',
			quantity: 'QuantityRenderer',
			unit: 'UnitRenderer',
			node_presentation: 'NodePresentationRenderer',
			node_ref: 'NodeRefRenderer',
		};
		if (fieldLabels[value]) {
			return fieldLabels[value];
		}
		return value.charAt(0).toUpperCase() + value.slice(1);
	}

	/**
	 * True when Attribute Options should use the Settings walk as the only
	 * Settings surface (suppress legacy Choices / depth-0 Relation chrome).
	 *
	 * @param {Object} attr
	 * @return {boolean}
	 */
	function attributeSettingsWalkCovers(attr) {
		if (!attr) {
			return false;
		}
		if (Array.isArray(attr.settingsWalk) && attr.settingsWalk.length > 0) {
			return true;
		}
		if (attr.settingsWalkLazy) {
			return true;
		}
		var meta =
			attr.settingsWalkMeta && typeof attr.settingsWalkMeta === 'object'
				? attr.settingsWalkMeta
				: null;
		if (!meta) {
			return false;
		}
		if (meta.lazy) {
			return true;
		}
		if ((parseInt(meta.nodeCount, 10) || 0) > 0) {
			return true;
		}
		return (parseInt(meta.depth, 10) || 0) > 0;
	}

	/**
	 * Settings walk (Attribut-Walk) — per-level Settings.view + Settings.data
	 * overrides as path-keyed deltas on the current attribute Relation (OQ-W16).
	 * Compact one-row controls; navigate secondary.
	 * Single-node (depth 0 only) is still this surface — not legacy chrome.
	 * Tree layout deferred — see settings-ui-parity.md / q123-migrate-handoff.
	 */
	function renderSettingsWalkSummary(hostNode, attr, editable, hostId, attrId) {
		var levels = Array.isArray(attr.settingsWalk) ? attr.settingsWalk : [];
		var walkMeta =
			attr.settingsWalkMeta && typeof attr.settingsWalkMeta === 'object'
				? attr.settingsWalkMeta
				: null;
		var walkNodes = walkMeta ? parseInt(walkMeta.nodeCount, 10) || 0 : 0;
		var lazy =
			!!attr.settingsWalkLazy ||
			!!(walkMeta && walkMeta.lazy) ||
			!!state.attrWalkLoading[wttAttrId(attrId || attr)];
		/* Always show when walk exists or is loading — including depth-0 only. */
		if (!levels.length && !lazy && walkNodes <= 0) {
			return null;
		}

		var bits = [];
		if (walkNodes > 0) {
			bits.push(
				String(
					i18n.attributesSettingsWalkNodes ||
						'Settings walk: %d nodes'
				).replace('%d', String(walkNodes))
			);
		}
		if (attr.settingsWalkCached) {
			bits.push(i18n.attributesSettingsWalkCached || 'cached');
		}

		var box = el('div', {
			className: 'wtt-attributes__walk-summary',
		});
		var titleRow = el('div', {
			className: 'wtt-attributes__walk-summary-head',
		});
		titleRow.appendChild(
			el('div', {
				className: 'wtt-attributes__detail-label',
				text: i18n.attributesSettingsWalk || 'Settings',
			})
		);
		if (bits.length) {
			titleRow.appendChild(
				el('span', {
					className: 'wtt-attributes__walk-meta',
					text: bits.join(' · '),
				})
			);
		}
		box.appendChild(titleRow);
		if (lazy && !levels.length) {
			box.appendChild(
				el('p', {
					className: 'description wtt-attributes__walk-loading',
					text:
						i18n.attributesSettingsWalkLoading ||
						'Loading composition settings…',
				})
			);
			return box;
		}
		if (!levels.length) {
			return box;
		}

		/*
		 * Layout: depth-0 + CatalogChoice stay as cards; nested scalar children
		 * (Kontakt → E-Mail/Vorname/…) share one Attributes-like table row chrome.
		 */
		var list = el('div', {
			className: 'wtt-attributes__walk-list wtt-attributes__walk-list--wizard',
		});
		var nestedTable = null;
		var nestedBody = null;

		function flushNestedTable() {
			nestedTable = null;
			nestedBody = null;
		}

		function ensureNestedTable() {
			if (nestedTable) {
				return nestedBody;
			}
			nestedTable = el('table', {
				className:
					'wtt-row-edit-table wtt-attributes__walk-table',
			});
			var thead = el('thead');
			var hr = el('tr');
			[
				{ cls: 'wtt-col-name', text: i18n.attributesName || 'Name' },
				{ cls: 'wtt-col-type', text: i18n.attributesType || 'Type' },
				{ cls: 'wtt-col-fixed', text: i18n.attributesFixed || 'Default' },
				{
					cls: 'wtt-col-readonly',
					text: i18n.attributesReadonly || 'RO',
				},
				{
					cls: 'wtt-col-hide',
					text: i18n.attributesHideLabel || i18n.attributesHide || 'Hide',
				},
				{
					cls: 'wtt-col-render',
					text:
						i18n.preferredRenderShort ||
						i18n.preferredRender ||
						'Render',
				},
				{
					cls: 'wtt-col-converter',
					text:
						i18n.preferredConverterShort ||
						i18n.preferredConverterNoneShort ||
						'Conv',
				},
				{
					cls: 'wtt-col-validators',
					text: i18n.attributesValidatorsShort || 'Val',
				},
				{ cls: 'wtt-col-actions', text: '' },
			].forEach(function (col) {
				hr.appendChild(
					el('th', {
						className: col.cls,
						scope: 'col',
						text: col.text,
					})
				);
			});
			thead.appendChild(hr);
			nestedBody = el('tbody');
			nestedTable.appendChild(thead);
			nestedTable.appendChild(nestedBody);
			list.appendChild(nestedTable);
			return nestedBody;
		}

		function levelShowsChoices(level) {
			return !!level.supportsChoiceFilter;
		}

		levels.forEach(function (level) {
			if (!level || typeof level !== 'object' || level.cycleStopped) {
				return;
			}
			var depth = parseInt(level.depth, 10) || 0;
			if (levelShowsChoices(level) || depth === 0) {
				flushNestedTable();
				var card = renderSettingsWalkLevel(
					hostNode,
					attr,
					level,
					!!editable,
					hostId,
					attrId
				);
				if (card) {
					list.appendChild(card);
				}
				return;
			}
			var row = renderSettingsWalkTableRow(
				hostNode,
				attr,
				level,
				!!editable,
				hostId,
				attrId
			);
			if (row) {
				ensureNestedTable().appendChild(row);
			}
		});
		box.appendChild(list);
		return box;
	}

	/**
	 * Probe attr shape for Default dialog at a walk level.
	 */
	function walkLevelDefaultProbe(attr, level) {
		var depth = parseInt(level.depth, 10) || 0;
		var hasOverride = !!level.hasDefaultOverride;
		var seed = hasOverride
			? Array.isArray(level.default)
				? level.default.slice()
				: []
			: Array.isArray(level.typeDefault)
			  ? level.typeDefault.slice()
			  : [];
		if (depth === 0 && attr) {
			return Object.assign({}, attr, {
				fixedValues: seed,
				allowsEmpty: true,
			});
		}
		var props = Array.isArray(attr && attr.typeProperties)
			? attr.typeProperties
			: [];
		var edgeId = level.edgeId != null ? String(level.edgeId) : '';
		var nodeId = parseInt(level.nodeId, 10) || 0;
		var i;
		for (i = 0; i < props.length; i++) {
			var p = props[i];
			if (!p) {
				continue;
			}
			if (edgeId && String(p.id) === edgeId) {
				return Object.assign({}, p, {
					fixedValues: seed,
					allowsEmpty: true,
				});
			}
			if (nodeId && parseInt(p.typeId, 10) === nodeId) {
				return Object.assign({}, p, {
					fixedValues: seed,
					allowsEmpty: true,
				});
			}
		}
		return {
			id: edgeId || nodeId || 0,
			name: level.edgeName || level.name || '',
			typeKey: level.typeKey || 'text',
			typeName: level.typeKey || 'text',
			typeId: nodeId,
			fixedValues: seed,
			allowsMany: false,
			allowsEmpty: true,
			multiplicity: '0..1',
		};
	}

	function settingsWalkLevelHasPathDelta(level) {
		return (
			!!level.hasPathOverride ||
			!!level.hasPreferredOverride ||
			!!level.hasConverterOverride ||
			!!level.hasValidatorsOverride ||
			!!level.hasDateModeOverride ||
			!!level.hasAllowedPrefixIdsOverride ||
			!!level.hasChoiceFilterOverride ||
			!!level.hasDefaultOverride ||
			!!level.hasReadOnlyOverride ||
			!!level.hasHiddenOverride
		);
	}

	/**
	 * Nested walk child as one Attributes-like table row (Name readonly + overrides).
	 */
	function renderSettingsWalkTableRow(
		hostNode,
		attr,
		level,
		editable,
		hostId,
		attrId
	) {
		if (!level || typeof level !== 'object' || level.cycleStopped) {
			return null;
		}
		var nodeId = parseInt(level.nodeId, 10) || 0;
		var path = level.path != null ? String(level.path) : '';
		var name =
			(level.edgeName && String(level.edgeName)) ||
			(level.name && String(level.name)) ||
			'—';
		var typeKey = level.typeKey ? String(level.typeKey) : '';
		var hasPath = settingsWalkLevelHasPathDelta(level);
		var depth = parseInt(level.depth, 10) || 0;

		var tr = el('tr', {
			className:
				'wtt-attributes__walk-tr' +
				(hasPath ? ' has-delta' : '') +
				(depth > 1 ? ' is-nested-depth' : ''),
		});
		if (depth > 1) {
			tr.style.setProperty('--wtt-walk-depth', String(Math.min(depth, 6)));
		}

		tr.appendChild(
			el('td', {
				className: 'wtt-col-name',
			}, [
				el('span', {
					className: 'wtt-attributes__walk-item-name is-readonly',
					text: name,
					title: settingsWalkLevelLabel(level),
				}),
			])
		);
		tr.appendChild(
			el('td', {
				className: 'wtt-col-type',
			}, [
				el('span', {
					className: 'wtt-attributes__walk-type',
					text: typeKey || '—',
					title: typeKey || '',
				}),
			])
		);

		var tdDefault = el('td', { className: 'wtt-col-fixed' });
		tdDefault.appendChild(
			renderWalkDefaultField(
				hostNode,
				attr,
				level,
				path,
				editable,
				hostId,
				attrId
			)
		);
		tr.appendChild(tdDefault);

		tr.appendChild(
			el('td', { className: 'wtt-col-readonly' }, [
				renderWalkBoolOverrideSwitch(level, path, editable, hostId, attrId, {
					key: 'readOnly',
					resolved: !!level.readOnly,
					typeDefault: !!level.typeReadOnly,
					hasOverride: !!level.hasReadOnlyOverride,
					title:
						i18n.attributesReadonlyHint ||
						'Read-only override for this nested attribute path.',
					aria:
						i18n.attributesReadonlyTitle ||
						i18n.attributesReadonly ||
						'Read-only',
				}),
			])
		);
		tr.appendChild(
			el('td', { className: 'wtt-col-hide' }, [
				renderWalkBoolOverrideSwitch(level, path, editable, hostId, attrId, {
					key: 'hidden',
					resolved: !!level.hidden,
					typeDefault: !!level.typeHidden,
					hasOverride: !!level.hasHiddenOverride,
					title:
						i18n.attributesHideBoHint ||
						i18n.attributesHideHint ||
						'Hide / background-only override for this nested attribute path.',
					aria: i18n.attributesHideLabel || i18n.attributesHide || 'Hide',
				}),
			])
		);

		var expand = el('div', {
			className: 'wtt-attributes__walk-row-expand',
			hidden: true,
		});

		var tdRender = el('td', { className: 'wtt-col-render' });
		tdRender.appendChild(
			renderWalkPreferredField(level, path, editable, hostId, attrId, true)
		);
		if (typeKey === 'date' || level.typeDateMode || level.dateMode) {
			tdRender.appendChild(
				renderWalkDateModeField(
					level,
					path,
					editable,
					hostId,
					attrId,
					true
				)
			);
		}
		tr.appendChild(tdRender);

		var tdConv = el('td', { className: 'wtt-col-converter' });
		var convEl = renderWalkConverterField(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId,
			true
		);
		if (convEl) {
			tdConv.appendChild(convEl);
		} else {
			tdConv.appendChild(
				el('span', {
					className: 'description',
					text: '—',
				})
			);
		}
		tr.appendChild(tdConv);

		var tdVal = el('td', { className: 'wtt-col-validators' });
		var valSummary = renderWalkValidatorsCompact(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId,
			expand
		);
		if (valSummary) {
			tdVal.appendChild(valSummary);
		} else {
			tdVal.appendChild(
				el('span', {
					className: 'description',
					text: '—',
				})
			);
		}
		var prefixSummary = renderWalkPrefixesCompact(
			level,
			path,
			editable,
			hostId,
			attrId,
			expand
		);
		if (prefixSummary) {
			tdVal.appendChild(prefixSummary);
		}
		tr.appendChild(tdVal);

		var tdAct = el('td', { className: 'wtt-col-actions' });
		if (nodeId > 0) {
			tdAct.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-attributes__walk-item-edit',
					text: '↗',
					title:
						i18n.attributesSettingsWalkGo ||
						'Select this type node in the tree',
					'aria-label':
						(i18n.attributesSettingsWalkEdit || 'Open type node') +
						': ' +
						name,
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						selectNode(nodeId);
					},
				})
			);
		}
		tr.appendChild(tdAct);

		var frag = document.createDocumentFragment();
		frag.appendChild(tr);
		var expandTr = el('tr', {
			className: 'wtt-attributes__walk-tr-expand',
			hidden: true,
		});
		expandTr.appendChild(
			el('td', {
				colSpan: 9,
				className: 'wtt-attributes__walk-td-expand',
			}, [expand])
		);
		frag.appendChild(expandTr);
		return frag;
	}

	/**
	 * Nested RO / Hide slide switch — Settings.data path override (inherit when matches type).
	 */
	function renderWalkBoolOverrideSwitch(
		level,
		path,
		editable,
		hostId,
		attrId,
		opts
	) {
		opts = opts || {};
		var key = opts.key || 'readOnly';
		var resolved = !!opts.resolved;
		var typeDefault = !!opts.typeDefault;
		var hasOverride = !!opts.hasOverride;
		var wrap = el('div', {
			className:
				'wtt-attributes__walk-bool' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		wrap.appendChild(
			renderSlideSwitch({
				checked: resolved,
				disabled: !editable,
				title: opts.title || '',
				onChange: function (on) {
					if (on === typeDefault) {
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							key,
							null,
							true
						);
						return;
					}
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'data',
						key,
						!!on,
						false
					);
				},
			})
		);
		if (hasOverride && editable) {
			wrap.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-attributes__walk-cell-reset',
					text: '×',
					title:
						i18n.attributesRelationOverrideResetHint ||
						'Delete this Relation Settings delta key and inherit the type default.',
					'aria-label':
						(i18n.attributesRelationOverrideReset ||
							'Reset override') +
						': ' +
						(opts.aria || key),
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							key,
							null,
							true
						);
					},
				})
			);
		}
		return wrap;
	}

	function renderSettingsWalkLevel(hostNode, attr, level, editable, hostId, attrId) {
		if (!level || typeof level !== 'object') {
			return null;
		}
		var depth = parseInt(level.depth, 10) || 0;
		var nodeId = parseInt(level.nodeId, 10) || 0;
		var path = level.path != null ? String(level.path) : '';
		var label = settingsWalkLevelLabel(level);
		var typeKey = level.typeKey ? String(level.typeKey) : '';
		var hasPath = settingsWalkLevelHasPathDelta(level);
		/*
		 * CatalogChoice only when the walk level supports it. Do not open Choices
		 * merely because choiceOptions is non-empty — structure hosts may still
		 * list heirs in stale caches (ghost C1 under Unit type).
		 */
		var showChoices = !!level.supportsChoiceFilter;

		var item = el('div', {
			className:
				'wtt-attributes__walk-item wtt-attributes__walk-level' +
				(showChoices
					? ' wtt-attributes__walk-level--choice'
					: ' wtt-attributes__walk-level--row') +
				(depth === 0 ? ' is-root' : '') +
				(hasPath ? ' has-delta' : '') +
				(level.cycleStopped ? ' is-cycle' : ''),
		});

		if (level.cycleStopped) {
			return null;
		}

		if (showChoices) {
			var choiceHead = el('div', {
				className: 'wtt-attributes__walk-choice-head',
			});
			choiceHead.appendChild(
				el('span', {
					className: 'wtt-attributes__walk-item-name',
					text: label,
					title:
						(level.edgeName ? String(level.edgeName) : '') +
						(level.name ? ' → ' + String(level.name) : ''),
				})
			);
			if (nodeId > 0) {
				choiceHead.appendChild(
					el('button', {
						type: 'button',
						className: 'button-link wtt-attributes__walk-item-edit',
						text: '↗',
						title:
							i18n.attributesSettingsWalkGo ||
							'Select this type node in the tree',
						onClick: function (e) {
							e.preventDefault();
							e.stopPropagation();
							selectNode(nodeId);
						},
					})
				);
			}
			item.appendChild(choiceHead);

			/* Reuse Options two-column layout (Choices | Settings). */
			var layout = el('div', {
				className: 'wtt-attributes__detail-layout',
			});
			var left = el('div', {
				className:
					'wtt-attributes__detail-col wtt-attributes__detail-col--choices',
			});
			left.appendChild(
				renderWalkChoiceFilterField(level, path, editable, hostId, attrId)
			);
			var right = el('div', {
				className:
					'wtt-attributes__detail-col wtt-attributes__detail-col--chrome',
			});
			right.appendChild(
				el('div', {
					className: 'wtt-attributes__relation-overrides-intro',
				}, [
					el('div', {
						className: 'wtt-attributes__detail-label',
						text:
							i18n.attributesSettingsWalk ||
							i18n.attributesRelationOverrides ||
							'Settings',
					}),
					el('p', {
						className: 'wtt-field-hint',
						text:
							i18n.attributesRelationOverridesHint ||
							'Hybrid Settings.view / Settings.data deltas on this attribute Relation. Nested walk levels use path-keyed deltas. Reset deletes the delta key.',
					}),
				])
			);
			right.appendChild(
				renderWalkSettingsPreferredChrome(
					level,
					path,
					typeKey,
					editable,
					hostId,
					attrId
				)
			);
			right.appendChild(
				renderWalkDefaultField(
					hostNode,
					attr,
					level,
					path,
					editable,
					hostId,
					attrId
				)
			);
			layout.appendChild(left);
			layout.appendChild(right);
			item.appendChild(layout);
			return item;
		}

		/* Depth-0 type host (e.g. Kontakt): compact header row, no nested RO/Hide. */
		var row = el('div', {
			className: 'wtt-attributes__walk-row',
		});
		row.appendChild(
			el('span', {
				className: 'wtt-attributes__walk-item-name',
				text: label,
				title: label,
			})
		);

		row.appendChild(
			renderWalkPreferredField(level, path, editable, hostId, attrId, true)
		);

		var convEl = renderWalkConverterField(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId,
			true
		);
		if (convEl) {
			row.appendChild(convEl);
		}

		if (typeKey === 'date' || level.typeDateMode || level.dateMode) {
			row.appendChild(
				renderWalkDateModeField(
					level,
					path,
					editable,
					hostId,
					attrId,
					true
				)
			);
		}

		row.appendChild(
			renderWalkDefaultField(
				hostNode,
				attr,
				level,
				path,
				editable,
				hostId,
				attrId
			)
		);

		var expand = el('div', {
			className: 'wtt-attributes__walk-row-expand',
			hidden: true,
		});

		var valSummary = renderWalkValidatorsCompact(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId,
			expand
		);
		if (valSummary) {
			row.appendChild(valSummary);
		}

		var prefixSummary = renderWalkPrefixesCompact(
			level,
			path,
			editable,
			hostId,
			attrId,
			expand
		);
		if (prefixSummary) {
			row.appendChild(prefixSummary);
		}

		if (nodeId > 0) {
			row.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-attributes__walk-item-edit',
					text: '↗',
					title:
						i18n.attributesSettingsWalkGo ||
						'Select this type node in the tree',
					'aria-label':
						(i18n.attributesSettingsWalkEdit || 'Open type node') +
						': ' +
						label,
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						selectNode(nodeId);
					},
				})
			);
		}

		item.appendChild(row);
		item.appendChild(expand);
		return item;
	}

	/**
	 * Walk-level Choices (choiceFilter) — same checkbox UX as attribute Options.
	 * Saves when leaving the Choices block (not on every tick).
	 */
	function renderWalkChoiceFilterField(level, path, editable, hostId, attrId) {
		var filter =
			level.choiceFilter && typeof level.choiceFilter === 'object'
				? level.choiceFilter
				: null;
		var opts = Array.isArray(level.choiceOptions) ? level.choiceOptions : [];
		var roots = buildChoiceTreeFromFixedOptions(opts);
		var allIds = collectChoiceFilterNodeIds(roots);
		var draftKey = choiceFilterDraftKey('walk', hostId, attrId, path);
		var draft = getChoiceFilterDraft(draftKey);
		var hasOverride =
			!!level.hasChoiceFilterOverride || !!(draft && draft.dirty);
		var excluded =
			draft && draft.dirty
				? Object.assign({}, draft.excluded || {})
				: choiceFilterToExcluded(filter, allIds);

		var block = el('div', {
			className:
				'wtt-attributes__detail-block wtt-attributes__detail-block--choice' +
				(hasOverride ? ' has-relation-override' : '') +
				(draft && draft.dirty ? ' is-choice-dirty' : ''),
		});
		var head = el('div', {
			className: 'wtt-attributes__relation-override-head',
		});
		head.appendChild(
			el('span', {
				className: 'wtt-attributes__detail-label',
				text: i18n.attributesChoiceFilter || 'Choices',
			})
		);
		if (hasOverride && editable) {
			head.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-attributes__relation-override-reset',
					text:
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						clearChoiceFilterDraft(draftKey);
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							'choiceFilter',
							null,
							true
						);
					},
				})
			);
		}
		block.appendChild(head);

		var treeWrap = el('div', {
			className: 'wtt-attributes__choice-tree',
			tabIndex: editable ? 0 : -1,
		});
		function paintNode(node, depth) {
			if (!node || node.id == null) {
				return;
			}
			var id = parseInt(node.id, 10) || 0;
			if (!id) {
				return;
			}
			var row = el('label', {
				className: 'wtt-attributes__choice-item',
				style: 'padding-left:' + String(depth * 12) + 'px',
			});
			var check = el('input', {
				type: 'checkbox',
				disabled: !editable,
				checked: !excluded[id],
			});
			check.addEventListener('change', function () {
				if (check.checked) {
					delete excluded[id];
				} else {
					excluded[id] = true;
				}
				if (typeof block._wttMarkChoiceDirty === 'function') {
					block._wttMarkChoiceDirty();
				}
			});
			row.appendChild(check);
			row.appendChild(
				document.createTextNode(
					' ' + (node.name || node.label || String(id))
				)
			);
			treeWrap.appendChild(row);
			(Array.isArray(node.children) ? node.children : []).forEach(
				function (ch) {
					paintNode(ch, depth + 1);
				}
			);
		}
		if (!roots.length) {
			treeWrap.appendChild(
				el('p', {
					className: 'description',
					text:
						i18n.attributesChoiceFilterEmpty ||
						'No choices under this type.',
				})
			);
		} else {
			roots.forEach(function (r) {
				paintNode(r, 0);
			});
			treeWrap.appendChild(
				el('p', {
					className: 'description wtt-attributes__choice-hint',
					text:
						i18n.attributesChoiceFilterDeferHint ||
						'Tick freely — choices save when you leave this list.',
				})
			);
		}
		block.appendChild(treeWrap);
		if (editable) {
			bindDeferredChoiceFilter(block, draftKey, function () {
				return {
					kind: 'walk',
					hostId: hostId,
					attrId: attrId,
					path: path == null ? '' : String(path),
					excluded: Object.assign({}, excluded),
				};
			});
		}
		return block;
	}

	/**
	 * Compact Default control — live type seed + Relation override (edge / nested path).
	 */
	function renderWalkDefaultField(
		hostNode,
		attr,
		level,
		path,
		editable,
		hostId,
		attrId
	) {
		var hasOverride = !!level.hasDefaultOverride;
		var liveSeed = Array.isArray(level.typeDefault)
			? level.typeDefault.slice()
			: [];
		var currentSeed = hasOverride
			? Array.isArray(level.default)
				? level.default.slice()
				: []
			: liveSeed.slice();
		var probe = walkLevelDefaultProbe(attr, level);
		probe.fixedValues = currentSeed.slice();
		var labelText = '';
		if (currentSeed.length) {
			labelText = formatAttributeFixedLabel(probe);
			if (!labelText && currentSeed[0] != null) {
				labelText = formatFixedWireDisplayLabel(
					probe,
					typeof currentSeed[0] === 'string'
						? currentSeed[0]
						: JSON.stringify(currentSeed[0])
				);
			}
		}
		var showLive =
			!hasOverride && liveSeed.length > 0 && !!labelText;
		var btn = el('button', {
			type: 'button',
			className:
				'button-link wtt-attributes__walk-default-btn' +
				(hasOverride ? ' has-relation-override' : '') +
				(labelText ? ' has-value' : ' is-empty'),
			disabled: !editable,
			title:
				(hasOverride
					? i18n.attributesRelationOverrideBadge ||
					  'Relation override'
					: showLive
					  ? i18n.attributesPreferredRenderDefault || 'Type default'
					  : i18n.attributesFixedAdd || 'Set default') +
				(labelText ? ': ' + labelText : ''),
			'aria-label':
				(i18n.attributesFixed || 'Default') +
				(labelText ? ': ' + labelText : ''),
			onClick: function (e) {
				e.preventDefault();
				e.stopPropagation();
				if (!editable || !hostNode) {
					return;
				}
				openAttributeFixedValueDialog(
					hostNode,
					probe,
					function () {},
					{
						onSave: function (values) {
							values = Array.isArray(values) ? values : [];
							return saveAttributeWalkSettings(
								hostId,
								attrId,
								path,
								'data',
								'default',
								values,
								values.length === 0
							);
						},
					}
				);
			},
		});
		if (labelText) {
			btn.appendChild(
				document.createTextNode(
					(hasOverride ? '★ ' : '') +
						(labelText.length > 18
							? labelText.slice(0, 17) + '…'
							: labelText)
				)
			);
		} else {
			btn.innerHTML =
				'<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>';
		}

		var cell = el('div', {
			className:
				'wtt-attributes__walk-cell wtt-attributes__walk-cell--default' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		cell.appendChild(
			el('span', {
				className: 'wtt-attributes__walk-cell-label',
				text: i18n.attributesFixed || 'Default',
			})
		);
		cell.appendChild(btn);
		if (hasOverride && editable) {
			cell.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-attributes__walk-cell-reset',
					text: '×',
					title:
						i18n.attributesRelationOverrideResetHint ||
						'Delete this Relation Settings delta key and inherit the type default.',
					'aria-label':
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							'default',
							null,
							true
						);
					},
				})
			);
		}
		return cell;
	}

	function toggleWalkExpand(expandEl, panelKey) {
		if (!expandEl) {
			return;
		}
		var open = expandEl.getAttribute('data-open') === panelKey;
		expandEl.innerHTML = '';
		var expandTr =
			expandEl.closest &&
			expandEl.closest('tr.wtt-attributes__walk-tr-expand');
		if (open) {
			expandEl.hidden = true;
			expandEl.removeAttribute('data-open');
			if (expandTr) {
				expandTr.hidden = true;
			}
			return false;
		}
		expandEl.hidden = false;
		expandEl.setAttribute('data-open', panelKey);
		if (expandTr) {
			expandTr.hidden = false;
		}
		return true;
	}

	function renderWalkValidatorsCompact(
		level,
		path,
		typeKey,
		editable,
		hostId,
		attrId,
		expandEl
	) {
		var hasOverride = !!level.hasValidatorsOverride;
		var list = hasOverride
			? Array.isArray(level.validators)
				? level.validators
				: []
			: Array.isArray(level.typeValidators)
			  ? level.typeValidators
			  : Array.isArray(level.validators)
			    ? level.validators
			    : [];
		var count = list.length;
		var reg = validatorRegistry();
		var probe = {
			id: level.nodeId,
			name: level.name,
			typeKey: typeKey || '',
			type: typeKey ? { name: typeKey } : null,
			typeId: level.nodeId,
			validators: list,
		};
		var compatible =
			reg && typeof reg.listCompatible === 'function'
				? reg.listCompatible(probe) || []
				: [];
		if (!compatible.length && !count && !hasOverride) {
			return null;
		}
		var cell = el('div', {
			className:
				'wtt-attributes__walk-cell wtt-attributes__walk-cell--validators' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		cell.appendChild(
			el('span', {
				className: 'wtt-attributes__walk-cell-label',
				text: i18n.attributesValidatorsShort || 'Val',
			})
		);
		var btn = el('button', {
			type: 'button',
			className: 'button-link wtt-attributes__walk-chip',
			text:
				count > 0
					? String(count)
					: i18n.attributesFixedAdd
					  ? '+'
					  : '+',
			title:
				(i18n.attributesValidators || 'Validators') +
				(hasOverride
					? ' (' +
					  (i18n.attributesRelationOverrideBadge ||
							'Relation override') +
					  ')'
					: ''),
			onClick: function (e) {
				e.preventDefault();
				e.stopPropagation();
				if (!toggleWalkExpand(expandEl, 'validators')) {
					return;
				}
				var panel = renderWalkValidatorsField(
					level,
					path,
					typeKey,
					editable,
					hostId,
					attrId
				);
				if (panel) {
					expandEl.appendChild(panel);
				}
			},
		});
		cell.appendChild(btn);
		if (hasOverride && editable) {
			cell.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-attributes__walk-cell-reset',
					text: '×',
					title:
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							'validators',
							null,
							true
						);
					},
				})
			);
		}
		return cell;
	}

	function renderWalkPrefixesCompact(
		level,
		path,
		editable,
		hostId,
		attrId,
		expandEl
	) {
		if (!level || !level.supportsPrefixAllowlist) {
			return null;
		}
		var catalog = Array.isArray(level.prefixCatalog)
			? level.prefixCatalog
			: [];
		if (!catalog.length) {
			return null;
		}
		var hasOverride = !!level.hasAllowedPrefixIdsOverride;
		var active = hasOverride
			? Array.isArray(level.allowedPrefixIds)
				? level.allowedPrefixIds
				: []
			: Array.isArray(level.typeAllowedPrefixIds)
			  ? level.typeAllowedPrefixIds
			  : [];
		var cell = el('div', {
			className:
				'wtt-attributes__walk-cell wtt-attributes__walk-cell--prefixes' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		cell.appendChild(
			el('span', {
				className: 'wtt-attributes__walk-cell-label',
				text: i18n.attributesAllowedPrefixesShort || 'Pref',
			})
		);
		cell.appendChild(
			el('button', {
				type: 'button',
				className: 'button-link wtt-attributes__walk-chip',
				text: String(active.length),
				title: i18n.allowedPrefixesTitle || 'Allowed prefixes',
				onClick: function (e) {
					e.preventDefault();
					e.stopPropagation();
					if (!toggleWalkExpand(expandEl, 'prefixes')) {
						return;
					}
					var panel = renderWalkAllowedPrefixesField(
						level,
						path,
						editable,
						hostId,
						attrId
					);
					if (panel) {
						expandEl.appendChild(panel);
					}
				},
			})
		);
		if (hasOverride && editable) {
			cell.appendChild(
				el('button', {
					type: 'button',
					className:
						'button-link wtt-attributes__walk-cell-reset',
					text: '×',
					title:
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						saveAttributeWalkSettings(
							hostId,
							attrId,
							path,
							'data',
							'allowedPrefixIds',
							null,
							true
						);
					},
				})
			);
		}
		return cell;
	}

	function wrapWalkCompactCell(label, select, hasOverride, onReset, editable) {
		var cell = el('div', {
			className:
				'wtt-attributes__walk-cell' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		cell.appendChild(
			el('span', {
				className: 'wtt-attributes__walk-cell-label',
				text: label,
			})
		);
		select.className =
			(select.className || '') + ' wtt-attributes__walk-select';
		cell.appendChild(select);
		if (hasOverride && editable && typeof onReset === 'function') {
			cell.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-attributes__walk-cell-reset',
					text: '×',
					title:
						i18n.attributesRelationOverrideResetHint ||
						'Delete this Relation Settings delta key and inherit the type default.',
					'aria-label':
						i18n.attributesRelationOverrideReset ||
						'Reset override',
					onClick: function (e) {
						e.preventDefault();
						e.stopPropagation();
						onReset();
					},
				})
			);
		}
		return cell;
	}

	/**
	 * Walk Relation-overrides: Preferred R/C/V via shared Settings renderer (Q114).
	 */
	function renderWalkSettingsPreferredChrome(
		level,
		path,
		typeKey,
		editable,
		hostId,
		attrId
	) {
		var preferred = renderWalkPreferredField(
			level,
			path,
			editable,
			hostId,
			attrId,
			false
		);
		var converter = renderWalkConverterField(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId,
			false
		);
		var validators = renderWalkValidatorsField(
			level,
			path,
			typeKey,
			editable,
			hostId,
			attrId
		);
		var SR = settingsRender();
		var wrap = el('div', {
			className:
				'wtt-settings-preferred-chrome wtt-settings-preferred--walk',
		});
		if (SR && typeof SR.renderPreferredChrome === 'function') {
			/*
			 * Walk field helpers still own select + override heads; compose them
			 * into the shared Preferred chrome shell for visual parity.
			 */
			var pair = el('div', {
				className:
					'wtt-preferred-pair wtt-settings-preferred' +
					(converter || validators
						? ' wtt-preferred-pair--triple'
						: ''),
			});
			if (preferred) {
				pair.appendChild(
					el('div', { className: 'wtt-preferred-pair__item' }, [
						preferred,
					])
				);
			}
			if (converter) {
				pair.appendChild(
					el('div', { className: 'wtt-preferred-pair__item' }, [
						converter,
					])
				);
			}
			if (validators) {
				pair.appendChild(
					el('div', { className: 'wtt-preferred-pair__item' }, [
						validators,
					])
				);
			} else if (converter) {
				pair.appendChild(
					el('div', { className: 'wtt-preferred-pair__item' }, [
						el('span', {
							className: 'wtt-preferred-pair__label',
							text: i18n.validators || 'Validators',
						}),
						el('span', {
							className: 'description',
							text:
								i18n.preferredConverterNoneShort || 'None',
						}),
					])
				);
			}
			wrap.appendChild(pair);
			return wrap;
		}
		if (preferred) {
			wrap.appendChild(preferred);
		}
		if (converter) {
			wrap.appendChild(converter);
		}
		if (validators) {
			wrap.appendChild(validators);
		}
		return wrap;
	}

	function renderWalkPreferredField(
		level,
		path,
		editable,
		hostId,
		attrId,
		compact
	) {
		var typeDefault = normalizePreferredRender(
			level.typePreferred || 'FormRenderer'
		);
		var hasOverride = !!level.hasPreferredOverride;
		var current = normalizePreferredRender(
			hasOverride ? level.preferred : typeDefault
		);
		var probe = {
			id: level.nodeId,
			name: level.name,
			typeKey: level.typeKey || '',
			type: level.typeKey ? { name: level.typeKey } : null,
			typeId: level.nodeId,
			preferredRender: current,
		};
		var renderOpts = listCompatiblePreferredOptions(probe);
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var val = e.target.value;
				if (!val) {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'view',
						'preferredRenderer',
						'',
						true
					);
				} else {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'view',
						'preferredRenderer',
						val,
						false
					);
				}
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesPreferredRenderDefault || 'Type default') +
					' (' +
					preferredRenderOptionLabel(typeDefault) +
					')',
				selected: !hasOverride,
			})
		);
		renderOpts.forEach(function (o) {
			select.appendChild(
				el('option', {
					value: o.value,
					text: o.label,
					selected:
						hasOverride &&
						normalizePreferredRender(level.preferred) === o.value,
				})
			);
		});
		applySoleRequiredListLock(select, countRealSelectOptions(select), {
			allowEmpty: true,
			disabled: !editable,
		});
		var onReset = function () {
			saveAttributeWalkSettings(
				hostId,
				attrId,
				path,
				'view',
				'preferredRenderer',
				'',
				true
			);
		};
		if (compact) {
			return wrapWalkCompactCell(
				i18n.preferredRenderShort || i18n.preferredRender || 'Render',
				select,
				hasOverride,
				onReset,
				editable
			);
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__walk-field' +
					(hasOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.preferredRenderShort ||
						i18n.preferredRender ||
						'Render',
					hasOverride,
					onReset,
					editable
				),
				select,
			]
		);
	}

	function renderWalkConverterField(
		level,
		path,
		typeKey,
		editable,
		hostId,
		attrId,
		compact
	) {
		var typeFormat = normalizePreferredConverter(
			level.typePreferredConverter || ''
		);
		var hasOverride = !!level.hasConverterOverride;
		var current = normalizePreferredConverter(
			hasOverride ? level.preferredConverter || '' : ''
		);
		var probe = {
			id: level.nodeId,
			name: level.name,
			typeKey: typeKey || '',
			type: typeKey ? { name: typeKey } : null,
			typeId: level.nodeId,
			preferredConverter: current || typeFormat || '',
		};
		var convOpts = listCompatiblePreferredConverterOptions(probe);
		if (!convOpts.length) {
			return null;
		}
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var val = e.target.value;
				if (!val) {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'view',
						'preferredConverter',
						'',
						true
					);
				} else {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'view',
						'preferredConverter',
						normalizePreferredConverter(val),
						false
					);
				}
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesPreferredConverterDefault ||
						'Type default') +
					(typeFormat
						? ' (' +
						  preferredConverterOptionLabel(typeFormat, typeFormat) +
						  ')'
						: ''),
				selected: !hasOverride,
			})
		);
		convOpts.forEach(function (o) {
			select.appendChild(
				el('option', {
					value: o.value,
					text: o.label,
					selected: !!hasOverride && current === o.value,
				})
			);
		});
		applySoleRequiredListLock(select, countRealSelectOptions(select), {
			allowEmpty: true,
			disabled: !editable,
		});
		var onReset = function () {
			saveAttributeWalkSettings(
				hostId,
				attrId,
				path,
				'view',
				'preferredConverter',
				'',
				true
			);
		};
		if (compact) {
			return wrapWalkCompactCell(
				i18n.preferredConverterShort ||
					i18n.preferredConverter ||
					'Converter',
				select,
				hasOverride,
				onReset,
				editable
			);
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__walk-field' +
					(hasOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.preferredConverterShort ||
						i18n.preferredConverter ||
						'Converter',
					hasOverride,
					onReset,
					editable
				),
				select,
			]
		);
	}

	function renderWalkDateModeField(
		level,
		path,
		editable,
		hostId,
		attrId,
		compact
	) {
		var typeMode = level.typeDateMode || 'date';
		var hasOverride = !!level.hasDateModeOverride;
		var current = hasOverride ? String(level.dateMode || '') : '';
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var val = e.target.value;
				if (!val) {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'data',
						'dateMode',
						'',
						true
					);
				} else {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'data',
						'dateMode',
						val === 'datetime' ? 'datetime' : 'date',
						false
					);
				}
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesDateModeDefault || 'Type default') +
					' (' +
					(typeMode === 'datetime'
						? i18n.dateModeDateTime || 'Date and time'
						: i18n.dateModeDate || 'Date only') +
					')',
				selected: !hasOverride,
			})
		);
		select.appendChild(
			el('option', {
				value: 'date',
				text: i18n.dateModeDate || 'Date only',
				selected: current === 'date',
			})
		);
		select.appendChild(
			el('option', {
				value: 'datetime',
				text: i18n.dateModeDateTime || 'Date and time',
				selected: current === 'datetime',
			})
		);
		var onReset = function () {
			saveAttributeWalkSettings(
				hostId,
				attrId,
				path,
				'data',
				'dateMode',
				'',
				true
			);
		};
		if (compact) {
			return wrapWalkCompactCell(
				i18n.attributesDateModeShort ||
					i18n.attributesDateMode ||
					'Date',
				select,
				hasOverride,
				onReset,
				editable
			);
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__walk-field' +
					(hasOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.attributesDateMode || 'Date mode',
					hasOverride,
					onReset,
					editable
				),
				select,
			]
		);
	}

	function renderWalkValidatorsField(level, path, typeKey, editable, hostId, attrId) {
		var probe = {
			id: level.nodeId,
			name: level.name,
			typeKey: typeKey || '',
			type: typeKey ? { name: typeKey } : null,
			typeId: level.nodeId,
			validators: level.validators || [],
		};
		var hasOverride = !!level.hasValidatorsOverride;
		var typeList = Array.isArray(level.typeValidators)
			? level.typeValidators
			: [];
		var list = hasOverride
			? normalizeValidatorsList(level.validators || [], probe)
			: normalizeValidatorsList(
					typeList.length ? typeList : level.validators || [],
					probe
			  );

		var editor = buildValidatorsEditor({
			probe: probe,
			list: list,
			locked: !editable,
			wrapClass: 'wtt-validators-editor--walk',
			onChange: function (next) {
				saveAttributeWalkSettings(
					hostId,
					attrId,
					path,
					'data',
					'validators',
					next,
					false
				);
			},
		});

		if (!editor && !hasOverride) {
			return null;
		}

		var block = el('div', {
			className:
				'wtt-attributes__walk-field wtt-attributes__walk-field--validators' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		block.appendChild(
			renderAttrRelationOverrideHead(
				i18n.attributesValidators || 'Validators',
				hasOverride,
				function () {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'data',
						'validators',
						null,
						true
					);
				},
				editable
			)
		);
		if (!hasOverride) {
			block.appendChild(
				el('span', {
					className: 'wtt-field-hint',
					text: i18n.attributesValidatorsDefault || 'Type default',
				})
			);
		}
		if (editor) {
			var pair = el('div', {
				className: 'wtt-validators-editor__chrome',
			});
			pair.appendChild(
				el('div', { className: 'wtt-validators-editor__add-row' }, [
					el('span', {
						className: 'wtt-preferred-pair__label',
						text: i18n.validators || 'Validators',
					}),
					editor.addSelect,
				])
			);
			pair.appendChild(editor.tableWrap);
			block.appendChild(pair);
		} else if (hasOverride) {
			block.appendChild(
				el('span', {
					className: 'description wtt-validators-table__empty-hint',
					text:
						i18n.validatorsEmptyHint ||
						'No validators yet — use Add validator to add one.',
				})
			);
		}
		return block;
	}

	/**
	 * Walk-Wizard: Settings.data.allowedPrefixIds on unit / With-prefix levels.
	 * Catalog unit marriage stays on the type node; this is an attribute Relation delta.
	 */
	function renderWalkAllowedPrefixesField(level, path, editable, hostId, attrId) {
		if (!level || !level.supportsPrefixAllowlist) {
			return null;
		}
		var catalog = Array.isArray(level.prefixCatalog) ? level.prefixCatalog : [];
		if (!catalog.length) {
			return null;
		}
		var hasOverride = !!level.hasAllowedPrefixIdsOverride;
		var typeIds = Array.isArray(level.typeAllowedPrefixIds)
			? level.typeAllowedPrefixIds.map(function (id) {
					return parseInt(id, 10) || 0;
			  }).filter(function (id) {
					return id > 0;
			  })
			: [];
		var activeIds = hasOverride
			? (Array.isArray(level.allowedPrefixIds) ? level.allowedPrefixIds : [])
					.map(function (id) {
						return parseInt(id, 10) || 0;
					})
					.filter(function (id) {
						return id > 0;
					})
			: typeIds.slice();
		var activeMap = {};
		activeIds.forEach(function (id) {
			activeMap[String(id)] = true;
		});
		var isUnitLeaf = String(level.prefixAllowlistSource || '') === 'unit';

		function persistFromChecks(root) {
			var next = [];
			var boxes = root.querySelectorAll(
				'input[type="checkbox"][data-prefix-id]'
			);
			boxes.forEach(function (box) {
				if (!box.checked) {
					return;
				}
				var id = parseInt(box.getAttribute('data-prefix-id'), 10) || 0;
				if (id > 0) {
					next.push(id);
				}
			});
			saveAttributeWalkSettings(
				hostId,
				attrId,
				path,
				'data',
				'allowedPrefixIds',
				next,
				false
			);
		}

		var block = el('div', {
			className:
				'wtt-attributes__walk-field wtt-attributes__walk-field--prefixes' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		block.appendChild(
			renderAttrRelationOverrideHead(
				i18n.allowedPrefixesTitle || 'Allowed prefixes',
				hasOverride,
				function () {
					saveAttributeWalkSettings(
						hostId,
						attrId,
						path,
						'data',
						'allowedPrefixIds',
						null,
						true
					);
				},
				editable
			)
		);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text: hasOverride
					? i18n.attributesAllowedPrefixesOverrideHint ||
					  'Relation override: restricts prefixes for this attribute (intersected with each unit’s catalog marriage). Empty = value + unit only.'
					: isUnitLeaf
					  ? i18n.attributesAllowedPrefixesUnitDefault ||
					    'Type default: unit catalog prefix marriage. Change to override on this attribute Relation.'
					  : i18n.attributesAllowedPrefixesWithPrefixDefault ||
					    'Type default: each unit keeps its catalog prefix marriage. Override to restrict prefixes for this attribute.',
			})
		);
		var checks = el('div', {
			className: 'wtt-attributes__walk-prefixes',
		});
		catalog.forEach(function (p) {
			if (!p || p.id == null) {
				return;
			}
			var id = parseInt(p.id, 10) || 0;
			if (id <= 0) {
				return;
			}
			var row = el('label', {
				className: 'wtt-attributes__walk-prefix-item',
			});
			var box = el('input', {
				type: 'checkbox',
				disabled: !editable,
				checked: !!activeMap[String(id)],
				'data-prefix-id': String(id),
			});
			box.addEventListener('change', function () {
				persistFromChecks(checks);
			});
			row.appendChild(box);
			var label = p.name || String(id);
			if (p.shortDescription) {
				label += ' (' + String(p.shortDescription) + ')';
			}
			row.appendChild(document.createTextNode(' ' + label));
			checks.appendChild(row);
		});
		block.appendChild(checks);
		return block;
	}

	function renderAttrPreferredChromePair(n, attr, extras, editable, hostId, attrId) {
		var typeDefault = normalizePreferredRender(
			attr.typePreferredRender || 'FormRenderer'
		);
		var hasOverride = !!attr.preferredRenderOverride;
		var edgeExtras = typeExtrasFromEdgeSettings(attr);
		var probe = {
			id: attr.id,
			name: attr.name,
			typeKey: attr.typeKey || '',
			type: attr.typeKey ? { name: attr.typeKey } : null,
			typeId: attr.typeId,
			quantitySchema: attr.quantitySchema,
			preferredRender: hasOverride
				? attr.preferredRender
				: typeDefault,
		};
		var renderOpts = listCompatiblePreferredOptions(probe);
		var renderSelect = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				saveAttributePreferredRender(hostId, attrId, e.target.value);
			},
		});
		renderSelect.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesPreferredRenderDefault || 'Type default') +
					' (' +
					preferredRenderOptionLabel(typeDefault) +
					')',
				selected: !hasOverride,
			})
		);
		renderOpts.forEach(function (o) {
			renderSelect.appendChild(
				el('option', {
					value: o.value,
					text: o.label,
					selected:
						hasOverride &&
						normalizePreferredRender(attr.preferredRender) ===
							o.value,
				})
			);
		});
		/*
		 * Attribute Preferred is an override (Type default = empty) → allowEmpty.
		 * Sole lock only if no empty option and one real choice (should not happen here).
		 */
		applySoleRequiredListLock(
			renderSelect,
			countRealSelectOptions(renderSelect),
			{
				allowEmpty: true,
				disabled: !editable,
			}
		);

		var typeFormat = normalizePreferredConverter(
			attr.typePreferredConverter ||
				(attr.intConfig && attr.intConfig.typeFormat) ||
				''
		);
		/*
		 * Override paint: edge Settings / typeExtras first; settingsResolved only
		 * when decorate_row already marked an override (avoids type-live false positives).
		 */
		var hasConvOverride =
			!!(
				edgeExtras.preferredConverter ||
				edgeExtras.displayFormat ||
				(attr.intConfig && attr.intConfig.hasOverride)
			);
		var currentConv = normalizePreferredConverter(
			(extras.preferredConverter != null && extras.preferredConverter !== ''
				? extras.preferredConverter
				: extras.displayFormat) || ''
		);
		if (
			!currentConv &&
			attr.intConfig &&
			attr.intConfig.hasOverride
		) {
			currentConv = normalizePreferredConverter(
				attr.preferredConverter ||
					attr.intConfig.displayFormat ||
					settingsViewString(
						attr.settingsResolved && attr.settingsResolved.view,
						'preferredConverter'
					) ||
					''
			);
		}
		if (currentConv) {
			hasConvOverride = true;
		}
		var convProbe = {
			id: attr.id,
			name: attr.name,
			typeKey: attr.typeKey || '',
			type: attr.typeKey ? { name: attr.typeKey } : null,
			typeId: attr.typeId,
			preferredConverter: currentConv || typeFormat || '',
		};
		var convOpts = listCompatiblePreferredConverterOptions(convProbe);
		var converterSelect = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable || !convOpts.length,
			onChange: function (e) {
				var next = Object.assign({}, extras);
				var val = e.target.value;
				if (!val) {
					delete next.displayFormat;
					delete next.preferredConverter;
				} else {
					next.preferredConverter = normalizePreferredConverter(val);
					next.displayFormat = next.preferredConverter;
				}
				saveAttributeTypeExtras(hostId, attrId, next);
			},
		});
		if (!convOpts.length) {
			converterSelect.appendChild(
				el('option', {
					value: '',
					text:
						i18n.preferredConverterNoneShort ||
						i18n.preferredConverterNone ||
						'None',
					title:
						i18n.preferredConverterNone ||
						'None (no converters for this type)',
				})
			);
		} else {
			converterSelect.appendChild(
				el('option', {
					value: '',
					text:
						(i18n.attributesPreferredConverterDefault ||
							'Type default') +
						(typeFormat
							? ' (' +
							  preferredConverterOptionLabel(typeFormat, typeFormat) +
							  ')'
							: ''),
					selected: !currentConv,
				})
			);
			convOpts.forEach(function (o) {
				converterSelect.appendChild(
					el('option', {
						value: o.value,
						text: o.label,
						selected: !!currentConv && currentConv === o.value,
					})
				);
			});
		}
		applySoleRequiredListLock(
			converterSelect,
			countRealSelectOptions(converterSelect),
			{
				allowEmpty: true,
				disabled: !editable || !convOpts.length,
			}
		);

		function clearPreferredOverride() {
			saveAttributePreferredRender(hostId, attrId, '');
		}
		function clearConverterOverride() {
			var next = Object.assign({}, extras);
			delete next.displayFormat;
			delete next.preferredConverter;
			saveAttributeTypeExtras(hostId, attrId, next);
		}

		var SR = settingsRender();
		var renderHead = renderAttrRelationOverrideHead(
			i18n.preferredRenderShort || i18n.preferredRender || 'Render',
			hasOverride,
			clearPreferredOverride,
			editable
		);
		var convHead = renderAttrRelationOverrideHead(
			i18n.preferredConverterShort ||
				i18n.preferredConverter ||
				'Converter',
			hasConvOverride,
			clearConverterOverride,
			editable
		);
		if (SR && typeof SR.renderPreferredChrome === 'function') {
			return SR.renderPreferredChrome({
				className:
					(hasOverride || hasConvOverride
						? 'has-relation-override'
						: '') + ' wtt-settings-preferred--attribute',
				render: {
					labelNode: renderHead,
					select: renderSelect,
				},
				converter: {
					labelNode: convHead,
					select: converterSelect,
				},
			});
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__detail-block wtt-attributes__detail-block--preferred-stack' +
					(hasOverride || hasConvOverride
						? ' has-relation-override'
						: ''),
			},
			[
				el('div', { className: 'wtt-preferred-stack' }, [
					el('div', { className: 'wtt-preferred-stack__item' }, [
						renderHead,
						renderSelect,
					]),
					el('div', { className: 'wtt-preferred-stack__item' }, [
						convHead,
						converterSelect,
					]),
				]),
			]
		);
	}

	/**
	 * Attribute Options: Preferred R/C/V via shared Settings renderer (Q114).
	 */
	function renderAttrSettingsPreferredChrome(
		n,
		attr,
		extras,
		preferredEditable,
		validatorsEditable,
		hostId,
		attrId
	) {
		var preferred = renderAttrPreferredChromePair(
			n,
			attr,
			extras,
			preferredEditable,
			hostId,
			attrId
		);
		var validators = renderAttrValidatorsDetail(
			n,
			attr,
			extras,
			validatorsEditable,
			hostId,
			attrId
		);
		var SR = settingsRender();
		if (
			!SR ||
			typeof SR.renderPreferredChrome !== 'function' ||
			!preferred ||
			!validators
		) {
			var wrap = el('div', {
				className: 'wtt-settings-preferred-chrome wtt-settings-preferred--attribute',
			});
			if (preferred) {
				wrap.appendChild(preferred);
			}
			if (validators) {
				wrap.appendChild(validators);
			}
			return wrap;
		}
		/*
		 * Preferred pair already painted via SR inside renderAttrPreferredChromePair.
		 * Append validators detail under the same chrome root when possible.
		 */
		var root = el('div', {
			className: 'wtt-settings-preferred-chrome wtt-settings-preferred--attribute',
		});
		root.appendChild(preferred);
		root.appendChild(validators);
		return root;
	}

	function renderAttrPreferredRenderDetail(n, attr, editable, hostId, attrId) {
		return renderAttrPreferredChromePair(
			n,
			attr,
			attributeOptionsExtras(attr),
			editable,
			hostId,
			attrId
		);
	}

	function renderAttrDateModeDetail(n, attr, extras, editable, hostId, attrId) {
		var typeMode =
			(attr.dateConfig && attr.dateConfig.typeMode) ||
			'date';
		var edgeExtras = typeExtrasFromEdgeSettings(attr);
		var hasDateOverride =
			Object.prototype.hasOwnProperty.call(edgeExtras, 'dateMode') ||
			!!(attr.dateConfig && attr.dateConfig.hasOverride);
		var current =
			extras.dateMode != null && extras.dateMode !== ''
				? extras.dateMode
				: '';
		if (
			!current &&
			attr.dateConfig &&
			attr.dateConfig.hasOverride &&
			attr.dateConfig.mode
		) {
			current = attr.dateConfig.mode;
		} else if (
			!current &&
			attr.dateConfig &&
			attr.dateConfig.hasOverride &&
			attr.settingsResolved &&
			attr.settingsResolved.data &&
			attr.settingsResolved.data.dateMode
		) {
			current = attr.settingsResolved.data.dateMode;
		}
		if (current) {
			hasDateOverride = true;
		}
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var next = Object.assign({}, extras);
				var val = e.target.value;
				if (!val) {
					delete next.dateMode;
				} else {
					next.dateMode = val === 'datetime' ? 'datetime' : 'date';
				}
				saveAttributeTypeExtras(hostId, attrId, next);
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesDateModeDefault || 'Type default') +
					' (' +
					(typeMode === 'datetime'
						? i18n.dateModeDateTime || 'Date and time'
						: i18n.dateModeDate || 'Date only') +
					')',
				selected: !current,
			})
		);
		select.appendChild(
			el('option', {
				value: 'date',
				text: i18n.dateModeDate || 'Date only',
				selected: current === 'date',
			})
		);
		select.appendChild(
			el('option', {
				value: 'datetime',
				text: i18n.dateModeDateTime || 'Date and time',
				selected: current === 'datetime',
			})
		);
		function clearDateOverride() {
			var next = Object.assign({}, extras);
			delete next.dateMode;
			saveAttributeTypeExtras(hostId, attrId, next);
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__detail-block wtt-attributes__detail-block--date' +
					(hasDateOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.attributesDateMode || 'Date mode',
					hasDateOverride,
					clearDateOverride,
					editable
				),
				select,
			]
		);
	}

	function renderAttrTextareaLayoutDetail(n, attr, extras, editable, hostId, attrId) {
		var typeCols =
			(attr.textareaConfig && attr.textareaConfig.typeCols) || 40;
		var typeRows =
			(attr.textareaConfig && attr.textareaConfig.typeRows) || 4;
		var edgeExtras = typeExtrasFromEdgeSettings(attr);
		var hasOverride =
			Object.prototype.hasOwnProperty.call(edgeExtras, 'textareaCols') ||
			Object.prototype.hasOwnProperty.call(edgeExtras, 'textareaRows') ||
			!!(attr.textareaConfig && attr.textareaConfig.hasOverride);
		var curCols =
			extras.textareaCols != null
				? parseInt(extras.textareaCols, 10)
				: attr.textareaConfig && attr.textareaConfig.hasOverride
					? parseInt(attr.textareaConfig.cols, 10)
					: NaN;
		var curRows =
			extras.textareaRows != null
				? parseInt(extras.textareaRows, 10)
				: attr.textareaConfig && attr.textareaConfig.hasOverride
					? parseInt(attr.textareaConfig.rows, 10)
					: NaN;
		if (isFinite(curCols) || isFinite(curRows)) {
			hasOverride = true;
		}
		var showCols = isFinite(curCols) ? curCols : typeCols;
		var showRows = isFinite(curRows) ? curRows : typeRows;

		function persist(nextCols, nextRows, asOverride) {
			var next = Object.assign({}, extras);
			if (!asOverride) {
				delete next.textareaCols;
				delete next.textareaRows;
			} else {
				next.textareaCols = nextCols;
				next.textareaRows = nextRows;
			}
			saveAttributeTypeExtras(hostId, attrId, next);
		}

		var colsInput = el('input', {
			type: 'number',
			className: 'wtt-attributes__detail-select wtt-attributes__textarea-cols',
			min: '1',
			max: '200',
			step: '1',
			value: String(showCols),
			disabled: !editable,
		});
		var rowsInput = el('input', {
			type: 'number',
			className: 'wtt-attributes__detail-select wtt-attributes__textarea-rows',
			min: '1',
			max: '100',
			step: '1',
			value: String(showRows),
			disabled: !editable,
		});
		function onChange() {
			var c = parseInt(colsInput.value, 10);
			var r = parseInt(rowsInput.value, 10);
			if (!isFinite(c) || c < 1) {
				c = typeCols;
			}
			if (!isFinite(r) || r < 1) {
				r = typeRows;
			}
			persist(c, r, true);
		}
		colsInput.addEventListener('change', onChange);
		rowsInput.addEventListener('change', onChange);

		var fields = el('div', { className: 'wtt-attributes__textarea-layout' }, [
			el('label', null, [
				document.createTextNode(
					(i18n.textareaCols || 'Columns') + ' '
				),
				colsInput,
			]),
			el('label', null, [
				document.createTextNode(
					(i18n.textareaRows || 'Lines') + ' '
				),
				rowsInput,
			]),
		]);

		return el(
			'div',
			{
				className:
					'wtt-attributes__detail-block wtt-attributes__detail-block--textarea' +
					(hasOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.textareaSettings || 'Textarea layout',
					hasOverride,
					function () {
						persist(typeCols, typeRows, false);
					},
					editable
				),
				el('span', {
					className: 'wtt-field-hint',
					text:
						(i18n.attributesTextareaDefault || 'Type default') +
						' (' +
						typeCols +
						'×' +
						typeRows +
						')',
				}),
				fields,
			]
		);
	}

	function renderAttrPresentationContextDetail(n, attr, extras, editable, hostId, attrId) {
		var typeCtx = normalizePresentationContext(
			(attr.presentationConfig && attr.presentationConfig.typeContext) ||
				(attr.presentationConfig && attr.presentationConfig.context) ||
				'form'
		);
		var edgeExtras = typeExtrasFromEdgeSettings(attr);
		var hasOverride =
			Object.prototype.hasOwnProperty.call(edgeExtras, 'presentationContext') ||
			!!(attr.presentationConfig && attr.presentationConfig.hasOverride);
		var current =
			extras.presentationContext != null && extras.presentationContext !== ''
				? normalizePresentationContext(extras.presentationContext)
				: '';
		if (
			!current &&
			attr.presentationConfig &&
			attr.presentationConfig.hasOverride &&
			attr.presentationConfig.context
		) {
			current = normalizePresentationContext(attr.presentationConfig.context);
		}
		if (current) {
			hasOverride = true;
		}
		var typeLabel =
			presentationContextOptions().find(function (o) {
				return o.value === typeCtx;
			}) || { label: typeCtx };
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var next = Object.assign({}, extras);
				var val = e.target.value;
				if (!val) {
					delete next.presentationContext;
				} else {
					next.presentationContext = normalizePresentationContext(val);
				}
				saveAttributeTypeExtras(hostId, attrId, next);
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesPresentationContextDefault || 'Type default') +
					' (' +
					typeLabel.label +
					')',
				selected: !current,
			})
		);
		presentationContextOptions().forEach(function (opt) {
			select.appendChild(
				el('option', {
					value: opt.value,
					text: opt.label,
					selected: current === opt.value,
				})
			);
		});
		function clearPresentationOverride() {
			var next = Object.assign({}, extras);
			delete next.presentationContext;
			saveAttributeTypeExtras(hostId, attrId, next);
		}
		return el(
			'div',
			{
				className:
					'wtt-attributes__detail-block wtt-attributes__detail-block--presentation' +
					(hasOverride ? ' has-relation-override' : ''),
			},
			[
				renderAttrRelationOverrideHead(
					i18n.attributesPresentationContext || 'Presentation field',
					hasOverride,
					clearPresentationOverride,
					editable
				),
				select,
			]
		);
	}

	function renderAttrIntFormatDetail(n, attr, extras, editable, hostId, attrId) {
		var typeFormat = normalizePreferredConverter(
			attr.typePreferredConverter ||
				(attr.intConfig && attr.intConfig.typeFormat) ||
				'arabic'
		) || 'arabic';
		var current = normalizePreferredConverter(
			(extras.preferredConverter != null && extras.preferredConverter !== ''
				? extras.preferredConverter
				: extras.displayFormat) || ''
		);
		if (!current && attr.intConfig && attr.intConfig.hasOverride) {
			current = normalizePreferredConverter(
				attr.preferredConverter ||
					attr.intConfig.displayFormat ||
					settingsViewString(
						attr.settingsResolved && attr.settingsResolved.view,
						'preferredConverter'
					) ||
					''
			);
		}
		var probe = {
			name: 'int',
			typeKey: 'int',
			type: { name: 'int' },
			preferredConverter: current || typeFormat,
		};
		var opts = listCompatiblePreferredConverterOptions(probe);
		if (!opts.length) {
			return null;
		}
		var select = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var next = Object.assign({}, extras);
				var val = e.target.value;
				if (!val) {
					delete next.displayFormat;
					delete next.preferredConverter;
				} else {
					next.preferredConverter = normalizePreferredConverter(val);
					next.displayFormat = next.preferredConverter;
				}
				saveAttributeTypeExtras(hostId, attrId, next);
			},
		});
		select.appendChild(
			el('option', {
				value: '',
				text:
					(i18n.attributesPreferredConverterDefault ||
						i18n.attributesIntFormatDefault ||
						'Type default') +
					' (' +
					preferredConverterOptionLabel(typeFormat) +
					')',
				selected: !current,
			})
		);
		opts.forEach(function (opt) {
			select.appendChild(
				el('option', {
					value: opt.value,
					text: opt.label,
					selected: current === opt.value,
				})
			);
		});
		return el(
			'div',
			{
				className:
					'wtt-attributes__detail-block wtt-attributes__detail-block--int',
			},
			[
				el('span', {
					className: 'wtt-attributes__detail-label',
					text:
						i18n.preferredConverter ||
						i18n.attributesIntFormat ||
						'Preferred converter',
				}),
				select,
			]
		);
	}

	function collectChoiceFilterNodeIds(roots) {
		var ids = [];
		function walk(node) {
			if (!node || node.id == null) {
				return;
			}
			var id = parseInt(node.id, 10) || 0;
			if (id > 0) {
				ids.push(id);
			}
			(Array.isArray(node.children) ? node.children : []).forEach(walk);
		}
		(roots || []).forEach(walk);
		return ids;
	}

	/**
	 * Build excluded-id map from a choiceFilter payload (exclude or legacy include).
	 *
	 * @param {object|null} filter
	 * @param {number[]}    allIds
	 * @return {Object<number, boolean>}
	 */
	function choiceFilterToExcluded(filter, allIds) {
		var excluded = {};
		if (!filter || !Array.isArray(filter.ids) || !filter.ids.length) {
			return excluded;
		}
		if (filter.mode === 'include') {
			var includeSet = {};
			filter.ids.forEach(function (id) {
				includeSet[parseInt(id, 10)] = true;
			});
			(allIds || []).forEach(function (id) {
				if (!includeSet[id]) {
					excluded[id] = true;
				}
			});
			return excluded;
		}
		filter.ids.forEach(function (id) {
			excluded[parseInt(id, 10)] = true;
		});
		return excluded;
	}

	/**
	 * @param {Object<number, boolean>} excluded
	 * @return {number[]}
	 */
	function excludedChoiceIds(excluded) {
		return Object.keys(excluded || {})
			.map(function (k) {
				return parseInt(k, 10);
			})
			.filter(function (id) {
				return id > 0 && excluded[id];
			});
	}

	function choiceFilterDraftKey(kind, hostId, attrId, path) {
		return [kind, String(hostId || 0), String(attrId || ''), String(path || '')].join(
			'\0'
		);
	}

	function getChoiceFilterDraft(key) {
		var bag = state.choiceFilterDrafts;
		return bag && bag[key] ? bag[key] : null;
	}

	function setChoiceFilterDraft(key, draft) {
		if (!state.choiceFilterDrafts) {
			state.choiceFilterDrafts = {};
		}
		state.choiceFilterDrafts[key] = draft;
	}

	function clearChoiceFilterDraft(key) {
		if (state.choiceFilterDrafts) {
			delete state.choiceFilterDrafts[key];
		}
	}

	/**
	 * Persist one deferred Choices draft (walk path or attr typeExtras).
	 *
	 * @param {object} draft
	 * @return {Promise}
	 */
	function persistChoiceFilterDraft(draft) {
		if (!draft || !draft.dirty) {
			return Promise.resolve();
		}
		var ids = excludedChoiceIds(draft.excluded);
		var scroll = capturePaneScroll();
		var key = draft.key;
		clearChoiceFilterDraft(key);
		var req;
		if (draft.kind === 'walk') {
			if (!ids.length) {
				req = saveAttributeWalkSettings(
					draft.hostId,
					draft.attrId,
					draft.path,
					'data',
					'choiceFilter',
					null,
					true
				);
			} else {
				req = saveAttributeWalkSettings(
					draft.hostId,
					draft.attrId,
					draft.path,
					'data',
					'choiceFilter',
					{ mode: 'exclude', ids: ids },
					false
				);
			}
		} else {
			var extras = Object.assign({}, draft.extras || {});
			if (!ids.length) {
				delete extras.choiceFilter;
			} else {
				extras.choiceFilter = { mode: 'exclude', ids: ids };
			}
			req = saveAttributeTypeExtras(draft.hostId, draft.attrId, extras);
		}
		return Promise.resolve(req)
			.then(function () {
				restorePaneScroll(scroll);
			})
			.catch(function () {
				setChoiceFilterDraft(key, draft);
				restorePaneScroll(scroll);
			});
	}

	/**
	 * Flush all pending Choices drafts (node leave / before structural navigate).
	 *
	 * @return {Promise}
	 */
	function flushPendingChoiceFilterDrafts() {
		var bag = state.choiceFilterDrafts || {};
		var drafts = Object.keys(bag)
			.map(function (k) {
				return bag[k];
			})
			.filter(function (d) {
				return d && d.dirty;
			});
		if (!drafts.length) {
			return Promise.resolve();
		}
		return Promise.all(drafts.map(persistChoiceFilterDraft));
	}

	/**
	 * Choices: tick locally; save when focus/pointer leaves the block.
	 *
	 * @param {HTMLElement} block
	 * @param {string}      key
	 * @param {function(): object} buildDraft  returns draft payload (dirty+excluded+…)
	 */
	function bindDeferredChoiceFilter(block, key, buildDraft) {
		if (!block) {
			return;
		}
		function markLocalDirty() {
			var draft = buildDraft();
			draft.key = key;
			draft.dirty = true;
			setChoiceFilterDraft(key, draft);
			block.classList.add('is-choice-dirty');
		}
		function tryFlush() {
			var draft = getChoiceFilterDraft(key);
			if (!draft || !draft.dirty) {
				block.classList.remove('is-choice-dirty');
				return;
			}
			if (block.contains(document.activeElement)) {
				return;
			}
			block.classList.remove('is-choice-dirty');
			persistChoiceFilterDraft(draft);
		}
		block.addEventListener('focusout', function () {
			window.setTimeout(tryFlush, 0);
		});
		block.addEventListener('mouseleave', function () {
			window.setTimeout(tryFlush, 120);
		});
		block._wttMarkChoiceDirty = markLocalDirty;
		block._wttFlushChoice = tryFlush;
	}

	/**
	 * Choice allowlist UI: all options checked by default; uncheck = exclude.
	 * Persists as { mode:'exclude', ids:[unchecked] }. Legacy include filters migrate in UI.
	 * Saves when leaving the Choices block (not on every tick).
	 */
	function renderAttrChoiceFilterDetail(n, attr, extras, editable, hostId, attrId) {
		var filter =
			extras.choiceFilter && typeof extras.choiceFilter === 'object'
				? extras.choiceFilter
				: null;
		var roots = attributeChoiceFilterRoots(attr);
		var allIds = collectChoiceFilterNodeIds(roots);
		var draftKey = choiceFilterDraftKey('attr', hostId, attrId, '');
		var draft = getChoiceFilterDraft(draftKey);
		var excluded = draft && draft.dirty
			? Object.assign({}, draft.excluded || {})
			: choiceFilterToExcluded(filter, allIds);

		var block = el('div', {
			className:
				'wtt-attributes__detail-block wtt-attributes__detail-block--choice' +
				(draft && draft.dirty ? ' is-choice-dirty' : ''),
		});
		block.appendChild(
			el('span', {
				className: 'wtt-attributes__detail-label',
				text: i18n.attributesChoiceFilter || 'Choices',
			})
		);

		var treeWrap = el('div', {
			className: 'wtt-attributes__choice-tree',
			tabIndex: editable ? 0 : -1,
		});
		function paintNode(node, depth) {
			if (!node || node.id == null) {
				return;
			}
			var id = parseInt(node.id, 10) || 0;
			if (!id) {
				return;
			}
			var row = el('label', {
				className: 'wtt-attributes__choice-item',
				style: 'padding-left:' + String(depth * 12) + 'px',
			});
			var check = el('input', {
				type: 'checkbox',
				disabled: !editable,
				checked: !excluded[id],
			});
			check.addEventListener('change', function () {
				if (check.checked) {
					delete excluded[id];
				} else {
					excluded[id] = true;
				}
				if (typeof block._wttMarkChoiceDirty === 'function') {
					block._wttMarkChoiceDirty();
				}
			});
			row.appendChild(check);
			row.appendChild(
				document.createTextNode(' ' + (node.name || '#' + id))
			);
			treeWrap.appendChild(row);
			(Array.isArray(node.children) ? node.children : []).forEach(function (
				child
			) {
				paintNode(child, depth + 1);
			});
		}
		if (roots && roots.length) {
			roots.forEach(function (r) {
				paintNode(r, 0);
			});
		} else {
			treeWrap.appendChild(
				el('span', {
					className: 'wtt-field-hint',
					text:
						i18n.attributesChoiceEmpty ||
						'No specialization children under this type.',
				})
			);
		}
		block.appendChild(treeWrap);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.attributesChoiceFilterDeferHint ||
					'Tick freely — choices save when you leave this list.',
			})
		);
		if (editable) {
			bindDeferredChoiceFilter(block, draftKey, function () {
				return {
					kind: 'attr',
					hostId: hostId,
					attrId: attrId,
					path: '',
					extras: Object.assign({}, extras),
					excluded: Object.assign({}, excluded),
				};
			});
		}
		return block;
	}

	/**
	 * Attribute validators — same table chrome as type Settings / walk.
	 */
	function renderAttrValidatorsDetail(n, attr, extras, editable, hostId, attrId) {
		var probe = {
			id: attr.id,
			name: attr.name,
			typeKey: attr.typeKey || '',
			type: attr.typeKey ? { name: attr.typeKey } : null,
			typeId: attr.typeId,
			validators: attr.validators || [],
		};
		var typeList = Array.isArray(attr.typeValidators)
			? attr.typeValidators
			: [];
		var hasOverride = !!attr.validatorsOverride;
		var overrideList = Array.isArray(extras.validators)
			? extras.validators
			: hasOverride &&
				  attr.settingsResolved &&
				  attr.settingsResolved.data &&
				  Array.isArray(attr.settingsResolved.data.validators)
				? attr.settingsResolved.data.validators
				: hasOverride && Array.isArray(attr.validators)
					? attr.validators
					: null;
		var list = hasOverride
			? normalizeValidatorsList(overrideList || [], probe)
			: normalizeValidatorsList(
					typeList.length ? typeList : attr.validators || [],
					probe
			  );

		function persist(nextList, asOverride) {
			var next = Object.assign({}, extras);
			if (!asOverride) {
				delete next.validators;
			} else {
				next.validators = normalizeValidatorsList(nextList, probe);
			}
			saveAttributeTypeExtras(hostId, attrId, next);
		}

		var editor = buildValidatorsEditor({
			probe: probe,
			list: list,
			locked: !editable,
			wrapClass: 'wtt-validators-editor--attr-detail',
			onChange: function (next) {
				persist(next, true);
			},
		});

		if (!editor && !hasOverride) {
			return el(
				'div',
				{
					className:
						'wtt-attributes__detail-block wtt-attributes__detail-block--validators is-empty',
				},
				[
					el('span', {
						className: 'wtt-attributes__detail-label',
						text: i18n.attributesValidators || 'Validators',
					}),
					el('span', {
						className: 'wtt-field-hint',
						text:
							i18n.validatorsEmptyHint ||
							'No validators yet — use Add validator to add one.',
					}),
				]
			);
		}

		var block = el('div', {
			className:
				'wtt-attributes__detail-block wtt-attributes__detail-block--validators' +
				(hasOverride ? ' has-relation-override' : ''),
		});
		block.appendChild(
			renderAttrRelationOverrideHead(
				i18n.attributesValidators || 'Validators',
				hasOverride,
				function () {
					persist([], false);
				},
				editable
			)
		);
		if (!hasOverride) {
			block.appendChild(
				el('span', {
					className: 'wtt-field-hint wtt-attributes__validators-default',
					text: i18n.attributesValidatorsDefault || 'Type default',
				})
			);
		}
		if (editor) {
			var pair = el('div', {
				className: 'wtt-validators-editor__chrome',
			});
			pair.appendChild(
				el('div', { className: 'wtt-validators-editor__add-row' }, [
					el('span', {
						className: 'wtt-preferred-pair__label',
						text: i18n.validators || 'Validators',
					}),
					editor.addSelect,
				])
			);
			pair.appendChild(editor.tableWrap);
			block.appendChild(pair);
		}
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.attributesValidatorsHint ||
					'Override type validators for this attribute. Empty override uses the type list.',
			})
		);
		return block;
	}

	function buildChoiceTreeFromFixedOptions(options) {
		var byId = {};
		var roots = [];
		(options || []).forEach(function (opt) {
			if (!opt || opt.id == null) {
				return;
			}
			var id = parseInt(opt.id, 10) || 0;
			if (!id) {
				return;
			}
			byId[id] = {
				id: id,
				name: opt.name || String(id),
				children: [],
			};
		});
		Object.keys(byId).forEach(function (k) {
			roots.push(byId[k]);
		});
		return roots;
	}

	function renderAttrComputeDetail(n, attr, extras, editable, hostId, attrId) {
		var compute =
			extras.compute && typeof extras.compute === 'object'
				? Object.assign({ sources: [] }, extras.compute)
				: { op: '', sources: [] };
		var peers = Array.isArray(n.attributes) ? n.attributes : [];
		var selfId = wttAttrId(attr);

		var block = el('div', {
			className:
				'wtt-attributes__detail-block wtt-attributes__detail-block--compute',
		});
		block.appendChild(
			el('span', {
				className: 'wtt-attributes__detail-label',
				text: i18n.attributesCompute || 'Compute',
			})
		);

		var opSelect = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
		});
		opSelect.appendChild(
			el('option', {
				value: '',
				text: i18n.attributesComputeOff || 'Off',
				selected: !compute.op,
			})
		);
		[
			{ key: 'sum', label: i18n.footerOpSum || 'Sum' },
			{ key: 'avg', label: i18n.footerOpAvg || 'Average' },
			{ key: 'min', label: i18n.footerOpMin || 'Minimum' },
			{ key: 'max', label: i18n.footerOpMax || 'Maximum' },
			{ key: 'count', label: i18n.footerOpCount || 'Count' },
		].forEach(function (op) {
			opSelect.appendChild(
				el('option', {
					value: op.key,
					text: op.label,
					selected: compute.op === op.key,
				})
			);
		});

		function persistCompute(nextCompute) {
			var next = Object.assign({}, extras);
			if (!nextCompute || !nextCompute.op || !nextCompute.sources.length) {
				delete next.compute;
			} else {
				next.compute = nextCompute;
			}
			saveAttributeTypeExtras(hostId, attrId, next);
		}

		opSelect.addEventListener('change', function () {
			var op = opSelect.value;
			if (!op) {
				persistCompute(null);
				return;
			}
			persistCompute({
				op: op,
				sources: Array.isArray(compute.sources) ? compute.sources : [],
			});
		});
		block.appendChild(opSelect);

		var sourcesWrap = el('div', {
			className: 'wtt-attributes__compute-sources',
		});
		function paintSources() {
			sourcesWrap.textContent = '';
			(Array.isArray(compute.sources) ? compute.sources : []).forEach(
				function (src, idx) {
					var row = el('div', {
						className: 'wtt-attributes__compute-source',
					});
					var kind = src && src.kind === 'attrPath' ? 'attrPath' : 'attr';
					var label = 'â€”';
					peers.forEach(function (p) {
						if (p && wttAttrId(p) === wttAttrId(src.attrId)) {
							label = p.name || '#' + src.attrId;
							if (kind === 'attrPath' && src.pathAttrId) {
								label +=
									' â†’ #' + String(src.pathAttrId);
							}
						}
					});
					row.appendChild(
						el('span', {
							className: 'wtt-attributes__compute-source-label',
							text: label,
						})
					);
					if (editable) {
						row.appendChild(
							el('button', {
								type: 'button',
								className:
									'button-link-delete wtt-row-edit-table__trash',
								title: i18n.attributesComputeRemoveSource || 'Remove source',
								'aria-label':
									i18n.attributesComputeRemoveSource ||
									'Remove source',
								html:
									'<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
								onClick: function () {
									var nextSources = (compute.sources || []).slice();
									nextSources.splice(idx, 1);
									persistCompute({
										op: compute.op || opSelect.value || 'sum',
										sources: nextSources,
									});
								},
							})
						);
					}
					sourcesWrap.appendChild(row);
				}
			);
		}
		paintSources();
		block.appendChild(sourcesWrap);

		if (editable) {
			var addWrap = el('div', {
				className: 'wtt-attributes__compute-add',
			});
			var peerSelect = el('select', {
				className: 'wtt-attributes__detail-select',
			});
			peerSelect.appendChild(
				el('option', {
					value: '',
					text: i18n.attributesComputePickSource || 'Add sourceâ€¦',
				})
			);
			peers.forEach(function (p) {
				if (!p || wttAttrId(p) === selfId) {
					return;
				}
				var many = !!p.allowsMany;
				peerSelect.appendChild(
					el('option', {
						value: String(p.id) + (many ? ':many' : ''),
						text:
							(p.name || '#' + p.id) +
							(many ? ' (0..*)' : ''),
					})
				);
			});
			var pathSelect = el('select', {
				className: 'wtt-attributes__detail-select',
				style: 'display:none',
			});
			peerSelect.addEventListener('change', function () {
				var val = peerSelect.value;
				pathSelect.style.display = 'none';
				pathSelect.textContent = '';
				if (!val) {
					return;
				}
				var parts = val.split(':');
				var pid = wttAttrId(parts[0]);
				var many = parts[1] === 'many';
				if (!many) {
					if (!pid) {
						return;
					}
					var nextSources = (compute.sources || []).slice();
					nextSources.push({ kind: 'attr', attrId: pid });
					persistCompute({
						op: compute.op || opSelect.value || 'sum',
						sources: nextSources,
					});
					peerSelect.value = '';
					return;
				}
				/* Mult-many: need path attr on linked type — list attrs of that type host if available. */
				pathSelect.style.display = '';
				pathSelect.appendChild(
					el('option', {
						value: '',
						text:
							i18n.attributesComputePickPath ||
							'Attribute on linked type…',
					})
				);
				var peer = null;
				peers.forEach(function (p) {
					if (p && wttAttrId(p) === pid) {
						peer = p;
					}
				});
				var typeId = peer ? parseInt(peer.typeId, 10) || 0 : 0;
				/* Load attributes of the type node from selectedNode cache if same tree. */
				var typeNode = typeId
					? findNodeInTree(state.tree, typeId)
					: null;
				var pathAttrs =
					typeNode && Array.isArray(typeNode.attributes)
						? typeNode.attributes
						: [];
				if (!pathAttrs.length && peer && Array.isArray(peer.fixedOptions)) {
					/* Fallback: no live attrs â€” user enters id via first numeric peer attrs of host as path candidates poorly. */
				}
				pathAttrs.forEach(function (pa) {
					if (!pa || pa.id == null) {
						return;
					}
					pathSelect.appendChild(
						el('option', {
							value: String(pa.id),
							text: pa.name || '#' + pa.id,
						})
					);
				});
				if (!pathAttrs.length) {
					pathSelect.appendChild(
						el('option', {
							value: '',
							disabled: true,
							text:
								i18n.attributesComputeNoPathAttrs ||
								'No attributes on linked type (select type node once to cache).',
						})
					);
				}
				pathSelect._sourceAttrId = pid;
			});
			pathSelect.addEventListener('change', function () {
				var pathId = wttAttrId(pathSelect.value);
				var srcId = wttAttrId(pathSelect._sourceAttrId);
				if (!pathId || !srcId) {
					return;
				}
				var nextSources = (compute.sources || []).slice();
				nextSources.push({
					kind: 'attrPath',
					attrId: srcId,
					pathAttrId: pathId,
				});
				persistCompute({
					op: compute.op || opSelect.value || 'sum',
					sources: nextSources,
				});
				peerSelect.value = '';
				pathSelect.value = '';
				pathSelect.style.display = 'none';
			});
			addWrap.appendChild(peerSelect);
			addWrap.appendChild(pathSelect);
			block.appendChild(addWrap);
		}

		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.attributesComputeHint ||
					'Aggregate over a flat list of source values (sum/avg/min/max/count). Computed fields are read-only.',
			})
		);
		return block;
	}

	/**
	 * Direct hierarchy children eligible as move-to-child targets (flat picker roots).
	 * Prefers server list; falls back to directChildren minus own attribute members.
	 */
	function attributeMoveChildRoots(n) {
		var fromServer = Array.isArray(n.attributeMoveChildren)
			? n.attributeMoveChildren
			: [];
		if (fromServer.length) {
			return fromServer
				.filter(function (c) {
					return c && parseInt(c.id, 10) > 0;
				})
				.map(function (c) {
					return {
						id: parseInt(c.id, 10),
						name: c.name || String(c.id),
						children: [],
						shortDescription: c.shortDescription || '',
					};
				});
		}

		/* Legacy slot term ids only (attrs are edge ids now; exclude leftover numeric slots). */
		var ownAttrIds = {};
		(Array.isArray(n.attributes) ? n.attributes : []).forEach(function (a) {
			if (a && !a.inherited && a.legacySlotId != null) {
				var lid = parseInt(a.legacySlotId, 10) || 0;
				if (lid) {
					ownAttrIds[lid] = true;
				}
			}
		});

		var kids = Array.isArray(n.directChildren) ? n.directChildren : [];
		var treeNode = findNodeInTree(state.tree, parseInt(n.id, 10) || 0);
		if (treeNode && Array.isArray(treeNode.children) && treeNode.children.length) {
			kids = treeNode.children;
		}

		return kids
			.filter(function (c) {
				if (!c || c.id == null) {
					return false;
				}
				var id = parseInt(c.id, 10) || 0;
				if (!id || ownAttrIds[id]) {
					return false;
				}
				if (c.isTrash || c.trashed) {
					return false;
				}
				return true;
			})
			.map(function (c) {
				return {
					id: parseInt(c.id, 10),
					name: c.name || String(c.id),
					children: [],
					shortDescription: c.shortDescription || '',
				};
			});
	}

	function openAttributeMoveToChildPicker(n, attrId, roots) {
		var hostId = parseInt(n.id, 10) || 0;
		attrId = wttAttrId(attrId);
		roots = Array.isArray(roots) ? roots : attributeMoveChildRoots(n);
		if (!hostId || !attrId) {
			return;
		}
		if (!roots.length) {
			setError(
				i18n.attributesMoveToChildEmpty ||
					'No eligible children to move this attribute to.'
			);
			return;
		}
		openNodeTreePickerDialog(
			{
				roots: roots,
				selectedId: 0,
				allowRoot: false,
				allowClear: false,
				expandKey: 'attr-move-child:' + String(hostId) + ':' + String(attrId),
				dialogTitle:
					i18n.attributesMoveToChildPick ||
					'Choose child for attribute',
				placeholder:
					i18n.attributesMoveToChildPick ||
					'Choose child for attribute',
				selectable: function (node) {
					return !!(node && parseInt(node.id, 10) > 0);
				},
			},
			function (childId) {
				childId = parseInt(childId, 10) || 0;
				if (!childId) {
					return;
				}
				post('wtt_move_attribute_to_child', {
					term_id: hostId,
					attr_id: attrId,
					child_id: childId,
				})
					.then(applyRelationMutation)
					.catch(function () {
						setError(i18n.error);
					});
			}
		);
	}

	/**
	 * Flat CatalogChoice dialog (Q90 depth â‰¤ 1) â€” simple <select> list.
	 *
	 * @param {{
	 *   options: Array,
	 *   selectedId?: number,
	 *   allowClear?: boolean,
	 *   dialogTitle?: string
	 * }} opts
	 * @param {function(number): void} onDone
	 */
	function openFlatCatalogChoiceDialog(opts, onDone) {
		opts = opts || {};
		var options = Array.isArray(opts.options) ? opts.options : [];
		var selectedId = parseInt(opts.selectedId, 10) || 0;
		var allowClear = !!opts.allowClear;
		var picked = selectedId;

		function close() {
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop);
			}
		}

		var body = el('div', {
			className: 'wtt-attr-fixed wtt-node-picker-dialog__host',
		});
		if (!options.length) {
			body.appendChild(
				el('p', {
					className: 'description',
					text:
						i18n.attributesFixedEmpty ||
						'This type has no selectable values yet.',
				})
			);
		} else {
			var select = renderOptionsSelect(options, {
				className: 'wtt-type-select wtt-catalog-choice-select',
				selectedValue: picked,
				allowEmpty: allowClear,
				getValue: function (opt) {
					return opt.id != null ? String(opt.id) : '';
				},
				onChange: function () {
					picked = parseInt(select.value, 10) || 0;
				},
			});
			body.appendChild(select);
			picked = parseInt(select.value, 10) || picked;
		}

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el(
				'div',
				{ className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' },
				[
					el('h2', {
						text:
							opts.dialogTitle ||
							i18n.attributesFixed ||
							'Default value',
					}),
					body,
					el(
						'div',
						{ className: 'wtt-dialog__actions' },
						[
							el('button', {
								type: 'button',
								className: 'button',
								text: i18n.cancel || 'Cancel',
								onClick: close,
							}),
							allowClear
								? el('button', {
										type: 'button',
										className: 'button',
										text: i18n.attributesFixedClear || 'Clear',
										onClick: function () {
											if (typeof onDone === 'function') {
												onDone(0);
											}
											close();
										},
								  })
								: null,
							el('button', {
								type: 'button',
								className: 'button button-primary',
								text: i18n.attributesFixedApply || 'Apply',
								onClick: function () {
									if (typeof onDone === 'function') {
										onDone(picked);
									}
									close();
								},
							}),
						].filter(Boolean)
					),
				]
			),
		]);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) {
				close();
			}
		});
		document.body.appendChild(backdrop);
	}

	/**
	 * Roots for attribute Festwert catalog picker (subtree under type).
	 */
	function attributeFixedCatalogRoots(n, attr) {
		/* Prefer server fixedOptions — tree may still point at empty legacy folders
		 * (Konstanten/Präfixe) or unloaded hasChildren stubs. */
		var opts = Array.isArray(attr.fixedOptions) ? attr.fixedOptions : [];
		if (opts.length) {
			if (typeof buildChoiceTreeFromFixedOptions === 'function') {
				var fromOpts = buildChoiceTreeFromFixedOptions(opts);
				if (fromOpts && fromOpts.length) {
					return fromOpts;
				}
			}
			return opts
				.filter(function (o) {
					return o && o.id != null;
				})
				.map(function (o) {
					return {
						id: o.id,
						name: o.name || String(o.id),
						children: [],
						shortDescription: o.shortDescription || '',
					};
				});
		}

		var typeId = parseInt(attr.typeId || attr.fixedRootId, 10) || 0;
		if (typeId) {
			var hit = findNodeInTree(state.tree, typeId);
			if (hit) {
				if (hit.children && hit.children.length) {
					return hit.children;
				}
				/* Type leaf with no children — still allow picking the type itself. */
				return [hit];
			}
		}
		return [];
	}

	function attributeUsesCatalogFixed(attr) {
		if (String(attr.fixedMode || '') !== 'catalog') {
			return false;
		}
		/*
		 * Unit↔prefix marriage (options carry allowedPrefixes) or Preferred
		 * Quantity/Unit → type paint, not a bare leaf CatalogChoice.
		 */
		if (attributeLooksLikeUnitMarriage(attr)) {
			return false;
		}
		var pref = String(
			attr.preferredRender || attr.typePreferredRender || ''
		)
			.trim()
			.toLowerCase();
		if (
			pref.indexOf('quantity') !== -1 ||
			pref === 'unit' ||
			pref.indexOf('unitrenderer') !== -1
		) {
			return false;
		}
		if (
			attr.quantitySchema &&
			Array.isArray(attr.quantitySchema.members) &&
			attr.quantitySchema.members.length
		) {
			return false;
		}
		return true;
	}

	function attributeLooksLikeUnitMarriage(attr) {
		/* CatalogChoice / ChildList Base unit pick ≠ UnitRenderer Prefix+Symbol chrome. */
		if (
			attr &&
			(String(attr.fixedMode || '') === 'catalog' ||
				String(attr.preferredRender || '')
					.toLowerCase()
					.indexOf('childlist') !== -1 ||
				String(attr.typePreferredRender || '')
					.toLowerCase()
					.indexOf('childlist') !== -1)
		) {
			return false;
		}
		var opts = Array.isArray(attr.fixedOptions) ? attr.fixedOptions : [];
		var i;
		for (i = 0; i < opts.length; i++) {
			var o = opts[i];
			if (
				o &&
				Array.isArray(o.allowedPrefixes) &&
				o.allowedPrefixes.length
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Field DTO for Default dialog — same shape as preview (type Preferred + settings).
	 *
	 * @param {object} attr
	 * @return {object|null}
	 */
	function attributeDefaultFieldFromAttr(attr) {
		if (!attr || typeof attr !== 'object') {
			return null;
		}
		var ObjectRender = window.WTTObjectRender;
		var field = null;
		if (
			ObjectRender &&
			typeof ObjectRender.normalizeAttributes === 'function'
		) {
			var list = ObjectRender.normalizeAttributes([attr]);
			field = list && list[0] ? list[0] : null;
		}
		if (!field) {
			field = {
				id: attr.id,
				name: attr.name || '',
				typeKey: attr.typeKey || attr.typeName || 'text',
				typeName: attr.typeName || attr.typeKey || 'text',
				preferredRender: String(
					attr.preferredRender || attr.typePreferredRender || ''
				),
				typePreferredRender: String(attr.typePreferredRender || ''),
				typeProperties: Array.isArray(attr.typeProperties)
					? attr.typeProperties
					: [],
				quantitySchema: attr.quantitySchema || null,
				fixedMode: attr.fixedMode || '',
				fixedOptions: Array.isArray(attr.fixedOptions)
					? attr.fixedOptions
					: [],
				fixedValues: Array.isArray(attr.fixedValues)
					? attr.fixedValues
					: [],
				allowsEmpty:
					attr.allowsEmpty != null
						? !!attr.allowsEmpty
						: String(attr.multiplicity || '1') === '0..1' ||
						  String(attr.multiplicity || '1') === '0..*',
				multiplicity: String(attr.multiplicity || '1'),
			};
		}
		/*
		 * Default dialog must not inherit preview Sample seeds from
		 * normalizeAttributes — empty Festwert stays empty until the user picks.
		 */
		return Object.assign({}, field, { sample: '' });
	}

	function attributeLooksLikeKuerzelName(attr) {
		var key = String((attr && attr.name) || '')
			.toLowerCase()
			.replace(/\u00fc/g, 'ue')
			.replace(/\u00e4/g, 'ae')
			.replace(/\u00f6/g, 'oe');
		return (
			key === 'kuerzel' ||
			key === 'symbol' ||
			key === 'einheit' ||
			key === 'unit'
		);
	}

	/**
	 * Human label for a stored default wire (Quantity/Unit JSON or scalar).
	 *
	 * @param {object} attr
	 * @param {string} raw
	 * @return {string}
	 */
	function formatFixedWireDisplayLabel(attr, raw) {
		var s = raw == null ? '' : String(raw).trim();
		if (!s) {
			return '';
		}
		var ObjectRender = window.WTTObjectRender;
		var field = attributeDefaultFieldFromAttr(attr);
		if (
			field &&
			ObjectRender &&
			typeof ObjectRender.paintFieldContent === 'function'
		) {
			try {
				var painted = ObjectRender.paintFieldContent(field, s, {
					readonly: true,
					contextName: 'form',
				});
				if (painted && painted.textContent) {
					var t = String(painted.textContent)
						.replace(/\s+/g, ' ')
						.trim();
					if (t && t !== '—') {
						return t;
					}
				}
			} catch (e) {
				/* fall through */
			}
		}
		if (s.charAt(0) === '{') {
			try {
				var obj = JSON.parse(s);
				if (obj && typeof obj === 'object') {
					var mag = obj.mag != null ? String(obj.mag) : '';
					var prefix = obj.prefix != null ? String(obj.prefix) : '';
					var unit = obj.unit != null ? String(obj.unit) : '';
					if (mag || prefix || unit) {
						return [mag, prefix, unit].filter(Boolean).join(' ');
					}
				}
			} catch (e2) {
				/* keep raw */
			}
		}
		return s;
	}

	/**
	 * Festwert picker — mount the attribute's type chrome via paintFieldContent
	 * (Preferred + settings / CatalogChoice ListChooser). Multi-catalog keeps
	 * the checklist. Preview Sample fill is disabled (noSampleFill).
	 *
	 * @param {Object} n Host node.
	 * @param {Object} attr Attribute row (id may be 0 for add-row draft).
	 * @param {Function} [onDone]
	 * @param {{ onSave?: function(list<string>): * }} [opts] When onSave is set,
	 *        values are handed to that callback instead of posting AJAX.
	 */
	function openAttributeFixedValueDialog(n, attr, onDone, opts) {
		opts = opts || {};
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = wttAttrId(attr);
		var allowsMany = !!attr.allowsMany;
		var current = Array.isArray(attr.fixedValues)
			? attr.fixedValues.slice()
			: [];
		var typeKey = String(attr.typeKey || '').toLowerCase();
		var useCatalog = attributeUsesCatalogFixed(attr);

		function finishDone() {
			if (typeof onDone === 'function') {
				onDone();
			}
		}

		function save(values) {
			values = values || [];
			if (typeof opts.onSave === 'function') {
				return Promise.resolve(opts.onSave(values)).then(finishDone);
			}
			return post('wtt_set_attribute_fixed', {
				term_id: hostId,
				attr_id: attrId,
				values: JSON.stringify(values),
			})
				.then(function (json) {
					return applyRelationMutation(json);
				})
				.then(finishDone)
				.catch(function () {
					setError(i18n.error);
				});
		}

		/*
		 * node_presentation always mirrors host presentation — no Festwert.
		 */
		if (isNodePresentationTypeKey(typeKey)) {
			window.alert(
				i18n.attributesDisplayNodeNameNoDefault ||
					'This attribute always shows a host presentation field. A default value is not used.'
			);
			finishDone();
			return;
		}

		/*
		 * CatalogChoice Festwert: ListChooser (flat select / checklist) — do not
		 * route Mult=1 through paintFieldContent (Form Preferred on catalog types
		 * produced a broken empty control in the dialog).
		 */
		if (useCatalog) {
			var roots = attributeFixedCatalogRoots(n, attr);
			var options = Array.isArray(attr.fixedOptions)
				? attr.fixedOptions.slice()
				: [];
			if (!options.length && roots.length) {
				options = flattenChoiceLeaves(roots);
			}
			if (!options.length) {
				window.alert(
					i18n.attributesFixedEmpty ||
						'This type has no selectable values yet.'
				);
				return;
			}

			var allowsEmptyCatalog =
				attr.allowsEmpty != null
					? !!attr.allowsEmpty
					: String(attr.multiplicity || '1') === '0..*' ||
					  String(attr.multiplicity || '1') === '0..1';

			/* Mult ≤ 1: flat ListChooser dialog (Surface Mount / Through Hole). */
			if (!allowsMany) {
				var selectedOne =
					current.length && current[0] != null
						? parseInt(current[0], 10) || 0
						: 0;
				openFlatCatalogChoiceDialog(
					{
						options: options,
						selectedId: selectedOne,
						/* Mult 1 / 1..* → no empty; 0..1 / 0..* → Clear + — (Q116). */
						allowClear: allowsEmptyCatalog,
						dialogTitle:
							(i18n.attributesFixed || 'Default value') +
							': ' +
							(attr.name || ''),
					},
					function (pickedId) {
						var id = parseInt(pickedId, 10) || 0;
						if (!id && !allowsEmptyCatalog) {
							window.alert(
								i18n.attributesFixedRequired ||
									'At least one value is required for this multiplicity.'
							);
							return;
						}
						save(id ? [String(id)] : []).then(finishDone);
					}
				);
				return;
			}

			/* Multiplicity > 1: checklist (list-picker row chrome). */
			var selected = {};
			current.forEach(function (v) {
				selected[String(v)] = true;
			});

			function closeMulti() {
				if (backdrop.parentNode) {
					backdrop.parentNode.removeChild(backdrop);
				}
			}

			var body = el('div', {
				className: 'wtt-attr-fixed wtt-node-picker-dialog__host',
			});
			body.appendChild(
				el('p', {
					className: 'description',
					text:
						(attr.name || '') +
						' · ' +
						(attr.typeName || typeKey || '') +
						' · ' +
						(attr.multiplicity || '1'),
				})
			);

			var list = el('ul', {
				className: 'wtt-node-picker__list wtt-attr-fixed__list',
			});
			options.forEach(function (opt) {
				var id = String(opt.id);
				var li = el('li', { className: 'wtt-node-picker__node' });
				var row = el('div', { className: 'wtt-node-picker__row' });
				var input = el('input', {
					type: 'checkbox',
					value: id,
					checked: !!selected[id],
				});
				input.addEventListener('change', function () {
					if (input.checked) {
						selected[id] = true;
						return;
					}
					var remaining = Object.keys(selected).filter(function (k) {
						return k !== id && selected[k];
					});
					if (!allowsEmptyCatalog && !remaining.length) {
						input.checked = true;
						return;
					}
					delete selected[id];
				});
				row.appendChild(input);
				row.appendChild(
					el('span', {
						className: 'wtt-node-picker__name',
						text: opt.path || opt.name || id,
					})
				);
				li.appendChild(row);
				list.appendChild(li);
			});
			body.appendChild(list);

			var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
				el(
					'div',
					{ className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' },
					[
						el('h2', {
							text:
								(i18n.attributesFixed || 'Default value') +
								': ' +
								(attr.name || ''),
						}),
						body,
						el('div', { className: 'wtt-dialog__actions' }, [
							el('button', {
								type: 'button',
								className: 'button',
								text: i18n.cancel || 'Cancel',
								onClick: closeMulti,
							}),
							allowsEmptyCatalog
								? el('button', {
										type: 'button',
										className: 'button',
										text: i18n.attributesFixedClear || 'Clear',
										onClick: function () {
											save([]).then(closeMulti);
										},
								  })
								: null,
							el('button', {
								type: 'button',
								className: 'button button-primary',
								text: i18n.attributesFixedApply || 'Apply',
								onClick: function () {
									var ids = Object.keys(selected).filter(
										function (k) {
											return !!selected[k];
										}
									);
									if (!allowsEmptyCatalog && !ids.length) {
										window.alert(
											i18n.attributesFixedRequired ||
												'At least one value is required for this multiplicity.'
										);
										return;
									}
									save(ids).then(closeMulti);
								},
							}),
						].filter(Boolean)),
					]
				),
			]);
			backdrop.addEventListener('click', function (e) {
				if (e.target === backdrop) {
					closeMulti();
				}
			});
			document.body.appendChild(backdrop);
			return;
		}

		var ObjectRender = window.WTTObjectRender;
		var field = attributeDefaultFieldFromAttr(attr);
		if (
			!field ||
			!ObjectRender ||
			typeof ObjectRender.paintFieldContent !== 'function'
		) {
			window.alert(
				i18n.previewUnavailable ||
					'Default editor unavailable for this type.'
			);
			return;
		}
		/* Unit folder marriage (concrete leaf Prefix?+Symbol) — not CatalogChoice Base unit. */
		if (attributeLooksLikeUnitMarriage(attr)) {
			field.preferredRender = 'unit';
			field.typePreferredRender = 'unit';
		}
		/*
		 * True catalog Festwert (e.g. Praefix → Präfixe): keep fixedMode so
		 * paintFieldContent mounts ListChooser / tree CatalogChoice — same as Form.
		 */
		if (useCatalog && String(field.fixedMode || '') !== 'catalog') {
			field.fixedMode = 'catalog';
		}
		if (
			useCatalog &&
			(!Array.isArray(field.fixedOptions) || !field.fixedOptions.length) &&
			Array.isArray(attr.fixedOptions)
		) {
			field.fixedOptions = attr.fixedOptions.slice();
		}

		var allowsEmptyType =
			attr.allowsEmpty != null
				? !!attr.allowsEmpty
				: String(attr.multiplicity || '1') === '0..*' ||
				  String(attr.multiplicity || '1') === '0..1';
		var wires = current.map(function (v) {
			return v == null ? '' : String(v);
		});
		/*
		 * Unit leaf (Meter…): Kuerzel Default seeds from Presentation.symbol
		 * (fallback shortDescription) — never a stale SI compound left in short.
		 */
		if (
			!wires.length &&
			attributeLooksLikeKuerzelName(attr) &&
			n &&
			n.isBasiseinheitUnit
		) {
			var glyph = resolveHostPresentationValue(n, 'symbol');
			if (!glyph && n.shortDescription != null) {
				glyph = String(n.shortDescription).trim();
			}
			if (glyph) {
				wires = [glyph];
			}
		}
		if (!wires.length) {
			wires = [''];
		}
		if (!allowsMany && wires.length > 1) {
			wires = wires.slice(0, 1);
		}

		function closeType() {
			if (typeBackdrop.parentNode) {
				typeBackdrop.parentNode.removeChild(typeBackdrop);
			}
		}

		var typeBody = el('div', {
			className: 'wtt-attr-fixed wtt-attr-fixed--type-paint',
		});
		typeBody.appendChild(
			el('p', {
				className: 'description',
				text:
					(attr.name || '') +
					' · ' +
					(attr.typeName || typeKey || '') +
					' · ' +
					(attr.multiplicity || '1'),
			})
		);
		var valuesHost = el('div', { className: 'wtt-attr-fixed__values' });

		function paintRow(index) {
			var row = el('div', {
				className: 'wtt-attr-fixed__value-row wtt-attr-fixed__type-row',
			});
			var control = el('div', {
				className: 'wtt-attr-fixed__type-control',
			});
			var painted = ObjectRender.paintFieldContent(
				field,
				wires[index] || '',
				{
					readonly: false,
					contextName: 'form',
					noSampleFill: true,
					onInput: function (next) {
						wires[index] = next == null ? '' : String(next);
					},
				}
			);
			if (painted) {
				control.appendChild(painted);
				/*
				 * CatalogChoice / ListChooser may auto-select the first option
				 * (Q116) without firing change — sync wire so Apply persists it.
				 */
				if (!String(wires[index] || '').trim()) {
					var autoSel = control.querySelector('select');
					if (autoSel && autoSel.value) {
						wires[index] = String(autoSel.value);
					}
				}
			}
			row.appendChild(control);
			if (allowsMany) {
				row.appendChild(
					el('button', {
						type: 'button',
						className: 'button-link-delete',
						title: i18n.attributesRemove || 'Remove',
						'aria-label': i18n.attributesRemove || 'Remove',
						html:
							'<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
						onClick: function () {
							if (!allowsEmptyType && wires.length <= 1) {
								return;
							}
							wires.splice(index, 1);
							rebuildRows();
						},
					})
				);
			}
			return row;
		}

		function rebuildRows() {
			valuesHost.textContent = '';
			if (!wires.length) {
				wires = [''];
			}
			wires.forEach(function (_w, idx) {
				valuesHost.appendChild(paintRow(idx));
			});
		}

		rebuildRows();
		typeBody.appendChild(valuesHost);
		if (allowsMany) {
			typeBody.appendChild(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.attributesFixedAddValue || 'Add value',
					onClick: function () {
						wires.push('');
						rebuildRows();
					},
				})
			);
		}

		var typeBackdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' }, [
				el('h2', {
					text:
						(i18n.attributesFixed || 'Default value') +
						': ' +
						(attr.name || ''),
				}),
				typeBody,
				el(
					'div',
					{ className: 'wtt-dialog__actions' },
					[
						el('button', {
							type: 'button',
							className: 'button',
							text: i18n.cancel || 'Cancel',
							onClick: closeType,
						}),
						allowsEmptyType
							? el('button', {
									type: 'button',
									className: 'button',
									text: i18n.attributesFixedClear || 'Clear',
									onClick: function () {
										save([]).then(closeType);
									},
							  })
							: null,
						el('button', {
							type: 'button',
							className: 'button button-primary',
							text: i18n.attributesFixedApply || 'Apply',
							onClick: function () {
								var vals = wires
									.map(function (v) {
										return String(v || '').trim();
									})
									.filter(Boolean);
								if (!allowsMany && vals.length > 1) {
									vals = vals.slice(0, 1);
								}
								if (!allowsEmptyType && !vals.length) {
									window.alert(
										i18n.attributesFixedRequired ||
											'At least one value is required for this multiplicity.'
									);
									return;
								}
								save(vals).then(closeType);
							},
						}),
					].filter(Boolean)
				),
			]),
		]);
		typeBackdrop.addEventListener('click', function (e) {
			if (e.target === typeBackdrop) {
				closeType();
			}
		});
		document.body.appendChild(typeBackdrop);
	}

	function renderNodeRelations(n, pane) {
		var stored = (n && n.relationsStored) || {};
		var storedCount =
			(Array.isArray(stored.von) ? stored.von.length : 0) +
			(Array.isArray(stored.an) ? stored.an.length : 0);

		var block = el('div', { className: 'wtt-panel wtt-relations' });
		var fold = el('details', {
			className: 'wtt-relations-fold',
		});
		if (state.relationsPanelOpen) {
			fold.open = true;
		}

		var summary = el('summary', {
			className: 'wtt-relations-fold__summary',
		});
		summary.appendChild(
			document.createTextNode(i18n.relationsTitle || 'Relations')
		);
		if (storedCount > 0) {
			summary.appendChild(
				el('span', {
					className: 'wtt-badge wtt-relations-fold__count',
					text: String(storedCount),
					title:
						i18n.relationsStoredCountHint ||
						'Stored relation edges (plus synthetic rows when expanded)',
				})
			);
		}
		fold.appendChild(summary);

		var body = el('div', {
			className: 'wtt-relations-fold__body',
		});
		var status = el('p', {
			className: 'wtt-relations-fold__status description',
			text: state.relationsPanelOpen
				? i18n.relationsFoldLoading || 'Loading…'
				: i18n.relationsFoldCollapsedHint ||
				  'Expand to load Relation types and edit edges. Power-user surface — left collapsed by default.',
		});
		body.appendChild(status);
		fold.appendChild(body);
		block.appendChild(fold);
		pane.appendChild(block);

		var built = false;

		function applyCatalogToNode(node) {
			var cat = state.relationCatalog;
			if (!cat || !node) {
				return;
			}
			node.relationTypeTree = cat.relationTypeTree || [];
			node.relationTypeOptions = cat.relationTypeOptions || [];
			if (
				Array.isArray(cat.relationMultiplicityOptions) &&
				cat.relationMultiplicityOptions.length
			) {
				node.relationMultiplicityOptions = cat.relationMultiplicityOptions;
			}
		}

		function ensureRelationCatalog() {
			if (state.relationCatalog) {
				return Promise.resolve(state.relationCatalog);
			}
			if (state.relationCatalogLoading) {
				return state.relationCatalogLoading;
			}
			state.relationCatalogLoading = post('wtt_get_relation_types', {})
				.then(function (json) {
					state.relationCatalogLoading = null;
					if (!json || !json.success || !json.data) {
						throw new Error(
							(json && json.data && json.data.message) ||
								(i18n.relationsFoldError || 'Could not load relation types.')
						);
					}
					state.relationCatalog = {
						relationTypeTree: json.data.relationTypeTree || [],
						relationTypeOptions: json.data.relationTypeOptions || [],
						relationMultiplicityOptions:
							json.data.relationMultiplicityOptions || [],
					};
					return state.relationCatalog;
				})
				.catch(function (err) {
					state.relationCatalogLoading = null;
					throw err;
				});
			return state.relationCatalogLoading;
		}

		function buildRelationsBody() {
			if (built) {
				return;
			}
			built = true;
			applyCatalogToNode(n);
			if (state.selectedNode && state.selectedNode.id === n.id) {
				applyCatalogToNode(state.selectedNode);
			}

			while (body.firstChild) {
				body.removeChild(body.firstChild);
			}

			var rel = collectSyntheticRelations(n);
			var relationTypes = assignableRelationTypes(n);
			var rows = buildDirectedRelationRows(n, rel);
			if (state.hideChildOfRelations !== false) {
				rows = rows.filter(function (row) {
					return String((row && row.type) || '').toLowerCase() !== 'child_of';
				});
			}

			var titleRow = el('div', { className: 'wtt-relations__head' });
			titleRow.appendChild(
				renderRelationsSectionTitle(
					i18n.relationsTitle || 'Relations',
					i18n.relationsHelp ||
						'Always From node → Relation type → To node. The current node is shown by name (not a link); hover for the hint.',
					'wtt-relations__title-wrap'
				)
			);
			var headActions = el('div', { className: 'wtt-relations__head-actions' });
			var hideLabel = el('label', {
				className: 'wtt-relations__hide-child-of',
			});
			hideLabel.appendChild(
				el('input', {
					type: 'checkbox',
					checked: state.hideChildOfRelations !== false,
					onChange: function (e) {
						state.hideChildOfRelations = !!e.target.checked;
						persistTreeUi();
						render();
					},
				})
			);
			hideLabel.appendChild(
				document.createTextNode(
					' ' + (i18n.relationsHideChildOf || 'Hide child_of')
				)
			);
			headActions.appendChild(hideLabel);
			headActions.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.relationsAdd || 'Add relation',
					onClick: function () {
						openAddRelationFlow(n);
					},
				})
			);
			titleRow.appendChild(headActions);
			body.appendChild(titleRow);
			body.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.relationsHint ||
						'Format: node → relation type → node. The current node is plain text (tooltip: current node). Mult. = definition multiplicity. Protected rows are derived (child_of / ref_scope).',
				})
			);
			body.appendChild(
				renderRelationsTable(rows, {
					node: n,
					editable: true,
					allowReorder: true,
					relationTypes: relationTypes,
					onTypeChange: function (row, typeId) {
						updateStoredRelationType(
							n,
							row,
							typeId,
							row.direction || 'von'
						);
					},
					onMultiplicityChange: function (row, mult) {
						updateStoredRelationMultiplicity(
							n,
							row,
							mult,
							row.direction || 'von'
						);
					},
					onNameChange: function (row, name) {
						updateStoredRelationName(
							n,
							row,
							name,
							row.direction || 'von'
						);
					},
					onTargetChange: function (row) {
						openChangeRelationTarget(n, row);
					},
					onDuplicateAsType: function (row) {
						duplicateRelationWithOtherType(
							n,
							row,
							row.direction || 'von'
						);
					},
					onRemove: function (row) {
						removeStoredRelation(n, row, row.direction || 'von');
					},
					onMove: function (row, delta) {
						if (row.direction !== 'von') {
							return;
						}
						moveStoredRelation(n, row, delta);
					},
				})
			);
		}

		function loadAndBuild() {
			status.textContent = i18n.relationsFoldLoading || 'Loading…';
			ensureRelationCatalog()
				.then(function () {
					buildRelationsBody();
				})
				.catch(function () {
					built = false;
					status.textContent =
						i18n.relationsFoldError || 'Could not load relation types.';
				});
		}

		fold.addEventListener('toggle', function () {
			state.relationsPanelOpen = !!fold.open;
			if (fold.open && !built) {
				loadAndBuild();
			}
		});

		if (fold.open) {
			loadAndBuild();
		}
	}

	function renderSetMembers(n, pane) {
		// Option: show child properties under parent â€” only for set-typed nodes.
		if (!cfg.showSetChildProps || !n.isSet || !n.setMembers || !n.setMembers.length) {
			return;
		}

		var block = el('div', { className: 'wtt-panel wtt-set-members' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-set-members__title',
				text: i18n.setChildProperties || i18n.setMembers || 'Child properties',
			})
		);
		if (i18n.setChildPropertiesHint) {
			block.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.setChildPropertiesHint }));
		}

		var table = el('table', { className: 'wtt-set-members__table' });
		var thead = el('thead');
		var headRow = el('tr');
		headRow.appendChild(el('th', { text: i18n.name || 'Name', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.setMemberType || 'Type', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.fixedValue || 'Fixed value', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.required || 'Required', scope: 'col' }));
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		n.setMembers.forEach(function (member) {
			var row = el('tr');
			var nameCell = el('td');
			nameCell.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-set-members__link',
					text: member.name,
					onClick: function () {
						selectNode(member.id);
					},
				})
			);
			row.appendChild(nameCell);
			row.appendChild(
				el('td', {
					text:
						(member.type && (member.type.path || member.type.name)) ||
						i18n.setMemberUntyped ||
						'â€” not typed â€”',
				})
			);
			row.appendChild(
				el('td', {
					text:
						(member.fixedEnabled &&
							((member.fixedLiteral && String(member.fixedLiteral)) ||
								(member.fixed && (member.fixed.path || member.fixed.name)))) ||
						i18n.fixedValueNone ||
						'â€” Not fixed â€”',
				})
			);
			row.appendChild(
				el('td', {
					text: member.required
						? i18n.required || 'Required'
						: i18n.optional || 'Optional',
				})
			);
			tbody.appendChild(row);
		});
		table.appendChild(tbody);
		block.appendChild(table);
		pane.appendChild(block);
	}

	function typeKeyFromMember(member) {
		if (!member) {
			return 'text';
		}
		var name = '';
		if (member.typeKey) {
			name = member.typeKey;
		} else if (typeof member.type === 'string') {
			name = member.type;
		} else if (member.type && member.type.name) {
			name = member.type.name;
		} else if (member.typeLabel) {
			name = member.typeLabel;
		} else if (member.typeName) {
			name = member.typeName;
		}
		name = String(name).trim().toLowerCase();
		/*
		 * Type-catalog leaves often have no type_id — the node name
		 * IS the type key (int, bool, …). Do not fall through to default "text".
		 */
		var selfName = member.name
			? String(member.name).trim().toLowerCase()
			: '';
		/*
		 * Type-catalog leaves: node name IS the type (int). Q88 may set typeId/type
		 * to the parent branch (Simple) — prefer a known leaf name over that.
		 */
		if (
			!name &&
			selfName &&
			!member.typeId
		) {
			name = selfName;
		}
		if (
			selfName &&
			/^(int|char|double|text|textarea|bool|email|date|table|enum|media|quantity|node_ref|node_embed)$/i.test(
				selfName
			) &&
			(!name ||
				!/^(int|char|double|text|textarea|bool|email|date|table|enum|media|quantity|node_ref|node_embed)$/i.test(
					name
				))
		) {
			name = selfName;
		}
		var Sample = window.WTTSampleData;
		if (Sample && typeof Sample.resolveTypeKey === 'function' && name) {
			var resolved = Sample.resolveTypeKey(name);
			if (resolved) {
				name = resolved;
			}
		}
		if (name === 'integer') {
			return 'int';
		}
		if (name === 'float' || name === 'number') {
			return 'double';
		}
		if (name === 'boolean') {
			return 'bool';
		}
		if (name === 'string' || name === 'varchar') {
			return 'text';
		}
		if (name === 'e-mail' || name === 'e_mail' || name === 'mail') {
			return 'email';
		}
		if (name === 'praefixe' || name === 'prÃ¤fixe') {
			return 'praefixe';
		}
		if (name === 'basiseinheit') {
			return 'basiseinheit';
		}
		if (
			name === 'display_node_name' ||
			name === 'display node name' ||
			name === 'displayname' ||
			name === 'node_name' ||
			name === 'node_presentation' ||
			name === 'node presentation' ||
			name === 'nodepresentation'
		) {
			return 'node_presentation';
		}
		if (name === 'media' || name === 'file' || name === 'image') {
			return 'media';
		}
		if (name === 'subtree' || name === 'node_embed' || name === 'node embed') {
			return 'node_embed';
		}
		if (name === 'node_pick' || name === 'node pick') {
			return 'node_pick';
		}
		/* Informal / DE aliases â†’ quantity (GrÃ¶ÃŸe). */
		if (
			name === 'measure' ||
			name === 'groesse' ||
			name === 'grÃ¶ÃŸe' ||
			name === 'grose'
		) {
			return 'quantity';
		}
		return name || 'text';
	}

	function embedPickKey(member) {
		var mid =
			member && member.id != null
				? 'id:' + member.id
				: 'name:' + String((member && member.name) || 'field');
		return String(state.selectedId || 0) + '|' + mid;
	}

	function rememberEmbedPick(member, id) {
		if (!state.embedPicks) {
			state.embedPicks = {};
		}
		if (!typeIsNodeEmbed(member)) {
			return;
		}
		state.embedPicks[embedPickKey(member)] = parseInt(id, 10) || 0;
	}

	function readEmbedPick(member) {
		if (!state.embedPicks) {
			return 0;
		}
		return parseInt(state.embedPicks[embedPickKey(member)], 10) || 0;
	}

	function helpChildLine(child) {
		var parts = [child.name || ''];
		if (child.typeName) {
			parts.push('(' + child.typeName + ')');
		}
		if (child.required) {
			parts.push('*');
		}
		if (child.fixed) {
			parts.push('= ' + child.fixed);
		}
		var line = parts.join(' ');
		var short =
			child.shortDescription != null ? String(child.shortDescription).trim() : '';
		var long = child.description != null ? String(child.description).trim() : '';
		if (short) {
			line += ' â€” ' + short;
		} else if (long) {
			line += ' â€” ' + long;
		}
		return line;
	}

	function appendHelpChildren(container, children, depth) {
		depth = depth || 0;
		if (!children || !children.length) {
			return;
		}
		var list = el('ul', { className: 'wtt-help__children' + (depth ? ' wtt-help__children--nested' : '') });
		children.forEach(function (child) {
			var li = el('li', { text: helpChildLine(child) });
			if (child.children && child.children.length) {
				appendHelpChildren(li, child.children, depth + 1);
			}
			list.appendChild(li);
		});
		container.appendChild(list);
	}

	/**
	 * @param {string|{description?:string,helpChildren?:Array}|null} descriptionOrHelp
	 */
	function renderHelpHint(descriptionOrHelp) {
		var description = '';
		var helpChildren = null;
		if (descriptionOrHelp && typeof descriptionOrHelp === 'object') {
			description = descriptionOrHelp.description != null ? String(descriptionOrHelp.description) : '';
			helpChildren = descriptionOrHelp.helpChildren || descriptionOrHelp.children || null;
		} else {
			description = descriptionOrHelp != null ? String(descriptionOrHelp) : '';
		}
		description = description.trim();
		var hasChildren = !!(helpChildren && helpChildren.length);
		if (!description && !hasChildren) {
			return null;
		}

		var titleBits = [];
		if (description) {
			titleBits.push(description);
		}
		if (hasChildren) {
			helpChildren.forEach(function (c) {
				titleBits.push(helpChildLine(c));
			});
		}
		var title = titleBits.join('\n');

		var wrap = el('span', { className: 'wtt-help' });
		var btn = el('button', {
			type: 'button',
			className: 'wtt-help__btn',
			title: title,
			'aria-label': i18n.helpShowDescription || 'Show description',
		});
		btn.appendChild(el('span', { className: 'dashicons dashicons-editor-help', 'aria-hidden': 'true' }));

		var pop = el('div', {
			className: 'wtt-help__pop',
			hidden: true,
		});
		if (description) {
			pop.appendChild(el('p', { className: 'wtt-help__text', text: description }));
		}
		if (hasChildren) {
			if (description) {
				pop.appendChild(
					el('p', {
						className: 'wtt-help__subhead',
						text: i18n.helpChildProperties || 'Child properties',
					})
				);
			}
			appendHelpChildren(pop, helpChildren, 0);
		}

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var open = pop.hasAttribute('hidden');
			if (open) {
				pop.removeAttribute('hidden');
				btn.classList.add('is-open');
			} else {
				pop.setAttribute('hidden', 'hidden');
				btn.classList.remove('is-open');
			}
		});

		wrap.appendChild(btn);
		wrap.appendChild(pop);
		return wrap;
	}

	function memberHelpPayload(member) {
		var children = [];
		if (member.helpChildren && member.helpChildren.length) {
			children = member.helpChildren;
		} else if (member.typeBranch && member.typeBranch.children && member.typeBranch.children.length) {
			children = member.typeBranch.children.map(function (c) {
				return {
					name: c.name || '',
					typeName: (c.type && c.type.name) || c.typeName || '',
					fixed: (c.fixed && c.fixed.name) || c.fixed || '',
					required: !!c.required,
					description: c.description || '',
					children: c.children || null,
				};
			});
		}
		return {
			description: member.description || '',
			helpChildren: children,
		};
	}

	/**
	 * ---------------------------------------------------------------------------
	 * Preview field views (generic)
	 *
	 * Call sites pass a field descriptor (set member or normalized node).
	 * Same kind â†’ same view everywhere (form row, table cell, unit usage, â€¦).
	 *
	 * - quantity: Typ + Praefix + Kuerzel (quantitySchema on a typed field, or
	 *   a Basiseinheit unit nodeâ€™s own setMembers)
	 * - scalar: one control from the fieldâ€™s data type / typeBranch
	 * Editable mode writes into state.previewValues; Display reads the same keys.
	 * ---------------------------------------------------------------------------
	 */

	/**
	 * Normalize a set-member or detail node into a preview field descriptor.
	 */
	function asPreviewField(source) {
		if (!source) {
			return null;
		}
		if (source.quantitySchema && Array.isArray(source.quantitySchema.members)) {
			return source;
		}
		if (source.isBasiseinheitUnit && Array.isArray(source.setMembers) && source.setMembers.length) {
			return {
				name: source.name || '',
				displayName: source.name || '',
				description: source.description || '',
				required: !!source.required,
				fixedLiteral: source.fixedLiteral || '',
				fixed: source.fixed || null,
				fixedEnabled: !!source.fixedEnabled,
				type: source.type || null,
				typeBranch: null,
				quantitySchema: {
					unitId: source.id || 0,
					unitName: source.name || '',
					members: source.setMembers,
				},
			};
		}
		if (source.type || source.typeId || source.name) {
			return {
				id: source.id != null ? source.id : undefined,
				name: source.name || '',
				displayName: source.displayName || source.name || '',
				description: source.description || '',
				shortDescription:
					source.shortDescription != null ? String(source.shortDescription) : '',
				helpChildren: source.helpChildren || [],
				type: source.type || null,
				typeKey: source.typeKey != null ? String(source.typeKey) : '',
				typeName: source.typeName != null ? String(source.typeName) : '',
				typeLabel: source.typeLabel != null ? String(source.typeLabel) : '',
				typeId: source.typeId != null ? source.typeId : undefined,
				sample:
					source.sample != null && String(source.sample) !== ''
						? String(source.sample)
						: '',
				required: !!source.required,
				fixedEnabled: !!source.fixedEnabled,
				fixedLiteral: source.fixedLiteral || '',
				fixed: source.fixed || null,
				fixedNodeId: source.fixedNodeId || 0,
				refScopeId: source.refScopeId || 0,
				typeBranch: source.typeBranch || null,
				quantitySchema: source.quantitySchema || null,
				mediaConfig: source.mediaConfig || null,
				dateConfig: source.dateConfig || null,
				textareaConfig: source.textareaConfig || null,
				presentationConfig: source.presentationConfig || null,
				hostPresentation:
					source.hostPresentation || source.presentation || null,
				hostShortDescription:
					source.hostShortDescription != null
						? String(source.hostShortDescription)
						: source.shortDescription != null
							? String(source.shortDescription)
							: '',
				intConfig: source.intConfig || null,
				validators: Array.isArray(source.validators)
					? source.validators.slice()
					: Array.isArray(source.typeValidators)
						? source.typeValidators.slice()
						: [],
				typeValidators: Array.isArray(source.typeValidators)
					? source.typeValidators.slice()
					: [],
				typeExtras:
					source.typeExtras && typeof source.typeExtras === 'object'
						? source.typeExtras
						: null,
				settingsResolved:
					source.settingsResolved &&
					typeof source.settingsResolved === 'object'
						? source.settingsResolved
						: null,
				displayFormat:
					source.displayFormat != null
						? String(source.displayFormat)
						: source.preferredConverter != null
							? String(source.preferredConverter)
							: source.intConfig && source.intConfig.displayFormat
								? String(source.intConfig.displayFormat)
								: '',
				preferredConverter: normalizePreferredConverter(
					source.preferredConverter ||
						source.displayFormat ||
						(source.intConfig && source.intConfig.displayFormat) ||
						''
				),
				enumOptions: Array.isArray(source.enumOptions)
					? deepClone(source.enumOptions)
					: Array.isArray(source.directChildren)
						? deepClone(source.directChildren)
						: [],
				preferredRender: normalizePreferredRender(
					source.preferredRender ||
						source.typePreferredRender ||
						'FormRenderer'
				),
				typePreferredRender: normalizePreferredRender(
					source.typePreferredRender || 'FormRenderer'
				),
			};
		}
		return null;
	}

	/**
	 * Quantity trinity members for a field, or null if not a quantity field.
	 */
	function resolveQuantityMembers(field) {
		if (!field || !field.quantitySchema || !Array.isArray(field.quantitySchema.members)) {
			return null;
		}
		var members = deepClone(field.quantitySchema.members);
		var magnitude =
			field.fixedLiteral != null && String(field.fixedLiteral) !== ''
				? String(field.fixedLiteral)
				: field.fixed && field.fixed.name
					? String(field.fixed.name)
					: '';
		if (magnitude) {
			var typ = findSetMemberByKey(members, 'typ') || findSetMemberByKey(members, 'wert');
			if (typ) {
				typ.fixedEnabled = true;
				typ.fixedLiteral = magnitude;
				typ.fixed = null;
				typ.fixedNodeId = 0;
			}
		}
		return members;
	}

	/**
	 * Shared quantity view: Typ + optional Praefix + Kuerzel symbol.
	 * Value+Unit hosts (no Praefix attr): inject prefix options from the
	 * selected unit’s allowlist (`allowedPrefixes` on fixedOptions).
	 */
	function renderQuantityView(members, mode, scope) {
		members = quantityMembersWithUnitPrefixes(members, scope);
		var typ = findSetMemberByKey(members, 'typ') || findSetMemberByKey(members, 'wert');
		var praefix = findSetMemberByKey(members, 'praefix');
		var sample = typ
			? livePreviewText(scope, typ)
			: getPreviewValue(scope, { name: 'Typ' }, '10.5');

		if (mode === 'display') {
			var prefixPart = samplePrefixLetter(praefix, scope);
			var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
			var symbol =
				(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
				(kuerzelMem && kuerzelMem.fixedLiteral) ||
				'';
			if (!symbol && kuerzelMem) {
				symbol = livePreviewText(scope, kuerzelMem) || '';
			}
			if (symbol) {
				symbol = formatSelectSymbolLabel({
					name: symbol,
					shortDescription:
						(kuerzelMem && kuerzelMem.shortDescription) || '',
				});
				/* Prefer short from selected catalog option when Unit is a choice. */
				var unitOpts = enabledBranchOptions(kuerzelMem);
				if (unitOpts.length) {
					var unitPick = livePreviewText(scope, kuerzelMem);
					for (var ui = 0; ui < unitOpts.length; ui++) {
						var uo = unitOpts[ui];
						if (
							uo &&
							(uo.name === unitPick || String(uo.id) === unitPick)
						) {
							symbol = formatSelectSymbolLabel(uo) || symbol;
							break;
						}
					}
				}
			}
			return el('span', {
				className: 'wtt-preview-display-value wtt-preview-quantity',
				text:
					sample +
					String(prefixPart || '') +
					String(symbol || composeUnitDisplay(members) || ''),
			});
		}

		var group = el('div', { className: 'wtt-preview-quantity' });
		if (typ) {
			group.appendChild(
				renderScalarFieldView(typ, { compact: true, mode: 'edit', scope: scope })
			);
		} else {
			var fallbackTyp = { name: 'Typ', type: { name: 'double' } };
			var num = el('input', {
				type: 'text',
				className: 'wtt-preview-input wtt-preview-input--num',
				inputmode: 'decimal',
				autocomplete: 'off',
				value: sample,
			});
			bindPreviewControl(num, scope, fallbackTyp);
			group.appendChild(num);
		}
		if (praefix && enabledBranchOptions(praefix).length) {
			/*
			 * Fallback path (Registry unavailable): Q109 rescale Typ when Praefix changes.
			 */
			var praefixSample = livePreviewText(scope, praefix);
			var typMem = typ || { name: 'Typ', type: { name: 'double' } };
			group.appendChild(
				renderBranchSelect(praefix, {
					compact: true,
					sample: praefixSample,
					editable: true,
					scope: scope,
					labeledSymbols: true,
					emptyLabel: '—',
					emptyValue: '',
					allowEmpty: memberListSelectAllowsEmpty(praefix),
					beforeChange: function (oldKey, newKey) {
						var qtyApi =
							window.WTTConverter && window.WTTConverter.Quantity
								? window.WTTConverter.Quantity
								: null;
						if (
							!qtyApi ||
							typeof qtyApi.rescaleOnPrefixChange !== 'function'
						) {
							return;
						}
						var key = previewValueKey(scope, typMem);
						var current = Object.prototype.hasOwnProperty.call(
							state.previewValues,
							key
						)
							? state.previewValues[key]
							: getPreviewValue(scope, typMem, previewSampleText(typMem));
						var next = qtyApi.rescaleOnPrefixChange(
							current,
							oldKey,
							newKey,
							enabledBranchOptions(praefix),
							1
						);
						if (next != null) {
							state.previewValues[key] = next;
						}
					},
				})
			);
		}
		var kuerzel = findSetMemberByKey(members, 'kuerzel');
		var unitChoiceOpts = kuerzel ? enabledBranchOptions(kuerzel) : [];
		var symbolText =
			unitChoiceOpts.length > 1
				? ''
				: (kuerzel && kuerzel.fixed && kuerzel.fixed.name) ||
				  (kuerzel && kuerzel.fixedLiteral) ||
				  '';
		/* Multi CatalogChoice on Unit/Währung → ListChooser (renderOptionsSelect via branch). */
		if (kuerzel && unitChoiceOpts.length > 1) {
			group.appendChild(
				renderBranchSelect(kuerzel, {
					compact: true,
					sample: livePreviewText(scope, kuerzel),
					editable: true,
					scope: scope,
					labeledSymbols: true,
					symbolLabels: false,
				})
			);
		} else if (symbolText) {
			group.appendChild(
				el('span', {
					className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
					text: formatSelectSymbolLabel({
						name: symbolText,
						shortDescription:
							(kuerzel && kuerzel.shortDescription) ||
							(kuerzel && kuerzel.fixed && kuerzel.fixed.shortDescription) ||
							'',
					}),
				})
			);
		} else if (kuerzel && unitChoiceOpts.length) {
			group.appendChild(
				renderBranchSelect(kuerzel, {
					compact: true,
					sample: livePreviewText(scope, kuerzel),
					editable: true,
					scope: scope,
					labeledSymbols: true,
					symbolLabels: false,
				})
			);
		} else if (kuerzel) {
			var liveUnit = livePreviewText(scope, kuerzel);
			if (liveUnit) {
				group.appendChild(
					el('span', {
						className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
						text: formatSelectSymbolLabel({ name: liveUnit }),
					})
				);
			}
		}
		return group;
	}

	/**
	 * When quantity members are Value + Unit (catalog) without Praefix,
	 * attach a virtual Praefix from the selected unit’s allowlist.
	 */
	function quantityMembersWithUnitPrefixes(members, scope) {
		members = Array.isArray(members) ? members.slice() : [];
		var typ =
			findSetMemberByKey(members, 'typ') || findSetMemberByKey(members, 'wert');
		var praefix = findSetMemberByKey(members, 'praefix');
		var unit = findSetMemberByKey(members, 'kuerzel');
		if (praefix || !unit || !typ) {
			return members;
		}
		var unitOpts = enabledBranchOptions(unit);
		if (!unitOpts.length) {
			return members;
		}
		var selected =
			livePreviewText(scope, unit) ||
			(unitOpts[0] && (unitOpts[0].name || String(unitOpts[0].id))) ||
			'';
		var pick = null;
		for (var i = 0; i < unitOpts.length; i++) {
			var o = unitOpts[i];
			if (!o) {
				continue;
			}
			if (o.name === selected || String(o.id) === String(selected)) {
				pick = o;
				break;
			}
		}
		if (!pick) {
			pick = unitOpts[0];
		}
		var prefixes = Array.isArray(pick.allowedPrefixes)
			? pick.allowedPrefixes.filter(function (p) {
					return p && p.enabled !== false && (p.name || p.id);
			  })
			: [];
		if (!prefixes.length) {
			return members;
		}
		var virtual = {
			id: 'wtt-qty-prefix-' + String(pick.id || pick.name || 'unit'),
			name: 'Praefix',
			fixedOptions: prefixes.map(function (p) {
				return {
					id: p.id,
					name: p.name,
					shortDescription: p.shortDescription || '',
					multiplikator: p.multiplikator,
					enabled: true,
				};
			}),
		};
		var out = [];
		members.forEach(function (m) {
			if (!m) {
				return;
			}
			out.push(m);
			if (m === typ || (typ && m.id != null && m.id === typ.id)) {
				out.push(virtual);
			}
		});
		if (out.indexOf(virtual) < 0) {
			out.splice(1, 0, virtual);
		}
		return out;
	}

	/**
	 * Single entry for any preview control (form, table, unit usage).
	 */
	function renderFieldView(field, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var normalized = asPreviewField(field) || field;
		var scope = previewMemberScope(normalized, opts.scope);
		var api = nodeRenderApi();
		if (api && api.Registry) {
			var key = resolveNodeRenderTypeKey(normalized);
			var hasQtySchema =
				normalized &&
				normalized.quantitySchema &&
				Array.isArray(normalized.quantitySchema.members) &&
				normalized.quantitySchema.members.length;
			if (
				hasQtySchema ||
				key === 'quantity' ||
				(api.isSimpleScalarType && api.isSimpleScalarType(key)) ||
				key === 'int' ||
				key === 'char' ||
				key === 'double' ||
				key === 'text' ||
				key === 'textarea' ||
				key === 'bool' ||
				key === 'email' ||
				key === 'date' ||
				key === 'enum' ||
				key === 'node_ref'
			) {
				var ctxName = opts.compact ? 'table' : 'form';
				var ctx = {
					name: ctxName,
					mode: mode,
					bare: true,
					value: String(getPreviewValue(scope, normalized, previewSampleText(normalized))),
					valueKey: previewValueKey(scope, normalized),
					onInput:
						mode === 'display'
							? null
							: function (next) {
									var active = document.activeElement;
									var vk = previewValueKey(scope, normalized);
									if (
										active &&
										active.getAttribute &&
										active.getAttribute('data-wtt-pv') === vk
									) {
										rememberPreviewFocus(active, vk);
									}
									setPreviewValue(scope, normalized, next);
							  },
				};
				var out =
					(api.Registry.renderContent &&
						api.Registry.renderContent(
							normalized,
							ctx,
							mode === 'display'
						)) ||
					api.Registry.render(normalized, ctx);
				if (out) {
					return out;
				}
			}
		}
		var qty = resolveQuantityMembers(normalized);
		if (qty) {
			return renderQuantityView(qty, mode, scope);
		}
		return renderScalarFieldView(
			normalized,
			Object.assign({}, opts, { mode: mode, scope: scope })
		);
	}

	/** @deprecated Use renderFieldView â€” kept as alias for call-site clarity during scaffold. */
	function renderPreviewControl(member, opts) {
		return renderFieldView(member, opts);
	}

	function renderScalarFieldView(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var scope = opts.scope;
		var editable = mode === 'edit';
		var key = typeKeyFromMember(member);
		var sample = livePreviewText(scope, member);
		var isFixedCatalog = !!(member.fixed && member.fixed.name);
		var isFixedLiteral =
			member.fixedLiteral != null && String(member.fixedLiteral) !== '' && !isFixedCatalog;
		/* Readonly lock wins over Fixed-as-lock for edit→display (attrs / slots). */
		if (editable && !!member.readonly) {
			mode = 'display';
			editable = false;
		}

		if (mode === 'display') {
			if (key === 'media') {
				return renderMediaFieldView(member, {
					compact: compact,
					mode: 'display',
					scope: scope,
				});
			}
			if (key === 'bool') {
				var boolOn = sample === '1' || sample === 'true' || sample === (i18n.boolTrue || 'true');
				return el('span', {
					className: 'wtt-preview-display-value' + (compact ? ' wtt-preview-display-value--compact' : ''),
					text: boolOn ? i18n.boolTrue || 'true' : i18n.boolFalse || 'false',
				});
			}
			if (key === 'node_ref' || key === 'node_embed') {
				var displayPick = resolveEmbedPickedId(member, scope, sample);
				var displayLabel = el('span', {
					className:
						'wtt-preview-display-value' +
						(compact ? ' wtt-preview-display-value--compact' : ''),
					text: resolveRefPickLabel(member, displayPick || sample),
				});
				if (key === 'node_embed' && displayPick) {
					return wrapNodeEmbedControl(displayLabel, displayPick, 'display', {
						compact: compact,
					});
				}
				return displayLabel;
			}
			return el('span', {
				className: 'wtt-preview-display-value' + (compact ? ' wtt-preview-display-value--compact' : ''),
				text: sample,
			});
		}

		var control;

		if (key === 'media') {
			return renderMediaFieldView(member, {
				compact: compact,
				mode: 'edit',
				scope: scope,
			});
		}

		if (isNodePresentationTypeKey(key)) {
			var shown = member.displayName || member.name || '';
			if (compact) {
				return el('span', {
					className: 'wtt-preview-display-name',
					text: shown,
				});
			}
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input wtt-preview-input--display-name',
				value: shown,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (isFixedCatalog && key !== 'node_embed' && key !== 'node_ref') {
			if (compact) {
				return el('span', {
					className: 'wtt-preview-fixed-text',
					text: member.fixed.name,
					title: (i18n.previewFixed || 'fixed') + ': ' + member.fixed.name,
				});
			}
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input',
				value: member.fixed.name,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (isFixedLiteral && (key === 'int' || key === 'double' || key === 'quantity' || key === 'text' || key === 'char')) {
			/* Schema-fixed magnitude still shown; not interactive in preview. */
			control = el('input', {
				type: key === 'int' || key === 'double' || key === 'quantity' ? 'number' : 'text',
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--num' : ''),
				value: sample,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (key === 'bool') {
			control = el('input', {
				type: 'checkbox',
				className: 'wtt-preview-check',
			});
			control.checked =
				sample === '1' || sample === 'true' || sample === (i18n.boolTrue || 'true');
			if (editable) {
				bindPreviewControl(control, scope, member, {
					event: 'change',
					readValue: function (node) {
						return node.checked ? '1' : '0';
					},
				});
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'textarea') {
			if (compact) {
				control = el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					value: sample,
				});
			} else {
				control = el('textarea', {
					className: 'wtt-preview-input wtt-preview-textarea',
					rows: '1',
				});
				control.value = sample;
			}
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'praefixe') {
			return renderBranchSelect(member, {
				compact: compact,
				sample: sample,
				editable: editable,
				scope: scope,
			});
		}

		if (key === 'basiseinheit') {
			if (member.typeBranch && enabledBranchOptions(member).length) {
				return renderBranchSelect(member, {
					compact: compact,
					sample: sample,
					editable: editable,
					scope: scope,
				});
			}
			var unitLabel = sample || (member.name || 'Basiseinheit');
			control = renderOptionsSelect([{ name: unitLabel }], {
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--compact' : ''),
				disabled: !editable,
				selectedValue: unitLabel,
				getValue: function (opt) {
					return String(opt.name || '');
				},
			});
			if (editable) {
				bindPreviewControl(control, scope, member, { event: 'change' });
			}
			return control;
		}

		if (key === 'node_ref' || key === 'node_embed') {
			var scopeId = parseInt(member.refScopeId, 10) || 0;
			var currentVal = resolveEmbedPickedId(member, scope, sample);
			if (!scopeId) {
				return el('p', {
					className: 'wtt-field-hint',
					text: i18n.refScopeNeeded || 'Set catalog root (ref_scope) firstâ€¦',
				});
			}
			var allowedIds = Array.isArray(member.allowedRefIds) ? member.allowedRefIds : [];
			var roots =
				key === 'node_embed'
					? nodeEmbedPickRoots(scopeId, allowedIds)
					: nodeRefPickRoots(scopeId);
			var expandKey =
				'preview:' +
				String(state.selectedId || 0) +
				':' +
				String(scope != null ? scope : member.id || member.name) +
				':' +
				key;
			var selectableIds = null;
			if (key === 'node_embed') {
				selectableIds = {};
				roots.forEach(function (c) {
					if (c && c.id != null) {
						selectableIds[String(c.id)] = true;
					}
				});
			}
			var pickControl;
			if (isFixedCatalog) {
				rememberEmbedPick(member, currentVal);
				pickControl = el('span', {
					className: 'wtt-preview-fixed-text',
					text: member.fixed.name,
					title: (i18n.previewFixed || 'fixed') + ': ' + member.fixed.name,
				});
			} else {
				var canClearRef =
					member.allowsEmpty != null
						? !!member.allowsEmpty
						: (function () {
								var NR = window.WTTNodeRender;
								var mult = String(
									member.fieldMultiplicity ||
										member.multiplicity ||
										'0..1'
								);
								if (
									NR &&
									typeof NR.multiplicityAllowsEmpty === 'function'
								) {
									return NR.multiplicityAllowsEmpty(mult);
								}
								return mult === '0..1' || mult === '0..*';
						  })();
				pickControl = renderNodeTreePicker({
					roots: roots,
					selectedId: currentVal,
					selectedLabel: resolveRefPickLabel(member, currentVal),
					compact: true,
					defaultOpen: !!currentVal,
					expandKey: expandKey,
					allowRoot: false,
					allowClear: canClearRef,
					emptyText:
						key === 'node_embed'
							? i18n.subtreeEmpty || 'No children under catalog root'
							: i18n.nodeRefEmpty || 'No descendants under catalog root',
					pickedPrefix: i18n.nodePickerSelected || 'Selected:',
					placeholder: i18n.nodeRefChoose || 'Choose nodeâ€¦',
					selectable: function (n) {
						if (key === 'node_ref') {
							return isAllowedRefCandidate(n, scopeId, allowedIds);
						}
						return !!(selectableIds && selectableIds[String(n.id)]);
					},
					onSelect: function (id) {
						id = parseInt(id, 10) || 0;
						if (!id && !canClearRef) {
							return;
						}
						rememberEmbedPick(member, id);
						setPreviewValue(scope, member, id ? String(id) : '');
					},
				});
			}
			if (key === 'node_embed') {
				return wrapNodeEmbedControl(pickControl, currentVal, 'edit', {
					compact: compact,
				});
			}
			return pickControl;
		}

		if (key === 'int' || key === 'double' || key === 'quantity') {
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--num' : ''),
				placeholder: key === 'int' ? '0' : '0.0',
				inputmode: key === 'int' ? 'numeric' : 'decimal',
				autocomplete: 'off',
				value: sample,
			});
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'char') {
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input wtt-preview-input--char',
				maxlength: '1',
				value: sample,
			});
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		control = el('input', {
			type: 'text',
			className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--compact' : ''),
			value: sample,
		});
		if (editable) {
			bindPreviewControl(control, scope, member);
		} else {
			control.disabled = true;
		}
		return control;
	}

	/* Shared MediaRef render (Q65) â€” same module used later on frontend page view. */
	var Media = window.WTTMediaRender || null;
	if (Media) {
		Media.configure({ i18n: i18n });
	}

	function mediaKindKeys() {
		return Media && Media.KINDS
			? Media.KINDS.slice()
			: ['image', 'video', 'audio', 'pdf', 'archive', 'office', 'text', 'file', 'link'];
	}

	function normalizeAllowedKinds(raw) {
		var known = {};
		mediaKindKeys().forEach(function (k) {
			known[k] = true;
		});
		var out = [];
		var seen = {};
		(Array.isArray(raw) ? raw : []).forEach(function (kind) {
			var key = String(kind || '')
				.trim()
				.toLowerCase();
			if (!key || !known[key] || seen[key]) {
				return;
			}
			seen[key] = true;
			out.push(key);
		});
		return out;
	}

	function mediaConfigFromField(member) {
		var cfg = member && member.mediaConfig;
		if (!cfg && state.draft && state.draft.mediaConfig) {
			cfg = state.draft.mediaConfig;
		}
		return {
			allowUpload: !cfg || cfg.allowUpload !== false,
			allowUrl: !!(cfg && cfg.allowUrl),
			allowedKinds: normalizeAllowedKinds(cfg && cfg.allowedKinds),
		};
	}

	function mediaKindsEnabled(cfg) {
		return normalizeAllowedKinds(cfg && cfg.allowedKinds).length > 0;
	}

	function isKindAllowed(cfg, kind) {
		var allowed = normalizeAllowedKinds(cfg && cfg.allowedKinds);
		return allowed.indexOf(String(kind || '')) !== -1;
	}

	function filterSampleEntriesByKinds(cfg) {
		var allowed = normalizeAllowedKinds(cfg && cfg.allowedKinds);
		if (!allowed.length) {
			return [];
		}
		var byKind = {};
		mediaKindSampleEntries().forEach(function (entry) {
			if (entry && entry.kind) {
				byKind[entry.kind] = entry;
			}
		});
		var out = [];
		allowed.forEach(function (kind) {
			if (byKind[kind]) {
				out.push(byKind[kind]);
			}
		});
		return out;
	}

	function libraryTypeForKinds(kinds) {
		var list = normalizeAllowedKinds(kinds);
		if (!list.length) {
			return null;
		}
		var map = {
			image: 'image',
			video: 'video',
			audio: 'audio',
			pdf: 'application/pdf',
			archive: 'application',
			office: 'application',
			text: 'text',
			file: '',
			link: '',
		};
		var types = [];
		var seen = {};
		list.forEach(function (kind) {
			var t = map[kind];
			if (t && !seen[t]) {
				seen[t] = true;
				types.push(t);
			}
		});
		if (!types.length) {
			return null;
		}
		return types.length === 1 ? types[0] : types;
	}

	function parseMediaRef(raw) {
		return Media ? Media.parseRef(raw) : null;
	}

	function normalizeMediaRef(ref) {
		return Media ? Media.normalizeRef(ref) : null;
	}

	function mediaRefToStore(ref) {
		return Media ? Media.toStore(ref) : ref ? JSON.stringify(ref) : '';
	}

	function mediaDisplayLabel(ref) {
		return Media ? Media.displayLabel(ref) : i18n.mediaEmpty || 'No media';
	}

	function classifyMediaKind(ref) {
		return Media ? Media.classifyKind(ref) : '';
	}

	function mediaKindLabel(kind) {
		return Media ? Media.kindLabel(kind) : kind || '';
	}

	function mediaKindSampleEntries() {
		return Media ? Media.sampleEntries() : [];
	}

	function isMediaTypeCatalogNode(n) {
		return !!(n && n.mediaConfig && String(n.name || '').toLowerCase() === 'media');
	}

	function livePreviewMedia(scope, member) {
		var raw = getPreviewValue(scope, member, null);
		if (raw == null || raw === '') {
			return null;
		}
		return parseMediaRef(raw);
	}

	function renderMediaPreviewSurface(ref, compact) {
		if (Media) {
			return Media.renderSurface(ref, { compact: !!compact });
		}
		return el('span', {
			className: 'wtt-media-empty',
			text: i18n.mediaEmpty || 'No media',
		});
	}

	function mediaKindGridSlots() {
		/* 9 kinds + 1 placeholder â†’ 2 rows Ã— 5 */
		return mediaKindKeys().concat([null]);
	}

	function renderMediaKindsGrid(buildCell) {
		var slots = mediaKindGridSlots();
		var wrap = el('div', { className: 'wtt-media-kinds-grid-wrap' });
		var table = el('table', { className: 'wtt-media-kinds-grid' });
		var tbody = el('tbody');
		var cols = 5;
		for (var r = 0; r < 2; r++) {
			var tr = el('tr');
			for (var c = 0; c < cols; c++) {
				var idx = r * cols + c;
				var kind = slots[idx];
				var td = el('td', {
					className:
						'wtt-media-kinds-grid__cell' +
						(kind == null ? ' wtt-media-kinds-grid__cell--placeholder' : ''),
				});
				td.appendChild(buildCell(kind, idx));
				tr.appendChild(td);
			}
			tbody.appendChild(tr);
		}
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function renderMediaKindsForm(entries) {
		var byKind = {};
		(entries || []).forEach(function (entry) {
			if (entry && entry.kind) {
				byKind[entry.kind] = entry;
			}
		});
		return renderMediaKindsGrid(function (kind) {
			if (kind == null) {
				return el('span', {
					className: 'wtt-media-kinds-grid__placeholder',
					text: 'â€”',
					title: i18n.mediaKindPlaceholder || 'Reserved',
				});
			}
			var entry = byKind[kind];
			var cell = el('div', { className: 'wtt-media-kinds-grid__body' });
			cell.appendChild(
				el('div', {
					className: 'wtt-media-kinds-grid__label',
					text: mediaKindLabel(kind),
				})
			);
			if (entry) {
				cell.appendChild(renderMediaPreviewSurface(entry.ref, true));
			} else {
				cell.appendChild(
					el('span', {
						className: 'wtt-media-kinds-grid__off',
						text: 'Â·',
					})
				);
			}
			return cell;
		});
	}

	function renderMediaKindsTable(fieldLabel, entries) {
		var caption = fieldLabel || 'media';
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		[
			i18n.previewColIndex || '#',
			i18n.previewColOther || 'Column A',
			caption,
			i18n.previewColNote || 'Column B',
		].forEach(function (label) {
			headRow.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		entries.forEach(function (entry, idx) {
			var row = el('tr');
			row.appendChild(el('td', { text: String(idx + 1) }));
			row.appendChild(el('td', { text: mediaKindLabel(entry.kind) }));
			var fieldTd = el('td', { className: 'wtt-set-preview__td-set' });
			fieldTd.appendChild(renderMediaPreviewSurface(entry.ref, true));
			row.appendChild(fieldTd);
			row.appendChild(el('td', { text: 'â€¦' }));
			tbody.appendChild(row);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function renderMediaKindsPreview(n) {
		var block = el('div', { className: 'wtt-preview__body' });
		var cfg =
			(n && n.mediaConfig) ||
			(state.draft && state.draft.mediaConfig) ||
			{};
		var allowed = normalizeAllowedKinds(cfg.allowedKinds);
		var entries = filterSampleEntriesByKinds(cfg);

		if (!allowed.length) {
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint wtt-field-hint--warn',
					text:
						i18n.mediaKindsRequired ||
						'Select at least one MIME kind â€” media fields do nothing until a kind is enabled.',
				})
			);
			return block;
		}

		var preferred = effectiveHostPreferredRender(n);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					(i18n.mediaKindsSelectedHint || 'Rendering only:') +
					' ' +
					allowed
						.map(function (kind) {
							return mediaKindLabel(kind);
						})
						.join(', '),
			})
		);

		if (preferred === 'TableRenderer') {
			var tableSection = el('div', { className: 'wtt-set-preview__section' });
			tableSection.appendChild(
				el('h4', {
					className: 'wtt-set-preview__subtitle',
					text: i18n.previewAsTable || 'Table',
				})
			);
			tableSection.appendChild(
				renderMediaKindsTable((n && n.name) || 'media', entries)
			);
			block.appendChild(tableSection);
			return block;
		}

		var formSection = el('div', { className: 'wtt-set-preview__section' });
		formSection.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.previewAsForm || 'Form',
			})
		);
		formSection.appendChild(renderMediaKindsForm(entries));
		block.appendChild(formSection);
		return block;
	}

	function openMediaLibrary(onPicked, allowedKinds) {
		if (!window.wp || !wp.media) {
			return;
		}
		var kinds = normalizeAllowedKinds(allowedKinds);
		var frameOpts = {
			title: i18n.mediaFrameTitle || 'Select media',
			button: { text: i18n.mediaFrameButton || 'Use this file' },
			multiple: false,
		};
		var libraryType = libraryTypeForKinds(kinds);
		if (libraryType) {
			frameOpts.library = { type: libraryType };
		}
		var frame = wp.media(frameOpts);
		frame.on('select', function () {
			var att = frame.state().get('selection').first();
			if (!att) {
				return;
			}
			var data = att.toJSON();
			var thumb = '';
			if (data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url) {
				thumb = data.sizes.thumbnail.url;
			} else if (data.sizes && data.sizes.medium && data.sizes.medium.url) {
				thumb = data.sizes.medium.url;
			}
			var picked = normalizeMediaRef({
				source: 'attachment',
				attachment_id: data.id,
				url: data.url || '',
				mime: data.mime || data.mime_type || '',
				filename: data.filename || data.title || '',
				thumb: thumb || data.url || '',
			});
			if (kinds.length && picked) {
				var kind = classifyMediaKind(picked);
				if (!isKindAllowed({ allowedKinds: kinds }, kind)) {
					return;
				}
			}
			onPicked(picked);
		});
		frame.open();
	}

	function renderMediaFieldView(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var scope = opts.scope;
		var cfg = mediaConfigFromField(member);
		var ref = livePreviewMedia(scope, member);
		var kindsOk = mediaKindsEnabled(cfg);

		if (!kindsOk) {
			return el('span', {
				className: 'wtt-media-empty',
				text:
					i18n.mediaKindsRequired ||
					'Select at least one MIME kind â€” media fields do nothing until a kind is enabled.',
			});
		}

		if (ref) {
			var refKind = classifyMediaKind(ref);
			if (refKind && !isKindAllowed(cfg, refKind)) {
				ref = null;
			}
		}

		if (mode === 'display') {
			return renderMediaPreviewSurface(ref, compact);
		}

		var wrap = el('div', {
			className: 'wtt-media-field' + (compact ? ' wtt-media-field--compact' : ''),
		});
		wrap.appendChild(renderMediaPreviewSurface(ref, compact));

		var actions = el('div', { className: 'wtt-media-field__actions' });
		if (cfg.allowUpload) {
			actions.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: ref ? i18n.mediaChange || 'Change' : i18n.mediaSelect || 'Select media',
					onClick: function () {
						openMediaLibrary(function (picked) {
							if (!picked) {
								return;
							}
							setPreviewValue(scope, member, mediaRefToStore(picked));
							render();
						}, cfg.allowedKinds);
					},
				})
			);
		}
		if (ref) {
			actions.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.mediaClear || 'Clear',
					onClick: function () {
						setPreviewValue(scope, member, '');
						render();
					},
				})
			);
		}
		wrap.appendChild(actions);

		if (cfg.allowUrl) {
			var urlInput = el('input', {
				type: 'url',
				className: 'wtt-preview-input wtt-media-field__url',
				placeholder: i18n.mediaUrlPlaceholder || 'https://â€¦',
				value: ref && ref.source === 'url' ? ref.url : ref && ref.url && !cfg.allowUpload ? ref.url : '',
			});
			urlInput.addEventListener('change', function (e) {
				var url = String(e.target.value || '').trim();
				if (!url) {
					setPreviewValue(scope, member, '');
				} else {
					var urlRef = normalizeMediaRef({
						source: 'url',
						attachment_id: 0,
						url: url,
						mime: '',
						filename: '',
						thumb: '',
					});
					var urlKind = classifyMediaKind(urlRef);
					if (urlKind && !isKindAllowed(cfg, urlKind)) {
						return;
					}
					setPreviewValue(scope, member, mediaRefToStore(urlRef));
				}
				render();
			});
			wrap.appendChild(urlInput);
		}

		if (!cfg.allowUpload && !cfg.allowUrl) {
			wrap.appendChild(
				el('span', {
					className: 'description',
					text: i18n.mediaEmpty || 'No media',
				})
			);
		}

		return wrap;
	}

	function renderMediaSettings(n, pane) {
		if (!n.mediaConfig) {
			return;
		}
		var block = el('div', { className: 'wtt-panel wtt-media-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-media-settings__title',
				text: i18n.mediaSettings || 'Media settings',
			})
		);

		var kinds = normalizeAllowedKinds(n.mediaConfig.allowedKinds);
		var kindsBlock = el('div', { className: 'wtt-media-settings__kinds' });
		kindsBlock.appendChild(
			el('p', {
				className: 'wtt-media-settings__kinds-label',
				text: i18n.mediaKindsLabel || 'Allowed MIME kinds',
			})
		);
		kindsBlock.appendChild(
			el('p', {
				className: 'description',
				text:
					i18n.mediaKindsRequired ||
					'Select at least one MIME kind â€” media fields do nothing until a kind is enabled.',
			})
		);
		var kindsFlow = renderMediaKindsGrid(function (kind) {
			if (kind == null) {
				return el('span', {
					className: 'wtt-media-kinds-grid__placeholder',
					text: 'â€”',
					title: i18n.mediaKindPlaceholder || 'Reserved',
				});
			}
			var id = 'wtt-media-kind-' + kind;
			var check = el('input', {
				type: 'checkbox',
				id: id,
				className: 'wtt-media-check',
			});
			check.checked = kinds.indexOf(kind) !== -1;
			check.addEventListener('change', function (e) {
				setDraftMediaAllowedKind(kind, !!e.target.checked);
			});
			var chip = el('label', {
				className: 'wtt-media-settings__kind-chip',
				htmlFor: id,
			});
			chip.appendChild(check);
			chip.appendChild(document.createTextNode(mediaKindLabel(kind)));
			return chip;
		});
		kindsBlock.appendChild(kindsFlow);
		block.appendChild(kindsBlock);

		block.appendChild(
			renderFlagsStrip(
				[
					{
						label: i18n.mediaAllowUpload || 'Allow Media Library',
						checked: n.mediaConfig.allowUpload !== false,
						title: i18n.mediaAllowUploadHint || '',
						onChange: setDraftMediaAllowUpload,
					},
					{
						label: i18n.mediaAllowUrl || 'Allow external URL',
						checked: !!n.mediaConfig.allowUrl,
						title: i18n.mediaAllowUrlHint || '',
						onChange: setDraftMediaAllowUrl,
					},
				],
				{ className: 'wtt-media-settings__flags' }
			)
		);
		pane.appendChild(block);
	}

	function setDraftDateMode(mode) {
		if (!state.draft) {
			return;
		}
		mode = mode === 'datetime' ? 'datetime' : 'date';
		if (!state.draft.dateConfig) {
			state.draft.dateConfig = { mode: mode };
		} else {
			state.draft.dateConfig.mode = mode;
		}
		afterDraftMutation();
	}

	function setDraftPresentationContext(context) {
		if (!state.draft) {
			return;
		}
		context = normalizePresentationContext(context);
		if (!state.draft.presentationConfig) {
			state.draft.presentationConfig = { context: context };
		} else {
			state.draft.presentationConfig.context = context;
		}
		afterDraftMutation();
	}

	function renderPreferredConverterRow(n, form, controlsLocked) {
		appendPreferredRenderConverterValidatorsRow(n, form, controlsLocked);
	}

	/**
	 * Preferred render + converter + validators add control in one row.
	 * Validator detail table (if any rows) spans below the triple.
	 */
	function appendPreferredRenderConverterValidatorsRow(n, form, controlsLocked) {
		var preferredSelect = el('select', {
			className: 'wtt-preferred-render-select',
			disabled: !!controlsLocked,
			onChange: function (e) {
				setDraftPreferredRender(e.target.value);
			},
		});
		var preferredOpts = listCompatiblePreferredOptions(n);
		var parentId = n && n.parent != null ? parseInt(n.parent, 10) || 0 : 0;
		var inheritMode = !!(state.draft && state.draft.preferredRenderInherit);
		if (parentId > 0) {
			var inheritEff = normalizePreferredRender(
				(n && n.preferredRender) || 'FormRenderer'
			);
			preferredOpts = [
				{
					value: 'inherit',
					label:
						(i18n.preferredRenderInherit || 'Inherit from parent') +
						' (' +
						preferredRenderOptionLabel(inheritEff) +
						')',
				},
			].concat(preferredOpts);
		}
		var preferredCur = inheritMode
			? 'inherit'
			: defaultPreferredRenderForNode(
					Object.assign({}, n, {
						preferredRender: effectiveHostPreferredRender(n),
					})
			  );
		/*
		 * Never clobber a stored/draft Preferred (e.g. TableRenderer → Form) when
		 * compatible-opts briefly omit it before attributes hydrate.
		 */
		if (
			state.draft &&
			!inheritMode &&
			preferredCur !== 'inherit' &&
			(state.draft.preferredRender == null ||
				String(state.draft.preferredRender).trim() === '')
		) {
			state.draft.preferredRender = preferredCur;
		}
		preferredOpts.forEach(function (opt) {
			preferredSelect.appendChild(
				el('option', {
					value: opt.value,
					text: opt.label,
					selected: preferredCur === opt.value,
				})
			);
		});
		/* Q116: sole Preferred choice (incl. Inherit as a real option) → auto + gray. */
		applySoleRequiredListLock(
			preferredSelect,
			countRealSelectOptions(preferredSelect),
			{
				allowEmpty: false,
				disabled: !!controlsLocked,
				title:
					i18n.soleSelectLockedHint ||
					'Only one choice — selected automatically.',
			}
		);

		var reg = converterRegistry();
		var convOpts = reg ? listCompatiblePreferredConverterOptions(n) : [];
		var converterSelect = el('select', {
			className: 'wtt-preferred-converter-select',
			disabled: !!controlsLocked || !convOpts.length,
			onChange: function (e) {
				setDraftPreferredConverter(e.target.value);
			},
		});
		if (!convOpts.length) {
			converterSelect.appendChild(
				el('option', {
					value: '',
					text:
						i18n.preferredConverterNoneShort ||
						i18n.preferredConverterNone ||
						'None',
					title:
						i18n.preferredConverterNone ||
						'None (no converters for this type)',
				})
			);
			applySoleRequiredListLock(converterSelect, 0, {
				allowEmpty: true,
				disabled: true,
			});
		} else {
			var preferredConvCur = defaultPreferredConverterForNode(
				Object.assign({}, n, {
					preferredConverter:
						(state.draft && state.draft.preferredConverter) ||
						(n && n.preferredConverter) ||
						(state.draft &&
							state.draft.intConfig &&
							state.draft.intConfig.displayFormat) ||
						(n && n.intConfig && n.intConfig.displayFormat) ||
						'',
				})
			);
			if (state.draft && state.draft.preferredConverter !== preferredConvCur) {
				state.draft.preferredConverter = preferredConvCur;
				if (!state.draft.intConfig) {
					state.draft.intConfig = { displayFormat: preferredConvCur };
				} else {
					state.draft.intConfig.displayFormat = preferredConvCur;
				}
			}
			convOpts.forEach(function (opt) {
				converterSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label,
						selected: preferredConvCur === opt.value,
					})
				);
			});
			applySoleRequiredListLock(converterSelect, convOpts.length, {
				allowEmpty: false,
				disabled: !!controlsLocked,
				title:
					i18n.soleSelectLockedHint ||
					'Only one choice — selected automatically.',
			});
		}

		var renderLabelKids = [
			el('span', {
				className: 'wtt-preferred-pair__label',
				text:
					i18n.preferredRenderShort ||
					i18n.preferredRender ||
					'Render',
			}),
		];
		if (inheritMode) {
			renderLabelKids.push(
				el('span', {
					className: 'wtt-inherited-badge',
					text:
						i18n.preferredRenderInheritedBadge ||
						i18n.attributesInherited ||
						'Inherited',
					title:
						i18n.preferredRenderInheritHint ||
						'When unset, Preferred render walks the child_of parent chain.',
				})
			);
		}

		var validatorsUi = buildNodeValidatorsEditor(n, controlsLocked);
		var SR = settingsRender();
		var renderLabelNode = el(
			'span',
			{ className: 'wtt-preferred-pair__label-row' },
			renderLabelKids
		);
		var chrome;
		if (SR && typeof SR.renderPreferredChrome === 'function') {
			chrome = SR.renderPreferredChrome({
				render: {
					labelNode: renderLabelNode,
					select: preferredSelect,
				},
				converter: {
					label:
						i18n.preferredConverterShort ||
						i18n.preferredConverter ||
						'Converter',
					select: converterSelect,
				},
				validators: validatorsUi
					? {
							label: i18n.validators || 'Validators',
							select: validatorsUi.addSelect,
					  }
					: null,
				detail:
					validatorsUi && validatorsUi.tableWrap
						? validatorsUi.tableWrap
						: null,
				triple: !!validatorsUi,
			});
		} else {
			chrome = el('div', { className: 'wtt-settings-preferred-chrome' }, [
				el(
					'div',
					{
						className:
							'wtt-preferred-pair' +
							(validatorsUi ? ' wtt-preferred-pair--triple' : ''),
					},
					[
						el('div', { className: 'wtt-preferred-pair__item' }, [
							renderLabelNode,
							preferredSelect,
						]),
						el('div', { className: 'wtt-preferred-pair__item' }, [
							el('span', {
								className: 'wtt-preferred-pair__label',
								text:
									i18n.preferredConverterShort ||
									i18n.preferredConverter ||
									'Converter',
							}),
							converterSelect,
						]),
						validatorsUi
							? el('div', { className: 'wtt-preferred-pair__item' }, [
									el('span', {
										className: 'wtt-preferred-pair__label',
										text: i18n.validators || 'Validators',
									}),
									validatorsUi.addSelect,
							  ])
							: null,
					]
				),
				validatorsUi && validatorsUi.tableWrap
					? validatorsUi.tableWrap
					: null,
			]);
		}

		var help =
			i18n.preferredChromeHint ||
			'Render = paint; converter = value transform; validators = value checks. Only options that apply to this type.';
		if (parentId > 0) {
			help +=
				' ' +
				(i18n.preferredRenderInheritHint ||
					'When unset, Preferred render walks the child_of parent chain.');
		}

		form.appendChild(
			formRow(i18n.preferredChrome || 'Preferred', [chrome], {
				className:
					'wtt-form__row--preferred-pair' +
					(validatorsUi && validatorsUi.tableWrap
						? ' wtt-form__row--preferred-pair-validators'
						: ''),
				help: help,
			})
		);
	}

	function validatorRegistry() {
		return (
			(window.WTTValidator && window.WTTValidator.Registry) || null
		);
	}

	function isBoundValidatorId(id) {
		if (window.WTTValidator && typeof window.WTTValidator.isBoundValidatorId === 'function') {
			return window.WTTValidator.isBoundValidatorId(id);
		}
		id = String(id || '')
			.trim()
			.toLowerCase();
		return (
			id === 'int_min' ||
			id === 'int_max' ||
			id === 'double_min' ||
			id === 'double_max'
		);
	}

	function isLengthValidatorId(id) {
		if (window.WTTValidator && typeof window.WTTValidator.isLengthValidatorId === 'function') {
			return window.WTTValidator.isLengthValidatorId(id);
		}
		id = String(id || '')
			.trim()
			.toLowerCase();
		return id === 'text_min_length' || id === 'text_max_length';
	}

	function isCharsetValidatorId(id) {
		if (
			window.WTTValidator &&
			typeof window.WTTValidator.isCharsetValidatorId === 'function'
		) {
			return window.WTTValidator.isCharsetValidatorId(id);
		}
		id = String(id || '')
			.trim()
			.toLowerCase();
		return (
			id === 'charset_range' ||
			id === 'charset_allowlist' ||
			id === 'charset_regex'
		);
	}

	function isParamThresholdValidatorId(id) {
		if (
			window.WTTValidator &&
			typeof window.WTTValidator.isParamThresholdValidatorId === 'function'
		) {
			return window.WTTValidator.isParamThresholdValidatorId(id);
		}
		return isBoundValidatorId(id) || isLengthValidatorId(id);
	}

	function isParamValueValidatorId(id) {
		if (
			window.WTTValidator &&
			typeof window.WTTValidator.isParamValueValidatorId === 'function'
		) {
			return window.WTTValidator.isParamValueValidatorId(id);
		}
		return isParamThresholdValidatorId(id) || isCharsetValidatorId(id);
	}

	function isIntegerThresholdValidatorId(id) {
		return isBoundValidatorId(id)
			? String(id).indexOf('int_') === 0
			: isLengthValidatorId(id);
	}

	function defaultBoundValue(id) {
		if (window.WTTValidator && typeof window.WTTValidator.defaultBoundValue === 'function') {
			return window.WTTValidator.defaultBoundValue(id);
		}
		id = String(id || '')
			.trim()
			.toLowerCase();
		if (id === 'charset_range') {
			return 'a-z';
		}
		if (id === 'charset_allowlist') {
			return 'a,b,c';
		}
		if (id === 'charset_regex') {
			return '[a-zA-Z0-9]';
		}
		return id.indexOf('_max') !== -1 ? 100 : 0;
	}

	function boundValueFromEntry(entry) {
		if (window.WTTValidator && typeof window.WTTValidator.boundValueFromEntry === 'function') {
			return window.WTTValidator.boundValueFromEntry(entry);
		}
		if (!entry || !entry.params || entry.params.value == null) {
			return null;
		}
		var n = Number(entry.params.value);
		return isFinite(n) ? n : null;
	}

	function stringParamFromEntry(entry) {
		if (
			window.WTTValidator &&
			typeof window.WTTValidator.stringParamFromEntry === 'function'
		) {
			return window.WTTValidator.stringParamFromEntry(entry);
		}
		if (!entry || !entry.params || entry.params.value == null) {
			return null;
		}
		var s = String(entry.params.value).trim();
		return s === '' ? null : s;
	}

	function newBoundValidatorEntry(id, reg) {
		id = String(id || '')
			.trim()
			.toLowerCase();
		var entry = {
			id: id,
			errorText: (reg && reg.defaultError && reg.defaultError(id)) || '',
			isDefault: false,
			fixes: [],
		};
		if (isParamValueValidatorId(id)) {
			entry.params = { value: defaultBoundValue(id) };
		}
		if (id === 'expression') {
			entry.expression = 'value != null && value !== ""';
		}
		return entry;
	}

	function normalizeValidatorsList(raw, node) {
		var reg = validatorRegistry();
		if (reg && typeof reg.effectiveList === 'function') {
			return reg.effectiveList(
				Object.assign({}, node || {}, { validators: raw || [] })
			);
		}
		if (reg && typeof reg.normalizeList === 'function') {
			return reg.normalizeList(raw || []);
		}
		return Array.isArray(raw) ? raw.slice() : [];
	}

	function setDraftValidators(list) {
		if (!state.draft) {
			return;
		}
		state.draft.validators = normalizeValidatorsList(list, state.draft);
		afterDraftMutation();
	}

	/**
	 * Shared validators chrome: Add select + row-edit table (Bound | Error | Fix | Actions).
	 * Same UI for type Settings, attribute detail, and Settings walk — no parallel list/checkbox UIs.
	 *
	 * @param {object} opts
	 * @param {object} opts.probe Node/attr probe for normalize + listCompatible
	 * @param {Array} opts.list Current validator entries
	 * @param {boolean} [opts.locked]
	 * @param {function(Array):void} opts.onChange Persist next list
	 * @param {string} [opts.wrapClass]
	 * @return {{ addSelect: HTMLElement, tableWrap: HTMLElement }|null}
	 */
	function buildValidatorsEditor(opts) {
		opts = opts || {};
		var reg = validatorRegistry();
		if (!reg) {
			return null;
		}
		var probe = opts.probe || {};
		var list = normalizeValidatorsList(opts.list || [], probe);
		var locked = !!opts.locked;
		var onChange =
			typeof opts.onChange === 'function' ? opts.onChange : function () {};
		var compatible =
			typeof reg.listCompatible === 'function'
				? reg.listCompatible(probe) || []
				: [];
		if (!compatible.length && !list.length) {
			return null;
		}

		var showExpr = list.some(function (entry) {
			return entry && entry.id === 'expression';
		});
		var colCount = showExpr ? 6 : 5;

		var table = el('table', {
			className: 'wtt-row-edit-table wtt-validators-table widefat',
		});
		var headCells = [
			el('th', {
				className: 'wtt-validators-table__col-id',
				text: i18n.validatorColId || 'Validator',
			}),
			el('th', {
				className: 'wtt-validators-table__col-bound',
				text: i18n.validatorColBound || 'Bound',
			}),
			el('th', {
				className: 'wtt-validators-table__col-error',
				text: i18n.validatorColError || 'Error text',
			}),
		];
		if (showExpr) {
			headCells.push(
				el('th', {
					className: 'wtt-validators-table__col-expr',
					text: i18n.validatorColExpression || 'Expression',
				})
			);
		}
		headCells.push(
			el('th', {
				className: 'wtt-validators-table__col-fix',
				text: i18n.validatorColFix || 'Fix',
			}),
			el('th', {
				className: 'wtt-col-actions',
				text: i18n.validatorColActions || 'Actions',
			})
		);
		var thead = el('thead');
		thead.appendChild(el('tr', null, headCells));
		table.appendChild(thead);
		var tbody = el('tbody');

		function rewrite(next) {
			onChange(normalizeValidatorsList(next, probe));
		}

		if (!list.length) {
			tbody.appendChild(
				el('tr', { className: 'wtt-validators-table__empty' }, [
					el('td', {
						colSpan: String(colCount),
						className: 'wtt-validators-table__empty-cell',
					}, [
						el('span', {
							className: 'description wtt-validators-table__empty-hint',
							text:
								i18n.validatorsEmptyHint ||
								'No validators yet — use Add validator to add one.',
						}),
					]),
				])
			);
		}

		list.forEach(function (entry, idx) {
			var isDefault = !!entry.isDefault;
			var isExpr = entry.id === 'expression';
			var label =
				(reg.labelFor && reg.labelFor(entry.id)) || entry.id;
			if (isDefault) {
				label =
					label +
					' (' +
					(i18n.validatorDefaultBadge || 'Default') +
					')';
			}

			var errorInput = el('input', {
				type: 'text',
				className: 'wtt-validators-table__input',
				value: entry.errorText || '',
				disabled: !!locked,
				onChange: function (e) {
					var next = list.map(function (row, i) {
						if (i !== idx) {
							return row;
						}
						return Object.assign({}, row, {
							errorText: e.target.value,
						});
					});
					rewrite(next);
				},
			});

			var boundCell;
			if (isCharsetValidatorId(entry.id)) {
				var curSpec = stringParamFromEntry(entry);
				if (curSpec == null || curSpec === '') {
					curSpec = String(defaultBoundValue(entry.id) || '');
				}
				var charsetHint =
					entry.id === 'charset_range'
						? i18n.validatorCharsetRangeHint ||
						  'Ranges: a-z, A-Z, 0-9 or U+0041-U+005A (comma-separated).'
						: entry.id === 'charset_allowlist'
							? i18n.validatorCharsetAllowlistHint ||
							  'Comma-separated allowed characters (\\, for a literal comma).'
							: i18n.validatorCharsetRegexHint ||
							  'Regex matched against the whole value, e.g. [0-9a-z] or [0-9]|[a-z].';
				boundCell = el('input', {
					type: 'text',
					className:
						'wtt-validators-table__input wtt-validators-table__bound wtt-validators-table__bound--text',
					value: curSpec,
					disabled: !!locked,
					title: charsetHint,
					placeholder:
						entry.id === 'charset_range'
							? 'a-z,A-Z,0-9'
							: entry.id === 'charset_allowlist'
								? 'a,b,c'
								: '[a-zA-Z0-9]',
					onChange: function (e) {
						var nextSpec = String(e.target.value || '');
						var next = list.map(function (row, i) {
							if (i !== idx) {
								return row;
							}
							return Object.assign({}, row, {
								params: { value: nextSpec },
							});
						});
						rewrite(next);
					},
				});
			} else if (isParamThresholdValidatorId(entry.id)) {
				var isIntBound = isIntegerThresholdValidatorId(entry.id);
				var curBound = boundValueFromEntry(entry);
				if (curBound == null) {
					curBound = defaultBoundValue(entry.id);
				}
				var boundInput = el('input', {
					type: 'number',
					className: 'wtt-validators-table__input wtt-validators-table__bound',
					step: isIntBound ? '1' : 'any',
					value: String(curBound),
					disabled: !!locked,
					title:
						i18n.validatorBoundHint ||
						'Threshold for this min/max or length check',
					onChange: function (e) {
						var raw = e.target.value;
						var num = isIntBound
							? parseInt(raw, 10)
							: parseFloat(raw);
						if (!isFinite(num)) {
							return;
						}
						var next = list.map(function (row, i) {
							if (i !== idx) {
								return row;
							}
							return Object.assign({}, row, {
								params: {
									value: isIntBound ? Math.trunc(num) : num,
								},
							});
						});
						rewrite(next);
					},
				});
				boundCell = boundInput;
			} else {
				boundCell = el('span', {
					className: 'description',
					text: '—',
				});
			}

			var exprCell;
			if (isExpr) {
				var exprInput = el('input', {
					type: 'text',
					className: 'wtt-validators-table__input',
					value: entry.expression || '',
					disabled: !!locked,
					onChange: function (e) {
						var next = list.map(function (row, i) {
							if (i !== idx) {
								return row;
							}
							return Object.assign({}, row, {
								expression: e.target.value,
							});
						});
						rewrite(next);
					},
				});
				exprCell = exprInput;
			} else {
				exprCell = el('span', {
					className: 'description',
					text: '—',
				});
			}

			var fixInput = el('input', {
				type: 'text',
				className: 'wtt-validators-table__input',
				value: Array.isArray(entry.fixes)
					? entry.fixes.join(', ')
					: '',
				disabled: !!locked || isDefault,
				placeholder: i18n.validatorFixPlaceholder || '',
				onChange: function (e) {
					var raw = String(e.target.value || '');
					var fixes = raw
						.split(',')
						.map(function (s) {
							return s.trim();
						})
						.filter(Boolean);
					var next = list.map(function (row, i) {
						if (i !== idx) {
							return row;
						}
						return Object.assign({}, row, { fixes: fixes });
					});
					rewrite(next);
				},
			});

			var trashBtn;
			if (!isDefault && !locked) {
				trashBtn = el('button', {
					type: 'button',
					className:
						'button-link button-link-delete wtt-row-edit-table__trash wtt-validators-table__trash',
					title: i18n.validatorRemove || 'Remove validator',
					'aria-label': i18n.validatorRemove || 'Remove validator',
					onClick: function () {
						rewrite(
							list.filter(function (_row, i) {
								return i !== idx;
							})
						);
					},
				});
				trashBtn.appendChild(
					el('span', {
						className: 'dashicons dashicons-trash',
						'aria-hidden': 'true',
					})
				);
			} else {
				trashBtn = el('span', { className: 'description', text: '—' });
			}

			var rowCells = [
				el('td', {
					className: 'wtt-validators-table__col-id',
					text: label,
				}),
				el('td', { className: 'wtt-validators-table__col-bound' }, [
					boundCell,
				]),
				el('td', { className: 'wtt-validators-table__col-error' }, [
					errorInput,
				]),
			];
			if (showExpr) {
				rowCells.push(
					el('td', { className: 'wtt-validators-table__col-expr' }, [
						exprCell,
					])
				);
			}
			rowCells.push(
				el('td', { className: 'wtt-validators-table__col-fix' }, [
					fixInput,
				]),
				el('td', { className: 'wtt-col-actions' }, [trashBtn])
			);
			tbody.appendChild(el('tr', null, rowCells));
		});
		table.appendChild(tbody);

		var addSelect = el('select', {
			className:
				'wtt-validators-editor__add-select wtt-preferred-validators-select',
			disabled: !!locked,
			title:
				i18n.validatorsHint ||
				'0..n value checks. Type default stays; add more including Expression.',
		});
		addSelect.appendChild(
			el('option', {
				value: '',
				text: i18n.validatorAdd || 'Add validator',
			})
		);
		compatible.forEach(function (opt) {
			var id = String(opt.id || '');
			if (!id) {
				return;
			}
			/* Allow multiple expression rows; other ids only once. */
			if (
				id !== 'expression' &&
				list.some(function (row) {
					return row.id === id;
				})
			) {
				return;
			}
			addSelect.appendChild(
				el('option', {
					value: id,
					text: opt.label || (reg.labelFor && reg.labelFor(id)) || id,
				})
			);
		});
		addSelect.addEventListener('change', function () {
			var id = String(addSelect.value || '');
			addSelect.value = '';
			if (!id) {
				return;
			}
			var entry = newBoundValidatorEntry(id, reg);
			rewrite(list.concat([entry]));
		});

		var tableWrap = el(
			'div',
			{
				className:
					'wtt-validators-editor' +
					(opts.wrapClass ? ' ' + opts.wrapClass : ''),
			},
			[
				el('div', { className: 'wtt-validators-editor__scroll' }, [
					table,
				]),
			]
		);

		return {
			addSelect: addSelect,
			tableWrap: tableWrap,
		};
	}

	/**
	 * Type / node Settings — Preferred triple row validators.
	 *
	 * @return {{ addSelect: HTMLElement, tableWrap: HTMLElement|null }|null}
	 */
	function buildNodeValidatorsEditor(n, controlsLocked) {
		var probe = {
			id: n && n.id,
			name: n && n.name,
			typeKey: resolveNodeRenderTypeKey(n),
			type: n && n.type,
			validators:
				(state.draft && state.draft.validators) ||
				(n && n.validators) ||
				[],
		};
		var list = normalizeValidatorsList(probe.validators, probe);
		if (state.draft && !Array.isArray(state.draft.validators)) {
			state.draft.validators = list;
		}
		return buildValidatorsEditor({
			probe: probe,
			list: list,
			locked: !!controlsLocked,
			wrapClass: 'wtt-validators-editor--under-preferred',
			onChange: function (next) {
				setDraftValidators(next);
			},
		});
	}

	/** @deprecated Preferred row owns validators UI. */
	function renderValidatorsSection() {
		/* no-op — merged into appendPreferredRenderConverterValidatorsRow */
	}

	function renderDateSettings(n, pane) {
		var isDateCatalog =
			n &&
			String(n.name || '')
				.trim()
				.toLowerCase() === 'date';
		if (!n || (!n.dateConfig && !isDateCatalog)) {
			return;
		}
		if (!n.dateConfig) {
			n.dateConfig = { mode: 'date' };
		}
		var block = el('div', { className: 'wtt-panel wtt-date-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-date-settings__title',
				text: i18n.dateSettings || 'Date settings',
			})
		);
		block.appendChild(
			el('p', {
				className: 'description',
				text:
					i18n.dateModeHint ||
					'Choose date-only or date+time. Instance values are stored as Unix timestamps.',
			})
		);
		var row = el('div', { className: 'wtt-date-settings__mode' });
		row.appendChild(
			el('label', {
				className: 'wtt-date-settings__label',
				text: i18n.dateMode || 'Mode',
			})
		);
		var select = el('select', {
			className: 'wtt-select wtt-date-settings__select',
			id: 'wtt-date-mode',
		});
		var cur = n.dateConfig.mode === 'datetime' ? 'datetime' : 'date';
		[
			{ value: 'date', label: i18n.dateModeDate || 'Date only' },
			{ value: 'datetime', label: i18n.dateModeDateTime || 'Date and time' },
		].forEach(function (opt) {
			var o = el('option', { value: opt.value, text: opt.label });
			if (opt.value === cur) {
				o.selected = true;
			}
			select.appendChild(o);
		});
		select.addEventListener('change', function () {
			setDraftDateMode(select.value);
		});
		row.appendChild(select);
		block.appendChild(row);
		pane.appendChild(block);
	}

	function renderTextareaSettings(n, pane) {
		var isTaCatalog =
			n &&
			String(n.name || n.typeKey || '')
				.trim()
				.toLowerCase() === 'textarea';
		var cfg = normalizeTextareaConfig(
			(state.draft && state.draft.textareaConfig) || n.textareaConfig,
			isTaCatalog ? 'textarea' : ''
		);
		if (!n || (!cfg && !isTaCatalog)) {
			return;
		}
		if (!cfg) {
			cfg = { cols: 40, rows: 4 };
		}
		if (state.draft && !state.draft.textareaConfig) {
			state.draft.textareaConfig = { cols: cfg.cols, rows: cfg.rows };
		}
		var block = el('div', { className: 'wtt-panel wtt-textarea-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title',
				text: i18n.textareaSettings || 'Textarea settings',
			})
		);
		block.appendChild(
			el('p', {
				className: 'description',
				text:
					i18n.textareaLayoutHint ||
					'Columns = characters per line; lines = visible rows in the editor.',
			})
		);
		function addNumRow(key, label, value) {
			var row = el('div', { className: 'wtt-textarea-settings__row' });
			row.appendChild(
				el('label', {
					className: 'wtt-textarea-settings__label',
					text: label,
				})
			);
			var input = el('input', {
				type: 'number',
				className: 'wtt-select wtt-textarea-settings__input',
				min: '1',
				max: key === 'cols' ? '200' : '100',
				step: '1',
				value: String(value),
			});
			input.addEventListener('change', function () {
				if (!state.draft) {
					return;
				}
				var patch = {};
				patch[key] = input.value;
				var next = normalizeTextareaConfig(
					Object.assign({}, state.draft.textareaConfig || cfg, patch),
					'textarea'
				);
				state.draft.textareaConfig = next;
				afterDraftMutation();
			});
			row.appendChild(input);
			block.appendChild(row);
		}
		addNumRow('cols', i18n.textareaCols || 'Columns (chars/line)', cfg.cols);
		addNumRow('rows', i18n.textareaRows || 'Lines (rows)', cfg.rows);
		pane.appendChild(block);
	}

	function renderPresentationTypeSettings(n, pane) {
		var isPresCatalog =
			n &&
			isNodePresentationTypeKey(
				n.typeKey || (n.type && n.type.name) || n.name
			);
		if (!n || (!n.presentationConfig && !isPresCatalog)) {
			return;
		}
		if (!n.presentationConfig) {
			n.presentationConfig = { context: 'form' };
		}
		var block = el('div', {
			className: 'wtt-panel wtt-presentation-type-settings',
		});
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title',
				text:
					i18n.presentationTypeSettings ||
					'Node presentation settings',
			})
		);
		block.appendChild(
			el('p', {
				className: 'description',
				text:
					i18n.presentationTypeHint ||
					'Choose which presentation field of the host node to show (form, table, select, symbol, help, or icon).',
			})
		);
		var row = el('div', {
			className: 'wtt-presentation-type-settings__row',
		});
		row.appendChild(
			el('label', {
				className: 'wtt-presentation-type-settings__label',
				text:
					i18n.attributesPresentationContext || 'Presentation field',
			})
		);
		var select = el('select', {
			className: 'wtt-select',
			id: 'wtt-presentation-context',
		});
		var cur = normalizePresentationContext(
			n.presentationConfig.context || 'form'
		);
		presentationContextOptions().forEach(function (opt) {
			var o = el('option', { value: opt.value, text: opt.label });
			if (opt.value === cur) {
				o.selected = true;
			}
			select.appendChild(o);
		});
		select.addEventListener('change', function () {
			setDraftPresentationContext(select.value);
		});
		row.appendChild(select);
		block.appendChild(row);
		pane.appendChild(block);
	}

	/**
	 * Caption for a set field from set name + member shortDescription.
	 * @param {string} setName
	 * @param {Array} members
	 * @param {string|{separator?:string,includeChildren?:boolean}} separatorOrOpts
	 */
	function setFieldCaption(setName, members, separatorOrOpts) {
		var opts =
			separatorOrOpts && typeof separatorOrOpts === 'object'
				? separatorOrOpts
				: { separator: separatorOrOpts };
		var sep = opts.separator != null ? String(opts.separator) : '/';
		var includeChildren = opts.includeChildren !== false;
		var base = setName != null ? String(setName).trim() : '';
		if (!includeChildren) {
			return base || (i18n.previewColField || 'Field');
		}
		var parts = [];
		(members || []).forEach(function (m) {
			if (!m) {
				return;
			}
			var short = m.shortDescription != null ? String(m.shortDescription).trim() : '';
			var part = short || (m.name ? String(m.name) : '');
			if (part) {
				parts.push(part);
			}
		});
		if (!parts.length) {
			return base || (i18n.previewColField || 'Field');
		}
		var joined = parts.join(sep);
		return base ? base + ' (' + joined + ')' : joined;
	}

	/** Shared set label/display options from a node (or draft view). */
	function setFieldOptionsFromNode(n) {
		return {
			separator: n && n.setSeparator != null ? String(n.setSeparator) : '/',
			joinUnits: !n || n.setJoinUnits !== false,
			includeChildren: !n || n.setLabelChildren !== false,
			asSetField: true,
		};
	}

	/**
	 * Magnitude + unit suffix for a field (quantity â†’ value / prefix+symbol).
	 * @param {*} member
	 * @param {string|null} sharedPrefixScope When set (join-units), Praefix is read from this scope.
	 */
	function fieldDisplayParts(member, sharedPrefixScope) {
		var normalized = asPreviewField(member) || member;
		var scope =
			normalized && (normalized.id != null ? normalized.id : normalized.name);
		var qty = resolveQuantityMembers(normalized);
		if (qty) {
			var typ = findSetMemberByKey(qty, 'typ') || findSetMemberByKey(qty, 'wert');
			var praefix = findSetMemberByKey(qty, 'praefix');
			var value = typ
				? livePreviewText(scope, typ)
				: getPreviewValue(scope, { name: 'Typ' }, '10.5');
			var prefixScope = sharedPrefixScope != null ? sharedPrefixScope : scope;
			var prefixPart = samplePrefixLetter(praefix, prefixScope);
			var kuerzelMem = findSetMemberByKey(qty, 'kuerzel');
			var symbol =
				(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
				(kuerzelMem && kuerzelMem.fixedLiteral) ||
				'';
			var unit = String(prefixPart || '') + String(symbol || '');
			return { value: String(value), unit: unit, full: String(value) + unit };
		}
		var text = livePreviewText(scope, normalized);
		var key = typeKeyFromMember(normalized);
		if (key === 'media') {
			var ref = parseMediaRef(text);
			var label = 'â€”';
			if (ref) {
				var kind = classifyMediaKind(ref);
				var url = ref.url ? String(ref.url) : '';
				if (url.indexOf('data:') === 0 || (url.length > 48 && !ref.filename)) {
					label = mediaKindLabel(kind) || kind || (i18n.mediaFile || 'file');
				} else {
					label = mediaDisplayLabel(ref);
				}
			}
			return { value: label, unit: '', full: label };
		}
		return { value: text, unit: '', full: text };
	}

	function setJoinSharedScope() {
		return 'join:' + String(state.selectedId || 0);
	}

	/** Same type + quantity with Praefix â†’ shared Praefix/Kuerzel in preview. */
	function membersAreJoinableQuantities(members) {
		if (!canJoinSetUnits(members)) {
			return false;
		}
		return (members || []).every(function (m) {
			var qty = resolveQuantityMembers(asPreviewField(m) || m);
			return !!(qty && findSetMemberByKey(qty, 'praefix'));
		});
	}

	/**
	 * Display-only set: values joined with separator; optional shared unit at end.
	 */
	function renderSetJoinedDisplay(members, opts) {
		opts = opts || {};
		var sep = opts.separator != null ? String(opts.separator) : '/';
		var joinUnits = !!opts.joinUnits && membersShareSameType(members);
		var sharedScope = joinUnits && membersAreJoinableQuantities(members) ? setJoinSharedScope() : null;
		var parts = (members || []).map(function (m) {
			return fieldDisplayParts(m, sharedScope);
		});
		var text = '';
		if (joinUnits && parts.length) {
			var unit = parts[0].unit;
			var allSameUnit =
				unit !== '' &&
				parts.every(function (p) {
					return p.unit === unit;
				});
			if (allSameUnit) {
				text =
					parts
						.map(function (p) {
							return p.value;
						})
						.join(sep) + unit;
			} else {
				text = parts
					.map(function (p) {
						return p.full;
					})
					.join(sep);
			}
		} else {
			text = parts
				.map(function (p) {
					return p.full;
				})
				.join(sep);
		}
		return el('span', {
			className: 'wtt-preview-display-value wtt-set-preview__joined',
			text: text,
		});
	}

	/**
	 * Editable set with join-units: magnitudes + separator, then one shared Praefix + Kuerzel.
	 */
	function renderSetJoinedQuantityEdit(members, opts) {
		opts = opts || {};
		var separator = opts.separator != null ? String(opts.separator) : '/';
		var sharedScope = setJoinSharedScope();
		var cell = el('div', {
			className: 'wtt-set-preview__cell wtt-set-preview__cell--joined-qty',
			title: i18n.setTableCellHint || 'Compact set as one table field',
		});
		var count = (members || []).length;
		if (count > 0) {
			cell.style.setProperty('--wtt-set-parts', String(count));
		}

		(members || []).forEach(function (member, index) {
			if (index > 0) {
				cell.appendChild(
					el('span', {
						className: 'wtt-set-preview__sep',
						text: separator,
						'aria-hidden': 'true',
					})
				);
			}
			var part = el('span', { className: 'wtt-set-preview__cell-part' });
			if (count > 0) {
				part.style.flexBasis = (100 / count).toFixed(4) + '%';
			}
			var qty = resolveQuantityMembers(asPreviewField(member) || member);
			var scope = member.id != null ? member.id : member.name;
			var typ = qty
				? findSetMemberByKey(qty, 'typ') || findSetMemberByKey(qty, 'wert')
				: null;
			if (typ) {
				part.appendChild(
					renderScalarFieldView(typ, { compact: true, mode: 'edit', scope: scope })
				);
			} else {
				part.appendChild(renderFieldView(member, { compact: true, mode: 'edit' }));
			}
			cell.appendChild(part);
		});

		var firstQty = resolveQuantityMembers(asPreviewField(members[0]) || members[0]);
		var unitWrap = el('span', { className: 'wtt-set-preview__shared-unit' });
		if (firstQty) {
			var praefix = findSetMemberByKey(firstQty, 'praefix');
			if (praefix) {
				var sharedPrefixSample = livePreviewText(sharedScope, praefix);
				var joinedRootToSi = 1;
				var firstField = asPreviewField(members[0]) || members[0];
				if (
					firstField &&
					firstField.quantitySchema &&
					firstField.quantitySchema.prefixRootToSi != null
				) {
					var parsedRoot = Number(firstField.quantitySchema.prefixRootToSi);
					if (isFinite(parsedRoot) && parsedRoot > 0) {
						joinedRootToSi = parsedRoot;
					}
				}
				unitWrap.appendChild(
					renderBranchSelect(praefix, {
						compact: true,
						sample: sharedPrefixSample,
						editable: true,
						scope: sharedScope,
						emptyLabel: '—',
						emptyValue: '',
						allowEmpty: memberListSelectAllowsEmpty(praefix),
						beforeChange: function (oldKey, newKey) {
							/* Q109: shared Präfix → rescale each member Typ. */
							rescaleQuantityTypsOnPrefixChange(
								members,
								praefix,
								oldKey,
								newKey,
								joinedRootToSi
							);
						},
					})
				);
			}
			var kuerzel = findSetMemberByKey(firstQty, 'kuerzel');
			var symbolText =
				(kuerzel && kuerzel.fixed && kuerzel.fixed.name) ||
				(kuerzel && kuerzel.fixedLiteral) ||
				'';
			if (symbolText) {
				unitWrap.appendChild(
					el('span', {
						className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
						text: symbolText,
					})
				);
			}
		}
		cell.appendChild(unitWrap);
		return cell;
	}

	/**
	 * One help for a set field: parent description, then each member (and nested) below.
	 * Prefer server helpChildren; else build from preview members.
	 */
	function setFieldHelpPayload(parentDescription, helpChildren, members) {
		var children = Array.isArray(helpChildren) && helpChildren.length ? helpChildren : null;
		if (!children && members && members.length) {
			children = members.map(function (m) {
				var nested = memberHelpPayload(m);
				return {
					name: m.name || '',
					typeName: (m.type && m.type.name) || m.typeName || '',
					required: !!m.required,
					fixed: (m.fixed && m.fixed.name) || m.fixed || '',
					description: nested.description || '',
					children:
						nested.helpChildren && nested.helpChildren.length
							? nested.helpChildren
							: null,
				};
			});
		}
		return {
			description: parentDescription != null ? String(parentDescription) : '',
			helpChildren: children || [],
		};
	}

	/**
	 * Set as one form row (same idea as one table column): label + inline member controls.
	 */
	function renderSetAsOneFormRow(setName, members, mode, helpPayload, setOpts) {
		setOpts = setOpts || {};
		var separator = setOpts.separator != null ? String(setOpts.separator) : '/';
		var joinUnits = setOpts.joinUnits !== false;
		var includeChildren = setOpts.includeChildren !== false;
		var ordered = membersPickFirst(members);
		var form = el('div', {
			className:
				'wtt-set-preview__form' +
				(mode === 'display' ? ' wtt-set-preview__form--display' : ''),
		});
		var row = el('div', { className: 'wtt-set-preview__row wtt-set-preview__row--set-field' });
		row.appendChild(
			el('label', {
				className: 'wtt-set-preview__label',
				text: setFieldCaption(setName, ordered, {
					separator: separator,
					includeChildren: includeChildren,
				}),
			})
		);
		/* Same control layout for edit and display (display = read-only mirror). */
		if (joinUnits && membersAreJoinableQuantities(ordered)) {
			if (mode === 'display') {
				row.appendChild(
					renderSetJoinedDisplay(ordered, {
						separator: separator,
						joinUnits: joinUnits,
					})
				);
			} else {
				row.appendChild(
					renderSetJoinedQuantityEdit(ordered, {
						separator: separator,
					})
				);
			}
		} else {
			row.appendChild(
				renderSetTableCell(ordered, {
					mode: mode,
					showPartLabels: false,
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		}
		var help = renderHelpHint(helpPayload || setFieldHelpPayload('', null, ordered));
		if (help) {
			row.appendChild(help);
		}
		form.appendChild(row);
		return form;
	}

	function renderSetFormPreview(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		if (opts.asSetField && members && members.length > 1) {
			var helpPayload = setFieldHelpPayload(
				opts.setDescription || '',
				opts.helpChildren || null,
				members
			);
			return renderSetAsOneFormRow(opts.setName || '', members, mode, helpPayload, {
				separator: opts.separator != null ? opts.separator : '/',
				joinUnits: opts.joinUnits !== false,
				includeChildren: opts.includeChildren !== false,
			});
		}
		var form = el('div', {
			className: 'wtt-set-preview__form' + (mode === 'display' ? ' wtt-set-preview__form--display' : ''),
		});
		members.forEach(function (member) {
			var key = typeKeyFromMember(member);
			var row = el('div', {
				className:
					'wtt-set-preview__row' +
					(isNodePresentationTypeKey(key)
						? ' wtt-set-preview__row--display-name'
						: ''),
			});

			if (isNodePresentationTypeKey(key)) {
				var nameWrap = el('div', { className: 'wtt-set-preview__display-name' });
				nameWrap.appendChild(renderFieldView(member, { compact: false, mode: mode }));
				row.appendChild(nameWrap);
				if (mode === 'edit') {
					var helpOnly = renderHelpHint(memberHelpPayload(member));
					if (helpOnly) {
						row.appendChild(helpOnly);
					}
				}
				form.appendChild(row);
				return;
			}

			var label = el('label', { className: 'wtt-set-preview__label' });
			var title = member.name || '';
			if (member.required) {
				title += ' *';
			}
			label.appendChild(document.createTextNode(title));
			if (member.fixed && member.fixed.name) {
				label.appendChild(
					el('span', {
						className: 'wtt-set-preview__badge',
						text: ' ' + (i18n.previewFixed || 'fixed'),
					})
				);
			}
			row.appendChild(label);
			row.appendChild(renderFieldView(member, { compact: false, mode: mode }));
			if (mode === 'edit') {
				var help = renderHelpHint(memberHelpPayload(member));
				if (help) {
					row.appendChild(help);
				}
			}
			form.appendChild(row);
		});
		return form;
	}

	function renderSetTableCell(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var showPartLabels = opts.showPartLabels !== false;
		var separator = opts.separator != null ? String(opts.separator) : '/';
		var joinUnits = opts.joinUnits !== false;
		var parts = partitionSetMembers(members);
		var ordered = parts.primary.concat(parts.bools, parts.statics);

		if (
			mode === 'edit' &&
			joinUnits &&
			parts.primary.length > 1 &&
			!parts.bools.length &&
			!parts.statics.length &&
			membersAreJoinableQuantities(parts.primary)
		) {
			return renderSetJoinedQuantityEdit(parts.primary, {
				separator: separator,
			});
		}

		var cell = el('div', {
			className:
				'wtt-set-preview__cell' +
				(parts.bools.length ? ' wtt-set-preview__cell--stacked' : ' wtt-set-preview__cell--inline') +
				(mode === 'display' ? ' wtt-set-preview__cell--display' : ''),
			title: i18n.setTableCellHint || 'Compact set as one table field',
		});

		var main = el('div', { className: 'wtt-set-preview__main' });
		if (parts.primary.length) {
			if (
				mode === 'edit' &&
				joinUnits &&
				parts.primary.length > 1 &&
				membersAreJoinableQuantities(parts.primary)
			) {
				main.appendChild(
					renderSetJoinedQuantityEdit(parts.primary, {
						separator: separator,
					})
				);
			} else {
				main.appendChild(
					renderSetMemberStrip(parts.primary, {
						mode: mode,
						separator: separator,
						showPartLabels: showPartLabels,
						className: 'wtt-set-preview__strip--primary',
					})
				);
			}
		}
		if (parts.statics.length) {
			main.appendChild(renderSetStaticStrip(parts.statics, { mode: mode }));
		}
		if (main.childNodes.length) {
			cell.appendChild(main);
		}
		if (parts.bools.length) {
			cell.appendChild(renderSetBoolStrip(parts.bools, { mode: mode }));
		}
		if (!parts.primary.length && !parts.bools.length && !parts.statics.length) {
			cell.appendChild(document.createTextNode('â€”'));
		}
		/* caption order helper for callers */
		cell.setAttribute('data-wtt-member-count', String(ordered.length));
		return cell;
	}

	/** Generic table context: neighbor columns + this node as ONE field (set members stay in the cell). */
	function renderGenericFieldTablePreview(members, fieldLabel, mode, setOpts) {
		setOpts = setOpts || {};
		var separator = setOpts.separator != null ? String(setOpts.separator) : '/';
		var joinUnits = setOpts.joinUnits !== false;
		var includeChildren = setOpts.includeChildren !== false;
		var orderedMembers = membersPickFirst(members);
		var caption =
			setOpts.asSetField && orderedMembers && orderedMembers.length > 1
				? setFieldCaption(fieldLabel, orderedMembers, {
						separator: separator,
						includeChildren: includeChildren,
				  })
				: fieldLabel || i18n.previewColField || 'Field';
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		[
			i18n.previewColIndex || '#',
			i18n.previewColOther || 'Column A',
			caption,
			i18n.previewColNote || 'Column B',
		].forEach(function (label) {
			headRow.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var row = el('tr');
		row.appendChild(el('td', { text: '1' }));
		if (mode === 'edit') {
			var otherTd = el('td');
			otherTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: i18n.previewSampleText || 'Sample',
				})
			);
			row.appendChild(otherTd);
		} else {
			row.appendChild(el('td', { text: i18n.previewSampleText || 'Sample' }));
		}
		var fieldTd = el('td', { className: 'wtt-set-preview__td-set' });
		if (members.length > 1) {
			fieldTd.appendChild(
				renderSetTableCell(members, {
					mode: mode,
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		} else if (members.length === 1) {
			fieldTd.appendChild(renderFieldView(members[0], { compact: true, mode: mode }));
		} else {
			fieldTd.appendChild(document.createTextNode('â€”'));
		}
		row.appendChild(fieldTd);
		if (mode === 'edit') {
			var noteTd = el('td');
			noteTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: 'â€¦',
				})
			);
			row.appendChild(noteTd);
		} else {
			row.appendChild(el('td', { text: 'â€¦' }));
		}
		tbody.appendChild(row);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function memberNameKey(member) {
		return String((member && member.name) || '')
			.toLowerCase()
			.replace(/\u00fc/g, 'ue')
			.replace(/\u00e4/g, 'ae')
			.replace(/\u00f6/g, 'oe')
			.replace(/Ã¼/g, 'ue')
			.replace(/Ã¤/g, 'ae')
			.replace(/Ã¶/g, 'oe');
	}

	/**
	 * Basiseinheit / quantity member lookup (Typ|Wert, Praefix, Kuerzel|Einheit).
	 */
	function findSetMemberByKey(members, nameKey) {
		var aliases = {
			typ: ['typ', 'wert', 'value', 'magnitude', 'betrag'],
			praefix: ['praefix', 'prefix', 'prafix'],
			kuerzel: ['kuerzel', 'einheit', 'unit', 'symbol', 'waehrung', 'currency'],
		};
		var keys = aliases[nameKey] || [nameKey];
		var found = null;
		(members || []).forEach(function (m) {
			if (found) {
				return;
			}
			var key = memberNameKey(m);
			if (keys.indexOf(key) !== -1) {
				found = m;
			}
		});
		return found;
	}

	/**
	 * Basiseinheit unit symbol: fixed Praefix (if any) + Kuerzel.
	 * Does not invent a sample prefix â€” optional Praefix stays off the unit label (Meter â†’ m, not mm).
	 */
	function composeUnitDisplay(members) {
		members = members || [];
		var prefix = '';
		var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
		var praefixMem = findSetMemberByKey(members, 'praefix');
		var kuerzel =
			(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
			(kuerzelMem && kuerzelMem.fixedLiteral) ||
			'';
		if (praefixMem && praefixMem.fixed && praefixMem.fixed.name) {
			prefix = praefixMem.fixed.name;
		} else if (praefixMem && praefixMem.fixedLiteral) {
			prefix = String(praefixMem.fixedLiteral);
		}
		if (prefix === 'Mega') {
			prefix = 'M';
		}
		if (!kuerzel) {
			return '';
		}
		return String(prefix || '') + String(kuerzel);
	}

	/**
	 * Sample prefix letter for usage demos (optional Praefix â†’ prefer milli "m" â†’ e.g. 10.5mm).
	 * Empty when Praefix is absent or has no enabled options.
	 */
	function samplePrefixLetter(praefixMem, scope) {
		if (!praefixMem) {
			return '';
		}
		var opts = enabledBranchOptions(praefixMem);
		function letterForName(name) {
			var n = String(name || '');
			if (!n) {
				return '';
			}
			var i;
			for (i = 0; i < opts.length; i++) {
				if (opts[i] && (opts[i].name === n || String(opts[i].id) === n)) {
					return formatSelectSymbolLabel(opts[i]);
				}
			}
			return formatSelectSymbolLabel({ name: n });
		}
		if (praefixMem.fixed && praefixMem.fixed.name) {
			return letterForName(praefixMem.fixed.name);
		}
		if (praefixMem.fixedLiteral) {
			return letterForName(praefixMem.fixedLiteral);
		}
		var live = scope != null ? getPreviewValue(scope, praefixMem, null) : null;
		if (live != null && String(live) !== '') {
			return letterForName(live);
		}
		if (!opts.length) {
			return '';
		}
		var pick = opts[0];
		for (var pi = 0; pi < opts.length; pi++) {
			var sym = formatSelectSymbolLabel(opts[pi]);
			if (sym === 'm' || (opts[pi] && opts[pi].name === 'Milli')) {
				pick = opts[pi];
				break;
			}
		}
		return formatSelectSymbolLabel(pick);
	}

	/** Basiseinheit unit (= set schema Typ/Praefix/Kuerzel), not a fillable instance. */
	function isUnitDefinitionNode(n) {
		return !!(n && n.isBasiseinheitUnit);
	}

	function memberTypeLabel(member) {
		if (!member) {
			return 'â€”';
		}
		var key = typeKeyFromMember(member);
		if (key === 'node_embed') {
			return 'node_embed';
		}
		if (member.type && member.type.name) {
			return String(member.type.name);
		}
		return key || 'â€”';
	}

	function renderUnitSchemaDefinition(members) {
		var wrap = el('div', { className: 'wtt-preview-schema' });
		wrap.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.previewSchema || 'Definition',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unitSchemaHint ||
					'This node defines the unit schema only â€” not an instance value.',
			})
		);
		var table = el('table', { className: 'wtt-set-preview__table wtt-preview-schema__table' });
		var thead = el('thead');
		var head = el('tr');
		[
			i18n.previewColField || 'Field',
			i18n.previewColType || 'Type',
			i18n.previewColConstraint || 'Constraint',
		].forEach(function (label) {
			head.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(head);
		table.appendChild(thead);
		var tbody = el('tbody');
		(members || []).forEach(function (m) {
			var tr = el('tr');
			var name = m.name || 'â€”';
			if (m.required) {
				name += ' *';
			}
			tr.appendChild(el('td', { text: name }));
			tr.appendChild(el('td', { text: memberTypeLabel(m) }));
			var constraint = 'â€”';
			var memKey = memberNameKey(m);
			if (m.fixed && m.fixed.name) {
				constraint =
					(i18n.previewFixed || 'fixed') +
					': ' +
					formatSelectLabel(m.fixed);
			} else if (m.fixedLiteral != null && String(m.fixedLiteral) !== '') {
				/*
				 * Kuerzel uses a fixed symbol literal (Meter â†’ "m"). That is NOT
				 * the Praefix catalog node "m" (Milli) â€” same letter, different role.
				 */
				if (memKey === 'kuerzel') {
					constraint =
						(i18n.previewFixedSymbol || 'fixed symbol') +
						': ' +
						String(m.fixedLiteral);
				} else {
					constraint =
						(i18n.previewFixed || 'fixed') + ': ' + String(m.fixedLiteral);
				}
			} else if (memKey === 'praefix') {
				constraint = i18n.previewOptionalPrefix || 'optional (allowlist)';
			}
			tr.appendChild(el('td', { text: constraint }));
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Conversion table for a Basiseinheit unit (enabled prefixes Ã— root factor â†’ SI).
	 */
	function renderUnitConversions(n, members) {
		var branch =
			(n && n.prefixBranch && n.prefixBranch.unitAllowlistEdit && n.prefixBranch) ||
			null;
		if (!branch) {
			var praefixMem = findSetMemberByKey(members, 'praefix');
			if (praefixMem && praefixMem.typeBranch && praefixMem.typeBranch.unitAllowlistEdit) {
				branch = praefixMem.typeBranch;
			}
		}
		var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
		var kuerzel =
			(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
			(kuerzelMem && kuerzelMem.fixedLiteral) ||
			'';
		if (!kuerzel) {
			return null;
		}

		var rootToSi =
			branch && branch.unitPrefixRootToSi != null && isFinite(Number(branch.unitPrefixRootToSi))
				? Number(branch.unitPrefixRootToSi)
				: n && n.prefixRootToSi != null && isFinite(Number(n.prefixRootToSi))
					? Number(n.prefixRootToSi)
					: 1;
		var siSymbol = rootToSi === 1 ? kuerzel : n.name || 'SI';

		var wrap = el('div', { className: 'wtt-unit-conversions' });
		wrap.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.unitConversions || 'Conversions',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unitConversionsHint ||
					'to_si = Typ Ã— multiplikator Ã— prefix_root_to_si.',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-unit-conversions__root',
				text:
					(i18n.prefixRootToSi || 'Unit: prefix root â†’ SI base') +
					': ' +
					formatFactor(rootToSi),
			})
		);

		var table = el('table', {
			className: 'wtt-set-preview__table wtt-unit-conversions__table',
		});
		var thead = el('thead');
		var head = el('tr');
		[
			i18n.unitConvPrefix || 'Praefix',
			i18n.unitConvSymbol || 'Symbol',
			i18n.unitConvFactor || 'Ã— factor',
			i18n.unitConvToSi || '1 â†’ SI',
			i18n.unitConvSample || '10.5 â†’ SI',
		].forEach(function (label) {
			head.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(head);
		table.appendChild(thead);

		var tbody = el('tbody');
		function addRow(prefixName, symbol, factor) {
			var tr = el('tr');
			tr.appendChild(el('td', { text: prefixName }));
			tr.appendChild(el('td', { text: symbol }));
			tr.appendChild(el('td', { text: formatFactor(factor) }));
			tr.appendChild(
				el('td', {
					text: formatFactor(1 * factor * rootToSi) + ' ' + siSymbol,
				})
			);
			tr.appendChild(
				el('td', {
					text: formatFactor(10.5 * factor * rootToSi) + ' ' + siSymbol,
				})
			);
			tbody.appendChild(tr);
		}

		addRow(i18n.unitConvNone || '—', kuerzel, 1);

		if (branch && Array.isArray(branch.children)) {
			branch.children.forEach(function (child) {
				if (!child || !child.enabled) {
					return;
				}
				var factor =
					child.multiplikator != null && isFinite(Number(child.multiplikator))
						? Number(child.multiplikator)
						: null;
				if (factor == null || factor <= 0) {
					return;
				}
				var prefixLetter = child.name === 'Mega' ? 'M' : String(child.name || '');
				addRow(String(child.name || ''), prefixLetter + kuerzel, factor);
			});
		}

		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Usage sample for a unit definition â€” same quantity field view as any unit-typed slot.
	 */
	function renderUnitUsageForm(members, nodeName, mode) {
		var field = asPreviewField({
			name: nodeName || '',
			isBasiseinheitUnit: true,
			setMembers: members,
		});
		return renderSetFormPreview([field], { mode: mode });
	}

	function renderUnitUsageTable(members, fieldLabel, mode) {
		var field = asPreviewField({
			name: fieldLabel || '',
			isBasiseinheitUnit: true,
			setMembers: members,
		});
		return renderGenericFieldTablePreview([field], fieldLabel || '', mode);
	}

	function scalarMemberFromNode(n) {
		return {
			id: n.id,
			name: n.name || '',
			displayName: n.name || '',
			description: n.description || '',
			helpChildren: n.helpChildren || [],
			type: n.type || null,
			required: !!n.required,
			readonly: !!n.readonly || (!!n.isAttributeSlot && !!n.fixedEnabled),
			fixedEnabled: !!n.fixedEnabled,
			fixedLiteral: n.fixedLiteral || '',
			fixed: n.fixed || null,
			fixedNodeId: n.fixedNodeId || 0,
			refScopeId: n.refScopeId || 0,
			allowedRefIds: Array.isArray(n.allowedRefIds) ? n.allowedRefIds.slice() : [],
			subtreeOptions: n.subtreeOptions || null,
			nodeRefOptions: n.nodeRefOptions || null,
			typeBranch: n.typeBranch || null,
			quantitySchema: n.quantitySchema || null,
		};
	}

	function collectColumnsFromTree(nodes, parentId) {
		var found = [];
		(nodes || []).forEach(function (node) {
			if (node.id === parentId) {
				(node.children || []).forEach(function (child) {
					found.push(child);
				});
			} else if (node.children && node.children.length) {
				found = found.concat(collectColumnsFromTree(node.children, parentId));
			}
		});
		return found;
	}

	function findTypePropByKey(props, key) {
		key = String(key || '').toLowerCase();
		props = props || [];
		for (var i = 0; i < props.length; i++) {
			var p = props[i];
			if (!p) {
				continue;
			}
			if (String(p.key || '').toLowerCase() === key || String(p.id || '').toLowerCase() === key) {
				return p;
			}
		}
		return null;
	}

	function boundPropChildId(n, key) {
		var props = (n && n.effectiveTypeProps) || (n && n.typeProps) || [];
		var prop = findTypePropByKey(props, key);
		if (!prop) {
			return 0;
		}
		var bindings = (n && n.propBindings) || {};
		return (
			parseInt(bindings[prop.id], 10) ||
			parseInt(bindings[prop.key], 10) ||
			0
		);
	}

	/** Columns = direct children of a bound child node (Zeile / Kopf / Fuss). */
	function columnsFromBoundChild(childId) {
		childId = parseInt(childId, 10) || 0;
		if (childId <= 0) {
			return [];
		}
		var node = findNodeInTree(state.tree, childId);
		if (!node) {
			return [];
		}
		var kids = node.children || [];
		if (!kids.length) {
			return [
				{
					id: node.id,
					name: node.name || 'â€”',
					type: node.type || (node.typeLabel ? { name: node.typeLabel } : { name: 'text' }),
					typeLabel: node.typeLabel || '',
					required: !!node.required,
				},
			];
		}
		return kids.map(function (col) {
			return {
				id: col.id,
				name: col.name || 'â€”',
				type: col.type || (col.typeLabel ? { name: col.typeLabel } : { name: 'text' }),
				typeLabel: col.typeLabel || '',
				required: !!col.required,
				fixed: col.fixed || null,
				fixedLiteral: col.fixedLiteral || '',
				fixedNodeId: col.fixedNodeId || 0,
				refScopeId: col.refScopeId || 0,
				typeBranch: col.typeBranch || null,
			};
		});
	}

	/**
	 * Instance table preview columns from type-prop bindings.
	 * Header labels: Kopf children if set, else Zeile children.
	 */
	function getTableColumnsFromBands(n) {
		var validation = resolveTableValidation(n);
		var bands = (validation && validation.bands) || null;
		if (!bands || !bands.zeile || !bands.zeile.fields || !bands.zeile.fields.length) {
			return null;
		}
		var headers =
			bands.kopf && bands.kopf.fields && bands.kopf.fields.length
				? bands.kopf.fields
				: null;
		return bands.zeile.fields.map(function (col, i) {
			var head = headers && headers[i] ? headers[i].name : col.name;
			var live = findNodeInTree(state.tree, col.id) || {};
			return {
				id: col.id,
				name: head || col.name || 'â€”',
				bodyName: col.name,
				type: live.type || (live.typeLabel ? { name: live.typeLabel } : { name: 'text' }),
				typeLabel: live.typeLabel || '',
				required: !!live.required,
			};
		});
	}

	function getTableColumnsFromBindings(n) {
		var fromBands = getTableColumnsFromBands(n);
		if (fromBands && fromBands.length) {
			return fromBands;
		}
		var zeileId = boundPropChildId(n, 'zeile');
		var kopfId = boundPropChildId(n, 'kopf');
		var body = columnsFromBoundChild(zeileId);
		if (!body.length) {
			return null;
		}
		var headers = kopfId ? columnsFromBoundChild(kopfId) : null;
		return body.map(function (col, i) {
			var head = headers && headers[i] ? headers[i].name : col.name;
			return Object.assign({}, col, {
				name: head || col.name,
				bodyName: col.name,
			});
		});
	}

	function getPreviewMembers(n) {
		if (
			n.isBasiseinheitUnit &&
			n.quantitySchema &&
			Array.isArray(n.quantitySchema.members) &&
			n.quantitySchema.members.length
		) {
			return n.quantitySchema.members;
		}
		if (n.isBasiseinheitUnit && n.setMembers && n.setMembers.length) {
			return n.setMembers;
		}
		if (n.isSet && n.setMembers && n.setMembers.length) {
			return n.setMembers;
		}
		if (n.isTableTypeCatalog) {
			return [
				{
					name: (i18n.previewColGeneric || 'Column') + ' A',
					type: { name: 'text' },
				},
				{
					name: (i18n.previewColGeneric || 'Column') + ' B',
					type: { name: 'text' },
				},
				{
					name: (i18n.previewColGeneric || 'Column') + ' C',
					type: { name: 'text' },
				},
			];
		}
		if (n.isTable) {
			var boundCols = getTableColumnsFromBindings(n);
			if (boundCols && boundCols.length) {
				return boundCols;
			}
			var cols = collectColumnsFromTree(state.tree, n.id);
			if (!cols.length) {
				cols = [
					{ name: (i18n.previewColGeneric || 'Column') + ' 1' },
					{ name: (i18n.previewColGeneric || 'Column') + ' 2' },
					{ name: (i18n.previewColGeneric || 'Column') + ' 3' },
				];
			}
			return cols.map(function (col) {
				var childMembers = null;
				if (col.children && col.children.length > 1) {
					childMembers = col.children.map(function (ch) {
						return { name: ch.name || 'â€”' };
					});
				}
				return {
					id: col.id,
					name: col.name || 'â€”',
					type: col.type || (col.typeLabel ? { name: col.typeLabel } : { name: 'text' }),
					typeLabel: col.typeLabel || '',
					required: !!col.required,
					fixed: col.fixed || null,
					fixedLiteral: col.fixedLiteral || '',
					fixedNodeId: col.fixedNodeId || 0,
					refScopeId: col.refScopeId || 0,
					allowedRefIds: Array.isArray(col.allowedRefIds) ? col.allowedRefIds.slice() : [],
					typeBranch: col.typeBranch || null,
					setMembers: childMembers,
					setSeparator: '/',
					setLabelChildren: true,
				};
			});
		}
		if (n.typeId && n.type) {
			return [scalarMemberFromNode(n)];
		}
		/* Untyped: still show form/table samples as a plain text field. */
		return [
			{
				name: n.name || (i18n.previewColField || 'Field'),
				displayName: n.name || '',
				description: n.description || '',
				type: { name: 'text' },
				required: false,
				fixed: null,
				fixedLiteral: '',
				typeBranch: null,
			},
		];
	}

	function renderMultiColumnTablePreview(columns, mode, hasFooter) {
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		columns.forEach(function (col) {
			var header = col.name || 'â€”';
			var childMembers = col.setMembers || col.members || null;
			if (childMembers && childMembers.length > 1) {
				header = setFieldCaption(col.name || '', childMembers, {
					separator: col.setSeparator != null ? String(col.setSeparator) : '/',
					includeChildren: col.setLabelChildren !== false,
				});
			}
			headRow.appendChild(el('th', { text: header, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var row = el('tr');
		columns.forEach(function (col) {
			var td = el('td');
			td.appendChild(renderPreviewControl(col, { compact: true, mode: mode }));
			row.appendChild(td);
		});
		tbody.appendChild(row);
		table.appendChild(tbody);

		if (hasFooter) {
			var tfoot = el('tfoot');
			var footRow = el('tr');
			columns.forEach(function (col, c) {
				footRow.appendChild(
					el('td', {
						className: 'wtt-table-preview__footer-cell',
						text: c === 0 ? (i18n.previewFooter || 'Footer') : 'Î£ / â€”',
					})
				);
			});
			tfoot.appendChild(footRow);
			table.appendChild(tfoot);
		}

		wrap.appendChild(table);
		return wrap;
	}

	function renderPreviewVariant(title, contentNode) {
		var variant = el('div', { className: 'wtt-preview__variant' });
		variant.appendChild(
			el('h5', {
				className: 'wtt-preview__variant-title',
				text: title,
			})
		);
		variant.appendChild(contentNode);
		return variant;
	}

	function renderPreviewSurface(surfaceTitle, editNode, displayNode) {
		var section = el('div', { className: 'wtt-set-preview__section' });
		section.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: surfaceTitle,
			})
		);
		var block = el('div', { className: 'wtt-preview__block' });
		var editWrap = el('div', { className: 'wtt-preview__block-edit' });
		editWrap.appendChild(editNode);
		var displayWrap = el('div', { className: 'wtt-preview__block-display' });
		displayWrap.appendChild(displayNode);
		block.appendChild(editWrap);
		block.appendChild(displayWrap);
		section.appendChild(block);
		return section;
	}

	function nodeRenderApi() {
		return window.WTTNodeRender || null;
	}

	/**
	 * Resolve type key for a bound band field (validation payload + tree).
	 */
	function bandFieldTypeKey(f, live) {
		live = live || {};
		var fromPayload =
			(f && f.typeKey) ||
			(f && f.typeName) ||
			(f && f.typeLabel) ||
			(f && f.type && f.type.name) ||
			'';
		var fromLive =
			typeKeyFromMember(live) ||
			(live.typeLabel ? String(live.typeLabel) : '') ||
			(live.type && live.type.name ? String(live.type.name) : '') ||
			'';
		var key = String(fromPayload || fromLive || 'text')
			.trim()
			.toLowerCase();
		if (key === 'integer') {
			return 'int';
		}
		if (key === 'boolean') {
			return 'bool';
		}
		return key || 'text';
	}

	/**
	 * Map bound band fields into renderer band DTOs.
	 * Zeile cells: field-type example nodes (Int_name, â€¦) so the sample row paints.
	 * Kopf: label-only (header text from the bound child names).
	 * Fuss: footerOp + symbol sample (not scalar type examples).
	 *
	 * @param {boolean} [asExample] Use getExampleNode(type) for Zeile cell content.
	 * @param {{band?:string}} [opts]
	 */
	function mapBandFieldsForRender(fields, asExample, opts) {
		fields = Array.isArray(fields) ? fields : [];
		opts = opts || {};
		var band = String(opts.band || '');
		var api = nodeRenderApi();
		return fields.map(function (f) {
			var id = f && f.id != null ? parseInt(f.id, 10) || 0 : 0;
			var live = id > 0 ? findNodeInTree(state.tree, id) || {} : {};
			var typeKey = bandFieldTypeKey(f, live);
			var fieldName = (f && f.name) || live.name || 'â€”';

			if (band === 'fuss') {
				var rawOp =
					(f && f.footerOp) ||
					(live.fussFieldContext && live.fussFieldContext.footerOp) ||
					live.footerOp ||
					'';
				var op =
					api && typeof api.normalizeFooterOp === 'function'
						? api.normalizeFooterOp(rawOp, typeKey)
						: {
								key:
									rawOp ||
									(typeKey === 'int' || typeKey === 'double' ? 'sum' : 'text'),
								symbol: 'Î£',
						  };
				return {
					id: id,
					name: fieldName,
					displayName: live.shortDescription || fieldName,
					typeKey: typeKey,
					type: { name: typeKey },
					typeLabel: typeKey,
					footerOp: op.key,
					sample: op.symbol,
					band: 'fuss',
					isExample: true,
				};
			}

			if (asExample && api && typeof api.getExampleNode === 'function') {
				var example = api.getExampleNode(typeKey);
				if (example) {
					var enumOpts =
						(f && Array.isArray(f.enumOptions) && f.enumOptions) ||
						(Array.isArray(live.enumOptions) && live.enumOptions) ||
						example.enumOptions ||
						[];
					var refOpts =
						(f && Array.isArray(f.nodeRefOptions) && f.nodeRefOptions) ||
						(Array.isArray(live.nodeRefOptions) && live.nodeRefOptions) ||
						example.nodeRefOptions ||
						[];
					var fieldMult =
						(f && f.fieldMultiplicity) ||
						live.fieldMultiplicity ||
						example.fieldMultiplicity ||
						'0..1';
					var scopeId =
						(f && f.refScopeId) ||
						live.refScopeId ||
						example.refScopeId ||
						0;
					var sampleVal = example.sample;
					if (typeKey === 'enum' && enumOpts[0] && enumOpts[0].name) {
						sampleVal = String(enumOpts[0].name);
					} else if (typeKey === 'node_ref' && refOpts.length) {
						var m = String(fieldMult || '0..1');
						if (m === '0..*' || m === '1..*') {
							var nTake = Math.min(
								3,
								refOpts.length >= 3 ? 3 : Math.max(1, Math.min(2, refOpts.length))
							);
							sampleVal = refOpts
								.slice(0, nTake)
								.map(function (o) {
									return String(o.id);
								})
								.join(',');
						} else {
							sampleVal = String(refOpts[0].id);
						}
					}
					return Object.assign({}, example, {
						id: id,
						bandFieldName: fieldName,
						name: fieldName,
						displayName: live.shortDescription || fieldName,
						typeKey: typeKey,
						type: { name: typeKey },
						typeLabel: typeKey,
						required: !!live.required,
						isExample: true,
						enumOptions: deepClone(enumOpts),
						nodeRefOptions: deepClone(refOpts),
						fieldMultiplicity: String(fieldMult),
						refScopeId: parseInt(scopeId, 10) || 0,
						allowedRefIds: Array.isArray(f && f.allowedRefIds)
							? f.allowedRefIds.slice()
							: Array.isArray(live.allowedRefIds)
								? live.allowedRefIds.slice()
								: [],
						sample: sampleVal,
					});
				}
			}
			return {
				id: id,
				name: fieldName,
				displayName: live.shortDescription || fieldName,
				type: live.type || { name: typeKey },
				typeLabel: live.typeLabel || typeKey,
				typeKey: typeKey,
				required: !!live.required,
				sample: 'â€¦',
				fixed: live.fixed || null,
				fixedLiteral: live.fixedLiteral || '',
				quantitySchema: live.quantitySchema || null,
				typeBranch: live.typeBranch || null,
				enumOptions:
					(f && Array.isArray(f.enumOptions) && deepClone(f.enumOptions)) ||
					(Array.isArray(live.enumOptions) && deepClone(live.enumOptions)) ||
					[],
				nodeRefOptions:
					(f && Array.isArray(f.nodeRefOptions) && deepClone(f.nodeRefOptions)) ||
					(Array.isArray(live.nodeRefOptions) && deepClone(live.nodeRefOptions)) ||
					[],
				fieldMultiplicity:
					(f && f.fieldMultiplicity) || live.fieldMultiplicity || '0..1',
				refScopeId: (f && f.refScopeId) || live.refScopeId || 0,
				allowedRefIds: Array.isArray(f && f.allowedRefIds)
					? f.allowedRefIds.slice()
					: Array.isArray(live.allowedRefIds)
						? live.allowedRefIds.slice()
						: [],
			};
		});
	}

	/**
	 * Instance table: Kopf labels + Zeile/Fuss from bindings; Zeile cells = type examples.
	 */
	function buildLiveTablePreviewNode(n) {
		var validation = resolveTableValidation(n);
		var bands = validation && validation.bands ? validation.bands : null;
		var zeileSrc = bands && bands.zeile && bands.zeile.fields;
		if (!zeileSrc || !zeileSrc.length) {
			var cols = getPreviewMembers(n) || [];
			return Object.assign({}, n, {
				bands: null,
				setMembers: cols,
				hasFooter: false,
				_liveTable: true,
			});
		}
		var zeile = mapBandFieldsForRender(zeileSrc, true, { band: 'zeile' });
		var kopf =
			bands.kopf && bands.kopf.fields
				? mapBandFieldsForRender(bands.kopf.fields, false, { band: 'kopf' })
				: [];
		var fuss =
			bands.fuss && bands.fuss.fields
				? mapBandFieldsForRender(bands.fuss.fields, false, { band: 'fuss' })
				: [];
		return Object.assign({}, n, {
			bands: { kopf: kopf, zeile: zeile, fuss: fuss },
			setMembers: zeile,
			hasFooter: fuss.length > 0,
			_liveTable: true,
		});
	}

	/**
	 * Catalog type leaves → example DTO. Table instances → live bound bands.
	 * Keep live presentation meta (converter, validators, sample, …) so draft
	 * Preferred converter / similar settings paint immediately in preview.
	 */
	function resolvePreviewRenderNode(n) {
		if (n && n.isTable && !n.isTableTypeCatalog) {
			return buildLiveTablePreviewNode(n);
		}
		var api = nodeRenderApi();
		if (!api || typeof api.getExampleNode !== 'function') {
			return n;
		}
		var key = resolveNodeRenderTypeKey(n);
		if (!key || !usesRegistryPreview(n)) {
			return n;
		}
		var example = api.getExampleNode(key);
		if (!example) {
			example = api.getExampleNode(n);
		}
		if (!example) {
			return n;
		}
		var live = n || {};
		var merged = Object.assign({}, example, {
			id: live.id != null ? live.id : example.id,
			name: live.name != null && String(live.name) !== '' ? live.name : example.name,
			displayName:
				live.displayName != null && String(live.displayName) !== ''
					? live.displayName
					: live.name != null && String(live.name) !== ''
						? live.name
						: example.displayName,
			_exampleFrom: key,
			preferredConverter:
				live.preferredConverter != null
					? live.preferredConverter
					: example.preferredConverter,
			displayFormat:
				live.displayFormat != null
					? live.displayFormat
					: example.displayFormat,
			intDisplayFormat:
				live.intDisplayFormat != null
					? live.intDisplayFormat
					: example.intDisplayFormat,
			intConfig: live.intConfig || example.intConfig || null,
			typeExtras: live.typeExtras || example.typeExtras || null,
			validators: live.validators != null ? live.validators : example.validators,
			preferredRender:
				live.preferredRender != null
					? live.preferredRender
					: example.preferredRender,
			mediaConfig:
				(state.draft && state.draft.mediaConfig) ||
				live.mediaConfig ||
				example.mediaConfig ||
				null,
		});
		if (live.sample != null && String(live.sample) !== '') {
			merged.sample = live.sample;
		}
		return merged;
	}

	/**
	 * True when key is a Registry field renderer id (int, bool, media, …).
	 * Used so type-catalog leaves win over Q88 parent branch types (Simple).
	 */
	function isRegistryRendererKey(key) {
		key = String(key || '')
			.trim()
			.toLowerCase();
		if (!key) {
			return false;
		}
		var NR = window.WTTNodeRender;
		if (NR && NR.Registry && typeof NR.Registry.getById === 'function') {
			return !!NR.Registry.getById(key);
		}
		if (NR && typeof NR.isRegisteredType === 'function') {
			return !!NR.isRegisteredType(key);
		}
		return /^(int|char|double|text|textarea|bool|email|date|table|media|quantity|node_presentation|display_node_name|node_ref|node_embed)$/.test(
			key
		);
	}

	/**
	 * Q96: term id → Registry id via catalog `builtin.*` bindings.
	 * @param {number|string} termId
	 * @return {string}
	 */
	function registryIdFromCatalogBindings(termId) {
		var id = parseInt(termId, 10) || 0;
		if (id <= 0) {
			return '';
		}
		var bindings = (cfg && cfg.catalogBindings) || {};
		var prefix = 'builtin.';
		var key;
		for (key in bindings) {
			if (
				!Object.prototype.hasOwnProperty.call(bindings, key) ||
				key.indexOf(prefix) !== 0
			) {
				continue;
			}
			if ((parseInt(bindings[key], 10) || 0) === id) {
				return String(key.slice(prefix.length)).toLowerCase();
			}
		}
		return '';
	}

	function resolveNodeRenderTypeKey(node) {
		if (node && (node.isTableTypeCatalog || node.isTable)) {
			return 'table';
		}
		/* Q96: prefer typeId / self id binding over leaf name. */
		var typeId =
			node && node.typeId != null
				? node.typeId
				: node && node.type && node.type.id != null
					? node.type.id
					: 0;
		var fromBind = registryIdFromCatalogBindings(typeId);
		if (fromBind) {
			return fromBind;
		}
		fromBind = registryIdFromCatalogBindings(node && node.id);
		if (fromBind) {
			return fromBind;
		}
		var name = String((node && node.name) || '')
			.trim()
			.toLowerCase();
		var Sample = window.WTTSampleData;
		if (Sample && typeof Sample.resolveTypeKey === 'function' && name) {
			var resolvedName = Sample.resolveTypeKey(name);
			if (resolvedName) {
				name = resolvedName;
			}
		}
		if (name === 'integer') {
			name = 'int';
		}
		if (name === 'boolean') {
			name = 'bool';
		}
		if (name === 'float' || name === 'number') {
			name = 'double';
		}
		var fromType = typeKeyFromMember(node);
		/*
		 * Debt: type-catalog leaf name (Data Types → Simple → int). Q88 may set
		 * typeId/type to parent branch ("Simple"). Prefer leaf name when it is a
		 * Registry renderer and the inherited type is not.
		 */
		if (
			isRegistryRendererKey(name) &&
			(!fromType || fromType === name || !isRegistryRendererKey(fromType))
		) {
			return name;
		}
		if (fromType && fromType !== 'text') {
			return fromType;
		}
		if (isRegistryRendererKey(name)) {
			return name;
		}
		if (node && !node.typeId && name) {
			return name;
		}
		return fromType || name || '';
	}

	function usesRegistryPreview(n) {
		if (isQuantityTypeCatalogNode(n)) {
			return false;
		}
		var api = nodeRenderApi();
		if (!api || !api.Registry) {
			return false;
		}
		var key = resolveNodeRenderTypeKey(n);
		if (api.isRegisteredType) {
			return api.isRegisteredType(key);
		}
		if (api.isSimpleScalarType && api.isSimpleScalarType(key)) {
			return true;
		}
		if (api.isStructuredType && api.isStructuredType(key)) {
			return true;
		}
		return (
			key === 'int' ||
			key === 'char' ||
			key === 'double' ||
			key === 'text' ||
			key === 'textarea' ||
			key === 'bool' ||
			key === 'email' ||
			key === 'date' ||
			key === 'quantity' ||
			key === 'unit' ||
			key === 'table'
		);
	}

	function makeNodeRenderContext(n, contextName, mode, extra) {
		var scope = previewMemberScope(n);
		var sample = previewSampleText(n);
		var valueKey = previewValueKey(scope, n) + '|' + String(contextName || 'form');
		var ctx = {
			name: contextName,
			mode: mode === 'display' ? 'display' : 'edit',
			value: String(getPreviewValue(scope, n, sample)),
			valueKey: valueKey,
			bare: true,
			onInput:
				mode === 'display'
					? null
					: function (next) {
							var active = document.activeElement;
							if (
								active &&
								active.getAttribute &&
								active.getAttribute('data-wtt-pv') === valueKey
							) {
								rememberPreviewFocus(active, valueKey);
							}
							setPreviewValue(scope, n, next);
					  },
		};
		if (extra && typeof extra === 'object') {
			Object.keys(extra).forEach(function (k) {
				ctx[k] = extra[k];
			});
		}
		return ctx;
	}

	function renderViaRegistry(n, contextName, mode, extra) {
		var api = nodeRenderApi();
		if (!api || !api.Registry) {
			return el('span', { text: 'â€”' });
		}
		var paint = resolvePreviewRenderNode(n);
		var out = api.Registry.render(
			paint,
			makeNodeRenderContext(paint, contextName, mode, extra)
		);
		return out || el('span', { className: 'wtt-field-hint', text: 'â€”' });
	}

	function renderViaRegistryLabel(n, contextName, mode, extra) {
		var api = nodeRenderApi();
		if (!api || !api.Registry || typeof api.Registry.renderLabel !== 'function') {
			return el('span', {
				className: 'wtt-node-render__label',
				text: (n && (n.displayName || n.name)) || 'â€”',
			});
		}
		var paint = resolvePreviewRenderNode(n);
		var out = api.Registry.renderLabel(
			paint,
			makeNodeRenderContext(paint, contextName, mode, extra)
		);
		return (
			out ||
			el('span', {
				className: 'wtt-node-render__label',
				text: (paint && (paint.displayName || paint.name)) || 'â€”',
			})
		);
	}

	function renderViaRegistryContent(n, contextName, mode, extra) {
		var api = nodeRenderApi();
		if (!api || !api.Registry || typeof api.Registry.renderContent !== 'function') {
			return el('span', { text: 'â€”' });
		}
		var paint = resolvePreviewRenderNode(n);
		var readonly = mode === 'display';
		var out = api.Registry.renderContent(
			paint,
			makeNodeRenderContext(
				paint,
				contextName,
				mode,
				Object.assign({ bare: true }, extra || {})
			),
			readonly
		);
		return out || el('span', { className: 'wtt-field-hint', text: 'â€”' });
	}

	/**
	 * Tree chrome: three-node branch; current field shows edit + display side by side.
	 */
	function renderRegistryTreeChrome(n) {
		var labelNode = renderViaRegistryLabel(n, 'tree', 'edit');
		var fieldEdit = renderViaRegistryContent(n, 'tree', 'edit');
		var fieldDisplay = renderViaRegistryContent(n, 'tree', 'display');
		var list = el('ul', { className: 'wtt-nr-tree' });
		function leaf(labelElOrText, body, isCurrent) {
			var li = el('li', {
				className:
					'wtt-nr-tree__node' + (isCurrent ? ' is-current' : ''),
			});
			var row = el('div', { className: 'wtt-nr-tree__row' });
			row.appendChild(
				el('span', {
					className: 'wtt-nr-tree__twist',
					html: '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>',
				})
			);
			if (typeof labelElOrText === 'string') {
				row.appendChild(
					el('span', {
						className: 'wtt-nr-tree__name',
						text: labelElOrText,
					})
				);
			} else if (labelElOrText) {
				labelElOrText.classList.add('wtt-nr-tree__name');
				row.appendChild(labelElOrText);
			}
			if (body) {
				row.appendChild(
					el('div', { className: 'wtt-nr-tree__field' }, [body])
				);
			}
			li.appendChild(row);
			return li;
		}
		var pair = el('div', { className: 'wtt-nr-pair' }, [
			el('div', { className: 'wtt-nr-pair__edit' }, [fieldEdit]),
			el('div', { className: 'wtt-nr-pair__display' }, [fieldDisplay]),
		]);
		list.appendChild(
			leaf(i18n.nrTreeSiblingBefore || 'Sample A', null, false)
		);
		list.appendChild(leaf(labelNode, pair, true));
		list.appendChild(
			leaf(i18n.nrTreeSiblingAfter || 'Sample C', null, false)
		);
		return el('div', { className: 'wtt-nr-chrome wtt-nr-chrome--tree' }, [
			list,
		]);
	}

	/**
	 * Form chrome: two columns (label | value), stacked rows.
	 * Rows: before, field edit, field display, after.
	 */
	function renderRegistryFormChrome(n) {
		var fieldEdit = renderViaRegistryContent(n, 'form', 'edit');
		var fieldDisplay = renderViaRegistryContent(n, 'form', 'display');
		var grid = el('div', { className: 'wtt-nr-form' });

		function row(labelPart, control, opts) {
			opts = opts || {};
			var r = el('div', {
				className:
					'wtt-nr-form__row' +
					(opts.isCurrent ? ' is-current' : '') +
					(opts.modeClass ? ' ' + opts.modeClass : ''),
			});
			var labelCell = el('div', { className: 'wtt-nr-form__label' });
			if (typeof labelPart === 'string') {
				labelCell.textContent = labelPart;
			} else if (labelPart) {
				labelCell.appendChild(labelPart);
			} else {
				labelCell.textContent = 'â€”';
			}
			if (opts.modeLabel) {
				labelCell.appendChild(
					el('span', {
						className: 'wtt-nr-mode-tag',
						text: opts.modeLabel,
					})
				);
			}
			r.appendChild(labelCell);
			var cell = el('div', {
				className:
					'wtt-nr-form__control' +
					(opts.controlClass ? ' ' + opts.controlClass : ''),
			});
			if (control) {
				cell.appendChild(control);
			} else {
				cell.appendChild(
					el('span', {
						className: 'wtt-nr-form__placeholder',
						text: 'â€”',
					})
				);
			}
			r.appendChild(cell);
			return r;
		}

		function fieldLabel(modeLabel) {
			var wrap = el('div', { className: 'wtt-nr-form__label-inner' });
			wrap.appendChild(renderViaRegistryLabel(n, 'form', 'edit'));
			wrap.appendChild(
				el('span', {
					className: 'wtt-nr-mode-tag',
					text: modeLabel,
				})
			);
			return wrap;
		}

		grid.appendChild(
			row(
				i18n.nrFormRowBefore || 'Name',
				el('input', {
					type: 'text',
					className: 'wtt-preview-input',
					value: i18n.previewSampleText || 'Sample',
					disabled: true,
				}),
				{ controlClass: 'wtt-nr-form__control--before' }
			)
		);
		grid.appendChild(
			row(fieldLabel(i18n.previewEditable || 'Editable'), fieldEdit, {
				isCurrent: true,
				modeClass: 'wtt-nr-form__row--edit',
				controlClass: 'wtt-nr-form__control--edit',
			})
		);
		grid.appendChild(
			row(
				fieldLabel(i18n.previewDisplayOnly || 'Display only'),
				fieldDisplay,
				{
					isCurrent: true,
					modeClass: 'wtt-nr-form__row--display',
					controlClass: 'wtt-nr-form__control--display',
				}
			)
		);
		grid.appendChild(
			row(
				i18n.nrFormRowAfter || 'Notes',
				el('input', {
					type: 'text',
					className: 'wtt-preview-input',
					value: i18n.previewSampleText || 'Sample',
					disabled: true,
				}),
				{ controlClass: 'wtt-nr-form__control--after' }
			)
		);

		return el('div', { className: 'wtt-nr-chrome wtt-nr-chrome--form' }, [
			grid,
		]);
	}

	/**
	 * Table chrome: Column A | edit | display | Column B (one shared row).
	 */
	function renderRegistryTableChrome(n) {
		var fieldEdit = renderViaRegistryContent(n, 'table', 'edit');
		var fieldDisplay = renderViaRegistryContent(n, 'table', 'display');
		var wrap = el('div', {
			className:
				'wtt-nr-chrome wtt-nr-chrome--table wtt-set-preview__table-wrap',
		});
		var table = el('table', {
			className: 'wtt-nr-table wtt-set-preview__table',
		});
		var thead = el('thead');
		var head = el('tr');
		head.appendChild(el('th', { text: '#', scope: 'col' }));
		head.appendChild(
			el('th', {
				text: i18n.nrTableColA || 'Column A',
				scope: 'col',
			})
		);

		function fieldTh(modeClass, modeLabel) {
			var th = el('th', {
				scope: 'col',
				className: 'is-current ' + modeClass,
			});
			var inner = el('div', { className: 'wtt-nr-table__th-inner' });
			inner.appendChild(renderViaRegistryLabel(n, 'table', 'edit'));
			inner.appendChild(
				el('span', {
					className: 'wtt-nr-mode-tag',
					text: modeLabel,
				})
			);
			th.appendChild(inner);
			return th;
		}

		head.appendChild(
			fieldTh(
				'wtt-nr-table__th--edit',
				i18n.previewEditable || 'Editable'
			)
		);
		head.appendChild(
			fieldTh(
				'wtt-nr-table__th--display',
				i18n.previewDisplayOnly || 'Display only'
			)
		);
		head.appendChild(
			el('th', {
				text: i18n.nrTableColB || 'Column B',
				scope: 'col',
			})
		);
		thead.appendChild(head);
		table.appendChild(thead);
		var tbody = el('tbody');
		var tr = el('tr');
		tr.appendChild(el('td', { text: '1' }));
		tr.appendChild(el('td', { text: i18n.nrTableSampleA || 'â€¦' }));
		var tdEdit = el('td', {
			className: 'wtt-nr-table__field is-current wtt-nr-table__field--edit',
		});
		tdEdit.appendChild(fieldEdit);
		tr.appendChild(tdEdit);
		var tdDisplay = el('td', {
			className:
				'wtt-nr-table__field is-current wtt-nr-table__field--display',
		});
		tdDisplay.appendChild(fieldDisplay);
		tr.appendChild(tdDisplay);
		tr.appendChild(el('td', { text: i18n.nrTableSampleB || 'â€¦' }));
		tbody.appendChild(tr);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Table-type chrome: only the table context (no tree/form).
	 * Edit + display skeletons side by side vertically.
	 */
	function renderRegistryTableTypeChrome(n) {
		var paint = resolvePreviewRenderNode(n);
		var columns = getPreviewMembers(n);
		var extra = {
			columns: columns,
			bands: paint && paint.bands ? paint.bands : null,
			hasFooter: !!(
				paint &&
				paint.bands &&
				paint.bands.fuss &&
				paint.bands.fuss.length
			),
		};
		var editTable = renderViaRegistryContent(n, 'table', 'edit', extra);
		var displayTable = renderViaRegistryContent(
			n,
			'table',
			'display',
			extra
		);
		var pair = el('div', { className: 'wtt-nr-table-type' });
		pair.appendChild(
			el('div', { className: 'wtt-nr-table-type__edit' }, [
				el('div', {
					className: 'wtt-nr-mode-tag',
					text: i18n.previewEditable || 'Editable',
				}),
				editTable,
			])
		);
		pair.appendChild(
			el('div', { className: 'wtt-nr-table-type__display' }, [
				el('div', {
					className: 'wtt-nr-mode-tag',
					text: i18n.previewDisplayOnly || 'Display only',
				}),
				displayTable,
			])
		);
		return el('div', { className: 'wtt-nr-chrome wtt-nr-chrome--table-type' }, [
			pair,
		]);
	}

	function renderRegistryPreviewSection(surfaceTitle, chromeNode) {
		var section = el('div', { className: 'wtt-set-preview__section' });
		section.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: surfaceTitle,
			})
		);
		var block = el('div', {
			className: 'wtt-preview__block wtt-preview__block--merged',
		});
		block.appendChild(chromeNode);
		section.appendChild(block);
		return section;
	}

	/**
	 * Preview for types with registered NodeRenderers.
	 * Scalars: tree + form + table chrome.
	 * Table type: table context only.
	 */
	function resolveTableValidation(n) {
		if (
			window.WTTTableValidator &&
			typeof window.WTTTableValidator.validate === 'function'
		) {
			return window.WTTTableValidator.validate(n, state.tree || []);
		}
		return n.tableValidation || null;
	}

	function treeNodeHasTableRuleError(node) {
		if (!node) {
			return false;
		}
		var id = parseInt(node.id, 10) || 0;
		if (id > 0 && sameTermId(state.selectedId, id) && state.selectedNode) {
			var live = resolveTableValidation(viewNode());
			return !!(live && !live.ok);
		}
		return !!node.tableInvalid;
	}

	function treeNodeTableErrorHint(node) {
		if (!node) {
			return '';
		}
		var id = parseInt(node.id, 10) || 0;
		if (id > 0 && sameTermId(state.selectedId, id) && state.selectedNode) {
			var live = resolveTableValidation(viewNode());
			if (live && Array.isArray(live.errors) && live.errors.length) {
				return live.errors.join(' ');
			}
		}
		return String(node.tableErrorHint || '');
	}

	function resolveAttributeValidation(n) {
		if (n && n.attributeValidation) {
			return n.attributeValidation;
		}
		var attrs = n && Array.isArray(n.attributes) ? n.attributes : [];
		var errors = [];
		var fixes = [];
		attrs.forEach(function (attr) {
			var attrId = wttAttrId(attr);
			var attrName = String(attr.name || '') || '#' + String(attrId);
			if (!attrId) {
				return;
			}
			if (attributeRowReadonlyNeedsDefault(attr)) {
				errors.push(
					(
						i18n.attributesReadonlyNeedsDefaultError ||
						'“%s” is read-only but has no default value.'
					).replace('%s', attrName)
				);
				fixes.push({
					rule: 'readonly_needs_default',
					action: 'clear_readonly',
					attrId: attrId,
					attrName: attrName,
				});
				fixes.push({
					rule: 'readonly_needs_default',
					action: 'set_default',
					attrId: attrId,
					attrName: attrName,
					needsUi: true,
				});
			}
			if (attributeRowBackgroundOnlyBadMult(attr)) {
				errors.push(
					(
						i18n.attributesBackgroundOnlyMultError ||
						'“%s” is Background-only (Hide) but multiplicity is not 0..1 or 1.'
					).replace('%s', attrName)
				);
				fixes.push({
					rule: 'background_only_needs_mult',
					action: 'set_mult_01',
					attrId: attrId,
					attrName: attrName,
				});
				fixes.push({
					rule: 'background_only_needs_mult',
					action: 'clear_hide',
					attrId: attrId,
					attrName: attrName,
				});
			}
		});
		return {
			ok: errors.length === 0,
			blocking: errors.length > 0,
			errors: errors,
			warnings: [],
			fixes: fixes,
		};
	}

	function attributeRowHasDefaultValue(attr) {
		var vals = attr && Array.isArray(attr.fixedValues) ? attr.fixedValues : [];
		if (!vals.length) {
			return false;
		}
		for (var i = 0; i < vals.length; i++) {
			var v = vals[i];
			if (v && typeof v === 'object') {
				if (Object.keys(v).length) {
					return true;
				}
				continue;
			}
			if (typeof v === 'number' || typeof v === 'boolean') {
				return true;
			}
			if (String(v == null ? '' : v).trim() !== '') {
				return true;
			}
		}
		return false;
	}

	function attributeRowReadonlyNeedsDefault(attr) {
		if (!attr || !attr.readonly) {
			return false;
		}
		if (attr.computed || (attr.compute && attr.compute.op)) {
			return false;
		}
		/* node_presentation = live host presentation; Festwert unused — no RO default required. */
		var typeKey = String(attr.typeKey || attr.typeName || '')
			.trim()
			.toLowerCase();
		if (isNodePresentationTypeKey(typeKey)) {
			return false;
		}
		return !attributeRowHasDefaultValue(attr);
	}

	function attributeRowBackgroundOnlyBadMult(attr) {
		if (!attr || attr.inherited || !attr.hidden) {
			return false;
		}
		var mult = String(attr.multiplicity || '0..*');
		return mult !== '0..1' && mult !== '1';
	}

	function attributeValidationBannerTitle(validation) {
		var fixes =
			validation && Array.isArray(validation.fixes) ? validation.fixes : [];
		var hasRo = false;
		var hasBo = false;
		fixes.forEach(function (fix) {
			var rule = String((fix && fix.rule) || '');
			if (rule === 'readonly_needs_default') {
				hasRo = true;
			}
			if (rule === 'background_only_needs_mult') {
				hasBo = true;
			}
		});
		if (hasRo && hasBo) {
			return (
				i18n.attributesValidationBanner ||
				'Attribute rules need attention.'
			);
		}
		if (hasBo) {
			return (
				i18n.attributesBackgroundOnlyMultBanner ||
				'Background-only (Hide) requires multiplicity 0..1 or 1.'
			);
		}
		return (
			i18n.attributesReadonlyNeedsDefaultBanner ||
			'Read-only attributes need a default value.'
		);
	}

	function attributeFixButtonLabel(fix) {
		var action = String((fix && fix.action) || '');
		var name = String((fix && fix.attrName) || '');
		var label;
		if (action === 'clear_readonly') {
			label = i18n.attributesFixClearReadonly || 'Clear read-only';
		} else if (action === 'set_mult_01') {
			label =
				i18n.attributesFixSetMult01 || 'Set multiplicity to 0..1';
		} else if (action === 'clear_hide') {
			label =
				i18n.attributesFixClearHide ||
				'Clear Hide (Background-only)';
		} else {
			label = i18n.attributesFixSetDefault || 'Set default value';
		}
		return label + (name ? ' — ' + name : '');
	}

	function fixAttributeRule(n, fix) {
		fix = fix || {};
		var hostId = n && n.id != null ? parseInt(n.id, 10) || 0 : 0;
		var attrId = wttAttrId(fix.attrId);
		var action = String(fix.action || '');
		if (hostId <= 0 || !attrId) {
			return;
		}

		if (action === 'set_default' || fix.needsUi) {
			var attrs = Array.isArray(n.attributes) ? n.attributes : [];
			var attr = null;
			attrs.forEach(function (row) {
				if (row && wttAttrId(row) === attrId) {
					attr = row;
				}
			});
			if (!attr) {
				setError(i18n.error);
				return;
			}
			openAttributeFixedValueDialog(n, attr, function () {});
			return;
		}

		post('wtt_fix_attribute_rule', {
			term_id: hostId,
			attr_id: attrId,
			fix_action: action,
		})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || state.tree;
				if (json.data.node) {
					applyLoadedNode(json.data.node);
					mergeNodeTypeIntoTree(state.tree, json.data.node);
				}
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function renderAttributeValidationBanner(validation, n) {
		var wrap = el('div', {
			className: 'wtt-rule-banner wtt-rule-banner--attributes',
			role: 'alert',
		});
		var head = el('div', { className: 'wtt-rule-banner__head' });
		head.appendChild(
			el('span', {
				className: 'wtt-rule-banner__mark',
				text: '!',
				'aria-hidden': 'true',
			})
		);
		head.appendChild(
			el('strong', {
				className: 'wtt-rule-banner__title',
				text: attributeValidationBannerTitle(validation),
			})
		);
		wrap.appendChild(head);

		var msgs = (validation && validation.errors) || [];
		if (msgs.length === 1) {
			wrap.appendChild(
				el('p', {
					className: 'wtt-rule-banner__msg',
					text: String(msgs[0] || ''),
				})
			);
		} else if (msgs.length > 1) {
			var list = el('ul', { className: 'wtt-rule-banner__list' });
			msgs.forEach(function (msg) {
				list.appendChild(el('li', { text: String(msg || '') }));
			});
			wrap.appendChild(list);
		}

		var fixes =
			validation && Array.isArray(validation.fixes) ? validation.fixes : [];
		if (fixes.length) {
			var actions = el('div', { className: 'wtt-rule-banner__actions' });
			fixes.forEach(function (fix) {
				actions.appendChild(
					el('button', {
						type: 'button',
						className: 'button button-secondary wtt-rule-banner__fix',
						text: attributeFixButtonLabel(fix),
						onClick: function () {
							fixAttributeRule(n, fix);
						},
					})
				);
			});
			wrap.appendChild(actions);
		}
		return wrap;
	}

	function collectTableValidationFixes(validation, n) {
		if (validation && Array.isArray(validation.fixes) && validation.fixes.length) {
			return validation.fixes;
		}
		if (
			window.WTTTableValidator &&
			typeof window.WTTTableValidator.collectFixes === 'function'
		) {
			return window.WTTTableValidator.collectFixes(
				(validation && validation.bands) || null,
				!!(validation && validation.isCatalog)
			);
		}
		return [];
	}

	function tableFixButtonLabel(fix) {
		var action = String((fix && fix.action) || 'create_fields');
		if (action === 'create_zeile') {
			return i18n.tableFixCreateZeile || 'Create Zeile';
		}
		if (action === 'create_zeile_field') {
			return i18n.tableFixCreateZeileField || 'Create Zeile field';
		}
		return (
			(i18n.tableFixCreateFields || 'Create missing fields') +
			' â€” ' +
			((fix && fix.bandName) || (fix && fix.band) || '') +
			' (' +
			String((fix && fix.missing) || 0) +
			'/' +
			String((fix && fix.zeileCount) || 0) +
			')'
		);
	}

	function fixTableRule(n, fix) {
		var termId = n && n.id != null ? parseInt(n.id, 10) || 0 : 0;
		if (termId <= 0) {
			return;
		}
		fix = fix || {};
		var payload = {
			term_id: termId,
			fix_action: String(fix.action || 'create_fields'),
		};
		if (fix.band && fix.action === 'create_fields') {
			payload.band = String(fix.band);
		}
		post('wtt_fix_table_band_fields', payload)
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || state.tree;
				if (json.data.node) {
					applyLoadedNode(json.data.node);
					mergeNodeTypeIntoTree(state.tree, json.data.node);
				}
				var created = Array.isArray(json.data.created) ? json.data.created : [];
				if (created.length) {
					var bindings =
						(json.data.node && json.data.node.propBindings) ||
						(n && n.propBindings) ||
						{};
					created.forEach(function (row) {
						var bandId = parseInt(bindings[row.band], 10) || 0;
						if (bandId > 0) {
							state.expanded[bandId] = true;
						}
						if (row.id) {
							state.expanded[row.id] = true;
						}
					});
					state.expanded[termId] = true;
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function fixTableBandFields(n, bandKey) {
		fixTableRule(n, { action: 'create_fields', band: bandKey || '' });
	}

	/**
	 * Slim top-of-detail rule banner (errors + optional fixes).
	 */
	function renderTableValidationBanner(validation, n) {
		var wrap = el('div', {
			className: 'wtt-rule-banner wtt-rule-banner--table',
			role: 'alert',
		});
		var head = el('div', { className: 'wtt-rule-banner__head' });
		head.appendChild(
			el('span', {
				className: 'wtt-rule-banner__mark',
				text: '!',
				'aria-hidden': 'true',
			})
		);
		head.appendChild(
			el('strong', {
				className: 'wtt-rule-banner__title',
				text: i18n.tableValidationBanner || 'Table rule failed',
			})
		);
		wrap.appendChild(head);

		var msgs = (validation && validation.errors) || [];
		if (msgs.length === 1) {
			wrap.appendChild(
				el('p', {
					className: 'wtt-rule-banner__msg',
					text: String(msgs[0] || ''),
				})
			);
		} else if (msgs.length > 1) {
			var list = el('ul', { className: 'wtt-rule-banner__list' });
			msgs.forEach(function (msg) {
				list.appendChild(el('li', { text: String(msg || '') }));
			});
			wrap.appendChild(list);
		}

		var fixes = collectTableValidationFixes(validation, n);
		if (fixes.length) {
			var actions = el('div', { className: 'wtt-rule-banner__actions' });
			fixes.forEach(function (fix) {
				actions.appendChild(
					el('button', {
						type: 'button',
						className: 'button button-secondary button-small wtt-rule-banner__fix',
						text: tableFixButtonLabel(fix),
						title:
							i18n.tableFixCreateFieldsHint ||
							'Apply suggested fix for this table rule',
						onClick: function () {
							fixTableRule(n, fix);
						},
					})
				);
			});
			var fieldFixes = fixes.filter(function (f) {
				return f && f.action === 'create_fields';
			});
			if (fieldFixes.length > 1) {
				actions.appendChild(
					el('button', {
						type: 'button',
						className: 'button button-primary button-small wtt-rule-banner__fix',
						text: i18n.tableFixAllBands || 'Fix all bands',
						onClick: function () {
							fixTableRule(n, { action: 'create_fields', band: '' });
						},
					})
				);
			}
			wrap.appendChild(actions);
		}
		return wrap;
	}

	/* Back-compat alias used by older call sites. */
	function renderTableValidationErrors(validation, n) {
		return renderTableValidationBanner(validation, n);
	}

	function renderRegistryPreviewContent(n) {
		var block = el('div', { className: 'wtt-preview__body' });
		var preferred = effectiveHostPreferredRender(n);
		var key = resolveNodeRenderTypeKey(n);
		var api = nodeRenderApi();
		var isTableType =
			key === 'table' ||
			!!(api && api.isStructuredType && api.isStructuredType(key)) ||
			!!n.isTable ||
			!!n.isTableTypeCatalog;

		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewPreferredOnlyHint ||
					"Preview surface = this node's Preferred (Editable + Display). Nested attribute fields still use their walk / Relation Render (e.g. Kontakt → Table).",
			})
		);

		if (key === 'media') {
			var mediaCfg =
				(state.draft && state.draft.mediaConfig) ||
				(n && n.mediaConfig) ||
				{};
			var allowedKinds = normalizeAllowedKinds(mediaCfg.allowedKinds);
			if (!allowedKinds.length) {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint wtt-field-hint--warn',
						text:
							i18n.mediaKindsRequired ||
							'Select at least one MIME kind — media fields do nothing until a kind is enabled.',
					})
				);
			} else {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text:
							(i18n.mediaKindsSelectedHint || 'Rendering only:') +
							' ' +
							allowedKinds
								.map(function (kind) {
									return mediaKindLabel(kind);
								})
								.join(', '),
					})
				);
			}
		}

		if (isTableType || preferred === 'TableRenderer') {
			var validation = resolveTableValidation(n);
			if (validation && !validation.ok) {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint wtt-preview__gated',
						text:
							i18n.tableValidationFailed ||
							'Table preview unavailable until the definition is valid.',
					})
				);
				return block;
			}
			if (isTableType) {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text: n.isTableTypeCatalog
							? i18n.tableTypePreviewHint ||
							  'Table type — preview as table only (tree/form not applicable).'
							: i18n.tableInstancePreviewHint ||
							  'Table preview from Kopf / Zeile / Fuss bindings (type properties).',
					})
				);
				block.appendChild(
					renderRegistryPreviewSection(
						i18n.previewAsTable || 'Table',
						renderRegistryTableTypeChrome(n)
					)
				);
				return block;
			}
			block.appendChild(
				renderRegistryPreviewSection(
					i18n.previewAsTable || 'Table',
					renderRegistryTableChrome(n)
				)
			);
			return block;
		}

		block.appendChild(
			renderRegistryPreviewSection(
				i18n.previewAsForm || 'Form',
				renderRegistryFormChrome(n)
			)
		);
		return block;
	}

	/**
	 * Attribute host preview members (Name/E-Mail, â€¦) â€” skip hidden.
	 * Seeds `.sample` from WTTSampleData so Editable/Display fill when previewValues empty.
	 * @return {Array}
	 */
	function attributePreviewMembers(n) {
		var attrs = Array.isArray(n.attributes) ? n.attributes : [];
		var out = [];
		var Sample = window.WTTSampleData;
		attrs.forEach(function (attr) {
			if (!attr || attr.hidden) {
				return;
			}
			var typeKey = String(
				attr.typeKey || attr.typeName || attr.typeLabel || 'text'
			)
				.trim()
				.toLowerCase();
			/* typeName may be a path ("â€¦ / text") â€” use last segment. */
			if (typeKey.indexOf('/') !== -1) {
				var parts = typeKey.split('/');
				typeKey = String(parts[parts.length - 1] || '')
					.trim()
					.toLowerCase();
			}
			if (!typeKey || typeKey === String(attr.typeId || '')) {
				typeKey = 'text';
			}
			if (Sample && typeof Sample.resolveTypeKey === 'function') {
				var resolved = Sample.resolveTypeKey(typeKey);
				if (resolved) {
					typeKey = resolved;
				}
			}
			if (typeKey === 'e-mail' || typeKey === 'e_mail' || typeKey === 'mail') {
				typeKey = 'email';
			}
			var member = {
				id: attr.id,
				name: attr.name || '',
				displayName: attr.name || '',
				description: attr.description || '',
				shortDescription:
					attr.shortDescription != null ? String(attr.shortDescription) : '',
				type: { name: typeKey },
				typeKey: typeKey,
				typeName: typeKey,
				typeId: parseInt(attr.typeId, 10) || 0,
				multiplicity: String(attr.multiplicity || '1'),
				fieldMultiplicity: String(attr.multiplicity || '1'),
				allowsMany: !!attr.allowsMany,
				allowsEmpty:
					attr.allowsEmpty != null
						? !!attr.allowsEmpty
						: String(attr.multiplicity || '1') === '0..1' ||
						  String(attr.multiplicity || '1') === '0..*',
				required: false,
				readonly: !!attr.readonly,
				fixedLabel: attr.fixedLabel || '',
				fixedValues: Array.isArray(attr.fixedValues)
					? attr.fixedValues.slice()
					: [],
				fixedMode: attr.fixedMode || '',
				fixedOptions: Array.isArray(attr.fixedOptions)
					? attr.fixedOptions.slice()
					: [],
				choiceDepth:
					attr.choiceDepth != null
						? parseInt(attr.choiceDepth, 10) || 0
						: 0,
				typePreferredRender: String(attr.typePreferredRender || ''),
				preferredRender: String(
					attr.preferredRender || attr.typePreferredRender || ''
				),
				typeProperties: Array.isArray(attr.typeProperties)
					? attr.typeProperties.slice()
					: [],
				typeExtras:
					attr.typeExtras && typeof attr.typeExtras === 'object'
						? deepClone(attr.typeExtras)
						: null,
				intConfig:
					attr.intConfig && typeof attr.intConfig === 'object'
						? deepClone(attr.intConfig)
						: null,
				displayFormat:
					attr.displayFormat != null
						? String(attr.displayFormat)
						: attr.preferredConverter != null
							? String(attr.preferredConverter)
							: attr.intConfig && attr.intConfig.displayFormat
								? String(attr.intConfig.displayFormat)
								: '',
				preferredConverter: normalizePreferredConverter(
					attr.preferredConverter ||
						attr.displayFormat ||
						(attr.intConfig && attr.intConfig.displayFormat) ||
						(attr.typeExtras && attr.typeExtras.preferredConverter) ||
						(attr.typeExtras && attr.typeExtras.displayFormat) ||
						''
				),
				fixed: null,
				fixedLiteral: '',
				sample: '',
				quantitySchema:
					attr.quantitySchema &&
					typeof attr.quantitySchema === 'object'
						? deepClone(attr.quantitySchema)
						: null,
			};
			var fest =
				(member.fixedLabel && String(member.fixedLabel).trim()) ||
				(member.fixedValues.length
					? member.fixedValues
							.map(function (v) {
								return String(v);
							})
							.filter(Boolean)
							.join(', ')
					: '');
			if (fest) {
				member.fixedLabel = fest;
				member.sample = fest;
			} else if (Sample && typeof Sample.forAttribute === 'function') {
				member.sample = String(Sample.forAttribute(member) || '');
			} else if (Sample && typeof Sample.forType === 'function') {
				member.sample = String(
					Sample.forType(typeKey, {
						name: member.name,
						shortDescription: member.shortDescription,
					}) || ''
				);
			}
			out.push(member);
		});
		return out;
	}

	/**
	 * Synthetic Object View DTO for admin schema Preview (samples, Mult many rows).
	 * Host Preferred drives the surface — not attribute Relation Render overrides.
	 */
	function buildAttributeHostPreviewView(n, members, preferred) {
		var Sample = window.WTTSampleData;
		var memberById = {};
		(members || []).forEach(function (m) {
			if (m && m.id != null) {
				memberById[String(m.id)] = m;
			}
		});
		var attrs = Array.isArray(n.attributes) ? n.attributes : members || [];
		var properties = [];
		var instanceValues = {};
		attrs.forEach(function (attr) {
			if (!attr || attr.hidden) {
				return;
			}
			var mid = String(attr.id != null ? attr.id : attr.name || '');
			var mem = memberById[mid] || {};
			var typeProps = Array.isArray(attr.typeProperties)
				? attr.typeProperties
				: Array.isArray(mem.typeProperties)
					? mem.typeProperties
					: [];
			var mult = String(attr.multiplicity || mem.multiplicity || '1');
			var allowsMany =
				!!attr.allowsMany ||
				!!mem.allowsMany ||
				mult === '0..*' ||
				mult === '1..*';
			var prop = {
				id: attr.id,
				name: attr.name || mem.name || '',
				typeKey: attr.typeKey || mem.typeKey || '',
				typeName: attr.typeName || attr.typeLabel || mem.typeName || '',
				typeId: parseInt(attr.typeId, 10) || 0,
				multiplicity: mult,
				fieldMultiplicity: mult,
				allowsMany: allowsMany,
				readonly: !!attr.readonly,
				preferredRender: String(
					attr.preferredRender || mem.preferredRender || ''
				),
				typePreferredRender: String(
					attr.typePreferredRender || mem.typePreferredRender || ''
				),
				fixedMode: String(attr.fixedMode || mem.fixedMode || ''),
				typeProperties: typeProps,
				binding: attr.binding || '',
				quantitySchema:
					attr.quantitySchema || mem.quantitySchema || null,
				fixedOptions: Array.isArray(attr.fixedOptions)
					? attr.fixedOptions
					: Array.isArray(mem.fixedOptions)
						? mem.fixedOptions
						: [],
				choiceDepth:
					attr.choiceDepth != null
						? parseInt(attr.choiceDepth, 10) || 0
						: mem.choiceDepth != null
							? parseInt(mem.choiceDepth, 10) || 0
							: 0,
				intConfig: attr.intConfig || mem.intConfig || null,
				presentationConfig:
					attr.presentationConfig || mem.presentationConfig || null,
				preferredConverter: normalizePreferredConverter(
					attr.preferredConverter || mem.preferredConverter || ''
				),
				values: [],
			};
			var hostNameSample = (n && n.name) || '';
			if (allowsMany && typeProps.length) {
				var rows = [];
				var r;
				for (r = 0; r < 3; r++) {
					var obj = {};
					typeProps.forEach(function (tp) {
						if (!tp) {
							return;
						}
						var fk =
							tp.id != null
								? String(tp.id)
								: String(tp.name || '');
						var sample = '';
						if (Sample && typeof Sample.forAttribute === 'function') {
							sample = String(
								Sample.forAttribute(
									Object.assign({}, tp, { variantIndex: r })
								) || ''
							);
						}
						obj[fk] = sample;
					});
					rows.push(JSON.stringify(obj));
				}
				prop.values = rows;
			} else {
				var typeKeySample = String(
					attr.typeKey || mem.typeKey || ''
				)
					.trim()
					.toLowerCase();
				var sampleVal = '';
				if (isNodePresentationTypeKey(typeKeySample)) {
					var pCtxSample = presentationContextFromAttr(
						Object.assign({}, mem, attr)
					);
					sampleVal = resolveHostPresentationValue(n, pCtxSample) || '';
					if (
						!sampleVal &&
						pCtxSample !== 'symbol' &&
						pCtxSample !== 'table' &&
						pCtxSample !== 'icon'
					) {
						sampleVal = hostNameSample || 'Node name';
					}
					if (
						!sampleVal &&
						(pCtxSample === 'symbol' || pCtxSample === 'table')
					) {
						sampleVal = '—';
					}
				} else if (mem.sample != null && String(mem.sample) !== '') {
					sampleVal = String(mem.sample);
				} else if (Sample && typeof Sample.forAttribute === 'function') {
					sampleVal = String(
						Sample.forAttribute(
							Object.assign({}, mem.id ? mem : attr, {
								hostName: hostNameSample,
								hostPresentation: presentationMapFromHost(n),
								hostShortDescription:
									resolveHostPresentationValue(n, 'symbol') ||
									(n && n.shortDescription) ||
									'',
								presentationConfig:
									attr.presentationConfig ||
									mem.presentationConfig ||
									null,
							})
						) || ''
					);
				}
				if (sampleVal) {
					prop.values = [sampleVal];
					instanceValues[mid] = sampleVal;
				}
			}
			if (isNodePresentationTypeKey(prop.typeKey)) {
				prop.readonly = true;
				prop.hostPresentation = presentationMapFromHost(n);
				prop.hostShortDescription =
					resolveHostPresentationValue(n, 'symbol') ||
					(n && n.shortDescription) ||
					'';
				prop.hostName = hostNameSample || (n && n.name) || '';
			}
			properties.push(prop);
		});
		return {
			id: n && n.id,
			name: (n && n.name) || '',
			preferredRender: preferred,
			properties: properties,
			instanceValues: instanceValues,
			embedChoiceOptions: Array.isArray(n.embedChoiceOptions)
				? n.embedChoiceOptions.slice()
				: [],
		};
	}

	/**
	 * Mount Object View chrome-free into a Preview edit/display cell.
	 */
	function mountAttributeHostPreviewSurface(ObjectRender, view, preferred, readonly) {
		var host = el('div', {
			className:
				'wtt-preview__object-mount' +
				(readonly ? ' is-display' : ' is-edit'),
		});
		if (!ObjectRender || typeof ObjectRender.mount !== 'function') {
			return host;
		}
		ObjectRender.mount(host, view, {
			layout: preferred,
			chrome: false,
			renderDepth: 1,
			readonly: !!readonly,
			mode: readonly ? 'display' : 'edit',
		});
		return host;
	}

	/**
	 * Resolve Praefix Default (fixedValues / fixedLabel) from host attributes.
	 *
	 * @param {Object} n Host node DTO.
	 * @return {string} Prefix option name (e.g. Kilo) or ''.
	 */
	function hostPraefixDefaultName(n) {
		var attrs = Array.isArray(n && n.attributes) ? n.attributes : [];
		var i;
		for (i = 0; i < attrs.length; i++) {
			var attr = attrs[i];
			if (!attr) {
				continue;
			}
			var key = String(attr.name || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue')
				.replace(/\u00f6/g, 'oe')
				.replace(/\u00e4/g, 'ae');
			if (key !== 'praefix' && key !== 'prefix') {
				continue;
			}
			if (attr.fixedLabel != null && String(attr.fixedLabel).trim() !== '') {
				return String(attr.fixedLabel).trim();
			}
			var fv = Array.isArray(attr.fixedValues) ? attr.fixedValues : [];
			var raw = fv.length ? fv[0] : null;
			if (raw == null || raw === '') {
				return '';
			}
			var opts = Array.isArray(attr.fixedOptions) ? attr.fixedOptions : [];
			var j;
			for (j = 0; j < opts.length; j++) {
				var o = opts[j];
				if (
					o &&
					(String(o.id) === String(raw) || String(o.name) === String(raw))
				) {
					return String(o.name || '');
				}
			}
			return String(raw);
		}
		/* Fallback: quantitySchema Praefix.sample from PHP overlay. */
		var schema = n && n.quantitySchema;
		var members =
			schema && Array.isArray(schema.members) ? schema.members : [];
		for (i = 0; i < members.length; i++) {
			var m = members[i];
			var mk = String((m && m.name) || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue')
				.replace(/\u00f6/g, 'oe')
				.replace(/\u00e4/g, 'ae');
			if (mk !== 'praefix' && mk !== 'prefix') {
				continue;
			}
			if (m.sample != null && String(m.sample) !== '') {
				return String(m.sample);
			}
			if (m.fixedLiteral != null && String(m.fixedLiteral) !== '') {
				return String(m.fixedLiteral);
			}
		}
		return '';
	}

	/**
	 * Initial Unit Preferred preview store — attribute Defaults, not empty.
	 *
	 * @param {Object} n
	 * @return {string}
	 */
	function seedUnitPreviewStoreFromHost(n) {
		var prefix = hostPraefixDefaultName(n);
		if (!prefix) {
			return '';
		}
		return JSON.stringify({ mag: '', prefix: prefix });
	}

	/**
	 * Initial Quantity Preferred preview store.
	 *
	 * @param {Object} n
	 * @return {string}
	 */
	function seedQuantityPreviewStoreFromHost(n) {
		var prefix = hostPraefixDefaultName(n);
		if (!prefix) {
			return '10.5';
		}
		return JSON.stringify({ mag: '10.5', prefix: prefix });
	}

	/**
	 * Attribute host preview: Preferred surface × edit/display (P4).
	 * Form/Compact/Embed → WTTObjectRender.mount (Mult many = collection Table).
	 * Table → Table(n) sample host rows. Host Preferred only — not attr Relation Render.
	 */
	function renderAttributeHostPreview(n, members) {
		var ObjectRender = window.WTTObjectRender;
		var preferred = effectiveHostPreferredRender(n);
		var block = el('div', { className: 'wtt-preview__body' });
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewPreferredOnlyHint ||
					"Preview surface = this node's Preferred (Editable + Display). Nested attribute fields still use their walk / Relation Render (e.g. Kontakt → Table).",
			})
		);

		if (!ObjectRender || typeof ObjectRender.renderForm !== 'function') {
			if (preferred === 'TableRenderer') {
				block.appendChild(
					renderPreviewSurface(
						i18n.previewAsTable || 'Table',
						renderMultiColumnTablePreview(members, 'edit', false),
						renderMultiColumnTablePreview(members, 'display', false)
					)
				);
			} else {
				block.appendChild(
					renderPreviewSurface(
						i18n.previewAsForm || 'Form',
						renderSetFormPreview(members, { mode: 'edit' }),
						renderSetFormPreview(members, { mode: 'display' })
					)
				);
			}
			return block;
		}

		var attrs = Array.isArray(n.attributes) ? n.attributes : members;
		var one =
			typeof ObjectRender.buildExampleInstance === 'function'
				? ObjectRender.buildExampleInstance(n, attrs)
				: { attributes: members, values: {} };
		one = applyComputedPreviewValues(one, attrs);

		var many =
			typeof ObjectRender.buildExampleList === 'function'
				? ObjectRender.buildExampleList(n, 3, attrs)
				: [one];
		many = many.map(function (inst) {
			return applyComputedPreviewValues(inst, attrs);
		});

		function applyStoredValues(instance, scope) {
			var fields = (instance && instance.attributes) || [];
			var values = Object.assign({}, (instance && instance.values) || {});
			var hostLiveName = (n && n.name) || '';
			fields.forEach(function (field) {
				var typeKey = String((field && field.typeKey) || '')
					.trim()
					.toLowerCase();
				var idKey =
					field && field.id != null
						? String(field.id)
						: String(field.name || '');
				/* node_presentation always tracks the selected host — ignore session samples. */
				if (isNodePresentationTypeKey(typeKey)) {
					field.hostPresentation =
						presentationMapFromHost(n) ||
						field.hostPresentation ||
						null;
					field.hostShortDescription =
						resolveHostPresentationValue(n, 'symbol') ||
						(n && n.shortDescription) ||
						field.hostShortDescription ||
						'';
					field.hostName = hostLiveName || field.hostName || '';
					var pCtxFill = presentationContextFromAttr(field);
					var presented =
						resolveHostPresentationValue(n, pCtxFill) || '';
					if (
						!presented &&
						pCtxFill !== 'symbol' &&
						pCtxFill !== 'table' &&
						pCtxFill !== 'icon'
					) {
						presented = hostLiveName || 'Node name';
					}
					if (
						!presented &&
						(pCtxFill === 'symbol' ||
							pCtxFill === 'table' ||
							pCtxFill === 'icon')
					) {
						presented = '—';
					}
					values[idKey] = presented;
					return;
				}
				var key = previewValueKey(scope, field);
				if (Object.prototype.hasOwnProperty.call(state.previewValues, key)) {
					values[idKey] = state.previewValues[key];
				}
			});
			return applyComputedPreviewValues(
				Object.assign({}, instance, { values: values }),
				attrs
			);
		}

		var formInstance = applyStoredValues(one, 'obj-form');
		var tableInstances = many.map(function (inst, idx) {
			return applyStoredValues(inst, 'obj-table-' + String(idx));
		});

		function onFormFieldInput(field, next) {
			if (field && (field.computed || field.compute)) {
				return;
			}
			setPreviewValue('obj-form', field, next);
		}
		function onTableFieldInput(field, next, _inst, rowIndex) {
			if (field && (field.computed || field.compute)) {
				return;
			}
			setPreviewValue('obj-table-' + String(rowIndex || 0), field, next);
		}

		var titleMap = {
			FormRenderer: i18n.previewAsForm || 'Form',
			TableRenderer: i18n.previewAsTable || 'Table',
			CompactRenderer:
				i18n.previewCompactHorizontal || 'Compact (horizontal)',
			CompactVerticalRenderer:
				i18n.previewCompactVertical || 'Compact (vertical)',
			EmbeddedRenderer: i18n.previewEmbed || 'Embedded renderer',
			quantity: i18n.previewQuantity || 'Quantity',
			unit: i18n.previewUnit || 'Unit',
		};

		if (preferred === 'quantity' || preferred === 'unit') {
			var apiQty = nodeRenderApi();
			var qtyScope = 'obj-qty';
			var qtyMember = {
				id: n && n.id != null ? n.id : 'qty',
				name: preferred === 'unit' ? 'Unit' : 'Quantity',
			};
			var qtyKey = previewValueKey(qtyScope, qtyMember);
			/*
			 * Seed from live attribute Defaults (e.g. Praefix → Kilo). Empty string
			 * used to mean “no store” and skipped the Default — prefix stayed unset.
			 */
			if (
				!Object.prototype.hasOwnProperty.call(state.previewValues, qtyKey) ||
				state.previewValues[qtyKey] === ''
			) {
				state.previewValues[qtyKey] =
					preferred === 'unit'
						? seedUnitPreviewStoreFromHost(n)
						: seedQuantityPreviewStoreFromHost(n);
			}
			var qtyProbe = {
				id: n && n.id,
				name: (n && n.name) || '',
				typeKey: preferred === 'unit' ? 'unit' : 'quantity',
				isBasiseinheitUnit: !!(n && n.isBasiseinheitUnit),
				quantitySchema: (n && n.quantitySchema) || null,
				shortDescription: (n && n.shortDescription) || '',
				/*
				 * Unit/Quantity Preferred preview must see Q117 presentation
				 * (symbol/table) — otherwise Kuerzel Festwert wins and Display
				 * edits look ignored.
				 */
				presentation: (function () {
					var draftMap =
						state.draft &&
						state.draft.presentation &&
						state.draft.presentation.loaded &&
						state.draft.presentation.values
							? state.draft.presentation.values
							: null;
					return (
						draftMap ||
						presentationMapFromHost(n) ||
						(n && n.presentation) ||
						null
					);
				})(),
				preferredRender: preferred === 'unit' ? 'unit' : 'quantity',
				attributes: Array.isArray(formInstance.attributes)
					? formInstance.attributes
					: Array.isArray(n.attributes)
						? n.attributes
						: members,
			};
			function paintPreferredField(mode) {
				if (
					!apiQty ||
					!apiQty.Registry ||
					typeof apiQty.Registry.renderContent !== 'function'
				) {
					return el('span', {
						text: i18n.previewUnavailable || 'Preview unavailable',
					});
				}
				var ctx = {
					name: 'form',
					mode: mode === 'display' ? 'display' : 'edit',
					value: String(state.previewValues[qtyKey] || ''),
					valueKey: qtyKey,
					onInput:
						mode === 'display'
							? null
							: function (next) {
									state.previewValues[qtyKey] = next;
									state.previewFocus = {
										key: qtyKey,
										start: null,
										end: null,
									};
									renderKeepingPreviewChrome();
							  },
				};
				var painted = apiQty.Registry.renderContent(
					qtyProbe,
					ctx,
					mode === 'display'
				);
				return painted || el('span', { text: '—' });
			}
			block.appendChild(
				renderPreviewSurface(
					titleMap[preferred] || titleMap.quantity,
					paintPreferredField('edit'),
					paintPreferredField('display')
				)
			);
		} else if (preferred === 'TableRenderer') {
			/* P4 Table(n): sample host rows — columns = host attributes. */
			block.appendChild(
				renderPreviewSurface(
					titleMap.TableRenderer,
					ObjectRender.renderTable(tableInstances, {
						readonly: false,
						onFieldInput: onTableFieldInput,
						attributes: (one && one.attributes) || members,
					}),
					ObjectRender.renderTable(tableInstances, {
						readonly: true,
						attributes: (one && one.attributes) || members,
					})
				)
			);
		} else if (
			preferred === 'EmbeddedRenderer' &&
			typeof ObjectRender.renderEmbed === 'function'
		) {
			var embedKey = 'obj-embed-' + String((n && n.id) || 0);
			var embedVal = Object.prototype.hasOwnProperty.call(
				state.previewValues,
				embedKey
			)
				? String(state.previewValues[embedKey] || '')
				: '';
			var embedChoices = Array.isArray(n.embedChoiceOptions)
				? n.embedChoiceOptions
				: [];
			block.appendChild(
				renderPreviewSurface(
					titleMap.EmbeddedRenderer,
					ObjectRender.renderEmbed({
						choiceOptions: embedChoices,
						value: embedVal,
						readonly: false,
						onChange: function (next) {
							state.previewValues[embedKey] =
								next == null ? '' : String(next);
							renderDetail();
						},
					}),
					ObjectRender.renderEmbed({
						choiceOptions: embedChoices,
						value: embedVal,
						readonly: true,
					})
				)
			);
		} else if (typeof ObjectRender.mount === 'function') {
			/*
			 * Form / Compact: same Object View mount path (Mult many → collection
			 * Table). Attribute Relation Render overrides do not switch this surface.
			 */
			var previewView = buildAttributeHostPreviewView(
				n,
				members,
				preferred
			);
			if (
				formInstance &&
				formInstance.values &&
				typeof formInstance.values === 'object'
			) {
				previewView.instanceValues = Object.assign(
					{},
					previewView.instanceValues || {},
					formInstance.values
				);
			}
			/* Force live host presentation onto node_presentation props. */
			(previewView.properties || []).forEach(function (prop) {
				if (!isNodePresentationTypeKey((prop && prop.typeKey) || '')) {
					return;
				}
				var pCtxLive = presentationContextFromAttr(prop);
				var live = resolveHostPresentationValue(n, pCtxLive) || '';
				if (
					!live &&
					pCtxLive !== 'symbol' &&
					pCtxLive !== 'table' &&
					pCtxLive !== 'icon'
				) {
					live = (n && n.name) || 'Node name';
				}
				if (
					!live &&
					(pCtxLive === 'symbol' ||
						pCtxLive === 'table' ||
						pCtxLive === 'icon')
				) {
					live = '—';
				}
				prop.values = [live];
				prop.readonly = true;
				prop.hostPresentation = presentationMapFromHost(n);
				prop.hostShortDescription =
					resolveHostPresentationValue(n, 'symbol') ||
					(n && n.shortDescription) ||
					'';
				prop.hostName = (n && n.name) || '';
				var pid = prop.id != null ? String(prop.id) : String(prop.name || '');
				if (pid) {
					previewView.instanceValues[pid] = live;
				}
			});
			block.appendChild(
				renderPreviewSurface(
					titleMap[preferred] || titleMap.FormRenderer,
					mountAttributeHostPreviewSurface(
						ObjectRender,
						previewView,
						preferred,
						false
					),
					mountAttributeHostPreviewSurface(
						ObjectRender,
						previewView,
						preferred,
						true
					)
				)
			);
		} else if (
			preferred === 'CompactRenderer' ||
			preferred === 'CompactVerticalRenderer'
		) {
			if (typeof ObjectRender.renderCompact === 'function') {
				var orient =
					preferred === 'CompactVerticalRenderer'
						? 'vertical'
						: 'horizontal';
				block.appendChild(
					renderPreviewSurface(
						titleMap[preferred],
						ObjectRender.renderCompact(formInstance, {
							readonly: false,
							orientation: orient,
							onFieldInput: onFormFieldInput,
						}),
						ObjectRender.renderCompact(formInstance, {
							readonly: true,
							orientation: orient,
						})
					)
				);
			}
		} else {
			block.appendChild(
				renderPreviewSurface(
					titleMap.FormRenderer,
					ObjectRender.renderForm(formInstance, {
						readonly: false,
						onFieldInput: onFormFieldInput,
					}),
					ObjectRender.renderForm(formInstance, { readonly: true })
				)
			);
		}

		return block;
	}

	/**
	 * Derive computed attribute sample values (flat-list Aggregate ops).
	 */
	function applyComputedPreviewValues(instance, attrs) {
		if (!instance) {
			return instance;
		}
		var values = Object.assign({}, instance.values || {});
		var list = Array.isArray(attrs)
			? attrs
			: Array.isArray(instance.attributes)
				? instance.attributes
				: [];
		list.forEach(function (attr) {
			if (!attr || !(attr.computed || (attr.compute && attr.compute.op))) {
				return;
			}
			var result = evaluateAttributeCompute(attr, list, values);
			if (result == null || result === '') {
				return;
			}
			var idKey =
				attr.id != null ? String(attr.id) : String(attr.name || '');
			values[idKey] = result;
			attr = Object.assign({}, attr, {
				readonly: true,
				computed: true,
			});
		});
		var nextAttrs = Array.isArray(instance.attributes)
			? instance.attributes.map(function (a) {
					if (a && (a.computed || (a.compute && a.compute.op))) {
						return Object.assign({}, a, { readonly: true, computed: true });
					}
					return a;
			  })
			: instance.attributes;
		return Object.assign({}, instance, {
			values: values,
			attributes: nextAttrs,
		});
	}

	function evaluateAttributeCompute(attr, attributes, values) {
		var compute =
			(attr.typeExtras && attr.typeExtras.compute) || attr.compute;
		if (!compute || !compute.op) {
			return null;
		}
		var op = String(compute.op || '').toLowerCase();
		var flat = [];
		(Array.isArray(compute.sources) ? compute.sources : []).forEach(
			function (src) {
				if (!src) {
					return;
				}
				var aid = wttAttrId(src.attrId);
				if (!aid) {
					return;
				}
				var raw = values[String(aid)];
				if (src.kind === 'attrPath') {
					var pathId = wttAttrId(src.pathAttrId);
					var items = Array.isArray(raw) ? raw : raw != null ? [raw] : [];
					items.forEach(function (item) {
						if (!item || typeof item !== 'object') {
							return;
						}
						var v = pathId ? item[String(pathId)] : undefined;
						if (Array.isArray(v)) {
							v = v[0];
						}
						if (v != null && v !== '' && !isNaN(Number(v))) {
							flat.push(Number(v));
						}
					});
					return;
				}
				var list = Array.isArray(raw) ? raw : raw != null ? [raw] : [];
				list.forEach(function (v) {
					if (v != null && v !== '' && !isNaN(Number(v))) {
						flat.push(Number(v));
					}
				});
			}
		);
		if (op === 'count') {
			return String(flat.length);
		}
		if (!flat.length) {
			return null;
		}
		var result;
		if (op === 'sum') {
			result = flat.reduce(function (a, b) {
				return a + b;
			}, 0);
		} else if (op === 'avg') {
			result =
				flat.reduce(function (a, b) {
					return a + b;
				}, 0) / flat.length;
		} else if (op === 'min') {
			result = Math.min.apply(null, flat);
		} else if (op === 'max') {
			result = Math.max.apply(null, flat);
		} else {
			return null;
		}
		if (Math.abs(result - Math.round(result)) < 1e-9) {
			return String(Math.round(result));
		}
		return String(Math.round(result * 1e6) / 1e6);
	}

	/**
	 * Compact surface: one section with Horizontal + Vertical sub-blocks (edit|display).
	 * Shares obj-form previewValues with the Form surface.
	 */
	function renderCompactPreviewSection(formInstance, onFieldInput) {
		var ObjectRender = window.WTTObjectRender;
		var section = el('div', {
			className: 'wtt-set-preview__section wtt-preview__compact-section',
		});
		section.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.previewAsCompact || 'Compact',
			})
		);

		function orientBlock(orientation, title) {
			var sub = el('div', {
				className: 'wtt-preview__compact-orient',
			});
			sub.appendChild(
				el('h5', {
					className: 'wtt-set-preview__orient-title',
					text: title,
				})
			);
			var pair = el('div', { className: 'wtt-preview__block' });
			var editWrap = el('div', {
				className: 'wtt-preview__block-edit',
			});
			editWrap.appendChild(
				ObjectRender.renderCompact(formInstance, {
					readonly: false,
					orientation: orientation,
					onFieldInput: onFieldInput,
				})
			);
			var displayWrap = el('div', {
				className: 'wtt-preview__block-display',
			});
			displayWrap.appendChild(
				ObjectRender.renderCompact(formInstance, {
					readonly: true,
					orientation: orientation,
				})
			);
			pair.appendChild(editWrap);
			pair.appendChild(displayWrap);
			sub.appendChild(pair);
			return sub;
		}

		section.appendChild(
			orientBlock(
				'horizontal',
				i18n.previewCompactHorizontal || 'Horizontal'
			)
		);
		section.appendChild(
			orientBlock('vertical', i18n.previewCompactVertical || 'Vertical')
		);
		return section;
	}

	/**
	 * Hierarchy children used as automatic choice options (e.g. WÃ¤hrung â†’ Euro / US Dollar).
	 * Prefer live tree subtree so deeper levels stay a tree chooser; fall back to flat directChildren.
	 *
	 * @return {Array}
	 */
	function choiceCatalogPickRoots(n) {
		var id = parseInt(n && n.id, 10) || 0;
		if (!id) {
			return [];
		}
		var fromTree = nodeRefPickRoots(id);
		if (fromTree && fromTree.length) {
			return fromTree;
		}
		var kids = Array.isArray(n.directChildren) ? n.directChildren : [];
		if (
			!kids.length &&
			state.selectedNode &&
			parseInt(state.selectedNode.id, 10) === id &&
			Array.isArray(state.selectedNode.directChildren)
		) {
			kids = state.selectedNode.directChildren;
		}
		return kids
			.filter(function (c) {
				return c && c.id != null && !c.isTrash && !c.trashed;
			})
			.map(function (c) {
				return {
					id: parseInt(c.id, 10),
					name: c.name || String(c.id),
					children: Array.isArray(c.children) ? c.children : [],
					shortDescription: c.shortDescription || '',
					presentation:
						c.presentation && typeof c.presentation === 'object'
							? c.presentation
							: null,
					hasChildren: !!(c.hasChildren || (c.children && c.children.length)),
				};
			});
	}

	function isQuantityTypeCatalogNode(n) {
		if (!n) {
			return false;
		}
		var key = String(n.name || n.typeKey || '')
			.trim()
			.toLowerCase();
		var Sample = window.WTTSampleData;
		if (Sample && typeof Sample.resolveTypeKey === 'function') {
			var resolved = Sample.resolveTypeKey(key);
			if (resolved) {
				key = resolved;
			}
		}
		return (
			key === 'quantity' ||
			key === 'measure' ||
			key === 'groesse' ||
			key === 'grÃ¶ÃŸe' ||
			key === 'grose'
		);
	}

	/**
	 * Quantity catalog preview = compact QuantityRenderer (Preis-shaped attrs as trinity).
	 */
	function quantityFakeHostForPreview(n) {
		var ex = n && n.quantityPreviewExample;
		var attrs =
			ex && Array.isArray(ex.attributes) && ex.attributes.length
				? ex.attributes
				: [];
		var hostName =
			(ex && ex.hostName) ||
			i18n.quantityExampleHost ||
			'Preis';
		return {
			id: n && n.id != null ? n.id : 0,
			name: hostName,
			/* Catalog type preferred Quantity → one compact control, not Form rows. */
			preferredRender: 'quantity',
			attributes: attrs,
			_quantityExample: true,
		};
	}

	function renderQuantityCatalogPreview(n) {
		var host = quantityFakeHostForPreview(n);
		var members = attributePreviewMembers(host);
		var block = el('div', { className: 'wtt-preview__body' });
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.quantityCatalogPreviewHint ||
					'Quantity uses the Quantity renderer (compact magnitude + prefix + unit). Example shape follows Preis when present.',
			})
		);
		if (!members.length) {
			block.appendChild(
				el('p', {
					className: 'wtt-preview__unavailable',
					text:
						i18n.previewUnavailable ||
						'Preview nicht mÃ¶glich',
				})
			);
			return block;
		}
		var inner = renderAttributeHostPreview(host, members);
		/* Drop duplicate preferred-render hint from nested host preview. */
		if (inner && inner.firstChild && inner.firstChild.classList) {
			var first = inner.firstChild;
			if (
				first.classList.contains('wtt-field-hint') &&
				inner.childNodes.length > 1
			) {
				inner.removeChild(first);
			}
		}
		while (inner && inner.firstChild) {
			block.appendChild(inner.firstChild);
		}
		return block;
	}

	/**
	 * Structure-first Preferred (quantity/unit) — not a display-name allowlist.
	 */
	function isStructureFirstPreviewHost(n) {
		if (!n) {
			return false;
		}
		var pref = normalizePreferredRender(
			effectiveHostPreferredRender(n) || ''
		).toLowerCase();
		return (
			pref === 'quantity' ||
			pref === 'unit' ||
			pref === 'quantityrenderer' ||
			pref === 'unitrenderer'
		);
	}

	function isAutomaticChoiceCatalogNode(n) {
		if (!n) {
			return false;
		}
		if (isQuantityTypeCatalogNode(n)) {
			return false;
		}
		if (usesRegistryPreview(n)) {
			return false;
		}
		if (isMediaTypeCatalogNode(n) || isUnitDefinitionNode(n)) {
			return false;
		}
		if (n.isTable || n.isTableTypeCatalog) {
			return false;
		}
		if (!choiceCatalogPickRoots(n).length) {
			return false;
		}
		/* Attr hosts paint Preferred attribute chrome — never double CatalogChoice. */
		if (attributePreviewMembers(n).length) {
			return false;
		}
		if (isStructureFirstPreviewHost(n)) {
			return false;
		}
		return true;
	}

	function choiceCatalogPreviewMember(n) {
		return {
			id: 'choice-catalog:' + String(n.id || 0),
			name: n.name || '',
			description: n.description || '',
			shortDescription:
				n.shortDescription != null ? String(n.shortDescription) : '',
		};
	}

	function firstChoiceCatalogId(roots) {
		var found = 0;
		function walk(nodes) {
			var i;
			for (i = 0; i < (nodes || []).length; i++) {
				var node = nodes[i];
				if (!node || node.id == null) {
					continue;
				}
				var id = parseInt(node.id, 10) || 0;
				if (id > 0) {
					found = id;
					return true;
				}
			}
			for (i = 0; i < (nodes || []).length; i++) {
				if (walk((nodes[i] && nodes[i].children) || [])) {
					return true;
				}
			}
			return false;
		}
		walk(roots);
		return found;
	}

	function resolveChoiceCatalogLabel(roots, id, labelCtx) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return '—';
		}
		var hit = findNodeInTree(roots, id) || findNodeInTree(state.tree, id);
		if (hit) {
			return (
				formatChoiceOptionLabel(hit, labelCtx || 'form') ||
				hit.name ||
				'#' + id
			);
		}
		return '#' + id;
	}

	/**
	 * CatalogChoice control (Q90): depth â‰¤1 â†’ flat <select>; depth â‰¥2 â†’ tree picker.
	 */
	function renderChoiceCatalogControl(n, mode, scope) {
		var roots = choiceCatalogPickRoots(n);
		var labelCtx = hostChoiceLabelContext(n);
		var member = choiceCatalogPreviewMember(n);
		var fallback = firstChoiceCatalogId(roots);
		var raw = getPreviewValue(scope, member, '');
		var selectedId = parseInt(raw, 10) || 0;
		if (!selectedId && fallback) {
			selectedId = fallback;
			/* Seed without setPreviewValue — that would re-enter render(). */
			state.previewValues[previewValueKey(scope, member)] = String(fallback);
		}

		if (mode === 'display') {
			return el('span', {
				className: 'wtt-preview-display-value',
				text: resolveChoiceCatalogLabel(roots, selectedId, labelCtx),
			});
		}

		var chooserMode = resolveCatalogChooserMode(roots, [], 'auto');
		if (chooserMode === 'flat') {
			var flatOpts = flattenChoiceLeaves(roots);
			if (!flatOpts.length) {
				return el('span', {
					className: 'wtt-field-hint',
					text:
						i18n.previewChoiceCatalogEmpty ||
						'No child options under this node yet.',
				});
			}
			return renderOptionsSelect(flatOpts, {
				className: 'wtt-type-select wtt-catalog-choice-select',
				selectedValue: selectedId,
				allowEmpty: false,
				getValue: function (opt) {
					return opt.id != null ? String(opt.id) : '';
				},
				getLabel: function (opt) {
					return formatChoiceOptionLabel(opt, labelCtx);
				},
				getTitle: function (opt) {
					return formatSelectLabel(opt) || String(opt.name || '');
				},
				onChange: function (e) {
					var id = parseInt(e.target.value, 10) || 0;
					if (!id) {
						return;
					}
					setPreviewValue(scope, member, String(id));
				},
			});
		}

		var expandKey =
			'preview-choice:' +
			String(state.selectedId || n.id || 0) +
			':' +
			String(scope != null ? scope : 'form');

		return renderNodeTreePicker({
			roots: roots,
			selectedId: selectedId,
			selectedLabel: resolveChoiceCatalogLabel(roots, selectedId, labelCtx),
			compact: true,
			defaultOpen: !!selectedId,
			expandKey: expandKey,
			allowRoot: false,
			allowClear: false,
			expandFocusBranch: true,
			focusId: selectedId || (roots[0] && roots[0].id) || 0,
			emptyText:
				i18n.previewChoiceCatalogEmpty ||
				'No child options under this node yet.',
			pickedPrefix: i18n.nodePickerSelected || 'Selected:',
			placeholder: i18n.nodePickerChoose || 'Choose…',
			selectable: function (node) {
				return !!(node && parseInt(node.id, 10) > 0);
			},
			formatNodeLabel: function (node) {
				return formatChoiceOptionLabel(node, labelCtx);
			},
			onSelect: function (id) {
				id = parseInt(id, 10) || 0;
				if (!id) {
					return;
				}
				setPreviewValue(scope, member, String(id));
			},
		});
	}

	function renderChoiceCatalogForm(n, mode) {
		var form = el('div', {
			className:
				'wtt-set-preview__form' +
				(mode === 'display' ? ' wtt-set-preview__form--display' : ''),
		});
		var row = el('div', { className: 'wtt-set-preview__row' });
		row.appendChild(
			el('label', {
				className: 'wtt-set-preview__label',
				text: n.name || i18n.previewColField || 'Field',
			})
		);
		row.appendChild(renderChoiceCatalogControl(n, mode, 'choice-form'));
		if (mode === 'edit') {
			var help = renderHelpHint({
				description: n.description || '',
				shortDescription: n.shortDescription || '',
			});
			if (help) {
				row.appendChild(help);
			}
		}
		form.appendChild(row);
		return form;
	}

	function renderChoiceCatalogTable(n, mode) {
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		[
			i18n.previewColIndex || '#',
			i18n.previewColOther || 'Column A',
			n.name || i18n.previewColField || 'Field',
			i18n.previewColNote || 'Column B',
		].forEach(function (label) {
			headRow.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var row = el('tr');
		row.appendChild(el('td', { text: '1' }));
		if (mode === 'edit') {
			var otherTd = el('td');
			otherTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: i18n.previewSampleText || 'Sample',
				})
			);
			row.appendChild(otherTd);
		} else {
			row.appendChild(el('td', { text: i18n.previewSampleText || 'Sample' }));
		}
		var fieldTd = el('td', { className: 'wtt-set-preview__td-set' });
		fieldTd.appendChild(renderChoiceCatalogControl(n, mode, 'choice-table'));
		row.appendChild(fieldTd);
		if (mode === 'edit') {
			var noteTd = el('td');
			noteTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: 'â€¦',
				})
			);
			row.appendChild(noteTd);
		} else {
			row.appendChild(el('td', { text: 'â€¦' }));
		}
		tbody.appendChild(row);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Automatic CatalogChoice preview (Q90): one Preferred surface only.
	 */
	function renderChoiceCatalogPreview(n) {
		var block = el('div', { className: 'wtt-preview__body' });
		var preferred = effectiveHostPreferredRender(n);
		var roots = choiceCatalogPickRoots(n);
		var mode = resolveCatalogChooserMode(roots, [], 'auto');
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewPreferredOnlyHint ||
					"Preview surface = this node's Preferred (Editable + Display). Nested attribute fields still use their walk / Relation Render (e.g. Kontakt → Table).",
			})
		);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					mode === 'flat'
						? i18n.previewChoiceCatalogListHint ||
						  'CatalogChoice (depth ≤ 1): list chooser — same control as flat type pickers.'
						: i18n.previewChoiceCatalogHint ||
						  'CatalogChoice (depth ≥ 2): tree chooser — same control as nested type pickers.',
			})
		);
		if (preferred === 'TableRenderer') {
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					renderChoiceCatalogTable(n, 'edit'),
					renderChoiceCatalogTable(n, 'display')
				)
			);
			return block;
		}
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsForm || 'Form',
				renderChoiceCatalogForm(n, 'edit'),
				renderChoiceCatalogForm(n, 'display')
			)
		);
		return block;
	}

	function renderChildListPreview(n) {
		var block = el('div', {
			className: 'wtt-preview__body wtt-preview__child-list',
		});
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewChildListHint ||
					'Child list: pick among direct children of this node (same control as CatalogChoice list/tree).',
			})
		);
		var roots = choiceCatalogPickRoots(n);
		if (!roots.length) {
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.previewChoiceCatalogEmpty ||
						'No child options under this node yet.',
				})
			);
			return block;
		}
		block.appendChild(
			renderPreviewSurface(
				i18n.preferredRenderChildList || 'Child list',
				renderChoiceCatalogForm(n, 'edit'),
				renderChoiceCatalogForm(n, 'display')
			)
		);
		return block;
	}

	function renderUnifiedPreviewContent(n) {
		var preferred = effectiveHostPreferredRender(n);
		var attrMembers = attributePreviewMembers(n);
		var childListPref = preferred === 'ChildListRenderer';
		var choiceHost = isAutomaticChoiceCatalogNode(n);

		/*
		 * Law: Preview = host Preferred only — one surface, no Form+Table doubles,
		 * no CatalogChoice stacked on attribute hosts, no name hardcodes.
		 */
		if (childListPref) {
			return renderChildListPreview(n);
		}

		if (attrMembers.length) {
			return renderAttributeHostPreview(n, attrMembers);
		}

		if (choiceHost) {
			return renderChoiceCatalogPreview(n);
		}

		/* Quantity catalog: fake object like Preis (number + select), not CatalogChoice. */
		if (isQuantityTypeCatalogNode(n)) {
			return renderQuantityCatalogPreview(n);
		}

		var members = getPreviewMembers(n);
		var block = el('div', { className: 'wtt-preview__body' });

		/*
		 * Case-study rebuild: no attribute schema yet → empty panel (old unit/media
		 * mega-preview stays off the hot path for hierarchy hosts).
		 */
		if (
			caseStudyMode() &&
			!usesRegistryPreview(n) &&
			!n.isTable &&
			!n.isTableTypeCatalog &&
			!isMediaTypeCatalogNode(n) &&
			!isUnitDefinitionNode(n)
		) {
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.previewRebuildEmpty ||
						'Preview rebuild — add attributes to see Preferred samples.',
				})
			);
			return block;
		}

		if (usesRegistryPreview(n)) {
			return renderRegistryPreviewContent(n);
		}

		/* Media type catalog: Preferred Form or Table of MIME kinds. */
		if (isMediaTypeCatalogNode(n)) {
			return renderMediaKindsPreview(n);
		}

		/* Collection `table` datatype: band skeleton preview (gated by validator). */
		if (n.isTableTypeCatalog || n.isTable) {
			var catalogValidation = resolveTableValidation(n);
			if (catalogValidation && !catalogValidation.ok) {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint wtt-preview__gated',
						text:
							i18n.tableValidationFailed ||
							'Table preview unavailable until the definition is valid.',
					})
				);
				return block;
			}
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.previewPreferredOnlyHint ||
						'Preview uses this node’s Preferred render (Settings → Preferred above).',
				})
			);
			if (n.isTableTypeCatalog) {
				block.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text:
							i18n.tableTypePreviewHint ||
							'Static preview of the table datatype. Bind Kopf / Zeile / Fuss on nodes that use this type.',
					})
				);
			}
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					renderMultiColumnTablePreview(members, 'edit', true),
					renderMultiColumnTablePreview(members, 'display', true)
				)
			);
			return block;
		}

		if (!members || !members.length) {
			block.appendChild(
				el('p', {
					className: 'wtt-preview__unavailable',
					text: i18n.previewUnavailable || 'Preview nicht möglich',
				})
			);
			return block;
		}

		/* Unit catalog node: definition + Preferred usage surface (Q91). */
		if (isUnitDefinitionNode(n)) {
			block.appendChild(renderUnitSchemaDefinition(members));
			var unitLabel = composeUnitDisplay(members);
			if (unitLabel) {
				block.appendChild(
					el('p', {
						className: 'wtt-unit-compose',
						text: (i18n.unitDisplayLabel || 'Unit label') + ': ' + unitLabel,
					})
				);
			}
			var conversions = renderUnitConversions(n, members);
			if (conversions) {
				block.appendChild(conversions);
			}
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.unitUsageHint ||
						'Usage sample when a field uses this unit (value + prefix + symbol).',
				})
			);
			var unitApi = nodeRenderApi();
			var unitScope = 'unit-usage';
			var unitMem = { id: n.id, name: n.name || 'Unit' };
			var unitKey = previewValueKey(unitScope, unitMem);
			if (!Object.prototype.hasOwnProperty.call(state.previewValues, unitKey)) {
				state.previewValues[unitKey] = '10.5';
			}
			var unitProbe = {
				id: n.id,
				name: n.name || '',
				typeKey: 'quantity',
				isBasiseinheitUnit: true,
				quantitySchema: n.quantitySchema || {
					unitId: n.id,
					unitName: n.name || '',
					members: members,
				},
			};
			function paintUnitUsage(mode) {
				if (
					!unitApi ||
					!unitApi.Registry ||
					typeof unitApi.Registry.renderContent !== 'function'
				) {
					return renderUnitUsageForm(members, n.name || '', mode);
				}
				var ctx = {
					name: preferred === 'TableRenderer' ? 'table' : 'form',
					mode: mode === 'display' ? 'display' : 'edit',
					value: String(state.previewValues[unitKey] || ''),
					valueKey: unitKey,
					onInput:
						mode === 'display'
							? null
							: function (next) {
									state.previewValues[unitKey] = next;
									state.previewFocus = {
										key: unitKey,
										start: null,
										end: null,
									};
									renderKeepingPreviewChrome();
							  },
				};
				return (
					unitApi.Registry.renderContent(unitProbe, ctx, mode === 'display') ||
					el('span', { text: '—' })
				);
			}
			var unitTitle =
				preferred === 'TableRenderer'
					? i18n.previewAsTable || 'Table'
					: preferred === 'unit' || preferred === 'quantity'
						? preferred === 'unit'
							? i18n.previewUnit || 'Unit'
							: i18n.previewQuantity || 'Quantity'
						: i18n.previewAsForm || 'Form';
			block.appendChild(
				renderPreviewSurface(
					unitTitle,
					paintUnitUsage('edit'),
					paintUnitUsage('display')
				)
			);
			return block;
		}

		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewPreferredOnlyHint ||
					'Preview uses this node’s Preferred render (Settings → Preferred above).',
			})
		);

		/*
		 * Set form = one labeled row (e.g. "Abmessung (L/B/H)"), members inline —
		 * same composition idea as the single table cell. Non-set keeps stacked fields.
		 * Help is one popover: parent description, then children underneath.
		 */
		var setOpts = n.isSet ? setFieldOptionsFromNode(n) : {};
		if (preferred === 'TableRenderer') {
			var tableEdit;
			var tableDisplay;
			if (n.isTable) {
				var tableVal = resolveTableValidation(n);
				if (tableVal && !tableVal.ok) {
					block.appendChild(
						el('p', {
							className: 'wtt-field-hint wtt-preview__gated',
							text:
								i18n.tableValidationFailed ||
								'Table preview unavailable until the definition is valid.',
						})
					);
					return block;
				}
				var showFooter =
					!!n.hasFooter ||
					boundPropChildId(n, 'fuss') > 0 ||
					!!(tableVal && tableVal.bands && tableVal.bands.fuss);
				tableEdit = renderMultiColumnTablePreview(members, 'edit', showFooter);
				tableDisplay = renderMultiColumnTablePreview(
					members,
					'display',
					showFooter
				);
			} else {
				var tableSetOpts = n.isSet
					? Object.assign({}, setOpts, { asSetField: true })
					: {};
				tableEdit = renderGenericFieldTablePreview(
					members,
					n.name || '',
					'edit',
					tableSetOpts
				);
				tableDisplay = renderGenericFieldTablePreview(
					members,
					n.name || '',
					'display',
					tableSetOpts
				);
			}
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					tableEdit,
					tableDisplay
				)
			);
			return block;
		}

		var formOpts = n.isSet
			? {
					mode: 'edit',
					setName: n.name || '',
					setDescription: n.description || '',
					helpChildren: n.helpChildren || null,
					asSetField: true,
					separator: setOpts.separator,
					joinUnits: setOpts.joinUnits,
					includeChildren: setOpts.includeChildren,
			  }
			: { mode: 'edit' };
		var formDisplayOpts = n.isSet
			? {
					mode: 'display',
					setName: n.name || '',
					setDescription: n.description || '',
					helpChildren: n.helpChildren || null,
					asSetField: true,
					separator: setOpts.separator,
					joinUnits: setOpts.joinUnits,
					includeChildren: setOpts.includeChildren,
			  }
			: { mode: 'display' };
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsForm || 'Form',
				renderSetFormPreview(members, formOpts),
				renderSetFormPreview(members, formDisplayOpts)
			)
		);

		return block;
	}

	function renderNodePreview(n, pane) {
		var block = el('div', { className: 'wtt-panel wtt-set-preview wtt-preview' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-set-preview__title',
				text: i18n.setPreview || 'Preview',
			})
		);
		/* Paint identity/attributes first; mount Preview on the next frames. */
		var mount = el('div', { className: 'wtt-preview__deferred' });
		mount.appendChild(
			el('p', {
				className: 'description',
				text: i18n.loading || 'Loading…',
			})
		);
		block.appendChild(mount);
		pane.appendChild(block);
		var seq = selectSeq;
		var nodeId = parseInt(n && n.id, 10) || 0;
		var schedule =
			typeof window.requestAnimationFrame === 'function'
				? window.requestAnimationFrame.bind(window)
				: function (cb) {
						return window.setTimeout(cb, 0);
				  };
		schedule(function () {
			schedule(function () {
				if (seq !== selectSeq || !sameTermId(state.selectedId, nodeId)) {
					return;
				}
				mount.textContent = '';
				var live = viewNode() || n;
				mount.appendChild(renderUnifiedPreviewContent(live));
			});
		});
	}

	function renderTrashPanel(n) {
		var block = el('div', { className: 'wtt-panel wtt-trash-panel' });
		block.appendChild(
			renderRelationsSectionTitle(
				i18n.trashTitle || 'Trash',
				i18n.trashHelp ||
					'Soft-deleted nodes keep their parent/child links. They appear only here. Empty Trash permanently deletes them.',
				'wtt-trash-panel__title-wrap'
			)
		);
		var count = parseInt(n.trashCount, 10) || 0;
		var roots = Array.isArray(n.trashItems) ? n.trashItems : [];
		block.appendChild(
			el('p', {
				className: 'description',
				text:
					(i18n.trashCountLabel || 'Deleted objects') +
					': ' +
					String(count) +
					(roots.length
						? ' (' +
						  String(roots.length) +
						  ' ' +
						  (i18n.trashRootsLabel || 'roots') +
						  ')'
						: ''),
			})
		);
		if (roots.length) {
			var list = el('ul', { className: 'wtt-trash-panel__list' });
			roots.forEach(function (item) {
				list.appendChild(
					el('li', {
						text: (item && (item.path || item.name)) || String(item && item.id),
					})
				);
			});
			block.appendChild(list);
		} else if (!count) {
			block.appendChild(
				el('p', {
					className: 'wtt-empty',
					text: i18n.trashEmpty || 'Trash is empty.',
				})
			);
		}
		var emptyBtn = el('button', {
			type: 'button',
			className: 'button button-secondary',
			text: i18n.trashEmptyAction || 'Empty Trash',
			disabled: count <= 0,
			onClick: function () {
				if (count <= 0) {
					return;
				}
				if (
					!window.confirm(
						i18n.trashEmptyConfirm ||
							'Permanently delete all soft-deleted nodes? This cannot be undone.'
					)
				) {
					return;
				}
				post('wtt_empty_trash', {})
					.then(function (json) {
						if (!json || !json.success) {
							setError(
								(json && json.data && json.data.message) || i18n.error
							);
							return;
						}
						state.tree = json.data.tree || [];
						state.error = '';
						if (state.selectedId) {
							return post('wtt_get_node', {
								term_id: state.selectedId,
							}).then(function (nodeJson) {
								if (nodeJson && nodeJson.success && nodeJson.data && nodeJson.data.node) {
									state.selectedNode = nodeJson.data.node;
									state.draft = settingsFromNode(nodeJson.data.node);
									state.savedDraft = deepClone(state.draft);
								}
								persistTreeUi();
								render();
							});
						}
						persistTreeUi();
						render();
					})
					.catch(function () {
						setError(i18n.error);
					});
			},
		});
		block.appendChild(emptyBtn);
		return block;
	}

	function renderHiddenPanel(n) {
		var block = el('div', { className: 'wtt-panel wtt-hidden-panel' });
		block.appendChild(
			renderRelationsSectionTitle(
				i18n.hiddenTitle || 'Hidden nodes',
				i18n.hiddenHelp ||
					'Nodes marked hidden stay in the database with their parent links, but are omitted from the normal tree. Unhide to restore them.',
				'wtt-hidden-panel__title-wrap'
			)
		);
		var count = parseInt(n.hiddenCount, 10) || 0;
		var items = Array.isArray(n.hiddenItems) ? n.hiddenItems : [];
		block.appendChild(
			el('p', {
				className: 'description',
				text: (i18n.hiddenCountLabel || 'Hidden objects') + ': ' + String(count),
			})
		);
		if (items.length) {
			var list = el('ul', { className: 'wtt-hidden-panel__list' });
			items.forEach(function (item) {
				var id = item && item.id != null ? parseInt(item.id, 10) || 0 : 0;
				var row = el('li', { className: 'wtt-hidden-panel__item' });
				row.appendChild(
					el('button', {
						type: 'button',
						className: 'button-link',
						text: (item && (item.path || item.name)) || String(id),
						onClick: function () {
							if (id > 0) {
								selectNode(id);
							}
						},
					})
				);
				row.appendChild(document.createTextNode(' '));
				row.appendChild(
					el('button', {
						type: 'button',
						className: 'button button-small',
						text: i18n.unhideNode || 'Show again',
						title:
							i18n.unhideNodeHint ||
							'Restore this node to the tree under its parent.',
						onClick: function () {
							if (id > 0) {
								unhideNodeById(id);
							}
						},
					})
				);
				list.appendChild(row);
			});
			block.appendChild(list);
		} else {
			block.appendChild(
				el('p', {
					className: 'wtt-empty',
					text: i18n.hiddenEmpty || 'No hidden nodes.',
				})
			);
		}
		return block;
	}

	/**
	 * Config page boxes (Q126) — registered once; ConfigPageRender stacks them.
	 * Changing a box updates every host that calls renderPage.
	 */
	function configCtxLocked(ctx) {
		return !!(ctx && ctx.locked);
	}

	function renderConfigActionsBox(node, ctx) {
		var frag = document.createDocumentFragment();
		frag.appendChild(
			renderDetailToolbar(
				node,
				!!(ctx && ctx.dirty),
				configCtxLocked(ctx)
			)
		);
		if (node.isTrash) {
			frag.appendChild(renderTrashPanel(node));
		}
		if (node.isHiddenBin) {
			frag.appendChild(renderHiddenPanel(node));
		}
		if (node.isTable || node.isTableTypeCatalog) {
			var tableValTop = resolveTableValidation(node);
			if (tableValTop && !tableValTop.ok) {
				frag.appendChild(renderTableValidationBanner(tableValTop, node));
			}
		}
		if (node.setParent && node.setParent.id) {
			var parentLink = el('button', {
				type: 'button',
				className: 'button-link',
				text: node.setParent.name || String(node.setParent.id),
				onClick: function () {
					selectNode(node.setParent.id);
				},
			});
			var parentLine = el('p', { className: 'wtt-set-parent' });
			parentLine.appendChild(
				document.createTextNode(
					(i18n.setParent || 'Member of set') + ': '
				)
			);
			parentLine.appendChild(parentLink);
			frag.appendChild(parentLine);
		}
		return frag;
	}

	function renderConfigMetaBox(node, ctx) {
		var form = el('div', {
			className:
				'wtt-form wtt-detail wtt-panel wtt-detail-form wtt-config-meta',
		});
		var parentId = parseInt(node.parent, 10) || 0;
		var parentStatic =
			parentId > 0
				? renderMetaStatic({
						label: i18n.parent || 'Parent',
						value: node.parentName || String(parentId),
						title:
							i18n.goToParent ||
							'Open parent in tree and settings',
						onClick: function () {
							selectNode(parentId);
						},
				  })
				: renderMetaStatic({
						label: i18n.parent || 'Parent',
						value: i18n.none || '—',
				  });

		var staticItems = [
			renderMetaStatic({
				label: i18n.termId || 'ID',
				value: node.id != null ? String(node.id) : '—',
				title: i18n.termIdHint || '',
				metaKey: 'id',
			}),
			parentStatic,
			renderMetaStatic({
				label: i18n.slug || 'Slug',
				value: node.slug || 'Slug',
				title: i18n.slugHint || '',
				metaKey: 'slug',
			}),
		];
		if (node.modified && (node.modified.userName || node.modified.atLabel)) {
			var modBy =
				node.modified.userName ||
				(node.modified.userId
					? '#' + String(node.modified.userId)
					: i18n.none || '—');
			staticItems.push(
				renderMetaStatic({
					label: i18n.lastModifiedBy || 'Last modified by',
					value: modBy,
					title: node.modified.atLabel
						? (i18n.lastModifiedAt || 'Last modified') +
						  ': ' +
						  node.modified.atLabel
						: '',
				})
			);
			if (node.modified.atLabel) {
				staticItems.push(
					renderMetaStatic({
						label: i18n.lastModifiedAt || 'Last modified',
						value: node.modified.atLabel,
					})
				);
			}
		}
		if (!caseStudyMode()) {
			staticItems.push(
				renderMetaStatic({
					label: i18n.count || 'Assigned posts',
					value: node.count != null ? node.count : 0,
				})
			);
		}
		var staticStrip = renderMetaStrip('static', staticItems);
		if (flagsAsFormRowEnabled()) {
			staticStrip.classList.add('wtt-form__meta-strip--in-row');
			form.appendChild(
				formRow(i18n.nodeMeta || 'Meta', [staticStrip], {
					className: 'wtt-form__row--meta',
					help: i18n.nodeMetaHint || '',
				})
			);
		} else {
			form.appendChild(staticStrip);
		}
		return form;
	}

	function renderConfigIdentitySettingsBox(node, ctx) {
		var locked = configCtxLocked(ctx);
		var form = el('div', {
			className:
				'wtt-form wtt-detail wtt-panel wtt-detail-form wtt-config-identity-settings',
		});

		var nameInput = el('input', {
			type: 'text',
			id: 'wtt-node-name',
			className: 'wtt-name-input regular-text',
			value: node.name || '',
		});
		if (locked) {
			nameInput.disabled = true;
		}
		nameInput.addEventListener('input', function (e) {
			setDraftName(e.target.value, { silent: true });
		});

		var identitySection = el('div', {
			className: 'wtt-form-section wtt-form-section--identity',
		});
		identitySection.appendChild(
			el('h4', {
				className: 'wtt-form-section__title',
				text: i18n.nodeIdentity || 'Identity',
			})
		);
		identitySection.appendChild(
			el('p', {
				className: 'wtt-form-section__hint',
				text:
					i18n.nodeIdentityHint ||
					'Core properties of this node (name, defaults). Flags and Kindknoten are separate boxes.',
			})
		);
		identitySection.appendChild(
			formRow(i18n.name || 'Name', [nameInput], {
				htmlFor: 'wtt-node-name',
				help: i18n.nameHint || '',
			})
		);
		form.appendChild(identitySection);

		/* Q88: no Data type row — hierarchy datatype = parent. */
		if (typeUsesRefScope(node)) {
			var blockedSelf = {};
			if (node.id) {
				blockedSelf[String(node.id)] = true;
			}
			var scopePicker = renderNodeTreePicker({
				roots: state.tree,
				selectedId: node.refScopeId || 0,
				selectedLabel:
					(node.refScope && node.refScope.name) ||
					(function () {
						var scopeNode = findNodeInTree(
							state.tree,
							node.refScopeId || 0
						);
						return scopeNode ? scopeNode.name : '';
					})(),
				compact: true,
				defaultOpen: !!(node.refScopeId || 0),
				expandKey: 'ref-scope:' + String(node.id || 0),
				allowRoot: false,
				allowClear: true,
				disabled: !!locked,
				blockedIds: blockedSelf,
				pickedPrefix: i18n.nodePickerSelected || 'Selected:',
				placeholder: i18n.refScopeChoose || 'Choose catalog root…',
				dialogTitle: i18n.refScope || 'Catalog root (ref_scope)',
				onSelect: function (id) {
					setDraftRefScope(id);
				},
			});
			var scopeHelp =
				typeKeyFromMember(node) === 'node_ref'
					? i18n.refScopeHintNodeRef ||
					  'node_ref: pick only among descendants under this root.'
					: i18n.refScopeHintEmbed ||
					  'node_embed: direct children of this root are selectable; their fields are embedded after pick.';
			form.appendChild(
				formRow(
					i18n.refScope || 'Catalog root (ref_scope)',
					[scopePicker],
					{ help: scopeHelp }
				)
			);

			var multCurrent = String(node.fieldMultiplicity || '0..1');
			var multSelect = el('select', {
				className: 'wtt-field-multiplicity-select',
				disabled: !!locked,
				title:
					i18n.fieldMultiplicityHint ||
					'How many targets this field may pick at runtime (1..* = multi-select). Not the Mult. on ref_scope relations.',
				onChange: function (e) {
					setDraftFieldMultiplicity(e.target.value);
				},
			});
			relationMultiplicityOptions(node).forEach(function (opt) {
				multSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.value,
						selected: opt.value === multCurrent,
					})
				);
			});
			form.appendChild(
				formRow(
					i18n.fieldMultiplicity || 'Field multiplicity',
					[multSelect],
					{
						help:
							i18n.fieldMultiplicityHint ||
							'Runtime picks: 0..1 / 1 = one id; 0..* / 1..* = many ids. Distinct from Relations Mult. on ref_scope.',
					}
				)
			);

			if (node.refScopeId) {
				var candidates = catalogChildCandidates(node.refScopeId);
				var allowedSet = {};
				var hasExplicitAllow =
					Array.isArray(node.allowedRefIds) &&
					node.allowedRefIds.length > 0;
				(node.allowedRefIds || []).forEach(function (id) {
					allowedSet[String(id)] = true;
				});
				var allowWrap = el('div', { className: 'wtt-ref-allowlist' });
				if (!candidates.length) {
					allowWrap.appendChild(
						el('p', {
							className: 'wtt-field-hint',
							text:
								i18n.allowedRefEmpty ||
								'Catalog root has no direct children yet.',
						})
					);
				} else {
					candidates.forEach(function (child) {
						if (!child || child.id == null) {
							return;
						}
						var cid = String(child.id);
						var checkId = 'wtt-allowed-ref-' + cid;
						var label = el('label', {
							className: 'wtt-checkbox-label',
							htmlFor: checkId,
						});
						var check = el('input', {
							type: 'checkbox',
							id: checkId,
							className: 'wtt-allowed-ref-check',
						});
						check.checked =
							!hasExplicitAllow || !!allowedSet[cid];
						if (locked) {
							check.disabled = true;
						}
						check.addEventListener('change', function () {
							var next = [];
							var boxes = allowWrap.querySelectorAll(
								'.wtt-allowed-ref-check'
							);
							var allOn = true;
							boxes.forEach(function (box) {
								if (box.checked) {
									var idAttr = box.id || '';
									var id =
										parseInt(
											idAttr.replace(
												'wtt-allowed-ref-',
												''
											),
											10
										) || 0;
									if (id > 0) {
										next.push(id);
									}
								} else {
									allOn = false;
								}
							});
							setDraftAllowedRefIds(allOn ? [] : next);
						});
						label.appendChild(check);
						label.appendChild(
							document.createTextNode(
								' ' + (child.name || '#' + child.id)
							)
						);
						allowWrap.appendChild(label);
					});
				}
				form.appendChild(
					formRow(
						i18n.allowedRefChildren || 'Allowed catalog children',
						[allowWrap],
						{
							help:
								i18n.allowedRefHint ||
								'Which direct children of the catalog root may be picked. Default: all. Shared by node_embed and node_ref (Q73).',
						}
					)
				);
			}
		}

		if (i18n.typePresetsHint && isUnderTypenBranch(node.id)) {
			form.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text: i18n.typePresetsHint,
				})
			);
		}

		if (shouldShowDefaultValue(node)) {
			var defaultMuted = !canEditDefaultValue(node) || !!locked;
			var defaultControl = renderFixedValueField(
				node,
				locked || defaultMuted,
				defaultMuted && isBuiltinCatalogLeaf(node)
			);
			if (defaultControl) {
				form.appendChild(
					formRow(
						i18n.attributesFixedTitle ||
							i18n.fixedValue ||
							'Default value',
						[defaultControl],
						{
							className:
								'wtt-form__row--fixed wtt-form__row--default' +
								(defaultMuted ? ' is-muted' : ''),
							help: defaultValueFieldHelpText(node),
						}
					)
				);
			}
		}

		var footerOpRow = renderFooterOpPicker(node, locked);
		if (footerOpRow) {
			form.appendChild(footerOpRow);
		}

		/* Non-bool type extras — Kindknoten (prefixes) live in childNodes box. */
		var extras = el('div', { className: 'wtt-config-type-extras' });
		if (node.isTable || node.isTableTypeCatalog) {
			var bandUi = renderTableBandBindings(node, locked);
			if (bandUi) {
				extras.appendChild(bandUi);
			}
		}
		if (!caseStudyMode()) {
			renderChildExtrasOnParent(node, extras);
			renderSetMembers(node, extras);
			renderTypeBranch(node, extras);
			renderSetSettings(node, extras);
			renderEnumValuesSettings(node, extras, locked);
			renderMediaSettings(node, extras);
			renderDateSettings(node, extras);
			renderTextareaSettings(node, extras);
			renderPresentationTypeSettings(node, extras);
		} else if (node.isSet) {
			renderSetMembers(node, extras);
		} else if (node.isConcreteEnum) {
			renderEnumValuesSettings(node, extras, locked);
		}
		/*
		 * Fallstudie slim UI still needs type-config that gates Preview paint:
		 * media MIME kinds, date mode, presentation context.
		 */
		if (caseStudyMode()) {
			renderMediaSettings(node, extras);
			renderDateSettings(node, extras);
			renderTextareaSettings(node, extras);
			renderPresentationTypeSettings(node, extras);
		}
		if (extras.childNodes.length) {
			form.appendChild(extras);
		}

		return form;
	}

	function canRenderConfigChildNodes(node) {
		return !!(node && node.isBasiseinheitUnit);
	}

	function renderConfigChildNodesBox(node, ctx) {
		if (!canRenderConfigChildNodes(node)) {
			return null;
		}
		var wrap = el('div', { className: 'wtt-config-child-nodes' });
		renderAllowedPrefixesWizard(node, wrap);
		return wrap.childNodes.length ? wrap : null;
	}

	function renderConfigBoolsBox(node, ctx) {
		var locked = configCtxLocked(ctx);
		var isDisplayName = isNodePresentationTypeKey(typeKeyFromMember(node));
		var flagItems = [];

		/* RO lives in Bools strip (5/row); gray on non-slots / Simple hosts (Q115). */
		if (shouldShowReadonly(node)) {
			var roEditable = canEditReadonly(node) && !locked;
			flagItems.push({
				label:
					i18n.nodeReadonly ||
					i18n.attributesReadonlyTitle ||
					'Read-only',
				checked: !!node.readonly,
				disabled: !roEditable,
				title: roEditable
					? i18n.nodeReadonlyHint ||
					  i18n.attributesReadonlyHint ||
					  'When on, this field is not editable in forms.'
					: i18n.nodeReadonlyGrayHint ||
					  'Read-only applies to attribute slots. Grayed on type catalog leaves and structure hosts.',
				onChange: roEditable ? setDraftReadonly : undefined,
			});
		}

		flagItems.push({
			label: i18n.isTemplate || 'Is template',
			checked: !!node.isTemplate,
			disabled: !isDevelopmentMode() || !!locked,
			title: i18n.isTemplateHint || '',
			onChange: isDevelopmentMode() ? setDraftIsTemplate : undefined,
		});

		if (!(caseStudyMode() || isDisplayName)) {
			flagItems.push({
				label: i18n.required || 'Required',
				checked: !!node.required,
				disabled: !!locked,
				title: i18n.requiredHint || '',
				onChange: setDraftRequired,
			});
		}

		return renderNodeFlagsRow(flagItems, {
			help: i18n.nodeFlagsHint || '',
		});
	}

	function renderConfigDisplayBox(node, ctx) {
		var locked = configCtxLocked(ctx);
		var iconOptions = Array.isArray(cfg.treeIcons) ? cfg.treeIcons : [];
		var currentIcon = node.icon != null ? String(node.icon) : '';
		var iconWrap = renderIconListChooser(iconOptions, {
			id: 'wtt-node-icon',
			selectedKey: currentIcon,
			disabled: !!locked,
			noneLabel: i18n.iconNone || 'No icon',
			onChange: function (key) {
				setDraftIcon(key);
			},
		});
		var displayChildren = [
			renderDisplayIconPresentationRow(node, iconWrap, locked),
		];
		var SR = settingsRender();
		var displaySection;
		if (SR && typeof SR.renderSection === 'function') {
			displaySection = SR.renderSection({
				title: i18n.nodeDisplay || 'Display',
				hint:
					i18n.nodeDisplayHint ||
					'Presentation, Preferred render/converter, and validators (Q118).',
				className: 'wtt-form-section--display',
				children: displayChildren,
			});
		} else {
			displaySection = el('div', {
				className: 'wtt-form-section wtt-form-section--display',
			});
			displaySection.appendChild(
				el('h4', {
					className: 'wtt-form-section__title',
					text: i18n.nodeDisplay || 'Display',
				})
			);
			displaySection.appendChild(
				el('p', {
					className: 'wtt-form-section__hint',
					text:
						i18n.nodeDisplayHint ||
						'Presentation, Preferred render/converter, and validators (Q118).',
				})
			);
			displayChildren.forEach(function (child) {
				if (child) {
					displaySection.appendChild(child);
				}
			});
		}
		renderPreferredConverterRow(node, displaySection, locked);
		var wrap = el('div', {
			className: 'wtt-form wtt-detail wtt-config-display',
		});
		wrap.appendChild(displaySection);
		return wrap;
	}

	function renderConfigAttributesBox(node, ctx) {
		var locked = configCtxLocked(ctx);
		var wrap = el('div', { className: 'wtt-config-attributes' });
		renderNodeAttributes(node, wrap, locked);
		return wrap.childNodes.length ? wrap : null;
	}

	function renderConfigPreviewBox(node, ctx) {
		if (
			isUnderRelationstypenBranch(node.id) ||
			node.isTrash ||
			node.isHiddenBin
		) {
			return null;
		}
		var wrap = el('div', { className: 'wtt-config-preview' });
		try {
			renderNodePreview(node, wrap);
		} catch (previewErr) {
			wrap.appendChild(
				el('div', { className: 'wtt-panel wtt-preview' }, [
					el('h3', {
						className: 'wtt-panel__title',
						text: i18n.setPreview || 'Preview',
					}),
					el('p', {
						className: 'wtt-error',
						text:
							(i18n.error || 'Something went wrong.') +
							' ' +
							String(
								previewErr && previewErr.message
									? previewErr.message
									: previewErr
							),
					}),
				])
			);
		}
		return wrap.childNodes.length ? wrap : null;
	}

	function renderConfigRelationsBox(node, ctx) {
		var wrap = el('div', { className: 'wtt-config-relations' });
		renderNodeRelations(node, wrap);
		return wrap.childNodes.length ? wrap : null;
	}

	var configBoxesRegistered = false;

	function ensureConfigBoxesRegistered() {
		var CR = configRender();
		if (!CR || typeof CR.registerBox !== 'function') {
			return false;
		}
		if (configBoxesRegistered) {
			return true;
		}
		CR.registerBox('actions', {
			render: function (node, ctx) {
				return renderConfigActionsBox(node, ctx);
			},
		});
		CR.registerBox('meta', {
			render: function (node, ctx) {
				return renderConfigMetaBox(node, ctx);
			},
		});
		CR.registerBox('identitySettings', {
			render: function (node, ctx) {
				return renderConfigIdentitySettingsBox(node, ctx);
			},
		});
		CR.registerBox('bools', {
			render: function (node, ctx) {
				return renderConfigBoolsBox(node, ctx);
			},
		});
		CR.registerBox('childNodes', {
			canRender: function (node, ctx) {
				return canRenderConfigChildNodes(node, ctx);
			},
			render: function (node, ctx) {
				return renderConfigChildNodesBox(node, ctx);
			},
		});
		CR.registerBox('display', {
			render: function (node, ctx) {
				return renderConfigDisplayBox(node, ctx);
			},
		});
		CR.registerBox('attributes', {
			render: function (node, ctx) {
				return renderConfigAttributesBox(node, ctx);
			},
		});
		CR.registerBox('preview', {
			render: function (node, ctx) {
				return renderConfigPreviewBox(node, ctx);
			},
		});
		CR.registerBox('relations', {
			render: function (node, ctx) {
				return renderConfigRelationsBox(node, ctx);
			},
		});
		configBoxesRegistered = true;
		return true;
	}

	/**
	 * Node detail = shared ConfigPageRender (Q126).
	 * Do not paint config chrome outside renderPage — one page everywhere.
	 */
	function renderDetail() {
		var pane = el('div', { className: 'wtt-detail-pane' });
		try {
			if (state.error) {
				pane.appendChild(
					el('p', { className: 'wtt-error', text: state.error })
				);
			}
			if (!state.selectedId) {
				pane.appendChild(
					el('p', { className: 'wtt-empty', text: i18n.selectHint })
				);
				return pane;
			}
			if (!state.selectedNode) {
				pane.appendChild(
					el('p', { className: 'wtt-empty', text: i18n.loading })
				);
				return pane;
			}

			var n = viewNode();
			var dirty = isSettingsDirty();
			var controlsLocked =
				saveViaButtonEnabled() && !!state.settingsSaving;
			var CR = configRender();
			ensureConfigBoxesRegistered();

			if (CR && typeof CR.renderPage === 'function') {
				pane.appendChild(
					CR.renderPage(n, {
						locked: controlsLocked,
						dirty: dirty,
						surface: 'nodeDetail',
					})
				);
			} else {
				/* Fallback if shared module failed to load — still one stack order. */
				var fbCtx = { locked: controlsLocked, dirty: dirty };
				pane.appendChild(renderConfigActionsBox(n, fbCtx));
				pane.appendChild(renderConfigMetaBox(n, fbCtx));
				pane.appendChild(renderConfigIdentitySettingsBox(n, fbCtx));
				var bools = renderConfigBoolsBox(n, fbCtx);
				if (bools) {
					pane.appendChild(bools);
				}
				var kids = renderConfigChildNodesBox(n, fbCtx);
				if (kids) {
					pane.appendChild(kids);
				}
				pane.appendChild(renderConfigDisplayBox(n, fbCtx));
				var attrs = renderConfigAttributesBox(n, fbCtx);
				if (attrs) {
					pane.appendChild(attrs);
				}
				var prev = renderConfigPreviewBox(n, fbCtx);
				if (prev) {
					pane.appendChild(prev);
				}
				var rels = renderConfigRelationsBox(n, fbCtx);
				if (rels) {
					pane.appendChild(rels);
				}
			}
		} catch (err) {
			pane.appendChild(
				el('p', {
					className: 'wtt-error',
					text:
						(i18n.error || 'Something went wrong.') +
						' ' +
						String(
							err && err.message ? err.message : err
						),
				})
			);
		}
		return pane;
	}

	function applyDemoTree(tree) {
		state.tree = tree || [];
		state.selectedId = null;
		state.selectedIds = {};
		state.selectionAnchorId = null;
		state.selectedNode = null;
		state.relationsPanelOpen = false;
		state.attributesPanelOpen = true;
		state.relationCatalog = null;
		state.relationCatalogLoading = null;
		state.draft = null;
		state.savedDraft = null;
		state.settingsSaving = false;
		state.error = '';
		state.expanded = {};
		(state.tree || []).forEach(function (n) {
			if (!n || !n.id) {
				return;
			}
			if (n.name === 'Fallstudie') {
				state.expanded[n.id] = true;
			}
		});
		persistTreeUi();
		render();
	}

	function render() {
		var root = document.getElementById('wtt-app');
		var badge = document.getElementById('wtt-badge');
		var intro = document.getElementById('wtt-intro');
		if (!root) {
			return;
		}
		var paneScroll = capturePaneScroll();
		if (badge) {
			badge.textContent = i18n.scaffoldBadge || 'Scaffold 0.0.1';
		}

		root.innerHTML = '';

		var taxList = cfg.taxonomies || [];
		var toolbarChildren = [];
		if (taxList.length > 1) {
			var taxSelect = el('select', {
				id: 'wtt-taxonomy',
				onChange: function (e) {
					persistTreeUi();
					var slug = e.target.value;
					var url = new URL(window.location.href);
					url.searchParams.set('page', 'wp-taxonomy-tree');
					url.searchParams.set('taxonomy', slug);
					window.location.href = url.toString();
				}
			});
			taxList.forEach(function (tax) {
				var opt = el('option', { value: tax.slug, text: tax.label });
				if (tax.slug === state.taxonomy) {
					opt.selected = true;
				}
				taxSelect.appendChild(opt);
			});
			toolbarChildren.push(
				el('label', { text: i18n.taxonomy + ' ', htmlFor: 'wtt-taxonomy' }),
				taxSelect
			);
		}
		var iconGroup = el('div', { className: 'wtt-toolbar__icons' }, [
			toolbarIconButton(
				'plus-alt2',
				i18n.expandAllHint || i18n.expandAll || 'Expand all nodes',
				expandAllTree
			),
			toolbarIconButton(
				'minus',
				i18n.collapseAllHint || i18n.collapseAll || 'Collapse all nodes',
				collapseAllTree
			),
		]);
		toolbarChildren.push(iconGroup);
		toolbarChildren.push(
			el('div', { className: 'wtt-toolbar__toggles' }, [
				renderSlideSwitch({
					checked: !!state.hideRootNode,
					text: i18n.hideRootNode || 'Hide root',
					title: i18n.hideRootNodeHint || '',
					onChange: function (on) {
						state.hideRootNode = !!on;
						cfg.hideRootNode = state.hideRootNode;
						render();
						post('wtt_set_hide_root_node', {
							enabled: state.hideRootNode ? '1' : '0',
						}).catch(function () {
							/* keep local toggle; settings page remains source of truth on reload */
						});
					},
				}),
				renderSlideSwitch({
					checked: !!state.showModelDataCounts,
					text: i18n.showModelDataCounts || 'Counts',
					title: i18n.showModelDataCountsHint || '',
					onChange: function (on) {
						state.showModelDataCounts = !!on;
						cfg.showModelDataCounts = state.showModelDataCounts;
						render();
						post('wtt_set_show_model_data_counts', {
							enabled: state.showModelDataCounts ? '1' : '0',
						}).catch(function () {
							/* keep local toggle; settings page remains source of truth on reload */
						});
					},
				}),
			])
		);
		var toolbar = el('div', { className: 'wtt-toolbar' }, toolbarChildren);

		var displayRoots = getDisplayTreeRoots();
		var treeList = el('ul', { className: 'wtt-tree' });
		if (!displayRoots.length) {
			treeList.appendChild(el('li', { className: 'wtt-empty', text: i18n.empty }));
		} else {
			renderTreeNodes(displayRoots, treeList);
		}

		var treePane = el('div', { className: 'wtt-tree-pane' }, [toolbar, treeList]);
		root.appendChild(treePane);

		try {
			root.appendChild(renderDetail());
		} catch (err) {
			var fallback = el('div', { className: 'wtt-detail-pane' });
			fallback.appendChild(
				el('p', {
					className: 'wtt-error',
					text: (i18n.error || 'Something went wrong.') + ' ' + String(err && err.message ? err.message : err),
				})
			);
			root.appendChild(fallback);
		}

		if (intro) {
			if (state.selectedNode && state.selectedNode.name) {
				intro.textContent =
					(i18n.inspecting || 'Inspecting:') + ' ' + displayNodeName(state.selectedNode);
			} else if (state.selectedId) {
				intro.textContent = i18n.loading || 'Loading...';
			} else {
				intro.textContent = i18n.selectHint || '';
			}
		}
		restorePaneScroll(paneScroll);
	}

	function isEditableTarget(el) {
		if (!el || !el.tagName) {
			return false;
		}
		var tag = String(el.tagName).toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select') {
			return true;
		}
		if (el.isContentEditable) {
			return true;
		}
		return false;
	}

	function onTreeCopyKeydown(e) {
		if (!e || !(e.ctrlKey || e.metaKey)) {
			return;
		}
		if (String(e.key || '').toLowerCase() !== 'c') {
			return;
		}
		if (isEditableTarget(e.target)) {
			return;
		}
		var app = document.getElementById('wtt-app');
		if (!app) {
			return;
		}
		/* Allow copy when focus is in the tree admin app (not unrelated page fields). */
		if (!app.contains(e.target) && e.target !== document.body && e.target !== document.documentElement) {
			return;
		}
		var ids = getSelectedIdList();
		if (!ids.length && !state.selectedId) {
			return;
		}
		e.preventDefault();
		copySelected();
	}

	function boot() {
		if (window.WTTObjectRender && typeof window.WTTObjectRender.setSchemaLoader === 'function') {
			window.WTTObjectRender.setSchemaLoader(function (termId) {
				return post('wtt_get_node', { term_id: termId }).then(function (json) {
					var node =
						json && json.success && json.data && json.data.node
							? json.data.node
							: null;
					return {
						attributes: (node && node.attributes) || [],
					};
				});
			});
		}
		if (window.WTTNodeRender && typeof window.WTTNodeRender.configure === 'function') {
			window.WTTNodeRender.configure({
				catalogBindings: (cfg && cfg.catalogBindings) || {},
				resolveTypeKey: function (node) {
					return resolveNodeRenderTypeKey(node);
				},
				i18n: {
					boolTrue: i18n.boolTrue || 'true',
					boolFalse: i18n.boolFalse || 'false',
					previewFooter: i18n.previewFooter || 'Footer',
					previewColGeneric: i18n.previewColGeneric || 'Column',
					emailInvalid: i18n.emailInvalid || 'Enter a valid email address.',
					intInvalid: i18n.intInvalid || 'Enter a whole number.',
					embedPickHint: i18n.embedPickHint || 'Choose kind…',
					embedNoChoices:
						i18n.embedNoChoices ||
						'No specialization children under this node.',
					embedLoading: i18n.embedLoading || 'Loading…',
					embedNoFields:
						i18n.embedNoFields || 'Selected node has no attributes.',
				},
			});
		}
		if (window.WTTObjectRender && typeof window.WTTObjectRender.configure === 'function') {
			window.WTTObjectRender.configure({
				taxonomy: state.taxonomy || (cfg && cfg.taxonomy) || '',
				i18n: {
					embedPickHint: i18n.embedPickHint || 'Choose kind…',
					embedNoChoices:
						i18n.embedNoChoices ||
						'No specialization children under this node.',
					embedLoading: i18n.embedLoading || 'Loading…',
					embedNoFields:
						i18n.embedNoFields || 'Selected node has no attributes.',
					embedPickPart: i18n.embedPickPart || 'Pick part…',
					embedChangePart: i18n.embedChangePart || 'Change…',
					embedPhaseATitle: i18n.embedPhaseATitle || 'Choose part kind',
					embedPhaseBTitle: i18n.embedPhaseBTitle || 'Pick or create part',
					embedCreateBind: i18n.embedCreateBind || 'Create and bind',
					embedRequiredEmpty:
						i18n.embedRequiredEmpty ||
						'Required — pick or create a part.',
				},
				/*
				 * TODO(UR-B6): wire tree-admin preview Model_Data list/create via REST
				 * (Fill Model Data AJAX nonce differs). Full Phase B works on Fill Model Data.
				 */
			});
		}
		restoreTreeUi();
		document.addEventListener('keydown', onTreeCopyKeydown);
		var bootTerm =
			cfg.initialTermId != null ? parseInt(cfg.initialTermId, 10) || 0 : 0;
		if (bootTerm > 0) {
			selectNode(bootTerm);
		} else if (state.selectedId) {
			selectNode(state.selectedId);
		} else {
			render();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();


