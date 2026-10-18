# Stoffe – Nextcloud-App

Eine Foto-Galerie für Stoffe. Fotos (JPEG, z. B. vom Handy) werden in den
Ordner **„Stoffe“** der eigenen Nextcloud-Dateien hochgeladen. In der
Seitenleiste rechts pflegt man zu jedem Foto:

- **Lagerort**: Raum, Schrank, Fach (bei neuen Fotos mit der letzten Eingabe vorausgefüllt)
- **Preis** in Euro
- **Stoffart** aus einer Liste gängiger Stoffarten
- **Kategorie** aus einer eigenen, änderbaren Liste (Start: Hosen, Unterwäsche, T-Shirts, Kinder)
- **Kommentar** als Freitext

Die Galerie ist nach allen Angaben filterbar (Suche, Lagerort, Stoffart,
Kategorie, Preisbereich, „ohne Angabe“).

## Wo die Daten liegen

Es gibt **keine Datenbank**. Alle Angaben stehen als XMP direkt in den
JPEG-Dateien (Namensraum `http://ns.stoffe-app.de/1.0/`, Präfix `stoffe`;
der Kommentar zusätzlich in `dc:description`). Vorhandene XMP-Daten der
Kamera bleiben erhalten. Ein Backup der Fotos ist damit ein Backup aller Daten.

Die Kategorienliste und der letzte Lagerort stehen in `Stoffe/.stoffe.json`.
Fehlt die Datei, startet die App mit den Standardkategorien.

Damit die Galerie nicht bei jedem Aufruf alle Dateien liest, merkt sich die
App die gelesenen Angaben im Speicher-Cache von Nextcloud (APCu/Redis, falls
eingerichtet). Der Cache ist jederzeit verzichtbar und wird aus den Bildern
neu aufgebaut. Fotos, die per Sync-Client oder WebDAV in den Ordner kommen,
erscheinen automatisch mit ihren Angaben.

## Installation

1. Release-Archiv nach `custom_apps/` (bzw. `apps/`) entpacken, sodass `custom_apps/stoffe/` entsteht.
   Aus dem Quellcode: Repository nach `custom_apps/stoffe` klonen und `npm ci && npm run build` ausführen.
2. `sudo -u www-data php occ app:enable stoffe`
3. In der Nextcloud oben in der Leiste „Stoffe“ öffnen.

Getestet mit Nextcloud 32 (unterstützt laut `info.xml`: 30–33).

## Entwicklung

```bash
npm ci
npm run build      # erzeugt js/ und css/
npm run watch      # baut bei Änderungen neu
```

## Aufbau

- `appinfo/` – App-Metadaten und Routen
- `lib/Service/XmpService.php` – XMP im JPEG lesen und schreiben
- `lib/Service/FabricService.php` – Fotos auflisten, hochladen, Angaben speichern, Kategorien umbenennen
- `lib/Service/SettingsService.php` – Kategorien und letzter Lagerort in `.stoffe.json`
- `lib/Controller/` – REST-API unter `/apps/stoffe/api/…`
- `src/` – Oberfläche (Vue 3, @nextcloud/vue 9)

## Lizenz

MIT, siehe [LICENSE](LICENSE).
