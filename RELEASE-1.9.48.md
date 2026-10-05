# Publion 1.9.48 - adminmenuroutes

Gerichte patch op de geteste 1.9.47-release. Geen database-/datamigratie of nieuwe externe toegang.

## Oorzaak en herstel

De Diagnose-submenuhook werd tijdens plugininclude toegevoegd, terwijl het parentmenu pas via plugins_loaded zijn admin_menu-hook kreeg. Beide stonden op prioriteit 10. Diagnose liep eerst: WordPress kende de parent nog niet, registreerde de orphan admin_page_publion-diagnostics-hook en maakte geen gebruikelijke dashboardentry. Het eerste submenu stuurde de hoofdmenuklik naar een ongeldige wp-admin/publion-diagnostics-route.

Registratie is nu centraal: parent publion, expliciete dashboardentry publion op positie nul, vervolgens publion-diagnostics. De juiste hooks zijn toplevel_page_publion en publion_page_publion-diagnostics. WordPress rendert admin.php?page=publion en admin.php?page=publion-diagnostics. Geen redirectpleister. Generatie/settings blijven bestaande dashboardtabs; callbacks controleren manage_options.

## Verificatie

56/56 regressies op WordPress 7.1.2/SQLite en WordPress 6.0.11/MariaDB strict mode, PHP 8.2.12, inclusief oorspronkelijke 52 pipelinegevallen. Tests gebruiken daadwerkelijke hooks, geregistreerde pages, core-menuoutput, directe callbacks/access en ontbrekende rechten. De geïsoleerde ingelogde WordPress-browser volgt beide sidebarlinks zonder 404; contentplanningtab reageert op geladen admin.js. Ook het opnieuw gemaakte installatiepakket wordt geïnstalleerd en gecontroleerd. Alle provider-HTTP/mails in de suite zijn onderschept; browserproviderverkeer is geblokkeerd.

## Rollout

Zie RELEASE-1.9.47.md voor backups en rollback. Verifieer live geïnstalleerde versie en hash, bewaar oude pluginbestanden en DB/queue/settings voordat dezelfde pluginmap wordt vervangen. Geen uninstall; geen nieuwe jobs/AIposts als productietest; geen bestaande artikelen wijzigen. De gebruikersscreenshot bewijst een ingelogde andere browser, geen toegang in de beschikbare IAB. Die IAB toont nog het loginformulier; live installatie blijft afhankelijk van handmatig inloggen en backups. Test na update de beide concrete adminlinks en bestaande dashboardtabs.
