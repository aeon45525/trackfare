<?php
/**
 * gps.php — Simulated trip state (no real GPS).
 * Uses stop coordinates from the DB. The dashboard animates the bus
 * client-side; this file persists status / currentStopIndex only.
 *
 * Actions (GET ?action=):
 *   start         – begin trip from stop 0
 *   arrive&index= – mark arrival at stop N
 *   depart        – depart current stop (resumes running)
 *   end           – reset trip to idle at stop 0
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
    return 1; // fallback: route 1
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
        'status'           => 'idle',
        'currentStopIndex' => 0,
        'busPosition'      => ['lat' => $terminal['lat'], 'lng' => $terminal['lng']],
    ];
}

$state = &$_SESSION['gps_sim'];
$state['currentStopIndex'] = max(0, min($maxIndex, (int)($state['currentStopIndex'] ?? 0)));

/* ── action handling ─────────────────────────────────────────── */

$action = $_GET['action'] ?? null;

switch ($action) {
    case 'start':
        $state['status']           = 'running';
        $state['currentStopIndex'] = 0;
        $state['busPosition']      = ['lat' => $terminal['lat'], 'lng' => $terminal['lng']];
        break;

    case 'arrive':
        $idx = max(0, min($maxIndex, (int)($_GET['index'] ?? $state['currentStopIndex'])));
        $state['status']           = 'paused';
        $state['currentStopIndex'] = $idx;
        $state['busPosition']      = [
            'lat' => $stops[$idx]['lat'],
            'lng' => $stops[$idx]['lng'],
        ];
        break;

    case 'depart':
        if (($state['status'] ?? 'idle') === 'paused') {
            $state['status'] = 'running';
        }
        break;

    case 'end':
        $state['status']           = 'idle';
        $state['currentStopIndex'] = 0;
        $state['busPosition']      = ['lat' => $terminal['lat'], 'lng' => $terminal['lng']];
        break;
}

/* ── response ────────────────────────────────────────────────── */

echo json_encode([
    'routeId'          => $routeId,
    'stops'            => $stops,
    'status'           => $state['status'] ?? 'idle',
    'currentStopIndex' => (int)($state['currentStopIndex'] ?? 0),
    'busPosition'      => [
        'lat' => (float)($state['busPosition']['lat'] ?? $terminal['lat']),
        'lng' => (float)($state['busPosition']['lng'] ?? $terminal['lng']),
    ],
], JSON_UNESCAPED_UNICODE);