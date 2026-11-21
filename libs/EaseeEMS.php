<?php

declare(strict_types=1);

/**
 * Schnittstelle für Energiemanager (Build 18): Messwerte nach dem Vertrag „charger“ 1.13
 * des NRG-Stack (https://github.com/DG65/NRGChargerHub/blob/main/docs/EIGENES_WALLBOX_MODUL.md).
 *
 * Nur Messen: keine Steuer-IDs, kein Rückfall (failsafeSec) – ein Energiemanager liest nur mit.
 *
 * Alles zu dieser Schnittstelle steht in dieser Datei. Zum Entfernen:
 * diese Datei, `use EaseeEMS;`, die mit „EMS:“ markierten Zeilen in module.php,
 * die Funktion GetFunctions() und das Panel „EmsPanel“ in form.json löschen.
 */
trait EaseeEMS
{
    private function EmsCreate(): void
    {
        $this->RegisterPropertyBoolean('EmsInterface', false);
        $this->RegisterAttributeInteger('EmsLastSeen', 0);
    }

    private function EmsEnabled(): bool
    {
        return $this->ReadPropertyBoolean('EmsInterface');
    }

    /** Leistung in Watt: eigene Variable nur, wenn die Schnittstelle an ist und das Modul in kW anzeigt. */
    private function EmsVariables(): void
    {
        $needed = $this->EmsEnabled() && $this->ReadPropertyInteger('PowerUnit') === 0;
        $this->MaintainVariable('PowerW', 'Ladeleistung (W)', VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' W',
            'DIGITS'       => 0
        ], 16, $needed);
        if ($needed) {
            $this->EmsPower((float) $this->GetValue('Power')); // Power steht hier in kW
        }
    }

    /** Observation 120 (Leistung in kW) zusätzlich in Watt ablegen. */
    private function EmsPower(float $kw): void
    {
        if (@$this->GetIDForIdent('PowerW') > 0) {
            $this->SetIfChanged('PowerW', round($kw * 1000, 0));
        }
    }

    /** Erfolgreicher Kontakt zur Cloud – zählt nicht, solange die Wallbox als offline gemeldet ist. */
    private function EmsSeen(): void
    {
        $vid = @$this->GetIDForIdent('Online');
        if ($vid > 0 && IPS_GetVariable($vid)['VariableUpdated'] > 0 && !$this->GetValue('Online')) {
            return;
        }
        $this->WriteAttributeInteger('EmsLastSeen', time());
    }

    /** Ein Energiemanager wertet Werte älter als 10 Minuten als unbekannt – dann spätestens alle 5 Minuten abfragen. */
    private function EmsPollCap(int $seconds): int
    {
        return $this->EmsEnabled() ? min($seconds, 300) : $seconds;
    }

    /** Eintrag für <PRÄFIX>_GetFunctions; leere Liste, wenn die Schnittstelle aus ist oder die Seriennummer fehlt. */
    private function EmsFunctions(): array
    {
        $serial = strtoupper(trim($this->ReadPropertyString('ChargerID')));
        if (!$this->EmsEnabled() || $serial === '') {
            return [];
        }
        $id = function (string $ident): int {
            $vid = @$this->GetIDForIdent($ident);
            return $vid > 0 ? (int) $vid : 0;
        };
        $entry = [
            'contractVersion'   => '1.13',
            'function'          => 'charger',
            'contractType'      => 'charger',
            'instanceID'        => $this->InstanceID,
            'label'             => IPS_GetName($this->InstanceID),
            'powerID'           => $this->ReadPropertyInteger('PowerUnit') === 0 ? $id('PowerW') : $id('Power'),
            'energyImportID'    => $id('LifetimeEnergy'),
            'measured'          => true,
            // Das Modul regelt die Wallbox selbst (Ziel-Ladeleistung) – der Energiemanager liest nur mit
            'chargeEnableID'    => 0,
            'currentLimitID'    => 0,
            'managedBy'         => 'other',
            'externallyManaged' => true,
            'lastSeenAt'        => $this->ReadAttributeInteger('EmsLastSeen'),
            'plugStateID'       => $id('VehicleConnected'),
            'stateID'           => $id('Status'),
            'deviceSerial'      => $serial,
            'deviceHost'        => '',
            'manufacturer'      => 'easee',
            'duplicateOf'       => null
        ];
        $phases = (int) $this->GetValue('ActivePhases');
        if (in_array($phases, [1, 3], true)) {
            $entry['phases'] = $phases;
        }
        return [$entry];
    }
}
