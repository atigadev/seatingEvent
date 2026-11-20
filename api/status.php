<?php
declare(strict_types=1);

/** Status kiosk (saklar scan, teks standby, mode printer, versi denah) — dipantau layar kiosk tiap beberapa detik */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/venue.php';

$cfg = app_config();
$extra = ['venue_version' => venue_version()];
if ($cfg['print']['mode'] === 'agent') {
    require __DIR__ . '/../lib/agent.php';
    $extra['stations'] = agent_station_list(); // pilihan stasiun cetak di kiosk (nama & online saja)
}
json_response(['ok' => true] + kiosk_status($cfg) + $extra);
