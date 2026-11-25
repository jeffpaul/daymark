// TEMPORARY: measures how much memory a Playground preview takes in Chromium.
// Removed before this branch is ready for review.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

const variant = process.env.VARIANT;
const repeats = Number(process.env.REPEATS || 1);
const idleSeconds = Number(process.env.IDLE_SECONDS || 180);
const REPO = process.env.REPO_URL || 'https://github.com/jeffpaul/daymark';
const REF = process.env.REF || 'main';
const outDir = process.env.OUT_DIR || '.';

const demoFromPlugin = [
	'<?php',
	"require_once '/wordpress/wp-load.php';",
	"if ( defined( 'DAYMARK_PLUGIN_FILE' ) ) {",
	"\t$demo = dirname( DAYMARK_PLUGIN_FILE ) . '/.github/blueprints/set-up-demo.php';",
	'\tif ( file_exists( $demo ) ) { require $demo; }',
	'}',
].join('\n');
const demoFromContent = [
	'<?php',
	"require_once '/wordpress/wp-load.php';",
	"$demo = WP_CONTENT_DIR . '/daymark-demo/set-up-demo.php';",
	'if ( file_exists( $demo ) ) { require $demo; }',
].join('\n');

const debug = { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false } };
const permalinks = { step: 'setSiteOptions', options: { permalink_structure: '/%postname%/' } };
const gitInstall = { step: 'installPlugin', pluginData: { resource: 'git:directory', url: REPO, ref: REF, path: '/' }, options: { activate: true } };
const zipInstall = { step: 'installPlugin', pluginData: { resource: 'url', url: `${REPO}/archive/refs/heads/${REF}.zip` }, options: { activate: true, targetFolderName: 'daymark' } };
const demoFiles = { step: 'writeFiles', writeToPath: '/wordpress/wp-content/daymark-demo', filesTree: { resource: 'git:directory', url: REPO, ref: REF, path: '.github/blueprints' } };
const base = { preferredVersions: { wp: 'latest' }, login: true, landingPage: '/daymark' };

const variants = {
	// Playground on its own: no plugin, wp-admin.
	bare: { preferredVersions: { wp: 'latest' }, login: true, landingPage: '/wp-admin/' },
	// What a pull request preview runs today.
	current: { ...base, steps: [debug, permalinks, gitInstall, { step: 'runPHP', code: demoFromPlugin }] },
	// Today's install, without the demo content.
	'current-nodemo': { ...base, steps: [debug, permalinks, gitInstall] },
	// The plugin from GitHub's zip of the branch (which leaves out the development files), demo files fetched on their own.
	zip: { ...base, steps: [debug, permalinks, zipInstall, demoFiles, { step: 'runPHP', code: demoFromContent }] },
	'zip-nodemo': { ...base, steps: [debug, permalinks, zipInstall] },
	// Today's preview without Playground's own toolbar around it.
	'current-seamless': { ...base, steps: [debug, permalinks, gitInstall, { step: 'runPHP', code: demoFromPlugin }] },
	// As zip, landing in wp-admin, to see what the Daymark app itself adds.
	'zip-admin': { ...base, landingPage: '/wp-admin/', steps: [debug, permalinks, zipInstall, demoFiles, { step: 'runPHP', code: demoFromContent }] },
};

function procs() {
	const all = {};
	for (const d of fs.readdirSync('/proc')) {
		if (!/^\d+$/.test(d)) continue;
		try {
			const stat = fs.readFileSync(`/proc/${d}/stat`, 'utf8');
			const ppid = Number(stat.slice(stat.lastIndexOf(')') + 2).split(' ')[1]);
			const cmd = fs.readFileSync(`/proc/${d}/cmdline`, 'utf8').replace(/\0/g, ' ');
			all[d] = { pid: Number(d), ppid, cmd };
		} catch (e) {}
	}
	return all;
}

function sample(userDataDir) {
	const all = procs();
	const root = Object.values(all).find((p) => p.cmd.includes(userDataDir) && !p.cmd.includes('--type='));
	if (!root) return null;
	const tree = [root];
	for (let i = 0; i < tree.length; i++) {
		for (const p of Object.values(all)) if (p.ppid === tree[i].pid) tree.push(p);
	}
	let total = 0;
	const byType = {};
	let biggest = 0;
	for (const p of tree) {
		try {
			const roll = fs.readFileSync(`/proc/${p.pid}/smaps_rollup`, 'utf8');
			const kb = Number((roll.match(/^Pss:\s+(\d+)/m) || [])[1] || 0);
			total += kb;
			const type = (p.cmd.match(/--type=([a-z-]+)/) || [, 'browser'])[1];
			byType[type] = (byType[type] || 0) + kb;
			if (type === 'renderer') biggest = Math.max(biggest, kb);
		} catch (e) {}
	}
	return { total, byType, biggest };
}

const mb = (kb) => Math.round(kb / 1024);

