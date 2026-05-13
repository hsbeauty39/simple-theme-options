/**
 * Rebuild instructions.html → WooCommerce-style 3-column (nav | prose | PHP only).
 * Run: node scripts/rebuild-instructions-layout.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const srcPath = path.join(root, 'instructions.html');
const cssPath = path.join(__dirname, 'instructions-layout-wc.css');
const previewTemplatesPath = path.join(__dirname, 'sto-instructions-preview-templates.html');
const previewTemplatesHtml = fs.readFileSync(previewTemplatesPath, 'utf8');

let html = fs.readFileSync(srcPath, 'utf8');

/* Remove legacy preview templates + viewer script (only present in pre-WC source HTML). */
const tplStart = html.indexOf('<div id="instr-preview-templates"');
if (tplStart === -1) {
	console.error(
		'Rebuild expects a legacy instructions.html that still contains <div id="instr-preview-templates"> and <pre data-instr-preview="KEY">…</pre> inside <main>. The checked-in WC layout cannot be used as rebuild input — restore a legacy copy from git, or maintain instructions.html and scripts/sto-instructions-preview-templates.html directly.',
	);
	process.exit(1);
}
const marker = "Array.prototype.forEach.call(document.querySelectorAll('main pre[data-instr-preview]'), wrapPre);";
const markerPos = html.indexOf(marker, tplStart);
if (markerPos === -1) {
	console.error('Missing viewer script marker');
	process.exit(1);
}
const scriptClose = html.indexOf('</script>', markerPos);
if (scriptClose === -1) {
	console.error('Missing </script> after viewer');
	process.exit(1);
}
html = html.slice(0, tplStart) + html.slice(scriptClose + '</script>'.length);

const preRe =
	/<pre\s+data-instr-preview="([^"]+)"[^>]*>\s*<code>([\s\S]*?)<\/code>\s*<\/pre>/g;
const snip = [];
let mm;
while ((mm = preRe.exec(html)) !== null) {
	snip.push({ key: mm[1], code: mm[2] });
}

html = html.replace(preRe, (_, key) => {
	return `<p class="sto-docs-php-jump"><a class="sto-docs-php-jump__a" href="#php-${key}">PHP →</a></p>`;
});

const mainOpen = html.indexOf('<main>');
const mainClose = html.lastIndexOf('</main>');
if (mainOpen === -1 || mainClose === -1 || mainClose < mainOpen) {
	console.error('Could not find <main>…</main>');
	process.exit(1);
}
let prose = html.slice(mainOpen + 6, mainClose);

/**
 * Last <h2|h3|h4>…</h> in slice (for attaching anchors / back-links).
 */
function lastHeadingMatch(slice) {
	const re = /<h([234])([^>]*)>([\s\S]*?)<\/h\1>/g;
	let m;
	let last = null;
	while ((m = re.exec(slice)) !== null) last = m;
	return last;
}

const anchorByKey = {};
const stub = (key) => `<p id="doc-${key}" class="sto-docs-anchor"></p>\n\t\t\t`;

for (const { key } of snip) {
	const jump = `<p class="sto-docs-php-jump"><a class="sto-docs-php-jump__a" href="#php-${key}">PHP →</a></p>`;
	const idx = prose.indexOf(jump);
	if (idx === -1) {
		console.warn('Jump not found for key', key);
		continue;
	}
	const before = prose.slice(0, idx);
	const after = prose.slice(idx);
	const last = lastHeadingMatch(before);
	if (!last) {
		prose = before + stub(key) + after;
		anchorByKey[key] = `doc-${key}`;
		continue;
	}
	const full = last[0];
	const tag = last[1];
	const attrs = last[2] || '';
	const lastEnd = before.lastIndexOf(full) + full.length;
	const gap = idx - lastEnd;
	const hasId = /\sid=["']([^"']+)["']/.test(attrs);
	const idM = attrs.match(/\sid=["']([^"']+)["']/);
	const near = gap >= 0 && gap < 900;

	if (hasId && idM && near) {
		anchorByKey[key] = idM[1];
		prose = before + after;
		continue;
	}
	/* Never attach doc-* ids to h2 — use stub so section titles stay clean. */
	if (!hasId && near && tag !== '2') {
		const replaced = full.replace(
			/^<(h[234])([^>]*)>/,
			`<$1$2 id="doc-${key}">`,
		);
		const pos = before.lastIndexOf(full);
		prose = before.slice(0, pos) + replaced + after;
		anchorByKey[key] = `doc-${key}`;
		continue;
	}
	prose = before + stub(key) + after;
	anchorByKey[key] = `doc-${key}`;
}

