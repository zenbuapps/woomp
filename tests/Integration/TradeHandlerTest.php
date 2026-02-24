<?php

declare(strict_types=1);

use J7\Payuni\Infrastructure\Http\TradeHandler;
use J7\Payuni\Shared\Utils\EncryptUtils;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ─── Integration Test: TradeHandler::process_notify ─────────────────────────
// ⚠️ 需要 wp-pest 環境（wordpress-develop + SQLite）
// 執行指令：vendor/bin/pest --group=integration
//
// ⚠️ 已知陷阱：isUnitTest() guard 防止 --group=unit 時載入此檔
if (isUnitTest()) {
    return;
}

uses(TestCase::class);

beforeEach(function (): void {
    makeSettingDTO(); // 注入 sandbox 憑證
});

afterEach(function (): void {
    resetSettingDTO();
});

test('process_notify decrypts valid webhook payload successfully', function (): void {
    $original = [
        'Status'      => 'SUCCESS',
        'Message'     => '授權成功',
        'MerID'       => 'S05584374',
        'MerTradeNo'  => '150',
        'TradeNo'     => '1771850232599176790',
        'TradeAmt'    => '80',
        'TradeStatus' => '1',
        'Card4No'     => '0001',
        'ResCode'     => '00',
    ];

    // 模擬 PayUni 傳入的加密資料
    $encrypt_info = EncryptUtils::encrypt($original);
    $hash_info    = EncryptUtils::hash_info($encrypt_info);

    $handler = new TradeHandler();
    $result  = $handler->process_notify([
        'EncryptInfo' => $encrypt_info,
        'HashInfo'    => $hash_info,
        'MerID'       => 'S05584374',
        'Version'     => '1.0',
    ]);

    expect($result['Status'])->toBe('SUCCESS')
        ->and($result['MerTradeNo'])->toBe('150')
        ->and($result['TradeNo'])->toBe('1771850232599176790')
        ->and($result['Card4No'])->toBe('0001');
})->group('integration');

test('process_notify throws exception when EncryptInfo is missing', function (): void {
    $handler = new TradeHandler();

    expect(fn() => $handler->process_notify([
        'HashInfo' => 'some-hash',
        'MerID'    => 'S05584374',
    ]))->toThrow(Exception::class, '缺少加密資料');
})->group('integration');

test('process_notify throws exception when HashInfo is missing', function (): void {
    $handler = new TradeHandler();

    expect(fn() => $handler->process_notify([
        'EncryptInfo' => 'some-encrypted-data',
        'MerID'       => 'S05584374',
    ]))->toThrow(Exception::class, '缺少加密資料');
})->group('integration');

test('process_notify throws exception when hash verification fails', function (): void {
    $encrypt_info = EncryptUtils::encrypt(['MerID' => 'S05584374', 'TradeAmt' => '80']);
    $wrong_hash   = str_repeat('A', 64); // 故意傳錯的 hash

    $handler = new TradeHandler();

    expect(fn() => $handler->process_notify([
        'EncryptInfo' => $encrypt_info,
        'HashInfo'    => $wrong_hash,
        'MerID'       => 'S05584374',
    ]))->toThrow(Exception::class, 'Hash 驗證失敗');
})->group('integration');
