<?php
$pdoV2 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio_v2', 'postgres', 'Yandry/*.');
$pdoV1 = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=db_gimnasio', 'postgres', 'Yandry/*.');

echo "Migrando deportistas...\n";
$stmt = $pdoV2->query("SELECT s.*, p.nombres, p.apellidos, p.numero_identificacion, p.telefono, p.email, p.fecha_nacimiento FROM socios.socios s JOIN core.personas p ON s.persona_id = p.id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $check = $pdoV1->prepare("SELECT 1 FROM gimnasio.deportistas WHERE identificacion = ?");
    $check->execute([$row['numero_identificacion']]);
    if (!$check->fetch()) {
        $insert = $pdoV1->prepare("INSERT INTO gimnasio.deportistas (identificacion, nombres, apellidos, correo, telefono, fecha_nacimiento, genero, activo, fecha_registro, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([
            $row['numero_identificacion'] ?? 'N/A',
            $row['nombres'],
            $row['apellidos'] ?? '',
            $row['email'] ?? null,
            $row['telefono'] ?? null,
            $row['fecha_nacimiento'],
            'NO_ESPECIFICADO',
            $row['activo'] ? 1 : 0,
            $row['created_at'],
            $row['created_at'],
            $row['updated_at']
        ]);
    }
}
echo "Migracion de deportistas completada.\n";
