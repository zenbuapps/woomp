/**
 * WooCommerce / Woomp 後台 URL 常數
 * 所有路徑均為 HPOS 相容格式
 */

export const ADMIN_URLS = {
  // WooCommerce 設定主頁
  wcSettings: '/wp-admin/admin.php?page=wc-settings',

  // 好用版擴充設定頁籤
  woompSettings: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting',
  woompGateway: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway',
  woompShipping: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping',
  woompInvoice: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_invoice',

  // 金流設定子區段
  gatewayPayuni: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=payuni',
  gatewayEcpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=ecpay',
  gatewayNewebpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=newebpay',
  gatewaySmilepay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=smilepay',
  gatewayPaynow: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=paynow',
  gatewayLinepay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=linepay',
  gatewayPchomepay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_gateway&section=pchomepay',

  // 物流設定子區段
  shippingEcpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping&section=ecpay',
  shippingNewebpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping&section=newebpay',
  shippingSmilepay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping&section=smilepay',
  shippingPaynow: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping&section=paynow',
  shippingPayuni: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_shipping&section=payuni',

  // 電子發票設定子區段
  invoiceEcpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_invoice&section=ecpay',
  invoiceEzpay: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_invoice&section=ezpay',
  invoicePaynow: '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting_invoice&section=paynow',

  // 訂單管理 (HPOS)
  orderList: '/wp-admin/admin.php?page=wc-orders',
  orderEdit: (id: string | number) => `/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}`,

  // WC REST API Key 管理
  restApiKeys: '/wp-admin/admin.php?page=wc-settings&tab=advanced&section=keys',
  restApiAddKey: '/wp-admin/admin.php?page=wc-settings&tab=advanced&section=keys&create-key=1',

  // 外掛列表
  plugins: '/wp-admin/plugins.php',

  // 商品管理
  products: '/wp-admin/edit.php?post_type=product',
  productEdit: (id: string | number) => `/wp-admin/post.php?post=${id}&action=edit`,

  // WooCommerce 選單
  woocommerce: '/wp-admin/admin.php?page=wc-admin',

  // 個別金流設定頁（WC Payments 標籤）
  paymentGateways: '/wp-admin/admin.php?page=wc-settings&tab=checkout',
  paymentGatewaySettings: (gatewayId: string) =>
    `/wp-admin/admin.php?page=wc-settings&tab=checkout&section=${gatewayId}`,
};
