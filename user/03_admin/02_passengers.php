<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    require_once '../../config/db.php';
    header('Content-Type: application/json');

    function getPassengers($search = '', $status = 'active', $limit = 100, $offset = 0) {
        global $conn;
        $passengers = [];
        try {
            $where = "WHERE u.role = 'passenger'";
            if ($status === 'active') {
                $where .= " AND u.is_active = 1";
            } elseif ($status === 'inactive') {
                $where .= " AND u.is_active = 0";
            }
            if (!empty($search)) {
                $search = $conn->real_escape_string($search);
                $where .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR nc.uid LIKE '%$search%')";
            }
            $result = $conn->query("SELECT u.user_id, u.full_name, u.email, pp.wallet_balance, COALESCE(nc.uid, 'Not Assigned') as nfc_card_id, COALESCE(nc.is_active, 0) as card_active, u.is_active as user_active, (SELECT COUNT(*) FROM trip_transactions WHERE user_id = u.user_id) as total_trips, COALESCE((SELECT DATE_FORMAT(MAX(tap_in_time), '%b %d %h:%i %p') FROM active_passengers WHERE user_id = u.user_id AND tap_state = 'in'), '—') as last_tap_in FROM users u LEFT JOIN passenger_profiles pp ON u.user_id = pp.user_id LEFT JOIN nfc_cards nc ON u.user_id = nc.user_id $where ORDER BY u.full_name ASC LIMIT $limit OFFSET $offset");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $passengers[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log("Get passengers error: " . $e->getMessage());
        }
        return $passengers;
    }

    function getPassengerDetail($user_id) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $result = $conn->query("SELECT u.user_id, u.full_name, u.email, pp.wallet_balance, COALESCE(nc.uid, 'Not Assigned') as nfc_card_id, COALESCE(nc.is_active, 0) as card_active, u.is_active FROM users u LEFT JOIN passenger_profiles pp ON u.user_id = pp.user_id LEFT JOIN nfc_cards nc ON u.user_id = nc.user_id WHERE u.user_id = $user_id AND u.role = 'passenger' LIMIT 1");
            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }
        } catch (Exception $e) {
            error_log("Get passenger detail error: " . $e->getMessage());
        }
        return null;
    }

    function getPassengerTrips($user_id, $limit = 10) {
        global $conn;
        $trips = [];
        try {
            $user_id = intval($user_id);
            $result = $conn->query("SELECT tt.transaction_id, bs.stop_name as boarding_stop, as_stop.stop_name as alighting_stop, tt.fare_amount, t.start_time FROM trip_transactions tt JOIN stops bs ON tt.boarding_stop_id = bs.stop_id JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id JOIN trips t ON tt.trip_id = t.trip_id WHERE tt.user_id = $user_id ORDER BY tt.transaction_id DESC LIMIT $limit");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $trips[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log("Get passenger trips error: " . $e->getMessage());
        }
        return $trips;
    }

    function topupBalance($user_id, $amount) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $amount = floatval($amount);
            if ($amount <= 0) {
                return ['success' => false, 'message' => 'Amount must be greater than 0'];
            }
            $result = $conn->query("UPDATE passenger_profiles SET wallet_balance = wallet_balance + $amount WHERE user_id = $user_id");
            if ($result) {
                return ['success' => true, 'message' => 'Balance topped up successfully'];
            }
        } catch (Exception $e) {
            error_log("Topup balance error: " . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to top up balance'];
    }

    function deductFare($user_id, $fare_amount) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $fare_amount = floatval($fare_amount);
            $result = $conn->query("UPDATE passenger_profiles SET wallet_balance = wallet_balance - $fare_amount WHERE user_id = $user_id AND wallet_balance >= $fare_amount");
            if ($result && $conn->affected_rows > 0) {
                return ['success' => true, 'message' => 'Fare deducted successfully'];
            }
        } catch (Exception $e) {
            error_log("Deduct fare error: " . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Insufficient balance'];
    }

    function updateNfcCard($user_id, $uid, $active) {
        global $conn;
        try {
            $user_id = intval($user_id);
            $uid = $conn->real_escape_string($uid);
            $active = $active ? 1 : 0;

            $result = $conn->query("SELECT card_id FROM nfc_cards WHERE user_id = $user_id LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $conn->query("UPDATE nfc_cards SET uid = '$uid', is_active = $active WHERE user_id = $user_id");
            } else {
                $conn->query("INSERT INTO nfc_cards (user_id, uid, is_active) VALUES ($user_id, '$uid', $active)");
            }

            if ($conn->affected_rows >= 0) {
                return ['success' => true, 'message' => 'NFC card updated successfully'];
            }
        } catch (Exception $e) {
            error_log("Update NFC card error: " . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to update NFC card'];
    }

    function addPassenger($full_name, $email, $password) {
        global $conn;
        try {
            $full_name = trim($full_name);
            $email = trim($email);
            $password = trim($password);

            if ($full_name === '') {
                return ['success' => false, 'message' => 'Full name is required.'];
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
                return ['success' => false, 'message' => 'A passenger with this email already exists.'];
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO users (full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
            $role = 'passenger';
            $insert->bind_param('ssss', $full_name, $email, $hashedPassword, $role);

            if (!$insert->execute()) {
                return ['success' => false, 'message' => 'Unable to create passenger account.'];
            }

            $userId = $conn->insert_id;
            $profile = $conn->prepare('INSERT INTO passenger_profiles (user_id, wallet_balance) VALUES (?, 0.00)');
            $profile->bind_param('i', $userId);
            $profile->execute();

            return ['success' => true, 'message' => 'Passenger added successfully.'];
        } catch (Exception $e) {
            error_log('Add passenger error: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => 'Failed to add passenger.'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'topup':
                $user_id = $_POST['user_id'] ?? 0;
                $amount = $_POST['amount'] ?? 0;
                echo json_encode(topupBalance($user_id, $amount));
                break;
            case 'deduct':
                $user_id = $_POST['user_id'] ?? 0;
                $amount = $_POST['amount'] ?? 0;
                echo json_encode(deductFare($user_id, $amount));
                break;
            case 'update_nfc':
                $user_id = $_POST['user_id'] ?? 0;
                $uid = $_POST['uid'] ?? '';
                $active = $_POST['active'] ?? 0;
                echo json_encode(updateNfcCard($user_id, $uid, $active));
                break;
            case 'add_passenger':
                $full_name = $_POST['full_name'] ?? '';
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';
                echo json_encode(addPassenger($full_name, $email, $password));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
        exit;
    }

    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'list':
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'active';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            echo json_encode(getPassengers($search, $status, $limit, $offset));
            break;
        case 'detail':
            $user_id = $_GET['user_id'] ?? 0;
            echo json_encode(getPassengerDetail($user_id));
            break;
        case 'trips':
            $user_id = $_GET['user_id'] ?? 0;
            $limit = intval($_GET['limit'] ?? 10);
            echo json_encode(getPassengerTrips($user_id, $limit));
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
    <title>TrackFare Passenger Management</title>
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
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
                Passenger Management
              </h1>
              <p class="mt-2 text-slate-600">
                Manage passenger travel accounts, NFC tap credentials, and fare
                balances.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <div class="relative w-full max-w-md">
                <span
                  class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl"
                  >search</span
                >
                <input
                  class="w-full bg-surface-container-low border-none rounded-xl py-3 pl-11 pr-4 text-sm focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Search passenger, email, or NFC Card ID"
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
                Passenger roster
              </p>
              <p class="mt-2 text-sm text-slate-600">
                Browse active accounts and manage credentials.
              </p>
            </div>
            <button
              id="add-passenger-button"
              class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-4 py-2 text-xs font-semibold text-blue-700"
            >
              <span class="material-symbols-outlined">person_add</span>
              Add Passenger
            </button>
          </div>
        </header>
        <section
          class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
        >
          <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6"
          >
            <div>
              <h2 class="text-lg font-bold text-on-surface">
                Passenger Accounts
              </h2>
              <p class="text-sm text-slate-600">
                Passenger IDs, NFC Card IDs, fare balances, and recent tap
                activity.
              </p>
            </div>
            <div
              class="inline-flex items-center gap-2 rounded-full bg-surface-container px-4 py-2 text-xs font-semibold text-slate-600"
            >
              <span class="material-symbols-outlined text-sm">filter_list</span>
              Active accounts
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left">
              <thead class="bg-slate-50">
                <tr>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Passenger ID
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Name
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Email
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    NFC Card ID
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Balance
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Last Tap-In
                  </th>
                  <th
                    class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody id="passengers-tbody" class="divide-y divide-slate-200 bg-white"></tbody>
            </table>
          </div>
        </section>
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
      let currentStatus = '';

      // Load passengers data
      async function loadPassengers(page = 1, search = '', status = '') {
        try {
          const offset = (page - 1) * pageSize;
          let url = `02_passengers.php?action=list&limit=${pageSize}&offset=${offset}`;
          if (search) url += `&search=${encodeURIComponent(search)}`;
          if (status) url += `&status=${status}`;

          const res = await fetch(url);
          const passengers = await res.json();

          const tbody = document.getElementById('passengers-tbody');
          tbody.innerHTML = '';

          passengers.forEach(p => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-50 transition-colors';
            row.innerHTML = `
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">P-${String(p.user_id).padStart(5, '0')}</td>
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">${p.full_name}</td>
              <td class="px-4 py-3 text-sm text-slate-500">${p.email}</td>
              <td class="px-4 py-3 text-sm text-slate-700">${p.nfc_card_id || 'Not assigned'}</td>
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">₱${parseFloat(p.wallet_balance || 0).toFixed(2)}</td>
              <td class="px-4 py-3 text-sm text-slate-700">${p.last_tap_in || '—'}</td>
              <td class="px-4 py-3 text-sm flex flex-wrap gap-1">
                <button onclick="viewProfile(${p.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                  View Profile
                </button>
                <button onclick="manageNFC(${p.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                  Manage NFC
                </button>
                <button onclick="topupBalance(${p.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                  Top Up
                </button>
                <button onclick="viewTransactions(${p.user_id})" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                  Transactions
                </button>
              </td>
            `;
            tbody.appendChild(row);
          });
        } catch (error) {
          console.error('Error loading passengers:', error);
        }
      }

      const modal = document.getElementById('admin-modal');
      const modalTitle = document.getElementById('modal-title');
      const modalBody = document.getElementById('modal-body');

      function openModal(title, contentHtml) {
        modalTitle.textContent = title;
        modalBody.innerHTML = contentHtml;
        modal.classList.remove('hidden');
      }

      function closeModal() {
        modal.classList.add('hidden');
        modalBody.innerHTML = '';
      }

      function showError(message) {
        alert(message);
      }

      async function viewProfile(userId) {
        try {
          const detailRes = await fetch(`02_passengers.php?action=detail&user_id=${userId}`);
          const detail = await detailRes.json();
          const tripsRes = await fetch(`02_passengers.php?action=trips&user_id=${userId}&limit=5`);
          const trips = await tripsRes.json();

          openModal('Passenger Profile', `
            <div class="space-y-4 text-sm text-slate-700">
              <div class="grid gap-2 sm:grid-cols-2">
                <div><strong>Name:</strong> ${detail.full_name}</div>
                <div><strong>Email:</strong> ${detail.email}</div>
                <div><strong>Wallet Balance:</strong> ₱${parseFloat(detail.wallet_balance || 0).toFixed(2)}</div>
                <div><strong>Card ID:</strong> ${detail.nfc_card_id}</div>
                <div><strong>Card Active:</strong> ${detail.card_active == 1 ? 'Yes' : 'No'}</div>
                <div><strong>Status:</strong> ${detail.is_active == 1 ? 'Active' : 'Inactive'}</div>
              </div>
              <div>
                <h4 class="text-sm font-semibold text-slate-900">Recent Trips</h4>
                <ul class="mt-2 space-y-2">
                  ${trips.length ? trips.map(trip => `
                    <li class="rounded-2xl bg-slate-50 p-3">
                      <div class="text-sm font-semibold text-slate-900">${trip.boarding_stop} → ${trip.alighting_stop}</div>
                      <div class="text-xs text-slate-500">Fare: ₱${parseFloat(trip.fare_amount).toFixed(2)} • ${new Date(trip.start_time).toLocaleString()}</div>
                    </li>
                  `).join('') : '<li class="text-sm text-slate-500">No trips found.</li>'}
                </ul>
              </div>
            </div>
          `);
        } catch (error) {
          showError('Unable to load passenger profile.');
          console.error(error);
        }
      }

      async function manageNFC(userId) {
        try {
          const detailRes = await fetch(`02_passengers.php?action=detail&user_id=${userId}`);
          const detail = await detailRes.json();

          openModal('Manage NFC Card', `
            <form id="nfc-form" class="space-y-4 text-sm text-slate-700">
              <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">NFC Card UID</label>
                <input name="uid" value="${detail.nfc_card_id === 'Not Assigned' ? '' : detail.nfc_card_id}" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15" placeholder="Enter NFC UID" />
              </div>
              <div class="flex items-center gap-3">
                <input id="nfc-active" name="active" type="checkbox" ${detail.card_active == 1 ? 'checked' : ''} class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary" />
                <label for="nfc-active" class="text-sm text-slate-700">Card is active</label>
              </div>
              <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-2xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save NFC</button>
              </div>
            </form>
          `);

          document.getElementById('nfc-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const uid = event.target.uid.value.trim();
            const active = event.target.active.checked ? 1 : 0;
            const res = await fetch('02_passengers.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: `action=update_nfc&user_id=${userId}&uid=${encodeURIComponent(uid)}&active=${active}`
            });
            const result = await res.json();
            alert(result.message || 'NFC update complete.');
            if (result.success) {
              closeModal();
              loadPassengers(currentPage, currentSearch, currentStatus);
            }
          });
        } catch (error) {
          showError('Unable to load NFC card details.');
          console.error(error);
        }
      }

      function openAddPassengerModal() {
        openModal('Add Passenger', `
          <form id="add-passenger-form" class="space-y-4 text-sm text-slate-700">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Full Name</label>
              <input name="full_name" type="text" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15" placeholder="Enter passenger name" required />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Email</label>
              <input name="email" type="email" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15" placeholder="Enter passenger email" required />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Password</label>
              <input name="password" type="password" minlength="6" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15" placeholder="Set a password" required />
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-2xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Create Passenger</button>
            </div>
          </form>
        `);

        document.getElementById('add-passenger-form').addEventListener('submit', async (event) => {
          event.preventDefault();
          const form = event.target;
          const formData = new URLSearchParams(new FormData(form));
          formData.append('action', 'add_passenger');

          const res = await fetch('02_passengers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
          });
          const result = await res.json();
          alert(result.message || 'Passenger created successfully.');
          if (result.success) {
            closeModal();
            loadPassengers(currentPage, currentSearch, currentStatus);
          }
        });
      }

      function topupBalance(userId) {
        openModal('Top Up Balance', `
          <form id="topup-form" class="space-y-4 text-sm text-slate-700">
            <div>
              <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Amount (PHP)</label>
              <input name="amount" type="number" min="1" step="0.01" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15" placeholder="Enter amount" required />
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
              <button type="button" onclick="closeModal()" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
              <button type="submit" class="rounded-2xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Top Up</button>
            </div>
          </form>
        `);

        document.getElementById('topup-form').addEventListener('submit', async (event) => {
          event.preventDefault();
          const amount = event.target.amount.value;
          const res = await fetch('02_passengers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=topup&user_id=${userId}&amount=${encodeURIComponent(amount)}`
          });
          const result = await res.json();
          alert(result.message || 'Top-up completed.');
          if (result.success) {
            closeModal();
            loadPassengers(currentPage, currentSearch, currentStatus);
          }
        });
      }

      async function viewTransactions(userId) {
        try {
          const tripsRes = await fetch(`02_passengers.php?action=trips&user_id=${userId}&limit=10`);
          const trips = await tripsRes.json();
          openModal('Passenger Transactions', `
            <div class="space-y-3 text-sm text-slate-700">
              ${trips.length ? trips.map(trip => `
                <div class="rounded-2xl bg-slate-50 p-4">
                  <div class="font-semibold text-slate-900">${trip.boarding_stop} → ${trip.alighting_stop}</div>
                  <div class="text-xs text-slate-500">Fare: ₱${parseFloat(trip.fare_amount).toFixed(2)} • ${new Date(trip.start_time).toLocaleString()}</div>
                </div>
              `).join('') : '<div class="text-sm text-slate-500">No transaction records found.</div>'}
            </div>
          `);
        } catch (error) {
          showError('Unable to load transactions.');
          console.error(error);
        }
      }

      // Search functionality
      document.addEventListener('DOMContentLoaded', () => {
        loadPassengers(1);

        // Search input handler
        const searchInput = document.querySelector('input[placeholder*="Search"]');
        if (searchInput) {
          searchInput.addEventListener('input', (e) => {
            currentSearch = e.target.value;
            loadPassengers(1, currentSearch, currentStatus);
          });
        }

        // Add passenger button
        const addPassengerButton = document.getElementById('add-passenger-button');
        if (addPassengerButton) {
          addPassengerButton.addEventListener('click', () => openAddPassengerModal());
        }

        // Status filter buttons
        const statusBtns = document.querySelectorAll('[onclick*="filter"]');
        statusBtns.forEach(btn => {
          btn.addEventListener('click', () => {
            currentStatus = btn.dataset.status || '';
            loadPassengers(1, currentSearch, currentStatus);
          });
        });
      });
    </script>
  </body>
</html>
