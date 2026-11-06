<div class="page-head">
  <div>
    <h1>Types de véhicule</h1>
    <p class="muted">Proposés dans la fiche d'un véhicule et dans le champ « Type de véhicule souhaité » des demandes. Un type retiré n'est plus proposé, mais les véhicules et demandes existants le gardent.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/flotte/vehicules"><?= fa('fa-solid fa-car') ?> Véhicules</a>
    <button class="btn btn-primary" type="button" data-dialog="new-type"><?= icon('plus') ?> Nouveau type</button>
  </div>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Type</th><th class="num">Véhicules</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($types as $t): ?>
      <tr class="<?= $t['active'] ? '' : 'is-muted' ?>">
        <td><?= fa('fa-solid fa-tags muted') ?> <strong><?= e($t['label']) ?></strong></td>
        <td class="num"><?= (int) $t['vehicles'] ?></td>
        <td><?= $t['active'] ? '<span class="badge badge-ok">Proposé</span>' : '<span class="badge badge-muted">Retiré</span>' ?></td>
        <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-dialog="type-<?= e($t['code']) ?>">Modifier</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$types): ?><tr><td colspan="4" class="muted center">Aucun type. Commencez par « Nouveau type ».</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<dialog id="new-type" class="dialog">
  <form method="post" action="/flotte/types" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau type de véhicule</h2>
    <label class="field"><span>Nom</span><input name="label" maxlength="60" required placeholder="Ex. : 4x4 double cabine"></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Créer</button>
    </div>
  </form>
</dialog>

<?php foreach ($types as $t): ?>
<dialog id="type-<?= e($t['code']) ?>" class="dialog">
  <form method="post" action="/flotte/types/modifier" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="code" value="<?= e($t['code']) ?>">
    <h2><?= e($t['label']) ?></h2>
    <label class="field"><span>Nom</span><input name="label" value="<?= e($t['label']) ?>" maxlength="60" required></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> Proposé (véhicules et demandes)</label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
