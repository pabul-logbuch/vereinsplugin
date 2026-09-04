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
  // Alle „detail"-Formulare: Enter springt zum nächsten Feld.
  if (tag === 'form' && String(attrs.class || '').includes('detail')) enterAdvances(n);
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

/** Deutsche Zahleneingabe parsen: „1.234,56" / „12,50" / „12.5" → Number. */
function parseNum(s) {
  s = String(s ?? '').trim().replace(/[^\d.,-]/g, '');
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const n = parseFloat(s);
  return Number.isFinite(n) ? n : 0;
}
/** Zahl mit Komma und 2 Nachkommastellen anzeigen (für Eingabefelder). */
function fmtNum(v) {
  if (v === '' || v == null) return '';
  const n = typeof v === 'number' ? v : parseNum(v);
  return n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
/** Geldbetrag-Eingabefeld (Text, Komma erlaubt). */
function moneyInput(value, attrs = {}) {
  return el('input', { type: 'text', inputmode: 'decimal', value: fmtNum(value), ...attrs });
}
/** Enter springt zum nächsten Eingabefeld; im letzten Feld wird abgeschickt. */
function enterAdvances(form) {
  if (form.dataset.enterAdv) return;
  form.dataset.enterAdv = '1';
  form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
    e.preventDefault();
    const fields = [...form.querySelectorAll('input, select')].filter((n) => !n.disabled && n.type !== 'hidden' && n.offsetParent !== null);
    const i = fields.indexOf(e.target);
    if (i > -1 && i < fields.length - 1) fields[i + 1].focus();
    else form.requestSubmit ? form.requestSubmit() : form.querySelector('[type=submit]')?.click();
  });
}

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
    if (has('jb_buchungen')) addItem('zbon', 'Z-Bon erfassen', { onClick: showZbon });
    if (has('jb_buchungen')) addItem('kontenblaetter', 'Kontenblätter (Doppik)', { onClick: showKontenblaetter });
    if (has('jb_budgets')) tblItem('jb_budgets', 'Budgets');
    if (has('jb_ruecklagen')) tblItem('jb_ruecklagen', 'Rücklagen');
    if (has('jb_konten')) addItem('kontenplan', 'Kontenplan', { onClick: showKontenplan });
    if (has('jb_anfangsbestaende')) tblItem('jb_anfangsbestaende', 'Anfangsbestände');
    if (has('jb_buchungen')) addItem('quellen', 'Geld-Töpfe → Konten', { onClick: () => showQuellen(showKontenplan) });
    if (has('vp_rechnungen')) addItem('rechnungen', 'Rechnungen', { slug: 'vp_rechnungen', badge: countFor('vp_rechnungen').count, onClick: () => showRechnungen() });
    if (has('vp_sepa_mandate')) addItem('sepa', 'SEPA-Lastschrift', { onClick: () => showSepa() });
    if (has('vp_spenden')) addItem('spenden', 'Spenden & Bescheinigungen', { onClick: () => showSpenden() });
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
  else if (c.name === 'zbon') showZbon();
  else if (c.name === 'kontenblaetter') showKontenblaetter();
  else if (c.name === 'kontenblatt') showKontenblatt(c.konto, c.from, c.jahr);
  else if (c.name === 'quellen') showQuellen(c.zurueck);
  else if (c.name === 'buchung') showBuchung(c.pk, c.from);
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
  else if (c.name === 'rechnungen') showRechnungen();
  else if (c.name === 'sepa') showSepa({ sub: c.sub, lauf: c.lauf });
  else if (c.name === 'spenden') showSpenden({ sub: c.sub });
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
    let val = row[c] == null ? '' : String(row[c]);
    // Neue Zeile: Datumsfelder mit heute vorbelegen (leere Strings brechen
    // sonst NOT-NULL-DATE-Spalten wie jb_ruecklagen.letzte_zahlung).
    if (isNew && val === '' && (cf.type === 'date' || c.includes('datum') || c.includes('_zahlung') || c === 'letzte_zahlung')) {
      val = new Date().toISOString().slice(0, 10);
    }
    if (isNew && val === '' && c === 'aktiv') val = '1';
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
    } else if (cf.type === 'money') {
      input = moneyInput(val);
      input.dataset.money = '1';
      if (!editable) input.setAttribute('readonly', 'readonly');
    } else {
      input = el('input', { type: cf.type || 'text', value: val });
      if (!editable) input.setAttribute('readonly', 'readonly');
    }

    inputs[c] = input;
    form.append(labelMitHilfe(cf.label || c, cf.hint), input);
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
      const inp = inputs[c];
      fields[c] = inp.dataset && inp.dataset.money ? String(parseNum(inp.value)) : inp.value;
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

  enterAdvances(form);
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
    line('Bankkonto (KSK)', d.bank);
    line('Barkasse', d.kasse);
    if (d.paypal != null) line('PayPal / Zettle', d.paypal);
    line('Kontostand gesamt', d.kontostand, true);
    line('Getränke-Warenwert', d.getraenke_wert);
    line('− Offene Auslagen (genehmigt)', -d.offene_auslagen);
    line(`− Rücklagenbedarf (inkl. ${d.ruecklagen_horizont ?? 9} Mon. Vorausplanung)`, -d.ruecklagen);
    line('− Verplantes Budget (Rest)', -d.verplantes);
    line('= Freies / verfügbares Budget', d.frei, true);
    view.append(el('div', { class: 'card' }, kv));
    view.append(el('p', { class: 'muted' }, 'Kontostände = Anfangsbestand + alle Buchungen mit passender Quelle. Anfangsbestände setzt du im Plugin unter Buchhaltung → Bestände.'));

    // Rücklagen-Aufschlüsselung aus dem lokalen Spiegel
    try {
      const rl = (await call(api.data.rows('jb_ruecklagen', { limit: 500 }))).rows.filter((x) => String(x.aktiv) !== '0');
      const horizont = d.ruecklagen_horizont ?? 9;
      if (rl.length) {
        view.append(el('h2', {}, 'Rücklagen im Detail'));
        const t = el('table');
        t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Bezeichnung'), el('th', { style: 'text-align:right' }, 'Betrag/Fällig.'),
          el('th', {}, 'Intervall'), el('th', {}, 'Letzte Zahlung'), el('th', { style: 'text-align:right' }, `Bedarf (+${horizont} Mon.)`))));
        const tb = el('tbody');
        let sum = 0;
        for (const r of rl) {
          const betrag = Number(r.betrag) || 0;
          const iv = Math.max(1, Number(r.intervall_monate) || 12);
          const monate = r.letzte_zahlung ? Math.max(0, Math.floor((Date.now() - Date.parse(r.letzte_zahlung)) / (30.44 * 864e5))) : 0;
          const fenster = monate + horizont;
          const zyklen = Math.max(1, Math.ceil(fenster / iv));
          const heute = Math.min(betrag * zyklen, (betrag / iv) * fenster);
          sum += heute;
          tb.append(el('tr', { style: 'cursor:pointer', onclick: () => showDetail('jb_ruecklagen', r.id) },
            el('td', {}, r.bezeichnung), el('td', { style: 'text-align:right' }, eur(betrag)),
            el('td', {}, `${iv} Mon.`), el('td', {}, r.letzte_zahlung || '—'),
            el('td', { style: 'text-align:right' }, eur(heute))));
        }
        tb.append(el('tr', { style: 'font-weight:700' }, el('td', { colspan: '4' }, 'Rücklagenbedarf gesamt'), el('td', { style: 'text-align:right' }, eur(sum))));
        t.append(tb);
        view.append(t);
        view.append(el('p', { class: 'muted' }, 'Zeile anklicken zum Bearbeiten.'));
      }
    } catch {
      /* jb_ruecklagen evtl. nicht gespiegelt */
    }
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
  const c = state.current && state.current.name === 'kontenplan' ? state.current : {};
  const jahr = c.jahr || '';
  state.current = { name: 'kontenplan', jahr };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Kontenplan'));
  view.append(el('p', { class: 'sub' }, 'Alle SKR-49-Konten mit Saldo. Gerechnet wie Kontenblätter und Kassenbericht: jede Buchung wirkt auf zwei Konten (Soll und Haben).'));

  let konten = [];
  let buchungen = [];
  let bestaende = [];
  let dmap = DOPPIK_MAP_DEFAULT;
  let serverAnfang = null;
  let serverSalden = null;
  const warn = [];
  try {
    [konten, buchungen] = await Promise.all([
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows),
      call(api.data.rows('jb_buchungen', { limit: 20000 })).then((r) => r.rows),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, 'Lokale Daten nicht lesbar: ' + e.message));
  }
  try {
    bestaende = (await call(api.data.rows('jb_anfangsbestaende', { limit: 2000 }))).rows;
  } catch {
    warn.push('Jahresanfangsbestände noch nicht gespiegelt – Plugin auf v0.23.0 aktualisieren und synchronisieren.');
  }
  try {
    const s = await call(api.report.salden(jahr || undefined));
    if (s && s.map) dmap = { ...DOPPIK_MAP_DEFAULT, ...s.map };
    if (s && s.anfang) serverAnfang = s.anfang;
    if (s && s.salden) serverSalden = s.salden;
  } catch (e) {
    warn.push('Server-Salden nicht abrufbar (' + e.message + ') – unten steht die lokal gerechnete Fassung.');
  }

  // Anfangsbestände: bevorzugt vom Server (kennt auch die alten Optionen),
  // sonst aus der gespiegelten Jahrestabelle.
  const lok = anfangFuerJahr(bestaende, jahr || new Date().getFullYear());
  const anfang = serverAnfang && Object.keys(serverAnfang).length ? serverAnfang : lok.anfang;
  const basis = lok.basis;
  const fenster = { von: basis ? `${basis}-01-01` : '', bis: jahr ? `${jahr}-12-31` : '' };
  const sal = saldenLokal(buchungen, anfang, fenster, dmap);

  for (const w of warn) view.append(el('div', { class: 'note' }, w));

  // Jahresauswahl
  const jahreBuchung = [...new Set(buchungen.map((r) => String(r.buchung_datum || '').slice(0, 4)).filter(Boolean))];
  const jahreBestand = [...new Set((bestaende || []).map((r) => String(r.jahr || '')).filter((x) => x && x !== '0'))];
  const jahre = [...new Set([...jahreBuchung, ...jahreBestand])].sort().reverse();
  const tb0 = el('div', { class: 'toolbar' });
  tb0.append('Zeitraum:', selectEl([['', 'Stand heute (laufend)'], ...jahre.map((y) => [y, 'Geschäftsjahr ' + y])], jahr, {
    onchange: (e) => { state.current = { name: 'kontenplan', jahr: e.target.value }; showKontenplan(); },
  }));
  tb0.append(el('span', { class: 'muted' },
    basis ? `Anfangsbestände ${basis}${jahr ? ' · Buchungen bis 31.12.' + jahr : ' · alle Buchungen ab 1.1.' + basis}`
          : 'Keine Jahresanfangsbestände hinterlegt – es zählen alle Buchungen.'));
  if (state.meta && state.meta.tables && state.meta.tables.jb_anfangsbestaende) {
    tb0.append(el('button', { class: 'small', onclick: () => showTable('jb_anfangsbestaende') }, 'Anfangsbestände bearbeiten'));
  }
  view.append(tb0);

  // Geld-Töpfe als Kacheln – direkt aus denselben Salden.
  // Welches Konto zu welchem Topf gehört, sagt die Zuordnung – nicht eine
  // feste Liste (sonst zeigt die Kachel auf ein anderes Konto als die Buchungen).
  const kn = Object.fromEntries(konten.map((k) => [String(k.nummer), k.bezeichnung]));
  const topfe = [];
  for (const [q, label] of [['Bank KSK', 'Bankkonto'], ['Zettle-Bar', 'Barkasse'], ['PayPal', 'PayPal'], ['Zettle-Karte', 'Zettle (Karte)']]) {
    const nr = String(dmap[q] || '');
    if (nr && !topfe.some((t) => t[0] === nr)) topfe.push([nr, `${label} · ${nr}${kn[nr] ? ' ' + kn[nr] : ''}`]);
  }
  const pt = el('div', { class: 'tiles' });
  for (const [nr, label] of topfe) {
    const u = sal[nr];
    pt.append(el('div', { class: 'tile', style: 'cursor:pointer', onclick: () => showKontenblatt(nr, 'kontenplan') },
      el('div', { class: 'tile-v', style: u && u.saldo < 0 ? 'color:var(--err-ink)' : '' }, eur((u && u.saldo) || 0)),
      el('div', { class: 'tile-l' }, label)));
  }
  view.append(pt);
  view.append(el('p', { class: 'muted' }, 'Kachel anklicken → Kontenblatt mit allen Bewegungen.'));

  // Gegenprobe: weichen lokale und Server-Salden ab, stimmt eine Annahme nicht.
  if (serverSalden) {
    const diff = [];
    for (const s of serverSalden) {
      const l = sal[String(s.konto)];
      if (Math.abs((l ? l.saldo : 0) - Number(s.saldo || 0)) > 0.02) diff.push(`${s.konto}: App ${eur(l ? l.saldo : 0)} / Server ${eur(s.saldo)}`);
    }
    if (diff.length) {
      view.append(el('div', { class: 'note' },
        'Abweichung zwischen lokal gerechneten und Server-Salden – meist fehlt noch ein Sync oder das Plugin ist älter: ' + diff.slice(0, 6).join(' · ')));
    }
  }

  // Auffangkonten sichtbar machen.
  for (const nr of ['1590', '1599']) {
    const u = sal[nr];
    if (u && u.anzahl) {
      view.append(el('div', { class: 'note' },
        `${u.anzahl} Buchung(en) haben kein SKR-Konto und liegen auf ${nr} (${eur(u.saldo)}). `,
        el('button', { class: 'small', onclick: () => showKontenblatt(nr, 'kontenplan') }, 'anzeigen und zuordnen')));
    }
  }

  const groups = { '': 'Bank / Kasse / Bestand', einnahme: 'Einnahmen / Erträge', ausgabe: 'Ausgaben / Aufwand' };
  const byTyp = { '': [], einnahme: [], ausgabe: [] };
  const bekannt = new Set();
  for (const k of konten.slice().sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }))) {
    bekannt.add(String(k.nummer));
    byTyp[k.typ === 'einnahme' || k.typ === 'ausgabe' ? k.typ : ''].push(k);
  }
  // Konten, die gebucht wurden, aber nicht im Kontenplan stehen.
  for (const nr of Object.keys(sal).sort()) {
    if (!bekannt.has(nr)) byTyp[''].push({ nummer: nr, bezeichnung: '(nicht im Kontenplan)', sphaere: '', aktiv: 1 });
  }

  for (const [typ, label] of Object.entries(groups)) {
    if (!byTyp[typ].length) continue;
    view.append(el('h2', {}, label));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Nr'), el('th', {}, 'Bezeichnung'), el('th', {}, 'Sphäre'),
      el('th', { style: 'text-align:right' }, 'Soll'), el('th', { style: 'text-align:right' }, 'Haben'),
      el('th', { style: 'text-align:right' }, typ === '' ? 'Bestand' : 'Saldo'), el('th', { style: 'text-align:right' }, 'Buchungen'))));
    const tbb = el('tbody');
    let sumSaldo = 0;
    for (const k of byTyp[typ]) {
      const u = sal[String(k.nummer)];
      // Ertragskonten haben einen Haben-Saldo – für die Anzeige positiv drehen.
      const anz = u ? (typ === 'einnahme' ? -u.saldo : u.saldo) : 0;
      if (u) sumSaldo += anz;
      tbb.append(el('tr', { class: String(k.aktiv) === '0' ? 'muted' : '', style: 'cursor:pointer', title: 'Buchungen dieses Kontos anzeigen', onclick: () => showKontenblatt(String(k.nummer), 'kontenplan') },
        el('td', {}, k.nummer), el('td', {}, k.bezeichnung), el('td', {}, k.sphaere || '—'),
        el('td', { style: 'text-align:right' }, u && u.soll ? eur(u.soll) : '—'),
        el('td', { style: 'text-align:right' }, u && u.haben ? eur(u.haben) : '—'),
        el('td', { style: 'text-align:right;font-weight:600' + (anz < 0 ? ';color:var(--err-ink)' : '') }, u ? eur(anz) : '—'),
        el('td', { style: 'text-align:right' }, u && u.anzahl ? String(u.anzahl) : '')));
    }
    t.append(tbb);
    t.append(el('tfoot', {}, el('tr', {}, el('td', { colspan: '5' }, 'Summe'),
      el('td', { style: 'text-align:right;font-weight:700' }, eur(sumSaldo)), el('td', {}))));
    view.append(t);
  }
  view.append(el('p', { class: 'muted', style: 'margin-top:12px' },
    'Zeile anklicken → alle Buchungen des Kontos. ',
    el('button', { class: 'small', onclick: () => showTable('jb_konten') }, 'Kontenplan bearbeiten')));
}

/* ------------------------------------------------ Geld-Töpfe → Konto */

/**
 * Jede Buchung nennt einen Geld-Topf („quelle"). Welches Bestandskonto dahinter
 * liegt, steht in dieser Zuordnung – ändert man sie, wandern rückwirkend alle
 * Buchungen des Topfes auf das neue Konto. Deshalb hier bearbeitbar und nicht
 * nur im WordPress-Frontend.
 */
