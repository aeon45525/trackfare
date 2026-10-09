<?php
date_default_timezone_set('Asia/Manila');

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "trackfare";

if (class_exists('mysqli')) {
    $conn = new mysqli($host, $user, $pass, $dbname);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    if (!$conn->query("SET time_zone = '+08:00'")) {
        die("Database timezone configuration failed: " . $conn->error);
    }
} elseif (class_exists('PDO')) {
    if (!extension_loaded('pdo_mysql')) {
        die("Database connection failed: PDO is available, but the PDO MySQL driver is not loaded. Please enable 'extension=pdo_mysql' in php.ini and restart Apache.");
    }

    try {
        $conn = new PDO("mysql:host={$host};dbname={$dbname}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $conn->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
} else {
    die("Database connection failed: mysqli or PDO is not available. Please enable mysqli or PDO in your PHP configuration.");
}
?>