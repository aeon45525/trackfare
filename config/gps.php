<?php
/**
 * gps.php — Simulated trip state (no real GPS).
 * Uses stop coordinates from the DB. The dashboard animates the bus
 * client-side; this file persists status / currentStopIndex only.
 *
 * Actions (GET ?action=):
 *   start         – begin trip from stop 0 (only when idle)
 *   arrive&index= – mark arrival at stop N
 *   depart        – depart current stop (resumes running)
 *   update        – persist live bus position (lat, lng, legFrom, legTo, legProgress)
 *   end           – complete leg in DB, clear onboard passengers, open new active trip on opposite route
 *   (none)        – return current state
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fare.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ── helpers ─────────────────────────────────────────────────── */

function load_route_stops(mysqli $conn, int $routeId): array
{
    $stops = [];
    $sql = 'SELECT s.stop_id, s.stop_name, s.lat, s.lng
            FROM route_stops rs
            JOIN stops s ON rs.stop_id = s.stop_id
            WHERE rs.route_id = ?
            ORDER BY rs.stop_order';

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stops[] = [
                'stop_id' => (int)  $row['stop_id'],
                'name'    =>        $row['stop_name'],
                'lat'     => (float)$row['lat'],
                'lng'     => (float)$row['lng'],
            ];
        }
        $stmt->close();
    }
    return $stops;
}

function resolve_route_id(mysqli $conn): int
{
    $driverId = (int)($_SESSION['user_id'] ?? 0);
    if ($driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
        if ($stmt = $conn->prepare(
            'SELECT route_id FROM trips WHERE driver_id = ? AND status = ? LIMIT 1'
        )) {
            $s = 'active';
            $stmt->bind_param('is', $driverId, $s);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['route_id'];
        }
    }
    return 1;
}

function load_route_info(mysqli $conn, int $routeId): array
{
    $info = ['route_name' => '', 'display_name' => ''];
    if ($stmt = $conn->prepare(
        'SELECT route_name, display_name FROM routes WHERE route_id = ? LIMIT 1'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $info['route_name']   = $row['route_name'];
            $info['display_name'] = $row['display_name'];
        }
    }
    return $info;
}

function opposite_route_id(int $routeId): int
{
    return $routeId === 1 ? 2 : 1;
}

