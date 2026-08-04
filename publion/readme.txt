=== Publion ===
Contributors: jaymian-lee
Donate link: https://jaymian-lee.nl
Tags: ai content, chatgpt, blog automatisering, post generatie, blogpost ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.9.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genereer en verfijn blogposts met AI. Kies een categorie, krijg onderwerp-ideeen, zet SEO-geoptimaliseerde posts met afbeeldingen in de wachtrij en plan het aanmaken in WordPress.

== Description ==

Publion is je persoonlijke content-assistent voor WordPress. Deze plugin is bedoeld voor bloggers, marketeers en ondernemers en laat je:

* Slimme onderwerpvoorstellen genereren voor een geselecteerde categorie, inclusief dynamische SEO-brief met focus-keyword, zoekintentie, invalshoek en FAQ-vragen.
* Voor iedere AI-run een actuele contentkaart van bestaande berichten gebruiken en nieuwe, te vergelijkbare concepten blokkeren.
* Een content-wachtrij opbouwen en beheren.
* Automatisch volledige blogposts (concepten) maken met ChatGPT.
* 6 contextbewuste AI-afbeeldingen genereren (5 in de content en 1 uitgelichte afbeelding).
* Postcreatie plannen zodat concepten op een consistent ritme verschijnen.
* "Nu maken" starten voor elk onderwerp in de wachtrij.
* Optioneel een CTA-blok aan het einde van elke post toevoegen.
* Schrijven voor mensen, Google en AI-zoekmachines: directe antwoorden, semantische kopstructuur, feitelijke bronverwijzingen, interne links en relevante alt-tekst.
* Een toegankelijke SEO/SEA/GEO-checklist in het dashboard voor review vóór publicatie of campagnegebruik.
* Optionele dynamische BlogPosting- en FAQ-structured data voor nieuwe Publion-artikelen, zonder dubbele articleschema's naast bekende SEO-plugins.
* De afbeeldingsafronding van alle Publion-afbeeldingen instellen; standaard 8px.
* Thema-vriendelijke artikelweergave: volg de bestaande themastijl of kies een verfijnde leesstijl met instelbare accentkleur, leesbreedte en eigen gescopede CSS.
* Actuele OpenAI-modelkeuze: GPT-5.6 Sol, Terra en Luna, GPT-5.4, Mini en Nano, met een gevalideerd veld voor een eigen model-ID.
* Afzonderlijke keuze voor GPT Image 2, eerdere afbeeldingsmodellen of een eigen afbeeldingsmodel-ID.
* API-sleutels worden na opslag niet meer in het instellingenformulier weergegeven; model- en API-fouten zijn duidelijker uitgelegd.
* Optionele Rank Math integratie met een dynamisch focus-keyword en meta description.
* Optioneel e-mailmeldingen ontvangen wanneer een nieuwe conceptpost is aangemaakt.

**Bespaar tijd. Blijf consistent. Laat je site groeien.**

== Installation ==

1. Upload de pluginmap naar de map '/wp-content/plugins/'.
2. Activeer de plugin via het menu "Plugins" in WordPress.
3. Ga naar **Publion** in je dashboard om te starten.
4. Vul je OpenAI API-sleutel in om AI-content te genereren.

== Frequently Asked Questions ==

= Heb ik een OpenAI-account nodig? =
Ja. Je hebt een OpenAI API-sleutel nodig om content te genereren.

= Publiceert deze plugin automatisch? =
Nee. De plugin maakt concepten (op schema of op aanvraag) zodat je ze handmatig kunt reviewen en publiceren.

= Kan ik direct een post maken zonder te wachten op het schema? =
Ja. Gebruik de knop **Nu maken** in de wachtrij.

= Waar komen de afbeeldingen vandaan? =
Publion genereert afbeeldingen met het gekozen OpenAI-afbeeldingsmodel. GPT Image 2 (`gpt-image-2`) is de standaard voor nieuwe installaties. Als genereren faalt, wordt een placeholder gebruikt zodat je die later kunt vervangen.

= Kan ik zelf een afbeeldingsmodel invullen? =
Ja. Kies **Eigen afbeeldingsmodel-ID…** onder OpenAI/ChatGPT instellingen en vul de exacte OpenAI API-model-ID in. Het model moet de Images API ondersteunen en beschikbaar zijn voor jouw API-project.

