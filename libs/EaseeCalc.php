<?php

declare(strict_types=1);

/**
 * Gemeinsame Hilfsfunktionen für EaseeWallbox.
 * Reine Rechenlogik ohne Symcon-Abhängigkeit (dadurch einfach testbar).
 */
class EaseeCalc
{
    /** Observation 109 – Charger Op Mode */
    public const OP_MODES = [
        0 => 'Offline',
        1 => 'Kein Fahrzeug',
        2 => 'Warte auf Start',
        3 => 'Lädt',
        4 => 'Ladung beendet',
        5 => 'Fehler',
        6 => 'Bereit zum Laden',
        7 => 'Warte auf Freigabe',
        8 => 'Freigabe wird entzogen'
    ];

    /** Observation 96 – Reason for no current (häufige Werte) */
    public const REASONS = [
        0   => 'Keine Begrenzung',
        1   => 'Max. Circuit-Strom zu niedrig',
        2   => 'Dynamischer Circuit-Strom zu niedrig',
        3   => 'Offline-Circuit-Strom zu niedrig',
        4   => 'Circuit-Sicherung zu niedrig',
        5   => 'Warteschlange',
        6   => 'Fahrzeug voll / verzögert',
        7   => 'Unzulässiger Netztyp',
        8   => 'Keine Stromanforderung erhalten',
        9   => 'Verbindung zum Master verloren',
        10  => 'Kein Strom vom Equalizer',
        11  => 'Keine Phase verbunden',
        25  => 'Begrenzt durch Circuit-Sicherung',
        26  => 'Begrenzt durch Lastverteilung',
        27  => 'Begrenzt durch dynamischen Circuit-Strom',
        28  => 'Begrenzt durch Equalizer',
        29  => 'Begrenzt durch Lastverteilung',
        30  => 'Begrenzt durch Offline-Einstellung',
        50  => 'Sekundäreinheit fordert keinen Strom an',
        51  => 'Max. Ladestrom zu niedrig',
        52  => 'Dynamischer Ladestrom zu niedrig',
        53  => 'Wallbox deaktiviert',
        54  => 'Ladeplan aktiv',
        55  => 'Warte auf Autorisierung',
        56  => 'Wallbox im Fehlerzustand',
        57  => 'Fahrzeug verhält sich auffällig',
        75  => 'Begrenzt durch Kabel',
        76  => 'Begrenzt durch Ladeplan',
        77  => 'Begrenzt durch Wallbox-Maximum',
        78  => 'Begrenzt durch dynamischen Ladestrom',
        79  => 'Fahrzeug lädt nicht',
        80  => 'Lokale Anpassung',
        81  => 'Begrenzt durch Fahrzeug',
        100 => 'Unbekannt'
    ];

    /** Phasenwahl (Variable PhaseSelection) */
    public const PHASE_SELECTION = [
        0 => 'Automatisch nach Leistung',
        1 => 'Fest 1-phasig',
        3 => 'Fest 3-phasig'
    ];

    /** Observation 38 – Phase mode der Wallbox */
    public const CHARGER_PHASE_MODES = [
        1 => '1-phasig gesperrt',
        2 => 'Automatisch',
        3 => '3-phasig gesperrt'
    ];

