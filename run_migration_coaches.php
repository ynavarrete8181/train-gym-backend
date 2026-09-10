<?php
$pdoV2 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio_v2', 'postgres', 'Yandry/*.');
$pdoV1 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio', 'postgres', 'Yandry/*.');

echo "Migrando entrenadores...\n";
$stmt = $pdoV2->query("SELECT p.*, c.nombres, c.apellidos, c.numero_identificacion, c.email FROM staff.perfiles p JOIN core.personas c ON p.persona_id = c.id");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $email = $row['email'] ?: ('coach' . $row['id'] . '@gimnasio.local');
    $identificacion = $row['numero_identificacion'];
    
    // Check if user exists
    $checkUser = $pdoV1->prepare("SELECT id FROM seguridad.users WHERE email = ? OR cedula = ?");
    $checkUser->execute([$email, $identificacion]);
    $userId = $checkUser->fetchColumn();
    
    if (!$userId) {
        $nombres = $row['nombres'] . ' ' . $row['apellidos'];
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
    
    // Check if Coach exists
    $checkDep = $pdoV1->prepare("SELECT id FROM gimnasio.entrenadores WHERE usuario_id = ?");
    $checkDep->execute([$userId]);
    
    if (!$checkDep->fetchColumn()) {
        $insertDep = $pdoV1->prepare("INSERT INTO gimnasio.entrenadores (usuario_id, especialidad, tipo, estado, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
        $insertDep->execute([
            $userId,
            $row['especialidad'],
            'COACH',
            $row['estado'] ?: 'ACTIVO',
            $row['created_at'],
            $row['updated_at']
        ]);
        echo "Coach migrado: " . $row['nombres'] . "\n";
    }
}
echo "Migracion completada.\n";
