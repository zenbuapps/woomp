# PayUni v3 order_note 重構 -- LABEL_MAP 驅動全欄位輸出

> **狀態**：已完成澄清，可進入實作
> **日期**：2026-03-23
> **關聯分支**：`feat/integration-test`
> **前置規格**：`specs/features/payuni/webhook-order-note.feature`（本次為其增量修改）

---

## 1. 變更摘要

將 `TradeHandler::build_order_note_html()` 從硬編碼 5+3 個欄位的清單，重構為 **LABEL_MAP 驅動**的全欄位輸出機制：

- 使用類別常數 `LABEL_MAP` 定義所有已知 key 到中文 label 的映射
- 遍歷整個 `$trade_result` array，有值才印、空值不印
- 已知 key 顯示中文 label，未知 key 直接顯示原始 key name
- 分期欄位群組條件保留（`CardInst > 1` 才顯示 CardInst/FirstAmt/EachAmt）
- 不排除任何欄位，管理員需要完整可見性

**方法簽名不變**，4 個呼叫處不受影響。

---

## 2. 決策紀錄

| # | 問題 | 決策 | 說明 |
|---|------|------|------|
| Q1 | 欄位輸出策略 | 印出整個 `$trade_result` array | 用 LABEL_MAP 將已知 key 轉中文 label，未知 key 直接顯示原始 key name |
| Q2 | 空值處理 | A — `isset($v) && $v !== ''` | 空字串欄位不顯示，減少備註雜訊 |
| Q3 | 分期欄位群組 | A — 保留 `CardInst > 1` 群組條件 | CardInst/FirstAmt/EachAmt 三個欄位仍以群組方式控制，僅分期交易才顯示，其餘走通用邏輯 |
| Q4 | LABEL_MAP 存放方式 | A — 類別常數 `private const LABEL_MAP` | 放在 TradeHandler 類別頂部，與現有 `TIMEOUT`/`USER_AGENT` 常數並列 |
| Q5 | 欄位顯示順序 | A — LABEL_MAP 定義順序優先 | 先印已知欄位（按 MAP 中的定義順序），再印不在 MAP 裡的未知 key（未知 key 之間按 `$trade_result` 原始順序） |
| Q6 | 欄位排除策略 | B — 不排除任何欄位 | 全部印出，管理員需要完整可見性 |

---

## 3. LABEL_MAP 完整定義

```php
private const LABEL_MAP = [
    'Status'        => '狀態碼',
    'Message'       => '交易訊息',
    'MerID'         => '商店代號',
    'MerTradeNo'    => '商店訂單編號',
    'Gateway'       => '付款方式',
    'TradeNo'       => '交易編號',
    'TradeAmt'      => '交易金額',
    'TradeStatus'   => '交易狀態',
    'PaymentType'   => '付款類型',
    'CardBank'      => '發卡銀行代碼',
    'Card6No'       => '卡號前六碼',
    'Card4No'       => '卡號末四碼',
    'CardInst'      => '分期期數',
    'FirstAmt'      => '首期金額',
    'EachAmt'       => '每期金額',
    'ResCode'       => '回應碼',
    'ResCodeMsg'    => '回應訊息',
    'AuthCode'      => '授權碼',
    'AuthBank'      => '收單銀行代碼',
    'AuthBankName'  => '收單銀行名稱',
    'AuthType'      => '授權類型',
    'AuthDay'       => '授權日期',
    'AuthTime'      => '授權時間',
    'CreditHash'    => '信用卡 Hash',
    'CreditLife'    => '有效期限',
    'CoBrandCode'   => '聯名卡代碼',
];
```

**共 26 個 key**，涵蓋 PHPDoc 中列出的全部已知欄位（含 3 個可選欄位 CreditHash、CreditLife、CoBrandCode）。

**位置**：`TradeHandler` 類別頂部，與現有常數並列：

```php
final class TradeHandler
{
    private const TIMEOUT = 60;
    private const USER_AGENT = 'payuni';
    private const LABEL_MAP = [
        // ... 如上
    ];
```

