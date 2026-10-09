<?php
foreach (glob(__DIR__ . '/../includes/*.php') as $f) echo "includes: " . basename($f) . "\n";
foreach (glob(__DIR__ . '/../partials/*.php') as $f) echo "partials: " . basename($f) . "\n";
