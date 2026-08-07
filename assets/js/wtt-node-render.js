/**
 * Node presentation — Registry + type renderers (not a factory).
 *
 * Single path for admin preview, backend chrome, and future frontend:
 *   WTTNodeRender.Registry.render(node, context)
 *   WTTNodeRender.Registry.renderLabel(node, context) — field designation
 *   WTTNodeRender.Registry.renderContent(node, context, readonly) — value / control
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
	var i18nLabels = {
		boolTrue: 'true',
		boolFalse: 'false',
		previewFooter: 'Footer',
		previewColGeneric: 'Column',
		emailInvalid: 'Enter a valid email address.',
	};

	/** Scalar catalog types with dedicated NodeRenderers (not set/list/media/…). */
	var SIMPLE_SCALAR_KEYS = {
		int: true,
		char: true,
		double: true,
		text: true,
		textarea: true,
		bool: true,
		email: true,
		date: true,
	};

	/** Collection / structured types with dedicated renderers. */
	var STRUCTURED_TYPE_KEYS = {
		table: true,
		enum: true,
		node_ref: true,
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
		if (!name && node.isDatatype && node.name) {
			name = String(node.name).trim().toLowerCase();
		}
		if (!name && node.name && !node.typeId) {
			name = String(node.name).trim().toLowerCase();
		}
		return name;
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
		if (Array.isArray(node.setMembers) && node.setMembers.length) {
			return node.setMembers;
		}
		if (
			node.quantitySchema &&
			Array.isArray(node.quantitySchema.members) &&
			node.quantitySchema.members.length
		) {
			return node.quantitySchema.members;
		}
		return [];
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
		var area = createEl('textarea', {
			className:
				'wtt-preview-textarea' +
				(compact ? ' wtt-preview-textarea--compact' : '') +
				(opts.inputClass ? ' ' + opts.inputClass : ''),
			rows: compact ? 2 : opts.rows || 2,
		});
		area.value = value;
		return bindValue(area, context);
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

	function findRenderer(node, context) {
		var i;
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

	/* ------------------------------------------------------------------ */
	/* Registry                                                              */
	/* ------------------------------------------------------------------ */

	var Registry = {
		register: function (renderer) {
			if (
				!renderer ||
				(typeof renderer.render !== 'function' &&
					typeof renderer.renderContent !== 'function')
			) {
				return;
			}
			renderers.push(renderer);
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
				var ctx = contentContext(context, !!readonly);
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
				var needsFill = rawVal === '';
				/*
				 * Generic text fallback ("Sample") must not stick on email fields —
				 * prefer the type/name sample map (e.g. herbert@home.de).
				 */
				if (
					!needsFill &&
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
			render: function (node, context) {
				if (!this.canRender(node, context)) {
					return false;
				}
				return composeLabeledField(this, node, context);
			},
		};
	}

	/* Samples: WTTSampleData.forAttribute(node) / forType — keep only control chrome here. */
	var IntRenderer = makeScalarRenderer('int', {
		inputType: 'number',
		step: 1,
		inputMode: 'numeric',
		inputClass: 'wtt-node-render--int',
	});

	var CharRenderer = makeScalarRenderer('char', {
		inputType: 'text',
		maxLength: 1,
		size: 1,
		inputClass: 'wtt-node-render--char',
	});

	var DoubleRenderer = makeScalarRenderer('double', {
		inputType: 'number',
		step: 'any',
		inputMode: 'decimal',
		inputClass: 'wtt-node-render--double',
	});

	var TextRenderer = makeScalarRenderer('text', {
		inputType: 'text',
		inputClass: 'wtt-node-render--text',
	});

	var EmailRenderer = makeScalarRenderer('email', {
		inputType: 'email',
		inputClass: 'wtt-node-render--email',
		placeholder: 'herbert@home.de',
		autocomplete: 'email',
		validate: 'email',
	});

	var TextareaRenderer = makeScalarRenderer('textarea', {
		control: 'textarea',
		rows: 2,
		inputClass: 'wtt-node-render--textarea',
	});

	var BoolRenderer = makeScalarRenderer('bool', {
		control: 'checkbox',
		inputClass: 'wtt-node-render--bool',
	});

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
		canRender: function (node) {
			return resolveTypeKey(node) === 'date';
		},
		renderContent: function (node, context, readonly) {
			if (!this.canRender(node, context)) {
				return false;
			}
			var ctx = contentContext(context, !!readonly);
			var rawVal = ctx.value != null ? String(ctx.value) : '';
			if (rawVal === '' || rawVal === 'Sample') {
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

	Registry.register(IntRenderer);
	Registry.register(CharRenderer);
	Registry.register(DoubleRenderer);
	Registry.register(TextRenderer);
	Registry.register(EmailRenderer);
	Registry.register(TextareaRenderer);
	Registry.register(BoolRenderer);
	Registry.register(DateRenderer);

	/**
	 * Enum (Q52): closed options → select. Options from node.enumOptions
	 * (field → Option → leaves, or direct children fallback).
	 */
	/**
	 * PARKED (Q90): catalog `enum` — legacy scaffold only. Do not extend.
	 * Prefer hierarchy + attributes / Default value for closed values.
	 */
	var EnumRenderer = {
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
	 * Distinct from Relation Mult. on has_type / ref_scope edges.
	 */
	var NodeRefRenderer = {
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
					if (typeName === 'int' || typeName === 'double') {
						inputType = 'number';
					} else if (typeName === 'email') {
						inputType = 'email';
					}
					control = createEl('input', {
						type: inputType,
						className: 'wtt-node-ref-chooser__input',
						step: typeName === 'double' ? 'any' : undefined,
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
		}
	}

	global.WTTNodeRender = {
		configure: configure,
		resolveTypeKey: resolveTypeKey,
		isSimpleScalarType: isSimpleScalarType,
		isStructuredType: isStructuredType,
		isRegisteredType: isRegisteredType,
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
		IntRenderer: IntRenderer,
		CharRenderer: CharRenderer,
		DoubleRenderer: DoubleRenderer,
		TextRenderer: TextRenderer,
		EmailRenderer: EmailRenderer,
		TextareaRenderer: TextareaRenderer,
		BoolRenderer: BoolRenderer,
		TableRenderer: TableRenderer,
		EnumRenderer: EnumRenderer,
		NodeRefRenderer: NodeRefRenderer,
		isValidEmail: isValidEmail,
		compositionMembers: compositionMembers,
		createEl: createEl,
		fieldCaption: fieldCaption,
	};
})(typeof window !== 'undefined' ? window : this);
