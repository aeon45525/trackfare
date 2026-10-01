<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment_request.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    payment_request_respond(false, 'GET required.', 405);
}
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    payment_request_respond(false, 'Please sign in as a passenger.', 401);
}

$requestId = strtolower(trim((string) ($_GET['id'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
    payment_request_respond(false, 'Unsupported QR code.', 422);
}

$requestHash = payment_request_id_hash($requestId);
$stmt = $conn->prepare(
    'SELECT merchant_name, description, amount, min_amount, max_amount, currency, status,
            expires_at, (expires_at <= NOW()) AS is_expired
     FROM payment_requests WHERE request_id_hash = ? LIMIT 1'
);
if (!$stmt) {
    payment_request_respond(false, 'Payment details are temporarily unavailable.', 500);
}
$stmt->bind_param('s', $requestHash);
$stmt->execute();
$paymentRequest = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$paymentRequest) {
    payment_request_respond(false, 'This payment request was not found.', 404);
}
if ($paymentRequest['status'] === 'pending' && (int) $paymentRequest['is_expired'] === 1) {
    $expireStmt = $conn->prepare(
        'UPDATE payment_requests SET status = "expired" WHERE request_id_hash = ? AND status = "pending"'
    );
    if ($expireStmt) {
        $expireStmt->bind_param('s', $requestHash);
        $expireStmt->execute();
        $expireStmt->close();
    }
    payment_request_respond(false, 'This payment request has expired.', 410);
}
if ($paymentRequest['status'] !== 'pending') {
    payment_request_respond(false, 'This payment request is no longer available.', 409);
}
if ($paymentRequest['currency'] !== 'PHP') {
    payment_request_respond(false, 'This payment request uses an unsupported currency.', 422);
}

payment_request_respond(true, 'Payment request found.', 200, [
    'merchant_name' => $paymentRequest['merchant_name'],
    'description' => $paymentRequest['description'],
    'status' => $paymentRequest['status'],
    'amount' => $paymentRequest['amount'],
    'currency' => $paymentRequest['currency'],
    'can_enter_amount' => $paymentRequest['amount'] === null,
    'min_amount' => $paymentRequest['min_amount'],
    'max_amount' => $paymentRequest['max_amount'],
    'expires_at' => $paymentRequest['expires_at'],
]);