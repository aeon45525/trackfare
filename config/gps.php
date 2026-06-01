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
 *   end           – end trip immediately; flip to opposite route for the next Start Trip
 *   (none)        – return current state
 */

session_start();
require_once __DIR__ . '/db.php';

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

function flip_active_trip_route(mysqli $conn, int $driverId, int $newRouteId): bool
{
    if ($stmt = $conn->prepare(
        'UPDATE trips SET route_id = ?, current_stop_index = 0
         WHERE driver_id = ? AND status = ?'
    )) {
        $s = 'active';
        $stmt->bind_param('iis', $newRouteId, $driverId, $s);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
    return false;
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

$driverId    = (int)($_SESSION['user_id'] ?? 0);
$routeFlipped = false;
$action      = $_GET['action'] ?? null;

switch ($action) {
    case 'start':
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
        break;

    case 'end':
        $tripWasActive = !empty($_GET['flip'])
            || in_array($state['status'] ?? 'idle', ['running', 'paused', 'ended'], true);

        if ($tripWasActive && $driverId > 0 && ($_SESSION['role'] ?? '') === 'driver') {
            $newRouteId = opposite_route_id($routeId);
            if (flip_active_trip_route($conn, $driverId, $newRouteId)) {
                $routeFlipped = true;
            }
            $routeId = $newRouteId;
            $stops   = load_route_stops($conn, $routeId);
            if (!empty($stops)) {
                $terminal = $stops[0];
                $maxIndex = count($stops) - 1;
                $state['routeId'] = $routeId;
            }
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

echo json_encode([
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
], JSON_UNESCAPED_UNICODE);