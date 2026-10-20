<?php
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetFiles;
use App\Modules\Fleet\Services\FleetWorkflow;

$r = $req;
$refused = $r['validation'] === 'refusee';
$cancelled = $r['status'] === 'annulee';
$started = in_array($r['status'], ['en_cours', 'terminee'], true);
$assigned = in_array($r['status'], ['affectee', 'en_cours', 'terminee'], true) || ($refused && $r['assigned_at'] && $started);
$on = static fn(?string $at, ?string $who = null) => $at ? Fleet::when($at) . ($who ? ' · ' . $who : '') : null;

// Étapes du circuit : créée → N+1 → direction (si requise) → affectation → mission → clôture
$steps = [['Demande créée', 'done', $on($r['created_at'], $r['requester_name'])]];
$n1State = match (true) {
    $r['n1_user_id'] === null && $r['n1_decided_at'] === null => 'skipped',
    $r['n1_decided_at'] !== null => $refused && $r['direction_decided_at'] === null ? 'refused' : 'done',
    $r['step'] === 'n1' && !$cancelled => 'current',
    default => 'todo',
};
$steps[] = ['Validation N+1', $n1State, $n1State === 'skipped' ? 'aucun N+1' : ($on($r['n1_decided_at'], $decider($r['n1_decided_by'])) ?? ($r['n1_name'] ? 'par ' . $r['n1_name'] : null))];
if ($r['direction_reasons']) {
    $dState = match (true) {
        $r['direction_decided_at'] !== null => $refused ? 'refused' : 'done',
        $r['step'] === 'direction' && !$cancelled => 'current',
        default => 'todo',
    };
    $steps[] = ['Validation direction', $dState, $on($r['direction_decided_at'], $decider($r['direction_decided_by'])) ?? $r['direction_reasons']];
}
$steps[] = ['Affectation', $assigned && $r['assigned_at'] ? 'done' : ($r['status'] === 'approuvee' || ($r['status'] === 'en_validation' && (int) $r['urgent']) ? 'current' : 'todo'),
    $r['assigned_at'] ? $on($r['assigned_at']) : null];
$steps[] = ['Mission', $mission ? ($mission['ended_at'] ? 'done' : 'current') : 'todo', $mission ? $on($mission['started_at']) : null];
$steps[] = ['Clôture', $mission && $mission['ended_at'] ? 'done' : 'todo', $mission && $mission['ended_at'] ? $on($mission['ended_at']) : null];
$stars = static fn(int $n) => '<span class="rating-view" aria-label="' . $n . ' sur 5">' . str_repeat('★', $n) . str_repeat('☆', 5 - $n) . '</span>';
$km = static fn(?int $v) => $v !== null ? number_format($v, 0, ',', ' ') . ' km' : '—';
?>
<div class="page-head">
  <div>
    <p class="muted"><a href="<?= $access['requester'] ? '/flotte/demandes' : (can('fleet_board') ? '/flotte/registre' : '/flotte/validations') ?>"><?= fa('fa-solid fa-arrow-left') ?> <?= $access['requester'] ? 'Mes demandes' : (can('fleet_board') ? 'Registre des demandes' : 'Demandes à valider') ?></a></p>
    <h1><?= e($r['reference']) ?> · <?= e($r['destination']) ?> <?= Fleet::badge($r) ?>
      <?= (int) $r['urgent'] ? '<span class="badge badge-danger">' . fa('fa-solid fa-bolt') . ' Urgent</span>' : '' ?></h1>
  </div>
  <div class="head-actions">
    <?php if ($access['manage'] && FleetWorkflow::canAssign($r)): ?>
      <a class="btn btn-primary" href="/flotte/demandes/<?= (int) $r['id'] ?>/affecter"><?= fa('fa-solid fa-car-side') ?> <?= $r['status'] === 'affectee' ? 'Modifier l\'affectation' : 'Affecter' ?></a>
    <?php endif; ?>
    <?php if ($access['driver'] && (FleetWorkflow::canStart($r) || FleetWorkflow::canClose($r))): ?>
      <a class="btn btn-primary" href="/flotte/missions/<?= (int) $r['id'] ?>"><?= fa('fa-solid fa-gauge-high') ?> <?= FleetWorkflow::canStart($r) ? 'Démarrer la mission' : 'Clôturer la mission' ?></a>
    <?php endif; ?>
  </div>
