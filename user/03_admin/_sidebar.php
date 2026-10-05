<?php
$adminCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$adminNavigation = [
    ['file' => '01_dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
    ['file' => '02_passengers.php', 'icon' => 'group', 'label' => 'Passengers'],
    ['file' => '03_drivers.php', 'icon' => 'badge', 'label' => 'Drivers'],
    ['file' => '04_fleet.php', 'icon' => 'local_shipping', 'label' => 'Fleet'],
    ['file' => '05_routes_fares.php', 'icon' => 'alt_route', 'label' => 'Routes & Fares'],
    ['file' => '06_transactions.php', 'icon' => 'payments', 'label' => 'Transactions'],
    ['file' => '07_analytics.php', 'icon' => 'monitoring', 'label' => 'Analytics'],
    ['file' => 'boarding_hotspot.php', 'icon' => 'pin_drop', 'label' => 'Boarding Hotspots'],
    ['file' => 'peak_boarding_time.php', 'icon' => 'schedule', 'label' => 'Peak Boarding Time'],
    ['file' => '08_profile.php', 'icon' => 'person', 'label' => 'Profile'],
];
?>
<aside class="fixed left-0 top-0 z-50 flex h-full w-72 flex-col border-r border-slate-200 bg-slate-50">
  <div class="border-b border-slate-200 px-6 py-8">
    <a href="01_dashboard.php" class="text-2xl font-black tracking-tight text-blue-900">TrackFare</a>
    <p class="mt-2 text-sm text-slate-500">Fleet Manager Portal</p>
  </div>
  <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-6" aria-label="Admin navigation">
    <?php foreach ($adminNavigation as $item): ?>
      <?php $isCurrent = $adminCurrentPage === $item['file']; ?>
      <a
        class="flex items-center gap-3 rounded-r-full px-5 py-3 transition <?= $isCurrent ? 'border-r-4 border-blue-700 bg-blue-50 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-100 hover:text-blue-700' ?>"
        href="<?= htmlspecialchars($item['file'], ENT_QUOTES, 'UTF-8') ?>"
        <?= $isCurrent ? 'aria-current="page"' : '' ?>
      >
        <span class="material-symbols-outlined"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="border-t border-slate-200 px-6 py-6">
    <div class="flex items-center gap-3">
      <div class="h-12 w-12 overflow-hidden rounded-2xl border border-slate-200">
        <img src="../../images/pfp.png" alt="Fleet Manager" class="h-full w-full object-cover">
      </div>
      <div>
        <p class="text-sm font-semibold text-slate-900">Fleet Manager</p>
        <p class="text-xs text-slate-500">Admin</p>
      </div>
    </div>
    <a href="../../auth/logout.php" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
      <span class="material-symbols-outlined">logout</span>
      Logout
    </a>
  </div>
</aside>
