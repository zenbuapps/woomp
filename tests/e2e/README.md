# Woomp E2E 端對端測試

使用 Playwright 對 Woomp 所有功能模組進行端對端測試，在真實瀏覽器中操作 WordPress + WooCommerce 站台。

## 測試涵蓋範圍

- **設定系統**：頁籤結構、模組啟停、結帳模式、地址設定
- **結帳流程**：一頁式/兩頁式、台灣地址、超商取貨、離島、表單驗證
- **發票載具**：ECPay / EZPAY / PayNow 三種載具 UI 與驗證
- **金流閘道**：PayUni / ECPay / PayNow / NewebPay / SmilePay / LINE Pay
- **後台管理**：訂單列表欄位、自訂狀態、批次操作、發票 Metabox
- **PayUni Embed**：結帳付款、分期、發票載具、錯誤處理、Webhook、退款、SDK

## 環境需求

- Node.js 18+
- 可連線的 WordPress 測試站台（Local by Flywheel / Cloudflare Tunnel / 遠端）
- 測試站台需安裝並啟用 WooCommerce + Woomp

## 安裝

```bash
cd tests/e2e
npm install
npx playwright install chromium
```

## 環境設定

在**專案根目錄**（非 `tests/e2e/`）建立 `.env` 檔案：

```bash
# 從專案根目錄
cp .env.example .env
```

填入測試站台的實際值：

```env
# 測試站台 URL
TEST_SITE_URL=https://your-test-site.example.com
TEST_ADMIN_URL=https://your-test-site.example.com/wp-admin

# WordPress 管理員帳號
TEST_USERNAME=your_username
TEST_PASSWORD=your_password

# CI 模式（true = headless，false = 有頭模式）
CI=false

# WooCommerce REST API Keys（由 00-setup 測試自動產生，初始可留空）
WC_API_KEY=
WC_API_SECRET=
```

> `.env` 放在專案根目錄，`playwright.config.ts` 會自動讀取。

## 首次執行 Setup

首次使用或更換測試站台後，需先跑 Setup 測試以啟用所有模組並建立 WC API Keys：

```bash
npx playwright test --project=setup
```

Setup 會自動將產生的 `WC_API_KEY` / `WC_API_SECRET` 寫入 `.env`。

## 常用指令

所有指令在 `tests/e2e/` 目錄下執行：

### 按使用場景

```bash
# 核心 happy flow（最常用，涵蓋 01-05 + A-checkout + B-invoice）
npm run test:happy-flow

# 同上，有頭模式（本地 debug 用，可看到瀏覽器畫面）
npm run test:happy-headed

# 最精簡 CI 驗證（核心目錄 × @P0 tag）
npm run test:happy-ci

# 全部測試（含 setup 依賴）
npm run test:all

# 查看上次測試的 HTML 報告
npm run report
```

### 按功能模組

```bash
npm run test:core         # 01-core：設定頁 + 閘道註冊
npm run test:checkout     # A-checkout：PayUni 結帳流程
npm run test:invoice      # B-invoice：發票載具 UI
npm run test:ecpay        # 04-ecpay：綠界測試
npm run test:paynow       # 05-paynow：立吉富測試
npm run test:admin        # 07-admin：後台管理
npm run test:frontend     # 08-frontend：前台 UI
npm run test:error        # C-error：錯誤處理
npm run test:edge         # D-edge-cases：邊緣案例
npm run test:boundary     # E-boundary：邊界值測試
```

### 按優先級

```bash
npm run test:p0           # @P0 最高優先（核心 happy path）
npm run test:p1           # @P1 高優先
npm run test:p2           # @P2 中優先
```

### 按 Playwright Project

```bash
npm run test:payuni-embed   # A-H 目錄（PayUni Embed 測試）
npm run test:integration    # 01-08 目錄（整合測試，自動先跑 setup）
npm run test:all            # 全部
```

### 開發 / Debug 模式

```bash
npm run test:headed       # 有頭模式（看到瀏覽器）
npm run test:debug        # Playwright Inspector 逐步 debug
```

### Playwright CLI 進階用法

```bash
# 跑特定 spec 檔案
npx playwright test tests/02-checkout/form-validation.spec.ts

# 跑特定目錄
npx playwright test tests/01-core/ tests/02-checkout/

# 按 tag 篩選
npx playwright test --grep @P0
npx playwright test --grep "@P0|@P1"

# 排除 tag
npx playwright test --grep-invert "@edge|@boundary"

# 組合：核心目錄 + P0 + 排除邊緣測試
npx playwright test --grep @P0 --grep-invert "@edge|@boundary" tests/01-core/ tests/02-checkout/

# 失敗重試 2 次
npx playwright test --retries=2

# 強制 headless
npx playwright test --headed=false
```

## Playwright Projects 說明

| Project | 說明 | 測試目錄 | 依賴 |
|---------|------|---------|------|
| `setup` | 啟用所有模組 + 建立 API Keys | `00-setup/` | 無 |
| `payuni-embed` | PayUni Embed 完整測試 | `A-H/` | 無 |
| `integration` | 整合測試 | `01-08/` | setup |
| `happy-flow` | 核心業務 happy flow | `01-05/` + `A-B/` | setup |
| `all` | 全部測試 | 全部 | 無 |
| `chromium` | 預設（向後相容） | 全部 | 無 |