async function showQuellen(zurueck) {
  state.current = { name: 'quellen', zurueck };
  renderNav();
  view.innerHTML = '';
  if (zurueck) view.append(el('button', { class: 'ghost small', onclick: zurueck }, '‹ Zurück'));
  view.append(el('h1', {}, 'Geld-Töpfe → Konten'));
  view.append(el('p', { class: 'sub' },
    'Ändern wirkt rückwirkend auf alle Buchungen des jeweiligen Topfes – es muss nichts neu gebucht werden.'));

  let data;
  let konten = [];
  try {
    [data, konten] = await Promise.all([
      call(api.report.quelleMap()),
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows).catch(() => []),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message
      + ' – dafür muss das Plugin mindestens v0.27.0 sein und die App online.'));
  }

  const map = { ...(data.map || {}) };
  const anzahl = data.anzahl || {};
  const form = el('form', { class: 'detail' });
  const sel = {};
  for (const quelle of Object.keys(map)) {
    sel[quelle] = kontoSelect(konten, map[quelle], 'alle');
    const n = anzahl[quelle] || 0;
    form.append(el('label', {}, `${quelle}`, el('span', { class: 'muted' }, n ? ` · ${n} Buchungen` : ' · keine Buchungen')), sel[quelle]);
  }
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Zuordnung speichern'));
  if (data.editable === false) {
    actions.append(el('span', { class: 'tag' }, 'nur lesbar – Recht „Journal bearbeiten" fehlt'));
  }
  form.append(actions);
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const neu = {};
    for (const q of Object.keys(sel)) if (sel[q].value) neu[q] = sel[q].value;
    try {
      await call(api.report.saveQuelleMap(neu));
      toast('Zuordnung gespeichert.');
      showQuellen(zurueck);
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(form);
  view.append(el('p', { class: 'muted' },
    'Standard: Bank KSK→1200, Zettle-Bar/Bar→1000, PayPal→1220, Zettle-Karte/Umbuchung→1360, Auslage→1600, Manuell→1200.'));
}

/* --------------------------------------------------------- Z-Bon erfassen */

async function showZbon() {
  state.current = { name: 'zbon' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Z-Bon erfassen'));
  view.append(el('p', { class: 'sub' }, 'Zettle-Tagesbon in vier Buchungen aufteilen (Getränke Bar/Karte, Trinkgeld, Spende).'));

  let konten = [];
  let booked = [];
  try {
    [konten, booked] = await Promise.all([
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('jb_buchungen', { limit: 5000 })).then((r) => r.rows).catch(() => []),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const kOpts = (konten.length ? konten : [{ nummer: '4600', bezeichnung: 'Getränkeumsatz' }, { nummer: '4200', bezeichnung: 'Geldspenden' }])
    .filter((k) => k.typ !== 'ausgabe')
    .sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }))
    .map((k) => [String(k.nummer), `${k.nummer} – ${k.bezeichnung}`]);
  const pick = (val) => selectEl(kOpts.length ? kOpts : [['', '—']], val);

  // Nächste Z-Bon-Nummer aus den bereits gebuchten ZBON-Referenzen.
  const nums = booked
    .map((x) => String(x.beleg_referenz || '').match(/^ZBON-(\d+)$/))
    .filter(Boolean)
    .map((m) => Number(m[1]));
  const nextNr = nums.length ? Math.max(...nums) + 1 : 1;

  const f = el('form', { class: 'detail' });
  const nr = el('input', { value: String(nextNr), placeholder: '60' });
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const bar = moneyInput('', { placeholder: 'Zahlungsart Bar' });
  const karte = moneyInput('', { placeholder: 'Zahlungsart Karte' });
  const tip = moneyInput(0, { placeholder: 'Trinkgelder' });
  const spende = moneyInput(0, { placeholder: 'Produkt „Spende"' });
  const spWeg = selectEl([['bar', 'bar bezahlt'], ['karte', 'per Karte bezahlt']], 'bar');
  const kGetr = pick('4600');
  const kSpende = pick('4200');
  const kTip = pick('4200');
  f.append(
    'Z-Bon-Nr', nr, 'Datum', datum,
    'Bar (Zahlungsart)', bar, 'Karte (Zahlungsart)', karte,
    'Trinkgeld', tip, 'Produkt „Spende"', spende, 'Spende bezahlt', spWeg,
    'Konto Getränke', kGetr, 'Konto Spende', kSpende, 'Konto Trinkgeld', kTip
  );
  enterAdvances(f);
  view.append(el('div', { class: 'card' }, f));

  const prev = el('div', {});
  view.append(prev);
  const num = (x) => parseNum(x.value);

  function computeLines() {
    const spBar = spWeg.value === 'bar' ? num(spende) : 0;
    const spKarte = spWeg.value === 'karte' ? num(spende) : 0;
    const getrBar = +(num(bar) - spBar).toFixed(2);
    const getrKarte = +(num(karte) - num(tip) - spKarte).toFixed(2);
    const kn = (v) => (kOpts.find((o) => o[0] === v) || [v, v])[1];
    const lines = [];
    if (getrBar > 0) lines.push(['Getränke Bar', getrBar, kn(kGetr.value), 'Barkasse']);
    if (getrKarte > 0) lines.push(['Getränke Karte', getrKarte, kn(kGetr.value), 'PayPal']);
    if (num(tip) > 0) lines.push(['Trinkgeld (Spende)', +num(tip).toFixed(2), kn(kTip.value), 'PayPal']);
    if (num(spende) > 0) lines.push(['Spende', +num(spende).toFixed(2), kn(kSpende.value), spWeg.value === 'bar' ? 'Barkasse' : 'PayPal']);
    return { lines, getrBar, getrKarte };
  }

  function drawPreview() {
    const { lines, getrBar, getrKarte } = computeLines();
    prev.innerHTML = '';
    prev.append(el('h2', {}, 'Vorschau'));
    if (getrBar < 0 || getrKarte < 0) {
      return prev.append(el('div', { class: 'note err' }, 'Trinkgeld/Spende übersteigen den Bar- bzw. Kartenumsatz.'));
    }
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Buchung'), el('th', {}, 'Konto'), el('th', {}, 'Topf'), el('th', { style: 'text-align:right' }, 'Betrag'))));
    const tb = el('tbody');
    let sBar = 0;
    let sPP = 0;
    for (const [label, betrag, konto, topf] of lines) {
      tb.append(el('tr', {}, el('td', {}, `${label} Z-Bon #${nr.value || '?'}`), el('td', {}, konto), el('td', {}, topf), el('td', { style: 'text-align:right' }, eur(betrag))));
      if (topf === 'Barkasse') sBar += betrag;
      else sPP += betrag;
    }
    t.append(tb);
    prev.append(t);
    prev.append(el('p', { class: 'muted' }, `→ Barkasse ${eur(sBar)} (soll: ${eur(num(bar))}) · PayPal ${eur(sPP)} (soll: ${eur(num(karte))})`));
    const btn = el('button', { class: 'primary', style: 'margin-top:8px' }, 'Buchen');
    btn.addEventListener('click', book);
    prev.append(btn);
  }

  async function book() {
    if (!nr.value.trim()) return toast('Z-Bon-Nummer fehlt.', true);
    try {
      const r = await call(api.action.run('zbon-import', {
        nr: nr.value, datum: datum.value, bar: num(bar), karte: num(karte), trinkgeld: num(tip),
        spende_produkt: num(spende), spende_bezahlung: spWeg.value,
        konto_getraenke: kGetr.value, konto_spende: kSpende.value, konto_trinkgeld: kTip.value,
      }));
      toast(`${(r.booked_ids || []).length} Buchung(en) angelegt.`);
      await runSyncQuiet();
      showZbon();
    } catch (e) {
      toast(e.message, true);
    }
  }

  [nr, bar, karte, tip, spende, spWeg, kGetr, kSpende, kTip].forEach((n) => n.addEventListener('input', drawPreview));
  f.addEventListener('submit', (e) => { e.preventDefault(); book(); });
  drawPreview();

  // Bereits gebuchte Z-Bons
  const done = [...new Set(booked.filter((x) => String(x.beleg_referenz || '').startsWith('ZBON-')).map((x) => x.beleg_referenz))].sort();
  if (done.length) {
    view.append(el('h2', {}, 'Bereits gebucht'));
    view.append(el('p', { class: 'muted' }, done.map((d) => d.replace('ZBON-', '#')).join(' · ')));
  }
}

/* --------------------------------------------------------- Kontenblätter (Doppik) */

async function showKontenblaetter() {
  state.current = { name: 'kontenblaetter' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Kontenblätter (Doppik)'));
  view.append(el('p', { class: 'sub' }, 'Sicht auf dieselben Daten in Soll/Haben. EÜR und Exporte bleiben unverändert.'));
  let data;
  try {
    data = await call(api.report.salden());
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message + ' – online sein und Recht „Buchungsjournal sehen“.'));
  }
  const groups = { bestand: 'Bestands-/Geldkonten', einnahme: 'Einnahmen (Erträge)', ausgabe: 'Ausgaben (Aufwand)', '': 'Neutral / Sonstige' };
  const byTyp = { bestand: [], einnahme: [], ausgabe: [], '': [] };
  for (const s of data.salden || []) (byTyp[['bestand', 'einnahme', 'ausgabe'].includes(s.typ) ? s.typ : ''] ||= []).push(s);
  for (const [typ, label] of Object.entries(groups)) {
    if (!byTyp[typ] || !byTyp[typ].length) continue;
    view.append(el('h2', {}, label));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Konto'), el('th', {}, 'Bezeichnung'),
      el('th', { style: 'text-align:right' }, 'Soll'), el('th', { style: 'text-align:right' }, 'Haben'), el('th', { style: 'text-align:right' }, 'Saldo'))));
    const tb = el('tbody');
    for (const s of byTyp[typ].sort((a, b) => String(a.konto).localeCompare(String(b.konto), 'de', { numeric: true }))) {
      tb.append(el('tr', { style: 'cursor:pointer', onclick: () => showKontenblatt(s.konto) },
        el('td', {}, s.konto), el('td', {}, s.name || '—'),
        el('td', { style: 'text-align:right' }, s.soll ? eur(s.soll) : '—'),
        el('td', { style: 'text-align:right' }, s.haben ? eur(s.haben) : '—'),
        el('td', { style: 'text-align:right;font-weight:600' }, eur(s.saldo))));
    }
    t.append(tb);
    view.append(t);
  }
  if (!(data.salden || []).length) view.append(el('div', { class: 'note' }, 'Noch keine Buchungen.'));
}

async function showKontenblatt(konto, from = 'blaetter', jahr = '') {
  state.current = { name: 'kontenblatt', konto, from, jahr };
  renderNav();
  view.innerHTML = '';
  const back = from === 'kontenplan' ? showKontenplan : showKontenblaetter;
  view.append(el('button', { class: 'ghost small', onclick: back }, from === 'kontenplan' ? '‹ Kontenplan' : '‹ Alle Konten'));
  view.append(el('h1', {}, `Kontenblatt ${konto}`));
  let kb;
  try {
    kb = await call(api.report.kontenblatt(konto, jahr || undefined));
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const zeilen = kb.zeilen || [];

  // Aufschlüsselung nach Gegenkonto – zeigt sofort, welche Vorgänge einen
  // Bestand treiben (z. B. warum die Barkasse ins Minus läuft).
  const proGegen = new Map();
  for (const z of zeilen) {
    const m = /\(Gegenkonto ([^)]+)\)/.exec(String(z.text || ''));
    const g = m ? m[1] : '—';
    const e = proGegen.get(g) || { soll: 0, haben: 0, n: 0 };
    e.soll += Number(z.soll) || 0;
    e.haben += Number(z.haben) || 0;
    e.n++;
    proGegen.set(g, e);
  }
  if (proGegen.size > 1) {
    const box = el('details', { class: 'card' });
    box.append(el('summary', {}, 'Woraus setzt sich der Saldo zusammen? (nach Gegenkonto)'));
    const gt = el('table');
    gt.append(el('thead', {}, el('tr', {}, el('th', {}, 'Gegenkonto'),
      el('th', { style: 'text-align:right' }, 'Zugang (Soll)'), el('th', { style: 'text-align:right' }, 'Abgang (Haben)'),
      el('th', { style: 'text-align:right' }, 'Wirkung'), el('th', { style: 'text-align:right' }, 'Anzahl'))));
    const gtb = el('tbody');
    for (const [g, e] of [...proGegen.entries()].sort((a, b) => Math.abs(b[1].soll - b[1].haben) - Math.abs(a[1].soll - a[1].haben))) {
      const w = Math.round((e.soll - e.haben) * 100) / 100;
      gtb.append(el('tr', { style: g !== '—' ? 'cursor:pointer' : '', onclick: g !== '—' ? () => showKontenblatt(g, from, jahr) : null },
        el('td', {}, g),
        el('td', { style: 'text-align:right' }, e.soll ? eur(e.soll) : '—'),
        el('td', { style: 'text-align:right' }, e.haben ? eur(e.haben) : '—'),
        el('td', { style: 'text-align:right;font-weight:600' + (w < 0 ? ';color:var(--err-ink)' : '') }, eur(w)),
        el('td', { style: 'text-align:right' }, String(e.n))));
    }
    gt.append(gtb);
    box.append(gt);
    view.append(box);
  }

  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Text'),
    el('th', { style: 'text-align:right' }, 'Soll'), el('th', { style: 'text-align:right' }, 'Haben'), el('th', { style: 'text-align:right' }, 'Saldo'))));
  const tb = el('tbody');
  for (const z of zeilen) {
    tb.append(el('tr', { style: z.id ? 'cursor:pointer' : '', onclick: z.id ? () => showBuchung(z.id, { name: 'kontenblatt', konto, from }) : null },
      el('td', {}, z.datum || '—'), el('td', {}, z.text || '—'),
      el('td', { style: 'text-align:right' }, z.soll ? eur(z.soll) : ''),
      el('td', { style: 'text-align:right' }, z.haben ? eur(z.haben) : ''),
      el('td', { style: 'text-align:right' + (Number(z.saldo) < 0 ? ';color:var(--err-ink)' : '') }, eur(z.saldo))));
  }
  tb.append(el('tr', { style: 'font-weight:700' }, el('td', { colspan: '4' }, 'Endsaldo'), el('td', { style: 'text-align:right' }, eur(kb.endsaldo))));
  t.append(tb);
  view.append(t);
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

const jState = { year: '', konto: '', sphaere: '', quelle: '', q: '', panel: null, sortBy: 'datum', sortDir: 'asc', sel: new Set(), view: 'euer' };

const DOPPIK_MAP_DEFAULT = {
  'Bank KSK': '1200', 'Zettle-Bar': '1000', Bar: '1000', PayPal: '1220',
  'Zettle-Karte': '1360', Auslage: '1600', Umbuchung: '1360', Manuell: '1200',
};
/** Soll/Haben aus einer jb_buchungen-Zeile ableiten (Spiegel von vp_doppik_satz). */
function doppikSatz(r, map) {
  map = map || DOPPIK_MAP_DEFAULT;
  const betrag = Number(r.betrag) || 0;
  const abs = Math.round(Math.abs(betrag) * 100) / 100;
  const konto = String(r.konto || '');
  const gegen = String(r.gegenkonto || '');
  const geld = gegen || map[r.quelle] || map.Manuell || '1200';
  // Ohne SKR-Konto gegen das Verrechnungskonto buchen – sonst stünde auf
  // beiden Seiten dasselbe Geldkonto und der Betrag verschwände aus dem Saldo.
  const sach = konto || (geld === '1590' ? '1599' : '1590');
  let soll;
  let haben;
  if (gegen) { soll = sach; haben = gegen; }
  else if (betrag >= 0) { soll = geld; haben = sach; }
  else { soll = sach; haben = geld; }
  return { soll, haben, betrag: abs, datum: r.buchung_datum || '', text: `${r.gegenpartei || ''} – ${r.beschreibung || ''}`.replace(/^ – | – $/, '') };
}

/**
 * Anfangsbestände für ein Jahr aus den gespiegelten `jb_anfangsbestaende`.
 * Basisjahr = jüngstes hinterlegtes Jahr, das nicht nach `jahr` liegt.
 */
function anfangFuerJahr(bestRows, jahr) {
  const ziel = Number(jahr) || new Date().getFullYear();
  let basis = 0;
  for (const r of bestRows || []) {
    const j = Number(r.jahr) || 0;
    if (j && j <= ziel && j > basis) basis = j;
  }
  const anfang = {};
  if (basis) {
    for (const r of bestRows || []) {
      if (Number(r.jahr) !== basis) continue;
      const k = String(r.konto || '');
      if (k) anfang[k] = (anfang[k] || 0) + (Number(r.betrag) || 0);
    }
  }
  return { basis, anfang };
}

/**
 * Salden je Konto aus den gespiegelten Buchungen – Spiegel von
 * vp_doppik_salden(). Bewusst lokal gerechnet: der Kontenplan funktioniert
 * damit offline und bleibt auch dann gefüllt, wenn der Report-Endpunkt hakt.
 */
function saldenLokal(rows, anfang, fenster, map) {
  const acc = {};
  const bump = (k, s, h, zaehlen = true) => {
    if (!k) return;
    if (!acc[k]) acc[k] = { konto: k, soll: 0, haben: 0, anzahl: 0 };
    acc[k].soll += s;
    acc[k].haben += h;
    if (zaehlen) acc[k].anzahl++;
  };
  for (const [k, v] of Object.entries(anfang || {})) bump(k, v > 0 ? v : 0, v < 0 ? -v : 0, false);
  const von = (fenster && fenster.von) || '';
  const bis = (fenster && fenster.bis) || '';
  for (const r of rows || []) {
    const d = String(r.buchung_datum || '');
    if (von && d < von) continue;
    if (bis && d > bis) continue;
    const s = doppikSatz(r, map);
    bump(s.soll, s.betrag, 0);
    bump(s.haben, 0, s.betrag);
  }
  for (const a of Object.values(acc)) {
    a.soll = Math.round(a.soll * 100) / 100;
    a.haben = Math.round(a.haben * 100) / 100;
    a.saldo = Math.round((a.soll - a.haben) * 100) / 100;
  }
  return acc;
}

