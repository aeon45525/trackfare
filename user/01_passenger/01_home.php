<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$fullName = trim($_SESSION['full_name'] ?? 'Passenger');
$walletBalance = 0.00;
$tapStatus = 'Waiting';
$tapStatusClasses = 'status-pill status-idle';
$activeTripStatus = 'No active trip';
$activeTripBadge = 'Idle';
$activeTripBadgeClasses = 'status-pill status-idle';
$boardedStop = '—';
$currentStop = '—';
$estimatedFare = '₱0.00';
$nfcCardUid = null;
$nfcCardStatus = 'No card linked';
$hasActiveTrip = false;
$hasPendingTap = false;
$availableTrips = [];
if (empty($_SESSION['passenger_tap_csrf'])) {
    $_SESSION['passenger_tap_csrf'] = bin2hex(random_bytes(32));
}

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, r.display_name, b.bus_number
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses b ON b.bus_id = t.bus_id
     WHERE t.status = ?
     ORDER BY t.start_time DESC, t.trip_id DESC'
)) {
    $tripStatus = 'active';
    $stmt->bind_param('s', $tripStatus);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($trip = $result->fetch_assoc()) {
        $availableTrips[] = $trip;
    }
    $stmt->close();
}

if ($stmt = $conn->prepare('SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($walletBalance);
    $stmt->fetch();
    $stmt->close();
}

if ($stmt = $conn->prepare('SELECT uid, is_active FROM nfc_cards WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($nfcCardUid, $nfcIsActive);
    if ($stmt->fetch()) {
        $nfcCardStatus = $nfcIsActive ? 'Active' : 'Inactive';
    } else {
        $nfcCardUid = null;
    }
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT t.status, t.start_time, bs.stop_name AS boarded_stop, r.display_name AS route_name
     FROM active_passengers ap
     JOIN trips t ON ap.trip_id = t.trip_id
     LEFT JOIN stops bs ON ap.boarding_stop_id = bs.stop_id
     LEFT JOIN routes r ON t.route_id = r.route_id
     WHERE ap.user_id = ?
     LIMIT 1'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($tripStatus, $tripStartTime, $boardedStopResult, $routeName);
    if ($stmt->fetch()) {
      if ($tripStatus === 'active' && empty($tripStartTime)) {
        $activeTripStatus = 'Waiting for driver';
        $activeTripBadge = 'Waiting';
        $hasPendingTap = true;
      } else {
        $activeTripStatus = $tripStatus === 'active' ? 'On active trip' : ucfirst($tripStatus);
        $activeTripBadge = $tripStatus === 'active' ? 'Active' : ucfirst($tripStatus);
        $activeTripBadgeClasses = $tripStatus === 'active' ? 'status-pill status-active' : 'status-pill status-idle';
        $boardedStop = $boardedStopResult ?: '—';
        $currentStop = $routeName ?: 'In transit';
        $hasActiveTrip = true;
        $tapStatus = 'On board';
        $tapStatusClasses = 'status-pill status-active';
      }
    }
    $stmt->close();

    if ($hasActiveTrip) {
        $fareInfo = get_passenger_fare_info($conn, $userId);
        if ($fareInfo['ok']) {
            $estimatedFare = '₱' . number_format($fareInfo['fare_now'], 2);
        } else {
            $estimatedFare = '₱0.00';
        }
    }
}

$pageTitle = 'Home';
$pageSubtitle = 'Welcome back, ' . $fullName;
$activeNav = 'home';
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="../../images/logo.png" />
    <link rel="manifest" href="../../manifest.json" />
    <meta name="theme-color" content="#0040a1" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
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
      .home-action {
        min-height: 2.625rem;
        border-radius: 0.875rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        font-size: 0.8125rem;
        font-weight: 700;
      }
      .trip-row {
        display: grid;
        grid-template-columns: 2rem minmax(0, 1fr);
        gap: 0.65rem;
        align-items: start;
        padding: 0.75rem 0;
        border-bottom: 1px solid #eef0f2;
      }
      .trip-row:last-child {
        border-bottom: 0;
      }
      .quick-link {
        min-height: 5.75rem;
        border-radius: 1rem;
        border: 1px solid #e1e3e4;
        background: #ffffff;
        padding: 0.875rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.5rem;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.035);
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
                <?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?>
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
      <main class="pt-20 pb-28 min-h-screen px-4 space-y-3">
        <section class="space-y-3">
          <div class="balance-panel p-5">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-white/75">Wallet balance</p>
                <p id="wallet-balance" class="mt-2 text-[2rem] leading-none font-extrabold tracking-tight">&#8369;<?= number_format($walletBalance, 2) ?></p>
              </div>
              <div class="icon-chip bg-white/15 text-white">
                <span class="material-symbols-outlined"
                  >account_balance_wallet</span
                >
              </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2.5">
              <a href="03_wallet.php" class="home-action bg-white text-primary">
                <span class="material-symbols-outlined text-[18px]">add</span>
                Top up
              </a>
              <a href="02_routes.php" class="home-action bg-white/15 text-white">
                <span class="material-symbols-outlined text-[18px]">directions_bus</span>
                Routes
              </a>
            </div>
          </div>

          <div
            class="phone-panel p-4"
          >
            <div class="flex items-start justify-between gap-4">
              <div>
                <p
                  class="text-[11px] uppercase tracking-[0.25em] text-on-surface-variant"
                >
                  NFC Tap
                </p>
                <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface">
                  Ready for boarding
                </h2>
              </div>
              <span
                id="tap-status-pill"
                class="<?= htmlspecialchars($tapStatusClasses, ENT_QUOTES, 'UTF-8') ?>"
              >
                <?= htmlspecialchars($tapStatus, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </div>
            <div class="mt-3 rounded-2xl bg-surface-container-low p-3 space-y-2">
              <p class="text-xs leading-relaxed text-on-surface-variant">
                Use this button, or tap your linked NFC card at the bus reader.
              </p>
              <?php if ($nfcCardUid): ?>
                <p class="text-xs text-on-surface-variant">
                  NFC UID: <?= htmlspecialchars($nfcCardUid, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($nfcCardStatus, ENT_QUOTES, 'UTF-8') ?>
                </p>
              <?php endif; ?>
              <div id="mobile-trip-selection" class="space-y-1" <?= $hasActiveTrip || $hasPendingTap ? 'hidden' : '' ?>>
                <label for="mobile-trip-select" class="block text-xs font-semibold text-on-surface">Active bus</label>
                <select id="mobile-trip-select" class="w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900">
                  <option value="">Choose your bus</option>
                  <?php foreach ($availableTrips as $trip): ?>
                    <option value="<?= (int) $trip['trip_id'] ?>" <?= count($availableTrips) === 1 ? 'selected' : '' ?>>
                      <?= htmlspecialchars(trim(($trip['display_name'] ?? 'Route') . ' · ' . ($trip['bus_number'] ?? 'Bus')), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button id="mobile-tap-button" type="button" class="home-action w-full bg-primary text-white disabled:cursor-not-allowed disabled:opacity-50" <?= !$hasActiveTrip && !$hasPendingTap && count($availableTrips) !== 1 ? 'disabled' : '' ?> <?= $hasPendingTap ? 'disabled' : '' ?>>
                <?= $hasActiveTrip ? 'Tap out' : ($hasPendingTap ? 'Waiting for driver' : 'Tap in') ?>
              </button>
              <p id="mobile-tap-feedback" class="text-xs text-on-surface-variant" role="status" aria-live="polite"></p>
              <button id="pwa-install-button" type="button" class="home-action w-full border border-slate-200 bg-white text-slate-700" hidden>
                <span class="material-symbols-outlined text-[18px]">download</span>
                Install TrackFare
              </button>
            </div>
          </div>

          <div
            class="phone-panel p-4"
          >
            <div class="flex items-center justify-between mb-4">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Active trip</p>
                <h2 id="active-trip-status" class="mt-1 text-base font-extrabold leading-tight text-on-surface truncate">
                  <?= htmlspecialchars($activeTripStatus, ENT_QUOTES, 'UTF-8') ?>
                </h2>
              </div>
              <span
                id="active-trip-badge"
                class="<?= htmlspecialchars($activeTripBadgeClasses, ENT_QUOTES, 'UTF-8') ?>"
              >
                <?= htmlspecialchars($activeTripBadge, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </div>
            <div class="mb-3">
              <div class="trip-row">
                <span class="icon-chip bg-surface-container-low text-primary !h-8 !w-8">
                  <span class="material-symbols-outlined text-[18px]">trip_origin</span>
                </span>
                <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-on-surface-variant">
                  Boarded stop
                </p>
                <p id="boarded-stop" class="mt-1 text-sm font-semibold text-on-surface truncate"><?= htmlspecialchars($boardedStop, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
              </div>
              <div class="trip-row">
                <span class="icon-chip bg-surface-container-low text-primary !h-8 !w-8">
                  <span class="material-symbols-outlined text-[18px]">route</span>
                </span>
                <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-on-surface-variant">
                  Current route
                </p>
                <p id="current-stop" class="mt-1 text-sm font-semibold text-on-surface truncate"><?= htmlspecialchars($currentStop, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
              </div>
              <div class="trip-row">
                <span class="icon-chip bg-surface-container-low text-primary !h-8 !w-8">
                  <span class="material-symbols-outlined text-[18px]">payments</span>
                </span>
                <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-on-surface-variant">
                  Estimated fare
                </p>
                <p id="fare-display" class="mt-1 text-sm font-semibold text-on-surface truncate"><?= htmlspecialchars($estimatedFare, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
              </div>
            </div>
            <p class="text-xs leading-relaxed text-on-surface-variant">
              Tap activity updates your trip and fare automatically.
            </p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <a
              href="03_wallet.php"
              class="quick-link"
            >
              <span class="material-symbols-outlined text-primary text-[26px]"
                >account_balance_wallet</span
              >
              <div>
                <p class="text-sm font-bold">Wallet</p>
                <p class="text-xs text-on-surface-variant">
                  Balance and top-up
                </p>
              </div>
            </a>
            <a
              href="04_trips.php"
              class="quick-link"
            >
              <span class="material-symbols-outlined text-primary text-[26px]"
                >history</span
              >
              <div>
                <p class="text-sm font-bold">Trips</p>
                <p class="text-xs text-on-surface-variant">Ride history</p>
              </div>
            </a>
            <a
              href="02_routes.php"
              class="quick-link"
            >
              <span class="material-symbols-outlined text-primary text-[26px]"
                >directions_bus</span
              >
              <div>
                <p class="text-sm font-bold">Routes</p>
                <p class="text-xs text-on-surface-variant">Live bus map</p>
              </div>
            </a>
            <a
              href="05_profile.php"
              class="quick-link"
            >
              <span class="material-symbols-outlined text-primary text-[26px]"
                >person</span
              >
              <div>
                <p class="text-sm font-bold">Profile</p>
                <p class="text-xs text-on-surface-variant">Card settings</p>
              </div>
            </a>
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
    <script>
      (function () {
        var endpoint = '../../config/gps.php?action=passenger_status';
        var tapStatusEl = document.getElementById('tap-status-pill');
        var tripStatusEl = document.getElementById('active-trip-status');
        var tripBadgeEl = document.getElementById('active-trip-badge');
        var boardedStopEl = document.getElementById('boarded-stop');
        var currentStopEl = document.getElementById('current-stop');
        var fareEl = document.getElementById('fare-display');
        var walletBalanceEl = document.getElementById('wallet-balance');
        var tapButton = document.getElementById('mobile-tap-button');
        var tripSelect = document.getElementById('mobile-trip-select');
        var tripSelection = document.getElementById('mobile-trip-selection');
        var tapFeedback = document.getElementById('mobile-tap-feedback');
        var installButton = document.getElementById('pwa-install-button');
        var csrfToken = <?= json_encode($_SESSION['passenger_tap_csrf']) ?>;
        var installPrompt = null;
        var tapRequestInFlight = false;

        function setStatusPill(isActive) {
          return isActive ? 'status-pill status-active' : 'status-pill status-idle';
        }

        function refreshPassengerStatus() {
          fetch(endpoint + '&_' + Date.now(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
          .then(function (r) {
            if (!r.ok) throw new Error('Network error');
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) return;
            if (tapStatusEl) {
              tapStatusEl.textContent = data.has_active_trip ? 'On board' : 'Waiting';
              tapStatusEl.className = setStatusPill(data.has_active_trip);
            }
            if (tripStatusEl) {
              tripStatusEl.textContent = data.active_trip_status || 'No active trip';
            }
            if (tripBadgeEl) {
              tripBadgeEl.textContent = data.active_trip_badge || 'Idle';
              tripBadgeEl.className = setStatusPill(data.has_active_trip);
            }
            if (boardedStopEl) {
              boardedStopEl.textContent = data.boarding_stop || '—';
            }
            if (currentStopEl) {
              currentStopEl.textContent = data.current_stop || '—';
            }
            if (fareEl) {
              fareEl.textContent = data.estimated_fare || '₱0.00';
            }
            if (walletBalanceEl && typeof data.wallet_balance === 'number') {
              walletBalanceEl.textContent = '₱' + data.wallet_balance.toFixed(2);
            }
            if (tapButton && !tapRequestInFlight) {
              if (data.has_active_trip) {
                tapButton.textContent = 'Tap out';
                tapButton.disabled = false;
                if (tripSelection) tripSelection.hidden = true;
              } else if (data.has_tap_record) {
                tapButton.textContent = 'Waiting for driver';
                tapButton.disabled = true;
                if (tripSelection) tripSelection.hidden = true;
              } else {
                tapButton.textContent = 'Tap in';
                tapButton.disabled = !tripSelect || !tripSelect.value;
                if (tripSelection) tripSelection.hidden = false;
              }
            }
          })
          .catch(function () {
            // ignore polling errors
          });
        }

        if (tripSelect) {
          tripSelect.addEventListener('change', function () {
            if (tapButton && tapButton.textContent === 'Tap in') {
              tapButton.disabled = !tripSelect.value;
            }
          });
        }

        if (tapButton) {
          tapButton.addEventListener('click', function () {
            if (tapRequestInFlight) return;
            tapRequestInFlight = true;
            var payload = new URLSearchParams({ csrf_token: csrfToken });
            if (tripSelect && tripSelection && !tripSelection.hidden) payload.set('trip_id', tripSelect.value);
            tapButton.disabled = true;
            tapButton.textContent = 'Processing...';
            if (tapFeedback) tapFeedback.textContent = '';

            fetch('../../api/passenger_tap.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
              },
              body: payload.toString(),
            })
            .then(function (response) {
              return response.json().then(function (data) {
                return { ok: response.ok, data: data };
              });
            })
            .then(function (result) {
              if (tapFeedback) tapFeedback.textContent = result.data.message || 'Tap request complete.';
              tapRequestInFlight = false;
              refreshPassengerStatus();
            })
            .catch(function () {
              if (tapFeedback) tapFeedback.textContent = 'Could not reach TrackFare. Check your connection and try again.';
              tapRequestInFlight = false;
              refreshPassengerStatus();
            });
          });
        }

        window.addEventListener('beforeinstallprompt', function (event) {
          event.preventDefault();
          installPrompt = event;
          if (installButton) installButton.hidden = false;
        });

        if (installButton) {
          installButton.addEventListener('click', function () {
            if (!installPrompt) return;
            installPrompt.prompt();
            installPrompt.userChoice.finally(function () {
              installPrompt = null;
              installButton.hidden = true;
            });
          });
        }

        if ('serviceWorker' in navigator && window.isSecureContext) {
          navigator.serviceWorker.register('../../service-worker.js', { scope: '../../' }).catch(function () {});
        }

        setInterval(refreshPassengerStatus, 2000);
        refreshPassengerStatus();
      })();
    </script>
  </body>
</html>
