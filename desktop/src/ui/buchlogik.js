'use strict';

/**
 * Buchungslogik – reine Rechenfunktionen ohne Oberfläche.
 *
 * Spiegel von vereinsplugin/includes/buchungslogik.php. Beide Seiten müssen
 * dieselben Zahlen liefern; Änderungen immer an beiden Stellen machen.
 *
 * Eine Buchung (Zeile in jb_buchungen) verbindet genau zwei Konten:
 *   geldkonto  – Konto A, bei EÜR-Buchungen das Geldkonto (Bank, Kasse, PayPal)
 *   konto      – Konto B, bei EÜR-Buchungen das SKR-Konto (wofür?)
 *   betrag     – Wirkung auf Konto A: positiv = Zugang, negativ = Abgang
 *
 * Daraus folgt der Buchungssatz:
 *   betrag ≥ 0:  Soll A  an  Haben B
 *   betrag < 0:  Soll B  an  Haben A
 *
 * Ob ein Geschäftsjahr als EÜR oder Doppik geführt wird, ändert nur Erfassung
 * und Auswertung – nie die gespeicherten Zeilen.
 */

const INTERIM = '1590'; // Buchung ohne SKR-Konto
const INTERIM2 = '1599'; // Gegenstück, falls das Geldkonto selbst 1590 ist

/** Alte Standardzuordnung „quelle" → Konto (nur noch für Altzeilen ohne geldkonto). */
const ALT_STANDARD_MAP = {
  'Bank KSK': '1200', 'Zettle-Bar': '1000', Bar: '1000', PayPal: '1220',
  'Zettle-Karte': '1360', Auslage: '1600', Umbuchung: '1360', Manuell: '1200',
};

/**
 * Herkunftsnamen, die dasselbe Geldkonto meinen. Zettle-Kartenzahlungen landen
 * auf dem PayPal-Konto, „Bar" ist die Barkasse, „Manuell" das Bankkonto.
 */
const QUELLE_ALIAS = { 'Zettle-Karte': 'PayPal', Bar: 'Zettle-Bar', Manuell: 'Bank KSK' };

const cents = (x) => Math.round((Number(x) || 0) * 100);
const eurosAus = (c) => Math.round(c) / 100;

/** Kontenplan als Nachschlagetabelle: nummer → { typ, sphaere, bezeichnung }. */
function kontoInfo(konten) {
  const m = new Map();
  for (const k of konten || []) {
    m.set(String(k.nummer), { typ: String(k.typ || ''), sphaere: String(k.sphaere || ''), bezeichnung: String(k.bezeichnung || '') });
  }
  return m;
}

/**
 * Typ eines Kontos. Unbekannte Nummern (nicht im Kontenplan) werden nach der
 * ersten Ziffer eingeordnet: 4 = Einnahme, 5–7 = Ausgabe, sonst Bestand.
 */
function typVon(nr, info) {
  const k = info && info.get(String(nr));
  if (k && k.typ) return k.typ;
  const s = String(nr || '');
  if ([INTERIM, INTERIM2].includes(s)) return 'neutral';
  if (s[0] === '4') return 'einnahme';
  if (['5', '6', '7'].includes(s[0])) return 'ausgabe';
  return 'bestand';
}

/** Erfolgskonto = Einnahme/Ausgabe oder ein noch fehlendes Sachkonto (1590/1599). */
function istErfolg(nr, info) {
  const s = String(nr || '');
  if (s === '' || s === INTERIM || s === INTERIM2) return true;
  const t = typVon(s, info);
  return t === 'einnahme' || t === 'ausgabe';
}

function istGeld(nr, info) {
  return typVon(nr, info) === 'geld';
}

/** Vorgabe-Geldkonto für automatisch erzeugte Buchungen (Z-Bon, Bank-Import …). */
function vorgabeGeldkonto(quelle, map) {
  const m = map || {};
  const q = QUELLE_ALIAS[quelle] || quelle;
  return String(m[q] || m[quelle] || m['Bank KSK'] || ALT_STANDARD_MAP['Bank KSK']);
}

/** Konto A einer Zeile – explizit oder (Altzeile) aus gegenkonto/quelle abgeleitet. */
function kontoA(r, altMap) {
  if (r.geldkonto) return { nr: String(r.geldkonto), legacyUmb: false };
  if (r.gegenkonto) return { nr: String(r.gegenkonto), legacyUmb: true };
  const m = altMap || ALT_STANDARD_MAP;
  return { nr: String(m[r.quelle] || m.Manuell || '1200'), legacyUmb: false };
}

