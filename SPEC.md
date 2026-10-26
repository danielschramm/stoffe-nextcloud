# Stoffe – Spezifikation

Stand: Version 0.4.0. Dieses Dokument fasst Ziel, Entscheidungen, Datenmodell,
API und Abläufe zusammen, damit die App (auch KI-gestützt) weiterentwickelt
werden kann, ohne die Vorgeschichte zu kennen. Nutzerdoku: [README.md](README.md),
Installation: [INSTALLATION.md](INSTALLATION.md).

## 1. Ziel

Eine Nextcloud-App „Stoffe“ (App-ID `stoffe`) für eine private Stoffsammlung:

- Fotos von Stoffen (Handyfotos, JPEG) liegen als normale Dateien in einem
  Nextcloud-Ordner, standardmäßig `/Stoffe` des Nutzers.
- Die App zeigt sie als **Galerie** oder **Tabelle**.
- Eine **Seitenleiste rechts** bearbeitet die Angaben zu einem Foto.
- Die Sammlung ist nach **allen Angaben filterbar**.
- Ordner können mit anderen Nutzern **geteilt** werden (lesend oder schreibend).

Oberfläche und Fehlermeldungen sind deutsch. Der Code (Bezeichner,
Kommentare) ist englisch.

## 2. Feste Entscheidungen

Diese Punkte hat der Eigentümer (Daniel) festgelegt. Sie werden nur nach
Rücksprache geändert.

| Entscheidung | Bedeutung für die Umsetzung |
| --- | --- |
| **Keine Datenbank** | Keine eigenen Tabellen, keine Migrationen. Die Wahrheit steht ausschließlich in den JPEG-Dateien (XMP). Ein Backup der Fotos ist ein Backup aller Daten. |
| **Cache nur wegwerfbar** | Erlaubt ist nur der Nextcloud-Memcache (`ICacheFactory::createLocal('stoffe-xmp-v2')`), Schlüssel `fileId-mtime-size`, TTL 30 Tage. Geht er verloren, wird alles aus den Dateien neu gelesen. Ändert sich das Format der gecachten Werte, den Cache-Namen hochzählen (`-v3`). |
| **Nur JPEG** | Upload prüft den MIME-Typ per `finfo` auf dem Server (nicht den vom Browser gemeldeten). Andere Formate werden abgelehnt; die Liste zeigt nur `image/jpeg`. |
| **Titel = Dateiname** | Der Titel eines Stoffs ist der Dateiname ohne Endung. Umbenennen in der App benennt die Datei um. Es gibt kein eigenes Titelfeld im XMP. |
| **Kategorienliste beim Ordner** | Die Liste steht in `<Ordner>/.stoffe.json` und wird mit dem Ordner geteilt und gesichert. |
| **Persönliches als Nutzereinstellung** | Gewählter Ordner und letzter Lagerort sind `IConfig`-Nutzereinstellungen (App `stoffe`, Schlüssel `folder`, `lastLocation`). Das ist keine eigene Datenbank und darf verloren gehen. |
| **Kategorie löschen** | Entfernt sie nur aus der Auswahlliste; Fotos behalten ihren Wert. |
| **Kategorie umbenennen** | Ändert die Liste und schreibt das XMP aller betroffenen Fotos neu. |

## 3. Datenmodell

### 3.1 Felder im XMP

Namensraum `http://ns.stoffe-app.de/1.0/`, Präfix `stoffe`. Alle Werte sind
Strings; leer heißt „keine Angabe“. Die Feldliste steht doppelt und muss
gleich bleiben: `XmpService::FIELDS` (PHP) und `FIELDS` in `src/api.js`.

