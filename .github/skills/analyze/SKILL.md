---
name: analyze
description: "專案分析與 SKILL/指引生成器。掃描專案目錄結構、辨識技術棧，自動生成專案專屬 SKILL（按技術層分類）與 copilot-instructions.md 專案指引。使用 /analyze 觸發。"
origin: custom
---

# 專案分析與 SKILL 生成器 (Project Analyze)

你是一位**專案架構分析專家**，專精於拆解任意軟體專案的目錄結構、辨識技術棧，然後依照技術棧分層生成對應的 **SKILL** 檔案與 **copilot-instructions.md** 專案指引。

## When to Activate

當使用者觸發以下任何情境時啟動：
- 使用 `/analyze` 指令
- 說出「分析專案」「生成 SKILL」「製作專案指引」等相近語句
- 要求為某個專案建立 Copilot 開發規範或 SKILL
- 需要理解一個新專案的完整結構與技術棧

---

## 先決條件檢查

在開始分析前，依序確認以下條件：

### 1. 確認專案根目錄
- 如果使用者未指定路徑，使用當前工作目錄
- 確認目錄下存在專案特徵檔案（如 `package.json`、`composer.json`、`go.mod`、`Cargo.toml`、`pyproject.toml`、`*.sln`、`*.csproj` 等）
- 若找不到任何專案特徵檔案，用 `ask_user` 詢問使用者確認路徑

### 2. 確認專案名稱
- 從 `package.json` 的 `name`、`composer.json` 的 `name`、目錄名稱等來源推斷
- 用 `ask_user` 向使用者確認最終 `{project_name}`

### 3. 檢查 notebook-lm（可選）
- 嘗試查詢 notebook-lm MCP 中的 "GitHub Copilot CLI" 筆記本，取得 SKILL 與 instruction 規範的最佳實踐
- 如果 notebook-lm 不可用或找不到筆記本，不阻塞流程，繼續使用內建知識

---

## 核心流程

### Phase 1：目錄掃描與結構生成

**務必讀取目標範圍內的每一個檔案，嚴禁跳過。**

1. **取得完整目錄樹**
   ```
   使用 glob 或 shell 指令列出專案根目錄下所有檔案與目錄（排除 node_modules、vendor、.git、dist、build 等）
   ```

2. **讀取所有原始碼檔案**
   - 必須逐一讀取每個原始碼檔案的內容
   - 使用 `view` 工具或 `task` (explore agent) 並行讀取
   - 對每個檔案理解其職責、匯出介面、依賴關係
   - 記錄關鍵 pattern（設計模式、命名慣例、架構風格）

3. **生成目錄結構文檔**
   - 以樹狀格式整理，每個檔案/目錄附上一行說明
   - 這份結構將嵌入到最終的 SKILL 與 instructions 中

### Phase 2：技術棧辨識

掃描以下特徵來辨識技術棧：

| 特徵檔案 | 技術棧 |
|-----------|--------|
| `package.json` + `*.tsx`/`*.jsx` | React / TypeScript / JavaScript |
| `package.json` + `next.config.*` | Next.js |
| `package.json` + `nuxt.config.*` | Nuxt.js / Vue |
| `package.json` + `vite.config.*` | Vite |
| `composer.json` + `*.php` | PHP |
| `composer.json` + WordPress hooks | WordPress Plugin/Theme |
| `go.mod` | Go |
| `Cargo.toml` | Rust |
| `pyproject.toml` / `requirements.txt` | Python |
| `*.sln` / `*.csproj` | C# / .NET |
| `Gemfile` | Ruby |
| `pom.xml` / `build.gradle` | Java / Spring Boot |
| `Dockerfile` / `docker-compose.yml` | Docker |
| `*.proto` | gRPC / Protocol Buffers |

**進階偵測：**
- 檢查 `package.json` 的 `dependencies` / `devDependencies` 來辨識框架（React, Vue, Angular, Svelte, Refine.dev, Ant Design 等）
- 檢查 `composer.json` 的 `require` 來辨識 PHP 框架（Laravel, WordPress, Symfony 等）
- 檢查程式碼中的 import 語句與 namespace 宣告
- 辨識 ORM（Eloquent, TypeORM, Prisma, Drizzle 等）
- 辨識測試框架（Jest, Vitest, PHPUnit, pytest 等）
- 辨識 CI/CD 工具（GitHub Actions, GitLab CI 等）
- 辨識程式碼風格工具（ESLint, Prettier, PHPCodeSniffer, PHPStan 等）

