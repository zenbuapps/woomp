@ignore @command
Feature: 選擇發票載具

  消費者在結帳頁選擇發票載具類型並填寫對應資訊。
  載具資訊暫存至訂單 meta，隨交易參數一併送出至 PayUni。

  Background:
    Given 系統中有以下訂單：
      | orderId | billing_name | billing_email  |
      | 1001    | Alice Chen   | alice@test.com |

  Rule: 前置（參數）- 載具類型必須為有效值

    Example: 載具類型為無效值時操作失敗
      When 消費者選擇發票載具，類型為 "INVALID"
      Then 操作失敗，錯誤為「無效的載具類型」

  Rule: 前置（參數）- 手機條碼格式必須為斜線開頭加 7 碼英數字

    Example: 手機條碼格式正確時操作成功
      When 消費者選擇發票載具，類型為 "3J0002"，載具資訊為 "/ABC1234"，買方名稱為 "Alice Chen"
      Then 操作成功

    Example: 手機條碼格式錯誤時操作失敗
      When 消費者選擇發票載具，類型為 "3J0002"，載具資訊為 "ABC1234"
      Then 操作失敗，錯誤為「手機條碼格式錯誤，必須為 /XXXXXXX」

  Rule: 前置（參數）- 自然人憑證長度不可超過 16 碼

    Example: 自然人憑證號碼過長時操作失敗
      When 消費者選擇發票載具，類型為 "CQ0001"，載具資訊為 "12345678901234567"
      Then 操作失敗，錯誤為「自然人憑證號碼長度不可超過 16 碼」

  Rule: 前置（參數）- 捐贈碼長度不可超過 7 碼

    Example: 捐贈碼長度正確時操作成功
      When 消費者選擇發票載具，類型為 "Donate"，載具資訊為 "168"
      Then 操作成功

  Rule: 前置（參數）- 公司統編必須為 8 碼

    Example: 統編長度正確時操作成功
      When 消費者選擇發票載具，類型為 "Company"，載具資訊為 "12345678"，買方名稱為 "Test Corp"
      Then 操作成功

    Example: 統編長度不為 8 碼時操作失敗
      When 消費者選擇發票載具，類型為 "Company"，載具資訊為 "1234567"
      Then 操作失敗，錯誤為「公司統一編號必須為 8 碼」

  Rule: 前置（參數）- 非捐贈類型必須提供買方名稱（未填時自動使用帳單姓名）

    Example: 未填買方名稱時自動使用帳單姓名
      Given 訂單 1001 的帳單姓名為 "Alice Chen"
      When 消費者選擇發票載具，類型為 "3J0002"，載具資訊為 "/ABC1234"，買方名稱為 ""
      Then 操作成功
      And 買方名稱應為 "Alice Chen"

  Rule: 前置（參數）- 會員載具不需額外載具資訊

    Example: 會員載具不需填寫載具資訊
      When 消費者選擇發票載具，類型為 "amego"，買方名稱為 "Alice Chen"
      Then 操作成功
      And 載具資訊應為空字串

  Rule: 後置（狀態）- 載具資訊應暫存至訂單 meta

    Example: 選擇手機條碼後資訊正確暫存
      When 消費者選擇發票載具，類型為 "3J0002"，載具資訊為 "/ABC1234"，買方名稱為 "Alice Chen"
      Then 操作成功
      And 訂單 1001 的暫存載具資訊應為：
        | carrier_type | carrier_info | inv_buyer_name |
        | 3J0002       | /ABC1234     | Alice Chen     |