</div>

<ol class="steps" aria-label="Étapes de la demande">
  <?php foreach ($steps as [$label, $state, $detail]): ?>
    <li class="step is-<?= e($state) ?>"><strong><?= e($label) ?></strong><?php if ($state === 'refused'): ?><span>Refusée</span><?php endif; ?><?php if ($detail): ?><span><?= e($detail) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
</ol>

<?php if ($refused): ?>
  <div class="callout callout-danger"><?= fa('fa-solid fa-ban') ?> <strong>Refusée<?= $started ? ' a posteriori' : '' ?></strong> le <?= e(date('d/m/Y à H:i', strtotime($r['refused_at']))) ?> par <?= e($decider($r['refused_by']) ?? '—') ?> · Motif : <?= e($r['refusal_reason']) ?></div>
<?php elseif ($cancelled): ?>
  <div class="callout"><?= fa('fa-solid fa-xmark') ?> Annulée le <?= e(date('d/m/Y à H:i', strtotime($r['cancelled_at']))) ?><?= $r['cancel_reason'] ? ' · ' . e($r['cancel_reason']) : '' ?></div>
<?php elseif ((int) $r['assigned_before_validation'] && $r['validation'] === 'en_attente'): ?>
  <div class="callout callout-warn"><?= fa('fa-solid fa-bolt') ?> Urgence : véhicule affecté avant la fin de la validation. Validation a posteriori attendue (<?= e(FleetWorkflow::STEPS[$r['step']] ?? '') ?>).</div>
<?php endif; ?>

<?php if ($access['validate']): ?>
<section class="card">
  <h2><?= fa('fa-solid fa-stamp') ?> <?= e(FleetWorkflow::STEPS[$access['validate']]) ?></h2>
  <p class="muted"><?= $access['validate'] === 'n1' && $r['direction_reasons'] ? 'Après votre accord, la demande ira à la direction (' . e($r['direction_reasons']) . ').' : 'Votre accord termine la validation.' ?></p>
  <div class="card-actions">
    <form method="post" action="/flotte/demandes/<?= (int) $r['id'] ?>/valider" class="inline-form">
      <?= csrf_field() ?>
      <input name="reason" maxlength="500" placeholder="Commentaire (facultatif)">
      <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-check') ?> Approuver</button>
    </form>
    <button class="btn btn-danger-ghost" type="button" data-dialog="refuse-dialog"><?= fa('fa-solid fa-xmark') ?> Refuser</button>
  </div>
