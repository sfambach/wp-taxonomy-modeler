/**
 * Node presentation — Registry + type renderers (not a factory).
 *
 * Single path for admin preview, backend chrome, and future frontend:
 *   WTTNodeRender.Registry.render(node, context)
 *   WTTNodeRender.Registry.renderLabel(node, context) — field designation
 *   WTTNodeRender.Registry.renderContent(node, context, readonly) — value / control
 *   WTTNodeRender.Registry.renderTreeNode(node, context) — taxonomy tree row name
 *
 * Context: { name: 'tree'|'form'|'table'|…, mode: 'edit'|'display', value, onInput, … }
 * `readonly` on renderContent forces display output (no editable control).
 * Renderer methods return HTMLElement | DocumentFragment | false.
 * Registry methods return HTMLElement | null.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var renderers = [];
	var resolveTypeKeyFn = null;
	/** Q96: catalogBindings map (`builtin.int` → term id) for id→Registry reverse lookup. */
	var catalogBindingsMap = null;
	var i18nLabels = {
		boolTrue: 'true',
		boolFalse: 'false',
		previewFooter: 'Footer',
		previewColGeneric: 'Column',
		emailInvalid: 'Enter a valid email address.',
		intInvalid: 'Enter a whole number.',
	};

	/** Scalar catalog types with dedicated NodeRenderers (not set/list/…). */
	var SIMPLE_SCALAR_KEYS = {
		int: true,
		char: true,
		double: true,
		text: true,
		textarea: true,
		bool: true,
		email: true,
		date: true,
		time: true,
		datetime: true,
		color: true,
		media: true,
	};

	/** Collection / structured types with dedicated renderers. */
	var STRUCTURED_TYPE_KEYS = {
		table: true,
		enum: true,
		node_ref: true,
		quantity: true,
	};

	/**
	 * Table Fuss cell ops (Q57).
	 * Numeric ops only for int/double columns; text/none/count may apply more widely.
	 * avg = Durchschnitt / Mittelwert (arithmetic mean) — one op, not two.
	 */
	var FOOTER_OPS = {
		none: {
			key: 'none',
			numeric: false,
			symbol: '—',
			label: 'None',
		},
		text: {
			key: 'text',
			numeric: false,
			symbol: '—',
			label: 'Text',
		},
		sum: {
			key: 'sum',
			numeric: true,
			symbol: 'Σ',
			label: 'Sum',
		},
		avg: {
			key: 'avg',
			numeric: true,
			symbol: 'Ø',
			label: 'Average',
		},
		min: {
			key: 'min',
			numeric: true,
			symbol: 'min',
			label: 'Minimum',
		},
		max: {
			key: 'max',
			numeric: true,
			symbol: 'max',
			label: 'Maximum',
		},
		count: {
			key: 'count',
			numeric: false,
			symbol: 'n',
			label: 'Count',
		},
	};

	/** @deprecated Alias — prefer `text`. */
	FOOTER_OPS.label = FOOTER_OPS.text;

	function normalizeFooterOp(op, typeKey) {
		var key = String(op || '')
			.trim()
			.toLowerCase();
		if (key === 'label') {
			key = 'text';
		}
		if (key === 'average' || key === 'mean' || key === 'mittelwert' || key === 'durchschnitt') {
			key = 'avg';
		}
		if (key === 'summe') {
			key = 'sum';
		}
		if (!FOOTER_OPS[key]) {
			key = isNumericTypeKey(typeKey) ? 'sum' : 'text';
		}
		var def = FOOTER_OPS[key];
		if (def.numeric && !isNumericTypeKey(typeKey) && key !== 'count') {
			return FOOTER_OPS.text;
		}
		return def;
	}

	function footerOpList(opts) {
		opts = opts || {};
		var typeKey = opts.typeKey != null ? String(opts.typeKey) : '';
		var numeric = typeKey ? isNumericTypeKey(typeKey) : null;
		var numericOnly = !!opts.numericOnly;
		var out = [];
		Object.keys(FOOTER_OPS).forEach(function (k) {
			if (k === 'label') {
				return;
			}
			var def = FOOTER_OPS[k];
			if (numericOnly && !def.numeric && k !== 'count' && k !== 'text' && k !== 'none') {
				return;
			}
			if (numeric === false && def.numeric) {
				return;
			}
			out.push(def);
		});
		return out;
	}

	function createEl(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'className') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (attrs[key] === false || attrs[key] == null) {
				return;
			} else if (attrs[key] === true) {
				node.setAttribute(key, key);
			} else {
				node.setAttribute(key, String(attrs[key]));
			}
		});
		if (children != null) {
			(Array.isArray(children) ? children : [children]).forEach(function (child) {
				if (child) {
					node.appendChild(child);
				}
			});
		}
		return node;
	}

	function defaultResolveTypeKey(node) {
		if (!node) {
			return '';
		}
		/*
		 * Q96: prefer typeId / self id → builtin.* binding over leaf name.
		 * Name match below remains debt fallback when bindings are unbound.
		 */
		var typeId =
			node.typeId != null
				? node.typeId
				: node.type && node.type.id != null
					? node.type.id
					: 0;
		var fromTypeBind = registryIdFromBindings(typeId);
		if (fromTypeBind) {
			return fromTypeBind;
		}
		var fromSelfBind = registryIdFromBindings(node.id);
		if (fromSelfBind) {
			return fromSelfBind;
		}
		var name = '';
		if (typeof node.type === 'string') {
			name = node.type;
		} else if (node.type && node.type.name) {
			name = node.type.name;
		} else if (node.typeLabel) {
			name = node.typeLabel;
		} else if (node.typeName) {
			name = node.typeName;
		} else if (node.typeKey) {
			name = node.typeKey;
		}
		name = String(name).trim().toLowerCase();
		if (name === 'integer') {
			name = 'int';
		}
		if (
			name === 'measure' ||
			name === 'groesse' ||
			name === 'größe' ||
			name === 'grose'
		) {
			name = 'quantity';
		}
		var leaf = node.name
			? String(node.name)
					.trim()
					.toLowerCase()
			: '';
		if (leaf === 'integer') {
			leaf = 'int';
		}
		if (leaf === 'boolean') {
			leaf = 'bool';
		}
		if (leaf === 'float' || leaf === 'number') {
			leaf = 'double';
		}
		/*
		 * Debt: type-catalog leaf name is the type (int); Q88 may set type to the
		 * parent branch (Simple). Prefer a known leaf name over a non-scalar type.
		 */
		if (
			leaf &&
			(SIMPLE_SCALAR_KEYS[leaf] || STRUCTURED_TYPE_KEYS[leaf]) &&
			(!name ||
				(!SIMPLE_SCALAR_KEYS[name] && !STRUCTURED_TYPE_KEYS[name]))
		) {
			return leaf;
		}
		if (!name && leaf && !node.typeId) {
			name = leaf;
		}
		return name;
	}

	/**
	 * Q96: term id → Registry id via `builtin.*` catalog bindings.
	 * @param {number|string} termId
	 * @return {string}
	 */
	function registryIdFromBindings(termId) {
		var id = parseInt(termId, 10) || 0;
		if (id <= 0 || !catalogBindingsMap || typeof catalogBindingsMap !== 'object') {
			return '';
		}
		var prefix = 'builtin.';
		var key;
		for (key in catalogBindingsMap) {
			if (
				!Object.prototype.hasOwnProperty.call(catalogBindingsMap, key) ||
				key.indexOf(prefix) !== 0
			) {
				continue;
			}
			if ((parseInt(catalogBindingsMap[key], 10) || 0) === id) {
				return String(key.slice(prefix.length)).toLowerCase();
			}
		}
		return '';
	}

	function resolveTypeKey(node) {
		if (typeof resolveTypeKeyFn === 'function') {
			var custom = resolveTypeKeyFn(node);
			if (custom != null && String(custom) !== '') {
				return String(custom).toLowerCase();
			}
		}
		return defaultResolveTypeKey(node);
	}

	function compositionMembers(node) {
		if (!node) {
			return [];
		}
		/*
		 * Only true set composition expands into child fields here.
		 * quantitySchema is painted as one trinity control by QuantityRenderer —
		 * do not recurse Typ/Praefix/Kuerzel as separate labeled rows.
		 */
		if (Array.isArray(node.setMembers) && node.setMembers.length) {
			return node.setMembers;
		}
		return [];
	}

	function quantitySchemaMembers(node) {
		if (
			node &&
			node.quantitySchema &&
			Array.isArray(node.quantitySchema.members) &&
			node.quantitySchema.members.length
		) {
			return node.quantitySchema.members;
		}
		return [];
	}

	function isQuantityTypeKey(key) {
		key = String(key || '')
			.trim()
			.toLowerCase();
		return (
			key === 'quantity' ||
			key === 'measure' ||
			key === 'groesse' ||
			key === 'größe' ||
			key === 'grose'
		);
	}

	/**
	 * Unit attr chrome: catalog leaves (fixedOptions) or With-prefix composition
	 * (Praefix + Kuerzel typeProperties) — not name-only.
	 */
	function unitAttrHasChrome(attr) {
		if (!attr) {
			return false;
		}
		if (attr.quantitySchema && attr.quantitySchema.members) {
			return true;
		}
		if (Array.isArray(attr.fixedOptions) && attr.fixedOptions.length) {
			return true;
		}
		var props = Array.isArray(attr.typeProperties)
			? attr.typeProperties
			: [];
		if (!props.length) {
			return false;
		}
		var hasPrefix = false;
		var hasSymbol = false;
		props.forEach(function (p) {
			if (!p) {
				return;
			}
			var n = String(p.name || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue')
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe');
			if (n === 'praefix' || n === 'prefix') {
				hasPrefix = true;
			}
			if (n === 'kuerzel' || n === 'symbol' || n === 'einheit' || n === 'unit') {
				hasSymbol = true;
			}
		});
		return hasPrefix || hasSymbol;
	}

	function qtyMemberNameKey(member) {
		return String((member && member.name) || '')
			.toLowerCase()
			.replace(/ü/g, 'ue')
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe');
	}

	function qtyMemberRoleAliases(role) {
		var map = {
			typ: ['typ', 'wert', 'value', 'magnitude', 'betrag'],
			praefix: ['praefix', 'prefix', 'prafix'],
			kuerzel: ['kuerzel', 'einheit', 'unit', 'symbol', 'waehrung', 'currency'],
		};
		return map[role] || [role];
	}

	function findQtyMember(members, nameKey) {
		var aliases = qtyMemberRoleAliases(nameKey);
		var found = null;
		(members || []).forEach(function (m) {
			if (found) {
				return;
			}
			var key = qtyMemberNameKey(m);
			if (aliases.indexOf(key) !== -1) {
				found = m;
			}
		});
		return found;
	}

	/**
	 * Parse stored quantity value: plain magnitude, or JSON {mag,prefix}.
	 */
	function parseQuantityStore(raw) {
		var s = raw == null ? '' : String(raw).trim();
		if (!s) {
			return { mag: '', prefix: '', unit: '' };
		}
		if (s.charAt(0) === '{') {
			try {
				var obj = JSON.parse(s);
				if (obj && typeof obj === 'object') {
					return {
						mag:
							obj.mag != null
								? String(obj.mag)
								: obj.v != null
									? String(obj.v)
									: '',
						prefix:
							obj.prefix != null
								? String(obj.prefix)
								: obj.p != null
									? String(obj.p)
									: '',
						unit:
							obj.unit != null
								? String(obj.unit)
								: obj.u != null
									? String(obj.u)
									: '',
					};
				}
			} catch (e) {
				/* fall through */
			}
		}
		var m = s.match(/^(-?\d+(?:[.,]\d+)?)/);
		return {
			mag: m ? m[1].replace(',', '.') : s,
			prefix: '',
			unit: '',
		};
	}

	function serializeQuantityStore(mag, prefix, unit) {
		var m = mag != null ? String(mag) : '';
		var p = prefix != null ? String(prefix) : '';
		var u = unit != null ? String(unit) : '';
		if (!p && !u) {
			return m;
		}
		var out = { mag: m };
		if (p) {
			out.prefix = p;
		}
		if (u) {
			out.unit = u;
		}
		return JSON.stringify(out);
	}

	function qtySymbolFromMembers(members) {
		var kuerzel = findQtyMember(members, 'kuerzel');
		if (!kuerzel) {
			return '';
		}
		var fixed = kuerzel.fixed;
		if (fixed && typeof fixed === 'object') {
			var short =
				fixed.shortDescription != null
					? String(fixed.shortDescription).trim()
					: '';
			if (short && short.length <= 3) {
				return short;
			}
			if (fixed.name) {
				return String(fixed.name);
			}
		}
		if (kuerzel.fixedLiteral) {
			return String(kuerzel.fixedLiteral);
		}
		var ownShort =
			kuerzel.shortDescription != null
				? String(kuerzel.shortDescription).trim()
				: '';
		if (ownShort && ownShort.length <= 3) {
			return ownShort;
		}
		return '';
	}

	/**
	 * Q117 presentation map on a node (flat or draft.values).
	 *
	 * @param {Object|null} node
	 * @return {Object|null}
	 */
	function presentationMapFromNode(node) {
		if (!node || typeof node !== 'object') {
			return null;
		}
		var p = node.presentation;
		if (!p || typeof p !== 'object') {
			return null;
		}
		if (Object.prototype.hasOwnProperty.call(p, 'loaded') && !p.loaded) {
			return null;
		}
		if (p.values && typeof p.values === 'object') {
			return p.values;
		}
		return p;
	}

	/**
	 * Unit leaf symbol from Display → Presentation (Q117).
	 * Preferred over stale Kuerzel Festwert / shortDescription when set.
	 *
	 * @param {Object|null} node
	 * @return {string}
	 */
	function presentationUnitSymbol(node) {
		var p = presentationMapFromNode(node);
		if (!p) {
			return '';
		}
		var sym = p.symbol != null ? String(p.symbol).trim() : '';
		if (sym) {
			return sym;
		}
		var table = p.table != null ? String(p.table).trim() : '';
		return table;
	}

	/**
	 * SI prefix closed-label: shortDescription / symbol when short, else letter heuristic.
	 * Full display name stays on option title for hover.
	 */
	function qtyPrefixSymbolFromOption(o) {
		if (!o) {
			return '';
		}
		var short =
			o.shortDescription != null
				? String(o.shortDescription).trim()
				: o.symbol != null
					? String(o.symbol).trim()
					: '';
		if (short && short.length <= 3) {
			return short;
		}
		var name = o.name != null ? String(o.name) : '';
		if (!name) {
			return '';
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

	function qtyPrefixOptions(praefixMem) {
		var opts = [];
		if (!praefixMem) {
			return opts;
		}
		var branch = praefixMem.typeBranch;
		var list =
			(branch && Array.isArray(branch.children) && branch.children) ||
			(branch && Array.isArray(branch.options) && branch.options) ||
			(Array.isArray(praefixMem.subtreeOptions) && praefixMem.subtreeOptions) ||
			(Array.isArray(praefixMem.fixedOptions) && praefixMem.fixedOptions) ||
			[];
		list.forEach(function (o) {
			if (!o) {
				return;
			}
			/* Unit allowlist marks disabled prefixes with enabled:false. */
			if (Object.prototype.hasOwnProperty.call(o, 'enabled') && !o.enabled) {
				return;
			}
			var name = o.name != null ? String(o.name) : '';
			if (!name) {
				return;
			}
			var mult = o.multiplikator;
			var multNum =
				mult != null && mult !== '' && isFinite(Number(mult)) && Number(mult) > 0
					? Number(mult)
					: null;
			var letter = qtyPrefixSymbolFromOption(o);
			opts.push({
				id: o.id != null ? String(o.id) : name,
				name: name,
				letter: letter || name,
				shortDescription:
					o.shortDescription != null ? String(o.shortDescription) : '',
				multiplikator: multNum,
			});
		});
		return opts;
	}

	function quantityApi() {
		return (
			(global.WTTConverter && global.WTTConverter.Quantity) || null
		);
	}

	function qtyPrefixRootToSi(node) {
		var schema = node && node.quantitySchema;
		if (!schema) {
			return 1;
		}
		var raw =
			schema.prefixRootToSi != null
				? schema.prefixRootToSi
				: schema.prefix_root_to_si;
		var n = Number(raw);
		return isFinite(n) && n > 0 ? n : 1;
	}

	function qtyPrefixLetter(prefixName, options) {
		var name = String(prefixName || '');
		if (!name) {
			return '';
		}
		var i;
		for (i = 0; i < (options || []).length; i++) {
			var opt = options[i];
			if (
				opt &&
				(opt.name === name ||
					String(opt.id) === name ||
					opt.letter === name)
			) {
				return opt.letter || qtyPrefixSymbolFromOption(opt) || name;
			}
		}
		return qtyPrefixSymbolFromOption({ name: name });
	}

	function qtyUnitOptions(unitMem) {
		var opts = [];
		if (!unitMem) {
			return opts;
		}
		var branch = unitMem.typeBranch;
		var list =
			(branch && Array.isArray(branch.children) && branch.children) ||
			(Array.isArray(unitMem.fixedOptions) && unitMem.fixedOptions) ||
			(Array.isArray(unitMem.subtreeOptions) && unitMem.subtreeOptions) ||
			[];
		list.forEach(function (o) {
			if (!o) {
				return;
			}
			if (Object.prototype.hasOwnProperty.call(o, 'enabled') && !o.enabled) {
				return;
			}
			var name = o.name != null ? String(o.name) : '';
			if (!name) {
				return;
			}
			var letter = qtyPrefixSymbolFromOption(o);
			opts.push({
				id: o.id != null ? String(o.id) : name,
				name: name,
				letter: letter || name,
				shortDescription:
					o.shortDescription != null ? String(o.shortDescription) : '',
				/* Keep allowlist for unit-switch → Praefix rebuild (size / SI). */
				allowedPrefixes: Array.isArray(o.allowedPrefixes)
					? o.allowedPrefixes
					: [],
			});
		});
		return opts;
	}

	function qtyUnitLabel(unitName, options) {
		var name = String(unitName || '');
		if (!name) {
			return '';
		}
		var i;
		for (i = 0; i < (options || []).length; i++) {
			var opt = options[i];
			if (opt && (opt.name === name || String(opt.id) === name)) {
				return opt.letter || name;
			}
		}
		return name;
	}

	/**
	 * Unit combi only: Prefix? + Symbol (Q120 marriage). No magnitude.
	 * Used by UnitRenderer alone and inside QuantityRenderer (OQ-R8).
	 */
	function renderUnitControl(node, context) {
		var members = quantitySchemaMembers(node);
		var parsed = parseQuantityStore(readValue(context, ''));
		var praefixMem = findQtyMember(members, 'praefix');
		var prefixOpts = qtyPrefixOptions(praefixMem);
		var prefixName = parsed.prefix ? String(parsed.prefix) : '';
		/* Live Default on Praefix attr → quantitySchema.sample (PHP overlay). */
		if (
			!prefixName &&
			praefixMem &&
			(praefixMem.sample != null || praefixMem.fixedLiteral)
		) {
			prefixName = String(
				praefixMem.sample != null && String(praefixMem.sample) !== ''
					? praefixMem.sample
					: praefixMem.fixedLiteral || ''
			);
		}
		var unitMem = findQtyMember(members, 'kuerzel');
		var unitOpts = qtyUnitOptions(unitMem);
		/* CatalogChoice units: multi = chooser; sole = Q116 auto + gray. */
		var multiUnitChoice = unitOpts.length > 1;
		var soleUnitChoice = unitOpts.length === 1;
		var symbol = multiUnitChoice || soleUnitChoice
			? ''
			: String(qtySymbolFromMembers(members) || '');
		/*
		 * Basiseinheit / fixed-Kuerzel unit leaf: Q117 Presentation.symbol|table
		 * wins over leftover Kuerzel Festwert (e.g. renamed Gramm still had "kg").
		 * Catalog multi-unit pick keeps the selected option label.
		 */
		var presentationSym = presentationUnitSymbol(node);
		if (presentationSym && !multiUnitChoice) {
			symbol = presentationSym;
		}
		var unitName = '';
		if (unitMem) {
			unitName =
				(parsed.unit != null && String(parsed.unit)) ||
				(unitMem.sample != null && String(unitMem.sample)) ||
				(unitOpts[0] && unitOpts[0].name) ||
				'';
			if (unitOpts.length) {
				var knownUnit = false;
				unitOpts.forEach(function (o) {
					if (
						o &&
						(o.name === unitName || String(o.id) === String(unitName))
					) {
						knownUnit = true;
					}
				});
				if (!knownUnit) {
					unitName = unitOpts[0].name;
				}
			}
			if (!symbol) {
				symbol = qtyUnitLabel(unitName, unitOpts);
			}
		}
		var prefixLetter = qtyPrefixLetter(prefixName, prefixOpts);

		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value wtt-preview-quantity wtt-node-render--unit is-compact',
				text: String(prefixLetter || '') + String(symbol || '—'),
			});
		}

		var group = createEl('div', {
			className: 'wtt-preview-quantity wtt-node-render--unit is-compact',
		});
		var noneLabel =
			(global.wttTree &&
				global.wttTree.i18n &&
				global.wttTree.i18n.unitConvNone) ||
			'—';
		var noneTitle =
			(global.wttTree &&
				global.wttTree.i18n &&
				global.wttTree.i18n.unitConvNoneTitle) ||
			'No prefix';
		var livePrefixOpts = prefixOpts.slice();
		/*
		 * Q116: Mult 1 / 1..* → no empty —; 0..1 / 0..* → empty required.
		 * Praefix Mult on the unit (attribute) drives this — not “always empty”.
		 */
		var prefixAllowEmpty = true;
		if (praefixMem) {
			if (praefixMem.allowsEmpty != null) {
				prefixAllowEmpty = !!praefixMem.allowsEmpty;
			} else {
				var pMult = String(praefixMem.multiplicity || '');
				var pReq =
					praefixMem.required === true ||
					praefixMem.required === 1 ||
					praefixMem.required === '1';
				prefixAllowEmpty =
					!pReq && pMult !== '1' && pMult !== '1..*';
			}
		}

		function fillPrefixSelect(selectEl, optsList, selectedName, allowEmpty) {
			allowEmpty = allowEmpty !== false;
			while (selectEl.firstChild) {
				selectEl.removeChild(selectEl.firstChild);
			}
			var noneOpt = null;
			if (allowEmpty) {
				noneOpt = createEl('option', {
					value: '',
					text: String(noneLabel),
					title: String(noneTitle),
				});
				if (!selectedName) {
					noneOpt.selected = true;
				}
				selectEl.appendChild(noneOpt);
			}
			(optsList || []).forEach(function (opt) {
				var letter = opt.letter || '';
				var label =
					letter && opt.name && letter !== opt.name
						? String(letter) + ' · ' + String(opt.name)
						: opt.name || letter;
				var o = createEl('option', {
					value: opt.name,
					text: label,
					title: opt.name,
				});
				if (opt.name === selectedName || String(opt.id) === selectedName) {
					o.selected = true;
					if (noneOpt) {
						noneOpt.selected = false;
					}
				}
				selectEl.appendChild(o);
			});
			/* Required Mult: no empty → ensure a real option is selected. */
			if (!allowEmpty && !selectEl.value && selectEl.options.length) {
				selectEl.options[0].selected = true;
			}
			selectEl.title = selectEl.value
				? selectEl.options[selectEl.selectedIndex].title ||
				  selectEl.options[selectEl.selectedIndex].text
				: String(noneTitle);
		}

		function prefixOptsFromUnitPick(pickName) {
			var pick = null;
			unitOpts.forEach(function (o) {
				if (
					!pick &&
					o &&
					(o.name === pickName || String(o.id) === String(pickName))
				) {
					pick = o;
				}
			});
			if (!pick) {
				return [];
			}
			return qtyPrefixOptions({
				fixedOptions: Array.isArray(pick.allowedPrefixes)
					? pick.allowedPrefixes
					: [],
				typeBranch: {
					children: Array.isArray(pick.allowedPrefixes)
						? pick.allowedPrefixes
						: [],
				},
			});
		}

		var prefixSelect = null;
		if (praefixMem && (livePrefixOpts.length || multiUnitChoice)) {
			prefixSelect = createEl('select', {
				className:
					'wtt-type-select wtt-preview-quantity__prefix wtt-preview-input--prefix',
			});
			fillPrefixSelect(
				prefixSelect,
				livePrefixOpts,
				prefixName,
				prefixAllowEmpty
			);
			if (!livePrefixOpts.length) {
				prefixSelect.style.display = 'none';
			} else if (!prefixAllowEmpty && livePrefixOpts.length === 1) {
				/* Q116: required Praefix with one allowed prefix → auto + gray. */
				prefixSelect.disabled = true;
				prefixSelect.classList.add('is-sole-locked');
				prefixSelect.title =
					(global.wttTree &&
						global.wttTree.i18n &&
						global.wttTree.i18n.soleSelectLockedHint) ||
					'Only one choice — selected automatically.';
			}
			group.appendChild(prefixSelect);
		}

		var unitSelect = null;
		if (
			multiUnitChoice ||
			soleUnitChoice ||
			(!qtySymbolFromMembers(members) && unitOpts.length)
		) {
			unitSelect = createEl('select', {
				className:
					'wtt-type-select wtt-preview-quantity__unit wtt-preview-input--unit-labeled wtt-catalog-choice-select',
			});
			unitOpts.forEach(function (opt) {
				var label =
					(opt.letter || '') && opt.name && opt.letter !== opt.name
						? String(opt.letter) + ' · ' + String(opt.name)
						: opt.letter || opt.name;
				var o = createEl('option', {
					value: opt.name,
					text: label,
					title: opt.name,
				});
				if (opt.name === unitName || String(opt.id) === unitName) {
					o.selected = true;
				}
				unitSelect.appendChild(o);
			});
			/* Q116: sole required unit → auto-selected + gray. */
			if (soleUnitChoice || (unitMem && unitMem.soleOptionLocked)) {
				unitSelect.disabled = true;
				unitSelect.classList.add('is-sole-locked');
				unitSelect.title =
					(global.wttTree &&
						global.wttTree.i18n &&
						global.wttTree.i18n.soleSelectLockedHint) ||
					'Only one choice — selected automatically.';
			}
			group.appendChild(unitSelect);
		} else if (symbol) {
			group.appendChild(
				createEl('span', {
					className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
					text: symbol,
				})
			);
		} else {
			group.appendChild(
				createEl('span', {
					className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
					text: '—',
				})
			);
		}

		var prevPrefix = prefixSelect ? prefixSelect.value : '';
		if (context && typeof context.onUnitPartChange === 'function') {
			var emitUnit = function () {
				context.onUnitPartChange({
					prefix:
						prefixSelect &&
						prefixSelect.style.display !== 'none' &&
						prefixSelect.value
							? String(prefixSelect.value)
							: '',
					unit:
						unitSelect && unitSelect.value
							? String(unitSelect.value)
							: '',
				});
			};
			if (prefixSelect) {
				prefixSelect.addEventListener('change', function () {
					var nextPrefix = prefixSelect.value;
					if (typeof context.onPrefixRescale === 'function') {
						context.onPrefixRescale(
							prevPrefix,
							nextPrefix,
							livePrefixOpts
						);
					}
					prevPrefix = nextPrefix;
					var selOpt = prefixSelect.options[prefixSelect.selectedIndex];
					prefixSelect.title =
						(selOpt && (selOpt.title || selOpt.text)) || noneTitle;
					emitUnit();
				});
			}
			if (unitSelect) {
				unitSelect.addEventListener('change', function () {
					if (prefixSelect && multiUnitChoice) {
						livePrefixOpts = prefixOptsFromUnitPick(unitSelect.value);
						fillPrefixSelect(
							prefixSelect,
							livePrefixOpts,
							'',
							prefixAllowEmpty
						);
						prefixSelect.style.display = livePrefixOpts.length
							? ''
							: 'none';
						prevPrefix = '';
					}
					emitUnit();
				});
			}
		} else if (context && typeof context.onInput === 'function') {
			var emitSolo = function () {
				context.onInput(
					serializeQuantityStore(
						'',
						prefixSelect &&
							prefixSelect.style.display !== 'none' &&
							prefixSelect.value
							? String(prefixSelect.value)
							: '',
						unitSelect && unitSelect.value
							? String(unitSelect.value)
							: ''
					)
				);
			};
			if (prefixSelect) {
				prefixSelect.addEventListener('change', emitSolo);
			}
			if (unitSelect) {
				unitSelect.addEventListener('change', function () {
					if (prefixSelect && multiUnitChoice) {
						livePrefixOpts = prefixOptsFromUnitPick(unitSelect.value);
						fillPrefixSelect(
							prefixSelect,
							livePrefixOpts,
							'',
							prefixAllowEmpty
						);
						prefixSelect.style.display = livePrefixOpts.length
							? ''
							: 'none';
					}
					emitSolo();
				});
			}
		}
		return group;
	}

	/**
	 * Quantity = Value renderer chrome + Unit renderer chrome in one row (OQ-R8).
	 * Does not re-implement Typ/Praefix/Kuerzel internals.
	 */
	function renderQuantityControl(node, context) {
		var members = quantitySchemaMembers(node);
		var compact = true;
		var parsed = parseQuantityStore(readValue(context, ''));
		var mag = parsed.mag;
		if (!mag && node && node.sample != null && String(node.sample) !== '') {
			mag = parseQuantityStore(node.sample).mag || String(node.sample);
		}
		if (!mag && !(context && context.noSampleFill)) {
			mag = sampleForTypeKey('quantity', '10.5', node) || '10.5';
		}

		if (!members.length) {
			/* Bare catalog quantity — magnitude only (no unit leaf). */
			if (!isEdit(context)) {
				return createEl('span', {
					className:
						'wtt-node-render__display wtt-node-render--quantity' +
						(compact ? ' is-compact' : ''),
					text: mag || '—',
				});
			}
			var bare = createEl('input', {
				type: 'text',
				inputmode: 'decimal',
				autocomplete: 'off',
				className:
					'wtt-preview-input wtt-node-render--quantity wtt-preview-input--num' +
					(compact ? ' is-compact' : ''),
				value: mag,
			});
			if (context && typeof context.onInput === 'function') {
				var emitBare = function () {
					context.onInput(bare.value);
				};
				bare.addEventListener('input', emitBare);
				bare.addEventListener('change', emitBare);
			}
			if (context && context.valueKey) {
				bare.setAttribute('data-wtt-pv', String(context.valueKey));
			}
			return bare;
		}

		var prefixOpts = qtyPrefixOptions(findQtyMember(members, 'praefix'));
		var kuerzelMem = findQtyMember(members, 'kuerzel');
		var seedUnit =
			(parsed.unit != null && String(parsed.unit)) ||
			(kuerzelMem && kuerzelMem.sample != null
				? String(kuerzelMem.sample)
				: '') ||
			'';
		var unitProbe = {
			name: (node && (node.name || node.displayName)) || 'Unit',
			typeKey: 'unit',
			quantitySchema: node.quantitySchema,
			sample: serializeQuantityStore(
				'',
				parsed.prefix,
				seedUnit
			),
		};

		if (!isEdit(context)) {
			var unitDisp = renderUnitControl(unitProbe, contentContext(context, true));
			var wrap = createEl('span', {
				className:
					'wtt-preview-display-value wtt-preview-quantity wtt-node-render--quantity is-compact',
			});
			wrap.appendChild(
				createEl('span', {
					className: 'wtt-node-render--quantity__mag',
					text: String(mag || ''),
				})
			);
			if (unitDisp) {
				wrap.appendChild(unitDisp);
			}
			return wrap;
		}

		var group = createEl('div', {
			className: 'wtt-preview-quantity wtt-node-render--quantity is-compact',
		});
		var num = createEl('input', {
			type: 'text',
			inputmode: 'decimal',
			autocomplete: 'off',
			className: 'wtt-preview-input wtt-preview-input--num',
			value: mag,
		});
		if (context && context.valueKey) {
			num.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		group.appendChild(num);

		var unitPart = { prefix: parsed.prefix || '', unit: parsed.unit || '' };
		var unitCtx = Object.assign({}, context, {
			value: serializeQuantityStore('', unitPart.prefix, unitPart.unit),
			onInput: null,
			onUnitPartChange: function (part) {
				unitPart.prefix = part.prefix || '';
				unitPart.unit = part.unit || '';
				emitAll();
			},
			onPrefixRescale: function (oldKey, newKey, opts) {
				var qty = quantityApi();
				if (qty && typeof qty.rescaleOnPrefixChange === 'function') {
					var rescaled = qty.rescaleOnPrefixChange(
						num.value,
						oldKey,
						newKey,
						opts || prefixOpts,
						qtyPrefixRootToSi(node)
					);
					if (rescaled != null) {
						num.value = rescaled;
					}
				}
			},
		});
		var unitEl = renderUnitControl(unitProbe, unitCtx);
		if (unitEl) {
			group.appendChild(unitEl);
		}

		function emitAll() {
			if (context && typeof context.onInput === 'function') {
				context.onInput(
					serializeQuantityStore(num.value, unitPart.prefix, unitPart.unit)
				);
			}
		}
		num.addEventListener('input', emitAll);
		num.addEventListener('change', emitAll);
		return group;
	}

	function isUnitTypeKey(key) {
		key = String(key || '')
			.trim()
			.toLowerCase();
		return (
			key === 'unit' ||
			key === 'basiseinheit' ||
			key === 'meter' ||
			key === 'ohm' ||
			key === 'farad' ||
			key === 'henry'
		);
	}

	var UnitRenderer = {
		id: 'unit',
		label: 'Unit',
		canRender: function (node) {
			if (!node) {
				return false;
			}
			/*
			 * CatalogChoice / ChildList (Base unit → Meter/Ohm) is not UnitRenderer.
			 * Prefix?+Symbol chrome is for concrete unit leaves / quantity Unit slots.
			 */
			var pref = normalizePreferredPaintId(
				node.preferredRender || node.typePreferredRender || ''
			);
			if (pref === 'childlist' || pref === 'child_list') {
				return false;
			}
			if (String(node.fixedMode || '') === 'catalog') {
				return false;
			}
			if (node.isBasiseinheitUnit) {
				return true;
			}
			if (quantitySchemaMembers(node).length) {
				/* Unit leaf / field typed as unit — not bare quantity catalog. */
				if (isQuantityTypeKey(resolveTypeKey(node))) {
					return false;
				}
				return true;
			}
			if (
				normalizePreferredPaintId(
					node.preferredRender || node.typePreferredRender
				) === 'unit'
			) {
				return true;
			}
			if (
				Array.isArray(node.fixedOptions) &&
				node.fixedOptions.length &&
				node.fixedOptions.some(function (o) {
					return (
						o &&
						Array.isArray(o.allowedPrefixes) &&
						o.allowedPrefixes.length
					);
				})
			) {
				return true;
			}
			return isUnitTypeKey(resolveTypeKey(node));
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			/* Catalog unit choices → schema from selected option + allowlist. */
			if (
				(!quantitySchemaMembers(node).length ||
					normalizePreferredPaintId(
						node.preferredRender || node.typePreferredRender
					) === 'unit') &&
				Array.isArray(node.fixedOptions) &&
				node.fixedOptions.length
			) {
				var composed = quantityNodeFromHostAttrs(
					{
						name: node.name || 'Unit',
						typeKey: 'unit',
						preferredRender: 'unit',
						attributes: [
							{ name: 'Value', typeKey: 'double' },
							{
								name: 'Unit',
								fixedOptions: node.fixedOptions,
								fixedValues: node.fixedValues,
								quantitySchema: node.quantitySchema,
							},
						],
					},
					ctx
				);
				if (composed && composed.quantitySchema) {
					return renderUnitControl(
						Object.assign({}, node, {
							typeKey: 'unit',
							quantitySchema: composed.quantitySchema,
						}),
						ctx
					);
				}
			}
			return renderUnitControl(node, ctx);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
		getExampleNode: function (probe) {
			var schema =
				probe && probe.quantitySchema
					? probe.quantitySchema
					: null;
			return {
				name: 'Unit_name',
				displayName: 'Unit_name',
				typeKey: 'unit',
				type: { name: 'unit' },
				isExample: true,
				isBasiseinheitUnit: true,
				quantitySchema: schema,
				sample: '',
			};
		},
	};

	var QuantityRenderer = {
		id: 'quantity',
		label: 'Quantity',
		canRender: function (node) {
			if (!node) {
				return false;
			}
			if (quantitySchemaMembers(node).length) {
				return true;
			}
			var hostAttrs = quantityHostAttrList(node);
			if (
				hostAttrs.length >= 2 &&
				quantityHostLooksLikeValueUnit(hostAttrs)
			) {
				return true;
			}
			if (normalizePreferredPaintId(node.preferredRender || node.typePreferredRender) === 'quantity') {
				return true;
			}
			return isQuantityTypeKey(resolveTypeKey(node));
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var hostAttrs = quantityHostAttrList(node);
			/* Host with Value + Unit attrs → compose via schema from selected unit. */
			if (
				(!quantitySchemaMembers(node).length ||
					isQuantityTypeKey(resolveTypeKey(node)) ||
					normalizePreferredPaintId(node.preferredRender || node.typePreferredRender) ===
						'quantity') &&
				hostAttrs.length >= 2 &&
				quantityHostLooksLikeValueUnit(hostAttrs)
			) {
				var composed = quantityNodeFromHostAttrs(
					Object.assign({}, node, { attributes: hostAttrs }),
					ctx
				);
				if (composed) {
					return renderQuantityControl(composed, ctx);
				}
			}
			return renderQuantityControl(node, ctx);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
		getExampleNode: function (probe) {
			var sample = sampleForTypeKey('quantity', '10.5', probe);
			var schema =
				probe && probe.quantitySchema
					? probe.quantitySchema
					: null;
			return {
				name: 'Quantity_name',
				displayName: 'Quantity_name',
				typeKey: 'quantity',
				type: { name: 'quantity' },
				sample: sample || '10.5',
				isExample: true,
				quantitySchema: schema,
			};
		},
	};

	function quantityHostAttrList(node) {
		if (!node) {
			return [];
		}
		if (Array.isArray(node.attributes) && node.attributes.length) {
			return node.attributes;
		}
		if (Array.isArray(node.typeProperties) && node.typeProperties.length) {
			return node.typeProperties;
		}
		return [];
	}

	function quantityHostLooksLikeValueUnit(attrs) {
		var hasVal = false;
		var hasUnit = false;
		(attrs || []).forEach(function (a) {
			if (!a) {
				return;
			}
			var n = String(a.name || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue');
			var key = String(a.typeKey || a.typeName || '')
				.trim()
				.toLowerCase();
			if (
				n === 'wert' ||
				n === 'value' ||
				n === 'betrag' ||
				n === 'typ' ||
				key === 'double' ||
				key === 'int'
			) {
				hasVal = true;
			}
			if (
				n === 'einheit' ||
				n === 'unit' ||
				n === 'kuerzel' ||
				n === 'waehrung' ||
				key.indexOf('prefix') !== -1 ||
				key === 'with prefix' ||
				key === 'mit praefix' ||
				key === 'mit präfix' ||
				unitAttrHasChrome(a)
			) {
				hasUnit = true;
			}
		});
		return hasVal && hasUnit;
	}

	/**
	 * When Unit/Währung has multi CatalogChoice fixedOptions, put them on the
	 * Kuerzel member so Quantity/Unit paint shows a ListChooser — not a static
	 * symbol from Festwert / first option (Preis "choices but no choice").
	 *
	 * @param {object|null} schema
	 * @param {object} unitAttr
	 * @param {object|null} context
	 * @return {object|null}
	 */
	function enrichQuantitySchemaUnitChoices(schema, unitAttr, context) {
		if (
			!schema ||
			!Array.isArray(schema.members) ||
			!unitAttr ||
			!Array.isArray(unitAttr.fixedOptions) ||
			unitAttr.fixedOptions.length < 1
		) {
			return schema;
		}
		var members = schema.members.slice();
		var kuerzel = findQtyMember(members, 'kuerzel');
		if (!kuerzel) {
			return schema;
		}
		var multi = unitAttr.fixedOptions.length > 1;
		var picked =
			(context && context.unitPick) ||
			(unitAttr.fixedValues && unitAttr.fixedValues[0]) ||
			(kuerzel.sample != null && String(kuerzel.sample)) ||
			unitAttr.fixedOptions[0].name ||
			unitAttr.fixedOptions[0].id;
		var opt = null;
		unitAttr.fixedOptions.forEach(function (o) {
			if (
				!opt &&
				o &&
				(o.name === picked || String(o.id) === String(picked))
			) {
				opt = o;
			}
		});
		if (!opt) {
			opt = unitAttr.fixedOptions[0];
		}
		/* Drop stale pick outside filtered Choices (e.g. Ampere after Ohm-only). */
		if (
			opt &&
			picked &&
			opt.name !== picked &&
			String(opt.id) !== String(picked)
		) {
			picked = opt.name || String(opt.id);
		}
		var idx = members.indexOf(kuerzel);
		var nextK = Object.assign({}, kuerzel, {
			fixedOptions: unitAttr.fixedOptions.slice(),
			typeBranch: { children: unitAttr.fixedOptions.slice() },
			sample: (opt && (opt.name || String(opt.id))) || '',
			shortDescription: (opt && opt.shortDescription) || '',
			/* Always ListChooser so Q116 can gray a sole required option. */
			fixedEnabled: false,
			fixedLiteral: '',
			fixed: null,
			soleOptionLocked: !multi,
		});
		if (idx >= 0) {
			members[idx] = nextK;
		}
		var prefixes = Array.isArray(opt.allowedPrefixes)
			? opt.allowedPrefixes.filter(function (p) {
					return p && p.enabled !== false;
			  })
			: [];
		var praefix = findQtyMember(members, 'praefix');
		if (praefix) {
			var pIdx = members.indexOf(praefix);
			members[pIdx] = Object.assign({}, praefix, {
				fixedOptions: prefixes,
				typeBranch: { children: prefixes },
			});
		} else if (prefixes.length) {
			members.unshift({
				name: 'Praefix',
				fixedOptions: prefixes,
				typeBranch: { children: prefixes },
			});
		}
		return Object.assign({}, schema, {
			unitId: (opt && opt.id) || schema.unitId || 0,
			unitName: (opt && opt.name) || schema.unitName || '',
			members: members,
		});
	}

	/**
	 * Build quantitySchema from Unit attr: catalog fixedOptions (preferred) or
	 * With-prefix typeProperties (Praefix + Kuerzel) as fallback.
	 */
	function quantitySchemaFromUnitAttr(unitAttr, context) {
		if (!unitAttr) {
			return null;
		}
		if (
			unitAttr.quantitySchema &&
			Array.isArray(unitAttr.quantitySchema.members) &&
			unitAttr.quantitySchema.members.length
		) {
			return enrichQuantitySchemaUnitChoices(
				unitAttr.quantitySchema,
				unitAttr,
				context
			);
		}
		if (Array.isArray(unitAttr.fixedOptions) && unitAttr.fixedOptions.length) {
			var multi = unitAttr.fixedOptions.length > 1;
			var picked =
				(context && context.unitPick) ||
				(unitAttr.fixedValues && unitAttr.fixedValues[0]) ||
				unitAttr.fixedOptions[0].name ||
				unitAttr.fixedOptions[0].id;
			var opt = null;
			unitAttr.fixedOptions.forEach(function (o) {
				if (
					!opt &&
					o &&
					(o.name === picked || String(o.id) === String(picked))
				) {
					opt = o;
				}
			});
			if (!opt) {
				opt = unitAttr.fixedOptions[0];
			}
			/*
			 * Catalog unit choice: prefer attached allowedPrefixes → synthetic
			 * quantitySchema members (Praefix + Kuerzel).
			 * Always expose unit ListChooser (Q116 grays sole option).
			 */
			var prefixes = Array.isArray(opt.allowedPrefixes)
				? opt.allowedPrefixes.filter(function (p) {
						return p && p.enabled !== false;
				  })
				: [];
			var kuerzelMem = {
				name: 'Kuerzel',
				fixedOptions: unitAttr.fixedOptions.slice(),
				typeBranch: { children: unitAttr.fixedOptions.slice() },
				sample: opt.name || String(opt.id || ''),
				shortDescription: opt.shortDescription || '',
				fixedEnabled: false,
				fixedLiteral: '',
				soleOptionLocked: !multi,
			};
			return {
				unitId: opt.id || 0,
				unitName: opt.name || '',
				members: [
					{
						name: 'Praefix',
						fixedOptions: prefixes,
						typeBranch: { children: prefixes },
					},
					kuerzelMem,
				],
			};
		}
		/* OQ-W11: Unit typed as With prefix → compose from nested Praefix/Kuerzel. */
		var props = Array.isArray(unitAttr.typeProperties)
			? unitAttr.typeProperties
			: [];
		if (!props.length) {
			return null;
		}
		var praefixProp = null;
		var kuerzelProp = null;
		props.forEach(function (p) {
			if (!p) {
				return;
			}
			var n = String(p.name || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue')
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe');
			if (!praefixProp && (n === 'praefix' || n === 'prefix')) {
				praefixProp = p;
			}
			if (
				!kuerzelProp &&
				(n === 'kuerzel' ||
					n === 'symbol' ||
					n === 'einheit' ||
					n === 'unit' ||
					n === 'waehrung' ||
					n === 'currency')
			) {
				kuerzelProp = p;
			}
		});
		if (!praefixProp && !kuerzelProp) {
			return null;
		}
		var members = [];
		if (praefixProp) {
			var prefixOpts = Array.isArray(praefixProp.fixedOptions)
				? praefixProp.fixedOptions
				: [];
			members.push({
				name: 'Praefix',
				fixedOptions: prefixOpts,
				typeBranch:
					praefixProp.typeBranch ||
					(prefixOpts.length ? { children: prefixOpts } : null),
			});
		}
		if (kuerzelProp) {
			var kOpts = Array.isArray(kuerzelProp.fixedOptions)
				? kuerzelProp.fixedOptions
				: [];
			var kMulti = kOpts.length > 1;
			members.push({
				name: 'Kuerzel',
				fixedEnabled: kMulti ? false : !!kuerzelProp.fixedEnabled,
				fixedLiteral: kMulti
					? ''
					: kuerzelProp.fixedLiteral ||
					  (kuerzelProp.fixedValues && kuerzelProp.fixedValues[0]) ||
					  '',
				fixedOptions: kOpts,
				typeBranch: kMulti ? { children: kOpts } : kuerzelProp.typeBranch,
				sample: kMulti
					? (kuerzelProp.fixedValues && kuerzelProp.fixedValues[0]) ||
					  (kOpts[0] && (kOpts[0].name || String(kOpts[0].id))) ||
					  ''
					: '',
				shortDescription: kuerzelProp.shortDescription || '',
			});
		}
		return enrichQuantitySchemaUnitChoices(
			{
				unitId: unitAttr.typeId || 0,
				unitName: unitAttr.typeName || unitAttr.name || '',
				members: members,
			},
			unitAttr,
			context
		);
	}

	function quantityNodeFromHostAttrs(node, context) {
		var attrs = Array.isArray(node.attributes) ? node.attributes : [];
		var valueAttr = null;
		var unitAttr = null;
		attrs.forEach(function (a) {
			if (!a) {
				return;
			}
			var n = String(a.name || '')
				.toLowerCase()
				.replace(/\u00fc/g, 'ue');
			if (!valueAttr && (n === 'wert' || n === 'value' || n === 'betrag' || n === 'typ')) {
				valueAttr = a;
			}
			if (!unitAttr && (n === 'einheit' || n === 'unit' || n === 'kuerzel' || n === 'waehrung')) {
				unitAttr = a;
			}
		});
		if (!valueAttr) {
			attrs.forEach(function (a) {
				var key = String((a && (a.typeKey || a.typeName)) || '')
					.trim()
					.toLowerCase();
				if (!valueAttr && (key === 'double' || key === 'int')) {
					valueAttr = a;
				}
			});
		}
		if (!unitAttr) {
			attrs.forEach(function (a) {
				if (!unitAttr && unitAttrHasChrome(a)) {
					unitAttr = a;
				}
			});
		}
		if (!valueAttr || !unitAttr) {
			return null;
		}

		var schema = quantitySchemaFromUnitAttr(unitAttr, context);

		return {
			name: node.name || 'Quantity',
			typeKey: 'quantity',
			preferredRender: 'quantity',
			quantitySchema: schema,
			sample: '10.5',
			attributes: attrs,
		};
	}

	function contextName(context) {
		return context && context.name ? String(context.name) : 'form';
	}

	function isEdit(context) {
		if (!context) {
			return true;
		}
		if (context.readonly === true) {
			return false;
		}
		return context.mode !== 'display';
	}

	/**
	 * Context for content rendering; `readonly` forces display output.
	 */
	function contentContext(context, readonly) {
		context = context || { name: 'form', mode: 'edit' };
		if (!readonly && context.readonly !== true && context.mode !== 'display') {
			return context;
		}
		return Object.assign({}, context, {
			readonly: true,
			mode: 'display',
			onInput: null,
		});
	}

	function readValue(context, fallback) {
		if (context && context.value != null) {
			return String(context.value);
		}
		if (context && typeof context.getValue === 'function') {
			return String(context.getValue(fallback != null ? fallback : ''));
		}
		return fallback != null ? String(fallback) : '';
	}

	function clampToMaxLength(value, maxLength) {
		var maxLen = parseInt(maxLength, 10);
		if (!(maxLen > 0)) {
			return value;
		}
		var str = value == null ? '' : String(value);
		if (maxLen === 1) {
			return str ? str.charAt(0) : '';
		}
		return str.length > maxLen ? str.slice(0, maxLen) : str;
	}

	function bindValue(control, context) {
		if (!isEdit(context) || !context || typeof context.onInput !== 'function') {
			return control;
		}
		control.addEventListener('input', function () {
			var next = clampToMaxLength(control.value, context.maxLength);
			if (control.value !== next) {
				control.value = next;
			}
			context.onInput(next);
		});
		if (context.valueKey) {
			control.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		return control;
	}

	function fieldCaption(node) {
		if (!node) {
			return '';
		}
		return String(node.displayName || node.name || node.typeKey || '').trim();
	}

	/**
	 * Example field name for a type key: int → Int_name.
	 */
	function exampleFieldName(typeKey) {
		var key = String(typeKey || '')
			.trim()
			.toLowerCase();
		if (!key) {
			return 'Field_name';
		}
		return key.charAt(0).toUpperCase() + key.slice(1) + '_name';
	}

	function isNumericTypeKey(typeKey) {
		var key = String(typeKey || '')
			.trim()
			.toLowerCase();
		return key === 'int' || key === 'double' || key === 'integer';
	}

	/**
	 * Plain example node DTO (not a live WP term) for preview rendering.
	 */
	function makeExampleScalarNode(typeKey, sample) {
		var key = String(typeKey || '')
			.trim()
			.toLowerCase();
		var name = exampleFieldName(key);
		return {
			name: name,
			displayName: name,
			typeKey: key,
			type: { name: key },
			sample: sample != null ? String(sample) : '',
			isExample: true,
		};
	}

	function isTruthyBool(value) {
		var s = String(value == null ? '' : value)
			.trim()
			.toLowerCase();
		var trueLabel = String(i18nLabels.boolTrue || 'true').toLowerCase();
		return s === '1' || s === 'true' || s === 'yes' || s === trueLabel;
	}

	function renderBoolSwitchControl(opts, context, value, compact) {
		var trueLabel = i18nLabels.boolTrue || 'true';
		var falseLabel = i18nLabels.boolFalse || 'false';
		var on = isTruthyBool(value == null ? '' : String(value));
		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value' +
					(compact ? ' wtt-preview-display-value--compact' : ''),
				text: on ? trueLabel : falseLabel,
			});
		}
		var wrap = createEl('label', {
			className:
				'wtt-switch wtt-preview-bool-switch' +
				(compact ? ' wtt-preview-bool-switch--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
		});
		var input = createEl('input', {
			type: 'checkbox',
			className: 'wtt-switch__input',
		});
		input.checked = on;
		if (context && typeof context.onInput === 'function') {
			input.addEventListener('change', function () {
				context.onInput(input.checked ? 'true' : 'false');
			});
		}
		if (context.valueKey) {
			input.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		wrap.appendChild(input);
		var track = createEl('span', { className: 'wtt-switch__track' });
		track.appendChild(createEl('span', { className: 'wtt-switch__thumb' }));
		wrap.appendChild(track);
		wrap.appendChild(
			createEl('span', {
				className: 'wtt-preview-bool__label',
				text: on ? trueLabel : falseLabel,
			})
		);
		input.addEventListener('change', function () {
			var label = wrap.querySelector('.wtt-preview-bool__label');
			if (label) {
				label.textContent = input.checked ? trueLabel : falseLabel;
			}
		});
		return wrap;
	}

	function renderBoolRadioControl(opts, context, value, compact) {
		var trueLabel = i18nLabels.boolTrue || 'true';
		var falseLabel = i18nLabels.boolFalse || 'false';
		var on = isTruthyBool(value == null ? '' : String(value));
		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value' +
					(compact ? ' wtt-preview-display-value--compact' : ''),
				text: on ? trueLabel : falseLabel,
			});
		}
		var name =
			'wtt-bool-radio-' +
			String((context && context.valueKey) || Math.random()).replace(
				/\W+/g,
				'_'
			);
		var wrap = createEl('div', {
			className:
				'wtt-preview-bool-radio' +
				(compact ? ' wtt-preview-bool-radio--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
		});
		function addOpt(isTrue) {
			var lab = createEl('label', {
				className: 'wtt-preview-bool-radio__opt',
			});
			var input = createEl('input', {
				type: 'radio',
				name: name,
				value: isTrue ? 'true' : 'false',
			});
			input.checked = isTrue ? on : !on;
			if (context && typeof context.onInput === 'function') {
				input.addEventListener('change', function () {
					if (input.checked) {
						context.onInput(isTrue ? 'true' : 'false');
					}
				});
			}
			lab.appendChild(input);
			lab.appendChild(
				createEl('span', { text: isTrue ? trueLabel : falseLabel })
			);
			wrap.appendChild(lab);
		}
		addOpt(true);
		addOpt(false);
		return wrap;
	}

	function resolveValidatorsList(node) {
		if (!node || typeof node !== 'object') {
			return [];
		}
		if (Array.isArray(node.validators) && node.validators.length) {
			return node.validators;
		}
		if (Array.isArray(node.typeValidators) && node.typeValidators.length) {
			return node.typeValidators;
		}
		if (
			node.typeExtras &&
			Array.isArray(node.typeExtras.validators) &&
			node.typeExtras.validators.length
		) {
			return node.typeExtras.validators;
		}
		if (
			node.settingsResolved &&
			node.settingsResolved.data &&
			Array.isArray(node.settingsResolved.data.validators)
		) {
			return node.settingsResolved.data.validators;
		}
		return [];
	}

	/**
	 * Numeric min/max from int_min|int_max|double_min|double_max (params.value).
	 * hasMin/hasMax = explicit validator present (range always needs a window;
	 * spinner/field only set HTML min/max when has*).
	 */
	function numericBoundsFromNode(opts) {
		opts = opts || {};
		var min = opts.min != null ? Number(opts.min) : NaN;
		var max = opts.max != null ? Number(opts.max) : NaN;
		var hasMin = isFinite(min);
		var hasMax = isFinite(max);
		var node = opts.context && opts.context.node;
		var vals = opts.validators || resolveValidatorsList(node);
		if (Array.isArray(vals)) {
			vals.forEach(function (v) {
				if (!v || typeof v !== 'object') {
					return;
				}
				var id = String(v.id || '')
					.trim()
					.toLowerCase();
				var params =
					v.params && typeof v.params === 'object' ? v.params : {};
				var bound =
					params.value != null
						? Number(params.value)
						: v.value != null
							? Number(v.value)
							: NaN;
				if (
					(id === 'int_min' || id === 'double_min') &&
					isFinite(bound)
				) {
					min = bound;
					hasMin = true;
				}
				if (
					(id === 'int_max' || id === 'double_max') &&
					isFinite(bound)
				) {
					max = bound;
					hasMax = true;
				}
				if (v.min != null && !hasMin) {
					min = Number(v.min);
					if (isFinite(min)) {
						hasMin = true;
					}
				}
				if (v.max != null && !hasMax) {
					max = Number(v.max);
					if (isFinite(max)) {
						hasMax = true;
					}
				}
				if (params.min != null && !hasMin) {
					min = Number(params.min);
					if (isFinite(min)) {
						hasMin = true;
					}
				}
				if (params.max != null && !hasMax) {
					max = Number(params.max);
					if (isFinite(max)) {
						hasMax = true;
					}
				}
			});
		}
		if (!isFinite(min)) {
			min = 0;
		}
		if (!isFinite(max) || max < min) {
			max = min + 100;
		}
		return {
			min: min,
			max: max,
			hasMin: hasMin,
			hasMax: hasMax,
			hasAny: hasMin || hasMax,
		};
	}

	/**
	 * Text length from text_min_length / text_max_length.
	 */
	function textLengthBoundsFromNode(node) {
		var min = NaN;
		var max = NaN;
		var hasMin = false;
		var hasMax = false;
		var vals = resolveValidatorsList(node);
		vals.forEach(function (v) {
			if (!v || typeof v !== 'object') {
				return;
			}
			var id = String(v.id || '')
				.trim()
				.toLowerCase();
			var params =
				v.params && typeof v.params === 'object' ? v.params : {};
			var bound =
				params.value != null
					? Number(params.value)
					: v.value != null
						? Number(v.value)
						: NaN;
			if (id === 'text_min_length' && isFinite(bound)) {
				min = Math.trunc(bound);
				hasMin = true;
			}
			if (id === 'text_max_length' && isFinite(bound)) {
				max = Math.trunc(bound);
				hasMax = true;
			}
		});
		return {
			min: hasMin ? min : 0,
			max: hasMax ? max : NaN,
			hasMin: hasMin,
			hasMax: hasMax,
		};
	}

	function renderRangeControl(opts, context, value, compact) {
		var bounds = numericBoundsFromNode(opts);
		var num = Number(value);
		if (!isFinite(num)) {
			num = bounds.min;
		}
		if (num < bounds.min) {
			num = bounds.min;
		}
		if (num > bounds.max) {
			num = bounds.max;
		}
		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value' +
					(compact ? ' wtt-preview-display-value--compact' : ''),
				text: String(num),
			});
		}
		var wrap = createEl('div', {
			className:
				'wtt-preview-range' +
				(compact ? ' wtt-preview-range--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
		});
		var input = createEl('input', {
			type: 'range',
			className: 'wtt-preview-range__input',
			min: String(bounds.min),
			max: String(bounds.max),
			step: opts.step != null ? String(opts.step) : '1',
			value: String(num),
		});
		var readout = createEl('span', {
			className: 'wtt-preview-range__value',
			text: String(num),
		});
		if (context && typeof context.onInput === 'function') {
			input.addEventListener('input', function () {
				readout.textContent = input.value;
				context.onInput(input.value);
			});
		}
		if (context.valueKey) {
			input.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		wrap.appendChild(input);
		wrap.appendChild(readout);
		return wrap;
	}

	function renderBoolControl(opts, context, value, compact) {
		var trueLabel = i18nLabels.boolTrue || 'true';
		var falseLabel = i18nLabels.boolFalse || 'false';
		/* Non-bool leftovers (e.g. wrong sample) → treat as sample default. */
		var raw = value == null ? '' : String(value);
		if (
			raw !== '' &&
			!isTruthyBool(raw) &&
			raw.toLowerCase() !== '0' &&
			raw.toLowerCase() !== 'false' &&
			raw.toLowerCase() !== String(falseLabel).toLowerCase() &&
			raw.toLowerCase() !== 'no' &&
			raw.toLowerCase() !== 'off'
		) {
			raw = opts.sample != null ? String(opts.sample) : 'true';
		}
		if (raw === '' && opts.sample != null) {
			raw = String(opts.sample);
		}
		var on = isTruthyBool(raw);
		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value' +
					(compact ? ' wtt-preview-display-value--compact' : '') +
					(opts.inputClass ? ' ' + opts.inputClass : ''),
				text: on ? trueLabel : falseLabel,
			});
		}
		var wrap = createEl('label', {
			className:
				'wtt-preview-bool' +
				(compact ? ' wtt-preview-bool--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
		});
		var input = createEl('input', {
			type: 'checkbox',
			className: 'wtt-preview-check',
		});
		input.checked = on;
		if (context && typeof context.onInput === 'function') {
			input.addEventListener('change', function () {
				context.onInput(input.checked ? 'true' : 'false');
			});
		}
		if (context.valueKey) {
			input.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		wrap.appendChild(input);
		wrap.appendChild(
			createEl('span', {
				className: 'wtt-preview-bool__label',
				text: on ? trueLabel : falseLabel,
			})
		);
		input.addEventListener('change', function () {
			var label = wrap.querySelector('.wtt-preview-bool__label');
			if (label) {
				label.textContent = input.checked ? trueLabel : falseLabel;
			}
		});
		return wrap;
	}

	function renderTextareaControl(opts, context, value, compact) {
		if (!isEdit(context)) {
			return createEl('span', {
				className:
					'wtt-preview-display-value wtt-preview-display-value--textarea' +
					(compact ? ' wtt-preview-display-value--compact' : '') +
					(opts.inputClass ? ' ' + opts.inputClass : ''),
				text: value === '' ? '—' : value,
			});
		}
		var layout = resolveTextareaLayout(
			(context && context.node) || null,
			opts,
			compact
		);
		var areaAttrs = {
			className:
				'wtt-preview-textarea' +
				(compact ? ' wtt-preview-textarea--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
			rows: String(layout.rows),
			cols: String(layout.cols),
		};
		var lenBounds = textLengthBoundsFromNode(
			(context && context.node) || null
		);
		if (lenBounds.hasMin && lenBounds.min > 0) {
			areaAttrs.minlength = String(lenBounds.min);
		}
		if (lenBounds.hasMax && isFinite(lenBounds.max)) {
			areaAttrs.maxlength = String(lenBounds.max);
		}
		var area = createEl('textarea', areaAttrs);
		area.value =
			lenBounds.hasMax && isFinite(lenBounds.max)
				? clampToMaxLength(value, lenBounds.max)
				: value;
		return bindValue(
			area,
			Object.assign({}, context, {
				maxLength:
					lenBounds.hasMax && isFinite(lenBounds.max)
						? lenBounds.max
						: undefined,
			})
		);
	}

	function resolveTextareaLayout(node, opts, compact) {
		opts = opts || {};
		var cfg =
			(node && node.textareaConfig && typeof node.textareaConfig === 'object'
				? node.textareaConfig
				: null) ||
			{};
		var extras =
			(node && node.typeExtras && typeof node.typeExtras === 'object'
				? node.typeExtras
				: null) || {};
		function num(raw, fallback) {
			var n = parseInt(raw, 10);
			return isFinite(n) && n > 0 ? n : fallback;
		}
		var cols = num(
			cfg.cols != null
				? cfg.cols
				: extras.textareaCols != null
					? extras.textareaCols
					: opts.cols,
			40
		);
		var rows = num(
			cfg.rows != null
				? cfg.rows
				: extras.textareaRows != null
					? extras.textareaRows
					: opts.rows,
			4
		);
		if (compact) {
			rows = Math.min(rows, 2);
		}
		if (cols < 1) {
			cols = 1;
		}
		if (cols > 200) {
			cols = 200;
		}
		if (rows < 1) {
			rows = 1;
		}
		if (rows > 100) {
			rows = 100;
		}
		return { cols: cols, rows: rows };
	}

	function isValidEmail(value) {
		var s = String(value == null ? '' : value).trim();
		if (s === '') {
			return true;
		}
		if (s.length > 254 || s.indexOf('..') !== -1) {
			return false;
		}
		return /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/.test(
			s
		);
	}

	function syncEmailValidity(input, hint) {
		if (!input) {
			return;
		}
		var ok = isValidEmail(input.value);
		input.classList.toggle('is-invalid', !ok);
		input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		if (typeof input.setCustomValidity === 'function') {
			input.setCustomValidity(
				ok ? '' : i18nLabels.emailInvalid || 'Enter a valid email address.'
			);
		}
		if (hint) {
			hint.hidden = ok;
			hint.textContent = ok
				? ''
				: i18nLabels.emailInvalid || 'Enter a valid email address.';
		}
	}

	function wrapValidatedEmailControl(input) {
		var wrap = createEl('div', {
			className: 'wtt-node-render__email-wrap',
		});
		var hint = createEl('span', {
			className: 'wtt-node-render__email-hint',
		});
		hint.hidden = true;
		wrap.appendChild(input);
		wrap.appendChild(hint);
		input.addEventListener('input', function () {
			syncEmailValidity(input, hint);
		});
		input.addEventListener('blur', function () {
			syncEmailValidity(input, hint);
		});
		syncEmailValidity(input, hint);
		return wrap;
	}

	function renderScalarControl(opts) {
		opts = opts || {};
		var context = opts.context || {};
		var compact =
			!!opts.compact ||
			contextName(context) === 'table' ||
			contextName(context) === 'tree';
		var value = readValue(context, opts.sample || '');
		var className =
			'wtt-preview-input' +
			(compact ? ' wtt-preview-input--compact' : '') +
			(opts.inputClass ? ' ' + opts.inputClass : '');

		if (opts.control === 'switch') {
			return renderBoolSwitchControl(opts, context, value, compact);
		}
		if (opts.control === 'radio') {
			return renderBoolRadioControl(opts, context, value, compact);
		}
		if (opts.control === 'range') {
			return renderRangeControl(opts, context, value, compact);
		}
		if (opts.control === 'checkbox' || opts.inputType === 'checkbox') {
			return renderBoolControl(opts, context, value, compact);
		}
		if (opts.control === 'textarea') {
			return renderTextareaControl(opts, context, value, compact);
		}

		if (!isEdit(context)) {
			var displayVal = value === '' ? '—' : value;
			if (opts.maxLength === 1 || opts.maxLength === '1') {
				displayVal = displayVal === '—' ? '—' : String(displayVal).charAt(0);
			}
			if (
				opts.validate === 'email' &&
				displayVal !== '—' &&
				!isValidEmail(displayVal)
			) {
				return createEl('span', {
					className:
						'wtt-preview-display-value is-invalid' +
						(compact ? ' wtt-preview-display-value--compact' : '') +
						(opts.inputClass ? ' ' + opts.inputClass : ''),
					text: displayVal,
					title: i18nLabels.emailInvalid || 'Enter a valid email address.',
				});
			}
			return createEl('span', {
				className:
					'wtt-preview-display-value' +
					(compact ? ' wtt-preview-display-value--compact' : '') +
					(opts.inputClass ? ' ' + opts.inputClass : ''),
				text: displayVal,
			});
		}

		var attrs = {
			type: opts.inputType || 'text',
			className: className,
			value: value,
		};
		if (opts.maxLength) {
			attrs.maxlength = String(opts.maxLength);
		}
		if (opts.step != null) {
			attrs.step = String(opts.step);
		}
		if (opts.inputMode) {
			attrs.inputmode = opts.inputMode;
		}
		if (opts.placeholder) {
			attrs.placeholder = String(opts.placeholder);
		}
		if (opts.autocomplete) {
			attrs.autocomplete = String(opts.autocomplete);
		}
		/*
		 * Spinner / native number: HTML min/max only when validators set bounds.
		 * Range uses numericBoundsFromNode separately (always needs a window).
		 */
		var inputType = String(opts.inputType || 'text').toLowerCase();
		if (inputType === 'number') {
			var numBounds = numericBoundsFromNode(opts);
			if (numBounds.hasMin) {
				attrs.min = String(numBounds.min);
			}
			if (numBounds.hasMax) {
				attrs.max = String(numBounds.max);
			}
		}
		/*
		 * text length validators → HTML minlength / maxlength
		 * (char keeps fixed maxLength: 1 from control opts).
		 */
		var lenNode = (context && context.node) || null;
		var typeKey =
			(lenNode && resolveTypeKey(lenNode)) ||
			(opts.typeKey != null ? String(opts.typeKey) : '');
		if (
			(typeKey === 'text' || typeKey === 'textarea') &&
			!opts.maxLength
		) {
			var lenBounds = textLengthBoundsFromNode(lenNode);
			if (lenBounds.hasMin && lenBounds.min > 0) {
				attrs.minlength = String(lenBounds.min);
			}
			if (lenBounds.hasMax && isFinite(lenBounds.max)) {
				attrs.maxlength = String(lenBounds.max);
				opts = Object.assign({}, opts, { maxLength: lenBounds.max });
			}
		}
		var input = createEl('input', attrs);
		input.value = clampToMaxLength(value, opts.maxLength);
		if (opts.size) {
			input.setAttribute('size', String(opts.size));
		}
		var bound = bindValue(
			input,
			Object.assign({}, context, {
				maxLength: opts.maxLength,
			})
		);
		if (opts.validate === 'email') {
			return wrapValidatedEmailControl(bound);
		}
		return bound;
	}

	/**
	 * Compose label + content for standalone render (form default).
	 * When context.bare / hideLabel: content only.
	 */
	function composeLabeledField(renderer, node, context) {
		var readonly = !isEdit(context);
		var content = renderer.renderContent(node, context, readonly);
		if (content === false || content == null) {
			return false;
		}
		var bare = !!(context && (context.bare || context.hideLabel));
		var ctx = contextName(context);
		if (bare) {
			var bareWrap = createEl('div', {
				className:
					'wtt-node-render wtt-node-render--' +
					ctx +
					' wtt-node-render--bare' +
					(readonly ? ' is-display' : ' is-edit'),
			});
			bareWrap.appendChild(content);
			return bareWrap;
		}
		var wrap = createEl('div', {
			className:
				'wtt-node-render wtt-node-render--' +
				ctx +
				(readonly ? ' is-display' : ' is-edit'),
		});
		var label = renderer.renderLabel(node, context);
		if (label && label !== false) {
			wrap.appendChild(label);
		}
		wrap.appendChild(content);
		return wrap;
	}

	/**
	 * Preferred wire ids (QuantityRenderer) → Registry short ids (quantity).
	 * Mirrors tree-admin normalizePreferredRender field map.
	 */
	function normalizePreferredPaintId(raw) {
		var key = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (!key) {
			return '';
		}
		var wireToShort = {
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
			intfieldrenderer: 'int',
			doublefieldrenderer: 'double',
			mediarenderer: 'media',
			displaynodenamerenderer: 'node_presentation',
			display_node_name: 'node_presentation',
			nodepresentationrenderer: 'node_presentation',
			node_presentation: 'node_presentation',
			quantityrenderer: 'quantity',
			unitrenderer: 'unit',
			basiseinheit: 'unit',
			noderefrenderer: 'node_ref',
			node_ref: 'node_ref',
		};
		if (wireToShort[key]) {
			return wireToShort[key];
		}
		/* Strip trailing "Renderer" if present (QuantityRenderer → quantity). */
		if (key.length > 8 && key.slice(-8) === 'renderer') {
			return key.slice(0, -8);
		}
		return key;
	}

	function findRenderer(node, context) {
		var preferred =
			node &&
			(node.preferredRender ||
				node.preferred_render ||
				(node.typePreferredRender != null ? node.typePreferredRender : ''));
		preferred = normalizePreferredPaintId(preferred);
		var i;
		if (preferred) {
			for (i = 0; i < renderers.length; i++) {
				var prefR = renderers[i];
				var prefId = String((prefR && prefR.id) || '')
					.trim()
					.toLowerCase();
				if (prefId !== preferred) {
					continue;
				}
				if (typeof prefR.canRender === 'function' && !prefR.canRender(node, context)) {
					continue;
				}
				return prefR;
			}
		}
		for (i = 0; i < renderers.length; i++) {
			var renderer = renderers[i];
			if (typeof renderer.canRender === 'function' && !renderer.canRender(node, context)) {
				continue;
			}
			return renderer;
		}
		return null;
	}

	function invokeLabel(node, context) {
		context = context || { name: 'form', mode: 'edit' };
		if (!node) {
			return null;
		}
		var renderer = findRenderer(node, context);
		if (!renderer || typeof renderer.renderLabel !== 'function') {
			return null;
		}
		var result = renderer.renderLabel(node, context);
		if (result === false || result == null) {
			return null;
		}
		return result;
	}

	function invokeContent(node, context, readonly) {
		context = contentContext(context, !!readonly);
		if (!node) {
			return null;
		}
		var renderer = findRenderer(node, context);
		if (!renderer || typeof renderer.renderContent !== 'function') {
			return null;
		}
		var result = renderer.renderContent(node, context, !!readonly || !isEdit(context));
		if (result === false || result == null) {
			return null;
		}
		return result;
	}

	/**
	 * Paint a tree icon key (Dashicon). Square chrome.
	 * Shared by tree rows, icon list chooser, and closed-select preview.
	 *
	 * @param {string} iconKey
	 * @param {string} [extraClass]
	 * @return {HTMLElement|null}
	 */
	function paintIcon(iconKey, extraClass) {
		var key = iconKey != null ? String(iconKey).replace(/^dashicons-/, '') : '';
		key = key.replace(/[^a-z0-9\-]/gi, '').toLowerCase();
		if (!key) {
			return null;
		}
		var extra = extraClass ? ' ' + String(extraClass) : '';
		return createEl('span', {
			className: 'dashicons dashicons-' + key + ' wtt-tree__icon' + extra,
			'aria-hidden': 'true',
		});
	}

	/**
	 * Default taxonomy-tree name chrome (span.wtt-tree__name).
	 * Used when no type renderer matches, or as the baseline each renderer gets.
	 *
	 * Context flags:
	 *   showType — append [typeLabel]
	 *   displayName — override node.name (e.g. Fallstudie → Taxonomy)
	 *
	 * @return {HTMLElement}
	 */
	function defaultRenderTreeNode(node, context) {
		context = context || {};
		var label = '';
		if (context.displayName != null && String(context.displayName) !== '') {
			label = String(context.displayName);
		} else if (node && node.displayName != null && String(node.displayName) !== '') {
			label = String(node.displayName);
		} else if (node && node.name != null) {
			label = String(node.name);
		}
		if (context.showType && node && node.typeLabel) {
			label += ' [' + String(node.typeLabel) + ']';
		}
		var children = [];
		var iconEl = paintIcon(node && node.icon != null ? node.icon : '');
		if (iconEl) {
			children.push(iconEl);
		}
		children.push(
			createEl('span', {
				className: 'wtt-tree__name-text',
				text: label,
			})
		);
		var nameEl = createEl(
			'span',
			{
				className: 'wtt-tree__name',
			},
			children
		);
		if (node && node.shortDescription) {
			nameEl.title = String(node.shortDescription);
		} else if (node && node.description) {
			nameEl.title = String(node.description);
		}
		return nameEl;
	}

	/**
	 * Every registered renderer that paints nodes must expose renderTreeNode.
	 * Missing implementations get the default taxonomy-tree name chrome.
	 */
	function ensureRenderTreeNode(renderer) {
		if (!renderer || typeof renderer.renderTreeNode === 'function') {
			return;
		}
		renderer.renderTreeNode = function (node, context) {
			if (typeof this.canRender === 'function' && !this.canRender(node, context)) {
				return false;
			}
			return defaultRenderTreeNode(node, context);
		};
	}

	function invokeTreeNode(node, context) {
		context = Object.assign(
			{
				name: 'tree',
				mode: 'display',
			},
			context || {}
		);
		if (!node) {
			return null;
		}
		var renderer = findRenderer(node, context);
		if (renderer && typeof renderer.renderTreeNode === 'function') {
			var result = renderer.renderTreeNode(node, context);
			if (result !== false && result != null) {
				return result;
			}
		}
		return defaultRenderTreeNode(node, context);
	}

	/* ------------------------------------------------------------------ */
	/* Registry                                                              */
	/* ------------------------------------------------------------------ */

	var Registry = {
		register: function (renderer) {
			if (
				!renderer ||
				(typeof renderer.render !== 'function' &&
					typeof renderer.renderContent !== 'function' &&
					typeof renderer.renderTreeNode !== 'function')
			) {
				return;
			}
			if (!renderer.id) {
				renderer.id = String(renderer.label || 'renderer-' + renderers.length)
					.trim()
					.toLowerCase()
					.replace(/\s+/g, '_');
			}
			ensureRenderTreeNode(renderer);
			renderers.push(renderer);
		},

		/**
		 * Compatible field renderers for Preferred render select (canRender only).
		 * @return {Array<{id:string,label:string}>}
		 */
		listCompatible: function (node, context) {
			context = context || { name: 'form', mode: 'edit' };
			var out = [];
			var seen = {};
			var i;
			for (i = 0; i < renderers.length; i++) {
				var r = renderers[i];
				if (!r || typeof r.canRender !== 'function') {
					continue;
				}
				if (!r.canRender(node, context)) {
					continue;
				}
				var id = String(r.id || '')
					.trim()
					.toLowerCase();
				if (!id || seen[id]) {
					continue;
				}
				seen[id] = true;
				out.push({
					id: id,
					label: String(r.label || id),
				});
			}
			return out;
		},

		getById: function (id) {
			id = String(id || '')
				.trim()
				.toLowerCase();
			if (!id) {
				return null;
			}
			var i;
			for (i = 0; i < renderers.length; i++) {
				if (
					String((renderers[i] && renderers[i].id) || '')
						.trim()
						.toLowerCase() === id
				) {
					return renderers[i];
				}
			}
			return null;
		},

		/**
		 * Field designation (caption / column title / tree name).
		 * @return {HTMLElement|null}
		 */
		renderLabel: function (node, context) {
			return invokeLabel(node, context);
		},

		/**
		 * Field value / control only.
		 * @param {boolean} [readonly] When true, force read-only display output.
		 * @return {HTMLElement|null}
		 */
		renderContent: function (node, context, readonly) {
			return invokeContent(node, context, !!readonly);
		},

		/**
		 * Taxonomy tree row — node name chrome in the admin tree.
		 * Type renderer when canRender; otherwise defaultRenderTreeNode.
		 * Context: { name:'tree', mode:'display', showType?, displayName?, depth? }.
		 * @return {HTMLElement|null}
		 */
		renderTreeNode: function (node, context) {
			return invokeTreeNode(node, context);
		},

		/** Shared default tree-name chrome (also attached by ensureRenderTreeNode). */
		defaultRenderTreeNode: defaultRenderTreeNode,

		/**
		 * Example node DTO for preview of a type (or of a live node's type).
		 * int → { name: 'Int_name', type int }; table → bands Kopf/Zeile/Fuss.
		 * @param {object|string} nodeOrTypeKey
		 * @return {object|null}
		 */
		getExampleNode: function (nodeOrTypeKey) {
			var key = '';
			var source = null;
			if (typeof nodeOrTypeKey === 'string') {
				key = String(nodeOrTypeKey)
					.trim()
					.toLowerCase();
				source = { typeKey: key, type: { name: key }, name: key };
			} else if (nodeOrTypeKey && typeof nodeOrTypeKey === 'object') {
				source = nodeOrTypeKey;
				key = resolveTypeKey(nodeOrTypeKey);
			}
			if (!key) {
				return null;
			}
			var probe = source || {
				typeKey: key,
				type: { name: key },
				name: key,
			};
			var i;
			for (i = 0; i < renderers.length; i++) {
				var r = renderers[i];
				if (!r || typeof r.getExampleNode !== 'function') {
					continue;
				}
				if (typeof r.canRender === 'function' && !r.canRender(probe, { name: 'table' }) && !r.canRender(probe, { name: 'form' })) {
					continue;
				}
				var example = r.getExampleNode(probe);
				if (example) {
					return example;
				}
			}
			if (SIMPLE_SCALAR_KEYS[key]) {
				return makeExampleScalarNode(key, sampleForTypeKey(key, ''));
			}
			return null;
		},

		/**
		 * Full field (label + content, or composition of children).
		 * Guards against cyclic setMembers / quantitySchema.members.
		 * @return {HTMLElement|null}
		 */
		render: function (node, context) {
			context = context || { name: 'form', mode: 'edit' };
			if (!node) {
				return null;
			}

			var visitKey =
				node.id != null
					? 'id:' + String(node.id)
					: node.name != null
					  ? 'name:' + String(node.name)
					  : '';
			var visited = context._wttVisited;
			if (!visited) {
				visited = {};
				context = Object.assign({}, context, { _wttVisited: visited });
			}
			if (visitKey && visited[visitKey]) {
				return null;
			}
			if (visitKey) {
				visited[visitKey] = true;
			}

			var members = compositionMembers(node);
			if (members.length) {
				var host = createEl('div', {
					className: 'wtt-node-render wtt-node-render--composition',
				});
				var any = false;
				members.forEach(function (member) {
					var piece = Registry.render(
						member,
						Object.assign({}, context, { _wttVisited: visited })
					);
					if (piece) {
						any = true;
						host.appendChild(piece);
					}
				});
				return any ? host : null;
			}

			var renderer = findRenderer(node, context);
			if (!renderer) {
				return null;
			}
			if (typeof renderer.render === 'function') {
				var full = renderer.render(node, context);
				if (full !== false && full != null) {
					return full;
				}
			}
			if (typeof renderer.renderContent === 'function') {
				return composeLabeledField(renderer, node, context) || null;
			}
			return null;
		},

		canHandle: function (node, context) {
			context = context || { name: 'form', mode: 'edit' };
			if (!node) {
				return false;
			}
			if (compositionMembers(node).length) {
				return true;
			}
			return !!findRenderer(node, context);
		},
	};

	/* ------------------------------------------------------------------ */
	/* Shared scalar renderer helpers                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Sample string from central WTTSampleData (name heuristics → type fallback).
	 * @param {string} typeKey
	 * @param {string} [fallback]
	 * @param {object|null} [attrNode] Attribute/member for name-aware fill
	 * @return {string}
	 */
	function sampleForTypeKey(typeKey, fallback, attrNode) {
		var Sample = global.WTTSampleData;
		if (Sample) {
			var mapped = '';
			if (typeof Sample.forAttribute === 'function' && attrNode) {
				mapped = Sample.forAttribute(
					Object.assign({}, attrNode, { typeKey: typeKey || resolveTypeKey(attrNode) })
				);
			} else if (typeof Sample.forType === 'function') {
				mapped = Sample.forType(
					typeKey,
					attrNode
						? {
								name: attrNode.name,
								shortDescription: attrNode.shortDescription,
								displayName: attrNode.displayName,
							}
						: null
				);
			}
			if (mapped != null && String(mapped) !== '') {
				return String(mapped);
			}
		}
		return fallback != null ? String(fallback) : '';
	}

	function makeScalarRenderer(typeKey, controlOpts) {
		return {
			id: typeKey,
			label: typeKey.charAt(0).toUpperCase() + typeKey.slice(1),
			canRender: function (node) {
				return resolveTypeKey(node) === typeKey;
			},
			/**
			 * Example node for preview: e.g. int → { name: 'Int_name', type int }.
			 * Sample value comes from WTTSampleData (name-aware when attr present).
			 * @return {object}
			 */
			getExampleNode: function () {
				return makeExampleScalarNode(
					typeKey,
					sampleForTypeKey(
						typeKey,
						controlOpts.sample != null ? controlOpts.sample : ''
					)
				);
			},
			renderLabel: function (node, context) {
				if (!this.canRender(node, context)) {
					return false;
				}
				var text = fieldCaption(node);
				if (!text) {
					return false;
				}
				return createEl('span', {
					className: 'wtt-node-render__label',
					text: text,
				});
			},
			/**
			 * @param {boolean} [readonly] Force read-only display (no input).
			 */
			renderContent: function (node, context, readonly) {
				if (!this.canRender(node, context)) {
					return false;
				}
				var ctx = Object.assign({}, contentContext(context, !!readonly), {
					node: node,
				});
				var mappedSample = sampleForTypeKey(
					typeKey,
					controlOpts.sample != null ? controlOpts.sample : '',
					node
				);
				var opts = Object.assign({}, controlOpts, {
					context: ctx,
					sample: mappedSample,
				});
				var rawVal = ctx.value != null ? String(ctx.value) : '';
				/*
				 * Preview seeds empty cells with type/name samples. Festwert /
				 * Default dialogs pass noSampleFill so empty stays empty (not "Sample").
				 */
				var needsFill = rawVal === '' && !ctx.noSampleFill;
				/*
				 * Generic text fallback ("Sample") must not stick on email fields —
				 * prefer the type/name sample map (e.g. herbert@home.de).
				 */
				if (
					!needsFill &&
					!ctx.noSampleFill &&
					typeKey === 'email' &&
					mappedSample &&
					rawVal === 'Sample' &&
					isValidEmail(mappedSample)
				) {
					needsFill = true;
				}
				if (needsFill) {
					var fill =
						node && node.sample != null && String(node.sample) !== ''
							? String(node.sample)
							: mappedSample;
					if (fill) {
						opts = Object.assign({}, opts, {
							context: Object.assign({}, ctx, {
								value: String(fill),
							}),
						});
					}
				}
				return renderScalarControl(opts);
			},
			/**
			 * Taxonomy tree name for nodes of this type (admin tree column).
			 */
			renderTreeNode: function (node, context) {
				if (!this.canRender(node, context)) {
					return false;
				}
				return defaultRenderTreeNode(node, context);
			},
			render: function (node, context) {
				if (!this.canRender(node, context)) {
					return false;
				}
				return composeLabeledField(this, node, context);
			},
		};
	}

	/**
	 * int — one renderer (edit + display); converters via WTTConverter / WTTIntValue.
	 * Canonical: decimal digit string. Preferred converter from node (default arabic).
	 */
	function intValueApi() {
		return global.WTTIntValue || null;
	}

	function converterRegistry() {
		return (global.WTTConverter && global.WTTConverter.Registry) || null;
	}

	function resolveIntDisplayFormat(node) {
		var reg = converterRegistry();
		if (reg && typeof reg.resolvePreferredId === 'function') {
			var fromReg = reg.resolvePreferredId(node);
			if (fromReg) {
				return fromReg;
			}
		}
		var api = intValueApi();
		var raw =
			(node && (node.preferredConverter || node.displayFormat || node.intDisplayFormat)) ||
			(node && node.intConfig && node.intConfig.displayFormat) ||
			(node && node.typeExtras && node.typeExtras.preferredConverter) ||
			(node && node.typeExtras && node.typeExtras.displayFormat) ||
			'';
		if (api && typeof api.normalizeFormatId === 'function') {
			return api.normalizeFormatId(raw);
		}
		return 'arabic';
	}

	function formatIntWithConverter(value, formatId, node) {
		var reg = converterRegistry();
		if (reg) {
			var c = typeof reg.getById === 'function' ? reg.getById(formatId) : null;
			if (c && typeof c.format === 'function') {
				return c.format(value, node);
			}
			if (typeof reg.formatPreferred === 'function' && node) {
				return reg.formatPreferred(value, node);
			}
		}
		var api = intValueApi();
		if (api && typeof api.format === 'function') {
			return api.format(value, formatId);
		}
		return value == null ? '' : String(value);
	}

	function syncIntValidity(input, hint, formatId, node) {
		var msg = i18nLabels.intInvalid || 'Enter a whole number.';
		var value = input.value;
		var result = { ok: true };
		var VReg =
			window.WTTValidator && window.WTTValidator.Registry
				? window.WTTValidator.Registry
				: null;
		if (VReg && typeof VReg.validateAll === 'function') {
			var probe = Object.assign({}, node || {}, {
				typeKey: 'int',
				validators: resolveValidatorsList(node),
			});
			result = VReg.validateAll(probe, value, { allowEmpty: true });
			if (result && result.message) {
				msg = result.message;
			}
		} else {
			var api = intValueApi();
			if (api && typeof api.validateAll === 'function') {
				result = api.validateAll(value, { allowEmpty: true });
				if (result && result.message) {
					msg = result.message;
				}
			} else if (value !== '' && value !== '-' && !/^-?\d+$/.test(value)) {
				result = { ok: false };
			} else if (value === '-') {
				result = { ok: false };
			}
		}
		var ok = !!(result && result.ok);
		input.classList.toggle('is-invalid', !ok);
		input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		if (hint) {
			hint.textContent = ok ? '' : msg;
			hint.hidden = ok;
		}
		if (!ok) {
			input.setAttribute('title', msg);
		} else {
			input.removeAttribute('title');
		}
		return ok;
	}

	function renderIntDisplay(value, compact, formatId, node) {
		var api = intValueApi();
		var displayVal = value === '' ? '—' : value;
		if (displayVal !== '—') {
			var formatted = formatIntWithConverter(value, formatId, node);
			displayVal = formatted || '—';
			if (displayVal === '') {
				displayVal = '—';
			}
		}
		var invalid =
			displayVal !== '—' &&
			api &&
			typeof api.validateAll === 'function' &&
			!api.validateAll(String(value), { allowEmpty: false }).ok;
		if (
			displayVal !== '—' &&
			!api &&
			!/^-?\d+$/.test(String(value))
		) {
			invalid = true;
		}
		return createEl('span', {
			className:
				'wtt-preview-display-value' +
				(invalid ? ' is-invalid' : '') +
				(compact ? ' wtt-preview-display-value--compact' : '') +
				' wtt-node-render--int',
			text: displayVal,
			title: invalid
				? i18nLabels.intInvalid || 'Enter a whole number.'
				: undefined,
		});
	}

	function renderIntEdit(opts) {
		opts = opts || {};
		var context = opts.context || {};
		var formatId = opts.formatId || 'arabic';
		var node = opts.node || null;
		var compact =
			!!opts.compact ||
			contextName(context) === 'table' ||
			contextName(context) === 'tree';
		var api = intValueApi();
		var raw = readValue(context, opts.sample || '');
		var value = raw;
		if (api && typeof api.normalize === 'function' && raw !== '') {
			value = api.normalize(raw, formatId);
		}
		var className =
			'wtt-preview-input wtt-node-render--int' +
			(compact ? ' wtt-preview-input--compact' : '');
		/*
		 * Int (field): type=text + numeric keyboard — no native spinner arrows.
		 * Preferred Int (spinner) uses inputType=number separately.
		 */
		var input = createEl('input', {
			type: 'text',
			className: className,
			inputmode: 'numeric',
			autocomplete: 'off',
			value: value,
		});
		input.value = value;

		var hint = createEl('span', {
			className: 'wtt-node-render__int-hint',
			hidden: true,
		});
		var wrap = createEl('div', {
			className: 'wtt-node-render__int-wrap',
		});
		wrap.appendChild(input);
		wrap.appendChild(hint);

		function applyFiltered(nextRaw, doCanonical) {
			var filtered =
				api && typeof api.filterLive === 'function'
					? api.filterLive(nextRaw, formatId)
					: String(nextRaw || '').replace(/[^\d-]/g, '');
			if (
				doCanonical &&
				api &&
				typeof api.parse === 'function' &&
				filtered !== '' &&
				filtered !== '-' &&
				api.isIntegerShape(filtered)
			) {
				var parsed = api.parse(filtered, formatId);
				if (parsed != null) {
					filtered = parsed;
				}
			}
			if (input.value !== filtered) {
				input.value = filtered;
				try {
					var pos = filtered.length;
					input.setSelectionRange(pos, pos);
				} catch (err) {
					/* ignore */
				}
			}
			syncIntValidity(input, hint, formatId, node);
			if (isEdit(context) && typeof context.onInput === 'function') {
				context.onInput(input.value);
			}
		}

		input.addEventListener('input', function () {
			applyFiltered(input.value, false);
		});
		input.addEventListener('blur', function () {
			applyFiltered(input.value, true);
		});
		if (context.valueKey) {
			input.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		syncIntValidity(input, hint, formatId, node);
		return wrap;
	}

	var IntRenderer = {
		id: 'int',
		label: 'Int (field)',
		canRender: function (node) {
			return resolveTypeKey(node) === 'int';
		},
		getExampleNode: function () {
			return makeExampleScalarNode(
				'int',
				sampleForTypeKey('int', '42')
			);
		},
		renderLabel: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var text = fieldCaption(node);
			if (!text) {
				return false;
			}
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var formatId = resolveIntDisplayFormat(node);
			var mappedSample = sampleForTypeKey('int', '42', node);
			var rawVal = ctx.value != null ? String(ctx.value) : '';
			var needsFill = rawVal === '' && !ctx.noSampleFill;
			var opts = {
				context: ctx,
				sample: mappedSample,
				formatId: formatId,
				node: node,
				compact:
					contextName(ctx) === 'table' ||
					contextName(ctx) === 'tree',
			};
			if (needsFill) {
				var fill =
					node && node.sample != null && String(node.sample) !== ''
						? String(node.sample)
						: mappedSample;
				if (fill) {
					opts.context = Object.assign({}, ctx, {
						value: String(fill),
					});
				}
			}
			if (!isEdit(opts.context)) {
				return renderIntDisplay(
					readValue(opts.context, opts.sample || ''),
					opts.compact,
					formatId,
					node
				);
			}
			return renderIntEdit(opts);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
	};

	var IntSpinnerRenderer = makeScalarRenderer('int', {
		inputType: 'number',
		inputMode: 'numeric',
		inputClass: 'wtt-node-render--int wtt-node-render--int-spinner',
	});
	IntSpinnerRenderer.id = 'int_spinner';
	IntSpinnerRenderer.label = 'Int (spinner)';

	var IntRangeRenderer = makeScalarRenderer('int', {
		control: 'range',
		inputClass: 'wtt-node-render--int wtt-node-render--int-range',
	});
	IntRangeRenderer.id = 'int_range';
	IntRangeRenderer.label = 'Int (range)';

	var CharRenderer = makeScalarRenderer('char', {
		inputType: 'text',
		maxLength: 1,
		size: 1,
		inputClass: 'wtt-node-render--char',
	});
	CharRenderer.label = 'Char (field)';
	(function () {
		var baseContent = CharRenderer.renderContent;
		CharRenderer.renderContent = function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = Object.assign({}, contentContext(context, !!readonly), {
				node: node,
			});
			if (!isEdit(ctx)) {
				var raw = readValue(ctx, '');
				if (raw === '' && !ctx.noSampleFill) {
					raw =
						(node && node.sample != null && String(node.sample) !== ''
							? String(node.sample)
							: '') ||
						sampleForTypeKey('char', 'A', node) ||
						'A';
				}
				var display = raw === '' ? '—' : raw;
				var reg = converterRegistry();
				if (raw !== '' && reg && typeof reg.formatPreferred === 'function') {
					var formatted = reg.formatPreferred(raw, node);
					if (formatted != null && String(formatted) !== '') {
						display = String(formatted);
					}
				}
				return createEl('span', {
					className:
						'wtt-preview-display-value' +
						(contextName(ctx) === 'table' || contextName(ctx) === 'tree'
							? ' wtt-preview-display-value--compact'
							: '') +
						' wtt-node-render--char',
					text: display,
				});
			}
			return baseContent.call(this, node, context, readonly);
		};
	})();

	var DoubleRenderer = makeScalarRenderer('double', {
		/* text + decimal keyboard — no native spinner arrows (those are int-only UX). */
		inputType: 'text',
		inputMode: 'decimal',
		inputClass: 'wtt-node-render--double',
	});
	DoubleRenderer.label = 'Double (field)';

	var DoubleSpinnerRenderer = makeScalarRenderer('double', {
		inputType: 'number',
		inputMode: 'decimal',
		step: 'any',
		inputClass: 'wtt-node-render--double wtt-node-render--double-spinner',
	});
	DoubleSpinnerRenderer.id = 'double_spinner';
	DoubleSpinnerRenderer.label = 'Double (spinner)';

	var DoubleRangeRenderer = makeScalarRenderer('double', {
		control: 'range',
		step: 'any',
		inputClass: 'wtt-node-render--double wtt-node-render--double-range',
	});
	DoubleRangeRenderer.id = 'double_range';
	DoubleRangeRenderer.label = 'Double (range)';

	var TextRenderer = makeScalarRenderer('text', {
		inputType: 'text',
		inputClass: 'wtt-node-render--text',
	});

	/*
	 * Use type=text + inputmode=email so caret survives preview re-renders.
	 * Native type=email often refuses selectionStart/setSelectionRange → cursor jumps to start.
	 */
	var EmailRenderer = makeScalarRenderer('email', {
		inputType: 'text',
		inputMode: 'email',
		inputClass: 'wtt-node-render--email',
		placeholder: 'herbert@home.de',
		autocomplete: 'email',
		validate: 'email',
	});

	var TextareaRenderer = makeScalarRenderer('textarea', {
		control: 'textarea',
		rows: 4,
		cols: 40,
		inputClass: 'wtt-node-render--textarea',
	});

	var BoolRenderer = makeScalarRenderer('bool', {
		control: 'switch',
		inputClass: 'wtt-node-render--bool',
	});
	BoolRenderer.label = 'Bool (switch)';

	var BoolCheckboxRenderer = makeScalarRenderer('bool', {
		control: 'checkbox',
		inputClass: 'wtt-node-render--bool wtt-node-render--bool-checkbox',
	});
	BoolCheckboxRenderer.id = 'bool_checkbox';
	BoolCheckboxRenderer.label = 'Bool (checkbox)';

	var BoolRadioRenderer = makeScalarRenderer('bool', {
		control: 'radio',
		inputClass: 'wtt-node-render--bool wtt-node-render--bool-radio',
	});
	BoolRadioRenderer.id = 'bool_radio';
	BoolRadioRenderer.label = 'Bool (radio)';

	var TimeRenderer = makeScalarRenderer('time', {
		inputType: 'time',
		inputClass: 'wtt-node-render--time',
		sample: '14:30',
	});
	TimeRenderer.label = 'Time';

	var DateTimeRenderer = makeScalarRenderer('datetime', {
		inputType: 'datetime-local',
		inputClass: 'wtt-node-render--datetime',
		sample: '2024-06-15T14:30',
	});
	DateTimeRenderer.label = 'Date+time';

	var ColorRenderer = makeScalarRenderer('color', {
		inputType: 'color',
		inputClass: 'wtt-node-render--color',
		sample: '#2271b1',
	});
	ColorRenderer.label = 'Color';

	/**
	 * Host Node presentation field (Q117 context: form/table/select/symbol/help/icon).
	 * Alias type key: display_node_name (legacy).
	 */
	var NodePresentationRenderer = {
		id: 'node_presentation',
		label: 'Node presentation',
		canRender: function (node) {
			var key = resolveTypeKey(node);
			return (
				key === 'node_presentation' ||
				key === 'display_node_name' ||
				key.indexOf('node_presentation') !== -1 ||
				key.indexOf('display_node_name') !== -1
			);
		},
		getExampleNode: function () {
			return makeExampleScalarNode('node_presentation', 'Node name');
		},
		renderLabel: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var text = fieldCaption(node);
			if (!text) {
				return false;
			}
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		renderContent: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, true);
			var presented = resolveNodePresentationValue(node, ctx);
			var compact =
				contextName(ctx) === 'table' || contextName(ctx) === 'compact';
			if (compact) {
				return createEl('span', {
					className:
						'wtt-preview-display-name wtt-node-render--display-name wtt-node-render--node-presentation',
					text: presented,
				});
			}
			return createEl('input', {
				type: 'text',
				className:
					'wtt-preview-input wtt-preview-input--display-name wtt-node-render--display-name wtt-node-render--node-presentation',
				value: presented,
				readonly: 'readonly',
				disabled: 'disabled',
				title: presented,
			});
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var wrap = createEl('div', {
				className:
					'wtt-node-render wtt-node-render--display-name wtt-node-render--node-presentation is-display',
			});
			var label = this.renderLabel(node, context);
			if (label) {
				wrap.appendChild(label);
			}
			var content = this.renderContent(node, context);
			if (content) {
				wrap.appendChild(content);
			}
			return wrap;
		},
	};

	/** Legacy export alias. */
	var DisplayNodeNameRenderer = NodePresentationRenderer;

	/**
	 * Resolve Q117 presentation context on a field/type node.
	 */
	function presentationContextFromNode(node) {
		var cfg = node && node.presentationConfig;
		var raw = '';
		if (cfg && cfg.context != null) {
			raw = String(cfg.context);
		} else if (node && node.typeExtras && node.typeExtras.presentationContext != null) {
			raw = String(node.typeExtras.presentationContext);
		} else if (node && node.presentationContext != null) {
			raw = String(node.presentationContext);
		}
		raw = raw.trim().toLowerCase();
		if (raw === 'name') {
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
		return allowed[raw] ? raw : 'form';
	}

	/**
	 * Value from host presentation map / legacy shortDescription / name.
	 * Always resolves by Q117 context — ignore paint value/sample (often the
	 * host form name from preview fill, which would bypass Symbol/Help/Icon).
	 * Order: map[context] → empty-slot fallbacks (never skip settings).
	 */
	function resolveNodePresentationValue(node, paintCtx) {
		var fieldCtx = presentationContextFromNode(node);
		var mapRaw =
			(node && node.hostPresentation) ||
			(node && node.presentation) ||
			null;
		var map = null;
		if (mapRaw && typeof mapRaw === 'object') {
			if (
				Object.prototype.hasOwnProperty.call(mapRaw, 'loaded') &&
				!mapRaw.loaded
			) {
				map = null;
			} else if (mapRaw.values && typeof mapRaw.values === 'object') {
				map = mapRaw.values;
			} else {
				map = mapRaw;
			}
		}
		if (map && typeof map === 'object' && map[fieldCtx] != null) {
			var fromMap = String(map[fieldCtx]).trim();
			if (fromMap) {
				return fromMap;
			}
		}
		var hostName = String(
			(node &&
				(node.hostName ||
					node.hostDisplayName ||
					node.nodeName ||
					(node.host && node.host.name))) ||
				''
		).trim();
		var short = String(
			(node &&
				(node.hostShortDescription ||
					node.shortDescription ||
					(node.host && node.host.shortDescription))) ||
				''
		).trim();
		if (fieldCtx === 'symbol') {
			/* Never fall back to form name for symbol (Micro uses glyph). */
			return short || '—';
		}
		if (fieldCtx === 'table') {
			return short || hostName || '—';
		}
		if (fieldCtx === 'icon') {
			return '—';
		}
		/* form / select / help — fallback only when map slot empty */
		return hostName || 'Node name';
	}

	/**
	 * Date / date-time simple type.
	 * Store SoT: Unix timestamp (decimal string). Mode from node.dateConfig.mode
	 * (`date` | `datetime`, default date) — configured on the date catalog type.
	 */
	function dateModeFromNode(node) {
		if (node && node.dateConfig && node.dateConfig.mode === 'datetime') {
			return 'datetime';
		}
		return 'date';
	}

	function parseDateStore(raw) {
		var s = raw == null ? '' : String(raw).trim();
		if (!s) {
			return 0;
		}
		if (/^-?\d+$/.test(s)) {
			return parseInt(s, 10) || 0;
		}
		var normalized = s.indexOf('T') !== -1 ? s : s.replace(' ', 'T');
		var ms = Date.parse(normalized);
		if (!isNaN(ms)) {
			return Math.floor(ms / 1000);
		}
		return 0;
	}

	function pad2(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function formatDateForInput(ts, mode) {
		if (!(ts > 0)) {
			return '';
		}
		var d = new Date(ts * 1000);
		if (isNaN(d.getTime())) {
			return '';
		}
		var y = d.getFullYear();
		var m = pad2(d.getMonth() + 1);
		var day = pad2(d.getDate());
		if (mode === 'datetime') {
			return y + '-' + m + '-' + day + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
		}
		return y + '-' + m + '-' + day;
	}

	function formatDateForDisplay(ts, mode) {
		if (!(ts > 0)) {
			return '';
		}
		var d = new Date(ts * 1000);
		if (isNaN(d.getTime())) {
			return '';
		}
		try {
			if (mode === 'datetime') {
				return d.toLocaleString(undefined, {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
				});
			}
			return d.toLocaleDateString(undefined, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			});
		} catch (e) {
			return formatDateForInput(ts, mode).replace('T', ' ');
		}
	}

	function storeFromDateInput(value, mode) {
		var s = value == null ? '' : String(value).trim();
		if (!s) {
			return '';
		}
		var d;
		if (mode === 'datetime') {
			d = new Date(s);
		} else {
			/* date-only: local midnight of that calendar day */
			var parts = s.split('-');
			if (parts.length !== 3) {
				return '';
			}
			d = new Date(
				parseInt(parts[0], 10),
				parseInt(parts[1], 10) - 1,
				parseInt(parts[2], 10),
				0,
				0,
				0,
				0
			);
		}
		if (isNaN(d.getTime())) {
			return '';
		}
		return String(Math.floor(d.getTime() / 1000));
	}

	function renderDateControl(node, context) {
		var mode = dateModeFromNode(node);
		var compact = contextName(context) === 'table' || contextName(context) === 'compact';
		var raw = readValue(context, '');
		var ts = parseDateStore(raw);
		if (!isEdit(context)) {
			var shown = formatDateForDisplay(ts, mode);
			return createEl('span', {
				className:
					'wtt-node-render__display wtt-node-render--date' +
					(compact ? ' is-compact' : ''),
				text: shown || '—',
			});
		}
		var input = createEl('input', {
			type: mode === 'datetime' ? 'datetime-local' : 'date',
			className:
				'wtt-preview-input wtt-node-render--date' +
				(compact ? ' is-compact' : ''),
			value: formatDateForInput(ts, mode),
		});
		if (context && typeof context.onInput === 'function') {
			var emit = function () {
				context.onInput(storeFromDateInput(input.value, mode));
			};
			input.addEventListener('input', emit);
			input.addEventListener('change', emit);
		}
		if (context && context.valueKey) {
			input.setAttribute('data-wtt-pv', String(context.valueKey));
		}
		return input;
	}

	var DateRenderer = {
		id: 'date',
		label: 'Date',
		canRender: function (node) {
			return resolveTypeKey(node) === 'date';
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var rawVal = ctx.value != null ? String(ctx.value) : '';
			if (
				!ctx.noSampleFill &&
				(rawVal === '' || rawVal === 'Sample')
			) {
				var mapped = sampleForTypeKey('date', '1718461800', node);
				if (mapped) {
					ctx = Object.assign({}, ctx, { value: String(mapped) });
				}
			}
			return renderDateControl(node, ctx);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
		getExampleNode: function () {
			return makeExampleScalarNode('date', '1718461800');
		},
	};

	/**
	 * Media (Q65): Preferred MediaRenderer → Editable (Select/URL) + Display surface.
	 * Delegates to WTTMediaRender so admin preview matches field paint.
	 */
	function mediaSampleStore(node) {
		var Media = global.WTTMediaRender;
		if (!Media || typeof Media.toStore !== 'function') {
			return '';
		}
		var cfg = (node && node.mediaConfig) || null;
		var allowed =
			cfg && Array.isArray(cfg.allowedKinds) ? cfg.allowedKinds : [];
		var entries =
			typeof Media.sampleEntries === 'function' ? Media.sampleEntries() : [];
		var i;
		if (allowed.length && entries.length) {
			for (i = 0; i < entries.length; i++) {
				var e = entries[i];
				if (e && e.kind && allowed.indexOf(e.kind) !== -1 && e.ref) {
					return Media.toStore(e.ref);
				}
			}
		}
		if (entries[0] && entries[0].ref) {
			return Media.toStore(entries[0].ref);
		}
		if (Media.SAMPLE_IMAGE) {
			return Media.toStore(Media.SAMPLE_IMAGE);
		}
		return '';
	}

	var MediaRenderer = {
		id: 'media',
		label: 'MediaRenderer',
		canRender: function (node) {
			return resolveTypeKey(node) === 'media';
		},
		getExampleNode: function () {
			return makeExampleScalarNode('media', mediaSampleStore(null));
		},
		renderLabel: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var text = fieldCaption(node);
			if (!text) {
				return false;
			}
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var Media = global.WTTMediaRender;
			if (!Media || typeof Media.renderField !== 'function') {
				return createEl('span', {
					className: 'wtt-field-hint',
					text: '—',
				});
			}
			var ctx = contentContext(context, !!readonly);
			var raw = ctx.value != null ? String(ctx.value) : '';
			if (!ctx.noSampleFill && (raw === '' || raw === 'Sample')) {
				var fill =
					node && node.sample != null && String(node.sample) !== ''
						? String(node.sample)
						: mediaSampleStore(node);
				if (fill) {
					raw = fill;
					ctx = Object.assign({}, ctx, { value: fill });
				}
			}
			var compact =
				contextName(ctx) === 'table' ||
				contextName(ctx) === 'compact' ||
				!!(ctx && ctx.compact);
			return Media.renderField(raw, {
				mode: ctx.mode === 'display' ? 'display' : 'edit',
				compact: compact,
				mediaConfig: (node && node.mediaConfig) || null,
				onChange:
					typeof ctx.onInput === 'function' ? ctx.onInput : null,
				el: createEl,
			});
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
		renderTreeNode: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return defaultRenderTreeNode(node, context);
		},
	};

	Registry.register(IntRenderer);
	Registry.register(IntSpinnerRenderer);
	Registry.register(IntRangeRenderer);
	Registry.register(CharRenderer);
	Registry.register(DoubleRenderer);
	Registry.register(DoubleSpinnerRenderer);
	Registry.register(DoubleRangeRenderer);
	Registry.register(TextRenderer);
	Registry.register(EmailRenderer);
	Registry.register(TextareaRenderer);
	Registry.register(BoolRenderer);
	Registry.register(BoolCheckboxRenderer);
	Registry.register(BoolRadioRenderer);
	Registry.register(NodePresentationRenderer);
	Registry.register(DateRenderer);
	Registry.register(TimeRenderer);
	Registry.register(DateTimeRenderer);
	Registry.register(ColorRenderer);
	Registry.register(MediaRenderer);
	Registry.register(UnitRenderer);
	Registry.register(QuantityRenderer);

	/**
	 * Enum (Q52): closed options → select. Options from node.enumOptions
	 * (field → Option → leaves, or direct children fallback).
	 */
	/**
	 * PARKED (Q90): catalog `enum` — legacy scaffold only. Do not extend.
	 * Prefer hierarchy + attributes / Default value for closed values.
	 */
	var EnumRenderer = {
		id: 'enum',
		label: 'Enum',
		canRender: function (node) {
			return resolveTypeKey(node) === 'enum';
		},
		getExampleNode: function () {
			return {
				name: 'Enum_name',
				displayName: 'Enum_name',
				typeKey: 'enum',
				type: { name: 'enum' },
				sample: 'Option A',
				isExample: true,
				enumOptions: [
					{ id: 1, name: 'Option A' },
					{ id: 2, name: 'Option B' },
					{ id: 3, name: 'Option C' },
				],
			};
		},
		renderLabel: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var text = fieldCaption(node);
			if (!text) {
				return false;
			}
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var options = normalizeEnumOptions(node);
			var raw =
				ctx.value != null && String(ctx.value) !== ''
					? String(ctx.value)
					: node && node.sample != null
						? String(node.sample)
						: '';
			var selected = resolveEnumSelection(options, raw);
			var displayLabel = selected
				? selected.name
				: raw || (options[0] ? options[0].name : '—');

			if (!isEdit(ctx) || ctx.readonly) {
				return createEl('span', {
					className:
						'wtt-node-render__value wtt-node-render--enum wtt-node-render--display',
					text: displayLabel,
				});
			}

			var sel = createEl('select', {
				className: 'wtt-node-render__select wtt-node-render--enum',
			});
			if (!options.length) {
				sel.appendChild(
					createEl('option', {
						value: '',
						text: '— no options —',
					})
				);
				sel.disabled = true;
				return sel;
			}
			options.forEach(function (opt) {
				var o = createEl('option', {
					value: String(opt.id || opt.name),
					text: opt.name,
				});
				if (
					selected &&
					(String(opt.id) === String(selected.id) ||
						String(opt.name) === String(selected.name))
				) {
					o.selected = true;
				}
				sel.appendChild(o);
			});
			if (!selected && options[0]) {
				sel.value = String(options[0].id || options[0].name);
			}
			if (typeof ctx.onInput === 'function') {
				sel.addEventListener('change', function () {
					ctx.onInput(sel.value);
				});
			}
			return sel;
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
	};

	Registry.register(EnumRenderer);

	/**
	 * node_ref (Q73): pick id(s) under catalog root (ref_scope).
	 * Options from node.nodeRefOptions; multiplicity 0..* / 1..* → multi-select.
	 * Catalog chooser (popup/inline via treePickerMode): search, list, mini-form create.
	 * Distinct from Relation Mult. on ref_scope edges.
	 */
	var NodeRefRenderer = {
		id: 'node_ref',
		label: 'Node ref',
		canRender: function (node) {
			return resolveTypeKey(node) === 'node_ref';
		},
		getExampleNode: function () {
			return {
				name: 'NodeRef_name',
				displayName: 'NodeRef_name',
				typeKey: 'node_ref',
				type: { name: 'node_ref' },
				sample: '1,2',
				isExample: true,
				fieldMultiplicity: '1..*',
				refScopeId: 1,
				nodeRefOptions: [
					{ id: 1, name: 'Contact A', path: 'Contacts / Contact A' },
					{ id: 2, name: 'Contact B', path: 'Contacts / Contact B' },
					{ id: 3, name: 'Contact C', path: 'Contacts / Contact C' },
				],
				nodeRefCreateFields: [
					{ id: 0, key: 'name', name: 'Name', typeName: 'text', required: true },
				],
			};
		},
		renderLabel: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var text = fieldCaption(node);
			if (!text) {
				return false;
			}
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var options = normalizeNodeRefOptions(node);
			var mult = String((node && node.fieldMultiplicity) || '0..1');
			var multi = isMultiFieldMultiplicity(mult);
			var raw =
				ctx.value != null && String(ctx.value) !== ''
					? String(ctx.value)
					: node && node.sample != null
						? String(node.sample)
						: '';
			if (!raw && multi && options.length) {
				raw = sampleNodeRefValue(options, mult);
			}
			var selectedIds = parseRefValueIds(raw);
			if (multi && !selectedIds.length && options.length) {
				selectedIds = parseRefValueIds(sampleNodeRefValue(options, mult));
			}

			if (!isEdit(ctx) || ctx.readonly) {
				return renderNodeRefDisplay(options, selectedIds, mult, raw);
			}

			var scopeId = node && (parseInt(node.refScopeId, 10) || 0);
			if (!scopeId && !options.length) {
				return createEl('span', {
					className:
						'wtt-node-render__hint wtt-node-render--node-ref',
					text: '— set catalog root (ref_scope) —',
				});
			}

			return renderNodeRefChooser(node, options, selectedIds, mult, ctx);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			return composeLabeledField(this, node, context);
		},
	};

	Registry.register(NodeRefRenderer);

	function sampleNodeRefValue(options, multiplicity) {
		options = options || [];
		if (!options.length) {
			return '';
		}
		if (isMultiFieldMultiplicity(multiplicity)) {
			var take =
				options.length >= 3 ? 3 : options.length >= 2 ? 2 : 1;
			return options
				.slice(0, take)
				.map(function (o) {
					return String(o.id);
				})
				.join(',');
		}
		return String(options[0].id);
	}

	function renderNodeRefDisplay(options, selectedIds, mult, rawFallback) {
		var wrap = createEl('div', {
			className:
				'wtt-node-render__ref-list wtt-node-render--node-ref wtt-node-render--display' +
				(isMultiFieldMultiplicity(mult)
					? ' wtt-node-render__ref-list--multi'
					: ''),
		});
		if (!selectedIds.length) {
			wrap.appendChild(
				createEl('span', {
					className: 'wtt-node-render__value',
					text:
						rawFallback && String(rawFallback).trim()
							? String(rawFallback)
							: '—',
				})
			);
			return wrap;
		}
		var byId = {};
		options.forEach(function (o) {
			byId[String(o.id)] = o;
		});
		selectedIds.forEach(function (id) {
			var o = byId[String(id)];
			wrap.appendChild(
				createEl('span', {
					className: 'wtt-node-render__ref-chip',
					text: o ? o.name || o.path : String(id),
					title: o ? o.path || o.name : String(id),
				})
			);
		});
		return wrap;
	}

	function createNodeRefChip(label, title, onRemove) {
		var chip = createEl('span', {
			className:
				'wtt-node-render__ref-chip' +
				(typeof onRemove === 'function'
					? ' wtt-node-render__ref-chip--removable'
					: ''),
			title: title || label,
		});
		chip.appendChild(
			createEl('span', {
				className: 'wtt-node-render__ref-chip-label',
				text: label,
			})
		);
		if (typeof onRemove === 'function') {
			var removeBtn = createEl('button', {
				type: 'button',
				className: 'wtt-node-render__ref-chip-remove',
				title: 'Remove',
				'aria-label': 'Remove ' + label,
				html: '&times;',
			});
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				onRemove();
			});
			chip.appendChild(removeBtn);
		}
		return chip;
	}

	function getTreeCfg() {
		return global.wttTree || global.wttTreeAdmin || {};
	}

	function nodeRefChooserPresentation() {
		var mode = String(
			(getTreeCfg().treePickerMode || 'popup')
		).toLowerCase();
		return mode === 'inline' ? 'inline' : 'popup';
	}

	function nodeRefI18n() {
		var i18n = (getTreeCfg().i18n || {});
		return {
			choose: i18n.nodePickerChoose || i18n.nodeRefChoose || 'Choose…',
			change: i18n.nodePickerChange || 'Change…',
			clear: i18n.nodePickerClear || 'Clear',
			title: i18n.nodeRefChooserTitle || 'Choose catalog entries',
			search: i18n.nodePickerSearchPlaceholder || 'Search…',
			searchEmpty: i18n.nodeRefChooserEmpty || 'No matching entries.',
			noTargets: i18n.nodeRefEmpty || 'No catalog targets',
			addNew: i18n.nodeRefAddNew || 'Add new…',
			backList: i18n.nodeRefBackList || 'Back to list',
			create: i18n.nodeRefCreate || 'Create',
			apply: i18n.nodeRefApply || i18n.apply || 'Apply',
			cancel: i18n.cancel || 'Cancel',
			nameRequired: i18n.nodeRefNameRequired || 'Name is required.',
			creating: i18n.nodeRefCreating || 'Creating…',
			createFailed: i18n.nodeRefCreateFailed || 'Could not create entry.',
		};
	}

	function nodeRefAjaxPost(action, data) {
		var cfg = getTreeCfg();
		var body = new global.URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');
		body.set('taxonomy', cfg.taxonomy || '');
		Object.keys(data || {}).forEach(function (key) {
			var val = data[key];
			if (val != null && typeof val === 'object') {
				body.set(key, JSON.stringify(val));
			} else {
				body.set(key, val == null ? '' : String(val));
			}
		});
		return fetch(cfg.ajaxUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		}).then(function (res) {
			return res.json();
		});
	}

	function normalizeNodeRefCreateFields(node) {
		var raw =
			node && Array.isArray(node.nodeRefCreateFields)
				? node.nodeRefCreateFields
				: [];
		var out = [];
		var sawName = false;
		raw.forEach(function (f) {
			if (!f) {
				return;
			}
			var key = String(f.key || f.id || '').trim();
			var name = String(f.name || '').trim();
			if (!key && !name) {
				return;
			}
			if (key === 'name' || String(f.id) === '0') {
				sawName = true;
				key = 'name';
			}
			var typeName = String(f.typeName || f.type || 'text')
				.trim()
				.toLowerCase();
			if (typeName === 'integer') {
				typeName = 'int';
			}
			if (
				key !== 'name' &&
				!SIMPLE_SCALAR_KEYS[typeName] &&
				typeName !== 'email'
			) {
				return;
			}
			out.push({
				id: f.id != null ? parseInt(f.id, 10) || 0 : 0,
				key: key || String(f.id),
				name: name || key,
				typeName: typeName || 'text',
				required: !!f.required,
				description: f.description ? String(f.description) : '',
			});
		});
		if (!sawName) {
			out.unshift({
				id: 0,
				key: 'name',
				name: 'Name',
				typeName: 'text',
				required: true,
				description: '',
			});
		}
		return out;
	}

	function emitNodeRefValue(ctx, ids) {
		if (typeof ctx.onInput !== 'function') {
			return;
		}
		var uniq = [];
		var seen = {};
		(ids || []).forEach(function (id) {
			var s = String(id).trim();
			if (!s || seen[s]) {
				return;
			}
			seen[s] = true;
			uniq.push(s);
		});
		ctx.onInput(uniq.join(','));
	}

	function renderNodeRefChooser(node, options, selectedIds, mult, ctx) {
		var multi = isMultiFieldMultiplicity(mult);
		var presentation = nodeRefChooserPresentation();
		var labels = nodeRefI18n();
		var scopeId = node && (parseInt(node.refScopeId, 10) || 0);
		var slotId = node && (parseInt(node.id, 10) || 0);
		var state = {
			options: (options || []).slice(),
			selected: (selectedIds || []).map(String),
			createFields: normalizeNodeRefCreateFields(node),
		};

		var wrap = createEl('div', {
			className:
				'wtt-node-ref-chooser wtt-node-render--node-ref is-edit' +
				(multi ? ' wtt-node-ref-chooser--multi' : '') +
				(presentation === 'inline'
					? ' wtt-node-ref-chooser--inline'
					: ' wtt-node-ref-chooser--popup'),
		});

		var trigger = createEl('div', {
			className:
				'wtt-node-picker wtt-node-picker--popup-trigger wtt-node-ref-chooser__trigger',
		});
		var chips = createEl('div', {
			className: 'wtt-node-ref-chooser__chips',
		});
		var actions = createEl('div', {
			className: 'wtt-node-picker__actions',
		});
		var openBtn = createEl('button', {
			type: 'button',
			className: 'wtt-node-picker__icon-btn wtt-node-picker__open',
			title: state.selected.length ? labels.change : labels.choose,
			'aria-label': state.selected.length
				? labels.change
				: labels.choose,
			html: '<span class="dashicons dashicons-category" aria-hidden="true"></span>',
		});
		actions.appendChild(openBtn);

		trigger.appendChild(chips);
		trigger.appendChild(actions);
		wrap.appendChild(trigger);

		var inlineHost = null;
		if (presentation === 'inline') {
			inlineHost = createEl('div', {
				className: 'wtt-node-ref-chooser__inline-host',
			});
			inlineHost.hidden = true;
			wrap.appendChild(inlineHost);
		}

		function refreshClosed() {
			while (chips.firstChild) {
				chips.removeChild(chips.firstChild);
			}
			var byId = {};
			state.options.forEach(function (o) {
				byId[String(o.id)] = o;
			});
			if (!state.selected.length) {
				chips.appendChild(
					createEl('span', {
						className:
							'wtt-node-picker__value is-empty wtt-node-ref-chooser__empty',
						text: labels.choose,
					})
				);
			} else {
				state.selected.forEach(function (id) {
					var o = byId[String(id)];
					var label = o ? o.name || o.path : String(id);
					var title = o ? o.path || o.name : String(id);
					var canRemove = multiplicityAllowsRemoveOne(
						mult,
						state.selected.length
					);
					chips.appendChild(
						createNodeRefChip(
							label,
							title,
							canRemove
								? function () {
										var next = state.selected.filter(function (sid) {
											return String(sid) !== String(id);
										});
										if (
											!multiplicityAllowsEmpty(mult) &&
											!next.length
										) {
											return;
										}
										applySelection(next, false);
								  }
								: null
						)
					);
				});
			}
			openBtn.title = state.selected.length
				? labels.change
				: labels.choose;
			openBtn.setAttribute('aria-label', openBtn.title);
		}

		function applySelection(ids, closePanel) {
			var next = (ids || []).map(String);
			if (!multi && next.length > 1) {
				next = [next[0]];
			}
			if (!multiplicityAllowsEmpty(mult) && !next.length) {
				/* Required cardinality — ignore empty clear; keep current selection. */
				refreshClosed();
				if (closePanel) {
					closeChooser();
				}
				return;
			}
			state.selected = next;
			emitNodeRefValue(ctx, state.selected);
			refreshClosed();
			if (closePanel) {
				closeChooser();
			}
		}

		function syncNodeOptions(list) {
			state.options = normalizeNodeRefOptions({
				nodeRefOptions: list || [],
			});
			if (node) {
				node.nodeRefOptions = state.options;
			}
		}

		var activeBackdrop = null;

		function closeChooser() {
			if (activeBackdrop && activeBackdrop.parentNode) {
				activeBackdrop.parentNode.removeChild(activeBackdrop);
			}
			activeBackdrop = null;
			if (inlineHost) {
				inlineHost.hidden = true;
				while (inlineHost.firstChild) {
					inlineHost.removeChild(inlineHost.firstChild);
				}
			}
		}

		function openChooser() {
			if (presentation === 'inline' && inlineHost && !inlineHost.hidden) {
				closeChooser();
				return;
			}
			closeChooser();
			var panel = buildChooserPanel({
				state: state,
				multi: multi,
				labels: labels,
				scopeId: scopeId,
				slotId: slotId,
				createFields: state.createFields,
				onApply: function (ids) {
					applySelection(ids, true);
				},
				onCancel: closeChooser,
				onCreated: function (option, list) {
					if (list && list.length) {
						syncNodeOptions(list);
					} else if (option) {
						var exists = state.options.some(function (o) {
							return String(o.id) === String(option.id);
						});
						if (!exists) {
							state.options.push(option);
						}
						if (node) {
							node.nodeRefOptions = state.options;
						}
					}
					var newId = option && option.id != null ? String(option.id) : '';
					if (!newId) {
						return;
					}
					var next = multi
						? state.selected.concat([newId])
						: [newId];
					applySelection(next, false);
				},
			});

			if (presentation === 'inline' && inlineHost) {
				inlineHost.appendChild(panel);
				inlineHost.hidden = false;
				return;
			}

			var backdrop = createEl('div', {
				className: 'wtt-dialog-backdrop',
			});
			var dialog = createEl('div', {
				className: 'wtt-dialog wtt-dialog--node-picker',
				role: 'dialog',
			});
			dialog.appendChild(
				createEl('h2', {
					text: labels.title,
				})
			);
			dialog.appendChild(panel);
			backdrop.appendChild(dialog);
			backdrop.addEventListener('click', function (e) {
				if (e.target === backdrop) {
					closeChooser();
				}
			});
			document.body.appendChild(backdrop);
			activeBackdrop = backdrop;
		}

		openBtn.addEventListener('click', function (e) {
			e.preventDefault();
			openChooser();
		});

		refreshClosed();
		return wrap;
	}

	function buildChooserPanel(opts) {
		var state = opts.state;
		var multi = !!opts.multi;
		var labels = opts.labels;
		var provisional = (state.selected || []).map(String);
		var view = 'list';
		var repaintList = null;
		var searchQuery = '';

		var root = createEl('div', {
			className: 'wtt-node-ref-chooser-panel',
		});
		var body = createEl('div', {
			className: 'wtt-node-ref-chooser-panel__body',
		});
		var footer = createEl('div', {
			className: 'wtt-dialog__actions wtt-node-ref-chooser-panel__footer',
		});
		root.appendChild(body);
		root.appendChild(footer);

		function setProvisional(ids) {
			provisional = (ids || []).map(String);
			if (!multi && provisional.length > 1) {
				provisional = [provisional[0]];
			}
			/* In-place refresh only — full render() was resetting provisional to state.selected. */
			if (view === 'list' && typeof repaintList === 'function') {
				repaintList();
				return;
			}
			render();
		}

		function render() {
			while (body.firstChild) {
				body.removeChild(body.firstChild);
			}
			while (footer.firstChild) {
				footer.removeChild(footer.firstChild);
			}
			if (view === 'create') {
				repaintList = null;
				renderCreateView();
			} else {
				renderListView();
			}
		}

		function renderListView() {
			var searchWrap = createEl('div', {
				className: 'wtt-node-picker__search',
			});
			var search = createEl('input', {
				type: 'search',
				className:
					'wtt-node-picker__search-input wtt-node-ref-chooser__search',
				placeholder: labels.search,
				value: searchQuery,
			});
			search.value = searchQuery;
			searchWrap.appendChild(search);
			var listHost = createEl('div', {
				className: 'wtt-node-ref-chooser__list',
			});

			function paintList() {
				while (listHost.firstChild) {
					listHost.removeChild(listHost.firstChild);
				}
				var q = String(search.value || '')
					.trim()
					.toLowerCase();
				searchQuery = String(search.value || '');
				var matched = state.options.filter(function (o) {
					if (!q) {
						return true;
					}
					var hay = (
						(o.name || '') +
						' ' +
						(o.path || '') +
						' ' +
						(o.shortDescription || '')
					).toLowerCase();
					return hay.indexOf(q) !== -1;
				});
				if (!matched.length) {
					listHost.appendChild(
						createEl('p', {
							className: 'wtt-node-ref-chooser__empty-hint',
							text: state.options.length
								? labels.searchEmpty
								: labels.noTargets,
						})
					);
					return;
				}
				var table = createEl('table', {
					className: 'wtt-node-ref-chooser__table',
				});
				var tbody = createEl('tbody');
				matched.forEach(function (opt) {
					var id = String(opt.id);
					var picked = provisional.indexOf(id) !== -1;
					var tr = createEl('tr', {
						className:
							'wtt-node-ref-chooser__row' +
							(picked ? ' is-picked' : ''),
					});
					var tdCheck = createEl('td', {
						className: 'wtt-node-ref-chooser__cell-check',
					});
					if (multi) {
						var cb = createEl('input', {
							type: 'checkbox',
							value: id,
						});
						cb.checked = picked;
						cb.addEventListener('click', function (e) {
							e.stopPropagation();
						});
						cb.addEventListener('change', function () {
							var next = provisional.slice();
							var idx = next.indexOf(id);
							if (cb.checked && idx === -1) {
								next.push(id);
							} else if (!cb.checked && idx !== -1) {
								next.splice(idx, 1);
							}
							setProvisional(next);
						});
						tdCheck.appendChild(cb);
					} else {
						var radio = createEl('input', {
							type: 'radio',
							name: 'wtt-node-ref-pick',
							value: id,
						});
						radio.checked = picked;
						radio.addEventListener('click', function (e) {
							e.stopPropagation();
						});
						radio.addEventListener('change', function () {
							if (radio.checked) {
								opts.onApply([id]);
							}
						});
						tdCheck.appendChild(radio);
					}
					var tdName = createEl('td', {
						className: 'wtt-node-ref-chooser__cell-name',
					});
					tdName.appendChild(
						createEl('span', {
							className: 'wtt-node-ref-chooser__name',
							text: opt.name || id,
						})
					);
					if (opt.path && opt.path !== opt.name) {
						tdName.appendChild(
							createEl('span', {
								className: 'wtt-node-ref-chooser__path',
								text: opt.path,
							})
						);
					}
					tr.appendChild(tdCheck);
					tr.appendChild(tdName);
					tr.addEventListener('click', function (e) {
						if (
							e.target &&
							(e.target.tagName === 'INPUT' ||
								(e.target.closest &&
									e.target.closest('input')))
						) {
							return;
						}
						if (multi) {
							var next = provisional.slice();
							var idx = next.indexOf(id);
							if (idx === -1) {
								next.push(id);
							} else {
								next.splice(idx, 1);
							}
							setProvisional(next);
						} else {
							opts.onApply([id]);
						}
					});
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				listHost.appendChild(table);
			}

			repaintList = paintList;
			search.addEventListener('input', paintList);
			body.appendChild(searchWrap);
			body.appendChild(listHost);
			paintList();

			if (opts.scopeId) {
				var addBtn = createEl('button', {
					type: 'button',
					className: 'button',
					text: labels.addNew,
				});
				addBtn.addEventListener('click', function (e) {
					e.preventDefault();
					view = 'create';
					render();
				});
				footer.appendChild(addBtn);
			}

			if (multi) {
				var applyBtn = createEl('button', {
					type: 'button',
					className: 'button button-primary',
					text: labels.apply,
				});
				applyBtn.addEventListener('click', function (e) {
					e.preventDefault();
					opts.onApply(provisional.slice());
				});
				footer.appendChild(applyBtn);
			}

			var cancelBtn = createEl('button', {
				type: 'button',
				className: 'button',
				text: labels.cancel,
			});
			cancelBtn.addEventListener('click', function (e) {
				e.preventDefault();
				opts.onCancel();
			});
			footer.appendChild(cancelBtn);
		}

		function renderCreateView() {
			var form = createEl('div', {
				className: 'wtt-node-ref-chooser__create',
			});
			var status = createEl('p', {
				className: 'wtt-node-ref-chooser__status',
			});
			status.hidden = true;
			var inputs = {};

			(opts.createFields || []).forEach(function (field) {
				var row = createEl('div', {
					className: 'wtt-node-ref-chooser__field',
				});
				var lab = createEl('label', {
					className: 'wtt-node-ref-chooser__field-label',
					text:
						field.name +
						(field.required ? ' *' : ''),
				});
				var control;
				var typeName = field.typeName || 'text';
				if (typeName === 'textarea') {
					control = createEl('textarea', {
						className: 'wtt-node-ref-chooser__input',
						rows: '3',
					});
				} else if (typeName === 'bool') {
					control = createEl('input', {
						type: 'checkbox',
						className: 'wtt-node-ref-chooser__input',
						value: '1',
					});
				} else {
					var inputType = 'text';
					var inputMode = '';
					if (typeName === 'int') {
						inputMode = 'numeric';
					} else if (typeName === 'double') {
						inputMode = 'decimal';
					} else if (typeName === 'email') {
						/* text + inputmode: caret survives; still email keyboard on mobile */
						inputMode = 'email';
					}
					control = createEl('input', {
						type: inputType,
						className: 'wtt-node-ref-chooser__input',
						inputmode: inputMode || undefined,
						autocomplete: typeName === 'email' ? 'email' : undefined,
					});
				}
				if (field.description) {
					control.title = field.description;
				}
				inputs[field.key] = { field: field, control: control };
				row.appendChild(lab);
				row.appendChild(control);
				form.appendChild(row);
			});

			body.appendChild(form);
			body.appendChild(status);

			var backBtn = createEl('button', {
				type: 'button',
				className: 'button',
				text: labels.backList,
			});
			backBtn.addEventListener('click', function (e) {
				e.preventDefault();
				view = 'list';
				render();
			});
			footer.appendChild(backBtn);

			var createBtn = createEl('button', {
				type: 'button',
				className: 'button button-primary',
				text: labels.create,
			});
			createBtn.addEventListener('click', function (e) {
				e.preventDefault();
				var nameVal = '';
				var fieldsPayload = {};
				Object.keys(inputs).forEach(function (key) {
					var entry = inputs[key];
					var field = entry.field;
					var control = entry.control;
					var val = '';
					if (field.typeName === 'bool') {
						val = control.checked ? '1' : '0';
					} else {
						val = String(control.value || '').trim();
					}
					if (key === 'name') {
						nameVal = val;
					} else {
						fieldsPayload[key] = val;
					}
				});
				if (!nameVal) {
					status.hidden = false;
					status.textContent = labels.nameRequired;
					status.className =
						'wtt-node-ref-chooser__status is-error';
					return;
				}
				createBtn.disabled = true;
				status.hidden = false;
				status.className = 'wtt-node-ref-chooser__status';
				status.textContent = labels.creating;

				nodeRefAjaxPost('wtt_create_node_ref_target', {
					ref_scope: opts.scopeId || 0,
					slot_id: opts.slotId || 0,
					name: nameVal,
					fields: fieldsPayload,
				})
					.then(function (json) {
						createBtn.disabled = false;
						if (!json || !json.success) {
							status.className =
								'wtt-node-ref-chooser__status is-error';
							status.textContent =
								(json &&
									json.data &&
									(json.data.message || json.data)) ||
								labels.createFailed;
							return;
						}
						var data = json.data || {};
						var option = data.option || null;
						var list = data.nodeRefOptions || [];
						if (typeof opts.onCreated === 'function') {
							opts.onCreated(option, list);
						}
						provisional = (state.selected || []).map(String);
						view = 'list';
						render();
					})
					.catch(function () {
						createBtn.disabled = false;
						status.className =
							'wtt-node-ref-chooser__status is-error';
						status.textContent = labels.createFailed;
					});
			});
			footer.appendChild(createBtn);

			var cancelBtn = createEl('button', {
				type: 'button',
				className: 'button',
				text: labels.cancel,
			});
			cancelBtn.addEventListener('click', function (e) {
				e.preventDefault();
				opts.onCancel();
			});
			footer.appendChild(cancelBtn);
		}

		render();
		return root;
	}

	function normalizeEnumOptions(node) {
		var raw = [];
		if (node && Array.isArray(node.enumOptions)) {
			raw = node.enumOptions;
		} else if (node && Array.isArray(node.directChildren)) {
			raw = node.directChildren;
		}
		var out = [];
		raw.forEach(function (o) {
			if (!o) {
				return;
			}
			var name = String(o.name || o.label || '').trim();
			if (!name) {
				return;
			}
			/* Skip the Option/column wrapper if it slipped into the list. */
			if (/^(option|spalte|column|wert)$/i.test(name) && !o.id) {
				return;
			}
			out.push({
				id: o.id != null ? o.id : name,
				name: name,
			});
		});
		return out;
	}

	function resolveEnumSelection(options, raw) {
		if (!options.length) {
			return null;
		}
		var needle = String(raw || '').trim();
		if (!needle) {
			return options[0];
		}
		for (var i = 0; i < options.length; i++) {
			if (
				String(options[i].id) === needle ||
				String(options[i].name) === needle
			) {
				return options[i];
			}
		}
		return options[0];
	}

	function normalizeNodeRefOptions(node) {
		var raw = node && Array.isArray(node.nodeRefOptions) ? node.nodeRefOptions : [];
		var out = [];
		raw.forEach(function (o) {
			if (!o) {
				return;
			}
			var id = o.id != null ? parseInt(o.id, 10) || 0 : 0;
			var name = String(o.name || o.label || '').trim();
			if (!id && !name) {
				return;
			}
			out.push({
				id: id || name,
				name: name || String(id),
				path: String(o.path || name || id),
				shortDescription: o.shortDescription
					? String(o.shortDescription)
					: '',
			});
		});
		return out;
	}

	function isMultiFieldMultiplicity(mult) {
		var m = String(mult || '0..1');
		return m === '0..*' || m === '1..*';
	}

	/**
	 * Optional cardinalities may clear to empty. Required `1` / `1..*` may only swap / keep ≥1.
	 */
	function multiplicityAllowsEmpty(mult) {
		var m = String(mult || '1');
		return m === '0..1' || m === '0..*';
	}

	/**
	 * Whether removing one selected id is allowed (chip × / uncheck).
	 */
	function multiplicityAllowsRemoveOne(mult, selectedCount) {
		selectedCount = parseInt(selectedCount, 10) || 0;
		if (multiplicityAllowsEmpty(mult)) {
			return selectedCount > 0;
		}
		/* Required: keep at least one — swap via picker, never delete last. */
		return selectedCount > 1;
	}

	function parseRefValueIds(raw) {
		var s = String(raw || '').trim();
		if (!s) {
			return [];
		}
		/* JSON array of ids. */
		if (s.charAt(0) === '[') {
			try {
				var parsed = JSON.parse(s);
				if (Array.isArray(parsed)) {
					return parsed
						.map(function (v) {
							return String(v).trim();
						})
						.filter(Boolean);
				}
			} catch (err) {
				/* fall through */
			}
		}
		return s
			.split(/[,;|]/)
			.map(function (p) {
				return p.trim();
			})
			.filter(Boolean);
	}

	/**
	 * Table collection type — skeleton HTML table.
	 * Only meaningful in context `table` (tree/form return false).
	 * Preview data from getExampleNode(): Kopf + ≥3 Zeilen + Fuss (sum/avg on doubles).
	 */
	/**
	 * PARKED (Q90): catalog `table` Collection kind — legacy scaffold only.
	 * Attribute-host Form/Table surfaces use WTTObjectRender (not this renderer).
	 */
	var TableRenderer = {
		id: 'table',
		label: 'Table',
		canRender: function (node, context) {
			if (resolveTypeKey(node) !== 'table') {
				return false;
			}
			var ctx = contextName(context);
			/* Preview chrome may call without context; allow, renderContent filters. */
			return !ctx || ctx === 'table';
		},
		/**
		 * Example table: Kopf + 3 body rows + Fuss.
		 * Two double columns: sum and avg over the sample rows.
		 * @return {object}
		 */
		getExampleNode: function () {
			/*
			 * Two doubles so Fuss can show Sum and Average on double values.
			 * Other simples stay for type-catalog variety.
			 */
			var specs = [
				{ key: 'text', name: 'Label', samples: ['A', 'B', 'C'] },
				{
					key: 'email',
					name: 'Email_name',
					samples: [
						'a@example.com',
						'b@example.com',
						'c@example.com',
					],
				},
				{
					key: 'double',
					name: 'Double_sum',
					samples: ['10.5', '20', '5.5'],
					footerOp: 'sum',
				},
				{
					key: 'double',
					name: 'Double_avg',
					samples: ['3', '6', '9'],
					footerOp: 'avg',
				},
				{ key: 'int', name: 'Int_name', samples: ['2', '4', '6'], footerOp: 'sum' },
				{
					key: 'textarea',
					name: 'Textarea_name',
					samples: [
						'Sample text\nSecond line',
						'Note two\nExtra detail',
						'Note three\nMore text',
					],
				},
				{ key: 'char', name: 'Char_name', samples: ['X', 'Y', 'Z'] },
				{
					key: 'bool',
					name: 'Bool_name',
					samples: ['true', 'false', 'true'],
				},
			];

			var zeile = specs.map(function (spec) {
				var piece = null;
				renderers.forEach(function (r) {
					if (
						!piece &&
						r &&
						typeof r.getExampleNode === 'function' &&
						typeof r.canRender === 'function' &&
						r.canRender({
							typeKey: spec.key,
							type: { name: spec.key },
						})
					) {
						piece = r.getExampleNode();
					}
				});
				if (!piece) {
					piece = makeExampleScalarNode(
						spec.key,
						spec.samples[0] != null ? spec.samples[0] : '…'
					);
				}
				return Object.assign({}, piece, {
					name: spec.name,
					displayName: spec.name,
					typeKey: spec.key,
					type: { name: spec.key },
					sample: String(spec.samples[0] != null ? spec.samples[0] : ''),
					isExample: true,
					band: 'zeile',
				});
			});

			var kopf = specs.map(function (spec) {
				return {
					name: spec.name,
					displayName: spec.name,
					typeKey: 'text',
					type: { name: 'text' },
					sample: spec.name,
					isExample: true,
					band: 'kopf',
				};
			});

			var sampleRows = [0, 1, 2].map(function (rowIndex) {
				return specs.map(function (spec, colIndex) {
					var val =
						spec.samples[rowIndex] != null
							? String(spec.samples[rowIndex])
							: '';
					return Object.assign({}, zeile[colIndex], {
						sample: val,
						isExample: true,
					});
				});
			});

			var fuss = specs.map(function (spec, index) {
				var typeKey = spec.key;
				var opKey = spec.footerOp || 'text';
				if (index === 0 && !spec.footerOp) {
					opKey = 'text';
				}
				var op = normalizeFooterOp(opKey, typeKey);
				var colValues = sampleRows.map(function (row) {
					return row[index] && row[index].sample != null
						? String(row[index].sample)
						: '';
				});
				var footerSample =
					op.key === 'text' || op.key === 'none'
						? index === 0
							? 'Summe'
							: op.symbol
						: computeFooterSample(op.key, colValues);
				return {
					name: spec.name,
					displayName: spec.name,
					typeKey: isNumericTypeKey(typeKey) ? typeKey : 'text',
					type: {
						name: isNumericTypeKey(typeKey) ? typeKey : 'text',
					},
					footerOp: op.key,
					sample: footerSample,
					isExample: true,
					band: 'fuss',
				};
			});

			return {
				name: 'Table_example',
				displayName: 'Table_example',
				typeKey: 'table',
				type: { name: 'table' },
				isTable: true,
				hasFooter: true,
				isExample: true,
				bands: {
					kopf: kopf,
					zeile: zeile,
					fuss: fuss,
				},
				sampleRows: sampleRows,
				/* Zeile fields also as setMembers for composition-aware callers. */
				setMembers: zeile.slice(),
			};
		},
		renderLabel: function (node, context) {
			if (resolveTypeKey(node) !== 'table') {
				return false;
			}
			var text = fieldCaption(node) || 'table';
			return createEl('span', {
				className: 'wtt-node-render__label',
				text: text,
			});
		},
		/**
		 * Sample table: Kopf + ≥1 body rows (+ optional Fuss).
		 * Columns from node.bands / context.columns, else A/B/C placeholders.
		 */
		renderContent: function (node, context, readonly) {
			if (resolveTypeKey(node) !== 'table') {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			if (contextName(ctx) !== 'table') {
				return false;
			}
			return renderTableSkeleton(node, ctx);
		},
		render: function (node, context) {
			if (!this.canRender(node, context)) {
				return false;
			}
			if (contextName(context) !== 'table') {
				return false;
			}
			var content = this.renderContent(node, context, !isEdit(context));
			if (content === false || content == null) {
				return false;
			}
			var wrap = createEl('div', {
				className:
					'wtt-node-render wtt-node-render--table-type' +
					(isEdit(context) ? ' is-edit' : ' is-display'),
			});
			wrap.appendChild(content);
			return wrap;
		},
	};

	/**
	 * Apply a footer aggregate to column sample strings (preview demo).
	 */
	function computeFooterSample(opKey, values) {
		var op = normalizeFooterOp(opKey, 'double');
		var nums = [];
		(values || []).forEach(function (v) {
			var n = parseFloat(String(v).replace(',', '.'));
			if (!isNaN(n) && isFinite(n)) {
				nums.push(n);
			}
		});
		if (op.key === 'count') {
			return String((values || []).length);
		}
		if (!nums.length) {
			return op.symbol;
		}
		var result = nums[0];
		if (op.key === 'sum') {
			result = nums.reduce(function (a, b) {
				return a + b;
			}, 0);
		} else if (op.key === 'avg') {
			result =
				nums.reduce(function (a, b) {
					return a + b;
				}, 0) / nums.length;
		} else if (op.key === 'min') {
			result = Math.min.apply(null, nums);
		} else if (op.key === 'max') {
			result = Math.max.apply(null, nums);
		} else {
			return op.symbol;
		}
		/* Trim trailing zeros for doubles (10.5 + 20 + 5.5 → 36). */
		var rounded = Math.round(result * 1000) / 1000;
		return String(rounded);
	}

	function defaultTableColumns() {
		var prefix = i18nLabels.previewColGeneric || 'Column';
		return [
			{ name: prefix + ' A', typeKey: 'text', sample: '…', footerOp: 'text' },
			{ name: prefix + ' B', typeKey: 'text', sample: '…', footerOp: 'text' },
			{ name: prefix + ' C', typeKey: 'text', sample: '…', footerOp: 'text' },
		];
	}

	/**
	 * Prefer live/example bands (Kopf / Zeile / Fuss), then context.columns.
	 * Zeile cells should already be type example DTOs when built from bindings.
	 */
	function resolveTableBands(node, context) {
		var bands =
			(node && node.bands) ||
			(context && context.bands) ||
			null;
		if (bands && Array.isArray(bands.zeile) && bands.zeile.length) {
			return {
				kopf: Array.isArray(bands.kopf) ? bands.kopf : [],
				zeile: bands.zeile,
				fuss: Array.isArray(bands.fuss) ? bands.fuss : [],
			};
		}
		return null;
	}

	function normalizeTableColumns(node, context) {
		var bands = resolveTableBands(node, context);
		if (bands) {
			return bands.zeile.map(function (col, i) {
				var name =
					(col && (col.name || col.displayName)) ||
					'Column ' + String(i + 1);
				var typeKey = col ? resolveTypeKey(col) || col.typeKey : '';
				var foot =
					bands.fuss[i] ||
					normalizeFooterOp(
						isNumericTypeKey(typeKey) ? 'sum' : 'text',
						typeKey
					);
				var footOp = normalizeFooterOp(
					foot.footerOp || foot.key || 'text',
					typeKey
				);
				var head = bands.kopf[i];
				return {
					name: String(name),
					headerLabel: head
						? String(head.name || head.displayName || head.sample || name)
						: String(name),
					typeKey: String(typeKey || 'text'),
					sample:
						col && col.sample != null
							? String(col.sample)
							: '…',
					footerOp: footOp.key,
					footerSample:
						foot.sample != null
							? String(foot.sample)
							: footOp.symbol,
					source: col,
				};
			});
		}
		var raw =
			(context && context.columns) ||
			(context && context.members) ||
			(node && node.setMembers) ||
			null;
		if (!Array.isArray(raw) || !raw.length) {
			return defaultTableColumns();
		}
		return raw.map(function (col, i) {
			var name =
				(col && (col.name || col.displayName)) ||
				'Column ' + String(i + 1);
			var typeKey = '';
			if (col && col.typeKey) {
				typeKey = String(col.typeKey);
			} else if (col) {
				typeKey = resolveTypeKey(col);
			}
			typeKey = typeKey || 'text';
			var footOp = normalizeFooterOp(
				isNumericTypeKey(typeKey) ? 'sum' : 'text',
				typeKey
			);
			return {
				name: String(name),
				headerLabel: String(name),
				typeKey: typeKey,
				sample:
					col && col.sample != null
						? String(col.sample)
						: '…',
				footerOp: footOp.key,
				footerSample: footOp.symbol,
				source: col,
			};
		});
	}

	/**
	 * Body rows for preview: node.sampleRows (≥1), else one row from column samples.
	 *
	 * @return {list<list<object>>}
	 */
	function resolveTableSampleRows(node, context, columns) {
		var raw =
			(node && node.sampleRows) ||
			(context && context.sampleRows) ||
			null;
		if (Array.isArray(raw) && raw.length) {
			return raw.map(function (row) {
				if (!Array.isArray(row)) {
					return columns.map(function (col) {
						return Object.assign({}, col.source || col, {
							sample: col.sample,
						});
					});
				}
				return columns.map(function (col, i) {
					var cell = row[i];
					if (cell && typeof cell === 'object') {
						return cell;
					}
					return Object.assign({}, col.source || col, {
						sample: cell != null ? String(cell) : col.sample,
					});
				});
			});
		}
		return [
			columns.map(function (col) {
				return Object.assign({}, col.source || col, {
					sample: col.sample,
				});
			}),
		];
	}

	function renderTableSkeleton(node, context) {
		var columns = normalizeTableColumns(node, context);
		var edit = isEdit(context);
		var hasFooter =
			!!(
				(context && context.hasFooter === true) ||
				(node && node.hasFooter === true) ||
				(node &&
					node.bands &&
					Array.isArray(node.bands.fuss) &&
					node.bands.fuss.length)
			);
		var hasKopf =
			!!(
				node &&
				node.bands &&
				Array.isArray(node.bands.kopf) &&
				node.bands.kopf.length
			);
		var sampleRows = resolveTableSampleRows(node, context, columns);
		var wrap = createEl('div', {
			className: 'wtt-node-render__table-wrap',
		});
		var table = createEl('table', {
			className: 'wtt-node-render__table wtt-set-preview__table',
		});

		if (hasKopf || columns.some(function (c) {
			return c.headerLabel;
		})) {
			var thead = createEl('thead');
			var headRow = createEl('tr');
			columns.forEach(function (col) {
				headRow.appendChild(
					createEl('th', {
						text: col.headerLabel || col.name,
						scope: 'col',
					})
				);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);
		}

		var tbody = createEl('tbody');
		sampleRows.forEach(function (row, rowIndex) {
			var bodyRow = createEl('tr');
			columns.forEach(function (col, index) {
				var td = createEl('td', {
					className: 'wtt-node-render__table-cell',
				});
				var cellNode =
					row[index] && typeof row[index] === 'object'
						? row[index]
						: col.source && typeof col.source === 'object'
							? Object.assign({}, col.source, {
									sample:
										row[index] != null
											? String(row[index])
											: col.sample,
							  })
							: {
									name: col.name,
									displayName: col.name,
									typeKey: col.typeKey,
									type: { name: col.typeKey },
									sample:
										row[index] != null
											? String(row[index])
											: col.sample,
							  };
				var cellSample =
					cellNode.sample != null ? String(cellNode.sample) : col.sample;
				/* Ensure sample row cells paint via field-type example (Int_name, …). */
				if (!cellNode.isExample) {
					var example = Registry.getExampleNode(
						resolveTypeKey(cellNode) || col.typeKey || 'text'
					);
					if (example) {
						cellNode = Object.assign({}, example, cellNode, {
							sample: cellSample,
							isExample: true,
						});
					}
				} else {
					cellNode = Object.assign({}, cellNode, { sample: cellSample });
				}
				var cellCtx = Object.assign({}, context, {
					name: 'table',
					bare: true,
					value: cellSample,
					valueKey:
						(context.valueKey || 'table') +
						'|r' +
						String(rowIndex) +
						'|c' +
						String(index),
					onInput: edit ? function () {} : null,
				});
				var cell =
					Registry.renderContent(cellNode, cellCtx, !edit) ||
					createEl('span', {
						className: 'wtt-preview-display-value',
						text: cellSample,
					});
				td.appendChild(cell);
				bodyRow.appendChild(td);
			});
			tbody.appendChild(bodyRow);
		});
		table.appendChild(tbody);

		if (hasFooter) {
			var tfoot = createEl('tfoot');
			var footRow = createEl('tr');
			columns.forEach(function (col, colIndex) {
				var op = normalizeFooterOp(col.footerOp, col.typeKey);
				var label =
					col.footerSample != null && String(col.footerSample) !== ''
						? String(col.footerSample)
						: op.symbol;
				/* If still a bare symbol and we have sample rows, compute. */
				if (
					(label === op.symbol || label === '') &&
					op.numeric &&
					sampleRows.length
				) {
					var vals = sampleRows.map(function (row) {
						var cell = row[colIndex];
						if (cell && typeof cell === 'object' && cell.sample != null) {
							return String(cell.sample);
						}
						return cell != null ? String(cell) : '';
					});
					label = computeFooterSample(op.key, vals);
				}
				footRow.appendChild(
					createEl('td', {
						className:
							'wtt-node-render__table-footer' +
							' wtt-node-render__table-footer--' +
							op.key,
						text: label,
						title: op.label + ' (' + op.key + ')',
					})
				);
			});
			tfoot.appendChild(footRow);
			table.appendChild(tfoot);
		}

		wrap.appendChild(table);
		return wrap;
	}

	Registry.register(TableRenderer);

	function isSimpleScalarType(key) {
		return !!SIMPLE_SCALAR_KEYS[String(key || '').toLowerCase()];
	}

	function isStructuredType(key) {
		return !!STRUCTURED_TYPE_KEYS[String(key || '').toLowerCase()];
	}

	function isRegisteredType(key) {
		return isSimpleScalarType(key) || isStructuredType(key);
	}

	function configure(opts) {
		opts = opts || {};
		if (opts.catalogBindings && typeof opts.catalogBindings === 'object') {
			catalogBindingsMap = opts.catalogBindings;
		}
		if (typeof opts.resolveTypeKey === 'function') {
			resolveTypeKeyFn = opts.resolveTypeKey;
		}
		if (opts.i18n && typeof opts.i18n === 'object') {
			if (opts.i18n.boolTrue) {
				i18nLabels.boolTrue = String(opts.i18n.boolTrue);
			}
			if (opts.i18n.boolFalse) {
				i18nLabels.boolFalse = String(opts.i18n.boolFalse);
			}
			if (opts.i18n.previewFooter) {
				i18nLabels.previewFooter = String(opts.i18n.previewFooter);
			}
			if (opts.i18n.previewColGeneric) {
				i18nLabels.previewColGeneric = String(opts.i18n.previewColGeneric);
			}
			if (opts.i18n.emailInvalid) {
				i18nLabels.emailInvalid = String(opts.i18n.emailInvalid);
			}
			if (opts.i18n.intInvalid) {
				i18nLabels.intInvalid = String(opts.i18n.intInvalid);
			}
		}
		if (global.WTTIntValue && typeof global.WTTIntValue.configure === 'function') {
			global.WTTIntValue.configure({
				i18n: {
					intInvalid: i18nLabels.intInvalid,
				},
			});
		}
	}

	global.WTTNodeRender = {
		configure: configure,
		resolveTypeKey: resolveTypeKey,
		isSimpleScalarType: isSimpleScalarType,
		isStructuredType: isStructuredType,
		isRegisteredType: isRegisteredType,
		isTruthyBool: isTruthyBool,
		formatBoolLabel: function (value) {
			return isTruthyBool(value)
				? i18nLabels.boolTrue || 'true'
				: i18nLabels.boolFalse || 'false';
		},
		exampleFieldName: exampleFieldName,
		getExampleNode: function (nodeOrTypeKey) {
			return Registry.getExampleNode(nodeOrTypeKey);
		},
		FOOTER_OPS: FOOTER_OPS,
		normalizeFooterOp: normalizeFooterOp,
		footerOpList: footerOpList,
		SIMPLE_SCALAR_KEYS: SIMPLE_SCALAR_KEYS,
		STRUCTURED_TYPE_KEYS: STRUCTURED_TYPE_KEYS,
		isMultiFieldMultiplicity: isMultiFieldMultiplicity,
		multiplicityAllowsEmpty: multiplicityAllowsEmpty,
		multiplicityAllowsRemoveOne: multiplicityAllowsRemoveOne,
		Registry: Registry,
		paintIcon: paintIcon,
		defaultRenderTreeNode: defaultRenderTreeNode,
		IntRenderer: IntRenderer,
		CharRenderer: CharRenderer,
		DoubleRenderer: DoubleRenderer,
		TextRenderer: TextRenderer,
		EmailRenderer: EmailRenderer,
		TextareaRenderer: TextareaRenderer,
		BoolRenderer: BoolRenderer,
		DisplayNodeNameRenderer: DisplayNodeNameRenderer,
		NodePresentationRenderer: NodePresentationRenderer,
		DateRenderer: DateRenderer,
		MediaRenderer: MediaRenderer,
		QuantityRenderer: QuantityRenderer,
		UnitRenderer: UnitRenderer,
		TableRenderer: TableRenderer,
		EnumRenderer: EnumRenderer,
		NodeRefRenderer: NodeRefRenderer,
		isValidEmail: isValidEmail,
		compositionMembers: compositionMembers,
		quantitySchemaMembers: quantitySchemaMembers,
		createEl: createEl,
		fieldCaption: fieldCaption,
	};
})(typeof window !== 'undefined' ? window : this);
