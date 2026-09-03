'use strict';

/**
 * Sync-Kern. Reine Logik gegen zwei injizierte Abhängigkeiten:
 *   db     – ein better-sqlite3-Handle (oder kompatibel)
 *   client – eine SyncClient-Instanz (oder ein Fake mit denselben Methoden)
 *
 * So bleibt der Kern ohne Electron/Netz testbar (siehe *.test.js).
 */

const { rowRev } = require('./revision');
const { applyMeta, getMeta, setMeta } = require('./schema');

const SAFETY_SECONDS = 120; // Delta-Fenster großzügig überlappen (Zeitzonen-Puffer)

/* Bekannte Fremdschlüssel (nur die in Phase 1 beschreibbaren Tabellen). */
const FK = {
  jb_auslagen: { budget_id: 'jb_budgets', buchung_id: 'jb_buchungen' },
  jb_buchungen: { auslage_id: 'jb_auslagen' },
  jb_getraenke_bewegungen: { produkt_id: 'jb_getraenke' },
};

const now = () => new Date().toISOString().replace('T', ' ').replace(/\.\d+Z$/, '');
const uuid = () =>
  'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

/* ------------------------------------------------------------------ helpers */

function tableExists(db, name) {
  return !!db
    .prepare(`SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?`)
    .get(name);
}

function pkColOf(db, slug) {
  const meta = JSON.parse(getMeta(db, 'api_meta_json') || '{}');
  return (meta.tables && meta.tables[slug] && meta.tables[slug].pk) || 'id';
}

function columnsOf(db, slug) {
  return db.prepare(`PRAGMA table_info("${slug}")`).all().map((r) => r.name);
}

function localRow(db, slug, pkCol, pkVal) {
  return db.prepare(`SELECT * FROM "${slug}" WHERE "${pkCol}" = ?`).get(String(pkVal)) || null;
}

function rowState(db, slug, pkVal) {
  return db.prepare(`SELECT * FROM _row_state WHERE tbl = ? AND pk = ?`).get(slug, String(pkVal)) || null;
}

function setRowState(db, slug, pkVal, patch) {
  const cur = rowState(db, slug, pkVal) || { base_rev: '', dirty: 0, deleted: 0 };
  const next = { ...cur, ...patch, updated_at: now() };
  db.prepare(
    `INSERT INTO _row_state(tbl,pk,base_rev,dirty,deleted,updated_at)
     VALUES(@tbl,@pk,@base_rev,@dirty,@deleted,@updated_at)
     ON CONFLICT(tbl,pk) DO UPDATE SET
       base_rev=excluded.base_rev, dirty=excluded.dirty,
       deleted=excluded.deleted, updated_at=excluded.updated_at`
  ).run({
    tbl: slug,
    pk: String(pkVal),
    base_rev: next.base_rev || '',
    dirty: next.dirty ? 1 : 0,
    deleted: next.deleted ? 1 : 0,
    updated_at: next.updated_at,
  });
}

function upsertLocal(db, slug, row, pkCol) {
  const cols = columnsOf(db, slug).filter((c) => c in row);
  if (!cols.includes(pkCol)) cols.push(pkCol);
  const placeholders = cols.map((c) => `@${c}`).join(',');
  const updates = cols.filter((c) => c !== pkCol).map((c) => `"${c}"=excluded."${c}"`).join(',');
  const data = {};
  for (const c of cols) data[c] = row[c] === undefined ? null : row[c];
  db.prepare(
    `INSERT INTO "${slug}" (${cols.map((c) => `"${c}"`).join(',')})
     VALUES (${placeholders})
     ON CONFLICT("${pkCol}") DO UPDATE SET ${updates || `"${pkCol}"="${pkCol}"`}`
  ).run(data);
}

function nextTempPk(db, slug, pkCol) {
  const row = db.prepare(`SELECT MIN(CAST("${pkCol}" AS INTEGER)) AS m FROM "${slug}"`).get();
  const min = row && row.m !== null ? Number(row.m) : 0;
  return String(Math.min(0, min) - 1);
}

