// Liquid Présence — interactions (sans dépendance, compatible CSP stricte)
(function () {
  'use strict';
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // Menu latéral sur mobile
  $$('[data-toggle-sidebar]').forEach((el) =>
    el.addEventListener('click', () => document.body.classList.toggle('sidebar-open'))
  );

  // Boîtes de dialogue
  $$('[data-dialog]').forEach((btn) =>
    btn.addEventListener('click', () => document.getElementById(btn.dataset.dialog)?.showModal())
  );
  $$('dialog [data-close]').forEach((btn) =>
    btn.addEventListener('click', () => btn.closest('dialog').close())
  );

  // Confirmations (formulaires ou boutons)
  document.addEventListener('submit', (e) => {
    const submitter = e.submitter;
    const msg = submitter?.dataset.confirm || e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Sélection multiple dans la liste des employés
  const bulk = document.querySelector('[data-bulk-bar]');
  if (bulk) {
    const boxes = $$('input[name="ids[]"]');
    const all = document.querySelector('[data-check-all]');
    const refresh = () => {
      const n = boxes.filter((b) => b.checked).length;
      bulk.querySelector('[data-bulk-count]').textContent = n;
      bulk.classList.toggle('is-visible', n > 0);
    };
    boxes.forEach((b) => b.addEventListener('change', refresh));
    all?.addEventListener('change', () => { boxes.forEach((b) => (b.checked = all.checked)); refresh(); });
  }

  // Filtre instantané d'une liste
  $$('[data-filter-list]').forEach((input) => {
    const items = $$(input.dataset.filterList + ' li');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      items.forEach((li) => (li.hidden = q !== '' && !li.dataset.text.includes(q)));
    });
  });

  // Formulaire d'attribution : afficher les champs selon la cible
  const assign = document.querySelector('[data-assign]');
  if (assign) {
    const update = () => {
      const target = assign.querySelector('input[name="target"]:checked')?.value;
      $$('[data-show-for]', assign).forEach((el) => (el.hidden = el.dataset.showFor !== target));
      $$('[data-hide-for]', assign).forEach((el) => (el.hidden = el.dataset.hideFor === target));
      const n = $$('input[name="employee_ids[]"]:checked', assign).length;
      const counter = assign.querySelector('[data-selected-count]');
      if (counter) counter.textContent = n;
    };
    assign.addEventListener('change', update);
    update();
  }

  // Rechargement automatique (tableau de bord, présence du jour) :
  // <div data-auto-refresh="30">. Uniquement si l'onglet est visible, sans
  // champ de formulaire actif ni boîte de dialogue ouverte.
  const auto = document.querySelector('[data-auto-refresh]');
  if (auto) {
    const delay = Math.max(10, parseInt(auto.dataset.autoRefresh, 10) || 30) * 1000;
    const loaded = Date.now();
    const busy = () => document.activeElement?.matches('input, select, textarea, [contenteditable]')
      || document.querySelector('dialog[open]') !== null;
    setInterval(() => {
      if (!document.hidden && !busy() && Date.now() - loaded >= delay) window.location.reload();
    }, 2000);
  }

  // Application installable (PWA)
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }
})();
