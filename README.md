# Publion

> AI-gestuurde contentplanning en artikelproductie voor WordPress — met een duidelijke SEO-brief, veilige conceptworkflow en praktische performancekoppelingen.

Publion helpt redacties, marketeers en ondernemers om van een categorie naar een gecontroleerd artikelconcept te werken. De plugin maakt niet alleen tekst: hij bouwt eerst een contentbrief met zoekintentie, focus-keyword, invalshoek en FAQ-vragen. Daarna kun je onderwerpen plannen, artikelen als concept maken, afbeeldingen laten genereren en de resultaten volgen in je eigen analyticsomgeving.

De huidige release is **1.9.24**. Download het WordPress-importpakket: [publion-wordpress-1.9.24.zip](publion-wordpress-1.9.24.zip). Het pakket bevat precies één hoofdmap: `publion/`. Dat is de vereiste WordPress-structuur en voorkomt dat WordPress een tweede, losstaande pluginmap maakt.

## In één oogopslag

| Onderdeel | Wat Publion doet |
| --- | --- |
| Contentplanning | Leest de bestaande contentkaart en genereert vijf relevante, afwijkende artikelkansen per categorie. |
| SEO-brief | Geeft elk voorstel een focus-keyword, zoekintentie, invalshoek en FAQ-vragen. |
| Artikelproductie | Schrijft een semantisch HTML-concept van minimaal 1.500 woorden. |
| Afbeeldingen | Maakt vijf contextuele afbeeldingen in de inhoud en één uitgelichte afbeelding. |
| Wachtrij en planning | Beheert handmatige en automatische onderwerpen, inclusief planning en bulkacties. |
| Kwaliteitsbasis | Stuurt op directe antwoorden, duidelijke koppen, relevante links, alt-tekst en interne links. |
| Publicatiecheck | Opent een toegankelijke SEO/SEA/GEO-checklist vóór publicatie of campagnegebruik. |
| Structured data | Kan dynamische `BlogPosting`- en `FAQPage`-gegevens publiceren, maar wijkt voor een actieve SEO-suite om dubbele schema's te voorkomen. |
| Dashboard | Toont operationele status, volgende acties, fouten en links naar performancebronnen. |
| Analytics | Opent de eigen Google Search Console- en GA4-rapporten vanuit het dashboard. |

## Voor wie is Publion?

Publion is bedoeld voor WordPress-sites die consistent goede artikelen willen publiceren zonder het redactionele oordeel uit handen te geven. Het past goed bij:

- marketingteams die een contentkalender willen opbouwen;
- ondernemers die in een vaste niche schrijven;
- SEO-specialisten die briefs, concepten en reviewmomenten willen structureren;
- bureaus die conceptproductie willen versnellen, maar publicatie willen controleren.

Publion is **geen** automatische rankingmachine. Organische zichtbaarheid blijft afhangen van originaliteit, expertise, autoriteit, techniek, concurrentie en gebruikerservaring. De plugin maakt het proces beter en consistenter, maar vervangt geen inhoudelijke review.

## Hoofdworkflow

```text
Categorie kiezen
    ↓
AI-onderwerpvoorstellen met SEO-brief beoordelen
    ↓
Beste kansen in de wachtrij zetten en plannen
    ↓
Artikelconcept + afbeeldingen laten maken
    ↓
Feiten, bronnen, toon en beeld controleren
    ↓
Publiceren
    ↓
Prestaties beoordelen in Search Console en GA4
    ↓
Volgende contentbrief aanscherpen
```

## Vereisten

- WordPress 6.0 of nieuwer
- PHP 7.4 of nieuwer
- Een OpenAI API-sleutel met toegang tot tekst- en afbeeldingsgeneratie
- Een werkende WordPress Cron of, voor productie, een echte servercron

Google Search Console en Google Analytics zijn optioneel. Je kunt er zonder API-koppeling vanuit Publion naartoe linken.

## Taal en lokalisatie

Publion volgt automatisch de actieve WordPress-taal. Voor beheerders geldt de persoonlijke taalvoorkeur van WordPress; daardoor kunnen redacteuren op dezelfde website de interface ieder in hun eigen taal gebruiken. De plugin bevat een Engelse basiscatalogus en valt bij een nog niet vertaalde niet-Nederlandse taal terug op Engels. Een specifieke vertaling kan als standaard WordPress-taalbestand worden toegevoegd in `wp-content/languages/plugins/publion-{locale}.mo`; die heeft voorrang.

