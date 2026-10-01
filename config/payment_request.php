<?php

function payment_request_respond(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function payment_request_id_hash(string $requestId): string
{
    return hash('sha256', $requestId);
}

function payment_request_money_to_cents($value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $value = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function payment_request_cents_to_amount(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Create a server-owned payment request and return its TrackFare QR payload.
 * Pass a null amount with minimum and maximum values for an open-amount request.
 */
function create_trackfare_payment_request(
    mysqli $conn,
    string $merchantName,
    ?string $amount,
    string $expiresAt,
    ?string $description = null,
    ?string $minimumAmount = null,
    ?string $maximumAmount = null
): string {
    $merchantName = trim($merchantName);
    $expiryTimestamp = strtotime($expiresAt);
    if ($merchantName === '' || strlen($merchantName) > 120
        || $expiryTimestamp === false || $expiryTimestamp <= time()
    ) {
        throw new InvalidArgumentException('Invalid payment request details.');
    }

    $amountCents = $amount === null ? null : payment_request_money_to_cents($amount);
    $minimumCents = $minimumAmount === null ? null : payment_request_money_to_cents($minimumAmount);
    $maximumCents = $maximumAmount === null ? null : payment_request_money_to_cents($maximumAmount);
    if (($amount !== null && ($amountCents === null || $amountCents < 1))
        || ($amount === null && ($minimumCents === null || $maximumCents === null
            || $minimumCents < 1 || $maximumCents < $minimumCents))
    ) {
        throw new InvalidArgumentException('Invalid payment amount or amount limits.');
    }

    $requestId = bin2hex(random_bytes(16));
    $requestHash = payment_request_id_hash($requestId);
    $amountValue = $amountCents === null ? null : payment_request_cents_to_amount($amountCents);
    $minimumValue = $minimumCents === null ? null : payment_request_cents_to_amount($minimumCents);
    $maximumValue = $maximumCents === null ? null : payment_request_cents_to_amount($maximumCents);
    $currency = 'PHP';
    $description = $description === null ? null : trim(substr($description, 0, 180));

    $stmt = $conn->prepare(
        'INSERT INTO payment_requests
         (request_id_hash, merchant_name, description, amount, min_amount, max_amount, currency, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create the payment request.');
    }
    $stmt->bind_param(
        'ssssssss',
        $requestHash,
        $merchantName,
        $description,
        $amountValue,
        $minimumValue,
        $maximumValue,
        $currency,
        $expiresAt
    );
    $created = $stmt->execute();
    $stmt->close();
    if (!$created) {
        throw new RuntimeException('Unable to create the payment request.');
    }

    return 'trackfare://pay?v=1&r=' . $requestId;
}