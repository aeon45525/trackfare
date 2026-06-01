<?php
/**
 * Global fare calculation for TrackFare.
 * Rate: ₱15 for the first km, then ₱2.50 per succeeding km.
 * Distance is summed along route stop coordinates (haversine).
 */

const FARE_FIRST_KM_PHP       = 15.00;
const FARE_PER_KM_AFTER_PHP    = 2.50;
const FARE_INCLUDED_KM         = 1.0;

/** Normalize UID from PN532 POST body (e.g. "62 BB 1D 07") for nfc_cards lookup. */
function normalize_nfc_uid(string $raw): string
{
    $uid = strtoupper(trim($raw));
    $uid = preg_replace('/\s+/', ' ', $uid) ?? $uid;

    return $uid;
}

function fare_policy_label(): string
{
    return sprintf(
        '₱%s first km + ₱%s/km after',
        number_format(FARE_FIRST_KM_PHP, 0),
        number_format(FARE_PER_KM_AFTER_PHP, 2)
    );
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function calculateFare(float $distanceKm): float
{
    $distanceKm = max(0.0, $distanceKm);
    if ($distanceKm <= 0.0) {
        return 0.0;
    }
    if ($distanceKm <= FARE_INCLUDED_KM) {
        return round(FARE_FIRST_KM_PHP, 2);
    }

    return round(FARE_FIRST_KM_PHP + (($distanceKm - FARE_INCLUDED_KM) * FARE_PER_KM_AFTER_PHP), 2);
}

function getDistanceBetweenIndices(int $fromIndex, int $toIndex, array $cumulativeDistances): float
{
    $max = count($cumulativeDistances) - 1;
    if ($max < 0) {
        return 0.0;
    }
    $from = max(0, min($max, $fromIndex));
    $to   = max(0, min($max, $toIndex));

    return round(abs($cumulativeDistances[$to] - $cumulativeDistances[$from]), 4);
}

/**
 * @param array<int, array{lat: float, lng: float}> $stops
 */
function build_cumulative_from_route_stops(array $stops): array
{
    $cumulative = [0.0];
    $count      = count($stops);
    for ($i = 1; $i < $count; $i++) {
        $prev = $stops[$i - 1];
        $curr = $stops[$i];
        $cumulative[] = round(
            $cumulative[$i - 1] + haversine_km(
                (float) $prev['lat'],
                (float) $prev['lng'],
                (float) $curr['lat'],
                (float) $curr['lng']
            ),
            4
        );
    }

    return $cumulative;
}

/** @return array{stops: list<array>, cumulative: list<float>} */
function fare_route_data(mysqli $conn, int $routeId): array
{
    static $cache = [];
    if (isset($cache[$routeId])) {
        return $cache[$routeId];
    }

    $stops = [];
    if ($stmt = $conn->prepare(
        'SELECT rs.stop_order, rs.stop_id, s.stop_name, s.lat, s.lng
         FROM route_stops rs
         JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $stops[] = [
                'stop_order' => (int) $row['stop_order'],
                'stop_id'    => (int) $row['stop_id'],
                'stop_name'  => $row['stop_name'],
                'lat'        => (float) $row['lat'],
                'lng'        => (float) $row['lng'],
            ];
        }
        $stmt->close();
    }

    $data = [
        'stops'      => $stops,
        'cumulative' => build_cumulative_from_route_stops($stops),
    ];
    $cache[$routeId] = $data;

    return $data;
}

function get_cumulative_for_route_id(mysqli $conn, int $routeId): array
{
    return fare_route_data($conn, $routeId)['cumulative'];
}

function route_stop_index_by_id(array $stops, int $stopId): int
{
    foreach ($stops as $i => $stop) {
        if ((int) $stop['stop_id'] === $stopId) {
            return $i;
        }
    }

    return -1;
}

function get_route_last_stop_id(mysqli $conn, int $routeId): ?int
{
    $stops = fare_route_data($conn, $routeId)['stops'];
    if ($stops === []) {
        return null;
    }

    return (int) $stops[count($stops) - 1]['stop_id'];
}

function get_route_stop_id_at_index(mysqli $conn, int $routeId, int $index): ?int
{
    $stops = fare_route_data($conn, $routeId)['stops'];
    if ($index < 0 || $index >= count($stops)) {
        return null;
    }

    return (int) $stops[$index]['stop_id'];
}

function fare_distance_between_stops(mysqli $conn, int $routeId, int $boardingStopId, int $alightingStopId): float
{
    $data = fare_route_data($conn, $routeId);
    $from = route_stop_index_by_id($data['stops'], $boardingStopId);
    $to   = route_stop_index_by_id($data['stops'], $alightingStopId);
    if ($from < 0 || $to < 0) {
        return 0.0;
    }

    return getDistanceBetweenIndices($from, $to, $data['cumulative']);
}

/** @return array{distance_km: float, fare: float} */
function fare_for_boarding_and_alighting(
    mysqli $conn,
    int $routeId,
    int $boardingStopId,
    int $alightingStopId
): array {
    $distanceKm = fare_distance_between_stops($conn, $routeId, $boardingStopId, $alightingStopId);

    return [
        'distance_km' => $distanceKm,
        'fare'        => calculateFare($distanceKm),
    ];
}

function record_fare_transaction(
    mysqli $conn,
    int $tripId,
    int $userId,
    int $cardId,
    int $boardingStopId,
    int $alightingStopId,
    float $fare
): bool {
    if ($stmt = $conn->prepare(
        'INSERT INTO trip_transactions
         (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount)
         VALUES (?, ?, ?, ?, ?, ?)'
    )) {
        $stmt->bind_param('iiiidd', $tripId, $userId, $cardId, $boardingStopId, $alightingStopId, $fare);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    return false;
}

function remove_active_passenger(mysqli $conn, int $tripId, int $userId): void
{
    if ($stmt = $conn->prepare(
        'DELETE FROM active_passengers WHERE trip_id = ? AND user_id = ?'
    )) {
        $stmt->bind_param('ii', $tripId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Tap-out: charge from boarding stop to alighting stop and remove from active list.
 *
 * @return array{ok: bool, fare: float, distance_km: float, message: string}
 */
function process_passenger_tap_out(
    mysqli $conn,
    int $tripId,
    int $routeId,
    int $userId,
    int $cardId,
    int $boardingStopId,
    int $alightingStopId
): array {
    $calc = fare_for_boarding_and_alighting($conn, $routeId, $boardingStopId, $alightingStopId);
    if ($calc['distance_km'] <= 0 && $boardingStopId !== $alightingStopId) {
        return ['ok' => false, 'fare' => 0.0, 'distance_km' => 0.0, 'message' => 'INVALID STOPS'];
    }

    if (!record_fare_transaction(
        $conn,
        $tripId,
        $userId,
        $cardId,
        $boardingStopId,
        $alightingStopId,
        $calc['fare']
    )) {
        return ['ok' => false, 'fare' => 0.0, 'distance_km' => 0.0, 'message' => 'TRANSACTION FAILED'];
    }

    remove_active_passenger($conn, $tripId, $userId);

    return [
        'ok'          => true,
        'fare'        => $calc['fare'],
        'distance_km' => $calc['distance_km'],
        'message'     => 'TAP OUT SUCCESS',
    ];
}

/**
 * Charge remaining passengers from boarding stop to the route terminus (last stop).
 * Used when a trip ends and passengers did not tap out.
 */
function settle_all_active_passengers_for_trip(mysqli $conn, int $tripId, int $routeId): int
{
    $lastStopId = get_route_last_stop_id($conn, $routeId);
    if ($lastStopId === null) {
        return 0;
    }

    $passengers = [];
    if ($stmt = $conn->prepare(
        'SELECT user_id, card_id, boarding_stop_id FROM active_passengers WHERE trip_id = ?'
    )) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $passengers[] = $row;
        }
        $stmt->close();
    }

    $settled = 0;
    foreach ($passengers as $p) {
        $result = process_passenger_tap_out(
            $conn,
            $tripId,
            $routeId,
            (int) $p['user_id'],
            (int) $p['card_id'],
            (int) $p['boarding_stop_id'],
            $lastStopId
        );
        if ($result['ok']) {
            $settled++;
        }
    }

    return $settled;
}

/** @return array{distance_km: float, fare: float} */
function fare_estimate_for_active_passenger(
    mysqli $conn,
    int $routeId,
    int $boardingStopId,
    int $alightingStopId
): array {
    return fare_for_boarding_and_alighting($conn, $routeId, $boardingStopId, $alightingStopId);
}
