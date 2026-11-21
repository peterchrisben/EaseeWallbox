# Changelog

## Version 1.0

### Build 18 (20.11.2026)

#### Neu
- **Messwerte für einen Energiemanager** (Bereich „Energiemanager“, Standard aus): Die Instanz bietet ihre Messwerte nach der Schnittstelle „charger“ 1.13 des NRG-Stack an (`EASEE_GetFunctions`). Ein Energiemanager wie das NRG-Stack EMS findet die Wallbox damit selbst und liest Ladeleistung (W), Zählerstand, Status, „Fahrzeug verbunden“, aktive Phasen und den letzten Kontakt mit. Gesteuert wird darüber nichts.
- Variable „Ladeleistung (W)“ – nur bei gesetztem Häkchen und Einheit kW.
- Bei gesetztem Häkchen fragt das Modul die Cloud spätestens alle 5 Minuten ab, damit die Werte nie älter als 10 Minuten sind.

#### Dokumentation
- README: Abschnitt „Energiemanager (nur Messen)“, `EASEE_GetFunctions` bei den öffentlichen Funktionen.

### Build 17 (16.11.2026)

#### Behoben
- **Symcon 9.1:** Die Statustexte (Status, Strombegrenzung, Phasenmodus Wallbox) werden als Wertanzeige mit Intervallen dargestellt. Symcon 9.1 prüft die Parameter von Darstellungen streng und bricht „Übernehmen“ bei unbekannten Unterparametern ab – dann würden die Variablen nicht angelegt. Die Intervalle enthalten jetzt nur noch die nötigen Angaben (derselbe Aufbau läuft bei einem anderen Modul unter 9.1 bestätigt). Unter Symcon 9.0 ändert sich nichts.

#### Dokumentation
- README: Installation über den Module Store (Beta).

### Build 16 (13.11.2026)

#### Geändert
- **Keine eigene Pause mehr beim Phasenwechsel.** Praxistest 07.11.2026: Die Easee unterbricht die Ladung beim Wechsel in beide Richtungen selbst (Status „Warte auf Start“), schaltet um und lädt nach ca. 25–30 s weiter. Die Einstellungen „Beim Wechsel 1 → 3 Phasen während des Ladens kurz pausieren“ und „Dauer der Pause“ entfallen.

#### Neu
- Phasenwechsel werden gemeldet: „Letzter Befehl“ (z. B. „Phasenwechsel 1 → 3, 3-phasig“), Debug „Phasen“ und – abschaltbar mit **„Phasenwechsel im Symcon-Log melden“** (Bereich Phasenumschaltung, Standard an) – eine normale Meldung im Symcon-Log mit der Vorgabe.
- Datei `LICENSE` (CC BY-NC-SA 4.0).

#### Dokumentation
- README: Installation über die Modulverwaltung, Hinweis zur Cloud-Abhängigkeit, „Noch offen“ aktualisiert, Phasenumschaltung beschrieben, Markenhinweis.
- Autor „Peter Chrisben“.

### Build 15 (07.11.2026)

#### Neu
- Einstellung **„Zählerstand zwischen den Cloud-Updates fortschreiben“** (Bereich Abfrageintervalle, Standard an). Die Easee-Cloud meldet den Zählerstand (Mr) nur etwa stündlich, die Energie des Ladevorgangs aber alle paar Minuten. Der Zählerstand wird jetzt daraus fortgeschrieben: letzter Cloud-Wert + Zuwachs der Ladevorgangs-Energie. Ein neuer Ladevorgang (Energie springt auf 0) wird berücksichtigt, der Wert läuft nie rückwärts, der nächste Cloud-Wert setzt die Basis neu. Nachgelagerte Zähler und Wallbox-Bausteine bekommen damit aktuelle Werte statt bis zu einer Stunde alter.
- Debug „Zählerstand“ bei Cloud-Wert und neuem Ladevorgang

### Build 14 (07.11.2026)

