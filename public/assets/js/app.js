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

  // Mode direct : pages qui portent data-live (tableau de bord, présence du
  // jour, pointeuses). Appelle /api/direct toutes les 5 s (60 s si l'onglet
  // est caché) et met à jour la page sans la recharger.
  const live = document.querySelector('[data-live]');
  if (live) startLive(live);

  function startLive(root) {
    const page = root.dataset.live;
    const state = {
      punch: parseInt(root.dataset.liveSincePunch, 10) || 0,
      day: root.dataset.liveSinceDay || '',
      date: root.dataset.liveDate || '',
      skew: 0,            // heure serveur - heure du navigateur (ms)
      lastOk: Date.now(),
      failures: 0,
      busy: false,
      timer: null,
    };
    const ERROR_DELAYS = [10000, 30000, 60000];
    const NOTIFY_KEY = 'liquid.notifications';
    const sprite = (document.querySelector('svg use')?.getAttribute('href') || '/assets/icons.svg').split('#')[0];

    // -- Outils ------------------------------------------------------------
    // Dates « AAAA-MM-JJ HH:MM:SS » du serveur, toutes lues de la même façon :
    // seuls les écarts entre elles comptent.
    const parse = (s) => new Date(String(s).replace(' ', 'T')).getTime();
    const serverNow = () => Date.now() + state.skew;
    const dayTime = (s) => `${s.slice(8, 10)}/${s.slice(5, 7)} ${s.slice(11, 16)}`;
    const ago = (s) => {
      if (!s) return '—';
      const sec = Math.max(0, Math.round((serverNow() - parse(s)) / 1000));
      if (sec < 60) return `il y a ${sec} s`;
      if (sec < 3600) return `il y a ${Math.floor(sec / 60)} min`;
      if (sec < 86400) return `il y a ${Math.floor(sec / 3600)} h`;
      return `le ${s.slice(8, 10)}/${s.slice(5, 7)} à ${s.slice(11, 16)}`;
    };
    const el = (tag, cls, text) => {
      const n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text !== undefined) n.textContent = text;
      return n;
    };
    const icon = (name) => {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon');
      svg.setAttribute('aria-hidden', 'true');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', `${sprite}#${name}`);
      svg.appendChild(use);
      return svg;
    };
    const initials = (name) => (name.trim().split(/\s+/).slice(0, 2).map((p) => p[0] || '').join('').toUpperCase() || '?');
    // Relance une animation CSS (surlignage, chiffre qui change)
    const replay = (node, cls, ms) => {
      node.classList.remove(cls);
      void node.offsetWidth;
      node.classList.add(cls);
      clearTimeout(node[`_${cls}`]);
      node[`_${cls}`] = setTimeout(() => node.classList.remove(cls), ms);
    };
    const fresh = (node) => replay(node, 'is-fresh', 3100);
    const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    // -- Indicateur « En direct » et interrupteur des notifications ----------
    const status = document.querySelector('[data-live-status]');
    const statusLabel = status?.querySelector('[data-live-label]');
    const statusAgo = status?.querySelector('[data-live-ago]');
    if (status) status.hidden = false;
    const setOnline = (ok) => {
      if (!status) return;
      status.classList.toggle('is-offline', !ok);
      statusLabel.textContent = ok ? 'En direct' : 'Hors ligne';
    };
    const tick = () => {
      if (statusAgo) {
        const sec = Math.max(0, Math.round((Date.now() - state.lastOk) / 1000));
        statusAgo.textContent = sec < 60 ? `à jour il y a ${sec} s` : `à jour il y a ${Math.floor(sec / 60)} min`;
      }
      $$('[data-ago]').forEach((n) => { n.textContent = ago(n.dataset.ago); });
    };
    setInterval(tick, 1000);

    const notifySwitch = document.querySelector('[data-live-notify]');
    const notifyInput = notifySwitch?.querySelector('input');
    let notifyOn = true;
    try { notifyOn = localStorage.getItem(NOTIFY_KEY) !== 'off'; } catch (e) { /* stockage indisponible */ }
    if (notifySwitch) {
      notifySwitch.hidden = false;
      notifyInput.checked = notifyOn;
      notifyInput.addEventListener('change', () => {
        notifyOn = notifyInput.checked;
        try { localStorage.setItem(NOTIFY_KEY, notifyOn ? 'on' : 'off'); } catch (e) { /* stockage indisponible */ }
      });
    }

    // -- Notifications (3 au maximum, 5 s chacune) ---------------------------
    let toasts = null;
    const toast = (p) => {
      if (!toasts) {
        toasts = el('div', 'toasts');
        toasts.setAttribute('role', 'status');
        toasts.setAttribute('aria-live', 'polite');
        document.body.appendChild(toasts);
      }
      const access = p.usage_type === 'acces';
      const verb = access ? '' : (p.direction === 'entree' ? ' est entré' : ' est sorti');
      const t = el('div', `toast toast-${access ? 'acces' : p.direction}`);
      t.append(el('strong', '', p.employee.name + verb), document.createTextNode(` · ${p.device.name}`));
      toasts.prepend(t);
      while (toasts.children.length > 3) toasts.lastElementChild.remove();
      setTimeout(() => t.remove(), 5000);
    };

    // -- Tableau de bord ----------------------------------------------------
    const updateKpis = (k) => {
      $$('[data-kpi]').forEach((n) => {
        const v = String(k[n.dataset.kpi] ?? '');
        if (v !== '' && n.textContent !== v) {
          n.textContent = v;
          replay(n, 'is-bumped', 700);
        }
      });
      const meter = document.querySelector('[data-kpi-meter]');
      if (meter) {
        meter.querySelector('.meter-fill')?.setAttribute('width', Math.min(100, k.rate));
        meter.setAttribute('aria-label', `${k.rate} % des agents attendus`);
      }
    };

    const punchItem = (p) => {
      const kind = p.usage_type === 'acces' ? 'acces' : p.direction;
      const li = el('li', 'list-item');
      li.dataset.punchedAt = p.punched_at;
      li.dataset.punchId = p.id;
      const dir = el('span', `dir dir-${kind}`);
      dir.appendChild(icon(kind === 'acces' ? 'door' : (kind === 'entree' ? 'arrow-in' : 'arrow-out')));
      const main = el('span', 'list-main');
      main.append(el('strong', '', p.employee.name), el('small', '', p.device.name));
      li.append(dir, main, el('span', 'time', p.time));
      return li;
    };
    const addPunches = (list) => {
      const ul = document.querySelector('[data-punches]');
      if (!ul) return;
      const newer = (a, b) => a.punched_at > b.punchedAt || (a.punched_at === b.punchedAt && a.id > Number(b.punchId));
      list.slice().reverse().forEach((p) => {
        if (ul.querySelector(`[data-punch-id="${p.id}"]`)) return;
        const items = $$('[data-punch-id]', ul);
        const before = items.find((li) => newer(p, li.dataset));
        if (!before && items.length >= 8) return; // plus ancien que les 8 affichés
        const li = punchItem(p);
        if (before) ul.insertBefore(li, before); else ul.appendChild(li);
        fresh(li);
      });
      $$('[data-punch-id]', ul).slice(8).forEach((li) => li.remove());
      if (ul.querySelector('[data-punch-id]')) ul.querySelector('[data-punches-empty]')?.remove();
    };

    const latesList = document.querySelector('[data-lates]');
    let latesSig = latesList ? $$('[data-late]', latesList).map((li) => li.dataset.late).join('|') : '';
    const updateLates = (lates) => {
      if (!latesList) return;
      const sig = lates.map((l) => `${l.id}:${l.late_minutes}:${l.first_in}`).join('|');
      if (sig === latesSig) return;
      const known = new Set(latesSig.split('|'));
      latesSig = sig;
      latesList.replaceChildren(...lates.map((l) => {
        const li = el('li');
        li.dataset.late = `${l.id}:${l.late_minutes}:${l.first_in}`;
        const a = el('a', 'list-item');
        a.href = `/employes/${l.id}`;
        const main = el('span', 'list-main');
        main.append(el('strong', '', l.name), el('small', '', `${l.group_name} · arrivé à ${l.first_in}`));
        a.append(el('span', 'avatar avatar-sm', initials(l.name)), main, el('span', 'badge badge-warn', `+${l.late_minutes} min`));
        li.appendChild(a);
        if (!known.has(li.dataset.late)) fresh(a);
        return li;
      }));
      document.querySelector('[data-lates-empty]').hidden = lates.length > 0;
    };

    // Pastilles d'état des pointeuses (tableau de bord)
    const updateDeviceDots = (devices) => {
      devices.forEach((d) => {
        const li = document.querySelector(`[data-devices] [data-device-id="${d.id}"]`);
        if (!li) return;
        const ok = d.last_sync_ok === 1;
        li.querySelector('[data-device-dot]').className = `dot ${d.last_sync_at === null ? 'dot-muted' : (ok ? 'dot-ok' : 'dot-danger')}`;
        li.querySelector('[data-device-sync]').textContent = `${li.dataset.deviceIp} · ${d.last_sync_at ? `synchronisée à ${d.last_sync_at.slice(11, 16)}` : 'jamais synchronisée'}`;
        const drift = li.querySelector('[data-device-drift]');
        drift.hidden = d.clock_offset_sec === null || Math.abs(d.clock_offset_sec) <= 60;
        if (!drift.hidden) drift.textContent = `Horloge ${d.clock_offset_sec > 0 ? '+' : ''}${d.clock_offset_sec} s`;
        li.querySelector('[data-device-down]').hidden = ok || d.last_sync_at === null;
      });
    };

    // -- Page Pointeuses ------------------------------------------------------
    const updateDeviceRows = (devices) => {
      devices.forEach((d) => {
        const tr = document.querySelector(`tr[data-device-id="${d.id}"]`);
        if (!tr) return;
        const sync = tr.querySelector('[data-col="sync"]');
        if (d.last_sync_at === null) {
          sync.replaceChildren(el('span', 'badge badge-muted', 'En attente'));
        } else if (d.last_sync_ok === 1) {
          sync.replaceChildren(el('span', 'badge badge-ok', 'OK'), document.createTextNode(' '), el('small', 'muted', dayTime(d.last_sync_at)));
        } else {
          sync.replaceChildren(el('span', 'badge badge-danger', 'Erreur'), document.createTextNode(' '), el('small', 'muted block', d.last_error || ''));
        }
        const event = tr.querySelector('[data-col="event"]');
        event.querySelector('[data-listening]').hidden = !d.listening;
        const agoEl = event.querySelector('[data-ago]');
        if (agoEl.dataset.ago !== (d.last_event_at || '')) {
          agoEl.dataset.ago = d.last_event_at || '';
          agoEl.classList.toggle('muted', !d.last_event_at);
          if (d.last_event_at) fresh(event);
        }
        const clock = tr.querySelector('[data-col="clock"]');
        const off = d.clock_offset_sec;
        if (off === null) clock.replaceChildren(document.createTextNode('—'));
        else if (Math.abs(off) <= 60) clock.replaceChildren(el('span', 'text-ok', "à l'heure"));
        else clock.replaceChildren(el('span', 'badge badge-warn',
          `${off > 0 ? 'avance' : 'retard'} de ${Math.abs(off) >= 120 ? `${Math.round(Math.abs(off) / 60)} min` : `${Math.abs(off)} s`}`));
      });
    };

    // -- Page Présence (aujourd'hui) -------------------------------------------
    const bar = document.querySelector('[data-live-bar]');
    const matches = (d) => {
      const group = parseInt(root.dataset.liveGroup, 10) || 0;
      const st = root.dataset.liveStatus || '';
      const q = fold(root.dataset.liveQ);
      return (!group || d.group_id === group)
        && (st ? d.status === st : d.status !== 'repos')
        && (!q || fold(d.name).includes(q) || fold(d.matricule).includes(q) || d.device_user_id === root.dataset.liveQ);
    };
    const updateRows = (days) => {
      days.forEach((d) => {
        const tr = document.querySelector(`tr[data-employee-id="${d.employee_id}"]`);
        if (!tr) {
          if (matches(d) && bar) bar.hidden = false;
          return;
        }
        const cells = $$('[data-col]', tr);
        const before = cells.map((c) => c.textContent).join('|');
        const col = (name) => tr.querySelector(`[data-col="${name}"]`);
        col('first_in').textContent = d.labels.first_in;
        const out = col('last_out');
        out.replaceChildren(document.createTextNode(d.labels.last_out));
        if (d.corrected) {
          const tag = el('span', 'tag', 'corrigée');
          tag.title = d.correction_note;
          out.append(document.createTextNode(' '), tag);
        }
        col('worked').textContent = d.labels.worked;
        col('late').textContent = d.labels.late;
        col('late').classList.toggle('text-warn', d.late_minutes > 0);
        col('overtime').textContent = d.labels.overtime;
        col('status').replaceChildren(el('span', `badge ${d.labels.status_class}`, d.labels.status));
        if (cells.map((c) => c.textContent).join('|') !== before) fresh(tr);
        if (!matches(d) && bar) bar.hidden = false;
      });
    };

    // -- Appels ----------------------------------------------------------------
    const apply = (data) => {
      state.skew = parse(data.server_time) - Date.now();
      if (state.date && data.date !== state.date) {
        // Nouveau jour : la page affichée n'est plus celle d'aujourd'hui
        window.location.reload();
        return;
      }
      const punches = data.punches || [];
      if (notifyOn) {
        // Seulement les badges récents (pas l'historique repris après une coupure)
        punches.filter((p) => serverNow() - parse(p.punched_at) < 10 * 60 * 1000)
          .slice(0, 3).reverse().forEach(toast);
      }
      state.punch = Math.max(state.punch, data.last_punch_id || 0);
      if (data.last_day_computed_at) state.day = data.last_day_computed_at;

      if (page === 'dashboard') {
        updateKpis(data.kpis);
        addPunches(punches);
        updateLates(data.lates || []);
        updateDeviceDots(data.devices || []);
      } else if (page === 'presence') {
        updateRows(data.days || []);
      } else if (page === 'devices') {
        updateDeviceRows(data.devices || []);
      }
    };

    const schedule = (ms) => {
      clearTimeout(state.timer);
      state.timer = setTimeout(poll, ms);
    };

    async function poll() {
      if (state.busy) return;
      state.busy = true;
      try {
        const qs = new URLSearchParams({ since_punch: state.punch, since_day: state.day });
        const res = await fetch(`/api/direct?${qs}`, {
          headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin',
        });
        if (res.status === 401) {
          window.location.href = '/connexion';
          return;
        }
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        apply(await res.json());
        state.failures = 0;
        state.lastOk = Date.now();
        setOnline(true);
        schedule(document.hidden ? 60000 : 5000);
      } catch (e) {
        state.failures += 1;
        setOnline(false);
        schedule(ERROR_DELAYS[Math.min(state.failures, ERROR_DELAYS.length) - 1]);
      } finally {
        state.busy = false;
        tick();
      }
    }

    // Onglet de nouveau visible : mise à jour immédiate (sauf pendant les
    // délais d'attente après une erreur).
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && state.failures === 0) poll();
    });
    tick();
    schedule(5000);
  }

  // Application installable (PWA)
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }
})();
