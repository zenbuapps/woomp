# Woomp 規格文件（反向工程產出）

從 woomp codebase 反向工程產出的完整規格文件。

## 產出日期

2026-03-15

## 檔案結構

```
specs/
├── *.activity          # Activity 規格（34 個，每個主要功能模組一個）
├── *.feature           # Gherkin Feature 規格（34 個）
├── api.yml             # OpenAPI 3.0 規格（所有回調/AJAX 端點）
├── erm.dbml            # Entity Relationship Model（DBML 格式）
└── README.md           # 本文件
```

## 模組覆蓋

| 模組 | Activity | Feature | 說明 |
|------|----------|---------|------|
| 核心 | 7 | 7 | 初始化、設定、結帳、商品、訂單、郵件 |
| PayUni | 10 | 10 | v1 信用卡、v3 Embed、分期、訂閱、ATM、CVS、退款、記憶卡號、通知、前端 SDK |
| 綠界 | 5 | 5 | 金流、物流、發票開立、作廢、折讓 |
| 藍新 | 2 | 2 | 金流、物流 |
| 速買配 | 1 | 1 | 金流 |
| 立吉富 | 3 | 3 | 金流、物流、發票 |
| LINE Pay | 1 | 1 | 金流 |
| 支付連 | 1 | 1 | 金流 |
| EZPAY 發票 | 2 | 2 | 開立、作廢 |
| 發票共用 | 2 | 2 | 載具、設定 |

## 關鍵發現

### 1. 綠界/EZPAY 折讓功能未實作

綠界發票 SDK（EcpayInvoice）定義了 `ALLOWANCE`、`ALLOWANCE_VOID` 等方法常數，`EcpayInvoice::Send` 也預留了 `AllowanceNotify`、`AllowanceAmount` 等欄位。然而 `EcpayInvoiceHandler` 與 UI 層均未實作折讓功能。

EZPAY 同理，`EzPayInvoiceHandler` 僅有 `generate_invoice()` 與 `invalid_invoice()`，無折讓方法。

**影響**：商家若需要部分退款開折讓單，目前必須手動到各服務商後台操作。

### 2. PayUni CVS 回應處理 Early Return Bug

`src/gateways/Cvs.php` 的 `cvs_response` 方法中有 early return 邏輯，導致超商取號回應的訂單 meta 不會被正確儲存。

**影響**：可能造成訂單缺少繳費代碼等關鍵資訊。

### 3. PayUni v3 發票載具整合模式與其他服務商不同

PayUni v3 將發票載具作為交易參數（`CarrierType` / `CarrierInfo`）直接傳給金流 API，由 PayUni 端負責開立發票。其他三家（綠界、EZPAY、立吉富）則是在付款完成後，由外掛獨立呼叫各自的發票 API 開立。

**影響**：架構上 PayUni v3 的發票與金流緊耦合，無法像其他服務商一樣獨立選擇發票服務商。

### 4. 立吉富悠遊卡載具（獨有）

立吉富電子發票支援悠遊卡載具類型，其他服務商（綠界、EZPAY、PayUni）均不支援。

### 5. 四家服務商的載具類型差異

| 載具類型 | 綠界 | EZPAY | 立吉富 | PayUni v3 |
|----------|------|-------|--------|-----------|
| 手機條碼 | ✅ | ✅ | ✅ | ✅ (3J0002) |
| 自然人憑證 | ✅ | ✅ | ✅ | ✅ (CQ0001) |
| 雲端/會員載具 | ✅ | ✅ | ✅ | ✅ (amego) |
| 悠遊卡 | ❌ | ❌ | ✅ | ❌ |
| 捐贈碼 | ✅ | ✅ | ✅ | ✅ (Donate) |
| 公司發票 | ✅ | ✅ | ✅ | ✅ (Company) |

### 6. 全專案無自訂資料表

woomp 完全使用 WordPress/WooCommerce 原生的 meta 系統（`wp_options`、`wp_postmeta` / `wc_orders_meta`、`wc_payment_tokens`）儲存資料，未建立任何自訂資料表（未發現 `CREATE TABLE` / `dbDelta` 調用）。

### 7. 加解密規格

- **PayUni v1/v3**：AES-256-GCM 加密 + SHA-256 雜湊，格式 `bin2hex(encrypted_data + ':::' + base64(tag))`
- **綠界**：CheckMacValue SHA256 驗證
- **藍新**：AES-256-CBC 加密 + SHA256 TradeSha 驗證
- **速買配**：自訂驗證碼（偶奇數位演算法）
- **立吉富金流**：SHA1 PassCode
- **立吉富物流**：DES-EDE3 加密
- **LINE Pay**：HMAC-SHA256 簽章
- **支付連**：Token-based Basic Auth

### 8. PayUni v3 Webhook 冪等性保護

PayUni v3 的 webhook 通知具備防重複機制：相同 `TradeNo` 且訂單已付款時，不會重複處理。其他服務商是否有類似保護需個別確認。

### 9. EZPAY 獨有功能

- 統一編號自動查詢公司名稱（透過 `company.g0v.ronny.tw` API）
- 已開立發票後 Metabox 欄位自動變為唯讀

### 10. 支付連審單機制

PChomePay 具備獨特的「審單」機制（`awaiting` 狀態），訂單付款後需等待過單或拒絕，這是其他金流服務商沒有的流程。
