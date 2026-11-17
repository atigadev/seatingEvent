<?php
declare(strict_types=1);

/** Status kiosk (saklar scan, teks standby, mode printer, versi denah) — dipantau layar kiosk tiap beberapa detik */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/venue.php';

json_response(['ok' => true] + kiosk_status(app_config()) + ['venue_version' => venue_version()]);
