<?php
$lines = file('C:/xampp/htdocs/group31petron_system_official4/backend/lib.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'ensure_fuel_inventory_synced') !== false || strpos($line, 'ensure_station_inventory_synced') !== false) {
        echo "Line " . ($i + 1) . ": $line";
    }
}
