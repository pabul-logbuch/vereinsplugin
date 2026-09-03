# Veröffentlichen & Aktualisieren über GitHub

Kurzantwort: **Ja.** Nach der einmaligen Einrichtung musst du das Plugin nie
wieder löschen und neu hochladen. Neue Versionen erscheinen im WordPress-Backend
unter **Plugins → Aktualisieren** wie bei jedem anderen Plugin – ein Klick.

Möglich macht das die eingebundene Bibliothek
[`plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker)
(`vereinsplugin/vendor/plugin-update-checker/`, MIT-Lizenz). Sie fragt regelmäßig
das GitHub-Repo nach dem neuesten Release und meldet WordPress, wenn eine neuere
Version bereitliegt.

---

## Einmalige Einrichtung

### 1. GitHub-Repo anlegen und Code hochladen

```bash
cd /Users/paul/Vereinsplug-In

# Repo bei GitHub erstellen (Web-UI: https://github.com/new  → Name z. B. "vereinsplugin", leer lassen)
git remote add origin https://github.com/pabul-logbuch/vereinsplugin.git
git push -u origin main
```

(Alternativ mit GitHub CLI: (Repo besteht schon: `pabul-logbuch/vereinsplugin`))

### 2. Repo-Namen im Plugin eintragen

In `vereinsplugin/vereinsplugin.php` an **zwei Stellen** `DEIN-GITHUB-NAME`
durch deinen tatsächlichen GitHub-Benutzer/Organisation ersetzen:

- Header-Zeile `GitHub Plugin URI: pabul-logbuch/vereinsplugin`
- `define( 'VP_GITHUB_REPO', 'pabul-logbuch/vereinsplugin' );`

Oder ohne Datei-Änderung: in der `wp-config.php` der Website

```php
define( 'VP_GITHUB_REPO', 'pabul-logbuch/vereinsplugin' );
```

Bei einem **privaten** Repo zusätzlich einen
[Personal Access Token](https://github.com/settings/tokens) (Scope `repo`) in die
`wp-config.php`:

```php
define( 'VP_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
```

### 3. Plugin auf der Website installieren

Einmalig manuell: ZIP aus einem Release herunterladen (siehe unten) oder den
Ordner `vereinsplugin/` nach `wp-content/plugins/` kopieren und aktivieren.
Ab dann laufen Updates automatisch.

---

## Eine neue Version veröffentlichen

1. Änderungen committen.
2. Versionsnummer an **beiden** Stellen in `vereinsplugin/vereinsplugin.php`
   hochzählen:
   - Header `* Version:           0.2.0`
   - `define( 'VP_VERSION', '0.2.0' );`
3. Taggen und pushen:

   ```bash
   git add -A && git commit -m "Version 0.2.0"
   git tag v0.2.0
   git push origin main --tags
   ```

4. Der Workflow [`.github/workflows/release.yml`](.github/workflows/release.yml)
   läuft automatisch: er prüft, dass Tag und Header-Version übereinstimmen, baut
   `vereinsplugin.zip` (mit korrektem Ordner `vereinsplugin/` darin) und hängt es
   an ein neues GitHub-Release `v0.2.0`.
5. Innerhalb einiger Stunden (oder sofort per **Plugins → Aktualisieren →
   „Nach Updates suchen“**) zeigt jede Website das Update an.

> Der Tag muss mit `v` beginnen (`v0.2.0`), die Version im Plugin-Header **ohne**
> `v` (`0.2.0`). Stimmen sie nicht überein, bricht der Workflow mit Fehler ab.

---

## Ohne GitHub-Actions (manuelle Releases)

Wenn du keine Actions nutzen willst: bei GitHub unter **Releases → Draft a new
release** einen Tag `v0.2.0` anlegen und selbst ein ZIP anhängen, das einen
Ordner `vereinsplugin/` enthält. Der Update-Checker findet auch das.

Fällt das Anhängen weg, zieht der Checker automatisch den von GitHub erzeugten
„Source code (zip)“ – dann landet aber ggf. ein Ordnername mit Tag-Suffix in
`wp-content/plugins/`. Deshalb ist das angehängte, sauber benannte ZIP besser.

---

## Andere Wege (Alternativen)

- **[Git Updater](https://git-updater.com/)** von Andy Fragen: separates Plugin
  auf der Website, liest die `GitHub Plugin URI:`-Header. Kein Bundle nötig,
  aber ein zusätzliches Plugin. Die Header sind bereits gesetzt, funktioniert
  also parallel.
- **wordpress.org Plugin-Verzeichnis**: für ein vereinsinternes Plugin
  ungeeignet (Review-Richtlinien, öffentliche Sichtbarkeit).
