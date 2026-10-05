# Integratietests

**Alleen op een wegwerpinstallatie.** `integration.php` verwijdert testposts/attachments en queue-records. De runner weigert te starten tenzij host `publion.test` en sitetitel `Publion isolated tests` exact overeenkomen. Gebruik een aparte database/datamap; nooit een productie-installatie naar deze namen hernoemen.

Activeer de checkout als plugin. Maak de lokale testadmin `publion_test_admin`. Zet `PUBLION_TEST_REPO` naar het absolute pad van deze repository. Voer uit:

```sh
wp eval-file tests/integration.php --path=/absolute/disposable/wordpress
```

De suite onderschept alle HTTP/mails, maakt geen echte AI-aanvragen, en draait twee echte PHP-processen voor claim/post-concurrency. De fixture-key heeft geen live toegang. Met een lokaal uit de checkout gemaakte plugin-kopie eerst die kopie synchroniseren.

Gecontroleerd in deze taak:

- WordPress **7.1.2**, officiële SQLite-integratie, PHP **8.2.12**: **52/52 geslaagd**.
- WordPress **6.0.11**, afzonderlijke **MariaDB 10.4.32**, strict SQL-mode, PHP **8.2.12**: **52/52 geslaagd**.
- PHP syntax van alle plugin/test/CLI-PHP-bestanden; `node --check` op beide admin-JS-bestanden.
- PHPStan niveau **1** met een WordPress-bootstrap: geen fouten. Geen WPCS-config bestond in de baseline.
- Packagegenerator vergelijkt elke ZIP-entry byte voor byte met de pluginbron en controleert CRC/één hoofdmap/geen path traversal.

PHP 7.4-runtime en de daadwerkelijke productieomgeving zijn nog afzonderlijke compatibiliteitscontroles. WordPress 6.0.11 met de nieuwste SQLite-drop-in bleek onverenigbaar op dependency-niveau; daarom is de minimum-WP-suite op MariaDB uitgevoerd, zonder die dependency of WordPress te patchen.

Fixtures behandelen exacte incidenten, beide writers, topic/keywordfallbacks, JSON/refusal/truncation, onveilige/malformed HTML, geldige foutmelding-artikelen, repair behoud, no-image-costs bij slechte tekst, publicatie/future/SEO-gates, nonces/caps, leases/fencing/concurrency, snapshots/crashrecovery, bounded retries/starvation, source quote/provenance/freshness/contradictions, SSRF/robots, beelden/hergebruik/budget/hero/alt, FAQ-refresh, migratiebehoud en read-only Diagnose.
