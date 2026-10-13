<?php
use App\Modules\Attendance\Services\AgentStatus;

/** @var array $agents */
/** @var array $tree */
$manage = can('manage');   // fiche, photo, département : administrateur
$editStatus = can('status'); // disponibilité : administrateur et RH
$q = mb_substr((string) ($_GET['q'] ?? ''), 0, 60);
$back = '/agents' . ($dep !== '' ? '?dep=' . rawurlencode($dep) : '');

// Arbre des départements : rendu récursif à partir de la liste à plat
$children = [];
$ids = array_column($tree['nodes'], 'id');
foreach ($tree['nodes'] as $n) {
    $parent = $n['parent_id'] !== null && in_array($n['parent_id'], $ids, true) ? $n['parent_id'] : 0;
    $children[$parent][] = $n;
}
$renderTree = static function (int $parent) use (&$renderTree, $children, $dep): string {
    $html = '';
    foreach ($children[$parent] ?? [] as $n) {
        $sub = $renderTree($n['id']);
        $active = (string) $n['id'] === $dep ? ' is-active' : '';
        $html .= '<li' . ($sub ? ' class="has-children"' : '') . '>'
            . '<div class="dept-row">'
            . ($sub ? '<button type="button" class="dept-caret" data-dept-toggle aria-expanded="true" aria-label="Replier ' . e($n['name']) . '">' . fa('fa-solid fa-caret-down') . '</button>' : '<span class="dept-caret-space"></span>')
            . '<a class="dept-link' . $active . '" href="/agents?dep=' . $n['id'] . '"' . ($active ? ' aria-current="page"' : '') . '>'
            . '<span class="dept-name">' . e($n['name']) . '</span><span class="dept-count">' . $n['count'] . '</span></a></div>'
            . ($sub ? '<ul>' . $sub . '</ul>' : '')
            . '</li>';
    }
    return $html;
};
$depName = '';
foreach ($tree['nodes'] as $n) {
    if ((string) $n['id'] === $dep) {
        $depName = $n['name'];
    }
}
?>
<div class="page-head" data-live="agents" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e($today) ?>">
  <div>
    <h1>Agents<?= $depName !== '' ? ' · ' . e($depName) : ($dep === 'aucun' ? ' · Sans département' : '') ?></h1>
    <p class="muted"><?= count($agents) ?> agent(s) · arrivées de <?= e(date_fr($today)) ?>, mises à jour en direct.</p>
  </div>
  <?php if ($manage || can('export')): ?>
  <div class="head-actions">
    <?php if (can('export')): ?>
      <a class="btn btn-primary" href="<?= e(url('/agents/export', array_filter(['dep' => $dep, 'q' => $q]))) ?>" data-agent-export
         title="Agents affichés (département, filtre et recherche en cours), au format Excel"><?= fa('fa-solid fa-file-excel') ?> Exporter en Excel</a>
    <?php endif; ?>
    <?php if ($manage): ?>
      <a class="btn btn-outline" href="/departements"><?= fa('fa-solid fa-sitemap') ?> Départements</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="agents-layout">
  <aside class="card dept-panel" aria-label="Départements">
    <p class="dept-title"><?= fa('fa-solid fa-people-group') ?> Département</p>
    <ul class="dept-tree">
      <li><div class="dept-row"><span class="dept-caret-space"></span><a class="dept-link<?= $dep === '' ? ' is-active' : '' ?>" href="/agents"><span class="dept-name">Tous</span><span class="dept-count"><?= (int) $tree['total'] ?></span></a></div></li>
      <?= $renderTree(0) ?>
      <?php if ($tree['none'] !== null && $tree['none'] > 0): ?>
      <li><div class="dept-row"><span class="dept-caret-space"></span><a class="dept-link<?= $dep === 'aucun' ? ' is-active' : '' ?>" href="/agents?dep=aucun"><span class="dept-name muted">Sans département</span><span class="dept-count"><?= (int) $tree['none'] ?></span></a></div></li>
      <?php endif; ?>
    </ul>
    <?php if (!$tree['nodes']): ?>
      <p class="muted small dept-empty"><?= $manage ? 'Aucun département. <a href="/departements">Créer les départements</a>.' : 'Aucun département attribué.' ?></p>
    <?php endif; ?>
  </aside>

  <section class="agents-main">
    <div class="agents-toolbar">
      <div class="chips" role="group" aria-label="Filtrer les agents" data-agent-filters>
        <?php foreach (AgentStatus::FILTERS as $k => $label): ?>
          <button type="button" class="chip<?= $k === 'tous' ? ' is-active' : '' ?>" data-agent-filter="<?= $k ?>" aria-pressed="<?= $k === 'tous' ? 'true' : 'false' ?>"><?= e($label) ?> <span class="chip-count" data-filter-count="<?= $k ?>"><?= (int) $counts[$k] ?></span></button>
        <?php endforeach; ?>
      </div>
      <label class="agents-search">
        <?= fa('fa-solid fa-magnifying-glass') ?>
        <input type="search" value="<?= e($q) ?>" placeholder="Nom, titre, e-mail ou téléphone" aria-label="Rechercher un agent" data-agent-search data-suggest="filter">
      </label>
    </div>

    <div class="agent-grid" data-agent-grid>
      <?php foreach ($agents as $a):
        $photo = photo_url($a);
        $w = $a['work'];
        $arr = $a['arrival'];
        $search = mb_strtolower(implode(' ', array_filter([$a['name'], $a['job_title'], $a['email'], $a['phone'], $a['department'], $a['group_name']])));
        $json = [
            'id' => (int) $a['id'], 'name' => $a['name'], 'job_title' => $a['job_title'], 'email' => $a['email'],
            'phone' => $a['phone'], 'department_id' => $a['department_id'] !== null ? (int) $a['department_id'] : null,
            'photo' => $photo, 'work_status' => $w['manual'] ? $a['work_status'] : '',
            'work_status_until' => $w['manual'] ? $a['work_status_until'] : null,
            'work_status_note' => $w['manual'] ? $a['work_status_note'] : null,
        ];
      ?>
      <article class="agent-card" data-agent="<?= (int) $a['id'] ?>" data-employee-id="<?= (int) $a['id'] ?>"
               data-search="<?= e($search) ?>" data-filters="<?= e(implode(' ', $a['filters'])) ?>"
               data-manual-status="<?= $w['manual'] ? e($a['work_status']) : '' ?>"
               <?php if ($manage || $editStatus): ?>data-agent-json="<?= e(json_encode($json, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
        <div class="agent-photo">
          <a class="agent-photo-link" href="/employes/<?= (int) $a['id'] ?>" title="Voir la fiche de <?= e($a['name']) ?>">
          <?php if ($photo): ?>
            <img src="<?= e($photo) ?>" alt="Fiche de <?= e($a['name']) ?>" loading="lazy" width="112" height="112">
          <?php else: ?>
            <span class="agent-initials"><span aria-hidden="true"><?= e(initials($a['name'])) ?></span><span class="visually-hidden">Fiche de <?= e($a['name']) ?></span></span>
          <?php endif; ?>
          </a>
          <?php if ($manage): ?>
            <button type="button" class="agent-photo-edit" data-agent-edit="photo" aria-label="Changer la photo de <?= e($a['name']) ?>"><?= fa('fa-solid fa-camera') ?></button>
          <?php endif; ?>
        </div>
        <div class="agent-body">
          <div class="agent-head">
            <h2 class="agent-name"><?= e($a['name']) ?></h2>
            <?php if ($editStatus): ?>
              <button type="button" class="agent-status status-<?= e($w['variant']) ?>" data-agent-status data-agent-edit="status" title="<?= e($w['title']) ?> · cliquer pour modifier" aria-label="<?= e($w['label']) ?>, modifier le statut"><?= fa($w['icon']) ?></button>
            <?php else: ?>
              <span class="agent-status status-<?= e($w['variant']) ?>" data-agent-status title="<?= e($w['title']) ?>" role="img" aria-label="<?= e($w['label']) ?>"><?= fa($w['icon']) ?></span>
            <?php endif; ?>
          </div>
          <p class="agent-title"><?= $a['job_title'] ? e($a['job_title']) : '<span class="muted">Titre non renseigné</span>' ?></p>
          <ul class="agent-contacts">
            <?php if ($a['email']): ?>
              <li><?= fa('fa-solid fa-envelope') ?><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a>
                <button type="button" class="copy-btn" data-copy="<?= e($a['email']) ?>" aria-label="Copier l'adresse e-mail" title="Copier"><?= fa('fa-regular fa-copy') ?></button></li>
            <?php endif; ?>
            <?php if ($a['phone']): ?>
              <li><?= fa('fa-solid fa-phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $a['phone'])) ?>"><?= e($a['phone']) ?></a>
                <button type="button" class="copy-btn" data-copy="<?= e($a['phone']) ?>" aria-label="Copier le numéro" title="Copier"><?= fa('fa-regular fa-copy') ?></button></li>
            <?php endif; ?>
          </ul>
          <div class="agent-foot">
            <div class="agent-tags">
              <?php if ($a['department']): ?><span class="pill pill-dept" title="<?= e($a['department']) ?>"><?= e($a['department_name']) ?></span><?php endif; ?>
              <?php if ($a['group_name']): ?><span class="pill pill-group"><?= e($a['group_name']) ?></span><?php endif; ?>
            </div>
            <span class="agent-arrival arrival-<?= e($arr['kind']) ?>" data-agent-arrival title="<?= e($arr['title']) ?>">
              <?= fa($arr['kind'] === 'late' ? 'fa-solid fa-clock' : ($arr['kind'] === 'ontime' ? 'fa-solid fa-circle-check' : 'fa-regular fa-clock')) ?>
              <span data-arrival-label><?= e($arr['label']) ?></span><?php if ($arr['kind'] === 'late'): ?><small data-arrival-late>+<?= (int) $a['late_minutes'] ?> min</small><?php endif; ?>
            </span>
          </div>
        </div>
        <?php if ($manage): ?>
          <button type="button" class="agent-edit" data-agent-edit="profile" aria-label="Modifier la fiche de <?= e($a['name']) ?>" title="Modifier"><?= fa('fa-solid fa-pen') ?></button>
        <?php elseif ($editStatus): ?>
          <button type="button" class="agent-edit" data-agent-edit="status" aria-label="Modifier la disponibilité de <?= e($a['name']) ?>" title="Modifier la disponibilité"><?= fa('fa-solid fa-pen') ?></button>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="empty-state" data-agents-empty<?= $agents ? ' hidden' : '' ?>>
      <?= fa('fa-solid fa-user-slash', 'empty-icon') ?>
      <p class="muted"><?= $agents ? 'Aucun agent ne correspond à ces critères.' : 'Aucun agent dans ce périmètre.' ?></p>
    </div>
  </section>
</div>

<?php if ($manage): ?>
<dialog id="agent-dialog" class="dialog dialog-wide" data-agent-dialog>
  <form method="post" class="stack" data-agent-form data-action="/agents/{id}">
    <?= csrf_field() ?>
    <input type="hidden" name="_back" value="<?= e($back) ?>">
    <input type="hidden" name="photo_data" data-crop-output>
    <h2>Modifier la fiche</h2>
    <div class="agent-edit-grid">
      <?php require __DIR__ . '/_photo_cropper.php'; ?>
      <div class="stack">
        <label class="field"><span>Nom affiché</span><input name="name" maxlength="120" required data-field="name"></label>
        <label class="field"><span>Titre / poste</span><input name="job_title" maxlength="120" placeholder="ex. Ingénieur réseau" data-field="job_title"></label>
        <label class="field"><span>E-mail</span><input type="email" name="email" maxlength="150" data-field="email"></label>
        <label class="field"><span>Téléphone</span><input type="tel" name="phone" maxlength="40" placeholder="+243 …" data-field="phone"></label>
        <label class="field"><span>Département</span>
          <select name="department_id" data-field="department_id">
            <option value="0">Aucun</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
          </select></label>
        <div>
          <label class="check"><input type="checkbox" name="active" value="1" checked data-agent-active> Agent actif</label>
          <p class="hint">Décocher pour désactiver : l'agent et ses badges disparaissent des pages, de la cloche et du calendrier ; l'historique est conservé.</p>
        </div>
      </div>
    </div>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit"><?= fa('fa-solid fa-check') ?> Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($editStatus): ?>
<dialog id="status-dialog" class="dialog" data-status-dialog>
  <form method="post" class="stack" data-status-form data-action="/agents/{id}/statut">
    <?= csrf_field() ?>
    <input type="hidden" name="_back" value="<?= e($back) ?>">
    <h2>Disponibilité de <span data-status-name></span></h2>
    <p class="muted small">« Au bureau » s'affiche automatiquement au premier badge d'entrée. Un statut saisi ici le remplace jusqu'à la date de fin.</p>
    <div class="status-options">
      <label class="status-option"><input type="radio" name="work_status" value=""><span><?= fa('fa-solid fa-wand-magic-sparkles') ?> Automatique <small>au bureau dès le badge</small></span></label>
      <?php foreach (AgentStatus::WORK as $k => [$label, $icon, $variant]): if ($k === 'none') continue; ?>
        <label class="status-option"><input type="radio" name="work_status" value="<?= $k ?>"><span><i class="<?= e($icon) ?> status-<?= e($variant) ?>" aria-hidden="true"></i> <?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="grid-2 gap-sm" data-status-extra>
      <label class="field"><span>Jusqu'au (inclus)</span><input type="date" name="work_status_until" min="<?= e($today) ?>" data-field="work_status_until"><small class="hint">Vide : jusqu'à nouvel ordre.</small></label>
      <label class="field"><span>Note</span><input name="work_status_note" maxlength="255" placeholder="ex. Mission à Lubumbashi" data-field="work_status_note"></label>
    </div>
    <div class="dialog-actions">
      <button class="btn btn-ghost" type="button" data-close>Annuler</button>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
