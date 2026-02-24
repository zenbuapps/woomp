<?php

declare(strict_types=1);

use J7\Payuni\Shared\Utils\EncryptUtils;

// ─── Unit Tests: EncryptUtils ───────────────────────────────────────────────
// 使用 Reflection 注入 SettingDTO，不依賴 WP get_option()

beforeEach(function (): void {
    makeSettingDTO(); // 注入 sandbox 測試憑證
});

afterEach(function (): void {
    resetSettingDTO(); // 還原 singleton，避免測試間互相污染
});

test('encrypt produces non-empty hex string', function (): void {
    $result = EncryptUtils::encrypt(['MerID' => 'S05584374', 'TradeAmt' => '80']);

    expect($result)
        ->toBeString()
        ->not->toBeEmpty()
        ->toMatch('/^[0-9a-f]+$/'); // 結果應為純 hex 字串
})->group('unit');

test('decrypt reverses encrypt round-trip', function (): void {
    $original = [
        'MerID'      => 'S05584374',
        'MerTradeNo' => '150',
        'TradeAmt'   => '80',
        'ProdDesc'   => 'Logo Collection',
    ];

    $encrypted = EncryptUtils::encrypt($original);
    $decrypted = EncryptUtils::decrypt($encrypted);

    expect($decrypted)->toBe($original);
})->group('unit');

test('decrypt with wrong key returns false or empty', function (): void {
    $encrypted = EncryptUtils::encrypt(['MerID' => 'S05584374']);

    // 換一組不同的 key/iv
    makeSettingDTO([
        'hash_key' => 'wrongkeyXXXXXXXXXXXXXXXXXXXXXXXX',
        'hash_iv'  => 'wrongivXXXXXXXX',
    ]);

    // decrypt 應回傳空陣列（openssl_decrypt 失敗時 parse_str 得到空值）
    $result = EncryptUtils::decrypt($encrypted);

    expect($result)->toBeArray()->toBeEmpty();
})->group('unit');

test('hash_info produces uppercase sha256 hex string', function (): void {
    $encrypted = EncryptUtils::encrypt(['MerID' => 'S05584374', 'TradeAmt' => '80']);
    $hash      = EncryptUtils::hash_info($encrypted);

    expect($hash)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[A-F0-9]+$/'); // 全大寫 hex
})->group('unit');

test('hash_info changes when encrypt string changes', function (): void {
    $hash1 = EncryptUtils::hash_info('abc');
    $hash2 = EncryptUtils::hash_info('xyz');

    expect($hash1)->not->toBe($hash2);
})->group('unit');

test('hash_info is deterministic for same input', function (): void {
    $hash1 = EncryptUtils::hash_info('same-input');
    $hash2 = EncryptUtils::hash_info('same-input');

    expect($hash1)->toBe($hash2);
})->group('unit');
