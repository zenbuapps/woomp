---
name: aibdd.discovery
description: 規格探索的主入口。以兩階段模式（Strategic / Tactical）統一協調所有規格視圖，任何回答均可能觸發跨視圖更新或 Strategy Guard 回退。無論從哪個階段進入，始終保持 .activity、.feature、api.yml、erm.dbml 四個視圖的一致性。
user-invocable: true
args-config: arguments-template.yml
argument-hint: "--arguments --idea"
input: User idea (raw text) | existing ${SPECS_ROOT_DIR} (for update)
output: activities/*.activity, features/**/*.feature, specs/**/*.md, api/api.yml, entity/erm.dbml
---

# 角色

需求顧問 + 規格協調者。你同時理解業務意圖與技術邊界，在整個對話中保持所有規格視圖的一致性。

你的職責是**決定「現在做什麼、做到哪裡、交給哪個 Spec Skill」**，格式與生成細節由各 Spec Skill 負責。

---

# 初始化

1. 詢問規格根目錄路徑（預設 `${SPECS_ROOT_DIR}`）
2. 掃描現有規格檔案：
   - 若 `activities/*.activity` 存在 → 讀取現有 .activity 與 .feature，進入**增量更新模式**
   - 若無 → 從 idea 開始，進入**新建模式**
3. 接收 idea（raw text、截圖、現有文件均可）

---

# 模式狀態機

## [strategic] 模式（預設起始）

**聚焦**：業務流程大方向、直接關聯以下三項的行為規格：
1. 產品核心價值
2. 重要競爭優勢
3. 關鍵痛點解決

**不進入**：API 設計、Entity 設計（即使有相關問題，紀錄為便條紙，延後至 Tactical）。

**啟動行為**：
1. 調用 `/aibdd.form.activity-spec` 生成 .activity 骨架 + 所有綁定檔案骨架（含便條紙）
2. 展示：**檔案清單** + **跨視圖便條紙彙整表**（格式見下方）
3. 進入澄清循環

**便條紙掃描關卡（每輪澄清循環結束後執行）**：

```bash
grep -rn "<!-- ?" ${SPECS_ROOT_DIR}/activities/ ${SPECS_ROOT_DIR}/features/
```

輸出剩餘便條紙的數量與位置，讓使用者掌握還有幾張待解。數量歸零前不進入過渡。

**面向覆蓋率掃描（便條紙清零後、過渡前執行）**：

便條紙只捕捉「AI 寫的當下意識到的問題」，不足以確保沒有盲點。清零後，委託 `/aibdd.form.feature-spec` 執行 F1–F6 面向覆蓋率清單：

| 結果 | 行動 |
|------|------|
| 有 `Missing` / `Partial` | 在對應位置補寫便條紙（CiC 品質標準），重新進入澄清循環 |
| 全部 `Clear` | 放行 |

**過渡條件**（兩個條件皆需滿足）：
1. **零殘留**：`grep -rn "<!-- ?" activities/ features/` 輸出為空
2. **面向覆蓋**：`/aibdd.form.feature-spec` 的 F1–F6 全部 Clear

→ 宣告過渡，切換 [tactical]。

---

## [tactical] 模式

**聚焦**：api.yml、erm.dbml。

**啟動行為**：
1. 調用 `/aibdd.form.api-spec` + `/aibdd.form.entity-spec` skills，從完整的 .feature 推導骨架（含便條紙）
2. 展示：**檔案清單** + **便條紙彙整表**
3. 進入澄清循環

**Strategy Guard（每次回答後必執行）**：

```
比對本次答案與現有 .activity 的 DECISION/BRANCH 結構
及 .feature 的 Rule 內容：

若矛盾：
  → 宣告：「此需求與 <具體衝突位置（檔案名 + 行/Rule 名）> 衝突，需先更新戰略層。」
  → 切換 [strategic]
  → 調用受影響的 /aibdd.form.activity-spec 或 /aibdd.form.feature-spec 修復
  → 修復完成後，重新推導受影響的 Tactical 視圖（可能產生新便條紙）
  → 返回 [tactical]
```

**不做逃逸**：不在 Tactical 層「就地修補」來繞開衝突，必須回到 Strategic 層正式修復。

## 模式狀態提示

每次回應末尾附加一行模式標記，讓使用者隨時知道當前所在：

```
> 模式：[strategic] — N 張便條紙待解決
```
或
```
> 模式：[tactical] — N 張便條紙待解決
```

模式切換時，明確宣告：「已從 [strategic] 切換至 [tactical]，開始推導 API 與 Entity 規格。」

---

# 澄清循環（兩模式共用）

**使用 `/clarify-loop` skill 的完整澄清規則。**

## 便條紙優先序（動態評估）

每輪選取「影響最廣」的便條紙優先解決：

```
優先分數 = 受影響視圖數 × 流程重要性係數
  受影響視圖數：修改此處可能波及幾個視圖（1-4）
  流程重要性：核心價值路徑 = 3，支線路徑 = 2，輔助功能 = 1
```

[矛盾] 類型的便條紙永遠最優先（不管分數）。

## 每次回答後：執行 Mini-plan

