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

function gps_shared_state_path(): string
{
    return __DIR__ . '/../config/gps_state.json';
}

function gps_read_shared_state(): ?array
{
    $path = gps_shared_state_path();
    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function find_nearest_route_stop_id_by_position(mysqli $conn, int $routeId, float $lat, float $lng): ?int
{
    $stops = fare_route_data($conn, $routeId)['stops'];
    if ($stops === []) {
        return null;
    }

    $closestStopId = null;
    $closestDistance = INF;
    foreach ($stops as $stop) {
        $distance = haversine_km($lat, $lng, (float) $stop['lat'], (float) $stop['lng']);
        if ($distance < $closestDistance) {
            $closestDistance = $distance;
            $closestStopId = (int) $stop['stop_id'];
        }
    }

    return $closestStopId;
}

function resolve_boarding_stop_id(mysqli $conn, array $trip): ?int
{
    $routeId = (int) $trip['route_id'];
    $state = gps_read_shared_state();
    if (is_array($state)
        && (int) ($state['routeId'] ?? 0) === $routeId
        && isset($state['busPosition']['lat'], $state['busPosition']['lng'])
    ) {
        $stopId = find_nearest_route_stop_id_by_position(
            $conn,
            $routeId,
            (float) $state['busPosition']['lat'],
            (float) $state['busPosition']['lng']
        );
        if ($stopId !== null) {
            return $stopId;
        }
    }

    return get_route_stop_id_at_index($conn, $routeId, (int) $trip['current_stop_index']);
}

function resolve_active_trip(mysqli $conn, int $requestedTripId): ?array
{
    $trip = null;
    if ($stmt = $conn->prepare(
        'SELECT trip_id, bus_id, route_id, current_stop_index, status, driver_id
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
            'SELECT trip_id, route_id, current_stop_index, status
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
$boardingStopId = resolve_boarding_stop_id($conn, $trip);

$check = null;
if ($stmt = $conn->prepare(
    'SELECT ap.boarding_stop_id, ap.card_id, ap.user_id
     FROM active_passengers ap
     WHERE ap.user_id = ? AND ap.trip_id = ?'
)) {
    $stmt->bind_param('ii', $user_id, $activeTripId);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($boardingStopId === null) {
    exit('INVALID STOP');
}

if ($check) {
    $result = process_passenger_tap_out(
        $conn,
        $activeTripId,
        $route_id,
        $user_id,
        $card_id,
        (int) $check['boarding_stop_id'],
        $boardingStopId
    );

    if (!$result['ok']) {
        exit($result['message']);
    }

    echo 'TAP OUT SUCCESS | FARE: ' . number_format($result['fare'], 2);
    exit;
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
    $stmt->bind_param('iiiidd', $activeTripId, $user_id, $card_id, $boardingStopId, $stopLat, $stopLng);
    $stmt->execute();
    $stmt->close();
    echo 'TAP IN SUCCESS';
} else {
    // Fallback to old schema if migration not run
    if ($stmt = $conn->prepare(
        'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES (?, ?, ?, ?)'
    )) {
        $stmt->bind_param('iiii', $activeTripId, $user_id, $card_id, $boardingStopId);
        $stmt->execute();
        $stmt->close();
        echo 'TAP IN SUCCESS';
    } else {
        exit('TAP IN FAILED');
    }
}
