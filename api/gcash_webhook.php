<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No payload received']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$webhookSecret = getenv('PAYMONGO_WEBHOOK_SECRET');
if ($webhookSecret === false && isset($_SERVER['PAYMONGO_WEBHOOK_SECRET'])) {
    $webhookSecret = $_SERVER['PAYMONGO_WEBHOOK_SECRET'];
}
$headerSignature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '';
if ($webhookSecret === false || trim($webhookSecret) === '' || $headerSignature === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Webhook authentication is not configured']);
    exit;
}

$signatureParts = [];
foreach (explode(',', $headerSignature) as $part) {
    [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
    $signatureParts[$key] = $value;
}
$timestamp = $signatureParts['t'] ?? '';
$modeSignature = ($payload['data']['livemode'] ?? false)
    ? ($signatureParts['li'] ?? '')
    : ($signatureParts['te'] ?? '');
if ($timestamp === '' || $modeSignature === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook signature']);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
if (!hash_equals($expected, $modeSignature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$event = $payload['data'] ?? [];
$eventType = (string) ($event['type'] ?? '');
if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Event acknowledged']);
    exit;
}

$session = $event['data'] ?? [];
$attributes = $session['attributes'] ?? [];
$metadata = $attributes['metadata'] ?? [];
$topupId = (int) ($metadata['trackfare_topup_id'] ?? 0);
if ($topupId < 1 && preg_match('/^trackfare-gcash-(\d+)$/', (string) ($attributes['reference_number'] ?? ''), $match)) {
    $topupId = (int) $match[1];
}

if ($topupId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown payment reference']);
    exit;
}

$conn->begin_transaction();
$walletStmt = $conn->prepare('SELECT user_id, amount, status FROM wallet_topups WHERE topup_id = ? FOR UPDATE');
if (!$walletStmt) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load top-up']);
    exit;
}
$walletStmt->bind_param('i', $topupId);
$walletStmt->execute();
$topupRow = $walletStmt->get_result()->fetch_assoc();
$walletStmt->close();

if (!$topupRow) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Top-up not found']);
    exit;
}

if ($topupRow['status'] !== 'completed') {
    $userId = (int) $topupRow['user_id'];
    $topupAmount = (float) $topupRow['amount'];
    $balanceStmt = $conn->prepare('UPDATE passenger_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?');
    $balanceStmt->bind_param('di', $topupAmount, $userId);
    $balanceStmt->execute();
    $balanceStmt->close();

    $markStmt = $conn->prepare('UPDATE wallet_topups SET status = "completed" WHERE topup_id = ?');
    $markStmt->bind_param('i', $topupId);
    $markStmt->execute();
    $markStmt->close();
}
$conn->commit();

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Webhook processed']);
