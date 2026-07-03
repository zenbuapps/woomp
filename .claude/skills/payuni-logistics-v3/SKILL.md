---
name: payuni-logistics-v3
description: >
  PAYUNi 統一金流物流工具 V3 完整 API 參考（依據 docs.payuni.com.tw 官方文件 2026-05-04 版重新爬取）。
  涵蓋 7-ELEVEN 超商 B2C/C2C/C2B 物流（建立物流單 v1.3、超商門市地圖 v1.1、出貨單列印 v1.0、
  退貨便要號 v1.0、店到店轉宅配 v1.0）、黑貓宅配物流（建立宅配單 v1.2、產宅配編號並下載託運單 PDF v1.0、
  下載託運單 PDF v1.0、呼叫黑貓 v1.0、建立宅配退貨單 v1.0）、物流單查詢 v1.1、物流單修改 v1.1，
  以及 AES-256-GCM + SHA256 加密規範（與 PAYUNi 金流共用）、官方完整錯誤碼表（450+ 條）、
  物流貨態狀態碼（91/92/98/21/22/31/32/33/11/41/43/44/46/51/52/53/55/56/81/82）、
  超商物流貨態 Notify v1.0 與 宅配貨態 Notify v1.0。
  Use this skill whenever the task involves PAYUNi 物流、payuni-logistics、shipping、CVS pickup、
  超商取貨、7-ELEVEN B2C/C2C/C2B、Tcat、黑貓宅配、ship_map、ShipTradeNo、ShipStatus、
  c2c_to_home_delivery、call_cat、退貨便、託運單 PDF、物流 Notify callback、
  或任何 import from 'payuni-logistics' / 'payuni-crypto' / 涉及 ZenbuSite shippingRef、
  shippingProvider=payuni、shippingStoreId、shippingOdno、ShipStatus mapping 的程式碼。
  本 SKILL 為唯一官方 API reference 來源——不要再去翻 docs.payuni.com.tw。
---

# PAYUNi Logistics V3 — 完整官方 API 參考

> **版本**：V3（AES-256-GCM）｜**官方來源**：https://docs.payuni.com.tw/web/ ｜**最後爬取**：2026-05-04
> **覆蓋頁面**：#/7/103, 122, 123, 124, 125, 129, 268, 269, 270, 271, 272, 274, 291, 304（API），#/7/29, 56（加密），#/7/119, 156（錯誤碼），#/7/120（貨態），#/7/170（金額限制）。
> **加密金鑰**：與 PAYUNi 金流（UPP / CREDIT / ATM / CVS / UNi Embed）共用同一組 `MerID / HashKey / HashIV`，可由 `payment.payuni_*` 設定取得。

---

## 1. 索引（Index）— 找東西先看這裡

### 1.1 API 端點查找表

| 我想做的事 | 章節 | 詳細參考檔 | 官方頁 |
|---|---|---|---|
| 建立超商 B2C/C2C 物流單（取貨付款 / 不付款） | 4.1 | `references/cvs-apis.md#trade-1.3` | #/7/122 |
| 顯示超商門市地圖、回傳消費者選的門市 | 4.2 | `references/cvs-apis.md#ship_map-1.1` | #/7/103 |
| 列印超商出貨單（A4 / 直立式 / 批次最多 50 筆） | 4.3 | `references/cvs-apis.md#print_label-1.0` | #/7/123 |
| 建立 7-11 退貨便（C2B），給消費者 12 碼退貨碼 | 4.4 | `references/cvs-apis.md#refund-1.0` | #/7/125 |
| C2C 賣家未取，補提供宅配地址退回 | 4.5 | `references/cvs-apis.md#c2c_to_home-1.0` | #/7/304 |
| 建立黑貓宅配單（COD / 不付款） | 5.1 | `references/home-delivery-apis.md#trade-1.2` | #/7/268 |
| 取得宅配託運單 PDF（首次） | 5.2 | `references/home-delivery-apis.md#get_obt_number_pdf-1.0` | #/7/269 |
| 重新下載託運單 PDF（24h 有效） | 5.3 | `references/home-delivery-apis.md#download_pdf-1.0` | #/7/270 |
| 預約黑貓司機到府收件 | 5.4 | `references/home-delivery-apis.md#call_cat-1.0` | #/7/271 |
| 建立黑貓宅配退貨單 | 5.5 | `references/home-delivery-apis.md#refund-1.0` | #/7/272 |
| 查詢物流單狀態（B2C/C2C/HOME/C2B 共用） | 6 | `references/cvs-apis.md#query-1.1` | #/7/124 |
| 修改物流單收件人姓名 / 手機 / 信箱 / 地址 | 7 | `references/cvs-apis.md#update-1.1` | #/7/129 |
| 接收貨態 Notify（超商 / 宅配） | 8 | `references/notify-and-status.md` | #/7/291, 274 |

