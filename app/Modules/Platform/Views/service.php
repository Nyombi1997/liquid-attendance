<?php
/** @var array $settings */
/** @var string $worker */
/** @var string $backup */
/** @var array $devices */
/** @var int $listening */
/** @var int $active */
/** @var int $dbSize */
/** @var array $tables */
$size = static fn(int $bytes): string => match (true) {
    $bytes >= 1073741824 => number_format($bytes / 1073741824, 2, ',', ' ') . ' Go',
    $bytes >= 1048576    => number_format($bytes / 1048576, 1, ',', ' ') . ' Mo',
    default              => number_format($bytes / 1024, 0, ',', ' ') . ' Ko',
};
$when = static fn(?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
$workerBadge = [
    'ok'   => ['badge-ok', 'En marche'],
    'late' => ['badge-warn', 'En retard'],
    'down' => ['badge-danger', 'Arrêté'],
    'none' => ['badge-muted', 'Jamais lancé'],
][$worker];
$backupBadge = [
    'ok'    => ['badge-ok', 'OK'],
    'late'  => ['badge-warn', 'En retard'],
    'error' => ['badge-danger', 'Échec'],
    'none'  => ['badge-muted', 'Aucune'],
][$backup];
?>
<div class="page-head">
  <div>
    <h1>État du service</h1>
    <p class="muted">Service de synchronisation (worker), pointeuses, base de données et sauvegarde nocturne. Rechargez la page pour actualiser.</p>
  </div>
</div>

<section class="kpis">
  <div class="kpi">
    <span class="kpi-label">Service de synchronisation</span>
    <span><span class="badge <?= $workerBadge[0] ?>"><?= e($workerBadge[1]) ?></span></span>
    <span class="kpi-foot">Dernier passage <?= e(ago_label($settings['worker_heartbeat_at'] ?? null)) ?><?php if (!empty($settings['worker_started_at'])): ?> · démarré le <?= e($when($settings['worker_started_at'])) ?><?php endif; ?></span>
  </div>
  <div class="kpi">
    <span class="kpi-label">Pointeuses en écoute</span>
    <span class="kpi-value"><?= $listening ?><small> / <?= $active ?></small></span>
    <span class="kpi-foot">Calcul global <?= e(ago_label($settings['compute_last_at'] ?? null)) ?></span>
  </div>
  <div class="kpi">
    <span class="kpi-label">Taille de la base</span>
    <span class="kpi-value"><?= e($size($dbSize)) ?></span>
    <span class="kpi-foot">Données et index (MariaDB)</span>
  </div>
  <div class="kpi">
    <span class="kpi-label">Dernière sauvegarde</span>
    <span><span class="badge <?= $backupBadge[0] ?>"><?= e($backupBadge[1]) ?></span></span>
    <span class="kpi-foot"><?php if (!empty($settings['backup_last_at'])): ?><?= e($when($settings['backup_last_at'])) ?> · <?= e($size((int) ($settings['backup_last_size'] ?? 0))) ?><?php else: ?>Chaque nuit à 02:00<?php endif; ?></span>
  </div>
</section>

<?php if ($backup === 'error'): ?>
  <div class="alert alert-error">Dernière sauvegarde en échec : <?= e($settings['backup_last_error']) ?></div>
<?php endif; ?>
<?php if ($worker === 'down' || $worker === 'late'): ?>
  <div class="alert alert-error">Le service de synchronisation ne répond plus : badges et calculs sont arrêtés. Sur la VM : <code>sudo docker compose logs --tail=100 worker</code>.</div>
<?php endif; ?>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Pointeuse</th><th>État</th><th>Dernier badge</th><th>Reçu en direct</th><th>Horloge</th><th>Dernière synchro</th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d):
      $offset = $d['clock_offset_sec'] !== null ? (int) $d['clock_offset_sec'] : null; ?>
      <tr class="<?= $d['active'] ? '' : 'is-muted' ?>">
        <td><strong><?= e($d['name']) ?></strong><small class="muted block"><?= e($d['ip']) ?> · <?= e($d['office']) ?><?= $d['model'] ? ' · ' . e($d['model']) : '' ?></small></td>
        <td>
          <?php if (!$d['active']): ?><span class="badge badge-muted">Désactivée</span>
          <?php elseif ($d['live_since']): ?><span class="listening"><span class="live-dot"></span>en écoute</span> <small class="muted block">depuis <?= e($when($d['live_since'])) ?></small>
          <?php elseif ((int) $d['last_sync_ok'] === 0 && $d['last_sync_at']): ?><span class="badge badge-danger">Erreur</span> <small class="muted block"><?= e($d['last_error']) ?></small>
          <?php else: ?><span class="badge badge-muted">En attente</span><?php endif; ?>
        </td>
        <td class="nowrap"><?= e($when($d['last_punch'])) ?></td>
        <td class="nowrap"><?= e(ago_label($d['last_event_at'])) ?></td>
        <td class="nowrap"><?php if ($offset === null): ?>—<?php elseif (abs($offset) <= 30): ?><span class="text-ok"><?= sprintf('%+d s', $offset) ?></span><?php else: ?><span class="badge badge-warn"><?= sprintf('%+d s', $offset) ?></span><?php endif; ?></td>
        <td class="nowrap muted"><?= e($when($d['last_sync_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="6" class="muted center">Aucune pointeuse.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table table-compact">
    <thead><tr><th>Table la plus volumineuse</th><th class="num">Lignes (estimation)</th><th class="num">Taille</th></tr></thead>
    <tbody>
    <?php foreach ($tables as $t): ?>
      <tr><td><code><?= e($t['name']) ?></code></td><td class="num"><?= number_format((int) $t['row_estimate'], 0, ',', ' ') ?></td><td class="num"><?= e($size((int) $t['bytes'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<p class="muted small">Horloges : remises à l'heure de la VM au démarrage du service (écart de plus de 30 s) et chaque nuit à 03:00. Sauvegardes : <code>./data/backups</code> sur la VM, 14 jours gardés ; sauvegarde immédiate : <code>sudo docker compose exec worker python3 worker/sync.py --sauvegarde</code>.</p>
