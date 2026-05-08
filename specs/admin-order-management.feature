Feature: 後台訂單管理
  As a 網站管理員
  I want 在後台管理訂單時有更多台灣物流/金流相關功能
  So that 我可以有效率地處理訂單、追蹤物流、管理訂單狀態

  Background:
    Given 管理員已登入且擁有 edit_shop_orders 權限
    And WooCommerce 與 Woomp 外掛已啟用

  Rule: 自訂訂單狀態

    Scenario: 新增「配送中」和「已出貨」訂單狀態
      When WooCommerce 訂單狀態列表載入
      Then 在「處理中」之後出現「配送中」和「已出貨」狀態
      And 「配送中」和「已出貨」在管理員狀態列表中可見
      And 「配送中」和「已出貨」被列為已付款狀態（影響報表）

  Rule: 訂單列表欄位

    Scenario: 訂單列表顯示金流單號和物流單號欄位
      When 管理員進入訂單列表頁
      Then 在「運送地址」欄位後出現「金流單號」和「物流單號」欄位

    Scenario: 顯示綠界物流單號
      Given 訂單 #100 的 _ecpay_shipping_info meta 包含 LogisticsType 為 "CVS" 的物流資訊
      When 管理員在訂單列表查看訂單 #100
      Then 「物流單號」欄位顯示 PaymentNo + ValidationNo

    Scenario: 顯示 PayNow 物流單號
      Given 訂單 #101 無 _ecpay_shipping_info meta
      And 訂單 #101 有 _paynow_shipping_paymentno meta 為 "PN12345"
      When 管理員在訂單列表查看訂單 #101
      Then 「物流單號」欄位顯示 "PN12345"

    Scenario: 手動輸入物流單號
      Given 訂單 #102 無綠界和 PayNow 物流資訊
      When 管理員在訂單列表查看訂單 #102
      Then 「物流單號」欄位顯示文字輸入框
      When 管理員輸入 "TRACK001" 並按 Enter
      Then 透過 AJAX 將物流單號儲存至 wmp_shipping_no meta
      And 顯示載入指示器後消失

    Scenario: 訂單列表顯示電話資訊
      Given 選項 wc_woomp_setting_show_phone 為 "yes"
      And 訂單 #103 帳單電話為 "0912345678"
      When 管理員在訂單列表查看訂單 #103
      Then 帳單地址欄位下方顯示「電話 0912345678」

    Scenario: 訂單列表不顯示電話資訊
      Given 選項 wc_woomp_setting_show_phone 為 "no"
      When 管理員在訂單列表查看任何訂單
      Then 不顯示額外的電話資訊

  Rule: 後台訂單地址顯示

    Scenario: 地址欄位整併（開啟）
      Given 選項 wc_woomp_setting_one_line_address 為 "yes"
      When 管理員進入訂單 #100 的編輯頁面
      Then 帳單區域顯示整併後的「帳單姓名」和「帳單地址」
      And 原始的多欄位地址被隱藏

    Scenario: 超商取貨訂單顯示門市資訊
      Given 選項 wc_woomp_setting_one_line_address 為 "yes"
      And 訂單 #100 使用超商取貨物流
      When 管理員進入訂單 #100 的編輯頁面
      Then 運送區域顯示門市編號、門市名稱、門市地址
      And 不顯示一般運送地址

    Scenario: 宅配訂單顯示運送地址
      Given 選項 wc_woomp_setting_one_line_address 為 "yes"
      And 訂單 #100 使用宅配物流
      When 管理員進入訂單 #100 的編輯頁面
      Then 運送區域顯示整併後的「運送地址」

  Rule: 超商重新選擇

    Scenario: 綠界超商訂單顯示重新選擇按鈕
      Given 綠界物流已啟用
      And 訂單 #100 使用綠界 CVS 物流
      When 管理員進入訂單 #100 的編輯頁面
      Then 運送地址區域顯示「Update convenience store」按鈕
      And 提示「After choosing cvs, you need update the order to save changing.」

    Scenario: 管理員重新選擇超商門市
      Given 訂單 #100 的編輯頁面顯示重新選擇按鈕
      When 管理員點擊「Update convenience store」按鈕
      Then 開啟綠界超商選擇地圖頁面
      When 管理員選擇新門市後返回
      Then 門市資訊欄位更新為新選擇的門市
      And 地址編輯模式自動開啟

  Rule: 刪除綠界物流資訊

    Scenario: 成功刪除綠界物流資訊
      Given 管理員擁有 edit_shop_orders 權限
      And 訂單 #100 有多筆綠界物流資訊
      When 管理員點擊刪除某筆物流資訊
      Then 該筆物流資訊從 _ecpay_shipping_info meta 中移除
      And 回傳成功訊息「已成功刪除物流資訊」

    Scenario: 權限不足無法刪除
      Given 使用者不擁有 edit_shop_orders 權限
      When 使用者嘗試刪除物流資訊
      Then 回傳錯誤「權限不足」

  Rule: 批次操作

    Scenario: 批次列印綠界超商託運單（B2C 模式）
      Given 綠界物流已啟用
      And 綠界物流超商類型設為 "B2C"
      When 管理員在訂單列表的批次操作選單中查看
      Then 出現以下列印選項：711、711 Freeze、family、hilife

    Scenario: 批次列印綠界超商託運單（C2C 模式）
      Given 綠界物流已啟用
      And 綠界物流超商類型設為 "C2C"
      When 管理員在訂單列表的批次操作選單中查看
      Then 出現以下列印選項：711、family、hilife、okmart

    Scenario: 批次執行列印託運單
      Given 管理員選取了訂單 #100、#101、#102
      When 管理員選擇「Print ECPay shipping booking note (711)」並執行
      Then 在新視窗開啟 ry_print_ecpay_shipping 頁面

    Scenario: 批次匯出 HCT CSV
      Given 管理員選取了訂單 #100、#101
      When 管理員選擇「wmp_print_hct」並執行
      Then 下載 CSV 檔案，包含欄位：序號、訂單號、收件人姓名、收件人地址、收件人電話、商品數量
      And 貨到付款訂單的「代收貨款」欄位有金額，非貨到付款訂單為空

    Scenario: 批次變更訂單狀態為已出貨
      Given 管理員選取了 3 筆訂單
      When 管理員選擇「變更為已出貨」並執行
      Then 3 筆訂單狀態更新為 wmp-shipped
      And 顯示通知「3 筆訂單已更新為 已出貨」

    Scenario: 批次變更訂單狀態為配送中
      Given 管理員選取了 2 筆訂單
      When 管理員選擇「變更為配送中」並執行
      Then 2 筆訂單狀態更新為 wmp-in-transit
      And 顯示通知「2 筆訂單已更新為 配送中」

  Rule: ATM 訂單自動排程

    Scenario: 綠界 ATM 新訂單建立排程
      Given 訂單 #200 使用 ry_ecpay_atm 付款方式
      And 綠界 ATM 到期天數設為 3 天
      When 訂單 #200 建立
      Then 排程在 3 天後執行 wmp_cron_atm_deadline（取消訂單）
      And 排程在 2 天後執行 wmp_cron_atm_deadline_remind（轉帳提醒）

    Scenario: ATM 訂單到期自動取消 — 未付款
      Given 訂單 #200 狀態為 "pending"
      When wmp_cron_atm_deadline 排程觸發
      Then 訂單 #200 狀態更新為 "cancelled"

    Scenario: ATM 訂單到期自動取消 — 已付款
      Given 訂單 #200 狀態為 "processing"
      When wmp_cron_atm_deadline 排程觸發
      Then 訂單 #200 狀態不變

  Rule: 訂閱管理（WC Subscriptions 整合）

    Scenario: 已取消的訂閱可重新啟用
      Given WC_Subscriptions 外掛已啟用
      And 訂閱 #300 狀態為 "cancelled"
      When 管理員嘗試將訂閱設為 "active"
      Then 操作被允許
      And _schedule_cancelled meta 被移除

    Scenario: 續訂訂單同步發票資訊
      Given WC_Subscriptions 外掛已啟用
      And 訂閱的上層訂單有綠界/ezPay/PayNow 發票 meta
      When 訂閱續訂產生新訂單
      Then 發票相關 meta (_ecpay_invoice_data, _ezpay_invoice_data, _paynow_ei_* 等) 被複製到新訂單

  Rule: 信用卡管理 Metabox

    Scenario: 訂閱頁顯示信用卡儲存資訊
      Given WC_Subscriptions 外掛已啟用
      And 管理員進入訂閱 #300 的編輯頁面
      Then 顯示「信用卡儲存資訊」Metabox
      And 列出客戶的所有 payuni-credit-subscription tokens（卡號末四碼、卡別、到期日、是否為預設）

    Scenario: 設定預設信用卡
      Given 訂閱 #300 的信用卡管理 Metabox 中有多張卡片
      When 管理員點擊某張卡片的「設為預設」按鈕並確認
      Then 該 token 被設為使用者預設
      And 上層訂單的 _payuni_token_id meta 更新
      And 頁面重新載入

    Scenario: 移除信用卡 token
      Given 訂閱 #300 的信用卡管理 Metabox 中有多張卡片
      When 管理員點擊某張卡片的「移除」按鈕並確認
      Then 該 payment token 被刪除
      And 訂閱新增備註記錄移除操作

  Rule: 訂閱發票管理 Metabox

    Scenario: 訂閱頁顯示發票資訊管理
      Given WC_Subscriptions 外掛已啟用
      And 選項 wc_settings_tab_active_paynow_einvoice 為 "yes"
      And 管理員進入訂閱 #300 的編輯頁面
      Then 顯示「訂閱發票資訊(下期開始以下方資訊開立發票)」Metabox
      And 顯示立吉富電子發票的欄位（載具類型、開立類型等）

    Scenario: 儲存訂閱發票資訊
      Given 管理員在訂閱 #300 的發票管理 Metabox 中修改了載具類型
      When 管理員按下更新按鈕
      Then 所有發票欄位值儲存至訂閱的 post meta
