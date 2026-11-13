<?php

declare(strict_types=1);

/**
 * Easee Cloud (Splitter)
 * - Anmeldung, Token-Erneuerung (Refresh-Token ist nur einmal gültig, sonst neue Anmeldung)
 * - REST-Anfragen für die Wallbox-Instanzen inkl. Schutz vor dem API-Limit
 * - Push der Wallbox-Werte über SignalR (WebSocket Client als übergeordnete Instanz)
 */
class EaseeCloud extends IPSModule
{
    private const API = 'https://api.easee.com';
    private const STREAM = 'https://streams.easee.com';
    private const WS_CLIENT = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const TO_CHILD = '{3E70FDCF-D9DC-496B-A244-7F57E810FC0B}';
    private const RS = "\x1e";

    /** Observations-Endpunkt: 100 Anfragen / 5 min – wir bleiben darunter */
    private const OBS_LIMIT = 90;
    private const OBS_WINDOW = 300;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyBoolean('UsePush', true);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('TokenUser', '');
        $this->RegisterAttributeInteger('BlockedUntil', 0);
        $this->RegisterAttributeInteger('Backoff', 0);
        $this->RegisterAttributeInteger('LoginBlockedUntil', 0);
        $this->RegisterAttributeInteger('LoginFailures', 0);
        $this->RegisterAttributeString('ChargerCache', '[]');
        $this->RegisterAttributeInteger('ChargerCacheTime', 0);
        $this->RegisterAttributeInteger('PushBlockedUntil', 0);
        $this->RegisterAttributeInteger('PushBackoff', 0);
        $this->RegisterAttributeInteger('PushMode', 0);   // 0 = direkt (ohne Aushandlung), 1 = mit Aushandlung
        // Überwachung der Push-Verbindung (Meldung im Log, wenn sie länger ausfällt)
        $this->RegisterAttributeInteger('PushDownSince', 0);   // 0 = verbunden bzw. noch nie versucht
        $this->RegisterAttributeInteger('PushFailures', 0);    // fehlgeschlagene Versuche seit der letzten Verbindung
        $this->RegisterAttributeBoolean('PushAlarm', false);   // Fehlermeldung für diesen Ausfall schon geschrieben

