/**
 * Shared MediaRef rendering (Q65) — admin preview and future frontend page view.
 *
 * API: window.WTTMediaRender
 *   .configure({ i18n })
 *   .parseRef(raw) / .normalizeRef(obj) / .toStore(ref)
 *   .classifyKind(ref)
 *   .kindLabel(kind) / .displayLabel(ref)
 *   .renderSurface(ref, { compact, el })  → HTMLElement
 *   .renderSurfaceHtml(ref, { compact }) → string (SSR-friendly markup)
 *   .renderField(raw|ref, { mode, compact, mediaConfig, onChange, el }) → HTMLElement
 *   .sampleEntries() → [{ kind, ref }, …] for galleries
 *
 * @package WP_Taxonomy_Tree
 */
(function (global) {
	'use strict';

	var i18n = {};

	function configure(opts) {
		opts = opts || {};
		if (opts.i18n && typeof opts.i18n === 'object') {
			i18n = opts.i18n;
		}
	}

	function t(key, fallback) {
		return (i18n && i18n[key]) || fallback;
	}

	function createEl(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'className') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
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

	function escAttr(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function escHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function parseRef(raw) {
		if (raw == null || raw === '') {
			return null;
		}
		if (typeof raw === 'object') {
			return normalizeRef(raw);
		}
		var s = String(raw).trim();
		if (!s) {
			return null;
		}
		if (s.charAt(0) === '{') {
			try {
				return normalizeRef(JSON.parse(s));
			} catch (e) {
				return normalizeRef({ source: 'url', url: s });
			}
		}
		if (/^\d+$/.test(s)) {
			return normalizeRef({ source: 'attachment', attachment_id: parseInt(s, 10) });
		}
		return normalizeRef({ source: 'url', url: s });
	}

	function normalizeRef(obj) {
		if (!obj || typeof obj !== 'object') {
			return null;
		}
		var source = obj.source === 'url' ? 'url' : 'attachment';
		var attachmentId = obj.attachment_id != null ? parseInt(obj.attachment_id, 10) || 0 : 0;
		var url = obj.url != null ? String(obj.url) : '';
		var mime = obj.mime != null ? String(obj.mime) : '';
		var filename = obj.filename != null ? String(obj.filename) : '';
		var thumb = obj.thumb != null ? String(obj.thumb) : '';
		if (!url && !attachmentId && !filename) {
			return null;
		}
		return {
			source: source,
			attachment_id: attachmentId,
			url: url,
			mime: mime,
			filename: filename,
			thumb: thumb,
		};
	}

	function toStore(ref) {
		return ref ? JSON.stringify(ref) : '';
	}

	function mediaProbe(ref) {
		if (!ref) {
			return { mime: '', url: '', file: '', probe: '' };
		}
		var mime = String(ref.mime || '').toLowerCase();
		var url = String(ref.url || '');
		var file = String(ref.filename || '');
		return { mime: mime, url: url, file: file, probe: (url || file).toLowerCase() };
	}

	function mediaExt(probe) {
		var m = String(probe || '')
			.split('?')[0]
			.match(/\.([a-z0-9]+)$/i);
		return m ? m[1].toLowerCase() : '';
	}

	function classifyKind(ref) {
		if (!ref) {
			return '';
		}
		var p = mediaProbe(ref);
		var mime = p.mime;
		var ext = mediaExt(p.probe);
		var knownFileExt =
			/^(pdf|zip|rar|7z|tar|gz|tgz|docx?|xlsx?|pptx?|odt|ods|odp|png|jpe?g|gif|webp|svg|bmp|ico|avif|mp4|webm|ogg|ogv|mov|m4v|avi|mp3|wav|flac|aac|m4a|txt|csv|md|json|xml|html?)$/;

		if (mime.indexOf('image/') === 0 || /^(png|jpe?g|gif|webp|svg|bmp|ico|avif)$/.test(ext)) {
			return 'image';
		}
		if (mime.indexOf('video/') === 0 || /^(mp4|webm|ogv|mov|m4v|avi)$/.test(ext)) {
			return 'video';
		}
		if (mime.indexOf('audio/') === 0 || /^(mp3|wav|flac|aac|m4a|oga|ogg)$/.test(ext)) {
			if (ext === 'ogv') {
				return 'video';
			}
			return 'audio';
		}
		if (mime === 'application/pdf' || ext === 'pdf') {
			return 'pdf';
		}
		if (
			mime === 'application/zip' ||
			mime === 'application/x-zip-compressed' ||
			mime === 'application/x-zip' ||
			mime === 'application/x-rar-compressed' ||
			mime === 'application/vnd.rar' ||
			mime === 'application/x-7z-compressed' ||
			mime === 'application/gzip' ||
			mime === 'application/x-tar' ||
			/^(zip|rar|7z|tar|gz|tgz)$/.test(ext)
		) {
			return 'archive';
		}
		if (
			mime.indexOf('application/msword') === 0 ||
			mime.indexOf('application/vnd.ms-excel') === 0 ||
			mime.indexOf('application/vnd.ms-powerpoint') === 0 ||
			mime.indexOf('application/vnd.openxmlformats-officedocument') === 0 ||
			mime.indexOf('application/vnd.oasis.opendocument') === 0 ||
			/^(docx?|xlsx?|pptx?|odt|ods|odp)$/.test(ext)
		) {
			return 'office';
		}
		if (
			mime.indexOf('text/') === 0 ||
			mime === 'application/json' ||
			mime === 'application/xml' ||
			/^(txt|csv|md|json|xml|html?)$/.test(ext)
		) {
			return 'text';
		}
		if (ref.source === 'url' || (!ref.attachment_id && p.url)) {
			if (!knownFileExt.test(ext)) {
				return 'link';
			}
		}
		return 'file';
	}

	function kindLabel(kind) {
		var map = {
			image: t('mediaKindImage', 'Image'),
			video: t('mediaKindVideo', 'Video'),
			audio: t('mediaKindAudio', 'Audio'),
			pdf: t('mediaKindPdf', 'PDF'),
			archive: t('mediaKindArchive', 'Archive download'),
			office: t('mediaKindOffice', 'Office document'),
			text: t('mediaKindText', 'Text'),
			file: t('mediaKindFile', 'File download'),
			link: t('mediaKindLink', 'Link'),
		};
		return map[kind] || kind || '';
	}

	function displayLabel(ref) {
		if (!ref) {
			return t('mediaEmpty', 'No media');
		}
		if (ref.filename) {
			return ref.filename;
		}
		if (ref.url) {
			return ref.url;
		}
		if (ref.attachment_id) {
			return '#' + ref.attachment_id;
		}
		return t('mediaEmpty', 'No media');
	}

	function actionText(kind, label) {
		var actions = {
			image: label,
			video: t('mediaPlayVideo', 'Play video') + ' — ' + label,
			audio: t('mediaPlayAudio', 'Play audio') + ' — ' + label,
			pdf: t('mediaOpenPdf', 'Open PDF') + ' — ' + label,
			archive: t('mediaDownloadArchive', 'Download archive') + ' — ' + label,
			office: t('mediaOpenOffice', 'Open document') + ' — ' + label,
			text: t('mediaOpenText', 'Open text') + ' — ' + label,
			file: t('mediaDownloadFile', 'Download') + ' — ' + label,
			link: label,
		};
		return actions[kind] || label;
	}

	function isLiveHref(href) {
		return !!(href && href.charAt(0) !== '#' && href.indexOf('data:') !== 0);
	}

	var KINDS = [
		'image',
		'video',
		'audio',
		'pdf',
		'archive',
		'office',
		'text',
		'file',
		'link',
	];

	var SAMPLE_IMAGE =
		'data:image/svg+xml,' +
		encodeURIComponent(
			'<svg xmlns="http://www.w3.org/2000/svg" width="96" height="64" viewBox="0 0 96 64">' +
				'<rect fill="#dcdcde" width="96" height="64"/>' +
				'<text x="48" y="36" text-anchor="middle" fill="#646970" font-size="12" font-family="sans-serif">IMG</text>' +
				'</svg>'
		);

	function sampleEntries() {
		return [
			{
				kind: 'image',
				ref: normalizeRef({
					source: 'attachment',
					url: SAMPLE_IMAGE,
					mime: 'image/png',
					filename: 'beispiel.png',
					thumb: SAMPLE_IMAGE,
				}),
			},
			{
				kind: 'video',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-clip.mp4',
					mime: 'video/mp4',
					filename: 'demo.mp4',
				}),
			},
			{
				kind: 'audio',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-tone.mp3',
					mime: 'audio/mpeg',
					filename: 'ton.mp3',
				}),
			},
			{
				kind: 'pdf',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-datenblatt.pdf',
					mime: 'application/pdf',
					filename: 'datenblatt.pdf',
				}),
			},
			{
				kind: 'archive',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-archive.zip',
					mime: 'application/zip',
					filename: 'gerber.zip',
				}),
			},
			{
				kind: 'office',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-spec.xlsx',
					mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					filename: 'stueckliste.xlsx',
				}),
			},
			{
				kind: 'text',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-notes.txt',
					mime: 'text/plain',
					filename: 'notizen.txt',
				}),
			},
			{
				kind: 'file',
				ref: normalizeRef({
					source: 'attachment',
					url: '#sample-binary.bin',
					mime: 'application/octet-stream',
					filename: 'firmware.bin',
				}),
			},
			{
				kind: 'link',
				ref: normalizeRef({
					source: 'url',
					url: 'https://example.com/docs',
					mime: '',
					filename: '',
				}),
			},
		];
	}

	function renderSurface(ref, opts) {
		opts = opts || {};
		var elFn = typeof opts.el === 'function' ? opts.el : createEl;
		var compact = !!opts.compact;

		if (!ref) {
			return elFn('span', {
				className: 'wtt-media-empty',
				text: t('mediaEmpty', 'No media'),
			});
		}

		var kind = classifyKind(ref);
		var wrap = elFn('div', {
			className:
				'wtt-media-preview' +
				(compact ? ' wtt-media-preview--compact' : '') +
				(kind ? ' wtt-media-preview--' + kind : ''),
		});
		var href = ref.url || '';
		var label = displayLabel(ref);
		var kindBadge = elFn('span', {
			className: 'wtt-media-preview__kind',
			text: kindLabel(kind),
		});
		var live = isLiveHref(href);

		if (kind === 'image' && (ref.thumb || href)) {
			var img = elFn('img', {
				className: 'wtt-media-preview__thumb',
				src: ref.thumb || href,
				alt: label,
			});
			if (live) {
				var imgLink = elFn('a', {
					href: href,
					target: '_blank',
					rel: 'noopener noreferrer',
					className: 'wtt-media-preview__link',
				});
				imgLink.appendChild(img);
				wrap.appendChild(imgLink);
			} else {
				wrap.appendChild(img);
			}
			wrap.appendChild(kindBadge);
			return wrap;
		}

		if (kind === 'video' && live && !compact) {
			var video = elFn('video', {
				className: 'wtt-media-preview__av',
				controls: true,
				preload: 'metadata',
			});
			video.appendChild(elFn('source', { src: href, type: ref.mime || 'video/mp4' }));
			wrap.appendChild(video);
			wrap.appendChild(kindBadge);
			return wrap;
		}

		if (kind === 'audio' && live && !compact) {
			var audio = elFn('audio', {
				className: 'wtt-media-preview__av',
				controls: true,
				preload: 'metadata',
			});
			audio.appendChild(elFn('source', { src: href, type: ref.mime || 'audio/mpeg' }));
			wrap.appendChild(audio);
			wrap.appendChild(kindBadge);
			return wrap;
		}

		var actionClass = 'wtt-media-preview__action wtt-media-preview__action--' + (kind || 'file');
		var txt = actionText(kind, label);
		if (live) {
			var linkAttrs = {
				href: href,
				target: '_blank',
				rel: 'noopener noreferrer',
				className: actionClass,
				text: txt,
			};
			if (kind === 'archive' || kind === 'file') {
				linkAttrs.download = label;
			}
			wrap.appendChild(elFn('a', linkAttrs));
		} else {
			wrap.appendChild(elFn('span', { className: actionClass, text: txt }));
		}
		wrap.appendChild(kindBadge);
		return wrap;
	}

	/**
	 * HTML string for PHP/SSR and non-DOM contexts (same classes as DOM renderer).
	 */
	function renderSurfaceHtml(ref, opts) {
		opts = opts || {};
		var compact = !!opts.compact;
		if (!ref) {
			return '<span class="wtt-media-empty">' + escHtml(t('mediaEmpty', 'No media')) + '</span>';
		}
		var kind = classifyKind(ref);
		var href = ref.url || '';
		var label = displayLabel(ref);
		var live = isLiveHref(href);
		var cls =
			'wtt-media-preview' +
			(compact ? ' wtt-media-preview--compact' : '') +
			(kind ? ' wtt-media-preview--' + kind : '');
		var badge =
			'<span class="wtt-media-preview__kind">' + escHtml(kindLabel(kind)) + '</span>';

		if (kind === 'image' && (ref.thumb || href)) {
			var src = ref.thumb || href;
			var img =
				'<img class="wtt-media-preview__thumb" src="' +
				escAttr(src) +
				'" alt="' +
				escAttr(label) +
				'" />';
			if (live) {
				img =
					'<a class="wtt-media-preview__link" href="' +
					escAttr(href) +
					'" target="_blank" rel="noopener noreferrer">' +
					img +
					'</a>';
			}
			return '<div class="' + cls + '">' + img + badge + '</div>';
		}

		if (kind === 'video' && live && !compact) {
			return (
				'<div class="' +
				cls +
				'"><video class="wtt-media-preview__av" controls preload="metadata">' +
				'<source src="' +
				escAttr(href) +
				'" type="' +
				escAttr(ref.mime || 'video/mp4') +
				'" /></video>' +
				badge +
				'</div>'
			);
		}

		if (kind === 'audio' && live && !compact) {
			return (
				'<div class="' +
				cls +
				'"><audio class="wtt-media-preview__av" controls preload="metadata">' +
				'<source src="' +
				escAttr(href) +
				'" type="' +
				escAttr(ref.mime || 'audio/mpeg') +
				'" /></audio>' +
				badge +
				'</div>'
			);
		}

		var actionClass = 'wtt-media-preview__action wtt-media-preview__action--' + (kind || 'file');
		var txt = actionText(kind, label);
		var body;
		if (live) {
			var dl = kind === 'archive' || kind === 'file' ? ' download="' + escAttr(label) + '"' : '';
			body =
				'<a class="' +
				actionClass +
				'" href="' +
				escAttr(href) +
				'" target="_blank" rel="noopener noreferrer"' +
				dl +
				'>' +
				escHtml(txt) +
				'</a>';
		} else {
			body = '<span class="' + actionClass + '">' + escHtml(txt) + '</span>';
		}
		return '<div class="' + cls + '">' + body + badge + '</div>';
	}

	/**
	 * @param {object|null|undefined} cfg
	 * @return {{ allowUpload: boolean, allowUrl: boolean, allowedKinds: string[] }}
	 */
	function normalizeFieldConfig(cfg) {
		var known = {};
		KINDS.forEach(function (k) {
			known[k] = true;
		});
		var kinds = [];
		var seen = {};
		(Array.isArray(cfg && cfg.allowedKinds) ? cfg.allowedKinds : []).forEach(function (kind) {
			var key = String(kind || '')
				.trim()
				.toLowerCase();
			if (!key || !known[key] || seen[key]) {
				return;
			}
			seen[key] = true;
			kinds.push(key);
		});
		return {
			allowUpload: !cfg || cfg.allowUpload !== false,
			allowUrl: !!(cfg && cfg.allowUrl),
			allowedKinds: kinds,
		};
	}

	function libraryTypeForKinds(kinds) {
		var list = Array.isArray(kinds) ? kinds : [];
		if (!list.length) {
			return null;
		}
		var map = {
			image: 'image',
			video: 'video',
			audio: 'audio',
			pdf: 'application/pdf',
			archive: 'application',
			office: 'application',
			text: 'text',
			file: '',
			link: '',
		};
		var types = [];
		var seen = {};
		list.forEach(function (kind) {
			var t = map[kind];
			if (t && !seen[t]) {
				seen[t] = true;
				types.push(t);
			}
		});
		if (!types.length) {
			return null;
		}
		return types.length === 1 ? types[0] : types;
	}

	function isKindAllowed(cfg, kind) {
		var allowed = (cfg && cfg.allowedKinds) || [];
		if (!allowed.length) {
			/* Empty allowlist → all kinds (instance fill; type preview may still require kinds). */
			return true;
		}
		return allowed.indexOf(String(kind || '')) !== -1;
	}

	function openMediaLibrary(onPicked, allowedKinds) {
		if (!global.wp || !global.wp.media) {
			return;
		}
		var kinds = Array.isArray(allowedKinds) ? allowedKinds : [];
		var frameOpts = {
			title: t('mediaFrameTitle', 'Select media'),
			button: { text: t('mediaFrameButton', 'Use this file') },
			multiple: false,
		};
		var libraryType = libraryTypeForKinds(kinds);
		if (libraryType) {
			frameOpts.library = { type: libraryType };
		}
		var frame = global.wp.media(frameOpts);
		frame.on('select', function () {
			var att = frame.state().get('selection').first();
			if (!att) {
				return;
			}
			var data = att.toJSON();
			var thumb = '';
			if (data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url) {
				thumb = data.sizes.thumbnail.url;
			} else if (data.sizes && data.sizes.medium && data.sizes.medium.url) {
				thumb = data.sizes.medium.url;
			}
			var picked = normalizeRef({
				source: 'attachment',
				attachment_id: data.id,
				url: data.url || '',
				mime: data.mime || data.mime_type || '',
				filename: data.filename || data.title || '',
				thumb: thumb || data.url || '',
			});
			if (kinds.length && picked) {
				var kind = classifyKind(picked);
				if (!isKindAllowed({ allowedKinds: kinds }, kind)) {
					return;
				}
			}
			onPicked(picked);
		});
		frame.open();
	}

	/**
	 * Editable / display media control — never dumps raw MediaRef JSON into a text input.
	 *
	 * @param {string|object|null} rawOrRef Store JSON, url, id, or ref object.
	 * @param {{
	 *   mode?: 'edit'|'display',
	 *   compact?: boolean,
	 *   mediaConfig?: object,
	 *   onChange?: function(string),
	 *   el?: function
	 * }} [opts]
	 * @return {HTMLElement}
	 */
	function renderField(rawOrRef, opts) {
		opts = opts || {};
		var elFn = typeof opts.el === 'function' ? opts.el : createEl;
		var mode = opts.mode === 'display' ? 'display' : 'edit';
		var compact = !!opts.compact;
		var cfg = normalizeFieldConfig(opts.mediaConfig);
		var onChange =
			typeof opts.onChange === 'function' ? opts.onChange : null;
		var ref =
			rawOrRef == null || typeof rawOrRef === 'string'
				? parseRef(rawOrRef)
				: normalizeRef(rawOrRef);

		if (ref) {
			var refKind = classifyKind(ref);
			if (refKind && !isKindAllowed(cfg, refKind)) {
				ref = null;
			}
		}

		if (mode === 'display') {
			return renderSurface(ref, { compact: compact, el: elFn });
		}

		var wrap = elFn('div', {
			className:
				'wtt-media-field' + (compact ? ' wtt-media-field--compact' : ''),
		});

		function emit(nextRef) {
			ref = nextRef;
			if (onChange) {
				onChange(toStore(nextRef));
			}
			rebuild();
		}

		function rebuild() {
			while (wrap.firstChild) {
				wrap.removeChild(wrap.firstChild);
			}
			wrap.appendChild(renderSurface(ref, { compact: compact, el: elFn }));

			var actions = elFn('div', { className: 'wtt-media-field__actions' });
			if (cfg.allowUpload) {
				var pickBtn = elFn('button', {
					type: 'button',
					className: 'button button-small',
					text: ref
						? t('mediaChange', 'Change')
						: t('mediaSelect', 'Select media'),
				});
				pickBtn.addEventListener('click', function (e) {
					e.preventDefault();
					openMediaLibrary(function (picked) {
						if (!picked) {
							return;
						}
						emit(picked);
					}, cfg.allowedKinds);
				});
				actions.appendChild(pickBtn);
			}
			if (ref) {
				var clearBtn = elFn('button', {
					type: 'button',
					className: 'button button-small',
					text: t('mediaClear', 'Clear'),
				});
				clearBtn.addEventListener('click', function (e) {
					e.preventDefault();
					emit(null);
				});
				actions.appendChild(clearBtn);
			}
			wrap.appendChild(actions);

			if (cfg.allowUrl) {
				var urlInput = elFn('input', {
					type: 'url',
					className: 'wtt-preview-input wtt-media-field__url',
					placeholder: t('mediaUrlPlaceholder', 'https://…'),
					value:
						ref && ref.source === 'url'
							? ref.url
							: ref && ref.url && !cfg.allowUpload
								? ref.url
								: '',
				});
				urlInput.addEventListener('change', function (e) {
					var url = String(e.target.value || '').trim();
					if (!url) {
						emit(null);
						return;
					}
					var urlRef = normalizeRef({
						source: 'url',
						attachment_id: 0,
						url: url,
						mime: '',
						filename: '',
						thumb: '',
					});
					var urlKind = classifyKind(urlRef);
					if (urlKind && !isKindAllowed(cfg, urlKind)) {
						return;
					}
					emit(urlRef);
				});
				wrap.appendChild(urlInput);
			}

			if (!cfg.allowUpload && !cfg.allowUrl) {
				wrap.appendChild(
					elFn('span', {
						className: 'description',
						text: t('mediaEmpty', 'No media'),
					})
				);
			}
		}

		rebuild();
		return wrap;
	}

	global.WTTMediaRender = {
		configure: configure,
		parseRef: parseRef,
		normalizeRef: normalizeRef,
		toStore: toStore,
		classifyKind: classifyKind,
		kindLabel: kindLabel,
		displayLabel: displayLabel,
		actionText: actionText,
		isLiveHref: isLiveHref,
		sampleEntries: sampleEntries,
		renderSurface: renderSurface,
		renderSurfaceHtml: renderSurfaceHtml,
		renderField: renderField,
		SAMPLE_IMAGE: SAMPLE_IMAGE,
		KINDS: KINDS,
	};
})(typeof window !== 'undefined' ? window : this);
