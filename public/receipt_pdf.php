<?php
require_once __DIR__ . '/../backend/lib.php';
petron_init_dynamic_timezone();
/**
 * PDF receipt renderer.
 *
 * The browser receipt (receipt.php) remains the source of truth for loading the
 * transaction. This file captures those variables, then renders mPDF-safe HTML.
 */
require_once __DIR__ . '/../vendor/autoload.php';

ob_start();
require __DIR__ . '/receipt.php';
$receipt_html = (string) ob_get_clean();

if (stripos($receipt_html, 'Receipt Not Found') !== false) {
    http_response_code(404);
    echo $receipt_html;
    exit;
}

function rp_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rp_money($value): string
{
    return '&#8369;' . number_format((float) $value, 2);
}

function rp_local_img_path(?string $src): string
{
    $src = trim((string) $src);
    if ($src === '' || preg_match('#^https?://#i', $src)) {
        return '';
    }

    $path = parse_url($src, PHP_URL_PATH) ?: $src;
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    if (strpos($path, DIRECTORY_SEPARATOR . 'group31petron_system_official4' . DIRECTORY_SEPARATOR) === 0) {
        $path = substr($path, strlen(DIRECTORY_SEPARATOR . 'group31petron_system_official4'));
    }

    $full = strpos($path, DIRECTORY_SEPARATOR) === 0
        ? realpath(dirname(__DIR__) . $path)
        : realpath(__DIR__ . DIRECTORY_SEPARATOR . $path);

    if (!$full || !is_file($full)) {
        return '';
    }

    return $full;
}

function rp_row(string $label, $value, bool $bold = false, string $extra = ''): string
{
    $strong = $bold ? ' bold' : '';
    return '<tr class="kv ' . $extra . '"><td class="key">' . rp_e($label) . '</td><td class="val' . $strong . '">' . $value . '</td></tr>';
}

if (!function_exists('rp_qr_png')) {
function rp_qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        if ((($y >> $i) & 1) !== 0) {
            $z ^= $x;
        }
    }
    return $z;
}

function rp_qr_rs_generator(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = rp_qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = rp_qr_gf_mul($root, 0x02);
    }
    return $result;
}

function rp_qr_rs_remainder(array $data, int $degree): array
{
    $gen = rp_qr_rs_generator($degree);
    $rem = array_fill(0, $degree, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $rem[0];
        array_shift($rem);
        $rem[] = 0;
        for ($i = 0; $i < $degree; $i++) {
            $rem[$i] ^= rp_qr_gf_mul($gen[$i], $factor);
        }
    }
    return $rem;
}

function rp_qr_set(array &$modules, array &$reserved, int $x, int $y, bool $dark, bool $is_function = true): void
{
    $size = count($modules);
    if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
        return;
    }
    $modules[$y][$x] = $dark;
    if ($is_function) {
        $reserved[$y][$x] = true;
    }
}

function rp_qr_finder(array &$modules, array &$reserved, int $x, int $y): void
{
    for ($dy = -1; $dy <= 7; $dy++) {
        for ($dx = -1; $dx <= 7; $dx++) {
            $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
            rp_qr_set($modules, $reserved, $x + $dx, $y + $dy, $dark);
        }
    }
}

function rp_qr_alignment(array &$modules, array &$reserved, int $cx, int $cy): void
{
    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            rp_qr_set($modules, $reserved, $cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
        }
    }
}

function rp_qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) !== 0;
    }
}

function rp_qr_format_bits(): int
{
    $data = 1 << 3; // ECC L, mask 0
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem <<= 1;
        if ((($rem >> 10) & 1) !== 0) {
            $rem ^= 0x537;
        }
    }
    return (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
}