### 1.2 加密與簽章

| 主題 | 章節 | 詳細參考檔 |
|---|---|---|
| AES-256-GCM 加密流程（plaintext → EncryptInfo） | 3.1 | `references/encryption.md#encrypt` |
| AES-256-GCM 解密（EncryptInfo → 物件） | 3.2 | `references/encryption.md#decrypt` |
| HashInfo（SHA256 大寫）計算公式 | 3.3 | `references/encryption.md#hashinfo` |
| HashInfo 驗證（timing-safe） | 3.4 | `references/encryption.md#verify` |
| HTTP request 共通 wrapper（4 個 form 欄位） | 3.5 | `references/encryption.md#wrapper` |

### 1.3 列舉、狀態碼、錯誤碼

| 主題 | 詳細參考檔 |
|---|---|
| **物流貨態狀態碼**（ShipStatus 11/21/22/31/32/33/41/43/44/46/51/52/53/55/56/81/82/91/92/98） | `references/notify-and-status.md#ship-status` |
| **PaymentType / TradeStatus / LgsType / GoodsType / ServiceType / ShipType / Tag / Spec / DeliveryTimeTag** | `references/enums.md` |
| **錯誤碼總表**（API00xxx + HOME01xxx + LAB01-06xxx + LGR01-06xxx + SHIP01-07xxx + DEF05xxx，共 450+ 條） | `references/error-codes.md` |

### 1.4 NestJS 整合

| 主題 | 詳細參考檔 |
|---|---|
| 本專案路徑、Settings keys、Service 慣例 | 9（本檔案下方） |
| 完整 NestJS Service 範例（建立物流單 / 解析 Notify） | `references/nestjs-integration.md` |
| 快速驗證點（HashInfo 公式、payload 範例、Notify 驗證流程） | `references/quick-checks.md` |

---

## 2. 端點總覽（Endpoint Overview）

| API Path | Ver | Method | 功能 | LgsType |
|---|---|---|---|---|
| `/api/logistics/ship_map` | 1.1 | Form POST → redirect | 超商門市地圖（前景） | B2C / C2C |
| `/api/logistics/trade` | 1.3 | HTTP POST | 建立超商物流單（B2C / C2C） | B2C / C2C |
| `/api/logistics/query` | 1.1 | HTTP POST | 物流單查詢（含黑貓） | B2C / C2C / HOME / C2B |
| `/api/logistics/update` | 1.1 | HTTP POST | 物流單修改（不支援 C2B / 黑貓退貨） | B2C / C2C / HOME |
| `/api/logistics/print_label` | 1.0 | Form POST → redirect / display | 超商出貨單列印（前景） | B2C / C2C |
| `/api/logistics/refund` | 1.0 | HTTP POST | 退貨便要號（C2B，僅 B2C 開通會員） | C2B |
| `/api/logistics/c2c_to_home_delivery` | 1.0 | HTTP POST | C2C 待轉宅配資料提供（背景） | C2C → 宅配 |
| `/api/home_delivery/trade` | 1.2 | HTTP POST | 建立黑貓宅配單（背景） | HOME |
| `/api/home_delivery/get_obt_number_pdf` | 1.0 | Form POST → redirect | 產宅配編號並下載託運單 PDF（前景） | HOME |
| `/api/home_delivery/download_pdf` | 1.0 | Form POST → redirect | 重新下載託運單 PDF（前景） | HOME |
| `/api/home_delivery/call_cat` | 1.0 | HTTP POST | 呼叫黑貓司機到府收件（背景） | HOME |
| `/api/home_delivery/refund` | 1.0 | HTTP POST | 建立黑貓宅配退貨單（背景） | HOME |

