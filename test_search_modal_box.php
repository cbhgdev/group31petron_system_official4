<?php
$c = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/staff_inventory_fuel.php');
foreach (explode("\n", $c) as $i => $line) {
    if (stripos($line, '.sr-modal-box') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
?>
