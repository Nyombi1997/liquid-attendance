<div class="empty-state">
  <?= icon('search', 'icon icon-xl') ?>
  <h1>Page introuvable</h1>
  <p>Cette adresse n'existe pas ou plus.</p>
  <a class="btn btn-primary" href="/"><?= auth_user() ? 'Retour au tableau de bord' : 'Se connecter' ?></a>
</div>
