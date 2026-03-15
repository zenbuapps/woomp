/**
 * PayNow (立吉富) 相關測試資料
 * Gateway IDs、Shipping IDs、Invoice 設定
 */

/** 立吉富金流閘道 IDs */
export const PAYNOW_GATEWAYS = {
  credit: 'paynow-credit',
  barcode: 'paynow-barcode',
  webatm: 'paynow-webatm',
  ibon: 'paynow-ibon',
  virtualAccount: 'paynow-virtual-account',
} as const;

/** 立吉富物流方式 IDs */
export const PAYNOW_SHIPPING = {
  cvs711: 'paynow_shipping_c2c_711',
  cvsFamily: 'paynow_shipping_c2c_family',
  cvsHilife: 'paynow_shipping_c2c_hilife',
  hdTcat: 'paynow_shipping_hd_tcat',
} as const;

/** 立吉富 WP Options keys */
export const PAYNOW_WP_OPTIONS = {
  gatewayEnabled: 'wc_woomp_setting_paynow_gateway',
  shippingEnabled: 'wc_woomp_setting_paynow_shipping',
  invoiceEnabled: 'wc_settings_tab_active_paynow_einvoice',
} as const;

/** 立吉富電子發票載具選項 */
export const PAYNOW_CARRIER_TYPES = {
  cloud: { value: 'cloud', label: '雲端發票' },
  mobile: { value: 'mobile', label: '手機條碼' },
  naturalPerson: { value: 'natural_person', label: '自然人憑證' },
  easycard: { value: 'easycard', label: '悠遊卡' },
  donate: { value: 'donate', label: '捐贈發票' },
  company: { value: 'company', label: '公司發票' },
} as const;

/** 立吉富結帳頁 Selectors */
export const PAYNOW_SELECTORS = {
  // 金流 radio buttons
  creditRadio: 'input#payment_method_paynow-credit',
  creditLabel: 'label[for="payment_method_paynow-credit"]',
  barcodeRadio: 'input#payment_method_paynow-barcode',
  webatmRadio: 'input#payment_method_paynow-webatm',
  ibonRadio: 'input#payment_method_paynow-ibon',
  virtualAccountRadio: 'input#payment_method_paynow-virtual-account',

  // 發票 metabox (後台)
  invoiceMetabox: '#paynow-einvoice',
  invoiceIssueBtn: '.paynow-invoice-issue',
  invoiceVoidBtn: '.paynow-invoice-void',
  invoiceNumber: '.paynow-invoice-number',

  // 載具選擇
  carrierTypeSelect: 'select.paynow-carrier-type',
  carrierInfoInput: 'input.paynow-carrier-info',
};

/** 其他金流閘道 IDs */
export const OTHER_GATEWAYS = {
  linepay: 'linepay',
  pchomepay: 'pchomepay',
} as const;

/** 藍新金流閘道 IDs */
export const NEWEBPAY_GATEWAYS = {
  credit: 'ry_newebpay_credit',
  creditInstallment: 'ry_newebpay_credit_installment',
  webatm: 'ry_newebpay_webatm',
  atm: 'ry_newebpay_atm',
  cvs: 'ry_newebpay_cvs',
  barcode: 'ry_newebpay_barcode',
} as const;

/** 藍新獨立分期閘道 IDs */
export const NEWEBPAY_INSTALLMENT_GATEWAYS = {
  '3': 'wmp_newebpay_credit_installment_3',
  '6': 'wmp_newebpay_credit_installment_6',
  '12': 'wmp_newebpay_credit_installment_12',
  '18': 'wmp_newebpay_credit_installment_18',
  '24': 'wmp_newebpay_credit_installment_24',
  '30': 'wmp_newebpay_credit_installment_30',
} as const;

/** 藍新物流方式 IDs */
export const NEWEBPAY_SHIPPING = {
  cvs711: 'ry_newebpay_shipping_cvs_711',
  cvsFami: 'ry_newebpay_shipping_cvs_fami',
  cvsHilife: 'ry_newebpay_shipping_cvs_hilife',
  cvsOkmart: 'ry_newebpay_shipping_cvs_okmart',
  homePost: 'ry_newebpay_shipping_home_post',
  homeTcat: 'ry_newebpay_shipping_home_tcat',
} as const;

/** 藍新 WP Options keys */
export const NEWEBPAY_WP_OPTIONS = {
  gatewayEnabled: 'ry_wt_enabled_newebpay_gateway',
  shippingEnabled: 'ry_wt_enabled_newebpay_shipping',
} as const;

/** SmilePay (速買配) 閘道 IDs */
export const SMILEPAY_GATEWAYS = {
  credit: 'ry_smilepay_credit',
  atm: 'ry_smilepay_atm',
  barcode: 'ry_smilepay_barcode',
  webatm: 'ry_smilepay_webatm',
  cvs711: 'ry_smilepay_cvs_711',
  cvsFami: 'ry_smilepay_cvs_fami',
} as const;

/** SmilePay WP Options keys */
export const SMILEPAY_WP_OPTIONS = {
  gatewayEnabled: 'ry_wt_enabled_smilepay_gateway',
  shippingEnabled: 'ry_wt_enabled_smilepay_shipping',
} as const;