**Hosts**：
- 正式區 `https://api.payuni.com.tw`
- 測試區 `https://sandbox-api.payuni.com.tw`
- 後台正式區 `https://www.payuni.com.tw`
- 後台測試區 `https://sandbox.payuni.com.tw`
- 7-ELEVEN 數網查件 `https://tracking.shopmore.com.tw/`（B2C 11 碼 = PartnerId(3) + Odno(8)；C2C 12 碼 = Odno(8) + ValidationNo(4)；C2B 12 碼 = RefundODNO(8) + ValidationNo(4)）
- 黑貓宅配查件 `https://www.t-cat.com.tw/inquire/trace.aspx`（OBTNumber 12 碼）

**HTTP 規範**：
- 必須 TLS v1.2+
- Content-Type：`application/x-www-form-urlencoded`
- Header：`User-Agent: payuni`
- 背景 Notify URL 僅限 80 / 443 port

---

## 3. AES-256-GCM 加解密（共用金流）

每個 request body 一律 4 個欄位：`MerID`, `Version`, `EncryptInfo`, `HashInfo`。回傳結構同形（外層 4 欄 + 內層解密物件）。

### 3.1 加密流程

```
1. 蒐集業務參數成 plain object（必含 MerID + Timestamp = unix time）
2. URL-encode 成 querystring：encodeURIComponent(k) + '=' + encodeURIComponent(v) join '&'
3. AES-256-GCM 加密：
   - key = HashKey（32 bytes UTF-8，請先 trim）
   - iv  = HashIV（16 bytes UTF-8，請先 trim）
   - 密文輸出 base64，AuthTag 取出後 base64
4. EncryptInfo = hex(`${ciphertextB64}:::${tagB64}`)
5. HashInfo = SHA256(HashKey.trim() + EncryptInfo + HashIV.trim()).hex.toUpperCase()
```

### 3.2 TypeScript / Node `crypto` 實作（與本專案 `payuni-crypto.ts` 一致）

```ts
import * as crypto from 'crypto';

export function encryptPayuni(
  params: Record<string, string | number | undefined>,
  hashKey: string,
  hashIv: string,
): string {
  const plaintext = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  const cipher = crypto.createCipheriv(
    'aes-256-gcm',
    Buffer.from(hashKey.trim(), 'utf8'),
    Buffer.from(hashIv.trim(), 'utf8'),
  );
  let b64 = cipher.update(plaintext, 'utf8', 'base64');
  b64 += cipher.final('base64');
  const tag = cipher.getAuthTag();
  return Buffer.from(`${b64}:::${tag.toString('base64')}`, 'utf8').toString('hex');
}

export function decryptPayuni(
  encryptInfo: string, hashKey: string, hashIv: string,
): Record<string, string> {
  const combined = Buffer.from(encryptInfo, 'hex').toString('utf8');
  const sepIdx = combined.indexOf(':::');
  const ciphertext = combined.slice(0, sepIdx);
  const tagB64 = combined.slice(sepIdx + 3);
  const decipher = crypto.createDecipheriv(
    'aes-256-gcm',
    Buffer.from(hashKey.trim(), 'utf8'),
    Buffer.from(hashIv.trim(), 'utf8'),
  );
  decipher.setAuthTag(Buffer.from(tagB64, 'base64'));
  let plaintext = decipher.update(ciphertext, 'base64', 'utf8');
  plaintext += decipher.final('utf8');
  return Object.fromEntries(new URLSearchParams(plaintext));
}

export function hashInfoPayuni(hashKey: string, encryptInfo: string, hashIv: string): string {
  return crypto.createHash('sha256')
    .update(hashKey.trim() + encryptInfo + hashIv.trim(), 'utf8')
    .digest('hex')
    .toUpperCase();
}

export function verifyPayuniHash(
  hashKey: string, encryptInfo: string, hashIv: string, receivedHash: string,
): boolean {
  const expected = hashInfoPayuni(hashKey, encryptInfo, hashIv);
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from((receivedHash ?? '').toUpperCase(), 'utf8');
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}
```

### 3.3 PHP 範例（官方 page #/7/29）

