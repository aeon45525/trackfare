<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fare.php';

$uid     = normalize_nfc_uid((string) ($_POST['uid'] ?? ''));
$trip_id = (int) ($_POST['trip_id'] ?? 0);

if ($uid === '' || $trip_id < 1) {
    exit('INVALID REQUEST');
}

$user = null;
if ($stmt = $conn->prepare(
    'SELECT u.user_id, c.card_id
     FROM users u
     JOIN nfc_cards c ON u.user_id = c.user_id
        WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(c.uid), " ", ""), ":", ""), "-", "")) = ?
            AND c.is_active = 1'
)) {
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$user) {
    exit('INVALID CARD');
}

$user_id = (int) $user['user_id'];
$card_id = (int) $user['card_id'];

$active = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, card_id, boarding_stop_id FROM active_passengers WHERE user_id = ? AND trip_id = ? LIMIT 1'
)) {
    $stmt->bind_param('ii', $user_id, $trip_id);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$active) {
    exit('NOT TAPED IN');
}

$trip = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, route_id, current_stop_index, start_time FROM trips WHERE trip_id = ? AND status = ?'
)) {
    $status = 'active';
    $stmt->bind_param('is', $trip_id, $status);
    $stmt->execute();
    $trip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$trip) {
    exit('INVALID TRIP');
}
if (empty($trip['start_time'])) {
    exit('WAIT FOR DRIVER TO START');
}

$route_id      = (int) $trip['route_id'];
$alightStopId  = resolve_trip_current_stop_id($conn, $trip);

if ($alightStopId === null) {
    exit('INVALID STOP');
}

$result = process_passenger_tap_out(
    $conn,
    $trip_id,
    $route_id,
    $user_id,
    (int) $active['card_id'],
    (int) $active['boarding_stop_id'],
    $alightStopId
);

if (!$result['ok']) {
    exit($result['message']);
}

echo $result['message'] . ' | FARE: ' . number_format($result['fare'], 2);