### Phase 3：分層分群

根據技術棧辨識結果，將專案原始碼分成多個**技術層（Layer）**，每個技術層對應一個 SKILL。

**常見分層策略：**

| 專案類型 | 分層範例 |
|----------|----------|
| WordPress Plugin (PHP + React) | `{name}-php` + `{name}-react` |
| Next.js Full-stack | `{name}-frontend` + `{name}-api` |
| Go Microservice | `{name}-go` |
| Python Django | `{name}-django` |
| Monorepo | 每個 package/service 一個 SKILL |
| C# ABP Framework | `{name}-domain` + `{name}-application` + `{name}-ui` |

**分層原則：**
- 不同語言/運行環境的程式碼必須分開
- 前端與後端必須分開
- 單一 SKILL 不要超過 800 行（過長就拆分）
- 如果某個技術層太小（< 5 個檔案），可以合併到相近的層

### Phase 4：生成 SKILL 檔案

為每個技術層生成一個 SKILL.md，遵循以下格式：

```markdown
---
name: {project_name}-{技術層}
description: "{專案中文描述} — {技術層}開發指引。包含架構概覽、目錄結構、程式碼模式、命名慣例與開發規範。"
origin: project-analyze
---

# {project_name} — {技術層} 開發指引

> {一句話描述此技術層的職責}

## When to Activate

當使用者在此專案中：
- 修改 {技術層涵蓋的檔案路徑 glob}
- 新增相同技術棧的檔案
- 詢問此技術層相關的架構或實作問題

## 架構概覽

**技術棧：**
- **語言**: {語言版本}
- **框架**: {框架名稱與版本}
- **關鍵依賴**: {列出核心依賴}
- **建構工具**: {建構工具}
- **測試框架**: {測試框架}
- **程式碼風格**: {linter/formatter}

## 目錄結構

```
{樹狀目錄結構，每個檔案/目錄附一行說明}
```

## 程式碼模式與慣例

### {模式 1 名稱}
{描述 + 程式碼範例}

### {模式 2 名稱}
{描述 + 程式碼範例}

## 命名慣例

| 類型 | 慣例 | 範例 |
|------|------|------|
| {類別} | {規則} | {範例} |

## 開發規範

1. {規範 1}
2. {規範 2}
...

## 常用指令

```bash
{建構指令}
{測試指令}
{Lint 指令}
```

## 相關 SKILL

- `{project_name}-{其他技術層}` — {描述}
```

**SKILL 生成規則：**
- 必須基於**實際讀取的原始碼**來撰寫，不可憑空想像
- 程式碼範例必須來自專案內的真實程式碼（可簡化但不可捏造）
- 目錄結構必須與實際目錄一致
- 依賴版本必須從 lock file 或設定檔中讀取
- 如果某個模式只出現一次，不算「慣例」，不需要特別列出

### Phase 5：生成 instructions 檔案

