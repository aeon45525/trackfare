<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    require_once '../../config/db.php';
    header('Content-Type: application/json');

    function getBuses($limit = 100, $offset = 0) {
        global $conn;
        $buses = [];
        try {
            $sql = "SELECT b.bus_id, b.bus_number, b.capacity, b.plate_number, 
                    COALESCE(dp.user_id, 0) AS driver_id,
                    COALESCE(u.full_name, 'Unassigned') AS driver_name,
                    COALESCE(IF(active_trip.trip_id IS NOT NULL, r.display_name, 'Unassigned'), 'Unassigned') AS assigned_route,
                    IF(active_trip.trip_id IS NOT NULL, 'Active', 'Inactive') AS status,
                    COALESCE(active_trip.current_stop, '-') AS current_stop
                    FROM buses b
                    LEFT JOIN driver_profiles dp ON b.bus_id = dp.assigned_bus_id
                    LEFT JOIN users u ON dp.user_id = u.user_id
                    LEFT JOIN (
                        SELECT t.driver_id, t.trip_id, t.route_id, t.bus_id, t.current_stop_index,
                               COALESCE(s.stop_name, CONCAT('Stop #', t.current_stop_index + 1)) AS current_stop
                        FROM trips t
                        LEFT JOIN route_stops rs ON rs.route_id = t.route_id AND rs.stop_order = t.current_stop_index + 1
                        LEFT JOIN stops s ON s.stop_id = rs.stop_id
                        WHERE t.status = 'active'
                    ) AS active_trip ON active_trip.bus_id = b.bus_id
                    LEFT JOIN routes r ON active_trip.route_id = r.route_id
                    ORDER BY b.bus_number ASC LIMIT $limit OFFSET $offset";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $buses[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log('Get buses error: ' . $e->getMessage());
        }
        return $buses;
    }

    function countBuses() {
        global $conn;
        $count = 0;
        try {
            $result = $conn->query("SELECT COUNT(*) AS total FROM buses");
            if ($result && $row = $result->fetch_assoc()) {
                $count = intval($row['total']);
            }
        } catch (Exception $e) {
            error_log('Count buses error: ' . $e->getMessage());
        }
        return $count;
    }

    function getBusDetail($bus_id) {
        global $conn;
        try {
            $bus_id = intval($bus_id);
            $sql = "SELECT b.bus_id, b.bus_number, b.capacity, b.plate_number,
                    COALESCE(dp.user_id, 0) AS driver_id,
                    COALESCE(u.full_name, 'Unassigned') AS driver_name,
                    COALESCE(IF(active_trip.trip_id IS NOT NULL, r.display_name, 'Unassigned'), 'Unassigned') AS assigned_route,
                    IF(active_trip.trip_id IS NOT NULL, 'Active', 'Inactive') AS status,
                    COALESCE(active_trip.current_stop, '-') AS current_stop
                    FROM buses b
                    LEFT JOIN driver_profiles dp ON b.bus_id = dp.assigned_bus_id
                    LEFT JOIN users u ON dp.user_id = u.user_id
                    LEFT JOIN (
                        SELECT t.driver_id, t.trip_id, t.route_id, t.bus_id, t.current_stop_index,
                               COALESCE(s.stop_name, CONCAT('Stop #', t.current_stop_index + 1)) AS current_stop
                        FROM trips t
                        LEFT JOIN route_stops rs ON rs.route_id = t.route_id AND rs.stop_order = t.current_stop_index + 1
                        LEFT JOIN stops s ON s.stop_id = rs.stop_id
                        WHERE t.status = 'active'
                    ) AS active_trip ON active_trip.bus_id = b.bus_id
                    LEFT JOIN routes r ON active_trip.route_id = r.route_id
                    WHERE b.bus_id = $bus_id LIMIT 1";
            $result = $conn->query($sql);
            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }
        } catch (Exception $e) {
            error_log('Get bus detail error: ' . $e->getMessage());
        }
        return null;
    }

    function addBus($bus_number, $plate_number, $capacity) {
        global $conn;
        try {
            $bus_number = trim($bus_number);
            $plate_number = trim($plate_number);
            $capacity = intval($capacity);

            if ($bus_number === '') {
                return ['success' => false, 'message' => 'Bus number is required.'];
            }
            if ($plate_number === '') {
                return ['success' => false, 'message' => 'Plate number is required.'];
            }
            if ($capacity <= 0) {
                return ['success' => false, 'message' => 'Capacity must be greater than 0.'];
            }

            $stmt = $conn->prepare('SELECT bus_id FROM buses WHERE plate_number = ? LIMIT 1');
            $stmt->bind_param('s', $plate_number);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                return ['success' => false, 'message' => 'A bus with that plate number already exists.'];
            }

            $insert = $conn->prepare('INSERT INTO buses (bus_number, plate_number, capacity) VALUES (?, ?, ?)');
            $insert->bind_param('ssi', $bus_number, $plate_number, $capacity);
            if (!$insert->execute()) {
                return ['success' => false, 'message' => 'Unable to add vehicle.'];
            }

            return ['success' => true, 'message' => 'Vehicle added successfully.'];
        } catch (Exception $e) {
            error_log('Add bus error: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to add vehicle.'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'add_bus':
                $bus_number = $_POST['bus_number'] ?? '';
                $plate_number = $_POST['plate_number'] ?? '';
                $capacity = $_POST['capacity'] ?? 0;
                echo json_encode(addBus($bus_number, $plate_number, $capacity));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
        exit;
    }

    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'list':
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            $buses = getBuses($limit, $offset);
            $total = countBuses();
            $totalActive = count(array_filter($buses, fn($b) => $b['status'] === 'Active'));
            echo json_encode(['buses' => $buses, 'total' => $total, 'active' => $totalActive]);
            break;
        case 'detail':
            $bus_id = intval($_GET['bus_id'] ?? 0);
            echo json_encode(getBusDetail($bus_id));
            break;
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Fleet Management</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <link rel="icon" href="../../images/logo.png" type="image/png">
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "secondary-fixed-dim": "#afcae2",
              "tertiary-fixed-dim": "#ffb783",
              "surface-container": "#edeeef",
              error: "#ba1a1a",
              "on-primary-fixed-variant": "#0040a1",
              background: "#f8f9fa",
              "on-secondary-container": "#4e677c",
              tertiary: "#713700",
              "surface-dim": "#d9dadb",
              primary: "#0040a1",
              "on-tertiary-container": "#ffd0b0",
              "surface-tint": "#0056d2",
              "on-background": "#191c1d",
              "surface-container-low": "#f3f4f5",
              "on-primary-fixed": "#001847",
              "inverse-primary": "#b2c5ff",
              "surface-container-high": "#e7e8e9",
              "outline-variant": "#c3c6d6",
              "primary-fixed-dim": "#b2c5ff",
              "primary-container": "#0056d2",
              "primary-fixed": "#dae2ff",
              "on-secondary-fixed-variant": "#30495d",
              "on-primary": "#ffffff",
              "inverse-surface": "#2e3132",
              "on-tertiary": "#ffffff",
              "on-error-container": "#93000a",
              "surface-variant": "#e1e3e4",
              "inverse-on-surface": "#f0f1f2",
              outline: "#737785",
              "on-secondary-fixed": "#001e30",
              "surface-bright": "#f8f9fa",
              "on-primary-container": "#ccd8ff",
              "on-error": "#ffffff",
              secondary: "#486176",
              "on-surface": "#191c1d",
              "surface-container-lowest": "#ffffff",
              surface: "#f8f9fa",
              "secondary-fixed": "#cbe6ff",
              "on-tertiary-fixed": "#301400",
              "on-tertiary-fixed-variant": "#713700",
              "tertiary-fixed": "#ffdcc5",
              "surface-container-highest": "#e1e3e4",
              "secondary-container": "#cbe6ff",
              "on-surface-variant": "#424654",
              "on-secondary": "#ffffff",
              "tertiary-container": "#944b00",
              "error-container": "#ffdad6",
            },
            fontFamily: {
              headline: ["Manrope"],
              body: ["Inter"],
              label: ["Inter"],
            },
            borderRadius: {
              DEFAULT: "0.125rem",
              lg: "0.25rem",
              xl: "0.5rem",
              full: "0.75rem",
            },
          },
        },
      };
    </script>
    <style>
      .material-symbols-outlined {
        font-variation-settings:
          "FILL" 0,
          "wght" 400,
          "GRAD" 0,
          "opsz" 24;
        vertical-align: middle;
      }
      .status-active {
        background: rgba(0, 64, 161, 0.12);
        color: #0040a1;
      }
      .status-inactive {
        background: rgba(107, 114, 128, 0.16);
        color: #4b5563;
      }
    </style>
  </head>
  <body class="bg-background text-on-background font-body antialiased">
    <div class="flex min-h-screen">
      <aside
        class="fixed left-0 top-0 h-full w-72 bg-slate-50 border-r border-slate-200 z-50 flex flex-col"
      >
        <div class="px-6 py-8 border-b border-slate-200">
          <span
            class="text-2xl font-black tracking-tight text-blue-900 font-headline"
            >TrackFare</span
          >
          <p class="mt-2 text-sm text-slate-500">Fleet Manager Portal</p>
        </div>
        <nav class="flex-1 px-3 py-6 space-y-1">
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="01_dashboard.php"
          >
            <span class="material-symbols-outlined">dashboard</span>
            <span>Dashboard</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="02_passengers.php"
          >
            <span class="material-symbols-outlined">group</span>
            <span>Passengers</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="03_drivers.php"
          >
            <span class="material-symbols-outlined">badge</span>
            <span>Drivers</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
            href="04_fleet.php"
          >
            <span class="material-symbols-outlined">local_shipping</span>
            <span>Fleet</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="05_routes_fares.php"
          >
            <span class="material-symbols-outlined">alt_route</span>
            <span>Routes &amp; Fares</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="06_transactions.php"
          >
            <span class="material-symbols-outlined">payments</span>
            <span>Transactions</span>
          </a>
          <a
            class="hidden"
            href="07_analytics.php"
            aria-hidden="true"
            tabindex="-1"
          >
            <span class="material-symbols-outlined">insights</span>
            <span>Analytics</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="08_profile.php"
          >
            <span class="material-symbols-outlined">person</span>
            <span>Profile</span>
          </a>
        </nav>
        <div class="px-6 py-6 border-t border-slate-200">
          <div class="flex items-center gap-3">
            <div
              class="w-12 h-12 rounded-2xl overflow-hidden border border-slate-200"
            >
              <img
                src="../../images/pfp.png"
                alt="Fleet Manager"
                class="w-full h-full object-cover"
              />
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">Fleet Manager</p>
              <p class="text-xs text-slate-500">Admin</p>
            </div>
          </div>
          <a
            href="../../auth/logout.php"
            class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
          >
            <span class="material-symbols-outlined">logout</span>
            Logout
          </a>
        </div>
      </aside>

      <main class="flex-1 ml-72 p-8 min-h-screen">
        <header class="flex flex-col gap-6 mb-8">
          <div
            class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
          >
            <div>
              <h1
                class="text-4xl font-extrabold tracking-tight text-on-surface font-headline"
              >
                Fleet Operations
              </h1>
              <p class="mt-2 text-slate-600">
                Monitor active buses and operational route assignments.
              </p>
            </div>
            <button
              class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/10 hover:bg-blue-700 transition"
              onclick="openAddVehicleModal()"
            >
              <span class="material-symbols-outlined">add</span>
              Add Vehicle
            </button>
          </div>
        </header>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Total Vehicles
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface" id="total-vehicles">0</h2>
            <p class="mt-3 text-sm text-slate-600">
              Total fleet units active in the TrackFare system.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Active Vehicles
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface" id="active-vehicles">0</h2>
            <p class="mt-3 text-sm text-slate-600">
              Vehicles currently operating on active routes.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Active Routes
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface" id="active-routes">0</h2>
            <p class="mt-3 text-sm text-slate-600">
              Currently monitored TrackFare route assignments.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Assigned Drivers
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface" id="assigned-drivers">0</h2>
            <p class="mt-3 text-sm text-slate-600">
              Drivers assigned to operational fleet vehicles.
            </p>
          </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.5fr_0.8fr]">
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div
              class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <h2 class="text-lg font-bold text-on-surface">
                  Fleet Operations Overview
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                  Monitor active buses and operational route assignments.
                </p>
              </div>
              <div
                class="inline-flex items-center gap-2 rounded-full bg-surface-container px-4 py-2 text-xs font-semibold text-slate-600"
              >
                <span class="material-symbols-outlined text-sm"
                  >directions_bus</span
                >
                <span id="fleet-count">0</span> Fleet Units
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full text-left text-sm text-slate-600">
                <thead class="border-b border-slate-200 text-slate-500">
                  <tr>
                    <th class="px-4 py-3">Vehicle ID</th>
                    <th class="px-4 py-3">Plate Number</th>
                    <th class="px-4 py-3">Assigned Driver</th>
                    <th class="px-4 py-3">Current Route</th>
                    <th class="px-4 py-3">Operational Status</th>
                    <th class="px-4 py-3">Current Stop</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200" id="fleet-tbody">
                  <tr>
                    <td colspan="7" class="px-4 py-3 text-sm text-slate-500">Loading...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <aside class="space-y-4">
            <div
              class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
            >
              <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Vehicle Information
                  </p>
                  <h3 class="mt-2 text-lg font-bold text-on-surface" id="vehicle-info-name">
                    Select a Bus
                  </h3>
                  <p class="mt-1 text-sm text-slate-600">
                    Live operation summary for the selected fleet vehicle.
                  </p>
                </div>
                <span
                  class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" id="vehicle-info-status"
                  style="background: rgba(107, 114, 128, 0.16); color: #4b5563;"
                >
                  N/A
                </span>
              </div>
              <div class="grid gap-4 text-sm text-slate-600">
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Vehicle ID
                  </p>
                  <p class="mt-2 font-semibold text-slate-900" id="vehicle-info-id">-</p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Plate Number
                  </p>
                  <p class="mt-2 font-semibold text-slate-900" id="vehicle-info-plate">-</p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Assigned Route
                  </p>
                  <p class="mt-2 font-semibold text-slate-900" id="vehicle-info-route">
                    Unassigned
                  </p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Assigned Driver
                  </p>
                  <p class="mt-2 font-semibold text-slate-900" id="vehicle-info-driver">
                    Unassigned
                  </p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Current Stop
                  </p>
                  <p class="mt-2 font-semibold text-slate-900" id="vehicle-info-stop">
                    -
                  </p>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </main>
    </div>
    
    <div id="admin-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div class="w-full max-w-2xl rounded-[1.5rem] bg-white p-6 shadow-2xl">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 pb-4">
          <h2 id="modal-title" class="text-lg font-bold text-slate-900">Modal Title</h2>
          <button onclick="closeModal()" class="rounded-full bg-slate-100 p-2 text-slate-700 hover:bg-slate-200">✕</button>
        </div>
        <div id="modal-body" class="mt-4"></div>
      </div>
    </div>

    <script>
      let selectedBusId = null;

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      async function fetchJson(url) {
        const response = await fetch(url);
        if (!response.ok) {
          throw new Error(`Request failed: ${response.status}`);
        }
        return response.json();
      }

      function updateVehicleInfo(bus) {
        selectedBusId = bus.bus_id;
        document.getElementById('vehicle-info-name').textContent = `Bus ${bus.bus_number}`;
        document.getElementById('vehicle-info-id').textContent = `Bus ${bus.bus_number}`;
        document.getElementById('vehicle-info-plate').textContent = bus.plate_number || '-';
        document.getElementById('vehicle-info-route').textContent = bus.assigned_route || 'Unassigned';
        document.getElementById('vehicle-info-driver').textContent = bus.driver_name || 'Unassigned';
        document.getElementById('vehicle-info-stop').textContent = bus.current_stop || '-';
        
        const statusElement = document.getElementById('vehicle-info-status');
        if (bus.status === 'Active') {
          statusElement.textContent = 'Active';
          statusElement.style.background = 'rgba(0, 64, 161, 0.12)';
          statusElement.style.color = '#0040a1';
        } else {
          statusElement.textContent = 'Inactive';
          statusElement.style.background = 'rgba(107, 114, 128, 0.16)';
          statusElement.style.color = '#4b5563';
        }
      }

      async function loadFleetData() {
        try {
          const data = await fetchJson('04_fleet.php?action=list');
          const buses = Array.isArray(data.buses) ? data.buses : [];
          const total = Number(data.total) || 0;
          const active = Number(data.active) || 0;

          document.getElementById('total-vehicles').textContent = total;
          document.getElementById('active-vehicles').textContent = active;
          document.getElementById('fleet-count').textContent = total;

          // Count unique assigned drivers
          const uniqueDrivers = new Set(buses.filter(b => b.driver_id > 0).map(b => b.driver_id));
          document.getElementById('assigned-drivers').textContent = uniqueDrivers.size;

          // Count unique routes
          const uniqueRoutes = new Set(buses.filter(b => b.assigned_route !== 'Unassigned').map(b => b.assigned_route));
          document.getElementById('active-routes').textContent = uniqueRoutes.size;

          // Render table
          const tbody = document.getElementById('fleet-tbody');
          tbody.innerHTML = '';

          if (buses.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="px-4 py-3 text-sm text-slate-500">No vehicles found.</td></tr>`;
            return;
          }

          buses.forEach((bus, index) => {
            const row = document.createElement('tr');
            row.className = index % 2 === 0 ? 'bg-surface-container-lowest' : '';
            const statusClass = bus.status === 'Active' ? 'status-active' : 'status-inactive';
            row.innerHTML = `
              <td class="px-4 py-4 font-semibold text-slate-900">Bus ${bus.bus_number}</td>
              <td class="px-4 py-4">${escapeHtml(bus.plate_number)}</td>
              <td class="px-4 py-4">${escapeHtml(bus.driver_name)}</td>
              <td class="px-4 py-4">${escapeHtml(bus.assigned_route)}</td>
              <td class="px-4 py-4">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ${statusClass}">${bus.status}</span>
              </td>
              <td class="px-4 py-4">${escapeHtml(bus.current_stop)}</td>
              <td class="px-4 py-4">
                <div class="inline-flex gap-2">
                  <button type="button" onclick="viewBus(${bus.bus_id})" class="rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition">View</button>
                  ${bus.status === 'Inactive' ? `<button type="button" onclick="editBus(${bus.bus_id})" class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-surface-container transition">Edit</button>` : ''}
                </div>
              </td>
            `;
            tbody.appendChild(row);
          });

          // Set first bus as selected by default
          if (buses.length > 0) {
            updateVehicleInfo(buses[0]);
          }
        } catch (error) {
          console.error('Error loading fleet data:', error);
        }
      }

      function viewBus(busId) {
        const buses = Array.from(document.getElementById('fleet-tbody').querySelectorAll('tr')).map((row, index) => {
          const cells = row.querySelectorAll('td');
          if (cells.length > 0) {
            return {
              bus_id: busId,
              bus_number: cells[0].textContent.replace('Bus ', ''),
              plate_number: cells[1].textContent,
              driver_name: cells[2].textContent,
              assigned_route: cells[3].textContent,
              status: cells[4].textContent.trim(),
              current_stop: cells[5].textContent
            };
          }
        }).filter(Boolean);

        const bus = buses.find(b => b.bus_id === busId);
        if (bus) {
          updateVehicleInfo(bus);
        }
      }

      function editBus(busId) {
        openModal('Edit Vehicle', `
          <form id="modal-edit-bus-form" class="space-y-4 text-sm text-slate-700">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Bus Number</label>
              <input name="bus_number" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Bus number" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Plate Number</label>
              <input name="plate_number" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Plate number" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Capacity</label>
              <input name="capacity" type="number" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Capacity" />
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save Changes</button>
            </div>
          </form>
        `);
      }

      function openAddVehicleModal() {
        openModal('Add Vehicle', `
          <form id="modal-add-vehicle-form" class="space-y-4 text-sm text-slate-700">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Bus Number</label>
              <input name="bus_number" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Bus number" required />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Plate Number</label>
              <input name="plate_number" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Plate number" required />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Capacity (Passengers)</label>
              <input name="capacity" type="number" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Capacity" required />
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add Vehicle</button>
            </div>
          </form>
        `);

        document.getElementById('modal-add-vehicle-form').addEventListener('submit', async function (event) {
          event.preventDefault();
          const form = event.target;
          const formData = new URLSearchParams(new FormData(form));
          formData.append('action', 'add_bus');

          try {
            const response = await fetch('04_fleet.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: formData.toString()
            });
            const result = await response.json();
            alert(result.message || 'Vehicle added successfully.');
            if (result.success) {
              closeModal();
              loadFleetData();
            }
          } catch (error) {
            console.error('Unable to create vehicle:', error);
            alert('There was a problem adding the vehicle.');
          }
        });
      }

      function openModal(title, contentHtml) {
        const modal = document.getElementById('admin-modal');
        const modalTitle = document.getElementById('modal-title');
        const modalBody = document.getElementById('modal-body');
        if (!modal || !modalTitle || !modalBody) return;
        modalTitle.textContent = title;
        modalBody.innerHTML = contentHtml;
        modal.classList.remove('hidden');
      }

      function closeModal() {
        const modal = document.getElementById('admin-modal');
        const modalBody = document.getElementById('modal-body');
        if (!modal || !modalBody) return;
        modal.classList.add('hidden');
        modalBody.innerHTML = '';
      }

      document.addEventListener('DOMContentLoaded', async () => {
        loadFleetData();
      });
    </script>
