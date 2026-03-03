import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './',
  testMatch: ['payuni-tokenization.ts', 'payuni-invoice-carrier.ts'],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  retries: 0,
  workers: 1, // 序列執行，避免 PayUni sandbox 並發問題
  reporter: 'list',
  use: {
    baseURL: 'https://payuni-test.powerhouse.tw',
    headless: false,
    viewport: { width: 1280, height: 900 },
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