Nieuwe AI-onderwerpvoorstellen en artikelen volgen juist de **sitetaal**. Zo kan een Engelstalige beheerder veilig op een Nederlandse website werken zonder per ongeluk Engelstalige content te publiceren.

## Installatie

### Installeren via WordPress

1. Download [publion-wordpress-1.9.24.zip](publion-wordpress-1.9.24.zip).
2. Ga in WordPress naar **Plugins → Nieuwe plugin → Plugin uploaden**.
3. Upload het zipbestand, installeer en activeer de plugin.
4. Open **Berichten → Publion**.
5. Voeg onder **OpenAI/ChatGPT instellingen** je API-sleutel toe.

### Handmatig installeren

1. Kopieer de map [`publion`](publion/) naar `wp-content/plugins/publion/`.
2. Activeer **Publion** op de WordPress-pluginpagina.
3. Open **Berichten → Publion** en voltooi de configuratie.

## Eerste configuratie

### 1. OpenAI verbinden

Ga naar **OpenAI/ChatGPT instellingen** en voeg de API-sleutel toe. Kies daarna het model en leg in de voorprompt vast:

- voor welke organisatie of website je schrijft;
- wie de doelgroep is;
- welke expertise, tone of voice en doelen belangrijk zijn;
- welke onderwerpen of claims je wilt vermijden.

De sleutel wordt als WordPress-optie `publion_api_key` opgeslagen. Plaats een sleutel nooit in broncode, screenshots of een Git-repository.

### 1a. Tekstmodel kiezen of zelf invullen

Publion gebruikt de officiële OpenAI API-model-ID's. De standaard voor een nieuwe installatie is **GPT-5.6 Terra**: een goede balans tussen kwaliteit, snelheid en kosten voor artikelproductie. De lijst bevat ook:

| Keuze | API-model-ID | Praktisch gebruik |
| --- | --- | --- |
| GPT-5.6 Sol | `gpt-5.6-sol` | Maximale kwaliteit voor strategische of complexe long-form artikelen. |
| GPT-5.6 Terra | `gpt-5.6-terra` | Aanbevolen standaard voor reguliere SEO- en GEO-artikelen. |
| GPT-5.6 Luna | `gpt-5.6-luna` | Kostenefficiënt voor grotere contentplanningen en snelle concepten. |
| GPT-5.4 | `gpt-5.4` | Sterke vorige generatie voor professionele taken. |
| GPT-5.4 Mini | `gpt-5.4-mini` | Lagere kosten en latency voor lichtere taken. |
| GPT-5.4 Nano | `gpt-5.4-nano` | Hoge volumes, classificatie en eenvoudige contentstappen. |

Kies **Eigen OpenAI model-ID…** als jouw API-project toegang heeft tot een ander model of een vaste snapshot. Vul uitsluitend de exacte API-ID in, bijvoorbeeld `gpt-5.6-sol`. Publion valideert het veilige formaat (maximaal 128 tekens) en OpenAI bevestigt bij de eerstvolgende aanvraag of dat model voor jouw project beschikbaar is. Een onjuiste of niet-beschikbare ID levert een concrete foutmelding op zonder je API-sleutel te tonen.

De namen **Sol**, **Terra** en **Luna** zijn echte GPT-5.6 API-varianten; `gpt-5.6` is de alias voor Sol. Kies geen naam uit ChatGPT of Codex als die niet exact als API-model-ID in je OpenAI-project beschikbaar is.

### 1b. Afbeeldingsmodel kiezen of zelf invullen

Tekst en afbeeldingen gebruiken afzonderlijke modelinstellingen. Voor afbeeldingen is **GPT Image 2** (`gpt-image-2`) de standaard voor nieuwe installaties. GPT Image 1.5 en GPT Image 1 blijven als eerdere opties beschikbaar. Kies **Eigen afbeeldingsmodel-ID…** wanneer je een exacte model-ID of snapshot uit jouw OpenAI API-project wilt gebruiken.

