<div class="page-head">
  <div>
    <h1>Sorties manquantes</h1>
    <p class="muted">Journées avec une entrée mais sans badge de sortie. Elles ne comptent aucune heure tant qu'une sortie n'est pas saisie.</p>
  </div>
  <div class="segmented">
    <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours'] as $k => $l): ?>
      <a class="<?= $days === $k ? 'is-active' : '' ?>" href="/corrections?jours=<?= $k ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Date</th><th>Employé</th><th>Arrivée</th><th>Fin prévue</th><th><?= can('manage') ? 'Saisir la sortie' : '' ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(day_short($r['work_date'])) ?> <small class="muted"><?= e(date('Y', strtotime($r['work_date']))) ?></small></td>
        <td><a class="person" href="/employes/<?= (int) $r['employee_id'] ?>"><span class="avatar avatar-sm"><?= e(initials($r['name'])) ?></span><span><strong><?= e($r['name']) ?></strong><small class="muted block"><?= e($r['group_name'] ?? 'Sans groupe') ?></small></span></a></td>
        <td><?= e(time_label($r['first_in'])) ?></td>
        <td class="muted"><?= e(time_label($r['expected_end'])) ?></td>
        <td>
          <?php if (can('manage')): ?>
          <form method="post" action="/corrections/<?= (int) $r['id'] ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="time" name="time" value="<?= e(substr((string) $r['expected_end'], 11, 5)) ?>" required aria-label="Heure de sortie">
            <input type="text" name="note" placeholder="Motif (facultatif)" maxlength="255" aria-label="Motif">
            <button class="btn btn-primary btn-sm" type="submit">Valider</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted center">Aucune sortie manquante sur la période.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($recent): ?>
<section class="card table-card">
  <div class="card-head"><h2>Dernières corrections</h2></div>
  <table class="table table-compact">
    <thead><tr><th>Date</th><th>Employé</th><th>Sortie saisie</th><th>Motif</th><th>Par</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('d/m/Y', strtotime($r['work_date']))) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><?= e(time_label($r['corrected_out'])) ?></td>
        <td class="muted"><?= e($r['correction_note'] ?? '—') ?></td>
        <td class="muted"><?= e($r['corrector'] ?? '—') ?>, <?= e(date('d/m H:i', strtotime($r['corrected_at']))) ?></td>
        <td class="actions"><?php if (can('manage')): ?>
          <form method="post" action="/corrections/<?= (int) $r['id'] ?>/annuler" data-confirm="Annuler cette correction ?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Annuler</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
