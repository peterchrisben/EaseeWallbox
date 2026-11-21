<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EaseeCalc.php';
require_once __DIR__ . '/../libs/EaseeEMS.php'; // EMS: Schnittstelle für Energiemanager

/**
 * Easee Wallbox (Gerät)
 * - Werte der Wallbox (Push über Easee Cloud, Abfrage nur als Rückfall)
 * - Ladeleistung vorgeben (kW oder W), Strom und Phasen werden daraus berechnet
 * - Modus "Direkt": macht genau, was vorgegeben wird (für Loxone-Energiemanager)
 * - Modus "Mit Schutzlogik": Verzögerungen / Hysterese / Mindeständerung
 */
class EaseeWallbox extends IPSModule
{
    use EaseeEMS; // EMS: Schnittstelle für Energiemanager (libs/EaseeEMS.php)

    private const TO_PARENT = '{2B9E74AC-9022-4EC7-B69F-1DAA196FCFF2}';
    private const SPLITTER = '{5FD11832-BF89-40A8-B49B-405783EEB6B5}';

    /** Observations, die per Abfrage gelesen werden (eine Anfrage für alle) */
    private const OBS_IDS = [30, 31, 38, 48, 80, 96, 103, 109, 110, 111, 112, 113, 119, 120, 121, 124, 182, 183, 184, 185, 190, 191, 192, 193, 194, 195, 196, 197, 198, 199, 250];

    /**
     * Variablen mit eigener Bezeichnung für Loxone:
     * [Standardname, Name wie Loxone-Wallbox-Baustein, [frühere Standardnamen], Baustein-Anschluss, Richtung]
     */
    private const VAR_NAMES = [
        'TargetPower'      => ['Ziel-Ladeleistung', 'Ziel-Ladeleistung (Tp)', ['Ladeleistung Vorgabe'], 'Ausgang Tp', 'Loxone → Wallbox'],
        'ChargingAllowed'  => ['Laden erlaubt', 'Laden erlaubt (Ca)', ['Laden freigegeben'], 'Ausgang Ca', 'Loxone → Wallbox'],
        'VehicleConnected' => ['Fahrzeug verbunden', 'Fahrzeug verbunden (Vc)', ['Fahrzeug angesteckt'], 'Eingang Vc', 'Wallbox → Loxone'],
        'Charging'         => ['Laden aktiv', 'Laden aktiv (Cac)', [], 'Eingang Cac', 'Wallbox → Loxone'],
        'Power'            => ['Aktuelle Ladeleistung', 'Aktuelle Ladeleistung (Cp)', ['Ladeleistung'], 'Eingang Cp', 'Wallbox → Loxone'],
        'LifetimeEnergy'   => ['Zählerstand', 'Zählerstand (Mr)', [], 'Eingang Mr', 'Wallbox → Loxone']
    ];

    /** Technischer Mindestabstand zwischen zwei Stromvorgaben (Sekunden) */
    private const MIN_SEND_INTERVAL = 5;

    /** Op-Modes mit angestecktem Fahrzeug */
    private const CONNECTED_MODES = [2, 3, 4, 6, 7, 8];

    /** Wächter: so lange nach dem Senden nicht prüfen (Werte der Wallbox kommen verzögert), Sekunden */
    private const GUARD_SETTLE = 20;

    /** Wächter: Abstand zwischen zwei Korrekturen, Sekunden (nach 3 Versuchen: GUARD_SLOW) */
    private const GUARD_INTERVAL = 20;
    private const GUARD_SLOW = 60;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ChargerID', '');
        // Build 14: Modul ein/aus (aus = keine Abfragen, keine Befehle, keine Regelung)
        $this->RegisterPropertyBoolean('ModuleActive', true);
        $this->RegisterAttributeBoolean('WasActive', true);

        // Ladeleistung & Regelung
        $this->RegisterPropertyInteger('PowerUnit', 0);      // 0 = kW, 1 = W
        $this->RegisterPropertyInteger('ControlMode', 0);    // 0 = Direkt, 1 = Mit Schutzlogik
        $this->RegisterPropertyInteger('UnderMinimum', 0);   // 0 = Pause, 1 = Mindeststrom
        $this->RegisterPropertyInteger('GapMode', 0);        // 0 = nicht überschreiten, 1 = nächster Wert
        $this->RegisterPropertyInteger('Rounding', 0);       // 0 = abrunden, 1 = runden
        $this->RegisterPropertyInteger('CurrentStep', 0);    // 0 = 1 A, 1 = 0,1 A
        $this->RegisterPropertyInteger('MinCurrent', 6);
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        $this->RegisterPropertyInteger('Voltage', 230);
        $this->RegisterPropertyInteger('GridType', 0);       // 0 = 3 Phasen + N (TN), 1 = ohne N (IT-Netz)

        // Build 13: Darstellung für Loxone (Variablennamen wie der Loxone-Wallbox-Baustein, Zuordnungsliste)
        $this->RegisterPropertyBoolean('LoxoneMode', true);
        $this->RegisterPropertyBoolean('LiveMeter', true);     // Zählerstand zwischen den Cloud-Updates fortschreiben

        // Phasenumschaltung
        // Build 16: Die Easee pausiert beim Phasenwechsel selbst – keine eigene Pause mehr, nur Meldung
        $this->RegisterPropertyBoolean('LogPhaseSwitch', true);

        $this->EmsCreate(); // EMS: Schnittstelle für Energiemanager

        // Schutzlogik (nur ControlMode 1)
        $this->RegisterPropertyInteger('StartDelay', 60);
        $this->RegisterPropertyInteger('StopDelay', 120);
        $this->RegisterPropertyInteger('PhaseDelay', 120);
        $this->RegisterPropertyInteger('PhaseMinInterval', 300);
        $this->RegisterPropertyInteger('MinChange', 200);

        // Sicherheit
        $this->RegisterPropertyInteger('TimeToLive', 0);     // Minuten, 0 = kein Ablauf

        // Abfrageintervalle (Sekunden)
        $this->RegisterPropertyInteger('PollCharging', 30);
        $this->RegisterPropertyInteger('PollConnected', 120);
        $this->RegisterPropertyInteger('PollIdle', 600);
        $this->RegisterPropertyInteger('PollWithPush', 600);

        $this->RegisterAttributeString('InitCharger', '');
        $this->RegisterAttributeInteger('SiteID', 0);
        $this->RegisterAttributeInteger('CircuitID', 0);
        $this->RegisterAttributeString('Desired', '');
        $this->RegisterAttributeString('LastSent', '');
        $this->RegisterAttributeInteger('LastSendTime', 0);
        $this->RegisterAttributeInteger('LastPhases', 3);
        $this->RegisterAttributeInteger('LastPhaseSwitch', 0);
        $this->RegisterAttributeInteger('PendingStartSince', 0);
        $this->RegisterAttributeInteger('PendingStopSince', 0);
        $this->RegisterAttributeInteger('PendingPhaseSince', 0);
        $this->RegisterAttributeBoolean('ForceSend', false);
        $this->RegisterAttributeInteger('LastOpMode', -1);
        $this->RegisterAttributeInteger('LastUnit', -1);
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeBoolean('TargetKnown', false);
        $this->RegisterAttributeString('Meter', '');           // Fortschreibung des Zählerstands: base, session, out // false = noch keine Vorgabe -> nichts senden, Wert der Wallbox übernehmen

