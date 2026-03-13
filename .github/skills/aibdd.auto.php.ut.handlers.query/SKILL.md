---
name: aibdd.auto.php.ut.handlers.query
description: 當在 Gherkin 中撰寫 Query 操作步驟時，務必參考此規範。
user-invocable: false
---

# Query-Handler (Behat BDD Version)

## Role

負責實作 `When` 步驟中的 Query（查詢）操作。

**核心任務**：呼叫 Service 方法執行讀取操作，將結果儲存到 `$this->feature->queryResult`。

---

## ⚠️ 與 Command 的差異

| 項目 | Command | Query |
|------|---------|-------|
| 目的 | 修改系統狀態 | 讀取資料 |
| 回傳值 | 通常無回傳 | **有回傳值** |
| 結果處理 | 捕獲錯誤 | **儲存查詢結果** |

---

## 實作範例

```php
<?php
use Behat\Behat\Context\Context;

class QueryContext implements Context
{
    private FeatureContext $feature;
    public function __construct(FeatureContext $feature) { $this->feature = $feature; }

    /**
     * @When 用戶 :userName 查詢課程 :lessonId 的進度
     */
    public function userQueriesProgress(string $userName, int $lessonId): void
    {
        if (!isset($this->feature->ids[$userName])) {
            throw new \RuntimeException("找不到 '{$userName}' 對應的 ID");
        }
        $userId = $this->feature->ids[$userName];

        try {
            $result = $this->feature->services->lesson->getLessonProgress($userId, $lessonId);
            $this->feature->queryResult = $result;
            $this->feature->lastError = null;
        } catch (\Throwable $e) {
            $this->feature->queryResult = null;
            $this->feature->lastError = $e;
        }
    }
}
```

---

## 查詢列表

```php
/**
 * @When 用戶 :userName 查詢所有課程進度
 */
public function userQueriesAllProgress(string $userName): void
{
    $userId = $this->feature->ids[$userName];
    try {
        $this->feature->queryResult = $this->feature->services->lesson->getAllProgress($userId);
        $this->feature->lastError = null;
    } catch (\Throwable $e) {
        $this->feature->queryResult = null;
        $this->feature->lastError = $e;
    }
}
```

---

## Critical Rules

### R1: Query 必須儲存結果到 `$this->feature->queryResult`
### R2: Query 也需要捕獲錯誤
### R3: 從 FeatureContext 取得 Service
### R4: userName → userId 轉換
### R5: queryResult 是 FeatureContext 的公開屬性

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
