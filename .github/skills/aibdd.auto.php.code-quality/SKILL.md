---
name: aibdd.auto.php.code-quality
description: PHP 程式碼品質規範合集。包含 SOLID 設計原則、Step Definition 組織規範、Meta 註記清理、日誌實踐、程式架構、程式碼品質等規範。供 refactor 階段嚴格遵守。
user-invocable: false
---

# SOLID 設計原則

## S - Single Responsibility Principle（單一職責原則）

```php
// ❌ Service 做太多事
class AssignmentService {
    public function submit(string $userId, string $content): void {
        if (!$this->checkPermission($userId)) throw new \UnauthorizedError();
        if (strlen($content) < 10) throw new \InvalidArgumentException();
        $this->repo->save(new Assignment($userId, $content));
        $this->sendEmail($userId);
    }
}

// ✅ 職責分離
class AssignmentService {
    public function __construct(
        private readonly AssignmentRepository $assignmentRepo,
        private readonly PermissionValidator $permissionValidator,
        private readonly NotificationService $notificationService,
    ) {}

    public function submit(string $userId, string $content): void {
        $this->permissionValidator->validate($userId);
        $this->assignmentRepo->save(new Assignment($userId, $content));
        $this->notificationService->notify($userId);
    }
}
```

## D - Dependency Inversion Principle（依賴反轉原則）

```php
// ✅ Service 透過建構子注入 Repository
class LessonService {
    public function __construct(
        private readonly LessonProgressRepository $lessonProgressRepo,
    ) {}

    public function updateProgress(string $userId, int $lessonId, int $progress): void {
        $current = $this->lessonProgressRepo->find($userId, $lessonId);
        // 業務邏輯...
    }
}
```

---

## 檢查清單
- [ ] 每個類別/方法只負責一件事
- [ ] Service 透過建構子注入 Repository
- [ ] 高層模組不直接依賴低層模組

---

# Step Definition 組織規範

## 組織原則

- 使用 Behat Context 類別分類
- 按 Subdomain 和事件風暴部位組織
- FeatureContext 作為共用狀態容器

## 目錄結構

```
tests/
├── bootstrap/
│   └── FeatureContext.php          # 共用狀態 + hooks
├── contexts/
│   ├── Lesson/                     # {Subdomain}
│   │   ├── AggregateGivenContext.php
│   │   ├── CommandContext.php
│   │   ├── QueryContext.php
│   │   ├── AggregateThenContext.php
│   │   └── ReadModelThenContext.php
│   └── CommonThenContext.php
└── behat.yml
```

## behat.yml 配置

```yaml
default:
  suites:
    default:
      contexts:
        - FeatureContext
        - Lesson\AggregateGivenContext:
            - '@FeatureContext'
        - Lesson\CommandContext:
            - '@FeatureContext'
        - CommonThenContext:
            - '@FeatureContext'
```

---

# Meta 註記清理規範

## 刪除的內容
- `// TODO: [事件風暴部位: ...]`
- `// TODO: 參考 xxx 實作`
- 其他臨時標記

## 保留的內容
- 必要的業務邏輯註解
- PHPDoc 文檔註解

---

# 程式架構規範（UT 2 層）

```
src/
├── Models/          # Plain PHP class
├── Repositories/    # FakeRepository（array-based）
├── Services/        # Service（業務邏輯）
└── Exceptions/      # 自定義例外
```

---

# 程式碼品質規範

## Early Return

```php
// ❌ 深層巢狀
function process(?Data $data): void {
    if ($data !== null) {
        if ($data->isValid()) {
            processData($data);
        } else { throw new ValidationException(); }
    } else { throw new DataException(); }
}

// ✅ Early return
function process(?Data $data): void {
    if ($data === null) throw new DataException();
    if (!$data->isValid()) throw new ValidationException();
    processData($data);
}
```

## 命名規範
- PascalCase：類別名稱
- camelCase：方法、屬性、變數
- snake_case：WordPress 函式（遵循 WordPress 慣例時）

## DRY 原則
消除重複邏輯，提取共用方法。

---

## 檢查清單
- [ ] 使用 Early Return
- [ ] 命名遵循 PHP/WordPress 慣例
- [ ] 消除重複邏輯

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
