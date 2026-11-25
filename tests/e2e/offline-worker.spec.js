// Exercise Daymark's extension with the framework worker in a real registration.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';

const framework = fs.readFileSync(path.resolve(__dirname, '../../lib/framework/packages/akirk/wp-app/assets/offline/worker.js'), 'utf8');
const extension = fs.readFileSync(path.resolve(__dirname, '../../assets/push-worker.js'), 'utf8');
let server;
let origin;

test.beforeAll(async () => {
	server = http.createServer((request, response) => {
		if (request.url === '/daymark/worker') {
			const config = {
				cachePolicy: 'shell', cacheName: 'wp-app-daymark-test-7', cachePrefix: 'wp-app-daymark-',
				cacheIdentity: '7', scopeUrl: origin + '/daymark/', offlineUrl: origin + '/daymark/offline',
				precache: [origin + '/daymark/offline'], appData: { restUrl: origin + '/rest/' },
			};
			response.setHeader('Content-Type', 'application/javascript');
			response.end('self.wpAppWorker = ' + JSON.stringify(config) + ';\n' + framework + '\n;(function () {\n' + extension + '\n}).call(self);');
			return;
		}
		response.setHeader('Content-Type', 'text/html');
		response.setHeader('X-Wp-App-Offline', '7');
		response.end('<p>Safe offline shell</p>');
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	origin = 'http://127.0.0.1:' + server.address().port;
});

test.afterAll(async () => {
	await new Promise((resolve) => server.close(resolve));
});

test('worker migration preserves push tokens, serves offline navigation, and clears its caches at logout', async ({ page, context }) => {
	await page.goto(origin + '/daymark/install');
	await page.evaluate(async () => {
		await (await caches.open('daymark-old-release')).put('/daymark/config', new Response('old account config'));
		await (await caches.open('daymark-push-v1')).put('/daymark/push-token', new Response('device token'));
		await (await caches.open('another-app-cache')).put('/other', new Response('other app'));
		await navigator.serviceWorker.register('/daymark/worker', { scope: '/daymark/' });
		await navigator.serviceWorker.ready;
	});
	await expect.poll(() => page.evaluate(() => caches.keys())).not.toContain('daymark-old-release');
	let names = await page.evaluate(() => caches.keys());
	expect(names).toEqual(expect.arrayContaining(['wp-app-daymark-test-7', 'daymark-push-v1', 'another-app-cache']));
	expect(await page.evaluate(async () => (await (await caches.open('daymark-push-v1')).match('/daymark/push-token')).text())).toBe('device token');
	await context.setOffline(true);
	await page.goto(origin + '/daymark/deep-link');
	await expect(page.locator('p')).toHaveText('Safe offline shell');
	await page.evaluate(() => new Promise((resolve) => {
		const channel = new MessageChannel();
		channel.port1.onmessage = () => { channel.port1.close(); resolve(); };
		navigator.serviceWorker.controller.postMessage({ type: 'daymark-clear-offline-cache' }, [channel.port2]);
	}));
	names = await page.evaluate(() => caches.keys());
	expect(names).not.toContain('wp-app-daymark-test-7');
	expect(names).not.toContain('daymark-push-v1');
	expect(names).toContain('another-app-cache');
});
