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
  Getränkekasse, Nextcloud-Belegablage. Beim Einreichen wählt man das Konto
  für die Rückzahlung (aus dem Profil, neu eingegeben oder bar); nach der
  Genehmigung zeigt der Vorstand einen **GiroCode** (EPC-QR) mit dem
  Verwendungszweck „Rückzahlung Einkauf bei [Händler] am [Datum], Zweck
  [Kostenstelle, Budget, Konto]“ zum Scannen in der Banking-App.
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
- Im Mitgliederbereich unter **Mitglieder** (Recht `vp_manage_members`) lassen
  sich Konten suchen, filtern und bearbeiten: Stammdaten, Anschrift, Beitrag,
  SEPA-Angaben, Konto für Erstattungen, interne Notiz sowie ein Link „Passwort-Link per E-Mail senden“.
  Die **Rolle** ändert nur eine Administrator:in (Recht `promote_users`), nie
  die eigene; Konten mit Admin-Rechten sind für den Vorstand gesperrt. Ämter in
  Kreisen werden dort nur angezeigt – gepflegt werden sie im Kreis unter
  „Mitglieder & Rollen“.
- **Mitglieder-Import** (Mitgliederbereich → „CSV-Import“): liest einfache
  Listen (`name;email`) ebenso wie den Voll-Export der alten Vereinsverwaltung
  (Mitglieds-ID, Anschrift, Beitrag, Bankverbindung, Status, Gruppen). In der
  Vorschau wird jede Zeile mit einem bestehenden WordPress-Konto verbunden
  (Vorschlag über Mitglieds-Nr., E-Mail oder Namen, frei änderbar), neu angelegt
  oder übersprungen; Status-/Gruppenwerte werden einmal zugeordnet (Mitgliedsart,
  ausgetreten, Kreis). Ausgetretene landen in der rechtelosen Rolle
  **Ehemaliges Mitglied** (`vp_ehemalig`) und tauchen in der Liste nur im
  eigenen Filter auf. Optional: SEPA-Mandate anlegen, Einladung mit
  Passwort-Link verschicken. Ein erneuter Import erkennt die Personen über die
  Mitglieds-Nr. wieder.

## Seitenleiste im Mitgliederbereich

- **Start** – Nächste Termine, geplante Sitzungen, meine Aufgaben, meine Kreise
  und Projekte (die frühere „Übersicht“ aus Sitzungen & Protokolle).
- **Kalender** – siehe unten.
- **Aufgaben** – offene Aufgaben (mit Zähler), **Rollenaufgaben** (was zu jeder
  Rolle regelmäßig bzw. vor Veranstaltungen gehört, eigene Rollen zuerst) und
  **Aufgaben-Sets**.
- **Sitzungen & Protokolle** – nur noch: Geplante Sitzungen, Protokolle,
  Entscheide, Themenspeicher.
- **Kreise & Rollen**, **Projekte & Veranstaltungen**, **Dokumente & Vorlagen**
  (Dokumente + Ablauf-Vorlagen) – eigene Einträge in der Hauptleiste.

## Kalender

Im Mitgliederbereich gibt es in der Seitenleiste den Punkt **Kalender**
(direkt unter „Start“). Er führt alle Termine des Vereins zusammen – die Daten
bleiben dabei in ihren Modulen, es wird nichts doppelt gepflegt:

- **Veranstaltungen** aus dem Veranstaltungs-Publisher (Entwürfe nur für
  Leute mit Veranstaltungsrechten),
- **Sitzungen** aus „Sitzungen & Protokolle“ (Sitzungen „nur Gremium“ nur für
  dessen Mitglieder) und **Termine aus Beschlüssen**,
- **Schichtpläne** je Veranstaltungstag mit freien Plätzen – plus die eigenen
  Schichten hervorgehoben,
- **Öffnungszeiten** (wöchentlich wiederkehrend) mit **Schließtagen/Ferien**,
- **Weitere Termine**, frei eingetragen (für alle oder nur den Vorstand),
- **Nextcloud-Kalender** (siehe unten).

- **Meine Aufgaben-Fristen** (offene Aufgaben mit Fälligkeitsdatum).

Kreis-Termine gehören ebenfalls in den Kalender (der frühere Reiter „Termine“
unter Sitzungen & Protokolle ist entfallen): Unter **Verwalten** beim Termin
einen Kreis auswählen; darunter stehen alle anstehenden Kreis-Termine und
geplanten Sitzungen mit **Aufgaben-Set anwenden** und **Rollenaufgaben
erzeugen**. Der persönliche Abo-Link ersetzt den alten „Kalender-Sync“ (alte
Links funktionieren weiter).

