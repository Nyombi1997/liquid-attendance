<div class="page-head">
  <div>
    <h1>Groupes d'agents</h1>
    <p class="muted">Un groupe permet d'attribuer un horaire à plusieurs agents en une fois. Pour y ajouter des agents, cochez-les dans la liste des employés.</p>
  </div>
  <?php if (can('manage')): ?><button class="btn btn-primary" type="button" data-dialog="new-group"><?= icon('plus') ?> Nouveau groupe</button><?php endif; ?>
</div>

<div class="cards">
  <?php foreach ($groups as $g): ?>
    <article class="card group-card">
      <div class="group-icon"><?= icon('layers') ?></div>
      <h2><?= e($g['name']) ?></h2>
      <p class="muted"><?= e($g['description'] ?? 'Pas de description') ?></p>
      <p><strong><?= (int) $g['members'] ?></strong> agent(s) actif(s)</p>
      <div class="card-actions">
        <a class="btn btn-ghost btn-sm" href="/employes?group=<?= (int) $g['id'] ?>">Voir les agents</a>
        <?php if (can('manage')): ?>
          <button class="btn btn-ghost btn-sm" type="button" data-dialog="group-<?= (int) $g['id'] ?>">Modifier</button>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$groups): ?>
    <div class="empty-state"><p>Aucun groupe pour l'instant. Exemples : « 5e étage », « NOC », « Équipe de nuit ».</p></div>
  <?php endif; ?>
</div>

<?php if (can('manage')): ?>
<dialog id="new-group" class="dialog">
  <form method="post" action="/groupes" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau groupe</h2>
    <label class="field"><span>Nom</span><input name="name" maxlength="100" required></label>
    <label class="field"><span>Description</span><input name="description" maxlength="255"></label>
    <div class="dialog-actions"><button class="btn btn-ghost" type="button" data-close>Annuler</button><button class="btn btn-primary" type="submit">Créer</button></div>
  </form>
</dialog>
<?php foreach ($groups as $g): ?>
<dialog id="group-<?= (int) $g['id'] ?>" class="dialog">
  <form method="post" action="/groupes/<?= (int) $g['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2>Modifier le groupe</h2>
    <label class="field"><span>Nom</span><input name="name" value="<?= e($g['name']) ?>" maxlength="100" required></label>
    <label class="field"><span>Description</span><input name="description" value="<?= e($g['description']) ?>" maxlength="255"></label>
    <div class="dialog-actions">
      <button class="btn btn-danger-ghost" type="submit" formaction="/groupes/<?= (int) $g['id'] ?>/supprimer" data-confirm="Supprimer ce groupe ? Ses agents seront sans groupe et ses attributions d'horaire supprimées.">Supprimer</button>
      <span class="spacer"></span>
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
<?php endif; ?>
