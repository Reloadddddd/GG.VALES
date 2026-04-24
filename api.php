<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db_file = __DIR__ . '/vales_secure.sqlite';

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Table des bots
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id TEXT NOT NULL,
            name TEXT NOT NULL,
            engine TEXT NOT NULL,
            template TEXT NOT NULL,
            token TEXT DEFAULT '',
            status TEXT DEFAULT 'Hors ligne',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Table des accès partagés
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_users (
            bot_id INTEGER,
            user_id TEXT,
            PRIMARY KEY(bot_id, user_id)
        )
    ");

    $action = $_GET['action'] ?? '';

    function getBody() {
        return json_decode(file_get_contents('php://input'), true);
    }

    // --- CRÉER ---
    if ($action === 'create') {
        $data = getBody();
        if (empty($data['discord_id']) || empty($data['name'])) { echo json_encode(['error' => 'Champs manquants']); exit; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bots WHERE owner_id = ?");
        $stmt->execute([$data['discord_id']]);
        if ($stmt->fetchColumn() >= 3) { echo json_encode(['error' => 'Limite atteinte : 3 bots maximum.']); exit; }

        $stmt = $pdo->prepare("INSERT INTO bots (owner_id, name, engine, template) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['discord_id'], $data['name'], $data['engine'], $data['template']]);
        echo json_encode(['success' => true]); exit;
    }

    // --- RÉCUPÉRER ---
    elseif ($action === 'get') {
        $user_id = $_GET['discord_id'] ?? '';
        $stmt = $pdo->prepare("
            SELECT DISTINCT b.* FROM bots b
            LEFT JOIN bot_users bu ON b.id = bu.bot_id
            WHERE b.owner_id = ? OR bu.user_id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($bots as &$bot) {
            $bot['token_value'] = $bot['token']; 
        }
        echo json_encode($bots); exit;
    }

    // --- SAUVEGARDER & SIMULER DÉMARRAGE ---
    elseif ($action === 'start') {
        $data = getBody();
        $bot_id = $data['bot_id'];
        $files = $data['files']; 

        // Création du dossier de stockage physique sur ton hébergement
        $bot_dir = __DIR__ . "/bots/bot_" . $bot_id;
        if(!is_dir($bot_dir)) {
            mkdir($bot_dir, 0777, true);
        }

        // Écriture de chaque fichier
        foreach($files as $name => $content) {
            $safe_name = basename($name); // Sécurité anti-hack de chemin
            file_put_contents($bot_dir . '/' . $safe_name, $content);
        }

        // Mise à jour BDD
        $stmt = $pdo->prepare("UPDATE bots SET status = 'En ligne' WHERE id = ?");
        $stmt->execute([$bot_id]);

        echo json_encode(['success' => true, 'message' => 'Fichiers stockés avec succès.']); exit;
    }

    // --- ARRÊTER ---
    elseif ($action === 'stop') {
        $data = getBody();
        $stmt = $pdo->prepare("UPDATE bots SET status = 'Hors ligne' WHERE id = ?");
        $stmt->execute([$data['bot_id']]);
        echo json_encode(['success' => true]); exit;
    }

    // --- PARAMÈTRES ---
    elseif ($action === 'update_settings') {
        $data = getBody();
        $stmt = $pdo->prepare("UPDATE bots SET token = ? WHERE id = ?");
        $stmt->execute([$data['token'], $data['bot_id']]);
        echo json_encode(['success' => true]); exit;
    }
    
    // --- SUPPRIMER ---
    elseif ($action === 'delete') {
        $data = getBody();
        $stmt = $pdo->prepare("DELETE FROM bots WHERE id = ? AND owner_id = ?");
        $stmt->execute([$data['bot_id'], $data['discord_id']]);
        $pdo->prepare("DELETE FROM bot_users WHERE bot_id = ?")->execute([$data['bot_id']]);
        
        // Suppression du dossier de stockage
        $bot_dir = __DIR__ . "/bots/bot_" . $data['bot_id'];
        if(is_dir($bot_dir)) {
            $files = glob($bot_dir . '/*');
            foreach($files as $file) {
                if(is_file($file)) unlink($file);
            }
            rmdir($bot_dir);
        }
        echo json_encode(['success' => true]); exit;
    }

    // --- UTILISATEURS ---
    elseif ($action === 'get_users') {
        $bot_id = $_GET['bot_id'] ?? '';
        $stmt = $pdo->prepare("SELECT user_id FROM bot_users WHERE bot_id = ?");
        $stmt->execute([$bot_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN)); exit;
    }
    elseif ($action === 'add_user') {
        $data = getBody();
        try {
            $stmt = $pdo->prepare("INSERT INTO bot_users (bot_id, user_id) VALUES (?, ?)");
            $stmt->execute([$data['bot_id'], $data['user_id']]);
            echo json_encode(['success' => true]);
        } catch(Exception $e) { echo json_encode(['error' => 'Cet utilisateur a déjà accès.']); }
        exit;
    }
    elseif ($action === 'remove_user') {
        $data = getBody();
        $stmt = $pdo->prepare("DELETE FROM bot_users WHERE bot_id = ? AND user_id = ?");
        $stmt->execute([$data['bot_id'], $data['user_id']]);
        echo json_encode(['success' => true]); exit;
    }

    else { echo json_encode(['error' => 'Action inconnue']); }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'details' => $e->getMessage()]);
}
?>