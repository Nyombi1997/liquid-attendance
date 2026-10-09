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
$nav = [
    'Présence' => [
        ['/', 'dashboard', 'Tableau de bord', ['/']],
        ['/presence', 'calendar', 'Présence du jour', ['/presence']],
        ['/corrections', 'alert', 'Sorties manquantes', ['/corrections']],
        ['/acces', 'door', 'Accès aux salles', ['/acces']],
        ['/horaires', 'clock', 'Horaires', ['/horaires']],
    ],
    'Personnel' => [
        ['/employes', 'users', 'Employés', ['/employes']],
        ['/groupes', 'layers', 'Groupes', ['/groupes']],
    ],
    'Modules' => [
        ['/flotte', 'car', 'Parc automobile', ['/flotte']],
        ['/conges', 'sun', 'Congés', ['/conges']],
    ],
    'Administration' => [
        ['/pointeuses', 'fingerprint', 'Pointeuses', ['/pointeuses']],
    ],
];
if (can('admin')) {
    $nav['Administration'][] = ['/utilisateurs', 'shield', 'Utilisateurs', ['/utilisateurs']];
    $nav['Administration'][] = ['/journal', 'list', 'Journal d\'activité', ['/journal']];
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#273c88">
  <title><?= e($title ?? '') ?> · <?= e(Config::get('app_name')) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon-192.png">
  <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="/assets/css/app.css?v=1">
  <script src="/assets/js/app.js?v=1" defer></script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/">
      <img src="/assets/img/logo.svg" alt="" width="34" height="34">
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
      <form class="search" action="/employes" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Rechercher un employé…" aria-label="Rechercher un employé">
      </form>
      <div class="topbar-right">
        <span class="today"><?= e(ucfirst(date_fr(date('Y-m-d')))) ?></span>
        <a class="user-chip" href="/profil">
          <span class="avatar"><?= e(initials($user['full_name'] ?? '?')) ?></span>
          <span class="user-meta"><strong><?= e($user['full_name'] ?? '') ?></strong><small><?= e(Auth::ROLES[$user['role'] ?? ''] ?? '') ?></small></span>
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