function openConflict(db, slug, pkVal, local, server) {
  const existing = db
    .prepare(`SELECT id FROM _conflicts WHERE tbl = ? AND pk = ? AND resolved = 0`)
    .get(slug, String(pkVal));
  const payload = {
    local_json: local ? JSON.stringify(local) : null,
    server_json: server ? JSON.stringify(server) : null,
    seen_at: now(),
  };
  if (existing) {
    db.prepare(`UPDATE _conflicts SET local_json=?, server_json=?, seen_at=? WHERE id=?`).run(
      payload.local_json,
      payload.server_json,
      payload.seen_at,
      existing.id
    );
  } else {
    db.prepare(
      `INSERT INTO _conflicts(tbl,pk,local_json,server_json,seen_at) VALUES(?,?,?,?,?)`
    ).run(slug, String(pkVal), payload.local_json, payload.server_json, payload.seen_at);
  }
}

/* -------------------------------------------------------------------- pull  */

async function pull(db, client) {
  const meta = await client.meta();
  applyMeta(db, meta);

  let since = getMeta(db, 'last_pull_iso', '') || '';
  if (since) {
    const t = Date.parse(since.replace(' ', 'T') + 'Z');
    if (!Number.isNaN(t)) since = new Date(t - SAFETY_SECONDS * 1000).toISOString().replace('T', ' ').replace(/\.\d+Z$/, '');
  }

  const snap = await client.snapshot(since);
  const counts = { upserted: 0, conflicts: 0, tables: 0 };

  const tx = db.transaction(() => {
    for (const [slug, payload] of Object.entries(snap.tables || {})) {
      if (!tableExists(db, slug)) continue;
      counts.tables++;
      const pkCol = pkColOf(db, slug);
      for (const raw of payload.rows || []) {
        const row = { ...raw };
        const serverRev = row._rev || rowRev(row);
        delete row._rev;
        const pkVal = String(row[pkCol]);
        const st = rowState(db, slug, pkVal);

        if (st && st.dirty && st.base_rev && st.base_rev !== serverRev) {
          openConflict(db, slug, pkVal, localRow(db, slug, pkCol, pkVal), { ...row, _rev: serverRev });
          counts.conflicts++;
          continue;
        }
        upsertLocal(db, slug, row, pkCol);
        setRowState(db, slug, pkVal, { base_rev: serverRev, dirty: 0, deleted: 0 });
        counts.upserted++;
      }
    }
    setMeta(db, 'last_pull_iso', snap.server_time || now());
  });
  tx();
  return counts;
}

/* -------------------------------------------------------------------- push  */

function remapPk(db, slug, oldPk, newPk) {
  const pkCol = pkColOf(db, slug);
  db.prepare(`UPDATE "${slug}" SET "${pkCol}" = ? WHERE "${pkCol}" = ?`).run(newPk, oldPk);
  db.prepare(`UPDATE _row_state SET pk = ? WHERE tbl = ? AND pk = ?`).run(newPk, slug, oldPk);
  db.prepare(`UPDATE _pending SET pk = ? WHERE tbl = ? AND pk = ?`).run(newPk, slug, oldPk);

  // Lokale Kindverweise mitziehen + in noch offenen Mutationen ersetzen.
  for (const [childTbl, map] of Object.entries(FK)) {
    for (const [col, refTbl] of Object.entries(map)) {
      if (refTbl !== slug || !tableExists(db, childTbl)) continue;
      db.prepare(`UPDATE "${childTbl}" SET "${col}" = ? WHERE "${col}" = ?`).run(newPk, oldPk);
      const pend = db.prepare(`SELECT id, fields_json FROM _pending WHERE tbl = ?`).all(childTbl);
      for (const p of pend) {
        const f = JSON.parse(p.fields_json || '{}');
        if (String(f[col]) === String(oldPk)) {
          f[col] = newPk;
          db.prepare(`UPDATE _pending SET fields_json = ? WHERE id = ?`).run(JSON.stringify(f), p.id);
        }
      }
    }
  }
}

