/**
 * PayUni UNi Embed API 服務模組
 *
 * @file ApiService.module.js
 * @description 處理與後端 API 的通訊，包含訂單建立、交易請求等
 */

import {$} from './env.module.js';
import {TRADE_STATUS, WC_SELECTORS} from './constants.module.js';

/**
 * WooCommerce 結帳參數類型定義
 * @typedef {Object} WCCheckoutParams
 * @property {string} ajax_url - AJAX 網址
 * @property {string} checkout_url - 結帳處理網址
 * @property {string} wc_ajax_url - WC AJAX 網址
 * @property {string} i18n_checkout_error - 結帳錯誤訊息翻譯
 * @property {string} debug_mode - 除錯模式
 */

/**
 * 結帳回應參數類型定義
 * @typedef {Object} CheckoutResponse
 * @property {string} result - 結果狀態 (success/failure)
 * @property {string} redirect - 導向網址
 * @property {string} [EncryptInfo] - AES 加密字串（PayUni 交易用）
 * @property {string} [HashInfo] - SHA256 加密字串
 * @property {string} [MerID] - 商店代號
 * @property {string} [Version] - API 版本
 * @property {string} [ApiUrl] - API 端點網址
 * @property {number} [order_id] - 訂單編號
 */

/**
 * 交易回應類型定義
 * @typedef {Object} TradeResponse
 * @property {string} Status - 交易狀態
 * @property {string} MerID - 商店代號
 * @property {string} Version - API 版本
 * @property {string} EncryptInfo - 加密的回應資料
 * @property {string} HashInfo - 雜湊驗證資料
 */

/**
 * API 服務類別
 *
 * @class ApiService
 * @description 封裝與 PayUni 後端 API 的通訊邏輯
 */
class ApiService {
    /**
     * 送出結帳請求到 WooCommerce
     *
     * @description
     * 此方法會送出結帳表單到 WooCommerce，
     * 後端 Gateway 的 process_payment 會回傳 PayUni 交易所需的加密參數
     *
     * @param {Object} additionalData - 額外的表單資料（如 SDK Token、Card Hash）
     * @returns {Promise<CheckoutResponse>} 後端回傳的結帳回應（含交易參數）
     * @throws {Error} 當結帳請求失敗時
     */
    async submitCheckout(additionalData = {}) {
        const wc_checkout_params = this.#getWCCheckoutParams();
        const $form = $(WC_SELECTORS.CHECKOUT_FORM);

        // 序列化表單資料
        const formData = $form.serializeArray();

        // 加入額外資料
        Object.entries(additionalData).forEach(([name, value]) => {
            formData.push({name, value});
        });

        return new Promise((resolve, reject) => {
            $.ajax({
                type: 'POST',
                url: wc_checkout_params.checkout_url,
                data: $.param(formData),
                dataType: 'json',
                success: (result) => {
                    // 處理 WooCommerce 回應
                    if (result.result === 'success') {
                        // 檢查是否包含 PayUni 交易參數
                        if (this.#hasTradeParams(result)) {
                            resolve(result);
                        } else if (result.redirect) {
                            // 一般的 WooCommerce 成功回應，直接導向
                            window.location.href = result.redirect;
                        } else {
                            reject(new Error('無效的伺服器回應'));
                        }
                    } else if (result.result === 'failure') {
                        // 處理 WooCommerce 驗證錯誤
                        const errorMsg = this.#extractErrorMessage(result);
                        reject(new Error(errorMsg || '結帳驗證失敗'));
                    } else {
                        reject(new Error('無效的伺服器回應'));
                    }
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    const errorMessage = wc_checkout_params.i18n_checkout_error || errorThrown || '結帳請求失敗';
                    reject(new Error(errorMessage));
                }
            });
        });
    }

    /**
     * 發送交易請求到 PayUni
     *
     * @description
     * 將後端回傳的加密交易參數送到 PayUni API 進行幕後信用卡交易授權
     *
     * @param {CheckoutResponse} checkoutResponse - 結帳回應（含交易參數）
     * @returns {Promise<TradeResponse>} 交易回應
     * @throws {Error} 當交易請求失敗時
     */
    async sendTradeRequest(checkoutResponse) {
        const {ApiUrl, EncryptInfo, HashInfo, MerID, Version} = checkoutResponse;

        // 組裝請求參數（根據 PayUni 文件格式）
        const requestBody = {
            MerID,
            Version,
            EncryptInfo,
            HashInfo
        };

        const response = await fetch(ApiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(requestBody).toString()
        });

        if (!response.ok) {
            throw new Error(`網路錯誤: ${response.status} ${response.statusText}`);
        }

        const result = await response.json();

        if (result.Status !== TRADE_STATUS.SUCCESS) {
            throw new Error(result.Status);
        }

        return result;
    }

    /**
     * 取得 WooCommerce 結帳參數
     *
     * @returns {WCCheckoutParams} WooCommerce 結帳參數
     * @private
     */
    #getWCCheckoutParams() {
        return window.wc_checkout_params || {};
    }

    /**
     * 檢查回應是否包含 PayUni 交易參數
     *
     * @param {Object} response - 回應物件
     * @returns {boolean} 是否包含交易參數
     * @private
     */
    #hasTradeParams(response) {
        const requiredKeys = ['EncryptInfo', 'HashInfo', 'MerID', 'Version', 'ApiUrl'];
        return requiredKeys.every(key => {
            const exists = !!response?.[key];
            if (!exists) {
                console.warn(`[PayUni] 回應缺少參數: ${key}`);
            }
            return exists;
        });
    }

    /**
     * 從 WooCommerce 回應中提取錯誤訊息
     *
     * @param {Object} result - WooCommerce 回應
     * @returns {string|null} 錯誤訊息
     * @private
     */
    #extractErrorMessage(result) {
        // 嘗試從 messages 欄位提取錯誤
        if (result.messages) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = result.messages;
            const errorLi = tempDiv.querySelector('.woocommerce-error li');
            return errorLi?.textContent?.trim() || null;
        }
        return null;
    }
}

export default ApiService;
