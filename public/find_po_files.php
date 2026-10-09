<?php
$dir = __DIR__;
$files = scandir($dir);
$matches = [];
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $c = file_get_contents($dir . '/' . $f);
        if (stripos($c, 'create po') !== false || stripos($c, 'create_po') !== false || stripos($c, 'purchase order') !== false || stripos($c, 'create purchase order') !== false) {
            $matches[] = $f;
        }
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