function rp_qr_png(string $text): string
{
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }

    $size = 37;              // QR version 5
    $data_codewords = 108;   // Version 5-L
    $ecc_codewords = 26;
    $text = substr($text, 0, 106);

    $bits = [];
    rp_qr_append_bits($bits, 0x4, 4);
    rp_qr_append_bits($bits, strlen($text), 8);
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        rp_qr_append_bits($bits, ord($text[$i]), 8);
    }
    $capacity_bits = $data_codewords * 8;
    for ($i = 0, $n = min(4, $capacity_bits - count($bits)); $i < $n; $i++) {
        $bits[] = false;
    }
    while ((count($bits) % 8) !== 0) {
        $bits[] = false;
    }

    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | ($bits[$i + $j] ? 1 : 0);
        }
        $data[] = $byte;
    }
    for ($pad = 0xEC; count($data) < $data_codewords; $pad ^= 0xFD) {
        $data[] = $pad;
    }
    $codewords = array_merge($data, rp_qr_rs_remainder($data, $ecc_codewords));

    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    rp_qr_finder($modules, $reserved, 0, 0);
    rp_qr_finder($modules, $reserved, $size - 7, 0);
    rp_qr_finder($modules, $reserved, 0, $size - 7);
    rp_qr_alignment($modules, $reserved, 30, 30);

    for ($i = 8; $i < $size - 8; $i++) {
        rp_qr_set($modules, $reserved, 6, $i, $i % 2 === 0);
        rp_qr_set($modules, $reserved, $i, 6, $i % 2 === 0);
    }
    for ($i = 0; $i < 9; $i++) {
        if ($i !== 6) {
            rp_qr_set($modules, $reserved, 8, $i, false);
            rp_qr_set($modules, $reserved, $i, 8, false);
        }
    }
    for ($i = 0; $i < 8; $i++) {
        rp_qr_set($modules, $reserved, $size - 1 - $i, 8, false);
        rp_qr_set($modules, $reserved, 8, $size - 1 - $i, false);
    }
    rp_qr_set($modules, $reserved, 8, $size - 8, true);

    $data_bits = [];
    foreach ($codewords as $byte) {
        for ($i = 7; $i >= 0; $i--) {
            $data_bits[] = (($byte >> $i) & 1) !== 0;
        }
    }
    $bit_index = 0;
    $dir = -1;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            $y = $dir === 1 ? $vert : $size - 1 - $vert;
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                if (!$reserved[$y][$x]) {
                    $bit = $data_bits[$bit_index++] ?? false;
                    $modules[$y][$x] = $bit ^ (($x + $y) % 2 === 0);
                }
            }
        }
        $dir = -$dir;
    }

    $format = rp_qr_format_bits();
    for ($i = 0; $i <= 5; $i++) rp_qr_set($modules, $reserved, 8, $i, (($format >> $i) & 1) !== 0);
    rp_qr_set($modules, $reserved, 8, 7, (($format >> 6) & 1) !== 0);
    rp_qr_set($modules, $reserved, 8, 8, (($format >> 7) & 1) !== 0);
    rp_qr_set($modules, $reserved, 7, 8, (($format >> 8) & 1) !== 0);
    for ($i = 9; $i < 15; $i++) rp_qr_set($modules, $reserved, 14 - $i, 8, (($format >> $i) & 1) !== 0);
    for ($i = 0; $i < 8; $i++) rp_qr_set($modules, $reserved, $size - 1 - $i, 8, (($format >> $i) & 1) !== 0);
    for ($i = 8; $i < 15; $i++) rp_qr_set($modules, $reserved, 8, $size - 15 + $i, (($format >> $i) & 1) !== 0);

    $scale = 5;
    $border = 4;
    $pixels = ($size + $border * 2) * $scale;
    $img = imagecreatetruecolor($pixels, $pixels);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($modules[$y][$x]) {
                imagefilledrectangle($img, ($x + $border) * $scale, ($y + $border) * $scale, ($x + $border + 1) * $scale - 1, ($y + $border + 1) * $scale - 1, $black);
            }
        }
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
}
}

$txn_type_label = $txn_type_label ?? 'MERCHANDISE & SERVICE TRANSACTION';
$txn_type_sublabel = $txn_type_sublabel ?? 'Official Merchandise & Service Invoice';
$station_addr = $station_addr ?? 'Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental';
$vat_tin = $vat_tin ?? '236-003-207-0000';
$txn_id = $txn_id ?? ($_GET['id'] ?? 'N/A');
$disp_date = $disp_date ?? date('F j, Y');
$disp_time = $disp_time ?? date('h:i A');
$customer = $customer ?? 'Walk-in Customer';
$staff_name = $staff_name ?? 'N/A';
$shift_name = $shift_name ?? '';
$items = $items ?? [];
$job_order = $job_order ?? null;
$has_jo = !empty($job_order);
$pay_method = $pay_method ?? 'Cash';
$pm_lc = strtolower($pay_method);
$pay_status_norm = $pay_status_norm ?? 'paid';
$total = (float) ($total ?? 0);
$vatable = (float) ($vatable ?? round($total / 1.12, 2));
$vat_amt = (float) ($vat_amt ?? round($total - $vatable, 2));
$amount_paid_db = (float) ($amount_paid_db ?? 0);
$balance_due_db = (float) ($balance_due_db ?? 0);
$tendered = (float) ($tendered ?? 0);
$change = (float) ($change ?? 0);
$sale = $sale ?? [];

