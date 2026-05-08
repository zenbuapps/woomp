# 技術棧

| 層級 | 技術 |
|------|------|
| 後端 | PHP 8.0+、WordPress 6.x、WooCommerce 5.3+ |
| 前端 | jQuery（舊版）、ES6 Modules（PayUni v3）、Tailwind CSS |
| 自動載入 | Composer PSR-4（`J7\Payuni\` → `includes/payuni/v3/`）、`a7/autoload` |
| 程式碼風格 | WordPress Coding Standards（phpcs.xml）、短陣列語法 `[]`、Tab 縮排 |
| 建置 | `node build.mjs`（archiver ZIP 打包） |
| E2E 測試 | Playwright（TypeScript），位於 `tests/e2e/` |
| HTTP | Guzzle ^6.5.8 |
| Metabox | `oberonlai/wp-metabox` |
| 發票 | `dennykuo/invoice-porter` |
| 更新 | `yahnis-elsts/plugin-update-checker ^5.3` |

## Composer Autoload
```json
{
  "psr-4": {
    "J7\\Payuni\\": "includes/payuni/v3/"
  }
}
```

## PayUni v3 架構（PSR-4 分層）
```
includes/payuni/v3/
├── Applications/     # 應用層（前端 assets: CSS/JS）
├── Contracts/DTOs/   # DTO 物件（SdkDTO, SettingDTO, TradeReqDTO, TradeReqHashDTO）
├── Infrastructure/Http/  # HTTP 通訊（HttpClient, TradeHandler）
├── Shared/Enums/     # 列舉（ECreditTokenType, EMode, EOrderStatus, EUseTokenType）
├── Shared/Helpers/   # 工具類別（Pipeline）
├── Shared/Utils/     # 工具（EncryptUtils, OrderUtils）
└── Bootstrap.php     # 啟動檔
```

## PayUni v1 架構
```
includes/payuni/src/
├── apis/         # API 端點
├── gateways/     # 金流閘道（AbstractGateway, Credit, CreditV3, Atm, Cvs 等）
├── pages/        # 前台頁面（MyAccount）
├── posts/ShopOrder/  # 訂單相關（Ajax, Metabox）
└── shippings/    # 物流
```
