<div class="empty-state">
  <?= icon('shield', 'icon icon-xl') ?>
  <h1>Accès refusé</h1>
  <p>Votre rôle ne permet pas d'accéder à cette page. Demandez à un administrateur si nécessaire.</p>
  <a class="btn btn-primary" href="<?= e(App\Core\Auth::home()) ?>">Retour à l'accueil</a>
</div>
