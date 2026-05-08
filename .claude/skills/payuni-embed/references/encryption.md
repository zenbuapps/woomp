# PAYUNi 加解密規格

> 來源：`includes/payuni/v3/Shared/Utils/EncryptUtils.php`
> 官方文件：https://docs.payuni.com.tw/web/#/7/56

## 金鑰取得

從 PAYUNi 後台取得，儲存於 WordPress options：

| 設定 | 正式環境 Option | 測試環境 Option |
|------|----------------|----------------|
| 商店代號 | `payuni_payment_merchant_no` | `payuni_payment_merchant_no_test` |
| Hash Key | `payuni_payment_hash_key` | `payuni_payment_hash_key_test` |
| Hash IV | `payuni_payment_hash_iv` | `payuni_payment_hash_iv_test` |

---

## AES-256-GCM 加密

### 演算法

- **Cipher**: `aes-256-gcm`
- **Key**: Hash Key（trim 後使用）
- **IV**: Hash IV（trim 後使用）
- **Auth Tag**: 由 `openssl_encrypt` 自動產生

### 流程

```
輸入陣列 → http_build_query() → AES-256-GCM 加密 → 組合格式 → hex 編碼
```

### PHP 實作

```php
public static function encrypt(array $encryptInfo): string
{
    $tag = '';
    $encrypted = openssl_encrypt(
        http_build_query($encryptInfo),  // 1. 參數轉 query string
        'aes-256-gcm',                   // 2. AES-256-GCM 算法
        trim($settings->hash_key),       // 3. Hash Key 作為密鑰
        0,                               // 4. options
        trim($settings->hash_iv),        // 5. Hash IV
        $tag                             // 6. 輸出 auth tag
    );
    // 7. 格式：hex(encrypted + ':::' + base64(tag))
    return trim(bin2hex($encrypted . ':::' . base64_encode($tag)));
}
```

### 加密結果格式

```
hex( base64_encrypted_data + ":::" + base64(authentication_tag) )
```

---

## AES-256-GCM 解密

### 流程

```
hex 字串 → hex2bin → 以 ':::' 分割 → AES-256-GCM 解密 → parse_str → 陣列
```

### PHP 實作

```php
public static function decrypt(string $encryptStr = ''): array
{
    // 1. hex 轉 binary，以 ':::' 分割
    [$encryptData, $tag] = explode(':::', hex2bin($encryptStr), 2);

    // 2. AES-256-GCM 解密
    $encryptInfo = openssl_decrypt(
        $encryptData,
        'aes-256-gcm',
        trim($settings->hash_key),
        0,
        trim($settings->hash_iv),
        base64_decode($tag)               // auth tag 還原
    );

    if ($encryptInfo === false) {
        return [];
    }

    // 3. query string 轉陣列
    parse_str($encryptInfo, $encryptArr);
    return $encryptArr;
}
```

---

## SHA256 HashInfo

### 用途

用於驗證 EncryptInfo 的完整性，防止竄改。

### 計算公式

```
HashInfo = UPPER( SHA256( hash_key + EncryptInfo + hash_iv ) )
```

### PHP 實作

```php
public static function hash_info(string $encrypt = ''): string
{
    return strtoupper(
        hash('sha256', $settings->hash_key . $encrypt . $settings->hash_iv)
    );
}
```

### 驗證流程（接收端）

```php
$calculatedHash = EncryptUtils::hash_info($encryptedData['EncryptInfo']);
if ($calculatedHash !== $encryptedData['HashInfo']) {
    throw new \Exception('Hash 驗證失敗');
}
```

---

## 完整請求組裝範例

```php
// 1. 準備加密參數
$encryptInfo = [
    'MerID'      => 'ABC123',
    'MerTradeNo' => 'ORDER_001',
    'TradeAmt'   => 1000,
    'Timestamp'  => time(),
    // ... 其他參數
];

// 2. 加密
$encrypted = EncryptUtils::encrypt($encryptInfo);

// 3. 計算 Hash
$hash = EncryptUtils::hash_info($encrypted);

// 4. 組裝請求
$requestBody = [
    'MerID'       => 'ABC123',
    'Version'     => '3.0',
    'EncryptInfo' => $encrypted,
    'HashInfo'    => $hash,
];
```
