# Publion 1.9.47

## Hersteld

- Lege/null/whitespace-zoektermen in oude en dagelijkse queue-records krijgen een gevalideerde onderwerpfallback. Ongeldige onderwerpen/SEO-briefs stoppen vóór API- of beeldkosten.
- Een gedeelde, verplichte inhoudsgate weigert JSON-fouten, weigeringen, afgebroken antwoorden, onvoldoende artikelstructuur, onveilige HTML, onderwerpafwijking en specifieke generatielekken. Een ongeldig herstelantwoord vervangt geen geldig concept.
- De publieke redactionele bronwaarschuwing is verwijderd. Exacte keyworddichtheid is uitsluitend advies; geen quotumprompt, geforceerde boilerplateintro of keywordprefix in alttekst.
- De vier lagen die de SEO-gate uitschakelden bij status `publish` zijn hersteld. Inhoudsveiligheid geldt ook zonder Rank Math en bij normale handmatige/future-publicatie van Publion-posts.
- Generatie schrijft eerst een gekoppeld concept; metadata, beelden en gates komen vóór publicatie. Cron-auteursselectie gebruikt een geldige WordPress-userreferentie.

## Pipeline

- Unieke claimtokens, conditionele lockverwijdering, leasevernieuwing per API/beeldstage en controles vóór postschrijf/publicatie. Retries gebruiken begrensde pogingen, backoff en duurzame redenen; probleemjobs blokkeren volgende jobs niet.
- Geldige tekst en succesvolle media worden per queuejob opgeslagen. Stabiele `hero`/`inline_*`-rollen vervangen vaste numerieke slotverwachtingen. Ontbrekende slots kunnen worden hersteld zonder geslaagde assets opnieuw te genereren.
- Afbeeldingsaltteksten komen uit hun specifieke generatiebrief, met expliciete vermelding dat geen visionverificatie is uitgevoerd. WordPress verzorgt dimensies/responsive media. Hoofdbeeldpromotie, optionele inlinehero en voorkoming van dubbele themathumbnails zijn instelbaar.
- Een browser-image-error verwijdert geen figuur meer: één herstelpoging, daarna zichtbare foutstatus. Fouten blijven per slot bewaard.
- Live onderzoek verzamelt kandidaat-URL's, onderscheidt geraadpleegd/geciteerd, haalt toegestane broninhoud veilig en begrensd op, respecteert robotsregels en beoordeelt relevante claims. Alleen teruggevonden letterlijke quotes komen in de claimmap. Bronstatus, timestamps, hashes, beperkingen en modelreview zijn zichtbaar; geen automatische feitverificatieclaim.
- Een tweede modelreview vergelijkt artikelclaims met het bewijs. Onzekerheid krijgt een concept/reviewstatus volgens het expliciete bronbeleid. Geen provider/model-fallback of reset van bestaande keuzes.
- Diagnose/review biedt read-only artikelcontrole, corrigeerbare jobretries, bronreview gebonden aan exacte tekst en herstel van ontbrekende beelden uitsluitend in concepten. FAQ-schema volgt contentwijzigingen.

## Validatie en beperkingen

Zie `tests/README.md` en `RELEASE-1.9.47.md`. De tests onderscheppen alle HTTP en mail; geen echte AI-generaties of productieposts zijn gebruikt. Feitelijke claimondersteuning en beeldinhoud blijven redactionele taken: modelreview of URL-bereikbaarheid bewijzen geen feit. Productie-installatie en herstel van bestaande artikelen zijn afzonderlijke rolloutacties.
