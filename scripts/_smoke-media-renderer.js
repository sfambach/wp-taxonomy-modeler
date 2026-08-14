/**
 * Smoke: MediaRenderer is registered and paints edit/display via WTTMediaRender.
 */
/* eslint-disable no-console */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var root = path.join(__dirname, '..');
var sandbox = {
	console: console,
	window: {},
	document: {
		createElement: function (tag) {
			return {
				tagName: String(tag).toUpperCase(),
				className: '',
				textContent: '',
				children: [],
				style: {},
				appendChild: function (c) {
					this.children.push(c);
					return c;
				},
				setAttribute: function () {},
				addEventListener: function () {},
				removeChild: function () {},
			};
		},
		createTextNode: function (t) {
			return { textContent: t };
		},
	},
};
sandbox.global = sandbox;
sandbox.window = sandbox;

function load(rel) {
	var code = fs.readFileSync(path.join(root, rel), 'utf8');
	vm.runInNewContext(code, sandbox, { filename: rel });
}

load('assets/js/wtt-media-render.js');
load('assets/js/wtt-node-render.js');

var NR = sandbox.WTTNodeRender || sandbox.window.WTTNodeRender;
if (!NR || !NR.MediaRenderer) {
	console.error('FAIL: MediaRenderer missing');
	process.exit(1);
}
if (!NR.isRegisteredType('media')) {
	console.error('FAIL: media not registered type');
	process.exit(1);
}
var byId = NR.Registry.getById('media');
if (!byId || byId.id !== 'media') {
	console.error('FAIL: Registry.getById(media)');
	process.exit(1);
}

var node = {
	name: 'media',
	typeKey: 'media',
	type: { name: 'media' },
	mediaConfig: {
		allowUpload: true,
		allowUrl: false,
		allowedKinds: ['image'],
	},
	preferredRender: 'MediaRenderer',
};

var edit = NR.Registry.renderContent(node, {
	name: 'form',
	mode: 'edit',
	bare: true,
	value: '',
	onInput: function () {},
});
var display = NR.Registry.renderContent(node, {
	name: 'form',
	mode: 'display',
	bare: true,
	value: '',
});

if (!edit || !display) {
	console.error('FAIL: renderContent returned empty', !!edit, !!display);
	process.exit(1);
}

var compat = NR.Registry.listCompatible(node, { name: 'form', mode: 'edit' });
var ids = (compat || []).map(function (o) {
	return o.id;
});
if (ids.indexOf('media') === -1) {
	console.error('FAIL: listCompatible missing media', ids);
	process.exit(1);
}

console.log('OK media renderer edit/display + preferred compatible');
process.exit(0);