Publion gebruikt het gekozen afbeeldingsmodel via de OpenAI Images API. Een handmatig ingevuld model moet daarom deze endpoint ondersteunen; een ongeldige of niet-beschikbare keuze toont de concrete OpenAI-fout in het dashboard. Image-generatie kan kosten per beeld veroorzaken: test een nieuwe keuze eerst met één conceptartikel.

### 2. Postinstellingen bepalen

Ga naar **Instellingen voor postcreatie** en kies:

| Instelling | Gebruik |
| --- | --- |
| Tijdvenster en tijdstip | Verdeelt wachtrij-items over een voorspelbaar publicatieritme. |
| Poststatus | Kies bij voorkeur **Concept**; zo blijft review verplicht. |
| Standaardauteur | Koppelt automatisch gemaakte posts aan de juiste redacteur. |
| Automatisch onderwerp | Voegt op vaste momenten een nieuwe contentkans toe. |
| Externe bronwebsite | Geeft de AI een betrouwbare externe bronwebsite. Publion gebruikt de HTTPS-homepage als veilige fallback. |
| Geverifieerde externe bron-URL's | Zet één relevante HTTPS-URL per regel. Publion gebruikt precies één passende bron per artikel; zonder ingestelde bron verzint de plugin nooit een URL. |
| Live brononderzoek op internet | Zoekt voor de artikeltekst actuele bronnen via OpenAI web search. Dit is bewust opt-in, omdat het extra API-kosten en tijd kan geven. |
| Onderzoeksregels | Kies het researchmodel, 1â€“5 bronnen, contextdiepte, live toegang en wat er gebeurt wanneer onderzoek geen bruikbare bronnen oplevert. |
| Domeinbeleid | Sta alleen gezaghebbende domeinen toe of sluit bijvoorbeeld concurrenten en forums uit. De API ontvangt alleen gevalideerde hostnamen, maximaal 100 per lijst. |
| Klikbare bronnenlijst | Plaatst uitsluitend URLs die de live webzoekactie werkelijk heeft teruggegeven, met veilige linkattributen. |
| Rank Math | Bereidt alle controleerbare contenttests voor: unieke focus-keyword, keyword-led titel/meta/URL, intro, koppen, links, inhoudsopgave, 4+ afbeeldingen en korte alinea's. Met de integratie aan vraagt Publion circa 2.500-2.800 woorden; dat kost meer tijd en API-tegoed. Bij het openen in de editor start Rank Math automatisch zijn eigen analyse; de score blijft de echte Rank Math-score. |
| Structured data | Publiceert optioneel `BlogPosting`- en `FAQPage`-gegevens voor Publion-posts, tenzij Rank Math, Yoast of All in One SEO al articleschema beheert. |
| Thema-integratie | Volgt standaard de bestaande themastijl; biedt optioneel een verfijnde leesstijl en gescoped Custom CSS. |
| Afbeeldingafronding | Standaard **8px**, instelbaar van 0 tot 48px voor Publion-afbeeldingen. |
| Artikelstijl | Laat het thema de vormgeving bepalen of kies een verfijnde Publion-leesstijl. |
| Accentkleur en leesbreedte | Stuurt in de verfijnde stijl de linkkleur en maximale contentbreedte aan. |
| Eigen CSS | Laat beheerders alleen Publion-artikelen nauwkeurig aanpassen met gescopede CSS. |
| CTA | Voegt desgewenst een gecontroleerde call-to-action toe aan het artikel. |
| Notificatie-e-mail | Stuurt een melding nadat een automatisch artikel is gemaakt. |

### 3. Rapportlinks toevoegen

Plak optioneel de directe URL van de juiste Google Search Console-property en het gewenste GA4-rapport. Het Publion-dashboard krijgt dan knoppen die meteen naar de juiste omgeving openen. Dit vraagt geen extra OAuth-rechten of analyticsdata-opslag in Publion.

## Vormgeving en thema-integratie

Publion probeert het actieve WordPress-thema niet te vervangen. De standaard **Thema volgen** behoudt daarom de bestaande lettertypen, kleuren, tussenruimtes en contentcontainer van het thema. Alleen de ingestelde afbeeldingsafronding wordt op Publion-afbeeldingen toegepast.

Kies **Verfijnde Publion-leesstijl** wanneer je voor gegenereerde artikelen een consistente leesbreedte, verzorgde linkweergave en veilige responsive afbeeldingen wilt toevoegen. Deze stijl gebruikt drie instelbare waarden:

