<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['driver', 'passenger'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// live GPS from the ESP32
$path = __DIR__ . '/../config/gps_live.json';
$d = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

// trip status written by the dashboard simulation
$statePath = __DIR__ . '/../config/gps_state.json';
$state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
$tripStatus = is_array($state) ? (string)($state['status'] ?? 'idle') : 'idle';
$tripIdle   = in_array($tripStatus, ['', 'idle'], true);

if (!is_array($d)) {
    echo json_encode(['ok' => true, 'online' => false, 'fix' => false, 'useLive' => false, 'tripStatus' => $tripStatus]);
    exit;
}

$age    = time() - (int)($d['ts'] ?? 0);
$online = $age <= 10;
$fix    = !empty($d['fix']);

echo json_encode([
    'ok'         => true,
    'online'     => $online,
    'fix'        => $fix,
    'useLive'    => $online && $fix && $tripIdle,   // <- the one flag both dashboards use
    'tripStatus' => $tripStatus,
    'lat'        => (float)$d['lat'],
    'lng'        => (float)$d['lng'],
    'sats'       => (int)$d['sats'],
    'speed'      => (float)$d['speed'],
    'age'        => $age,
]);