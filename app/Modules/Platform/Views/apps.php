<?php
/** @var array $apps */
use App\Core\Auth;

$firstName = explode(' ', trim(Auth::user()['full_name'] ?? ''))[0] ?? '';
?>
<div class="apps-home">
  <div class="apps-hello">
    <h1>Bonjour<?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h1>
    <p class="muted"><?= e(ucfirst(date_fr(date('Y-m-d')))) ?></p>
  </div>

  <?php if (can('staff')): ?>
  <form class="search apps-search" action="/employes" method="get" role="search">
    <?= icon('search') ?>
    <input type="search" name="q" placeholder="Rechercher un employé…" aria-label="Rechercher un employé" autocomplete="off" data-suggest="go">
  </form>
  <?php endif; ?>

  <nav class="apps-grid" aria-label="Applications">
    <?php foreach ($apps as $app): ?>
      <a class="app-tile" href="<?= e($app['home']) ?>">
        <span class="app-icon app-<?= e($app['color']) ?>"><?= fa($app['icon']) ?></span>
        <span class="app-name"><?= e($app['name']) ?></span>
        <?php if (!empty($app['soon'])): ?><span class="badge badge-muted">Bientôt</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</div>
