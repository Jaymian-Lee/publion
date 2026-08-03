# Publion dashboardhandleiding

## Waarvoor dient het overzicht?

Het overzicht is de startplaats voor de contentworkflow. Het toont uitsluitend gegevens die Publion en WordPress lokaal kunnen vaststellen:

- onderwerpen die nog in de wachtrij staan;
- ingeplande onderwerpen;
- WordPress-concepten die een review nodig hebben;
- posts die Publion in de afgelopen 30 dagen heeft aangemaakt;
- de status van gestructureerde data.

Verkeers- en rankingcijfers worden niet geschat. Open hiervoor Google Search Console of Google Analytics via de knoppen in het overzicht.

## Vaste workflow

1. **Verbind OpenAI.** Voeg een API-sleutel toe in *OpenAI/ChatGPT instellingen*.
2. **Plan artikelen.** Kies een categorie en beoordeel per voorstel de focus-term, zoekintentie, invalshoek en FAQ-vragen.
3. **Zet alleen goede kansen in de wachtrij.** Kies een realistisch ritme in *Instellingen voor postcreatie*.
4. **Maak een concept.** Controleer altijd feiten, bronnen, interne links, merktoon, auteursrechten en afbeeldingen.
5. **Publiceer bewust.** Een concept is geen automatisch goedgekeurd artikel.
6. **Meet na publicatie.** Kijk in Search Console naar vertoningen, klikken, CTR en gemiddelde positie.

## Analytics gebruiken

Plak in *Instellingen voor postcreatie* een directe link naar de juiste Search Console-property en eventueel naar je GA4-rapport. De knoppen op het dashboard openen daarna precies die rapporten.

Gebruik Search Console als volgt:

- **Veel vertoningen, lage CTR:** herschrijf titel en meta description zodat ze de zoekvraag beter beantwoorden.
- **Veel klikken:** analyseer welke zoekvragen en structuur goed werken en gebruik die inzichten voor nieuwe briefs.
- **Dalende vertoningen of klikken:** vergelijk perioden en controleer eerst relevantie, actualiteit en technische problemen.
- **Gemiddelde positie:** zie dit als een trend, niet als een exacte rangorde voor één zoekopdracht.

## Vormgeving aanpassen

Ga naar *Instellingen voor postcreatie* en kies eerst **Artikelstijl**:

- **Thema volgen:** aanbevolen wanneer het actieve thema al een goede artikelweergave heeft. Publion past dan alleen de ingestelde afbeeldingsafronding toe.
- **Verfijnde Publion-leesstijl:** voegt een consistente leesbreedte, linkaccent en responsive beeldweergave toe zonder de rest van het thema te vervangen.

In de verfijnde stijl kun je de accentkleur en maximale leesbreedte instellen. Gebruik het veld *Eigen CSS voor Publion-artikelen* alleen voor gerichte aanpassingen en begin iedere selector met `.publion-generated-post`.

## SEO, SEA en GEO/AEO checken

Klik vanuit het dashboard op **SEO / SEA / GEO-check** voordat je publiceert of een artikel als landingspagina inzet. De modal helpt je drie verschillende vragen te beantwoorden:

1. **SEO:** beantwoordt de pagina een relevante zoekvraag en zijn links, metadata en afbeeldingen zorgvuldig gecontroleerd?
2. **SEA:** komt de belofte in een advertentie exact overeen met de landingspagina en is er één duidelijke conversieactie?
3. **GEO/AEO:** staat een direct, feitelijk antwoord vroeg in de pagina en wordt de inhoud ondersteund door heldere koppen, FAQ en data?

De modal is met toetsenbord te gebruiken: Enter of Spatie opent hem, Escape sluit hem, en Tab blijft binnen het venster.

## Afbeeldingen en alt-tekst

Publion voegt afbeeldingen als gewone HTML-afbeeldingen toe en maakt een korte alt-tekst op basis van de relevante passage. Controleer die tekst altijd in de mediabibliotheek: een goede alt-tekst beschrijft de afbeelding in context, herhaalt niet alleen het focus-keyword en bevat geen reeks zoekwoorden.

## Fouten oplossen

### AI is niet verbonden

Voeg een geldige API-sleutel toe. Controleer ook de facturatie en het gekozen model. De foutmelding op het dashboard vertelt wat je als eerste moet doen.

### Een afbeelding kon niet worden gemaakt

Publion bewaart de melding en gebruikt een placeholder. Vervang die afbeelding voordat je publiceert. Probeer bij een blijvende fout later opnieuw en controleer API-tegoed en de netwerkverbinding.

### Een geplande post verschijnt niet

Publion gebruikt WordPress Cron. WordPress Cron draait wanneer de website bezocht wordt. Voor een betrouwbare productieplanning is een echte servercron aanbevolen.

### SEO-resultaten blijven achter

Publiceer niet meer artikelen om een daling te maskeren. Controleer eerst de zoekintentie, titel, inhoudelijke diepgang, interne links en actualiteit van bestaande artikelen. Gebruik daarna Search Console-data om de volgende contentbrief te kiezen.

## Wat Publion wel en niet doet

Publion helpt met een consistente, technische en redactionele basis voor organische vindbaarheid. Het garandeert geen ranking, verkeer of vermelding in AI-antwoorden. Die resultaten hangen ook af van de kwaliteit en originaliteit van de inhoud, autoriteit, concurrentie, techniek en gebruikerservaring van de website.
