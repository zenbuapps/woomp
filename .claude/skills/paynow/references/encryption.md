# PayNow 加密 / 簽章機制全集

> 來源：docs.paynow.com.tw/developer/docs/apipdf/cashflow/cashflow-check-code、
> .../appendix、.../cashflow-applepay、understanding-paynow/webhook。
> ⚠️ PayNow 三套體系的加密簽章完全不同，**不可互相套用，也不可套用 ezPay / ECPay / PAYUNi 的 crypto**。

## 目錄（TOC）

1. 對照速查表（哪個體系用哪種）
2. 體系 1：REST Webhook HMAC-SHA256 驗簽
3. 體系 2-A：導轉 / 信用卡授權 SHA-1 PassCode
4. 體系 2-B：AES256（CBC + Zero padding，base64）
5. 體系 2-B：檢核碼握手（GP / GK 換鑰）
6. 加權檢核碼演算法（GP / GK 規則）
7. TimeStr 時間戳格式
8. SHA256 / HMACSHA256（背景交易握手用）
9. TripleDES（票券核銷 / 分店資料）
10. ApplePay Signature（舊版商家驗證）
11. PHP 實作對照（官方為 C#）

---

## 1. 對照速查表

| 體系 / 用途 | 演算法 | 金鑰 | 輸出 |
|-------------|--------|------|------|
| 體系 1 Webhook 驗簽 | **HMAC-SHA256** | 商家 **PrivateKey** | hex（大寫，比對 `X-Payment-Center-Hmac-Sha256`） |
| 體系 1 REST 認證 | 無加密 | Bearer PrivateKey | — |
| 體系 3 發票認證 | 無加密 | Bearer 商家 JWT-Token | — |
| 體系 2 導轉 PassCode | **SHA-1** | 無 key（純串接 hash） | hex（多數需大寫） |
| 體系 2 業務資料加密 | **AES256**（CBC + PaddingMode.Zeros） | 握手取得的 EncryptionKey / EncryptionIV | base64 |
| 體系 2 握手 bootstrap | **AES256**（CBC + Zeros） | **固定** Key/IV（見下） | base64 |
| 體系 2 握手 PassCode（GP） | **SHA256** | 無（純串接） | hex 大寫 |
| 體系 2 握手 PassCode（GK 回覆 / 換鑰） | **HMAC-SHA256** | CheckNum | hex 大寫 |
| 體系 2 票券核銷 / 分店資料 | **TripleDES**（ECB + Zeros） | 內嵌 8 碼 key | base64 |
| 體系 2 ApplePay 商家驗證 | **SHA256** | 商家 PayNow 交易密碼 | hex 小寫 |

---

## 2. 體系 1：REST Webhook HMAC-SHA256 驗簽

PayNow 推送 `payment_result` Webhook 時，於 Header `X-Payment-Center-Hmac-Sha256` 帶簽章，
**用商家 PrivateKey 對 raw payload（原始 body 字串）做 HMAC-SHA256** 產生。

```php
// $rawBody = file_get_contents('php://input');  // 原始 body，勿先 json_decode 再 re-encode
// $headerSig = $_SERVER['HTTP_X_PAYMENT_CENTER_HMAC_SHA256'] ?? '';
$calc = strtoupper( hash_hmac('sha256', $rawBody, $privateKey) );
$ok   = hash_equals($calc, strtoupper($headerSig));   // timing-safe 比對
```

- 範例標頭值為大寫 hex（`F9E1AB66...`）；比對時統一大小寫再 `hash_equals`。
- 驗證通過才處理；處理完回 **HTTP 200**。

> 文件未明示 hex 大小寫，實作時兩端都 `strtoupper` 後比對最穩。

---

## 3. 體系 2-A：導轉 / 信用卡授權 SHA-1 PassCode

PayNow 舊版用 **SHA-1** 產生 PassCode（驗證碼），輸入為 **ASCII**，輸出 **HEX**，多數需轉大寫。
各值「直接相接」成一字串，**不含 `+` 號 / `&` 號**（文件中的 `+`/`&` 只是表示「接續」）。

