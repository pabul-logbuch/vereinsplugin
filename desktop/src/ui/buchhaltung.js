'use strict';

/* global BUCH, api, state, view, el, call, toast, eur, selectEl, moneyInput, parseNum, labelMitHilfe,
   renderNav, runSyncQuiet, showConflicts, showDetail, showTable, openBeleg, fmtNum */

/**
 * Buchhaltung der Desktop-App: Journal, Buchung bearbeiten, Auswertung,
 * Kontoauszug/Kontenblatt, Geschäftsjahr und Kontenplan.
 *
 * Jedes Geschäftsjahr wird entweder als EÜR oder als Doppik geführt. Davon
 * hängt ab, wie Buchungen eingegeben und ausgewertet werden – die gespeicherten
 * Zeilen sind in beiden Fällen dieselben. Gerechnet wird lokal aus dem
 * Datenspiegel (buchlogik.js, Spiegel der PHP-Logik), damit alles offline geht.
 *
 * Wird vor app.js geladen; nutzt dessen Helfer erst zur Laufzeit.
 */

const BH = { jahr: new Date().getFullYear(), konto: '', q: '', sel: new Set(), panel: null };

const METHODEN = { euer: 'Einnahmen-Überschuss-Rechnung (EÜR)', doppik: 'Doppelte Buchführung (Doppik)' };
const SPHAERE_OPTS = [
  ['', '—'], ['ideell', 'Ideeller Bereich'], ['zweckbetrieb', 'Zweckbetrieb'],
  ['vermoegen', 'Vermögensverwaltung'], ['wirtschaftlich', 'Wirtschaftl. Geschäftsbetrieb'], ['neutral', 'Neutral / Umbuchung'],
];
const SPHAERE_LABEL = Object.fromEntries(SPHAERE_OPTS);

/* ------------------------------------------------------------------ Daten */

/** Alles, was die Buchhaltungs-Ansichten brauchen, aus dem lokalen Spiegel. */
async function bhLaden() {
  const rows = (slug, limit) => call(api.data.rows(slug, { limit })).then((r) => r.rows);
  const t = (state.meta && state.meta.tables) || {};
  const [buchungen, konten, best, gj, ruecklagen, budgets] = await Promise.all([
    rows('jb_buchungen', 50000),
    rows('jb_konten', 2000),
    t.jb_anfangsbestaende ? rows('jb_anfangsbestaende', 5000) : [],
    t.jb_geschaeftsjahre ? rows('jb_geschaeftsjahre', 500) : [],
    t.jb_ruecklagen ? rows('jb_ruecklagen', 500).then((r) => r.filter((x) => String(x.aktiv) !== '0')) : [],
    t.jb_budgets ? rows('jb_budgets', 500).then((r) => r.filter((x) => String(x.aktiv) !== '0')) : [],
  ]);
  const cols = (t.jb_buchungen && t.jb_buchungen.columns) || [];
  return {
    buchungen, konten, best, gj, ruecklagen, budgets,
    info: BUCH.kontoInfo(konten),
    // Ohne Plugin v0.33 gibt es weder feste Geldkonten noch Geschäftsjahre.
    umgestellt: cols.includes('geldkonto') && !!t.jb_geschaeftsjahre,
    cols: new Set(cols),
  };
}

function bhJahre(d, extra = []) {
  const s = new Set([new Date().getFullYear(), ...extra]);
  for (const r of d.buchungen) if (r.buchung_datum) s.add(Number(String(r.buchung_datum).slice(0, 4)));
  for (const r of d.best) if (Number(r.jahr)) s.add(Number(r.jahr));
  for (const r of d.gj) if (Number(r.jahr)) s.add(Number(r.jahr));
  return [...s].filter(Boolean).sort((a, b) => b - a);
}

const kLabel = (d, nr) => {
  if (!nr) return '—';
  const k = d.info.get(String(nr));
  return `${nr}${k && k.bezeichnung ? ' · ' + k.bezeichnung : ''}`;
};
const istInterim = (nr) => nr === BUCH.INTERIM || nr === BUCH.INTERIM2;

/**
 * Konto-Auswahl, gruppiert. filter: 'alle' | 'geld' (Geld- und Bestandskonten)
 * | 'erfolg' (Einnahmen und Ausgaben) | 'einnahme' | 'ausgabe'.
 */
function kontoSelect(konten, value, filter = 'alle', leer = '—', attrs = {}) {
  const gruppen = { geld: 'Geldkonten', bestand: 'Weitere Bestandskonten', einnahme: 'Einnahmen', ausgabe: 'Ausgaben' };
  const erlaubt = {
    alle: ['geld', 'bestand', 'einnahme', 'ausgabe'], geld: ['geld', 'bestand'], erfolg: ['einnahme', 'ausgabe'],
    einnahme: ['einnahme', 'ausgabe'], ausgabe: ['ausgabe', 'einnahme'],
  }[filter] || ['geld', 'bestand', 'einnahme', 'ausgabe'];
  const cur = String(value ?? '');
  const s = el('select', attrs);
  if (leer !== null) s.append(el('option', { value: '' }, leer));
  let gefunden = cur === '';
  for (const g of erlaubt) {
    const liste = (konten || [])
      .filter((k) => (['geld', 'einnahme', 'ausgabe'].includes(k.typ) ? k.typ : 'bestand') === g)
      .filter((k) => String(k.aktiv) !== '0' || String(k.nummer) === cur)
      .sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }));
    if (!liste.length) continue;
    const og = el('optgroup', { label: gruppen[g] });
    for (const k of liste) {
      og.append(el('option', { value: String(k.nummer) }, `${k.nummer} – ${k.bezeichnung}`));
      if (String(k.nummer) === cur) gefunden = true;
    }
    s.append(og);
  }
  if (!gefunden) s.insertBefore(el('option', { value: cur }, `${cur} (aktuell)`), s.firstChild);
  s.value = cur;
  return s;
}

/** Erklärkasten zum Aufklappen. */
function hilfe(titel, absaetze, offen = false) {
  const d = el('details', { class: 'hilfe' });
  if (offen) d.setAttribute('open', 'open');
  d.append(el('summary', {}, titel));
  for (const a of absaetze) d.append(el('p', { class: 'bsp' }, a));
  return d;
}

/** Überschrift, Jahresauswahl und Hinweis zur Buchführungsart. */
function bhKopf(d, titel, untertitel, neuLaden, { folgejahr = false } = {}) {
  view.innerHTML = '';
  view.append(el('h1', {}, titel));
  if (untertitel) view.append(el('p', { class: 'sub' }, untertitel));
  if (!d.umgestellt) {
    view.append(el('div', { class: 'note err' },
      'Das Plugin auf der Website ist noch nicht auf v0.33 aktualisiert (oder die App hat danach noch nicht synchronisiert). '
      + 'Bis dahin sind Geschäftsjahre und feste Geldkonten nicht verfügbar – Anzeige nach alter Rechnung, Bearbeiten gesperrt.'));
  }
  const jahre = bhJahre(d, folgejahr ? [new Date().getFullYear() + 1] : []);
  if (!jahre.includes(BH.jahr)) BH.jahr = jahre[0];
  const m = BUCH.methodeFuer(d.gj, BH.jahr);
  const bar = el('div', { class: 'toolbar' });
  bar.append('Geschäftsjahr:', selectEl(jahre.map((j) => [String(j), String(j)]), String(BH.jahr), {
    onchange: (e) => { BH.jahr = Number(e.target.value); BH.sel.clear(); neuLaden(); },
  }));
  bar.append(el('span', { class: 'tag', style: 'background:var(--panel);color:inherit;border:1px solid var(--line)' }, m.methode === 'doppik' ? 'Doppik' : 'EÜR'));
  if (state.current.name !== 'geschaeftsjahr') {
    bar.append(el('button', { class: 'small ghost', onclick: () => showGeschaeftsjahr() }, 'Was heißt das? / ändern'));
  }
  view.append(bar);
  if (!m.gesetzt && d.umgestellt) {
    view.append(el('div', { class: 'note warn' },
      `Für ${BH.jahr} ist noch keine Buchführungsart festgelegt – bis dahin gilt die EÜR. Am besten gleich zu Jahresbeginn wählen. `,
      state.current.name !== 'geschaeftsjahr' ? el('button', { class: 'small', onclick: () => showGeschaeftsjahr() }, 'Jetzt festlegen') : null));
  }
  return m.methode;
}

/** Offene Aktion über den Server, danach synchronisieren. */
async function bhAktion(name, body, erfolg) {
  const r = await call(api.action.run(name, body));
  await runSyncQuiet();
  if (erfolg) toast(typeof erfolg === 'function' ? erfolg(r) : erfolg);
  return r;
}

/* ----------------------------------------------------------------- Journal */