function get_active_trip(mysqli $conn, int $driverId): ?array
{
    if ($stmt = $conn->prepare(
        'SELECT trip_id, bus_id, route_id, driver_id, start_time
         FROM trips
         WHERE driver_id = ? AND status = ?
         LIMIT 1'
    )) {
        $s = 'active';
        $stmt->bind_param('is', $driverId, $s);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    return null;
}

function record_trip_start(mysqli $conn, int $driverId): void
{
    if ($stmt = $conn->prepare(
        'UPDATE trips
         SET start_time = NOW()
         WHERE driver_id = ? AND status = ? AND start_time IS NULL'
    )) {
        $s = 'active';
        $stmt->bind_param('is', $driverId, $s);
        $stmt->execute();
        $stmt->close();
    }
}

function sync_trip_stop_index(mysqli $conn, int $driverId, int $index): void
{
    if ($stmt = $conn->prepare(
        'UPDATE trips SET current_stop_index = ?
         WHERE driver_id = ? AND status = ?'
    )) {
        $s = 'active';
        $stmt->bind_param('iis', $index, $driverId, $s);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Mark the current active leg completed and insert a new active trip (opposite route).
 */
function complete_active_trip_and_rotate(mysqli $conn, int $driverId): ?array
{
    $trip = get_active_trip($conn, $driverId);
    if (!$trip) {
        return null;
    }

    $tripId    = (int)$trip['trip_id'];
    $busId     = (int)$trip['bus_id'];
    $routeId   = (int)$trip['route_id'];
    $newRoute  = opposite_route_id($routeId);

    if (!$conn->begin_transaction()) {
        return null;
    }

    try {
        $completed = 'completed';
        $active    = 'active';

        if ($stmt = $conn->prepare(
            'UPDATE trips
             SET status = ?, end_time = NOW(),
                 start_time = COALESCE(start_time, NOW()),
                 current_stop_index = 0
             WHERE trip_id = ? AND status = ?'
        )) {
            $stmt->bind_param('sis', $completed, $tripId, $active);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                $conn->rollback();
                return null;
            }
            $stmt->close();
        } else {
            $conn->rollback();
            return null;
        }

        if ($stmt = $conn->prepare(
            'INSERT INTO trips (bus_id, route_id, driver_id, status, current_stop_index)
             VALUES (?, ?, ?, ?, 0)'
        )) {
            $stmt->bind_param('iiis', $busId, $newRoute, $driverId, $active);
            $stmt->execute();
            $newTripId = (int)$conn->insert_id;
            $stmt->close();
            if ($newTripId < 1) {
                $conn->rollback();
                return null;
            }
        } else {
            $conn->rollback();
            return null;
        }

        $passengersCleared = settle_all_active_passengers_for_trip($conn, $tripId, $routeId);

        $conn->commit();

        return [
            'completed_trip_id'   => $tripId,
            'completed_route_id'  => $routeId,
            'new_trip_id'         => $newTripId,
            'new_route_id'        => $newRoute,
            'passengers_cleared'  => $passengersCleared,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return null;
    }
}

function apply_route_rotation(
    mysqli $conn,
    int $newRouteId,
    int &$routeId,
    array &$stops,
    array &$terminal,
    int &$maxIndex,
    array &$state
): void {
    $routeId = $newRouteId;
    $stops   = load_route_stops($conn, $routeId);
    if (!empty($stops)) {
        $terminal = $stops[0];
        $maxIndex = count($stops) - 1;
        $state['routeId'] = $routeId;
    }
}

function total_route_km(array $stops): float
{
    $total = 0.0;
    $n = count($stops);
    for ($i = 1; $i < $n; $i++) {
        $a = $stops[$i - 1];
        $b = $stops[$i];
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $p1   = deg2rad($a['lat']);
        $p2   = deg2rad($b['lat']);
        $h    = sin($dLat / 2) ** 2 + cos($p1) * cos($p2) * sin($dLng / 2) ** 2;
        $total += 6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
    return round($total, 4);
}

function gps_shared_state_file(): string
{
    return __DIR__ . '/gps_state.json';
}

function gps_read_shared_state(): ?array
{
    $file = gps_shared_state_file();
    if (!is_file($file)) {
        return null;
    }

    $json = file_get_contents($file);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function gps_write_shared_state(array $payload): void
{
    $payload['updatedAt'] = time();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    $fp = fopen(gps_shared_state_file(), 'c');
    if (!$fp) {
        return;
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/* ── passenger read-only tracking ───────────────────────────── */

if (($_SESSION['role'] ?? '') === 'passenger' && !isset($_GET['action'])) {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedRouteId = isset($_GET['route_id']) ? max(1, (int) $_GET['route_id']) : 0;

    $sharedState = gps_read_shared_state();
    $routeId = $requestedRouteId;
    if ($routeId <= 0 && is_array($sharedState) && !empty($sharedState['routeId'])) {
        $routeId = max(1, (int) $sharedState['routeId']);
    }

    if ($routeId <= 0) {
        if ($stmt = $conn->prepare(
            'SELECT route_id
             FROM trips
             WHERE status = ?
             ORDER BY start_time DESC, trip_id DESC
             LIMIT 1'
        )) {
            $active = 'active';
            $stmt->bind_param('s', $active);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $routeId = max(1, (int) $row['route_id']);
            }
        }
    }

    if ($routeId <= 0) {
        $routeId = 1;
    }

    $stops   = load_route_stops($conn, $routeId);

    if ($stops === []) {
        http_response_code(404);
        echo json_encode(['error' => 'Route not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $maxIndex         = count($stops) - 1;
    $tripStatus       = 'idle';
    $currentStopIndex = 0;
    $busPosition      = [
        'lat' => (float) $stops[0]['lat'],
        'lng' => (float) $stops[0]['lng'],
    ];

    if ($stmt = $conn->prepare(
        'SELECT trip_id, status, current_stop_index
         FROM trips
         WHERE route_id = ? AND status = ?
         LIMIT 1'
    )) {
        $active = 'active';
        $stmt->bind_param('is', $routeId, $active);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $tripStatus       = $row['status'];
            $currentStopIndex = max(0, min($maxIndex, (int) $row['current_stop_index']));
            $busPosition      = [
                'lat' => (float) $stops[$currentStopIndex]['lat'],
                'lng' => (float) $stops[$currentStopIndex]['lng'],
            ];
        }
    }

    if (is_array($sharedState) && (int)($sharedState['routeId'] ?? 0) === $routeId) {
        $tripStatus = (string)($sharedState['status'] ?? $tripStatus);
        $currentStopIndex = max(0, min($maxIndex, (int)($sharedState['currentStopIndex'] ?? $currentStopIndex)));
        if (isset($sharedState['busPosition']['lat'], $sharedState['busPosition']['lng'])) {
            $busPosition = [
                'lat' => (float)$sharedState['busPosition']['lat'],
                'lng' => (float)$sharedState['busPosition']['lng'],
            ];
        }
    } else {
        $sharedState = null;
    }

    $routeInfo = load_route_info($conn, $routeId);

    echo json_encode([
        'routeId'          => $routeId,
        'routeName'        => $routeInfo['route_name'],
        'displayName'      => $routeInfo['display_name'],
        'stops'            => $stops,
        'status'           => $tripStatus,
        'currentStopIndex' => $currentStopIndex,
        'busPosition'      => $busPosition,
        'legFrom'          => $sharedState['legFrom'] ?? null,
        'legTo'            => $sharedState['legTo'] ?? null,
        'legProgress'      => isset($sharedState['legProgress']) ? (float)$sharedState['legProgress'] : 0.0,
        'updatedAt'        => $sharedState['updatedAt'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── bootstrap ───────────────────────────────────────────────── */

$routeId = resolve_route_id($conn);
$stops   = load_route_stops($conn, $routeId);

if (empty($stops)) {
    http_response_code(503);
    echo json_encode(['error' => 'No route stops found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$terminal = $stops[0];
$maxIndex = count($stops) - 1;

// Init session state on first call
if (empty($_SESSION['gps_sim']) || !is_array($_SESSION['gps_sim'])) {
    $_SESSION['gps_sim'] = [
        'routeId'          => $routeId,
        'status'           => 'idle',
        'currentStopIndex' => 0,
        'busPosition'      => ['lat' => $terminal['lat'], 'lng' => $terminal['lng']],
        'legFrom'          => null,
        'legTo'            => null,
        'legProgress'      => 0.0,
    ];
}

$state = &$_SESSION['gps_sim'];

if (($state['routeId'] ?? null) !== $routeId) {
    $_SESSION['gps_sim'] = [
        'routeId'          => $routeId,
        'status'           => 'idle',
        'currentStopIndex' => 0,
        'busPosition'      => ['lat' => $terminal['lat'], 'lng' => $terminal['lng']],
        'legFrom'          => null,
        'legTo'            => null,
        'legProgress'      => 0.0,
    ];
    $state = &$_SESSION['gps_sim'];
}

$state['routeId'] = $routeId;
$state['currentStopIndex'] = max(0, min($maxIndex, (int)($state['currentStopIndex'] ?? 0)));

$driverId          = (int)($_SESSION['user_id'] ?? 0);
$routeFlipped      = false;
$tripCompleted     = false;
$completedTripId   = null;
$passengersCleared = 0;
$action            = $_GET['action'] ?? null;

switch ($action) {
    case 'start':
        unset($_SESSION['gps_leg_completed']);
        if ($driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
            record_trip_start($conn, $driverId);
        }
        if (($state['status'] ?? 'idle') === 'idle') {
            $state['status']           = 'running';
            $state['currentStopIndex'] = 0;
            $state['busPosition']      = ['lat' => $terminal['lat'], 'lng' => $terminal['lng']];
            $state['legFrom']          = 0;
            $state['legTo']            = min(1, $maxIndex);
            $state['legProgress']      = 0.0;
        } else {
            $state['status'] = 'running';
        }
        break;

    case 'arrive':
        $idx = max(0, min($maxIndex, (int)($_GET['index'] ?? $state['currentStopIndex'])));
        $state['status']           = 'paused';
        $state['currentStopIndex'] = $idx;
        $state['busPosition']      = [
            'lat' => $stops[$idx]['lat'],
            'lng' => $stops[$idx]['lng'],
        ];
        $state['legFrom']          = null;
        $state['legTo']            = null;
        $state['legProgress']      = 0.0;
        if ($driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
            sync_trip_stop_index($conn, $driverId, $idx);
        }
        break;

    case 'depart':
        if (($state['status'] ?? 'idle') !== 'idle') {
            $state['status'] = 'running';
            $from = (int)($state['currentStopIndex'] ?? 0);
            $state['legFrom']     = $from;
            $state['legTo']       = min($from + 1, $maxIndex);
            $state['legProgress'] = 0.0;
        }
        break;

    case 'update':
        if (isset($_GET['lat'], $_GET['lng'])) {
            $state['busPosition'] = [
                'lat' => (float)$_GET['lat'],
                'lng' => (float)$_GET['lng'],
            ];
        }
        if (isset($_GET['index'])) {
            $state['currentStopIndex'] = max(0, min($maxIndex, (int)$_GET['index']));
        }
        if (isset($_GET['legFrom'])) {
            $state['legFrom'] = max(0, min($maxIndex, (int)$_GET['legFrom']));
        }
        if (isset($_GET['legTo'])) {
            $state['legTo'] = max(0, min($maxIndex, (int)$_GET['legTo']));
        }
        if (isset($_GET['legProgress'])) {
            $state['legProgress'] = max(0.0, min(1.0, (float)$_GET['legProgress']));
        }
        if (isset($_GET['status']) && in_array($_GET['status'], ['running', 'paused', 'ended'], true)) {
            $state['status'] = $_GET['status'];
        } elseif (($state['status'] ?? 'idle') === 'idle') {
            $state['status'] = 'running';
        }
        if (isset($_GET['index']) && $driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
            sync_trip_stop_index($conn, $driverId, (int)$state['currentStopIndex']);
        }
        if (($state['status'] ?? '') === 'ended'
            && empty($_SESSION['gps_leg_completed'])
            && $driverId > 0
            && ($_SESSION['role'] ?? '') === 'driver'
        ) {
            $rotated = complete_active_trip_and_rotate($conn, $driverId);
            if ($rotated) {
                $_SESSION['gps_leg_completed'] = true;
                $tripCompleted       = true;
                $completedTripId     = $rotated['completed_trip_id'];
                $passengersCleared   = (int)($rotated['passengers_cleared'] ?? 0);
                $routeFlipped        = true;
                apply_route_rotation(
                    $conn,
                    $rotated['new_route_id'],
                    $routeId,
                    $stops,
                    $terminal,
                    $maxIndex,
                    $state
                );
            }
        }
        break;

    case 'end':
        $tripWasActive = !empty($_GET['flip'])
            || in_array($state['status'] ?? 'idle', ['running', 'paused', 'ended'], true);

        if ($tripWasActive
            && empty($_SESSION['gps_leg_completed'])
            && $driverId > 0
            && ($_SESSION['role'] ?? '') === 'driver'
        ) {
            $rotated = complete_active_trip_and_rotate($conn, $driverId);
            if ($rotated) {
                $_SESSION['gps_leg_completed'] = true;
                $tripCompleted     = true;
                $completedTripId   = $rotated['completed_trip_id'];
                $passengersCleared = (int)($rotated['passengers_cleared'] ?? 0);
                $routeFlipped      = true;
                apply_route_rotation(
                    $conn,
                    $rotated['new_route_id'],
                    $routeId,
                    $stops,
                    $terminal,
                    $maxIndex,
                    $state
                );
            }
        } elseif ($tripWasActive && !empty($_SESSION['gps_leg_completed'])) {
            $routeId = resolve_route_id($conn);
            apply_route_rotation($conn, $routeId, $routeId, $stops, $terminal, $maxIndex, $state);
        }

        $state['status']           = 'idle';
        $state['currentStopIndex'] = 0;
        $state['busPosition']      = ['lat' => $terminal['lat'], 'lng' => $terminal['lng']];
        $state['legFrom']          = null;
        $state['legTo']            = null;
        $state['legProgress']      = 0.0;
        break;
}

/* ── response ────────────────────────────────────────────────── */

$legFrom    = $state['legFrom'] ?? null;
$legTo      = $state['legTo'] ?? null;
$routeInfo  = load_route_info($conn, $routeId);
$totalKm    = total_route_km($stops);

$response = [
    'routeId'          => $routeId,
    'routeName'        => $routeInfo['route_name'],
    'displayName'      => $routeInfo['display_name'],
    'totalKm'          => $totalKm,
    'stops'            => $stops,
    'status'           => $state['status'] ?? 'idle',
    'currentStopIndex' => (int)($state['currentStopIndex'] ?? 0),
    'busPosition'      => [
        'lat' => (float)($state['busPosition']['lat'] ?? $terminal['lat']),
        'lng' => (float)($state['busPosition']['lng'] ?? $terminal['lng']),
    ],
    'legFrom'          => $legFrom === null ? null : (int)$legFrom,
    'legTo'            => $legTo === null ? null : (int)$legTo,
    'legProgress'      => (float)($state['legProgress'] ?? 0),
    'routeFlipped'     => $routeFlipped,
    'tripCompleted'      => $tripCompleted,
    'completedTripId'    => $completedTripId,
    'passengersCleared'  => $passengersCleared,
];

if ($driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
    gps_write_shared_state($response);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

/* ── passenger position update ─────────────────────────────── */

/**
 * Update all active passengers for a trip with the current bus location.
 * @return array{ok: bool, message: string, affected: int}
 */
function update_passenger_positions(mysqli $conn, int $tripId, float $lat, float $lng): array
{
    if ($tripId < 1 || $lat === 0.0 || $lng === 0.0) {
        return ['ok' => false, 'message' => 'INVALID REQUEST', 'affected' => 0];
    }

    if ($stmt = $conn->prepare(
        'UPDATE active_passengers SET lat = ?, lng = ? WHERE trip_id = ?'
    )) {
        $stmt->bind_param('ddi', $lat, $lng, $tripId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'ok' => $ok,
            'message' => $ok ? 'POSITIONS UPDATED' : 'UPDATE FAILED',
            'affected' => $affected
        ];
    }

    return ['ok' => false, 'message' => 'DATABASE ERROR', 'affected' => 0];
}
