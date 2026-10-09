<?php use App\Core\Config; ?>
<main class="auth">
  <section class="auth-panel">
    <div class="auth-brand">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="44" height="44">
      <div>
        <strong><?= e(Config::get('app_name')) ?></strong>
        <small>Plateforme interne</small>
      </div>
    </div>
    <h1>Connexion</h1>
    <p class="muted">Présence, parc automobile et congés, avec un seul compte.</p>

    <?php foreach (flash() ?? [] as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/connexion" class="stack">
      <?= csrf_field() ?>
      <label class="field">
        <span>Identifiant</span>
        <input type="text" name="username" value="<?= e(old('username')) ?>" autocomplete="username" required autofocus>
      </label>
      <label class="field">
        <span>Mot de passe</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn-primary btn-block" type="submit">Se connecter</button>
    </form>
  </section>
  <aside class="auth-art" aria-hidden="true">
    <div class="auth-art-card">
      <span class="dot dot-ok"></span> Entrée 5e étage · 07:58
    </div>
    <div class="auth-art-card">
      <span class="dot dot-accent"></span> Sortie 4e étage · 17:04
    </div>
    <p>Chaque pointage, au bon endroit, à la bonne heure.</p>
  </aside>
</main>