官方加密後範例：`F6ACC37A32D592A90414E1AB0F3DE0DE4474B98A`

```php
function paynow_sha1(string $data, bool $upper = true): string {
    $h = sha1($data);                 // ASCII 輸入，hex 輸出（小寫）
    return $upper ? strtoupper($h) : $h;
}

// 導轉送出（傳遞碼）
$passCode = paynow_sha1($webNo . $orderNo . $totalPrice . $apicode);
// 導轉回傳驗簽（信用卡 / WebATM / 銀聯 / 分期 / 超商條碼）
$verify   = paynow_sha1($webNo . $orderNo . $totalPrice . $merchantPassword . $tranStatus);
// PassCode2（ibon/FamiPort/icash 成功）
$passCode2 = paynow_sha1($passCode . $receiverEmail);   // 轉大寫
```

> 完整 PassCode 組成總表見 `references/cashflow-legacy-api.md` 第 6 節。

官方 C# 原文：
```csharp
public string SHA1Encrypt(string data) {
  SHA1CryptoServiceProvider sha1 = new SHA1CryptoServiceProvider();
  var keyBytes = Encoding.Default.GetBytes(data);
  var hash = sha1.ComputeHash(keyBytes);
  return BitConverter.ToString(hash).Replace("-", "");
}
```

---

## 4. 體系 2-B：AES256（CBC + PaddingMode.Zeros，base64）

舊版背景交易（PayNowAPI_JS.aspx）的業務 JSON 用 **AES256** 加密。**CBC 模式 + PaddingMode.Zeros**
（不是 PKCS#7），輸出 base64。金鑰用握手取得的 EncryptionKey / EncryptionIV。

官方 C#：
```csharp
private string AES256_Encrypt(string Content, string Key, string IV) {
  byte[] byteString   = Encoding.UTF8.GetBytes(Content);
  byte[] ByteIVString = Encoding.UTF8.GetBytes(IV);
  byte[] ByteKeyString= Encoding.UTF8.GetBytes(Key);
  RijndaelManaged rDel = new RijndaelManaged {
    Key = ByteKeyString, IV = ByteIVString,
    Mode = CipherMode.CBC, Padding = PaddingMode.Zeros
  };
  ICryptoTransform cTransform = rDel.CreateEncryptor();
  byte[] ResultArray = cTransform.TransformFinalBlock(byteString, 0, byteString.Length);
  return Convert.ToBase64String(ResultArray, 0, ResultArray.Length);
}
```

PHP 對照（zero padding：自行補 0 到 16 bytes 倍數，openssl 用 `OPENSSL_ZERO_PADDING`）：
```php
function paynow_aes256_encrypt(string $content, string $key, string $iv): string {
    $blockSize = 16; // AES block = 16 bytes（CBC）
    $pad = $blockSize - (strlen($content) % $blockSize);
    if ($pad !== $blockSize) {
        $content .= str_repeat("\0", $pad);   // PaddingMode.Zeros：補 \0
    }
    $raw = openssl_encrypt(
        $content, 'aes-256-cbc', $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv
    );
    return base64_encode($raw);
}

function paynow_aes256_decrypt(string $b64, string $key, string $iv): string {
    $raw = openssl_decrypt(
        base64_decode($b64), 'aes-256-cbc', $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv
    );
    return rtrim($raw, "\0");   // 去除 zero padding
}
```

> 重點：AES**256** 要求 Key 為 32 bytes。握手回的 `EncryptionKey`（如 `9a704b9059f14ea18103ac874a8d42c3`，
> 32 字元）與 `EncryptionIV`（如 `adb710074b47cfc6`，16 字元）以 UTF-8 bytes 當 key / iv。

---

## 5. 體系 2-B：檢核碼握手（GP / GK 換鑰）

### 5.1 bootstrap 固定金鑰（握手第一步用）

```
Key：paynowencryptpaynowcomtw28229955   （32 bytes）
IV ：encrypt282299550                    （16 bytes）
```

