# PayUni v3 E2E 測試

本目錄包含 PayUni 統一金流 v3 信用卡功能的端對端（E2E）測試腳本，使用 [Playwright](https://playwright.dev/) 撰寫。

## 測試涵蓋功能

| 測試腳本 | 功能 | 執行方式 |
|---|---|---|
| `payuni-installment.ts` | 信用卡分期付款（3/6/12 期） | `npx tsx` |
| `payuni-tokenization.ts` | 信用卡記憶卡號（Token） | `npx playwright test` |
| `payuni-invoice-carrier.ts` | 電子發票載具整合 | `npx playwright test` |
| `payuni-checkout.ts` | 信用卡一次付清（基本結帳） | `npx tsx` |

---

## 環境設定

### 1. 安裝依賴

```bash
cd e2e
npm install
```

### 2. 安裝 Playwright 瀏覽器

```bash
npx playwright install chromium
```

---

## 前置條件（WordPress 後台設定）

測試前請確認 WordPress 後台的付款設定已正確配置：

1. 前往 `WooCommerce → 設定 → 付款 → 統一金流 PAYUNi 信用卡 v3`
2. **分期付款**：「分期付款選項」需至少勾選 **3期、6期、12期**
3. **記憶卡號**：「啟用記憶卡號功能」需為 **啟用**
4. **發票載具**：「啟用電子發票」需為 **啟用**

---

## 測試帳號與環境

| 項目 | 值 |
|---|---|
| 測試網站 | `https://payuni-test.powerhouse.tw/` |
| WordPress 帳號 | `test` / `test` |
| 測試商品 | Album（NT$15） |

### 測試用信用卡

| 用途 | 卡號 | CVC | 到期日 |
|---|---|---|---|
| 一次付清 | `4147630000000001` | `123` | `12/28` |
| 分期 / 記憶卡號 | `4147631000000001` | `123` | `12/28` |

### 發票載具測試資料

| 載具類型 | 測試值 |
|---|---|
| 手機條碼 | `/ABC1234` |
| 會員載具 | （無需輸入，勾選即可） |
| 捐贈 | （無需輸入，勾選即可） |

---

## 執行測試

### 分期付款測試

```bash
cd e2e
npx tsx payuni-installment.ts
# 或使用 npm script：
npm run test:installment
```

**預期結果：** 選擇 3 期分期，訂單建立成功，頁面顯示「已完成的訂單」或「已收到訂單」。

---

### 記憶卡號測試

```bash
cd e2e
npx playwright test payuni-tokenization.ts
# 或使用 npm script：
npm run test:tokenization
```

**測試包含三個案例（依序執行）：**
1. Test 1：第一次付款並記憶卡號
2. Test 2：使用已記憶的卡號重新付款
3. Test 3：驗證已儲存的卡片顯示在結帳頁

> ⚠️ 三個測試有依賴關係，必須依序執行（已設定 `test.describe.serial`）。

---

### 電子發票載具測試

```bash
cd e2e
npx playwright test payuni-invoice-carrier.ts
# 或使用 npm script：
npm run test:invoice
```

**測試包含五個案例：**
1. 無選擇載具（預設）
2. 手機條碼 `/ABC1234`
3. 會員載具
4. 捐贈
5. 發票 UI 欄位驗證

---

### 一次付清基本結帳測試

```bash
cd e2e
npx tsx payuni-checkout.ts
# 或使用 npm script：
npm run test:checkout
```

---

### 執行所有使用 Playwright Test 框架的測試

```bash
cd e2e
npm test
```

> 注意：這只會執行 `payuni-tokenization.ts` 和 `payuni-invoice-carrier.ts`，不含以 `npx tsx` 執行的腳本。

---

## 兩種執行模式說明

本目錄的測試腳本分為兩種類型：

| 模式 | 腳本 | 執行方式 | 說明 |
|---|---|---|---|
| Playwright Test 框架 | `payuni-tokenization.ts`、`payuni-invoice-carrier.ts` | `npx playwright test` | 支援 `expect()`、`test.describe.serial`、HTML 報告 |
| Standalone（raw playwright） | `payuni-installment.ts`、`payuni-checkout.ts` | `npx tsx` | 獨立腳本，不使用測試框架，直接以 `process.exit()` 回傳結果 |

---

## 測試截圖

測試執行時會自動在 `screenshots/` 目錄儲存截圖，命名格式為：

```
{測試名稱}-{步驟}-{timestamp}.png
```

例如：
- `installment-before-submit-xxxxx.png`
- `installment-success-xxxxx.png`
- `installment-error-xxxxx.png`

---

## CDP 模式（進階）

若要連接到已開啟的 Chrome 瀏覽器（用於除錯）：

```bash
# 先用 CDP 模式啟動 Chrome（需手動開啟 Chrome）
cd e2e
npx tsx payuni-checkout.ts --cdp-endpoint=http://localhost:9222
# 或：
npm run test:checkout:cdp
```

---

## 常見問題

### Q: `Cannot find module '@playwright/test'`
請確認已在 `e2e` 目錄執行 `npm install`，不要在外層目錄執行。

```bash
cd e2e
npm install
```

### Q: 分期下拉選單沒有出現
請確認 WordPress 後台「PayUni 信用卡 v3」設定中，已在「分期付款選項」勾選至少一個期數並儲存。

### Q: 發票載具選項沒有出現
請確認 WordPress 後台已啟用「電子發票功能」。

### Q: 測試執行時付款 3D 驗證失敗
PayUni sandbox 沙箱環境中，使用測試卡號 `4147631000000001` 付款時，可能出現「建立幕後3D成功」訊息，這是正常的 sandbox 行為，訂單仍然成功建立。
