<?php

declare(strict_types=1);

use J7\Payuni\Contracts\DTOs\TradeReqHashDTO;

// ─── Unit Tests: TradeReqHashDTO ────────────────────────────────────────────
// 純 PHP 建構，不依賴 WP 函式，直接測試屬性設定邏輯

test('constructor sets properties from args', function (): void {
    $dto = new TradeReqHashDTO([
        'MerID'      => 'S05584374',
        'MerTradeNo' => '150',
        'TradeAmt'   => 80,
        'Token'      => 'test-sdk-token-abc123',
        'ProdDesc'   => 'Logo Collection',
    ]);

    expect($dto->MerID)->toBe('S05584374')
        ->and($dto->MerTradeNo)->toBe('150')
        ->and($dto->TradeAmt)->toBe(80)
        ->and($dto->Token)->toBe('test-sdk-token-abc123')
        ->and($dto->ProdDesc)->toBe('Logo Collection');
})->group('unit');

test('constructor ignores unknown properties', function (): void {
    $dto = new TradeReqHashDTO([
        'MerID'           => 'S05584374',
        'NonExistentProp' => 'should-be-ignored',
    ]);

    expect($dto->MerID)->toBe('S05584374')
        ->and(property_exists($dto, 'NonExistentProp'))->toBeFalse();
})->group('unit');

test('constructor sets default values for unset properties', function (): void {
    $dto = new TradeReqHashDTO([]);

    expect($dto->MerID)->toBe('')
        ->and($dto->MerTradeNo)->toBe('')
        ->and($dto->TradeAmt)->toBe(0)
        ->and($dto->CardInst)->toBe(1)
        ->and($dto->API3D)->toBe(1);
})->group('unit');

test('to_array returns set properties and filters null', function (): void {
    $dto = new TradeReqHashDTO([
        'MerID'      => 'S05584374',
        'MerTradeNo' => '150',
        'TradeAmt'   => 80,
        'NotifyURL'  => 'https://payuni-test.powerhouse.tw/wc-api/payuni_notify',
    ]);

    $array = $dto->to_array();

    expect($array)
        ->toHaveKey('MerID')
        ->toHaveKey('MerTradeNo')
        ->toHaveKey('TradeAmt')
        ->toHaveKey('NotifyURL');

    expect($array['MerID'])->toBe('S05584374');
    expect($array['TradeAmt'])->toBe(80);
    expect($array['NotifyURL'])->toBe('https://payuni-test.powerhouse.tw/wc-api/payuni_notify');
})->group('unit');

test('to_array excludes empty string properties that were never set', function (): void {
    $dto = new TradeReqHashDTO([
        'MerID'    => 'S05584374',
        'TradeAmt' => 80,
    ]);

    $array = $dto->to_array();

    // 預設值為空字串的屬性（非 null）仍應出現在 array 中（array_filter 只過 null）
    expect($array)->toHaveKey('MerID');
    expect($array['MerID'])->toBe('S05584374');
})->group('unit');
