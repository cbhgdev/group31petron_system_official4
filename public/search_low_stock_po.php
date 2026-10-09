<?php
$dir = __DIR__;
$files = scandir($dir);
$results = [];
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $c = file_get_contents($dir . '/' . $f);
        if ((stripos($c, 'low stock') !== false || stripos($c, 'out of stock') !== false || stripos($c, 'low_stock') !== false) &&
            (stripos($c, 'purchase') !== false || stripos($c, ' po') !== false || stripos($c, 'reorder') !== false)) {
            $results[] = $f;
        }
    }
}
echo json_encode($results, JSON_PRETTY_PRINT);
