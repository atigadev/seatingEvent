<?php
declare(strict_types=1);

/** Status job cetak (dipantau layar kiosk): { ok, status: pending|taken|done|failed, error } */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/agent.php';

$st = queue_status((string) ($_GET['id'] ?? ''));
json_response($st ? ['ok' => true] + $st : ['ok' => false, 'status' => 'failed', 'error' => 'Job cetak tidak ditemukan.'], $st ? 200 : 404);
