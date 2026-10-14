# Publion 1.9.47 — review en rollout

Basis: `main` commit `2bcb6d24c96dbb66c0c67c88215d66e858849b8f`, plugin 1.9.46. Werkbranch: `fix/content-publication-safety`. De werkelijk geïnstalleerde versie op woonproblemen.nl is nog niet vastgesteld. Deze release is niet op productie geïnstalleerd.

## Concrete oorzaken

1. Daily cron vulde `focus_keyword` niet; de databasewaarde `''` passeerde beide `??`-fallbacks. Daardoor kon de artikel-/repairprompt een lege primaire zoekterm bevatten.
2. Generator en repair accepteerden elke niet-lege tekst, ook een HTTP-200 diagnose/weigering of afgebroken HTML. Beeldcontext en alttekst werden vervolgens uit die tekst gekopieerd.
3. `functions-openai.php` voegde zelf “Controleer deze bronnen altijd voordat je publiceert” toe aan publieke inhoud. Runtime, settings save, admin PHP en admin JS schakelden de SEO-gate uit bij `publish`.
4. Bronselectie mengde geraadpleegde/geciteerde URL's, knipte de eerste N af en behandelde ingestelde URL's als verificatie. Een homepage werd zonder inhoudelijk bewijs geforceerd toegevoegd. Dat is nu kandidaatinput; live bewijs en quote-/claimbeoordeling zijn aparte stages.
5. Percentageposities konden hetzelfde tekstblok/alt kiezen. Uitgelicht beeld hing uitsluitend van slot 6 af; falende slots hadden geen duurzame status/hergebruik. Het frontend-script verwijderde een figuur na elke tijdelijke image-error.
6. De lock had alleen queue-ID en leeftijd: een oude worker kon de opvolgerlock verwijderen. Fouten kwamen onbeperkt terug als pending. Tokens, renewal, fenced writes, snapshots en begrensde retry/review lossen dit op.

## Voor productie goedkeuren

- Leg geïnstalleerde pluginversie, WordPress/PHP/DB-versie, actieve SEO-plugin, media-offload en thema vast. Vergelijk installed files/hash met repo/ZIP; maak geen aanname dat live gelijk is aan main.
- Maak een databasebackup (queue, Publion-opties, betrokken posts/meta/revisions), pluginbestandenbackup en mediabackup. Controleer een herstelpunt. Nieuwe queuekolommen zijn additief; bestaande records, schema-data, modellen, prompts, cadence en settings blijven staan.
- Laat lopende workers afronden. Zet tijdens de rollout nieuwe Publion-generaties tijdelijk uit via de bestaande cadence/autotopic-instellingen; onthoud de oorspronkelijke keuzes. Installeer eerst op een stagingkopie. Een oude worker zonder nieuwe tokens mag niet parallel doorwerken tijdens upgrade.
- Upload `publion-wordpress-1.9.47.zip` als vervanging van dezelfde pluginmap. **Niet verwijderen/uninstalleren**: het bestaande opt-in uninstallbeleid kan gegevens wissen.
- Configureer bewust bronbeleid, max. bronpagina's/timeouts, beeldpogingen en beeldbeleid in **Publion → Diagnose en review**. Bestaande model/providerkeuzes worden behouden. Live research gebruikt dezelfde OpenAI-keuze; geen nieuwe provider/key nodig voor die bestaande contracten. Ondersteuning en echte API-billing moeten met de bestaande geautoriseerde sleutel op staging afzonderlijk worden bevestigd.
- Staging: één gecontroleerd concept, geen bulk. Controleer body/preview, claimmap, source fetch errors, alts/beeldbrief, hero, featured versus inline-modus, canonical/meta en schema in het werkelijk gebruikte thema/SEO-plugin. De featured-modus blijft afhankelijk van de thematemplate; inline-modus onderdrukt de standaard `post_thumbnail_html`, maar een thema dat rechtstreeks attachment-HTML rendert vraagt een themacheck.
- Pas na review en expliciete productie-rolloutscope installeren. Hervat de oorspronkelijke cadence na controle van queue/status/logs. Deze werkzaamheden hebben geen productiecontent veranderd.

