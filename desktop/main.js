'use strict';

const { app, BrowserWindow, ipcMain, safeStorage, shell } = require('electron');
const path = require('node:path');
const fs = require('node:fs');
const Database = require('better-sqlite3');

const { SyncClient } = require('./src/sync/client');
const engine = require('./src/sync/engine');
const { applyMeta, loadApiMeta } = require('./src/sync/schema');

let db = null;
let win = null;

/* --------------------------------------------------------------- Speicher   */

function userDir() {
  const dir = app.getPath('userData');
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

function openDb() {
  if (db) return db;
  db = new Database(path.join(userDir(), 'vereinssync.sqlite'));
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = OFF');
  require('./src/sync/schema').ensureAdminTables(db);
  return db;
}

/* --------------------------------------------------- Zugangsdaten (Keychain) */

const CRED_FILE = () => path.join(userDir(), 'credentials.bin');

function saveCreds({ baseUrl, user, pass }) {
  const payload = JSON.stringify({ baseUrl, user, pass });
  if (safeStorage.isEncryptionAvailable()) {
    fs.writeFileSync(CRED_FILE(), safeStorage.encryptString(payload));
  } else {
    // Fallback: nur wenn das OS keine Verschlüsselung anbietet.
    fs.writeFileSync(CRED_FILE(), Buffer.from('PLAIN:' + payload, 'utf8'));
  }
}

function loadCreds() {
  try {
    const buf = fs.readFileSync(CRED_FILE());
    if (buf.slice(0, 6).toString() === 'PLAIN:') {
      return JSON.parse(buf.slice(6).toString('utf8'));
    }
    return JSON.parse(safeStorage.decryptString(buf));
  } catch {
    return { baseUrl: '', user: '', pass: '' };
  }
}

function client() {
  return new SyncClient(loadCreds());
}

/* ------------------------------------------------------------------ Fenster  */

function createWindow() {
  win = new BrowserWindow({
    width: 1180,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: 'Vereinssync',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  win.loadFile(path.join(__dirname, 'src', 'ui', 'index.html'));
}

app.whenReady().then(() => {
  openDb();
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

/* -------------------------------------------------------------------- IPC    */

const ok = (data) => ({ ok: true, data });
const fail = (e) => ({ ok: false, error: e && e.message ? e.message : String(e) });

ipcMain.handle('config:get', () => {
  const c = loadCreds();
  return ok({ baseUrl: c.baseUrl, user: c.user, hasPass: !!c.pass });
});

ipcMain.handle('config:set', (_e, cfg) => {
  try {
    const cur = loadCreds();
    saveCreds({
      baseUrl: (cfg.baseUrl || '').trim(),
      user: (cfg.user || '').trim(),
      pass: cfg.pass ? cfg.pass : cur.pass, // leer lassen = altes Passwort behalten
    });
    return ok(true);
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('config:test', async () => {
  try {
    const meta = await client().meta();
    applyMeta(openDb(), meta);
    return ok({ plugin_version: meta.plugin_version, tables: Object.keys(meta.tables || {}).length });
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('me:get', async () => {
  try {
    return ok(await client().me());
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('report:summary', async (_e, { year } = {}) => {
  try {
    return ok(await client().reportSummary(year));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('report:salden', async () => {
  try {
    return ok(await client().salden());
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('report:kontenblatt', async (_e, { konto }) => {
  try {
    return ok(await client().kontenblatt(konto));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('action:run', async (_e, { name, body }) => {
  try {
    return ok(await client().action(name, body));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('action:auslage-einreichen', async (_e, { fields, file }) => {
  try {
    return ok(await client().auslageEinreichen(fields, file));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('sync:run', async () => {
  try {
    return ok(await engine.syncAll(openDb(), client()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('sync:pull', async () => {
  try {
    return ok(await engine.pull(openDb(), client()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('sync:push', async () => {
  try {
    return ok(await engine.push(openDb(), client()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('sync:reconcile', async () => {
  try {
    return ok(await engine.reconcile(openDb(), client()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('sync:reconcile:apply', (_e, items) => {
  try {
    return ok(engine.applyReconcileDeletions(openDb(), items));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:stats', () => {
  try {
    return ok(engine.stats(openDb()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:meta', () => ok(loadApiMeta(openDb())));

ipcMain.handle('data:rows', (_e, { slug, search, limit }) => {
  try {
    return ok(engine.listRows(openDb(), slug, { search, limit }));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:row', (_e, { slug, pk }) => {
  try {
    return ok(engine.getRow(openDb(), slug, pk));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:save', (_e, { slug, pk, fields }) => {
  try {
    engine.saveRow(openDb(), slug, pk, fields);
    return ok(true);
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:create', (_e, { slug, fields }) => {
  try {
    return ok({ pk: engine.createRow(openDb(), slug, fields) });
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('data:delete', (_e, { slug, pk }) => {
  try {
    engine.deleteRow(openDb(), slug, pk);
    return ok(true);
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('conflicts:list', () => {
  try {
    return ok(engine.listConflicts(openDb()));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('conflicts:resolve', (_e, { id, choice, fields }) => {
  try {
    return ok(engine.resolveConflict(openDb(), id, choice, fields));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('nc:users', async () => {
  try {
    return ok(await client().ncUsers());
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('nc:groups', async () => {
  try {
    return ok(await client().ncGroups());
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('nc:sync', async (_e, { dry }) => {
  try {
    return ok(await client().ncSync(dry));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('nc:beleg', async (_e, { path }) => {
  try {
    return ok(await client().ncBelegUrl(path));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('nc:beleg-upload', async (_e, { path, file, meta }) => {
  try {
    if (!file || !file.buffer) throw new Error('Keine Datei übergeben.');
    return ok(await client().ncBelegUpload(path || '', file.buffer, file.name, meta || {}));
  } catch (e) {
    return fail(e);
  }
});

ipcMain.handle('app:info', () => ok({ version: app.getVersion(), userData: userDir() }));

ipcMain.handle('app:open-external', (_e, { url }) => {
  try {
    if (url && /^https?:\/\//i.test(url)) shell.openExternal(url);
    return ok(true);
  } catch (e) {
    return fail(e);
  }
});
