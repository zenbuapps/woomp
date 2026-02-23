/**
 * PayUni UNi Embed UI 工具模組
 *
 * @file UIHelper.module.js
 * @description 處理 WooCommerce 結帳頁面的 UI 操作，包含載入狀態、錯誤訊息顯示等
 */

import {$} from './env.module.js';
import {WC_SELECTORS} from './constants.module.js';

/**
 * UI 輔助工具類別
 *
 * @class UIHelper
 * @description 封裝 WooCommerce 結帳頁面的 UI 操作方法
 */
class UIHelper {
    /** @type {jQuery} 結帳表單 jQuery 物件 */
    #$checkoutForm;

    /**
     * 建構函式
     */
    constructor() {
        this.#$checkoutForm = $(WC_SELECTORS.CHECKOUT_FORM);
    }

    /**
     * 設定載入狀態
     *
     * @param {boolean} isLoading - 是否顯示載入狀態
     * @description 控制結帳表單的 processing 樣式及 blockUI 遮罩
     */
    setLoading(isLoading) {
        if (isLoading) {
            this.#$checkoutForm.addClass('processing')?.block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        } else {
            this.#$checkoutForm.removeClass('processing')?.unblock();
        }
    }

    /**
     * 顯示錯誤訊息
     *
     * @param {string} message - 錯誤訊息內容
     * @description 在結帳表單上方顯示 WooCommerce 風格的錯誤訊息
     */
    showError(message) {
        // 移除既有的錯誤訊息
        this.clearErrors();

        // 移除 processing 狀態
        this.#$checkoutForm.removeClass('processing');

        // 插入錯誤訊息
        const errorHtml = `
            <div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">
                <div role="alert">
                    <ul class="woocommerce-error" tabindex="-1">
                        <li>${this.#escapeHtml(message)}</li>
                    </ul>
                </div>
            </div>
        `;

        this.#$checkoutForm.before(errorHtml);
        this.scrollToNotices();
    }

    /**
     * 顯示多個錯誤訊息
     *
     * @param {string[]} messages - 錯誤訊息陣列
     */
    showErrors(messages) {
        this.clearErrors();
        this.#$checkoutForm.removeClass('processing');

        const errorItems = messages.map(msg => `<li>${this.#escapeHtml(msg)}</li>`).join('');
        const errorHtml = `
            <div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">
                <div role="alert">
                    <ul class="woocommerce-error" tabindex="-1">
                        ${errorItems}
                    </ul>
                </div>
            </div>
        `;

        this.#$checkoutForm.before(errorHtml);
        this.scrollToNotices();
    }

    /**
     * 清除所有錯誤訊息
     */
    clearErrors() {
        $('.woocommerce-NoticeGroup-checkout').remove();
        $('.checkout-inline-error-message').remove();
    }

    /**
     * 捲動到通知訊息區塊
     *
     * @description 使用 WooCommerce 內建的 scroll_to_notices 方法（如果可用）
     */
    scrollToNotices() {
        if (typeof $?.scroll_to_notices === 'function') {
            $.scroll_to_notices(this.#$checkoutForm);
        } else {
            // 後備方案：手動捲動到表單頂部
            const scrollElement = $(WC_SELECTORS.NOTICE_GROUP);
            if (scrollElement.length) {
                $('html, body').animate({
                    scrollTop: scrollElement.offset().top - 100
                }, 500);
            }
        }
    }

    /**
     * 取得結帳表單 jQuery 物件
     *
     * @returns {jQuery} 結帳表單 jQuery 物件
     */
    getCheckoutForm() {
        return this.#$checkoutForm;
    }

    /**
     * 檢查結帳表單是否處於 processing 狀態
     *
     * @returns {boolean} 是否處於 processing 狀態
     */
    isProcessing() {
        return this.#$checkoutForm.is('.processing');
    }

    /**
     * HTML 跳脫處理
     *
     * @param {string} text - 要跳脫的文字
     * @returns {string} 跳脫後的文字
     * @private
     */
    #escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 顯示已綁定的卡號資訊
     *
     * @param {string} cardNo - 已綁定的卡號（隱碼格式，如 414712******2716）
     */
    showSavedCardInfo(cardNo) {
        const $savedCardInfo = $('#payuni_saved_card_info');
        if ($savedCardInfo.length) {
            $savedCardInfo.html(`已綁定卡號: ${this.#escapeHtml(cardNo)}`).show();
        } else {
            // 如果元素不存在，在表單中創建
            const $container = $(WC_SELECTORS.TOKEN_TYPE_CHECKBOX_AREA);
            if ($container.length) {
                $container.after(`<div id="payuni_saved_card_info" class="payuni-saved-card-info">已綁定卡號: ${this.#escapeHtml(cardNo)}</div>`);
            }
        }
    }
}

export default UIHelper;
