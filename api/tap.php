<?php
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$handlers = [
    'card' => 'tapin.php',
    'phone_nfc' => 'phone_nfc_tap.php',
    'passenger' => 'passenger_tap.php',
    'register_phone_nfc' => 'register_phone_nfc.php',
];

if (!isset($handlers[$action])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown tap action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/' . $handlers[$action];