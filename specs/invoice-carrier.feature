Feature: 發票載具選擇與驗證
  As a 顧客
  I want 在結帳時選擇發票類型與載具
  So that 發票能依照我的需求正確開立

  Background:
    Given 至少一個電子發票模組已啟用

  Rule: 發票類型選擇（所有服務商共通）

    Scenario: 結帳頁面顯示三種發票類型
      Given 顧客進入結帳頁面
      And 購物車總額大於 0
      When 頁面載入完成
      Then 發票類型下拉選單顯示「個人」「公司」「捐贈」三個選項

    Scenario: 零元訂單不顯示發票欄位
      Given 購物車總額為 0
      And 商品非訂閱類型
      When 顧客進入結帳頁面
      Then 不顯示發票相關欄位

    Scenario: 訂閱商品零元訂單仍顯示發票欄位
      Given 購物車總額為 0
      And 商品為訂閱類型（product_type 包含 subscription）
      When 顧客進入結帳頁面
      Then 發票相關欄位仍然顯示

  Rule: 個人發票載具選擇

    Scenario: 選擇個人發票時顯示載具類型
      Given 顧客在結帳頁面
      When 選擇發票類型為「個人」
      Then 顯示載具類型下拉選單
      And 隱藏統一編號欄位
      And 隱藏捐贈碼欄位

    Scenario: 綠界的載具類型選項
      Given 使用綠界電子發票模組
      When 選擇發票類型為「個人」
      Then 載具類型包含「雲端發票」「手機條碼」「自然人憑證」「紙本發票」
      # 選項來源：wc_woomp_ecpay_invoice_carrier_type

    Scenario: EZPAY 的載具類型選項
      Given 使用 EZPAY 電子發票模組
      When 選擇發票類型為「個人」
      Then 載具類型包含「手機條碼」「自然人憑證」「ezPay 電子發票載具」「雲端電子發票載具」

    Scenario: 立吉富的載具類型選項
      Given 使用立吉富電子發票模組
      When 選擇發票類型為「個人」
      Then 載具類型可包含「雲端載具」「手機條碼」「自然人憑證」「悠遊卡」
      # 各選項可從設定個別啟用/停用

    Scenario: PayUni v3 的載具類型選項
      Given 使用 PayUni v3 金流
      When 付款頁面載入
      Then 載具類型可選「手機條碼(3J0002)」「自然人憑證(CQ0001)」「會員載具(amego)」「捐贈碼(Donate)」「公司發票(Company)」

  Rule: 載具編號欄位顯示邏輯

    Scenario: 選擇手機條碼或自然人憑證時顯示載具編號
      When 選擇載具類型為「手機條碼」
      Then 顯示載具編號輸入欄位

    Scenario: 選擇手機條碼或自然人憑證時顯示載具編號（自然人憑證）
      When 選擇載具類型為「自然人憑證」
      Then 顯示載具編號輸入欄位

    Scenario: 選擇雲端發票時隱藏載具編號
      When 選擇載具類型為「雲端發票」
      Then 隱藏載具編號輸入欄位

    Scenario: 選擇紙本發票時隱藏載具編號（綠界）
      Given 使用綠界電子發票
      When 選擇載具類型為「紙本發票」
      Then 隱藏載具編號輸入欄位

  Rule: 公司發票欄位顯示

    Scenario: 選擇公司發票時顯示統編與公司名稱
      When 選擇發票類型為「公司」
      Then 顯示統一編號輸入欄位
      And 顯示公司名稱輸入欄位
      And 隱藏載具類型欄位
      And 隱藏捐贈碼欄位

  Rule: 捐贈發票欄位顯示

    Scenario: 選擇捐贈發票時顯示捐贈碼
      When 選擇發票類型為「捐贈」
      Then 顯示捐贈碼下拉選單（綠界/EZPAY）或選擇欄位（立吉富）
      And 隱藏載具類型欄位
      And 隱藏統一編號欄位

    Scenario: 捐贈機構列表預設值
      Given 未設定捐贈機構清單
      When 顯示捐贈碼選項
      Then 預設顯示「伊甸社會福利基金會（25885）」

    Scenario: 自訂捐贈機構列表
      Given 設定捐贈機構為「919|創世基金會」
      When 顯示捐贈碼選項
      Then 選項包含「創世基金會（919）」

  Rule: 手機條碼驗證

    Scenario: 有效的手機條碼格式
      Given 選擇載具類型為「手機條碼」
      When 輸入載具編號「/ABC+123」
      Then 驗證通過
      # 正則：/^\/[A-Za-z0-9+-\.]{7}$/

    Scenario: 無效的手機條碼 — 缺少斜線開頭
      Given 選擇載具類型為「手機條碼」
      When 輸入載具編號「ABC12345」
      Then 驗證失敗
      And 顯示錯誤「請輸入第 1 碼為「/」，後 7 碼為大寫英文、數字、「+」、「-」或「.」」

    Scenario: 無效的手機條碼 — 長度不足
      Given 選擇載具類型為「手機條碼」
      When 輸入載具編號「/ABC」
      Then 驗證失敗

  Rule: 自然人憑證驗證

    Scenario: 有效的自然人憑證格式
      Given 選擇載具類型為「自然人憑證」
      When 輸入載具編號「AB12345678901234」
      Then 驗證通過
      # 正則：/^[A-Z]{2}\d{14}$/

    Scenario: 無效的自然人憑證 — 小寫英文
      Given 選擇載具類型為「自然人憑證」
      When 輸入載具編號「ab12345678901234」
      Then 驗證失敗
      And 顯示錯誤「請輸入前 2 位大寫英文與 14 位數字自然人憑證號碼」

  Rule: 統一編號驗證

    Scenario: 有效的統一編號
      Given 選擇發票類型為「公司」
      When 輸入統一編號「12345678」
      Then 驗證通過
      # 正則：/^\d{8}$/

    Scenario: 無效的統一編號 — 非 8 位數字
      Given 選擇發票類型為「公司」
      When 輸入統一編號「1234567」
      Then 驗證失敗
      And 顯示錯誤「請輸入 8 位數字組成統一編號」

  Rule: 公司名稱驗證

    Scenario: 公司名稱為空
      Given 選擇發票類型為「公司」
      When 公司名稱欄位為空
      Then 驗證失敗
      And 顯示錯誤「公司名稱為必填欄位」

  Rule: 捐贈碼驗證

    Scenario: 有效的捐贈碼
      Given 選擇發票類型為「捐贈」
      When 輸入捐贈碼「25885」
      Then 驗證通過
      # 正則：/^\d{3,7}$/

    Scenario: 無效的捐贈碼 — 位數不足
      Given 選擇發票類型為「捐贈」
      When 輸入捐贈碼「12」
      Then 驗證失敗
      And 顯示錯誤「請輸入 3~7 位數字」

  Rule: 立吉富載具驗證

    Scenario: 立吉富個人發票選擇非雲端載具但未填編號
      Given 使用立吉富電子發票
      And 選擇索取方式為「個人」
      And 載具類型不為空（非雲端）
      When 載具編號為空
      Then 驗證失敗
      And 顯示錯誤「Please input the carrier number」

    Scenario: 立吉富公司發票未填公司名稱或統編
      Given 使用立吉富電子發票
      And 選擇索取方式為「公司」
      When 公司名稱或統一編號為空
      Then 驗證失敗
      And 顯示錯誤「Please input the company name and Unified Business NO」

  Rule: EZPAY 自動帶入公司名稱

    Scenario: 輸入統一編號後自動查詢公司名稱
      Given 使用 EZPAY 電子發票
      And 選擇發票類型為「公司」
      When 顧客在統一編號欄位輸入「12345678」並移出焦點
      Then 前端呼叫 https://company.g0v.ronny.tw/api/show/12345678
      And 若查詢到公司名稱，自動填入公司名稱欄位

  Rule: 後台訂單 Metabox 發票資料編輯

    Scenario: 綠界 Metabox 顯示發票資訊並可編輯
      Given 管理員在訂單編輯頁
      And 綠界電子發票已啟用
      When 查看「綠界電子發票(好用版)」metabox
      Then 顯示發票類型下拉（個人/公司/捐贈）
      And 根據類型顯示對應子欄位
      And 提供「開立發票」或「作廢發票」按鈕
      And 提供「更新發票資料」按鈕（僅在欄位被修改時啟用）

    Scenario: EZPAY Metabox 已開立發票時欄位唯讀
      Given 管理員在訂單編輯頁
      And 訂單已開立 EZPAY 發票
      When 查看「藍新 ezPay 電子發票」metabox
      Then 所有欄位顯示為唯讀（灰色背景，pointer-events:none）
      And 僅顯示「作廢發票」按鈕

  Rule: 訂閱訂單發票資料更新

    Scenario: 訂閱訂單的 Metabox 僅允許更新資料
      Given 管理員在訂閱編輯頁（shop_subscription）
      When 查看發票 metabox
      Then 顯示「更新訂閱發票資料」按鈕
      And 不顯示「開立發票」或「作廢發票」按鈕

  Rule: 我的帳號頁面顯示發票號碼

    Scenario: 綠界發票號碼顯示在訂單列表
      Given 顧客訂單已開立綠界發票
      When 顧客查看「我的帳號 > 訂單」頁面
      Then 訂單列表的「Invoice number」欄位顯示發票號碼

    Scenario: EZPAY 發票號碼顯示在訂單列表
      Given 顧客訂單已開立 EZPAY 發票
      When 顧客查看「我的帳號 > 訂單」頁面
      Then 訂單列表的「Invoice number」欄位顯示發票號碼

  Rule: PayUni v3 載具整合

    Scenario: PayUni v3 會員載具不需填載具內容
      Given 使用 PayUni v3 金流
      When 選擇載具類型為「會員載具(amego)」
      Then 不需填寫 CarrierInfo
      And InvBuyerName 必填

    Scenario: PayUni v3 手機條碼需填載具內容
      Given 使用 PayUni v3 金流
      When 選擇載具類型為「手機條碼(3J0002)」
      Then 需填寫 CarrierInfo（手機條碼）
      And InvBuyerName 必填

    Scenario: PayUni v3 捐贈不需填買方名稱
      Given 使用 PayUni v3 金流
      When 選擇載具類型為「捐贈碼(Donate)」
      Then 需填寫 CarrierInfo（捐贈碼）
      And 隱藏 InvBuyerName 欄位