async function showJournal() {
  state.current = { name: 'journal', slug: 'jb_buchungen' };
  renderNav();
  let d;
  try {
    d = await bhLaden();
  } catch (e) {
    view.innerHTML = '';
    return view.append(el('div', { class: 'note err' }, 'Lokale Daten nicht lesbar: ' + e.message));
  }
  const methode = bhKopf(d, 'Buchungsjournal', 'Alle Buchungen des Geschäftsjahres. Zeile anklicken zum Bearbeiten.', showJournal);
  const jahr = String(BH.jahr);
  const rows = d.buchungen.filter((r) => String(r.buchung_datum || '').startsWith(jahr));

  view.append(methode === 'doppik'
    ? hilfe('So bucht ihr in der Doppik', [
      'Jede Buchung ist ein Buchungssatz „Soll an Haben": Soll ist das Konto, auf das der Betrag geht, Haben das Konto, von dem er kommt. Der Betrag ist immer positiv.',
      'Beitrag per Überweisung: 1200 Bank an 4100 Mitgliedsbeiträge. Getränke bar gekauft: 5600 Wareneinkauf an 1000 Kasse. Bargeld zur Bank: 1200 Bank an 1000 Kasse.',
      'Auch Vorgänge ohne Geldfluss gehen, z. B. eine genehmigte, noch nicht ausgezahlte Auslage: 5140 Material an 1600 Verbindlichkeiten – bei Auszahlung dann 1600 an 1200 Bank.',
    ])
    : hilfe('So bucht ihr in der EÜR', [
      'Jede Buchung beantwortet drei Fragen: Art (kommt Geld rein, geht es raus oder wandert es nur zwischen euren Konten?), Geldkonto – wo? (Bank, Barkasse, PayPal) und SKR-Konto – wofür? (z. B. 4100 Mitgliedsbeiträge). Der Betrag ist immer positiv.',
      'Beispiel Einnahme: Beitrag 30 € per Überweisung → Einnahme, Geldkonto Bank, SKR-Konto 4100, Gegenpartei „Anna Müller".',
      'Beispiel Ausgabe: Getränke bar gekauft für 84,20 € → Ausgabe, Geldkonto Barkasse, SKR-Konto 5600.',
      'Beispiel Umbuchung: Kasseninhalt zur Bank gebracht → Umbuchung von Barkasse nach Bank. Umbuchungen sind weder Einnahme noch Ausgabe und zählen deshalb nicht im Überschuss.',
      'Zettle-Kartenzahlungen und PayPal sind dasselbe Konto (PayPal).',
    ]));

  // Aktionen
  const panelHost = el('div', {});
  const toggle = (kind, builder) => {
    panelHost.innerHTML = '';
    if (BH.panel === kind) { BH.panel = null; return; }
    BH.panel = kind;
    panelHost.append(builder());
  };
  BH.panel = null;
  const acts = el('div', { class: 'toolbar' });
  if (d.umgestellt) {
    acts.append(
      el('button', { class: 'primary small', onclick: () => toggle('add', () => buchungFormular(d, methode, null, showJournal)) }, '+ Buchung'),
      el('button', { class: 'small', onclick: () => toggle('csv', () => bankCsvForm(d)) }, '⇑ Bank-CSV importieren'),
    );
  }
  view.append(acts, panelHost);

  // Auswahl
  const selBar = el('div', { class: 'toolbar' });
  selBar.hidden = true;
  view.append(selBar);
  const renderSel = () => {
    selBar.innerHTML = '';
    const n = BH.sel.size;
    selBar.hidden = n === 0;
    if (!n) return;
    const ids = [...BH.sel];
    selBar.append(el('span', {}, `${n} ausgewählt`));
    selBar.append(el('button', { class: 'small danger', onclick: () => loeschen(ids) }, `${n} löschen`));
    if (d.umgestellt && n === 1) selBar.append(el('button', { class: 'small', onclick: () => toggle('split', () => splitForm(d, rows.find((x) => String(x.id) === ids[0]))) }, 'Aufteilen'));
    if (d.umgestellt && (n === 1 || n === 2)) selBar.append(el('button', { class: 'small', onclick: () => toggle('umb', () => zuUmbuchungForm(d, ids)) }, 'Zu Umbuchung'));
    selBar.append(el('button', { class: 'small ghost', onclick: () => { BH.sel.clear(); draw(); renderSel(); } }, 'Auswahl aufheben'));
  };
  async function loeschen(ids) {
    if (!confirm(`${ids.length} Buchung(en) löschen? Wird beim nächsten Sync auch auf dem Server gelöscht.`)) return;
    try {
      for (const id of ids) await call(api.data.remove('jb_buchungen', id));
      BH.sel.clear();
      await runSyncQuiet();
      toast(`${ids.length} gelöscht.`);
      showJournal();
    } catch (e) { toast(e.message, true); }
  }

  // Filter
  const fb = el('div', { class: 'toolbar' });
  const kS = kontoSelect(d.konten, BH.konto, 'alle', 'Alle Konten', { onchange: (e) => { BH.konto = e.target.value; draw(); } });
  const such = el('input', { type: 'search', placeholder: 'Text, Gegenpartei, Beleg-Nr …', value: BH.q });
  such.addEventListener('input', (e) => { BH.q = e.target.value; draw(); });
  fb.append('Konto:', kS, such);
  view.append(fb);
  const host = el('div', {});
  view.append(host);

  function draw() {
    const q = BH.q.toLowerCase();
    const liste = rows
      .map((r) => ({ r, s: BUCH.satz(r), v: BUCH.euerSicht(r, d.info) }))
      .filter(({ r, s }) => (!BH.konto || s.soll === BH.konto || s.haben === BH.konto)
        && (!q || `${r.beschreibung} ${r.gegenpartei} ${r.beleg_nr} ${r.kategorie}`.toLowerCase().includes(q)))
      .sort((a, b) => a.s.datum.localeCompare(b.s.datum) || Number(a.r.id) - Number(b.r.id));

    // Laufender Stand nur, wenn nach einem Geld-/Bestandskonto gefiltert wird.
    const stand = new Map();
    if (BH.konto && !BUCH.istErfolg(BH.konto, d.info)) {
      for (const z of BUCH.kontenblatt(d.buchungen, d.best, BH.konto, BH.jahr).zeilen) stand.set(String(z.id), z.saldo);
    }

    const t = el('table');
    const allChk = el('input', { type: 'checkbox' });
    allChk.checked = liste.length > 0 && liste.every(({ r }) => BH.sel.has(String(r.id)));
    allChk.addEventListener('change', () => {
      for (const { r } of liste) allChk.checked ? BH.sel.add(String(r.id)) : BH.sel.delete(String(r.id));
      draw(); renderSel();
    });
    const R = { style: 'text-align:right' };
    const kopf = methode === 'doppik'
      ? [el('th', {}, 'Soll'), el('th', {}, 'Haben'), el('th', R, 'Betrag'), el('th', {}, 'Text')]
      : [el('th', {}, 'Wofür / Konto'), el('th', {}, 'Gegenpartei / Zweck'), el('th', R, 'Einnahme'), el('th', R, 'Ausgabe')];
    t.append(el('thead', {}, el('tr', {}, el('th', {}, allChk), el('th', {}, 'Datum'), el('th', {}, 'Beleg'), ...kopf, stand.size ? el('th', R, 'Stand') : null)));
    const tb = el('tbody');
    let summeEin = 0;
    let summeAus = 0;
    for (const { r, s, v } of liste) {
      const chk = el('input', { type: 'checkbox' });
      chk.checked = BH.sel.has(String(r.id));
      chk.addEventListener('click', (e) => e.stopPropagation());
      chk.addEventListener('change', () => { chk.checked ? BH.sel.add(String(r.id)) : BH.sel.delete(String(r.id)); renderSel(); });
      const text = el('td', {}, r.gegenpartei || '', r.beschreibung ? el('div', { class: 'muted' }, r.beschreibung) : null);
      if (r.beleg_pfad) text.append(el('span', { style: 'cursor:pointer', title: 'Beleg öffnen', onclick: (e) => { e.stopPropagation(); openBeleg(r.beleg_pfad); } }, ' 📎'));
      let zellen;
      if (methode === 'doppik') {
        zellen = [el('td', {}, kLabel(d, s.soll)), el('td', {}, kLabel(d, s.haben)), el('td', R, eur(s.betrag)), text];
      } else if (v && v.art === 'umbuchung') {
        zellen = [el('td', {}, 'Umbuchung', el('div', { class: 'muted' }, `${kLabel(d, v.von)} → ${kLabel(d, v.nach)}`)), text,
          el('td', { colspan: '2', class: 'muted', style: 'text-align:center' }, `⇄ ${eur(v.betrag)}`)];
      } else if (v) {
        if (v.art === 'einnahme') summeEin += v.betrag; else summeAus += v.betrag;
        zellen = [
          el('td', {}, istInterim(v.konto) ? el('span', { style: 'color:var(--err-ink)' }, '– noch nicht zugeordnet –') : kLabel(d, v.konto),
            el('div', { class: 'muted' }, `${v.art === 'einnahme' ? 'auf' : 'von'} ${kLabel(d, v.geldkonto)}`)),
          text,
          el('td', { style: 'text-align:right;color:var(--accent)' }, v.art === 'einnahme' ? eur(v.betrag) : ''),
          el('td', { style: 'text-align:right;color:var(--err-ink)' }, v.art === 'ausgabe' ? eur(v.betrag) : ''),
        ];
      } else {
        zellen = [el('td', {}, 'Buchungssatz', el('div', { class: 'muted' }, `${s.soll} an ${s.haben}`)), text,
          el('td', { colspan: '2', class: 'muted', style: 'text-align:center' }, eur(s.betrag))];
      }
      const sd = stand.get(String(r.id));
      tb.append(el('tr', { class: r._dirty ? 'dirty' : '', style: 'cursor:pointer', onclick: () => showBuchung(r.id) },
        el('td', {}, chk), el('td', {}, r.buchung_datum), el('td', {}, r.beleg_nr || ''), ...zellen,
        stand.size ? el('td', { style: 'text-align:right' + (sd < 0 ? ';color:var(--err-ink)' : '') }, sd == null ? '' : eur(sd)) : null));
    }
    t.append(tb);
    if (methode === 'euer' && liste.length) {
      t.append(el('tfoot', {}, el('tr', { style: 'font-weight:700' }, el('td', { colspan: '5' }, 'Summe (ohne Umbuchungen)'),
        el('td', R, eur(summeEin)), el('td', R, eur(summeAus)), stand.size ? el('td', {}) : null)));
    }
    host.innerHTML = '';
    host.append(t);
    host.append(el('p', { class: 'muted' }, `${liste.length} Buchung(en) in ${BH.jahr}` + (stand.size ? ' · Stand = Kontostand nach der Buchung' : ' · Nach einem Geldkonto filtern zeigt den laufenden Kontostand.')));
  }
  draw();
  renderSel();
}