---

## 4. 分期欄位群組條件邏輯

分期欄位（CardInst、FirstAmt、EachAmt）不走通用的「有值就印」邏輯，而是受群組條件控制：

```
分期群組欄位 = ['CardInst', 'FirstAmt', 'EachAmt']

IF CardInst 存在 AND (int)CardInst > 1 THEN
    CardInst、FirstAmt、EachAmt 進入通用印出流程（有值才印）
ELSE
    CardInst、FirstAmt、EachAmt 一律跳過，不印出
END IF
```

**判斷依據**：
- PayUni 一次付清回傳 `CardInst = "1"` 或 `"0"` 或空字串
- 只有 `CardInst > 1`（如 `"3"`、`"6"`、`"12"`）才是真正的分期交易
- 此條件與現有實作一致，不做變更

---

## 5. 顯示順序演算法（虛擬碼）

```
FUNCTION build_order_note_html(title, trade_result):
    html = "<strong>" + escape(title) + "</strong>"

    // 判斷是否為分期交易
    card_inst = trade_result['CardInst'] ?? ''
    is_installment = card_inst !== '' AND (int)card_inst > 1
    installment_keys = ['CardInst', 'FirstAmt', 'EachAmt']

    // 收集已處理的 key（用於後續排除）
    processed_keys = []

    // Phase 1: 按 LABEL_MAP 定義順序印出已知欄位
    FOR EACH (key, label) IN LABEL_MAP:
        processed_keys[] = key

        // 分期群組欄位特殊處理
        IF key IN installment_keys AND NOT is_installment:
            CONTINUE  // 非分期交易，跳過分期欄位

        value = trade_result[key] ?? null

        // 空值過濾
        IF NOT isset(value) OR value === '':
            CONTINUE

        html += "<br>" + escape(label) + "：" + escape(value)

    // Phase 2: 印出不在 LABEL_MAP 中的未知 key（按 trade_result 原始順序）
    FOR EACH (key, value) IN trade_result:
        IF key IN processed_keys:
            CONTINUE

        // 空值過濾
        IF NOT isset(value) OR value === '':
            CONTINUE

        html += "<br>" + escape(key) + "：" + escape(value)

    RETURN html
```

**關鍵特性**：
1. Phase 1 保證已知欄位按 LABEL_MAP 中的定義順序輸出（而非 `$trade_result` 的原始順序）
2. Phase 2 確保未來 PayUni 新增的欄位不會被遺漏
3. 所有值皆通過 `esc_html()` 跳脫

---

## 6. 呼叫處不受影響的確認

`build_order_note_html()` 的方法簽名不變：

```php
private function build_order_note_html( string $title, array $trade_result ): string
```

4 個呼叫處的程式碼不需任何修改：

| 寫入點 | 程式碼位置 | 呼叫程式碼 | 變更 |
|--------|-----------|-----------|------|
| W1 Webhook 首次成功 | `TradeHandler.php` L195 | `$this->build_order_note_html('統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result)` | 無 |
| W2 前景授權成功 | `TradeHandler.php` L197 | `$this->build_order_note_html('統一金流 PAYUNi 交易紀錄（前景授權）', $trade_result)` | 無 |
| W3 冪等路徑 B | `TradeHandler.php` L169 | `$this->build_order_note_html('統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result)` | 無 |
| W5 付款失敗 | `TradeHandler.php` L210 | `$this->build_order_note_html('統一金流 PAYUNi 信用卡付款失敗', $trade_result)` | 無 |

W4（Bootstrap 冪等路徑 A）本來就不使用此方法，維持純文字 `'PayUni Webhook：重複通知，已略過'`，不受影響。

---

## 7. 預期的 order_note HTML 輸出範例

### 7.1 成功 -- Webhook 一次付清（非分期）

**輸入**：

