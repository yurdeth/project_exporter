<?php
// ─── LOGGING ───

function authLog($message, $level = 'INFO') {
    $logFile = __DIR__ . '/error.log';

    // Rotar log si es muy grande (>5MB)
    if (file_exists($logFile) && filesize($logFile) > 5242880) {
        $backupFile = __DIR__ . '/error.' . date('Y-m-d.His') . '.log';
        @rename($logFile, $backupFile);
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = '[' . $timestamp . '] [' . $level . '] ' . $message . "\n";

    // Intentar escribir al log, silenciar errores si no se puede
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ─── ERROR HANDLING AUTOMÁTICO ───

// Capturar warnings, notices, etc.
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];

    $type = $errorTypes[$errno] ?? 'UNKNOWN';
    $message = "$type: $errstr in $errfile:$errline";

    // Solo loggear errores significativos (no notices deprecados)
    if ($errno !== E_DEPRECATED && $errno !== E_USER_DEPRECATED) {
        authLog($message, 'ERROR');
    }

    // No interferir con el error handling normal de PHP
    return false;
});

// Capturar excepciones no capturadas
set_exception_handler(function($exception) {
    $message = 'UNCAUGHT EXCEPTION: ' . $exception->getMessage() .
               ' in ' . $exception->getFile() . ':' . $exception->getLine() .
               "\nStack trace:\n" . $exception->getTraceAsString();
    authLog($message, 'FATAL');

    // Mostrar error genérico al usuario
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
    exit;
});

// Capturar errores fatales (shutdown function)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $message = 'FATAL ERROR: ' . $error['message'] .
                   ' in ' . $error['file'] . ':' . $error['line'];
        authLog($message, 'FATAL');
    }
});

// Log inicial al cargar el módulo (comentar en producción para reducir ruido)
authLog("Auth module loaded, PHP version=" . PHP_VERSION . ", login_enabled=" . ($GLOBALS['loginEnabled'] ?? 'unknown'));

// ─── CONFIGURACIÓN DE SESIÓN ───

function authInit() {
    session_name('exporter_sess');
    session_set_cookie_params(0, '/', '', false, true);
    @session_start();
}

// ─── DETECCIÓN DE ALMACENAMIENTO ───

function getStorageType() {
    static $type = null;
    if ($type === null) {
        $hasSqlite = extension_loaded('pdo_sqlite');
        $hasFile = file_exists(__DIR__ . '/exporter.sqlite');

        authLog("Storage detection: pdo_sqlite=" . ($hasSqlite ? 'yes' : 'no') . ", sqlite_file=" . ($hasFile ? 'yes' : 'no'));

        if ($hasSqlite && $hasFile) {
            $type = 'sqlite';
            authLog("Using SQLite storage");
        } else {
            $type = 'txt';
            authLog("Using TXT storage");
        }
    }
    return $type;
}

function getAuthDataPath() {
    return __DIR__;
}

// ─── FUNCIONES DE ALMACENAMIENTO ───

