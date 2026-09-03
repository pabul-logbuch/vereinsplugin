'use strict';

const { contextBridge, ipcRenderer } = require('electron');

const invoke = (ch, payload) => ipcRenderer.invoke(ch, payload);

contextBridge.exposeInMainWorld('api', {
  config: {
    get: () => invoke('config:get'),
    set: (cfg) => invoke('config:set', cfg),
    test: () => invoke('config:test'),
  },
  me: () => invoke('me:get'),
  report: {
    summary: (year) => invoke('report:summary', { year }),
    salden: () => invoke('report:salden'),
    kontenblatt: (konto) => invoke('report:kontenblatt', { konto }),
  },
  action: {
    run: (name, body) => invoke('action:run', { name, body }),
    auslageEinreichen: (fields, file) => invoke('action:auslage-einreichen', { fields, file }),
  },
  sync: {
    run: () => invoke('sync:run'),
    pull: () => invoke('sync:pull'),
    push: () => invoke('sync:push'),
    reconcile: () => invoke('sync:reconcile'),
    reconcileApply: (items) => invoke('sync:reconcile:apply', items),
  },
  data: {
    stats: () => invoke('data:stats'),
    meta: () => invoke('data:meta'),
    rows: (slug, opts = {}) => invoke('data:rows', { slug, ...opts }),
    row: (slug, pk) => invoke('data:row', { slug, pk }),
    save: (slug, pk, fields) => invoke('data:save', { slug, pk, fields }),
    create: (slug, fields) => invoke('data:create', { slug, fields }),
    remove: (slug, pk) => invoke('data:delete', { slug, pk }),
  },
  conflicts: {
    list: () => invoke('conflicts:list'),
    resolve: (id, choice, fields) => invoke('conflicts:resolve', { id, choice, fields }),
  },
  nc: {
    users: () => invoke('nc:users'),
    groups: () => invoke('nc:groups'),
    sync: (dry) => invoke('nc:sync', { dry }),
    beleg: (path) => invoke('nc:beleg', { path }),
  },
  app: {
    info: () => invoke('app:info'),
    openExternal: (url) => invoke('app:open-external', { url }),
  },
});
