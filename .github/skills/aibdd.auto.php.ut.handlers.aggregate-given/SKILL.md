---
name: aibdd.auto.php.ut.handlers.aggregate-given
description: 當在 Gherkin 測試中進行「Aggregate 初始狀態建立」，「只能」使用此指令。
user-invocable: false
---

# Aggregate-Given-Handler (Behat BDD Version)

## Role

負責實作 `Given` 步驟中建立 Aggregate 初始狀態的邏輯。

**核心任務**：透過 Repository（從 FeatureContext 取得）直接建立 Aggregate 初始資料。

---

## ⚠️ 注意事項

- **不透過 Service**：直接使用 `$this->feature->repos->*->save()` 建立資料
- **不驗證業務規則**
- **使用 Fake Repository**：資料儲存在 array 中
- **從 FeatureContext 取得 Repository**

---

## 實作範例

```php
<?php
use Behat\Behat\Context\Context;

class AggregateGivenContext implements Context
{
    private FeatureContext $feature;
    public function __construct(FeatureContext $feature) { $this->feature = $feature; }

    /**
     * @Given 用戶 :userName 在課程 :lessonId 的進度為 :progress%，狀態為 :status
     */
    public function userHasProgress(string $userName, int $lessonId, int $progress, string $status): void
    {
        // 1. 取得或建立用戶 ID
        if (!isset($this->feature->ids[$userName])) {
            $this->feature->ids[$userName] = $userName;
        }
        $userId = $this->feature->ids[$userName];

        // 2. 狀態映射
        $statusMap = [
            '進行中' => 'IN_PROGRESS',
            '已完成' => 'COMPLETED',
            '未開始' => 'NOT_STARTED',
        ];

        // 3. 建立 Aggregate
        $entity = new LessonProgress();
        $entity->userId = $userId;
        $entity->lessonId = $lessonId;
        $entity->progress = $progress;
        $entity->status = $statusMap[$status] ?? $status;

        // 4. 儲存到 Repository
        $this->feature->repos->lessonProgress->save($entity);
    }
}
```

---

## 處理 DataTable

```php
/**
 * @Given 系統中有以下課程：
 */
public function systemHasLessons(TableNode $table): void
{
    foreach ($table->getHash() as $row) {
        $lesson = new Lesson();
        $lesson->lessonId = (int) $row['lessonId'];
        $lesson->name = $row['name'];
        $this->feature->repos->lesson->save($lesson);
    }
}
```

---

## 處理 DocString

```php
/**
 * @Given 用戶 :userName 的個人簡介為：
 */
public function userHasBio(string $userName, PyStringNode $bio): void
{
    if (!isset($this->feature->ids[$userName])) {
        throw new \RuntimeException("找不到 '{$userName}' 對應的 ID");
    }
    $userId = $this->feature->ids[$userName];
    $user = $this->feature->repos->user->find($userId);
    $user->bio = $bio->getRaw();
    $this->feature->repos->user->save($user);
}
```

---

## Critical Rules

### R1: 不透過 Service（直接使用 Repository）
### R2: 從 FeatureContext 取得 Repository
### R3: userName → userId 映射存入 $this->feature->ids
### R4: 狀態映射（中文 → 英文 enum）
### R5: DataTable 使用 TableNode 參數
### R6: DocString 使用 PyStringNode 參數
### R7: 建構子注入 FeatureContext

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