/* ------------------------------------------------------ Buchungsformular */

/**
 * Formular für eine neue oder bestehende Buchung – als EÜR (Art, Geldkonto,
 * SKR-Konto) oder als Buchungssatz (Soll an Haben).
 */
function buchungFormular(d, methode, row, nachSpeichern) {
  const neu = !row;
  const r = row || {};
  let v = neu ? { art: 'ausgabe', geldkonto: bhVorgabe(d), konto: '', betrag: '' } : BUCH.euerSicht(r, d.info);
  const card = el('div', { class: 'card' });
  if (methode === 'euer' && !v) {
    methode = 'doppik';
    card.append(el('div', { class: 'note' }, 'Diese Buchung ist ein reiner Buchungssatz ohne Geldkonto und lässt sich nur als Soll/Haben bearbeiten.'));
  }
  const s = neu ? { soll: '', haben: '', betrag: '' } : BUCH.satz(r);
  if (neu) card.append(el('h2', {}, 'Neue Buchung'));

  const form = el('form', { class: 'detail' });
  const feld = (label, node, hint) => { form.append(labelMitHilfe(label, hint), node); return node; };

  const fDatum = feld('Datum', el('input', { type: 'date', value: r.buchung_datum || new Date().toISOString().slice(0, 10) }));
  const fBetrag = feld('Betrag (€, immer positiv)', moneyInput(neu ? '' : (methode === 'euer' ? v.betrag : s.betrag), { placeholder: '0,00' }));

  let fArt; let fGeld; let fKonto; let fNach; let fSoll; let fHaben; let lKonto; let lNach;
  if (methode === 'euer') {
    fArt = feld('Art', selectEl([['einnahme', 'Einnahme – Geld kommt rein'], ['ausgabe', 'Ausgabe – Geld geht raus'], ['umbuchung', 'Umbuchung – zwischen eigenen Konten']], v.art));
    fGeld = feld('Geldkonto – wo?', kontoSelect(d.konten, v.art === 'umbuchung' ? v.von : v.geldkonto, 'geld', null),
      'Auf welchem Konto das Geld liegt (Bank, Barkasse, PayPal). Bei einer Umbuchung: von welchem Konto.');
    lKonto = labelMitHilfe('SKR-Konto – wofür?', 'Der Grund: 4100 Beiträge, 4600 Getränke, 5600 Wareneinkauf … Nur dieses Konto steht in der EÜR.');
    fKonto = kontoSelect(d.konten, v.art === 'umbuchung' ? '' : (istInterim(v.konto) ? '' : v.konto), v.art === 'einnahme' ? 'einnahme' : 'ausgabe', '– noch nicht zugeordnet –');
    lNach = labelMitHilfe('Nach Konto', 'Wohin das Geld gebracht wurde, z. B. von Barkasse nach Bank.');
    fNach = kontoSelect(d.konten, v.art === 'umbuchung' ? v.nach : '', 'geld', '—');
    form.append(lKonto, fKonto, lNach, fNach);
    const umschalten = () => {
      const umb = fArt.value === 'umbuchung';
      lKonto.hidden = umb; fKonto.hidden = umb; lNach.hidden = !umb; fNach.hidden = !umb;
    };
    fArt.addEventListener('change', umschalten);
    umschalten();
  } else {
    fSoll = feld('Soll – wohin geht der Betrag?', kontoSelect(d.konten, s.soll, 'alle'),
      'Bei einer Einnahme das Geldkonto, bei einer Ausgabe das Aufwandskonto.');
    fHaben = feld('Haben – woher kommt er?', kontoSelect(d.konten, s.haben, 'alle'),
      'Bei einer Einnahme das Ertragskonto, bei einer Ausgabe das Geldkonto.');
  }

  const vorschau = el('p', { class: 'muted', style: 'grid-column:1/-1;margin:0' });
  form.append(vorschau);

  const gpListe = el('datalist', { id: 'dl_gegenpartei' });
  for (const g of [...new Set(d.buchungen.map((x) => String(x.gegenpartei || '').trim()).filter(Boolean))].sort()) gpListe.append(el('option', { value: g }));
  form.append(gpListe);
  const fGp = feld('Gegenpartei – mit wem?', el('input', { type: 'text', list: 'dl_gegenpartei', value: r.gegenpartei || '', placeholder: 'z. B. Bauhaus, Anna Müller' }),
    'Freitext, kein Konto – nur zum Wiederfinden.');
  const fText = feld('Beschreibung', el('textarea', {}));
  fText.value = r.beschreibung || '';
  const fBeleg = el('input', { type: 'text', value: r.beleg_nr || '', placeholder: neu ? 'leer = automatisch' : '' });
  const nextBeleg = () => {
    const j = String(fDatum.value || '').slice(0, 4);
    let max = 0;
    for (const x of d.buchungen) {
      const m = /^(\d{4})-(\d+)$/.exec(String(x.beleg_nr || ''));
      if (m && m[1] === j) max = Math.max(max, Number(m[2]));
    }
    return `${j}-${String(max + 1).padStart(4, '0')}`;
  };
  feld('Beleg-Nr', el('div', { class: 'row' }, fBeleg, el('button', { type: 'button', class: 'small ghost', onclick: () => { fBeleg.value = nextBeleg(); } }, 'nächste freie')));
  const fSph = feld('Sphäre', selectEl(SPHAERE_OPTS, r.sphaere || ''), 'Steuerlicher Bereich. Folgt normalerweise dem SKR-Konto.');
  let sphAngefasst = !neu && !!r.sphaere;
  fSph.addEventListener('change', () => { sphAngefasst = true; });

  let fBudget = null; let fKs = null; let fRl = null;
  if (d.cols.has('budget_id') && d.budgets.length) {
    fBudget = feld('Budget belasten', selectEl([['', '— kein Budget —'], ...d.budgets.map((b) => [String(b.id), `${b.zweck}${b.kostenstelle ? ' · ' + b.kostenstelle : ''}`])], r.budget_id == null ? '' : String(r.budget_id)));
  }
  if (d.cols.has('kostenstelle')) {
    const ks = [...new Set([...d.budgets.map((b) => b.kostenstelle), ...d.buchungen.map((x) => x.kostenstelle)].map((x) => String(x || '').trim()).filter(Boolean))].sort();
    fKs = feld('Kostenstelle', selectEl([['', '— keine —'], ...ks.map((k) => [k, k])], r.kostenstelle || ''));
    if (fBudget) fBudget.addEventListener('change', () => { const b = d.budgets.find((x) => String(x.id) === fBudget.value); if (b && b.kostenstelle) fKs.value = b.kostenstelle; });
  }
  if (d.ruecklagen.length) {
    fRl = feld('Für Rücklage', selectEl([['', '— keine —'], ...d.ruecklagen.map((x) => [String(x.id), x.bezeichnung])], r.ruecklage_id == null ? '' : String(r.ruecklage_id)),
      'Setzt bei der Rücklage „letzte Zahlung" auf das Buchungsdatum.');
  }

  let fPfad = null;
  if (!neu) {
    fPfad = el('input', { type: 'text', value: r.beleg_pfad || '', placeholder: 'wird beim Hochladen gesetzt' });
    const fDatei = el('input', { type: 'file', accept: 'image/*,application/pdf' });
    const up = el('button', { type: 'button', class: 'small' }, 'Hochladen');
    up.addEventListener('click', async () => {
      const f = fDatei.files && fDatei.files[0];
      if (!f) return toast('Bitte zuerst eine Datei wählen.', true);
      up.disabled = true;
      try {
        const res = await call(api.nc.belegUpload('', { name: f.name, buffer: new Uint8Array(await f.arrayBuffer()) },
          { jahr: String(fDatum.value).slice(0, 4), ref: fBeleg.value || `Buchung-${r.id}` }));
        fPfad.value = (res && res.path) || fPfad.value;
        toast('Beleg hochgeladen – bitte noch speichern.');
      } catch (e) { toast(e.message, true); } finally { up.disabled = false; }
    });
    const zeile = el('div', { class: 'row' }, fDatei, up);
    if (r.beleg_pfad) zeile.append(el('button', { type: 'button', class: 'small ghost', onclick: () => openBeleg(r.beleg_pfad) }, '📎 öffnen'));
    feld('Beleg-Datei (Nextcloud)', zeile);
    feld('Beleg-Pfad', fPfad);
  }

  /** Eingaben → Zeilenspalten, oder Fehlermeldung. */
  function zeile() {
    const betrag = Math.abs(parseNum(fBetrag.value));
    if (!betrag) return { fehler: 'Betrag fehlt.' };
    if (methode === 'doppik') {
      if (!fSoll.value || !fHaben.value) return { fehler: 'Soll- und Haben-Konto wählen.' };
      if (fSoll.value === fHaben.value) return { fehler: 'Soll und Haben müssen verschiedene Konten sein.' };
      return BUCH.zeileAusSatz(fSoll.value, fHaben.value, betrag, d.info);
    }
    if (!fGeld.value) return { fehler: 'Geldkonto wählen.' };
    if (fArt.value === 'umbuchung') {
      if (!fNach.value || fNach.value === fGeld.value) return { fehler: 'Bei einer Umbuchung zwei verschiedene Konten wählen.' };
      return BUCH.zeileAusEuer({ art: 'umbuchung', von: fGeld.value, nach: fNach.value, betrag });
    }
    return BUCH.zeileAusEuer({ art: fArt.value, geldkonto: fGeld.value, konto: fKonto.value, betrag });
  }
  if (fArt) {
    // Einnahme: Einnahmekonten zuerst anbieten, Ausgabe: Ausgabekonten.
    fArt.addEventListener('change', () => {
      if (fArt.value === 'umbuchung') return;
      const wert = fKonto.value;
      const neuSel = kontoSelect(d.konten, wert, fArt.value, '– noch nicht zugeordnet –');
      fKonto.replaceChildren(...neuSel.childNodes);
      fKonto.value = wert;
    });
  }
  function aktualisieren() {
    const z = zeile();
    if (z.fehler) { vorschau.textContent = ''; return; }
    const satz = BUCH.satz(z);
    const umb = !BUCH.istErfolg(z.geldkonto, d.info) && !BUCH.istErfolg(z.konto, d.info);
    const k = d.info.get(String(z.konto));
    if (!sphAngefasst) fSph.value = umb ? 'neutral' : (k && k.sphaere) || '';
    const art = umb ? 'Umbuchung' : BUCH.euerSicht(z, d.info) ? (z.betrag < 0 ? 'Ausgabe' : 'Einnahme') : 'Buchungssatz';
    vorschau.textContent = `${art}: ${eur(satz.betrag)} · Buchungssatz ${kLabel(d, satz.soll)} an ${kLabel(d, satz.haben)}`
      + (istInterim(satz.soll) || istInterim(satz.haben) ? ' · ohne SKR-Konto (landet vorläufig auf 1590)' : '');
  }
  for (const n of [fArt, fGeld, fKonto, fNach, fSoll, fHaben].filter(Boolean)) n.addEventListener('change', aktualisieren);
  fBetrag.addEventListener('input', aktualisieren);
  aktualisieren();

  const actions = el('div', { class: 'form-actions' });
  actions.append(el('button', { class: 'primary', type: 'submit' }, neu ? 'Buchen' : 'Speichern'));
  form.append(actions);
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!d.umgestellt) return toast('Erst das Plugin auf v0.33 aktualisieren und synchronisieren.', true);
    const z = zeile();
    if (z.fehler) return toast(z.fehler, true);
    const umb = !BUCH.istErfolg(z.geldkonto, d.info) && !BUCH.istErfolg(z.konto, d.info);
    const felder = {
      buchung_datum: fDatum.value, geldkonto: z.geldkonto, konto: z.konto, betrag: String(z.betrag), gegenkonto: '',
      sphaere: fSph.value, gegenpartei: fGp.value, beschreibung: fText.value, beleg_nr: fBeleg.value,
      kategorie: umb ? 'Umbuchung' : (z.konto ? kLabel(d, z.konto) : 'Sonstige'),
      budget_id: fBudget ? fBudget.value : undefined, kostenstelle: fKs ? fKs.value : undefined,
      ruecklage_id: fRl ? fRl.value : undefined, beleg_pfad: fPfad ? fPfad.value : undefined,
    };
    for (const k of Object.keys(felder)) if (felder[k] === undefined || !d.cols.has(k)) delete felder[k];
    try {
      if (neu) {
        await bhAktion('journal-add', { ...felder, quelle: 'Manuell' }, 'Gebucht.');
      } else {
        if (felder.beleg_nr) felder.beleg_referenz = felder.beleg_nr;
        await call(api.data.save('jb_buchungen', r.id, felder));
        const vorher = (state.stats && state.stats.conflicts) || 0;
        await runSyncQuiet();
        const nachher = (state.stats && state.stats.conflicts) || 0;
        if (nachher > vorher) return toast('Nicht gespeichert: Die Buchung wurde zwischenzeitlich auf dem Server geändert. Bitte unter „Konflikte" entscheiden.', true);
        toast('Gespeichert.');
      }
      nachSpeichern();
    } catch (e) { toast(e.message, true); }
  });
  card.append(form);
  return card;
}

