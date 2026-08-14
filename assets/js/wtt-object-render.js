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
			/*
			 * Keep display type name separate from registry typeKey.
			 * Overwriting typeName with typeKey broke nested structure Q117
			 * host resolve (slug instead of type Presentation / label).
			 */
			var typeDisplayName = String(
				attr.typeLabel || attr.typeDisplayName || attr.typeName || ''
			).trim();
			if (
				typeDisplayName &&
				typeDisplayName.toLowerCase() === typeKey &&
				typeDisplayName === typeDisplayName.toLowerCase()
			) {
				/* Only a lowercased key was stored — keep key; PHP typeLabel preferred. */
				typeDisplayName = typeDisplayName;
			}
			var field = {
				id: attr.id,
				name: attr.name || '',
				displayName: attr.name || '',
				description: attr.description || '',
				shortDescription:
					attr.shortDescription != null ? String(attr.shortDescription) : '',
				typeKey: typeKey,
				typeName: typeDisplayName || typeKey,
				typeLabel: String(attr.typeLabel || typeDisplayName || '').trim(),
				typeDisplayName: typeDisplayName || '',
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
				textareaConfig: attr.textareaConfig || null,
				intConfig: attr.intConfig || null,
				validators: Array.isArray(attr.validators)
					? attr.validators.slice()
					: Array.isArray(attr.typeValidators)
						? attr.typeValidators.slice()
						: [],
				typeValidators: Array.isArray(attr.typeValidators)
					? attr.typeValidators.slice()
					: [],
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
				typePresentation:
					attr.typePresentation && typeof attr.typePresentation === 'object'
						? attr.typePresentation
						: null,
				typeShortDescription: String(
					attr.typeShortDescription != null
						? attr.typeShortDescription
						: ''
				).trim(),
				compactShowLabels:
					attr.compactShowLabels !== false &&
					attr.compactShowLabels !== 0 &&
					attr.compactShowLabels !== '0',
				binding: attr.binding || '',
				isRelatedDataset: !!attr.isRelatedDataset,
				usesRelatedInstances: !!attr.usesRelatedInstances,
				relatedInstances: Array.isArray(attr.relatedInstances)
					? attr.relatedInstances.slice()
					: [],
				typePreferredRender: String(attr.typePreferredRender || ''),
				preferredRender: String(
					attr.preferredRender || attr.typePreferredRender || ''
				),
			};
			/*
			 * node_presentation: always attach Q117 meta — even when Festwert is set.
			 * Default `%` on Unit used to take the fest branch and skip presentationConfig,
			 * so Preview fell back to the host form name (Tolerance).
			 */
			if (
				typeKey === 'node_presentation' ||
				typeKey === 'display_node_name' ||
				typeKey.indexOf('node_presentation') !== -1 ||
				typeKey.indexOf('display_node_name') !== -1
			) {
				var pCtx = 'form';
				if (attr.presentationConfig && attr.presentationConfig.context) {
					pCtx = String(attr.presentationConfig.context)
						.trim()
						.toLowerCase();
				} else if (
					attr.typeExtras &&
					attr.typeExtras.presentationContext
				) {
					pCtx = String(attr.typeExtras.presentationContext)
						.trim()
						.toLowerCase();
				}
				if (pCtx === 'name') {
					pCtx = 'form';
				}
				field.presentationConfig = attr.presentationConfig
					? Object.assign({}, attr.presentationConfig, { context: pCtx })
					: { context: pCtx };
				field.hostPresentation =
					attr.hostPresentation || attr.presentation || null;
				field.hostShortDescription = String(
					attr.hostShortDescription || ''
				).trim();
				field.hostName = String(
					attr.hostName ||
						attr.hostDisplayName ||
						attr.nodeName ||
						''
				).trim();
				var map = presentationMapLoose(field.hostPresentation);
				var fromPres =
					map && map[pCtx] != null && String(map[pCtx]).trim() !== ''
						? String(map[pCtx]).trim()
						: '';
				if (!fromPres && (pCtx === 'symbol' || pCtx === 'table')) {
					fromPres = field.hostShortDescription;
				}
				if (
					!fromPres &&
					pCtx !== 'symbol' &&
					pCtx !== 'table' &&
					pCtx !== 'icon'
				) {
					fromPres = field.hostName;
				}
				if (!fromPres && pCtx === 'icon') {
					fromPres = '—';
				}
				if (fromPres) {
					field.sample = fromPres;
				} else if (fest) {
					/* Festwert when host presentation slot is empty (Organisation name, …). */
					field.sample = fest;
				} else if (
					!field.sample &&
					Sample &&
					typeof Sample.forAttribute === 'function' &&
					pCtx !== 'icon'
				) {
					field.sample = String(
						Sample.forAttribute(
							Object.assign({}, field, {
								hostName: field.hostName || '',
								hostPresentation: field.hostPresentation,
								presentationConfig: field.presentationConfig,
								hostShortDescription: field.hostShortDescription,
							})
						) ||
							field.hostName ||
							(pCtx === 'symbol' || pCtx === 'table' ? '—' : 'Node name')
					);
				} else if (
					!field.sample &&
					(pCtx === 'symbol' || pCtx === 'table')
				) {
					field.sample = field.hostName || '—';
				} else if (!field.sample && pCtx !== 'icon') {
					field.sample = field.hostName || 'Node name';
				}
			} else if (fest) {
				/* Festwert wins over generic type sample (e.g. Einheit → Ohm). */
				field.sample = fest;
			} else if (!field.sample && Sample && !shouldSkipSampleFill(field)) {
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
		var pref = normalizeFieldPreferredPaintId(field);
		var hostAttrs = [];
		if (Array.isArray(field && field.attributes) && field.attributes.length) {
			hostAttrs = field.attributes;
		} else if (
			Array.isArray(field && field.typeProperties) &&
			field.typeProperties.length
		) {
			hostAttrs = field.typeProperties;
		}
		return Object.assign({}, field, {
			sample:
				value != null && String(value) !== ''
					? String(value)
					: field.sample || '',
			preferredRender: pref || field.preferredRender || '',
			typePreferredRender:
				pref || field.typePreferredRender || field.preferredRender || '',
			attributes: hostAttrs,
		});
	}

	/**
	 * Preferred wire (QuantityRenderer) → Registry paint id (quantity).
	 */
	function normalizePaintId(raw) {
		var key = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (!key) {
			return '';
		}
		var wireToShort = {
			quantityrenderer: 'quantity',
			unitrenderer: 'unit',
			basiseinheit: 'unit',
			intrenderer: 'int',
			doublerenderer: 'double',
			textrenderer: 'text',
			textarearenderer: 'textarea',
			charrenderer: 'char',
			boolrenderer: 'bool',
			emailrenderer: 'email',
			daterenderer: 'date',
			mediarenderer: 'media',
			displaynodenamerenderer: 'node_presentation',
			nodepresentationrenderer: 'node_presentation',
			noderefrenderer: 'node_ref',
			formrenderer: 'form',
			tablerenderer: 'table',
			compactrenderer: 'compact',
			compactverticalrenderer: 'compactvertical',
			embeddedrenderer: 'embed',
			multisteprenderer: 'multistep',
			multistep: 'multistep',
		};
		if (wireToShort[key]) {
			return wireToShort[key];
		}
		if (key.length > 8 && key.slice(-8) === 'renderer') {
			return key.slice(0, -8);
		}
		return key;
	}

	/**
	 * Field paint Preferred (nested attribute cell).
	 * Slot / walk override wins — including Form/Table/Compact for structure embeds.
	 * Host Preview surface stays host Preferred only (tree-admin); this is field paint.
	 */
	function resolveFieldPreferredPaint(field) {
		var typePref = normalizePaintId(field && field.typePreferredRender);
		var slotPref = normalizePaintId(field && field.preferredRender);
		if (slotPref) {
			return slotPref;
		}
		return typePref || '';
	}

	function normalizeFieldPreferredPaintId(field) {
		return resolveFieldPreferredPaint(field);
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
		if (
			Array.isArray(field.embedChoiceOptions) &&
			field.embedChoiceOptions.length
		) {
			return field.embedChoiceOptions;
		}
		return [];
	}

	function prefersChildListPaint(field) {
		var p = normalizePaintId(
			(field && (field.preferredRender || field.typePreferredRender)) || ''
		);
		return p === 'childlist' || p === 'child_list';
	}

	function isCatalogChoiceField(field) {
		if (!field) {
			return false;
		}
		/*
		 * ChildList Preferred = pick hierarchy children (Währung, Praefix, Konstanten, …).
		 * Same paint path — no per-name CatalogChoice product axis.
		 */
		if (prefersChildListPaint(field)) {
			return true;
		}
		/* PHP fixedMode wins — Bauformen may ship typeProperties for heirs but paint as choice. */
		if (String(field.fixedMode || '') === 'catalog') {
			return true;
		}
		/* Structure types embed Form/Table of typeProperties — never CatalogChoice. */
		if (isStructureField(field)) {
			return false;
		}
		return catalogOptionsForField(field).length > 0;
	}

	/**
	 * Whether the field embeds its type's attribute schema (Form/Table).
	 * CatalogChoice hosts (fixedMode=catalog) keep typeProperties for inheritance /
	 * Options walk but must not force Structure embed over the chooser (Bauformen).
	 *
	 * @param {object|null} field
	 * @return {boolean}
	 */
	function isStructureField(field) {
		if (
			!field ||
			!Array.isArray(field.typeProperties) ||
			field.typeProperties.length === 0
		) {
			return false;
		}
		if (prefersChildListPaint(field)) {
			return false;
		}
		if (String(field.fixedMode || '') === 'catalog') {
			return false;
		}
		return true;
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
		/* Flat ListChooser: leaf name (not ancestor path). Path stays in title/tree. */
		return (
			opt.name ||
			opt.path ||
			String(opt.id != null ? opt.id : '')
		);
	}

	function catalogEmptyOptionLabel(field) {
		var i18n = global.wttTree && global.wttTree.i18n ? global.wttTree.i18n : {};
		if (isPrefixChoiceField(field)) {
			/* Visible: — (unitConvNone); tooltip: “No prefix” (unitConvNoneTitle). */
			return i18n.unitConvNone || '—';
		}
		/* Visible empty: em dash (Q116); never the word “None”. */
		return i18n.catalogChoiceNone || i18n.unitConvNone || '—';
	}

	/**
	 * Prefix (and other zero-lower) choices: never invent a sample — Meter without Milli,
	 * Ohm without Kilo is valid.
	 */
	function shouldSkipSampleFill(field) {
		if (!field) {
			return false;
		}
		if (isPrefixChoiceField(field)) {
			return true;
		}
		return fieldListSelectAllowsEmpty(field);
	}

	/**
	 * Empty option always for prefixes; also for optional Mult / empty catalog.
	 */
	function catalogChoiceNeedsEmptyOption(field, optionCount) {
		/* Q116: Mult drives empty — Praefix is not special-cased. */
		if (fieldListSelectAllowsEmpty(field)) {
			return true;
		}
		return !(parseInt(optionCount, 10) > 0);
	}

	/**
	 * Nested tree list for CatalogChoice depth ≥ 2 (vanilla mirror of ModelTreeChooser).
	 * @param {Array} options
	 * @param {number} selectedId
	 * @param {function} onPick
	 * @param {{ allowEmpty?: boolean, emptyLabel?: string }} [chooserOpts]
	 */
	function renderCatalogTreeChooser(options, selectedId, onPick, chooserOpts) {
		chooserOpts = chooserOpts || {};
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
		if (chooserOpts.allowEmpty) {
			host.appendChild(
				createEl('button', {
					type: 'button',
					className: 'button-link wtt-object-render__catalog-clear',
					text: chooserOpts.emptyLabel || 'None',
					onClick: function () {
						if (typeof onPick === 'function') {
							onPick(0);
						}
					},
				})
			);
		}
		host.appendChild(tree);
		return host;
	}

	function paintCatalogChoice(field, value, opts) {
		opts = opts || {};
		var options = catalogOptionsForField(field);
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var selected = value != null ? String(value) : '';
		var allowEmpty = catalogChoiceNeedsEmptyOption(field, options.length);
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
			var preferSymbolRo = isPrefixChoiceField(field);
			var labelRo = '';
			if (selected) {
				options.forEach(function (opt) {
					if (!opt || opt.id == null || labelRo) {
						return;
					}
					var id = String(opt.id);
					var name = opt.name != null ? String(opt.name) : '';
					var letter = catalogOptionLabel(opt, true);
					if (
						id === selected ||
						(name && name === selected) ||
						(letter && letter === selected)
					) {
						labelRo = catalogOptionLabel(opt, preferSymbolRo);
					}
				});
			}
			if (!labelRo) {
				labelRo = resolveCatalogLabel(options, selected);
			}
			return createEl('span', {
				className: 'wtt-object-render__display',
				text: labelRo || '—',
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
			var emptyLabel = catalogEmptyOptionLabel(field);
			/* Drop sample/store values that are not real option ids (e.g. letter "m"). */
			if (selected) {
				var matched = false;
				options.forEach(function (opt) {
					if (!opt || opt.id == null) {
						return;
					}
					var id = String(opt.id);
					var name = opt.name != null ? String(opt.name) : '';
					var letter = catalogOptionLabel(opt, true);
					if (
						id === selected ||
						(name && name === selected) ||
						(letter && letter === selected)
					) {
						matched = true;
						selected = id;
					}
				});
				if (!matched && allowEmpty) {
					selected = '';
				}
			}
			if (allowEmpty) {
				var emptyOpt = createEl('option', {
					value: '',
					text: emptyLabel,
				});
				emptyOpt.title = preferSymbol
					? (global.wttTree &&
							global.wttTree.i18n &&
							global.wttTree.i18n.unitConvNoneTitle) ||
					  'No prefix'
					: emptyLabel;
				if (!selected) {
					emptyOpt.selected = true;
				}
				select.appendChild(emptyOpt);
			}
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
				if (id === selected) {
					option.selected = true;
				}
				select.appendChild(option);
			});
			/* Required (non-prefix): no match → first real option. */
			if (!allowEmpty && !selected && select.options.length) {
				var firstReal = null;
				var oi;
				for (oi = 0; oi < select.options.length; oi++) {
					if (String(select.options[oi].value || '') !== '') {
						firstReal = select.options[oi];
						break;
					}
				}
				if (firstReal) {
					firstReal.selected = true;
					selected = firstReal.value;
				}
			}
			if (typeof opts.onInput === 'function') {
				select.addEventListener('change', function () {
					opts.onInput(select.value);
				});
			}
			applySoleRequiredListLock(select, realCount, {
				allowEmpty: allowEmpty,
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
			},
			{ allowEmpty: allowEmpty, emptyLabel: catalogEmptyOptionLabel(field) }
		);
	}

	/**
	 * Structure-type host for nested embeds (any typed structure attribute).
	 * Q117 / name come from the *type* node — never the outer Form host.
	 *
	 * @param {object|null} field
	 * @return {{ name: string, shortDescription: string, presentation: object|null }}
	 */
	function structureTypeHostFromField(field) {
		var typeKey = String((field && field.typeKey) || '')
			.trim()
			.toLowerCase();
		var label = String(
			(field &&
				(field.typeLabel || field.typeDisplayName || field.typeName)) ||
				''
		).trim();
		if (
			label &&
			typeKey &&
			label.toLowerCase() === typeKey &&
			label === label.toLowerCase()
		) {
			label = String((field && field.schemaName) || '').trim() || label;
		}
		return {
			name: label,
			shortDescription: String(
				(field && field.typeShortDescription) || ''
			).trim(),
			/* Never fall back to outer hostPresentation here. */
			presentation: (field && field.typePresentation) || null,
		};
	}

	function isNodePresentationField(field) {
		var typeKey = String((field && field.typeKey) || '')
			.trim()
			.toLowerCase();
		return (
			typeKey === 'node_presentation' ||
			typeKey === 'display_node_name' ||
			typeKey.indexOf('node_presentation') !== -1 ||
			typeKey.indexOf('display_node_name') !== -1
		);
	}

	/**
	 * Seed nested structure store from type schema (Preferred Compact/Form/Table).
	 * Avoid Sample.forAttribute(typeKey=organisation) → scalar company name.
	 *
	 * @param {object} field
	 * @param {number} [variantIndex]
	 * @return {string}
	 */
	function seedStructureFieldStore(field, variantIndex) {
		var host = structureTypeHostFromField(field);
		var typeProps = Array.isArray(field && field.typeProperties)
			? field.typeProperties.filter(function (tp) {
					return tp && !tp.hidden;
			  })
			: [];
		if (!typeProps.length) {
			return '';
		}
		var nested = buildExampleInstance(
			{
				name: host.name,
				shortDescription: host.shortDescription,
				presentation: host.presentation,
			},
			typeProps,
			variantIndex
		);
		return encodeStructureStore(
			(nested && nested.values) || {},
			(nested && nested.attributes) || typeProps
		);
	}

	/**
	 * Bindung SoT (Q111): Aggregation vs Composition storage chrome.
	 * Attribute::DEFAULT_BINDING = aggregation — empty binding counts as aggregation.
	 * @param {object|null} field
	 * @return {boolean}
	 */
	function isAggregationBinding(field) {
		var key = String(
			(field && (field.binding || field.multistepStorage)) || ''
		)
			.trim()
			.toLowerCase()
			.replace(/\s+/g, '_');
		if (
			key === 'besteht_aus' ||
			key === 'composition' ||
			key.indexOf('besteht') !== -1 ||
			key.indexOf('compos') !== -1
		) {
			return false;
		}
		return true;
	}

	/**
	 * @param {object|null} field
	 * @return {boolean}
	 */
	function isCompositionBinding(field) {
		return !isAggregationBinding(field);
	}

	/**
	 * Concrete Aggregation type id when there is no kind catalog (Q111).
	 * e.g. Organisation host — skip Multistep Phase A, open Model_Data Phase B.
	 *
	 * @param {object|null} field
	 * @param {object} [options]
	 * @return {number}
	 */
	function resolveSoleAggregationKindId(field, options) {
		options = options || {};
		var rootId =
			parseInt(options.rootId, 10) ||
			parseInt(field && field.fixedRootId, 10) ||
			parseInt(field && field.typeId, 10) ||
			0;
		var choices = Array.isArray(options.choiceOptions)
			? options.choiceOptions
			: catalogOptionsForField(field);
		if (choices.length === 1) {
			var only = parseInt(choices[0] && choices[0].id, 10) || 0;
			return only > 0 ? only : rootId;
		}
		if (choices.length === 0 && rootId > 0) {
			return rootId;
		}
		return 0;
	}

	/**
	 * Aggregation structure cell (Q111 / Q112):
	 * - Unbound + editable → Multistep Aggregation chooser (filter/search/create/bind)
	 * - Bound → type Preferred (Compact/Form/Table) readonly of that Model_Data
	 * Never composition-embed editable type schema (Name/E-Mail inputs) on the host slot.
	 *
	 * @param {object} field
	 * @param {string} value
	 * @param {object} opts
	 * @return {HTMLElement}
	 */
	function paintAggregationBound(field, value, opts) {
		opts = opts || {};
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var store = parseEmbedStore(value);
		var boundId = store.instanceId ? String(store.instanceId) : '';
		var typeHost = structureTypeHostFromField(field);
		var attrs = attachKindHostToAttributes(schemaFieldsForManyProp(field), {
			name: typeHost.name,
			shortDescription: typeHost.shortDescription,
			presentation: typeHost.presentation,
		});
		var related = Array.isArray(field && field.relatedInstances)
			? field.relatedInstances.filter(function (r) {
					return r && typeof r === 'object';
			  })
			: [];
		var allowsMany =
			!!(field && field.allowsMany) ||
			multiplicityAllowsMany(
				(field && (field.multiplicity || field.fieldMultiplicity)) || '1'
			);
		var hasBoundInstance = related.length > 0 || !!boundId;

		/* Unbound Editable → pick/search/bind — never schema composition inputs. */
		if (!readonly && !hasBoundInstance) {
			return paintEmbedField(
				field,
				value,
				Object.assign({}, opts, {
					binding: (field && field.binding) || 'aggregation',
					multistepStorage: 'aggregation',
				})
			);
		}

		/* Unbound Display → empty. */
		if (!hasBoundInstance) {
			return createEl('span', {
				className:
					'wtt-object-render__display wtt-object-render__aggregation-empty',
				text: '—',
			});
		}

		function instanceFromRelated(row, variantIndex) {
			var vals =
				row && row.values && typeof row.values === 'object'
					? Object.assign({}, row.values)
					: {};
			attrs.forEach(function (inner) {
				if (isNodePresentationField(inner)) {
					vals[valueKey(inner)] = resolveNodePresentationDisplay(
						inner,
						''
					);
				}
			});
			return {
				id: row && row.id ? String(row.id) : '',
				attributes: attrs,
				values: vals,
				compactShowLabels:
					field &&
					Object.prototype.hasOwnProperty.call(field, 'compactShowLabels')
						? !!field.compactShowLabels
						: true,
				structureId:
					row && row.structureId != null
						? parseInt(row.structureId, 10) || 0
						: parseInt((field && field.typeId) || 0, 10) || 0,
			};
		}

		var instances = [];
		if (related.length) {
			instances = related.map(function (row, i) {
				return instanceFromRelated(row, i);
			});
		} else {
			/*
			 * Bound id without relatedInstances payload (admin Preview session):
			 * show id chrome — Change re-opens Multistep; avoid fake schema samples.
			 */
			instances = [
				{
					id: boundId,
					attributes: attrs,
					values: {},
					compactShowLabels:
						field &&
						Object.prototype.hasOwnProperty.call(
							field,
							'compactShowLabels'
						)
							? !!field.compactShowLabels
							: true,
					structureId: parseInt((field && field.typeId) || 0, 10) || 0,
				},
			];
		}

		var layout = normalizeLayout(
			resolveFieldPreferredPaint(field) ||
				(field && (field.preferredRender || field.typePreferredRender)) ||
				'FormRenderer'
		);
		var showLabels = true;
		if (
			field &&
			Object.prototype.hasOwnProperty.call(field, 'compactShowLabels')
		) {
			showLabels = !!field.compactShowLabels;
		}
		/*
		 * Bound Aggregation Display = type Preferred readonly (Hide/RO already in attrs).
		 * Nested fields are never freely edited on the host — rebind only.
		 */
		var paintOpts = {
			readonly: true,
			referenceMode: opts.referenceMode,
			showLabels: showLabels,
			className:
				'wtt-object-render__structure-embed wtt-object-render__aggregation-bound',
			onFieldInput: null,
		};

		var display;
		if (
			!related.length &&
			boundId &&
			attrs.length === 0
		) {
			display = createEl('span', {
				className: 'wtt-object-render__display',
				text: boundId,
			});
		} else if (!related.length && boundId) {
			display = createEl('span', {
				className:
					'wtt-object-render__display wtt-object-render__aggregation-bound-id',
				text: boundId,
				title: t(
					'embedBoundHint',
					'Bound Model_Data instance (values load with related payload).'
				),
			});
		} else if (allowsMany || instances.length > 1 || layout === 'TableRenderer') {
			display = renderTable(instances, paintOpts);
		} else if (
			layout === 'CompactRenderer' ||
			layout === 'CompactVerticalRenderer'
		) {
			paintOpts.orientation =
				layout === 'CompactVerticalRenderer' ? 'vertical' : 'horizontal';
			display = renderCompact(instances[0], paintOpts);
		} else {
			display = renderForm(instances[0], paintOpts);
		}

		if (readonly) {
			return display;
		}

		/* Editable + bound: Preferred Display + Change (clear → Multistep chooser). */
		var wrap = createEl('div', {
			className: 'wtt-object-render__aggregation-slot',
		});
		wrap.appendChild(display);
		wrap.appendChild(
			createEl('button', {
				type: 'button',
				className:
					'button button-small wtt-object-render__embed-open wtt-object-render__aggregation-change',
				text: t('embedChangePart', 'Change…'),
				onClick: function (e) {
					e.preventDefault();
					if (typeof opts.onInput === 'function') {
						opts.onInput('');
					}
				},
			})
		);
		return wrap;
	}

	/**
	 * Embed the attribute type's schema via Preferred layout (Form/Compact/Table).
	 * Composition Bindung only — Aggregation uses paintAggregationBound (Q111).
	 *
	 * @param {object} field
	 * @param {string} value
	 * @param {object} opts
	 * @return {HTMLElement}
	 */
	function paintStructureEmbed(field, value, opts) {
		opts = opts || {};
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var typeHost = structureTypeHostFromField(field);
		var attrs = attachKindHostToAttributes(schemaFieldsForManyProp(field), {
			name: typeHost.name,
			shortDescription: typeHost.shortDescription,
			presentation: typeHost.presentation,
		});
		var raw = value != null ? String(value) : '';
		/*
		 * Scalar samples (MAP.organisation → "Muster GmbH") are not structure
		 * bags — rebuild from type schema so Kontakt Type uses Q117.
		 */
		if (raw && raw.charAt(0) !== '{' && attrs.length > 1) {
			raw = '';
		}
		var values = rowValuesFromStore(attrs, raw);
		attrs.forEach(function (inner) {
			if (!isNodePresentationField(inner)) {
				return;
			}
			values[valueKey(inner)] = resolveNodePresentationDisplay(inner, '');
		});
		var missingSample = false;
		attrs.forEach(function (inner) {
			if (isNodePresentationField(inner)) {
				return;
			}
			if (!values[valueKey(inner)]) {
				missingSample = true;
			}
		});
		if ((!raw || missingSample) && attrs.length) {
			var seeded = seedStructureFieldStore(field, 0);
			if (seeded) {
				var seededVals = rowValuesFromStore(attrs, seeded);
				attrs.forEach(function (inner) {
					var k = valueKey(inner);
					if (isNodePresentationField(inner)) {
						values[k] = resolveNodePresentationDisplay(inner, '');
						return;
					}
					if (!values[k] && seededVals[k]) {
						values[k] = seededVals[k];
					}
				});
			}
		}
		var instance = {
			attributes: attrs,
			values: values,
			compactShowLabels:
				field &&
				Object.prototype.hasOwnProperty.call(field, 'compactShowLabels')
					? !!field.compactShowLabels
					: true,
		};
		var layout = normalizeLayout(
			resolveFieldPreferredPaint(field) ||
				(field && (field.preferredRender || field.typePreferredRender)) ||
				'FormRenderer'
		);
		var showLabels = true;
		if (
			field &&
			Object.prototype.hasOwnProperty.call(field, 'compactShowLabels')
		) {
			showLabels = !!field.compactShowLabels;
		}
		var paintOpts = {
			readonly: readonly,
			referenceMode: opts.referenceMode,
			showLabels: showLabels,
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
		};
		if (layout === 'TableRenderer') {
			return renderTable([instance], paintOpts);
		}
		if (
			layout === 'CompactRenderer' ||
			layout === 'CompactVerticalRenderer'
		) {
			paintOpts.orientation =
				layout === 'CompactVerticalRenderer' ? 'vertical' : 'horizontal';
			return renderCompact(instance, paintOpts);
		}
		return renderForm(instance, paintOpts);
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
				/*
				 * Composition Multistep bag: { kindId, values } (≈ 0.0.552+).
				 * Aggregation may still use pick / instanceId.
				 */
				if (obj.kindId != null && String(obj.kindId).trim() !== '') {
					out.kindId = String(obj.kindId).trim();
				} else if (obj.k != null && String(obj.k).trim() !== '') {
					out.kindId = String(obj.k).trim();
				}
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
					if (!out.kindId) {
						out.kindId = p;
					}
				}
				out.pick = p || out.kindId || out.instanceId;
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

	function isMultistepPreferredField(field) {
		var key = String(
			(field && (field.preferredRender || field.typePreferredRender)) || ''
		)
			.trim()
			.toLowerCase();
		return (
			key === 'multisteprenderer' ||
			key === 'multistep' ||
			key === 'embeddedrenderer' ||
			key === 'embed' ||
			key === 'pick-fill' ||
			key === 'pick_fill' ||
			key === 'compact-embed'
		);
	}

	/** @deprecated Use isMultistepPreferredField */
	function isEmbedPreferredField(field) {
		return isMultistepPreferredField(field);
	}

	function resolveMultistepMode(options, field) {
		var raw =
			(options && (options.mode || options.multistepMode)) ||
			(field && field.multistepMode) ||
			'dialog';
		var key = String(raw)
			.trim()
			.toLowerCase();
		return key === 'inline' ? 'inline' : 'dialog';
	}

	/**
	 * Multistep Phase B storage chrome from Bindung (Q111 / Q112 ≈ 0.0.552).
	 * Aggregation → Model_Data list/search/create/bind.
	 * Composition → step fill in parent context (no Matches / Create-and-bind).
	 *
	 * @param {object} [options]
	 * @param {object} [field]
	 * @return {'aggregation'|'composition'}
	 */
	function resolveMultistepStorage(options, field) {
		var raw =
			(options && (options.multistepStorage || options.binding)) ||
			(field && (field.multistepStorage || field.binding)) ||
			'';
		var key = String(raw)
			.trim()
			.toLowerCase()
			.replace(/\s+/g, '_');
		if (
			key === 'aggregation' ||
			key.indexOf('aggreg') !== -1
		) {
			return 'aggregation';
		}
		if (
			key === 'besteht_aus' ||
			key === 'composition' ||
			key.indexOf('compos') !== -1 ||
			key.indexOf('besteht') !== -1
		) {
			return 'composition';
		}
		/*
		 * Empty Bindung: same law as isAggregationBinding (Attribute default =
		 * aggregation). Host-only Multistep without a field stays composition fill.
		 */
		if (field) {
			return isAggregationBinding(field) ? 'aggregation' : 'composition';
		}
		return 'composition';
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
			return Promise.resolve({
				attributes: [],
				name: '',
				shortDescription: '',
				presentation: null,
			});
		}
		var key = String(termId);
		if (schemaCache[key]) {
			return Promise.resolve(schemaCache[key]);
		}
		if (!schemaLoaderFn) {
			return Promise.resolve({
				attributes: [],
				name: '',
				shortDescription: '',
				presentation: null,
			});
		}
		return Promise.resolve(schemaLoaderFn(termId)).then(function (schema) {
			var normalized = {
				attributes: normalizeAttributes(
					(schema &&
						(schema.attributes ||
							schema.properties ||
							schema.fields)) ||
						[]
				),
				name: String((schema && schema.name) || '').trim(),
				shortDescription: String(
					(schema && schema.shortDescription) || ''
				).trim(),
				presentation:
					schema &&
					schema.presentation &&
					typeof schema.presentation === 'object'
						? schema.presentation
						: null,
				preferredRender: String(
					(schema && schema.preferredRender) || ''
				).trim(),
				compactShowLabels:
					!schema ||
					!Object.prototype.hasOwnProperty.call(
						schema,
						'compactShowLabels'
					)
						? true
						: !!schema.compactShowLabels,
			};
			schemaCache[key] = normalized;
			return normalized;
		});
	}

	/**
	 * Attach kind-host Q117 presentation onto attribute DTOs (Meter → symbol m).
	 *
	 * @param {Array} attrs
	 * @param {{ name?: string, shortDescription?: string, presentation?: object|null }} host
	 * @return {Array}
	 */
	function attachKindHostToAttributes(attrs, host) {
		host = host || {};
		var hostName = String(host.name || '').trim();
		var hostShort = String(host.shortDescription || '').trim();
		/*
		 * Node presentation DTO may be { values: { symbol, form, … } } —
		 * Compact/Display look up map[context], so unwrap like schemaPresentationMap.
		 */
		var hostPres = schemaPresentationMap({
			presentation: host.presentation || null,
			name: hostName,
		});
		if (!hostPres && host.presentation && typeof host.presentation === 'object') {
			hostPres = presentationMapLoose(host.presentation);
		}
		return (attrs || []).map(function (a) {
			if (!a) {
				return a;
			}
			var next = Object.assign({}, a);
			next.hostName = hostName || next.hostName || '';
			next.hostShortDescription =
				hostShort || next.hostShortDescription || '';
			if (hostPres) {
				next.hostPresentation = hostPres;
			}
			return next;
		});
	}

	/** Accept flat or { values } presentation bags; ignore unloaded drafts. */
	function presentationMapLoose(p) {
		if (!p || typeof p !== 'object') {
			return null;
		}
		if (Object.prototype.hasOwnProperty.call(p, 'loaded') && !p.loaded) {
			return null;
		}
		if (p.values && typeof p.values === 'object') {
			return p.values;
		}
		if (
			Object.prototype.hasOwnProperty.call(p, 'form') ||
			Object.prototype.hasOwnProperty.call(p, 'symbol') ||
			Object.prototype.hasOwnProperty.call(p, 'table') ||
			Object.prototype.hasOwnProperty.call(p, 'select') ||
			Object.prototype.hasOwnProperty.call(p, 'help') ||
			Object.prototype.hasOwnProperty.call(p, 'icon')
		) {
			return p;
		}
		return null;
	}

	/**
	 * Seed filter/create values for node_presentation (and other RO defaults)
	 * from the kind host — e.g. Meter + context symbol → "m".
	 *
	 * @param {Array} attrs
	 * @return {Object<string, string>}
	 */
	function seedKindFilterValues(attrs) {
		var values = {};
		(attrs || []).forEach(function (field) {
			if (!field) {
				return;
			}
			var typeKey = String(field.typeKey || '')
				.trim()
				.toLowerCase();
			var isPres =
				typeKey === 'node_presentation' ||
				typeKey === 'display_node_name' ||
				typeKey.indexOf('node_presentation') !== -1 ||
				typeKey.indexOf('display_node_name') !== -1;
			if (!isPres) {
				return;
			}
			var ctx = 'form';
			if (
				field.presentationConfig &&
				field.presentationConfig.context
			) {
				ctx = String(field.presentationConfig.context)
					.trim()
					.toLowerCase();
			} else if (
				field.typeExtras &&
				field.typeExtras.presentationContext
			) {
				ctx = String(field.typeExtras.presentationContext)
					.trim()
					.toLowerCase();
			}
			if (ctx === 'name') {
				ctx = 'form';
			}
			var map = presentationMapLoose(field.hostPresentation);
			var shown = '';
			if (map && map[ctx] != null) {
				shown = String(map[ctx]).trim();
			}
			if (
				!shown &&
				(ctx === 'symbol' || ctx === 'table') &&
				field.hostShortDescription
			) {
				shown = String(field.hostShortDescription).trim();
			}
			if (
				!shown &&
				ctx !== 'symbol' &&
				ctx !== 'table' &&
				ctx !== 'icon'
			) {
				shown = String(field.hostName || '').trim();
			}
			if (shown && shown !== '—') {
				values[valueKey(field)] = shown;
			}
		});
		return values;
	}

	/**
	 * Build TreeChooser roots from flat fixedOptions (children of chooser root).
	 * Do **not** wrap with the current host node — root is the branch, not a row.
	 *
	 * @param {Array} options
	 * @param {number} [rootId] Fallback sole kind when options empty (Aggregation).
	 * @param {string} [rootLabel]
	 * @return {Array}
	 */
	function buildEmbedKindRoots(options, rootId, rootLabel) {
		var roots = [];
		var byKey = {};
		var branchLabel =
			rootLabel != null && String(rootLabel).trim() !== ''
				? String(rootLabel).trim()
				: 'Kind';

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

		/* Aggregation / sole kind: type itself when no specialization children. */
		if (!roots.length && rootId > 0) {
			return [
				{
					id: rootId,
					name: branchLabel,
					selectable: true,
					children: [],
				},
			];
		}
		return roots;
	}

	/**
	 * Multistep Phase A kind picker — Q90: depth ≤ 1 ListChooser, ≥ 2 TreeChooser.
	 * Never paints the chooser root as a row (children only).
	 *
	 * @param {Array} choiceOptions
	 * @param {number} selectedId
	 * @param {function(number): void} onPick
	 * @param {{ choiceDepth?: number, rootId?: number, rootLabel?: string, required?: boolean, i18n?: object }} [opts]
	 * @return {HTMLElement}
	 */
	function paintMultistepKindChooser(choiceOptions, selectedId, onPick, opts) {
		opts = opts || {};
		var options = Array.isArray(choiceOptions) ? choiceOptions : [];
		var selected = parseInt(selectedId, 10) || 0;
		var required = !!opts.required;
		var mode = resolveCatalogChooserMode(options, opts.choiceDepth);
		var i18nLocal = opts.i18n || i18nStrings;

		if (mode === 'flat' || options.length <= 1) {
			var select = createEl('select', {
				className:
					'wtt-type-select wtt-catalog-choice-select wtt-multistep__kind-select',
			});
			/* Phase A is a step — always offer clear placeholder unless sole Q116. */
			var allowEmpty = !(required && options.length === 1);
			if (allowEmpty) {
				var emptyOpt = createEl('option', {
					value: '',
					text: t('embedPickHint', 'Choose kind…'),
				});
				if (!selected) {
					emptyOpt.selected = true;
				}
				select.appendChild(emptyOpt);
			}
			var realCount = 0;
			options.forEach(function (opt) {
				var id = opt && opt.id != null ? parseInt(opt.id, 10) || 0 : 0;
				if (id <= 0) {
					return;
				}
				realCount += 1;
				var label =
					(opt.name != null && String(opt.name)) ||
					(opt.path != null && String(opt.path)) ||
					'#' + id;
				var option = createEl('option', {
					value: String(id),
					text: label,
				});
				if (opt.shortDescription) {
					option.title = String(opt.shortDescription);
				} else if (opt.path && String(opt.path) !== label) {
					option.title = String(opt.path);
				}
				if (id === selected) {
					option.selected = true;
				}
				select.appendChild(option);
			});
			/* Q116: sole required option → auto + gray + advance. */
			if (required && realCount === 1 && select.options.length) {
				var only = null;
				var oi;
				for (oi = 0; oi < select.options.length; oi++) {
					if (String(select.options[oi].value || '') !== '') {
						only = select.options[oi];
						break;
					}
				}
				if (only) {
					only.selected = true;
					selected = parseInt(only.value, 10) || 0;
					select.disabled = true;
					select.title =
						(i18nLocal && i18nLocal.soleSelectLockedHint) ||
						'Only one choice — selected automatically.';
					if (selected > 0 && typeof onPick === 'function') {
						window.setTimeout(function () {
							onPick(selected);
						}, 0);
					}
				}
			}
			select.addEventListener('change', function () {
				var id = parseInt(select.value, 10) || 0;
				if (typeof onPick === 'function') {
					onPick(id);
				}
			});
			return select;
		}

		var kindRoots = buildEmbedKindRoots(
			options,
			parseInt(opts.rootId, 10) || 0,
			opts.rootLabel
		);
		var Picker = global.WTTNodePicker;
		if (Picker && typeof Picker.render === 'function') {
			return Picker.render({
				roots: kindRoots,
				selectedId: selected || 0,
				focusId: selected || 0,
				expandFocusBranch: true,
				presentation: 'inline',
				embedded: true,
				defaultOpen: true,
				showPickedLabel: true,
				dialogTitle: t('embedPhaseATitle', 'Choose part kind'),
				i18n: i18nLocal,
				selectable: function (node) {
					return !!(node && node.selectable && node.id);
				},
				onSelect: function (id) {
					if (typeof onPick === 'function') {
						onPick(parseInt(id, 10) || 0);
					}
				},
			});
		}
		return renderCatalogTreeChooser(options, selected, function (id) {
			if (typeof onPick === 'function') {
				onPick(parseInt(id, 10) || 0);
			}
		});
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
	 * Multistep (UR-B6): (A) kind under branch root; (B) filter + Model_Data list/create.
	 * mode dialog = popup; mode inline = step1 | step2 horizontal strip.
	 * Host store = instance id only (Q93). Legacy aliases: EmbeddedRenderer / embed.
	 *
	 * @param {{ choiceOptions?: Array, value?: string, readonly?: boolean, loadSchema?: function, onChange?: function, className?: string, field?: object, rootId?: number, required?: boolean, mode?: string, multistepMode?: string }} options
	 * @return {HTMLElement}
	 */
	function renderMultistep(options) {
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
		var mode = resolveMultistepMode(options, field);
		var storage = resolveMultistepStorage(options, field);
		var store = parseEmbedStore(options.value);
		var boundId = store.instanceId || '';
		var boundLabel = boundId
			? boundId
			: store.kindId
				? resolveCatalogLabel(choiceOptions, store.kindId) ||
				  '#' + store.kindId
				: '';
		var kindIdInline =
			parseInt(store.kindId, 10) || lastEmbedKindId || 0;

		var root = createEl('div', {
			className:
				'wtt-object-render wtt-object-render--multistep wtt-object-render--embed wtt-object-render--embed-b6' +
				(mode === 'inline' ? ' is-inline' : ' is-dialog') +
				(readonly ? ' is-display' : ' is-edit') +
				(required && !boundId ? ' is-invalid' : '') +
				(options.className ? ' ' + options.className : ''),
		});

		var labelEl = createEl('span', {
			className:
				'wtt-object-render__embed-pick-label wtt-multistep__bound-label',
			text:
				boundLabel ||
				(readonly
					? '—'
					: storage === 'aggregation'
						? t('embedPickPart', 'Pick part…')
						: t('embedPickHint', 'Choose kind…')),
		});

		function emitBind(instanceId, label) {
			boundId = instanceId != null ? String(instanceId) : '';
			boundLabel = label || boundId;
			labelEl.textContent =
				boundLabel ||
				(storage === 'aggregation'
					? t('embedPickPart', 'Pick part…')
					: t('embedPickHint', 'Choose kind…'));
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

		function kindPaintFn(schema) {
			var kindPref = String((schema && schema.preferredRender) || '')
				.trim()
				.toLowerCase();
			var showLabels = true;
			if (
				schema &&
				Object.prototype.hasOwnProperty.call(schema, 'compactShowLabels')
			) {
				showLabels = !!schema.compactShowLabels;
			}
			var baseOpts = { showLabels: showLabels };
			if (
				kindPref === 'compactrenderer' ||
				kindPref === 'compact' ||
				kindPref === 'compact-horizontal' ||
				kindPref === 'compact-h'
			) {
				return function (inst, opts) {
					return renderCompact(
						inst,
						Object.assign({}, baseOpts, opts || {}, {
							orientation: 'horizontal',
						})
					);
				};
			}
			if (
				kindPref === 'compactverticalrenderer' ||
				kindPref === 'compact-vertical' ||
				kindPref === 'compact-v'
			) {
				return function (inst, opts) {
					return renderCompact(
						inst,
						Object.assign({}, baseOpts, opts || {}, {
							orientation: 'vertical',
						})
					);
				};
			}
			return renderForm;
		}

		function paintCompositionDisplay(host, kindId, values) {
			host.textContent = '';
			kindId = parseInt(kindId, 10) || 0;
			if (kindId <= 0) {
				host.appendChild(
					createEl('span', {
						className:
							'wtt-object-render__embed-pick-label wtt-multistep__bound-label',
						text: '—',
					})
				);
				return;
			}
			host.appendChild(
				createEl('span', {
					className: 'wtt-field-hint',
					text: t('embedLoading', 'Loading…'),
				})
			);
			var loader =
				typeof options.loadSchema === 'function'
					? options.loadSchema
					: loadNodeSchema;
			Promise.resolve(loader(kindId)).then(function (schema) {
				host.textContent = '';
				var attrs = attachKindHostToAttributes(
					normalizeAttributes((schema && schema.attributes) || []),
					{
						name: (schema && schema.name) || '',
						shortDescription:
							(schema && schema.shortDescription) || '',
						presentation: (schema && schema.presentation) || null,
					}
				);
				if (!attrs.length) {
					host.appendChild(
						createEl('span', {
							className: 'wtt-field-hint',
							text: t(
								'embedNoFields',
								'Selected node has no attributes.'
							),
						})
					);
					return;
				}
				var paint = kindPaintFn(schema);
				host.appendChild(
					paint(
						{
							attributes: attrs,
							values: values && typeof values === 'object' ? values : {},
						},
						{ readonly: true }
					)
				);
			});
		}

		function paintPhaseBInto(host, kindId, closeFn) {
			host.textContent = '';
			var filterValues = {};
			var kindAttrs = [];

			var formHost = createEl('div', {
				className:
					'wtt-object-render__embed-filter-form wtt-multistep__phase-b-form',
			});
			host.appendChild(formHost);

			var matchesWrap = null;
			var listHost = null;
			var createBtn = null;
			var instances = [];

			if (storage === 'aggregation') {
				matchesWrap = createEl('div', {
					className: 'wtt-object-render__embed-matches-wrap',
				});
				listHost = createEl('div', {
					className: 'wtt-object-render__embed-matches',
				});
				listHost.appendChild(
					createEl('span', {
						className: 'wtt-field-hint',
						text: t('embedLoading', 'Loading…'),
					})
				);
				matchesWrap.appendChild(listHost);
				host.appendChild(matchesWrap);

				var actionsRow = createEl('div', {
					className: 'wtt-object-render__embed-phase-actions',
				});
				createBtn = createEl('button', {
					type: 'button',
					className: 'button button-primary',
					text: t('embedCreateBind', 'Create and bind'),
				});
				actionsRow.appendChild(createBtn);
				host.appendChild(actionsRow);
			}

			function refreshMatches() {
				if (!listHost || storage !== 'aggregation') {
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
				hr.appendChild(createEl('th', { text: t('colIndex', '#') }));
				hr.appendChild(createEl('th', { text: t('colVersion', 'Version') }));
				hr.appendChild(
					createEl('th', { text: t('colModified', 'Modified') })
				);
				hr.appendChild(createEl('th', { text: t('colInstanceId', 'Id') }));
				thead.appendChild(hr);
				table.appendChild(thead);
				var tbody = createEl('tbody');
				matched.forEach(function (inst) {
					var tr = createEl('tr', {
						className: 'wtt-model-instance-picker__row',
						onClick: function () {
							emitBind(inst.id, formatEmbedInstanceLabel(inst));
							if (typeof closeFn === 'function') {
								closeFn();
							}
						},
					});
					var seq = parseInt(inst.seq, 10) || 0;
					tr.appendChild(
						createEl('td', { text: seq > 0 ? '#' + seq : '—' })
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

			function emitCompositionValues() {
				if (storage !== 'composition') {
					return;
				}
				if (typeof options.onChange !== 'function') {
					return;
				}
				/* Preview/session: kind id + filled attr bag (parent context). */
				try {
					options.onChange(
						JSON.stringify({
							kindId: kindId,
							values: Object.assign({}, filterValues),
						})
					);
				} catch (e) {
					options.onChange(String(kindId || ''));
				}
			}

			var loader =
				typeof options.loadSchema === 'function'
					? options.loadSchema
					: loadNodeSchema;

			Promise.resolve(loader(kindId)).then(function (schema) {
				/* Preserve Attributes table order from schema — do not reorder. */
				kindAttrs = attachKindHostToAttributes(
					normalizeAttributes((schema && schema.attributes) || []),
					{
						name: (schema && schema.name) || '',
						shortDescription:
							(schema && schema.shortDescription) || '',
						presentation: (schema && schema.presentation) || null,
					}
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
					filterValues = seedKindFilterValues(kindAttrs);
					/* Restore prior composition bag after remount (if any). */
					if (
						storage === 'composition' &&
						store.values &&
						typeof store.values === 'object'
					) {
						Object.keys(store.values).forEach(function (vk) {
							if (
								Object.prototype.hasOwnProperty.call(
									filterValues,
									vk
								) ||
								(kindAttrs || []).some(function (a) {
									return valueKey(a) === vk;
								})
							) {
								filterValues[vk] = store.values[vk];
							}
						});
					}
					/*
					 * Phase B paint = kind node's Preferred (Meter/Gramm → Compact).
					 * Do not hard-code Form — same chrome as opening the kind node.
					 */
					var filterPaint = kindPaintFn(schema);
					formHost.appendChild(
						filterPaint(
							{ attributes: kindAttrs, values: filterValues },
							{
								readonly: false,
								className:
									'wtt-object-render__embed-filter-inner',
								onFieldInput: function (innerField, next) {
									var k = valueKey(innerField);
									filterValues[k] =
										next == null ? '' : String(next);
									if (storage === 'aggregation') {
										refreshMatches();
									} else {
										emitCompositionValues();
									}
								},
							}
						)
					);
					/*
					 * Soft-sync Display (composition JSON) after Phase B paints —
					 * Editable must not remount; Display refreshes via onChange.
					 */
					if (storage === 'composition') {
						emitCompositionValues();
					}
				}

				if (storage !== 'aggregation') {
					return;
				}

				ensureDefaultModelDataApi();
				var listFn = modelDataApi.listInstances;
				if (typeof listFn !== 'function') {
					if (listHost) {
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
					}
					if (createBtn) {
						createBtn.disabled = true;
					}
					return;
				}

				Promise.resolve(listFn(kindId, modelDataApi.taxonomy || ''))
					.then(function (rows) {
						instances = Array.isArray(rows) ? rows : [];
						refreshMatches();
					})
					.catch(function () {
						instances = [];
						if (listHost) {
							listHost.textContent = '';
							listHost.appendChild(
								createEl('p', {
									className: 'description',
									text: t('error', 'Something went wrong.'),
								})
							);
						}
					});
			});

			if (storage === 'aggregation' && createBtn) {
				createBtn.addEventListener('click', function () {
					ensureDefaultModelDataApi();
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
						if (!v || v === '—') {
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
							if (typeof closeFn === 'function') {
								closeFn();
							}
						})
						.catch(function (err) {
							createBtn.disabled = false;
							var msg =
								(err && err.message) ||
								t('error', 'Something went wrong.');
							window.alert(msg);
						});
				});
			}
		}

		function openB6Popup() {
			ensureDefaultModelDataApi();
			var kindRoots = buildEmbedKindRoots(
				choiceOptions,
				rootId,
				options.rootLabel
			);
			var soleKindId =
				storage === 'aggregation'
					? resolveSoleAggregationKindId(field, {
							rootId: rootId,
							choiceOptions: choiceOptions,
					  })
					: 0;
			if (!kindRoots.length && !choiceOptions.length && soleKindId <= 0) {
				window.alert(
					t(
						'embedNoChoices',
						'No specialization children under this node.'
					)
				);
				return;
			}

			var kindId =
				parseInt(store.kindId, 10) ||
				soleKindId ||
				lastEmbedKindId ||
				0;

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
				/* Aggregation + concrete type (no kind catalog) → Phase B only. */
				if (storage === 'aggregation' && soleKindId > 0) {
					kindId = soleKindId;
					lastEmbedKindId = soleKindId;
					titleEl.textContent = t(
						'embedPhaseBTitle',
						'Pick or create part'
					);
					body.textContent = '';
					backBtn.hidden = true;
					paintPhaseBInto(body, kindId, close);
					return;
				}
				titleEl.textContent = t('embedPhaseATitle', 'Choose part kind');
				body.textContent = '';
				body.appendChild(
					paintMultistepKindChooser(choiceOptions, kindId, function (id) {
						kindId = parseInt(id, 10) || 0;
						if (kindId > 0) {
							lastEmbedKindId = kindId;
							titleEl.textContent =
								storage === 'aggregation'
									? t(
											'embedPhaseBTitle',
											'Pick or create part'
									  )
									: t(
											'embedPhaseBFillTitle',
											'Fill values'
									  );
							backBtn.hidden = false;
							paintPhaseBInto(body, kindId, close);
						}
					}, {
						choiceDepth:
							options.choiceDepth != null
								? options.choiceDepth
								: field && field.choiceDepth,
						rootId: rootId,
						rootLabel: options.rootLabel,
						required: required,
						i18n: i18nStrings,
					})
				);
				backBtn.hidden = true;
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
				className:
					'wtt-dialog-backdrop wtt-object-render__embed-backdrop',
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

		if (readonly) {
			/*
			 * Display: Composition → kind Preferred with filled values (not "—").
			 * Aggregation → bound instance label.
			 */
			if (storage === 'composition') {
				var displayHost = createEl('div', {
					className: 'wtt-multistep__display-composition',
				});
				root.appendChild(displayHost);
				paintCompositionDisplay(
					displayHost,
					store.kindId,
					store.values
				);
				return root;
			}
			root.appendChild(labelEl);
			return root;
		}

		if (mode === 'inline' && !readonly) {
			var strip = createEl('div', {
				className: 'wtt-multistep__strip',
			});
			var step1 = createEl('div', {
				className: 'wtt-multistep__step1',
			});
			var step2 = createEl('div', {
				className: 'wtt-multistep__step2',
			});
			strip.appendChild(step1);
			strip.appendChild(step2);
			root.appendChild(strip);

			if (required && !boundId) {
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

			if (boundId) {
				step1.appendChild(labelEl);
				step1.appendChild(
					createEl('button', {
						type: 'button',
						className:
							'button button-small wtt-object-render__embed-open',
						text: t('embedChangePart', 'Change…'),
						onClick: function (e) {
							e.preventDefault();
							boundId = '';
							boundLabel = '';
							store = parseEmbedStore('');
							if (typeof options.onChange === 'function') {
								options.onChange('');
							}
							root.textContent = '';
							root.appendChild(
								renderMultistep(
									Object.assign({}, options, {
										value: '',
									})
								)
							);
						},
					})
				);
				step2.appendChild(
					createEl('span', {
						className: 'wtt-field-hint',
						text: boundLabel || boundId,
					})
				);
				return root;
			}

			var kindRoots = buildEmbedKindRoots(
				choiceOptions,
				rootId,
				options.rootLabel
			);
			var soleKindIdInline =
				storage === 'aggregation'
					? resolveSoleAggregationKindId(field, {
							rootId: rootId,
							choiceOptions: choiceOptions,
					  })
					: 0;
			if (
				!kindRoots.length &&
				!choiceOptions.length &&
				soleKindIdInline <= 0
			) {
				step1.appendChild(
					createEl('span', {
						className: 'wtt-field-hint',
						text: t(
							'embedNoChoices',
							'No specialization children under this node.'
						),
					})
				);
				return root;
			}

			/* Aggregation + concrete type → Phase B inline (no kind step). */
			if (storage === 'aggregation' && soleKindIdInline > 0) {
				kindIdInline = soleKindIdInline;
				lastEmbedKindId = soleKindIdInline;
				step1.appendChild(
					createEl('strong', {
						className: 'wtt-multistep__step-label',
						text: t('embedPhaseBTitle', 'Pick or create part'),
					})
				);
				step1.appendChild(
					createEl('span', {
						className: 'wtt-field-hint',
						text: options.rootLabel || '#' + String(soleKindIdInline),
					})
				);
				paintPhaseBInto(step2, soleKindIdInline, null);
				return root;
			}

			step1.appendChild(
				createEl('strong', {
					className: 'wtt-multistep__step-label',
					text: t('embedPhaseATitle', 'Choose part kind'),
				})
			);
			step2.appendChild(
				createEl('span', {
					className: 'wtt-field-hint wtt-multistep__step2-placeholder',
					text: t('embedPickHint', 'Choose kind…'),
				})
			);

			function paintInlineStep2(kindId) {
				step2.textContent = '';
				step2.appendChild(
					createEl('strong', {
						className: 'wtt-multistep__step-label',
						text:
							storage === 'aggregation'
								? t(
										'embedPhaseBTitle',
										'Pick or create part'
								  )
								: t('embedPhaseBFillTitle', 'Fill values'),
					})
				);
				var host = createEl('div', {
					className: 'wtt-multistep__step2-body',
				});
				step2.appendChild(host);
				paintPhaseBInto(host, kindId, null);
			}

			step1.appendChild(
				paintMultistepKindChooser(
					choiceOptions,
					kindIdInline,
					function (id) {
						kindIdInline = parseInt(id, 10) || 0;
						if (kindIdInline > 0) {
							lastEmbedKindId = kindIdInline;
							paintInlineStep2(kindIdInline);
						} else {
							step2.textContent = '';
							step2.appendChild(
								createEl('span', {
									className:
										'wtt-field-hint wtt-multistep__step2-placeholder',
									text: t('embedPickHint', 'Choose kind…'),
								})
							);
						}
					},
					{
						choiceDepth:
							options.choiceDepth != null
								? options.choiceDepth
								: field && field.choiceDepth,
						rootId: rootId,
						rootLabel: options.rootLabel,
						required: required,
						i18n: i18nStrings,
					}
				)
			);

			if (kindIdInline > 0) {
				paintInlineStep2(kindIdInline);
			}
			return root;
		}

		/* Dialog mode (default): bound label + open popup. */
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

		if (!readonly) {
			root.appendChild(
				createEl('button', {
					type: 'button',
					className: 'button button-small wtt-object-render__embed-open',
					text: boundId
						? t('embedChangePart', 'Change…')
						: storage === 'aggregation'
							? t('embedPickPart', 'Pick part…')
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

	/** @deprecated Use renderMultistep — keeps EmbeddedRenderer call sites working. */
	function renderEmbed(options) {
		return renderMultistep(options);
	}

	function paintEmbedField(field, value, opts) {
		opts = opts || {};
		var required =
			field &&
			field.allowsEmpty === false &&
			!multiplicityAllowsMany(field.multiplicity);
		var mode = resolveMultistepMode(opts, field);
		return renderMultistep({
			field: field,
			choiceOptions: catalogOptionsForField(field),
			rootId:
				parseInt(field && field.fixedRootId, 10) ||
				parseInt(field && field.typeId, 10) ||
				0,
			rootLabel:
				(field &&
					(field.typeName || field.typeLabel || field.name)) ||
				'',
			value: value != null ? String(value) : '',
			readonly: !!opts.readonly || !!(field && field.readonly),
			required: !!required,
			mode: mode,
			multistepMode: mode,
			binding: opts.binding || (field && field.binding) || '',
			multistepStorage:
				opts.multistepStorage ||
				(opts.binding || (field && field.binding) || ''),
			loadSchema: opts.loadSchema,
			className: 'wtt-object-render__embed-field wtt-object-render__multistep-field',
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

		var typeKeyPaint = String((field && field.typeKey) || '')
			.trim()
			.toLowerCase();
		if (
			typeKeyPaint === 'node_presentation' ||
			typeKeyPaint === 'display_node_name' ||
			typeKeyPaint.indexOf('node_presentation') !== -1 ||
			typeKeyPaint.indexOf('display_node_name') !== -1
		) {
			var shownName = resolveNodePresentationDisplay(field, value);
			var compactName =
				opts.contextName === 'table' || opts.contextName === 'compact';
			if (compactName) {
				return createEl('span', {
					className:
						'wtt-object-render__display wtt-preview-display-name',
					text: shownName,
				});
			}
			return createEl('input', {
				type: 'text',
				className:
					'wtt-preview-input wtt-preview-input--display-name wtt-object-render__input',
				value: shownName,
				readonly: 'readonly',
				disabled: 'disabled',
				title: shownName,
			});
		}

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

		/*
		 * CatalogChoice / ChildList BEFORE Quantity/Unit Registry paint.
		 * Base unit (With prefix Konstanten) = pick Meter/Ohm leaves — not OQ-W11
		 * Praefix+Kuerzel composition chrome (attribute-choice-inheritance.md).
		 */
		if (isCatalogChoiceField(field)) {
			if (readonly) {
				return paintReferenceDisplay(field, value, opts);
			}
			return paintCatalogChoice(field, value, opts);
		}

		var hasQty =
			field &&
			field.quantitySchema &&
			Array.isArray(field.quantitySchema.members) &&
			field.quantitySchema.members.length;
		var prefPaint = resolveFieldPreferredPaint(field);
		/*
		 * Multistep before Structure embed — objectLayoutPaint used to swallow
		 * Multistep into paintStructureEmbed → Form (Taktrate / Bauteil).
		 */
		if (isMultistepPreferredField(field)) {
			return paintEmbedField(field, value, opts);
		}
		var objectLayoutPaint = {
			form: true,
			table: true,
			compact: true,
			compactvertical: true,
			childlist: true,
		};

		/*
		 * Preferred object layout (Compact/Form/Table/…) wins over Value+Unit
		 * shape heuristics — e.g. Toleranz Compact = Sign/Value/Unit strip, not
		 * bare Quantity magnitude.
		 * Aggregation → bound Model_Data via type Preferred (Q111).
		 * Composition → inline schema embed on the host slot.
		 */
		if (isStructureField(field) && objectLayoutPaint[prefPaint]) {
			if (isAggregationBinding(field)) {
				return paintAggregationBound(field, value, opts);
			}
			return paintStructureEmbed(field, value, opts);
		}

		var hostProps =
			(field &&
				Array.isArray(field.typeProperties) &&
				field.typeProperties.length &&
				field.typeProperties) ||
			(field && Array.isArray(field.attributes) && field.attributes) ||
			[];
		/*
		 * Fallback only when Preferred is empty / quantity family: Value+Unit
		 * structure hosts (size, Unit type) → QuantityRenderer.
		 */
		var looksValueUnitHost = (function (attrs) {
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
					n === 'menge' ||
					key === 'double' ||
					key === 'int'
				) {
					hasVal = true;
				}
				if (
					n === 'einheit' ||
					n === 'unit' ||
					n === 'base unit' ||
					n === 'basiseinheit' ||
					n === 'kuerzel' ||
					n === 'waehrung' ||
					(a.quantitySchema && a.quantitySchema.members) ||
					(Array.isArray(a.fixedOptions) && a.fixedOptions.length) ||
					(Array.isArray(a.typeProperties) && a.typeProperties.length)
				) {
					hasUnit = true;
				}
			});
			return hasVal && hasUnit;
		})(hostProps);

		if (
			hasQty ||
			prefPaint === 'quantity' ||
			prefPaint === 'unit' ||
			(looksValueUnitHost &&
				(!prefPaint || prefPaint === 'quantity' || prefPaint === 'unit'))
		) {
			var RegQty = registry();
			var ctxQty = {
				name: opts.contextName || 'form',
				mode: readonly ? 'display' : 'edit',
				bare: true,
				hideLabel: true,
				noSampleFill: !!opts.noSampleFill,
				value: value != null ? String(value) : '',
				onInput:
					readonly || typeof opts.onInput !== 'function'
						? null
						: opts.onInput,
			};
			var nodeQty = fieldNode(field, value);
			if (opts.noSampleFill) {
				nodeQty.sample = value != null ? String(value) : '';
			}
			nodeQty.preferredRender = prefPaint || 'quantity';
			nodeQty.typePreferredRender = prefPaint || 'quantity';
			if (
				(!Array.isArray(nodeQty.attributes) || !nodeQty.attributes.length) &&
				Array.isArray(field.typeProperties)
			) {
				nodeQty.attributes = field.typeProperties;
			}
			if (RegQty && typeof RegQty.renderContent === 'function') {
				var paintedQty = RegQty.renderContent(nodeQty, ctxQty, readonly);
				if (paintedQty) {
					return paintedQty;
				}
			}
		}

		/* Structured type → type Preferred (Aggregation bound vs Composition embed). */
		if (
			isStructureField(field) &&
			prefPaint !== 'quantity' &&
			prefPaint !== 'unit'
		) {
			if (isAggregationBinding(field)) {
				return paintAggregationBound(field, value, opts);
			}
			return paintStructureEmbed(field, value, opts);
		}

		/* Display-only referenceMode for node_ref / leftover refs. */
		if (readonly && isReferenceField(field)) {
			return paintReferenceDisplay(field, value, opts);
		}

		var Reg = registry();
		var context = {
			name: opts.contextName || 'form',
			mode: readonly ? 'display' : 'edit',
			bare: true,
			hideLabel: true,
			noSampleFill: !!opts.noSampleFill,
			value: value != null ? String(value) : '',
			onInput:
				readonly || typeof opts.onInput !== 'function' ? null : opts.onInput,
		};
		var node = fieldNode(field, value);
		if (opts.noSampleFill) {
			/* Festwert edit: do not inherit preview Sample into the wire. */
			node.sample = value != null ? String(value) : '';
		}
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
		var rawFields =
			(instances[0] && (instances[0].attributes || instances[0].fields)) ||
			(options.attributes || []);
		var hostFromInstance = {
			name: String(
				(instances[0] && instances[0].schemaName) ||
					(options && options.hostName) ||
					''
			).trim(),
			shortDescription: String(
				(instances[0] && instances[0].hostShortDescription) || ''
			).trim(),
			presentation:
				(instances[0] && instances[0].hostPresentation) || null,
		};
		var fields = attachKindHostToAttributes(
			normalizeAttributes(rawFields),
			hostFromInstance
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
		var showLabels = true;
		if (Object.prototype.hasOwnProperty.call(options, 'showLabels')) {
			showLabels = !!options.showLabels;
		} else if (
			instance &&
			Object.prototype.hasOwnProperty.call(instance, 'compactShowLabels')
		) {
			showLabels = !!instance.compactShowLabels;
		}
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
				(showLabels ? '' : ' is-no-labels') +
				(options.className ? ' ' + options.className : ''),
			'data-orientation': orientation,
			'data-show-labels': showLabels ? '1' : '0',
		});

		fields.forEach(function (field) {
			var cell = createEl('div', {
				className: 'wtt-object-render__compact-field',
			});
			if (showLabels) {
				cell.appendChild(
					createEl('label', {
						className: 'wtt-object-render__compact-label',
						text: field.name || '—',
					})
				);
			}
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

	function schemaPresentationMap(schemaNode) {
		return presentationMapLoose(schemaNode && schemaNode.presentation);
	}

	/**
	 * Q117 presentation context on a field (Relation override wins).
	 * "name" / Knotenname → form.
	 *
	 * @param {object|null} field
	 * @return {string}
	 */
	function presentationContextFromField(field) {
		var ctx = 'form';
		if (
			field &&
			field.typeExtras &&
			field.typeExtras.presentationContext
		) {
			ctx = String(field.typeExtras.presentationContext)
				.trim()
				.toLowerCase();
		} else if (
			field &&
			field.presentationConfig &&
			field.presentationConfig.context
		) {
			ctx = String(field.presentationConfig.context)
				.trim()
				.toLowerCase();
		} else if (field && field.presentationContext != null) {
			ctx = String(field.presentationContext).trim().toLowerCase();
		}
		if (ctx === 'name' || ctx === 'knotenname') {
			ctx = 'form';
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

	/**
	 * Law: node_presentation paints host presentation for the chosen context.
	 * Resolve order (do not skip to host.name):
	 * 1) presentationContext → slot (form/table/select/symbol/help/icon)
	 * 2) hostPresentation[slot] when non-empty (respect Presentation settings)
	 * 3) empty-slot fallbacks only — form/select/help → host.name;
	 *    symbol → short / — (never invent name); table → short then name;
	 *    icon → —
	 *
	 * @param {object|null} field
	 * @param {string} [value]
	 * @return {string}
	 */
	function resolveNodePresentationDisplay(field, value) {
		var ctx = presentationContextFromField(field);
		var map =
			presentationMapLoose(field && field.hostPresentation) ||
			presentationMapLoose(field && field.presentation) ||
			null;
		var fromMap =
			map && map[ctx] != null && String(map[ctx]).trim() !== ''
				? String(map[ctx]).trim()
				: '';
		var hostName = String(
			(field &&
				(field.hostName ||
					field.hostDisplayName ||
					field.nodeName ||
					field.schemaName)) ||
				''
		).trim();
		var shortHost = String(
			(field && field.hostShortDescription) || ''
		).trim();
		var fest =
			(field && field.fixedLabel && String(field.fixedLabel).trim()) ||
			'';
		if (!fest && field && Array.isArray(field.fixedValues)) {
			var i;
			for (i = 0; i < field.fixedValues.length; i++) {
				if (
					field.fixedValues[i] != null &&
					typeof field.fixedValues[i] !== 'object'
				) {
					var fv = String(field.fixedValues[i]).trim();
					if (fv) {
						fest = fv;
						break;
					}
				}
			}
		}
		if (fromMap) {
			return fromMap;
		}
		if (ctx === 'symbol') {
			return shortHost || fest || '—';
		}
		if (ctx === 'table') {
			return shortHost || hostName || fest || '—';
		}
		if (ctx === 'icon') {
			return '—';
		}
		/* form / select / help — fallback only when map slot empty */
		if (hostName) {
			return hostName;
		}
		var raw = value != null ? String(value).trim() : '';
		if (raw && raw !== '—') {
			return raw;
		}
		if (fest) {
			return fest;
		}
		var sample = field && field.sample != null ? String(field.sample).trim() : '';
		if (sample && sample !== '—') {
			return sample;
		}
		return 'Node name';
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
		var hostName = schemaName(schemaNode) || '';
		/*
		 * Attach host Q117 presentation onto every field before fill/paint.
		 * Resolve uses map[context] first; host.name only as empty-slot fallback.
		 */
		var fields = attachKindHostToAttributes(normalizeAttributes(attrs), {
			name: hostName,
			shortDescription: String(
				(schemaNode && schemaNode.shortDescription) || ''
			).trim(),
			presentation: (schemaNode && schemaNode.presentation) || null,
		});
		var Sample = sampleApi();
		var values = {};
		fields.forEach(function (field) {
			var key = valueKey(field);
			var typeKey = String((field && field.typeKey) || '')
				.trim()
				.toLowerCase();
			var fest =
				(field.fixedLabel && String(field.fixedLabel).trim()) || '';
			if (isNodePresentationField(field)) {
				values[key] = resolveNodePresentationDisplay(field, '');
				field.sample = values[key];
				return;
			}
			if (fest) {
				values[key] = fest;
				return;
			}
			/*
			 * Nested structure: seed Preferred schema JSON — never Sample.MAP by
			 * typeKey scalar or outer-host presentation.
			 */
			if (
				Array.isArray(field.typeProperties) &&
				field.typeProperties.length &&
				String(field.fixedMode || '') !== 'catalog'
			) {
				values[key] = seedStructureFieldStore(field, variantIndex);
				field.sample = values[key];
				return;
			}
			if (Sample && typeof Sample.forAttribute === 'function') {
				if (shouldSkipSampleFill(field)) {
					values[key] = '';
					return;
				}
				values[key] = String(
					Sample.forAttribute(
						Object.assign({}, field, {
							variantIndex: variantIndex,
							hostName: hostName,
						})
					) ||
						field.sample ||
						''
				);
				return;
			}
			values[key] = shouldSkipSampleFill(field) ? '' : field.sample || '';
		});
		return {
			schemaName: hostName,
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
		if (field && field.allowsEmpty === true) {
			return true;
		}
		if (field && field.allowsEmpty === false) {
			return false;
		}
		if (field && field.required === false) {
			return true;
		}
		if (field && field.required === true) {
			return false;
		}
		return multiplicityAllowsEmpty(
			(field && (field.multiplicity || field.fieldMultiplicity)) || '1'
		);
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
				typeName: prop.typeLabel || prop.typeName || '',
				typeLabel: String(prop.typeLabel || prop.typeName || '').trim(),
				typeDisplayName: String(
					prop.typeDisplayName || prop.typeLabel || prop.typeName || ''
				).trim(),
				typeId: prop.typeId || 0,
				multiplicity: prop.multiplicity || '1',
				fieldMultiplicity:
					prop.fieldMultiplicity || prop.multiplicity || '1',
				allowsMany:
					!!prop.allowsMany ||
					multiplicityAllowsMany(prop.multiplicity),
				allowsEmpty:
					prop.allowsEmpty != null
						? !!prop.allowsEmpty
						: multiplicityAllowsEmpty(prop.multiplicity),
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
				typePresentation:
					prop.typePresentation && typeof prop.typePresentation === 'object'
						? prop.typePresentation
						: null,
				typeShortDescription: String(
					prop.typeShortDescription != null
						? prop.typeShortDescription
						: ''
				).trim(),
				compactShowLabels:
					prop.compactShowLabels !== false &&
					prop.compactShowLabels !== 0 &&
					prop.compactShowLabels !== '0',
				binding: prop.binding || '',
				isRelatedDataset: !!prop.isRelatedDataset,
				usesRelatedInstances: !!prop.usesRelatedInstances,
				relatedInstances: Array.isArray(prop.relatedInstances)
					? prop.relatedInstances.slice()
					: [],
				typePreferredRender: String(prop.typePreferredRender || ''),
				preferredRender: String(
					prop.preferredRender || prop.typePreferredRender || ''
				),
				multistepMode: String(prop.multistepMode || ''),
				fixedRootId:
					parseInt(prop.fixedRootId, 10) ||
					parseInt(prop.typeId, 10) ||
					0,
				embedChoiceOptions: Array.isArray(prop.embedChoiceOptions)
					? prop.embedChoiceOptions.slice()
					: [],
				presentationConfig:
					prop.presentationConfig &&
					typeof prop.presentationConfig === 'object'
						? Object.assign({}, prop.presentationConfig)
						: null,
				hostPresentation:
					prop.hostPresentation &&
					typeof prop.hostPresentation === 'object'
						? prop.hostPresentation
						: prop.presentation && typeof prop.presentation === 'object'
							? prop.presentation
							: null,
				hostShortDescription: String(prop.hostShortDescription || ''),
				hostName: String(prop.hostName || prop.hostDisplayName || ''),
				hostDisplayName: String(
					prop.hostDisplayName || prop.hostName || ''
				),
				typeExtras:
					prop.typeExtras && typeof prop.typeExtras === 'object'
						? prop.typeExtras
						: null,
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
			if (
				!val &&
				Array.isArray(field.typeProperties) &&
				field.typeProperties.length &&
				String(field.fixedMode || '') !== 'catalog'
			) {
				val = seedStructureFieldStore(field, 0);
			}
			if (
				!val &&
				Sample &&
				typeof Sample.forAttribute === 'function' &&
				!shouldSkipSampleFill(field)
			) {
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
			embed: 'MultistepRenderer',
			embeddedrenderer: 'MultistepRenderer',
			'pick-fill': 'MultistepRenderer',
			pick_fill: 'MultistepRenderer',
			'compact-embed': 'MultistepRenderer',
			multistep: 'MultistepRenderer',
			multisteprenderer: 'MultistepRenderer',
			child_list: 'ChildListRenderer',
			childlist: 'ChildListRenderer',
			childlistrenderer: 'ChildListRenderer',
		};
		if (map[key]) {
			return map[key];
		}
		/* Pass through known wire ids (e.g. ChildListRenderer). */
		if (/^[A-Za-z][A-Za-z0-9]*Renderer$/.test(String(layout || '').trim())) {
			return String(layout).trim();
		}
		return 'FormRenderer';
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
			typeName: prop.typeLabel || prop.typeName || '',
			typeLabel: String(prop.typeLabel || prop.typeName || '').trim(),
			typeDisplayName: String(
				prop.typeDisplayName || prop.typeLabel || prop.typeName || ''
			).trim(),
			typeId: prop.typeId || 0,
			hidden: !!prop.hidden,
			multiplicity: prop.multiplicity || '0..*',
			fieldMultiplicity:
				prop.fieldMultiplicity || prop.multiplicity || '0..*',
			allowsEmpty:
				prop.allowsEmpty != null
					? !!prop.allowsEmpty
					: multiplicityAllowsEmpty(
							prop.multiplicity || prop.fieldMultiplicity || '0..*'
					  ),
			readonly: !!prop.readonly,
			fixedMode: prop.fixedMode || '',
			fixedLabel:
				prop.fixedLabel != null ? String(prop.fixedLabel) : '',
			fixedValues: Array.isArray(prop.fixedValues)
				? prop.fixedValues.slice()
				: [],
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
			typePresentation:
				prop.typePresentation && typeof prop.typePresentation === 'object'
					? prop.typePresentation
					: null,
			typeShortDescription: String(
				prop.typeShortDescription != null
					? prop.typeShortDescription
					: ''
			).trim(),
			compactShowLabels:
				prop.compactShowLabels !== false &&
				prop.compactShowLabels !== 0 &&
				prop.compactShowLabels !== '0',
			typePreferredRender: String(prop.typePreferredRender || ''),
			preferredRender: String(
				prop.preferredRender || prop.typePreferredRender || ''
			),
			multistepMode: String(prop.multistepMode || ''),
			fixedRootId:
				parseInt(prop.fixedRootId, 10) ||
				parseInt(prop.typeId, 10) ||
				0,
			embedChoiceOptions: Array.isArray(prop.embedChoiceOptions)
				? prop.embedChoiceOptions.slice()
				: [],
			presentationConfig:
				prop.presentationConfig &&
				typeof prop.presentationConfig === 'object'
					? Object.assign({}, prop.presentationConfig)
					: null,
			hostPresentation:
				prop.hostPresentation && typeof prop.hostPresentation === 'object'
					? prop.hostPresentation
					: null,
			hostShortDescription: String(prop.hostShortDescription || ''),
			hostName: String(prop.hostName || prop.hostDisplayName || ''),
			hostDisplayName: String(prop.hostDisplayName || prop.hostName || ''),
			typeExtras:
				prop.typeExtras && typeof prop.typeExtras === 'object'
					? prop.typeExtras
					: null,
			binding: prop.binding || '',
			isRelatedDataset: !!prop.isRelatedDataset,
			usesRelatedInstances: !!prop.usesRelatedInstances,
			relatedInstances: Array.isArray(prop.relatedInstances)
				? prop.relatedInstances.slice()
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
			return typeProps
				.filter(function (p) {
					return p && !p.hidden;
				})
				.map(function (p) {
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
	 *   chrome?: boolean,
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
		/* Admin schema Preview: surfaces only (no Object View title/meta strip). */
		var showChrome = options.chrome !== false;
		var onFieldInput =
			typeof options.onFieldInput === 'function'
				? options.onFieldInput
				: null;
		var showLabelsOpt = true;
		if (Object.prototype.hasOwnProperty.call(options, 'showLabels')) {
			showLabelsOpt = !!options.showLabels;
		} else if (
			view &&
			Object.prototype.hasOwnProperty.call(view, 'compactShowLabels')
		) {
			showLabelsOpt = !!view.compactShowLabels;
		}
		var paintOpts = {
			readonly: readonly,
			referenceMode: refMode,
			onFieldInput: onFieldInput,
			showLabels: showLabelsOpt,
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
				(readonly ? ' is-display' : ' is-edit') +
				(showChrome ? '' : ' wtt-object-view--no-chrome'),
		});
		root.setAttribute('data-wtt-render-depth', String(depth));
		root.setAttribute('data-wtt-reference-mode', refMode);

		if (showChrome) {
			var header = createEl('header', {
				className: 'wtt-object-view__header',
			});
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
		}

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
				layout === 'MultistepRenderer' &&
				Array.isArray(view.embedChoiceOptions) &&
				view.embedChoiceOptions.length
			) {
				section.appendChild(
					renderMultistep({
						choiceOptions: view.embedChoiceOptions,
						value:
							view.instanceValues &&
							typeof view.instanceValues === 'object' &&
							view.instanceValues.__embed != null
								? String(view.instanceValues.__embed)
								: '',
						readonly: !!paintOpts.readonly,
						mode: resolveMultistepMode(
							{ multistepMode: view.multistepMode },
							null
						),
						multistepMode: view.multistepMode,
						className: 'wtt-object-view__embed wtt-object-view__multistep',
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
							showLabels: !!paintOpts.showLabels,
							orientation:
								layout === 'CompactVerticalRenderer'
									? 'vertical'
									: 'horizontal',
							className: 'wtt-object-view__compact',
						})
					);
				} else if (layout === 'MultistepRenderer') {
					section.appendChild(
						renderMultistep({
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
							mode: resolveMultistepMode(
								{ multistepMode: view.multistepMode },
								null
							),
							multistepMode: view.multistepMode,
							className: 'wtt-object-view__embed wtt-object-view__multistep',
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
		renderMultistep: renderMultistep,
		renderEmbed: renderEmbed,
		paintFieldContent: paintFieldContent,
		setSchemaLoader: setSchemaLoader,
		parseEmbedStore: parseEmbedStore,
		encodeEmbedStore: encodeEmbedStore,
		buildExampleInstance: buildExampleInstance,
		buildExampleList: buildExampleList,
		resolveNodePresentationDisplay: resolveNodePresentationDisplay,
	};
})(typeof window !== 'undefined' ? window : this);
