'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const B = require('./buchlogik');

const KONTEN = [
  { nummer: '1000', bezeichnung: 'Kasse', typ: 'geld', sphaere: 'neutral' },
  { nummer: '1200', bezeichnung: 'Bank', typ: 'geld', sphaere: 'neutral' },
  { nummer: '1215', bezeichnung: 'PayPal', typ: 'geld', sphaere: 'neutral' },
  { nummer: '1600', bezeichnung: 'Verbindlichkeiten', typ: 'bestand', sphaere: 'neutral' },
  { nummer: '4100', bezeichnung: 'Beiträge', typ: 'einnahme', sphaere: 'ideell' },
  { nummer: '4600', bezeichnung: 'Getränke', typ: 'einnahme', sphaere: 'wirtschaftlich' },
  { nummer: '5600', bezeichnung: 'Wareneinkauf', typ: 'ausgabe', sphaere: 'wirtschaftlich' },
];
const info = B.kontoInfo(KONTEN);
const MAP = { ...B.ALT_STANDARD_MAP, PayPal: '1215' };

test('Satz: Einnahme, Ausgabe, Altzeile mit gegenkonto', () => {
  assert.deepEqual(
    (({ soll, haben, betrag }) => ({ soll, haben, betrag }))(B.satz({ geldkonto: '1200', konto: '4100', betrag: '30' })),
    { soll: '1200', haben: '4100', betrag: 30 });
  assert.deepEqual(
    (({ soll, haben }) => ({ soll, haben }))(B.satz({ geldkonto: '1000', konto: '5600', betrag: '-84.2' })),
    { soll: '5600', haben: '1000' });
  // Altzeile: Umbuchung Kasse → Bank, positiver Betrag mit gegenkonto
  assert.deepEqual(
    (({ soll, haben }) => ({ soll, haben }))(B.satz({ konto: '1200', gegenkonto: '1000', betrag: '1585', quelle: 'Umbuchung' }, MAP)),
    { soll: '1200', haben: '1000' });
  // Altzeile ohne Konto → Verrechnungskonto
  assert.equal(B.satz({ quelle: 'Bank KSK', betrag: '-5' }, MAP).soll, B.INTERIM);
});

test('Umstellung ändert keinen Buchungssatz', () => {
  const alt = [
    { id: 1, quelle: 'PayPal', konto: '4600', betrag: '6' },
    { id: 2, quelle: 'Bank KSK', konto: '1215', betrag: '54.87' },
    { id: 3, quelle: 'Umbuchung', konto: '1000', gegenkonto: '1200', betrag: '2150' },
    { id: 4, quelle: 'Bank KSK', konto: '', betrag: '-12.5' },
    { id: 5, quelle: 'Zettle-Bar', konto: '1000', betrag: '3' },
  ];
  for (const r of alt) {
    const vorher = B.satz(r, MAP);
    const neu = { ...r, ...B.migriereZeile(r, MAP) };
    assert.ok(neu.geldkonto, 'geldkonto gesetzt');
    assert.equal(neu.gegenkonto, '');
    const nachher = B.satz(neu, {});
    assert.deepEqual([nachher.soll, nachher.haben, nachher.cent], [vorher.soll, vorher.haben, vorher.cent], `Zeile ${r.id}`);
  }
});

test('Satz → Zeile → Satz ist verlustfrei', () => {
  const faelle = [['1200', '4100'], ['5600', '1000'], ['1200', '1000'], ['5600', '4600'], ['1600', '1200'], ['1200', B.INTERIM]];
  for (const [soll, haben] of faelle) {
    const z = B.zeileAusSatz(soll, haben, 12.34, info);
    const s = B.satz(z);
    assert.deepEqual([s.soll, s.haben, s.betrag], [soll, haben, 12.34], `${soll} an ${haben}`);
  }
});

