/**
 * Value validators — Registry (0..n per node), parallel to converters/renderers.
 *
 * Each entry: { id, errorText, expression?, isDefault?, fixes? }.
 * Built-ins filter via canValidate(node). Expression is generic (any type).
 * validateAll runs configured list; first failure wins (+ optional fixes).
 *
 * Simple type defaults:
 *   int → integer_shape (+ optional int_min / int_max),
 *   double → number_shape (+ optional double_min / double_max),
 *   text/textarea → optional text_min_length / text_max_length (params.value; seed 0 / 100),
 *   char/text/textarea → optional charset_range / charset_allowlist / charset_regex
 *     (params.value string: `a-z,A-Z,0-9` | `a,b,c` | `[0-9a-z]`),
 *   email → email_shape, char → char_shape, date → date_shape, media → media_shape.
 *   text / textarea / bool → no shape default (optional builtins remain addable).
 * Bound/length validators store one numeric threshold in params.value.
 * Charset validators store a string spec in params.value.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var builtins = [];

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
			if (key === 'string' || key === 'varchar') {
				return 'text';
			}
			if (
				key === 'datetime' ||
				key === 'date_time' ||
				key === 'date-time' ||
				key === 'timestamp'
			) {
				return 'date';
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

	function defaultError(id) {
		var map = {
			integer_shape: 'Enter a whole number.',
			int_min: 'Value is below the minimum.',
			int_max: 'Value is above the maximum.',
			number_shape: 'Enter a number.',
			double_min: 'Value is below the minimum.',
			double_max: 'Value is above the maximum.',
			bool_shape: 'Enter a boolean value.',
			email_shape: 'Enter a valid email address.',
			text_shape: 'Enter text.',
			text_min_length: 'Text is shorter than the minimum length.',
			text_max_length: 'Text is longer than the maximum length.',
			char_shape: 'Enter exactly one character.',
			charset_range:
				'Value contains characters outside the allowed range(s).',
			charset_allowlist:
				'Value contains characters that are not in the allowlist.',
			charset_regex: 'Value does not match the allowed pattern.',
			date_shape: 'Enter a valid date.',
			media_shape: 'Enter a media attachment, URL, or media reference.',
			expression: 'Value does not satisfy the expression.',
		};
		return map[id] || 'Invalid value.';
	}

	function defaultIdForType(typeKey) {
		var map = {
			int: 'integer_shape',
			double: 'number_shape',
			email: 'email_shape',
			char: 'char_shape',
			date: 'date_shape',
			media: 'media_shape',
		};
		return map[typeKey] || '';
	}

	function labelFor(id) {
		var map = {
			integer_shape: 'Integer shape',
			int_min: 'Int min',
			int_max: 'Int max',
			number_shape: 'Number shape',
			double_min: 'Double min',
			double_max: 'Double max',
			bool_shape: 'Boolean shape',
			email_shape: 'Email shape',
			text_shape: 'Text shape',
			text_min_length: 'Text min length',
			text_max_length: 'Text max length',
			char_shape: 'Single character',
			charset_range: 'Charset range',
			charset_allowlist: 'Charset allowlist',
			charset_regex: 'Charset regex',
			date_shape: 'Date shape',
			media_shape: 'Media shape',
			expression: 'Expression',
		};
		return map[id] || id;
	}

	function isBoundValidatorId(id) {
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
		id = String(id || '')
			.trim()
			.toLowerCase();
		return id === 'text_min_length' || id === 'text_max_length';
	}

	function isCharsetValidatorId(id) {
		id = String(id || '')
			.trim()
			.toLowerCase();
		return (
			id === 'charset_range' ||
			id === 'charset_allowlist' ||
			id === 'charset_regex'
		);
	}

	/** Bound or length — UI shows numeric params.value threshold. */
	function isParamThresholdValidatorId(id) {
		return isBoundValidatorId(id) || isLengthValidatorId(id);
	}

	/** Any Bound-column params.value (numeric or charset string). */
	function isParamValueValidatorId(id) {
		return isParamThresholdValidatorId(id) || isCharsetValidatorId(id);
	}

	function defaultBoundValue(id) {
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

	/**
	 * Bound threshold from entry.params.value (int_* → int, double_* → float).
	 * @return {number|null}
	 */
	function boundValueFromEntry(entry) {
		if (!entry || typeof entry !== 'object') {
			return null;
		}
		var id = String(entry.id || '')
			.trim()
			.toLowerCase();
		if (isCharsetValidatorId(id)) {
			return null;
		}
		var params =
			entry.params && typeof entry.params === 'object' ? entry.params : {};
		var raw = null;
		if (params.value != null && params.value !== '') {
			raw = params.value;
		} else if (entry.value != null && entry.value !== '') {
			raw = entry.value;
		} else if (id.indexOf('_min') !== -1 && entry.min != null) {
			raw = entry.min;
		} else if (id.indexOf('_max') !== -1 && entry.max != null) {
			raw = entry.max;
		} else if (id.indexOf('_min') !== -1 && params.min != null) {
			raw = params.min;
		} else if (id.indexOf('_max') !== -1 && params.max != null) {
			raw = params.max;
		}
		if (raw == null || raw === '') {
			return null;
		}
		var n = Number(raw);
		if (!isFinite(n)) {
			return null;
		}
		if (id.indexOf('int_') === 0 || isLengthValidatorId(id)) {
			return Math.trunc(n);
		}
		return n;
	}

	/**
	 * String params.value for charset validators.
	 * @return {string|null}
	 */
	function stringParamFromEntry(entry) {
		if (!entry || typeof entry !== 'object') {
			return null;
		}
		var params =
			entry.params && typeof entry.params === 'object' ? entry.params : {};
		var raw = null;
		if (params.value != null && params.value !== '') {
			raw = params.value;
		} else if (entry.value != null && entry.value !== '') {
			raw = entry.value;
		} else if (params.pattern != null && params.pattern !== '') {
			raw = params.pattern;
		} else if (entry.pattern != null && entry.pattern !== '') {
			raw = entry.pattern;
		}
		if (raw == null) {
			return null;
		}
		var s = String(raw).trim();
		return s === '' ? null : s;
	}

	function stringLength(s) {
		s = String(s == null ? '' : s);
		if (typeof Intl !== 'undefined' && Intl.Segmenter) {
			try {
				var seg = new Intl.Segmenter(undefined, {
					granularity: 'grapheme',
				});
				var n = 0;
				for (var _ of seg.segment(s)) {
					n++;
				}
				return n;
			} catch (e) {
				/* fall through */
			}
		}
		return Array.from(s).length;
	}

	function makeLengthValidate(id) {
		return function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg = (entry && entry.errorText) || defaultError(id);
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: id };
			}
			var bound = boundValueFromEntry(
				Object.assign({}, entry || {}, { id: id })
			);
			if (bound == null) {
				return { ok: true };
			}
			var len = stringLength(s);
			if (id === 'text_min_length' && len < bound) {
				return { ok: false, message: msg, failedId: id };
			}
			if (id === 'text_max_length' && len > bound) {
				return { ok: false, message: msg, failedId: id };
			}
			return { ok: true };
		};
	}

	function splitGraphemes(value) {
		var s = value == null ? '' : String(value);
		if (!s) {
			return [];
		}
		if (typeof Intl !== 'undefined' && Intl.Segmenter) {
			try {
				var seg = new Intl.Segmenter(undefined, {
					granularity: 'grapheme',
				});
				var out = [];
				for (var part of seg.segment(s)) {
					out.push(part.segment);
				}
				return out;
			} catch (e) {
				/* fall through */
			}
		}
		return Array.from(s);
	}

	function firstCodepoint(ch) {
		if (ch == null || ch === '') {
			return null;
		}
		var cp = String(ch).codePointAt(0);
		return cp == null ? null : cp;
	}

	function parseRangeToken(token) {
		token = String(token || '').trim();
		if (!token) {
			return null;
		}
		var um = token.match(
			/^U\+([0-9A-Fa-f]{1,6})\s*-\s*U\+([0-9A-Fa-f]{1,6})$/
		);
		if (um) {
			var ulo = parseInt(um[1], 16);
			var uhi = parseInt(um[2], 16);
			if (ulo > uhi) {
				var ut = ulo;
				ulo = uhi;
				uhi = ut;
			}
			return [ulo, uhi];
		}
		var us = token.match(/^U\+([0-9A-Fa-f]{1,6})$/);
		if (us) {
			var ucp = parseInt(us[1], 16);
			return [ucp, ucp];
		}
		var dash = token.indexOf('-');
		if (dash > 0) {
			var left = token.slice(0, dash);
			var right = token.slice(dash + 1);
			var lo = firstCodepoint(left);
			var hi = firstCodepoint(right);
			if (lo == null || hi == null) {
				return null;
			}
			if (lo > hi) {
				var t = lo;
				lo = hi;
				hi = t;
			}
			return [lo, hi];
		}
		var cp = firstCodepoint(token);
		return cp == null ? null : [cp, cp];
	}

	function parseCharsetRanges(spec) {
		var parts = String(spec || '').split(/\s*,\s*/);
		var out = [];
		parts.forEach(function (part) {
			var range = parseRangeToken(part);
			if (range) {
				out.push(range);
			}
		});
		return out;
	}

	function parseCharsetAllowlist(spec) {
		var raw = String(spec == null ? '' : spec);
		if (!raw.trim()) {
			return [];
		}
		var out = [];
		var buf = '';
		var i;
		for (i = 0; i < raw.length; i++) {
			var ch = raw.charAt(i);
			if (ch === '\\' && i + 1 < raw.length && raw.charAt(i + 1) === ',') {
				buf += ',';
				i++;
				continue;
			}
			if (ch === ',') {
				var token = buf.trim();
				buf = '';
				if (token) {
					splitGraphemes(token).forEach(function (g) {
						out.push(g);
					});
				}
				continue;
			}
			buf += ch;
		}
		var last = buf.trim();
		if (last) {
			splitGraphemes(last).forEach(function (g) {
				out.push(g);
			});
		}
		return out;
	}

	function valueMatchesCharsetRange(value, spec) {
		var ranges = parseCharsetRanges(spec);
		if (!ranges.length) {
			return false;
		}
		var chars = splitGraphemes(value);
		if (!chars.length) {
			return false;
		}
		var i;
		for (i = 0; i < chars.length; i++) {
			var cp = firstCodepoint(chars[i]);
			if (cp == null) {
				return false;
			}
			var ok = false;
			var r;
			for (r = 0; r < ranges.length; r++) {
				if (cp >= ranges[r][0] && cp <= ranges[r][1]) {
					ok = true;
					break;
				}
			}
			if (!ok) {
				return false;
			}
		}
		return true;
	}

	function valueMatchesCharsetAllowlist(value, spec) {
		var allowed = parseCharsetAllowlist(spec);
		if (!allowed.length) {
			return false;
		}
		var map = {};
		allowed.forEach(function (ch) {
			map[ch] = true;
		});
		var chars = splitGraphemes(value);
		if (!chars.length) {
			return false;
		}
		var i;
		for (i = 0; i < chars.length; i++) {
			if (!map[chars[i]]) {
				return false;
			}
		}
		return true;
	}

	function valueMatchesCharsetRegex(value, pattern) {
		pattern = String(pattern || '').trim();
		if (!pattern) {
			return false;
		}
		var body = pattern;
		var flags = 'u';
		var wrap = pattern.match(/^(.)([\s\S]*)\1([imsuxADSUXJ]*)$/);
		if (wrap && wrap[1] !== '\\') {
			body = wrap[2];
			flags = wrap[3] || '';
			if (flags.indexOf('u') === -1) {
				flags += 'u';
			}
		}
		if (body.charAt(0) !== '^' || body.charAt(body.length - 1) !== '$') {
			body = '^(?:' + body + ')$';
		}
		try {
			var re = new RegExp(body, flags);
			return re.test(String(value == null ? '' : value));
		} catch (e) {
			return false;
		}
	}

	function makeCharsetValidate(id) {
		return function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg = (entry && entry.errorText) || defaultError(id);
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: id };
			}
			var spec = stringParamFromEntry(
				Object.assign({}, entry || {}, { id: id })
			);
			if (spec == null || spec === '') {
				return { ok: true };
			}
			var ok = false;
			if (id === 'charset_range') {
				ok = valueMatchesCharsetRange(s, spec);
			} else if (id === 'charset_allowlist') {
				ok = valueMatchesCharsetAllowlist(s, spec);
			} else {
				ok = valueMatchesCharsetRegex(s, spec);
			}
			if (!ok) {
				return { ok: false, message: msg, failedId: id };
			}
			return { ok: true };
		};
	}

	function makeBoundValidate(id) {
		return function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value).trim();
			var msg = (entry && entry.errorText) || defaultError(id);
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: id };
			}
			var bound = boundValueFromEntry(
				Object.assign({}, entry || {}, { id: id })
			);
			if (bound == null) {
				return { ok: true };
			}
			var isInt = id.indexOf('int_') === 0;
			if (isInt) {
				if (!/^-?\d+$/.test(s)) {
					return { ok: false, message: msg, failedId: id };
				}
				var ni = parseInt(s, 10);
				if (id === 'int_min' && ni < bound) {
					return { ok: false, message: msg, failedId: id };
				}
				if (id === 'int_max' && ni > bound) {
					return { ok: false, message: msg, failedId: id };
				}
				return { ok: true };
			}
			if (!/^-?\d+(\.\d+)?$/.test(s) || !isFinite(Number(s))) {
				return { ok: false, message: msg, failedId: id };
			}
			var nd = Number(s);
			if (id === 'double_min' && nd < bound) {
				return { ok: false, message: msg, failedId: id };
			}
			if (id === 'double_max' && nd > bound) {
				return { ok: false, message: msg, failedId: id };
			}
			return { ok: true };
		};
	}

	function isSingleCharacter(s) {
		s = String(s == null ? '' : s);
		if (!s) {
			return false;
		}
		if (typeof Intl !== 'undefined' && Intl.Segmenter) {
			try {
				var seg = new Intl.Segmenter(undefined, {
					granularity: 'grapheme',
				});
				var n = 0;
				for (var _ of seg.segment(s)) {
					n++;
					if (n > 1) {
						return false;
					}
				}
				return n === 1;
			} catch (e) {
				/* fall through */
			}
		}
		return Array.from(s).length === 1;
	}

	/**
	 * Flexible date acceptance (shape validator — not store SoT).
	 * Accepts year-only YYYY, unix (5+ digits), Ymd, common separators,
	 * and Date.parse when the string looks date-like. Rejects garbage.
	 */
	function isFlexibleDateValue(raw) {
		var s = String(raw == null ? '' : raw).trim();
		if (!s) {
			return false;
		}
		if (/^\d{4}$/.test(s)) {
			var y = parseInt(s, 10);
			return y >= 1000 && y <= 9999;
		}
		if (/^\d{8}$/.test(s)) {
			var y2 = parseInt(s.slice(0, 4), 10);
			var m2 = parseInt(s.slice(4, 6), 10);
			var d2 = parseInt(s.slice(6, 8), 10);
			var dt2 = new Date(y2, m2 - 1, d2);
			return (
				dt2.getFullYear() === y2 &&
				dt2.getMonth() === m2 - 1 &&
				dt2.getDate() === d2
			);
		}
		if (/^-?\d{5,}$/.test(s)) {
			return true;
		}
		var patterns = [
			/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/,
			/^(\d{4})[./](\d{1,2})[./](\d{1,2})$/,
			/^(\d{1,2})[./](\d{1,2})[./](\d{4})(?:[ T](\d{1,2}):(\d{2}))?$/,
		];
		var i;
		for (i = 0; i < patterns.length; i++) {
			var m = s.match(patterns[i]);
			if (!m) {
				continue;
			}
			var year;
			var month;
			var day;
			if (i < 2) {
				year = parseInt(m[1], 10);
				month = parseInt(m[2], 10);
				day = parseInt(m[3], 10);
			} else {
				day = parseInt(m[1], 10);
				month = parseInt(m[2], 10);
				year = parseInt(m[3], 10);
			}
			var dt = new Date(year, month - 1, day);
			if (
				dt.getFullYear() === year &&
				dt.getMonth() === month - 1 &&
				dt.getDate() === day
			) {
				return true;
			}
		}
		if (!/\d/.test(s) || !/[^\d]/.test(s)) {
			return false;
		}
		var parsed = Date.parse(s);
		return !isNaN(parsed);
	}

	function looksLikeMediaUrl(url) {
		var u = String(url == null ? '' : url).trim();
		if (!u) {
			return false;
		}
		if (/^(https?:)?\/\//i.test(u) || u.charAt(0) === '/') {
			return true;
		}
		try {
			var parsed = new URL(u);
			return !!parsed.protocol;
		} catch (e) {
			return false;
		}
	}

	function isMediaRefValue(value) {
		if (value == null || value === '') {
			return false;
		}
		if (typeof value === 'object') {
			var att = parseInt(value.attachment_id, 10) || 0;
			var url = value.url != null ? String(value.url).trim() : '';
			var file =
				value.filename != null ? String(value.filename).trim() : '';
			if (att > 0 || file) {
				return true;
			}
			return looksLikeMediaUrl(url);
		}
		var s = String(value).trim();
		if (/^\d+$/.test(s)) {
			return parseInt(s, 10) > 0;
		}
		if (s.charAt(0) === '{') {
			try {
				return isMediaRefValue(JSON.parse(s));
			} catch (e) {
				return false;
			}
		}
		return looksLikeMediaUrl(s);
	}

	/**
	 * Safe expression eval: only `value`, numbers, strings, comparisons, && || ! ().
	 * Returns true when valid.
	 */
	function evalExpression(expression, value) {
		var expr = String(expression || '').trim();
		if (!expr) {
			return false;
		}
		/* Block obvious injection / statements. */
		if (
			/[;`\\]|\/\/|\/\*|\bfunction\b|\breturn\b|\bwindow\b|\bglobal\b|\bthis\b|\beval\b|\bnew\b|\bimport\b/i.test(
				expr
			)
		) {
			return false;
		}
		if (!/^[0-9a-zA-Z_.\s"'!<>=&|()+\-*/%]+$/.test(expr)) {
			return false;
		}
		var coerced = value;
		if (value !== '' && value != null && /^-?\d+(\.\d+)?$/.test(String(value))) {
			coerced = Number(value);
		} else if (value === 'true' || value === true) {
			coerced = true;
		} else if (value === 'false' || value === false) {
			coerced = false;
		} else {
			coerced = value == null ? '' : String(value);
		}
		try {
			/* eslint-disable no-new-func */
			var fn = new Function('value', 'return !!( ' + expr + ' );');
			/* eslint-enable no-new-func */
			return !!fn(coerced);
		} catch (e) {
			return false;
		}
	}

	function makeBuiltin(def) {
		return {
			id: def.id,
			label: def.label || labelFor(def.id),
			appliesTo: def.appliesTo || [],
			defaultErrorText: def.defaultErrorText || defaultError(def.id),
			canValidate: function (node) {
				var key = typeKeyOf(node);
				if (!def.appliesTo || !def.appliesTo.length) {
					return true;
				}
				return def.appliesTo.indexOf(key) >= 0;
			},
			validate: def.validate,
		};
	}

	function registerBuiltin(def) {
		var v = makeBuiltin(def);
		var i;
		for (i = 0; i < builtins.length; i++) {
			if (builtins[i].id === v.id) {
				builtins[i] = v;
				return;
			}
		}
		builtins.push(v);
	}

	registerBuiltin({
		id: 'integer_shape',
		appliesTo: ['int'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg =
				(entry && entry.errorText) || defaultError('integer_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'integer_shape' };
			}
			if (!/^-?\d+$/.test(s)) {
				return { ok: false, message: msg, failedId: 'integer_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'int_min',
		appliesTo: ['int'],
		validate: makeBoundValidate('int_min'),
	});

	registerBuiltin({
		id: 'int_max',
		appliesTo: ['int'],
		validate: makeBoundValidate('int_max'),
	});

	registerBuiltin({
		id: 'number_shape',
		appliesTo: ['double'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value).trim();
			var msg = (entry && entry.errorText) || defaultError('number_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'number_shape' };
			}
			if (!/^-?\d+(\.\d+)?$/.test(s) || !isFinite(Number(s))) {
				return { ok: false, message: msg, failedId: 'number_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'double_min',
		appliesTo: ['double'],
		validate: makeBoundValidate('double_min'),
	});

	registerBuiltin({
		id: 'double_max',
		appliesTo: ['double'],
		validate: makeBoundValidate('double_max'),
	});

	registerBuiltin({
		id: 'bool_shape',
		appliesTo: ['bool'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value).trim().toLowerCase();
			var msg = (entry && entry.errorText) || defaultError('bool_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'bool_shape' };
			}
			if (
				s !== '0' &&
				s !== '1' &&
				s !== 'true' &&
				s !== 'false' &&
				s !== 'yes' &&
				s !== 'no'
			) {
				return { ok: false, message: msg, failedId: 'bool_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'email_shape',
		appliesTo: ['email'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value).trim();
			var msg = (entry && entry.errorText) || defaultError('email_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'email_shape' };
			}
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) {
				return { ok: false, message: msg, failedId: 'email_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'text_shape',
		appliesTo: ['text', 'textarea'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg = (entry && entry.errorText) || defaultError('text_shape');
			if (s === '' && opts.allowEmpty === false) {
				return { ok: false, message: msg, failedId: 'text_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'text_min_length',
		appliesTo: ['text', 'textarea'],
		validate: makeLengthValidate('text_min_length'),
	});

	registerBuiltin({
		id: 'text_max_length',
		appliesTo: ['text', 'textarea'],
		validate: makeLengthValidate('text_max_length'),
	});

	registerBuiltin({
		id: 'char_shape',
		appliesTo: ['char'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg = (entry && entry.errorText) || defaultError('char_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'char_shape' };
			}
			if (!isSingleCharacter(s)) {
				return { ok: false, message: msg, failedId: 'char_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'charset_range',
		appliesTo: ['char', 'text', 'textarea'],
		validate: makeCharsetValidate('charset_range'),
	});

	registerBuiltin({
		id: 'charset_allowlist',
		appliesTo: ['char', 'text', 'textarea'],
		validate: makeCharsetValidate('charset_allowlist'),
	});

	registerBuiltin({
		id: 'charset_regex',
		appliesTo: ['char', 'text', 'textarea'],
		validate: makeCharsetValidate('charset_regex'),
	});

	registerBuiltin({
		id: 'date_shape',
		appliesTo: ['date'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value).trim();
			var msg = (entry && entry.errorText) || defaultError('date_shape');
			if (s === '') {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'date_shape' };
			}
			if (!isFlexibleDateValue(s)) {
				return { ok: false, message: msg, failedId: 'date_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'media_shape',
		appliesTo: ['media'],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var msg = (entry && entry.errorText) || defaultError('media_shape');
			var empty =
				value == null ||
				value === '' ||
				(typeof value === 'string' && !String(value).trim());
			if (empty) {
				if (opts.allowEmpty !== false) {
					return { ok: true };
				}
				return { ok: false, message: msg, failedId: 'media_shape' };
			}
			if (!isMediaRefValue(value)) {
				return { ok: false, message: msg, failedId: 'media_shape' };
			}
			return { ok: true };
		},
	});

	registerBuiltin({
		id: 'expression',
		appliesTo: [],
		validate: function (value, opts, entry) {
			opts = opts || {};
			var s = value == null ? '' : String(value);
			var msg =
				(entry && entry.errorText) || defaultError('expression');
			if (s === '' && opts.allowEmpty !== false) {
				return { ok: true };
			}
			var expr = entry && entry.expression ? String(entry.expression) : '';
			if (!expr || !evalExpression(expr, s)) {
				return {
					ok: false,
					message: msg,
					failedId: 'expression',
					fixes: (entry && entry.fixes) || [],
				};
			}
			return { ok: true };
		},
	});

	function getBuiltin(id) {
		id = String(id || '')
			.trim()
			.toLowerCase();
		var i;
		for (i = 0; i < builtins.length; i++) {
			if (builtins[i].id === id) {
				return builtins[i];
			}
		}
		return null;
	}

	function normalizeEntry(row) {
		if (!row || typeof row !== 'object') {
			return null;
		}
		var id = String(row.id || '')
			.trim()
			.toLowerCase();
		if (!id || !getBuiltin(id)) {
			return null;
		}
		var errorText = String(row.errorText || row.error_text || '').trim();
		if (!errorText) {
			errorText = defaultError(id);
		}
		var entry = {
			id: id,
			errorText: errorText,
			isDefault: !!(row.isDefault || row.is_default),
			fixes: Array.isArray(row.fixes) ? row.fixes.slice() : [],
		};
		if (id === 'expression') {
			var expr = String(row.expression || '').trim();
			if (!expr) {
				return null;
			}
			entry.expression = expr;
		}
		if (isParamThresholdValidatorId(id)) {
			var bound = boundValueFromEntry(
				Object.assign({}, row, { id: id })
			);
			if (bound != null) {
				entry.params = { value: bound };
			}
		}
		if (isCharsetValidatorId(id)) {
			var spec = stringParamFromEntry(
				Object.assign({}, row, { id: id })
			);
			if (spec != null && spec !== '') {
				entry.params = { value: spec };
			}
		}
		return entry;
	}

	function normalizeList(raw) {
		if (!raw) {
			return [];
		}
		if (typeof raw === 'string') {
			try {
				raw = JSON.parse(raw);
			} catch (e) {
				return [];
			}
		}
		if (!Array.isArray(raw)) {
			return [];
		}
		var out = [];
		raw.forEach(function (row) {
			var n = normalizeEntry(row);
			if (n) {
				out.push(n);
			}
		});
		return out;
	}

	function defaultListForNode(node) {
		var key = typeKeyOf(node);
		var id = defaultIdForType(key);
		if (!id) {
			return [];
		}
		return [
			{
				id: id,
				errorText: defaultError(id),
				isDefault: true,
				fixes: [],
			},
		];
	}

	function effectiveList(node) {
		var stored = normalizeList(
			(node && (node.validators || node.validatorConfig)) || []
		);
		var defaults = defaultListForNode(node);
		if (!defaults.length) {
			return stored;
		}
		var defaultId = defaults[0].id;
		var has = stored.some(function (r) {
			return r.id === defaultId;
		});
		if (has) {
			return stored.map(function (r) {
				if (r.id === defaultId) {
					return Object.assign({}, r, { isDefault: true });
				}
				return r;
			});
		}
		return defaults.concat(stored);
	}

	var Registry = {
		register: registerBuiltin,
		getById: getBuiltin,
		listCompatible: function (node) {
			var out = [];
			builtins.forEach(function (b) {
				if (typeof b.canValidate === 'function' && !b.canValidate(node)) {
					return;
				}
				out.push({ id: b.id, label: b.label || labelFor(b.id) });
			});
			return out;
		},
		defaultListForNode: defaultListForNode,
		effectiveList: effectiveList,
		normalizeList: normalizeList,
		normalizeEntry: normalizeEntry,
		defaultError: defaultError,
		labelFor: labelFor,
		defaultIdForType: defaultIdForType,
		evalExpression: evalExpression,
		isFlexibleDateValue: isFlexibleDateValue,
		isMediaRefValue: isMediaRefValue,
		/**
		 * @return {{ok:boolean,message?:string,failedId?:string,fixes?:Array}}
		 */
		validateAll: function (node, value, opts) {
			var list = effectiveList(node);
			var i;
			for (i = 0; i < list.length; i++) {
				var entry = list[i];
				var builtin = getBuiltin(entry.id);
				if (!builtin || typeof builtin.validate !== 'function') {
					continue;
				}
				var result = builtin.validate(value, opts, entry);
				if (!result || !result.ok) {
					return {
						ok: false,
						message:
							(result && result.message) ||
							entry.errorText ||
							defaultError(entry.id),
						failedId: (result && result.failedId) || entry.id,
						fixes:
							(result && result.fixes) ||
							entry.fixes ||
							[],
					};
				}
			}
			return { ok: true };
		},
	};

	global.WTTValidator = {
		Registry: Registry,
		evalExpression: evalExpression,
		typeKeyOf: typeKeyOf,
		isBoundValidatorId: isBoundValidatorId,
		isLengthValidatorId: isLengthValidatorId,
		isCharsetValidatorId: isCharsetValidatorId,
		isParamThresholdValidatorId: isParamThresholdValidatorId,
		isParamValueValidatorId: isParamValueValidatorId,
		defaultBoundValue: defaultBoundValue,
		boundValueFromEntry: boundValueFromEntry,
		stringParamFromEntry: stringParamFromEntry,
	};
})(typeof window !== 'undefined' ? window : this);
