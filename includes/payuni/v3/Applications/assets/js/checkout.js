import {$, params} from "./env.module.js";
import Elements from "./Elements.module.js";
import {PAGE_CONTEXT} from "./constants.module.js";

$(document).ready(function () {
    // 訂閱換卡頁（order-pay）不會觸發 updated_checkout：DOM ready 就初始化一次，
    // 之後付款方式切換由 Elements 的 change 監聽負責渲染 iframe。
    if (params.PAGE_CONTEXT === PAGE_CONTEXT.ORDER_PAY) {
        setTimeout(() => {
            new Elements();
        }, 300);
        return;
    }

    $("body").on("updated_checkout", function (e) {
        setTimeout(() => {
            new Elements();
        }, 300);
    });
});
