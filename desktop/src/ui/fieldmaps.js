'use strict';

/**
 * Anzeige-Konfiguration je Bereich (Renderer-seitig, hängt an window.FIELDMAPS).
 *
 *  editable : darf die App zurückschreiben (Phase 1: Mitglieder + Buchhaltung)
 *  label    : Klartextname im Menü
 *  title(r) : Kurztitel einer Zeile in der Liste
 *  fields   : { spalte: { label, type, readonly, ... } }
 *
 * Feldtypen:
 *  (ohne)          – Textfeld
 *  'number'/'date'/'email'/'textarea'
 *  'select'        – Dropdown. Optionen aus `options: [['wert','Text'], …]`
 *                    und/oder dynamisch aus `from: { slug, value, label(r) }`.
 *                    `allowEmpty:false` unterdrückt die Leer-Option.
 *  'datalist'      – Textfeld mit Vorschlägen aus vorhandenen Werten der Spalte
 *                    `suggest` (Standard: dieselbe Spalte).
 */

const SPHAEREN = [
  ['', '—'],
  ['ideell', 'Ideeller Bereich'],
  ['zweckbetrieb', 'Zweckbetrieb'],
  ['vermoegen', 'Vermögensverwaltung'],
  ['wirtschaftlich', 'Wirtschaftl. Geschäftsbetrieb'],
];
const JA_NEIN = [['1', 'Ja'], ['0', 'Nein']];

const KONTO_GRUPPE = { einnahme: 'Einnahmen / Erträge', ausgabe: 'Ausgaben / Aufwand' };
// Gemeinsame Konfiguration für alle „Konto (SKR 49)"-Felder: Wert = Kontonummer,
// Anzeige = „Nr – Bezeichnung", gruppiert nach Typ, sortiert nach Nummer.
const KONTO_FROM = {
  slug: 'jb_konten',
  value: 'nummer',
  label: (r) => `${r.nummer} – ${r.bezeichnung}`,
  group: (r) => KONTO_GRUPPE[r.typ] || 'Bank / Sonstige',
  sort: (r) => String(r.nummer || ''),
};

