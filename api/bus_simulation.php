<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gps_simulation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');
if ($userId < 1 || !in_array($role, ['driver', 'passenger'], true)) {
    http_response_code(401);
    echo json_encode(['available' => false, 'message' => 'Unauthorized']);
    exit;
}

$routeId = max(0, (int) ($_GET['route_id'] ?? 0));
$tripId = 0;
if ($role === 'driver') {
    if ($stmt = $conn->prepare('SELECT trip_id, route_id FROM trips WHERE driver_id = ? AND status = ? LIMIT 1')) {
        $active = 'active';
        $stmt->bind_param('is', $userId, $active);
        $stmt->execute();
        $driverTrip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($driverTrip) {
            $driverRouteId = (int) $driverTrip['route_id'];
            if ($routeId < 1) {
                $routeId = $driverRouteId;
            }
            if ($routeId === $driverRouteId) {
                $tripId = (int) $driverTrip['trip_id'];
            }
        }
    }
} else {
    if ($stmt = $conn->prepare(
        'SELECT t.trip_id, t.route_id
         FROM active_passengers ap
         JOIN trips t ON t.trip_id = ap.trip_id
         WHERE ap.user_id = ? AND t.status = ?
         ORDER BY t.start_time DESC, t.trip_id DESC
         LIMIT 1'
    )) {
        $active = 'active';
        $stmt->bind_param('is', $userId, $active);
        $stmt->execute();
        $passengerTrip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($passengerTrip) {
            $passengerRouteId = (int) $passengerTrip['route_id'];
            if ($routeId < 1) {
                $routeId = $passengerRouteId;
            }
            if ($routeId === $passengerRouteId) {
                $tripId = (int) $passengerTrip['trip_id'];
            }
        }
    }
}

if ($routeId < 1) {
    http_response_code(404);
    echo json_encode(['available' => false, 'message' => 'No active route']);
    exit;
}

echo json_encode(
    gps_simulation_tick($conn, $routeId, $tripId > 0 ? $tripId : null),
    JSON_UNESCAPED_UNICODE
);