async function push(db, client, { maxPasses = 3 } = {}) {
  const total = { applied: 0, conflicts: 0, errors: 0, passes: 0 };

  for (let pass = 0; pass < maxPasses; pass++) {
    const rows = db.prepare(`SELECT * FROM _pending ORDER BY id`).all();
    if (!rows.length) break;
    total.passes++;

    const muts = rows.map((r) => ({
      cid: r.cid,
      table: r.tbl,
      op: r.op,
      pk: Number(r.pk) < 0 ? 0 : Number(r.pk),
      base_rev: r.base_rev || '',
      fields: JSON.parse(r.fields_json || '{}'),
    }));

    const res = await client.mutations(muts);
    let remapped = false;

    const tx = db.transaction(() => {
      for (const a of res.applied || []) {
        const pend = rows.find((x) => x.cid === a.cid);
        if (!pend) continue;
        let pkStr = pend.pk;
        if (a.new_pk && Number(pend.pk) < 0) {
          remapPk(db, pend.tbl, pend.pk, String(a.new_pk));
          pkStr = String(a.new_pk);
          remapped = true;
        }
        if (pend.op === 'delete') {
          const pkCol = pkColOf(db, pend.tbl);
          db.prepare(`DELETE FROM "${pend.tbl}" WHERE "${pkCol}" = ?`).run(pkStr);
          db.prepare(`DELETE FROM _row_state WHERE tbl = ? AND pk = ?`).run(pend.tbl, pkStr);
        } else {
          setRowState(db, pend.tbl, pkStr, { base_rev: a.new_rev || '', dirty: 0, deleted: 0 });
        }
        db.prepare(`DELETE FROM _pending WHERE id = ?`).run(pend.id);
        total.applied++;
      }

      for (const c of res.conflicts || []) {
        const pend = rows.find((x) => x.cid === c.cid);
        const slug = c.table;
        const pkVal = pend ? pend.pk : String(c.pk);
        const pkCol = pkColOf(db, slug);
        openConflict(db, slug, pkVal, localRow(db, slug, pkCol, pkVal), c.server_row || null);
        if (pend) db.prepare(`DELETE FROM _pending WHERE id = ?`).run(pend.id);
        total.conflicts++;
      }

      for (const e of res.errors || []) {
        const pend = rows.find((x) => x.cid === e.cid);
        if (pend) {
          db.prepare(`UPDATE _pending SET tries = tries + 1, last_error = ? WHERE id = ?`).run(
            e.message || 'Fehler',
            pend.id
          );
        }
        total.errors++;
      }
    });
    tx();

    // Nichts remapped -> ein weiterer Durchlauf brächte nichts Neues.
    if (!remapped) break;
  }
  return total;
}

/* --------------------------------------------------------------- reconcile  */

async function reconcile(db, client) {
  const meta = await client.meta();
  applyMeta(db, meta);
  const snap = await client.snapshot(''); // Vollabzug
  const candidates = [];

  for (const [slug, payload] of Object.entries(snap.tables || {})) {
    if (!tableExists(db, slug)) continue;
    const pkCol = pkColOf(db, slug);
    const serverPks = new Set((payload.rows || []).map((r) => String(r[pkCol])));
    const locals = db.prepare(`SELECT "${pkCol}" AS pk FROM "${slug}"`).all();
    for (const { pk } of locals) {
      const s = String(pk);
      if (serverPks.has(s)) continue;
      if (Number(s) < 0) continue; // lokal neu, noch nie gepusht
      const st = rowState(db, slug, s);
      if (st && st.dirty) continue; // lokale ungespeicherte Änderung -> nicht anfassen
      candidates.push({ tbl: slug, pk: s, row: localRow(db, slug, pkCol, s) });
    }
  }
  setMeta(db, 'last_reconcile_iso', snap.server_time || now());
  return candidates;
}

function applyReconcileDeletions(db, items) {
  const tx = db.transaction(() => {
    for (const it of items || []) {
      const pkCol = pkColOf(db, it.tbl);
      db.prepare(`DELETE FROM "${it.tbl}" WHERE "${pkCol}" = ?`).run(String(it.pk));
      db.prepare(`DELETE FROM _row_state WHERE tbl = ? AND pk = ?`).run(it.tbl, String(it.pk));
    }
  });
  tx();
  return { deleted: (items || []).length };
}

/* ---------------------------------------------------------- local mutations */

function enqueue(db, { tbl, op, pk, fields }) {
  const cid = uuid();
  db.prepare(
    `INSERT INTO _pending(cid,tbl,op,pk,base_rev,fields_json,created_at)
     VALUES(?,?,?,?,?,?,?)`
  ).run(
    cid,
    tbl,
    op,
    String(pk),
    (rowState(db, tbl, pk) || {}).base_rev || '',
    JSON.stringify(fields || {}),
    now()
  );
  return cid;
}

