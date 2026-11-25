// Exercise the framework bootstrap and Daymark adapter without a WordPress server.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const client = fs.readFileSync(path.resolve(__dirname, '../../lib/framework/packages/akirk/wp-app/assets/offline/client.js'), 'utf8');
const adapter = fs.readFileSync(path.resolve(__dirname, '../../assets/offline-boot.js'), 'utf8');

async function openShell(page, response) {
	const origin = 'http://127.0.0.1:8123';
	const offline = { userId: 7, currentUser: { id: 7 }, assetsUrl: origin + '/assets/' };
	const settings = JSON.stringify({ url: origin + '/config', offline, worker: null }).replace(/"/g, '&quot;');
	await page.route('**/*', async (route) => {
		const pathname = new URL(route.request().url()).pathname;
		if (pathname === '/config') {
			if (response === 'offline') return route.abort('internetdisconnected');
			return route.fulfill({ status: response.status, contentType: 'application/json', body: JSON.stringify(response.data || {}) });
		}
		const scripts = { '/client.js': client, '/offline-boot.js': adapter, '/assets/app.js': 'window.startedConfig = window.daymarkApp;' };
		if (scripts[pathname]) return route.fulfill({ contentType: 'application/javascript', body: scripts[pathname] });
		return route.fulfill({ contentType: 'text/html', body: `<script src="/client.js"></script><div data-wp-app-bootstrap="${settings}"><div id="daymark-app">Loading</div></div><script src="/offline-boot.js"></script>` });
	});
	await page.goto(origin + '/offline');
}

for (const response of ['offline', { status: 503 }]) {
	test(`safe offline configuration starts the app after ${typeof response === 'string' ? response : 'server failure'}`, async ({ page }) => {
		await openShell(page, response);
		await expect.poll(() => page.evaluate(() => window.startedConfig)).toMatchObject({ userId: 7, offlineShell: true });
		expect(await page.evaluate(() => window.startedConfig.nonce)).toBeUndefined();
	});
}

test('live bootstrap keeps the fresh credential in memory', async ({ page }) => {
	await openShell(page, { status: 200, data: { userId: 7, assetsUrl: 'http://127.0.0.1:8123/assets/', nonce: 'fresh' } });
	await expect.poll(() => page.evaluate(() => window.startedConfig)).toMatchObject({ userId: 7, nonce: 'fresh' });
});

for (const response of [{ status: 401 }, { status: 200, data: { userId: 8 } }]) {
	test(`bootstrap rejects ${response.status === 401 ? 'expired authentication' : 'an account change'}`, async ({ page }) => {
		await openShell(page, response);
		await expect(page.locator('#daymark-app')).toContainText(response.status === 401 ? 'Sign in again' : 'account changed');
		expect(await page.evaluate(() => window.startedConfig)).toBeUndefined();
	});
}
