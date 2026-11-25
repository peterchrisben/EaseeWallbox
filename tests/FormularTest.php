<?php

declare(strict_types=1);

/**
 * Prüft alle form.json der Bibliothek: Beschriftungen von Eingabefeldern höchstens 38 Zeichen.
 *
 * Hintergrund: Symcon 9.1 bricht lange Beschriftungen um und legt sie über das Feld darüber
 * (9.0 hat sie mit „…“ gekürzt). Erklärungen und Beispiele gehören in ein eigenes Label.
 * Im Code erzeugte Formulare bauen in diesem Modul keine Eingabefelder – geprüft werden daher die form.json.
 *
 * Aufruf: php tests/FormularTest.php   (Rückgabe 0 = ok, 1 = zu lange Beschriftung gefunden)
 */

const MAX_CAPTION = 38;
const INPUT_TYPES = [
    'ValidationTextBox', 'PasswordTextBox', 'NumberSpinner', 'Select', 'SelectVariable', 'SelectInstance',
    'SelectObject', 'SelectScript', 'SelectTime', 'SelectDate', 'SelectDateTime', 'SelectColor', 'SelectFile',
    'IntervalBox'
];

function checkElements($node, string $file, array &$errors): void
{
    if (!is_array($node)) {
        return;
    }
    if (isset($node['type'], $node['caption']) && in_array($node['type'], INPUT_TYPES, true) && is_string($node['caption'])) {
        $len = mb_strlen($node['caption']);
        if ($len > MAX_CAPTION) {
            $errors[] = sprintf('%s: %s „%s“ (%s) hat %d Zeichen', $file, $node['type'], $node['caption'], $node['name'] ?? '-', $len);
        }
    }
    foreach ($node as $key => $child) {
        if ($key === 'columns') {
            continue; // Spaltenköpfe von Listen sind nicht betroffen
        }
        checkElements($child, $file, $errors);
    }
}

$errors = [];
$files = glob(dirname(__DIR__) . '/*/form.json');
foreach ($files as $file) {
    $form = json_decode((string) file_get_contents($file), true);
    if (!is_array($form)) {
        $errors[] = basename(dirname($file)) . '/form.json: kein gültiges JSON';
        continue;
    }
    checkElements($form, basename(dirname($file)) . '/form.json', $errors);
}

if ($errors !== []) {
    echo "FEHLER – Beschriftungen über " . MAX_CAPTION . " Zeichen:\n" . implode("\n", $errors) . "\n";
    exit(1);
}
echo 'OK – ' . count($files) . ' Formulare geprüft, alle Beschriftungen von Eingabefeldern höchstens ' . MAX_CAPTION . " Zeichen.\n";
exit(0);
