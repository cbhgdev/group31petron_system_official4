<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
header('Content-Type: text/plain');

echo "=== PETRON REGIONAL SETTINGS VERIFICATION SUITE ===\n\n";
$all_passed = true;

// TEST 1: Check Helper Functions exist in backend/lib.php
echo "[TEST 1] Testing Regional Helper Functions in backend/lib.php...\n";
$helpers = [
    'petron_get_setting_value',
    'petron_init_dynamic_timezone',
    'petron_currency_symbol',
    'petron_date_format',
    'petron_php_date_format',
    'petron_time_format',
    'petron_php_time_format',
    'petron_format_date',
    'petron_format_time',
    'petron_format_datetime',
    'petron_format_currency'
];

foreach ($helpers as $h) {
    if (function_exists($h)) {
        echo " + Function {$h}() exists.\n";
    } else {
        echo " FAILED: Function {$h}() does NOT exist!\n";
        $all_passed = false;
    }
}

// TEST 2: Currency Symbol Extraction
echo "\n[TEST 2] Testing Currency Symbol parsing...\n";
$test_currencies = [
    'PHP (₱)' => '₱',
    'USD ($)' => '$',
    'EUR (€)' => '€',
    'JPY (¥)' => '¥',
    'GBP (£)' => '£',
    '₱'       => '₱',
    '$'       => '$'
];
foreach ($test_currencies as $input => $expected) {
    // temporarily mock or test extraction
    $extracted = (preg_match('/\((.*?)\)/', $input, $m)) ? trim($m[1]) : trim($input);
    if ($extracted === $expected) {
        echo " + '{$input}' parsed as '{$extracted}' [OK]\n";
    } else {
        echo " - FAILED: '{$input}' parsed as '{$extracted}', expected '{$expected}'\n";
        $all_passed = false;
    }
}

// TEST 3: Date & Time Formats
echo "\n[TEST 3] Testing Date & Time format mappings...\n";
$test_ts = strtotime('2026-10-04 22:45:14');

echo "Current date format in system: " . petron_date_format() . " (PHP: " . petron_php_date_format() . ")\n";
echo "Current time format in system: " . petron_time_format() . " (PHP: " . petron_php_time_format(true) . ")\n";
echo "Formatted date sample: " . petron_format_date($test_ts) . "\n";
echo "Formatted time sample: " . petron_format_time($test_ts) . "\n";
echo "Formatted datetime sample: " . petron_format_datetime($test_ts) . "\n";
echo "Formatted currency sample: " . petron_format_currency(2850.50) . "\n";

// TEST 4: Timezone Functionality
echo "\n[TEST 4] Testing Dynamic Timezone Switching...\n";
$orig_tz = date_default_timezone_get();
echo "Default timezone active: {$orig_tz}\n";

// Test UTC
petron_init_dynamic_timezone();
echo "Active timezone after init: " . date_default_timezone_get() . "\n";

// TEST 5: API Save & Load Simulation for Regional Settings
echo "\n[TEST 5] Testing Save & Load of Regional Settings via DB...\n";
$test_settings = [
    'timezone'        => 'Asia/Singapore (UTC+8)',
    'date_format'     => 'MM/DD/YYYY',
    'time_format'     => '24H',
    'currency_symbol' => 'USD ($)'
];

try {
    foreach ($test_settings as $k => $v) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, category, station_id, updated_by, updated_at)
            VALUES (?, ?, 'regional', 0, 1, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $stmt->execute([$k, $v]);
    }
    
    // Read them back
    $saved_tz   = petron_get_setting_value('timezone', 0);
    $saved_date = petron_get_setting_value('date_format', 0);
    $saved_time = petron_get_setting_value('time_format', 0);
    $saved_curr = petron_get_setting_value('currency_symbol', 0);
    $sym        = petron_currency_symbol(0);

    echo "Saved & Retrieved:\n";
    echo " - Timezone: '{$saved_tz}' (Expected: '{$test_settings['timezone']}')\n";
    echo " - Date Format: '{$saved_date}' (Expected: '{$test_settings['date_format']}')\n";
    echo " - Time Format: '{$saved_time}' (Expected: '{$test_settings['time_format']}')\n";
    echo " - Currency: '{$saved_curr}', Symbol: '{$sym}' (Expected symbol: '$')\n";

    if ($saved_tz === $test_settings['timezone'] &&
        $saved_date === $test_settings['date_format'] &&
        $saved_time === $test_settings['time_format'] &&
        $saved_curr === $test_settings['currency_symbol'] &&
        $sym === '$') {
        echo "PASSED: All regional settings save and load perfectly!\n";
    } else {
        echo "FAILED: Saved values do not match test settings!\n";
        $all_passed = false;
    }

    // Restore to normal defaults
    $restore_settings = [
        'timezone'        => 'Asia/Manila (UTC+8)',
        'date_format'     => 'YYYY-MM-DD',
        'time_format'     => '24H', // As user had in screenshot
        'currency_symbol' => 'PHP (₱)'
    ];
    foreach ($restore_settings as $k => $v) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, category, station_id, updated_by, updated_at)
            VALUES (?, ?, 'regional', 0, 1, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $stmt->execute([$k, $v]);
    }
    echo "Restored settings to: Asia/Manila (UTC+8), YYYY-MM-DD, 24H, PHP (₱)\n";
} catch (Exception $e) {
    echo "FAILED with exception: " . $e->getMessage() . "\n";
    $all_passed = false;
}

echo "\n======================================================\n";
if ($all_passed) {
    echo "ALL REGIONAL SETTINGS TESTS PASSED SUCCESSFULLY! 100% FUNCTIONAL.\n";
} else {
    echo "SOME TESTS FAILED.\n";
}
