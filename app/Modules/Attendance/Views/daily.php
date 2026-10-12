<?php
$statuses = ['present' => 'Présent', 'retard' => 'Retard', 'absent' => 'Absent', 'sortie_manquante' => 'Sortie manquante',
    'en_cours' => 'En cours', 'hors_horaire' => 'Hors horaire', 'repos' => 'Repos'];
$multi = $filters['from'] !== $filters['to'];
$full = can('view'); // false : manager, journée du jour et son département uniquement
$query = array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'group' => $filters['group'] ?: null, 'dep' => $filters['dep'] ?: null,
    'status' => $filters['status'] ?: null, 'q' => $filters['q'] ?: null]);
$shift = fn(int $days) => url('/presence', array_merge($query, [
    'from' => date('Y-m-d', strtotime($filters['from'] . " $days days")),
    'to' => date('Y-m-d', strtotime($filters['to'] . " $days days")),
]));
?>
<div class="page-head"<?php if ($live): ?> data-live="presence" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e($filters['from']) ?>" data-live-group="<?= (int) $filters['group'] ?>" data-live-deps="<?= e(implode(',', $filters['dep'] ? App\Modules\Platform\Services\Departments::descendants($filters['dep']) : [])) ?>" data-live-status="<?= e($filters['status']) ?>" data-live-q="<?= e($filters['q']) ?>"<?php endif; ?>>
  <div>
    <h1>Présence</h1>
    <p class="muted"><?= $multi ? 'Du ' . e(date_fr($filters['from'], false)) . ' au ' . e(date_fr($filters['to'], false)) : e(ucfirst(date_fr($filters['from']))) ?> · <?= count($rows) ?> ligne(s)</p>
  </div>
  <?php if ($full): ?>
  <div class="head-actions">
    <a class="btn btn-ghost" href="<?= e($shift(-1)) ?>" aria-label="Jour précédent"><?= icon('chevron-left') ?></a>
    <a class="btn btn-ghost" href="/presence">Aujourd'hui</a>
    <a class="btn btn-ghost" href="<?= e($shift(1)) ?>" aria-label="Jour suivant"><?= icon('chevron-right') ?></a>
    <a class="btn btn-outline" href="<?= e(url('/presence/export', $query)) ?>"><?= icon('download') ?> Export CSV</a>
  </div>
  <?php endif; ?>
</div>

<form class="filters card" method="get" action="/presence">
  <?php if ($full): ?>
  <label class="field"><span>Du</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
  <label class="field"><span>Au</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
  <?php endif; ?>
  <?php if ($departments): ?>
  <label class="field"><span>Département</span>
    <select name="dep"><option value="0">Tous</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $filters['dep'] === (int) $d['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <label class="field"><span>Groupe</span>
    <select name="group"><option value="0">Tous</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $filters['group'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field"><span>Statut</span>
    <select name="status"><option value="">Tous (hors repos)</option>
      <?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field field-grow"><span>Employé</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, matricule ou numéro"></label>
  <button class="btn btn-primary" type="submit"><?= icon('filter') ?> Filtrer</button>
</form>

<div class="summary">
  <span>Présence totale <strong><?= minutes_label($totals['worked']) ?></strong></span>
  <span>Retards cumulés <strong><?= minutes_label($totals['late']) ?></strong></span>
  <span>Heures sup <strong><?= minutes_label($totals['overtime']) ?></strong></span>
</div>

<?php if ($live): ?>
<p class="live-bar" data-live-bar hidden>Nouveaux pointages : <a href="<?= e(url('/presence', $query)) ?>">actualiser</a></p>
<?php endif; ?>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr>
      <?php if ($multi): ?><th>Date</th><?php endif; ?>
      <th>Employé</th><th>Horaire</th><th>Arrivée</th><th>Départ</th><th class="num">Présence</th><th class="num">Retard</th><th class="num">Heures sup</th><th>Statut</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-employee-id="<?= (int) $r['employee_id'] ?>">
        <?php if ($multi): ?><td class="nowrap"><?= e(date('d/m/Y', strtotime($r['work_date']))) ?></td><?php endif; ?>
        <td><a class="person" href="<?= $full ? '/employes/' . (int) $r['employee_id'] . '?mois=' . e(substr($r['work_date'], 0, 7)) : '/agents' ?>">
          <?= avatar($r['name'], photo_url(['id' => $r['employee_id'], 'photo_updated_at' => $r['photo_updated_at']])) ?>
          <span><strong><?= e($r['name']) ?></strong><small class="muted block"><?= e($r['group_name'] ?? 'Sans groupe') ?></small></span></a></td>
        <td class="muted"><?= e($r['schedule_name'] ?? '—') ?><?php if ($r['expected_start']): ?><small class="block"><?= e(time_label($r['expected_start'])) ?>–<?= e(time_label($r['expected_end'])) ?></small><?php endif; ?></td>
        <td class="nowrap" data-col="first_in"><?= e(time_label($r['first_in'])) ?></td>
        <td class="nowrap" data-col="last_out"><?= e(time_label($r['last_out'])) ?><?php if ($r['corrected_out']): ?> <span class="tag" title="<?= e($r['correction_note'] ?? 'Saisie manuelle') ?>">corrigée</span><?php endif; ?></td>
        <td class="num" data-col="worked"><?= minutes_label((int) $r['worked_minutes']) ?></td>
        <td class="num <?= $r['late_minutes'] > 0 ? 'text-warn' : '' ?>" data-col="late"><?= e(late_label((int) $r['late_minutes'])) ?></td>
        <td class="num" data-col="overtime"><?= minutes_label((int) $r['overtime_minutes']) ?></td>
        <td data-col="status"><?= status_badge($r['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted center">Aucune donnée pour ces critères.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
