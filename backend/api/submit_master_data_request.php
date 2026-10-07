<?php
/**
 * Submit Master Data Request API
 * backend/api/submit_master_data_request.php
 *
 * Serves both POST (submit request) and GET (list requests with filters).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$me   = current_user();
$role = role_key($me['role'] ?? '');

// Only operational roles can submit/view requests
$allowed_roles = ['staff', 'manager', 'admin', 'superadmin', 'developer'];
if (!in_array($role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$stationId = !empty($me['station_id']) ? (int)$me['station_id'] : null;

// ── Handle POST: Submit new request ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $reqType     = $input['request_type'] ?? ''; // 'vehicle_type', 'service_type', 'product'
    $requestData = $input['request_data'] ?? null;
    
    if (empty($requestData) || !is_array($requestData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Request data is required.']);
        exit;
    }

    $category = '';
    $sourceModule = '';

    if ($reqType === 'product') {
        $category = 'Merchandise Product';
        $sourceModule = 'Merchandise';
        if (empty($requestData['product_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Product name is required.']);
            exit;
        }
        if (empty($requestData['category'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Category is required.']);
            exit;
        }
        if (empty($requestData['unit'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unit is required.']);
            exit;
        }
    } elseif ($reqType === 'service_type') {
        $category = 'Service Type';
        $sourceModule = 'Job Order';
        if (empty($requestData['service_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Service name is required.']);
            exit;
        }
        $svc_cat = $requestData['service_category'] ?? $requestData['category'] ?? '';
        if (empty($svc_cat)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Category is required.']);
            exit;
        }
        $svc_price = (float)($requestData['default_price'] ?? $requestData['suggested_price'] ?? 0);
        if ($svc_price < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid service fee is required.']);
            exit;
        }
    } elseif ($reqType === 'vehicle_type') {
        $category = 'Vehicle';
        $sourceModule = 'Job Order';
        if (empty($requestData['vehicle_brand'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle brand is required.']);
            exit;
        }
        if (empty($requestData['vehicle_model'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle model is required.']);
            exit;
        }
        if (empty($requestData['vehicle_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle type is required.']);
            exit;
        }
        if (empty($requestData['fuel_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Fuel type is required.']);
            exit;
        }
    } elseif ($reqType === 'inspection_item') {
        $category = 'Inspection Item';
        $sourceModule = 'Vehicle Inspection';
        if (empty($requestData['item_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Inspection item name is required.']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request type.']);
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

    $isAdmin = in_array($role, ['admin', 'superadmin'], true);

    if ($isAdmin) {
        // ── DIRECT AUTO-APPROVAL FOR ADMIN ──────────────────────────────
        $stmt = $pdo->prepare("
            INSERT INTO master_data_requests 
                (category, source_module, requested_by, reviewed_by, station_id, status, data_payload, created_at, updated_at)
            VALUES 
                (?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
        ");
        $stmt->execute([
            $category,
            $sourceModule,
            $me['id'],
            $me['id'],
            $stationId,
            json_encode($requestData)
        ]);

        $requestId = $pdo->lastInsertId();
        $requestNo = sprintf('MDR-%05d', $requestId);

        $update = $pdo->prepare("UPDATE master_data_requests SET request_no = ? WHERE id = ?");
        $update->execute([$requestNo, $requestId]);

        // Insert into production tables immediately
        $newId = null;
        $reqStationId = !empty($stationId) ? (int)$stationId : 1;

        if ($reqType === 'product') {
            $productName = trim($requestData['product_name'] ?? '');
            $prodCategory = trim($requestData['category'] ?? 'Lubricants');
            $brand = trim($requestData['brand'] ?? 'Generic');
            if ($brand === '' || strtolower($brand) === 'n/a') $brand = 'Generic';
            $unitVal = trim($requestData['unit'] ?? $requestData['size'] ?? 'pcs');
            if ($unitVal === '' || strtolower($unitVal) === 'n/a') $unitVal = 'pcs';

            $sku = trim($requestData['sku'] ?? '');
            if ($sku === '' || strtolower($sku) === 'n/a') {
                $sku = 'SKU-' . strtoupper(substr(md5($productName . time()), 0, 8));
            }

            $unitPrice = floatval(
                $requestData['unit_price'] ??
                $requestData['selling_price'] ??
                $requestData['suggested_price'] ??
                $requestData['price'] ??
                0.00
            );
            $unitCost = floatval($requestData['unit_cost'] ?? $requestData['cost'] ?? 0.00);
            if ($unitCost <= 0.00 && $unitPrice > 0.00) {
                $unitCost = round($unitPrice * 0.70, 2);
            }

            $reorderLevel = (int)($requestData['reorder_level'] ?? 24);
            $criticalLevel = (int)($requestData['critical_level'] ?? 10);

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
                if (!empty($requestData['barcode'])) {
                    $ipData['barcode'] = $requestData['barcode'];
                }
                if (!empty($requestData['expiration_date'])) {
                    $ipData['expiration_date'] = $requestData['expiration_date'];
                }

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

                if ($newId > 0 && (empty($requestData['sku']) || strtolower($requestData['sku']) === 'n/a')) {
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
                            'expiration_date'=> !empty($requestData['expiration_date']) ? $requestData['expiration_date'] : null,
                            'last_updated'   => '__NOW__',
                            'created_at'     => '__NOW__'
                        ];
                        safe_dynamic_insert($pdo, 'station_inventory', $siData);
                    }
                } catch (Exception $e) {}
            }

        } elseif ($reqType === 'service_type') {
            $serviceName = trim($requestData['service_name'] ?? '');
            $serviceCategory = trim($requestData['service_category'] ?? $requestData['category'] ?? 'General');
            $serviceKey  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $serviceName));
            $serviceKey  = trim($serviceKey, '_');
            if ($serviceKey === '') $serviceKey = 'svc_' . time();
            $baseKey = $serviceKey;
            $sfx = 1;
            while (true) {
                $chkKey = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key = ? AND station_id = ? LIMIT 1");
                $chkKey->execute([$serviceKey, $stationId]);
                if (!$chkKey->fetch()) break;
                $serviceKey = $baseKey . '_' . $sfx++;
            }

            $suggestedPrice = floatval(
                $requestData['suggested_price'] ??
                $requestData['default_price'] ??
                $requestData['service_price'] ??
                $requestData['price'] ??
                0.00
            );
            $laborFee = floatval($requestData['labor_fee'] ?? $suggestedPrice);
            $duration = !empty($requestData['estimated_duration']) ? (int)$requestData['estimated_duration'] : null;
            $pricingNotes = $requestData['pricing_notes'] ?? $requestData['estimated_duration'] ?? $requestData['remarks'] ?? null;
            $description = $requestData['description'] ?? $requestData['remarks'] ?? null;

            $existingSvcId = 0;
            try {
                $chkSvc = $pdo->prepare("SELECT id FROM job_order_service_types WHERE station_id = ? AND LOWER(TRIM(service_name)) = LOWER(TRIM(?)) LIMIT 1");
                $chkSvc->execute([$stationId, $serviceName]);
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
                    $sortStmt->execute([$stationId]);
                    $nextSortOrder = (int)$sortStmt->fetchColumn() ?: 1;
                } catch (Exception $e) {}

                $svcData = [
                    'station_id'         => $stationId,
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
                    'submitted_by'       => $me['id'],
                    'reviewed_by'        => $me['id'],
                    'created_at'         => '__NOW__',
                    'updated_at'         => '__NOW__'
                ];

                $newId = safe_dynamic_insert($pdo, 'job_order_service_types', $svcData);
            }

        } elseif ($reqType === 'inspection_item') {
            $itemName = trim($requestData['item_name'] ?? '');
            $description = trim($requestData['description'] ?? '');
            $cat = trim($requestData['category'] ?? 'General');
            $isActive = isset($requestData['is_active']) ? (int)$requestData['is_active'] : 1;

            $existingId = 0;
            try {
                $dup = $pdo->prepare("SELECT id FROM vehicle_inspection_items WHERE station_id = ? AND LOWER(TRIM(item_name)) = LOWER(TRIM(?)) LIMIT 1");
                $dup->execute([$stationId, $itemName]);
                $existingId = (int)$dup->fetchColumn();
            } catch (Exception $e) {}

            if ($existingId > 0) {
                $newId = $existingId;
            } else {
                $inspData = [
                    'station_id'   => $stationId,
                    'item_name'    => $itemName,
                    'description'  => $description ?: null,
                    'category'     => $cat ?: 'General',
                    'is_active'    => $isActive,
                    'created_by'   => $me['id'],
                    'reviewed_by'  => $me['id'],
                    'created_at'   => '__NOW__',
                    'updated_at'   => '__NOW__'
                ];
                $newId = safe_dynamic_insert($pdo, 'vehicle_inspection_items', $inspData);
            }

        } elseif ($reqType === 'vehicle_type') {
            $vBrand = trim($requestData['vehicle_brand'] ?? '');
            $vModel = trim($requestData['vehicle_model'] ?? '');
            $vType  = trim($requestData['vehicle_type'] ?? 'Sedan');
            $vFuel  = trim($requestData['fuel_type'] ?? 'Gasoline');
            $vehicleName = trim(($vBrand ? $vBrand . ' ' : '') . $vModel);
            if ($vehicleName === '') $vehicleName = 'Standard Vehicle';

            $existingVehId = 0;
            try {
                $dupV = $pdo->prepare("SELECT id FROM vehicle_types WHERE station_id = ? AND LOWER(TRIM(vehicle_name)) = LOWER(TRIM(?)) LIMIT 1");
                $dupV->execute([$stationId, $vehicleName]);
                $existingVehId = (int)$dupV->fetchColumn();
            } catch (Exception $e) {}

            if ($existingVehId > 0) {
                $newId = $existingVehId;
            } else {
                $vehData = [
                    'station_id'    => $stationId,
                    'category'      => $vType,
                    'vehicle_name'  => $vehicleName,
                    'vehicle_type'  => $vType,
                    'vehicle_brand' => $vBrand ?: null,
                    'vehicle_model' => $vModel ?: null,
                    'fuel_type'     => $vFuel ?: null,
                    'status'        => 'approved',
                    'submitted_by'  => $me['id'],
                    'reviewed_by'   => $me['id'],
                    'created_by'    => $me['id'],
                    'is_active'     => 1,
                    'created_at'    => '__NOW__',
                    'updated_at'    => '__NOW__'
                ];
                $newId = safe_dynamic_insert($pdo, 'vehicle_types', $vehData);
            }
        }

        $pdo->commit();

            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], "Admin Direct Master Data Add", "Category: {$category} | Request: {$requestNo}");
            }

            echo json_encode([
                'success'       => true,
                'auto_approved' => true,
                'request_id'    => $requestId,
                'request_no'    => $requestNo,
                'category'      => $category,
                'new_record_id' => $newId ?? null,
                'message'       => "{$category} added and automatically approved successfully."
            ]);
            exit;
        }

        // ── STANDARD PENDING SUBMISSION FOR REGULAR STAFF ───────────────────
        $stmt = $pdo->prepare("
            INSERT INTO master_data_requests 
                (category, source_module, requested_by, station_id, status, data_payload, created_at)
            VALUES 
                (?, ?, ?, ?, 'Pending', ?, NOW())
        ");
        $stmt->execute([
            $category,
            $sourceModule,
            $me['id'],
            $stationId,
            json_encode($requestData)
        ]);

        $requestId = $pdo->lastInsertId();
        $requestNo = sprintf('MDR-%05d', $requestId);

        $update = $pdo->prepare("UPDATE master_data_requests SET request_no = ? WHERE id = ?");
        $update->execute([$requestNo, $requestId]);

        $pdo->commit();

        // Send notification to Managers
        try {
            $requestedItem = '';
            if ($reqType === 'product') $requestedItem = $requestData['product_name'] ?? '';
            elseif ($reqType === 'service_type') $requestedItem = $requestData['service_name'] ?? '';
            elseif ($reqType === 'inspection_item') $requestedItem = $requestData['item_name'] ?? '';
            else $requestedItem = ($requestData['vehicle_brand'] ?? '') . ' ' . ($requestData['vehicle_model'] ?? '');

            $requesterName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
            if (empty($requesterName)) $requesterName = $me['name'] ?? 'Staff';

            // ── Notify manager(s) — event-driven ────────────────────────
            if ($stationId && function_exists('notify_manager')) {
                notify_manager(
                    $pdo, $stationId,
                    'info', 'master_data_request', 'medium',
                    'New Master Data Request: ' . $category,
                    "{$requesterName} requested to add {$requestedItem}. Review required.",
                    "mdr_submitted_{$requestId}",
                    'manager_request_data_management.php',
                    'master_data_request', $requestId
                );
            }
        } catch (Exception $e) {
            // Non-critical notification failure
        }

        echo json_encode([
            'success'    => true,
            'request_id' => $requestId,
            'request_no' => $requestNo,
            'message'    => 'Request submitted successfully. Waiting for manager approval.'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Handle GET: Fetch requests (for manager request management page) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status      = $_GET['status'] ?? '';
    $category    = $_GET['category'] ?? '';
    $requestedBy = $_GET['requested_by'] ?? '';
    $dateFrom    = $_GET['date_from'] ?? '';
    $dateTo      = $_GET['date_to'] ?? '';
    $search      = $_GET['search'] ?? '';

    try {
        $query = "
            SELECT 
                r.*,
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, 'Unknown Staff') as requester_name,
                COALESCE(CONCAT(rev.first_name, ' ', rev.last_name), rev.name, '') as reviewer_name
            FROM master_data_requests r
            LEFT JOIN users u ON r.requested_by = u.id
            LEFT JOIN users rev ON r.reviewed_by = rev.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($status)) {
            $query .= " AND r.status = ?";
            $params[] = $status;
        }

        if (!empty($category)) {
            $query .= " AND r.category = ?";
            $params[] = $category;
        }

        if (!empty($requestedBy)) {
            $query .= " AND r.requested_by = ?";
            $params[] = (int)$requestedBy;
        }

        if (!empty($dateFrom)) {
            $query .= " AND DATE(r.created_at) >= ?";
            $params[] = $dateFrom;
        }

        if (!empty($dateTo)) {
            $query .= " AND DATE(r.created_at) <= ?";
            $params[] = $dateTo;
        }

        if (!empty($search)) {
            $query .= " AND (r.request_no LIKE ? OR r.data_payload LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $query .= " ORDER BY r.created_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates and decode JSON payloads
        foreach ($requests as &$req) {
            $req['data_payload'] = json_decode($req['data_payload'], true);
            $req['date_submitted'] = date('M d, Y', strtotime($req['created_at']));
        }

        echo json_encode([
            'success'  => true,
            'requests' => $requests
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