- **Accentkleur voor links** — standaard `#4f46e5`.
- **Maximale leesbreedte** — standaard 760px; voor long-form content is 720–800px meestal prettig.
- **Afbeeldingafronding** — standaard 8px, instelbaar van 0 tot 48px.

### Eigen CSS gebruiken

Het veld **Eigen CSS voor Publion-artikelen** is bedoeld voor beheerders die gerichte aanpassingen willen maken. Scope elke selector met `.publion-generated-post`; daardoor raak je geen pagina’s of posts die niet door Publion zijn gemaakt.

```css
/* Alleen gegenereerde Publion-artikelen. */
.publion-generated-post .entry-content h2 {
  letter-spacing: -0.02em;
}

.publion-generated-post .entry-content blockquote {
  border-left: 3px solid #4f46e5;
  padding-left: 1rem;
}
```

`@import`, `@charset` en `<style>`-tags worden verwijderd. Test CSS altijd eerst op een conceptartikel en controleer desktop én mobiel.

## Dashboard

**Overzicht** is de operationele startpagina. Het toont alleen cijfers die lokaal betrouwbaar vast te stellen zijn:

- items in de wachtrij en hoeveel daarvan gepland zijn;
- alle WordPress-concepten die nog review vragen;
- artikelen die Publion in de afgelopen 30 dagen heeft gemaakt;
- de status van structured data;
- eventuele problemen met de API-sleutel of de laatste afbeeldingsopdracht.

Het dashboard toont bewust geen geschatte rankings, clicks of AI-verkeer. Die zouden zonder een volwaardige, geautoriseerde datakoppeling misleidend zijn. Gebruik de knoppen naar Search Console en GA4 voor de brondata.

### De aanbevolen volgorde

1. **Plan onderwerpen met zoekintentie.**
2. **Maak en review concepten.**
3. **Meet resultaten en verbeter bestaande inhoud.**

De volledige dashboardhandleiding staat als normale tekst in **Berichten > Publion > Handleiding & diagnose**. Dezelfde workflow is ook beschikbaar als [PDF-handleiding](publion/publion-documentation.pdf).

## Contentplanning en SEO-brief

Kies onder **Content plannen** een WordPress-categorie en vraag voorstellen op. Publion vraagt de AI om exact vijf afzonderlijke kansen. Per kans zie je:

| Veld | Betekenis |
| --- | --- |
| Titel | Het voorgestelde artikelonderwerp. |
| Focus-keyword | De natuurlijke primaire term waarop het artikel focust. |
| Zoekintentie | Informatief, commercieel, transactioneel of navigerend. |
| Invalshoek | De concrete, onderscheidende belofte van het artikel. |
| FAQ-vragen | Vragen die aan het einde van het artikel als FAQ kunnen worden beantwoord. |

### Contentkaart en duplicaatbescherming

Vóór iedere onderwerpaanvraag bouwt Publion opnieuw een contentkaart uit alle lokale WordPress-berichten met titel, koppen en een relevant inhoudsextract. Die kaart gaat mee naar de AI, zodat een nieuw onderwerp een nog onbeantwoorde zoekvraag of een duidelijk andere invalshoek moet kiezen. Dit gebeurt ook voor de automatische dagelijkse onderwerpplanning.

Vóór een concept wordt opgeslagen, vergelijkt Publion de nieuwe titel en inhoud daarnaast lokaal met alle bestaande berichten, inclusief concepten en geplande posts. Identieke of sterk vergelijkbare titels en veel herhaalde langere woordreeksen blokkeren de creatie. De melding noemt het bestaande bericht dat aanleiding gaf. Dit is een technische vangrail, geen vervanging voor een redactionele review op inhoudelijke overlap of cannibalisatie.

Controleer deze brief voordat je iets in de wachtrij zet. Een goede brief past bij de doelgroep en de bestaande inhoud van je website; een AI-voorstel is geen zoekwoordonderzoek of feitelijke garantie.

## Wat er gebeurt bij artikelgeneratie

Wanneer je **Nu maken** gebruikt of een gepland item aan de beurt is, doorloopt Publion deze stappen:

