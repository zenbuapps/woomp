# 發票載具整合

> 來源：`v3/Contracts/DTOs/TradeReqHashDTO.php`、`src/gateways/CreditV3.php`

## CarrierType 對照表

| CarrierType | 載具名稱 | CarrierInfo 格式 | CarrierInfo 範例 | InvBuyerName |
|-------------|---------|-----------------|-----------------|-------------|
| *(空白)* | 紙本發票 | 不需要 | — | 選填 |
| `3J0002` | 手機條碼 | `/` + 7 碼大寫英數 | `/ABC1234` | 必填 |
| `CQ0001` | 自然人憑證 | 最長 16 字元 | `AB12345678901234` | 必填 |
| `amego` | 會員載具 | 不需要 | — | 必填 |
| `Donate` | 捐贈碼 | 最長 7 碼數字 | `8957282` | 選填 |
| `Company` | 公司統編 | 8 碼數字（統一編號） | `12345678` | 必填（公司名稱） |

---

## 前端 UI 元素

### 載具選擇下拉選單

```html
<select id="payuni_carrier_type">
    <option value="">紙本發票</option>
    <option value="3J0002">手機條碼</option>
    <option value="CQ0001">自然人憑證</option>
    <option value="amego">會員載具</option>
    <option value="Donate">捐贈</option>
    <option value="Company">公司發票</option>
</select>
```

### 載具資料輸入欄位

每種載具有獨立的輸入欄位，依選擇的 CarrierType 顯示/隱藏：

```html
<!-- 手機條碼 -->
<input id="payuni_carrier_info_3J0002" maxlength="8" placeholder="/" />

<!-- 自然人憑證 -->
<input id="payuni_carrier_info_CQ0001" maxlength="16" />

<!-- 捐贈碼 -->
<input id="payuni_carrier_info_Donate" maxlength="7" />

<!-- 公司統編 -->
<input id="payuni_carrier_info_Company" maxlength="8" />
```

> `amego`（會員載具）不需要 CarrierInfo 輸入欄位。

### 買家名稱

```html
<input id="payuni_inv_buyer_name" maxlength="60" />
```

### Hidden 同步欄位

```html
<input type="hidden" name="payuni_carrier_info" />
```

前端根據當前選擇的 CarrierType，將對應輸入欄位的值同步至此 hidden field。

---

## 前端資料收集

`PayUniService.module.js` 中的 `#getCarrierData()` 方法：

```javascript
#getCarrierData() {
    return {
        payuni_carrier_type: $('#payuni_carrier_type').val(),
        payuni_carrier_info: $('#payuni_carrier_info').val(),
        payuni_inv_buyer_name: $('#payuni_inv_buyer_name').val()
    };
}
```

---

## 後端參數傳遞

`TradeReqHashDTO` 中的發票載具欄位：

```php
// 條件判斷：僅在 CarrierType 有值時才送出
if (!empty($carrier_type)) {
    $params['CarrierType']   = $carrier_type;
    $params['CarrierInfo']   = $carrier_info;
    $params['InvBuyerName']  = $inv_buyer_name;
}
```

---

## 驗證規則

| CarrierType | 驗證規則 |
|-------------|---------|
| `3J0002` | 必須以 `/` 開頭，共 8 字元（含 `/`），限大寫英數 |
| `CQ0001` | 最長 16 字元 |
| `Donate` | 最長 7 碼，純數字 |
| `Company` | 恰好 8 碼，純數字（統一編號） |
| `amego` | 不需要 CarrierInfo |

> PAYUNi 端也會進行驗證，若格式不符會回傳 TOKEN02030-TOKEN02036 等錯誤代碼。