async function showJournal() {
  state.current = { name: 'journal', slug: 'jb_buchungen' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Buchungsjournal'));
  view.append(el('p', { class: 'sub' }, 'EÜR-Journal mit laufendem Saldo. Neue Buchungen gehen sofort online.'));

  let rows = [];
  let konten = [];
  let ruecklagen = [];
  let budgets = [];
  let dmap = DOPPIK_MAP_DEFAULT;
  try {
    [rows, konten, ruecklagen, budgets] = await Promise.all([
      call(api.data.rows('jb_buchungen', { limit: 5000 })).then((r) => r.rows),
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('jb_ruecklagen', { limit: 500 })).then((r) => r.rows.filter((x) => String(x.aktiv) !== '0')).catch(() => []),
      call(api.data.rows('jb_budgets', { limit: 500 })).then((r) => r.rows.filter((x) => String(x.aktiv) !== '0')).catch(() => []),
    ]);
    const s = await call(api.report.salden()).catch(() => null);
    if (s && s.map) dmap = { ...DOPPIK_MAP_DEFAULT, ...s.map };
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const kmapName = Object.fromEntries((konten || []).map((k) => [String(k.nummer), k.bezeichnung]));

  // Aktionen
  jState.sel = new Set();
  const acts = el('div', { class: 'toolbar' });
  acts.append(
    el('button', { class: 'primary small', onclick: () => togglePanel('add', () => journalForm(konten, ruecklagen, budgets)) }, '+ Buchung'),
    el('button', { class: 'small', onclick: () => togglePanel('transfer', () => umbuchungForm(konten)) }, '⇄ Umbuchung'),
    el('button', { class: 'small', onclick: () => togglePanel('csv', () => bankCsvForm(konten)) }, '⇑ Bank-CSV importieren'),
    el('span', { style: 'flex:1' }),
    el('button', { class: 'small' + (jState.view === 'euer' ? ' primary' : ''), onclick: () => { jState.view = 'euer'; draw(); } }, 'EÜR'),
    el('button', { class: 'small' + (jState.view === 'doppik' ? ' primary' : ''), onclick: () => { jState.view = 'doppik'; draw(); } }, 'Doppik')
  );
  view.append(acts);
  const panelHost = el('div', {});
  view.append(panelHost);
  jState.panelHost = panelHost;
  jState.panel = null;

  const selBar = el('div', { class: 'toolbar', hidden: true });
  view.append(selBar);
  function renderSelBar() {
    selBar.innerHTML = '';
    const n = jState.sel.size;
    selBar.hidden = n === 0;
    if (!n) return;
    selBar.append(el('span', {}, `${n} ausgewählt`));
    selBar.append(el('button', { class: 'small danger', onclick: batchDelete }, `${n} löschen`));
    if (n === 1) selBar.append(el('button', { class: 'small', onclick: () => togglePanel('split', () => splitForm(rows.find((x) => String(x.id) === [...jState.sel][0]), konten, ruecklagen)) }, 'Aufteilen'));
    if (n === 1 || n === 2) selBar.append(el('button', { class: 'small', onclick: () => togglePanel('umb', () => zuUmbuchungForm([...jState.sel], rows, konten)) }, 'Zu Umbuchung'));
    selBar.append(el('button', { class: 'small', onclick: flipSign }, '± Vorzeichen umdrehen'));
    selBar.append(el('button', { class: 'small ghost', onclick: () => { jState.sel.clear(); draw(); renderSelBar(); } }, 'Auswahl aufheben'));
  }
  async function batchDelete() {
    if (!confirm(`${jState.sel.size} Buchung(en) löschen? Wird beim nächsten Sync auch auf dem Server gelöscht.`)) return;
    try {
      for (const id of jState.sel) await call(api.data.remove('jb_buchungen', id));
      toast(`${jState.sel.size} zum Löschen vorgemerkt.`);
      jState.sel.clear();
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  }

  /**
   * Vorzeichen der ausgewählten Buchungen umdrehen. Gedacht für Geld-Transfers,
   * die versehentlich in die falsche Richtung erfasst wurden (z. B. Wechselgeld
   * von der Bank in die Kasse als Einnahme statt als Abgang).
   */
  async function flipSign() {
    const ids = [...jState.sel];
    const betroffen = rows.filter((r) => ids.includes(String(r.id)));
    const summe = betroffen.reduce((s, r) => s + (Number(r.betrag) || 0), 0);
    if (!confirm(`Bei ${ids.length} Buchung(en) das Vorzeichen umdrehen?\n\nSumme jetzt: ${eur(summe)}\nSumme danach: ${eur(-summe)}`)) return;
    try {
      for (const r of betroffen) {
        await call(api.data.save('jb_buchungen', r.id, { betrag: String(-(Number(r.betrag) || 0)) }));
      }
      toast(`${betroffen.length} Buchung(en) gedreht (wird beim Sync gesendet).`);
      jState.sel.clear();
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  }

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

  function filtered() {
    return rows.filter((r) => {
      if (jState.year && String(r.buchung_datum || '').slice(0, 4) !== jState.year) return false;
      if (jState.konto && String(r.konto) !== jState.konto && String(r.gegenkonto) !== jState.konto) return false;
      if (jState.sphaere && String(r.sphaere) !== jState.sphaere) return false;
      if (jState.q) {
        const hay = `${r.beschreibung} ${r.kategorie} ${r.gegenpartei} ${r.beleg_nr}`.toLowerCase();
        if (!hay.includes(jState.q.toLowerCase())) return false;
      }
      return true;
    });
  }

  function drawDoppik() {
    const f = filtered().slice().sort((a, b) => String(a.buchung_datum).localeCompare(String(b.buchung_datum)) || Number(a.id) - Number(b.id));
    const lbl = (nr) => (nr ? `${nr}${kmapName[nr] ? ' ' + kmapName[nr] : ''}` : '—');
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Soll'), el('th', {}, 'Haben'), el('th', { style: 'text-align:right' }, 'Betrag'), el('th', {}, 'Text'))));
    const tb = el('tbody');
    for (const r of f) {
      const s = doppikSatz(r, dmap);
      tb.append(el('tr', { style: 'cursor:pointer', onclick: () => showBuchung(r.id) },
        el('td', {}, s.datum), el('td', {}, lbl(s.soll)), el('td', {}, lbl(s.haben)),
        el('td', { style: 'text-align:right' }, eur(s.betrag)), el('td', {}, s.text || '—')));
    }
    t.append(tb);
    tableHost.innerHTML = '';
    tableHost.append(t);
    tableHost.append(el('p', { class: 'muted' }, `${f.length} Buchungssatz/-sätze · Ansicht Doppik (Soll/Haben)`));
  }

  function draw() {
    renderSelBar();
    if (jState.view === 'doppik') return drawDoppik();
    const kmap = kmapName;
    let f = filtered();

    // Laufender Saldo immer chronologisch berechnen …
    const chrono = f.slice().sort((a, b) => String(a.buchung_datum).localeCompare(String(b.buchung_datum)) || Number(a.id) - Number(b.id));
    let acc = 0;
    const saldoOf = new Map();
    for (const r of chrono) {
      acc += Number(r.betrag) || 0;
      saldoOf.set(r.id, acc);
    }

    // … Anzeige-Sortierung frei wählbar
    const key = jState.sortBy;
    const dir = jState.sortDir === 'desc' ? -1 : 1;
    const val = (r) =>
      key === 'betrag' ? Number(r.betrag) || 0
      : key === 'konto' ? String(r.konto || r.kategorie || '')
      : key === 'beschreibung' ? String(r.beschreibung || r.kategorie || '').toLowerCase()
      : key === 'quelle' ? String(r.quelle || '')
      : key === 'sphaere' ? String(r.sphaere || '')
      : String(r.buchung_datum || '');
    f = f.slice().sort((a, b) => {
      const x = val(a), y = val(b);
      return (typeof x === 'number' ? x - y : String(x).localeCompare(y)) * dir || (Number(a.id) - Number(b.id)) * dir;
    });
    const showSaldo = key === 'datum';

    const th = (label, sortKey, right) => {
      const active = jState.sortBy === sortKey;
      const arrow = active ? (jState.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
      return el('th', {
        style: `cursor:pointer;user-select:none;${right ? 'text-align:right' : ''}`,
        onclick: () => {
          if (jState.sortBy === sortKey) jState.sortDir = jState.sortDir === 'asc' ? 'desc' : 'asc';
          else { jState.sortBy = sortKey; jState.sortDir = sortKey === 'betrag' ? 'desc' : 'asc'; }
          draw();
        },
      }, label + arrow);
    };

    const allChk = el('input', { type: 'checkbox' });
    allChk.checked = f.length > 0 && f.every((r) => jState.sel.has(String(r.id)));
    allChk.addEventListener('change', () => {
      if (allChk.checked) f.forEach((r) => jState.sel.add(String(r.id)));
      else jState.sel.clear();
      draw();
      renderSelBar();
    });

    const t = el('table');
    t.append(el('thead', {}, el('tr', {},
      el('th', {}, allChk), th('Datum', 'datum'), th('Beschreibung', 'beschreibung'), th('Konto', 'konto'),
      th('Sphäre', 'sphaere'), th('Quelle', 'quelle'), th('Betrag', 'betrag', true), el('th', { style: 'text-align:right' }, 'Saldo'))));
    const tb = el('tbody');
    for (const r of f) {
      const b = Number(r.betrag) || 0;
      const chk = el('input', { type: 'checkbox' });
      chk.checked = jState.sel.has(String(r.id));
      chk.addEventListener('click', (e) => e.stopPropagation());
      chk.addEventListener('change', () => {
        chk.checked ? jState.sel.add(String(r.id)) : jState.sel.delete(String(r.id));
        renderSelBar();
      });
      const descCell = el('td', {}, (r.beleg_nr ? `[${r.beleg_nr}] ` : '') + (r.beschreibung || r.kategorie || ''));
      if (r.beleg_pfad) {
        descCell.append(' ');
        descCell.append(el('span', { style: 'cursor:pointer', title: 'Beleg öffnen', onclick: (e) => { e.stopPropagation(); openBeleg(r.beleg_pfad); } }, '📎'));
      }
      tb.append(el('tr', { class: r._dirty ? 'dirty' : '', style: 'cursor:pointer', onclick: () => showBuchung(r.id) },
        el('td', {}, chk),
        el('td', {}, r.buchung_datum),
        descCell,
        el('td', {}, r.konto ? `${r.konto} ${kmap[r.konto] ? '– ' + kmap[r.konto] : ''}` : (r.kategorie || '—')),
        el('td', {}, r.sphaere || '—'),
        el('td', {}, r.quelle || '—'),
        el('td', { style: 'text-align:right;color:' + (b < 0 ? 'var(--err-ink)' : 'var(--accent)') }, eur(b)),
        el('td', { style: 'text-align:right' }, showSaldo ? eur(saldoOf.get(r.id) || 0) : '—')));
    }
    t.append(tb);
    tableHost.innerHTML = '';
    tableHost.append(t);
    tableHost.append(el('p', { class: 'muted' }, `${f.length} Buchung(en) · Summe: ${eur(f.reduce((s, r) => s + (Number(r.betrag) || 0), 0))}` + (showSaldo ? '' : ' · Saldo nur bei Sortierung „Datum“')));
  }
  draw();
  renderSelBar();
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

/* ------------------------------------------- Erklärtexte zu den Buchungsfeldern */

/**
 * Eine Buchung beantwortet drei verschiedene Fragen. Die Kurztexte hängen an
 * den Feldern, der ausführliche Block klappt darüber auf.
 */
const FELD_HILFE = {
  quelle:
    'WO das Geld liegt. Bei negativem Betrag geht es aus diesem Topf raus, bei positivem rein. '
    + 'Dahinter steht ein Bestandskonto (Bank 1200, Kasse 1000 …).',
  konto:
    'WOFÜR das Geld geflossen ist – der Zweck (z. B. 4100 Mitgliedsbeiträge, 5600 Wareneinkauf). '
    + 'Dieses Konto steht in der EÜR, der Geldtopf nicht.',
  gegenkonto:
    'Nur bei Umbuchungen: der zweite Geldtopf. Beispiel Bargeld zur Bank gebracht – Konto 1200, Gegenkonto 1000.',
  gegenpartei:
    'MIT WEM – reiner Text, kein Konto (z. B. „Bauhaus", „Anna Müller"). Nur für Suche und Beleg, '
    + 'verändert keine Auswertung.',
  sphaere:
    'Steuerlicher Bereich des Zwecks: ideell, Zweckbetrieb, Vermögensverwaltung oder wirtschaftlicher '
    + 'Geschäftsbetrieb. Ergibt sich normalerweise aus dem SKR-Konto.',
  soll:
    'Wohin der Betrag fließt: das Geldkonto bei einer Einnahme, das Aufwandskonto bei einer Ausgabe.',
  haben:
    'Woher der Betrag kommt: das Ertragskonto bei einer Einnahme, das Geldkonto bei einer Ausgabe.',
};

/** Aufklappbarer Erklärblock „Geldtopf, Konto und Gegenpartei". */
function buchungsHilfe(offen) {
  const d = el('details', { class: 'hilfe' });
  if (offen) d.setAttribute('open', 'open');
  d.append(el('summary', {}, 'Geldtopf, SKR-Konto und Gegenpartei – was ist was?'));
  d.append(el('p', { class: 'muted', style: 'margin:8px 0 0' },
    'Jede Buchung beantwortet drei verschiedene Fragen. Nur die ersten beiden sind Konten.'));
  const dl = el('dl', {});
  const zeile = (t, txt) => { dl.append(el('dt', {}, t), el('dd', {}, txt)); };
  zeile('Geldtopf (Quelle) — wo?',
    'Welches Geld sich bewegt: Bank KSK, Barkasse, PayPal, Zettle-Karte. Negativer Betrag = raus aus diesem Topf, '
    + 'positiver = rein. Der Geldtopf ist ein Bestandskonto – er sagt, wie viel Geld ihr habt. '
    + 'Welches Konto dahinter liegt, steht unter „Geld-Töpfe → Konten".');
  zeile('SKR-Konto — wofür?',
    'Der Grund der Bewegung: 4100 Mitgliedsbeiträge, 4600 Getränkeumsatz, 5600 Wareneinkauf. '
    + 'Das ist ein Erfolgskonto – es sagt, ob ihr etwas eingenommen oder ausgegeben habt, und nur dieses '
    + 'Konto erscheint in der EÜR.');
  zeile('Gegenpartei — mit wem?',
    'Freitext, kein Konto: „Bauhaus", „Stadt Riedlingen", „Anna Müller". Dient nur dem Wiederfinden und dem '
    + 'Beleg und beeinflusst keine Auswertung.');
  d.append(dl);
  d.append(el('p', { class: 'bsp' },
    'Beispiel Einnahme: Beitrag 30 € per Überweisung → Betrag +30, Geldtopf Bank KSK, Konto 4100 Mitgliedsbeiträge, '
    + 'Gegenpartei „Anna Müller".'));
  d.append(el('p', { class: 'bsp' },
    'Beispiel Ausgabe: Getränke bar gekauft für 84,20 € → Betrag −84,20, Geldtopf Barkasse, Konto 5600 Wareneinkauf, '
    + 'Gegenpartei „Getränke Müller GmbH".'));
  d.append(el('p', { class: 'bsp' },
    'Jede Buchung berührt genau einen Geldtopf und ein SKR-Konto – das sind die beiden Seiten des Buchungssatzes '
    + '(Soll und Haben). Nur Umbuchungen berühren zwei Geldtöpfe und gar kein SKR-Konto.'));
  return d;
}

/** Beschriftung mit Erklärung darunter (funktioniert in allen detail-Formularen). */
function labelMitHilfe(text, hint) {
  return el('label', {}, text, hint ? el('span', { class: 'hint' }, hint) : null);
}

/* ---------------------------------------- Buchung bearbeiten (Doppik-Form) */

/** Bevorzugte „quelle" je Geldkonto – Umkehrung der Doppik-Zuordnung. */
const QUELLE_PREF = ['Bank KSK', 'Zettle-Bar', 'PayPal', 'Zettle-Karte', 'Auslage', 'Manuell', 'Bar', 'Umbuchung'];
function quelleFuerKonto(konto, map) {
  const m = map || DOPPIK_MAP_DEFAULT;
  for (const q of QUELLE_PREF) if (String(m[q]) === String(konto)) return q;
  for (const [q, k] of Object.entries(m)) if (String(k) === String(konto)) return q;
  return 'Manuell';
}

/**
 * Soll/Haben/Betrag → gespeicherte jb_buchungen-Spalten. Genaue Umkehrung von
 * `doppikSatz()` bzw. `vp_doppik_satz()`, damit ein Bearbeiten die Ansicht
 * nicht verändert.
 */
function satzZuZeile(soll, haben, betrag, konten, map) {
  const typOf = (nr) => String(((konten || []).find((k) => String(k.nummer) === String(nr)) || {}).typ || '');
  // Die Verrechnungskonten stehen für ein noch fehlendes Sachkonto und zählen
  // deshalb als Erfolgsseite – sonst würde aus einer unzugeordneten Ausgabe
  // beim Speichern eine vorzeichenlose Umbuchung.
  const erfolg = (nr) => ['1590', '1599'].includes(String(nr)) || typOf(nr) === 'einnahme' || typOf(nr) === 'ausgabe';
  const sphOf = (nr) => String(((konten || []).find((k) => String(k.nummer) === String(nr)) || {}).sphaere || '');
  const b = Math.round(Math.abs(Number(betrag) || 0) * 100) / 100;
  const sE = erfolg(soll);
  const hE = erfolg(haben);
  // Ausgabe
  if (sE && !hE) return { konto: soll, gegenkonto: '', betrag: -b, quelle: quelleFuerKonto(haben, map), geldkonto: String(haben), sphaere: sphOf(soll) };
  // Einnahme
  if (!sE && hE) return { konto: haben, gegenkonto: '', betrag: b, quelle: quelleFuerKonto(soll, map), geldkonto: String(soll), sphaere: sphOf(haben) };
  // Bestand ↔ Bestand: beide Konten stehen direkt in der Zeile, die Quelle ist
  // dann nur noch ein Etikett.
  return { konto: soll, gegenkonto: haben, betrag: b, quelle: 'Umbuchung', geldkonto: '', sphaere: 'neutral' };
}

/**
 * Prüft, ob das gewählte Geldkonto überhaupt über einen Geld-Topf erreichbar
 * ist. Ist es das nicht, würde beim Speichern die Quelle auf „Manuell" fallen
 * und die Buchung landete auf einem ganz anderen Konto.
 */
function quelleStimmt(z, map) {
  const m = map || DOPPIK_MAP_DEFAULT;
  if (!z.geldkonto) return true;
  return String(m[z.quelle] || '') === String(z.geldkonto);
}

async function showBuchung(pk, from) {
  state.current = { name: 'buchung', pk, from };
  renderNav();
  view.innerHTML = '';
  const back = () =>
    from && from.name === 'kontenblatt' ? showKontenblatt(from.konto, from.from) : showJournal();
  view.append(el('button', { class: 'ghost small', onclick: back }, '‹ Zurück'));
  view.append(el('h1', {}, 'Buchung bearbeiten'));

  let data;
  let konten = [];
  let budgets = [];
  let ruecklagen = [];
  let auslagen = [];
  let alle = [];
  let dmap = DOPPIK_MAP_DEFAULT;
  // Ob die Zuordnung wirklich vom Server kommt: nur dann darf das Speichern
  // wegen einer nicht zugeordneten Quelle abgelehnt werden.
  let mapEcht = false;
  let mapFehler = '';
  try {
    [data, konten, budgets, ruecklagen, auslagen, alle] = await Promise.all([
      call(api.data.row('jb_buchungen', pk)),
      call(api.data.rows('jb_konten', { limit: 2000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('jb_budgets', { limit: 500 })).then((r) => r.rows.filter((x) => String(x.aktiv) !== '0')).catch(() => []),
      call(api.data.rows('jb_ruecklagen', { limit: 500 })).then((r) => r.rows.filter((x) => String(x.aktiv) !== '0')).catch(() => []),
      call(api.data.rows('jb_auslagen', { limit: 1000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('jb_buchungen', { limit: 5000 })).then((r) => r.rows).catch(() => []),
    ]);
    const s = await call(api.report.salden()).catch((e) => { mapFehler = e.message; return null; });
    if (s && s.map) { dmap = { ...DOPPIK_MAP_DEFAULT, ...s.map }; mapEcht = true; }
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  if (mapFehler) {
    view.append(el('div', { class: 'note' },
      'Zuordnung der Geld-Töpfe konnte nicht geladen werden (' + mapFehler + ') – es gilt die Standardzuordnung. '
      + 'Prüfe die Konten unten besonders sorgfältig.'));
  }

  const row = (data && data.row) || {};
  const cols = new Set((state.meta && state.meta.tables.jb_buchungen && state.meta.tables.jb_buchungen.columns) || Object.keys(row));
  const satz = doppikSatz(row, dmap);
  const kname = (nr) => {
    const k = konten.find((x) => String(x.nummer) === String(nr));
    return nr ? `${nr}${k ? ' – ' + k.bezeichnung : ''}` : '—';
  };

  // Offener Konflikt? Dann kommt hier nichts durch, bis er entschieden ist –
  // das muss man sehen, sonst wirkt „Speichern" wie ein stiller Fehlschlag.
  try {
    const offen = (await call(api.conflicts.list())) || [];
    if (offen.some((k) => k.tbl === 'jb_buchungen' && String(k.pk) === String(pk))) {
      view.append(el('div', { class: 'note err' },
        'Diese Buchung hat einen offenen Konflikt: sie wurde hier und auf dem Server geändert. '
        + 'Solange er offen ist, wird nichts gespeichert. ',
        el('button', { class: 'small', onclick: showConflicts }, 'Konflikt entscheiden')));
    }
  } catch { /* Konfliktliste ist nur ein Hinweis */ }

  if (!String(row.konto || '')) {
    view.append(el('div', { class: 'note' },
      'Diese Buchung hat noch kein SKR-Konto – sie liegt deshalb auf dem Verrechnungskonto 1590. '
      + 'Bitte unten das passende Sachkonto als Soll bzw. Haben wählen.'));
  }

  const form = el('form', { class: 'detail' });
  const sec = (titel, hinweis) => {
    form.append(el('h2', { style: 'grid-column:1/-1;margin:16px 0 0' }, titel));
    if (hinweis) form.append(el('p', { class: 'muted', style: 'grid-column:1/-1;margin:0' }, hinweis));
  };
  const feld = (label, node, hint) => { form.append(labelMitHilfe(label, hint), node); };
  view.append(buchungsHilfe(false));

  /* --- 1. Buchungssatz ---------------------------------------------------- */
  sec('Buchungssatz', 'Soll an Haben – Betrag immer positiv. Quelle, Gegenkonto und Vorzeichen ergeben sich daraus automatisch.');
  const fDatum = el('input', { type: 'date', value: row.buchung_datum || '' });
  const fBetrag = moneyInput(satz.betrag);
  const fSoll = kontoSelect(konten, satz.soll, 'alle');
  const fHaben = kontoSelect(konten, satz.haben, 'alle');
  feld('Datum', fDatum);
  feld('Betrag (€)', fBetrag);
  feld('Soll (Empfänger / Aufwand)', fSoll, FELD_HILFE.soll);
  feld('Haben (Herkunft / Ertrag)', fHaben, FELD_HILFE.haben);

  const vorschau = el('p', { class: 'muted', style: 'grid-column:1/-1;margin:0' });
  form.append(vorschau);
  // Direkter Weg aus der Warnung heraus – die Zuordnung ist der eigentliche Fix.
  const fixBtn = el('button', {
    type: 'button', class: 'small', hidden: true, style: 'grid-column:1/-1;justify-self:start',
    onclick: () => showQuellen(() => showBuchung(pk, from)),
  }, 'Geld-Töpfe → Konten öffnen');
  form.append(fixBtn);

  /* --- 2. Beschreibung ---------------------------------------------------- */
  sec('Beschreibung');
  const fText = el('textarea', {});
  fText.value = row.beschreibung || '';
  const dlGp = el('datalist', { id: 'dl_gegenpartei' });
  for (const g of [...new Set(alle.map((r) => String(r.gegenpartei || '').trim()).filter(Boolean))].sort()) {
    dlGp.append(el('option', { value: g }));
  }
  form.append(dlGp);
  const fGegenpartei = el('input', { type: 'text', value: row.gegenpartei || '', list: 'dl_gegenpartei', placeholder: 'z. B. Bauhaus, Mitglied Müller' });
  feld('Beschreibung', fText);
  feld('Gegenpartei (mit wem?)', fGegenpartei, FELD_HILFE.gegenpartei);

  /* --- 3. Zuordnung ------------------------------------------------------- */
  sec('Zuordnung');
  const fSphaere = selectEl(SPHAERE_OPTS, row.sphaere || satz.sphaere || '');
  let sphaereTouched = false;
  fSphaere.addEventListener('change', () => { sphaereTouched = true; });
  const fBudget = selectEl(
    [['', '— kein Budget —'], ...budgets.map((b) => [String(b.id), `${b.zweck}${b.kostenstelle ? ' · ' + b.kostenstelle : ''} (${eur(Number(b.rest ?? (Number(b.betrag) - Number(b.ausgegeben))) || 0)} frei)`])],
    row.budget_id == null ? '' : String(row.budget_id)
  );
  const ksListe = [...new Set([...budgets.map((b) => b.kostenstelle), ...alle.map((r) => r.kostenstelle)].map((s) => String(s || '').trim()).filter(Boolean))].sort();
  const fKostenstelle = selectEl([['', '— keine —'], ...ksListe.map((k) => [k, k])], row.kostenstelle || '');
  const fRuecklage = selectEl(
    [['', '— keine —'], ...ruecklagen.map((r) => [String(r.id), `${r.bezeichnung} (${eur(Number(r.betrag) || 0)} / ${r.intervall_monate} Mon.)`])],
    row.ruecklage_id == null ? '' : String(row.ruecklage_id)
  );
  const fAuslage = selectEl(
    [['', '— keine —'], ...auslagen.map((a) => [String(a.id), `#${a.id} ${a.zweck || a.beschreibung || ''} (${eur(Number(a.betrag) || 0)})`])],
    row.auslage_id == null ? '' : String(row.auslage_id)
  );
  feld('Sphäre', fSphaere, FELD_HILFE.sphaere);
  if (cols.has('budget_id')) feld('Budget belasten', fBudget);
  if (cols.has('kostenstelle')) feld('Kostenstelle', fKostenstelle);
  feld('Für Rücklage', fRuecklage);
  feld('Auslage', fAuslage);
  if (!cols.has('budget_id')) {
    form.append(el('p', { class: 'note', style: 'grid-column:1/-1' },
      'Budget und Kostenstelle stehen erst nach dem Plugin-Update (ab v0.21.0) zur Verfügung.'));
  }

  /* --- 4. Beleg ----------------------------------------------------------- */
  sec('Beleg');
  const belegNrs = [...new Set(alle.map((r) => String(r.beleg_nr || r.beleg_referenz || '').trim()).filter(Boolean))].sort();
  const dlBeleg = el('datalist', { id: 'dl_beleg_nr' });
  for (const b of belegNrs) dlBeleg.append(el('option', { value: b }));
  form.append(dlBeleg);
  const fBelegNr = el('input', { type: 'text', value: row.beleg_nr || row.beleg_referenz || '', list: 'dl_beleg_nr' });
  const nextBelegNr = () => {
    const jahr = String(fDatum.value || row.buchung_datum || '').slice(0, 4) || String(new Date().getFullYear());
    let max = 0;
    for (const b of belegNrs) {
      const m = /^(\d{4})-(\d+)$/.exec(b);
      if (m && m[1] === jahr) max = Math.max(max, Number(m[2]));
    }
    return `${jahr}-${String(max + 1).padStart(4, '0')}`;
  };
  const belegNrZeile = el('div', { class: 'row' }, fBelegNr,
    el('button', { type: 'button', class: 'small ghost', onclick: () => { fBelegNr.value = nextBelegNr(); } }, 'nächste freie'));
  feld('Beleg-Nr', belegNrZeile);
  const belegNrInfo = el('p', { class: 'muted', style: 'grid-column:1/-1;margin:0' },
    belegNrs.length ? `${belegNrs.length} vergebene Nummern – Feld anklicken zeigt die Liste. Zuletzt: ${belegNrs.slice(-5).join(', ')}` : 'Noch keine Beleg-Nummern vergeben.');
  form.append(belegNrInfo);

  const fPfad = el('input', { type: 'text', value: row.beleg_pfad || '', placeholder: 'wird beim Hochladen automatisch gesetzt' });
  const fDatei = el('input', { type: 'file', accept: 'image/*,application/pdf' });
  const upBtn = el('button', { type: 'button', class: 'small' }, 'Hochladen');
  const pfadZeile = el('div', { class: 'row' }, fDatei, upBtn);
  if (row.beleg_pfad) {
    pfadZeile.append(el('button', { type: 'button', class: 'small ghost', onclick: () => openBeleg(row.beleg_pfad) }, '📎 öffnen'));
  }
  upBtn.addEventListener('click', async () => {
    const f = fDatei.files && fDatei.files[0];
    if (!f) return toast('Bitte zuerst eine Datei wählen.', true);
    if (!(api.nc && api.nc.belegUpload)) return toast('App bitte komplett neu starten (npm start), damit der Upload verfügbar ist.', true);
    upBtn.disabled = true;
    try {
      const res = await call(api.nc.belegUpload('', { name: f.name, buffer: new Uint8Array(await f.arrayBuffer()) }, {
        jahr: String(fDatum.value || '').slice(0, 4),
        ref: fBelegNr.value || `Buchung-${pk}`,
      }));
      fPfad.value = (res && res.path) || fPfad.value;
      toast('Beleg hochgeladen – bitte noch speichern.');
    } catch (e) {
      toast(e.message, true);
    } finally {
      upBtn.disabled = false;
    }
  });
  feld('Beleg-Datei (Nextcloud)', pfadZeile);
  feld('Beleg-Pfad', fPfad);

  /* --- Vorschau + Sphären-Automatik -------------------------------------- */
  function updateVorschau() {
    const z = satzZuZeile(fSoll.value, fHaben.value, parseNum(fBetrag.value), konten, dmap);
    if (!sphaereTouched && z.sphaere) fSphaere.value = z.sphaere;
    const art = z.gegenkonto ? 'Umbuchung' : Number(z.betrag) < 0 ? 'Ausgabe' : 'Einnahme';
    vorschau.textContent =
      `${kname(fSoll.value)} an ${kname(fHaben.value)} · ${eur(Math.abs(Number(z.betrag) || 0))}` +
      ` → wird gespeichert als ${art}: Betrag ${eur(z.betrag)}, Konto ${z.konto || '—'}, Quelle ${z.quelle}` +
      (z.gegenkonto ? `, Gegenkonto ${z.gegenkonto}` : '');
    const schief = !quelleStimmt(z, dmap);
    vorschau.className = schief ? 'note err' : 'muted';
    fixBtn.hidden = !schief;
    if (schief) {
      vorschau.textContent =
        `Konto ${kname(z.geldkonto)} ist keinem Geld-Topf zugeordnet. Gespeichert würde die Quelle „${z.quelle}" –`
        + ` die Buchung landete damit auf Konto ${dmap[z.quelle] || '?'} statt auf ${z.geldkonto}.`
        + ' Ordne das Konto einem Topf zu oder wähle hier ein bereits zugeordnetes Konto.';
    }
  }
  for (const n of [fSoll, fHaben, fBetrag]) n.addEventListener('change', updateVorschau);
  fBetrag.addEventListener('input', updateVorschau);
  updateVorschau();

  /* --- Aktionen ----------------------------------------------------------- */
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Speichern'));
  actions.append(el('button', { type: 'button', class: 'danger', onclick: async () => {
    if (!confirm('Diese Buchung löschen? Wird beim nächsten Sync auch auf dem Server gelöscht.')) return;
    try {
      await call(api.data.remove('jb_buchungen', pk));
      toast('Zum Löschen vorgemerkt.');
      await runSyncQuiet();
      back();
    } catch (e) { toast(e.message, true); }
  } }, 'Löschen'));
  if (data && data.state && data.state.dirty) actions.append(el('span', { class: 'tag' }, 'lokal geändert, noch nicht gesendet'));
  form.append(actions);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!fSoll.value || !fHaben.value) return toast('Soll- und Haben-Konto wählen.', true);
    if (fSoll.value === fHaben.value) return toast('Soll und Haben dürfen nicht dasselbe Konto sein.', true);
    const z = satzZuZeile(fSoll.value, fHaben.value, parseNum(fBetrag.value), konten, dmap);
    // Nur blockieren, wenn die Zuordnung wirklich bekannt ist – sonst würde eine
    // bloß angenommene Standardzuordnung das Speichern verhindern.
    if (mapEcht && !quelleStimmt(z, dmap)) {
      return toast(`Konto ${z.geldkonto} ist keinem Geld-Topf zugeordnet – die Buchung würde auf ${dmap[z.quelle] || '?'} landen. Unten „Geld-Töpfe → Konten" öffnen und zuordnen.`, true);
    }
    const fields = {
      buchung_datum: fDatum.value,
      betrag: String(z.betrag),
      konto: z.konto,
      gegenkonto: z.gegenkonto,
      quelle: z.quelle,
      sphaere: fSphaere.value,
      beschreibung: fText.value,
      gegenpartei: fGegenpartei.value,
      beleg_nr: fBelegNr.value,
      beleg_referenz: fBelegNr.value,
      beleg_pfad: fPfad.value,
      ruecklage_id: fRuecklage.value,
      auslage_id: fAuslage.value,
      // `kategorie` ist ein Altfeld – wird aus dem Konto mitgeführt, damit die
      // alten Kategorie-Auswertungen weiter stimmen (kein eigenes Eingabefeld).
      kategorie: kname(z.konto),
    };
    if (cols.has('budget_id')) fields.budget_id = fBudget.value;
    if (cols.has('kostenstelle')) fields.kostenstelle = fKostenstelle.value;
    // Nur Spalten senden, die es auf dem Server wirklich gibt.
    for (const k of Object.keys(fields)) if (!cols.has(k)) delete fields[k];
    try {
      await call(api.data.save('jb_buchungen', pk, fields));
      const vorher = (state.stats && state.stats.conflicts) || 0;
      await runSyncQuiet();
      const nachher = (state.stats && state.stats.conflicts) || 0;
      if (nachher > vorher) {
        toast('Nicht gespeichert: Die Buchung wurde auf dem Server zwischenzeitlich geändert. Bitte unter „Konflikte" entscheiden.', true);
      } else {
        toast('Gespeichert.');
      }
      showBuchung(pk, from);
    } catch (e) {
      toast(e.message, true);
    }
  });

  view.append(form);
  view.append(el('p', { class: 'muted' },
    `ID ${row.id ?? pk} · angelegt ${row.erstellt_am || '—'}${row.erstellt_von ? ' von User-ID ' + row.erstellt_von : ''}` +
    ' · Rohdaten (alle Spalten) unter Admin · Rohdaten → Buchungen.'));
}

function journalForm(konten, ruecklagen = [], budgets = []) {
  const card = el('div', { class: 'card' });
  const head = el('div', { class: 'row', style: 'justify-content:space-between' });
  head.append(el('h2', {}, 'Neue Buchung'));
  const modeBtns = el('div', { class: 'row' });
  head.append(modeBtns);
  card.append(head);
  const body = el('div', {});
  card.append(body);
  const setMode = (m) => {
    modeBtns.innerHTML = '';
    modeBtns.append(
      el('button', { type: 'button', class: 'small' + (m === 'betrag' ? ' primary' : ''), onclick: () => setMode('betrag') }, 'Betrag'),
      el('button', { type: 'button', class: 'small' + (m === 'sh' ? ' primary' : ''), onclick: () => setMode('sh') }, 'Soll-Haben')
    );
    body.innerHTML = '';
    body.append(buchungsHilfe(false));
    body.append(m === 'sh' ? sollHabenForm(konten) : euerForm());
  };

  function euerForm() {
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = moneyInput('', { placeholder: 'negativ = Ausgabe' });
  const konto = kontoSelect(konten, '', 'kategorie', {
    onchange: (e) => {
      const k = (konten || []).find((x) => String(x.nummer) === e.target.value);
      if (k && k.sphaere) sph.value = k.sphaere;
      if (k) betragHint(k);
    },
  });
  const sph = selectEl(SPHAERE_OPTS, '');
  const quelle = selectEl(QUELLE_OPTS, 'Bank KSK');
  const gegen = el('input', { type: 'text', placeholder: 'Gegenpartei (optional)' });
  const beschr = el('textarea', { placeholder: 'Beschreibung' });
  const rl = selectEl([['', '– keine –'], ...ruecklagen.map((r) => [String(r.id), r.bezeichnung])], '');
  const bud = selectEl(
    [['', '– kein Budget –'], ...budgets.map((b) => [String(b.id), `${b.zweck}${b.kostenstelle ? ' · ' + b.kostenstelle : ''}`])], '');
  const ksListe = [...new Set(budgets.map((b) => String(b.kostenstelle || '').trim()).filter(Boolean))].sort();
  const ks = selectEl([['', '– keine –'], ...ksListe.map((k) => [k, k])], '');
  bud.addEventListener('change', () => {
    const b = budgets.find((x) => String(x.id) === bud.value);
    if (b && b.kostenstelle) ks.value = b.kostenstelle;
  });
  const hint = el('div', { class: 'muted' });
  function betragHint(k) {
    hint.textContent = k.typ === 'einnahme' ? 'Einnahme → Betrag positiv.' : k.typ === 'ausgabe' ? 'Ausgabe → Betrag negativ.' : '';
  }
  const f = el('form', { class: 'detail' });
  f.append(
    'Datum', datum,
    'Betrag (€)', betrag,
    labelMitHilfe('Konto (SKR) – wofür?', FELD_HILFE.konto), konto,
    labelMitHilfe('Sphäre', FELD_HILFE.sphaere), sph,
    labelMitHilfe('Geldtopf (Quelle) – wo?', FELD_HILFE.quelle), quelle,
    labelMitHilfe('Gegenpartei – mit wem?', FELD_HILFE.gegenpartei), gegen,
    'Beschreibung', beschr
  );
  if (budgets.length) f.append('Budget belasten', bud, 'Kostenstelle', ks);
  if (ruecklagen.length) f.append('Für Rücklage', rl);
  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, 'Buchen'), hint);
  f.append(actions);
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const amt = parseNum(betrag.value);
    if (!amt) return toast('Betrag fehlt.', true);
    try {
      await call(api.action.run('journal-add', {
        buchung_datum: datum.value, betrag: amt, konto: konto.value, sphaere: sph.value,
        kategorie: (konten.find((k) => String(k.nummer) === konto.value) || {}).bezeichnung || 'Sonstige',
        quelle: quelle.value, gegenpartei: gegen.value, beschreibung: beschr.value,
        ruecklage_id: Number(rl.value) || 0,
        budget_id: Number(bud.value) || 0, kostenstelle: ks.value,
      }));
      toast('Gebucht.');
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  });
  return f;
  }

  setMode('betrag');
  return card;
}

function sollHabenForm(konten) {
  const f = el('form', { class: 'detail' });
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = moneyInput('', { placeholder: 'Betrag > 0' });
  const soll = kontoSelect(konten, '', 'alle');
  const haben = kontoSelect(konten, '', 'alle');
  const text = el('input', { type: 'text', placeholder: 'Buchungstext' });
  const beleg = el('input', { type: 'text', placeholder: 'Beleg-Nr (optional)' });
  f.append('Datum', datum, 'Betrag (€)', betrag, 'Soll (an Konto)', soll, 'Haben (von Konto)', haben, 'Text', text, 'Beleg-Nr', beleg);
  f.append(el('div', { class: 'form-actions' },
    el('button', { class: 'primary', type: 'submit' }, 'Buchen'),
    el('span', { class: 'muted' }, 'Geldkonto↔Erfolgskonto = Einnahme/Ausgabe · Geldkonto↔Geldkonto = Umbuchung')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const amt = parseNum(betrag.value);
    if (!amt || !soll.value || !haben.value || soll.value === haben.value) return toast('Betrag und zwei verschiedene Konten nötig.', true);
    try {
      await call(api.action.run('journal-add', {
        soll_konto: soll.value, haben_konto: haben.value, betrag: amt, datum: datum.value, text: text.value, beleg_nr: beleg.value,
      }));
      toast('Gebucht.');
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  });
  return f;
}

function umbuchungForm(konten) {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Umbuchung (zwei Zeilen, neutral)'));
  const datum = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = moneyInput('', { placeholder: 'Betrag > 0' });
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
    const amt = Math.abs(parseNum(betrag.value));
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

function splitForm(row, konten, ruecklagen = []) {
  const card = el('div', { class: 'card' });
  if (!row) {
    card.append(el('div', { class: 'note err' }, 'Buchung nicht gefunden – bitte neu synchronisieren.'));
    return card;
  }
  const ziel = Number(row.betrag) || 0;
  card.append(el('h2', {}, `Buchung #${row.id} aufteilen`));
  card.append(el('p', { class: 'muted' }, `${row.buchung_datum} · ${row.beschreibung || row.kategorie || ''} · Gesamt ${eur(ziel)}`));

  const parts = [];
  const host = el('div', {});
  const restEl = el('div', { class: 'muted', style: 'margin:6px 0' });

  function addPart(preset = {}) {
    const betrag = moneyInput(preset.betrag != null ? preset.betrag : '');
    const konto = kontoSelect(konten, preset.konto || row.konto || '', 'alle');
    const zweck = el('input', { type: 'text', value: preset.beschreibung || row.beschreibung || '' });
    const rl = ruecklagen.length ? selectEl([['', '– keine –'], ...ruecklagen.map((r) => [String(r.id), r.bezeichnung])], preset.ruecklage_id || '') : null;
    const p = { betrag, konto, zweck, rl };
    parts.push(p);
    const rowEl = el('div', { class: 'row', style: 'gap:8px;margin:4px 0;flex-wrap:wrap' },
      betrag, konto, zweck, rl,
      el('button', { type: 'button', class: 'small ghost', onclick: () => { parts.splice(parts.indexOf(p), 1); rowEl.remove(); recalc(); } }, '×'));
    host.append(rowEl);
    betrag.addEventListener('input', recalc);
  }
  function recalc() {
    const sum = parts.reduce((s, p) => s + (parseNum(p.betrag.value)), 0);
    const rest = +(ziel - sum).toFixed(2);
    restEl.textContent = `Summe der Teile: ${eur(sum)} · Rest: ${eur(rest)}` + (Math.abs(rest) < 0.005 ? ' ✓' : '');
    restEl.style.color = Math.abs(rest) < 0.005 ? 'var(--accent)' : 'var(--err-ink)';
  }

  // Vorbelegung: Teil 1 = Gesamt, Teil 2 = 0 (z. B. Bankgebühr abspalten)
  addPart({ betrag: ziel.toFixed(2) });
  addPart({ betrag: '0', beschreibung: 'Bankgebühr', konto: '5190' });
  recalc();

  card.append(host);
  card.append(el('button', { type: 'button', class: 'small', onclick: () => { addPart(); recalc(); } }, '+ Zeile'));
  card.append(restEl);
  const go = el('button', { class: 'primary', style: 'display:block;margin-top:8px' }, 'Aufteilen');
  go.addEventListener('click', async () => {
    const teile = parts
      .map((p) => ({
        betrag: parseNum(p.betrag.value),
        konto: p.konto.value,
        beschreibung: p.zweck.value,
        ruecklage_id: p.rl ? Number(p.rl.value) || 0 : 0,
      }))
      .filter((x) => x.betrag !== 0);
    if (teile.length < 2) return toast('Mindestens zwei Teile mit Betrag ≠ 0.', true);
    try {
      const r = await call(api.action.run('split-buchung', { id: row.id, teile }));
      toast(`Aufgeteilt in ${(r.created || []).length} Buchungen.`);
      jState.sel.clear();
      await runSyncQuiet();
      showJournal();
    } catch (e) {
      toast(e.message, true);
    }
  });
  card.append(go);
  return card;
}

function zuUmbuchungForm(ids, rows, konten) {
  const card = el('div', { class: 'card' });
  const sel = ids.map((id) => rows.find((r) => String(r.id) === String(id))).filter(Boolean);
  if (!sel.length) {
    card.append(el('div', { class: 'note err' }, 'Buchung(en) nicht gefunden – neu synchronisieren.'));
    return card;
  }
  card.append(el('h2', {}, 'Zu Umbuchung machen'));
  card.append(el('p', { class: 'muted' }, 'Umbuchungen sind neutral (Sphäre „neutral", Kategorie/Quelle „Umbuchung") und zählen nicht als Einnahme/Ausgabe.'));
  for (const r of sel) card.append(el('div', {}, `#${r.id} · ${r.buchung_datum} · ${eur(Number(r.betrag) || 0)} · ${r.konto || r.kategorie || ''}`));

  if (sel.length >= 2) {
    const summe = sel.reduce((s, r) => s + (Number(r.betrag) || 0), 0);
    const ok = Math.abs(summe) < 0.005;
    card.append(el('p', { class: ok ? 'muted' : 'note err' }, `Summe: ${eur(summe)}` + (ok ? ' ✓' : ' – muss 0 ergeben')));
    const go = el('button', { class: 'primary', disabled: !ok }, 'Beide als Umbuchung markieren');
    go.addEventListener('click', () => runZuUmb({ ids }));
    card.append(go);
    return card;
  }

  // Eine Buchung → neutral stellen + optional Gegenbuchung
  const src = sel[0];
  const gk = kontoSelect(konten, '', 'alle');
  const gq = selectEl(['Umbuchung', 'Bank KSK', 'Zettle-Bar', 'Bar', 'PayPal', 'Zettle-Karte'], 'Umbuchung');
  const mkGegen = el('input', { type: 'checkbox' });
  mkGegen.checked = true;
  const f = el('form', { class: 'detail' });
  f.append('Gegen-Konto', gk, 'Topf / Quelle der Gegenbuchung', gq, 'Gegenbuchung anlegen', el('label', {}, mkGegen, ` +${eur(-(Number(src.betrag) || 0))} auf das Gegen-Konto`));
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Umbuchung erstellen')));
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    if (mkGegen.checked && !gk.value) return toast('Gegen-Konto wählen.', true);
    runZuUmb({ ids, gegen_konto: gk.value, gegen_quelle: gq.value, gegenbuchung: mkGegen.checked });
  });
  card.append(f);
  return card;
}
async function runZuUmb(body) {
  try {
    const r = await call(api.action.run('zu-umbuchung', body));
    toast('Umbuchung gesetzt' + (r.created && r.created.length ? ' (+ Gegenbuchung).' : '.'));
    jState.sel.clear();
    await runSyncQuiet();
    showJournal();
  } catch (e) {
    toast(e.message, true);
  }
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
  const betrag = moneyInput('');
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
    const amt = parseNum(betrag.value);
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
  add('betrag', 'Festbetrag (€)', 'input', { type: 'text', inputmode: 'decimal' });
  add('preis_von', 'Preis von (€)', 'input', { type: 'text', inputmode: 'decimal' });
  add('preis_bis', 'Preis bis (€)', 'input', { type: 'text', inputmode: 'decimal' });
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

async function membersList() {
  if (state._members) return state._members;
  try {
    state._members = (await call(api.data.rows('wp_members', { limit: 3000 }))).rows;
  } catch {
    state._members = [];
  }
  return state._members;
}
function memberName(members, uid) {
  const m = members.find((x) => String(x.id) === String(uid));
  return m ? m.display_name || `${m.first_name} ${m.last_name}`.trim() || m.user_login : `User #${uid}`;
}
function userSelect(members, value) {
  return selectEl(
    [['', '— niemand —'], ...members.slice().sort((a, b) => String(a.display_name).localeCompare(String(b.display_name))).map((m) => [String(m.id), m.display_name || m.user_login])],
    String(value || '')
  );
}

function ppViewKreise({ host, gremien, kmit, rollen }) {
  const mBy = {};
  for (const m of kmit) (mBy[m.gremium_id] ||= []).push(m);
  const rBy = {};
  for (const r of rollen) (rBy[r.gremium_id] ||= []).push(r);
  if (can('pp_manage')) host.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => showGremium(null, gremien) }, '+ Neues Gremium')));
  const grid = el('div', { class: 'grid-cards' });
  for (const g of gremien) {
    const c = el('div', { class: 'card', onclick: () => showGremium(g, gremien) });
    c.append(el('strong', {}, g.name));
    c.append(el('div', { class: 'muted', style: 'font-size:12px' }, (g.typ || '') + (String(g.aktiv) === '0' ? ' · inaktiv' : '')));
    c.append(el('div', {}, `${(mBy[g.id] || []).length} Mitglied(er) · ${(rBy[g.id] || []).length} Rolle(n)`));
    grid.append(c);
  }
  host.append(grid);
  if (!gremien.length) host.append(el('div', { class: 'note' }, 'Keine Gremien.'));
}

async function showGremium(g, gremien) {
  g = g || {};
  state.current = { name: 'protokolle', sub: 'kreise' };
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showProtokolle({ sub: 'kreise' }) }, '‹ Zurück'));
  view.append(el('h1', {}, g.id ? g.name : 'Neues Gremium'));

  const members = await membersList();
  let kmit = [];
  let rollen = [];
  let vorlagen = [];
  if (g.id) {
    [kmit, rollen, vorlagen] = await Promise.all([
      call(api.data.rows('pp_kreis_mitglieder', { limit: 5000 })).then((r) => r.rows.filter((x) => String(x.gremium_id) === String(g.id))).catch(() => []),
      call(api.data.rows('pp_rollen', { limit: 5000 })).then((r) => r.rows.filter((x) => String(x.gremium_id) === String(g.id))).catch(() => []),
      call(api.data.rows('pp_rollenvorlagen', { limit: 2000 })).then((r) => r.rows.filter((x) => String(x.gremium_id) === String(g.id))).catch(() => []),
    ]);
  }

  // --- Gremium-Stammdaten ---
  const f = el('form', { class: 'detail' });
  const name = el('input', { value: g.name || '' });
  const typ = selectEl(['mv', 'vorstand', 'leitungskreis', 'kreis', 'kreisversammlung'], g.typ || 'kreis');
  const parent = selectEl([['', '—'], ...(gremien || []).filter((x) => String(x.id) !== String(g.id)).map((x) => [String(x.id), x.name])], String(g.parent_gremium_id || ''));
  const oeff = selectEl(['oeffentlich', 'vereinsintern', 'nur_gremium'], g.oeffentlichkeit || 'vereinsintern');
  const verf = selectEl(['konsent', 'mehrheit', 'geheime_wahl'], g.standardverfahren || 'konsent');
  const frist = el('input', { type: 'number', value: g.einladungsfrist_tage ?? 14 });
  const beschr = el('textarea', {});
  beschr.value = g.beschreibung || '';
  const aktiv = selectEl([['1', 'aktiv'], ['0', 'inaktiv']], String(g.aktiv ?? 1));
  f.append('Name', name, 'Typ', typ, 'Übergeordnet', parent, 'Öffentlichkeit', oeff, 'Verfahren', verf, 'Einladungsfrist (Tage)', frist, 'Beschreibung', beschr, 'Status', aktiv);
  f.append(el('div', { class: 'form-actions' },
    el('button', { class: 'primary', type: 'submit' }, 'Speichern'),
    g.id ? el('button', { type: 'button', class: 'danger', onclick: () => gremiumDelete(g.id) }, 'Gremium löschen') : null));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      const r = await call(api.action.run('gremium-save', {
        id: g.id || 0, name: name.value, typ: typ.value, parent_gremium_id: parent.value, oeffentlichkeit: oeff.value,
        standardverfahren: verf.value, einladungsfrist_tage: frist.value, beschreibung: beschr.value, aktiv: Number(aktiv.value),
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      const fresh = (await call(api.data.rows('pp_gremien', { limit: 500 }))).rows.find((x) => String(x.id) === String(r.id));
      showGremium(fresh || { ...g, id: r.id }, gremien);
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(el('div', { class: 'card' }, f));
  if (!g.id) return;

  // --- Mitglieder ---
  view.append(el('h2', {}, `Mitglieder (${kmit.length})`));
  const ml = el('div', { class: 'card' });
  for (const m of kmit) {
    ml.append(el('div', { class: 'row', style: 'justify-content:space-between;border-bottom:1px solid var(--line);padding:5px 0' },
      el('div', {}, memberName(members, m.user_id), m.beigetreten_am ? el('span', { class: 'muted' }, ' · seit ' + m.beigetreten_am) : null),
      el('button', { class: 'small danger', onclick: () => kreisMitglied({ op: 'remove', id: m.id }, g, gremien) }, 'Entfernen')));
  }
  const addSel = userSelect(members, '');
  ml.append(el('div', { class: 'row', style: 'margin-top:8px' }, addSel,
    el('button', { class: 'small primary', onclick: () => addSel.value && kreisMitglied({ op: 'add', gremium_id: g.id, user_id: addSel.value }, g, gremien) }, '+ Hinzufügen')));
  view.append(ml);

  // --- Rollen ---
  view.append(el('h2', {}, `Rollen (${rollen.length})`));
  if (can('pp_manage')) view.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => rolleEdit(null, g, members, vorlagen) }, '+ Rolle')));
  for (const r of rollen) {
    view.append(el('div', { class: 'card row', style: 'justify-content:space-between' },
      el('div', {}, el('strong', {}, r.bezeichnung), el('div', { class: 'muted', style: 'font-size:12px' },
        (r.user_id ? memberName(members, r.user_id) : 'unbesetzt') + (String(r.vertretungsberechtigt) === '1' ? ' · vertretungsberechtigt' : '') +
        (r.amtszeit_ende ? ` · bis ${r.amtszeit_ende}` : ''))),
      el('div', { class: 'row' },
        el('button', { class: 'small', onclick: () => rolleEdit(r, g, members, vorlagen) }, 'Bearbeiten'),
        el('button', { class: 'small danger', onclick: () => rolleDelete(r.id, g, gremien) }, 'Löschen'))));
  }
}

