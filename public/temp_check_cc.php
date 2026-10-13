<?php
$dir = __DIR__;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$found = [];

foreach ($files as $file) {
    if ($file->isDir()) continue;
    $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
    if (!in_array($ext, ['php', 'js'])) continue;
    $content = file_get_contents($file->getPathname());
    if (strpos($content, 'CLOSING_COMPLETED') !== false || strpos($content, 'closing_completed') !== false) {
        preg_match_all('/[^\n]*closing_completed[^\n]*/i', $content, $m);
        foreach ($m[0] as $line) {
            if (preg_match('/(UPDATE|INSERT|status\s*=)/i', $line)) {
                $found[] = $file->getFilename() . ": " . trim($line);
            }
        }
    }
}

file_put_contents(__DIR__ . '/temp_closing_completed.txt', implode("\n", $found));
echo "FOUND: " . count($found);
