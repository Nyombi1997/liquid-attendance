<?php
/** @var string $content */
/** @var string $title */
use App\Core\Auth;
use App\Core\Config;

$user = Auth::user();
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$active = static function (string ...$prefixes) use ($path): string {
    foreach ($prefixes as $p) {
        if ($p === '/' ? $path === '/' : str_starts_with($path, $p)) {
            return ' is-active';
        }
    }
    return '';
};
// Menu selon le rôle : chaque entrée n'apparaît qu'avec le droit de sa route.
// La direction et les managers commencent par la page Agents.
$agents = ['/agents', 'id-card', 'Agents', ['/agents'], 'board'];
$presence = [
    ['/', 'dashboard', 'Tableau de bord', ['/'], 'board'],
    ['/presence', 'calendar', 'Présence du jour', ['/presence'], 'presence'],
];
$nav = [
    // Manager : son département uniquement
    (can('view') || can('staff') ? 'Présence' : 'Mon département') => array_merge(
        Auth::home() === '/agents' ? [$agents] : [],
        $presence,
        Auth::home() === '/agents' ? [] : [$agents],
        [
            ['/corrections', 'alert', 'Sorties manquantes', ['/corrections'], 'view'],
            ['/acces', 'door', 'Accès aux salles', ['/acces'], 'view'],
            ['/horaires', 'clock', 'Horaires', ['/horaires'], 'view'],
        ]
    ),
    'Personnel' => [
        ['/employes', 'users', 'Employés', ['/employes'], 'staff'],
        ['/departements', 'sitemap', 'Départements', ['/departements'], 'manage'],
        ['/groupes', 'layers', 'Groupes', ['/groupes'], 'view'],
    ],
    'Modules' => [
        ['/flotte', 'car', 'Parc automobile', ['/flotte'], 'view'],
        ['/conges', 'sun', 'Congés', ['/conges'], 'view'],
    ],
    'Administration' => [
        ['/pointeuses', 'fingerprint', 'Pointeuses', ['/pointeuses'], 'view'],
        ['/utilisateurs', 'shield', 'Utilisateurs', ['/utilisateurs'], 'admin'],
        ['/journal', 'list', 'Journal d\'activité', ['/journal'], 'admin'],
    ],
];
foreach ($nav as $section => $items) {
    $nav[$section] = array_filter($items, static fn(array $item) => can($item[4]));
}
$nav = array_filter($nav);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#273c88">
  <title><?= e($title ?? '') ?> · <?= e(Config::get('app_name')) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="<?= e(asset('img/icon-192.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/icon-192.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(Auth::home()) ?>">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="34" height="34">
      <span><?= e(Config::get('app_name')) ?></span>
    </a>
    <nav>
      <?php foreach ($nav as $section => $items): ?>
        <p class="nav-section"><?= e($section) ?></p>
        <?php foreach ($items as [$href, $ic, $label, $prefixes]): ?>
          <a class="nav-link<?= $active(...$prefixes) ?>" href="<?= e($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="/deconnexion" class="logout">
      <?= csrf_field() ?>
      <button type="submit" class="nav-link"><?= icon('logout') ?><span>Déconnexion</span></button>
    </form>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" type="button" data-toggle-sidebar aria-label="Menu"><?= icon('menu') ?></button>
      <form class="search" action="<?= can('staff') ? '/employes' : '/agents' ?>" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Rechercher un <?= can('staff') ? 'employé' : 'agent' ?>…" aria-label="Rechercher" autocomplete="off" data-suggest="go">
      </form>
      <div class="topbar-right">
        <span class="live-status" data-live-status hidden role="status" aria-live="polite">
          <span class="live-dot"></span><strong data-live-label>En direct</strong><small data-live-ago></small>
        </span>
        <label class="switch" data-live-notify hidden>
          <input type="checkbox" data-live-notify-input>
          <span class="switch-track" aria-hidden="true"></span><span class="switch-label">Notifications</span>
        </label>
        <span class="today"><?= e(ucfirst(date_fr(date('Y-m-d')))) ?></span>
        <a class="user-chip" href="/profil">
          <span class="avatar"><?= e(initials($user['full_name'] ?? '?')) ?></span>
          <span class="user-meta"><strong><?= e($user['full_name'] ?? '') ?></strong><small><?= e(Auth::ROLES[$user['role'] ?? ''] ?? '') ?><?php if (($user['role'] ?? '') === 'manager' && $user['department_id']): ?> · <?= e(App\Modules\Platform\Services\Departments::all()[(int) $user['department_id']]['name'] ?? '') ?><?php endif; ?></small></span>
        </a>
      </div>
    </header>

    <main class="content">
      <?php foreach (flash() ?? [] as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div class="backdrop" data-toggle-sidebar></div>
</body>
</html>
