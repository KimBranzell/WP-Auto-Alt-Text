# Ändringslogg

Här listas alla viktiga ändringar i WP Auto Alt Text. Senaste versionen står först.

## [2.0.0] – Ej släppt

Den här versionen gör att pluginet slutar störa WooCommerce, WordPress och flerspråkspluginer. Alt-texterna genereras nu i bakgrunden. De blir dessutom kortare och mer användbara för den som använder skärmläsare, produktens namn kommer med när du vill det, och stora bilder skalas ned innan de skickas, vilket sänker kostnaden per bild.

### Tillagt

#### Alt-texterna

- **Namn i alt-texten.** En ny inställning ersätter brand-tonalitet: "Bara det som syns i bilden", "Produktnamn" eller "Produkt- och varumärkesnamn". Med de två senare finns produktens namn alltid med när bilden hör till en enda produkt: pluginet kontrollerar texten och lägger till namnet om AI:n har glömt det. Sidor och inlägg används bara som sammanhang.
- **Produkt- och sidnamn som sammanhang.** När en bild hör till en produkt (även en variant, till exempel "Nordic Mug – Blå"), en sida eller ett inlägg får AI:n veta det, även om filnamnet är obegripligt. Med "Produktnamn" eller "Produkt- och varumärkesnamn" tas produktnamnet med i alt-texten. Används bilden på flera produkter skickas inget namn, eftersom ett felaktigt produktnamn är värre än inget.
- **Extra instruktioner.** Ett fält för egna instruktioner, till exempel "Nämn alltid färgen". De läggs sist i pluginets instruktioner och kan inte ändra svarets format.
- **Alt-textkontext.** I mediebiblioteket, på bildens redigeringssida och i förhandsgranskningen ser du vilken produkt, sida eller vilket inlägg alt-texten hör till, eller varför inget hittades. `wp auto-alt-text context <id>` visar samma sak i terminalen.
- **Skilj på AI-text och text skriven av en redaktör.** Pluginet märker de alt-texter det skriver. Ändrar en redaktör texten räknas den som redaktörens egen, var ändringen än görs. En ny inställning, "Skriv över befintliga alt-texter vid massgenerering", bestämmer vad massåtgärder, REST och WP-CLI får skriva över: bara saknade alt-texter (standard), även oredigerade AI-texter, eller allt. Befintliga AI-texter från tidigare versioner märks automatiskt i bakgrunden efter uppdateringen.

#### Modell och kostnad

- **Välj modell.** Inställningssidan visar de OpenAI-modeller pluginet stöder och ungefär vad 1 000 bilder kostar med varje. Standard är GPT-6.1 Sol, vald efter ett test på 31 bilder.
- **Vattenmärkning av AI-text.** Under modellvalet förklarar inställningssidan hur du slår på OpenAI:s vattenmärkning av text i ditt OpenAI-konto (Data controls → Text provenance). Pluginet ändrar aldrig AI:ns text, förutom när det lägger till produktnamnet.

#### Bakgrund och kö

- **Generering i bakgrunden.** Nya bilder (uppladdningar och produktbilder i WooCommerce) får sin alt-text i bakgrunden, några i taget. Uppladdningar och produktsparningar väntar inte längre på OpenAI. Om ett försök misslyckas försöker pluginet igen senare, med allt längre pauser mellan försöken.
- **Hantering av slut på krediter.** Om din OpenAI-kredit tar slut pausas genereringen. Webbplatsens administratör får ett mejl och ett meddelande i adminpanelen. Genereringen fortsätter av sig själv när krediten fyllts på, och inga köade bilder går förlorade.
- **Överblick över kön.** Statistiksidan visar hur många bilder som väntar, bearbetas, är klara eller har misslyckats. I terminalen visar `wp auto-alt-text queue` kön, `--status=failed` listar felen och `--retry-failed` försöker igen med de misslyckade bilderna.
- **Automatisk rensning av loggen.** Loggposter äldre än 30 dagar raderas en gång per dag.