/** Buchungssatz einer Zeile. */
function satz(r, altMap) {
  const a = kontoA(r, altMap);
  const betrag = Number(r.betrag) || 0;
  const b = String(r.konto || '') || (a.nr === INTERIM ? INTERIM2 : INTERIM);
  const zugang = a.legacyUmb ? false : betrag >= 0;
  return {
    id: r.id,
    datum: String(r.buchung_datum || ''),
    soll: zugang ? a.nr : b,
    haben: zugang ? b : a.nr,
    cent: Math.abs(cents(betrag)),
    betrag: eurosAus(Math.abs(cents(betrag))),
  };
}

/**
 * Soll/Haben/Betrag → Zeilenspalten. Genaue Umkehrung von satz():
 * steht auf genau einer Seite ein Bestandskonto, wird es Konto A.
 */
function zeileAusSatz(soll, haben, betrag, info) {
  const c = Math.abs(cents(betrag));
  const sollBestand = !istErfolg(soll, info);
  const habenBestand = !istErfolg(haben, info);
  if (sollBestand && !habenBestand) return { geldkonto: String(soll), konto: String(haben), betrag: eurosAus(c) };
  return { geldkonto: String(haben), konto: String(soll), betrag: eurosAus(-c) };
}

/**
 * EÜR-Sicht einer Zeile: Einnahme, Ausgabe oder Umbuchung zwischen zwei
 * Bestandskonten. null, wenn die Zeile nur als Buchungssatz darstellbar ist
 * (Konto A ist ein Erfolgskonto – z. B. „5100 an 4100").
 */
function euerSicht(r, info, altMap) {
  const s = satz(r, altMap);
  const sollE = istErfolg(s.soll, info);
  const habenE = istErfolg(s.haben, info);
  if (sollE && habenE) return null;
  if (!sollE && habenE) return { art: 'einnahme', geldkonto: s.soll, konto: s.haben, betrag: s.betrag };
  if (sollE && !habenE) return { art: 'ausgabe', geldkonto: s.haben, konto: s.soll, betrag: s.betrag };
  return { art: 'umbuchung', von: s.haben, nach: s.soll, betrag: s.betrag };
}

/** EÜR-Eingabe → Zeilenspalten. */
function zeileAusEuer(e) {
  const c = Math.abs(cents(e.betrag));
  if (e.art === 'umbuchung') return { geldkonto: String(e.von), konto: String(e.nach), betrag: eurosAus(-c) };
  return { geldkonto: String(e.geldkonto), konto: String(e.konto || ''), betrag: eurosAus(e.art === 'ausgabe' ? -c : c) };
}

/**
 * Umstellung einer Altzeile auf die explizite Form (Spiegel der PHP-Migration).
 * altMap muss die bisher gültige Zuordnung quelle → Konto sein.
 */
function migriereZeile(r, altMap) {
  if (r.geldkonto) return { geldkonto: String(r.geldkonto), konto: String(r.konto || ''), betrag: Number(r.betrag) || 0, gegenkonto: '' };
  if (r.gegenkonto) {
    return { geldkonto: String(r.gegenkonto), konto: String(r.konto || ''), betrag: eurosAus(-Math.abs(cents(r.betrag))), gegenkonto: '' };
  }
  return { geldkonto: kontoA(r, altMap).nr, konto: String(r.konto || ''), betrag: Number(r.betrag) || 0, gegenkonto: '' };
}

/* ------------------------------------------------------------ Geschäftsjahr */

/** Buchführungsart eines Jahres. Ohne Eintrag gilt EÜR. */
function methodeFuer(gjRows, jahr) {
  const r = (gjRows || []).find((x) => Number(x.jahr) === Number(jahr));
  return { methode: r && r.methode === 'doppik' ? 'doppik' : 'euer', gesetzt: !!r };
}

/** Basisjahr = jüngstes Jahr mit Anfangsbeständen, das nicht nach `jahr` liegt. */
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
      if (Number(r.jahr) !== basis || !r.konto) continue;
      anfang[String(r.konto)] = (anfang[String(r.konto)] || 0) + cents(r.betrag);
    }
  }
  return { basis, anfang }; // anfang in Cent
}