</section>
<dialog id="refuse-dialog" class="dialog">
  <form method="post" action="/flotte/demandes/<?= (int) $r['id'] ?>/refuser" class="stack">
    <?= csrf_field() ?>
    <h2>Refuser la demande <?= e($r['reference']) ?></h2>
    <label class="field"><span>Motif du refus (obligatoire, envoyé au demandeur)</span><textarea name="reason" rows="3" maxlength="500" minlength="3" required></textarea></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Refuser</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<div class="grid-2">
  <section class="card">
    <h2>Demande</h2>
    <dl class="facts facts-tight">
      <dt>Demandeur</dt><dd><?= e($r['requester_name']) ?><?= $r['department_name'] ? ' <span class="muted">· ' . e($r['department_name']) . '</span>' : '' ?><?= $r['requester_phone'] ? '<small class="block muted">' . e($r['requester_phone']) . '</small>' : '' ?></dd>
      <dt>Motif</dt><dd><?= e($r['purpose']) ?></dd>
      <dt>Destination</dt><dd><?= e($r['destination']) ?><?= (int) $r['out_of_town'] ? ' <span class="tag">hors ville</span>' : '' ?></dd>
      <dt>Départ</dt><dd><?= e(date_fr(substr($r['depart_at'], 0, 10))) ?> à <?= e(substr($r['depart_at'], 11, 5)) ?></dd>
      <dt>Retour</dt><dd><?= e(date_fr(substr($r['return_at'], 0, 10))) ?> à <?= e(substr($r['return_at'], 11, 5)) ?></dd>
      <dt>Passagers</dt><dd><?= (int) $r['passengers'] ?></dd>
      <dt>Chauffeur</dt><dd><?= (int) $r['with_driver'] ? 'Avec chauffeur' . ($r['preferred_driver_name'] ? ' (souhaité : ' . e($r['preferred_driver_name']) . ')' : '') : 'Le demandeur conduit' ?></dd>
      <dt>Véhicule souhaité</dt><dd><?= e(FleetWorkflow::VEHICLE_TYPES[$r['vehicle_type']] ?? 'Indifférent') ?></dd>
      <?php if ((int) $r['urgent']): ?><dt>Urgence</dt><dd><?= e($r['urgent_reason']) ?></dd><?php endif; ?>
      <dt>Ordre de mission</dt><dd><?= $r['order_stored_name'] ? '<a href="/flotte/demandes/' . (int) $r['id'] . '/ordre">' . fa($r['order_mime'] === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-file-image') . ' ' . e($r['order_file_name']) . '</a> <small class="muted">' . e(FleetFiles::sizeLabel((int) $r['order_size'])) . '</small>' : '<span class="muted">aucun</span>' ?></dd>
    </dl>
  </section>

  <div class="stack">
    <section class="card">
      <h2>Affectation</h2>
      <?php if ($r['vehicle_id']): ?>
        <dl class="facts facts-tight">
          <dt>Véhicule</dt><dd><span class="plate"><?= e($r['plate']) ?></span> <?= e($r['brand'] . ' ' . $r['model']) ?></dd>
          <dt>Chauffeur</dt><dd><?= $r['driver_name'] ? e($r['driver_name']) . ($r['driver_phone'] ? ' · <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $r['driver_phone'])) . '">' . e($r['driver_phone']) . '</a>' : '') : 'Le demandeur conduit' ?></dd>
          <dt>Affectée</dt><dd><?= e(Fleet::when($r['assigned_at'])) ?><?= (int) $r['assigned_before_validation'] ? ' <span class="tag">avant validation</span>' : '' ?></dd>
        </dl>
      <?php else: ?>
        <p class="muted"><?= $r['status'] === 'approuvee' ? 'Validée : en attente d\'un véhicule et d\'un chauffeur.' : 'Pas encore de véhicule.' ?></p>
      <?php endif; ?>
    </section>
    <?php if ($mission): ?>
    <section class="card">
      <h2>Mission</h2>
      <dl class="facts facts-tight">
        <dt>Départ</dt><dd><?= e(Fleet::when($mission['started_at'])) ?> · <?= e($km((int) $mission['start_km'])) ?> · carburant <?= e(Fleet::FUEL_LEVELS[(int) $mission['start_fuel']] ?? $mission['start_fuel'] . ' %') ?>
          <small class="block muted"><?= e(Fleet::CONDITIONS[$mission['start_condition']] ?? '') ?><?= $mission['start_notes'] ? ' · ' . e($mission['start_notes']) : '' ?><?= $mission['start_photo'] ? ' · <a href="/flotte/missions/' . (int) $r['id'] . '/photo-depart">photo du compteur</a>' : '' ?></small></dd>
        <?php if ($mission['ended_at']): ?>
        <dt>Retour</dt><dd><?= e(Fleet::when($mission['ended_at'])) ?> · <?= e($km((int) $mission['end_km'])) ?> · carburant <?= e(Fleet::FUEL_LEVELS[(int) $mission['end_fuel']] ?? $mission['end_fuel'] . ' %') ?>
          <small class="block muted"><?= e(Fleet::CONDITIONS[$mission['end_condition']] ?? '') ?><?= $mission['end_notes'] ? ' · ' . e($mission['end_notes']) : '' ?><?= $mission['end_photo'] ? ' · <a href="/flotte/missions/' . (int) $r['id'] . '/photo-retour">photo du compteur</a>' : '' ?></small></dd>
        <dt>Distance</dt><dd><strong><?= e($km((int) $mission['end_km'] - (int) $mission['start_km'])) ?></strong></dd>
        <?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>
    <?php if ($rating): ?>
    <section class="card">
      <h2>Évaluation de la course</h2>
      <dl class="facts facts-tight">
        <dt>Ponctualité</dt><dd><?= $stars((int) $rating['punctuality']) ?></dd>
        <dt>Conduite</dt><dd><?= $stars((int) $rating['driving']) ?></dd>
        <?php if ($rating['comment']): ?><dt>Commentaire</dt><dd><?= e($rating['comment']) ?></dd><?php endif; ?>
      </dl>
    </section>
    <?php elseif ($access['requester'] && FleetWorkflow::canRate($r)): ?>
    <section class="card">
      <h2><?= fa('fa-regular fa-star') ?> Évaluer la course</h2>
      <form method="post" action="/flotte/demandes/<?= (int) $r['id'] ?>/evaluer" class="stack">
        <?= csrf_field() ?>
        <?php foreach (['punctuality' => 'Ponctualité', 'driving' => 'Conduite'] as $field => $label): ?>
          <fieldset class="field"><legend><?= e($label) ?></legend>
            <div class="stars">
              <?php for ($n = 5; $n >= 1; $n--): ?>
                <input type="radio" id="<?= $field . $n ?>" name="<?= $field ?>" value="<?= $n ?>" required><label for="<?= $field . $n ?>" title="<?= $n ?> sur 5">★</label>
              <?php endfor; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
        <label class="field"><span>Commentaire (facultatif)</span><textarea name="comment" rows="2" maxlength="500"></textarea></label>
        <div><button class="btn btn-primary" type="submit">Envoyer l'évaluation</button></div>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2><?= fa('fa-solid fa-clock-rotate-left') ?> Historique</h2>
    <?php if ($access['requester'] && FleetWorkflow::canCancel($r)): ?>
      <button class="btn btn-danger-ghost btn-sm" type="button" data-dialog="cancel-dialog"><?= fa('fa-solid fa-xmark') ?> Annuler la demande</button>
    <?php endif; ?>
  </div>
  <ol class="history">
    <?php foreach ($events as $ev): ?>
      <li><time datetime="<?= e(str_replace(' ', 'T', $ev['created_at'])) ?>"><?= e(date('d/m/Y H:i', strtotime($ev['created_at']))) ?></time>
        <span><strong><?= e(Fleet::EVENTS[$ev['action']] ?? $ev['action']) ?></strong> <span class="muted">· <?= e($ev['full_name'] ?? 'Système') ?></span>
          <?php if ($ev['details']): ?><small><?= e($ev['details']) ?></small><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>

<?php if ($access['requester'] && FleetWorkflow::canCancel($r)): ?>
<dialog id="cancel-dialog" class="dialog">
  <form method="post" action="/flotte/demandes/<?= (int) $r['id'] ?>/annuler" class="stack">
    <?= csrf_field() ?>
    <h2>Annuler la demande <?= e($r['reference']) ?> ?</h2>
    <?php if ($r['vehicle_id']): ?><p class="muted">Le véhicule <?= e($r['plate']) ?> sera libéré et le gestionnaire prévenu.</p><?php endif; ?>
    <label class="field"><span>Raison (facultatif)</span><input name="reason" maxlength="255"></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Retour</button>
      <button class="btn btn-primary" type="submit">Annuler la demande</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