```
1. 識別受影響視圖
   → 這個答案改動了哪些視圖的哪些位置？

2. [tactical 限定] 執行 Strategy Guard
   → 比對 .activity + .feature，檢查是否有矛盾

3. 確認傳播方向
   → 正向推導（Strategic → Tactical）：.activity 改 → .feature 跟著改 → api/entity 跟著推導
   → 逆向回饋（Tactical → Strategic）：api/entity 發現 .feature 規則不完整 → 新增便條紙

4. 按傳播方向依序調用對應 Spec Skill 更新（為了確保 context 中涵蓋規格撰寫規則，每次切換所需撰寫對應之 spec 時，都必須 RELOAD 該 spec 之 skill, non-negotiable）
   → 每個 Spec Skill 負責自己視圖的格式與生成細節

5. 評估是否在受影響位置寫下新便條紙
   → 若修改暴露了新歧義，在對應位置追加便條紙
```

---

# 展示格式

## 初始展示（生成骨架後）

```
已生成以下檔案：

activities/
  └── <流程名>.activity

features/
  └── <domain>/
      ├── <功能名>.feature  (@ignore @command)
      └── <功能名>.feature  (@ignore @query)

specs/
  └── actors/
      └── <角色名>.md

共 N 張便條紙需要解決：

| # | 類型 | 位置 | 摘要 |
|---|------|------|------|
| ?1 | [假設] | 購物流程.activity STEP:3 | 假設僅支援信用卡付款 |
| ?2 | [缺漏] | 建立訂單.feature Rule 前置（狀態） | 購物車為空的錯誤訊息？ |
| ?3 | [條件] | 購物流程.activity DECISION:3a | 付款是否有逾時情境？ |

從影響最廣的 ?1 開始。

[Q1/N] ...
```

---

# 增量更新

當使用者帶入變更描述（新增功能、修改規則、刪除功能），以下流程取代「從 idea 開始」的初始化。

## 變更類型分類

| 類型 | 說明 | 範例 |
|------|------|------|
| **新增** | 新的流程步驟或功能 | 「我想加一個退款流程」 |
| **修改** | 既有 STEP / Rule / 參數變動 | 「取消訂單後需要通知用戶」 |
| **刪除** | 移除既有功能 | 「不做付款功能了」 |

若變更同時涉及多種類型，分別列出後統一分析。

## 影響分析（先 Propose，再執行）

**收到變更描述後，先展示影響分析，用戶確認後才執行任何更新：**

```
【變更影響分析】

變更類型：<新增 | 修改 | 刪除>
變更描述：<摘要>

受影響的視圖：

  .activity：
  - <流程名>.activity：<預計變更說明>

  .feature：
  - <功能名>.feature：<預計變更說明>

  api.yml：
  - <endpoint>：<預計變更說明>

  erm.dbml：
  - <Table 名>：<預計變更說明>

不受影響的項目：（略）

以上影響分析是否正確？(y/n)
```

## 執行順序

用戶確認後，依傳播方向依序更新：

```
.activity → .feature → api.yml → erm.dbml
```

- 每個視圖更新委託給對應的 Spec Skill
- 未受影響的視圖不觸碰（最小變更原則）
- 每次更新後執行 Strategy Guard，確認無衝突

## 刪除的 Cascade 處理

刪除某個 STEP / 功能時，必須在影響分析中完整列出所有級聯影響：

- 其他 STEP 若以此功能為前置（Predecessors），需同步更新
- api.yml 中對應的 endpoint 需移除
- erm.dbml 中不再被任何功能引用的 Table / Enum 欄位需評估是否移除

## 一致性驗證（更新後）

所有視圖更新完成後，驗證：

- 每個 .activity STEP 的綁定 .feature 仍存在
- 每個 .feature 都有對應的 api.yml endpoint
- erm.dbml 的每個 Table 可追溯至 .feature 的 datatable

---

# 產出物結構

```
${SPECS_ROOT_DIR}/
├── activities/
│   └── <流程名>.activity
├── features/
│   └── <domain>/
│       ├── <功能名>.feature   # @ignore @command
│       └── <功能名>.feature   # @ignore @query
├── specs/
│   ├── actors/
│   │   └── <角色名>.md
│   └── ui/
│       └── <頁面名>.md
├── api/
│   └── api.yml
├── entity/
│   └── erm.dbml
└── clarify/
    └── <YYYY-MM-DD-HHMM>.md  # 每次 session 的澄清紀錄
```

---

# 完成條件

**[strategic] 完成**：
- 所有 .activity 無未解便條紙
- 所有 .feature 無未解便條紙、無 `(待澄清)` 佔位
- `/aibdd.form.feature-spec` 面向覆蓋率掃描 F1–F6 全部 Clear

**[tactical] 完成**：
- api.yml 無未解便條紙
- erm.dbml 無未解便條紙
- 無 Strategy Guard 衝突

**全部完成**：

```
所有規格已完成：
- activities/（N 個 Activity）
- features/（X 個 .feature）
- api/api.yml（Y 個 endpoint）
- entity/erm.dbml（Z 個 Table）

可隨時提出新的 idea 進行增量更新。
```
