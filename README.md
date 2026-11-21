# EaseeWallbox

Symcon-Bibliothek für die **Easee Home** Wallbox über die Easee-Cloud.
Die Ladeleistung lässt sich von außen vorgeben (Skript, Ablaufplan, Energiemanager). Für den Wallbox-Baustein von Loxone gibt es eine passende Darstellung.

**Aktuelle Version:** 1.0 (Build 18, 20.11.2026)

## Warum Cloud?

Easee bietet eine lokale Steuerung per OCPP bisher nur als Beta an: Stromregelung erst ab Firmware 344, Phasenumschaltung weiterhin nur über die Cloud. Deshalb läuft v1.0 über die Easee-Cloud-API.

## Aufbau

```
WebSocket Client (Symcon-I/O, für Push)
└── Easee Cloud (Splitter, Präfix EASEECLOUD)
    └── Easee Wallbox (Gerät, Präfix EASEE)
```

- **Easee Cloud**: Anmeldung, Token, alle Anfragen an Easee, Schutz vor dem API-Limit, Push (SignalR).
- **Easee Wallbox**: Variablen, Umrechnung Leistung → Strom/Phasen, Befehle.

Beim Anlegen der Wallbox-Instanz bietet Symcon an, die Cloud-Instanz mit anzulegen (oder eine vorhandene zu nutzen); die Cloud-Instanz bekommt immer einen eigenen WebSocket Client. Dessen Adresse setzt das Modul selbst – dort nichts eintragen.

## Installation

Im **Module Store** (Kanal Beta) unter „Easee Wallbox“ – oder in Symcon: **Kerninstanzen → Modulverwaltung (Module Control) → Hinzufügen** und diese Adresse eintragen:

```
https://github.com/peterchrisben/EaseeWallbox
```

Voraussetzung: Symcon 9.0 oder neuer, Easee-Konto mit Zugriff auf die Wallbox, Internetverbindung.

## Einrichtung

1. Instanz **Easee Wallbox** anlegen.
2. In der Instanz **Easee Cloud**: Benutzer und Passwort der Easee-App eintragen → „Verbindung testen“. Das Ergebnis mit den Seriennummern der Wallboxen steht direkt unter dem Button.
3. In der Instanz **Easee Wallbox**: Seriennummer eintragen, Einheit (kW/W) und Regelmodus wählen.
4. Button **„Phasenmodus der Wallbox auf Automatisch“** einmal drücken (nötig für die automatische Phasenumschaltung).

## Modul ein-/ausschalten

Ganz oben in der Wallbox-Instanz: **„Modul aktiv“**. Ausschalten und „Änderungen übernehmen“ – dann fragt das Modul nichts mehr ab, sendet nichts und regelt nicht. Die Werte (Status, Leistung, Phasen …) kommen weiter per Push, z. B. zum Beobachten eines Tests mit der Easee-App.

- **„Wenn „Modul aktiv“ ausgeschaltet wird: Ladestrom-Vorgabe von Symcon aufheben“** (Standard an): Beim Ausschalten des Moduls (nicht der Wallbox) wird die letzte Vorgabe des Moduls aufgehoben, die Wallbox verhält sich wie ohne Symcon. Ein angestecktes Auto lädt dann mit der Leistung, die in der Easee-App eingestellt ist (oft voll).
- Vorgaben, die im ausgeschalteten Zustand kommen (z. B. aus Loxone), werden gemerkt und beim Einschalten gesendet.
- Wieder einschalten: Häkchen setzen, übernehmen – das Modul liest die Wallbox neu ein und sendet die aktuelle Vorgabe.

## Darstellung für Loxone

In der Wallbox-Instanz gibt es das Häkchen **„Darstellung für Loxone“** (Standard: an).

**An:** Die Variablen heißen wie die Ein- und Ausgänge des Wallbox-Bausteins in Loxone Config, Kürzel in Klammern. Zusätzlich zeigt die Instanz den Bereich „Loxone“ mit der Zuordnung samt Variablen-ID. Typ und Einheit passen zum Baustein.

**Aus:** neutrale Namen, z. B. „Ziel-Ladeleistung“, „Laden aktiv“; der Bereich „Loxone“ ist ausgeblendet. Sinnvoll, wenn kein Loxone im Einsatz ist.

