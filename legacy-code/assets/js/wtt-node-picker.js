/**
 * Shared admin TreeChooser (Q90/Q92).
 *
 * Canonical entry: window.WTTNodePicker.render(opts)
 * Do not invent a third tree UI — extend this module or ListChooser.
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var expandedBuckets = {};
	var openBuckets = {};
	var queryBuckets = {};

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
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function ensureBucket(store, key) {
		if (!store[key]) {
			store[key] = {};
		}
		return store[key];
	}

	function findNodeInTree(nodes, id) {
		id = parseInt(id, 10) || 0;
		if (!id || !Array.isArray(nodes)) {
			return null;
		}
		var i;
		for (i = 0; i < nodes.length; i++) {
			var n = nodes[i];
			if (!n) {
				continue;
			}
			if ((parseInt(n.id, 10) || 0) === id) {
				return n;
			}
			var found = findNodeInTree(n.children || [], id);
			if (found) {
				return found;
			}
		}
		return null;
	}

	function findNode(opts, id) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return null;
		}
		var trees = [];
		if (opts && Array.isArray(opts.roots)) {
			trees.push(opts.roots);
		}
		if (opts && Array.isArray(opts.lookupTrees)) {
			opts.lookupTrees.forEach(function (t) {
				if (Array.isArray(t)) {
					trees.push(t);
				}
			});
		}
		var i;
		for (i = 0; i < trees.length; i++) {
			var hit = findNodeInTree(trees[i], id);
			if (hit) {
				return hit;
			}
		}
		return null;
	}

	function buildPathLabel(opts, id) {
		id = parseInt(id, 10) || 0;
		if (!id) {
			return '';
		}
		var parts = [];
		var guard = 0;
		var cur = id;
		while (cur > 0 && guard < 64) {
			++guard;
			var n = findNode(opts, cur);
			if (!n) {
				break;
			}
			parts.unshift(n.name || String(cur));
			cur = parseInt(n.parent, 10) || 0;
		}
		return parts.join(' / ');
	}

	function expandAncestorsInMap(nodes, targetId, expandedMap, trail) {
		targetId = parseInt(targetId, 10) || 0;
		if (!targetId || !Array.isArray(nodes)) {
			return false;
		}
		trail = trail || [];
		var i;
		for (i = 0; i < nodes.length; i++) {
			var n = nodes[i];
			if (!n) {
				continue;
			}
			var id = parseInt(n.id, 10) || 0;
			var next = trail.concat([id]);
			if (id === targetId) {
				next.slice(0, -1).forEach(function (aid) {
					expandedMap[aid] = true;
					expandedMap[String(aid)] = true;
				});
				return true;
			}
			if (expandAncestorsInMap(n.children || [], targetId, expandedMap, next)) {
				return true;
			}
		}
		return false;
	}

	function collectExpandableIds(nodes, out) {
		out = out || [];
		(nodes || []).forEach(function (n) {
			if (!n) {
				return;
			}
			var kids = n.children || [];
			if (kids.length || n.hasChildren) {
				out.push(n.id);
				collectExpandableIds(kids, out);
			}
		});
		return out;
	}

	function applyAdaptiveNodeLabel(target, name, path) {
		if (!target) {
			return;
		}
		name = String(name || '');
		path = String(path || name);
		target.textContent = name;
		target.title = path && path !== name ? path : name;
	}

	function resolvePickerFocusId(opts) {
		opts = opts || {};
		var explicit = opts.focusId != null ? parseInt(opts.focusId, 10) || 0 : 0;
		if (opts.preferFocus && explicit > 0) {
			return explicit;
		}
		var selected = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		if (selected > 0) {
			return selected;
		}
		if (explicit > 0) {
			return explicit;
		}
		return 0;
	}

	function treePickerPresentation(opts) {
		opts = opts || {};
		if (opts.presentation === 'inline' || opts.embedded) {
			return 'inline';
		}
		if (opts.presentation === 'popup') {
			return 'popup';
		}
		var mode = opts.treePickerMode || (global.wttSettings && global.wttSettings.saved && global.wttSettings.saved.treePickerMode) || 'popup';
		return mode === 'inline' ? 'inline' : 'popup';
	}

	function shouldExpandFocusBranch(opts) {
		opts = opts || {};
		if (opts.expandFocusBranch != null) {
			return !!opts.expandFocusBranch;
		}
		if (opts.expandSelectedPath != null) {
			return !!opts.expandSelectedPath;
		}
		return false;
	}

	function i18nOf(opts) {
		return (opts && opts.i18n) || {};
	}

	function renderPopup(opts) {
		opts = opts || {};
		var i18n = i18nOf(opts);
		var selectedId = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : function () {};
		var disabled = !!opts.disabled;
		var allowClear = opts.allowClear !== false;
		var placeholder = opts.placeholder || i18n.nodeRefChoose || 'Choose…';
		var pickLabel = selectedId
			? i18n.nodePickerChange || 'Change…'
			: i18n.nodePickerChoose || 'Choose…';
		var roots = opts.roots || [];
		var allowRoot = !!opts.allowRoot;
		var rootLabel = opts.rootLabel || i18n.reparentRoot || 'Root (no parent)';

		function labelForId(id) {
			id = parseInt(id, 10) || 0;
			if (allowRoot && id === 0) {
				return rootLabel;
			}
			if (!id) {
				return '';
			}
			var n = findNode(opts, id);
			if (n && n.name) {
				return n.name;
			}
			if (opts.selectedLabel && String(opts.selectedId) === String(id)) {
				var raw = String(opts.selectedLabel);
				var parts = raw.split(/\s*\/\s*/);
				return parts.length ? parts[parts.length - 1] : raw;
			}
			return '#' + id;
		}

		var currentName = selectedId ? labelForId(selectedId) : '';
		var currentPath = '';
		if (selectedId) {
			if (opts.selectedLabel && String(opts.selectedId) === String(selectedId)) {
				currentPath = String(opts.selectedLabel);
			} else {
				currentPath = buildPathLabel(opts, selectedId);
			}
		}
		var emptyLabel = placeholder || '—';
		var wrap = el('div', {
			className:
				'wtt-node-picker wtt-node-picker--popup-trigger' +
				(disabled ? ' is-disabled' : '') +
				(opts.className ? ' ' + opts.className : ''),
		});

		var valueEl = el('span', {
			className: 'wtt-node-picker__value' + (selectedId && currentName ? '' : ' is-empty'),
			text: selectedId && currentName ? currentName : emptyLabel,
		});
		if (selectedId && currentName) {
			applyAdaptiveNodeLabel(valueEl, currentName, currentPath || currentName);
		} else {
			valueEl.title = emptyLabel;
		}
		wrap.appendChild(valueEl);

		var actions = el('div', { className: 'wtt-node-picker__actions' });
		actions.appendChild(
			el('button', {
				type: 'button',
				className: 'wtt-node-picker__icon-btn wtt-node-picker__open',
				disabled: disabled ? 'disabled' : undefined,
				title: pickLabel,
				'aria-label': pickLabel,
				html: '<span class="dashicons dashicons-category" aria-hidden="true"></span>',
				onClick: function (e) {
					e.preventDefault();
					if (disabled) {
						return;
					}
					openDialog(opts, function (id) {
						onSelect(id);
					});
				},
			})
		);

		if (allowClear) {
			actions.appendChild(
				el('button', {
					type: 'button',
					className: 'wtt-node-picker__icon-btn wtt-node-picker__clear-icon',
					disabled: disabled || !selectedId ? 'disabled' : undefined,
					title: i18n.nodePickerClear || 'Clear',
					'aria-label': i18n.nodePickerClear || 'Clear',
					html: '<span class="dashicons dashicons-trash" aria-hidden="true"></span>',
					onClick: function (e) {
						e.preventDefault();
						if (disabled || !selectedId) {
							return;
						}
						onSelect(0);
					},
				})
			);
		}
		wrap.appendChild(actions);
		return wrap;
	}

	function openDialog(opts, onDone) {
		opts = opts || {};
		var i18n = i18nOf(opts);
		var localSelected = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var focusId = resolvePickerFocusId(opts);
		var expandKey = opts.expandKey || 'dialog-pick';
		var pickerHost = el('div', { className: 'wtt-node-picker-dialog__host' });

		expandedBuckets[expandKey] = {};
		if (focusId && shouldExpandFocusBranch(opts)) {
			expandAncestorsInMap(opts.roots || [], focusId, expandedBuckets[expandKey], []);
			(opts.lookupTrees || []).forEach(function (tree) {
				expandAncestorsInMap(tree, focusId, expandedBuckets[expandKey], []);
			});
			expandedBuckets[expandKey][focusId] = true;
			expandedBuckets[expandKey][String(focusId)] = true;
		}

		function close() {
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop);
			}
		}

		function mount() {
			pickerHost.innerHTML = '';
			pickerHost.appendChild(
				renderInline(
					Object.assign({}, opts, {
						selectedId: localSelected,
						expandKey: expandKey,
						presentation: 'inline',
						embedded: true,
						defaultOpen: true,
						compact: false,
						showPickedLabel: opts.showPickedLabel !== false,
						currentId:
							opts.currentId != null
								? opts.currentId
								: localSelected
								? localSelected
								: focusId,
						onSelect: function (id) {
							localSelected = id;
							if (typeof onDone === 'function') {
								onDone(id);
							}
							close();
						},
					})
				)
			);
		}
		mount();

		var backdrop = el('div', { className: 'wtt-dialog-backdrop' }, [
			el('div', { className: 'wtt-dialog wtt-dialog--node-picker', role: 'dialog' }, [
				el('h2', {
					text: opts.dialogTitle || i18n.nodePickerTitle || 'Choose node',
				}),
				pickerHost,
				el('div', { className: 'wtt-dialog__actions' }, [
					el('button', {
						type: 'button',
						className: 'button',
						text: i18n.cancel || 'Cancel',
						onClick: function () {
							close();
						},
					}),
				]),
			]),
		]);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) {
				close();
			}
		});
		document.body.appendChild(backdrop);
		if (focusId && shouldExpandFocusBranch(opts)) {
			window.requestAnimationFrame(function () {
				var row = backdrop.querySelector('.wtt-node-picker__row.is-current');
				if (row && typeof row.scrollIntoView === 'function') {
					row.scrollIntoView({ block: 'center', behavior: 'smooth' });
				}
			});
		}
	}

	function renderInline(opts) {
		opts = opts || {};
		var i18n = i18nOf(opts);
		var roots = opts.roots || [];
		var selectedId = opts.selectedId != null ? parseInt(opts.selectedId, 10) || 0 : 0;
		var onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : function () {};
		var blocked = opts.blockedIds || {};
		var allowRoot = !!opts.allowRoot;
		var rootLabel = opts.rootLabel || i18n.reparentRoot || 'Root (no parent)';
		var currentId = opts.currentId != null ? parseInt(opts.currentId, 10) || 0 : null;
		var compact = !!opts.compact;
		var disabled = !!opts.disabled;
		var expandKey = opts.expandKey || 'default';
		var expandedMap = ensureBucket(expandedBuckets, expandKey);
		var selectableFn =
			typeof opts.selectable === 'function'
				? opts.selectable
				: function () {
						return true;
				  };
		var showPickedLabel = opts.showPickedLabel !== false;
		var pickedPrefix = opts.pickedPrefix || i18n.nodePickerSelected || 'Selected:';
		var placeholder = opts.placeholder || i18n.nodeRefChoose || 'Choose node…';
		var allowClear = opts.allowClear !== false;
		var showTypeInTree = !!opts.showTypeInTree;
		var pendingScrollTop =
			opts.restoreScrollTop != null ? parseInt(opts.restoreScrollTop, 10) || 0 : null;

		var defaultOpen = opts.defaultOpen != null ? !!opts.defaultOpen : !compact;
		if (openBuckets[expandKey] == null) {
			openBuckets[expandKey] = defaultOpen;
		}
		if (queryBuckets[expandKey] == null) {
			queryBuckets[expandKey] = '';
		}

		var wrap = el('div', {
			className:
				'wtt-node-picker' +
				(compact ? ' wtt-node-picker--compact' : '') +
				(disabled ? ' is-disabled' : ''),
		});

		function nodeSelectable(node) {
			if (!node || node.id == null) {
				return false;
			}
			if (blocked[String(node.id)]) {
				return false;
			}
			if (node.selectable === false) {
				return false;
			}
			return !!selectableFn(node);
		}

		function labelForId(id) {
			id = parseInt(id, 10) || 0;
			if (allowRoot && id === 0) {
				return rootLabel;
			}
			if (!id) {
				return placeholder;
			}
			var n = findNode(opts, id);
			return (n && n.name) || '#' + id;
		}

		function pathForId(id) {
			id = parseInt(id, 10) || 0;
			if (!id) {
				return '';
			}
			if (opts.selectedLabel && String(opts.selectedId) === String(id)) {
				var sl = String(opts.selectedLabel);
				if (sl.indexOf('/') !== -1) {
					return sl;
				}
			}
			return buildPathLabel(opts, id) || labelForId(id);
		}

		function captureTreeScroll() {
			var tree = wrap.querySelector('.wtt-node-picker__tree');
			return tree ? tree.scrollTop : 0;
		}

		function restoreTreeScroll(scrollTop) {
			window.requestAnimationFrame(function () {
				var tree = wrap.querySelector('.wtt-node-picker__tree');
				if (tree) {
					tree.scrollTop = scrollTop || 0;
				}
			});
		}

		function pick(id) {
			if (disabled) {
				return;
			}
			id = parseInt(id, 10) || 0;
			selectedId = id;
			onSelect(id);
			if (wrap.isConnected) {
				rebuild();
			}
		}

		function normalizePickerQuery(raw) {
			return String(raw || '')
				.trim()
				.toLowerCase();
		}

		function nodeMatchesQuery(node, q) {
			if (!q) {
				return true;
			}
			var name = String((node && node.name) || '').toLowerCase();
			if (name.indexOf(q) !== -1) {
				return true;
			}
			if (showTypeInTree && node && node.typeLabel) {
				return String(node.typeLabel).toLowerCase().indexOf(q) !== -1;
			}
			return false;
		}

		function nodeOrDescendantMatches(node, q) {
			if (!q) {
				return true;
			}
			if (nodeMatchesQuery(node, q)) {
				return true;
			}
			var kids = (node && node.children) || [];
			var i;
			for (i = 0; i < kids.length; i++) {
				if (nodeOrDescendantMatches(kids[i], q)) {
					return true;
				}
			}
			return false;
		}

		function rebuild() {
			var scrollTop =
				pendingScrollTop != null ? pendingScrollTop : captureTreeScroll();
			pendingScrollTop = null;
			wrap.innerHTML = '';
			var isOpen = !!openBuckets[expandKey];
			var query = normalizePickerQuery(queryBuckets[expandKey]);

			if (showPickedLabel) {
				var head = el('div', { className: 'wtt-node-picker__head' });
				var toggle = el('button', {
					type: 'button',
					className: 'wtt-node-picker__toggle-open',
					'aria-expanded': isOpen ? 'true' : 'false',
					title: isOpen
						? i18n.nodePickerCollapse || 'Collapse'
						: i18n.nodePickerExpand || 'Expand',
					html:
						'<span class="dashicons dashicons-arrow-' +
						(isOpen ? 'down' : 'right') +
						'"></span>',
					onClick: function (e) {
						e.preventDefault();
						openBuckets[expandKey] = !openBuckets[expandKey];
						rebuild();
					},
				});
				head.appendChild(toggle);
				var pickedName =
					selectedId || (allowRoot && selectedId === 0)
						? labelForId(selectedId)
						: placeholder;
				var pickedPath = selectedId > 0 ? pathForId(selectedId) : pickedName;
				var pickedEl = el('span', { className: 'wtt-node-picker__picked' });
				pickedEl.appendChild(
					el('span', {
						className: 'wtt-node-picker__picked-prefix',
						text: pickedPrefix + ' ',
					})
				);
				var valueEl = el('span', {
					className: 'wtt-node-picker__picked-value',
					text: pickedName,
				});
				pickedEl.appendChild(valueEl);
				head.appendChild(pickedEl);
				if (selectedId > 0) {
					applyAdaptiveNodeLabel(valueEl, pickedName, pickedPath || pickedName);
				} else {
					valueEl.title = pickedName;
				}
				if (allowClear && selectedId && !disabled) {
					head.appendChild(
						el('button', {
							type: 'button',
							className: 'button-link wtt-node-picker__clear',
							text: i18n.nodePickerClear || 'Clear',
							onClick: function (e) {
								e.preventDefault();
								pick(0);
							},
						})
					);
				}
				wrap.appendChild(head);
			}

			if (!isOpen && compact) {
				return;
			}

			var tools = el('div', { className: 'wtt-node-picker__tools' });
			var searchWrap = el('div', { className: 'wtt-node-picker__search' });
			var searchInput = el('input', {
				type: 'search',
				className: 'wtt-node-picker__search-input',
				placeholder: i18n.nodePickerSearchPlaceholder || 'Search nodes…',
				value: queryBuckets[expandKey] || '',
				disabled: disabled ? 'disabled' : undefined,
				'aria-label': i18n.nodePickerSearch || 'Search',
			});
			searchInput.addEventListener('input', function () {
				queryBuckets[expandKey] = searchInput.value;
				rebuild();
				window.requestAnimationFrame(function () {
					var again = wrap.querySelector('.wtt-node-picker__search-input');
					if (again) {
						again.focus();
						var len = again.value.length;
						if (typeof again.setSelectionRange === 'function') {
							again.setSelectionRange(len, len);
						}
					}
				});
			});
			searchWrap.appendChild(searchInput);
			tools.appendChild(searchWrap);
			tools.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.expandAll || 'Expand',
					title: i18n.expandAllHint || 'Expand all nodes',
					disabled: disabled ? 'disabled' : undefined,
					onClick: function (e) {
						e.preventDefault();
						collectExpandableIds(roots).forEach(function (id) {
							expandedMap[id] = true;
						});
						rebuild();
					},
				})
			);
			tools.appendChild(
				el('button', {
					type: 'button',
					className: 'button button-small',
					text: i18n.collapseAll || 'Collapse',
					title: i18n.collapseAllHint || 'Collapse all nodes',
					disabled: disabled ? 'disabled' : undefined,
					onClick: function (e) {
						e.preventDefault();
						Object.keys(expandedMap).forEach(function (key) {
							delete expandedMap[key];
						});
						rebuild();
					},
				})
			);
			wrap.appendChild(tools);

			var treeHost = el('div', { className: 'wtt-node-picker__tree' });
			var list = el('ul', { className: 'wtt-node-picker__list' });

			if (allowRoot) {
				var rootVisible =
					!query ||
					String(rootLabel)
						.toLowerCase()
						.indexOf(query) !== -1;
				if (rootVisible) {
					var rootLi = el('li', { className: 'wtt-node-picker__node' });
					rootLi.appendChild(
						el('button', {
							type: 'button',
							className:
								'wtt-node-picker__item' +
								(selectedId === 0 ? ' is-picked' : '') +
								(currentId === 0 ? ' is-current' : ''),
							text: rootLabel,
							disabled: disabled ? 'disabled' : undefined,
							onClick: function () {
								pick(0);
							},
						})
					);
					list.appendChild(rootLi);
				}
			}

			function appendNodes(nodes, parentUl, depth) {
				(nodes || []).forEach(function (n) {
					if (!n || n.id == null) {
						return;
					}
					if (query && !nodeOrDescendantMatches(n, query)) {
						return;
					}
					var id = n.id;
					var isBlocked = !!blocked[String(id)];
					var canPick = nodeSelectable(n);
					var kids = n.children || [];
					var hasChildren = !!(n.hasChildren || kids.length);
					var matchSelf = !query || nodeMatchesQuery(n, query);
					var matchDesc =
						query && hasChildren
							? kids.some(function (c) {
									return nodeOrDescendantMatches(c, query);
							  })
							: false;
					var isExpanded = query
						? !!(matchDesc || expandedMap[id])
						: !!expandedMap[id];
					if (query && matchDesc) {
						expandedMap[id] = true;
						expandedMap[String(id)] = true;
					}
					var li = el('li', { className: 'wtt-node-picker__node' });
					var row = el('div', {
						className:
							'wtt-node-picker__row' +
							(isBlocked ? ' is-blocked' : '') +
							((parseInt(selectedId, 10) || 0) === (parseInt(id, 10) || 0)
								? ' is-picked'
								: '') +
							(currentId != null &&
							(parseInt(currentId, 10) || 0) === (parseInt(id, 10) || 0)
								? ' is-current'
								: '') +
							(query && matchSelf ? ' is-match' : '') +
							(!canPick && !isBlocked ? ' is-not-selectable' : ''),
						style: 'padding-left:' + depth * 1.1 + 'em',
					});

					if (hasChildren) {
						row.appendChild(
							el('button', {
								type: 'button',
								className: 'wtt-node-picker__twist',
								'aria-expanded': isExpanded ? 'true' : 'false',
								onClick: function (e) {
									e.stopPropagation();
									expandedMap[id] = !expandedMap[id];
									rebuild();
								},
								html:
									'<span class="dashicons dashicons-arrow-' +
									(isExpanded ? 'down' : 'right') +
									'"></span>',
							})
						);
					} else {
						row.appendChild(
							el('span', {
								className: 'wtt-node-picker__twist wtt-node-picker__twist--spacer',
							})
						);
					}

					var label = n.name || String(id);
					if (showTypeInTree && n.typeLabel) {
						label += ' [' + n.typeLabel + ']';
					}
					if (n.attributeCount != null && n.attributeCount > 0) {
						label +=
							' (' +
							n.attributeCount +
							' ' +
							(i18n.attrsLabel || 'attrs') +
							')';
					}
					if (isBlocked) {
						label += ' (' + (i18n.reparentBlocked || 'unavailable') + ')';
					}

					var notPickable = disabled || isBlocked || !canPick;
					var nameTitle = '';
					if (isBlocked) {
						nameTitle = i18n.reparentBlocked || 'unavailable';
					} else if (!canPick && n.isAbstract) {
						nameTitle =
							i18n.nodePickerAbstractHint ||
							'Abstract catalog — expand and choose a child, not this folder.';
					} else if (!canPick) {
						nameTitle =
							i18n.nodePickerNotSelectable ||
							'Not selectable in this chooser.';
					}

					row.appendChild(
						el('button', {
							type: 'button',
							className: 'wtt-node-picker__name',
							text: label,
							title: nameTitle || undefined,
							disabled: notPickable ? 'disabled' : undefined,
							onClick: function () {
								if (notPickable) {
									return;
								}
								pick(id);
							},
						})
					);
					li.appendChild(row);

					if (hasChildren && isExpanded) {
						var childUl = el('ul', { className: 'wtt-node-picker__list' });
						appendNodes(kids, childUl, depth + 1);
						li.appendChild(childUl);
					}
					parentUl.appendChild(li);
				});
			}

			if (!roots.length && !allowRoot) {
				treeHost.appendChild(
					el('p', {
						className: 'wtt-field-hint',
						text: opts.emptyText || i18n.relationsEmpty || 'None',
					})
				);
			} else {
				appendNodes(roots, list, 0);
				if (!list.children.length) {
					treeHost.appendChild(
						el('p', {
							className: 'wtt-field-hint',
							text: query
								? i18n.nodePickerSearchEmpty || 'No matching nodes.'
								: opts.emptyText || i18n.relationsEmpty || 'None',
						})
					);
				} else {
					treeHost.appendChild(list);
				}
			}
			wrap.appendChild(treeHost);
			restoreTreeScroll(scrollTop);
		}

		var focusExpandId = resolvePickerFocusId(
			Object.assign({}, opts, { selectedId: selectedId })
		);
		if (focusExpandId && shouldExpandFocusBranch(opts)) {
			expandAncestorsInMap(roots, focusExpandId, expandedMap, []);
			(opts.lookupTrees || []).forEach(function (tree) {
				expandAncestorsInMap(tree, focusExpandId, expandedMap, []);
			});
			expandedMap[focusExpandId] = true;
			expandedMap[String(focusExpandId)] = true;
		}

		rebuild();
		return wrap;
	}

	function render(opts) {
		opts = opts || {};
		if (treePickerPresentation(opts) === 'popup') {
			return renderPopup(opts);
		}
		return renderInline(opts);
	}

	global.WTTNodePicker = {
		render: render,
		openDialog: openDialog,
		findNode: findNode,
		findNodeInTree: findNodeInTree,
		resolveFocusId: resolvePickerFocusId,
		presentation: treePickerPresentation,
	};
})(typeof window !== 'undefined' ? window : this);
