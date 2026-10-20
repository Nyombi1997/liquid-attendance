<?php
/**
 * Champs d'un véhicule (création et modification).
 * @var array|null $v véhicule modifié, null pour un nouveau
 */
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetWorkflow;

$val = static fn(string $k, mixed $d = '') => $v !== null ? ($v[$k] ?? '') : old($k, $d);
$opts = static function (array $list, string $selected): string {
    $html = '';
    foreach ($list as $k => $label) {
        $label = is_array($label) ? $label[0] : $label;
        $html .= '<option value="' . e($k) . '"' . ((string) $selected === (string) $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
};
?>
<div class="form-grid">
  <label class="field"><span>Immatriculation</span><input name="plate" value="<?= e($val('plate')) ?>" maxlength="20" required autocapitalize="characters"></label>
  <label class="field"><span>Marque</span><input name="brand" value="<?= e($val('brand')) ?>" maxlength="60" required></label>
  <label class="field"><span>Modèle</span><input name="model" value="<?= e($val('model')) ?>" maxlength="60" required></label>
  <label class="field"><span>Année</span><input type="number" name="year" value="<?= e($val('year')) ?>" min="1950" max="<?= (int) date('Y') + 1 ?>"></label>
  <label class="field"><span>Type</span><select name="vehicle_type"><?= $opts(FleetWorkflow::VEHICLE_TYPES, (string) $val('vehicle_type', 'berline')) ?></select></label>
  <label class="field"><span>Places (chauffeur compris)</span><input type="number" name="seats" value="<?= e($val('seats', 5)) ?>" min="1" max="80" required></label>
  <label class="field"><span>Carburant</span><select name="fuel_type"><?= $opts(Fleet::FUEL_TYPES, (string) $val('fuel_type', 'diesel')) ?></select></label>
  <label class="field"><span>Réservoir (litres)</span><input type="number" name="tank_liters" value="<?= e($val('tank_liters')) ?>" min="0" max="2000" step="0.1"></label>
  <label class="field"><span>Consommation de référence (L/100 km)</span><input type="number" name="ref_consumption" value="<?= e($val('ref_consumption')) ?>" min="0" max="100" step="0.01"></label>
  <label class="field"><span>Kilométrage actuel</span><input type="number" name="odometer_km" value="<?= e($val('odometer_km')) ?>" min="0" step="1"></label>
  <label class="field"><span>État</span><select name="status"><?= $opts(Fleet::VEHICLE_STATUSES, (string) $val('status', 'disponible')) ?></select></label>
  <label class="field"><span>Tracker GPS (identifiant)</span><input name="tracker_id" value="<?= e($val('tracker_id')) ?>" maxlength="60"><small class="hint">Pour le suivi GPS (module 2).</small></label>
</div>
<label class="field"><span>Remarques</span><input name="notes" value="<?= e($val('notes')) ?>" maxlength="255"></label>
<?php if ($v !== null): ?>
  <label class="check"><input type="checkbox" name="active" value="1" <?= $v['active'] ? 'checked' : '' ?>> Véhicule dans le parc (décocher pour le retirer)</label>
<?php endif; ?>
