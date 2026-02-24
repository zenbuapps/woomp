<?php

declare(strict_types=1);

/**
 * Helper: 判斷目前是否為 unit test 模式
 *
 * 用途：整合測試頂部加入 guard，防止 --group=unit 時載入 WP 環境
 *
 * @return bool
 */
function isUnitTest(): bool {
    return !empty($GLOBALS['argv']) && in_array('--group=unit', $GLOBALS['argv'], true);
}

/**
 * Helper: 注入測試用 SettingDTO singleton（不依賴 WP get_option）
 *
 * @param array $args 覆蓋預設值，預設使用 sandbox 憑證
 */
function makeSettingDTO(array $args = []): void {
    $defaults = [
        'merchant_id' => 'S05584374',
        'hash_key'    => 'tnfdY03NofsO0gRux1LOtXVEp3xZOXBf',
        'hash_iv'     => 'UffVePT5rgd3O8CR',
    ];

    $reflection  = new ReflectionClass(\J7\Payuni\Contracts\DTOs\SettingDTO::class);
    $instance    = $reflection->newInstanceWithoutConstructor();

    foreach (array_merge($defaults, $args) as $key => $value) {
        if ($reflection->hasProperty($key)) {
            $prop = $reflection->getProperty($key);
            $prop->setAccessible(true);
            $prop->setValue($instance, $value);
        }
    }

    $instanceProp = $reflection->getProperty('instance');
    $instanceProp->setAccessible(true);
    $instanceProp->setValue(null, $instance);
}

/**
 * Helper: 重置 SettingDTO singleton（afterEach 清除）
 */
function resetSettingDTO(): void {
    $reflection   = new ReflectionClass(\J7\Payuni\Contracts\DTOs\SettingDTO::class);
    $instanceProp = $reflection->getProperty('instance');
    $instanceProp->setAccessible(true);
    $instanceProp->setValue(null, null);
}
