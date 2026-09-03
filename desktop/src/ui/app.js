'use strict';

/* global FIELDMAPS */

/* `api` ist bereits als globales Objekt aus preload.js (contextBridge) vorhanden. */
const view = document.getElementById('view');
const navList = document.getElementById('nav-list');
const navStatus = document.getElementById('nav-status');

const state = {
  meta: null,
  stats: null,
  me: null,
  current: { name: 'status' },
};

/** Rechte-Abfrage – funktioniert auch offline (Fallback aus dem letzten /meta). */
function can(capOrKey) {
  const m = state.me;
  if (m && m.caps && capOrKey in m.caps) return !!m.caps[capOrKey];
  if (capOrKey === 'can_manage') return !!(m ? m.can_manage : state.meta && state.meta.can_manage);
  // Fallback: aus der Tabellen-Sichtbarkeit des letzten /meta ableiten.
  const t = state.meta && state.meta.tables ? state.meta.tables : {};
  const visAll = (slug) => t[slug] && t[slug].visibility === 'all';
  const map = {
    jb_view_journal: visAll('jb_buchungen'),
    jb_approve_auslagen: visAll('jb_auslagen') && visAll('jb_buchungen'),
    jb_submit_auslagen: !!t.jb_auslagen,
    vp_manage_members: visAll('vp_antraege'),
    pp_manage: visAll('pp_protokolle'),
    wl_manage_wishes: visAll('wunschliste'),
  };
  return !!map[capOrKey];
}
const isManager = () => can('can_manage') || can('vp_manage_members');

/* ------------------------------------------------------------- utilities */

function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') n.className = v;
    else if (k === 'html') n.innerHTML = v;
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
    else if (v !== null && v !== undefined) n.setAttribute(k, v);
  }
  for (const kid of kids.flat()) {
    if (kid == null) continue;
    n.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  }
  return n;
}

let toastTimer = null;
function toast(msg, isErr) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = isErr ? 'err' : '';
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), isErr ? 6000 : 3000);
}

/** ruft eine api-Methode auf, wirft bei { ok:false } */
async function call(promise) {
  const res = await promise;
  if (res && res.ok === false) throw new Error(res.error || 'Unbekannter Fehler');
  return res ? res.data : res;
}

const fmt = (v) => (v === null || v === undefined || v === '' ? '—' : String(v));

/* ------------------------------------------------------------- boot */

async function boot() {
  wireGlobalButtons();
  const cfg = await call(api.config.get());
  if (!cfg.baseUrl) {
    renderNav();
    return showSettings('Bitte zuerst die Verbindung zu WordPress einrichten.');
  }
  navStatus.textContent = cfg.baseUrl.replace(/^https?:\/\//, '');
  await refreshMeta();
  await refreshMe();
  await refreshStats();
  renderNav();
  showStatus();
}

async function refreshMeta() {
  try {
    state.meta = await call(api.data.meta());
  } catch {
    state.meta = null;
  }
}

async function refreshMe() {
  try {
    state.me = await call(api.me());
  } catch {
    // offline o. Ä. – Rechte werden dann aus dem letzten /meta abgeleitet
    if (!state.me) state.me = null;
  }
}

async function refreshStats() {
  try {
    state.stats = await call(api.data.stats());
    navStatus.classList.toggle('online', !!state.stats.last_pull);
  } catch (e) {
    state.stats = null;
  }
}

function wireGlobalButtons() {
  document.getElementById('btn-sync').addEventListener('click', runSync);
  document.getElementById('btn-reconcile').addEventListener('click', runReconcile);
}

async function runSync() {
  const btn = document.getElementById('btn-sync');
  btn.disabled = true;
  btn.textContent = 'Synchronisiere…';
  try {
    const r = await call(api.sync.run());
    await refreshMeta();
    await refreshMe();
    await refreshStats();
    renderNav();
    const c = r.pulled.conflicts + r.pushed.conflicts;
    toast(
      `Sync fertig: ${r.pulled.upserted} geladen, ${r.pushed.applied} gesendet` +
        (c ? `, ${c} Konflikt(e)` : '')
    );
    rerender();
  } catch (e) {
    toast(e.message, true);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Synchronisieren';
  }
}

async function runReconcile() {
  const btn = document.getElementById('btn-reconcile');
  btn.disabled = true;
  try {
    const cands = await call(api.sync.reconcile());
    if (!cands.length) {
      toast('Vollabgleich: keine serverseitig gelöschten Zeilen.');
      return;
    }
    const byTbl = {};
    cands.forEach((c) => (byTbl[c.tbl] = (byTbl[c.tbl] || 0) + 1));
    const summary = Object.entries(byTbl)
      .map(([t, n]) => `${FIELDMAPS.get(t).label}: ${n}`)
      .join('\n');
    if (confirm(`Diese Zeilen wurden serverseitig gelöscht und werden lokal entfernt:\n\n${summary}\n\nEntfernen?`)) {
      await call(api.sync.reconcileApply(cands));
      await refreshStats();
      renderNav();
      rerender();
      toast(`${cands.length} lokale Zeile(n) entfernt.`);
    }
  } catch (e) {
    toast(e.message, true);
  } finally {
    btn.disabled = false;
  }
}

/* ------------------------------------------------------------- navigation */

function renderNav() {
  navList.innerHTML = '';
  const stats = state.stats || { conflicts: 0, pending: 0, tables: [] };
  const countFor = (slug) => {
    const t = (stats.tables || []).find((x) => x.slug === slug);
    return t ? t : { count: 0, dirty: 0 };
  };

  const addItem = (name, label, opts = {}) => {
    const item = el('button', { class: 'nav-item' + (isCurrent(name, opts.slug) ? ' active' : '') });
    item.append(el('span', {}, label));
    if (opts.badge != null) {
      item.append(el('span', { class: 'badge' + (opts.badgeWarn ? ' warn' : '') }, String(opts.badge)));
    }
    item.addEventListener('click', opts.onClick);
    navList.append(item);
  };

  const meta = state.meta || { tables: {} };
  const has = (slug) => !!(meta.tables && meta.tables[slug]);
  const groupEl = (t) => navList.append(el('div', { class: 'nav-group' }, t));
  const tblItem = (slug, label) => {
    const c = countFor(slug);
    addItem('table', label || FIELDMAPS.get(slug).label, {
      slug, badge: c.count, badgeWarn: c.dirty > 0, onClick: () => showTable(slug),
    });
  };
  const isAdmin = () => can('manage_options') || can('can_manage');

  addItem('status', 'Übersicht', { onClick: showStatus });

  // ================= MITGLIED =================
  groupEl('Mitglied');
  if (has('wunschliste')) addItem('wunschliste', 'Wunschliste & Abstimmung', { onClick: () => showWunschliste() });
  if (has('pp_protokolle')) addItem('protokolle', 'Sitzungen & Protokolle', { onClick: showProtokolle });
  if (has('wl_shift_events')) addItem('schichtplaene', 'Schichtpläne', { onClick: showSchichtplaene });
  if (has('jb_auslagen') && can('jb_submit_auslagen')) {
    addItem('auslage_neu', 'Auslage einreichen', { onClick: () => showMeineAuslagen({ compose: true }) });
  }
  if (has('jb_auslagen')) {
    const c = countFor('jb_auslagen');
    addItem('meine_auslagen', isManager() ? 'Alle Auslagen' : 'Meine Auslagen', {
      slug: 'jb_auslagen', badge: c.count, badgeWarn: c.dirty > 0, onClick: () => showMeineAuslagen(),
    });
  }
  addItem('kassenbericht', 'Kassenbericht', { onClick: showKassenbericht });
  if (has('wp_members')) addItem('profil', 'Mein Profil', { onClick: showProfil });

  // ========== VORSTAND · Mitgliederverwaltung ==========
  if (can('vp_manage_members')) {
    groupEl('Vorstand · Mitgliederverwaltung');
    if (has('vp_antraege')) addItem('antraege', 'Mitgliedsanträge', { slug: 'vp_antraege', badge: countFor('vp_antraege').count, onClick: showAntraege });
    if (has('wp_members')) addItem('mitglieder', 'Mitglieder', { slug: 'wp_members', badge: countFor('wp_members').count, onClick: showMitglieder });
    addItem('newsletter', 'Newsletter', { onClick: showNewsletter });
  }

  // ============== VORSTAND · Kassier:in ==============
  if (can('jb_view_journal') || can('jb_approve_auslagen')) {
    groupEl('Vorstand · Kassier:in');
    if (can('jb_approve_auslagen')) addItem('auslagen_pruefen', 'Auslagen prüfen', { onClick: showAuslagenPruefen });
    if (has('jb_buchungen')) addItem('journal', 'Buchungsjournal', { slug: 'jb_buchungen', onClick: showJournal });
    if (has('jb_budgets')) tblItem('jb_budgets', 'Budgets');
    if (has('jb_ruecklagen')) tblItem('jb_ruecklagen', 'Rücklagen');
    if (has('jb_konten')) addItem('kontenplan', 'Kontenplan', { onClick: showKontenplan });
  }

  // ============== VORSTAND · Sonstige ==============
  const sonst = [];
  if (can('wl_manage_wishes') && has('wunschliste')) sonst.push(['wunsch_verwaltung', 'Wunschlistenverwaltung', showWunschverwaltung]);
  if (can('wl_manage_wishes') && has('wl_shift_events')) sonst.push(['schicht_verwaltung', 'Schichtplanverwaltung', showSchichtverwaltung]);
  if (isManager()) sonst.push(['nextcloud', 'Nextcloud-Sync', showNextcloud]);
  if (sonst.length) {
    groupEl('Vorstand · Sonstige');
    for (const [name, label, fn] of sonst) addItem(name, label, { onClick: fn });
  }

  // ================= ADMIN (Rohlisten) =================
  if (isAdmin()) {
    const admin = Object.keys(meta.tables || {}).sort((a, b) => FIELDMAPS.get(a).label.localeCompare(FIELDMAPS.get(b).label));
    if (admin.length) {
      groupEl('Admin · Rohdaten');
      admin.forEach((slug) => tblItem(slug));
    }
  }

  // ================= SYSTEM =================
  groupEl('System');
  addItem('conflicts', 'Konflikte', { badge: stats.conflicts || 0, badgeWarn: stats.conflicts > 0, onClick: showConflicts });
  addItem('settings', 'Einstellungen', { onClick: () => showSettings() });
}

function isCurrent(name, slug) {
  if (slug) return (state.current.name === 'table' || state.current.name === 'detail') && state.current.slug === slug;
  return state.current.name === name;
}

function rerender() {
  const c = state.current;
  if (c.name === 'status') showStatus();
  else if (c.name === 'conflicts') showConflicts();
  else if (c.name === 'table') showTable(c.slug);
  else if (c.name === 'detail') showDetail(c.slug, c.pk);
  else if (c.name === 'nextcloud') showNextcloud();
  else if (c.name === 'kassenbericht') showKassenbericht();
  else if (c.name === 'kontenplan') showKontenplan();
  else if (c.name === 'auslagen_pruefen') showAuslagenPruefen();
  else if (c.name === 'journal') showJournal();
  else if (c.name === 'mitglieder') showMitglieder();
  else if (c.name === 'antraege') showAntraege();
  else if (c.name === 'meine_auslagen') showMeineAuslagen();
  else if (c.name === 'profil') showProfil();
  else if (c.name === 'wunschliste') showWunschliste();
  else if (c.name === 'wunsch_verwaltung') showWunschverwaltung();
  else if (c.name === 'protokolle') showProtokolle({ sub: c.sub });
  else if (c.name === 'schichtplaene') showSchichtplaene();
  else if (c.name === 'schicht_verwaltung') showSchichtverwaltung();
  else if (c.name === 'newsletter') showNewsletter();
}

/* ------------------------------------------------------------- views */

function showStatus() {
  state.current = { name: 'status' };
  renderNav();
  const s = state.stats;
  view.innerHTML = '';
  view.append(el('h1', {}, 'Übersicht'));
  view.append(
    el('p', { class: 'sub' }, 'Lokaler Spiegel der Vereinsdaten. Bearbeiten funktioniert offline; „Synchronisieren“ gleicht mit WordPress ab.')
  );

  if (!s) {
    view.append(el('div', { class: 'note warn' }, 'Noch keine Daten. Auf „Synchronisieren“ klicken.'));
    return;
  }

  const g = el('div', { class: 'card' });
  g.append(el('div', { class: 'kv' },
    el('div', {}, 'Zuletzt geladen'), el('div', {}, fmt(s.last_pull)),
    el('div', {}, 'Letzter Vollabgleich'), el('div', {}, fmt(s.last_reconcile)),
    el('div', {}, 'Offene Änderungen (Warteschlange)'), el('div', {}, String(s.pending)),
    el('div', {}, 'Offene Konflikte'), el('div', {}, String(s.conflicts)),
  ));
  view.append(g);

  if (s.pending > 0) {
    view.append(el('div', { class: 'note warn' }, `${s.pending} lokale Änderung(en) noch nicht gesendet. „Synchronisieren“ überträgt sie.`));
  }
  if (s.conflicts > 0) {
    const n = el('div', { class: 'note err' });
    n.append(`${s.conflicts} Konflikt(e) warten auf Entscheidung. `);
    n.append(el('button', { class: 'small', onclick: showConflicts }, 'Öffnen'));
    view.append(n);
  }

  view.append(el('h2', {}, 'Datenbestand'));
  const tbl = el('table');
  tbl.append(el('thead', {}, el('tr', {}, el('th', {}, 'Bereich'), el('th', {}, 'Zeilen'), el('th', {}, 'Geändert'))));
  const tb = el('tbody');
  (s.tables || [])
    .slice()
    .sort((a, b) => FIELDMAPS.get(a.slug).label.localeCompare(FIELDMAPS.get(b.slug).label))
    .forEach((t) => {
      const tr = el('tr', { onclick: () => showTable(t.slug) });
      tr.append(el('td', {}, FIELDMAPS.get(t.slug).label));
      tr.append(el('td', {}, String(t.count)));
      tr.append(el('td', {}, t.dirty ? el('span', { class: 'tag' }, `${t.dirty} offen`) : '—'));
      tb.append(tr);
    });
  tbl.append(tb);
  view.append(tbl);
}

async function showTable(slug) {
  state.current = { name: 'table', slug };
  renderNav();
  const fm = FIELDMAPS.get(slug);
  view.innerHTML = '';
  view.append(el('h1', {}, fm.label));
  const sub = el('p', { class: 'sub' }, fm.editable ? 'Bearbeitbar – Änderungen werden beim nächsten Sync gesendet.' : 'Nur-Lesen-Spiegel.');
  view.append(sub);

  const search = el('input', { type: 'search', placeholder: 'Suchen…' });
  const toolbar = el('div', { class: 'toolbar' }, search);
  if (fm.editable) {
    toolbar.append(el('button', { class: 'primary small', onclick: () => showDetail(slug, null) }, '+ Neu'));
  }
  view.append(toolbar);
  const host = el('div', {});
  view.append(host);

  let timer = null;
  const load = async () => {
    host.classList.add('spin');
    try {
      const { columns, rows } = await call(api.data.rows(slug, { search: search.value.trim(), limit: 500 }));
      renderRows(host, slug, columns, rows);
    } catch (e) {
      host.innerHTML = '';
      host.append(el('div', { class: 'note err' }, e.message));
    } finally {
      host.classList.remove('spin');
    }
  };
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(load, 200);
  });
  load();
}

