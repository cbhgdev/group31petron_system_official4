<?php
$file = __DIR__ . '/staff_transactions_hub.php';
$lines = file($file);
$matches = [];
foreach ($lines as $num => $line) {
    if (preg_match('/ending.*\.value\s*=/i', $line) || 
        preg_match('/\.value\s*=.*ending/i', $line) ||
        preg_match('/restoreFuelInputCache/i', $line) ||
        preg_match('/petron_fuel_reading_cache/i', $line) ||
        preg_match('/petron_draft/i', $line) ||
        preg_match('/user_form_drafts/i', $line)) {
        $matches[] = ($num + 1) . ": " . trim($line);
    }
}
file_put_contents(__DIR__ . '/temp_ending_setters.txt', implode("\n", $matches));
echo "MATCHES: " . count($matches);
