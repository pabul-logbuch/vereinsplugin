'use strict';

/**
 * REST-Client für die Plugin-API `vereinsplugin/v1`.
 * Auth: WordPress Application Password über HTTPS Basic-Auth.
 */

class SyncClient {
  /** @param {{baseUrl:string, user:string, pass:string}} cfg */
  constructor(cfg) {
    this.baseUrl = String(cfg.baseUrl || '').replace(/\/+$/, '');
    this.user = cfg.user || '';
    this.pass = cfg.pass || '';
  }

  _url(path) {
    return `${this.baseUrl}/wp-json/vereinsplugin/v1${path}`;
  }

  _headers(extra) {
    const token = Buffer.from(`${this.user}:${this.pass}`).toString('base64');
    return { Authorization: `Basic ${token}`, Accept: 'application/json', ...(extra || {}) };
  }

  async _req(method, path, { query, json } = {}) {
    let url = this._url(path);
    if (query && Object.keys(query).length) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null && v !== '') qs.set(k, v);
      }
      const s = qs.toString();
      if (s) url += `?${s}`;
    }
    const opts = { method, headers: this._headers(json ? { 'Content-Type': 'application/json' } : {}) };
    if (json !== undefined) opts.body = JSON.stringify(json);

    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new Error(`Netzwerkfehler: ${e.message}`);
    }
    const text = await res.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      /* non-JSON body */
    }
    if (!res.ok) {
      const msg = (data && (data.message || data.code)) || `HTTP ${res.status}`;
      const err = new Error(`API ${method} ${path}: ${msg}`);
      err.status = res.status;
      err.body = data || text;
      throw err;
    }
    return data;
  }

  me() {
    return this._req('GET', '/me');
  }

  meta() {
    return this._req('GET', '/meta');
  }

  reportSummary(year) {
    return this._req('GET', '/report/summary', { query: year ? { year } : {} });
  }

  /** Aktions-Endpunkte: JSON-Body. */
  action(name, body) {
    return this._req('POST', `/actions/${name}`, { json: body || {} });
  }

  /** Auslage einreichen: multipart (Felder + optionale Datei). */
  async auslageEinreichen(fields, file) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(fields || {})) fd.set(k, v == null ? '' : String(v));
    if (file && file.buffer) fd.set('file', new Blob([file.buffer]), file.name || 'beleg');
    const res = await fetch(this._url('/actions/auslage-einreichen'), {
      method: 'POST',
      headers: this._headers(),
      body: fd,
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) throw new Error((data && data.message) || `HTTP ${res.status}`);
    return data;
  }

  /** @param {string} since ISO8601 oder '' für Vollabzug */
  snapshot(since, tables) {
    return this._req('GET', '/snapshot', { query: { since: since || '', tables: tables || '' } });
  }

  /** @param {Array} mutations */
  mutations(mutations) {
    return this._req('POST', '/mutations', { json: { mutations } });
  }

  ncUsers() {
    return this._req('GET', '/nextcloud/users');
  }

  ncGroups() {
    return this._req('GET', '/nextcloud/groups');
  }

  ncSync(dry) {
    return this._req('POST', '/nextcloud/sync', { json: { dry: !!dry } });
  }

  ncBelegUrl(path) {
    return this._req('GET', '/nextcloud/beleg', { query: { path } });
  }

  async ncBelegUpload(destPath, fileBuffer, filename) {
    const fd = new FormData();
    fd.set('path', destPath);
    fd.set('file', new Blob([fileBuffer]), filename || 'beleg');
    const res = await fetch(this._url('/nextcloud/beleg'), {
      method: 'POST',
      headers: this._headers(),
      body: fd,
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) throw new Error((data && data.message) || `HTTP ${res.status}`);
    return data;
  }
}

module.exports = { SyncClient };