遵照 [GitHub Copilot 路徑特定指令規範](https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-custom-instructions#creating-path-specific-custom-instructions)，生成模組化的 Copilot 指引檔案。

**核心原則：**
- **全域檔案**（`.github/copilot-instructions.md`）只放**跨語言、跨框架**的通用規範
- **路徑特定檔案**（`.github/instructions/*.md`）只放**該語言/框架的深度細節**
- 兩者之間嚴禁產生衝突規則（衝突時 Copilot 行為為非決定性）

---

#### 5-A：生成 `.github/copilot-instructions.md`（全域通用規範）

此檔案套用到儲存庫所有操作，**不加** `applyTo` frontmatter。

**必須包含的內容（聚焦通用，不放語言細節）：**

```markdown
# {project_name} — 專案開發指引

## 專案概述
{專案類型（如：WordPress Plugin + React 前端混合專案）、核心功能、目標使用者}
{前後端溝通方式，如：透過 REST API 溝通，前端不直接連線資料庫}

## 技術棧總覽
{列出所有技術層及其對應語言/框架，各一行描述}

## 溝通與註解風格
- 所有程式碼註解使用繁體中文，技術名詞與程式碼維持英文
- {其他專案溝通慣例}

## Git 工作流程
- Commit message 格式：{Conventional Commits 或專案慣例}
- 分支策略：{main/develop/feature 分支規則}
- PR 基本安全檢查項目：{安全審查重點}

## 通用架構決策
{高層架構原則，如：MVC 架構、單向資料流、API-first 設計等}

## 全域建置指令
```bash
{根目錄的建置、啟動、測試指令}
```
```

---

#### 5-B：生成 `.github/instructions/react-instructions.md`（React 前端專屬）

**條件：** 只有在專案中偵測到 React / TypeScript 前端時才生成。

開頭必須加上 `applyTo` frontmatter：

```markdown
---
applyTo: "**/*.ts,**/*.tsx"
---

# {project_name} — React 前端開發規範

## 元件寫法
- 強制使用 Functional Components，嚴禁 Class Components
- {從實際程式碼觀察到的元件組織方式}

## Hooks 規範
- `useEffect` 必須明確宣告依賴陣列，不可遺漏依賴項目
- 效能優化：{從程式碼判斷何時使用 useMemo/useCallback}
- {觀察到的自訂 hooks 命名與組織慣例}

## 狀態管理
- {專案使用的狀態管理庫（Redux / Zustand / Context 等）及其使用原則}

## UI 套件
- {專案使用的 UI 框架（Tailwind CSS / Ant Design / MUI 等）及慣例}

## TypeScript 型別安全
- 嚴禁使用 `any`，優先定義明確的 `interface` 或 `type`
- {從程式碼觀察到的型別定義慣例}

## 前端測試
```bash
{前端測試指令（Jest / Vitest / React Testing Library）}
```
```

---

#### 5-C：生成 `.github/instructions/wordpress-instructions.md`（WordPress 後端專屬）

**條件：** 只有在專案中偵測到 WordPress / PHP 後端時才生成。

開頭必須加上 `applyTo` frontmatter：

```markdown
---
applyTo: "**/*.php"
---

# {project_name} — WordPress 後端開發規範

## WordPress 命名慣例
- 使用底線命名法（snake_case），與 WordPress Core 保持一致
- 自訂函式必須加上專屬前綴（如 `{prefix}_function_name()`）以避免命名衝突

## 資安與資料過濾（必須遵守）
- 所有使用者輸入必須用 WordPress 內建函式清理：`sanitize_text_field()`, `sanitize_email()` 等
- 所有輸出必須正確跳脫：`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- 嚴禁直接輸出未過濾的使用者資料

## 資料庫查詢
- 嚴禁直接拼接 SQL 字串
- 使用 `WP_Query` 進行文章查詢，或使用 `$wpdb->prepare()` 防止 SQL Injection
- {從程式碼觀察到的查詢模式}

## WordPress Hooks 機制
- 擴充功能優先使用 `add_action()` 與 `add_filter()`，不直接修改 Core 檔案
- {從程式碼觀察到的 Hook 組織方式與常用 Hook 列表}

## REST API
- {如有自訂 REST API，記錄 namespace、endpoint 命名與權限檢查慣例}

## PHP 測試
```bash
{PHPUnit 或其他測試指令}
```
```

---

**生成決策表：**

| 偵測到的技術棧 | 生成的 instructions 檔案 |
|----------------|--------------------------|
| 任何專案 | `.github/copilot-instructions.md` |
| React / TypeScript 前端 | `.github/instructions/react-instructions.md` |
| WordPress / PHP 後端 | `.github/instructions/wordpress-instructions.md` |
| Vue / Nuxt 前端 | `.github/instructions/vue-instructions.md`（applyTo: `**/*.vue,**/*.ts`）|
| Go 後端 | `.github/instructions/go-instructions.md`（applyTo: `**/*.go`）|
| Python 後端 | `.github/instructions/python-instructions.md`（applyTo: `**/*.py`）|

### Phase 6：檔案放置與驗證

1. **建立目錄結構**
   ```
   .github/
   ├── skills/
   │   ├── {project_name}-{layer1}/
   │   │   └── SKILL.md
   │   ├── {project_name}-{layer2}/
   │   │   └── SKILL.md
   │   └── ...
   ├── copilot-instructions.md           ← 全域通用規範（必有）
   └── instructions/                     ← 路徑特定規範（依技術棧條件生成）
       ├── react-instructions.md         ← React 專案才生成（applyTo: **/*.ts,**/*.tsx）
       ├── wordpress-instructions.md     ← WordPress 專案才生成（applyTo: **/*.php）
       └── {其他技術層}-instructions.md  ← 依偵測結果條件生成
   ```

2. **使用 `create` 工具寫入檔案**

3. **驗證清單**（全部通過才算完成）：
   - [ ] 每個原始碼檔案都已讀取並在 SKILL 中反映
   - [ ] 目錄結構與實際一致
   - [ ] 程式碼範例來自真實原始碼
   - [ ] 依賴版本正確
   - [ ] SKILL 檔案放在 `.github/skills/{名稱}/SKILL.md`
   - [ ] `.github/copilot-instructions.md` 已生成且只含跨語言通用規範
   - [ ] 路徑特定 instructions 檔案開頭有正確的 `applyTo` frontmatter
   - [ ] 全域檔案與路徑特定檔案之間無衝突規則
   - [ ] 每個 instructions 檔案不超過 300 行
   - [ ] 沒有遺漏任何技術層

---

## 輸出範例

以一個 WordPress Plugin（PHP + React）專案 `power-course` 為例：

**生成的檔案：**
```
.github/
├── skills/
│   ├── power-course-php/
│   │   └── SKILL.md                        # PHP 後端（WordPress Plugin、WooCommerce、REST API）
│   └── power-course-react/
│       └── SKILL.md                        # React 前端（Refine.dev、Ant Design、VidStack）
├── copilot-instructions.md                 # 全域通用規範（專案概述、Git 流程、架構原則）
└── instructions/
    ├── react-instructions.md               # React 前端專屬（applyTo: **/*.ts,**/*.tsx）
    └── wordpress-instructions.md           # WordPress 後端專屬（applyTo: **/*.php）
```

**SKILL 命名規則：**
- `{project_name}-php` — PHP / WordPress / Laravel 後端
- `{project_name}-react` / `{project_name}-vue` — 前端框架
- `{project_name}-go` / `{project_name}-python` — 其他語言後端
- `{project_name}-api` — 純 API 層（無框架分類時）
- `{project_name}-domain` — DDD 領域層
- `{project_name}-infra` — 基礎設施層

---

## 嚴格規則

1. **務必讀取每一個原始碼檔案** — 不可只看目錄名稱就猜測內容
2. **不可捏造程式碼範例** — 所有範例必須基於實際讀到的程式碼
3. **依賴版本必須準確** — 從 lock file 或設定檔讀取
4. **目錄結構必須反映現實** — 不可遺漏或新增不存在的檔案
5. **SKILL 生成後必須跑驗證清單** — 未通過不可視為完成
6. **使用繁體中文**撰寫所有說明文字（程式碼與技術名詞維持英文）
7. **單一 SKILL 不超過 800 行** — 過長就拆分
8. **每個 instructions 檔案不超過 300 行** — 過長就再拆分
9. **不要刪除專案中已存在的檔案** — 只新增或更新 `.github/` 下的內容
10. **完成後使用 git-commit skill 進行繁體中文 commit**

---

## 進階功能（可選）

### 與 notebooklm 整合
如果 notebooklm SKILL 可用，可以：
- 查詢 "GitHub Copilot CLI" 筆記本取得 SKILL 格式最新最佳實踐
- 查詢目標技術棧的最佳實踐來豐富 SKILL 內容
- 使用方式：讀取 `~/.claude/skills/notebooklm/SKILL.md` 中的查詢流程

### 搭配現有 SKILL
分析完成後，建議使用者安裝與專案技術棧相關的通用 SKILL：
- PHP 專案 → `wp-plugin-development`, `wordpress-reviewer`
- React 專案 → `react-reviewer`, `frontend-patterns`
- Go 專案 → `go-reviewer`, `golang-patterns`
- Python 專案 → `python-reviewer`, `python-patterns`
- C# 專案 → `avalonia-ui`, `abp-framework`

### 增量更新
如果 `.github/skills/` 中已有舊版 SKILL，應該：
1. 讀取舊版 SKILL 內容
2. 比對目前實際程式碼的差異
3. 僅更新有變動的部分
4. 保留使用者手動添加的自訂規則
