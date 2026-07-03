# Quick Self-Checks（快速驗證點）

> 寫完 PAYUNi 物流相關程式碼後，按本檔對照 self-check。每一項都來自官方文件，**對不上就是 bug**。

---

## ✅ Check 1：HashInfo 公式

**正確公式**：

```
HashInfo = SHA256( HashKey + EncryptInfo + HashIV ).hex.toUpperCase()
```

**錯誤範例**：
- ❌ `SHA256(HashIV + EncryptInfo + HashKey)` — 順序顛倒
- ❌ `MD5(...)` — 不是 SHA256
- ❌ 小寫 hex — PAYUNi 對大小寫不敏感但官方範例是大寫，不一致就是技術債
- ❌ 加上換行 / 多餘空白
- ❌ 忘記 trim HashKey / HashIV（後台複製常帶空白）

**測試向量**（官方 PHP 範例）：
```js
const KEY = '12345678901234567890123456789012';
const IV  = '1234567890123456';
const cipher = '47396636346f66735853533167396942344f587a3775696b34752b596e70452b3a3a3a4373354a5a5143306b7153467531354c6e6f554a69773d3d';
// SHA256(KEY + cipher + IV).toUpperCase() 應產出 64 字元 hex 大寫字串
```

---

## ✅ Check 2：EncryptInfo 結構

**正確結構**：

```
EncryptInfo = hex_encode( base64(ciphertext_aes256gcm) + ":::" + base64(auth_tag_16bytes) )
```

**驗證點**：
1. `EncryptInfo` 應該是 hex string（只含 `[0-9a-f]`），長度為偶數。
2. `Buffer.from(encryptInfo, 'hex').toString('utf8')` 拆 `:::` 後應得**正好兩段**：base64 ciphertext + base64 auth_tag(16 bytes → base64 24 字元末尾 ==)。
3. AES-256-GCM key=32 bytes、iv=16 bytes（**注意**：標準 AES-GCM IV 是 12 bytes，但 PAYUNi 用 16 bytes，這在 Node `createCipheriv` 是 OK 的，會自動處理）。

**錯誤範例**：
- ❌ 沒做 `hex_encode` 直接傳 base64
- ❌ 用 `:` 取代 `:::`
- ❌ 把 ciphertext 與 tag 順序對調

---

## ✅ Check 3：plaintext querystring 編碼

**正確流程**：

```js
const plaintext = Object.entries(params)
  .filter(([, v]) => v !== undefined && v !== null && v !== '')
  .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
  .join('&');
```

**驗證點**：
1. 必須過濾 `undefined / null / ""`（PHP `http_build_query` 會跳過 null，與我們一致）。
2. 必須是 `encodeURIComponent`，**不是** `encodeURI`。
3. **不要排序 key**，依照物件入序。
4. **必含 `MerID` 與 `Timestamp`**——後者是 `Math.floor(Date.now() / 1000)`，**整數秒**。

---

## ✅ Check 4：HTTP request 欄位

```ts
fetch(url, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    'User-Agent': 'payuni',          // ← 必須
  },
  body: new URLSearchParams({
    MerID, Version, EncryptInfo, HashInfo,
  }).toString(),
});
```

**錯誤範例**：
- ❌ `Content-Type: application/json` — PAYUNi 是 form-urlencoded
- ❌ 沒帶 `User-Agent` — 部分 PAYUNi 邊界服務會擋
- ❌ 用 `JSON.stringify(...)` body — 必須是 querystring

---

## ✅ Check 5：Tag 不是超商代號

如果你在 `ship_map` 看到 `Tag: 3` 寫成「3=7-11」，**錯誤**！

| Tag | 用途 |
|---|---|
| 2 | 回傳選取的門市 |
| 3 | 更新商店 C2C 退貨門市 |
| 4 | 更新物流單取件門市 |
| 5 | 更新指定 C2C 物流單退貨門市 |

PAYUNi 物流目前**只支援 7-ELEVEN 與黑貓**，沒有全家、OK、萊爾富。`ShipType=1` 就是 7-11。

---

## ✅ Check 6：Notify 處理

**必做事項**：
- ☑️ Controller 不加 `@UseGuards(JwtGuard)` / `@UseGuards(RolesGuard)`
- ☑️ HTTP 一律回 200 + 字串 `"OK"`（即使 hash 驗證失敗也回 200，避免重送風暴）
- ☑️ Hash 驗證用 `crypto.timingSafeEqual` 防 timing attack
- ☑️ Idempotency：用 `(shippingRef, shipStatusCode, shipStatusTime)` 為 key，重複 ignore
- ☑️ 區分三種 Notify：
  - `ApiType=ShipStatus` → 貨態變更（page 274 / 291）
  - `ApiType=Print` → 列印成功（page 123）
  - 沒有 ApiType + `TradeStatus=1` → 取貨付款完成（page 122 / 268 內節）

**錯誤範例**：
- ❌ `if (!verifyHash) throw new UnauthorizedException()` — 會回 401，PAYUNi 重送
- ❌ `@HttpCode(204)` — 部分客戶反映過 204 也有重送現象，建議 200
- ❌ 不做 idempotency，導致同一張單被處理 N 次

---

## ✅ Check 7：訂單金額限制

| 場景 | 上下限 |
|---|---|
| CVS 取貨付款 (B2C/C2C) | **1~20,000** |
| 黑貓貨到付款 | **30~20,000**（注意下限不是 1）|
| C2B 退貨便商品金額 | **1~20,000** |
| C2B 退貨便代收金額 | **1~999**（ServiceType=4 退貨付款） |

如果 `tradeAmt > 20000` 你的 service 應該在打 API 前自己 throw `BadRequestException`，不要等 PAYUNi 回 `HOME01035 / SHIP02025`。