| Loxone-Baustein | Variable (Ident) | Typ | Einheit | Richtung |
|---|---|---|---|---|
| Ausgang **Tp** (Ziel-Ladeleistung) | Ziel-Ladeleistung (Tp) (`TargetPower`) | Float | kW | Loxone → Wallbox (Aktion) |
| Ausgang **Ca** (Laden erlaubt) | Laden erlaubt (Ca) (`ChargingAllowed`) | Boolean | – | Loxone → Wallbox (Aktion) |
| Eingang **Vc** (Fahrzeug verbunden) | Fahrzeug verbunden (Vc) (`VehicleConnected`) | Boolean | – | Wallbox → Loxone |
| Eingang **Cac** (Laden aktiv) | Laden aktiv (Cac) (`Charging`) | Boolean | – | Wallbox → Loxone |
| Eingang **Cp** (Aktuelle Ladeleistung) | Aktuelle Ladeleistung (Cp) (`Power`) | Float | kW | Wallbox → Loxone |
| Eingang **Mr** (Zählerstand) | Zählerstand (Mr) (`LifetimeEnergy`) | Float | kWh | Wallbox → Loxone |

- Umbenannt werden nur Standardnamen. Eigene Namen bleiben beim Umschalten erhalten. Idents und Variablen-IDs ändern sich nie, bestehende Verknüpfungen bleiben also gültig.
- Der Baustein arbeitet mit kW: „Einheit der Ladeleistung“ auf kW lassen (Standard). Bei W zeigt der Bereich „Loxone“ einen Hinweis.
- Als Regelmodus „Direkt“ wählen (Standard): Die Regelung macht Loxone, das Modul setzt die Vorgabe ohne eigene Verzögerung um.
- Wird ein Zählerstand über einen virtuellen Eingang in Loxone geschrieben: dort den Höchstwert passend erhöhen (Standard 100).
- Der Zählerstand (Mr) kommt von der Easee-Cloud nur etwa stündlich – auch die Easee-App zeigt ihn so alt. Siehe Abschnitt „Zählerstand fortschreiben“.

Ohne Häkchen funktioniert alles genauso – es ändern sich nur Namen und Anzeige.

## Zählerstand fortschreiben

Die Easee-Cloud liefert den Zählerstand (Mr, Lebensdauer-Energie) nur etwa einmal pro Stunde und am Ende eines Ladevorgangs. Die Energie des laufenden Ladevorgangs meldet sie dagegen alle paar Minuten. Wer in Loxone oder Symcon mit dem Zählerstand rechnet (Wallbox-Baustein, Zählerbaustein, Archiv), bekommt sonst einmal pro Stunde einen Sprung von bis zu 11 kWh.

Mit der Einstellung **„Zählerstand zwischen den Cloud-Updates fortschreiben“** (Bereich Abfrageintervalle, Standard an) rechnet das Modul dazwischen:

`Zählerstand = letzter Cloud-Zählerstand + (Energie Ladevorgang jetzt − Energie Ladevorgang beim Cloud-Zählerstand)`

- Beide Werte stammen aus demselben Messwerk der Wallbox; es wird nichts aus Leistung und Zeit geschätzt. Die Abweichung ist nur Rundung und Zeitversatz.
- Beginnt die Wallbox einen neuen Ladevorgang (Energie springt auf 0, z. B. nach einer Pause), wird der bisherige Zuwachs in die Basis übernommen – kein Sprung.
- Der Wert läuft **nie rückwärts**. Liegt er beim nächsten Cloud-Wert etwas zu hoch, bleibt er so lange stehen, bis der echte Zählerstand ihn eingeholt hat. Ein Fehler kann sich nicht aufsummieren, er ist auf eine Stunde begrenzt und danach weg.
- Im Debug zeigt „Zählerstand“ jeden Cloud-Wert und jeden Neustart des Ladevorgangs – so lässt sich prüfen, wie nah die Fortschreibung am echten Wert liegt.
- **Ohne Häkchen** wird der Cloud-Wert unverändert angezeigt (Verhalten bis Build 14). Wer einen eigenen Zähler an der Wallbox hat, braucht die Fortschreibung nicht.

