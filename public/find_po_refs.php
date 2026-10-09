<?php
$dir = new RecursiveDirectoryIterator(__DIR__ . '/..');
$it  = new RecursiveIteratorIterator($dir);
$matches = [];
foreach ($it as $file) {
    if ($file->isFile() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $content = file_get_contents($file->getPathname());
        if (strpos($content, 'purchase_orders_management.php') !== false) {
            $matches[] = str_replace('\\', '/', $file->getPathname());
        }
    }
}
echo json_encode($matches, JSON_PRETTY_PRINT);
