<?php
$js = file_get_contents(__DIR__ . '/temp_script_6.js');
preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/i', $js, $matches);
echo "SCRIPT 6 FUNCTIONS:\n" . implode("\n", array_unique($matches[1])) . "\n";
