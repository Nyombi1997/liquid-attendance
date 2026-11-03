<?php
/** @var string $content */
/** @var string $title */
use App\Core\Auth;
use App\Core\Config;
use App\Modules\Platform\Services\Apps;

$user = Auth::user();
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
// Applications à la manière d'Odoo : grille d'accueil (/applications) et, dans chaque
// application, sa barre de menus. Menus et droits : App\Modules\Platform\Services\Apps.
$apps = Apps::all();
$app = Apps::current($path);
$active = static fn(array $prefixes): string => Apps::matches($path, $prefixes) ? ' is-active' : '';
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
  <link rel="icon" type="image/png" href="<?= e(asset('img/Q-color-liquid.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<div class="shell">
  <header class="appbar">
    <?php if (count($apps) > 1): ?>
      <a class="appbar-grid<?= $path === '/applications' ? ' is-active' : '' ?>" href="/applications" aria-label="Applications" title="Applications"><?= fa('fa-solid fa-grip') ?></a>
    <?php endif; ?>
    <?php if ($app): ?>
      <a class="appbar-name" href="<?= e($app['home']) ?>"><?= fa($app['icon']) ?><span><?= e($app['name']) ?></span></a>
      <button class="menu-toggle" type="button" data-toggle-menu aria-expanded="false" aria-controls="appbar-menu" aria-label="Menu"><?= fa('fa-solid fa-bars') ?></button>
      <nav class="appbar-menu" id="appbar-menu" aria-label="<?= e($app['name']) ?>">
        <?php foreach ($app['menu'] as $i => $entry): ?>
          <?php if (is_array($entry[1])): ?>
            <?php $open = array_filter($entry[1], static fn(array $item) => $active($item[3]) !== ''); ?>
            <div class="appbar-group" data-dropdown>
              <button type="button" class="appbar-link<?= $open ? ' is-active' : '' ?>" data-dropdown-toggle aria-expanded="false" aria-controls="appbar-drop-<?= $i ?>"><?= e($entry[0]) ?><?= fa('fa-solid fa-chevron-down', 'appbar-caret') ?></button>
              <div class="appbar-drop" id="appbar-drop-<?= $i ?>" data-dropdown-panel hidden>
                <?php foreach ($entry[1] as [$label, $href, , $prefixes]): ?>
                  <a class="appbar-drop-link<?= $active($prefixes) ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <a class="appbar-link<?= $active($entry[3]) ?>" href="<?= e($entry[1]) ?>"><?= e($entry[0]) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php else: ?>
      <a class="appbar-brand" href="<?= e(Apps::landing()) ?>">
        <img src="<?= e(asset('img/LOGO-LIT-blanc.png')) ?>" alt="Liquid Intelligent Technologies" width="150" height="44">
      </a>
    <?php endif; ?>

    <div class="appbar-right">
      <a class="queue-badge" href="/flotte/missions" data-queue-badge hidden title="Saisies de mission gardées sur le téléphone, en attente d'envoi">
        <?= fa('fa-solid fa-cloud-arrow-up') ?><span data-queue-count>0</span>
      </a>
      <span class="live-status" data-live-status hidden role="status" aria-live="polite">
        <span class="live-dot"></span><strong data-live-label>En direct</strong><small data-live-ago></small>
      </span>
      <div class="notif" data-notif<?= can('board') ? ' data-badges' : '' ?>>
        <button type="button" class="notif-btn" data-notif-toggle aria-expanded="false" aria-controls="notif-panel" aria-label="Notifications" title="Notifications">
          <?= fa('fa-regular fa-bell') ?><span class="notif-count" data-notif-count hidden></span>
        </button>
        <div class="notif-panel" id="notif-panel" data-notif-panel hidden>
          <div class="notif-head">
            <strong>Notifications</strong>
            <button type="button" class="btn btn-ghost btn-sm" data-notif-read><?= fa('fa-solid fa-check-double') ?> Tout marquer comme lu</button>
          </div>
          <ul class="notif-list" data-notif-list>
            <li class="notif-empty muted">Aucune notification.</li>
          </ul>
        </div>
      </div>
      <div class="user-menu" data-dropdown>
        <button type="button" class="user-chip" data-dropdown-toggle aria-expanded="false" aria-controls="user-drop" aria-label="Mon compte" title="<?= e($user['full_name'] ?? '') ?>">
          <span class="avatar"><?= e(initials($user['full_name'] ?? '?')) ?></span>
        </button>
        <div class="appbar-drop user-drop" id="user-drop" data-dropdown-panel hidden>
          <div class="user-meta">
            <strong><?= e($user['full_name'] ?? '') ?></strong>
            <small><?= e(Auth::ROLES[$user['role'] ?? ''] ?? '') ?><?php if (($user['role'] ?? '') === 'manager' && $user['department_id']): ?> · <?= e(App\Modules\Platform\Services\Departments::all()[(int) $user['department_id']]['name'] ?? '') ?><?php endif; ?></small>
          </div>
          <?php if (count($apps) > 1): ?>
            <a class="appbar-drop-link" href="/applications"><?= fa('fa-solid fa-grip') ?> Applications</a>
          <?php endif; ?>
          <a class="appbar-drop-link<?= $active(['/profil']) ?>" href="/profil"><?= fa('fa-regular fa-user') ?> Mon profil</a>
          <form method="post" action="/deconnexion">
            <?= csrf_field() ?>
            <button type="submit" class="appbar-drop-link"><?= fa('fa-solid fa-right-from-bracket') ?> Déconnexion</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <div class="main">
    <main class="content">
      <?php foreach (flash() ?? [] as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div class="backdrop" data-toggle-menu></div>
</body>
</html>
