# 常用指令

## 系統工具（Windows / Git Bash）
```bash
git status / git log / git diff     # Git 版本控制
ls / dir                            # 列出目錄
```

## PHP 依賴
```bash
composer install                    # 安裝 PHP 依賴
composer install --no-dev           # 安裝正式依賴（打包用）
```

## 建置與打包
```bash
npm install                         # 安裝 Node 依賴（首次）
npm run build                       # 打包為 build/woomp.zip
```

## 程式碼檢查（Linting）
```bash
vendor/bin/phpcs                    # 執行 PHPCS 程式碼風格檢查
vendor/bin/phpcs --standard=phpcs.xml <file>  # 檢查特定檔案
vendor/bin/phpcbf                   # 自動修復程式碼風格
```

## E2E 測試（Playwright）
```bash
cd tests/e2e && npm install         # 安裝測試依賴（首次）
cd tests/e2e && npx playwright install chromium  # 安裝瀏覽器
cd tests/e2e && npx playwright test              # 執行所有 E2E 測試
cd tests/e2e && npx playwright test <spec-file>  # 執行特定測試
```

## 測試環境
- URL: https://local-turbo.powerhouse.tw/
- 後台: https://local-turbo.powerhouse.tw/wp-admin
- 登入憑證請參照專案根目錄 `.env` 檔案
- 透過 Cloudflare Tunnel 連線本地測試伺服器
