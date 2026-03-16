import { defineConfig, devices } from '@playwright/test';
import * as dotenv from 'dotenv';

dotenv.config();

export default defineConfig({
  testDir: './tests',
  timeout: 120_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.BASE_URL || 'https://payuni-test.powerhouse.tw',
    headless: process.env.CI === 'true',
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    // 原有 PayUni Embed 測試（A-checkout ~ H-sdk）
    {
      name: 'payuni-embed',
      testDir: './tests',
      testMatch: /\/[A-H]-.*\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // 環境 Setup（啟用所有模組 + 建立 API Keys）
    {
      name: 'setup',
      testDir: './tests/00-setup',
      testMatch: /.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    // 新整合測試（01-08）
    {
      name: 'integration',
      testDir: './tests',
      testMatch: /\/(0[1-8])-.*\/.*\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // 核心業務 happy flow（01-05 + A-checkout + B-invoice，排除 edge cases）
    {
      name: 'happy-flow',
      testDir: './tests',
      testMatch: [
        /\/0[1-5]-.*\/.*\.spec\.ts$/,
        /\/A-checkout\/.*\.spec\.ts$/,
        /\/B-invoice\/.*\.spec\.ts$/,
      ],
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // 全部跑（setup + payuni-embed + integration）
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