```php
function Encrypt(array $data, string $merKey, string $merIV): string {
    $tag = '';
    $encrypted = openssl_encrypt(http_build_query($data), 'aes-256-gcm', trim($merKey), 0, trim($merIV), $tag);
    return trim(bin2hex($encrypted . ':::' . base64_encode($tag)));
}
function Decrypt(string $encryptStr, string $merKey, string $merIV): string {
    [$encryptData, $tag] = explode(':::', hex2bin($encryptStr), 2);
    return openssl_decrypt($encryptData, 'aes-256-gcm', trim($merKey), 0, trim($merIV), base64_decode($tag));
}
// $hash = strtoupper(hash('sha256', "$merKey$encryptStr$merIV"));
```

> 詳細範例與測試向量見 `references/encryption.md` 與 `references/quick-checks.md`。

---

## 4. CVS（7-ELEVEN）物流 — 章節摘要

完整參數表請見 `references/cvs-apis.md`。以下僅列入口資訊：

- **4.1 建立超商物流單**（`/api/logistics/trade` v1.3）：B2C/C2C，TradeAmt ≤ 20,000，必填 GoodsType/LgsType/ShipType=1/ServiceType/StoreID/Consignee/ConsigneeMobile，C2C 才能用 RefundStoreID/SenderName/SenderMobile。
- **4.2 超商門市地圖**（`/api/logistics/ship_map` v1.1）：Form POST 跳轉。`Tag` 是「處理標記」（**不是超商代號**）：`2=回傳選取的門市資訊`、`3=更新商店退貨門市(C2C)`、`4=更新物流單取件門市`、`5=更新指定物流單退貨門市(C2C)`。`MapType`：1=本島、2=含離島（GoodsType=2 時固定 2）。`MobileTag`：Y=手機、N=PC。回傳含 `MapJson` 字串內含 StoreType/StoreID/StoreName/Address/InsularArea。
- **4.3 出貨單列印**（`/api/logistics/print_label` v1.0）：ShipTradeNo 多筆以半形逗號分隔（最多 50 筆），LabelMode 1=A4 / 2=直立式（C2C 自 2025-12-16 起也支援），B2C 的 ShipDate 不得為當日。
- **4.4 退貨便要號**（`/api/logistics/refund` v1.0）：僅限 B2C 常溫商店；ServiceType 4=退貨付款 / 5=退貨不付款；ProcessType 固定 1。
- **4.5 C2C 待轉宅配**（`/api/logistics/c2c_to_home_delivery` v1.0）：賣家未取後在期限內提供宅配地址，物流中心改用黑貓退回（運費由商家承擔）。

---

## 5. 黑貓宅配（Tcat）物流 — 章節摘要

完整參數表請見 `references/home-delivery-apis.md`。

- **5.1 建立宅配單**（`/api/home_delivery/trade` v1.2）：LgsType=HOME、ShipType=2，TradeAmt 30~20,000；GoodsType 1=常溫/2=冷凍/3=冷藏；DeliveryTimeTag 01/02/04；Consignee 限 30 字混合長度（中文 / 全形 ×2、半形 ×1）；ProdDesc 必填（≤ 20 字混合）。
- **5.2 產宅配編號並下載託運單 PDF**（`/api/home_delivery/get_obt_number_pdf` v1.0）：PostType=1, PrintType=1；ShipDate > 今日且非週日國定假日，DeliveryDate > ShipDate 且非週日；Spec 1=60/2=90/3=120/4=150 cm（冷凍冷藏不支援 150）；可帶 HideProdDesc 與 Memo。
- **5.3 重新下載 PDF**（`/api/home_delivery/download_pdf` v1.0）：FileNo 24 小時內有效。
- **5.4 呼叫黑貓**（`/api/home_delivery/call_cat` v1.0）：每次只能指定**單一溫層** > 0；常溫/冷藏/冷凍三者擇一；ContactMobile 與 ContactTel 二擇一。
- **5.5 黑貓退貨單**（`/api/home_delivery/refund` v1.0）：ServiceType 固定 3=取貨不付款；Spec 與正物流相同；ShipDate 須 D+1~D+7（每日 16:25 後申請最少 D+2）。

---

## 6. 物流單查詢（共用）

`/api/logistics/query` v1.1，依 LgsType 不同回傳欄位略有差異：
- LgsType=B2C：StoreID/StoreName。
- LgsType=C2C：StoreID/StoreName + ValidationNo（4 碼）。
- LgsType=HOME：FileNo + TradeType + ConsigneeAddress。
- LgsType=C2B：RefundODNO + ValidationNo + DeadlineDate（請求時用 ReturnOdno = 12 碼）。

