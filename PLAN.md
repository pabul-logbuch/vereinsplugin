# Vereinsplugin – Architektur & Umsetzungsplan

Zusammenführung von vier eigenständigen WordPress-Plugins zu **einem** Plugin mit
gemeinsamem Kern, einem Mitgliederbereich und minimalem Dashboard-Fußabdruck.

## Ausgangslage

| Alt-Plugin | Version | Prefix | Tabellen | Rolle | Admin-Menüs |
|---|---|---|---|---|---|
| Vereins-Wunschliste | 5.3 | `wl_` | `wl_*` (Wünsche, Links, Voting, Schichten) | legt `wl_mitglied` an | 1 Top + 7 Unterseiten |
| ProtokollPro | 1.7 | `pp_` | `pp_*` (Gremien, Protokolle, TOPs, Aufgaben, Termine, Kommentare) | nutzt `wl_mitglied`, Fallback `pp_mitglied` | 1 Top + 8 Unterseiten |
| JuFo Buchhaltung | 1.0 | `jb_` | `jb_*` (Auslagen, Journal, Budgets, Getränke) | nutzt `wl_mitglied` | 1 Top + 7 Unterseiten |
| Jufobleibt Event Publisher | 0.6 | `jbf_` / `Jbf_` | keine eigenen (CPT `veranstaltung` + Postmeta) | nutzt `wl_mitglied` | 1 Top + CPT-Menü |

**Gemeinsame Klammer heute:** Drei der vier Plugins hängen ihre Capabilities schon
an die Rolle `wl_mitglied` – die Synergie-Basis existiert also. Vier getrennte
CSS/JS-Sets, vier Mitgliederbereiche, vier Kalender-/Aufgabenbegriffe, ~23
Dashboard-Unterseiten.

## Zielbild

1. **Ein Plugin** `vereinsplugin/`, das die vier bisherigen als `modules/` bündelt
   und über einen gemeinsamen Kern bootet.
2. **Ein Mitgliederbereich** `[verein_mitgliederbereich]` – eine Seite, Tab-Navigation
   über alle Module.
3. **Dashboard-Fußabdruck = 1 Seite:** „Verein → Shortcodes“ (Liste aller Shortcodes
   mit Beschreibung). Darunter genau **eine** technische Seite „Einstellungen“.
   Alle Verwaltung läuft über Frontend-Shortcodes.
4. **Datenkompatibilität:** bestehende `wl_*` / `pp_* `/ `jb_*`-Tabellen und die
   Rolle `wl_mitglied` bleiben unverändert. Eine laufende Installation deaktiviert
   die vier Einzel-Plugins und aktiviert dieses – ohne Migration, ohne Datenverlust.
5. **Generisch:** vereinsspezifische Texte/Defaults werden neutralisiert
   („Verein“ statt „Jugendforum Riedlingen“), Prefix des Kerns `vp_`.

## Verzeichnisstruktur

```
vereinsplugin/
  vereinsplugin.php            Bootstrap: Modul-Registry, Laden, Aktivierung
  includes/
    core-roles.php             Rollen-/Capability-Brücke, Backend-Sperre für Mitglieder
    shortcode-registry.php     Single source of truth: Katalog aller Shortcodes
    admin-consolidation.php    Verein-Menü + Shortcode-Seite; Modul-Menüs ausblenden
    member-area.php            [verein_mitgliederbereich], [verein_login], Asset-Shim
    settings-page.php          Die eine Einstellungsseite (Hub)
  assets/                      Kern-CSS/JS (Stage 2: vereinheitlichtes Design)
  modules/
    wunschliste/   (= altes wunschliste-plugin, unverändert)
    protokoll/     (= altes protokollpro)
    buchhaltung/   (= altes jufo-buchhaltung-v2)
    events/        (= altes jufobleibt-event-publisher)
  languages/
```

Module bleiben in **Stage 1 unverändert** – dadurch bleibt das Risiko klein und
die vier Feature-Sets sofort lauffähig. Der Kern verändert nur *Rahmen*
(Menü, Rollen, Einstiegspunkt), nicht die Feature-Logik.

## Kern-Mechanik (implementiert, Stage 1)

- **Laden:** `plugins_loaded` prio 1 – `require_once` je Modul-Hauptdatei.
  `plugin_dir_path()/plugin_dir_url()` der Module lösen aus dem Unterordner
  korrekt auf. `register_activation_hook` der Module läuft ins Leere → der Kern
  ruft `wl_activate()` / `pp_activate()` / `jb_activate()` / `jbf_activate_plugin()`
  explizit in `vp_activate()` auf. Zusätzlich haben alle Module einen
  `plugins_loaded`-Upgrade-Check, der fehlende Tabellen selbst nachrüstet.
- **Rollen:** `vp_core_setup_roles()` (idempotent) stellt sicher, dass
  `wl_mitglied` **alle** Modul-Caps trägt, egal in welcher Reihenfolge aktiviert
  wurde – behebt das bisherige „Reihenfolge-Problem“ der Einzel-Plugins.