function renderRows(host, slug, columns, rows) {
  host.innerHTML = '';
  if (!rows.length) {
    host.append(el('div', { class: 'note' }, 'Keine Einträge.'));
    return;
  }
  const shown = columns.slice(0, 7);
  const tbl = el('table');
  tbl.append(el('thead', {}, el('tr', {}, ...shown.map((c) => el('th', {}, c)))));
  const tb = el('tbody');
  for (const r of rows) {
    const tr = el('tr', { class: r._dirty ? 'dirty' : '', onclick: () => showDetail(slug, r[pkOf(slug)]) });
    for (const c of shown) tr.append(el('td', {}, fmt(r[c])));
    tb.append(tr);
  }
  tbl.append(tb);
  host.append(tbl);
  host.append(el('p', { class: 'muted' }, `${rows.length} Zeile(n)${rows.length === 500 ? ' (Anzeige begrenzt)' : ''}`));
}

function pkOf(slug) {
  return (state.meta && state.meta.tables[slug] && state.meta.tables[slug].pk) || 'id';
}

async function showDetail(slug, pk) {
  state.current = { name: 'detail', slug, pk };
  const fm = FIELDMAPS.get(slug);
  const isNew = pk === null || pk === undefined;
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showTable(slug) }, '‹ Zurück'));
  view.append(el('h1', {}, isNew ? `Neu: ${fm.label}` : `${fm.label} bearbeiten`));

  let data = { row: {}, state: null, pkCol: pkOf(slug) };
  if (!isNew) {
    try {
      data = await call(api.data.row(slug, pk));
    } catch (e) {
      return view.append(el('div', { class: 'note err' }, e.message));
    }
  }
  const row = data.row || {};
  const cols = (state.meta.tables[slug] && state.meta.tables[slug].columns) || Object.keys(row);
  const writable =
    slug === 'wp_members'
      ? (state.meta.tables.wp_members.writable || [])
      : cols.filter((c) => c !== data.pkCol);

  // Dynamische Dropdown-Quellen (from:{slug}) + Vorschlagslisten (datalist) laden.
  const fieldDefs = cols.map((c) => (fm.fields && fm.fields[c]) || { label: c });
  const sourceSlugs = [...new Set(fieldDefs.filter((cf) => cf.from && cf.from.slug).map((cf) => cf.from.slug))];
  const needSuggest = fieldDefs.some((cf) => cf.type === 'datalist');
  const sources = {};
  let suggestRows = [];
  try {
    await Promise.all([
      ...sourceSlugs.map(async (s) => {
        sources[s] = (await call(api.data.rows(s, { limit: 2000 }))).rows;
      }),
      (async () => {
        if (needSuggest) suggestRows = (await call(api.data.rows(slug, { limit: 2000 }))).rows;
      })(),
    ]);
  } catch {
    /* Quellen optional – zur Not eben ohne Vorbelegung */
  }

  const form = el('form', { class: 'detail' });
  const inputs = {};
  for (const c of cols) {
    const cf = (fm.fields && fm.fields[c]) || { label: c };
    const editable = fm.editable && writable.includes(c) && !cf.readonly;
    const val = row[c] == null ? '' : String(row[c]);
    let input;

    if (cf.type === 'select') {
      input = el('select', {});
      const known = new Set();
      const opt = (v, label) => {
        const ov = String(v ?? '');
        known.add(ov);
        return el('option', { value: ov }, label);
      };

      const statics = (cf.options || []).slice();
      if (cf.from && cf.allowEmpty !== false && !statics.some((o) => o[0] === '')) statics.unshift(['', '—']);
      for (const [ov, ol] of statics) input.append(opt(ov, ol));

      if (cf.from && sources[cf.from.slug]) {
        const seen = new Set();
        let rows = sources[cf.from.slug].filter((r) => {
          const ov = String(r[cf.from.value] ?? '');
          if (ov === '' || seen.has(ov)) return false;
          seen.add(ov);
          return true;
        });
        const key = cf.from.sort || ((r) => String(cf.from.label(r)));
        rows.sort((a, b) => String(key(a)).localeCompare(String(key(b)), 'de', { numeric: true }));

        if (cf.from.group) {
          const groups = new Map();
          for (const r of rows) {
            const g = cf.from.group(r) || 'Sonstige';
            (groups.get(g) || groups.set(g, []).get(g)).push(r);
          }
          for (const [g, grows] of groups) {
            const og = el('optgroup', { label: g });
            for (const r of grows) og.append(opt(r[cf.from.value], String(cf.from.label(r))));
            input.append(og);
          }
        } else {
          for (const r of rows) input.append(opt(r[cf.from.value], String(cf.from.label(r))));
        }
      }

      // Aktuellen Wert erhalten, auch wenn er (noch) nicht in der Liste ist.
      if (val !== '' && !known.has(val)) input.insertBefore(opt(val, `${val} (aktuell)`), input.firstChild);
      input.value = val;
      if (!editable) input.setAttribute('disabled', 'disabled');
    } else if (cf.type === 'datalist') {
      const dlId = `dl_${slug}_${c}`;
      input = el('input', { type: 'text', value: val, list: dlId });
      const seen = new Set();
      const dl = el('datalist', { id: dlId });
      const add = (v) => {
        const s = String(v ?? '').trim();
        if (s && !seen.has(s)) {
          seen.add(s);
          dl.append(el('option', { value: s }));
        }
      };
      if (cf.from && sources[cf.from.slug]) {
        for (const r of sources[cf.from.slug]) add(cf.from.label(r));
      }
      for (const r of suggestRows) add(r[cf.suggest || c]);
      form.append(dl);
      if (!editable) input.setAttribute('readonly', 'readonly');
    } else if (cf.type === 'textarea') {
      input = el('textarea', {});
      input.value = val;
      if (!editable) input.setAttribute('readonly', 'readonly');
    } else {
      input = el('input', { type: cf.type || 'text', value: val });
      if (!editable) input.setAttribute('readonly', 'readonly');
    }

    inputs[c] = input;
    form.append(el('label', {}, cf.label || c), input);
  }

  if (!fm.editable) {
    view.append(el('div', { class: 'note' }, 'Dieser Bereich ist in dieser Version nur lesbar.'));
    view.append(form);
    return;
  }

  const actions = el('div', { class: 'form-actions' });
  const saveBtn = el('button', { class: 'primary', type: 'submit' }, isNew ? 'Anlegen' : 'Speichern');
  actions.append(saveBtn);
  if (!isNew && slug !== 'wp_members') {
    actions.append(
      el('button', { type: 'button', class: 'danger', onclick: () => removeRow(slug, pk) }, 'Löschen')
    );
  }
  if (data.state && data.state.dirty) {
    actions.append(el('span', { class: 'tag' }, 'lokal geändert, noch nicht gesendet'));
  }
  form.append(actions);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fields = {};
    for (const c of Object.keys(inputs)) {
      if (!writable.includes(c)) continue;
      fields[c] = inputs[c].value;
    }
    try {
      if (isNew) {
        const r = await call(api.data.create(slug, fields));
        toast('Angelegt (wird beim Sync gesendet).');
        await afterMutate();
        showDetail(slug, r.pk);
      } else {
        await call(api.data.save(slug, pk, fields));
        toast('Gespeichert (wird beim Sync gesendet).');
        await afterMutate();
        showDetail(slug, pk);
      }
    } catch (e) {
      toast(e.message, true);
    }
  });

  view.append(form);
}

async function removeRow(slug, pk) {
  if (!confirm('Diese Zeile löschen? Wird beim nächsten Sync auch auf dem Server gelöscht.')) return;
  try {
    await call(api.data.remove(slug, pk));
    toast('Zum Löschen vorgemerkt.');
    await afterMutate();
    showTable(slug);
  } catch (e) {
    toast(e.message, true);
  }
}

async function afterMutate() {
  await refreshStats();
  renderNav();
}

/* ------------------------------------------------------------- conflicts */

