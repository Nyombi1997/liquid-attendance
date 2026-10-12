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

  // Outils partagés
  const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const faIcon = (cls) => { const i = document.createElement('i'); i.className = cls; i.setAttribute('aria-hidden', 'true'); return i; };
  const initialsOf = (name) => (String(name).trim().split(/\s+/).slice(0, 2).map((p) => p[0] || '').join('').toUpperCase() || '?');

  // Mots de passe : bouton « œil » pour afficher / masquer la saisie
  $$('input[type="password"]').forEach((input) => {
    const wrap = document.createElement('span');
    wrap.className = 'password-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    const icon = faIcon('fa-regular fa-eye');
    btn.appendChild(icon);
    const set = (visible) => {
      input.type = visible ? 'text' : 'password';
      icon.className = visible ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
      const label = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
      btn.setAttribute('aria-label', label);
      btn.title = label;
      btn.setAttribute('aria-pressed', String(visible));
    };
    set(false);
    btn.addEventListener('click', () => {
      set(input.type === 'password');
      input.focus();
    });
    // Toujours renvoyer le champ masqué (gestionnaires de mots de passe)
    input.form?.addEventListener('submit', () => set(false));
    wrap.appendChild(btn);
  });

  // Barres de recherche : propositions dès la première lettre.
  //   data-suggest="go"     : ouvre la fiche de l'agent choisi
  //   data-suggest="submit" : met son nom dans le champ et envoie le filtre
  //   data-suggest="filter" : met son nom dans le champ (filtre instantané de la page)
  // Propositions : /api/suggestions (périmètre vérifié par le serveur), ou les
  // cartes de la page Agents pour « filter ».
  let suggestCount = 0;
  $$('input[data-suggest]').forEach((input) => {
    const mode = input.dataset.suggest;
    const host = input.closest('.search, .agents-search, .field') || input.parentElement;
    host.classList.add('suggest-host');
    const box = document.createElement('ul');
    box.className = 'suggest';
    box.id = `suggest-${++suggestCount}`;
    box.setAttribute('role', 'listbox');
    box.hidden = true;
    host.appendChild(box);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', box.id);
    input.setAttribute('aria-expanded', 'false');
    input.autocomplete = 'off';

    let items = [];
    let active = -1;
    let timer = null;
    let seq = 0;
    let picking = false;

    const close = () => {
      box.hidden = true;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    };
    const highlight = (i) => {
      active = i;
      $$('[role="option"]', box).forEach((li, n) => {
        li.classList.toggle('is-active', n === i);
        li.setAttribute('aria-selected', String(n === i));
        if (n === i) { input.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); }
      });
    };
    const choose = (item) => {
      close();
      if (mode === 'go') {
        window.location.href = item.url;
        return;
      }
      picking = true;
      input.value = item.name;
      if (mode === 'submit' && input.form) input.form.requestSubmit();
      else input.dispatchEvent(new Event('input', { bubbles: true }));
      picking = false;
    };
    const render = (q) => {
      if (!items.length) {
        const li = document.createElement('li');
        li.className = 'suggest-empty';
        li.textContent = `Aucun agent pour « ${q} »`;
        box.replaceChildren(li);
      } else {
        box.replaceChildren(...items.map((item, n) => {
          const li = document.createElement('li');
          li.className = 'suggest-item';
          li.id = `${box.id}-${n}`;
          li.setAttribute('role', 'option');
          li.setAttribute('aria-selected', 'false');
          let pic;
          if (item.photo) {
            pic = document.createElement('img');
            pic.className = 'avatar avatar-sm avatar-img';
            pic.src = item.photo;
            pic.alt = '';
          } else {
            pic = document.createElement('span');
            pic.className = 'avatar avatar-sm';
            pic.textContent = initialsOf(item.name);
          }
          const main = document.createElement('span');
          main.className = 'suggest-main';
          const strong = document.createElement('strong');
          strong.textContent = item.name;
          main.appendChild(strong);
          if (item.detail) {
            const small = document.createElement('small');
            small.textContent = item.detail;
            main.appendChild(small);
          }
          li.append(pic, main);
          li.addEventListener('mousedown', (e) => e.preventDefault()); // garder le focus dans le champ
          li.addEventListener('click', (e) => { e.preventDefault(); choose(item); });
          return li;
        }));
      }
      box.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      active = -1;
    };
    // Propositions locales : cartes de la page Agents
    const localItems = (q) => $$('[data-agent]')
      .filter((card) => fold(card.dataset.search).includes(fold(q)))
      .slice(0, 8)
      .map((card) => ({
        name: card.querySelector('.agent-name')?.textContent.trim() || '',
        detail: card.querySelector('.agent-title')?.textContent.trim() || '',
        photo: card.querySelector('.agent-photo img')?.getAttribute('src') || null,
      }));
    const lookup = async () => {
      const q = input.value.trim();
      const mine = ++seq;
      if (!q) { close(); return; }
      if (mode === 'filter') {
        items = localItems(q);
      } else {
        try {
          const res = await fetch(`/api/suggestions?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
          });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const data = await res.json();
          if (mine !== seq) return; // une saisie plus récente a déjà répondu
          items = data.items || [];
        } catch (e) {
          if (mine === seq) close();
          return;
        }
      }
      if (document.activeElement === input) render(q);
    };

    input.addEventListener('input', () => {
      if (picking) return;
      clearTimeout(timer);
      timer = setTimeout(lookup, mode === 'filter' ? 0 : 120);
    });
    input.addEventListener('focus', () => { if (input.value.trim() && box.hidden) lookup(); });
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (e) => {
      if (box.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const step = e.key === 'ArrowDown' ? 1 : -1;
        highlight((active + step + items.length) % items.length);
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        choose(items[active]);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
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

  // Comptes : le champ « Département » ne concerne que le rôle Manager
  $$('[data-role-select]').forEach((select) => {
    const field = select.closest('form').querySelector('[data-role-department]');
    if (!field) return;
    const update = () => { field.hidden = select.value !== 'manager'; };
    select.addEventListener('change', update);
    update();
  });

  // Boutons « Copier » (e-mail, téléphone…) : data-copy="texte à copier"
  const copyText = async (text) => {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      // Repli : navigateur sans API presse-papiers
      const area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.className = 'visually-hidden';
      document.body.appendChild(area);
      area.select();
      const ok = document.execCommand('copy');
      area.remove();
      return ok;
    }
  };
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const ok = await copyText(btn.dataset.copy);
    const icon = btn.querySelector('i');
    const label = btn.getAttribute('aria-label');
    btn.classList.toggle('is-copied', ok);
    btn.title = ok ? 'Copié !' : 'Copie impossible';
    if (icon) icon.className = ok ? 'fa-solid fa-check' : 'fa-solid fa-xmark';
    btn.setAttribute('aria-label', ok ? 'Copié' : 'Copie impossible');
    clearTimeout(btn._copyTimer);
    btn._copyTimer = setTimeout(() => {
      btn.classList.remove('is-copied');
      btn.title = 'Copier';
      if (icon) icon.className = 'fa-regular fa-copy';
      btn.setAttribute('aria-label', label);
    }, 1600);
  });

  // Arbre des départements : replier / déplier
  $$('[data-dept-toggle]').forEach((btn) => btn.addEventListener('click', () => {
    const li = btn.closest('li');
    const collapsed = li.classList.toggle('is-collapsed');
    btn.setAttribute('aria-expanded', String(!collapsed));
  }));

  // Page Agents : puces de filtre, recherche, fiches et statuts
  const grid = document.querySelector('[data-agent-grid]');
  if (grid) setupAgents(grid);

  function setupAgents(gridEl) {
    const cards = $$('[data-agent]', gridEl);
    const search = document.querySelector('[data-agent-search]');
    const empty = document.querySelector('[data-agents-empty]');
    let filter = 'tous';

    const apply = () => {
      const q = fold(search?.value.trim());
      let shown = 0;
      cards.forEach((card) => {
        const ok = card.dataset.filters.split(' ').includes(filter) && (!q || fold(card.dataset.search).includes(q));
        card.hidden = !ok;
        shown += ok ? 1 : 0;
      });
      if (empty) empty.hidden = shown > 0;
    };
    const recount = () => {
      $$('[data-filter-count]').forEach((n) => {
        n.textContent = cards.filter((c) => c.dataset.filters.split(' ').includes(n.dataset.filterCount)).length;
      });
    };
    $$('[data-agent-filter]').forEach((chip) => chip.addEventListener('click', () => {
      filter = chip.dataset.agentFilter;
      $$('[data-agent-filter]').forEach((c) => {
        c.classList.toggle('is-active', c === chip);
        c.setAttribute('aria-pressed', String(c === chip));
      });
      apply();
    }));
    search?.addEventListener('input', apply);
    if (search?.value) apply();
    // Appelé par le mode direct après la mise à jour d'une carte
    gridEl.refreshFilters = () => { recount(); apply(); };

    // -- Fiche (nom, titre, contacts, département, photo) ---------------------
    const dialog = document.querySelector('[data-agent-dialog]');
    const statusDialog = document.querySelector('[data-status-dialog]');
    const cropper = dialog ? createCropper(dialog.querySelector('[data-cropper]')) : null;

    gridEl.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-agent-edit]');
      if (!btn) return;
      const card = btn.closest('[data-agent]');
      const data = JSON.parse(card.dataset.agentJson || '{}');
      if (btn.dataset.agentEdit === 'status') openStatus(data);
      else openProfile(data, btn.dataset.agentEdit === 'photo');
    });

    function openProfile(data, pickPhoto) {
      if (!dialog) return;
      const form = dialog.querySelector('[data-agent-form]');
      form.action = form.dataset.action.replace('{id}', data.id);
      $$('[data-field]', form).forEach((input) => {
        const v = data[input.dataset.field];
        input.value = v === null || v === undefined ? (input.tagName === 'SELECT' ? '0' : '') : v;
      });
      cropper.reset(data.photo);
      dialog.showModal();
      if (pickPhoto) cropper.pick();
    }

    function openStatus(data) {
      if (!statusDialog) return;
      const form = statusDialog.querySelector('[data-status-form]');
      form.action = form.dataset.action.replace('{id}', data.id);
      statusDialog.querySelector('[data-status-name]').textContent = data.name;
      const current = data.work_status || '';
      $$('input[name="work_status"]', form).forEach((r) => { r.checked = r.value === current; });
      $$('[data-field]', form).forEach((input) => { input.value = data[input.dataset.field] || ''; });
      const extra = form.querySelector('[data-status-extra]');
      const sync = () => { extra.hidden = (form.querySelector('input[name="work_status"]:checked')?.value || '') === ''; };
      form.onchange = sync;
      sync();
      statusDialog.showModal();
    }
  }

  /**
   * Recadrage carré d'une photo, sans dépendance : faire glisser pour déplacer,
   * molette / curseur pour zoomer, flèches du clavier pour ajuster.
   * À l'envoi du formulaire, l'image recadrée (512 × 512, JPEG) est placée dans
   * le champ caché photo_data, contrôlé par AgentController::decodePhoto().
   */
  function createCropper(root) {
    const OUT = 512;
    const canvas = root.querySelector('[data-crop-canvas]');
    const stage = canvas.parentElement;
    const ctx = canvas.getContext('2d');
    const file = root.querySelector('[data-crop-file]');
    const zoom = root.querySelector('[data-crop-zoom]');
    const zoomField = root.querySelector('[data-crop-zoom-field]');
    const current = root.querySelector('[data-crop-current]');
    const placeholder = root.querySelector('[data-crop-placeholder]');
    const hint = root.querySelector('[data-crop-hint]');
    const removeField = root.closest('form').querySelector('[data-photo-remove-field]');
    const remove = root.closest('form').querySelector('[data-photo-remove]');
    const output = root.closest('form').querySelector('[data-crop-output]');
    const hintText = hint.textContent;
    const SIZE = canvas.width; // taille d'affichage, en pixels CSS
    const ratio = window.devicePixelRatio || 1;
    canvas.width = canvas.height = Math.round(SIZE * ratio);
    canvas.style.width = canvas.style.height = `${SIZE}px`;
    let img = null;
    let x = 0; let y = 0; // décalage du centre de l'image par rapport au centre du cadre
    let drag = null;

    const base = () => Math.max(SIZE / img.naturalWidth, SIZE / img.naturalHeight);
    const dims = () => {
      const s = base() * parseFloat(zoom.value);
      return [img.naturalWidth * s, img.naturalHeight * s];
    };
    const clamp = () => {
      const [w, h] = dims();
      x = Math.min((w - SIZE) / 2, Math.max(-(w - SIZE) / 2, x));
      y = Math.min((h - SIZE) / 2, Math.max(-(h - SIZE) / 2, y));
    };
    const paint = (context, size) => {
      const k = size / SIZE;
      const [w, h] = dims();
      context.fillStyle = '#fff';
      context.fillRect(0, 0, size, size);
      context.imageSmoothingQuality = 'high';
      context.drawImage(img, (SIZE / 2 + x - w / 2) * k, (SIZE / 2 + y - h / 2) * k, w * k, h * k);
    };
    const draw = () => {
      if (!img) return;
      clamp();
      paint(ctx, canvas.width);
    };
    const show = (mode) => {
      // mode : 'empty' | 'current' (photo enregistrée) | 'edit' (nouvelle photo)
      canvas.hidden = mode !== 'edit';
      current.hidden = mode !== 'current';
      placeholder.hidden = mode !== 'empty';
      zoomField.hidden = mode !== 'edit';
      stage.classList.toggle('has-image', mode === 'edit');
    };

    file.addEventListener('change', () => {
      const f = file.files[0];
      if (!f) return;
      if (!/^image\/(jpeg|png|webp)$/.test(f.type) || f.size > 15 * 1024 * 1024) {
        hint.textContent = 'Choisissez une image JPEG, PNG ou WebP de moins de 15 Mo.';
        file.value = '';
        return;
      }
      // Lecture en data: (la CSP n'autorise pas les images blob:)
      const reader = new FileReader();
      reader.onload = () => {
        const image = new Image();
        image.onload = () => {
          if (image.naturalWidth < 128 || image.naturalHeight < 128) {
            hint.textContent = 'Image trop petite (128 × 128 pixels au minimum).';
            return;
          }
          img = image;
          x = 0; y = 0; zoom.value = '1';
          hint.textContent = 'Faites glisser pour cadrer le visage, zoomez avec le curseur ou la molette.';
          if (remove) remove.checked = false;
          show('edit');
          draw();
          canvas.focus();
        };
        image.onerror = () => { hint.textContent = 'Image illisible.'; };
        image.src = reader.result;
      };
      reader.readAsDataURL(f);
      file.value = '';
    });

    canvas.addEventListener('pointerdown', (e) => {
      if (!img) return;
      drag = { id: e.pointerId, sx: e.clientX, sy: e.clientY, x, y };
      canvas.setPointerCapture(e.pointerId);
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drag || e.pointerId !== drag.id) return;
      x = drag.x + (e.clientX - drag.sx);
      y = drag.y + (e.clientY - drag.sy);
      draw();
    });
    const stop = () => { drag = null; };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    canvas.addEventListener('wheel', (e) => {
      if (!img) return;
      e.preventDefault();
      zoom.value = Math.min(4, Math.max(1, parseFloat(zoom.value) * (e.deltaY < 0 ? 1.08 : 1 / 1.08)));
      draw();
    }, { passive: false });
    canvas.addEventListener('keydown', (e) => {
      if (!img) return;
      const step = e.shiftKey ? 20 : 5;
      const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
      if (moves[e.key]) {
        x += moves[e.key][0]; y += moves[e.key][1];
      } else if (e.key === '+' || e.key === '=') {
        zoom.value = Math.min(4, parseFloat(zoom.value) + 0.1);
      } else if (e.key === '-') {
        zoom.value = Math.max(1, parseFloat(zoom.value) - 0.1);
      } else {
        return;
      }
      e.preventDefault();
      draw();
    });
    zoom.addEventListener('input', draw);
    remove?.addEventListener('change', () => {
      img = null;
      show(remove.checked || !current.getAttribute('src') ? 'empty' : 'current');
    });

    root.closest('form').addEventListener('submit', () => {
      output.value = '';
      if (!img) return;
      const out = document.createElement('canvas');
      out.width = out.height = OUT;
      paint(out.getContext('2d'), OUT);
      output.value = out.toDataURL('image/jpeg', 0.88);
    });

    return {
      reset(photoUrl) {
        img = null;
        output.value = '';
        hint.textContent = hintText;
        if (remove) remove.checked = false;
        if (removeField) removeField.hidden = !photoUrl;
        if (photoUrl) current.src = photoUrl; else current.removeAttribute('src');
        show(photoUrl ? 'current' : 'empty');
      },
      pick() { file.click(); },
    };
  }

  // Mode direct : pages qui portent data-live (tableau de bord, présence du
  // jour, agents, pointeuses). Appelle /api/direct toutes les 5 s (60 s si
  // l'onglet est caché) et met à jour la page sans la recharger.
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
        a.href = latesList.dataset.lateHref === 'agents' ? '/agents' : `/employes/${l.id}`;
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
      const deps = (root.dataset.liveDeps || '').split(',').filter(Boolean).map(Number);
      const st = root.dataset.liveStatus || '';
      const q = fold(root.dataset.liveQ);
      return (!group || d.group_id === group)
        && (!deps.length || deps.includes(d.department_id))
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

    // -- Page Agents : heure d'arrivée et statut de chaque carte ----------------
    // L'état est calculé par le serveur (days[].card, AgentStatus) : on l'affiche tel quel.
    const fa = (cls) => { const i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };
    const updateCards = (days) => {
      const gridEl = document.querySelector('[data-agent-grid]');
      if (!gridEl) return;
      let changed = false;
      days.forEach((d) => {
        const card = gridEl.querySelector(`[data-agent="${d.employee_id}"]`);
        if (!card || !d.card) return;
        const { arrival, work, filters } = d.card;
        const sig = `${arrival.kind}|${arrival.label}|${d.late_minutes}|${work.key}`;
        if (card.dataset.liveSig === sig) return;
        const first = card.dataset.liveSig === undefined;
        card.dataset.liveSig = sig;

        const arr = card.querySelector('[data-agent-arrival]');
        const before = `${arr.className}|${arr.textContent.trim()}`;
        arr.className = `agent-arrival arrival-${arrival.kind}`;
        arr.title = arrival.title;
        const icon = arrival.kind === 'late' ? 'fa-solid fa-clock' : (arrival.kind === 'ontime' ? 'fa-solid fa-circle-check' : 'fa-regular fa-clock');
        const parts = [fa(icon), document.createTextNode(' '), el('span', '', arrival.label)];
        if (arrival.kind === 'late') parts.push(el('small', '', `+${d.late_minutes} min`));
        arr.replaceChildren(...parts);

        const st = card.querySelector('[data-agent-status]');
        st.className = `agent-status status-${work.variant}`;
        st.title = st.tagName === 'BUTTON' ? `${work.title} · cliquer pour modifier` : work.title;
        st.setAttribute('aria-label', st.tagName === 'BUTTON' ? `${work.label}, modifier le statut` : work.label);
        st.replaceChildren(fa(work.icon));

        card.dataset.filters = filters.join(' ');
        changed = true;
        if (!first || before !== `${arr.className}|${arr.textContent.trim()}`) fresh(card);
      });
      if (changed) gridEl.refreshFilters?.();
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
      } else if (page === 'agents') {
        updateCards(data.days || []);
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