#### WooCommerce

- **Massåtgärden för WooCommerce fungerar.** "Generate Alt Texts" i produktlistan fungerar nu. Den köar bilderna som saknar alt-text i de valda produkterna och visar hur många som köades.
- **Kompatibel med nya WooCommerce-funktioner.** Pluginet anger att det fungerar med WooCommerces High-Performance Order Storage (HPOS) och blocken för varukorg och kassa. Därför hindrar det inte längre att de funktionerna slås på.

#### Inställningar och API-nyckel

- **API-nyckeln kan ligga i wp-config.php.** Du kan ange OpenAI-nyckeln med `define('AUTO_ALT_TEXT_OPENAI_API_KEY', 'sk-…');` i stället för att spara den i inställningarna.
- **Tydligt besked när WordPress AI blockerar pluginet.** Om WordPress AI-plugin inte har godkänt pluginets anslutning till OpenAI visas en förklaring och en länk för att godkänna den (Verktyg → AI connector approval), i stället för ett otydligt felmeddelande. Genereringen pausas tills anslutningen är godkänd.

### Ändrat

#### Alt-texterna

- **Kortare och mer användbara alt-texter.** AI:n får nya instruktioner: högst cirka 125 tecken, huvudmotivet först, inga gissningar och inget säljspråk. Text som syns i bilden skrivs av exakt.
- **Brand-tonalitet har ersatts av "Namn i alt-texten".** Påslagen brand-tonalitet blir "Produkt- och varumärkesnamn" och avslagen blir "Bara det som syns i bilden".

#### Modell och kostnad

- **Ny standardmodell.** Webbplatser som inte har valt någon modell använder GPT-6.1 Sol i stället för GPT-5.2. Det kostar ungefär 3,40 USD per 1 000 bilder (GPT-5.2 med samma bildhantering: cirka 3,20 USD).
- **Billigare bildhantering.** Bilden skalas ned till den storlek modellen faktiskt använder (högst cirka 1 megapixel) innan den skickas. Originalfilen ändras aldrig.
- **OpenAI sparar inte anropen.** Pluginet ber OpenAI att inte lagra förfrågningarna.

#### Bakgrund och kö

- **Inställningen "Generera alt-texter automatiskt" gör nu bara det.** Den slår på eller av automatisk generering för nya uppladdningar och sparade produkter. Generera-knappen, massåtgärderna, WP-CLI och REST-API:t fungerar alltid, oavsett inställningen.

#### WooCommerce

- **Bilder köas bara när du sparar en produkt i adminpanelen.** Köp i kassan, lagerändringar, uppdateringar via REST/API och importer startar inte längre något. Importerade bilder tas fortfarande om hand när de laddas upp.

#### Mediebiblioteket och adminpanelen

- **Sidan laddas inte om.** När du genererar alt-text för flera bilder i mediebiblioteket visas ett "klart"-meddelande i stället för att sidan laddas om.
- **Pluginets skript och stilmallar laddas bara där de behövs:** i mediebiblioteket, när du redigerar inlägg och produkter, på sidor med mediaväljaren och på pluginets egna sidor.
- **API-nyckeln visas inte längre.** Fältet är ett lösenordsfält och visar aldrig den sparade nyckeln. Lämna fältet tomt för att behålla nuvarande nyckel.

#### Flerspråkiga webbplatser

- **WPML och Polylang.** Pluginet känner nu pålitligt av WPML och Polylang och låter dem bestämma vilket språk uppladdade bilder får.

#### WP-CLI

- **WP-CLI skriver inte längre över befintliga alt-texter som standard.** `wp auto-alt-text generate` fyller bara i saknade alt-texter, om du inte väljer något annat med `--overwrite=ai`, `--overwrite=all` eller inställningen. `--skip-existing` fungerar som förut.
- **Namn och modell per körning.** `--naming` och `--model` gäller en körning. `--brand-tonality` fungerar som förut. En körning ändrar aldrig pluginets inställningar.

