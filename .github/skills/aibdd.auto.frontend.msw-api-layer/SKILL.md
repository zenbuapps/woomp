---
name: aibdd.auto.frontend.msw-api-layer
description: 前端 Stage 1：從 api.yml + discovery features 產出 Zod Schemas、Fixtures、MSW Handlers、API Client 函式。可被 /aibdd.frontend-formulation 調用，也可獨立使用。
user-invokable: true
argument-hint: "[specs-root-dir] [project-root]"
---

# 角色

資料層建構者。從規格產出型別安全的 API 層，讓後續的 Gherkin 測試和元件實作都能直接引用。

---

# Entry 條件

**獨立調用時**，先詢問：
- `SPECS_ROOT_DIR`（discovery 產出的規格根目錄）
- `PROJECT_ROOT`（前端專案根目錄，含 `src/`）

**被 `/aibdd.frontend-formulation` 調用時**，由協調器提供以上資訊，不再詢問。

---

# 輸入

| 來源 | 用途 |
|------|------|
| `${SPECS_ROOT_DIR}/api/api.yml` | schemas → Zod types、endpoints → handlers + client、error codes → 錯誤模擬、enums → 固定資料值 |
| `${SPECS_ROOT_DIR}/features/**/*.feature` | Given 步驟中的具體實體資料 → 寫實的 fixtures（後端 Given 提到的每個值都必須出現在 fixtures） |

---

# 已有基礎建設（勿覆寫）

以下檔案已存在，本階段只在標記處補充，不重建：

- `src/lib/api/client.ts` — `apiClient<T>()`（exported）、`ApiClientError`（exported）；`ApiResponse<T>` / `ApiError` 為內部 interface，不對外 export，無需引用
- `src/lib/api/index.ts` — barrel re-export（在註解處補上新的 re-export）
- `src/lib/types/index.ts` — barrel re-export（在註解處補上新的 re-export）
- `src/mocks/browser.ts` — `setupWorker` + `initMocks()`
- `src/mocks/handlers/index.ts` — handler 彙總（在註解處補上新的 handler）
- `src/components/MSWProvider.tsx` — Client-side MSW 初始化

---

# 產出物

## 1. Zod Schemas + 型別（`src/lib/types/{resource}.schema.ts`）

對 `api.yml` 中每個 `components/schemas`：
- 產生 Zod schema（enum 用 `z.enum()`，物件用 `z.object()`）
- 用 `z.infer<>` 推導 TypeScript 型別並 export
- 在 `src/lib/types/index.ts` 補上 re-export

## 2. 固定資料（`src/mocks/fixtures.ts`）

- 從 `@/lib/types` import 型別
- 產生 `mock{Entity}[]` 陣列，使用**來自 features 的具體資料**（不隨意編造）
- discovery features 的 Given 步驟中提到的每個實體/值都必須存在

## 3. MSW Handlers（`src/mocks/handlers/{resource}.ts`）

對 `api.yml` 中每個端點：
- 產生一個 `http.{method}()` handler
- 正常路徑：回傳 fixtures，支援查詢參數篩選
- 錯誤路徑：對 api.yml 中**每個** error response code，加入對應分支
- 匹配 `client.ts` 的回應封套格式（`{ success, data }` 或 `{ success, error }`）
- 在 `src/mocks/handlers/index.ts` 補上新增的 handler

## 4. API 客戶端函式（`src/lib/api/{resource}.ts`）

對 `api.yml` 中每個端點：
- 產生型別化的非同步函式（命名 = `operationId`）
- 使用 `apiClient<T>()` from `@/lib/api/client`
- 回傳型別與 Zod schema 推導一致
- 在 `src/lib/api/index.ts` 補上 re-export

---

# 輸出結構

```
src/
├── lib/
│   ├── types/
│   │   ├── {resource}.schema.ts   ← Zod schemas + z.infer 型別
│   │   └── index.ts               ← 補上 re-export（已有）
│   └── api/
│       ├── {resource}.ts          ← API 客戶端函式
│       └── index.ts               ← 補上 re-export（已有）
└── mocks/
    ├── fixtures.ts                ← 型別化的模擬資料（已有空殼）
    └── handlers/
        ├── {resource}.ts          ← 按資源分的 handlers
        └── index.ts               ← 補上 handler 彙總（已有空殼）
```

---

# 規則

- Fixtures 資料**必須來自** `${SPECS_ROOT_DIR}/features/` 的具體範例，不得憑空編造
- 每個 `api.yml` error response **必須**在 handler 中有對應分支
- Handler URL pattern 必須完全匹配 `api.yml` 的 path（含 path params）
- 不產生任何 UI 元件，此階段只處理資料層
- 不覆寫「已有基礎建設」中的檔案，僅在標記處補充

---

# 完成條件

- 所有 `api.yml` schemas 均有對應的 Zod schema 檔案
- 所有 `api.yml` endpoints 均有對應的 handler 和 client 函式
- 所有 error codes 均有 handler 分支
- `fixtures.ts` 的資料可追溯至 `features/` 的具體 Given 步驟
