<?php

function trackfare_payment_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    return $default;
}

function is_gcash_configured()
{
    $apiKey = trackfare_payment_env('PAYMONGO_SECRET_KEY', '');
    return $apiKey !== '' && $apiKey !== 'your_paymongo_secret_key';
}

function createGcashInvoice($userId, $amount, $payerEmail, $topupId, $referenceNumber = '', $gcashNumber = '')
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to process GCash payments.');
    }

    if (!is_gcash_configured()) {
        throw new RuntimeException(
            'GCash is not configured yet. Add your PayMongo sandbox secret key in the environment as PAYMONGO_SECRET_KEY and set PAYMONGO_WEBHOOK_SECRET.'
        );
    }

    $apiKey = trackfare_payment_env('PAYMONGO_SECRET_KEY');
    $baseUrl = rtrim(trackfare_payment_env('PAYMONGO_API_BASE_URL', 'https://api.paymongo.com/v2'), '/');
    $successUrl = trackfare_payment_env('TRACKFARE_GCASH_SUCCESS_URL', 'http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=success');
    $failureUrl = trackfare_payment_env('TRACKFARE_GCASH_FAILURE_URL', 'http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=failed');

    $checkoutReference = 'trackfare-gcash-' . (int) $topupId;
    $description = 'TrackFare wallet top-up';
    if ($referenceNumber !== '') {
        $description .= ' - ' . $referenceNumber;
    }

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => 'TrackFare wallet top-up',
                    'amount' => (int) round((float) $amount * 100),
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'description' => $description,
                'payment_method_types' => ['gcash'],
                'success_url' => $successUrl,
                'cancel_url' => $failureUrl,
                'reference_number' => $checkoutReference,
                'metadata' => [
                    'trackfare_topup_id' => (string) $topupId,
                    'trackfare_user_id' => (string) $userId,
                ],
                'billing' => [
                    'name' => 'TrackFare Passenger',
                    'email' => $payerEmail ?: 'passenger@example.com',
                ],
            ],
        ],
    ];

    $ch = curl_init($baseUrl . '/checkout_sessions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($apiKey . ':'),
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: ' . $checkoutReference,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Unable to contact the PayMongo payment gateway: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $message = $decoded['errors'][0]['detail'] ?? ($decoded['message'] ?? 'PayMongo rejected the request.');
        throw new RuntimeException($message);
    }

    $checkoutUrl = $decoded['data']['attributes']['checkout_url'] ?? null;
    if (!is_string($checkoutUrl) || $checkoutUrl === '') {
        throw new RuntimeException('PayMongo checkout session was not created successfully.');
    }

    return [
        'checkout_url' => $checkoutUrl,
        'checkout_session_id' => $decoded['data']['id'] ?? '',
        'raw' => $decoded,
    ];
}
