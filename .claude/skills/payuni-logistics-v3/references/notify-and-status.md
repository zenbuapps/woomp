# 物流貨態狀態碼 + Notify Callback

> 來源：docs.payuni.com.tw 官方頁 `#/7/120`（貨態碼）、`#/7/274`（宅配貨態 Notify）、`#/7/291`（超商物流貨態 Notify）。

---

## <a id="ship-status"></a>物流貨態狀態碼（ShipStatus）— 數字代碼

| Code | 中文名稱 | 適用通路 / 說明 |
|---|---|---|
| `91` | 未處理 | 尚未取得出貨單編號（剛建立物流單） |
| `92` | 處理中 | **僅超商物流**：出貨單編號傳送至上游物流服務廠商確認中 |
| `98` | 處理中（已接收） | **僅超商物流-大宗寄倉 B2C**：已配出貨單編號，待傳送至物流中心 |
| `21` | 待出貨 | 等待商店出貨 |
| `22` | 物流驗收 | **僅超商物流**：物流中心驗收中 |
| `31` | 配送中 | 安排出貨中。**超商**可能於此階段發生門市關轉，需重選門市 |
| `32` | 待取貨 | **僅超商物流**：包裹配達門市（PPS101）/ 補配達門市（PPS102）/ 包裹今日 23:59 後將退回物流中心 |
| `33` | 異常訂單 | 配送過程異常 |
| `11` | 已取貨 | 消費者已取貨（成功的終點） |
| `41` | 已取消 | 訂單已被商店取消 |
| `43` | 賠償訂單 | 物流中心 / 黑貓宅配確認賠償訂單產生 |
| `44` | 包裹遺失 | **超商門市/物流中心遺失，18 天協尋後無法找到** → 走遺失賠償流程（**2023-09-19 新增**）|
| `46` | 包裹拋棄 | **僅超商物流-店到店 C2C**：賣家未取且商店未於期限內提供宅配收件資料，物流中心將逾期未轉宅配包裹拋棄處理 |
| `51` | 一般退貨 | 退貨便 / 黑貓退貨 |
| `52` | 買家未取 | 因買家超過取貨期限未取件，產生退貨（取貨期限以買家簡訊截止日為準） |
| `53` | 廠退 | **僅超商物流**：包裹退回物流中心 / 黑貓集貨所 |
| `55` | 賣家未取 | **僅超商物流-店到店 C2C**：買家未取後配達退貨門市，商店未於期限內領取（**2023-12-13 新增**）|
| `56` | 已轉宅配退回 | **僅超商物流-店到店 C2C**：商店提供宅配地址後，物流中心交付黑貓退回（運費貨到付款）（**2023-12-13 新增**）|
| `81` | 門市關轉 | 取件 / 退貨（僅 C2C）門市暫歇（裝修 / 搬遷 / 改店號）；需在期限內重選門市（**2023-09-19 新增**）|
| `82` | 待轉宅配退回 | **僅超商物流-店到店 C2C**：賣家未取後物流中心保管，PAYUNi 寄通知信提醒商店於期限內提供宅配地址（**2023-12-13 新增**）|

### 商店狀態映射建議（zenbu-site `order.shippingStatus`）

| ShipStatus | 建議映射 | 備註 |
|---|---|---|
| 11 | `delivered` | 已取貨完成 |
| 21, 22, 91, 92, 98 | `preparing` | 處理中（含「未處理」）|
| 31, 32 | `shipped` | 配送中 / 待取貨 |
| 33, 81 | `attention` | 異常 / 門市關轉，需商家處理 |
| 41 | `cancelled` |  |
| 43, 44 | `compensation` | 賠償案件（業務層特別處理）|
| 51, 52, 53, 55, 56, 82 | `returned` | 各種退貨 |
| 46 | `abandoned` | 包裹拋棄（不可挽回）|

> 此 mapping 為**建議**，實際以 zenbu-site `order.shippingStatus` enum 為準（`apps/api-gateway/src/commerce/orders/`）。

---

## 超商物流貨態 Notify — 官方頁 `#/7/291`（v1.0）

### 觸發條件

商店在 PAYUNi 後台「物流設定 > 申請/查看物流頁面」設定**貨態 Notify URL** 後，**所有貨態變更**會發 Notify。包含 B2C / C2C 與 C2B（退貨便）。

### 大宗寄倉（B2C）+ 店到店（C2C）回傳參數（EncryptInfo 解密後）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | `SUCCESS`（失敗請參考錯誤碼） |
| `Message` | String | `貨態狀態處理成功({ShipStatus})` |
| `MerID` | String |  |
| `PartnerId` | String | 母代碼 3 碼 |
| `ShipTradeNo` | String | UNi 物流序號（**主鍵連結 order.shippingRef**） |
| `LgsType` | String | B2C / C2C |
| `GoodsType` | Int | 1=常溫，2=冷凍 |
| `ShipType` | Int | `1=SEVEN`（注意官方文件這裡寫 SEVEN，其他 API 是 1=7-ELEVEN） |
| `ShipStatus` | Int | 物流貨態狀態碼 |
| `PickupStoreType` | Int | **僅當 ShipStatus=81 時回傳**：1=取件門市 / 2=退件門市（**2025-03-12 新增**）|
| `ShipStatusDesc` | String | 貨態說明 |
| `ShipStatusTime` | DateTime | `YYYY-MM-DD HH:II:SS` |
| `ApiType` | String | **固定 `ShipStatus`** |

### 退貨便（C2B）回傳參數