## 測試架構

```
tests/e2e/
├── playwright.config.ts              # Playwright 設定（projects / timeout / retry）
├── package.json                      # npm scripts
├── fixtures/
│   ├── test-data.ts                  # 通用測試資料（帳號、卡號、Selectors）
│   ├── admin-urls.ts                 # 後台 URL 常數
│   ├── ecpay-data.ts                 # ECPay Gateway/Shipping/Invoice IDs
│   └── paynow-data.ts               # PayNow/NewebPay/SmilePay IDs
├── helpers/
│   ├── auth.helper.ts                # WordPress 登入/登出
│   ├── cart.helper.ts                # 加入購物車/前往結帳
│   ├── checkout.helper.ts            # 結帳流程（填表、選金流、送出）
│   ├── iframe.helper.ts              # PayUni SDK iframe 操作
│   ├── carrier.helper.ts             # 發票載具選擇
│   ├── admin.helper.ts               # 後台訂單操作
│   ├── wc-api.helper.ts              # WC REST API 客戶端
│   ├── settings.helper.ts            # 設定頁面導航與操作
│   ├── invoice-admin.helper.ts       # 發票後台操作
│   ├── shipping-admin.helper.ts      # 物流後台操作
│   └── product.helper.ts             # 商品頁面操作
└── tests/
    ├── 00-setup/                     # 環境 Setup（啟用模組 + API Keys）
    ├── 01-core/                      # 設定系統 + Gateway 註冊
    ├── 02-checkout/                  # 結帳流程（模式、地址、驗證）
    ├── 03-invoice/                   # 發票載具 UI
    ├── 04-ecpay/                     # 綠界（閘道 + 發票 + 物流）
    ├── 05-paynow/                    # 立吉富（閘道 + 發票 + 物流）
    ├── 06-other-gateways/            # LINE Pay / 支付連 / 藍新 / 速買配
    ├── 07-admin/                     # 後台管理（訂單列表、狀態、批次）
    ├── 08-frontend/                  # 前台展示（變體、物流查詢）
    ├── A-checkout/                   # PayUni 結帳（新卡 / 記憶卡）
    ├── B-invoice/                    # PayUni 發票載具
    ├── C-error/                      # 錯誤處理（前端 / 後端 / SDK）
    ├── D-edge-cases/                 # 邊緣案例
    ├── E-boundary/                   # 邊界值（金額 / 長度 / 分期）
    ├── F-webhook/                    # Webhook 回調
    ├── G-refund/                     # 退款
    └── H-sdk/                        # SDK 初始化
```

## 三層驗證策略

| Tier | 方式 | 適用場景 |
|------|------|---------|
| **Tier 1** | Browser UI Tests | 結帳流程、設定頁面、表單驗證、商品展示 |
| **Tier 2** | Admin Panel 驗證 | 訂單狀態、備註、meta、發票狀態 |
| **Tier 3** | WC REST API 驗證 | Gateway 註冊、訂單 meta、設定值 |

## Graceful Skip 機制

測試遇到以下情況時會 **skip**（而非 fail），確保缺少特定模組或 API Key 時不會阻斷整個 suite：

- 缺少 WC REST API Key（`WC_API_KEY` / `WC_API_SECRET` 為空）
- 特定金流模組未啟用（如 ECPay、PayNow）
- PayUni SDK iframe 未載入（缺少有效 Merchant ID）

## 測試卡號（PayUni Sandbox）

| 用途 | 卡號 | 品牌 |
|------|------|------|
| 一般付款 | `4147631000000001` | Visa |
| 一般付款 | `3560511000000001` | JCB |
| 分期付款 | `4147632000000001` | Visa |
| 分期付款 | `3560562000000001` | JCB |
| CVC | 任意 3 碼（如 `123`） | — |
| 到期日 | 任意未來日期（如 `1228`） | — |

## 設定參數

`playwright.config.ts` 中的關鍵設定：

| 參數 | 值 | 說明 |
|------|-----|------|
| `timeout` | 300s (5min) | 全域 timeout，WC admin 頁面含 Query Monitor 很慢 |
| `actionTimeout` | 30s | 單一操作 timeout |
| `navigationTimeout` | 120s (2min) | 頁面導航 timeout |
| `expect.timeout` | 15s | 斷言等待 timeout |
| `workers` | 1 | 單執行緒（金流 Sandbox 有並發限制） |
| `retries` | 1 | Sandbox 偶有不穩定，自動重試 1 次 |
| `ignoreHTTPSErrors` | true | 支援 Local by Flywheel 自簽憑證 |
| `headless` | 由 `CI` 環境變數控制 | `CI=true` 時 headless，否則有頭 |

## 注意事項

- 測試站台透過 Cloudflare Tunnel 或本地連線，如無法連線請確認服務已啟動
- `.env` 檔案放在**專案根目錄**（`woomp/.env`），不是 `tests/e2e/.env`
- `workers: 1` 是刻意的 — 金流 Sandbox 不支援並發、WC session 狀態會互相干擾
- 長時間連續跑全部測試時，Local by Flywheel 的 PHP 效能可能下降，建議分批執行
- REST API 測試需要先跑 `--project=setup` 建立 API Keys
