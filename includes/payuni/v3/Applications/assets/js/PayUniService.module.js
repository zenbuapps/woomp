/**
 * PayUni UNi Embed 付款服務
 *
 * @file PayUniService.module.js
 * @description PayUni UNi Embed SDK 整合服務，處理信用卡免跳轉付款流程
 * @see https://docs.payuni.com.tw/web/#/29/383
 */

import {$, params} from './env.module.js';
import {isPayuni} from './utils.module.js';
import {DEFAULT_IFRAME_STYLE, IFRAME_ELEMENTS, SDK_EVENTS, TOKEN_TYPE, WC_SELECTORS} from './constants.module.js';
import FormState from './FormState.module.js';
import UIHelper from './UIHelper.module.js';
import ApiService from './ApiService.module.js';

//region JSDoc 類型定義

/**
 * PayUni SDK 實例
 * @typedef {Object} PayuniSDK
 * @property {function(): Promise<Object>} start - 驗證 origin 或 token，並顯示輸入框在頁面上
 * @property {function(Function): void} onUpdate - 監聽使用者輸入表單的狀態變更事件
 * @property {function(): string} getTokenTypeText - 取得記憶卡號或約定信用卡的相關文案
 * @property {function(Object): Promise<TradeResult>} getTradeResult - 進行交易並取得加密的交易結果
 */

/**
 * UniPayment 全域物件
 * @typedef {Object} UniPayment
 * @property {function(string, Object): PayuniSDK} createSession - 建立 SDK 連線
 */

/**
 * SDK 初始化選項
 * @typedef {Object} SDKInitOptions
 * @property {string} env - 環境 (P: 正式, S: 測試)
 * @property {boolean} useInst - 是否啟用分期付款
 * @property {Object} elements - iframe 元素 ID 對應
 * @property {Object} style - iframe 樣式設定
 */

/**
 * 交易結果
 * @typedef {Object} TradeResult
 * @property {string} Status - 交易狀態
 * @property {string} EncryptInfo - 加密的交易資訊
 * @property {string} HashInfo - 雜湊驗證資訊
 * @property {string} MerID - 商店 ID
 * @property {string} Version - 版本
 */

/**
 * 交易設定
 * @typedef {Object} TradeConfig
 * @property {number} cardInst - 分期期數 (1=不分期)
 * @property {boolean} useDefault - 是否使用記憶卡號
 */

//endregion JSDoc 類型定義


/**
 * PayUni 付款服務類別
 *
 * @class PayUniService
 * @description 整合 PayUni UNi Embed SDK，處理信用卡免跳轉付款的完整流程
 *
 * @example
 * const service = new PayUniService();
 * await service.render();
 */
class PayUniService {
    /** @type {PayuniSDK} SDK 實例 */
    #payuniSDK;

    /** @type {FormState} 表單狀態管理器 */
    #formState;

    /** @type {UIHelper} UI 輔助工具 */
    #uiHelper;

    /** @type {ApiService} API 服務 */
    #apiService;

    /** @type {boolean} 是否已初始化 */
    #initialized = false;

    /** @type {boolean} 初始化是否失敗（SDK 未載入 / Token 未取得 / 連線建立失敗），用於在結帳頁顯示錯誤並阻擋下單 */
    #initFailed = false;

    /** @type {boolean} 是否已綁定事件 */
    #eventsBound = false;

    /** @type {boolean} 是否使用已儲存的卡片 */
    #useSavedCard = false;

    /** @type {string|null} 已選擇的儲存卡片 Token ID */
    #selectedTokenId = null;

    /** @type {number} 已選擇的分期期數（防止 WC updated_checkout 重置 select 時遺失選擇） */
    #selectedInstallment = 1;

    /** @type {boolean} SDK 是否處於 token 卡號模式（CardNo/CardExp 已由 useTokenType 設為有效，不應被 SDK 後續事件覆寫） */
    #sdkTokenCardActive = false;

    /**
     * 建構函式
     *
     * @description 初始化 SDK 連線並設定事件監聽
     */
    constructor() {
        this.#formState = new FormState();
        this.#uiHelper = new UIHelper();
        this.#apiService = new ApiService();

        this.#initSDK();
        this.#bindCheckoutEvents();
        this.#bindSavedCardEvents();
    }