async function run(n) {
	const blueprint = variants[variant];
	const url = 'https://playground.wordpress.net/?' + (variant.endsWith('-seamless') ? 'mode=seamless&' : '') + 'blueprint-url=data:application/json,' + encodeURIComponent(JSON.stringify(blueprint));
	const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pgmem-'));
	const context = await chromium.launchPersistentContext(userDataDir, {
		channel: 'chromium',
		headless: true,
		viewport: { width: 1280, height: 900 },
	});
	const page = context.pages()[0] || (await context.newPage());
	const errors = [];
	page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 300)); });
	page.on('pageerror', (e) => errors.push('pageerror ' + String(e).slice(0, 300)));

	await page.goto('about:blank');
	await page.waitForTimeout(3000);
	const idle = sample(userDataDir);

	const samples = [];
	let peak = { total: 0 };
	const timer = setInterval(() => {
		const s = sample(userDataDir);
		if (!s) return;
		samples.push(s);
		if (s.total > peak.total) peak = s;
	}, 500);

	const started = Date.now();
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	const landing = blueprint.landingPage;
	let frame = null;
	const deadline = Date.now() + 240000;
	while (Date.now() < deadline) {
		frame = page.frames().find((f) => {
			try { return new URL(f.url()).pathname.replace(/^\/scope:[^/]+/, '').startsWith(landing.replace(/\/$/, '')) && f.url().includes('scope:'); } catch (e) { return false; }
		});
		if (frame) {
			const ready = await frame.evaluate(() => document.readyState === 'complete').catch(() => false);
			if (ready) break;
			frame = null;
		}
		await page.waitForTimeout(500);
	}
	const readySeconds = Math.round((Date.now() - started) / 1000);
	const bootPeak = peak;

	// Let it sit for a moment once it is up.
	await page.waitForTimeout(45000);
	const settled = sample(userDataDir);

	const describe = async () => frame ? frame.evaluate(() => ({
		url: location.pathname + location.hash,
		articles: document.querySelectorAll('article').length,
		images: document.images.length,
		imagesLoaded: [...document.images].filter((i) => i.complete && i.naturalWidth > 0).length,
		text: document.body.innerText.replace(/\s+/g, ' ').slice(0, 160),
	})).catch((e) => ({ error: String(e).slice(0, 200) })) : null;
	const domAtRest = await describe();

	// Use it for a while, the way a reviewer would.
	peak = { total: 0 };
	const useNotes = [];
	if (frame) {
		try {
			if (landing.startsWith('/daymark')) {
				for (let i = 0; i < 20; i++) {
					await frame.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)).catch(() => {});
					await page.waitForTimeout(1500);
				}
				useNotes.push('scrolled:' + (await frame.evaluate(() => document.querySelectorAll('article').length).catch(() => -1)));
				for (const hash of ['#explore', '#notifications', '#me', '#home']) {
					await frame.click(`.daymark-bottomnav__link[href="${hash}"]`, { timeout: 5000 }).catch((e) => useNotes.push('noclick ' + hash));
					await page.waitForTimeout(4000);
				}
				const opened = await frame.click('article a[href]', { timeout: 5000 }).then(() => true).catch(() => false);
				useNotes.push('opened:' + opened);
				await page.waitForTimeout(8000);
				for (let i = 0; i < 10; i++) {
					await frame.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)).catch(() => {});
					await page.waitForTimeout(1500);
				}
			} else {
				for (const sel of ['#menu-posts > a', '#menu-media > a', '#menu-plugins > a', '#menu-settings > a', '#menu-dashboard > a', '#menu-posts > a', '#menu-plugins > a']) {
					frame = page.frames().find((f) => f.url().includes('scope:') && f.url().includes('/wp-admin')) || frame;
					await frame.click(sel, { timeout: 8000 }).catch(() => useNotes.push('noclick ' + sel));
					await page.waitForTimeout(8000);
				}
			}
		} catch (e) { useNotes.push('use failed ' + String(e).slice(0, 120)); }
	}
	const peakUse = peak;
	await page.waitForTimeout(15000);
	const afterUse = sample(userDataDir);
	const domAfterUse = await describe();

	// Then leave the tab alone.
	const idleSeries = [];
	for (let t = 0; t < idleSeconds; t += 60) {
		await page.waitForTimeout(60000);
		idleSeries.push(mb(sample(userDataDir).total));
	}
	clearInterval(timer);

	const result = {
		variant, run: n, readySeconds, found: !!frame,
		browserAloneMB: mb(idle.total),
		bootPeakMB: mb(bootPeak.total),
		settledMB: mb(settled.total), settledBiggestRendererMB: mb(settled.biggest),
		usePeakMB: mb(peakUse.total),
		afterUseMB: mb(afterUse.total), afterUseBiggestRendererMB: mb(afterUse.biggest),
		idleSeriesMB: idleSeries,
		domAtRest, domAfterUse, useNotes,
		errors: errors.filter((e) => !/429|Manifest fetch|Permissions policy|bad HTTP response code \(404\)/.test(e)).slice(0, 5),
	};
	console.log('RESULT ' + JSON.stringify(result));
	await context.close();
	return result;
}

(async () => {
	if (!variants[variant]) throw new Error('Unknown variant ' + variant);
	for (let n = 1; n <= repeats; n++) {
		try { await run(n); } catch (e) { console.log('RESULT ' + JSON.stringify({ variant, run: n, failed: String(e) })); }
	}
})();