/**
 * Alles, was die Auswertungen eines Jahres brauchen, in einem Durchlauf:
 *   anfang  – Bestand je Konto am 1.1. (Anfangsbestand des Basisjahres
 *             + alle Bewegungen bis zum 31.12. des Vorjahres)
 *   soll/haben/anzahl – Bewegungen im Jahr
 *   saetze  – die Buchungssätze des Jahres
 * Alle Beträge in Cent.
 */
function jahresDaten(rows, bestRows, jahr, altMap) {
  const j = Number(jahr);
  const { basis, anfang: anf } = anfangFuerJahr(bestRows, j);
  const konten = {};
  const acc = (k) => (konten[k] ||= { konto: k, anfang: 0, soll: 0, haben: 0, anzahl: 0 });
  for (const [k, c] of Object.entries(anf)) acc(k).anfang += c;
  const von = basis ? `${basis}-01-01` : '';
  const jahrVon = `${j}-01-01`;
  const bis = `${j}-12-31`;
  const saetze = [];
  for (const r of rows || []) {
    const d = String(r.buchung_datum || '');
    if ((von && d < von) || d > bis) continue;
    const s = satz(r, altMap);
    if (d < jahrVon) {
      acc(s.soll).anfang += s.cent;
      acc(s.haben).anfang -= s.cent;
      continue;
    }
    saetze.push(s);
    acc(s.soll).soll += s.cent;
    acc(s.soll).anzahl++;
    acc(s.haben).haben += s.cent;
    acc(s.haben).anzahl++;
  }
  for (const k of Object.values(konten)) k.ende = k.anfang + k.soll - k.haben;
  return { jahr: j, basis, konten, saetze };
}

/** Summen- und Saldenliste (Doppik) – in Euro, sortiert nach Kontonummer. */
function saldenliste(jd, info) {
  return Object.values(jd.konten)
    .filter((k) => k.anfang || k.soll || k.haben)
    .map((k) => ({
      konto: k.konto,
      name: (info && info.get(k.konto) && info.get(k.konto).bezeichnung) || '',
      typ: typVon(k.konto, info),
      anfang: eurosAus(k.anfang), soll: eurosAus(k.soll), haben: eurosAus(k.haben), saldo: eurosAus(k.ende), anzahl: k.anzahl,
    }))
    .sort((a, b) => a.konto.localeCompare(b.konto, 'de', { numeric: true }));
}

/** Stand der Geldkonten (Konten mit Typ „geld") am Jahresanfang und -ende. */
function geldkontenStand(jd, info) {
  const out = [];
  for (const k of Object.values(jd.konten)) {
    if (!istGeld(k.konto, info)) continue;
    out.push({
      konto: k.konto,
      name: (info.get(k.konto) || {}).bezeichnung || '',
      anfang: eurosAus(k.anfang), zugang: eurosAus(k.soll), abgang: eurosAus(k.haben), ende: eurosAus(k.ende),
    });
  }
  // Geldkonten ohne jede Bewegung trotzdem zeigen.
  for (const [nr, k] of info) {
    if (k.typ === 'geld' && !out.some((x) => x.konto === nr)) out.push({ konto: nr, name: k.bezeichnung, anfang: 0, zugang: 0, abgang: 0, ende: 0 });
  }
  return out.sort((a, b) => a.konto.localeCompare(b.konto, 'de', { numeric: true }));
}

/**
 * Einnahmen-Überschuss-Rechnung eines Jahres – aus den Buchungssätzen, nicht
 * aus dem Vorzeichen. Umbuchungen zwischen Geldkonten zählen deshalb nie als
 * Einnahme oder Ausgabe. In der Doppik ist das die GuV.
 */
