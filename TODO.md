# TODO – EaseeWallbox

## Optionen (bei Bedarf umsetzen)

### Ladefreigabe durch das Modul („Autorisierung erforderlich“)
**Ziel:** Die Wallbox startet nach dem Anstecken gar nicht erst von selbst – auch nicht für ein paar Sekunden.

- In der Wallbox wird „Autorisierung erforderlich“ eingeschaltet. Nach dem Anstecken wartet sie dann im Zustand „Warte auf Freigabe“.
- Das Modul gibt das Laden erst frei (`start_charging` über die Easee-Cloud), wenn Loxone eine Ziel-Ladeleistung > 0 vorgibt und „Laden erlaubt“ an ist. Ein RFID-Chip ist dafür nicht nötig.
- **Zuschaltbare Option, Standard: aus** – ohne Zutun ändert sich nichts.
- Notlösung bei Ausfall von Symcon, Internet oder Easee-Cloud: RFID-Chip oder „Start“ in der Easee-App funktionieren weiterhin.
- Jedes Anstecken braucht eine neue Freigabe – erledigt das Modul automatisch.
- **Vorher an der Wallbox testen:** Ersetzt der Cloud-Befehl die Freigabe wirklich? Das nutzen andere Projekte (z. B. evcc), in der offiziellen Easee-Doku ist es aber nicht bestätigt.
- **Wann:** Nur, wenn der kurze Anlauf beim Anstecken trotz Wächter (Build 5) noch stört.

### Stromregelung direkt an der Wallbox
- Falls die Wallbox Vorgaben am Stromkreis (dynamischer Circuit-Strom) ignoriert: Strom über die Ladestrom-Freigabe der Wallbox regeln (`set_dynamic_charger_current`, wie evcc) und den Stromkreis nur für die Umschaltung 1 ↔ 3 Phasen nutzen.
- Stand 04.10.2026: Regelung über den Stromkreis funktioniert (alle Befehle angenommen und umgesetzt). Der Aussetzer um ~13:10 (vermutlich durch das Modul-Update während der Ladung) wurde vom Wächter um 13:17 korrigiert. Nur umsetzen, falls Aussetzer wieder auftreten.

### Eigene Pause beim Phasenwechsel – erledigt (Build 16)
- Getestet 07.11.2026 (Debug): Die Easee unterbricht die Ladung in **beide** Richtungen selbst (Status „Warte auf Start“ → „Bereit“ → „Lädt“), schaltet um und lädt nach ca. 25–30 s weiter. Das Auto hat ohne Fehler weitergeladen. Die Modul-Pause bei 1 → 3 wurde in Build 16 entfernt; Phasenwechsel werden stattdessen gemeldet.

### Phasen-Hysterese auch im Modus „Direkt“ (Idee, 06.11.2026)
**Ziel:** Keine unnötigen Phasenwechsel für kurze Zeit, z. B. bei einer Wolke oder einer kurzen Verbrauchsspitze.

- Vorbild: LoxBerry-Plugin Easee Home (`post_dynamicPower` mit `hys1to3` / `hys3to1` in Sekunden).
- Zwei eigene Einstellungen unter „Phasenumschaltung“: „Wechsel 1 → 3 erst nach … s“ und „Wechsel 3 → 1 erst nach … s“, **Standard 0 = sofort** (ändert nichts am heutigen Verhalten).
- Gewechselt wird nur, wenn die Vorgabe die ganze Wartezeit über bzw. unter der Grenze (4,14 kW) liegt; sonst beginnt die Zeit neu. Die Leistung folgt der Vorgabe weiter sofort, nur die Phasenzahl wartet.
- Während der Wartezeit:
  - Warten auf 1 → 3: einphasig mit Höchststrom (16 A = 3,68 kW) weiter.
  - Warten auf 3 → 1: offen – a) weiter mit 3 × 6 A = 4,14 kW (mehr als vorgegeben, Rest aus Netz/Akku) oder b) Pause bis zum Wechsel. Vorschlag: a), Wartezeit kurz halten (2–5 min); für 1 → 3 z. B. 10 min.
- Bisher gibt es Phasenverzögerung nur im Modus „Mit Schutzlogik“, zusammen mit Start-/Stoppverzögerung und Mindeständerung.
- **Wann:** Bei Bedarf, einfach halten. Test vom 07.11.2026 erledigt: Die Easee macht den Wechsel selbst sauber (siehe oben).

## Für ein größeres Update (gesammelt)

### Wallbox aus der Liste auswählen
- Die Wallbox-Instanz zeigt die Wallboxen des Easee-Kontos schon als Liste. Neu: Zeile anklicken → Seriennummer steht im Feld (statt abtippen).
- Kleiner Aufwand, spart Tippfehler.

### Konfigurator für mehrere Wallboxen
- Eigenes Modul „Easee Konfigurator“ an der Cloud-Instanz: listet alle Wallboxen des Kontos und legt die Instanzen per Klick an.
- Eine Discovery passt nicht (die sucht im eigenen Netzwerk und muss ohne Einrichtung laufen; die Easee ist nur über das Cloud-Konto erreichbar).
- **Wann:** Wenn sich Nutzer mit mehreren Wallboxen melden.

## Noch zu testen (mit Auto)
- ✔ 04.10.2026: 1-phasig 6–15 A, 3-phasig 15 A, Pause, Wechsel 1 → 3 (mit Pause) und 3 → 1 (ohne Pause) funktionieren.
- ✔ Wächter „lädt trotz Pause“ hat in der Praxis gegriffen (04.10.2026, 13:17). Offen: Anstecken bei Vorgabe 0, Einschalten der Wallbox bei Vorgabe 0.
- Angehaltene Wallbox (Pause in der App) wird bei Vorgabe > 0 fortgesetzt.
- Build 7: Ströme L1/L2/L3/N plausibel, „Strombegrenzung (Easee)“.
- Ob die Wallbox 0,1-A-Schritte umsetzt.
- ✔ 07.11.2026: Phasenwechsel ohne Modul-Pause – die Easee pausiert in beide Richtungen selbst, Auto lädt weiter (→ Build 16).
- Build 16: Meldung „Phasenwechsel 1 → 3 / 3 → 1“ in „Letzter Befehl“ und im Symcon-Log (abschaltbar) bei der nächsten PV-Ladung prüfen.

## Offene Fragen
- Wer setzt die Ladestrom-Freigabe der Wallbox (Observation 48) auf 0?
- Meldet Easee per Push Änderungen von Observation 48 und 111–113?