---

## ✅ Check 8：黑貓 Spec 限制

```ts
if (goodsType !== 1 && spec === 4) {
  throw new BadRequestException('Spec=4 (150cm) only for normal temp');
}
```

冷凍 (2) 與冷藏 (3) **不支援 Spec=4 (150cm)**，會回 `HOME01132`。

---

## ✅ Check 9：呼叫黑貓單一溫層

```ts
const nonZero = [normal, cold, freeze].filter(q => q > 0);
if (nonZero.length === 0) throw new BadRequestException('At least one > 0');
if (nonZero.length > 1)  throw new BadRequestException('Only one temp can be > 0');
```

不滿足會回 `HOME01119` 或 `HOME01134`。

---

## ✅ Check 10：欄位長度

| 欄位 | 上限 | 違反 |
|---|---|---|
| `MerTradeNo` | 25 | HOME01031 / SHIP02003 |
| `MerKeyNo` (ship_map) | 20 | — |
| `Consignee` (CVS) | 10 | — |
| `Consignee` (HOME) | 30 | HOME01133 |
| `ConsigneeAddress` (HOME) | 120 | HOME01050 |
| `ProdDesc` (HOME 物流) | 20 | HOME01054 |
| `ProdDesc` (發票) | 500 | — |
| `Memo` | 100 | HOME01055 |

---

## ✅ Check 11：日期 / 時間規則

| 場景 | 規則 |
|---|---|
| `ShipDate` (CVS B2C) | 不得為當日（YYYYMMDD） |
| `ShipDate` (HOME 列印) | 必須 > 今日，且不可週日國定假日 |
| `DeliveryDate` (HOME 列印) | 必須 > ShipDate，且不可週日 |
| `ShipDate` (HOME 退貨) | D+1~D+7；16:25 後申請最少 D+2 |
| `Timestamp` | unix epoch sec，整數 |
| 時區 | 用 server local 時間（Taiwan, UTC+8）即可，PAYUNi 在 TW |

---

## ✅ Check 12：解密回傳 + URLSearchParams

```ts
function decryptPayuni(encryptInfo, hashKey, hashIv) {
  const combined = Buffer.from(encryptInfo, 'hex').toString('utf8');
  const sepIdx = combined.indexOf(':::');
  const ciphertext = combined.slice(0, sepIdx);
  const tagB64 = combined.slice(sepIdx + 3);
  // ... AES decrypt → plaintext
  return Object.fromEntries(new URLSearchParams(plaintext));
}
```

**驗證點**：
- 解密後是 querystring 格式 `MerID=AAA&MerTradeNo=BBB`
- 用 `URLSearchParams` parse 後得到 `Record<string, string>`（**所有值都是 string**）
- 數字欄位需要 `parseInt(data.ShipStatus, 10)` 才能用

---

## ✅ Check 13：MapJson 雙重序列化

`ship_map` 的 `MapJson` 是 **JSON 字串**，需要兩段解開：

```ts
const data = decryptPayuni(body.EncryptInfo, key, iv);
// data.MapJson === '{"StoreType":"SEVEN","StoreID":"916712",...}'
const map = JSON.parse(data.MapJson);
```

忘了 `JSON.parse` 會直接拿到字串。

---

## ✅ Check 14：B2C / C2C / C2B 編號區分

| 通路 | 編號組合 | 從哪來 |
|---|---|---|
| B2C 7-11 出貨單 | `PartnerId(3) + Odno(8)` = 11 碼 | trade 回傳 + print Notify |
| C2C 7-11 出貨單 | `Odno(8) + ValidationNo(4)` = 12 碼 | print Notify |
| C2B 退貨便 | `RefundODNO(8) + ValidationNo(4)` = 12 碼 | refund 回傳 |
| 黑貓正物流託運單 | `Odno`（12 碼） | trade Notify |
| 黑貓退貨單 | `OBTNumber`（12 碼） | refund 回傳 + ship-status Notify |

---

## ✅ Check 15：UNi 物流序號 = 主鍵

`ShipTradeNo` 是 PAYUNi 給的物流單**唯一識別**，存到 `order.shippingRef`。所有後續操作（query / update / print_label / refund / call_cat...）都用它認單。

C2B（退貨便）特例：用 `RefundODNO` 認單，不是 `ShipTradeNo`。

---

## Sandbox 測試流程速查

1. 後台註冊測試帳號：`https://sandbox.payuni.com.tw/`
2. 開通對應物流服務（後台「物流設定」）
3. 取得 `MerID / HashKey / HashIV`，填入專案 `payment.payuni_*` settings
4. `payment.payuni_mode = 'sandbox'` → service 自動指向 `https://sandbox-api.payuni.com.tw`
5. 測試貨態：登入後台「交易動態明細」，超商可手動模擬「模擬出貨」「模擬取件」
6. 7-11 數網查件：`https://tracking.shopmore.com.tw/`（測試訂單也能查）
7. 黑貓查件：`https://www.t-cat.com.tw/inquire/trace.aspx`

---

## 「我的程式跑不通」5 步診斷

1. **打開 server log**，看 PAYUNi 回的 `Status` 與 `Message`，比對 `error-codes.md`
2. 若 `Status=HOME01002 / LAB01003 / SHIP01003`：HashKey/HashIV 或順序錯——對照 Check 1
3. 若 `Status=API00010 / API00011`：EncryptInfo 或 HashInfo 格式錯——對照 Check 2 / 3
4. 若 `Status=HOME0106x / SHIP0300x`：商店物流尚未開通——回後台檢查
5. 若 `verifyPayuniHash` fail（回應端 hash 不對）：通常是 `mode` 設錯（sandbox 用 production key）
