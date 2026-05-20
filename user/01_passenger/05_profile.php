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

$frequentRouteLabel = '—';

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
    'SELECT bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop, COUNT(*) AS route_count
     FROM trip_transactions tt
     LEFT JOIN stops bs ON tt.boarding_stop_id = bs.stop_id
     LEFT JOIN stops as_stop ON tt.alighting_stop_id = as_stop.stop_id
     WHERE tt.user_id = ?
     GROUP BY tt.boarding_stop_id, tt.alighting_stop_id
     ORDER BY route_count DESC
     LIMIT 1'
)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($freqBoarding, $freqAlighting, $routeCount);
    if ($stmt->fetch()) {
        if (!empty($freqBoarding) && !empty($freqAlighting)) {
            $frequentRouteLabel = $freqBoarding . ' → ' . $freqAlighting;
        } elseif (!empty($freqBoarding)) {
            $frequentRouteLabel = $freqBoarding;
        }
    }
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT tt.fare_amount, bs.stop_name AS boarding_stop, as_stop.stop_name AS alighting_stop
     FROM trip_transactions tt
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

if (!empty($recentTrips)) {
    $firstTrip = $recentTrips[0];
    if (!empty($firstTrip['boarding_stop']) && !empty($firstTrip['alighting_stop'])) {
        $lastTripLabel = $firstTrip['boarding_stop'] . ' → ' . $firstTrip['alighting_stop'];
    } elseif (!empty($firstTrip['boarding_stop'])) {
        $lastTripLabel = $firstTrip['boarding_stop'];
    }
}

function escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
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
      body {
        margin: 0;
        min-height: max(884px, 100dvh);
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
      <!-- TopAppBar -->
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-primary text-2xl"
              >person</span
            >
            <div>
              <p
                class="text-xs uppercase tracking-[0.25em] text-on-surface-variant"
              >
                TrackFare
              </p>
              <h1 class="font-headline font-bold text-lg text-on-surface">
                Profile
              </h1>
            </div>
          </div>
          <img
            src="../../images/pfp.png"
            alt="Profile photo"
            class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
          />
        </div>
      </header>
      <main class="pt-20 pb-28 px-4 min-h-screen space-y-4">
        <section class="rounded-[1.75rem] bg-primary text-white p-5 shadow-lg">
          <div class="flex items-center gap-4">
            <div
              class="h-20 w-20 rounded-3xl overflow-hidden border border-white/20"
            >
              <img
                src="../../images/pfp.png"
                alt="Passenger avatar"
                class="h-full w-full object-cover"
              />
            </div>
            <div class="flex-1">
              <p class="text-sm opacity-80">TrackFare Passenger</p>
              <h2 class="mt-2 text-2xl font-bold"><?= escape($fullName) ?></h2>
              <p class="mt-1 text-sm text-white/80"><?= escape($email) ?></p>
            </div>
          </div>
          <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-3xl bg-white/10 p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-white/70">
                Trips
              </p>
              <p class="mt-2 text-2xl font-extrabold"><?= number_format($tripCount) ?></p>
            </div>
            <div class="rounded-3xl bg-white/10 p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-white/70">
                Wallet Balance
              </p>
              <p class="mt-2 text-2xl font-extrabold">₱<?= number_format($walletBalance, 2) ?></p>
            </div>
          </div>
        </section>

        <section
          class="rounded-[1.75rem] bg-white p-5 shadow-sm border border-outline-variant"
        >
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm text-on-surface-variant">Travel Statistics</p>
              <h2 class="mt-2 text-xl font-semibold text-on-surface">
                Journey Summary
              </h2>
            </div>
            <span class="text-xs uppercase tracking-[0.3em] text-primary">
              updated today
            </span>
          </div>
          <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-3xl bg-surface-container-lowest p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-on-surface-variant">
                Total Trips
              </p>
              <p class="mt-2 text-xl font-bold text-on-surface"><?= number_format($tripCount) ?></p>
            </div>
            <div class="rounded-3xl bg-surface-container-lowest p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-on-surface-variant">
                Fare Spent
              </p>
              <p class="mt-2 text-xl font-bold text-on-surface">₱<?= number_format($fareSpent, 2) ?></p>
            </div>
            <div class="rounded-3xl bg-surface-container-lowest p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-on-surface-variant">
                Frequent Route
              </p>
              <p class="mt-2 text-base font-semibold text-on-surface">
                <?= escape($frequentRouteLabel) ?>
              </p>
            </div>
            <div class="rounded-3xl bg-surface-container-lowest p-4">
              <p class="text-xs uppercase tracking-[0.25em] text-on-surface-variant">
                Last Trip
              </p>
              <p class="mt-2 text-base font-semibold text-on-surface">
                <?= escape($lastTripLabel) ?>
              </p>
            </div>
          </div>
        </section>

        <section
          class="rounded-[1.75rem] bg-white p-5 shadow-sm border border-outline-variant"
        >
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm text-on-surface-variant">NFC Card</p>
              <h2 class="mt-2 text-xl font-semibold text-on-surface">
                <?= escape($nfcMasked) ?>
              </h2>
            </div>
            <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold <?= $nfcStatusClass ?>">
              <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
              <?= escape($nfcStatusText) ?>
            </div>
          </div>
          <div class="mt-4 rounded-3xl bg-surface-container-lowest p-4 border border-slate-200">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-xs uppercase tracking-[0.25em] text-on-surface-variant">
                  NFC UID
                </p>
                <p class="mt-2 text-base font-semibold text-on-surface">
                  <?= escape($nfcUid) ?>
                </p>
              </div>
              <span class="inline-flex rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                <?= $nfcStatusText === 'Active / Ready' ? 'Ready to tap' : 'Not linked' ?>
              </span>
            </div>
          </div>
          <p class="mt-4 text-sm text-on-surface-variant">
            Used for tap-in fare verification and fast boarding on TrackFare buses.
          </p>
        </section>

        <section
          class="rounded-[1.75rem] bg-white p-5 shadow-sm border border-outline-variant"
        >
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm text-on-surface-variant">Recent Trips Preview</p>
              <h2 class="mt-2 text-xl font-semibold text-on-surface">
                Latest rides
              </h2>
            </div>
            <button
              class="rounded-full border border-outline-variant px-3 py-2 text-xs font-semibold text-on-surface-variant transition hover:bg-surface-container-high"
            >
              View All Trips
            </button>
          </div>
          <div class="mt-5 space-y-3">
            <?php if (!empty($recentTrips)): ?>
              <?php foreach ($recentTrips as $trip): ?>
                <div class="rounded-3xl bg-surface-container-lowest p-4">
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="font-semibold text-on-surface">
                        <?= escape(trim(($trip['boarding_stop'] ?? '') . ' → ' . ($trip['alighting_stop'] ?? ''))) ?>
                      </p>
                      <p class="mt-1 text-sm text-on-surface-variant">
                        —
                      </p>
                    </div>
                    <p class="font-semibold text-on-surface">₱<?= number_format((float)$trip['fare_amount'], 2) ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="rounded-3xl bg-surface-container-lowest p-4">
                <div class="text-sm text-on-surface-variant">No recent trips yet</div>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section
          class="rounded-[1.75rem] bg-surface-container-lowest p-5 shadow-sm border border-outline-variant space-y-3"
        >
          <button
            type="button"
            onclick="openModal('edit-profile-modal')"
            class="w-full rounded-3xl bg-primary px-4 py-4 text-sm font-semibold text-white transition hover:bg-primary/90"
          >
            Edit Profile
          </button>
          <button
            type="button"
            onclick="openModal('change-password-modal')"
            class="w-full rounded-3xl bg-white border border-outline-variant px-4 py-4 text-sm font-semibold text-on-surface transition hover:bg-surface-container-high"
          >
            Change Password
          </button>
          <form method="post" class="w-full">
            <input type="hidden" name="form_action" value="logout" />
            <button
              type="submit"
              class="w-full rounded-3xl border border-error text-error px-4 py-4 font-semibold transition hover:bg-error/10"
            >
              Logout
            </button>
          </form>
        </section>
      </main>

      <div id="edit-profile-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm <?= $showEditModal ? '' : 'hidden' ?>">
        <div class="flex h-full items-center justify-center p-4">
          <div class="w-full max-w-[420px] rounded-[1.75rem] bg-white p-5 shadow-lg">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h2 class="text-lg font-semibold">Edit Profile</h2>
                <p class="text-sm text-slate-500">Update your name and email.</p>
              </div>
              <button type="button" onclick="closeModal('edit-profile-modal')" class="text-slate-500 hover:text-slate-900">✕</button>
            </div>
            <?php if ($editProfileMessage !== ''): ?>
              <div class="mb-4 rounded-2xl bg-slate-100 px-4 py-3 text-sm text-slate-700">
                <?= escape($editProfileMessage) ?>
              </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
              <input type="hidden" name="form_action" value="edit_profile" />
              <div>
                <label class="text-sm font-medium text-slate-700">Full Name</label>
                <input
                  type="text"
                  name="full_name"
                  value="<?= escape($fullName) ?>"
                  class="mt-2 w-full rounded-3xl border border-slate-200 bg-surface-container-lowest px-4 py-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                />
              </div>
              <div>
                <label class="text-sm font-medium text-slate-700">Email</label>
                <input
                  type="email"
                  name="email"
                  value="<?= escape($email) ?>"
                  class="mt-2 w-full rounded-3xl border border-slate-200 bg-surface-container-lowest px-4 py-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                />
              </div>
              <button
                type="submit"
                class="w-full rounded-3xl bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-primary/90"
              >
                Save Changes
              </button>
            </form>
          </div>
        </div>
      </div>

      <div id="change-password-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm <?= $showChangePasswordModal ? '' : 'hidden' ?>">
        <div class="flex h-full items-center justify-center p-4">
          <div class="w-full max-w-[420px] rounded-[1.75rem] bg-white p-5 shadow-lg">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h2 class="text-lg font-semibold">Change Password</h2>
                <p class="text-sm text-slate-500">Update your account password.</p>
              </div>
              <button type="button" onclick="closeModal('change-password-modal')" class="text-slate-500 hover:text-slate-900">✕</button>
            </div>
            <?php if ($changePasswordMessage !== ''): ?>
              <div class="mb-4 rounded-2xl bg-slate-100 px-4 py-3 text-sm text-slate-700">
                <?= escape($changePasswordMessage) ?>
              </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
              <input type="hidden" name="form_action" value="change_password" />
              <div>
                <label class="text-sm font-medium text-slate-700">Current Password</label>
                <input
                  type="password"
                  name="current_password"
                  class="mt-2 w-full rounded-3xl border border-slate-200 bg-surface-container-lowest px-4 py-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                />
              </div>
              <div>
                <label class="text-sm font-medium text-slate-700">New Password</label>
                <input
                  type="password"
                  name="new_password"
                  class="mt-2 w-full rounded-3xl border border-slate-200 bg-surface-container-lowest px-4 py-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                />
              </div>
              <div>
                <label class="text-sm font-medium text-slate-700">Confirm Password</label>
                <input
                  type="password"
                  name="confirm_password"
                  class="mt-2 w-full rounded-3xl border border-slate-200 bg-surface-container-lowest px-4 py-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                />
              </div>
              <button
                type="submit"
                class="w-full rounded-3xl bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-primary/90"
              >
                Change Password
              </button>
            </form>
          </div>
        </div>
      </div>

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
          class="flex flex-col items-center justify-center text-white bg-primary rounded-3xl px-4 py-2 shadow-lg"
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
