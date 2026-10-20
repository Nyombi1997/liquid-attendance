<?php
use App\Core\Auth;
use App\Modules\Platform\Services\Departments;

// Liste des départements pour le champ « Département » (rôle Manager)
$deptOptions = static function (?int $selected) use ($departments): string {
    $html = '<option value="0">Choisir…</option>';
    foreach ($departments as $d) {
        $html .= '<option value="' . $d['id'] . '"' . ($selected === $d['id'] ? ' selected' : '') . '>'
            . str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) . '</option>';
    }
    return $html;
};
// Fiche employé reliée au compte (demandes de véhicule, chauffeur, N+1)
$employeeOptions = static function (?int $selected, ?int $userId) use ($employees): string {
    $html = '<option value="0">Aucune</option>';
    foreach ($employees as $emp) {
        $taken = $emp['user_id'] !== null && (int) $emp['user_id'] !== $userId;
        $html .= '<option value="' . (int) $emp['id'] . '"' . ($selected === (int) $emp['id'] ? ' selected' : '') . ($taken ? ' disabled' : '') . '>'
            . e($emp['name']) . ($taken ? ' (déjà reliée)' : '') . '</option>';
    }
    return $html;
};
$fleetOptions = static function (string $selected): string {
    $html = '';
    foreach (Auth::FLEET_ROLES as $k => $l) {
        $html .= '<option value="' . $k . '"' . ($selected === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html;
};
?>
<div class="page-head">
  <div>
    <h1>Utilisateurs</h1>
    <p class="muted">Comptes de connexion à la plateforme et leurs rôles.</p>
  </div>
  <button class="btn btn-primary" type="button" data-dialog="new-user"><?= icon('plus') ?> Nouveau compte</button>
</div>

<div class="role-help">
  <div class="card card-flat"><strong><?= fa('fa-solid fa-user-shield') ?> Administrateur</strong><p class="muted">Tout : seul rôle qui modifie les fiches, photos, départements, groupes, horaires, corrections, comptes et pointeuses.</p></div>
  <div class="card card-flat"><strong><?= fa('fa-solid fa-user-tie') ?> Ressources humaines</strong><p class="muted">Tableau de bord, Agents (mise à jour de la disponibilité uniquement) et Employés en consultation. Tous les départements.</p></div>
  <div class="card card-flat"><strong><?= fa('fa-solid fa-crown') ?> Direction (CEO)</strong><p class="muted">Voit tous les départements et toutes les pages, sans aucune modification. Arrive sur la page Agents.</p></div>
  <div class="card card-flat"><strong><?= fa('fa-solid fa-people-group') ?> Manager</strong><p class="muted">Page Agents, tableau de bord et présence du jour de son département uniquement.</p></div>
  <div class="card card-flat"><strong><?= fa('fa-solid fa-eye') ?> Lecteur</strong><p class="muted">Consultation et export, sans modification.</p></div>
  <div class="card card-flat"><strong><?= fa('fa-solid fa-car') ?> Employé</strong><p class="muted">Parc automobile seulement : demandes de véhicule et, pour un chauffeur, ses missions. Compte relié à une fiche employé.</p></div>
</div>
<p class="muted small"><?= fa('fa-solid fa-circle-info') ?> Parc automobile : tout compte relié à une fiche employé peut demander un véhicule. Le rôle Parc automobile ajoute la validation (validateur, direction), la gestion du parc (gestionnaire) ou les missions (chauffeur). Le responsable d'un service désigné dans Départements valide les demandes de son équipe.</p>

<section class="card table-card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Département</th><th>Fiche employé</th><th>Parc automobile</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><div class="person"><span class="avatar avatar-sm"><?= e(initials($u['full_name'])) ?></span><?= e($u['full_name']) ?></div></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e(Auth::ROLES[$u['role']]) ?></td>
        <td><?= $u['role'] === 'manager' ? ($u['department_id'] ? e(Departments::path((int) $u['department_id'])) : '<span class="badge badge-danger">À choisir</span>') : '<span class="muted">Tous</span>' ?></td>
        <td><?= $u['employee_name'] !== null ? e($u['employee_name']) : '<span class="muted">—</span>' ?></td>
        <td><?= e(Auth::FLEET_ROLES[$u['fleet_role']] ?? '') ?></td>
        <td><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : '<span class="muted">jamais</span>' ?></td>
        <td><?= $u['active'] ? '<span class="badge badge-ok">Actif</span>' : '<span class="badge badge-muted">Désactivé</span>' ?></td>
        <td class="actions"><button class="btn btn-ghost btn-sm" type="button" data-dialog="user-<?= (int) $u['id'] ?>">Modifier</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<dialog id="new-user" class="dialog">
  <form method="post" action="/utilisateurs" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau compte</h2>
    <label class="field"><span>Nom complet</span><input name="full_name" value="<?= e(old('full_name')) ?>" required></label>
    <label class="field"><span>Identifiant</span><input name="username" value="<?= e(old('username')) ?>" pattern="[a-zA-Z0-9._\-]{3,60}" autocapitalize="none" spellcheck="false" required><small class="hint">Sans distinction de majuscules : enregistré en minuscules.</small></label>
    <label class="field"><span>Rôle</span>
      <select name="role" data-role-select><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>" <?= old('role', 'lecteur') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field" data-role-department><span>Département du manager</span>
      <select name="department_id"><?= $deptOptions((int) old('department_id', 0) ?: null) ?></select>
      <small class="hint">Le manager voit aussi les sous-départements.<?= $departments ? '' : ' Aucun département : <a href="/departements">les créer d\'abord</a>.' ?></small></label>
    <label class="field"><span>Fiche employé</span><select name="employee_id"><?= $employeeOptions((int) old('employee_id', 0) ?: null, null) ?></select>
      <small class="hint">Obligatoire pour un compte « employé », un chauffeur ou un validateur.</small></label>
    <label class="field"><span>Rôle Parc automobile</span><select name="fleet_role"><?= $fleetOptions((string) old('fleet_role', 'demandeur')) ?></select></label>
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
      <select name="role" data-role-select><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field" data-role-department><span>Département du manager</span>
      <select name="department_id"><?= $deptOptions($u['department_id'] !== null ? (int) $u['department_id'] : null) ?></select></label>
    <label class="field"><span>Fiche employé</span><select name="employee_id"><?= $employeeOptions($u['employee_id'] !== null ? (int) $u['employee_id'] : null, (int) $u['id']) ?></select></label>
    <label class="field"><span>Rôle Parc automobile</span><select name="fleet_role"><?= $fleetOptions($u['fleet_role']) ?></select></label>
    <label class="field"><span>Nouveau mot de passe (laisser vide pour ne pas changer)</span><input type="password" name="password" minlength="12" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Compte actif</label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
