<?php
$files_to_remove = [
    'check_missing_fks.php',
    'check_relations.php',
    'find_candidate_fks.php',
    'check_tx_tables.php',
    'analyze_missing_relations.php',
    'check_products_schema.php',
    'deep_check_missing.php',
    'check_fuel_tables.php',
    'check_fuel_pumps_create.php',
    'check_msc.php',
    'check_deliv_data.php',
    'check_do_rows.php',
    'check_pma_relation.php',
    'apply_foreign_keys.php',
    'check_fk_error.php',
    'check_fks_now.php',
    'check_target_fks.php',
    'sync_pma_relations.php',
    'list_temp_scripts.php',
    'inspect_coords.php',
    'test_clusters.php',
    'apply_designer_layout.php',
    'check_pdf_pages.php'
];

$deleted = [];
$failed = [];

foreach ($files_to_remove as $f) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $f;
    if (file_exists($path)) {
        if (@unlink($path)) {
            $deleted[] = $f;
        } else {
            $failed[] = $f;
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'dir' => __DIR__,
    'deleted' => $deleted,
    'failed' => $failed
], JSON_PRETTY_PRINT);
