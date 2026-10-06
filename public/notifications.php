<?php
$page_id = 'notifications';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['staff', 'admin', 'manager', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

// Handle POST actions (mark_all_read, mark_read, archive)
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $notif_id = intval($_POST['notification_id'] ?? 0);

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'unread'");
        $stmt->execute([$me['id']]);
        unset($_SESSION['notif_bell_snoozed_' . (int)$me['id']]);
        $notice = '<i class="fas fa-check-circle"></i> All notifications marked as read.';
    } elseif ($action === 'mark_read' && $notif_id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $me['id']]);
        $notice = '<i class="fas fa-check-circle"></i> Notification marked as read.';
    } elseif ($action === 'archive' && $notif_id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'archived' WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $me['id']]);
        $notice = '<i class="fas fa-box"></i> Notification moved to archive.';
    }
}

// Filter parameters
$search       = trim($_GET['search'] ?? '');
$category     = strtolower(trim($_GET['category'] ?? 'all'));
$filter_type  = trim($_GET['type'] ?? 'all');
$filter_prio  = trim($_GET['priority'] ?? 'all');
$filter_stat  = trim($_GET['status'] ?? 'all');
$filter_shift = trim($_GET['shift'] ?? 'all');
$date_from    = trim($_GET['date_from'] ?? '');
$date_to      = trim($_GET['date_to'] ?? '');

$assigned_shift = trim($me['assigned_shift'] ?? '');

// Overall Counts
$counts = ['total' => 0, 'unread' => 0, 'read' => 0, 'archived' => 0];
try {
    $c_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
            SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_cnt,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_cnt
        FROM notifications 
        WHERE user_id = ?
    ");
    $c_stmt->execute([$me['id']]);
    $res = $c_stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $counts['total']    = (int)($res['total'] ?? 0);
        $counts['unread']   = (int)($res['unread'] ?? 0);
        $counts['read']     = (int)($res['read_cnt'] ?? 0);
        $counts['archived'] = (int)($res['archived_cnt'] ?? 0);
    }
} catch (Exception $e) {}

// Query Filter Build
$where  = ['n.user_id = ?'];
$params = [$me['id']];

// Shift filter (only apply when a specific shift is requested)
if ($filter_shift !== 'all' && $filter_shift !== '') {
    if (in_array(strtolower($filter_shift), ['shift 1', 'first shift', 'first'])) {
        $where[] = '(LOWER(n.shift_period) IN ("shift 1", "first shift", "first") OR n.shift_period IS NULL OR n.shift_period = "")';
    } elseif (in_array(strtolower($filter_shift), ['shift 2', 'second shift', 'second'])) {
        $where[] = '(LOWER(n.shift_period) IN ("shift 2", "second shift", "second") OR n.shift_period IS NULL OR n.shift_period = "")';
    } else {
        $where[]  = '(n.shift_period = ? OR n.shift_period IS NULL OR n.shift_period = "")';
        $params[] = $filter_shift;
    }
}

// Category filter
if ($category !== 'all' && $category !== '') {
    if ($category === 'fuel') {
        $where[] = "(n.event_type IN ('fuel_transaction','fuel_sales_closing','fuel_reading','fuel','fuel_stock_in') OR n.title LIKE '%Fuel%')";
    } elseif ($category === 'inventory') {
        $where[] = "(n.event_type IN ('stock_request','purchase_order','inventory','delivery','stock_in','merchandise_stock_in','fuel_stock_in') OR n.title LIKE '%Stock%' OR n.title LIKE '%Inventory%')";
    } elseif ($category === 'transactions') {
        $where[] = "(n.event_type IN ('void_request','transaction_adjustment','transaction','merchandise_transaction','job_order') OR n.title LIKE '%Void%' OR n.title LIKE '%Adjustment%' OR n.title LIKE '%Transaction%')";
    } elseif ($category === 'approvals') {
        $where[] = "(n.event_type IN ('stock_request','void_request','master_data_request','fuel_transaction','fuel_reading','stock_in','merchandise_stock_in','fuel_stock_in','transaction_adjustment') OR n.title LIKE '%Approved%' OR n.title LIKE '%Validated%' OR n.title LIKE '%Pending%' OR n.title LIKE '%Review%' OR n.title LIKE '%Returned%' OR n.title LIKE '%Adjusted%' OR n.title LIKE '%Rejected%')";
    } elseif ($category === 'master_data') {
        $where[] = "(n.event_type IN ('master_data_request','customer_request') OR n.title LIKE '%Master Data%')";
    } elseif ($category === 'system') {
        $where[] = "(n.event_type IN ('system','system_error','security','account_lockout','unauthorized_access') OR n.title LIKE '%System%' OR n.title LIKE '%Security%')";
    }
}

