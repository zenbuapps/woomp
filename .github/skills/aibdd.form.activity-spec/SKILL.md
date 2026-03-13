---
name: aibdd.form.activity-spec
description: Activity 視圖的 Spec Skill。從 idea 生成 .activity 骨架並連動生成所有綁定檔案（.feature/.md）骨架，不確定處標記便條紙。可被 /discovery 調用，也可獨立使用。
user-invocable: true
args-config: arguments-template.yml
argument-hint: "[idea]"
input: User idea (raw text) | existing .activity files (for update)
output: activities/*.activity, features/**/*.feature (skeleton), specs/**/*.md (skeleton)
---

# 角色

管理 Activity 視圖。將業務流程以扁平事件流格式記錄，並連動生成所有綁定檔案的骨架。

---

# Entry 條件

**獨立調用時**，先詢問：
- 規格根目錄路徑（預設 `${SPECS_ROOT_DIR}`）
- 任務類型：**新建**（從 idea 生成）或 **更新**（修改現有 .activity）

**被 `/discovery` 調用時**，由協調器提供以上資訊，不再詢問。

---

# .activity 語法速查

完整語法見 `syntax.bnf`（版本 6）。

**Id 規則（一條規則消除所有衝突）**：
- 純數字（`1`、`2`、`3`）→ 永遠是 `STEP`，全局唯一
- 數字 + 後綴（`2a`、`2a1`、`3a`）→ 永遠是 `DECISION` / `FORK`，強制帶後綴
- 後綴命名：字母與數字交替，編碼嵌套路徑（`2a` → `2a1` → `2a1a`）

**Guard 規則**：所有分支條件必須明確寫出字串，禁止 `_`（else / 預設路徑不合法）。

**Loop-back**：
- `-> N`（純數字）= 跳回 `STEP:N`，重新執行整個步驟
- `-> Nx`（帶後綴）= 跳回 `DECISION:Nx`，直接重新評估條件（for-each / while）

**便條紙格式**：`<!-- ?N[類型] 內容 -->`，N 為檔案內流水號，每個 .activity 從 1 重新計數。

---

# 從 Idea 生成 .activity 骨架

## 1. 識別 Actor

從 idea 找出所有參與者（人、系統、外部服務）。每個 Actor 對應一個 `[ACTOR]` 行，綁定 `specs/actors/<Actor名>.md`。

## 2. 推斷 STEP 序列

從 idea 的業務動詞依序抽出主線步驟：
- 步驟數以「完成業務目標的端到端完整性」為準，不人為壓縮
- 主線 STEP 純數字遞增（1、2、3 …）
- 每個 STEP 必須有 `@Actor` 標記執行者，並綁定一個檔案

## 3. 識別 DECISION / FORK

**DECISION**（條件分支）：步驟結果有多種走向時加入。
- id = 上一個 STEP 數字 + 字母後綴（如 STEP:3 後的第一個分支為 `DECISION:3a`）
- Guard 必須窮舉所有條件，不可使用 `_`

**FORK**（並行）：多個動作可同時進行時加入。
- id = 同規則（如 STEP:4 後的第一個並行為 `FORK:4a`）

## 4. 綁定規則

每個 STEP 綁定一個檔案：

| STEP 性質 | 綁定格式 | Feature Tag |
|-----------|---------|-------------|
| Actor 執行改變狀態的操作 | `features/<domain>/<功能名>.feature` | `@ignore @command` |
| Actor 讀取 / 查詢資料 | `features/<domain>/<功能名>.feature` | `@ignore @query` |
| 純 UI 展示 / 頁面呈現 | `specs/ui/<頁面名>.md` | — |

`[ACTOR]` 行統一綁定 `specs/actors/<Actor名>.md`。
同一個 `.feature` 可被多個 STEP 共用（路徑相同即視為同一功能）。

---

# 便條紙生成原則

在對應行末加上便條紙：

| 類型 | 何時標記 |
|------|---------|
| `[條件]` | DECISION 的分支條件可能不完整（漏了某些情境）時 |
| `[替代]` | 存在其他可行路徑但暫時未建模時 |
| `[矛盾]` | 同一流程中前後文衝突時 |
| `[缺漏]` | idea 資訊不足，無法確定該步驟的詳細行為時 |
| `[假設]` | AI 做了假設需使用者確認時 |

**原則**：寧可多標記、少遺漏。不確定就標記，確定了就刪除。

