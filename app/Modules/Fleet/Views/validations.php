<?php $showRequester = true; ?>
<div class="page-head">
  <div>
    <h1>Demandes à valider</h1>
    <p class="muted">Demandes de véhicule qui attendent votre accord<?= (App\Core\Auth::user()['fleet_role'] ?? '') === 'direction' ? ' (N+1 ou direction)' : ' (N+1)' ?>. Un refus doit être motivé ; le demandeur est prévenu.</p>
  </div>
</div>

<h2 class="section-title">En attente (<?= count($pending) ?>)</h2>
<?php if ($pending): ?>
  <ul class="req-list"><?php foreach ($pending as $r) { require __DIR__ . '/_request_card.php'; } ?></ul>
<?php else: ?>
  <div class="card muted"><?= fa('fa-regular fa-circle-check') ?> Aucune demande en attente de votre validation.</div>
<?php endif; ?>

<?php if ($decided): ?>
  <h2 class="section-title">Mes dernières décisions</h2>
  <ul class="req-list"><?php foreach ($decided as $r) { require __DIR__ . '/_request_card.php'; } ?></ul>
<?php endif; ?>
