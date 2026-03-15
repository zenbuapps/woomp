# Woomp E2E 測試

## 概述

使用 Playwright 對 Woomp 所有功能模組進行端對端測試，涵蓋：
- **PayUni Embed**：結帳付款、分期、發票載具、錯誤處理、Webhook、退款、SDK
- **設定系統**：頁籤結構、模組啟停、結帳模式、地址設定
- **結帳流程**：一頁式/兩頁式、台灣地址、超商取貨、離島、表單驗證
- **發票載具**：ECPay / EZPAY / PayNow 三種載具 UI 與驗證
- **金流閘道**：ECPay / PayNow / NewebPay / SmilePay / LINE Pay / PChomePay
- **物流方式**：超商取貨、宅配、離島處理
- **後台管理**：訂單列表、自訂狀態、批次操作、發票 Metabox
- **前台展示**：變體標籤/單選、物流查詢

## 測試環境

| 項目 | 值 |
|------|-----|
| 測試站台 | `https://payuni-test.powerhouse.tw` |
| WordPress 帳號 | `test` / `test` |
| 測試商品 | T-Shirt with Logo (NT$10, ID: 81) |
| PayUni 環境 | Sandbox |

## 安裝

```bash
cd tests/e2e
npm install
npx playwright install chromium
```

## 設定

複製 `.env.example` 為 `.env` 並填入實際值：

```bash
cp .env.example .env
```

首次使用需執行 Setup 測試以啟用所有模組並建立 API Keys：

```bash
npx playwright test --project=setup
```

## 執行測試

```bash
# 執行所有測試
npx playwright test --project=all

# 僅 PayUni Embed 測試
npx playwright test --project=payuni-embed

# 僅新整合測試（依賴 setup）
npx playwright test --project=integration

# 先跑 setup 再跑整合測試
npx playwright test --project=setup && npx playwright test --project=integration

# 有瀏覽器畫面（debug 用）
npm run test:headed

# Debug 模式
npm run test:debug

# 依優先級執行（PayUni Embed）
npm run test:p0
npm run test:p1
npm run test:p2

# 依功能群組執行
npx playwright test tests/01-core/
npx playwright test tests/02-checkout/
npx playwright test tests/03-invoice/
npx playwright test tests/04-ecpay/
npx playwright test tests/05-paynow/
npx playwright test tests/06-other-gateways/
npx playwright test tests/07-admin/
npx playwright test tests/08-frontend/

# 查看報告
npm run report
```

## 測試架構

```
tests/e2e/
├── playwright.config.ts              # Playwright 設定（含 projects）
├── .env.example                      # 環境變數範本
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
│   ├── carrier.helper.ts             # PayUni 發票載具選擇
│   ├── admin.helper.ts               # 後台訂單操作
│   ├── wc-api.helper.ts              # WC REST API 客戶端（核心）
│   ├── settings.helper.ts            # 設定頁面導航與操作
│   ├── invoice-admin.helper.ts       # 發票後台操作
│   ├── shipping-admin.helper.ts      # 物流後台操作
│   └── product.helper.ts             # 商品頁面操作
├── tests/
│   ├── A-H/                          # PayUni Embed 測試（~67 案例）
│   ├── 00-setup/                     # 模組啟用 + API Key 建立
│   ├── 01-core/                      # 設定系統 + Gateway 註冊（~20 案例）
│   ├── 02-checkout/                  # 結帳流程（~25 案例）
│   ├── 03-invoice/                   # 發票載具 UI（~20 案例）
│   ├── 04-ecpay/                     # 綠界（~10 案例）
│   ├── 05-paynow/                    # 立吉富（~8 案例）
│   ├── 06-other-gateways/            # LINE Pay/支付連/藍新/速買配（~5 案例）
│   ├── 07-admin/                     # 後台管理（~15 案例）
│   └── 08-frontend/                  # 前台展示（~10 案例）
```

## Projects 說明

| Project | 說明 | 測試目錄 |
|---------|------|---------|
| `setup` | 啟用所有模組 + 建立 API Keys | `00-setup/` |
| `payuni-embed` | PayUni Embed 測試 | `A-H/` |
| `integration` | 新整合測試（依賴 setup） | `01-08/` |
| `all` | 全部測試 | 全部 |
| `chromium` | 預設（向後相容） | 全部 |

## 三層驗證策略

| Tier | 方式 | 適用場景 |
|------|------|---------|
| **Tier 1** | Browser UI Tests | 結帳流程、設定頁面、表單驗證、商品展示 |
| **Tier 2** | Admin Panel 驗證 | 訂單狀態、備註、meta、發票狀態 |
| **Tier 3** | WC REST API 驗證 | Gateway 註冊、訂單 meta、設定值 |

## 測試卡號（PayUni Sandbox）

| 用途 | 卡號 | 品牌 |
|------|------|------|
| 一般付款 | `4147631000000001` | Visa |
| 一般付款 | `3560511000000001` | JCB |
| 分期付款 | `4147632000000001` | Visa |
| 分期付款 | `3560562000000001` | JCB |
| CVC | 任意 3 碼（如 `123`） | — |
| 到期日 | 任意未來日期（如 `1228`） | — |

## 注意事項

- 測試站透過 Cloudflare Tunnel 連線，如無法連線請開啟本地伺服器
- `workers: 1` — 金流 Sandbox 有並發限制
- `timeout: 120s` — 支付流程較慢
- `retries: 1` — Sandbox 偶有不穩定
- REST API 測試需要先跑 `--project=setup` 建立 API Keys