#### Prestanda och loggning

- **Mindre databasarbete vid varje sidvisning.** Pluginet kontrollerar sina databastabeller bara efter en uppdatering, inte vid varje anrop.
- **Loggning.** Felsökningsmeddelanden sparas bara när felsökningsläget är påslaget. Nätverksfel loggas nu med sin verkliga orsak.

### Åtgärdat

#### Alt-texterna

- Tomma, avbrutna eller nekade svar från OpenAI sparas aldrig som alt-text. De ger i stället ett tydligt felmeddelande.
- Genomskinliga bilder skickas inte längre som JPEG med svart bakgrund.
- Filnamn som är hashar eller kameranamn skickas inte längre till AI:n som ledtråd.

#### Bakgrund och kö

- En saknad eller avvisad API-nyckel pausar nu kön i bakgrunden i stället för att markera alla köade bilder som misslyckade.
- En bild som kraschar under bearbetningen blockerar inte längre kön för alltid.

#### WooCommerce

- Kassan i WooCommerce blir inte längre långsammare av pluginet. Lagerändringar vid köp startade tidigare arbete med alt-texter, och i äldre versioner även anrop till OpenAI.
- Alt-texter som teman och block sätter med flit (även tomma alt-texter för dekorativa bilder) skrivs inte längre över på hela webbplatsen när WooCommerce är aktivt.

#### Mediebiblioteket och redigeraren

- Alt-text som du tillämpar i mediefönstret ligger nu kvar. Tidigare hoppade den tillbaka till den gamla texten när du klickade på Uppdatera eller Infoga.
- Att tillämpa alt-text med citattecken eller apostrofer, eller att tillämpa samma text två gånger, ger inte längre något fel.
- Fel vid generering visas nu på skärmen i stället för att misslyckas tyst.
- Generera-knappen fungerar i alla mediaväljare i adminpanelen, till exempel i webbplatsredigeraren, i anpassaren och för bilder på produktkategorier.
- Att stänga av automatisk generering slår inte längre ut Generera-knappen och massåtgärderna. Tidigare misslyckades de med det missvisande felet "empty response".
- När du markerar flera bilder i blockredigeraren eller i WooCommerces produktgalleri visas inte längre en massknapp som laddade om sidan och kastade osparade ändringar.
- Massåtgärden i mediebiblioteket startar inte längre om sig själv i en oändlig loop efter att den är klar.
- Sidnumreringen i listor (till exempel "0 objekt") visas rätt igen i listorna för inlägg, media och WooCommerce.

#### Inställningar och adminpanelen

- Att spara API-nyckeln för första gången misslyckas inte längre med "Invalid OpenAI API key format".
- Inställningssidans stilmall laddas nu, och länken "Configure now" i mediefönstret leder till rätt sida.
- Inställningen "Aktivera feedbacksystem" sparas nu.
- Rutan "Custom Template" på inställningssidan sparades aldrig och är borttagen.
- Pluginet ändrar inte längre andra pluginers inställningar som använder det allmänna inställningsnamnet "language".
- Statistiksidan visar inte längre PHP-varningar för bilder som bearbetats i bakgrunden. Den visar nu "System (queue)" som användare.
- Meddelandet om att översättningar laddades för tidigt ("translation loading was triggered too early") i WordPress 6.7 och senare är borta.

#### Flerspråkiga webbplatser

- Polylang: uppladdade bilder behåller det språk Polylang ger dem, och exporten av personuppgifter omfattar alla språk.
- WPML: ingen krasch längre när en köad bild har raderats.

#### Personuppgifter

- Verktyg → Exportera personuppgifter fungerar igen och exporterar bara den begärande användarens egna bilder.

### Säkerhet

