<div class="page-head">
  <div>
    <h1>Journal d'activité</h1>
    <p class="muted">Les 300 dernières actions : connexions, modifications, corrections.</p>
  </div>
</div>
<section class="card table-card">
  <table class="table table-compact">
    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Détails</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('d/m/Y H:i:s', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['username'] ?? '—') ?></td>
        <td><code><?= e($r['action']) ?></code></td>
        <td class="details"><?= e($r['details']) ?></td>
        <td class="muted"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted center">Aucune activité.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