async function gremiumDelete(id) {
  if (!confirm('Gremium mit allen Mitgliedern und Rollen löschen?')) return;
  try {
    await call(api.action.run('gremium-delete', { id }));
    toast('Gelöscht.');
    await runSyncQuiet();
    showProtokolle({ sub: 'kreise' });
  } catch (e) {
    toast(e.message, true);
  }
}
async function kreisMitglied(body, g, gremien) {
  try {
    await call(api.action.run('kreis-mitglied', body));
    await runSyncQuiet();
    state._members = null;
    showGremium(g, gremien);
  } catch (e) {
    toast(e.message, true);
  }
}
async function rolleDelete(id, g, gremien) {
  if (!confirm('Rolle löschen?')) return;
  try {
    await call(api.action.run('rolle-delete', { id }));
    await runSyncQuiet();
    showGremium(g, gremien);
  } catch (e) {
    toast(e.message, true);
  }
}
function rolleEdit(r, g, members, vorlagen) {
  r = r || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: () => showGremium(g, null) }, '‹ Zurück'));
  view.append(el('h1', {}, r.id ? 'Rolle bearbeiten' : 'Neue Rolle'));
  const bez = el('input', { value: r.bezeichnung || '' });
  const person = userSelect(members, r.user_id);
  const vorlage = selectEl([['', '—'], ...(vorlagen || []).map((v) => [String(v.id), v.bezeichnung])], String(r.rollenvorlage_id || ''));
  const vertret = selectEl([['0', 'nein'], ['1', 'ja']], String(r.vertretungsberechtigt || 0));
  const start = el('input', { type: 'date', value: (r.amtszeit_start || '').slice(0, 10) });
  const ende = el('input', { type: 'date', value: (r.amtszeit_ende || '').slice(0, 10) });
  const wg = el('input', { value: r.wahl_gruppe || '' });
  const f = el('form', { class: 'detail' }, 'Bezeichnung', bez, 'Person', person, 'Rollenvorlage', vorlage, 'Vertretungsberechtigt', vertret, 'Amtszeit von', start, 'Amtszeit bis', ende, 'Wahlgruppe', wg);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('rolle-save', {
        id: r.id || 0, gremium_id: g.id, bezeichnung: bez.value, user_id: person.value, rollenvorlage_id: vorlage.value,
        vertretungsberechtigt: Number(vertret.value), amtszeit_start: start.value, amtszeit_ende: ende.value, wahl_gruppe: wg.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showGremium(g, null);
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(f);
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
  let tausch = [];
  try {
    [ev, st, sc, ei, tausch] = await Promise.all([
      call(api.data.rows('wl_shift_events', { limit: 500 })).then((r) => r.rows),
      call(api.data.rows('wl_shift_stationen', { limit: 2000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_schichten', { limit: 5000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_eintragungen', { limit: 20000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_tausch', { limit: 5000 })).then((r) => r.rows).catch(() => []),
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
  const myEmail = (state.me && (state.me.email || '')).toLowerCase();
  const myEntryIds = new Set(ei.filter((x) => String(x.user_id) === myId).map((x) => String(x.id)));
  const schichtInfo = (eid) => {
    const en = ei.find((x) => String(x.id) === String(eid));
    if (!en) return `Eintrag #${eid}`;
    const s = sc.find((x) => String(x.id) === String(en.schicht_id)) || {};
    return `${(s.start_zeit || s.titel || 'Schicht').slice(0, 16)} (${en.name})`;
  };

  // --- Tauschanfragen ---
  const incoming = tausch.filter((t) => (t.an_email || '').toLowerCase() === myEmail && t.status === 'offen');
  const outgoing = tausch.filter((t) => myEntryIds.has(String(t.von_eintrag_id)));
  if (incoming.length || outgoing.length) {
    view.append(el('h2', {}, 'Schichttausch'));
    for (const t of incoming) {
      view.append(el('div', { class: 'card row', style: 'justify-content:space-between' },
        el('div', {}, `Anfrage: ${schichtInfo(t.von_eintrag_id)}`),
        el('div', { class: 'row' },
          el('button', { class: 'small primary', onclick: () => tauschEntscheiden(t.id, true) }, 'Annehmen'),
          el('button', { class: 'small danger', onclick: () => tauschEntscheiden(t.id, false) }, 'Ablehnen'))));
    }
    for (const t of outgoing) {
      view.append(el('div', { class: 'card row', style: 'justify-content:space-between' },
        el('div', {}, `Meine Anfrage an ${t.an_email}: ${schichtInfo(t.von_eintrag_id)} `,
          el('span', { class: 'st st-' + (t.status === 'angenommen' ? 'ausgezahlt' : t.status === 'offen' ? 'ausstehend' : 'abgelehnt') }, t.status)),
        t.status === 'offen' ? el('button', { class: 'small ghost', onclick: () => tauschZurueck(t.id) }, 'Zurückziehen') : null));
    }
  }

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
        if (mine) {
          cell.append(el('div', { class: 'row' },
            el('button', { class: 'small ghost', onclick: () => schichtAus(mine.id) }, 'Austragen'),
            el('button', { class: 'small', onclick: () => tauschAnfrage(mine.id) }, 'Tauschen')));
        } else if (!full) {
          cell.append(el('button', { class: 'small primary', onclick: () => schichtEin(schicht.id) }, 'Eintragen'));
        }
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
  if (!schicht_id) return toast('Schicht-ID fehlt (bitte einmal synchronisieren).', true);
  if (!(api.action && api.action.run)) return toast('App bitte komplett neu starten (npm start), nicht nur neu laden.', true);
  try {
    const r = await call(api.action.run('schicht-eintragen', { schicht_id }));
    toast(r && r.note ? r.note : 'Eingetragen.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    console.error('schicht-eintragen', e);
    toast(e.message || 'Eintragen fehlgeschlagen.', true);
  }
}
async function schichtAus(eintrag_id) {
  if (!eintrag_id) return toast('Eintrag-ID fehlt.', true);
  if (!(api.action && api.action.run)) return toast('App bitte komplett neu starten (npm start).', true);
  try {
    await call(api.action.run('schicht-austragen', { eintrag_id }));
    toast('Ausgetragen.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    console.error('schicht-austragen', e);
    toast(e.message || 'Austragen fehlgeschlagen.', true);
  }
}
async function tauschAnfrage(von_eintrag_id) {
  const an_email = prompt('E-Mail der Person, die die Schicht übernehmen soll:', '');
  if (!an_email) return;
  try {
    await call(api.action.run('shift-tausch', { op: 'anfrage', von_eintrag_id, an_email }));
    toast('Tauschanfrage verschickt.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    toast(e.message, true);
  }
}
async function tauschEntscheiden(id, annehmen) {
  try {
    await call(api.action.run('shift-tausch', { op: 'entscheiden', id, annehmen }));
    toast(annehmen ? 'Schicht übernommen.' : 'Abgelehnt.');
    await runSyncQuiet();
    showSchichtplaene();
  } catch (e) {
    toast(e.message, true);
  }
}
async function tauschZurueck(id) {
  try {
    await call(api.action.run('shift-tausch', { op: 'zuruecknehmen', id }));
    toast('Zurückgezogen.');
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
  view.append(el('div', { class: 'toolbar' }, el('button', { class: 'primary small', onclick: () => schichtEventEdit(null) }, '+ Veranstaltung')));

  let ev = [];
  let st = [];
  let sc = [];
  let ei = [];
  try {
    [ev, st, sc, ei] = await Promise.all([
      call(api.data.rows('wl_shift_events', { limit: 500 })).then((r) => r.rows),
      call(api.data.rows('wl_shift_stationen', { limit: 3000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_schichten', { limit: 8000 })).then((r) => r.rows).catch(() => []),
      call(api.data.rows('wl_shift_eintragungen', { limit: 20000 })).then((r) => r.rows).catch(() => []),
    ]);
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const stBy = {};
  st.forEach((s) => (stBy[s.event_id] ||= []).push(s));
  const scBy = {};
  sc.forEach((s) => (scBy[s.station_id] ||= []).push(s));
  const eiN = {};
  ei.forEach((e) => (eiN[e.schicht_id] = (eiN[e.schicht_id] || 0) + 1));

  for (const e of ev) {
    const d = el('details', { class: 'card' });
    d.append(el('summary', {}, `${e.titel}${e.veranstaltungsdatum ? ' · ' + e.veranstaltungsdatum : ''}${String(e.aktiv) === '0' ? ' · inaktiv' : ''}`));
    d.append(el('div', { class: 'row', style: 'margin:6px 0' },
      el('button', { class: 'small', onclick: () => schichtEventEdit(e) }, 'Veranstaltung bearbeiten'),
      el('button', { class: 'small primary', onclick: () => schichtStationEdit(null, e.id) }, '+ Station'),
      el('button', { class: 'small danger', onclick: () => schichtDel('shift-event-delete', e.id, 'Veranstaltung inkl. Stationen/Schichten löschen?') }, 'Löschen')));
    for (const station of (stBy[e.id] || []).sort((a, b) => Number(a.sortierung) - Number(b.sortierung))) {
      const sd = el('div', { style: 'border-top:1px solid var(--line);padding:8px 0' });
      sd.append(el('div', { class: 'row', style: 'justify-content:space-between' },
        el('strong', {}, station.titel + (station.treffpunkt ? ` · 📍 ${station.treffpunkt}` : '')),
        el('div', { class: 'row' },
          el('button', { class: 'small', onclick: () => schichtStationEdit(station, e.id) }, 'Station'),
          el('button', { class: 'small primary', onclick: () => schichtSchichtEdit(null, station.id) }, '+ Schicht'),
          el('button', { class: 'small danger', onclick: () => schichtDel('shift-station-delete', station.id, 'Station inkl. Schichten löschen?') }, '×'))));
      for (const schicht of (scBy[station.id] || []).sort((a, b) => String(a.start_zeit || '').localeCompare(String(b.start_zeit || '')))) {
        const zeit = schicht.start_zeit ? `${schicht.start_zeit.slice(0, 16)}–${(schicht.end_zeit || '').slice(11, 16)}` : (schicht.titel || 'Schicht');
        sd.append(el('div', { class: 'row', style: 'justify-content:space-between;padding:3px 0 3px 16px' },
          el('div', {}, `${zeit} · ${eiN[schicht.id] || 0}/${schicht.max_plaetze || 1}`),
          el('div', { class: 'row' },
            el('button', { class: 'small', onclick: () => schichtSchichtEdit(schicht, station.id) }, 'bearb.'),
            el('button', { class: 'small danger', onclick: () => schichtDel('shift-schicht-delete', schicht.id, 'Schicht löschen?') }, '×'))));
      }
      d.append(sd);
    }
    view.append(d);
  }
  if (!ev.length) view.append(el('div', { class: 'note' }, 'Noch keine Veranstaltungen.'));
}

async function schichtDel(action, id, frage) {
  if (!confirm(frage)) return;
  try {
    await call(api.action.run(action, { id }));
    toast('Gelöscht.');
    await runSyncQuiet();
    showSchichtverwaltung();
  } catch (e) {
    toast(e.message, true);
  }
}
function schichtEventEdit(e) {
  e = e || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: showSchichtverwaltung }, '‹ Zurück'));
  view.append(el('h1', {}, e.id ? 'Veranstaltung bearbeiten' : 'Neue Veranstaltung'));
  const titel = el('input', { value: e.titel || '' });
  const datum = el('input', { type: 'date', value: (e.veranstaltungsdatum || '').slice(0, 10) });
  const beschr = el('textarea', {});
  beschr.value = e.beschreibung || '';
  const grenze = el('input', { type: 'number', min: '0', max: '23', value: e.tagesgrenze_stunde ?? 0 });
  const aktiv = selectEl([['1', 'aktiv'], ['0', 'inaktiv']], String(e.aktiv ?? 1));
  const f = el('form', { class: 'detail' }, 'Titel', titel, 'Datum', datum, 'Beschreibung', beschr, 'Tagesgrenze (Stunde)', grenze, 'Status', aktiv);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('shift-event-save', {
        id: e.id || 0, titel: titel.value, veranstaltungsdatum: datum.value, beschreibung: beschr.value,
        tagesgrenze_stunde: grenze.value, aktiv: Number(aktiv.value),
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showSchichtverwaltung();
    } catch (err) {
      toast(err.message, true);
    }
  });
  view.append(f);
}
function schichtStationEdit(s, eventId) {
  s = s || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: showSchichtverwaltung }, '‹ Zurück'));
  view.append(el('h1', {}, s.id ? 'Station bearbeiten' : 'Neue Station'));
  const titel = el('input', { value: s.titel || '' });
  const beschr = el('textarea', {});
  beschr.value = s.beschreibung || '';
  const treff = el('input', { value: s.treffpunkt || '' });
  const ap1 = el('input', { value: s.ansprechperson1 || '' });
  const ap1k = el('input', { value: s.ansprechperson1_kontakt || '' });
  const ap2 = el('input', { value: s.ansprechperson2 || '' });
  const ap2k = el('input', { value: s.ansprechperson2_kontakt || '' });
  const sort = el('input', { type: 'number', value: s.sortierung || 0 });
  const f = el('form', { class: 'detail' }, 'Titel', titel, 'Beschreibung', beschr, 'Treffpunkt', treff,
    'Ansprechperson 1', ap1, 'Kontakt 1', ap1k, 'Ansprechperson 2', ap2, 'Kontakt 2', ap2k, 'Sortierung', sort);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('shift-station-save', {
        id: s.id || 0, event_id: eventId, titel: titel.value, beschreibung: beschr.value, treffpunkt: treff.value,
        ansprechperson1: ap1.value, ansprechperson1_kontakt: ap1k.value, ansprechperson2: ap2.value, ansprechperson2_kontakt: ap2k.value,
        sortierung: sort.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showSchichtverwaltung();
    } catch (err) {
      toast(err.message, true);
    }
  });
  view.append(f);
}
function schichtSchichtEdit(sc, stationId) {
  sc = sc || {};
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: showSchichtverwaltung }, '‹ Zurück'));
  view.append(el('h1', {}, sc.id ? 'Schicht bearbeiten' : 'Neue Schicht'));
  const titel = el('input', { value: sc.titel || '' });
  const start = el('input', { type: 'datetime-local', value: (sc.start_zeit || '').replace(' ', 'T').slice(0, 16) });
  const ende = el('input', { type: 'datetime-local', value: (sc.end_zeit || '').replace(' ', 'T').slice(0, 16) });
  const minp = el('input', { type: 'number', min: '0', value: sc.min_plaetze ?? 0 });
  const maxp = el('input', { type: 'number', min: '1', value: sc.max_plaetze ?? 1 });
  const sort = el('input', { type: 'number', value: sc.sortierung || 0 });
  const f = el('form', { class: 'detail' }, 'Titel (optional)', titel, 'Beginn', start, 'Ende', ende, 'Mindestplätze', minp, 'Maximalplätze', maxp, 'Sortierung', sort);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Speichern')));
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await call(api.action.run('shift-schicht-save', {
        id: sc.id || 0, station_id: stationId, titel: titel.value,
        start_zeit: start.value ? start.value.replace('T', ' ') + ':00' : '',
        end_zeit: ende.value ? ende.value.replace('T', ' ') + ':00' : '',
        min_plaetze: minp.value, max_plaetze: maxp.value, sortierung: sort.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showSchichtverwaltung();
    } catch (err) {
      toast(err.message, true);
    }
  });
  view.append(f);
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

/* =====================================================================
 * Rechnungen
 * ================================================================== */

const RE_STATUS = { entwurf: 'Entwurf', offen: 'offen', bezahlt: 'bezahlt', storniert: 'storniert' };
const RE_ZAHLART = [['ueberweisung', 'Überweisung'], ['lastschrift', 'SEPA-Lastschrift'], ['bar', 'bar']];

async function showRechnungen() {
  state.current = { name: 'rechnungen' };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Rechnungen'));

  let rows = [];
  try {
    rows = (await call(api.data.rows('vp_rechnungen', { limit: 2000 }))).rows;
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  rows.sort((a, b) => String(b.datum || '').localeCompare(String(a.datum || '')) || Number(b.id) - Number(a.id));

  const offen = rows.filter((r) => r.status === 'offen').reduce((s, r) => s + Number(r.summe || 0), 0);
  view.append(el('p', { class: 'sub' }, `${rows.length} Rechnungen · offen: ${eur(offen)}`));

  const neu = el('button', { class: 'primary' }, 'Neue Rechnung');
  neu.addEventListener('click', () => rechnungEdit(null));
  view.append(el('p', {}, neu));

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Nummer'), el('th', {}, 'Datum'), el('th', {}, 'Empfänger:in'),
    el('th', {}, 'Betreff'), el('th', { style: 'text-align:right' }, 'Summe'),
    el('th', {}, 'Zahlart'), el('th', {}, 'Status'))));
  const tb = el('tbody');
  for (const r of rows) {
    const tr = el('tr', { style: 'cursor:pointer' },
      el('td', {}, r.nummer || '(Entwurf)'),
      el('td', {}, r.datum || ''),
      el('td', {}, r.empfaenger_name || ''),
      el('td', {}, r.betreff || ''),
      el('td', { style: 'text-align:right' }, eur(r.summe)),
      el('td', {}, (RE_ZAHLART.find((z) => z[0] === r.zahlart) || ['', r.zahlart])[1]),
      el('td', {}, el('span', { class: `st st-${r.status}` }, RE_STATUS[r.status] || r.status)));
    tr.addEventListener('click', () => rechnungEdit(r));
    tb.append(tr);
  }
  if (!rows.length) tb.append(el('tr', {}, el('td', { colspan: '7' }, 'Noch keine Rechnungen.')));
  t.append(tb);
  view.append(t);
}

async function rechnungEdit(r) {
  state.current = { name: 'rechnungen' };
  view.innerHTML = '';
  const back = el('button', {}, '← Zurück');
  back.addEventListener('click', showRechnungen);
  view.append(el('p', {}, back));
  view.append(el('h1', {}, r ? `Rechnung ${r.nummer || '(Entwurf)'}` : 'Neue Rechnung'));

  const [konten, members, mandate, posAll] = await Promise.all([
    call(api.data.rows('jb_konten', { limit: 2000 })).then((x) => x.rows).catch(() => []),
    membersList(),
    call(api.data.rows('vp_sepa_mandate', { limit: 2000 })).then((x) => x.rows).catch(() => []),
    call(api.data.rows('vp_rechnung_positionen', { limit: 5000 })).then((x) => x.rows).catch(() => []),
  ]);
  const ertrag = konten.filter((k) => k.typ === 'einnahme')
    .sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }))
    .map((k) => [String(k.nummer), `${k.nummer} – ${k.bezeichnung}`]);
  const pos = r ? posAll.filter((p) => String(p.rechnung_id) === String(r.id)).sort((a, b) => Number(a.pos) - Number(b.pos)) : [];
  const locked = !!r && ['bezahlt', 'storniert'].includes(r.status);

  const f = el('form', { class: 'detail' });
  const user = userSelect(members, r ? r.user_id : '');
  const name = el('input', { value: r ? r.empfaenger_name || '' : '' });
  const email = el('input', { type: 'email', value: r ? r.empfaenger_email || '' : '' });
  const anschrift = el('textarea', { rows: '3' });
  anschrift.value = r ? r.empfaenger_anschrift || '' : '';
  const datum = el('input', { type: 'date', value: (r && r.datum) || new Date().toISOString().slice(0, 10) });
  const faellig = el('input', { type: 'date', value: (r && r.faellig_am) || '' });
  const zahlart = selectEl(RE_ZAHLART, r ? r.zahlart : 'ueberweisung');
  const mandat = selectEl(
    [['', '— automatisch —'], ...mandate.filter((m) => m.status === 'aktiv').map((m) => [String(m.id), `${m.kontoinhaber} (${m.mandatsref})`])],
    r ? r.mandat_id : ''
  );
  const konto = selectEl([['', '— später —'], ...ertrag], r ? r.konto : '');
  const kst = el('input', { value: r ? r.kostenstelle || '' : '' });
  const betreff = el('input', { value: r ? r.betreff || '' : 'Rechnung' });
  const einleitung = el('textarea', { rows: '2' });
  einleitung.value = r ? r.einleitung || '' : '';
  const schluss = el('textarea', { rows: '2' });
  schluss.value = r ? r.schluss || '' : '';
  const ust = el('input', { type: 'checkbox' });
  ust.checked = !!(r && Number(r.ust_ausweisen));

  user.addEventListener('change', () => {
    const m = members.find((x) => String(x.id) === user.value);
    if (!m) return;
    if (!name.value) name.value = m.display_name || m.user_login || '';
    if (!email.value) email.value = m.user_email || '';
    if (!anschrift.value.trim()) {
      anschrift.value = [m.vp_strasse, [m.vp_plz, m.vp_ort].filter(Boolean).join(' ')].filter(Boolean).join('\n');
    }
  });

  f.append(
    'Mitglied', user, 'Empfänger:in', name, 'E-Mail', email, 'Anschrift', anschrift,
    'Datum', datum, 'Fällig am', faellig, 'Zahlart', zahlart, 'SEPA-Mandat', mandat,
    'Ertragskonto', konto, 'Kostenstelle', kst, 'Betreff', betreff,
    'Einleitung', einleitung, 'Schlusstext', schluss, 'Umsatzsteuer ausweisen', ust
  );
  view.append(el('div', { class: 'card' }, f));

  /* ---- Positionen ---- */
  view.append(el('h2', {}, 'Positionen'));
  const ptab = el('table');
  ptab.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Bezeichnung'), el('th', {}, 'Menge'), el('th', {}, 'Einheit'),
    el('th', {}, 'Einzelpreis'), el('th', {}, 'USt %'), el('th', {}, 'Konto'), el('th', {}, ''))));
  const ptb = el('tbody');
  ptab.append(ptb);
  const zeilen = [];

  function addZeile(p) {
    const bez = el('input', { value: (p && p.bezeichnung) || '', style: 'width:100%' });
    const menge = el('input', { value: p ? fmtNum(p.menge).replace(/,00$/, '') : '1', size: '4' });
    const einheit = el('input', { value: (p && p.einheit) || '', size: '6' });
    const preis = moneyInput(p ? p.einzelpreis : '', { size: '8' });
    const ustS = el('input', { value: p ? String(Number(p.ust_satz) || 0) : '0', size: '3' });
    const kt = selectEl([['', '—'], ...ertrag], (p && p.konto) || '');
    const del = el('button', { type: 'button' }, '✕');
    const tr = el('tr', {}, el('td', {}, bez), el('td', {}, menge), el('td', {}, einheit),
      el('td', {}, preis), el('td', {}, ustS), el('td', {}, kt), el('td', {}, del));
    const entry = { bez, menge, einheit, preis, ustS, kt, tr };
    del.addEventListener('click', () => { tr.remove(); zeilen.splice(zeilen.indexOf(entry), 1); drawSumme(); });
    [bez, menge, preis, ustS].forEach((n) => n.addEventListener('input', drawSumme));
    zeilen.push(entry);
    ptb.append(tr);
  }
  pos.forEach(addZeile);
  if (!pos.length) addZeile(null);
  view.append(ptab);

  const plus = el('button', { type: 'button' }, '+ Position');
  plus.addEventListener('click', () => { addZeile(null); drawSumme(); });
  const summeEl = el('strong', {}, '');
  view.append(el('p', {}, plus, ' ', summeEl));

  function sammelPositionen() {
    return zeilen
      .filter((z) => z.bez.value.trim() || parseNum(z.preis.value))
      .map((z) => ({
        bezeichnung: z.bez.value,
        menge: parseNum(z.menge.value) || 1,
        einheit: z.einheit.value,
        einzelpreis: parseNum(z.preis.value),
        ust_satz: parseNum(z.ustS.value),
        konto: z.kt.value,
      }));
  }
  function drawSumme() {
    const p = sammelPositionen();
    const netto = p.reduce((s, x) => s + x.menge * x.einzelpreis, 0);
    const steuer = ust.checked ? p.reduce((s, x) => s + (x.menge * x.einzelpreis * x.ust_satz) / 100, 0) : 0;
    summeEl.textContent = ust.checked
      ? `Netto ${eur(netto)} + USt ${eur(steuer)} = ${eur(netto + steuer)}`
      : `Summe ${eur(netto)}`;
  }
  ust.addEventListener('change', drawSumme);
  drawSumme();

  /* ---- Aktionen ---- */
  const bar = el('p', { style: 'margin-top:14px' });
  const btn = (label, cls, fn) => {
    const b = el('button', cls ? { class: cls } : {}, label);
    b.addEventListener('click', fn);
    bar.append(b, ' ');
    return b;
  };

  async function speichern() {
    try {
      const res = await call(api.action.run('rechnung-save', {
        rechnung: {
          id: r ? r.id : 0,
          user_id: user.value, mandat_id: mandat.value,
          empfaenger_name: name.value, empfaenger_email: email.value, empfaenger_anschrift: anschrift.value,
          datum: datum.value, faellig_am: faellig.value, zahlart: zahlart.value,
          konto: konto.value, kostenstelle: kst.value, betreff: betreff.value,
          einleitung: einleitung.value, schluss: schluss.value, ust_ausweisen: ust.checked ? 1 : 0,
        },
        positionen: sammelPositionen(),
      }));
      toast('Rechnung gespeichert.');
      await runSyncQuiet();
      rechnungEdit(res.rechnung);
    } catch (e) {
      toast(e.message, true);
    }
  }
  async function status(aktion, extra) {
    try {
      const res = await call(api.action.run('rechnung-status', { id: r.id, aktion, ...(extra || {}) }));
      if (aktion === 'html') {
        await call(api.app.openHtml(`Rechnung ${r.nummer || r.id}`, res.html, res.css));
        return;
      }
      toast('Erledigt.');
      await runSyncQuiet();
      if (aktion === 'loeschen') showRechnungen();
      else rechnungEdit(res.rechnung || r);
    } catch (e) {
      toast(e.message, true);
    }
  }

  if (!locked) btn('Speichern', 'primary', speichern);
  if (r && r.status === 'entwurf') {
    btn('Festschreiben', '', () => status('festschreiben'));
    btn('Löschen', '', () => confirm('Entwurf löschen?') && status('loeschen'));
  }
  if (r) btn('Drucken / PDF', '', () => status('html'));
  if (r && r.status === 'offen') {
    btn('Per E-Mail senden', '', () => status('mail'));
    btn('Stornieren', '', () => confirm('Rechnung stornieren?') && status('storno'));
  }
  view.append(bar);

  if (r && ['offen', 'entwurf'].includes(r.status)) {
    const zdat = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
    const topf = el('input', { value: 'Bank KSK' });
    const zb = el('button', {}, 'Als bezahlt buchen');
    zb.addEventListener('click', () => status('bezahlt', { datum: zdat.value, quelle: topf.value }));
    view.append(el('div', { class: 'card' },
      el('h3', {}, 'Zahlungseingang buchen'),
      el('form', { class: 'detail' }, 'Bezahlt am', zdat, 'Geld-Topf', topf),
      el('p', {}, zb)));
  }
  if (r && r.bezahlt_am) {
    view.append(el('p', { class: 'muted' }, `Bezahlt am ${r.bezahlt_am} · Buchung #${r.buchung_id || '—'}`));
  }
}