/** Vorgabe-Geldkonto für neue Buchungen: das Bankkonto (erstes Geldkonto). */
function bhVorgabe(d) {
  const geld = d.konten.filter((k) => k.typ === 'geld').sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }));
  const bank = geld.find((k) => /bank/i.test(k.bezeichnung));
  return String((bank || geld[0] || {}).nummer || '');
}

async function showBuchung(pk, from) {
  state.current = { name: 'buchung', pk, from };
  renderNav();
  let d;
  let data;
  try {
    [d, data] = await Promise.all([bhLaden(), call(api.data.row('jb_buchungen', pk))]);
  } catch (e) {
    view.innerHTML = '';
    return view.append(el('div', { class: 'note err' }, e.message));
  }
  const row = (data && data.row) || null;
  const zurueck = () => (from && from.name === 'kontenblatt' ? showKontenblatt(from.konto, from.jahr) : showJournal());
  view.innerHTML = '';
  view.append(el('button', { class: 'ghost small', onclick: zurueck }, '‹ Zurück'));
  view.append(el('h1', {}, 'Buchung bearbeiten'));
  if (!row) return view.append(el('div', { class: 'note err' }, 'Buchung nicht gefunden – evtl. gelöscht. Bitte synchronisieren.'));
  const jahr = Number(String(row.buchung_datum || '').slice(0, 4)) || BH.jahr;
  const m = BUCH.methodeFuer(d.gj, jahr).methode;
  view.append(el('p', { class: 'sub' }, `Geschäftsjahr ${jahr} · ${METHODEN[m]}`));

  try {
    const offen = (await call(api.conflicts.list())) || [];
    if (offen.some((k) => k.tbl === 'jb_buchungen' && String(k.pk) === String(pk))) {
      view.append(el('div', { class: 'note err' }, 'Diese Buchung hat einen offenen Konflikt (hier und auf dem Server geändert). Solange er offen ist, wird nichts gespeichert. ',
        el('button', { class: 'small', onclick: showConflicts }, 'Konflikt entscheiden')));
    }
  } catch (e) {
    view.append(el('div', { class: 'note' }, 'Konfliktliste nicht lesbar: ' + e.message));
  }
  if (!d.umgestellt) view.append(el('div', { class: 'note err' }, 'Bearbeiten erst nach dem Plugin-Update auf v0.33 möglich.'));

  view.append(buchungFormular(d, m, row, zurueck));
  const unten = el('div', { class: 'form-actions' });
  unten.append(el('button', { class: 'danger', onclick: async () => {
    if (!confirm('Diese Buchung löschen? Wird beim nächsten Sync auch auf dem Server gelöscht.')) return;
    try {
      await call(api.data.remove('jb_buchungen', pk));
      await runSyncQuiet();
      toast('Gelöscht.');
      zurueck();
    } catch (e) { toast(e.message, true); }
  } }, 'Löschen'));
  if (data.state && data.state.dirty) unten.append(el('span', { class: 'tag' }, 'lokal geändert, noch nicht gesendet'));
  view.append(unten);
  view.append(el('p', { class: 'muted' }, `ID ${row.id} · angelegt ${row.erstellt_am || '—'} · Herkunft ${row.quelle || '—'} · alle Spalten unter Admin · Rohdaten → Buchungsjournal.`));
}

function splitForm(d, row) {
  const card = el('div', { class: 'card' });
  if (!row) return card.append(el('div', { class: 'note err' }, 'Buchung nicht gefunden.')), card;
  const ziel = Number(row.betrag) || 0;
  card.append(el('h2', {}, `Buchung aufteilen (${eur(Math.abs(ziel))})`));
  card.append(el('p', { class: 'muted' }, 'Z. B. eine Bankabbuchung, die Getränke und Bankgebühr enthält. Alle Teile bleiben auf demselben Geldkonto; jeder bekommt sein eigenes SKR-Konto. Beträge positiv eingeben.'));
  const teile = [];
  const host = el('div', {});
  const rest = el('div', { class: 'muted', style: 'margin:6px 0' });
  const add = (betrag, konto, text) => {
    const t = { betrag: moneyInput(betrag), konto: kontoSelect(d.konten, konto, 'erfolg', '– noch nicht zugeordnet –'), text: el('input', { type: 'text', value: text }) };
    teile.push(t);
    t.betrag.addEventListener('input', rechnen);
    host.append(el('div', { class: 'row', style: 'gap:8px;margin:4px 0;flex-wrap:wrap' }, t.betrag, t.konto, t.text));
  };
  function rechnen() {
    const sum = teile.reduce((s, t) => s + Math.abs(parseNum(t.betrag.value)), 0);
    const r = Math.round((Math.abs(ziel) - sum) * 100) / 100;
    rest.textContent = `Summe der Teile ${eur(sum)} · Rest ${eur(r)}${Math.abs(r) < 0.005 ? ' ✓' : ''}`;
    rest.style.color = Math.abs(r) < 0.005 ? 'var(--accent)' : 'var(--err-ink)';
  }
  const s = BUCH.euerSicht(row, d.info);
  add(Math.abs(ziel), s && s.konto && !istInterim(s.konto) ? s.konto : '', row.beschreibung || '');
  add(0, '5190', 'Bankgebühr');
  rechnen();
  card.append(host, el('button', { type: 'button', class: 'small', onclick: () => { add(0, '', ''); rechnen(); } }, '+ Teil'), rest);
  card.append(el('button', { class: 'primary', style: 'display:block;margin-top:8px', onclick: async () => {
    const vz = ziel < 0 ? -1 : 1;
    const liste = teile.map((t) => ({ betrag: vz * Math.abs(parseNum(t.betrag.value)), konto: t.konto.value, beschreibung: t.text.value })).filter((t) => t.betrag);
    if (liste.length < 2) return toast('Mindestens zwei Teile mit Betrag.', true);
    try {
      await bhAktion('split-buchung', { id: row.id, teile: liste }, (r) => `Aufgeteilt in ${(r.created || []).length} Buchungen.`);
      BH.sel.clear();
      showJournal();
    } catch (e) { toast(e.message, true); }
  } }, 'Aufteilen'));
  return card;
}

