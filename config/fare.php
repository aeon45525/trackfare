<?php
// Shared fare and distance helpers
// Uses the same segment distance model as the passenger/driver pages
function build_segment_distances(int $neededSegments): array
{
    $segmentDistances = [
        1.2, 0.9, 0.9, 0.8, 0.9, 1.1, 1.1, 0.9, 0.8, 1.0,
        1.0, 1.1, 1.0, 1.2, 1.4, 1.3, 1.2, 1.0, 0.9,
    ];

    $segmentDistances = array_slice($segmentDistances, 0, $neededSegments);
    while (count($segmentDistances) < $neededSegments) {
        $segmentDistances[] = 1.0;
    }

    return $segmentDistances;
}

function build_cumulative_distances(int $neededStops): array
{
    $neededSegments = max(0, $neededStops - 1);
    $segmentDistances = build_segment_distances($neededSegments);

    $cumulative = [0.0];
    for ($i = 0; $i < count($segmentDistances); $i++) {
        $cumulative[] = round($cumulative[$i] + $segmentDistances[$i], 2);
    }

    return $cumulative;
}

function calculateFare(float $distance): float
{
    if ($distance <= 5.0) {
        return 13.00;
    }

    $extraKm = (int) ceil($distance - 5.0);
    return 13.00 + ($extraKm * 2.25);
}

function getDistanceBetweenIndices(int $fromIndex, int $toIndex, array $cumulativeDistances): float
{
    $from = max(0, min(count($cumulativeDistances) - 1, $fromIndex));
    $to = max(0, min(count($cumulativeDistances) - 1, $toIndex));
    return round(abs($cumulativeDistances[$to] - $cumulativeDistances[$from]), 2);
}

function get_cumulative_for_route_id(mysqli $conn, int $routeId): array
{
    // Count stops for the route
    $neededStops = 0;
    if ($stmt = $conn->prepare('SELECT COUNT(*) AS c FROM route_stops WHERE route_id = ?')) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $neededStops = (int) ($row['c'] ?? 0);
        $stmt->close();
    }

    if ($neededStops <= 0) {
        // fallback default route with 20 stops
        $neededStops = 20;
    }

    return build_cumulative_distances($neededStops);
}

?>
