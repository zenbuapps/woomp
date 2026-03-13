---
name: wp-e2e-creator
description: 根據 spec 規格文件，識別邊緣案例並生成 Playwright E2E 測試。涵蓋邊緣案例識別框架、測試生成模式，以及 wp-env + Playwright 技術實作。
---

# WordPress Plugin E2E 測試生成器

從 spec 規格到測試生成的完整流程：**讀取 spec 規格 → 識別邊緣案例 → 生成 Playwright E2E 測試**。

---

## 前置條件：讀取 spec 規格

> ⚠️ **若 `specs/` 目錄不存在或其中無任何檔案，立即中止任務。**
> 提示使用者先執行 `@agents/clarifier.agent.md` 進行需求訪談，產生 spec 規格文件後再繼續。

**spec 檔案為所有功能規格與使用者情境的唯一依據，必須優先完整閱讀。**

```bash
# 列出所有 spec 檔案
find spec -type f | sort

# 讀取所有 spec 內容
find spec -type f | xargs cat
```

從 spec 中提取：
- **功能模組清單**（每個模組的主要功能）
- **使用者角色清單**（所有角色及其權限）
- **使用者情境清單**（所有 user story / 使用流程）
- **業務規則**（驗證規則、狀態機、限制條件）

---

## 核心工作流程

```
Step 1: 讀取 spec，建立情境清單
  └── 從 spec 提取使用者角色、功能情境、業務規則

Step 2: 識別邊緣案例
  └── 權限邊界、資料邊界、狀態邊界、整合邊界

Step 3: 規劃測試矩陣
  └── 情境 × 角色 × 邊緣案例 的交叉組合

Step 4: 生成 Playwright 測試
  └── 三階段測試（Admin / Frontend / Integration）
```

---

## Step 2：邊緣案例識別框架

### 2.1 權限邊界

每個功能都必須測試以下邊界：

```typescript
// 權限邊界測試矩陣
const permissionEdgeCases = [
  { role: 'guest',            expectation: 'redirect-to-login' },
  { role: 'subscriber',       expectation: 'show-purchase-prompt' },
  { role: 'purchased',        expectation: 'full-access' },
  { role: 'expired',          expectation: 'show-expired-message' },
  { role: 'admin',            expectation: 'full-access-plus-admin-bar' },
  { role: 'purchased-paused', expectation: 'show-paused-message' },   // 若有此狀態
]
```

### 2.2 資料邊界

```typescript
// 資料邊界測試矩陣
const dataEdgeCases = {
  empty:    { courses: 0,    chapters: 0    },  // 空狀態
  single:   { courses: 1,    chapters: 1    },  // 最小資料集
  large:    { courses: 100,  chapters: 500  },  // 大量資料（分頁測試）
  special:  { title: '中文 & <script> "title"' },  // XSS、特殊字元
  unicode:  { title: '🎓 課程 título' },           // 多語言
  boundary: { price: 0, price_max: 999999 },      // 金額邊界值
}
```

### 2.3 狀態邊界

```typescript
// 狀態邊界（Lifecycle 狀態機）
const stateEdgeCases = [
  // 課程狀態
  'draft',          // 草稿（訂閱者不可見）
  'published',      // 發布（正常存取）
  'private',        // 私密（僅管理員）

  // 存取狀態
  'not-purchased',  // 未購買
  'pending',        // 訂單待處理（limbo state）
  'active',         // 有效存取
  'expired',        // 已過期（固定日期 or 相對天數）
  'refunded',       // 已退款
  'revoked',        // 手動撤銷

  // 訂單狀態
  'pending-payment', 'processing', 'completed', 'cancelled', 'refunded', 'failed',
]
```

### 2.4 整合邊界

```typescript
// 第三方整合邊界
const integrationEdgeCases = [
  'wc-product-deleted-after-purchase',  // 商品刪除後仍有存取權
  'plugin-deactivated-reactivated',     // Plugin 停用/重新啟用後資料完整性
  'multiple-purchases-same-course',      // 重複購買同一課程
  'purchase-during-checkout',           // 同一課程兩個 tab 同時結帳
  'expired-coupon-at-checkout',         // 結帳時優惠券已過期
]
```

---

## Step 3：測試矩陣規劃

### 優先級排序

```typescript
// 測試優先級（P0 最高）
const testPriority = {
  P0: '核心流程 × 正常路徑',     // 絕對要測試
  P1: '核心流程 × 權限邊界',     // 安全性相關
  P2: '次要流程 × 正常路徑',     // 重要功能
  P3: '邊緣案例 × 罕見情境',     // 品質提升
}
```

### 測試矩陣範例

