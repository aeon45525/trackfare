<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/fare.php';
require_once __DIR__ . '/../config/gps_simulation.php';

header('Content-Type: application/json; charset=utf-8');

function phone_nfc_response(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    phone_nfc_response(false, 'POST required.', 405);
}

$credentialId = strtolower(trim((string) ($_POST['credential_id'] ?? '')));
$challengeHex = strtolower(trim((string) ($_POST['challenge'] ?? '')));
$signatureHex = strtolower(trim((string) ($_POST['signature'] ?? '')));
$tripId = (int) ($_POST['trip_id'] ?? 0);
$busId = (int) ($_POST['bus_id'] ?? 0);

if (!preg_match('/^[a-f0-9]{32}$/', $credentialId)
    || !preg_match('/^[a-f0-9]{32}$/', $challengeHex)
    || $tripId < 1 || $busId < 1) {
    phone_nfc_response(false, 'Invalid phone tap request.', 400);
}

$signature = preg_match('/^(?:[a-f0-9]{2}){64,80}$/', $signatureHex) ? hex2bin($signatureHex) : false;
if ($signature === false || strlen($signature) < 64 || strlen($signature) > 80) {
    phone_nfc_response(false, 'Invalid phone signature.', 400);
}

$credential = null;
if ($stmt = $conn->prepare(
    'SELECT c.user_id, c.card_id, p.public_key
     FROM phone_nfc_credentials p
     JOIN users u ON u.user_id = p.user_id AND u.is_active = 1
     LEFT JOIN nfc_cards c ON c.user_id = p.user_id AND c.is_active = 1
     WHERE p.credential_id = ? AND p.is_active = 1 LIMIT 1'
)) {
    $stmt->bind_param('s', $credentialId);
    $stmt->execute();
    $credential = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$credential || !$credential['card_id']) {
    phone_nfc_response(false, 'No active passenger fare card is linked to this phone.', 403);
}

$publicKeyDer = base64_decode((string) $credential['public_key'], true);
if ($publicKeyDer === false) {
    phone_nfc_response(false, 'Stored phone credential is invalid.', 500);
}
$publicKey = openssl_pkey_get_public(
    "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKeyDer), 64, "\n") . "-----END PUBLIC KEY-----"
);
$challenge = hex2bin($challengeHex);
$signedMessage = $challenge === false ? false : 'TrackFareTapV1' . pack('N', $tripId) . $challenge;
if (!$publicKey || $signedMessage === false
    || openssl_verify($signedMessage, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
    phone_nfc_response(false, 'Phone authentication failed.', 403);
}

$stmt = $conn->prepare('INSERT IGNORE INTO phone_nfc_challenges (challenge) VALUES (?)');
if (!$stmt) {
    phone_nfc_response(false, 'Phone NFC replay protection is unavailable. Apply the phone NFC migration first.', 503);
}
$stmt->bind_param('s', $challengeHex);
$stmt->execute();
$challengeAccepted = $stmt->affected_rows === 1;
$stmt->close();
if (!$challengeAccepted) {
    phone_nfc_response(false, 'This phone tap challenge has already been used.', 409);
}

$userId = (int) $credential['user_id'];
$cardId = (int) $credential['card_id'];
$trip = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, bus_id, route_id, current_stop_index, status, driver_id, start_time
     FROM trips WHERE trip_id = ? AND bus_id = ? AND status = ? LIMIT 1'
)) {
    $activeStatus = 'active';
    $stmt->bind_param('iis', $tripId, $busId, $activeStatus);
    $stmt->execute();
    $trip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$trip) {
    phone_nfc_response(false, 'No active trip for this bus.', 404);
}

$routeId = (int) $trip['route_id'];
$stopId = resolve_trip_current_stop_id($conn, $trip);
if ($stopId === null) {
    phone_nfc_response(false, 'Unable to determine bus stop.', 409);
}

$existing = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, card_id, boarding_stop_id FROM active_passengers WHERE user_id = ? LIMIT 1'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($existing) {
    if ((int) $existing['trip_id'] !== $tripId) {
        phone_nfc_response(false, 'Already tapped in on another trip.', 409);
    }
    if (empty($trip['start_time'])) {
        phone_nfc_response(false, 'Wait for the driver to start before tapping out.', 409);
    }
    $result = process_passenger_tap_out(
        $conn, $tripId, $routeId, $userId, (int) $existing['card_id'],
        (int) $existing['boarding_stop_id'], $stopId
    );
    if (!$result['ok']) {
        phone_nfc_response(false, (string) $result['message'], 409);
    }
    phone_nfc_response(true, 'TAP OUT SUCCESS', 200, ['fare' => (float) $result['fare']]);
}

$stopLat = null;
$stopLng = null;
if ($stmt = $conn->prepare('SELECT lat, lng FROM stops WHERE stop_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $stopId);
    $stmt->execute();
    $stmt->bind_result($stopLat, $stopLng);
    $stmt->fetch();
    $stmt->close();
}

$stmt = $conn->prepare(
    'INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id, lat, lng, tap_in_time)
     VALUES (?, ?, ?, ?, ?, ?, NOW())'
);
if (!$stmt) {
    phone_nfc_response(false, 'Unable to start trip.', 500);
}
$stmt->bind_param('iiiidd', $tripId, $userId, $cardId, $stopId, $stopLat, $stopLng);
if (!$stmt->execute()) {
    $stmt->close();
    phone_nfc_response(false, 'Unable to start trip.', 409);
}
$stmt->close();
phone_nfc_response(true, 'TAP IN SUCCESS');