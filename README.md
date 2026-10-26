# Stoffe – Nextcloud-App

Eine Foto-Galerie für Stoffe. Fotos (JPEG, z. B. vom Handy) werden in den
Ordner **„Stoffe“** der eigenen Nextcloud-Dateien hochgeladen. In der
Seitenleiste rechts pflegt man zu jedem Foto:

- **Titel**: der Dateiname ohne `.jpg`; Umbenennen in der Seitenleiste benennt die Datei um
- **Lagerort**: Raum, Schrank, Fach (bei neuen Fotos mit der letzten Eingabe vorausgefüllt)
- **Preis** in Euro
- **Abmessung**: Länge und Breite in cm
- **Reststück**: ja oder nein
- **Stoffart** aus einer Liste gängiger Stoffarten
- **Kategorie** aus einer eigenen, änderbaren Liste (Start: Hosen, Unterwäsche, T-Shirts, Kinder)
- **Kommentar** als Freitext

Die Galerie ist nach allen Angaben filterbar (Suche in Titel und Kommentar,
Lagerort, Stoffart, Kategorie, Preisbereich, Mindestlänge und -breite,
Reststück, „ohne Angabe“).

Fotos lädt man über den Knopf „Hochladen“ oder per Ziehen und Ablegen in die
Galerie hoch; nur JPEG wird angenommen. Mit **„Speichern und weiter“** in der
Seitenleiste arbeitet man neue Fotos nacheinander ab, in der Reihenfolge, die
Galerie oder Tabelle gerade zeigen.

## Tabellenansicht

Oben rechts lässt sich zwischen Galerie und Tabelle wechseln. In der Tabelle:

- **Spalten** wählt die sichtbaren Spalten (der Titel ist immer da).
- Unter jeder Überschrift steht ein **Spaltenfilter**: Text wird als „enthält“
  gesucht, bei Zahlen gehen `150`, `>=140`, `<20` oder `100-200`.
- Ein Klick auf eine Überschrift **sortiert** auf-, dann absteigend, dann gar
  nicht. Mit gedrückter Umschalttaste kommt eine weitere Sortierspalte dazu.
- Ansicht, Spalten und Sortierung merkt sich der Browser.

## Geteilte Ordner

Der Stoffe-Ordner kann ganz normal in Nextcloud mit anderen Nutzern geteilt
werden. Über den Ordnernamen oben im Kopf wählt jeder Nutzer, welchen Ordner
die App zeigt: den eigenen „Stoffe“-Ordner, einen geteilten Ordner oder einen
beliebigen anderen. Geteilte Ordner werden angeboten, wenn sie „Stoff“ im
Namen tragen oder schon eine `.stoffe.json` enthalten.

- Mit Bearbeitungsrecht kann man Angaben ändern, umbenennen und hochladen.
- Mit Leserecht sieht man alles, kann aber nichts ändern; die App sagt das.
- Die Kategorienliste gehört zum Ordner und gilt für alle, die ihn nutzen.
  Der letzte Lagerort und der gewählte Ordner sind pro Nutzer.

## Wo die Daten liegen

Es gibt **keine Datenbank**. Alle Angaben stehen als XMP direkt in den
JPEG-Dateien (Namensraum `http://ns.stoffe-app.de/1.0/`, Präfix `stoffe`;
der Kommentar zusätzlich in `dc:description`). Vorhandene XMP-Daten der
Kamera bleiben erhalten. Ein Backup der Fotos ist damit ein Backup aller Daten.

Die Kategorienliste steht in `.stoffe.json` im Stoffe-Ordner. Fehlt die
Datei, startet die App mit den Standardkategorien. Der gewählte Ordner und
der letzte Lagerort sind persönliche Einstellungen des Nutzers in Nextcloud.

Damit die Galerie nicht bei jedem Aufruf alle Dateien liest, merkt sich die
App die gelesenen Angaben im Speicher-Cache von Nextcloud (APCu/Redis, falls
eingerichtet). Der Cache ist jederzeit verzichtbar und wird aus den Bildern
neu aufgebaut. Fotos, die per Sync-Client oder WebDAV in den Ordner kommen,
erscheinen automatisch mit ihren Angaben.

## Installation

Siehe [INSTALLATION.md](INSTALLATION.md), auch für Nextcloud All-in-One (AIO).

Getestet mit Nextcloud 32 und 35, geteilte Ordner mit 35 (unterstützt laut `info.xml`: 30–35).

## Entwicklung

Die Spezifikation (Ziel, Datenmodell, API, Entscheidungen, Tests, Grenzen)
steht in [SPEC.md](SPEC.md). Hinweise für die Arbeit mit KI-Assistenten
stehen in [CLAUDE.md](CLAUDE.md).

```bash
npm ci
npm run build      # erzeugt js/ und css/
npm run watch      # baut bei Änderungen neu
./scripts/package.sh   # erzeugt build/stoffe-<version>.tar.gz
```

## Release

`<version>` in `appinfo/info.xml` (und `package.json`) erhöhen, auf `main`
bringen und einen passenden Tag pushen, z. B. `git tag v0.4.0 && git push origin v0.4.0`.
Der GitHub-Workflow `Release` baut dann das Archiv und veröffentlicht es als
Release. Passt der Tag nicht zur Version in `info.xml`, bricht er ab.

Ohne eigenen Tag geht es auch über GitHub: unter *Actions → Release → Run workflow*.
Der Workflow setzt dann den Tag `v<version>` aus `info.xml` selbst.

## Aufbau

- `appinfo/` – App-Metadaten und Routen
- `lib/Service/XmpService.php` – XMP im JPEG lesen und schreiben
- `lib/Service/FabricService.php` – Fotos auflisten, hochladen, Angaben speichern, Kategorien umbenennen
- `lib/Service/PhotoFolder.php` – gewählter Ordner, eigene und geteilte Ordner
- `lib/Service/SettingsService.php` – Kategorien (`.stoffe.json`), Stoffarten und letzter Lagerort
- `lib/Controller/` – REST-API unter `/apps/stoffe/api/…`
- `src/App.vue` – Galerie, Filter, Hochladen, Wechsel Galerie/Tabelle
- `src/components/` – Seitenleiste, Tabelle, Kategorienverwaltung, Ordnerwahl
- `scripts/package.sh` – baut das Release-Archiv
- `.github/workflows/release.yml` – Release-Workflow

## Lizenz

MIT, siehe [LICENSE](LICENSE).
