<?php use App\Core\Auth; ?>
<div class="page-head">
  <div>
    <h1>Utilisateurs</h1>
    <p class="muted">Comptes de connexion à la plateforme et leurs rôles.</p>
  </div>
  <button class="btn btn-primary" type="button" data-dialog="new-user"><?= icon('plus') ?> Nouveau compte</button>
</div>

<div class="grid-3 role-help">
  <div class="card card-flat"><strong>Administrateur</strong><p class="muted">Tout, y compris les comptes et les pointeuses.</p></div>
  <div class="card card-flat"><strong>Ressources humaines</strong><p class="muted">Employés, groupes, horaires et corrections.</p></div>
  <div class="card card-flat"><strong>Lecteur</strong><p class="muted">Consultation et export, sans modification.</p></div>
</div>

<section class="card table-card">
  <table class="table">
    <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><div class="person"><span class="avatar avatar-sm"><?= e(initials($u['full_name'])) ?></span><?= e($u['full_name']) ?></div></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e(Auth::ROLES[$u['role']]) ?></td>
        <td><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : '<span class="muted">jamais</span>' ?></td>
        <td><?= $u['active'] ? '<span class="badge badge-ok">Actif</span>' : '<span class="badge badge-muted">Désactivé</span>' ?></td>
        <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-dialog="user-<?= (int) $u['id'] ?>">Modifier</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<dialog id="new-user" class="dialog">
  <form method="post" action="/utilisateurs" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau compte</h2>
    <label class="field"><span>Nom complet</span><input name="full_name" value="<?= e(old('full_name')) ?>" required></label>
    <label class="field"><span>Identifiant</span><input name="username" value="<?= e(old('username')) ?>" pattern="[a-zA-Z0-9._\-]{3,60}" required></label>
    <label class="field"><span>Rôle</span>
      <select name="role"><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Mot de passe provisoire (12 caractères minimum)</span><input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Créer</button>
    </div>
  </form>
</dialog>

<?php foreach ($users as $u): ?>
<dialog id="user-<?= (int) $u['id'] ?>" class="dialog">
  <form method="post" action="/utilisateurs/<?= (int) $u['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2><?= e($u['username']) ?></h2>
    <label class="field"><span>Nom complet</span><input name="full_name" value="<?= e($u['full_name']) ?>" required></label>
    <label class="field"><span>Rôle</span>
      <select name="role"><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Nouveau mot de passe (laisser vide pour ne pas changer)</span><input type="password" name="password" minlength="12" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Compte actif</label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
