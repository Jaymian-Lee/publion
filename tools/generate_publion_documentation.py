"""Build the bundled Publion manual as a polished, readable PDF."""

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "publion" / "publion-documentation.pdf"

NAVY = colors.HexColor("#16213a")
INDIGO = colors.HexColor("#4f46e5")
BLUE = colors.HexColor("#2563eb")
TEXT = colors.HexColor("#172033")
MUTED = colors.HexColor("#5d6b82")
LINE = colors.HexColor("#dbe3ef")
PALE = colors.HexColor("#f5f7ff")
GREEN = colors.HexColor("#eaf8f0")
AMBER = colors.HexColor("#fff7e7")


def styles():
    base = getSampleStyleSheet()
    return {
        "cover_kicker": ParagraphStyle(
            "cover_kicker", parent=base["Normal"], fontName="Helvetica-Bold",
            fontSize=9, leading=12, textColor=INDIGO, spaceAfter=12,
        ),
        "cover_title": ParagraphStyle(
            "cover_title", parent=base["Title"], fontName="Helvetica-Bold",
            fontSize=34, leading=40, textColor=NAVY, alignment=TA_CENTER, spaceAfter=14,
        ),
        "cover_subtitle": ParagraphStyle(
            "cover_subtitle", parent=base["Normal"], fontName="Helvetica",
            fontSize=13, leading=20, textColor=MUTED, alignment=TA_CENTER,
        ),
        "h1": ParagraphStyle(
            "h1", parent=base["Heading1"], fontName="Helvetica-Bold",
            fontSize=22, leading=28, textColor=NAVY, spaceBefore=2, spaceAfter=13,
        ),
        "h2": ParagraphStyle(
            "h2", parent=base["Heading2"], fontName="Helvetica-Bold",
            fontSize=14, leading=19, textColor=NAVY, spaceBefore=14, spaceAfter=7,
        ),
        "body": ParagraphStyle(
            "body", parent=base["BodyText"], fontName="Helvetica",
            fontSize=9.6, leading=14.2, textColor=TEXT, spaceAfter=7,
        ),
        "small": ParagraphStyle(
            "small", parent=base["BodyText"], fontName="Helvetica",
            fontSize=8.4, leading=11.7, textColor=MUTED, spaceAfter=4,
        ),
        "bullet": ParagraphStyle(
            "bullet", parent=base["BodyText"], fontName="Helvetica",
            fontSize=9.4, leading=13.6, textColor=TEXT, leftIndent=13, firstLineIndent=-8, spaceAfter=3,
        ),
        "table": ParagraphStyle(
            "table", parent=base["BodyText"], fontName="Helvetica",
            fontSize=8.4, leading=11.3, textColor=TEXT,
        ),
        "table_head": ParagraphStyle(
            "table_head", parent=base["BodyText"], fontName="Helvetica-Bold",
            fontSize=8.4, leading=11.2, textColor=colors.white,
        ),
        "note": ParagraphStyle(
            "note", parent=base["BodyText"], fontName="Helvetica",
            fontSize=9.2, leading=13.5, textColor=TEXT,
        ),
    }


S = styles()


def p(text, style="body"):
    return Paragraph(text, S[style])


def bullet(text):
    return p("- " + text, "bullet")


def section(title, intro=None):
    # Reserve a clear buffer below the repeating page header, including on a
    # page that starts with a two-line section title.
    items = [Spacer(1, 16 * mm), p(title, "h1")]
    if intro:
        items.append(p(intro))
    return items


def note(title, text, color=PALE):
    table = Table([[p(f"<b>{title}</b><br/>{text}", "note")]], colWidths=[170 * mm])
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), color),
        ("BOX", (0, 0), (-1, -1), 0.5, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 10),
        ("RIGHTPADDING", (0, 0), (-1, -1), 10),
        ("TOPPADDING", (0, 0), (-1, -1), 8),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
    ]))
    return table