function euer(jd, info) {
  const proKonto = {};
  const ohne = { einnahmen: 0, ausgaben: 0, anzahl: 0 };
  const zeile = (k) => (proKonto[k] ||= { konto: k, einnahmen: 0, ausgaben: 0, anzahl: 0 });
  for (const s of jd.saetze) {
    for (const [nr, seite] of [[s.soll, 'soll'], [s.haben, 'haben']]) {
      if (nr === INTERIM || nr === INTERIM2) {
        if (seite === 'haben') ohne.einnahmen += s.cent;
        else ohne.ausgaben += s.cent;
        ohne.anzahl++;
        continue;
      }
      const t = typVon(nr, info);
      if (t === 'einnahme') {
        zeile(nr).einnahmen += seite === 'haben' ? s.cent : -s.cent;
        zeile(nr).anzahl++;
      } else if (t === 'ausgabe') {
        zeile(nr).ausgaben += seite === 'soll' ? s.cent : -s.cent;
        zeile(nr).anzahl++;
      }
    }
  }
  const sphaereVon = (nr) => {
    const k = info && info.get(nr);
    return (k && k.sphaere) || '';
  };
  const proSphaere = {};
  let ein = ohne.einnahmen;
  let aus = ohne.ausgaben;
  for (const z of Object.values(proKonto)) {
    ein += z.einnahmen;
    aus += z.ausgaben;
    const sp = sphaereVon(z.konto) || '—';
    const p = (proSphaere[sp] ||= { sphaere: sp, einnahmen: 0, ausgaben: 0 });
    p.einnahmen += z.einnahmen;
    p.ausgaben += z.ausgaben;
  }
  if (ohne.anzahl) {
    const p = (proSphaere['—'] ||= { sphaere: '—', einnahmen: 0, ausgaben: 0 });
    p.einnahmen += ohne.einnahmen;
    p.ausgaben += ohne.ausgaben;
  }
  return {
    einnahmen: eurosAus(ein),
    ausgaben: eurosAus(aus),
    ueberschuss: eurosAus(ein - aus),
    proKonto: Object.values(proKonto)
      .map((z) => ({ konto: z.konto, name: ((info && info.get(z.konto)) || {}).bezeichnung || '', typ: typVon(z.konto, info), sphaere: sphaereVon(z.konto), einnahmen: eurosAus(z.einnahmen), ausgaben: eurosAus(z.ausgaben), anzahl: z.anzahl }))
      .sort((a, b) => a.konto.localeCompare(b.konto, 'de', { numeric: true })),
    proSphaere: Object.values(proSphaere).map((p) => ({ sphaere: p.sphaere, einnahmen: eurosAus(p.einnahmen), ausgaben: eurosAus(p.ausgaben), saldo: eurosAus(p.einnahmen - p.ausgaben) })),
    ohneKonto: { einnahmen: eurosAus(ohne.einnahmen), ausgaben: eurosAus(ohne.ausgaben), anzahl: ohne.anzahl },
  };
}

/** Zeilen eines Jahres, die sich nicht als EÜR darstellen lassen. */
function nichtEuerKonform(rows, jahr, info, altMap) {
  const j = String(jahr);
  return (rows || []).filter((r) => String(r.buchung_datum || '').slice(0, 4) === j && !euerSicht(r, info, altMap));
}

/** Kontenblatt eines Kontos im Jahr: Anfang, jede Bewegung mit Gegenkonto, laufender Saldo. */
function kontenblatt(rows, bestRows, konto, jahr, altMap) {
  const nr = String(konto);
  const jd = jahresDaten(rows, bestRows, jahr, altMap);
  const k = jd.konten[nr] || { anfang: 0, ende: 0 };
  const byId = new Map((rows || []).map((r) => [String(r.id), r]));
  let saldo = k.anfang;
  const zeilen = [];
  const sortiert = jd.saetze.slice().sort((a, b) => a.datum.localeCompare(b.datum) || Number(a.id) - Number(b.id));
  for (const s of sortiert) {
    if (s.soll !== nr && s.haben !== nr) continue;
    const soll = s.soll === nr ? s.cent : 0;
    const haben = s.haben === nr ? s.cent : 0;
    saldo += soll - haben;
    const r = byId.get(String(s.id)) || {};
    zeilen.push({
      id: s.id, datum: s.datum, gegen: s.soll === nr ? s.haben : s.soll,
      text: [r.gegenpartei, r.beschreibung].map((x) => String(x || '').trim()).filter(Boolean).join(' – '),
      beleg: r.beleg_nr || '', soll: eurosAus(soll), haben: eurosAus(haben), saldo: eurosAus(saldo),
    });
  }
  return { konto: nr, anfang: eurosAus(k.anfang), zeilen, ende: eurosAus(saldo) };
}

const BUCH = {
  INTERIM, INTERIM2, ALT_STANDARD_MAP, QUELLE_ALIAS,
  cents, kontoInfo, typVon, istErfolg, istGeld, vorgabeGeldkonto,
  satz, zeileAusSatz, euerSicht, zeileAusEuer, migriereZeile,
  methodeFuer, anfangFuerJahr, jahresDaten, saldenliste, geldkontenStand, euer, nichtEuerKonform, kontenblatt,
};

if (typeof module !== 'undefined' && module.exports) module.exports = BUCH;
if (typeof window !== 'undefined') window.BUCH = BUCH;
