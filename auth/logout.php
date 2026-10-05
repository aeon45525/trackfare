<?php
session_start();

if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['driver', 'passenger'], true)) {
    require_once __DIR__ . '/../config/db.php';

    $userId = (int) $_SESSION['user_id'];
    $role = (string) $_SESSION['role'];
    if ($stmt = $conn->prepare('UPDATE users SET lat = NULL, lng = NULL WHERE user_id = ? AND role = ?')) {
        $stmt->bind_param('is', $userId, $role);
        if (!$stmt->execute()) {
            error_log('Could not clear user location on logout: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log('Could not prepare location cleanup on logout: ' . $conn->error);
    }

    if ($role === 'passenger'
        && ($stmt = $conn->prepare('UPDATE active_passengers SET lat = NULL, lng = NULL WHERE user_id = ?'))
    ) {
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            error_log('Could not clear passenger trip location on logout: ' . $stmt->error);
        }
        $stmt->close();
    } elseif ($role === 'passenger') {
        error_log('Could not prepare passenger trip location cleanup: ' . $conn->error);
    }
}

session_unset();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>
