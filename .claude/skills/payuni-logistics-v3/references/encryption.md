# Encryption — AES-256-GCM + SHA256 HashInfo

> 來源：docs.payuni.com.tw `#/7/29` PHP 範例、`#/7/56` 資料加密陣列。
> 物流工具與 PAYUNi 金流（UPP / CREDIT / ATM / CVS / UNi Embed）共用同一組 `MerID / HashKey / HashIV`，**程式碼可重用**。

## 算法總覽

```
plain object  →  url-encoded querystring
querystring   →  AES-256-GCM(key=HashKey, iv=HashIV) → ciphertext + auth_tag
EncryptInfo   =  hex( base64(ciphertext) + ":::" + base64(auth_tag) )
HashInfo      =  SHA256( HashKey + EncryptInfo + HashIV ).hex().toUpperCase()
```

## 共通規則

- **HashKey**：32 bytes（UTF-8）。建議 trim 後使用，PAYUNi 後台複製出來偶爾會有 trailing 空白。
- **HashIV**：16 bytes（UTF-8）。同樣 trim。
- **plaintext 排序**：依官方 PHP `http_build_query()` 結果——按 object 進入順序輸出。本專案用 `Object.entries(params).map(...)` 一致。
- **空值過濾**：`undefined` / `null` / `""` 應該濾掉再加密，避免「空欄位也佔 querystring 空格」。
- **Timestamp**：必填（unix epoch in seconds，`Math.floor(Date.now()/1000)`）。
- **Header**：`User-Agent: payuni`。Content-Type 一律 `application/x-www-form-urlencoded`。

## <a id="encrypt"></a>3.1 加密（TypeScript / NestJS）

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
  const tag = cipher.getAuthTag(); // 16 bytes
  const combined = `${b64}:::${tag.toString('base64')}`;
  return Buffer.from(combined, 'utf8').toString('hex');
}
```

## <a id="decrypt"></a>3.2 解密

```ts
import { BadRequestException } from '@nestjs/common';

