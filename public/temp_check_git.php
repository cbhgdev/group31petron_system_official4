<?php
$output = shell_exec('cd .. && git diff public/staff_transactions_hub.php 2>&1');
file_put_contents(__DIR__ . '/temp_git_diff.txt', (string)$output);
echo "HUB DIFF: " . strlen((string)$output);
