<?php
$sidebars = [];
foreach (scandir(__DIR__ . '/../includes') as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') $sidebars[] = "includes/$f";
}
if (is_dir(__DIR__ . '/../partials')) {
    foreach (scandir(__DIR__ . '/../partials') as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'php') $sidebars[] = "partials/$f";
    }
}
echo json_encode($sidebars, JSON_PRETTY_PRINT);