async function showConflicts() {
  state.current = { name: 'conflicts' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Konflikte'));
  view.append(el('p', { class: 'sub' }, 'Beide Seiten haben dieselbe Zeile geändert. Wähle, welche Version gilt.'));

  let list = [];
  try {
    list = await call(api.conflicts.list());
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  if (!list.length) {
    return view.append(el('div', { class: 'note ok' }, 'Keine offenen Konflikte.'));
  }

  for (const c of list) {
    const fm = FIELDMAPS.get(c.tbl);
    const card = el('div', { class: 'card' });
    card.append(el('h2', {}, `${fm.label} · #${c.pk}`));
    const local = c.local || {};
    const server = c.server || {};
    const keys = Array.from(new Set([...Object.keys(local), ...Object.keys(server)])).filter((k) => k !== '_rev');

    const diff = el('div', { class: 'diff' });
    diff.append(el('div', { class: 'h' }, 'Feld'), el('div', { class: 'h' }, 'Deine Version'), el('div', { class: 'h' }, 'Server'));
    for (const k of keys) {
      const lv = fmt(local[k]);
      const sv = fmt(server[k]);
      const changed = lv !== sv;
      diff.append(
        el('div', { class: changed ? 'changed' : '' }, k),
        el('div', { class: changed ? 'changed' : '' }, lv),
        el('div', { class: changed ? 'changed' : '' }, sv)
      );
    }
    card.append(diff);

    const act = el('div', { class: 'row', style: 'margin-top:12px' });
    act.append(
      el('button', { class: 'primary small', onclick: () => resolve(c.id, 'local') }, 'Meine übernehmen'),
      el('button', { class: 'small', onclick: () => resolve(c.id, 'server') }, 'Server übernehmen')
    );
    if (!c.server) {
      act.innerHTML = '';
      act.append(el('span', { class: 'muted' }, 'Server hat die Zeile gelöscht. '));
      act.append(
        el('button', { class: 'small', onclick: () => resolve(c.id, 'server') }, 'Lokal auch löschen'),
        el('button', { class: 'primary small', onclick: () => resolve(c.id, 'local') }, 'Neu anlegen (meine Version)')
      );
    }
    card.append(act);
    view.append(card);
  }
}

async function resolve(id, choice) {
  try {
    await call(api.conflicts.resolve(id, choice));
    toast(choice === 'local' ? 'Deine Version übernommen (wird beim Sync gesendet).' : 'Server-Version übernommen.');
    await refreshStats();
    renderNav();
    showConflicts();
  } catch (e) {
    toast(e.message, true);
  }
}

/* ------------------------------------------------------------- nextcloud */

async function showNextcloud() {
  state.current = { name: 'nextcloud' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Nextcloud'));
  view.append(el('p', { class: 'sub' }, 'Läuft über das Plugin auf dem Server – die App braucht keinen Nextcloud-Zugang.'));

  const card = el('div', { class: 'card' });
  const dry = el('input', { type: 'checkbox' });
  card.append(el('label', {}, dry, ' Nur Testlauf (zeigt an, ändert nichts)'));
  const runBtn = el('button', { class: 'primary', style: 'display:block;margin-top:10px' }, 'Benutzer & Gruppen synchronisieren');
  card.append(runBtn);
  const out = el('div', {});
  card.append(out);
  view.append(card);

  runBtn.addEventListener('click', async () => {
    runBtn.disabled = true;
    out.innerHTML = '';
    try {
      const report = await call(api.nc.sync(dry.checked));
      out.append(el('div', { class: 'note ok' }, dry.checked ? 'Testlauf abgeschlossen.' : 'Synchronisierung abgeschlossen.'));
      const blocks = [
        ['In WordPress angelegt', report.created_wp],
        ['In Nextcloud angelegt', report.created_nc],
        ['WP-Rolle angepasst', report.role_wp],
        ['NC-Gruppe ergänzt', report.group_nc],
        ['Nur in Nextcloud', report.only_nc],
        ['Nur in WordPress', report.only_wp],
        ['Fehler', report.errors],
      ];
      for (const [t, items] of blocks) {
        const arr = items || [];
        const d = el('details', { class: 'card' });
        d.append(el('summary', {}, `${t} (${arr.length})`));
        if (arr.length) d.append(el('pre', { class: 'report' }, arr.join('\n')));
        out.append(d);
      }
    } catch (e) {
      out.append(el('div', { class: 'note err' }, e.message));
    } finally {
      runBtn.disabled = false;
    }
  });
}

/* ------------------------------------------------------------- settings */

async function showSettings(hint) {
  state.current = { name: 'settings' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Einstellungen'));
  if (hint) view.append(el('div', { class: 'note warn' }, hint));

  const cfg = await call(api.config.get());
  const card = el('div', { class: 'card' });
  const form = el('form', { class: 'detail' });
  const url = el('input', { type: 'url', value: cfg.baseUrl || '', placeholder: 'https://verein.example' });
  const user = el('input', { type: 'text', value: cfg.user || '', placeholder: 'WordPress-Benutzername' });
  const pass = el('input', {
    type: 'password',
    placeholder: cfg.hasPass ? '•••••••• (gespeichert – leer lassen zum Behalten)' : 'Application Password',
    autocomplete: 'new-password',
  });
  form.append(
    el('label', {}, 'WordPress-URL'), url,
    el('label', {}, 'Benutzer'), user,
    el('label', {}, 'Application Password'), pass
  );
  const actions = el('div', { class: 'form-actions' });
  actions.append(
    el('button', { class: 'primary', type: 'submit' }, 'Speichern'),
    el('button', { type: 'button', class: 'ghost', onclick: testConn }, 'Verbindung testen')
  );
  form.append(actions);
  card.append(form);
  view.append(card);

  view.append(
    el('div', { class: 'card' },
      el('h2', {}, 'Hinweise'),
      el('p', { class: 'muted', html:
        'Das <b>Application Password</b> legst du in WordPress an: Profil → „Anwendungspasswörter“. ' +
        'Der Benutzer braucht die Rechte <code>vp_manage_members</code> oder <code>manage_options</code>.<br>' +
        'Die lokale Datenbank liegt unverschlüsselt im App-Ordner und enthält auch IBAN/SEPA/Geburtsdaten – ' +
        '<b>Festplattenverschlüsselung (FileVault/BitLocker) ist Pflicht.</b>' }))
  );

  async function persist() {
    await call(api.config.set({ baseUrl: url.value, user: user.value, pass: pass.value }));
  }
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await persist();
      pass.value = '';
      toast('Gespeichert.');
      navStatus.textContent = url.value.replace(/^https?:\/\//, '');
    } catch (e) {
      toast(e.message, true);
    }
  });
  async function testConn() {
    try {
      await persist();
      pass.value = '';
      const r = await call(api.config.test());
      toast(`Verbunden: Plugin ${r.plugin_version}, ${r.tables} Tabellen.`);
      await refreshMeta();
      await refreshStats();
      renderNav();
    } catch (e) {
      toast(e.message, true);
    }
  }
}

/* ------------------------------------------------------------- Kassenbericht */

const eur = (n) =>
  (Number(n) || 0).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });

async function showKassenbericht(year) {
  state.current = { name: 'kassenbericht', year };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Kassenbericht'));
  view.append(el('p', { class: 'sub' }, 'Live vom Server berechnet (Einnahmen-Überschuss-Rechnung).'));

  let data;
  try {
    data = await call(api.report.summary(year));
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message + ' – online sein und Recht „Buchungsjournal sehen“ nötig.'));
  }

  // Jahr-Umschalter
  const bar = el('div', { class: 'toolbar' });
  bar.append(el('span', { class: 'muted' }, 'Jahr:'));
  for (const y of data.years || [data.year]) {
    bar.append(
      el('button', { class: 'small' + (y === data.year ? ' primary' : ''), onclick: () => showKassenbericht(y) }, String(y))
    );
  }
  view.append(bar);

  // KPI-Kacheln
  const tiles = el('div', { class: 'tiles' });
  const tile = (label, value, cls) => tiles.append(el('div', { class: 'tile ' + (cls || '') }, el('div', { class: 'tile-v' }, value), el('div', { class: 'tile-l' }, label)));
  tile('Einnahmen ' + data.year, eur(data.total_einnahmen), 'pos');
  tile('Ausgaben ' + data.year, eur(data.total_ausgaben), 'neg');
  tile('Überschuss', eur(data.ueberschuss), data.ueberschuss >= 0 ? 'pos' : 'neg');
  tile('Journal-Saldo gesamt', eur(data.bestand && data.bestand.journal_saldo), '');
  view.append(tiles);

  // Geld-Töpfe
  if ((data.topfe || []).length) {
    const pt = el('div', { class: 'tiles' });
    for (const p of data.topfe) pt.append(el('div', { class: 'tile' }, el('div', { class: 'tile-v' }, eur(p.saldo)), el('div', { class: 'tile-l' }, p.label)));
    view.append(pt);
  }

  // Vereinsvermögen / Freies Budget (JuFo-Kassenbericht)
  const d = data.dashboard;
  if (d) {
    view.append(el('h2', {}, 'Aktueller Stand'));
    const kv = el('div', { class: 'kv' });
    const line = (k, v, strong) => {
      kv.append(el('div', {}, k));
      kv.append(el('div', strong ? { style: 'font-weight:700' } : {}, eur(v)));
    };
    line('Bankkonto', d.bank);
    line('Barkasse (gezählt)', d.kasse);
    line('Kontostand gesamt', d.kontostand, true);
    line('Getränke-Warenwert', d.getraenke_wert);
    line('− Offene Auslagen (genehmigt)', -d.offene_auslagen);
    line('− Rücklagenbedarf bis heute', -d.ruecklagen);
    line('− Verplantes Budget (Rest)', -d.verplantes);
    line('= Freies / verfügbares Budget', d.frei, true);
    view.append(el('div', { class: 'card' }, kv));
    view.append(el('p', { class: 'muted' }, 'Bank & Barkasse pflegst du im Plugin unter Buchhaltung → Kassenbericht; alles andere wird berechnet.'));
  }

  // Nach Sphäre – Balken
  if ((data.by_sphaere || []).length) {
    view.append(el('h2', {}, 'Nach Sphäre'));
    const max = Math.max(1, ...data.by_sphaere.flatMap((r) => [r.einnahmen, r.ausgaben]));
    const chart = el('div', { class: 'bars' });
    for (const r of data.by_sphaere) {
      const rowEl = el('div', { class: 'bar-row' });
      rowEl.append(el('div', { class: 'bar-label' }, r.label || r.sphaere));
      const track = el('div', { class: 'bar-track' });
      track.append(el('div', { class: 'bar pos', style: `width:${(r.einnahmen / max) * 100}%` }, r.einnahmen ? eur(r.einnahmen) : ''));
      track.append(el('div', { class: 'bar neg', style: `width:${(r.ausgaben / max) * 100}%` }, r.ausgaben ? eur(r.ausgaben) : ''));
      rowEl.append(track);
      chart.append(rowEl);
    }
    view.append(chart);
  }

  // Nach Konto – Tabelle
  view.append(el('h2', {}, 'Nach Konto / Kategorie'));
  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Konto'), el('th', {}, 'Bezeichnung'), el('th', {}, 'Kategorie'), el('th', {}, 'Einnahmen'), el('th', {}, 'Ausgaben'), el('th', {}, 'Anz.'))));
  const tb = el('tbody');
  for (const r of data.by_konto || []) {
    tb.append(el('tr', {},
      el('td', {}, r.konto), el('td', {}, r.name || '—'), el('td', {}, r.kategorie || '—'),
      el('td', {}, r.einnahmen ? eur(r.einnahmen) : '—'),
      el('td', {}, r.ausgaben ? eur(r.ausgaben) : '—'),
      el('td', {}, String(r.anzahl))));
  }
  t.append(tb);
  view.append(t);
  if (!data.has_skr) view.append(el('p', { class: 'muted' }, 'Hinweis: SKR-Konten-Spalten nicht vorhanden – Auswertung nur nach Kategorie.'));
}

/* --------------------------------------------------------- Kontenplan */

async function showKontenplan() {
  state.current = { name: 'kontenplan' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Kontenplan'));
  view.append(el('p', { class: 'sub' }, 'SKR-49-Konten mit ihren bisherigen Umsätzen, oben die Geld-Töpfe mit Saldo.'));

  let report = null;
  let konten = [];
  try {
    [report, konten] = await Promise.all([
      call(api.report.summary()).catch(() => null),
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }

  if (report && (report.topfe || []).length) {
    const pt = el('div', { class: 'tiles' });
    for (const p of report.topfe) pt.append(el('div', { class: 'tile' }, el('div', { class: 'tile-v' }, eur(p.saldo)), el('div', { class: 'tile-l' }, p.label)));
    view.append(pt);
  }

  const um = (report && report.by_konto_all) || {};
  const groups = { einnahme: 'Einnahmen / Erträge', ausgabe: 'Ausgaben / Aufwand', '': 'Bank / Kasse / Sonstige' };
  const byTyp = { einnahme: [], ausgabe: [], '': [] };
  for (const k of konten.slice().sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }))) {
    byTyp[k.typ === 'einnahme' || k.typ === 'ausgabe' ? k.typ : ''].push(k);
  }
  for (const [typ, label] of Object.entries(groups)) {
    if (!byTyp[typ].length) continue;
    view.append(el('h2', {}, label));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Nr'), el('th', {}, 'Bezeichnung'), el('th', {}, 'Sphäre'),
      el('th', { style: 'text-align:right' }, 'Einnahmen'), el('th', { style: 'text-align:right' }, 'Ausgaben'),
      el('th', { style: 'text-align:right' }, 'Saldo'), el('th', { style: 'text-align:right' }, 'Buchungen'))));
    const tbb = el('tbody');
    for (const k of byTyp[typ]) {
      const u = um[String(k.nummer)] || { einnahmen: 0, ausgaben: 0, anzahl: 0 };
      const saldo = u.einnahmen - u.ausgaben;
      tbb.append(el('tr', { class: String(k.aktiv) === '0' ? 'muted' : '' },
        el('td', {}, k.nummer), el('td', {}, k.bezeichnung), el('td', {}, k.sphaere || '—'),
        el('td', { style: 'text-align:right' }, u.einnahmen ? eur(u.einnahmen) : '—'),
        el('td', { style: 'text-align:right' }, u.ausgaben ? eur(u.ausgaben) : '—'),
        el('td', { style: 'text-align:right;font-weight:600' }, u.anzahl ? eur(saldo) : '—'),
        el('td', { style: 'text-align:right' }, String(u.anzahl || ''))));
    }
    t.append(tbb);
    view.append(t);
  }
  view.append(el('p', { class: 'muted', style: 'margin-top:12px' },
    el('button', { class: 'small', onclick: () => showTable('jb_konten') }, 'Konten bearbeiten')));
}