const T = {
  // ---------------- editierbar -------------------------------------------
  wp_members: {
    label: 'Mitglieder',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => r.display_name || `${r.first_name || ''} ${r.last_name || ''}`.trim() || r.user_login,
    fields: {
      id: { label: 'ID', readonly: true },
      user_login: { label: 'Benutzername', readonly: true },
      roles: { label: 'Rollen', readonly: true },
      user_email: { label: 'E-Mail', type: 'email' },
      display_name: { label: 'Anzeigename' },
      first_name: { label: 'Vorname' },
      last_name: { label: 'Nachname' },
      vp_telefon: { label: 'Telefon' },
      vp_geburtsdatum: { label: 'Geburtsdatum', type: 'date' },
      vp_strasse: { label: 'Straße' },
      vp_plz: { label: 'PLZ' },
      vp_ort: { label: 'Ort' },
      vp_land: { label: 'Land' },
      vp_beitrag: { label: 'Beitrag (€)', type: 'number' },
      vp_beitrag_intervall: { label: 'Intervall' },
      vp_sepa_iban: { label: 'IBAN' },
      vp_sepa_kontoinhaber: { label: 'Kontoinhaber' },
      vp_sepa_mandat: { label: 'SEPA-Mandat', type: 'select', allowEmpty: false, options: JA_NEIN },
      vp_mandatsref: { label: 'Mandatsreferenz' },
      vp_mitglied_seit: { label: 'Mitglied seit', type: 'date' },
    },
  },
  vp_antraege: {
    label: 'Mitgliedsanträge',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `${r.vorname} ${r.nachname} – ${r.status}`,
    fields: {
      id: { label: 'ID', readonly: true },
      created_at: { label: 'Eingegangen', readonly: true },
      status: {
        label: 'Status',
        type: 'select',
        allowEmpty: false,
        options: [['neu', 'Neu'], ['angenommen', 'Angenommen'], ['abgelehnt', 'Abgelehnt']],
      },
      vorname: { label: 'Vorname' },
      nachname: { label: 'Nachname' },
      email: { label: 'E-Mail', type: 'email' },
      telefon: { label: 'Telefon' },
      geburtsdatum: { label: 'Geburtsdatum', type: 'date' },
      strasse: { label: 'Straße' },
      plz: { label: 'PLZ' },
      ort: { label: 'Ort' },
      land: { label: 'Land' },
      beitrag: { label: 'Beitrag (€)', type: 'number' },
      beitrag_intervall: {
        label: 'Intervall',
        type: 'select',
        options: [
          ['', '—'],
          ['monatlich', 'monatlich'],
          ['vierteljährlich', 'vierteljährlich'],
          ['halbjährlich', 'halbjährlich'],
          ['jährlich', 'jährlich'],
        ],
      },
      sepa_iban: { label: 'IBAN' },
      sepa_kontoinhaber: { label: 'Kontoinhaber' },
      sepa_mandat: { label: 'SEPA-Mandat', type: 'select', allowEmpty: false, options: JA_NEIN },
      mandatsref: { label: 'Mandatsreferenz' },
      nachricht: { label: 'Nachricht', type: 'textarea' },
      notiz: { label: 'Interne Notiz', type: 'textarea' },
      user_id: { label: 'Verknüpfter Benutzer', readonly: true },
    },
  },
  jb_auslagen: {
    label: 'Auslagen',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `#${r.id} · ${r.betrag} € · ${r.status}`,
    fields: {
      id: { label: 'ID', readonly: true },
      user_id: { label: 'Mitglied (User-ID)', type: 'number' },
      ausgabe_datum: { label: 'Ausgabedatum', type: 'date' },
      betrag: { label: 'Betrag (€)', type: 'number' },
      kategorie: {
        label: 'Kategorie',
        type: 'datalist',
        from: { slug: 'jb_konten', value: 'bezeichnung', label: (r) => `${r.nummer} – ${r.bezeichnung}` },
      },
      beschreibung: { label: 'Beschreibung', type: 'textarea' },
      beleg_pfad: { label: 'Beleg-Pfad (Nextcloud)' },
      beleg_name: { label: 'Beleg-Dateiname' },
      status: {
        label: 'Status',
        type: 'select',
        allowEmpty: false,
        options: [
          ['ausstehend', 'Ausstehend'],
          ['genehmigt', 'Genehmigt'],
          ['abgelehnt', 'Abgelehnt'],
          ['ausgezahlt', 'Ausgezahlt'],
        ],
      },
      kassier_id: { label: 'Kassier (User-ID)', type: 'number' },
      kassier_notiz: { label: 'Kassier-Notiz', type: 'textarea' },
      budget_id: {
        label: 'Budget',
        type: 'select',
        from: { slug: 'jb_budgets', value: 'id', label: (r) => `#${r.id} · ${r.zweck}` },
      },
      buchung_id: { label: 'Buchungs-ID', type: 'number' },
    },
  },
  jb_buchungen: {
    label: 'Buchungsjournal',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `${r.buchung_datum} · ${r.betrag} € · ${r.kategorie}`,
    fields: {
      id: { label: 'ID', readonly: true },
      buchung_datum: { label: 'Datum', type: 'date' },
      betrag: { label: 'Betrag (€, negativ = Ausgabe)', type: 'number' },
      kategorie: {
        label: 'Kategorie',
        type: 'datalist',
        from: { slug: 'jb_konten', value: 'bezeichnung', label: (r) => `${r.nummer} – ${r.bezeichnung}` },
      },
      beschreibung: { label: 'Beschreibung', type: 'textarea' },
      quelle: {
        label: 'Quelle',
        type: 'select',
        options: [
          ['', '—'],
          ['Bank KSK', 'Bank KSK'],
          ['Zettle-Bar', 'Zettle-Bar'],
          ['Zettle-Karte', 'Zettle-Karte'],
          ['Auslage', 'Auslage'],
          ['Manuell', 'Manuell'],
        ],
      },
      konto: { label: 'Konto (SKR 49)', type: 'select', from: KONTO_FROM },
      sphaere: { label: 'Sphäre', type: 'select', options: SPHAEREN },
      gegenpartei: { label: 'Gegenpartei', type: 'datalist' },
      beleg_referenz: { label: 'Beleg-Nr' },
      beleg_pfad: { label: 'Beleg-Pfad (Nextcloud)' },
      auslage_id: {
        label: 'Auslage',
        type: 'select',
        from: { slug: 'jb_auslagen', value: 'id', label: (r) => `#${r.id} · ${r.betrag} € · ${r.beschreibung || ''}`.slice(0, 60) },
      },
      erstellt_von: { label: 'Erstellt von (User-ID)', type: 'number' },
    },
  },
  jb_budgets: {
    label: 'Budgets',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `${r.zweck} · ${r.ausgegeben || 0}/${r.betrag} €`,
    fields: {
      id: { label: 'ID', readonly: true },
      zweck: { label: 'Zweck' },
      beschreibung: { label: 'Beschreibung', type: 'textarea' },
      betrag: { label: 'Budget (€)', type: 'number' },
      ausgegeben: { label: 'Ausgegeben (€)', type: 'number' },
      notiz: { label: 'Notiz', type: 'textarea' },
      verantwortlich_user_id: { label: 'Verantwortlich (User-ID)', type: 'number' },
      jahr: { label: 'Jahr', type: 'number' },
      kostenstelle: { label: 'Kostenstelle' },
      konto: { label: 'Konto (SKR 49)', type: 'select', from: KONTO_FROM },
      aktiv: { label: 'Aktiv', type: 'select', allowEmpty: false, options: JA_NEIN },
    },
  },
  jb_ruecklagen: {
    label: 'Rücklagen',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `${r.bezeichnung} · ${r.betrag} € / ${r.intervall_monate} Mon.`,
    fields: {
      id: { label: 'ID', readonly: true },
      bezeichnung: { label: 'Bezeichnung' },
      betrag: { label: 'Betrag (€)', type: 'number' },
      intervall_monate: { label: 'Intervall (Monate)', type: 'number' },
      letzte_zahlung: { label: 'Letzte Zahlung', type: 'date' },
      notiz: { label: 'Notiz', type: 'textarea' },
      aktiv: { label: 'Aktiv', type: 'select', allowEmpty: false, options: JA_NEIN },
    },
  },
  jb_konten: {
    label: 'Kontenplan (SKR 49)',
    group: 'Bearbeiten',
    editable: true,
    title: (r) => `${r.nummer} · ${r.bezeichnung}`,
    fields: {
      id: { label: 'ID', readonly: true },
      nummer: { label: 'Kontonummer' },
      bezeichnung: { label: 'Bezeichnung' },
      typ: {
        label: 'Typ',
        type: 'select',
        allowEmpty: false,
        options: [['einnahme', 'Einnahme'], ['ausgabe', 'Ausgabe']],
      },
      sphaere: { label: 'Sphäre', type: 'select', options: SPHAEREN },
      aktiv: { label: 'Aktiv', type: 'select', allowEmpty: false, options: JA_NEIN },
      sort: { label: 'Sortierung', type: 'number' },
    },
  },
};

