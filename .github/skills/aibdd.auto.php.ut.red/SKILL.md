---
name: aibdd.auto.php.ut.red
description: PHP UT Stage 2：紅燈生成器。建立 FakeRepository（BadMethodCallException）+ Service 介面（BadMethodCallException）+ 完整 Step Definition。
user-invocable: true
argument-hint: "[feature-file]"
input: tests/contexts/**/*.php（樣板）, handler skills
output: tests/contexts/**/*.php（完整）, src/Models/*.php, src/Repositories/*.php, src/Services/*.php
---

# 角色

BDD 測試協調器，負責紅燈階段。讀取樣板中的 TODO 註解，調用對應的 Handler Skill 來實作。

---

# 入口條件（雙模式）

## 模式 A：獨立使用
## 模式 B：被 /php-ut 調用

---

# 紅燈階段的核心原則

## 可以做的事
- 實作 Step Definition 方法
- 建立 Model 類別（plain PHP class）
- 建立 FakeRepository（方法拋出 `\BadMethodCallException`）
- 建立 Service 類別（方法拋出 `\BadMethodCallException`）
- 在 FeatureContext.php 設定 `@BeforeScenario` 初始化

## 不可以做的事
- **不實作 FakeRepository 的方法體**
- **不實作 Service 的業務邏輯**
- **不讓測試通過**

---

# 核心契約（FeatureContext）

```php
<?php
class FeatureContext implements Context
{
    public object $repos;
    public object $services;
    public ?\Throwable $lastError = null;
    public mixed $queryResult = null;
    public array $ids = [];

    /** @BeforeScenario */
    public function beforeScenario(): void
    {
        $this->repos = new \stdClass();
        $this->services = new \stdClass();
        $this->lastError = null;
        $this->queryResult = null;
        $this->ids = [];

        $this->repos->lessonProgress = new LessonProgressRepository();
        $this->services->lesson = new LessonService($this->repos->lessonProgress);
    }
}
```

---

# 範例產出

```php
// src/Models/LessonProgress.php
class LessonProgress
{
    public string $userId = '';
    public int $lessonId = 0;
    public int $progress = 0;
    public string $status = '';
}
```

```php
// src/Repositories/LessonProgressRepository.php
class LessonProgressRepository
{
    public function save(LessonProgress $entity): void
    {
        throw new \BadMethodCallException('紅燈階段：尚未實作');
    }

    public function find(string $userId, int $lessonId): ?LessonProgress
    {
        throw new \BadMethodCallException('紅燈階段：尚未實作');
    }
}
```

```php
// src/Services/LessonService.php
class LessonService
{
    public function __construct(private readonly LessonProgressRepository $repo) {}

    public function updateVideoProgress(string $userId, int $lessonId, int $progress): void
    {
        throw new \BadMethodCallException('紅燈階段：尚未實作');
    }
}
```

---

# 執行測試確認紅燈

```bash
vendor/bin/behat
```

**預期結果**：測試失敗（BadMethodCallException）

---

# 路牌對照表

| TODO 中的 Handler | Handler Skill |
|-------------------|--------------|
| aggregate-given | /aibdd.auto.php.ut.handlers.aggregate-given |
| aggregate-then | /aibdd.auto.php.ut.handlers.aggregate-then |
| command | /aibdd.auto.php.ut.handlers.command |
| query | /aibdd.auto.php.ut.handlers.query |
| readmodel-then | /aibdd.auto.php.ut.handlers.readmodel-then |
| success-failure | /aibdd.auto.php.ut.handlers.success-failure |

---

# Critical Rules

### R1: Step Definition 必須完整（不能只有 PendingException）
### R2: 介面定義完整，但不實作業務邏輯
### R3: FeatureContext beforeScenario 必須初始化所有 repos/services
### R4: 測試會失敗（紅燈）
### R5: 依賴透過 FeatureContext 取得
### R6: lastError 和 queryResult 是 FeatureContext 的公開屬性

---

# 完成條件

- 所有 Step Definition 完整實作
- Model 類別定義完整
- FakeRepository 所有方法拋出 `BadMethodCallException`
- Service 所有方法拋出 `BadMethodCallException`
- FeatureContext 正確初始化
- `vendor/bin/behat` 測試失敗（紅燈）
- Feature File 的 `@ignore` tag 已移除