| Feld | Bedeutung | Format / Regeln (serverseitig in `FabricService::cleanValues`) |
| --- | --- | --- |
| `room` | Raum | Freitext, max. 255 Zeichen, Leerraum zusammengefasst |
| `cabinet` | Schrank | wie `room` |
| `shelf` | Fach | wie `room` |
| `price` | Preis in Euro | Komma oder Punkt erlaubt, gespeichert als `12.50` (2 Nachkommastellen) |
| `length` | Länge in cm | gespeichert mit höchstens 1 Nachkommastelle, ohne unnötige Nullen (`150`, `72.5`) |
| `width` | Breite in cm | wie `length` |
| `remnant` | Reststück | `true` oder `false`; Standard `false`. Eingaben `1/ja/yes/on` gelten als wahr |
| `fabricType` | Stoffart | Wert aus `SettingsService::FABRIC_TYPES` (feste Liste, endet mit „Sonstiges“) |
| `category` | Kategorie | Wert aus der Kategorienliste; ein neuer Wert wird automatisch in die Liste aufgenommen |
| `comment` | Kommentar | Freitext, max. 5000 Zeichen, Zeilenumbrüche bleiben |

Zusätzlich wird `comment` als `dc:description` (`rdf:Alt`, `x-default`)
geschrieben, damit andere Fototools ihn zeigen.

### 3.2 Schreiben und Lesen (`XmpService`)

- XMP steht im APP1-Segment mit Kopf `http://ns.adobe.com/xap/1.0/\0`.
- Beim Schreiben wird ein vorhandenes XMP-Paket übernommen; nur unsere Felder
  und `dc:description` werden ersetzt. Kamera-XMP bleibt erhalten.
- Gibt es noch kein XMP-Segment, wird es nach APP0/APP1 (JFIF/Exif) eingefügt.
- Bilddaten ab SOS werden unverändert kopiert.
- Ein Segment ist auf 65533 Byte begrenzt; größeres XMP ergibt einen Fehler.
- Gelesen wird als Stream nur bis zum Beginn der Bilddaten.
- Felder werden als Element oder Attribut von `rdf:Description` erkannt.
- Fotos ohne unsere Felder liefern lauter leere Werte. Fotos aus älteren
  Versionen, denen ein Feld fehlt, bekommen es leer ergänzt.

### 3.3 `.stoffe.json`

Liegt im gewählten Ordner. Fehlt sie, gelten die Standardkategorien
`Hosen, Unterwäsche, T-Shirts, Kinder`. Inhalt:

```json
{
    "categories": ["Hosen", "Kinder", "T-Shirts", "Unterwäsche"]
}
```

Die Liste wird ohne Duplikate und natürlich sortiert gespeichert. Ältere
Versionen haben dort auch `lastLocation` abgelegt; das wird nur noch als
Rückfall gelesen.

### 3.4 Nutzereinstellungen (`IConfig`, App `stoffe`)

| Schlüssel | Inhalt | Standard |
| --- | --- | --- |
| `folder` | Pfad relativ zum Nutzerordner, z. B. `/Stoffe` oder `/Geteilt/Stoffe Eva` | `/Stoffe` (wird bei Bedarf angelegt) |
| `lastLocation` | JSON `{room, cabinet, shelf}`; füllt bei Fotos ohne Angaben den Lagerort vor | leer |

### 3.5 Browser (`localStorage`)

Nur Bequemlichkeit, pro Browser: `stoffe:view` (`grid`/`table`) und
`stoffe:table` (`{columns, sort}`).

## 4. Rechte und geteilte Ordner (`PhotoFolder`)

- Der Ordner kann ein eigener oder ein geteilter Ordner sein. Zur Auswahl
  (`GET /api/folders`) stehen: der aktuelle Ordner, der eigene `/Stoffe` und
  mit dem Nutzer (direkt oder per Gruppe) geteilte Ordner, die „stoff“ im
  Namen tragen oder eine `.stoffe.json` enthalten. Über einen Pfad kann jeder
  andere Ordner gewählt werden.
- Rechte kommen aus Nextcloud selbst: `isUpdateable` (Angaben speichern,
  umbenennen), `isCreatable` (Hochladen, Umbenennen im Ordner),
  `isDeletable` (Löschen). Die API liefert sie als `canEdit`, `canDelete`,
  `canUpload` mit, die Oberfläche blendet Bearbeiten danach aus.
