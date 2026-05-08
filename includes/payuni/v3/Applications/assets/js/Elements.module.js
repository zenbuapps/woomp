/**
 * PayUni UNi Embed 元素管理模組
 *
 * @file Elements.module.js
 * @description 管理 PayUni SDK iframe 元素的渲染與生命週期
 */

import PayUniService from './PayUniService.module.js';
import {$} from './env.module.js';
import {isPayuni} from './utils.module.js';
import {IFRAME_ELEMENTS, WC_SELECTORS} from './constants.module.js';

/**
 * 元素管理類別
 *
 * @class Elements
 * @description 負責偵測付款方式變更並管理 PayUni iframe 的渲染
 */
class Elements {
    /** @type {PayUniService|null} PayUni 服務實例（單例） */
    static #serviceInstance = null;

    /**
     * 建構函式
     *
     * @description 初始化並設定付款方式變更監聽
     */
    constructor() {
        this.#init();
        this.#bindPaymentMethodChange();
        console.log('Elements constructor')
    }

    /**
     * 初始化渲染
     *
     * @private
     */
    #init() {
        if (isPayuni()) {
            this.#renderPayUniIframe();
        }
    }

    /**
     * 綁定付款方式變更事件
     *
     * @private
     */
    #bindPaymentMethodChange() {
        console.log('bindPaymentMethodChange')
        $(WC_SELECTORS.PAYMENT_METHODS).on('change', () => {
            if (isPayuni()) {
                this.#renderPayUniIframe();
            }
        });
    }

    /**
     * 渲染 PayUni iframe
     *
     * @private
     * @async
     */
    async #renderPayUniIframe() {

        const isRendered = $(`#${IFRAME_ELEMENTS.CardNo}`).children().is('iframe')

        // 避免重複渲染
        if (isRendered && Elements.#serviceInstance) {
            console.log('[PayUni] iframe 已渲染，跳過重複渲染');
            return;
        }

        try {
            // 使用單例模式確保只有一個 PayUniService 實例
            if (!Elements.#serviceInstance) {
                Elements.#serviceInstance = new PayUniService();
            }

            await Elements.#serviceInstance.render();
            console.log('[PayUni] iframe 渲染完成');
        } catch (error) {
            console.error('[PayUni] iframe 渲染失敗:', error);
        }
    }

    /**
     * 取得 PayUniService 實例
     *
     * @returns {PayUniService|null} PayUniService 實例
     * @static
     */
    static getServiceInstance() {
        return Elements.#serviceInstance;
    }

    /**
     * 重置實例（用於測試或重新初始化）
     *
     * @static
     */
    static resetInstance() {
        Elements.#serviceInstance = null;
    }
}

export default Elements;