```
功能: 課程教室存取
                  | guest | subscriber | purchased | expired | admin
------------------+-------+------------+-----------+---------+------
前往課程 URL      |  302  |    403     |    200    |   403   |  200
觀看影片          |  N/A  |    N/A     |    ✓      |   N/A   |   ✓
標記章節完成      |  N/A  |    N/A     |    ✓      |   N/A   |   ✓
查看進度          |  N/A  |    N/A     |    ✓      |   N/A   |   ✓
```

---

## Step 4：測試生成模式

### 4.1 使用者情境測試模板

```typescript
// tests/e2e/02-frontend/course-access.spec.ts
import { test, expect } from '@playwright/test'
import { loginAs } from '../helpers/frontend-setup'
import { COURSES } from '../fixtures/test-data'

const accessScenarios = [
  {
    role: 'guest',
    setup: async (page) => { /* 不登入 */ },
    expected: 'redirect-to-login',
  },
  {
    role: 'subscriber-not-purchased',
    setup: async (page) => loginAs(page, 'subscriber', 'password'),
    expected: 'show-purchase-prompt',
  },
  {
    role: 'purchased-student',
    setup: async (page) => loginAs(page, 'student_purchased', 'password'),
    expected: 'full-access',
  },
  {
    role: 'expired-student',
    setup: async (page) => loginAs(page, 'student_expired', 'password'),
    expected: 'show-expired-message',
  },
]

for (const scenario of accessScenarios) {
  test(`課程教室存取 — ${scenario.role}`, async ({ page }) => {
    await scenario.setup(page)
    await page.goto(COURSES.PUBLISHED.classroomUrl)

    switch (scenario.expected) {
      case 'redirect-to-login':
        await expect(page).toHaveURL(/wp-login\.php/)
        break
      case 'show-purchase-prompt':
        await expect(page.locator('[data-testid="purchase-prompt"]')).toBeVisible()
        break
      case 'full-access':
        await expect(page.locator('[data-testid="video-player"]')).toBeVisible()
        break
      case 'show-expired-message':
        await expect(page.locator('[data-testid="expired-notice"]')).toBeVisible()
        break
    }
  })
}
```

### 4.2 邊緣案例測試模板

```typescript
// tests/e2e/03-integration/edge-cases.spec.ts
import { test, expect } from '@playwright/test'
import { wpPost, wpDelete } from '../helpers/api-client'

test.describe('邊緣案例：課程存取控制', () => {

  test('商品刪除後已購買使用者仍保有存取權', async ({ page, request }) => {
    // Arrange：建立課程、購買、刪除 WC 商品
    const courseId = await createTestCourse(request)
    const orderId  = await purchaseCourse(page, courseId)
    await wpDelete(request, `wc/v3/products/${productId}?force=true`)

    // Act：已購買使用者嘗試存取教室
    await loginAs(page, 'student_purchased', 'password')
    await page.goto(`/classroom/${courseId}/`)

    // Assert：仍有存取權（存取記錄不依賴商品存在）
    await expect(page.locator('[data-testid="video-player"]')).toBeVisible()
  })

  test('同時開啟兩個結帳頁面不會重複授予存取權', async ({ browser }) => {
    // 開兩個 browser context 模擬兩個 tab
    const ctx1 = await browser.newContext()
    const ctx2 = await browser.newContext()
    const page1 = await ctx1.newPage()
    const page2 = await ctx2.newPage()

    // 兩個 tab 同時結帳
    await Promise.all([
      completePurchase(page1, COURSES.PUBLISHED.productUrl),
      completePurchase(page2, COURSES.PUBLISHED.productUrl),
    ])

    // Assert：使用者的存取記錄只有一筆（冪等）
    const accessCount = await getAccessCount(page1, COURSES.PUBLISHED.id)
    expect(accessCount).toBe(1)

    await ctx1.close()
    await ctx2.close()
  })

  test('存取到期後立即刷新頁面', async ({ page }) => {
    // Arrange：建立即將到期（1 秒後）的存取記錄
    const expiry = new Date(Date.now() + 1000).toISOString()
    await setAccessExpiry(page, studentId, courseId, expiry)

    // Act：等待到期後刷新
    await loginAs(page, 'student_purchased', 'password')
    await page.goto(`/classroom/${courseId}/`)
    await expect(page.locator('[data-testid="video-player"]')).toBeVisible()

    await page.waitForTimeout(2000)  // 等待到期
    await page.reload()

    // Assert：到期後被拒絕
    await expect(page.locator('[data-testid="expired-notice"]')).toBeVisible()
  })

})
```

### 4.3 API 邊界測試模板