> 這是「取檢核碼」階段的固定 AES256 金鑰（公開於官方文件）；用來加密 GP / GK 握手的 JStr，
> 取得後續操作真正要用的「動態 EncryptionKey / EncryptionIV」。**勿用此固定金鑰加密業務資料。**

### 5.2 三段握手流程

```
OP=GP（取隨機檢查碼 CheckNum）
  Request JStr（用 bootstrap Key/IV AES256 加密）內含：
    mem_cid（商家帳號 統編/身分證）、PassCode、TimeStr
  Response（bootstrap Key/IV 解密）：{ mem_cid, PassCode, TimeStr, CheckNum }

OP=GK（取動態 EncryptionKey / EncryptionIV）
  Request JStr（bootstrap Key/IV AES256 加密）內含：
    mem_cid、PassCode、TimeStr、CheckNum（上一步取得的 8 碼）
  Response（bootstrap Key/IV 解密）：{ PassCode, EncryptionKey, EncryptionIV }

OP=<操作>（CP_gp / R_gp / CPA_gp / PQS_gp …）
  業務 JSON → 用 GK 取得的 EncryptionKey/EncryptionIV AES256 加密 → 字串對半拆成 JStr1 + JStr2
  → UrlEncode；另帶 mem_cid + TimeStr（操作時用的）+ CheckNum
```

### 5.3 GP / GK 的 PassCode 規則

| OP | 送出 PassCode | 回覆 PassCode |
|----|---------------|---------------|
| **GP** | `mem_cid` + GP 規則加權檢核碼 串接後 **SHA256** 轉大寫 | `mem_cid` + GK 規則加權檢核碼 串接後 **HMACSHA256**（key=CheckNum）轉大寫 |
| **GK** | `mem_cid` + GK 規則加權檢核碼 串接後 **HMACSHA256**（key=CheckNum）轉大寫 | `mem_cid` + GP 規則加權檢核碼 串接後 **SHA256** 轉大寫 |

官方回傳範例：
```
// GP 回應
{ "mem_cid":"28229955", "PassCode":"CCE089C41567EFB631A3E82AA20D54B3F3D1BE841806C748AA9E39B57F301D73",
  "TimeStr":"2321163000", "CheckNum":"65813612" }
// GK 回應
{ "PassCode":"D35792712EBE651B297B4CD543086D47A68CCBB1338F19B19AD0EE8AA49F1355",
  "EncryptionKey":"9a704b9059f14ea18103ac874a8d42c3", "EncryptionIV":"adb710074b47cfc6" }
```

### 5.4 操作層 PassCode（業務 JSON 內）

操作層業務 PassCode 用 **SHA-1**（見第 3 節公式），如：
`strtoupper(sha1("2822" + UserID + 商家交易密碼 + "9955"))`。

---

## 6. 加權檢核碼演算法（GP / GK 規則）

握手 PassCode 的「加權檢核碼」是 16 碼數字，由商家帳號重排 + 固定加權基數計算。

### 規則摘要

```
加權基數（23 碼，固定）：93193193193193193193193
加權權數（23 碼）：由商家帳號（9 碼，不足左補 0）依 GP / GK 不同規則重排組成

GP 規則加權權數 = 商家帳號前5碼 + TimeStr(10碼) + TimeStr前4碼 + 商家帳號後4碼
GK 規則加權權數 = 商家帳號後5碼 + TimeStr(10碼) + TimeStr前4碼 + 商家帳號前4碼

計算檢查碼 1 碼：
  將「加權權數」與「加權基數」逐位相乘，各取乘積個位數，全部相加 = S
  檢查碼 = 10 - (S % 10)；若為 10 則取 0
最終加權檢核碼（16 碼）= 加權權數前 15 碼 + 檢查碼 1 碼
```

### 官方 GP 範例

```
mem_cid = 028229955（9 碼，左補 0）, TimeStr = 9328005018
GP 加權權數 = 02822(前5) + 9328005018(TimeStr) + 9328(TimeStr前4) + 9955(後4)
            = 029955932800501893280282   (23 碼)
逐位乘基數取個位相加 = 104 → 104 % 10 = 4 → 檢查碼 = 10 - 4 = 6
GP 加權檢核碼 = 0282293280050186  (前15碼 + 6)
```

