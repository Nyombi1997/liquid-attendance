<?php
use App\Modules\Fleet\Services\Fleet;

$card = static function (array $m): string {
    return '<li><a class="req-card" href="/flotte/missions/' . (int) $m['id'] . '">'
        . '<span class="list-main"><strong>' . ((int) $m['urgent'] ? '<span class="req-urgent">' . fa('fa-solid fa-bolt') . ' Urgent</span> · ' : '') . e($m['destination']) . '</strong>'
        . '<small>' . e($m['reference']) . ' · ' . e($m['requester_name']) . ((int) $m['with_driver'] ? '' : ' (vous conduisez)') . '</small></span>'
        . '<span>' . Fleet::badge($m) . '</span>'
        . '<span class="req-meta"><span>' . fa('fa-regular fa-calendar') . ' ' . e(Fleet::when($m['depart_at'])) . ' → ' . e(Fleet::when($m['return_at'])) . '</span>'
        . '<span>' . fa('fa-solid fa-car') . ' ' . e($m['plate']) . ' · ' . e($m['brand'] . ' ' . $m['model']) . '</span>'
        . '<span>' . fa('fa-solid fa-users') . ' ' . (int) $m['passengers'] . '</span></span></a></li>';
};
?>
<div class="mission-screen stack">
  <div class="page-head">
    <div>
      <h1>Mes missions</h1>
      <p class="muted">Démarrez et clôturez vos missions depuis le téléphone, même sans réseau : la saisie part dès que le réseau revient.</p>
    </div>
  </div>
  <div class="callout offline-queue" data-offline-status hidden role="status"></div>

  <?php if ($employee === null): ?>
    <div class="callout callout-warn">Votre compte n'est relié à aucune fiche employé : demandez à l'administrateur de le relier.</div>
  <?php else: ?>
    <h2 class="section-title">À faire</h2>
    <?php if ($current): ?>
      <ul class="req-list"><?php foreach ($current as $m) { echo $card($m); } ?></ul>
    <?php else: ?>
      <div class="card muted">Aucune mission prévue.</div>
    <?php endif; ?>
    <?php if ($done): ?>
      <h2 class="section-title">Terminées</h2>
      <ul class="req-list"><?php foreach ($done as $m) { echo $card($m); } ?></ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
