# Vereinssync (Desktop)

Offline-Desktop-App, die alle Vereinsdaten aus **WordPress** (Vereinsplugin) und
**Nextcloud** lokal spiegelt, offline bearbeitbar macht und Änderungen später
zurücksynchronisiert. Konflikte (beide Seiten geändert) werden erkannt und dir zur
Entscheidung vorgelegt – nichts wird still überschrieben, nichts automatisch gelöscht.

Technik: Electron + lokale SQLite-Datenbank (`better-sqlite3`). Kein Server nötig.

---

## Einrichten

### 1. Im WordPress (einmalig)

Das Plugin **ab v0.7.0** bringt die Sync-API mit (`vereinsplugin/includes/rest-sync-api.php`).
Nichts weiter zu konfigurieren – es reicht ein Benutzer mit dem Recht
`vp_manage_members` oder `manage_options` (Vorstand/Admin).

Dieser Benutzer legt sich ein **Application Password** an:
`WordPress → Benutzer → Profil → „Anwendungspasswörter"` → Name z. B. „Vereinssync
Laptop" → *Neues Anwendungspasswort hinzufügen*. Das angezeigte Passwort (Form
`xxxx xxxx xxxx xxxx xxxx xxxx`) einmalig kopieren.

### 2. App starten (Entwicklung)

```bash
cd desktop
npm install
npm run rebuild      # better-sqlite3 gegen die Electron-Version bauen (einmalig / nach Update)
npm start
```

Beim ersten Start: **Einstellungen** ausfüllen
- WordPress-URL (nur die Basisadresse, z. B. `https://verein.example`)
- Benutzername
- Application Password

→ *Verbindung testen* → *Synchronisieren*.

### 3. Installer bauen (optional)

```bash
npm run dist:mac     # .dmg   (bzw. npm run dist für die aktuelle Plattform)
```

---

## Bedienung

| Bereich | |
|---|---|
| **Übersicht** | Letzter Sync, offene Änderungen, Datenbestand |
| **Bearbeiten** | Mitglieder, Anträge, Auslagen, Buchungsjournal, Budgets, Rücklagen, Kontenplan – offline änderbar |
| **Nur lesen** | Protokolle, Gremien, Aufgaben, Termine, Wünsche, Schichten … (Spiegel) |
| **Konflikte** | Beide Seiten geändert → „Meine übernehmen" / „Server übernehmen" |
| **Nextcloud** | Benutzer/Gruppen-Sync auf dem Server anstoßen (mit Testlauf) |
| **Synchronisieren** | Holen (Pull) + Senden (Push) in einem Schritt |
| **Vollabgleich** | Prüft, welche Zeilen serverseitig gelöscht wurden, und entfernt sie nach Rückfrage lokal |

Lokal geänderte, noch nicht gesendete Zeilen sind in Listen links **orange** markiert.

---

## Sicherheit / Datenschutz

- Die lokale Datenbank (`vereinssync.sqlite` im App-Datenordner) enthält **auch
  sensible Mitgliederdaten** (IBAN, SEPA-Mandat, Geburtsdatum). Sie ist **nicht
  verschlüsselt**. → **FileVault (macOS) bzw. BitLocker (Windows) muss aktiv sein.**
- Die WordPress-Zugangsdaten liegen über die OS-Schlüsselverwaltung verschlüsselt
  (`safeStorage`). Nur wenn das OS keine Verschlüsselung anbietet, wird als
  Notlösung Klartext gespeichert.
- Die App spricht **nur mit deiner WordPress-Seite** (HTTPS). Der Nextcloud-Zugang
  bleibt serverseitig – die App braucht ihn nie.
- Dateipfad des Datenordners: siehe *Einstellungen → Hinweise* bzw.
  `~/Library/Application Support/Vereinssync/` (macOS).

---

## Grenzen dieser Version (Phase 1)

- **Bearbeiten** nur für Mitglieder + Buchhaltungs-Tabellen. `pp_*` / `wl_*` sind
  Nur-Lese-Spiegel (kommt in Phase 2).
- **Löschungen vom Server** erkennt nur der *Vollabgleich* (kein Soft-Delete in den
  Plugin-Tabellen). Zwischen zwei Vollabgleichen „lebt" eine gelöschte Zeile lokal
  weiter.
- **Neue verknüpfte Datensätze** (z. B. neues Budget + neue Auslage darauf) werden
  beim Push mit temporären IDs angelegt und danach auf die echten Server-IDs
  umgeschrieben – umgesetzt für die Buchhaltungs-Fremdschlüssel
  (`jb_auslagen.budget_id/buchung_id`, `jb_buchungen.auslage_id`,
  `jb_getraenke_bewegungen.produkt_id`).
- **Beleg-Dateien** (Nextcloud/WebDAV) werden noch nicht in der Oberfläche
  hoch-/heruntergeladen; die Server-Endpunkte dafür existieren bereits.

---

## Tests

```bash
npm test         # revision-Hash (== PHP crc32b); Engine-Tests laufen nur mit Node-ABI-Build
npm run test:full # baut better-sqlite3 kurz für Node, testet alles, baut zurück für Electron
```

> `better-sqlite3` ist ein natives Modul. `npm start`/`npm run dist` brauchen den
> Electron-Build (`npm run rebuild`), die vollständigen Engine-Tests den Node-Build.
> `npm run test:full` erledigt beides.

## Aufbau

```
main.js              Electron-Hauptprozess: Fenster, IPC, SQLite, Keychain
preload.js           contextBridge → window.api
src/sync/revision.js Zeilen-Hash, identisch zu vp_sync_rev() im Plugin
src/sync/client.js   REST-Client für vereinsplugin/v1
src/sync/schema.js   lokales SQLite-Schema aus /meta
src/sync/engine.js   pull / push / reconcile / Konflikte / lokale Mutationen
src/ui/              Renderer (Vanilla JS): Navigation, Tabellen, Formulare, Konflikte
```
