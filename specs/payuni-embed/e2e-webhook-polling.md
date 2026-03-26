# PayUni Embed E2E Webhook 輪詢測試規格

基於 specs/payuni-embed 規格產出，驗證 PayUni 信用卡付款在真實 Sandbox 環境下的 webhook 回調行為。

**測試目標**：透過 60 秒輪詢策略驗證成功/失敗場景的訂單狀態流轉，涵蓋不分期與分期兩種付款模式。

**測試環境**：`https://local-test.powerhouse.tw`

---

## 設計決策

### Webhook 等待策略

PayUni Sandbox 環境中，付款成功後 webhook 回調的到達時間不確定。採用以下策略：

| 場景 | 策略 | 超時 | 判定邏輯 |
|------|------|------|---------|
| 成功 | 輪詢等待 | 60 秒 | 每 5 秒檢查一次訂單狀態，若變為 `processing` 或 `completed` 即通過 |
| 失敗 | 超時後檢查 | 60 秒 | 等滿 60 秒後檢查訂單狀態仍為 `pending`，即視為 webhook 未到達（預期行為）|

**選擇 60 秒的理由**：最保守方案，幾乎不會 false positive。即使 Sandbox 網路延遲，60 秒內成功場景的 webhook 應可到達；失敗場景（3D 取消卡）根本不會觸發 webhook，等滿 60 秒是安全的。

### 輪詢實作方式

使用 WC REST API（`/wp-json/wc/v3/orders/{orderId}`）輪詢訂單狀態，不透過 Admin UI 登入查看。

```
每 5 秒呼叫 GET /wp-json/wc/v3/orders/{orderId}
  → 讀取 response.status
  → 若 status 為 processing 或 completed → 通過
  → 若已超過 60 秒 → 依場景判定（成功場景 fail / 失敗場景 pass）
```

---

## 測試卡號對照表

| 代號 | 卡號 | 品牌 | 用途 | Fixture Key |
|------|------|------|------|-------------|
| 一次付清 | `4147631000000001` | Visa | 不分期成功 | `CARDS.visa` |
| 3D 取消 | `4147631000000002` | Visa | 不分期失敗（ECI 不符，主動取消授權）| `CARDS.visa3d` |
| 分期全期數 | `3560562000000001` | JCB | 分期成功（支援所有期數）| `CARDS.jcbInstallment` |
| 一次付清（不支援分期）| `4147631000000001` | Visa | 分期失敗（一次付清卡搭配分期）| `CARDS.visa` |

**到期日**：`1228`（任意）
**CVC**：`123`（任意三碼）

---

## 測試案例

### T1. 不分期成功 -- Visa 一次付清卡 + 不分期

| 項目 | 內容 |
|------|------|
| **案例 ID** | T1 |
| **優先級** | @P0 |
| **類型** | 成功場景 |
| **卡號** | `4147631000000001`（Visa 一次付清）|
| **分期** | 不分期（installment = 1 或不選）|

#### 前置條件

1. 購物車有商品（NT$10，T-Shirt with Logo，`?add-to-cart=81`）
2. `.env` 已設定 `WC_API_KEY` / `WC_API_SECRET`
3. PayUni Sandbox 模式已啟用

#### 操作步驟

1. 加入商品至購物車
2. 前往結帳頁（`/checkout/`）
3. 填寫帳單欄位（使用 `BILLING` 預設資料）
4. 選擇 PayUni 信用卡付款方式
5. 等待 SDK iframe 載入
6. 填入卡號 `4147631000000001`、到期日 `1228`、CVC `123`
7. 分期選擇「不分期」（保持預設）
8. 點擊下單按鈕
9. 驗證導向 order-received 頁面
10. 從 URL 擷取 orderId
11. **輪詢等待 webhook**：每 5 秒透過 WC REST API 查詢訂單狀態，最多等 60 秒

#### 預期結果

- 導向 `order-received` 頁面（下單成功）
- 60 秒內訂單狀態變為 `processing` 或 `completed`（webhook 已回調）

---

### T2. 不分期失敗 -- Visa 3D 取消卡 + 不分期

| 項目 | 內容 |
|------|------|
| **案例 ID** | T2 |
| **優先級** | @P0 |
| **類型** | 失敗場景 |
| **卡號** | `4147631000000002`（Visa 3D 取消）|
| **分期** | 不分期 |

#### 前置條件

同 T1。

#### 操作步驟

1. 加入商品至購物車
2. 前往結帳頁
3. 填寫帳單欄位
4. 選擇 PayUni 信用卡付款方式
5. 等待 SDK iframe 載入
6. 填入卡號 `4147631000000002`、到期日 `1228`、CVC `123`
7. 分期選擇「不分期」
8. 點擊下單按鈕
9. 驗證導向 order-received 頁面（3D 取消卡仍會建立訂單並導向 order-received）
10. 從 URL 擷取 orderId
11. **等待 60 秒**（完整超時）
12. 透過 WC REST API 查詢訂單狀態

#### 預期結果

- 導向 `order-received` 頁面（訂單已建立）
- 等待 60 秒後，訂單狀態仍為 `pending`（webhook 未回調，因為 3D 驗證取消，授權未完成）

---

### T3. 分期成功 -- JCB 分期卡 + 3 期

| 項目 | 內容 |
|------|------|
| **案例 ID** | T3 |
| **優先級** | @P1 |
| **類型** | 成功場景 |
| **卡號** | `3560562000000001`（JCB 分期全期數）|
| **分期** | 3 期 |

#### 前置條件

同 T1。

#### 操作步驟