if ($filter_stat !== 'all' && $filter_stat !== '') {
    $where[]  = 'n.status = ?';
    $params[] = $filter_stat;
}

if ($filter_type !== 'all' && $filter_type !== '') {
    $where[]  = '(n.event_type = ? OR n.type = ?)';
    $params[] = $filter_type;
    $params[] = $filter_type;
}

if ($filter_prio !== 'all' && $filter_prio !== '') {
    $where[]  = 'n.severity = ?';
    $params[] = $filter_prio;
}

if ($search !== '') {
    $where[]  = '(n.title LIKE ? OR n.message LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($date_from !== '') {
    $where[]  = 'DATE(n.created_at) >= ?';
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[]  = 'DATE(n.created_at) <= ?';
    $params[] = $date_to;
}

$where_clause = implode(' AND ', $where);

// Pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$notifications = [];
$total_rows    = 0;

try {
    // Total count for current query
    $count_sql = "SELECT COUNT(*) FROM notifications n WHERE {$where_clause}";
    $c_stmt = $pdo->prepare($count_sql);
    $c_stmt->execute($params);
    $total_rows = (int)$c_stmt->fetchColumn();

    // Query rows
    $sql = "SELECT n.* FROM notifications n WHERE {$where_clause} ORDER BY n.created_at DESC LIMIT {$per_page} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$nr) {
        if (empty($nr['redirect_url']) && !empty($nr['reference_type'])) {
            $nr['redirect_url'] = notification_redirect_url(
                $nr['reference_type'],
                (int)($nr['reference_id'] ?? 0),
                $role
            );
        }
    }
    unset($nr);
} catch (Exception $e) {
    $notifications = [];
}

$total_pages = max(1, ceil($total_rows / $per_page));

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Modern Notification Hub Feed Styling ── */
.notif-wrapper {
    padding: 0 !important;
    background: #f8fafc;
    min-height: calc(100vh - 70px);
}

.notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0 !important;
    margin-bottom: 24px !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
    flex-wrap: wrap;
    gap: 16px;
}

.notif-title-area h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 22px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}

.notif-title-area p {
    margin: 4px 0 0 0;
    color: #64748b;
    font-size: 13.5px;
}



/* Filter Container */
.notif-filter-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.notif-filter-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}