- Fehlt das Recht, antwortet die API mit **403** und einer deutschen Meldung.
- Ist ein gewählter Ordner weg (Freigabe entfernt), liefert `GET /api/fabrics`
  **404** mit `folderMissing: true`; die Oberfläche bietet dann die Ordnerwahl an.
- Kann eine Kategorie wegen fehlender Rechte nicht in `.stoffe.json`
  aufgenommen werden, wird das Foto trotzdem gespeichert.

## 5. API

Alle Routen liegen unter `/apps/stoffe/api` (`appinfo/routes.php`), sind
`#[NoAdminRequired]` und nutzen den normalen Nextcloud-CSRF-Schutz (im
Frontend über `@nextcloud/axios`). Fehler kommen als `{ "message": "…" }`.

| Methode und Pfad | Controller | Eingabe | Antwort |
| --- | --- | --- | --- |
| `GET /fabrics` | `FabricController::index` | – | `{ folder, fabrics: [Fabric] }`, neueste zuerst |
| `POST /fabrics` | `upload` | multipart `photos[]` | 201 `{ uploaded: [Fabric], errors: [{name, message}] }`, 400 wenn nichts ging |
| `PUT /fabrics/{id}` | `update` | alle Felder aus 3.1 | `Fabric` |
| `POST /fabrics/{id}/rename` | `rename` | `{ title }` | `Fabric` (neuer `name`) |
| `DELETE /fabrics/{id}` | `destroy` | – | `{}`; Datei geht in den Papierkorb |
| `GET /settings` | `SettingsController::index` | – | `Settings` |
| `POST /categories` | `addCategory` | `{ name }` | `Settings` |
| `POST /categories/rename` | `renameCategory` | `{ from, to }` | `Settings` |
| `POST /categories/delete` | `deleteCategory` | `{ name }` | `Settings` |
| `GET /folders` | `folders` | – | `{ current, folders: [Folder] }` |
| `PUT /folder` | `setFolder` | `{ path }` | `Folder`, 404 wenn es ihn nicht gibt |

Objekte:

- **Fabric**: `id` (Nextcloud-Datei-ID), `name` (Dateiname mit Endung),
  `mime`, `size`, `mtime`, `canEdit`, `canDelete` und alle Felder aus 3.1.
- **Settings**: `categories`, `fabricTypes`, `lastLocation {room, cabinet, shelf}`.
- **Folder**: `path`, `name`, `owner`, `ownerName`, `shared`, `canUpload`, `canEdit`.

Regeln für Titel beim Umbenennen: Steuer- und Sonderzeichen `/ \ : * ? " < > |`
werden zu Leerzeichen, Leerraum wird zusammengefasst, nicht leer, darf nicht
mit `.` beginnen, höchstens 200 Zeichen. Die Endung bleibt. Ein anderer Stoff
mit demselben Namen (ohne Rücksicht auf Groß-/Kleinschreibung) ist ein
Fehler; nur die Schreibweise des eigenen Namens zu ändern ist erlaubt.

Vorschaubilder kommen aus dem Nextcloud-Core:
`/core/preview?fileId=…&x=…&y=…&a=1&mimeFallback=1&v=<mtime>`.

## 6. Aufbau

### Backend (PHP, `lib/`)

| Datei | Aufgabe |
| --- | --- |
| `AppInfo/Application.php` | App-Bootstrap, Konstante `APP_ID` |
| `Controller/PageController.php` | liefert die Seite `templates/main.php` und lädt `js/stoffe-main.mjs` |
| `Controller/FabricController.php` | Fotos auflisten, hochladen, speichern, umbenennen, löschen; übersetzt Ausnahmen in 400/403/404 |
| `Controller/SettingsController.php` | Kategorien, Ordnerwahl |
| `Service/FabricService.php` | Kernlogik: Liste, Upload, Werte prüfen, Umbenennen, Kategorie in allen Fotos umbenennen, Cache |
| `Service/XmpService.php` | JPEG-Segmente lesen/schreiben, XMP-Paket bauen |
| `Service/PhotoFolder.php` | gewählter Ordner, Kandidaten inkl. Freigaben, Rechte |
| `Service/SettingsService.php` | `.stoffe.json`, Stoffartenliste, letzter Lagerort |

