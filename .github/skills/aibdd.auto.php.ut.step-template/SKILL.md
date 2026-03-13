---
name: aibdd.auto.php.ut.step-template
description: PHP UT Stage 1：從 Gherkin Feature 生成 Unit Test Step Definition 樣板。使用 FeatureContext 共用狀態。可被 /php-ut 調用，也可獨立使用。
user-invocable: true
argument-hint: "[feature-file]"
input: tests/features/**/*.feature, tests/contexts/**/*.php
output: tests/contexts/{Subdomain}/{Category}Context.php（樣板）
---

# 角色

BDD Step Definition 樣板生成器。從 Gherkin Feature File 生成可執行的 Step Definition 骨架（Behat Context class + DocBlock 註解 + `throw new PendingException()`）。

**重要**：此 Skill 的產出僅為「樣板」，不包含實作邏輯。

---

# 入口條件（雙模式）

## 模式 A：獨立使用
1. 詢問目標 Feature File
2. 執行樣板生成流程

## 模式 B：被 /php-ut 調用
接收參數：Feature File 路徑。直接執行。

---

# 執行前檢查

**永遠不要覆蓋已存在的 Step Definition。**

1. 掃描 `tests/contexts/` 下所有 `.php` 檔案中的 `@Given`, `@When`, `@Then` DocBlock
2. 建立「已存在步驟清單」
3. 對比找出「缺少的步驟」
4. **只針對缺少的步驟生成樣板**

---

# 樣板格式

```php
<?php
// tests/contexts/Lesson/AggregateGivenContext.php

use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Exception\PendingException;

class AggregateGivenContext implements Context
{
    private FeatureContext $feature;

    public function __construct(FeatureContext $feature)
    {
        $this->feature = $feature;
    }

    /**
     * TODO: [事件風暴部位: Aggregate - LessonProgress]
     * TODO: 參考 /aibdd.auto.php.ut.handlers.aggregate-given 實作
     *
     * @Given 用戶 :userName 在課程 :lessonId 的進度為 :progress%，狀態為 :status
     */
    public function userHasProgress(string $userName, int $lessonId, int $progress, string $status): void
    {
        throw new PendingException();
    }
}
```

## 樣板規範

1. **檔案與目錄**：按 Subdomain 分（`Lesson/`, `Order/`），再按分類。`CommonThenContext` 不分 Subdomain
2. **一個 step 一個方法**（同類可放同一個 Context class）
3. **建構子注入 FeatureContext**
4. **TODO DocBlock**：標註事件風暴部位與 Handler Skill
5. **空方法體**：`throw new PendingException()`

---

# Behat 語法重點

## 參數語法

```php
/**
 * @Given 用戶 :userName 在課程 :lessonId 的進度為 :progress%
 */
public function userHasProgress(string $userName, int $lessonId, int $progress): void
{
    // :param 自動解析為函數參數
}
```

## FeatureContext 共用狀態

```php
$this->feature->repos->lessonProgress  // FakeRepository
$this->feature->services->lesson       // Service
$this->feature->ids[$userName]         // ID 映射
$this->feature->lastError             // 最後錯誤
$this->feature->queryResult           // 查詢結果
```

## DataTable

```php
/**
 * @Given 系統中有以下課程：
 */
public function systemHasLessons(TableNode $table): void
{
    foreach ($table->getHash() as $row) {
        $lessonId = (int) $row['lessonId'];
        // ...
    }
}
```

---

# Decision Tree

```
Given:
  建立初始資料狀態 → /aibdd.auto.php.ut.handlers.aggregate-given
  已完成的寫入操作 → /aibdd.auto.php.ut.handlers.command

When:
  讀取操作 → /aibdd.auto.php.ut.handlers.query
  寫入操作 → /aibdd.auto.php.ut.handlers.command

Then:
  只關注成功/失敗 → /aibdd.auto.php.ut.handlers.success-failure
  驗證 Aggregate 狀態 → /aibdd.auto.php.ut.handlers.aggregate-then
  驗證 Query 回傳值 → /aibdd.auto.php.ut.handlers.readmodel-then

And:
  繼承前一個判斷規則
```

---

# Handler Prompt 映射表

| 事件風暴部位 | 位置 | Handler Skill |
|------------|------|--------------|
| Aggregate | Given | /aibdd.auto.php.ut.handlers.aggregate-given |
| Command | Given/When | /aibdd.auto.php.ut.handlers.command |
| Query | When | /aibdd.auto.php.ut.handlers.query |
| 操作成功/失敗 | Then | /aibdd.auto.php.ut.handlers.success-failure |
| Aggregate | Then | /aibdd.auto.php.ut.handlers.aggregate-then |
| Read Model | Then | /aibdd.auto.php.ut.handlers.readmodel-then |

---

# Critical Rules

### R1: 永遠不覆蓋已存在的 Step Definition
### R2: 使用 Behat DocBlock 語法（@Given/@When/@Then）
### R3: 建構子注入 FeatureContext
### R4: 只輸出樣板（PendingException）
### R5: 保留完整 Gherkin 語句
### R6: 標註事件風暴部位
### R7: 指引正確的 Handler Skill

---

# 完成條件

- 所有缺少的 Step 都有對應的 Context class 和方法
- 樣板方法體為 `throw new PendingException()`
- 已存在的 Step Definition 未被覆蓋
