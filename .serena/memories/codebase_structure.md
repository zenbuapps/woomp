# 目錄結構

```
woomp/
├── woomp.php                  # 外掛入口（版本 header）
├── init.php                   # 初始化、常數定義、子外掛條件載入
├── Compatibility.php          # 相容性處理
├── debug.php                  # 除錯工具
├── admin/                     # 後台功能
│   ├── class-woomp-admin.php      # 後台主類別
│   ├── class-woomp-order.php      # 訂單管理
│   ├── class-woomp-product.php    # 商品管理
│   ├── class-woomp-email.php      # 郵件
│   ├── class-woomp-payment-*.php  # 金流相關
│   ├── class-woomp-shipping-*.php # 物流相關
│   ├── settings/              # WooCommerce 設定頁籤
│   ├── resources/             # 後台資源（訂閱管理、結帳）
│   ├── css/ js/               # 後台靜態資源
│   └── partials/              # 後台模板
├── public/                    # 前台功能
│   ├── class-woomp-public.php     # 前台主類別
│   ├── class-woomp-checkout.php   # 結帳頁
│   ├── class-woomp-order.php      # 訂單頁
│   ├── class-woomp-product.php    # 商品頁
│   ├── css/ js/               # 前台靜態資源
│   └── partials/              # 前台模板
├── includes/                  # 核心類別 + 子外掛模組
│   ├── class-woomp.php        # 主類別（Loader Pattern）
│   ├── class-woomp-loader.php # Hook 管理器
│   ├── class-woomp-logger.php # 日誌
│   └── [子外掛模組目錄...]
├── woocommerce/checkout/      # WC 結帳模板覆寫
├── languages/                 # i18n 語系
├── specs/                     # 規格文件（payuni-embed BDD features）
├── tests/e2e/                 # Playwright E2E 測試
├── vendor/                    # Composer 依賴
├── composer.json / phpcs.xml  # PHP 設定
└── package.json / build.mjs   # Node 建置
```

## 核心架構模式

### Loader Pattern
`Woomp_Loader` 集中管理 WordPress action/filter 註冊。透過 `add_action()`/`add_filter()` 收集 Hook，於 `run()` 批次註冊。

### 金流閘道
所有金流閘道繼承 `WC_Payment_Gateway`（或 `WC_Payment_Gateway_CC`）。

### 入口流程
`woomp.php` → `init.php`（常數 + WC 檢查 + 條件載入子模組）→ `class-woomp.php`（Loader）→ `run()`
