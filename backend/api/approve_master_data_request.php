<?php
/**
 * Approve / Reject Master Data Request API
 * backend/api/approve_master_data_request.php
 *
 * Called by the Manager from the Request Data Management dashboard.
 * POST body: { id, action: 'approve'|'reject', rejection_reason?, modified_data? }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Manager access required.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$id     = (int)($input['id'] ?? 0);
$action = trim($input['action'] ?? '');
$rejectionReason = trim($input['rejection_reason'] ?? '');
$modifiedData    = $input['modified_data'] ?? null; // Optional: manager can edit payload before approving

if (!$id || !in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request ID or action.']);
    exit;
}

if ($action === 'reject' && empty($rejectionReason)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Rejection reason is required.']);
    exit;
}

if (!function_exists('safe_dynamic_insert')) {
    function safe_dynamic_insert(PDO $pdo, string $table, array $data): int {
        static $cachedCols = [];
        if (!isset($cachedCols[$table])) {
            $cols = [];
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($r['Field'])] = $r['Field'];
                }
            } catch (Exception $e) {
                return 0;
            }
            $cachedCols[$table] = $cols;
        }

        $cols = $cachedCols[$table];
        if (empty($cols)) return 0;

        $insertCols = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $k => $v) {
            $kl = strtolower($k);
            if (isset($cols[$kl])) {
                $realCol = $cols[$kl];
                $insertCols[] = "`{$realCol}`";
                if ($v === '__NOW__') {
                    $placeholders[] = "NOW()";
                } else {
                    $placeholders[] = "?";
                    $params[] = $v;
                }
            }
        }

        if (empty($insertCols)) return 0;

        $sql = "INSERT INTO `{$table}` (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

try {
    $pdo->beginTransaction();

    // Fetch the request
    $stmt = $pdo->prepare("SELECT * FROM master_data_requests WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Request not found.']);
        exit;
    }

    if ($req['status'] !== 'Pending') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Request has already been processed.']);
        exit;
    }

    $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';
    $payload   = json_decode($req['data_payload'], true);

    // If manager modified the data, use it
    if (!empty($modifiedData) && is_array($modifiedData)) {
        $payload = $modifiedData;
    }

    // Update the request record
    $updStmt = $pdo->prepare("
        UPDATE master_data_requests
        SET status = ?, reviewed_by = ?, rejection_reason = ?, data_payload = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updStmt->execute([
        $newStatus,
        $me['id'],
        $action === 'reject' ? $rejectionReason : null,
        json_encode($payload),
        $id
    ]);

    // ── If Approved, insert into production tables ─────────────────────────────
    if ($action === 'approve') {
        $category = $req['category'];
        $reqStationId = !empty($req['station_id']) ? (int)$req['station_id'] : 1;

        if ($category === 'Merchandise Product') {
            $productName = trim($payload['product_name'] ?? '');
            $prodCategory = trim($payload['category'] ?? 'Lubricants');
            $brand = trim($payload['brand'] ?? 'Generic');
            if ($brand === '' || strtolower($brand) === 'n/a') $brand = 'Generic';
            $unitVal = trim($payload['unit'] ?? $payload['size'] ?? 'pcs');
            if ($unitVal === '' || strtolower($unitVal) === 'n/a') $unitVal = 'pcs';

            // Use staff-provided SKU if present; otherwise generate one
            $sku = trim($payload['sku'] ?? '');
            if ($sku === '' || strtolower($sku) === 'n/a') {
                $sku = 'SKU-' . strtoupper(substr(md5($productName . time()), 0, 8));
            }

            // Price: prefer unit_price > selling_price > suggested_price > price
            $unitPrice = floatval(
                $payload['unit_price'] ??
                $payload['selling_price'] ??
                $payload['suggested_price'] ??
                $payload['price'] ??
                0.00
            );
            $unitCost = floatval($payload['unit_cost'] ?? $payload['cost'] ?? 0.00);
            if ($unitCost <= 0.00 && $unitPrice > 0.00) {
                $unitCost = round($unitPrice * 0.70, 2);
            }

            $reorderLevel = (int)($payload['reorder_level'] ?? 24);
            $criticalLevel = (int)($payload['critical_level'] ?? 10);

            // Check if product already exists in inventory_products by name & station
            $existingId = 0;
            try {
                $chkStmt = $pdo->prepare("SELECT id FROM inventory_products WHERE station_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?)) LIMIT 1");
                $chkStmt->execute([$reqStationId, $productName]);
                $existingId = (int)$chkStmt->fetchColumn();
            } catch (Exception $e) {}

            if ($existingId > 0) {
                $newId = $existingId;
            } else {
                $ipData = [
                    'product_name'   => $productName,
                    'category'       => $prodCategory,
                    'brand'          => $brand,
                    'sku'            => $sku,
                    'size'           => $unitVal,
                    'unit_cost'      => $unitCost,
                    'unit_price'     => $unitPrice,
                    'reorder_level'  => $reorderLevel,
                    'critical_level' => $criticalLevel,
                    'stock_quantity' => 0,
                    'status'         => 'active',
                    'station_id'     => $reqStationId,
                    'created_at'     => '__NOW__',
                    'updated_at'     => '__NOW__'
                ];
                if (!empty($payload['barcode'])) {
                    $ipData['barcode'] = $payload['barcode'];
                }
                if (!empty($payload['expiration_date'])) {
                    $ipData['expiration_date'] = $payload['expiration_date'];
                }

                // Check table columns for any alternate column names
                $tableCols = [];
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM inventory_products");
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $tableCols[strtolower($r['Field'])] = $r['Field'];
                    }
                } catch (Exception $e) {}

                if (!isset($tableCols['product_name']) && isset($tableCols['name'])) {
                    $ipData['name'] = $productName;
                }
                if (!isset($tableCols['unit_price']) && isset($tableCols['price'])) {
                    $ipData['price'] = $unitPrice;
                }
                if (!isset($tableCols['unit_cost']) && isset($tableCols['cost'])) {
                    $ipData['cost'] = $unitCost;
                }
                if (!isset($tableCols['size']) && isset($tableCols['unit'])) {
                    $ipData['unit'] = $unitVal;
                }
                if (!isset($tableCols['stock_quantity']) && isset($tableCols['stock'])) {
                    $ipData['stock'] = 0;
                }

                $newId = safe_dynamic_insert($pdo, 'inventory_products', $ipData);

                // Auto-generate SKU if needed
                if ($newId > 0 && (empty($payload['sku']) || strtolower($payload['sku']) === 'n/a')) {
                    $sku = 'P' . str_pad($newId, 4, '0', STR_PAD_LEFT);
                    try {
                        $pdo->prepare("UPDATE inventory_products SET sku = ? WHERE id = ?")->execute([$sku, $newId]);
                    } catch (Exception $e) {}
                }
            }

            // Sync with products table (legacy/compatibility catalog)
            if ($newId > 0) {
                try {
                    $chkP = $pdo->prepare("SELECT id FROM products WHERE id = ? OR (station_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))) LIMIT 1");
                    $chkP->execute([$newId, $reqStationId, $productName]);
                    if (!$chkP->fetchColumn()) {
                        $catId = null;
                        try {
                            $cFind = $pdo->prepare("SELECT id FROM product_categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                            $cFind->execute([$prodCategory]);
                            $catId = $cFind->fetchColumn() ?: null;
                            if (!$catId) {
                                $pdo->prepare("INSERT INTO product_categories (name, created_at) VALUES (?, NOW())")->execute([$prodCategory]);
                                $catId = (int)$pdo->lastInsertId();
                            }
                        } catch (Exception $ce) {}

                        $pData = [
                            'id'              => $newId,
                            'sku'             => $sku,
                            'name'            => $productName,
                            'description'     => '',
                            'category_id'     => $catId,
                            'brand'           => $brand,
                            'unit'            => $unitVal,
                            'cost'            => $unitCost,
                            'price'           => $unitPrice,
                            'min_stock_level' => $criticalLevel,
                            'max_stock_level' => $reorderLevel * 20,
                            'station_id'      => $reqStationId,
                            'current_stock'   => 0,
                            'capacity'        => 480,
                            'status'          => 'active',
                            'created_at'      => '__NOW__',
                            'updated_at'      => '__NOW__'
                        ];
                        safe_dynamic_insert($pdo, 'products', $pData);
                    }
                } catch (Exception $e) {}

                // Initialize station_inventory entry with stock_level = 0
                try {
                    $chkSi = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1");
                    $chkSi->execute([$reqStationId, $newId]);
                    if (!$chkSi->fetchColumn()) {
                        $siData = [
                            'station_id'     => $reqStationId,
                            'product_id'     => $newId,
                            'stock_level'    => 0,
                            'unit'           => $unitVal,
                            'cost'           => $unitCost,
                            'price'          => $unitPrice,
                            'reorder_level'  => $reorderLevel,
                            'critical_level' => $criticalLevel,
                            'status'         => 'active',
                            'expiration_date'=> !empty($payload['expiration_date']) ? $payload['expiration_date'] : null,
                            'last_updated'   => '__NOW__',
                            'created_at'     => '__NOW__'
                        ];
                        safe_dynamic_insert($pdo, 'station_inventory', $siData);
                    }
                } catch (Exception $e) {}
            }

        } elseif ($category === 'Service Type') {
            $serviceName = trim($payload['service_name'] ?? '');
            $serviceCategory = trim($payload['service_category'] ?? $payload['category'] ?? 'General');
            $serviceKey  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $serviceName));
            $serviceKey  = trim($serviceKey, '_');
            if ($serviceKey === '') $serviceKey = 'svc_' . time();
            $baseKey = $serviceKey;
            $sfx = 1;
            while (true) {
                $chkKey = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key = ? AND station_id = ? LIMIT 1");
                $chkKey->execute([$serviceKey, $reqStationId]);
                if (!$chkKey->fetch()) break;
                $serviceKey = $baseKey . '_' . $sfx++;
            }

            $suggestedPrice = floatval(
                $payload['suggested_price'] ??
                $payload['default_price'] ??
                $payload['service_price'] ??
                $payload['price'] ??
                0.00
            );
            $laborFee = floatval($payload['labor_fee'] ?? $suggestedPrice);
            $duration = !empty($payload['estimated_duration']) ? (int)$payload['estimated_duration'] : null;
            $pricingNotes = $payload['pricing_notes'] ?? $payload['estimated_duration'] ?? $payload['remarks'] ?? null;
            $description = $payload['description'] ?? $payload['remarks'] ?? null;

            // Check duplicate service
            $existingSvcId = 0;
            try {
                $chkSvc = $pdo->prepare("SELECT id FROM job_order_service_types WHERE station_id = ? AND LOWER(TRIM(service_name)) = LOWER(TRIM(?)) LIMIT 1");
                $chkSvc->execute([$reqStationId, $serviceName]);
                $existingSvcId = (int)$chkSvc->fetchColumn();
            } catch (Exception $e) {}

            if ($existingSvcId > 0) {
                $newId = $existingSvcId;
            } else {
                $maxSvcId = 0;
                try {
                    $maxSvcId = (int)$pdo->query("SELECT MAX(id) FROM job_order_service_types")->fetchColumn();
                } catch (Exception $e) {}
                $serviceCode = 'SVC-' . str_pad($maxSvcId + 1, 4, '0', STR_PAD_LEFT);

                $nextSortOrder = 1;
                try {
                    $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM job_order_service_types WHERE station_id = ?");
                    $sortStmt->execute([$reqStationId]);
                    $nextSortOrder = (int)$sortStmt->fetchColumn() ?: 1;
                } catch (Exception $e) {}

                $svcData = [
                    'station_id'         => $reqStationId,
                    'service_code'       => $serviceCode,
                    'service_key'        => $serviceKey,
                    'service_name'       => $serviceName,
                    'category'           => $serviceCategory,
                    'service_price'      => $suggestedPrice,
                    'min_price'          => $suggestedPrice,
                    'max_price'          => $suggestedPrice,
                    'labor_fee'          => $laborFee,
                    'pricing_notes'      => $pricingNotes,
                    'description'        => $description,
                    'estimated_duration' => $duration,
                    'required_mechanics' => 1,
                    'sort_order'         => $nextSortOrder,
                    'status'             => 'approved',
                    'active'             => 1,
                    'created_by'         => $me['id'],
                    'submitted_by'       => $req['requested_by'] ?? $me['id'],
                    'reviewed_by'        => $me['id'],
                    'created_at'         => '__NOW__',
                    'updated_at'         => '__NOW__'
                ];

                $newId = safe_dynamic_insert($pdo, 'job_order_service_types', $svcData);
            }

        } elseif ($category === 'Inspection Item') {
            $itemName = trim($payload['item_name'] ?? $payload['inspection_name'] ?? '');
            $description = trim($payload['description'] ?? '');
            $cat = trim($payload['category'] ?? 'General');
            $isActive = isset($payload['is_active']) ? (int)$payload['is_active'] : 1;

            $existingId = 0;
            try {
                $dup = $pdo->prepare("SELECT id FROM vehicle_inspection_items WHERE station_id = ? AND LOWER(TRIM(item_name)) = LOWER(TRIM(?)) LIMIT 1");
                $dup->execute([$reqStationId, $itemName]);
                $existingId = (int)$dup->fetchColumn();
            } catch (Exception $e) {}

            if ($existingId > 0) {
                $newId = $existingId;
            } else {
                $inspData = [
                    'station_id'   => $reqStationId,
                    'item_name'    => $itemName,
                    'description'  => $description ?: null,
                    'category'     => $cat ?: 'General',
                    'is_active'    => $isActive,
                    'created_by'   => $req['requested_by'] ?? $me['id'],
                    'reviewed_by'  => $me['id'],
                    'created_at'   => '__NOW__',
                    'updated_at'   => '__NOW__'
                ];
                $newId = safe_dynamic_insert($pdo, 'vehicle_inspection_items', $inspData);
            }

        } elseif ($category === 'Vehicle') {
            $vBrand = trim($payload['vehicle_brand'] ?? '');
            $vModel = trim($payload['vehicle_model'] ?? '');
            $vType  = trim($payload['vehicle_type'] ?? 'Sedan');
            $vFuel  = trim($payload['fuel_type'] ?? 'Gasoline');
            $vehicleName = trim(($vBrand ? $vBrand . ' ' : '') . $vModel);
            if ($vehicleName === '') $vehicleName = 'Standard Vehicle';

            $existingVehId = 0;
            try {
                $dupV = $pdo->prepare("SELECT id FROM vehicle_types WHERE station_id = ? AND LOWER(TRIM(vehicle_name)) = LOWER(TRIM(?)) LIMIT 1");
                $dupV->execute([$reqStationId, $vehicleName]);
                $existingVehId = (int)$dupV->fetchColumn();
            } catch (Exception $e) {}

            if ($existingVehId > 0) {
                $newId = $existingVehId;
            } else {
                $vehData = [
                    'station_id'    => $reqStationId,
                    'category'      => $vType,
                    'vehicle_name'  => $vehicleName,
                    'vehicle_type'  => $vType,
                    'vehicle_brand' => $vBrand ?: null,
                    'vehicle_model' => $vModel ?: null,
                    'fuel_type'     => $vFuel ?: null,
                    'status'        => 'approved',
                    'submitted_by'  => $req['requested_by'] ?? $me['id'],
                    'reviewed_by'   => $me['id'],
                    'created_by'    => $req['requested_by'] ?? $me['id'],
                    'is_active'     => 1,
                    'created_at'    => '__NOW__',
                    'updated_at'    => '__NOW__'
                ];
                $newId = safe_dynamic_insert($pdo, 'vehicle_types', $vehData);
            }
        }
    }

    $pdo->commit();

    // ── Notify the requester — event-driven ─────────────────────────────────
    try {
        $requestedBy = (int)$req['requested_by'];
        $requestNo   = $req['request_no'] ?? "#$id";
        $category    = ucfirst($req['category'] ?? 'Item');
        $mgr_name    = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Station Manager');
        $mgr_role    = normalize_role($me['role'] ?? 'Manager');

        if ($action === 'approve') {
            notify_staff_action_result(
                $pdo,
                $requestedBy,
                "Master Data Request ({$category})",
                'Approved',
                $requestNo,
                $mgr_name,
                $mgr_role,
                "Approved and added to station master catalog. Ready for operational use.",
                'master_data_request',
                $id,
                "staff_transactions_hub.php?section=merchandise&apply_mdr={$id}"
            );
        } else {
            $rejDetail = !empty($rejectionReason) ? "Reason: {$rejectionReason}" : 'Reason: Master data entry rejected by management.';
            notify_staff_action_result(
                $pdo,
                $requestedBy,
                "Master Data Request ({$category})",
                'Rejected',
                $requestNo,
                $mgr_name,
                $mgr_role,
                $rejDetail,
                'master_data_request',
                $id,
                "staff_transactions_hub.php?section=merchandise"
            );
        }
    } catch (Exception $notifErr) {
        error_log("Approval notification error: " . $notifErr->getMessage());
    }

    $actionDesc = ($action === 'approve') ? 'Approved' : 'Rejected';
    $successMsg = "Request {$requestNo} ({$category}) has been {$actionDesc} successfully.";
    echo json_encode([
        'success'       => true,
        'message'       => $successMsg,
        'request_no'    => $requestNo,
        'category'      => $category,
        'status'        => $newStatus,
        'new_record_id' => $newId ?? null
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log("Approve master data request error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
