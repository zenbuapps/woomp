Feature: PayUni ATM 轉帳付款
  As a 消費者
  I want 透過 ATM 轉帳的方式完成訂單付款
  So that 不需要信用卡也能完成購物

  Background:
    Given PayUni 金流已啟用
    And ATM 閘道已啟用（payuni-atm enabled = yes）
    And 訂單金額大於等於 10 元

  Rule: ATM 取號與轉帳流程

    Scenario: 消費者成功取得虛擬轉帳帳號
      When 消費者選擇轉帳銀行為「台灣銀行」（004）
      And 消費者點擊下單
      Then 系統發送 POST 請求到 PayUni api/atm
      And 請求參數包含 BankType='004' 和 NotifyURL
      And PayUni 回傳虛擬帳號資訊（BankType, PayNo, ExpireDate）
      And 訂單狀態設為 pending
      And 訂單 meta 儲存轉帳銀行代碼、虛擬帳號、轉帳期限
      And 系統排程到期自動取消任務

    Scenario: 消費者成功完成 ATM 轉帳
      Given 消費者已取得虛擬帳號
      And 訂單狀態為 pending
      When 消費者在 ATM 完成轉帳
      And PayUni 發送背景通知到 wc-api/payuni_notify_atm
      Then 系統解密通知資料
      And 訂單狀態更新為 processing
      And 訂單備註記錄繳費時間和轉帳後五碼

    Scenario: 消費者超過繳費期限未轉帳
      Given 消費者已取得虛擬帳號
      And 訂單狀態為 pending
      When 轉帳期限到期
      Then Action Scheduler 觸發 payuni_atm_check
      And 訂單狀態更新為 cancelled
      And 訂單備註記錄「超過繳費期限，該訂單已取消！」

  Rule: 取號錯誤處理

    Scenario: PayUni 回傳相同訂單編號錯誤（ATM04001）
      When PayUni 回傳 Status = 'ATM04001'
      Then 系統建立新訂單（woomp_copy_order）
      And 使用新訂單編號重新執行 process_payment

  Rule: 訂單完成頁面與 Email 顯示

    Scenario: 消費者在訂單完成頁面看到轉帳資訊
      Given ATM 取號成功
      When 消費者檢視訂單詳情頁面
      Then 頁面顯示：交易訊息、交易編號、轉帳銀行代碼、轉帳銀行帳號、轉帳期限

    Scenario: 消費者收到包含轉帳資訊的 Email
      Given ATM 取號成功
      And 訂單狀態為 on-hold
      When 系統發送訂單通知 Email
      Then Email 中包含轉帳資訊區塊（交易編號、轉帳銀行代碼、轉帳帳號、轉帳期限）

  Rule: 銀行選項

    Scenario: 結帳頁顯示可用的轉帳銀行
      When 消費者選擇 ATM 付款方式
      Then 頁面顯示銀行選擇下拉選單
      And 可選銀行包含：台灣銀行(004)、中信銀行(822)、國泰世華(013)