test('EÜR-Sicht und Rückweg', () => {
  const faelle = [
    { art: 'einnahme', geldkonto: '1200', konto: '4100', betrag: 30 },
    { art: 'ausgabe', geldkonto: '1000', konto: '5600', betrag: 84.2 },
    { art: 'umbuchung', von: '1000', nach: '1200', betrag: 500 },
  ];
  for (const e of faelle) assert.deepEqual(B.euerSicht(B.zeileAusEuer(e), info), e, e.art);
  // Erfolg an Erfolg ist keine EÜR-Buchung
  assert.equal(B.euerSicht(B.zeileAusSatz('5600', '4600', 10, info), info), null);
});

test('EÜR zählt Umbuchungen nicht als Einnahme', () => {
  const rows = [
    { id: 1, buchung_datum: '2026-02-01', geldkonto: '1200', konto: '4100', betrag: '100' },
    { id: 2, buchung_datum: '2026-02-02', geldkonto: '1000', konto: '5600', betrag: '-40' },
    { id: 3, buchung_datum: '2026-02-03', geldkonto: '1200', konto: '1000', betrag: '300' }, // Bareinzahlung
    { id: 4, buchung_datum: '2026-02-04', geldkonto: '1200', konto: '', betrag: '-7' },
    { id: 5, buchung_datum: '2025-12-31', geldkonto: '1200', konto: '4100', betrag: '999' }, // Vorjahr
  ];
  const jd = B.jahresDaten(rows, [{ jahr: 2025, konto: '1200', betrag: '1000' }, { jahr: 2025, konto: '1000', betrag: '500' }], 2026);
  const e = B.euer(jd, info);
  assert.equal(e.einnahmen, 100);
  assert.equal(e.ausgaben, 47);
  assert.equal(e.ueberschuss, 53);
  assert.equal(e.ohneKonto.anzahl, 1);
  const bank = B.geldkontenStand(jd, info).find((k) => k.konto === '1200');
  assert.deepEqual([bank.anfang, bank.zugang, bank.abgang, bank.ende], [1999, 400, 7, 2392]);
  const kasse = B.geldkontenStand(jd, info).find((k) => k.konto === '1000');
  assert.equal(kasse.ende, 500 - 40 - 300);
  assert.equal(B.geldkontenStand(jd, info).find((k) => k.konto === '1215').ende, 0);
});

test('Kontenblatt läuft vom Anfangsbestand', () => {
  const rows = [
    { id: 1, buchung_datum: '2026-03-01', geldkonto: '1000', konto: '4600', betrag: '20', gegenpartei: 'Z-Bon #1' },
    { id: 2, buchung_datum: '2026-03-02', geldkonto: '1200', konto: '1000', betrag: '15' },
  ];
  const kb = B.kontenblatt(rows, [{ jahr: 2026, konto: '1000', betrag: '10' }], '1000', 2026);
  assert.equal(kb.anfang, 10);
  assert.deepEqual(kb.zeilen.map((z) => [z.gegen, z.soll, z.haben, z.saldo]), [['4600', 20, 0, 30], ['1200', 0, 15, 15]]);
  assert.equal(kb.ende, 15);
});

test('Methode je Geschäftsjahr, Standard EÜR', () => {
  const gj = [{ jahr: '2027', methode: 'doppik' }];
  assert.deepEqual(B.methodeFuer(gj, 2027), { methode: 'doppik', gesetzt: true });
  assert.deepEqual(B.methodeFuer(gj, 2026), { methode: 'euer', gesetzt: false });
});

test('Vorgabe-Geldkonto: Zettle-Karte ist PayPal', () => {
  const m = { 'Bank KSK': '1200', 'Zettle-Bar': '1000', PayPal: '1215' };
  assert.equal(B.vorgabeGeldkonto('Zettle-Karte', m), '1215');
  assert.equal(B.vorgabeGeldkonto('Bar', m), '1000');
  assert.equal(B.vorgabeGeldkonto('Manuell', m), '1200');
});
