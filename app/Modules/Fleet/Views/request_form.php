<?php
use App\Modules\Fleet\Services\FleetFiles;
use App\Modules\Fleet\Services\FleetWorkflow;

$s = $settings;
$rules = array_filter([
    $s['fleet_direction_out_of_town'] === '1' ? 'mission hors de ' . $s['fleet_city'] : null,
    (int) $s['fleet_direction_max_days'] > 0 ? 'plus de ' . (int) $s['fleet_direction_max_days'] . ' jours' : null,
    $s['fleet_direction_weekend'] === '1' ? 'week-end' : null,
]);
$orderHint = match ($s['fleet_order_required']) {
    'toujours'   => 'Obligatoire.',
    'hors_ville' => 'Obligatoire pour une mission hors de ' . $s['fleet_city'] . '.',
    default      => 'Facultatif.',
};
$withDriver = (string) old('with_driver', '1');
// Valeur par défaut : demain 08:00 → 17:00
$tomorrow = date('Y-m-d', strtotime('+1 day'));
?>
<div class="page-head">
  <div>
    <p class="muted"><a href="/flotte/demandes"><?= fa('fa-solid fa-arrow-left') ?> Mes demandes</a></p>
    <h1>Nouvelle demande de véhicule</h1>
  </div>
</div>

<?php if ($employee === null): ?>
  <div class="callout callout-warn"><?= fa('fa-solid fa-circle-info') ?> Votre compte n'est relié à aucune fiche employé : demandez à l'administrateur de le relier (Utilisateurs) pour pouvoir demander un véhicule.</div>
<?php else: ?>
<section class="card card-narrow">
  <form method="post" action="/flotte/demandes" enctype="multipart/form-data" class="stack" data-request-form>
    <?= csrf_field() ?>
    <p class="callout"><?= fa('fa-solid fa-route') ?>
      Validation par <?= $n1 ? '<strong>' . e($n1['name']) . '</strong> (N+1)' . ($n1['user_id'] ? '' : ' — <em>sans compte : la demande ira à la direction</em>') : 'la direction (aucun N+1 désigné pour votre service)' ?><?php if ($rules): ?>,
      puis par la direction si : <?= e(implode(', ', $rules)) ?><?php endif; ?>.</p>

    <label class="field"><span>Motif de la mission</span><input name="purpose" value="<?= e(old('purpose')) ?>" maxlength="255" required placeholder="Ex. : réunion avec le client, livraison de matériel…"></label>
    <label class="field"><span>Destination</span><input name="destination" value="<?= e(old('destination')) ?>" maxlength="160" required placeholder="Adresse, quartier ou ville"></label>
    <label class="check"><input type="checkbox" name="out_of_town" value="1" <?= old('out_of_town') ? 'checked' : '' ?>> Mission hors de <?= e($s['fleet_city']) ?></label>
    <div class="form-grid">
      <label class="field"><span>Départ</span><input type="datetime-local" name="depart_at" value="<?= e(old('depart_at', $tomorrow . 'T08:00')) ?>" required></label>
      <label class="field"><span>Retour</span><input type="datetime-local" name="return_at" value="<?= e(old('return_at', $tomorrow . 'T17:00')) ?>" required></label>
      <label class="field"><span>Passagers (vous compris)</span><input type="number" name="passengers" value="<?= e(old('passengers', 1)) ?>" min="1" max="60" required></label>
      <label class="field"><span>Type de véhicule souhaité</span><select name="vehicle_type">
        <option value="">Indifférent</option>
        <?php foreach (FleetWorkflow::VEHICLE_TYPES as $k => $label): ?><option value="<?= e($k) ?>" <?= old('vehicle_type') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
    </div>
    <fieldset class="field"><legend>Chauffeur</legend>
      <div class="segmented segmented-block">
        <label><input type="radio" name="with_driver" value="1" <?= $withDriver !== '0' ? 'checked' : '' ?> data-toggle-target="preferred-driver" data-toggle-show="1"><span>Avec chauffeur</span></label>
        <label><input type="radio" name="with_driver" value="0" <?= $withDriver === '0' ? 'checked' : '' ?> data-toggle-target="preferred-driver" data-toggle-show="0"><span>Je conduis</span></label>
      </div>
    </fieldset>
    <label class="field" id="preferred-driver"><span>Chauffeur souhaité</span><select name="preferred_driver_id">
      <option value="0">Pas de préférence</option>
      <?php foreach ($drivers as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) old('preferred_driver_id', 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select><small class="hint">Le gestionnaire de flotte en tient compte selon les disponibilités.</small></label>
    <label class="field"><span>Ordre de mission (PDF ou image, 5 Mo maximum)</span>
      <input type="file" name="order_file" accept="application/pdf,image/jpeg,image/png,image/webp" data-max-bytes="<?= FleetFiles::MAX_BYTES ?>">
      <small class="hint"><?= e($orderHint) ?></small></label>
    <label class="check"><input type="checkbox" name="urgent" value="1" <?= old('urgent') ? 'checked' : '' ?> data-toggle-target="urgent-reason"> Demande urgente</label>
    <label class="field" id="urgent-reason"><span>Raison de l'urgence</span><input name="urgent_reason" value="<?= e(old('urgent_reason')) ?>" maxlength="255">
      <small class="hint">Le gestionnaire peut affecter un véhicule avant la fin de la validation ; la validation se fait ensuite (a posteriori), et elle est tracée.</small></label>
    <div class="dialog-actions">
      <a class="btn btn-ghost" href="/flotte/demandes">Annuler</a>
      <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-paper-plane') ?> Envoyer la demande</button>
    </div>
  </form>
</section>
<?php endif; ?>
