/**
 * Object presentation surfaces — Form(1 instance) + Table(n instances).
 *
 * Not the parked Collection catalog type `table` (Q90). These are presentation
 * surfaces over a node schema + filled attribute values. Field cells go through
 * WTTNodeRender.Registry by typeKey.
 *
 * Sample stubs (Kontakt) are static for now; buildExampleInstance / buildExampleList
 * are shaped so a real example factory can replace them later.
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
				sample: attr.sample != null ? String(attr.sample) : '',
			};
			if (!field.sample && Sample) {
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

	function paintFieldContent(field, value, opts) {
		opts = opts || {};
		var Reg = registry();
		var readonly = !!opts.readonly;
		var context = {
			name: opts.contextName || 'form',
			mode: readonly ? 'display' : 'edit',
			bare: true,
			hideLabel: true,
			value: value != null ? String(value) : '',
			onInput: typeof opts.onInput === 'function' ? opts.onInput : null,
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
				className: 'wtt-set-preview__row wtt-object-render__row',
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
			control.appendChild(
				paintFieldContent(field, current, {
					readonly: readonly,
					contextName: 'form',
					onInput: function (next) {
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
				'wtt-object-render wtt-object-render--table wtt-set-preview__table-wrap' +
				(options.className ? ' ' + options.className : ''),
		});
		var table = createEl('table', {
			className: 'wtt-set-preview__table wtt-object-render__table',
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
		instances.forEach(function (instance, rowIndex) {
			var tr = createEl('tr');
			fields.forEach(function (field) {
				var td = createEl('td');
				var current = readValue(instance, field);
				td.appendChild(
					paintFieldContent(field, current, {
						readonly: readonly,
						contextName: 'table',
						onInput: function (next) {
							if (typeof options.onFieldInput === 'function') {
								options.onFieldInput(field, next, instance, rowIndex);
							}
						},
					})
				);
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function schemaName(schemaNode) {
		return String((schemaNode && schemaNode.name) || '').trim();
	}

	function isKontaktSchema(schemaNode) {
		return schemaName(schemaNode).toLowerCase() === 'kontakt';
	}

	/**
	 * Static Kontakt sample (plan slice). Later replaced by example factory.
	 * @param {Array} attributes normalized or raw
	 * @return {object}
	 */
	function buildStaticKontaktInstance(attributes, variant) {
		variant = variant || 0;
		var fields = normalizeAttributes(attributes);
		var Sample = sampleApi();
		var persona =
			Sample && Sample.PERSONA
				? Sample.PERSONA
				: { firstName: 'Herbert', lastName: 'Müller', email: 'herbert@home.de' };
		var first = String(persona.firstName || 'Herbert');
		var last = String(persona.lastName || 'Müller');
		var full = (first + ' ' + last).trim();
		var email = String(persona.email || 'herbert@home.de');
		/* Light variants for table rows — same persona family. */
		var names = [full, first + ' Muster', 'Hans ' + last];
		var emails = [
			email,
			first.toLowerCase() + '.muster@example.com',
			'hans.' + last.toLowerCase() + '@example.com',
		];
		var idx = Math.abs(parseInt(variant, 10) || 0) % names.length;
		var values = {};
		fields.forEach(function (field) {
			var key = valueKey(field);
			var n = String(field.name || '').toLowerCase();
			var tk = String(field.typeKey || '').toLowerCase();
			if (tk === 'email' || n === 'e-mail' || n === 'email') {
				values[key] = emails[idx];
			} else if (n === 'name' || n === 'fullname') {
				values[key] = names[idx];
			} else if (
				n === 'vorname' ||
				n === 'first name' ||
				n === 'firstname' ||
				n === 'given name'
			) {
				values[key] = names[idx].split(' ')[0];
			} else if (
				n === 'nachname' ||
				n === 'last name' ||
				n === 'lastname' ||
				n === 'surname'
			) {
				var parts = names[idx].split(' ');
				values[key] = parts.length > 1 ? parts.slice(1).join(' ') : names[idx];
			} else if (n === 'anrede' || n === 'titel' || n === 'title') {
				values[key] = 'Herr';
			} else if (Sample && typeof Sample.forAttribute === 'function') {
				values[key] = String(Sample.forAttribute(field) || field.sample || '');
			} else {
				values[key] = field.sample || '';
			}
		});
		return {
			schemaName: 'Kontakt',
			attributes: fields,
			values: values,
			variant: idx,
		};
	}

	/**
	 * @param {Array} attributes
	 * @param {number} [count]
	 * @return {Array}
	 */
	function buildStaticKontaktList(attributes, count) {
		count = Math.max(1, parseInt(count, 10) || 3);
		var list = [];
		var i;
		for (i = 0; i < count; i++) {
			list.push(buildStaticKontaktInstance(attributes, i));
		}
		return list;
	}

	/**
	 * Generic one-instance fill (Sample_Data / attribute samples). Kontakt → static.
	 * @param {object} schemaNode
	 * @param {Array} [attributes]
	 * @return {object}
	 */
	function buildExampleInstance(schemaNode, attributes) {
		var attrs =
			attributes ||
			(schemaNode && (schemaNode.attributes || schemaNode.fields)) ||
			[];
		if (isKontaktSchema(schemaNode)) {
			return buildStaticKontaktInstance(attrs, 0);
		}
		var fields = normalizeAttributes(attrs);
		var values = {};
		fields.forEach(function (field) {
			values[valueKey(field)] = field.sample || '';
		});
		return {
			schemaName: schemaName(schemaNode),
			attributes: fields,
			values: values,
			variant: 0,
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
		if (isKontaktSchema(schemaNode)) {
			return buildStaticKontaktList(attrs, n);
		}
		var base = buildExampleInstance(schemaNode, attrs);
		var list = [];
		var i;
		for (i = 0; i < n; i++) {
			list.push({
				schemaName: base.schemaName,
				attributes: base.attributes,
				values: Object.assign({}, base.values),
				variant: i,
			});
		}
		return list;
	}

	global.WTTObjectRender = {
		normalizeAttributes: normalizeAttributes,
		renderForm: renderForm,
		renderTable: renderTable,
		isKontaktSchema: isKontaktSchema,
		buildStaticKontaktInstance: buildStaticKontaktInstance,
		buildStaticKontaktList: buildStaticKontaktList,
		buildExampleInstance: buildExampleInstance,
		buildExampleList: buildExampleList,
	};
})(typeof window !== 'undefined' ? window : this);
