<?php
$diff = file_get_contents(__DIR__ . '/temp_git_diff.txt');
$lines = explode("\n", $diff);
$matches = [];
foreach ($lines as $i => $l) {
    if (preg_match('/(meter|fuel|calc|reading)/i', $l)) {
        $matches[] = ($i+1) . ": " . $l;
    }
}
echo "FUEL MATCHES IN DIFF: " . count($matches) . "\n";
echo implode("\n", array_slice($matches, 0, 30));
