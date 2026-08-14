/**
 * Shared Settings / Config chrome (Q114 / Q118 / Q126).
 *
 * Config page = vertical stack of invisible boxes; each box is its own renderer(node, ctx).
 * One ConfigPage everywhere (node detail + attribute walk) — change a box → page updates all hosts.
 *
 * Canonical entry: window.WTTConfigRender (alias: window.WTTSettingsRender)
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			var val = attrs[key];
			if (val == null || val === false) {
				return;
			}
			if (key === 'text') {
				node.textContent = String(val);
				return;
			}
			if (key === 'html') {
				node.innerHTML = String(val);
				return;
			}
			if (key === 'className') {
				node.className = String(val);
				return;
			}
			if (key === 'style' && typeof val === 'string') {
				node.setAttribute('style', val);
				return;
			}
			if (key.slice(0, 2) === 'on' && typeof val === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), val);
				return;
			}
			if (val === true) {
				node.setAttribute(key, key);
				return;
			}
			node.setAttribute(key, String(val));
		});
		(children || []).forEach(function (child) {
			if (child == null) {
				return;
			}
			node.appendChild(
				typeof child === 'string' ? document.createTextNode(child) : child
			);
		});
		return node;
	}

	/**
	 * Boolean slide switch (admin-ux).
	 *
	 * @param {{
	 *   checked?: boolean,
	 *   disabled?: boolean,
	 *   title?: string,
	 *   text?: string,
	 *   onChange?: function(boolean): void
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderSlideSwitch(opts) {
		opts = opts || {};
		var checked = !!opts.checked;
		var disabled = !!opts.disabled;
		var input = el('input', {
			type: 'checkbox',
			className: 'wtt-switch__input',
			checked: checked,
			disabled: disabled,
		});
		if (typeof opts.onChange === 'function') {
			input.addEventListener('change', function () {
				opts.onChange(!!input.checked);
			});
		}
		var label = el('label', {
			className:
				'wtt-switch' +
				(disabled ? ' is-disabled' : '') +
				(checked ? ' is-on' : ''),
			title: opts.title || '',
		});
		label.appendChild(input);
		label.appendChild(
			el('span', { className: 'wtt-switch__track' }, [
				el('span', { className: 'wtt-switch__thumb' }),
			])
		);
		if (opts.text) {
			label.appendChild(
				el('span', { className: 'wtt-switch__text', text: opts.text })
			);
		}
		return label;
	}

	/** Canonical flags-per-row (admin-ux / Q118). CSS reads --wtt-flags-cols. */
	var FLAGS_COLUMNS = 5;

	/**
	 * Normalize columns to a positive int (default 5).
	 *
	 * @param {number|string|undefined} columns
	 * @return {number}
	 */
	function normalizeFlagsColumns(columns) {
		var n = parseInt(columns, 10);
		if (!n || n < 1) {
			return FLAGS_COLUMNS;
		}
		return n;
	}

	/**
	 * One flag as slide switch (label text beside the track).
	 *
	 * @param {{
	 *   label?: string,
	 *   checked?: boolean,
	 *   disabled?: boolean,
	 *   title?: string,
	 *   onChange?: function(boolean): void
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderFlagSwitch(opts) {
		opts = opts || {};
		return el(
			'div',
			{
				className:
					'wtt-form__meta-flag wtt-settings-flag' +
					(opts.disabled ? ' is-muted' : ''),
				title: opts.title || '',
			},
			[
				renderSlideSwitch({
					checked: !!opts.checked,
					disabled: !!opts.disabled,
					title: opts.title || '',
					text: opts.label || '',
					onChange: opts.onChange,
				}),
			]
		);
	}

	/**
	 * Flags grid — N per row (default 5), then wrap.
	 * Prefer plain flag descriptors; prebuilt .wtt-form__meta-flag / .wtt-settings-flag
	 * nodes are accepted for thin wrappers. Do not pass checkboxes here.
	 *
	 * @param {{
	 *   flags?: Array<{label:string,checked?:boolean,disabled?:boolean,title?:string,onChange?:Function}|HTMLElement|null|undefined>,
	 *   columns?: number,
	 *   className?: string,
	 *   inRow?: boolean
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderFlagsStrip(opts) {
		opts = opts || {};
		var columns = normalizeFlagsColumns(opts.columns);
		var strip = el('div', {
			className:
				'wtt-form__meta-strip wtt-form__meta-strip--flags wtt-settings-flags' +
				(opts.inRow ? ' wtt-form__meta-strip--in-row' : '') +
				(opts.className ? ' ' + opts.className : ''),
		});
		strip.style.setProperty('--wtt-flags-cols', String(columns));
		(opts.flags || []).forEach(function (flag) {
			if (!flag) {
				return;
			}
			/* Prebuilt flag cell from a thin wrapper — keep layout, do not wrap again. */
			if (flag.nodeType === 1) {
				var cls = flag.className || '';
				if (
					cls.indexOf('wtt-form__meta-flag') !== -1 ||
					cls.indexOf('wtt-settings-flag') !== -1
				) {
					strip.appendChild(flag);
					return;
				}
				/* Unknown DOM (e.g. legacy checkbox) — skip; callers must use descriptors. */
				return;
			}
			if (typeof flag !== 'object') {
				return;
			}
			strip.appendChild(renderFlagSwitch(flag));
		});
		return strip;
	}

	/**
	 * Shared settings form row (label | control | help) — foundation for node config chrome.
	 *
	 * @param {{
	 *   label?: string,
	 *   controls?: Array<Node|null|undefined>|Node|null,
	 *   help?: string|Node|null,
	 *   className?: string,
	 *   htmlFor?: string,
	 *   labelExtra?: Node|Array<Node>|null
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderFormRow(opts) {
		opts = opts || {};
		var row = el('div', {
			className:
				'wtt-form__row wtt-settings-row' +
				(opts.className ? ' ' + opts.className : ''),
		});
		var labelCol = el('div', { className: 'wtt-form__label' });
		if (opts.htmlFor) {
			labelCol.appendChild(
				el('label', {
					text: opts.label || '',
					htmlFor: opts.htmlFor,
				})
			);
		} else {
			labelCol.appendChild(
				el('span', {
					className: 'wtt-form__label-text',
					text: opts.label || '',
				})
			);
		}
		if (opts.labelExtra) {
			if (opts.labelExtra.nodeType) {
				labelCol.appendChild(opts.labelExtra);
			} else if (Array.isArray(opts.labelExtra)) {
				opts.labelExtra.forEach(function (n) {
					if (n) {
						labelCol.appendChild(n);
					}
				});
			}
		}
		var controlCol = el('div', { className: 'wtt-form__control' });
		var controls = Array.isArray(opts.controls)
			? opts.controls
			: [opts.controls];
		controls.forEach(function (node) {
			if (node) {
				controlCol.appendChild(node);
			}
		});
		var helpCol = el('div', { className: 'wtt-form__help' });
		if (opts.help != null && opts.help !== '') {
			if (opts.help.nodeType) {
				helpCol.appendChild(opts.help);
			} else {
				helpCol.appendChild(
					el('span', {
						className: 'wtt-field-hint',
						title: String(opts.help),
						text: '?',
					})
				);
			}
		}
		row.appendChild(labelCol);
		row.appendChild(controlCol);
		row.appendChild(helpCol);
		return row;
	}

	/**
	 * Flags as a settings form row: label + 5-column strip + help.
	 * Canonical entry for node / set / media / attribute flag chrome.
	 *
	 * @param {{
	 *   label?: string,
	 *   help?: string|Node|null,
	 *   flags?: Array<object|HTMLElement|null|undefined>,
	 *   columns?: number,
	 *   className?: string,
	 *   stripClassName?: string
	 * }} opts
	 * @return {HTMLElement|null} null when no flags
	 */
	function renderFlagsRow(opts) {
		opts = opts || {};
		var flags = (opts.flags || []).filter(function (f) {
			return !!f;
		});
		if (!flags.length) {
			return null;
		}
		var strip = renderFlagsStrip({
			flags: flags,
			columns: opts.columns,
			className: opts.stripClassName || '',
			inRow: true,
		});
		return renderFormRow({
			label: opts.label || 'Flags',
			help: opts.help,
			controls: [strip],
			className:
				'wtt-form__row--flags' +
				(opts.className ? ' ' + opts.className : ''),
		});
	}

	/**
	 * Identity / Display section chrome.
	 *
	 * @param {{
	 *   title?: string,
	 *   hint?: string,
	 *   className?: string,
	 *   children?: Array<Node|null|undefined>
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderSection(opts) {
		opts = opts || {};
		var section = el('div', {
			className:
				'wtt-form-section wtt-settings-section' +
				(opts.className ? ' ' + opts.className : ''),
		});
		if (opts.title) {
			section.appendChild(
				el('h4', {
					className: 'wtt-form-section__title',
					text: opts.title,
				})
			);
		}
		if (opts.hint) {
			section.appendChild(
				el('p', {
					className: 'wtt-form-section__hint',
					text: opts.hint,
				})
			);
		}
		(opts.children || []).forEach(function (child) {
			if (child) {
				section.appendChild(child);
			}
		});
		return section;
	}

	/**
	 * Build or reuse a <select>.
	 *
	 * @param {{
	 *   select?: HTMLElement,
	 *   className?: string,
	 *   disabled?: boolean,
	 *   value?: string,
	 *   options?: Array<{value:string,label:string,selected?:boolean,title?:string}>,
	 *   onChange?: function(string): void
	 * }} spec
	 * @return {HTMLElement|null}
	 */
	function resolveSelect(spec) {
		if (!spec) {
			return null;
		}
		if (spec.select) {
			return spec.select;
		}
		var select = el('select', {
			className: spec.className || 'wtt-settings-select',
			disabled: !!spec.disabled,
		});
		(spec.options || []).forEach(function (opt) {
			if (!opt) {
				return;
			}
			var o = el('option', {
				value: opt.value != null ? String(opt.value) : '',
				text: opt.label != null ? String(opt.label) : '',
			});
			if (opt.title) {
				o.title = String(opt.title);
			}
			if (
				opt.selected === true ||
				(opt.selected == null &&
					spec.value != null &&
					String(spec.value) === String(opt.value))
			) {
				o.selected = true;
			}
			select.appendChild(o);
		});
		if (typeof spec.onChange === 'function') {
			select.addEventListener('change', function () {
				spec.onChange(String(select.value || ''));
			});
		}
		return select;
	}

	function resolveLabel(spec, fallback) {
		if (!spec) {
			return el('span', {
				className: 'wtt-preferred-pair__label',
				text: fallback || '',
			});
		}
		if (spec.labelNode) {
			return spec.labelNode;
		}
		return el('span', {
			className: 'wtt-preferred-pair__label',
			text: spec.label || fallback || '',
		});
	}

	function appendPreferredItem(kids, spec, fallback) {
		if (!spec) {
			return;
		}
		var select = resolveSelect(spec);
		if (!select && !(spec.label || spec.labelNode || spec.emptyText)) {
			return;
		}
		var item = el('div', { className: 'wtt-preferred-pair__item' });
		if (spec.labelNode) {
			item.appendChild(spec.labelNode);
		} else {
			item.appendChild(
				el('span', { className: 'wtt-preferred-pair__label-row' }, [
					resolveLabel(spec, fallback),
				])
			);
		}
		if (select) {
			item.appendChild(select);
		} else if (spec.emptyText) {
			item.appendChild(
				el('span', {
					className: 'description',
					text: String(spec.emptyText),
				})
			);
		}
		kids.push(item);
	}

	/**
	 * Preferred Render | Converter | Validators — one row (Q114).
	 * Optional detail (validators table/list) below.
	 *
	 * @param {{
	 *   className?: string,
	 *   triple?: boolean,
	 *   render?: object,
	 *   converter?: object,
	 *   validators?: object,
	 *   detail?: HTMLElement|null
	 * }} opts
	 * @return {HTMLElement}
	 */
	function renderPreferredChrome(opts) {
		opts = opts || {};
		var kids = [];
		appendPreferredItem(kids, opts.render, 'Render');
		appendPreferredItem(kids, opts.converter, 'Converter');
		if (
			opts.validators ||
			opts.triple === true ||
			opts.detail
		) {
			appendPreferredItem(
				kids,
				opts.validators || { label: 'Validators', emptyText: '—' },
				'Validators'
			);
		}

		var pairClass =
			'wtt-preferred-pair wtt-settings-preferred' +
			(kids.length >= 3 ? ' wtt-preferred-pair--triple' : '') +
			(opts.className ? ' ' + opts.className : '');
		var pair = el('div', { className: pairClass }, kids);

		var root = el('div', {
			className: 'wtt-settings-preferred-chrome',
		});
		root.appendChild(pair);

		var detail =
			opts.detail ||
			(opts.validators && opts.validators.detail
				? opts.validators.detail
				: null);
		if (detail) {
			root.appendChild(detail);
		}
		return root;
	}

	/**
	 * Fixed vertical order for the configuration page (Q126).
	 * Page shell only stacks these boxes — it does not paint field chrome itself.
	 */
	var CONFIG_BOX_ORDER = [
		'actions',
		'meta',
		'identitySettings',
		'bools',
		'childNodes',
		'display',
		'attributes',
		'preview',
		'relations',
	];

	/** @type {Object.<string, {canRender?: Function, render: Function}>} */
	var configBoxRegistry = {};

	/**
	 * Register or replace a config page box renderer.
	 * Hosts (tree-admin) register once; ConfigPageRender calls them in BOX_ORDER.
	 *
	 * @param {string} id One of CONFIG_BOX_ORDER
	 * @param {{canRender?: function(*,*): boolean, render: function(*,*): (HTMLElement|Array|DocumentFragment|null)}} renderer
	 */
	function registerConfigBox(id, renderer) {
		id = String(id || '');
		if (!id || !renderer || typeof renderer.render !== 'function') {
			return;
		}
		configBoxRegistry[id] = renderer;
	}

	/**
	 * @param {string} id
	 * @return {{canRender?: Function, render: Function}|null}
	 */
	function getConfigBox(id) {
		return configBoxRegistry[String(id || '')] || null;
	}

	/**
	 * Configuration page shell — invisible boxes top → bottom.
	 * Every admin config surface must use this entry so box changes apply everywhere.
	 *
	 * @param {object} node
	 * @param {{
	 *   boxes?: Object.<string, {canRender?: Function, render: Function}>,
	 *   className?: string
	 * }} [ctx]
	 * @return {HTMLElement}
	 */
	function renderConfigPage(node, ctx) {
		ctx = ctx || {};
		var page = el('div', {
			className:
				'wtt-config-page' + (ctx.className ? ' ' + ctx.className : ''),
		});
		CONFIG_BOX_ORDER.forEach(function (id) {
			var box =
				(ctx.boxes && ctx.boxes[id]) || configBoxRegistry[id] || null;
			if (!box || typeof box.render !== 'function') {
				return;
			}
			if (
				typeof box.canRender === 'function' &&
				!box.canRender(node, ctx)
			) {
				return;
			}
			var content = box.render(node, ctx);
			if (!content) {
				return;
			}
			var slot = el('div', {
				className: 'wtt-config-box wtt-config-box--' + id,
				'data-wtt-config-box': id,
			});
			if (content.nodeType === 11) {
				/* DocumentFragment */
				slot.appendChild(content);
			} else if (content.nodeType) {
				slot.appendChild(content);
			} else if (Array.isArray(content)) {
				content.forEach(function (child) {
					if (child) {
						slot.appendChild(child);
					}
				});
			} else {
				return;
			}
			page.appendChild(slot);
		});
		return page;
	}

	global.WTTConfigRender = {
		FLAGS_COLUMNS: FLAGS_COLUMNS,
		BOX_ORDER: CONFIG_BOX_ORDER.slice(),
		el: el,
		registerBox: registerConfigBox,
		getBox: getConfigBox,
		renderPage: renderConfigPage,
		renderSlideSwitch: renderSlideSwitch,
		renderFlagSwitch: renderFlagSwitch,
		renderFlagsStrip: renderFlagsStrip,
		renderFormRow: renderFormRow,
		renderFlagsRow: renderFlagsRow,
		renderSection: renderSection,
		renderPreferredChrome: renderPreferredChrome,
	};

	/* Alias — same module; prefer WTTConfigRender for new call sites. */
	global.WTTSettingsRender = global.WTTConfigRender;
})(typeof window !== 'undefined' ? window : this);
