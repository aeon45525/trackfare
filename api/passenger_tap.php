<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fare.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function passenger_tap_response(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    passenger_tap_response(false, 'POST required.', 405);
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    passenger_tap_response(false, 'Please sign in as a passenger.', 401);
}

$sessionToken = (string) ($_SESSION['passenger_tap_csrf'] ?? '');
$requestToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    passenger_tap_response(false, 'Your session expired. Refresh the page and try again.', 403);
}

$userId = (int) $_SESSION['user_id'];
$qrTripId = (int) ($_POST['qr_trip_id'] ?? 0);
$qrBusId = (int) ($_POST['qr_bus_id'] ?? 0);
if (($qrTripId > 0) !== ($qrBusId > 0)) {
    passenger_tap_response(false, 'Invalid bus QR code.', 422);
}
if ($qrTripId > 0) {
    $qrTrip = null;
    if ($stmt = $conn->prepare(
        'SELECT trip_id FROM trips WHERE trip_id = ? AND bus_id = ? AND status = ? LIMIT 1'
    )) {
        $activeStatus = 'active';
        $stmt->bind_param('iis', $qrTripId, $qrBusId, $activeStatus);
        $stmt->execute();
        $qrTrip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$qrTrip) {
        passenger_tap_response(false, 'This bus QR is no longer active. Scan the current QR on the bus.', 409);
    }
}

$activePassenger = null;
if ($stmt = $conn->prepare(
        'SELECT ap.trip_id, ap.card_id, ap.boarding_stop_id, t.route_id, t.bus_id,
            t.current_stop_index, t.status, t.start_time
     FROM active_passengers ap
     JOIN trips t ON t.trip_id = ap.trip_id
     WHERE ap.user_id = ? AND ap.tap_state = ?
     LIMIT 1'
)) {
    $tapState = 'in';
    $stmt->bind_param('is', $userId, $tapState);
    $stmt->execute();
    $activePassenger = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($activePassenger) {
    if ($qrTripId > 0
        && ((int) $activePassenger['bus_id'] !== $qrBusId
            || ($activePassenger['status'] === 'active'
                && (int) $activePassenger['trip_id'] !== $qrTripId))
    ) {
        passenger_tap_response(false, 'This QR does not match your current bus trip.', 409);
    }
    if (!in_array($activePassenger['status'], ['active', 'completed'], true)) {
        passenger_tap_response(false, 'This trip is no longer active.', 409);
    }
    if ($activePassenger['status'] === 'active' && empty($activePassenger['start_time'])) {
        passenger_tap_response(false, 'Your tap-in is saved. Wait for the driver to start before tapping out.', 409, [
            'action' => 'waiting',
            'pending_driver_start' => true,
        ]);
    }
    $tripId = (int) $activePassenger['trip_id'];
    $routeId = (int) $activePassenger['route_id'];
    $alightingStopId = $activePassenger['status'] === 'completed'
        ? get_route_last_stop_id($conn, $routeId)
        : resolve_trip_current_stop_id($conn, $activePassenger);
    if ($alightingStopId === null) {
        passenger_tap_response(false, 'Unable to determine the bus stop. Try again shortly.', 409);
    }

    $result = process_passenger_tap_out(
        $conn,
        $tripId,
        $routeId,
        $userId,
        (int) $activePassenger['card_id'],
        (int) $activePassenger['boarding_stop_id'],
        $alightingStopId
    );
    if (!$result['ok']) {
        passenger_tap_response(false, (string) $result['message'], 409);
    }

    passenger_tap_response(true, 'TAP OUT SUCCESS', 200, [
        'action' => 'tap_out',
        'fare' => (float) $result['fare'],
    ]);
}

$tripId = $qrTripId > 0 ? $qrTripId : max(0, (int) ($_POST['trip_id'] ?? 0));
if ($tripId < 1) {
    passenger_tap_response(false, 'Choose an active bus before tapping in.', 422);
}

$trip = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, route_id, current_stop_index, status, start_time
     FROM trips
     WHERE trip_id = ? AND status = ?
     LIMIT 1'
)) {
    $activeStatus = 'active';
    $stmt->bind_param('is', $tripId, $activeStatus);
    $stmt->execute();
    $trip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$trip) {
    passenger_tap_response(false, 'That bus trip is no longer active. Refresh and choose another.', 409);
}

$cardId = 0;
if ($stmt = $conn->prepare(
    'SELECT card_id FROM nfc_cards WHERE user_id = ? AND is_active = 1 LIMIT 1'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $card = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cardId = (int) ($card['card_id'] ?? 0);
}
if ($cardId < 1) {
    passenger_tap_response(false, 'Link an active fare card before tapping in.', 409);
}

$boardingStopId = resolve_trip_current_stop_id($conn, $trip);
if ($boardingStopId === null) {
    passenger_tap_response(false, 'Unable to determine the bus stop. Try again shortly.', 409);
}

if ($stmt = $conn->prepare(
    'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id, tap_in_time)
     VALUES (?, ?, ?, ?, NOW())'
)) {
    $stmt->bind_param('iiii', $tripId, $userId, $cardId, $boardingStopId);
    $inserted = $stmt->execute();
    $stmt->close();
} else {
    $inserted = false;
}
if (!$inserted) {
    passenger_tap_response(false, 'Tap-in could not be recorded. Refresh and try again.', 409);
}

$pendingDriverStart = empty($trip['start_time']);
passenger_tap_response(true, $pendingDriverStart
    ? 'Tap-in recorded. Waiting for the driver to start.'
    : 'TAP IN SUCCESS', 200, [
        'action' => $pendingDriverStart ? 'waiting' : 'tap_in',
        'pending_driver_start' => $pendingDriverStart,
    ]);