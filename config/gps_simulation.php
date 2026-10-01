<?php

function gps_simulation_state_file(): string
{
    return __DIR__ . '/gps_simulation_state.json';
}

function gps_simulation_read_state(): ?array
{
    $path = gps_simulation_state_file();
    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $state = json_decode($json, true);
    return is_array($state) ? $state : null;
}

function gps_simulation_start(mysqli $conn, int $routeId, int $tripId, int $busId): bool
{
    $stops = [];
    if ($stmt = $conn->prepare(
        'SELECT s.lat, s.lng
         FROM route_stops rs
         JOIN stops s ON s.stop_id = rs.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($stop = $result->fetch_assoc()) {
            $stops[] = ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']];
        }
        $stmt->close();
    }

    if (count($stops) < 2) {
        return false;
    }

    $now = microtime(true);
    $state = [
        'source' => 'tap-simulation',
        'routeId' => $routeId,
        'tripId' => $tripId,
        'busId' => $busId,
        'status' => 'running',
        'currentStopIndex' => 0,
        'legFrom' => 0,
        'legTo' => 1,
        'legProgress' => 0.0,
        'busPosition' => $stops[0],
        'lastTick' => $now,
    ];

    $file = fopen(gps_simulation_state_file(), 'c+');
    if (!$file || !flock($file, LOCK_EX)) {
        if ($file) {
            fclose($file);
        }
        return false;
    }

    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    $saved = $json !== false && ftruncate($file, 0) && rewind($file)
        && fwrite($file, $json) !== false && fflush($file);
    flock($file, LOCK_UN);
    fclose($file);

    return $saved;
}

function gps_simulation_tick(mysqli $conn, int $routeId, ?int $requestedTripId = null): array
{
    $trip = null;
    if ($requestedTripId !== null && $requestedTripId > 0) {
        $stmt = $conn->prepare(
            'SELECT trip_id, bus_id, current_stop_index, start_time
             FROM trips
             WHERE trip_id = ? AND route_id = ? AND status = ?
             LIMIT 1'
        );
        if ($stmt) {
            $active = 'active';
            $stmt->bind_param('iis', $requestedTripId, $routeId, $active);
            $stmt->execute();
            $trip = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($stmt = $conn->prepare(
        'SELECT trip_id, bus_id, current_stop_index, start_time
         FROM trips
         WHERE route_id = ? AND status = ?
         ORDER BY start_time DESC, trip_id DESC
         LIMIT 1'
    )) {
        $active = 'active';
        $stmt->bind_param('is', $routeId, $active);
        $stmt->execute();
        $trip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $stops = [];
    if ($stmt = $conn->prepare(
        'SELECT s.stop_id, s.stop_name, s.lat, s.lng
         FROM route_stops rs
         JOIN stops s ON s.stop_id = rs.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($stop = $result->fetch_assoc()) {
            $stops[] = [
                'stop_id' => (int) $stop['stop_id'],
                'name' => $stop['stop_name'],
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ];
        }
        $stmt->close();
    }

    if (!$trip || count($stops) < 2) {
        return ['available' => false, 'routeId' => $routeId, 'stops' => $stops];
    }

    $tripId = (int) $trip['trip_id'];
    $passengerCount = 0;
    if ($stmt = $conn->prepare('SELECT COUNT(*) AS passenger_count FROM active_passengers WHERE trip_id = ?')) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $passengerCount = (int) ($stmt->get_result()->fetch_assoc()['passenger_count'] ?? 0);
        $stmt->close();
    }

    $simulationState = gps_simulation_read_state();
    $simulationOngoing = !empty($trip['start_time'])
        && is_array($simulationState)
        && (int) ($simulationState['tripId'] ?? 0) === $tripId
        && (int) ($simulationState['routeId'] ?? 0) === $routeId
        && ($simulationState['status'] ?? '') === 'running';

    $deviceStatePath = __DIR__ . '/gps_state.json';
    if (!$simulationOngoing && is_file($deviceStatePath)) {
        $deviceJson = file_get_contents($deviceStatePath);
        $deviceState = $deviceJson === false ? null : json_decode($deviceJson, true);
        $devicePosition = is_array($deviceState) ? ($deviceState['busPosition'] ?? null) : null;
        $updatedAt = (int) (is_array($deviceState) ? ($deviceState['updatedAt'] ?? 0) : 0);
        if (is_array($deviceState)
            && in_array(($deviceState['source'] ?? ''), ['device', 'driver-browser'], true)
            && (int) ($deviceState['tripId'] ?? 0) === $tripId
            && (int) ($deviceState['busId'] ?? 0) === (int) $trip['bus_id']
            && (int) ($deviceState['routeId'] ?? 0) === $routeId
            && $updatedAt >= time() - 15
            && $updatedAt <= time() + 5
            && is_array($devicePosition)
            && isset($devicePosition['lat'], $devicePosition['lng'])
            && is_numeric($devicePosition['lat'])
            && is_numeric($devicePosition['lng'])
            && is_finite((float) $devicePosition['lat'])
            && is_finite((float) $devicePosition['lng'])
            && (float) $devicePosition['lat'] >= -90
            && (float) $devicePosition['lat'] <= 90
            && (float) $devicePosition['lng'] >= -180
            && (float) $devicePosition['lng'] <= 180
        ) {
            return [
                'available' => true,
                'routeId' => $routeId,
                'tripId' => $tripId,
                'busId' => (int) $trip['bus_id'],
                'stops' => $stops,
                'status' => 'running',
                'currentStopIndex' => max(0, min(count($stops) - 1, (int) ($deviceState['currentStopIndex'] ?? 0))),
                'busPosition' => [
                    'lat' => (float) $devicePosition['lat'],
                    'lng' => (float) $devicePosition['lng'],
                ],
                'legFrom' => null,
                'legTo' => null,
                'legProgress' => 0.0,
                'updatedAt' => $updatedAt,
                'source' => $deviceState['source'],
                'passengerCount' => $passengerCount,
            ];
        }
    }

    if (empty($trip['start_time'])) {
        return ['available' => false, 'routeId' => $routeId, 'stops' => $stops];
    }

    if ($passengerCount === 0 && !is_file(gps_simulation_state_file())) {
        return ['available' => false, 'routeId' => $routeId, 'stops' => $stops];
    }

    $file = fopen(gps_simulation_state_file(), 'c+');
    if (!$file || !flock($file, LOCK_EX)) {
        if ($file) {
            fclose($file);
        }
        return ['available' => false, 'routeId' => $routeId, 'stops' => $stops];
    }

    $raw = stream_get_contents($file);
    $state = $raw === false ? null : json_decode($raw, true);
    $sameTrip = is_array($state)
        && (int) ($state['tripId'] ?? 0) === $tripId
        && (int) ($state['routeId'] ?? 0) === $routeId;
    $now = microtime(true);

    if ($passengerCount === 0 && !$sameTrip) {
        flock($file, LOCK_UN);
        fclose($file);
        return ['available' => false, 'routeId' => $routeId, 'stops' => $stops];
    }

    if (!$sameTrip) {
        $index = max(0, min(count($stops) - 1, (int) $trip['current_stop_index']));
        $state = [
            'source' => 'tap-simulation',
            'routeId' => $routeId,
            'tripId' => $tripId,
            'busId' => (int) $trip['bus_id'],
            'status' => 'paused',
            'currentStopIndex' => $index,
            'legFrom' => $index,
            'legTo' => min($index + 1, count($stops) - 1),
            'legProgress' => 0.0,
            'busPosition' => ['lat' => $stops[$index]['lat'], 'lng' => $stops[$index]['lng']],
            'lastTick' => $now,
        ];
    }

    $lastIndex = count($stops) - 1;
    $tripStopIndex = max(0, min($lastIndex, (int) $trip['current_stop_index']));
    if ($passengerCount > 0
        && $sameTrip
        && (int) ($state['currentStopIndex'] ?? 0) >= $lastIndex
        && $tripStopIndex < $lastIndex
    ) {
        $state['status'] = 'paused';
        $state['currentStopIndex'] = $tripStopIndex;
        $state['legFrom'] = null;
        $state['legTo'] = null;
        $state['legProgress'] = 0.0;
        $state['busPosition'] = [
            'lat' => $stops[$tripStopIndex]['lat'],
            'lng' => $stops[$tripStopIndex]['lng'],
        ];
        $state['lastTick'] = $now;
    }

    if (($state['status'] ?? '') === 'running'
        && (int) ($state['currentStopIndex'] ?? 0) < $lastIndex
    ) {
        $elapsed = max(0.0, min(5.0, $now - (float) ($state['lastTick'] ?? $now)));
        $state['legProgress'] = (float) ($state['legProgress'] ?? 0.0) + ($elapsed / 60.0);
        $state['lastTick'] = $now;

        while ($state['legProgress'] >= 1.0) {
            $state['legProgress'] -= 1.0;
            $index = min($lastIndex, (int) $state['legTo']);
            $state['currentStopIndex'] = $index;
            if ($index >= $lastIndex) {
                $state['status'] = 'paused';
                $state['legFrom'] = null;
                $state['legTo'] = null;
                $state['legProgress'] = 0.0;
                break;
            }
            $state['legFrom'] = $index;
            $state['legTo'] = $index + 1;
        }
    } elseif ((int) ($state['currentStopIndex'] ?? 0) >= $lastIndex) {
        $state['status'] = 'paused';
        $state['legFrom'] = null;
        $state['legTo'] = null;
        $state['legProgress'] = 0.0;
        $state['lastTick'] = $now;
    }

    $state['source'] = 'tap-simulation';
    $state['passengerCount'] = $passengerCount;
    $state['updatedAt'] = time();
    if (($state['status'] ?? '') === 'running') {
        $from = max(0, min($lastIndex, (int) $state['legFrom']));
        $to = max(0, min($lastIndex, (int) $state['legTo']));
        $progress = max(0.0, min(1.0, (float) $state['legProgress']));
        $state['currentStopIndex'] = $from;
        $state['busPosition'] = [
            'lat' => $stops[$from]['lat'] + (($stops[$to]['lat'] - $stops[$from]['lat']) * $progress),
            'lng' => $stops[$from]['lng'] + (($stops[$to]['lng'] - $stops[$from]['lng']) * $progress),
        ];
    } else {
        $index = max(0, min($lastIndex, (int) ($state['currentStopIndex'] ?? 0)));
        $state['currentStopIndex'] = $index;
        $state['busPosition'] = ['lat' => $stops[$index]['lat'], 'lng' => $stops[$index]['lng']];
    }

    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, $json);
        fflush($file);
    }
    flock($file, LOCK_UN);
    fclose($file);

    $stopIndex = (int) $state['currentStopIndex'];
    if ($passengerCount > 0 && $stopIndex !== (int) $trip['current_stop_index']) {
        if ($stmt = $conn->prepare('UPDATE trips SET current_stop_index = ? WHERE trip_id = ? AND status = ?')) {
            $active = 'active';
            $stmt->bind_param('iis', $stopIndex, $tripId, $active);
            $stmt->execute();
            $stmt->close();
        }
    }

    return array_merge($state, ['available' => true, 'stops' => $stops]);
}