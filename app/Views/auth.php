<?php use App\Core\Config; ?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#273c88">
  <meta name="color-scheme" content="light dark">
  <title><?= e($title ?? 'Connexion') ?> · <?= e(Config::get('app_name')) ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" type="image/png" href="<?= e(asset('img/Q-color-liquid.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="auth-body">
  <?= $content ?>
</body>
</html>
