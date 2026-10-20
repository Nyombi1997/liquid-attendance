<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetWorkflow;

$r = $req;
$canStart = $access['driver'] && FleetWorkflow::canStart($r);
$canClose = $access['driver'] && FleetWorkflow::canClose($r);
$km = static fn(?int $v) => $v !== null ? number_format($v, 0, ',', ' ') . ' km' : '—';
?>
<div class="mission-screen stack" data-mission-screen="<?= (int) $r['id'] ?>">
  <p class="muted"><a href="<?= $access['driver'] ? '/flotte/missions' : '/flotte/demandes/' . (int) $r['id'] ?>"><?= fa('fa-solid fa-arrow-left') ?> <?= $access['driver'] ? 'Mes missions' : 'Demande ' . e($r['reference']) ?></a></p>

  <section class="card mission-head">
    <span class="plate"><?= e($r['plate']) ?></span>
    <h1><?= e($r['destination']) ?></h1>
    <p><?= Fleet::badge($r) ?> <span class="muted"><?= e($r['reference']) ?> · <?= e($r['brand'] . ' ' . $r['model']) ?></span></p>
    <dl class="facts facts-tight">
      <dt>Départ</dt><dd><strong><?= e(Fleet::when($r['depart_at'])) ?></strong></dd>
      <dt>Retour prévu</dt><dd><?= e(Fleet::when($r['return_at'])) ?></dd>
      <dt>Demandeur</dt><dd><?= e($r['requester_name']) ?><?= $r['requester_phone'] ? ' · <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $r['requester_phone'])) . '">' . e($r['requester_phone']) . '</a>' : '' ?></dd>
      <dt>Passagers</dt><dd><?= (int) $r['passengers'] ?></dd>
      <dt>Motif</dt><dd><?= e($r['purpose']) ?></dd>
      <?php if ($mission): ?>
        <dt>Démarrée</dt><dd><?= e(Fleet::when($mission['started_at'])) ?> · <?= e($km((int) $mission['start_km'])) ?> · carburant <?= e(Fleet::FUEL_LEVELS[(int) $mission['start_fuel']] ?? '') ?></dd>
        <?php if ($mission['ended_at']): ?>
          <dt>Clôturée</dt><dd><?= e(Fleet::when($mission['ended_at'])) ?> · <?= e($km((int) $mission['end_km'])) ?> · <strong><?= e($km((int) $mission['end_km'] - (int) $mission['start_km'])) ?></strong> parcourus</dd>
        <?php endif; ?>
      <?php endif; ?>
    </dl>
  </section>

  <div class="callout offline-queue" data-offline-status hidden role="status"></div>

  <?php if ((int) $r['assigned_before_validation'] && $r['validation'] === 'en_attente'): ?>
    <p class="callout callout-warn"><?= fa('fa-solid fa-bolt') ?> Mission urgente : validation en cours, le départ est autorisé.</p>
  <?php endif; ?>

  <?php if ($canStart): ?>
    <?php $moment = 'depart'; $hiddenForm = false; require __DIR__ . '/_mission_form.php'; ?>
    <?php // Clôture préparée : affichée par app.js après un démarrage enregistré hors réseau ?>
    <?php $moment = 'retour'; $hiddenForm = true; require __DIR__ . '/_mission_form.php'; ?>
  <?php elseif ($canClose): ?>
    <?php $moment = 'retour'; $hiddenForm = false; require __DIR__ . '/_mission_form.php'; ?>
  <?php elseif ($r['status'] === 'terminee'): ?>
    <p class="callout callout-ok"><?= fa('fa-solid fa-circle-check') ?> Mission terminée.</p>
  <?php elseif (!$access['driver']): ?>
    <p class="callout">Consultation : seul le chauffeur affecté démarre et clôture la mission.</p>
  <?php else: ?>
    <p class="callout callout-warn">Cette mission ne peut pas être démarrée (<?= e(FleetWorkflow::label($r)[0]) ?>).</p>
  <?php endif; ?>
</div>