        $this->RegisterTimer('TokenTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TokenTimer", 0);');
        $this->RegisterTimer('PushConnect', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "PushConnect", 0);');
        $this->RegisterTimer('PushHandshake', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "PushHandshake", 0);');
        $this->RegisterTimer('PushWatchdog', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "PushWatchdog", 0);');
        $this->RegisterTimer('PushLost', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "PushLost", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('LoggedIn', 'Angemeldet', $this->BoolDisplay('Nein', 'Ja'), 1);
        $this->RegisterVariableBoolean('PushConnected', 'Push verbunden', $this->BoolDisplay('Getrennt', 'Verbunden'), 2);
        $this->RegisterVariableInteger('Requests5Min', 'Abfragen Status (5 min)', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' / 100', 'DIGITS' => 0], 3);
        $this->RegisterVariableInteger('LastRequest', 'Letzte Anfrage', '~UnixTimestamp', 4);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 5);

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->RegisterParent();

        // Neuer Benutzer -> alte Token verwerfen
        $user = trim($this->ReadPropertyString('Username'));
        if ($user !== $this->ReadAttributeString('TokenUser')) {
            $this->ClearTokens();
            $this->WriteAttributeString('TokenUser', $user);
            $this->WriteAttributeInteger('LoginBlockedUntil', 0);
            $this->WriteAttributeInteger('LoginFailures', 0);
        }

        $this->SetPushConnected(false);
        $this->SetTimerInterval('PushWatchdog', 0);
        $this->SetTimerInterval('PushHandshake', 0);

        if ($user === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('TokenTimer', 0);
            $this->SetTimerInterval('PushConnect', 0);
            $this->DeactivateParent();
            $this->ResetPushMonitor();
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('TokenTimer', 300 * 1000);
        if ($this->ReadPropertyBoolean('UsePush')) {
            $this->SetTimerInterval('PushConnect', 2000);
        } else {
            $this->SetTimerInterval('PushConnect', 0);
            $this->DeactivateParent();
            $this->ResetPushMonitor();
        }
        // Anmeldung gleich prüfen, damit der Status stimmt
        if ($this->GetToken() !== '' && $this->GetStatus() != 102) {
            $this->SetStatus(102);
        }
    }

    public function GetCompatibleParents(): string
    {
        // Jede Cloud-Instanz braucht einen eigenen WebSocket Client (das Modul setzt dessen Adresse selbst)
        return json_encode(['type' => 'require', 'moduleIDs' => [self::WS_CLIENT]]);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->RegisterParent();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID == $this->GetParentID()) {
                    if ((int) $Data[0] == IS_ACTIVE) {
                        if ($this->GetBuffer('HandshakePending') === '1') {
                            $this->SetTimerInterval('PushHandshake', 500);
                        }
                    } elseif ($this->GetBuffer('PushConnected') === '1') {
                        // WebSocket Client ist ausgefallen (Server hat getrennt, Netzwerk weg …) -> gleich neu verbinden.
                        // Nicht direkt hier, sondern per Timer (hier läuft gerade die Statusmeldung des WebSocket Client).
                        $this->SetBuffer('PushLostReason', 'WebSocket Client meldet Status ' . (int) $Data[0]);
                        $this->SetPushConnected(false);
                        $this->SetTimerInterval('PushLost', 1000);
                    }
                }
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'TokenTimer':
                $this->GetToken();
                $this->UpdateRequestCounter();
                $this->SupervisePush();
                break;
            case 'PushLost':
                $this->SetTimerInterval('PushLost', 0);
                $this->SetError('Push: Verbindung verloren (' . $this->GetBuffer('PushLostReason') . ') – neuer Versuch');
                $this->SchedulePushRetry();
                break;
            case 'PushConnect':
                $this->SetTimerInterval('PushConnect', 0);
                $this->PushConnect();
                break;
            case 'PushHandshake':
                $this->SetTimerInterval('PushHandshake', 0);
                $this->PushHandshake();
                break;
            case 'PushWatchdog':
                $this->PushWatchdog();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen
    // ---------------------------------------------------------------------

    /** Prüft Anmeldung und listet die Wallboxen des Kontos. */
    public function TestConnection(): string
    {
        $this->WriteAttributeInteger('LoginBlockedUntil', 0);
        if ($this->GetToken() === '') {
            $text = 'Anmeldung fehlgeschlagen: ' . $this->GetValue('LastError');
        } else {
            $chargers = $this->GetChargers(true);
            if (count($chargers) === 0) {
                $text = 'Anmeldung OK, aber keine Wallbox gefunden (oder Abfrage-Limit erreicht).';
            } else {
                $lines = [];
                foreach ($chargers as $c) {
                    $lines[] = $c['id'] . ' – ' . $c['name'];
                }
                $text = 'Anmeldung OK. Gefundene Wallboxen: ' . implode(', ', $lines);
            }
        }
        // Ergebnis im Formular anzeigen statt als Popup
        $this->UpdateFormField('TestResult', 'caption', $text);
        return $text;
    }

    /** Baut die Push-Verbindung neu auf. */
    public function ReconnectPush(): bool
    {
        $this->WriteAttributeInteger('PushBlockedUntil', 0);
        $this->WriteAttributeInteger('PushBackoff', 0);
        $this->WriteAttributeInteger('PushMode', 0);
        $this->SetBuffer('HandshakePending', '0');
        $this->SetTimerInterval('PushWatchdog', 0);
        $this->SetPushConnected(false);
        $this->DeactivateParent();
        $this->SetTimerInterval('PushConnect', 2000);
        return true;
    }

    // ---------------------------------------------------------------------
    // Datenfluss zu den Wallbox-Instanzen
    // ---------------------------------------------------------------------

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return json_encode(['ok' => false, 'error' => 'Ungültige Daten']);
        }
        switch ($data['Function'] ?? '') {
            case 'Request':
                $result = $this->ApiRequest(
                    (string) ($data['Method'] ?? 'GET'),
                    (string) ($data['Path'] ?? ''),
                    isset($data['Body']) && is_array($data['Body']) ? $data['Body'] : null
                );
                return json_encode($result);
            case 'Subscribe':
                $this->AddSubscription((string) ($data['ChargerID'] ?? ''));
                return json_encode(['ok' => true, 'push' => $this->GetBuffer('PushConnected') === '1']);
            case 'GetChargers':
                return json_encode(['ok' => true, 'data' => $this->GetChargers(false)]);
            case 'Status':
                return json_encode(['ok' => true, 'push' => $this->GetBuffer('PushConnected') === '1']);
        }
        return json_encode(['ok' => false, 'error' => 'Unbekannte Funktion']);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }
        $chunk = (string) $data['Buffer'];
        $buffer = $this->GetBuffer('RxBuffer') . $chunk;
        $this->SetBuffer('LastPushRx', (string) time());

        $parts = explode(self::RS, $buffer);
        $rest = array_pop($parts);
        $this->SetBuffer('RxBuffer', strlen($rest) > 65536 ? '' : $rest);

        foreach ($parts as $msg) {
            if ($msg === '') {
                continue;
            }
            $this->HandleSignalRMessage($msg);
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // REST
    // ---------------------------------------------------------------------

    private function ApiRequest(string $method, string $path, ?array $body, bool $retry = true): array
    {
        $now = time();
        $blocked = $this->ReadAttributeInteger('BlockedUntil');
        if ($blocked > $now) {
            return ['ok' => false, 'status' => 429, 'error' => 'API-Limit – gesperrt bis ' . date('H:i:s', $blocked), 'retryAfter' => $blocked - $now];
        }

        $isObservation = strpos($path, '/state/') === 0;
        if ($isObservation && !$this->ObservationSlotFree()) {
            return ['ok' => false, 'status' => 429, 'error' => 'Eigener Schutz: Abfrage-Limit Status fast erreicht', 'retryAfter' => 30];
        }

        $token = $this->GetToken();
        if ($token === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'Nicht angemeldet'];
        }

        $res = $this->Http($method, self::API . $path, $body, $token);
        $this->SetValue('LastRequest', $now);
        if ($isObservation) {
            $this->LogObservationRequest();
        }

        if ($res['code'] === 401 && $retry) {
            // Token abgelaufen/ungültig -> einmal erneuern (Refresh, sonst Anmeldung) und wiederholen
            $this->WriteAttributeInteger('TokenExpires', 0);
            return $this->ApiRequest($method, $path, $body, false);
        }
        if ($this->IsBlackhole($res)) {
            $this->WriteAttributeInteger('BlockedUntil', $now + 1800);
            $this->SetError('Easee blockiert diese IP-Adresse (zu viele Anfragen). Pause 30 min.');
            $this->SetStatus(203);
            return ['ok' => false, 'status' => $res['code'], 'error' => 'IP gesperrt', 'retryAfter' => 1800];
        }
        if ($res['code'] === 429) {
            $retryAfter = (int) ($res['headers']['retry-after'] ?? 0);
            if ($retryAfter <= 0) {
                $steps = [60, 120, 300, 600, 900];
                $idx = min($this->ReadAttributeInteger('Backoff'), count($steps) - 1);
                $retryAfter = $steps[$idx];
                $this->WriteAttributeInteger('Backoff', $idx + 1);
            }
            $this->WriteAttributeInteger('BlockedUntil', $now + $retryAfter);
            $this->SetError('API-Limit erreicht (429), Pause ' . $retryAfter . ' s');
            $this->SetStatus(202);
            return ['ok' => false, 'status' => 429, 'error' => 'API-Limit erreicht', 'retryAfter' => $retryAfter];
        }
        if ($res['code'] >= 200 && $res['code'] < 300) {
            $this->WriteAttributeInteger('Backoff', 0);
            if ($this->GetStatus() != 102) {
                $this->SetStatus(102);
            }
            $decoded = json_decode($res['body'], true);
            return ['ok' => true, 'status' => $res['code'], 'data' => $decoded ?? $res['body']];
        }
        if ($res['code'] === 0) {
            $this->SetError('Easee-Cloud nicht erreichbar: ' . $res['error']);
            $this->SetStatus(204);
            return ['ok' => false, 'status' => 0, 'error' => $res['error'], 'retryAfter' => 60];
        }
        $msg = 'HTTP ' . $res['code'] . ' bei ' . $method . ' ' . $path . ': ' . substr($res['body'], 0, 200);
        $this->SetError($msg);
        return ['ok' => false, 'status' => $res['code'], 'error' => $msg];
    }

    private function GetChargers(bool $force): array
    {
        $age = time() - $this->ReadAttributeInteger('ChargerCacheTime');
        $cache = json_decode($this->ReadAttributeString('ChargerCache'), true) ?: [];
        // /api/chargers ist auf 2 Anfragen pro Minute begrenzt
        if ((!$force && $age < 600) || $age < 40) {
            return $cache;
        }
        $res = $this->ApiRequest('GET', '/api/chargers', null);
        if (!$res['ok'] || !is_array($res['data'])) {
            return $cache;
        }
        $list = [];
        foreach ($res['data'] as $c) {
            if (isset($c['id'])) {
                $list[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? '')];
            }
        }
        $this->WriteAttributeString('ChargerCache', json_encode($list));
        $this->WriteAttributeInteger('ChargerCacheTime', time());
        return $list;
    }

    private function ObservationSlotFree(): bool
    {
        return count($this->GetObservationLog()) < self::OBS_LIMIT;
    }

    private function LogObservationRequest(): void
    {
        $log = $this->GetObservationLog();
        $log[] = time();
        $this->SetBuffer('ObsLog', json_encode($log));
        $this->SetValue('Requests5Min', count($log));
    }

    private function GetObservationLog(): array
    {
        $log = json_decode($this->GetBuffer('ObsLog'), true) ?: [];
        $limit = time() - self::OBS_WINDOW;
        return array_values(array_filter($log, function ($t) use ($limit) {
            return $t > $limit;
        }));
    }

    private function UpdateRequestCounter(): void
    {
        $log = $this->GetObservationLog();
        $this->SetBuffer('ObsLog', json_encode($log));
        $this->SetValue('Requests5Min', count($log));
    }

    // ---------------------------------------------------------------------
    // Anmeldung / Token
    // ---------------------------------------------------------------------

    private function GetToken(): string
    {
        $now = time();
        $token = $this->ReadAttributeString('AccessToken');
        if ($token !== '' && $this->ReadAttributeInteger('TokenExpires') - 300 > $now) {
            return $token;
        }

        // 1. Versuch: Refresh-Token (nur einmal gültig!)
        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh !== '' && $token !== '') {
            $res = $this->Http('POST', self::API . '/api/accounts/refresh_token', ['accessToken' => $token, 'refreshToken' => $refresh], '');
            if ($res['code'] >= 200 && $res['code'] < 300 && $this->StoreTokens($res['body'])) {
                $this->SendDebug('Token', 'Access-Token erneuert', 0);
                return $this->ReadAttributeString('AccessToken');
            }
            $this->SendDebug('Token', 'Erneuerung fehlgeschlagen (HTTP ' . $res['code'] . '), neue Anmeldung', 0);
            $this->ClearTokens();
        }

        // 2. Versuch: Anmeldung mit Benutzer/Passwort
        if ($this->ReadAttributeInteger('LoginBlockedUntil') > $now) {
            return '';
        }
        $user = trim($this->ReadPropertyString('Username'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') {
            return '';
        }
        $res = $this->Http('POST', self::API . '/api/accounts/login', ['userName' => $user, 'password' => $pass], '');
        if ($res['code'] >= 200 && $res['code'] < 300 && $this->StoreTokens($res['body'])) {
            $this->WriteAttributeInteger('LoginFailures', 0);
            $this->SendDebug('Token', 'Anmeldung erfolgreich', 0);
            if ($this->GetStatus() != 102) {
                $this->SetStatus(102);
            }
            return $this->ReadAttributeString('AccessToken');
        }

        // Fehlgeschlagen: nicht dauernd wiederholen, sonst sperrt Easee
        $fails = $this->ReadAttributeInteger('LoginFailures') + 1;
        $this->WriteAttributeInteger('LoginFailures', $fails);
        $wait = min(3600, 120 * $fails);
        $this->WriteAttributeInteger('LoginBlockedUntil', $now + $wait);
        $this->SetValue('LoggedIn', false);
        if ($res['code'] === 0) {
            $this->SetError('Anmeldung: Cloud nicht erreichbar (' . $res['error'] . ')');
            $this->SetStatus(204);
        } elseif ($res['code'] === 429 || $this->IsBlackhole($res)) {
            $this->SetError('Anmeldung: zu viele Anfragen, nächster Versuch in ' . $wait . ' s');
            $this->SetStatus(202);
        } else {
            $this->SetError('Anmeldung fehlgeschlagen (HTTP ' . $res['code'] . '). Benutzer/Passwort prüfen. Nächster Versuch in ' . $wait . ' s');
            $this->SetStatus(201);
        }
        return '';
    }

    private function StoreTokens(string $body): bool
    {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['accessToken'])) {
            return false;
        }
        $expiresIn = (int) ($data['expiresIn'] ?? 3600);
        $this->WriteAttributeString('AccessToken', (string) $data['accessToken']);
        $this->WriteAttributeString('RefreshToken', (string) ($data['refreshToken'] ?? ''));
        $this->WriteAttributeInteger('TokenExpires', time() + max(600, $expiresIn));
        $this->SetValue('LoggedIn', true);
        return true;
    }

    private function ClearTokens(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpires', 0);
    }

    // ---------------------------------------------------------------------
    // Push (SignalR über WebSocket Client)
    // ---------------------------------------------------------------------

    private function PushConnect(): void
    {
        if (!$this->ReadPropertyBoolean('UsePush')) {
            return;
        }
        $now = time();
        if ($this->GetBuffer('PushConnected') !== '1' && $this->ReadAttributeInteger('PushDownSince') === 0) {
            $this->WriteAttributeInteger('PushDownSince', $now);
        }
        if ($this->ReadAttributeInteger('PushBlockedUntil') > $now) {
            $this->SetTimerInterval('PushConnect', ($this->ReadAttributeInteger('PushBlockedUntil') - $now + 5) * 1000);
            return;
        }
        $parentID = $this->GetParentID();
        if ($parentID === 0) {
            $this->SetError('Push: keine übergeordnete Instanz (WebSocket Client) verbunden');
            $this->CheckPushAlarm();
            return;
        }
        $token = $this->GetToken();
        if ($token === '') {
            $this->SchedulePushRetry();
            return;
        }

        $url = $this->BuildPushUrl($token);
        if ($url === '') {
            return; // Fehler/Neuversuch wurde bereits geplant
        }

        $config = json_decode(IPS_GetConfiguration($parentID), true);
        if (!is_array($config) || !array_key_exists('URL', $config)) {
            $this->SetError('Push: übergeordnete Instanz ist kein WebSocket Client');
            return;
        }
        $this->SetBuffer('RxBuffer', '');
        $this->SetBuffer('PushOk', '0');
        $this->SetBuffer('HandshakePending', '1');
        IPS_SetProperty($parentID, 'URL', $url);
        if (array_key_exists('VerifyCertificate', $config)) {
            IPS_SetProperty($parentID, 'VerifyCertificate', true);
        }
        if (array_key_exists('Active', $config)) {
            IPS_SetProperty($parentID, 'Active', true);
        }
        IPS_ApplyChanges($parentID);
        $this->SendDebug('Push', 'WebSocket wird verbunden (' . ($this->ReadAttributeInteger('PushMode') === 0 ? 'direkt' : 'mit Aushandlung') . ')', 0);

        // Falls keine Statusmeldung kommt: Handshake trotzdem versuchen
        $this->SetBuffer('HandshakeTries', '0');
        $this->SetTimerInterval('PushHandshake', 3000);
    }

    /**
     * Adresse für den WebSocket Client.
     * Modus 0 (Standard): direkt verbinden ohne Aushandlung (SignalR "skipNegotiation").
     *   Vorteil: Easee verteilt die Verbindungen auf mehrere Server; die Aushandlung setzt ein Cookie,
     *   das der Symcon-WebSocket-Client nicht mitschickt -> "No Connection with that ID".
     * Modus 1: mit Aushandlung (connectionToken), inkl. Umleitung auf einen anderen SignalR-Dienst.
     * Klappt ein Modus nicht, wird beim nächsten Versuch der andere genommen.
     */
    private function BuildPushUrl(string $token): string
    {
        $wsBase = 'wss://streams.easee.com/hubs/chargers';
        if ($this->ReadAttributeInteger('PushMode') === 0) {
            return $wsBase . '?access_token=' . rawurlencode($token);
        }

        $now = time();
        $res = $this->Http('POST', self::STREAM . '/hubs/chargers/negotiate?negotiateVersion=1', null, $token);
        if ($this->IsBlackhole($res) || ($res['code'] >= 300 && $res['code'] < 400)) {
            $this->WriteAttributeInteger('PushBlockedUntil', $now + 3600);
            $this->WriteAttributeInteger('PushMode', 0);
            $this->SetError('Push nicht verfügbar (Umleitung/Sperre). Weiter per Abfrage, neuer Versuch in 60 min.');
            $this->SetTimerInterval('PushConnect', 3605 * 1000);
            return '';
        }
        $neg = json_decode($res['body'], true);
        if ($res['code'] !== 200 || !is_array($neg)) {
            $this->SetError('Push: Aushandlung fehlgeschlagen (HTTP ' . $res['code'] . ')');
            $this->SchedulePushRetry();
            return '';
        }
        $wsToken = $token;
        if (!empty($neg['url'])) {
            // Umleitung auf einen anderen SignalR-Dienst
            $wsBase = preg_replace('#^http#', 'ws', (string) $neg['url']);
            $wsToken = (string) ($neg['accessToken'] ?? $token);
            $res2 = $this->Http('POST', rtrim(preg_replace('#\?.*$#', '', (string) $neg['url']), '/') . '/negotiate?negotiateVersion=1', null, $wsToken);
            $neg2 = json_decode($res2['body'], true);
            if ($res2['code'] === 200 && is_array($neg2)) {
                $neg = $neg2;
            }
        }
        $connToken = (string) ($neg['connectionToken'] ?? ($neg['connectionId'] ?? ''));
        if ($connToken === '') {
            $this->SetError('Push: keine Verbindungskennung erhalten');
            $this->SchedulePushRetry();
            return '';
        }
        $sep = strpos($wsBase, '?') === false ? '?' : '&';
        return $wsBase . $sep . 'id=' . rawurlencode($connToken) . '&access_token=' . rawurlencode($wsToken);
    }

    private function PushHandshake(): void
    {
        if ($this->GetBuffer('HandshakePending') !== '1') {
            return;
        }
        $parentID = $this->GetParentID();
        if ($parentID === 0) {
            return;
        }
        if (IPS_GetInstance($parentID)['InstanceStatus'] != IS_ACTIVE) {
            $tries = (int) $this->GetBuffer('HandshakeTries') + 1;
            $this->SetBuffer('HandshakeTries', (string) $tries);
            if ($tries < 10) {
                $this->SetTimerInterval('PushHandshake', 3000);
            } else {
                $this->SetError('Push: WebSocket verbindet nicht');
                $this->SchedulePushRetry();
            }
            return;
        }
        $this->SendRaw('{"protocol":"json","version":1}' . self::RS);
        $this->SetBuffer('LastPushRx', (string) time());
        $this->SetTimerInterval('PushWatchdog', 15 * 1000);
    }

    private function HandleSignalRMessage(string $msg): void
    {
        // IPSModule: der I/O liefert die Bytes UTF-8-kodiert -> zurückwandeln; sonst Text direkt verwenden
        $data = json_decode(mb_convert_encoding($msg, 'ISO-8859-1', 'UTF-8'), true);
        if (!is_array($data)) {
            $data = json_decode($msg, true);
        }
        if (!is_array($data)) {
            $this->SendDebug('Push', 'Unbekannte Nachricht: ' . substr($msg, 0, 200), 0);
            return;
        }
        if ($this->GetBuffer('HandshakePending') === '1' && !isset($data['type'])) {
            if (isset($data['error'])) {
                $this->SetError('Push: Handshake abgelehnt: ' . $data['error']);
                $this->SchedulePushRetry();
                return;
            }
            // Handshake bestätigt -> Wallboxen abonnieren
            $this->SetBuffer('HandshakePending', '0');
            $this->SetBuffer('PushOk', '1');
            $this->WriteAttributeInteger('PushBackoff', 0);
            $this->SetPushConnected(true);
            foreach ($this->GetSubscriptions() as $chargerID) {
                $this->SendSubscribe($chargerID);
            }
            return;
        }
        $type = (int) ($data['type'] ?? 0);
        switch ($type) {
            case 1: // Invocation
                $target = (string) ($data['target'] ?? '');
                $args = $data['arguments'] ?? [];
                $item = $args[0] ?? null;
                if (!is_array($item)) {
                    return;
                }
                if ($target === 'ProductUpdate' || $target === 'ChargerUpdate') {
                    $this->SendDebug('Push', ($item['mid'] ?? '?') . ': ' . ($item['id'] ?? '?') . ' = ' . json_encode($item['value'] ?? null), 0);
                    $this->SendDataToChildren(json_encode([
                        'DataID'    => self::TO_CHILD,
                        'ChargerID' => (string) ($item['mid'] ?? ''),
                        'Type'      => 'Observation',
                        'ID'        => (int) ($item['id'] ?? 0),
                        'Value'     => $item['value'] ?? null,
                        'Time'      => (string) ($item['timestamp'] ?? '')
                    ]));
                } elseif ($target === 'CommandResponse') {
                    $this->SendDebug('Push', 'Befehlsantwort: ' . json_encode($item), 0);
                }
                break;
            case 3: // Completion (z. B. Antwort auf Abo)
                if (!empty($data['error'])) {
                    $this->SetError('Push: ' . $data['error']);
                }
                break;
            case 6: // Ping
                break;
            case 7: // Close
                $this->SendDebug('Push', 'Server hat die Verbindung geschlossen: ' . ($data['error'] ?? ''), 0);
                $this->SetPushConnected(false);
                $this->SchedulePushRetry();
                break;
        }
    }

    private function PushWatchdog(): void
    {
        if ($this->GetBuffer('PushConnected') !== '1' && $this->GetBuffer('HandshakePending') !== '1') {
            $this->SetTimerInterval('PushWatchdog', 0);
            return;
        }
        $last = (int) $this->GetBuffer('LastPushRx');
        if (time() - $last > 60) {
            $this->SendDebug('Push', 'Keine Daten seit 60 s – neu verbinden', 0);
            $this->SetPushConnected(false);
            $this->SchedulePushRetry();
            return;
        }
        if ($this->GetBuffer('PushConnected') === '1') {
            $this->SendRaw('{"type":6}' . self::RS);
        }
    }

    private function SchedulePushRetry(): void
    {
        if ($this->GetBuffer('PushOk') !== '1') {
            // Verbindung kam gar nicht zustande -> beim nächsten Mal die andere Verbindungsart probieren
            $this->WriteAttributeInteger('PushMode', $this->ReadAttributeInteger('PushMode') === 0 ? 1 : 0);
        }
        $this->SetBuffer('HandshakePending', '0');
        $this->SetTimerInterval('PushWatchdog', 0);
        $this->SetPushConnected(false);
        $this->DeactivateParent();
        $steps = [10, 30, 60, 120, 300];
        $idx = min($this->ReadAttributeInteger('PushBackoff'), count($steps) - 1);
        $this->WriteAttributeInteger('PushBackoff', $idx + 1);
        if ($this->ReadPropertyBoolean('UsePush')) {
            $this->SetTimerInterval('PushConnect', $steps[$idx] * 1000);
            if ($this->ReadAttributeInteger('PushDownSince') === 0) {
                $this->WriteAttributeInteger('PushDownSince', time());
            }
            $this->WriteAttributeInteger('PushFailures', $this->ReadAttributeInteger('PushFailures') + 1);
            $this->SendDebug('Push', sprintf('Neuer Versuch in %d s (Fehlversuch %d)', $steps[$idx], $this->ReadAttributeInteger('PushFailures')), 0);
            $this->CheckPushAlarm();
        }
    }

    /**
     * Fehlermeldung im Symcon-Log (rot), wenn die Push-Verbindung länger ausfällt:
     * ab 3 fehlgeschlagenen Versuchen in Folge oder nach 10 min ohne Verbindung – einmal pro Ausfall.
     * Die Werte kommen in der Zeit weiter per Abfrage, die Regelung läuft also weiter (nur langsamer).
     */
    private function CheckPushAlarm(): void
    {
        if (!$this->ReadPropertyBoolean('UsePush') || $this->ReadAttributeBoolean('PushAlarm') || $this->GetBuffer('PushConnected') === '1') {
            return;
        }
        $since = $this->ReadAttributeInteger('PushDownSince');
        $failures = $this->ReadAttributeInteger('PushFailures');
        if ($failures < 3 && ($since === 0 || time() - $since < 600)) {
            return;
        }
        $this->WriteAttributeBoolean('PushAlarm', true);
        $last = (string) $this->GetValue('LastError');
        $text = sprintf(
            'Push-Verbindung zur Easee-Cloud getrennt seit %s (%d Fehlversuche). Die Werte kommen weiter per Abfrage, Änderungen aber langsamer. Das Modul versucht es automatisch weiter.%s',
            $since > 0 ? date('d.m.Y H:i', $since) : 'unbekannt',
            $failures,
            $last !== '' ? ' Letzter Fehler: ' . $last : ''
        );
        $this->LogMessage($text, KL_ERROR);
        $this->SetError('Push getrennt seit ' . ($since > 0 ? date('d.m. H:i', $since) : '?') . ' – ' . $failures . ' Fehlversuche, Werte per Abfrage');
    }

    /** Alle 5 min (TokenTimer): Push soll laufen, aber nichts ist geplant? Dann neu anstoßen. */
    private function SupervisePush(): void
    {
        if (!$this->ReadPropertyBoolean('UsePush') || $this->GetBuffer('PushConnected') === '1') {
            return;
        }
        if (trim($this->ReadPropertyString('Username')) === '' || $this->ReadPropertyString('Password') === '') {
            return;
        }
        $pending = $this->GetBuffer('HandshakePending') === '1';
        $stuck = $pending && time() - (int) $this->GetBuffer('LastPushRx') > 120;
        if ($stuck) {
            $this->SetError('Push: Verbindungsaufbau hängt – neuer Versuch');
            $this->SchedulePushRetry();
        } elseif (!$pending && $this->GetTimerInterval('PushConnect') === 0) {
            $this->SendDebug('Push', 'Keine Verbindung und kein Versuch geplant – neuer Versuch', 0);
            $this->SetTimerInterval('PushConnect', 1000);
        }
        $this->CheckPushAlarm();
    }

    private function ResetPushMonitor(): void
    {
        $this->WriteAttributeInteger('PushDownSince', 0);
        $this->WriteAttributeInteger('PushFailures', 0);
        $this->WriteAttributeBoolean('PushAlarm', false);
    }

    private function AddSubscription(string $chargerID): void
    {
        if ($chargerID === '') {
            return;
        }
        $subs = $this->GetSubscriptions();
        if (!in_array($chargerID, $subs, true)) {
            $subs[] = $chargerID;
            $this->SetBuffer('Subscriptions', json_encode($subs));
        }
        if ($this->GetBuffer('PushConnected') === '1') {
            $this->SendSubscribe($chargerID);
        }
    }

    private function GetSubscriptions(): array
    {
        return json_decode($this->GetBuffer('Subscriptions'), true) ?: [];
    }

    private function SendSubscribe(string $chargerID): void
    {
        $id = (int) $this->GetBuffer('InvocationID') + 1;
        $this->SetBuffer('InvocationID', (string) $id);
        $msg = json_encode([
            'type'         => 1,
            'invocationId' => (string) $id,
            'target'       => 'SubscribeWithCurrentState',
            'arguments'    => [$chargerID, true]
        ]);
        $this->SendRaw($msg . self::RS);
        $this->SendDebug('Push', 'Abonniert: ' . $chargerID, 0);
    }

    private function SendRaw(string $text): void
    {
        if ($this->GetParentID() === 0) {
            return;
        }
        try {
            // IPSModule: Bytes UTF-8-kodiert an den I/O übergeben
            $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1')]));
        } catch (Throwable $e) {
            $this->SendDebug('Push', 'Senden fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    private function SetPushConnected(bool $connected): void
    {
        $old = $this->GetBuffer('PushConnected') === '1';
        if ($connected && !$old) {
            $since = $this->ReadAttributeInteger('PushDownSince');
            if ($this->ReadAttributeBoolean('PushAlarm')) {
                $this->LogMessage(sprintf('Push-Verbindung zur Easee-Cloud wieder hergestellt (getrennt seit %s).', $since > 0 ? date('d.m.Y H:i', $since) : 'unbekannt'), KL_MESSAGE);
                $this->SetError('Push wieder verbunden (getrennt seit ' . ($since > 0 ? date('d.m. H:i', $since) : '?') . ')');
            }
            $this->ResetPushMonitor();
        } elseif (!$connected && $old && $this->ReadAttributeInteger('PushDownSince') === 0) {
            $this->WriteAttributeInteger('PushDownSince', time());
        }
        $this->SetBuffer('PushConnected', $connected ? '1' : '0');
        if (@$this->GetIDForIdent('PushConnected') > 0) {
            $this->SetValue('PushConnected', $connected);
        }
        if ($old !== $connected) {
            $this->SendDataToChildren(json_encode([
                'DataID'    => self::TO_CHILD,
                'ChargerID' => '*',
                'Type'      => 'PushState',
                'Connected' => $connected
            ]));
        }
    }

    private function DeactivateParent(): void
    {
        $parentID = $this->GetParentID();
        if ($parentID === 0) {
            return;
        }
        $config = json_decode(IPS_GetConfiguration($parentID), true);
        if (!is_array($config) || !array_key_exists('Active', $config)) {
            return;
        }
        if ($config['Active'] || (($config['URL'] ?? '') !== '')) {
            IPS_SetProperty($parentID, 'Active', false);
            if (array_key_exists('URL', $config)) {
                IPS_SetProperty($parentID, 'URL', '');
            }
            IPS_ApplyChanges($parentID);
        }
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function RegisterParent(): int
    {
        $old = (int) $this->GetBuffer('ParentID');
        $new = $this->GetParentID();
        if ($old !== $new) {
            if ($old > 0 && IPS_InstanceExists($old)) {
                $this->UnregisterMessage($old, IM_CHANGESTATUS);
            }
            if ($new > 0) {
                $this->RegisterMessage($new, IM_CHANGESTATUS);
            }
            $this->SetBuffer('ParentID', (string) $new);
        }
        return $new;
    }

    private function GetParentID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function IsBlackhole(array $res): bool
    {
        $location = (string) ($res['headers']['location'] ?? '');
        return stripos($location, 'blackhole') !== false;
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

    private function SetError(string $text): void
    {
        $this->SendDebug('Fehler', $text, 0);
        if (@$this->GetIDForIdent('LastError') > 0) {
            $this->SetValue('LastError', date('d.m. H:i:s') . ' ' . $text);
        }
    }

    /** HTTP-Anfrage. Rückgabe: code, headers (klein geschrieben), body, error */
    protected function Http(string $method, string $url, ?array $body, string $token): array
    {
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        } elseif ($method !== 'GET') {
            $payload = '';
            $headers[] = 'Content-Length: 0';
        }

        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$respHeaders) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            return strlen($line);
        });
        $result = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $safeUrl = preg_replace('/(access_token=)[^&]+/', '$1***', $url);
        $this->SendDebug('HTTP', $method . ' ' . $safeUrl . ' -> ' . $code, 0);
        if ($result !== false && strpos($url, '/accounts/') === false) {
            $this->SendDebug('HTTP Antwort', substr((string) $result, 0, 2000), 0);
        }
        return [
            'code'    => $result === false ? 0 : $code,
            'headers' => $respHeaders,
            'body'    => $result === false ? '' : (string) $result,
            'error'   => $error
        ];
    }
}