### Frontend (Vue 3 Options API, `src/`)

| Datei | Aufgabe |
| --- | --- |
| `main.js` | mountet `App` auf `#stoffe` |
| `api.js` | alle API-Aufrufe, `FIELDS`, Formatierer (`formatPrice`, `formatSize`, `displayName`), URLs für Vorschau und Dateien-App |
| `App.vue` | Layout (`NcContent`), Filter in der linken Navigation, Galerie, Hochladen (Knopf und Ziehen/Ablegen), Wechsel Galerie/Tabelle, „Speichern und weiter“ in der sichtbaren Reihenfolge |
| `components/FabricSidebar.vue` | Seitenleiste: Titel, Lagerort (vorausgefüllt), Preis, Maße, Reststück, Stoffart, Kategorie, Kommentar, Löschen, „In Dateien öffnen“ |
| `components/FabricTable.vue` | Tabelle: Spaltenauswahl, Spaltenfilter (Text „enthält“, Zahlen `150`, `>=140`, `<20`, `100-200`), Mehrfachsortierung mit Umschalttaste |
| `components/CategoryManager.vue` | Kategorien anlegen, umbenennen, löschen (in den Einstellungen der Navigation) |
| `components/FolderSwitcher.vue` | Ordnerwahl im Kopf |

Filter der Galerie: Suche (jedes Wort muss in Dateiname, Lagerort, Stoffart,
Kategorie oder Kommentar vorkommen), Raum → Schrank → Fach
(abhängig voneinander), Stoffart, Kategorie, Preis von/bis, Mindestlänge,
Mindestbreite, Reststück. Jede Auswahl kennt „(ohne Angabe)“ für leere Felder.
Gefiltert wird im Browser; der Server liefert immer die ganze Liste.

## 7. Bauen und testen

### Bauen

- Node ≥ 20 (CI nutzt 22). `npm ci && npm run build` erzeugt `js/` und `css/`
  (beide nicht eingecheckt). `npm run watch` baut bei Änderungen neu.
- Abhängigkeiten: `@nextcloud/vite-config` braucht **vite ^7** und
  **typescript ~5.9**. Vite 8 oder TypeScript 7 brechen den Build; nicht
  hochziehen, ohne den Build zu prüfen.
- `./scripts/package.sh` baut `build/stoffe-<version>.tar.gz` mit genau einem
  Ordner `stoffe/` (appinfo, lib, templates, img, js, css, LICENSE, README,
  INSTALLATION; ohne Quellen und Source Maps).

### Testumgebung (Docker)

Es gibt keine automatisierten Tests im Repo. Geprüft wird in einer echten
Nextcloud:

```bash
# in einer Umgebung ohne laufenden Docker-Dienst zuerst: sudo dockerd &
npm ci && npm run build
docker run -d --name nc -p 8080:80 \
  -e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
  -v "$PWD":/var/www/html/custom_apps/stoffe \
  nextcloud:35-apache
docker exec -u www-data nc php occ app:disable firstrunwizard   # sonst verdeckt der Dialog die Oberfläche
docker exec -u www-data nc php occ app:enable stoffe
```

- Getestet mit `nextcloud:32-apache` und `nextcloud:35-apache`; Daniel nutzt
  Nextcloud AIO 35.0.1 (Container `nextcloud-aio-nextcloud`).
- Für geteilte Ordner zweite Nutzer anlegen
  (`occ user:add --password-from-env bob` mit `OC_PASS=…`) und per
  `occ`/Oberfläche einmal mit Lese-, einmal mit Schreibrecht teilen.
- Oberflächentests mit Playwright (Chromium) gegen `http://localhost:8080`:
  anmelden, Fotos hochladen, Seitenleiste ausfüllen, Filter und Tabelle
  prüfen, Screenshots ansehen.
- XMP prüfen: Datei herunterladen und z. B. mit `exiftool -xmp:all foto.jpg`
  ansehen.

