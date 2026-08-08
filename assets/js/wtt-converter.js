/**
 * Value converters — Registry parallel to field renderers (WTTNodeRender.Registry).
 *
 * Preferred converter (term meta) selects which registered converter formats output.
 * Options are filtered by canConvert(node), same idea as Preferred render + canRender.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var converters = [];

	function typeKeyOf(node) {
		if (!node || typeof node !== 'object') {
			return '';
		}
		function norm(raw) {
			var key = String(raw == null ? '' : raw)
				.trim()
				.toLowerCase();
			if (key === 'integer') {
				return 'int';
			}
			if (key === 'boolean') {
				return 'bool';
			}
			if (key === 'float' || key === 'number') {
				return 'double';
			}
			return key;
		}
		var leafKeys = {
			int: true,
			char: true,
			double: true,
			text: true,
			textarea: true,
			bool: true,
			email: true,
			date: true,
			media: true,
			quantity: true,
			node_ref: true,
			node_embed: true,
			table: true,
			enum: true,
		};
		var fromTypeKey = norm(node.typeKey);
		var fromType = '';
		if (node.type && node.type.name != null) {
			fromType = norm(node.type.name);
		} else if (typeof node.type === 'string') {
			fromType = norm(node.type);
		}
		var fromName = norm(node.name);
		/*
		 * Catalog leaf (Data Types → Simple → int): Q88 type is parent "Simple".
		 * Prefer the leaf name when it is a known field key and the inherited
		 * type key is not (same rule as Preferred render / resolveNodeRenderTypeKey).
		 */
		if (fromName && leafKeys[fromName] && (!fromTypeKey || !leafKeys[fromTypeKey])) {
			if (!fromType || !leafKeys[fromType] || fromType === fromName) {
				return fromName;
			}
		}
		if (fromTypeKey && leafKeys[fromTypeKey]) {
			return fromTypeKey;
		}
		if (fromName && leafKeys[fromName] && (!fromType || !leafKeys[fromType])) {
			return fromName;
		}
		if (fromTypeKey) {
			return fromTypeKey;
		}
		if (fromType) {
			return fromType;
		}
		return fromName;
	}

	function intValueApi() {
		return global.WTTIntValue || null;
	}

	function makeIntConverter(id, label) {
		return {
			id: id,
			label: label,
			appliesTo: ['int'],
			canConvert: function (node) {
				return typeKeyOf(node) === 'int';
			},
			format: function (canonical) {
				var api = intValueApi();
				if (api && typeof api.format === 'function') {
					return api.format(canonical, id);
				}
				return canonical == null ? '' : String(canonical);
			},
			parse: function (text) {
				var api = intValueApi();
				if (api && typeof api.parse === 'function') {
					return api.parse(text, id);
				}
				return null;
			},
			normalize: function (raw) {
				var api = intValueApi();
				if (api && typeof api.normalize === 'function') {
					return api.normalize(raw, id);
				}
				return raw == null ? '' : String(raw);
			},
			filterLive: function (raw) {
				var api = intValueApi();
				if (api && typeof api.filterLive === 'function') {
					return api.filterLive(raw, id);
				}
				return raw == null ? '' : String(raw);
			},
		};
	}

	var Registry = {
		register: function (converter) {
			if (!converter || typeof converter.canConvert !== 'function') {
				return;
			}
			if (!converter.id) {
				converter.id = String(converter.label || 'converter-' + converters.length)
					.trim()
					.toLowerCase()
					.replace(/\s+/g, '_');
			}
			var id = String(converter.id)
				.trim()
				.toLowerCase();
			if (!id) {
				return;
			}
			converter.id = id;
			var i;
			for (i = 0; i < converters.length; i++) {
				if (
					String((converters[i] && converters[i].id) || '')
						.trim()
						.toLowerCase() === id
				) {
					converters[i] = converter;
					return;
				}
			}
			converters.push(converter);
		},

		/**
		 * @return {Array<{id:string,label:string}>}
		 */
		listCompatible: function (node) {
			var out = [];
			var seen = {};
			var i;
			for (i = 0; i < converters.length; i++) {
				var c = converters[i];
				if (!c || typeof c.canConvert !== 'function') {
					continue;
				}
				if (!c.canConvert(node)) {
					continue;
				}
				var id = String(c.id || '')
					.trim()
					.toLowerCase();
				if (!id || seen[id]) {
					continue;
				}
				seen[id] = true;
				out.push({
					id: id,
					label: String(c.label || id),
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
			for (i = 0; i < converters.length; i++) {
				if (
					String((converters[i] && converters[i].id) || '')
						.trim()
						.toLowerCase() === id
				) {
					return converters[i];
				}
			}
			return null;
		},

		/**
		 * Resolve preferred converter id for a node (compatible only).
		 *
		 * @param {object} node
		 * @return {string}
		 */
		resolvePreferredId: function (node) {
			var raw =
				(node &&
					(node.preferredConverter ||
						node.displayFormat ||
						node.intDisplayFormat ||
						(node.intConfig && node.intConfig.displayFormat) ||
						(node.typeExtras && node.typeExtras.displayFormat) ||
						(node.typeExtras && node.typeExtras.preferredConverter))) ||
				'';
			raw = String(raw || '')
				.trim()
				.toLowerCase();
			var compatible = this.listCompatible(node);
			if (!compatible.length) {
				return '';
			}
			var i;
			if (raw) {
				for (i = 0; i < compatible.length; i++) {
					if (compatible[i].id === raw) {
						return raw;
					}
				}
			}
			return compatible[0].id;
		},

		/**
		 * Format canonical value with the node's preferred converter.
		 *
		 * @param {*} canonical
		 * @param {object} node
		 * @return {string}
		 */
		formatPreferred: function (canonical, node) {
			var id = this.resolvePreferredId(node);
			var c = id ? this.getById(id) : null;
			if (c && typeof c.format === 'function') {
				return c.format(canonical, node);
			}
			return canonical == null || canonical === '' ? '' : String(canonical);
		},
	};

	/* Int converters — previously hard-coded as Number format / Int settings. */
	Registry.register(makeIntConverter('arabic', 'Arabic (decimal)'));
	Registry.register(makeIntConverter('roman', 'Roman'));
	Registry.register(makeIntConverter('binary', 'Binary'));
	Registry.register(makeIntConverter('octal', 'Octal'));
	Registry.register(makeIntConverter('hex', 'Hexadecimal'));

	/**
	 * Q109 / Q51 — quantity Präfix switch keeps physical value constant.
	 * to_si = Typ × multiplikator × prefix_root_to_si (same unit → root cancels).
	 * Bare / no prefix → multiplikator 1. Currency/FX is Q110 (out of scope).
	 */
	var Quantity = {
		/**
		 * @param {string|number|null|undefined} prefixKey id or name; empty → bare
		 * @param {Array<{id?:*,name?:*,multiplikator?:number|null}>|null|undefined} options
		 * @return {number} positive multiplikator (default 1)
		 */
		multiplikatorOf: function (prefixKey, options) {
			var key = prefixKey == null ? '' : String(prefixKey).trim();
			if (!key) {
				return 1;
			}
			var list = Array.isArray(options) ? options : [];
			var i;
			for (i = 0; i < list.length; i++) {
				var o = list[i];
				if (!o) {
					continue;
				}
				var id = o.id != null ? String(o.id) : '';
				var name = o.name != null ? String(o.name) : '';
				if (key !== id && key !== name) {
					continue;
				}
				var m = o.multiplikator;
				if (m == null || m === '') {
					return 1;
				}
				var n = Number(m);
				return isFinite(n) && n > 0 ? n : 1;
			}
			return 1;
		},

		/**
		 * @param {number} magnitude
		 * @param {number} multiplikator
		 * @param {number} [prefixRootToSi]
		 * @return {number}
		 */
		toSi: function (magnitude, multiplikator, prefixRootToSi) {
			var mag = Number(magnitude);
			var mult = Number(multiplikator);
			var root = Number(prefixRootToSi);
			if (!isFinite(mag)) {
				return NaN;
			}
			if (!isFinite(mult) || mult <= 0) {
				mult = 1;
			}
			if (!isFinite(root) || root <= 0) {
				root = 1;
			}
			return mag * mult * root;
		},

		/**
		 * Format a rescaled Typ for an <input type="number"> (trim float noise).
		 *
		 * @param {number} n
		 * @return {string}
		 */
		formatTyp: function (n) {
			if (!isFinite(n)) {
				return '';
			}
			if (n === 0) {
				return '0';
			}
			var abs = Math.abs(n);
			var s;
			if (abs >= 1e6 || (abs > 0 && abs < 1e-6)) {
				s = n.toExponential(12);
			} else {
				s = String(Number(n.toPrecision(12)));
			}
			if (s.indexOf('e') !== -1 || s.indexOf('E') !== -1) {
				return s;
			}
			if (s.indexOf('.') !== -1) {
				s = s.replace(/\.?0+$/, '');
			}
			return s;
		},

		/**
		 * Keep to_si constant when switching Präfix (same Basiseinheit).
		 *
		 * @param {string|number|null|undefined} oldTyp
		 * @param {number} oldMult
		 * @param {number} newMult
		 * @param {number} [prefixRootToSi] unused when same unit (cancels); kept for API
		 * @return {string|null} new Typ string, or null when no-op (empty/non-numeric)
		 */
		rescaleTyp: function (oldTyp, oldMult, newMult, prefixRootToSi) {
			var raw = oldTyp == null ? '' : String(oldTyp).trim().replace(',', '.');
			if (!raw) {
				return null;
			}
			var mag = Number(raw);
			if (!isFinite(mag)) {
				return null;
			}
			var om = Number(oldMult);
			var nm = Number(newMult);
			if (!isFinite(om) || om <= 0) {
				om = 1;
			}
			if (!isFinite(nm) || nm <= 0) {
				nm = 1;
			}
			if (om === nm) {
				return this.formatTyp(mag);
			}
			var root = Number(prefixRootToSi);
			if (!isFinite(root) || root <= 0) {
				root = 1;
			}
			var toSi = this.toSi(mag, om, root);
			var next = toSi / (nm * root);
			if (!isFinite(next)) {
				return null;
			}
			return this.formatTyp(next);
		},

		/**
		 * One-shot: resolve multis from option list and rescale.
		 *
		 * @param {string|number|null|undefined} oldTyp
		 * @param {string|number|null|undefined} oldPrefixKey
		 * @param {string|number|null|undefined} newPrefixKey
		 * @param {Array|null|undefined} options
		 * @param {number} [prefixRootToSi]
		 * @return {string|null}
		 */
		rescaleOnPrefixChange: function (
			oldTyp,
			oldPrefixKey,
			newPrefixKey,
			options,
			prefixRootToSi
		) {
			var oldKey = oldPrefixKey == null ? '' : String(oldPrefixKey);
			var newKey = newPrefixKey == null ? '' : String(newPrefixKey);
			if (oldKey === newKey) {
				return null;
			}
			return this.rescaleTyp(
				oldTyp,
				this.multiplikatorOf(oldKey, options),
				this.multiplikatorOf(newKey, options),
				prefixRootToSi
			);
		},
	};

	global.WTTConverter = {
		Registry: Registry,
		typeKeyOf: typeKeyOf,
		Quantity: Quantity,
	};
})(typeof window !== 'undefined' ? window : this);
