<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment_request.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payment_request_respond(false, 'POST required.', 405);
}
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    payment_request_respond(false, 'Please sign in as a passenger.', 401);
}
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    payment_request_respond(false, 'Invalid payment request.', 400);
}
$csrfToken = (string) ($_SESSION['payment_csrf'] ?? '');
if ($csrfToken === '' || !hash_equals($csrfToken, (string) ($payload['csrf_token'] ?? ''))) {
    payment_request_respond(false, 'Your session expired. Refresh the page and try again.', 403);
}

$requestId = strtolower(trim((string) ($payload['request_id'] ?? '')));
$idempotencyKey = strtolower(trim((string) ($payload['idempotency_key'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $requestId)
    || !preg_match('/^[a-f0-9]{32}$/', $idempotencyKey)
) {
    payment_request_respond(false, 'Invalid payment request.', 422);
}

$userId = (int) $_SESSION['user_id'];
$requestHash = payment_request_id_hash($requestId);
$idempotencyHash = hash('sha256', $idempotencyKey);
if (!$conn->begin_transaction()) {
    payment_request_respond(false, 'Payment could not be started. Try again.', 503);
}

try {
    $stmt = $conn->prepare(
        'SELECT merchant_name, amount, min_amount, max_amount, currency, status,
                (expires_at <= NOW()) AS is_expired
         FROM payment_requests WHERE request_id_hash = ? FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('s', $requestHash);
    $stmt->execute();
    $paymentRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$paymentRequest) {
        $conn->rollback();
        payment_request_respond(false, 'This payment request was not found.', 404);
    }

    $stmt = $conn->prepare(
        'SELECT request_id_hash, user_id, amount, currency
         FROM wallet_payment_transactions WHERE idempotency_hash = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('s', $idempotencyHash);
    $stmt->execute();
    $previousPayment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($previousPayment) {
        if (!hash_equals((string) $previousPayment['request_id_hash'], $requestHash)
            || (int) $previousPayment['user_id'] !== $userId
        ) {
            $conn->rollback();
            payment_request_respond(false, 'This idempotency key was already used.', 409);
        }
        $balanceStmt = $conn->prepare('SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? LIMIT 1');
        if (!$balanceStmt) {
            throw new RuntimeException('database error');
        }
        $balanceStmt->bind_param('i', $userId);
        $balanceStmt->execute();
        $currentWallet = $balanceStmt->get_result()->fetch_assoc();
        $balanceStmt->close();
        $conn->commit();
        payment_request_respond(true, 'Payment completed.', 200, [
            'merchant_name' => $paymentRequest['merchant_name'],
            'amount' => $previousPayment['amount'],
            'currency' => $previousPayment['currency'],
            'wallet_balance' => $currentWallet['wallet_balance'] ?? null,
            'idempotent_replay' => true,
        ]);
    }

    if ($paymentRequest['status'] !== 'pending') {
        $conn->rollback();
        payment_request_respond(false, 'This payment request has already been used or cancelled.', 409);
    }
    if ((int) $paymentRequest['is_expired'] === 1) {
        $expireStmt = $conn->prepare(
            'UPDATE payment_requests SET status = "expired" WHERE request_id_hash = ? AND status = "pending"'
        );
        if (!$expireStmt) {
            throw new RuntimeException('database error');
        }
        $expireStmt->bind_param('s', $requestHash);
        $expireStmt->execute();
        $expireStmt->close();
        $conn->commit();
        payment_request_respond(false, 'This payment request has expired.', 410);
    }
    if ($paymentRequest['currency'] !== 'PHP') {
        $conn->rollback();
        payment_request_respond(false, 'This payment request uses an unsupported currency.', 422);
    }

    if ($paymentRequest['amount'] !== null) {
        $amountCents = payment_request_money_to_cents((string) $paymentRequest['amount']);
    } else {
        $amountCents = payment_request_money_to_cents($payload['amount'] ?? null);
        $minimumCents = payment_request_money_to_cents((string) $paymentRequest['min_amount']);
        $maximumCents = payment_request_money_to_cents((string) $paymentRequest['max_amount']);
        if ($amountCents === null || $amountCents < 1
            || $minimumCents === null || $maximumCents === null
            || $amountCents < $minimumCents || $amountCents > $maximumCents
        ) {
            $conn->rollback();
            payment_request_respond(false, 'Enter an amount within the allowed range.', 422);
        }
    }
    if ($amountCents === null || $amountCents < 1) {
        $conn->rollback();
        payment_request_respond(false, 'This payment request has an invalid amount.', 422);
    }

    $stmt = $conn->prepare('SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $balanceCents = $wallet ? payment_request_money_to_cents((string) $wallet['wallet_balance']) : null;
    if ($balanceCents === null) {
        $conn->rollback();
        payment_request_respond(false, 'Your wallet is unavailable. Try again later.', 503);
    }
    if ($balanceCents < $amountCents) {
        $conn->rollback();
        payment_request_respond(false, 'Insufficient wallet balance. Top up and try again.', 402);
    }

    $amountValue = payment_request_cents_to_amount($amountCents);
    $stmt = $conn->prepare(
        'INSERT INTO wallet_payment_transactions
         (request_id_hash, user_id, idempotency_hash, amount, currency)
         VALUES (?, ?, ?, ?, "PHP")'
    );
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('siss', $requestHash, $userId, $idempotencyHash, $amountValue);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('database error');
    }
    $transactionId = (int) $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        'UPDATE passenger_profiles SET wallet_balance = wallet_balance - ?
         WHERE user_id = ? AND wallet_balance >= ?'
    );
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('sis', $amountValue, $userId, $amountValue);
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$updated) {
        throw new RuntimeException('wallet update failed');
    }

    $stmt = $conn->prepare(
        'UPDATE payment_requests SET status = "paid", paid_by = ?, paid_at = NOW()
         WHERE request_id_hash = ? AND status = "pending" AND expires_at > NOW()'
    );
    if (!$stmt) {
        throw new RuntimeException('database error');
    }
    $stmt->bind_param('is', $userId, $requestHash);
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$updated || !$conn->commit()) {
        throw new RuntimeException('payment commit failed');
    }

    payment_request_respond(true, 'Payment completed.', 200, [
        'merchant_name' => $paymentRequest['merchant_name'],
        'amount' => $amountValue,
        'currency' => 'PHP',
        'transaction_id' => $transactionId,
        'wallet_balance' => payment_request_cents_to_amount($balanceCents - $amountCents),
        'idempotent_replay' => false,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    payment_request_respond(false, 'Payment could not be completed. Try again.', 500);
}