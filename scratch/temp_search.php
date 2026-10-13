<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/public/db_connect.php';
foreach (['transaction_requests','payment_methods'] as $t) {
  echo "== $t ==\n";
  try { foreach ($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n"; } catch (Throwable $e) { echo $e->getMessage() . "\n"; }
}
echo "\n== payment_methods rows ==\n";
try { print_r($pdo->query("SELECT * FROM payment_methods")->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable $e) {}
$lines = file(__DIR__ . '/public/staff_transactions_hub.php');
foreach ($lines as $i => $l) {
  if (strpos($l, 'pending_requests') !== false && $i < 3500) echo ($i+1) . ": " . trim($l) . "\n";
}
foreach (['backend/lib.php','backend/customer_module_helpers.php','backend/transaction_schema_fix.php'] as $f) {
  $c = file(__DIR__ . '/' . $f);
  foreach ($c as $i => $l) if (strpos($l, 'function normalize_payment_type') !== false) { echo "$f:" . ($i+1) . "\n"; for ($k=$i;$k<$i+40;$k++) echo $c[$k]; }
}
