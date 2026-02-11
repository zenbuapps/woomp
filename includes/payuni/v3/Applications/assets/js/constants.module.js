/**
 * PayUni UNi Embed 常數定義
 *
 * @file constants.module.js
 * @description 定義 SDK 相關常數，包含錯誤代碼、事件類型、DOM 元素 ID 等
 */

/**
 * SDK 環境類型
 * @readonly
 * @enum {string}
 */
export const SDK_ENV = Object.freeze({
    /** 正式環境 */
    PROD: 'P',
    /** 測試環境 */
    SANDBOX: 'S'
});

/**
 * iframe 元素 ID 設定
 * @readonly
 * @type {Object}
 */
export const IFRAME_ELEMENTS = Object.freeze({
    /** 信用卡號輸入框 */
    CardNo: 'put_card_no',
    /** 信用卡有效期限輸入框 */
    CardExp: 'put_card_exp',
    /** 信用卡安全碼輸入框 */
    CardCvc: 'put_card_cvc'
});

/**
 * SDK 事件類型
 * @readonly
 * @enum {string}
 */
export const SDK_EVENTS = Object.freeze({
    /** 使用 Token 類型事件（記憶卡號或約定信用卡） */
    USE_TOKEN_TYPE: 'useTokenType',
    /** 表單狀態更新事件 */
    STATUS_UPDATE: 'statusUpdate',
    /** iframe 載入完成事件 */
    LOADED: 'loaded'
});

/**
 * 交易結果狀態
 * @readonly
 * @enum {string}
 */
export const TRADE_STATUS = Object.freeze({
    /** 成功 */
    SUCCESS: 'SUCCESS',
    /** 失敗 */
    FAILURE: 'failure'
});

/**
 * Token 類型（記憶卡號設定）
 * @readonly
 * @enum {number}
 */
export const TOKEN_TYPE = Object.freeze({
    /** 不使用 Token（一次性付款） */
    NONE: 0,
    /** 記憶卡號 */
    REMEMBER_CARD: 1,
    /** 約定信用卡 */
    SUBSCRIPTION_CARD: 2
});

/**
 * WooCommerce 結帳表單選擇器
 * @readonly
 * @type {Object}
 */
export const WC_SELECTORS = Object.freeze({
    /** 結帳表單 */
    CHECKOUT_FORM: 'form.checkout',
    /** 下單按鈕 */
    PLACE_ORDER_BTN: '#place_order',
    /** PayUni 信用卡 V3 付款方式 */
    PAYUNI_CREDIT_V3: '#payment_method_payuni-credit-v3',
    /** 付款方式選項 */
    PAYMENT_METHODS: 'input[type="radio"][name="payment_method"]',
    /** 錯誤訊息區塊 */
    NOTICE_GROUP: '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout'
});

/**
 * 預設 iframe 樣式
 * @readonly
 * @type {Object}
 */
export const DEFAULT_IFRAME_STYLE = Object.freeze({
    color: '#000000',
    errorColor: '#FF0000',
    fontSize: '14px',
    fontWeight: '400',
    lineHeight: '24px'
});