    /**
     * 初始化 PayUni SDK
     *
     * @private
     * @description 建立 SDK 連線並設定 onUpdate 監聽器
     */
    #initSDK() {
        // 檢查 SDK 是否已載入
        if (typeof UniPayment === 'undefined') {
            console.error('[PayUni] SDK 未載入，請確認 uni-payment.js 已正確引入');
            this.#failInit('信用卡付款元件載入失敗，請重新整理頁面，若持續發生請聯繫網站管理員');
            return;
        }

        // 檢查 SDK Token
        if (!params.SDK_TOKEN) {
            console.error('[PayUni] SDK Token 未設定');
            this.#failInit(this.#getInitErrorMessage());
            return;
        }

        /** @type {SDKInitOptions} */
        const options = {
            env: params.ENV,
            useInst: params.USE_INST || false,
            elements: IFRAME_ELEMENTS,
            style: DEFAULT_IFRAME_STYLE
        };

        // 建立 SDK 連線（createSession 可能因 Token 無效而拋錯，需攔截以免靜默失敗）
        try {
            this.#payuniSDK = UniPayment.createSession(params.SDK_TOKEN, options);
            // 設定狀態更新監聽
            this.#payuniSDK.onUpdate((update) => this.#handleSDKUpdate(update));
        } catch (error) {
            console.error('[PayUni] SDK 建立連線失敗:', error);
            this.#failInit(this.#getInitErrorMessage());
        }
    }

    /**
     * 標記初始化失敗並在結帳頁顯示可見錯誤
     *
     * @param {string} message - 顯示給消費者的錯誤訊息
     * @private
     * @description 統一所有初始化失敗路徑（SDK 未載入 / Token 未取得 / 連線建立失敗）。
     *              除了 console.error 外，額外在結帳頁彈出 WooCommerce 風格通知並將表單設為未就緒，
     *              避免顧客面對空白的信用卡欄位卻無任何提示。
     */
    #failInit(message) {
        this.#initFailed = true;
        this.#formState.setReady(false);
        this.#uiHelper.showError(message);
    }

    /**
     * 取得初始化失敗時顯示的錯誤訊息
     *
     * @returns {string} 錯誤訊息（具 manage_woocommerce 權限者會額外看到後端失敗原因）
     * @private
     */
    #getInitErrorMessage() {
        const base = '信用卡付款目前無法使用，請稍後再試或改用其他付款方式';
        // INIT_ERROR_DETAIL 僅在具 manage_woocommerce 權限時由後端帶出，方便管理員當場定位問題
        const detail = params?.INIT_ERROR_DETAIL;
        return detail ? `${base}（管理員資訊：${detail}）` : base;
    }

    /**
     * 處理 SDK 狀態更新
     *
     * @param {Object} update - SDK 回傳的更新物件
     * @param {Object} [update.status] - 各欄位驗證狀態
     * @param {string} [update.event] - 觸發的事件名稱
     * @param {*} [update.data] - 事件相關資料
     * @private
     */
    #handleSDKUpdate(update) {
        const {status, event, data} = update;

        // 在 token 模式下，SDK 發送的 CardNo:null 不應覆蓋手動設定的 true 值
        // (token 模式卡號已遮蔽，SDK 只回報 CardNo:null，非真正錯誤)
        if (this.#sdkTokenCardActive && update.status && update.status.CardNo === null) {
            delete update.status.CardNo;
        }

        // 更新表單狀態
        this.#formState.update(update);

        // 處理特定事件
        if (event === SDK_EVENTS.USE_TOKEN_TYPE) {
            this.#handleTokenTypeEvent(data);
        }
    }

    /**
     * 處理 Token 類型事件（記憶卡號/約定信用卡）
     *
     * @param {Object} data - 事件資料
     * @param {string|null} data.cardNo - 已記憶的卡號（隱碼格式）
     * @param {string} data.tokenTypeText - 顯示的文案
     * @param {string} data.tokenType - Token 類型（1=約定信用卡, 2=記憶卡號, 3=強制約定信用卡）
     * @private
     */
    #handleTokenTypeEvent(data) {
        const {cardNo, tokenTypeText, tokenType} = data || {};

        // 僅在使用新卡片時顯示「記憶卡號」checkbox（存卡付款不需再次儲存）
        if (!this.#useSavedCard) {
            const $checkboxArea = $(WC_SELECTORS.TOKEN_TYPE_CHECKBOX_AREA);
            if ($checkboxArea.length) {
                $checkboxArea.css('display', 'flex');
            }
        }

        // 啟用記憶卡號，且已綁定成功後，SDK 會透過 cardNo 回傳綁定的記憶卡號
        if (tokenType === '2' && cardNo !== null) {
            // 可在此做相對應的畫面處理，例如顯示已綁定卡號
            this.#uiHelper.showSavedCardInfo(cardNo);
            // Token 模式下，SDK 已從已儲存 token 填入卡號與有效期限
            // useTokenType 事件不含 status，需手動標記這兩欄位為 valid
            this.#formState.update({status: {CardNo: true, CardExp: true}});
            // 標記進入 token 卡號模式，後續 SDK 事件的 CardNo:null 將被忽略
            this.#sdkTokenCardActive = true;
        }

        // 顯示 checkbox 的文案
        setTimeout(() => {
            if (tokenTypeText) {
                $(WC_SELECTORS.TOKEN_TYPE_TEXT).html(tokenTypeText);
            }
        }, 100);
    }

    /**
     * 渲染 SDK iframe
     *
     * @public
     * @async
     * @returns {Promise<void>}
     * @description 驗證 origin 或 token，並在頁面上顯示信用卡輸入框
     */
    async render() {
        // 初始化已失敗（SDK 未載入 / Token 未取得 / 連線建立失敗）— 錯誤已於 #initSDK 顯示，直接中止
        if (this.#initFailed) {
            return;
        }

        if (!this.#payuniSDK) {
            console.error('[PayUni] SDK 未初始化');
            this.#failInit(this.#getInitErrorMessage());
            return;
        }

        try {
            const response = await this.#payuniSDK.start();
            this.#formState.setReady(true);
            this.#initialized = true;
            console.log('[PayUni] iframe 連線成功:', response);
        } catch (error) {
            this.#formState.setReady(false);
            console.error('[PayUni] iframe 連線失敗:', error);

            // 根據錯誤代碼提供對應處理
            this.#handleConnectionError(error);
        }
    }

    /**
     * 處理連線錯誤
     *
     * @param {Error} error - 錯誤物件
     * @private
     */
    #handleConnectionError(error) {
        const errorCode = error?.message?.match(/Code (\d+)/)?.[1];

        switch (errorCode) {
            case '1008':
                this.#uiHelper.showError('信用卡輸入框連線逾時，請重新整理頁面');
                break;
            case '1007':
                this.#uiHelper.showError('網域驗證失敗，請聯繫網站管理員');
                break;
            default:
                this.#uiHelper.showError(this.#getErrorMessage(error?.message) || '信用卡輸入框載入失敗');
        }
    }

    /**
     * 綁定結帳事件
     *
     * @private
     * @description 攔截 WooCommerce 結帳按鈕點擊事件
     */
    #bindCheckoutEvents() {
        if (this.#eventsBound) return;

        // 使用事件代理，避免 WC 更新結帳後按鈕重新渲染導致 handler 失效
        $(document).on('click', WC_SELECTORS.PLACE_ORDER_BTN, (e) => {
            if (!isPayuni()) return;

            e.preventDefault();
            e.stopPropagation();
            this.#processCheckout();
        });

        // 監聽分期選擇變更，儲存至 class 屬性
        $(document).on('change', '#payuni_installment', (e) => {
            this.#selectedInstallment = parseInt($(e.target).val(), 10) || 1;
        });

        // WC updated_checkout 後恢復分期選擇（payment section 重新渲染會重置 select）
        $(document).on('updated_checkout', () => {
            if (params.USE_INST && this.#selectedInstallment > 1) {
                $('#payuni_installment').val(String(this.#selectedInstallment));
            }
        });

        // 監聽發票載具類型變更，顯示/隱藏對應輸入框
        $(document).on('change', '#payuni_carrier_type', (e) => {
            const carrierType = $(e.target).val();
            // 先隱藏所有載具資訊輸入框
            $('.payuni-carrier-info-row').hide();
            // 顯示對應的輸入框
            if (carrierType && carrierType !== 'amego') {
                $(`#payuni_carrier_info_row_${carrierType}`).show();
            }
            // 捐贈不需買方名稱；其他有載具時顯示
            if (carrierType && carrierType !== 'Donate') {
                $('#payuni_inv_buyer_name_row').show();
            } else {
                $('#payuni_inv_buyer_name_row').hide();
            }
        });

        this.#eventsBound = true;
    }

    /**
     * 綁定已儲存卡片選擇事件
     *
     * @private
     * @description 監聽已儲存卡片的 radio 選擇變更
     */
    #bindSavedCardEvents() {
        // 監聽已儲存卡片的選擇變更
        $(document).on('change', '.payuni-saved-token-radio', (e) => {
            const selectedValue = $(e.target).val();
            this.#updateSavedCardState(selectedValue);
        });

        // 初始化時檢查是否有預選的卡片
        const $checkedToken = $('.payuni-saved-token-radio:checked');
        if ($checkedToken.length) {
            this.#updateSavedCardState($checkedToken.val());
        }
    }

    /**
     * 更新已儲存卡片狀態
     *
     * @param {string} selectedValue - 選擇的值（token ID 或 'new'）
     * @private
     */
    #updateSavedCardState(selectedValue) {
        if (selectedValue === 'new' || !selectedValue) {
            // 選擇使用新卡片
            this.#useSavedCard = false;
            this.#selectedTokenId = null;
            // 重置 token 模式，確保新卡片流程以實際輸入欄位進行驗證
            this.#sdkTokenCardActive = false;
            // 還原全部 form-group 可見
            $('#put_card_no').closest('.payuni-form-group').show();
            $('#put_card_exp').closest('.payuni-form-group').show();
            $('.payuni-new-card-form').show();
            $('#payuni_used_token_id').val('');
        } else {
            // 選擇使用已儲存的卡片
            this.#useSavedCard = true;
            this.#selectedTokenId = selectedValue;
            // 顯示 form wrapper 讓 CVC 可見，但隱藏 CardNo 與 CardExp
            // SDK getTradeResult({ useDefault: true }) 需要用戶輸入安全碼才能完成交易
            $('.payuni-new-card-form').show();
            $('#put_card_no').closest('.payuni-form-group').hide();
            $('#put_card_exp').closest('.payuni-form-group').hide();
            // 使用存卡付款時隱藏「記憶卡號」checkbox（不需再次儲存）
            $(WC_SELECTORS.TOKEN_TYPE_CHECKBOX_AREA).hide();
            $('#payuni_used_token_id').val(selectedValue);
        }
        console.log('[PayUni] 卡片選擇狀態更新:', {useSavedCard: this.#useSavedCard, tokenId: this.#selectedTokenId});
    }

    /**
     * 處理結帳流程
     *
     * @private
     * @async
     * @description 完整的結帳流程：驗證 → 取得交易結果 → 送出訂單 → 執行交易
     */
    async #processCheckout() {
        // 避免重複提交
        if (this.#uiHelper.isProcessing()) {
            return;
        }

        // 初始化失敗時，下單前重新提示真正原因，避免顯示誤導性的「請稍候」
        if (this.#initFailed) {
            this.#uiHelper.showError(this.#getInitErrorMessage());
            return;
        }

        // 清除之前的錯誤訊息
        this.#uiHelper.clearErrors();

        // 如果是使用已儲存的卡片，跳過 SDK 表單驗證，但仍需呼叫 getTradeResult
        if (this.#useSavedCard && this.#selectedTokenId) {
            this.#uiHelper.setLoading(true);
            try {
                // Step 1: 必須呼叫 getTradeResult({ useDefault: true })，讓 PayUni 建立 pending trade 記錄
                // 若省略此步，merchant_trade 將返回 IFTRADE04002（未有交易設定資料）
                await this.#payuniSDK.getTradeResult({useDefault: true});
                console.log('[PayUni] 使用已儲存卡片 - getTradeResult 完成');

                // 使用已儲存的卡片付款
                const additionalData = {
                    sdk_token_tmp: params.SDK_TOKEN,
                    payuni_use_saved_token: '1',
                    payuni_saved_token_id: this.#selectedTokenId,
                    payuni_installment: this.#getInstallmentValue(),
                    ...this.#getCarrierData(),
                };

                const checkoutResponse = await this.#apiService.submitCheckout(additionalData);
                console.log('[PayUni] 使用已儲存卡片 - 取得後端交易參數:', checkoutResponse);

                // 執行 PayUni 幕後交易授權
                const tradeResponse = await this.#apiService.sendTradeRequest(checkoutResponse);
                console.log('[PayUni] 使用已儲存卡片 - PayUni 交易回應:', tradeResponse);

                // 導向感謝頁面
                this.#handleTradeSuccess(checkoutResponse);

            } catch (error) {
                console.error('[PayUni] 已儲存卡片結帳流程錯誤:', error);
                this.#uiHelper.showError(this.#getErrorMessage(error.message));
            } finally {
                this.#uiHelper.setLoading(false);
            }
            return;
        }

        // 驗證 SDK 是否已準備好
        if (!this.#formState.isReady()) {
            this.#uiHelper.showError('信用卡輸入框尚未準備完成，請稍候');
            return;
        }

        // 驗證表單欄位
        if (!this.#formState.isAllValid()) {
            const invalidFields = this.#formState.getInvalidFields();
            const fieldNames = {
                CardNo: '信用卡號',
                CardExp: '有效期限',
                CardCvc: '安全碼'
            };
            const errorMsg = invalidFields
                .map(f => fieldNames[f] || f)
                .join('、') + ' 填寫有誤，請重新確認';
            this.#uiHelper.showError(errorMsg);
            return;
        }

        this.#uiHelper.setLoading(true);

        try {
            // Step 1: 從 SDK 取得交易結果（加密的信用卡資訊）
            const tradeResult = await this.#getTradeResult();
            console.log('[PayUni] Step 1 - 取得 SDK 交易結果:', tradeResult);

            // Step 2: 送出訂單到 WooCommerce 並取得 PayUni 交易參數
            // SDK 會在 put_token_type 容器中產生一個 id 為 type-checkbox 的 checkbox
            const shouldSaveCard = params.ENABLE_TOKENIZATION && $(WC_SELECTORS.TOKEN_TYPE_CHECKBOX).is(':checked');
            const additionalData = {
                sdk_token_tmp: params.SDK_TOKEN,
                payuni_save_card: shouldSaveCard ? '1' : '0',
                payuni_installment: this.#getInstallmentValue(),
                ...this.#getCarrierData(),
            };

            const checkoutResponse = await this.#apiService.submitCheckout(additionalData);
            console.log('[PayUni] Step 2 - 取得後端交易參數:', checkoutResponse);

            // Step 3: 執行 PayUni 幕後交易授權
            const tradeResponse = await this.#apiService.sendTradeRequest(checkoutResponse);
            console.log('[PayUni] Step 3 - PayUni 交易回應:', tradeResponse);

            // Step 4: 導向感謝頁面
            this.#handleTradeSuccess(checkoutResponse);

        } catch (error) {
            console.error('[PayUni] 結帳流程錯誤:', error);
            this.#uiHelper.showError(this.#getErrorMessage(error.message));
        } finally {
            this.#uiHelper.setLoading(false);
        }
    }

    /**
     * 取得發票載具資料
     *
     * @private
     * @returns {Object} 載具相關欄位 { payuni_carrier_type, payuni_carrier_info, payuni_inv_buyer_name }
     */
    #getCarrierData() {
        // 使用 name 屬性選取，避免 WC billing hidden field 的 id 衝突
        const carrierType = $('select[name="payuni_carrier_type"]').val() || '';
        if (!carrierType) {
            return {};
        }
        // 依類型從對應輸入框取得 carrier_info
        let carrierInfo = '';
        if (carrierType !== 'amego') {
            const infoInput = $(`#payuni_carrier_info_${carrierType}`);
            carrierInfo = infoInput.length ? (infoInput.val() || '') : '';
        }
        // 同步更新隱藏欄位（供 WC checkout form submit 使用）
        $('input[name="payuni_carrier_info"]').val(carrierInfo);
        const invBuyerName = $('input[name="payuni_inv_buyer_name"]').val() || '';
        return {
            payuni_carrier_type: carrierType,
            payuni_carrier_info: carrierInfo,
            payuni_inv_buyer_name: invBuyerName,
        };
    }

    /**
     * 取得分期付款選擇的值
     *
     * @private
     * @returns {number} 分期期數（1 表示不分期）
     */
    #getInstallmentValue() {
        if (!params.USE_INST) {
            return 1;
        }
        return this.#selectedInstallment;
    }

    /**
     * 取得交易結果
     *
     * @private
     * @async
     * @returns {Promise<TradeResult>} 交易結果
     * @description 呼叫 SDK 的 getTradeResult 取得加密的信用卡資訊
     */
    async #getTradeResult() {
        const config = this.#prepareTradeConfig();

        try {
            const result = await this.#payuniSDK.getTradeResult(config);
            return result;
        } catch (error) {
            console.error('[PayUni] 取得交易結果失敗:', error);
            throw new Error(error?.message || '信用卡資訊處理失敗');
        }
    }

    /**
     * 準備交易設定
     *
     * @private
     * @returns {TradeConfig} 交易設定
     */
    #prepareTradeConfig() {
        // 取得分期期數
        const cardInst = this.#getInstallmentValue();

        // 檢查是否需要記憶卡號（需要啟用記憶卡號功能且勾選了儲存卡片）
        // SDK 會在 put_token_type 容器中產生一個 id 為 type-checkbox 的 checkbox
        const shouldSaveCard = params.ENABLE_TOKENIZATION && $(WC_SELECTORS.TOKEN_TYPE_CHECKBOX).is(':checked');
        // 定期定額 gateway 強制約定信用卡（續扣需 CreditHash），不受全域 ENABLE_TOKENIZATION / checkbox 影響。
        // 缺此強制設定時 SDK token 已帶 UseTokenType 但 getTradeResult 未帶 → 卡號遮蔽 → 「Credit card number is required」。
        const isSubscription = $(WC_SELECTORS.PAYUNI_CREDIT_SUBSCRIPTION_V3).is(':checked');

        const config = {
            cardInst,
            // SDK token 模式下（已綁定卡號），useDefault: true 才能通過 SDK 內部 Code 1005 驗證
            // SDK 在 token 模式下 this.status.CardNo 不會被更新（卡號遮蔽），必須以 useDefault 取代
            useDefault: this.#sdkTokenCardActive
        };

        // useTokenType 必須與後端 token_get 送出的值完全一致，否則 PAYUNi 不執行綁定、
        // 授權雖成功卻不回 CreditHash，導致後續無法幕後續扣。
        // SDK_TOKEN 在頁面載入時就已綁定某個 UseTokenType，故一律以後端傳來的值為準，
        // 不在前端自行推導（購物車含訂閱時後端已送 3 強制約定信用卡）。
        if (isSubscription || shouldSaveCard) {
            config.useTokenType = params.USE_TOKEN_TYPE || TOKEN_TYPE.REMEMBER_CARD;
        }

        return config;
    }

    /**
     * 處理交易成功
     *
     * @param {Object} checkoutResponse - 結帳回應（含導向網址）
     * @private
     */
    #handleTradeSuccess(checkoutResponse) {
        // 交易成功後，導向訂單完成頁面
        console.log('[PayUni] 交易成功，導向訂單完成頁面', checkoutResponse);

        if (checkoutResponse.redirect) {
            window.location.href = checkoutResponse.redirect;
        } else {
            // 如果沒有 redirect，重新載入頁面讓 WooCommerce 處理
            window.location.reload();
        }
    }

    /**
     * 取得錯誤訊息
     *
     * @param {string} status - 錯誤代碼
     * @returns {string} 對應的錯誤訊息
     * @private
     */
    #getErrorMessage(status) {
        return params?.ERROR_MAPPER?.[status] || status || '發生未知錯誤';
    }
}


export default PayUniService;