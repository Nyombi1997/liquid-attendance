<?php
/** @var string $kind */
/** @var array $def */
/** @var array $pending */
/** @var array $analysis */
/** @var string $fingerprint */
$c = $analysis['counts'];
$toSave = $c['create'] + $c['update'];
$badges = [
    'create'    => ['badge-ok', 'Création'],
    'update'    => ['badge-info', 'Mise à jour'],
    'unchanged' => ['badge-muted', 'Inchangée'],
    'error'     => ['badge-danger', 'Erreur'],
];
$separators = [';' => '« ; »', ',' => '« , »', "\t" => 'tabulation'];
?>
<div class="page-head">
  <div>
    <h1>Aperçu de l'import : <?= e(mb_strtolower($def['label'])) ?></h1>
    <p class="muted">Fichier <strong><?= e($pending['file']) ?></strong> · encodage <?= e($pending['encoding']) ?> · séparateur <?= e($separators[$pending['separator']] ?? $pending['separator']) ?>. <strong>Rien n'est encore enregistré.</strong></p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/flotte/import"><?= fa('fa-solid fa-arrow-left') ?> Autre fichier</a>
  </div>
</div>

<?php if ($analysis['fatal'] !== null): ?>
  <div class="alert alert-error"><?= e($analysis['fatal']) ?></div>
  <p><a class="btn btn-outline btn-sm" href="/flotte/import/modele?liste=<?= e($kind) ?>"><?= fa('fa-solid fa-file-csv') ?> Télécharger le modèle</a></p>
<?php else: ?>
  <section class="kpis">
    <div class="kpi"><span class="kpi-label">Créations</span><span class="kpi-value"><?= $c['create'] ?></span></div>
    <div class="kpi"><span class="kpi-label">Mises à jour de fiches existantes</span><span class="kpi-value"><?= $c['update'] ?></span></div>
    <div class="kpi"><span class="kpi-label">Lignes inchangées</span><span class="kpi-value"><?= $c['unchanged'] ?></span></div>
    <div class="kpi"><span class="kpi-label">Lignes en erreur</span><span class="kpi-value<?= $c['error'] ? ' text-danger' : '' ?>"><?= $c['error'] ?></span></div>
  </section>

  <section class="card stack">
    <?php if ($c['error']): ?>
      <p class="text-warn"><?= $c['error'] ?> ligne(s) en erreur ne seront pas importées. Corrigez-les dans le fichier puis importez-le de nouveau<?= $toSave ? ', ou confirmez pour enregistrer seulement les lignes valides' : '' ?>.</p>
    <?php endif; ?>
    <div class="import-actions">
      <?php if ($toSave): ?>
        <form method="post" action="/flotte/import/confirmer">
          <?= csrf_field() ?>
          <input type="hidden" name="liste" value="<?= e($kind) ?>">
          <input type="hidden" name="empreinte" value="<?= e($fingerprint) ?>">
          <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-check') ?> Confirmer l'import (<?= $toSave ?> ligne<?= $toSave > 1 ? 's' : '' ?>)</button>
        </form>
      <?php else: ?>
        <p class="muted">Aucune ligne à enregistrer.</p>
      <?php endif; ?>
      <form method="post" action="/flotte/import/annuler">
        <?= csrf_field() ?>
        <input type="hidden" name="liste" value="<?= e($kind) ?>">
        <button class="btn btn-ghost" type="submit">Annuler</button>
      </form>
    </div>
  </section>

  <section class="card table-card">
    <div class="table-scroll">
    <table class="table import-table">
      <thead><tr><th class="num">Ligne</th><th><?= e($kind === 'organisation' ? 'Direction › service' : ($kind === 'vehicules' ? 'Immatriculation' : 'Employé')) ?></th><th>Action</th><th>Détail</th></tr></thead>
      <tbody>
      <?php foreach ($analysis['lines'] as $l): [$cls, $label] = $badges[$l['status']]; ?>
        <tr class="<?= $l['status'] === 'error' ? 'import-error' : ($l['status'] === 'unchanged' ? 'is-muted' : '') ?>">
          <td class="num"><?= (int) $l['line'] ?></td>
          <td><strong><?= e($l['label'] !== '' ? $l['label'] : '—') ?></strong></td>
          <td><span class="badge <?= $cls ?>"><?= e($label) ?></span></td>
          <td>
            <?php if ($l['errors']): ?>
              <ul class="import-notes text-danger"><?php foreach ($l['errors'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
            <?php elseif ($l['changes']): ?>
              <ul class="import-notes"><?php foreach ($l['changes'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
            <?php else: ?><span class="muted">Déjà à jour</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php endif; ?>
