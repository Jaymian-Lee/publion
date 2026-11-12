# Publion 1.9.62

- Hersteld: de plugin-eigen bronnen-footer (div.publion-research-sources) telde mee in de instructie-leakcontrole. De exacte plugin-markup van die footer bevatte tot 1.9.5x de zin "Controleer deze bronnen altijd voordat je publiceert.", die ook in de leak-bloklijst staat. Oudere gepubliceerde berichten met die footer raakten daardoor bij elke bewerking+opslag gedegradeerd naar concept (waargenomen: post 2707 na een interne-linkbewerking; 41 gepubliceerde berichten dragen de zin latent). De footer is plugin-output, geen modeloutput, en wordt nu met een exacte class-tokenmatch (DOM) uitgesloten van de leakcontrole, in lijn met de bestaande woordtelling.
- Beveiliging behouden: de onveilige-markupregex scant nog steeds de volledige HTML inclusief footer (script/event-handler/javascript:-href in de footer blijft blokkeren); lookalike-classnamen (publion-research-sources-extra, xpublion-research-sources) worden nooit gestript; fouttekst in de footer kan geen artikel vervangen want de footer telt niet mee voor de lengte-/structuurvloer.
- readme.txt Stable tag bijgewerkt naar de echte releaseversie (was 1.9.52; veroorzaakte verwarrende versierapportages).
- tests/research-footer-leak-standalone.php: 12 checks (volledig artikel + oude/huidige footer doorlaatbaar, fouttekst + footer geblokkeerd, instructie buiten de footer geblokkeerd, lookalike-classes geblokkeerd, dubbele footer, script en javascript:-href in de footer geblokkeerd, ongesloten footer geblokkeerd via de tagbalans, in-footer verborgen fouttekst zonder artikel geblokkeerd via de lengtevloer, multi-class footer gestript).
- Regressie: de exacte opgeslagen bodies van de 9 historische concept-stubs (2679 t/m 2811) blijven geblokkeerd; bestaande suites ongewijzigd groen (leak-variants 21, focus-keyword 107, quality-report 18, affiliate 36, time 13).
- Eerlijke grens: de uitsluiting geldt alleen voor de exacte plugin-class; foutdetectie buiten de footer blijft een woordenlijst, met de structurele vloer en menselijke review als robuuste lagen.

# Publion 1.9.61

- Foutantwoord-blokkade uitgebreid met de waargenomen varianten uit de concept-stubs van sep/okt 2026 (posts 2679 t/m 2811): "de primaire zoekterm is niet opgegeven", "zoektermoptimalisatie/-frequentie/-dichtheid niet betrouwbaar" en "deze HTML niet betrouwbaar worden herschreven". Die stubs werden al geblokkeerd via de bronnen-footer en de lengtevloer, maar een langer foutantwoord in deze bewoording zonder die footer passeerde alle gates; dat restgat is nu dicht.
- Negatieve controles toegevoegd: legitieme artikelen die over zoektermen/SEO schrijven (advies, Rank Math-waarschuwingen, meetonzekerheid) worden niet gemarkeerd.
- tests/leak-variants-standalone.php: 21 checks (waargenomen foutzinnen, opgevuld >=150-woorden foutantwoord geblokkeerd, 8 negatieve controles, legitiem SEO-artikel door de volledige validator, bestaande detecties ongewijzigd).
- Eerlijke grens: dit blijft een woordenlijst, geen algemene foutdetectie; de structurele vloer (150 woorden/opbouw) en menselijke review blijven de robuuste lagen.

# Publion 1.9.60

- Grammatica gaat voor op exacte keywordplaatsing: de generatie-instructie eist niet langer de exacte focus-keyword in de eerste alinea; grammaticaal correct en natuurlijk Nederlands heeft nu expliciet voorrang, met de exacte vorm waar die past (kop/titel) en een grammaticale variant in de lopende tekst. Dit herstelt de structurele druk die ongrammaticale intro's gaf bij zinsfragment-keywords (bijv. "Wanneer een schuifpui loopt zwaar ...").
- Kwaliteitsrapport: focus_keyword_in_intro verplaatst van vereiste naar adviescheck (needs_editor_review). Een intro zonder exacte keyword triggert de (betaalde) auto-repair niet meer en blokkeert publicatie via de publish-gate niet meer; de afwijking blijft zichtbaar als advies. Alle overige vereiste checks (titel, meta, url, content, kop, lengte, alinealengte) en alle bron-/veiligheidsgates blijven ongewijzigd. Rapportversie 2.
- Achtergrond (gecorrigeerde aanname): de keyword in de intro werd nooit mechanisch ingevoegd - publion_ensure_rank_math_keyword_intro()/ _heading() zijn no-ops; de plaatsing was prompt-gedreven via publion_get_rank_math_generation_instruction().
- tests/quality-report-standalone.php: 18 checks op het echte rapport (intro-mismatch blijft publicabel + advies zichtbaar; ontbrekende keyword in content/kop blijft vereiste fout; exacte intro blijft herkend) en op de consumers (publish-gate en auto-repair lezen uitsluitend failed_required).