### 便條紙品質標準（CiC 原則）

每張便條紙必須「自足」—— 讀者不需查閱其他檔案，就能理解為什麼不確定、有哪些選項、各自的影響。

**必須包含：**
1. **背景**：為什麼這裡有疑問（上下文）
2. **選項**：至少 2 個可能解法
3. **影響**：每個選項對流程結構（STEP 數、DECISION 分支數、綁定檔案數）的影響

**範例對比：**

❌ 低品質（只有結論）：
```
<!-- ?1[假設] 假設僅支援信用卡付款 -->
```

✅ 高品質（自足、含選項與影響）：
```
<!-- ?1[假設] 付款方式假設為信用卡，但需確認支援範圍。
選項 A: 僅信用卡 → DECISION:3a 只有「付款成功/失敗」2 條分支，綁定 1 個 .feature
選項 B: 信用卡 + LINE Pay → DECISION:3a 需增加「選擇付款方式」分支，多 1 個 .feature
選項 C: 含貨到付款 → 還需額外 STEP 處理物流通知事件，影響後續整個流程結構
影響：此決策決定 DECISION:3a 的分支數量與對應 .feature 的數量。 -->
```

**標記原則：寧多勿少、不計 token。每一個不確定就標一張。**

---

# 輸出格式

## .activity 檔案結構

```
[ACTIVITY] <流程名稱>
[ACTOR] <Actor1> -> {specs/actors/<Actor1>.md}
[ACTOR] <Actor2> -> {specs/actors/<Actor2>.md}

[STEP:1] @<Actor> {features/<domain>/<功能名>.feature}
[STEP:2] @<Actor> {features/<domain>/<功能名>.feature}  <!-- ?1[假設] ... -->
[DECISION:2a] <條件描述>                                 <!-- ?2[條件] ... -->
  [BRANCH:2a:<guard1>] @<Actor> {features/<domain>/<功能名>.feature}
  [BRANCH:2a:<guard2>] {specs/ui/<頁面名>.md}
[MERGE:2a]

[STEP:3] @<Actor> {features/<domain>/<功能名>.feature}
[FORK:3a]                                                <!-- ?3[缺漏] ... -->
  [PARALLEL:3a] @<Actor> {features/<domain>/<功能名>.feature}
  [PARALLEL:3a] @<Actor> {features/<domain>/<功能名>.feature}
[JOIN:3a]
```

完整範例見 `範例-複雜情境.activity`。

## .feature 骨架格式

推理深度依 idea 細節多寡決定，能推斷的直接填入，無法推斷的用便條紙標記：

```gherkin
@ignore @command
Feature: <功能名>

  Background:
    Given 系統中有以下<主要 Aggregate>：
      | <key欄位> | <欄位1> | <欄位2> |
      | (待澄清)  | (待澄清) | (待澄清) |

  Rule: 前置（狀態）- <推斷的約束>  <!-- ?1[假設] 錯誤訊息為「...」？ -->

    Example: <推斷的失敗情境>
      Given ...
      When ...
      Then 操作失敗，錯誤為「(待澄清)」

  Rule: 後置（狀態）- <推斷的後置效果>  <!-- ?2[缺漏] 後置狀態為何？ -->

    Example: <推斷的成功情境>
      Given ...
      When ...
      Then 操作成功
      And (待澄清)
```

## .md 骨架格式（Actor / UI）

```markdown
# <Actor名 / 頁面名>

<!-- ?1[缺漏] <不確定的核心特徵> -->

## 描述
(待澄清)

## 關鍵屬性
- (待澄清)
```

---

# 更新規則

## 收到澄清後

1. **解決便條紙**：定位對應便條紙，刪除 `<!-- ?N[類型] ... -->` 標記
2. **修改流程**：若澄清導致 STEP / DECISION / FORK 結構改變，更新對應行
3. **新增便條紙**：若修改後暴露出新歧義，在受影響位置追加新便條紙（流水號續接）
4. **同步綁定檔案**：若 STEP 的綁定路徑改變，同步更新或新建對應的 .feature / .md 骨架
5. **記錄澄清**：將問答內容寫入 `clarify/<YYYY-MM-DD-HHMM>.md`

## 便條紙刪除規則

每次只刪除「已被澄清的那一張」，其他便條紙不動。

---

# 完成條件

所有 .activity 無未解便條紙，且所有綁定的 .feature / .md 骨架已建立。
