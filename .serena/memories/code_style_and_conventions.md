# 程式碼風格與慣例

## PHPCS 設定（phpcs.xml）
- 基於 **WordPress Coding Standards**（WordPress-Core, WordPress-Docs, WordPress-Extra）
- **Tab 縮排**，縮排寬度 4
- **短陣列語法** `[]`（禁止 `array()`）
- PHP 相容性：**PHP 8.0+**（PHPCompatibility）
- PSR1 命名空間相容

## 排除的規則
- `WordPress.Files.FileName` — 不強制 WordPress 檔名格式
- `WordPress.PHP.YodaConditions.NotYoda` — 不要求 Yoda 條件式
- `WordPress.Security.EscapeOutput.OutputNotEscaped` — 不強制所有輸出 escape
- 部分空白/縮排規則放寬
- `Generic.Commenting.DocComment.MissingShort` — 不要求 docblock 簡述
- `Universal.Classes.RequireFinalClass` — 排除（不強制 final class）

## 排除檢查的目錄
- `vendor/`, `node_modules/`, `tests/`, `js/`, `.idea/`, `release/`

## 命名慣例
- **類別檔名**: `class-{name}.php`（如 `class-woomp-admin.php`）
- **抽象類別**: `abstract-{name}.php`
- **命名空間（v3）**: PSR-4（`J7\Payuni\`）
- **函式**: snake_case（WordPress 慣例）
- **常數**: UPPER_SNAKE_CASE（如 `WOOMP_VERSION`, `WOOMP_PLUGIN_URL`）

## Git 提交風格
- Conventional Commits: `feat:`, `fix:`, `chore:`, `test:`, `docs:` 等
- 中文提交訊息

## 其他
- 前端 JS 使用 ES6 Modules（PayUni v3）搭配 `type="module"`
- 舊版模組使用 jQuery
