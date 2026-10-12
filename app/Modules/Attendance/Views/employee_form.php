<div class="page-head">
  <div>
    <h1>Modifier l'employé</h1>
    <p class="muted">N° pointeuse <code><?= e($emp['device_user_id']) ?></code> · nom sur la pointeuse : <?= e($emp['device_name'] ?? '—') ?></p>
  </div>
  <a class="btn btn-ghost" href="/employes/<?= (int) $emp['id'] ?>">Annuler</a>
</div>

<section class="card card-narrow">
  <form method="post" action="/employes/<?= (int) $emp['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <label class="field"><span>Nom affiché dans la plateforme</span>
      <input name="name" value="<?= e(old('name', $emp['name'])) ?>" maxlength="120" required>
      <small class="hint">Les pointeuses ne sont pas modifiées. Photo et statut : depuis la page <a href="/agents">Agents</a>.</small></label>
    <div class="grid-2 gap-sm">
      <label class="field"><span>Titre / poste</span><input name="job_title" value="<?= e(old('job_title', $emp['job_title'])) ?>" maxlength="120"></label>
      <label class="field"><span>Département</span>
        <select name="department_id"><option value="0">Aucun</option>
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) old('department_id', $emp['department_id']) === $d['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
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
    <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>