### 官方 GK 範例

```
mem_cid = 028229955, TimeStr = 9328005018
GK 加權權數 = 29955(後5) + 9328005018 + 9328 + 0282 = 29955932800501893280282
逐位乘基數 = 104 → 餘 4 → 檢查碼 6
GK 加權檢核碼 = 2995593280050186
```

> 另一組 PassCode 範例（TimeStr=4211160609, mem_cid=28229955）：
> GP CheckStr `0282242111606098` → PassCode 串 `282299550282242111606098` →
> SHA256 = `24551CB46438C363FB54CB670B6005EB98815AF67CCC52742891CBB32562D885`。

---

## 7. TimeStr 時間戳格式

10 碼數字 = 西元年最後 1 碼 + 一年起算天數(3 碼) + 時(2) + 分(2) + 秒(2)。

```
範例 2019-11-24 00:50:18：
  年最後 1 碼：2019 → 9
  起算天數：11/24 是該年第 328 天 → 328
  時/分/秒：00 / 50 / 18
  TimeStr = 9 + 328 + 00 + 50 + 18 = 9328005018
```

PHP：
```php
function paynow_timestr(?int $ts = null): string {
    $ts = $ts ?? time();
    $y  = (int) date('Y', $ts);
    return substr((string) $y, -1)                         // 年最後一碼
         . str_pad((string) ((int) date('z', $ts) + 1), 3, '0', STR_PAD_LEFT) // 一年第幾天（date('z') 從 0 起算 → +1）
         . date('H', $ts) . date('i', $ts) . date('s', $ts);
}
```

> 注意：PHP `date('z')` 從 0 起算，PayNow `DayOfYear`（C#）從 1 起算，故需 `+1`。

---

## 8. SHA256 / HMACSHA256（背景交易握手用）

官方 C#（皆 ASCII 輸入、hex 輸出、轉大寫）：

```csharp
public string SHA256_Encrypt(string Content) {
  var sha256 = new SHA256CryptoServiceProvider();
  byte[] ByteString = Encoding.ASCII.GetBytes(Content);
  ByteString = sha256.ComputeHash(ByteString);
  string r = null; foreach (byte bt in ByteString) r += bt.ToString("x2");
  return r.ToUpper();
}
public string HMACSHA256Encrypt(string content, string key) {
  byte[] ByteString = Encoding.ASCII.GetBytes(content);
  byte[] ByteKey    = Encoding.ASCII.GetBytes(key);
  HMACSHA256 h = new HMACSHA256(ByteKey);
  byte[] hb = h.ComputeHash(ByteString);
  return BitConverter.ToString(hb).Replace("-", "").ToUpper();
}
```

PHP 對照：
```php
function paynow_sha256_upper(string $content): string {
    return strtoupper(hash('sha256', $content));            // ASCII 輸入，hex 大寫
}
function paynow_hmac_sha256_upper(string $content, string $key): string {
    return strtoupper(hash_hmac('sha256', $content, $key)); // hex 大寫
}
```

---

## 9. TripleDES（票券核銷 / 分店資料）

票券核銷（T_S / T_G）與分店資料用 **TripleDES**：**ECB 模式 + PaddingMode.Zeros**，base64 輸出。
Key 24 bytes = `"1234567890" + <8 碼 key> + "123456"`，IV = `"12345678"`（ECB 其實不用 IV，但官方範例有設）。

官方 C#（分店資料範例，key 段為 `28229955`）：
```csharp
public string Encrypt(string content) {
  var keystr = "28229955";
  TripleDes.IV  = Encoding.UTF8.GetBytes("12345678");
  TripleDes.Key = Encoding.UTF8.GetBytes("1234567890" + keystr + "123456"); // 24 bytes
  TripleDes.Mode = CipherMode.ECB;
  TripleDes.Padding = PaddingMode.Zeros;
  var data = Encoding.UTF8.GetBytes(content);
  var enc  = TripleDes.CreateEncryptor().TransformFinalBlock(data, 0, data.Length);
  return Convert.ToBase64String(enc).Replace(' ', '+');
}
// 文件註：公鑰 12345678、私鑰 123456789028229955123456（共 24 碼，28229955 為 key 值）
// 票券核銷（T_S / T_G）的 Key 固定為 28229955
```

