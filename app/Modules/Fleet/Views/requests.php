<?php
$active = array_filter($requests, static fn($r) => in_array($r['status'], ['en_validation', 'approuvee', 'affectee', 'en_cours'], true));
$past = array_filter($requests, static fn($r) => !in_array($r['status'], ['en_validation', 'approuvee', 'affectee', 'en_cours'], true));
$showRequester = false;
$rateHint = true;
?>
<div class="page-head">
  <div>
    <h1>Mes demandes de véhicule</h1>
    <p class="muted">Suivez vos demandes, de la validation à la fin de la mission.</p>
  </div>
  <div class="head-actions">
    <?php if ($pending > 0): ?><a class="btn btn-outline" href="/flotte/validations"><?= fa('fa-solid fa-stamp') ?> À valider <span class="badge badge-accent"><?= $pending ?></span></a><?php endif; ?>
    <?php if ($employee !== null): ?><a class="btn btn-primary" href="/flotte/demandes/nouvelle"><?= icon('plus') ?> Nouvelle demande</a><?php endif; ?>
  </div>
</div>

<?php if ($employee === null): ?>
  <div class="callout callout-warn"><?= fa('fa-solid fa-circle-info') ?> Votre compte n'est relié à aucune fiche employé : demandez à l'administrateur de le relier (Utilisateurs) pour pouvoir demander un véhicule.</div>
<?php else: ?>
  <h2 class="section-title">En cours</h2>
  <?php if ($active): ?>
    <ul class="req-list"><?php foreach ($active as $r) { require __DIR__ . '/_request_card.php'; } ?></ul>
  <?php else: ?>
    <div class="card muted">Aucune demande en cours. <a href="/flotte/demandes/nouvelle">Demander un véhicule</a>.</div>
  <?php endif; ?>
  <?php if ($past): ?>
    <h2 class="section-title">Historique</h2>
    <ul class="req-list"><?php foreach ($past as $r) { require __DIR__ . '/_request_card.php'; } ?></ul>
  <?php endif; ?>
<?php endif; ?>