#### Neu
- Schalter **„Modul aktiv“** in der Wallbox-Instanz (Standard an). Aus: keine Abfragen, keine Befehle, kein Wächter, keine Regelung; Status „Modul ausgeschaltet“. Werte kommen weiter per Push (kostet keine Abfragen). Vorgaben (Ziel-Ladeleistung, Laden erlaubt, Phasenwahl) werden gemerkt und beim Wiedereinschalten gesendet; Befehle wie Ein/Aus oder Kabelverriegelung werden mit Hinweis abgelehnt.
- Beim Ausschalten hebt das Modul seine letzte Ladestrom-Vorgabe auf (Höchststrom auf allen Phasen mit 1 min Ablaufzeit, danach gilt die normale Einstellung der Wallbox) – die Wallbox wird dann nur noch über die Easee-App gesteuert. Ein angestecktes Auto lädt so, wie es in der App eingestellt ist.

#### Geändert
- Status „Bitte Seriennummer eintragen“ hat jetzt Code 202 (104 = Modul ausgeschaltet).

### Build 13 (31.10.2026)

#### Neu
- Einstellung **„Darstellung für Loxone“** (Standard: an). An: Variablennamen wie die Ein-/Ausgänge des Loxone-Wallbox-Bausteins (Tp, Ca, Vc, Cac, Cp, Mr) und Bereich „Loxone“ mit Zuordnung, Variablen-ID, Typ und Richtung, Hinweis bei Einheit W. Aus: neutrale Namen („Ziel-Ladeleistung“, „Laden aktiv“ …), Bereich ausgeblendet.

#### Geändert
- Umbenannt werden nur Standardnamen (auch die früheren aus Build 1); eigene Namen bleiben. Idents und IDs unverändert.
- Regelmodus „Direkt“: Text neutral („übergeordnete Regelung, z. B. Loxone“).
- README: Abschnitt „Darstellung für Loxone“ statt Verknüpfungsabschnitt.

#### Hinweis zum Update
- Bestehende Instanzen haben die Einstellung nach dem Update **an** – an den Namen ändert sich nichts. Wer kein Loxone nutzt, nimmt das Häkchen heraus. Verknüpfungen über die Variablen-ID funktionieren in beiden Fällen weiter.

### Build 12 (25.10.2026)

#### Behoben
- „Spannung“ lag zu hoch (z. B. 244 V, ein anderes Messgerät zeigte 233 V): Das Modul nahm den **höchsten** Wert aller Klemmen-Spannungen unter 260 V. Bei 3 Phasen + N ist jetzt „Spannung“ der **Mittelwert** von N–L1/L2/L3 (Observation 194/195/196). Beim IT-Netz bleibt es wie bisher.

#### Neu
- Variablen „Spannung L1“, „Spannung L2“, „Spannung L3“ (nur bei 3 Phasen + N).

#### Hinweis
- Die Easee meldet ihre Spannungen nur gelegentlich (Zeitstempel in der Antwort). Für einen Vergleich mit anderen Messgeräten deshalb Werte zur selben Zeit nehmen; einige Volt Unterschied sind bei einer Wallbox normale Messtoleranz.

### Build 11 (11.10.2026)

#### Behoben
- Push blieb nach einem Verbindungsabbruch dauerhaft getrennt (beobachtet seit 09.10.2026, 19:40): Meldete der WebSocket Client einen Fehler, setzte das Modul nur „Push verbunden“ auf Aus, plante aber keinen neuen Versuch – und die Überwachung schaltete sich ab. Jetzt wird in diesem Fall automatisch neu verbunden.

#### Neu
- Fehlermeldung im Symcon-Log (rot) und in „Letzter Fehler“, wenn die Push-Verbindung 3 Versuche in Folge fehlschlägt oder 10 min getrennt ist – einmal pro Ausfall, mit Zeitpunkt und letztem Fehler. Ist sie wieder da, eine normale Meldung „Push-Verbindung … wieder hergestellt“.
- Alle 5 min Kontrolle: Soll Push laufen, ist aber weder verbunden noch ein Versuch geplant (oder hängt der Verbindungsaufbau), wird neu verbunden.
- Debug zeigt bei jedem Fehlversuch Wartezeit und Anzahl („Neuer Versuch in 60 s (Fehlversuch 3)“).

