<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$fullName = trim($_SESSION['full_name'] ?? 'Passenger');
$email = trim($_SESSION['email'] ?? '');
$walletBalance = 0.00;
$tripCount = 0;
$fareSpent = 0.00;
$lastTripLabel = '—';
$nfcUid = 'N/A';
$nfcMasked = '•••• ----';
$nfcStatusText = 'Inactive';
$nfcStatusClass = 'text-error';
$recentTrips = [];
$editProfileMessage = '';
$changePasswordMessage = '';
$showEditModal = false;
$showChangePasswordModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        header('Location: ../../auth/login.php');
        exit;
    }

    if ($action === 'edit_profile') {
        $showEditModal = true;
        $newFullName = trim($_POST['full_name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');

        if ($newFullName === '' || $newEmail === '') {
            $editProfileMessage = 'Full name and email are required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $editProfileMessage = 'Please enter a valid email address.';
        } else {
            $duplicateStmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
            $duplicateStmt->bind_param('si', $newEmail, $userId);
            $duplicateStmt->execute();
            $duplicateStmt->store_result();
            if ($duplicateStmt->num_rows > 0) {
                $editProfileMessage = 'This email is already in use.';
            } else {
                $duplicateStmt->close();
                if ($updateStmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ?')) {
                    $updateStmt->bind_param('ssi', $newFullName, $newEmail, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    $fullName = $newFullName;
                    $email = $newEmail;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $editProfileMessage = 'Profile updated successfully.';
                } else {
                    $editProfileMessage = 'Unable to update profile. Please try again.';
                }
            }
        }
    }

    if ($action === 'change_password') {
        $showChangePasswordModal = true;
        $currentPassword = trim($_POST['current_password'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $changePasswordMessage = 'Please fill in all password fields.';
        } elseif ($newPassword !== $confirmPassword) {
            $changePasswordMessage = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 6) {
            $changePasswordMessage = 'New password must be at least 6 characters.';
        } else {
            $stmt = $conn->prepare('SELECT password FROM users WHERE user_id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->bind_result($storedPassword);
            $stmt->fetch();
            $stmt->close();

            $validCurrent = password_verify($currentPassword, $storedPassword) || $currentPassword === $storedPassword;
            if (!$validCurrent) {
                $changePasswordMessage = 'Current password is incorrect.';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($updateStmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?')) {
                    $updateStmt->bind_param('si', $passwordHash, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    $changePasswordMessage = 'Password changed successfully.';
                } else {
                    $changePasswordMessage = 'Unable to update password. Please try again.';
                }
            }
        }
    }
}

if ($stmt = $conn->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($dbFullName, $dbEmail);
    if ($stmt->fetch()) {
        $fullName = trim($dbFullName ?: $fullName);
        $email = trim($dbEmail ?: $email);
    }
    $stmt->close();
}

if ($stmt = $conn->prepare('SELECT wallet_balance FROM passenger_profiles WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($walletBalance);
    $stmt->fetch();
    $walletBalance = (float) $walletBalance;
    $stmt->close();
}

if ($stmt = $conn->prepare('SELECT uid, is_active FROM nfc_cards WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($uid, $isActive);
    if ($stmt->fetch()) {
        $nfcUid = trim($uid) ?: 'N/A';
        $cleanUid = preg_replace('/[^A-Za-z0-9]/', '', $nfcUid);
        if (strlen($cleanUid) >= 4) {
            $nfcMasked = '•••• ' . strtoupper(substr($cleanUid, -4));
        }
        $nfcStatusText = $isActive ? 'Active / Ready' : 'Inactive';
        $nfcStatusClass = $isActive ? 'text-emerald-700' : 'text-error';
    }
    $stmt->close();
}

$frequentRouteName = '—';
$frequentStopsLabel = '—';

if ($stmt = $conn->prepare('SELECT COUNT(*) AS trip_count, COALESCE(SUM(fare_amount), 0) AS fare_spent FROM trip_transactions WHERE user_id = ?')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($tripCount, $fareSpent);
    $stmt->fetch();
    $tripCount = (int) $tripCount;
    $fareSpent = (float) $fareSpent;
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT r.display_name AS route_name, bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop, COUNT(*) AS route_count
     FROM trip_transactions tt
     LEFT JOIN trips t ON tt.trip_id = t.trip_id
     LEFT JOIN routes r ON t.route_id = r.route_id
     LEFT JOIN stops bs ON tt.boarding_stop_id = bs.stop_id
     LEFT JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id
     WHERE tt.user_id = ?
     GROUP BY t.route_id, tt.boarding_stop_id, tt.alighting_stop_id
     ORDER BY route_count DESC
     LIMIT 1'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($freqRouteName, $freqBoarding, $freqAlighting, $routeCount);
    if ($stmt->fetch()) {
        $frequentRouteName = $freqRouteName ?: 'Unknown Route';
        if (!empty($freqBoarding) && !empty($freqAlighting)) {
            $frequentStopsLabel = $freqBoarding . ' → ' . $freqAlighting;
        } elseif (!empty($freqBoarding)) {
            $frequentStopsLabel = $freqBoarding;
        }
    }
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT tt.fare_amount, bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop, r.display_name AS route_name
     FROM trip_transactions tt
     LEFT JOIN trips t ON tt.trip_id = t.trip_id
     LEFT JOIN routes r ON t.route_id = r.route_id
     LEFT JOIN stops bs ON tt.boarding_stop_id = bs.stop_id
     LEFT JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id
     WHERE tt.user_id = ?
     ORDER BY tt.transaction_id DESC
     LIMIT 2'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recentTrips[] = $row;
    }
    $stmt->close();
}

$lastTripName = '—';
$lastTripStops = '—';
if (!empty($recentTrips)) {
    $firstTrip = $recentTrips[0];
    $lastTripName = $firstTrip['route_name'] ?: 'Unknown Route';
    if (!empty($firstTrip['boarding_stop']) && !empty($firstTrip['alighting_stop'])) {
        $lastTripStops = $firstTrip['boarding_stop'] . ' → ' . $firstTrip['alighting_stop'];
    } elseif (!empty($firstTrip['boarding_stop'])) {
        $lastTripStops = $firstTrip['boarding_stop'];
    }
}

function escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Profile';
$activeNav = 'profile';
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="../../images/logo.png" />
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
        margin: 0;
        min-height: max(884px, 100dvh);
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
                <?= escape($pageTitle) ?>
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
          <div class="flex items-center gap-4">
            <div class="h-16 w-16 rounded-2xl overflow-hidden border-2 border-white/30 shrink-0">
              <img
                src="../../images/pfp.png"
                alt="Passenger avatar"
                class="h-full w-full object-cover"
              />
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-white/75">TrackFare Passenger</p>
              <h2 class="mt-1 text-xl font-extrabold tracking-tight text-white truncate"><?= escape($fullName) ?></h2>
              <p class="text-xs text-white/80 truncate mt-0.5"><?= escape($email) ?></p>
            </div>
          </div>
          <div class="mt-4 grid grid-cols-2 gap-2.5">
            <div class="rounded-2xl bg-white/10 p-3 flex flex-col justify-between">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-white/70">Trips Taken</p>
              <p class="mt-1.5 text-lg font-extrabold tracking-tight"><?= number_format($tripCount) ?></p>
            </div>
            <div class="rounded-2xl bg-white/10 p-3 flex flex-col justify-between">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-white/70">Wallet Balance</p>
              <p class="mt-1.5 text-lg font-extrabold tracking-tight">&#8369;<?= number_format($walletBalance, 2) ?></p>
            </div>
          </div>
        </section>

        <section class="phone-panel p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Travel Statistics</p>
              <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface">
                Journey Summary
              </h2>
            </div>
            <span class="status-pill status-active">TODAY</span>
          </div>
          <div class="mt-4 grid grid-cols-2 gap-2.5">
            <div class="rounded-2xl bg-surface-container-low p-3">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Total Trips</p>
              <p class="mt-1 text-base font-extrabold text-on-surface"><?= number_format($tripCount) ?></p>
            </div>
            <div class="rounded-2xl bg-surface-container-low p-3">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Total Spent</p>
              <p class="mt-1 text-base font-extrabold text-on-surface">&#8369;<?= number_format($fareSpent, 2) ?></p>
            </div>
            <div class="rounded-2xl bg-surface-container-low p-3 col-span-2">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Frequent Route</p>
              <p class="mt-1 text-xs font-bold text-on-surface truncate"><?= escape($frequentRouteName) ?></p>
              <p class="text-[10px] text-on-surface-variant mt-0.5 truncate"><?= escape($frequentStopsLabel) ?></p>
            </div>
            <div class="rounded-2xl bg-surface-container-low p-3 col-span-2">
              <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Last Trip</p>
              <p class="mt-1 text-xs font-bold text-on-surface truncate"><?= escape($lastTripName) ?></p>
              <p class="text-[10px] text-on-surface-variant mt-0.5 truncate"><?= escape($lastTripStops) ?></p>
            </div>
          </div>
        </section>

        <section class="phone-panel p-5">
          <div class="flex items-center justify-between gap-4">
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">NFC ID</p>
              <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface truncate">
                <?= escape($nfcUid) ?>
              </h2>
            </div>
            <?php
            $nfcIsActive = $nfcStatusText === 'Active / Ready';
            $badgeClass = $nfcIsActive ? 'status-active' : 'status-idle';
            ?>
            <span class="status-pill <?= $badgeClass ?>"><?= $nfcIsActive ? 'Active' : 'Inactive' ?></span>
          </div>
        </section>

        <section class="phone-panel p-5">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">Recent Trips Preview</p>
              <h2 class="mt-1 text-base font-extrabold leading-tight text-on-surface">
                Latest rides
              </h2>
            </div>
            <a
              href="04_trips.php"
              class="status-pill status-active hover:bg-primary hover:text-white transition"
            >
              View All
            </a>
          </div>
          <div class="mt-4 space-y-2.5">
            <?php if (!empty($recentTrips)): ?>
              <?php foreach ($recentTrips as $trip): ?>
                <div class="rounded-2xl bg-surface-container-low p-3.5 border border-surface-container-high/60">
                  <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px] text-primary">directions_bus</span>
                        <p class="font-bold text-xs text-on-surface leading-tight truncate">
                          <?= escape($trip['route_name'] ?: 'Unknown Route') ?>
                        </p>
                      </div>
                      <?php if (!empty($trip['boarding_stop']) && !empty($trip['alighting_stop'])): ?>
                        <p class="text-[10px] text-on-surface-variant mt-1.5 font-medium truncate">
                          <?= escape($trip['boarding_stop'] . ' → ' . $trip['alighting_stop']) ?>
                        </p>
                      <?php endif; ?>
                    </div>
                    <span class="text-xs font-extrabold text-on-surface">&#8369;<?= number_format((float)$trip['fare_amount'], 2) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="rounded-2xl bg-surface-container-low p-4 text-center">
                <p class="text-xs text-on-surface-variant font-medium">No recent trips yet</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="phone-panel p-5 space-y-3">
          <button
            type="button"
            onclick="openModal('edit-profile-modal')"
            class="w-full min-h-[3rem] rounded-2xl bg-primary text-xs font-bold text-white transition hover:bg-primary/90 flex items-center justify-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[18px]">edit</span>
            Edit Profile
          </button>
          <button
            type="button"
            onclick="openModal('change-password-modal')"
            class="w-full min-h-[3rem] rounded-2xl bg-white border border-outline-variant text-xs font-bold text-on-surface transition hover:bg-surface-container-low flex items-center justify-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[18px]">lock</span>
            Change Password
          </button>
          <form method="post" class="w-full">
            <input type="hidden" name="form_action" value="logout" />
            <button
              type="submit"
              class="w-full min-h-[3rem] rounded-2xl border border-error text-error text-xs font-bold transition hover:bg-error/5 flex items-center justify-center gap-1.5"
            >
              <span class="material-symbols-outlined text-[18px]">logout</span>
              Logout Account
            </button>
          </form>
        </section>
      </main>

      <!-- Edit Profile Modal -->
      <div id="edit-profile-modal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm <?= $showEditModal ? '' : 'hidden' ?> flex items-center justify-center p-4">
        <div class="w-full max-w-[340px] rounded-[1.75rem] bg-white border border-outline-variant p-5 shadow-2xl animate-[pop-in_0.2s_ease-out]">
          <div class="flex items-start justify-between gap-4 mb-4">
            <div>
              <h2 class="text-base font-extrabold text-on-surface">Edit Profile</h2>
              <p class="text-[11px] text-on-surface-variant mt-0.5">Update your personal details</p>
            </div>
            <button type="button" onclick="closeModal('edit-profile-modal')" class="h-7 w-7 rounded-full bg-surface-container-low flex items-center justify-center text-on-surface-variant hover:text-on-surface transition">
              <span class="material-symbols-outlined text-[16px]">close</span>
            </button>
          </div>
          <?php if ($editProfileMessage !== ''): ?>
            <div class="mb-4 rounded-xl bg-surface-container px-3.5 py-2.5 text-xs text-on-surface font-medium">
              <?= escape($editProfileMessage) ?>
            </div>
          <?php endif; ?>
          <form method="post" class="space-y-4">
            <input type="hidden" name="form_action" value="edit_profile" />
            <div class="space-y-1.5">
              <label class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Full Name</label>
              <input
                type="text"
                name="full_name"
                value="<?= escape($fullName) ?>"
                class="w-full rounded-2xl border border-outline-variant bg-white px-3.5 py-2.5 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Email Address</label>
              <input
                type="email"
                name="email"
                value="<?= escape($email) ?>"
                class="w-full rounded-2xl border border-outline-variant bg-white px-3.5 py-2.5 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </div>
            <button
              type="submit"
              class="w-full min-h-[2.75rem] rounded-2xl bg-primary text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm mt-2"
            >
              Save Changes
            </button>
          </form>
        </div>
      </div>

      <!-- Change Password Modal -->
      <div id="change-password-modal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm <?= $showChangePasswordModal ? '' : 'hidden' ?> flex items-center justify-center p-4">
        <div class="w-full max-w-[340px] rounded-[1.75rem] bg-white border border-outline-variant p-5 shadow-2xl animate-[pop-in_0.2s_ease-out]">
          <div class="flex items-start justify-between gap-4 mb-4">
            <div>
              <h2 class="text-base font-extrabold text-on-surface">Change Password</h2>
              <p class="text-[11px] text-on-surface-variant mt-0.5">Update your account security</p>
            </div>
            <button type="button" onclick="closeModal('change-password-modal')" class="h-7 w-7 rounded-full bg-surface-container-low flex items-center justify-center text-on-surface-variant hover:text-on-surface transition">
              <span class="material-symbols-outlined text-[16px]">close</span>
            </button>
          </div>
          <?php if ($changePasswordMessage !== ''): ?>
            <div class="mb-4 rounded-xl bg-surface-container px-3.5 py-2.5 text-xs text-on-surface font-medium">
              <?= escape($changePasswordMessage) ?>
            </div>
          <?php endif; ?>
          <form method="post" class="space-y-4">
            <input type="hidden" name="form_action" value="change_password" />
            <div class="space-y-1.5">
              <label class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Current Password</label>
              <input
                type="password"
                name="current_password"
                class="w-full rounded-2xl border border-outline-variant bg-white px-3.5 py-2.5 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">New Password</label>
              <input
                type="password"
                name="new_password"
                class="w-full rounded-2xl border border-outline-variant bg-white px-3.5 py-2.5 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">Confirm Password</label>
              <input
                type="password"
                name="confirm_password"
                class="w-full rounded-2xl border border-outline-variant bg-white px-3.5 py-2.5 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </div>
            <button
              type="submit"
              class="w-full min-h-[2.75rem] rounded-2xl bg-primary text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm mt-2"
            >
              Update Password
            </button>
          </form>
        </div>
      </div>

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
          href="<?= escape($navHref) ?>"
          class="nav-link<?= $navActive ? ' active' : '' ?>"
        >
          <span class="material-symbols-outlined text-[22px] leading-none"><?= escape($navIcon) ?></span>
          <span class="font-label font-medium text-[10px] uppercase tracking-wider mt-1 leading-none"><?= escape($navLabel) ?></span>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>
    <script>
      function openModal(id) {
        var el = document.getElementById(id);
        if (el) {
          el.classList.remove('hidden');
        }
      }
      function closeModal(id) {
        var el = document.getElementById(id);
        if (el) {
          el.classList.add('hidden');
        }
      }
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          closeModal('edit-profile-modal');
          closeModal('change-password-modal');
        }
      });
    </script>
  </body>
</html>
