# PayUni v3 信用卡 Webhook Order Note 改善規格

> **狀態**：已完成澄清，可進入實作
> **日期**：2026-03-23
> **關聯分支**：`feat/integration-test`

---

## 1. 功能需求總覽

將 PayUni v3 信用卡模組中 4 個 `order_note` 寫入點，從目前的純文字訊息改為結構化 HTML 格式，與 v1（`Response.php`）的備註風格對齊，並額外帶入分期資訊與授權碼。

### 1.1 寫入點清單

| # | 路徑 | 檔案 | 方法 | 情境 | 目前內容 |
|---|------|------|------|------|---------|
| W1 | Webhook 首次成功付款 | `TradeHandler.php` | `update_order_status()` L195 | `$is_webhook === true` 且 `STATUS === 'SUCCESS'` 且訂單尚未付款 | `PayUni Webhook: Payment successful` |
| W2 | 前景授權成功 | `TradeHandler.php` | `update_order_status()` L197 | `$is_webhook === false` 且 `STATUS === 'SUCCESS'` 且訂單尚未付款 | `統一金流 PAYUNi 信用卡付款成功（幕後授權）` |
| W3 | Webhook 到達但訂單已付款（冪等路徑 B） | `TradeHandler.php` | `update_order_status()` L169 | `$order->is_paid() === true` 且 `STATUS === 'SUCCESS'` 且 `$is_webhook === true` | `PayUni Webhook: Payment successful` |
| W4 | Webhook 重複通知（冪等路徑 A） | `Bootstrap.php` | `handle_notify()` L204 | `incoming_trade_no === existing_trade_no` 且 `$order->is_paid()` | `PayUni Webhook: Payment successful` |
| W5 | 付款失敗 | `TradeHandler.php` | `update_order_status()` | `STATUS !== 'SUCCESS'` | `統一金流 PAYUNi 信用卡付款失敗。狀態: %s, 訊息: %s` |

---

## 2. 備註 HTML 範本

### 2.1 成功備註（W1 Webhook 首次成功）

```html
<strong>統一金流 PAYUNi 交易紀錄（Webhook）</strong><br>
狀態碼：SUCCESS<br>
交易訊息：付款成功<br>
交易編號：TRD20260323001<br>
卡號末四碼：1234<br>
授權碼：A12345<br>
分期期數：6<br>
首期金額：300<br>
每期金額：200
```

**規則**：分期欄位（分期期數、首期金額、每期金額）僅在 `CardInst` 大於 1 時顯示（PayUni 一次付清回傳 `"1"`，不視為分期）。

### 2.2 成功備註（W2 前景授權成功）

```html
<strong>統一金流 PAYUNi 交易紀錄（前景授權）</strong><br>
狀態碼：SUCCESS<br>
交易訊息：付款成功<br>
交易編號：TRD20260323001<br>
卡號末四碼：1234<br>
授權碼：A12345<br>
分期期數：6<br>
首期金額：300<br>
每期金額：200
```

**差異**：僅標題不同（「前景授權」vs「Webhook」），其餘結構與欄位完全一致。

### 2.3 成功備註（W3 冪等路徑 B — 訂單已付款，Webhook 補傳資料）

與 W1 相同格式：

```html
<strong>統一金流 PAYUNi 交易紀錄（Webhook）</strong><br>
狀態碼：SUCCESS<br>
交易訊息：付款成功<br>
交易編號：TRD20260323001<br>
卡號末四碼：1234<br>
授權碼：A12345
```

**說明**：此路徑仍寫入完整交易資訊，因為 Webhook 可能補傳前景授權未回傳的欄位（如 TradeNo）。

### 2.4 冪等備註（W4 重複通知）

```
PayUni Webhook：重複通知，已略過
```

**格式**：純文字，不使用 HTML。不帶任何交易細節欄位。

### 2.5 失敗備註（W5）

```html
<strong>統一金流 PAYUNi 信用卡付款失敗</strong><br>
狀態碼：FAILED<br>
交易訊息：餘額不足<br>
交易編號：TRD20260323002<br>
卡號末四碼：5678<br>
授權碼：
```

**規則**：失敗時仍顯示所有基本欄位（即使部分值為空），讓商家了解失敗的交易細節。空值欄位顯示空字串。

---

## 3. 欄位對應表

### 3.1 `$trade_result` Key → 中文標籤

| `$trade_result` Key | 中文標籤 | 類型 | 顯示條件 |
|---------------------|---------|------|---------|
| `Status` | 狀態碼 | 基本 | 必定顯示 |
| `Message` | 交易訊息 | 基本 | 必定顯示 |
| `TradeNo` | 交易編號 | 基本 | 必定顯示 |
| `Card4No` | 卡號末四碼 | 基本 | 必定顯示 |
| `AuthCode` | 授權碼 | 基本 | 必定顯示 |
| `CardInst` | 分期期數 | 分期 | `CardInst` 大於 1（一次付清回傳 `"1"`，不視為分期） |
| `FirstAmt` | 首期金額 | 分期 | 同上（與 `CardInst` 連動） |
| `EachAmt` | 每期金額 | 分期 | 同上（與 `CardInst` 連動） |