= Kan ik de afgeronde hoeken van afbeeldingen aanpassen? =
Ja. Ga naar **Instellingen voor postcreatie** en kies een waarde tussen 0 en 48px. Nieuwe en bestaande Publion-artikelen gebruiken standaard 8px.

= Kan ik zelf een OpenAI-model invullen? =
Ja. Kies **Eigen OpenAI model-ID…** onder OpenAI/ChatGPT instellingen en vul de exacte API-model-ID in. Publion controleert het formaat vooraf; OpenAI controleert vervolgens of dat model voor jouw API-project beschikbaar is.

= Garandeert Publion een positie in Google of AI-antwoorden? =
Nee. Publion maakt de inhoud technisch en redactioneel beter voorbereid, maar ranking hangt ook af van kwaliteit, autoriteit, concurrentie en technische SEO van de website.

= Ondersteunt dit custom post types? =
Deze versie maakt standaard blogposts aan.

== Screenshots ==

1. Genereer onderwerp-ideeen met AI.
2. Voeg geselecteerde onderwerpen toe aan de content-wachtrij.
3. Stel planning en CTA-instellingen in.
4. Maak automatisch volledige conceptposts met AI-afbeeldingen.

== Changelog ==

= 1.9.10 =
* Herstelt een opslagfout waarbij geldige geselecteerde onderwerpkaarten op sommige PHP/FastCGI-servers als een lege wachtrij konden aankomen.
* Wachtrijgegevens gebruiken nu een expliciet JSON-contract met veilige fallback voor bestaande browsersessies.
* Bij een echte validatiefout toont Publion per afgekeurd onderwerp de concrete titel- of categoriereden.

= 1.9.9 =
* Uitgebreide foutafhandeling voor onderwerpvoorstellen, wachtrij, planning, instellingen, modellen, prompt en postcreatie.
* Elke actuele fout bevat een veilige oorzaak, concrete vervolgstap, relevante knop en foutreferentie voor ondersteuning; API-sleutels worden uit foutteksten verwijderd.
* Bulkacties tonen een volledig eindoverzicht per mislukt onderwerp in plaats van alleen een nummer van het mislukte item.

= 1.9.8 =
* Publion volgt nu automatisch de WordPress-site- en gebruikerstalen: de beheerinterface heeft een Engelse basiscatalogus en kan worden uitgebreid met standaard WordPress-taalpakketten.
* Nieuwe onderwerpvoorstellen en artikelen volgen de WordPress-sitetaal, terwijl de persoonlijke beheertaal veilig alleen de interface beïnvloedt.
* Browsermeldingen, live voortgang en bevestigingsvensters gebruiken dezelfde vertaallaag als de PHP-interface.

= 1.9.7 =
* De dashboardhandleiding staat nu als volledige, gestructureerde tekst in de tab Handleiding & diagnose. De losse Markdown-handleiding is verwijderd.

= 1.9.6 =
* De gebundelde PDF-handleiding is volledig herschreven: installatie, modellen, onderwerpvalidatie, postcreatie, SEO/SEA/GEO, styling, analytics, diagnose, privacy en review.

= 1.9.5 =
* Onderwerpvoorstellen accepteren alleen vijf complete, gevalideerde SEO-briefs; JSON-fragmenten, losse velden en onvolledige antwoorden worden niet getoond of opgeslagen.
* De OpenAI-fallback houdt JSON-modus actief wanneer Structured Outputs niet beschikbaar is.
* De browser valideert ontvangen voorstellen opnieuw als extra bescherming tegen oude caches en gemanipuleerde responses.

= 1.9.4 =
* New articles use semantic image blocks in an intentional 16:9 and 1:1 rhythm; old float styles are no longer written into content.
* The configurable image radius is enforced against theme image selectors.
* Article generation uses one complete, strictly structured HTML response to prevent incomplete lists and repeated continuation text.
* Meta descriptions finish at a readable word or sentence boundary.
* Publion skips its own BlogPosting schema when Rank Math, Yoast, or All in One SEO is active, preventing duplicate article schema.

= 1.9.3 =
* “Nu maken” toont live, servergestuurde voortgang per artikel: onderzoek, tekst, afbeeldingen, SEO, opslaan en afronden.
* De voortgang is een feitelijke workflowstatus met percentage per voltooid checkpoint, inclusief een duidelijke foutstatus.

