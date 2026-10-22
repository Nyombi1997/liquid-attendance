<?php
use App\Modules\Fleet\Services\Fleet;

$vehicleChecks = static function (array $checked) use ($vehicles): string {
    if (!$vehicles) {
        return '<p class="muted small">Aucun véhicule dans le parc.</p>';
    }
    $html = '<div class="check-grid">';
    foreach ($vehicles as $v) {
        $html .= '<label class="check"><input type="checkbox" name="vehicle_ids[]" value="' . (int) $v['id'] . '"'
            . (in_array((int) $v['id'], $checked, true) ? ' checked' : '') . '> ' . e($v['plate']) . ' <small class="muted">' . e($v['brand'] . ' ' . $v['model']) . '</small></label>';
    }
    return $html . '</div>';
};
$plates = array_column($vehicles, 'plate', 'id');
?>
<div class="page-head">
  <div>
    <h1>Chauffeurs</h1>
    <p class="muted">Permis, véhicules autorisés et missions. Pour voir ses missions sur son téléphone, un chauffeur a besoin d'un compte relié à sa fiche, avec le rôle Parc automobile « Chauffeur ».</p>
  </div>
  <?php if (can('fleet_manage')): ?>
    <div class="head-actions">
      <a class="btn btn-outline" href="/flotte/import"><?= fa('fa-solid fa-file-import') ?> Importer (CSV)</a>
      <button class="btn btn-primary" type="button" data-dialog="new-driver"><?= icon('plus') ?> Nouveau chauffeur</button>
    </div>
  <?php endif; ?>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Chauffeur</th><th>Permis</th><th>Expiration</th><th>Véhicules autorisés</th><th class="num">Missions</th><th class="num">Note</th><th>Compte</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $auth = $authorized[(int) $d['id']] ?? []; ?>
      <tr class="<?= $d['active'] ? '' : 'is-muted' ?>">
        <td><div class="person"><?= avatar($d['name'], photo_url(['id' => $d['employee_id'], 'photo_updated_at' => $d['photo_updated_at']])) ?>
          <span><strong><?= e($d['name']) ?></strong><?= $d['active'] ? '' : ' <span class="badge badge-muted">Inactif</span>' ?><small class="block muted"><?= e($d['phone'] ?? '') ?></small></span></div></td>
        <td><?= e($d['license_number'] ?? '—') ?><?= $d['license_category'] ? ' <span class="tag">' . e($d['license_category']) . '</span>' : '' ?></td>
        <td><?= Fleet::expiryBadge($d['license_expires'], $alertDays) ?></td>
        <td><?= $auth ? e(implode(', ', array_map(static fn($id) => $plates[$id] ?? '?', $auth))) : '<span class="muted">Tous</span>' ?></td>
        <td class="num"><?= (int) $d['missions'] ?></td>
        <td class="num"><?= $d['rating'] !== null ? e(str_replace('.', ',', (string) $d['rating'])) . ' / 5' : '—' ?></td>
        <td><?= $d['user_id'] === null ? '<span class="badge badge-muted">Aucun</span>' : ($d['fleet_role'] === 'chauffeur' ? '<span class="badge badge-ok">Chauffeur</span>' : '<span class="badge badge-warn" title="Rôle Parc automobile à mettre sur « Chauffeur » (Utilisateurs)">Rôle à corriger</span>') ?></td>
        <td class="actions"><?php if (can('fleet_manage')): ?><button class="btn btn-ghost btn-sm" type="button" data-dialog="driver-<?= (int) $d['id'] ?>">Modifier</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$drivers): ?><tr><td colspan="8" class="muted center">Aucun chauffeur. Commencez par « Nouveau chauffeur ».</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if (can('fleet_manage')): ?>
<dialog id="new-driver" class="dialog dialog-wide">
  <form method="post" action="/flotte/chauffeurs" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau chauffeur</h2>
    <label class="field"><span>Employé</span><select name="employee_id" required>
      <option value="">Choisir…</option>
      <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['name']) ?></option><?php endforeach; ?>
    </select></label>
    <div class="form-grid">
      <label class="field"><span>N° de permis</span><input name="license_number" maxlength="40"></label>
      <label class="field"><span>Catégories (ex. B,C)</span><input name="license_category" maxlength="20" autocapitalize="characters"></label>
      <label class="field"><span>Expiration du permis</span><input type="date" name="license_expires"></label>
    </div>
    <fieldset class="field"><legend>Véhicules autorisés (aucun coché : tous)</legend><?= $vehicleChecks([]) ?></fieldset>
    <label class="field"><span>Remarques</span><input name="notes" maxlength="255"></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Ajouter</button>
    </div>
  </form>
</dialog>

<?php foreach ($drivers as $d): ?>
<dialog id="driver-<?= (int) $d['id'] ?>" class="dialog dialog-wide">
  <form method="post" action="/flotte/chauffeurs/<?= (int) $d['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2><?= e($d['name']) ?></h2>
    <div class="form-grid">
      <label class="field"><span>N° de permis</span><input name="license_number" value="<?= e($d['license_number']) ?>" maxlength="40"></label>
      <label class="field"><span>Catégories (ex. B,C)</span><input name="license_category" value="<?= e($d['license_category']) ?>" maxlength="20" autocapitalize="characters"></label>
      <label class="field"><span>Expiration du permis</span><input type="date" name="license_expires" value="<?= e($d['license_expires']) ?>"></label>
    </div>
    <fieldset class="field"><legend>Véhicules autorisés (aucun coché : tous)</legend><?= $vehicleChecks($authorized[(int) $d['id']] ?? []) ?></fieldset>
    <label class="field"><span>Remarques</span><input name="notes" value="<?= e($d['notes']) ?>" maxlength="255"></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $d['active'] ? 'checked' : '' ?>> Chauffeur actif</label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
<?php endif; ?>