### 3.2 欄位順序

備註中欄位嚴格按照上表順序排列：狀態碼 → 交易訊息 → 交易編號 → 卡號末四碼 → 授權碼 → （分期期數 → 首期金額 → 每期金額）。

---

## 4. Helper Method 規格

### 4.1 方法簽章

```php
/**
 * 建構訂單備註 HTML
 *
 * @param string $title      備註標題（如「統一金流 PAYUNi 交易紀錄（Webhook）」）
 * @param array  $trade_result 解密後的交易結果陣列
 *
 * @return string 格式化的 HTML 備註字串
 */
private function build_order_note_html(string $title, array $trade_result): string
```

### 4.2 位置

新增於 `TradeHandler` 類別中（`includes/payuni/v3/Infrastructure/Http/TradeHandler.php`）。

### 4.3 行為定義

1. 以 `<strong>{$title}</strong>` 開頭
2. 依欄位對應表（第 3 節）順序，逐一拼接 `<br>{中文標籤}：{值}`
3. 基本欄位必定輸出（值為空時顯示空字串）
4. 分期欄位僅在 `CardInst` 大於 1（一次付清回傳 `"1"`，不視為分期） 時輸出
5. 回傳完整 HTML 字串

### 4.4 呼叫方式

| 寫入點 | 標題參數 |
|--------|---------|
| W1（Webhook 首次成功） | `統一金流 PAYUNi 交易紀錄（Webhook）` |
| W2（前景授權成功） | `統一金流 PAYUNi 交易紀錄（前景授權）` |
| W3（冪等路徑 B） | `統一金流 PAYUNi 交易紀錄（Webhook）` |
| W4（重複通知） | 不使用此 method，直接寫入純文字 |
| W5（失敗） | `統一金流 PAYUNi 信用卡付款失敗` |

---

## 5. 各寫入點行為定義

### 5.1 W1 — Webhook 首次成功付款

**觸發條件**：`$is_webhook === true` && `$status === 'SUCCESS'` && `!$order->is_paid()`

**行為**：
1. 呼叫 `$order->payment_complete($trade_no)`
2. 呼叫 `build_order_note_html('統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result)`
3. 呼叫 `$order->add_order_note($note_html)`

### 5.2 W2 — 前景授權成功

**觸發條件**：`$is_webhook === false` && `$status === 'SUCCESS'` && `!$order->is_paid()`

**行為**：
1. 呼叫 `$order->payment_complete($trade_no)`
2. 呼叫 `build_order_note_html('統一金流 PAYUNi 交易紀錄（前景授權）', $trade_result)`
3. 呼叫 `$order->add_order_note($note_html)`

### 5.3 W3 — 冪等路徑 B（訂單已付款，Webhook 補傳）

**觸發條件**：`$order->is_paid()` && `$status === 'SUCCESS'` && `$is_webhook === true`

**行為**：
1. 不呼叫 `payment_complete()`（已付款）
2. 呼叫 `build_order_note_html('統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result)`
3. 呼叫 `$order->add_order_note($note_html)`
4. 繼續執行 token 儲存邏輯（不變）

### 5.4 W4 — 重複通知冪等路徑 A（Bootstrap）

**觸發條件**：`$incoming_trade_no === $existing_trade_no` && `$order->is_paid()`

**行為**：
1. 呼叫 `$order->add_order_note('PayUni Webhook：重複通知，已略過')`
2. 回傳 `wp_send_json_success()`（不進入 `update_order_status()`）

### 5.5 W5 — 付款失敗

**觸發條件**：`$status !== 'SUCCESS'`

**行為**：
1. 呼叫 `build_order_note_html('統一金流 PAYUNi 信用卡付款失敗', $trade_result)`
2. 呼叫 `$order->update_status('failed', $note_html)`

**注意**：`update_status()` 的第二個參數即為 order note 內容，不需額外呼叫 `add_order_note()`。

---

## 6. 驗收標準

### AC-1：Webhook 首次成功付款備註格式正確

- **Given** 訂單 100 尚未付款
- **When** PayUni Webhook 到達，解密後 `Status = "SUCCESS"`
- **Then** 訂單 100 的 order_note 包含：
  - `<strong>統一金流 PAYUNi 交易紀錄（Webhook）</strong>` 作為標題
  - `狀態碼：SUCCESS`
  - `交易訊息：` 後接具體訊息
  - `交易編號：` 後接 TradeNo
  - `卡號末四碼：` 後接 Card4No
  - `授權碼：` 後接 AuthCode

### AC-2：分期欄位條件顯示

- **Given** 訂單 100 尚未付款
- **When** PayUni Webhook 到達，解密後含 `CardInst = "6"`, `FirstAmt = "300"`, `EachAmt = "200"`
- **Then** 訂單 100 的 order_note 額外包含：
  - `分期期數：6`
  - `首期金額：300`
  - `每期金額：200`