prose = prose.replace(
	/<div class="box box--note">\s*<strong>About previews<\/strong>[\s\S]*?<\/div>/,
	'<div class="box box--note"><strong>PHP column</strong> Each topic that includes a sample links with <strong>PHP →</strong> to the matching block on the right. For real admin chrome (Select2, Iris, spacing), run the plugin in WordPress and open <strong>Theme Settings</strong>.</div>',
);

const newCss = fs.readFileSync(cssPath, 'utf8');
const styleStart = html.indexOf('<style>');
const styleEnd = html.indexOf('</style>') + 8;
const bodyStart = html.indexOf('<body>');
if (styleStart === -1 || bodyStart === -1) {
	console.error('Missing <style> or <body>');
	process.exit(1);
}

let headThroughBody = html.slice(0, styleStart) + '<style>\n' + newCss + '\n</style>' + html.slice(styleEnd, bodyStart + 6);

headThroughBody = headThroughBody.replace(
	/<html(\s[^>]*)>/i,
	function (full, inner) {
		if (/\bsto-docs-root\b/.test(inner)) return full;
		if (/\bclass="/i.test(inner)) {
			return '<html' + inner.replace(/\bclass="/i, 'class="sto-docs-root ') + '>';
		}
		return '<html class="sto-docs-root"' + inner + '>';
	},
);

headThroughBody = headThroughBody.replace(
	/<title>[^<]*<\/title>/,
	'<title>Simple Theme Options — Developer guide (PHP reference)</title>\n' +
		'\t<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin />\n' +
		'\t<link rel="preconnect" href="https://fonts.googleapis.com" />\n' +
		'\t<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />\n' +
		'\t<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />\n',
);

const phpBlocks = snip
	.map(({ key, code }) => {
		const anchor = anchorByKey[key] || `doc-${key}`;
		const label = key.replace(/_/g, ' ');
		return (
			'\t\t\t\t<section class="sto-php-snippet" id="php-' +
			key +
			'" aria-labelledby="php-h-' +
			key +
			'">\n' +
			'\t\t\t\t\t<div class="sto-php-snippet__bar" id="php-h-' +
			key +
			'">\n' +
			'\t\t\t\t\t\t<span class="sto-php-snippet__pill">PHP</span>\n' +
			'\t\t\t\t\t\t<span class="sto-php-snippet__label">' +
			label +
			'</span>\n' +
			'\t\t\t\t\t\t<a class="sto-php-snippet__back" href="#' +
			anchor +
			'">↑ Docs</a>\n' +
			'\t\t\t\t\t</div>\n' +
			'\t\t\t\t\t<pre class="sto-php-snippet__pre"><code class="language-php">' +
			code +
			'</code></pre>\n' +
			'\t\t\t\t</section>'
		);
	})
	.join('\n');

	const introBlock = `
					<section class="sto-docs-section sto-docs-section--intro" id="intro">
						<h2>Introduction</h2>
						<p><strong>Simple Theme Options</strong> registers a <strong>Theme Settings</strong> WordPress admin experience: nested sidebar, SPA-style section switching, one options form, responsive controls, composite fields (Tabs, Accordion, Group), conditional <code>required</code> visibility, and header quick search.</p>
						<p>This guide is for <strong>developers, agencies, and product teams</strong> who ship client sites or bundle STO in a commercial theme. Samples use the <code>SimpleThemeOptions\\</code> namespace and text domain <code>'simple-theme-options'</code>.</p>
					</section>
					<section class="sto-docs-section" id="commercial">
						<h2>License &amp; resale</h2>
						<p>GPL v2 or later applies to this plugin’s PHP. When you resell or bundle, include license terms for your layer (theme, config UI, support). Keep GPL headers in shipped PHP. Point customers to this file and to <code>README.md</code> for integration depth.</p>
						<ul>
							<li><strong>White-label:</strong> Replace <code>Sample\\</code> menu and sections with your brand; adjust menu title and icon in <code>Menu::register()</code>.</li>
							<li><strong>Support scope:</strong> In your own README, separate “STO core behaviour” from “your custom field classes” so buyers know what you maintain.</li>
						</ul>
					</section>
					<section class="sto-docs-section" id="requirements">
						<h2>Requirements</h2>
						<ul>
							<li>WordPress <strong>5.8+</strong></li>
							<li>PHP <strong>7.4+</strong></li>
							<li><code>manage_options</code> for Theme Settings</li>
						</ul>
					</section>
					<section class="sto-docs-section" id="quickstart">
						<h2>Quick start</h2>
						<ol>
							<li>Activate the plugin.</li>
							<li>Boot your field registrar <strong>before</strong> <code>Sections::register()</code> (see §1.1 and PHP column).</li>
							<li>Register menu + sections, then fields with <code>section_slug</code> equal to the <strong>leaf</strong> slug.</li>
							<li>Open <strong>Theme Settings</strong> in wp-admin (<code>admin.php?page=theme-settings&amp;section=…</code>).</li>
						</ol>
					</section>
					<section class="sto-docs-section" id="customize">
						<h2>Customize</h2>
						<ul>
							<li><strong>Navigation:</strong> <code>add_section</code> / <code>add_sub_section</code> — §2.</li>
							<li><strong>New field types:</strong> Singleton + <code>register()</code>, render, sanitize, and (when adding a type) document <code>sto_render_section_content</code> priority — §5 and <code>README.md</code>.</li>
							<li><strong>Styling:</strong> Scoped under <code>.sto-option-panel-wrapper</code> — see plugin <code>assets/admin/css</code>.</li>
						</ul>
					</section>
					<section class="sto-docs-section" id="architecture">
						<h2>Architecture</h2>
						<dl class="sto-docs-dl">
							<dt><code>Plugin.php</code></dt><dd>Loads assets, Ajax, sample admin.</dd>
							<dt><code>Options\\Menu</code></dt><dd>Admin page, panels, save, redirects.</dd>
							<dt><code>Sample\\Sections</code></dt><dd>Example sidebar tree (replace in your product).</dd>
							<dt><code>Fields\\*</code></dt><dd>One class per field family; hook priorities in §8.</dd>
						</dl>
					</section>
`;

	const nav = `	<div class="sto-docs">
		<header class="sto-docs-topbar">
			<div class="sto-docs-brand">
				<span class="sto-docs-brand__logo" aria-hidden="true">STO</span>
				<div>
					<strong class="sto-docs-brand__title">Simple Theme Options</strong>
					<span class="sto-docs-brand__sub">Developer guide · PHP reference</span>
				</div>
			</div>
			<p class="sto-docs-topbar__note">Centre: concepts and tables. Right: <strong>PHP only</strong> (no cURL or other languages). Open Theme Settings on a dev site to see real controls.</p>
		</header>
		<div class="sto-docs-grid">
			<nav class="sto-docs-nav" id="sto-docs-nav" aria-label="Documentation">
				<div class="sto-docs-nav__inner">
					<p class="sto-docs-nav__kicker">Guide</p>
					<ul class="sto-docs-nav__list">
						<li><a href="#intro">Introduction</a></li>
						<li><a href="#commercial">License &amp; resale</a></li>
						<li><a href="#requirements">Requirements</a></li>
						<li><a href="#quickstart">Quick start</a></li>
						<li><a href="#customize">Customize</a></li>
						<li><a href="#architecture">Architecture</a></li>
					</ul>
					<p class="sto-docs-nav__kicker">Reference</p>
					<ul class="sto-docs-nav__list">
						<li><a href="#overview">1. Overview</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-boot_wiring">1.1 Sections + fields</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-field_skeleton">1.2 Field class</a></li>
						<li><a href="#nav-menu">2. Sections &amp; nav</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-nav_sections">2.2 Sections.php</a></li>
						<li><a href="#responsive">3. Responsive</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-responsive_select">3.5 Sample</a></li>
						<li><a href="#required">4. required</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-required_and">4.1 AND</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-required_or">4.2 OR</a></li>
						<li><a href="#save">5. Save &amp; types</a></li>
						<li><a href="#fields">6. Field types</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-use_imports">use imports</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-min-class">6.0 Class file</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-select">6.1 Select</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-dynamic">6.2 DynamicObject</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-input">6.3 Input</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-switcher">6.4 Switcher</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-color">6.5 Color</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-bg">6.6 Background</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-linkc">6.7 Link color</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-border">6.8 Border</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-range">6.9 Range</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-typo">6.10 Typography</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-img">6.11 Image select</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-btng">6.12 Button group</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-code">6.13 Code editor</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-tabs">6.14 Tabs</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-acc">6.15 Accordion</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-group">6.16 Group</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-group_inner">6.16.1 Infer select</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-group-tabs">6.17 + Tabs</a></li>
						<li class="sto-docs-nav__nest"><a href="#f-group-acc">6.18 + Accordion</a></li>
						<li><a href="#hooks">7. Hooks</a></li>
						<li class="sto-docs-nav__nest"><a href="#doc-hook_intro">Sample hook</a></li>
						<li><a href="#docs">8. Checklist</a></li>
					</ul>
				</div>
			</nav>
			<div class="sto-docs-main">
				<div class="sto-docs-prose-scroll" id="sto-docs-prose-scroll">
					<main class="sto-docs-prose">
${introBlock}
${prose}
					</main>
				</div>
				<aside class="sto-docs-php" aria-label="PHP samples">
					<div class="sto-docs-php-scroll">
						<div class="sto-docs-php-head">
							<span class="sto-docs-php-head__pill">PHP</span>
						</div>
${phpBlocks}
${previewTemplatesHtml}
					</div>
				</aside>
			</div>
			<footer class="sto-docs-footer">
				Simple Theme Options — static <code>instructions.html</code>. Edge cases: <code>README.md</code> + <code>simple-theme-options.mdc</code>.
			</footer>
		</div>
	</div>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js" crossorigin="anonymous" data-manual></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-clike.min.js" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js" crossorigin="anonymous"></script>
	<script>
(function () {
	var prose = document.getElementById('sto-docs-prose-scroll');
	var nav = document.getElementById('sto-docs-nav');
	var phpScroll = document.querySelector('.sto-docs-php-scroll');
	if (!prose || !nav) return;

	var links = [].slice.call(nav.querySelectorAll('a[href^="#"]'));
	var phpSnippets = phpScroll ? [].slice.call(phpScroll.querySelectorAll('.sto-php-snippet')) : [];
	var jumpLinks = [].slice.call(prose.querySelectorAll('.sto-docs-php-jump a[href^="#php-"]'));

	var lastNavId = '';
	var lastPhpKey = '';
	var phpUserUntil = 0;
	var suppressProseScrollSync = false;
	var phpScrollProgrammatic = false;
	var lastPhpDriveAt = 0;

	if (phpScroll) {
		phpScroll.addEventListener(
			'wheel',
			function () {
				phpUserUntil = Date.now() + 900;
			},
			{ passive: true }
		);
		phpScroll.addEventListener(
			'touchstart',
			function () {
				phpUserUntil = Date.now() + 1400;
			},
			{ passive: true }
		);
		phpScroll.addEventListener(
			'scroll',
			function () {
				if (phpScrollProgrammatic) return;
				if (phpScrollTicking) return;
				phpScrollTicking = true;
				window.requestAnimationFrame(function () {
					phpScrollTicking = false;
					syncFromPhp();
				});
			},
			{ passive: true }
		);
	}
	prose.addEventListener(
		'wheel',
		function () {
			phpUserUntil = 0;
		},
		{ passive: true }
	);

	function setActive(hash) {
		var id = (hash || '').replace(/^#/, '');
		var changed = id !== lastNavId;
		lastNavId = id;
		links.forEach(function (a) {
			a.classList.toggle('is-active', a.getAttribute('href') === '#' + id);
		});
		if (changed && id) {
			var activeNavA = nav.querySelector('a.is-active');
			if (activeNavA && nav.scrollHeight > nav.clientHeight) {
				activeNavA.scrollIntoView({ block: 'nearest', inline: 'nearest' });
			}
		}
	}

	function pickRefY() {
		var root = prose.getBoundingClientRect();
		return root.top + Math.min(root.height * 0.26, 200);
	}

	function getActivePhpKey() {
		if (!jumpLinks.length) return null;
		var y = pickRefY();
		var key = null;
		for (var i = 0; i < jumpLinks.length; i++) {
			var r = jumpLinks[i].getBoundingClientRect();
			if (r.top <= y) {
				key = (jumpLinks[i].getAttribute('href') || '').replace(/^#php-/, '');
			}
		}
		if (!key) {
			key = (jumpLinks[0].getAttribute('href') || '').replace(/^#php-/, '');
		}
		return key;
	}

	function getActiveNavId() {
		var y = pickRefY();
		var best = null;
		var bestTop = -1e9;
		for (var i = 0; i < links.length; i++) {
			var href = links[i].getAttribute('href') || '';
			if (href.charAt(0) !== '#') continue;
			var el = document.getElementById(href.slice(1));
			if (!el || !prose.contains(el)) continue;
			var r = el.getBoundingClientRect();
			if (r.top <= y && r.top > bestTop) {
				bestTop = r.top;
				best = href.slice(1);
			}
		}
		if (!best) {
			var intro = document.getElementById('intro');
			if (intro && prose.contains(intro)) {
				best = 'intro';
			}
		}
		return best;
	}

	function pickRefYPhp() {
		var root = phpScroll.getBoundingClientRect();
		return root.top + Math.min(root.height * 0.26, 200);
	}

	function getActivePhpKeyFromPhpColumn() {
		if (!phpScroll || !phpSnippets.length) return null;
		var y = pickRefYPhp();
		var key = null;
		for (var i = 0; i < phpSnippets.length; i++) {
			var r = phpSnippets[i].getBoundingClientRect();
			if (r.top <= y) {
				key = (phpSnippets[i].id || '').replace(/^php-/, '');
			}
		}
		if (!key) {
			key = (phpSnippets[0].id || '').replace(/^php-/, '');
		}
		return key;
	}

	function scrollProseToPhpKey(key, smooth) {
		if (!key || !prose) return;
		var jump = prose.querySelector('a.sto-docs-php-jump__a[href="#php-' + key + '"]');
		if (!jump) return;
		var targetY = pickRefY();
		var delta = jump.getBoundingClientRect().top - targetY;
		if (Math.abs(delta) < 4) return;
		suppressProseScrollSync = true;
		var behavior = smooth === true ? 'smooth' : 'auto';
		prose.scrollBy({ top: delta, behavior: behavior });
		lastPhpKey = key;
		phpSnippets.forEach(function (s) {
			s.classList.toggle('is-sto-php-synced', s.id === 'php-' + key);
		});
		window.setTimeout(function () {
			suppressProseScrollSync = false;
		}, 50);
		window.requestAnimationFrame(function () {
			var navId = getActiveNavId();
			if (navId) setActive('#' + navId);
		});
	}

	function scrollPhpToKey(key, smooth) {
		if (!phpScroll || !key) return;
		if (Date.now() < phpUserUntil) return;
		var head = phpScroll.querySelector('.sto-docs-php-head');
		var headH = head ? head.offsetHeight : 0;
		var el = document.getElementById('php-' + key);
		if (!el) return;
		var top = el.offsetTop - headH - 6;
		var behavior = smooth === true ? 'smooth' : 'auto';
		var progMs = smooth === true ? 480 : 0;
		phpScrollProgrammatic = true;
		if (key !== lastPhpKey || smooth) {
			lastPhpKey = key;
			phpScroll.scrollTo({ top: Math.max(0, top), behavior: behavior });
		}
		phpSnippets.forEach(function (s) {
			s.classList.toggle('is-sto-php-synced', s.id === 'php-' + key);
		});
		window.setTimeout(function () {
			phpScrollProgrammatic = false;
		}, progMs);
	}

	var scrollTicking = false;
	function syncFromProse() {
		var navId = getActiveNavId();
		if (navId) setActive('#' + navId);
		if (Date.now() - lastPhpDriveAt < 100) return;
		var k = getActivePhpKey();
		scrollPhpToKey(k, false);
	}

	var phpScrollTicking = false;
	function syncFromPhp() {
		if (phpScrollProgrammatic) return;
		lastPhpDriveAt = Date.now();
		var key = getActivePhpKeyFromPhpColumn();
		if (!key) return;
		scrollProseToPhpKey(key, false);
	}

	prose.addEventListener(
		'scroll',
		function () {
			if (suppressProseScrollSync) {
				suppressProseScrollSync = false;
				return;
			}
			if (scrollTicking) return;
			scrollTicking = true;
			window.requestAnimationFrame(function () {
				scrollTicking = false;
				syncFromProse();
			});
		},
		{ passive: true }
	);
	window.addEventListener('resize', syncFromProse, { passive: true });

	links.forEach(function (a) {
		a.addEventListener('click', function (e) {
			var href = a.getAttribute('href');
			if (!href || href === '#') return;
			var el = document.getElementById(href.slice(1));
			if (el && prose.contains(el)) {
				e.preventDefault();
				el.scrollIntoView({ behavior: 'smooth', block: 'start' });
				setActive(href);
				history.replaceState(null, '', href);
				window.setTimeout(function () {
					syncFromProse();
					scrollPhpToKey(getActivePhpKey(), true);
				}, 380);
			}
		});
	});

	var COPY_ICON_SVG =
		'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';

	function showCopiedToast() {
		var t = document.getElementById('sto-docs-toast');
		if (!t) {
			t = document.createElement('div');
			t.id = 'sto-docs-toast';
			t.setAttribute('role', 'status');
			t.setAttribute('aria-live', 'polite');
			document.body.appendChild(t);
		}
		t.textContent = 'Copied';
		t.classList.add('is-visible');
		clearTimeout(t._hideTid);
		t._hideTid = setTimeout(function () {
			t.classList.remove('is-visible');
		}, 1600);
	}

	function copyTextToClipboard(text, onDone) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(onDone).catch(function () {
				fallbackCopyText(text, onDone);
			});
			return;
		}
		fallbackCopyText(text, onDone);
	}

	function fallbackCopyText(text, onDone) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
		} catch (e) {}
		document.body.removeChild(ta);
		if (onDone) onDone();
	}

	function initSnippetChrome() {
		var tplRoot = document.getElementById('sto-snippet-preview-templates');
		var snippets = document.querySelectorAll('.sto-docs-php-scroll .sto-php-snippet');
		snippets.forEach(function (sec) {
			var id = sec.id || '';
			var m = /^php-(.+)$/.exec(id);
			if (!m) return;
			var key = m[1];
			var bar = sec.querySelector('.sto-php-snippet__bar');
			var pre = sec.querySelector('pre.sto-php-snippet__pre');
			if (!bar || !pre || bar.querySelector('.sto-php-snippet__actions')) return;
			var codeEl = pre.querySelector('code');
			var back = bar.querySelector('.sto-php-snippet__back');
			var actions = document.createElement('div');
			actions.className = 'sto-php-snippet__actions';
			var copyBtn = document.createElement('button');
			copyBtn.type = 'button';
			copyBtn.className = 'sto-php-snippet__iconbtn';
			copyBtn.setAttribute('aria-label', 'Copy code');
			copyBtn.innerHTML = COPY_ICON_SVG;
			copyBtn.addEventListener('click', function () {
				var raw = codeEl ? codeEl.textContent : pre.textContent;
				copyTextToClipboard(raw || '', function () {
					showCopiedToast();
				});
			});
			actions.appendChild(copyBtn);
			var tpl = tplRoot ? tplRoot.querySelector('template[data-sto-preview="' + key + '"]') : null;
			var liveBtn = null;
			var livePanel = null;
			var liveInner = null;
			if (tpl) {
				liveBtn = document.createElement('button');
				liveBtn.type = 'button';
				liveBtn.className = 'sto-php-snippet__livebtn';
				liveBtn.textContent = 'Live preview';
				liveBtn.setAttribute('aria-expanded', 'false');
				liveBtn.setAttribute('aria-controls', 'sto-live-' + key);
				livePanel = document.createElement('div');
				livePanel.className = 'sto-php-snippet__live';
				livePanel.id = 'sto-live-' + key;
				livePanel.setAttribute('hidden', '');
				liveInner = document.createElement('div');
				liveInner.className = 'sto-php-snippet__live-inner';
				livePanel.appendChild(liveInner);
				liveBtn.addEventListener('click', function () {
					var open = !livePanel.classList.contains('is-open');
					document.querySelectorAll('.sto-php-snippet__live.is-open').forEach(function (p) {
						if (p !== livePanel) {
							p.classList.remove('is-open');
							p.setAttribute('hidden', '');
						}
					});
					document.querySelectorAll('.sto-php-snippet__livebtn').forEach(function (b) {
						if (b !== liveBtn) b.setAttribute('aria-expanded', 'false');
					});
					if (open) {
						if (!liveInner.firstChild) {
							liveInner.appendChild(tpl.content.cloneNode(true));
						}
						livePanel.classList.add('is-open');
						livePanel.removeAttribute('hidden');
						liveBtn.setAttribute('aria-expanded', 'true');
					} else {
						livePanel.classList.remove('is-open');
						livePanel.setAttribute('hidden', '');
						liveBtn.setAttribute('aria-expanded', 'false');
					}
				});
				actions.appendChild(liveBtn);
			}
			if (back) {
				bar.insertBefore(actions, back);
			} else {
				bar.appendChild(actions);
			}
			if (livePanel) {
				sec.appendChild(livePanel);
			}
		});
	}

	function highlightPhpBlocks() {
		if (!window.Prism) return;
		document.querySelectorAll('.sto-docs-php-scroll pre code').forEach(function (el) {
			el.classList.add('language-php');
			Prism.highlightElement(el);
		});
	}

	function boot() {
		initSnippetChrome();
		highlightPhpBlocks();
		if (location.hash) {
			var hid = location.hash.replace(/^#/, '');
			var t = document.getElementById(hid);
			if (t && prose.contains(t)) {
				t.scrollIntoView({ block: 'start' });
				setActive(location.hash);
			}
		}
		syncFromProse();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
	</script>
</body>
</html>`;

const out = headThroughBody + '\n' + nav;

fs.writeFileSync(srcPath, out, 'utf8');
console.log('Wrote', srcPath, 'snippets:', snip.length);