PHP 對照（DES-EDE3 ECB + zero padding）：
```php
function paynow_tripledes_encrypt(string $content, string $key8 = '28229955'): string {
    $key = '1234567890' . $key8 . '123456';   // 24 bytes
    $block = 8;                                 // 3DES block = 8 bytes
    $pad = $block - (strlen($content) % $block);
    if ($pad !== $block) { $content .= str_repeat("\0", $pad); } // Zeros
    $raw = openssl_encrypt($content, 'des-ede3', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    return base64_encode($raw);
}
```

---

## 10. ApplePay Signature（舊版商家驗證）

舊版 ApplePay 商家驗證（`mpay.paynow.com.tw/api/ApplePay/GetTransactionSession`）的 `Signature` 算法：

```
1. 將參數依英文字母 A→Z 排序（字母相同看下一字母），參數名稱與參數值串聯
   （POST 時 ValidationURL 不加入；回傳時 TransactionSession 不加入）
2. 整串轉小寫後 UrlEncode
3. 對該字串做 SHA256，**加密 Key 為商家 PayNow 交易密碼**
4. 加密結果轉小寫，放入 Signature
```

官方 POST 範例（密碼 `1234567890`）：
```
排序串聯：DisplayNamePayNowDomainNamempay.paynow.com.twMemCid28229955MerchantIdentifiermerchant.tw.com.paynow.pay
轉小寫  ：displaynamepaynowdomainnamempay.paynow.com.twmemcid28229955merchantidentifiermerchant.tw.com.paynow.pay
SHA256(key=1234567890) → 613b0106b509b252c3d9468723fc7a75dd69101624353646e608f3e3c44e3a31
```

> 此處 SHA256「以密碼為 Key」實際是 **HMAC-SHA256**（key=交易密碼），輸出小寫 hex。

PHP：
```php
function paynow_applepay_signature(array $params, string $merchantPassword, array $exclude = ['ValidationURL','TransactionSession']): string {
    foreach ($exclude as $k) { unset($params[$k]); }
    ksort($params, SORT_STRING);                  // A→Z
    $s = '';
    foreach ($params as $k => $v) { $s .= $k . $v; }
    $s = strtolower($s);
    $s = rawurlencode($s);                          // 文件：轉小寫後 UrlEncode
    return strtolower(hash_hmac('sha256', $s, $merchantPassword));
}
```

> ⚠️ 文件對「SHA256 + Key」與「UrlEncode 時機」描述較簡略；實際串接務必以官方提供的測試向量
> （上方範例輸出）核對你的實作，必要時嘗試「不 UrlEncode」版本比對。標記為 `[需以測試向量驗證]`。

---

## 11. PHP 實作對照速覽

| PayNow 演算法 | PHP |
|---------------|-----|
| SHA-1 PassCode | `strtoupper(sha1($concat))` |
| SHA256（握手 / 一般） | `strtoupper(hash('sha256', $content))` |
| HMAC-SHA256（握手 / Webhook） | `strtoupper(hash_hmac('sha256', $content, $key))` |
| AES256 CBC + Zeros | `openssl_encrypt($padded, 'aes-256-cbc', $key, OPENSSL_RAW_DATA\|OPENSSL_ZERO_PADDING, $iv)` → base64 |
| TripleDES ECB + Zeros | `openssl_encrypt($padded, 'des-ede3', $key24, OPENSSL_RAW_DATA\|OPENSSL_ZERO_PADDING)` → base64 |
| TimeStr | 見第 7 節（注意 `date('z')+1`） |

> 完整可套用的 PHP class 見 `references/php-examples.md`（`PaynowLegacyCrypto`）。
