/**
 * Central sample / preview values for simple data types and attributes.
 *
 * Samples are name-aware first (attribute label heuristics), then type fallback.
 * Registry maps only — not methods on nodes. Pass an attribute / type identity
 * → get a realistic default for admin preview and example DTOs.
 *
 * Mirrors includes/class-sample-data.php (WTT\Sample_Data).
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	/**
	 * Shared persona so name + email stay consistent across fields on one host.
	 */
	var PERSONA = {
		firstName: 'Herbert',
		lastName: 'Müller',
		fullName: 'Herbert Müller',
		email: 'herbert@home.de',
		phone: '+49 30 12345678',
		mobile: '+49 170 1234567',
		company: 'Muster GmbH',
		city: 'Berlin',
		zip: '10115',
		street: 'Musterstraße 1',
		title: 'Herr',
		country: 'Deutschland',
		website: 'https://www.muster.de',
		note: 'Sample note',
	};

	/**
	 * Simple catalog leaf keys (Definition/Data Types/Simple).
	 * Parked Complex kinds (enum/list/table, Q90) are intentionally absent.
	 */
	var MAP = {
		int: '42',
		double: '10.5',
		text: 'Sample',
		/* Type email → always a fake address (persona-consistent). */
		email: PERSONA.email,
		textarea: 'Sample text\nSecond line',
		char: 'A',
		bool: 'true',
		/* Unix timestamp (UTC 2024-06-15 14:30:00). Mode on type chooses date vs datetime chrome. */
		date: '1718461800',
		time: '14:30',
		datetime: '2024-06-15T14:30',
		color: '#2271b1',
		/* Read-only host presentation — live preview resolves Q117 context. */
		node_presentation: 'Node name',
		display_node_name: 'Node name',
		/*
		 * Media: descriptor object. forType() may upgrade via WTTMediaRender
		 * sampleEntries when available (richer admin chrome).
		 */
		media: {
			source: 'url',
			url: 'https://example.com/sample.png',
			mime: 'image/png',
			filename: 'beispiel.png',
		},
		/* Usage magnitude only (P1: Basiseinheit units are schema, not instances). */
		quantity: '10.5',
	};

	/**
	 * Normalized attribute-name hints → sample strings (DE + EN).
	 */
	var NAME_MAP = {
		/* Prefix is optional by domain (Meter without Milli) — never sample-force. */
		praefix: '',
		prefix: '',
		/*
		 * Kuerzel / symbol: no hardcoded glyph — node_presentation (context=symbol)
		 * reads host presentation / shortDescription.
		 */
		einheit: 'Ohm',
		unit: 'Ohm',
		name: PERSONA.firstName,
		bezeichnung: PERSONA.firstName,
		fullname: PERSONA.fullName,
		'full name': PERSONA.fullName,
		vorname: PERSONA.firstName,
		'first name': PERSONA.firstName,
		firstname: PERSONA.firstName,
		'given name': PERSONA.firstName,
		nachname: PERSONA.lastName,
		'last name': PERSONA.lastName,
		lastname: PERSONA.lastName,
		surname: PERSONA.lastName,
		'family name': PERSONA.lastName,
		email: PERSONA.email,
		'e mail': PERSONA.email,
		mail: PERSONA.email,
		datum: '1718461800',
		date: '1718461800',
		datetime: '1718461800',
		zeitpunkt: '1718461800',
		timestamp: '1718461800',
		telefon: PERSONA.phone,
		phone: PERSONA.phone,
		tel: PERSONA.phone,
		handy: PERSONA.mobile,
		mobile: PERSONA.mobile,
		firma: PERSONA.company,
		company: PERSONA.company,
		unternehmen: PERSONA.company,
		organization: PERSONA.company,
		organisation: PERSONA.company,
		stadt: PERSONA.city,
		city: PERSONA.city,
		ort: PERSONA.city,
		plz: PERSONA.zip,
		zip: PERSONA.zip,
		zipcode: PERSONA.zip,
		'zip code': PERSONA.zip,
		'postal code': PERSONA.zip,
		postcode: PERSONA.zip,
		strasse: PERSONA.street,
		street: PERSONA.street,
		adresse: PERSONA.street,
		address: PERSONA.street,
		hausnummer: '1',
		'house number': '1',
		housenumber: '1',
		postleitzahl: PERSONA.zip,
		plz: PERSONA.zip,
		titel: PERSONA.title,
		title: PERSONA.title,
		anrede: PERSONA.title,
		land: PERSONA.country,
		country: PERSONA.country,
		website: PERSONA.website,
		web: PERSONA.website,
		url: PERSONA.website,
		homepage: PERSONA.website,
		bemerkung: PERSONA.note,
		notiz: PERSONA.note,
		note: PERSONA.note,
		notes: PERSONA.note,
		comment: PERSONA.note,
		kommentar: PERSONA.note,
		/* PCB / Platine (Retro Projekt post tables). */
		platine: 'ESP8266-RS232',
		board: 'ESP8266-RS232',
		pcb: 'ESP8266-RS232',
		'bestellt wo': 'JLCPCB',
		bestelltwo: 'JLCPCB',
		'bestellt bei': 'JLCPCB',
		bestelltbei: 'JLCPCB',
		'ordered from': 'JLCPCB',
		orderedfrom: 'JLCPCB',
		gerberdatei: {
			source: 'url',
			url: 'https://example.com/esp8266-rs232-gerber.zip',
			mime: 'application/zip',
			filename: 'esp8266-rs232-gerber.zip',
		},
		gerber: {
			source: 'url',
			url: 'https://example.com/esp8266-rs232-gerber.zip',
			mime: 'application/zip',
			filename: 'esp8266-rs232-gerber.zip',
		},
		'gerber vorhanden': 'true',
		gerbervorhanden: 'true',
		stuck: '20',
		stück: '20',
		qty: '20',
		preis: '12',
		'preis inclusive': '12',
		besonderheiten: 'Lead-free, black',
		erfolgreich: 'true',
		'preis pro stück': '7',
		preisprostück: '7',
		stückpreis: '7',
		lötdauer: '20 Minuten',
		lotdauer: '20 Minuten',
		schwierigkeitsgrad: 'Mittel',
		schwierigkeitsfaktor: 'Mittel',
		funktion: 'Gut',
		'lohnt es sich': 'Ja — sinnvolle Ergänzung für das Set',
		lohntessich: 'Ja — sinnvolle Ergänzung für das Set',
		einschränkungen: 'Verbraucht einen ISA-Slot.',
		einschraenkungen: 'Verbraucht einen ISA-Slot.',
		version: '1.3',
		'meine version': '1.3',
		meineversion: '1.3',
		optionen: "Option A — Beschreibung\nOption B — Beschreibung",
		protokoll:
			'30.08.2025 — Beitrag erstellt und Platine bestellt.\n09.09.2025 — Platinen eingetroffen.',
		änderungsprotokoll:
			'30.08.2025 — Beitrag erstellt und Platine bestellt.\n09.09.2025 — Platinen eingetroffen.',
		aenderungsprotokoll:
			'30.08.2025 — Beitrag erstellt und Platine bestellt.\n09.09.2025 — Platinen eingetroffen.',
		/* Bauteillisten Position (minimal BOM line — ESP8266-RS232 PCB). */
		referenz: 'PCB',
		designator: 'PCB',
		menge: '1',
		'qty line': '1',
		beschreibung: 'ESP8266-RS232 Leiterplatte',
		description: 'ESP8266-RS232 Leiterplatte',
		'auf lager': 'true',
		auflager: 'true',
		'in stock': 'true',
	};

	function normalizeKey(raw) {
		var key = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (!key) {
			return '';
		}
		if (key.indexOf('/') !== -1) {
			var parts = key.split('/');
			key = String(parts[parts.length - 1] || '')
				.trim()
				.toLowerCase();
		}
		if (key === 'integer') {
			return 'int';
		}
		/* Unicode dashes → ASCII before slug folding. */
		key = key.replace(/[\u2010-\u2015\u2212\uFE58\uFE63\uFF0D]/g, '-');
		key = key.replace(/-/g, '_');
		if (key === 'e_mail' || key === 'mail') {
			return 'email';
		}
		if (key === 'boolean') {
			return 'bool';
		}
		if (key === 'string' || key === 'varchar') {
			return 'text';
		}
		if (key === 'float' || key === 'number') {
			return 'double';
		}
		if (
			key === 'datetime' ||
			key === 'date_time' ||
			key === 'timestamp'
		) {
			return 'date';
		}
		/* Informal / DE aliases → quantity (Größe). Not Messung; not BOM Menge. */
		if (
			key === 'measure' ||
			key === 'groesse' ||
			key === 'größe' ||
			key === 'grose'
		) {
			return 'quantity';
		}
		return key;
	}

	function normalizeNameHint(raw) {
		var hint = String(raw == null ? '' : raw)
			.trim()
			.toLowerCase();
		if (!hint) {
			return '';
		}
		hint = hint
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe')
			.replace(/ü/g, 'ue')
			.replace(/ß/g, 'ss');
		/* ASCII + common Unicode dashes/underscores → spaces. */
		hint = hint
			.replace(/[\u2010-\u2015\u2212\uFE58\uFE63\uFF0D]/g, '-')
			.replace(/[_\-.]+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
		return hint;
	}

	/**
	 * Resolve type key from string, id-like, or node-like object.
	 * Does not treat attribute display name as a type key.
	 * @param {string|number|object|null} typeNodeOrKey
	 * @return {string}
	 */
	/**
	 * Q96: reverse-lookup Registry id from catalogBindings (`builtin.*` → term id).
	 * @param {number|string} termId
	 * @return {string}
	 */
	function registryIdFromBindings(termId) {
		var id = parseInt(termId, 10) || 0;
		if (id <= 0) {
			return '';
		}
		var bindings =
			(global.wttTree && global.wttTree.catalogBindings) ||
			null;
		if (!bindings || typeof bindings !== 'object') {
			return '';
		}
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
				return normalizeKey(key.slice(prefix.length));
			}
		}
		return '';
	}

	function resolveTypeKey(typeNodeOrKey) {
		if (typeNodeOrKey == null) {
			return '';
		}
		if (typeof typeNodeOrKey === 'string' || typeof typeNodeOrKey === 'number') {
			var asStr = String(typeNodeOrKey).trim();
			/* Q96: numeric id → builtin.* binding when available. */
			if (/^\d+$/.test(asStr)) {
				return registryIdFromBindings(asStr);
			}
			return normalizeKey(asStr);
		}
		if (typeof typeNodeOrKey !== 'object') {
			return '';
		}
		var fromBind = registryIdFromBindings(
			typeNodeOrKey.typeId != null
				? typeNodeOrKey.typeId
				: typeNodeOrKey.type && typeNodeOrKey.type.id != null
					? typeNodeOrKey.type.id
					: typeNodeOrKey.id
		);
		if (fromBind) {
			return fromBind;
		}
		var fields = ['typeKey', 'typeName', 'typeLabel'];
		var i;
		for (i = 0; i < fields.length; i++) {
			if (typeNodeOrKey[fields[i]] != null && String(typeNodeOrKey[fields[i]]) !== '') {
				return normalizeKey(typeNodeOrKey[fields[i]]);
			}
		}
		if (typeNodeOrKey.type && typeNodeOrKey.type.name) {
			return normalizeKey(typeNodeOrKey.type.name);
		}
		return '';
	}

	function collectNameHints(attr) {
		var hints = [];
		if (attr == null) {
			return hints;
		}
		if (typeof attr === 'string') {
			if (!/^\d+$/.test(attr.trim())) {
				hints.push(attr);
			}
			return hints;
		}
		if (typeof attr !== 'object') {
			return hints;
		}
		var fields = [
			'name',
			'displayName',
			'display_name',
			'label',
			'shortDescription',
			'short_description',
		];
		var i;
		for (i = 0; i < fields.length; i++) {
			if (attr[fields[i]] != null && String(attr[fields[i]]) !== '') {
				hints.push(String(attr[fields[i]]));
			}
		}
		return hints;
	}

	function sampleForNameHint(raw) {
		var hint = normalizeNameHint(raw);
		if (!hint) {
			return '';
		}
		if (Object.prototype.hasOwnProperty.call(NAME_MAP, hint)) {
			return NAME_MAP[hint];
		}
		var compact = hint.replace(/\s+/g, '');
		if (compact !== hint && Object.prototype.hasOwnProperty.call(NAME_MAP, compact)) {
			return NAME_MAP[compact];
		}
		return '';
	}

	function mediaToStore(ref) {
		var Media = global.WTTMediaRender;
		if (Media && typeof Media.toStore === 'function') {
			var normalized =
				typeof Media.normalizeRef === 'function' ? Media.normalizeRef(ref) : ref;
			return Media.toStore(normalized);
		}
		try {
			return JSON.stringify(ref);
		} catch (e) {
			return '';
		}
	}

	function sampleFromTypeKey(key) {
		if (!key || !Object.prototype.hasOwnProperty.call(MAP, key)) {
			/*
			 * Parked Complex (Q90): no sample factories for enum/list/table.
			 */
			return '';
		}
		var raw = MAP[key];
		if (key === 'media') {
			var Media = global.WTTMediaRender;
			if (Media && typeof Media.sampleEntries === 'function') {
				var entries = Media.sampleEntries();
				if (entries && entries[0] && entries[0].ref) {
					return mediaToStore(entries[0].ref);
				}
			}
			return mediaToStore(raw);
		}
		return raw == null ? '' : String(raw);
	}

	/**
	 * Light row variation for table sample lists (host-agnostic).
	 * Emails get +N before @; plain strings get a short tag. JSON/URLs unchanged.
	 *
	 * @param {string} value
	 * @param {number} variantIndex
	 * @return {string}
	 */
	function applyVariant(value, variantIndex) {
		var idx = Math.abs(parseInt(variantIndex, 10) || 0);
		if (!idx || value == null || value === '') {
			return value == null ? '' : String(value);
		}
		var s = String(value);
		if (s.charAt(0) === '{' || /^https?:\/\//i.test(s)) {
			return s;
		}
		/* Leave unix timestamps / pure integers alone. */
		if (/^-?\d+$/.test(s)) {
			return s;
		}
		var at = s.indexOf('@');
		if (at > 0) {
			return s.slice(0, at) + '+' + idx + s.slice(at);
		}
		var tags = ['', ' (B)', ' (C)'];
		return s + (tags[idx % tags.length] || ' (' + (idx + 1) + ')');
	}

	function readVariantIndex(source) {
		if (!source || typeof source !== 'object') {
			return 0;
		}
		if (source.variantIndex != null) {
			return Math.abs(parseInt(source.variantIndex, 10) || 0);
		}
		if (source.variant != null) {
			return Math.abs(parseInt(source.variant, 10) || 0);
		}
		return 0;
	}

	/**
	 * Sample for an attribute/member: name heuristics, then type fallback.
	 * Does not mutate session previewValues — callers use as fallback only.
	 * Optional attr.variantIndex lightly varies the string for table rows.
	 *
	 * @param {string|object|null} attr Attribute name or node-like { name, typeKey, … }
	 * @param {string|object|null} [typeFallback] Type when attr is a bare name
	 * @return {string}
	 */
	function forAttribute(attr, typeFallback) {
		var hints = collectNameHints(attr);
		var typeKey = resolveTypeKey(attr);
		if (!typeKey && typeFallback != null) {
			typeKey = resolveTypeKey(typeFallback);
		}

		/*
		 * Optional Mult (0..) and Praefix: leave empty — Meter without Milli / Ohm
		 * without Kilo is valid; do not invent a catalog pick.
		 */
		if (attr && typeof attr === 'object') {
			var mult = String(
				attr.multiplicity || attr.fieldMultiplicity || ''
			).trim();
			var allowsEmpty =
				attr.allowsEmpty === true ||
				mult === '0' ||
				mult === '0..1' ||
				mult === '0..*';
			var nameKey = normalizeKey(attr.name || attr.displayName || '');
			if (
				allowsEmpty ||
				nameKey === 'praefix' ||
				nameKey === 'prefix' ||
				nameKey === 'prafix'
			) {
				return '';
			}
		}

		var base = '';
		/* Type email → always persona fake address. */
		if (typeKey === 'email') {
			base = PERSONA.email;
		} else if (
			typeKey === 'node_presentation' ||
			typeKey === 'display_node_name' ||
			typeKey.indexOf('node_presentation') !== -1 ||
			typeKey.indexOf('display_node_name') !== -1
		) {
			/*
			 * Host presentation field (Q117 context). Callers pass hostPresentation
			 * map and/or hostName / shortDescription.
			 */
			var ctx = 'form';
			if (attr && typeof attr === 'object') {
				if (attr.presentationConfig && attr.presentationConfig.context) {
					ctx = String(attr.presentationConfig.context)
						.trim()
						.toLowerCase();
				} else if (attr.typeExtras && attr.typeExtras.presentationContext) {
					ctx = String(attr.typeExtras.presentationContext)
						.trim()
						.toLowerCase();
				} else if (attr.presentationContext) {
					ctx = String(attr.presentationContext).trim().toLowerCase();
				}
			}
			if (ctx === 'name') {
				ctx = 'form';
			}
			var map =
				attr && attr.hostPresentation && typeof attr.hostPresentation === 'object'
					? attr.hostPresentation
					: attr && attr.presentation && typeof attr.presentation === 'object'
						? attr.presentation
						: null;
			if (map && map[ctx] != null && String(map[ctx]).trim() !== '') {
				base = String(map[ctx]).trim();
			}
			if (!base && (ctx === 'symbol' || ctx === 'table')) {
				base = String(
					(attr && (attr.hostShortDescription || attr.shortDescription)) || ''
				).trim();
			}
			if (!base && ctx === 'table') {
				base = String(
					(attr &&
						(attr.hostName ||
							attr.hostDisplayName ||
							attr.nodeName ||
							attr.schemaName)) ||
						''
				).trim();
			}
			if (!base && ctx !== 'icon' && ctx !== 'symbol') {
				base = String(
					(attr &&
						(attr.hostName ||
							attr.hostDisplayName ||
							attr.nodeName ||
							attr.schemaName)) ||
						''
				).trim();
			}
			if (!base && (ctx === 'symbol' || ctx === 'icon')) {
				base = '—';
			}
			if (!base) {
				base =
					sampleFromTypeKey('node_presentation') ||
					sampleFromTypeKey('display_node_name') ||
					'Node name';
			}
		} else {
			var host = '';
			if (attr && typeof attr === 'object') {
				host = String(attr.definedOnName || attr.hostName || '')
					.trim()
					.toLowerCase();
			}
			/* Platine.Name is a board title, not the Herbert persona. */
			if (host === 'platine') {
				for (var hi = 0; hi < hints.length; hi++) {
					var hh = String(hints[hi] || '')
						.trim()
						.toLowerCase();
					if (
						hh === 'name' ||
						hh === 'bezeichnung' ||
						hh === 'titel' ||
						hh === 'title'
					) {
						base = 'ESP8266-RS232';
						break;
					}
				}
			}
			/* Lieferant.Name — fab / distributor. */
			if (!base && host === 'lieferant') {
				for (var li = 0; li < hints.length; li++) {
					var lh = String(hints[li] || '')
						.trim()
						.toLowerCase();
					if (lh === 'name' || lh === 'firma' || lh === 'lieferant') {
						base = 'JLCPCB';
						break;
					}
				}
			}
			/* Bauteilliste.Name */
			if (!base && host === 'bauteilliste') {
				for (var bi = 0; bi < hints.length; bi++) {
					var bh = String(hints[bi] || '')
						.trim()
						.toLowerCase();
					if (
						bh === 'name' ||
						bh === 'bezeichnung' ||
						bh === 'titel' ||
						bh === 'title'
					) {
						base = 'ESP8266-RS232 BOM';
						break;
					}
				}
			}
			/* Bauteillisten Position — ESP8266-RS232 first BOM row (PCB). */
			var hostNorm = String(host || '').replace(/\s+/g, '');
			if (
				!base &&
				(hostNorm === 'position' || hostNorm === 'bauteillistenposition')
			) {
				var posMap = {
					referenz: 'PCB',
					menge: '1',
					beschreibung: 'ESP8266-RS232 Leiterplatte',
					'auf lager': 'true',
					auflager: 'true',
				};
				for (var pi = 0; pi < hints.length; pi++) {
					var ph = String(hints[pi] || '')
						.trim()
						.toLowerCase();
					if (Object.prototype.hasOwnProperty.call(posMap, ph)) {
						base = posMap[ph];
						break;
					}
				}
			}
			var i;
			if (!base) {
				for (i = 0; i < hints.length; i++) {
					var mapped = sampleForNameHint(hints[i]);
					if (mapped) {
						base = mapped;
						break;
					}
				}
			}
			if (!base) {
				base = sampleFromTypeKey(typeKey);
			}
		}

		return applyVariant(base, readVariantIndex(attr));
	}

	/**
	 * Sample value for a simple type (string). Empty when unknown / parked.
	 * Optional context.name / shortDescription enables name-aware fill.
	 * Optional context.variantIndex lightly varies multi-row samples.
	 *
	 * @param {string|number|object|null} typeNodeOrKey
	 * @param {{ name?: string, shortDescription?: string, variantIndex?: number }|null} [context]
	 * @return {string}
	 */
	function forType(typeNodeOrKey, context) {
		if (
			context &&
			(context.name ||
				context.shortDescription ||
				context.short_description ||
				context.displayName ||
				context.variantIndex != null ||
				context.variant != null)
		) {
			var attr =
				typeof typeNodeOrKey === 'object' && typeNodeOrKey
					? Object.assign({}, typeNodeOrKey, context)
					: Object.assign({ typeKey: typeNodeOrKey }, context);
			return forAttribute(attr);
		}
		return sampleFromTypeKey(resolveTypeKey(typeNodeOrKey));
	}

	/**
	 * Whether the map has an entry for this type key.
	 * @param {string|number|object|null} typeNodeOrKey
	 * @return {boolean}
	 */
	function hasType(typeNodeOrKey) {
		var key = resolveTypeKey(typeNodeOrKey);
		return !!(key && Object.prototype.hasOwnProperty.call(MAP, key));
	}

	global.WTTSampleData = {
		/* Exposed for tests / debugging — treat as read-only. */
		PERSONA: PERSONA,
		MAP: MAP,
		NAME_MAP: NAME_MAP,
		resolveTypeKey: resolveTypeKey,
		forAttribute: forAttribute,
		forType: forType,
		hasType: hasType,
	};
})(typeof window !== 'undefined' ? window : this);
