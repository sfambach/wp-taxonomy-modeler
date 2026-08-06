/**
 * Table definition validator (mirror of WTT\Table_Validator).
 * Band identity = type-prop bindings (propBindings), not child display names.
 * Zeile required 1..n fields; optional Kopf/Fuss must match Zeile count.
 */
(function (global) {
	'use strict';

	var BAND_ZEILE = 'zeile';
	var BAND_KOPF = 'kopf';
	var BAND_FUSS = 'fuss';

	function findInTree(nodes, id) {
		id = parseInt(id, 10) || 0;
		if (id <= 0 || !nodes) {
			return null;
		}
		for (var i = 0; i < nodes.length; i++) {
			var n = nodes[i];
			if (!n) {
				continue;
			}
			if (parseInt(n.id, 10) === id) {
				return n;
			}
			if (n.children && n.children.length) {
				var found = findInTree(n.children, id);
				if (found) {
					return found;
				}
			}
		}
		return null;
	}

	function compositionTargets(node) {
		var stored = node && node.relationsStored;
		var edges = [];
		if (Array.isArray(stored)) {
			edges = stored;
		} else if (stored && Array.isArray(stored.von)) {
			edges = stored.von;
		}
		var out = [];
		for (var i = 0; i < edges.length; i++) {
			var e = edges[i];
			if (!e) {
				continue;
			}
			var key = String(e.typeKey || e.type || e.typeName || '')
				.trim()
				.toLowerCase();
			if (key !== 'composition' && key !== 'besteht_aus') {
				continue;
			}
			out.push({
				id: parseInt(e.toId != null ? e.toId : e.otherId, 10) || 0,
				name: e.toName || e.otherName || '',
			});
		}
		return out;
	}

	function memberList(node, tree) {
		var viaComp = compositionTargets(node);
		if (viaComp.length) {
			return viaComp.map(function (m) {
				var live = findInTree(tree, m.id);
				return {
					id: m.id,
					name: (live && live.name) || m.name || '',
					node: live,
				};
			});
		}
		var kids = (node && node.children) || [];
		return kids.map(function (ch) {
			return {
				id: parseInt(ch.id, 10) || 0,
				name: ch.name || '',
				node: ch,
			};
		});
	}

	function fieldTypeKey(live) {
		if (!live) {
			return 'text';
		}
		var name = '';
		if (live.type && live.type.name) {
			name = live.type.name;
		} else if (live.typeLabel) {
			name = live.typeLabel;
		} else if (live.typeKey) {
			name = live.typeKey;
		}
		name = String(name)
			.trim()
			.toLowerCase();
		if (name === 'integer') {
			return 'int';
		}
		if (name === 'boolean') {
			return 'bool';
		}
		return name || 'text';
	}

	function bandFields(bandNode, tree) {
		if (!bandNode) {
			return [];
		}
		return memberList(bandNode, tree).map(function (m) {
			var live = m.node || findInTree(tree, m.id);
			var typeKey = fieldTypeKey(live);
			return {
				id: m.id,
				name: m.name,
				typeKey: typeKey,
				typeName: typeKey,
				typeId: live && live.typeId != null ? live.typeId : 0,
			};
		});
	}

	function bandKeyFromProp(prop) {
		if (!prop) {
			return null;
		}
		var k = String(prop.key || prop.id || '')
			.trim()
			.toLowerCase();
		if (k === BAND_ZEILE || k === BAND_KOPF || k === BAND_FUSS) {
			return k;
		}
		return null;
	}

	function boundChildId(bindings, prop) {
		bindings = bindings || {};
		var idKey = String(prop.id || '');
		var keyKey = String(prop.key || '');
		return (
			parseInt(bindings[idKey], 10) ||
			parseInt(bindings[keyKey], 10) ||
			0
		);
	}

	/**
	 * Resolve bands from propBindings + effectiveTypeProps (names irrelevant).
	 */
	function resolveBands(node, tree) {
		var bands = { zeile: null, kopf: null, fuss: null };
		var props =
			(node &&
				node.effectiveTypeProps &&
				node.effectiveTypeProps.length &&
				node.effectiveTypeProps) ||
			(node && node.typeProps) ||
			[];
		var bindings = (node && node.propBindings) || {};

		for (var i = 0; i < props.length; i++) {
			var prop = props[i];
			var key = bandKeyFromProp(prop);
			if (!key || bands[key]) {
				continue;
			}
			var childId = boundChildId(bindings, prop);
			if (childId <= 0) {
				continue;
			}
			var bandNode = findInTree(tree, childId);
			var name =
				(bandNode && bandNode.name) ||
				'#' + childId;
			var fields = bandFields(bandNode, tree);
			bands[key] = {
				id: childId,
				name: name,
				fieldCount: fields.length,
				fields: fields,
			};
		}
		return bands;
	}

	/**
	 * @param {object} node Node payload (isTable / isTableTypeCatalog / propBindings)
	 * @param {array} tree Full tree for child lookup
	 * @returns {{ok:boolean,blocking:boolean,errors:string[],bands:object,isCatalog:boolean}}
	 */
	function validate(node, tree) {
		var empty = {
			ok: true,
			blocking: false,
			errors: [],
			bands: { zeile: null, kopf: null, fuss: null },
			isCatalog: false,
		};
		if (!node) {
			return empty;
		}
		var isCatalog = !!node.isTableTypeCatalog;
		var isTable = isCatalog || !!node.isTable;
		if (!isTable) {
			return empty;
		}

		var bands = resolveBands(node, tree || []);
		var errors = [];
		var blocking = false;
		var zeile = bands.zeile;

		if (!zeile) {
			errors.push(
				'Table requires a Zeile band: bind the Zeile type property to a direct child node.'
			);
			blocking = true;
		} else if (!isCatalog && zeile.fieldCount < 1) {
			errors.push('Zeile must have at least one field.');
			blocking = true;
		}

		var zeileCount = zeile ? zeile.fieldCount : 0;
		[
			{ key: BAND_KOPF, label: 'Kopf' },
			{ key: BAND_FUSS, label: 'Fuss' },
		].forEach(function (opt) {
			var band = bands[opt.key];
			if (!band) {
				return;
			}
			if (!zeile) {
				errors.push(opt.label + ' is bound but Zeile is missing.');
				blocking = true;
				return;
			}
			if (!isCatalog && band.fieldCount !== zeileCount) {
				errors.push(
					opt.label +
						' has ' +
						band.fieldCount +
						' fields but Zeile has ' +
						zeileCount +
						' — counts must match.'
				);
				blocking = true;
			}
		});

		return {
			ok: errors.length === 0,
			blocking: blocking,
			errors: errors,
			bands: bands,
			isCatalog: isCatalog,
			fixes: collectFixes(bands, isCatalog),
		};
	}

	function collectFixes(bands, isCatalog) {
		if (isCatalog || !bands) {
			return [];
		}
		var fixes = [];
		var zeile = bands.zeile;
		if (!zeile) {
			fixes.push({
				band: BAND_ZEILE,
				bandId: 0,
				bandName: 'Zeile',
				action: 'create_zeile',
			});
			return fixes;
		}
		var zeileCount = zeile.fieldCount || 0;
		if (zeileCount < 1) {
			fixes.push({
				band: BAND_ZEILE,
				bandId: zeile.id || 0,
				bandName: zeile.name || 'Zeile',
				action: 'create_zeile_field',
			});
			return fixes;
		}
		[BAND_KOPF, BAND_FUSS].forEach(function (key) {
			var band = bands[key];
			if (!band) {
				return;
			}
			var missing = zeileCount - (band.fieldCount || 0);
			if (missing > 0) {
				fixes.push({
					band: key,
					bandId: band.id,
					bandName: band.name || key,
					missing: missing,
					zeileCount: zeileCount,
					action: 'create_fields',
				});
			}
		});
		return fixes;
	}

	global.WTTTableValidator = {
		validate: validate,
		resolveBands: resolveBands,
		collectFixes: collectFixes,
		BAND_ZEILE: BAND_ZEILE,
		BAND_KOPF: BAND_KOPF,
		BAND_FUSS: BAND_FUSS,
	};
})(typeof window !== 'undefined' ? window : this);