$logo_path = '';
if (!empty($receipt_cfg['logo_path'])) {
    $custom_logo = rp_local_img_path($receipt_cfg['logo_path']);
    if ($custom_logo && file_exists($custom_logo)) {
        $logo_path = $custom_logo;
    }
}
if (!$logo_path) {
    $logo_path = realpath(__DIR__ . '/../assets/img/Petron Logo.png');
    if (!$logo_path || !file_exists($logo_path)) {
        $logo_path = realpath(__DIR__ . '/../assets/img/petron_logo.png');
    }
}
$verify_code = (string) ($verify_url ?? $qr_data ?? $txn_id);
$qr_png = $verify_code !== '' ? rp_qr_png($verify_code) : '';

$item_rows = '';
foreach ($items as $it) {
    $name = $it['product_name'] ?? $it['name'] ?? 'Item';
    $category = $it['category'] ?? '';
    $size = $it['size_variant'] ?? $it['size'] ?? '';
    $qty = (float) ($it['quantity'] ?? $it['qty'] ?? 1);
    $price = (float) ($it['unit_price'] ?? $it['price'] ?? 0);
    $subtotal = (float) ($it['subtotal'] ?? $it['amount'] ?? ($qty * $price));
    $meta = implode(' - ', array_filter([$category, $size]));

    $item_rows .= '<tr class="item-row">'
        . '<td class="item-name">' . rp_e($name) . ($meta ? '<br><span class="muted small">' . rp_e($meta) . '</span>' : '') . '</td>'
        . '<td class="num">' . number_format($qty, 0) . '</td>'
        . '<td class="money">' . rp_money($price) . '</td>'
        . '<td class="money bold">' . rp_money($subtotal) . '</td>'
        . '</tr>';
}
if ($item_rows === '') {
    $item_rows = '<tr><td colspan="4" class="muted">No item details available.</td></tr>';
}

$job_order_rows = '';
if ($has_jo) {
    $jo_no = $job_order['job_order_id'] ?? $job_order['job_order_number'] ?? '';
    if ($jo_no !== '') {
        $job_order_rows .= rp_row('Job Order ID', rp_e($jo_no), true);
    }
    if (!empty($job_order['service_type'])) {
        $job_order_rows .= rp_row('Service Type', rp_e($job_order['service_type']));
    }
    if (!empty($job_order['service_description'])) {
        $job_order_rows .= rp_row('Description', nl2br(rp_e($job_order['service_description'])));
    }
    if (!empty($job_order['vehicle_plate'])) {
        $job_order_rows .= rp_row('Vehicle Plate', rp_e($job_order['vehicle_plate']), true);
    }
    if (!empty($job_order['vehicle_type'])) {
        $job_order_rows .= rp_row('Vehicle Type', rp_e($job_order['vehicle_type']));
    }
    if (!empty($job_order['mechanic_name'])) {
        $job_order_rows .= rp_row('Mechanic', rp_e($job_order['mechanic_name']));
    }
}

$payment_rows = rp_row('Method', rp_e(strtoupper($pay_method)), true);
if ($pay_status_norm === 'partial') {
    $payment_rows .= rp_row('Amount Paid', rp_money($amount_paid_db), true);
    $payment_rows .= rp_row('Balance Due', rp_money($balance_due_db), true, 'warn');
    if ($pm_lc === 'cash' && $tendered > 0) {
        $payment_rows .= rp_row('Change', rp_money($change), true);
    }
} elseif ($pay_status_norm === 'pending') {
    $payment_rows .= rp_row('Amount Paid', rp_money(0));
    $payment_rows .= rp_row('Balance Due', rp_money($total), true, 'warn');
} elseif ($pay_status_norm === 'credit') {
    $payment_rows .= rp_row('Amount Paid', rp_money(0));
    $payment_rows .= rp_row('Credit Amount', rp_money($total), true, 'credit');
} elseif ($pm_lc === 'cash') {
    $payment_rows .= rp_row('Amount Tendered', rp_money($tendered > 0 ? $tendered : $total));
    $payment_rows .= rp_row('Change', rp_money($change), true);
} else {
    $payment_rows .= rp_row('Amount Charged', rp_money($total));
}

