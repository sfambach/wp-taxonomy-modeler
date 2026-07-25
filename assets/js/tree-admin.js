(function () {
	'use strict';

	var cfg = window.wttTree || {};
	var i18n = cfg.i18n || {};
	var state = {
		taxonomy: cfg.taxonomy || 'category',
		tree: Array.isArray(cfg.tree) ? cfg.tree : [],
		selectedId: null,
		selectedNode: null,
		expanded: {},
		error: '',
	};

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'className') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key.indexOf('on') === 0) {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(child);
			}
		});
		return node;
	}

	function post(action, data) {
		var body = new window.URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');
		body.set('taxonomy', state.taxonomy);
		Object.keys(data || {}).forEach(function (key) {
			body.set(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).then(function (res) {
			return res.json();
		});
	}

	function setError(message) {
		state.error = message || '';
		render();
	}

	function selectNode(id) {
		state.selectedId = id;
		state.selectedNode = null;
		state.error = '';
		render();
		post('wtt_get_node', { term_id: id })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.selectedNode = json.data.node;
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function refreshTree(tree) {
		state.tree = tree || [];
		if (state.selectedId) {
			selectNode(state.selectedId);
		} else {
			render();
		}
	}

	function createTerm(parent) {
		var promptText = parent ? i18n.promptChild : i18n.promptRoot;
		var name = window.prompt(promptText, '');
		if (name === null) {
			return;
		}
		name = String(name).trim();
		if (!name) {
			return;
		}
		post('wtt_create_term', { name: name, parent: parent || 0 })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				if (json.data.node && json.data.node.id) {
					state.selectedId = json.data.node.id;
					state.selectedNode = json.data.node;
					if (parent) {
						state.expanded[parent] = true;
					}
				}
				state.error = '';
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function deleteSelected() {
		if (!state.selectedNode) {
			return;
		}
		var node = state.selectedNode;
		if (!node.hasChildren) {
			if (!window.confirm(i18n.confirmLeaf)) {
				return;
			}
			runDelete('leaf');
			return;
		}
		showDeleteDialog();
	}

	function runDelete(mode) {
		post('wtt_delete_term', { term_id: state.selectedId, mode: mode })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.selectedId = null;
				state.selectedNode = null;
				state.tree = json.data.tree || [];
				state.error = '';
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function showDeleteDialog() {
		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog', role: 'dialog' }, [
				el('h2', { text: i18n.dialogTitle }),
				el('p', { text: i18n.dialogText }),
				el('div', { className: 'wtt-dialog__actions' }, [
					el('button', {
						type: 'button',
						className: 'button button-primary',
						text: i18n.promoteChildren,
						onClick: function () {
							document.body.removeChild(backdrop);
							runDelete('promote');
						},
					}),
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.deleteChildren,
						onClick: function () {
							document.body.removeChild(backdrop);
							runDelete('cascade');
						},
					}),
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.cancel,
						onClick: function () {
							document.body.removeChild(backdrop);
						},
					}),
				]),
			]),
		]);
		document.body.appendChild(backdrop);
	}

	function renderTreeNodes(nodes, list) {
		nodes.forEach(function (node) {
			var hasChildren = node.hasChildren || (node.children && node.children.length);
			var isExpanded = !!state.expanded[node.id];
			var row = el('div', {
				className: 'wtt-tree__row' + (state.selectedId === node.id ? ' is-active' : ''),
			});

			if (hasChildren) {
				row.appendChild(
					el('button', {
						type: 'button',
						className: 'wtt-tree__toggle',
						'aria-expanded': isExpanded ? 'true' : 'false',
						onClick: function (e) {
							e.stopPropagation();
							state.expanded[node.id] = !state.expanded[node.id];
							render();
						},
						html:
							'<span class="dashicons dashicons-arrow-' +
							(isExpanded ? 'down' : 'right') +
							'"></span>',
					})
				);
			} else {
				row.appendChild(el('span', { className: 'wtt-tree__toggle wtt-tree__toggle--spacer' }));
			}

			row.appendChild(
				el('button', {
					type: 'button',
					className: 'wtt-tree__name',
					text: node.name,
					onClick: function () {
						selectNode(node.id);
					},
				})
			);

			var li = el('li', { className: 'wtt-tree__node' }, [row]);
			if (hasChildren) {
				var childList = el('ul', {
					className: 'wtt-tree__children' + (isExpanded ? '' : ' is-collapsed'),
				});
				renderTreeNodes(node.children || [], childList);
				li.appendChild(childList);
			}
			list.appendChild(li);
		});
	}

	function saveNode(payload, keepSelection) {
		return post('wtt_update_node', {
			term_id: state.selectedId,
			payload: JSON.stringify(payload || {}),
		}).then(function (json) {
			if (!json || !json.success) {
				setError((json && json.data && json.data.message) || i18n.error);
				return null;
			}
			state.tree = json.data.tree || state.tree;
			state.selectedNode = json.data.node;
			state.error = '';
			if (!keepSelection) {
				render();
			} else {
				render();
			}
			return json.data.node;
		});
	}

	function fieldBlock(labelText, control) {
		return el('div', { className: 'wtt-field' }, [
			el('label', { text: labelText }),
			control,
		]);
	}

	function renderDetail() {
		var pane = el('div', { className: 'wtt-detail-pane' });
		if (state.error) {
			pane.appendChild(el('p', { className: 'wtt-error', text: state.error }));
		}
		if (!state.selectedId) {
			pane.appendChild(el('p', { className: 'wtt-empty', text: i18n.selectHint }));
			return pane;
		}
		if (!state.selectedNode) {
			pane.appendChild(el('p', { className: 'wtt-empty', text: i18n.loading }));
			return pane;
		}

		var n = state.selectedNode;
		var meta = n.meta || {};
		var draft = {
			name: n.name || '',
			description: n.description || '',
			template: !!meta.template,
			required: !!meta.required,
			footerOp: meta.footerOp || 'none',
			hasType: meta.hasType || '',
			refScope: meta.refScope || '',
			parameters: Array.isArray(meta.parameters)
				? meta.parameters.map(function (p) {
						return {
							id: p.id || '',
							name: p.name || '',
							type: p.type || '',
							required: !!p.required,
							footerOp: p.footerOp || 'none',
							refScope: p.refScope || null,
						};
				  })
				: [],
		};

		pane.appendChild(el('h2', { className: 'wtt-detail-title', text: n.name }));
		pane.appendChild(el('h3', { className: 'wtt-subtitle', text: i18n.attributes || 'Node attributes' }));

		var nameInput = el('input', { type: 'text', className: 'wtt-input', value: draft.name });
		nameInput.value = draft.name;
		var descInput = el('textarea', { className: 'wtt-input', rows: '3' });
		descInput.value = draft.description;
		pane.appendChild(fieldBlock(i18n.name, nameInput));
		pane.appendChild(fieldBlock(i18n.description, descInput));

		var metaLine = el('p', {
			className: 'wtt-muted',
			text:
				'slug: ' +
				n.slug +
				' | parent: ' +
				(n.parentName || i18n.none) +
				' | id: ' +
				n.id,
		});
		pane.appendChild(metaLine);

		var templateCb = el('input', { type: 'checkbox' });
		templateCb.checked = draft.template;
		pane.appendChild(
			el('label', { className: 'wtt-check' }, [
				templateCb,
				el('span', { text: ' ' + (i18n.templateFlag || 'Template node') }),
			])
		);

		// Type binding
		var typeBlock = el('div', { className: 'wtt-block' });
		typeBlock.appendChild(el('h3', { className: 'wtt-subtitle', text: i18n.typeBinding || 'Type binding' }));
		typeBlock.appendChild(el('p', { className: 'wtt-muted', text: i18n.typeHint || '' }));

		var typeSel = el('select', { className: 'wtt-input' });
		typeSel.appendChild(el('option', { value: '', text: i18n.noType || '- no type -' }));
		(meta.typeOptions || []).forEach(function (opt) {
			var o = el('option', { value: String(opt.id), text: opt.path || opt.label });
			if (String(opt.id) === String(draft.hasType)) {
				o.selected = true;
			}
			typeSel.appendChild(o);
		});
		typeBlock.appendChild(fieldBlock(i18n.type || 'Type', typeSel));

		var scopeWrap = el('div', { className: 'wtt-scope-wrap' });
		function refreshScopeVisibility() {
			var selectedOpt = null;
			(meta.typeOptions || []).forEach(function (opt) {
				if (String(opt.id) === String(typeSel.value)) {
					selectedOpt = opt;
				}
			});
			var isSubtree =
				!!selectedOpt &&
				(selectedOpt.label === 'subtree' ||
					/(^|\/)subtree$/.test(String(selectedOpt.path || '').replace(/\s+/g, '')));
			scopeWrap.style.display = isSubtree ? '' : 'none';
		}
		var scopeSel = el('select', { className: 'wtt-input' });
		scopeSel.appendChild(el('option', { value: '', text: i18n.noScope || '- choose catalog root -' }));
		(meta.scopeOptions || []).forEach(function (opt) {
			var o = el('option', { value: String(opt.id), text: opt.path || opt.label });
			if (String(opt.id) === String(draft.refScope)) {
				o.selected = true;
			}
			scopeSel.appendChild(o);
		});
		scopeWrap.appendChild(fieldBlock(i18n.refScope || 'ref_scope', scopeSel));
		typeBlock.appendChild(scopeWrap);
		typeSel.addEventListener('change', refreshScopeVisibility);
		refreshScopeVisibility();
		pane.appendChild(typeBlock);

		// Required / footer for slot-like
		if (meta.slotLike || draft.hasType) {
			var reqBlock = el('div', { className: 'wtt-block' });
			var reqCb = el('input', { type: 'checkbox' });
			reqCb.checked = draft.required;
			reqBlock.appendChild(
				el('label', { className: 'wtt-check' }, [
					reqCb,
					el('span', { text: ' ' + (i18n.required || 'Required') }),
				])
			);
			reqBlock.appendChild(el('p', { className: 'wtt-muted', text: i18n.requiredHint || '' }));
			var footerSel = el('select', { className: 'wtt-input' });
			['none', 'label', 'sum', 'avg', 'min', 'max', 'count'].forEach(function (op) {
				var o = el('option', { value: op, text: op });
				if (op === draft.footerOp) {
					o.selected = true;
				}
				footerSel.appendChild(o);
			});
			reqBlock.appendChild(fieldBlock(i18n.footerOp || 'Footer op', footerSel));
			pane.appendChild(reqBlock);
			draft._reqCb = reqCb;
			draft._footerSel = footerSel;
		}

		// Parameters
		var paramBlock = el('div', { className: 'wtt-block' });
		paramBlock.appendChild(el('h3', { className: 'wtt-subtitle', text: i18n.parameters || 'Parameters' }));
		var paramList = el('div', { className: 'wtt-param-list' });

		function redrawParams() {
			paramList.innerHTML = '';
			draft.parameters.forEach(function (p, idx) {
				var row = el('div', { className: 'wtt-param-row' });
				var nameIn = el('input', { type: 'text', className: 'wtt-input', placeholder: i18n.paramName || 'Name' });
				nameIn.value = p.name;
				nameIn.addEventListener('input', function () {
					draft.parameters[idx].name = nameIn.value;
				});
				var typeIn = el('select', { className: 'wtt-input' });
				typeIn.appendChild(el('option', { value: '', text: i18n.noType || '- type -' }));
				(meta.typeOptions || []).forEach(function (opt) {
					var o = el('option', { value: String(opt.id), text: opt.path || opt.label });
					if (String(opt.id) === String(p.type)) {
						o.selected = true;
					}
					typeIn.appendChild(o);
				});
				typeIn.addEventListener('change', function () {
					draft.parameters[idx].type = typeIn.value ? parseInt(typeIn.value, 10) : '';
				});
				var req = el('input', { type: 'checkbox', title: i18n.required || 'Required' });
				req.checked = !!p.required;
				req.addEventListener('change', function () {
					draft.parameters[idx].required = req.checked;
				});
				var rm = el('button', {
					type: 'button',
					className: 'button-link-delete',
					text: i18n.remove || 'Remove',
					onClick: function () {
						draft.parameters.splice(idx, 1);
						redrawParams();
					},
				});
				row.appendChild(nameIn);
				row.appendChild(typeIn);
				row.appendChild(el('label', { className: 'wtt-check' }, [req, el('span', { text: ' req' })]));
				row.appendChild(rm);
				paramList.appendChild(row);
			});
		}
		redrawParams();
		paramBlock.appendChild(paramList);
		paramBlock.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.addParameter || 'Add parameter',
				onClick: function () {
					draft.parameters.push({
						id: 'p_' + Date.now(),
						name: '',
						type: '',
						required: false,
						footerOp: 'none',
						refScope: null,
					});
					redrawParams();
				},
			})
		);
		pane.appendChild(paramBlock);

		// Relations read-only list
		var edges = meta.edges || [];
		if (edges.length) {
			var relBlock = el('div', { className: 'wtt-block' });
			relBlock.appendChild(el('h3', { className: 'wtt-subtitle', text: i18n.relations || 'Relations' }));
			var ul = el('ul', { className: 'wtt-edge-list' });
			edges.forEach(function (e) {
				var label = (e.label || '') + ' -> #' + (e.to || '?');
				if (e.value != null) {
					label += ' (value=' + e.value + ')';
				}
				ul.appendChild(el('li', { text: label }));
			});
			relBlock.appendChild(ul);
			pane.appendChild(relBlock);
		}

		var status = el('p', { className: 'wtt-muted', id: 'wtt-save-status' });
		pane.appendChild(status);

		pane.appendChild(
			el('div', { className: 'wtt-actions' }, [
				el('button', {
					type: 'button',
					className: 'button button-primary',
					text: i18n.save || 'Save',
					onClick: function () {
						var payload = {
							name: nameInput.value,
							description: descInput.value,
							template: templateCb.checked,
							hasType: typeSel.value ? parseInt(typeSel.value, 10) : 0,
							refScope: scopeSel.value ? parseInt(scopeSel.value, 10) : 0,
							parameters: draft.parameters
								.filter(function (p) {
									return p.name && p.type;
								})
								.map(function (p) {
									return {
										id: p.id,
										name: p.name,
										type: parseInt(p.type, 10),
										required: !!p.required,
										footerOp: p.footerOp || 'none',
										refScope: p.refScope || null,
									};
								}),
						};
						if (draft._reqCb) {
							payload.required = draft._reqCb.checked;
						}
						if (draft._footerSel) {
							payload.footerOp = draft._footerSel.value;
						}
						status.textContent = i18n.loading || '...';
						saveNode(payload, true)
							.then(function (node) {
								if (node) {
									status.textContent = i18n.saved || 'Saved.';
								}
							})
							.catch(function () {
								setError(i18n.error);
							});
					},
				}),
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.addChild,
					onClick: function () {
						createTerm(n.id);
					},
				}),
				el('button', {
					type: 'button',
					className: 'button button-link-delete',
					text: i18n.delete,
					onClick: deleteSelected,
				}),
			])
		);
		return pane;
	}

	function applyDemoTree(tree) {
		state.tree = tree || [];
		state.selectedId = null;
		state.selectedNode = null;
		state.error = '';
		state.expanded = {};
		(state.tree || []).forEach(function (n) {
			if (n && n.id && n.name === 'BOM Testprojekt') {
				state.expanded[n.id] = true;
			}
		});
		render();
	}

	function installDemo() {
		post('wtt_install_demo', {})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				applyDemoTree(json.data.tree);
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function resetDemo() {
		var msg = i18n.confirmReset || 'Reset test tree?';
		if (!window.confirm(msg)) {
			return;
		}
		post('wtt_reset_demo', {})
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				applyDemoTree(json.data.tree);
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function render() {
		var root = document.getElementById('wtt-app');
		var badge = document.getElementById('wtt-badge');
		var intro = document.getElementById('wtt-intro');
		if (!root) {
			return;
		}
		if (badge) {
			badge.textContent = i18n.scaffoldBadge || 'Scaffold 0.0.1';
		}
		if (intro) {
			intro.textContent = i18n.selectHint || '';
		}

		root.innerHTML = '';

		var taxSelect = el('select', {
			id: 'wtt-taxonomy',
			onChange: function (e) {
				state.taxonomy = e.target.value;
				state.selectedId = null;
				state.selectedNode = null;
				state.error = '';
				post('wtt_get_tree', {})
					.then(function (json) {
						if (!json || !json.success) {
							setError((json && json.data && json.data.message) || i18n.error);
							return;
						}
						refreshTree(json.data.tree);
					})
					.catch(function () {
						setError(i18n.error);
					});
			},
		});
		(cfg.taxonomies || []).forEach(function (tax) {
			var opt = el('option', { value: tax.slug, text: tax.label });
			if (tax.slug === state.taxonomy) {
				opt.selected = true;
			}
			taxSelect.appendChild(opt);
		});

		var toolbar = el('div', { className: 'wtt-toolbar' }, [
			el('label', { text: i18n.taxonomy + ' ', htmlFor: 'wtt-taxonomy' }),
			taxSelect,
			el('button', {
				type: 'button',
				className: 'button button-primary',
				text: i18n.addRoot,
				onClick: function () {
					createTerm(0);
				},
			}),
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.installDemo || 'Install test tree',
				onClick: installDemo,
			}),
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.resetDemo || 'Reset test tree',
				onClick: resetDemo,
			}),
		]);

		var treeList = el('ul', { className: 'wtt-tree' });
		if (!state.tree.length) {
			treeList.appendChild(el('li', { className: 'wtt-empty', text: i18n.empty }));
		} else {
			renderTreeNodes(state.tree, treeList);
		}

		var treePane = el('div', { className: 'wtt-tree-pane' }, [toolbar, treeList]);
		root.appendChild(treePane);
		root.appendChild(renderDetail());
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', render);
	} else {
		render();
	}
})();
