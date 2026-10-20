<?php
/**
 * Frise (affectation, planning). Positions en % appliquées par app.js.
 * @var array $ticks graduations [gauche, largeur, libellé] (Timeline::ticks)
 * @var array $rows  lignes : ['label' => HTML déjà échappé, 'blocked' => bool,
 *                   'slot' => [gauche, largeur]|null, 'bars' => [[gauche, largeur, texte, url|null, classe, titre]]]
 * @var string $head titre de la colonne des libellés
 */
?>
<div class="table-scroll">
  <div class="timeline" data-timeline>
    <div class="tl-row tl-head">
      <div class="tl-label"><?= e($head) ?></div>
      <div class="tl-scale">
        <?php foreach ($ticks as [$left, $width, $label]): ?>
          <span data-span-left="<?= $left ?>" data-span-width="<?= $width ?>"><?= e($label) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php foreach ($rows as $row): ?>
      <div class="tl-row<?= !empty($row['blocked']) ? ' is-blocked' : '' ?>">
        <div class="tl-label"><?= $row['label'] ?></div>
        <div class="tl-track">
          <?php if (!empty($row['blocked'])): ?><span class="tl-off" aria-hidden="true"></span><?php endif; ?>
          <?php if (!empty($row['slot'])): ?><span class="tl-slot" data-span-left="<?= $row['slot'][0] ?>" data-span-width="<?= $row['slot'][1] ?>" aria-hidden="true"></span><?php endif; ?>
          <?php foreach ($row['bars'] as [$left, $width, $text, $url, $class, $tip]): ?>
            <?php if ($url): ?>
              <a class="tl-bar <?= e($class) ?>" href="<?= e($url) ?>" title="<?= e($tip) ?>" data-span-left="<?= $left ?>" data-span-width="<?= $width ?>"><?= e($text) ?></a>
            <?php else: ?>
              <span class="tl-bar <?= e($class) ?>" title="<?= e($tip) ?>" data-span-left="<?= $left ?>" data-span-width="<?= $width ?>"><?= e($text) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="tl-row"><div class="tl-label muted">Aucun élément</div><div class="tl-track"></div></div><?php endif; ?>
  </div>
</div>