$footer_note = 'This document is valid as an official service record.';
if ($pay_status_norm === 'partial') {
    $footer_note = 'This receipt reflects a partial payment. Balance due: ' . rp_money($balance_due_db);
} elseif ($pay_status_norm === 'pending') {
    $footer_note = 'No payment collected yet. Balance due: ' . rp_money($total);
} elseif ($pay_status_norm === 'credit') {
    $footer_note = 'Credit transaction. Amount forwarded to Receivables module.';
}

$vat_reg_no = $vat_reg_no ?? 'Registered';
$atp_no = $atp_no ?? 'BIR-ATP-2026-00984712';
$vat_tin = $vat_tin ?? '248-719-305-00000';
$or_number = $or_number ?? ('OR-' . date('Ymd') . '-' . str_pad((string)($sale['id'] ?? '1'), 6, '0', STR_PAD_LEFT));

$filename = 'receipt_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $txn_id) . '.pdf';

$html = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { size: 80mm 260mm; margin: 3mm 2mm; }
body { margin: 0; font-family: "Courier New", monospace; color: #111; background: #fff; }
.receipt { width: 76mm; font-size: 8.5px; line-height: 1.25; page-break-inside: avoid; }
.center { text-align: center; }
.logo { width: 14mm; height: auto; margin-bottom: 1mm; }
.brand { color: #003d7a; font-size: 9.5px; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; }
.branch { font-size: 7.5px; color: #222; margin-top: .5mm; }
.tin { font-size: 7.5px; color: #555; margin-top: .3mm; }
.double { border-top: 1px solid #111; border-bottom: .5px solid #111; height: 1px; margin: 1.5mm 0; }
.dash { border-top: .4px solid #999; margin: 1.2mm 0; }
.title { font-size: 9.5px; font-weight: bold; letter-spacing: .5px; text-align: center; }
.sub { font-size: 7px; color: #555; text-align: center; margin-top: .5mm; }
.label { color: #003d7a; font-size: 7.5px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; margin: 1.2mm 0 .5mm; }
.label.warn-label { color: #b45309; }
table { width: 100%; border-collapse: collapse; }
.kv td { padding: .2mm 0; vertical-align: top; }
.key { color: #666; width: 38%; }
.val { text-align: right; width: 62%; word-break: break-word; }
.bold { font-weight: bold; }
.warn .key, .warn .val { color: #9a3412; }
.credit .key, .credit .val { color: #6b21a8; }
.items th { color: #555; border-bottom: .4px solid #bbb; font-size: 7.5px; text-align: left; padding-bottom: .5mm; }
.items td { border-bottom: .25px dotted #ddd; padding: .4mm 0; vertical-align: top; }
.item-name { width: 45%; }
.num { width: 10%; text-align: center; }
.money { width: 22.5%; text-align: right; }
.small { font-size: 7px; }
.muted { color: #777; }
.grand td { font-size: 9.5px; font-weight: bold; padding: .5mm 0; }
.qr { text-align: center; margin: 1.2mm 0; }
.qr img { width: 16mm; height: 16mm; }
.qr-label { color: #888; font-size: 7px; margin-bottom: .5mm; }
.footer { text-align: center; margin-top: 1.5mm; }
.foot-title { font-size: 8.5px; font-weight: bold; }
.foot-line { color: #555; font-size: 7.2px; margin-top: .4mm; }
.foot-meta { color: #999; font-size: 6.8px; margin-top: .8mm; }
</style>
</head>
<body>
<div class="receipt">
  <div class="center">'
    . ($logo_path ? '<img class="logo" src="var:receipt_logo" alt="Petron">' : '')
    . '<div class="brand">' . rp_e($station_name) . '</div>'
    . '<div class="branch">' . rp_e($station_addr) . '</div>'
    . (!empty($station_contact) ? '<div class="tin">Tel: ' . rp_e($station_contact) . '</div>' : '') . '
    <div class="tin">VAT Reg TIN: ' . rp_e($vat_tin) . '</div>
    <div class="tin">ATP No.: ' . rp_e($atp_no) . '</div>'
    . (!empty($min_serial) ? '<div class="tin">MIN: ' . rp_e($min_serial) . '</div>' : '') . '
  </div>

  <div class="double"></div>
  <div class="title">' . rp_e($txn_type_label) . '</div>
  ' . ($txn_type_sublabel ? '<div class="sub">' . rp_e($txn_type_sublabel) . '</div>' : '') . '
  <div class="dash"></div>

  <div class="label">Transaction Details</div>
  <table>'
    . rp_row('OR / Invoice No', rp_e($or_number), true)
    . rp_row('Transaction ID', rp_e($txn_id), true)
    . rp_row('Date & Time', rp_e($disp_date . ' ' . $disp_time))
    . ($show_customer ? rp_row('Customer Name', rp_e($customer), true) : '')
    . ($show_cashier ? rp_row('Staff / Shift', rp_e($staff_name . ($shift_name ? ' (' . $shift_name . ')' : ''))) : '')
  . '</table>

  <div class="dash"></div>
  <div class="label">Items Purchased</div>
  <table class="items">
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="money">Unit Price</th><th class="money">Subtotal</th></tr></thead>
    <tbody>' . $item_rows . '</tbody>
  </table>'

  . (($job_order_rows && $show_jo_details) ? '<div class="dash"></div><div class="label warn-label">Job Order Details</div><table>' . $job_order_rows . '</table>' : '')

  . ($show_vat ? '<div class="dash"></div>
  <div class="label">Tax Breakdown</div>
  <table>'
    . rp_row('Vatable Sales', rp_money($vatable))
    . rp_row('VAT (12%)', rp_money($vat_amt))
    . rp_row('Zero-Rated Sales', rp_money(0))
    . rp_row('VAT-Exempt Sales', rp_money(0))
  . '</table>' : '')

  . '<div class="double"></div>
  <table><tr class="grand"><td>GRAND TOTAL</td><td class="val">' . rp_money($total) . '</td></tr></table>'
  . ($show_payment_details ? '<div class="dash"></div>
  <div class="label">Totals & Payment</div>
  <table>' . $payment_rows . '</table>' : '')
  . '<div class="dash"></div>'
  . ($show_qr && $qr_png !== '' ? '<div class="qr"><div class="qr-label">Scan to Verify</div><img src="var:receipt_qr" alt="QR"><div class="foot-meta">' . rp_e($txn_id) . '</div></div><div class="dash"></div>' : '') . '

  <div class="footer">
    <div class="foot-title" style="font-weight: bold; font-size: 8.5px; margin-bottom: .4mm;">' . rp_e(!empty($receipt_cfg['footer_title']) ? $receipt_cfg['footer_title'] : 'Official Sales Invoice / Receipt') . '</div>
    <div class="foot-line">TIN: ' . rp_e($vat_tin) . ' | VAT Reg: ' . rp_e($vat_reg_no) . '</div>
    <div class="foot-line">ATP No.: ' . rp_e($atp_no) . (!empty($min_serial) ? ' | MIN: ' . rp_e($min_serial) : '') . '</div>
    <div class="foot-line" style="font-weight: bold; color: #003d7a; margin: 1mm 0;">' . rp_e(!empty($receipt_cfg['footer_message']) ? $receipt_cfg['footer_message'] : 'Thank you for your purchase!') . '</div>'
    . (!empty($receipt_cfg['terms_notes']) ? '<div class="foot-line" style="font-size:7px;color:#666;">' . nl2br(rp_e($receipt_cfg['terms_notes'])) . '</div>' : '') . '
    <div class="foot-meta">Printed: ' . date('M j, Y h:i A') . ' | ' . rp_e($txn_id) . '</div>
  </div>
</div>
</body>
</html>';

$pdf_format = match($paper_size ?? 'thermal_80mm') {
    'a4'     => 'A4',
    'letter' => 'Letter',
    'thermal_58mm' => [58, 220],
    default  => [80, 260],
};

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => $pdf_format,
    'margin_left' => 2,
    'margin_right' => 2,
    'margin_top' => 3,
    'margin_bottom' => 3,
    'margin_header' => 0,
    'margin_footer' => 0,
    'default_font' => 'courier',
    'default_font_size' => 8,
    'tempDir' => sys_get_temp_dir(),
]);

$logo_raw = $logo_path ? @file_get_contents($logo_path) : false;
if ($logo_raw !== false && $logo_raw !== '') {
    $mpdf->imageVars['receipt_logo'] = $logo_raw;
}
if ($qr_png !== '') {
    $mpdf->imageVars['receipt_qr'] = $qr_png;
}

$mpdf->SetTitle($filename);
$mpdf->WriteHTML($html);
$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
