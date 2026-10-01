<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function phone_nfc_json_error(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function ensure_phone_nfc_tables(mysqli $conn): bool
{
    $credentialTableSql = 'CREATE TABLE IF NOT EXISTS phone_nfc_credentials (
        credential_id CHAR(32) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        public_key VARCHAR(2048) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (credential_id),
        UNIQUE KEY uq_phone_nfc_user (user_id),
        KEY idx_phone_nfc_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $challengeTableSql = 'CREATE TABLE IF NOT EXISTS phone_nfc_challenges (
        challenge CHAR(32) NOT NULL,
        used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (challenge),
        KEY idx_phone_nfc_challenge_used_at (used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    try {
        return $conn->query($credentialTableSql) && $conn->query($challengeTableSql);
    } catch (Throwable $error) {
        error_log('Phone NFC schema setup failed: ' . $error->getMessage());
        return false;
    }
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    phone_nfc_json_error('Please sign in as a passenger first.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    phone_nfc_json_error('POST required.', 405);
}

if (!ensure_phone_nfc_tables($conn)) {
    phone_nfc_json_error(
        'Phone NFC setup could not create its database tables. Import migrations/20260928_phone_nfc.sql in phpMyAdmin or grant the MySQL user CREATE TABLE permission.',
        503
    );
}

$publicKeyBase64 = trim((string) ($_POST['public_key'] ?? ''));
$publicKeyDer = base64_decode($publicKeyBase64, true);
if ($publicKeyDer === false || strlen($publicKeyDer) > 1024) {
    phone_nfc_json_error('Invalid phone public key.', 400);
}

$publicKey = openssl_pkey_get_public(
    "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKeyDer), 64, "\n") . "-----END PUBLIC KEY-----"
);
$publicDetails = $publicKey ? openssl_pkey_get_details($publicKey) : false;
if (!$publicDetails || ($publicDetails['type'] ?? null) !== OPENSSL_KEYTYPE_EC
    || ($publicDetails['bits'] ?? 0) !== 256) {
    phone_nfc_json_error('Phone key must be an EC P-256 public key.', 400);
}

$userId = (int) $_SESSION['user_id'];
$credentialId = bin2hex(random_bytes(16));
$encodedKey = base64_encode($publicKeyDer);
$conn->begin_transaction();
$cardId = null;
$cardId = null;
$cardIsActive = null;
if ($stmt = $conn->prepare('SELECT card_id, is_active FROM nfc_cards WHERE user_id = ? LIMIT 1 FOR UPDATE')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($cardId, $cardIsActive);
    $stmt->fetch();
    $stmt->close();
}
if ($cardId && !$cardIsActive) {
    $conn->rollback();
    phone_nfc_json_error('Your fare card is inactive. Contact an administrator before linking phone NFC.', 403);
}
if (!$cardId) {
    $virtualUid = 'HCE-' . strtoupper(bin2hex(random_bytes(8)));
    $stmt = $conn->prepare('INSERT INTO nfc_cards (user_id, uid, is_active) VALUES (?, ?, 1)');
    if (!$stmt) {
        $conn->rollback();
        phone_nfc_json_error('Unable to create the phone fare-card record.', 500);
    }
    $stmt->bind_param('is', $userId, $virtualUid);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        phone_nfc_json_error('Unable to create the phone fare-card record.', 500);
    }
    $stmt->close();
}

$stmt = $conn->prepare(
    'UPDATE phone_nfc_credentials
     SET is_active = 0
     WHERE public_key = ? AND user_id <> ? AND is_active = 1'
);
if (!$stmt) {
    $conn->rollback();
    phone_nfc_json_error('Phone NFC account linking is unavailable.', 503);
}
$stmt->bind_param('si', $encodedKey, $userId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->rollback();
    phone_nfc_json_error('Unable to move this phone link to the signed-in passenger.', 500);
}
$stmt->close();

$stmt = $conn->prepare(
    'INSERT INTO phone_nfc_credentials (user_id, credential_id, public_key, is_active)
     VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE credential_id = VALUES(credential_id), public_key = VALUES(public_key), is_active = 1'
);
if (!$stmt) {
    $conn->rollback();
    phone_nfc_json_error('Phone NFC credential storage is unavailable. Check the Apache/PHP database connection.', 503);
}

$stmt->bind_param('iss', $userId, $credentialId, $encodedKey);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->rollback();
    phone_nfc_json_error('Unable to save phone NFC credential.', 500);
}
$stmt->close();
$conn->commit();

echo json_encode(['success' => true, 'credential_id' => $credentialId]);