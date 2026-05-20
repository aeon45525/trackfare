<?php
require_once "../config/db.php";

$uid = strtoupper(trim($_POST['uid']));
$trip_id = intval($_POST['trip_id']);

require_once "../config/fare.php";
$user = $conn->query("
SELECT u.user_id, c.card_id
FROM users u
JOIN nfc_cards c ON u.user_id = c.user_id
WHERE UPPER(c.uid) = '$uid'
")->fetch_assoc();

if (!$user) exit("INVALID CARD");

$user_id = $user['user_id'];
$card_id = $user['card_id'];

$trip = $conn->query("
SELECT trip_id, route_id, current_stop_index, status
FROM trips
WHERE trip_id = $trip_id AND status = 'active'
")->fetch_assoc();

if (!$trip) exit("NO ACTIVE TRIP");

$check = $conn->query("
SELECT 1 FROM active_passengers
WHERE user_id = $user_id AND trip_id = $trip_id
")->fetch_assoc();

if ($check) exit("ALREADY TAP IN");

$route_id = $trip['route_id'];
$current_index = $trip['current_stop_index'];

$boarding = $conn->query("
SELECT stop_id
FROM route_stops
WHERE route_id = $route_id
ORDER BY stop_order
LIMIT 1 OFFSET $current_index
")->fetch_assoc();

if (!$boarding) exit("INVALID STOP");

if ($stmt = $conn->prepare("INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES (?, ?, ?, ?)") ) {
	$stmt->bind_param('iiii', $trip_id, $user_id, $card_id, $boarding['stop_id']);
	$stmt->execute();
	$stmt->close();
} else {
	$conn->query("INSERT INTO active_passengers (trip_id, user_id, card_id, boarding_stop_id) VALUES ($trip_id, $user_id, $card_id, {$boarding['stop_id']})");
}

echo "TAP IN SUCCESS";
?>