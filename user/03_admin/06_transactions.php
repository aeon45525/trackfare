<?php
require_once '../../config/db.php';

$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

$sql = "SELECT tt.transaction_id,
               u.full_name AS passenger_name,
               nc.uid AS nfc_uid,
               bs.stop_name AS boarding_stop,
               as_stop.stop_name AS alighting_stop,
               tt.fare_amount,
               t.start_time,
               COALESCE(r.display_name, r.route_name, 'Unknown Route') AS route_name
        FROM trip_transactions tt
        JOIN users u ON tt.user_id = u.user_id
        LEFT JOIN nfc_cards nc ON tt.card_id = nc.card_id
        JOIN stops bs ON tt.boarding_stop_id = bs.stop_id
        JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id
        LEFT JOIN trips t ON tt.trip_id = t.trip_id
        LEFT JOIN routes r ON t.route_id = r.route_id
        ORDER BY tt.transaction_id DESC";

$transactions = [];
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $result->free();
}

if ($exportCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="trackfare-transactions.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Transaction ID', 'Passenger', 'NFC UID', 'Tap Journey', 'Boarding Stop', 'Alighting Stop', 'Fare', 'Route Direction', 'Timestamp']);
    foreach ($transactions as $transaction) {
        fputcsv($output, [
            $transaction['transaction_id'], 
            $transaction['passenger_name'],
            $transaction['nfc_uid'] ?? 'N/A',
            sprintf('%s → %s', $transaction['boarding_stop'], $transaction['alighting_stop']),
            $transaction['boarding_stop'],
            $transaction['alighting_stop'],
            number_format((float)$transaction['fare_amount'], 2),
            $transaction['route_name'],
            $transaction['start_time'] ? date('Y-m-d H:i:s', strtotime($transaction['start_time'])) : '',
        ]);
    }
    fclose($output);
    exit;
}

$totalTransactions = count($transactions);
?>
<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Fare Transactions - TrackFare Admin</title>
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
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
                Fare Transactions
              </h1>
              <p class="mt-2 text-slate-600">
                Monitor completed NFC tap-in/tap-out journeys on the Balagtas ↔
                Monumento line.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <div class="relative w-full max-w-md">
                <span
                  class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl"
                  >search</span
                >
                <input
                  id="transaction-search"
                  class="w-full bg-surface-container-low border-none rounded-xl py-3 pl-11 pr-4 text-sm focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Search passenger, NFC tag, or route"
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
                Fare Transaction Logs
              </p>
              <p class="mt-2 text-sm text-slate-600">
                Complete NFC tap records for Balagtas ↔ Monumento operations.
              </p>
            </div>
            <button
              type="button"
              onclick="window.location.href='06_transactions.php?export=csv'"
              class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
            >
              <span class="material-symbols-outlined">download</span>
              Export
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
                Completed Passenger Trips
              </h2>
              <p class="text-sm text-slate-600">
                NFC journey records showing tap-in, tap-out, distance and fare.
              </p>
            </div>
            <div
              class="inline-flex items-center gap-2 rounded-full bg-surface-container px-4 py-2 text-xs font-semibold text-slate-600"
            >
              <span class="material-symbols-outlined text-sm">sort</span>
              Sort by date
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left">
              <thead class="bg-slate-50">
                <tr>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Passenger
                  </th>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Tap Journey
                  </th>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    NFC UID
                  </th>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Fare
                  </th>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Timestamp
                  </th>
                  <th
                    class="px-6 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                  >
                    Route Direction
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (count($transactions) === 0): ?>
                      <tr>
                        <td colspan="6" class="px-6 py-5 text-sm text-slate-600">
                          No transactions found.
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($transactions as $transaction): ?>
                        <?php
                          $boarding = htmlspecialchars($transaction['boarding_stop'], ENT_QUOTES, 'UTF-8');
                          $alighting = htmlspecialchars($transaction['alighting_stop'], ENT_QUOTES, 'UTF-8');
                          $routeDirection = htmlspecialchars($transaction['route_name'], ENT_QUOTES, 'UTF-8');
                          $timestamp = $transaction['start_time'] ? date('Y-m-d H:i:s', strtotime($transaction['start_time'])) : '—';
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors transaction-row" data-search="<?= htmlspecialchars(strtolower($transaction['passenger_name'] . ' ' . ($transaction['nfc_uid'] ?? '') . ' ' . $boarding . ' ' . $alighting . ' ' . $routeDirection), ENT_QUOTES, 'UTF-8'); ?>">
                          <td class="px-6 py-5 text-sm font-semibold text-slate-900">
                            <?= htmlspecialchars($transaction['passenger_name'], ENT_QUOTES, 'UTF-8'); ?>
                          </td>
                          <td class="px-6 py-5">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                              <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700"><?= $boarding; ?></span>
                              <span class="material-symbols-outlined text-blue-600">arrow_forward</span>
                              <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700"><?= $alighting; ?></span>
                            </div>
                            <div class="mt-2 text-xs uppercase tracking-[0.2em] text-slate-500">
                              Tap In → Tap Out
                            </div>
                          </td>
                          <td class="px-6 py-5 text-sm font-semibold text-slate-900">
                            <?= htmlspecialchars($transaction['nfc_uid'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                          </td>
                          <td class="px-6 py-5 text-sm font-semibold text-slate-900">
                            ₱<?= number_format((float)$transaction['fare_amount'], 2); ?>
                          </td>
                          <td class="px-6 py-5 text-sm text-slate-600">
                            <?= htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'); ?>
                          </td>
                          <td class="px-6 py-5">
                            <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"><?= $routeDirection; ?></span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
          </div>
          <div
            class="mt-6 flex items-center justify-between border-t border-slate-200 pt-4"
          >
            <span class="text-xs font-semibold text-slate-500"
              >Showing <?= $totalTransactions; ?> of <?= $totalTransactions; ?> transactions</span
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
      </main>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('transaction-search');
        const transactionRows = Array.from(document.querySelectorAll('.transaction-row'));

        if (!searchInput) {
          return;
        }

        searchInput.addEventListener('input', function () {
          const query = this.value.trim().toLowerCase();
          transactionRows.forEach(function (row) {
            row.style.display = query === '' || row.dataset.search.includes(query) ? '' : 'none';
          });
        });
      });
    </script>
  </body>
</html>
