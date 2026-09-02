# Vereinsplugin

Alles-in-einem-Vereinsverwaltung für WordPress. Bündelt vier bisher getrennte
Plugins zu einem – mit gemeinsamem Kern, einem Mitgliederbereich und **genau
einer** zusätzlichen Dashboard-Seite: der Shortcode-Übersicht.

## Enthaltene Module

- **Wunschliste & Spenden** – öffentliche Wunschliste mit Spenden-Modal,
  Abstimmung, Schichtpläne für Veranstaltungen, Mitglieder-Import.
- **Sitzungen & Protokolle** – Gremien, Konsent-Protokolle, TOPs, Themenspeicher,
  Aufgaben, Termine, Organigramm, PWA/App.
- **Buchhaltung & Auslagen** – EÜR, Auslagen-Erstattung mit Beleg, Budgets,
  Getränkekasse, Nextcloud-Belegablage.
- **Veranstaltungs-Publisher** – Veranstaltungen an Mastodon, Bluesky, Telegram,
  Presse, Signal u. a. verteilen (Frontend-UI folgt in Stage 2).

## Installation

1. Ordner `vereinsplugin/` nach `wp-content/plugins/` kopieren (oder als ZIP
   hochladen).
2. Aktivieren. Tabellen, Rollen und Cron-Jobs der Module werden dabei angelegt.
3. Falls die alten Einzel-Plugins (`wunschliste-plugin`, `protokollpro`,
   `jufo-buchhaltung-v2`, `jufobleibt-event-publisher`) noch aktiv sind: jetzt
   deaktivieren. Daten bleiben erhalten – dieselben Tabellen werden weitergenutzt.
4. Seiten anlegen (Shortcodes siehe **Verein → Shortcodes** im Dashboard):
   - öffentliche Seite mit `[wunschliste]`
   - Seite „Mitgliederbereich“ mit `[verein_mitgliederbereich]`
   - Login-Seite mit `[verein_login]` (oder Login in den Mitgliederbereich einbetten)

## Dashboard

Dieses Plugin fügt bewusst nur **eine** Menüseite hinzu: **Verein**.

- **Verein → Shortcodes** – vollständige Liste aller Shortcodes mit Beschreibung,
  Zielgruppe und Kopier-Button. Unten Direktlinks zu den (aus dem Menü
  ausgeblendeten) Rest-Admin-Funktionen wie CSV-Import oder Event-Editor.
- **Verein → Einstellungen** – Bankverbindung/Spendenkontakt, Modul-An/Aus,
  Zugriffsschalter. Umfangreiche technische Zugänge (Social-APIs, Nextcloud,
  PWA) sind bis Stage 2 noch auf den ursprünglichen Modul-Seiten – von hier aus
  direkt verlinkt.

Die alten Menüs der vier Module sind ausgeblendet, ihre Seiten bleiben per URL
erreichbar. Zum Debugging lassen sie sich unter **Einstellungen → „Modul-Menüs
wieder einblenden“** reaktivieren.

## Mitglieder

- Mitglieder haben die Rolle **Vereinsmitglied** (`wl_mitglied`, unverändert
  kompatibel zur bisherigen Wunschliste).
- Reine Mitglieder werden aus `wp-admin` auf den Mitgliederbereich umgeleitet und
  sehen keine Admin-Bar. Abschaltbar in den Einstellungen.
- Vorstand = WordPress-Rollen **Administrator/Redakteur**: zusätzlich Kassen-
  und Versand-Rechte (`jbf_send_external`, `jb_approve_auslagen` …).

## Updates über GitHub

Das Plugin bringt einen Update-Checker mit (`vendor/plugin-update-checker/`,
YahnisElsts, MIT-Lizenz). Ist in `vereinsplugin.php` bzw. der `wp-config.php`
das GitHub-Repo gesetzt (`VP_GITHUB_REPO`), erscheinen neue Versionen ganz normal
unter **Plugins → Aktualisieren** – kein Löschen/Neu-Hochladen. Einrichtung und
Release-Ablauf: siehe [`../RELEASING.md`](../RELEASING.md).

## Aufbau / Weiterentwicklung

Siehe [`../PLAN.md`](../PLAN.md). Kurz:

- `vereinsplugin.php` – Modul-Registry & Bootstrap
- `includes/core-roles.php` – Rollenbrücke, Backend-Sperre
- `includes/shortcode-registry.php` – Katalog (single source of truth)
- `includes/admin-consolidation.php` – das eine Menü, Modul-Menüs verstecken
- `includes/member-area.php` – `[verein_mitgliederbereich]`
- `includes/settings-page.php` – Einstellungs-Hub
- `modules/*` – die vier Alt-Plugins, in Stage 1 unverändert eingebunden

Stand: **Stage 1 (Gerüst)**. Nächster Schritt: Boot-Test auf echter
WordPress-Instanz, dann Design-Vereinheitlichung und Portierung des
Event-Publishers ins Frontend.
