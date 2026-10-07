<?php
$files = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
foreach ($iter as $file) {
    if (!$file->isDir()) {
        $files[] = $file->getPathname();
    }
}
echo json_encode($files);
?>
