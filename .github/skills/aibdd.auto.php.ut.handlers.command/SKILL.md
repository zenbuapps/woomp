---
name: aibdd.auto.php.ut.handlers.command
description: 當在 Gherkin 中撰寫寫入操作步驟（Given 已完成 / When 執行中），務必參考此規範。
user-invocable: false
---

# Command-Handler (Behat BDD Version)

## Role

負責實作 `Given`（已完成的動作）和 `When`（執行中的動作）步驟中的 Command 操作。

**核心任務**：直接呼叫 Service 方法執行寫入操作。

---

## ⚠️ 與 E2E 的關鍵差異

| 項目 | E2E 版本 | Unit Test 版本 |
|------|---------|---------------|
| 執行方式 | HTTP POST | **直接呼叫 Service 方法** |
| 認證 | Cookie/Session | **不需認證** |
| 結果處理 | HTTP Response | **捕獲 Throwable** |
| 依賴注入 | WordPress hooks | **FeatureContext** |

---

## Given vs When

### Given（已完成的動作）

```php
/**
 * @Given 用戶 :userName 已訂閱旅程 :journeyId
 */
public function userSubscribed(string $userName, int $journeyId): void
{
    if (!isset($this->feature->ids[$userName])) {
        throw new \RuntimeException("找不到 '{$userName}' 對應的 ID");
    }
    $userId = $this->feature->ids[$userName];

    // Given 假設成功，不需要 try-catch
    $this->feature->services->journey->subscribe($userId, $journeyId);
}
```

### When（執行中的動作）

```php
/**
 * @When 用戶 :userName 更新課程 :lessonId 的影片進度為 :progress%
 */
public function userUpdatesProgress(string $userName, int $lessonId, int $progress): void
{
    if (!isset($this->feature->ids[$userName])) {
        throw new \RuntimeException("找不到 '{$userName}' 對應的 ID");
    }
    $userId = $this->feature->ids[$userName];

    try {
        $this->feature->services->lesson->updateVideoProgress($userId, $lessonId, $progress);
        $this->feature->lastError = null;
    } catch (\Throwable $e) {
        $this->feature->lastError = $e;
    }
}
```

---

## 處理 DataTable

```php
/**
 * @When 管理員批次建立以下用戶：
 */
public function adminCreatesUsers(TableNode $table): void
{
    try {
        foreach ($table->getHash() as $row) {
            $this->feature->services->user->register($row['name'], $row['email']);
        }
        $this->feature->lastError = null;
    } catch (\Throwable $e) {
        $this->feature->lastError = $e;
    }
}
```

---

## 處理 DocString

```php
/**
 * @When 用戶 :userName 提交作業，內容為：
 */
public function userSubmitsAssignment(string $userName, PyStringNode $content): void
{
    $userId = $this->feature->ids[$userName];
    try {
        $this->feature->services->assignment->submit($userId, $content->getRaw());
        $this->feature->lastError = null;
    } catch (\Throwable $e) {
        $this->feature->lastError = $e;
    }
}
```

---

## Critical Rules

### R1: 所有 When 步驟必須捕獲錯誤（try-catch）
### R2: Given 通常不需要捕獲錯誤
### R3: 從 FeatureContext 取得 Service
### R4: userName → userId 轉換
### R5: DataTable 使用 TableNode
### R6: DocString 使用 PyStringNode
### R7: lastError 是 FeatureContext 的公開屬性

---

**文件版本**：Unit Test Behat BDD Version 1.0
**適用框架**：PHP 8.2+ + Behat 3.x + WordPress