- Åtgärdat en möjlig skriptinjektion i förhandsvisningen av alt-text. AI-genererad text infogades som HTML men behandlas nu alltid som ren text.
- Användare kan bara generera eller skriva över alt-text för bilder de har behörighet att redigera. Tidigare kunde alla som fick ladda upp filer (till exempel författare) skriva över alt-text på andras bilder.
- Tagit bort en oskyddad adminadress som kunde starta bearbetning av alla produkter utan säkerhetskontroll.
- API-nyckeln skrivs inte längre ut i inställningssidans källkod.
- Exporten av personuppgifter kan inte längre innehålla andra användares bilder.

### Borttaget

- **Integrationerna för sidbyggare** (Divi, Beaver Builder, WPBakery, Elementor). De anropade OpenAI medan besökare tittade på sidor, vilket gjorde sidorna långsamma och kostade pengar. Divis galleri-integration kunde dessutom krascha. Bilderna tas om hand när de laddas upp, och sidbyggarna visar bildens vanliga alt-text.
- **Visningsfiltren för WooCommerce** som skrev om bilders alt-text i butiken. WooCommerce använder redan varje bilds alt-text.
- Oanvänd, dubblerad kod för massbearbetning och cachning.

### För utvecklare

#### Klasser och funktioner

- Nya klasser: `Auto_Alt_Text_Models`, `Auto_Alt_Text_Prompt` och `Auto_Alt_Text_Image_Source`. `Auto_Alt_Text_OpenAI::run_generation()` genererar utan att spara något.
- Pluginet startar nu på `plugins_loaded`, efter att alla andra pluginer har laddats.
- Bytt namn på globala hjälpfunktioner (uppdatera egen kod som anropar dem):
  - `get_supported_languages()` → `auto_alt_text_get_supported_languages()`
  - `add_format_compatibility_section()` → `auto_alt_text_render_status_page()`
  - konstanten `SETTINGS_GROUP` → `AUTO_ALT_TEXT_SETTINGS_GROUP`

#### Filter

- `aat_queue_batch_size`: antal jobb per körning av kön, standard 5.
- `auto_alt_text_log_debug`: spara felsökningsposter i loggen.
- `auto_alt_text_connector_approval_url`: länk till godkännandesidan.
- `auto_alt_text_models`, `auto_alt_text_model`, `auto_alt_text_max_length`, `auto_alt_text_image_detail` och `auto_alt_text_image_max_pixels`: modellregistret, modellval, längd och bildhantering.

#### Inställningar och databas

- Nya inställningar: `auto_alt_text_overwrite_policy` (`missing` | `ai` | `all`), `auto_alt_text_naming`, `auto_alt_text_extra_instructions` och `auto_alt_text_model`. `wp_auto_alt_text_enable_brand_tonality` tas bort vid uppgraderingen.
- Dold metadata `_aat_alt_provenance` på bilder.
- Databasschema 1.3: statistiktabellen får kolumnerna `model`, `input_tokens` och `output_tokens`.
- Kön har en ny kolumn, `context_post_id`, och processorer får produkten eller inlägget som tredje argument. `Auto_Alt_Text_Queue::enqueue()` och `promote_pending()` tar emot det som ett valfritt tredje argument.

#### Cron, AJAX och REST

- Nya WP-Cron-händelser: `aat_queue_tick` (varje minut), `aat_daily_maintenance` (dagligen) och `aat_provenance_backfill` (körs i omgångar efter uppdateringen). Alla tas bort när pluginet inaktiveras.
- Ny AJAX-åtgärd: `aat_get_context`.
- REST: `POST /wp-auto-alt-text/v1/generate` svarar `403` för bilder som användaren inte får redigera, och `/batch` rapporterar "Permission denied" för de bilderna.

#### Tester och utvärdering

- Testsviten körs på WordPress 7.1.1 med WooCommerce 11.2.1. Se "Running tests" i README (`composer test`).
- `eval/` innehåller utvärderingskittet. Det ingår inte i releasen.
