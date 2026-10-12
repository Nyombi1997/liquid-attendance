<div class="page-head">
  <div>
    <h1>Accès aux salles</h1>
    <p class="muted">Entrées au NOC et à l'IT Room. La sortie se fait par bouton poussoir : elle n'est pas enregistrée et ces passages ne comptent pas dans les heures de présence.</p>
  </div>
</div>

<form class="filters card" method="get" action="/acces">
  <label class="field"><span>Date</span><input type="date" name="date" value="<?= e($date) ?>"></label>
  <label class="field"><span>Salle</span>
    <select name="device"><option value="0">Toutes</option>
      <?php foreach ($devices as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $device === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="field field-grow"><span>Employé</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom ou numéro" data-suggest="submit"></label>
  <button class="btn btn-primary" type="submit"><?= icon('filter') ?> Filtrer</button>
</form>

<section class="card table-card">
  <table class="table">
    <thead><tr><th>Heure</th><th>Employé</th><th>Salle</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('H:i:s', strtotime($r['punched_at']))) ?></td>
        <td><?php if ($r['employee_id']): ?><a href="/employes/<?= (int) $r['employee_id'] ?>"><?= e($r['employee']) ?></a><?php else: ?><?= e($r['employee']) ?><?php endif; ?></td>
        <td><span class="dir dir-acces"><?= icon('door') ?></span> <?= e($r['device']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="muted center">Aucun passage ce jour-là.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