        $this->RegisterTimer('TimerInit', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerInit", 0);');
        $this->RegisterTimer('TimerPoll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerPoll", 0);');
        $this->RegisterTimer('TimerSend', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerSend", 0);');
        $this->RegisterTimer('TimerEvaluate', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerEvaluate", 0);');
        $this->RegisterTimer('TimerKeepAlive', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerKeepAlive", 0);');
        $this->RegisterTimer('TimerVerify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerVerify", 0);');
        $this->RegisterTimer('TimerResume', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerResume", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariables();

        $chargerID = strtoupper(trim($this->ReadPropertyString('ChargerID')));
        $this->SetReceiveDataFilter('.*"ChargerID":"(\*|' . preg_quote($chargerID, '/') . ')".*');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        if (!$this->ReadPropertyBoolean('ModuleActive')) {
            $this->SwitchOff();
            return;
        }
        $this->WriteAttributeBoolean('WasActive', true);

        if ($chargerID === '') {
            $this->StopTimers();
            $this->SetStatus(202);
            return;
        }

        // Andere Wallbox gewählt -> alles zurücksetzen
        if ($chargerID !== $this->ReadAttributeString('InitCharger')) {
            $this->WriteAttributeInteger('SiteID', 0);
            $this->WriteAttributeInteger('CircuitID', 0);
            $this->WriteAttributeString('LastSent', '');
            $this->WriteAttributeInteger('LastOpMode', -1);
        }
        $this->WriteAttributeBoolean('ForceSend', true);
        $this->SetTimerInterval('TimerInit', 1000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::SPLITTER]]);
    }

    public function RequestAction($Ident, $Value)
    {
        if (!$this->IsActive()) {
            switch ($Ident) {
                case 'TargetPower':
                case 'ChargingAllowed':
                case 'PhaseSelection':
                    // Vorgabe merken, aber nichts senden – greift beim Wiedereinschalten
                    $this->SetValue($Ident, $Ident === 'TargetPower' ? max(0.0, (float) $Value) : ($Ident === 'PhaseSelection' ? (int) $Value : (bool) $Value));
                    if ($Ident === 'TargetPower') {
                        $this->WriteAttributeBoolean('TargetKnown', true);
                    }
                    return;
                case 'FormLoxoneMode':
                    break;
                default:
                    if (strpos($Ident, 'Timer') === 0) {
                        $this->StopTimers();
                        return;
                    }
                    throw new Exception('Easee Wallbox ist ausgeschaltet („Modul aktiv“ in der Instanz).');
            }
        }
        switch ($Ident) {
            case 'TargetPower':
                $this->SetValue('TargetPower', max(0.0, (float) $Value));
                $this->WriteAttributeBoolean('TargetKnown', true);
                $this->Evaluate(false);
                break;
            case 'ChargingAllowed':
                $this->SetValue('ChargingAllowed', (bool) $Value);
                $this->Evaluate(false);
                break;
            case 'PhaseSelection':
                $value = (int) $Value;
                if (!array_key_exists($value, EaseeCalc::PHASE_SELECTION)) {
                    throw new Exception('Ungültige Phasenwahl: ' . $value);
                }
                $this->SetValue('PhaseSelection', $value);
                $this->Evaluate(false);
                break;
            case 'ChargerEnabled':
                if ((bool) $Value) {
                    // Erst die aktuelle Vorgabe (z. B. Pause) senden, dann einschalten – sonst legt die Wallbox mit vollem Strom los
                    $this->WriteAttributeBoolean('ForceSend', true);
                    $this->WriteAttributeInteger('LastSendTime', 0);
                    $this->TrySend();
                }
                if ($this->Command('POST', '/api/chargers/' . $this->ChargerID() . '/settings', ['enabled' => (bool) $Value], 'Wallbox ' . ($Value ? 'ein' : 'aus'))) {
                    $this->SetValue('ChargerEnabled', (bool) $Value);
                    if ((bool) $Value) {
                        $this->AfterWakeUp('Wallbox eingeschaltet');
                    }
                }
                break;
            case 'CableLockPermanent':
                if ($this->Command('POST', '/api/chargers/' . $this->ChargerID() . '/commands/lock_state', ['state' => (bool) $Value], 'Kabel dauerhaft ' . ($Value ? 'verriegeln' : 'entriegeln'))) {
                    $this->SetValue('CableLockPermanent', (bool) $Value);
                }
                break;

            case 'FormLoxoneMode':
                // Nur Anzeige im Formular; wirksam wird die Einstellung mit „Änderungen übernehmen“
                $this->UpdateFormField('LoxonePanel', 'visible', (bool) $Value);
                break;
            case 'TimerInit':
                $this->SetTimerInterval('TimerInit', 0);
                $this->InitCharger();
                break;
            case 'TimerPoll':
                $this->Poll();
                break;
            case 'TimerSend':
                $this->SetTimerInterval('TimerSend', 0);
                $this->TrySend();
                break;
            case 'TimerEvaluate':
                $this->SetTimerInterval('TimerEvaluate', 0);
                $this->Evaluate(false);
                break;
            case 'TimerKeepAlive':
                $this->SetTimerInterval('TimerKeepAlive', 0);
                $this->WriteAttributeBoolean('ForceSend', true);
                $this->TrySend();
                break;
            case 'TimerResume':
                $this->SetTimerInterval('TimerResume', 0);
                $this->ResumeCharger();
                break;
            case 'TimerVerify':
                // Kontrollabfrage nach Anstecken/Einschalten: stimmt der Strom der Wallbox mit der Vorgabe überein?
                $this->SetTimerInterval('TimerVerify', 0);
                $this->Poll();
                $this->Guard();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }
        switch ($data['Type'] ?? '') {
            case 'Observation':
                $this->ApplyObservation((int) $data['ID'], $data['Value'] ?? null);
                $this->SetValue('LastUpdate', time());
                $this->EmsSeen(); // EMS: letzter Kontakt
                if (!$this->IsActive()) {
                    // Ausgeschaltet: Werte per Push weiter anzeigen, aber nichts regeln
                    $this->StopTimers();
                }
                break;
            case 'PushState':
                // Abos erneuert die Cloud-Instanz selbst beim Verbinden
                $this->SetBuffer('PushConnected', !empty($data['Connected']) ? '1' : '0');
                if ($this->IsActive()) {
                    $this->SchedulePoll();
                }
                break;
        }
        return '';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $chargers = [];
        $res = $this->ParentCall(['Function' => 'GetChargers']);
        if (!empty($res['ok']) && is_array($res['data'] ?? null)) {
            foreach ($res['data'] as $c) {
                $chargers[] = ['id' => $c['id'], 'name' => $c['name']];
            }
        }
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'FoundChargers') {
                $element['values'] = $chargers;
            }
        }
        unset($element);
        $loxone = $this->ReadPropertyBoolean('LoxoneMode');
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'LoxonePanel') {
                $element['visible'] = $loxone;
                foreach ($element['items'] as &$item) {
                    if (($item['name'] ?? '') === 'LoxoneList') {
                        $item['values'] = $this->LoxoneRows();
                    }
                    if (($item['name'] ?? '') === 'LoxoneUnitHint') {
                        $item['visible'] = $this->ReadPropertyInteger('PowerUnit') !== 0;
                    }
                }
                unset($item);
            }
        }
        unset($element);
        return json_encode($form);
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen (EASEE_...)
    // ---------------------------------------------------------------------

    /**
     * EMS: Messwerte für einen Energiemanager (Vertrag „charger“ 1.13, z. B. NRG-Stack EMS).
     * Liefert eine leere Liste, solange „Schnittstelle für Energiemanager“ aus ist.
     */
    public function GetFunctions(): array
    {
        return $this->EmsFunctions();
    }

    /** Ladeleistung in der eingestellten Einheit (kW oder W) vorgeben. */
    public function SetChargingPower(float $Power): bool
    {
        $this->RequestAction('TargetPower', $Power);
        return true;
    }

    public function SetChargingAllowed(bool $Allowed): bool
    {
        $this->RequestAction('ChargingAllowed', $Allowed);
        return true;
    }

    /** 0 = automatisch, 1 = fest 1-phasig, 3 = fest 3-phasig */
    public function SetPhaseSelection(int $Selection): bool
    {
        $this->RequestAction('PhaseSelection', $Selection);
        return true;
    }

    public function Update(): bool
    {
        return $this->Poll();
    }

    public function SetChargerEnabled(bool $Enabled): bool
    {
        $this->RequestAction('ChargerEnabled', $Enabled);
        return true;
    }

    public function SetCableLock(bool $Locked): bool
    {
        $this->RequestAction('CableLockPermanent', $Locked);
        return true;
    }

    /** Stellt den Phasenmodus der Wallbox dauerhaft auf "Automatisch" (nötig für die Phasenumschaltung). */
    public function SetChargerPhaseModeAuto(): bool
    {
        $ok = $this->Command('POST', '/api/chargers/' . $this->ChargerID() . '/settings', ['phaseMode' => 2], 'Phasenmodus Automatisch');
        if ($ok) {
            $this->SetValue('ChargerPhaseMode', 2);
        }
        return $ok;
    }

    public function Reboot(): bool
    {
        return $this->Command('POST', '/api/chargers/' . $this->ChargerID() . '/commands/reboot', null, 'Neustart');
    }

    /** Zeigt, was bei der aktuellen Vorgabe an die Wallbox gesendet würde (zum Testen). */
    public function GetCalculation(): string
    {
        $calc = $this->CalculateTarget(null);
        $text = sprintf(
            "Vorgabe: %.0f W → %s, %.1f A, %d Phase(n), P1/P2/P3: %.1f/%.1f/%.1f A, tatsächlich ca. %.0f W (%s)",
            $this->TargetPowerW(),
            $calc['pause'] ? 'Pause' : 'Laden',
            $calc['amps'],
            $calc['phases'],
            $calc['currents'][0],
            $calc['currents'][1],
            $calc['currents'][2],
            $calc['effW'],
            $calc['note']
        );
        // Ergebnis im Formular anzeigen statt als Popup
        $this->UpdateFormField('CalcResult', 'caption', $text);
        return $text;
    }

    // ---------------------------------------------------------------------
    // Variablen
    // ---------------------------------------------------------------------

    private function RegisterVariables(): void
    {
        $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
        $unit = $kw ? ' kW' : ' W';
        $digits = $kw ? 2 : 0;

        // Einheit umgestellt -> Vorgabe umrechnen
        $lastUnit = $this->ReadAttributeInteger('LastUnit');
        $current = $this->ReadPropertyInteger('PowerUnit');
        $convert = ($lastUnit !== -1 && $lastUnit !== $current && @$this->GetIDForIdent('TargetPower') > 0);

        // Steuerung (Vorgabe von außen, z. B. durch Loxone)
        $this->RegisterVariableFloat('TargetPower', $this->VarName('TargetPower'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => $kw ? 22 : 22000,
            'STEP_SIZE'    => $kw ? 0.1 : 100,
            'SUFFIX'       => $unit,
            'DIGITS'       => $digits
        ], 1);
        $this->EnableAction('TargetPower');
        $this->RegisterVariableBoolean('ChargingAllowed', $this->VarName('ChargingAllowed'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 2);
        $this->EnableAction('ChargingAllowed');
        $this->RegisterVariableInteger('PhaseSelection', 'Phasenwahl', $this->Enum(EaseeCalc::PHASE_SELECTION), 3);
        $this->EnableAction('PhaseSelection');

        // Zustand
        $this->RegisterVariableInteger('Status', 'Status', $this->EnumDisplay(EaseeCalc::OP_MODES), 10);
        $this->RegisterVariableBoolean('VehicleConnected', $this->VarName('VehicleConnected'), $this->BoolDisplay('Getrennt', 'Verbunden'), 11);
        $this->RegisterVariableBoolean('Charging', $this->VarName('Charging'), $this->BoolDisplay('Lädt nicht', 'Lädt'), 12);
        $this->RegisterVariableFloat('Power', $this->VarName('Power'), $this->Value($unit, $digits), 13);
        $this->RegisterVariableFloat('SessionEnergy', 'Energie Ladevorgang', $this->Value(' kWh', 2), 14);
        $this->RegisterVariableFloat('LifetimeEnergy', $this->VarName('LifetimeEnergy'), $this->Value(' kWh', 2), 15);

        // Elektrisch
        $this->RegisterVariableInteger('ActivePhases', 'Aktive Phasen', $this->Value('', 0), 20);
        $this->RegisterVariableFloat('CurrentL1', 'Strom L1', $this->Value(' A', 1), 21);
        $this->RegisterVariableFloat('CurrentL2', 'Strom L2', $this->Value(' A', 1), 22);
        $this->RegisterVariableFloat('CurrentL3', 'Strom L3', $this->Value(' A', 1), 23);
        if ($this->ReadPropertyInteger('GridType') === 0) {
            $this->RegisterVariableFloat('CurrentN', 'Strom N', $this->Value(' A', 1), 25);
            // Build 12: Spannung je Phase (N–L1/L2/L3, Observation 194/195/196)
            $this->RegisterVariableFloat('VoltageL1', 'Spannung L1', $this->Value(' V', 1), 26);
            $this->RegisterVariableFloat('VoltageL2', 'Spannung L2', $this->Value(' V', 1), 27);
            $this->RegisterVariableFloat('VoltageL3', 'Spannung L3', $this->Value(' V', 1), 28);
        } else {
            $this->UnregisterVariable('CurrentN');
            $this->UnregisterVariable('VoltageL1');
            $this->UnregisterVariable('VoltageL2');
            $this->UnregisterVariable('VoltageL3');
        }
        $this->RegisterVariableFloat('Voltage', 'Spannung', $this->Value(' V', 0), 24);

        // Vorgabe an die Wallbox
        $this->RegisterVariableFloat('SetCurrent', 'Vorgegebener Strom', $this->Value(' A', 1), 30);
        $this->RegisterVariableInteger('SetPhases', 'Vorgegebene Phasen', $this->Value('', 0), 31);
        $this->RegisterVariableInteger('ReasonNoCurrent', 'Strombegrenzung (Easee)', $this->EnumDisplay(EaseeCalc::REASONS), 32);
        $this->RegisterVariableFloat('ChargerCurrent', 'Ladestrom-Freigabe der Wallbox', $this->Value(' A', 1), 33);

        // Wallbox-Einstellungen
        $this->RegisterVariableBoolean('ChargerEnabled', 'Wallbox eingeschaltet', ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 40);
        $this->EnableAction('ChargerEnabled');
        $this->RegisterVariableBoolean('CableLockPermanent', 'Kabel dauerhaft verriegeln', ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 41);
        $this->EnableAction('CableLockPermanent');
        $this->RegisterVariableBoolean('CableLocked', 'Kabel verriegelt', $this->BoolDisplay('Nein', 'Ja'), 42);
        $this->RegisterVariableInteger('ChargerPhaseMode', 'Phasenmodus Wallbox', $this->EnumDisplay(EaseeCalc::CHARGER_PHASE_MODES), 43);

        // Diagnose
        $this->RegisterVariableBoolean('Online', 'Online', $this->BoolDisplay('Offline', 'Online'), 50);
        $this->RegisterVariableInteger('ErrorCode', 'Fehlercode', $this->Value('', 0), 51);
        $this->RegisterVariableInteger('Firmware', 'Firmware', $this->Value('', 0), 52);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 53);
        $this->RegisterVariableString('LastCommand', 'Letzter Befehl', '', 54);

        $this->RemoveOldProfiles();

        // Namen je nach „Darstellung für Loxone“ anpassen – nur Standardnamen, eigene Namen bleiben
        foreach (self::VAR_NAMES as $ident => $names) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid <= 0) {
                continue;
            }
            $target = $this->VarName($ident);
            $defaults = array_merge([$names[0], $names[1]], $names[2] ?? []);
            $name = IPS_GetName($vid);
            if ($name !== $target && in_array($name, $defaults, true)) {
                IPS_SetName($vid, $target);
            }
        }
        // Build 7: alter Standardname
        $vid = @$this->GetIDForIdent('ReasonNoCurrent');
        if ($vid > 0 && IPS_GetName($vid) === 'Grund ohne Ladestrom') {
            IPS_SetName($vid, 'Strombegrenzung (Easee)');
        }

        if ($convert) {
            $value = (float) $this->GetValue('TargetPower');
            $this->SetValue('TargetPower', $current === 1 ? $value * 1000 : $value / 1000);
            $power = (float) $this->GetValue('Power');
            $this->SetValue('Power', $current === 1 ? $power * 1000 : $power / 1000);
        }
        $this->WriteAttributeInteger('LastUnit', $current);
        $this->EmsVariables(); // EMS: Leistung in W (nach einer Umrechnung der Einheit)

        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SetValue('ChargingAllowed', true);
            $this->SetValue('PhaseSelection', 0);
            $this->WriteAttributeBoolean('Initialized', true);
        }
    }

    /** Anzeige für Ja/Nein-Werte ohne Aktion (Schalter-Darstellung geht nur mit Variablenaktion). */
    private function BoolDisplay(string $off, string $on): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $off, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => $on, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x00AA00]
            ])
        ];
    }

    /** Bezeichnung einer Variable – mit Loxone-Kürzel, wenn „Darstellung für Loxone“ an ist. */
    private function VarName(string $ident): string
    {
        $names = self::VAR_NAMES[$ident];
        return $this->ReadPropertyBoolean('LoxoneMode') ? $names[1] : $names[0];
    }

    /** Zeilen der Liste „Zuordnung zum Loxone-Wallbox-Baustein“ im Formular. */
    private function LoxoneRows(): array
    {
        $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
        $types = [
            'TargetPower'      => 'Float, ' . ($kw ? 'kW' : 'W'),
            'ChargingAllowed'  => 'Boolean',
            'VehicleConnected' => 'Boolean',
            'Charging'         => 'Boolean',
            'Power'            => 'Float, ' . ($kw ? 'kW' : 'W'),
            'LifetimeEnergy'   => 'Float, kWh'
        ];
        $rows = [];
        foreach (self::VAR_NAMES as $ident => $names) {
            $vid = @$this->GetIDForIdent($ident);
            $rows[] = [
                'block'     => $names[3],
                'variable'  => $vid > 0 ? IPS_GetName($vid) : $names[1],
                'id'        => $vid > 0 ? (string) $vid : '–',
                'type'      => $types[$ident],
                'direction' => $names[4]
            ];
        }
        return $rows;
    }

    private function Value(string $suffix, int $digits): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    /**
     * Klartext für Zahlenwerte ohne Aktion (Status, Strombegrenzung, Phasenmodus): Wertanzeige mit Intervallen,
     * je Wert ein Intervall [Wert, Wert] mit festem Text (ConstantValue).
     * „Aufzählung“ geht nur mit Variablenaktion, OPTIONS der Wertanzeige nur für Boolean/String (Build 8 zeigte Zahlen).
     */
    private function EnumDisplay(array $options): array
    {
        $intervals = [];
        foreach ($options as $value => $caption) {
            // Nur die nötigen Schlüssel: Symcon 9.1 prüft die Unterparameter streng und lehnt
            // unbekannte ab (dann bricht „Übernehmen“ ab). Abgeschaltete Überschreibungen brauchen keine Werte.
            $intervals[] = [
                'IntervalMinValue' => (int) $value,
                'IntervalMaxValue' => (int) $value,
                'ConstantActive'   => true,
                'ConstantValue'    => (string) $caption,
                'ConversionFactor' => 1,
                'PrefixActive'     => false,
                'SuffixActive'     => false,
                'DigitsActive'     => false,
                'IconActive'       => false,
                'ColorActive'      => false
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS'           => 0,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals)
        ];
    }

    /** Build 9 nutzte Variablenprofile – nach der Umstellung auf Intervalle entfernen, wenn keine Variable sie mehr nutzt. */
    private function RemoveOldProfiles(): void
    {
        foreach (['EASEE.Status', 'EASEE.Limit', 'EASEE.PhaseMode'] as $name) {
            if (!IPS_VariableProfileExists($name)) {
                continue;
            }
            $used = false;
            foreach (IPS_GetVariableList() as $vid) {
                $v = IPS_GetVariable($vid);
                if (($v['VariableProfile'] ?? '') === $name || ($v['VariableCustomProfile'] ?? '') === $name) {
                    $used = true;
                    break;
                }
            }
            if (!$used) {
                IPS_DeleteVariableProfile($name);
            }
        }
    }

    /** Aufzählung (Auswahl) – nur für Variablen mit Aktion, z. B. Phasenwahl. */
    private function Enum(array $options): array
    {
        $list = [];
        foreach ($options as $value => $caption) {
            $list[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($list)];
    }

    /** Observation 182–185 (Strom an Klemme T2–T5) → Variable je nach Anschluss */
    private function CurrentIdent(int $obs): string
    {
        $map = $this->ReadPropertyInteger('GridType') === 0
            ? [182 => 'CurrentN', 183 => 'CurrentL1', 184 => 'CurrentL2', 185 => 'CurrentL3']
            : [182 => 'CurrentL1', 183 => 'CurrentL2', 184 => 'CurrentL3'];
        $ident = $map[$obs] ?? '';
        return ($ident !== '' && @$this->GetIDForIdent($ident) > 0) ? $ident : '';
    }

    /**
     * Zählerstand (Mr) fortschreiben. Die Cloud liefert den echten Zählerstand (Obs 124) nur etwa stündlich,
     * die Energie des Ladevorgangs (Obs 121) aber alle paar Minuten. Zwischen zwei echten Werten gilt:
     *   Zählerstand = letzter echter Wert + (Energie Ladevorgang jetzt − Energie Ladevorgang beim echten Wert)
     * Beginnt die Wallbox einen neuen Ladevorgang (Energie springt zurück), wird der bisherige Zuwachs
     * in die Basis übernommen. Der Wert läuft nie rückwärts, damit nachgelagerte Zähler keine negativen
     * Differenzen sehen; ein etwas zu hoher Schätzwert wird vom nächsten echten Wert eingeholt.
     */
    private function LiveMeter(?float $real, ?float $session): void
    {
        if (!$this->ReadPropertyBoolean('LiveMeter')) {
            if ($real !== null) {
                $this->SetIfChanged('LifetimeEnergy', round($real, 3));
            }
            return;
        }
        $m = json_decode($this->ReadAttributeString('Meter'), true);
        if (!is_array($m)) {
            $m = ['base' => null, 'session' => null, 'last' => null, 'out' => 0.0];
        }
        if ($session === null) {
            $session = $m['last'];
        }
        if ($real !== null) {
            $m['base'] = $real;
            $m['session'] = $session;
            $this->SendDebug('Zählerstand', sprintf('Cloud %.3f kWh', $real), 0);
        } elseif ($m['base'] === null) {
            return;                                      // noch kein echter Zählerstand bekannt
        } elseif ($m['session'] === null) {
            $m['session'] = $session;
        } elseif ($session !== null && $session + 0.001 < (float) $m['last']) {
            // neuer Ladevorgang: Zuwachs des alten in die Basis übernehmen
            $m['base'] = (float) $m['base'] + ((float) $m['last'] - (float) $m['session']);
            $m['session'] = 0.0;                         // der neue Ladevorgang zählt ab 0
            $this->SendDebug('Zählerstand', sprintf('Ladevorgang neu, Basis jetzt %.3f kWh', $m['base']), 0);
        }
        $m['last'] = $session;
        $estimate = (float) $m['base'];
        if ($session !== null && $m['session'] !== null) {
            $estimate += max(0.0, $session - (float) $m['session']);
        }
        $m['out'] = max((float) $m['out'], $estimate);
        $this->WriteAttributeString('Meter', json_encode($m));
        $this->SetIfChanged('LifetimeEnergy', round((float) $m['out'], 3));
    }

    private function SetIfChanged(string $ident, $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // ---------------------------------------------------------------------
    // Initialisierung & Abfrage
    // ---------------------------------------------------------------------

    private function InitCharger(): void
    {
        $id = $this->ChargerID();
        if ($id === '') {
            return;
        }
        if ($this->ParentID() === 0) {
            $this->SetStatus(200);
            $this->SetTimerInterval('TimerInit', 60 * 1000);
            return;
        }
        $this->Subscribe();

        // Site und Circuit werden für die Stromvorgabe gebraucht
        $site = $this->Api('GET', '/api/chargers/' . $id . '/site');
        if (!$site['ok'] || !is_array($site['data'])) {
            $this->SendDebug('Init', 'Site nicht lesbar: ' . ($site['error'] ?? ''), 0);
            $this->SetStatus(($site['status'] ?? 0) === 404 ? 201 : 200);
            $this->SetTimerInterval('TimerInit', max(60, (int) ($site['retryAfter'] ?? 60)) * 1000);
            return;
        }
        $siteID = (int) ($site['data']['id'] ?? 0);
        $circuitID = 0;
        foreach (($site['data']['circuits'] ?? []) as $circuit) {
            foreach (($circuit['chargers'] ?? []) as $charger) {
                if (strtoupper((string) ($charger['id'] ?? '')) === $id) {
                    $circuitID = (int) $circuit['id'];
                }
            }
        }
        if ($siteID === 0 || $circuitID === 0) {
            $this->SendDebug('Init', 'Wallbox nicht in der Site gefunden', 0);
            $this->SetStatus(201);
            $this->SetTimerInterval('TimerInit', 600 * 1000);
            return;
        }
        $this->WriteAttributeInteger('SiteID', $siteID);
        $this->WriteAttributeInteger('CircuitID', $circuitID);
        $this->WriteAttributeString('InitCharger', $id);
        $this->SendDebug('Init', 'Site ' . $siteID . ', Circuit ' . $circuitID, 0);

        // Einstellungen der Wallbox
        $config = $this->Api('GET', '/api/chargers/' . $id . '/config');
        if ($config['ok'] && is_array($config['data'])) {
            $c = $config['data'];
            if (isset($c['isEnabled'])) {
                $this->SetIfChanged('ChargerEnabled', (bool) $c['isEnabled']);
            }
            if (isset($c['lockCablePermanently'])) {
                $this->SetIfChanged('CableLockPermanent', (bool) $c['lockCablePermanently']);
            }
            if (isset($c['phaseMode'])) {
                $this->SetIfChanged('ChargerPhaseMode', (int) $c['phaseMode']);
            }
        }
        $this->CheckStatus();
        $known = $this->ReadAttributeBoolean('TargetKnown');
        $this->Poll();
        if (!$known) {
            // Erste Verbindung: nichts senden, sondern die aktuelle Einstellung der Wallbox übernehmen
            $this->AdoptFromCharger();
        }
        $this->Evaluate($known);
    }

    /**
     * Übernimmt den aktuellen dynamischen Strom der Wallbox (Observations 111–113) als Vorgabe,
     * solange noch nie eine Vorgabe gesetzt wurde. Es wird dabei nichts an die Wallbox gesendet.
     */
    private function AdoptFromCharger(): bool
    {
        if ($this->ReadAttributeBoolean('TargetKnown')) {
            return true;
        }
        $amps = json_decode($this->GetBuffer('DynAmps'), true) ?: [];
        if (!isset($amps[111], $amps[112], $amps[113])) {
            $this->SendDebug('Vorgabe', 'Strom der Wallbox noch unbekannt – warte, nichts gesendet', 0);
            return false;
        }
        $phases = ($amps[112] > 0 || $amps[113] > 0) ? 3 : 1;
        $a = min((float) max($amps[111], $amps[112], $amps[113]), (float) $this->ReadPropertyInteger('MaxCurrent'));
        $watt = max(0.0, $a) * $this->ReadPropertyInteger('Voltage') * $phases;
        $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
        $this->SetValue('TargetPower', $kw ? round($watt / 1000, 2) : round($watt));
        if ($watt > 0) {
            $this->WriteAttributeInteger('LastPhases', $phases);
        }
        $calc = $this->CalculateTarget(null);
        $this->WriteAttributeString('Desired', json_encode($calc));
        $this->WriteAttributeString('LastSent', json_encode($calc));
        $this->WriteAttributeBoolean('ForceSend', false);
        $this->WriteAttributeBoolean('TargetKnown', true);
        $text = sprintf('Vorgabe von der Wallbox übernommen: %.1f / %.1f / %.1f A = %s', $amps[111], $amps[112], $amps[113], $kw ? sprintf('%.2f kW', $watt / 1000) : sprintf('%.0f W', $watt));
        $this->SetValue('LastCommand', date('H:i:s') . '  ' . $text);
        $this->SendDebug('Vorgabe', $text, 0);
        return true;
    }

    private function CheckStatus(): void
    {
        if ($this->ReadAttributeInteger('CircuitID') === 0) {
            return;
        }
        $auto = (int) $this->GetValue('PhaseSelection') === 0;
        $mode = (int) $this->GetValue('ChargerPhaseMode');
        $status = ($auto && $mode !== 2 && $mode !== 0) ? 203 : 102;
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }

    private function IsActive(): bool
    {
        return $this->ReadPropertyBoolean('ModuleActive');
    }

    private function StopTimers(): void
    {
        foreach (['TimerInit', 'TimerPoll', 'TimerSend', 'TimerEvaluate', 'TimerKeepAlive', 'TimerVerify', 'TimerResume'] as $timer) {
            $this->SetTimerInterval($timer, 0);
        }
    }

    /** Modul ausschalten: keine Abfragen, keine Befehle, kein Wächter. Optional einmal die Strombegrenzung freigeben. */
    private function SwitchOff(): void
    {
        $this->StopTimers();
        $wasActive = $this->ReadAttributeBoolean('WasActive');
        $this->WriteAttributeBoolean('WasActive', false);
        if ($wasActive && $this->ReadAttributeInteger('CircuitID') > 0 && $this->ParentID() > 0) {
            // Höchststrom auf allen Phasen mit 1 min Ablaufzeit: danach gilt wieder die normale Circuit-Einstellung,
            // die Wallbox verhält sich wie ohne Symcon (Easee-App hat die Kontrolle)
            $max = (float) $this->ReadPropertyInteger('MaxCurrent');
            $this->SetBuffer('AllowWhileOff', '1');
            $path = '/api/sites/' . $this->ReadAttributeInteger('SiteID') . '/circuits/' . $this->ReadAttributeInteger('CircuitID') . '/dynamicCurrent';
            $res = $this->Api('POST', $path, ['phase1' => $max, 'phase2' => $max, 'phase3' => $max, 'timeToLive' => 1]);
            $this->SetBuffer('AllowWhileOff', '0');
            $this->SetValue('LastCommand', date('H:i:s') . '  Modul ausgeschaltet – Strombegrenzung freigegeben' . ($res['ok'] ? '' : ' – FEHLER: ' . ($res['error'] ?? '')));
        } elseif ($wasActive) {
            $this->SetValue('LastCommand', date('H:i:s') . '  Modul ausgeschaltet');
        }
        $this->WriteAttributeString('LastSent', '');
        // Push-Werte weiter anzeigen (kostet keine Abfragen)
        if ($this->ChargerID() !== '' && $this->ParentID() > 0) {
            $this->Subscribe();
        }
        $this->SetStatus(104);
    }

    private function Subscribe(): void
    {
        $id = $this->ChargerID();
        if ($id !== '') {
            $res = $this->ParentCall(['Function' => 'Subscribe', 'ChargerID' => $id]);
            $this->SetBuffer('PushConnected', !empty($res['push']) ? '1' : '0');
        }
    }

    private function Poll(): bool
    {
        $id = $this->ChargerID();
        if ($id === '' || $this->ParentID() === 0) {
            return false;
        }
        $res = $this->Api('GET', '/state/' . $id . '/observations?ids=' . implode(',', self::OBS_IDS));
        if (!$res['ok']) {
            $this->SendDebug('Abfrage', 'Fehler: ' . ($res['error'] ?? ''), 0);
            $this->SchedulePoll(max(60, (int) ($res['retryAfter'] ?? 60)));
            return false;
        }
        $list = $res['data'];
        if (is_array($list) && isset($list['observations']) && is_array($list['observations'])) {
            $list = $list['observations'];
        }
        if (is_array($list)) {
            foreach ($list as $key => $obs) {
                if (is_array($obs)) {
                    $obsID = (int) ($obs['id'] ?? ($obs['observationId'] ?? 0));
                    $this->ApplyObservation($obsID, $obs['value'] ?? null);
                } elseif (is_numeric($key)) {
                    // Falls die Antwort als {"109": 3, ...} kommt
                    $this->ApplyObservation((int) $key, $obs);
                }
            }
        }
        $this->SetValue('LastUpdate', time());
        $this->EmsSeen(); // EMS: letzter Kontakt
        $this->SchedulePoll();
        return true;
    }

    private function SchedulePoll(int $seconds = 0): void
    {
        if ($seconds <= 0) {
            if ($this->GetBuffer('PushConnected') === '1') {
                $seconds = $this->ReadPropertyInteger('PollWithPush');
            } else {
                $status = (int) $this->GetValue('Status');
                if ($status === 3) {
                    $seconds = $this->ReadPropertyInteger('PollCharging');
                } elseif (in_array($status, [2, 4, 6, 7, 8], true)) {
                    $seconds = $this->ReadPropertyInteger('PollConnected');
                } else {
                    $seconds = $this->ReadPropertyInteger('PollIdle');
                }
            }
        }
        $seconds = $this->EmsPollCap($seconds); // EMS: Werte nie älter als 10 min
        // Nie schneller als alle 10 s (Limit 100 Abfragen / 5 min für alle Instanzen zusammen)
        $this->SetTimerInterval('TimerPoll', max(10, $seconds) * 1000);
    }

    private function ApplyObservation(int $id, $value): void
    {
        if ($value === null) {
            return;
        }
        $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
        switch ($id) {
            case 109: // Op Mode
                $mode = (int) $value;
                $old = $this->ReadAttributeInteger('LastOpMode');
                $this->WriteAttributeInteger('LastOpMode', $mode);
                $this->SetIfChanged('Status', $mode);
                $this->SetIfChanged('VehicleConnected', in_array($mode, [2, 3, 4, 6, 7, 8], true));
                $this->SetIfChanged('Charging', $mode === 3);
                if (!in_array($old, self::CONNECTED_MODES, true) && in_array($mode, self::CONNECTED_MODES, true)) {
                    // Fahrzeug neu angesteckt: Easee setzt den dynamischen Strom beim Abstecken zurück (Home: 40 A)
                    // -> aktuelle Vorgabe sofort erneut senden, damit die Wallbox nicht mit vollem Strom loslegt
                    $this->AfterWakeUp('Fahrzeug angesteckt');
                }
                if ($old !== $mode) {
                    $this->SchedulePoll();
                }
                if ($mode === 3) {
                    $this->Guard();
                }
                break;
            case 120: // Total power kW
                $this->SetIfChanged('Power', round($kw ? (float) $value : (float) $value * 1000, $kw ? 3 : 0));
                $this->EmsPower((float) $value); // EMS: Leistung in W
                if ((float) $value > 0.1) {
                    $this->Guard();
                }
                break;
            case 121:
                $this->SetIfChanged('SessionEnergy', round((float) $value, 3));
                $this->LiveMeter(null, (float) $value);
                break;
            case 124:
                $this->LiveMeter((float) $value, null);
                break;
            case 110:
                $this->SetIfChanged('ActivePhases', EaseeCalc::PhasesFromOutputPhase((int) $value));
                break;
            case 182:
            case 183:
            case 184:
            case 185:
                // Klemmen der Easee: T1 = PE, T2 = N, T3 = L1, T4 = L2, T5 = L3 (3 Phasen + N).
                // Ohne N (IT-Netz): T2..T4 = L1..L3 (Zuordnung bis Build 6).
                $ident = $this->CurrentIdent($id);
                if ($ident !== '') {
                    $this->SetIfChanged($ident, round((float) $value, 1));
                }
                break;
            case 190:
            case 191:
            case 192:
            case 193:
            case 194:
            case 195:
            case 196:
            case 197:
            case 198:
            case 199:
                // Spannung zwischen den Klemmen T1..T5 -> Phasenspannung (siehe EaseeCalc::PhaseVoltage)
                $volts = json_decode($this->GetBuffer('Volts'), true) ?: [];
                $volts[$id] = (float) $value;
                $this->SetBuffer('Volts', json_encode($volts));
                if ($this->ReadPropertyInteger('GridType') === 0) {
                    // 3 Phasen + N: T2 = N → 194/195/196 = N–L1/L2/L3. „Spannung“ = Mittelwert der vorhandenen Phasen
                    // (bis Build 11 der höchste Wert aller Klemmen < 260 V inkl. PE–L – lag dadurch zu hoch).
                    $phases = [194 => 'VoltageL1', 195 => 'VoltageL2', 196 => 'VoltageL3'];
                    if (isset($phases[$id]) && @$this->GetIDForIdent($phases[$id]) > 0) {
                        $this->SetIfChanged($phases[$id], round((float) $value, 1));
                    }
                    $avg = EaseeCalc::MeanPhaseVoltage($volts);
                    if ($avg > 0.0) {
                        $this->SetIfChanged('Voltage', round($avg, 0));
                    }
                } else {
                    $this->SetIfChanged('Voltage', round(EaseeCalc::PhaseVoltage($volts), 0));
                }
                break;
            case 111:
            case 112:
            case 113:
                $amps = json_decode($this->GetBuffer('DynAmps'), true) ?: [];
                $amps[$id] = (float) $value;
                $this->SetBuffer('DynAmps', json_encode($amps));
                if ($id === 111) {
                    $this->SetIfChanged('SetCurrent', round((float) $value, 1));
                }
                if (isset($amps[112], $amps[113]) && (float) $this->GetValue('SetCurrent') > 0) {
                    $this->SetIfChanged('SetPhases', ($amps[112] > 0 || $amps[113] > 0) ? 3 : 1);
                }
                if (!$this->ReadAttributeBoolean('TargetKnown') && $this->ReadAttributeInteger('CircuitID') > 0) {
                    $this->AdoptFromCharger();
                } else {
                    $this->Guard();
                }
                break;
            case 96:
                $this->SetIfChanged('ReasonNoCurrent', (int) $value);
                $this->Guard();
                break;
            case 48: // Dynamic Charger Current: 0 = Wallbox „angehalten“ (z. B. Pause in der Easee-App)
                $this->SetIfChanged('ChargerCurrent', round((float) $value, 1));
                $this->SetBuffer('ChargerCurrentKnown', '1');
                $this->Guard();
                break;
            case 31:
                $enabled = EaseeCalc::ToBool($value);
                $wasEnabled = (bool) $this->GetValue('ChargerEnabled');
                $this->SetIfChanged('ChargerEnabled', $enabled);
                if ($enabled && !$wasEnabled) {
                    // Wallbox wurde eingeschaltet (App, Taster, Skript …) -> Vorgabe sofort erneut senden
                    $this->AfterWakeUp('Wallbox eingeschaltet');
                }
                break;
            case 30:
                $this->SetIfChanged('CableLockPermanent', EaseeCalc::ToBool($value));
                break;
            case 103:
                $this->SetIfChanged('CableLocked', EaseeCalc::ToBool($value));
                break;
            case 38:
                $this->SetIfChanged('ChargerPhaseMode', (int) $value);
                $this->CheckStatus();
                break;
            case 250:
                $this->SetIfChanged('Online', EaseeCalc::ToBool($value));
                break;
            case 119:
                $this->SetIfChanged('ErrorCode', (int) $value);
                break;
            case 80:
                $this->SetIfChanged('Firmware', (int) $value);
                break;
        }
    }

    // ---------------------------------------------------------------------
    // Regelung
    // ---------------------------------------------------------------------

    private function TargetPowerW(): float
    {
        $value = (float) $this->GetValue('TargetPower');
        return $this->ReadPropertyInteger('PowerUnit') === 0 ? $value * 1000 : $value;
    }

    private function CalcConfig(?int $forcePhases): array
    {
        return [
            'Voltage'        => $this->ReadPropertyInteger('Voltage'),
            'MinCurrent'     => $this->ReadPropertyInteger('MinCurrent'),
            'MaxCurrent'     => $this->ReadPropertyInteger('MaxCurrent'),
            'Step'           => $this->ReadPropertyInteger('CurrentStep') === 1 ? 0.1 : 1.0,
            'Rounding'       => $this->ReadPropertyInteger('Rounding'),
            'UnderMinimum'   => $this->ReadPropertyInteger('UnderMinimum'),
            'GapMode'        => $this->ReadPropertyInteger('GapMode'),
            'PhaseSelection' => $forcePhases ?? (int) $this->GetValue('PhaseSelection')
        ];
    }

    private function CalculateTarget(?int $forcePhases): array
    {
        return EaseeCalc::Calculate(
            $this->CalcConfig($forcePhases),
            $this->TargetPowerW(),
            (bool) $this->GetValue('ChargingAllowed'),
            $this->ReadAttributeInteger('LastPhases')
        );
    }

    private function Evaluate(bool $force): void
    {
        $desired = $this->CalculateTarget(null);
        if ($this->ReadPropertyInteger('ControlMode') === 1) {
            $desired = $this->ApplyProtection($desired);
        } else {
            $this->SetTimerInterval('TimerEvaluate', 0);
        }
        $this->WriteAttributeString('Desired', json_encode($desired));
        $this->CheckStatus();
        if ($force) {
            $this->WriteAttributeBoolean('ForceSend', true);
        }
        $this->TrySend();
    }

    /** Nur im Modus "Mit Schutzlogik": Verzögerungen, Phasen-Hysterese, Mindeständerung. */
    private function ApplyProtection(array $calc): array
    {
        $now = time();
        $last = json_decode($this->ReadAttributeString('LastSent'), true);
        $lastPause = !is_array($last) || !empty($last['pause']);
        $lastPhases = $this->ReadAttributeInteger('LastPhases');
        $pending = false;

        // Stopp verzögern
        if ($calc['pause'] && !$lastPause) {
            $since = $this->ReadAttributeInteger('PendingStopSince') ?: $now;
            $this->WriteAttributeInteger('PendingStopSince', $since);
            if ($now - $since < $this->ReadPropertyInteger('StopDelay')) {
                $calc = $this->MinimumAt($lastPhases, 'Stopp verzögert');
                $pending = true;
            }
        } else {
            $this->WriteAttributeInteger('PendingStopSince', 0);
        }

        // Start verzögern
        if (!$calc['pause'] && $lastPause) {
            $since = $this->ReadAttributeInteger('PendingStartSince') ?: $now;
            $this->WriteAttributeInteger('PendingStartSince', $since);
            if ($now - $since < $this->ReadPropertyInteger('StartDelay')) {
                $calc['pause'] = true;
                $calc['currents'] = [0.0, 0.0, 0.0];
                $calc['amps'] = 0.0;
                $calc['effW'] = 0.0;
                $calc['note'] = 'Start verzögert';
                $pending = true;
            }
        } else {
            $this->WriteAttributeInteger('PendingStartSince', 0);
        }

        // Phasenwechsel verzögern und Mindestabstand einhalten
        if (!$calc['pause'] && !$lastPause && $calc['phases'] !== $lastPhases) {
            $since = $this->ReadAttributeInteger('PendingPhaseSince') ?: $now;
            $this->WriteAttributeInteger('PendingPhaseSince', $since);
            $tooEarly = ($now - $since < $this->ReadPropertyInteger('PhaseDelay'))
                || ($now - $this->ReadAttributeInteger('LastPhaseSwitch') < $this->ReadPropertyInteger('PhaseMinInterval'));
            if ($tooEarly) {
                $calc = $this->CalculateTarget($lastPhases);
                if ($calc['pause']) {
                    $calc = $this->MinimumAt($lastPhases, 'Phasenwechsel verzögert');
                } else {
                    $calc['note'] = 'Phasenwechsel verzögert, ' . $calc['note'];
                }
                $pending = true;
            }
        } else {
            $this->WriteAttributeInteger('PendingPhaseSince', 0);
        }

        // Mindeständerung
        if (!$calc['pause'] && !$lastPause && is_array($last) && (int) $last['phases'] === $calc['phases']
            && abs($calc['effW'] - (float) $last['effW']) < $this->ReadPropertyInteger('MinChange')) {
            $calc = $last;
        }

        $this->SetTimerInterval('TimerEvaluate', $pending ? 5000 : 0);
        return $calc;
    }

    private function MinimumAt(int $phases, string $note): array
    {
        $amps = (float) $this->ReadPropertyInteger('MinCurrent');
        $phases = $phases === 1 ? 1 : 3;
        return [
            'amps'     => $amps,
            'phases'   => $phases,
            'pause'    => false,
            'currents' => [$amps, $phases === 3 ? $amps : 0.0, $phases === 3 ? $amps : 0.0],
            'effW'     => round($amps * $this->ReadPropertyInteger('Voltage') * $phases),
            'note'     => $note
        ];
    }

    private function TrySend(): void
    {
        $desired = json_decode($this->ReadAttributeString('Desired'), true);
        if (!is_array($desired)) {
            return;
        }
        if (!$this->ReadAttributeBoolean('TargetKnown')) {
            return; // noch keine Vorgabe (weder von dir/Loxone noch von der Wallbox übernommen)
        }
        $last = json_decode($this->ReadAttributeString('LastSent'), true);
        $force = $this->ReadAttributeBoolean('ForceSend');
        $same = is_array($last) && json_encode($last['currents']) === json_encode($desired['currents']);
        if ($same && !$force) {
            return;
        }
        if ($this->ReadAttributeInteger('CircuitID') === 0) {
            return; // Init noch nicht fertig, wird danach gesendet
        }

        $now = time();
        $wait = $this->ReadAttributeInteger('LastSendTime') + self::MIN_SEND_INTERVAL - $now;
        if ($wait > 0) {
            $this->SetTimerInterval('TimerSend', $wait * 1000);
            return;
        }

        $reason = $this->GetBuffer('SendReason');
        $note = $reason !== '' ? $reason . ', ' . $desired['note'] : $desired['note'];
        // Phasenwechsel? (die Easee pausiert dabei selbst kurz und schaltet dann um)
        $fromPhases = $this->ReadAttributeInteger('LastPhases');
        $phaseSwitch = !$desired['pause'] && is_array($last) && empty($last['pause']) && in_array($fromPhases, [1, 3], true)
            && (int) $desired['phases'] !== $fromPhases;
        if ($phaseSwitch) {
            $note = 'Phasenwechsel ' . $fromPhases . ' → ' . $desired['phases'] . ', ' . $note;
        }
        if ($this->SendCurrents($desired['currents'], $note)) {
            if ($phaseSwitch) {
                $this->ReportPhaseSwitch($fromPhases, (int) $desired['phases'], $desired['currents']);
            }
            $this->SetBuffer('SendReason', '');
            if (!$desired['pause']) {
                if ($desired['phases'] !== $this->ReadAttributeInteger('LastPhases')) {
                    $this->WriteAttributeInteger('LastPhaseSwitch', $now);
                }
                $this->WriteAttributeInteger('LastPhases', (int) $desired['phases']);
            }
            if (!$same) {
                // neue Vorgabe -> Wächter-Zähler zurücksetzen
                $this->SetBuffer('GuardCount', '0');
                $this->SetBuffer('GuardWarned', '');
            }
            $this->WriteAttributeString('LastSent', json_encode($desired));
            $this->WriteAttributeBoolean('ForceSend', false);
            $this->SetIfChanged('SetCurrent', (float) $desired['amps']);
            $this->SetIfChanged('SetPhases', $desired['pause'] ? 0 : (int) $desired['phases']);
            $ttl = $this->ReadPropertyInteger('TimeToLive');
            // Bei Ablaufzeit rechtzeitig vorher erneut senden
            $this->SetTimerInterval('TimerKeepAlive', $ttl > 0 ? max(60, $ttl * 60 - 60) * 1000 : 0);
            if (!$desired['pause'] && !(bool) $this->GetValue('Charging') && (bool) $this->GetValue('VehicleConnected')) {
                // Laden soll beginnen: kurz danach prüfen, ob die Wallbox wirklich lädt (z. B. nicht „angehalten“ ist)
                $this->SetTimerInterval('TimerVerify', (self::GUARD_SETTLE + 5) * 1000);
                // Ist die Wallbox „angehalten“, sofort fortsetzen – nicht erst auf die Kontrollabfrage warten
                if ((int) $this->GetBuffer('ResumeCount') < 3) {
                    $this->SetBuffer('ResumeTime', '0');
                }
                $this->Guard();
            }
        } else {
            $this->SetTimerInterval('TimerSend', max(30, (int) $this->GetBuffer('RetryAfter')) * 1000);
        }
    }

    /**
     * Fahrzeug angesteckt oder Wallbox eingeschaltet: Easee hat den dynamischen Strom dann evtl. auf den
     * Standardwert zurückgesetzt (laut Easee-Doku beim Abstecken bzw. Neustart). Deshalb die aktuelle Vorgabe
     * sofort erneut senden und kurz danach per Abfrage kontrollieren.
     */
    private function AfterWakeUp(string $why): void
    {
        if (!$this->ReadAttributeBoolean('TargetKnown') || $this->ReadAttributeInteger('CircuitID') === 0) {
            return;
        }
        $this->SendDebug('Wächter', $why . ' → Vorgabe wird erneut gesendet', 0);
        $this->SetBuffer('SendReason', $why);
        $this->WriteAttributeBoolean('ForceSend', true);
        // Pause hat Vorrang: nicht auf den Mindestabstand warten
        $desired = json_decode($this->ReadAttributeString('Desired'), true);
        if (is_array($desired) && !empty($desired['pause'])) {
            $this->WriteAttributeInteger('LastSendTime', 0);
        }
        $this->SetBuffer('GuardCount', '0');
        $this->SetBuffer('GuardWarned', '');
        $this->SetTimerInterval('TimerSend', 1000);
        $this->SetTimerInterval('TimerVerify', 15 * 1000);
    }

    /**
     * Wächter (Soll-Ist-Abgleich): Lädt die Wallbox, obwohl Pause vorgegeben ist, oder meldet sie einen anderen
     * dynamischen Strom als zuletzt gesendet, wird die Vorgabe erneut gesendet.
     * Schutz gegen Endlosschleifen: höchstens alle 20 s, nach 3 erfolglosen Versuchen nur noch jede Minute
     * (bei Pause) bzw. gar nicht mehr (bei reiner Stromabweichung) – mit Meldung im Log.
     */
    private function Guard(): void
    {
        if (!$this->ReadAttributeBoolean('TargetKnown') || $this->ReadAttributeInteger('CircuitID') === 0) {
            return;
        }
        $last = json_decode($this->ReadAttributeString('LastSent'), true);
        if (!is_array($last) || !isset($last['currents'])) {
            return;
        }
        $now = time();
        if ($this->CheckHalted($last, $now)) {
            return;
        }
        if ($now - $this->ReadAttributeInteger('LastSendTime') < self::GUARD_SETTLE) {
            return; // Wallbox braucht nach dem Senden ein paar Sekunden
        }

        $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
        $power = (float) $this->GetValue('Power');
        $charging = (int) $this->GetValue('Status') === 3 || $power > ($kw ? 0.1 : 100);
        $pauseWanted = !empty($last['pause']);

        $problem = '';
        if ($pauseWanted && $charging) {
            $problem = 'Wallbox lädt, obwohl Pause vorgegeben ist';
        } else {
            $amps = json_decode($this->GetBuffer('DynAmps'), true) ?: [];
            foreach ([111, 112, 113] as $i => $obs) {
                if (isset($amps[$obs]) && abs((float) $amps[$obs] - (float) $last['currents'][$i]) > 0.6) {
                    $problem = sprintf(
                        'Wallbox meldet %.1f / %.1f / %.1f A statt %.1f / %.1f / %.1f A',
                        $amps[111] ?? -1,
                        $amps[112] ?? -1,
                        $amps[113] ?? -1,
                        $last['currents'][0],
                        $last['currents'][1],
                        $last['currents'][2]
                    );
                    break;
                }
            }
        }
        if ($problem === '') {
            $this->SetBuffer('GuardCount', '0');
            return;
        }

        $count = (int) $this->GetBuffer('GuardCount');
        $wait = $count >= 3 ? self::GUARD_SLOW : self::GUARD_INTERVAL;
        if ($now - (int) $this->GetBuffer('GuardTime') < $wait) {
            return;
        }
        if ($count >= 3 && !$pauseWanted) {
            // reine Stromabweichung, die sich nicht korrigieren lässt (z. B. Lastmanagement der Site) -> nicht weiter kämpfen
            if ($this->GetBuffer('GuardWarned') !== '1') {
                $this->SetBuffer('GuardWarned', '1');
                $this->LogMessage('Easee Wallbox: ' . $problem . ' – nach 3 Korrekturen unverändert, Wächter wartet auf die nächste neue Vorgabe', KL_WARNING);
            }
            return;
        }
        if ($count === 3 && $this->GetBuffer('GuardWarned') !== '1') {
            $this->SetBuffer('GuardWarned', '1');
            $this->LogMessage('Easee Wallbox: ' . $problem . ' – Pause wird weiter jede Minute gesendet', KL_WARNING);
        }

        $this->SetBuffer('GuardTime', (string) $now);
        $this->SetBuffer('GuardCount', (string) ($count + 1));
        $this->SendDebug('Wächter', $problem . ' → sende Vorgabe erneut (Versuch ' . ($count + 1) . ')', 0);
        if ($this->GetBuffer('SendReason') === '') {
            $this->SetBuffer('SendReason', 'Wächter: ' . ($pauseWanted && $charging ? 'lädt trotz Pause' : 'Strom weicht ab'));
        }
        $this->WriteAttributeBoolean('ForceSend', true);
        if ($pauseWanted) {
            $this->WriteAttributeInteger('LastSendTime', 0);
        }
        $this->SetTimerInterval('TimerSend', 1000);
        $this->SetTimerInterval('TimerVerify', (self::GUARD_SETTLE + 5) * 1000);
    }

    /**
     * Wallbox „angehalten“ (Easee-App: Pause; Observation 48 Dynamic Charger Current = 0, Grund 52):
     * Dann hilft keine Stromvorgabe am Circuit – die Wallbox muss fortgesetzt werden.
     * Nur wenn Laden gewollt ist (Vorgabe > 0, Laden erlaubt) und ein Fahrzeug angesteckt ist.
     * Rückgabe true = Wallbox ist angehalten (dann keine weiteren Prüfungen).
     */
    private function CheckHalted(array $last, int $now): bool
    {
        if (!empty($last['pause']) || !(bool) $this->GetValue('VehicleConnected') || (bool) $this->GetValue('Charging')) {
            $this->SetBuffer('ResumeCount', '0');
            return false;
        }
        $limit = (float) $this->GetValue('ChargerCurrent');
        $halted = (int) $this->GetValue('ReasonNoCurrent') === 52
            || ($this->GetBuffer('ChargerCurrentKnown') === '1' && $limit < (float) $this->ReadPropertyInteger('MinCurrent'));
        if (!$halted) {
            $this->SetBuffer('ResumeCount', '0');
            return false;
        }
        $count = (int) $this->GetBuffer('ResumeCount');
        $wait = $count >= 3 ? self::GUARD_SLOW * 5 : self::GUARD_INTERVAL;
        if ($now - (int) $this->GetBuffer('ResumeTime') < $wait) {
            return true;
        }
        if ($count === 3) {
            $this->LogMessage('Easee Wallbox: Wallbox bleibt „angehalten“, obwohl geladen werden soll – Fortsetzen nur noch alle 5 Minuten', KL_WARNING);
        }
        $this->SetBuffer('ResumeTime', (string) $now);
        $this->SetBuffer('ResumeCount', (string) ($count + 1));
        $this->SendDebug('Wächter', sprintf('Wallbox ist angehalten (Ladestrom-Freigabe %.1f A, Grund %d) → fortsetzen (Versuch %d)', $limit, (int) $this->GetValue('ReasonNoCurrent'), $count + 1), 0);
        $this->SetTimerInterval('TimerResume', 1000);
        return true;
    }

    /** Wallbox fortsetzen: erst „resume_charging“ (wie „Fortsetzen“ in der App), ab dem 2. Versuch Ladestrom-Freigabe direkt setzen. */
    private function ResumeCharger(): void
    {
        $id = $this->ChargerID();
        if ($id === '') {
            return;
        }
        if ((int) $this->GetBuffer('ResumeCount') <= 1) {
            $ok = $this->Command('POST', '/api/chargers/' . $id . '/commands/resume_charging', null, 'Laden fortsetzen (Wallbox war angehalten)');
        } else {
            $amps = $this->ReadPropertyInteger('MaxCurrent');
            $ok = $this->Command('POST', '/api/chargers/' . $id . '/commands/set_dynamic_charger_current', ['amps' => $amps, 'minutes' => 0], 'Ladestrom-Freigabe der Wallbox auf ' . $amps . ' A (Wallbox war angehalten)');
        }
        $this->SendDebug('Wächter', 'Fortsetzen ' . ($ok ? 'gesendet' : 'fehlgeschlagen'), 0);
        // danach kontrollieren
        $this->SetTimerInterval('TimerVerify', (self::GUARD_SETTLE + 5) * 1000);
    }

    /** Phasenwechsel melden: Debug immer, Symcon-Log nur mit Häkchen „Phasenwechsel im Symcon-Log melden“. */
    private function ReportPhaseSwitch(int $from, int $to, array $currents): void
    {
        $text = sprintf('Phasenwechsel %d → %d: %.1f / %.1f / %.1f A', $from, $to, $currents[0], $currents[1], $currents[2]);
        $this->SendDebug('Phasen', $text, 0);
        if ($this->ReadPropertyBoolean('LogPhaseSwitch')) {
            $kw = $this->ReadPropertyInteger('PowerUnit') === 0;
            $target = (float) $this->GetValue('TargetPower');
            $this->LogMessage(sprintf(
                'Easee Wallbox: Phasenwechsel %d → %d (Vorgabe %s)',
                $from,
                $to,
                $kw ? number_format($target, 2, ',', '') . ' kW' : number_format($target, 0, ',', '.') . ' W'
            ), KL_MESSAGE);
        }
    }

    private function SendCurrents(array $currents, string $note): bool
    {
        $body = [
            'phase1'     => round((float) $currents[0], 1),
            'phase2'     => round((float) $currents[1], 1),
            'phase3'     => round((float) $currents[2], 1),
            'timeToLive' => $this->ReadPropertyInteger('TimeToLive')
        ];
        $path = '/api/sites/' . $this->ReadAttributeInteger('SiteID') . '/circuits/' . $this->ReadAttributeInteger('CircuitID') . '/dynamicCurrent';
        $this->WriteAttributeInteger('LastSendTime', time());
        $res = $this->Api('POST', $path, $body);
        $text = sprintf('%s  %.1f / %.1f / %.1f A  (%s)', date('H:i:s'), $body['phase1'], $body['phase2'], $body['phase3'], $note);
        if ($res['ok']) {
            $this->SetValue('LastCommand', $text);
            $this->SendDebug('Senden', $text, 0);
            // Easee nimmt Befehle an, bevor die Wallbox sie umsetzt -> kurz danach per Abfrage kontrollieren
            $this->SetTimerInterval('TimerVerify', (self::GUARD_SETTLE + 5) * 1000);
            return true;
        }
        $this->SetBuffer('RetryAfter', (string) ((int) ($res['retryAfter'] ?? 30)));
        $this->SetValue('LastCommand', $text . ' – FEHLER: ' . ($res['error'] ?? ''));
        return false;
    }

    // ---------------------------------------------------------------------
    // Kommunikation mit Easee Cloud
    // ---------------------------------------------------------------------

    private function Command(string $method, string $path, ?array $body, string $label): bool
    {
        $res = $this->Api($method, $path, $body);
        $text = date('H:i:s') . '  ' . $label . ($res['ok'] ? '' : ' – FEHLER: ' . ($res['error'] ?? ''));
        $this->SetValue('LastCommand', $text);
        return (bool) $res['ok'];
    }

    private function Api(string $method, string $path, ?array $body = null): array
    {
        if (!$this->IsActive() && $this->GetBuffer('AllowWhileOff') !== '1') {
            return ['ok' => false, 'error' => 'Modul ausgeschaltet'];
        }
        return $this->ParentCall(['Function' => 'Request', 'Method' => $method, 'Path' => $path, 'Body' => $body]);
    }

    private function ParentCall(array $data): array
    {
        if ($this->ParentID() === 0) {
            return ['ok' => false, 'error' => 'Keine Easee-Cloud-Instanz verbunden'];
        }
        $data['DataID'] = self::TO_PARENT;
        try {
            $result = $this->SendDataToParent(json_encode($data));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $decoded = json_decode((string) $result, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Keine Antwort von Easee Cloud'];
    }

    private function ParentID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function ChargerID(): string
    {
        return strtoupper(trim($this->ReadPropertyString('ChargerID')));
    }
}