function getUsers() {
    if (getStorageType() === 'sqlite') {
        $db = new PDO('sqlite:' . __DIR__ . '/exporter.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query('SELECT id, username, password_hash, role, remember_token, remember_expires, created_at, updated_at FROM users ORDER BY id');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $path = __DIR__ . '/exporter.txt';
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $users = [];
        foreach ($lines as $line) {
            $user = json_decode($line, true);
            if ($user) {
                $users[] = $user;
            }
        }
        return $users;
    }
}

function saveUsers($users) {
    if (getStorageType() === 'sqlite') {
        $db = new PDO('sqlite:' . __DIR__ . '/exporter.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();
        $db->exec('DELETE FROM users');
        $stmt = $db->prepare('INSERT INTO users (id, username, password_hash, role, remember_token, remember_expires, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($users as $u) {
            $stmt->execute([
                $u['id'],
                $u['username'],
                $u['password_hash'],
                $u['role'],
                $u['remember_token'],
                $u['remember_expires'],
                $u['created_at'],
                $u['updated_at']
            ]);
        }
        $db->commit();
    } else {
        $path = __DIR__ . '/exporter.txt';
        $fp = fopen($path, 'c');
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            foreach ($users as $u) {
                fwrite($fp, json_encode($u) . "\n");
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

function getUserById($id) {
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['id'] == $id) {
            return $u;
        }
    }
    return null;
}

function getUserByUsername($username) {
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['username'] === $username) {
            return $u;
        }
    }
    return null;
}

function createUser($username, $password, $role) {
    $users = getUsers();

    foreach ($users as $u) {
        if ($u['username'] === $username) {
            return false;
        }
    }

    $maxId = 0;
    foreach ($users as $u) {
        if ($u['id'] > $maxId) {
            $maxId = $u['id'];
        }
    }

    $newUser = [
        'id' => $maxId + 1,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'remember_token' => null,
        'remember_expires' => null,
        'created_at' => date('c'),
        'updated_at' => date('c')
    ];

    if (getStorageType() === 'sqlite') {
        $db = new PDO('sqlite:' . __DIR__ . '/exporter.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, remember_token, remember_expires, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $newUser['username'],
            $newUser['password_hash'],
            $newUser['role'],
            $newUser['remember_token'],
            $newUser['remember_expires'],
            $newUser['created_at'],
            $newUser['updated_at']
        ]);
        $newUser['id'] = $db->lastInsertId();
    } else {
        $users[] = $newUser;
        saveUsers($users);
    }

    return $newUser;
}

function updateUser($id, $data) {
    authLog("updateUser called: id=$id, data=" . json_encode($data));

    $users = getUsers();
    $index = null;
    for ($i = 0; $i < count($users); $i++) {
        if ($users[$i]['id'] == $id) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        authLog("updateUser: user not found with id=$id");
        return false;
    }

    $originalUsername = $users[$index]['username'];

    if (isset($data['username'])) {
        foreach ($users as $u) {
            if ($u['id'] != $id && $u['username'] === $data['username']) {
                authLog("updateUser: username already exists");
                return false;
            }
        }
        $users[$index]['username'] = $data['username'];
    }

    if (isset($data['role'])) {
        $users[$index]['role'] = $data['role'];
    }

    if (isset($data['password'])) {
        $newHash = password_hash($data['password'], PASSWORD_DEFAULT);
        $users[$index]['password_hash'] = $newHash;
        authLog("updateUser: password hashed for user " . $users[$index]['username']);
    }

    if (array_key_exists('remember_token', $data)) {
        $users[$index]['remember_token'] = $data['remember_token'];
        authLog("updateUser: remember_token updated for user " . $users[$index]['username']);
    }

    if (array_key_exists('remember_expires', $data)) {
        $users[$index]['remember_expires'] = $data['remember_expires'];
        authLog("updateUser: remember_expires updated for user " . $users[$index]['username']);
    }

    $users[$index]['updated_at'] = date('c');

    $storage = getStorageType();
    authLog("updateUser: saving to storage=$storage");

    if ($storage === 'sqlite') {
        try {
            $dbPath = __DIR__ . '/exporter.sqlite';
            authLog("updateUser: sqlite path=$dbPath");

            if (!is_writable($dbPath)) {
                authLog("updateUser: ERROR - sqlite file not writable!");
            }

            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $fields = ['updated_at = ?'];
            $params = [date('c')];

            if (isset($data['username'])) {
                $fields[] = 'username = ?';
                $params[] = $data['username'];
            }
            if (isset($data['role'])) {
                $fields[] = 'role = ?';
                $params[] = $data['role'];
            }
            if (isset($data['password'])) {
                $fields[] = 'password_hash = ?';
                $params[] = $users[$index]['password_hash'];
            }
            if (array_key_exists('remember_token', $data)) {
                $fields[] = 'remember_token = ?';
                $params[] = $data['remember_token'];
            }
            if (array_key_exists('remember_expires', $data)) {
                $fields[] = 'remember_expires = ?';
                $params[] = $data['remember_expires'];
            }

            $params[] = $id;

            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
            authLog("updateUser: sql=$sql, params=" . json_encode($params));

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            authLog("updateUser: sqlite UPDATE executed successfully");
        } catch (Exception $e) {
            authLog("updateUser: EXCEPTION - " . $e->getMessage());
            throw $e;
        }
    } else {
        authLog("updateUser: saving to TXT file");
        saveUsers($users);
    }

    authLog("updateUser: completed successfully");
    return true;
}