```php
$title = '統一金流 PAYUNi 交易紀錄（Webhook）';
$trade_result = [
    'Status'       => 'SUCCESS',
    'Message'      => '付款成功',
    'MerID'        => 'M12345678',
    'MerTradeNo'   => '202603230001',
    'Gateway'      => 'credit',
    'TradeNo'      => 'TRD20260323001',
    'TradeAmt'     => '1500',
    'TradeStatus'  => '1',
    'PaymentType'  => 'CREDIT',
    'CardBank'     => '812',
    'Card6No'      => '411111',
    'Card4No'      => '1234',
    'CardInst'     => '0',
    'FirstAmt'     => '',
    'EachAmt'      => '',
    'ResCode'      => '00',
    'ResCodeMsg'   => '交易成功',
    'AuthCode'     => 'A12345',
    'AuthBank'     => '812',
    'AuthBankName' => '台新銀行',
    'AuthType'     => '1',
    'AuthDay'      => '20260323',
    'AuthTime'     => '143022',
];
```

**輸出**：

```html
<strong>統一金流 PAYUNi 交易紀錄（Webhook）</strong><br>
狀態碼：SUCCESS<br>
交易訊息：付款成功<br>
商店代號：M12345678<br>
商店訂單編號：202603230001<br>
付款方式：credit<br>
交易編號：TRD20260323001<br>
交易金額：1500<br>
交易狀態：1<br>
付款類型：CREDIT<br>
發卡銀行代碼：812<br>
卡號前六碼：411111<br>
卡號末四碼：1234<br>
回應碼：00<br>
回應訊息：交易成功<br>
授權碼：A12345<br>
收單銀行代碼：812<br>
收單銀行名稱：台新銀行<br>
授權類型：1<br>
授權日期：20260323<br>
授權時間：143022
```

**注意**：CardInst=0，所以分期期數/首期金額/每期金額三個欄位皆不顯示。FirstAmt 和 EachAmt 雖然是空字串但因群組條件已跳過，不影響結果。

---

### 7.2 成功 -- 前景授權 + 分期 6 期

**輸入**：

```php
$title = '統一金流 PAYUNi 交易紀錄（前景授權）';
$trade_result = [
    'Status'       => 'SUCCESS',
    'Message'      => '付款成功',
    'MerID'        => 'M12345678',
    'MerTradeNo'   => '202603230002',
    'Gateway'      => 'credit',
    'TradeNo'      => '',            // 前景授權可能沒有 TradeNo
    'TradeAmt'     => '6000',
    'TradeStatus'  => '1',
    'PaymentType'  => 'CREDIT',
    'CardBank'     => '812',
    'Card6No'      => '411111',
    'Card4No'      => '5678',
    'CardInst'     => '6',
    'FirstAmt'     => '1100',
    'EachAmt'      => '980',
    'ResCode'      => '00',
    'ResCodeMsg'   => '交易成功',
    'AuthCode'     => 'B67890',
    'AuthBank'     => '812',
    'AuthBankName' => '台新銀行',
    'AuthType'     => '1',
    'AuthDay'      => '20260323',
    'AuthTime'     => '150000',
];
```

**輸出**：

```html
<strong>統一金流 PAYUNi 交易紀錄（前景授權）</strong><br>
狀態碼：SUCCESS<br>
交易訊息：付款成功<br>
商店代號：M12345678<br>
商店訂單編號：202603230002<br>
付款方式：credit<br>
交易金額：6000<br>
交易狀態：1<br>
付款類型：CREDIT<br>
發卡銀行代碼：812<br>
卡號前六碼：411111<br>
卡號末四碼：5678<br>
分期期數：6<br>
首期金額：1100<br>
每期金額：980<br>
回應碼：00<br>
回應訊息：交易成功<br>
授權碼：B67890<br>
收單銀行代碼：812<br>
收單銀行名稱：台新銀行<br>
授權類型：1<br>
授權日期：20260323<br>
授權時間：150000
```

**注意**：TradeNo 為空字串所以「交易編號」不顯示。CardInst=6 > 1 所以分期三欄位皆顯示。

---

