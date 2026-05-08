Feature: 綠界電子發票折讓
  As a 店家管理員
  I want 為已開立的發票申請部分折讓
  So that 在部分退款時正確處理發票金額

  Background:
    Given 綠界電子發票模組已啟用

  Rule: 折讓功能目前不支援

    Scenario: 嘗試對綠界發票進行折讓
      Given 訂單 #123 已開立綠界電子發票
      When 管理員希望進行部分退款折讓
      Then 系統目前不提供折讓操作 UI
      And 管理員需透過綠界電子發票後台手動處理折讓

  # 備註：
  # 綠界 SDK（EcpayInvoiceSDK.php）已定義以下折讓相關 API 方法常數：
  # - ALLOWANCE: 開立折讓
  # - ALLOWANCE_BY_COLLEGIATE: 線上開立折讓（通知開立）
  # - ALLOWANCE_VOID: 折讓作廢
  # - ALLOWANCE_SEARCH: 查詢折讓明細
  # - ALLOWANCE_VOID_SEARCH: 查詢折讓作廢明細
  #
  # EcpayInvoice::Send 中已預留折讓欄位：
  # - AllowanceNotify, AllowanceAmount, AllowanceNo, NotifyMail, NotifyPhone
  #
  # 但 EcpayInvoiceHandler 未實作折讓方法，
  # 管理後台 UI 也未提供折讓操作按鈕。
  # 此功能為「SDK 可用但應用層未實作」的狀態。
