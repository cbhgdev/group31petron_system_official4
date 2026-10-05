<?php
@unlink(__DIR__ . '/test_ping.php');
@unlink(__DIR__ . '/test_diag.php');
@unlink(__FILE__);
echo "Cleaned up";