/** Bestehende Zeile ändern (UI-Formular). */
function saveRow(db, slug, pkVal, fields) {
  const pkCol = pkColOf(db, slug);
  const clean = { ...fields };
  delete clean[pkCol];
  delete clean._rev;

  const tx = db.transaction(() => {
    const set = Object.keys(clean).map((c) => `"${c}" = @${c}`).join(', ');
    if (set) db.prepare(`UPDATE "${slug}" SET ${set} WHERE "${pkCol}" = @__pk`).run({ ...clean, __pk: String(pkVal) });
    setRowState(db, slug, pkVal, { dirty: 1 });

    // Offene, noch nicht gepushte Upsert-Mutation für dieselbe Zeile zusammenführen.
    const open = db
      .prepare(`SELECT id, fields_json FROM _pending WHERE tbl = ? AND pk = ? AND op = 'upsert' ORDER BY id DESC LIMIT 1`)
      .get(slug, String(pkVal));
    if (open) {
      const merged = { ...JSON.parse(open.fields_json || '{}'), ...clean };
      db.prepare(`UPDATE _pending SET fields_json = ? WHERE id = ?`).run(JSON.stringify(merged), open.id);
    } else {
      enqueue(db, { tbl: slug, op: 'upsert', pk: pkVal, fields: clean });
    }
  });
  tx();
}

/** Neue Zeile anlegen (temporärer negativer PK bis zum Push). */
function createRow(db, slug, fields) {
  const pkCol = pkColOf(db, slug);
  const temp = nextTempPk(db, slug, pkCol);
  const clean = { ...fields };
  delete clean._rev;
  clean[pkCol] = temp;

  const tx = db.transaction(() => {
    upsertLocal(db, slug, clean, pkCol);
    setRowState(db, slug, temp, { base_rev: '', dirty: 1, deleted: 0 });
    enqueue(db, { tbl: slug, op: 'upsert', pk: temp, fields: (() => { const f = { ...clean }; delete f[pkCol]; return f; })() });
  });
  tx();
  return temp;
}

function deleteRow(db, slug, pkVal) {
  const tx = db.transaction(() => {
    if (Number(pkVal) < 0) {
      // War nur lokal – Mutation + Zeile entfernen.
      const pkCol = pkColOf(db, slug);
      db.prepare(`DELETE FROM "${slug}" WHERE "${pkCol}" = ?`).run(String(pkVal));
      db.prepare(`DELETE FROM _pending WHERE tbl = ? AND pk = ?`).run(slug, String(pkVal));
      db.prepare(`DELETE FROM _row_state WHERE tbl = ? AND pk = ?`).run(slug, String(pkVal));
      return;
    }
    setRowState(db, slug, pkVal, { dirty: 1, deleted: 1 });
    enqueue(db, { tbl: slug, op: 'delete', pk: pkVal, fields: {} });
  });
  tx();
}

/* --------------------------------------------------------------- conflicts  */

function listConflicts(db) {
  return db
    .prepare(`SELECT * FROM _conflicts WHERE resolved = 0 ORDER BY id`)
    .all()
    .map((c) => ({
      id: c.id,
      tbl: c.tbl,
      pk: c.pk,
      seen_at: c.seen_at,
      local: c.local_json ? JSON.parse(c.local_json) : null,
      server: c.server_json ? JSON.parse(c.server_json) : null,
    }));
}

/**
 * @param {'server'|'local'} choice
 * @param {object} [mergedFields] bei 'local' optional feldweise überschrieben
 */
