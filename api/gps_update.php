<?php
$key = 'trackfare-demo-key';   // must match DEVICE_KEY in the .ino
if (($_POST['key'] ?? '') !== $key) { http_response_code(401); exit('UNAUTHORIZED'); }

$fix = ($_POST['fix'] ?? '0') === '1';
$lat = (float)($_POST['lat'] ?? 0);
$lng = (float)($_POST['lng'] ?? 0);
if ($fix && ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0))) {
    $fix = false;
}

$ok = file_put_contents(__DIR__ . '/../config/gps_live.json', json_encode([
    'fix'   => $fix,
    'lat'   => $lat,
    'lng'   => $lng,
    'sats'  => (int)($_POST['sats'] ?? 0),
    'speed' => (float)($_POST['speed'] ?? 0),
    'ts'    => time(),
]), LOCK_EX);

if ($ok === false) { http_response_code(500); exit('WRITE FAILED - check config folder permissions'); }
echo 'OK';