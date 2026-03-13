---
name: aibdd.auto.php.ut.green
description: PHP UT Stage 3：綠燈階段。實作 FakeRepository（array-based）+ Service 業務邏輯。Trial-and-error 循環直到測試通過。
user-invocable: true
argument-hint: "[feature-file]"
input: tests/features/**/*.feature, src/Repositories/*.php, src/Services/*.php
output: src/Repositories/*.php（完整實作）, src/Services/*.php（完整實作）
---

# 角色

BDD 綠燈階段協調器。

**核心任務**：實作最簡單的業務邏輯，讓測試從紅燈變成綠燈。

---

# 入口條件（雙模式）

## 模式 A：獨立使用
## 模式 B：被 /php-ut 調用

---

# 綠燈階段的核心原則

## 可以做的事
- 實作 FakeRepository（array-based 儲存）
- 實作 Service 業務邏輯
- 建立自定義例外類別
- 讓測試通過

## 不可以做的事
- 不要過度設計
- 不要加入測試沒有要求的功能
- 不要優化程式碼

---

# FakeRepository 範例

```php
// src/Repositories/LessonProgressRepository.php（綠燈）
class LessonProgressRepository
{
    /** @var array<string, LessonProgress> */
    private array $store = [];

    public function save(LessonProgress $entity): void
    {
        $key = $entity->userId . ':' . $entity->lessonId;
        $this->store[$key] = $entity;
    }

    public function find(string $userId, int $lessonId): ?LessonProgress
    {
        $key = $userId . ':' . $lessonId;
        return $this->store[$key] ?? null;
    }

    /** @return LessonProgress[] */
    public function findAll(): array
    {
        return array_values($this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
```

---

# Service 範例

```php
// src/Services/LessonService.php（綠燈）
class LessonService
{
    public function __construct(private readonly LessonProgressRepository $repo) {}

    public function updateVideoProgress(string $userId, int $lessonId, int $progress): void
    {
        $current = $this->repo->find($userId, $lessonId);

        if ($current === null) {
            $entity = new LessonProgress();
            $entity->userId = $userId;
            $entity->lessonId = $lessonId;
            $entity->progress = $progress;
            $entity->status = 'IN_PROGRESS';
            $this->repo->save($entity);
            return;
        }

        if ($progress < $current->progress) {
            throw new InvalidStateException('進度不可倒退');
        }

        $current->progress = $progress;
        if ($progress >= 100) {
            $current->status = 'COMPLETED';
        }
        $this->repo->save($current);
    }
}
```

---

# 自定義例外

```php
// src/Exceptions/InvalidStateException.php
class InvalidStateException extends \RuntimeException {}

// src/Exceptions/NotFoundException.php
class NotFoundException extends \RuntimeException {}
```

---

# 工作流程

1. 執行 `vendor/bin/behat` 確認紅燈
2. 實作 FakeRepository（array-based）
3. 實作 Service 業務邏輯
4. 執行 `vendor/bin/behat` 確認綠燈
5. 回歸測試：`vendor/bin/behat`

---

# Critical Rules

### R1: 只寫能讓測試通過的最簡單邏輯
### R2: FakeRepository 使用 array
### R3: 依賴透過 FeatureContext beforeScenario 初始化
### R4: 共用 Repository 實例
### R5: 不要重構
### R6: 必須執行完整回歸測試

---

# 完成條件

- FakeRepository array-based 完整實作
- Service 實作最簡單的業務邏輯
- `vendor/bin/behat` 所有測試通過