- **Menü:** `admin_menu` prio 9 registriert „Verein“. Prio 999 blendet die
  Modul-Top-Level-Menüs (`wunschliste`, `protokollpro`, `jb_kassenbericht`,
  `jufobleibt-event-publisher-settings`, CPT `veranstaltung`, `pp-app`) per
  `remove_menu_page()` aus. **Die Seiten-Callbacks bleiben per
  `admin.php?page=…` erreichbar** – die Shortcode-Seite und die Einstellungsseite
  listen die nötigen Direktlinks (Importe, Mitglied anlegen, Event-Editor).
- **Mitglieder-Backend-Sperre:** reine `wl_mitglied`-User werden aus `wp-admin`
  auf den Mitgliederbereich umgeleitet, Admin-Bar aus. Abschaltbar per Option
  `vp_member_backend_access`.
- **Mitgliederbereich:** `[verein_mitgliederbereich]` rendert Tabs und bettet je
  Tab den vorhandenen Modul-Shortcode via `do_shortcode()` ein. Asset-Shim lädt
  ProtokollPro-CSS/JS nach (dessen Enqueue prüft sonst auf pp-eigene Shortcodes).

## Shortcode-Katalog (Ergebnis: die eine Dashboard-Seite)

| Shortcode | Sichtbar für | Zweck |
|---|---|---|
| `[verein_mitgliederbereich start="wuensche"]` | Mitglieder | **Der** Mitgliederbereich, Tabs über alle Module |
| `[verein_login redirect="/mitglieder"]` | öffentlich | Login-Formular |
| `[wunschliste kategorie="" status=""]` | öffentlich | Spender-Ansicht mit Spenden-Modal |
| `[wunschliste_verwaltung]` | Mitglieder | Wünsche verwalten (auch als Tab) |
| `[wunschliste_voting]` | öffentlich | Abstimmung über Wünsche |
| `[wunschliste_login]` | öffentlich | Modul-Login (→ besser `[verein_login]`) |
| `[schichtplan event="slug"]` | öffentlich | Schichtplan-Kalender einer Veranstaltung |
| `[schichtplan_verwaltung]` | Mitglieder | Veranstaltungen/Stationen/Schichten verwalten |
| `[protokollpro_mitgliederbereich]` | Mitglieder | Protokoll-Bereich (auch als Tab); `?pp_view=live` Sitzungsmodus |
| `[protokollpro_oeffentlich gremium="MV"]` | öffentlich | Abgeschlossene Protokolle veröffentlichen |
| `[protokollpro_organigramm]` | öffentlich | Gremien-Mindmap (SVG) |
| `[protokollpro_kreis id="5"]` | öffentlich | Kreis-/Gremien-Steckbrief |
| `[jb_auslage_einreichen]` | Mitglieder | Auslage mit Beleg einreichen |
| `[jb_meine_auslagen]` | Mitglieder | Eigene Auslagen + Status |
| `[jb_kassenbericht]` | Vorstand/Kassier | EÜR-/Kassenübersicht |
| `[verein_veranstaltungen]` | Mitglieder | **geplant (Stage 2)** – Event-Publisher im Frontend |

Der Katalog liegt zentral in `includes/shortcode-registry.php`
(`vp_shortcode_catalog()`), filterbar über `vp_shortcode_catalog`. Die
Admin-Seite rendert ihn samt Kopier-Button und Direktlink-Liste.

## Synergien (Stage 2 – die eigentliche Zusammenführung)

Bisher berühren sich die Module nur über die gemeinsame Rolle. Geplante echte
Verzahnung:

1. **Personen/Ämter als gemeinsame Schicht.** ProtokollPro kennt Rollenvorlagen
   und Besetzungen (Kassier:in, Kreisleitung …). Buchhaltung hat eine „Kassier:in“
   nur als Capability. → ProtokollPro-Besetzung „Kassier:in“ vergibt automatisch
   `jb_approve_auslagen` an genau diese Person(en); Event-„Presse/Öffentlichkeit“
   vergibt `jbf_send_external`.
2. **Ein Termin-/Aufgabenmodell.** ProtokollPro-Termine, Wunschlisten-Schichtpläne
   und Event-Kampagnen-Daten sind drei getrennte Kalender. → Gemeinsame Sicht im
   Mitgliederbereich („Was steht an?“), gemeinsamer iCal-Feed (heute je Modul
   separat), Event-Aufgaben-Sets aus ProtokollPro können auf einen
   Schichtplan-Termin angewandt werden.
3. **Veranstaltung als verbindende Entität.** Heute drei „Event“-Begriffe:
   `pp_termine`, `wl_events` (Schichtpläne), CPT `veranstaltung` (Publisher).
   → Eine Veranstaltung anlegen erzeugt optional: Schichtplan (Wunschliste),
   Publisher-Kampagne (Events), Aufgaben-Set + Termin (ProtokollPro).
