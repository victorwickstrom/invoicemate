<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

return function (App $app) {
    $container = $app->getContainer();

    // Hent alle konti for en organisation
    $app->get('/{organizationId}/accounts', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->has('db')) {
            throw new \RuntimeException("Database connection not found");
        }

        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];

        // Tjek om der findes konti
        $stmt = $pdo->prepare("SELECT * FROM account WHERE organization_id = :organizationId ORDER BY accountNumber ASC");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hvis ingen konti findes, indsæt danske standardkonti
        if (empty($accounts)) {
            insertStandardAccounts($pdo, $organizationId);
            $stmt->execute(); // Hent de nyoprettede konti
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $response->getBody()->write(json_encode($accounts));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Funktion til at indsætte danske standardkonti
    function insertStandardAccounts(PDO $pdo, string $organizationId) {
        $standardAccounts = [
            // 📌 1000 - 1999 | Omsætningstillgångar
            // Likvide beholdninger
            ["accountNumber" => 1000, "name" => "Kasse", "vatCode" => "I25"],
            ["accountNumber" => 1010, "name" => "Bank", "vatCode" => "I25"],
            ["accountNumber" => 1020, "name" => "Checkkonto", "vatCode" => "I25"],
            ["accountNumber" => 1030, "name" => "Kreditorkonto", "vatCode" => "I25"],
            
            // Tilgodehavender
            ["accountNumber" => 1100, "name" => "Debitorer", "vatCode" => "I25"],
            ["accountNumber" => 1110, "name" => "Tvivlsomme debitorer", "vatCode" => "I25"],
            ["accountNumber" => 1120, "name" => "Andre tilgodehavender", "vatCode" => "I25"],
            ["accountNumber" => 1130, "name" => "Tilgodehavender hos tilknyttede virksomheder", "vatCode" => "I25"],
            ["accountNumber" => 1140, "name" => "Tilgodehavender hos associerede virksomheder", "vatCode" => "I25"],
            ["accountNumber" => 1150, "name" => "Periodeafgrænsningsposter", "vatCode" => "I25"],
            
            // Varebeholdninger
            ["accountNumber" => 1200, "name" => "Varelager", "vatCode" => "I25"],
            ["accountNumber" => 1210, "name" => "Råvarer og hjælpematerialer", "vatCode" => "I25"],
            ["accountNumber" => 1220, "name" => "Varer under fremstilling", "vatCode" => "I25"],
            ["accountNumber" => 1230, "name" => "Fremstillede varer og handelsvarer", "vatCode" => "I25"],
            ["accountNumber" => 1240, "name" => "Forudbetalinger for varer", "vatCode" => "I25"],
            
            // Periodiseringer og forudbetalinger
            ["accountNumber" => 1300, "name" => "Forudbetalinger", "vatCode" => "I25"],
            ["accountNumber" => 1310, "name" => "Forudbetalte omkostninger", "vatCode" => "I25"],
            ["accountNumber" => 1320, "name" => "Deposita", "vatCode" => "I25"],
            
            // Moms og afgifter
            ["accountNumber" => 1400, "name" => "Moms af køb", "vatCode" => "I25"],
            ["accountNumber" => 1410, "name" => "Moms af salg", "vatCode" => "U25"],
            ["accountNumber" => 1420, "name" => "Momsafregningskonto", "vatCode" => "I25"],
            ["accountNumber" => 1430, "name" => "A-skat", "vatCode" => "I25"],
            ["accountNumber" => 1440, "name" => "AM-bidrag", "vatCode" => "I25"],
            ["accountNumber" => 1450, "name" => "ATP", "vatCode" => "I25"],
            ["accountNumber" => 1460, "name" => "Feriepengeforpligtelse", "vatCode" => "I25"],
            
            // Værdipapirer
            ["accountNumber" => 1500, "name" => "Værdipapirer", "vatCode" => "I25"],
            ["accountNumber" => 1510, "name" => "Aktier", "vatCode" => "I25"],
            ["accountNumber" => 1520, "name" => "Obligationer", "vatCode" => "I25"],
            ["accountNumber" => 1530, "name" => "Andre værdipapirer", "vatCode" => "I25"],
            
            // Andre omsætningsaktiver
            ["accountNumber" => 1600, "name" => "Andre omsætningsaktiver", "vatCode" => "I25"],
            ["accountNumber" => 1610, "name" => "Igangværende arbejder", "vatCode" => "I25"],
            ["accountNumber" => 1620, "name" => "Udlæg", "vatCode" => "I25"],
            
            // 📌 2000 - 2999 | Anlægsningstillgångar
            // Immaterielle anlægsaktiver
            ["accountNumber" => 2000, "name" => "Immaterielle aktiver", "vatCode" => "U25"],
            ["accountNumber" => 2010, "name" => "Goodwill", "vatCode" => "U25"],
            ["accountNumber" => 2020, "name" => "Patenter", "vatCode" => "U25"],
            ["accountNumber" => 2030, "name" => "Udviklingsomkostninger", "vatCode" => "U25"],
            ["accountNumber" => 2040, "name" => "Software", "vatCode" => "U25"],
            ["accountNumber" => 2050, "name" => "Varemærker", "vatCode" => "U25"],
            
            // Materielle anlægsaktiver
            ["accountNumber" => 2100, "name" => "Grunde og bygninger", "vatCode" => "U25"],
            ["accountNumber" => 2110, "name" => "Produktionsanlæg og maskiner", "vatCode" => "U25"],
            ["accountNumber" => 2120, "name" => "Andre anlæg, driftsmateriel og inventar", "vatCode" => "U25"],
            ["accountNumber" => 2130, "name" => "Indretning af lejede lokaler", "vatCode" => "U25"],
            ["accountNumber" => 2140, "name" => "Materielle anlægsaktiver under udførelse", "vatCode" => "U25"],
            
            // Køretøjer
            ["accountNumber" => 2300, "name" => "Biler", "vatCode" => "U25"],
            ["accountNumber" => 2310, "name" => "Varebiler", "vatCode" => "U25"],
            ["accountNumber" => 2320, "name" => "Lastbiler", "vatCode" => "U25"],
            
            // IT og udstyr
            ["accountNumber" => 2400, "name" => "IT-udstyr", "vatCode" => "U25"],
            ["accountNumber" => 2410, "name" => "Hardware", "vatCode" => "U25"],
            ["accountNumber" => 2420, "name" => "Software", "vatCode" => "U25"],
            
            // Finansielle anlægsaktiver
            ["accountNumber" => 2500, "name" => "Kapitalandele i tilknyttede virksomheder", "vatCode" => "U25"],
            ["accountNumber" => 2510, "name" => "Kapitalandele i associerede virksomheder", "vatCode" => "U25"],
            ["accountNumber" => 2520, "name" => "Andre værdipapirer og kapitalandele", "vatCode" => "U25"],
            ["accountNumber" => 2530, "name" => "Tilgodehavender hos tilknyttede virksomheder", "vatCode" => "U25"],
            ["accountNumber" => 2540, "name" => "Tilgodehavender hos associerede virksomheder", "vatCode" => "U25"],
            ["accountNumber" => 2550, "name" => "Andre tilgodehavender", "vatCode" => "U25"],
            
            // Afskrivninger
            ["accountNumber" => 2600, "name" => "Akkumulerede afskrivninger, immaterielle aktiver", "vatCode" => "U25"],
            ["accountNumber" => 2610, "name" => "Akkumulerede afskrivninger, bygninger", "vatCode" => "U25"],
            ["accountNumber" => 2620, "name" => "Akkumulerede afskrivninger, produktionsanlæg og maskiner", "vatCode" => "U25"],
            ["accountNumber" => 2630, "name" => "Akkumulerede afskrivninger, andre anlæg, driftsmateriel og inventar", "vatCode" => "U25"],
            ["accountNumber" => 2640, "name" => "Akkumulerede afskrivninger, biler", "vatCode" => "U25"],
            ["accountNumber" => 2650, "name" => "Akkumulerede afskrivninger, IT-udstyr", "vatCode" => "U25"],
            
            // Forbedringer
            ["accountNumber" => 2700, "name" => "Bygningsforbedringer", "vatCode" => "U25"],
            ["accountNumber" => 2710, "name" => "Maskinforbedringer", "vatCode" => "U25"],
            ["accountNumber" => 2720, "name" => "Andre forbedringer", "vatCode" => "U25"],
            
            // 📌 3000 - 3999 | Omsætning og indtægter
            // Varesalg
            ["accountNumber" => 3000, "name" => "Varesalg", "vatCode" => "U25"],
            ["accountNumber" => 3010, "name" => "Varesalg, høj sats", "vatCode" => "U25"],
            ["accountNumber" => 3020, "name" => "Varesalg, lav sats", "vatCode" => "U25"],
            ["accountNumber" => 3030, "name" => "Varesalg, momsfrit", "vatCode" => "U0"],
            
            // Ydelsessalg
            ["accountNumber" => 3100, "name" => "Ydelsessalg", "vatCode" => "U25"],
            ["accountNumber" => 3110, "name" => "Konsulentydelser", "vatCode" => "U25"],
            ["accountNumber" => 3120, "name" => "Abonnementer", "vatCode" => "U25"],
            ["accountNumber" => 3130, "name" => "Undervisning", "vatCode" => "U25"],
            
            // Eksportsalg
            ["accountNumber" => 3200, "name" => "Salg til EU", "vatCode" => "UEUV"],
            ["accountNumber" => 3210, "name" => "EU-salg, varer", "vatCode" => "UEUV"],
            ["accountNumber" => 3220, "name" => "EU-salg, ydelser", "vatCode" => "UEUY"],
            
            ["accountNumber" => 3300, "name" => "Salg til verden", "vatCode" => "UVC"],
            ["accountNumber" => 3310, "name" => "Eksport, varer", "vatCode" => "UVC"],
            ["accountNumber" => 3320, "name" => "Eksport, ydelser", "vatCode" => "UVC"],
            
            // Finansielle indtægter
            ["accountNumber" => 3400, "name" => "Renteindtægter", "vatCode" => "U0"],
            ["accountNumber" => 3410, "name" => "Udbytter", "vatCode" => "U0"],
            ["accountNumber" => 3420, "name" => "Valutakursgevinster", "vatCode" => "U0"],
            
            // Andre indtægter
            ["accountNumber" => 3500, "name" => "Andre driftsindtægter", "vatCode" => "U25"],
            ["accountNumber" => 3510, "name" => "Lejeindtægter", "vatCode" => "U25"],
            ["accountNumber" => 3520, "name" => "Provisionsindtægter", "vatCode" => "U25"],
            ["accountNumber" => 3530, "name" => "Royalties", "vatCode" => "U25"],
            
            // Tilskud
            ["accountNumber" => 3600, "name" => "Tilskud og bidrag", "vatCode" => "U0"],
            ["accountNumber" => 3610, "name" => "Offentlige tilskud", "vatCode" => "U0"],
            ["accountNumber" => 3620, "name" => "Private tilskud", "vatCode" => "U0"],
            ["accountNumber" => 3630, "name" => "EU-tilskud", "vatCode" => "U0"],
            
            // 📌 4000 - 4999 | Indkøb og vareomkostninger
            // Varekøb
            ["accountNumber" => 4000, "name" => "Køb af varer", "vatCode" => "I25"],
            ["accountNumber" => 4010, "name" => "Køb af råvarer", "vatCode" => "I25"],
            ["accountNumber" => 4020, "name" => "Køb af hjælpematerialer", "vatCode" => "I25"],
            ["accountNumber" => 4030, "name" => "Lagerregulering", "vatCode" => "I25"],
            
            // Ydelseskøb
            ["accountNumber" => 4100, "name" => "Køb af ydelser", "vatCode" => "I25"],
            ["accountNumber" => 4110, "name" => "Underleverandører", "vatCode" => "I25"],
            ["accountNumber" => 4120, "name" => "Fremmede tjenesteydelser", "vatCode" => "I25"],
            
            // Importkøb
            ["accountNumber" => 4200, "name" => "Køb fra EU", "vatCode" => "IEUV"],
            ["accountNumber" => 4210, "name" => "EU-køb, varer", "vatCode" => "IEUV"],
            ["accountNumber" => 4220, "name" => "EU-køb, ydelser", "vatCode" => "IEUY"],
            
            ["accountNumber" => 4300, "name" => "Køb fra verden", "vatCode" => "IVV"],
            ["accountNumber" => 4310, "name" => "Import, varer", "vatCode" => "IVV"],
            ["accountNumber" => 4320, "name" => "Import, ydelser", "vatCode" => "IVY"],
            
            // Personaleomkostninger
            ["accountNumber" => 4400, "name" => "Lønninger", "vatCode" => "I0"],
            ["accountNumber" => 4410, "name" => "ATP", "vatCode" => "I0"],
            ["accountNumber" => 4420, "name" => "Pension", "vatCode" => "I0"],
            ["accountNumber" => 4430, "name" => "AER (Arbejdsgivernes Elevrefusion)", "vatCode" => "I0"],
            ["accountNumber" => 4440, "name" => "AES (Arbejdsmarkedets Erhvervssikring)", "vatCode" => "I0"],
            ["accountNumber" => 4450, "name" => "DA-barsel", "vatCode" => "I0"],
            ["accountNumber" => 4460, "name" => "Lønsumsafgift", "vatCode" => "I0"],
            ["accountNumber" => 4470, "name" => "Personalegoder", "vatCode" => "I25"],
            ["accountNumber" => 4480, "name" => "Personaleomkostninger, skattefrit", "vatCode" => "I0"],
            
            // Materialer og værktøj
            ["accountNumber" => 4500, "name" => "Materialer", "vatCode" => "I25"],
            ["accountNumber" => 4510, "name" => "Småanskaffelser", "vatCode" => "I25"],
            ["accountNumber" => 4520, "name" => "Arbejdstøj", "vatCode" => "I25"],
            
            ["accountNumber" => 4600, "name" => "Værktøj", "vatCode" => "I25"],
            ["accountNumber" => 4610, "name" => "Håndværktøj", "vatCode" => "I25"],
            ["accountNumber" => 4620, "name" => "Maskinværktøj", "vatCode" => "I25"],
            
            // Transport
            ["accountNumber" => 4700, "name" => "Transportomkostninger", "vatCode" => "I25"],
            ["accountNumber" => 4710, "name" => "Fragt", "vatCode" => "I25"],
            ["accountNumber" => 4720, "name" => "Told", "vatCode" => "I0"],
            ["accountNumber" => 4730, "name" => "Emballage", "vatCode" => "I25"],
            
            // 📌 5000 - 5999 | Driftsomkostninger
            // Lokaleomkostninger
            ["accountNumber" => 5000, "name" => "Husleje", "vatCode" => "I25"],
            ["accountNumber" => 5010, "name" => "El", "vatCode" => "I25"],
            ["accountNumber" => 5020, "name" => "Vand", "vatCode" => "I25"],
            ["accountNumber" => 5030, "name" => "Varme", "vatCode" => "I25"],
            ["accountNumber" => 5040, "name" => "Ejendomsskatter", "vatCode" => "I0"],
            ["accountNumber" => 5050, "name" => "Rengøring", "vatCode" => "I25"],
            ["accountNumber" => 5060, "name" => "Reparation og vedligeholdelse, lokaler", "vatCode" => "I25"],
            
            // Markedsføring
            ["accountNumber" => 5100, "name" => "Reklame og markedsføring", "vatCode" => "I25"],
            ["accountNumber" => 5110, "name" => "Annoncer", "vatCode" => "I25"],
            ["accountNumber" => 5120, "name" => "Tryksager", "vatCode" => "I25"],
            ["accountNumber" => 5130, "name" => "Udstillinger og messer", "vatCode" => "I25"],
            ["accountNumber" => 5140, "name" => "Gaver og blomster", "vatCode" => "I25"],
            ["accountNumber" => 5150, "name" => "Sponsorater", "vatCode" => "I25"],
            
            // Repræsentation
            ["accountNumber" => 5200, "name" => "Repræsentation", "vatCode" => "REP"],
            ["accountNumber" => 5210, "name" => "Restaurationsbesøg", "vatCode" => "REP"],
            ["accountNumber" => 5220, "name" => "Repræsentationsartikler", "vatCode" => "REP"],
            
            // Forsikringer
            ["accountNumber" => 5300, "name" => "Forsikringer", "vatCode" => "I0"],
            ["accountNumber" => 5310, "name" => "Erhvervsforsikring", "vatCode" => "I0"],
            ["accountNumber" => 5320, "name" => "Produktansvarsforsikring", "vatCode" => "I0"],
            ["accountNumber" => 5330, "name" => "Bil- og transportforsikring", "vatCode" => "I0"],
            ["accountNumber" => 5340, "name" => "Sundhedsforsikring", "vatCode" => "I0"],
            
            // Rejser og møder
            ["accountNumber" => 5400, "name" => "Rejseomkostninger", "vatCode" => "I25"],
            ["accountNumber" => 5410, "name" => "Hotelomkostninger", "vatCode" => "I25"],
            ["accountNumber" => 5420, "name" => "Fortæring på rejser", "vatCode" => "I25"],
            ["accountNumber" => 5430, "name" => "Taxa", "vatCode" => "I25"],
            ["accountNumber" => 5440, "name" => "Parkering", "vatCode" => "I25"],
            ["accountNumber" => 5450, "name" => "Kørselsgodtgørelse", "vatCode" => "I0"],
            ["accountNumber" => 5460, "name" => "Mødeomkostninger", "vatCode" => "I25"],
            
            // Kontorartikler og IT
            ["accountNumber" => 5500, "name" => "Kontorartikler", "vatCode" => "I25"],
            ["accountNumber" => 5510, "name" => "Papir og tryksager", "vatCode" => "I25"],
            ["accountNumber" => 5520, "name" => "Faglitteratur", "vatCode" => "I25"],
            ["accountNumber" => 5530, "name" => "Tidsskrifter og aviser", "vatCode" => "I25"],
            
            // Forbrugsafgifter
            ["accountNumber" => 5600, "name" => "El, vand og varme (produktion)", "vatCode" => "I25"],
            ["accountNumber" => 5610, "name" => "Brændstof", "vatCode" => "I25"],
            
            // Vedligeholdelse
            ["accountNumber" => 5700, "name" => "Vedligeholdelse", "vatCode" => "I25"],
            ["accountNumber" => 5710, "name" => "Reparation, maskiner", "vatCode" => "I25"],
            ["accountNumber" => 5720, "name" => "Reparation, inventar", "vatCode" => "I25"],
            ["accountNumber" => 5730, "name" => "Reparation, biler", "vatCode" => "I25"],
            
            // Kommunikation og IT
            ["accountNumber" => 5800, "name" => "Telefon og internet", "vatCode" => "I25"],
            ["accountNumber" => 5810, "name" => "IT-omkostninger", "vatCode" => "I25"],
            ["accountNumber" => 5820, "name" => "Web-hotel og domæner", "vatCode" => "I25"],
            ["accountNumber" => 5830, "name" => "Software-licenser", "vatCode" => "I25"],
            
            // Kontorudstyr og inventar
            ["accountNumber" => 5900, "name" => "Kontorudstyr", "vatCode" => "I25"],
            ["accountNumber" => 5910, "name" => "IT-udstyr, småanskaffelser", "vatCode" => "I25"],
            ["accountNumber" => 5920, "name" => "Inventar, småanskaffelser", "vatCode" => "I25"],
            
            // 📌 6000 - 6999 | Finansielle omkostninger
            // Renteudgifter
            ["accountNumber" => 6000, "name" => "Renteudgifter", "vatCode" => "U0"],
            ["accountNumber" => 6010, "name" => "Renteudgifter, bank", "vatCode" => "U0"],
            ["accountNumber" => 6020, "name" => "Renteudgifter, realkreditlån", "vatCode" => "U0"],
            ["accountNumber" => 6030, "name" => "Renteudgifter, leverandører", "vatCode" => "U0"],
            
            // Gebyrer
            ["accountNumber" => 6100, "name" => "Bankgebyrer", "vatCode" => "U0"],
            ["accountNumber" => 6110, "name" => "Kortgebyrer", "vatCode" => "U0"],
            ["accountNumber" => 6120, "name" => "PBS-gebyrer", "vatCode" => "U0"],
            
            // Valutaomkostninger
            ["accountNumber" => 6200, "name" => "Kursdifferencer", "vatCode" => "U0"],
            ["accountNumber" => 6210, "name" => "Valutakurstab", "vatCode" => "U0"],
            
            // Andre finansielle omkostninger
            ["accountNumber" => 6300, "name" => "Andre finansielle omkostninger", "vatCode" => "U0"],
            ["accountNumber" => 6310, "name" => "Garantiprovisioner", "vatCode" => "U0"],
            ["accountNumber" => 6320, "name" => "Låneomkostninger", "vatCode" => "U0"],
            ["accountNumber" => 6330, "name" => "Finansielle gebyrer", "vatCode" => "U0"],
            
            // 📌 7000 - 7999 | Ekstraordinære poster
            // Ekstraordinære indtægter
            ["accountNumber" => 7000, "name" => "Ekstraordinære indtægter", "vatCode" => "U25"],
            ["accountNumber" => 7010, "name" => "Gevinst ved salg af anlægsaktiver", "vatCode" => "U25"],
            ["accountNumber" => 7020, "name" => "Forsikringserstatninger", "vatCode" => "U0"],
            
            // Ekstraordinære udgifter
            ["accountNumber" => 7100, "name" => "Ekstraordinære udgifter", "vatCode" => "I25"],
            ["accountNumber" => 7110, "name" => "Tab ved salg af anlægsaktiver", "vatCode" => "I0"],
            ["accountNumber" => 7120, "name" => "Bøder og mangelbetaling", "vatCode" => "I0"],
            
            // Afskrivninger
            ["accountNumber" => 7200, "name" => "Afskrivninger, immaterielle aktiver", "vatCode" => "I0"],
            ["accountNumber" => 7210, "name" => "Afskrivninger, bygninger", "vatCode" => "I0"],
            ["accountNumber" => 7220, "name" => "Afskrivninger, produktionsanlæg og maskiner", "vatCode" => "I0"],
            ["accountNumber" => 7230, "name" => "Afskrivninger, andre anlæg, driftsmateriel og inventar", "vatCode" => "I0"],
            ["accountNumber" => 7240, "name" => "Afskrivninger, biler", "vatCode" => "I0"],
            ["accountNumber" => 7250, "name" => "Afskrivninger, IT-udstyr", "vatCode" => "I0"],
            
            // 📌 8000 - 8999 | Skatter og afgifter
            // Selskabsskat
            ["accountNumber" => 8000, "name" => "Selskabsskat", "vatCode" => "U0"],
            ["accountNumber" => 8010, "name" => "Selskabsskat, årets", "vatCode" => "U0"],
            ["accountNumber" => 8020, "name" => "Selskabsskat, regulering tidligere år", "vatCode" => "U0"],
            ["accountNumber" => 8030, "name" => "Ændring i udskudt skat", "vatCode" => "U0"],
            
            // Momsafregning
            ["accountNumber" => 8100, "name" => "Momsbetaling", "vatCode" => "U0"],
            ["accountNumber" => 8110, "name" => "Momsafregning", "vatCode" => "U0"],
            ["accountNumber" => 8120, "name" => "Moms, regulering tidligere år", "vatCode" => "U0"],
            
            // Andre skatter
            ["accountNumber" => 8200, "name" => "Andre skatter", "vatCode" => "U0"],
            ["accountNumber" => 8210, "name" => "Ejendomsskat", "vatCode" => "U0"],
            ["accountNumber" => 8220, "name" => "Energiafgifter", "vatCode" => "U0"],
            ["accountNumber" => 8230, "name" => "Miljøafgifter", "vatCode" => "U0"],
            
            // 📌 9000 - 9999 | Kapital og gæld
            // Egenkapital
            ["accountNumber" => 9000, "name" => "Aktiekapital/Anpartskapital", "vatCode" => "U0"],
            ["accountNumber" => 9010, "name" => "Overkurs ved emission", "vatCode" => "U0"],
            ["accountNumber" => 9020, "name" => "Reserve for opskrivninger", "vatCode" => "U0"],
            ["accountNumber" => 9030, "name" => "Overført resultat", "vatCode" => "U0"],
            ["accountNumber" => 9040, "name" => "Foreslået udbytte", "vatCode" => "U0"],
            ["accountNumber" => 9050, "name" => "Kapitalkonto (personlig virksomhed)", "vatCode" => "U0"],
            
            // Lån
            ["accountNumber" => 9100, "name" => "Virksomhedslån", "vatCode" => "U0"],
            ["accountNumber" => 9110, "name" => "Realkreditlån", "vatCode" => "U0"],
            ["accountNumber" => 9120, "name" => "Banklån", "vatCode" => "U0"],
            ["accountNumber" => 9130, "name" => "Leasingforpligtelser", "vatCode" => "U0"],
            
            // Investeringer
            ["accountNumber" => 9200, "name" => "Aktieinvesteringer", "vatCode" => "U0"],
            ["accountNumber" => 9210, "name" => "Obligationsinvesteringer", "vatCode" => "U0"],
            
            // Hensættelser
            ["accountNumber" => 9300, "name" => "Pensionsafsætninger", "vatCode" => "U0"],
            ["accountNumber" => 9310, "name" => "Udskudt skat", "vatCode" => "U0"],
            ["accountNumber" => 9320, "name" => "Andre hensættelser", "vatCode" => "U0"],
            
            // Kortfristet gæld
            ["accountNumber" => 9400, "name" => "Leverandører af varer og tjenesteydelser", "vatCode" => "U0"],
            ["accountNumber" => 9410, "name" => "Skyldig moms", "vatCode" => "U0"],
            ["accountNumber" => 9420, "name" => "Skyldig A-skat", "vatCode" => "U0"],
            ["accountNumber" => 9430, "name" => "Skyldigt AM-bidrag", "vatCode" => "U0"],
      ];

        $stmt = $pdo->prepare("
            INSERT INTO account (organization_id, accountNumber, name, vatCode, isActive, createdAt, updatedAt)
            VALUES (:organizationId, :accountNumber, :name, :vatCode, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($standardAccounts as $account) {
            $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
            $stmt->bindParam(':accountNumber', $account['accountNumber'], PDO::PARAM_INT);
            $stmt->bindParam(':name', $account['name'], PDO::PARAM_STR);
            $stmt->bindParam(':vatCode', $account['vatCode'], PDO::PARAM_STR);
            $stmt->execute();
        }
    }


    // Hent et specifikt konto
    $app->get('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $accountNumber = $args['accountNumber'];

        $stmt = $pdo->prepare("SELECT * FROM account WHERE organization_id = :organizationId AND accountNumber = :accountNumber");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':accountNumber', $accountNumber, PDO::PARAM_INT);
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            return $response->withStatus(404)->withJson(["error" => "Account not found"]);
        }

        $response->getBody()->write(json_encode($account));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Opret et nyt konto
    $app->post('/{organizationId}/accounts', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $data = $request->getParsedBody();

        if (!isset($data['accountNumber'], $data['name'], $data['vatCode'])) {
            return $response->withStatus(400)->withJson(["error" => "Missing required fields"]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO account (organization_id, accountNumber, name, vatCode, isActive, createdAt, updatedAt)
            VALUES (:organizationId, :accountNumber, :name, :vatCode, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':accountNumber', $data['accountNumber'], PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':vatCode', $data['vatCode'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(201)->withJson(["message" => "Account created successfully"]);
    });

    // Opdater et konto
    $app->put('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $accountNumber = $args['accountNumber'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("
            UPDATE account 
            SET name = :name, vatCode = :vatCode, updatedAt = CURRENT_TIMESTAMP
            WHERE organization_id = :organizationId AND accountNumber = :accountNumber
        ");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':accountNumber', $accountNumber, PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':vatCode', $data['vatCode'], PDO::PARAM_STR);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Account updated successfully"]);
    });

    // Slet et konto
    $app->delete('/{organizationId}/accounts/{accountNumber}', function (Request $request, Response $response, array $args) use ($container) {
        $pdo = $container->get('db');
        $organizationId = $args['organizationId'];
        $accountNumber = $args['accountNumber'];

        $stmt = $pdo->prepare("DELETE FROM account WHERE organization_id = :organizationId AND accountNumber = :accountNumber");
        $stmt->bindParam(':organizationId', $organizationId, PDO::PARAM_STR);
        $stmt->bindParam(':accountNumber', $accountNumber, PDO::PARAM_INT);
        $stmt->execute();

        return $response->withStatus(200)->withJson(["message" => "Account deleted successfully"]);
    });
};
