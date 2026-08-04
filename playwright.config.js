// Playwright config for Daymark E2E tests.
// Set WP_BASE_URL / WP_ADMIN_USER / WP_ADMIN_PASS for your target site.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://wp70.local',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'mobile-chromium',
			use: {
				// Phone-sized viewport per scenario 1; keep chromium even
				// though the descriptor defaults iPhones to webkit.
				...devices[ 'iPhone 13' ],
				browserName: 'chromium',
			},
		},
	],
} );