### Build 10 (05.10.2026)

#### Geändert
- „Status“, „Strombegrenzung (Easee)“ und „Phasenmodus Wallbox“ ohne Legacy-Variablenprofil: Darstellung „Wertanzeige“ mit **Intervallen** – je Wert ein Intervall (z. B. 4 bis 4) mit festem Text „Ladung beendet“. Die Profile aus Build 9 (EASEE.Status, EASEE.Limit, EASEE.PhaseMode) werden gelöscht, sobald keine Variable sie mehr nutzt.

### Build 9 (05.10.2026)

#### Behoben
- Build 8 zeigte bei „Status“, „Strombegrenzung (Easee)“ und „Phasenmodus Wallbox“ nur noch die Zahl (z. B. 4, 77, 2) – die „Wertanzeige“ setzt Zahlen nicht in Text um. Jetzt bekommen die drei Variablen je ein Variablenprofil mit Klartext (EASEE.Status, EASEE.Limit, EASEE.PhaseMode): wieder „Ladung beendet“, „Begrenzt durch Wallbox-Maximum“, „Automatisch“ – und ohne den Hinweis „Darstellung nicht kompatibel“, weil ein Profil keine Variablenaktion braucht.

### Build 8 (05.10.2026)

#### Behoben
- „Status“, „Strombegrenzung (Easee)“ und „Phasenmodus Wallbox“ hatten die Darstellung „Aufzählung“. Die geht nur mit Variablenaktion – Symcon meldete in den Eigenschaften „Darstellung nicht kompatibel … nur für Variablen mit einer Variablenaktion verfügbar“. Jetzt „Wertanzeige“ mit denselben Klartexten (wie bei den Ja/Nein-Anzeigen seit Build 4). „Phasenwahl“ bleibt eine Aufzählung, weil sie schaltbar ist.

### Build 7 (04.10.2026)

#### Behoben
- Ströme wurden der falschen Phase zugeordnet: Bei 1-phasigem Laden zeigten „Strom L1“ und „Strom L2“ je 10 A. Die Easee misst an den Klemmen T2–T5; bei 3 Phasen + N ist T2 der Neutralleiter (T1 = PE, T2 = N, T3 = L1, T4 = L2, T5 = L3). Jetzt: Observation 183/184/185 → Strom L1/L2/L3, 182 → neue Variable „Strom N“.

#### Neu
- Einstellung „Anschluss der Wallbox“: „3 Phasen + N + PE“ (Standard) oder „Ohne N (IT-Netz)“ – bei IT-Netz gilt die alte Zuordnung, „Strom N“ entfällt.
- Nach jeder Stromvorgabe fragt das Modul 25 s später den Stand der Wallbox ab. Hat sie die Vorgabe nicht umgesetzt, greift der Wächter (vorher nur, wenn neue Push-Werte kamen).

#### Geändert
- „Grund ohne Ladestrom“ heißt jetzt „Strombegrenzung (Easee)“ und zeigt bei Wert 0 „Keine Begrenzung“. Easee meldet hier auch während des Ladens, was den Strom gerade begrenzt – das ist kein Fehler. Vorhandene Variable wird nur umbenannt, wenn sie noch den alten Standardnamen hat.

### Build 6 (04.10.2026)

#### Behoben
- Wallbox lud nicht, obwohl Loxone eine Ziel-Ladeleistung (z. B. 2,77 kW) vorgab: Die Wallbox war „angehalten“ (Easee-App: „angehalten“, Grund ohne Ladestrom „Dynamischer Ladestrom zu niedrig“). Dann ist die Ladestrom-Freigabe der Wallbox selbst 0 A, und die Stromvorgabe am Circuit allein bewirkt nichts.

