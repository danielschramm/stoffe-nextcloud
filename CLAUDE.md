# CLAUDE.md

Hinweise für KI-Assistenten (Claude Code u. a.), die an dieser App arbeiten.
Die vollständige Spezifikation steht in [SPEC.md](SPEC.md); zuerst lesen.

## Kurz

Nextcloud-App `stoffe`: Galerie und Tabelle für Stoff-Fotos (JPEG) mit
Seitenleiste zum Bearbeiten. PHP-Backend unter `lib/`, Vue-3-Frontend unter
`src/`, gebaut mit Vite nach `js/` und `css/`. Unterstützt Nextcloud 30–35.

## Regeln, die nicht verletzt werden dürfen

- **Keine Datenbank**: keine Tabellen, keine Migrationen, kein `IDBConnection`.
  Daten nur im XMP der JPEG-Dateien, Kategorien in `.stoffe.json`,
  Persönliches in `IConfig`-Nutzereinstellungen.
- **Cache nur wegwerfbar** (Memcache, Schlüssel `fileId-mtime-size`). Nichts
  darf nur im Cache stehen.
- **Nur JPEG**, MIME-Typ serverseitig per `finfo` prüfen.
- **Titel = Dateiname** ohne Endung; kein Titelfeld im XMP.
- Fremdes XMP (Kamera, andere Tools) beim Schreiben erhalten.
- Neue Felder an **beiden** Stellen ergänzen: `XmpService::FIELDS` und
  `FIELDS` in `src/api.js`, dazu `cleanValues`, Seitenleiste, Filter, Tabelle
  und SPEC.md. Alte Fotos ohne das Feld müssen weiter funktionieren (leer).
- Rechte über Nextcloud-Knoten prüfen (`isUpdateable`, `isCreatable`,
  `isDeletable`), Fehler als 403 mit deutscher Meldung.
- Merge auf `main` und Releases nur nach Freigabe durch Daniel.

## Stil

- Oberfläche und Meldungen deutsch, Code und Kommentare englisch.
- PHP: `declare(strict_types=1)`, Tabs, Konstruktor-Property-Promotion,
  Attribute `#[NoAdminRequired]`. Ausnahmen im Service, Übersetzung in
  HTTP-Status im Controller (`respond()`).
- Vue: Options API, Komponenten aus `@nextcloud/vue/components/…`, Icons aus
  `vue-material-design-icons`, API-Aufrufe nur über `src/api.js`.
- Kommentare sparsam, sie erklären das Warum.

## Befehle

```bash
npm ci
npm run build            # js/ und css/ erzeugen
npm run watch            # beim Entwickeln
./scripts/package.sh     # build/stoffe-<version>.tar.gz
php -l lib/**/*.php      # Syntax prüfen
```

Abhängigkeiten nicht über vite ^7 und typescript ~5.9 hinaus anheben
(`@nextcloud/vite-config` bricht sonst).

## Testen

Keine automatisierten Tests. Prüfen in Docker mit `nextcloud:35-apache`
(und `32-apache`), App nach `custom_apps/stoffe` mounten, `firstrunwizard`
deaktivieren, dann mit Playwright durch die Oberfläche klicken. Details und
Checkliste: SPEC.md, Abschnitt 7.

## Release

Version in `appinfo/info.xml` und `package.json` erhöhen, PR auf `main`,
nach Freigabe Workflow *Release* manuell starten (Tag-Pushes sind in
Cloud-Sitzungen gesperrt). Siehe SPEC.md, Abschnitt 8.

## Dokumentation pflegen

Bei jeder Funktionsänderung README.md (für Nutzer) und SPEC.md (Datenmodell,
API, Versionen, Grenzen) mit anpassen.