請求時 `ShipTradeNo` 與 `ReturnOdno` **二擇一必填**。HOME 時 `TradeType` 1=正物流（預設）/2=逆物流。

完整欄位見 `references/cvs-apis.md#query-1.1`。

---

## 7. 物流單修改

`/api/logistics/update` v1.1。**僅修改未列印出貨單的單**，**不支援退貨便 C2B 與黑貓退貨**。可選 Consignee / ConsigneeMail（僅超商）/ ConsigneeMobile / ConsigneeAddress（僅 HOME）。回傳同時包含 OriginalConsignee 等修改前資訊。

完整欄位見 `references/cvs-apis.md#update-1.1`。

---

## 8. 貨態 Notify（背景）

兩個獨立通道，PAYUNi 在貨態變化時 POST 到商店指定 URL，payload 結構與一般 API 回傳相同（`MerID`/`Version`/`EncryptInfo`/`HashInfo` 4 個 form 欄位）。

- **超商物流貨態 Notify**（page #/7/291，v1.0）：`ApiType=ShipStatus`；B2C/C2C 回 `PartnerId/ShipTradeNo/LgsType/GoodsType/ShipType/ShipStatus/ShipStatusDesc/ShipStatusTime`，狀態 81 時加帶 `PickupStoreType` (1=取件門市 / 2=退件門市)；C2B 改回 `RefundODNO/ValidationNo`。
- **宅配貨態 Notify**（page #/7/274，v1.0）：`ApiType=ShipStatus`；回 `TradeType/ShipTradeNo/OBTNumber/GoodsType/LgsType=HOME/ShipType=2/FileNo/ShipStatus/ShipStatusDesc/ShipStatusTime`。

另有 2 種「取貨完成 Notify」（在建立物流單時帶 `NotifyURL`，僅 ServiceType=1 取貨付款時觸發）：
- **CVS 取件付款完成**（page #/7/122 內節）：`ApiType` 不含；含 `Odno(8)/PaymentType=5或8/ShipAmt/PayTime`，TradeStatus 固定 1。
- **宅配到付取件完成**（page #/7/268 內節）：欄位類同，`Odno` 12 碼。

詳細欄位、貨態碼 81 與 PickupStoreType、商店回應 HTTP 200 的處理慣例見 `references/notify-and-status.md`。

---

## 9. 本專案（ZenbuSite）整合

### 9.1 程式碼位置

| 用途 | 路徑 |
|---|---|
| AES / Hash 共用 helper | `apps/api-gateway/src/commerce/payments/payuni/payuni-crypto.ts` |
| 物流 Service | `apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.service.ts` |
| 物流 Controller | `apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.controller.ts` |
| 模組註冊 | `apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.module.ts` |

### 9.2 設定（與金流共用憑證）

讀 `settings` 表時使用以下 keys（`commerce.payment.*` 與 `commerce.shipping.*`）：

| Key | 說明 |
|---|---|
| `payment.payuni_mer_id` | 商店代號 |
| `payment.payuni_hash_key` | 32 byte AES key |
| `payment.payuni_hash_iv` | 16 byte AES iv |
| `payment.payuni_mode` | `sandbox` / `production` |
| `shipping.payuni_enabled` | 物流主開關（**只擋 outbound action，inbound notify 不擋**） |

### 9.3 Order 欄位映射

| 欄位 | 取自 |
|---|---|
| `order.shippingProvider` | 固定 `payuni` |
| `order.shippingRef` | `ShipTradeNo`（**主鍵連結**） |
| `order.shippingStoreId` | `StoreID`（CVS） |
| `order.shippingStoreName` | `StoreName`（CVS） |
| `order.shippingStoreAddr` | `StoreAddr`（CVS） |
| `order.shippingOdno` | `Odno` 或 `OBTNumber` |
| `order.shippingStatus` | 由 `ShipStatus` 經 `references/notify-and-status.md#mapping` 對應 |

### 9.4 Notify 處理慣例

1. Controller 入口接 `application/x-www-form-urlencoded`；不要使用 RolesGuard / JwtGuard。
2. 先 `verifyPayuniHash` → 失敗回 `200 "OK"`（不要回 4xx 否則 PAYUNi 重送）。
3. `decryptPayuni` 取出內層 → 比對 `Status === 'SUCCESS'`。
4. 用 `ShipTradeNo` 查 order → 更新 `shippingStatus` / `shippingOdno`。
5. 回傳純文字 `"OK"`（PAYUNi 不檢查 body，只看 200）。

