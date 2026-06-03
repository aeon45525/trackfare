<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$walletBalance = 0.00;
$transactions = [];

// Fetch wallet balance
if ($stmt = $conn->prepare('SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $walletBalance = (float) $row['wallet_balance'];
    }
    $stmt->close();
}

// Handle top-up button click
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    $amount = (float) $_POST['amount'];
    if ($amount > 0) {
        $newBalance = $walletBalance + $amount;
        if ($stmt = $conn->prepare('UPDATE passenger_profiles SET wallet_balance = ? WHERE user_id = ?')) {
            $stmt->bind_param('di', $newBalance, $userId);
            $stmt->execute();
            $stmt->close();
            $walletBalance = $newBalance;
        }
    }
}

// Fetch transaction history
if ($stmt = $conn->prepare(
    'SELECT tt.transaction_id, tt.fare_amount, tt.boarding_stop_id, tt.alighting_stop_id, 
            bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop,
            r.display_name AS route_name
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
        $transactions[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Wallet';
$activeNav = 'wallet';
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="../../images/logo.png" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
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
              "on-secondary-container": "#4e677c",
              "on-error-container": "#93000a",
              "secondary-container": "#cbe6ff",
              "surface-container-high": "#e7e8e9",
              outline: "#737785",
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
      html {
        scrollbar-gutter: stable;
      }
      body {
        min-height: max(884px, 100dvh);
        margin: 0;
        display: flex;
        justify-content: center;
        background: #f8f9fa;
        overflow-x: hidden;
        -webkit-tap-highlight-color: transparent;
      }
      #app-shell {
        width: min(100%, 420px);
        min-height: 100dvh;
        position: relative;
      }
      .nav-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 4.25rem;
        height: 3.25rem;
        border-radius: 1rem;
        flex-shrink: 0;
        color: rgba(66, 70, 84, 0.6);
        transition: color 0.2s, background-color 0.2s, box-shadow 0.2s;
      }
      .nav-link:hover:not(.active) {
        color: #0040a1;
      }
      .nav-link.active {
        background: #0040a1;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 64, 161, 0.25);
      }
      .phone-panel {
        border: 1px solid #e1e3e4;
        border-radius: 1.75rem;
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      }
      .balance-panel {
        border-radius: 1.75rem;
        background: #0040a1;
        color: #ffffff;
        box-shadow: 0 10px 28px rgba(0, 64, 161, 0.22);
      }
      .icon-chip {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.875rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }
      .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 1.625rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 700;
        white-space: nowrap;
      }
      .status-idle {
        background: #e7e8e9;
        color: #424654;
      }
      .status-active {
        background: #dae2ff;
        color: #0040a1;
      }
      @keyframes pop-in {
        from {
          transform: scale(0.96);
          opacity: 0;
        }
        to {
          transform: scale(1);
          opacity: 1;
        }
      }
    </style>
  </head>
  <body class="bg-surface font-body text-on-surface">
    <div id="app-shell" class="w-full">
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div class="flex items-center gap-3 min-w-0 flex-1">
            <img
              src="../../images/logo.png"
              alt=""
              class="h-9 w-9 object-contain shrink-0"
              aria-hidden="true"
            />
            <div class="min-w-0">
              <h1
                class="font-headline font-extrabold text-lg leading-tight tracking-tight text-on-surface truncate"
              >
                TrackFare
              </h1>
              <p class="text-xs text-on-surface-variant truncate">
                <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
              </p>
            </div>
          </div>
          <a href="05_profile.php" class="shrink-0">
            <img
              src="../../images/pfp.png"
              alt="Profile"
              class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
            />
          </a>
        </div>
      </header>
      <main class="pt-20 pb-28 min-h-screen px-4 space-y-3.5">
        <section class="balance-panel p-5">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-white/75">Current balance</p>
              <p class="mt-2 text-[2rem] leading-none font-extrabold tracking-tight">&#8369;<?= number_format($walletBalance, 2) ?></p>
            </div>
            <div class="icon-chip bg-white/15 text-white">
              <span class="material-symbols-outlined">account_balance_wallet</span>
            </div>
          </div>
          <p class="mt-4 text-xs text-white/80 leading-relaxed">
            Your wallet balance is reserved for NFC tap journeys only.
          </p>
        </section>

        <section class="phone-panel p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Quick top-up</p>
              <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface">
                Add funds
              </h2>
            </div>
            <span class="status-pill status-active">
              Presets
            </span>
          </div>
          <form method="POST" class="grid grid-cols-3 gap-3">
            <button
              type="submit"
              name="amount"
              value="50"
              class="min-h-[2.875rem] rounded-2xl bg-surface-container-low text-xs font-bold text-on-surface hover:bg-surface-container transition flex items-center justify-center gap-1"
            >
              <span class="material-symbols-outlined text-[16px] text-primary">add</span>
              &#8369;50
            </button>
            <button
              type="submit"
              name="amount"
              value="100"
              class="min-h-[2.875rem] rounded-2xl bg-surface-container-low text-xs font-bold text-on-surface hover:bg-surface-container transition flex items-center justify-center gap-1"
            >
              <span class="material-symbols-outlined text-[16px] text-primary">add</span>
              &#8369;100
            </button>
            <button
              type="submit"
              name="amount"
              value="200"
              class="min-h-[2.875rem] rounded-2xl bg-surface-container-low text-xs font-bold text-on-surface hover:bg-surface-container transition flex items-center justify-center gap-1"
            >
              <span class="material-symbols-outlined text-[16px] text-primary">add</span>
              &#8369;200
            </button>
          </form>
        </section>

        <section class="phone-panel p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Transaction history</p>
              <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface">
                Recent activity
              </h2>
            </div>
            <span class="status-pill status-idle">LATEST</span>
          </div>
          <div class="space-y-3">
            <?php if (count($transactions) > 0): ?>
              <?php foreach ($transactions as $txn): ?>
                <div class="rounded-2xl bg-surface-container-low p-3.5 border border-surface-container-high/60">
                  <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px] text-error">payments</span>
                        <p class="font-bold text-xs text-on-surface leading-tight truncate">
                          <?= htmlspecialchars($txn['route_name'] ?? 'Unknown Route', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                      </div>
                      <p class="text-[10px] text-on-surface-variant mt-1.5 font-medium truncate">
                        <?= htmlspecialchars($txn['boarding_stop'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars($txn['alighting_stop'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                      </p>
                    </div>
                    <span class="text-xs font-extrabold text-error whitespace-nowrap">-&#8369;<?= number_format((float)$txn['fare_amount'], 2) ?></span>
                  </div>
                  <div class="mt-3 pt-2.5 border-t border-surface-container-highest flex items-center justify-between text-[10px] text-on-surface-variant">
                    <span>ID: <?= htmlspecialchars($txn['transaction_id'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="font-medium text-primary">Completed</span>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="rounded-2xl bg-surface-container-low p-4 text-center">
                <p class="text-xs text-on-surface-variant font-medium">No transaction history yet</p>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </main>
      <nav
        class="fixed inset-x-0 bottom-0 z-50 mx-auto w-full max-w-[420px] flex items-center justify-around px-1 h-20 bg-white/95 backdrop-blur-md rounded-t-3xl border-t border-slate-200 shadow-[0_-4px_12px_rgba(0,0,0,0.05)]"
      >
        <?php
        foreach (
            [
                ['home', '01_home.php', 'home', 'Home'],
                ['routes', '02_routes.php', 'directions_bus', 'Routes'],
                ['wallet', '03_wallet.php', 'account_balance_wallet', 'Wallet'],
                ['trips', '04_trips.php', 'history', 'Trips'],
                ['profile', '05_profile.php', 'person', 'Profile'],
            ] as [$navKey, $navHref, $navIcon, $navLabel]
        ):
            $navActive = $activeNav === $navKey;
            ?>
        <a
          href="<?= htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') ?>"
          class="nav-link<?= $navActive ? ' active' : '' ?>"
        >
          <span class="material-symbols-outlined text-[22px] leading-none"><?= htmlspecialchars($navIcon, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="font-label font-medium text-[10px] uppercase tracking-wider mt-1 leading-none"><?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </body>
</html>