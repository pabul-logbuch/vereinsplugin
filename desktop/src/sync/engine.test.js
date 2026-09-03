'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

let Database;
try {
  Database = require('better-sqlite3');
  new Database(':memory:').close(); // native Binary wirklich laden (ABI-Probe)
} catch (e) {
  Database = null;
  const why = /NODE_MODULE_VERSION/.test(String(e && e.message))
    ? 'better-sqlite3 ist für die Electron-ABI gebaut – für Node-Tests: `npm rebuild better-sqlite3`, danach `npm run rebuild` für Electron'
    : 'better-sqlite3 nicht verfügbar';
  test(`engine-Tests übersprungen (${why})`, { skip: true }, () => {});
}

if (Database) {
  const engine = require('./engine');
  const { rowRev } = require('./revision');

  const META = {
    server_time: '2026-01-01 00:00:00',
    tables: {
      jb_budgets: { pk: 'id', time_col: 'erstellt_am', columns: ['id', 'zweck', 'betrag', 'erstellt_am'] },
      jb_auslagen: { pk: 'id', time_col: 'eingereicht_am', columns: ['id', 'betrag', 'beschreibung', 'budget_id', 'eingereicht_am'] },
    },
  };

  const srvRow = (o) => ({ ...o, _rev: rowRev(o) });

  function fakeClient(overrides = {}) {
    return {
      async meta() {
        return META;
      },
      async snapshot(since) {
        return this._snap || { server_time: '2026-01-02 00:00:00', tables: {} };
      },
      async mutations(muts) {
        return this._mut ? this._mut(muts) : { server_time: 't', applied: [], conflicts: [], errors: [] };
      },
      ...overrides,
    };
  }

  const freshDb = () => new Database(':memory:');

  test('pull spiegelt Zeilen und setzt row_state', async () => {
    const db = freshDb();
    const client = fakeClient();
    client._snap = {
      server_time: '2026-01-02 00:00:00',
      tables: {
        jb_budgets: { full: true, rows: [srvRow({ id: '1', zweck: 'Sommerfest', betrag: '500.00', erstellt_am: '2026-01-01 10:00:00' })] },
      },
    };
    const r = await engine.pull(db, client);
    assert.equal(r.upserted, 1);
    const row = db.prepare('SELECT * FROM jb_budgets WHERE id = ?').get('1');
    assert.equal(row.zweck, 'Sommerfest');
    const st = db.prepare('SELECT * FROM _row_state WHERE tbl = ? AND pk = ?').get('jb_budgets', '1');
    assert.ok(st.base_rev);
    assert.equal(st.dirty, 0);
  });

  test('lokale Änderung erzeugt Mutation; push übernimmt sie', async () => {
    const db = freshDb();
    const client = fakeClient();
    const base = srvRow({ id: '1', zweck: 'Sommerfest', betrag: '500.00', erstellt_am: '2026-01-01 10:00:00' });
    client._snap = { server_time: '2026-01-02 00:00:00', tables: { jb_budgets: { rows: [base] } } };
    await engine.pull(db, client);

    engine.saveRow(db, 'jb_budgets', '1', { betrag: '650.00' });
    assert.equal(db.prepare('SELECT COUNT(*) n FROM _pending').get().n, 1);
    assert.equal(db.prepare('SELECT dirty FROM _row_state WHERE tbl=? AND pk=?').get('jb_budgets', '1').dirty, 1);

    client._mut = (muts) => {
      assert.equal(muts.length, 1);
      assert.equal(muts[0].fields.betrag, '650.00');
      return { server_time: 't', applied: [{ cid: muts[0].cid, table: 'jb_budgets', pk: 1, new_rev: 'deadbeef' }], conflicts: [], errors: [] };
    };
    const p = await engine.push(db, client);
    assert.equal(p.applied, 1);
    assert.equal(db.prepare('SELECT COUNT(*) n FROM _pending').get().n, 0);
    const st = db.prepare('SELECT * FROM _row_state WHERE tbl=? AND pk=?').get('jb_budgets', '1');
    assert.equal(st.dirty, 0);
    assert.equal(st.base_rev, 'deadbeef');
  });

  test('gegenseitige Änderung -> Konflikt bei pull', async () => {
    const db = freshDb();
    const client = fakeClient();
    const base = srvRow({ id: '1', zweck: 'Sommerfest', betrag: '500.00', erstellt_am: '2026-01-01 10:00:00' });
    client._snap = { server_time: '2026-01-02 00:00:00', tables: { jb_budgets: { rows: [base] } } };
    await engine.pull(db, client);
    engine.saveRow(db, 'jb_budgets', '1', { betrag: '650.00' });

    client._snap = {
      server_time: '2026-01-03 00:00:00',
      tables: { jb_budgets: { rows: [srvRow({ id: '1', zweck: 'Sommerfest', betrag: '900.00', erstellt_am: '2026-01-01 10:00:00' })] } },
    };
    const r = await engine.pull(db, client);
    assert.equal(r.conflicts, 1);
    const cs = engine.listConflicts(db);
    assert.equal(cs.length, 1);
    assert.equal(cs[0].server.betrag, '900.00');

    engine.resolveConflict(db, cs[0].id, 'server');
    assert.equal(engine.listConflicts(db).length, 0);
    assert.equal(db.prepare('SELECT betrag FROM jb_budgets WHERE id=?').get('1').betrag, '900.00');
  });

  test('createRow: temporärer PK, push remappt PK und Kind-FK', async () => {
    const db = freshDb();
    const client = fakeClient();
    client._snap = { server_time: '2026-01-02 00:00:00', tables: {} };
    await engine.pull(db, client);

    const tmpBudget = engine.createRow(db, 'jb_budgets', { zweck: 'Neu', betrag: '100.00' });
    assert.ok(Number(tmpBudget) < 0);
    const tmpAuslage = engine.createRow(db, 'jb_auslagen', { betrag: '20.00', beschreibung: 'Kabel', budget_id: tmpBudget });
    assert.ok(Number(tmpAuslage) < 0);

    client._mut = (muts) => {
      // Erster Durchlauf: beide temporär -> Server vergibt echte IDs.
      const applied = muts.map((m, i) => ({
        cid: m.cid,
        table: m.table,
        pk: m.pk,
        new_pk: m.table === 'jb_budgets' ? 77 : 88,
        new_rev: 'r' + i,
      }));
      return { server_time: 't', applied, conflicts: [], errors: [] };
    };
    const p = await engine.push(db, client);
    assert.equal(p.applied, 2);
    assert.equal(db.prepare('SELECT COUNT(*) n FROM jb_budgets WHERE id=?').get('77').n, 1);
    const aus = db.prepare('SELECT * FROM jb_auslagen WHERE id=?').get('88');
    assert.equal(aus.budget_id, '77'); // Kind-FK wurde mitgezogen
    assert.equal(db.prepare('SELECT COUNT(*) n FROM _pending').get().n, 0);
  });

  test('reconcile findet serverseitig gelöschte Zeile', async () => {
    const db = freshDb();
    const client = fakeClient();
    client._snap = {
      server_time: '2026-01-02 00:00:00',
      tables: { jb_budgets: { rows: [srvRow({ id: '1', zweck: 'A', betrag: '1', erstellt_am: 'x' }), srvRow({ id: '2', zweck: 'B', betrag: '2', erstellt_am: 'y' })] } },
    };
    await engine.pull(db, client);

    client._snap = {
      server_time: '2026-01-03 00:00:00',
      tables: { jb_budgets: { rows: [srvRow({ id: '1', zweck: 'A', betrag: '1', erstellt_am: 'x' })] } },
    };
    const cands = await engine.reconcile(db, client);
    assert.equal(cands.length, 1);
    assert.equal(cands[0].pk, '2');
    engine.applyReconcileDeletions(db, cands);
    assert.equal(db.prepare('SELECT COUNT(*) n FROM jb_budgets').get().n, 1);
  });
}
