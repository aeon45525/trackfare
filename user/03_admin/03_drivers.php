<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    require_once '../../config/db.php';
    header('Content-Type: application/json');

    function getDrivers($search = '', $limit = 100, $offset = 0) {
        global $conn;
        $drivers = [];
        try {
            $where = "WHERE u.role = 'driver'";
            if (!empty($search)) {
                $search = $conn->real_escape_string($search);
                $where .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR b.bus_number LIKE '%$search%' OR r.display_name LIKE '%$search%')";
            }

            $sql = "SELECT u.user_id, u.full_name, u.email, COALESCE(dp.assigned_bus_id, 0) AS assigned_bus_id, COALESCE(b.bus_number, 'Unassigned') AS bus_number, COALESCE(IF(active_trip.trip_id IS NOT NULL, r.display_name, NULL), 'Unassigned') AS assigned_route, IF(active_trip.trip_id IS NOT NULL, 'On Route', 'Idle') AS status FROM users u LEFT JOIN driver_profiles dp ON u.user_id = dp.user_id LEFT JOIN buses b ON dp.assigned_bus_id = b.bus_id LEFT JOIN (SELECT t.driver_id, t.trip_id, t.route_id, t.bus_id FROM trips t WHERE t.status = 'active') AS active_trip ON active_trip.driver_id = u.user_id LEFT JOIN routes r ON active_trip.route_id = r.route_id $where ORDER BY u.full_name ASC LIMIT $limit OFFSET $offset";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $drivers[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log('Get drivers error: ' . $e->getMessage());
        }
        return $drivers;
    }

    function countDrivers($search = '') {
        global $conn;
        $count = 0;
        try {
            $where = "WHERE u.role = 'driver'";
            if (!empty($search)) {
                $search = $conn->real_escape_string($search);
                $where .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR b.bus_number LIKE '%$search%' OR r.display_name LIKE '%$search%')";
            }
            $sql = "SELECT COUNT(DISTINCT u.user_id) AS total FROM users u LEFT JOIN driver_profiles dp ON u.user_id = dp.user_id LEFT JOIN buses b ON dp.assigned_bus_id = b.bus_id LEFT JOIN (SELECT t.driver_id, t.route_id FROM trips t WHERE t.status = 'active') AS active_trip ON active_trip.driver_id = u.user_id LEFT JOIN routes r ON active_trip.route_id = r.route_id $where";
            $result = $conn->query($sql);
            if ($result && $row = $result->fetch_assoc()) {
                $count = intval($row['total']);
            }
        } catch (Exception $e) {
            error_log('Count drivers error: ' . $e->getMessage());
        }
        return $count;
    }

    function getDriverDetail($user_id) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $sql = "SELECT u.user_id, u.full_name, u.email, COALESCE(dp.assigned_bus_id, 0) AS assigned_bus_id, COALESCE(b.bus_number, 'Unassigned') AS bus_number, COALESCE(IF(active_trip.trip_id IS NOT NULL, r.display_name, NULL), 'Unassigned') AS assigned_route, COALESCE(active_trip.route_id, 0) AS route_id, IF(active_trip.trip_id IS NOT NULL, 'On Route', 'Idle') AS status, (SELECT COUNT(*) FROM trips WHERE driver_id = u.user_id) AS total_trips FROM users u LEFT JOIN driver_profiles dp ON u.user_id = dp.user_id LEFT JOIN buses b ON dp.assigned_bus_id = b.bus_id LEFT JOIN (SELECT t.driver_id, t.trip_id, t.route_id, t.bus_id FROM trips t WHERE t.status = 'active') AS active_trip ON active_trip.driver_id = u.user_id LEFT JOIN routes r ON active_trip.route_id = r.route_id WHERE u.user_id = $user_id AND u.role = 'driver' LIMIT 1";
            $result = $conn->query($sql);
            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }
        } catch (Exception $e) {
            error_log('Get driver detail error: ' . $e->getMessage());
        }
        return null;
    }

    function getRoutes() {
        global $conn;
        $routes = [];
        try {
            $result = $conn->query("SELECT route_id, display_name FROM routes ORDER BY display_name ASC");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $routes[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log('Get routes error: ' . $e->getMessage());
        }
        return $routes;
    }

    function getBuses() {
        global $conn;
        $buses = [];
        try {
            $result = $conn->query("SELECT bus_id, bus_number FROM buses ORDER BY bus_number ASC");
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

    function addDriver($full_name, $email, $password, $route_id, $bus_id, $status) {
        global $conn;
        try {
            $full_name = trim($full_name);
            $email = trim($email);
            $password = trim($password);
            $route_id = intval($route_id);
            $bus_id = intval($bus_id);

            if ($full_name === '') {
                return ['success' => false, 'message' => 'Driver name is required.'];
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'A valid email address is required.'];
            }
            if (strlen($password) < 6) {
                return ['success' => false, 'message' => 'Password must be at least 6 characters long.'];
            }

            $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                return ['success' => false, 'message' => 'A driver with that email already exists.'];
            }

            if ($status === 'On Route' && ($route_id <= 0 || $bus_id <= 0)) {
                return ['success' => false, 'message' => 'Please assign both a route and a bus before setting the driver on route.'];
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $role = 'driver';
            $insert = $conn->prepare('INSERT INTO users (full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
            $insert->bind_param('ssss', $full_name, $email, $hashedPassword, $role);
            if (!$insert->execute()) {
                return ['success' => false, 'message' => 'Unable to create driver account.'];
            }

            $userId = $conn->insert_id;
            $profile = $conn->prepare('INSERT INTO driver_profiles (user_id, assigned_bus_id) VALUES (?, ?)');
            $profile->bind_param('ii', $userId, $bus_id);
            $profile->execute();

            if ($status === 'On Route') {
                $insertTrip = $conn->prepare('INSERT INTO trips (bus_id, route_id, driver_id, status) VALUES (?, ?, ?, "active")');
                $insertTrip->bind_param('iii', $bus_id, $route_id, $userId);
                $insertTrip->execute();
            }

            return ['success' => true, 'message' => 'Driver added successfully.'];
        } catch (Exception $e) {
            error_log('Add driver error: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to add driver.'];
    }

    function updateDriver($user_id, $full_name, $email, $route_id, $bus_id, $status) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $full_name = trim($full_name);
            $email = trim($email);
            $route_id = intval($route_id);
            $bus_id = intval($bus_id);

            if ($full_name === '') {
                return ['success' => false, 'message' => 'Driver name is required.'];
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'A valid email address is required.'];
            }

            $checkEmail = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
            $checkEmail->bind_param('si', $email, $user_id);
            $checkEmail->execute();
            $checkEmail->store_result();
            if ($checkEmail->num_rows > 0) {
                return ['success' => false, 'message' => 'This email is already used by another driver.'];
            }

            $updateUser = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ?');
            $updateUser->bind_param('ssi', $full_name, $email, $user_id);
            if (!$updateUser->execute()) {
                return ['success' => false, 'message' => 'Unable to update driver information.'];
            }

            if ($bus_id > 0) {
                $updateProfile = $conn->prepare('UPDATE driver_profiles SET assigned_bus_id = ? WHERE user_id = ?');
                $updateProfile->bind_param('ii', $bus_id, $user_id);
                $updateProfile->execute();
            }

            if ($status === 'On Route' && $route_id > 0 && $bus_id > 0) {
                $checkTrip = $conn->prepare('SELECT trip_id FROM trips WHERE driver_id = ? AND status = "active" LIMIT 1');
                $checkTrip->bind_param('i', $user_id);
                $checkTrip->execute();
                $checkTrip->store_result();
                
                if ($checkTrip->num_rows > 0) {
                    // Update existing active trip with new route and bus
                    $updateTrip = $conn->prepare('UPDATE trips SET route_id = ?, bus_id = ? WHERE driver_id = ? AND status = "active"');
                    $updateTrip->bind_param('iii', $route_id, $bus_id, $user_id);
                    $updateTrip->execute();
                } else {
                    // Create new trip if none exists
                    $insertTrip = $conn->prepare('INSERT INTO trips (bus_id, route_id, driver_id, status) VALUES (?, ?, ?, "active")');
                    $insertTrip->bind_param('iii', $bus_id, $route_id, $user_id);
                    $insertTrip->execute();
                }
            } else if ($status === 'Idle') {
                // End any active trips when setting status to Idle
                $endTrip = $conn->prepare('UPDATE trips SET status = "completed" WHERE driver_id = ? AND status = "active"');
                $endTrip->bind_param('i', $user_id);
                $endTrip->execute();
            }

            return ['success' => true, 'message' => 'Driver updated successfully.'];
        } catch (Exception $e) {
            error_log('Update driver error: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to update driver.'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'add_driver':
                $full_name = $_POST['full_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';
                $route_id = $_POST['route_id'] ?? 0;
                $bus_id = $_POST['bus_id'] ?? 0;
                $status = $_POST['status'] ?? 'Idle';
                echo json_encode(addDriver($full_name, $email, $password, $route_id, $bus_id, $status));
                break;
            case 'update_driver':
                $user_id = $_POST['user_id'] ?? 0;
                $full_name = $_POST['full_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $route_id = $_POST['route_id'] ?? 0;
                $bus_id = $_POST['bus_id'] ?? 0;
                $status = $_POST['status'] ?? 'Idle';
                echo json_encode(updateDriver($user_id, $full_name, $email, $route_id, $bus_id, $status));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
        exit;
    }

    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'list':
            $search = $_GET['search'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            $drivers = getDrivers($search, $limit, $offset);
            $total = countDrivers($search);
            echo json_encode(['drivers' => $drivers, 'total' => $total]);
            break;
        case 'detail':
            $user_id = intval($_GET['user_id'] ?? 0);
            echo json_encode(getDriverDetail($user_id));
            break;
        case 'routes':
            echo json_encode(getRoutes());
            break;
        case 'buses':
            echo json_encode(getBuses());
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
    <title>Driver Management - TrackFare Admin</title>
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
            href="03_drivers.php"
          >
            <span class="material-symbols-outlined">badge</span>
            <span>Drivers</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="07_analytics.php"
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
          <button
            class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
          >
            <span class="material-symbols-outlined">logout</span>
            Logout
          </button>
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
                Driver Operations
              </h1>
              <p class="mt-2 text-slate-600">
                Manage fleet drivers, route assignments, and operational status.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <div class="relative w-full max-w-md">
                <span
                  class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl"
                  >search</span
                >
                <input
                  id="driver-search"
                  class="w-full bg-surface-container-low border-none rounded-xl py-3 pl-11 pr-4 text-sm focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Search drivers, route, or status"
                  type="text"
                />
              </div>
              <button
                class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-surface-container-lowest shadow-sm text-on-surface hover:bg-surface-container transition"
              >
                <span class="material-symbols-outlined">notifications</span>
              </button>
            </div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div
              class="rounded-3xl bg-white px-5 py-4 shadow-sm border border-slate-200"
            >
              <p
                class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
              >
                Driver Operations Panel
              </p>
              <p class="mt-2 text-sm text-slate-600">
                Operational overview of driver routes, bus assignments, and
                status.
              </p>
            </div>
            <button
              id="open-add-driver-modal"
              type="button"
              class="hidden"
            >
              <span class="material-symbols-outlined">person_add</span>
              Add Driver
            </button>
          </div>
        </header>
        <div class="grid gap-4 xl:grid-cols-3 mb-6">
          <section
            class="xl:col-span-2 bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div
              class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6"
            >
              <div>
                <h2 class="text-lg font-bold text-on-surface">Driver List</h2>
                <p class="text-sm text-slate-600">
                  Driver name, route assignment, and current status.
                </p>
              </div>
              <div
                class="inline-flex items-center gap-2 rounded-full bg-surface-container px-4 py-2 text-xs font-semibold text-slate-600"
              >
                <span class="material-symbols-outlined text-sm">sort</span>
                Sort by status
              </div>
            </div>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200 text-left">
                <thead class="bg-slate-50">
                  <tr>
                    <th
                      class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >
                      Driver Name
                    </th>
                    <th
                      class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >
                      Assigned Route
                    </th>
                    <th
                      class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >
                      Operational Status
                    </th>
                    <th
                      class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >
                      Bus Assignment
                    </th>
                    <th
                      class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 text-right"
                    >
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody id="drivers-tbody" class="divide-y divide-slate-200 bg-white"></tbody>
              </table>
            </div>
            <div
              class="mt-6 flex items-center justify-between border-t border-slate-200 pt-4"
            >
              <span id="driver-count" class="text-xs font-semibold text-slate-500"
                >Loading drivers...</span
              >
              <div class="flex items-center gap-2">
                <button
                  class="rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-100 transition"
                >
                  <span class="material-symbols-outlined text-sm"
                    >chevron_left</span
                  >
                </button>
                <button
                  class="rounded-lg bg-blue-50 px-3 py-2 text-primary font-semibold"
                >
                  1
                </button>
                <button
                  class="rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-100 transition"
                >
                  <span class="material-symbols-outlined text-sm"
                    >chevron_right</span
                  >
                </button>
              </div>
            </div>
          </section>
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div class="mb-6">
              <h2 class="text-lg font-bold text-on-surface">Add Driver</h2>
              <p class="text-sm text-slate-600">
                Create a new driver account and assign a route.
              </p>
            </div>
            <form id="sidebar-add-driver-form" class="space-y-4">
              <div class="grid gap-4 md:grid-cols-2">
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Driver Name</label
                  >
                  <input
                    id="driver-name"
                    name="full_name"
                    type="text"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    placeholder="Driver full name"
                  />
                </div>
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Email</label
                  >
                  <input
                    id="driver-email"
                    name="email"
                    type="email"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    placeholder="driver@example.com"
                  />
                </div>
              </div>
              <div class="grid gap-4 md:grid-cols-2">
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Password</label
                  >
                  <input
                    id="driver-password"
                    name="password"
                    type="password"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    placeholder="Enter password"
                  />
                </div>
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Assigned Route</label
                  >
                  <select
                    id="route-id"
                    name="route_id"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                  >
                    <option value="">Choose Route</option>
                  </select>
                </div>
              </div>
              <div class="grid gap-4 md:grid-cols-2">
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Assigned Bus Number</label
                  >
                  <select
                    id="bus-id"
                    name="bus_id"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                  >
                    <option value="">Choose bus</option>
                  </select>
                </div>
                <div>
                  <label
                    class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                    >Driver Status</label
                  >
                  <select
                    id="driver-status"
                    name="status"
                    class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                  >
                    <option value="Idle" selected>Idle</option>
                    <option value="On Route">On Route</option>
                  </select>
                </div>
              </div>
              <button
                class="w-full rounded-2xl bg-blue-600 px-5 py-4 text-sm font-semibold text-white hover:bg-blue-700 transition"
              >
                Add Driver to Fleet
              </button>
            </form>
          </section>
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
      let currentPage = 1;
      const pageSize = 10;
      let currentSearch = '';
      let routeOptions = [];
      let busOptions = [];

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

      async function loadRoutesAndBuses() {
        try {
          routeOptions = await fetchJson('03_drivers.php?action=routes');
          busOptions = await fetchJson('03_drivers.php?action=buses');
          populateSidebarOptions();
        } catch (error) {
          console.error('Unable to load routes and buses:', error);
        }
      }

      function populateSidebarOptions() {
        const routeSelect = document.getElementById('route-id');
        const busSelect = document.getElementById('bus-id');
        if (!routeSelect || !busSelect) return;

        routeSelect.innerHTML = '<option value="">Choose Route</option>' +
          routeOptions.map(route => `<option value="${route.route_id}">${escapeHtml(route.display_name)}</option>`).join('');

        busSelect.innerHTML = '<option value="">Choose bus</option>' +
          busOptions.map(bus => `<option value="${bus.bus_id}">${escapeHtml(bus.bus_number)}</option>`).join('');
      }

      async function loadDrivers(page = 1, search = '') {
        try {
          currentPage = page;
          currentSearch = search;
          const offset = (page - 1) * pageSize;
          const url = `03_drivers.php?action=list&limit=${pageSize}&offset=${offset}&search=${encodeURIComponent(search)}`;
          const data = await fetchJson(url);
          const drivers = Array.isArray(data.drivers) ? data.drivers : [];
          const total = Number(data.total) || drivers.length;

          const tbody = document.getElementById('drivers-tbody');
          const countLabel = document.getElementById('driver-count');
          tbody.innerHTML = '';
          if (countLabel) {
            countLabel.textContent = `Showing ${drivers.length} of ${total} drivers`;
          }

          if (drivers.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="px-4 py-3 text-sm text-slate-500">No drivers found.</td></tr>`;
            return;
          }

          drivers.forEach(driver => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-50 transition-colors';
            row.innerHTML = `
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">${escapeHtml(driver.full_name)}</td>
              <td class="px-4 py-3 text-sm text-slate-700">
                <div class="font-semibold text-slate-900">${escapeHtml(driver.assigned_route)}</div>
                <div class="mt-1 text-xs text-slate-500">${driver.status === 'On Route' ? 'Active trip' : 'Idle'}</div>
              </td>
              <td class="px-4 py-3 text-sm font-semibold ${driver.status === 'On Route' ? 'text-emerald-700' : 'text-slate-700'}">${escapeHtml(driver.status)}</td>
              <td class="px-4 py-3 text-sm text-slate-900">${escapeHtml(driver.bus_number)}</td>
              <td class="px-4 py-3 text-sm text-right flex flex-wrap gap-1 justify-end">
                <button type="button" onclick="editDriver(${driver.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">Edit</button>
                <button type="button" onclick="viewDriver(${driver.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</button>
              </td>
            `;
            tbody.appendChild(row);
          });
        } catch (error) {
          console.error('Error loading drivers:', error);
        }
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

      function showMessage(message) {
        alert(message);
      }

      async function viewDriver(userId) {
        try {
          const detail = await fetchJson(`03_drivers.php?action=detail&user_id=${userId}`);
          if (!detail || !detail.user_id) {
            showMessage('Driver details not found.');
            return;
          }
          openModal('Driver Details', `
            <div class="space-y-4 text-sm text-slate-700">
              <div><strong>Name:</strong> ${escapeHtml(detail.full_name)}</div>
              <div><strong>Email:</strong> ${escapeHtml(detail.email)}</div>
              <div><strong>Status:</strong> ${escapeHtml(detail.status)}</div>
              <div><strong>Assigned Route:</strong> ${escapeHtml(detail.assigned_route)}</div>
              <div><strong>Bus:</strong> ${escapeHtml(detail.bus_number)}</div>
              <div><strong>Total Trips:</strong> ${escapeHtml(detail.total_trips)}</div>
              <div class="pt-3">
                <button type="button" onclick="closeModal()" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Close</button>
              </div>
            </div>
          `);
        } catch (error) {
          console.error('Unable to load driver details:', error);
          showMessage('Unable to load driver details.');
        }
      }

      function contactDriver(userId) {
        const driverRow = document.querySelector(`#drivers-tbody tr button[onclick='viewDriver(${userId})']`);
        const name = driverRow ? driverRow.closest('tr').querySelector('td:first-child').textContent.trim() : 'Driver';
        openModal('Contact Driver', `
          <form id="contact-driver-form" class="space-y-4 text-sm text-slate-700">
            <div><strong>To:</strong> ${escapeHtml(name)}</div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Message</label>
              <textarea id="contact-message" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" rows="4">Hello ${escapeHtml(name)},</textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Send</button>
            </div>
          </form>
        `);
        document.getElementById('contact-driver-form').addEventListener('submit', function (event) {
          event.preventDefault();
          closeModal();
          showMessage('Message sent to ' + name + '.');
        });
      }

      async function editDriver(userId) {
        try {
          const detail = await fetchJson(`03_drivers.php?action=detail&user_id=${userId}`);
          if (!detail || !detail.user_id) {
            showMessage('Driver details not found.');
            return;
          }

          const routeHtml = routeOptions.length === 0 ? '<option value="">No routes available</option>' : '<option value="">Choose Route</option>' + routeOptions.map(route => `<option value="${route.route_id}" ${route.route_id == detail.route_id ? 'selected' : ''}>${escapeHtml(route.display_name)}</option>`).join('');
          const busHtml = busOptions.length === 0 ? '<option value="">No buses available</option>' : '<option value="">Choose bus</option>' + busOptions.map(bus => `<option value="${bus.bus_id}" ${bus.bus_id == detail.assigned_bus_id ? 'selected' : ''}>${escapeHtml(bus.bus_number)}</option>`).join('');

          openModal('Edit Driver', `
            <form id="modal-edit-driver-form" class="space-y-4 text-sm text-slate-700">
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Driver Name</label>
                <input id="edit-driver-name" name="full_name" type="text" value="${escapeHtml(detail.full_name)}" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Driver full name" />
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Email</label>
                <input id="edit-driver-email" name="email" type="email" value="${escapeHtml(detail.email)}" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="driver@example.com" />
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Assigned Route</label>
                <select id="edit-route-id" name="route_id" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">${routeHtml}</select>
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Assigned Bus</label>
                <select id="edit-bus-id" name="bus_id" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">${busHtml}</select>
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Driver Status</label>
                <select id="edit-driver-status" name="status" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                  <option value="Idle" ${detail.status === 'Idle' ? 'selected' : ''}>Idle</option>
                  <option value="On Route" ${detail.status === 'On Route' ? 'selected' : ''}>On Route</option>
                </select>
              </div>
              <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save Changes</button>
              </div>
            </form>
          `);

          document.getElementById('modal-edit-driver-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            const form = event.target;
            const formData = new URLSearchParams(new FormData(form));
            formData.append('action', 'update_driver');
            formData.append('user_id', userId);

            try {
              const response = await fetch('03_drivers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
              });
              const result = await response.json();
              showMessage(result.message || 'Driver updated successfully.');
              if (result.success) {
                closeModal();
                loadDrivers(currentPage, currentSearch);
              }
            } catch (error) {
              console.error('Unable to update driver:', error);
              showMessage('There was a problem updating the driver.');
            }
          });
        } catch (error) {
          console.error('Unable to load driver for editing:', error);
          showMessage('Unable to load driver for editing.');
        }
      }

      function openAddDriverModal() {
        const routeHtml = routeOptions.length === 0 ? '<option value="">No routes available</option>' : '<option value="">Choose Route</option>' + routeOptions.map(route => `<option value="${route.route_id}">${escapeHtml(route.display_name)}</option>`).join('');
        const busHtml = busOptions.length === 0 ? '<option value="">No buses available</option>' : '<option value="">Choose bus</option>' + busOptions.map(bus => `<option value="${bus.bus_id}">${escapeHtml(bus.bus_number)}</option>`).join('');

        openModal('Add Driver', `
          <form id="modal-add-driver-form" class="space-y-4 text-sm text-slate-700">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Driver Name</label>
              <input id="modal-driver-name" name="full_name" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Driver full name" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Email</label>
              <input id="modal-driver-email" name="email" type="email" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="driver@example.com" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Password</label>
              <input id="modal-driver-password" name="password" type="password" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Enter password" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Assigned Route</label>
              <select id="modal-route-id" name="route_id" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">${routeHtml}</select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Assigned Bus</label>
              <select id="modal-bus-id" name="bus_id" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">${busHtml}</select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Driver Status</label>
              <select id="modal-driver-status" name="status" class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="Idle" selected>Idle</option>
                <option value="On Route">On Route</option>
              </select>
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add Driver</button>
            </div>
          </form>
        `);

        document.getElementById('modal-add-driver-form').addEventListener('submit', async function (event) {
          event.preventDefault();
          const form = event.target;
          const formData = new URLSearchParams(new FormData(form));
          formData.append('action', 'add_driver');

          try {
            const response = await fetch('03_drivers.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: formData.toString()
            });
            const result = await response.json();
            showMessage(result.message || 'Driver added successfully.');
            if (result.success) {
              closeModal();
              loadDrivers(currentPage, currentSearch);
              loadRoutesAndBuses();
            }
          } catch (error) {
            console.error('Unable to create driver:', error);
            showMessage('There was a problem adding the driver.');
          }
        });
      }

      async function submitSidebarAddDriver(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new URLSearchParams(new FormData(form));
        formData.append('action', 'add_driver');

        try {
          const response = await fetch('03_drivers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
          });
          const result = await response.json();
          showMessage(result.message || 'Driver added successfully.');
          if (result.success) {
            form.reset();
            loadDrivers(currentPage, currentSearch);
            loadRoutesAndBuses();
          }
        } catch (error) {
          console.error('Unable to create driver:', error);
          showMessage('There was a problem adding the driver.');
        }
      }

      document.addEventListener('DOMContentLoaded', async () => {
        await loadRoutesAndBuses();
        loadDrivers();

        const searchInput = document.getElementById('driver-search');
        if (searchInput) {
          searchInput.addEventListener('input', event => {
            loadDrivers(1, event.target.value.trim());
          });
        }

        const openModalButton = document.getElementById('open-add-driver-modal');
        if (openModalButton) {
          openModalButton.addEventListener('click', openAddDriverModal);
        }

        const sidebarForm = document.getElementById('sidebar-add-driver-form');
        if (sidebarForm) {
          sidebarForm.addEventListener('submit', submitSidebarAddDriver);
        }
      });
    </script>
  </body>
</html>
