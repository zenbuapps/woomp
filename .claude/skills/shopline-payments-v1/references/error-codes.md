# SHOPLINE Payments v1 -- Error Codes

> Source: https://docs.shoplinepayments.com/appendix/errorCode/

Error responses return HTTP 400/429/500 with `{ "code": "...", "msg": "..." }`.

## Table of Contents

1. [General Error Codes](#general-error-codes)
2. [Order/Create Error Codes](#ordercreate-error-codes)
3. [Payment Error Codes](#payment-error-codes)
4. [Refund Error Codes](#refund-error-codes)
5. [Cancel Error Codes](#cancel-error-codes)
6. [Capture Error Codes](#capture-error-codes)
7. [Card Binding Error Codes](#card-binding-error-codes)
8. [Recurring Payment Error Codes](#recurring-payment-error-codes)
9. [Connect Error Codes](#connect-error-codes)
10. [SDK Error Codes](#sdk-error-codes)

---

## General Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1005` | Validation error | Check error |
| `1006` | Record already exists | Record already exists |
| `1008` | Status error | Status error |
| `1016` | No transaction record found | No transaction record. |
| `1018` | Payment failed / cancelled / expired / unknown | Business error |
| `1901` | System connection failed | System connection failed |
| `1902` | Internal format error | System internal format error |
| `1904` | Too many requests | Requests are too frequent |
| `1997` | Database error | Database error |
| `1998` | System configuration error | System configuration error |
| `1999` | System exception | Server error |
| `2001` | APPID does not exist | APPID does not exist |
| `2002` | Signature error | Signature error |
| `2003` | Request URL error | Request URL error |
| `2004` | Internal system error | Internal System Error |
| `2005` | Access denied | Access denied |
| `2006` | Bad request | Bad request |
| `2007` | Not found | Not found |
| `2008` | Service unavailable | Service cannot be accessed |
| `2009` | Token expired | Token expired |
| `2010` | Token tampered | Token tampered |
| `2011` | Instrument status change forbidden | InstrumentStatus forbidden change |
| `2013` | Merchant not connected to platform | Merchants are not connected to the platform |

## Order/Create Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1001` | Duplicate order | Order exsit |
| `1003` | Missing parameter | Param miss |
| `1004` | Invalid parameter | Param error |
| `1025` | Amount exceeds max/min limit | Create amount error |
| `4001` | Channel connection failed | Channel connection failed |
| `4002` | Channel error | Channel error |
| `4003` | Channel response timeout | Channel response timeout |
| `4101` | Amount not within min/max range | Wrong amount |
| `4102` | Order abnormality | Payment declined due to order abnormalities. |
| `4103` | Unknown reason | The payment has been declined for unspecificed reasons. |
| `4104` | Merchant account abnormal | Payment declined (issues with merchant account) |
| `4105` | Customer account abnormal | Payment declined (issues with customer account) |
| `4106` | IP not whitelisted | The payment(s) failed due to system issues. |
| `4107` | Currency not supported by channel | The payment has been declined for unspecificed reasons. |
| `4108` | Failed to pull up payment form | Failed to pull up the form |
| `4109` | Store URL not configured | Invalid store url |

## Payment Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1012` | Merchant payment permission disabled | Merchant payment permission is disabled |
| `3000` | SLP risk: user behavior abnormal | The payment was declined due to payer/card irregularities. |
| `3001` | SLP risk: card abnormal | The payment was declined due to payer/card irregularities. |
| `3002` | SLP risk: recipient info abnormal | The payment was declined due to payer/card irregularities. |
| `3003` | SLP risk: high-risk transaction | The payment was declined due to payer/card irregularities. |
| `3004` | SLP risk: transaction info abnormal | The payment was declined due to payer/card irregularities. |
| `4350` | Channel/bank risk rejection | The payment was rejected by the issuing bank. |
| `4400` | Channel data transmission/system error | The payment failed due to the transaction timed out. |
| `4401` | Channel transaction expired | The payment period has expired. |
| `4402` | ATM: actual amount mismatch | The payment failed due to amount mismatch |
| `4403` | System busy | System Busy |
| `4404` | Token amount mismatch | Payment token amount not match |
| `4405` | Invalid payment token | Payment token invailid |
| `4406` | Invalid public key | Payment public key invailid |
| `4407` | Invalid private key | Payment private key invailid |
| `4408` | Invalid payment signature | Payment signature invailid |
| `4409` | Payment token expired | Payment token expires |
| `4410` | Duplicate payment | Duplicate payment. |
| `4411` | Channel authentication failed | Payment failed due to technical issue |
| `4412` | Channel not found | Payment failed due to technical issue |
| `4450` | 3DS verification timeout | The payer did not complete 3D Secure authentication. |
| `4451` | Issuing bank declined | The payment was rejected by the issuing bank. |
| `4452` | 3DS verification failed | The payer's attempt at 3D Secure authentication failed. |
| `4453` | CVV2 verification failed | The CVC number is incorrect. |
| `4454` | Insufficient card balance | The card has insufficient funds. |
| `4455` | Invalid card number | The card number used is invalid. |
| `4456` | Channel system error | The payment failed due to system issues. |
| `4457` | Issuing bank: high-risk | The payment was declined by the issuing bank due to abnormalities. |
| `4458` | Payment still processing | The payment is still being processed. |
| `4459` | Card expired | The card used has expired. |
| `4460` | PIN attempts exceeded | The payer exceeded the maximum number of PIN attempts. |
| `4461` | Amount exceeds card limit | The payer exceeded their available balance/credit limit. |
| `4462` | Invalid PIN | The PIN entered is invalid. |
| `4463` | Stolen/lost card | The payment was declined after being flagged by the issuing bank. |
| `4464` | Card frozen | The selected card application is blocked. |
| `4465` | Online PIN required | Card/E-Wallet requires online pin |
| `4466` | Restricted card | Restricted Card or Invalid card in this country |
| `4467` | Transaction count exceeded | Withdrawals permitted exceeded |
| `4468` | Invalid/expired QR code | Invalid QR Code, or QR Code has expired. |
| `4550` | Customer cancelled | Customer cancellation |
| `4551` | Customer dispute/chargeback | Customer dispute |
| `4552` | Customer account error | Customer account error |
| `4600` | Other unknown exception | The payment has been declined for unspecificed reasons. |

## Refund Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1010` | Merchant refund permission disabled | Merchant refund permission is disabled |
| `1013` | Duplicate refund request | The refund request is existing. |
| `1014` | Full amount already refunded | Exceeded the allowable refund amount. |
| `1015` | Merchant not KYC verified | Refund decline (issues with customer account) |
| `1020` | Refund window expired (180 days) | The allowable refund period has expired. |
| `1021` | Transaction not found or abnormal status | Order does not exist or is in exception status. |
| `1022` | Insufficient merchant balance | Merchant acc does not have enough balance. |
| `1023` | Unknown refund failure | The refund request can not be processed. |
| `1202` | Online refund API not supported | Refund is not support currently |
| `4502` | Transaction not found or abnormal | Order does not exist or is in exception status. |
| `4700` | Unknown refund failure | The refund failed because of unknown reasons. |
| `4701` | Refund amount exceeds refundable | Refund request amount exceeds order refundable amount |
| `4702` | Insufficient merchant balance | Insufficient merchant account balance |
| `4703` | Refund time limit exceeded | Payment order has exceeded the refundable time limit |
| `4704` | Unknown reason | The refund failed because of unknown reasons. |
| `4705` | Invalid amount | Refund failed because of amount not valid |
| `4706` | Previous refund still processing | Previously refund is still processing |
| `4707` | Partial refund not supported | Partial refund doesn't support. |

## Cancel Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `6001` | Transaction processing, cannot cancel | Transaction is processing, can not cancel. |
| `6002` | Already captured, cannot cancel | Can not Cancel the Captured transaction |
| `6003` | Already cancelled | Can not Cancel the Cancelled transaction |
| `6400` | Abnormal transaction status | Transaction status is abnormal and cannot be cancelled |
| `6401` | Channel system error | Can not Cancel for system error |

## Capture Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `7001` | Capture amount exceeds capturable | Capture amount bigger than capturable |
| `7002` | Abnormal transaction status | Transaction status abnormal, cannot capture |
| `7400` | Abnormal transaction status | Transaction status abnormal, cannot capture |
| `7401` | Channel system error | Can not Capture for system error |
| `7402` | Capture amount exceeds capturable | Capture amount bigger than capturable |
| `7403` | Authorization amount issue | Capture fail for bank decline |

## Card Binding Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1200` | Card binding not supported | Card binding is not support currently. |
| `1201` | Card cloning in progress | Card is cloning |
| `1203` | Card verification failed | Card verification is failed |
| `4800` | Unknown binding failure | Binding failed due to unknown reason. |
| `4801` | User rejected authorization | The merchant rejected the binding. |
| `4802` | Channel parameter error | Bind parameter error. |

## Recurring Payment Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `4900` | 3DS required but customer absent | Need 3DS |
| `4901` | CVS required but customer absent | Need cvs |
| `4902` | Other recurring payment error | Saved card payment other erorr |

## Connect Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `URL_NOT_FOUND` | URL does not exist | The url does not exist |
| `ACCESS_DENIED` | Invalid apiKey/clientKey | Access Denied |
| `MERCHANT_NOT_EXISTS` | Merchant ID not found | Merchant information does not exist |
| `UNAUTHORIZED_CLIENT` | Client ID error | Client not authorized |
| `SERVER_ERROR` | System error | System exception |
| `KEY_INCORRECT` | Incorrect apiKey format | apiKey or clientKey format is incorrect |
| `INVALID_SCOPE` | Invalid authorization scope | Requested range invalid |

## SDK Error Codes

| Code | Description (ZH) | Message (EN) |
|---|---|---|
| `1009` | Merchant collection permission disabled | Receive forbidden |
| `4200` | Payment method not activated | Payment method not activated |
| `4201` | No merchant permission | Merchant account invalid |
| `4202` | Currency not supported | Currency not support |
| `4203` | Merchant account abnormal | Merchant payment permission is disabled |
| `4204` | Payment method not supported | This payment method is not currently supported |