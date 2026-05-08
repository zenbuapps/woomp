/**
 * ECPay (綠界) 相關測試資料
 * Gateway IDs、Shipping IDs、Invoice 設定
 */

/** 綠界金流閘道 IDs */
export const ECPAY_GATEWAYS = {
  credit: 'ry_ecpay_credit',
  creditInstallment: 'ry_ecpay_credit_installment',
  webatm: 'ry_ecpay_webatm',
  atm: 'ry_ecpay_atm',
  cvs: 'ry_ecpay_cvs',
  barcode: 'ry_ecpay_barcode',
} as const;

/** 綠界獨立分期閘道 IDs */
export const ECPAY_INSTALLMENT_GATEWAYS = {
  '3': 'wmp_ecpay_credit_installment_3',
  '6': 'wmp_ecpay_credit_installment_6',
  '12': 'wmp_ecpay_credit_installment_12',
  '18': 'wmp_ecpay_credit_installment_18',
  '24': 'wmp_ecpay_credit_installment_24',
} as const;

/** 綠界物流方式 IDs */
export const ECPAY_SHIPPING = {
  cvs711: 'ry_ecpay_shipping_cvs_711',
  cvsFami: 'ry_ecpay_shipping_cvs_fami',
  cvsHilife: 'ry_ecpay_shipping_cvs_hilife',
  cvsOkmart: 'ry_ecpay_shipping_cvs_okmart',
  homePost: 'ry_ecpay_shipping_home_post',
  homeTcat: 'ry_ecpay_shipping_home_tcat',
} as const;

/** 綠界電子發票選項 keys */
export const ECPAY_INVOICE_OPTIONS = {
  enabled: 'wc_woomp_enabled_ecpay_invoice',
  debugLog: 'wc_woomp_ecpay_invoice_debug_log',
  orderPrefix: 'wc_woomp_ecpay_invoice_order_prefix',
  issueMode: 'wc_woomp_ecpay_invoice_issue_mode',
  issueAt: 'wc_woomp_ecpay_invoice_issue_at',
  voidMode: 'wc_woomp_ecpay_invoice_void_mode',
  carrierType: 'wc_woomp_ecpay_invoice_carrier_type',
  donateOrg: 'wc_woomp_ecpay_invoice_donate_org',
} as const;

/** 綠界發票載具選項 (前台結帳顯示) */
export const ECPAY_CARRIER_TYPES = {
  paper: { value: '', label: '紙本發票' },
  mobile: { value: '3J0002', label: '手機條碼' },
  naturalPerson: { value: 'CQ0001', label: '自然人憑證' },
  donate: { value: 'donate', label: '捐贈發票' },
  company: { value: 'company', label: '公司發票' },
} as const;

/** 綠界 WP Options keys (RY_WT) */
export const ECPAY_WP_OPTIONS = {
  gatewayEnabled: 'ry_wt_enabled_ecpay_gateway',
  shippingEnabled: 'ry_wt_enabled_ecpay_shipping',
} as const;

/** 綠界結帳頁 Selectors */
export const ECPAY_SELECTORS = {
  // 金流 radio buttons
  creditRadio: 'input#payment_method_ry_ecpay_credit',
  creditLabel: 'label[for="payment_method_ry_ecpay_credit"]',
  installmentRadio: 'input#payment_method_ry_ecpay_credit_installment',
  atmRadio: 'input#payment_method_ry_ecpay_atm',
  cvsRadio: 'input#payment_method_ry_ecpay_cvs',
  barcodeRadio: 'input#payment_method_ry_ecpay_barcode',
  webatmRadio: 'input#payment_method_ry_ecpay_webatm',

  // 發票 metabox (後台訂單頁)
  invoiceMetabox: '#woomp-ecpay-invoice',
  invoiceIssueBtn: '.ecpay-invoice-issue',
  invoiceVoidBtn: '.ecpay-invoice-void',
  invoiceNumber: '.ecpay-invoice-number',
  invoiceStatus: '.ecpay-invoice-status',

  // 載具選擇
  carrierTypeSelect: 'select.ecpay-carrier-type',
  carrierInfoInput: 'input.ecpay-carrier-info',
  loveCodeInput: 'input.ecpay-love-code',
  companyIdInput: 'input.ecpay-company-id',
};
