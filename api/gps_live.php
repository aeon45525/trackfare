<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/db.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['driver', 'passenger'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// live GPS from the ESP32 (written by api/gps_update.php)
$path = __DIR__ . '/../config/gps_live.json';
$d = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

// trip status written by the dashboard simulation (config/gps.php)
$statePath = __DIR__ . '/../config/gps_state.json';
$state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
$tripStatus = is_array($state) ? (string)($state['status'] ?? 'idle') : 'idle';
$tripIdle   = in_array($tripStatus, ['', 'idle'], true);

if (!is_array($d)) {
    echo json_encode([
        'ok' => true, 'online' => false, 'fix' => false, 'useLive' => false,
        'tripStatus' => $tripStatus, 'reason' => 'no GPS data yet',
    ]);
    exit;
}

$age    = time() - (int)($d['ts'] ?? 0);
$online = $age <= 15;
$fix    = !empty($d['fix']);
if ($role === 'driver' && $online && $fix && isset($d['lat'], $d['lng'])) {
    $userId = (int) $_SESSION['user_id'];
    if ($stmt = $conn->prepare(
        'SELECT bus_id FROM trips WHERE driver_id = ? AND status = ? LIMIT 1'
    )) {
        $active = 'active';
        $stmt->bind_param('is', $userId, $active);
        $stmt->execute();
        $trip = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lat = (float) $d['lat'];
        $lng = (float) $d['lng'];
        if ($trip && (int) $trip['bus_id'] === (int) ($d['bus_id'] ?? 0)
            && is_finite($lat) && is_finite($lng)
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        ) {
            if ($stmt = $conn->prepare(
                'UPDATE users SET lat = ?, lng = ? WHERE user_id = ? AND role = ?'
            )) {
                $driverRole = 'driver';
                $stmt->bind_param('ddis', $lat, $lng, $userId, $driverRole);
                if (!$stmt->execute()) {
                    error_log('Could not save live driver location: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log('Could not prepare live driver location update: ' . $conn->error);
            }
        }
    }
}
$useLive = $online && $fix && $tripIdle;

$reason = '';
if (!$online)        $reason = 'GPS device offline';
elseif (!$fix)       $reason = 'GPS searching';
elseif (!$tripIdle)  $reason = 'trip running';

echo json_encode([
    'ok'         => true,
    'online'     => $online,
    'fix'        => $fix,
    'useLive'    => $useLive,   // the one flag both dashboards/passenger use
    'tripStatus' => $tripStatus,
    'reason'     => $reason,
    'lat'        => (float)($d['lat'] ?? 0),
    'lng'        => (float)($d['lng'] ?? 0),
    'sats'       => (int)($d['sats'] ?? 0),
    'speed'      => (float)($d['speed'] ?? 0),
    'age'        => $age,
]);