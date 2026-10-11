<?php
$pdoV2 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio_v2', 'postgres', 'Yandry/*.');
$pdoV1 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio', 'postgres', 'Yandry/*.');

echo "Migrando clientes a Deportistas...\n";
$stmt = $pdoV2->query("SELECT s.*, p.nombres, p.apellidos, p.numero_identificacion, p.telefono, p.email, p.fecha_nacimiento FROM socios.socios s JOIN core.personas p ON s.persona_id = p.id");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $email = $row['email'] ?: ('cliente' . $row['id'] . '@gimnasio.local');
    $nombres = $row['nombres'] . ' ' . $row['apellidos'];
    $identificacion = $row['numero_identificacion'];
    
    // 1. Check if user exists in seguridad.users by email or cedula
    $checkUser = $pdoV1->prepare("SELECT id FROM seguridad.users WHERE email = ? OR cedula = ?");
    $checkUser->execute([$email, $identificacion]);
    $userId = $checkUser->fetchColumn();
    
    if (!$userId) {
        echo "Creando usuario para: $nombres\n";
        $insertUser = $pdoV1->prepare("INSERT INTO seguridad.users (name, email, cedula, nombres, apellidos, password, usr_estado, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVO', ?, ?) RETURNING id");
        $insertUser->execute([
            $nombres,
            $email,
            $identificacion,
            $row['nombres'],
            $row['apellidos'],
            password_hash($identificacion ?: '12345678', PASSWORD_BCRYPT),
            $row['created_at'],
            $row['updated_at']
        ]);
        $userId = $insertUser->fetchColumn();
    }
    
    // 2. Create Deportista profile
    $checkDep = $pdoV1->prepare("SELECT id FROM gimnasio.deportistas WHERE usuario_id = ?");
    $checkDep->execute([$userId]);
    
    if (!$checkDep->fetchColumn()) {
        echo "Creando perfil deportista para: $nombres\n";
        $insertDep = $pdoV1->prepare("INSERT INTO gimnasio.deportistas (usuario_id, codigo_deportista, fecha_nacimiento, genero, telefono, estado, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insertDep->execute([
            $userId,
            'DEP-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT),
            $row['fecha_nacimiento'],
            'NO_ESPECIFICADO',
            $row['telefono'],
            $row['activo'] ? 'ACTIVO' : 'INACTIVO',
            $row['created_at'],
            $row['updated_at']
        ]);
    }
}
echo "Migracion completada.\n";