/* =====================================================================
 * SEPA-Lastschrift
 * ================================================================== */

const sepaState = { sub: 'mandate', lauf: 0 };

async function showSepa(opts = {}) {
  if (opts.sub) sepaState.sub = opts.sub;
  if (opts.lauf !== undefined) sepaState.lauf = opts.lauf;
  state.current = { name: 'sepa', sub: sepaState.sub, lauf: sepaState.lauf };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'SEPA-Lastschrift'));

  const nav = el('nav', { class: 'subnav' });
  for (const [k, label] of [['mandate', 'Mandate'], ['laeufe', 'Einzugsläufe'], ['neu', 'Neuer Lauf']]) {
    nav.append(el('button', {
      class: 'chip' + (sepaState.sub === k ? ' is-active' : ''),
      onclick: () => showSepa({ sub: k, lauf: 0 }),
    }, label));
  }
  view.append(nav);

  if (sepaState.sub === 'laeufe' && sepaState.lauf) return sepaLauf(sepaState.lauf);
  if (sepaState.sub === 'laeufe') return sepaLaeufe();
  if (sepaState.sub === 'neu') return sepaNeuerLauf();
  return sepaMandate();
}

async function sepaMandate() {
  const [rows, members] = await Promise.all([
    call(api.data.rows('vp_sepa_mandate', { limit: 3000 })).then((x) => x.rows).catch(() => []),
    membersList(),
  ]);
  rows.sort((a, b) => String(a.kontoinhaber || '').localeCompare(String(b.kontoinhaber || '')));

  const imp = el('button', {}, 'Aus Mitgliederprofilen übernehmen');
  imp.addEventListener('click', async () => {
    try {
      const r = await call(api.action.run('sepa-mandat-save', { import: true }));
      toast(`${r.angelegt} Mandate angelegt, ${r.uebersprungen} vorhanden.`);
      await runSyncQuiet();
      showSepa({ sub: 'mandate' });
    } catch (e) {
      toast(e.message, true);
    }
  });
  const neu = el('button', { class: 'primary' }, 'Mandat anlegen');
  neu.addEventListener('click', () => sepaMandatEdit(null, members));
  view.append(el('p', {}, neu, ' ', imp));

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Referenz'), el('th', {}, 'Kontoinhaber:in'), el('th', {}, 'IBAN'),
    el('th', {}, 'Unterschrift'), el('th', {}, 'Seq.'), el('th', {}, 'Letzter Einzug'), el('th', {}, 'Status'))));
  const tb = el('tbody');
  for (const m of rows) {
    const ref = m.letzte_nutzung || m.unterschrift_datum;
    const alt = ref && new Date(ref).getTime() < Date.now() - 36 * 30.44 * 864e5;
    const tr = el('tr', { style: 'cursor:pointer' },
      el('td', {}, m.mandatsref || ''),
      el('td', {}, m.kontoinhaber || ''),
      el('td', {}, maskIban(m.iban)),
      el('td', {}, m.unterschrift_datum || '—'),
      el('td', {}, m.sequenz || ''),
      el('td', {}, m.letzte_nutzung || '—'),
      el('td', {}, (m.status || '') + (alt ? ' ⚠' : '')));
    tr.addEventListener('click', () => sepaMandatEdit(m, members));
    tb.append(tr);
  }
  if (!rows.length) tb.append(el('tr', {}, el('td', { colspan: '7' }, 'Noch keine Mandate.')));
  t.append(tb);
  view.append(t);
  view.append(el('p', { class: 'muted' }, '⚠ = seit 36 Monaten kein Einzug – das Mandat ist erloschen und muss neu erteilt werden.'));
}