### AC-3：非分期交易不顯示分期欄位

- **Given** 訂單 100 尚未付款
- **When** PayUni Webhook 到達，解密後 `CardInst = "0"` 或 `CardInst` 為空
- **Then** 訂單 100 的 order_note 不包含「分期期數」、「首期金額」、「每期金額」

### AC-4：前景授權成功備註標題正確

- **Given** 訂單 100 尚未付款
- **When** `process_payment` 前景授權回應 `Status = "SUCCESS"`
- **Then** 訂單 100 的 order_note 包含 `<strong>統一金流 PAYUNi 交易紀錄（前景授權）</strong>`
- **And** 其餘欄位結構與 Webhook 成功一致

### AC-5：冪等路徑 B 備註格式正確

- **Given** 訂單 100 已由 `process_payment` 完成付款
- **When** PayUni Webhook 到達，`Status = "SUCCESS"`
- **Then** 訂單 100 的 order_note 包含 `<strong>統一金流 PAYUNi 交易紀錄（Webhook）</strong>` 及完整交易細節
- **And** 不重複呼叫 `payment_complete()`

### AC-6：重複通知冪等路徑 A 備註為簡單標記

- **Given** 訂單 100 已付款且 `_payuni_resp_trade_no = "TRD001"`
- **When** 相同 `TradeNo = "TRD001"` 的 Webhook 再次到達
- **Then** 訂單 100 的 order_note 為 `PayUni Webhook：重複通知，已略過`
- **And** 不包含任何 HTML 標記或交易細節

### AC-7：失敗備註格式正確

- **Given** 訂單 100 尚未付款
- **When** PayUni Webhook 到達，`Status = "FAILED"`
- **Then** 訂單 100 的 order_note 包含 `<strong>統一金流 PAYUNi 信用卡付款失敗</strong>` 及交易細節
- **And** 訂單狀態變更為 `failed`

### AC-8：Helper method 被所有寫入點使用（W4 除外）

- W1, W2, W3, W5 的備註 HTML 均由 `build_order_note_html()` 生成
- 不存在手動拼接 HTML 的寫入點（W4 為純文字，不在此限）

---

## 7. 影響範圍

### 7.1 需修改的檔案

| 檔案 | 修改內容 |
|------|---------|
| `includes/payuni/v3/Infrastructure/Http/TradeHandler.php` | 1. 新增 `build_order_note_html()` private method<br>2. 修改 `update_order_status()` 中 W1/W2/W3/W5 四個寫入點的備註生成邏輯 |
| `includes/payuni/v3/Bootstrap.php` | 修改 `handle_notify()` 中 W4 冪等路徑的備註文字（從 `PayUni Webhook: Payment successful` 改為 `PayUni Webhook：重複通知，已略過`） |

### 7.2 不受影響的檔案

- PayUni v1（`includes/payuni/src/`）：不在此次改動範圍
- 其他金流模組（ECPay、PayNow、LinePay 等）：不受影響
- E2E 測試：若有斷言 order note 內容的測試案例需同步更新
- 前端 JS/CSS：不受影響

### 7.3 向下相容性

- 此變更僅影響 order_note 的顯示文字，不影響訂單狀態流轉邏輯
- 不影響 `_payuni_v3_resp` 等 meta 的儲存
- 不影響 `payment_complete()` 的呼叫時機
- 不影響 token 儲存邏輯

---

## 8. 技術備註

### 8.1 v1 對齊參考

v1 信用卡成功備註格式（`Response.php` L151-153）：

```php
"<strong>統一金流交易紀錄</strong><br>狀態碼：{$status}<br>交易訊息：{$message}<br>交易編號：{$trade_no}<br>卡號末四碼：{$card_4no}"
```

v3 在此基礎上增加：
- 標題區分來源（Webhook / 前景授權）
- 新增授權碼（`AuthCode`）
- 新增分期欄位（`CardInst`、`FirstAmt`、`EachAmt`）

### 8.2 `$trade_result` 欄位可用性

根據 `TradeHandler::update_order_status()` 的 PHPDoc，`$trade_result` 陣列包含以下與本規格相關的 key：

```
Status, Message, TradeNo, Card4No, AuthCode, CardInst, FirstAmt, EachAmt
```

所有 key 在 Webhook 與前景授權兩種路徑下均可取得（但前景授權路徑可能缺少 `TradeNo`，此時顯示空字串）。

### 8.3 冪等路徑區分

| 路徑 | 位置 | 判斷條件 | 備註行為 |
|------|------|---------|---------|
| 冪等 A（Bootstrap） | `Bootstrap::handle_notify()` | `incoming_trade_no === existing_trade_no && $order->is_paid()` | 純文字「重複通知，已略過」 |
| 冪等 B（TradeHandler） | `TradeHandler::update_order_status()` | `$order->is_paid() && $status === 'SUCCESS'` | 完整 HTML 備註（因為可能有新的交易資料需記錄） |
