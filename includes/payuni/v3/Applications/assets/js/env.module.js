/**
 * PayUni UNi Embed 環境變數模組
 *
 * @file env.module.js
 * @description 定義從後端傳送的環境參數及全域依賴
 */

/**
 * PayUni 付款參數類型定義
 * @typedef {Object} PayUniParams
 * @property {string} ENV - 環境設定 (P: 正式環境, S: 測試環境)
 * @property {string} SDK_TOKEN - SDK Token（由後端 API 取得）
 * @property {string} INIT_ERROR_DETAIL - 後端初始化失敗原因（僅 manage_woocommerce 權限者會收到，供管理員除錯）
 * @property {boolean} USE_INST - 是否啟用分期付款功能
 * @property {boolean} ENABLE_3D_AUTH - 是否啟用 3D 驗證
 * @property {boolean} ENABLE_TOKENIZATION - 是否啟用記憶卡號功能
 * @property {string} API_URL - PayUni API 端點網址
 * @property {Object<string, string>} ERROR_MAPPER - 錯誤代碼對應訊息
 * @property {number[]} INST_OPTIONS - 可用的分期選項
 */

/** @type {PayUniParams} */
const params = window.payuni_payment_v3_checkout_params || {
    ENV: 'P',
    SDK_TOKEN: '',
    INIT_ERROR_DETAIL: '',
    ERROR_MAPPER: {},
    USE_INST: false,
    ENABLE_3D_AUTH: false,
    ENABLE_TOKENIZATION: false,
    API_URL: '',
    INST_OPTIONS: []
};

/** @type {jQuery} */
const $ = window.jQuery;

export {params, $};
