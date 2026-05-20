<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Driver Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <!-- Leaflet for live map -->
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.10.0/dist/leaflet.css"
      integrity="sha256-b48gk2+23GPlCGSO44BXlrwYMy9UFj+sZh1QmP4lw5w="
      crossorigin=""
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
      .glass-nav {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(20px);
      }
      .signature-gradient {
        background: linear-gradient(135deg, #0040a1 0%, #0056d2 100%);
      }
      /* Route progress tracker */
      .route-track {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
      }
      .stop-dot {
        width: 12px;
        height: 12px;
        border-radius: 9999px;
        background: #cbd5e1;
        border: 2px solid #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
      }
      .stop-complete {
        background: #22c55e;
      }
      .stop-current {
        background: #0040a1;
      }
      .track-line {
        height: 4px;
        background: linear-gradient(90deg, #cbd5e1, #cbd5e1);
        flex: 1;
        border-radius: 9999px;
      }

      /* Passenger list accordion */
      .passenger-item {
        border-radius: 12px;
        padding: 0.75rem;
        background: transparent;
        border: 1px solid transparent;
      }
      .passenger-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
      .passenger-body {
        padding-top: 0.5rem;
        color: #475569;
      }
      .accordion-open {
        border-color: #e2e8f0;
        background: #ffffff;
      }

      /* Live map card */
      #live-map {
        width: 100%;
        height: 520px;
        min-height: 420px;
        border-radius: 1rem;
        overflow: hidden;
        position: relative;
      }
      #live-map iframe {
        width: 100%;
        height: 100%;
        border: none;
        border-radius: inherit;
        display: block;
      }
    </style>
  </head>
  <body class="bg-slate-100 text-on-background font-body antialiased">
    <!-- Layout Wrapper -->
    <div class="flex min-h-screen">
      <!-- SideNavBar -->
      <aside
        class="fixed left-0 top-0 h-screen w-[260px] bg-white border-r border-slate-200 shadow-sm"
      >
        <div class="flex h-full flex-col">
          <div class="px-6 py-8 border-b border-slate-200">
            <span
              class="text-2xl font-black tracking-tight text-primary font-headline"
              >TrackFare</span
            >
            <p class="mt-2 text-sm text-slate-500">Driver Panel</p>
          </div>
          <nav class="flex-1 px-4 py-6 space-y-1">
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold transition"
              href="01_dashboard.php"
            >
              <span class="material-symbols-outlined">dashboard</span>
              <span>Dashboard</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="02_route.php"
            >
              <span class="material-symbols-outlined">alt_route</span>
              <span>Route</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="03_logs.php"
            >
              <span class="material-symbols-outlined">receipt_long</span>
              <span>Logs</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="04_profile.php"
            >
              <span class="material-symbols-outlined">person</span>
              <span>Profile</span>
            </a>
          </nav>
          <div class="mt-auto px-6 py-6 border-t border-slate-200">
            <div class="flex items-center gap-3">
              <div
                class="w-12 h-12 rounded-2xl overflow-hidden border border-slate-200"
              >
                <img
                  src="../../images/pfp.png"
                  alt="Driver profile"
                  class="w-full h-full object-cover"
                />
              </div>
              <div>
                <p class="text-sm font-semibold text-slate-900">
                  Juan Dela Cruz
                </p>
                <p class="text-xs text-slate-500">Driver</p>
              </div>
            </div>
            <button
              class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
              title="Logout"
            >
              <span class="material-symbols-outlined">logout</span>
              Logout
            </button>
          </div>
        </div>
      </aside>
      <!-- Main Content -->
      <main class="ml-[260px] flex-1 min-h-screen bg-slate-100 p-10">
        <div class="max-w-full">
          <header class="mb-10">
            <h1
              class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline"
            >
              Dashboard
            </h1>
            <p class="mt-2 text-sm text-slate-600">
              Driver overview for the current route and onboard passengers.
            </p>
          </header>
          <div class="grid grid-cols-12 gap-8">
            <section class="col-span-12 xl:col-span-7 space-y-6">
              <!-- Live Map -->
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <p
                  class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                >
                  Live Map
                </p>
                <div class="mt-4">
                  <div id="live-map">
                    <iframe
                      id="live-map-iframe"
                      src="https://www.openstreetmap.org/export/embed.html?bbox=120.9500%2C14.7800%2C121.0800%2C14.8900&layer=mapnik&marker=14.8400%2C121.0167"
                      title="Live route map"
                      loading="lazy"
                    ></iframe>
                  </div>
                </div>
              </article>
              <!-- Live Trip Controls -->
              <article
                class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
              >
                <p
                  class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                >
                  Live Trip Controls
                </p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                  <button
                    class="rounded-2xl bg-emerald-600 text-white py-2 text-xs font-semibold"
                  >
                    Start Trip
                  </button>
                  <button
                    class="rounded-2xl bg-sky-600 text-white py-2 text-xs font-semibold"
                  >
                    Arrive at Stop
                  </button>
                  <button
                    class="rounded-2xl bg-indigo-600 text-white py-2 text-xs font-semibold"
                  >
                    Depart Stop
                  </button>
                  <button
                    class="rounded-2xl bg-rose-600 text-white py-2 text-xs font-semibold"
                  >
                    End Trip
                  </button>
                </div>
              </article>
            </section>
            <section class="col-span-12 xl:col-span-5 space-y-6">
              <article
                class="rounded-[1.5rem] bg-white p-8 shadow-sm border border-slate-200"
              >
                <div class="flex items-start justify-between gap-6">
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Active Route
                    </p>
                    <h2 class="mt-3 text-3xl font-black text-slate-900">
                      Bocaue–Meycauayan Line
                    </h2>
                    <p class="mt-2 text-sm text-slate-600">
                      Bocaue → Marilao → Meycauayan
                    </p>
                  </div>
                  <span
                    class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700"
                    >On Route</span
                  >
                </div>
                <div class="mt-6">
                  <p
                    class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                  >
                    Route Progress
                  </p>
                  <div class="mt-3 route-track">
                    <div class="flex items-center gap-3 w-full">
                      <div class="stop-dot stop-complete" title="Bocaue"></div>
                      <div class="track-line" style="max-width: 40px"></div>
                      <div class="stop-dot stop-current" title="Marilao"></div>
                      <div class="track-line" style="max-width: 40px"></div>
                      <div class="stop-dot" title="Meycauayan"></div>
                    </div>
                    <div class="ml-3 text-sm text-slate-600">
                      Marilao (Current Stop)
                    </div>
                  </div>
                </div>
              </article>
              <!-- Onboard Passenger List (accordion) -->
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <div class="flex items-center justify-between">
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Onboard Passengers
                    </p>
                    <h2 class="mt-3 text-2xl font-black text-slate-900">
                      Passenger List
                    </h2>
                  </div>
                  <span
                    class="inline-flex items-center rounded-full bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-700"
                    >Updated now</span
                  >
                </div>
                <div class="mt-6 space-y-3" id="passenger-list">
                  <div class="passenger-item accordion-open">
                    <div class="passenger-header" data-toggle>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">
                          John Doe
                          <span class="text-xs text-slate-500">#P1023</span>
                        </div>
                        <div class="text-xs text-slate-500">
                          Boarding: Marilao
                        </div>
                      </div>
                      <div class="text-sm text-slate-900 font-semibold">
                        Tapped In ✔
                      </div>
                    </div>
                  </div>

                  <div class="passenger-item">
                    <div class="passenger-header" data-toggle>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">
                          Maria Santos
                          <span class="text-xs text-slate-500">#P1102</span>
                        </div>
                      </div>
                      <div class="text-sm text-slate-900 font-semibold">
                        Tapped Out ✔
                      </div>
                    </div>
                    <div class="passenger-body">Exit Stop: Meycauayan</div>
                  </div>

                  <div class="passenger-item">
                    <div class="passenger-header" data-toggle>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">
                          Luis Ramirez
                          <span class="text-xs text-slate-500">#P1188</span>
                        </div>
                        <div class="text-xs text-slate-500">
                          Boarding: Meycauayan
                        </div>
                      </div>
                      <div class="text-sm text-slate-900 font-semibold">
                        Not Tapped ⏳
                      </div>
                    </div>
                  </div>
                </div>
              </article>

              <!-- Trip Metrics & Earnings -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <article
                  class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
                >
                  <p
                    class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold"
                  >
                    Trip Metrics
                  </p>
                  <div class="mt-3 text-sm text-slate-700 space-y-2">
                    <div class="flex justify-between">
                      <span>Trip Duration</span><strong>1h 20m</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Distance Traveled</span><strong>42.5 km</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Average Speed</span><strong>32 km/h</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Est. Arrival</span><strong>12:40 PM</strong>
                    </div>
                  </div>
                </article>

                <article
                  class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
                >
                  <p
                    class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold"
                  >
                    Earnings
                  </p>
                  <div class="mt-3 text-sm text-slate-700 space-y-2">
                    <div class="flex justify-between">
                      <span>Current Trip</span><strong>₱520</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Collected Fares</span><strong>₱4,200</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Estimated Today</span><strong>₱5,100</strong>
                    </div>
                  </div>
                </article>
              </div>
            </section>
          </div>
        </div>
      </main>
    </div>
    <!-- Leaflet JS and UI scripts -->
    <script
      src="https://unpkg.com/leaflet@1.10.0/dist/leaflet.js"
      integrity="sha256-gZOGcR9PGyS3zT8aqfD7VG4HFSYx4M0Qv2eMCyMaC5k="
      crossorigin=""
    ></script>
    <script>
      // Passenger accordion
      document.addEventListener("DOMContentLoaded", function () {
        document
          .querySelectorAll("#passenger-list .passenger-item")
          .forEach(function (item) {
            const body = item.querySelector(".passenger-body");
            const hdr = item.querySelector("[data-toggle]");
            if (!item.classList.contains("accordion-open")) {
              if (body) body.style.display = "none";
            }
            if (hdr) {
              hdr.style.cursor = "pointer";
              hdr.addEventListener("click", function () {
                const open = item.classList.toggle("accordion-open");
                if (body) body.style.display = open ? "block" : "none";
              });
            }
          });

        // Initialize Leaflet map for Bulacan route
        const stops = [
          { name: "Bocaue", latlng: [14.7983, 120.9742] },
          { name: "Marilao", latlng: [14.757, 120.9588] },
          { name: "Meycauayan", latlng: [14.735, 120.9604] },
        ];

        try {
          const iframe = document.getElementById("live-map-iframe");
          if (iframe) {
            iframe.style.display = "none";
          }

          const mapEl = document.getElementById("live-map");
          if (!mapEl) return;

          const map = L.map(mapEl, {
            attributionControl: false,
            zoomControl: true,
          }).setView([14.77, 120.965], 12);
          L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
          }).addTo(map);
          const routeCoords = stops.map((s) => s.latlng);
          L.polyline(routeCoords, {
            color: "#0040a1",
            weight: 4,
            opacity: 0.85,
          }).addTo(map);
          stops.forEach((s, i) => {
            L.circleMarker(s.latlng, {
              radius: 7,
              fillColor: i === 1 ? "#0040a1" : "#22c55e",
              color: "#fff",
              weight: 2,
              fillOpacity: 1,
            })
              .addTo(map)
              .bindPopup("<b>" + s.name + "</b>");
          });
          // Static bus marker
          const busIcon = L.divIcon({
            className: "",
            html: '<div style="width:28px;height:28px;border-radius:14px;background:#0040a1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px">🚌</div>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
          });
          L.marker([14.778, 120.966], { icon: busIcon })
            .addTo(map)
            .bindPopup("<b>Your Bus</b>");
          map.fitBounds(L.latLngBounds(routeCoords).pad(0.2));
        } catch (e) {
          console.warn("Leaflet init failed", e);
          const iframe = document.getElementById("live-map-iframe");
          if (iframe) {
            iframe.style.display = "block";
          }
        }
      });
    </script>
  </body>
</html>