/* --------------------------------------------------------- Auslagen prüfen */

async function showAuslagenPruefen() {
  state.current = { name: 'auslagen_pruefen' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Auslagen prüfen'));
  view.append(el('p', { class: 'sub' }, 'Aktionen wirken sofort auf dem Server (Online-Verbindung nötig).'));

  let rows;
  try {
    rows = (await call(api.data.rows('jb_auslagen', { limit: 1000 }))).rows;
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const badge = (s) => el('span', { class: 'st st-' + s }, s);
  const groups = [
    ['Offen', (r) => ['ausstehend', 'beleg'].includes(r.status)],
    ['Genehmigt (noch nicht ausgezahlt)', (r) => r.status === 'genehmigt'],
    ['Erledigt', (r) => ['ausgezahlt', 'abgelehnt'].includes(r.status)],
  ];

  for (const [title, pred] of groups) {
    const list = rows.filter(pred);
    view.append(el('h2', {}, `${title} (${list.length})`));
    if (!list.length) {
      view.append(el('p', { class: 'muted' }, '—'));
      continue;
    }
    for (const r of list) {
      const card = el('div', { class: 'card auslage' });
      const head = el('div', { class: 'row', style: 'justify-content:space-between' });
      head.append(el('strong', {}, `#${r.id} · ${eur(r.betrag)}`));
      head.append(badge(r.status));
      card.append(head);
      card.append(el('div', { class: 'kv' },
        el('div', {}, 'Mitglied'), el('div', {}, r.user_name || r.user_id || '—'),
        el('div', {}, 'Datum'), el('div', {}, r.ausgabe_datum || '—'),
        el('div', {}, 'Kategorie'), el('div', {}, r.kategorie || '—'),
        el('div', {}, 'Zweck'), el('div', {}, r.beschreibung || '—'),
        el('div', {}, 'Beleg'), el('div', {}, r.beleg_name || (r.beleg_pfad ? 'vorhanden' : '—'))));

      const act = el('div', { class: 'row', style: 'margin-top:10px' });
      if (['ausstehend', 'beleg'].includes(r.status)) {
        act.append(
          el('button', { class: 'primary small', onclick: () => auslageAction(r.id, 'auslage-decide', { id: r.id, approve: true }) }, 'Genehmigen'),
          el('button', { class: 'danger small', onclick: () => auslageAblehnen(r.id) }, 'Ablehnen')
        );
      } else if (r.status === 'genehmigt') {
        act.append(el('button', { class: 'primary small', onclick: () => auslageAction(r.id, 'auslage-auszahlen', { id: r.id }) }, 'Als ausgezahlt markieren'));
      }
      if (r.beleg_pfad) {
        act.append(el('button', { class: 'small', onclick: () => openBeleg(r.beleg_pfad) }, 'Beleg öffnen'));
      }
      if (act.children.length) card.append(act);
      view.append(card);
    }
  }
}

async function auslageAction(id, name, body) {
  try {
    const r = await call(api.action.run(name, body));
    toast(r && r.buchung_id ? `Erledigt – Journalbuchung #${r.buchung_id} angelegt.` : 'Erledigt.');
    await runSyncQuiet();
    showAuslagenPruefen();
  } catch (e) {
    toast(e.message, true);
  }
}
function auslageAblehnen(id) {
  const notiz = prompt('Grund der Ablehnung (optional):', '');
  if (notiz === null) return;
  auslageAction(id, 'auslage-decide', { id, approve: false, notiz });
}
async function openBeleg(path) {
  try {
    const r = await call(api.nc.beleg(path));
    if (r && r.url) await api.app.openExternal(r.url);
    else toast('Kein Download-Link erhalten.', true);
  } catch (e) {
    toast(e.message, true);
  }
}
async function runSyncQuiet() {
  try {
    await call(api.sync.run());
    await refreshStats();
    renderNav();
  } catch {
    /* ignore */
  }
}

/* ------------------------------------------------------------- Buchungsjournal */

const SPHAERE_OPTS = [
  ['', '—'], ['ideell', 'Ideeller Bereich'], ['zweckbetrieb', 'Zweckbetrieb'],
  ['vermoegen', 'Vermögensverwaltung'], ['wirtschaftlich', 'Wirtschaftl. Geschäftsbetrieb'], ['neutral', 'Neutral / Bestand'],
];
const QUELLE_OPTS = ['Bank KSK', 'Zettle-Bar', 'Zettle-Karte', 'PayPal', 'Auslage', 'Umbuchung', 'Manuell'];

function selectEl(options, value, attrs = {}) {
  const s = el('select', attrs);
  for (const o of options) {
    const [v, l] = Array.isArray(o) ? o : [o, o];
    s.append(el('option', { value: v }, l));
  }
  s.value = value == null ? '' : String(value);
  return s;
}

/** Konto-Dropdown, gruppiert nach Typ. `mode`: 'alle' | 'kategorie' (4xxx/5xxx) | 'bestand' (1xxx). */
function kontoSelect(konten, value, mode = 'alle', attrs = {}) {
  const s = el('select', attrs);
  s.append(el('option', { value: '' }, '—'));
  const groups = { 'Einnahmen / Erträge': [], 'Ausgaben / Aufwand': [], 'Bank / Kasse / Sonstige': [] };
  const rows = (konten || [])
    .filter((k) => {
      if (mode === 'kategorie') return k.typ === 'einnahme' || k.typ === 'ausgabe';
      if (mode === 'bestand') return k.typ !== 'einnahme' && k.typ !== 'ausgabe';
      return true;
    })
    .sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }));
  for (const k of rows) {
    const g = k.typ === 'einnahme' ? 'Einnahmen / Erträge' : k.typ === 'ausgabe' ? 'Ausgaben / Aufwand' : 'Bank / Kasse / Sonstige';
    groups[g].push(k);
  }
  let cur = String(value ?? '');
  let has = cur === '';
  for (const [gl, gr] of Object.entries(groups)) {
    if (!gr.length) continue;
    const og = el('optgroup', { label: gl });
    for (const k of gr) {
      og.append(el('option', { value: String(k.nummer) }, `${k.nummer} – ${k.bezeichnung}`));
      if (String(k.nummer) === cur) has = true;
    }
    s.append(og);
  }
  if (!has) s.insertBefore(el('option', { value: cur }, `${cur} (aktuell)`), s.firstChild);
  s.value = cur;
  return s;
}

const jState = { year: '', konto: '', sphaere: '', quelle: '', q: '', panel: null };

async function showJournal() {
  state.current = { name: 'journal', slug: 'jb_buchungen' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Buchungsjournal'));
  view.append(el('p', { class: 'sub' }, 'EÜR-Journal mit laufendem Saldo. Neue Buchungen gehen sofort online.'));

  let rows = [];
  let konten = [];
  try {
    [rows, konten] = await Promise.all([
      call(api.data.rows('jb_buchungen', { limit: 5000 })).then((r) => r.rows),
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows).catch(() => []),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }

  // Aktionen
  const acts = el('div', { class: 'toolbar' });
  acts.append(
    el('button', { class: 'primary small', onclick: () => togglePanel('add', () => journalForm(konten)) }, '+ Buchung'),
    el('button', { class: 'small', onclick: () => togglePanel('transfer', () => umbuchungForm(konten)) }, '⇄ Umbuchung'),
    el('button', { class: 'small', onclick: () => togglePanel('csv', () => bankCsvForm(konten)) }, '⇑ Bank-CSV importieren')
  );
  view.append(acts);
  const panelHost = el('div', {});
  view.append(panelHost);
  jState.panelHost = panelHost;
  jState.panel = null;

  // Filter
  const years = [...new Set(rows.map((r) => String(r.buchung_datum || '').slice(0, 4)).filter(Boolean))].sort().reverse();
  const fb = el('div', { class: 'toolbar' });
  const yS = selectEl([['', 'Alle Jahre'], ...years.map((y) => [y, y])], jState.year, { onchange: (e) => ((jState.year = e.target.value), draw()) });
  const kS = kontoSelect(konten, jState.konto, 'alle', { onchange: (e) => ((jState.konto = e.target.value), draw()) });
  const sS = selectEl([['', 'Alle Sphären'], ...SPHAERE_OPTS.slice(1)], jState.sphaere, { onchange: (e) => ((jState.sphaere = e.target.value), draw()) });
  const search = el('input', { type: 'search', placeholder: 'Text…', value: jState.q });
  search.addEventListener('input', (e) => ((jState.q = e.target.value), draw()));
  fb.append('Jahr:', yS, 'Konto:', kS, sS, search);
  view.append(fb);

  const tableHost = el('div', {});
  view.append(tableHost);

  function draw() {
    let list = rows.slice().sort((a, b) => String(a.buchung_datum).localeCompare(String(b.buchung_datum)) || Number(a.id) - Number(b.id));
    const f = list.filter((r) => {
      if (jState.year && String(r.buchung_datum || '').slice(0, 4) !== jState.year) return false;
      if (jState.konto && String(r.konto) !== jState.konto) return false;
      if (jState.sphaere && String(r.sphaere) !== jState.sphaere) return false;
      if (jState.q) {
        const hay = `${r.beschreibung} ${r.kategorie} ${r.gegenpartei} ${r.beleg_nr}`.toLowerCase();
        if (!hay.includes(jState.q.toLowerCase())) return false;
      }
      return true;
    });
    let saldo = 0;
    const kmap = Object.fromEntries((konten || []).map((k) => [String(k.nummer), k.bezeichnung]));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {},
      el('th', {}, 'Datum'), el('th', {}, 'Beschreibung'), el('th', {}, 'Konto'), el('th', {}, 'Sphäre'),
      el('th', {}, 'Quelle'), el('th', { style: 'text-align:right' }, 'Betrag'), el('th', { style: 'text-align:right' }, 'Saldo'))));
    const tb = el('tbody');
    for (const r of f) {
      const b = Number(r.betrag) || 0;
      saldo += b;
      tb.append(el('tr', { class: r._dirty ? 'dirty' : '' },
        el('td', {}, r.buchung_datum),
        el('td', {}, (r.beleg_nr ? `[${r.beleg_nr}] ` : '') + (r.beschreibung || r.kategorie || '')),
        el('td', {}, r.konto ? `${r.konto} ${kmap[r.konto] ? '– ' + kmap[r.konto] : ''}` : (r.kategorie || '—')),
        el('td', {}, r.sphaere || '—'),
        el('td', {}, r.quelle || '—'),
        el('td', { style: 'text-align:right;color:' + (b < 0 ? 'var(--err-ink)' : 'var(--accent)') }, eur(b)),
        el('td', { style: 'text-align:right' }, eur(saldo))));
    }
    t.append(tb);
    tableHost.innerHTML = '';
    tableHost.append(t);
    tableHost.append(el('p', { class: 'muted' }, `${f.length} Buchung(en) · Saldo gefiltert: ${eur(saldo)}`));
  }
  draw();
}

function togglePanel(kind, builder) {
  const host = jState.panelHost;
  if (!host) return;
  if (jState.panel === kind) {
    host.innerHTML = '';
    jState.panel = null;
    return;
  }
  host.innerHTML = '';
  jState.panel = kind;
  host.append(builder());
}

