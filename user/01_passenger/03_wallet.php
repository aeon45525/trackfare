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
            bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop
     FROM trip_transactions tt
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
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - Wallet</title>
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
      body {
        min-height: max(884px, 100dvh);
        margin: 0;
        display: flex;
        justify-content: center;
        background: #f8f9fa;
      }
      #app-shell {
        width: min(100%, 420px);
        min-height: 100dvh;
        position: relative;
      }
    </style>
  </head>
  <body class="bg-surface font-body text-on-surface">
    <div id="app-shell" class="w-full">
      <!-- Top App Bar -->
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-primary text-2xl"
              >account_balance_wallet</span
            >
            <div>
              <p
                class="text-xs uppercase tracking-[0.25em] text-on-surface-variant"
              >
                TrackFare
              </p>
              <h1 class="font-headline font-bold text-lg">Wallet</h1>
            </div>
          </div>
          <img
            src="../../images/pfp.png"
            alt="Profile photo"
            class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
          />
        </div>
      </header>
      <main class="pt-20 pb-28 min-h-screen px-4 space-y-4">
        <section class="rounded-[1.75rem] bg-primary text-white p-5 shadow-lg">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-sm opacity-80">Current balance</p>
              <p class="mt-3 text-4xl font-extrabold">₱<?= number_format($walletBalance, 2) ?></p>
            </div>
          </div>
          <p class="mt-4 text-sm text-white/85">
            Your wallet balance is reserved for NFC tap journeys only.
          </p>
        </section>

        <section
          class="rounded-[1.75rem] bg-white p-5 shadow-sm border border-outline-variant"
        >
          <div class="flex items-center justify-between mb-4">
            <div>
              <p class="text-sm text-on-surface-variant">Quick top-up</p>
              <h2 class="mt-2 text-xl font-semibold text-on-surface">
                Add funds
              </h2>
            </div>
            <span
              class="inline-flex rounded-full bg-surface-container-high px-3 py-1 text-xs font-semibold text-primary"
            >
              ₱50 / ₱100 / ₱200
            </span>
          </div>
          <form method="POST" class="grid grid-cols-3 gap-3">
            <button
              type="submit"
              name="amount"
              value="50"
              class="rounded-3xl bg-surface-container-low py-4 text-sm font-semibold text-on-surface shadow-sm hover:bg-surface-container transition"
            >
              Add ₱50
            </button>
            <button
              type="submit"
              name="amount"
              value="100"
              class="rounded-3xl bg-surface-container-low py-4 text-sm font-semibold text-on-surface shadow-sm hover:bg-surface-container transition"
            >
              Add ₱100
            </button>
            <button
              type="submit"
              name="amount"
              value="200"
              class="rounded-3xl bg-surface-container-low py-4 text-sm font-semibold text-on-surface shadow-sm hover:bg-surface-container transition"
            >
              Add ₱200
            </button>
          </form>
        </section>

        <section
          class="rounded-[1.75rem] bg-surface-container-lowest p-5 shadow-sm border border-outline-variant"
        >
          <div class="flex items-center justify-between mb-4">
            <div>
              <p class="text-sm text-on-surface-variant">Transaction history</p>
              <h2 class="mt-2 text-xl font-semibold text-on-surface">
                Recent activity
              </h2>
            </div>
            <span
              class="text-xs uppercase tracking-[0.2em] text-on-surface-variant"
              >Latest</span
            >
          </div>
          <div class="space-y-3">
            <?php if (count($transactions) > 0): ?>
              <?php foreach ($transactions as $txn): ?>
                <div
                  class="rounded-3xl bg-white p-4 shadow-sm border border-surface-container-high"
                >
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="font-semibold text-on-surface">Fare deduction</p>
                      <p class="text-xs text-on-surface-variant mt-1">
                        <?= htmlspecialchars($txn['boarding_stop'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars($txn['alighting_stop'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                      </p>
                    </div>
                    <span class="text-sm font-bold text-error">-₱<?= number_format((float)$txn['fare_amount'], 2) ?></span>
                  </div>
                  <p class="mt-3 text-[11px] text-on-surface-variant">
                    Transaction ID: <?= htmlspecialchars($txn['transaction_id'], ENT_QUOTES, 'UTF-8') ?>
                  </p>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div
                class="rounded-3xl bg-white p-4 shadow-sm border border-surface-container-high text-center"
              >
                <p class="text-sm text-on-surface-variant">No transaction history yet</p>
              </div>
            <?php endif; ?>
          </div>
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
          class="flex flex-col items-center justify-center text-white bg-primary rounded-3xl px-4 py-2 shadow-lg"
          href="03_wallet.php"
        >
          <span class="material-symbols-outlined">account_balance_wallet</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Wallet</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
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