import {$} from "./env.module.js";
import Elements from "./Elements.module.js";

$(document).ready(function () {
    $("body").on("updated_checkout", function (e) {
        setTimeout(() => {
            new Elements();
        }, 300);
    });
});