function journalForm(konten) {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Neue Buchung'));
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = el('input', { type: 'number', step: '0.01', placeholder: 'negativ = Ausgabe' });
  const konto = kontoSelect(konten, '', 'kategorie', {
    onchange: (e) => {
      const k = (konten || []).find((x) => String(x.nummer) === e.target.value);
      if (k && k.sphaere) sph.value = k.sphaere;
      if (k) betragHint(k);
    },
  });
  const sph = selectEl(SPHAERE_OPTS, '');
  const kat = el('input', { type: 'text', placeholder: 'Kategorie / Zweck' });
  const quelle = selectEl(QUELLE_OPTS, 'Bank KSK');
  const gegen = el('input', { type: 'text', placeholder: 'Gegenpartei (optional)' });
  const beschr = el('textarea', { placeholder: 'Beschreibung' });
  const hint = el('div', { class: 'muted' });
  function betragHint(k) {
    hint.textContent = k.typ === 'einnahme' ? 'Einnahme → Betrag positiv.' : k.typ === 'ausgabe' ? 'Ausgabe → Betrag negativ.' : '';
  }
  const f = el('form', { class: 'detail' });
  f.append('Datum', datum, 'Betrag (€)', betrag, 'Konto (SKR)', konto, 'Sphäre', sph, 'Kategorie', kat, 'Quelle', quelle, 'Gegenpartei', gegen, 'Beschreibung', beschr);
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Buchen'), hint);
  f.append(actions);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const amt = parseFloat(String(betrag.value).replace(',', '.'));
    if (!amt) return toast('Betrag fehlt.', true);
    try {
      await call(api.action.run('journal-add', {
        buchung_datum: datum.value, betrag: amt, konto: konto.value, sphaere: sph.value,
        kategorie: kat.value || (konten.find((k) => String(k.nummer) === konto.value) || {}).bezeichnung || 'Sonstige',
        quelle: quelle.value, gegenpartei: gegen.value, beschreibung: beschr.value,
      }));
      toast('Gebucht.');
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  });
  card.append(f);
  return card;
}

function umbuchungForm(konten) {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Umbuchung (zwei Zeilen, neutral)'));
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = el('input', { type: 'number', step: '0.01', min: '0', placeholder: 'Betrag > 0' });
  const von = kontoSelect(konten, '', 'bestand');
  const nach = kontoSelect(konten, '', 'bestand');
  const zweck = el('input', { type: 'text', value: 'Umbuchung' });
  const f = el('form', { class: 'detail' });
  f.append('Datum', datum, 'Betrag (€)', betrag, 'Von Konto', von, 'Nach Konto', nach, 'Zweck', zweck);
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Umbuchen'),
    el('span', { class: 'muted' }, 'legt −Betrag auf „Von" und +Betrag auf „Nach" an'));
  f.append(actions);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const amt = Math.abs(parseFloat(String(betrag.value).replace(',', '.')) || 0);
    if (!amt || !von.value || !nach.value || von.value === nach.value) return toast('Betrag und zwei verschiedene Konten nötig.', true);
    const lbl = (n) => `${n} ${((konten.find((k) => String(k.nummer) === n) || {}).bezeichnung) || ''}`.trim();
    try {
      for (const [k, sign, other] of [[von.value, -1, nach.value], [nach.value, +1, von.value]]) {
        await call(api.action.run('journal-add', {
          buchung_datum: datum.value, betrag: sign * amt, konto: k, sphaere: 'neutral',
          kategorie: zweck.value || 'Umbuchung', quelle: 'Umbuchung', gegenpartei: lbl(other), beschreibung: zweck.value || 'Umbuchung',
        }));
      }
      toast('Umbuchung gebucht (2 Zeilen).');
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message + ' – evtl. wurde nur eine der zwei Zeilen gebucht, bitte Journal prüfen.', true);
    }
  });
  card.append(f);
  return card;
}

function bankCsvForm(konten) {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Bank-CSV importieren'));
  const ta = el('textarea', { placeholder: 'CSV-Inhalt hier einfügen (Sparkasse: Umsätze → CSV-CAMT)', style: 'min-height:120px' });
  const delim = selectEl([[';', '; (Sparkasse)'], [',', ',']], ';');
  const previewBtn = el('button', { class: 'small', type: 'button' }, 'Vorschau');
  const out = el('div', {});
  card.append(el('form', { class: 'detail' }, 'CSV', ta, 'Trenner', delim), el('div', { class: 'form-actions' }, previewBtn), out);

  let parsed = [];
  previewBtn.addEventListener('click', async () => {
    out.innerHTML = '';
    try {
      const r = await call(api.action.run('bank-csv', { csv: ta.value, delim: delim.value }));
      parsed = r.rows || [];
      if (!parsed.length) return out.append(el('div', { class: 'note warn' }, 'Keine verwertbaren Zeilen erkannt.'));
      const t = el('table');
      t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Betrag'), el('th', {}, 'Name / Zweck'), el('th', {}, 'Konto (Kategorie)'))));
      const tb = el('tbody');
      parsed.forEach((row, i) => {
        const sel = kontoSelect(konten, row.konto || '', 'kategorie', { onchange: (e) => (parsed[i].konto = e.target.value) });
        tb.append(el('tr', {},
          el('td', {}, row.datum),
          el('td', { style: 'text-align:right;color:' + (row.betrag < 0 ? 'var(--err-ink)' : 'var(--accent)') }, eur(row.betrag)),
          el('td', {}, `${row.name || ''} — ${row.zweck || ''}`),
          el('td', {}, sel)));
      });
      t.append(tb);
      out.append(t);
      const imp = el('button', { class: 'primary', type: 'button' }, `${parsed.length} Buchung(en) importieren`);
      imp.addEventListener('click', async () => {
        imp.disabled = true;
        try {
          const res = await call(api.action.run('bank-csv', { import: true, rows: parsed }));
          toast(`${res.imported} Buchung(en) importiert.`);
          await runSyncQuiet();
          showJournal();
        } catch (e) {
          toast(e.message, true);
          imp.disabled = false;
        }
      });
      out.append(el('div', { class: 'form-actions' }, imp));
    } catch (e) {
      out.append(el('div', { class: 'note err' }, e.message));
    }
  });
  return card;
}

/* ------------------------------------------------------------- Mitglieder / Anträge (Karten) */

async function showMitglieder() {
  state.current = { name: 'mitglieder', slug: 'wp_members' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, isManager() ? 'Mitglieder' : 'Mein Profil'));
  const search = el('input', { type: 'search', placeholder: 'Suchen…' });
  view.append(el('div', { class: 'toolbar' }, search));
  const host = el('div', {});
  view.append(host);

  let rows = [];
  try {
    rows = (await call(api.data.rows('wp_members', { limit: 2000 }))).rows;
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const draw = () => {
    const q = search.value.trim().toLowerCase();
    const f = rows.filter((r) => !q || `${r.display_name} ${r.user_email} ${r.vp_ort} ${r.first_name} ${r.last_name}`.toLowerCase().includes(q));
    host.innerHTML = '';
    const grid = el('div', { class: 'grid-cards' });
    for (const r of f) {
      const c = el('div', { class: 'card' + (r._dirty ? ' dirty' : ''), onclick: () => showDetail('wp_members', String(r.id)) });
      c.append(el('strong', {}, r.display_name || `${r.first_name} ${r.last_name}`.trim() || r.user_login));
      c.append(el('div', { class: 'muted', style: 'font-size:12px;margin:4px 0' }, r.roles || ''));
      c.append(el('div', {}, r.user_email || '—'));
      if (r.vp_telefon) c.append(el('div', {}, '☎ ' + r.vp_telefon));
      if (r.vp_ort) c.append(el('div', { class: 'muted' }, `${r.vp_plz || ''} ${r.vp_ort}`));
      grid.append(c);
    }
    host.append(grid);
    host.append(el('p', { class: 'muted' }, `${f.length} Mitglied(er)`));
  };
  search.addEventListener('input', draw);
  draw();
}

async function showAntraege() {
  state.current = { name: 'antraege', slug: 'vp_antraege' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Mitgliedsanträge'));
  view.append(el('p', { class: 'sub' }, '„Annehmen" legt online den WordPress-Benutzer an und verschickt die Zugangsdaten.'));

  let rows = [];
  try {
    rows = (await call(api.data.rows('vp_antraege', { limit: 1000 }))).rows;
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const order = { neu: 0, angenommen: 1, abgelehnt: 2 };
  rows.sort((a, b) => (order[a.status] ?? 9) - (order[b.status] ?? 9) || String(b.created_at).localeCompare(String(a.created_at)));
  if (!rows.length) return view.append(el('div', { class: 'note' }, 'Keine Anträge.'));

  for (const r of rows) {
    const card = el('div', { class: 'card' });
    const head = el('div', { class: 'row', style: 'justify-content:space-between' });
    head.append(el('strong', {}, `${r.vorname} ${r.nachname}`));
    head.append(el('span', { class: 'st st-' + (r.status === 'neu' ? 'ausstehend' : r.status) }, r.status));
    card.append(head);
    card.append(el('div', { class: 'kv' },
      el('div', {}, 'E-Mail'), el('div', {}, r.email || '—'),
      el('div', {}, 'Eingang'), el('div', {}, (r.created_at || '').slice(0, 10)),
      el('div', {}, 'Beitrag'), el('div', {}, r.beitrag ? `${eur(r.beitrag)} ${r.beitrag_intervall || ''}` : '—'),
      el('div', {}, 'IBAN'), el('div', {}, r.sepa_iban || '—'),
      el('div', {}, 'Nachricht'), el('div', {}, r.nachricht || '—')));
    if (r.status === 'neu') {
      const act = el('div', { class: 'row', style: 'margin-top:10px' });
      act.append(
        el('button', { class: 'primary small', onclick: () => antragDecide(r.id, 'annehmen') }, 'Annehmen'),
        el('button', { class: 'danger small', onclick: () => antragDecide(r.id, 'ablehnen') }, 'Ablehnen')
      );
      card.append(act);
    } else if (r.notiz) {
      card.append(el('div', { class: 'muted', style: 'margin-top:6px' }, 'Notiz: ' + r.notiz));
    }
    view.append(card);
  }
}

async function antragDecide(id, action) {
  const notiz = action === 'ablehnen' ? prompt('Grund (optional, wird dem Antragsteller gemailt):', '') : '';
  if (action === 'ablehnen' && notiz === null) return;
  try {
    const r = await call(api.action.run('antrag-decide', { id, action, notiz: notiz || '' }));
    toast(r.message || 'Erledigt.');
    await runSyncQuiet();
    showAntraege();
  } catch (e) {
    toast(e.message, true);
  }
}

/* ------------------------------------------------------------- Meine Auslagen */

async function showMeineAuslagen(opts = {}) {
  state.current = { name: 'meine_auslagen', slug: 'jb_auslagen' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, isManager() ? 'Alle Auslagen' : 'Meine Auslagen'));

  if (can('jb_submit_auslagen')) {
    view.append(el('div', { class: 'toolbar' },
      el('button', { class: 'primary small', onclick: () => togglePanel2(auslageEinreichenForm) }, '+ Auslage einreichen')));
  }
  const panel = el('div', {});
  view.append(panel);
  state._panel2host = panel;
  if (opts.compose && can('jb_submit_auslagen')) togglePanel2(auslageEinreichenForm);

  let rows = [];
  try {
    rows = (await call(api.data.rows('jb_auslagen', { limit: 2000 }))).rows;
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  if (!rows.length) return view.append(el('div', { class: 'note' }, 'Noch keine Auslagen.'));
  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, '#'), el('th', {}, 'Datum'), el('th', {}, 'Betrag'), el('th', {}, 'Kategorie'), el('th', {}, 'Zweck'), el('th', {}, 'Status'))));
  const tb = el('tbody');
  for (const r of rows.sort((a, b) => Number(b.id) - Number(a.id))) {
    tb.append(el('tr', { onclick: () => showDetail('jb_auslagen', String(r.id)) },
      el('td', {}, r.id), el('td', {}, r.ausgabe_datum), el('td', {}, eur(r.betrag)),
      el('td', {}, r.kategorie || '—'), el('td', {}, r.beschreibung || '—'),
      el('td', {}, el('span', { class: 'st st-' + r.status }, r.status))));
  }
  t.append(tb);
  view.append(t);
}

function togglePanel2(builder) {
  const h = state._panel2host;
  if (!h) return;
  if (h.dataset.open) {
    h.innerHTML = '';
    delete h.dataset.open;
  } else {
    h.innerHTML = '';
    h.dataset.open = '1';
    h.append(builder());
  }
}