#### Neu
- Sobald geladen werden soll (Vorgabe > 0, Laden erlaubt, Fahrzeug angesteckt) und die Wallbox angehalten ist, setzt das Modul sie sofort fort: erst „Laden fortsetzen“ (wie in der App), ab dem 2. Versuch Ladestrom-Freigabe direkt auf den Höchststrom. Höchstens alle 20 s, nach 3 Versuchen Meldung im Log und nur noch alle 5 Minuten. Bei Pause-Vorgabe wird nichts fortgesetzt.
- Variable „Ladestrom-Freigabe der Wallbox“ (Observation 48, Dynamic Charger Current).
- Nach einer Ladevorgabe ohne laufende Ladung: Kontrollabfrage nach 25 s.

### Build 5 (04.10.2026)

#### Behoben
- Wallbox lud nach dem Einschalten bzw. Anstecken mit vollem Strom (z. B. 3-phasig ca. 10,5 kW), obwohl Pause (0 kW) vorgegeben war. Ursache: Easee setzt den dynamischen Strom beim Abstecken und beim Neustart auf den Standardwert zurück (Easee Home: 40 A); das Modul hielt die Pause aber für schon gesendet und schickte nichts mehr.

#### Neu
- **Wächter (Soll-Ist-Abgleich):** Lädt die Wallbox trotz Pause oder meldet sie einen anderen dynamischen Strom (Observations 111–113) als zuletzt gesendet, wird die Vorgabe erneut gesendet. Höchstens alle 20 s, erst 20 s nach dem letzten Senden; nach 3 erfolglosen Versuchen Meldung im Log, eine Pause wird dann jede Minute wiederholt, eine reine Stromabweichung nicht mehr (bis zur nächsten neuen Vorgabe).
- Nach dem Anstecken oder Einschalten (auch über Easee-App/Taster) Kontrollabfrage nach 15 s.

#### Geändert
- Erneutes Senden beim Anstecken jetzt bei jedem Wechsel in einen Zustand „Fahrzeug angesteckt“ (vorher nur aus „Offline“/„Kein Fahrzeug“), sofort statt nach 3 s; eine Pause wartet nicht auf den Mindestabstand.
- „Wallbox eingeschaltet“ über das Modul: zuerst wird die Vorgabe gesendet, dann eingeschaltet, danach noch einmal gesendet.
- Wird die Wallbox außerhalb des Moduls eingeschaltet (Observation 31), sendet das Modul die Vorgabe ebenfalls sofort erneut.
- „Letzter Befehl“ nennt den Grund des Sendens (z. B. „Fahrzeug angesteckt“, „Wächter: lädt trotz Pause“).

#### Dokumentation
- README: Abschnitt „Wächter“, Bedeutung von `timeToLive` = 0 laut Easee-Doku.

### Build 4 (30.09.2026)

#### Behoben
- Reine Anzeige-Variablen (Angemeldet, Push verbunden, Fahrzeug verbunden (Vc), Laden aktiv (Cac), Kabel verriegelt, Online) hatten die Darstellung „Schalter“. Die geht nur mit Variablenaktion – Symcon meldete „Darstellung nicht kompatibel“, und die Variablen ließen sich u. a. nicht fürs Archiv einstellen. Jetzt „Wertanzeige“ mit Klartext (z. B. Verbunden/Getrennt, Lädt/Lädt nicht).

### Build 3 (29.09.2026)

