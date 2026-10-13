<?php
$dir = __DIR__;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$found = [];

foreach ($files as $file) {
    if ($file->isDir()) continue;
    $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
    if (!in_array($ext, ['php', 'js', 'html'])) continue;
    $content = file_get_contents($file->getPathname());
    if (strpos($content, 'endingEl.value = beginning') !== false ||
        strpos($content, 'ending_reading') !== false ||
        strpos($content, 'present_reading') !== false) {
        // check if there's any automatic copy
        if (preg_match('/(ending|present)[^=;\n]*=\s*[^=;\n]*(beginning|previous)/i', $content, $m)) {
            $found[] = $file->getPathname() . " ==> MATCH: " . $m[0];
        }
    }
}

file_put_contents(__DIR__ . '/temp_repo_search.txt', implode("\n", $found));
echo "FOUND: " . count($found);
