<?php
// ─── API DE AUTENTICACIÓN ───

$action = $_GET['op'] ?? null;

// ─── LOGIN ───
if ($action === 'login') {
    header('Content-Type: application/json');

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Usuario y contraseña son requeridos']);
        exit;
    }

    $user = verifyCredentials($username, $password);

    if ($user) {
        createSession($user['id'], $remember);

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciales incorrectas']);
    }
    exit;
}

// ─── LOGOUT ───
if ($action === 'logout') {
    header('Content-Type: application/json');

    destroySession();

    echo json_encode(['success' => true]);
    exit;
}

// ─── CHECK SESSION ───
if ($action === 'check-session') {
    header('Content-Type: application/json');

    $user = getSessionUser();

    if ($user) {
        if (!refreshSessionActivity()) {
            echo json_encode(['valid' => false]);
        } else {
            echo json_encode([
                'valid' => true,
                'user' => $user
            ]);
        }
    } else {
        echo json_encode(['valid' => false]);
    }
    exit;
}

// ─── USERS LIST (solo admin) ───
if ($action === 'users') {
    header('Content-Type: application/json');

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $users = getUsers();
    $safeUsers = [];
    foreach ($users as $u) {
        $safeUsers[] = [
            'id' => $u['id'],
            'username' => $u['username'],
            'role' => $u['role'],
            'created_at' => $u['created_at']
        ];
    }

    echo json_encode(['users' => $safeUsers]);
    exit;
}

// ─── USER CREATE (solo admin) ───
if ($action === 'user-create') {
    header('Content-Type: application/json');

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';

    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario es requerido']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario debe tener entre 3 y 30 caracteres alfanuméricos, guiones o guiones bajos']);
        exit;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'La contraseña debe tener al menos 6 caracteres']);
        exit;
    }

    if ($role !== 'user') {
        http_response_code(400);
        echo json_encode(['error' => 'Solo se pueden crear usuarios de tipo "user"']);
        exit;
    }

    $newUser = createUser($username, $password, $role);

    if ($newUser) {
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $newUser['id'],
                'username' => $newUser['username'],
                'role' => $newUser['role']
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario ya existe']);
    }
    exit;
}

// ─── USER UPDATE (solo admin) ───
if ($action === 'user-update') {
    header('Content-Type: application/json');

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario inválido']);
        exit;
    }

    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario es requerido']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario debe tener entre 3 y 30 caracteres alfanuméricos, guiones o guiones bajos']);
        exit;
    }

    if ($role !== 'admin' && $role !== 'user') {
        http_response_code(400);
        echo json_encode(['error' => 'Rol inválido']);
        exit;
    }

    $existing = getUserById($id);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }

    if ($existing['role'] === 'admin' && $role === 'user') {
        $adminCount = 0;
        foreach (getUsers() as $u) {
            if ($u['role'] === 'admin') {
                $adminCount++;
            }
        }
        if ($adminCount <= 1) {
            http_response_code(400);
            echo json_encode(['error' => 'No se puede cambiar el rol del único administrador']);
            exit;
        }
    }

    $updated = updateUser($id, [
        'username' => $username,
        'role' => $role
    ]);

    if ($updated) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre de usuario ya existe']);
    }
    exit;
}

// ─── USER DELETE (solo admin) ───
if ($action === 'user-delete') {
    header('Content-Type: application/json');

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario inválido']);
        exit;
    }

    $deleted = deleteUser($id);

    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'No se puede eliminar el único administrador']);
    }
    exit;
}

// ─── CHANGE PASSWORD ───
if ($action === 'change-password') {
    header('Content-Type: application/json');

    authLog("change-password: request received");

    $currentUser = getSessionUser();
    if (!$currentUser) {
        authLog("change-password: user not authenticated");
        http_response_code(401);
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }

    authLog("change-password: user=" . $currentUser['username'] . " (id=" . $currentUser['id'] . ")");

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '') {
        authLog("change-password: missing passwords");
        http_response_code(400);
        echo json_encode(['error' => 'Contraseña actual y nueva son requeridas']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        authLog("change-password: new password too short");
        http_response_code(400);
        echo json_encode(['error' => 'La nueva contraseña debe tener al menos 6 caracteres']);
        exit;
    }

    $user = getUserById($currentUser['id']);
    if (!$user) {
        authLog("change-password: user not found in storage");
        http_response_code(400);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        authLog("change-password: current password verification FAILED");
        http_response_code(400);
        echo json_encode(['error' => 'La contraseña actual es incorrecta']);
        exit;
    }

    authLog("change-password: current password verified, calling changePassword");

    changePassword($currentUser['id'], $newPassword);

    authLog("change-password: completed successfully");

    echo json_encode(['success' => true]);
    exit;
}