// Alle übrigen Tabellen: nur lesen.
const READ_ONLY_LABELS = {
  wunschliste: 'Wünsche',
  wl_links: 'Wunsch-Links',
  wl_votes: 'Abstimmungen',
  wl_shift_events: 'Schicht-Veranstaltungen',
  wl_shift_stationen: 'Schicht-Stationen',
  wl_shift_schichten: 'Schichten',
  wl_shift_eintragungen: 'Schicht-Eintragungen',
  wl_shift_tausch: 'Schicht-Tauschanfragen',
  pp_gremien: 'Gremien',
  pp_rollen: 'Gremien-Rollen',
  pp_rollenvorlagen: 'Rollenvorlagen',
  pp_rollenvorlagen_aufgaben: 'Rollenvorlagen-Aufgaben',
  pp_protokolle: 'Protokolle',
  pp_tops: 'Tagesordnungspunkte',
  pp_einwaende: 'Einwände',
  pp_themen: 'Themen',
  pp_aufgaben: 'Aufgaben',
  pp_termine: 'Termine',
  pp_bestaetigungen: 'Bestätigungen',
  pp_freigaben: 'Freigaben',
  pp_kreis_mitglieder: 'Kreis-Mitglieder',
  pp_kommentare: 'Protokoll-Kommentare',
  pp_aufgaben_sets: 'Aufgaben-Sets',
  pp_aufgaben_set_eintraege: 'Aufgaben-Set-Einträge',
  jb_getraenke: 'Getränke-Produkte',
  jb_getraenke_bewegungen: 'Getränke-Lagerbewegungen',
  jb_konto_regeln: 'Konto-Regeln',
};

window.FIELDMAPS = {
  get(slug) {
    if (T[slug]) return T[slug];
    return {
      label: READ_ONLY_LABELS[slug] || slug,
      group: 'Nur lesen',
      editable: false,
      title: (r) => `#${r.id}`,
      fields: {},
    };
  },
  editableSlugs: () => Object.keys(T),
  allKnown: () => ({ ...T, ...READ_ONLY_LABELS }),
};
