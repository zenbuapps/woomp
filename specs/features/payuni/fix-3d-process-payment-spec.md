# PayUni v3 3D 驗證流程 process_payment 誤判修正規格

> **狀態**：已完成澄清，可進入實作
> **日期**：2026-03-23
> **關聯分支**：`feat/integration-test`
> **澄清紀錄**：`specs/clarify/2026-03-23-1600.md`

---

## 1. Bug 描述

### 1.1 重現步驟

1. 環境：PayUni **sandbox** 測試環境
2. 使用一次付清測試卡號 `4147631000000001`
3. 在結帳頁選擇**分期付款**（該卡號不支援分期）
4. 完成結帳提交

### 1.2 現象

| # | 現象 | 預期行為 |
|---|------|---------|
| B1 | 訂單狀態變為「處理中」或「完成」 | 應維持「等待付款中」 |
| B2 | Webhook 的 order_note 不顯示 | 應顯示 Webhook 交易結果備註 |

### 1.3 根因分析

`CreditV3::process_payment()` 在呼叫 PayUni `/iframe/merchant_trade` 後，依回應是否包含 `EncryptInfo` 分流：

```php
// 現有邏輯（CreditV3.php L347-354）
if (! empty($raw_response['EncryptInfo'])) {
    $trade_result = $handler->process_notify($raw_response);  // 直接授權流程
} else {
    $trade_result = $raw_response;  // 3D 流程：raw response
}

// ★ 問題在這裡：3D 流程也呼叫了 update_order_status()
$handler->update_order_status($order, $trade_result);
```

3D 驗證流程中，PayUni 回傳的 `raw_response` 不含 `EncryptInfo`，但包含：
- `Status = "SUCCESS"` -- 僅代表「建立幕後 3D 驗證成功」
- `Message = "建立幕後3D成功"`
- `TradeNo`、`Card4No`、`AuthCode` 均為空

`update_order_status()` 收到 `Status = "SUCCESS"` 後，呼叫 `payment_complete()`，將訂單標記為已付款。但此時交易根本尚未完成，真正的交易結果應由後續 Webhook 回傳。

### 1.4 連鎖影響

由於 `payment_complete()` 已執行，訂單狀態變為「處理中」：
- Webhook 到達時，`$order->is_paid()` 為 `true`，進入冪等路徑 B
- 冪等路徑 B 的 order_note 雖然會寫入，但 **在訂單已付款的情境下，交易實際上失敗**（分期不支援），造成狀態不一致
- 使用者觀察到「order_note 沒顯示」可能是因為 Webhook 根本沒有成功到達（分期失敗時 PayUni 可能不發送 Webhook）

---

## 2. 修正方案

### 2.1 核心原則

3D 流程（`raw_response` 不含 `EncryptInfo`）時，`process_payment()` **不呼叫 `update_order_status()`**，訂單維持「等待付款中」，由後續 Webhook 決定最終訂單狀態。

### 2.2 3D 流程新行為

當 `raw_response` 不含 `EncryptInfo` 時：

1. **儲存 meta**：`$order->update_meta_data('_payuni_v3_resp', $raw_response)` -- 記錄 3D 建立回應
2. **寫 order_note**：記錄「3D 驗證已建立，等待 webhook 回傳交易結果」，附帶 `Status` 和 `Message`
3. **不呼叫** `update_order_status()` -- 訂單狀態不變，維持「等待付款中」
4. **不呼叫** `OrderUtils::delete_tmp_data()` -- 暫存資料保留，供 Webhook 處理時使用
5. **回傳** `result: 'success'` + `redirect: order_received_url` -- 讓前端導向訂單接收頁

### 2.3 Order Note 格式

```
3D 驗證已建立，等待 webhook 回傳交易結果（狀態碼：SUCCESS，交易訊息：建立幕後3D成功）
```

純文字格式，不使用 HTML。僅記錄 `Status` 和 `Message` 兩個欄位。

---

## 3. 修改範圍

### 3.1 需修改的檔案

| 檔案 | 修改內容 |
|------|---------|
| `includes/payuni/src/gateways/CreditV3.php` | 修改 `process_payment()` 方法，在 3D 流程分支中不呼叫 `update_order_status()`，改為儲存 meta + 寫 order_note |

### 3.2 不受影響的檔案

- `TradeHandler.php` -- `update_order_status()` 方法本身不需修改
- `Bootstrap.php` -- Webhook 處理邏輯不需修改（Webhook 仍正常呼叫 `update_order_status()`）
- 前端 JS -- 不受影響
- 其他金流模組 -- 不受影響

### 3.3 向下相容性

- 非 3D 直接授權流程（`raw_response` 含 `EncryptInfo`）行為完全不變
- Webhook 處理邏輯不變
- 冪等性保護不受影響（3D 流程訂單維持「等待付款中」，Webhook 到達時會正常進入首次付款路徑）

---

## 4. 修正後的 process_payment 虛擬碼