Bij **Nu maken** staat de actuele fase met het bijbehorende workflowpercentage in de knop zelf. Het kruisje ernaast vraagt een veilige annulering aan. Publion stopt dan bij het eerstvolgende veilige servermoment; het wachtrij-item blijft behouden en er wordt geen half artikel als voltooid opgeslagen.

1. Leest het onderwerp, de categorie, de opgeslagen SEO-brief én de actuele contentkaart van bestaande berichten.
2. Laat een artikel schrijven met een directe beantwoording, semantische HTML, duidelijke `h2`/`h3`-structuur, voorbeelden en FAQ-sectie.
3. Vraagt de AI om feitelijk te blijven, niet te herformuleren wat al bestaat en geen bronnen, citaten, cijfers of ervaringen te verzinnen.
4. Vergelijkt titel en inhoud lokaal met alle bestaande berichten; bij een conflict wordt geen concept opgeslagen.
5. Schoont de HTML op, controleert externe links met een veilige HEAD/GET-fallback, bewaart ingestelde bron-URL's en verbetert interne links.
6. Genereert vijf contextuele afbeeldingen voor de inhoud en één uitgelichte afbeelding.
7. Voegt beschrijvende alt-tekst, lazy loading en de ingestelde border-radius toe.
8. Maakt een WordPress-post als concept of gepubliceerd item, afhankelijk van de instelling.
9. Slaat indien ingeschakeld Rank Math-data en dynamische article/FAQ-schema-informatie op. Bij het openen in de WordPress-editor vraagt Publion Rank Math om de eigen titel- en contenttests opnieuw uit te voeren.

### Verplichte review voor publicatie

Review ieder concept voordat je publiceert. Controleer minimaal:

- feitelijke juistheid en volledigheid;
- bronverwijzingen en externe links;
- merktoon, juridische claims en vertrouwelijke informatie;
- eventuele fictieve voorbeelden, getallen of citaten;
- afbeeldingen, alt-tekst en placeholders;
- titel, metabeschrijving en interne links;
- de daadwerkelijke aansluiting op de zoekintentie.

## SEO, AI-zoekmachines en structured data

Publion optimaliseert voor begrijpelijke, nuttige inhoud in plaats van keyword stuffing. De schrijfinstructies sturen onder andere op:

- direct antwoord aan het begin van een artikel;
- natuurlijke focus-term en duidelijke subonderwerpen;
- scanbare koppen, korte alinea’s en alleen zinvolle lijsten of tabellen;
- feitelijke, controleerbare inhoud;
- relevante interne links en veilige, verifieerbare externe links. Voor een gegarandeerde externe bron voeg je zelf minimaal één relevante HTTPS-URL toe in de postinstellingen;
- FAQ-vragen die inhoudelijk in de pagina worden beantwoord.

Als **Gestructureerde artikeldata** aan staat, genereert Publion op de frontend dynamische JSON-LD op basis van de definitieve post, auteur, datum, thumbnail en FAQ-inhoud. De data staat buiten de postcontent, zodat WordPress de JSON-LD niet tijdens opschonen verwijdert. Is Rank Math, Yoast of All in One SEO actief, dan geeft Publion de artikeldata aan die plugin uit: zo ontstaat er per artikel maar één schema-eigenaar.

Structured data helpt zoekmachines de pagina te begrijpen; het is geen belofte op een rich result of hogere positie.

### SEO, SEA en GEO/AEO praktisch gebruiken

Open op het dashboard **SEO / SEA / GEO-check** vlak vóór publicatie. De toegankelijke modal geeft drie afzonderlijke checks:

- **SEO:** controleer de zoekvraag, inhoudelijke consistentie, metadata, interne links, bronnen en afbeeldingen.
- **SEA:** controleer of een landingspagina exact aansluit op de advertentiebelofte en één meetbare conversieactie heeft. Een artikel wordt niet automatisch een advertentie of campagne.
- **GEO/AEO:** zorg voor een direct antwoord, heldere begripsdefinities, controleerbare feiten, relevante entiteiten, FAQ en correcte structured data.

