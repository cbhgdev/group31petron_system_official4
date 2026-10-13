<?php
$content = file_get_contents(__DIR__ . '/staff_transactions_hub.php');
preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $content, $matches);
echo "TOTAL SCRIPTS: " . count($matches[1]) . "\n";
foreach ($matches[1] as $idx => $script) {
    if (strpos($script, 'calcRow') !== false || strpos($script, 'updateFuelCalc') !== false || strpos($script, 'formatOnInput') !== false) {
        file_put_contents(__DIR__ . "/temp_script_$idx.js", $script);
        echo "SAVED SCRIPT $idx (" . strlen($script) . " bytes)\n";
    }
}
