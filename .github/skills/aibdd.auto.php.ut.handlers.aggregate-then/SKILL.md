---
name: aibdd.auto.php.ut.handlers.aggregate-then
description: 當在 Gherkin 測試中驗證「Aggregate 最終狀態」，務必參考此規範。
user-invocable: false
---

# Aggregate-Then-Handler (Behat BDD Version)

## Role

負責實作 `Then` 步驟中驗證 Aggregate 最終狀態的邏輯。

**核心任務**：透過 Repository（從 FeatureContext 取得）查詢 Aggregate 狀態並驗證。

---

## ⚠️ 注意事項

- **透過 Repository 查詢**：`$this->feature->repos->*->find()`
- **不透過 Service**
- **使用 PHPUnit Assert 驗證**

---

## 實作範例

```php
<?php
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;

class AggregateThenContext implements Context
{
    private FeatureContext $feature;
    public function __construct(FeatureContext $feature) { $this->feature = $feature; }

    /**
     * @Then 用戶 :userName 在課程 :lessonId 的進度應為 :progress%
     */
    public function userProgressShouldBe(string $userName, int $lessonId, int $progress): void
    {
        if (!isset($this->feature->ids[$userName])) {
            throw new \RuntimeException("找不到 '{$userName}' 對應的 ID");
        }
        $userId = $this->feature->ids[$userName];

        $entity = $this->feature->repos->lessonProgress->find($userId, $lessonId);

        Assert::assertNotNull($entity, "找不到用戶 {$userName} 在課程 {$lessonId} 的進度");
        Assert::assertSame($progress, $entity->progress,
            "預期進度 {$progress}%，實際為 {$entity->progress}%");
    }
}
```

---

## 驗證多個屬性

```php
/**
 * @Then 用戶 :userName 在課程 :lessonId 的進度應為 :progress%，狀態為 :status
 */
public function userProgressAndStatusShouldBe(string $userName, int $lessonId, int $progress, string $status): void
{
    $userId = $this->feature->ids[$userName];
    $statusMap = ['進行中' => 'IN_PROGRESS', '已完成' => 'COMPLETED', '未開始' => 'NOT_STARTED'];

    $entity = $this->feature->repos->lessonProgress->find($userId, $lessonId);
    Assert::assertNotNull($entity);
    Assert::assertSame($progress, $entity->progress);
    Assert::assertSame($statusMap[$status] ?? $status, $entity->status);
}
```

---

## 驗證 DataTable

```php
/**
 * @Then 系統中應有以下課程進度：
 */
public function systemShouldHaveProgress(TableNode $table): void
{
    foreach ($table->getHash() as $row) {
        $userId = $this->feature->ids[$row['userName']];
        $entity = $this->feature->repos->lessonProgress->find($userId, (int) $row['lessonId']);
        Assert::assertNotNull($entity);
        Assert::assertSame((int) $row['progress'], $entity->progress);
    }
}
```

---

## 驗證不存在

```php
/**
 * @Then 用戶 :userName 在課程 :lessonId 的進度記錄不存在
 */
public function progressShouldNotExist(string $userName, int $lessonId): void
{
    $userId = $this->feature->ids[$userName];
    $entity = $this->feature->repos->lessonProgress->find($userId, $lessonId);
    Assert::assertNull($entity, '預期不存在，但找到了記錄');
}
```

---

## Critical Rules

### R1: 透過 Repository 查詢（不透過 Service）
### R2: 從 FeatureContext 取得 Repository
### R3: userName → userId 轉換（必須已在 ids 中）
### R4: 狀態映射（中文 → 英文）
### R5: 驗證前檢查不為 null
### R6: DataTable 使用 TableNode
### R7: 建構子注入 FeatureContext

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