export function decryptPayuni(
  encryptInfo: string, hashKey: string, hashIv: string,
): Record<string, string> {
  const combined = Buffer.from(encryptInfo, 'hex').toString('utf8');
  const sepIdx = combined.indexOf(':::');
  if (sepIdx < 0) {
    throw new BadRequestException('invalid EncryptInfo: missing separator');
  }
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
```

## <a id="hashinfo"></a>3.3 HashInfo 計算

```ts
export function hashInfoPayuni(hashKey: string, encryptInfo: string, hashIv: string): string {
  return crypto.createHash('sha256')
    .update(hashKey.trim() + encryptInfo + hashIv.trim(), 'utf8')
    .digest('hex')
    .toUpperCase();
}
```

順序固定為 `HashKey + EncryptInfo + HashIV`，不可換序。輸出**大寫 hex**（PAYUNi 對大小寫不敏感，但官方範例與其他金流一致用大寫）。

## <a id="verify"></a>3.4 HashInfo 驗證（timing-safe）

```ts
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

## <a id="wrapper"></a>3.5 Request / Response Envelope

每個 API 的 request body 都是 4 個欄位：

| Field | 來源 |
|---|---|
| `MerID` | 商店代號（明文） |
| `Version` | 該 API 版本（如 `1.3` / `1.1` / `1.0`） |
| `EncryptInfo` | 把所有業務欄位（含 MerID + Timestamp）加密 |
| `HashInfo` | SHA256(HashKey + EncryptInfo + HashIV) 大寫 |

回傳格式同形：
```json
{
  "Status": "SUCCESS",         // 或錯誤碼如 "HOME01007"
  "MerID": "S111111111",
  "Version": "1.3",
  "EncryptInfo": "<hex>",
  "HashInfo": "<sha256>"
}
```

回傳的 `Status` 在外層只有 `SUCCESS` / 失敗碼（少數 API 為 `UNKNOWN`），詳細 `Message` 與業務欄位都在解密後的內層。

## NestJS 完整呼叫 wrapper（供 service 重用）

```ts
import { HttpException, HttpStatus, Injectable, Logger } from '@nestjs/common';
import { encryptPayuni, decryptPayuni, hashInfoPayuni, verifyPayuniHash, PAYUNI_HOSTS } from '../payuni/payuni-crypto';

@Injectable()
export class PayuniLogisticsHttp {
  private readonly logger = new Logger(PayuniLogisticsHttp.name);

  async post<T = Record<string, string>>(
    path: string,
    version: string,
    body: Record<string, string | number | undefined>,
    creds: { merId: string; hashKey: string; hashIv: string; mode: 'sandbox' | 'production' },
  ): Promise<T> {
    const host = PAYUNI_HOSTS[creds.mode];
    const params = { MerID: creds.merId, Timestamp: Math.floor(Date.now() / 1000), ...body };
    const encryptInfo = encryptPayuni(params, creds.hashKey, creds.hashIv);
    const hashInfo = hashInfoPayuni(creds.hashKey, encryptInfo, creds.hashIv);
    const form = new URLSearchParams({
      MerID: creds.merId, Version: version, EncryptInfo: encryptInfo, HashInfo: hashInfo,
    });

    const res = await fetch(`${host}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'User-Agent': 'payuni',
      },
      body: form.toString(),
    });
    const text = await res.text();
    let json: any;
    try { json = JSON.parse(text); }
    catch { throw new HttpException({ raw: text }, HttpStatus.BAD_GATEWAY); }

    if (!verifyPayuniHash(creds.hashKey, json.EncryptInfo ?? '', creds.hashIv, json.HashInfo ?? '')) {
      this.logger.error('PAYUNi response hash mismatch', { path, status: json.Status });
      throw new HttpException('hash mismatch', HttpStatus.BAD_GATEWAY);
    }
    if (json.Status !== 'SUCCESS') {
      // 注意有些 API 會回 UNKNOWN（系統忙碌），請求 caller 處理
      throw new HttpException({ status: json.Status, message: json.Message }, HttpStatus.BAD_GATEWAY);
    }
    return decryptPayuni(json.EncryptInfo, creds.hashKey, creds.hashIv) as unknown as T;
  }
}
```

## 測試向量（官方 PHP 文件提供）

```
HashKey  = "12345678901234567890123456789012"
HashIV   = "1234567890123456"
plain    = { "MerID": "AAA", "MerTradeNo": "BBB" }
querystring = "MerID=AAA&MerTradeNo=BBB"
EncryptInfo (例) = "47396636346f66735853533167396942344f587a3775696b34752b596e70452b3a3a3a4373354a5a5143306b7153467531354c6e6f554a69773d3d"
                = hex("G9f64ofsXSS1g9iB4OXz7uik4u+YnpE+:::Cs5JZQC0kqSFu15LnoUJiw==")
                解後得 querystring = "MerID=AAA&MerTradeNo=BBB"
HashInfo = strtoupper(sha256(HashKey + EncryptInfo + HashIV))
```

> 注意：AES-GCM 每次加密都會產生不同的 cipher（受 IV 與 nonce 影響），所以 EncryptInfo 不可寫死比對。**驗收的方式是**：encrypt → hex 字串 → decrypt → 還原成 querystring `MerID=AAA&MerTradeNo=BBB`。

## 常見錯誤對應（HashInfo / EncryptInfo）

| 錯誤碼 | 對策 |
|---|---|
| `API00010` EncryptInfo 格式錯誤 | hex decode 失敗或缺 `:::` 分隔符 |
| `API00011` HashInfo 格式錯誤 | 不是 64 字元 hex 或含非 hex 字元 |
| `HOME01002` / `LAB01003` / `LGR01003` / `SHIP01003` 資料 HASH 比對不符合 | HashKey/HashIV 錯、或順序錯，或 EncryptInfo 內容被竄改 |
| `HOME01003` / `LAB01004` / `LGR01004` / `SHIP01004` 資料解密失敗 | AES key/iv 錯、或 ciphertext 被 truncate、或 auth_tag 錯 |
| `HOME01009` / `LAB02007` / `SHIP02007` 時間戳記已過期 | Timestamp 偏離主機過久（檢查時鐘同步） |
