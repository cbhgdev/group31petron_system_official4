<?php
$c = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/manager_stock_request_review.php');
foreach (explode("\n", $c) as $i => $line) {
    if (stripos($line, 'CURRENT LITERS') !== false || stripos($line, 'Direct Purchase Order') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
?>