### 7.3 失敗 -- 餘額不足

**輸入**：

```php
$title = '統一金流 PAYUNi 信用卡付款失敗';
$trade_result = [
    'Status'       => 'FAILED',
    'Message'      => '餘額不足',
    'MerID'        => 'M12345678',
    'MerTradeNo'   => '202603230003',
    'Gateway'      => 'credit',
    'TradeNo'      => 'TRD20260323002',
    'TradeAmt'     => '1500',
    'TradeStatus'  => '0',
    'PaymentType'  => 'CREDIT',
    'CardBank'     => '',
    'Card6No'      => '',
    'Card4No'      => '5678',
    'CardInst'     => '0',
    'FirstAmt'     => '',
    'EachAmt'      => '',
    'ResCode'      => '51',
    'ResCodeMsg'   => '餘額不足',
    'AuthCode'     => '',
    'AuthBank'     => '',
    'AuthBankName' => '',
    'AuthType'     => '',
    'AuthDay'      => '',
    'AuthTime'     => '',
];
```

**輸出**：

```html
<strong>統一金流 PAYUNi 信用卡付款失敗</strong><br>
狀態碼：FAILED<br>
交易訊息：餘額不足<br>
商店代號：M12345678<br>
商店訂單編號：202603230003<br>
付款方式：credit<br>
交易編號：TRD20260323002<br>
交易金額：1500<br>
交易狀態：0<br>
付款類型：CREDIT<br>
卡號末四碼：5678<br>
回應碼：51<br>
回應訊息：餘額不足
```

**注意**：
- CardBank、Card6No、AuthCode、AuthBank、AuthBankName、AuthType、AuthDay、AuthTime 皆為空字串，全部不顯示
- CardInst=0 所以分期三欄位不顯示（群組條件跳過）
- 只有真正有值的 12 個欄位出現在備註中

---

## 8. 影響範圍

### 8.1 需修改的檔案

| 檔案 | 修改內容 |
|------|---------|
| `includes/payuni/v3/Infrastructure/Http/TradeHandler.php` | 1. 新增 `private const LABEL_MAP` 類別常數<br>2. 重寫 `build_order_note_html()` 方法體（簽名不變） |

### 8.2 不需修改的檔案

| 檔案 | 原因 |
|------|------|
| `includes/payuni/v3/Bootstrap.php` | W4 冪等路徑不使用 `build_order_note_html()`，不受影響 |
| `TradeHandler::update_order_status()` | 4 個呼叫處的程式碼行不需改動，簽名與標題參數皆相同 |
| PayUni v1（`includes/payuni/src/`） | 不在此次改動範圍 |
| 其他金流模組（ECPay、PayNow、LinePay 等） | 不受影響 |
| 前端 JS/CSS | 不受影響 |

### 8.3 向下相容性

- 此變更僅影響 order_note 的顯示文字內容（從 5 個欄位擴展為全欄位）
- 不影響訂單狀態流轉邏輯
- 不影響 `_payuni_v3_resp` 等 meta 的儲存
- 不影響 `payment_complete()` 的呼叫時機
- 不影響 token 儲存邏輯

### 8.4 與前置規格的差異

本規格取代 `webhook-order-note-spec.md` 中第 3 節（欄位對應表）和第 4 節（Helper Method 規格）的定義。其餘部分（寫入點清單、各路徑觸發條件、W4 冪等處理）維持不變。

---

## 9. 技術備註

### 9.1 效能考量

`LABEL_MAP` 為 `private const`，PHP 8.0+ 支援類別常數陣列。常數在編譯期解析，無運行時 overhead。

### 9.2 未來維護

PayUni 若新增回傳欄位：
- **已知欄位**：只需在 `LABEL_MAP` 中新增一行 `'NewKey' => '中文標籤'`
- **未知欄位**：無需改 code，自動以原始 key name 顯示

### 9.3 安全性

所有輸出皆通過 `\esc_html()` 跳脫，符合 WordPress 安全編碼標準。
