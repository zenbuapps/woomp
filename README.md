# Woomp — MorePower Addon for WooCommerce

## 打包發布（Build）

### 需求

- Bash（WSL、Git Bash 或 macOS/Linux 終端機）
- [Composer](https://getcomposer.org/) （已加入 PATH）
- `zip` 指令（WSL Ubuntu 可執行 `sudo apt-get install -y zip` 安裝）

### 執行打包

在 plugin 根目錄執行：

```bash
bash build.sh
```

### 輸出

打包完成後會在 `build/` 目錄產生 zip 檔，檔名帶有版本號：

```
build/woomp-{VERSION}.zip
```

> 版本號自動從 `woomp.php` 的 `Version:` header 讀取。

### 打包內容

| 包含                                                             | 排除                                                                         |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `includes/`, `admin/`, `public/`, `languages/`, `woocommerce/`   | `.git/`, `.idea/`, `tests/`, `build/`                                        |
| `vendor/`（僅正式依賴，重新由 `composer install --no-dev` 產生） | `debug.php`, `phpcs.xml`, `phpunit.xml`, `tailwind.config.cjs`, `.gitignore` |
| `woomp.php`, `init.php`, `uninstall.php` 等主要檔案              | `composer.json`, `composer.lock`                                             |

### 安裝

將 zip 解壓縮後上傳至 `wp-content/plugins/`，或直接從 WordPress 後台上傳安裝。

```
wp-content/plugins/
└── woomp/
    ├── woomp.php
    ├── vendor/
    └── ...
```
