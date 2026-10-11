<?php
$pdo = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio', 'postgres', 'Yandry/*.');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Crear tabla
$pdo->exec("
CREATE TABLE IF NOT EXISTS gimnasio.entrenadores (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES seguridad.users(id) ON DELETE CASCADE,
    especialidad VARCHAR(150),
    tipo VARCHAR(50) DEFAULT 'COACH',
    estado VARCHAR(30) DEFAULT 'ACTIVO',
    created_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(usuario_id)
);
");

// 2. Crear menú padre "Equipo" (orden 3, después de Operaciones)
$stmt = $pdo->prepare("SELECT id_usermenu FROM seguridad.cpu_usermenu WHERE menu = 'Equipo'");
$stmt->execute();
$idUsermenu = $stmt->fetchColumn();

if (!$idUsermenu) {
    $pdo->exec("INSERT INTO seguridad.cpu_usermenu (menu, icono, activo, orden, created_at, updated_at) VALUES ('Equipo', 'groups', true, 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $idUsermenu = $pdo->lastInsertId('seguridad.cpu_usermenu_id_usermenu_seq');
}

// 3. Crear Submenú y asociar a la página
$stmt = $pdo->prepare("SELECT id_pagina_sistema FROM seguridad.cpu_pagina_sistema WHERE id_menu = 'GIMNASIO-ENTRENADORES'");
$stmt->execute();
if (!$stmt->fetch()) {
    $pdo->exec("INSERT INTO seguridad.cpu_pagina_sistema (id_menu, clave_pagina, created_at, updated_at) VALUES ('GIMNASIO-ENTRENADORES', 'EntrenadoresPage', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
}

// 4. Asignar menú a Administradores (id_userrole = 1) y a todos sus usuarios.
$stmt = $pdo->prepare("SELECT id FROM seguridad.users WHERE usr_tipo = 1");
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($admins as $admin) {
    $stmtCheck = $pdo->prepare("SELECT id_userfunction FROM seguridad.cpu_userfunction WHERE id_users = ? AND id_menu = 'GIMNASIO-ENTRENADORES'");
    $stmtCheck->execute([$admin['id']]);
    if (!$stmtCheck->fetchColumn()) {
        $pdo->prepare("INSERT INTO seguridad.cpu_userfunction (id_users, id_userrole, id_usermenu, nombre, accion, id_menu, activo, orden, icono, created_at, updated_at) VALUES (?, 1, ?, 'Entrenadores', 'VER', 'GIMNASIO-ENTRENADORES', true, 1, 'sports', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
            ->execute([$admin['id'], $idUsermenu]);
    }
}

echo "Migracion DB exitosa.\n";
