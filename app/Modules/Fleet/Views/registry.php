<?php $showRequester = true; ?>
<div class="page-head">
  <div>
    <h1>Registre des demandes</h1>
    <p class="muted">Toutes les demandes de véhicule, de la création à la clôture. Les urgences passent en tête des demandes à affecter.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/flotte/planning"><?= fa('fa-solid fa-calendar-days') ?> Planning</a>
  </div>
</div>

<form class="filters card" method="get" data-auto-filter>
  <input type="hidden" name="filtre" value="<?= e($filter) ?>">
  <label class="field field-grow"><span>Rechercher</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Référence, destination, demandeur, immatriculation…"></label>
</form>

<nav class="chips" aria-label="Filtrer les demandes">
  <?php foreach ($filters as $k => $label): ?>
    <a class="chip<?= $filter === $k ? ' is-active' : '' ?>" href="/flotte/registre?<?= e(http_build_query(array_filter(['filtre' => $k, 'q' => $q]))) ?>"><?= e($label) ?> <span class="chip-count"><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if ($requests): ?>
  <ul class="req-list"><?php foreach ($requests as $r) { require __DIR__ . '/_request_card.php'; } ?></ul>
<?php else: ?>
  <div class="card muted">Aucune demande<?= $q !== '' ? ' pour « ' . e($q) . ' »' : '' ?>.</div>
<?php endif; ?>
