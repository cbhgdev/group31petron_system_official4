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
            // Use staff-provided SKU if present; otherwise generate one
            $sku = !empty($payload['sku'])
                ? $payload['sku']
                : ('SKU-' . strtoupper(substr(md5(($payload['product_name'] ?? '') . time()), 0, 8)));

            // Price: prefer unit_price > selling_price > suggested_price
            $unitPrice = floatval(
                $payload['unit_price'] ??
                $payload['selling_price'] ??
                $payload['suggested_price'] ??
                0.00
            );
            $unitCost = $unitPrice * 0.70;

            // Insert into inventory_products
            $pStmt = $pdo->prepare("
                INSERT INTO inventory_products
                    (product_name, category, sku, unit_price, unit_cost, selling_price, stock, stock_quantity, status, station_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, NOW(), NOW())
            ");
            $pStmt->execute([
                $payload['product_name'] ?? '',
                $payload['category']      ?? 'Lubricants',
                $sku,
                $unitPrice,
                $unitCost,
                $unitPrice,
                $reqStationId
            ]);
            $newId = $pdo->lastInsertId();

            // Also insert zero-stock station_inventory entry
            if ($newId) {
                try {
                    $siStmt = $pdo->prepare("
                        INSERT INTO station_inventory (station_id, product_id, stock_level, status, last_updated)
                        VALUES (?, ?, 0, 'active', NOW())
                    ");
                    $siStmt->execute([$reqStationId, $newId]);
                } catch (PDOException $e) { /* ignore duplicate */ }
            }

        } elseif ($category === 'Service Type') {
            $serviceName = $payload['service_name'] ?? '';
            $serviceKey  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $serviceName));
            $serviceKey  = trim($serviceKey, '_') . '_' . time();
            $suggestedPrice = isset($payload['suggested_price']) ? floatval($payload['suggested_price']) : 0.00;

            // Insert into job_order_service_types
            $sStmt = $pdo->prepare("
                INSERT INTO job_order_service_types
                    (station_id, service_key, service_name, category, service_price, pricing_notes, sort_order, status, submitted_by, reviewed_by, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM job_order_service_types j2 WHERE j2.station_id = ?), 'approved', ?, ?, 1, NOW(), NOW())
            ");
            $sStmt->execute([
                (int)$req['station_id'],
                $serviceKey,
                $serviceName,
                $payload['category'] ?? 'Others',
                $suggestedPrice,
                $payload['estimated_duration'] ?? null,
                (int)$req['station_id'],
                $req['requested_by'],
                $me['id']
            ]);
            $newId = $pdo->lastInsertId();

        } elseif ($category === 'Inspection Item') {
            $itemName = trim($payload['item_name'] ?? $payload['inspection_name'] ?? '');
            $description = trim($payload['description'] ?? '');
            $cat = trim($payload['category'] ?? 'General');
            $isActive = isset($payload['is_active']) ? (int)$payload['is_active'] : 1;

            $dup = $pdo->prepare("SELECT id FROM vehicle_inspection_items WHERE station_id = ? AND LOWER(TRIM(item_name)) = LOWER(TRIM(?))");
            $dup->execute([(int)$req['station_id'], $itemName]);
            $existingId = $dup->fetchColumn();

            if ($existingId) {
                $newId = (int)$existingId;
            } else {
                $iStmt = $pdo->prepare("
                    INSERT INTO vehicle_inspection_items
                        (station_id, item_name, description, category, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $iStmt->execute([
                    !empty($req['station_id']) ? (int)$req['station_id'] : null,
                    $itemName,
                    $description ?: null,
                    $cat ?: 'General',
                    $isActive,
                    $req['requested_by']
                ]);
                $newId = (int)$pdo->lastInsertId();
            }
        } elseif ($category === 'Vehicle') {
            $vehicleName = trim(($payload['vehicle_brand'] ?? '') . ' ' . ($payload['vehicle_model'] ?? ''));

            // Insert into vehicle_types
            $vStmt = $pdo->prepare("
                INSERT INTO vehicle_types
                    (station_id, category, vehicle_name, status, submitted_by, reviewed_by, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'approved', ?, ?, 1, NOW(), NOW())
            ");
            $vStmt->execute([
                !empty($req['station_id']) ? (int)$req['station_id'] : null,
                $payload['vehicle_type'] ?? 'Sedan',
                $vehicleName,
                $req['requested_by'],
                $me['id']
            ]);
            $newId = $pdo->lastInsertId();
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
