<?php
$dir = __DIR__;
$files = scandir($dir);
$matches = [];
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $c = file_get_contents($dir . '/' . $f);
        if (stripos($c, 'directPoModal') !== false || stripos($c, 'autoPopulateLowStock') !== false) {
            $matches[] = $f;
        }
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