差異是不帶 `ShipTradeNo`，改帶 `RefundODNO + ValidationNo`：

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `PartnerId` | String | 3 碼 |
| `RefundODNO` | String | 8 碼 |
| `ValidationNo` | String | 4 碼 |
| `LgsType` | String | C2B |
| `GoodsType` | Int | 1=常溫 |
| `ShipType` | Int | 1=SEVEN |
| `ShipStatus` | Int | 物流貨態狀態碼 |
| `ShipStatusDesc` | String |  |
| `ShipStatusTime` | DateTime |  |
| `ApiType` | String | 固定 `ShipStatus` |

---

## 黑貓宅配貨態 Notify — 官方頁 `#/7/274`（v1.0）

### 觸發條件

開通黑貓宅配時填**貨態 Notify URL**，交寄包裹貨態更新時送 Notify。

### 回傳參數（EncryptInfo 解密）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | SUCCESS |
| `Message` | String |  |
| `MerID` | String |  |
| `TradeType` | Int | 1=正物流（黑貓宅配）/ 2=逆物流（黑貓退貨） |
| `ShipTradeNo` | String |  |
| `OBTNumber` | String | 宅配單號 12 碼 |
| `GoodsType` | Int | 1/2/3 |
| `LgsType` | String | 固定 `HOME` |
| `ShipType` | Int | 固定 2 |
| `FileNo` | String | 用以重新下載託運單 PDF（24 小時內有效） |
| `ShipStatus` | Int | 物流貨態狀態碼 |
| `ShipStatusDesc` | String |  |
| `ShipStatusTime` | Date | `YYYY-MM-DD HH:II:SS` |
| `ApiType` | String | **固定 `ShipStatus`** |

> **FileNo 提醒**：每次貨態更新（特別是「產宅配編號並下載託運單 PDF」成功時）會在 Notify 含 FileNo，請務必於 24h 內備份/下載 PDF；過期後該 FileNo 失效。

---

## 「取件付款完成」Notify（與貨態 Notify 不同）

這類 Notify **只在建立物流單時帶 NotifyURL，且 ServiceType=1（取貨付款）才觸發**。`ApiType` 不帶（區分用）。

| 來源 | 對應 page |
|---|---|
| CVS 取件付款完成 | `#/7/122` 內節 |
| 宅配到付取件完成 | `#/7/268` 內節 |

完整欄位見 `cvs-apis.md#trade-1.3` 與 `home-delivery-apis.md#trade-1.2`。**TradeStatus = 1** 是判斷該訂單已完成取貨付款的依據。

---

## 出貨單列印成功 Notify

`/api/logistics/print_label` 列印成功時，會送 Notify 到**建立物流單時填的 NotifyURL**（不是貨態 Notify URL）。

特徵：`ApiType=Print`，含 `Odno`（B2C 8 碼，C2C 8+4）、`ValidationNo`（C2C 才有）、`PartnerId`（B2C 3 碼）。

---

## 商店端處理慣例（NestJS）

```ts
// payuni-logistics-notify.controller.ts
@Controller('webhooks/payuni/logistics')
export class PayuniLogisticsNotifyController {
  constructor(
    private readonly settings: SettingsService,
    private readonly orders: OrdersService,
    private readonly logger: Logger,
  ) {}

  @Post('ship-status')
  @HttpCode(200)
  async shipStatusNotify(@Body() body: { MerID: string; Version: string; EncryptInfo: string; HashInfo: string }) {
    const creds = await this.settings.getPayuniCreds();
    if (!verifyPayuniHash(creds.hashKey, body.EncryptInfo, creds.hashIv, body.HashInfo)) {
      this.logger.warn('PAYUNi notify hash mismatch', { merId: body.MerID });
      return 'OK'; // 仍回 200，避免 PAYUNi 重送
    }

    const data = decryptPayuni(body.EncryptInfo, creds.hashKey, creds.hashIv);
    if (data.Status !== 'SUCCESS') return 'OK';
    if (data.ApiType !== 'ShipStatus') return 'OK';

    await this.orders.updateShipStatus({
      shippingRef: data.ShipTradeNo,        // 或 RefundODNO（C2B）
      shipStatus: parseInt(data.ShipStatus, 10),
      pickupStoreType: data.PickupStoreType ? parseInt(data.PickupStoreType, 10) : undefined,
      shipStatusTime: data.ShipStatusTime,
    });
    return 'OK';
  }

  @Post('cod-paid')  // 取件付款完成
  @HttpCode(200)
  async codPaidNotify(@Body() body: any) {
    /* 同上 verify + decrypt；TradeStatus===1 → 標記訂單為已完成取貨付款 */
    return 'OK';
  }

  @Post('print-result') // 出貨單列印成功
  @HttpCode(200)
  async printResultNotify(@Body() body: any) {
    /* ApiType==='Print'，記錄 Odno / ValidationNo */
    return 'OK';
  }
}
```

### 重要安全 / 韌性注意

1. **永遠回 200**——PAYUNi 在 5xx / 4xx 時會重送，造成重複處理。對 hash 驗證失敗也回 200（並寫 log）。
2. **Idempotency**：同一個 `ShipTradeNo + ShipStatus + ShipStatusTime` 可能重送多次；用 `(shippingRef, shipStatus, shipStatusTime)` 做唯一 key，重複進來直接 ignore。
3. **不要做 AuthGuard / RolesGuard**：PAYUNi 沒帶任何 cookie/JWT。
4. **Content-Type 必須是 form-urlencoded**：NestJS 預設 `BodyParser` 已支援。
5. **JSON.parse 不適用**：body 是 `URLSearchParams`，要用 NestJS `@Body()` 的物件形式（`urlencoded` 已 parse 成物件）。
