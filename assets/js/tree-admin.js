(function () {
	'use strict';

	var cfg = window.wttTree || {};
	var i18n = cfg.i18n || {};
	var state = {
		taxonomy: cfg.taxonomy || 'wtt_tree',
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
		/* Attribute Options detail rows: expand per attr id (UI session only). */
		attrDetailExpanded: {},
	};
	var autosaveTimer = null;
	var autosaveSeq = 0;
	var dragDidMove = false;
	/* Serialize settings saves; latest draft per term wins (no cross-term overwrite). */
	var settingsSaveChain = Promise.resolve();
	var settingsSavePending = {};

	var DEFAULT_TABLE_TYPE_PROPS = [
		{ id: 'kopf', key: 'kopf', name: 'Kopf', valueType: 'subnode', required: false },
		{ id: 'zeile', key: 'zeile', name: 'Zeile', valueType: 'subnode', required: true },
		{ id: 'fuss', key: 'fuss', name: 'Fuss', valueType: 'subnode', required: false },
	];

	/**
	 * Band bindings must be a plain object. PHP encodes empty bindings as JSON [],
	 * and assigning keys onto an Array + JSON.stringify/deepClone drops them ("[]").
	 * That looked Bom-only when Bom was unbound while Partner already had {"zeile":…}.
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
		return 'wtt.treeUi.v1.' + String(taxonomy || 'wtt_tree');
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

	function normalizePreferredRender(raw) {
		var key = String(raw || 'form').toLowerCase();
		if (key === 'compact-h' || key === 'compact-horizontal') {
			key = 'compact';
		}
		if (key === 'compact-v') {
			key = 'compact-vertical';
		}
		if (key === 'list') {
			key = 'table';
		}
		if (
			key === 'form' ||
			key === 'table' ||
			key === 'compact' ||
			key === 'compact-vertical'
		) {
			return key;
		}
		return 'form';
	}

	function settingsFromNode(n) {
		return {
			name: n.name != null ? String(n.name) : '',
			slug: n.slug != null ? String(n.slug) : '',
			description: n.description != null ? String(n.description) : '',
			shortDescription: n.shortDescription != null ? String(n.shortDescription) : '',
			typeId: n.typeId || 0,
			ownTypeId: n.ownTypeId != null ? n.ownTypeId : n.typeId || 0,
			typeInheriting: !!n.typeInheriting,
			typeOverride: !!n.typeOverride,
			canInheritType: !!n.canInheritType,
			inheritedTypeId: n.inheritedTypeId || 0,
			typeIsParent: !!n.typeIsParent,
			isDatatype: !!n.isDatatype,
			isAbstract: !!n.isAbstract,
			isDatatypeLocal: n.isDatatypeLocal == null ? null : !!n.isDatatypeLocal,
			isAbstractLocal: n.isAbstractLocal == null ? null : !!n.isAbstractLocal,
			datatypeTree: Array.isArray(n.datatypeTree) ? n.datatypeTree : [],
			required: !!n.required,
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
				: n.isDatatype &&
					  String(n.name || '')
							.trim()
							.toLowerCase() === 'date'
					? { mode: 'date' }
					: null,
			preferredRender: normalizePreferredRender(n.preferredRender),
			typeBranch: n.typeBranch ? deepClone(n.typeBranch) : null,
			isBasiseinheitUnit: !!n.isBasiseinheitUnit,
			prefixAllowlist: n.prefixAllowlist ? deepClone(n.prefixAllowlist) : null,
			prefixRootToSi: n.prefixRootToSi != null ? n.prefixRootToSi : null,
			quantitySchema: n.quantitySchema ? deepClone(n.quantitySchema) : null,
			/* Child extras on parent (e.g. Meter): Praefix allowlist + factors — not name/description. */
			prefixBranch: extractPrefixBranchFromNode(n),
		};
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
			typeId: d.typeId,
			ownTypeId: d.ownTypeId != null ? d.ownTypeId : d.typeId || 0,
			typeInheriting: !!d.typeInheriting,
			typeOverride: !!d.typeOverride,
			canInheritType: !!d.canInheritType,
			inheritedTypeId: d.inheritedTypeId || 0,
			typeIsParent: !!d.typeIsParent,
			isDatatype: !!d.isDatatype,
			isAbstract: !!d.isAbstract,
			isDatatypeLocal: d.isDatatypeLocal == null ? null : !!d.isDatatypeLocal,
			isAbstractLocal: d.isAbstractLocal == null ? null : !!d.isAbstractLocal,
			datatypeTree: Array.isArray(d.datatypeTree) ? d.datatypeTree : [],
			required: d.required,
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
			typeBranch: d.typeBranch,
			isBasiseinheitUnit: d.isBasiseinheitUnit,
			prefixAllowlist: d.prefixAllowlist,
			prefixRootToSi: d.prefixRootToSi,
			prefixBranch: d.prefixBranch,
			quantitySchema: d.quantitySchema,
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
			key === 'display_node_name' ||
			key === 'media'
		);
	}

	function supportsFixedLiteral(type) {
		var key = typeKeyFromMember({ type: type });
		return isSimpleDataType(type) && key !== 'display_node_name' && key !== 'media';
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
		// Read-only filter from sibling Einheit — do not persist local disables.
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
	 * Short text goes on option/select title — see formatSelectTitle / syncSelectTitle.
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
				next = chain.concat([String(n.name || n.id)]);
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
		return n && n.name ? String(n.name) : '#' + termId;
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
			/* Path too wide for the box → name only. */
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
	 * Focus id for a picker: optional preferFocus, else current selection, else focusId / last node.
	 */
	function resolvePickerFocusId(opts) {
		opts = opts || {};
		var explicit = opts.focusId != null ? parseInt(opts.focusId, 10) || 0 : 0;
		/* Attribute type: prefer chooser_focus (e.g. Data Types) over selection. */
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
	 * One select builder for option lists (branch children, type/fixed pickers, …).
	 * No blank placeholder option — first real option is selected when nothing matches.
	 * shortDescription → option title (+ select title for closed hover).
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
	function renderOptionsSelect(options, opts) {
		opts = opts || {};
		var list = (options || []).filter(function (opt) {
			return !!opt;
		});
		var control = el('select', {
			className: opts.className || 'wtt-type-select',
		});
		if (opts.disabled) {
			control.disabled = true;
		}
		if (!list.length) {
			if (opts.emptyLabel) {
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
			return control;
		}
		var selected = false;
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
			var tip = formatSelectTitle(opt);
			var optionAttrs = {
				value: value,
				text: formatSelectLabel(opt) || value,
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
		if (!selected && control.options.length) {
			control.options[0].selected = true;
		}
		syncSelectTitle(control);
		control.addEventListener('change', function () {
			syncSelectTitle(control);
		});
		if (typeof opts.onChange === 'function') {
			control.addEventListener('change', opts.onChange);
		}
		return control;
	}

	/**
	 * CatalogChoice (Q90): max nesting under a type host.
	 * Depth 0 = empty; 1 = only direct children → List; ≥2 → Tree.
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
					/* Unloaded deeper children — treat as nested (tree). */
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
					});
					return;
				}
				/* Intermediate nodes with children stay folders for tree mode;
				 * for flat list also include leaf-only — skip folders. */
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
				/* Must be "true"/"false" — boolean true was written as attribute "draggable" (invalid). */
				node.draggable = !!attrs[key];
				node.setAttribute('draggable', attrs[key] ? 'true' : 'false');
			} else if (
				key === 'checked' ||
				key === 'disabled' ||
				key === 'selected' ||
				key === 'readOnly' ||
				key === 'multiple'
			) {
				/* Boolean IDL properties — setAttribute alone is unreliable for checkboxes. */
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
		window.requestAnimationFrame(function () {
			var tree = document.querySelector('.wtt-tree-pane');
			var detail = document.querySelector('.wtt-detail-pane');
			if (tree) {
				tree.scrollTop = scroll.tree || 0;
			}
			if (detail) {
				detail.scrollTop = scroll.detail || 0;
			}
		});
	}

	function selectNode(id, opts) {
		opts = opts || {};
		var termId = parseInt(id, 10) || 0;
		if (termId <= 0) {
			return;
		}
		var additive = !!opts.additive;
		var range = !!opts.range;

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

		/* Flush dirty settings before leaving; wait so draft is not cleared mid-capture. */
		var leaveFlush = Promise.resolve();
		if (state.selectedId && state.selectedId !== termId) {
			leaveFlush = flushPendingNodeSettings() || Promise.resolve();
		} else if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
			autosaveTimer = null;
		}

		leaveFlush.then(function () {
			state.previewValues = {};
			state.previewFocus = null;
			expandAncestorsOf(termId);
			state.selectedId = termId;
			state.selectedNode = null;
			state.draft = null;
			state.savedDraft = null;
			state.settingsSaving = false;
			state.error = '';
			persistTreeUi();
			render();
			scrollSelectedIntoTreeView();
			return post('wtt_get_node', { term_id: termId });
		})
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.node) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				applyLoadedNode(json.data.node);
				render();
				scrollSelectedIntoTreeView();
			})
			.catch(function () {
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
		var visible = flattenVisibleTreeIds(state.tree, []);
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
			selectNode(state.selectedId);
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

	function isDevelopmentMode() {
		return !!cfg.developmentMode;
	}

	/** Protected relation rows unlock when Development mode is on. */
	function isRelationRowLocked(row) {
		return !!(row && row.protected && !isDevelopmentMode());
	}

	/**
	 * @param {string|number} termId
	 * @param {boolean} hasChildren
	 * @param {{deletable?:boolean, mode?:'node'|'branch'|'leaf'|'promote'|'cascade'}} [opts]
	 *   Soft-delete: node + descendants move to Trash (hierarchy kept).
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
		if (deletable === false) {
			setError(i18n.notDeletable || 'This system or catalog type cannot be deleted.');
			return;
		}

		var ask = confirmNodeDeleteEnabled();
		var msg = hasChildren
			? i18n.confirmMoveToTrashBranch ||
			  'Move this node and all descendants to Trash? Parent/child links are kept.'
			: i18n.confirmMoveToTrash || 'Move this node to Trash?';
		if (ask && !window.confirm(msg)) {
			return;
		}
		runDelete('cascade', termId);
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

	function ensurePickerExpandedBucket(key) {
		if (!state.nodePickerExpanded) {
			state.nodePickerExpanded = {};
		}
		if (!state.nodePickerExpanded[key]) {
			state.nodePickerExpanded[key] = {};
		}
		return state.nodePickerExpanded[key];
	}

	/**
	 * Unified expandable node tree picker (settings, preview, reparent).
	 * Presentation: setting `treePickerMode` = inline | popup (default popup).
	 * Pass presentation:'inline' / embedded:true when already inside a dialog.
	 *
	 * Focus options:
	 * - focusId / preferFocus — which node is the default focus
	 * - expandFocusBranch — when true, open ancestors and expand that node (attribute type picker sets true)
	 */
	function renderNodeTreePicker(opts) {
		opts = opts || {};
		if (treePickerPresentation(opts) === 'popup') {
			return renderNodeTreePickerPopup(opts);
		}
		return renderNodeTreePickerInline(opts);
	}

	function renderNodeTreePickerPopup(opts) {
		opts = opts || {};
		var selectedId = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : function () {};
		var disabled = !!opts.disabled;
		var allowClear = opts.allowClear !== false;
		var placeholder = opts.placeholder || (i18n.nodeRefChoose || 'Choose…');
		var pickLabel = selectedId
			? i18n.nodePickerChange || 'Change…'
			: i18n.nodePickerChoose || 'Choose…';
		var roots = opts.roots || [];
		var allowRoot = !!opts.allowRoot;
		var rootLabel = opts.rootLabel || (i18n.reparentRoot || 'Root (no parent)');

		function labelForId(id) {
			id = parseInt(id, 10) || 0;
			if (allowRoot && id === 0) {
				return rootLabel;
			}
			if (!id) {
				return '';
			}
			var n = findNodeInTree(state.tree, id) || findNodeInTree(roots, id);
			if (n && n.name) {
				return n.name;
			}
			/* selectedLabel may be a path — use last segment as name fallback. */
			if (opts.selectedLabel && String(opts.selectedId) === String(id)) {
				var raw = String(opts.selectedLabel);
				var parts = raw.split(/\s*\/\s*/);
				return parts.length ? parts[parts.length - 1] : raw;
			}
			return '#' + id;
		}

		var currentName = selectedId ? labelForId(selectedId) : '';
		var currentPath = '';
		if (selectedId) {
			if (opts.selectedLabel && String(opts.selectedId) === String(selectedId)) {
				currentPath = String(opts.selectedLabel);
			} else {
				currentPath = buildNodePathLabel(selectedId, roots);
			}
		}
		var emptyLabel = placeholder || '—';
		var wrap = el('div', {
			className:
				'wtt-node-picker wtt-node-picker--popup-trigger' +
				(disabled ? ' is-disabled' : '') +
				(opts.className ? ' ' + opts.className : ''),
		});

		var valueEl = el('span', {
			className:
				'wtt-node-picker__value' + (selectedId && currentName ? '' : ' is-empty'),
			text: selectedId && currentName ? currentName : emptyLabel,
		});
		if (selectedId && currentName) {
			applyAdaptiveNodeLabel(valueEl, currentName, currentPath || currentName);
		} else {
			valueEl.title = emptyLabel;
		}
		wrap.appendChild(valueEl);

		var actions = el('div', { className: 'wtt-node-picker__actions' });
		actions.appendChild(
			el('button', {
				type: 'button',
				className: 'wtt-node-picker__icon-btn wtt-node-picker__open',
				disabled: disabled ? 'disabled' : undefined,
				title: pickLabel,
				'aria-label': pickLabel,
				html: '<span class="dashicons dashicons-category" aria-hidden="true"></span>',
				onClick: function (e) {
					e.preventDefault();
					if (disabled) {
						return;
					}
					openNodeTreePickerDialog(opts, function (id) {
						onSelect(id);
					});
				},
			})
		);

		if (allowClear) {
			actions.appendChild(
				el('button', {
					type: 'button',
					className: 'wtt-node-picker__icon-btn wtt-node-picker__clear-icon',
					disabled: disabled || !selectedId ? 'disabled' : undefined,
					title: i18n.nodePickerClear || 'Clear',
					'aria-label': i18n.nodePickerClear || 'Clear',
					html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
					onClick: function (e) {
						e.preventDefault();
						if (disabled || !selectedId) {
							return;
						}
						onSelect(0);
					},
				})
			);
		}
		wrap.appendChild(actions);

		return wrap;
	}

	/**
	 * Whether to open the path to focusId and expand that node (show its children).
	 * Explicit opts.expandFocusBranch wins; otherwise false (opt-in).
	 */
	function shouldExpandFocusBranch(opts) {
		opts = opts || {};
		if (opts.expandFocusBranch != null) {
			return !!opts.expandFocusBranch;
		}
		/* Legacy alias used by some inline pickers. */
		if (opts.expandSelectedPath != null) {
			return !!opts.expandSelectedPath;
		}
		return false;
	}

	function applyFocusBranchExpand(opts, expandKey, focusId) {
		focusId = parseInt(focusId, 10) || 0;
		if (!focusId || !shouldExpandFocusBranch(opts)) {
			return;
		}
		state.nodePickerExpanded = state.nodePickerExpanded || {};
		var map = state.nodePickerExpanded[expandKey] || {};
		state.nodePickerExpanded[expandKey] = map;
		expandAncestorsInMap(opts.roots || [], focusId, map, []);
		expandAncestorsInMap(state.tree, focusId, map, []);
		/* Expand focus node itself so its children are visible. */
		map[focusId] = true;
		map[String(focusId)] = true;
	}

	function openNodeTreePickerDialog(opts, onDone) {
		opts = opts || {};
		var localSelected = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var focusId = resolvePickerFocusId(opts);
		var expandKey = opts.expandKey || 'dialog-pick';
		var pickerHost = el('div', { className: 'wtt-node-picker-dialog__host' });

		/* Fresh dialog: start collapsed; optionally open path to focus/default node. */
		state.nodePickerExpanded = state.nodePickerExpanded || {};
		state.nodePickerExpanded[expandKey] = {};
		applyFocusBranchExpand(opts, expandKey, focusId);

		function close() {
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop);
			}
		}

		function mount() {
			pickerHost.innerHTML = '';
			pickerHost.appendChild(
				renderNodeTreePickerInline(
					Object.assign({}, opts, {
						selectedId: localSelected,
						expandKey: expandKey,
						presentation: 'inline',
						embedded: true,
						defaultOpen: true,
						compact: false,
						showPickedLabel: opts.showPickedLabel !== false,
						/* Highlight last-focus when nothing is selected yet. */
						currentId:
							opts.currentId != null
								? opts.currentId
								: localSelected
								? localSelected
								: focusId,
						onSelect: function (id) {
							localSelected = id;
							if (typeof onDone === 'function') {
								onDone(id);
							}
							close();
						},
					})
				)
			);
		}
		mount();

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' }, [
				el('h2', {
					text: opts.dialogTitle || i18n.nodePickerTitle || 'Choose node',
				}),
				pickerHost,
				el('div', { className: 'wtt-dialog__actions' }, [
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.cancel,
						onClick: function () {
							close();
						},
					}),
				]),
			]),
		]);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) {
				close();
			}
		});
		document.body.appendChild(backdrop);
		if (focusId && shouldExpandFocusBranch(opts)) {
			window.requestAnimationFrame(function () {
				var row = backdrop.querySelector('.wtt-node-picker__row.is-current');
				if (row && typeof row.scrollIntoView === 'function') {
					row.scrollIntoView({ block: 'center', behavior: 'smooth' });
				}
			});
		}
	}

	function renderNodeTreePickerInline(opts) {
		opts = opts || {};
		var roots = opts.roots || [];
		var selectedId = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : function () {};
		var blocked = opts.blockedIds || {};
		var allowRoot = !!opts.allowRoot;
		var rootLabel = opts.rootLabel || (i18n.reparentRoot || 'Root (no parent)');
		var currentId = opts.currentId != null ? parseInt(opts.currentId, 10) || 0 : null;
		var compact = !!opts.compact;
		var disabled = !!opts.disabled;
		var expandKey = opts.expandKey || 'default';
		var expandedMap = ensurePickerExpandedBucket(expandKey);
		var selectableFn =
			typeof opts.selectable === 'function'
				? opts.selectable
				: function () {
						return true;
				  };
		var showPickedLabel = opts.showPickedLabel !== false;
		var pickedPrefix = opts.pickedPrefix || (i18n.nodePickerSelected || 'Selected:');
		var placeholder = opts.placeholder || (i18n.nodeRefChoose || 'Choose node…');
		var allowClear = opts.allowClear !== false;
		var pendingScrollTop =
			opts.restoreScrollTop != null ? parseInt(opts.restoreScrollTop, 10) || 0 : null;

		if (!state.nodePickerOpen) {
			state.nodePickerOpen = {};
		}
		if (!state.nodePickerQuery) {
			state.nodePickerQuery = {};
		}
		var defaultOpen = opts.defaultOpen != null ? !!opts.defaultOpen : !compact;
		if (state.nodePickerOpen[expandKey] == null) {
			state.nodePickerOpen[expandKey] = defaultOpen;
		}

		var wrap = el('div', {
			className:
				'wtt-node-picker' +
				(compact ? ' wtt-node-picker--compact' : '') +
				(disabled ? ' is-disabled' : ''),
		});

		function nodeSelectable(node) {
			if (!node || node.id == null) {
				return false;
			}
			if (blocked[String(node.id)]) {
				return false;
			}
			return !!selectableFn(node);
		}

		function labelForId(id) {
			id = parseInt(id, 10) || 0;
			if (allowRoot && id === 0) {
				return rootLabel;
			}
			if (!id) {
				return placeholder;
			}
			var n = findNodeInTree(state.tree, id) || findNodeInTree(roots, id);
			return (n && n.name) || '#' + id;
		}

		function pathForId(id) {
			id = parseInt(id, 10) || 0;
			if (!id) {
				return '';
			}
			if (opts.selectedLabel && String(opts.selectedId) === String(id)) {
				var sl = String(opts.selectedLabel);
				if (sl.indexOf('/') !== -1) {
					return sl;
				}
			}
			return buildNodePathLabel(id, roots) || labelForId(id);
		}

		function captureTreeScroll() {
			var tree = wrap.querySelector('.wtt-node-picker__tree');
			return tree ? tree.scrollTop : 0;
		}

		function restoreTreeScroll(scrollTop) {
			window.requestAnimationFrame(function () {
				var tree = wrap.querySelector('.wtt-node-picker__tree');
				if (tree) {
					tree.scrollTop = scrollTop || 0;
				}
			});
		}

		function pick(id) {
			if (disabled) {
				return;
			}
			id = parseInt(id, 10) || 0;
			selectedId = id;
			onSelect(id);
			/* Parent may remount (reparent) or close (popup); only rebuild if still attached. */
			if (wrap.isConnected) {
				rebuild();
			}
		}

		function normalizePickerQuery(raw) {
			return String(raw || '')
				.trim()
				.toLowerCase();
		}

		function nodeMatchesQuery(node, q) {
			if (!q) {
				return true;
			}
			var name = String((node && node.name) || '').toLowerCase();
			if (name.indexOf(q) !== -1) {
				return true;
			}
			if (cfg.showTypeInTree && node && node.typeLabel) {
				return String(node.typeLabel).toLowerCase().indexOf(q) !== -1;
			}
			return false;
		}

		function nodeOrDescendantMatches(node, q) {
			if (!q) {
				return true;
			}
			if (nodeMatchesQuery(node, q)) {
				return true;
			}
			var kids = (node && node.children) || [];
			var i;
			for (i = 0; i < kids.length; i++) {
				if (nodeOrDescendantMatches(kids[i], q)) {
					return true;
				}
			}
			return false;
		}

		function rebuild() {
			var scrollTop =
				pendingScrollTop != null ? pendingScrollTop : captureTreeScroll();
			pendingScrollTop = null;
			wrap.innerHTML = '';
			var isOpen = !!state.nodePickerOpen[expandKey];
			var query = normalizePickerQuery(state.nodePickerQuery[expandKey]);

			if (showPickedLabel) {
				var head = el('div', { className: 'wtt-node-picker__head' });
				var toggle = el('button', {
					type: 'button',
					className: 'wtt-node-picker__toggle-open',
					'aria-expanded': isOpen ? 'true' : 'false',
					title: isOpen
						? i18n.nodePickerCollapse || 'Collapse'
						: i18n.nodePickerExpand || 'Expand',
					html:
						'<span class="dashicons dashicons-arrow-' +
						(isOpen ? 'down' : 'right') +
						'"></span>',
					onClick: function (e) {
						e.preventDefault();
						state.nodePickerOpen[expandKey] = !state.nodePickerOpen[expandKey];
						rebuild();
					},
				});
				head.appendChild(toggle);
				var pickedName =
					selectedId || (allowRoot && selectedId === 0)
						? labelForId(selectedId)
						: placeholder;
				var pickedPath =
					selectedId > 0 ? pathForId(selectedId) : pickedName;
				var pickedEl = el('span', {
					className: 'wtt-node-picker__picked',
				});
				var prefixEl = el('span', {
					className: 'wtt-node-picker__picked-prefix',
					text: pickedPrefix + ' ',
				});
				var valueEl = el('span', {
					className: 'wtt-node-picker__picked-value',
					text: pickedName,
				});
				pickedEl.appendChild(prefixEl);
				pickedEl.appendChild(valueEl);
				head.appendChild(pickedEl);
				if (selectedId > 0) {
					applyAdaptiveNodeLabel(valueEl, pickedName, pickedPath || pickedName);
				} else {
					valueEl.title = pickedName;
				}
				if (allowClear && selectedId && !disabled) {
					head.appendChild(
						el('button', {
							type: 'button',
							className: 'button-link wtt-node-picker__clear',
							text: i18n.nodePickerClear || 'Clear',
							onClick: function (e) {
								e.preventDefault();
								pick(0);
							},
						})
					);
				}
				wrap.appendChild(head);
			}

			if (!isOpen && compact) {
				return;
			}

			var tools = el('div', { className: 'wtt-node-picker__tools' });
			var searchWrap = el('div', { className: 'wtt-node-picker__search' });
			var searchInput = el('input', {
				type: 'search',
				className: 'wtt-node-picker__search-input',
				placeholder:
					i18n.nodePickerSearchPlaceholder || 'Search nodes…',
				value: state.nodePickerQuery[expandKey] || '',
				disabled: disabled ? 'disabled' : undefined,
				'aria-label': i18n.nodePickerSearch || 'Search',
			});
			searchInput.addEventListener('input', function () {
				state.nodePickerQuery[expandKey] = searchInput.value;
				rebuild();
				window.requestAnimationFrame(function () {
					var again = wrap.querySelector('.wtt-node-picker__search-input');
					if (again) {
						again.focus();
						var len = again.value.length;
						if (typeof again.setSelectionRange === 'function') {
							again.setSelectionRange(len, len);
						}
					}
				});
			});
			searchWrap.appendChild(searchInput);
			tools.appendChild(searchWrap);
			tools.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.expandAll || 'Expand',
					title: i18n.expandAllHint || 'Expand all nodes',
					disabled: disabled ? 'disabled' : undefined,
					onClick: function (e) {
						e.preventDefault();
						collectExpandableIds(roots).forEach(function (id) {
							expandedMap[id] = true;
						});
						rebuild();
					},
				})
			);
			tools.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.collapseAll || 'Collapse',
					title: i18n.collapseAllHint || 'Collapse all nodes',
					disabled: disabled ? 'disabled' : undefined,
					onClick: function (e) {
						e.preventDefault();
						Object.keys(expandedMap).forEach(function (key) {
							delete expandedMap[key];
						});
						rebuild();
					},
				})
			);
			wrap.appendChild(tools);

			var treeHost = el('div', { className: 'wtt-node-picker__tree' });
			var list = el('ul', { className: 'wtt-node-picker__list' });

			if (allowRoot) {
				var rootVisible =
					!query ||
					String(rootLabel)
						.toLowerCase()
						.indexOf(query) !== -1;
				if (rootVisible) {
					var rootLi = el('li', { className: 'wtt-node-picker__node' });
					var rootBtn = el('button', {
						type: 'button',
						className:
							'wtt-node-picker__item' +
							(selectedId === 0 ? ' is-picked' : '') +
							(currentId === 0 ? ' is-current' : ''),
						text: rootLabel,
						disabled: disabled ? 'disabled' : undefined,
						onClick: function () {
							pick(0);
						},
					});
					rootLi.appendChild(rootBtn);
					list.appendChild(rootLi);
				}
			}

			function appendNodes(nodes, parentUl, depth) {
				(nodes || []).forEach(function (n) {
					if (!n || n.id == null) {
						return;
					}
					if (query && !nodeOrDescendantMatches(n, query)) {
						return;
					}
					var id = n.id;
					var isBlocked = !!blocked[String(id)];
					var canPick = nodeSelectable(n);
					var kids = n.children || [];
					var hasChildren = !!(n.hasChildren || kids.length);
					var matchSelf = !query || nodeMatchesQuery(n, query);
					var matchDesc =
						query && hasChildren
							? kids.some(function (c) {
									return nodeOrDescendantMatches(c, query);
							  })
							: false;
					/* While filtering, open branches that contain matches. */
					var isExpanded = query
						? !!(matchDesc || expandedMap[id])
						: !!expandedMap[id];
					if (query && matchDesc) {
						expandedMap[id] = true;
						expandedMap[String(id)] = true;
					}
					var li = el('li', { className: 'wtt-node-picker__node' });
					var row = el('div', {
						className:
							'wtt-node-picker__row' +
							(isBlocked ? ' is-blocked' : '') +
							((parseInt(selectedId, 10) || 0) === (parseInt(id, 10) || 0)
								? ' is-picked'
								: '') +
							(currentId != null &&
							(parseInt(currentId, 10) || 0) === (parseInt(id, 10) || 0)
								? ' is-current'
								: '') +
							(query && matchSelf ? ' is-match' : ''),
						style: 'padding-left:' + depth * 1.1 + 'em',
					});

					if (hasChildren) {
						row.appendChild(
							el('button', {
								type: 'button',
								className: 'wtt-node-picker__twist',
								'aria-expanded': isExpanded ? 'true' : 'false',
								onClick: function (e) {
									e.stopPropagation();
									expandedMap[id] = !expandedMap[id];
									rebuild();
								},
								html:
									'<span class="dashicons dashicons-arrow-' +
									(isExpanded ? 'down' : 'right') +
									'"></span>',
							})
						);
					} else {
						row.appendChild(
							el('span', {
								className: 'wtt-node-picker__twist wtt-node-picker__twist--spacer',
							})
						);
					}

					var label = n.name || String(id);
					if (cfg.showTypeInTree && n.typeLabel) {
						label += ' [' + n.typeLabel + ']';
					}
					if (isBlocked) {
						label += ' (' + (i18n.reparentBlocked || 'unavailable') + ')';
					}

					var notPickable = disabled || isBlocked || !canPick;
					if (notPickable && !canPick && !isBlocked) {
						row.className += ' is-not-selectable';
					}
					var nameTitle = '';
					if (isBlocked) {
						nameTitle =
							i18n.reparentBlocked || 'unavailable';
					} else if (!canPick && n.isAbstract) {
						nameTitle =
							i18n.nodePickerAbstractHint ||
							'Abstract catalog — expand and choose a child, not this folder.';
					} else if (!canPick) {
						nameTitle =
							i18n.nodePickerNotSelectable ||
							'Not selectable in this chooser.';
					}

					var nameBtn = el('button', {
						type: 'button',
						className: 'wtt-node-picker__name',
						text: label,
						title: nameTitle || undefined,
						disabled: notPickable ? 'disabled' : undefined,
						onClick: function () {
							if (notPickable) {
								return;
							}
							pick(id);
						},
					});
					row.appendChild(nameBtn);
					li.appendChild(row);

					if (hasChildren && isExpanded) {
						var childUl = el('ul', { className: 'wtt-node-picker__list' });
						appendNodes(kids, childUl, depth + 1);
						li.appendChild(childUl);
					}

					parentUl.appendChild(li);
				});
			}

			if (!roots.length && !allowRoot) {
				treeHost.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text: opts.emptyText || (i18n.relationsEmpty || 'None'),
					})
				);
			} else {
				appendNodes(roots, list, 0);
				if (!list.children.length) {
					treeHost.appendChild(
						el('p', {
							className: 'wtt-field-hint',
							text:
								query
									? i18n.nodePickerSearchEmpty || 'No matching nodes.'
									: opts.emptyText || (i18n.relationsEmpty || 'None'),
						})
					);
				} else {
					treeHost.appendChild(list);
				}
			}
			wrap.appendChild(treeHost);
			restoreTreeScroll(scrollTop);
		}

		var focusExpandId = resolvePickerFocusId(
			Object.assign({}, opts, { selectedId: selectedId })
		);
		if (focusExpandId && shouldExpandFocusBranch(opts)) {
			expandAncestorsInMap(roots, focusExpandId, expandedMap, []);
			expandAncestorsInMap(state.tree, focusExpandId, expandedMap, []);
			expandedMap[focusExpandId] = true;
			expandedMap[String(focusExpandId)] = true;
		}

		rebuild();
		return wrap;
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
	 * into → child of target (append). before/after → sibling of target.
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
					selectNode(primary, { selectionIds: moved });
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
					selectNode(state.selectedId);
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
		collectExpandableIds(state.tree).forEach(function (id) {
			state.expanded[id] = true;
		});
		persistTreeUi();
		render();
	}

	function collapseAllTree() {
		state.expanded = {};
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
					(state.selectedId === node.id ? ' is-active' : '') +
					(isIdSelected(node.id) ? ' is-selected' : '') +
					(node.isTrash ? ' is-trash' : '') +
					(node.trashed ? ' is-trashed' : ''),
				draggable: !node.isTrash && !node.trashed,
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
							t.closest('.wtt-tree__toggle'))
					) {
						e.preventDefault();
						return;
					}
					var moveIds = getDragMoveIds(termId);
					if (!moveIds.length) {
						e.preventDefault();
						return;
					}
					dragDidMove = true;
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

			var label = node.name;
			if (cfg.showTypeInTree && node.typeLabel) {
				label += ' [' + node.typeLabel + ']';
			}

			var nameBtn = el('span', {
				className: 'wtt-tree__name',
				role: 'button',
				tabIndex: '0',
				text: label,
				onClick: function (e) {
					if (dragDidMove) {
						dragDidMove = false;
						return;
					}
					var additive = !!(e && (e.ctrlKey || e.metaKey));
					var range = !!(e && e.shiftKey);
					selectNode(node.id, { additive: additive && !range, range: range });
				},
				onKeyDown: function (e) {
					if (e.key !== 'Enter' && e.key !== ' ') {
						return;
					}
					e.preventDefault();
					selectNode(node.id, {
						additive: !!(e.ctrlKey || e.metaKey),
						range: !!e.shiftKey,
					});
				},
			});
			if (node.shortDescription) {
				nameBtn.title = String(node.shortDescription);
			} else if (node.description) {
				nameBtn.title = String(node.description);
			}
			main.appendChild(nameBtn);

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
					canUp
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
					canDown
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
			/* Props live on the catalog `table` type — copy for band-binding UI. */
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
			state.draft.isDatatype &&
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
		if (typeKeyFromMember({ type: state.draft.type }) === 'display_node_name') {
			state.draft.required = false;
		}
	}

	function setDraftIsDatatype(checked) {
		if (!state.draft) {
			return;
		}
		checked = !!checked;
		state.draft.isDatatypeLocal = checked;
		state.draft.isDatatype = checked;
		afterDraftMutation();
	}

	function setDraftIsAbstract(checked) {
		if (!state.draft) {
			return;
		}
		checked = !!checked;
		state.draft.isAbstractLocal = checked;
		state.draft.isAbstract = checked;
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
		/* Table is a structural container — never push type onto bands/fields. */
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
		if (state.draft.typeIsParent) {
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
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe')
			.replace(/ü/g, 'ue')
			.replace(/ß/g, 'ss')
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
		/* Persist band bindings immediately — snapshot draft so later stale saves cannot drop it. */
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
					'Bindings map type property → child node (not by the child\'s display name).',
			})
		);
		var body = el('div', { className: 'wtt-panel__body wtt-table-band-bindings__rows' });
		bandProps.forEach(function (prop) {
			var key = String(prop.id || prop.key || '');
			var label = String(prop.name || prop.key || key);
			var required = !!prop.required;
			var current =
				parseInt((n.propBindings && (n.propBindings[prop.id] || n.propBindings[prop.key])) || 0, 10) || 0;
			var opts = [{ id: 0, name: i18n.tableBandUnbound || '— not bound —' }].concat(
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
		state.draft.preferredRender = normalizePreferredRender(layout);
		afterDraftMutation();
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
				text: symbol && symbol !== '—' ? label + ' (' + symbol + ')' : label,
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
		if (!state.draft.fixedEnabled) {
			state.draft.fixedLiteral = '';
			state.draft.fixedNodeId = 0;
			state.draft.fixed = null;
		} else if (supportsFixedLiteral(state.draft.type) && typeKeyFromMember({ type: state.draft.type }) === 'bool') {
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
				statusText = i18n.settingsSaving || 'Saving…';
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
				statusText = i18n.settingsSaving || 'Saving…';
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
			/* New catalog root → reset allowlist (default = all children). */
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

	/** Relationstypen folder (Relation::ROOT_NAME) or any descendant — no Preview panel. */
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

	/** Direct children of catalog root — `node_embed` pick list. */
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

	/** Descendants under catalog root — `node_ref` pick roots (full subtree). */
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
			return n.datatypeTree;
		}
		if (
			state.selectedNode &&
			Array.isArray(state.selectedNode.datatypeTree) &&
			state.selectedNode.datatypeTree.length
		) {
			return state.selectedNode.datatypeTree;
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
	 * Attribute type chooser: two bindings — branch root (ast) + focus node.
	 * Example Fallstudie: root=Fallstudie (full tree), focus=Data Types.
	 * @return {Array}
	 */
	function attributeTypePickerRoots(n) {
		var bindings = cfg.catalogBindings || {};
		var rootId = parseInt(bindings.chooser_root, 10) || 0;
		var root = findCatalogNodeById(rootId, state.tree);
		if (!root) {
			root =
				findNamedInTree(state.tree, 'Fallstudie') ||
				findNamedInTree(state.tree, 'BOM Testprojekt');
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
	 * Initial focus in attribute type chooser (Data Types / Datentypen).
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
		var roots = [];
		function walk(list, acc) {
			(list || []).forEach(function (node) {
				if (!node) {
					return;
				}
				if (node.isDatatype) {
					var copy = Object.assign({}, node, { children: [] });
					walk(node.children || [], copy.children);
					copy.hasChildren = copy.children.length > 0;
					acc.push(copy);
				} else {
					walk(node.children || [], acc);
				}
			});
		}
		walk(nodes, roots);
		return roots;
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
			return raw && String(raw) !== '' ? String(raw) : '—';
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
					render();
					restorePreviewFocus();
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
			sample !== '—' &&
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
	 * primary (picks + inputs) → bools → static/fixed (wrap groups).
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
			if (key === 'display_node_name' || fixedCat || fixedLit) {
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
						text: on ? '☑' : '☐',
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
				'—';
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
				/* no interactive control — value is schema-fixed */
			}
		});
		return strip;
	}

	/**
	 * Embedded property fields of a picked catalog node — same row as the picker.
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
					text: i18n.loading || 'Loading…',
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

	/** @deprecated Separate panel removed — embed fields render inline with the picker. */
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
		/* Snapshot now — selectNode clears draft after this promise. */
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
			type_id:
				payloadDraft.canInheritType && !payloadDraft.typeOverride
					? payloadDraft.ownTypeId || 0
					: payloadDraft.typeId || 0,
			type_inheriting: payloadDraft.typeInheriting ? '1' : '0',
			type_override: payloadDraft.typeOverride ? '1' : '0',
			is_datatype:
				payloadDraft.isDatatypeLocal == null
					? 'inherit'
					: payloadDraft.isDatatypeLocal
						? '1'
						: '0',
			is_abstract: payloadDraft.isAbstract ? '1' : '0',
			required: payloadDraft.required ? '1' : '0',
			has_footer: payloadDraft.hasFooter ? '1' : '0',
			set_separator: payloadDraft.setSeparator != null ? String(payloadDraft.setSeparator) : '/',
			set_join_units: payloadDraft.setJoinUnits !== false ? '1' : '0',
			set_label_children: payloadDraft.setLabelChildren !== false ? '1' : '0',
			fixed_enabled: payloadDraft.fixedEnabled ? '1' : '0',
			fixed_literal: payloadDraft.fixedLiteral || '',
			fixed_node_id: payloadDraft.fixedNodeId || 0,
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
		if (payloadDraft.fussFieldContext) {
			savePayload.footer_op = payloadDraft.footerOp != null ? String(payloadDraft.footerOp) : '';
		}
		if (
			payloadDraft.isTableTypeCatalog ||
			(payloadDraft.isDatatype && String(payloadDraft.name || '').toLowerCase() === 'table')
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
		savePayload.preferred_render = normalizePreferredRender(
			payloadDraft.preferredRender
		);
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
				if (autosave) {
					state.autosaving = false;
				} else {
					state.settingsSaving = false;
				}
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				if (state.selectedId !== termId) {
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
						'—')
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

			if (
				memberNameKey(member) === 'praefix' &&
				n.prefixBranch &&
				n.prefixBranch.unitAllowlistEdit
			) {
				renderPrefixAllowlistEditor(n.prefixBranch, card);
			}

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
		if (!branch || !Array.isArray(branch.children)) {
			return [];
		}
		return branch.children.filter(function (child) {
			return child && child.enabled !== false;
		});
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
		state.previewFocus = {
			key: key,
			start: typeof control.selectionStart === 'number' ? control.selectionStart : null,
			end: typeof control.selectionEnd === 'number' ? control.selectionEnd : null,
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
		node.focus();
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

	function setPreviewValue(scope, member, value) {
		var key = previewValueKey(scope, member);
		state.previewValues[key] = value;
		rememberEmbedPick(member, value);
		render();
		restorePreviewFocus();
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
	 * Uses WTTSampleData (name heuristics → type fallback) — does not overwrite session edits.
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
		if (key === 'display_node_name') {
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
				return (pick && pick.name) || '—';
			}
			return '—';
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

	function renderBranchSelect(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var editable = !!opts.editable;
		var scope = opts.scope;
		var sample = opts.sample != null ? String(opts.sample) : '';
		var options = enabledBranchOptions(member);
		var control = renderOptionsSelect(options, {
			className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--prefix' : ''),
			disabled: !editable,
			selectedValue: sample,
			getValue: function (child) {
				return String(child.name || child.id);
			},
		});
		if (editable) {
			bindPreviewControl(control, scope, member, { event: 'change' });
		}
		return control;
	}

	function canEditFixedValue(n) {
		if (!n || !n.typeId || n.isSet || n.isTable) {
			return false;
		}
		if (typeKeyFromMember(n) === 'display_node_name' || typeKeyFromMember(n) === 'media') {
			return false;
		}
		return true;
	}

	/**
	 * Compact wrap strip for flags (checkboxes) or static chips.
	 * @param {'flags'|'static'} kind
	 * @param {Array<Node|null|undefined>} items
	 * @param {string} [extraClass]
	 */
	function renderMetaStrip(kind, items, extraClass) {
		var strip = el('div', {
			className:
				'wtt-form__meta-strip wtt-form__meta-strip--' +
				(kind === 'static' ? 'static' : 'flags') +
				(extraClass ? ' ' + extraClass : ''),
		});
		(items || []).forEach(function (item) {
			if (item) {
				strip.appendChild(item);
			}
		});
		return strip;
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
				: '—';
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
		var value = opts.value != null ? String(opts.value) : '—';
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

	function fixedFieldHelpText(n) {
		var parts = [];
		if (i18n.fixedValueHint) {
			parts.push(i18n.fixedValueHint);
		}
		if (n && n.fixedEnabled) {
			if (supportsFixedLiteral(n.type)) {
				if (i18n.fixedLiteralHint) {
					parts.push(i18n.fixedLiteralHint);
				}
			} else if (i18n.fixedCatalogHint) {
				parts.push(i18n.fixedCatalogHint);
			}
		}
		return parts.join('\n\n');
	}

	function renderFixedValueField(n, controlsLocked) {
		// Hide entirely when not applicable — inactive stubs get noisy with many parameters.
		if (!canEditFixedValue(n)) {
			return null;
		}

		var mode = el('div', { className: 'wtt-fixed-mode' });
		[
			{ value: false, label: i18n.fixedValueOff || 'No fixed value' },
			{ value: true, label: i18n.fixedValueOn || 'Use fixed value' },
		].forEach(function (opt, index) {
			var id = 'wtt-fixed-mode-' + (opt.value ? 'on' : 'off');
			var label = el('label', {
				className: 'wtt-radio-label',
				htmlFor: id,
			});
			var radio = el('input', {
				type: 'radio',
				id: id,
				name: 'wtt-fixed-mode',
				value: opt.value ? '1' : '0',
			});
			if (!!n.fixedEnabled === opt.value) {
				radio.checked = true;
			}
			if (controlsLocked) {
				radio.disabled = true;
			}
			radio.addEventListener('change', function () {
				setDraftFixedEnabled(opt.value);
			});
			label.appendChild(radio);
			label.appendChild(document.createTextNode(' ' + opt.label));
			mode.appendChild(label);
			if (index === 0) {
				mode.appendChild(document.createTextNode(' '));
			}
		});
		var wrap = el('div', { className: 'wtt-fixed-control' });
		wrap.appendChild(mode);

		if (!n.fixedEnabled) {
			return wrap;
		}

		var key = typeKeyFromMember(n);
		if (supportsFixedLiteral(n.type)) {
			if (key === 'bool') {
				var boolSelect = el('select', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-type-select',
				});
				if (controlsLocked) {
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
				wrap.appendChild(boolSelect);
			} else if (key === 'textarea') {
				var area = el('textarea', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-fixed-literal wtt-fixed-literal--textarea',
					rows: '3',
				});
				area.value = n.fixedLiteral || '';
				if (controlsLocked) {
					area.disabled = true;
				}
				area.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				wrap.appendChild(area);
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
				if (controlsLocked) {
					input.disabled = true;
				}
				input.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				wrap.appendChild(input);
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
				allowClear: true,
				disabled: !!controlsLocked,
				pickedPrefix: i18n.nodePickerSelected || 'Selected:',
				placeholder: i18n.fixedValueChoose || 'Choose node…',
				dialogTitle: i18n.fixedValue || 'Fixed value',
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
			wrap.appendChild(fixedPicker);
			if (!(parseInt(n.refScopeId, 10) || 0)) {
				wrap.appendChild(
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
			[{ id: 0, name: i18n.fixedValueChoose || 'Choose node' }].concat(
				(Array.isArray(n.fixedOptions) ? n.fixedOptions : []).filter(function (opt) {
					return opt && opt.id != null;
				})
			),
			{
				className: 'wtt-type-select',
				disabled: !!controlsLocked,
				selectedValue: n.fixedNodeId || 0,
				getValue: function (opt) {
					return String(opt.id);
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
		wrap.appendChild(fixedSelect);
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

	/** True when the member’s quantity schema includes a Praefix slot. */
	function memberHasPraefixSlot(member) {
		if (!member || !member.quantitySchema || !Array.isArray(member.quantitySchema.members)) {
			return false;
		}
		for (var i = 0; i < member.quantitySchema.members.length; i++) {
			var m = member.quantitySchema.members[i];
			var key = String((m && m.name) || '')
				.toLowerCase()
				.replace(/ü/g, 'ue')
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe');
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
			renderMetaStrip('flags', [
				renderMetaCheck({
					id: 'wtt-set-label-children',
					label: i18n.setLabelChildren || 'Include composition in label',
					checked: n.setLabelChildren !== false,
					disabled: !!state.settingsSaving,
					title: i18n.setLabelChildrenHint || '',
					onChange: setDraftSetLabelChildren,
				}),
				renderMetaCheck({
					id: 'wtt-set-join-units',
					label: i18n.setJoinUnits || 'Join units',
					checked: n.setJoinUnits !== false,
					disabled: !!state.settingsSaving,
					title: joinHint,
					onChange: setDraftSetJoinUnits,
				}),
			])
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
				? i18n.enumValuesSaving || 'Saving…'
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
		if (!row) {
			return false;
		}
		var key = String(row.typeKey || row.type || '')
			.trim()
			.toLowerCase();
		return key === 'has_type';
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

	function isRelationMultiplicityLocked(row) {
		return (
			isChildOfRelationRow(row) ||
			isHasTypeRelationRow(row) ||
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
		if (isHasTypeRelationRow(row)) {
			return !isRelationRowLocked(row);
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
		var hasTypeRelId = findRelationTypeIdByName(n, 'has_type');

		if (parentId > 0) {
			von.push({
				type: 'child_of',
				otherId: parentId,
				otherName: n.parentName || String(parentId),
				multiplicity: '1',
				notes: i18n.relationsProtected || 'protected — reparent only',
				protected: true,
			});
		}

		/*
		 * Q88: hierarchy datatype = parent via child_of — do not mirror as has_type.
		 * Attribute / catalog field types still show has_type when typeId is set.
		 */
		if (n.typeId && !n.typeIsParent) {
			var typeInherited = !!n.canInheritType && !n.typeOverride;
			von.push({
				type: 'has_type',
				typeKey: 'has_type',
				typeId: hasTypeRelId,
				otherId: parseInt(n.typeId, 10) || 0,
				otherName:
					(n.type && (n.type.path || n.type.name)) ||
					String(n.typeId),
				multiplicity: '0..1',
				notes: typeInherited
					? i18n.relationsHasTypeInherited ||
					  'inherited — enable Override to change'
					: i18n.relationsHasTypeNote ||
					  'Data type binding (0..1). Add / remove here.',
				protected: typeInherited,
				synthetic: true,
				stored: false,
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
					'Catalog root — click To to change',
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
				notes: i18n.relationsProtected || 'protected — reparent only',
				protected: true,
			});
		});

		walkTreeNodes(state.tree, function (node) {
			var nid = parseInt(node.id, 10) || 0;
			if (!nid || nid === selfId) {
				return;
			}
			if ((parseInt(node.typeId, 10) || 0) === selfId) {
				an.push({
					type: 'has_type',
					typeKey: 'has_type',
					typeId: hasTypeRelId,
					otherId: nid,
					otherName: node.name || String(nid),
					multiplicity: '0..*',
					notes: i18n.relationsHasTypeNote || 'Data type binding (0..1). Add / remove here.',
					protected: true,
					synthetic: true,
					stored: false,
				});
			}
			if ((parseInt(node.refScopeId, 10) || 0) === selfId) {
				an.push({
					type: 'ref_scope',
					otherId: nid,
					otherName: node.name || String(nid),
					multiplicity: '0..*',
					notes: i18n.relationsRefScopeNote || 'derived — change Catalog root',
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
					  'Choose data-type node (has_type target)'
					: i18n.relationsChangeTarget || 'Change target node',
				placeholder: isHasType
					? i18n.relationsPickHasTypeTarget ||
					  'Choose data-type node (has_type target)'
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
						return !!node.isDatatype;
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
				text: row.type || '—',
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
			types.unshift({ id: currentId, name: row.type, path: row.type });
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
					text: row.type || '—',
					selected: true,
				})
			);
		} else {
			types.forEach(function (t) {
				select.appendChild(
					el('option', {
						value: String(t.id),
						text: t.name || String(t.id),
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
					text: nodeName || String(id) || '—',
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
		td.appendChild(document.createTextNode(nodeName || '—'));
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
						  'Locked: child_of is always 1; has_type and ref_scope are always 0..1.'
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
	 * Always: From node → Relation type → To node. Current node shown by name.
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
					(row.stored ? ' wtt-relations__row--stored' : '') +
					(row.direction === 'an' ? ' wtt-relations__row--an' : ''),
			});
			tr.appendChild(
				renderRelationEndpointCell(selfId, row.fromId, row.fromName)
			);
			tr.appendChild(renderRelationTypeCell(opts.node || null, row, opts));
			tr.appendChild(
				renderRelationEndpointCell(selfId, row.toId, row.toName, {
					canPick: canPickRelationTarget(row, opts),
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
				var canRemoveHasType =
					editable &&
					!isRelationRowLocked(row) &&
					isHasTypeRelationRow(row) &&
					!!row.synthetic &&
					!!(row.typeId || findRelationTypeIdByName(opts.node, 'has_type')) &&
					!!(row.toId || row.otherId);
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
					!!(parseInt(row.fromId, 10) || parseInt(row.toId, 10));
				var canReorderRow =
					canEditStored && allowReorder && row.direction === 'von';
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
	 * Type ids already used for From → To (stored edges only).
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
				var blocked = blockedToIdsForType(n, typeId);
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
							  'Choose data-type node (has_type target)'
							: i18n.relationsPickTarget || 'Choose target node',
						placeholder: isHasType
							? i18n.relationsPickHasTypeTarget ||
							  'Choose data-type node (has_type target)'
							: i18n.relationsPickTarget || 'Choose target node',
						blockedIds: isHasType ? {} : blocked,
						selectable: function (node) {
							var id = node && node.id ? parseInt(node.id, 10) || 0 : 0;
							if (!id || id === selfId) {
								return false;
							}
							if (isHasType) {
								if (typeIdMap[String(id)]) {
									return true;
								}
								return !!node.isDatatype;
							}
							return !blocked[String(id)];
						},
					},
					function (toId) {
						toId = parseInt(toId, 10) || 0;
						if (!toId) {
							return;
						}
						if (!isHasType && blocked[String(toId)]) {
							setError(
								i18n.relationsDuplicateExists ||
									'This relation already exists (same From, Relation type, and To).'
							);
							return;
						}
						post('wtt_add_relation', {
							term_id: selfId,
							type_id: typeId,
							to_id: toId,
							multiplicity: isHasType ? '0..1' : undefined,
						})
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
	 * direction 'von': self → partner; 'an': partner → self (edge stored on partner).
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
	 * Flatten von/an into directed edges: from → type → to (current node = this).
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
		block.appendChild(
			renderRelationsSectionTitle(
				i18n.attributesTitle || 'Attributes',
				i18n.attributesHelp ||
					'Name + type + multiplicity (besteht_aus members).',
				'wtt-attributes__title-wrap'
			)
		);

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
			});
		}
		columns.push({
			label: i18n.attributesActions || 'Actions',
			className: 'wtt-col-actions',
		});
		var colCount = columns.length;
		var ownPeers = attrs.filter(function (a) {
			return a && !a.inherited;
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
					ownPeers: ownPeers,
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
							mountAddTypePicker();
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
			var bindSelect = el('select', {
				className: 'wtt-attributes__binding-select',
			});
			attributeBindingOptions().forEach(function (opt) {
				bindSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label,
						selected: opt.value === 'besteht_aus',
					})
				);
			});
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
					post('wtt_add_attribute', {
						term_id: hostId,
						name: name,
						type_id: typeState.id,
						multiplicity: multSelect.value || '1',
						binding: bindSelect.value || 'besteht_aus',
					})
						.then(function (json) {
							nameInput.value = '';
							typeState.id = 0;
							typeState.name = '';
							mountAddTypePicker();
							multSelect.value = '1';
							bindSelect.value = 'besteht_aus';
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
			addTr.appendChild(
				el('td', {
					className: 'wtt-col-fixed',
					text: '—',
				})
			);
			addTr.appendChild(
				el('td', { className: 'wtt-col-readonly' }, [
					renderSlideSwitch({
						checked: false,
						disabled: true,
						title: i18n.attributesReadonlyHint || '',
					}),
				])
			);
			addTr.appendChild(
				el('td', { className: 'wtt-col-hide' }, [
					renderSlideSwitch({
						checked: false,
						disabled: true,
						title:
							i18n.attributesHideOwnHint ||
							'Hide applies only to inherited attributes (default off).',
					}),
				])
			);
			if (showInherited) {
				addTr.appendChild(
					el('td', {
						className: 'wtt-col-inherited',
						text: '—',
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
		block.appendChild(tableWrap);
		pane.appendChild(block);
	}

	function attributeBindingOptions() {
		return [
			{
				value: 'besteht_aus',
				label:
					i18n.attributesBindingComposition ||
					'Composition (besteht_aus)',
			},
			{
				value: 'aggregation',
				label: i18n.attributesBindingAggregation || 'Aggregation',
			},
		];
	}

	function bindingLabelForKey(key) {
		key = String(key || 'besteht_aus');
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
	 * Shared selectable predicate for attribute Type (any datatype, incl. abstract).
	 */
	function attributeTypeSelectable(node, typeIdMap) {
		var id = node && node.id != null ? parseInt(node.id, 10) || 0 : 0;
		if (!id) {
			return false;
		}
		if (typeIdMap && typeIdMap[String(id)]) {
			return true;
		}
		return !!node.isDatatype;
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
			placeholder: i18n.attributesPickType || 'Choose type…',
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
	 * Which Options panels apply for an attribute (hide N/A chrome).
	 *
	 * @param {Object} attr
	 * @return {{isDate:boolean,hasChoice:boolean,showCompute:boolean,hasAny:boolean}}
	 */
	function attributeDetailSections(attr) {
		var extras =
			attr.typeExtras && typeof attr.typeExtras === 'object'
				? attr.typeExtras
				: {};
		var compute = extras.compute || attr.compute;
		var typeKey = String(attr.typeKey || '').toLowerCase();
		var isDate = typeKey === 'date';
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
			hasChoice: hasChoice,
			showCompute: showCompute,
			hasAny: isDate || hasChoice || showCompute,
		};
	}

	function renderAttributeRow(n, attr, editable, rowOpts) {
		rowOpts = rowOpts || {};
		var showInherited = !!rowOpts.showInherited;
		var ownPeers = Array.isArray(rowOpts.ownPeers) ? rowOpts.ownPeers : [];
		var colCount = parseInt(rowOpts.colCount, 10) || 8;
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = parseInt(attr.id, 10) || 0;
		var inherited = !!attr.inherited;
		var hidden = !!attr.hidden;
		var ownEditable = editable && !inherited;
		var detailSections = attributeDetailSections(attr);
		var detailExpanded = !!state.attrDetailExpanded[attrId];
		var frag = document.createDocumentFragment();
		var tr = el('tr', {
			className:
				'wtt-attributes__row' +
				(inherited ? ' wtt-attributes__row--inherited' : '') +
				(hidden ? ' wtt-attributes__row--hidden' : '') +
				(attr.computed ? ' wtt-attributes__row--computed' : '') +
				(detailSections.hasAny && detailExpanded
					? ' is-options-open'
					: ''),
		});

		var peerIndex = -1;
		ownPeers.forEach(function (a, i) {
			if (a && parseInt(a.id, 10) === attrId) {
				peerIndex = i;
			}
		});
		var canReorderUp = ownEditable && peerIndex > 0;
		var canReorderDown =
			ownEditable && peerIndex >= 0 && peerIndex < ownPeers.length - 1;

		/* Name (+ Options disclosure when detail panels exist). */
		var nameChildren = [];
		if (detailSections.hasAny) {
			nameChildren.push(
				el('button', {
					type: 'button',
					className: 'button-link wtt-attributes__options-toggle',
					'aria-expanded': detailExpanded ? 'true' : 'false',
					'data-attr-id': String(attrId),
					title: i18n.attributesOptions || 'Options',
					'aria-label': i18n.attributesOptions || 'Options',
					html:
						'<span class="dashicons dashicons-arrow-' +
						(detailExpanded ? 'down' : 'right') +
						'" aria-hidden="true"></span>',
					onClick: function (e) {
						e.preventDefault();
						state.attrDetailExpanded[attrId] = !detailExpanded;
						renderDetail();
					},
				})
			);
		}
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
			nameChildren.push(nameInput);
			tr.appendChild(
				el('td', { className: 'wtt-col-name' }, [
					el('div', { className: 'wtt-attributes__name-wrap' }, nameChildren),
				])
			);
		} else {
			nameChildren.push(el('span', { text: attr.name || '' }));
			tr.appendChild(
				el('td', { className: 'wtt-col-name wtt-attributes__name' }, [
					el('div', { className: 'wtt-attributes__name-wrap' }, nameChildren),
				])
			);
		}

		/* Type — popup tree chooser (own or inherited local override). */
		var typeLabel =
			attr.typeName ||
			(attr.typeId
				? String(attr.typeId)
				: i18n.attributesUntyped || 'not typed');
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

		/* Mult. — own update or inherited local override. */
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

		/* Bindung — aggregation | besteht_aus (local override when inherited). */
		var currentBinding = String(attr.binding || 'besteht_aus');
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

		/* Default — empty = +, else show value. */
		var fixedCell = el('td', { className: 'wtt-col-fixed wtt-attributes__fixed' });
		var fixedLabel =
			attr.fixedLabel ||
			(Array.isArray(attr.fixedValues) && attr.fixedValues.length
				? attr.fixedValues.join(', ')
				: '');
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
					? ' — ' + i18n.attributesFixedHint
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
		var readonlyCell = el('td', {
			className: 'wtt-col-readonly wtt-attributes__readonly',
		});
		var isComputed = !!(attr.computed || (attr.compute && attr.compute.op));
		readonlyCell.appendChild(
			renderSlideSwitch({
				checked: isComputed || !!attr.readonly,
				disabled: !editable || isComputed,
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

		/* Hide — default OFF. Only meaningful for inherited attrs. */
		var hideCell = el('td', { className: 'wtt-col-hide wtt-attributes__hide' });
		hideCell.appendChild(
			renderSlideSwitch({
				checked: inherited && hidden,
				disabled: !editable || !inherited,
				title: inherited
					? i18n.attributesHideHint ||
					  'Hide this inherited attribute on this node.'
					: i18n.attributesHideOwnHint ||
					  'Hide applies only to inherited attributes (default off).',
				onChange: function (on) {
					if (!inherited) {
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
			} else {
				inhCell.appendChild(
					el('span', {
						className: 'wtt-attributes__inherited-no',
						text: i18n.attributesInheritedNo || '—',
					})
				);
			}
			tr.appendChild(inhCell);
		}

		/* Actions — reorder ↑↓, hierarchy move, duplicate, trash. */
		var actions = el('td', {
			className: 'wtt-col-actions wtt-attributes__actions',
		});
		if (editable) {
			if (ownEditable) {
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

			/* Duplicate: own or inherited → new own attribute on this host. */
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
							if (
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
					text: '—',
				})
			);
		}
		tr.appendChild(actions);

		frag.appendChild(tr);
		if (detailSections.hasAny && detailExpanded) {
			var detail = renderAttributeDetailRow(n, attr, editable, {
				colCount: colCount,
				inherited: inherited,
				sections: detailSections,
			});
			if (detail) {
				frag.appendChild(detail);
			}
		}
		return frag;
	}

	/**
	 * Detail row under an attribute: dateMode, choiceFilter, compute.
	 * Collapsed by default; only rendered when Options is expanded.
	 */
	function renderAttributeDetailRow(n, attr, editable, opts) {
		opts = opts || {};
		var colCount = parseInt(opts.colCount, 10) || 8;
		var inherited = !!opts.inherited;
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = parseInt(attr.id, 10) || 0;
		var extras = attr.typeExtras && typeof attr.typeExtras === 'object'
			? Object.assign({}, attr.typeExtras)
			: {};
		if (attr.compute && !extras.compute) {
			extras.compute = attr.compute;
		}

		var sections = opts.sections || attributeDetailSections(attr);
		if (!sections.hasAny) {
			return null;
		}

		var detailEditable = editable && !inherited;
		var wrap = el('div', { className: 'wtt-attributes__detail' });

		if (sections.isDate) {
			wrap.appendChild(
				renderAttrDateModeDetail(n, attr, extras, detailEditable, hostId, attrId)
			);
		}
		if (sections.hasChoice) {
			wrap.appendChild(
				renderAttrChoiceFilterDetail(
					n,
					attr,
					extras,
					detailEditable,
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
					detailEditable,
					hostId,
					attrId
				)
			);
		}

		return el('tr', {
			className:
				'wtt-attributes__detail-row' +
				(inherited ? ' is-inherited' : ''),
			'data-attr-id': String(attrId),
		}, [
			el('td', {
				colSpan: colCount,
				className: 'wtt-attributes__detail-cell',
			}, [wrap]),
		]);
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

	function renderAttrDateModeDetail(n, attr, extras, editable, hostId, attrId) {
		var typeMode =
			(attr.dateConfig && attr.dateConfig.typeMode) ||
			(attr.dateConfig && attr.dateConfig.mode) ||
			'date';
		var current =
			extras.dateMode != null && extras.dateMode !== ''
				? extras.dateMode
				: '';
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
		return el(
			'div',
			{ className: 'wtt-attributes__detail-block wtt-attributes__detail-block--date' },
			[
				el('span', {
					className: 'wtt-attributes__detail-label',
					text: i18n.attributesDateMode || 'Date mode',
				}),
				select,
			]
		);
	}

	function renderAttrChoiceFilterDetail(n, attr, extras, editable, hostId, attrId) {
		var filter =
			extras.choiceFilter && typeof extras.choiceFilter === 'object'
				? extras.choiceFilter
				: { mode: 'include', ids: [] };
		var mode = filter.mode === 'exclude' ? 'exclude' : 'include';
		var selected = {};
		(Array.isArray(filter.ids) ? filter.ids : []).forEach(function (id) {
			selected[parseInt(id, 10)] = true;
		});

		var roots = attributeChoiceFilterRoots(attr);

		var block = el('div', {
			className:
				'wtt-attributes__detail-block wtt-attributes__detail-block--choice',
		});
		block.appendChild(
			el('span', {
				className: 'wtt-attributes__detail-label',
				text: i18n.attributesChoiceFilter || 'Choice filter',
			})
		);

		var modeSelect = el('select', {
			className: 'wtt-attributes__detail-select',
			disabled: !editable,
			onChange: function (e) {
				var nextMode = e.target.value === 'exclude' ? 'exclude' : 'include';
				var next = Object.assign({}, extras);
				var ids = Object.keys(selected)
					.map(function (k) {
						return parseInt(k, 10);
					})
					.filter(function (id) {
						return id > 0 && selected[id];
					});
				if (!ids.length) {
					delete next.choiceFilter;
				} else {
					next.choiceFilter = { mode: nextMode, ids: ids };
				}
				saveAttributeTypeExtras(hostId, attrId, next);
			},
		});
		modeSelect.appendChild(
			el('option', {
				value: 'include',
				text: i18n.attributesChoiceInclude || 'Include',
				selected: mode === 'include',
			})
		);
		modeSelect.appendChild(
			el('option', {
				value: 'exclude',
				text: i18n.attributesChoiceExclude || 'Exclude',
				selected: mode === 'exclude',
			})
		);
		block.appendChild(modeSelect);

		var treeWrap = el('div', {
			className: 'wtt-attributes__choice-tree',
		});
		function persistFilter() {
			var next = Object.assign({}, extras);
			var ids = Object.keys(selected)
				.map(function (k) {
					return parseInt(k, 10);
				})
				.filter(function (id) {
					return id > 0 && selected[id];
				});
			if (!ids.length) {
				delete next.choiceFilter;
			} else {
				next.choiceFilter = {
					mode: modeSelect.value === 'exclude' ? 'exclude' : 'include',
					ids: ids,
				};
			}
			saveAttributeTypeExtras(hostId, attrId, next);
		}
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
				checked: !!selected[id],
			});
			check.addEventListener('change', function () {
				if (check.checked) {
					selected[id] = true;
				} else {
					delete selected[id];
				}
				persistFilter();
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
					i18n.attributesChoiceFilterHint ||
					'Empty = no filter. Checked nodes include or exclude their whole subtree.',
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
		var selfId = parseInt(attr.id, 10) || 0;

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
					var label = '—';
					peers.forEach(function (p) {
						if (p && parseInt(p.id, 10) === parseInt(src.attrId, 10)) {
							label = p.name || '#' + src.attrId;
							if (kind === 'attrPath' && src.pathAttrId) {
								label +=
									' → #' + String(src.pathAttrId);
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
					text: i18n.attributesComputePickSource || 'Add source…',
				})
			);
			peers.forEach(function (p) {
				if (!p || parseInt(p.id, 10) === selfId) {
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
				var pid = parseInt(parts[0], 10) || 0;
				var many = parts[1] === 'many';
				if (!many) {
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
					if (p && parseInt(p.id, 10) === pid) {
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
					/* Fallback: no live attrs — user enters id via first numeric peer attrs of host as path candidates poorly. */
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
				var pathId = parseInt(pathSelect.value, 10) || 0;
				var srcId = parseInt(pathSelect._sourceAttrId, 10) || 0;
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

		var ownAttrIds = {};
		(Array.isArray(n.attributes) ? n.attributes : []).forEach(function (a) {
			if (a && !a.inherited && a.id != null) {
				ownAttrIds[parseInt(a.id, 10)] = true;
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
		attrId = parseInt(attrId, 10) || 0;
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
	 * Flat CatalogChoice dialog (Q90 depth ≤ 1) — simple <select> list.
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
		var opts = Array.isArray(attr.fixedOptions) ? attr.fixedOptions : [];
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

	function attributeUsesCatalogFixed(attr) {
		if (String(attr.fixedMode || '') === 'catalog') {
			return true;
		}
		if (String(attr.fixedMode || '') === 'literal') {
			return false;
		}
		return (
			Array.isArray(attr.fixedOptions) && attr.fixedOptions.length > 0
		);
	}

	/**
	 * Festwert picker — catalog types use the same node tree picker as list pickers;
	 * scalars use an editable value list (1 or many by multiplicity).
	 */
	function openAttributeFixedValueDialog(n, attr, onDone) {
		var hostId = parseInt(n.id, 10) || 0;
		var attrId = parseInt(attr.id, 10) || 0;
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
			return post('wtt_set_attribute_fixed', {
				term_id: hostId,
				attr_id: attrId,
				values: JSON.stringify(values || []),
			})
				.then(function (json) {
					return applyRelationMutation(json);
				})
				.then(finishDone)
				.catch(function () {
					setError(i18n.error);
				});
		}

		/* Catalog / enum: CatalogChoice — depth ≤1 list, ≥2 tree (Q90). */
		if (useCatalog) {
			var roots = attributeFixedCatalogRoots(n, attr);
			var options = Array.isArray(attr.fixedOptions)
				? attr.fixedOptions.slice()
				: [];
			if (!options.length && roots.length) {
				options = flattenChoiceLeaves(roots);
			}
			var chooserMode = resolveCatalogChooserMode(roots, options, 'auto');

			if (!allowsMany) {
				if (!roots.length && !options.length) {
					window.alert(
						i18n.attributesFixedEmpty ||
							'This type has no selectable values yet.'
					);
					return;
				}
				var canClearFixed =
					attr.allowsEmpty != null
						? !!attr.allowsEmpty
						: String(attr.multiplicity || '1') === '0..1' ||
						  String(attr.multiplicity || '1') === '0..*';

				if (chooserMode === 'flat') {
					var flatOpts = options.length
						? options
						: flattenChoiceLeaves(roots);
					openFlatCatalogChoiceDialog(
						{
							options: flatOpts,
							selectedId: parseInt(current[0], 10) || 0,
							allowClear: canClearFixed,
							dialogTitle:
								(i18n.attributesFixed || 'Default value') +
								': ' +
								(attr.name || ''),
						},
						function (pickedId) {
							pickedId = parseInt(pickedId, 10) || 0;
							if (!pickedId && !canClearFixed) {
								return;
							}
							save(pickedId ? [String(pickedId)] : []);
						}
					);
					return;
				}

				openNodeTreePickerDialog(
					{
						roots: roots.length
							? roots
							: options.map(function (o) {
									return {
										id: o.id,
										name: o.name || String(o.id),
										children: [],
									};
							  }),
						selectedId: parseInt(current[0], 10) || 0,
						allowRoot: false,
						allowClear: canClearFixed,
						expandKey:
							'attr-fixed:' +
							String(hostId) +
							':' +
							String(attrId),
						dialogTitle:
							(i18n.attributesFixed || 'Default value') +
							': ' +
							(attr.name || ''),
						placeholder:
							i18n.attributesFixedEdit || 'Choose default…',
						selectable: function (node) {
							return !!(node && node.id != null);
						},
					},
					function (pickedId) {
						pickedId = parseInt(pickedId, 10) || 0;
						if (!pickedId && !canClearFixed) {
							return;
						}
						save(pickedId ? [String(pickedId)] : []);
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
						var allowsEmptyFixed =
							attr.allowsEmpty != null
								? !!attr.allowsEmpty
								: String(attr.multiplicity || '1') === '0..*' ||
								  String(attr.multiplicity || '1') === '0..1';
						if (input.checked) {
							selected[id] = true;
							return;
						}
						var remaining = Object.keys(selected).filter(function (k) {
							return k !== id && selected[k];
						});
						if (!allowsEmptyFixed && !remaining.length) {
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
			}

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
							(function () {
								var allowsEmptyMulti =
									attr.allowsEmpty != null
										? !!attr.allowsEmpty
										: String(attr.multiplicity || '1') ===
												'0..*' ||
										  String(attr.multiplicity || '1') ===
												'0..1';
								if (!allowsEmptyMulti) {
									return null;
								}
								return el('button', {
									type: 'button',
									className: 'button',
									text: i18n.attributesFixedClear || 'Clear',
									onClick: function () {
										save([]).then(closeMulti);
									},
								});
							})(),
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
									var allowsEmptyApply =
										attr.allowsEmpty != null
											? !!attr.allowsEmpty
											: String(attr.multiplicity || '1') ===
													'0..*' ||
											  String(attr.multiplicity || '1') ===
													'0..1';
									if (!allowsEmptyApply && !ids.length) {
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

		/* Scalar / unit: editable value list. */
		var selected = {};
		current.forEach(function (v) {
			selected[String(v)] = true;
		});

		function close() {
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop);
			}
		}

		function collectValues() {
			var inputs = body.querySelectorAll('.wtt-attr-fixed__value');
			var vals = [];
			Array.prototype.forEach.call(inputs, function (inp) {
				var v = String(inp.value || '').trim();
				if (v) {
					vals.push(v);
				}
			});
			if (!allowsMany && vals.length > 1) {
				vals = vals.slice(0, 1);
			}
			return vals;
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

		var valuesHost = el('div', { className: 'wtt-attr-fixed__values' });
		function addValueRow(val) {
			var row = el('div', { className: 'wtt-attr-fixed__value-row' });
			var inp;
			if (typeKey === 'bool') {
				inp = el('select', { className: 'wtt-attr-fixed__value' });
				[
					{ v: '0', t: i18n.boolFalse || 'false' },
					{ v: '1', t: i18n.boolTrue || 'true' },
				].forEach(function (opt) {
					inp.appendChild(
						el('option', {
							value: opt.v,
							text: opt.t,
							selected: String(val || '0') === opt.v,
						})
					);
				});
			} else {
				inp = el('input', {
					type:
						typeKey === 'int' || typeKey === 'double'
							? 'number'
							: 'text',
					className: 'regular-text wtt-attr-fixed__value',
					value: val || '',
					step: typeKey === 'int' ? '1' : 'any',
				});
			}
			row.appendChild(inp);
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
							var allowsEmptyScalar =
								attr.allowsEmpty != null
									? !!attr.allowsEmpty
									: String(attr.multiplicity || '1') ===
											'0..*' ||
									  String(attr.multiplicity || '1') ===
											'0..1';
							var rows = valuesHost.querySelectorAll(
								'.wtt-attr-fixed__value-row'
							);
							if (!allowsEmptyScalar && rows.length <= 1) {
								return;
							}
							if (row.parentNode) {
								row.parentNode.removeChild(row);
							}
						},
					})
				);
			}
			valuesHost.appendChild(row);
		}
		if (current.length) {
			current.forEach(function (v) {
				addValueRow(v);
			});
		} else {
			addValueRow('');
		}
		body.appendChild(valuesHost);
		if (allowsMany) {
			body.appendChild(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.attributesFixedAddValue || 'Add value',
					onClick: function () {
						addValueRow('');
					},
				})
			);
		}

		var allowsEmptyScalarDlg =
			attr.allowsEmpty != null
				? !!attr.allowsEmpty
				: String(attr.multiplicity || '1') === '0..*' ||
				  String(attr.multiplicity || '1') === '0..1';

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' }, [
				el('h2', {
					text:
						(i18n.attributesFixed || 'Default value') +
						': ' +
						(attr.name || ''),
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
						allowsEmptyScalarDlg
							? el('button', {
									type: 'button',
									className: 'button',
									text: i18n.attributesFixedClear || 'Clear',
									onClick: function () {
										save([]).then(close);
									},
							  })
							: null,
						el('button', {
							type: 'button',
							className: 'button button-primary',
							text: i18n.attributesFixedApply || 'Apply',
							onClick: function () {
								var vals = collectValues();
								if (!allowsEmptyScalarDlg && !vals.length) {
									window.alert(
										i18n.attributesFixedRequired ||
											'At least one value is required for this multiplicity.'
									);
									return;
								}
								save(vals).then(close);
							},
						}),
					].filter(Boolean)
				),
			]),
		]);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) {
				close();
			}
		});
		document.body.appendChild(backdrop);
	}

	function renderNodeRelations(n, pane) {
		var rel = collectSyntheticRelations(n);
		var relationTypes = assignableRelationTypes(n);
		var rows = buildDirectedRelationRows(n, rel);
		if (state.hideChildOfRelations !== false) {
			rows = rows.filter(function (row) {
				return String((row && row.type) || '').toLowerCase() !== 'child_of';
			});
		}

		var block = el('div', { className: 'wtt-panel wtt-relations' });
		var titleRow = el('div', { className: 'wtt-relations__head' });
		titleRow.appendChild(
			renderRelationsSectionTitle(
				i18n.relationsTitle || 'Relations',
				i18n.relationsHelp ||
					'Always From node → Relation type → To node. The current node is shown by name (not a link); hover for the hint.'
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
				' ' +
					(i18n.relationsHideChildOf || 'Hide child_of')
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
		block.appendChild(titleRow);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.relationsHint ||
					'Format: node → relation type → node. The current node is plain text (tooltip: current node). Mult. = definition multiplicity. Protected rows are derived (child_of / has_type).',
			})
		);
		block.appendChild(
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

		pane.appendChild(block);
	}

	function renderSetMembers(n, pane) {
		// Option: show child properties under parent — only for set-typed nodes.
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
						'— not typed —',
				})
			);
			row.appendChild(
				el('td', {
					text:
						(member.fixedEnabled &&
							((member.fixedLiteral && String(member.fixedLiteral)) ||
								(member.fixed && (member.fixed.path || member.fixed.name)))) ||
						i18n.fixedValueNone ||
						'— Not fixed —',
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
		 * Type-catalog leaves (isDatatype) often have no type_id — the node name
		 * IS the type key (int, bool, …). Do not fall through to default "text".
		 */
		if (
			!name &&
			member.isDatatype &&
			member.name
		) {
			name = String(member.name).trim().toLowerCase();
		}
		if (
			!name &&
			!member.typeId &&
			member.name &&
			/^(int|char|double|text|textarea|bool|email|date|table|enum)$/i.test(
				String(member.name).trim()
			)
		) {
			name = String(member.name).trim().toLowerCase();
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
		if (name === 'praefixe' || name === 'präfixe') {
			return 'praefixe';
		}
		if (name === 'basiseinheit') {
			return 'basiseinheit';
		}
		if (
			name === 'display_node_name' ||
			name === 'display node name' ||
			name === 'displayname' ||
			name === 'node_name'
		) {
			return 'display_node_name';
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
		/* Informal / DE aliases → quantity (Größe). */
		if (
			name === 'measure' ||
			name === 'groesse' ||
			name === 'größe' ||
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
			line += ' — ' + short;
		} else if (long) {
			line += ' — ' + long;
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
	 * Same kind → same view everywhere (form row, table cell, unit usage, …).
	 *
	 * - quantity: Typ + Praefix + Kuerzel (quantitySchema on a typed field, or
	 *   a Basiseinheit unit node’s own setMembers)
	 * - scalar: one control from the field’s data type / typeBranch
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
				enumOptions: Array.isArray(source.enumOptions)
					? deepClone(source.enumOptions)
					: Array.isArray(source.directChildren)
						? deepClone(source.directChildren)
						: [],
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
	 */
	function renderQuantityView(members, mode, scope) {
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
			return el('span', {
				className: 'wtt-preview-display-value wtt-preview-quantity',
				text: sample + String(prefixPart || '') + String(symbol || composeUnitDisplay(members) || ''),
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
				type: 'number',
				className: 'wtt-preview-input wtt-preview-input--num',
				step: 'any',
				value: sample,
			});
			bindPreviewControl(num, scope, fallbackTyp);
			group.appendChild(num);
		}
		if (praefix) {
			group.appendChild(
				renderScalarFieldView(praefix, { compact: true, mode: 'edit', scope: scope })
			);
		}
		var kuerzel = findSetMemberByKey(members, 'kuerzel');
		var symbolText =
			(kuerzel && kuerzel.fixed && kuerzel.fixed.name) ||
			(kuerzel && kuerzel.fixedLiteral) ||
			'';
		if (symbolText) {
			group.appendChild(
				el('span', {
					className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
					text: symbolText,
				})
			);
		}
		return group;
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
			if (
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

	/** @deprecated Use renderFieldView — kept as alias for call-site clarity during scaffold. */
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

		if (key === 'display_node_name') {
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
					text: i18n.refScopeNeeded || 'Set catalog root (ref_scope) first…',
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
					placeholder: i18n.nodeRefChoose || 'Choose node…',
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
				type: 'number',
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--num' : ''),
				placeholder: key === 'int' ? '0' : '0.0',
				step: key === 'int' ? '1' : 'any',
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

	/* Shared MediaRef render (Q65) — same module used later on frontend page view. */
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
		/* 9 kinds + 1 placeholder → 2 rows × 5 */
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
					text: '—',
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
						text: '·',
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
			row.appendChild(el('td', { text: '…' }));
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
						'Select at least one MIME kind — media fields do nothing until a kind is enabled.',
				})
			);
			return block;
		}

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

		var formSection = el('div', { className: 'wtt-set-preview__section' });
		formSection.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.previewAsForm || 'Form',
			})
		);
		formSection.appendChild(renderMediaKindsForm(entries));
		block.appendChild(formSection);

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
					'Select at least one MIME kind — media fields do nothing until a kind is enabled.',
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
				placeholder: i18n.mediaUrlPlaceholder || 'https://…',
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
					'Select at least one MIME kind — media fields do nothing until a kind is enabled.',
			})
		);
		var kindsFlow = renderMediaKindsGrid(function (kind) {
			if (kind == null) {
				return el('span', {
					className: 'wtt-media-kinds-grid__placeholder',
					text: '—',
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
			renderMetaStrip('flags', [
				renderMetaCheck({
					id: 'wtt-media-allow-upload',
					label: i18n.mediaAllowUpload || 'Allow Media Library',
					checked: n.mediaConfig.allowUpload !== false,
					title: i18n.mediaAllowUploadHint || '',
					className: 'wtt-media-check',
					onChange: setDraftMediaAllowUpload,
				}),
				renderMetaCheck({
					id: 'wtt-media-allow-url',
					label: i18n.mediaAllowUrl || 'Allow external URL',
					checked: !!n.mediaConfig.allowUrl,
					title: i18n.mediaAllowUrlHint || '',
					className: 'wtt-media-check',
					onChange: setDraftMediaAllowUrl,
				}),
			])
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

	function renderDateSettings(n, pane) {
		var isDateCatalog =
			n &&
			n.isDatatype &&
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

	/**
	 * Caption for a set field: "Abmessung (Länge/Breite/Höhe)" from set name +
	 * member shortDescription (fallback: name) + separator.
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
	 * Magnitude + unit suffix for a field (quantity → value / prefix+symbol).
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
			var label = '—';
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

	/** Same type + quantity with Praefix → shared Praefix/Kuerzel in preview. */
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
				unitWrap.appendChild(
					renderScalarFieldView(praefix, {
						compact: true,
						mode: 'edit',
						scope: sharedScope,
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
					(key === 'display_node_name' ? ' wtt-set-preview__row--display-name' : ''),
			});

			if (key === 'display_node_name') {
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
			cell.appendChild(document.createTextNode('—'));
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
			fieldTd.appendChild(document.createTextNode('—'));
		}
		row.appendChild(fieldTd);
		if (mode === 'edit') {
			var noteTd = el('td');
			noteTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: '…',
				})
			);
			row.appendChild(noteTd);
		} else {
			row.appendChild(el('td', { text: '…' }));
		}
		tbody.appendChild(row);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function memberNameKey(member) {
		return String((member && member.name) || '')
			.toLowerCase()
			.replace(/ü/g, 'ue')
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe');
	}

	/**
	 * Basiseinheit set helpers: Praefix + Kuerzel → mm / kΩ / °C (no atomic mm node).
	 */
	function findSetMemberByKey(members, nameKey) {
		var found = null;
		(members || []).forEach(function (m) {
			if (!found && memberNameKey(m) === nameKey) {
				found = m;
			}
		});
		return found;
	}

	/**
	 * Basiseinheit unit symbol: fixed Praefix (if any) + Kuerzel.
	 * Does not invent a sample prefix — optional Praefix stays off the unit label (Meter → m, not mm).
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
	 * Sample prefix letter for usage demos (optional Praefix → prefer milli "m" → e.g. 10.5mm).
	 * Empty when Praefix is absent or has no enabled options.
	 */
	function samplePrefixLetter(praefixMem, scope) {
		if (!praefixMem) {
			return '';
		}
		if (praefixMem.fixed && praefixMem.fixed.name) {
			return praefixMem.fixed.name === 'Mega' ? 'M' : String(praefixMem.fixed.name);
		}
		if (praefixMem.fixedLiteral) {
			return String(praefixMem.fixedLiteral);
		}
		var live = scope != null ? getPreviewValue(scope, praefixMem, null) : null;
		if (live != null && String(live) !== '') {
			var liveName = String(live);
			return liveName === 'Mega' ? 'M' : liveName;
		}
		var opts = enabledBranchOptions(praefixMem);
		if (!opts.length) {
			return '';
		}
		var pick = opts[0];
		for (var pi = 0; pi < opts.length; pi++) {
			if (opts[pi] && opts[pi].name === 'm') {
				pick = opts[pi];
				break;
			}
		}
		var name = (pick && pick.name) || '';
		return name === 'Mega' ? 'M' : name;
	}

	/** Basiseinheit unit (= set schema Typ/Praefix/Kuerzel), not a fillable instance. */
	function isUnitDefinitionNode(n) {
		return !!(n && n.isBasiseinheitUnit);
	}

	function memberTypeLabel(member) {
		if (!member) {
			return '—';
		}
		var key = typeKeyFromMember(member);
		if (key === 'node_embed') {
			return 'node_embed';
		}
		if (member.type && member.type.name) {
			return String(member.type.name);
		}
		return key || '—';
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
					'This node defines the unit schema only — not an instance value.',
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
			var name = m.name || '—';
			if (m.required) {
				name += ' *';
			}
			tr.appendChild(el('td', { text: name }));
			tr.appendChild(el('td', { text: memberTypeLabel(m) }));
			var constraint = '—';
			var memKey = memberNameKey(m);
			if (m.fixed && m.fixed.name) {
				constraint =
					(i18n.previewFixed || 'fixed') +
					': ' +
					formatSelectLabel(m.fixed);
			} else if (m.fixedLiteral != null && String(m.fixedLiteral) !== '') {
				/*
				 * Kuerzel uses a fixed symbol literal (Meter → "m"). That is NOT
				 * the Praefix catalog node "m" (Milli) — same letter, different role.
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
	 * Conversion table for a Basiseinheit unit (enabled prefixes × root factor → SI).
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
					'to_si = Typ × multiplikator × prefix_root_to_si.',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-unit-conversions__root',
				text:
					(i18n.prefixRootToSi || 'Unit: prefix root → SI base') +
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
			i18n.unitConvFactor || '× factor',
			i18n.unitConvToSi || '1 → SI',
			i18n.unitConvSample || '10.5 → SI',
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

		addRow(i18n.unitConvNone || '(none)', kuerzel, 1);

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
	 * Usage sample for a unit definition — same quantity field view as any unit-typed slot.
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
					name: node.name || '—',
					type: node.type || (node.typeLabel ? { name: node.typeLabel } : { name: 'text' }),
					typeLabel: node.typeLabel || '',
					required: !!node.required,
				},
			];
		}
		return kids.map(function (col) {
			return {
				id: col.id,
				name: col.name || '—',
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
				name: head || col.name || '—',
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
						return { name: ch.name || '—' };
					});
				}
				return {
					id: col.id,
					name: col.name || '—',
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
			var header = col.name || '—';
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
						text: c === 0 ? (i18n.previewFooter || 'Footer') : 'Σ / —',
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
	 * Zeile cells: field-type example nodes (Int_name, …) so the sample row paints.
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
			var fieldName = (f && f.name) || live.name || '—';

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
								symbol: 'Σ',
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
				sample: '…',
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
		return Object.assign({}, example, {
			id: n && n.id != null ? n.id : example.id,
			_exampleFrom: key,
		});
	}

	function resolveNodeRenderTypeKey(node) {
		if (node && (node.isTableTypeCatalog || node.isTable)) {
			return 'table';
		}
		var fromType = typeKeyFromMember(node);
		if (fromType && fromType !== 'text') {
			return fromType;
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
		if (
			name === 'int' ||
			name === 'char' ||
			name === 'double' ||
			name === 'text' ||
			name === 'textarea' ||
			name === 'bool' ||
			name === 'email' ||
			name === 'date' ||
			name === 'table'
		) {
			return name;
		}
		if (node && node.isDatatype && name) {
			return name;
		}
		/* Datatype catalog leaf often has no type_id; name is the type key. */
		if (
			node &&
			!node.typeId &&
			(name === 'int' ||
				name === 'char' ||
				name === 'double' ||
				name === 'text' ||
				name === 'textarea' ||
				name === 'bool' ||
				name === 'email' ||
				name === 'date' ||
				name === 'table')
		) {
			return name;
		}
		return fromType || name || '';
	}

	function usesRegistryPreview(n) {
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
			return el('span', { text: '—' });
		}
		var paint = resolvePreviewRenderNode(n);
		var out = api.Registry.render(
			paint,
			makeNodeRenderContext(paint, contextName, mode, extra)
		);
		return out || el('span', { className: 'wtt-field-hint', text: '—' });
	}

	function renderViaRegistryLabel(n, contextName, mode, extra) {
		var api = nodeRenderApi();
		if (!api || !api.Registry || typeof api.Registry.renderLabel !== 'function') {
			return el('span', {
				className: 'wtt-node-render__label',
				text: (n && (n.displayName || n.name)) || '—',
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
				text: (paint && (paint.displayName || paint.name)) || '—',
			})
		);
	}

	function renderViaRegistryContent(n, contextName, mode, extra) {
		var api = nodeRenderApi();
		if (!api || !api.Registry || typeof api.Registry.renderContent !== 'function') {
			return el('span', { text: '—' });
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
		return out || el('span', { className: 'wtt-field-hint', text: '—' });
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
				labelCell.textContent = '—';
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
						text: '—',
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
		tr.appendChild(el('td', { text: i18n.nrTableSampleA || '…' }));
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
		tr.appendChild(el('td', { text: i18n.nrTableSampleB || '…' }));
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
		if (id > 0 && state.selectedId === id && state.selectedNode) {
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
		if (id > 0 && state.selectedId === id && state.selectedNode) {
			var live = resolveTableValidation(viewNode());
			if (live && Array.isArray(live.errors) && live.errors.length) {
				return live.errors.join(' ');
			}
		}
		return String(node.tableErrorHint || '');
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
			' — ' +
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
		var key = resolveNodeRenderTypeKey(n);
		var api = nodeRenderApi();
		var isTableType =
			key === 'table' ||
			!!(api && api.isStructuredType && api.isStructuredType(key)) ||
			!!n.isTable ||
			!!n.isTableTypeCatalog;

		if (isTableType) {
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
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.nodeRenderPreviewHint ||
					'Rendered via NodeRendererRegistry (tree / form / table). Same path for admin preview and future frontend.',
			})
		);
		block.appendChild(
			renderRegistryPreviewSection(
				i18n.previewAsTree || 'Tree',
				renderRegistryTreeChrome(n)
			)
		);
		block.appendChild(
			renderRegistryPreviewSection(
				i18n.previewAsForm || 'Form',
				renderRegistryFormChrome(n)
			)
		);
		block.appendChild(
			renderRegistryPreviewSection(
				i18n.previewAsTable || 'Table',
				renderRegistryTableChrome(n)
			)
		);
		return block;
	}

	/**
	 * Attribute host preview members (Name/E-Mail, …) — skip hidden.
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
			/* typeName may be a path ("… / text") — use last segment. */
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
				fixed: null,
				fixedLiteral: '',
				sample: '',
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
	 * Attribute host preview: Form(1) + Table(n) + Compact(1, H|V) × edit/display.
	 * Samples are host-agnostic via WTTSampleData (name → type) + variantIndex for rows.
	 */
	function renderAttributeHostPreview(n, members) {
		var ObjectRender = window.WTTObjectRender;
		var preferred = normalizePreferredRender(
			(state.draft && state.draft.preferredRender) ||
				n.preferredRender ||
				'form'
		);
		var block = el('div', { className: 'wtt-preview__body' });
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.previewPreferredOnlyHint ||
					'Preview shows only the preferred render for this node (Editable + Display only). Change Preferred render in node settings.',
			})
		);

		if (!ObjectRender || typeof ObjectRender.renderForm !== 'function') {
			if (preferred === 'table') {
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
			fields.forEach(function (field) {
				var key = previewValueKey(scope, field);
				if (Object.prototype.hasOwnProperty.call(state.previewValues, key)) {
					var idKey =
						field && field.id != null
							? String(field.id)
							: String(field.name || '');
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
			form: i18n.previewAsForm || 'Form',
			table: i18n.previewAsTable || 'Table',
			compact: i18n.previewCompactHorizontal || 'Compact (horizontal)',
			'compact-vertical':
				i18n.previewCompactVertical || 'Compact (vertical)',
		};

		if (preferred === 'table') {
			block.appendChild(
				renderPreviewSurface(
					titleMap.table,
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
			preferred === 'compact' ||
			preferred === 'compact-vertical'
		) {
			if (typeof ObjectRender.renderCompact === 'function') {
				var orient =
					preferred === 'compact-vertical' ? 'vertical' : 'horizontal';
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
					titleMap.form,
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
				var aid = parseInt(src.attrId, 10) || 0;
				if (!aid) {
					return;
				}
				var raw = values[String(aid)];
				if (src.kind === 'attrPath') {
					var pathId = parseInt(src.pathAttrId, 10) || 0;
					var items = Array.isArray(raw) ? raw : raw != null ? [raw] : [];
					items.forEach(function (item) {
						if (!item || typeof item !== 'object') {
							return;
						}
						var v = item[String(pathId)];
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
	 * Hierarchy children used as automatic choice options (e.g. Währung → Euro / US Dollar).
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
					hasChildren: !!(c.hasChildren || (c.children && c.children.length)),
				};
			});
	}

	/**
	 * No attributes + hierarchy children → automatic CatalogChoice preview (list or tree by depth).
	 */
	function isAutomaticChoiceCatalogNode(n) {
		if (!n) {
			return false;
		}
		if (attributePreviewMembers(n).length) {
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
		return choiceCatalogPickRoots(n).length > 0;
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

	function resolveChoiceCatalogLabel(roots, id) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return '—';
		}
		var hit = findNodeInTree(roots, id) || findNodeInTree(state.tree, id);
		if (hit && hit.name) {
			return hit.name;
		}
		return '#' + id;
	}

	/**
	 * CatalogChoice control (Q90): depth ≤1 → flat <select>; depth ≥2 → tree picker.
	 */
	function renderChoiceCatalogControl(n, mode, scope) {
		var roots = choiceCatalogPickRoots(n);
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
				text: resolveChoiceCatalogLabel(roots, selectedId),
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
				getValue: function (opt) {
					return opt.id != null ? String(opt.id) : '';
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
			selectedLabel: resolveChoiceCatalogLabel(roots, selectedId),
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
					value: '…',
				})
			);
			row.appendChild(noteTd);
		} else {
			row.appendChild(el('td', { text: '…' }));
		}
		tbody.appendChild(row);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Automatic CatalogChoice preview (Q90): list when depth ≤1, tree when ≥2.
	 */
	function renderChoiceCatalogPreview(n) {
		var block = el('div', { className: 'wtt-preview__body' });
		var roots = choiceCatalogPickRoots(n);
		var mode = resolveCatalogChooserMode(roots, [], 'auto');
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
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsForm || 'Form',
				renderChoiceCatalogForm(n, 'edit'),
				renderChoiceCatalogForm(n, 'display')
			)
		);
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsTable || 'Table',
				renderChoiceCatalogTable(n, 'edit'),
				renderChoiceCatalogTable(n, 'display')
			)
		);
		return block;
	}

	function renderUnifiedPreviewContent(n) {
		var attrMembers = attributePreviewMembers(n);
		if (attrMembers.length) {
			return renderAttributeHostPreview(n, attrMembers);
		}

		/* No attributes + hierarchy children → CatalogChoice (list/tree by depth). */
		if (isAutomaticChoiceCatalogNode(n)) {
			return renderChoiceCatalogPreview(n);
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
						'Preview rebuild — add attributes to see Form, Table, and Compact samples.',
				})
			);
			return block;
		}

		if (usesRegistryPreview(n)) {
			return renderRegistryPreviewContent(n);
		}

		/* Media type catalog: Form + Table of all MIME kinds (no interactive std field). */
		if (isMediaTypeCatalogNode(n)) {
			return renderMediaKindsPreview(n);
		}

		/* Collection `table` datatype: band skeleton preview (gated by validator). */
		if (n.isTableTypeCatalog) {
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
						i18n.tableTypePreviewHint ||
						'Static preview of the table datatype. Bind Kopf / Zeile / Fuss on nodes that use this type.',
				})
			);
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					renderMultiColumnTablePreview(members, 'display', true),
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

		/* Unit catalog node: definition table + same quantity field view as any unit-typed slot. */
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
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsForm || 'Form',
					renderUnitUsageForm(members, n.name || '', 'edit'),
					renderUnitUsageForm(members, n.name || '', 'display')
				)
			);
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					renderUnitUsageTable(members, n.name || '', 'edit'),
					renderUnitUsageTable(members, n.name || '', 'display')
				)
			);
			return block;
		}

		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unifiedPreviewHint ||
					'Form and table layouts — editable row above, display mirror below (same fields).',
			})
		);

		/*
		 * Set form = one labeled row (e.g. "Abmessung (L/B/H)"), members inline —
		 * same composition idea as the single table cell. Non-set keeps stacked fields.
		 * Help is one popover: parent description, then children underneath.
		 */
		var setOpts = n.isSet ? setFieldOptionsFromNode(n) : {};
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
			tableDisplay = renderMultiColumnTablePreview(members, 'display', showFooter);
		} else {
			/*
			 * Set (and scalar) table context: the node is ONE field/column.
			 * Set members (L/B/H, …) live inside that cell — not as sibling columns.
			 * Column header uses the same setFieldCaption as the form label.
			 */
			var tableSetOpts = n.isSet
				? Object.assign({}, setOpts, { asSetField: true })
				: {};
			tableEdit = renderGenericFieldTablePreview(members, n.name || '', 'edit', tableSetOpts);
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

	function renderNodePreview(n, pane) {
		var block = el('div', { className: 'wtt-panel wtt-set-preview wtt-preview' });
		block.appendChild(
			el('h3', {
				className: 'wtt-panel__title wtt-set-preview__title',
				text: i18n.setPreview || 'Preview',
			})
		);
		block.appendChild(renderUnifiedPreviewContent(n));
		pane.appendChild(block);
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

	function renderDetail() {
		var pane = el('div', { className: 'wtt-detail-pane' });
		try {
			if (state.error) {
				pane.appendChild(el('p', { className: 'wtt-error', text: state.error }));
			}
			if (!state.selectedId) {
				pane.appendChild(el('p', { className: 'wtt-empty', text: i18n.selectHint }));
				return pane;
			}
			if (!state.selectedNode) {
				pane.appendChild(el('p', { className: 'wtt-empty', text: i18n.loading }));
				return pane;
			}

			var n = viewNode();
			var dirty = isSettingsDirty();
			var controlsLocked = saveViaButtonEnabled() && !!state.settingsSaving;

			pane.appendChild(renderDetailToolbar(n, dirty, controlsLocked));

			if (n.isTrash) {
				pane.appendChild(renderTrashPanel(n));
			}

			if (n.isTable || n.isTableTypeCatalog) {
				var tableValTop = resolveTableValidation(n);
				if (tableValTop && !tableValTop.ok) {
					pane.appendChild(renderTableValidationBanner(tableValTop, n));
				}
			}

			if (n.setParent && n.setParent.id) {
				var parentLink = el('button', {
					type: 'button',
					className: 'button-link',
					text: n.setParent.name || String(n.setParent.id),
					onClick: function () {
						selectNode(n.setParent.id);
					},
				});
				var parentLine = el('p', { className: 'wtt-set-parent' });
				parentLine.appendChild(document.createTextNode((i18n.setParent || 'Member of set') + ': '));
				parentLine.appendChild(parentLink);
				pane.appendChild(parentLine);
			}

			var form = el('div', { className: 'wtt-form wtt-detail' });
			var isDisplayName = typeKeyFromMember(n) === 'display_node_name';
			var parentId = parseInt(n.parent, 10) || 0;
			var parentStatic =
				parentId > 0
					? renderMetaStatic({
							label: i18n.parent || 'Parent',
							value: n.parentName || String(parentId),
							title: i18n.goToParent || 'Open parent in tree and settings',
							onClick: function () {
								selectNode(parentId);
							},
					  })
					: renderMetaStatic({
							label: i18n.parent || 'Parent',
							value: i18n.none || '—',
					  });

			/* Static meta at top — strip or form-row (same flagsAsFormProp switch). */
			var staticItems = [
				renderMetaStatic({
					label: i18n.termId || 'ID',
					value: n.id != null ? String(n.id) : '—',
					title: i18n.termIdHint || '',
					metaKey: 'id',
				}),
				parentStatic,
				renderMetaStatic({
					label: i18n.slug || 'Slug',
					value: n.slug || 'Slug',
					title: i18n.slugHint || '',
					metaKey: 'slug',
				}),
			];
			if (n.modified && (n.modified.userName || n.modified.atLabel)) {
				var modBy =
					n.modified.userName ||
					(n.modified.userId
						? '#' + String(n.modified.userId)
						: i18n.none || '—');
				staticItems.push(
					renderMetaStatic({
						label: i18n.lastModifiedBy || 'Last modified by',
						value: modBy,
						title: n.modified.atLabel
							? (i18n.lastModifiedAt || 'Last modified') +
							  ': ' +
							  n.modified.atLabel
							: '',
					})
				);
				if (n.modified.atLabel) {
					staticItems.push(
						renderMetaStatic({
							label: i18n.lastModifiedAt || 'Last modified',
							value: n.modified.atLabel,
						})
					);
				}
			}
			if (!caseStudyMode()) {
				staticItems.push(
					renderMetaStatic({
						label: i18n.count || 'Assigned posts',
						value: n.count != null ? n.count : 0,
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

			var nameInput = el('input', {
				type: 'text',
				id: 'wtt-node-name',
				className: 'wtt-name-input regular-text',
				value: n.name || '',
			});
			if (controlsLocked) {
				nameInput.disabled = true;
			}
			nameInput.addEventListener('input', function (e) {
				setDraftName(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.name || 'Name', [nameInput], {
					htmlFor: 'wtt-node-name',
					help: i18n.nameHint || '',
				})
			);

			var shortInput = el('input', {
				type: 'text',
				id: 'wtt-node-short-description',
				className: 'wtt-short-description-input regular-text',
				value: n.shortDescription || '',
				placeholder: '…',
			});
			if (controlsLocked) {
				shortInput.disabled = true;
			}
			shortInput.addEventListener('input', function (e) {
				setDraftShortDescription(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.shortDescription || 'Short description', [shortInput], {
					htmlFor: 'wtt-node-short-description',
					help: i18n.shortDescriptionHint || '',
				})
			);

			var descInput = el('textarea', {
				id: 'wtt-node-description',
				className: 'wtt-description-input large-text',
				rows: '3',
			});
			descInput.value = n.description || '';
			if (controlsLocked) {
				descInput.disabled = true;
			}
			descInput.addEventListener('input', function (e) {
				setDraftDescription(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.description || 'Description', [descInput], {
					htmlFor: 'wtt-node-description',
					className: 'wtt-form__row--description',
					help: {
						description: i18n.descriptionHint || '',
						helpChildren: n.helpChildren || [],
					},
				})
			);

			/*
			 * Q88: no Data type row on node detail — hierarchy datatype = parent (derived).
			 * Attribute field types stay on the Attributes panel (Q87), not here.
			 * Q76 Override / type picker chrome removed for hierarchy nodes.
			 */

			if (typeUsesRefScope(n)) {
				var blockedSelf = {};
				if (n.id) {
					blockedSelf[String(n.id)] = true;
				}
				var scopePicker = renderNodeTreePicker({
					roots: state.tree,
					selectedId: n.refScopeId || 0,
					selectedLabel:
						(n.refScope && n.refScope.name) ||
						(function () {
							var scopeNode = findNodeInTree(state.tree, n.refScopeId || 0);
							return scopeNode ? scopeNode.name : '';
						})(),
					compact: true,
					defaultOpen: !!(n.refScopeId || 0),
					expandKey: 'ref-scope:' + String(n.id || 0),
					allowRoot: false,
					allowClear: true,
					disabled: !!controlsLocked,
					blockedIds: blockedSelf,
					pickedPrefix: i18n.nodePickerSelected || 'Selected:',
					placeholder: i18n.refScopeChoose || 'Choose catalog root…',
					dialogTitle: i18n.refScope || 'Catalog root (ref_scope)',
					onSelect: function (id) {
						setDraftRefScope(id);
					},
				});
				var scopeHelp =
					typeKeyFromMember(n) === 'node_ref'
						? i18n.refScopeHintNodeRef ||
						  'node_ref: pick only among descendants under this root.'
						: i18n.refScopeHintEmbed ||
						  'node_embed: direct children of this root are selectable; their fields are embedded after pick.';
				form.appendChild(
					formRow(i18n.refScope || 'Catalog root (ref_scope)', [scopePicker], {
						help: scopeHelp,
					})
				);

				var multCurrent = String(n.fieldMultiplicity || '0..1');
				var multSelect = el('select', {
					className: 'wtt-field-multiplicity-select',
					disabled: !!controlsLocked,
					title:
						i18n.fieldMultiplicityHint ||
						'How many targets this field may pick at runtime (1..* = multi-select). Not the Mult. on has_type / ref_scope relations.',
					onChange: function (e) {
						setDraftFieldMultiplicity(e.target.value);
					},
				});
				relationMultiplicityOptions(n).forEach(function (opt) {
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
								'Runtime picks: 0..1 / 1 = one id; 0..* / 1..* = many ids. Distinct from Relations Mult. on has_type / ref_scope.',
						}
					)
				);

				if (n.refScopeId) {
					var candidates = catalogChildCandidates(n.refScopeId);
					var allowedSet = {};
					var hasExplicitAllow = Array.isArray(n.allowedRefIds) && n.allowedRefIds.length > 0;
					(n.allowedRefIds || []).forEach(function (id) {
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
							check.checked = !hasExplicitAllow || !!allowedSet[cid];
							if (controlsLocked) {
								check.disabled = true;
							}
							check.addEventListener('change', function () {
								var next = [];
								var boxes = allowWrap.querySelectorAll('.wtt-allowed-ref-check');
								var allOn = true;
								boxes.forEach(function (box) {
									if (box.checked) {
										var idAttr = box.id || '';
										var id = parseInt(idAttr.replace('wtt-allowed-ref-', ''), 10) || 0;
										if (id > 0) {
											next.push(id);
										}
									} else {
										allOn = false;
									}
								});
								/* Default = all → store empty allowlist. */
								setDraftAllowedRefIds(allOn ? [] : next);
							});
							label.appendChild(check);
							label.appendChild(
								document.createTextNode(' ' + (child.name || '#' + child.id))
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

			if (i18n.typePresetsHint && isUnderTypenBranch(n.id)) {
				form.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text: i18n.typePresetsHint,
					})
				);
			}

			var fixedControl = renderFixedValueField(n, controlsLocked);
			if (fixedControl) {
				form.appendChild(
					formRow(i18n.fixedValue || 'Fixed value', [fixedControl], {
						className: 'wtt-form__row--fixed',
						help: fixedFieldHelpText(n),
					})
				);
			}

			var footerOpRow = renderFooterOpPicker(n, controlsLocked);
			if (footerOpRow) {
				form.appendChild(footerOpRow);
			}

			/* Flags: is_datatype / is_abstract / required. Q76 Inheriting chrome removed (Q88). */
			var flagItems = [
				renderMetaCheck({
					label: i18n.isDatatype || 'Is data type',
					checked: !!n.isDatatype,
					disabled: !!controlsLocked,
					title: i18n.isDatatypeHint || '',
					onChange: setDraftIsDatatype,
				}),
				renderMetaCheck({
					label: i18n.isAbstract || 'Is abstract',
					checked: !!n.isAbstract,
					disabled: !!controlsLocked,
					title: i18n.isAbstractHint || '',
					onChange: setDraftIsAbstract,
				}),
				caseStudyMode() || isDisplayName
					? null
					: renderMetaCheck({
							id: 'wtt-node-required',
							label: i18n.required || 'Required',
							checked: !!n.required,
							disabled: !!controlsLocked,
							title: i18n.requiredHint || '',
							onChange: setDraftRequired,
					  }),
			];
			var flagsStrip = renderMetaStrip('flags', flagItems);
			if (flagsAsFormRowEnabled()) {
				flagsStrip.classList.add('wtt-form__meta-strip--in-row');
				form.appendChild(
					formRow(i18n.nodeFlags || 'Flags', [flagsStrip], {
						className: 'wtt-form__row--flags',
						help: i18n.nodeFlagsHint || '',
					})
				);
			} else {
				form.appendChild(flagsStrip);
			}

			var preferredSelect = el('select', {
				className: 'wtt-preferred-render-select',
				disabled: !!controlsLocked,
				onChange: function (e) {
					setDraftPreferredRender(e.target.value);
				},
			});
			[
				{
					value: 'form',
					label: i18n.preferredRenderForm || 'Form',
				},
				{
					value: 'table',
					label: i18n.preferredRenderTable || 'Table',
				},
				{
					value: 'compact',
					label: i18n.preferredRenderCompact || 'Compact (horizontal)',
				},
				{
					value: 'compact-vertical',
					label:
						i18n.preferredRenderCompactVertical ||
						'Compact (vertical)',
				},
			].forEach(function (opt) {
				preferredSelect.appendChild(
					el('option', {
						value: opt.value,
						text: opt.label,
						selected:
							normalizePreferredRender(
								(state.draft && state.draft.preferredRender) ||
									(n && n.preferredRender) ||
									'form'
							) === opt.value,
					})
				);
			});
			form.appendChild(
				formRow(
					i18n.preferredRender || 'Preferred render',
					[preferredSelect],
					{
						help:
							i18n.preferredRenderHint ||
							'Default layout for admin preview and Object View (when the block layout is Node preferred).',
					}
				)
			);

			form.className = (form.className || '') + ' wtt-panel wtt-detail-form';
			pane.appendChild(form);

			if (n.isTable || n.isTableTypeCatalog) {
				var bandUi = renderTableBandBindings(n, controlsLocked);
				if (bandUi) {
					pane.appendChild(bandUi);
				}
			}

			/* Attributes → Preview → Relations (Preview above the fold for attribute hosts). */
			renderNodeAttributes(n, pane, controlsLocked);
			if (!isUnderRelationstypenBranch(n.id) && !n.isTrash) {
				try {
					renderNodePreview(n, pane);
				} catch (previewErr) {
					pane.appendChild(
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
			}
			renderNodeRelations(n, pane);
			if (!caseStudyMode()) {
				renderChildExtrasOnParent(n, pane);
				renderSetMembers(n, pane);
				renderTypeBranch(n, pane);
				renderSetSettings(n, pane);
				renderEnumValuesSettings(n, pane, controlsLocked);
				renderMediaSettings(n, pane);
				renderDateSettings(n, pane);
			} else if (n.isSet) {
				renderSetMembers(n, pane);
			} else if (n.isConcreteEnum) {
				renderEnumValuesSettings(n, pane, controlsLocked);
			}
		} catch (err) {
			pane.appendChild(
				el('p', {
					className: 'wtt-error',
					text: (i18n.error || 'Something went wrong.') + ' ' + String(err && err.message ? err.message : err),
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
		state.draft = null;
		state.savedDraft = null;
		state.settingsSaving = false;
		state.error = '';
		state.expanded = {};
		(state.tree || []).forEach(function (n) {
			if (!n || !n.id) {
				return;
			}
			if (n.name === 'BOM Testprojekt' || n.name === 'Fallstudie') {
				state.expanded[n.id] = true;
			}
		});
		persistTreeUi();
		render();
	}

	function resetDemo() {
		var msg = i18n.confirmReset || 'Reset test tree?';
		if (!window.confirm(msg)) {
			return;
		}
		post('wtt_reset_demo', {})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				applyDemoTree(json.data.tree);
			})
			.catch(function () {
				setError(i18n.error);
			});
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
		toolbarChildren.push(
			el('button', {
				type: 'button',
				className: 'button button-primary',
				text: i18n.addRoot,
				onClick: function () {
					createTerm(0);
				},
			}),
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.expandAll || 'Expand',
				title: i18n.expandAllHint || 'Expand all nodes',
				onClick: expandAllTree,
			}),
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.collapseAll || 'Collapse',
				title: i18n.collapseAllHint || 'Collapse all nodes',
				onClick: collapseAllTree,
			})
		);
		if (cfg.testMode) {
			toolbarChildren.push(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.resetDemo || 'Reset test tree',
					onClick: resetDemo,
				})
			);
		}
		var toolbar = el('div', { className: 'wtt-toolbar' }, toolbarChildren);

		var treeList = el('ul', { className: 'wtt-tree' });
		if (!state.tree.length) {
			treeList.appendChild(el('li', { className: 'wtt-empty', text: i18n.empty }));
		} else {
			renderTreeNodes(state.tree, treeList);
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
				intro.textContent = (i18n.inspecting || 'Inspecting:') + ' ' + state.selectedNode.name;
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
		if (window.WTTNodeRender && typeof window.WTTNodeRender.configure === 'function') {
			window.WTTNodeRender.configure({
				resolveTypeKey: function (node) {
					return resolveNodeRenderTypeKey(node);
				},
				i18n: {
					boolTrue: i18n.boolTrue || 'true',
					boolFalse: i18n.boolFalse || 'false',
					previewFooter: i18n.previewFooter || 'Footer',
					previewColGeneric: i18n.previewColGeneric || 'Column',
					emailInvalid: i18n.emailInvalid || 'Enter a valid email address.',
				},
			});
		}
		restoreTreeUi();
		document.addEventListener('keydown', onTreeCopyKeydown);
		if (state.selectedId) {
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

