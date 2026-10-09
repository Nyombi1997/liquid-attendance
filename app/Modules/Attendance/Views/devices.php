<div class="page-head" data-live="devices" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e(date('Y-m-d')) ?>">
  <div>
    <h1>Pointeuses</h1>
    <p class="muted">Badges reçus en temps réel, synchronisation complète toutes les 30 minutes. Rien n'est effacé ni modifié sur les appareils, sauf l'horloge, remise à l'heure de la plateforme chaque nuit à 03:00.</p>
  </div>
  <?php if (can('admin')): ?><button class="btn btn-primary" type="button" data-dialog="device-new"><?= icon('plus') ?> Ajouter</button><?php endif; ?>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Pointeuse</th><th>Bureau</th><th>Rôle</th><th>Modèle</th><th>Dernière synchro</th><th>Dernier badge reçu</th><th>Horloge</th><th class="num">Utilisateurs</th><th class="num">Pointages</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d):
      $ok = (int) $d['last_sync_ok'] === 1;
      $offset = $d['clock_offset_sec'] !== null ? (int) $d['clock_offset_sec'] : null;
      $event = $d['last_event_at'] ?? null; ?>
      <tr class="<?= $d['active'] ? '' : 'is-muted' ?>" data-device-id="<?= (int) $d['id'] ?>">
        <td><strong><?= e($d['name']) ?></strong><small class="muted block"><?= e($d['ip']) ?>:<?= (int) $d['port'] ?></small></td>
        <td><?= e($d['office']) ?></td>
        <td><?= $d['usage_type'] === 'acces' ? '<span class="tag">Accès salle</span>' : ($d['direction'] === 'entree' ? 'Entrée' : 'Sortie') ?></td>
        <td class="muted"><?= e($d['model'] ?? '—') ?><small class="block"><?= e($d['serial_number'] ?? '') ?></small></td>
        <td data-col="sync">
          <?php if (!$d['last_sync_at']): ?><span class="badge badge-muted">En attente</span>
          <?php elseif ($ok): ?><span class="badge badge-ok">OK</span> <small class="muted"><?= e(date('d/m H:i', strtotime($d['last_sync_at']))) ?></small>
          <?php else: ?><span class="badge badge-danger">Erreur</span> <small class="muted block"><?= e($d['last_error']) ?></small><?php endif; ?>
        </td>
        <td class="nowrap" data-col="event">
          <span class="listening" data-listening<?= $d['live_since'] ?? null ? '' : ' hidden' ?>><span class="live-dot"></span>en écoute</span>
          <span class="block<?= $event ? '' : ' muted' ?>" data-ago="<?= e($event ?? '') ?>"><?= e(ago_label($event)) ?></span></td>
        <td class="nowrap" data-col="clock"><?php if ($offset === null): ?>—<?php elseif (abs($offset) <= 60): ?><span class="text-ok">à l'heure</span><?php else: ?><span class="badge badge-warn"><?= $offset > 0 ? 'avance' : 'retard' ?> de <?= abs($offset) >= 120 ? round(abs($offset) / 60) . ' min' : abs($offset) . ' s' ?></span><?php endif; ?></td>
        <td class="num"><?= e($d['user_count'] ?? '—') ?></td>
        <td class="num"><?= $d['log_count'] !== null ? number_format((int) $d['log_count'], 0, ',', ' ') : '—' ?></td>
        <td class="actions"><?php if (can('admin')): ?><button class="btn btn-ghost btn-sm" type="button" data-dialog="device-<?= (int) $d['id'] ?>">Modifier</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<p class="muted small">Une horloge décalée de plus d'une minute fausse les retards et les durées. Elle est remise à l'heure automatiquement chaque nuit à 03:00 ; si l'écart persiste, corrigez l'heure sur l'appareil (menu Système &gt; Date/Heure).</p>

<?php if (can('admin')):
  $form = function (?array $d) use ($offices) {
      $id = $d ? 'device-' . (int) $d['id'] : 'device-new';
      $action = $d ? '/pointeuses/' . (int) $d['id'] : '/pointeuses'; ?>
<dialog id="<?= $id ?>" class="dialog">
  <form method="post" action="<?= $action ?>" class="stack">
    <?= csrf_field() ?>
    <h2><?= $d ? 'Modifier la pointeuse' : 'Nouvelle pointeuse' ?></h2>
    <label class="field"><span>Nom</span><input name="name" value="<?= e($d['name'] ?? '') ?>" required></label>
    <div class="grid-2 gap-sm">
      <label class="field"><span>Adresse IP</span><input name="ip" value="<?= e($d['ip'] ?? '10.0.4.') ?>" required></label>
      <label class="field"><span>Port</span><input type="number" name="port" value="<?= (int) ($d['port'] ?? 4370) ?>"></label>
    </div>
    <label class="field"><span>Bureau</span><input name="office" list="offices" value="<?= e($d['office'] ?? '') ?>" required></label>
    <div class="grid-2 gap-sm">
      <label class="field"><span>Sens</span><select name="direction"><option value="entree" <?= ($d['direction'] ?? '') === 'entree' ? 'selected' : '' ?>>Entrée</option><option value="sortie" <?= ($d['direction'] ?? '') === 'sortie' ? 'selected' : '' ?>>Sortie</option></select></label>
      <label class="field"><span>Usage</span><select name="usage_type"><option value="presence" <?= ($d['usage_type'] ?? '') === 'presence' ? 'selected' : '' ?>>Présence (compte dans les heures)</option><option value="acces" <?= ($d['usage_type'] ?? '') === 'acces' ? 'selected' : '' ?>>Accès salle</option></select></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= !$d || $d['active'] ? 'checked' : '' ?>> Interroger cette pointeuse</label>
    <div class="dialog-actions"><button class="btn btn-ghost" type="button" data-close>Annuler</button><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</dialog>
<?php };
  $form(null);
  foreach ($devices as $d) { $form($d); } ?>
<datalist id="offices"><?php foreach ($offices as $o): ?><option value="<?= e($o['name']) ?>"><?php endforeach; ?></datalist>
<?php endif; ?>
