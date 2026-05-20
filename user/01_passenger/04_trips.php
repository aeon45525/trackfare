<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$tripHistory = [];

if ($stmt = $conn->prepare(
    'SELECT tt.transaction_id, tt.fare_amount, tt.boarding_stop_id, tt.alighting_stop_id,
            bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop,
            t.status AS trip_status, r.display_name AS route_name
     FROM trip_transactions tt
     LEFT JOIN trips t ON tt.trip_id = t.trip_id
     LEFT JOIN routes r ON t.route_id = r.route_id
     LEFT JOIN stops bs ON tt.boarding_stop_id = bs.stop_id
     LEFT JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id
     WHERE tt.user_id = ?
     ORDER BY tt.transaction_id DESC
     LIMIT 10'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tripHistory[] = $row;
    }
    $stmt->close();
}

function formatTripStatus($status)
{
    if (empty($status) || strtolower($status) === 'completed') {
        return 'Completed';
    }

    return strtolower($status) === 'active' ? 'Active' : ucfirst($status);
}

function formatTripRoute(array $trip)
{
    if (!empty($trip['boarding_stop']) && !empty($trip['alighting_stop'])) {
        return $trip['boarding_stop'] . ' → ' . $trip['alighting_stop'];
    }

    if (!empty($trip['route_name'])) {
        return $trip['route_name'];
    }

    return 'Trip record';
}
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - Trip History</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "tertiary-fixed-dim": "#ffb783",
              "on-primary-fixed-variant": "#0040a1",
              "surface-container": "#edeeef",
              "on-tertiary-container": "#ffd0b0",
              "on-secondary": "#ffffff",
              "on-surface": "#191c1d",
              "outline-variant": "#c3c6d6",
              "tertiary-fixed": "#ffdcc5",
              "surface-tint": "#0056d2",
              surface: "#f8f9fa",
              "surface-container-highest": "#e1e3e4",
              "tertiary-container": "#944b00",
              "on-error": "#ffffff",
              "on-primary-fixed": "#001847",
              "on-surface-variant": "#424654",
              "secondary-fixed-dim": "#afcae2",
              secondary: "#486176",
              tertiary: "#713700",
              "surface-dim": "#d9dadb",
              "surface-bright": "#f8f9fa",
              "on-secondary": "#ffffff",
              "on-surface": "#191c1d",
              "outline": "#737785",
              "inverse-surface": "#2e3132",
              "on-background": "#191c1d",
              background: "#f8f9fa",
              "inverse-primary": "#b2c5ff",
              "surface-container-lowest": "#ffffff",
              "secondary-fixed": "#cbe6ff",
              error: "#ba1a1a",
              "surface-container-low": "#f3f4f5",
              "primary-fixed": "#dae2ff",
              primary: "#0040a1",
              "inverse-on-surface": "#f0f1f2",
              "on-secondary-fixed-variant": "#30495d",
              "on-tertiary-fixed-variant": "#713700",
              "primary-container": "#0056d2",
              "error-container": "#ffdad6",
              "on-tertiary-fixed": "#301400",
              "on-primary-container": "#ccd8ff",
              "on-secondary-fixed": "#001e30",
              "surface-variant": "#e1e3e4",
              "on-tertiary": "#ffffff",
              "primary-fixed-dim": "#b2c5ff",
              "on-primary": "#ffffff",
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
      }
      body {
        margin: 0;
        min-height: max(884px, 100dvh);
        display: flex;
        justify-content: center;
        background: #f8f9fa;
        font-family: "Inter", sans-serif;
      }
      #app-shell {
        width: min(100%, 420px);
        min-height: 100dvh;
        position: relative;
      }
      .glass-nav {
        backdrop-filter: blur(20px);
      }
    </style>
  </head>
  <body class="bg-surface text-on-surface antialiased">
    <div id="app-shell" class="w-full">
      <!-- TopAppBar -->
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div>
            <p
              class="text-xs uppercase tracking-[0.25em] text-on-surface-variant"
            >
              TrackFare
            </p>
            <h1
              class="font-['Manrope'] font-bold text-lg tracking-tight text-[#0040a1]"
            >
              Trips
            </h1>
          </div>
          <img
            src="../../images/pfp.png"
            alt="Profile photo"
            class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
          />
        </div>
      </header>
      <!-- Main Content Canvas -->
      <main class="pt-20 pb-28 px-4 min-h-screen space-y-4">
        <section class="space-y-3">
          <?php if (!empty($tripHistory)): ?>
            <?php foreach ($tripHistory as $trip): ?>
              <div
                class="rounded-3xl bg-surface-container-lowest p-4 border border-surface-container-high shadow-sm"
              >
                <p
                  class="text-xs uppercase tracking-[0.2em] text-on-surface-variant"
                >
                  Route
                </p>
                <p class="mt-2 font-semibold text-on-surface">
                  <?= htmlspecialchars(formatTripRoute($trip), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div
                  class="mt-3 flex items-center justify-between text-sm text-on-surface-variant"
                >
                  <span>Fare</span>
                  <span>₱<?= number_format((float)$trip['fare_amount'], 2) ?></span>
                </div>
                <div
                  class="mt-2 flex items-center justify-between text-sm text-on-surface-variant"
                >
                  <span>Time</span>
                  <span>—</span>
                </div>
                <div
                  class="mt-3 inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800"
                >
                  <?= htmlspecialchars(formatTripStatus($trip['trip_status']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div
              class="rounded-3xl bg-surface-container-lowest p-4 border border-surface-container-high shadow-sm"
            >
              <p class="text-sm font-semibold text-on-surface">
                No trips yet
              </p>
            </div>
          <?php endif; ?>
        </section>
      </main>
      <!-- BottomNavBar -->
      <nav
        class="fixed bottom-0 left-0 w-full flex justify-around items-center px-2 pb-safe h-20 bg-white/95 backdrop-blur-md rounded-t-3xl z-50 border-t border-slate-200 shadow-[0_-4px_12px_rgba(0,0,0,0.05)]"
      >
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="01_home.php"
        >
          <span class="material-symbols-outlined">home</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Home</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="02_routes.php"
        >
          <span class="material-symbols-outlined">directions_bus</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Routes</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="03_wallet.php"
        >
          <span class="material-symbols-outlined">account_balance_wallet</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Wallet</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-white bg-primary rounded-3xl px-4 py-2 shadow-lg"
          href="04_trips.php"
        >
          <span class="material-symbols-outlined">history</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Trips</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="05_profile.php"
        >
          <span class="material-symbols-outlined">person</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Profile</span
          >
        </a>
      </nav>
    </div>
  </body>
</html>
