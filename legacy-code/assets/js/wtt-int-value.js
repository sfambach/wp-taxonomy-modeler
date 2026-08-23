/**
 * Integer value — normalize / convert / validators (1..n).
 *
 * Canonical storage: decimal digit string ("42", "-7") — no float, no separators.
 * Display formats: arabic (default), roman, binary, octal, hex.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var DEFAULT_FORMAT = 'arabic';
	var FORMAT_IDS = ['arabic', 'roman', 'binary', 'octal', 'hex'];

	var messages = {
		intInvalid: 'Enter a whole number.',
	};

	/** Classic Roman map (1..3999). */
	var ROMAN_TABLE = [
		[1000, 'M'],
		[900, 'CM'],
		[500, 'D'],
		[400, 'CD'],
		[100, 'C'],
		[90, 'XC'],
		[50, 'L'],
		[40, 'XL'],
		[10, 'X'],
		[9, 'IX'],
		[5, 'V'],
		[4, 'IV'],
		[1, 'I'],
	];

	function configure(opts) {
		opts = opts || {};
		if (opts.i18n && opts.i18n.intInvalid) {
			messages.intInvalid = String(opts.i18n.intInvalid);
		}
	}

	function normalizeFormatId(formatId) {
		var id = String(formatId || '')
			.trim()
			.toLowerCase();
		if (FORMAT_IDS.indexOf(id) >= 0) {
			return id;
		}
		return DEFAULT_FORMAT;
	}

	/**
	 * Live filter for arabic edit: optional leading minus + digits only.
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function filterLiveArabic(raw) {
		var s = raw == null ? '' : String(raw);
		var out = '';
		var i;
		for (i = 0; i < s.length; i++) {
			var ch = s.charAt(i);
			if (ch >= '0' && ch <= '9') {
				out += ch;
				continue;
			}
			if (ch === '-' && out.length === 0) {
				out += ch;
			}
		}
		return out;
	}

	/**
	 * Live filter for the active format (edit still uses arabic digits).
	 *
	 * @param {string} raw
	 * @param {string} [formatId]
	 * @return {string}
	 */
	function filterLive(raw, formatId) {
		normalizeFormatId(formatId);
		return filterLiveArabic(raw);
	}

	/**
	 * Canonicalize a filtered arabic string: strip leading zeros (keep "0" / "-0"→"0").
	 * Empty and lone "-" stay as-is (not yet a value).
	 *
	 * @param {string} filtered
	 * @return {string}
	 */
	function canonicalizeArabic(filtered) {
		var s = filtered == null ? '' : String(filtered);
		if (s === '' || s === '-') {
			return s;
		}
		var neg = s.charAt(0) === '-';
		var digits = neg ? s.slice(1) : s;
		digits = digits.replace(/^0+(?=\d)/, '');
		if (digits === '') {
			digits = '0';
		}
		if (neg && digits === '0') {
			return '0';
		}
		return neg ? '-' + digits : digits;
	}

	/**
	 * @param {string} value Canonical or field text.
	 * @return {boolean}
	 */
	function isIntegerShape(value) {
		return /^-?\d+$/.test(String(value == null ? '' : value));
	}

	/**
	 * Normalize raw input → field text (filtered). Prefer canonicalize when complete.
	 *
	 * @param {string} raw
	 * @param {string} [formatId]
	 * @return {string}
	 */
	function normalize(raw, formatId) {
		var filtered = filterLive(raw, formatId);
		if (filtered === '' || filtered === '-') {
			return filtered;
		}
		if (isIntegerShape(filtered)) {
			return canonicalizeArabic(filtered);
		}
		return filtered;
	}

	/**
	 * Parse to canonical decimal string, or null if invalid / incomplete.
	 *
	 * @param {string} text
	 * @param {string} [formatId]
	 * @return {string|null}
	 */
	function parse(text, formatId) {
		var normalized = normalize(text, formatId);
		if (!isIntegerShape(normalized)) {
			return null;
		}
		return canonicalizeArabic(normalized);
	}

	/**
	 * @param {number} n Positive integer in classic Roman range.
	 * @return {string}
	 */
	function toRoman(n) {
		var out = '';
		var rest = n;
		var i;
		for (i = 0; i < ROMAN_TABLE.length; i++) {
			var pair = ROMAN_TABLE[i];
			var value = pair[0];
			var glyph = pair[1];
			while (rest >= value) {
				out += glyph;
				rest -= value;
			}
		}
		return out;
	}

	/**
	 * Format canonical for display using the preferred converter id.
	 *
	 * @param {string|number|null|undefined} canonical
	 * @param {string} [formatId]
	 * @return {string}
	 */
	function format(canonical, formatId) {
		var id = normalizeFormatId(formatId);
		if (canonical == null || canonical === '') {
			return '';
		}
		var s = String(canonical).trim();
		if (!isIntegerShape(s)) {
			return s;
		}
		var arabic = canonicalizeArabic(s);
		if (id === 'arabic') {
			return arabic;
		}

		var neg = arabic.charAt(0) === '-';
		var absStr = neg ? arabic.slice(1) : arabic;
		/* Keep digit string for bases; Number is fine within JS safe integer for samples. */
		var absNum = parseInt(absStr, 10);
		if (!isFinite(absNum)) {
			return arabic;
		}

		var body = '';
		if (id === 'roman') {
			/* Classic Roman: 1..3999. Outside range → arabic fallback. */
			if (neg || absNum < 1 || absNum > 3999) {
				return arabic;
			}
			body = toRoman(absNum);
		} else if (id === 'binary') {
			body = absNum.toString(2);
		} else if (id === 'octal') {
			body = absNum.toString(8);
		} else if (id === 'hex') {
			body = absNum.toString(16).toUpperCase();
		} else {
			return arabic;
		}
		return neg ? '-' + body : body;
	}

	/**
	 * @param {string} value
	 * @param {{allowEmpty?:boolean}} [opts]
	 * @return {{ok:boolean,message?:string}}
	 */
	function validateIntegerShape(value, opts) {
		opts = opts || {};
		var s = value == null ? '' : String(value);
		if (s === '') {
			if (opts.allowEmpty !== false) {
				return { ok: true };
			}
			return { ok: false, message: messages.intInvalid };
		}
		if (!isIntegerShape(s)) {
			return { ok: false, message: messages.intInvalid };
		}
		return { ok: true };
	}

	var validators = [
		{
			id: 'integer_shape',
			validate: function (value, opts) {
				return validateIntegerShape(value, opts);
			},
		},
	];

	/**
	 * Run all validators; first failure wins.
	 *
	 * @param {string} value
	 * @param {{allowEmpty?:boolean}} [opts]
	 * @return {{ok:boolean,message?:string,failedId?:string}}
	 */
	function validateAll(value, opts) {
		var i;
		for (i = 0; i < validators.length; i++) {
			var rule = validators[i];
			var result = rule.validate(value, opts);
			if (!result || !result.ok) {
				return {
					ok: false,
					message:
						(result && result.message) || messages.intInvalid,
					failedId: rule.id,
				};
			}
		}
		return { ok: true };
	}

	global.WTTIntValue = {
		DEFAULT_FORMAT: DEFAULT_FORMAT,
		FORMAT_IDS: FORMAT_IDS.slice(),
		configure: configure,
		normalizeFormatId: normalizeFormatId,
		filterLive: filterLive,
		normalize: normalize,
		parse: parse,
		format: format,
		isIntegerShape: isIntegerShape,
		validators: validators,
		validateAll: validateAll,
		messages: messages,
	};
})(typeof window !== 'undefined' ? window : this);
