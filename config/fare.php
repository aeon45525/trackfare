<?php
/**
 * Global fare calculation for TrackFare.
 * Rate: ₱15 for the first 5km, then ₱2.50 per succeeding km.
 * Distance is summed along route stop coordinates (haversine).
 */

const FARE_FIRST_KM_PHP       = 15.00;
const FARE_PER_KM_AFTER_PHP    = 2.50;
const FARE_INCLUDED_KM         = 5.0;

/** Normalize a PN532 UID so spaces, colons, and hyphens do not affect lookup. */
function normalize_nfc_uid(string $raw): string
{
    return preg_replace('/[^0-9A-F]/', '', strtoupper(trim($raw))) ?? '';
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

function resolve_trip_current_stop_id(mysqli $conn, array $trip): ?int
{
    $tripId = (int) ($trip['trip_id'] ?? 0);
    $routeId = (int) ($trip['route_id'] ?? 0);
    if ($tripId > 0 && $routeId > 0) {
        foreach ([__DIR__ . '/gps_simulation_state.json', __DIR__ . '/gps_state.json'] as $statePath) {
            if (!is_file($statePath)) {
                continue;
            }

            $state = json_decode((string) file_get_contents($statePath), true);
            $position = is_array($state) ? ($state['busPosition'] ?? null) : null;
            $updatedAt = (int) (is_array($state) ? ($state['updatedAt'] ?? 0) : 0);
            if (!is_array($state)
                || (int) ($state['tripId'] ?? 0) !== $tripId
                || (int) ($state['routeId'] ?? 0) !== $routeId
                || $updatedAt < time() - 30
                || $updatedAt > time() + 5
                || !is_array($position)
                || !isset($position['lat'], $position['lng'])
                || !is_numeric($position['lat'])
                || !is_numeric($position['lng'])
                || !is_finite((float) $position['lat'])
                || !is_finite((float) $position['lng'])
                || (float) $position['lat'] < -90
                || (float) $position['lat'] > 90
                || (float) $position['lng'] < -180
                || (float) $position['lng'] > 180
            ) {
                continue;
            }

            $nearestStopId = null;
            $nearestDistance = INF;
            foreach (fare_route_data($conn, $routeId)['stops'] as $stop) {
                $distance = haversine_km(
                    (float) $position['lat'],
                    (float) $position['lng'],
                    (float) $stop['lat'],
                    (float) $stop['lng']
                );
                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearestStopId = (int) $stop['stop_id'];
                }
            }

            if ($nearestStopId !== null) {
                return $nearestStopId;
            }
        }
    }

    return get_route_stop_id_at_index(
        $conn,
        $routeId,
        (int) ($trip['current_stop_index'] ?? -1)
    );
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
): ?string {
    if (!$conn->begin_transaction()) {
        return 'TRANSACTION FAILED';
    }

    try {
        if ($stmt = $conn->prepare(
            'SELECT active_id
             FROM active_passengers
             WHERE trip_id = ? AND user_id = ? AND card_id = ? AND boarding_stop_id = ? AND tap_state = ?
             FOR UPDATE'
        )) {
            $tapState = 'in';
            $stmt->bind_param('iiiis', $tripId, $userId, $cardId, $boardingStopId, $tapState);
            $stmt->execute();
            $active = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$active) {
                throw new RuntimeException('NOT TAPED IN');
            }
        } else {
            throw new RuntimeException('TRANSACTION FAILED');
        }

        if ($stmt = $conn->prepare(
            'SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? FOR UPDATE'
        )) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $wallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$wallet) {
                throw new RuntimeException('WALLET NOT FOUND');
            }
            if ((float) $wallet['wallet_balance'] < $fare) {
                throw new RuntimeException('INSUFFICIENT BALANCE');
            }
        } else {
            throw new RuntimeException('TRANSACTION FAILED');
        }

        if ($stmt = $conn->prepare(
            'INSERT INTO trip_transactions
             (trip_id, user_id, card_id, boarding_stop_id, alighting_stop_id, fare_amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        )) {
            $stmt->bind_param('iiiidd', $tripId, $userId, $cardId, $boardingStopId, $alightingStopId, $fare);
            $ok = $stmt->execute();
            $stmt->close();

            if (!$ok) {
                throw new Exception('transaction insert failed');
            }
        } else {
            throw new Exception('transaction insert prepare failed');
        }

        if ($stmt = $conn->prepare(
            'UPDATE passenger_profiles
             SET wallet_balance = wallet_balance - ?
             WHERE user_id = ? AND wallet_balance >= ?'
        )) {
            $stmt->bind_param('did', $fare, $userId, $fare);
            $ok = $stmt->execute();
            $updated = $stmt->affected_rows === 1;
            $stmt->close();

            if (!$ok || !$updated) {
                throw new Exception('wallet update failed');
            }
        } else {
            throw new Exception('wallet update prepare failed');
        }

        if ($stmt = $conn->prepare(
            'DELETE FROM active_passengers
             WHERE trip_id = ? AND user_id = ? AND card_id = ? AND boarding_stop_id = ? AND tap_state = ?'
        )) {
            $tapState = 'in';
            $stmt->bind_param('iiiis', $tripId, $userId, $cardId, $boardingStopId, $tapState);
            $ok = $stmt->execute();
            $deleted = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$ok || !$deleted) {
                throw new Exception('active passenger removal failed');
            }
        } else {
            throw new Exception('active passenger removal prepare failed');
        }

        if (!$conn->commit()) {
            throw new Exception('transaction commit failed');
        }
        return null;
    } catch (Throwable $e) {
        $conn->rollback();
        return $e->getMessage() === 'INSUFFICIENT BALANCE'
            || $e->getMessage() === 'WALLET NOT FOUND'
            || $e->getMessage() === 'NOT TAPED IN'
            ? $e->getMessage()
            : 'TRANSACTION FAILED';
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

    $transactionError = record_fare_transaction(
        $conn,
        $tripId,
        $userId,
        $cardId,
        $boardingStopId,
        $alightingStopId,
        $calc['fare']
    );
    if ($transactionError !== null) {
        return ['ok' => false, 'fare' => 0.0, 'distance_km' => 0.0, 'message' => $transactionError];
    }

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

/**
 * Get current fare information for an active passenger.
 * @return array{ok: bool, fare_now: float, distance_now: float, fare_max: float, distance_max: float, boarding_stop: string, current_stop_index: int, message: string}
 */
function get_passenger_fare_info(mysqli $conn, int $userId): array
{
    $active = null;
    if ($stmt = $conn->prepare(
        'SELECT ap.trip_id, ap.boarding_stop_id, t.route_id, t.current_stop_index, s.stop_name AS boarding_stop
         FROM active_passengers ap
         JOIN trips t ON ap.trip_id = t.trip_id
         JOIN stops s ON ap.boarding_stop_id = s.stop_id
         WHERE ap.user_id = ? AND t.status = ?'
    )) {
        $status = 'active';
        $stmt->bind_param('is', $userId, $status);
        $stmt->execute();
        $active = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$active) {
        return ['ok' => false, 'message' => 'NOT ON TRIP', 'fare_now' => 0.0, 'distance_now' => 0.0, 'fare_max' => 0.0, 'distance_max' => 0.0, 'boarding_stop' => '', 'current_stop_index' => 0];
    }

    $tripId = (int) $active['trip_id'];
    $routeId = (int) $active['route_id'];
    $boardingStopId = (int) $active['boarding_stop_id'];
    $currentStopIndex = (int) $active['current_stop_index'];

    $currentStopId = get_route_stop_id_at_index($conn, $routeId, $currentStopIndex);
    if ($currentStopId === null) {
        return ['ok' => false, 'message' => 'INVALID STOP', 'fare_now' => 0.0, 'distance_now' => 0.0, 'fare_max' => 0.0, 'distance_max' => 0.0, 'boarding_stop' => '', 'current_stop_index' => 0];
    }

    $fareData = fare_for_boarding_and_alighting($conn, $routeId, $boardingStopId, $currentStopId);

    $lastStopId = get_route_last_stop_id($conn, $routeId);
    $maxFareData = $lastStopId !== null
        ? fare_for_boarding_and_alighting($conn, $routeId, $boardingStopId, $lastStopId)
        : $fareData;

    return [
        'ok' => true,
        'fare_now' => $fareData['fare'],
        'distance_now' => $fareData['distance_km'],
        'fare_max' => $maxFareData['fare'],
        'distance_max' => $maxFareData['distance_km'],
        'boarding_stop' => $active['boarding_stop'],
        'current_stop_index' => $currentStopIndex,
        'message' => 'SUCCESS'
    ];
}
