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
		/* Read-only host name — live preview prefers member.displayName/name. */
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
		praefix: 'm',
		prefix: 'm',
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
	function resolveTypeKey(typeNodeOrKey) {
		if (typeNodeOrKey == null) {
			return '';
		}
		if (typeof typeNodeOrKey === 'string' || typeof typeNodeOrKey === 'number') {
			var asStr = String(typeNodeOrKey).trim();
			/* Numeric ids alone are not resolvable client-side without a term name. */
			if (/^\d+$/.test(asStr)) {
				return '';
			}
			return normalizeKey(asStr);
		}
		if (typeof typeNodeOrKey !== 'object') {
			return '';
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

		var base = '';
		/* Type email → always persona fake address. */
		if (typeKey === 'email') {
			base = PERSONA.email;
		} else {
			var i;
			for (i = 0; i < hints.length; i++) {
				var mapped = sampleForNameHint(hints[i]);
				if (mapped) {
					base = mapped;
					break;
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