def info_table(rows, widths=(42 * mm, 128 * mm)):
    data = [[p("Onderdeel", "table_head"), p("Wat je ermee doet", "table_head")]]
    data += [[p(a, "table"), p(b, "table")] for a, b in rows]
    table = Table(data, colWidths=list(widths), repeatRows=1, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("GRID", (0, 0), (-1, -1), 0.35, LINE),
        ("BACKGROUND", (0, 1), (-1, -1), colors.white),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#fafbfe")]),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return table


def header_footer(canvas, doc):
    canvas.saveState()
    width, height = A4
    canvas.setStrokeColor(LINE)
    canvas.line(20 * mm, height - 14 * mm, width - 20 * mm, height - 14 * mm)
    canvas.setFont("Helvetica-Bold", 7.5)
    canvas.setFillColor(INDIGO)
    canvas.drawString(20 * mm, height - 10 * mm, "PUBLION  |  HANDLEIDING")
    canvas.setFont("Helvetica", 7.5)
    canvas.setFillColor(MUTED)
    canvas.drawRightString(width - 20 * mm, 10 * mm, f"Versie 1.9.9  |  Pagina {doc.page}")
    canvas.restoreState()


def build_story():
    story = []

    story += [Spacer(1, 42 * mm), p("CONTENT ENGINE", "cover_kicker"), p("Publion", "cover_title")]
    story += [p("Handleiding voor betrouwbare contentplanning, AI-artikelproductie en review in WordPress.", "cover_subtitle"), Spacer(1, 15 * mm)]
    cover = Table([[p("<b>Van zoekintentie naar een controleerbaar concept.</b><br/>Inclusief onderwerpvalidatie, anti-duplicatie, SEO, SEA, GEO/AEO, beeldopmaak, analytics en diagnose.", "note")]], colWidths=[140 * mm])
    cover.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), PALE), ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 14), ("RIGHTPADDING", (0, 0), (-1, -1), 14),
        ("TOPPADDING", (0, 0), (-1, -1), 12), ("BOTTOMPADDING", (0, 0), (-1, -1), 12),
    ]))
    story += [cover, Spacer(1, 55 * mm), p("Voor WordPress beheerders en redacties", "cover_subtitle"), PageBreak()]

    story += section("1. Start hier", "Publion helpt je om onderwerpen en conceptartikelen te maken, maar publicatie blijft een redactionele beslissing. Kies bij voorkeur Concept als poststatus en review ieder resultaat.")
    story += [p("Snelle route", "h2")]
    story += [bullet("Ga naar Berichten > Publion > OpenAI/ChatGPT instellingen en sla je API-sleutel op."),
              bullet("Kies een tekstmodel en een afbeeldingsmodel. Gebruik een eigen model-ID alleen als jouw OpenAI-project die API-ID ondersteunt."),
              bullet("Open Content plannen, kies een categorie en vraag vijf onderwerpvoorstellen op."),
              bullet("Lees de SEO-briefs, voeg alleen passende onderwerpen toe aan de wachtrij en maak een concept."),
              bullet("Controleer feiten, bronnen, toon, beelden, meta description en interne links voordat je publiceert.")]
    story += [note("Belangrijk", "Publion toont alleen vijf volledig gevalideerde voorstellen. Een onvolledig of kapot JSON-antwoord wordt niet als kaart, wachtrij-item of artikelonderwerp gebruikt.", GREEN), Spacer(1, 6 * mm)]
    story += [p("Taal", "h2"), p("De beheerinterface volgt de persoonlijke WordPress-taal van de ingelogde gebruiker. Zonder specifieke taalvertaling gebruikt Publion buiten het Nederlands een Engelse basisinterface. Nieuwe onderwerpen en artikelen volgen altijd de WordPress-sitetaal, zodat de publieke content niet per ongeluk verandert wanneer een beheerder een andere profieltaal gebruikt.")]
    story += [p("Wat je in het dashboard ziet", "h2"), info_table([
        ("Overzicht", "Operationele status, openstaande acties, foutmeldingen en verwijzingen naar performancebronnen."),
        ("Content plannen", "Categorie, AI-voorstellen, focus-keyword, zoekintentie, unieke invalshoek en FAQ-vragen."),
        ("Postcreatie", "Wachtrij, planning, direct maken, voortgang en gemaakte concepten."),
        ("Instellingen", "Poststatus, auteur, planning, Rank Math, structured data, beeldradius en artikelstijl."),
        ("Handleiding & diagnose", "Kwaliteitscheck, foutuitleg en veilige volgende stappen."),
    ])]
    story.append(PageBreak())

    story += section("2. Instellen en veilig verbinden", "Gebruik een apart API-project voor productie. De sleutel wordt als WordPress-optie opgeslagen en na opslag niet opnieuw in het formulier getoond.")
    story += [p("Tekstmodel", "h2"), p("De modelkiezer bevat GPT-5.6 Sol, Terra en Luna, GPT-5.4 varianten en GPT-4o. Terra is de praktische standaard voor reguliere long-form concepten. Kies Sol voor complexe inhoudelijke opdrachten en Luna voor snelle, afgebakende stappen. Beschikbaarheid en prijs worden door je OpenAI-project bepaald.")]
    story += [p("Afbeeldingsmodel", "h2"), p("GPT Image 2 is de standaard. Nieuwe artikelen wisselen brede beelden en vierkante beelden af. Bij GPT Image 2 kan Publion een echte 16:9-breedbeeldresolutie aanvragen; voor compatibele oudere modellen gebruikt het een veilig standaardformaat."),
              p("Gebruik alleen een eigen afbeeldingsmodel-ID als deze de OpenAI Images API ondersteunt. Test na een modelwissel eerst met een conceptartikel."),
              note("Sleutelveiligheid", "Plaats een API-sleutel nooit in screenshots, berichten, GitHub of een voorprompt. Zie je een sleutel terug in een oud screenshot, trek die sleutel direct in en maak een nieuwe aan.", AMBER)]
    story += [p("Voorprompt", "h2"), p("Beschrijf kort de website, doelgroep, expertise, tone of voice, grenzen en gewenste bronnen. Vermijd instructies die de AI vragen om feiten, offertes, statistieken of ervaringen te verzinnen.")]
    story.append(PageBreak())

    story += section("3. Onderwerpvoorstellen die veilig de wachtrij in gaan", "Per aanvraag leest Publion de actuele contentkaart van bestaande berichten. Daardoor is de nieuwe zoekvraag of invalshoek bewust anders dan de al gepubliceerde en ingeplande inhoud.")
    story += [p("Elke kaart bevat", "h2"), info_table([
        ("Titel", "Een concrete zoekvraag voor een zelfstandig artikel."),
        ("Focus-keyword", "Een natuurlijke primaire zoekterm. Gebruik hem waar inhoudelijk logisch is, niet geforceerd."),
        ("Zoekintentie", "Informatief, commercieel, transactioneel of navigerend. Dit stuurt structuur en volgende stap."),
        ("Invalshoek", "Waarom dit artikel aanvullend en niet duplicerend is."),
        ("FAQ-vragen", "Drie of vier concrete lezersvragen voor een nuttige FAQ-sectie."),
    ]), p("Kwaliteitsgrens", "h2"),
              p("Publion accepteert alleen een compleet JSON-object met precies vijf kaarten. Losse velden, afgebroken antwoorden, haakjes en JSON-sleutels worden geweigerd. Als de AI geen veilige set kan maken, verschijnt een duidelijke foutmelding en wordt niets opgeslagen."),
              note("Als voorstellen blijven falen", "Controleer eerst de API-sleutel, facturatie en het gekozen model. Verkort daarna een extreem lange voorprompt en probeer opnieuw. Houd de contentkaart inhoudelijk scherp: sterk overlappende bestaande posts kunnen terecht tot minder bruikbare kansen leiden.", AMBER)]
    story.append(PageBreak())

    story += section("4. Van wachtrij naar conceptartikel", "Gebruik Nu maken voor een direct concept of stel een vast ritme in via WordPress Cron. Voor productie is een echte servercron betrouwbaarder dan verkeer-afhankelijke WP-Cron.")
    story += [p("De echte voortgang", "h2"), p("De knop Nu maken toont servergestuurde checkpoints: onderzoek, tekst genereren, tekst nakijken, afbeeldingen voorbereiden, afbeelding genereren, artikel samenstellen, concept opslaan, SEO en metadata en afronden. Het percentage hoort bij een feitelijk voltooid checkpoint; het is geen geschatte laadanimatie.")]
    story += [p("Kwaliteit van de artikeltekst", "h2"), bullet("Een volledig semantisch HTML-concept met directe antwoorden, h2/h3-koppen, korte alinea's en waar passend een lijst, tabel of stappenplan."), bullet("Een complete generatie in plaats van aan elkaar geplakte vervolgteksten. Dit beperkt herhalingen en ongesloten lijsten."), bullet("Lokale duplicate-check op titel en inhoud voordat een nieuw WordPress-concept wordt aangemaakt."), bullet("Alleen relevante, verifieerbare links. Externe links krijgen veilige rel-attributen.")]
    story += [note("Publiceer niet blind", "AI kan onjuiste of verouderde informatie geven. Controleer elke feitelijke claim, bron, veiligheidsinstructie, prijs, juridische uitspraak en merkbelofte voordat een concept live gaat.", AMBER)]
    story.append(PageBreak())

    story += section("5. Beelden, styling en toegankelijkheid", "Publion genereert vijf beelden in de inhoud en een uitgelichte afbeelding. Ieder beeld heeft een contextuele alt-tekst en lazy loading.")
    story += [p("Beeldritme", "h2"), p("Nieuwe artikelen gebruiken semantische figure-blokken. De inhoud wisselt brede 16:9-beelden af met compacte 1:1-beelden. Dat leest rustiger dan de oude float-opmaak en werkt beter op mobiel."),
              p("De standaard border radius is 8px. Onder Instellingen voor postcreatie kun je 0 tot 48px kiezen. De Publion-regel heeft voorrang op reguliere theme image-regels. Een eigen Custom CSS-regel blijft mogelijk voor een bewust afwijkend ontwerp."),
              p("Alt-tekst", "h2"), p("Alt-tekst is beschrijvend, kort en gekoppeld aan de passage rond het beeld. Controleer na generatie altijd of de tekst het daadwerkelijke beeld beschrijft. Corrigeer dit zo nodig in de WordPress-mediabibliotheek. Vermijd zoekwoordstapeling.")]
    story += [note("Bestaande artikelen", "De sterkere radiusregel werkt na de update ook voor bestaande Publion-afbeeldingen. Voor de nieuwe 16:9/1:1-structuur moet je de beelden in een bestaand artikel vervangen of het concept opnieuw maken.", GREEN)]
    story.append(PageBreak())

    story += section("6. SEO, SEA en GEO/AEO", "Publion optimaliseert voor duidelijke, nuttige informatie. Het is geen garantie op rankings, rich results, advertentiescores of zichtbaarheid in AI-antwoorden.")
    story += [p("SEO - organisch vinden", "h2"), bullet("Eén heldere zoekvraag in titel, eerste alinea en koppenstructuur."), bullet("Een leesbare meta description die op een woord- of zinsgrens eindigt."), bullet("Relevante interne links, betrouwbare bronnen en controleerbare feiten."), bullet("Afbeeldingen met passende alt-tekst en een bruikbare context eromheen.")]
    story += [p("SEA - campagneklaar", "h2"), bullet("Laat landingspagina, advertentiebelofte en conversieactie exact op elkaar aansluiten."), bullet("Test meerdere unieke headlines en descriptions; verzin geen claims of kortingen."), bullet("Gebruik analytics voor de echte cijfers. Publion simuleert geen conversies of rankingdata.")]
    story += [p("GEO/AEO - antwoordklaar", "h2"), bullet("Geef vroeg in het artikel een direct, volledig antwoord."), bullet("Definieer belangrijke begrippen en benoem relevante entiteiten concreet."), bullet("Gebruik FAQ alleen als vragen en antwoorden echt nuttig zijn."), bullet("Zorg dat feiten controleerbaar zijn; structured data verduidelijkt inhoud maar is geen rankingknop.")]
    story += [note("Schema zonder dubbeling", "Als Rank Math, Yoast of All in One SEO actief is, laat Publion de BlogPosting-schema-output aan die SEO-plugin. Dat voorkomt twee concurrerende BlogPosting-schema's op dezelfde pagina.", GREEN)]
    story.append(PageBreak())

    story += section("7. Review, publicatie en meten", "De beste workflow is: concept maken, inhoud beoordelen, metadata afronden, publiceren en daarna meten.")
    story += [p("Review voor publicatie", "h2"), bullet("Is de titel anders dan bestaande artikelen en beantwoordt hij een specifieke vraag?"), bullet("Klopt elke feitelijke claim en werkt elke bronlink?"), bullet("Passen tone of voice, CTA en auteur bij het merk?"), bullet("Zijn uitgelichte afbeelding, inline beelden en alt-teksten bruikbaar?"), bullet("Zijn focus-keyword, title en meta description inhoudelijk consistent?"), bullet("Staat er geen placeholderbeeld, onafgemaakte zin of dubbele tekst in het concept?")]
    story += [p("Na publicatie", "h2"), p("Gebruik Google Search Console voor vertoningen, klikken, CTR en gemiddelde positie. Gebruik GA4 voor gedrag en conversies als die goed zijn ingericht. Begin met pagina's die veel vertoningen maar weinig klikken krijgen: verbeter titel en description eerst, niet blind de hele tekst.")]
    story += [note("Geen nepdata", "Het dashboard linkt naar je eigen Search Console- en GA4-rapporten. Zonder geautoriseerde koppeling toont Publion bewust geen geschatte rankings, bezoekersaantallen of AI-verkeer.", PALE)]
    story.append(PageBreak())

    story += section("8. Diagnose en veelvoorkomende fouten", "Gebruik de laatste foutmelding in Overzicht of Handleiding & diagnose als startpunt. De melding toont geen API-sleutel.")
    story += [note("Foutmelding lezen", "Elke actuele foutmelding noemt de mislukte stap, de veilige oorzaak, een concrete vervolgstap en een referentie zoals PUBLION-OPENAI-MODEL. Gebruik de voorgestelde knop naar instellingen of diagnose. De referentie is veilig om met ondersteuning te delen; deel nooit een API-sleutel of ruwe providerrespons.", PALE)]
    story += [info_table([
        ("AI niet verbonden", "Voeg een geldige OpenAI API-sleutel toe en sla die op."),
        ("Geen voorstellen", "Controleer model, facturatie, netwerk en voorprompt. Probeer daarna opnieuw met een categorie."),
        ("JSON-fout bij voorstellen", "Er is niets opgeslagen. Vernieuw de voorstellen; kies eventueel een ondersteund model of verkort de voorprompt."),
        ("Afbeelding ontbreekt", "Een placeholder houdt het concept compleet. Vervang de placeholder voor publicatie en lees de afbeeldingsfout."),
        ("Geplande post komt niet", "Controleer de planning, WordPress-tijdzone en WP-Cron. Gebruik voor productie een servercron."),
        ("Geen resultaten", "Verbeter zoekintentie, titel en inhoud met Search Console-data. Vermijd bulkpublicatie van vergelijkbare stukken."),
    ]), p("Privacy en verantwoordelijkheid", "h2"), p("Een onderwerp, voorprompt en artikelinhoud worden naar OpenAI gestuurd om generatie mogelijk te maken. Stuur geen geheimen, persoonsgegevens of vertrouwelijke klantinformatie mee. Controleer auteursrecht en gebruiksrechten van elk beeld en elke bron voordat je publiceert."),
              note("Ondersteuning", "Noteer bij een fout het gekozen model, de stap waar het misgaat en de veilige foutmelding. Deel nooit je API-sleutel. Raadpleeg ook README.md in de pluginrepository voor de volledige changelog en installatie-instructies.", PALE)]
    return story


def main():
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc = SimpleDocTemplate(
        str(OUTPUT), pagesize=A4, leftMargin=20 * mm, rightMargin=20 * mm,
        topMargin=22 * mm, bottomMargin=18 * mm, title="Publion Handleiding", author="Jaymian-Lee",
    )
    doc.build(build_story(), onFirstPage=header_footer, onLaterPages=header_footer)
    print(OUTPUT)


if __name__ == "__main__":
    main()
