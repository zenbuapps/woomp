<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Utils;

\defined( 'ABSPATH' ) || exit;

/**
 * CreditToken（綁定Token）淨化工具
 *
 * PayUni 的 CreditToken 僅允許字元集 [A-Za-z0-9@.#$%_-]（長度 ≤ 150）。
 * 過去程式直接以顧客 email 當 CreditToken，當 email 含非法字元（例如 Gmail
 * plus-alias 的 "+"）時，PayUni token_get 會回 TOKEN02019（綁定Token，格式錯誤），
 * 導致 SDK Token 取得失敗、首次付款無法發起。
 *
 * 本工具將 CreditToken 淨化為合法值，且刻意保證「向後相容」：
 *
 * - 對所有「已符合 PayUni 格式」的值（含一般 email）→ 原值回傳（identity）。
 *   因此既有以 email 綁定、且能正常續扣的舊訂閱，CreditToken 完全不變。
 * - 僅對「含非法字元」的值做替換（這些值在任何版本都無法通過 PayUni 綁定，
 *   代表不存在可被影響的既有綁定）。
 *
 * 重點：綁定（token_get / 首次付款）與續扣必須呼叫「同一個」淨化函式，
 * 才能保證同一顧客在 bind 與 renewal 產生一致的 CreditToken。
 *
 * @see https://docs.payuni.com.tw/web/#/29/383
 */
final class CreditTokenUtils {

    /** PayUni CreditToken 允許字元集與長度限制 */
    private const ALLOWED_PATTERN = '/\A[A-Za-z0-9@.#$%_-]{1,150}\z/';

    /**
     * 淨化 CreditToken
     *
     * @param string $credit_token 原始 CreditToken（通常為顧客 email）。
     * @param int    $customer_id  WooCommerce 顧客 ID，用於替換非法值時產生穩定識別碼；0 表示無會員。
     *
     * @return string 合法且確定性的 CreditToken。
     */
    public static function sanitize( string $credit_token, int $customer_id = 0 ): string {
        // 已符合 PayUni 格式 → 原值回傳（向後相容：既有 email 綁定的舊訂閱不受影響）。
        if ( \preg_match( self::ALLOWED_PATTERN, $credit_token ) ) {
            return $credit_token;
        }

        // 含非法字元（如 "+"）→ 這類值從未能通過 PayUni 綁定，故無既有綁定會被影響。
        // 優先使用會員 ID 產生穩定、可讀、跨綁定/續扣一致的識別碼。
        if ( $customer_id > 0 ) {
            return 'wc_' . $customer_id;
        }

        // 無會員 ID（訪客）→ 以 email 雜湊作為確定性 fallback（同輸入必得同輸出）。
        return 'wc_' . \md5( $credit_token );
    }
}
