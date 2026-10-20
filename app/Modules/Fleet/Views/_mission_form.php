<?php
/**
 * Formulaire de démarrage ou de clôture d'une mission (écran téléphone).
 * Envoyé par app.js (setupMissionForms) : tout de suite si le réseau répond,
 * sinon gardé dans le téléphone (IndexedDB) et envoyé au retour du réseau.
 * @var string $moment depart | retour
 * @var array  $req
 * @var array|null $mission
 * @var bool   $hiddenForm formulaire de clôture affiché après un démarrage hors réseau
 */
use App\Modules\Fleet\Services\Fleet;

$isStart = $moment === 'depart';
$uid = $moment . '-' . (int) $req['id'];
$minKm = $isStart ? null : (int) ($mission['start_km'] ?? 0);
?>
<section class="card mission-form" data-mission-form="<?= e($moment) ?>"<?= !empty($hiddenForm) ? ' hidden data-after-start' : '' ?>>
  <h2><?= $isStart ? fa('fa-solid fa-play') . ' Démarrer la mission' : fa('fa-solid fa-flag-checkered') . ' Clôturer la mission' ?></h2>
  <form method="post" action="/flotte/missions/<?= (int) $req['id'] ?>/<?= $isStart ? 'demarrer' : 'cloturer' ?>" class="stack"
        data-offline-form="<?= e($moment) ?>" data-mission="<?= (int) $req['id'] ?>" data-reference="<?= e($req['reference']) ?>"
        <?= $minKm !== null ? 'data-start-km="' . $minKm . '"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="client_id" value="">
    <input type="hidden" name="recorded_at" value="">
    <input type="hidden" name="photo_data" value="" data-photo-data>
    <label class="field"><span>Kilométrage au compteur</span>
      <input type="number" name="km" inputmode="numeric" min="<?= $minKm !== null ? $minKm + 1 : 0 ?>" max="5000000" step="1" required
             placeholder="<?= $req['odometer_km'] !== null ? 'Dernier relevé : ' . e(number_format((int) $req['odometer_km'], 0, ',', ' ')) : '' ?>">
      <?php if ($minKm !== null): ?><small class="hint">Départ : <?= e(number_format($minKm, 0, ',', ' ')) ?> km. Le retour doit être supérieur.</small><?php endif; ?>
    </label>
    <fieldset class="field"><legend>Niveau de carburant</legend>
      <div class="fuel-picker">
        <?php foreach (Fleet::FUEL_LEVELS as $value => $label): ?>
          <label><input type="radio" name="fuel" value="<?= $value ?>" required><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <fieldset class="field"><legend>État du véhicule</legend>
      <div class="cond-picker">
        <?php foreach (Fleet::CONDITIONS as $value => $label): ?>
          <label><input type="radio" name="condition" value="<?= e($value) ?>" <?= $value === 'bon' ? 'checked' : '' ?> required><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <label class="field"><span>Remarques (obligatoire si un problème est signalé)</span><textarea name="notes" rows="2" maxlength="500"></textarea></label>
    <div class="field">
      <span>Photo du compteur</span>
      <div class="photo-pick">
        <input type="file" accept="image/*" capture="environment" id="photo-<?= e($uid) ?>" data-photo-input required>
        <img class="photo-preview" alt="Aperçu de la photo" data-photo-preview hidden>
      </div>
      <small class="hint">Réduite sur le téléphone avant l'envoi.</small>
    </div>
    <p class="callout callout-danger" data-form-error hidden role="alert"></p>
    <button class="btn btn-primary btn-lg btn-block" type="submit"><?= $isStart ? 'Démarrer la mission' : 'Clôturer la mission' ?></button>
  </form>
</section>
