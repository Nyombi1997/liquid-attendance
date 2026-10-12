<?php
/**
 * Recadrage de la photo d'un agent (createCropper dans app.js), à inclure dans
 * un formulaire POST qui contient aussi <input type="hidden" name="photo_data" data-crop-output>.
 * $cropperPhoto : URL de la photo actuelle (null = aucune) ; si la variable est
 * définie, le recadrage s'initialise seul (fiche employé). Sinon la page Agents
 * l'initialise à l'ouverture de sa boîte de dialogue.
 */
$standalone = array_key_exists('cropperPhoto', get_defined_vars());
?>
<div class="cropper" data-cropper<?php if ($standalone): ?> data-cropper-standalone data-cropper-photo="<?= e($cropperPhoto ?? '') ?>"<?php endif; ?>>
  <div class="crop-stage">
    <canvas class="crop-canvas" width="240" height="240" tabindex="0" aria-label="Recadrage : faire glisser pour déplacer, molette ou curseur pour zoomer, flèches du clavier pour ajuster" data-crop-canvas></canvas>
    <img class="crop-current" alt="" data-crop-current hidden>
    <span class="crop-placeholder" data-crop-placeholder><?= fa('fa-solid fa-user') ?></span>
  </div>
  <label class="field crop-zoom" data-crop-zoom-field hidden><span><?= fa('fa-solid fa-magnifying-glass-plus') ?> Zoom</span>
    <input type="range" min="1" max="4" step="0.01" value="1" data-crop-zoom></label>
  <div class="crop-actions">
    <label class="btn btn-outline btn-sm"><?= fa('fa-solid fa-upload') ?> Choisir une photo
      <input type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-crop-file></label>
    <label class="check small" data-photo-remove-field hidden><input type="checkbox" name="photo_remove" value="1" data-photo-remove> Retirer la photo</label>
  </div>
  <p class="hint" data-crop-hint>JPEG, PNG ou WebP. Recadrée en carré dans le navigateur.</p>
</div>
