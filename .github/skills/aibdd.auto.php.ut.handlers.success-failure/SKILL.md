---
name: aibdd.auto.php.ut.handlers.success-failure
description: 當在 Gherkin 測試中驗證操作成功或失敗時，參考此規範。
user-invocable: false
---

# Success-Failure-Handler (Behat BDD Version)

## Role

負責實作 `Then` 步驟中驗證操作成功或失敗的邏輯。

**核心任務**：檢查 `$this->feature->lastError` 來判斷操作結果。

---

## 已實作步驟

這些步驟應在 `CommonThenContext.php` 中預先實作：

```php
<?php
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;

class CommonThenContext implements Context
{
    private FeatureContext $feature;
    public function __construct(FeatureContext $feature) { $this->feature = $feature; }

    /** @Then 操作成功 */
    public function operationSucceeds(): void
    {
        Assert::assertNull($this->feature->lastError,
            sprintf('預期操作成功，但發生錯誤：%s', $this->feature->lastError));
    }

    /** @Then 操作失敗 */
    public function operationFails(): void
    {
        Assert::assertNotNull($this->feature->lastError, '預期操作失敗，但沒有發生錯誤');
    }
}
```

---

## 擴展：驗證特定錯誤類型

```php
/**
 * @Then 操作失敗，錯誤類型為 :errorType
 */
public function operationFailsWithType(string $errorType): void
{
    $error = $this->feature->lastError;
    Assert::assertNotNull($error, '預期操作失敗');
    $actualType = (new \ReflectionClass($error))->getShortName();
    Assert::assertSame($errorType, $actualType);
}
```

---

## 擴展：驗證錯誤訊息

```php
/**
 * @Then 操作失敗，錯誤訊息包含 :message
 */
public function operationFailsWithMessage(string $message): void
{
    $error = $this->feature->lastError;
    Assert::assertNotNull($error, '預期操作失敗');
    Assert::assertStringContainsString($message, $error->getMessage());
}
```

---

## Critical Rules

### R1: 使用 $this->feature->lastError
### R2: 成功時 lastError 為 null
### R3: 失敗時 lastError 包含 Throwable 物件
### R4: 這是預設已實作的步驟（CommonThenContext）
### R5: 建構子注入 FeatureContext

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
