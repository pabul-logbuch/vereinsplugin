'use strict';

/**
 * Lokales SQLite-Schema. Für jede Remote-Tabelle aus /meta wird eine
 * gleichnamige lokale Tabelle angelegt (alle Spalten TEXT – der lokale
 * Spiegel muss nur exakt zurückliefern, was der Server geschickt hat).
 *
 * Zusätzliche Verwaltungstabellen:
 *   _meta        key/value (last_pull_iso, wp_url, api_meta_json, …)
 *   _row_state   je Zeile: base_rev, dirty, deleted
 *   _pending     ausgehende Mutationen (Warteschlange)
 *   _conflicts   erkannte Konflikte, bis der Nutzer entscheidet
 */

const IDENT = /^[A-Za-z_][A-Za-z0-9_]*$/;

function assertIdent(name, what) {
  if (!IDENT.test(name)) throw new Error(`Ungültiger ${what || 'Bezeichner'}: ${name}`);
  return name;
}

function ensureAdminTables(db) {
  db.exec(`
    CREATE TABLE IF NOT EXISTS _meta (
      key   TEXT PRIMARY KEY,
      value TEXT
    );
    CREATE TABLE IF NOT EXISTS _row_state (
      tbl       TEXT NOT NULL,
      pk        TEXT NOT NULL,
      base_rev  TEXT NOT NULL DEFAULT '',
      dirty     INTEGER NOT NULL DEFAULT 0,
      deleted   INTEGER NOT NULL DEFAULT 0,
      updated_at TEXT,
      PRIMARY KEY (tbl, pk)
    );
    CREATE TABLE IF NOT EXISTS _pending (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      cid         TEXT NOT NULL,
      tbl         TEXT NOT NULL,
      op          TEXT NOT NULL,
      pk          TEXT NOT NULL,
      base_rev    TEXT NOT NULL DEFAULT '',
      fields_json TEXT NOT NULL DEFAULT '{}',
      created_at  TEXT NOT NULL,
      tries       INTEGER NOT NULL DEFAULT 0,
      last_error  TEXT
    );
    CREATE TABLE IF NOT EXISTS _conflicts (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      tbl         TEXT NOT NULL,
      pk          TEXT NOT NULL,
      local_json  TEXT,
      server_json TEXT,
      seen_at     TEXT NOT NULL,
      resolved    INTEGER NOT NULL DEFAULT 0
    );
  `);
}

/**
 * Legt/aktualisiert die Spiegel-Tabellen anhand des /meta-Ergebnisses an.
 * Neue Spalten werden per ALTER TABLE nachgezogen; bestehende Daten bleiben.
 */
function applyMeta(db, meta) {
  ensureAdminTables(db);
  const tables = meta && meta.tables ? meta.tables : {};
  for (const [slug, def] of Object.entries(tables)) {
    assertIdent(slug, 'Tabellenname');
    const pk = assertIdent(def.pk || 'id', 'Primärschlüssel');
    const cols = (def.columns || []).map((c) => assertIdent(c, 'Spaltenname'));
    if (!cols.includes(pk)) cols.unshift(pk);

    const existing = db
      .prepare(`SELECT name FROM sqlite_master WHERE type='table' AND name = ?`)
      .get(slug);

    if (!existing) {
      const colDefs = cols.map((c) => (c === pk ? `"${c}" TEXT PRIMARY KEY` : `"${c}" TEXT`));
      db.exec(`CREATE TABLE "${slug}" (${colDefs.join(', ')})`);
    } else {
      const have = new Set(db.prepare(`PRAGMA table_info("${slug}")`).all().map((r) => r.name));
      for (const c of cols) {
        if (!have.has(c)) db.exec(`ALTER TABLE "${slug}" ADD COLUMN "${c}" TEXT`);
      }
    }
  }
  db.prepare(`INSERT INTO _meta(key,value) VALUES('api_meta_json',?)
              ON CONFLICT(key) DO UPDATE SET value=excluded.value`).run(JSON.stringify(meta));
}

function getMeta(db, key, fallback = null) {
  const row = db.prepare(`SELECT value FROM _meta WHERE key = ?`).get(key);
  return row ? row.value : fallback;
}

function setMeta(db, key, value) {
  db.prepare(`INSERT INTO _meta(key,value) VALUES(?,?)
              ON CONFLICT(key) DO UPDATE SET value=excluded.value`).run(key, String(value));
}

function loadApiMeta(db) {
  const raw = getMeta(db, 'api_meta_json');
  return raw ? JSON.parse(raw) : null;
}

module.exports = { ensureAdminTables, applyMeta, getMeta, setMeta, loadApiMeta, assertIdent };
