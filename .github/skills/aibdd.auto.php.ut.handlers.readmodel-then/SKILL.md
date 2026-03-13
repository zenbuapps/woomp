---
name: aibdd.auto.php.ut.handlers.readmodel-then
description: 當在 Gherkin 測試中驗證「Query 回傳結果」時，「只能」使用此指令。
user-invocable: false
---

# ReadModel-Then-Handler (Behat BDD Version)

## Role

負責實作 `Then` 步驟中驗證 Query 查詢結果的邏輯。

**核心任務**：從 `$this->feature->queryResult` 取得查詢結果並驗證內容。

---

## ⚠️ 與 Aggregate-Then 的差異

| 項目 | Aggregate Then | ReadModel Then |
|------|---------------|---------------|
| 資料來源 | Repository 查詢 | **$this->feature->queryResult** |
| 操作 | 執行 Repository 查詢 | **只讀取 queryResult** |

**關鍵規則**：ReadModel Then **不能重新執行 Query**。

---

## 實作範例

```php
<?php
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;

class ReadModelThenContext implements Context
{
    private FeatureContext $feature;
    public function __construct(FeatureContext $feature) { $this->feature = $feature; }

    /**
     * @Then 查詢結果應包含進度 :progress，狀態為 :status
     */
    public function resultShouldContain(int $progress, string $status): void
    {
        $result = $this->feature->queryResult;
        Assert::assertNotNull($result, '查詢結果為空');

        $statusMap = ['進行中' => 'IN_PROGRESS', '已完成' => 'COMPLETED', '未開始' => 'NOT_STARTED'];

        Assert::assertSame($progress, $result->progress);
        Assert::assertSame($statusMap[$status] ?? $status, $result->status);
    }
}
```

---

## 驗證列表結果

```php
/**
 * @Then 查詢結果應包含 :count 筆課程進度
 */
public function resultShouldContainCount(int $count): void
{
    $result = $this->feature->queryResult;
    Assert::assertNotNull($result);
    Assert::assertCount($count, $result);
}
```

---

## 驗證列表內容（DataTable）

```php
/**
 * @Then 查詢結果應包含以下課程進度：
 */
public function resultShouldContainItems(TableNode $table): void
{
    $result = $this->feature->queryResult;
    Assert::assertNotNull($result);

    $rows = $table->getHash();
    Assert::assertCount(count($rows), $result);

    foreach ($rows as $i => $row) {
        Assert::assertSame((int) $row['lessonId'], $result[$i]->lessonId);
        Assert::assertSame((int) $row['progress'], $result[$i]->progress);
    }
}
```

---

## 驗證空結果

```php
/**
 * @Then 查詢結果應為空
 */
public function resultShouldBeEmpty(): void
{
    $result = $this->feature->queryResult;
    if (is_array($result)) {
        Assert::assertEmpty($result);
    } else {
        Assert::assertNull($result);
    }
}
```

---

## Critical Rules

### R1: 使用 $this->feature->queryResult（不重新查詢）
### R2: Then 不得重新執行 Query
### R3: 驗證前檢查結果不為 null
### R4: 狀態映射（中文 → 英文）
### R5: 建構子注入 FeatureContext

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