Voor SEA is de veilige werkwijze om meerdere unieke assets te testen, niet om claims of zoekwoorden eindeloos te herhalen. Google adviseert voor responsive search ads meerdere unieke headlines en descriptions, met relevante landingspagina’s en een duidelijke waardepropositie. [Google Ads-richtlijnen](https://support.google.com/google-ads/answer/10530456)

De kwaliteitsmodal is volledig met toetsenbord te bedienen: openen met Enter of Spatie, sluiten met Escape of de sluitknop, en Tab blijft binnen de dialoog.

### Afbeeldingen en alt-tekst

Nieuwe Publion-artikelen gebruiken semantische `figure`-blokken met lazy loading en beschrijvende alt-tekst op basis van de relevante artikelpassage. De inhoud wisselt bewust brede 16:9-afbeeldingen af met compacte 1:1-afbeeldingen. Dat geeft een rustiger leesritme zonder de tekst met oude float-layouts te onderbreken. De afbeeldingradius is standaard 8px, is instelbaar van 0 tot 48px en krijgt voorrang op reguliere themaregels.

De alt-tekst is kort, contextueel en niet gevuld met zoekwoorden. Beoordeel bij review altijd of de tekst werkelijk beschrijft wat er op de gegenereerde afbeelding te zien is en pas hem in de WordPress-mediabibliotheek aan als dat niet zo is. Bestaande artikelen behouden hun inhoud, maar krijgen na de update wel de stevigere radiusregel; maak een artikel opnieuw aan of vervang de bestaande afbeeldingen om de nieuwe 16:9/1:1-structuur te gebruiken.

Google combineert alt-tekst met de inhoud rond een afbeelding om het onderwerp te begrijpen en benadrukt dat keyword stuffing de gebruikerservaring schaadt. [Google Image SEO](https://developers.google.com/search/docs/appearance/google-images)

## Analytics gebruiken

Gebruik Google Search Console voor organische zoekprestaties. De belangrijkste vier metrics zijn:

| Metric | Praktische vraag |
| --- | --- |
| Vertoningen | Wordt de pagina gezien voor relevante zoekvragen? |
| Klikken | Leidt zichtbaarheid daadwerkelijk tot bezoek? |
| CTR | Sluiten titel en snippet goed aan op de verwachting? |
| Gemiddelde positie | Verbetert of verslechtert de zichtbaarheid als trend? |

Een sterke eerste analyse is: zoek pagina’s met veel vertoningen en een lage CTR. Verbeter dan eerst de titel, metabeschrijving en aansluiting op de zoekintentie. Google beschrijft deze metrics en workflow in de [Search Console Performance-documentatie](https://support.google.com/webmasters/answer/7576553) en de [gids voor lage CTR](https://support.google.com/webmasters/answer/17010961).

Gebruik GA4 aanvullend om te zien wat bezoekers na de klik doen: betrokkenheid, conversies en vervolgacties. Vergelijk nooit zomaar totaalgegevens met paginaniveau; Search Console hanteert verschillende aggregaties per property en per URL.

## Planning en WordPress Cron

Publion plant wachtrij-items met WordPress Cron. WordPress Cron wordt normaal gestart tijdens websitebezoek. Dat is voldoende voor kleine sites, maar niet altijd precies genoeg voor productie.

Voor een betrouwbaardere planning:

1. Stel in WordPress een tijdvenster en aanmaaktijd in.
2. Configureer op productie een echte servercron die `wp-cron.php` periodiek aanroept.
3. Houd de wachtrij op **Postcreatie** in de gaten.
4. Gebruik **Nu maken** alleen als een item bewust direct moet starten.

## Fouten en diagnose

Publion toont relevante problemen in het dashboard en precies bij de actie waar ze optreden. Een actuele foutmelding bevat altijd de mislukte stap, een veilige oorzaak, een concrete vervolgstap en een foutreferentie zoals `PUBLION-OPENAI-MODEL`. Gebruik de voorgestelde knop om direct naar de juiste instellingen of diagnose te gaan. De referentie is veilig om met ondersteuning te delen; deel nooit een API-sleutel of ruwe providerrespons.

| Melding | Mogelijke oorzaak | Eerste actie |
| --- | --- | --- |
| AI is niet verbonden | Geen of ongeldige API-sleutel. | Voeg de sleutel toe in AI-instellingen. |
| Geen onderwerpvoorstellen | Sleutel, model, facturatie, netwerk of API-fout. | Controleer instellingen en probeer opnieuw met één categorie. |
| Afbeelding kon niet worden gemaakt | Afbeeldingsmodel of API-aanvraag faalde. | Review de opgeslagen fout; vervang de placeholder voor publicatie. |
| Wachtrij wordt niet opgeslagen | Ongeldige of lege selectie, sessie- of verbindingsprobleem. | Selecteer minimaal één voorstel en probeer opnieuw. |
| Geplande post verschijnt niet | WordPress Cron draaide niet. | Controleer planning en gebruik zo nodig servercron. |

De plugin behoudt bij een mislukte beeldgeneratie een placeholder, zodat de rest van de conceptworkflow niet stilvalt. Publiceer een placeholder nooit per ongeluk.

## Privacy en gegevens

- Onderwerpen, wachtrijgegevens en postinstellingen worden in de WordPress-database opgeslagen.
- De OpenAI API-sleutel wordt als WordPress-optie opgeslagen en nooit in deze repository opgenomen.
- Bij genereren worden het onderwerp, de categorie, de SEO-brief, de geconfigureerde voorprompt en een lokale contentkaart (titels, koppen en inhoudsextracten van bestaande berichten) naar de OpenAI API gestuurd.
- Google Search Console- en GA4-links zijn alleen opgeslagen URL’s; Publion haalt daarmee zelf geen analyticsdata op.
- Verwijder of anonimiseer persoonsgegevens voordat je ze aan een AI-prompt toevoegt.

## Projectstructuur

```text
publion/
├── publion.php                         # Plugin-entrypoint, hooks en tabelmigratie
├── includes/
│   ├── class-publion-admin.php          # Admininterface, dashboard en frontend-schema
│   ├── class-publion-ajax.php           # Wachtrij, instellingen en AJAX-acties
│   ├── class-publion-cron.php           # Geplande onderwerp- en postcreatie
│   ├── class-publion-settings.php       # Instellingenhelpers
│   └── functions-openai.php             # Tekst-, beeld- en SEO-hulpfuncties
├── assets/
│   ├── admin.css                        # Adminstyling
│   └── admin.js                         # Interacties en AJAX-feedback
├── readme.txt                           # WordPress.org-stijl plugininformatie
└── publion-documentation.pdf            # Volledige pluginhandleiding
```

## Ontwikkelen en testen

Er is geen buildstap of geconfigureerde test-suite. Voer voor een wijziging minimaal uit:

```powershell
php -l publion/publion.php
php -l publion/includes/*.php
node --check publion/assets/admin.js
rg "TODO|FIXME" publion
```

Test daarna handmatig in WordPress:

1. Alle tabs, dashboardacties en mobiele layout.
2. Opslaan van postinstellingen, rapportlinks en afbeeldingsradius.
3. Voorstelgeneratie met geldige én ongeldige API-sleutel.
4. Onderwerp opslaan, bulkacties en handmatige toevoeging.
5. Directe en geplande conceptcreatie.
6. Placeholdergedrag bij mislukte afbeeldingen.
7. Structured data en featured-image-styling op een gegenereerde post.

## Veelgestelde vragen

### Publiceert Publion zonder controle?

Alleen wanneer je expliciet **Gepubliceerd** als poststatus kiest. Voor de meeste sites is **Concept** de juiste standaard, omdat AI-inhoud altijd gecontroleerd moet worden.

### Kan ik bestaande artikelen met Publion herschrijven?

Niet in deze versie. Publion richt zich op de planning en productie van nieuwe WordPress-berichten.

### Ondersteunt de plugin custom post types?

Nee. Publion maakt standaard WordPress-berichten (`post`) aan.

### Worden afbeeldingen overal afgerond?

Alle afbeeldingen die Publion in de inhoud plaatst krijgen de ingestelde afronding. Uitgelichte afbeeldingen van Publion krijgen dezelfde class wanneer WordPress de thumbnail rendert. De standaard is 8px en is aanpasbaar van 0 tot 48px.

### Garandeert de plugin vindbaarheid in Google, ChatGPT of andere AI-systemen?

Nee. Publion ondersteunt een betere contentbasis en technische duidelijkheid, maar geen enkel hulpmiddel kan rankings, clicks of AI-vermeldingen garanderen.

## Licentie en credits

Publion is gelicenseerd onder [GPLv2 of later](publion/publion.php). Tekst- en afbeeldingsgeneratie gebruiken OpenAI. De plugin is ontwikkeld door Jaymian-Lee.
