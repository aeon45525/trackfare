<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fare.php';
require_once __DIR__ . '/../config/gps_simulation.php';

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

function resolve_boarding_stop_id(mysqli $conn, array $trip): ?int
{
    return resolve_trip_current_stop_id($conn, $trip);
}

function resolve_active_trip(mysqli $conn, int $requestedTripId): ?array
{
    $trip = null;
    if ($stmt = $conn->prepare(
        'SELECT trip_id, bus_id, route_id, current_stop_index, status, driver_id, start_time
         FROM trips WHERE trip_id = ? AND status = ? LIMIT 1'
    )) {
        $active = 'active';
        $stmt->bind_param('is', $requestedTripId, $active);
        $stmt->execute();
        $trip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($trip) {
            return $trip;
        }
    }

    $fallback = null;
    if ($stmt = $conn->prepare(
        'SELECT bus_id, driver_id FROM trips WHERE trip_id = ? LIMIT 1'
    )) {
        $stmt->bind_param('i', $requestedTripId);
        $stmt->execute();
        $fallback = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($fallback && isset($fallback['bus_id'], $fallback['driver_id'])) {
        if ($stmt = $conn->prepare(
            'SELECT trip_id, bus_id, route_id, current_stop_index, status, driver_id, start_time
             FROM trips WHERE bus_id = ? AND driver_id = ? AND status = ? LIMIT 1'
        )) {
            $active = 'active';
            $stmt->bind_param('iis', $fallback['bus_id'], $fallback['driver_id'], $active);
            $stmt->execute();
            $trip = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $trip ?: null;
        }
    }

    return null;
}

$trip = resolve_active_trip($conn, $trip_id);
if (!$trip) {
    exit('NO ACTIVE TRIP');
}

$activeTripId = (int) $trip['trip_id'];
$route_id      = (int) $trip['route_id'];

$check = null;
if ($stmt = $conn->prepare(
    'SELECT ap.trip_id, ap.boarding_stop_id, ap.card_id, ap.user_id
     FROM active_passengers ap
     WHERE ap.user_id = ? AND ap.tap_state = ?
     LIMIT 1'
)) {
    $tapState = 'in';
    $stmt->bind_param('is', $user_id, $tapState);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($check) {
    $settlementTripId = (int) $check['trip_id'];
    $settlementRouteId = $route_id;

    if ($settlementTripId !== $activeTripId) {
        $previousTrip = null;
        if ($stmt = $conn->prepare(
            'SELECT bus_id, route_id, status FROM trips WHERE trip_id = ? LIMIT 1'
        )) {
            $stmt->bind_param('i', $settlementTripId);
            $stmt->execute();
            $previousTrip = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$previousTrip
            || $previousTrip['status'] !== 'completed'
            || (int) $previousTrip['bus_id'] !== (int) $trip['bus_id']
        ) {
            exit('ALREADY TAPED IN ON ANOTHER TRIP');
        }
        $settlementRouteId = (int) $previousTrip['route_id'];
        $alightingStopId = get_route_last_stop_id($conn, $settlementRouteId);
    } else {
        if (empty($trip['start_time'])) {
            exit('WAIT FOR DRIVER TO START');
        }
        $alightingStopId = resolve_trip_current_stop_id($conn, $trip);
    }

    if ($alightingStopId === null) {
        exit('INVALID STOP');
    }
    $result = process_passenger_tap_out(
        $conn,
        $settlementTripId,
        $settlementRouteId,
        $user_id,
        (int) $check['card_id'],
        (int) $check['boarding_stop_id'],
        $alightingStopId
    );

    if (!$result['ok']) {
        exit($result['message']);
    }

    echo 'TAP OUT SUCCESS | FARE: ' . number_format($result['fare'], 2);
    exit;
}

$boardingStopId = resolve_boarding_stop_id($conn, $trip);
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

// Try the location-aware schema first, then fall back to older installations.
$inserted = false;
try {
    if ($stmt = $conn->prepare(
        'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id, lat, lng, tap_in_time) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )) {
        $stmt->bind_param('iiiidd', $activeTripId, $user_id, $card_id, $boardingStopId, $stopLat, $stopLng);
        $inserted = $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    $inserted = false;
}

if (!$inserted) {
    try {
        if ($stmt = $conn->prepare(
            'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES (?, ?, ?, ?)'
        )) {
        $stmt->bind_param('iiii', $activeTripId, $user_id, $card_id, $boardingStopId);
            $inserted = $stmt->execute();
        $stmt->close();
        }
    } catch (Throwable $e) {
        $inserted = false;
    }
}

if (!$inserted) {
    exit('TAP IN FAILED');
}

echo 'TAP IN SUCCESS';
