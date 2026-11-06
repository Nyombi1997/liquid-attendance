<div class="page-head">
  <div>
    <h1>Provinces</h1>
    <p class="muted">Province de rattachement des employés. Une demande de véhicule propose « Mission hors de &lt;province du demandeur&gt; ». Les RH, le profil Parc automobile et l'administrateur assignent la province depuis la fiche d'un employé ou la liste des employés. <?= $none ?> agent(s) actif(s) sans province.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/employes"><?= fa('fa-solid fa-users') ?> Voir les employés</a>
    <button class="btn btn-primary" type="button" data-dialog="new-province"><?= icon('plus') ?> Nouvelle province</button>
  </div>
</div>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Province</th><th class="num">Agents</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($provinces as $p): ?>
      <tr class="<?= $p['active'] ? '' : 'is-muted' ?>">
        <td><?= fa('fa-solid fa-map-location-dot muted') ?> <strong><?= e($p['name']) ?></strong></td>
        <td class="num"><?= (int) $p['agents'] ?></td>
        <td><?= $p['active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Retirée</span>' ?></td>
        <td class="actions">
          <button class="btn btn-ghost btn-sm" type="button" data-dialog="province-<?= (int) $p['id'] ?>">Modifier</button>
          <form method="post" action="/provinces/<?= (int) $p['id'] ?>/supprimer" data-confirm="Supprimer « <?= e($p['name']) ?> » ? Ses <?= (int) $p['agents'] ?> agent(s) n'auront plus de province.">
            <?= csrf_field() ?><button class="btn btn-danger-ghost btn-sm" type="submit">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$provinces): ?><tr><td colspan="4" class="muted center">Aucune province. Commencez par « Nouvelle province » (ex. Kinshasa, Kongo-Central, Haut-Katanga…).</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<dialog id="new-province" class="dialog">
  <form method="post" action="/provinces" class="stack">
    <?= csrf_field() ?>
    <h2>Nouvelle province</h2>
    <label class="field"><span>Nom</span><input name="name" maxlength="80" required></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Créer</button>
    </div>
  </form>
</dialog>

<?php foreach ($provinces as $p): ?>
<dialog id="province-<?= (int) $p['id'] ?>" class="dialog">
  <form method="post" action="/provinces/<?= (int) $p['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2><?= e($p['name']) ?></h2>
    <label class="field"><span>Nom</span><input name="name" value="<?= e($p['name']) ?>" maxlength="80" required></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>> Province active (proposée lors de l'assignation)</label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
