<?php
/**
 * map.php — Google Maps API config for authenticated users.
 * Returns the API key and libraries list; the dashboard loads the Maps JS SDK.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['driver', 'passenger', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = 'AIzaSyAuvXtccaGYNVFuYXIfYPP8XiRazm5xlXA';

echo json_encode([
    'apiKey'    => $apiKey,
    'libraries' => ['marker'],
    'mapId'     => null,
], JSON_UNESCAPED_UNICODE);

/*
backup APIs

AIzaSyDJ_gNjSq_T8NjeAtRfgS3Xgl5vAFton10
AIzaSyAuvXtccaGYNVFuYXIfYPP8XiRazm5xlXA
AIzaSyBLFBDkebvsVjgNd5T_KjT1FBJINmbH8r8
AIzaSyANfZ6wm4a-kKshAOx0dWas7faGqqj6v_g
*/