完整 NestJS 範例（含 timeout、retry、order 反查、idempotency）見 `references/nestjs-integration.md`。

---

## 10. 重要差異與雷區（從官方文件直接萃取）

1. **`Tag` 不是超商代號**。它是「處理標記」(2/3/4/5)。要選不同超商門市請用 GoodsType + LgsType + StoreID（超商 7-ELEVEN 為 ShipType=1，PAYUNi 物流目前**只支援 7-ELEVEN**，不接 FamilyMart/OK/HiLife）。
2. **退貨便（C2B）只能 B2C 大宗寄倉常溫**；C2C 沒有 C2B。GoodsType 必須是 1。
3. **C2C 店到店冷凍**：GoodsType=2 時 MapType 固定 2（含離島）。
4. **黑貓不接受 150 cm 冷凍冷藏**：Spec=4 只能搭配 GoodsType=1。
5. **黑貓退貨 ShipDate 限 D+1~D+7**，且 16:25 後申請的最少 D+2。**週日不可配送**。
6. **call_cat 三種溫層只能擇一 > 0**——同時填會被拒。
7. **物流單修改不支援 C2B/黑貓退貨**；列印過的物流單也無法再改收件資訊。
8. **TradeAmt 上限 20,000**（CVS 與黑貓宅配共同），**黑貓宅配 TradeAmt 下限 30**。
9. **MerTradeNo** 限制：長度 ≤ 25、字符 `[A-Za-z0-9_-]`、10 分鐘內不可重複。
10. **NotifyURL 僅限 80/443 port**；填了才會發「取件付款完成」Notify（ServiceType=1 才觸發）。

---

## 11. 文件版本紀錄（關鍵變更）

| 日期 | 變更 |
|---|---|
| 2026-01-05 | 建立超商物流單升 1.3、建立宅配單升 1.2，新增優惠券（PromoCode/DiscountAmt/OrderAmt/CouponNotifyURL）參數。 |
| 2025-11-25 | ship_map 回傳新增 `InsularArea` 門市區域識別（I=本島 / O=離島）。 |
| 2025-12-16 | 出貨單列印 `LabelMode=2`（直立式）支援 C2C。 |
| 2025-03-12 | 超商物流貨態 Notify 與 query 新增 `PickupStoreType`（針對狀態 81 門市關轉）。 |
| 2025-02-10 | get_obt_number_pdf 新增 `DeliveryDate` 必填參數。 |
| 2024-11-26 | call_cat ContactTel 手機格式說明調整。 |
| 2024-01-30 | 新增 HOME01145、HOME01146 錯誤碼；建立宅配單 NotifyURL 補充說明。 |
| 2023-12-13 | 新增 c2c_to_home_delivery；新增貨態碼 46/55/56/82。 |
| 2023-09-19 | 新增貨態碼 44=包裹遺失、81=門市關轉。 |
| 2023-08-29 | 黑貓宅配全套 API 上線。 |
| 2023-03-22 | C2C 店到店全套參數上線；ship_map 升 1.1（MapType / MobileTag）。 |
| 2023-01-31 | 7-ELEVEN B2C 物流上線。 |

---

## 12. References Index

| 主題 | 檔案 |
|---|---|
| 超商（CVS）所有 API 完整欄位表 | `references/cvs-apis.md` |
| 黑貓宅配所有 API 完整欄位表 | `references/home-delivery-apis.md` |
| AES / SHA256 加解密細節（含 PHP 與 TS 範例 + 測試向量） | `references/encryption.md` |
| 列舉值（LgsType / GoodsType / ServiceType / Tag / Spec / DeliveryTimeTag / ShipType） | `references/enums.md` |
| 物流貨態狀態碼 ShipStatus + Notify payload 結構 + 對應商店 status mapping | `references/notify-and-status.md` |
| 官方錯誤碼總表（API00xxx / HOME01xxx / LAB / LGR / SHIP / DEF） | `references/error-codes.md` |
| NestJS Service 整合範例（4 大實際情境） | `references/nestjs-integration.md` |
| 快速驗證點（Self-check 用） | `references/quick-checks.md` |
