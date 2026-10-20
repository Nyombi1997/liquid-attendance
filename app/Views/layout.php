<?php
/** @var string $content */
/** @var string $title */
use App\Core\Auth;
use App\Core\Config;

$user = Auth::user();
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
// Préfixe d'adresse de l'entrée active ; « /flotte$ » : adresse exacte
$active = static function (string ...$prefixes) use ($path): string {
    foreach ($prefixes as $p) {
        if ($p === '/' || str_ends_with($p, '$') ? $path === rtrim($p, '$') : str_starts_with($path, $p)) {
            return ' is-active';
        }
    }
    return '';
};
// Menu selon le rôle : chaque entrée n'apparaît qu'avec le droit de sa route.
// La direction et les managers commencent par la page Agents.
// RH et managers : Agents, Tableau de bord, (Présence du jour), Calendrier, Employés ;
// rien de ce qui demande le droit « view » (sorties manquantes, modules…).
$agents = ['/agents', 'id-card', 'Agents', ['/agents'], 'board'];
$presence = [
    ['/', 'dashboard', 'Tableau de bord', ['/'], 'board'],
    ['/presence', 'calendar', 'Présence du jour', ['/presence'], 'presence'],
    ['/calendrier', 'timeline', 'Calendrier', ['/calendrier'], 'calendar'],
    ['/pointages', 'arrow-in', 'Pointages', ['/pointages'], 'board'],
];
$nav = [
    // Manager : son département uniquement
    (Auth::role() === 'manager' ? 'Mon département' : 'Présence') => array_merge(
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
    // Parc automobile : chaque entrée selon le droit de sa route (comptes « employé » compris)
    'Parc automobile' => [
        ['/flotte/missions', 'arrow-out', 'Mes missions', ['/flotte/missions'], 'fleet_drive'],
        ['/flotte/demandes', 'list', 'Mes demandes', ['/flotte/demandes'], 'fleet'],
        ['/flotte/validations', 'shield', 'À valider', ['/flotte/validations'], 'fleet_validate'],
        ['/flotte/registre', 'layers', 'Registre des demandes', ['/flotte/registre'], 'fleet_board'],
        ['/flotte/planning', 'timeline', 'Planning', ['/flotte/planning'], 'fleet_board'],
        ['/flotte/vehicules', 'car', 'Véhicules', ['/flotte/vehicules', '/flotte/documents'], 'fleet_board'],
        ['/flotte/chauffeurs', 'id-card', 'Chauffeurs', ['/flotte/chauffeurs'], 'fleet_board'],
    ],
    'Modules' => [
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
  <meta name="color-scheme" content="light dark">
  <meta name="csrf-token" content="<?= e(App\Core\Csrf::token()) ?>">
  <meta name="user-id" content="<?= (int) ($user['id'] ?? 0) ?>">
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
      <?php if (can('staff')): ?>
      <form class="search" action="/employes" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Rechercher un employé…" aria-label="Rechercher" autocomplete="off" data-suggest="go">
      </form>
      <?php endif; ?>
      <div class="topbar-right">
        <a class="queue-badge" href="/flotte/missions" data-queue-badge hidden title="Saisies de mission gardées sur le téléphone, en attente d'envoi">
          <?= fa('fa-solid fa-cloud-arrow-up') ?><span data-queue-count>0</span>
        </a>
        <span class="live-status" data-live-status hidden role="status" aria-live="polite">
          <span class="live-dot"></span><strong data-live-label>En direct</strong><small data-live-ago></small>
        </span>
        <span class="today"><?= e(ucfirst(date_fr(date('Y-m-d')))) ?></span>
        <div class="notif" data-notif>
          <button type="button" class="notif-btn" data-notif-toggle aria-expanded="false" aria-controls="notif-panel" aria-label="Notifications" title="Notifications">
            <?= fa('fa-regular fa-bell') ?><span class="notif-count" data-notif-count hidden></span>
          </button>
          <div class="notif-panel" id="notif-panel" data-notif-panel hidden>
            <div class="notif-head">
              <strong>Notifications</strong>
              <button type="button" class="btn btn-ghost btn-sm" data-notif-read><?= fa('fa-solid fa-check-double') ?> Tout marquer comme lu</button>
            </div>
            <ul class="notif-list" data-notif-list>
              <li class="notif-empty muted">Aucun badge aujourd'hui.</li>
            </ul>
          </div>
        </div>
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
