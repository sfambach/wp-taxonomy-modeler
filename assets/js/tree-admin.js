(function () {
	'use strict';

	var cfg = window.wttTree || {};
	var i18n = cfg.i18n || {};
	var state = {
		taxonomy: cfg.taxonomy || 'category',
		tree: Array.isArray(cfg.tree) ? cfg.tree : [],
		selectedId: null,
		selectedNode: null,
		draft: null,
		savedDraft: null,
		settingsSaving: false,
		autosaving: false,
		expanded: {},
		error: '',
		/* Interactive Editable preview samples (not persisted to terms). */
		previewValues: {},
		previewFocus: null,
	};
	var autosaveTimer = null;
	var autosaveSeq = 0;

	function saveViaButtonEnabled() {
		return !!cfg.saveViaButton;
	}

	function deepClone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function uiStorageKey(taxonomy) {
		return 'wtt.treeUi.v1.' + String(taxonomy || 'category');
	}

	function collectTreeIds(nodes, out) {
		out = out || {};
		(nodes || []).forEach(function (node) {
			if (!node || node.id == null) {
				return;
			}
			out[String(node.id)] = true;
			if (node.children && node.children.length) {
				collectTreeIds(node.children, out);
			}
		});
		return out;
	}

	function persistTreeUi() {
		try {
			if (!window.localStorage) {
				return;
			}
			var expandedIds = [];
			Object.keys(state.expanded || {}).forEach(function (id) {
				if (state.expanded[id]) {
					expandedIds.push(String(id));
				}
			});
			window.localStorage.setItem(
				uiStorageKey(state.taxonomy),
				JSON.stringify({
					expanded: expandedIds,
					selectedId: state.selectedId ? String(state.selectedId) : null,
				})
			);
		} catch (err) {
			/* ignore quota / private mode */
		}
	}

	function restoreTreeUi() {
		try {
			if (!window.localStorage) {
				return;
			}
			var raw = window.localStorage.getItem(uiStorageKey(state.taxonomy));
			if (!raw) {
				return;
			}
			var data = JSON.parse(raw);
			if (!data || typeof data !== 'object') {
				return;
			}
			var known = collectTreeIds(state.tree, {});
			state.expanded = {};
			(Array.isArray(data.expanded) ? data.expanded : []).forEach(function (id) {
				var key = String(id);
				if (known[key]) {
					state.expanded[key] = true;
					state.expanded[parseInt(key, 10)] = true;
				}
			});
			if (data.selectedId != null && known[String(data.selectedId)]) {
				state.selectedId = parseInt(data.selectedId, 10) || data.selectedId;
			}
		} catch (err) {
			/* ignore bad JSON */
		}
	}

	function settingsFromNode(n) {
		return {
			name: n.name != null ? String(n.name) : '',
			description: n.description != null ? String(n.description) : '',
			shortDescription: n.shortDescription != null ? String(n.shortDescription) : '',
			typeId: n.typeId || 0,
			required: !!n.required,
			fixedEnabled: !!n.fixedEnabled,
			fixedLiteral: n.fixedLiteral != null ? String(n.fixedLiteral) : '',
			fixedNodeId: n.fixedNodeId || 0,
			hasFooter: !!n.hasFooter,
			setSeparator: n.setSeparator != null ? String(n.setSeparator) : '/',
			setJoinUnits: n.setJoinUnits !== false,
			setLabelChildren: n.setLabelChildren !== false,
			type: n.type || null,
			fixed: n.fixed || null,
			isTable: !!n.isTable,
			isSet: !!n.isSet,
			typeBranch: n.typeBranch ? deepClone(n.typeBranch) : null,
			isBasiseinheitUnit: !!n.isBasiseinheitUnit,
			prefixAllowlist: n.prefixAllowlist ? deepClone(n.prefixAllowlist) : null,
			prefixRootToSi: n.prefixRootToSi != null ? n.prefixRootToSi : null,
			quantitySchema: n.quantitySchema ? deepClone(n.quantitySchema) : null,
			/* Child extras on parent (e.g. Meter): Praefix allowlist + factors — not name/description. */
			prefixBranch: extractPrefixBranchFromNode(n),
		};
	}

	function extractPrefixBranchFromNode(n) {
		if (!n || !n.isBasiseinheitUnit || !Array.isArray(n.setMembers)) {
			return null;
		}
		for (var i = 0; i < n.setMembers.length; i++) {
			var m = n.setMembers[i];
			if (
				m &&
				memberNameKey(m) === 'praefix' &&
				m.typeBranch &&
				m.typeBranch.unitAllowlistEdit
			) {
				return deepClone(m.typeBranch);
			}
		}
		return null;
	}

	function applyLoadedNode(node) {
		state.selectedNode = node;
		state.draft = settingsFromNode(node);
		state.savedDraft = settingsFromNode(node);
		state.settingsSaving = false;
		state.error = '';
	}

	function viewNode() {
		var n = state.selectedNode;
		var d = state.draft;
		if (!n || !d) {
			return n;
		}
		return Object.assign({}, n, {
			name: d.name,
			description: d.description,
			shortDescription: d.shortDescription != null ? String(d.shortDescription) : '',
			typeId: d.typeId,
			required: d.required,
			fixedEnabled: d.fixedEnabled,
			fixedLiteral: d.fixedLiteral,
			fixedNodeId: d.fixedNodeId,
			hasFooter: d.hasFooter,
			setSeparator: d.setSeparator != null ? String(d.setSeparator) : '/',
			setJoinUnits: d.setJoinUnits !== false,
			setLabelChildren: d.setLabelChildren !== false,
			type: d.type,
			fixed: draftFixedDisplay(d),
			isTable: d.isTable,
			isSet: d.isSet,
			typeBranch: d.typeBranch,
			isBasiseinheitUnit: d.isBasiseinheitUnit,
			prefixAllowlist: d.prefixAllowlist,
			prefixRootToSi: d.prefixRootToSi,
			prefixBranch: d.prefixBranch,
			quantitySchema: d.quantitySchema,
		});
	}

	function isSimpleDataType(type) {
		var key = typeKeyFromMember({ type: type });
		return (
			key === 'int' ||
			key === 'double' ||
			key === 'text' ||
			key === 'textarea' ||
			key === 'char' ||
			key === 'bool' ||
			key === 'quantity' ||
			key === 'display_node_name'
		);
	}

	function supportsFixedLiteral(type) {
		var key = typeKeyFromMember({ type: type });
		return isSimpleDataType(type) && key !== 'display_node_name';
	}

	function draftFixedDisplay(draft) {
		if (!draft || !draft.fixedEnabled) {
			return null;
		}
		if (isSimpleDataType(draft.type)) {
			var lit = draft.fixedLiteral != null ? String(draft.fixedLiteral) : '';
			if (typeKeyFromMember({ type: draft.type }) === 'bool') {
				lit = lit === '1' || lit === 'true' ? '1' : lit === '' ? '' : '0';
			}
			if (lit === '') {
				return null;
			}
			return { id: 0, name: lit, path: lit };
		}
		return draft.fixed || null;
	}

	function isSettingsDirty() {
		if (!state.draft || !state.savedDraft) {
			return false;
		}
		return JSON.stringify(state.draft) !== JSON.stringify(state.savedDraft);
	}

	function resolveTypeFromOptions(typeId, typeOptions) {
		if (!typeId) {
			return null;
		}
		var found = (typeOptions || []).find(function (opt) {
			return opt && String(opt.id) === String(typeId);
		});
		if (!found) {
			return state.draft && state.draft.type && String(state.draft.type.id) === String(typeId)
				? state.draft.type
				: null;
		}
		return {
			id: found.id,
			name: found.name || '',
			path: found.path || found.name || '',
		};
	}

	function resolveFixedFromOptions(fixedNodeId, fixedOptions) {
		if (!fixedNodeId) {
			return null;
		}
		var found = (fixedOptions || []).find(function (opt) {
			return opt && String(opt.id) === String(fixedNodeId);
		});
		if (!found) {
			return state.draft && state.draft.fixed && String(state.draft.fixed.id) === String(fixedNodeId)
				? state.draft.fixed
				: null;
		}
		return {
			id: found.id,
			name: found.name || '',
			path: found.path || found.name || '',
		};
	}

	function typeNameIs(type, name) {
		return !!(type && String(type.name || '').toLowerCase() === String(name).toLowerCase());
	}

	function disabledIdsFromBranch(branch) {
		var ids = [];
		if (!branch || !Array.isArray(branch.children)) {
			return ids;
		}
		// Read-only filter from sibling Einheit — do not persist local disables.
		if (branch.unitFilter && !branch.unitAllowlistEdit) {
			return ids;
		}
		branch.children.forEach(function (child) {
			if (child && child.id != null && child.enabled === false) {
				ids.push(parseInt(child.id, 10) || 0);
			}
		});
		return ids.filter(function (id) {
			return id > 0;
		});
	}

	function disabledBranchIdsFromDraft(draft) {
		if (!draft) {
			return [];
		}
		/* Prefer prefix extras on unit parent; else type-branch on current node. */
		if (draft.prefixBranch && draft.prefixBranch.unitAllowlistEdit) {
			return disabledIdsFromBranch(draft.prefixBranch);
		}
		return disabledIdsFromBranch(draft.typeBranch);
	}

	/**
	 * Dropdown label: "name — shortDescription" when a short exists.
	 * Falls back to path (type picker) or name alone. No decorative leading dashes.
	 */
	function formatSelectLabel(opt) {
		if (!opt) {
			return '';
		}
		var name = opt.name != null ? String(opt.name) : '';
		var path = opt.path != null ? String(opt.path) : '';
		var short =
			opt.shortDescription != null ? String(opt.shortDescription).trim() : '';
		if (short) {
			var base = name || path || String(opt.id != null ? opt.id : '');
			return base ? base + ' — ' + short : short;
		}
		return path || name || String(opt.id != null ? opt.id : '') || '';
	}

	/**
	 * One select builder for option lists (branch children, type/fixed pickers, …).
	 * No blank placeholder option — first real option is selected when nothing matches.
	 *
	 * @param {Array<{id?:*,name?:string,path?:string,shortDescription?:string}>} options
	 * @param {{
	 *   className?: string,
	 *   disabled?: boolean,
	 *   selectedValue?: *,
	 *   getValue?: function,
	 *   onChange?: function,
	 *   emptyLabel?: string
	 * }} opts
	 */
	function renderOptionsSelect(options, opts) {
		opts = opts || {};
		var list = (options || []).filter(function (opt) {
			return !!opt;
		});
		var control = el('select', {
			className: opts.className || 'wtt-type-select',
		});
		if (opts.disabled) {
			control.disabled = true;
		}
		if (!list.length) {
			if (opts.emptyLabel) {
				control.appendChild(
					el('option', {
						value: opts.emptyValue != null ? String(opts.emptyValue) : '',
						text: String(opts.emptyLabel),
					})
				);
			}
			if (typeof opts.onChange === 'function') {
				control.addEventListener('change', opts.onChange);
			}
			return control;
		}
		var selected = false;
		list.forEach(function (opt) {
			var value =
				typeof opts.getValue === 'function'
					? String(opts.getValue(opt))
					: opt.id != null
						? String(opt.id)
						: String(opt.name || '');
			if (value === '') {
				return;
			}
			var option = el('option', {
				value: value,
				text: formatSelectLabel(opt) || value,
			});
			if (
				!selected &&
				opts.selectedValue != null &&
				String(opts.selectedValue) === value
			) {
				option.selected = true;
				selected = true;
			}
			control.appendChild(option);
		});
		if (!selected && control.options.length) {
			control.options[0].selected = true;
		}
		if (typeof opts.onChange === 'function') {
			control.addEventListener('change', opts.onChange);
		}
		return control;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'className') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'htmlFor') {
				node.htmlFor = attrs[key];
			} else if (key.indexOf('on') === 0 && typeof attrs[key] === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (attrs[key] === false || attrs[key] == null) {
				return;
			} else if (attrs[key] === true) {
				node.setAttribute(key, key);
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		if (children != null) {
			var list = Array.isArray(children) ? children : [children];
			list.forEach(function (child) {
				if (child) {
					node.appendChild(child);
				}
			});
		}
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

	function expandAncestorsOf(termId) {
		var target = parseInt(termId, 10) || 0;
		if (target <= 0) {
			return;
		}
		function walk(nodes, ancestors) {
			var list = nodes || [];
			for (var i = 0; i < list.length; i++) {
				var node = list[i];
				if (!node || node.id == null) {
					continue;
				}
				if (parseInt(node.id, 10) === target) {
					ancestors.forEach(function (id) {
						state.expanded[id] = true;
					});
					return true;
				}
				if (node.children && node.children.length) {
					if (walk(node.children, ancestors.concat([node.id]))) {
						return true;
					}
				}
			}
			return false;
		}
		walk(state.tree, []);
	}

	function scrollSelectedIntoTreeView() {
		window.requestAnimationFrame(function () {
			var row = document.querySelector('.wtt-tree__row.is-active');
			if (row && typeof row.scrollIntoView === 'function') {
				row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
		});
	}

	function selectNode(id) {
		var termId = parseInt(id, 10) || 0;
		if (termId <= 0) {
			return;
		}
		if (
			!saveViaButtonEnabled() &&
			state.selectedId &&
			state.selectedId !== termId &&
			state.draft &&
			isSettingsDirty()
		) {
			if (autosaveTimer) {
				window.clearTimeout(autosaveTimer);
				autosaveTimer = null;
			}
			saveNodeSettings({ autosave: true });
		} else if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
			autosaveTimer = null;
		}
		state.previewValues = {};
		state.previewFocus = null;
		expandAncestorsOf(termId);
		state.selectedId = termId;
		state.selectedNode = null;
		state.draft = null;
		state.savedDraft = null;
		state.settingsSaving = false;
		state.error = '';
		persistTreeUi();
		render();
		scrollSelectedIntoTreeView();
		post('wtt_get_node', { term_id: termId })
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.node) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				applyLoadedNode(json.data.node);
				render();
				scrollSelectedIntoTreeView();
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
					applyLoadedNode(json.data.node);
					state.selectedId = json.data.node.id;
					if (parent) {
						state.expanded[parent] = true;
					}
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function copySelectedAsSibling() {
		if (!state.selectedId) {
			return;
		}
		post('wtt_copy_term', { term_id: state.selectedId })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				if (json.data.node && json.data.node.id) {
					applyLoadedNode(json.data.node);
					state.selectedId = json.data.node.id;
					if (json.data.node.parent) {
						state.expanded[json.data.node.parent] = true;
					}
				}
				state.error = '';
				persistTreeUi();
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function deleteSelected() {
		if (!state.selectedId) {
			return;
		}
		deleteNodeById(state.selectedId, !!(state.selectedNode && state.selectedNode.hasChildren));
	}

	function deleteNodeById(termId, hasChildren) {
		if (!termId) {
			return;
		}
		if (!hasChildren) {
			if (!window.confirm(i18n.confirmLeaf)) {
				return;
			}
			runDelete('leaf', termId);
			return;
		}
		showDeleteDialog(termId);
	}

	function runDelete(mode, termId) {
		termId = termId || state.selectedId;
		if (!termId) {
			return;
		}
		post('wtt_delete_term', { term_id: termId, mode: mode })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (state.selectedId === termId) {
					state.selectedId = null;
					state.selectedNode = null;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				render();
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function showDeleteDialog(termId) {
		termId = termId || state.selectedId;
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
							runDelete('promote', termId);
						},
					}),
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.deleteChildren,
						onClick: function () {
							document.body.removeChild(backdrop);
							runDelete('cascade', termId);
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

	function collectSubtreeIds(node, out) {
		out = out || {};
		if (!node || node.id == null) {
			return out;
		}
		out[String(node.id)] = true;
		(node.children || []).forEach(function (child) {
			collectSubtreeIds(child, out);
		});
		return out;
	}

	function findNodeInTree(nodes, id) {
		var i;
		var found;
		id = parseInt(id, 10) || 0;
		for (i = 0; i < (nodes || []).length; i++) {
			if ((parseInt(nodes[i].id, 10) || 0) === id) {
				return nodes[i];
			}
			found = findNodeInTree(nodes[i].children || [], id);
			if (found) {
				return found;
			}
		}
		return null;
	}

	function flattenParentOptions(nodes, depth, blocked, out) {
		out = out || [];
		depth = depth || 0;
		(nodes || []).forEach(function (node) {
			if (!node || node.id == null) {
				return;
			}
			var id = String(node.id);
			if (!blocked[id]) {
				var pad = depth > 0 ? new Array(depth + 1).join('  ') : '';
				out.push({
					id: node.id,
					label: pad + formatSelectLabel(node),
				});
				flattenParentOptions(node.children || [], depth + 1, blocked, out);
			}
		});
		return out;
	}

	function showReparentDialog(termId) {
		termId = termId || state.selectedId;
		if (!termId) {
			return;
		}
		var node = findNodeInTree(state.tree, termId);
		var blocked = collectSubtreeIds(node, {});
		var options = flattenParentOptions(state.tree, 0, blocked, []);
		var currentParent = node ? parseInt(node.parent, 10) || 0 : 0;

		var select = el('select', { className: 'wtt-reparent-select' });
		select.appendChild(
			el('option', {
				value: '0',
				text: i18n.reparentRoot || '— Root (no parent) —',
			})
		);
		options.forEach(function (opt) {
			var option = el('option', {
				value: String(opt.id),
				text: opt.label,
			});
			if ((parseInt(opt.id, 10) || 0) === currentParent) {
				option.selected = true;
			}
			select.appendChild(option);
		});
		if (currentParent === 0) {
			select.value = '0';
		}

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog', role: 'dialog' }, [
				el('h2', { text: i18n.reparentTitle || 'Change parent' }),
				el('p', { text: i18n.reparentText || 'Choose a new parent for this term.' }),
				select,
				el('div', { className: 'wtt-dialog__actions' }, [
					el('button', {
						type: 'button',
						className: 'button button-primary',
						text: i18n.reparentApply || 'Move',
						onClick: function () {
							var parentId = parseInt(select.value, 10) || 0;
							document.body.removeChild(backdrop);
							if (parentId === currentParent) {
								return;
							}
							reparentTerm(termId, parentId);
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

	function reparentTerm(termId, parentId) {
		post('wtt_reparent_term', { term_id: termId, parent: parentId || 0 })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				if (parentId) {
					state.expanded[parentId] = true;
				}
				selectNode(termId);
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function moveNode(termId, direction) {
		post('wtt_move_term', { term_id: termId, direction: direction })
			.then(function (json) {
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				state.tree = json.data.tree || [];
				state.error = '';
				if (state.selectedId) {
					selectNode(state.selectedId);
				} else {
					render();
				}
			})
			.catch(function () {
				setError(i18n.error);
			});
	}

	function treeActionButton(icon, title, onClick, disabled) {
		var btn = el('button', {
			type: 'button',
			className: 'wtt-tree__action' + (disabled ? ' is-disabled' : ''),
			title: title || '',
			'aria-label': title || '',
			onClick: function (e) {
				e.stopPropagation();
				if (disabled) {
					return;
				}
				onClick();
			},
			html: '<span class="dashicons dashicons-' + icon + '"></span>',
		});
		if (disabled) {
			btn.disabled = true;
		}
		return btn;
	}

	function renderTreeNodes(nodes, list) {
		nodes.forEach(function (node, index) {
			var hasChildren = node.hasChildren || (node.children && node.children.length);
			var isExpanded = !!state.expanded[node.id];
			var canUp = node.canMoveUp != null ? !!node.canMoveUp : index > 0;
			var canDown =
				node.canMoveDown != null ? !!node.canMoveDown : index < nodes.length - 1;
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
							persistTreeUi();
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

			var label = node.name;
			if (cfg.showTypeInTree && node.typeLabel) {
				label += ' [' + node.typeLabel + ']';
			}

			var nameBtn = el('button', {
				type: 'button',
				className: 'wtt-tree__name',
				text: label,
				onClick: function () {
					selectNode(node.id);
				},
			});
			if (node.shortDescription) {
				nameBtn.title = String(node.shortDescription);
			} else if (node.description) {
				nameBtn.title = String(node.description);
			}
			row.appendChild(nameBtn);

			var actions = el('div', { className: 'wtt-tree__actions' });
			actions.appendChild(
				treeActionButton('plus', i18n.addChild || 'Add child', function () {
					state.expanded[node.id] = true;
					createTerm(node.id);
				})
			);
			actions.appendChild(
				treeActionButton(
					'arrow-up-alt2',
					i18n.moveUp || 'Move up',
					function () {
						moveNode(node.id, 'up');
					},
					!canUp
				)
			);
			actions.appendChild(
				treeActionButton(
					'arrow-down-alt2',
					i18n.moveDown || 'Move down',
					function () {
						moveNode(node.id, 'down');
					},
					!canDown
				)
			);
			actions.appendChild(
				treeActionButton('trash', i18n.delete || 'Delete', function () {
					deleteNodeById(node.id, !!hasChildren);
				})
			);
			row.appendChild(actions);

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

	function mergeNodeTypeIntoTree(nodes, updated) {
		if (!updated || !updated.id) {
			return false;
		}
		var found = false;
		(nodes || []).forEach(function (node) {
			if (node.id === updated.id) {
				node.type = updated.type || null;
				node.typeLabel = updated.type && updated.type.name ? updated.type.name : '';
				found = true;
				return;
			}
			if (node.children && node.children.length && mergeNodeTypeIntoTree(node.children, updated)) {
				found = true;
			}
		});
		return found;
	}

	function setDraftType(typeId) {
		if (!state.draft || !state.selectedNode) {
			return;
		}
		typeId = typeId || 0;
		state.draft.typeId = typeId;
		state.draft.type = resolveTypeFromOptions(typeId, state.selectedNode.typeOptions);
		state.draft.isTable = typeNameIs(state.draft.type, 'table');
		state.draft.isSet = typeNameIs(state.draft.type, 'set');
		state.draft.typeBranch = null;
		state.draft.quantitySchema = null;
		state.draft.fixedEnabled = false;
		state.draft.fixedLiteral = '';
		state.draft.fixedNodeId = 0;
		state.draft.fixed = null;
		if (typeKeyFromMember({ type: state.draft.type }) === 'display_node_name') {
			state.draft.required = false;
		}
		if (!typeId) {
			afterDraftMutation();
			return;
		}
		afterDraftMutation();
		post('wtt_get_type_branch', { type_id: typeId })
			.then(function (json) {
				if (!state.draft || state.draft.typeId !== typeId) {
					return;
				}
				if (!json || !json.success) {
					return;
				}
				if (json.data && json.data.isSet) {
					state.draft.isSet = true;
				}
				if (json.data && json.data.quantitySchema) {
					state.draft.quantitySchema = json.data.quantitySchema;
					state.draft.typeBranch = null;
					if (state.selectedNode) {
						state.selectedNode.quantitySchema = json.data.quantitySchema;
						state.selectedNode.typeBranch = null;
					}
				} else {
					state.draft.typeBranch = json.data.typeBranch || null;
					state.draft.quantitySchema = null;
					if (state.selectedNode) {
						state.selectedNode.quantitySchema = null;
					}
				}
				afterDraftMutation();
			})
			.catch(function () {
				/* draft type still applied; branch optional */
			});
	}

	function setDraftName(name, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.name = name != null ? String(name) : '';
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftDescription(description, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.description = description != null ? String(description) : '';
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftShortDescription(shortDescription, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.shortDescription = shortDescription != null ? String(shortDescription) : '';
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftRequired(required) {
		if (!state.draft) {
			return;
		}
		state.draft.required = !!required;
		afterDraftMutation();
	}

	function setDraftHasFooter(hasFooter) {
		if (!state.draft) {
			return;
		}
		state.draft.hasFooter = !!hasFooter;
		afterDraftMutation();
	}

	function setDraftSetSeparator(separator) {
		if (!state.draft) {
			return;
		}
		state.draft.setSeparator = separator != null ? String(separator) : '/';
		afterDraftMutation({ silent: true });
	}

	function setDraftSetJoinUnits(joinUnits) {
		if (!state.draft) {
			return;
		}
		state.draft.setJoinUnits = !!joinUnits;
		afterDraftMutation();
	}

	function setDraftSetLabelChildren(includeChildren) {
		if (!state.draft) {
			return;
		}
		state.draft.setLabelChildren = !!includeChildren;
		afterDraftMutation();
	}

	function setDraftFixedEnabled(enabled) {
		if (!state.draft) {
			return;
		}
		state.draft.fixedEnabled = !!enabled;
		if (!state.draft.fixedEnabled) {
			state.draft.fixedLiteral = '';
			state.draft.fixedNodeId = 0;
			state.draft.fixed = null;
		} else if (supportsFixedLiteral(state.draft.type) && typeKeyFromMember({ type: state.draft.type }) === 'bool') {
			if (state.draft.fixedLiteral !== '1' && state.draft.fixedLiteral !== '0') {
				state.draft.fixedLiteral = '0';
			}
			state.draft.fixed = draftFixedDisplay(state.draft);
		}
		afterDraftMutation();
	}

	function refreshSettingsActionState() {
		var wrap = document.querySelector('.wtt-detail-toolbar');
		if (!wrap) {
			return;
		}
		var dirty = isSettingsDirty();
		var viaButton = saveViaButtonEnabled();
		wrap.classList.toggle('is-dirty', viaButton && dirty);
		var locked = !!state.settingsSaving;
		Array.prototype.forEach.call(wrap.querySelectorAll('[data-wtt-settings-action]'), function (btn) {
			btn.disabled = locked || !dirty;
		});
		var status = wrap.querySelector('.wtt-settings-status');
		if (!viaButton) {
			if (hintRemove(wrap, '.wtt-settings-unsaved')) {
				/* removed */
			}
			var statusText = '';
			if (state.autosaving) {
				statusText = i18n.settingsSaving || 'Saving…';
			} else if (!dirty && state.selectedId) {
				statusText = i18n.settingsSaved || 'Saved';
			}
			if (statusText) {
				if (!status) {
					var group = wrap.querySelector('.wtt-detail-toolbar__group--settings');
					if (group) {
						status = el('span', { className: 'wtt-settings-status', text: statusText });
						group.appendChild(status);
					}
				} else {
					status.textContent = statusText;
				}
			} else if (status && status.parentNode) {
				status.parentNode.removeChild(status);
			}
			return;
		}
		if (status && status.parentNode) {
			status.parentNode.removeChild(status);
		}
		var hint = wrap.querySelector('.wtt-settings-unsaved');
		if (dirty && i18n.settingsUnsavedHint) {
			if (!hint) {
				var settingsGroup = wrap.querySelector('.wtt-detail-toolbar__group--settings');
				if (settingsGroup) {
					settingsGroup.appendChild(
						el('span', {
							className: 'wtt-settings-unsaved',
							text: i18n.settingsUnsavedHint,
						})
					);
				}
			}
		} else if (hint) {
			hint.parentNode.removeChild(hint);
		}
	}

	function hintRemove(wrap, selector) {
		var node = wrap.querySelector(selector);
		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
			return true;
		}
		return false;
	}

	function scheduleAutosave() {
		if (saveViaButtonEnabled()) {
			return;
		}
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
		}
		autosaveTimer = window.setTimeout(function () {
			autosaveTimer = null;
			runAutosave();
		}, 450);
		refreshSettingsActionState();
	}

	function runAutosave() {
		if (saveViaButtonEnabled() || !state.selectedId || !state.draft) {
			return;
		}
		if (!isSettingsDirty()) {
			refreshSettingsActionState();
			return;
		}
		if (state.autosaving || state.settingsSaving) {
			scheduleAutosave();
			return;
		}
		saveNodeSettings({ autosave: true });
	}

	function afterDraftMutation(opts) {
		opts = opts || {};
		if (saveViaButtonEnabled()) {
			if (opts.silent) {
				refreshSettingsActionState();
			} else {
				render();
			}
			return;
		}
		if (opts.silent) {
			refreshSettingsActionState();
		} else {
			render();
		}
		scheduleAutosave();
	}

	function renderDetailToolbar(n, dirty, controlsLocked) {
		var viaButton = saveViaButtonEnabled();
		var bar = el('div', {
			className:
				'wtt-detail-toolbar' + (viaButton && dirty ? ' is-dirty' : ''),
		});

		var settings = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--settings',
		});
		if (viaButton) {
			var saveBtn = el('button', {
				type: 'button',
				className: 'button button-primary',
				text: i18n.saveSettings || 'Save settings',
				onClick: function () {
					saveNodeSettings();
				},
			});
			saveBtn.setAttribute('data-wtt-settings-action', 'save');
			var undoBtn = el('button', {
				type: 'button',
				className: 'button',
				text: i18n.undoSettings || 'Undo',
				onClick: undoNodeSettings,
			});
			undoBtn.setAttribute('data-wtt-settings-action', 'undo');
			if (controlsLocked || !dirty) {
				saveBtn.disabled = true;
				undoBtn.disabled = true;
			}
			settings.appendChild(saveBtn);
			settings.appendChild(undoBtn);
			if (dirty && i18n.settingsUnsavedHint) {
				settings.appendChild(
					el('span', {
						className: 'wtt-settings-unsaved',
						text: i18n.settingsUnsavedHint,
					})
				);
			}
		} else {
			var statusText = '';
			if (state.autosaving) {
				statusText = i18n.settingsSaving || 'Saving…';
			} else if (state.selectedId && !dirty) {
				statusText = i18n.settingsSaved || 'Saved';
			}
			if (statusText) {
				settings.appendChild(
					el('span', {
						className: 'wtt-settings-status',
						text: statusText,
					})
				);
			}
		}
		bar.appendChild(settings);

		var structure = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--structure',
		});
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.addChild,
				onClick: function () {
					createTerm(n.id);
				},
			})
		);
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.copy || 'Copy',
				onClick: copySelectedAsSibling,
			})
		);
		structure.appendChild(
			el('button', {
				type: 'button',
				className: 'button',
				text: i18n.reparent || 'Reparent',
				onClick: function () {
					showReparentDialog(n.id);
				},
			})
		);
		bar.appendChild(structure);

		var danger = el('div', {
			className: 'wtt-detail-toolbar__group wtt-detail-toolbar__group--danger',
		});
		danger.appendChild(
			el('button', {
				type: 'button',
				className: 'button button-link-delete',
				text: i18n.delete,
				onClick: deleteSelected,
			})
		);
		bar.appendChild(danger);

		return bar;
	}

	function setDraftFixedLiteral(value, opts) {
		opts = opts || {};
		if (!state.draft) {
			return;
		}
		state.draft.fixedEnabled = true;
		state.draft.fixedLiteral = value != null ? String(value) : '';
		state.draft.fixedNodeId = 0;
		state.draft.fixed = draftFixedDisplay(state.draft);
		afterDraftMutation({ silent: !!opts.silent });
	}

	function setDraftFixed(fixedNodeId) {
		if (!state.draft || !state.selectedNode) {
			return;
		}
		fixedNodeId = fixedNodeId || 0;
		state.draft.fixedEnabled = fixedNodeId > 0;
		state.draft.fixedNodeId = fixedNodeId;
		state.draft.fixedLiteral = '';
		state.draft.fixed = resolveFixedFromOptions(fixedNodeId, state.selectedNode.fixedOptions);
		afterDraftMutation();
	}

	function draftPrefixBranch() {
		if (!state.draft) {
			return null;
		}
		if (state.draft.prefixBranch && state.draft.prefixBranch.unitAllowlistEdit) {
			return state.draft.prefixBranch;
		}
		if (state.draft.typeBranch && state.draft.typeBranch.unitAllowlistEdit) {
			return state.draft.typeBranch;
		}
		return null;
	}

	function formatFactor(value) {
		var n = Number(value);
		if (!isFinite(n)) {
			return '';
		}
		if (n === 0) {
			return '0';
		}
		var abs = Math.abs(n);
		if (abs >= 1e6 || (abs > 0 && abs < 1e-6)) {
			return n.toExponential(0).replace(/e\+?/, 'e');
		}
		var s = n.toPrecision(12);
		if (s.indexOf('e') !== -1 || s.indexOf('E') !== -1) {
			return s;
		}
		if (s.indexOf('.') !== -1) {
			s = s.replace(/\.?0+$/, '');
		}
		return s;
	}

	function setDraftBranchChild(childId, enabled) {
		var branch = draftPrefixBranch();
		if (!branch || !Array.isArray(branch.children)) {
			if (!state.draft || !state.draft.typeBranch || !Array.isArray(state.draft.typeBranch.children)) {
				return;
			}
			branch = state.draft.typeBranch;
		}
		branch.children.forEach(function (child) {
			if (child && String(child.id) === String(childId)) {
				child.enabled = !!enabled;
			}
		});
		afterDraftMutation();
	}

	function setDraftBranchMultiplikator(childId, value) {
		var branch = draftPrefixBranch();
		if (!branch || !Array.isArray(branch.children)) {
			return;
		}
		var parsed = parseFloat(String(value).replace(',', '.'));
		branch.children.forEach(function (child) {
			if (child && String(child.id) === String(childId)) {
				child.multiplikator = isFinite(parsed) && parsed > 0 ? parsed : null;
			}
		});
		afterDraftMutation({ silent: true });
	}

	function setDraftUnitPrefixRootToSi(value) {
		var branch = draftPrefixBranch();
		if (!branch) {
			return;
		}
		var parsed = parseFloat(String(value).replace(',', '.'));
		branch.unitPrefixRootToSi = isFinite(parsed) && parsed > 0 ? parsed : 1;
		afterDraftMutation({ silent: true });
	}

	function prefixMultiplikatorsFromDraft(draft) {
		var map = {};
		if (!draft) {
			return map;
		}
		var branch =
			draft.prefixBranch && draft.prefixBranch.unitAllowlistEdit
				? draft.prefixBranch
				: draft.typeBranch && draft.typeBranch.unitAllowlistEdit
					? draft.typeBranch
					: null;
		if (!branch || !Array.isArray(branch.children)) {
			return map;
		}
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var m = child.multiplikator;
			if (m != null && isFinite(Number(m)) && Number(m) > 0) {
				map[String(child.id)] = Number(m);
			}
		});
		return map;
	}

	function undoNodeSettings() {
		if (!saveViaButtonEnabled() || !state.savedDraft) {
			return;
		}
		state.draft = deepClone(state.savedDraft);
		state.error = '';
		render();
	}

	function saveNodeSettings(opts) {
		opts = opts || {};
		var autosave = !!opts.autosave;
		if (!state.selectedId || !state.draft) {
			return;
		}
		if (autosave && saveViaButtonEnabled()) {
			return;
		}
		if (!autosave && !saveViaButtonEnabled()) {
			/* Manual save only when button mode is on; otherwise ignore. */
			return;
		}
		if (!isSettingsDirty() && autosave) {
			refreshSettingsActionState();
			return;
		}

		var payloadDraft = deepClone(state.draft);
		var seq = ++autosaveSeq;
		var termId = state.selectedId;

		if (autosave) {
			state.autosaving = true;
			refreshSettingsActionState();
		} else {
			state.settingsSaving = true;
			state.error = '';
			render();
		}

		var savePayload = {
			term_id: termId,
			name: payloadDraft.name || '',
			description: payloadDraft.description || '',
			short_description: payloadDraft.shortDescription || '',
			type_id: payloadDraft.typeId || 0,
			required: payloadDraft.required ? '1' : '0',
			has_footer: payloadDraft.hasFooter ? '1' : '0',
			set_separator: payloadDraft.setSeparator != null ? String(payloadDraft.setSeparator) : '/',
			set_join_units: payloadDraft.setJoinUnits !== false ? '1' : '0',
			set_label_children: payloadDraft.setLabelChildren !== false ? '1' : '0',
			fixed_enabled: payloadDraft.fixedEnabled ? '1' : '0',
			fixed_literal: payloadDraft.fixedLiteral || '',
			fixed_node_id: payloadDraft.fixedNodeId || 0,
			disabled_branch_ids: JSON.stringify(disabledBranchIdsFromDraft(payloadDraft)),
			prefix_multiplikators: JSON.stringify(prefixMultiplikatorsFromDraft(payloadDraft)),
		};
		var prefixBranch =
			payloadDraft.prefixBranch && payloadDraft.prefixBranch.unitAllowlistEdit
				? payloadDraft.prefixBranch
				: payloadDraft.typeBranch && payloadDraft.typeBranch.unitAllowlistEdit
					? payloadDraft.typeBranch
					: null;
		if (prefixBranch && prefixBranch.unitPrefixRootToSi != null) {
			savePayload.prefix_root_to_si = String(prefixBranch.unitPrefixRootToSi);
		}

		post('wtt_save_node_settings', savePayload)
			.then(function (json) {
				if (autosave) {
					state.autosaving = false;
				} else {
					state.settingsSaving = false;
				}
				if (!json || !json.success) {
					setError((json && json.data && json.data.message) || i18n.error);
					return;
				}
				if (json.data.tree) {
					state.tree = json.data.tree;
				}
				if (state.selectedId !== termId) {
					return;
				}
				if (
					autosave &&
					seq === autosaveSeq &&
					state.draft &&
					JSON.stringify(state.draft) !== JSON.stringify(payloadDraft)
				) {
					/* User typed more while request was in flight — keep draft, mark baseline saved. */
					state.savedDraft = payloadDraft;
					if (json.data.node) {
						state.selectedNode = json.data.node;
					}
					mergeNodeTypeIntoTree(state.tree, json.data.node || {});
					scheduleAutosave();
					return;
				}
				applyLoadedNode(json.data.node);
				mergeNodeTypeIntoTree(state.tree, json.data.node);
				if (autosave) {
					refreshSettingsActionState();
				} else {
					render();
				}
			})
			.catch(function () {
				if (autosave) {
					state.autosaving = false;
				} else {
					state.settingsSaving = false;
				}
				setError(i18n.error);
			});
	}

	/**
	 * Praefix allowlist + conversion factors (editable).
	 * Same view on the Praefix child and under the unit parent (child extras).
	 */
	function renderPrefixAllowlistEditor(branch, container) {
		if (!branch || !Array.isArray(branch.children)) {
			return;
		}
		var rootToSi =
			branch.unitPrefixRootToSi != null && isFinite(Number(branch.unitPrefixRootToSi))
				? Number(branch.unitPrefixRootToSi)
				: 1;
		var block = el('div', { className: 'wtt-type-branch wtt-type-branch--embedded' });
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.praefixChildSettingsHint ||
					'Enable prefixes and enter each factor vs the prefix root. to_si = Typ × factor × unit root factor.',
			})
		);
		var rootRow = el('div', { className: 'wtt-type-branch__root-factor' });
		rootRow.appendChild(
			el('label', {
				className: 'wtt-type-branch__factor-label',
				text: i18n.prefixRootToSi || 'Unit: prefix root → SI base',
			})
		);
		var rootInput = el('input', {
			type: 'text',
			className: 'wtt-type-branch__factor-input',
			value: formatFactor(rootToSi) || '1',
			title: i18n.prefixRootToSiHint || 'Usually 1; Kilogramm uses 0.001 (g → kg).',
		});
		if (state.settingsSaving) {
			rootInput.disabled = true;
		}
		rootInput.addEventListener('input', function (e) {
			setDraftUnitPrefixRootToSi(e.target.value);
		});
		rootRow.appendChild(rootInput);
		block.appendChild(rootRow);

		var list = el('ul', {
			className: 'wtt-type-branch__list wtt-type-branch__list--factors',
		});
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var item = el('li', {
				className:
					'wtt-type-branch__item' + (child.enabled ? '' : ' is-disabled'),
			});
			var label = el('label', { className: 'wtt-checkbox-label' });
			var check = el('input', { type: 'checkbox', className: 'wtt-branch-check' });
			if (child.enabled) {
				check.checked = true;
			}
			if (state.settingsSaving) {
				check.disabled = true;
			}
			check.addEventListener('change', function (e) {
				setDraftBranchChild(parseInt(child.id, 10) || 0, !!e.target.checked);
			});
			label.appendChild(check);
			label.appendChild(document.createTextNode(' ' + (child.name || String(child.id))));
			item.appendChild(label);

			var factorWrap = el('span', { className: 'wtt-type-branch__factor' });
			factorWrap.appendChild(
				el('span', { className: 'wtt-type-branch__factor-mark', text: '×' })
			);
			var factorVal =
				child.multiplikator != null && child.multiplikator !== ''
					? Number(child.multiplikator)
					: null;
			var factorInput = el('input', {
				type: 'text',
				className: 'wtt-type-branch__factor-input',
				value: factorVal != null && isFinite(factorVal) ? formatFactor(factorVal) : '',
				placeholder: i18n.multiplikatorPlaceholder || 'e.g. 0.001',
				title: i18n.multiplikatorHint || 'Factor vs prefix root (SI powers).',
			});
			if (state.settingsSaving) {
				factorInput.disabled = true;
			}
			factorInput.addEventListener('input', function (e) {
				setDraftBranchMultiplikator(parseInt(child.id, 10) || 0, e.target.value);
			});
			factorWrap.appendChild(factorInput);
			if (child.enabled && factorVal != null && isFinite(factorVal) && factorVal > 0) {
				factorWrap.appendChild(
					el('span', {
						className: 'wtt-type-branch__to-si',
						text: '→ SI × ' + formatFactor(factorVal * rootToSi),
					})
				);
			}
			item.appendChild(factorWrap);
			list.appendChild(item);
		});
		block.appendChild(list);
		container.appendChild(block);
	}

	/**
	 * On set parents: show each child’s extras (not name/description) under parent settings.
	 */
	function renderChildExtrasOnParent(n, pane) {
		if (!n.isSet || !Array.isArray(n.setMembers) || !n.setMembers.length) {
			return;
		}

		var block = el('div', { className: 'wtt-child-extras' });
		block.appendChild(
			el('h3', {
				className: 'wtt-child-extras__title',
				text: i18n.childExtras || 'Child extras',
			})
		);
		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.childExtrasHint ||
					'Extras for set members (type, required, fixed, prefix conversion). Name and description stay on the child node.',
			})
		);

		n.setMembers.forEach(function (member) {
			if (!member) {
				return;
			}
			var card = el('div', { className: 'wtt-child-extras__member' });
			var head = el('div', { className: 'wtt-child-extras__head' });
			head.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-child-extras__link',
					text: member.name || String(member.id || ''),
					onClick: function () {
						if (member.id) {
							selectNode(member.id);
						}
					},
				})
			);
			card.appendChild(head);

			var meta = el('p', { className: 'wtt-child-extras__meta' });
			var bits = [];
			bits.push(
				(i18n.setMemberType || 'Type') +
					': ' +
					((member.type && (member.type.path || member.type.name)) ||
						i18n.setMemberUntyped ||
						'—')
			);
			if (member.required) {
				bits.push(i18n.required || 'Required');
			}
			if (member.fixed && member.fixed.name) {
				bits.push((i18n.fixedValue || 'Fixed') + ': ' + member.fixed.name);
			} else if (member.fixedLiteral) {
				bits.push((i18n.fixedValue || 'Fixed') + ': ' + member.fixedLiteral);
			}
			meta.textContent = bits.join(' · ');
			card.appendChild(meta);

			if (
				memberNameKey(member) === 'praefix' &&
				n.prefixBranch &&
				n.prefixBranch.unitAllowlistEdit
			) {
				renderPrefixAllowlistEditor(n.prefixBranch, card);
			}

			block.appendChild(card);
		});

		pane.appendChild(block);
	}

	function renderTypeBranch(n, pane) {
		var branch = n.typeBranch;
		if (!branch || !Array.isArray(branch.children) || !branch.children.length) {
			return;
		}

		/* Same Praefix allowlist+factors view as on the unit parent (child extras). */
		if (branch.unitAllowlistEdit) {
			var allowWrap = el('div', { className: 'wtt-type-branch' });
			var allowTitle = i18n.typeBranch || 'Type branch';
			if (branch.typeName) {
				allowTitle += ': ' + branch.typeName;
			}
			allowWrap.appendChild(
				el('h3', {
					className: 'wtt-type-branch__title',
					text: allowTitle,
				})
			);
			renderPrefixAllowlistEditor(branch, allowWrap);
			pane.appendChild(allowWrap);
			return;
		}

		var unitLocked = !!branch.unitFilter;
		var block = el('div', { className: 'wtt-type-branch' });
		var title = i18n.typeBranch || 'Type branch';
		if (branch.typeName) {
			title += ': ' + branch.typeName;
		}
		block.appendChild(
			el('h3', {
				className: 'wtt-type-branch__title',
				text: title,
			})
		);
		if (unitLocked) {
			var unitHint = i18n.prefixFilteredByUnit || 'Filtered by Basiseinheit allowlist';
			if (branch.unitName) {
				unitHint += ': ' + branch.unitName;
			}
			block.appendChild(el('p', { className: 'wtt-field-hint', text: unitHint }));
		} else if (i18n.typeBranchHint) {
			block.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.typeBranchHint }));
		}

		var list = el('ul', { className: 'wtt-type-branch__list' });
		branch.children.forEach(function (child) {
			if (!child || child.id == null) {
				return;
			}
			var item = el('li', {
				className:
					'wtt-type-branch__item' + (child.enabled ? '' : ' is-disabled'),
			});
			var label = el('label', { className: 'wtt-checkbox-label' });
			var check = el('input', {
				type: 'checkbox',
				className: 'wtt-branch-check',
			});
			if (child.enabled) {
				check.checked = true;
			}
			if (state.settingsSaving || unitLocked) {
				check.disabled = true;
			}
			check.addEventListener('change', function (e) {
				setDraftBranchChild(parseInt(child.id, 10) || 0, !!e.target.checked);
			});
			label.appendChild(check);
			label.appendChild(document.createTextNode(' ' + (child.name || String(child.id))));
			item.appendChild(label);
			list.appendChild(item);
		});
		block.appendChild(list);
		pane.appendChild(block);
	}

	function enabledBranchOptions(member) {
		var branch = member && member.typeBranch;
		if (!branch || !Array.isArray(branch.children)) {
			return [];
		}
		return branch.children.filter(function (child) {
			return child && child.enabled !== false;
		});
	}

	function previewValueKey(scope, member) {
		var scopePart = scope != null && String(scope) !== '' ? String(scope) : '_';
		var memberPart =
			member && member.id != null
				? 'id:' + member.id
				: 'name:' + String((member && member.name) || 'field');
		return String(state.selectedId || 0) + '|' + scopePart + '|' + memberPart;
	}

	function getPreviewValue(scope, member, fallback) {
		var key = previewValueKey(scope, member);
		if (Object.prototype.hasOwnProperty.call(state.previewValues, key)) {
			return state.previewValues[key];
		}
		return fallback;
	}

	function rememberPreviewFocus(control, key) {
		state.previewFocus = {
			key: key,
			start: typeof control.selectionStart === 'number' ? control.selectionStart : null,
			end: typeof control.selectionEnd === 'number' ? control.selectionEnd : null,
		};
	}

	function restorePreviewFocus() {
		var focus = state.previewFocus;
		if (!focus || !focus.key) {
			return;
		}
		var node = null;
		var nodes = document.querySelectorAll('[data-wtt-pv]');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].getAttribute('data-wtt-pv') === focus.key) {
				node = nodes[i];
				break;
			}
		}
		if (!node || typeof node.focus !== 'function') {
			return;
		}
		node.focus();
		if (
			focus.start != null &&
			focus.end != null &&
			typeof node.setSelectionRange === 'function' &&
			(node.tagName === 'TEXTAREA' || node.type === 'text' || node.type === 'number')
		) {
			try {
				node.setSelectionRange(focus.start, focus.end);
			} catch (err) {
				/* ignore unsupported input types */
			}
		}
	}

	function setPreviewValue(scope, member, value) {
		var key = previewValueKey(scope, member);
		state.previewValues[key] = value;
		render();
		restorePreviewFocus();
	}

	function bindPreviewControl(control, scope, member, opts) {
		opts = opts || {};
		var key = previewValueKey(scope, member);
		control.setAttribute('data-wtt-pv', key);
		var eventName = opts.event || 'input';
		control.addEventListener(eventName, function () {
			rememberPreviewFocus(control, key);
			var next = opts.readValue ? opts.readValue(control) : control.value;
			setPreviewValue(scope, member, next);
		});
		return control;
	}

	function previewSampleText(member) {
		if (member.fixed && member.fixed.name) {
			return String(member.fixed.name);
		}
		if (member.fixedLiteral != null && String(member.fixedLiteral) !== '') {
			return String(member.fixedLiteral);
		}
		var key = typeKeyFromMember(member);
		if (key === 'display_node_name') {
			return member.displayName || member.name || '—';
		}
		if (key === 'bool') {
			return i18n.boolTrue || 'true';
		}
		if (key === 'int') {
			return '12';
		}
		if (key === 'double' || key === 'quantity') {
			return '10.5';
		}
		if (key === 'char') {
			return 'A';
		}
		if (key === 'praefixe' || key === 'basiseinheit') {
			var opts = enabledBranchOptions(member);
			if (opts.length) {
				var pick = opts[0];
				for (var i = 0; i < opts.length; i++) {
					if (opts[i] && opts[i].name === 'm') {
						pick = opts[i];
						break;
					}
				}
				return (pick && pick.name) || '—';
			}
			return '—';
		}
		if (key === 'textarea') {
			return i18n.previewSampleText || 'Sample text';
		}
		return i18n.previewSampleText || 'Sample';
	}

	function livePreviewText(scope, member) {
		return String(getPreviewValue(scope, member, previewSampleText(member)));
	}

	function renderBranchSelect(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var editable = !!opts.editable;
		var scope = opts.scope;
		var sample = opts.sample != null ? String(opts.sample) : '';
		var options = enabledBranchOptions(member);
		var control = renderOptionsSelect(options, {
			className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--prefix' : ''),
			disabled: !editable,
			selectedValue: sample,
			getValue: function (child) {
				return String(child.name || child.id);
			},
		});
		if (editable) {
			bindPreviewControl(control, scope, member, { event: 'change' });
		}
		return control;
	}

	function canEditFixedValue(n) {
		if (!n || !n.typeId || n.isSet || n.isTable) {
			return false;
		}
		if (typeKeyFromMember(n) === 'display_node_name') {
			return false;
		}
		return true;
	}

	function formRow(labelText, controlNodes, opts) {
		opts = opts || {};
		var row = el('div', {
			className: 'wtt-form__row' + (opts.className ? ' ' + opts.className : ''),
		});
		var labelCol = el('div', { className: 'wtt-form__label' });
		if (opts.htmlFor) {
			labelCol.appendChild(
				el('label', {
					text: labelText || '',
					htmlFor: opts.htmlFor,
				})
			);
		} else {
			labelCol.appendChild(
				el('span', {
					className: 'wtt-form__label-text',
					text: labelText || '',
				})
			);
		}
		var controlCol = el('div', { className: 'wtt-form__control' });
		(Array.isArray(controlNodes) ? controlNodes : [controlNodes]).forEach(function (node) {
			if (node) {
				controlCol.appendChild(node);
			}
		});
		var helpCol = el('div', { className: 'wtt-form__help' });
		var helpNode = null;
		if (opts.help != null && opts.help !== '') {
			if (opts.help.nodeType) {
				helpNode = opts.help;
			} else {
				helpNode = renderHelpHint(opts.help);
			}
		}
		if (helpNode) {
			helpCol.appendChild(helpNode);
		}
		row.appendChild(labelCol);
		row.appendChild(controlCol);
		row.appendChild(helpCol);
		return row;
	}

	function fixedFieldHelpText(n) {
		var parts = [];
		if (i18n.fixedValueHint) {
			parts.push(i18n.fixedValueHint);
		}
		if (n && n.fixedEnabled) {
			if (supportsFixedLiteral(n.type)) {
				if (i18n.fixedLiteralHint) {
					parts.push(i18n.fixedLiteralHint);
				}
			} else if (i18n.fixedCatalogHint) {
				parts.push(i18n.fixedCatalogHint);
			}
		}
		return parts.join('\n\n');
	}

	function renderFixedValueField(n, controlsLocked) {
		// Hide entirely when not applicable — inactive stubs get noisy with many parameters.
		if (!canEditFixedValue(n)) {
			return null;
		}

		var mode = el('div', { className: 'wtt-fixed-mode' });
		[
			{ value: false, label: i18n.fixedValueOff || 'No fixed value' },
			{ value: true, label: i18n.fixedValueOn || 'Use fixed value' },
		].forEach(function (opt, index) {
			var id = 'wtt-fixed-mode-' + (opt.value ? 'on' : 'off');
			var label = el('label', {
				className: 'wtt-radio-label',
				htmlFor: id,
			});
			var radio = el('input', {
				type: 'radio',
				id: id,
				name: 'wtt-fixed-mode',
				value: opt.value ? '1' : '0',
			});
			if (!!n.fixedEnabled === opt.value) {
				radio.checked = true;
			}
			if (controlsLocked) {
				radio.disabled = true;
			}
			radio.addEventListener('change', function () {
				setDraftFixedEnabled(opt.value);
			});
			label.appendChild(radio);
			label.appendChild(document.createTextNode(' ' + opt.label));
			mode.appendChild(label);
			if (index === 0) {
				mode.appendChild(document.createTextNode(' '));
			}
		});
		var wrap = el('div', { className: 'wtt-fixed-control' });
		wrap.appendChild(mode);

		if (!n.fixedEnabled) {
			return wrap;
		}

		var key = typeKeyFromMember(n);
		if (supportsFixedLiteral(n.type)) {
			if (key === 'bool') {
				var boolSelect = el('select', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-type-select',
				});
				if (controlsLocked) {
					boolSelect.disabled = true;
				}
				[
					{ v: '0', t: i18n.boolFalse || 'false' },
					{ v: '1', t: i18n.boolTrue || 'true' },
				].forEach(function (opt) {
					var option = el('option', { value: opt.v, text: opt.t });
					if (String(n.fixedLiteral || '0') === opt.v) {
						option.selected = true;
					}
					boolSelect.appendChild(option);
				});
				boolSelect.addEventListener('change', function (e) {
					setDraftFixedLiteral(e.target.value);
				});
				wrap.appendChild(boolSelect);
			} else if (key === 'textarea') {
				var area = el('textarea', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-fixed-literal wtt-fixed-literal--textarea',
					rows: '3',
				});
				area.value = n.fixedLiteral || '';
				if (controlsLocked) {
					area.disabled = true;
				}
				area.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				wrap.appendChild(area);
			} else {
				var input = el('input', {
					id: 'wtt-node-fixed-literal',
					className: 'wtt-fixed-literal',
					type: key === 'int' || key === 'double' || key === 'quantity' ? 'number' : 'text',
					step: key === 'int' ? '1' : key === 'double' || key === 'quantity' ? 'any' : undefined,
					maxlength: key === 'char' ? '1' : undefined,
					value: n.fixedLiteral || '',
					placeholder: i18n.fixedLiteralPlaceholder || 'Constant value…',
				});
				if (controlsLocked) {
					input.disabled = true;
				}
				input.addEventListener('input', function (e) {
					setDraftFixedLiteral(e.target.value, { silent: true });
				});
				wrap.appendChild(input);
			}
			return wrap;
		}

		var fixedSelect = renderOptionsSelect(
			[{ id: 0, name: i18n.fixedValueChoose || 'Choose node' }].concat(
				(Array.isArray(n.fixedOptions) ? n.fixedOptions : []).filter(function (opt) {
					return opt && opt.id != null;
				})
			),
			{
				className: 'wtt-type-select',
				disabled: !!controlsLocked,
				selectedValue: n.fixedNodeId || 0,
				getValue: function (opt) {
					return String(opt.id);
				},
				onChange: function (e) {
					setDraftFixed(parseInt(e.target.value, 10) || 0);
				},
			}
		);
		fixedSelect.id = 'wtt-node-fixed';
		if (
			n.fixedNodeId &&
			!(Array.isArray(n.fixedOptions) ? n.fixedOptions : []).some(function (opt) {
				return opt && String(opt.id) === String(n.fixedNodeId);
			})
		) {
			fixedSelect.appendChild(
				el('option', {
					value: String(n.fixedNodeId),
					text: formatSelectLabel(n.fixed || { name: String(n.fixedNodeId) }),
					selected: true,
				})
			);
		}
		wrap.appendChild(fixedSelect);
		return wrap;
	}

	function renderTableSettings(n, pane) {
		if (!n.isTable) {
			return;
		}

		var block = el('div', { className: 'wtt-table-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-table-settings__title',
				text: i18n.tableSettings || 'Table settings',
			})
		);

		var field = el('div', { className: 'wtt-field wtt-field--footer' });
		var label = el('label', {
			className: 'wtt-checkbox-label',
			htmlFor: 'wtt-node-has-footer',
		});
		var check = el('input', {
			type: 'checkbox',
			id: 'wtt-node-has-footer',
			className: 'wtt-required-check',
		});
		if (n.hasFooter) {
			check.checked = true;
		}
		if (state.settingsSaving) {
			check.disabled = true;
		}
		check.addEventListener('change', function (e) {
			setDraftHasFooter(!!e.target.checked);
		});
		label.appendChild(check);
		label.appendChild(document.createTextNode(' ' + (i18n.hasFooter || 'Has footer (Fußzeile)')));
		field.appendChild(label);
		if (i18n.hasFooterHint) {
			field.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.hasFooterHint }));
		}
		block.appendChild(field);
		pane.appendChild(block);
	}

	function membersShareSameType(members) {
		if (!members || members.length < 2) {
			return false;
		}
		var first = memberTypeIdentity(members[0]);
		if (!first) {
			return false;
		}
		for (var i = 1; i < members.length; i++) {
			if (memberTypeIdentity(members[i]) !== first) {
				return false;
			}
		}
		return true;
	}

	/** True when the member’s quantity schema includes a Praefix slot. */
	function memberHasPraefixSlot(member) {
		if (!member || !member.quantitySchema || !Array.isArray(member.quantitySchema.members)) {
			return false;
		}
		for (var i = 0; i < member.quantitySchema.members.length; i++) {
			var m = member.quantitySchema.members[i];
			var key = String((m && m.name) || '')
				.toLowerCase()
				.replace(/ü/g, 'ue')
				.replace(/ä/g, 'ae')
				.replace(/ö/g, 'oe');
			if (key === 'praefix') {
				return true;
			}
		}
		return false;
	}

	/** Join-units UI/preview: same type + every member is a quantity with Praefix. */
	function canJoinSetUnits(members) {
		return membersShareSameType(members) && (members || []).every(memberHasPraefixSlot);
	}

	function memberTypeIdentity(member) {
		if (!member) {
			return '';
		}
		if (member.typeId) {
			return 'id:' + String(member.typeId);
		}
		if (member.type && member.type.id) {
			return 'id:' + String(member.type.id);
		}
		if (member.type && member.type.name) {
			return 'name:' + String(member.type.name).toLowerCase();
		}
		if (member.quantitySchema && member.quantitySchema.unitId) {
			return 'unit:' + String(member.quantitySchema.unitId);
		}
		return '';
	}

	function renderSetSettings(n, pane) {
		if (!n.isSet) {
			return;
		}

		var block = el('div', { className: 'wtt-set-settings' });
		block.appendChild(
			el('h3', {
				className: 'wtt-set-settings__title',
				text: i18n.setSettings || 'Set settings',
			})
		);

		var sepField = el('div', { className: 'wtt-field wtt-field--set-separator' });
		sepField.appendChild(
			el('label', {
				className: 'wtt-field__label',
				htmlFor: 'wtt-set-separator',
				text: i18n.setSeparator || 'Separator',
			})
		);
		var sepInput = el('input', {
			type: 'text',
			id: 'wtt-set-separator',
			className: 'wtt-set-separator-input',
			value: n.setSeparator != null ? String(n.setSeparator) : '/',
			maxlength: '16',
		});
		if (state.settingsSaving) {
			sepInput.disabled = true;
		}
		sepInput.addEventListener('input', function (e) {
			setDraftSetSeparator(e.target.value);
		});
		sepField.appendChild(sepInput);
		if (i18n.setSeparatorHint) {
			sepField.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.setSeparatorHint }));
		}
		block.appendChild(sepField);

		var labelKidsField = el('div', { className: 'wtt-field wtt-field--set-label-children' });
		var labelKidsLabel = el('label', {
			className: 'wtt-checkbox-label',
			htmlFor: 'wtt-set-label-children',
		});
		var labelKidsCheck = el('input', {
			type: 'checkbox',
			id: 'wtt-set-label-children',
			className: 'wtt-required-check',
		});
		if (n.setLabelChildren !== false) {
			labelKidsCheck.checked = true;
		}
		if (state.settingsSaving) {
			labelKidsCheck.disabled = true;
		}
		labelKidsCheck.addEventListener('change', function (e) {
			setDraftSetLabelChildren(!!e.target.checked);
		});
		labelKidsLabel.appendChild(labelKidsCheck);
		labelKidsLabel.appendChild(
			document.createTextNode(
				' ' + (i18n.setLabelChildren || 'Include children in label')
			)
		);
		labelKidsField.appendChild(labelKidsLabel);
		if (i18n.setLabelChildrenHint) {
			labelKidsField.appendChild(
				el('p', { className: 'wtt-field-hint', text: i18n.setLabelChildrenHint })
			);
		}
		block.appendChild(labelKidsField);

		var members = n.setMembers || [];
		var sameType = membersShareSameType(members);
		var canJoin = canJoinSetUnits(members);
		var joinField = el('div', { className: 'wtt-field wtt-field--set-join-units' });
		var joinLabel = el('label', {
			className: 'wtt-checkbox-label',
			htmlFor: 'wtt-set-join-units',
		});
		var joinCheck = el('input', {
			type: 'checkbox',
			id: 'wtt-set-join-units',
			className: 'wtt-required-check',
		});
		if (n.setJoinUnits !== false) {
			joinCheck.checked = true;
		}
		if (state.settingsSaving || !canJoin) {
			joinCheck.disabled = true;
		}
		joinCheck.addEventListener('change', function (e) {
			setDraftSetJoinUnits(!!e.target.checked);
		});
		joinLabel.appendChild(joinCheck);
		joinLabel.appendChild(
			document.createTextNode(' ' + (i18n.setJoinUnits || 'Join units'))
		);
		joinField.appendChild(joinLabel);
		var joinHint = i18n.setJoinUnitsHint ||
			'When all members share the same type with Praefix, choose Praefix once for all (e.g. 10.5/20/5mm).';
		if (!canJoin) {
			joinHint = !sameType
				? i18n.setJoinUnitsUnavailable ||
					'Available when every set member has the same data type.'
				: i18n.setJoinUnitsNoPrefix ||
					'Available when that shared type includes a Praefix.';
		}
		joinField.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text: joinHint,
			})
		);
		block.appendChild(joinField);
		pane.appendChild(block);
	}

	function renderSetMembers(n, pane) {
		// Option: show child properties under parent — only for set-typed nodes.
		if (!cfg.showSetChildProps || !n.isSet || !n.setMembers || !n.setMembers.length) {
			return;
		}

		var block = el('div', { className: 'wtt-set-members' });
		block.appendChild(
			el('h3', {
				className: 'wtt-set-members__title',
				text: i18n.setChildProperties || i18n.setMembers || 'Child properties',
			})
		);
		if (i18n.setChildPropertiesHint) {
			block.appendChild(el('p', { className: 'wtt-field-hint', text: i18n.setChildPropertiesHint }));
		}

		var table = el('table', { className: 'wtt-set-members__table' });
		var thead = el('thead');
		var headRow = el('tr');
		headRow.appendChild(el('th', { text: i18n.name || 'Name', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.setMemberType || 'Type', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.fixedValue || 'Fixed value', scope: 'col' }));
		headRow.appendChild(el('th', { text: i18n.required || 'Required', scope: 'col' }));
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		n.setMembers.forEach(function (member) {
			var row = el('tr');
			var nameCell = el('td');
			nameCell.appendChild(
				el('button', {
					type: 'button',
					className: 'button-link wtt-set-members__link',
					text: member.name,
					onClick: function () {
						selectNode(member.id);
					},
				})
			);
			row.appendChild(nameCell);
			row.appendChild(
				el('td', {
					text:
						(member.type && (member.type.path || member.type.name)) ||
						i18n.setMemberUntyped ||
						'— not typed —',
				})
			);
			row.appendChild(
				el('td', {
					text:
						(member.fixedEnabled &&
							((member.fixedLiteral && String(member.fixedLiteral)) ||
								(member.fixed && (member.fixed.path || member.fixed.name)))) ||
						i18n.fixedValueNone ||
						'— Not fixed —',
				})
			);
			row.appendChild(
				el('td', {
					text: member.required
						? i18n.required || 'Required'
						: i18n.optional || 'Optional',
				})
			);
			tbody.appendChild(row);
		});
		table.appendChild(tbody);
		block.appendChild(table);
		pane.appendChild(block);
	}

	function typeKeyFromMember(member) {
		var name = (member.type && member.type.name) || '';
		name = String(name).trim().toLowerCase();
		if (name === 'integer') {
			return 'int';
		}
		if (name === 'float' || name === 'number') {
			return 'double';
		}
		if (name === 'boolean') {
			return 'bool';
		}
		if (name === 'string' || name === 'varchar') {
			return 'text';
		}
		if (name === 'praefixe' || name === 'präfixe') {
			return 'praefixe';
		}
		if (name === 'basiseinheit') {
			return 'basiseinheit';
		}
		if (
			name === 'display_node_name' ||
			name === 'display node name' ||
			name === 'displayname' ||
			name === 'node_name'
		) {
			return 'display_node_name';
		}
		return name || 'text';
	}

	function helpChildLine(child) {
		var parts = [child.name || ''];
		if (child.typeName) {
			parts.push('(' + child.typeName + ')');
		}
		if (child.required) {
			parts.push('*');
		}
		if (child.fixed) {
			parts.push('= ' + child.fixed);
		}
		var line = parts.join(' ');
		var short =
			child.shortDescription != null ? String(child.shortDescription).trim() : '';
		var long = child.description != null ? String(child.description).trim() : '';
		if (short) {
			line += ' — ' + short;
		} else if (long) {
			line += ' — ' + long;
		}
		return line;
	}

	function appendHelpChildren(container, children, depth) {
		depth = depth || 0;
		if (!children || !children.length) {
			return;
		}
		var list = el('ul', { className: 'wtt-help__children' + (depth ? ' wtt-help__children--nested' : '') });
		children.forEach(function (child) {
			var li = el('li', { text: helpChildLine(child) });
			if (child.children && child.children.length) {
				appendHelpChildren(li, child.children, depth + 1);
			}
			list.appendChild(li);
		});
		container.appendChild(list);
	}

	/**
	 * @param {string|{description?:string,helpChildren?:Array}|null} descriptionOrHelp
	 */
	function renderHelpHint(descriptionOrHelp) {
		var description = '';
		var helpChildren = null;
		if (descriptionOrHelp && typeof descriptionOrHelp === 'object') {
			description = descriptionOrHelp.description != null ? String(descriptionOrHelp.description) : '';
			helpChildren = descriptionOrHelp.helpChildren || descriptionOrHelp.children || null;
		} else {
			description = descriptionOrHelp != null ? String(descriptionOrHelp) : '';
		}
		description = description.trim();
		var hasChildren = !!(helpChildren && helpChildren.length);
		if (!description && !hasChildren) {
			return null;
		}

		var titleBits = [];
		if (description) {
			titleBits.push(description);
		}
		if (hasChildren) {
			helpChildren.forEach(function (c) {
				titleBits.push(helpChildLine(c));
			});
		}
		var title = titleBits.join('\n');

		var wrap = el('span', { className: 'wtt-help' });
		var btn = el('button', {
			type: 'button',
			className: 'wtt-help__btn',
			title: title,
			'aria-label': i18n.helpShowDescription || 'Show description',
		});
		btn.appendChild(el('span', { className: 'dashicons dashicons-editor-help', 'aria-hidden': 'true' }));

		var pop = el('div', {
			className: 'wtt-help__pop',
			hidden: true,
		});
		if (description) {
			pop.appendChild(el('p', { className: 'wtt-help__text', text: description }));
		}
		if (hasChildren) {
			if (description) {
				pop.appendChild(
					el('p', {
						className: 'wtt-help__subhead',
						text: i18n.helpChildProperties || 'Child properties',
					})
				);
			}
			appendHelpChildren(pop, helpChildren, 0);
		}

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var open = pop.hasAttribute('hidden');
			if (open) {
				pop.removeAttribute('hidden');
				btn.classList.add('is-open');
			} else {
				pop.setAttribute('hidden', 'hidden');
				btn.classList.remove('is-open');
			}
		});

		wrap.appendChild(btn);
		wrap.appendChild(pop);
		return wrap;
	}

	function memberHelpPayload(member) {
		var children = [];
		if (member.helpChildren && member.helpChildren.length) {
			children = member.helpChildren;
		} else if (member.typeBranch && member.typeBranch.children && member.typeBranch.children.length) {
			children = member.typeBranch.children.map(function (c) {
				return {
					name: c.name || '',
					typeName: (c.type && c.type.name) || c.typeName || '',
					fixed: (c.fixed && c.fixed.name) || c.fixed || '',
					required: !!c.required,
					description: c.description || '',
					children: c.children || null,
				};
			});
		}
		return {
			description: member.description || '',
			helpChildren: children,
		};
	}

	/**
	 * ---------------------------------------------------------------------------
	 * Preview field views (generic)
	 *
	 * Call sites pass a field descriptor (set member or normalized node).
	 * Same kind → same view everywhere (form row, table cell, unit usage, …).
	 *
	 * - quantity: Typ + Praefix + Kuerzel (quantitySchema on a typed field, or
	 *   a Basiseinheit unit node’s own setMembers)
	 * - scalar: one control from the field’s data type / typeBranch
	 * Editable mode writes into state.previewValues; Display reads the same keys.
	 * ---------------------------------------------------------------------------
	 */

	/**
	 * Normalize a set-member or detail node into a preview field descriptor.
	 */
	function asPreviewField(source) {
		if (!source) {
			return null;
		}
		if (source.quantitySchema && Array.isArray(source.quantitySchema.members)) {
			return source;
		}
		if (source.isBasiseinheitUnit && Array.isArray(source.setMembers) && source.setMembers.length) {
			return {
				name: source.name || '',
				displayName: source.name || '',
				description: source.description || '',
				required: !!source.required,
				fixedLiteral: source.fixedLiteral || '',
				fixed: source.fixed || null,
				fixedEnabled: !!source.fixedEnabled,
				type: source.type || null,
				typeBranch: null,
				quantitySchema: {
					unitId: source.id || 0,
					unitName: source.name || '',
					members: source.setMembers,
				},
			};
		}
		if (source.type || source.typeId || source.name) {
			return {
				name: source.name || '',
				displayName: source.displayName || source.name || '',
				description: source.description || '',
				helpChildren: source.helpChildren || [],
				type: source.type || null,
				required: !!source.required,
				fixedEnabled: !!source.fixedEnabled,
				fixedLiteral: source.fixedLiteral || '',
				fixed: source.fixed || null,
				fixedNodeId: source.fixedNodeId || 0,
				typeBranch: source.typeBranch || null,
				quantitySchema: source.quantitySchema || null,
			};
		}
		return null;
	}

	/**
	 * Quantity trinity members for a field, or null if not a quantity field.
	 */
	function resolveQuantityMembers(field) {
		if (!field || !field.quantitySchema || !Array.isArray(field.quantitySchema.members)) {
			return null;
		}
		var members = deepClone(field.quantitySchema.members);
		var magnitude =
			field.fixedLiteral != null && String(field.fixedLiteral) !== ''
				? String(field.fixedLiteral)
				: field.fixed && field.fixed.name
					? String(field.fixed.name)
					: '';
		if (magnitude) {
			var typ = findSetMemberByKey(members, 'typ') || findSetMemberByKey(members, 'wert');
			if (typ) {
				typ.fixedEnabled = true;
				typ.fixedLiteral = magnitude;
				typ.fixed = null;
				typ.fixedNodeId = 0;
			}
		}
		return members;
	}

	/**
	 * Shared quantity view: Typ + optional Praefix + Kuerzel symbol.
	 */
	function renderQuantityView(members, mode, scope) {
		var typ = findSetMemberByKey(members, 'typ') || findSetMemberByKey(members, 'wert');
		var praefix = findSetMemberByKey(members, 'praefix');
		var sample = typ
			? livePreviewText(scope, typ)
			: getPreviewValue(scope, { name: 'Typ' }, '10.5');

		if (mode === 'display') {
			var prefixPart = samplePrefixLetter(praefix, scope);
			var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
			var symbol =
				(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
				(kuerzelMem && kuerzelMem.fixedLiteral) ||
				'';
			return el('span', {
				className: 'wtt-preview-display-value wtt-preview-quantity',
				text: sample + String(prefixPart || '') + String(symbol || composeUnitDisplay(members) || ''),
			});
		}

		var group = el('div', { className: 'wtt-preview-quantity' });
		if (typ) {
			group.appendChild(
				renderScalarFieldView(typ, { compact: true, mode: 'edit', scope: scope })
			);
		} else {
			var fallbackTyp = { name: 'Typ', type: { name: 'double' } };
			var num = el('input', {
				type: 'number',
				className: 'wtt-preview-input wtt-preview-input--num',
				step: 'any',
				value: sample,
			});
			bindPreviewControl(num, scope, fallbackTyp);
			group.appendChild(num);
		}
		if (praefix) {
			group.appendChild(
				renderScalarFieldView(praefix, { compact: true, mode: 'edit', scope: scope })
			);
		}
		var kuerzel = findSetMemberByKey(members, 'kuerzel');
		var symbolText =
			(kuerzel && kuerzel.fixed && kuerzel.fixed.name) ||
			(kuerzel && kuerzel.fixedLiteral) ||
			'';
		if (symbolText) {
			group.appendChild(
				el('span', {
					className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
					text: symbolText,
				})
			);
		}
		return group;
	}

	/**
	 * Single entry for any preview control (form, table, unit usage).
	 */
	function renderFieldView(field, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var normalized = asPreviewField(field) || field;
		var scope =
			opts.scope != null && String(opts.scope) !== ''
				? opts.scope
				: normalized && (normalized.id != null ? normalized.id : normalized.name);
		var qty = resolveQuantityMembers(normalized);
		if (qty) {
			return renderQuantityView(qty, mode, scope);
		}
		return renderScalarFieldView(
			normalized,
			Object.assign({}, opts, { mode: mode, scope: scope })
		);
	}

	/** @deprecated Use renderFieldView — kept as alias for call-site clarity during scaffold. */
	function renderPreviewControl(member, opts) {
		return renderFieldView(member, opts);
	}

	function renderScalarFieldView(member, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var scope = opts.scope;
		var editable = mode === 'edit';
		var key = typeKeyFromMember(member);
		var sample = livePreviewText(scope, member);
		var isFixedCatalog = !!(member.fixed && member.fixed.name);
		var isFixedLiteral =
			member.fixedLiteral != null && String(member.fixedLiteral) !== '' && !isFixedCatalog;

		if (mode === 'display') {
			if (key === 'bool') {
				var boolOn = sample === '1' || sample === 'true' || sample === (i18n.boolTrue || 'true');
				return el('span', {
					className: 'wtt-preview-display-value' + (compact ? ' wtt-preview-display-value--compact' : ''),
					text: boolOn ? i18n.boolTrue || 'true' : i18n.boolFalse || 'false',
				});
			}
			return el('span', {
				className: 'wtt-preview-display-value' + (compact ? ' wtt-preview-display-value--compact' : ''),
				text: sample,
			});
		}

		var control;

		if (key === 'display_node_name') {
			var shown = member.displayName || member.name || '';
			if (compact) {
				return el('span', {
					className: 'wtt-preview-display-name',
					text: shown,
				});
			}
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input wtt-preview-input--display-name',
				value: shown,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (isFixedCatalog) {
			if (compact) {
				return el('span', {
					className: 'wtt-preview-fixed-text',
					text: member.fixed.name,
					title: (i18n.previewFixed || 'fixed') + ': ' + member.fixed.name,
				});
			}
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input',
				value: member.fixed.name,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (isFixedLiteral && (key === 'int' || key === 'double' || key === 'quantity' || key === 'text' || key === 'char')) {
			/* Schema-fixed magnitude still shown; not interactive in preview. */
			control = el('input', {
				type: key === 'int' || key === 'double' || key === 'quantity' ? 'number' : 'text',
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--num' : ''),
				value: sample,
				readonly: 'readonly',
			});
			control.disabled = true;
			return control;
		}

		if (key === 'bool') {
			control = el('input', {
				type: 'checkbox',
				className: 'wtt-preview-check',
			});
			control.checked =
				sample === '1' || sample === 'true' || sample === (i18n.boolTrue || 'true');
			if (editable) {
				bindPreviewControl(control, scope, member, {
					event: 'change',
					readValue: function (node) {
						return node.checked ? '1' : '0';
					},
				});
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'textarea') {
			if (compact) {
				control = el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					value: sample,
				});
			} else {
				control = el('textarea', {
					className: 'wtt-preview-input wtt-preview-textarea',
					rows: '1',
				});
				control.value = sample;
			}
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'praefixe') {
			return renderBranchSelect(member, {
				compact: compact,
				sample: sample,
				editable: editable,
				scope: scope,
			});
		}

		if (key === 'basiseinheit') {
			if (member.typeBranch && enabledBranchOptions(member).length) {
				return renderBranchSelect(member, {
					compact: compact,
					sample: sample,
					editable: editable,
					scope: scope,
				});
			}
			var unitLabel = sample || (member.name || 'Basiseinheit');
			control = renderOptionsSelect([{ name: unitLabel }], {
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--compact' : ''),
				disabled: !editable,
				selectedValue: unitLabel,
				getValue: function (opt) {
					return String(opt.name || '');
				},
			});
			if (editable) {
				bindPreviewControl(control, scope, member, { event: 'change' });
			}
			return control;
		}

		if (key === 'node_ref' || key === 'subtree') {
			control = el('select', {
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--compact' : ''),
			});
			control.appendChild(el('option', { value: '1', text: sample }));
			if (editable) {
				bindPreviewControl(control, scope, member, { event: 'change' });
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'int' || key === 'double' || key === 'quantity') {
			control = el('input', {
				type: 'number',
				className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--num' : ''),
				placeholder: key === 'int' ? '0' : '0.0',
				step: key === 'int' ? '1' : 'any',
				value: sample,
			});
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		if (key === 'char') {
			control = el('input', {
				type: 'text',
				className: 'wtt-preview-input wtt-preview-input--char',
				maxlength: '1',
				value: sample,
			});
			if (editable) {
				bindPreviewControl(control, scope, member);
			} else {
				control.disabled = true;
			}
			return control;
		}

		control = el('input', {
			type: 'text',
			className: 'wtt-preview-input' + (compact ? ' wtt-preview-input--compact' : ''),
			value: sample,
		});
		if (editable) {
			bindPreviewControl(control, scope, member);
		} else {
			control.disabled = true;
		}
		return control;
	}

	/**
	 * Caption for a set field: "Abmessung (Länge/Breite/Höhe)" from set name +
	 * member shortDescription (fallback: name) + separator.
	 * @param {string} setName
	 * @param {Array} members
	 * @param {string|{separator?:string,includeChildren?:boolean}} separatorOrOpts
	 */
	function setFieldCaption(setName, members, separatorOrOpts) {
		var opts =
			separatorOrOpts && typeof separatorOrOpts === 'object'
				? separatorOrOpts
				: { separator: separatorOrOpts };
		var sep = opts.separator != null ? String(opts.separator) : '/';
		var includeChildren = opts.includeChildren !== false;
		var base = setName != null ? String(setName).trim() : '';
		if (!includeChildren) {
			return base || (i18n.previewColField || 'Field');
		}
		var parts = [];
		(members || []).forEach(function (m) {
			if (!m) {
				return;
			}
			var short = m.shortDescription != null ? String(m.shortDescription).trim() : '';
			var part = short || (m.name ? String(m.name) : '');
			if (part) {
				parts.push(part);
			}
		});
		if (!parts.length) {
			return base || (i18n.previewColField || 'Field');
		}
		var joined = parts.join(sep);
		return base ? base + ' (' + joined + ')' : joined;
	}

	/** Shared set label/display options from a node (or draft view). */
	function setFieldOptionsFromNode(n) {
		return {
			separator: n && n.setSeparator != null ? String(n.setSeparator) : '/',
			joinUnits: !n || n.setJoinUnits !== false,
			includeChildren: !n || n.setLabelChildren !== false,
			asSetField: true,
		};
	}

	/**
	 * Magnitude + unit suffix for a field (quantity → value / prefix+symbol).
	 * @param {*} member
	 * @param {string|null} sharedPrefixScope When set (join-units), Praefix is read from this scope.
	 */
	function fieldDisplayParts(member, sharedPrefixScope) {
		var normalized = asPreviewField(member) || member;
		var scope =
			normalized && (normalized.id != null ? normalized.id : normalized.name);
		var qty = resolveQuantityMembers(normalized);
		if (qty) {
			var typ = findSetMemberByKey(qty, 'typ') || findSetMemberByKey(qty, 'wert');
			var praefix = findSetMemberByKey(qty, 'praefix');
			var value = typ
				? livePreviewText(scope, typ)
				: getPreviewValue(scope, { name: 'Typ' }, '10.5');
			var prefixScope = sharedPrefixScope != null ? sharedPrefixScope : scope;
			var prefixPart = samplePrefixLetter(praefix, prefixScope);
			var kuerzelMem = findSetMemberByKey(qty, 'kuerzel');
			var symbol =
				(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
				(kuerzelMem && kuerzelMem.fixedLiteral) ||
				'';
			var unit = String(prefixPart || '') + String(symbol || '');
			return { value: String(value), unit: unit, full: String(value) + unit };
		}
		var text = livePreviewText(scope, normalized);
		return { value: text, unit: '', full: text };
	}

	function setJoinSharedScope() {
		return 'join:' + String(state.selectedId || 0);
	}

	/** Same type + quantity with Praefix → shared Praefix/Kuerzel in preview. */
	function membersAreJoinableQuantities(members) {
		if (!canJoinSetUnits(members)) {
			return false;
		}
		return (members || []).every(function (m) {
			var qty = resolveQuantityMembers(asPreviewField(m) || m);
			return !!(qty && findSetMemberByKey(qty, 'praefix'));
		});
	}

	/**
	 * Display-only set: values joined with separator; optional shared unit at end.
	 */
	function renderSetJoinedDisplay(members, opts) {
		opts = opts || {};
		var sep = opts.separator != null ? String(opts.separator) : '/';
		var joinUnits = !!opts.joinUnits && membersShareSameType(members);
		var sharedScope = joinUnits && membersAreJoinableQuantities(members) ? setJoinSharedScope() : null;
		var parts = (members || []).map(function (m) {
			return fieldDisplayParts(m, sharedScope);
		});
		var text = '';
		if (joinUnits && parts.length) {
			var unit = parts[0].unit;
			var allSameUnit =
				unit !== '' &&
				parts.every(function (p) {
					return p.unit === unit;
				});
			if (allSameUnit) {
				text =
					parts
						.map(function (p) {
							return p.value;
						})
						.join(sep) + unit;
			} else {
				text = parts
					.map(function (p) {
						return p.full;
					})
					.join(sep);
			}
		} else {
			text = parts
				.map(function (p) {
					return p.full;
				})
				.join(sep);
		}
		return el('span', {
			className: 'wtt-preview-display-value wtt-set-preview__joined',
			text: text,
		});
	}

	/**
	 * Editable set with join-units: magnitudes + separator, then one shared Praefix + Kuerzel.
	 */
	function renderSetJoinedQuantityEdit(members, opts) {
		opts = opts || {};
		var separator = opts.separator != null ? String(opts.separator) : '/';
		var sharedScope = setJoinSharedScope();
		var cell = el('div', {
			className: 'wtt-set-preview__cell wtt-set-preview__cell--joined-qty',
			title: i18n.setTableCellHint || 'Compact set as one table field',
		});
		var count = (members || []).length;
		if (count > 0) {
			cell.style.setProperty('--wtt-set-parts', String(count));
		}

		(members || []).forEach(function (member, index) {
			if (index > 0) {
				cell.appendChild(
					el('span', {
						className: 'wtt-set-preview__sep',
						text: separator,
						'aria-hidden': 'true',
					})
				);
			}
			var part = el('span', { className: 'wtt-set-preview__cell-part' });
			if (count > 0) {
				part.style.flexBasis = (100 / count).toFixed(4) + '%';
			}
			var qty = resolveQuantityMembers(asPreviewField(member) || member);
			var scope = member.id != null ? member.id : member.name;
			var typ = qty
				? findSetMemberByKey(qty, 'typ') || findSetMemberByKey(qty, 'wert')
				: null;
			if (typ) {
				part.appendChild(
					renderScalarFieldView(typ, { compact: true, mode: 'edit', scope: scope })
				);
			} else {
				part.appendChild(renderFieldView(member, { compact: true, mode: 'edit' }));
			}
			cell.appendChild(part);
		});

		var firstQty = resolveQuantityMembers(asPreviewField(members[0]) || members[0]);
		var unitWrap = el('span', { className: 'wtt-set-preview__shared-unit' });
		if (firstQty) {
			var praefix = findSetMemberByKey(firstQty, 'praefix');
			if (praefix) {
				unitWrap.appendChild(
					renderScalarFieldView(praefix, {
						compact: true,
						mode: 'edit',
						scope: sharedScope,
					})
				);
			}
			var kuerzel = findSetMemberByKey(firstQty, 'kuerzel');
			var symbolText =
				(kuerzel && kuerzel.fixed && kuerzel.fixed.name) ||
				(kuerzel && kuerzel.fixedLiteral) ||
				'';
			if (symbolText) {
				unitWrap.appendChild(
					el('span', {
						className: 'wtt-preview-fixed-text wtt-preview-quantity__symbol',
						text: symbolText,
					})
				);
			}
		}
		cell.appendChild(unitWrap);
		return cell;
	}

	/**
	 * One help for a set field: parent description, then each member (and nested) below.
	 * Prefer server helpChildren; else build from preview members.
	 */
	function setFieldHelpPayload(parentDescription, helpChildren, members) {
		var children = Array.isArray(helpChildren) && helpChildren.length ? helpChildren : null;
		if (!children && members && members.length) {
			children = members.map(function (m) {
				var nested = memberHelpPayload(m);
				return {
					name: m.name || '',
					typeName: (m.type && m.type.name) || m.typeName || '',
					required: !!m.required,
					fixed: (m.fixed && m.fixed.name) || m.fixed || '',
					description: nested.description || '',
					children:
						nested.helpChildren && nested.helpChildren.length
							? nested.helpChildren
							: null,
				};
			});
		}
		return {
			description: parentDescription != null ? String(parentDescription) : '',
			helpChildren: children || [],
		};
	}

	/**
	 * Set as one form row (same idea as one table column): label + inline member controls.
	 */
	function renderSetAsOneFormRow(setName, members, mode, helpPayload, setOpts) {
		setOpts = setOpts || {};
		var separator = setOpts.separator != null ? String(setOpts.separator) : '/';
		var joinUnits = setOpts.joinUnits !== false;
		var includeChildren = setOpts.includeChildren !== false;
		var form = el('div', {
			className:
				'wtt-set-preview__form' +
				(mode === 'display' ? ' wtt-set-preview__form--display' : ''),
		});
		var row = el('div', { className: 'wtt-set-preview__row wtt-set-preview__row--set-field' });
		row.appendChild(
			el('label', {
				className: 'wtt-set-preview__label',
				text: setFieldCaption(setName, members, {
					separator: separator,
					includeChildren: includeChildren,
				}),
			})
		);
		if (mode === 'display') {
			row.appendChild(
				renderSetJoinedDisplay(members, {
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		} else if (joinUnits && membersAreJoinableQuantities(members)) {
			row.appendChild(
				renderSetJoinedQuantityEdit(members, {
					separator: separator,
				})
			);
		} else {
			row.appendChild(
				renderSetTableCell(members, {
					mode: mode,
					showPartLabels: false,
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		}
		var help = renderHelpHint(helpPayload || setFieldHelpPayload('', null, members));
		if (help) {
			row.appendChild(help);
		}
		form.appendChild(row);
		return form;
	}

	function renderSetFormPreview(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		if (opts.asSetField && members && members.length > 1) {
			var helpPayload = setFieldHelpPayload(
				opts.setDescription || '',
				opts.helpChildren || null,
				members
			);
			return renderSetAsOneFormRow(opts.setName || '', members, mode, helpPayload, {
				separator: opts.separator != null ? opts.separator : '/',
				joinUnits: opts.joinUnits !== false,
				includeChildren: opts.includeChildren !== false,
			});
		}
		var form = el('div', {
			className: 'wtt-set-preview__form' + (mode === 'display' ? ' wtt-set-preview__form--display' : ''),
		});
		members.forEach(function (member) {
			var key = typeKeyFromMember(member);
			var row = el('div', {
				className:
					'wtt-set-preview__row' +
					(key === 'display_node_name' ? ' wtt-set-preview__row--display-name' : ''),
			});

			if (key === 'display_node_name') {
				var nameWrap = el('div', { className: 'wtt-set-preview__display-name' });
				nameWrap.appendChild(renderFieldView(member, { compact: false, mode: mode }));
				row.appendChild(nameWrap);
				if (mode === 'edit') {
					var helpOnly = renderHelpHint(memberHelpPayload(member));
					if (helpOnly) {
						row.appendChild(helpOnly);
					}
				}
				form.appendChild(row);
				return;
			}

			var label = el('label', { className: 'wtt-set-preview__label' });
			var title = member.name || '';
			if (member.required) {
				title += ' *';
			}
			label.appendChild(document.createTextNode(title));
			if (member.fixed && member.fixed.name) {
				label.appendChild(
					el('span', {
						className: 'wtt-set-preview__badge',
						text: ' ' + (i18n.previewFixed || 'fixed'),
					})
				);
			}
			row.appendChild(label);
			row.appendChild(renderFieldView(member, { compact: false, mode: mode }));
			if (mode === 'edit') {
				var help = renderHelpHint(memberHelpPayload(member));
				if (help) {
					row.appendChild(help);
				}
			}
			form.appendChild(row);
		});
		return form;
	}

	function renderSetTableCell(members, opts) {
		opts = opts || {};
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var showPartLabels = opts.showPartLabels !== false;
		var separator = opts.separator != null ? String(opts.separator) : '/';
		var joinUnits = opts.joinUnits !== false;

		if (mode === 'display' && members && members.length > 1) {
			return renderSetJoinedDisplay(members, {
				separator: separator,
				joinUnits: joinUnits,
			});
		}

		if (
			mode === 'edit' &&
			joinUnits &&
			members &&
			members.length > 1 &&
			membersAreJoinableQuantities(members)
		) {
			return renderSetJoinedQuantityEdit(members, {
				separator: separator,
			});
		}

		var cell = el('div', {
			className: 'wtt-set-preview__cell' + (mode === 'display' ? ' wtt-set-preview__cell--display' : ''),
			title: i18n.setTableCellHint || 'Compact set as one table field',
		});
		var count = (members || []).length;
		if (count > 0) {
			cell.style.setProperty('--wtt-set-parts', String(count));
		}
		(members || []).forEach(function (member, index) {
			if (index > 0) {
				cell.appendChild(
					el('span', {
						className: 'wtt-set-preview__sep',
						text: separator,
						'aria-hidden': 'true',
					})
				);
			}
			var part = el('span', { className: 'wtt-set-preview__cell-part' });
			if (count > 0) {
				part.style.flexBasis = (100 / count).toFixed(4) + '%';
			}
			var key = typeKeyFromMember(member);
			var showLabel =
				showPartLabels &&
				!(member.fixed && member.fixed.name) &&
				key !== 'praefixe';
			if (showLabel && member.name) {
				part.appendChild(
					el('span', {
						className: 'wtt-set-preview__cell-label',
						text: member.name,
					})
				);
			}
			part.appendChild(renderFieldView(member, { compact: true, mode: mode }));
			cell.appendChild(part);
		});
		return cell;
	}

	/** Generic table context: neighbor columns + this node as ONE field (set members stay in the cell). */
	function renderGenericFieldTablePreview(members, fieldLabel, mode, setOpts) {
		setOpts = setOpts || {};
		var separator = setOpts.separator != null ? String(setOpts.separator) : '/';
		var joinUnits = setOpts.joinUnits !== false;
		var includeChildren = setOpts.includeChildren !== false;
		var caption =
			setOpts.asSetField && members && members.length > 1
				? setFieldCaption(fieldLabel, members, {
						separator: separator,
						includeChildren: includeChildren,
				  })
				: fieldLabel || i18n.previewColField || 'Field';
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		[
			i18n.previewColIndex || '#',
			i18n.previewColOther || 'Column A',
			caption,
			i18n.previewColNote || 'Column B',
		].forEach(function (label) {
			headRow.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var row = el('tr');
		row.appendChild(el('td', { text: '1' }));
		if (mode === 'edit') {
			var otherTd = el('td');
			otherTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: i18n.previewSampleText || 'Sample',
				})
			);
			row.appendChild(otherTd);
		} else {
			row.appendChild(el('td', { text: i18n.previewSampleText || 'Sample' }));
		}
		var fieldTd = el('td', { className: 'wtt-set-preview__td-set' });
		if (members.length > 1) {
			fieldTd.appendChild(
				renderSetTableCell(members, {
					mode: mode,
					separator: separator,
					joinUnits: joinUnits,
				})
			);
		} else if (members.length === 1) {
			fieldTd.appendChild(renderFieldView(members[0], { compact: true, mode: mode }));
		} else {
			fieldTd.appendChild(document.createTextNode('—'));
		}
		row.appendChild(fieldTd);
		if (mode === 'edit') {
			var noteTd = el('td');
			noteTd.appendChild(
				el('input', {
					type: 'text',
					className: 'wtt-preview-input wtt-preview-input--compact',
					disabled: 'disabled',
					value: '…',
				})
			);
			row.appendChild(noteTd);
		} else {
			row.appendChild(el('td', { text: '…' }));
		}
		tbody.appendChild(row);
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function memberNameKey(member) {
		return String((member && member.name) || '')
			.toLowerCase()
			.replace(/ü/g, 'ue')
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe');
	}

	/**
	 * Basiseinheit set helpers: Praefix + Kuerzel → mm / kΩ / °C (no atomic mm node).
	 */
	function findSetMemberByKey(members, nameKey) {
		var found = null;
		(members || []).forEach(function (m) {
			if (!found && memberNameKey(m) === nameKey) {
				found = m;
			}
		});
		return found;
	}

	/**
	 * Basiseinheit unit symbol: fixed Praefix (if any) + Kuerzel.
	 * Does not invent a sample prefix — optional Praefix stays off the unit label (Meter → m, not mm).
	 */
	function composeUnitDisplay(members) {
		members = members || [];
		var prefix = '';
		var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
		var praefixMem = findSetMemberByKey(members, 'praefix');
		var kuerzel =
			(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
			(kuerzelMem && kuerzelMem.fixedLiteral) ||
			'';
		if (praefixMem && praefixMem.fixed && praefixMem.fixed.name) {
			prefix = praefixMem.fixed.name;
		} else if (praefixMem && praefixMem.fixedLiteral) {
			prefix = String(praefixMem.fixedLiteral);
		}
		if (prefix === 'Mega') {
			prefix = 'M';
		}
		if (!kuerzel) {
			return '';
		}
		return String(prefix || '') + String(kuerzel);
	}

	/**
	 * Sample prefix letter for usage demos (optional Praefix → prefer milli "m" → e.g. 10.5mm).
	 * Empty when Praefix is absent or has no enabled options.
	 */
	function samplePrefixLetter(praefixMem, scope) {
		if (!praefixMem) {
			return '';
		}
		if (praefixMem.fixed && praefixMem.fixed.name) {
			return praefixMem.fixed.name === 'Mega' ? 'M' : String(praefixMem.fixed.name);
		}
		if (praefixMem.fixedLiteral) {
			return String(praefixMem.fixedLiteral);
		}
		var live = scope != null ? getPreviewValue(scope, praefixMem, null) : null;
		if (live != null && String(live) !== '') {
			var liveName = String(live);
			return liveName === 'Mega' ? 'M' : liveName;
		}
		var opts = enabledBranchOptions(praefixMem);
		if (!opts.length) {
			return '';
		}
		var pick = opts[0];
		for (var pi = 0; pi < opts.length; pi++) {
			if (opts[pi] && opts[pi].name === 'm') {
				pick = opts[pi];
				break;
			}
		}
		var name = (pick && pick.name) || '';
		return name === 'Mega' ? 'M' : name;
	}

	/** Basiseinheit unit (= set schema Typ/Praefix/Kuerzel), not a fillable instance. */
	function isUnitDefinitionNode(n) {
		return !!(n && n.isBasiseinheitUnit);
	}

	function memberTypeLabel(member) {
		if (!member) {
			return '—';
		}
		if (member.type && member.type.name) {
			return String(member.type.name);
		}
		return typeKeyFromMember(member) || '—';
	}

	function renderUnitSchemaDefinition(members) {
		var wrap = el('div', { className: 'wtt-preview-schema' });
		wrap.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.previewSchema || 'Definition',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unitSchemaHint ||
					'This node defines the unit schema only — not an instance value.',
			})
		);
		var table = el('table', { className: 'wtt-set-preview__table wtt-preview-schema__table' });
		var thead = el('thead');
		var head = el('tr');
		[
			i18n.previewColField || 'Field',
			i18n.previewColType || 'Type',
			i18n.previewColConstraint || 'Constraint',
		].forEach(function (label) {
			head.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(head);
		table.appendChild(thead);
		var tbody = el('tbody');
		(members || []).forEach(function (m) {
			var tr = el('tr');
			var name = m.name || '—';
			if (m.required) {
				name += ' *';
			}
			tr.appendChild(el('td', { text: name }));
			tr.appendChild(el('td', { text: memberTypeLabel(m) }));
			var constraint = '—';
			var memKey = memberNameKey(m);
			if (m.fixed && m.fixed.name) {
				constraint =
					(i18n.previewFixed || 'fixed') +
					': ' +
					formatSelectLabel(m.fixed);
			} else if (m.fixedLiteral != null && String(m.fixedLiteral) !== '') {
				/*
				 * Kuerzel uses a fixed symbol literal (Meter → "m"). That is NOT
				 * the Praefix catalog node "m" (Milli) — same letter, different role.
				 */
				if (memKey === 'kuerzel') {
					constraint =
						(i18n.previewFixedSymbol || 'fixed symbol') +
						': ' +
						String(m.fixedLiteral);
				} else {
					constraint =
						(i18n.previewFixed || 'fixed') + ': ' + String(m.fixedLiteral);
				}
			} else if (memKey === 'praefix') {
				constraint = i18n.previewOptionalPrefix || 'optional (allowlist)';
			}
			tr.appendChild(el('td', { text: constraint }));
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Conversion table for a Basiseinheit unit (enabled prefixes × root factor → SI).
	 */
	function renderUnitConversions(n, members) {
		var branch =
			(n && n.prefixBranch && n.prefixBranch.unitAllowlistEdit && n.prefixBranch) ||
			null;
		if (!branch) {
			var praefixMem = findSetMemberByKey(members, 'praefix');
			if (praefixMem && praefixMem.typeBranch && praefixMem.typeBranch.unitAllowlistEdit) {
				branch = praefixMem.typeBranch;
			}
		}
		var kuerzelMem = findSetMemberByKey(members, 'kuerzel');
		var kuerzel =
			(kuerzelMem && kuerzelMem.fixed && kuerzelMem.fixed.name) ||
			(kuerzelMem && kuerzelMem.fixedLiteral) ||
			'';
		if (!kuerzel) {
			return null;
		}

		var rootToSi =
			branch && branch.unitPrefixRootToSi != null && isFinite(Number(branch.unitPrefixRootToSi))
				? Number(branch.unitPrefixRootToSi)
				: n && n.prefixRootToSi != null && isFinite(Number(n.prefixRootToSi))
					? Number(n.prefixRootToSi)
					: 1;
		var siSymbol = rootToSi === 1 ? kuerzel : n.name || 'SI';

		var wrap = el('div', { className: 'wtt-unit-conversions' });
		wrap.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: i18n.unitConversions || 'Conversions',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unitConversionsHint ||
					'to_si = Typ × multiplikator × prefix_root_to_si.',
			})
		);
		wrap.appendChild(
			el('p', {
				className: 'wtt-unit-conversions__root',
				text:
					(i18n.prefixRootToSi || 'Unit: prefix root → SI base') +
					': ' +
					formatFactor(rootToSi),
			})
		);

		var table = el('table', {
			className: 'wtt-set-preview__table wtt-unit-conversions__table',
		});
		var thead = el('thead');
		var head = el('tr');
		[
			i18n.unitConvPrefix || 'Praefix',
			i18n.unitConvSymbol || 'Symbol',
			i18n.unitConvFactor || '× factor',
			i18n.unitConvToSi || '1 → SI',
			i18n.unitConvSample || '10.5 → SI',
		].forEach(function (label) {
			head.appendChild(el('th', { text: label, scope: 'col' }));
		});
		thead.appendChild(head);
		table.appendChild(thead);

		var tbody = el('tbody');
		function addRow(prefixName, symbol, factor) {
			var tr = el('tr');
			tr.appendChild(el('td', { text: prefixName }));
			tr.appendChild(el('td', { text: symbol }));
			tr.appendChild(el('td', { text: formatFactor(factor) }));
			tr.appendChild(
				el('td', {
					text: formatFactor(1 * factor * rootToSi) + ' ' + siSymbol,
				})
			);
			tr.appendChild(
				el('td', {
					text: formatFactor(10.5 * factor * rootToSi) + ' ' + siSymbol,
				})
			);
			tbody.appendChild(tr);
		}

		addRow(i18n.unitConvNone || '(none)', kuerzel, 1);

		if (branch && Array.isArray(branch.children)) {
			branch.children.forEach(function (child) {
				if (!child || !child.enabled) {
					return;
				}
				var factor =
					child.multiplikator != null && isFinite(Number(child.multiplikator))
						? Number(child.multiplikator)
						: null;
				if (factor == null || factor <= 0) {
					return;
				}
				var prefixLetter = child.name === 'Mega' ? 'M' : String(child.name || '');
				addRow(String(child.name || ''), prefixLetter + kuerzel, factor);
			});
		}

		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	/**
	 * Usage sample for a unit definition — same quantity field view as any unit-typed slot.
	 */
	function renderUnitUsageForm(members, nodeName, mode) {
		var field = asPreviewField({
			name: nodeName || '',
			isBasiseinheitUnit: true,
			setMembers: members,
		});
		return renderSetFormPreview([field], { mode: mode });
	}

	function renderUnitUsageTable(members, fieldLabel, mode) {
		var field = asPreviewField({
			name: fieldLabel || '',
			isBasiseinheitUnit: true,
			setMembers: members,
		});
		return renderGenericFieldTablePreview([field], fieldLabel || '', mode);
	}

	function scalarMemberFromNode(n) {
		return {
			name: n.name || '',
			displayName: n.name || '',
			description: n.description || '',
			helpChildren: n.helpChildren || [],
			type: n.type || null,
			required: !!n.required,
			fixedEnabled: !!n.fixedEnabled,
			fixedLiteral: n.fixedLiteral || '',
			fixed: n.fixed || null,
			fixedNodeId: n.fixedNodeId || 0,
			typeBranch: n.typeBranch || null,
			quantitySchema: n.quantitySchema || null,
		};
	}

	function collectColumnsFromTree(nodes, parentId) {
		var found = [];
		(nodes || []).forEach(function (node) {
			if (node.id === parentId) {
				(node.children || []).forEach(function (child) {
					found.push(child);
				});
			} else if (node.children && node.children.length) {
				found = found.concat(collectColumnsFromTree(node.children, parentId));
			}
		});
		return found;
	}

	function getPreviewMembers(n) {
		if (n.isSet && n.setMembers && n.setMembers.length) {
			return n.setMembers;
		}
		if (n.isTable) {
			var cols = collectColumnsFromTree(state.tree, n.id);
			if (!cols.length) {
				cols = [
					{ name: (i18n.previewColGeneric || 'Column') + ' 1' },
					{ name: (i18n.previewColGeneric || 'Column') + ' 2' },
					{ name: (i18n.previewColGeneric || 'Column') + ' 3' },
				];
			}
			return cols.map(function (col) {
				var childMembers = null;
				if (col.children && col.children.length > 1) {
					childMembers = col.children.map(function (ch) {
						return { name: ch.name || '—' };
					});
				}
				return {
					name: col.name || '—',
					type: col.type || { name: 'text' },
					required: !!col.required,
					fixed: col.fixed || null,
					fixedLiteral: col.fixedLiteral || '',
					typeBranch: col.typeBranch || null,
					setMembers: childMembers,
					setSeparator: '/',
					setLabelChildren: true,
				};
			});
		}
		if (n.typeId && n.type) {
			return [scalarMemberFromNode(n)];
		}
		/* Untyped: still show form/table samples as a plain text field. */
		return [
			{
				name: n.name || (i18n.previewColField || 'Field'),
				displayName: n.name || '',
				description: n.description || '',
				type: { name: 'text' },
				required: false,
				fixed: null,
				fixedLiteral: '',
				typeBranch: null,
			},
		];
	}

	function renderMultiColumnTablePreview(columns, mode, hasFooter) {
		var wrap = el('div', { className: 'wtt-set-preview__table-wrap' });
		var table = el('table', { className: 'wtt-set-preview__table' });
		var thead = el('thead');
		var headRow = el('tr');
		columns.forEach(function (col) {
			var header = col.name || '—';
			var childMembers = col.setMembers || col.members || null;
			if (childMembers && childMembers.length > 1) {
				header = setFieldCaption(col.name || '', childMembers, {
					separator: col.setSeparator != null ? String(col.setSeparator) : '/',
					includeChildren: col.setLabelChildren !== false,
				});
			}
			headRow.appendChild(el('th', { text: header, scope: 'col' }));
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var row = el('tr');
		columns.forEach(function (col) {
			var td = el('td');
			td.appendChild(renderPreviewControl(col, { compact: true, mode: mode }));
			row.appendChild(td);
		});
		tbody.appendChild(row);
		table.appendChild(tbody);

		if (hasFooter) {
			var tfoot = el('tfoot');
			var footRow = el('tr');
			columns.forEach(function (col, c) {
				footRow.appendChild(
					el('td', {
						className: 'wtt-table-preview__footer-cell',
						text: c === 0 ? (i18n.previewFooter || 'Footer') : 'Σ / —',
					})
				);
			});
			tfoot.appendChild(footRow);
			table.appendChild(tfoot);
		}

		wrap.appendChild(table);
		return wrap;
	}

	function renderPreviewVariant(title, contentNode) {
		var variant = el('div', { className: 'wtt-preview__variant' });
		variant.appendChild(
			el('h5', {
				className: 'wtt-preview__variant-title',
				text: title,
			})
		);
		variant.appendChild(contentNode);
		return variant;
	}

	function renderPreviewSurface(surfaceTitle, editNode, displayNode) {
		var section = el('div', { className: 'wtt-set-preview__section' });
		section.appendChild(
			el('h4', {
				className: 'wtt-set-preview__subtitle',
				text: surfaceTitle,
			})
		);
		var pair = el('div', { className: 'wtt-preview__pair' });
		pair.appendChild(
			renderPreviewVariant(i18n.previewEditable || 'Editable', editNode)
		);
		pair.appendChild(
			renderPreviewVariant(i18n.previewDisplayOnly || 'Display only', displayNode)
		);
		section.appendChild(pair);
		return section;
	}

	function renderUnifiedPreviewContent(n) {
		var members = getPreviewMembers(n);
		var block = el('div', { className: 'wtt-preview__body' });

		if (!members || !members.length) {
			block.appendChild(
				el('p', {
					className: 'wtt-preview__unavailable',
					text: i18n.previewUnavailable || 'Preview nicht möglich',
				})
			);
			return block;
		}

		/* Unit catalog node: definition table + same quantity field view as any unit-typed slot. */
		if (isUnitDefinitionNode(n)) {
			block.appendChild(renderUnitSchemaDefinition(members));
			var unitLabel = composeUnitDisplay(members);
			if (unitLabel) {
				block.appendChild(
					el('p', {
						className: 'wtt-unit-compose',
						text: (i18n.unitDisplayLabel || 'Unit label') + ': ' + unitLabel,
					})
				);
			}
			var conversions = renderUnitConversions(n, members);
			if (conversions) {
				block.appendChild(conversions);
			}
			block.appendChild(
				el('p', {
					className: 'wtt-field-hint',
					text:
						i18n.unitUsageHint ||
						'Usage sample when a field uses this unit (value + prefix + symbol).',
				})
			);
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsForm || 'Form',
					renderUnitUsageForm(members, n.name || '', 'edit'),
					renderUnitUsageForm(members, n.name || '', 'display')
				)
			);
			block.appendChild(
				renderPreviewSurface(
					i18n.previewAsTable || 'Table',
					renderUnitUsageTable(members, n.name || '', 'edit'),
					renderUnitUsageTable(members, n.name || '', 'display')
				)
			);
			return block;
		}

		block.appendChild(
			el('p', {
				className: 'wtt-field-hint',
				text:
					i18n.unifiedPreviewHint ||
					'Form and table layouts — each as editable input and display-only.',
			})
		);

		/*
		 * Set form = one labeled row (e.g. "Abmessung (L/B/H)"), members inline —
		 * same composition idea as the single table cell. Non-set keeps stacked fields.
		 * Help is one popover: parent description, then children underneath.
		 */
		var setOpts = n.isSet ? setFieldOptionsFromNode(n) : {};
		var formOpts = n.isSet
			? {
					mode: 'edit',
					setName: n.name || '',
					setDescription: n.description || '',
					helpChildren: n.helpChildren || null,
					asSetField: true,
					separator: setOpts.separator,
					joinUnits: setOpts.joinUnits,
					includeChildren: setOpts.includeChildren,
			  }
			: { mode: 'edit' };
		var formDisplayOpts = n.isSet
			? {
					mode: 'display',
					setName: n.name || '',
					setDescription: n.description || '',
					helpChildren: n.helpChildren || null,
					asSetField: true,
					separator: setOpts.separator,
					joinUnits: setOpts.joinUnits,
					includeChildren: setOpts.includeChildren,
			  }
			: { mode: 'display' };
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsForm || 'Form',
				renderSetFormPreview(members, formOpts),
				renderSetFormPreview(members, formDisplayOpts)
			)
		);

		var tableEdit;
		var tableDisplay;
		if (n.isTable) {
			tableEdit = renderMultiColumnTablePreview(members, 'edit', !!n.hasFooter);
			tableDisplay = renderMultiColumnTablePreview(members, 'display', !!n.hasFooter);
		} else {
			/*
			 * Set (and scalar) table context: the node is ONE field/column.
			 * Set members (L/B/H, …) live inside that cell — not as sibling columns.
			 * Column header uses the same setFieldCaption as the form label.
			 */
			var tableSetOpts = n.isSet ? setOpts : {};
			tableEdit = renderGenericFieldTablePreview(members, n.name || '', 'edit', tableSetOpts);
			tableDisplay = renderGenericFieldTablePreview(
				members,
				n.name || '',
				'display',
				tableSetOpts
			);
		}
		block.appendChild(
			renderPreviewSurface(
				i18n.previewAsTable || 'Table',
				tableEdit,
				tableDisplay
			)
		);

		return block;
	}

	function renderNodePreview(n, pane) {
		var block = el('div', { className: 'wtt-set-preview wtt-preview' });
		block.appendChild(
			el('h3', {
				className: 'wtt-set-preview__title',
				text: i18n.setPreview || 'Preview',
			})
		);
		block.appendChild(renderUnifiedPreviewContent(n));
		pane.appendChild(block);
	}

	function renderDetail() {
		var pane = el('div', { className: 'wtt-detail-pane' });
		try {
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

			var n = viewNode();
			var dirty = isSettingsDirty();
			var controlsLocked = saveViaButtonEnabled() && !!state.settingsSaving;

			pane.appendChild(renderDetailToolbar(n, dirty, controlsLocked));

			if (n.setParent && n.setParent.id) {
				var parentLink = el('button', {
					type: 'button',
					className: 'button-link',
					text: n.setParent.name || String(n.setParent.id),
					onClick: function () {
						selectNode(n.setParent.id);
					},
				});
				var parentLine = el('p', { className: 'wtt-set-parent' });
				parentLine.appendChild(document.createTextNode((i18n.setParent || 'Member of set') + ': '));
				parentLine.appendChild(parentLink);
				pane.appendChild(parentLine);
			}

			var form = el('div', { className: 'wtt-form wtt-detail' });

			var parentControl;
			var parentId = parseInt(n.parent, 10) || 0;
			if (parentId > 0) {
				parentControl = el('button', {
					type: 'button',
					className: 'button-link wtt-form__link',
					text: n.parentName || String(parentId),
					title: i18n.goToParent || 'Open parent in tree and settings',
					onClick: function () {
						selectNode(parentId);
					},
				});
			} else {
				parentControl = el('span', {
					className: 'wtt-form__readonly',
					text: i18n.none || '—',
				});
			}
			form.appendChild(formRow(i18n.parent || 'Parent', [parentControl]));

			var nameInput = el('input', {
				type: 'text',
				id: 'wtt-node-name',
				className: 'wtt-name-input regular-text',
				value: n.name || '',
			});
			if (controlsLocked) {
				nameInput.disabled = true;
			}
			nameInput.addEventListener('input', function (e) {
				setDraftName(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.name || 'Name', [nameInput], {
					htmlFor: 'wtt-node-name',
					help: i18n.nameHint || '',
				})
			);

			var shortInput = el('input', {
				type: 'text',
				id: 'wtt-node-short-description',
				className: 'wtt-short-description-input regular-text',
				value: n.shortDescription || '',
				placeholder: '…',
			});
			if (controlsLocked) {
				shortInput.disabled = true;
			}
			shortInput.addEventListener('input', function (e) {
				setDraftShortDescription(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.shortDescription || 'Short description', [shortInput], {
					htmlFor: 'wtt-node-short-description',
					help: i18n.shortDescriptionHint || '',
				})
			);

			var descInput = el('textarea', {
				id: 'wtt-node-description',
				className: 'wtt-description-input large-text',
				rows: '3',
			});
			descInput.value = n.description || '';
			if (controlsLocked) {
				descInput.disabled = true;
			}
			descInput.addEventListener('input', function (e) {
				setDraftDescription(e.target.value, { silent: true });
			});
			form.appendChild(
				formRow(i18n.description || 'Description', [descInput], {
					htmlFor: 'wtt-node-description',
					className: 'wtt-form__row--description',
					help: {
						description: i18n.descriptionHint || '',
						helpChildren: n.helpChildren || [],
					},
				})
			);

			var typeOptions = Array.isArray(n.typeOptions) ? n.typeOptions : [];
			var typeSelect = renderOptionsSelect(
				[{ id: 0, name: i18n.dataTypeNone || 'No type' }].concat(
					typeOptions.filter(function (opt) {
						return opt && opt.id != null;
					})
				),
				{
					className: 'wtt-type-select',
					disabled: !!controlsLocked,
					selectedValue: n.typeId || 0,
					getValue: function (opt) {
						return String(opt.id);
					},
					onChange: function (e) {
						setDraftType(parseInt(e.target.value, 10) || 0);
					},
				}
			);
			typeSelect.id = 'wtt-node-type';
			if (
				n.typeId &&
				!typeOptions.some(function (opt) {
					return opt && String(opt.id) === String(n.typeId);
				})
			) {
				typeSelect.appendChild(
					el('option', {
						value: String(n.typeId),
						text: formatSelectLabel(n.type || { name: String(n.typeId) }),
						selected: true,
					})
				);
			}
			var typeControls = [typeSelect];
			if (state.settingsSaving) {
				typeControls.push(
					el('p', { className: 'wtt-type-saving', text: i18n.dataTypeSaving || 'Saving…' })
				);
			}
			var isDisplayName = typeKeyFromMember(n) === 'display_node_name';
			var typeHelpParts = [];
			if (i18n.dataTypeHint) {
				typeHelpParts.push(i18n.dataTypeHint);
			}
			if (isDisplayName && i18n.displayNodeNameHint) {
				typeHelpParts.push(i18n.displayNodeNameHint);
			}
			form.appendChild(
				formRow(i18n.dataType || 'Data type', typeControls, {
					htmlFor: 'wtt-node-type',
					help: typeHelpParts.join('\n\n'),
				})
			);

			if (!isDisplayName) {
				var requiredCheck = el('input', {
					type: 'checkbox',
					id: 'wtt-node-required',
					className: 'wtt-required-check',
				});
				if (n.required) {
					requiredCheck.checked = true;
				}
				if (controlsLocked) {
					requiredCheck.disabled = true;
				}
				requiredCheck.addEventListener('change', function (e) {
					setDraftRequired(!!e.target.checked);
				});
				form.appendChild(
					formRow(i18n.required || 'Required', [requiredCheck], {
						htmlFor: 'wtt-node-required',
						className: 'wtt-form__row--check',
						help: i18n.requiredHint || '',
					})
				);
			}

			var fixedControl = renderFixedValueField(n, controlsLocked);
			if (fixedControl) {
				form.appendChild(
					formRow(i18n.fixedValue || 'Fixed value', [fixedControl], {
						className: 'wtt-form__row--fixed',
						help: fixedFieldHelpText(n),
					})
				);
			}

			form.appendChild(
				formRow(i18n.slug || 'Slug', [
					el('span', { className: 'wtt-form__readonly', text: n.slug || '—' }),
				])
			);
			form.appendChild(
				formRow(i18n.count || 'Assigned posts', [
					el('span', {
						className: 'wtt-form__readonly',
						text: String(n.count != null ? n.count : 0),
					}),
				])
			);

			pane.appendChild(form);
			renderChildExtrasOnParent(n, pane);
			renderSetMembers(n, pane);
			renderTypeBranch(n, pane);
			renderTableSettings(n, pane);
			renderSetSettings(n, pane);
			renderNodePreview(n, pane);
		} catch (err) {
			pane.appendChild(
				el('p', {
					className: 'wtt-error',
					text: (i18n.error || 'Something went wrong.') + ' ' + String(err && err.message ? err.message : err),
				})
			);
		}
		return pane;
	}

	function applyDemoTree(tree) {
		state.tree = tree || [];
		state.selectedId = null;
		state.selectedNode = null;
		state.draft = null;
		state.savedDraft = null;
		state.settingsSaving = false;
		state.error = '';
		state.expanded = {};
		(state.tree || []).forEach(function (n) {
			if (n && n.id && n.name === 'BOM Testprojekt') {
				state.expanded[n.id] = true;
			}
		});
		persistTreeUi();
		render();
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

		root.innerHTML = '';

		var taxSelect = el('select', {
			id: 'wtt-taxonomy',
			onChange: function (e) {
				persistTreeUi();
				state.taxonomy = e.target.value;
				state.selectedId = null;
				state.selectedNode = null;
				state.draft = null;
				state.savedDraft = null;
				state.expanded = {};
				state.error = '';
				post('wtt_get_tree', {})
					.then(function (json) {
						if (!json || !json.success) {
							setError((json && json.data && json.data.message) || i18n.error);
							return;
						}
						state.tree = json.data.tree || [];
						restoreTreeUi();
						persistTreeUi();
						if (state.selectedId) {
							selectNode(state.selectedId);
						} else {
							render();
						}
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

		var toolbarChildren = [
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
		];
		if (cfg.testMode) {
			toolbarChildren.push(
				el('button', {
					type: 'button',
					className: 'button',
					text: i18n.resetDemo || 'Reset test tree',
					onClick: resetDemo,
				})
			);
		}
		var toolbar = el('div', { className: 'wtt-toolbar' }, toolbarChildren);

		var treeList = el('ul', { className: 'wtt-tree' });
		if (!state.tree.length) {
			treeList.appendChild(el('li', { className: 'wtt-empty', text: i18n.empty }));
		} else {
			renderTreeNodes(state.tree, treeList);
		}

		var treePane = el('div', { className: 'wtt-tree-pane' }, [toolbar, treeList]);
		root.appendChild(treePane);

		try {
			root.appendChild(renderDetail());
		} catch (err) {
			var fallback = el('div', { className: 'wtt-detail-pane' });
			fallback.appendChild(
				el('p', {
					className: 'wtt-error',
					text: (i18n.error || 'Something went wrong.') + ' ' + String(err && err.message ? err.message : err),
				})
			);
			root.appendChild(fallback);
		}

		if (intro) {
			if (state.selectedNode && state.selectedNode.name) {
				intro.textContent = (i18n.inspecting || 'Inspecting:') + ' ' + state.selectedNode.name;
			} else if (state.selectedId) {
				intro.textContent = i18n.loading || 'Loading...';
			} else {
				intro.textContent = i18n.selectHint || '';
			}
		}
	}

	function boot() {
		restoreTreeUi();
		if (state.selectedId) {
			selectNode(state.selectedId);
		} else {
			render();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