function zuUmbuchungForm(d, ids) {
  const card = el('div', { class: 'card' });
  const sel = ids.map((id) => d.buchungen.find((r) => String(r.id) === String(id))).filter(Boolean);
  card.append(el('h2', {}, 'Zu Umbuchung machen'));
  card.append(el('p', { class: 'muted' }, 'Für Geld, das nur zwischen euren eigenen Konten wandert (Bareinzahlung, Wechselgeld, PayPal-Auszahlung). Umbuchungen zählen nicht als Einnahme oder Ausgabe.'));
  if (sel.length === 2) {
    const summe = Math.round(sel.reduce((x, r) => x + (Number(r.betrag) || 0), 0) * 100) / 100;
    card.append(el('p', {}, 'Die beiden Buchungen (Abgang auf dem einen, Zugang auf dem anderen Konto) werden zu einer Umbuchung zusammengefasst.'));
    for (const r of sel) card.append(el('div', {}, `${r.buchung_datum} · ${kLabel(d, r.geldkonto)} · ${eur(Number(r.betrag) || 0)} · ${r.beschreibung || ''}`));
    const ok = Math.abs(summe) < 0.005;
    if (!ok) card.append(el('div', { class: 'note err' }, `Summe ${eur(summe)} – muss 0 ergeben.`));
    const b = el('button', { class: 'primary', style: 'margin-top:8px', onclick: async () => {
      try { await bhAktion('zu-umbuchung', { ids }, 'Zu einer Umbuchung zusammengefasst.'); BH.sel.clear(); showJournal(); } catch (e) { toast(e.message, true); }
    } }, 'Zusammenfassen');
    b.disabled = !ok;
    card.append(b);
    return card;
  }
  const r = sel[0];
  if (!r) return card;
  const v = BUCH.euerSicht(r, d.info);
  card.append(el('p', {}, `${r.buchung_datum} · ${eur(Math.abs(Number(r.betrag) || 0))} ${Number(r.betrag) < 0 ? 'von' : 'auf'} ${kLabel(d, v ? v.geldkonto || v.von : '')} · ${r.beschreibung || ''}`));
  const gk = kontoSelect(d.konten, '', 'geld', '—');
  const f = el('form', { class: 'detail' });
  f.append(labelMitHilfe(Number(r.betrag) < 0 ? 'Das Geld ging an Konto' : 'Das Geld kam von Konto', 'Das zweite eigene Konto, z. B. Barkasse bei einer Bareinzahlung.'), gk);
  f.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Umbuchung setzen')));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!gk.value) return toast('Zweites Konto wählen.', true);
    try { await bhAktion('zu-umbuchung', { ids, gegen_konto: gk.value }, 'Umbuchung gesetzt.'); BH.sel.clear(); showJournal(); } catch (err) { toast(err.message, true); }
  });
  card.append(f);
  return card;
}

function bankCsvForm(d) {
  const card = el('div', { class: 'card' });
  card.append(el('h2', {}, 'Bank-CSV importieren'));
  card.append(el('p', { class: 'muted' }, 'CSV aus dem Online-Banking (Sparkasse: Umsätze → CSV-CAMT) einfügen. SKR-Konten werden per Stichwort-Regel vorgeschlagen und lassen sich vor dem Import ändern. Umbuchungen (z. B. Bareinzahlung) im Journal danach mit „Zu Umbuchung" korrigieren.'));
  const ta = el('textarea', { placeholder: 'CSV-Inhalt hier einfügen', style: 'min-height:120px' });
  const delim = selectEl([[';', '; (Sparkasse)'], [',', ',']], ';');
  const geld = kontoSelect(d.konten, bhVorgabe(d), 'geld', null);
  const out = el('div', {});
  const vor = el('button', { class: 'small', type: 'button' }, 'Vorschau');
  card.append(el('form', { class: 'detail' }, 'CSV', ta, 'Trenner', delim, labelMitHilfe('Umsätze gehören zu', 'Das Geldkonto, von dem der Kontoauszug stammt.'), geld), el('div', { class: 'form-actions' }, vor), out);
  let zeilen = [];
  vor.addEventListener('click', async () => {
    out.innerHTML = '';
    try {
      zeilen = ((await call(api.action.run('bank-csv', { csv: ta.value, delim: delim.value }))).rows) || [];
      if (!zeilen.length) return out.append(el('div', { class: 'note warn' }, 'Keine verwertbaren Zeilen erkannt.'));
      const t = el('table');
      t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Betrag'), el('th', {}, 'Name / Zweck'), el('th', {}, 'SKR-Konto'))));
      const tb = el('tbody');
      zeilen.forEach((z, i) => {
        tb.append(el('tr', {}, el('td', {}, z.datum),
          el('td', { style: 'text-align:right;color:' + (z.betrag < 0 ? 'var(--err-ink)' : 'var(--accent)') }, eur(z.betrag)),
          el('td', {}, `${z.name || ''} — ${z.zweck || ''}`),
          el('td', {}, kontoSelect(d.konten, z.konto || '', 'alle', '– noch nicht zugeordnet –', { onchange: (e) => { zeilen[i].konto = e.target.value; } }))));
      });
      t.append(tb);
      out.append(t);
      const imp = el('button', { class: 'primary', type: 'button' }, `${zeilen.length} Buchung(en) importieren`);
      imp.addEventListener('click', async () => {
        imp.disabled = true;
        try {
          await bhAktion('bank-csv', { import: true, rows: zeilen.map((z) => ({ ...z, geldkonto: geld.value })) }, (r) => `${r.imported} Buchung(en) importiert.`);
          showJournal();
        } catch (e) { toast(e.message, true); imp.disabled = false; }
      });
      out.append(el('div', { class: 'form-actions' }, imp));
    } catch (e) { out.append(el('div', { class: 'note err' }, e.message)); }
  });
  return card;
}

/* -------------------------------------------------------------- Auswertung */

