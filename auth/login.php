<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
    } else {
        $stmt = $conn->prepare('SELECT user_id, full_name, email, password, role, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && $user['is_active']) {
            $validPassword = password_verify($password, $user['password']) || $user['password'] === $password;

            if ($validPassword) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                switch ($user['role']) {
                    case 'passenger':
                        header('Location: ../user/01_passenger/01_home.php');
                        break;
                    case 'driver':
                        header('Location: ../user/02_driver/01_dashboard.php');
                        break;
                    case 'admin':
                        header('Location: ../user/03_admin/01_dashboard.php');
                        break;
                    default:
                        $error = 'Unknown user role.';
                }
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - Login</title>
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
    <div
      class="relative flex min-h-screen w-full flex-col items-center justify-start px-6 py-8"
    >
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
      <div class="w-full max-w-md text-left mb-8">
        <h2 class="text-2xl font-bold leading-tight">Welcome Back</h2>
      </div>
      <?php if ($error): ?>
        <div class="w-full max-w-md rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 shadow-sm mb-6">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
      <form class="flex w-full max-w-md flex-col gap-6" method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold leading-none" for="email">Email Address</label>
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
        <div class="flex flex-col gap-2">
          <div class="flex items-center justify-between">
            <label class="text-sm font-semibold leading-none" for="password">Password</label>
            <a class="text-xs font-medium text-primary hover:underline" href="#">Forgot password?</a>
          </div>
          <div class="relative flex items-center">
            <input
              class="flex h-14 w-full rounded-xl border border-gray-300 bg-white px-4 py-2 text-base ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 pr-12"
              id="password"
              name="password"
              placeholder="••••••••"
              type="password"
              required
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
        <button
          class="mt-4 flex h-14 w-full items-center justify-center rounded-xl bg-brand-blue font-bold text-white shadow-lg hover:opacity-90 active:scale-[0.98] transition-all"
          type="submit"
        >
          Login
        </button>
      </form>
      <footer class="mt-auto pt-8 text-center">
        <p class="text-sm text-gray-600">
          Don't have an account?
          <a class="font-bold text-primary hover:underline" href="register.php">Sign Up</a>
        </p>
      </footer>
    </div>
  </body>
</html>