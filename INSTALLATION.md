# Installation

## Nextcloud All-in-One (AIO)

Bei Nextcloud AIO läuft Nextcloud im Docker-Container `nextcloud-aio-nextcloud`.
Der Ordner `custom_apps` liegt dort im Datenvolumen und bleibt bei Updates
von AIO erhalten. Alle Befehle werden auf dem Docker-Host ausgeführt.

1. Das Release-Archiv `stoffe-<version>.tar.gz` auf den Docker-Host laden und
   prüfen, dass oben der Ordner `stoffe/` liegt:

   ```bash
   tar -tzf stoffe-0.3.1.tar.gz | head
   ```

2. Das Archiv in den Container kopieren:

   ```bash
   sudo docker cp stoffe-0.3.1.tar.gz nextcloud-aio-nextcloud:/tmp/
   ```

3. Das Archiv in `custom_apps` entpacken:

   ```bash
   sudo docker exec -u www-data nextcloud-aio-nextcloud tar -xzf /tmp/stoffe-0.3.1.tar.gz -C /var/www/html/custom_apps/
   ```

4. Die App aktivieren:

   ```bash
   sudo docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable stoffe
   ```

5. Nextcloud im Browser neu laden (`https://<ip>` bzw. die Adresse deiner
   AIO-Installation). Oben in der Leiste erscheint „Stoffe“.

6. Optional das Archiv im Container wieder löschen:

   ```bash
   sudo docker exec nextcloud-aio-nextcloud rm /tmp/stoffe-0.3.1.tar.gz
   ```

### Update auf eine neue Version

Die Fotos und alle Angaben liegen im Ordner „Stoffe“ der Nextcloud-Dateien
und sind vom Update nicht betroffen. Nur der App-Ordner wird ersetzt:

```bash
sudo docker cp stoffe-<version>.tar.gz nextcloud-aio-nextcloud:/tmp/
sudo docker exec -u www-data nextcloud-aio-nextcloud rm -rf /var/www/html/custom_apps/stoffe
sudo docker exec -u www-data nextcloud-aio-nextcloud tar -xzf /tmp/stoffe-<version>.tar.gz -C /var/www/html/custom_apps/
sudo docker exec -u www-data nextcloud-aio-nextcloud php occ upgrade
```

## Andere Nextcloud-Installationen

1. Das Archiv in den Ordner `custom_apps/` (bzw. `apps/`) der Nextcloud
   entpacken, sodass `custom_apps/stoffe/` entsteht, und dem Webserver-Benutzer
   gehören lassen (z. B. `chown -R www-data:www-data custom_apps/stoffe`).
2. `sudo -u www-data php occ app:enable stoffe`

## Aus dem Quellcode

```bash
git clone https://github.com/danielschramm/stoffe-nextcloud custom_apps/stoffe
cd custom_apps/stoffe
npm ci
npm run build
```

Danach die App wie oben mit `occ app:enable stoffe` aktivieren.