```typescript
// tests/e2e/03-integration/api-edge-cases.spec.ts
test.describe('REST API 邊緣案例', () => {

  test('未授權請求應回傳 401', async ({ request }) => {
    const res = await request.get('/wp-json/plugin/v1/courses', {
      headers: { /* 不帶 nonce */ }
    })
    expect(res.status()).toBe(401)
  })

  test('存取不存在的課程應回傳 404', async ({ request, page }) => {
    await loginAs(page, 'admin', 'password')
    const nonce = await extractNonce(page)

    const res = await request.get('/wp-json/plugin/v1/courses/99999999', {
      headers: { 'X-WP-Nonce': nonce }
    })
    expect(res.status()).toBe(404)
  })

  test('SQL injection 防護', async ({ request, page }) => {
    await loginAs(page, 'admin', 'password')
    const nonce = await extractNonce(page)

    const res = await request.get("/wp-json/plugin/v1/courses?id=1' OR '1'='1", {
      headers: { 'X-WP-Nonce': nonce }
    })
    // 應回傳正常錯誤，不應洩漏資料
    expect(res.status()).toBeOneOf([400, 404])
    const body = await res.text()
    expect(body).not.toContain('wp_')  // 不洩漏資料表名稱
  })

})
```

---

## 技術實作：wp-env + Playwright 環境

### 目錄結構

```
project-root/
├── .wp-env.json
├── tests/
│   └── e2e/
│       ├── package.json            # 獨立 npm（非 pnpm）
│       ├── playwright.config.ts
│       ├── global-setup.ts         # 建立測試資料（各角色使用者）
│       ├── global-teardown.ts      # 還原環境
│       ├── helpers/
│       │   ├── api-client.ts       # REST API CRUD 工具
│       │   ├── frontend-setup.ts   # 角色登入輔助
│       │   ├── lc-bypass.ts        # License check 繞過（若需要）
│       │   └── wc-checkout.ts      # WooCommerce 結帳自動化
│       ├── fixtures/
│       │   └── test-data.ts        # 測試常數（URL、選擇器、名稱）
│       ├── 01-admin/               # Admin SPA 測試
│       ├── 02-frontend/            # 前端頁面測試（各角色情境）
│       └── 03-integration/         # 端對端整合測試（含邊緣案例）
```

### Global Setup：建立所有角色的測試使用者

```typescript
// global-setup.ts
async function globalSetup(config: FullConfig) {
  const BASE = config.projects[0].use.baseURL!
  const browser = await chromium.launch()
  const page = await browser.newPage()

  // 管理員登入
  await page.goto(`${BASE}/wp-login.php`)
  await page.fill('#user_login', 'admin')
  await page.fill('#user_pass', 'password')
  await page.click('#wp-submit')
  await page.context().storageState({ path: '.auth/admin.json' })

  const nonce = await extractNonce(page)
  const opts = { request: page.context().request, baseURL: BASE, nonce }

  // 清除舊測試資料
  await cleanTestData(opts)

  // 建立各角色使用者（對應邊緣案例矩陣）
  await wpPost(opts, 'wp/v2/users', {
    username: 'student_purchased', password: 'password', email: 'purchased@test.com',
    roles: ['subscriber'],
  })
  await wpPost(opts, 'wp/v2/users', {
    username: 'student_expired', password: 'password', email: 'expired@test.com',
    roles: ['subscriber'],
  })

  // 建立測試課程
  const course = await wpPost(opts, 'plugin/v1/courses', {
    title: '[E2E] 測試課程',
    status: 'publish',
  })

  // 授予 purchased 使用者存取（有效）
  await wpPost(opts, `plugin/v1/courses/${course.id}/enroll`, {
    user_id: purchasedUserId,
    expire_date: null,  // 無限期
  })

  // 授予 expired 使用者存取（已過期）
  await wpPost(opts, `plugin/v1/courses/${course.id}/enroll`, {
    user_id: expiredUserId,
    expire_date: '2020-01-01T00:00:00',  // 過去的日期
  })

  await browser.close()
}
```

### 關鍵技術注意事項

#### Workers 必須設為 1

```typescript
export default defineConfig({
  workers: 1,          // WordPress 共用 DB session，不能平行
  fullyParallel: false,
})
```

#### 使用獨立 npm（非 pnpm）

```json
// tests/e2e/package.json
{
  "private": true,
  "type": "module",
  "dependencies": {
    "@playwright/test": "^1.52.0",
    "@wordpress/env": "^11.1.0"
  }
}
```

**原因：** Windows NTFS junction 在 pnpm 中會造成「untrusted mount point」錯誤。

#### 不使用 WP CLI — 改用 REST API