4. **Auslage ↔ Budget ↔ Wunsch.** Erfüllter Wunsch (Wunschliste) kann als
   Ausgabe ins Buchungsjournal (Buchhaltung) gebucht werden; Auslagen-Erstattung
   auf ein Budget (Buchhaltung `jb_budgets`) referenzieren.
5. **Ein Design.** Vier `style.css` (33k + 17k + 3k + 1k) → ein Token-Set
   (Farben, Abstände, Buttons, Modals, Tabellen, Kalender), Dark-Mode-fähig.
6. **Ein Mitglieder-Onboarding.** CSV/XML-Mitgliederimport (heute Wunschliste)
   wird Kern-Funktion; „Mitglied anlegen“ als Frontend-Formular für den Vorstand.

## Stage 2 – „alles ins Frontend“ (verbleibende Admin-Reste portieren)

Noch ohne Frontend-Pendant, bis dahin als ausgeblendete Admin-Seiten erreichbar:

| Funktion | heute | Stage-2-Ziel |
|---|---|---|
| Mitglied anlegen / CSV-Import Mitglieder | `admin.php?page=wunschliste-mitglied` / `…-mitglieder-import` | Kern-Shortcode `[verein_mitglieder]` (Vorstand) |
| Wunschlisten-CSV/XML-Import | `…page=wunschliste-import` | Button im Wünsche-Tab |
| Abstimmung: Ergebnis + Gast-Codes | `…page=wunschliste-voting` / `…-gastcodes` | Voting-Tab-Unterbereich |
| Schichtplan-Import | `…page=wunschliste-schichtplan-import` | Button im Schichtplan-Tab |
| Buchhaltung komplett (Kassenbericht, Auslagen-Freigabe, Budgets, Getränke, Journal, Export) | `jb_*`-Seiten | Buchhaltungs-Tabs (Vorstand/Kassier); `[jb_kassenbericht]` existiert schon |
| ProtokollPro Backend (Gremien, Protokolle, TOPs, Themen, Bestätigungen, Freigaben) | `pp-*`-Seiten | überwiegend schon im `[protokollpro_mitgliederbereich]`; Rest nachziehen |
| **Event-Publisher komplett** | CPT `veranstaltung` + Metaboxen + Publish-Buttons | **größter Posten:** Frontend-Manager `[verein_veranstaltungen]` – Liste, Anlegen/Bearbeiten, Kampagnenplaner, „Zur Freigabe einreichen“ (Mitglied) / „Senden“ (Vorstand). CPT bleibt intern als Speicher, Editor-UI entfällt. |
| Technische Zugänge (Social-APIs, Nextcloud, PWA) | `jbf_settings` / `jb_settings` / `pp-app` | in `settings-page.php` zusammenziehen |

## Etappen / Reihenfolge

- **Stage 1 – Gerüst (dieser Stand).** Bundle + Boot + eine Menüseite +
  Mitgliederbereich-Hülle + Einstellungs-Hub. Alle vier Feature-Sets nutzbar.
- **Stage 1.1 – Boot-Test & Fixes.** Auf echter WP-Instanz aktivieren,
  Aktivierungsreihenfolge, Tabellen, Asset-Laden, Tab-Rendering prüfen.
- **Stage 2a – Design-Token + Mitgliederbereich-Feinschliff.**
- **Stage 2b – Event-Publisher ins Frontend** (größter Brocken).
- **Stage 2c – restliche Admin-Reste ins Frontend**, Modul-Menüs endgültig entfernen.
- **Stage 3 – echte Synergien** (Personen/Termine/Veranstaltung/Budget-Verknüpfung).
- **Stage 4 – Aufräumen:** doppelte Kalender-/iCal-/Cron-Logik zusammenführen,
  gemeinsame `README`, Übersetzungen, `uninstall.php` (opt-in Datenlöschung).

## Risiken / offene Punkte

- **Funktions-Namensraum:** Module nutzen globale Funktionen mit Prefix; grober
  Scan zeigt keine echten Kreuz-Kollisionen. Endgültige Prüfung = Boot auf WP
  (PHP lokal nicht verfügbar).
- **`register_activation_hook` der Module** feuert nicht → durch explizite Aufrufe
  in `vp_activate()` + Upgrade-Checks abgedeckt; bei Erstaktivierung ohne
  Alt-Daten testen.
- **Event-CPT `veranstaltung`:** `remove_menu_page()` versteckt nur; Rewrite-Slug
  `/veranstaltungen` bleibt öffentlich (gewollt).
- **Mehrere `flush_rewrite_rules()`** bei Aktivierung – unkritisch, nur einmalig.
- **CSS-Kollisionen** zwischen den vier Sets auf einer gemeinsamen Seite erst in
  Stage 2a final gelöst; Stage 1 kann optisch uneinheitlich wirken.
- **PWA/Service-Worker** von ProtokollPro erwartet HTTPS; Manifest-Name generisch
  setzen (Stage 2).
