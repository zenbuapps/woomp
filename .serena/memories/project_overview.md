# Woomp - 專案概述

## 用途
Woomp（好用版擴充 MorePower Addon for WooCommerce）是一個以**台灣市場**為核心的 WooCommerce 擴充外掛。將多家金流、物流與電子發票服務整合於單一外掛中，並透過設定驅動的模組系統進行管理。

- **Plugin Name**: 好用版擴充 MorePower Addon for WooCommerce
- **版本**: 3.4.81
- **授權**: GPL-2.0+
- **需求**: WooCommerce 5.3+、PHP 8.0+
- **GitHub**: j7-dev/woomp（透過 Plugin Update Checker 自動更新）
- **主分支**: `master`

## 整合的金流/物流/發票服務

| 模組 | 目錄 | 說明 |
|------|------|------|
| PayUni 統一金流 v1+v3 | `includes/payuni/` | 信用卡、ATM、CVS 等 |
| 綠界/藍新/速買配 | `includes/ry-woocommerce-tools/` | ECPay、NewebPay |
| 立吉富金流 | `includes/paynow-payment/` | PayNow 付款 |
| 立吉富物流 | `includes/paynow-shipping/` | PayNow 物流 |
| 立吉富電子發票 | `includes/paynow-einvoice/` | PayNow 發票 |
| LINE Pay | `includes/line-pay-for-woo/` | LINE Pay |
| 支付連 PChomePay | `includes/PChomePay-Cart-for-WooCommerce/` | PChome 支付 |
| 綠界電子發票 | `includes/woomp-ecpay-invoice/` | ECPay 發票 |
| EZPAY 電子發票 | `includes/woomp-ezpay-invoice/` | EZPAY 發票 |
| 立吉富物流(woomp版) | `includes/woomp-paynow-shipping/` | PayNow 物流 v2 |

## 設定驅動的模組載入
子外掛在 `init.php` 中根據 `get_option('wc_woomp_setting_*')` 的值條件式載入。每個服務商可從 WooCommerce > 設定 > 好用版擴充 獨立啟用/停用。
