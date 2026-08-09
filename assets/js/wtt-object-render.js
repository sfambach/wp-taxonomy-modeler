/**
 * Object presentation surfaces — Form(1) + Table(n) + Compact(1, H|V strip).
 *
 * Not the parked Collection catalog type `table` (Q90). These are presentation
 * surfaces over a node schema + filled attribute values. Field cells go through
 * WTTNodeRender.Registry by typeKey.
 *
 * Example instances are host-agnostic: WTTSampleData / Sample_Data by name then
 * type, with optional variantIndex for multi-row table samples.
 *
 * Preferred surface for page view is intentionally not stored here — later block
 * (or node) setting may choose Form / Table / Compact.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	function createEl(tag, props, children) {
		var el = document.createElement(tag);
		props = props || {};
		Object.keys(props).forEach(function (key) {
			var val = props[key];
			if (val == null || val === false) {
				return;
			}
			if (key === 'text') {
				el.textContent = String(val);
				return;
			}
			if (key === 'html') {
				el.innerHTML = String(val);
				return;
			}
			if (key === 'className') {
				el.className = String(val);
				return;
			}
			if (key.slice(0, 2) === 'on' && typeof val === 'function') {
				el.addEventListener(key.slice(2).toLowerCase(), val);
				return;
			}
			if (key === 'disabled') {
				el.disabled = !!val;
				return;
			}
			el.setAttribute(key, String(val));
		});
		(children || []).forEach(function (child) {
			if (child == null || child === false) {
				return;
			}
			if (typeof child === 'string' || typeof child === 'number') {
				el.appendChild(document.createTextNode(String(child)));
				return;
			}
			el.appendChild(child);
		});
		return el;
	}

	function registry() {
		return global.WTTNodeRender && global.WTTNodeRender.Registry
			? global.WTTNodeRender.Registry
			: null;
	}

	function sampleApi() {
		return global.WTTSampleData || null;
	}

	function fixedDisplayValue(attr) {
		if (!attr) {
			return '';
		}
		if (attr.fixedLabel != null && String(attr.fixedLabel).trim() !== '') {
			return String(attr.fixedLabel).trim();
		}
		if (Array.isArray(attr.fixedValues) && attr.fixedValues.length) {
			return attr.fixedValues
				.map(function (v) {
					/* Q106 nested default maps — skip object rows in scalar label. */
					if (v != null && typeof v === 'object') {
						return '';
					}
					return String(v);
				})
				.filter(Boolean)
				.join(', ');
		}
		return '';
	}

	/**
	 * Normalize schema attribute rows for field painting.
	 * @param {Array} attributes
	 * @return {Array}
	 */
	function normalizeAttributes(attributes) {
		var Sample = sampleApi();
		var out = [];
		(attributes || []).forEach(function (attr) {
			if (!attr || attr.hidden) {
				return;
			}
			var typeKey = String(
				attr.typeKey || attr.typeName || attr.typeLabel || 'text'
			)
				.trim()
				.toLowerCase();
			if (typeKey.indexOf('/') !== -1) {
				var parts = typeKey.split('/');
				typeKey = String(parts[parts.length - 1] || '')
					.trim()
					.toLowerCase();
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
			var fest = fixedDisplayValue(attr);
			var field = {
				id: attr.id,
				name: attr.name || '',
				displayName: attr.name || '',
				description: attr.description || '',
				shortDescription:
					attr.shortDescription != null ? String(attr.shortDescription) : '',
				typeKey: typeKey,
				typeName: typeKey,
				type: { name: typeKey },
				typeId: parseInt(attr.typeId, 10) || 0,
				fixedRootId:
					parseInt(attr.fixedRootId, 10) ||
					parseInt(attr.typeId, 10) ||
					0,
				multiplicity: String(attr.multiplicity || '1'),
				fieldMultiplicity: String(
					attr.fieldMultiplicity || attr.multiplicity || '1'
				),
				allowsMany: !!attr.allowsMany,
				allowsEmpty:
					attr.allowsEmpty != null
						? !!attr.allowsEmpty
						: String(attr.multiplicity || '1') === '0..1' ||
						  String(attr.multiplicity || '1') === '0..*',
				readonly: !!attr.readonly,
				fixedLabel: fest,
				fixedValues: Array.isArray(attr.fixedValues)
					? attr.fixedValues.slice()
					: [],
				fixedMode: attr.fixedMode || '',
				fixedOptions: Array.isArray(attr.fixedOptions)
					? attr.fixedOptions.slice()
					: [],
				choiceDepth:
					attr.choiceDepth != null ? parseInt(attr.choiceDepth, 10) || 0 : 0,
				refScopeId: attr.refScopeId != null ? parseInt(attr.refScopeId, 10) || 0 : 0,
				nodeRefOptions: Array.isArray(attr.nodeRefOptions)
					? attr.nodeRefOptions.slice()
					: [],
				nodeRefCreateFields: Array.isArray(attr.nodeRefCreateFields)
					? attr.nodeRefCreateFields.slice()
					: [],
				sample: attr.sample != null ? String(attr.sample) : '',
				quantitySchema:
					attr.quantitySchema &&
					typeof attr.quantitySchema === 'object'
						? attr.quantitySchema
						: null,
				dateConfig: attr.dateConfig || null,
				intConfig: attr.intConfig || null,
				typeExtras:
					attr.typeExtras && typeof attr.typeExtras === 'object'
						? attr.typeExtras
						: null,
				displayFormat:
					attr.displayFormat != null
						? String(attr.displayFormat)
						: attr.preferredConverter != null
							? String(attr.preferredConverter)
							: attr.intConfig && attr.intConfig.displayFormat
								? String(attr.intConfig.displayFormat)
								: attr.typeExtras && attr.typeExtras.displayFormat
									? String(attr.typeExtras.displayFormat)
									: '',
				preferredConverter:
					attr.preferredConverter != null
						? String(attr.preferredConverter)
						: attr.displayFormat != null
							? String(attr.displayFormat)
							: attr.intConfig && attr.intConfig.displayFormat
								? String(attr.intConfig.displayFormat)
								: attr.typeExtras && attr.typeExtras.preferredConverter
									? String(attr.typeExtras.preferredConverter)
									: '',
				mediaConfig:
					attr.mediaConfig && typeof attr.mediaConfig === 'object'
						? attr.mediaConfig
						: null,
				typeProperties: Array.isArray(attr.typeProperties)
					? attr.typeProperties.slice()
					: [],
				typePreferredRender: String(attr.typePreferredRender || ''),
				preferredRender: String(
					attr.preferredRender || attr.typePreferredRender || ''
				),
			};
			/* Festwert wins over generic type sample (e.g. Einheit → Ohm). */
			if (fest) {
				field.sample = fest;
			} else if (!field.sample && Sample) {
				if (typeof Sample.forAttribute === 'function') {
					field.sample = String(Sample.forAttribute(field) || '');
				} else if (typeof Sample.forType === 'function') {
					field.sample = String(
						Sample.forType(typeKey, {
							name: field.name,
							shortDescription: field.shortDescription,
						}) || ''
					);
				}
			}
			out.push(field);
		});
		return out;
	}

	function valueKey(field) {
		if (field && field.id != null && String(field.id) !== '') {
			return String(field.id);
		}
		return String((field && field.name) || '');
	}

	function readValue(instance, field) {
		var values = (instance && instance.values) || {};
		var key = valueKey(field);
		if (Object.prototype.hasOwnProperty.call(values, key)) {
			return values[key];
		}
		if (field && field.name && Object.prototype.hasOwnProperty.call(values, field.name)) {
			return values[field.name];
		}
		return field && field.sample != null ? field.sample : '';
	}

	function fieldNode(field, value) {
		return Object.assign({}, field, {
			sample: value != null && String(value) !== '' ? String(value) : field.sample || '',
		});
	}

	function isMediaTypeKey(typeKey) {
		var key = String(typeKey || '')
			.trim()
			.toLowerCase();
		if (!key) {
			return false;
		}
		if (key.indexOf('/') !== -1) {
			var parts = key.split('/');
			key = String(parts[parts.length - 1] || '')
				.trim()
				.toLowerCase();
		}
		return key === 'media';
	}

	function isMediaProp(prop) {
		return (
			isMediaTypeKey(prop && prop.typeKey) ||
			isMediaTypeKey(prop && prop.typeName)
		);
	}

	/**
	 * Store strings for a many-valued property (never use display-only valueLabel for media).
	 *
	 * @param {object} prop
	 * @return {string[]}
	 */
	function manyPropStoreValues(prop) {
		var vals = Array.isArray(prop.values) ? prop.values.slice() : [];
		if (!vals.length && prop.valueLabel != null && String(prop.valueLabel) !== '') {
			var label = String(prop.valueLabel);
			/* Media: valueLabel may be a human filename — only reuse when it is store JSON. */
			if (!isMediaProp(prop) || label.charAt(0) === '{') {
				vals = [label];
			}
		}
		if (!vals.length) {
			var Sample = sampleApi();
			var sample =
				Sample && typeof Sample.forAttribute === 'function'
					? String(Sample.forAttribute(prop) || '')
					: '';
			if (sample) {
				vals = [sample];
			}
		}
		return vals.map(function (v) {
			return v == null ? '' : String(v);
		});
	}

	/**
	 * CatalogChoice depth from fixedOptions paths (Q90 mirror of build-path-tree).
	 * @param {Array} options
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

	function resolveCatalogChooserMode(options, explicitDepth) {
		var d =
			explicitDepth != null && explicitDepth > 0
				? explicitDepth
				: maxChoiceDepthFromOptions(options);
		return d >= 2 ? 'tree' : 'flat';
	}

	function catalogOptionsForField(field) {
		if (!field) {
			return [];
		}
		if (Array.isArray(field.fixedOptions) && field.fixedOptions.length) {
			return field.fixedOptions;
		}
		return [];
	}

	function isCatalogChoiceField(field) {
		if (!field) {
			return false;
		}
		/* Structure types embed Form/Table of typeProperties — never CatalogChoice. */
		if (isStructureField(field)) {
			return false;
		}
		if (String(field.fixedMode || '') === 'catalog') {
			return true;
		}
		return catalogOptionsForField(field).length > 0;
	}

	/**
	 * Whether the field embeds its type's attribute schema (Form/Table).
	 * @param {object|null} field
	 * @return {boolean}
	 */
	function isStructureField(field) {
		return !!(
			field &&
			Array.isArray(field.typeProperties) &&
			field.typeProperties.length > 0
		);
	}

	function isNodeRefField(field) {
		var key = String((field && (field.typeKey || field.typeName)) || '')
			.trim()
			.toLowerCase();
		if (key.indexOf('/') !== -1) {
			var parts = key.split('/');
			key = String(parts[parts.length - 1] || '')
				.trim()
				.toLowerCase();
		}
		return key === 'node_ref' || key === 'node_embed' || key === 'node_pick';
	}

	function isReferenceField(field) {
		return isNodeRefField(field) || isCatalogChoiceField(field);
	}

	function normalizeReferenceMode(mode) {
		var key = String(mode || 'link').toLowerCase();
		if (
			key === 'none' ||
			key === 'link' ||
			key === 'summary' ||
			key === 'embed'
		) {
			return key;
		}
		return 'link';
	}

	function normalizeRenderDepth(raw) {
		var n = parseInt(raw, 10);
		if (isNaN(n)) {
			n = 1;
		}
		if (n < 0) {
			n = 0;
		}
		if (n > 5) {
			n = 5;
		}
		return n;
	}

	function resolveReferenceLabel(field, value) {
		var options = [];
		if (field && Array.isArray(field.nodeRefOptions) && field.nodeRefOptions.length) {
			options = field.nodeRefOptions;
		} else {
			options = catalogOptionsForField(field);
		}
		var raw = value != null ? String(value) : '';
		if (!raw) {
			return '';
		}
		var ids = raw.split(/\s*,\s*/);
		var labels = [];
		ids.forEach(function (idRaw) {
			var id = String(idRaw || '').trim();
			if (!id) {
				return;
			}
			var found = '';
			var i;
			for (i = 0; i < options.length; i++) {
				var opt = options[i];
				if (opt && String(opt.id) === id) {
					found = opt.path || opt.name || id;
					break;
				}
			}
			labels.push(found || ( /^\d+$/.test(id) ? '#' + id : id ));
		});
		return labels.join(', ');
	}

	function paintReferenceDisplay(field, value, opts) {
		opts = opts || {};
		var mode = normalizeReferenceMode(opts.referenceMode);
		if (mode === 'none') {
			return createEl('span', {
				className: 'wtt-object-view__empty-value',
				text: '—',
			});
		}
		var label = resolveReferenceLabel(field, value) || String(value || '');
		if (mode === 'summary' || mode === 'embed') {
			var typeName = (field && (field.typeName || field.typeKey)) || '';
			var summary = label;
			if (typeName) {
				summary += ' · ' + typeName;
			}
			return createEl('span', {
				className:
					mode === 'embed'
						? 'wtt-object-view__ref wtt-object-view__ref--embed-stub'
						: 'wtt-object-view__ref wtt-object-view__ref--summary',
				text: summary || '—',
				title:
					mode === 'embed'
						? t(
								'referenceEmbedDeferred',
								'Nested embed requires depth ≥ 2; showing summary until full nest is available.'
						  )
						: '',
			});
		}
		return createEl('span', {
			className: 'wtt-object-view__ref wtt-object-view__ref--link',
			text: label || '—',
		});
	}

	function paintMediaEdit(field, value, opts) {
		opts = opts || {};
		var Media = global.WTTMediaRender;
		var raw = value != null ? String(value) : '';
		if (Media && typeof Media.renderField === 'function') {
			return Media.renderField(raw, {
				mode: 'edit',
				compact:
					opts.contextName === 'table' ||
					opts.contextName === 'compact',
				mediaConfig: (field && field.mediaConfig) || null,
				el: createEl,
				onChange:
					typeof opts.onInput === 'function'
						? function (next) {
								opts.onInput(next == null ? '' : String(next));
						  }
						: null,
			});
		}
		/* Fallback display-only when Media_Render edit API is missing. */
		var ref =
			Media && typeof Media.parseRef === 'function'
				? Media.parseRef(raw)
				: null;
		if (Media && typeof Media.renderSurface === 'function') {
			return Media.renderSurface(ref, {
				compact: true,
				el: createEl,
			});
		}
		return createEl('span', {
			className: 'wtt-object-render__display',
			text: '—',
		});
	}

	function resolveCatalogLabel(options, value) {
		var id = String(value != null ? value : '');
		if (!id) {
			return '';
		}
		var i;
		for (i = 0; i < (options || []).length; i++) {
			var opt = options[i];
			if (opt && String(opt.id) === id) {
				var short =
					opt.shortDescription != null
						? String(opt.shortDescription).trim()
						: '';
				if (short && short.length <= 3) {
					return short;
				}
				return opt.name || opt.path || id;
			}
		}
		return id;
	}

	function isPrefixChoiceField(field) {
		var key = String((field && field.name) || '')
			.toLowerCase()
			.replace(/\u00fc/g, 'ue')
			.replace(/\u00e4/g, 'ae')
			.replace(/\u00f6/g, 'oe');
		return (
			key === 'praefix' ||
			key === 'prefix' ||
			key === 'prafix'
		);
	}

	function catalogOptionLabel(opt, preferSymbol) {
		if (!opt) {
			return '';
		}
		if (preferSymbol) {
			var short =
				opt.shortDescription != null
					? String(opt.shortDescription).trim()
					: '';
			if (short && short.length <= 3) {
				return short;
			}
			var name = opt.name != null ? String(opt.name) : '';
			if (name === 'Mega') {
				return 'M';
			}
			if (name && name.length > 2) {
				return name.charAt(0).toLowerCase();
			}
			if (name) {
				return name;
			}
		}
		return opt.path || opt.name || String(opt.id != null ? opt.id : '');
	}

	/**
	 * Nested tree list for CatalogChoice depth ≥ 2 (vanilla mirror of ModelTreeChooser).
	 */
	function renderCatalogTreeChooser(options, selectedId, onPick) {
		var roots = [];
		var byKey = {};

		function ensureFolder(segments) {
			var parentList = roots;
			var pathSoFar = '';
			var i;
			for (i = 0; i < segments.length; i++) {
				pathSoFar = pathSoFar
					? pathSoFar + '/' + segments[i]
					: segments[i];
				var key = 'folder::' + pathSoFar;
				var node = byKey[key];
				if (!node) {
					node = {
						key: key,
						id: 0,
						name: segments[i],
						selectable: false,
						children: [],
					};
					byKey[key] = node;
					parentList.push(node);
				}
				parentList = node.children;
			}
			return parentList;
		}

		(options || []).forEach(function (opt) {
			if (!opt || opt.id == null) {
				return;
			}
			var path = String(opt.path || opt.name || '')
				.trim()
				.replace(/\s*\/\s*/g, '/')
				.replace(/^\/+|\/+$/g, '');
			var parts = path
				? path.split('/').filter(Boolean)
				: [String(opt.name || opt.id)];
			var leafName = parts[parts.length - 1] || String(opt.name || opt.id);
			var parentSegs = parts.slice(0, -1);
			var parentList =
				parentSegs.length > 0 ? ensureFolder(parentSegs) : roots;
			parentList.push({
				key: 'leaf::' + String(opt.id),
				id: parseInt(opt.id, 10) || 0,
				name: leafName,
				selectable: true,
				children: [],
			});
		});

		var expanded = {};
		function markAncestors(nodes, trail, targetId) {
			var i;
			for (i = 0; i < (nodes || []).length; i++) {
				var n = nodes[i];
				var next = trail.concat([n.key]);
				if (n.selectable && Number(n.id) === Number(targetId)) {
					trail.forEach(function (k) {
						expanded[k] = true;
					});
					return true;
				}
				if (markAncestors(n.children || [], next, targetId)) {
					return true;
				}
			}
			return false;
		}
		markAncestors(roots, [], selectedId);

		function renderNode(node, depth) {
			var li = createEl('li', { className: 'wtt-node-picker__node' });
			var hasChildren = node.children && node.children.length > 0;
			var isOpen = !!expanded[node.key];
			var isSelected =
				node.selectable && Number(node.id) === Number(selectedId || 0);
			var row = createEl('div', {
				className:
					'wtt-node-picker__row' +
					(isSelected ? ' is-picked is-current' : '') +
					(node.selectable ? '' : ' is-not-selectable'),
			});
			row.style.paddingLeft = depth * 0.75 + 'rem';
			if (hasChildren) {
				row.appendChild(
					createEl('button', {
						type: 'button',
						className: 'wtt-node-picker__twist',
						text: isOpen ? '▾' : '▸',
						onClick: function () {
							expanded[node.key] = !expanded[node.key];
							rebuild();
						},
					})
				);
			} else {
				row.appendChild(
					createEl('span', {
						className:
							'wtt-node-picker__twist wtt-node-picker__twist--spacer',
					})
				);
			}
			row.appendChild(
				createEl('button', {
					type: 'button',
					className: 'wtt-node-picker__name',
					text: node.name || '—',
					disabled: !node.selectable,
					onClick: function () {
						if (node.selectable && typeof onPick === 'function') {
							onPick(node.id);
						} else if (hasChildren) {
							expanded[node.key] = !expanded[node.key];
							rebuild();
						}
					},
				})
			);
			li.appendChild(row);
			if (hasChildren && isOpen) {
				var ul = createEl('ul', { className: 'wtt-node-picker__list' });
				node.children.forEach(function (child) {
					ul.appendChild(renderNode(child, depth + 1));
				});
				li.appendChild(ul);
			}
			return li;
		}

		var host = createEl('div', {
			className:
				'wtt-node-picker wtt-model-tree-chooser wtt-object-render__catalog-tree',
		});
		var tree = createEl('div', { className: 'wtt-node-picker__tree' });
		var list = createEl('ul', { className: 'wtt-node-picker__list' });

		function rebuild() {
			list.textContent = '';
			roots.forEach(function (r) {
				list.appendChild(renderNode(r, 0));
			});
		}
		rebuild();
		tree.appendChild(list);
		host.appendChild(tree);
		return host;
	}

	function paintCatalogChoice(field, value, opts) {
		opts = opts || {};
		var options = catalogOptionsForField(field);
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var selected = value != null ? String(value) : '';
		var mode = resolveCatalogChooserMode(
			options,
			field && field.choiceDepth
		);
		/* Table / compact cells: always flat <select> — no nested tree chrome. */
		if (
			opts.contextName === 'table' ||
			opts.contextName === 'compact'
		) {
			mode = 'flat';
		}

		if (readonly) {
			var label = resolveCatalogLabel(options, selected);
			return createEl('span', {
				className: 'wtt-object-render__display',
				text: label || '—',
			});
		}

		if (mode === 'flat' || options.length <= 1) {
			var preferSymbol = isPrefixChoiceField(field);
			var select = createEl('select', {
				className:
					'wtt-type-select wtt-catalog-choice-select' +
					(preferSymbol ? ' wtt-preview-input--prefix' : ''),
			});
			var realCount = 0;
			if (!options.length) {
				select.appendChild(
					createEl('option', { value: '', text: '—' })
				);
			} else {
				options.forEach(function (opt) {
					var id = opt && opt.id != null ? String(opt.id) : '';
					if (!id) {
						return;
					}
					realCount += 1;
					var option = createEl('option', {
						value: id,
						text: catalogOptionLabel(opt, preferSymbol),
					});
					if (preferSymbol && (opt.name || opt.path)) {
						option.title = String(opt.path || opt.name);
					} else if (opt.shortDescription) {
						option.title = String(opt.shortDescription);
					}
					if (id === selected || (!selected && !select.value)) {
						option.selected = true;
						selected = id;
					}
					select.appendChild(option);
				});
				if (!selected && select.options.length) {
					select.options[0].selected = true;
					selected = select.options[0].value;
				}
			}
			if (typeof opts.onInput === 'function') {
				select.addEventListener('change', function () {
					opts.onInput(select.value);
				});
			}
			applySoleRequiredListLock(select, realCount, {
				allowEmpty: fieldListSelectAllowsEmpty(field),
				disabled: false,
			});
			return select;
		}

		return renderCatalogTreeChooser(
			options,
			parseInt(selected, 10) || 0,
			function (id) {
				if (typeof opts.onInput === 'function') {
					opts.onInput(String(id));
				}
			}
		);
	}

	/**
	 * Embed the attribute type's schema via Form (Mult≤1 cell) — same surfaces, no special chrome.
	 *
	 * @param {object} field
	 * @param {string} value
	 * @param {object} opts
	 * @return {HTMLElement}
	 */
	function paintStructureEmbed(field, value, opts) {
		opts = opts || {};
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var attrs = schemaFieldsForManyProp(field);
		var instance = {
			attributes: attrs,
			values: rowValuesFromStore(attrs, value != null ? String(value) : ''),
		};
		return renderForm(instance, {
			readonly: readonly,
			referenceMode: opts.referenceMode,
			className: 'wtt-object-render__structure-embed',
			onFieldInput: readonly
				? null
				: function (innerField, next) {
						var idKey = valueKey(innerField);
						instance.values[idKey] =
							next == null ? '' : String(next);
						if (typeof opts.onInput === 'function') {
							opts.onInput(
								encodeStructureStore(instance.values, attrs)
							);
						}
				  },
		});
	}

	/**
	 * Preferred-render embed store (UR-B6 / Q93): id-only on host.
	 * Accepts Model_Data instance id (`md_…`), legacy kind term id, or old `{pick,values}` JSON.
	 */
	function parseEmbedStore(raw) {
		var out = { pick: '', values: {}, instanceId: '', kindId: '' };
		var s = raw != null ? String(raw).trim() : '';
		if (!s) {
			return out;
		}
		if (/^md_[a-z0-9_]+$/i.test(s)) {
			out.instanceId = s;
			out.pick = s;
			return out;
		}
		if (/^\d+$/.test(s)) {
			out.kindId = s;
			out.pick = s;
			return out;
		}
		try {
			var obj = JSON.parse(s);
			if (obj && typeof obj === 'object') {
				var p =
					obj.pick != null
						? String(obj.pick)
						: obj.p != null
							? String(obj.p)
							: obj.instanceId != null
								? String(obj.instanceId)
								: '';
				if (/^md_/i.test(p)) {
					out.instanceId = p;
				} else if (/^\d+$/.test(p)) {
					out.kindId = p;
				}
				out.pick = p;
				var vals = obj.values || obj.v;
				if (vals && typeof vals === 'object') {
					Object.keys(vals).forEach(function (k) {
						out.values[String(k)] =
							vals[k] == null ? '' : String(vals[k]);
					});
				}
				return out;
			}
		} catch (e) {
			/* plain fallback */
		}
		out.pick = s;
		return out;
	}

	/** Q93: emit id only (Model_Data instance id preferred). */
	function encodeEmbedStore(pick, values) {
		var p = pick != null ? String(pick).trim() : '';
		if (/^md_/i.test(p) || /^\d+$/.test(p)) {
			return p;
		}
		if (!p) {
			return '';
		}
		/* Legacy path — still emit pick when present; do not reintroduce values bag. */
		return p;
	}

	function encodeEmbedBind(instanceId) {
		var id = instanceId != null ? String(instanceId).trim() : '';
		return id;
	}

	function isEmbedPreferredField(field) {
		var key = String(
			(field && (field.typePreferredRender || field.preferredRender)) || ''
		)
			.trim()
			.toLowerCase();
		return (
			key === 'embeddedrenderer' ||
			key === 'embed' ||
			key === 'pick-fill' ||
			key === 'pick_fill' ||
			key === 'compact-embed'
		);
	}

	var schemaLoaderFn = null;
	var schemaCache = {};
	/** @type {{ taxonomy?: string, listInstances?: Function, createInstance?: Function, resolveInstance?: Function }} */
	var modelDataApi = {};
	/** Last kind term id picked in embed popup (focus fallback). */
	var lastEmbedKindId = 0;

	function setSchemaLoader(fn) {
		schemaLoaderFn = typeof fn === 'function' ? fn : null;
	}

	function loadNodeSchema(termId) {
		termId = parseInt(termId, 10) || 0;
		if (termId <= 0) {
			return Promise.resolve({ attributes: [] });
		}
		var key = String(termId);
		if (schemaCache[key]) {
			return Promise.resolve(schemaCache[key]);
		}
		if (!schemaLoaderFn) {
			return Promise.resolve({ attributes: [] });
		}
		return Promise.resolve(schemaLoaderFn(termId)).then(function (schema) {
			var normalized = {
				attributes: normalizeAttributes(
					(schema && (schema.attributes || schema.properties || schema.fields)) ||
						[]
				),
			};
			schemaCache[key] = normalized;
			return normalized;
		});
	}

	/**
	 * Build TreeChooser roots from flat fixedOptions (branch under type root only).
	 * @param {Array} options
	 * @param {number} [rootId]
	 * @return {Array}
	 */
	function buildEmbedKindRoots(options, rootId) {
		var roots = [];
		var byKey = {};
		var rootLabel = 'Bauteil';

		function ensureFolder(segments) {
			var parentList = roots;
			var pathSoFar = '';
			var i;
			for (i = 0; i < segments.length; i++) {
				pathSoFar = pathSoFar
					? pathSoFar + '/' + segments[i]
					: segments[i];
				var key = 'folder::' + pathSoFar;
				var node = byKey[key];
				if (!node) {
					node = {
						key: key,
						id: 0,
						name: segments[i],
						selectable: false,
						children: [],
					};
					byKey[key] = node;
					parentList.push(node);
				}
				parentList = node.children;
			}
			return parentList;
		}

		(options || []).forEach(function (opt) {
			if (!opt || opt.id == null) {
				return;
			}
			var path = String(opt.path || opt.name || '')
				.trim()
				.replace(/\s*\/\s*/g, '/')
				.replace(/^\/+|\/+$/g, '');
			var parts = path
				? path.split('/').filter(Boolean)
				: [String(opt.name || opt.id)];
			var leafName = parts[parts.length - 1] || String(opt.name || opt.id);
			var parentSegs = parts.slice(0, -1);
			var parentList =
				parentSegs.length > 0 ? ensureFolder(parentSegs) : roots;
			parentList.push({
				key: 'leaf::' + String(opt.id),
				id: parseInt(opt.id, 10) || 0,
				name: leafName,
				selectable: true,
				children: [],
			});
		});

		if (!roots.length && rootId > 0) {
			return [
				{
					id: rootId,
					name: rootLabel,
					selectable: true,
					children: [],
				},
			];
		}
		/* Present options as a single branch under the type root (Model/Bauteil). */
		if (roots.length && rootId > 0) {
			return [
				{
					id: rootId,
					name: rootLabel,
					selectable: false,
					children: roots,
				},
			];
		}
		return roots;
	}

	function instanceMatchesAndFilter(inst, filterValues, attrs) {
		var values =
			inst && inst.values && typeof inst.values === 'object'
				? inst.values
				: {};
		var i;
		for (i = 0; i < (attrs || []).length; i++) {
			var field = attrs[i];
			if (!field) {
				continue;
			}
			var k = valueKey(field);
			var want = filterValues[k] != null ? String(filterValues[k]).trim() : '';
			if (!want) {
				continue;
			}
			var have = values[k] != null ? String(values[k]) : '';
			if (have.toLowerCase().indexOf(want.toLowerCase()) === -1) {
				return false;
			}
		}
		return true;
	}

	function formatEmbedInstanceLabel(inst) {
		if (!inst) {
			return '';
		}
		var seq = parseInt(inst.seq, 10) || 0;
		var id = inst.id != null ? String(inst.id) : '';
		if (seq > 0) {
			return '#' + seq + (id ? ' · ' + id : '');
		}
		return id || '—';
	}

	/**
	 * UR-B6 popup: (A) TreeChooser kind under branch root; (B) Form filter + Model_Data list/create.
	 * Host store = instance id only (Q93).
	 *
	 * @param {{ choiceOptions?: Array, value?: string, readonly?: boolean, loadSchema?: function, onChange?: function, className?: string, field?: object, rootId?: number, required?: boolean }} options
	 * @return {HTMLElement}
	 */
	function renderEmbed(options) {
		options = options || {};
		var readonly = !!options.readonly;
		var choiceOptions = Array.isArray(options.choiceOptions)
			? options.choiceOptions
			: [];
		var field = options.field || null;
		var rootId =
			parseInt(options.rootId, 10) ||
			(field && (parseInt(field.fixedRootId, 10) || parseInt(field.typeId, 10))) ||
			0;
		var required =
			options.required != null
				? !!options.required
				: !!(field && field.allowsEmpty === false);
		var store = parseEmbedStore(options.value);
		var boundId = store.instanceId || '';
		var boundLabel = boundId
			? boundId
			: store.kindId
				? resolveCatalogLabel(choiceOptions, store.kindId) ||
				  '#' + store.kindId
				: '';

		var root = createEl('div', {
			className:
				'wtt-object-render wtt-object-render--embed wtt-object-render--embed-b6' +
				(readonly ? ' is-display' : ' is-edit') +
				(required && !boundId ? ' is-invalid' : '') +
				(options.className ? ' ' + options.className : ''),
		});

		var labelEl = createEl('span', {
			className: 'wtt-object-render__embed-pick-label',
			text: boundLabel || (readonly ? '—' : t('embedPickHint', 'Choose kind…')),
		});
		root.appendChild(labelEl);

		if (required && !boundId && !readonly) {
			root.appendChild(
				createEl('span', {
					className: 'wtt-object-render__embed-error',
					title: t(
						'embedRequiredEmpty',
						'Required — pick or create a part.'
					),
					text: '!',
				})
			);
		}

		function emitBind(instanceId, label) {
			boundId = instanceId != null ? String(instanceId) : '';
			boundLabel = label || boundId;
			labelEl.textContent =
				boundLabel || t('embedPickHint', 'Choose kind…');
			if (required) {
				if (boundId) {
					root.classList.remove('is-invalid');
					var err = root.querySelector('.wtt-object-render__embed-error');
					if (err && err.parentNode) {
						err.parentNode.removeChild(err);
					}
				} else if (!root.querySelector('.wtt-object-render__embed-error')) {
					root.classList.add('is-invalid');
					root.appendChild(
						createEl('span', {
							className: 'wtt-object-render__embed-error',
							title: t(
								'embedRequiredEmpty',
								'Required — pick or create a part.'
							),
							text: '!',
						})
					);
				}
			}
			if (typeof options.onChange === 'function') {
				options.onChange(encodeEmbedBind(boundId));
			}
		}

		function openB6Popup() {
			ensureDefaultModelDataApi();
			var Picker = global.WTTNodePicker;
			var kindRoots = buildEmbedKindRoots(choiceOptions, rootId);
			if (!kindRoots.length) {
				window.alert(
					t(
						'embedNoChoices',
						'No specialization children under this node.'
					)
				);
				return;
			}

			var phase = 'A';
			var kindId =
				parseInt(store.kindId, 10) ||
				lastEmbedKindId ||
				0;
			var filterValues = {};
			var kindAttrs = [];
			var instances = [];

			var body = createEl('div', {
				className: 'wtt-object-render__embed-dialog-body',
			});
			var titleEl = createEl('h2', {
				text: t('embedPhaseATitle', 'Choose part kind'),
			});

			function close() {
				if (backdrop.parentNode) {
					backdrop.parentNode.removeChild(backdrop);
				}
			}

			function paintPhaseA() {
				phase = 'A';
				titleEl.textContent = t('embedPhaseATitle', 'Choose part kind');
				body.textContent = '';
				var focusId = kindId || lastEmbedKindId || rootId || 0;
				if (Picker && typeof Picker.render === 'function') {
					body.appendChild(
						Picker.render({
							roots: kindRoots,
							selectedId: kindId || 0,
							focusId: focusId,
							expandFocusBranch: true,
							presentation: 'inline',
							embedded: true,
							defaultOpen: true,
							showPickedLabel: true,
							dialogTitle: t('embedPhaseATitle', 'Choose part kind'),
							i18n: i18nStrings,
							selectable: function (node) {
								return !!(node && node.selectable && node.id);
							},
							onSelect: function (id) {
								kindId = parseInt(id, 10) || 0;
								if (kindId > 0) {
									lastEmbedKindId = kindId;
									paintPhaseB();
								}
							},
						})
					);
				} else {
					/* Fallback: reuse catalog tree chooser (debt — prefer WTTNodePicker). */
					body.appendChild(
						renderCatalogTreeChooser(
							choiceOptions,
							kindId,
							function (id) {
								kindId = parseInt(id, 10) || 0;
								if (kindId > 0) {
									lastEmbedKindId = kindId;
									paintPhaseB();
								}
							}
						)
					);
				}
				backBtn.hidden = true;
			}

			function refreshMatches() {
				var listHost = body.querySelector(
					'.wtt-object-render__embed-matches'
				);
				if (!listHost) {
					return;
				}
				listHost.textContent = '';
				var matched = (instances || []).filter(function (inst) {
					return instanceMatchesAndFilter(inst, filterValues, kindAttrs);
				});
				if (!matched.length) {
					listHost.appendChild(
						createEl('p', {
							className: 'description',
							text: t('embedNoMatches', 'No matching instances.'),
						})
					);
					return;
				}
				var table = createEl('table', {
					className:
						'wtt-model-instance-picker__table wtt-object-render__embed-instance-table',
				});
				var thead = createEl('thead');
				var hr = createEl('tr');
				hr.appendChild(
					createEl('th', { text: t('colIndex', '#') })
				);
				hr.appendChild(
					createEl('th', { text: t('colVersion', 'Version') })
				);
				hr.appendChild(
					createEl('th', { text: t('colModified', 'Modified') })
				);
				hr.appendChild(
					createEl('th', { text: t('colInstanceId', 'Id') })
				);
				thead.appendChild(hr);
				table.appendChild(thead);
				var tbody = createEl('tbody');
				matched.forEach(function (inst) {
					var tr = createEl('tr', {
						className: 'wtt-model-instance-picker__row',
						onClick: function () {
							emitBind(
								inst.id,
								formatEmbedInstanceLabel(inst)
							);
							close();
						},
					});
					var seq = parseInt(inst.seq, 10) || 0;
					tr.appendChild(
						createEl('td', {
							text: seq > 0 ? '#' + seq : '—',
						})
					);
					tr.appendChild(
						createEl('td', {
							text: inst.version != null ? 'v' + inst.version : '—',
						})
					);
					tr.appendChild(
						createEl('td', {
							text:
								inst.modifiedAtLabel ||
								inst.modifiedAt ||
								'—',
						})
					);
					tr.appendChild(
						createEl('td', {
							html:
								'<code>' +
								String(inst.id || '')
									.replace(/</g, '&lt;')
									.replace(/>/g, '&gt;') +
								'</code>',
						})
					);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				listHost.appendChild(table);
			}

			function paintPhaseB() {
				phase = 'B';
				titleEl.textContent = t('embedPhaseBTitle', 'Pick or create part');
				body.textContent = '';
				body.appendChild(
					createEl('p', {
						className: 'description',
						text: t(
							'embedPhaseBHint',
							'Filter existing Model data for this kind, pick a match, or create from the form.'
						),
					})
				);
				backBtn.hidden = false;

				var filterHost = createEl('div', {
					className: 'wtt-object-render__embed-filter',
				});
				filterHost.appendChild(
					createEl('strong', {
						text: t('embedFilterLabel', 'Filter (AND)'),
					})
				);
				var formHost = createEl('div', {
					className: 'wtt-object-render__embed-filter-form',
				});
				filterHost.appendChild(formHost);
				body.appendChild(filterHost);

				var matchesWrap = createEl('div', {
					className: 'wtt-object-render__embed-matches-wrap',
				});
				matchesWrap.appendChild(
					createEl('strong', {
						text: t('embedMatches', 'Matches'),
					})
				);
				var listHost = createEl('div', {
					className: 'wtt-object-render__embed-matches',
				});
				listHost.appendChild(
					createEl('span', {
						className: 'wtt-field-hint',
						text: t('embedLoading', 'Loading…'),
					})
				);
				matchesWrap.appendChild(listHost);
				body.appendChild(matchesWrap);

				var actionsRow = createEl('div', {
					className: 'wtt-object-render__embed-phase-actions',
				});
				var createBtn = createEl('button', {
					type: 'button',
					className: 'button button-primary',
					text: t('embedCreateBind', 'Create and bind'),
				});
				actionsRow.appendChild(createBtn);
				body.appendChild(actionsRow);

				var loader =
					typeof options.loadSchema === 'function'
						? options.loadSchema
						: loadNodeSchema;

				Promise.resolve(loader(kindId)).then(function (schema) {
					kindAttrs = normalizeAttributes(
						(schema && schema.attributes) || []
					);
					formHost.textContent = '';
					if (!kindAttrs.length) {
						formHost.appendChild(
							createEl('span', {
								className: 'wtt-field-hint',
								text: t(
									'embedNoFields',
									'Selected node has no attributes.'
								),
							})
						);
					} else {
						filterValues = {};
						formHost.appendChild(
							renderForm(
								{ attributes: kindAttrs, values: filterValues },
								{
									readonly: false,
									className:
										'wtt-object-render__embed-filter-inner',
									onFieldInput: function (innerField, next) {
										var k = valueKey(innerField);
										filterValues[k] =
											next == null ? '' : String(next);
										refreshMatches();
									},
								}
							)
						);
					}

					var listFn = modelDataApi.listInstances;
					if (typeof listFn !== 'function') {
						listHost.textContent = '';
						listHost.appendChild(
							createEl('p', {
								className: 'description',
								text: t(
									'embedInstanceApiMissing',
									'Model data API unavailable — cannot list or create instances.'
								),
							})
						);
						/* TODO(UR-B6): AND-filter polish + REST wiring on all surfaces. */
						createBtn.disabled = true;
						return;
					}

					Promise.resolve(
						listFn(kindId, modelDataApi.taxonomy || '')
					).then(function (rows) {
						instances = Array.isArray(rows) ? rows : [];
						refreshMatches();
					}).catch(function () {
						instances = [];
						listHost.textContent = '';
						listHost.appendChild(
							createEl('p', {
								className: 'description',
								text: t('error', 'Something went wrong.'),
							})
						);
					});
				});

				createBtn.addEventListener('click', function () {
					var createFn = modelDataApi.createInstance;
					if (typeof createFn !== 'function') {
						window.alert(
							t(
								'embedInstanceApiMissing',
								'Model data API unavailable — cannot list or create instances.'
							)
						);
						return;
					}
					/* Minimal Mult/required check on create form (= filter values). */
					var missing = [];
					kindAttrs.forEach(function (a) {
						if (!a || a.allowsEmpty !== false) {
							return;
						}
						var k = valueKey(a);
						var v =
							filterValues[k] != null
								? String(filterValues[k]).trim()
								: '';
						if (!v) {
							missing.push(a.name || k);
						}
					});
					if (missing.length) {
						window.alert(
							t(
								'embedRequiredEmpty',
								'Required — pick or create a part.'
							) +
								'\n' +
								missing.join(', ')
						);
						return;
					}
					createBtn.disabled = true;
					Promise.resolve(
						createFn(
							kindId,
							Object.assign({}, filterValues),
							modelDataApi.taxonomy || ''
						)
					)
						.then(function (created) {
							createBtn.disabled = false;
							if (!created || !created.id) {
								window.alert(
									t('error', 'Something went wrong.')
								);
								return;
							}
							emitBind(
								created.id,
								formatEmbedInstanceLabel(created)
							);
							close();
						})
						.catch(function () {
							createBtn.disabled = false;
							window.alert(t('error', 'Something went wrong.'));
						});
				});
			}

			var backBtn = createEl('button', {
				type: 'button',
				className: 'button',
				text: t('embedBackKind', '← Kind'),
				onClick: function () {
					paintPhaseA();
				},
			});
			backBtn.hidden = true;

			var backdrop = createEl('div', {
				className: 'wtt-dialog-backdrop wtt-object-render__embed-backdrop',
			});
			var dialog = createEl('div', {
				className: 'wtt-dialog wtt-dialog--embed-b6',
				role: 'dialog',
			});
			dialog.appendChild(titleEl);
			dialog.appendChild(body);
			dialog.appendChild(
				createEl('div', { className: 'wtt-dialog__actions' }, [
					backBtn,
					createEl('button', {
						type: 'button',
						className: 'button',
						text: t('cancel', 'Cancel'),
						onClick: function () {
							close();
						},
					}),
				])
			);
			backdrop.appendChild(dialog);
			backdrop.addEventListener('click', function (e) {
				if (e.target === backdrop) {
					close();
				}
			});
			document.body.appendChild(backdrop);
			paintPhaseA();
		}

		if (!readonly) {
			root.appendChild(
				createEl('button', {
					type: 'button',
					className: 'button button-small wtt-object-render__embed-open',
					text: boundId
						? t('embedChangePart', 'Change…')
						: t('embedPickPart', 'Pick part…'),
					onClick: function (e) {
						e.preventDefault();
						openB6Popup();
					},
				})
			);
		}

		return root;
	}

	function paintEmbedField(field, value, opts) {
		opts = opts || {};
		var required =
			field &&
			field.allowsEmpty === false &&
			!multiplicityAllowsMany(field.multiplicity);
		return renderEmbed({
			field: field,
			choiceOptions: catalogOptionsForField(field),
			rootId:
				parseInt(field && field.fixedRootId, 10) ||
				parseInt(field && field.typeId, 10) ||
				0,
			value: value != null ? String(value) : '',
			readonly: !!opts.readonly || !!(field && field.readonly),
			required: !!required,
			loadSchema: opts.loadSchema,
			className: 'wtt-object-render__embed-field',
			onChange:
				typeof opts.onInput === 'function'
					? function (next) {
							opts.onInput(next);
					  }
					: null,
		});
	}

	/**
	 * @param {object} values
	 * @param {Array} attrs
	 * @return {string}
	 */
	function encodeStructureStore(values, attrs) {
		values = values || {};
		attrs = attrs || [];
		var obj = {};
		var any = false;
		attrs.forEach(function (field) {
			var k = valueKey(field);
			var v = values[k] != null ? String(values[k]) : '';
			obj[k] = v;
			if (v !== '') {
				any = true;
			}
		});
		if (!any) {
			return '';
		}
		if (attrs.length === 1) {
			return obj[valueKey(attrs[0])] || '';
		}
		try {
			return JSON.stringify(obj);
		} catch (e) {
			return '';
		}
	}

	function paintFieldContent(field, value, opts) {
		opts = opts || {};
		var readonly =
			!!opts.readonly || !!(field && field.readonly);

		if (isMediaTypeKey(field && field.typeKey)) {
			if (!readonly) {
				return paintMediaEdit(field, value, opts);
			}
			var Media = global.WTTMediaRender;
			var raw = value != null ? String(value) : '';
			var ref =
				Media && typeof Media.parseRef === 'function'
					? Media.parseRef(raw)
					: null;
			if (Media && typeof Media.renderSurface === 'function') {
				return Media.renderSurface(ref, {
					compact:
						opts.contextName === 'table' ||
						opts.contextName === 'compact',
					el: createEl,
				});
			}
			var label =
				Media && typeof Media.displayLabel === 'function' && ref
					? Media.displayLabel(ref)
					: raw && raw.charAt(0) === '{'
						? '—'
						: raw;
			return createEl('span', {
				className: 'wtt-object-render__display',
				text: label || '—',
			});
		}

		/* Quantity trinity (schema on unit-typed attrs) before CatalogChoice. */
		var hasQty =
			field &&
			field.quantitySchema &&
			Array.isArray(field.quantitySchema.members) &&
			field.quantitySchema.members.length;
		if (hasQty) {
			var RegQty = registry();
			var ctxQty = {
				name: opts.contextName || 'form',
				mode: readonly ? 'display' : 'edit',
				bare: true,
				hideLabel: true,
				value: value != null ? String(value) : '',
				onInput:
					readonly || typeof opts.onInput !== 'function'
						? null
						: opts.onInput,
			};
			var nodeQty = fieldNode(field, value);
			if (RegQty && typeof RegQty.renderContent === 'function') {
				var paintedQty = RegQty.renderContent(nodeQty, ctxQty, readonly);
				if (paintedQty) {
					return paintedQty;
				}
			}
		}

		/* Preferred render embed on the type node: pick child + compact fill. */
		if (isEmbedPreferredField(field)) {
			return paintEmbedField(field, value, opts);
		}

		/* Structured type (has attributes) → embed Form of type schema, not CatalogChoice. */
		if (isStructureField(field)) {
			return paintStructureEmbed(field, value, opts);
		}

		/* Display-only referenceMode; edit path keeps pickers / controls. */
		if (readonly && isReferenceField(field)) {
			return paintReferenceDisplay(field, value, opts);
		}

		if (isCatalogChoiceField(field)) {
			return paintCatalogChoice(field, value, opts);
		}

		var Reg = registry();
		var context = {
			name: opts.contextName || 'form',
			mode: readonly ? 'display' : 'edit',
			bare: true,
			hideLabel: true,
			value: value != null ? String(value) : '',
			onInput:
				readonly || typeof opts.onInput !== 'function' ? null : opts.onInput,
		};
		var node = fieldNode(field, value);
		if (Reg && typeof Reg.renderContent === 'function') {
			var painted = Reg.renderContent(node, context, readonly);
			if (painted) {
				return painted;
			}
		}
		/* Fallback when Registry has no renderer for this typeKey. */
		if (readonly) {
			return createEl('span', {
				className: 'wtt-object-render__display',
				text: value != null && String(value) !== '' ? String(value) : '—',
			});
		}
		var input = createEl('input', {
			type: field.typeKey === 'email' ? 'email' : 'text',
			className: 'wtt-preview-input wtt-object-render__input',
			value: value != null ? String(value) : '',
		});
		if (typeof opts.onInput === 'function') {
			input.addEventListener('input', function () {
				opts.onInput(input.value);
			});
			input.addEventListener('change', function () {
				opts.onInput(input.value);
			});
		}
		return input;
	}

	/**
	 * Form surface: one filled instance → attribute rows.
	 *
	 * @param {object} instance { attributes|fields, values }
	 * @param {{ readonly?: boolean, onFieldInput?: function, className?: string }} [options]
	 * @return {HTMLElement}
	 */
	function renderForm(instance, options) {
		options = options || {};
		var readonly = !!options.readonly;
		var fields = normalizeAttributes(
			(instance && (instance.attributes || instance.fields)) || []
		);
		var form = createEl('div', {
			className:
				'wtt-object-render wtt-object-render--form wtt-set-preview__form' +
				(readonly ? ' wtt-set-preview__form--display is-display' : ' is-edit') +
				(options.className ? ' ' + options.className : ''),
		});

		fields.forEach(function (field) {
			var row = createEl('div', {
				className:
					'wtt-set-preview__row wtt-object-render__row' +
					(field.readonly ? ' is-readonly' : ''),
			});
			row.appendChild(
				createEl('label', {
					className: 'wtt-set-preview__label',
					text: field.name || '—',
				})
			);
			var control = createEl('div', {
				className: 'wtt-object-render__control',
			});
			var current = readValue(instance, field);
			var fieldReadonly = readonly || !!field.readonly;
			control.appendChild(
				paintFieldContent(field, current, {
					readonly: fieldReadonly,
					contextName: 'form',
					referenceMode: options.referenceMode,
					onInput: fieldReadonly
						? null
						: function (next) {
								if (typeof options.onFieldInput === 'function') {
									options.onFieldInput(field, next, instance);
								}
						  },
				})
			);
			row.appendChild(control);
			form.appendChild(row);
		});

		if (!fields.length) {
			form.appendChild(
				createEl('p', {
					className: 'wtt-field-hint',
					text: 'No attributes on this schema.',
				})
			);
		}

		return form;
	}

	/**
	 * Table surface: list of instances → columns = attributes, rows = instances.
	 *
	 * @param {Array} instances
	 * @param {{ readonly?: boolean, onFieldInput?: function, className?: string }} [options]
	 * @return {HTMLElement}
	 */
	function renderTable(instances, options) {
		options = options || {};
		var readonly = !!options.readonly;
		instances = Array.isArray(instances) ? instances : [];
		var fields = normalizeAttributes(
			(instances[0] && (instances[0].attributes || instances[0].fields)) ||
				(options.attributes || [])
		);

		var wrap = createEl('div', {
			className:
				'wtt-object-render wtt-object-render--table wtt-set-preview__table-wrap wtt-object-view__table-wrap' +
				(options.className ? ' ' + options.className : ''),
		});
		var table = createEl('table', {
			className:
				'wtt-set-preview__table wtt-object-render__table wtt-object-view__table',
		});
		var thead = createEl('thead');
		var headRow = createEl('tr');
		fields.forEach(function (field) {
			headRow.appendChild(
				createEl('th', {
					text: field.name || '—',
					scope: 'col',
				})
			);
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = createEl('tbody');
		if (0 === instances.length) {
			var emptyTr = createEl('tr', {
				className: 'wtt-object-render__table-empty-row',
			});
			var emptyTd = createEl('td', {
				className: 'wtt-object-render__table-empty',
				text:
					options.emptyMessage ||
					t('tableEmpty', 'No data available.'),
			});
			emptyTd.colSpan = Math.max(1, fields.length);
			emptyTr.appendChild(emptyTd);
			tbody.appendChild(emptyTr);
		} else {
			instances.forEach(function (instance, rowIndex) {
				var tr = createEl('tr');
				fields.forEach(function (field) {
					var td = createEl('td');
					var current = readValue(instance, field);
					var fieldReadonly = readonly || !!field.readonly;
					td.appendChild(
						paintFieldContent(field, current, {
							readonly: fieldReadonly,
							contextName: 'table',
							referenceMode: options.referenceMode,
							onInput: fieldReadonly
								? null
								: function (next) {
										if (typeof options.onFieldInput === 'function') {
											options.onFieldInput(
												field,
												next,
												instance,
												rowIndex
											);
										}
								  },
						})
					);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			});
		}
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Compact surface: one filled instance → dense field strip.
	 * orientation: 'horizontal' (one row, wrap allowed) | 'vertical' (tight stack).
	 *
	 * @param {object} instance { attributes|fields, values }
	 * @param {{ readonly?: boolean, orientation?: string, onFieldInput?: function, className?: string }} [options]
	 * @return {HTMLElement}
	 */
	function renderCompact(instance, options) {
		options = options || {};
		var readonly = !!options.readonly;
		var orientation =
			String(options.orientation || 'horizontal').toLowerCase() === 'vertical'
				? 'vertical'
				: 'horizontal';
		var fields = normalizeAttributes(
			(instance && (instance.attributes || instance.fields)) || []
		);
		var strip = createEl('div', {
			className:
				'wtt-object-render wtt-object-render--compact wtt-object-render--compact-' +
				orientation +
				' wtt-set-preview__compact' +
				(readonly ? ' is-display' : ' is-edit') +
				(options.className ? ' ' + options.className : ''),
			'data-orientation': orientation,
		});

		fields.forEach(function (field) {
			var cell = createEl('div', {
				className: 'wtt-object-render__compact-field',
			});
			cell.appendChild(
				createEl('label', {
					className: 'wtt-object-render__compact-label',
					text: field.name || '—',
				})
			);
			var control = createEl('div', {
				className: 'wtt-object-render__compact-control',
			});
			var current = readValue(instance, field);
			var fieldReadonly = readonly || !!field.readonly;
			control.appendChild(
				paintFieldContent(field, current, {
					readonly: fieldReadonly,
					contextName: 'compact',
					referenceMode: options.referenceMode,
					onInput: fieldReadonly
						? null
						: function (next) {
								if (typeof options.onFieldInput === 'function') {
									options.onFieldInput(field, next, instance);
								}
						  },
				})
			);
			cell.appendChild(control);
			strip.appendChild(cell);
		});

		if (!fields.length) {
			strip.appendChild(
				createEl('p', {
					className: 'wtt-field-hint',
					text: 'No attributes on this schema.',
				})
			);
		}

		return strip;
	}

	function schemaName(schemaNode) {
		return String((schemaNode && schemaNode.name) || '').trim();
	}

	/**
	 * Host-agnostic one-instance fill via WTTSampleData (name → type).
	 * @param {object} schemaNode
	 * @param {Array} [attributes]
	 * @param {number} [variantIndex] Row index for light sample variation
	 * @return {object}
	 */
	function buildExampleInstance(schemaNode, attributes, variantIndex) {
		variantIndex = Math.abs(parseInt(variantIndex, 10) || 0);
		var attrs =
			attributes ||
			(schemaNode && (schemaNode.attributes || schemaNode.fields)) ||
			[];
		var fields = normalizeAttributes(attrs);
		var Sample = sampleApi();
		var values = {};
		fields.forEach(function (field) {
			var key = valueKey(field);
			var fest =
				(field.fixedLabel && String(field.fixedLabel).trim()) || '';
			if (fest) {
				values[key] = fest;
				return;
			}
			if (Sample && typeof Sample.forAttribute === 'function') {
				values[key] = String(
					Sample.forAttribute(
						Object.assign({}, field, { variantIndex: variantIndex })
					) ||
						field.sample ||
						''
				);
				return;
			}
			values[key] = field.sample || '';
		});
		return {
			schemaName: schemaName(schemaNode),
			attributes: fields,
			values: values,
			variant: variantIndex,
		};
	}

	/**
	 * @param {object} schemaNode
	 * @param {number} [n]
	 * @param {Array} [attributes]
	 * @return {Array}
	 */
	function buildExampleList(schemaNode, n, attributes) {
		n = Math.max(1, parseInt(n, 10) || 3);
		var attrs =
			attributes ||
			(schemaNode && (schemaNode.attributes || schemaNode.fields)) ||
			[];
		var list = [];
		var i;
		for (i = 0; i < n; i++) {
			list.push(buildExampleInstance(schemaNode, attrs, i));
		}
		return list;
	}

	/** @type {Record<string, string>} */
	var i18nStrings = {};

	function t(key, fallback) {
		if (i18nStrings && i18nStrings[key] != null && String(i18nStrings[key]) !== '') {
			return String(i18nStrings[key]);
		}
		return fallback != null ? String(fallback) : key;
	}

	/**
	 * Default Model_Data bridge via wp.apiFetch REST when page did not wire AJAX.
	 */
	function ensureDefaultModelDataApi() {
		if (
			typeof modelDataApi.listInstances === 'function' &&
			typeof modelDataApi.createInstance === 'function'
		) {
			return;
		}
		var apiFetch =
			global.wp && global.wp.apiFetch ? global.wp.apiFetch : null;
		if (typeof apiFetch !== 'function') {
			return;
		}
		if (typeof modelDataApi.listInstances !== 'function') {
			modelDataApi.listInstances = function (structureId, taxonomy) {
				var id = parseInt(structureId, 10) || 0;
				var qs = taxonomy
					? '?taxonomy=' + encodeURIComponent(String(taxonomy))
					: '';
				return apiFetch({
					path: '/wtt/v1/model-data/' + id + qs,
				}).then(function (data) {
					return data && Array.isArray(data.instances)
						? data.instances
						: [];
				});
			};
		}
		if (typeof modelDataApi.createInstance !== 'function') {
			modelDataApi.createInstance = function (
				structureId,
				values,
				taxonomy
			) {
				var id = parseInt(structureId, 10) || 0;
				return apiFetch({
					path: '/wtt/v1/model-data/' + id,
					method: 'POST',
					data: {
						taxonomy: taxonomy || modelDataApi.taxonomy || undefined,
						values:
							values && typeof values === 'object' ? values : {},
					},
				}).then(function (data) {
					return data && data.instance ? data.instance : null;
				});
			};
		}
	}

	/**
	 * Merge i18n / Model_Data API from PHP localize / page boot.
	 * @param {{ i18n?: Record<string, string>, taxonomy?: string, modelDataApi?: object }} [opts]
	 */
	function configure(opts) {
		opts = opts || {};
		if (opts.i18n && typeof opts.i18n === 'object') {
			i18nStrings = Object.assign({}, i18nStrings, opts.i18n);
		}
		if (opts.taxonomy != null && String(opts.taxonomy) !== '') {
			modelDataApi.taxonomy = String(opts.taxonomy);
		}
		if (opts.modelDataApi && typeof opts.modelDataApi === 'object') {
			modelDataApi = Object.assign({}, modelDataApi, opts.modelDataApi);
			if (opts.taxonomy != null && String(opts.taxonomy) !== '') {
				modelDataApi.taxonomy = String(opts.taxonomy);
			}
		}
		ensureDefaultModelDataApi();
	}

	function multiplicityAllowsMany(mult) {
		var m = String(mult || '1').trim();
		return m === '0..*' || m === '1..*';
	}

	/** Zero-lower multiplicity → optional list-select (Q116). */
	function multiplicityAllowsEmpty(mult) {
		var m = String(mult == null || mult === '' ? '1' : mult).trim();
		return m === '0' || m === '0..1' || m === '0..*';
	}

	/**
	 * Q116: required list-select with exactly one real option → select + disable.
	 * @param {HTMLSelectElement} control
	 * @param {number} realOptionCount
	 * @param {{ allowEmpty?: boolean, disabled?: boolean, title?: string }} [opts]
	 */
	function applySoleRequiredListLock(control, realOptionCount, opts) {
		opts = opts || {};
		if (!control) {
			return;
		}
		control.classList.remove('is-sole-locked');
		if (opts.disabled) {
			control.disabled = true;
			return;
		}
		var count = parseInt(realOptionCount, 10) || 0;
		if (!opts.allowEmpty && count === 1) {
			control.disabled = true;
			control.classList.add('is-sole-locked');
			control.title =
				opts.title ||
				'Only one choice — selected automatically.';
			return;
		}
		control.disabled = false;
	}

	function fieldListSelectAllowsEmpty(field) {
		if (field && field.required === false) {
			return true;
		}
		if (field && field.required === true) {
			return false;
		}
		return multiplicityAllowsEmpty(field && field.multiplicity);
	}

	/**
	 * Map Object_Render view DTO → Form/Table/Compact instance shape.
	 * @param {object|null} view
	 * @param {Array|null} [onlyProps] Optional property subset
	 * @return {{ attributes: Array, values: object, schemaName: string }}
	 */
	function instanceFromView(view, onlyProps) {
		var Sample = sampleApi();
		var props =
			onlyProps ||
			(view && Array.isArray(view.properties) ? view.properties : []) ||
			[];
		var instanceValues =
			view && view.instanceValues && typeof view.instanceValues === 'object'
				? view.instanceValues
				: {};
		var attributes = [];
		var values = {};
		props.forEach(function (prop) {
			if (!prop || typeof prop !== 'object') {
				return;
			}
			var id = prop.id != null ? prop.id : prop.name;
			var idKey = String(id);
			var field = {
				id: id,
				name: prop.name || '',
				typeKey: prop.typeKey || prop.typeName || 'text',
				typeName: prop.typeName || '',
				typeId: prop.typeId || 0,
				multiplicity: prop.multiplicity || '1',
				fieldMultiplicity:
					prop.fieldMultiplicity || prop.multiplicity || '1',
				allowsMany:
					!!prop.allowsMany ||
					multiplicityAllowsMany(prop.multiplicity),
				readonly: !!prop.readonly,
				inherited: !!prop.inherited,
				fixedMode: prop.fixedMode || '',
				fixedOptions: Array.isArray(prop.fixedOptions)
					? prop.fixedOptions.slice()
					: [],
				choiceDepth:
					prop.choiceDepth != null
						? parseInt(prop.choiceDepth, 10) || 0
						: 0,
				refScopeId:
					prop.refScopeId != null
						? parseInt(prop.refScopeId, 10) || 0
						: 0,
				nodeRefOptions: Array.isArray(prop.nodeRefOptions)
					? prop.nodeRefOptions.slice()
					: [],
				nodeRefCreateFields: Array.isArray(prop.nodeRefCreateFields)
					? prop.nodeRefCreateFields.slice()
					: [],
				sample: '',
				quantitySchema:
					prop.quantitySchema && typeof prop.quantitySchema === 'object'
						? prop.quantitySchema
						: null,
				dateConfig: prop.dateConfig || null,
				intConfig: prop.intConfig || null,
				displayFormat:
					prop.displayFormat != null
						? String(prop.displayFormat)
						: prop.preferredConverter != null
							? String(prop.preferredConverter)
							: prop.intConfig && prop.intConfig.displayFormat
								? String(prop.intConfig.displayFormat)
								: '',
				preferredConverter:
					prop.preferredConverter != null
						? String(prop.preferredConverter)
						: prop.displayFormat != null
							? String(prop.displayFormat)
							: prop.intConfig && prop.intConfig.displayFormat
								? String(prop.intConfig.displayFormat)
								: '',
				mediaConfig:
					prop.mediaConfig && typeof prop.mediaConfig === 'object'
						? prop.mediaConfig
						: null,
				typeProperties: Array.isArray(prop.typeProperties)
					? prop.typeProperties.slice()
					: [],
				binding: prop.binding || '',
				isRelatedDataset: !!prop.isRelatedDataset,
				usesRelatedInstances: !!prop.usesRelatedInstances,
				relatedInstances: Array.isArray(prop.relatedInstances)
					? prop.relatedInstances.slice()
					: [],
			};
			var val = '';
			var media = isMediaProp(prop) || isMediaTypeKey(field.typeKey);
			if (
				Object.prototype.hasOwnProperty.call(instanceValues, idKey) &&
				String(instanceValues[idKey] || '') !== ''
			) {
				val = String(instanceValues[idKey]);
			} else if (
				!!prop.hasInstanceValue &&
				Array.isArray(prop.values) &&
				prop.values.length
			) {
				val =
					prop.values.length > 1
						? JSON.stringify(prop.values.map(String))
						: String(prop.values[0]);
			} else if (Array.isArray(prop.values) && prop.values.length) {
				/* Prefer store values[] over display valueLabel (media JSON vs filename). */
				val =
					prop.values.length > 1
						? JSON.stringify(prop.values.map(String))
						: String(prop.values[0]);
			} else if (
				prop.valueLabel != null &&
				String(prop.valueLabel) !== '' &&
				(!media || String(prop.valueLabel).charAt(0) === '{')
			) {
				val = String(prop.valueLabel);
			}
			if (!val && Sample && typeof Sample.forAttribute === 'function') {
				val = String(Sample.forAttribute(field) || '');
			}
			field.sample = val;
			attributes.push(field);
			values[idKey] = val;
		});
		return {
			schemaName: (view && view.name) || '',
			attributes: attributes,
			values: values,
		};
	}

	function partitionViewProperties(view) {
		var props =
			(view && Array.isArray(view.properties) ? view.properties : []) || [];
		var single = [];
		var many = [];
		props.forEach(function (prop) {
			if (!prop || typeof prop !== 'object') {
				return;
			}
			if (
				!!prop.allowsMany ||
				multiplicityAllowsMany(prop.multiplicity)
			) {
				many.push(prop);
			} else {
				single.push(prop);
			}
		});
		return { single: single, many: many };
	}

	/**
	 * Object-view layout wire ids (Q113). Legacy form|table|compact|embed accepted.
	 */
	function normalizeLayout(layout) {
		var key = String(layout == null || layout === '' ? 'FormRenderer' : layout)
			.trim()
			.toLowerCase();
		if (key === 'auto') {
			return 'auto';
		}
		var map = {
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
		};
		return map[key] || 'FormRenderer';
	}

	function resolveLayout(layout, view) {
		var raw =
			layout != null && String(layout) !== ''
				? layout
				: 'auto';
		var key = normalizeLayout(raw);
		if (key === 'auto') {
			var preferred =
				(view && view.preferredRender) ||
				(view && view.preferred_render) ||
				'FormRenderer';
			key = normalizeLayout(preferred);
			if (key === 'auto') {
				key = 'FormRenderer';
			}
		}
		return key;
	}

	function appendMetaStrip(root, view) {
		var none = t('none', '—');
		var chips = [];

		function pushChip(label, value, metaKey, title) {
			if (value == null || String(value) === '') {
				return;
			}
			chips.push({
				label: label,
				value: String(value),
				metaKey: metaKey || '',
				title: title || '',
			});
		}

		if (view && view.id) {
			pushChip(t('termId', 'ID'), view.id, 'id');
		}
		var parentId = view && view.parent != null ? parseInt(view.parent, 10) || 0 : 0;
		var parentName = (view && view.parentName) || '';
		pushChip(
			t('parent', 'Parent'),
			parentId > 0
				? parentName || '#' + parentId
				: none,
			'parent'
		);
		pushChip(
			t('slug', 'Slug'),
			(view && view.slug) || none,
			'slug'
		);
		if (view && view.typeName) {
			pushChip(t('type', 'Type'), view.typeName, 'type');
		}
		var modified = view && view.modified && typeof view.modified === 'object'
			? view.modified
			: null;
		if (modified && (modified.userName || modified.atLabel)) {
			var modBy =
				modified.userName ||
				(modified.userId ? '#' + String(modified.userId) : none);
			pushChip(
				t('lastModifiedBy', 'Last modified by'),
				modBy,
				'modifiedBy',
				modified.atLabel
					? t('lastModifiedAt', 'Last modified') + ': ' + modified.atLabel
					: ''
			);
			if (modified.atLabel) {
				pushChip(
					t('lastModifiedAt', 'Last modified'),
					modified.atLabel,
					'modifiedAt'
				);
			}
		}

		if (!chips.length) {
			return;
		}

		var wrap = createEl('div', {
			className: 'wtt-object-view__meta wtt-object-view__meta--pills',
			role: 'group',
			'aria-label': t('nodeMeta', 'Meta'),
		});
		var strip = createEl('div', {
			className:
				'wtt-form__meta-strip wtt-form__meta-strip--static wtt-object-view__meta-strip',
		});
		chips.forEach(function (chip) {
			var text = chip.label
				? chip.label + ': ' + chip.value
				: chip.value;
			var elChip = createEl('span', {
				className: 'wtt-form__meta-static',
				text: text,
				title: chip.title || '',
			});
			if (chip.metaKey) {
				elChip.setAttribute('data-wtt-meta', chip.metaKey);
			}
			strip.appendChild(elChip);
		});
		wrap.appendChild(strip);
		root.appendChild(wrap);
	}

	function fieldFromProp(prop) {
		return {
			id: prop.id != null ? prop.id : prop.name,
			name: prop.name || '',
			typeKey: prop.typeKey || prop.typeName || 'text',
			typeName: prop.typeName || '',
			typeId: prop.typeId || 0,
			multiplicity: prop.multiplicity || '0..*',
			fieldMultiplicity:
				prop.fieldMultiplicity || prop.multiplicity || '0..*',
			readonly: !!prop.readonly,
			fixedMode: prop.fixedMode || '',
			fixedOptions: Array.isArray(prop.fixedOptions)
				? prop.fixedOptions.slice()
				: [],
			choiceDepth:
				prop.choiceDepth != null ? parseInt(prop.choiceDepth, 10) || 0 : 0,
			refScopeId:
				prop.refScopeId != null ? parseInt(prop.refScopeId, 10) || 0 : 0,
			nodeRefOptions: Array.isArray(prop.nodeRefOptions)
				? prop.nodeRefOptions.slice()
				: [],
			nodeRefCreateFields: Array.isArray(prop.nodeRefCreateFields)
				? prop.nodeRefCreateFields.slice()
				: [],
			mediaConfig:
				prop.mediaConfig && typeof prop.mediaConfig === 'object'
					? prop.mediaConfig
					: null,
			dateConfig: prop.dateConfig || null,
			intConfig: prop.intConfig || null,
			displayFormat:
				prop.displayFormat != null
					? String(prop.displayFormat)
					: prop.intConfig && prop.intConfig.displayFormat
						? String(prop.intConfig.displayFormat)
						: '',
			quantitySchema:
				prop.quantitySchema && typeof prop.quantitySchema === 'object'
					? prop.quantitySchema
					: null,
			typeProperties: Array.isArray(prop.typeProperties)
				? prop.typeProperties.slice()
				: [],
		};
	}

	/**
	 * Serialize many-cell values back to a Model_Data store string.
	 * @param {string[]} vals
	 * @return {string}
	 */
	function encodeManyStoreValues(vals) {
		var cleaned = (vals || []).map(function (v) {
			return v == null ? '' : String(v);
		});
		while (cleaned.length && cleaned[cleaned.length - 1] === '') {
			cleaned.pop();
		}
		if (!cleaned.length) {
			return '';
		}
		if (cleaned.length === 1) {
			return cleaned[0];
		}
		try {
			return JSON.stringify(cleaned);
		} catch (e) {
			return cleaned.join(',');
		}
	}

	/**
	 * Multiplicity > 1 = list of the attribute's type → collection Table(n) (OQ-R8).
	 * Related Model_Data (Q97): edit via onRelatedFieldInput / onAddRelatedLine — not host blob.
	 *
	 * @param {HTMLElement} section
	 * @param {Array} manyProps
	 * @param {{
	 *   readonly?: boolean,
	 *   referenceMode?: string,
	 *   onFieldInput?: function,
	 *   onRelatedFieldInput?: function,
	 *   onAddRelatedLine?: function
	 * }} [opts]
	 */
	function appendManyTable(section, manyProps, opts) {
		opts = opts || {};
		var readonly = !!opts.readonly;
		var onRelatedFieldInput =
			typeof opts.onRelatedFieldInput === 'function'
				? opts.onRelatedFieldInput
				: null;
		var onAddRelatedLine =
			typeof opts.onAddRelatedLine === 'function'
				? opts.onAddRelatedLine
				: null;
		var stack = createEl('div', {
			className: 'wtt-object-view__many-stack',
		});
		(manyProps || []).forEach(function (prop) {
			if (!prop || typeof prop !== 'object') {
				return;
			}
			var attrs = schemaFieldsForManyProp(prop);
			var usesRelated = propUsesRelatedInstances(prop);
			/*
			 * Related Mult many: Preferred paints unit cells inside the collection
			 * Table; edit goes to child Model_Data via callbacks (not host JSON).
			 */
			var relatedEditable =
				usesRelated &&
				!readonly &&
				!prop.readonly &&
				(!!onRelatedFieldInput || !!onAddRelatedLine);
			var rowReadonly =
				readonly || !!prop.readonly || (usesRelated && !relatedEditable);
			var instances = instancesForManyProp(prop, attrs, rowReadonly);
			var hostField = fieldFromProp(prop);

			var item = createEl('div', {
				className:
					'wtt-object-view__many-item' +
					(usesRelated ? ' wtt-object-view__many-item--related' : ''),
			});
			var titleRow = createEl('div', {
				className: 'wtt-object-view__many-title-row',
			});
			titleRow.appendChild(
				createEl('h5', {
					className: 'wtt-object-view__many-title',
					text: prop.name || '—',
				})
			);
			if (relatedEditable && onAddRelatedLine) {
				titleRow.appendChild(
					createEl('button', {
						type: 'button',
						className:
							'button button-small wtt-object-view__add-line',
						text: t('addLine', 'Add line'),
						onClick: function (e) {
							if (e && e.preventDefault) {
								e.preventDefault();
							}
							onAddRelatedLine(prop);
						},
					})
				);
			}
			item.appendChild(titleRow);
			if (usesRelated && relatedEditable) {
				item.appendChild(
					createEl('p', {
						className: 'description wtt-object-view__related-hint',
						text: t(
							'relatedLinesHint',
							'Composition/aggregation Mult many rows for this instance (not a global orphan list).'
						),
					})
				);
			}
			item.appendChild(
				renderTable(instances, {
					readonly: rowReadonly,
					referenceMode: opts.referenceMode,
					attributes: attrs,
					className: 'wtt-object-view__table',
					emptyMessage: usesRelated
						? t('noRelatedLines', 'No related lines yet.')
						: t('tableEmpty', 'No data available.'),
					onFieldInput: rowReadonly
						? null
						: function (field, next, instance, rowIndex) {
								var idKey = valueKey(field);
								if (!instance.values) {
									instance.values = {};
								}
								instance.values[idKey] =
									next == null ? '' : String(next);
								if (usesRelated) {
									if (
										!instance.id ||
										typeof onRelatedFieldInput !== 'function'
									) {
										return;
									}
									onRelatedFieldInput(
										prop,
										field,
										next,
										instance,
										rowIndex
									);
									return;
								}
								if (typeof opts.onFieldInput === 'function') {
									opts.onFieldInput(
										hostField,
										encodeManyInstances(instances, attrs),
										null,
										rowIndex
									);
								}
						  },
				})
			);
			stack.appendChild(item);
		});
		section.appendChild(stack);
	}

	/**
	 * Q97 related-dataset Mult many (Bauteilliste → Position, …).
	 * @param {object} prop
	 * @return {boolean}
	 */
	function propUsesRelatedInstances(prop) {
		if (!prop || typeof prop !== 'object') {
			return false;
		}
		return (
			!!prop.usesRelatedInstances ||
			!!prop.isRelatedDataset ||
			(Array.isArray(prop.relatedInstances) &&
				prop.relatedInstances.length > 0)
		);
	}

	/**
	 * Columns for a many-valued attribute: type schema when present, else the field.
	 * @param {object} prop
	 * @return {Array}
	 */
	function schemaFieldsForManyProp(prop) {
		var typeProps = Array.isArray(prop.typeProperties)
			? prop.typeProperties
			: [];
		if (typeProps.length) {
			return typeProps.map(function (p) {
				return fieldFromProp(p);
			});
		}
		return [fieldFromProp(prop)];
	}

	/**
	 * @param {Array} attrs
	 * @return {object}
	 */
	function emptyRowValues(attrs) {
		var values = {};
		(attrs || []).forEach(function (field) {
			values[valueKey(field)] = '';
		});
		return values;
	}

	/**
	 * Decode one list-row store string into values map.
	 * @param {Array} attrs
	 * @param {string} raw
	 * @return {object}
	 */
	function rowValuesFromStore(attrs, raw) {
		var s = raw == null ? '' : String(raw).trim();
		var values = emptyRowValues(attrs);
		if (!s) {
			return values;
		}
		if (s.charAt(0) === '{') {
			try {
				var obj = JSON.parse(s);
				if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
					Object.keys(obj).forEach(function (k) {
						values[String(k)] =
							obj[k] == null ? '' : String(obj[k]);
					});
					return values;
				}
			} catch (e) {
				/* fall through to scalar */
			}
		}
		if (attrs.length === 1) {
			values[valueKey(attrs[0])] = s;
		}
		return values;
	}

	/**
	 * @param {object} prop
	 * @param {Array} attrs
	 * @param {boolean} readonly
	 * @return {Array}
	 */
	function instancesForManyProp(prop, attrs, readonly) {
		/*
		 * Q97 / OQ-R8: Mult > 1 related Model_Data → collection Table of linked
		 * rows. Prefer relatedInstances; never invent an inline spare host blob.
		 * New lines come from create_linked (Add line), not encodeManyInstances.
		 */
		if (propUsesRelatedInstances(prop)) {
			var related = Array.isArray(prop.relatedInstances)
				? prop.relatedInstances
				: [];
			if (!related.length) {
				return [];
			}
			return related.map(function (row) {
				var vals =
					row && row.values && typeof row.values === 'object'
						? Object.assign({}, row.values)
						: emptyRowValues(attrs);
				return {
					id: row && row.id ? String(row.id) : '',
					attributes: attrs,
					values: vals,
					structureId:
						row && row.structureId != null
							? parseInt(row.structureId, 10) || 0
							: parseInt(prop.typeId, 10) || 0,
				};
			});
		}

		var raws = manyPropStoreValues(prop);
		var list = raws.map(function (raw) {
			return {
				attributes: attrs,
				values: rowValuesFromStore(attrs, raw),
			};
		});
		if (!list.length) {
			list.push({
				attributes: attrs,
				values: emptyRowValues(attrs),
			});
		}
		/* Spare empty row so the user can add another list entry. */
		if (!readonly) {
			list.push({
				attributes: attrs,
				values: emptyRowValues(attrs),
			});
		}
		return list;
	}

	/**
	 * Encode Table(n) instances back to the host attribute store string.
	 * @param {Array} instances
	 * @param {Array} attrs
	 * @return {string}
	 */
	function encodeManyInstances(instances, attrs) {
		attrs = attrs || [];
		var structured = attrs.length > 1;
		var singleKey = attrs.length === 1 ? valueKey(attrs[0]) : '';
		var rows = [];
		(instances || []).forEach(function (inst) {
			var vals = (inst && inst.values) || {};
			if (structured) {
				var obj = {};
				var any = false;
				attrs.forEach(function (field) {
					var k = valueKey(field);
					var v = vals[k] != null ? String(vals[k]) : '';
					obj[k] = v;
					if (v !== '') {
						any = true;
					}
				});
				if (any) {
					rows.push(obj);
				}
				return;
			}
			var scalar = vals[singleKey] != null ? String(vals[singleKey]) : '';
			if (scalar !== '') {
				rows.push(scalar);
			}
		});
		if (!rows.length) {
			return '';
		}
		if (!structured && rows.length === 1) {
			return rows[0];
		}
		try {
			return JSON.stringify(rows);
		} catch (e) {
			return structured ? '' : rows.join(',');
		}
	}

	/**
	 * Mount Object View into a host element (block editor preview / mirror of SSR).
	 *
	 * Canonical form layout: meta strip → Form (Mult≤1) → Table (Mult many).
	 *
	 * @param {HTMLElement} host
	 * @param {object|null} view Object_Render get_view DTO
	 * @param {{
	 *   layout?: string,
	 *   renderDepth?: number,
	 *   referenceMode?: string,
	 *   readonly?: boolean,
	 *   mode?: 'edit'|'display',
	 *   onFieldInput?: function,
	 *   onRelatedFieldInput?: function,
	 *   onAddRelatedLine?: function
	 * }} [options]
	 * TODO(per-attribute): individual attributes may later carry their own
	 * renderDepth / referenceMode overrides — keep mount options as the block-
	 * level defaults until that UI exists.
	 */
	function mount(host, view, options) {
		options = options || {};
		if (!host) {
			return;
		}
		host.textContent = '';
		var layout = resolveLayout(
			options.layout != null ? options.layout : view && view.layout,
			view
		);
		var depth = normalizeRenderDepth(
			options.renderDepth != null
				? options.renderDepth
				: view && view.renderDepth
		);
		var refMode = normalizeReferenceMode(
			options.referenceMode || (view && view.referenceMode) || 'link'
		);
		var readonly =
			options.readonly != null
				? !!options.readonly
				: String(options.mode || 'display') !== 'edit';
		var onFieldInput =
			typeof options.onFieldInput === 'function'
				? options.onFieldInput
				: null;
		var paintOpts = {
			readonly: readonly,
			referenceMode: refMode,
			onFieldInput: onFieldInput,
			onRelatedFieldInput:
				typeof options.onRelatedFieldInput === 'function'
					? options.onRelatedFieldInput
					: null,
			onAddRelatedLine:
				typeof options.onAddRelatedLine === 'function'
					? options.onAddRelatedLine
					: null,
		};

		if (!view) {
			host.appendChild(
				createEl('p', {
					className: 'wtt-object-view__empty',
					text: t('empty', 'Select a node in the sidebar.'),
				})
			);
			return;
		}

		var root = createEl('div', {
			className:
				'wtt-object-view wtt-object-view--layout-' +
				layout +
				' wtt-object-view--depth-' +
				depth +
				' wtt-object-view--ref-' +
				refMode +
				(readonly ? ' is-display' : ' is-edit'),
		});
		root.setAttribute('data-wtt-render-depth', String(depth));
		root.setAttribute('data-wtt-reference-mode', refMode);

		var header = createEl('header', { className: 'wtt-object-view__header' });
		header.appendChild(
			createEl('h3', {
				className: 'wtt-object-view__title',
				text: view.name || '—',
			})
		);
		if (view.path) {
			header.appendChild(
				createEl('p', {
					className: 'wtt-object-view__path',
					text: String(view.path),
				})
			);
		}
		root.appendChild(header);
		appendMetaStrip(root, view);

		/* Depth 0 = meta-only (header + pills). */
		if (depth < 1) {
			host.appendChild(root);
			return;
		}

		var section = createEl('section', {
			className: 'wtt-object-view__properties',
			'aria-label': t('properties', 'Properties'),
		});

		var parts = partitionViewProperties(view);
		var allProps =
			(view && Array.isArray(view.properties) ? view.properties : []) || [];

		if (!allProps.length) {
			if (
				layout === 'EmbeddedRenderer' &&
				Array.isArray(view.embedChoiceOptions) &&
				view.embedChoiceOptions.length
			) {
				section.appendChild(
					renderEmbed({
						choiceOptions: view.embedChoiceOptions,
						value:
							view.instanceValues &&
							typeof view.instanceValues === 'object' &&
							view.instanceValues.__embed != null
								? String(view.instanceValues.__embed)
								: '',
						readonly: !!paintOpts.readonly,
						className: 'wtt-object-view__embed',
						onChange: paintOpts.onEmbedChange || null,
					})
				);
			} else {
				section.appendChild(
					createEl('p', {
						className: 'wtt-object-view__empty',
						text: t('noProperties', 'This node has no attributes.'),
					})
				);
			}
		} else {
			/* Singles follow layout; multi-value attributes always render as a table. */
			if (parts.single.length) {
				section.appendChild(
					createEl('h4', {
						className: 'wtt-object-view__section-title',
						text: t('properties', 'Properties'),
					})
				);
				if (layout === 'TableRenderer') {
					section.appendChild(
						renderTable([instanceFromView(view, parts.single)], {
							readonly: paintOpts.readonly,
							referenceMode: paintOpts.referenceMode,
							onFieldInput: paintOpts.onFieldInput,
							className: 'wtt-object-view__table',
						})
					);
				} else if (
					layout === 'CompactRenderer' ||
					layout === 'CompactVerticalRenderer'
				) {
					section.appendChild(
						renderCompact(instanceFromView(view, parts.single), {
							readonly: paintOpts.readonly,
							referenceMode: paintOpts.referenceMode,
							onFieldInput: paintOpts.onFieldInput,
							orientation:
								layout === 'CompactVerticalRenderer'
									? 'vertical'
									: 'horizontal',
							className: 'wtt-object-view__compact',
						})
					);
				} else if (layout === 'EmbeddedRenderer') {
					section.appendChild(
						renderEmbed({
							choiceOptions: Array.isArray(view.embedChoiceOptions)
								? view.embedChoiceOptions
								: [],
							value:
								view.instanceValues &&
								typeof view.instanceValues === 'object' &&
								view.instanceValues.__embed != null
									? String(view.instanceValues.__embed)
									: '',
							readonly: !!paintOpts.readonly,
							className: 'wtt-object-view__embed',
							onChange: paintOpts.onEmbedChange || null,
						})
					);
				} else {
					section.appendChild(
						renderForm(instanceFromView(view, parts.single), {
							readonly: paintOpts.readonly,
							referenceMode: paintOpts.referenceMode,
							onFieldInput: paintOpts.onFieldInput,
							className: 'wtt-object-view__form-surface',
						})
					);
				}
			}
			if (parts.many.length) {
				section.appendChild(
					createEl('h4', {
						className: 'wtt-object-view__section-title',
						text: t('propertiesMany', 'Multi-value attributes'),
					})
				);
				appendManyTable(section, parts.many, paintOpts);
			}
		}

		root.appendChild(section);
		host.appendChild(root);
	}

	global.WTTObjectRender = {
		configure: configure,
		mount: mount,
		normalizeAttributes: normalizeAttributes,
		normalizeRenderDepth: normalizeRenderDepth,
		normalizeReferenceMode: normalizeReferenceMode,
		instanceFromView: instanceFromView,
		renderForm: renderForm,
		renderTable: renderTable,
		renderCompact: renderCompact,
		renderEmbed: renderEmbed,
		setSchemaLoader: setSchemaLoader,
		parseEmbedStore: parseEmbedStore,
		encodeEmbedStore: encodeEmbedStore,
		buildExampleInstance: buildExampleInstance,
		buildExampleList: buildExampleList,
	};
})(typeof window !== 'undefined' ? window : this);
