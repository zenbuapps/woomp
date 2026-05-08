/**
 * PayUni UNi Embed 工具函式模組
 *
 * @file utils.module.js
 * @description 提供通用的輔助函式
 */

import {$} from './env.module.js';
import {WC_SELECTORS} from './constants.module.js';

/**
 * 判斷是否選中 PayUni 免跳轉付款方式
 *
 * @returns {boolean} 是否選中 PayUni 信用卡 V3 付款方式
 */
function isPayuni() {
    const $input = $(WC_SELECTORS.PAYUNI_CREDIT_V3);
    return !!$input.is(':checked');
}

/**
 * 取得目前選中的付款方式 ID
 *
 * @returns {string|null} 付款方式 ID
 */
function getSelectedPaymentMethod() {
    const $checked = $(`${WC_SELECTORS.PAYMENT_METHODS}:checked`);
    return $checked.val() || null;
}

/**
 * 檢查 DOM 元素是否存在
 *
 * @param {string} selector - CSS 選擇器
 * @returns {boolean} 元素是否存在
 */
function elementExists(selector) {
    return $(selector).length > 0;
}

/**
 * 安全地解析 JSON 字串
 *
 * @param {string} jsonString - JSON 字串
 * @param {*} defaultValue - 解析失敗時的預設值
 * @returns {*} 解析結果或預設值
 */
function safeParseJSON(jsonString, defaultValue = null) {
    try {
        return JSON.parse(jsonString);
    } catch (e) {
        console.warn('[PayUni] JSON 解析失敗:', e);
        return defaultValue;
    }
}

/**
 * 延遲執行
 *
 * @param {number} ms - 延遲毫秒數
 * @returns {Promise<void>}
 */
function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * 防抖函式
 *
 * @param {Function} func - 要執行的函式
 * @param {number} wait - 等待時間（毫秒）
 * @returns {Function} 防抖後的函式
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

export {
    isPayuni,
    getSelectedPaymentMethod,
    elementExists,
    safeParseJSON,
    delay,
    debounce
};