function resolveConflict(db, id, choice, mergedFields) {
  const c = db.prepare(`SELECT * FROM _conflicts WHERE id = ?`).get(id);
  if (!c) return { ok: false, error: 'Konflikt nicht gefunden.' };
  const slug = c.tbl;
  const pkCol = pkColOf(db, slug);
  const server = c.server_json ? JSON.parse(c.server_json) : null;
  const local = c.local_json ? JSON.parse(c.local_json) : null;

  const tx = db.transaction(() => {
    db.prepare(`DELETE FROM _pending WHERE tbl = ? AND pk = ?`).run(slug, String(c.pk));

    if (choice === 'server') {
      if (server) {
        const row = { ...server };
        const rev = row._rev || rowRev(row);
        delete row._rev;
        upsertLocal(db, slug, row, pkCol);
        setRowState(db, slug, c.pk, { base_rev: rev, dirty: 0, deleted: 0 });
      } else {
        // Server hat die Zeile gelöscht.
        db.prepare(`DELETE FROM "${slug}" WHERE "${pkCol}" = ?`).run(String(c.pk));
        db.prepare(`DELETE FROM _row_state WHERE tbl = ? AND pk = ?`).run(slug, String(c.pk));
      }
    } else {
      // 'local': eigene Version durchsetzen, aber auf Basis der Server-Revision,
      // damit die nächste Mutation sauber greift.
      const serverRev = server ? server._rev || rowRev({ ...server }) : '';
      const fields = mergedFields || (() => { const f = { ...(local || {}) }; delete f[pkCol]; delete f._rev; return f; })();
      const set = Object.keys(fields).map((k) => `"${k}" = @${k}`).join(', ');
      if (set) db.prepare(`UPDATE "${slug}" SET ${set} WHERE "${pkCol}" = @__pk`).run({ ...fields, __pk: String(c.pk) });
      setRowState(db, slug, c.pk, { base_rev: serverRev, dirty: 1, deleted: 0 });
      db.prepare(
        `INSERT INTO _pending(cid,tbl,op,pk,base_rev,fields_json,created_at) VALUES(?,?,?,?,?,?,?)`
      ).run(uuid(), slug, 'upsert', String(c.pk), serverRev, JSON.stringify(fields), now());
    }
    db.prepare(`UPDATE _conflicts SET resolved = 1 WHERE id = ?`).run(id);
  });
  tx();
  return { ok: true };
}

/* ------------------------------------------------------------------ status  */

function stats(db) {
  const meta = JSON.parse(getMeta(db, 'api_meta_json') || '{}');
  const slugs = Object.keys(meta.tables || {});
  const tables = [];
  for (const slug of slugs) {
    if (!tableExists(db, slug)) continue;
    const count = db.prepare(`SELECT COUNT(*) AS n FROM "${slug}"`).get().n;
    const dirty = db.prepare(`SELECT COUNT(*) AS n FROM _row_state WHERE tbl = ? AND dirty = 1`).get(slug).n;
    tables.push({ slug, count, dirty });
  }
  return {
    last_pull: getMeta(db, 'last_pull_iso', null),
    last_reconcile: getMeta(db, 'last_reconcile_iso', null),
    pending: db.prepare(`SELECT COUNT(*) AS n FROM _pending`).get().n,
    conflicts: db.prepare(`SELECT COUNT(*) AS n FROM _conflicts WHERE resolved = 0`).get().n,
    tables,
  };
}

function listRows(db, slug, { search = '', limit = 500 } = {}) {
  if (!tableExists(db, slug)) return { columns: [], rows: [] };
  const columns = columnsOf(db, slug);
  let sql = `SELECT r.*, s.dirty AS _dirty, s.deleted AS _deleted
             FROM "${slug}" r
             LEFT JOIN _row_state s ON s.tbl = ? AND s.pk = CAST(r."${pkColOf(db, slug)}" AS TEXT)`;
  const args = [slug];
  if (search) {
    const like = columns.map((c) => `r."${c}" LIKE ?`).join(' OR ');
    sql += ` WHERE (${like})`;
    for (let i = 0; i < columns.length; i++) args.push(`%${search}%`);
  }
  sql += ` ORDER BY CAST(r."${pkColOf(db, slug)}" AS INTEGER) DESC LIMIT ?`;
  args.push(limit);
  const rows = db.prepare(sql).all(...args).filter((r) => !r._deleted);
  return { columns, rows };
}

function getRow(db, slug, pkVal) {
  const pkCol = pkColOf(db, slug);
  const row = localRow(db, slug, pkCol, pkVal);
  const st = rowState(db, slug, pkVal);
  return { row, state: st, pkCol };
}

async function syncAll(db, client) {
  const pulled = await pull(db, client);
  const pushed = await push(db, client);
  return { pulled, pushed };
}

module.exports = {
  pull,
  push,
  reconcile,
  applyReconcileDeletions,
  syncAll,
  saveRow,
  createRow,
  deleteRow,
  listConflicts,
  resolveConflict,
  stats,
  listRows,
  getRow,
  // intern für Tests:
  _internal: { setRowState, rowState, upsertLocal, remapPk, nextTempPk },
};
