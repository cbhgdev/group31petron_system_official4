<?php
// Bridge wrapper for legacy/notification links to Manager Purchase Management
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Location: manager_stock_request_review.php?tab=pr');
exit;
