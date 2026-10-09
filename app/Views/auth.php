<?php use App\Core\Config; ?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#273c88">
  <title><?= e($title ?? 'Connexion') ?> · <?= e(Config::get('app_name')) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/img/icon-192.png">
  <link rel="stylesheet" href="/assets/css/app.css?v=1">
  <script src="/assets/js/app.js?v=1" defer></script>
</head>
<body class="auth-body">
  <?= $content ?>
</body>
</html>