function auslageEinreichenForm() {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Auslage einreichen'));
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = el('input', { type: 'number', step: '0.01', min: '0' });
  const kat = el('input', { type: 'text', placeholder: 'z. B. Material' });
  const beschr = el('textarea', { placeholder: 'Wofür war die Ausgabe?' });
  const modus = selectEl([['erstattung', 'Erstattung (Geld zurück)'], ['beleg', 'Nur Beleg archivieren']], 'erstattung');
  const file = el('input', { type: 'file', accept: 'image/*,application/pdf' });
  const f = el('form', { class: 'detail' });
  f.append('Datum', datum, 'Betrag (€)', betrag, 'Kategorie', kat, 'Beschreibung', beschr, 'Art', modus, 'Beleg (Foto/PDF)', file);
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Einreichen'));
  f.append(actions);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const amt = parseFloat(String(betrag.value).replace(',', '.'));
    if (!amt || !beschr.value.trim()) return toast('Betrag und Beschreibung nötig.', true);
    let fileArg = null;
    if (file.files && file.files[0]) {
      const fobj = file.files[0];
      fileArg = { name: fobj.name, buffer: new Uint8Array(await fobj.arrayBuffer()) };
    }
    try {
      await call(api.action.auslageEinreichen(
        { ausgabe_datum: datum.value, betrag: amt, kategorie: kat.value || 'Sonstige Ausgaben', beschreibung: beschr.value, modus: modus.value },
        fileArg
      ));
      toast('Auslage eingereicht.');
      await runSyncQuiet();
      showMeineAuslagen();
    } catch (e) {
      toast(e.message, true);
    }
  });
  card.append(f);
  return card;
}

/* ------------------------------------------------------------- Mein Profil */

async function showProfil() {
  if (!state.me || !state.me.id) {
    await refreshMe(); // zweiter Versuch (z. B. nach frischem Start / Verbindung)
  }
  const myId = state.me && state.me.id;
  if (!myId) {
    state.current = { name: 'profil' };
    renderNav();
    view.innerHTML = '';
    view.append(el('h1', {}, 'Mein Profil'));
    view.append(el('div', { class: 'note warn' }, 'Konnte die eigene Benutzer-ID nicht vom Server holen. Prüfe unter „Einstellungen“ die Verbindung – App-Passwort und URL (ohne /wp-admin) müssen stimmen.'));
    view.append(el('button', { class: 'small', onclick: showProfil }, 'Nochmal versuchen'));
    return;
  }
  showDetail('wp_members', String(myId));
}

/* =========================================================== Wunschliste / Abstimmung */

const STUFE_LABEL = {
  1: 'Braucht der Verein', 2: 'Wünsche ich mir', 3: 'Egal', 4: 'Braucht der Verein nicht', 5: 'Veto',
};
const myVoterKey = () => (state.me && state.me.id ? 'u' + state.me.id : '');

async function loadWuensche() {
  const [w, v, g] = await Promise.all([
    call(api.data.rows('wunschliste', { limit: 2000 })).then((r) => r.rows),
    call(api.data.rows('wl_votes', { limit: 20000 })).then((r) => r.rows).catch(() => []),
    call(api.data.rows('pp_gremien', { limit: 500 })).then((r) => r.rows).catch(() => []),
  ]);
  const byWish = {};
  for (const vote of v) (byWish[vote.wunsch_id] ||= []).push(vote);
  const gname = Object.fromEntries(g.map((x) => [String(x.id), x.name]));
  const mk = myVoterKey();
  const list = w.map((x) => {
    const votes = byWish[x.id] || [];
    const nums = votes.map((z) => Number(z.stufe)).filter((n) => n >= 1 && n <= 5);
    const avg = nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : null;
    const n1 = votes.filter((z) => Number(z.stufe) === 1).length;
    const n2 = votes.filter((z) => Number(z.stufe) === 2).length;
    const nv = votes.filter((z) => Number(z.stufe) === 5).length;
    return {
      ...x,
      votes,
      count: votes.length,
      avg,
      pop: n1 * 2 + n2 - nv * 5, // Beliebtheit
      veto: nv > 0,
      kreis: x.gremium_id ? gname[String(x.gremium_id)] || '' : '',
      mine: votes.find((z) => z.voter_key === mk) || null,
    };
  });
  list._gremien = g;
  return list;
}

function wunschCard(x, { withVote }) {
  const card = el('div', { class: 'card' });
  const head = el('div', { class: 'row', style: 'justify-content:space-between' });
  head.append(el('strong', {}, x.titel));
  head.append(el('span', { class: 'st st-' + (x.status === 'erfuellt' ? 'ausgezahlt' : x.status === 'in_bearbeitung' ? 'genehmigt' : 'ausstehend') }, x.status));
  card.append(head);
  if (x.bild_url) card.append(el('img', { src: x.bild_url, style: 'max-height:120px;border-radius:6px;margin:6px 0' }));
  const preis = x.preis_von || x.preis_bis ? `${eur(x.preis_von || 0)}–${eur(x.preis_bis || 0)}` : x.betrag ? eur(x.betrag) : '—';
  card.append(el('div', { class: 'kv' },
    el('div', {}, 'Kategorie'), el('div', {}, x.kategorie || '—'),
    el('div', {}, 'Kosten'), el('div', {}, preis),
    el('div', {}, 'Beschreibung'), el('div', {}, x.beschreibung || '—'),
    el('div', {}, 'Abstimmung'), el('div', {}, x.count ? `Ø ${x.avg.toFixed(1)} aus ${x.count}${x.veto ? ' · ⛔ Veto' : ''}` : 'noch keine Stimmen')));
  if (withVote) {
    const box = el('div', { class: 'vote', style: 'margin-top:10px' });
    for (let s = 1; s <= 5; s++) {
      const active = x.mine && Number(x.mine.stufe) === s;
      box.append(el('button', { class: 'small' + (active ? ' primary' : ''), onclick: () => castVote(x, s) }, `${s} · ${STUFE_LABEL[s]}`));
    }
    if (x.mine) box.append(el('button', { class: 'small ghost', onclick: () => retractVote(x) }, 'Stimme zurückziehen'));
    card.append(box);
  }
  return card;
}

async function castVote(x, stufe) {
  let begruendung = '';
  if (stufe === 5) {
    begruendung = prompt('Begründung für das Veto (Pflicht):', '');
    if (!begruendung) return toast('Veto braucht eine Begründung.', true);
  }
  try {
    await call(api.action.run('vote-cast', { wunsch_id: x.id, stufe, begruendung }));
    toast('Stimme gespeichert.');
    await runSyncQuiet();
    rerender();
  } catch (e) {
    toast(e.message, true);
  }
}
async function retractVote(x) {
  try {
    await call(api.action.run('vote-retract', { wunsch_id: x.id }));
    toast('Stimme zurückgezogen.');
    await runSyncQuiet();
    rerender();
  } catch (e) {
    toast(e.message, true);
  }
}

const wlState = { sort: 'pop', kat: '', kreis: '', onlyOpen: false };

function wunschPreis(x) {
  return x.preis_von || x.preis_bis ? (Number(x.preis_von) || 0 || Number(x.preis_bis) || 0) : Number(x.betrag) || 0;
}

async function showWunschliste(opts = {}) {
  state.current = { name: 'wunschliste' };
  if (opts.onlyOpen != null) wlState.onlyOpen = opts.onlyOpen;
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Wunschliste & Abstimmung'));

  let list;
  try {
    list = await loadWuensche();
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const kats = [...new Set(list.map((x) => x.kategorie).filter(Boolean))].sort();
  const kreise = [...new Set(list.map((x) => x.kreis).filter(Boolean))].sort();

  const tb = el('div', { class: 'toolbar' });
  const sortSel = selectEl(
    [['pop', 'Beliebtheit'], ['preis_auf', 'Preis ↑'], ['preis_ab', 'Preis ↓'], ['titel', 'Titel A–Z'], ['prio', 'Priorität']],
    wlState.sort, { onchange: (e) => ((wlState.sort = e.target.value), draw()) }
  );
  const katSel = selectEl([['', 'Alle Kategorien'], ...kats.map((k) => [k, k])], wlState.kat, { onchange: (e) => ((wlState.kat = e.target.value), draw()) });
  const kreisSel = selectEl([['', 'Alle Kreise'], ...kreise.map((k) => [k, k])], wlState.kreis, { onchange: (e) => ((wlState.kreis = e.target.value), draw()) });
  const openChk = el('input', { type: 'checkbox' });
  openChk.checked = wlState.onlyOpen;
  openChk.addEventListener('change', () => ((wlState.onlyOpen = openChk.checked), draw()));
  tb.append('Sortieren:', sortSel, katSel, kreisSel, el('label', {}, openChk, ' nur offen für mich'));
  view.append(tb);
  const host = el('div', {});
  view.append(host);

  function draw() {
    let f = list.filter((x) => x.status !== 'erfuellt');
    if (wlState.kat) f = f.filter((x) => x.kategorie === wlState.kat);
    if (wlState.kreis) f = f.filter((x) => x.kreis === wlState.kreis);
    if (wlState.onlyOpen) f = f.filter((x) => x.status === 'offen' && !x.mine);
    const s = wlState.sort;
    f.sort((a, b) =>
      s === 'preis_auf' ? wunschPreis(a) - wunschPreis(b)
      : s === 'preis_ab' ? wunschPreis(b) - wunschPreis(a)
      : s === 'titel' ? String(a.titel).localeCompare(String(b.titel), 'de')
      : s === 'prio' ? Number(a.prioritaet) - Number(b.prioritaet)
      : b.pop - a.pop
    );
    host.innerHTML = '';
    if (!f.length) return host.append(el('div', { class: 'note ok' }, 'Nichts offen. 🎉'));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {},
      el('th', {}, 'Wunsch'), el('th', {}, 'Kategorie'), el('th', {}, 'Kreis'), el('th', { style: 'text-align:right' }, 'Kosten'),
      el('th', {}, 'Abstimmung'), el('th', {}, 'Meine Stimme'), el('th', {}, ''))));
    const body = el('tbody');
    for (const x of f) {
      const tr = el('tr', { style: 'cursor:pointer' });
      const preis = x.preis_von || x.preis_bis ? `${eur(x.preis_von || 0)}–${eur(x.preis_bis || 0)}` : x.betrag ? eur(x.betrag) : '—';
      tr.append(
        el('td', {}, el('strong', {}, x.titel), x.beschreibung ? el('div', { class: 'muted', style: 'font-size:12px' }, x.beschreibung.slice(0, 90)) : null),
        el('td', {}, x.kategorie || '—'),
        el('td', {}, x.kreis || '—'),
        el('td', { style: 'text-align:right' }, preis),
        el('td', {}, x.count ? `Ø ${x.avg.toFixed(1)} · ${x.count}×${x.veto ? ' · ⛔' : ''}` : '—'),
        el('td', {}, x.mine ? `${x.mine.stufe} – ${STUFE_LABEL[x.mine.stufe]}` : '–'),
        el('td', {}, el('button', { class: 'small', onclick: (ev) => { ev.stopPropagation(); toggleVoteRow(tr, x); } }, 'Abstimmen'))
      );
      body.append(tr);
    }
    t.append(body);
    host.append(t);
    host.append(el('p', { class: 'muted' }, `${f.length} Wunsch/Wünsche`));
  }
  draw();
}

function toggleVoteRow(tr, x) {
  if (tr.nextSibling && tr.nextSibling.classList && tr.nextSibling.classList.contains('vote-row')) {
    tr.nextSibling.remove();
    return;
  }
  const td = el('td', { colspan: '7' });
  const box = el('div', { class: 'vote' });
  for (let s = 1; s <= 5; s++) {
    const active = x.mine && Number(x.mine.stufe) === s;
    box.append(el('button', { class: 'small' + (active ? ' primary' : ''), onclick: () => castVote(x, s) }, `${s} · ${STUFE_LABEL[s]}`));
  }
  if (x.mine) box.append(el('button', { class: 'small ghost', onclick: () => retractVote(x) }, 'Stimme zurückziehen'));
  td.append(box);
  const vr = el('tr', { class: 'vote-row' }, td);
  tr.after(vr);
}

async function showWunschverwaltung() {
  state.current = { name: 'wunsch_verwaltung' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Wunschlistenverwaltung'));
  view.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => wunschEdit(null) }, '+ Neuer Wunsch')));
  let list;
  try {
    list = await loadWuensche();
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  window._wlGremien = list._gremien || [];
  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Titel'), el('th', {}, 'Kategorie'), el('th', {}, 'Kreis'), el('th', {}, 'Status'), el('th', {}, 'Kosten'), el('th', {}, 'Stimmen'), el('th', {}, ''))));
  const tb = el('tbody');
  for (const x of list) {
    tb.append(el('tr', {},
      el('td', {}, x.titel), el('td', {}, x.kategorie || '—'), el('td', {}, x.kreis || '—'), el('td', {}, x.status),
      el('td', {}, x.betrag ? eur(x.betrag) : (x.preis_von ? `${eur(x.preis_von)}–${eur(x.preis_bis || 0)}` : '—')),
      el('td', {}, x.count ? `Ø ${x.avg.toFixed(1)}/${x.count}` : '—'),
      el('td', {}, el('button', { class: 'small', onclick: () => wunschEdit(x) }, 'Bearbeiten'))));
  }
  t.append(tb);
  view.append(t);
}

