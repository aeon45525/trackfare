<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    if (isset($_GET['action']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/db.php';

$profileName = 'Fleet Manager';
$profileEmail = 'fleet.manager@trackfare.com';
$profileRole = 'Admin';
$profileStatus = 'Active';
$totalPassengers = 0;
$totalDrivers = 0;
$totalTransactions = 0;
$totalRoutes = 0;
$activeTrips = 0;
$adminRevenueTotal = 0.0;
$adminRevenueToday = 0.0;
$driverWalletsTotal = 0.0;

$adminResult = $conn->query("SELECT full_name, email, is_active FROM users WHERE role = 'admin' LIMIT 1");
if ($adminResult && ($adminRow = $adminResult->fetch_assoc())) {
    $profileName = $adminRow['full_name'] ?: $profileName;
    $profileEmail = $adminRow['email'] ?: $profileEmail;
    $profileStatus = $adminRow['is_active'] === '0' ? 'Inactive' : 'Active';
    $adminResult->free();
}

$countResult = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'passenger'");
if ($countResult) {
    $totalPassengers = (int)$countResult->fetch_assoc()['count'];
    $countResult->free();
}

$countResult = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'driver'");
if ($countResult) {
    $totalDrivers = (int)$countResult->fetch_assoc()['count'];
    $countResult->free();
}

$countResult = $conn->query("SELECT COUNT(*) AS count FROM trip_transactions");
if ($countResult) {
    $totalTransactions = (int)$countResult->fetch_assoc()['count'];
    $countResult->free();
}

$countResult = $conn->query("SELECT COUNT(*) AS count FROM routes");
if ($countResult) {
    $totalRoutes = (int)$countResult->fetch_assoc()['count'];
    $countResult->free();
}

$countResult = $conn->query("SELECT COUNT(*) AS count FROM trips WHERE status = 'active'");
if ($countResult) {
    $activeTrips = (int)$countResult->fetch_assoc()['count'];
    $countResult->free();
}

$revResult = $conn->query("SELECT COALESCE(SUM(admin_share),0) AS total, COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN admin_share ELSE 0 END),0) AS today FROM fare_splits");
if ($revResult) {
    $revRow = $revResult->fetch_assoc();
    $adminRevenueTotal = (float)$revRow['total'];
    $adminRevenueToday = (float)$revRow['today'];
    $revResult->free();
}
$walletResult = $conn->query("SELECT COALESCE(SUM(wallet_balance),0) AS total FROM driver_profiles");
if ($walletResult) {
    $driverWalletsTotal = (float)$walletResult->fetch_assoc()['total'];
    $walletResult->free();
}
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Admin Profile</title>
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
      .profile-pill {
        background: rgba(0, 64, 161, 0.08);
      }
    </style>
  </head>
  <body class="bg-background text-on-background font-body antialiased">
    <div class="flex min-h-screen">
      <?php
      $adminNavPage = basename($_SERVER['PHP_SELF'] ?? '');
      $adminNavItems = [
          ['file' => '01_dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
          ['file' => '02_passengers.php', 'icon' => 'group', 'label' => 'Passengers'],
          ['file' => '03_drivers.php', 'icon' => 'badge', 'label' => 'Drivers'],
          ['file' => '04_fleet.php', 'icon' => 'local_shipping', 'label' => 'Fleet'],
          ['file' => '05_routes_fares.php', 'icon' => 'alt_route', 'label' => 'Routes & Fares'],
          ['file' => '06_transactions.php', 'icon' => 'payments', 'label' => 'Transactions'],
          ['file' => '07_analytics.php', 'icon' => 'monitoring', 'label' => 'Analytics'],
          ['file' => '08_profile.php', 'icon' => 'person', 'label' => 'Profile'],
      ];
      ?>
      <aside class="fixed left-0 top-0 z-50 flex h-full w-72 flex-col border-r border-slate-200 bg-slate-50">
        <div class="border-b border-slate-200 px-6 py-8">
          <a href="01_dashboard.php" class="text-2xl font-black tracking-tight text-blue-900">TrackFare</a>
          <p class="mt-2 text-sm text-slate-500">Fleet Manager Portal</p>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-6" aria-label="Admin navigation">
          <?php foreach ($adminNavItems as $item): ?>
            <?php
            $isAnalyticsNavItem = $item['file'] === '07_analytics.php';
            $isActiveNavItem = $isAnalyticsNavItem
                ? in_array($adminNavPage, ['07_analytics.php', 'boarding_hotspot.php', 'peak_boarding_time.php'], true)
                : $adminNavPage === $item['file'];
            ?>
            <a class="flex items-center gap-3 rounded-r-full px-5 py-3 transition <?= $isActiveNavItem ? 'border-r-4 border-blue-700 bg-blue-50 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-100 hover:text-blue-700' ?>" href="<?= htmlspecialchars($item['file'], ENT_QUOTES, 'UTF-8') ?>" <?= $adminNavPage === $item['file'] ? 'aria-current="page"' : '' ?>>
              <span class="material-symbols-outlined"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
              <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
        <div class="border-t border-slate-200 px-6 py-6">
          <div class="flex items-center gap-3">
            <div class="h-12 w-12 overflow-hidden rounded-2xl border border-slate-200"><img src="../../images/pfp.png" alt="Fleet Manager" class="h-full w-full object-cover"></div>
            <div><p class="text-sm font-semibold text-slate-900">Fleet Manager</p><p class="text-xs text-slate-500">Admin</p></div>
          </div>
          <a href="../../auth/logout.php" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
            <span class="material-symbols-outlined">logout</span>Logout
          </a>
        </div>
      </aside>

      <main class="flex-1 ml-72 p-8 min-h-screen">
        <header class="flex flex-col gap-4 mb-8">
          <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <h1
                class="text-4xl font-extrabold tracking-tight text-on-surface font-headline"
              >
                Fleet Operations Profile
              </h1>
              <p class="mt-2 text-slate-600">
                Identity and operational controls for fleet management.
              </p>
            </div>
          </div>
        </header>

        <div class="grid grid-cols-1 gap-6">
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div
              class="flex flex-col items-center text-center gap-4 sm:flex-row sm:items-center sm:text-left"
            >
              <div
                class="relative inline-flex h-36 w-36 items-center justify-center rounded-[2rem] bg-surface-container border border-slate-200 overflow-hidden"
              >
                <img
                  src="../../images/pfp.png"
                  alt="Fleet Manager"
                  class="h-full w-full object-cover"
                />
              </div>
              <div>
                <h2 class="mt-2 text-3xl font-black text-on-surface">
                  <?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <div class="mt-2 flex items-center gap-3">
                  <div
                    class="inline-flex items-center gap-2 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-sm font-semibold"
                  >
                    <span class="material-symbols-outlined text-sm"
                      >shield</span
                    >
                    <?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                  <div
                    class="inline-flex items-center gap-2 rounded-full bg-surface-container px-3 py-1 text-sm font-semibold text-slate-600"
                  >
                    <span class="material-symbols-outlined text-sm"
                      >verified_user</span
                    >
                    Admin Portal
                  </div>
                </div>
                <p class="mt-3 text-sm text-slate-600">
                  <?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8'); ?>
                </p>
              </div>
            </div>
            <!-- System Overview: 2x2 grid -->
            <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-white p-5"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Assigned Role
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  Fleet operations &amp; analytics
                </p>
              </div>
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-white p-5"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Scope
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  Full fleet control (routes, drivers, vehicles)
                </p>
              </div>
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-white p-5"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Access Level
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  Administrative control
                </p>
              </div>
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-white p-5"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Status
                </p>
                <p class="mt-3">
                  <span
                    class="inline-flex items-center px-3 py-1 rounded-full <?= $profileStatus === 'Active' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?> font-semibold"
                  >
                    <?= htmlspecialchars($profileStatus, ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </p>
              </div>
            </div>

            <!-- Account Metrics / KPI cards -->
            <div class="mt-6 grid gap-4 sm:grid-cols-3">
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Total Passengers
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  <?= number_format($totalPassengers); ?>
                </p>
              </div>
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Total Drivers
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  <?= number_format($totalDrivers); ?>
                </p>
              </div>
              <div
                class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center"
              >
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Total Transactions
                </p>
                <p class="mt-3 text-lg font-semibold text-on-surface">
                  <?= number_format($totalTransactions); ?>
                </p>
              </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
              <div class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center">
                <p class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold">Admin Revenue (80%)</p>
                <p class="mt-3 text-lg font-semibold text-on-surface">₱<?= number_format($adminRevenueTotal, 2); ?></p>
              </div>
              <div class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center">
                <p class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold">Admin Revenue Today</p>
                <p class="mt-3 text-lg font-semibold text-on-surface">₱<?= number_format($adminRevenueToday, 2); ?></p>
              </div>
              <div class="rounded-[1.25rem] border border-slate-200 bg-surface-container p-5 text-center">
                <p class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold">Driver Wallets (20%)</p>
                <p class="mt-3 text-lg font-semibold text-on-surface">₱<?= number_format($driverWalletsTotal, 2); ?></p>
              </div>
            </div>

            <!-- Administrative Controls (clear defined actions) -->
            <div class="mt-8">
              <p
                class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
              >
                Administrative Controls
              </p>
              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <button
                  type="button"
                  onclick="window.location.href='01_dashboard.php'"
                  class="w-full inline-flex items-center justify-between rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition"
                >
                  <span>View Fleet Dashboard</span>
                  <span class="material-symbols-outlined">chevron_right</span>
                </button>
                <button
                  type="button"
                  onclick="window.location.href='03_drivers.php'"
                  class="w-full inline-flex items-center justify-between rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-surface-container transition"
                >
                  <span>Manage Drivers</span>
                  <span class="material-symbols-outlined">chevron_right</span>
                </button>
                <a
                  href="../../auth/logout.php"
                  class="w-full inline-flex items-center justify-between rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 hover:bg-red-100 transition"
                >
                  <span>Log out</span>
                  <span class="material-symbols-outlined">logout</span>
                </a>
                <button
                  type="button"
                  onclick="window.location.href='05_routes_fares.php'"
                  class="w-full inline-flex items-center justify-between rounded-2xl border border-blue-100 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-surface-container transition"
                >
                  <span>Manage Routes</span>
                  <span class="material-symbols-outlined">chevron_right</span>
                </button>
                <button
                  type="button"
                  class="hidden"
                  aria-hidden="true"
                  tabindex="-1"
                >
                  <span>View Analytics</span>
                  <span class="material-symbols-outlined">chevron_right</span>
                </button>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
  </body>
</html>
