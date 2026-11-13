<?php

// Rechentest für EaseeWallbox – als Skript in Symcon einfügen und ausführen.
// Prüft die Umrechnung Leistung -> Strom/Phasen, ohne etwas an die Wallbox zu senden.

require_once IPS_GetKernelDir() . 'modules' . DIRECTORY_SEPARATOR . 'EaseeWallbox' . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'EaseeCalc.php';

$cfg = [
    'Voltage'        => 230,
    'MinCurrent'     => 6,
    'MaxCurrent'     => 16,
    'Step'           => 1.0,
    'Rounding'       => 0,
    'UnderMinimum'   => 0,
    'GapMode'        => 0,
    'PhaseSelection' => 0
];

$expected = [
    0     => 'Pause',
    1000  => 'Pause',
    1380  => '6.0 A x1',
    2000  => '8.0 A x1',
    3680  => '16.0 A x1',
    3900  => '16.0 A x1',
    4140  => '6.0 A x3',
    5000  => '7.0 A x3',
    7000  => '10.0 A x3',
    11000 => '15.0 A x3',
    12000 => '16.0 A x3'
];

$fehler = 0;
foreach ($expected as $watt => $soll) {
    $r = EaseeCalc::Calculate($cfg, (float) $watt, true, 3);
    $ist = $r['pause'] ? 'Pause' : sprintf('%.1f A x%d', $r['amps'], $r['phases']);
    $ok = $ist === $soll;
    if (!$ok) {
        $fehler++;
    }
    printf("%6d W -> %-10s %s\n", $watt, $ist, $ok ? 'OK' : 'FEHLER (erwartet ' . $soll . ')');
}

// Lücke mit "nächster Wert"
$cfg['GapMode'] = 1;
$r = EaseeCalc::Calculate($cfg, 4000.0, true, 1);
$ok = !$r['pause'] && $r['phases'] === 3 && $r['amps'] == 6.0;
printf("  4000 W (nächster Wert) -> %s\n", $ok ? 'OK (6 A x3)' : 'FEHLER');
$fehler += $ok ? 0 : 1;

echo $fehler === 0 ? "\nAlle Tests OK\n" : "\n$fehler Fehler\n";