@media (max-width: 1200px) {
    .notif-filter-form { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .notif-filter-form { grid-template-columns: 1fr; }
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.filter-group input, .filter-group select {
    height: 36px;
    padding: 0 10px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 12.5px;
    color: #1e293b;
    outline: none;
    transition: border-color 0.15s ease;
    background: #fff;
}

.filter-group input:focus, .filter-group select:focus {
    border-color: #002F6C;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.1);
}

.btn-filter-submit {
    height: 36px;
    padding: 0 16px;
    background: #002F6C;
    color: #ffffff;
    border: 1px solid #002F6C;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12.5px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.btn-filter-submit:hover { background: #001f4d; border-color: #001f4d; }

.btn-filter-reset {
    height: 36px;
    padding: 0 14px;
    background: #ffffff;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12.5px;
    text-decoration: none !important;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.btn-filter-reset:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

/* ── Modern Notification Feed List ── */
.notif-feed-container {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
}

.notif-date-divider {
    padding: 10px 20px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 8px;
}
.notif-date-divider:first-child {
    border-top: none;
}

.notif-feed-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    position: relative;
    text-decoration: none !important;
}

.notif-feed-item:last-child {
    border-bottom: none;
}

.notif-feed-item:hover {
    background: #f8fafc !important;
}

.notif-feed-item.is-unread {
    background: #f0f7ff;
}

.notif-feed-item.is-read {
    background: #ffffff;
}

.notif-item-icon-wrap {
    flex-shrink: 0;
}

.notif-type-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.icon-fuel { background: #eff6ff; color: #2563eb; }
.icon-inventory { background: #fef3c7; color: #d97706; }
.icon-transaction { background: #f0fdf4; color: #16a34a; }
.icon-job_order { background: #f5f3ff; color: #7c3aed; }
.icon-delivery { background: #ecfeff; color: #0891b2; }
.icon-customer { background: #fdf2f8; color: #db2777; }
.icon-system { background: #fee2e2; color: #dc2626; }
.icon-general { background: #f1f5f9; color: #475569; }

.notif-item-content {
    flex-grow: 1;
    min-width: 0;
}

.notif-item-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 3px;
}

.notif-item-title {
    display: inline-flex;
    align-items: center;
    line-height: 1.3;
}

.notif-feed-item.is-unread .notif-item-title {
    font-size: 14.5px;
    font-weight: 700;
    color: #002244;
}

.notif-feed-item.is-read .notif-item-title {
    font-size: 14px;
    font-weight: 500;
    color: #334155;
}

.unread-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #2563eb;
    margin-right: 8px;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
}

.notif-item-time {
    font-size: 11.5px;
    color: #94a3b8;
    white-space: nowrap;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

.notif-item-msg {
    margin: 2px 0 6px;
    font-size: 13px;
    color: #64748b;
    line-height: 1.4;
    word-break: break-word;
}

.notif-feed-item.is-unread .notif-item-msg {
    color: #334155;
}

.notif-item-footer {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.notif-tag {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #475569;
    text-transform: capitalize;
}

.prio-tag {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
}
.prio-low { background: #e0f2fe; color: #0369a1; }
.prio-medium { background: #ffedd5; color: #c2410c; }
.prio-high { background: #fee2e2; color: #b91c1c; }
.prio-critical { background: #7f1d1d; color: #ffffff; }

.shift-tag {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 6px;
    background: #faf5ff;
    color: #6b21a8;
    border: 1px solid #f3e8ff;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.notif-item-arrow {
    flex-shrink: 0;
    color: #cbd5e1;
    font-size: 13px;
    transition: transform 0.15s ease, color 0.15s ease;
    margin-left: 8px;
}

.notif-feed-item:hover .notif-item-arrow {
    color: #002F6C;
    transform: translateX(3px);
}

.notif-empty-state {
    text-align: center;
    padding: 56px 20px;
    color: #94a3b8;
}

.notif-empty-state i {
    font-size: 40px;
    margin-bottom: 14px;
    display: block;
    color: #cbd5e1;
}

.notif-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

/* Modal Dialog */
.modal-backdrop-custom {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,15,35,0.6);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 999999;
    padding: 16px;
}

.modal-content-custom {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 16px 40px rgba(0,0,0,0.2);
    overflow: hidden;
    animation: modalPop 0.2s cubic-bezier(0.16,1,0.3,1);
    border: 1px solid #e2e8f0;
}

.modal-hdr {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-hdr h3 {
    font-size: 15px;
    font-weight: 700;
    color: #002244;
    margin: 0;
}

.modal-body {
    padding: 20px;
}

.modal-ftr {
    padding: 12px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<div class="notif-wrapper">

    <!-- Top Header -->
    <div class="notif-header">
        <div class="notif-title-area">
            <h1><i class="fas fa-bell"></i> Notifications Hub</h1>
        </div>
        <div>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-filter-reset" style="background-color: #f0fdf4 !important; color: #166534 !important; border: 1px solid #bbf7d0 !important; font-weight: 700;">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form>
        </div>
    </div>

    <?php if ($notice): ?>
        <div style="padding:12px 18px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px; margin-bottom:20px; font-weight:600; font-size:13.5px;">
            <?= htmlspecialchars($notice) ?>
        </div>
    <?php endif; ?>





    <!-- Summary Count Banners -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 22px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Total Notifications</div>
                <div style="font-size: 22px; font-weight: 800; color: #002F6C; margin-top: 2px;"><?= number_format($counts['total']) ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(0,47,108,0.08); display: flex; align-items: center; justify-content: center; color: #002F6C; font-size: 18px;">
                <i class="fas fa-bell"></i>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Unread</div>
                <div style="font-size: 22px; font-weight: 800; color: #dc2626; margin-top: 2px;"><?= number_format($counts['unread']) ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(220,38,38,0.08); display: flex; align-items: center; justify-content: center; color: #dc2626; font-size: 18px;">
                <i class="fas fa-envelope"></i>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Read</div>
                <div style="font-size: 22px; font-weight: 800; color: #16a34a; margin-top: 2px;"><?= number_format($counts['read']) ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(22,163,74,0.08); display: flex; align-items: center; justify-content: center; color: #16a34a; font-size: 18px;">
                <i class="fas fa-check-circle"></i>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Archived</div>
                <div style="font-size: 22px; font-weight: 800; color: #64748b; margin-top: 2px;"><?= number_format($counts['archived']) ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(100,116,139,0.08); display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 18px;">
                <i class="fas fa-archive"></i>
            </div>
        </div>
    </div>
    <div class="notif-filter-box">
        <form method="GET" class="notif-filter-form">
            <?php if ($category !== 'all'): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
            <?php endif; ?>
            <?php if ($filter_shift !== 'all'): ?>
                <input type="hidden" name="shift" value="<?= htmlspecialchars($filter_shift) ?>">
            <?php endif; ?>
            
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" placeholder="Search keyword..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Type</label>
                <select name="type">
                    <option value="all">All Types</option>
                    <option value="transaction" <?= $filter_type === 'transaction' ? 'selected' : '' ?>>Transaction</option>
                    <option value="job_order" <?= $filter_type === 'job_order' ? 'selected' : '' ?>>Job Order</option>
                    <option value="fuel_transaction" <?= in_array($filter_type, ['fuel_transaction','fuel_management','fuel']) ? 'selected' : '' ?>>Fuel Management</option>
                    <option value="inventory" <?= $filter_type === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                    <option value="customer" <?= $filter_type === 'customer' ? 'selected' : '' ?>>Customer</option>
                    <option value="delivery" <?= $filter_type === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                    <option value="report" <?= $filter_type === 'report' ? 'selected' : '' ?>>Report</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-exclamation-circle"></i> Priority</label>
                <select name="priority">
                    <option value="all">All Priorities</option>
                    <option value="low" <?= $filter_prio === 'low' ? 'selected' : '' ?>>Low</option>
                    <option value="medium" <?= $filter_prio === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="high" <?= $filter_prio === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="critical" <?= $filter_prio === 'critical' ? 'selected' : '' ?>>Critical</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Status</label>
                <select name="status">
                    <option value="all">All Statuses</option>
                    <option value="unread" <?= $filter_stat === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read" <?= $filter_stat === 'read' ? 'selected' : '' ?>>Read</option>
                    <option value="archived" <?= $filter_stat === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn-filter-submit"><i class="fas fa-filter"></i> Filter</button>
                <a href="notifications.php" class="btn-filter-reset"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>

    <!-- ── Modern Notification Feed Feed/Timeline Card ── -->
    <div class="notif-feed-container">
        <?php if (empty($notifications)): ?>
            <div class="notif-empty-state">
                <i class="fas fa-bell-slash"></i>
                <div style="font-size: 15px; font-weight: 600; color: #475569; margin-bottom: 4px;">No notifications found</div>
                <div style="font-size: 13px; color: #94a3b8;">No records match your selected filters or search keyword.</div>
            </div>
        <?php else: ?>
            <?php 
            // Helper function for smart relative time
            $format_feed_time = function(string $dt): string {
                $diff = max(0, time() - strtotime($dt));
                if ($diff < 60)     return 'Just now';
                if ($diff < 3600)   return floor($diff / 60) . ' min ago';
                if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
                if ($diff < 172800) return 'Yesterday';
                if ($diff < 604800) return floor($diff / 86400) . ' days ago';
                return date('M j, Y', strtotime($dt));
            };

            // Group notifications by Date (Today, Yesterday, or Specific Date)
            $grouped_notifs = [];
            foreach ($notifications as $n) {
                $item_date = date('Y-m-d', strtotime($n['created_at']));
                $today     = date('Y-m-d');
                $yest      = date('Y-m-d', strtotime('-1 day'));

                if ($item_date === $today) {
                    $grp = 'Today';
                } elseif ($item_date === $yest) {
                    $grp = 'Yesterday';
                } else {
                    $grp = date('F j, Y', strtotime($n['created_at']));
                }
                $grouped_notifs[$grp][] = $n;
            }

            foreach ($grouped_notifs as $group_label => $items):
            ?>
                <div class="notif-date-divider">
                    <span><?= htmlspecialchars($group_label) ?></span>
                </div>

                <?php foreach ($items as $n): 
                    $is_unread = ($n['status'] === 'unread');
                    $prio = strtolower($n['severity'] ?? 'medium');
                    $ev_type = strtolower($n['event_type'] ?? 'general');
                    $type_label = ucwords(str_replace('_', ' ', $n['event_type'] ?? 'General'));
                    $redirect = $n['redirect_url'] ?? '';

                    // Determine icon class
                    $icon_class = 'icon-general';
                    $fa_icon = 'fas fa-bell';
                    if (strpos($ev_type, 'fuel') !== false) {
                        $icon_class = 'icon-fuel'; $fa_icon = 'fas fa-gas-pump';
                    } elseif (strpos($ev_type, 'inventory') !== false || strpos($ev_type, 'stock') !== false) {
                        $icon_class = 'icon-inventory'; $fa_icon = 'fas fa-boxes';
                    } elseif (strpos($ev_type, 'transaction') !== false) {
                        $icon_class = 'icon-transaction'; $fa_icon = 'fas fa-receipt';
                    } elseif (strpos($ev_type, 'job') !== false) {
                        $icon_class = 'icon-job_order'; $fa_icon = 'fas fa-tools';
                    } elseif (strpos($ev_type, 'delivery') !== false) {
                        $icon_class = 'icon-delivery'; $fa_icon = 'fas fa-truck-loading';
                    } elseif (strpos($ev_type, 'customer') !== false) {
                        $icon_class = 'icon-customer'; $fa_icon = 'fas fa-users';
                    } elseif (strpos($ev_type, 'system') !== false || strpos($ev_type, 'security') !== false) {
                        $icon_class = 'icon-system'; $fa_icon = 'fas fa-shield-alt';
                    }
                ?>
                    <div class="notif-feed-item <?= $is_unread ? 'is-unread' : 'is-read' ?>" 
                         id="notif-row-<?= $n['id'] ?>"
                         data-id="<?= $n['id'] ?>"
                         data-redirect="<?= htmlspecialchars($redirect) ?>"
                         data-unread="<?= $is_unread ? '1' : '0' ?>"
                         onclick="handleFeedItemClick(this, <?= (int)$n['id'] ?>, '<?= htmlspecialchars(addslashes($redirect)) ?>', <?= htmlspecialchars(json_encode($n)) ?>)">

                        <!-- Left Icon -->
                        <div class="notif-item-icon-wrap">
                            <div class="notif-type-icon <?= $icon_class ?>">
                                <i class="<?= $fa_icon ?>"></i>
                            </div>
                        </div>

                        <!-- Content Area -->
                        <div class="notif-item-content">
                            <div class="notif-item-header">
                                <span class="notif-item-title">
                                    <?php if ($is_unread): ?>
                                        <span class="unread-dot" title="Unread"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars(function_exists('clean_mojibake') ? clean_mojibake($n['title']) : $n['title']) ?>
                                </span>
                                <span class="notif-item-time">
                                    <i class="far fa-clock"></i> <?= $format_feed_time($n['created_at']) ?>
                                </span>
                            </div>

                            <p class="notif-item-msg">
                                <?= htmlspecialchars(function_exists('clean_mojibake') ? clean_mojibake($n['message']) : $n['message']) ?>
                            </p>

                            <div class="notif-item-footer">
                                <span class="notif-tag">
                                    <?= htmlspecialchars($type_label) ?>
                                </span>
                                <?php if (!empty($prio) && $prio !== 'low'): ?>
                                    <span class="prio-tag prio-<?= $prio ?>">
                                        <i class="fas fa-circle" style="font-size:5px;"></i> <?= ucfirst($prio) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($n['shift_period'])): ?>
                                    <span class="shift-tag">
                                        <i class="fas fa-user-clock" style="font-size:9.5px;"></i> <?= htmlspecialchars($n['shift_period']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Chevron Arrow -->
                        <div class="notif-item-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Pagination Bar -->
        <?php if ($total_pages > 1): ?>
            <div class="notif-pagination">
                <div style="font-size: 12.5px; color: #64748b;">
                    Showing page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong> (<?= number_format($total_rows) ?> records)
                </div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn-filter-reset" style="height:32px; padding:0 12px; font-size:12px;">&laquo; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn-filter-reset" style="height:32px; padding:0 12px; font-size:12px;">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

</div>

<!-- Modal Dialog -->
<div id="notifModal" class="modal-backdrop-custom">
    <div class="modal-content-custom">
        <div class="modal-hdr">
            <h3 id="modalTitle">Notification Details</h3>
            <button type="button" onclick="closeNotifModal()" style="background:none;border:none;font-size:22px;color:#64748b;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                <span id="modalPriority" class="prio-tag prio-medium">Medium</span>
                <span id="modalDate" style="font-size:12px; color:#64748b;">Jul 29, 2026 12:00 PM</span>
            </div>
            <h4 id="modalHeading" style="font-size:15px; font-weight:700; color:#002244; margin:0 0 8px;">Title</h4>
            <p id="modalMessage" style="font-size:13.5px; color:#334155; line-height:1.5; margin:0 0 16px;">Message body...</p>
        </div>
        <div class="modal-ftr">
            <button type="button" onclick="closeNotifModal()" class="btn-filter-reset" style="height:34px; padding:0 14px; font-size:12px; cursor:pointer;">Close</button>
            <a id="modalActionBtn" href="#" class="btn-filter-submit" style="display:none; text-decoration:none; height:34px; padding:0 14px; font-size:12px;">
                <i class="fas fa-external-link-alt"></i> Open Related Record
            </a>
        </div>
    </div>
</div>

<script>
function handleFeedItemClick(el, notifId, redirectUrl, nData) {
    const isUnread = el && el.classList.contains('is-unread');

    // 1. If currently unread, immediately mark as read in UI & database
    if (isUnread) {
        el.classList.remove('is-unread');
        el.classList.add('is-read');

        // Remove blue unread dot
        const dot = el.querySelector('.unread-dot');
        if (dot) dot.remove();

        // Update title font styling
        const titleEl = el.querySelector('.notif-item-title');
        if (titleEl) {
            titleEl.style.fontWeight = '500';
            titleEl.style.color = '#334155';
        }

        // Decrement header bell badge — target the correct element by ID
        document.querySelectorAll('#notificationBadge, .header-notif-badge, .notif-badge-count, #headerNotifBadge').forEach(b => {
            let cnt = parseInt(b.textContent.replace(/\D/g, ''), 10) || 0;
            cnt = Math.max(0, cnt - 1);
            if (cnt > 0) {
                b.textContent = cnt > 99 ? '99+' : cnt;
                b.style.display = 'flex';
            } else {
                b.textContent = '';
                b.style.display = 'none';
            }
        });

        // Persist mark-as-read to DB using keepalive fetch (survives page navigation)
        // + sendBeacon fallback for maximum reliability
        const apiUrl = (window.pageData && window.pageData.appBasePath)
            ? window.pageData.appBasePath + '/backend/api/notifications_api.php'
            : '../backend/api/notifications_api.php';
        const beaconUrl = apiUrl + '?action=mark_read';
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', notifId);

        let sent = false;
        try {
            // Primary: keepalive fetch — persists even when page navigates away
            fetch(beaconUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                keepalive: true
            });
            sent = true;
        } catch (e) {}

        // Fallback: sendBeacon (fire-and-forget, no credentials needed for same-origin)
        if (!sent && navigator.sendBeacon) {
            navigator.sendBeacon(beaconUrl, formData);
        }
    }

    // 2. Navigate to the related module/record
    // Smart fallback: if no redirect_url, guess based on event_type + role
    const role = (window.pageData && window.pageData.role) ? window.pageData.role : 'staff';
    const evType = (nData && nData.event_type) ? nData.event_type.toLowerCase() : '';
    const notifTitle = (nData && nData.title) ? nData.title.toLowerCase() : '';

    const managerFallback = {
        'fuel_transaction'  : 'manager_fuel_transaction_validation.php',
        'fuel_reading'      : 'manager_fuel_transaction_validation.php',
        'fuel_management'   : 'manager_inventory_fuel.php',
        'inventory'         : 'manager_inventory_merchandise.php',
        'stock_request'     : 'manager_stock_request_review.php?tab=pending_requests',
        'job_order'         : 'manager_validated_transactions.php',
        'delivery'          : 'manager_merchandise_deliveries.php',
        'customer'          : 'manager_customers.php',
        'calendar'          : 'manager_calendar.php',
        'report'            : 'manager_reports.php',
        'transaction'       : 'manager_validated_transactions.php',
        'general'           : 'manager_fuel_transaction_validation.php'
    };
    const adminFallback = {
        'fuel_transaction'  : 'admin_fuel_management.php',
        'fuel_management'   : 'admin_fuel_management.php',
        'inventory'         : 'admin_stock_in.php',
        'transaction'       : 'manager_validated_transactions.php',
        'job_order'         : 'manager_validated_transactions.php',
        'delivery'          : 'admin_deliveries_oversight.php',
        'general'           : 'admin_fuel_management.php'
    };
    const staffFallback = {
        'fuel_transaction'  : 'staff_fuel_sales_closing.php',
        'fuel_management'   : 'staff_fuel_sales_closing.php',
        'inventory'         : 'staff_inventory_merchandise.php',
        'stock_request'     : 'staff_my_requests.php',
        'general'           : 'staff_fuel_sales_closing.php'
    };

    // Title-based overrides for the manager (handles "general" event_type with specific titles)
    let titleFallback = null;
    if (role === 'manager') {
        if (notifTitle.includes('fuel reading') || notifTitle.includes('fuel meter') || notifTitle.includes('fuel transaction') || notifTitle.includes('pending validation')) {
            titleFallback = 'manager_fuel_transaction_validation.php';
        } else if (notifTitle.includes('stock request') || notifTitle.includes('new stock')) {
            titleFallback = 'manager_stock_request_review.php?tab=pending_requests';
        } else if (notifTitle.includes('adjustment') || notifTitle.includes('job order')) {
            titleFallback = 'manager_validated_transactions.php';
        } else if (notifTitle.includes('delivery')) {
            titleFallback = 'manager_merchandise_deliveries.php';
        } else if (notifTitle.includes('customer')) {
            titleFallback = 'manager_customers.php';
        } else if (notifTitle.includes('inventory') || notifTitle.includes('stock alert')) {
            titleFallback = 'manager_inventory_merchandise.php';
        } else if (notifTitle.includes('fuel') || notifTitle.includes('low fuel')) {
            titleFallback = 'manager_inventory_fuel.php';
        }
    }

    let finalUrl = redirectUrl && redirectUrl.trim() !== '' && redirectUrl !== '#' && redirectUrl !== 'null'
        ? redirectUrl
        : (titleFallback || (role === 'manager' ? managerFallback[evType] : (role === 'admin' ? adminFallback[evType] : staffFallback[evType])) || null);

    if (finalUrl) {
        const targetUrl = window.resolveRedirectUrl ? window.resolveRedirectUrl(finalUrl) : finalUrl;
        // Small delay (150ms) ensures keepalive fetch has time to start before navigation
        setTimeout(() => {
            window.location.href = targetUrl;
        }, 150);
    } else {
        openNotifModal(nData);
    }
}



function openNotifModal(n) {
    document.getElementById('modalTitle').textContent = (n.event_type || 'Notification').toUpperCase().replace('_', ' ');
    document.getElementById('modalHeading').textContent = n.title || 'Notification';
    document.getElementById('modalMessage').textContent = n.message || '';
    
    const prioEl = document.getElementById('modalPriority');
    const prio = (n.severity || 'medium').toLowerCase();
    prioEl.className = `prio-tag prio-${prio}`;
    prioEl.innerHTML = `<i class="fas fa-circle" style="font-size:5px;"></i> ${prio.toUpperCase()}`;

    const d = new Date(n.created_at);
    document.getElementById('modalDate').textContent = d.toLocaleString();

    const actionBtn = document.getElementById('modalActionBtn');
    if (n.redirect_url && n.redirect_url.trim() !== '' && n.redirect_url !== '#' && n.redirect_url !== 'null') {
        actionBtn.href = window.resolveRedirectUrl ? window.resolveRedirectUrl(n.redirect_url) : n.redirect_url;
        actionBtn.style.display = 'inline-flex';
    } else {
        actionBtn.style.display = 'none';
    }

    document.getElementById('notifModal').style.display = 'flex';
}

function closeNotifModal() {
    document.getElementById('notifModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('notifModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeNotifModal();
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
