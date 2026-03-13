---
name: aibdd.form.api-spec
description: API 視圖的 Spec Skill。從完整的 .feature 文件推導 OpenAPI 格式的 api.yml。每個 Feature 對應一個 endpoint。可被 /discovery 調用，也可獨立使用。
user-invocable: true
args-config: arguments-template.yml
argument-hint: "[FEATURE_SPECS_DIR]"
input: ${FEATURE_SPECS_DIR}/**/*.feature (completed, no sticky notes)
output: ${API_SPECS_DIR}/api.yml
---

# 角色

管理 API 視圖。從完整的 Feature Files 機械性推導 OpenAPI 規格，每個 Feature 對應一個 endpoint，每條 Rule 對應一個 response 情境。

---

# Entry 條件

**獨立調用時**，先詢問：
- Feature Files 路徑（預設 `${FEATURE_SPECS_DIR}`）
- API spec 輸出路徑（預設 `${API_SPECS_DIR}/api.yml`）

**被 `/discovery` 調用時**，由協調器提供以上資訊，不再詢問。

**前提**：所有輸入的 .feature 必須已完成（無便條紙、無 `(待澄清)` 佔位）。若發現未完成的 .feature，暫停並回報給協調器。

---

# api.yml 格式

使用 OpenAPI 3.0.0 格式：

```yaml
openapi: 3.0.0
info:
  title: <系統名稱>
  version: 1.0.0

paths:
  /<resource>:
    post:
      operationId: <camelCase 功能名>
      summary: <功能名（中文）>
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/<RequestBody 名>'
      responses:
        '200':
          description: 操作成功
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/<Response 名>'
        '400':
          description: 驗證失敗
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
        '404':
          description: 資源不存在
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'

components:
  schemas:
    Error:
      type: object
      required: [message]
      properties:
        message:
          type: string
          example: <錯誤訊息範例>
```

---

# 從 .feature 推導 endpoint

## Command Feature（`@ignore @command`）→ 寫入操作

**HTTP Method 選擇**：

| When 動詞 | Method | 說明 |
|-----------|--------|------|
| 建立、新增、送出、提交 | `POST` | 新增資源 |
| 更新、修改、設定 | `PUT` / `PATCH` | 修改既有資源（影響全部欄位用 PUT，部分欄位用 PATCH） |
| 刪除、取消、移除 | `DELETE` | 刪除資源 |

**Request Body Schema**：從 When 子句的參數提取：

```gherkin
When 用戶 "Alice" 將商品 1 加入購物車，數量 2
```
→
```yaml
requestBody:
  schema:
    type: object
    required: [productId, quantity]
    properties:
      productId: {type: integer, example: 1}
      quantity:  {type: integer, example: 2}
```

注意：`用戶 "Alice"` 為呼叫者身份（從 auth token 取得），**不放進** request body。

**Path Parameter**：若 When 子句包含資源 ID（如修改、刪除操作），提取為 path parameter：

```gherkin
When 用戶 "Alice" 取消訂單 "ORDER-123"
```
→ `DELETE /orders/{orderId}`，path parameter: `orderId`

## Query Feature（`@ignore @query`）→ 讀取操作（GET）

**Query Parameters**：從 When 子句的過濾條件提取：

```gherkin
When 用戶 "Alice" 查詢課程 1 的進度
```
→ `GET /courses/{courseId}/progress`，path parameter: `courseId`

**Response Schema**：從 Then datatable 的欄位提取：

```gherkin
Then 查詢結果應包含：
  | lessonId | progress | status |
  | 1        | 80       | 進行中  |
```
→
```yaml
response:
  schema:
    type: array
    items:
      type: object
      properties:
        lessonId:  {type: integer}
        progress:  {type: integer}
        status:    {type: string}
```

---

# Endpoint Path 命名規則

- 資源名稱：kebab-case，複數形式（`/orders`、`/video-progresses`）
- 中文功能名 → 英文資源名推斷規則（優先使用 idea 中已有的英文名；無則音譯或語意翻譯）
- 巢狀資源（如課程下的進度）：`/courses/{courseId}/progress`
- 避免動詞出現在 path（用 HTTP Method 表達動詞）

---

# Response Status Code 對應

從 .feature 的 Rule 對應到 HTTP status code：

| .feature Rule 類型 | 失敗情境 | Status Code |
|-------------------|---------|-------------|
| 前置（狀態）失敗 | 資源不存在（如「訂單不存在」） | `404` |
| 前置（狀態）失敗 | 業務規則不滿足（如「購物車為空」、「已付款不可取消」） | `422` |
| 前置（參數）失敗 | 必要參數缺少或格式錯誤 | `400` |
| 權限不足 | 角色無權限 | `403` |

---

# 型別推斷規則

從 .feature datatable 的範例值推斷型別：

| 範例值 | 推斷型別 |
|--------|---------|
| `1`、`42`、`100` | `integer` |
| `45000.5`、`1.5` | `number` |
| `"Alice"`、`"ORDER-123"` | `string` |
| `true`、`false` | `boolean` |
| 日期格式（`2026-01-01`） | `string` (format: date) |
| 時間格式（`2026-01-01T00:00:00Z`） | `string` (format: date-time) |
| 有限值域（`待付款`、`已付款`、`已取消`） | `string`（搭配 `enum`） |

---

# 便條紙格式（強制規範）

**所有便條紙一律使用 HTML 註解格式，包在 YAML 原生行尾 comment 內：**

```
# <!-- ?N[類型] 說明文字 -->
```

**嚴禁使用任何其他格式**，包含但不限於：
- `# ?N[類型] 說明` ❌（缺少 `<!-- -->`）
- `# (待澄清)` ❌
- `# TODO` ❌

理由：系統使用正規表達式 `<!--[\s\S]*?-->` 統一掃描所有視圖（`.activity`、`.feature`、`erm.dbml`、`api.yml`）的未解便條紙，非此格式的標記將被漏掉。

**格式說明：**

| 欄位 | 說明 |
|------|------|
| `?N` | 便條紙編號，在同一檔案內遞增 |
| `[類型]` | `[假設]` / `[缺漏]` / `[條件]` / `[矛盾]` 其中之一 |
| 說明文字 | 具體問題或假設內容 |

**範例：**
```yaml
paths:
  /orders:
    post: # <!-- ?1[條件] 不確定是 POST 還是 PUT，取決於後端實作 -->
```

---

# 便條紙規則

在以下情況於 api.yml 對應位置加上便條紙（YAML 行尾 `# <!-- ?N[類型] 內容 -->`）：

| 類型 | 何時標記 |
|------|---------|
| `[缺漏]` | 無法從 .feature 推斷 HTTP Method 或 Path 時 |
| `[假設]` | 推斷了 enum 值域但不確定是否完整時 |
| `[條件]` | 同一操作可能有多種 HTTP Method（如 PUT vs PATCH）時 |

---

# 完成條件

所有 .feature 對應的 endpoint 均已生成，無便條紙、無模糊型別，api.yml 可被 OpenAPI validator 解析。