Ansichten: Monat (auf dem Handy automatisch als Liste), Liste; Kategorien
lassen sich per Filter ein-/ausblenden. Auf der Startseite erscheinen die
nächsten Termine. Öffnungszeiten, weitere Termine und Nextcloud-Kalender
pflegt der Vorstand unter **Kalender → Verwalten**.

**Nextcloud – beide Richtungen:**

- *Nextcloud → Vereinskalender:* Unter „Verwalten“ werden die Kalender des in
  den Einstellungen hinterlegten Nextcloud-Kontos zum Anklicken angeboten.
  Alternativ lässt sich jeder Freigabe-/Abo-Link (oder jede andere ICS-Adresse)
  einfügen. Die Termine werden alle 15 Minuten gelesen (nur lesend,
  Wiederholungen und Ausnahmen werden aufgelöst). Zugangsdaten gehen nur an
  den eigenen Nextcloud-Host.
- *Vereinskalender → Nextcloud:* Unter „Abonnieren / Nextcloud“ bekommt jede
  Person einen persönlichen ICS-Link. In Nextcloud: Kalender → „+ Neuer
  Kalender“ → „Neues Abonnement aus Link“. Funktioniert genauso mit Google,
  Apple, Outlook und Thunderbird. Der Link enthält nur, was die Person auch im
  Mitgliederbereich sieht, und lässt sich jederzeit neu erzeugen.

Weitere Quellen können sich über den Filter `vp_kalender_termine` anhängen.

## Live-Sitzung: weiterarbeiten und alles dokumentieren

Wer eine Sitzung **live protokolliert**, kann nebenbei überall weiterarbeiten:

- **Live-Leiste**: Solange die Sitzung läuft, steht oben in jedem Bereich des
  Mitgliederbereichs eine rote Leiste mit „Zum Protokoll“, „🎥 Online“ (Nextcloud
  Talk) und „Live verlassen“.
- **Automatische Dokumentation**: Was Teilnehmende währenddessen ändern – Kasse
  und Budgets (auch Kreiskassen), Wunschliste, Kreise (bearbeiten, Unterkreise,
  Mitglieder, Rollen), Schichtpläne, Aufgaben, Kalender, Projekte,
  Veranstaltungen –, erscheint im Protokoll unter **„Während der Sitzung
  erledigt“** (Uhrzeit, Bereich, was, wer). Einzelne Einträge lassen sich bis
  zum Abschluss wieder entfernen.
- **In der Live-Seitenleiste**:
  - *Online-Teilnahme*: Talk-Link bzw. Raum anlegen (Kreis-Chat oder eigener
    Raum mit Gast-Link).
  - *Weiterarbeiten an …*: Kassenbericht, Budgets, Kreiskasse und Wunschliste des
    Kreises, Kreis bearbeiten, Unterkreis anlegen, Schichtpläne, Aufgaben,
    Kalender, Projekte – öffnet in einem neuen Tab, das Protokoll bleibt offen.
  - *Bericht einfügen*: Kassenbericht des Vereins, Kreiskasse & Budgets eines
    Kreises oder die Wunschliste (alle bzw. eines Kreises) als Stand zum
    Zeitpunkt der Sitzung – optional einem TOP zugeordnet.
- Mit dem **Abschluss** des Protokolls endet die Live-Sitzung für alle.

## Nextcloud Talk: Kreis-Chats & Online-Besprechungen

Voraussetzung: In Nextcloud ist die App **Talk** installiert und unter „Verein →
Einstellungen“ der Nextcloud-Zugang eingetragen (derselbe wie für den
Benutzer-Sync). Dieses Konto legt die Unterhaltungen an und ist darin Moderator:in.

- **Chats & Besprechungen** (Seitenleiste, unter Kalender): die eigenen
  Kreis-Chats und die nächsten Online-Besprechungen, jeweils mit Link, der
  Nextcloud Talk (Browser oder Talk-App) öffnet. Eingebettet wird Talk nicht,
  weil Nextcloud das Einbetten in fremde Seiten standardmäßig blockiert.
- **Kreis-Chats**: Der Vorstand hakt dort die Kreise an; je Kreis entsteht eine
  Talk-Unterhaltung, deren Teilnehmende mit den Kreismitgliedern abgeglichen
  werden (neu rein, ausgetreten raus; auf Wunsch stündlich). Die
  Mitgliederversammlung umfasst alle Mitglieder.
- **Sitzungen**: In „Geplante Sitzungen“ gibt es „🎥 Online (Talk)“ – die
  Besprechung läuft im Kreis-Chat. Alternativ „Eigener Raum mit Gast-Link“ für
  Sitzungen mit Externen. Der Link steht auch im Kalender und im Abo.
- **Benutzer**: Die Zuordnung WordPress → Nextcloud übernimmt der bestehende
  **Nextcloud-Sync** (legt fehlende Konten auf beiden Seiten an, abgeglichen
  über die E-Mail-Adresse).

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
