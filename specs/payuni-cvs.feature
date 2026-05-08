Feature: PayUni 超商代碼繳費
  As a 消費者
  I want 使用超商代碼繳費方式完成訂單付款
  So that 可以到便利商店繳費而不需要銀行帳戶或信用卡

  Background:
    Given PayUni 金流已啟用
    And CVS 閘道已啟用（payuni-cvs enabled = yes）
    And 訂單金額大於等於 10 元

  Rule: 超商代碼取號流程

    Scenario: 消費者成功取得超商繳費代碼
      When 消費者選擇 CVS 付款方式並下單
      Then 系統發送 POST 請求到 PayUni api/cvs
      And 請求參數包含 NotifyURL = home_url('wc-api/payuni_notify_cvs')
      And 消費者被導向訂單完成頁面

    Scenario: 消費者完成超商繳費
      Given 消費者已取得繳費代碼
      When 消費者至超商完成繳費
      And PayUni 發送背景通知到 wc-api/payuni_notify_cvs
      And 通知 Status = 'SUCCESS'
      Then 系統解密通知資料
      And 訂單狀態更新為 processing
      And 訂單備註記錄繳費結果（狀態碼、繳費結果、繳費時間、轉帳後五碼）

  Rule: CVS 訂單詳情顯示

    Scenario: 消費者檢視超商繳費訂單明細
      Given 消費者已使用 payuni-cvs 完成取號
      When 消費者檢視訂單詳情頁面
      Then 頁面顯示交易明細：狀態碼、交易訊息、交易編號、轉帳銀行代碼、轉帳銀行帳號、轉帳期限

  Rule: 已知限制

    Scenario: CVS 取號回應中的 early return
      When PayUni 回傳 CVS 取號成功
      Then cvs_response 在 Payment::log() 後直接 return
      And 訂單 meta 不會被更新（_payuni_resp_* 系列不會儲存）
      And 訂單到期檢查排程不會建立
      And 此為目前程式碼的已知行為