## Herstelvoorstel voor bestaande incidenten (geen bulkactie)

De vier bekende URL's blijven behouden:

- `/achterzetraam-tegen-verkeerslawaai-wanneer-werkt/`: nieuwe inhoudelijke draft voorbereiden; lege zoekterm/diagnose en afgeleide altteksten herstellen.
- `/thuisbatterij-in-huurwoning/`, `/waarom-verliest-mijn-slimme-thermostaat-steeds-de/`, `/ventilatierooster-schoonmaken-zo-verwijder-je/`: originele HTML/revisions bewaren; redactionele instructies verwijderen in een herstelconcept; claims/bronnen/altteksten individueel beoordelen.

`wp eval-file tools/audit_posts.php > publion-audit-originals.json` is een **read-only** export van de bekende en structureel verdachte Publion-posts met originele content, excerpt, IDs/URLs, hashes en voorstellen. Het script wijzigt/publiceert/verwijdert niets en moet in de geautoriseerde siteomgeving worden uitgevoerd. Maak daarnaast een gewone DB-/mediabackup; dit JSON-bestand is geen volledige sitebackup. Diagnose toont de laatste 100 artikelen; de CLI-export pagina's door alle Publion-posts.

Het contactlabel `info@woonproblemen.nl` met `mailto:contact@mysite.com` en ongerelateerde theme-related-articles zijn niet teruggevonden als door Publion gegenereerde componenten. Verifieer en herstel die in thema/siteconfiguratie als afzonderlijke sitefix. Publions automatische interne links worden wel conservatiever op titel/context gematcht.

## Rollback en onzekerheden

- Vervang alleen de pluginbestanden door de geback-upte geïnstalleerde versie en herstel zo nodig specifiek relevante queue/settings uit backup; overschrijf geen nieuwe redactionele wijzigingen met een volledige oude DB. De extra kolommen mogen blijven staan. Stop nieuwe jobs tijdens rollback. Oudere code heeft de beschreven gaten nog steeds.
- Letterlijke quote-match en modelreview zijn geen bewijs van feitelijke waarheid, actuele wetgeving of medische juistheid. Broninhoud blijft onbetrouwbare data. Onbekende bronouderdom/gezag en tegenstrijdigheden zijn zichtbaar; gebruik `require_review` waar alle nieuwe content menselijke bronreview vereist.
- Geen vision-model toegevoegd: alts beschrijven de generatiebrief. Controleer echte assets vóór publicatie waar nauwkeurige beeldbeschrijving noodzakelijk is. Media-offload kan `publion/attachment_is_ready` integreren; standaard wordt een lokaal geldig attachmentbestand vereist.
- WordPress safe HTTP valideert URL/DNS/redirects; geen eigen DNS-pinning toegevoegd. Alleen HTML/plaintext, robots/toegang respecteren, begrensde bytes/time, geen login-/bot-block bypass.
- Een onzekere betaalde API-timeout kan al door de provider zijn verwerkt. Lokale ready assets worden nooit opnieuw aangevraagd; providerbilling bij verloren antwoorden kan zonder provider-idempotency niet exact-once worden gegarandeerd. Retries blijven begrensd.
- Systeem/PHP/proxytimeouts kunnen een lange job afbreken. Stages verlengen PHP-runtime binnen hostmogelijkheden; snapshots en leases ondersteunen herstel. Elke aanvraag en elk beeldslot heeft een bovengrens; geen onbegrensde generatie.
- De lokale suites gebruiken echte WordPress/MySQL/SQLite, maar gemockte providers. Productiesleutel/modelrechten, CDN, live thema/SEO-plugin en echte bronsemantiek zijn nog geen bewezen productieresultaat.