async function showAuswertung() {
  state.current = { name: 'auswertung' };
  renderNav();
  let d;
  try { d = await bhLaden(); } catch (e) { view.innerHTML = ''; return view.append(el('div', { class: 'note err' }, e.message)); }
  const methode = bhKopf(d, 'Auswertung', 'Offline aus den lokalen Daten gerechnet. Konto anklicken zeigt jede Bewegung.', showAuswertung);
  const jd = BUCH.jahresDaten(d.buchungen, d.best, BH.jahr);
  const e = BUCH.euer(jd, d.info);
  const geld = BUCH.geldkontenStand(jd, d.info);
  const euerM = methode === 'euer';
  const R = { style: 'text-align:right' };
  const link = (nr) => el('a', { href: '#', onclick: (ev) => { ev.preventDefault(); showKontenblatt(nr, BH.jahr); } }, kLabel(d, nr));

  view.append(euerM
    ? hilfe('Wie liest man die EÜR?', [
      'Einnahmen − Ausgaben = Überschuss. Gezählt wird, was im Jahr tatsächlich eingenommen und ausgegeben wurde. Geld, das nur zwischen euren Konten wandert (Bareinzahlung, Wechselgeld, PayPal-Auszahlung), ist weder Einnahme noch Ausgabe.',
      'Geldkonten: Stand 1.1. + Zugänge − Abgänge = Stand 31.12. Steht ein Konto im Minus, fehlt meist eine Buchung (z. B. nicht gebuchte PayPal-/Zettle-Gebühren) oder eine Umbuchung ist falsch herum.',
      'Sphären trennen die Bereiche, die das Finanzamt bei gemeinnützigen Vereinen unterscheidet.',
    ])
    : hilfe('Wie liest man die Doppik-Auswertung?', [
      'Summen- und Saldenliste: jedes Konto mit Stand 1.1., allen Soll- und Haben-Beträgen des Jahres und dem Saldo (Soll − Haben). Ertragskonten haben einen negativen Saldo – das ist normal.',
      'Gewinn- und Verlustrechnung: Erträge − Aufwendungen = Jahresergebnis. Vermögensübersicht: was am Jahresende da ist (Geld, Forderungen) und was ihr schuldet (Verbindlichkeiten).',
    ]));

  const tiles = el('div', { class: 'tiles' });
  const tile = (l, w, cls) => tiles.append(el('div', { class: 'tile ' + (cls || '') }, el('div', { class: 'tile-v' }, eur(w)), el('div', { class: 'tile-l' }, l)));
  tile(euerM ? 'Einnahmen' : 'Erträge', e.einnahmen, 'pos');
  tile(euerM ? 'Ausgaben' : 'Aufwendungen', e.ausgaben, 'neg');
  tile(euerM ? 'Überschuss' : 'Jahresergebnis', e.ueberschuss, e.ueberschuss >= 0 ? 'pos' : 'neg');
  view.append(tiles);

  if (e.ohneKonto.anzahl) {
    view.append(el('div', { class: 'note warn' }, `${e.ohneKonto.anzahl} Buchung(en) haben noch kein SKR-Konto (Einnahmen ${eur(e.ohneKonto.einnahmen)}, Ausgaben ${eur(e.ohneKonto.ausgaben)}). `,
      el('button', { class: 'small', onclick: () => showKontenblatt(BUCH.INTERIM, BH.jahr) }, 'anzeigen und zuordnen')));
  }

  // Gegenprobe mit dem Server – weicht etwas ab, rechnen App und Plugin verschieden.
  call(api.report.salden(BH.jahr)).then((srv) => {
    if (!srv || !srv.euer) return;
    const diffs = [];
    if (Math.abs(srv.euer.einnahmen - e.einnahmen) > 0.005) diffs.push(`Einnahmen Server ${eur(srv.euer.einnahmen)}`);
    if (Math.abs(srv.euer.ausgaben - e.ausgaben) > 0.005) diffs.push(`Ausgaben Server ${eur(srv.euer.ausgaben)}`);
    for (const g of srv.geldkonten || []) {
      const l = geld.find((x) => x.konto === g.konto);
      if (Math.abs((l ? l.ende : 0) - g.ende) > 0.005) diffs.push(`${g.konto} Server ${eur(g.ende)}`);
    }
    if (diffs.length) view.insertBefore(el('div', { class: 'note' }, 'Server rechnet anders als die App – vermutlich fehlt ein Sync: ' + diffs.join(' · ')), tiles);
  }).catch((err) => {
    view.insertBefore(el('div', { class: 'note' }, 'Gegenprobe mit dem Server nicht möglich (' + err.message + ') – Zahlen aus den lokalen Daten.'), tiles.nextSibling);
  });

  const geldTabelle = () => {
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Geldkonto'), el('th', R, 'Stand 1.1.'), el('th', R, '+ Zugänge'), el('th', R, '− Abgänge'), el('th', R, '= Stand 31.12.'))));
    const tb = el('tbody');
    const sum = [0, 0, 0, 0];
    for (const g of geld) {
      [g.anfang, g.zugang, g.abgang, g.ende].forEach((x, i) => { sum[i] += x; });
      tb.append(el('tr', {}, el('td', {}, link(g.konto)), el('td', R, eur(g.anfang)), el('td', R, eur(g.zugang)), el('td', R, eur(g.abgang)),
        el('td', { style: 'text-align:right;font-weight:600' + (g.ende < 0 ? ';color:var(--err-ink)' : '') }, eur(g.ende))));
    }
    t.append(tb, el('tfoot', {}, el('tr', { style: 'font-weight:700' }, el('td', {}, 'Gesamt'), ...sum.map((x) => el('td', R, eur(x))))));
    return geld.length ? t : el('div', { class: 'note warn' }, 'Im Kontenplan ist noch kein Konto als „Geldkonto" gekennzeichnet.');
  };
  const sphTabelle = () => {
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Sphäre'), el('th', R, euerM ? 'Einnahmen' : 'Erträge'), el('th', R, euerM ? 'Ausgaben' : 'Aufwendungen'), el('th', R, 'Ergebnis'))));
    const tb = el('tbody');
    for (const p of e.proSphaere) tb.append(el('tr', {}, el('td', {}, SPHAERE_LABEL[p.sphaere] || (p.sphaere === '—' ? 'ohne Sphäre' : p.sphaere)), el('td', R, eur(p.einnahmen)), el('td', R, eur(p.ausgaben)), el('td', R, eur(p.saldo))));
    t.append(tb);
    return t;
  };
  const kontoTabelle = (typ, titel) => {
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, titel), el('th', R, 'Betrag'), el('th', R, 'Buchungen'))));
    const tb = el('tbody');
    let sum = 0;
    for (const k of e.proKonto.filter((x) => x.typ === typ)) {
      const w = typ === 'einnahme' ? k.einnahmen : k.ausgaben;
      sum += w;
      tb.append(el('tr', {}, el('td', {}, link(k.konto)), el('td', R, eur(w)), el('td', R, String(k.anzahl))));
    }
    t.append(tb, el('tfoot', {}, el('tr', { style: 'font-weight:700' }, el('td', {}, 'Summe'), el('td', R, eur(sum)), el('td', {}))));
    return t;
  };

  if (euerM) {
    view.append(el('h2', {}, 'Geldkonten'), geldTabelle());
    view.append(el('h2', {}, 'Nach Sphäre'), sphTabelle());
    view.append(el('h2', {}, 'Nach SKR-Konto'), kontoTabelle('einnahme', 'Einnahmen'), kontoTabelle('ausgabe', 'Ausgaben'));
  } else {
    view.append(el('h2', {}, 'Summen- und Saldenliste'));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Konto'), el('th', R, 'Stand 1.1.'), el('th', R, 'Soll'), el('th', R, 'Haben'), el('th', R, 'Saldo'))));
    const tb = el('tbody');
    const gruppe = (s) => (s.typ === 'geld' ? 0 : ['einnahme', 'ausgabe'].includes(s.typ) ? (s.typ === 'einnahme' ? 2 : 3) : 1);
    const titel = ['Geldkonten', 'Weitere Bestandskonten', 'Erträge', 'Aufwendungen'];
    let letzte = -1;
    for (const s of BUCH.saldenliste(jd, d.info).sort((a, b) => gruppe(a) - gruppe(b) || a.konto.localeCompare(b.konto, 'de', { numeric: true }))) {
      if (gruppe(s) !== letzte) { letzte = gruppe(s); tb.append(el('tr', {}, el('th', { colspan: '5' }, titel[letzte]))); }
      tb.append(el('tr', {}, el('td', {}, link(s.konto)), el('td', R, eur(s.anfang)), el('td', R, s.soll ? eur(s.soll) : '—'), el('td', R, s.haben ? eur(s.haben) : '—'), el('td', { style: 'text-align:right;font-weight:600' }, eur(s.saldo))));
    }
    t.append(tb);
    view.append(t);
    view.append(el('h2', {}, 'Gewinn- und Verlustrechnung'), sphTabelle(), kontoTabelle('einnahme', 'Erträge'), kontoTabelle('ausgabe', 'Aufwendungen'));
    view.append(el('h2', {}, 'Vermögensübersicht am 31.12.'), geldTabelle());
    const sonst = BUCH.saldenliste(jd, d.info).filter((s) => !BUCH.istErfolg(s.konto, d.info) && s.typ !== 'geld' && Math.abs(s.saldo) > 0.004);
    if (sonst.length) {
      const t2 = el('table');
      t2.append(el('thead', {}, el('tr', {}, el('th', {}, 'Weitere Bestandskonten'), el('th', R, 'Saldo 31.12.'))));
      t2.append(el('tbody', {}, ...sonst.map((s) => el('tr', {}, el('td', {}, link(s.konto)), el('td', R, eur(s.saldo))))));
      view.append(t2);
    }
  }
}

async function showKontenblatt(konto, jahr) {
  if (jahr) BH.jahr = Number(jahr);
  state.current = { name: 'kontenblatt', konto, jahr: BH.jahr };
  renderNav();
  let d;
  try { d = await bhLaden(); } catch (e) { view.innerHTML = ''; return view.append(el('div', { class: 'note err' }, e.message)); }
  const methode = bhKopf(d, `${BUCH.istGeld(konto, d.info) ? 'Kontoauszug' : 'Kontenblatt'} ${kLabel(d, konto)}`, null, () => showKontenblatt(konto));
  view.insertBefore(el('button', { class: 'ghost small', onclick: showAuswertung }, '‹ Auswertung'), view.firstChild);
  const kb = BUCH.kontenblatt(d.buchungen, d.best, konto, BH.jahr);
  const euerM = methode === 'euer';
  const R = { style: 'text-align:right' };

  if (kb.zeilen.length > 1) {
    const pro = new Map();
    for (const z of kb.zeilen) {
      const p = pro.get(z.gegen) || { soll: 0, haben: 0, n: 0 };
      p.soll += z.soll; p.haben += z.haben; p.n++;
      pro.set(z.gegen, p);
    }
    const box = el('details', { class: 'card' });
    box.append(el('summary', {}, 'Woraus setzt sich der Stand zusammen? (nach Gegenkonto)'));
    const t = el('table');
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Gegenkonto'), el('th', R, euerM ? 'Zugang' : 'Soll'), el('th', R, euerM ? 'Abgang' : 'Haben'), el('th', R, 'Wirkung'), el('th', R, 'Anzahl'))));
    const tb = el('tbody');
    for (const [g, p] of [...pro.entries()].sort((a, b) => Math.abs(b[1].soll - b[1].haben) - Math.abs(a[1].soll - a[1].haben))) {
      const w = Math.round((p.soll - p.haben) * 100) / 100;
      tb.append(el('tr', { style: 'cursor:pointer', onclick: () => showKontenblatt(g) }, el('td', {}, kLabel(d, g)), el('td', R, p.soll ? eur(p.soll) : '—'),
        el('td', R, p.haben ? eur(p.haben) : '—'), el('td', { style: 'text-align:right;font-weight:600' + (w < 0 ? ';color:var(--err-ink)' : '') }, eur(w)), el('td', R, String(p.n))));
    }
    t.append(tb);
    box.append(t);
    view.append(box);
  }

  const t = el('table');
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Datum'), el('th', {}, 'Beleg'), el('th', {}, 'Text'), el('th', {}, 'Gegenkonto'),
    el('th', R, euerM ? 'Zugang' : 'Soll'), el('th', R, euerM ? 'Abgang' : 'Haben'), el('th', R, 'Stand'))));
  const tb = el('tbody');
  tb.append(el('tr', { class: 'muted' }, el('td', { colspan: '6' }, `Stand am 1.1.${BH.jahr}`), el('td', R, eur(kb.anfang))));
  for (const z of kb.zeilen) {
    tb.append(el('tr', { style: 'cursor:pointer', onclick: () => showBuchung(z.id, { name: 'kontenblatt', konto, jahr: BH.jahr }) },
      el('td', {}, z.datum), el('td', {}, z.beleg), el('td', {}, z.text || '—'), el('td', {}, kLabel(d, z.gegen)),
      el('td', R, z.soll ? eur(z.soll) : ''), el('td', R, z.haben ? eur(z.haben) : ''),
      el('td', { style: 'text-align:right' + (z.saldo < 0 ? ';color:var(--err-ink)' : '') }, eur(z.saldo))));
  }
  t.append(tb, el('tfoot', {}, el('tr', { style: 'font-weight:700' }, el('td', { colspan: '6' }, 'Stand am Jahresende'), el('td', R, eur(kb.ende)))));
  view.append(t);
  if (BUCH.typVon(konto, d.info) === 'einnahme') view.append(el('p', { class: 'muted' }, 'Einnahmekonten stehen im Minus (Haben-Saldo) – das ist in der Buchhaltung so üblich und kein Fehler.'));
  if (istInterim(konto)) view.append(el('p', { class: 'muted' }, 'Hier liegen Buchungen ohne SKR-Konto. Zeile anklicken und das passende Konto wählen.'));
}

