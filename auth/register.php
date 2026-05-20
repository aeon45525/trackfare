<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm-password'] ?? '');
    $role = 'passenger';

    if ($fullName === '') {
        $error = 'Please enter your full name.';
    } elseif ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password === '') {
        $error = 'Please enter a password.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($confirmPassword === '') {
        $error = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = 'An account with this email already exists.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO users (full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
            $insert->bind_param('ssss', $fullName, $email, $hashedPassword, $role);

            if ($insert->execute()) {
                $userId = $conn->insert_id;

                if ($role === 'passenger') {
                    $profile = $conn->prepare('INSERT INTO passenger_profiles (user_id, wallet_balance) VALUES (?, 0.00)');
                    $profile->bind_param('i', $userId);
                    $profile->execute();
                }

                header('Location: login.php');
                exit;
            }

            $error = 'Unable to create your account at this time. Please try again later.';
        }
    }
}
?>

<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - Create Account</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&amp;display=swap"
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
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              primary: "#ec5b13",
              "background-light": "#f8f6f6",
              "background-dark": "#221610",
              "brand-blue": "#0040a1",
            },
            fontFamily: {
              display: ["Public Sans", "sans-serif"],
              sans: ["Public Sans", "sans-serif"],
            },
            borderRadius: {
              DEFAULT: "0.25rem",
              lg: "0.5rem",
              xl: "0.75rem",
              full: "9999px",
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
    </style>
    <style>
      body {
        min-height: max(884px, 100dvh);
      }
    </style>
  </head>
  <body class="bg-background-light font-sans text-background-dark antialiased">
    <!-- Focused View: Navigation Excluded as per Rule 2 -->
    <div
      class="relative flex min-h-screen w-full flex-col items-center justify-start px-6 py-8"
    >
      <!-- Brand Logo Section -->
      <div class="mb-10 flex flex-col items-center">
        <div class="mb-4 h-24 w-24 overflow-hidden rounded-xl">
          <img
            alt="TrackFare Logo"
            class="h-full w-full object-cover"
            src="../images/logo.png"
          />
        </div>
        <h1 class="text-3xl font-bold tracking-tight text-background-dark">
          TrackFare
        </h1>
      </div>
      <!-- Heading -->
      <div class="w-full max-w-md text-left mb-8">
        <h2 class="text-2xl font-bold leading-tight">Create Account</h2>
      </div>
      <!-- Registration Form -->
      <form method="post" class="flex w-full max-w-md flex-col gap-6">
        <?php if ($error !== '') : ?>
          <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" aria-live="assertive">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <!-- Full Name Input -->
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold leading-none" for="name"
            >Full Name</label
          >
          <div class="relative">
            <input
              class="flex h-14 w-full rounded-xl border border-gray-300 bg-white px-4 py-2 text-base ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              id="name"
              name="name"
              placeholder="John Doe"
              type="text"
              value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
              required
            />
          </div>
        </div>
        <!-- Email Input -->
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold leading-none" for="email"
            >Email Address</label
          >
          <div class="relative">
            <input
              class="flex h-14 w-full rounded-xl border border-gray-300 bg-white px-4 py-2 text-base ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              id="email"
              name="email"
              placeholder="name@example.com"
              type="email"
              value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
              required
            />
          </div>
        </div>
        <!-- Password Input -->
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold leading-none" for="password"
            >Password</label
          >
          <div class="relative flex items-center">
            <input
              class="flex h-14 w-full rounded-xl border border-gray-300 bg-white px-4 py-2 text-base ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 pr-12"
              id="password"
              name="password"
              placeholder="••••••••"
              type="password"
            />
            <button
              class="absolute right-4 text-gray-400 hover:text-gray-600"
              data-icon="visibility"
              type="button"
            >
              <span class="material-symbols-outlined">visibility</span>
            </button>
          </div>
        </div>
        <!-- Confirm Password Input -->
        <div class="flex flex-col gap-2">
          <label
            class="text-sm font-semibold leading-none"
            for="confirm-password"
            >Confirm Password</label
          >
          <div class="relative flex items-center">
            <input
              class="flex h-14 w-full rounded-xl border border-gray-300 bg-white px-4 py-2 text-base ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 pr-12"
              id="confirm-password"
              name="confirm-password"
              placeholder="••••••••"
              type="password"
            />
            <button
              class="absolute right-4 text-gray-400 hover:text-gray-600"
              data-icon="visibility"
              type="button"
            >
              <span class="material-symbols-outlined">visibility</span>
            </button>
          </div>
        </div>
        <!-- Sign Up Button -->
        <button
          class="mt-4 flex h-14 w-full items-center justify-center rounded-xl bg-brand-blue font-bold text-white shadow-lg hover:opacity-90 active:scale-[0.98] transition-all"
          type="submit"
        >
          Sign Up
        </button>
      </form>
      <!-- Footer Sign Up -->
      <footer class="mt-auto pt-8 text-center">
        <p class="text-sm text-gray-600">
          Already have an account?
          <a class="font-bold text-primary hover:underline" href="login.php"
            >Back to Login</a
          >
        </p>
      </footer>
    </div>
  </body>
</html>