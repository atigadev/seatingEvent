<?php
declare(strict_types=1);

/**
 * Denah publik untuk kiosk (versi yang sudah dipublikasikan).
 * GET -> { ok, data } atau { ok:false } bila belum ada denah.
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/venue.php';

$data = venue_live();
json_response($data ? ['ok' => true, 'data' => $data] : ['ok' => false, 'message' => 'Denah belum dipublikasikan.']);