function maskIban(iban) {
  const s = String(iban || '').replace(/\s+/g, '');
  return s.length < 8 ? s : `${s.slice(0, 4)} … ${s.slice(-4)}`;
}

function sepaMandatEdit(m, members) {
  view.innerHTML = '';
  const back = el('button', {}, '← Zurück');
  back.addEventListener('click', () => showSepa({ sub: 'mandate' }));
  view.append(el('p', {}, back));
  view.append(el('h1', {}, m ? `Mandat ${m.mandatsref}` : 'Mandat anlegen'));

  const f = el('form', { class: 'detail' });
  const user = userSelect(members, m ? m.user_id : '');
  const inhaber = el('input', { value: (m && m.kontoinhaber) || '' });
  const iban = el('input', { value: (m && m.iban) || '' });
  const bic = el('input', { value: (m && m.bic) || '', placeholder: 'optional' });
  const email = el('input', { type: 'email', value: (m && m.email) || '' });
  const unter = el('input', { type: 'date', value: (m && m.unterschrift_datum) || '' });
  const typ = selectEl([['CORE', 'Basislastschrift (CORE)'], ['B2B', 'Firmenlastschrift (B2B)']], m ? m.typ : 'CORE');
  const seq = selectEl([['FRST', 'Erstlastschrift (FRST)'], ['RCUR', 'Folgelastschrift (RCUR)'], ['OOFF', 'Einmallastschrift (OOFF)'], ['FNAL', 'Letzte (FNAL)']], m ? m.sequenz : 'FRST');
  const st = selectEl([['aktiv', 'aktiv'], ['widerrufen', 'widerrufen'], ['abgelaufen', 'abgelaufen']], m ? m.status : 'aktiv');
  const notiz = el('textarea', { rows: '2' });
  notiz.value = (m && m.notiz) || '';

  user.addEventListener('change', () => {
    const u = members.find((x) => String(x.id) === user.value);
    if (!u) return;
    if (!inhaber.value) inhaber.value = u.vp_sepa_kontoinhaber || u.display_name || '';
    if (!iban.value) iban.value = u.vp_sepa_iban || '';
    if (!email.value) email.value = u.user_email || '';
  });

  f.append('Mitglied', user, 'Kontoinhaber:in', inhaber, 'IBAN', iban, 'BIC', bic, 'E-Mail', email,
    'Unterschrift am', unter, 'Art', typ, 'Nächste Sequenz', seq, 'Status', st, 'Notiz', notiz);
  view.append(el('div', { class: 'card' }, f));

  const save = el('button', { class: 'primary' }, 'Speichern');
  save.addEventListener('click', async () => {
    try {
      await call(api.action.run('sepa-mandat-save', {
        id: m ? m.id : 0, user_id: user.value, kontoinhaber: inhaber.value, iban: iban.value,
        bic: bic.value, email: email.value, unterschrift_datum: unter.value,
        typ: typ.value, sequenz: seq.value, status: st.value, notiz: notiz.value,
      }));
      toast('Mandat gespeichert.');
      await runSyncQuiet();
      showSepa({ sub: 'mandate' });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(el('p', {}, save));
}

async function sepaLaeufe() {
  const rows = await call(api.data.rows('vp_sepa_laeufe', { limit: 500 })).then((x) => x.rows).catch(() => []);
  rows.sort((a, b) => String(b.faellig_am || '').localeCompare(String(a.faellig_am || '')));
  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Fällig'), el('th', {}, 'Bezeichnung'), el('th', {}, 'Posten'),
    el('th', { style: 'text-align:right' }, 'Summe'), el('th', {}, 'Status'))));
  const tb = el('tbody');
  for (const l of rows) {
    const tr = el('tr', { style: 'cursor:pointer' },
      el('td', {}, l.faellig_am || ''), el('td', {}, l.bezeichnung || ''),
      el('td', {}, String(l.anzahl || 0)), el('td', { style: 'text-align:right' }, eur(l.summe)),
      el('td', {}, l.status || ''));
    tr.addEventListener('click', () => showSepa({ sub: 'laeufe', lauf: l.id }));
    tb.append(tr);
  }
  if (!rows.length) tb.append(el('tr', {}, el('td', { colspan: '5' }, 'Noch keine Läufe.')));
  t.append(tb);
  view.append(t);
}