# Publion 1.9.59

- Hersteld: geplande artikelen konden tot gmt_offset uur te vroeg publiceren. De cron bewaakte scheduled_at met current_time('timestamp') (een offset-verschoven waarde) tegen een echte epoch uit publion_mysql_to_timestamp(); nu wordt met time() (echte epoch) vergeleken, zodat slots op hun geconfigureerde tijd blijven.
- publion_mysql_to_timestamp() verhuisd naar includes/functions-time.php zodat het conversiepad direct testbaar is.
- tests/time-standalone.php: 13 checks op de echte conversie (UTC, Europe/Amsterdam winter/zomer-DST, Australia/Sydney) plus een regressie die aantoont dat de oude vergelijking ~gmt_offset te vroeg publiceerde en de nieuwe blokkeert.

# Publion 1.9.52

- Nieuwe optionele affiliate-module (standaard uit): centraal beheerde aanbiedingen (id, label, url, type, intents, categories, keywords, placement, style, text, enabled) als JSON bij de postinstellingen.
- Generatie plaatst na alle inhoudsgates een neutrale [publion_affiliate]-shortcode: eerste match na de tweede H2, volgende voor de FAQ/onderaan. Het model schrijft zelf nooit affiliate-URL's; links blijven centraal aanpasbaar en verdwijnen netjes als een aanbieding uit staat of weg is.
- Front-end rendert blokken (box/button/link) met rel="sponsored noopener noreferrer" en target="_blank", plus automatische disclosure boven artikelen met een affiliate-link.
- Instellingen: module aan/uit, max. 1-3 blokken per artikel, eigen disclosure-tekst; de JSON wordt bij opslaan gevalideerd met duidelijke foutmeldingen.
- tests/affiliate-standalone.php: los draaiende unitchecks (parsing, matching, plaatsing, rendering, disclosure) zonder WordPress-installatie.

# Publion 1.9.51

- Slugs bij lange focus-keywords: publion_build_rank_math_slug() kapt niet meer midden in een zin af. Bij een te lange slug (vaak een volledige vraag als focus-keyword) worden Nederlandse stopwoorden verwijderd tot de keywordkern; ontkenningen (niet/geen/zonder) blijven bewust staan. Fallback blijft afkappen op woordgrens.

# Publion 1.9.50

- Geen door het model verzonnen afbeeldingen meer: prompt verbiedt <img>-tags/URL's en publion_strip_model_images() verwijdert ze uit elk modelantwoord.

# Publion 1.9.49

- GPT-5-modellen: het uitvoerbudget (max_completion_tokens) houdt rekening met reasoning-tokens (min. 12.000, max. 32.000). Voorheen werd het artikel bij 4096 tokens afgebroken (finish_reason "length") en door de strikte gate geweigerd.
- Bij finish_reason "length" een duidelijke melding; finish_reason en tokengebruik worden bewaard in de optie publion_last_openai_response_meta.

# Publion 1.9.48

- Dashboard en Diagnose worden samen na het hoofdmenu geregistreerd; juiste admin.php?page= routes en parentafhankelijke hooks. Dashboard blijft eerste submenu, ook bij vroege extensie. Beide callbacks controleren manage_options.
- Vier regressies voor daadwerkelijke admin_menu/coremenuoutput/hooks/directe toegang en rechten plus ingelogde browsernavigatie. De oorspronkelijke 52 pipelinegevallen blijven slagen.
- Verouderde settingsbeschrijvingen over placeholders, gateuitschakeling en gegarandeerde bronlinks zijn in lijn gebracht met de veilige pipeline.

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
