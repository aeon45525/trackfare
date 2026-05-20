<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Routes & Fares - TrackFare Admin</title>
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
            href="05_routes_fares.php"
          >
            <span class="material-symbols-outlined">alt_route</span>
            <span>Routes &amp; Fares</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="06_transactions.php"
          >
            <span class="material-symbols-outlined">payments</span>
            <span>Transactions</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="07_analytics.php"
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
          <button
            class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
          >
            <span class="material-symbols-outlined">logout</span>
            Logout
          </button>
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
                Routes &amp; Fares
              </h1>
              <p class="mt-2 text-slate-600">
                Manage transit routes, stops, and fare pricing.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <div class="relative w-full max-w-md">
                <span
                  class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl"
                  >search</span
                >
                <input
                  class="w-full bg-surface-container-low border-none rounded-xl py-3 pl-11 pr-4 text-sm focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Search routes or fares"
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
        </header>

        <div class="grid gap-6 xl:grid-cols-2 mb-6">
          <!-- Routes Section -->
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div class="flex flex-col gap-4 mb-6">
              <div>
                <h2 class="text-lg font-bold text-on-surface">Route List</h2>
                <p class="text-sm text-slate-600">
                  Active transit routes with assigned stops.
                </p>
              </div>
              <button
                class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition w-fit"
              >
                <span class="material-symbols-outlined">add</span>
                Add Route
              </button>
            </div>

            <div class="space-y-4">
              <!-- Route 1 -->
              <div
                class="p-4 border border-slate-200 rounded-xl hover:border-blue-300 transition"
              >
                <div
                  class="flex flex-col gap-3 mb-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <h3 class="text-xl font-semibold text-on-surface">
                      Balagtas → Monumento
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                      Northbound operational route
                    </p>
                  </div>
                  <div
                    class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                  >
                    Route 1
                  </div>
                </div>
                <p
                  class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-3"
                >
                  Stops
                </p>
                <div class="overflow-x-auto">
                  <div
                    class="flex items-center gap-2 whitespace-nowrap text-xs font-semibold text-slate-700"
                  >
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Balagtas</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Bocaue</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Marilao</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Meycauayan</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Monumento</span
                    >
                  </div>
                </div>
              </div>

              <!-- Route 2 -->
              <div
                class="p-4 border border-slate-200 rounded-xl hover:border-blue-300 transition"
              >
                <div
                  class="flex flex-col gap-3 mb-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <h3 class="text-xl font-semibold text-on-surface">
                      Monumento → Balagtas
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                      Southbound operational route
                    </p>
                  </div>
                  <div
                    class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                  >
                    Route 2
                  </div>
                </div>
                <p
                  class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-3"
                >
                  Stops
                </p>
                <div class="overflow-x-auto">
                  <div
                    class="flex items-center gap-2 whitespace-nowrap text-xs font-semibold text-slate-700"
                  >
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Monumento</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Meycauayan</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Marilao</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Bocaue</span
                    >
                    <span class="text-slate-400">→</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1"
                      >Balagtas</span
                    >
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Fare Matrix Section -->
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div class="flex flex-col gap-4 mb-6">
              <div>
                <h2 class="text-lg font-bold text-on-surface">
                  Kilometer Fare Matrix
                </h2>
              </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
              <div
                class="overflow-x-auto rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4"
              >
                <table
                  class="w-full divide-y divide-slate-200 text-left text-sm"
                >
                  <thead>
                    <tr>
                      <th
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                      >
                        Distance (km)
                      </th>
                      <th
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                      >
                        Regular Fare
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white">
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        1–5 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱13.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        6 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱15.25
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        7 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱17.50
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        8 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱19.75
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        9 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱22.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        10 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱24.25
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        11 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱26.50
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        12 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱28.75
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        13 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱31.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        14 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱33.25
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        15 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱35.50
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div
                class="overflow-x-auto rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4"
              >
                <table
                  class="w-full divide-y divide-slate-200 text-left text-sm"
                >
                  <thead>
                    <tr>
                      <th
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                      >
                        Distance (km)
                      </th>
                      <th
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500"
                      >
                        Regular Fare
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white">
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        16 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱37.75
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        17 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱40.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        18 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱42.25
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        19 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱44.50
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        20 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱46.75
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        21 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱49.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        22 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱51.25
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        23 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱53.50
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        24 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱55.75
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        25 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱58.00
                      </td>
                    </tr>
                    <tr
                      class="odd:bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      <td class="px-4 py-4 font-semibold text-slate-900">
                        26 km
                      </td>
                      <td
                        class="px-4 py-4 text-lg font-semibold text-slate-900"
                      >
                        ₱60.25
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
  </body>
</html>
