<?php use App\Core\Auth; ?>
<div class="page-head">
  <div>
    <h1>Mon profil</h1>
    <p class="muted"><?= e($user['full_name']) ?> · <?= e(Auth::ROLES[$user['role']]) ?> · identifiant <strong><?= e($user['username']) ?></strong></p>
  </div>
</div>

<section class="card card-narrow">
  <h2>Changer mon mot de passe</h2>
  <form method="post" action="/profil/mot-de-passe" class="stack">
    <?= csrf_field() ?>
    <label class="field"><span>Mot de passe actuel</span>
      <input type="password" name="current_password" autocomplete="current-password" required></label>
    <label class="field"><span>Nouveau mot de passe (12 caractères minimum)</span>
      <input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
    <label class="field"><span>Confirmation</span>
      <input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label>
    <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>
