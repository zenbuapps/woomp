# PayUni Embed E2E 測試

## 概述

使用 Playwright 對 PayUni UNi Embed（免跳轉支付元件）進行端對端測試，涵蓋結帳付款、分期付款、發票載具、錯誤處理、邊界條件、Webhook 回調、退款及 SDK 初始化等場景。

## 測試環境

| 項目 | 值 |
|------|-----|
| 測試站台 | `https://payuni-test.powerhouse.tw` |
| WordPress 帳號 | `test` / `test` |
| 測試商品 | Album (NT$15, ID: 73) |
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

## 執行測試

```bash
# 執行所有測試
npm test

# 有瀏覽器畫面（debug 用）
npm run test:headed

# Debug 模式
npm run test:debug

# 依優先級執行
npm run test:p0    # 核心流程（3 案例）
npm run test:p1    # 載具+錯誤（9 案例）
npm run test:p2    # 分期+邊緣（10 案例）
npm run test:p3    # 邊界條件（20 案例）
npm run test:p4    # 退款+SDK（9 案例）
```

## 測試架構

```
tests/e2e/
├── fixtures/test-data.ts          # 測試卡號、CSS 選擇器、帳單資料
├── helpers/
│   ├── auth.helper.ts             # WordPress 登入
│   ├── cart.helper.ts             # 加入購物車
│   ├── checkout.helper.ts         # 結帳流程（填表、選金流、送出）
│   ├── iframe.helper.ts           # SDK iframe 操作（卡號輸入）
│   ├── carrier.helper.ts          # 發票載具選擇
│   └── admin.helper.ts            # 後台管理操作
├── tests/
│   ├── A-checkout/                # 基本結帳（8 案例）
│   ├── B-invoice/                 # 發票載具（12 案例）
│   ├── C-error/                   # 錯誤處理（9 案例）
│   ├── D-edge-cases/              # 邊緣案例（15 案例）
│   ├── E-boundary/                # 邊界值（17 案例）
│   ├── F-webhook/                 # Webhook（3 案例）
│   ├── G-refund/                  # 退款（3 案例）
│   └── H-sdk/                     # SDK 初始化（3 案例）
```

## 測試卡號（Sandbox）

| 用途 | 卡號 | 品牌 |
|------|------|------|
| 一般付款 | `4147631000000001` | Visa |
| 一般付款 | `3560511000000001` | JCB |
| 分期付款 | `4147632000000001` | Visa（不含 9 期）|
| 分期付款 | `3560562000000001` | JCB（全期數）|
| CVC | 任意 3 碼（如 `123`）| — |
| 到期日 | 任意未來日期（如 `1228`）| — |

## 優先級說明

| 等級 | 說明 | 案例數 |
|------|------|--------|
| P0 | 核心付款流程（必須通過）| 3 |
| P1 | 載具整合 + 錯誤顯示 | 9 |
| P2 | 分期 + 付款邊緣 | 10 |
| P3 | 邊界條件 + 載具邊緣 | 20 |
| P4 | 退款 + SDK + Token 邊緣 | 9 |

## 注意事項

- 測試站透過 Cloudflare Tunnel 連線，如無法連線請開啟本地伺服器
- `workers: 1` — PayUni Sandbox 有並發限制
- `timeout: 120s` — 支付流程較慢
- `retries: 1` — Sandbox 偶有不穩定
