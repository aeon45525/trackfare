<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Fleet Management</title>
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
      .status-active {
        background: rgba(0, 64, 161, 0.12);
        color: #0040a1;
      }
      .status-inactive {
        background: rgba(107, 114, 128, 0.16);
        color: #4b5563;
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
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
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
                Fleet Operations
              </h1>
              <p class="mt-2 text-slate-600">
                Monitor active buses and operational route assignments.
              </p>
            </div>
            <button
              class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/10 hover:bg-blue-700 transition"
            >
              <span class="material-symbols-outlined">add</span>
              Add Vehicle
            </button>
          </div>
        </header>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Total Vehicles
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface">8</h2>
            <p class="mt-3 text-sm text-slate-600">
              Total fleet units active in the TrackFare system.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Active Vehicles
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface">6</h2>
            <p class="mt-3 text-sm text-slate-600">
              Vehicles currently operating on active routes.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Active Routes
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface">3</h2>
            <p class="mt-3 text-sm text-slate-600">
              Currently monitored TrackFare route assignments.
            </p>
          </div>
          <div
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <p
              class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
            >
              Assigned Drivers
            </p>
            <h2 class="mt-4 text-3xl font-black text-on-surface">6</h2>
            <p class="mt-3 text-sm text-slate-600">
              Drivers assigned to operational fleet vehicles.
            </p>
          </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.5fr_0.8fr]">
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div
              class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <h2 class="text-lg font-bold text-on-surface">
                  Fleet Operations Overview
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                  Monitor active buses and operational route assignments.
                </p>
              </div>
              <div
                class="inline-flex items-center gap-2 rounded-full bg-surface-container px-4 py-2 text-xs font-semibold text-slate-600"
              >
                <span class="material-symbols-outlined text-sm"
                  >directions_bus</span
                >
                8 Fleet Units
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full text-left text-sm text-slate-600">
                <thead class="border-b border-slate-200 text-slate-500">
                  <tr>
                    <th class="px-4 py-3">Vehicle ID</th>
                    <th class="px-4 py-3">Plate Number</th>
                    <th class="px-4 py-3">Assigned Driver</th>
                    <th class="px-4 py-3">Current Route</th>
                    <th class="px-4 py-3">Operational Status</th>
                    <th class="px-4 py-3">Current Stop</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <tr class="bg-surface-container-lowest">
                    <td class="px-4 py-4 font-semibold text-slate-900">
                      Bus 001
                    </td>
                    <td class="px-4 py-4">ABC-1234</td>
                    <td class="px-4 py-4">Juan Dela Cruz</td>
                    <td class="px-4 py-4">Bocaue → Marilao</td>
                    <td class="px-4 py-4">
                      <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold status-active"
                        >Active</span
                      >
                    </td>
                    <td class="px-4 py-4">Bocaue Terminal</td>
                    <td class="px-4 py-4">
                      <div class="inline-flex gap-2">
                        <button
                          class="rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                        >
                          View
                        </button>
                        <button
                          class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-surface-container transition"
                        >
                          Track
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td class="px-4 py-4 font-semibold text-slate-900">
                      Bus 002
                    </td>
                    <td class="px-4 py-4">DEF-5678</td>
                    <td class="px-4 py-4">Maria Santos</td>
                    <td class="px-4 py-4">Marilao → Meycauayan</td>
                    <td class="px-4 py-4">
                      <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold status-active"
                        >Active</span
                      >
                    </td>
                    <td class="px-4 py-4">Marilao Town Center</td>
                    <td class="px-4 py-4">
                      <div class="inline-flex gap-2">
                        <button
                          class="rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                        >
                          View
                        </button>
                        <button
                          class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-surface-container transition"
                        >
                          Track
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr class="bg-surface-container-lowest">
                    <td class="px-4 py-4 font-semibold text-slate-900">
                      Bus 003
                    </td>
                    <td class="px-4 py-4">GHI-9012</td>
                    <td class="px-4 py-4">Alvin Reyes</td>
                    <td class="px-4 py-4">Bocaue → Meycauayan</td>
                    <td class="px-4 py-4">
                      <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold status-active"
                        >Active</span
                      >
                    </td>
                    <td class="px-4 py-4">Meycauayan West</td>
                    <td class="px-4 py-4">
                      <div class="inline-flex gap-2">
                        <button
                          class="rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                        >
                          View
                        </button>
                        <button
                          class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-surface-container transition"
                        >
                          Track
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td class="px-4 py-4 font-semibold text-slate-900">
                      Bus 004
                    </td>
                    <td class="px-4 py-4">JKL-3456</td>
                    <td class="px-4 py-4">Rhea Concepcion</td>
                    <td class="px-4 py-4">Bocaue → Marilao</td>
                    <td class="px-4 py-4">
                      <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold status-inactive"
                        >Inactive</span
                      >
                    </td>
                    <td class="px-4 py-4">-</td>
                    <td class="px-4 py-4">
                      <div class="inline-flex gap-2">
                        <button
                          class="rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                        >
                          View
                        </button>
                        <button
                          class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-surface-container transition"
                        >
                          Edit
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <aside class="space-y-4">
            <div
              class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
            >
              <div class="flex items-center justify-between gap-3 mb-5">
                <div>
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Vehicle Information
                  </p>
                  <h3 class="mt-2 text-lg font-bold text-on-surface">
                    Bus 001
                  </h3>
                  <p class="mt-1 text-sm text-slate-600">
                    Live operation summary for the selected fleet vehicle.
                  </p>
                </div>
                <span
                  class="inline-flex rounded-full px-3 py-1 text-xs font-semibold status-active"
                  >Active</span
                >
              </div>
              <div class="grid gap-4 text-sm text-slate-600">
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Vehicle ID
                  </p>
                  <p class="mt-2 font-semibold text-slate-900">Bus 001</p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Plate Number
                  </p>
                  <p class="mt-2 font-semibold text-slate-900">ABC-1234</p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Assigned Route
                  </p>
                  <p class="mt-2 font-semibold text-slate-900">
                    Bocaue → Marilao
                  </p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Assigned Driver
                  </p>
                  <p class="mt-2 font-semibold text-slate-900">
                    Juan Dela Cruz
                  </p>
                </div>
                <div class="rounded-3xl bg-surface-container p-4">
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Current Stop
                  </p>
                  <p class="mt-2 font-semibold text-slate-900">
                    Bocaue Terminal
                  </p>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </main>
    </div>
  </body>
</html>
