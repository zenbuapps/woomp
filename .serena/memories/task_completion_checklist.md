# 任務完成檢查清單

當完成一個開發任務時，應執行以下檢查：

## 1. 程式碼風格
```bash
vendor/bin/phpcs --standard=phpcs.xml <changed-files>
```
如有違規，嘗試自動修復：
```bash
vendor/bin/phpcbf --standard=phpcs.xml <changed-files>
```

## 2. 功能驗證
- 確認修改不影響現有功能
- 如涉及金流/物流/發票，確認對應模組正常運作

## 3. E2E 測試（如涉及結帳/付款流程）
```bash
cd tests/e2e && npx playwright test
```

## 4. 安全性檢查
- 所有用戶輸入都經過 sanitize
- 資料庫查詢使用 prepared statements
- 輸出使用適當的 escape 函式
- nonce 驗證用於表單提交

## 5. 相容性
- PHP 8.0+ 語法
- WooCommerce 5.3+ API
- WordPress Coding Standards 合規