```
function process_payment($order_id):
    $order = wc_get_order($order_id)

    try:
        $request_body = TradeReqDTO::of($order)->to_array()
        $handler = new TradeHandler()
        $raw_response = $handler->execute_trade($request_body)

        if raw_response 含 EncryptInfo:
            // 非 3D 直接授權流程（原有邏輯不變）
            $trade_result = $handler->process_notify($raw_response)
            $handler->update_order_status($order, $trade_result)
        else:
            // ★ 3D 流程：不呼叫 update_order_status()
            $order->update_meta_data('_payuni_v3_resp', $raw_response)
            $order->add_order_note(
                "3D 驗證已建立，等待 webhook 回傳交易結果"
                + "（狀態碼：{Status}，交易訊息：{Message}）"
            )
            $order->save()

        return { result: 'success', redirect: order_received_url, order_id }

    catch (Throwable $e):
        log error
        wc_add_notice(error_message, 'error')
        return { result: 'failure' }
```

---

## 5. 驗收標準

### AC-1：3D 流程不改變訂單狀態

- **Given** 訂單 1001 狀態為「等待付款中」
- **And** PayUni 3D 驗證已啟用
- **When** `process_payment` 執行，PayUni 回傳不含 `EncryptInfo` 的回應（`Status = "SUCCESS"`，`Message = "建立幕後3D成功"`）
- **Then** 訂單 1001 的狀態應維持「等待付款中」
- **And** 不應呼叫 `payment_complete()`
- **And** 不應呼叫 `$order->update_status('failed')`

### AC-2：3D 流程儲存 meta

- **Given** 同 AC-1 前置條件
- **When** `process_payment` 執行，PayUni 回傳 3D 建立回應
- **Then** 訂單 1001 的 `_payuni_v3_resp` meta 應包含 3D 建立回應的完整內容

### AC-3：3D 流程寫入 order_note

- **Given** 同 AC-1 前置條件
- **When** `process_payment` 執行，PayUni 回傳 `Status = "SUCCESS"`、`Message = "建立幕後3D成功"`
- **Then** 訂單 1001 的 order_note 應包含「3D 驗證已建立，等待 webhook 回傳交易結果」
- **And** order_note 應包含「狀態碼：SUCCESS」
- **And** order_note 應包含「交易訊息：建立幕後3D成功」

### AC-4：3D 流程不清除暫存資料

- **Given** 訂單 1001 有暫存付款資料（sdk_token_tmp, payuni_save_card 等）
- **When** `process_payment` 執行 3D 流程
- **Then** 訂單 1001 的暫存付款資料應仍然存在

### AC-5：3D 流程回傳 success 並導向 order_received

- **Given** 同 AC-1 前置條件
- **When** `process_payment` 執行 3D 流程
- **Then** 回傳值的 `result` 應為 `'success'`
- **And** 回傳值的 `redirect` 應為 `$order->get_checkout_order_received_url()`

### AC-6：非 3D 直接授權流程行為不變

- **Given** 訂單 1001 狀態為「等待付款中」
- **When** `process_payment` 執行，PayUni 回傳包含 `EncryptInfo` 的回應（直接授權）
- **Then** 行為與修正前完全一致（呼叫 `process_notify` + `update_order_status`）

### AC-7：3D 流程後 Webhook 正常處理

- **Given** 訂單 1001 狀態為「等待付款中」（3D 流程已執行，未呼叫 `update_order_status`）
- **When** PayUni Webhook 到達，解密後 `Status = "SUCCESS"`
- **Then** Webhook 正常進入首次付款路徑（非冪等路徑）
- **And** 呼叫 `payment_complete()`
- **And** 訂單狀態變為「處理中」
- **And** order_note 包含完整交易紀錄

### AC-8：3D 流程後 Webhook 通知交易失敗

- **Given** 訂單 1001 狀態為「等待付款中」（3D 流程已執行）
- **When** PayUni Webhook 到達，解密後 `Status` 非 `"SUCCESS"`
- **Then** 訂單狀態變為「失敗」
- **And** order_note 包含失敗交易紀錄

---

## 6. 修正連帶解決的問題

此次修正同時解決使用者回報的兩個問題：

| # | 問題 | 解決原因 |
|---|------|---------|
| B1 | 訂單狀態錯誤（處理中/完成） | 3D 流程不再呼叫 `payment_complete()`，訂單維持「等待付款中」 |
| B2 | Webhook order_note 不顯示 | 3D 流程不再將訂單標為已付款，Webhook 到達時進入首次付款路徑，正常寫入完整交易紀錄備註。若交易失敗（如分期不支援），Webhook 會寫入失敗備註 |

---

## 7. 測試建議

### 7.1 手動測試

1. **3D 流程 + 分期失敗**：使用卡號 `4147631000000001` + 分期付款，確認訂單維持「等待付款中」
2. **3D 流程 + 付款成功**：使用支援分期的卡號 `3560562000000001` + 分期付款，確認 Webhook 後訂單變為「處理中」
3. **非 3D 直接授權**：正常一次付清流程，確認行為不受影響

### 7.2 E2E 測試案例參考

新增 E2E 測試案例至 `specs/payuni-embed/e2e-test-cases.md`：

| # | 案例 | 卡號 | 流程 | 預期結果 |
|---|------|------|------|---------|
| D1-7 | 一次付清卡號 + 選分期（3D 觸發後失敗） | `4147631000000001` | 選分期 3 期 → 下單 | 訂單維持「等待付款中」，order_note 含「3D 驗證已建立」 |
