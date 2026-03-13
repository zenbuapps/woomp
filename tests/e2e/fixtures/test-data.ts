/**
 * 集中管理所有測試資料
 * Selectors 已透過 browser_evaluate 在實際結帳頁面驗證
 */

export const CREDENTIALS = {
  username: process.env.WP_USERNAME || 'test',
  password: process.env.WP_PASSWORD || 'test',
};

export const PRODUCT = {
  /** ?add-to-cart=81 → T-Shirt with Logo, NT$10 */
  addToCartUrl: '/?add-to-cart=81',
  name: 'T-Shirt with Logo',
  price: 10,
  id: 81,
};

/** PayUni Sandbox 測試卡號 */
export const CARDS = {
  visa: { number: '4147631000000001', expiry: '1228', cvc: '123' },
  jcb: { number: '3560511000000001', expiry: '1228', cvc: '123' },
  visa3d: { number: '4147631000000002', expiry: '1228', cvc: '123' },
  jcb3d: { number: '3560511000000002', expiry: '1228', cvc: '123' },
  visaInstallment: { number: '4147632000000001', expiry: '1228', cvc: '123' },
  jcbInstallment: { number: '3560562000000001', expiry: '1228', cvc: '123' },
};

export const CARRIERS = {
  paper: { type: '', value: '', label: '不使用載具（紙本發票）' },
  mobile: { type: '3J0002', value: '/ABC1234', label: '手機條碼' },
  naturalPerson: { type: 'CQ0001', value: 'AB12345678901234', label: '自然人憑證' },
  amego: { type: 'amego', value: '', label: '會員載具' },
  donate: { type: 'Donate', value: '168', label: '捐贈發票' },
  company: { type: 'Company', value: '12345678', buyerName: '測試公司', label: '公司發票（統編）' },
};

export const BILLING = {
  firstName: '測試',
  lastName: '用戶',
  phone: '0912345678',
  email: 'test@example.com',
  address1: '測試路123號',
  city: '中正區',
  state: '台北市',
  postcode: '100',
  country: 'TW',
};

/**
 * CSS Selectors — 全部在真實結帳頁透過 browser_evaluate 驗證
 * 此為 WooCommerce Classic Checkout（非 Block Checkout）
 */
export const SELECTORS = {
  // ── Payment method radio ──
  payuniRadio: 'input#payment_method_payuni-credit-v3',
  payuniLabel: 'label[for="payment_method_payuni-credit-v3"]',
  payuniPaymentBox: '.payment_box.payment_method_payuni-credit-v3',

  // ── SDK iframes（by ID, 最可靠）──
  cardNoIframe: '#ifr_cardno',
  cardExpIframe: '#ifr_cardexp',
  cardCvcIframe: '#ifr_cardcvc',
  // parent containers
  cardNoContainer: '#put_card_no',
  cardExpContainer: '#put_card_exp',
  cardCvcContainer: '#put_card_cvc',

  // ── Installment ──
  installmentSelect: 'select#payuni_installment',
  installmentContainer: '.payuni-installment-options',

  // ── Carrier / Invoice ──
  carrierTypeSelect: 'select#payuni_carrier_type',
  carrierInfoMobile: '#payuni_carrier_info_3J0002',
  carrierInfoNatural: '#payuni_carrier_info_CQ0001',
  carrierInfoDonate: '#payuni_carrier_info_Donate',
  carrierInfoCompany: '#payuni_carrier_info_Company',
  carrierInfoHidden: '#payuni_carrier_info',
  carrierInfoRow: (type: string) => `#payuni_carrier_info_row_${type}`,
  carrierContainer: '.payuni-invoice-carrier-options',
  buyerName: '#payuni_inv_buyer_name',

  // ── Saved card / Token ──
  saveCardFlag: '#payuni_save_card',
  useSavedToken: '#payuni_use_saved_token',
  savedTokenId: '#payuni_saved_token_id',
  usedTokenId: '#payuni_used_token_id',
  newCardForm: '.payuni-credit-v3-form.payuni-new-card-form',
  savedTokenRadio: '.payuni-saved-token-radio',
  newCardRadio: '.payuni-new-card-radio',

  // ── Billing fields（Classic Checkout standard IDs）──
  billingFirstName: '#billing_first_name',
  billingLastName: '#billing_last_name',
  billingAddress1: '#billing_address_1',
  billingAddress2: '#billing_address_2',
  billingCity: '#billing_city',
  billingState: '#billing_state',
  billingPostcode: '#billing_postcode',
  billingPhone: '#billing_phone',
  billingEmail: '#billing_email',
  billingCountry: '#billing_country',

  // ── Checkout actions ──
  placeOrderBtn: '#place_order',
  checkoutForm: 'form.woocommerce-checkout, form[name="checkout"]',
  errorNotice: '.woocommerce-NoticeGroup-checkout, .woocommerce-error',
  noticesWrapper: '.woocommerce-notices-wrapper',

  // ── Order success ──
  orderReceivedHeading: '.woocommerce-thankyou-order-received, .entry-title, h1:has-text("已收到訂單")',
};

/** 實際在結帳頁出現的分期選項（value 值）*/
export const INSTALLMENT_OPTIONS = [1, 3, 6, 12] as const;

export const URLS = {
  shop: '/shop/',
  checkout: '/checkout/',
  cart: '/cart/',
  myAccount: '/my-account/',
  myAccountPayment: '/my-account/payment-methods/',
  adminOrders: '/wp-admin/edit.php?post_type=shop_order',
  wpLogin: '/wp-login.php',
};