## Energiemanager (nur Messen)

Mit **„Messwerte für einen Energiemanager bereitstellen“** (Bereich Energiemanager, Standard aus) bietet die Instanz ihre Messwerte nach der Schnittstelle „charger“ (Version 1.13) des NRG-Stack an ([Beschreibung](https://github.com/DG65/NRGChargerHub/blob/main/docs/EIGENES_WALLBOX_MODUL.md)). Ein Energiemanager, der diese Schnittstelle unterstützt – z. B. das NRG-Stack EMS ab Version 0.110.0 –, findet die Wallbox dann selbst über `EASEE_GetFunctions` und liest mit:

- Ladeleistung in **Watt** – zeigt das Modul kW an, kommt dafür die Variable „Ladeleistung (W)“ dazu
- Zählerstand (kWh, mit Fortschreibung), Status, Fahrzeug verbunden, aktive Phasen, Seriennummer
- Zeitpunkt des letzten Kontakts zur Cloud (zählt nicht, solange die Wallbox als offline gemeldet ist)

**Gesteuert wird die Wallbox darüber nicht** – das bleibt bei diesem Modul (Ziel-Ladeleistung). Der Energiemanager bekommt dazu die Angabe „wird von einem anderen System geregelt“. Damit die Werte nie älter als 10 Minuten sind, fragt das Modul die Cloud bei gesetztem Häkchen spätestens alle 5 Minuten ab (kostet etwa eine Abfrage je 5 Minuten).

## Regelmodus

**Beim ersten Verbinden** sendet das Modul nichts an die Wallbox. Es übernimmt den aktuellen Strom der Wallbox als „Ziel-Ladeleistung (Tp)“ (z. B. 16 A × 3 Phasen → 11,04 kW). Erst wenn du oder Loxone eine Vorgabe setzt, regelt das Modul.

**Direkt (Standard):** Die Vorgabe wird sofort umgesetzt. Es gibt keine Hysterese, keine Verzögerung und keine Mindeständerung. 0 bedeutet Pause.

**Mit Schutzlogik:** Start- und Stoppverzögerung, verzögerter Phasenwechsel mit Mindestabstand, Mindeständerung in W. Gedacht für den Betrieb ohne übergeordnete Regelung.

## Wächter: kein ungewollter Ladestart

Easee setzt den dynamischen Strom beim Abstecken des Fahrzeugs und beim Neustart auf den Standardwert zurück (Easee Home: 40 A). Beim nächsten Anstecken oder Einschalten würde die Wallbox deshalb mit vollem Strom loslegen – auch wenn im Modul Pause (0 kW) vorgegeben ist. Seit Build 5 passt das Modul darauf auf:

- **Fahrzeug angesteckt** oder **Wallbox eingeschaltet** (egal ob über das Modul, die Easee-App oder einen Taster): Die aktuelle Vorgabe wird sofort erneut gesendet, eine Pause ohne Wartezeit. 15 s später prüft eine Kontrollabfrage, ob die Wallbox sie übernommen hat.
- **„Wallbox eingeschaltet“ über das Modul:** Erst wird die Vorgabe gesendet, dann eingeschaltet, danach noch einmal gesendet.
- **Soll-Ist-Abgleich:** Lädt die Wallbox, obwohl Pause vorgegeben ist, oder meldet sie einen anderen dynamischen Strom als zuletzt gesendet, sendet das Modul die Vorgabe erneut – höchstens alle 20 s und erst 20 s nach dem letzten Senden. Nach 3 erfolglosen Versuchen gibt es eine Meldung im Log; eine Pause wird dann weiter jede Minute gesendet, eine reine Stromabweichung nicht mehr (bis zur nächsten neuen Vorgabe).
- „Letzter Befehl“ zeigt den Grund, z. B. „Fahrzeug angesteckt, Pause (Vorgabe 0)“. Details im Debug unter „Wächter“.
- **Wallbox „angehalten“** (z. B. Pause in der Easee-App, Strombegrenzung „Dynamischer Ladestrom zu niedrig“): Soll geladen werden, setzt das Modul die Wallbox sofort fort – erst „Laden fortsetzen“, ab dem 2. Versuch Ladestrom-Freigabe direkt auf den Höchststrom. Variable „Ladestrom-Freigabe der Wallbox“ zeigt den Wert. Hinweis: Eine Pause in der Easee-App wird dadurch aufgehoben, sobald Loxone Leistung vorgibt – Loxone hat Vorrang.

Ein Anlauf von wenigen Sekunden beim Anstecken lässt sich über die Cloud nicht ganz ausschließen: Die Wallbox entscheidet selbst, und das Modul erfährt es erst per Push.

## Umrechnung Leistung → Strom (Standard: 230 V, 6–16 A, abrunden, 1 A)

| Vorgabe | Ergebnis |
|---|---|
| unter 1,38 kW | Pause (einstellbar: „mit Mindeststrom laden“) |
| 1,38 – 3,68 kW | 1-phasig, 6 – 16 A |
| 3,68 – 4,14 kW | Lücke: 1 × 16 A (einstellbar: „nächster Wert“) |
| ab 4,14 kW | 3-phasig, 6 – 16 A |

Die Easee bekommt Ampere. Bei 1 A Auflösung sind das Stufen von ca. 230 W (1-phasig) bzw. 690 W (3-phasig). Beispiel: 11,0 kW → 15 A × 3 = 10,35 kW. Mit „0,1 A“ wird es genauer, falls die Wallbox das umsetzt (im Test prüfen). Der Button **„Berechnung anzeigen“** zeigt jederzeit unter den Buttons, was gesendet würde.

Gesendet wird der dynamische Circuit-Strom je Phase (P1/P2/P3). 1-phasig bedeutet P2 = P3 = 0 A.

**Phasenwechsel:** Die Easee unterbricht die Ladung dabei selbst kurz (Status „Warte auf Start“), schaltet um und lädt nach ca. 25–30 s weiter – in beide Richtungen, das Modul muss dafür nichts tun. Jeder Wechsel steht in „Letzter Befehl“ und im Debug; mit **„Phasenwechsel im Symcon-Log melden“** (Standard an) zusätzlich im Symcon-Log. Wer häufige Wechsel vermeiden will, nimmt den Regelmodus „Mit Schutzlogik“ (Phasenwechsel verzögert, Mindestabstand) oder begrenzt die Vorgabe in der übergeordneten Regelung.

## Ströme und Strombegrenzung

Die Easee misst den Strom an ihren Anschlussklemmen. Bei „3 Phasen + N + PE“ (Standard, in Deutschland üblich) gilt: T1 = PE, T2 = N, T3 = L1, T4 = L2, T5 = L3. Das Modul zeigt Strom L1, L2, L3 und N. Beim 1-phasigen Laden fließt derselbe Strom über L1 und N zurück – beide zeigen dann z. B. 10 A. Für IT-Netze (ohne N) gibt es die Einstellung „Ohne N“.

„Strombegrenzung (Easee)“ ist die Meldung der Wallbox, was den Ladestrom gerade begrenzt (z. B. „Begrenzt durch Wallbox-Maximum“, „Dynamischer Ladestrom zu niedrig“). Sie kommt auch während des Ladens und ist kein Fehler; 0 = „Keine Begrenzung“.

## Push

Die Werte kommen über SignalR (WebSocket). Das Modul verbindet sich zuerst **direkt** mit `streams.easee.com` (ohne vorherige Aushandlung). Kommt keine Verbindung zustande, versucht es beim nächsten Mal die Verbindung **mit Aushandlung** – und wechselt so lange hin und her, bis eine klappt (Wartezeit 10 s bis 5 min). Welche Art gerade genutzt wird, steht im Debug der Cloud-Instanz („WebSocket wird verbunden (direkt)“). Ohne Push läuft alles per Abfrage weiter, nur langsamer.

**Überwachung (seit Build 11):**
- Fällt die Verbindung aus (Server trennt, Netzwerk weg, WebSocket Client meldet einen Fehler), verbindet das Modul automatisch neu – erst nach 10 s, dann mit wachsender Wartezeit bis 5 min, unbegrenzt oft.
- Zusätzlich prüft das Modul alle 5 min, ob Push laufen soll, aber weder verbunden noch ein Versuch geplant ist, und stößt dann einen neuen Versuch an.
- Nach **3 Fehlversuchen in Folge oder 10 min ohne Verbindung** steht eine **Fehlermeldung (rot) im Symcon-Log** und in „Letzter Fehler“ – einmal pro Ausfall. Ist die Verbindung wieder da, folgt eine normale Meldung „wieder hergestellt“.
- In der Zwischenzeit kommen die Werte per Abfrage (lädt: alle 30 s), die Regelung läuft also weiter.

## API-Limit

- Werte kommen per **Push**. Eine Abfrage läuft dann nur alle 10 min zur Kontrolle.
- Ohne Push richtet sich die Abfrage nach dem Zustand: lädt 30 s, angesteckt 2 min, kein Fahrzeug 10 min.
- Statusabfragen sind bei Easee auf 100 je 5 Minuten begrenzt. Das Modul zählt mit (Variable in der Cloud-Instanz) und hält selbst bei 90 an.
- Befehle werden nur gesendet, wenn sich Strom oder Phasen wirklich ändern. Technischer Mindestabstand: 5 s.
- Bei HTTP 429 oder einer IP-Sperre durch Easee pausiert das Modul automatisch und versucht es später wieder.

## Öffentliche Funktionen

```php
EASEE_SetChargingPower(int $InstanzID, float $Leistung);   // in kW oder W (je nach Einstellung)
EASEE_SetChargingAllowed(int $InstanzID, bool $Freigabe);
EASEE_SetPhaseSelection(int $InstanzID, int $Auswahl);     // 0 = automatisch, 1 = fest 1-phasig, 3 = fest 3-phasig
EASEE_Update(int $InstanzID);
EASEE_SetChargerEnabled(int $InstanzID, bool $Ein);
EASEE_SetCableLock(int $InstanzID, bool $Verriegeln);
EASEE_SetChargerPhaseModeAuto(int $InstanzID);
EASEE_Reboot(int $InstanzID);
EASEE_GetCalculation(int $InstanzID);                      // Text: was würde gesendet
EASEE_GetFunctions(int $InstanzID);                        // Messwerte für einen Energiemanager (leer, wenn aus)
EASEECLOUD_TestConnection(int $InstanzID);
EASEECLOUD_ReconnectPush(int $InstanzID);
```

## Testreihenfolge

1. `tests/RechenTest.php` als Skript in Symcon ausführen (prüft nur die Umrechnung).
2. Cloud-Instanz: „Verbindung testen“.
3. Wallbox-Instanz: Seriennummer eintragen, Werte beobachten. Kommen sie per Push? Das zeigt die Variable „Push verbunden“ in der Cloud-Instanz.
4. Ohne Auto: „Berechnung anzeigen“ mit verschiedenen Vorgaben.
5. Mit Auto: Vorgabe 2 kW (1-phasig), 7 kW (3-phasig), wieder 2 kW, dann 0 (Pause).
6. Erst dann mit der übergeordneten Regelung (z. B. Loxone) verbinden.

Bei Problemen: Debug-Ausgabe der Cloud-Instanz (HTTP- und Push-Meldungen, Token werden nicht angezeigt).

## Gut zu wissen

- **Cloud-Abhängigkeit:** Ohne Internet oder bei einer Störung der Easee-Cloud kann das Modul nichts senden. Die Wallbox lädt dann mit der zuletzt gesendeten Vorgabe weiter (bis zum Abstecken). Beim Wiederverbinden holt das Modul den Stand nach.
- **Push:** Werte kommen über den WebSocket Client sofort. Bricht die Verbindung ab, verbindet das Modul selbst neu und meldet längere Ausfälle im Log.

## Noch offen

- Ob die Wallbox 0,1-A-Schritte umsetzt (Standard: 1 A).
- Anstecken bzw. Einschalten der Wallbox bei Vorgabe 0 noch einmal ausführlich prüfen.

Rückmeldungen bitte im Symcon-Forum oder als GitHub-Issue.

## Lizenz

CC BY-NC-SA 4.0 – private Nutzung ja, kommerzielle Nutzung nein (siehe `LICENSE`).

Easee ist eine Marke ihres jeweiligen Inhabers. EaseeWallbox ist ein privates Projekt und steht in keiner Verbindung zum Hersteller.