function wunschEdit(x) {
  x = x || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: showWunschverwaltung }, '‹ Zurück'));
  view.append(el('h1', {}, x.id ? 'Wunsch bearbeiten' : 'Neuer Wunsch'));
  const f = el('form', { class: 'detail' });
  const inp = {};
  const add = (key, label, tag = 'input', attrs = {}) => {
    const n = el(tag, { ...attrs });
    if (tag === 'input') n.value = x[key] == null ? '' : String(x[key]);
    else n.textContent = x[key] == null ? '' : String(x[key]);
    inp[key] = n;
    f.append(el('label', {}, label), n);
  };
  add('titel', 'Titel');
  add('kategorie', 'Kategorie');
  add('beschreibung', 'Beschreibung', 'textarea');
  add('begruendung', 'Begründung', 'textarea');
  add('betrag', 'Festbetrag (€)', 'input', { type: 'number', step: '0.01' });
  add('preis_von', 'Preis von (€)', 'input', { type: 'number', step: '0.01' });
  add('preis_bis', 'Preis bis (€)', 'input', { type: 'number', step: '0.01' });
  inp.status = selectEl([['offen', 'offen'], ['in_bearbeitung', 'in Bearbeitung'], ['erfuellt', 'erfüllt']], x.status || 'offen');
  f.append(el('label', {}, 'Status'), inp.status);
  inp.prioritaet = selectEl([['1', 'hoch'], ['2', 'normal'], ['3', 'niedrig']], String(x.prioritaet || 2));
  f.append(el('label', {}, 'Priorität'), inp.prioritaet);
  const gremien = (window._wlGremien || []);
  inp.gremium_id = selectEl([['', '— kein Kreis —'], ...gremien.map((g) => [String(g.id), g.name])], String(x.gremium_id || ''));
  f.append(el('label', {}, 'Kreis / Gremium'), inp.gremium_id);
  add('bild_url', 'Bild-URL');
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Speichern'));
  if (x.id) actions.append(el('button', { type: 'button', class: 'danger', onclick: () => wunschDelete(x.id) }, 'Löschen'));
  f.append(actions);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = { id: x.id || 0 };
    for (const k of Object.keys(inp)) body[k] = inp[k].value;
    try {
      await call(api.action.run('wunsch-save', body));
      toast('Gespeichert.');
      await runSyncQuiet();
      showWunschverwaltung();
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
}
async function wunschDelete(id) {
  if (!confirm('Wunsch wirklich löschen?')) return;
  try {
    await call(api.action.run('wunsch-delete', { id }));
    toast('Gelöscht.');
    await runSyncQuiet();
    showWunschverwaltung();
  } catch (e) {
    toast(e.message, true);
  }
}

/* =========================================================== Sitzungen & Protokolle */

const ppState = { sub: 'protokolle' };
const PP_SUBS = [
  ['uebersicht', 'Übersicht'], ['protokolle', 'Protokolle'], ['kreise', 'Kreise & Gremien'],
  ['sets', 'Aufgaben-Sets'], ['themen', 'Themenspeicher'], ['aufgaben', 'Aufgaben'], ['termine', 'Termine'],
];

async function showProtokolle(opts = {}) {
  if (opts.sub) ppState.sub = opts.sub;
  if (opts.manage) ppState.sub = ppState.sub === 'uebersicht' ? 'protokolle' : ppState.sub;
  state.current = { name: 'protokolle', sub: ppState.sub };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Sitzungen & Protokolle'));

  const nav = el('nav', { class: 'subnav' });
  for (const [k, label] of PP_SUBS) {
    nav.append(el('button', { class: 'chip' + (ppState.sub === k ? ' is-active' : ''), onclick: () => showProtokolle({ sub: k }) }, label));
  }
  view.append(nav);
  const host = el('div', {});
  view.append(host);

  let d;
  try {
    d = await ppLoad();
  } catch (e) {
    return host.append(el('div', { class: 'note err' }, e.message));
  }
  const r = { host, ...d };
  if (ppState.sub === 'uebersicht') ppViewUebersicht(r);
  else if (ppState.sub === 'kreise') ppViewKreise(r);
  else if (ppState.sub === 'sets') ppViewGeneric(r, 'pp_aufgaben_sets', 'Aufgaben-Sets', ['name', 'gremium_id', 'beschreibung']);
  else if (ppState.sub === 'themen') ppViewThemen(r);
  else if (ppState.sub === 'aufgaben') ppViewAufgaben(r);
  else if (ppState.sub === 'termine') ppViewGeneric(r, 'pp_termine', 'Termine', null);
  else ppViewProtokolle(r);
}

async function ppLoad() {
  const grab = (slug, n = 5000) => call(api.data.rows(slug, { limit: n })).then((x) => x.rows).catch(() => []);
  const [gremien, protok, tops, aufgaben, themen, termine, sets, kmit, rollen] = await Promise.all([
    grab('pp_gremien', 500), grab('pp_protokolle', 3000), grab('pp_tops', 30000),
    grab('pp_aufgaben', 5000), grab('pp_themen', 3000), grab('pp_termine', 3000),
    grab('pp_aufgaben_sets', 2000), grab('pp_kreis_mitglieder', 5000), grab('pp_rollen', 3000),
  ]);
  return {
    gremien, protok, tops, aufgaben, themen, termine, sets, kmit, rollen,
    gname: Object.fromEntries(gremien.map((g) => [String(g.id), g.name])),
    manage: can('pp_manage'),
  };
}

function ppViewUebersicht({ host, protok, aufgaben, termine }) {
  const entw = protok.filter((p) => p.status === 'entwurf');
  const offen = aufgaben.filter((a) => a.status === 'offen');
  const t = el('div', { class: 'tiles' });
  const tile = (v, l) => t.append(el('div', { class: 'tile' }, el('div', { class: 'tile-v' }, String(v)), el('div', { class: 'tile-l' }, l)));
  tile(protok.length, 'Protokolle'); tile(entw.length, 'Entwürfe'); tile(offen.length, 'Offene Aufgaben'); tile(termine.length, 'Termine');
  host.append(t);
  if (entw.length) {
    host.append(el('h2', {}, 'Entwürfe'));
    entw.forEach((p) => host.append(el('div', { class: 'card', onclick: () => showProtokolle({ sub: 'protokolle' }) }, `${p.datum || '—'} · ${p.titel}`)));
  }
}

function ppViewProtokolle({ host, protok, tops, gname, gremien, manage }) {
  const topsBy = {};
  for (const t of tops) (topsBy[t.protokoll_id] ||= []).push(t);
  if (manage) host.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => protokollEdit(null, gremien) }, '+ Neues Protokoll')));
  protok.slice().sort((a, b) => String(b.datum || '').localeCompare(String(a.datum || ''))).forEach((p) => {
    const d = el('details', { class: 'card' });
    d.append(el('summary', {}, `${p.datum || '—'} · ${gname[String(p.gremium_id)] || 'Gremium'} · ${p.titel} `,
      el('span', { class: 'st st-' + (p.status === 'abgeschlossen' ? 'ausgezahlt' : 'ausstehend') }, p.status)));
    (topsBy[p.id] || []).slice().sort((a, b) => Number(a.sortierung) - Number(b.sortierung)).forEach((t) => {
      const box = el('div', { style: 'border-top:1px solid var(--line);padding:8px 0' });
      box.append(el('strong', {}, t.titel), ' ', el('span', { class: 'muted' }, t.konsent_status || ''));
      if (t.beschreibung) box.append(el('div', {}, t.beschreibung));
      if (t.beschluss) box.append(el('div', { class: 'note ok', style: 'margin:6px 0' }, 'Beschluss: ' + t.beschluss));
      if (manage) box.append(el('div', { class: 'row', style: 'margin-top:4px' },
        el('button', { class: 'small', onclick: () => topEdit(t, p.id) }, 'TOP bearbeiten'),
        el('button', { class: 'small danger', onclick: () => topDelete(t.id) }, 'löschen')));
      d.append(box);
    });
    if (manage) d.append(el('div', { style: 'margin-top:8px' },
      el('button', { class: 'small primary', onclick: () => topEdit(null, p.id) }, '+ TOP'), ' ',
      el('button', { class: 'small', onclick: () => protokollEdit(p, gremien) }, 'Protokoll bearbeiten')));
    host.append(d);
  });
  if (!protok.length) host.append(el('div', { class: 'note' }, 'Keine Protokolle.'));
}

function ppViewKreise({ host, gremien, kmit, rollen }) {
  const mBy = {};
  for (const m of kmit) (mBy[m.gremium_id] ||= []).push(m);
  const rBy = {};
  for (const r of rollen) (rBy[r.gremium_id] ||= []).push(r);
  const grid = el('div', { class: 'grid-cards' });
  for (const g of gremien) {
    const c = el('div', { class: 'card', onclick: () => showDetail('pp_gremien', String(g.id)) });
    c.append(el('strong', {}, g.name));
    c.append(el('div', { class: 'muted', style: 'font-size:12px' }, g.typ || ''));
    const mem = mBy[g.id] || [];
    const rol = rBy[g.id] || [];
    c.append(el('div', {}, `${mem.length} Mitglied(er) · ${rol.length} Rolle(n)`));
    grid.append(c);
  }
  host.append(grid);
  if (!gremien.length) host.append(el('div', { class: 'note' }, 'Keine Gremien.'));
}

function ppViewGeneric({ host }, slug, label, cols) {
  showTableInto(host, slug, label, cols);
}
function showTableInto(host, slug, label, cols) {
  call(api.data.rows(slug, { limit: 5000 })).then(({ columns, rows }) => {
    const show = cols || columns.slice(0, 6);
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, ...show.map((c) => el('th', {}, c)))));
    const tb = el('tbody');
    for (const r of rows) tb.append(el('tr', { onclick: () => showDetail(slug, r.id) }, ...show.map((c) => el('td', {}, fmt(r[c])))));
    t.append(tb);
    host.append(t);
    host.append(el('p', { class: 'muted' }, `${rows.length} Einträge`));
  }).catch((e) => host.append(el('div', { class: 'note err' }, e.message)));
}

function ppViewThemen({ host, themen, gremien, manage }) {
  if (manage) host.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => ppThemaEdit(null, gremien) }, '+ Thema')));
  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Titel'), el('th', {}, 'Status'), el('th', {}, 'Beschreibung'), el('th', {}, ''))));
  const tb = el('tbody');
  for (const x of themen) {
    tb.append(el('tr', {}, el('td', {}, x.titel), el('td', {}, x.status || '—'), el('td', {}, (x.beschreibung || '').slice(0, 80)),
      el('td', {}, manage ? el('button', { class: 'small', onclick: () => ppThemaEdit(x, gremien) }, 'Bearbeiten') : '')));
  }
  t.append(tb);
  host.append(t);
  if (!themen.length) host.append(el('div', { class: 'note' }, 'Themenspeicher leer.'));
}
function ppThemaEdit(x, gremien) {
  x = x || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showProtokolle({ sub: 'themen' }) }, '‹ Zurück'));
  view.append(el('h1', {}, x.id ? 'Thema bearbeiten' : 'Neues Thema'));
  const titel = el('input', { value: x.titel || '' });
  const beschr = el('textarea', {});
  beschr.value = x.beschreibung || '';
  const status = selectEl(['vorbereitet', 'in_bearbeitung', 'abgeschlossen', 'evaluationsreif'], x.status || 'vorbereitet');
  const g = selectEl([['', '—'], ...gremien.map((z) => [String(z.id), z.name])], String(x.gremium_id || ''));
  const f = el('form', { class: 'detail' }, 'Titel', titel, 'Beschreibung', beschr, 'Status', status, 'Gremium', g);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('thema-save', { id: x.id || 0, titel: titel.value, beschreibung: beschr.value, status: status.value, gremium_id: g.value }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showProtokolle({ sub: 'themen' });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
}