#### Geändert
- Neue Instanz setzt die Wallbox nicht mehr auf Pause: Solange noch nie eine Ladeleistung vorgegeben wurde, sendet das Modul nichts, sondern übernimmt den aktuellen Strom der Wallbox als Vorgabe (z. B. 16 A × 3 → 11,04 kW). Ist der Strom noch unbekannt, wartet das Modul, bis er per Abfrage oder Push kommt. „Letzter Befehl“ zeigt die Übernahme an.
- Variablennamen wie die Ein-/Ausgänge des Loxone-Wallbox-Bausteins: „Ziel-Ladeleistung (Tp)“, „Laden erlaubt (Ca)“, „Fahrzeug verbunden (Vc)“, „Laden aktiv (Cac)“, „Aktuelle Ladeleistung (Cp)“, „Zählerstand (Mr)“. Idents bleiben gleich; vorhandene Variablen werden nur umbenannt, wenn sie noch den alten Standardnamen haben.
- Eingehende Push-Werte stehen jetzt im Debug der Cloud-Instanz (z. B. „EH123456: 109 = "3"“).

### Build 2 (29.09.2026)

#### Geändert
- Push: Die Verbindung wird jetzt standardmäßig **direkt** aufgebaut (ohne vorherige Aushandlung). Hintergrund: Easee verteilt die Verbindungen auf mehrere Server; die Aushandlung setzt ein Cookie, das der Symcon-WebSocket-Client nicht mitschickt – dann lehnt Easee die Verbindung ab („No Connection with that ID“). Klappt eine Verbindungsart nicht, nimmt das Modul beim nächsten Versuch automatisch die andere.
- Easee Cloud legt beim Anlegen immer einen **eigenen** WebSocket Client an (statt einen vorhandenen anzubieten), weil das Modul dessen Adresse selbst setzt.
- Buttons „Verbindung testen“ und „Berechnung anzeigen“ zeigen das Ergebnis im Formular statt als Popup.
- Bei abgelaufenem Token (HTTP 401) wird zuerst das Refresh-Token genutzt, erst danach neu angemeldet.
- Spannung: alle Klemmenspannungen werden gelesen; angezeigt wird die Phasenspannung (ca. 230 V), nicht die Spannung zwischen zwei Phasen (400 V).

#### Behoben
- Beim Verbinden des Push wurde jede Wallbox doppelt abonniert.
- Status „Freigabe wird entzogen“ (8) zählt jetzt als „Fahrzeug angesteckt“.
- Push-Nachrichten mit Umlauten werden unabhängig von der Kodierung des WebSocket Client gelesen; gesendete Nachrichten werden korrekt kodiert.
- Kleinere Aufräumarbeiten (feste Rückgabetypen, kein `curl_close` mehr – in PHP 8.5 veraltet).

### Build 1 (29.09.2026)

#### Neu
- Modul **Easee Cloud** (Splitter): Anmeldung mit Easee-Zugangsdaten, automatische Token-Erneuerung (Refresh-Token nur einmal gültig, bei Fehler neue Anmeldung), Schutz vor dem API-Limit (eigener Zähler für Statusabfragen, Pause bei HTTP 429, Erkennung einer IP-Sperre), Push der Werte über SignalR mit Überwachung und automatischem Neuverbinden.
- Modul **Easee Wallbox** (Gerät): Werte der Wallbox (Status, Fahrzeug angesteckt, Laden aktiv, Ladeleistung, Energie, Zählerstand, Ströme, Spannung, Phasen, Fehler), Ladeleistung vorgeben in kW oder W, automatische Umrechnung in Strom und Phasen (1-/3-phasig).
- Regelmodus „Direkt“ (Vorgabe wird sofort umgesetzt, für den Loxone-Energiemanager) und „Mit Schutzlogik“ (Verzögerungen, Phasen-Hysterese, Mindeständerung).
- Einstellbar: Verhalten unter Mindeststrom, Lücke zwischen 1- und 3-phasig, Runden/Abrunden, Stromauflösung, Pause beim Wechsel 1 → 3 Phasen, Ablaufzeit der Vorgabe.
- Statusabfrage über den neuen Observations-Endpunkt (der alte State-Endpunkt wurde von Easee zum 01.09.2026 abgeschaltet).
- Skript `tests/RechenTest.php` zum Prüfen der Umrechnung in Symcon.
