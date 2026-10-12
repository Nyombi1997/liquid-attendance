<?php
/** @var array $tree */
$options = static function (?int $selected, ?int $exclude = null) use ($tree): string {
    $html = '<option value="0">Aucun (département principal)</option>';
    $skip = $exclude !== null ? App\Modules\Platform\Services\Departments::descendants($exclude) : [];
    foreach ($tree as $d) {
        if (in_array($d['id'], $skip, true)) {
            continue;
        }
        $html .= '<option value="' . $d['id'] . '"' . ($selected === $d['id'] ? ' selected' : '') . '>'
            . str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) . '</option>';
    }
    return $html;
};
?>
<div class="page-head">
  <div>
    <h1>Départements</h1>
    <p class="muted">Organisation des agents. Un manager voit les agents de son département et de ses sous-départements. <?= $none ?> agent(s) actif(s) sans département.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-outline" href="/agents"><?= fa('fa-solid fa-id-card') ?> Voir les agents</a>
    <button class="btn btn-primary" type="button" data-dialog="new-dept"><?= icon('plus') ?> Nouveau département</button>
  </div>
</div>

<section class="card table-card">
  <table class="table">
    <thead><tr><th>Département</th><th class="num">Agents</th><th>Managers</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tree as $d): ?>
      <tr>
        <td><span class="tree-indent depth-<?= min(5, $d['depth']) ?>"><?= fa($d['depth'] ? 'fa-solid fa-turn-up fa-rotate-90 muted' : 'fa-solid fa-sitemap muted') ?> <strong><?= e($d['name']) ?></strong></span></td>
        <td class="num"><a href="/agents?dep=<?= $d['id'] ?>"><?= $agents[$d['id']] ?? 0 ?></a></td>
        <td><?= isset($managers[$d['id']]) ? e(implode(', ', $managers[$d['id']])) : '<span class="muted">—</span>' ?></td>
        <td class="actions">
          <button class="btn btn-ghost btn-sm" type="button" data-dialog="dept-<?= $d['id'] ?>">Modifier</button>
          <form method="post" action="/departements/<?= $d['id'] ?>/supprimer" data-confirm="Supprimer « <?= e($d['name']) ?> » ? Ses sous-départements remontent d'un niveau, ses agents et managers n'auront plus de département.">
            <?= csrf_field() ?><button class="btn btn-danger-ghost btn-sm" type="submit">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tree): ?><tr><td colspan="4" class="muted center">Aucun département. Commencez par « Nouveau département ».</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<dialog id="new-dept" class="dialog">
  <form method="post" action="/departements" class="stack">
    <?= csrf_field() ?>
    <h2>Nouveau département</h2>
    <label class="field"><span>Nom</span><input name="name" maxlength="100" required></label>
    <label class="field"><span>Rattaché à</span><select name="parent_id"><?= $options(null) ?></select></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Créer</button>
    </div>
  </form>
</dialog>

<?php foreach ($tree as $d): ?>
<dialog id="dept-<?= $d['id'] ?>" class="dialog">
  <form method="post" action="/departements/<?= $d['id'] ?>" class="stack">
    <?= csrf_field() ?>
    <h2><?= e($d['name']) ?></h2>
    <label class="field"><span>Nom</span><input name="name" value="<?= e($d['name']) ?>" maxlength="100" required></label>
    <label class="field"><span>Rattaché à</span><select name="parent_id"><?= $options($d['parent_id'], $d['id']) ?></select></label>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>