async function sepaNeuerLauf() {
  const konten = await call(api.data.rows('jb_konten', { limit: 2000 })).then((x) => x.rows).catch(() => []);
  const ertrag = konten.filter((k) => k.typ === 'einnahme')
    .sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }))
    .map((k) => [String(k.nummer), `${k.nummer} – ${k.bezeichnung}`]);

  const f = el('form', { class: 'detail' });
  const bez = el('input', { placeholder: 'Beitragseinzug 2026' });
  const faellig = el('input', { type: 'date', value: new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10) });
  const typ = selectEl([['beitrag', 'Mitgliedsbeiträge'], ['rechnung', 'Offene Rechnungen (Lastschrift)'], ['frei', 'Leer']], 'beitrag');
  const iv = selectEl([['jaehrlich', 'jährlich'], ['halbjaehrlich', 'halbjährlich'], ['vierteljaehrlich', 'vierteljährlich'], ['monatlich', 'monatlich']], 'jaehrlich');
  const konto = selectEl(ertrag.length ? ertrag : [['4100', '4100']], '4100');
  const quelle = el('input', { value: 'Bank KSK' });
  const zweck = el('input', { value: 'Mitgliedsbeitrag {jahr} - {name}' });
  f.append('Bezeichnung', bez, 'Fällig am', faellig, 'Quelle der Posten', typ,
    'Einzugsintervall', iv, 'Ertragskonto', konto, 'Geld-Topf', quelle, 'Verwendungszweck', zweck);
  view.append(el('div', { class: 'card' }, f));
  view.append(el('p', { class: 'muted' }, 'Platzhalter: {jahr} {monat} {name} {mandatsref} {betrag}. Der Beitrag aus dem Profil wird auf das gewählte Intervall umgerechnet.'));

  const go = el('button', { class: 'primary' }, 'Lauf anlegen');
  const run = async () => {
    try {
      const r = await call(api.action.run('sepa-lauf', {
        aktion: 'erstellen', bezeichnung: bez.value, faellig_am: faellig.value, typ: typ.value,
        intervall: iv.value, konto: konto.value, quelle: quelle.value, zweck_vorlage: zweck.value,
      }));
      toast(`Lauf mit ${(r.posten || []).length} Posten angelegt.`);
      await runSyncQuiet();
      showSepa({ sub: 'laeufe', lauf: r.lauf_id });
    } catch (e) {
      toast(e.message, true);
    }
  };
  go.addEventListener('click', run);
  f.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  view.append(el('p', {}, go));
}

