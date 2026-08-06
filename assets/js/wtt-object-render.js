/**
 * Object presentation surfaces — Form(1) + Table(n) + Compact(1, H|V strip).
 *
 * Not the parked Collection catalog type `table` (Q90). These are presentation
 * surfaces over a node schema + filled attribute values. Field cells go through
 * WTTNodeRender.Registry by typeKey.
 *
 * Sample stubs (Kontakt) are static for now; buildExampleInstance / buildExampleList
 * are shaped so a real example factory can replace them later.
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
				sample: attr.sample != null ? String(attr.sample) : '',
			};
			/* Festwert wins over generic type sample (e.g. Einheit → Ohm). */
			if (fest) {
				field.sample = fest;
			} else if (!field.sample && Sample) {
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

	function isMediaTypeKey(typeKey) {
		return String(typeKey || '')
			.trim()
			.toLowerCase() === 'media';
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
		return [];
	}

	function isCatalogChoiceField(field) {
		if (!field) {
			return false;
		}
		if (String(field.fixedMode || '') === 'catalog') {
			return true;
		}
		return catalogOptionsForField(field).length > 0;
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
				return opt.name || opt.path || id;
			}
		}
		return id;
	}

	/**
	 * Nested tree list for CatalogChoice depth ≥ 2 (vanilla mirror of ModelTreeChooser).
	 */
	function renderCatalogTreeChooser(options, selectedId, onPick) {
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
		host.appendChild(tree);
		return host;
	}

	function paintCatalogChoice(field, value, opts) {
		opts = opts || {};
		var options = catalogOptionsForField(field);
		var readonly = !!opts.readonly || !!(field && field.readonly);
		var selected = value != null ? String(value) : '';
		var mode = resolveCatalogChooserMode(
			options,
			field && field.choiceDepth
		);

		if (readonly) {
			var label = resolveCatalogLabel(options, selected);
			return createEl('span', {
				className: 'wtt-object-render__display',
				text: label || '—',
			});
		}

		if (mode === 'flat' || options.length <= 1) {
			var select = createEl('select', {
				className: 'wtt-type-select wtt-catalog-choice-select',
			});
			if (!options.length) {
				select.appendChild(
					createEl('option', { value: '', text: '—' })
				);
			} else {
				options.forEach(function (opt) {
					var id = opt && opt.id != null ? String(opt.id) : '';
					if (!id) {
						return;
					}
					var option = createEl('option', {
						value: id,
						text: opt.name || opt.path || id,
					});
					if (id === selected || (!selected && !select.value)) {
						option.selected = true;
						selected = id;
					}
					select.appendChild(option);
				});
				if (!selected && select.options.length) {
					select.options[0].selected = true;
					selected = select.options[0].value;
				}
			}
			if (typeof opts.onInput === 'function') {
				select.addEventListener('change', function () {
					opts.onInput(select.value);
				});
			}
			return select;
		}

		return renderCatalogTreeChooser(
			options,
			parseInt(selected, 10) || 0,
			function (id) {
				if (typeof opts.onInput === 'function') {
					opts.onInput(String(id));
				}
			}
		);
	}

	function paintFieldContent(field, value, opts) {
		opts = opts || {};
		var readonly =
			!!opts.readonly || !!(field && field.readonly);

		if (isMediaTypeKey(field && field.typeKey)) {
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

		if (isCatalogChoiceField(field)) {
			return paintCatalogChoice(field, value, opts);
		}

		var Reg = registry();
		var context = {
			name: opts.contextName || 'form',
			mode: readonly ? 'display' : 'edit',
			bare: true,
			hideLabel: true,
			value: value != null ? String(value) : '',
			onInput:
				readonly || typeof opts.onInput !== 'function' ? null : opts.onInput,
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
				var fieldReadonly = readonly || !!field.readonly;
				td.appendChild(
					paintFieldContent(field, current, {
						readonly: fieldReadonly,
						contextName: 'table',
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
				(options.className ? ' ' + options.className : ''),
			'data-orientation': orientation,
		});

		fields.forEach(function (field) {
			var cell = createEl('div', {
				className: 'wtt-object-render__compact-field',
			});
			cell.appendChild(
				createEl('label', {
					className: 'wtt-object-render__compact-label',
					text: field.name || '—',
				})
			);
			var control = createEl('div', {
				className: 'wtt-object-render__compact-control',
			});
			var current = readValue(instance, field);
			var fieldReadonly = readonly || !!field.readonly;
			control.appendChild(
				paintFieldContent(field, current, {
					readonly: fieldReadonly,
					contextName: 'compact',
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

	function isKontaktSchema(schemaNode) {
		return schemaName(schemaNode).toLowerCase() === 'kontakt';
	}

	function isPlatineSchema(schemaNode) {
		var n = schemaName(schemaNode).toLowerCase();
		return n === 'platine' || n === 'pcb' || n === 'board';
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
	 * Static Platine / PCB sample — Name = Prototype PCB (+ light table variants).
	 * @param {Array} attributes
	 * @param {number} [variant]
	 * @return {object}
	 */
	function buildStaticPlatineInstance(attributes, variant) {
		variant = variant || 0;
		var fields = normalizeAttributes(attributes);
		var Sample = sampleApi();
		var names = [
			'Prototype PCB',
			'Prototype PCB Rev B',
			'Demo Board',
		];
		var idx = Math.abs(parseInt(variant, 10) || 0) % names.length;
		var values = {};
		fields.forEach(function (field) {
			var key = valueKey(field);
			var n = String(field.name || '').toLowerCase();
			if (
				n === 'name' ||
				n === 'bezeichnung' ||
				n === 'title' ||
				n === 'titel' ||
				n === 'board' ||
				n === 'platine'
			) {
				values[key] = names[idx];
			} else if (Sample && typeof Sample.forAttribute === 'function') {
				values[key] = String(Sample.forAttribute(field) || field.sample || '');
			} else {
				values[key] = field.sample || '';
			}
		});
		return {
			schemaName: 'Platine',
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
	function buildStaticPlatineList(attributes, count) {
		count = Math.max(1, parseInt(count, 10) || 3);
		var list = [];
		var i;
		for (i = 0; i < count; i++) {
			list.push(buildStaticPlatineInstance(attributes, i));
		}
		return list;
	}

	/**
	 * Generic one-instance fill (Sample_Data / attribute samples). Kontakt / Platine → static.
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
		if (isPlatineSchema(schemaNode)) {
			return buildStaticPlatineInstance(attrs, 0);
		}
		var fields = normalizeAttributes(attrs);
		var values = {};
		fields.forEach(function (field) {
			/* Prefer Festwert / sample already resolved in normalizeAttributes. */
			values[valueKey(field)] =
				(field.fixedLabel && String(field.fixedLabel)) ||
				field.sample ||
				'';
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
		if (isPlatineSchema(schemaNode)) {
			return buildStaticPlatineList(attrs, n);
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

	/** @type {Record<string, string>} */
	var i18nStrings = {};

	function t(key, fallback) {
		if (i18nStrings && i18nStrings[key] != null && String(i18nStrings[key]) !== '') {
			return String(i18nStrings[key]);
		}
		return fallback != null ? String(fallback) : key;
	}

	/**
	 * Merge i18n (and future options) from PHP localize / Object_Render::enqueue_assets.
	 * @param {{ i18n?: Record<string, string> }} [opts]
	 */
	function configure(opts) {
		opts = opts || {};
		if (opts.i18n && typeof opts.i18n === 'object') {
			i18nStrings = Object.assign({}, i18nStrings, opts.i18n);
		}
	}

	function multiplicityAllowsMany(mult) {
		var m = String(mult || '1').trim();
		return m === '0..*' || m === '1..*';
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
				typeName: prop.typeName || '',
				typeId: prop.typeId || 0,
				multiplicity: prop.multiplicity || '1',
				allowsMany:
					!!prop.allowsMany ||
					multiplicityAllowsMany(prop.multiplicity),
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
				sample: '',
			};
			var val = '';
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
				val = String(prop.values[0]);
			} else if (prop.valueLabel != null && String(prop.valueLabel) !== '') {
				val = String(prop.valueLabel);
			} else if (Array.isArray(prop.values) && prop.values.length) {
				val = String(prop.values[0]);
			}
			if (!val && Sample && typeof Sample.forAttribute === 'function') {
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

	function normalizeLayout(layout) {
		var key = String(layout || 'form').toLowerCase();
		if (key === 'auto') {
			return 'form';
		}
		if (key === 'table' || key === 'list') {
			return 'table';
		}
		if (key === 'compact' || key === 'compact-horizontal' || key === 'compact-h') {
			return 'compact';
		}
		if (key === 'compact-vertical' || key === 'compact-v') {
			return 'compact-vertical';
		}
		return 'form';
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

	function appendManyTable(section, manyProps) {
		var maxRows = 1;
		manyProps.forEach(function (prop) {
			var vals = Array.isArray(prop.values) ? prop.values : [];
			if (!vals.length && prop.valueLabel) {
				vals = [String(prop.valueLabel)];
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
			prop._displayValues = vals;
			maxRows = Math.max(maxRows, vals.length, 1);
		});

		var wrap = createEl('div', {
			className: 'wtt-object-view__table-wrap',
		});
		var table = createEl('table', {
			className: 'wtt-object-view__table',
		});
		var thead = createEl('thead');
		var headRow = createEl('tr');
		manyProps.forEach(function (prop) {
			headRow.appendChild(
				createEl('th', {
					text: prop.name || '—',
					scope: 'col',
				})
			);
		});
		thead.appendChild(headRow);
		table.appendChild(thead);
		var tbody = createEl('tbody');
		var r;
		for (r = 0; r < maxRows; r++) {
			var tr = createEl('tr');
			manyProps.forEach(function (prop) {
				var vals = prop._displayValues || [];
				var cell = vals[r] != null ? String(vals[r]) : '';
				var td = createEl('td');
				if (cell) {
					td.textContent = cell;
				} else {
					td.appendChild(
						createEl('span', {
							className: 'wtt-object-view__empty-value',
							text: '—',
						})
					);
				}
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		}
		table.appendChild(tbody);
		wrap.appendChild(table);
		section.appendChild(wrap);
	}

	/**
	 * Mount Object View into a host element (block editor preview / mirror of SSR).
	 *
	 * Canonical form layout: meta strip → Form (Mult≤1) → Table (Mult many).
	 *
	 * @param {HTMLElement} host
	 * @param {object|null} view Object_Render get_view DTO
	 * @param {{ layout?: string }} [options] layout: form | table | compact | compact-vertical
	 */
	function mount(host, view, options) {
		options = options || {};
		if (!host) {
			return;
		}
		host.textContent = '';
		var layout = normalizeLayout(options.layout || (view && view.layout));

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
			className: 'wtt-object-view wtt-object-view--layout-' + layout,
		});

		var header = createEl('header', { className: 'wtt-object-view__header' });
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

		var section = createEl('section', {
			className: 'wtt-object-view__properties',
			'aria-label': t('properties', 'Properties'),
		});

		var parts = partitionViewProperties(view);
		var allProps =
			(view && Array.isArray(view.properties) ? view.properties : []) || [];

		if (!allProps.length) {
			section.appendChild(
				createEl('p', {
					className: 'wtt-object-view__empty',
					text: t('noProperties', 'This node has no attributes.'),
				})
			);
		} else if (layout === 'table') {
			section.appendChild(
				createEl('h4', {
					className: 'wtt-object-view__section-title',
					text: t('properties', 'Properties'),
				})
			);
			section.appendChild(
				renderTable([instanceFromView(view)], {
					readonly: true,
					className: 'wtt-object-view__table',
				})
			);
		} else if (layout === 'compact' || layout === 'compact-vertical') {
			section.appendChild(
				createEl('h4', {
					className: 'wtt-object-view__section-title',
					text: t('properties', 'Properties'),
				})
			);
			section.appendChild(
				renderCompact(instanceFromView(view), {
					readonly: true,
					orientation:
						layout === 'compact-vertical' ? 'vertical' : 'horizontal',
					className: 'wtt-object-view__compact',
				})
			);
		} else {
			if (parts.single.length) {
				section.appendChild(
					createEl('h4', {
						className: 'wtt-object-view__section-title',
						text: t('properties', 'Properties'),
					})
				);
				section.appendChild(
					renderForm(instanceFromView(view, parts.single), {
						readonly: true,
						className: 'wtt-object-view__form-surface',
					})
				);
			}
			if (parts.many.length) {
				section.appendChild(
					createEl('h4', {
						className: 'wtt-object-view__section-title',
						text: t(
							'propertiesMany',
							'Multi-value attributes'
						),
					})
				);
				appendManyTable(section, parts.many);
			}
		}

		root.appendChild(section);
		host.appendChild(root);
	}

	global.WTTObjectRender = {
		configure: configure,
		mount: mount,
		normalizeAttributes: normalizeAttributes,
		instanceFromView: instanceFromView,
		renderForm: renderForm,
		renderTable: renderTable,
		renderCompact: renderCompact,
		isKontaktSchema: isKontaktSchema,
		isPlatineSchema: isPlatineSchema,
		buildStaticKontaktInstance: buildStaticKontaktInstance,
		buildStaticKontaktList: buildStaticKontaktList,
		buildStaticPlatineInstance: buildStaticPlatineInstance,
		buildStaticPlatineList: buildStaticPlatineList,
		buildExampleInstance: buildExampleInstance,
		buildExampleList: buildExampleList,
	};
})(typeof window !== 'undefined' ? window : this);
