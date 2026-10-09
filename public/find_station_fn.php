<?php
$files = scandir(__DIR__ . '/../backend');
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $c = file_get_contents(__DIR__ . '/../backend/' . $f);
        if (strpos($c, 'function user_station_id') !== false) {
            echo "Found in backend/$f\n";
        }
    }
}
$files = scandir(__DIR__);
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $c = file_get_contents(__DIR__ . '/' . $f);
        if (strpos($c, 'function user_station_id') !== false) {
            echo "Found in public/$f\n";
        }
    }
}
