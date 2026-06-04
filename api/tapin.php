<?php
require_once '../config/db.php';
require_once '../config/fare.php';

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
     WHERE UPPER(TRIM(c.uid)) = ?'
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

$trip = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, route_id, current_stop_index, status
     FROM trips WHERE trip_id = ? AND status = ?'
)) {
    $status = 'active';
    $stmt->bind_param('is', $trip_id, $status);
    $stmt->execute();
    $trip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$trip) {
    exit('NO ACTIVE TRIP');
}

$check = null;
if ($stmt = $conn->prepare(
    'SELECT 1 FROM active_passengers WHERE user_id = ? AND trip_id = ?'
)) {
    $stmt->bind_param('ii', $user_id, $trip_id);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($check) {
    exit('ALREADY TAP IN');
}

$route_id      = (int) $trip['route_id'];
$current_index = (int) $trip['current_stop_index'];
$boardingStopId = get_route_stop_id_at_index($conn, $route_id, $current_index);

if ($boardingStopId === null) {
    exit('INVALID STOP');
}

// Get the stop location for passenger position tracking
$stopLat = null;
$stopLng = null;
if ($stmt = $conn->prepare('SELECT lat, lng FROM stops WHERE stop_id = ?')) {
    $stmt->bind_param('i', $boardingStopId);
    $stmt->execute();
    $stmt->bind_result($stopLat, $stopLng);
    $stmt->fetch();
    $stmt->close();
}

// Try to insert with location columns (if migration was run)
if ($stmt = $conn->prepare(
    'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id, lat, lng, tap_in_time) VALUES (?, ?, ?, ?, ?, ?, NOW())'
)) {
    $stmt->bind_param('iiiidd', $trip_id, $user_id, $card_id, $boardingStopId, $stopLat, $stopLng);
    $stmt->execute();
    $stmt->close();
    echo 'TAP IN SUCCESS';
} else {
    // Fallback to old schema if migration not run
    if ($stmt = $conn->prepare(
        'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES (?, ?, ?, ?)'
    )) {
        $stmt->bind_param('iiii', $trip_id, $user_id, $card_id, $boardingStopId);
        $stmt->execute();
        $stmt->close();
        echo 'TAP IN SUCCESS';
    } else {
        exit('TAP IN FAILED');
    }
}
