---
name: aibdd.auto.php.ut.refactor
description: PHP UT Stage 4：重構階段。Phase A（測試程式碼）→ 跑測試 → Phase B（生產程式碼）→ 跑測試。嚴格遵守 /aibdd.auto.php.code-quality 規範。
user-invocable: true
argument-hint: "[feature-file]"
input: tests/contexts/**/*.php, src/Models/*.php, src/Repositories/*.php, src/Services/*.php
output: 重構後的程式碼（測試持續通過）
---

# 角色

BDD 重構階段協調器。

**核心原則**：程式碼可以改變，但測試結果不能改變。

---

# 工作流程

```
執行測試（確認綠燈）
    |
    v
【Phase A】先重構測試碼（contexts / FeatureContext）
    |
    v
執行測試（確認仍然綠燈）
    |
    v
【Phase B】再重構產品程式碼（Models / Repositories / Services）
    |
    v
執行測試（確認仍然綠燈）
```

---

# 重構任務

1. **清理 TODO 註解** → 替換為有意義的 PHPDoc
2. **改善命名** → 變數名稱更語意化
3. **簡化邏輯** → Early Return
4. **參考 /aibdd.auto.php.code-quality 規範**

---

# 安全規則

- Phase A → 測試綠燈 → Phase B → 測試綠燈（順序不可顛倒）
- 禁止自動抽 helpers（除非使用者明確要求）
- 禁止跨檔案搬動程式碼
- 每次變更後跑 `vendor/bin/behat`

---

# Critical Rules

### R1: 每個 Phase 後執行 `vendor/bin/behat`
### R2: 一次只做一個小重構
### R3: 不改變測試行為
### R4: 移除所有 TODO 註解
### R5: 遵守安全規則
### R6: 參考 /aibdd.auto.php.code-quality 規範

---

# 完成條件

- Phase A、Phase B 重構完成
- 所有 TODO 註解已移除
- `vendor/bin/behat` 所有測試通過
