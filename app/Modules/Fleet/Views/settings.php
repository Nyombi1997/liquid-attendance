<div class="page-head">
  <div>
    <h1>Paramètres du parc automobile</h1>
    <p class="muted">Circuit de validation des demandes, ordre de mission, rappels et alertes. Les seuils s'appliquent aux nouvelles demandes.</p>
  </div>
</div>

<section class="card card-narrow">
  <form method="post" action="/flotte/parametres" class="stack">
    <?= csrf_field() ?>
    <h2>Validation de la direction</h2>
    <p class="muted small">Toute demande est validée par le N+1 du demandeur. La direction valide en plus quand l'une des conditions cochées est remplie.</p>
    <label class="field"><span>Ville de référence</span><input name="fleet_city" value="<?= e($s['fleet_city']) ?>" maxlength="60" required></label>
    <label class="check"><input type="checkbox" name="fleet_direction_out_of_town" value="1" <?= $s['fleet_direction_out_of_town'] === '1' ? 'checked' : '' ?>> Mission hors de la ville de référence</label>
    <label class="check"><input type="checkbox" name="fleet_direction_weekend" value="1" <?= $s['fleet_direction_weekend'] === '1' ? 'checked' : '' ?>> Mission qui touche un samedi ou un dimanche</label>
    <label class="field"><span>Mission de plus de … jours (0 : pas de seuil)</span><input type="number" name="fleet_direction_max_days" value="<?= e($s['fleet_direction_max_days']) ?>" min="0" max="31" required></label>

    <h2>Ordre de mission</h2>
    <label class="field"><span>Pièce jointe obligatoire</span><select name="fleet_order_required">
      <?php foreach (['hors_ville' => 'Pour les missions hors ville', 'toujours' => 'Pour toutes les demandes', 'jamais' => 'Jamais (facultatif)'] as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $s['fleet_order_required'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select></label>

    <h2>Rappels et alertes</h2>
    <label class="field"><span>Rappel avant le départ (minutes, 0 : aucun)</span><input type="number" name="fleet_reminder_minutes" value="<?= e($s['fleet_reminder_minutes']) ?>" min="0" max="1440" required></label>
    <label class="field"><span>Alerte avant l'expiration des documents et des permis (jours)</span><input type="number" name="fleet_doc_alert_days" value="<?= e($s['fleet_doc_alert_days']) ?>" min="1" max="365" required></label>
    <p class="muted small">E-mails : envoyés par le worker si le serveur SMTP est configuré dans le fichier .env de la VM (SMTP_HOST, SMTP_FROM…) ; sinon, notifications dans l'application seulement.</p>
    <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>