= 1.9.2 =
* Onderwerpvoorstellen gebruiken strikt gestructureerde JSON-uitvoer. Onvolledige AI-antwoorden kunnen niet langer als losse, foutieve onderwerpkaarten verschijnen.
* Het uitvoerbudget voor onderwerpvoorstellen is verhoogd en een duidelijke herstelmelding verschijnt bij een afgebroken respons.

= 1.9.1 =
* Verpakking gecorrigeerd voor WordPress: het importpakket bevat nu exact één `publion` pluginmap.
* Bootstrap en alle PHP-bestanden opnieuw gecontroleerd op syntax en veilig laden.

= 1.9.0 =
* Nieuwe afzonderlijke afbeeldingsmodelkiezer met GPT Image 2 als standaard, GPT Image 1.5, GPT Image 1 en een eigen model-ID.
* Afbeeldingsfouten tonen nu ook het modelgerelateerde OpenAI-antwoord veilig in het dashboard.

= 1.8.0 =
* Onderwerp- en artikelgeneratie lezen nu eerst een actuele contentkaart van bestaande WordPress-berichten.
* Dubbele of sterk vergelijkbare titels en inhoud worden lokaal geblokkeerd vóór er een WordPress-concept wordt aangemaakt.
* De handleiding legt de contentkaart, foutmelding en noodzakelijke redactionele controle uit.

= 1.7.0 =
* De modelkiezer bevat GPT-5.6 Sol, Terra en Luna, GPT-5.4, GPT-5.4 Mini en GPT-5.4 Nano naast eerdere modellen.
* Beheerders kunnen een eigen, gevalideerde OpenAI model-ID opslaan.
* De API-sleutel wordt na opslag niet opnieuw in het dashboard getoond en AI-fouten melden nu concreet wat er gecontroleerd moet worden.

= 1.6.0 =
* Verbeterde alt-teksten voor content- en media-afbeeldingen, zonder keyword stuffing.
* Nieuwe toetsenbordtoegankelijke SEO/SEA/GEO-checkmodal met concrete publicatie- en landingspagina-controles.
* Schrijfinstructies aangescherpt voor directe antwoorden, controleerbare feiten en eerlijke commerciële content.

= 1.5.0 =
* Nieuwe thema-vriendelijke artikelstijl: thema volgen of een verfijnde Publion-leesstijl.
* Instelbare accentkleur, maximale leesbreedte en veilig verwerkt Custom CSS voor Publion-artikelen.
* Gebruikers- en GitHub-documentatie uitgebreid met vormgeving, reviewflow en diagnose-informatie.

= 1.4.0 =
* Nieuw operationeel dashboard met wachtrij-, concept-, plannings- en 30-dagenstatus.
* Duidelijke volgende acties, contextuele foutmeldingen en een praktische diagnosehandleiding in de plugin.
* Directe, optionele snelkoppelingen naar de eigen Google Search Console- en Google Analytics-rapporten.
* Instellingen voor structured data en afbeeldingsafronding worden nu volledig opgeslagen vanuit de interface.

= 1.3.0 =
* Vernieuwde, rustigere admininterface met duidelijke SEO-briefs per onderwerp.
* Onderwerpgeneratie levert nu focus-keyword, zoekintentie, invalshoek en FAQ-vragen als dynamische data.
* Artikelen worden aangestuurd op behulpzame, feitelijke en AI-citeerbare content in plaats van keyword stuffing.
* Optionele BlogPosting- en FAQ-structured data op basis van de uiteindelijke post.
* 8px afbeeldingsafronding als standaard, instelbaar van 0 tot 48px.

= 1.0.0 =
* Eerste release met AI-onderwerpvoorstellen, wachtrijbeheer, geplande postcreatie (concepten), AI-afbeeldingen met placeholders, actie "Nu maken", optionele CTA en e-mailmeldingen.

== Upgrade Notice ==

= 1.0.0 =
Eerste release - genereer AI-gedreven conceptposts volgens planning en keur ze goed in je workflow.

== Credits ==

Afbeeldingen in posts worden gegenereerd met het gekozen OpenAI-afbeeldingsmodel (standaard: GPT Image 2).
AI-content wordt gegenereerd met OpenAI (ChatGPT).
Plugin ontwikkeld door Jaymian-Lee.
