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
		var dl = el('dl');
		[
			[i18n.name, n.name],
			[i18n.slug, n.slug],
			[i18n.parent, n.parentName || i18n.none],
			[i18n.description, n.description || ''],
			[i18n.count, String(n.count)],
		].forEach(function (pair) {
			dl.appendChild(el('dt', { text: pair[0] }));
			dl.appendChild(el('dd', { text: pair[1] }));
		});

		pane.appendChild(el('div', { className: 'wtt-detail' }, [dl]));
		pane.appendChild(
			el('div', { className: 'wtt-actions' }, [
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
