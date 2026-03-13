@ignore @command
Feature: 對應錯誤訊息

  PayUni API 回傳錯誤代碼時，系統將錯誤代碼對應為中文訊息並顯示於結帳頁面。
  錯誤代碼共 109 組，分為 7 大類：SDK(1xxx)、OBJ、TOKEN、IFTRADE、TRADE、API、DEF。
  前端與後端共用同一份錯誤對應表（HttpClient::$error_mapper），
  前端透過 wp_localize_script 取得，後端在 API 呼叫失敗時直接查表。

  Background:
    Given 系統中有以下錯誤代碼對應表：
      | code           | message                                            |
      | 1001           | iframe 連線失敗, 無法使用相關 function                |
      | 1005           | 填寫欄位有誤，請重新確認 (請配合 onUpdate 去確認狀態)   |
      | 1007           | 非法的跨域溝通，Token 設定的 限定網域名稱 與 當前網域端 不吻合 |
      | 1008           | iframe 嘗試連線時間過長, 中斷連線 (timeout)           |
      | IFTRADE04001   | Token已過期                                         |
      | IFTRADE04002   | 未有交易設定資料                                      |
      | TRADE02008     | 信用卡到期日，已逾期                                   |
      | TOKEN01002     | 資料 HASH 比對不符合                                  |

  Rule: 後置（回應）- PayUni API 錯誤代碼應對應為「{code}: {中文訊息}」格式

    Example: 已知錯誤代碼對應中文訊息
      Given PayUni API 回傳 Status 為 "IFTRADE04001"
      When 系統對應錯誤訊息
      Then 錯誤訊息應為 "IFTRADE04001: Token已過期"

    Example: 未知錯誤代碼顯示 fallback 訊息
      Given PayUni API 回傳 Status 為 "UNKNOWN_CODE_999"
      When 系統對應錯誤訊息
      Then 錯誤訊息應為 "UNKNOWN_CODE_999: 未知錯誤"

  Rule: 後置（狀態）- 後端 API 錯誤應透過 WooCommerce notice 顯示於結帳頁

    交易失敗時，CreditV3::process_payment 捕捉 Exception，
    呼叫 wc_add_notice($message, 'error') 將中文錯誤訊息加入結帳頁通知。
    WooCommerce 自動將通知渲染至 .woocommerce-NoticeGroup-checkout 容器。

    Example: 交易失敗時結帳頁顯示中文錯誤訊息
      Given PayUni merchant_trade API 回傳 Status 為 "TRADE02008"
      When 系統處理交易結果
      Then 結帳頁應顯示錯誤通知 "TRADE02008: 信用卡到期日，已逾期"
      And 錯誤通知應位於 .woocommerce-NoticeGroup-checkout 容器

    Example: SDK Token 過期時結帳頁顯示錯誤
      Given PayUni merchant_trade API 回傳 Status 為 "IFTRADE04001"
      When 系統處理交易結果
      Then 結帳頁應顯示錯誤通知 "IFTRADE04001: Token已過期"

  Rule: 後置（狀態）- 前端 SDK iframe 錯誤應即時顯示於結帳頁

    SDK iframe 連線或驗證錯誤由前端 JavaScript 即時處理。
    PayUniService.module.js 的 #handleConnectionError 方法處理 SDK 事件，
    透過 UIHelper.showError() 將錯誤訊息渲染至結帳頁。

    Example: SDK iframe 連線逾時時顯示錯誤
      Given SDK iframe 回傳連線錯誤代碼 1008
      When 前端處理 SDK 錯誤
      Then 結帳頁應顯示錯誤訊息 "信用卡輸入框連線逾時，請重新整理頁面"
      And 頁面應自動捲動至錯誤通知位置

    Example: SDK 網域驗證失敗時顯示錯誤
      Given SDK iframe 回傳連線錯誤代碼 1007
      When 前端處理 SDK 錯誤
      Then 結帳頁應顯示錯誤訊息 "網域驗證失敗，請聯繫網站管理員"

    Example: SDK 其他錯誤代碼使用 error_mapper 對應
      Given SDK iframe 回傳錯誤代碼 1005
      When 前端處理 SDK 錯誤
      Then 結帳頁應顯示錯誤訊息 "填寫欄位有誤，請重新確認 (請配合 onUpdate 去確認狀態)"

  Rule: 前置（狀態）- 錯誤對應表必須在結帳頁前端可用

    HttpClient::$error_mapper 透過 Bootstrap::enqueue_checkout_scripts 中的
    wp_localize_script 傳至前端，儲存於 window.payuni_payment_v3_checkout_params.ERROR_MAPPER。

    Example: 結帳頁前端可取得錯誤對應表
      Given 系統已載入結帳頁 SDK
      When 前端查詢錯誤對應表
      Then window.payuni_payment_v3_checkout_params.ERROR_MAPPER 應包含 109 組錯誤代碼

  Rule: 後置（狀態）- 顯示多筆錯誤時應全部列出

    UIHelper.showErrors() 支援同時顯示多筆錯誤訊息，
    每筆錯誤為獨立的 <li> 元素。

    Example: 多筆驗證錯誤同時顯示
      Given 前端偵測到以下錯誤：
        | error_message                |
        | 請確認信用卡安全碼            |
        | 手機條碼格式錯誤              |
      When 前端顯示錯誤
      Then 結帳頁應顯示 2 筆錯誤通知
      And 每筆錯誤應為獨立的清單項目

  Rule: 後置（狀態）- 新的錯誤應清除前次的錯誤通知

    每次顯示新錯誤前，UIHelper.clearErrors() 先清除前次的通知，
    避免錯誤訊息堆疊混淆消費者。

    Example: 重新提交付款時清除前次錯誤
      Given 結帳頁已顯示錯誤通知 "TRADE02008: 信用卡到期日，已逾期"
      When 消費者修改卡片資訊後重新提交
      Then 前次錯誤通知應被清除
      And 若再次失敗應顯示新的錯誤通知
