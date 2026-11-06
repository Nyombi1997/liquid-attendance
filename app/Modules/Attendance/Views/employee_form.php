<?php
/** Création ($emp = null) ou modification d'un employé. */
$isNew = $emp === null;
$emp ??= ['id' => 0, 'name' => '', 'job_title' => null, 'department_id' => null, 'matricule' => null,
    'group_id' => null, 'province_id' => null, 'email' => null, 'phone' => null, 'active' => 1];
// Province actuelle conservée même si elle a été retirée de la liste
$provinceIds = array_map('intval', array_column($provinces, 'id'));
if ($emp['province_id'] !== null && !in_array((int) $emp['province_id'], $provinceIds, true)) {
    $provinces[] = ['id' => (int) $emp['province_id'], 'name' => (string) App\Core\Database::value('SELECT name FROM provinces WHERE id = ?', [$emp['province_id']])];
}
?>
<div class="page-head">
  <div>
    <?php if ($isNew): ?>
    <h1>Nouvel employé</h1>
    <p class="muted">Pour un agent pas encore synchronisé depuis les pointeuses. Les pointeuses ne sont pas modifiées.</p>
    <?php else: ?>
    <h1>Modifier l'employé</h1>
    <p class="muted">N° pointeuse <code><?= e($emp['device_user_id']) ?></code> · nom sur la pointeuse : <?= e($emp['device_name'] ?? '—') ?></p>
    <?php endif; ?>
  </div>
  <a class="btn btn-ghost" href="<?= $isNew ? '/employes' : '/employes/' . (int) $emp['id'] ?>">Annuler</a>
</div>

<section class="card card-narrow">
  <form method="post" action="<?= $isNew ? '/employes' : '/employes/' . (int) $emp['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="photo_data" data-crop-output>
    <fieldset class="field">
      <legend>Photo de profil</legend>
      <?php $cropperPhoto = $photo; require __DIR__ . '/_photo_cropper.php'; ?>
    </fieldset>
    <label class="field"><span>Nom affiché dans la plateforme</span>
      <input name="name" value="<?= e(old('name', $emp['name'])) ?>" maxlength="120" required>
      <small class="hint">Les pointeuses ne sont pas modifiées. Disponibilité (télétravail, congé…) : depuis la page <a href="/agents">Agents</a>.</small></label>
    <?php if ($isNew): ?>
    <label class="field"><span>N° pointeuse</span>
      <input name="device_user_id" value="<?= e(old('device_user_id')) ?>" maxlength="20" pattern="[A-Za-z0-9_\-]{1,20}" required inputmode="numeric">
      <small class="hint">Numéro sous lequel l'agent est (ou sera) enrôlé sur les pointeuses : ses badges seront reliés à cette fiche.</small></label>
    <?php endif; ?>
    <div class="grid-2 gap-sm">
      <label class="field"><span>Titre / poste</span><input name="job_title" value="<?= e(old('job_title', $emp['job_title'])) ?>" maxlength="120"></label>
      <label class="field"><span>Département</span>
        <select name="department_id"><option value="0">Aucun</option>
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) old('department_id', $emp['department_id']) === $d['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field"><span>Province</span>
        <select name="province_id"><option value="0">Aucune</option>
          <?php foreach ($provinces as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) old('province_id', $emp['province_id']) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field"><span>Matricule</span><input name="matricule" value="<?= e(old('matricule', $emp['matricule'])) ?>" maxlength="40"></label>
      <label class="field"><span>Groupe</span>
        <select name="group_id"><option value="0">Aucun</option>
          <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) old('group_id', $emp['group_id']) === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e(old('email', $emp['email'])) ?>" maxlength="150"></label>
      <label class="field"><span>Téléphone</span><input name="phone" value="<?= e(old('phone', $emp['phone'])) ?>" maxlength="40"></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $emp['active'] ? 'checked' : '' ?>> Employé actif (décocher pour un départ : l'historique est conservé)</label>
    <div><button class="btn btn-primary" type="submit"><?= $isNew ? 'Créer l\'employé' : 'Enregistrer' ?></button></div>
  </form>
</section>
