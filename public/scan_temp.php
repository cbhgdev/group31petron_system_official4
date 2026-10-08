<?php
@unlink(__DIR__ . '/do_cleanup.php');
@unlink(__FILE__);
echo json_encode(['done' => true]);