/* ------------------------------------------------------------ Geschäftsjahr */

async function showGeschaeftsjahr() {
  state.current = { name: 'geschaeftsjahr' };
  renderNav();
  let d;
  try { d = await bhLaden(); } catch (e) { view.innerHTML = ''; return view.append(el('div', { class: 'note err' }, e.message)); }
  bhKopf(d, 'Geschäftsjahr', 'Buchführungsart, Anfangsbestände, Jahresabschluss und Geldkonten.', showGeschaeftsjahr, { folgejahr: true });
  const jahr = BH.jahr;
  const m = BUCH.methodeFuer(d.gj, jahr);

  /* 1. Buchführungsart */
  view.append(el('h2', {}, `1. Buchführungsart ${jahr}`));
  view.append(el('p', { class: 'muted' },
    'Legt zu Beginn jedes Geschäftsjahres fest, wie ihr bucht, und bleibt dann dabei – Steuerbüro und Kassenprüfung brauchen für ein Jahr eine einheitliche Methode. '
    + 'Die Wahl ändert nur, wie Buchungen eingegeben und ausgewertet werden. Die gespeicherten Buchungen bleiben dieselben, beim Wechsel geht nichts verloren.'));
  const karten = el('div', { class: 'grid-cards', style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px' });
  const karte = (key, titel, zeilen) => {
    const aktiv = m.methode === key;
    const c = el('div', { class: 'card', style: aktiv ? 'border:2px solid var(--accent)' : '' });
    c.append(el('h3', { style: 'margin-top:0' }, titel, aktiv ? el('span', { style: 'color:var(--accent);font-size:13px' }, m.gesetzt ? '  ✓ aktiv' : '  ✓ gilt (Standard)') : null));
    for (const [k, t] of zeilen) c.append(el('p', { style: 'margin:6px 0' }, el('strong', {}, k + ' '), t));
    if (d.umgestellt && (!aktiv || !m.gesetzt)) {
      c.append(el('button', { class: aktiv ? 'primary' : '', onclick: async () => {
        if (!aktiv && !confirm(`${titel} für ${jahr} verwenden? Die Buchungen bleiben unverändert.`)) return;
        try { await bhAktion('geschaeftsjahr', { jahr, methode: key }, `${jahr}: ${titel}`); showGeschaeftsjahr(); } catch (e) { toast(e.message, true); }
      } }, aktiv ? `Für ${jahr} bestätigen` : `Für ${jahr} verwenden`));
    }
    karten.append(c);
  };
  karte('euer', METHODEN.euer, [
    ['Prinzip:', 'Ihr zählt, was an Geld tatsächlich rein- und rausgeht. Am Jahresende: Einnahmen − Ausgaben = Überschuss.'],
    ['Ihr erfasst:', 'Einnahme / Ausgabe / Umbuchung, Betrag, Geldkonto (Bank, Kasse, PayPal) und wofür (SKR-Konto).'],
    ['Ihr bekommt:', 'EÜR nach Sphären und Konten, Kontostände, Kontoauszüge.'],
    ['Passt für:', 'die meisten Vereine. Pflicht zur Doppik entsteht erst bei großen wirtschaftlichen Geschäftsbetrieben (derzeit über 800.000 € Umsatz oder 80.000 € Gewinn, § 141 AO) oder wenn Satzung/Steuerbüro es verlangen.'],
  ]);
  karte('doppik', METHODEN.doppik, [
    ['Prinzip:', 'Jede Buchung ist ein Buchungssatz „Soll an Haben": ein Konto bekommt den Betrag, ein anderes gibt ihn ab. Auch Vorgänge ohne Geldfluss (offene Rechnungen, Verbindlichkeiten) lassen sich buchen.'],
    ['Ihr erfasst:', 'Soll-Konto, Haben-Konto, Betrag – z. B. 1200 Bank an 4100 Mitgliedsbeiträge.'],
    ['Ihr bekommt:', 'Summen- und Saldenliste, Kontenblätter, Gewinn- und Verlustrechnung, Vermögensübersicht.'],
    ['Passt für:', 'Vereine mit Buchführungspflicht oder wenn ihr Forderungen und Verbindlichkeiten sauber abbilden wollt.'],
  ]);
  view.append(karten);
  const nicht = BUCH.nichtEuerKonform(d.buchungen, jahr, d.info);
  if (nicht.length) {
    view.append(el('div', { class: 'note' }, `${nicht.length} Buchung(en) in ${jahr} sind reine Buchungssätze ohne Geldkonto – ein Wechsel zur EÜR geht erst, wenn sie geändert sind: `,
      ...nicht.slice(0, 10).map((r) => el('button', { class: 'small ghost', onclick: () => showBuchung(r.id) }, `#${r.id}`))));
  }

  /* 2. Anfangsbestände */
  const vorjahr = BUCH.jahresDaten(d.buchungen, d.best, jahr - 1);
  const eintraege = d.best.filter((r) => Number(r.jahr) === jahr);
  const kontenNr = [...new Set([...d.konten.filter((k) => k.typ === 'geld').map((k) => String(k.nummer)), ...eintraege.map((r) => String(r.konto))])]
    .sort((a, b) => a.localeCompare(b, 'de', { numeric: true }));
  view.append(el('h2', {}, `2. Anfangsbestände am 1.1.${jahr}`));
  view.append(el('p', { class: 'muted' }, 'Der tatsächliche Stand jedes Geldkontos am 1. Januar (Kontoauszug, gezählte Kasse). Alle Auswertungen dieses und der folgenden Jahre rechnen davon aus. Ohne Eintrag wird aus den Vorjahren weitergerechnet.'));
  const t = el('table');
  const R = { style: 'text-align:right' };
  t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Konto'), el('th', R, 'Anfangsbestand'), el('th', R, `Berechneter Endbestand ${jahr - 1}`))));
  const tb = el('tbody');
  const inputs = new Map();
  for (const nr of kontenNr) {
    const e = eintraege.find((r) => String(r.konto) === nr);
    const inp = moneyInput(e ? e.betrag : '', { placeholder: '—', style: 'width:130px;text-align:right' });
    inputs.set(nr, { inp, e });
    const k = vorjahr.konten[nr];
    const ende = k ? k.ende / 100 : null;
    const abw = ende != null && e && Math.abs(ende - Number(e.betrag)) > 0.004;
    const uebernehmen = ende != null ? el('button', { type: 'button', class: 'small ghost', onclick: () => { inp.value = fmtNum(ende); } }, 'übernehmen') : null;
    tb.append(el('tr', {}, el('td', {}, kLabel(d, nr)), el('td', R, inp),
      el('td', { style: 'text-align:right' + (abw ? ';color:var(--err-ink)' : '') }, ende == null ? '—' : `${eur(ende)}${abw ? ' ⚠' : ''} `, uebernehmen)));
  }
  const neuKonto = kontoSelect(d.konten, '', 'geld', '+ weiteres Bestandskonto …');
  const neuBetrag = moneyInput('', { placeholder: '0,00', style: 'width:130px;text-align:right' });
  tb.append(el('tr', {}, el('td', {}, neuKonto), el('td', R, neuBetrag), el('td', {})));
  t.append(tb);
  view.append(t);
  if (d.umgestellt) {
    view.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', onclick: async () => {
      try {
        for (const [nr, { inp, e }] of inputs) {
          const roh = inp.value.trim();
          if (!roh && e) await call(api.data.remove('jb_anfangsbestaende', e.id));
          else if (roh && e && Math.abs(parseNum(roh) - Number(e.betrag)) > 0.004) await call(api.data.save('jb_anfangsbestaende', e.id, { betrag: String(parseNum(roh)) }));
          else if (roh && !e) await call(api.data.create('jb_anfangsbestaende', { jahr: String(jahr), konto: nr, betrag: String(parseNum(roh)) }));
        }
        if (neuKonto.value && neuBetrag.value.trim()) {
          await call(api.data.create('jb_anfangsbestaende', { jahr: String(jahr), konto: neuKonto.value, betrag: String(parseNum(neuBetrag.value)) }));
        }
        await runSyncQuiet();
        toast(`Anfangsbestände ${jahr} gespeichert.`);
        showGeschaeftsjahr();
      } catch (e) { toast(e.message, true); }
    } }, 'Anfangsbestände speichern'), el('span', { class: 'muted' }, 'Leeres Feld = kein Anfangsbestand. ⚠ = weicht vom berechneten Endbestand des Vorjahres ab.')));
  }

  /* 3. Jahresabschluss */
  view.append(el('h2', {}, `3. Jahresabschluss ${jahr}`));
  view.append(el('p', { class: 'muted' }, `Wenn ${jahr} fertig gebucht ist: Die berechneten Endbestände aller Geld- und Bestandskonten werden als Anfangsbestände ${jahr + 1} eingetragen, und ${jahr + 1} übernimmt die Buchführungsart, falls dort noch keine gewählt ist. Lässt sich jederzeit wiederholen, z. B. wenn noch nachgebucht wurde.`));
  const folge = d.best.filter((r) => Number(r.jahr) === jahr + 1);
  if (folge.length) {
    const jd = BUCH.jahresDaten(d.buchungen, d.best, jahr);
    const abw = [];
    for (const nr of new Set([...Object.keys(jd.konten), ...folge.map((r) => String(r.konto))])) {
      if (BUCH.istErfolg(nr, d.info)) continue;
      const ende = (jd.konten[nr] ? jd.konten[nr].ende : 0) / 100;
      const anf = folge.filter((r) => String(r.konto) === nr).reduce((s, r) => s + Number(r.betrag), 0);
      if (Math.abs(ende - anf) > 0.004) abw.push(`${kLabel(d, nr)}: ${eur(ende)} ≠ ${eur(anf)}`);
    }
    view.append(el('div', { class: abw.length ? 'note warn' : 'note ok' }, abw.length ? `Die Anfangsbestände ${jahr + 1} passen nicht mehr zu den Endbeständen: ${abw.join(' · ')}` : `Die Anfangsbestände ${jahr + 1} stimmen mit den Endbeständen überein.`));
  }
  if (d.umgestellt) {
    view.append(el('button', { onclick: async () => {
      if (!confirm(`Endbestände ${jahr} als Anfangsbestände ${jahr + 1} übernehmen?`)) return;
      try { await bhAktion('jahresabschluss', { jahr }, (r) => `${r.konten} Endbestände als Anfangsbestände ${r.jahr} übernommen.`); showGeschaeftsjahr(); } catch (e) { toast(e.message, true); }
    } }, `Endbestände ${jahr} → Anfangsbestände ${jahr + 1}`));
  }

  /* 4. Geldkonten und Vorgaben */
  view.append(el('h2', {}, '4. Geldkonten und Vorgaben für automatische Buchungen'));
  view.append(el('p', { class: 'muted' }, 'Geldkonten sind die Konten, auf denen echtes Geld liegt. Welche das sind, legt ihr im Kontenplan über den Typ „Geldkonto" fest. Zettle-Kartenzahlungen landen auf dem PayPal-Konto – beides ist dasselbe Geld.'));
  view.append(el('p', { class: 'muted' }, 'Z-Bon, Bank-Import, SEPA-Einzug, Rechnungen und Auslagen buchen selbstständig. Hier stellt ihr ein, auf welches Konto sie gehen. Eine Änderung gilt für neue Buchungen; bestehende bleiben, wo sie sind (dafür: „Konten zusammenlegen").'));
  const vorgabenHost = el('div', {});
  view.append(vorgabenHost);
  call(api.report.quelleMap()).then((q) => {
    const vorgaben = q.vorgaben || {};
    const form = el('form', { class: 'detail' });
    const sel = {};
    for (const [quelle, label] of Object.entries(vorgaben)) {
      sel[quelle] = kontoSelect(d.konten, (q.map || {})[quelle] || '', 'geld', null);
      form.append(labelMitHilfe(label), sel[quelle]);
    }
    if (q.editable !== false && d.umgestellt) form.append(el('div', { class: 'form-actions' }, el('button', { class: 'primary', type: 'submit' }, 'Vorgaben speichern')));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await call(api.report.saveQuelleMap(Object.fromEntries(Object.entries(sel).map(([k, s]) => [k, s.value]))));
        toast('Vorgaben gespeichert.');
      } catch (e) { toast(e.message, true); }
    });
    vorgabenHost.append(form);
    if (q.umstellung) {
      const u = q.umstellung;
      vorgabenHost.append(hilfe('Umstellung auf feste Geldkonten (v0.33)', [
        `Am ${u.datum} wurden ${u.umgestellt} von ${u.buchungen} Buchungen umgestellt. Jede Buchung wurde vorher und nachher als Buchungssatz verglichen.`,
        (u.abweichungen || []).length ? `Nicht umgestellt, weil sich der Buchungssatz geändert hätte: #${u.abweichungen.join(', #')}` : 'Keine Abweichungen – alle Kontostände sind unverändert.',
        `Sicherungskopie der Buchungen vor der Umstellung: Tabelle ${u.sicherung}.`,
        ...(u.hinweise || []),
      ]));
    }
  }).catch((e) => vorgabenHost.append(el('div', { class: 'note' }, 'Vorgaben nur online einsehbar: ' + e.message)));

  /* 5. Konten zusammenlegen */
  if (d.umgestellt) {
    view.append(el('h2', {}, '5. Konten zusammenlegen'));
    view.append(el('p', { class: 'muted' }, 'Wenn zwei Konten eigentlich dasselbe sind (z. B. ein altes und ein neues PayPal-Konto): Alle Buchungen, Anfangsbestände, Budgets und Regeln des ersten Kontos wandern auf das zweite, das erste wird deaktiviert. Gilt für alle Jahre.'));
    const von = kontoSelect(d.konten, '', 'alle');
    const nach = kontoSelect(d.konten, '', 'alle');
    const f = el('form', { class: 'detail' }, 'Dieses Konto …', von, '… geht auf in', nach,
      el('div', { class: 'form-actions' }, el('button', { class: 'danger', type: 'submit' }, 'Zusammenlegen')));
    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!von.value || !nach.value || von.value === nach.value) return toast('Zwei verschiedene Konten wählen.', true);
      if (!confirm(`${kLabel(d, von.value)} in ${kLabel(d, nach.value)} aufgehen lassen? Das betrifft alle Jahre.`)) return;
      try { await bhAktion('konten-zusammenlegen', { von: von.value, nach: nach.value }, (r) => `Zusammengelegt: ${r.buchungen} Buchungsfelder, ${r.anfangsbestaende} Anfangsbestände.`); showGeschaeftsjahr(); } catch (e) { toast(e.message, true); }
    });
    view.append(f);
  }
}

