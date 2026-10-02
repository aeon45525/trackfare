<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gps_device.php';
require_once __DIR__ . '/../config/fare.php';
require_once __DIR__ . '/../config/payment_request.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token = (string) ($_POST['token'] ?? '');
if (!hash_equals(TRACKFARE_GPS_DEVICE_TOKEN, $token)) {
    http_response_code(401);
    exit;
}

$busId = filter_var($_POST['bus_id'] ?? null, FILTER_VALIDATE_INT);
if ($busId === false || $busId === null || $busId < 1) {
    http_response_code(422);
    exit;
}

try {
    echo create_trackfare_payment_request(
        $conn,
        'TrackFare Bus #' . $busId,
        number_format(FARE_FIRST_KM_PHP, 2, '.', ''),
        date('Y-m-d H:i:s', time() + 300),
        'Bus fare'
    );
} catch (Throwable $error) {
    http_response_code(500);
}