```typescript
// ✅ 使用 REST API（跨平台，無 Docker PATH 問題）
const res = await request.get(`${BASE}/wp-json/wp/v2/posts`)

// ❌ 不要用 execSync（Windows 上 Docker PATH 問題）
execSync('npx wp-env run cli wp post list')
```

#### wp-env 必須從專案根目錄執行

```bash
# ✅ 正確
./tests/e2e/node_modules/.bin/wp-env start

# ❌ 錯誤（找不到 .wp-env.json）
cd tests/e2e && npx wp-env start
```

### REST API Client

```typescript
// helpers/api-client.ts
type ApiOptions = { request: APIRequestContext; baseURL: string; nonce: string }

export async function wpGet<T>(opts: ApiOptions, endpoint: string, params?: Record<string, string>): Promise<T> {
  const url = new URL(`${opts.baseURL}/wp-json/${endpoint}`)
  if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
  const res = await opts.request.get(url.toString(), { headers: { 'X-WP-Nonce': opts.nonce } })
  if (!res.ok()) throw new Error(`GET ${endpoint} → ${res.status()}`)
  return res.json()
}

export async function wpPost<T>(opts: ApiOptions, endpoint: string, data: Record<string, unknown>): Promise<T> {
  const res = await opts.request.post(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: { 'X-WP-Nonce': opts.nonce },
    data,
  })
  if (!res.ok()) throw new Error(`POST ${endpoint} → ${res.status()}`)
  return res.json()
}

export async function wpDelete(opts: ApiOptions, endpoint: string): Promise<void> {
  const res = await opts.request.delete(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: { 'X-WP-Nonce': opts.nonce },
  })
  if (!res.ok()) throw new Error(`DELETE ${endpoint} → ${res.status()}`)
}

// 從 wp-admin 提取 nonce
export async function extractNonce(page: Page): Promise<string> {
  await page.goto(`${page.context().browser()?.newContext}/wp-admin/`)
  return page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '')
}
```

### License Check Bypass（若有 LC 機制）

```typescript
// helpers/lc-bypass.ts
const PLUGIN_FILE = path.resolve(__dirname, '../../../plugin.php')
const BACKUP_FILE = PLUGIN_FILE + '.e2e-backup'
const MARKER = "/* E2E-LC-BYPASS */"
const INJECTION = `$args['lc'] = false; ${MARKER}`

export function applyLcBypass(): void {
  const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')
  if (content.includes(MARKER)) return
  fs.copyFileSync(PLUGIN_FILE, BACKUP_FILE)
  const needle = "Plugin::instance($args);"
  const idx = content.indexOf(needle)
  if (idx === -1) throw new Error('找不到 LC bypass 注入點')
  fs.writeFileSync(PLUGIN_FILE, content.slice(0, idx) + INJECTION + '\n' + content.slice(idx))
}

export function revertLcBypass(): void {
  if (fs.existsSync(BACKUP_FILE)) {
    fs.copyFileSync(BACKUP_FILE, PLUGIN_FILE)
    fs.unlinkSync(BACKUP_FILE)
  }
}
```

---

## 疑難排解

| 症狀 | 原因 | 解法 |
|------|------|------|
| `Cannot connect to Docker` | Docker 未啟動 | 啟動 Docker Desktop |
| `wp-env: command not found` | wp-env 未安裝 | 在 `tests/e2e/` 執行 `npm install` |
| global-setup 中 `spawn UNKNOWN` | execSync 呼叫 Docker | 改用 REST API |
| 章節 URL 404 | Rewrite rules 未刷新 | setup 中造訪永久連結設定頁 |
| 重複資料 slug 加 `-2` | 未強制刪除舊資料 | 清除時加 `?force=true` |
| 訂單編號提取失敗 | WC DOM 結構異動 | 改從 URL 提取（`/order-received/{id}/`）|
| CI 測試不穩定 | 缺少等待 | 補充 `waitForLoadState('networkidle')` |
| Windows pnpm junction 錯誤 | NTFS mount | E2E 相依套件改用 npm |

---

## 最佳實踐

1. **情境先行** — 先從程式碼分析找出所有情境，再決定要測試哪些
2. **角色矩陣** — 每個功能都測試所有相關角色（不只 happy path）
3. **邊緣案例分類** — 用「權限 / 資料 / 狀態 / 整合」四個維度系統化識別
4. **獨立測試資料** — 每個測試用 API 建立自己的資料，執行後清除
5. **REST API 建立資料** — 絕不在測試程式碼中使用 WP CLI 或 execSync
6. **強制刪除** — 建立新資料前刪除所有狀態（publish、draft、trash）
7. **單一 worker** — WordPress 無法處理平行 session
8. **LC bypass 安全** — 備份、注入、並在 CI 的 `if: always()` 中還原