async function sepaLauf(id) {
  let data;
  try {
    data = await call(api.action.run('sepa-lauf', { aktion: 'pruefen', lauf_id: id }));
  } catch (e) {
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const { lauf, posten, probleme } = data;
  const back = el('button', {}, '← Alle Läufe');
  back.addEventListener('click', () => showSepa({ sub: 'laeufe', lauf: 0 }));
  view.append(el('p', {}, back));
  view.append(el('h2', {}, `${lauf.bezeichnung} — ${lauf.faellig_am} · ${lauf.status} · ${eur(lauf.summe)}`));

  if (probleme && probleme.length) {
    const ul = el('ul');
    probleme.slice(0, 20).forEach((p) => ul.append(el('li', {}, p)));
    view.append(el('div', { class: 'note err' }, el('strong', {}, 'Vor dem Export klären:'), ul));
  }

  const bar = el('p', {});
  if (!probleme.length) {
    const x = el('button', { class: 'primary' }, 'SEPA-XML speichern');
    x.addEventListener('click', async () => {
      try {
        const r = await call(api.action.run('sepa-lauf', { aktion: 'xml', lauf_id: id }));
        const res = await call(api.app.saveFile(r.dateiname, r.xml));
        if (!res.canceled) toast(`Gespeichert: ${res.path}`);
        await runSyncQuiet();
      } catch (e) {
        toast(e.message, true);
      }
    });
    bar.append(x, ' ');
  }
  if (lauf.status !== 'gebucht') {
    const b = el('button', {}, 'Als eingezogen buchen');
    b.addEventListener('click', async () => {
      if (!confirm('Alle Posten als eingezogen ins Journal buchen?')) return;
      try {
        const r = await call(api.action.run('sepa-lauf', { aktion: 'buchen', lauf_id: id }));
        toast(`${r.gebucht} Posten gebucht (${eur(r.summe)}).`);
        await runSyncQuiet();
        showSepa({ sub: 'laeufe', lauf: id });
      } catch (e) {
        toast(e.message, true);
      }
    });
    const d = el('button', {}, 'Lauf löschen');
    d.addEventListener('click', async () => {
      if (!confirm('Lauf wirklich löschen?')) return;
      try {
        await call(api.action.run('sepa-lauf', { aktion: 'loeschen', lauf_id: id }));
        toast('Gelöscht.');
        await runSyncQuiet();
        showSepa({ sub: 'laeufe', lauf: 0 });
      } catch (e) {
        toast(e.message, true);
      }
    });
    bar.append(b, ' ', d);
  }
  view.append(bar);

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Kontoinhaber:in'), el('th', {}, 'IBAN'), el('th', {}, 'Mandat'),
    el('th', {}, 'Seq.'), el('th', { style: 'text-align:right' }, 'Betrag'),
    el('th', {}, 'Verwendungszweck'), el('th', {}, 'Status'))));
  const tb = el('tbody');
  for (const p of posten) {
    tb.append(el('tr', {},
      el('td', {}, p.kontoinhaber || ''), el('td', {}, maskIban(p.iban)),
      el('td', {}, `${p.mandatsref || ''} (${p.unterschrift_datum || '—'})`),
      el('td', {}, p.sequenz || ''), el('td', { style: 'text-align:right' }, eur(p.betrag)),
      el('td', {}, p.zweck || ''), el('td', {}, p.status || '')));
  }
  t.append(tb);
  view.append(t);
  view.append(el('p', { class: 'muted' },
    'Beim Buchen entsteht je Posten eine Einnahme im Journal – die Sammelbuchung auf dem Kontoauszug beim Bank-Import deshalb überspringen. Beträge ändern: in der Weboberfläche unter „SEPA-Lastschrift".'));
}

/* =====================================================================
 * Spenden & Zuwendungsbestätigungen
 * ================================================================== */

const spState = { sub: 'zuwendungen', jahr: new Date().getFullYear() };
const SPENDE_ART = [['geld', 'Geldzuwendung'], ['beitrag', 'Mitgliedsbeitrag'], ['sach', 'Sachzuwendung'], ['aufwand', 'Aufwandsersatz']];
const artLabel = (a) => (SPENDE_ART.find((x) => x[0] === a) || [a, a])[1];

async function showSpenden(opts = {}) {
  if (opts.sub) spState.sub = opts.sub;
  if (opts.jahr) spState.jahr = opts.jahr;
  state.current = { name: 'spenden', sub: spState.sub };
  renderNav();
  view.innerHTML = '';
  view.append(el('h1', {}, 'Spenden & Zuwendungsbestätigungen'));

  const nav = el('nav', { class: 'subnav' });
  for (const [k, label] of [['zuwendungen', 'Zuwendungen'], ['spender', 'Spender:innen'], ['bestaetigungen', 'Bestätigungen']]) {
    nav.append(el('button', {
      class: 'chip' + (spState.sub === k ? ' is-active' : ''),
      onclick: () => showSpenden({ sub: k }),
    }, label));
  }
  view.append(nav);

  const jn = el('p', { class: 'muted' }, 'Jahr: ');
  const now = new Date().getFullYear();
  for (let y = now; y >= now - 5; y--) {
    const b = el('button', { class: y === spState.jahr ? 'primary' : '' }, String(y));
    b.addEventListener('click', () => showSpenden({ jahr: y }));
    jn.append(b, ' ');
  }
  view.append(jn);

  const [spenden, spender, zuw] = await Promise.all([
    call(api.data.rows('vp_spenden', { limit: 10000 })).then((x) => x.rows).catch(() => []),
    call(api.data.rows('vp_spender', { limit: 5000 })).then((x) => x.rows).catch(() => []),
    call(api.data.rows('vp_zuwendungen', { limit: 5000 })).then((x) => x.rows).catch(() => []),
  ]);

  if (spState.sub === 'spender') return spViewSpender(spenden, spender);
  if (spState.sub === 'bestaetigungen') return spViewBestaetigungen(zuw, spender);
  return spViewZuwendungen(spenden, spender, zuw);
}

const jahrVon = (d) => Number(String(d || '').slice(0, 4));

function spViewZuwendungen(spenden, spender, zuw) {
  const rows = spenden.filter((s) => jahrVon(s.datum) === spState.jahr)
    .sort((a, b) => String(b.datum).localeCompare(String(a.datum)));
  const nameOf = (id) => (spender.find((p) => String(p.id) === String(id)) || {}).name || '—';
  const spOf = (id) => spender.find((p) => String(p.id) === String(id));
  const zbOf = (id) => (zuw.find((z) => String(z.id) === String(id)) || {}).nummer || '—';

  const summe = rows.reduce((s, r) => s + Number(r.betrag || 0), 0);
  const offen = rows.filter((r) => !r.bescheinigung_id).reduce((s, r) => s + Number(r.betrag || 0), 0);
  view.append(el('p', { class: 'sub' }, `${spState.jahr}: ${eur(summe)} Zuwendungen, davon ${eur(offen)} noch ohne Bestätigung.`));

  const imp = el('button', {}, 'Aus dem Journal übernehmen');
  imp.addEventListener('click', async () => {
    try {
      const r = await call(api.action.run('spende-save', { import: true, jahr: spState.jahr }));
      toast(`${r.neu} übernommen, bei ${r.ohne_anschrift} fehlt die Anschrift.`);
      await runSyncQuiet();
      showSpenden();
    } catch (e) {
      toast(e.message, true);
    }
  });
  const alle = el('button', { class: 'primary' }, 'Sammelbestätigungen für alle');
  alle.addEventListener('click', async () => {
    if (!confirm('Für alle Spender:innen mit offenen Zuwendungen eine Sammelbestätigung ausstellen?')) return;
    try {
      const r = await call(api.action.run('zuwendung', { aktion: 'alle', jahr: spState.jahr }));
      toast(`${r.ausgestellt} Bestätigungen ausgestellt.`);
      if ((r.fehler || []).length) toast(r.fehler.slice(0, 3).join(' · '), true);
      await runSyncQuiet();
      showSpenden({ sub: 'bestaetigungen' });
    } catch (e) {
      toast(e.message, true);
    }
  });
  view.append(el('p', {}, imp, ' ', alle));

  /* Von Hand erfassen */
  const f = el('form', { class: 'detail' });
  const sel = selectEl([['', '— neu —'], ...spender.map((p) => [String(p.id), p.name])], '');
  const nname = el('input', { placeholder: 'Name, falls neu' });
  const dat = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });
  const betrag = moneyInput('');
  const art = selectEl(SPENDE_ART, 'geld');
  const besch = el('input', {});
  const verz = el('input', { type: 'checkbox' });
  f.append('Spender:in', sel, '… oder neuer Name', nname, 'Datum', dat, 'Betrag', betrag,
    'Art', art, 'Beschreibung', besch, 'Verzicht auf Aufwendungsersatz', verz);
  const add = el('button', {}, 'Erfassen');
  const doAdd = async () => {
    try {
      await call(api.action.run('spende-save', {
        spender_id: sel.value, name: nname.value, datum: dat.value, betrag: parseNum(betrag.value),
        art: art.value, beschreibung: besch.value, verzicht: verz.checked ? 1 : 0,
      }));
      toast('Zuwendung erfasst.');
      await runSyncQuiet();
      showSpenden();
    } catch (e) {
      toast(e.message, true);
    }
  };
  add.addEventListener('click', doAdd);
  f.addEventListener('submit', (e) => { e.preventDefault(); doAdd(); });
  const det = el('details', { class: 'card' }, el('summary', {}, 'Zuwendung von Hand erfassen'), f, el('p', {}, add));
  view.append(det);

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Datum'), el('th', {}, 'Spender:in'), el('th', {}, 'Anschrift'),
    el('th', {}, 'Art'), el('th', { style: 'text-align:right' }, 'Betrag'), el('th', {}, 'Bestätigung'))));
  const tb = el('tbody');
  for (const r of rows) {
    const p = spOf(r.spender_id);
    const adr = p ? [p.strasse, [p.plz, p.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ') : '';
    tb.append(el('tr', {},
      el('td', {}, r.datum || ''),
      el('td', {}, nameOf(r.spender_id)),
      adr ? el('td', {}, adr) : el('td', { class: 'warn' }, 'fehlt'),
      el('td', {}, artLabel(r.art)),
      el('td', { style: 'text-align:right' }, eur(r.betrag)),
      el('td', {}, r.bescheinigung_id ? zbOf(r.bescheinigung_id) : '—')));
  }
  if (!rows.length) tb.append(el('tr', {}, el('td', { colspan: '6' }, 'Keine Zuwendungen in diesem Jahr.')));
  t.append(tb);
  view.append(t);
}

function spViewSpender(spenden, spender) {
  const neu = el('button', { class: 'primary' }, 'Spender:in anlegen');
  neu.addEventListener('click', () => spSpenderEdit(null));
  view.append(el('p', {}, neu));

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Name'), el('th', {}, 'Anschrift'), el('th', {}, 'E-Mail'),
    el('th', { style: 'text-align:right' }, 'Zuwendungen'), el('th', {}, 'Ohne Bestätigung'))));
  const tb = el('tbody');
  for (const p of spender.slice().sort((a, b) => String(a.name).localeCompare(String(b.name)))) {
    const meine = spenden.filter((s) => String(s.spender_id) === String(p.id));
    const summe = meine.reduce((s, x) => s + Number(x.betrag || 0), 0);
    const offen = meine.filter((s) => !s.bescheinigung_id && jahrVon(s.datum) === spState.jahr)
      .reduce((s, x) => s + Number(x.betrag || 0), 0);
    const adr = [p.strasse, [p.plz, p.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ');

    const td = el('td', {});
    if (offen > 0) {
      const b = el('button', {}, `${eur(offen)} (${spState.jahr}) bestätigen`);
      b.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        try {
          const r = await call(api.action.run('zuwendung', { aktion: 'erstellen', spender_id: p.id, typ: 'sammel', jahr: spState.jahr }));
          toast(`Bestätigung ${r.bestaetigung.nummer} ausgestellt.`);
          await runSyncQuiet();
          showSpenden({ sub: 'bestaetigungen' });
        } catch (e) {
          toast(e.message, true);
        }
      });
      td.append(b);
    } else {
      td.append('—');
    }

    const tr = el('tr', { style: 'cursor:pointer' },
      el('td', {}, p.name || ''),
      adr ? el('td', {}, adr) : el('td', { class: 'warn' }, 'fehlt'),
      el('td', {}, p.email || ''),
      el('td', { style: 'text-align:right' }, `${eur(summe)} (${meine.length})`),
      td);
    tr.addEventListener('click', () => spSpenderEdit(p));
    tb.append(tr);
  }
  if (!spender.length) tb.append(el('tr', {}, el('td', { colspan: '5' }, 'Noch keine Spender:innen erfasst.')));
  t.append(tb);
  view.append(t);
}

async function spSpenderEdit(p) {
  const members = await membersList();
  view.innerHTML = '';
  const back = el('button', {}, '← Zurück');
  back.addEventListener('click', () => showSpenden({ sub: 'spender' }));
  view.append(el('p', {}, back));
  view.append(el('h1', {}, p ? p.name : 'Spender:in anlegen'));

  const f = el('form', { class: 'detail' });
  const name = el('input', { value: (p && p.name) || '' });
  const strasse = el('input', { value: (p && p.strasse) || '' });
  const plz = el('input', { value: (p && p.plz) || '' });
  const ort = el('input', { value: (p && p.ort) || '' });
  const land = el('input', { value: (p && p.land) || '' });
  const email = el('input', { type: 'email', value: (p && p.email) || '' });
  const user = userSelect(members, p ? p.user_id : '');
  user.addEventListener('change', () => {
    const u = members.find((x) => String(x.id) === user.value);
    if (!u) return;
    if (!name.value) name.value = u.display_name || '';
    if (!strasse.value) strasse.value = u.vp_strasse || '';
    if (!plz.value) plz.value = u.vp_plz || '';
    if (!ort.value) ort.value = u.vp_ort || '';
    if (!email.value) email.value = u.user_email || '';
  });
  f.append('Mitglied', user, 'Name', name, 'Straße, Nr.', strasse, 'PLZ', plz, 'Ort', ort, 'Land', land, 'E-Mail', email);
  view.append(el('div', { class: 'card' }, f));

  const save = el('button', { class: 'primary' }, 'Speichern');
  const doSave = async () => {
    try {
      await call(api.action.run('spender-save', {
        id: p ? p.id : 0, user_id: user.value, name: name.value, strasse: strasse.value,
        plz: plz.value, ort: ort.value, land: land.value, email: email.value,
      }));
      toast('Gespeichert.');
      await runSyncQuiet();
      showSpenden({ sub: 'spender' });
    } catch (e) {
      toast(e.message, true);
    }
  };
  save.addEventListener('click', doSave);
  f.addEventListener('submit', (e) => { e.preventDefault(); doSave(); });
  view.append(el('p', {}, save));
}

function spViewBestaetigungen(zuw, spender) {
  const rows = zuw.filter((z) => Number(z.jahr) === spState.jahr)
    .sort((a, b) => String(b.nummer).localeCompare(String(a.nummer)));
  const nameOf = (id) => (spender.find((p) => String(p.id) === String(id)) || {}).name || '—';

  const t = el('table');
  t.append(el('thead', {}, el('tr', {},
    el('th', {}, 'Nummer'), el('th', {}, 'Spender:in'), el('th', {}, 'Art'),
    el('th', { style: 'text-align:right' }, 'Summe'), el('th', {}, 'Ausgestellt'), el('th', {}, ''))));
  const tb = el('tbody');
  for (const z of rows) {
    const akt = el('td', {});
    const p = el('button', {}, 'Drucken / PDF');
    p.addEventListener('click', async () => {
      try {
        const r = await call(api.action.run('zuwendung', { aktion: 'html', id: z.id }));
        await call(api.app.openHtml(`Zuwendungsbestätigung ${z.nummer}`, r.html, r.css));
      } catch (e) {
        toast(e.message, true);
      }
    });
    akt.append(p, ' ');
    if (!Number(z.storniert)) {
      const m = el('button', {}, 'Mailen');
      m.addEventListener('click', async () => {
        try {
          await call(api.action.run('zuwendung', { aktion: 'mail', id: z.id }));
          toast('Verschickt.');
        } catch (e) {
          toast(e.message, true);
        }
      });
      const s = el('button', {}, 'Stornieren');
      s.addEventListener('click', async () => {
        if (!confirm('Bestätigung stornieren?')) return;
        try {
          await call(api.action.run('zuwendung', { aktion: 'storno', id: z.id }));
          toast('Storniert.');
          await runSyncQuiet();
          showSpenden({ sub: 'bestaetigungen' });
        } catch (e) {
          toast(e.message, true);
        }
      });
      akt.append(m, ' ', s);
    }
    tb.append(el('tr', {},
      el('td', {}, z.nummer + (Number(z.storniert) ? ' (storniert)' : '')),
      el('td', {}, nameOf(z.spender_id)),
      el('td', {}, z.typ === 'sammel' ? 'Sammelbestätigung' : 'Einzelbestätigung'),
      el('td', { style: 'text-align:right' }, eur(z.summe)),
      el('td', {}, String(z.ausgestellt_am || '').slice(0, 10)),
      akt));
  }
  if (!rows.length) tb.append(el('tr', {}, el('td', { colspan: '6' }, 'Für dieses Jahr wurden noch keine Bestätigungen ausgestellt.')));
  t.append(tb);
  view.append(t);
}
