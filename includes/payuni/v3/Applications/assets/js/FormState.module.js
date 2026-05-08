/**
 * PayUni UNi Embed 表單狀態管理
 *
 * @file FormState.module.js
 * @description 管理 SDK iframe 表單的驗證狀態
 */

/**
 * 表單欄位驗證狀態
 * @typedef {Object} FieldStatus
 * @property {boolean|null} CardNo - 信用卡號驗證狀態
 * @property {boolean|null} CardExp - 有效期限驗證狀態
 * @property {boolean|null} CardCvc - 安全碼驗證狀態
 */

/**
 * 表單狀態管理類別
 * @class FormState
 */
class FormState {
    /** @type {FieldStatus} */
    #status = {CardNo: null, CardExp: null, CardCvc: null};

    /** @type {boolean} */
    #isReady = false;

    /** @type {string|null} */
    #lastEvent = null;

    constructor() {
        this.reset();
    }

    /**
     * 更新表單狀態
     * @param {Object} update - SDK onUpdate 回傳的更新物件
     */
    update(update) {
        const {status, event} = update;
        if (status) this.#status = {...this.#status, ...status};
        if (event) this.#lastEvent = event;
    }

    /** @returns {boolean} 是否全部欄位驗證通過 */
    isAllValid() {
        return Object.values(this.#status).every(val => val === true);
    }

    /** @returns {boolean} iframe 是否已準備完成 */
    isReady() {
        return this.#isReady;
    }

    /** @param {boolean} ready */
    setReady(ready) {
        this.#isReady = ready;
    }

    /** @returns {FieldStatus} */
    getAllStatus() {
        return {...this.#status};
    }

    /** @returns {string[]} 未通過驗證的欄位名稱陣列 */
    getInvalidFields() {
        return Object.entries(this.#status)
            .filter(([_, value]) => value !== true)
            .map(([key]) => key);
    }

    reset() {
        this.#status = {CardNo: null, CardExp: null, CardCvc: null};
        this.#isReady = false;
        this.#lastEvent = null;
    }
}

export default FormState;