function deleteUser($id) {
    $users = getUsers();

    $adminCount = 0;
    $targetIsAdmin = false;
    foreach ($users as $u) {
        if ($u['role'] === 'admin') {
            $adminCount++;
            if ($u['id'] == $id) {
                $targetIsAdmin = true;
            }
        }
    }

    if ($targetIsAdmin && $adminCount <= 1) {
        return false;
    }

    if (getStorageType() === 'sqlite') {
        $db = new PDO('sqlite:' . __DIR__ . '/exporter.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    } else {
        $newUsers = [];
        foreach ($users as $u) {
            if ($u['id'] != $id) {
                $newUsers[] = $u;
            }
        }
        saveUsers($newUsers);
    }

    return true;
}

// ─── VERIFICACIÓN DE CREDENCIALES ───

function verifyCredentials($username, $password) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }

    if ($_SESSION['login_attempts'] >= 5) {
        $delay = min($_SESSION['login_attempts'] - 4, 5);
        sleep($delay);
    }

    $user = getUserByUsername($username);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['login_attempts'] = 0;
        return $user;
    }

    $_SESSION['login_attempts']++;
    return null;
}

// ─── GESTIÓN DE SESIÓN ───

function createSession($userId, $remember) {
    $user = getUserById($userId);
    if (!$user) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expires = date('c', strtotime('+7 days'));

        updateUser($userId, [
            'remember_token' => $token,
            'remember_expires' => $expires
        ]);

        setcookie('exporter_remember', $token, [
            'expires' => strtotime('+7 days'),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    return true;
}

function destroySession() {
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];

        // Intentar limpiar remember_token, pero no fallar si no hay permisos
        try {
            updateUser($userId, [
                'remember_token' => null,
                'remember_expires' => null
            ]);
        } catch (Exception $e) {
            // Ignorar error de BD — el token expira por sí mismo
        }
    }

    $_SESSION = [];

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => '/',
            'httponly' => true
        ]);
    }

    if (isset($_COOKIE['exporter_remember'])) {
        setcookie('exporter_remember', '', [
            'expires' => time() - 42000,
            'path' => '/',
            'httponly' => true
        ]);
    }

    session_destroy();
}

function checkAuth() {
    if (isset($_SESSION['user_id'])) {
        $user = getUserById($_SESSION['user_id']);
        if ($user) {
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role']
            ];
        }
    }

    if (isset($_COOKIE['exporter_remember'])) {
        $token = $_COOKIE['exporter_remember'];

        $users = getUsers();
        foreach ($users as $u) {
            if ($u['remember_token'] === $token) {
                $expires = strtotime($u['remember_expires']);
                if ($expires > time()) {
                    $_SESSION['user_id'] = $u['id'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['role'] = $u['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();

                    return [
                        'id' => $u['id'],
                        'username' => $u['username'],
                        'role' => $u['role']
                    ];
                }
            }
        }
    }

    return null;
}

function refreshSessionActivity() {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    $inactive = time() - $_SESSION['last_activity'];

    if ($inactive > 1800) {
        destroySession();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function getSessionUser() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
    return null;
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function changePassword($userId, $newPassword) {
    return updateUser($userId, ['password' => $newPassword]);
}

// ─── INICIALIZACIÓN DE SQLITE (solo para compilación CLI) ───

function initSqliteDb($path) {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ("admin","user")) DEFAULT "user",
        remember_token TEXT DEFAULT NULL,
        remember_expires TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    return $db;
}