/* --------------------------------------------------------------- Kontenplan */

async function showKontenplan() {
  state.current = { name: 'kontenplan' };
  renderNav();
  let d;
  try { d = await bhLaden(); } catch (e) { view.innerHTML = ''; return view.append(el('div', { class: 'note err' }, e.message)); }
  bhKopf(d, 'Kontenplan', 'Alle Konten mit Stand (Geld- und Bestandskonten) bzw. Umsatz im Geschäftsjahr (Einnahmen/Ausgaben).', showKontenplan);
  view.append(hilfe('Welcher Kontotyp wofür?', [
    'Geldkonto: wo echtes Geld liegt – Bank, Barkasse, PayPal. Nur diese stehen bei Einnahmen und Ausgaben zur Auswahl und im Kassenbericht.',
    'Einnahme / Ausgabe: der Zweck einer Buchung (4100 Beiträge, 5600 Wareneinkauf). Nur diese Konten stehen in der EÜR bzw. GuV.',
    'Bestand: Forderungen, Verbindlichkeiten, Geldtransit – Werte ohne eigenes Bankkonto. Neutral: Verrechnungskonten wie 1590 „noch nicht zugeordnet".',
  ]));
  const jd = BUCH.jahresDaten(d.buchungen, d.best, BH.jahr);
  const e = BUCH.euer(jd, d.info);
  const umsatz = new Map(e.proKonto.map((k) => [k.konto, k]));
  const gruppen = [['geld', 'Geldkonten'], ['bestand', 'Weitere Bestandskonten'], ['einnahme', 'Einnahmen'], ['ausgabe', 'Ausgaben']];
  const gruppeVon = (k) => (['geld', 'einnahme', 'ausgabe'].includes(k.typ) ? k.typ : 'bestand');
  const bekannt = new Set(d.konten.map((k) => String(k.nummer)));
  const fremd = Object.keys(jd.konten).filter((nr) => !bekannt.has(nr) && (jd.konten[nr].ende || jd.konten[nr].anzahl)).map((nr) => ({ nummer: nr, bezeichnung: '(nicht im Kontenplan)', typ: BUCH.typVon(nr, d.info), aktiv: 1 }));
  const R = { style: 'text-align:right' };
  for (const [g, titel] of gruppen) {
    const liste = [...d.konten, ...fremd].filter((k) => gruppeVon(k) === g).sort((a, b) => String(a.nummer).localeCompare(String(b.nummer), 'de', { numeric: true }));
    if (!liste.length) continue;
    view.append(el('h2', {}, titel));
    const t = el('table');
    const erfolg = g === 'einnahme' || g === 'ausgabe';
    t.append(el('thead', {}, el('tr', {}, el('th', {}, 'Nr'), el('th', {}, 'Bezeichnung'), el('th', {}, 'Sphäre'), el('th', R, erfolg ? `Umsatz ${BH.jahr}` : `Stand 31.12.${BH.jahr}`), el('th', R, 'Buchungen'), el('th', {}))));
    const tb = el('tbody');
    for (const k of liste) {
      const nr = String(k.nummer);
      const u = umsatz.get(nr);
      const jk = jd.konten[nr];
      const wert = erfolg ? (u ? (g === 'einnahme' ? u.einnahmen : u.ausgaben) : 0) : (jk ? jk.ende / 100 : 0);
      const anz = jk ? jk.anzahl : 0;
      tb.append(el('tr', { class: String(k.aktiv) === '0' ? 'muted' : '', style: 'cursor:pointer', onclick: () => showKontenblatt(nr, BH.jahr) },
        el('td', {}, nr), el('td', {}, k.bezeichnung), el('td', {}, SPHAERE_LABEL[k.sphaere] || k.sphaere || '—'),
        el('td', { style: 'text-align:right' + (wert < 0 && !erfolg ? ';color:var(--err-ink)' : '') }, anz || wert ? eur(wert) : '—'),
        el('td', R, anz ? String(anz) : ''),
        el('td', {}, k.id ? el('button', { class: 'small ghost', onclick: (ev) => { ev.stopPropagation(); showDetail('jb_konten', k.id); } }, 'bearbeiten') : null)));
    }
    t.append(tb);
    view.append(t);
  }
  view.append(el('p', { class: 'muted', style: 'margin-top:12px' }, 'Zeile anklicken → alle Bewegungen des Kontos. ',
    el('button', { class: 'small', onclick: () => showTable('jb_konten') }, 'Konto anlegen / Liste bearbeiten')));
}
