---
name: aibdd.form.feature-spec
description: Feature 視圖的 Spec Skill。從 .feature 骨架（含便條紙）、ES spec 或 User idea 出發，澄清並產出完整的 Gherkin Feature File。可被 /discovery 調用，也可獨立使用。
user-invocable: true
args-config: arguments-template.yml
argument-hint: "[feature-skeleton-or-idea]"
input: .feature skeleton (from /aibdd.form.activity-spec, may contain sticky notes) | ${ES_SPEC_PATH} (Event Storming spec) | User idea (raw text)
output: ${FEATURE_SPECS_DIR}/*.feature
---

# 行為探索

---

## 執行流程

### 輸入來源判斷

**輸入來源一：`.feature` 骨架（含便條紙）**

來自 `/aibdd.form.activity-spec` 連動生成的骨架。骨架已有 `@ignore @command` / `@ignore @query` + Feature header + 部分 Rule 框架。

執行方式：
1. 讀取骨架中的所有便條紙（`<!-- ?N[類型] ... -->`），整理成待澄清清單
2. 能直接推斷的便條紙（如格式明顯的假設）靜默處理，直接填入 + 刪除便條紙
3. 無法推斷的便條紙進入澄清模式，逐一解決
4. 所有便條紙解決後，填入完整的 Rule + Example，移除所有 `(待澄清)` 佔位

**輸入來源二：`${ES_SPEC_PATH}`（Event Storming spec）**

直接採用 ES 中的 Commands 與 Read Models。每個 Command 對應一個 Feature file（Command 類型），每個 Read Model 對應一個 Feature file（Query 類型）。不重新識別，不合併，不拆分。ES 中的 Actor、Aggregate、Rules、Description 作為澄清起點，僅對 `(待澄清)` 欄位進行澄清。

**輸入來源三：User idea（raw text）**

解析 idea，識別 Command（改變系統狀態的操作）與 Query（讀取狀態的查詢操作）。

---

### 功能識別

1. 依輸入來源確認功能清單（.feature 骨架已有功能清單；ES / idea 則識別後列出）
2. 列表呈現給用戶確認：會開出幾個 Feature、哪些是 Command 類型、哪些是 Query 類型
3. 確認後進入草稿模式或澄清模式

### 草稿模式（ES 項目已 clear）

**當 ES 項目無 `(待澄清)` 標記時，AI 直接根據 ES 的 Rules、參數、Aggregate 生成完整的 Feature File 草稿，不逐條提問。**

- ES 中的每條原子規則 → 對應一條 Gherkin Rule + Example
- AI 自行推斷具體的 datatable 測試資料（如用戶名 `Alice`、商品 ID `1`、價格 `50`）
- 草稿產出後由協調器統一展示，用戶審閱確認或糾正

### 澄清模式（逐一功能處理）

針對含便條紙、含 `(待澄清)` 的功能，或 raw idea 輸入，按以下固定順序澄清其規則：

1. **前置（狀態）** — 操作前系統必須滿足什麼狀態？
2. **前置（參數）** — 輸入參數有什麼約束？
3. **後置（回應）** — 操作回傳什麼內容？（主要用於 Query）
4. **後置（狀態）** — 操作成功後系統狀態如何變化？（主要用於 Command）

每條規則依照協調器定義的澄清循環進行：提問 → 更新 → 確認 → 下一題。

一個功能的所有規則皆為 clear 後，進入下一個功能。

---

## 便條紙格式（強制規範）

**所有便條紙、佔位符、待澄清標記，無論出現在 `.feature` 或 `.activity` 任何位置，一律使用 HTML 註解格式：**

```
<!-- ?N[類型] 說明文字 -->
```

**嚴禁使用任何其他格式**，包含但不限於：
- `（待澄清）` ❌
- `(TODO)` ❌
- `# 待確認` ❌
- `[待補]` ❌

理由：系統使用正規表達式 `<!--[\s\S]*?-->` 統一掃描所有視圖的未解便條紙，非此格式的標記將被忽略、導致遺漏。

**格式說明：**

| 欄位 | 說明 |
|------|------|
| `?N` | 便條紙編號，在同一檔案內遞增 |
| `[類型]` | `[假設]` / `[缺漏]` / `[條件]` / `[矛盾]` 其中之一 |
| 說明文字 | 具體問題或假設內容 |

**範例：**
```gherkin
Rule: 前置（狀態）- 商品庫存必須大於 0 <!-- ?1[缺漏] 庫存為 0 時的錯誤訊息為何？ -->

  Example: <!-- ?2[假設] 假設單次最多購買 10 件 -->
```

### 便條紙品質標準（CiC 原則）

每張便條紙必須「自足」—— 讀者不需查閱其他檔案，就能理解為什麼不確定、有哪些選項、各自的影響。

**必須包含：背景 + 選項 + 影響**

❌ 低品質（只有疑問）：
```gherkin
Rule: 前置（狀態）- 商品庫存必須大於 0  <!-- ?1[缺漏] 庫存為 0 時的錯誤訊息為何？ -->
```

✅ 高品質（自足、含選項與影響）：
```gherkin
Rule: 前置（狀態）- 商品庫存必須大於 0  <!-- ?1[缺漏] 庫存為 0 時的錯誤訊息尚未定義。
選項 A: 「商品庫存不足」（通用訊息，簡單，但用戶無法判斷是哪個商品）
選項 B: 「商品 {productId} 目前庫存為 0，請稍後再試」（帶商品 ID，需 API Response 包含 productId 欄位）
選項 C: 「商品庫存不足，目前庫存：0」（顯示庫存數量，API Response 需額外的 stock 欄位）
影響：選項 B/C 的錯誤訊息需要後端在 error payload 中回傳額外欄位，影響 api.yml 的 error schema 設計。 -->
```

**標記原則：寧多勿少、不計 token。**

---

## Feature File 格式

**關鍵字一律使用英文：Feature / Rule / Example / Given / When / Then / And / But。檔案開頭不標註語言。**

**本關注點產出的所有 Feature File 必須在最上方標註 `@ignore @command`（Command 類型）或 `@ignore @query`（Query 類型）。**

```gherkin
@ignore @command
Feature: <功能名（Command 類型）>

  Background:
    Given <全部 Example 共用的前置條件，使用具體 datatable>

  Rule: 前置（狀態）- <主詞>必須<單一條件>

    Example: <情境條件>時<預期結果>
      Given <前置條件>
      When <操作>
      Then 操作失敗，錯誤為「<具體錯誤訊息>」

  Rule: 前置（參數）- <主詞>必須<單一條件>

    Example: <情境條件>時<預期結果>
      Given <前置條件>
      When <帶有不合法參數的操作>
      Then 操作失敗，錯誤為「<具體錯誤訊息>」

  Rule: 後置（回應）- <主詞>應<單一條件>（用於 Query）

    Example: <情境描述>
      Given <前置條件>
      When <操作>
      Then 操作成功
      And 查詢結果應包含：
        | 欄位1 | 欄位2 |
        | 值1   | 值2   |

  Rule: 後置（狀態）- <主詞>應<單一條件>（用於 Command）

    Example: <情境描述>
      Given <前置條件>
      When <操作>
      Then 操作成功
      And <狀態驗證>
```

### Example 步驟數量

每個 Example 建議 3-5 步（不含 Background）。超過 5 步時，檢查是否：
- 某些 Given 可以提取到 Background
- 多個 Then/And 斷言可以合併為一個 datatable 驗證
- 該 Example 其實在測試多個行為，應拆分為多個 Example

### Example 標題命名

Example 標題描述「什麼情境 → 什麼結果」，讓測試失敗時能立刻定位問題。

**句型：** `<情境條件>時<預期結果>`（失敗場景）或 `<操作描述>後<預期結果>`（成功場景）

**正確：**
```gherkin
Example: 已購買旅程的用戶再次購買時操作失敗
Example: 使用已過期折扣券時操作失敗
Example: 影片進度達到 100% 時課程自動完成
Example: 建立多個商品的訂單後總價正確計算
```

**錯誤（模糊）：**
```gherkin
Example: 使用折扣券
Example: 測試失敗場景
Example: 正常情況
```

### Background 節制原則

Background 僅包含「所有 Example 都需要」的共用前置條件。逐條檢查 Background 中的每個 Given 步驟及 datatable 中的每一行資料，確認它被多數 Example 引用。若某筆資料只被 1-2 個 Example 使用，應移入那些 Example 的 Given 中。

**原則：讀者在讀一個 Example 時，不應為了理解它而去 Background 中搜尋只跟這個 Example 有關的資料。**

---

## Rule 命名與原子化

### 命名句型

每條 Rule 的名稱必須遵循：

```
Rule: <類型前綴> - <主詞> 必須/應 <單一條件>
```

- **類型前綴**：`前置（狀態）`、`前置（參數）`、`後置（回應）`、`後置（狀態）`
- **主詞**：一個明確的實體屬性或系統狀態
- **動詞**：前置用「必須」（約束），後置用「應」（預期結果）
- **單一條件**：一個可驗證的具體斷言

**正確：**
```
Rule: 前置（狀態）- 商品庫存必須大於 0
Rule: 前置（參數）- 加入數量必須大於 0
Rule: 後置（狀態）- 購物車應新增一筆商品項目
```

**錯誤（違反原子化）：**
```
Rule: 前置（狀態）- 商品庫存必須大於 0 且商品狀態必須為上架中
```
→ 兩個主詞、兩個條件，應拆為兩條 Rule。

### 原子化判定

- 陳述句中出現「且」「和」「並且」→ 拆分為多條 Rule
- 陳述句中出現「或」→ 保留在同一條 Rule
- 混合前置與後置 → 拆分

### 必要參數規則（必備 + 允許合併）

每個 Feature **至少**必須有一條「前置（參數）」Rule 驗證必要參數。

所有「缺少必要參數」的檢查可合併為單一 Rule，使用 Scenario Outline：

```gherkin
Rule: 前置（參數）- 必要參數必須提供

  Scenario Outline: 缺少 <缺少參數> 時操作失敗
    Given 系統中有以下用戶：
      | userId | name  |
      | 1      | Alice |
    When 用戶 "Alice" 將商品 <商品ID> 加入購物車，數量 <數量>
    Then 操作失敗，錯誤為「必要參數未提供」

    Examples:
      | 缺少參數 | 商品ID | 數量 |
      | 商品 ID  |        | 1    |
      | 數量     | 1      |      |
```

其他具有**領域特定約束**的參數規則仍各自獨立為原子 Rule。

---

## 資料驅動原則

每個 Step 必須指定具體、可驗證的資料，禁止模糊描述。

**正確示範：**
```gherkin
Given 系統中有以下用戶：
  | name  | email          | level | exp |
  | Alice | alice@test.com | 1     | 0   |
When 用戶 "Alice" 更新課程 1 的影片進度為 80%
Then 操作成功
And 課程 1 的進度應為：
  | lessonId | progress | status |
  | 1        | 80       | 進行中 |
```

**規則：**
- Given：使用 datatable 提供所有相關屬性的具體值
- When：明確指定用戶名稱/ID、資源 ID、參數值
- Then：使用 datatable 驗證具體的預期值，禁止模糊描述如「已改變」
- Then：失敗場景必須指定具體錯誤訊息：`Then 操作失敗，錯誤為「<具體錯誤訊息>」`
- **錯誤訊息一致性**：同一類失敗跨 Feature 使用同一條錯誤訊息
- **禁止在 datatable 中使用 JSON 字串**，複雜資料應拆分為多個 Given/And 步驟

---

## Given 設定方式

### 選擇 A：直接設定 Aggregate State

```gherkin
Given 訂單 "ORDER-123" 的狀態為：
  | orderId   | status | totalAmount |
  | ORDER-123 | 已付款  | 1500        |
```

適用：Aggregate 的不變條件（Invariant）簡單。

### 選擇 B：透過 Commands 設定

```gherkin
Given 用戶 "Alice" 建立訂單 "ORDER-123"，購買課程 1
And 用戶 "Alice" 完成訂單 "ORDER-123" 的付款，金額 1500 元
```

適用：Aggregate 有複雜的 Invariant（如「總金額 = Σ品項金額 + 運費 - 折扣」）。

| 條件 | 建議 |
|------|------|
| Invariant 簡單（如 `0 ≤ progress ≤ 100`） | 選擇 A |
| Invariant 複雜（跨屬性計算、狀態流轉） | 選擇 B |
| 建立路徑需要 5 個以上 Commands | 選擇 A |

---

## Key 識別規則

### Key 選擇原則

- **Actor（用戶等）**：優先使用 name（如 `"Alice"`），而非 ID
- **其他 Aggregate**：視情境選最具辨識度的屬性（如訂單用 `"ORDER-123"`）

### 引號規則

- **字串值用雙引號**：`"Alice"`、`"ORDER-123"`
- **數字值不用引號**：`1`、`80`

### 複合 Key

| 連接詞 | 範例 | 複合 Key |
|--------|------|----------|
| 在 | `用戶 "Alice" 在課程 1 的進度為 70%` | userId + lessonId |
| 對 | `用戶 "Alice" 對訂單 "ORDER-123" 的評價為 5 星` | userId + orderId |

**所有 Feature File 之間的句型應保持一致。**

---

## When 步驟格式

| 類型 | 格式 | 範例 |
|------|------|------|
| Command | `用戶 "<key>" <動詞＋參數>` | `When 用戶 "Alice" 更新課程 1 的影片進度為 80%` |
| Query | `用戶 "<key>" 查詢 <目標＋參數>` | `When 用戶 "Alice" 查詢課程 1 的進度` |

---

## 核心約束

**嚴格遵守用戶提供的資訊，不添加、不假設、不推測任何未明確說明的內容。**

**Feature file 的讀者是不了解功能細節的人。** 每一步都應該用業務語言描述，不依賴讀者對系統內部模型的理解。

**每個 Example 獨立執行。** Example 之間不共享可變狀態、不依賴執行順序。

---

## 完成條件

所有功能的所有規則皆為 clear、所有便條紙已解決、無 `(待澄清)` 佔位時，輸出完整的 Feature File，告知用戶行為探索已完成。

---

## 面向覆蓋率清單（被 /discovery 調用時，便條紙全部解決後執行）

便條紙只能捕捉「AI 寫的當下意識到的問題」，但可能漏掉某些從未想到的面向。便條紙清零後，逐一過以下 6 個面向，標記 `Clear` / `Partial` / `Missing`：

| # | 面向 | 檢查內容 | 常見盲區 |
|---|------|---------|---------|
| F1 | **規則完整性** | 前置（狀態）/ 前置（參數）/ 後置 四類 Rule 是否都存在？ | 只有後置（狀態），忘記前置（參數）|
| F2 | **邊界條件** | 空值、極端值（最大/最小）、重複操作是否有對應 Rule？ | 空購物車、0 數量、重複建立同一資源 |
| F3 | **錯誤訊息明確性** | 每個失敗 Example 的錯誤訊息是否具體、可驗證？ | `Then 操作失敗`（沒有具體錯誤訊息字串）|
| F4 | **資料可驗證性** | 每個 Then 是否使用具體 datatable 驗證，而非模糊的「狀態已改變」？ | `Then 訂單狀態已更新`（沒有具體值）|
| F5 | **跨功能一致性** | 同一術語/錯誤訊息/Key 識別方式在不同 .feature 之間是否一致？ | userId 在 A 用 name，在 B 用 ID |
| F6 | **必要參數 Rule** | 是否有「前置（參數）- 必要參數必須提供」的 Rule？ | 缺少任何必填參數的驗證 Rule |

對 `Missing` / `Partial` 面向，補寫便條紙（CiC 品質標準），重新進入澄清循環。所有面向 `Clear` 後通知 `/discovery` 放行。