    /**
     * Rechnet eine Leistungsvorgabe in Strom und Phasen um.
     *
     * $cfg:
     *   Voltage        Spannung je Phase in V (z. B. 230)
     *   MinCurrent     Mindeststrom in A (Easee: 6)
     *   MaxCurrent     Höchststrom je Phase in A (z. B. 16)
     *   Step           Stromauflösung in A (1.0 oder 0.1)
     *   Rounding       0 = abrunden (nie mehr als vorgegeben), 1 = kaufmännisch runden
     *   UnderMinimum   0 = Pause, 1 = mit Mindeststrom laden
     *   GapMode        0 = nicht überschreiten (1-phasig Maximum), 1 = nächster Wert
     *   PhaseSelection 0 = automatisch, 1 = fest 1-phasig, 3 = fest 3-phasig
     *
     * Rückgabe: amps, phases, pause, currents [P1,P2,P3], effW (tatsächlich angefragte Leistung), note
     */
    public static function Calculate(array $cfg, float $powerW, bool $allowed, int $lastPhases): array
    {
        $voltage = max(100.0, (float) $cfg['Voltage']);
        $minA = max(0.0, (float) $cfg['MinCurrent']);
        $maxA = max($minA, (float) $cfg['MaxCurrent']);
        $keepPhases = in_array($lastPhases, [1, 3], true) ? $lastPhases : 3;

        if (!$allowed) {
            return self::Result(0.0, $keepPhases, true, 'Pause (Laden nicht freigegeben)', $voltage);
        }
        if ($powerW <= 0.0) {
            return self::Result(0.0, $keepPhases, true, 'Pause (Vorgabe 0)', $voltage);
        }

        $selection = (int) $cfg['PhaseSelection'];
        $gapUp = false;
        if ($selection === 1 || $selection === 3) {
            $phases = $selection;
            $note = 'fest ' . $selection . '-phasig';
        } else {
            $max1 = $voltage * $maxA;        // höchste Leistung 1-phasig
            $min3 = 3.0 * $voltage * $minA;  // kleinste Leistung 3-phasig
            if ($powerW >= $min3) {
                $phases = 3;
                $note = '3-phasig';
            } elseif ($powerW <= $max1) {
                $phases = 1;
                $note = '1-phasig';
            } elseif ((int) $cfg['GapMode'] === 1 && ($min3 - $powerW) < ($powerW - $max1)) {
                $phases = 3;
                $note = 'Lücke 1/3 Phasen: nächster Wert (3-phasig Minimum)';
                $gapUp = true;
            } else {
                $phases = 1;
                $note = 'Lücke 1/3 Phasen: 1-phasig Maximum';
            }
        }

        $step = (float) $cfg['Step'];
        if ($step <= 0.0) {
            $step = 1.0;
        }
        $raw = $powerW / ($voltage * $phases);
        if ((int) $cfg['Rounding'] === 1) {
            $amps = round($raw / $step) * $step;
        } else {
            $amps = floor($raw / $step + 1e-9) * $step;
        }
        $amps = round($amps, 1);

        if ($gapUp && $amps < $minA) {
            $amps = $minA; // Lücke: nächster Wert ist 3-phasig mit Mindeststrom
        }
        if ($amps < $minA) {
            if ((int) $cfg['UnderMinimum'] === 1) {
                $amps = $minA;
                $note .= ', unter Minimum: Mindeststrom';
            } else {
                return self::Result(0.0, $phases, true, 'Pause (unter Mindeststrom)', $voltage);
            }
        }
        if ($amps > $maxA) {
            $amps = $maxA;
            $note .= ', auf Maximum begrenzt';
        }
        return self::Result($amps, $phases, false, $note, $voltage);
    }

    private static function Result(float $amps, int $phases, bool $pause, string $note, float $voltage): array
    {
        if ($pause) {
            $currents = [0.0, 0.0, 0.0];
        } else {
            $currents = [$amps, $phases === 3 ? $amps : 0.0, $phases === 3 ? $amps : 0.0];
        }
        return [
            'amps'     => $pause ? 0.0 : $amps,
            'phases'   => $phases,
            'pause'    => $pause,
            'currents' => $currents,
            'effW'     => $pause ? 0.0 : round($amps * $voltage * $phases),
            'note'     => $note
        ];
    }

    /** Wandelt Observation-Werte (oft als String geliefert) in bool um. */
    public static function ToBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float) $value) != 0.0;
        }
        return in_array(strtolower(trim((string) $value)), ['true', 'yes', 'on'], true);
    }

    /**
     * Phasenspannung aus den Klemmenspannungen (Observations 190–199).
     * Im TN-Netz (Deutschland) gibt es 230 V (Phase–N) und 400 V (Phase–Phase) – angezeigt wird die Phasenspannung,
     * also der höchste Wert bis 260 V. Gibt es keinen solchen Wert, wird der höchste Wert genommen.
     */
    public static function PhaseVoltage(array $volts): float
    {
        $phase = 0.0;
        $all = 0.0;
        foreach ($volts as $v) {
            $v = (float) $v;
            $all = max($all, $v);
            if ($v <= 260.0) {
                $phase = max($phase, $v);
            }
        }
        return $phase > 50.0 ? $phase : $all;
    }

    /**
     * Mittlere Phasenspannung bei 3 Phasen + N: Observation 194/195/196 (N–L1/L2/L3). Phasen unter 50 V (nicht
     * angeschlossen) zählen nicht. 0, wenn keine Phase bekannt.
     */
    public static function MeanPhaseVoltage(array $volts): float
    {
        $sum = 0.0;
        $n = 0;
        foreach ([194, 195, 196] as $id) {
            $v = (float) ($volts[$id] ?? 0.0);
            if ($v > 50.0) {
                $sum += $v;
                $n++;
            }
        }
        return $n > 0 ? $sum / $n : 0.0;
    }

    /** Aktive Phasen aus Observation 110 (Output phase, z. B. 10/11 = 1-phasig, 20 = 2-phasig, 30 = 3-phasig). */
    public static function PhasesFromOutputPhase(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }
        $phases = intdiv($value, 10);
        return ($phases >= 1 && $phases <= 3) ? $phases : 0;
    }
}
