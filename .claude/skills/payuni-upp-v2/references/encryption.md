# PAYUNi UPP V2 — 加解密與 HashInfo

> 來源：
> - PHP 範例：[#/7/29](https://docs.payuni.com.tw/web/#/7/29)
> - Node.js 範例：[#/7/312](https://docs.payuni.com.tw/web/#/7/312)
> - Java 範例：[#/7/343](https://docs.payuni.com.tw/web/#/7/343)
> - 主頁加解密說明：[#/7/34](https://docs.payuni.com.tw/web/#/7/34)

PAYUNi V2 的加密規範**全平台統一**——UPP（`/api/upp`）、交易查詢（`/api/trade/query`）、各退款 API、Token API、各幕後 API 都用同一套 AES-256-GCM + SHA256 機制。

## TOC

- [機制概覽](#機制概覽)
- [Node.js 完整範例（官方）](#nodejs-完整範例官方)
- [PHP 完整範例（官方）](#php-完整範例官方)
- [Java 完整範例（官方）](#java-完整範例官方)
- [TypeScript Utility Class](#typescript-utility-class)
- [常見錯誤排查](#常見錯誤排查)

---

## 機制概覽

### 金鑰

- **HashKey**：32 字元字串，後台 → 會員 → 商店清單 → 串接設定取得。
- **HashIV**：16 字元字串，同上。
- **不可包含空白**（PAYUNi 警告會自動 trim）。
- **Sandbox / Production 金鑰不同**——切環境一定要對應換金鑰。

### 三步驟

1. **Encrypt**：將 EncryptInfo 內層欄位以 `application/x-www-form-urlencoded` 編碼，AES-256-GCM 加密，組合成 `hex(base64(ciphertext) + ":::" + base64(authTag))`。
2. **HashInfo**：對加密後字串計算 `SHA256(hashKey + EncryptInfo + hashIv).toUpperCase()`。
3. **POST 外層**：`MerID=...&Version=...&EncryptInfo=...&HashInfo=...`（Form POST，UPP）或 JSON body（其他 API）。

### 解密驗章

收到 PAYUNi 回傳必須：
1. 檢查外層 `Status` 是否 `ERROR`（如是則無 EncryptInfo，直接拒絕）。
2. 重新計算 `SHA256(hashKey + receivedEncryptInfo + hashIv).toUpperCase()`，與 `HashInfo` 比對。
3. 解密 EncryptInfo（用同樣 hashKey/hashIv，AES-256-GCM 解密）。
4. URL decode → 取內層 `Status`。

> **HashInfo 不一致 = 資料被竄改 / 用錯金鑰 / EncryptInfo 損壞**，必須拒絕該回呼。

---

## Node.js 完整範例（官方）

> 來自 [#/7/312](https://docs.payuni.com.tw/web/#/7/312)，逐字保留官方範例。

### 載入 crypto 模組

```javascript
const crypto = require("crypto");
```

### AES-GCM 加密

```javascript
/**
 * @param {string} plaintext - 要加密的參數
 * @param {string} key - 加密 Key
 * @param {Buffer} iv - 初始化向量 iv
 * @returns {string} - 加密結果（hex string）
 */
function encrypt(plaintext, key, iv) {
  const cipher = crypto.createCipheriv("aes-256-gcm", key, iv);

  let cipherText = cipher.update(plaintext, "utf8", "base64");
  cipherText += cipher.final("base64");

  const tag = cipher.getAuthTag().toString("base64");
  return Buffer.from(`${cipherText}:::${tag}`).toString("hex").trim();
}
```

### AES-GCM 解密

```javascript
/**
 * @param {string} encryptStr - 要解密的參數（hex 字串）
 * @param {string} key - 加密 Key
 * @param {Buffer} iv - 初始化向量 iv
 * @returns {string} - 解密結果（query string）
 */
function decrypt(encryptStr, key, iv) {
  const [encryptData, tag] = Buffer.from(encryptStr, "hex").toString().split(":::");

  const decipher = crypto.createDecipheriv("aes-256-gcm", key, iv);
  decipher.setAuthTag(Buffer.from(tag, "base64"));

  let decipherText = decipher.update(encryptData, "base64", "utf8");
  decipherText += decipher.final("utf8");

  return decipherText;
}
```

### SHA256 驗章

```javascript
/**
 * @param {string} encryptStr - 加密過後的參數
 * @param {string} key - 加密 Key
 * @param {Buffer} iv - 初始化向量 iv
 * @returns {string} - hash 結果的字串，16進制且皆為大寫
 */
function sha256 (encryptStr, key, iv) {
  const hash = crypto.createHash("sha256").update(`${key}${encryptStr}${iv}`);
  return hash.digest("hex").toUpperCase();
}
```

### 執行範例（含官方測試向量）

```javascript
// 模擬商店資料
const merData = {
  MerID: "AAA",
  MerTradeNO: "BBB",
  Prod: "商品說明"
};

// 將字串轉成 Query String
const querystring = require("querystring");
const plaintext = querystring.stringify(merData);
const merKey = "12345678901234567890123456789012";

// AES-GCM 傳入的 iv 必須為 Buffer 格式
const merIv = Buffer.from("1234567890123456");

const getEncrypt = encrypt(plaintext, merKey, merIv);
const getDecrypt = decrypt(getEncrypt, merKey, merIv);
const getSha256  = sha256(getEncrypt, merKey, merIv);
```

### 預期結果（驗證實作正確性）

```
// AES-GCM 加密結果（hex）
47396636346f66735853533167396942344f587a3775696b34732b596e70452b675270564f73536b7753446c6a4d77526d4e374256514173672b6c78616d4533504d475152642b362f4530626f446e4f6356533969756c743a3a3a4b5961342f4635456965743069385a784b6277704a413d3d

// AES-GCM 解密結果
MerID=AAA&MerTradeNO=BBB&Prod=%E5%95%86%E5%93%81%E8%AA%AA%E6%98%8E

// SHA256 結果
E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7
```

> **注意**：因 AES-GCM 是 deterministic（相同 key+iv+plaintext+tag），給定相同輸入應產生相同輸出。**SHA256 預期值是固定的**，可作為單元測試 assertion。

---

## PHP 完整範例（官方）

> 來自 [#/7/29](https://docs.payuni.com.tw/web/#/7/29)，逐字保留。建議 PHP ≥ 7.1.0。

### 加密 Function

```php
function Encrypt(array $data = [], string $merKey = "", string $merIV = "")
{
    $tag = ""; // 預設為空
    $encrypted = openssl_encrypt(http_build_query($data), "aes-256-gcm", trim($merKey), 0, trim($merIV), $tag);
    return trim(bin2hex($encrypted . ":::" . base64_encode($tag)));
}
```

### 解密 Function

```php
function Decrypt(string $encryptStr = "", string $merKey = "", string $merIV = "")
{
    list($encryptData, $tag) = explode(":::", hex2bin($encryptStr), 2);
    return openssl_decrypt($encryptData, "aes-256-gcm", trim($merKey), 0, trim($merIV), base64_decode($tag));
}
```

### Hash Function

```php
$merKey = "12345678901234567890123456789012";
$merIV  = "1234567890123456";
$encryptStr = "加密後字串(可參考範例)";
strtoupper(hash("sha256", "$merKey$encryptStr$merIV"));
```

### 加解密 Key 範例

```php
$AesKey = "12345678901234567890123456789012";
$AesIV  = "1234567890123456";
```

### 加密 / 解密範例

```php
<?php
// 加密資料
$encryptArr = [
    "MerID" => "AAA",
    "MerTradeNo" => "BBB",
];

// KeyIV
$merKey = "12345678901234567890123456789012";
$merIV  = "1234567890123456";

// 加密字串（PHP 範例輸出）
$encryptStr = Encrypt($encryptArr, $merKey, $merIV);
// 結果範例：47396636346f66735853533167396942344f587a3775696b34752b596e70452b3a3a3a4373354a5a5143306b7153467531354c6e6f554a69773d3d

// 解密
$decryptArr = Decrypt($encryptStr, $merKey, $merIV);
// 結果：MerID=AAA&MerTradeNo=BBB
?>
```

> 注意：PHP 範例的測試向量與 Node.js 略有差異（PHP 範例只用 2 欄位），但**演算法相同**——只要實作正確，相同輸入會得相同輸出。

---

## Java 完整範例（官方）

> 來自 [#/7/343](https://docs.payuni.com.tw/web/#/7/343)。本 SKILL 不深入 Java 細節（本專案是 Node.js），如需請查官方頁面。重點：

- 演算法：`AES/GCM/NoPadding`
- IV 長度：12 bytes（部分實作會用 16 bytes，**請以官方範例為準**）
- AuthTag 長度：16 bytes（128 bits）

> **注意**：PAYUNi 的 IV 規範**都是 16 bytes**（HashIV = 16 字元）。Node.js 與 PHP 的官方範例都用 16 bytes IV，可放心。

---

## TypeScript Utility Class

> 整合官方 Node.js 範例為可注入式 Class，方便 NestJS DI。

```typescript
// payuni-crypto.util.ts
import * as crypto from "crypto";
import * as querystring from "querystring";

export class PayuniCrypto {
  constructor(
    private readonly hashKey: string,  // 32 chars
    private readonly hashIv: string,   // 16 chars
  ) {
    if (hashKey.length !== 32) {
      throw new Error(`PAYUNi HashKey must be 32 chars, got ${hashKey.length}`);
    }
    if (hashIv.length !== 16) {
      throw new Error(`PAYUNi HashIV must be 16 chars, got ${hashIv.length}`);
    }
  }

  /**
   * 加密：對 EncryptInfo 內層 params 進行 AES-256-GCM 加密
   * 流程：params → urlencode → AES-256-GCM → hex(base64(cipher) + ":::" + base64(tag))
   */
  encrypt(params: Record<string, string | number | undefined>): string {
    // 過濾 undefined（PAYUNi 不接受空字串以外的 falsy）
    const cleaned: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null) {
        cleaned[k] = String(v);
      }
    }

    const plaintext = querystring.stringify(cleaned);
    const cipher = crypto.createCipheriv("aes-256-gcm", this.hashKey, Buffer.from(this.hashIv));
    let enc = cipher.update(plaintext, "utf8", "base64");
    enc += cipher.final("base64");
    const tag = cipher.getAuthTag().toString("base64");
    return Buffer.from(`${enc}:::${tag}`).toString("hex").trim();
  }

  /**
   * 解密：將 EncryptInfo (hex) 還原為 params object
   * 流程：hex → split(":::") → AES-256-GCM 解密 → urldecode → object
   */
  decrypt(encryptStr: string): Record<string, string> {
    const [encData, tag] = Buffer.from(encryptStr, "hex").toString().split(":::");
    if (!encData || !tag) {
      throw new Error("PAYUNi decrypt: malformed EncryptInfo (missing :::)");
    }
    const decipher = crypto.createDecipheriv("aes-256-gcm", this.hashKey, Buffer.from(this.hashIv));
    decipher.setAuthTag(Buffer.from(tag, "base64"));
    let dec = decipher.update(encData, "base64", "utf8");
    dec += decipher.final("utf8");

    const result: Record<string, string> = {};
    new URLSearchParams(dec).forEach((v, k) => {
      result[k] = v;
    });
    return result;
  }

  /**
   * 計算 HashInfo：SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()
   */
  generateHashInfo(encryptInfo: string): string {
    return crypto
      .createHash("sha256")
      .update(`${this.hashKey}${encryptInfo}${this.hashIv}`)
      .digest("hex")
      .toUpperCase();
  }

  /**
   * 驗證 HashInfo（時間恆定比較，防止 timing attack）
   */
  verifyHashInfo(encryptInfo: string, hashInfo: string): boolean {
    const expected = this.generateHashInfo(encryptInfo);
    if (expected.length !== hashInfo.length) return false;
    return crypto.timingSafeEqual(
      Buffer.from(expected, "utf8"),
      Buffer.from(hashInfo, "utf8"),
    );
  }
}
```

### 單元測試（用官方測試向量）

```typescript
// payuni-crypto.util.spec.ts
import { PayuniCrypto } from "./payuni-crypto.util";

describe("PayuniCrypto", () => {
  const crypto = new PayuniCrypto(
    "12345678901234567890123456789012",
    "1234567890123456",
  );

  it("should encrypt-decrypt roundtrip", () => {
    const params = { MerID: "AAA", MerTradeNO: "BBB", Prod: "商品說明" };
    const encrypted = crypto.encrypt(params);
    const decrypted = crypto.decrypt(encrypted);
    expect(decrypted).toEqual(
      expect.objectContaining({
        MerID: "AAA",
        MerTradeNO: "BBB",
        Prod: "商品說明",
      }),
    );
  });

  it("should match official SHA256 test vector", () => {
    const params = { MerID: "AAA", MerTradeNO: "BBB", Prod: "商品說明" };
    const encrypted = crypto.encrypt(params);
    const sha = crypto.generateHashInfo(encrypted);
    // 官方 Node.js 範例的預期值
    expect(sha).toBe(
      "E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7",
    );
  });

  it("should reject invalid HashInfo", () => {
    const params = { MerID: "AAA" };
    const encrypted = crypto.encrypt(params);
    expect(crypto.verifyHashInfo(encrypted, "WRONG_HASH")).toBe(false);
  });
});
```

---

## 常見錯誤排查

| 症狀 | 可能原因 | 解法 |
|------|---------|------|
| `Unsupported state or unable to authenticate data` | AuthTag 解碼錯 / 用錯金鑰 / EncryptInfo 損壞 | 檢查金鑰是否符合 sandbox/prod、HashInfo 是否一致 |
| `Invalid IV length` | HashIV 不是 16 字元 | 檢查 `.env` 是否有空白 |
| `Invalid key length` | HashKey 不是 32 字元 | 檢查後台複製的金鑰是否完整 |
| `bad decrypt` / decrypt 出空字串 | hex 解碼後找不到 `:::` 分隔符 | 來源不是 V2 格式（V1 是 CBC，無 tag） |
| `Hash mismatch` 但加密正確 | HashKey/IV 順序錯（不是 `key + str + iv`） | 公式：`SHA256(hashKey + encryptInfo + hashIv).toUpperCase()` |
| `403 / API00003` | 外層缺 `Version` 或值不對 | UPP 必須 `Version=2.0`，幕後 API 各自版本（CREDIT 1.3、ATM 1.3、CVS 1.3 等） |
| Sandbox NotifyURL 收不到 | 非 80/443 port、URL 不公開 | NotifyURL 限 80/443，本地測試需 cloudflare tunnel |

### Express / NestJS 接收 NotifyURL 的注意事項

PAYUNi NotifyURL 是 `application/x-www-form-urlencoded` Form POST。NestJS 要確認：

```typescript
// main.ts
import { NestFactory } from "@nestjs/core";
import { json, urlencoded } from "express";

async function bootstrap() {
  const app = await NestFactory.create(AppModule, { rawBody: true });
  app.use(urlencoded({ extended: true })); // 必要：解析 PAYUNi notify body
  app.use(json());
  // ...
}
```
