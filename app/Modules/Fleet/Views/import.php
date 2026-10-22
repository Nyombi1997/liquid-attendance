<?php
/** @var array $kinds */
/** @var array<string, string> $pending fichiers en attente de confirmation, par liste */
use App\Modules\Fleet\Services\FleetImport;

$icons = ['organisation' => 'fa-solid fa-sitemap', 'vehicules' => 'fa-solid fa-car', 'chauffeurs' => 'fa-solid fa-id-card'];
?>
<div class="page-head">
  <div>
    <h1>Import CSV</h1>
    <p class="muted">Chargez l'organisation, les véhicules ou les chauffeurs depuis un fichier CSV (Excel : Enregistrer sous › CSV). Séparateur « ; » ou « , », encodage UTF-8 ou Windows-1252 : détection automatique. Un aperçu s'affiche avant tout enregistrement.</p>
  </div>
</div>

<div class="grid-3 import-grid">
  <?php foreach ($kinds as $key => $def): ?>
    <section class="card stack">
      <div class="card-head"><h2><?= fa($icons[$key]) ?> <?= e($def['label']) ?></h2></div>
      <p class="muted"><?= e($def['help']) ?></p>
      <p class="small">Colonnes : <?php foreach (array_values($def['columns']) as $i => [$title]): ?><?= $i ? ' ; ' : '' ?><?= in_array(array_keys($def['columns'])[$i], $def['required'], true) ? '<strong>' . e($title) . '</strong>' : e($title) ?><?php endforeach; ?></p>
      <a class="btn btn-outline btn-sm" href="/flotte/import/modele?liste=<?= e($key) ?>"><?= fa('fa-solid fa-file-csv') ?> Télécharger le modèle</a>
      <form method="post" action="/flotte/import" enctype="multipart/form-data" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="liste" value="<?= e($key) ?>">
        <label class="field"><span>Fichier CSV</span><input type="file" name="fichier" accept=".csv,.txt,text/csv" required></label>
        <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-magnifying-glass') ?> Voir l'aperçu</button>
      </form>
      <?php if (isset($pending[$key])): ?>
        <p class="small"><a href="/flotte/import/apercu?liste=<?= e($key) ?>">Aperçu en attente : <?= e($pending[$key]) ?></a></p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<section class="card stack">
  <h2>Règles</h2>
  <ul class="import-rules">
    <li><strong>Rien n'est enregistré avant la confirmation</strong> de l'aperçu. Seules les lignes valides sont enregistrées, en une seule fois ; les lignes en erreur sont ignorées (corrigez-les et importez le fichier de nouveau : les lignes déjà importées apparaîtront « inchangées »).</li>
    <li><strong>Dates</strong> : JJ/MM/AAAA ou AAAA-MM-JJ. <strong>Nombres</strong> : virgule ou point décimal.</li>
    <li><strong>Employé</strong> (N+1, chauffeur) : son nom tel qu'il apparaît dans la plateforme (majuscules, accents et ordre des mots libres) ou son n° de pointeuse, obligatoire si plusieurs employés portent le même nom.</li>
    <li><strong>Fiches existantes</strong> (même immatriculation, même employé, même nom de direction ou de service) : mises à jour ; une cellule vide ne change rien.</li>
    <li><strong>Organisation</strong> : une direction absente est créée ; le service est créé ou rattaché à la direction de la ligne ; le N+1 s'applique au service, ou à la direction si la colonne Service est vide.</li>
    <li><strong>Véhicules</strong> : marque et modèle obligatoires pour un nouveau véhicule ; statut : disponible, en panne ou en entretien ; une échéance d'assurance ou de contrôle technique différente de la dernière connue ajoute un document (sans fichier, à joindre ensuite sur la fiche).</li>
    <li><strong>Chauffeurs</strong> : catégories de permis A à G (ex. « B,C »). Pour voir ses missions, le chauffeur a aussi besoin d'un compte relié à sa fiche (Administration › Utilisateurs).</li>
    <li>Fichier de <?= FleetImport::MAX_BYTES / 1_048_576 ?> Mo et <?= FleetImport::MAX_ROWS ?> lignes au plus ; chaque import est noté dans le journal d'activité.</li>
  </ul>
</section>