1. 加入商品至購物車
2. 前往結帳頁
3. 填寫帳單欄位
4. 選擇 PayUni 信用卡付款方式
5. 等待 SDK iframe 載入
6. 填入卡號 `3560562000000001`、到期日 `1228`、CVC `123`
7. **選擇分期 3 期**（`select#payuni_installment` → value `3`）
8. 點擊下單按鈕
9. 驗證導向 order-received 頁面
10. 從 URL 擷取 orderId
11. **輪詢等待 webhook**：每 5 秒透過 WC REST API 查詢訂單狀態，最多等 60 秒

#### 預期結果

- 導向 `order-received` 頁面
- 60 秒內訂單狀態變為 `processing` 或 `completed`
- 訂單 meta 包含 `CardInst=3`（可選驗證）

---

### T4. 分期失敗 -- Visa 一次付清卡 + 3 期

| 項目 | 內容 |
|------|------|
| **案例 ID** | T4 |
| **優先級** | @P1 |
| **類型** | 失敗場景 |
| **卡號** | `4147631000000001`（Visa 一次付清，不支援分期）|
| **分期** | 3 期 |

#### 前置條件

同 T1。

#### 操作步驟

1. 加入商品至購物車
2. 前往結帳頁
3. 填寫帳單欄位
4. 選擇 PayUni 信用卡付款方式
5. 等待 SDK iframe 載入
6. 填入卡號 `4147631000000001`、到期日 `1228`、CVC `123`
7. **選擇分期 3 期**
8. 點擊下單按鈕
9. 驗證導向 order-received 頁面（一次付清卡 + 分期會觸發 3D 驗證流程，訂單仍會建立）
10. 從 URL 擷取 orderId
11. **等待 60 秒**（完整超時）
12. 透過 WC REST API 查詢訂單狀態

#### 預期結果

- 導向 `order-received` 頁面（訂單已建立）
- 等待 60 秒後，訂單狀態仍為 `pending`（一次付清卡不支援分期，3D 驗證後授權失敗，webhook 不會回調成功）

---

## 輪詢工具函式規格

### `pollOrderStatus(orderId, options)`

用於成功場景（T1、T3），在超時內輪詢等待訂單狀態變更。

```typescript
interface PollOptions {
  /** 預期的目標狀態（任一匹配即通過） */
  expectedStatuses: string[];  // ['processing', 'completed']
  /** 最大等待時間（毫秒） */
  timeout: number;             // 60_000
  /** 輪詢間隔（毫秒） */
  interval: number;            // 5_000
}

/**
 * 每 interval 毫秒透過 WC REST API 查詢訂單狀態。
 * 若狀態匹配 expectedStatuses 之一，回傳該狀態。
 * 若超過 timeout 仍未匹配，拋出錯誤。
 */
async function pollOrderStatus(
  orderId: string,
  options: PollOptions
): Promise<string>;
```

### `waitAndCheckOrderStatus(orderId, options)`

用於失敗場景（T2、T4），等滿超時後驗證訂單狀態未變更。

```typescript
interface WaitCheckOptions {
  /** 預期的狀態（等待後應仍為此狀態） */
  expectedStatus: string;  // 'pending'
  /** 等待時間（毫秒） */
  waitTime: number;        // 60_000
}

/**
 * 等待 waitTime 毫秒後，透過 WC REST API 查詢訂單狀態。
 * 斷言狀態等於 expectedStatus。
 */
async function waitAndCheckOrderStatus(
  orderId: string,
  options: WaitCheckOptions
): Promise<void>;
```

---

## 測試檔案結構

```
tests/e2e/tests/F-webhook/
  F2-webhook-polling.spec.ts    ← 本規格的 4 個測試案例
```

放在現有的 `F-webhook/` 目錄下，與 `F1-webhook.spec.ts` 並列。F1 是既有的基於 Admin UI 驗證的 webhook 測試；F2 是新增的基於 REST API 輪詢的 webhook 測試。

### 測試標籤

| 案例 | 標籤 |
|------|------|
| T1 | `@P0 @webhook @no-installment @success` |
| T2 | `@P0 @webhook @no-installment @failure` |
| T3 | `@P1 @webhook @installment @success` |
| T4 | `@P1 @webhook @installment @failure` |

---

## 技術依賴

| 依賴 | 用途 | 狀態 |
|------|------|------|
| WC REST API | 輪詢訂單狀態 | 已有（`wc-api.helper.ts`） |
| `CARDS` fixture | 測試卡號 | 已有（`test-data.ts`） |
| `checkout.helper.ts` | 結帳流程輔助 | 已有 |
| `iframe.helper.ts` | SDK iframe 操作 | 已有 |
| `admin.helper.ts` | `extractOrderIdFromUrl` | 已有 |

無需新增任何第三方 library。輪詢函式為純 Playwright + WC REST API 組合，在測試檔案或 helper 中直接實作。

---

## 與現有測試的關係

| 現有測試 | 本規格測試 | 差異 |
|---------|-----------|------|
| F1-1（Admin UI 驗證） | T1（REST API 輪詢） | F1-1 登入後台用 UI 看狀態；T1 用 API 輪詢，更可靠且不需 Admin 登入 |
| D1-7（一次付清卡 + 分期） | T4（一次付清卡 + 分期 + 60s 等待） | D1-7 只驗證 order-received 頁面；T4 額外等 60 秒驗證 pending 狀態 |

本規格的 4 個測試案例是「紅燈測試」-- 先寫測試、後補實作的 TDD 流程起點。測試會先 fail，待 webhook 處理邏輯完善後轉 green。