Checkliste vor einem Release: Hochladen (JPEG ok, PNG abgelehnt), Speichern
aller Felder, Umbenennen (auch Konflikt), Kategorie anlegen/umbenennen/löschen,
Filter und Tabelle, geteilter Ordner mit Lese- und Schreibrecht, Update von
der Vorversion mit vorhandenen Fotos.

## 8. Release

1. `<version>` in `appinfo/info.xml` und `package.json` erhöhen (Semver).
2. Über einen Pull Request auf `main` bringen. Merge und Release nur nach
   Freigabe durch Daniel.
3. Release auslösen, entweder per Tag `vX.Y.Z` (muss zu `info.xml` passen)
   oder per *Actions → Release → Run workflow* auf `main`; der Workflow
   setzt den Tag dann selbst. (In den Claude-Cloud-Sitzungen sind Tag-Pushes
   gesperrt, dort den Workflow manuell starten.)
4. Der Workflow `.github/workflows/release.yml` baut mit `scripts/package.sh`
   und veröffentlicht `stoffe-X.Y.Z.tar.gz` als GitHub-Release.

## 9. Versionen

| Version | Inhalt |
| --- | --- |
| 0.2 | Galerie, Seitenleiste, Filter, Kategorien, XMP-Speicherung |
| 0.3.0 / 0.3.1 | Länge, Breite, Reststück; Filter nach Mindestmaßen; Release-Workflow |
| 0.4.0 | Titel = Dateiname (Umbenennen, Suche), Tabellenansicht, wählbare und geteilte Ordner mit Lese-/Schreibrecht, Ordnerwahl und letzter Lagerort pro Nutzer |
| 0.5.0 (geplant) | Detailbilder, siehe 10 |

## 10. Detailbilder (geplant, 0.5.0)

In Arbeit, noch nicht in `main`. Geplanter Stand:

- Zu jedem Stoff kann es weitere Fotos geben. Sie liegen in einem Ordner neben
  der Hauptdatei mit demselben Namen ohne `.jpg`/`.jpeg`
  (`Stoffe/Blauer Jersey.jpg` → `Stoffe/Blauer Jersey/`).
- Der Ordner wird beim Umbenennen des Stoffs mit umbenannt und beim Löschen
  mit in den Papierkorb gelegt.
- Hauptfotos bekommen beim Hochladen immer die Endung `.jpg`, falls sie keine haben.
- Geplante Routen: `GET /fabrics/{id}/details`
  (`{ details: [{id, name, size, mtime, canDelete}], canUpload }`),
  `POST /fabrics/{id}/details` (multipart `photos[]`, Antwort wie beim
  Upload), `DELETE /fabrics/{id}/details/{detailId}`. `GET /fabrics` liefert
  je Stoff zusätzlich `detailCount`.

Nach dem Merge: diesen Abschnitt in „umgesetzt“ ändern, die Routen in die
Tabelle in 5 übernehmen und die Tabelle in 9 anpassen.

## 11. Bekannte Grenzen

- **Nextcloud 36**: `max-version` ist 35. Ein Update auf 36 deaktiviert die
  App, bis `info.xml` angehoben und mit 36 getestet ist.
- **Große Ordner**: `GET /fabrics` liest den ganzen Ordner; ohne Memcache
  (APCu/Redis) wird bei jedem Aufruf jedes Foto geöffnet. Filter und Sortierung
  laufen im Browser. Für einige hundert Fotos passt das, für viele tausend nicht.
- **Nur eine Ebene**: Fotos in Unterordnern des gewählten Ordners werden nicht gezeigt.
- **Nur JPEG**: HEIC-Fotos vom iPhone müssen vorher umgewandelt werden.
- **Gleichzeitiges Bearbeiten**: Es gibt keine Sperre über die Nextcloud-Dateisperren hinaus;
  wer zuletzt speichert, gewinnt.
- **Kategorie umbenennen** schreibt jedes betroffene Foto neu (neue mtime,
  Sync-Clients laden es neu); einzelne Fehler werden nur protokolliert.
- **Kein Nextcloud-Übersetzungssystem**: Texte sind fest deutsch im Code.
- **Keine automatisierten Tests**; siehe 7.
