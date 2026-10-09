<?php
$lines = file('C:/xampp/htdocs/group31petron_system_official4/backend/lib.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'function get_tank_config') !== false) {
        echo "Found at line " . ($i + 1) . ": $line\n";
    }
}
