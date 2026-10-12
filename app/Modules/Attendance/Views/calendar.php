<?php
use App\Modules\Attendance\Services\Calendar;

/** @var array $rows */
/** @var string[] $dates */
$isDay = $view === 'jour';
$link = static fn(array $over) => url('/calendrier', array_filter(array_merge(
    ['vue' => $view, 'date' => $date, 'dep' => $dep ?: null, 'q' => $q !== '' ? $q : null], $over
)));
$mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$period = match ($view) {
    'jour'    => ucfirst(date_fr($date)),
    'semaine' => 'Semaine du ' . date('d/m', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to)),
    default   => ucfirst($mois[(int) date('n', strtotime($from))]) . ' ' . date('Y', strtotime($from)),
};
$letters = ['', 'L', 'M', 'M', 'J', 'V', 'S', 'D'];
// Icône d'une case (raison d'absence, absence, en cours)
$cellIcon = static function (array $c): string {
    return match (true) {
        $c['kind'] === 'reason'  => fa(Calendar::REASONS[$c['reason']][1]),
        $c['kind'] === 'absent'  => fa('fa-solid fa-user-xmark'),
        $c['kind'] === 'ongoing' => '<span class="live-dot" aria-hidden="true"></span>',
        default => '',
    };
};
?>
<div class="page-head"<?php if ($live): ?> data-live="calendar" data-live-since-punch="<?= (int) $live['punch'] ?>" data-live-since-day="<?= e($live['day']) ?>" data-live-date="<?= e($today) ?>"<?php endif; ?>>
  <div>
    <h1>Calendrier</h1>
    <p class="muted"><?= e($period) ?> · <?= count($rows) ?> agent(s)<?= $live ? ' · temps de présence en direct' : '' ?></p>
  </div>
  <div class="head-actions">
    <nav class="segmented" aria-label="Affichage">
      <?php foreach ($views as $k => $label): ?>
        <a href="<?= e($link(['vue' => $k])) ?>" class="<?= $k === $view ? 'is-active' : '' ?>"<?= $k === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<form class="filters card cal-filters" method="get" action="/calendrier">
  <input type="hidden" name="vue" value="<?= e($view) ?>">
  <div class="cal-nav">
    <a class="btn btn-ghost" href="<?= e($link(['date' => $prev])) ?>" aria-label="Période précédente"><?= icon('chevron-left') ?></a>
    <a class="btn btn-outline btn-sm" href="<?= e($link(['vue' => 'jour', 'date' => $today])) ?>" title="Afficher la journée d'aujourd'hui">Aujourd'hui</a>
    <a class="btn btn-ghost" href="<?= e($link(['date' => $next])) ?>" aria-label="Période suivante"><?= icon('chevron-right') ?></a>
  </div>
  <label class="field"><span>Date</span><input type="date" name="date" value="<?= e($date) ?>"></label>
  <?php if ($departments): ?>
  <label class="field"><span>Département</span>
    <select name="dep"><option value="0">Tous</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $dep === (int) $d['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) . e($d['name']) ?></option><?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <label class="field field-grow"><span>Agent</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, titre ou matricule" data-suggest="submit"></label>
  <button class="btn btn-primary" type="submit"><?= icon('filter') ?> Afficher</button>
</form>

<section class="card cal-card" data-calendar data-server-time="<?= e($now) ?>" data-cal-date="<?= e($isDay ? $date : '') ?>" data-cal-today="<?= e($today) ?>">
  <div class="cal-scroll">
  <?php if ($isDay): ?>
    <div class="cal-day" role="table" aria-label="Présence de <?= e(date_fr($date)) ?>, de 0 h à 24 h">
      <div class="cal-row cal-head" role="row">
        <div class="cal-agent-head" role="columnheader">Agents</div>
        <div class="cal-hours" role="columnheader">
          <?php for ($h = 0; $h < 24; $h++): ?><span<?= $date === $today && (int) date('G', strtotime($now)) === $h ? ' class="is-now"' : '' ?>><?= $h ?></span><?php endfor; ?>
        </div>
      </div>
      <?php foreach ($rows as $r): $c = $r['cells'][$date]; ?>
      <div class="cal-row" role="row" data-employee-id="<?= (int) $r['id'] ?>">
        <a class="cal-agent person" role="rowheader" href="/employes/<?= (int) $r['id'] ?>">
          <?= avatar($r['name'], photo_url($r)) ?>
          <span class="cal-agent-text"><strong><?= e($r['name']) ?></strong><small class="muted"><?= e($r['job_title'] ?: $r['department'] ?: '—') ?></small></span>
        </a>
        <div class="cal-track cal-track-<?= e($c['kind']) ?>" role="cell" title="<?= e($c['title']) ?>">
          <?php if ($c['sched']): ?>
            <span class="cal-sched" data-from-min="<?= Calendar::minuteOf($c['sched'][0], $date) ?>" data-to-min="<?= Calendar::minuteOf($c['sched'][1], $date) ?>"></span>
          <?php endif; ?>
          <?php if (in_array($c['kind'], ['work', 'ongoing', 'open'], true)):
            $fromMin = Calendar::minuteOf($c['in'], $date);
            $toMin = match ($c['kind']) {
                'work'    => Calendar::minuteOf($c['out'], $date),
                'ongoing' => Calendar::minuteOf($now, $date),
                // Départ inconnu : barre estompée jusqu'à la fin de l'horaire (ou 30 min)
                default   => $c['sched'] && $c['sched'][1] > $c['in'] ? Calendar::minuteOf($c['sched'][1], $date) : min(1440, $fromMin + 30),
            }; ?>
            <span class="cal-bar cal-bar-<?= e($c['kind']) ?><?= $c['late'] ? ' is-late' : '' ?>" data-from-min="<?= $fromMin ?>" data-to-min="<?= max($toMin, $fromMin) ?>"<?php if ($c['kind'] === 'ongoing'): ?> data-since="<?= e($c['in']) ?>"<?php endif; ?>>
              <span class="cal-bar-label"><?= e(time_label($c['in'])) ?><?php if ($c['kind'] === 'work'): ?> – <?= e(time_label($c['out'])) ?> · <strong><?= e(minutes_label($c['minutes'])) ?></strong><?php elseif ($c['kind'] === 'ongoing'): ?> · <strong data-elapsed="<?= e($c['in']) ?>"><?= e($c['label']) ?></strong><?php endif; ?></span>
            </span>
            <?php if ($c['reason']): ?><span class="cal-flag"><?= fa(Calendar::REASONS[$c['reason']][1]) ?></span><?php endif; ?>
          <?php elseif ($c['kind'] !== 'off' || $c['label'] !== ''): ?>
            <span class="cal-band cal-band-<?= e($c['kind'] === 'reason' ? $c['reason'] : $c['kind']) ?>"><?= $cellIcon($c) ?> <?= e($c['kind'] === 'pending' ? 'Attendu à ' . $c['label'] : ($c['kind'] === 'reason' || $c['kind'] === 'absent' ? $c['title'] : $c['label'])) ?></span>
          <?php endif; ?>
          <?php if ($date === $today): ?><span class="cal-now" data-now-line aria-hidden="true"></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <table class="cal-table cal-<?= e($view) ?>">
      <thead><tr>
        <th class="cal-agent-head" scope="col">Agents</th>
        <?php foreach ($dates as $d): $n = (int) date('N', strtotime($d)); ?>
          <th scope="col" class="<?= $n >= 6 ? 'is-weekend' : '' ?><?= $d === $today ? ' is-today' : '' ?>">
            <a href="<?= e($link(['vue' => 'jour', 'date' => $d])) ?>" title="Afficher la journée du <?= e(date_fr($d)) ?>"><span><?= $view === 'semaine' ? e(day_short($d)) : $letters[$n] ?></span><?php if ($view === 'mois'): ?><strong><?= (int) date('j', strtotime($d)) ?></strong><?php endif; ?></a>
          </th>
        <?php endforeach; ?>
        <th scope="col" class="num">Total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $ongoing = null; ?>
        <tr data-employee-id="<?= (int) $r['id'] ?>">
          <th scope="row" class="cal-agent-cell"><a class="cal-agent person" href="/employes/<?= (int) $r['id'] ?>">
            <?= avatar($r['name'], photo_url($r)) ?>
            <span class="cal-agent-text"><strong><?= e($r['name']) ?></strong><small class="muted"><?= e($r['job_title'] ?: $r['department'] ?: '—') ?></small></span></a></th>
          <?php foreach ($r['cells'] as $d => $c): $ongoing = $c['kind'] === 'ongoing' ? $c : $ongoing; ?>
            <td class="cal-cell cal-cell-<?= e($c['kind'] === 'reason' ? $c['reason'] : $c['kind']) ?><?= $c['late'] ? ' is-late' : '' ?><?= $d === $today ? ' is-today' : '' ?>" title="<?= e(ucfirst(date_fr($d)) . ($c['title'] !== '' ? ' · ' . $c['title'] : '')) ?>">
              <?= $cellIcon($c) ?><?php if ($c['kind'] === 'ongoing'): ?><span data-elapsed="<?= e($c['in']) ?>"><?= e($c['label']) ?></span><?php elseif ($c['label'] !== '' && ($view === 'semaine' || !in_array($c['kind'], ['reason', 'absent'], true))): ?><span><?= e($c['label']) ?></span><?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="num cal-total"<?php if ($ongoing): ?> data-total-base="<?= (int) ($r['total'] - $ongoing['minutes']) ?>" data-total-since="<?= e($ongoing['in']) ?>"<?php endif; ?>>
            <strong data-total><?= e(minutes_label($r['total'])) ?></strong>
            <?php if ($r['absent']): ?><small class="cal-absences"><?= (int) $r['absent'] ?> abs.</small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-state"><?= fa('fa-solid fa-user-slash', 'empty-icon') ?><p class="muted">Aucun agent dans ce périmètre.</p></div>
  <?php endif; ?>
</section>

<ul class="legend cal-legend">
  <li><span class="swatch cal-sw-work"></span>Temps au bureau</li>
  <li><span class="swatch cal-sw-ongoing"></span>Au bureau en ce moment</li>
  <li><span class="swatch cal-sw-open"></span>Heure de départ inconnue</li>
  <li><span class="swatch cal-sw-late"></span>Arrivé en retard</li>
  <?php foreach (Calendar::REASONS as $k => [$label, $ic]): ?><li><span class="cal-legend-icon cal-band-<?= e($k) ?>"><?= fa($ic) ?></span><?= e($label) ?></li><?php endforeach; ?>
  <li><span class="swatch cal-sw-absent"></span>Absent sans raison</li>
  <?php if ($isDay): ?><li><span class="swatch cal-sw-sched"></span>Horaire prévu</li><?php endif; ?>
</ul>
