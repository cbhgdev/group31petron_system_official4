<?php
$dirs = ['public', 'backend'];
$results = [];
foreach ($dirs as $dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/' . $dir));
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            if (strpos($content, 'UPDATE fuel_inventory') !== false || strpos($content, 'fuel_inventory SET') !== false) {
                $results[] = $file->getPathname();
            }
        }
    }
}
echo json_encode($results);
?>
