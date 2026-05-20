<?php
require_once "../config/db.php";
require_once "../config/fare.php";

$uid = strtoupper(trim($_POST['uid']));
$trip_id = intval($_POST['trip_id']);


$user = $conn->query("
SELECT u.user_id, c.card_id
FROM users u
JOIN nfc_cards c ON u.user_id = c.user_id
WHERE UPPER(c.uid) = '$uid'
")->fetch_assoc();

if (!$user) exit("INVALID CARD");

$user_id = $user['user_id'];
$card_id = $user['card_id'];

$active = $conn->query("
SELECT * FROM active_passengers
WHERE user_id = $user_id AND trip_id = $trip_id
")->fetch_assoc();

if (!$active) exit("NOT TAPED IN");

$trip = $conn->query("
SELECT route_id, current_stop_index
FROM trips
WHERE trip_id = $trip_id AND status = 'active'
")->fetch_assoc();

if (!$trip) exit("INVALID TRIP");

$route_id = $trip['route_id'];
$current_index = $trip['current_stop_index'];

$alight = $conn->query("
SELECT stop_id, stop_order
FROM route_stops
WHERE route_id = $route_id
ORDER BY stop_order
LIMIT 1 OFFSET $current_index
")->fetch_assoc();

if (!$alight) exit("INVALID STOP");

$board = $conn->query("
SELECT stop_order
FROM route_stops
WHERE stop_id = {$active['boarding_stop_id']}
")->fetch_assoc();

if (!$board) exit("INVALID BOARDING STOP");

$cumulative = get_cumulative_for_route_id($conn, $route_id);
$board_order = (int) ($board['stop_order'] ?? 0);
$alight_order = (int) ($alight['stop_order'] ?? 0);
$distance_km = getDistanceBetweenIndices($board_order, $alight_order, $cumulative);
$fare = calculateFare($distance_km);

 $stmt = $conn->prepare("INSERT INTO trip_transactions
 (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount)
 VALUES (?, ?, ?, ?, ?, ?)");
 if ($stmt) {
	 $stmt->bind_param('iiiidd', $trip_id, $user_id, $card_id, $active['boarding_stop_id'], $alight['stop_id'], $fare);
	 $stmt->execute();
	 $stmt->close();
 } else {
	 // fallback to original query if prepare fails
	 $conn->query("INSERT INTO trip_transactions (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount) VALUES ($trip_id, $user_id, $card_id, {$active['boarding_stop_id']}, {$alight['stop_id']}, $fare)");
 }

$conn->query("
DELETE FROM active_passengers
WHERE user_id = $user_id AND trip_id = $trip_id
");

echo "TAP OUT SUCCESS | FARE: $fare";
?>