function ppViewAufgaben({ host, aufgaben, gremien, manage }) {
  if (manage) host.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => ppAufgabeEdit(null, gremien) }, '+ Aufgabe')));
  for (const [title, st] of [['Offen', 'offen'], ['Erledigt', 'erledigt']]) {
    const list = aufgaben.filter((a) => a.status === st);
    host.append(el('h2', {}, `${title} (${list.length})`));
    for (const a of list) {
      const row = el('div', { class: 'row', style: 'border-bottom:1px solid var(--line);padding:6px 0' });
      const cb = el('input', { type: 'checkbox' });
      cb.checked = st === 'erledigt';
      cb.addEventListener('change', () => ppAufgabeToggle(a.id, cb.checked ? 'erledigt' : 'offen'));
      row.append(cb, el('div', { style: 'flex:1' }, el('strong', {}, a.titel),
        a.faelligkeitsdatum ? el('span', { class: 'muted' }, ' · fällig ' + a.faelligkeitsdatum) : null));
      if (manage) row.append(el('button', { class: 'small', onclick: () => ppAufgabeEdit(a, gremien) }, 'Bearbeiten'));
      host.append(row);
    }
  }
  if (!aufgaben.length) host.append(el('div', { class: 'note' }, 'Keine Aufgaben.'));
}
async function ppAufgabeToggle(id, status) {
  try {
    await call(api.action.run('aufgabe-save', { id, status }));
    await runSyncQuiet();
    showProtokolle({ sub: 'aufgaben' });
  } catch (e) {
    toast(e.message, true);
  }
}
function ppAufgabeEdit(a, gremien) {
  a = a || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showProtokolle({ sub: 'aufgaben' }) }, '‹ Zurück'));
  view.append(el('h1', {}, a.id ? 'Aufgabe bearbeiten' : 'Neue Aufgabe'));
  const titel = el('input', { value: a.titel || '' });
  const beschr = el('textarea', {});
  beschr.value = a.beschreibung || '';
  const faellig = el('input', { type: 'date', value: (a.faelligkeitsdatum || '').slice(0, 10) });
  const uid = el('input', { type: 'number', value: a.verantwortlich_user_id || '' });
  const g = selectEl([['', '—'], ...gremien.map((z) => [String(z.id), z.name])], String(a.verantwortliches_gremium_id || ''));
  const status = selectEl([['offen', 'offen'], ['erledigt', 'erledigt']], a.status || 'offen');
  const f = el('form', { class: 'detail' }, 'Titel', titel, 'Beschreibung', beschr, 'Fällig', faellig, 'Verantwortlich (User-ID)', uid, 'Gremium', g, 'Status', status);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('aufgabe-save', {
        id: a.id || 0, titel: titel.value, beschreibung: beschr.value, faelligkeitsdatum: faellig.value,
        verantwortlich_user_id: uid.value, verantwortliches_gremium_id: g.value, status: status.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showProtokolle({ sub: 'aufgaben' });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
}

function protokollEdit(p, gremien) {
  p = p || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showProtokolle({ manage: true }) }, '‹ Zurück'));
  view.append(el('h1', {}, p.id ? 'Protokoll bearbeiten' : 'Neues Protokoll'));
  const f = el('form', { class: 'detail' });
  const g = selectEl(gremien.map((x) => [String(x.id), x.name]), String(p.gremium_id || (gremien[0] && gremien[0].id) || ''));
  const titel = el('input', { value: p.titel || '' });
  const datum = el('input', { type: 'date', value: (p.datum || '').slice(0, 10) });
  const ort = el('input', { value: p.ort || '' });
  const status = selectEl([['entwurf', 'Entwurf'], ['abgeschlossen', 'Abgeschlossen']], p.status || 'entwurf');
  f.append('Gremium', g, 'Titel', titel, 'Datum', datum, 'Ort', ort, 'Status', status);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('protokoll-save', {
        id: p.id || 0, gremium_id: g.value, titel: titel.value, datum: datum.value, ort: ort.value, status: status.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showProtokolle({ manage: true });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
}

function topEdit(t, protokollId) {
  t = t || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showProtokolle({ manage: true }) }, '‹ Zurück'));
  view.append(el('h1', {}, t.id ? 'TOP bearbeiten' : 'Neuer TOP'));
  const f = el('form', { class: 'detail' });
  const titel = el('input', { value: t.titel || '' });
  const beschr = el('textarea', {});
  beschr.value = t.beschreibung || '';
  const beschluss = el('textarea', {});
  beschluss.value = t.beschluss || '';
  const status = selectEl(['vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'einwand_offen', 'beschlossen'], t.konsent_status || 'vorstellung');
  const sort = el('input', { type: 'number', value: t.sortierung || 0 });
  f.append('Titel', titel, 'Beschreibung', beschr, 'Beschluss', beschluss, 'Status', status, 'Reihenfolge', sort);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('top-save', {
        id: t.id || 0, protokoll_id: protokollId, titel: titel.value, beschreibung: beschr.value,
        beschluss: beschluss.value, konsent_status: status.value, sortierung: sort.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showProtokolle({ manage: true });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
}
async function topDelete(id) {
  if (!confirm('TOP löschen?')) return;
  try {
    await call(api.action.run('top-delete', { id }));
    toast('Gelöscht.');
    await runSyncQuiet();
    showProtokolle({ manage: true });
  } catch (e) {
    toast(e.message, true);
  }
}

/* =========================================================== Schichtpläne */

async function showSchichtplaene() {
  state.current = { name: 'schichtplaene' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Schichtpläne'));
  let ev = [];
  let st = [];
  let sc = [];
  let ei = [];
  try {
    [ev, st, sc, ei] = await Promise.all([
      call(api.data.rows('wl_shift_events', { limit: 500 })).then((r) => r.rows),
      call(api.data.rows('wl_shift_stationen', { limit: 2000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_schichten', { limit: 5000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_eintragungen', { limit: 20000 })).then((r) => r.rows).catch(() => []),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const stBy = {};
  st.forEach((s) => (stBy[s.event_id] ||= []).push(s));
  const scBy = {};
  sc.forEach((s) => (scBy[s.station_id] ||= []).push(s));
  const eiBy = {};
  ei.forEach((e) => (eiBy[e.schicht_id] ||= []).push(e));
  const myId = state.me && String(state.me.id);

  for (const e of ev.filter((x) => String(x.aktiv) !== '0')) {
    view.append(el('h2', {}, `${e.titel}${e.veranstaltungsdatum ? ' · ' + e.veranstaltungsdatum : ''}`));
    const stations = (stBy[e.id] || []).sort((a, b) => Number(a.sortierung) - Number(b.sortierung));
    if (!stations.length) {
      view.append(el('p', { class: 'muted' }, 'Keine Stationen.'));
      continue;
    }
    const cols = el('div', { class: 'shift-grid', style: `grid-template-columns:repeat(${stations.length},minmax(200px,1fr))` });
    for (const station of stations) {
      const col = el('div', { class: 'shift-col' });
      col.append(el('div', { class: 'shift-head' }, station.titel,
        station.treffpunkt ? el('div', { class: 'muted', style: 'font-size:11px;font-weight:400' }, '📍 ' + station.treffpunkt) : null));
      for (const schicht of (scBy[station.id] || []).sort((a, b) => String(a.start_zeit || '').localeCompare(String(b.start_zeit || '')))) {
        const eintr = eiBy[schicht.id] || [];
        const mine = eintr.find((x) => String(x.user_id) === myId);
        const max = Number(schicht.max_plaetze || 1);
        const full = eintr.length >= max;
        const zeit = schicht.start_zeit
          ? `${schicht.start_zeit.slice(11, 16)}–${(schicht.end_zeit || '').slice(11, 16)}`
          : (schicht.titel || 'Schicht');
        const cell = el('div', { class: 'shift-cell' + (mine ? ' mine' : full ? ' full' : '') });
        cell.append(el('div', { class: 'shift-time' }, zeit, ' ', el('span', { class: 'muted' }, `${eintr.length}/${max}`)));
        if (eintr.length) cell.append(el('div', { class: 'shift-names' }, eintr.map((x) => x.name).join(', ')));
        if (mine) cell.append(el('button', { class: 'small ghost', onclick: () => schichtAus(mine.id) }, 'Austragen'));
        else if (!full) cell.append(el('button', { class: 'small primary', onclick: () => schichtEin(schicht.id) }, 'Eintragen'));
        col.append(cell);
      }
      cols.append(col);
    }
    const scroller = el('div', { style: 'overflow-x:auto' });
    scroller.append(cols);
    view.append(scroller);
  }
  if (!ev.length) view.append(el('div', { class: 'note' }, 'Keine Veranstaltungen.'));
}

async function schichtEin(schicht_id) {
  try {
    await call(api.action.run('schicht-eintragen', { schicht_id }));
    toast('Eingetragen.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    toast(e.message, true);
  }
}
async function schichtAus(eintrag_id) {
  try {
    await call(api.action.run('schicht-austragen', { eintrag_id }));
    toast('Ausgetragen.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    toast(e.message, true);
  }
}

async function showSchichtverwaltung() {
  state.current = { name: 'schicht_verwaltung' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Schichtplanverwaltung'));
  view.append(el('p', { class: 'sub' }, 'Veranstaltungen, Stationen und Schichten pflegst du hier über die Rohdaten-Editoren.'));
  view.append(el('div', { class: 'row' },
    el('button', { class: 'small', onclick: () => showTable('wl_shift_events') }, 'Veranstaltungen'),
    el('button', { class: 'small', onclick: () => showTable('wl_shift_stationen') }, 'Stationen'),
    el('button', { class: 'small', onclick: () => showTable('wl_shift_schichten') }, 'Schichten'),
    el('button', { class: 'small', onclick: () => showTable('wl_shift_eintragungen') }, 'Eintragungen')));
  view.append(el('p', { class: 'muted', style: 'margin-top:12px' }, 'Ansicht wie „Schichtpläne" zeigt das Ergebnis; ein voller Kalender-Editor folgt später.'));
}

/* =========================================================== Newsletter */

async function showNewsletter(prefillRole) {
  state.current = { name: 'newsletter' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Newsletter'));
  view.append(el('p', { class: 'sub' }, 'Geht als BCC an alle Mitglieder der gewählten Rolle. „Testmail" schickt nur an dich.'));

  const roles = (state.me && state.me.roles) || [];
  const roleSel = selectEl(
    [...new Set(['wl_mitglied', 'editor', 'administrator', ...roles])].map((r) => [r, r]),
    prefillRole || 'wl_mitglied'
  );
  const betreff = el('input', { placeholder: 'Betreff' });
  const body = el('textarea', { placeholder: 'Text …', style: 'min-height:200px' });
  const f = el('form', { class: 'detail' });
  f.append('Empfänger-Rolle', roleSel, 'Betreff', betreff, 'Text', body);
  const actions = el('div', { class: 'form-actions' });
  actions.append(
    el('button', { class: 'primary', type: 'submit' }, 'An alle senden'),
    el('button', { type: 'button', class: 'ghost', onclick: () => send(true) }, 'Testmail an mich')
  );
  f.append(actions);
  const out = el('div', {});
  view.append(f, out);

  async function send(test) {
    if (!betreff.value.trim() || !body.value.trim()) return toast('Betreff und Text nötig.', true);
    if (!test && !confirm(`Newsletter an alle „${roleSel.value}" senden?`)) return;
    try {
      const r = await call(api.action.run('newsletter-send', { betreff: betreff.value, body: body.value, rolle: roleSel.value, test: !!test }));
      toast(test ? 'Testmail verschickt.' : `Verschickt an ${r.anzahl} Empfänger.`);
      if (!test) {
        betreff.value = '';
        body.value = '';
        loadSent();
      }
    } catch (e) {
      toast(e.message, true);
    }
  }
  f.addEventListener('submit', (ev) => {
    ev.preventDefault();
    send(false);
  });

  async function loadSent() {
    try {
      const rows = (await call(api.data.rows('vp_newsletter', { limit: 200 }))).rows;
      out.innerHTML = '';
      if (!rows.length) return;
      out.append(el('h2', {}, 'Versendet'));
      const t = el('table');
      t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Betreff'), el('th', {}, 'Rolle'), el('th', {}, 'Anzahl'))));
      const tb = el('tbody');
      rows.sort((a, b) => String(b.gesendet_am).localeCompare(String(a.gesendet_am)));
      for (const r of rows) tb.append(el('tr', {}, el('td', {}, (r.gesendet_am || '').slice(0, 16)), el('td', {}, r.betreff), el('td', {}, r.empfaenger_rolle), el('td', {}, r.anzahl)));
      t.append(tb);
      out.append(t);
    } catch {
      /* Tabelle evtl. noch nicht da */
    }
  }
  loadSent();
}

boot().catch((e) => {
  view.innerHTML = '';
  view.append(el('div', { class: 'note err' }, 'Startfehler: ' + e.message));
});
