<?php
session_start();
//functions/plex/_plex_auth.php
// Deine App-Informationen (wichtig für Plex)
$plexHeaders = [
    'X-Plex-Product: MeinCoolesDashboard', // Name deiner App
    'X-Plex-Client-Identifier: app-unique-id-' . md5('mein_geheimes_dashboard'), // Eine eindeutige ID für deine App
    'Accept: application/json'
];

$action = $_GET['action'] ?? 'start';

// ==========================================
// SCHRITT 1: Login starten & Nutzer weiterleiten
// ==========================================
if ($action === 'start') {
    // 1. PIN bei Plex anfordern
    $ch = curl_init('https://plex.tv/api/v2/pins?strong=true');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $plexHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    $data = json_decode($response, true);

    if (isset($data['id']) && isset($data['code'])) {
        // PIN-ID für später in der Session speichern
        $_SESSION['plex_pin_id'] = $data['id'];
        
        // 2. Offizielle Plex-Login-URL zusammenbauen
        $clientId = 'app-unique-id-' . md5('mein_geheimes_dashboard');
        $forwardUrl = urlencode('http://' . $_SERVER['HTTP_HOST'] . '/plex_login.php?action=verify'); // Hierhin kommt der Nutzer nach dem Login zurück
        
        $authUrl = "https://app.plex.tv/auth#?clientID={$clientId}&code={$data['code']}&context[device][product]=MeinCoolesDashboard&forwardUrl={$forwardUrl}";

        // Nutzer zu Plex weiterleiten
        echo "<h1>Plex Login</h1>";
        echo "<p>Bitte klicke auf den Button, um dich bei Plex anzumelden.</p>";
        echo "<a href='{$authUrl}' style='padding: 10px 20px; background: #e5a00d; color: #fff; text-decoration: none; border-radius: 5px;'>Mit Plex anmelden</a>";
    } else {
        echo "Fehler beim Anfordern des Plex-PINs.";
    }
}

// ==========================================
// SCHRITT 2: Login prüfen & Server auslesen
// ==========================================
elseif ($action === 'verify') {
    if (!isset($_SESSION['plex_pin_id'])) {
        die("Keine PIN-ID gefunden. Bitte starte den Login neu.");
    }

    $pinId = $_SESSION['plex_pin_id'];

    // 3. Prüfen, ob der Nutzer den Login bestätigt hat und das Token abholen
    $ch = curl_init("https://plex.tv/api/v2/pins/{$pinId}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $plexHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    $data = json_decode($response, true);

    if (isset($data['authToken']) && $data['authToken'] !== null) {
        $token = $data['authToken'];
        echo "<h2 style='color: green;'>Erfolgreich eingeloggt!</h2>";
        // WICHTIG: Dieses Token speicherst du jetzt normalerweise in deiner Datenbank für diesen Nutzer!
        echo "<p>Dein geheimes Token: <strong>{$token}</strong></p>";

        // 4. Server des Nutzers abfragen
        echo "<h3>Deine verknüpften Server:</h3>";
        
        // Füge das Token zu den Headern hinzu
        $authHeaders = $plexHeaders;
        $authHeaders[] = "X-Plex-Token: {$token}";

        // Die "resources" API gibt dir alle Server des Nutzers zurück
        $chServer = curl_init("https://plex.tv/api/v2/resources?includeHttps=1");
        curl_setopt($chServer, CURLOPT_HTTPHEADER, $authHeaders);
        curl_setopt($chServer, CURLOPT_RETURNTRANSFER, true);
        $serverResponse = curl_exec($chServer);

        $servers = json_decode($serverResponse, true);

        if (!empty($servers)) {
            echo "<ul>";
            foreach ($servers as $resource) {
                // Uns interessieren nur die echten Plex Media Server
                if ($resource['provides'] === 'server') {
                    echo "<li>";
                    echo "<strong>" . htmlspecialchars($resource['name']) . "</strong><br>";
                    
                    // Zeige die Verbindungs-IPs des Servers an
                    if (isset($resource['connections'])) {
                        foreach ($resource['connections'] as $conn) {
                            echo "<small>URL: " . $conn['uri'] . "</small><br>";
                        }
                    }
                    echo "</li>";
                }
            }
            echo "</ul>";
        } else {
            echo "<p>Keine Server gefunden.</p>";
        }

    } else {
        echo "<h2 style='color: red;'>Login nicht abgeschlossen oder fehlgeschlagen.</h2>";
        echo "<a href='?action=start'>Nochmal versuchen</a>";
    }
}