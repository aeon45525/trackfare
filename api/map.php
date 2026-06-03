<?php
/**
 * map.php — Google Maps API config for authenticated drivers.
 * Returns the API key and libraries list; the dashboard loads the Maps JS SDK.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['driver', 'passenger'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = 'AIzaSyDJ_gNjSq_T8NjeAtRfgS3Xgl5vAFton10';

echo json_encode([
    'apiKey'    => $apiKey,
    'libraries' => ['marker'],
    'mapId'     => null,
], JSON_UNESCAPED_UNICODE);
