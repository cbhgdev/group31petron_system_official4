<?php
$content = file_get_contents(__DIR__ . '/staff_transactions_hub.php');
$lines = explode("\n", $content);
$res = [];
$res[] = "TOTAL LINES: " . count($lines);

foreach ($lines as $i => $line) {
    if (stripos($line, 'formatOnInput') !== false) {
        $res[] = "Line " . ($i + 1) . ": " . trim($line);
    }
    if (stripos($line, 'formatOnBlur') !== false) {
        $res[] = "Line " . ($i + 1) . ": " . trim($line);
    }
}
$fp = fopen(__DIR__ . '/diag_log.txt', 'w');
if ($fp) {
    fwrite($fp, implode("\r\n", $res));
    fclose($fp);
}
echo "RESULT: " . ($fp ? "OK" : "FAILED");
