import { defineConfig, devices } from '@playwright/test';
import * as dotenv from 'dotenv';
import * as path from 'path';

// 從專案根目錄讀取 .env（不論從哪個目錄執行 playwright）
dotenv.config({ path: path.resolve(__dirname, '../../.env') });

export default defineConfig({
  testDir: './tests',
  timeout: 300_000,       // 5 分鐘：WC admin 頁面含 Query Monitor 需大量 DB 查詢
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.TEST_SITE_URL || 'https://local-turbo.powerhouse.tw',
    headless: process.env.CI === 'true',
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
    ignoreHTTPSErrors: true,    // Local by Flywheel 自簽憑證
    actionTimeout: 30_000,
    navigationTimeout: 120_000,  // 2 分鐘：WC settings 頁面 PHP 執行極慢
  },
  projects: [
    // 環境 Setup（啟用所有模組 + 建立 API Keys）
    {
      name: 'setup',
      testDir: './tests/00-setup',
      testMatch: /.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // PayUni V3 付款矩陣（8 案例：分期/不分期 × 載具/無載具 × 成功/失敗）
    {
      name: 'payuni-matrix',
      testDir: './tests',
      testMatch: /\/P-payuni-matrix\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // PayUni 載具 UI 測試（無付款流程，純 UI 驗證）
    {
      name: 'payuni-carrier-ui',
      testDir: './tests',
      testMatch: /\/B-invoice\/B1-carrier-ui\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // 整合測試（01-08，非 PayUni 付款流程）
    {
      name: 'integration',
      testDir: './tests',
      testMatch: /\/(0[1-8])-.*\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // 核心業務 happy flow（01-05 + B1 載具 UI + P 付款矩陣）
    {
      name: 'happy-flow',
      testDir: './tests',
      testMatch: [
        /\/0[1-5]-.*\/.*\.spec\.ts$/,
        /\/B-invoice\/B1-carrier-ui\.spec\.ts$/,
        /\/P-payuni-matrix\/.*\.spec\.ts$/,
      ],
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // 全部跑
    {
      name: 'all',
      testDir: './tests',
      testMatch: /.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // 預設 project（向後相容）
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
