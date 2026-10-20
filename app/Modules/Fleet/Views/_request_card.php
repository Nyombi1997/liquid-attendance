<?php
/**
 * Carte d'une demande dans une liste (mes demandes, à valider, registre).
 * @var array $r ligne de RequestController::rows()
 * @var bool $showRequester afficher le demandeur
 */
use App\Modules\Fleet\Services\Fleet;
?>
<li>
  <a class="req-card" href="/flotte/demandes/<?= (int) $r['id'] ?>">
    <span class="list-main">
      <strong><?= (int) $r['urgent'] ? '<span class="req-urgent">' . fa('fa-solid fa-bolt') . ' Urgent</span> · ' : '' ?><?= e($r['destination']) ?></strong>
      <small><?= e($r['reference']) ?> · <?= e($r['purpose']) ?></small>
    </span>
    <span><?= Fleet::badge($r) ?></span>
    <span class="req-meta">
      <span><?= fa('fa-regular fa-calendar') ?> <?= e(Fleet::when($r['depart_at'])) ?> → <?= e(Fleet::when($r['return_at'])) ?></span>
      <?php if (!empty($showRequester)): ?><span><?= fa('fa-regular fa-user') ?> <?= e($r['requester_name']) ?><?= $r['department_name'] ? ' · ' . e($r['department_name']) : '' ?></span><?php endif; ?>
      <span><?= fa('fa-solid fa-users') ?> <?= (int) $r['passengers'] ?></span>
      <?php if ($r['plate']): ?><span><?= fa('fa-solid fa-car') ?> <?= e($r['plate']) ?><?= $r['driver_name'] ? ' · ' . e($r['driver_name']) : ((int) $r['with_driver'] ? '' : ' · sans chauffeur') ?></span><?php endif; ?>
      <?php if ((int) $r['out_of_town']): ?><span><?= fa('fa-solid fa-road') ?> Hors ville</span><?php endif; ?>
      <?php if ($r['status'] === 'terminee' && empty($r['rated']) && !empty($rateHint)): ?><span class="text-warn"><?= fa('fa-regular fa-star') ?> À évaluer</span><?php endif; ?>
    </span>
  </a>
</li>
