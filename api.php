<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/includes/config.php';

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function inputData(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
function reservationOwner(): string {
    $token = (string)($_SERVER['HTTP_X_RESERVATION_OWNER'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/i', $token)) {
        respond(['error' => 'No se pudo identificar esta selección. Actualizá la página e intentá nuevamente.'], 400);
    }
    return hash('sha256', strtolower($token));
}
function requireAdmin(): void {
    if (empty($_SESSION['admin_id'])) respond(['error' => 'Iniciá sesión como administrador.'], 401);
}
$action = $_GET['action'] ?? '';

try {
    if ($action === 'catalogos') {
        $specialties = $pdo->query("SELECT nombre, descripcion FROM especialidades WHERE activa=1 ORDER BY FIELD(nombre,'Clínica Médica','Clínica médica','Ginecología','Kinesiología','Laboratorio','Psicopedagogía'), nombre")->fetchAll();
        $professionals = $pdo->query("SELECT p.id, p.nombre, GROUP_CONCAT(DISTINCT e.nombre ORDER BY e.nombre SEPARATOR '||') AS especialidades FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id JOIN especialidades e ON e.id=pe.especialidad_id WHERE p.activo=1 AND e.activa=1 GROUP BY p.id,p.nombre ORDER BY p.nombre")->fetchAll();
        respond(['specialties' => $specialties, 'professionals' => $professionals]);
    }

    if ($action === 'dias_atencion') {
        $professional = trim((string)($_GET['professional'] ?? ''));
        $specialty = trim((string)($_GET['specialty'] ?? ''));
        if (!$professional || !$specialty) respond(['days'=>[], 'error'=>'Elegí un profesional y una especialidad.'], 422);
        $q = $pdo->prepare("SELECT p.id AS profesional_id, e.id AS especialidad_id FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id AND pe.activo=1 JOIN especialidades e ON e.id=pe.especialidad_id AND e.activa=1 WHERE p.nombre=? AND e.nombre=? AND p.activo=1 LIMIT 1");
        $q->execute([$professional, $specialty]);
        $pair = $q->fetch();
        if (!$pair) respond(['days'=>[]]);
        $q = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM horarios_profesionales WHERE profesional_id=? AND especialidad_id=? AND activo=1 ORDER BY dia_semana, hora_inicio');
        $q->execute([(int)$pair['profesional_id'], (int)$pair['especialidad_id']]);
        $agenda = $q->fetchAll();
        $days = array_values(array_unique(array_map(fn($row) => (int)$row['dia_semana'], $agenda)));
        respond(['days'=>$days, 'agenda'=>$agenda]);
    }

    if ($action === 'disponibilidad') {
        $professional = trim((string)($_GET['professional'] ?? ''));
        $specialty = trim((string)($_GET['specialty'] ?? ''));
        $dateText = trim((string)($_GET['date'] ?? ''));
        $date = DateTime::createFromFormat('!Y-m-d', $dateText);
        if (!$professional || !$specialty || !$date || $date->format('Y-m-d') !== $dateText || $date < new DateTime('today')) {
            respond(['slots'=>[], 'error'=>'Elegí un profesional y una fecha válida.'], 422);
        }
        $q = $pdo->prepare("SELECT p.id AS profesional_id, e.id AS especialidad_id FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id AND pe.activo=1 JOIN especialidades e ON e.id=pe.especialidad_id AND e.activa=1 WHERE p.nombre=? AND e.nombre=? AND p.activo=1 LIMIT 1");
        $q->execute([$professional, $specialty]);
        $pair = $q->fetch();
        if (!$pair) respond(['slots'=>[]]);
        $weekday = (int)$date->format('N');
        $q = $pdo->prepare('SELECT hora_inicio,hora_fin,duracion_minutos FROM horarios_profesionales WHERE profesional_id=? AND especialidad_id=? AND dia_semana=? AND activo=1 ORDER BY hora_inicio');
        $q->execute([(int)$pair['profesional_id'], (int)$pair['especialidad_id'], $weekday]);
        $schedules = $q->fetchAll();
        $q = $pdo->prepare("SELECT hora, COALESCE(duracion_minutos,30) AS duracion_minutos FROM turnos WHERE profesional_id=? AND fecha=? AND estado <> 'Cancelado'");
        $q->execute([(int)$pair['profesional_id'], $dateText]);
        $busy = $q->fetchAll();
        $busyRanges = [];
        foreach ($busy as $b) { $start = strtotime($b['hora']); $busyRanges[] = [$start, $start + ((int)$b['duracion_minutos'] * 60)]; }
        // Las selecciones en proceso bloquean temporalmente la franja para otros pacientes.
        $pdo->prepare('DELETE FROM reservas_horarios_temporales WHERE vence_en <= NOW()')->execute();
        $holdsQuery = $pdo->prepare('SELECT hora, duracion_minutos, propietario_sesion FROM reservas_horarios_temporales WHERE profesional_id=? AND fecha=? AND vence_en > NOW()');
        $holdsQuery->execute([(int)$pair['profesional_id'], $dateText]);
        $heldRanges = [];
        foreach ($holdsQuery->fetchAll() as $hold) {
            $start = strtotime($hold['hora']);
            $heldRanges[] = [$start, $start + ((int)$hold['duracion_minutos'] * 60), hash_equals((string)$hold['propietario_sesion'], reservationOwner())];
        }
        $slotStates = [];
        $now = new DateTime();
        foreach ($schedules as $schedule) {
            $duration = max(10, (int)$schedule['duracion_minutos']);
            $start = strtotime($schedule['hora_inicio']); $end = strtotime($schedule['hora_fin']);
            for ($minute = $start; $minute + $duration*60 <= $end; $minute += $duration*60) {
                $slotEnd = $minute + $duration*60; $status = 'disponible';
                foreach ($busyRanges as [$busyStart,$busyEnd]) { if ($minute < $busyEnd && $slotEnd > $busyStart) { $status = 'ocupado'; break; } }
                if ($status === 'disponible') {
                    foreach ($heldRanges as [$holdStart,$holdEnd,$mine]) {
                        if ($minute < $holdEnd && $slotEnd > $holdStart) { $status = $mine ? 'en_proceso_propio' : 'en_proceso'; break; }
                    }
                }
                if ($dateText === $now->format('Y-m-d') && $minute <= strtotime($now->format('H:i:s'))) $status = 'no_disponible';
                $label = date('H:i', $minute);
                if (!isset($slotStates[$label]) || $slotStates[$label] === 'disponible' && $status !== 'disponible') $slotStates[$label] = $status;
            }
        }
        ksort($slotStates);
        $slots = array_keys(array_filter($slotStates, fn($status) => $status === 'disponible'));
        $states = [];
        foreach ($slotStates as $hora => $estado) $states[] = ['hora'=>$hora, 'estado'=>$estado];
        respond(['slots'=>$slots, 'slot_states'=>$states, 'agenda'=>$schedules]);
    }

    if ($action === 'mi_turno' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $dni = preg_replace('/\D+/', '', (string)($_GET['dni'] ?? ''));
        if (!preg_match('/^[0-9]{7,8}$/', $dni)) respond(['error'=>'Ingresá un DNI válido de 7 u 8 números.'], 422);
        $patient = $pdo->prepare('SELECT id,nombre,apellido,dni FROM pacientes WHERE dni=? LIMIT 1');
        $patient->execute([$dni]);
        $person = $patient->fetch();
        if (!$person) respond(['found'=>false,'has_turns'=>false,'turns'=>[]]);
        $q = $pdo->prepare("SELECT t.id, CONCAT(pa.nombre,' ',pa.apellido) AS paciente, pa.dni, e.nombre AS especialidad, COALESCE(p.nombre,'Profesional no asignado') AS profesional, DATE_FORMAT(t.fecha,'%d/%m/%Y') AS fecha, DATE_FORMAT(t.fecha,'%Y-%m-%d') AS fecha_iso, TIME_FORMAT(t.hora,'%H:%i') AS hora, t.motivo, t.estado FROM turnos t JOIN pacientes pa ON pa.id=t.paciente_id JOIN especialidades e ON e.id=t.especialidad_id LEFT JOIN profesionales p ON p.id=t.profesional_id WHERE pa.id=? ORDER BY t.fecha DESC,t.hora DESC,t.id DESC");
        $q->execute([(int)$person['id']]);
        $turns=$q->fetchAll();
        respond(['found'=>true,'has_turns'=>count($turns)>0,'patient'=>['nombre'=>$person['nombre'],'apellido'=>$person['apellido'],'dni'=>$person['dni']],'turns'=>$turns]);
    }

    if ($action === 'mi_turno_cancelar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data=inputData();
        $dni=preg_replace('/\D+/', '', (string)($data['dni'] ?? ''));
        $id=(int)($data['id'] ?? 0);
        if (!preg_match('/^[0-9]{7,8}$/',$dni) || $id<1) respond(['error'=>'Revisá el DNI y el turno seleccionado.'],422);
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT t.id,t.estado,p.id AS paciente_id FROM turnos t JOIN pacientes p ON p.id=t.paciente_id WHERE t.id=? AND p.dni=? FOR UPDATE');
            $q->execute([$id,$dni]); $turn=$q->fetch();
            if (!$turn) { $pdo->rollBack(); respond(['error'=>'No se encontró un turno asociado a ese DNI.'],404); }
            if ($turn['estado']!=='Confirmado') { $pdo->rollBack(); respond(['error'=>'Este turno ya no admite cancelación porque su estado es '.$turn['estado'].'.'],409); }
            $upd=$pdo->prepare("UPDATE turnos SET estado='Cancelado' WHERE id=? AND paciente_id=? AND estado='Confirmado'");
            $upd->execute([$id,(int)$turn['paciente_id']]);
            if ($upd->rowCount()!==1) { $pdo->rollBack(); respond(['error'=>'No se pudo cancelar el turno. Actualizá la lista e intentá nuevamente.'],409); }
            $detail=$pdo->prepare("SELECT CONCAT(pa.nombre,' ',pa.apellido) AS paciente,e.nombre AS especialidad,DATE_FORMAT(t.fecha,'%d/%m/%Y') AS fecha,TIME_FORMAT(t.hora,'%H:%i') AS hora FROM turnos t JOIN pacientes pa ON pa.id=t.paciente_id JOIN especialidades e ON e.id=t.especialidad_id WHERE t.id=?");
            $detail->execute([$id]); $d=$detail->fetch();
            $msg='Turno cancelado por '.$d['paciente'].' · '.$d['especialidad'].' · '.$d['fecha'].' '.$d['hora'];
            $n=$pdo->prepare("INSERT INTO notificaciones (turno_id,tipo,mensaje) VALUES (?, 'turno_cancelado', ?) ON DUPLICATE KEY UPDATE mensaje=VALUES(mensaje),leida=0,creado_en=CURRENT_TIMESTAMP"); $n->execute([$id,$msg]);
            $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['El paciente canceló el turno #'.$id.' desde Mi Turno']);
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        respond(['ok'=>true,'message'=>'El turno fue cancelado correctamente.']);
    }

    // Al seleccionar una hora, se bloquea temporalmente durante 10 minutos.
    if ($action === 'tomar_horario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = inputData();
        $professional = trim((string)($data['professional'] ?? ''));
        $specialty = trim((string)($data['specialty'] ?? ''));
        $dateText = trim((string)($data['date'] ?? ''));
        $timeText = trim((string)($data['time'] ?? ''));
        $date = DateTime::createFromFormat('!Y-m-d', $dateText);
        if (!$professional || !$specialty || !$date || $date->format('Y-m-d') !== $dateText || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeText) || $date < new DateTime('today')) {
            respond(['error'=>'La fecha o el horario elegido no son válidos. Actualizá la disponibilidad e intentá nuevamente.'], 422);
        }
        $pairQuery = $pdo->prepare("SELECT p.id AS profesional_id, e.id AS especialidad_id FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id AND pe.activo=1 JOIN especialidades e ON e.id=pe.especialidad_id AND e.activa=1 WHERE p.nombre=? AND e.nombre=? AND p.activo=1 LIMIT 1");
        $pairQuery->execute([$professional, $specialty]);
        $pair = $pairQuery->fetch();
        if (!$pair) respond(['error'=>'El profesional o la especialidad ya no están disponibles.'], 409);
        $professionalId = (int)$pair['profesional_id'];
        $specialtyId = (int)$pair['especialidad_id'];
        $weekday = (int)$date->format('N');
        $schedule = $pdo->prepare('SELECT duracion_minutos FROM horarios_profesionales WHERE profesional_id=? AND especialidad_id=? AND dia_semana=? AND activo=1 AND hora_inicio<=? AND ADDTIME(?, SEC_TO_TIME(duracion_minutos*60))<=hora_fin ORDER BY duracion_minutos LIMIT 1');
        $schedule->execute([$professionalId, $specialtyId, $weekday, $timeText, $timeText]);
        $duration = (int)$schedule->fetchColumn();
        if ($duration < 1) respond(['error'=>'Ese horario ya no pertenece a la agenda habilitada. Actualizá la disponibilidad.'], 409);
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM profesionales WHERE id=? AND activo=1 FOR UPDATE');
            $lock->execute([$professionalId]);
            if (!$lock->fetchColumn()) { $pdo->rollBack(); respond(['error'=>'El profesional ya no está disponible.'], 409); }
            $pdo->prepare('DELETE FROM reservas_horarios_temporales WHERE vence_en <= NOW()')->execute();
            // Una sesión mantiene una sola hora temporal a la vez.
            $pdo->prepare('DELETE FROM reservas_horarios_temporales WHERE propietario_sesion=?')->execute([reservationOwner()]);
            $booked = $pdo->prepare("SELECT COUNT(*) FROM turnos WHERE profesional_id=? AND fecha=? AND estado <> 'Cancelado' AND ADDTIME(hora, SEC_TO_TIME(COALESCE(duracion_minutos,30)*60)) > ? AND hora < ADDTIME(?, SEC_TO_TIME(?*60))");
            $booked->execute([$professionalId, $dateText, $timeText, $timeText, $duration]);
            $held = $pdo->prepare("SELECT COUNT(*) FROM reservas_horarios_temporales WHERE profesional_id=? AND fecha=? AND vence_en>NOW() AND ADDTIME(hora, SEC_TO_TIME(duracion_minutos*60)) > ? AND hora < ADDTIME(?, SEC_TO_TIME(?*60))");
            $held->execute([$professionalId, $dateText, $timeText, $timeText, $duration]);
            if ((int)$booked->fetchColumn() > 0) { $pdo->rollBack(); respond(['error'=>'Ese horario acaba de ser ocupado por un turno confirmado. Elegí otro horario.'], 409); }
            if ((int)$held->fetchColumn() > 0) { $pdo->rollBack(); respond(['error'=>'Otra persona ya seleccionó ese horario y está completando sus datos. Probá con otro horario o esperá a que se libere.'], 409); }
            $insert = $pdo->prepare('INSERT INTO reservas_horarios_temporales (profesional_id, especialidad_id, fecha, hora, duracion_minutos, propietario_sesion, vence_en) VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
            $insert->execute([$professionalId, $specialtyId, $dateText, $timeText, $duration, reservationOwner()]);
            $pdo->commit();
            respond(['ok'=>true, 'message'=>'Horario reservado temporalmente por 10 minutos.']);
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error->getCode() === '23000') respond(['error'=>'Otra persona ya seleccionó ese horario y está completando sus datos. Probá con otro horario o esperá a que se libere.'], 409);
            throw $error;
        }
    }

    if ($action === 'renovar_horario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $q = $pdo->prepare('UPDATE reservas_horarios_temporales SET vence_en=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE propietario_sesion=? AND vence_en>NOW()');
        $q->execute([reservationOwner()]);
        respond(['ok'=>true, 'renovada'=>$q->rowCount()>0]);
    }

    if ($action === 'liberar_horario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare('DELETE FROM reservas_horarios_temporales WHERE propietario_sesion=?')->execute([reservationOwner()]);
        respond(['ok'=>true]);
    }

    if ($action === 'reservar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = inputData();
        foreach (['name','surname','dni','phone','email','specialty','professional','date','time'] as $field) {
            if (empty(trim((string)($data[$field] ?? '')))) respond(['error' => 'Completá todos los campos obligatorios.'], 422);
        }
        if (!preg_match('/^[0-9]{7,8}$/', (string)$data['dni'])) respond(['error' => 'El DNI debe contener únicamente números (7 u 8 dígitos).'], 422);
        if (!preg_match('/^[0-9]{8,15}$/', (string)$data['phone'])) respond(['error' => 'El teléfono debe contener únicamente números (entre 8 y 15 dígitos).'], 422);
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) respond(['error' => 'Ingresá un correo electrónico válido.'], 422);
        $date = DateTime::createFromFormat('!Y-m-d', $data['date']);
        if (!$date || $date->format('Y-m-d') !== $data['date'] || $date < new DateTime('today')) respond(['error' => 'Elegí una fecha válida, desde hoy en adelante.'], 422);
        $time = DateTime::createFromFormat('!H:i', $data['time']);
        if (!$time) respond(['error' => 'Elegí un horario válido.'], 422);
        $specialtyQuery = $pdo->prepare('SELECT id FROM especialidades WHERE nombre=? AND activa=1');
        $specialtyQuery->execute([$data['specialty']]);
        $specialtyId = $specialtyQuery->fetchColumn();
        if (!$specialtyId) respond(['error' => 'La especialidad ya no está disponible. Actualizá la página e intentá nuevamente.'], 422);
        $professionalQuery = $pdo->prepare('SELECT p.id FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id WHERE p.nombre=? AND pe.especialidad_id=? AND p.activo=1 LIMIT 1');
        $professionalQuery->execute([$data['professional'], $specialtyId]);
        $professionalId = (int)$professionalQuery->fetchColumn();
        if (!$professionalId) respond(['error' => 'Elegí un profesional activo vinculado a esa especialidad. No se puede confirmar un turno sin profesional asignado.'], 422);

        $pdo->beginTransaction();
        // Bloquear la fila del profesional serializa las solicitudes simultáneas de su agenda.
        $lock = $pdo->prepare('SELECT id FROM profesionales WHERE id=? AND activo=1 FOR UPDATE');
        $lock->execute([$professionalId]);
        if (!$lock->fetchColumn()) { $pdo->rollBack(); respond(['error' => 'El profesional ya no está disponible. Actualizá la página e intentá nuevamente.'], 409); }

        // La reserva solo se acepta dentro de una franja real configurada para ese profesional y especialidad.
        $weekday = (int)$date->format('N'); // 1=lunes, 2=martes, ... 7=domingo
        $schedule = $pdo->prepare('SELECT duracion_minutos FROM horarios_profesionales WHERE profesional_id=? AND especialidad_id=? AND dia_semana=? AND activo=1 AND hora_inicio<=? AND ADDTIME(?, SEC_TO_TIME(duracion_minutos*60))<=hora_fin ORDER BY duracion_minutos LIMIT 1');
        $schedule->execute([$professionalId, $specialtyId, $weekday, $data['time'], $data['time']]);
        $duration = (int)$schedule->fetchColumn();
        if ($duration < 1) {
            $pdo->rollBack();
            respond(['error' => 'Ese horario no está dentro de la agenda configurada para este profesional y especialidad. Elegí una franja habilitada.'], 409);
        }
        $busy = $pdo->prepare("SELECT COUNT(*) FROM turnos t WHERE t.profesional_id=? AND t.fecha=? AND t.estado <> 'Cancelado' AND ADDTIME(t.hora, SEC_TO_TIME(COALESCE(t.duracion_minutos,30)*60)) > ? AND t.hora < ADDTIME(?, SEC_TO_TIME(?*60))");
        $busy->execute([$professionalId, $data['date'], $data['time'], $data['time'], $duration]);
        if ((int)$busy->fetchColumn() > 0) { $pdo->rollBack(); respond(['error' => 'Ese horario se superpone con otro turno del profesional, aunque sea de otra especialidad. Elegí otro horario.'], 409); }
        // La confirmación exige una reserva temporal propia, todavía vigente y del horario exacto.
        // No se permite confirmar si el bloqueo venció, aunque el horario parezca libre al volver a consultar.
        $ownHold = $pdo->prepare('SELECT id FROM reservas_horarios_temporales WHERE propietario_sesion=? AND profesional_id=? AND especialidad_id=? AND fecha=? AND hora=? AND vence_en>NOW() FOR UPDATE');
        $ownHold->execute([reservationOwner(), $professionalId, $specialtyId, $data['date'], $data['time']]);
        $ownHoldId = $ownHold->fetchColumn();
        if (!$ownHoldId) {
            $pdo->rollBack();
            respond(['error'=>'Tu reserva temporal venció o ya no está activa. Volvé a elegir un horario disponible para continuar.'], 409);
        }
        $otherHold = $pdo->prepare('SELECT COUNT(*) FROM reservas_horarios_temporales WHERE profesional_id=? AND fecha=? AND propietario_sesion<>? AND vence_en>NOW() AND ADDTIME(hora, SEC_TO_TIME(duracion_minutos*60))>? AND hora<ADDTIME(?, SEC_TO_TIME(?*60))');
        $otherHold->execute([$professionalId, $data['date'], reservationOwner(), $data['time'], $data['time'], $duration]);
        if ((int)$otherHold->fetchColumn() > 0) {
            $pdo->rollBack();
            respond(['error'=>'Otra persona está completando la reserva de ese horario. Elegí otro horario.'], 409);
        }
        $obraSocial = trim((string)($data['obra_social'] ?? ''));
        $obraOtra = trim((string)($data['obra_social_otra'] ?? ''));
        $validObras = ['', 'IOSCOR', 'OSECAC', 'ODFE', 'PAMI', 'Otras', 'Ninguna'];
        if (!in_array($obraSocial, $validObras, true)) respond(['error'=>'Elegí una obra social válida.'], 422);
        if ($obraSocial === 'Otras' && $obraOtra === '') respond(['error'=>'Escribí el nombre de la obra social.'], 422);
        if ($obraSocial !== 'Otras') $obraOtra = '';
        $patientQuery = $pdo->prepare('SELECT id FROM pacientes WHERE dni=?');
        $patientQuery->execute([$data['dni']]);
        $patientId = $patientQuery->fetchColumn();
        if ($patientId) {
            $existingPatient = $pdo->prepare('SELECT nombre, apellido FROM pacientes WHERE id=? FOR UPDATE');
            $existingPatient->execute([(int)$patientId]);
            $existing = $existingPatient->fetch();
            $normalizeName = static fn($value) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$value)), 'UTF-8');
            if (!$existing || $normalizeName($existing['nombre']) !== $normalizeName($data['name']) || $normalizeName($existing['apellido']) !== $normalizeName($data['surname'])) {
                $pdo->rollBack();
                respond(['error'=>'Los datos ingresados no coinciden con el registro existente. Verificá tu nombre, apellido y DNI.'], 409);
            }
            $updatePatient = $pdo->prepare('UPDATE pacientes SET telefono=?, email=?, obra_social=?, obra_social_otra=? WHERE id=?');
            $updatePatient->execute([$data['phone'], $data['email'], ($obraSocial !== '' ? $obraSocial : null), ($obraOtra !== '' ? $obraOtra : null), $patientId]);
        } else {
            $insertPatient = $pdo->prepare('INSERT INTO pacientes (nombre, apellido, dni, telefono, email, obra_social, obra_social_otra) VALUES (?,?,?,?,?,?,?)');
            $insertPatient->execute([$data['name'], $data['surname'], $data['dni'], $data['phone'], $data['email'], ($obraSocial !== '' ? $obraSocial : null), ($obraOtra !== '' ? $obraOtra : null)]);
            $patientId = (int)$pdo->lastInsertId();
        }
        $insertAppointment = $pdo->prepare("INSERT INTO turnos (paciente_id, especialidad_id, profesional_id, fecha, hora, duracion_minutos, motivo, estado) VALUES (?,?,?,?,?,?,?, 'Confirmado')");
        $insertAppointment->execute([$patientId, $specialtyId, $professionalId, $data['date'], $data['time'], $duration, trim((string)($data['reason'] ?? ''))]);
        $appointmentId = (int)$pdo->lastInsertId();
        $pdo->prepare('DELETE FROM reservas_horarios_temporales WHERE id=? AND propietario_sesion=?')->execute([(int)$ownHoldId, reservationOwner()]);
        $log = $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)');
        $log->execute(['Solicitud de turno #' . $appointmentId . ' recibida']);
        $notice = $pdo->prepare("INSERT INTO notificaciones (turno_id,tipo,mensaje) VALUES (?, 'nuevo_turno', ?)");
        $notice->execute([$appointmentId, 'Nueva solicitud de turno: ' . trim($data['name'] . ' ' . $data['surname']) . ' · ' . $data['specialty'] . ' · ' . $data['date'] . ' ' . $data['time']]);
        $pdo->commit();
        // Token aleatorio para que el paciente pueda cancelar este turno desde esta misma sesión.
        $cancelToken = bin2hex(random_bytes(24));
        $_SESSION['turnos_cancelables'][$appointmentId] = password_hash($cancelToken, PASSWORD_DEFAULT);
        respond(['ok' => true, 'id' => $appointmentId, 'cancel_token' => $cancelToken], 201);
    }

    // El paciente puede cancelar únicamente un turno que reservó en esta sesión.
    if ($action === 'cancelar_turno' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = inputData();
        $id = (int)($data['id'] ?? 0);
        $token = (string)($data['token'] ?? '');
        $savedHash = $_SESSION['turnos_cancelables'][$id] ?? '';
        if (!$id || !$savedHash || !$token || !password_verify($token, $savedHash)) {
            respond(['error' => 'No se pudo verificar que este turno te pertenece. Para cancelar desde otro dispositivo se necesita una verificación adicional.'], 403);
        }
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT estado FROM turnos WHERE id=? FOR UPDATE");
            $check->execute([$id]);
            $status = $check->fetchColumn();
            if (!$status) { $pdo->rollBack(); respond(['error' => 'No se encontró el turno.'], 404); }
            if ($status === 'Cancelado') { $pdo->rollBack(); respond(['error' => 'Este turno ya está cancelado.'], 409); }
            $update = $pdo->prepare("UPDATE turnos SET estado='Cancelado' WHERE id=?");
            $update->execute([$id]);
            $details = $pdo->prepare("SELECT CONCAT(pa.nombre,' ',pa.apellido) AS paciente,e.nombre AS especialidad,DATE_FORMAT(t.fecha,'%d/%m/%Y') AS fecha,TIME_FORMAT(t.hora,'%H:%i') AS hora FROM turnos t JOIN pacientes pa ON pa.id=t.paciente_id JOIN especialidades e ON e.id=t.especialidad_id WHERE t.id=?");
            $details->execute([$id]); $event = $details->fetch();
            if ($event) {
                $message = 'Turno cancelado por ' . $event['paciente'] . ' · ' . $event['especialidad'] . ' · ' . $event['fecha'] . ' ' . $event['hora'];
                $n=$pdo->prepare("INSERT INTO notificaciones (turno_id,tipo,mensaje) VALUES (?, 'turno_cancelado', ?) ON DUPLICATE KEY UPDATE mensaje=VALUES(mensaje),leida=0,creado_en=CURRENT_TIMESTAMP");
                $n->execute([$id, $message]);
            }
            $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['El paciente canceló el turno #' . $id]);
            $pdo->commit();
            unset($_SESSION['turnos_cancelables'][$id]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(['ok' => true, 'message' => 'Tu turno fue cancelado correctamente.']);
    }

    requireAdmin();
    if ($action === 'agenda_profesional') {
        requireAdmin();
        $id=(int)($_GET['id']??$_POST['id']??inputData()['id']??0);
        if($id<1) respond(['error'=>'Profesional inválido.'],422);
        $q=$pdo->prepare('SELECT id,nombre FROM profesionales WHERE id=? AND activo=1');$q->execute([$id]);$pro=$q->fetch();if(!$pro)respond(['error'=>'No se encontró el profesional.'],404);
        $q=$pdo->prepare('SELECT DISTINCT dia_semana,TIME_FORMAT(hora_inicio,\'%H:%i\') AS hora_inicio,TIME_FORMAT(hora_fin,\'%H:%i\') AS hora_fin,duracion_minutos FROM horarios_profesionales WHERE profesional_id=? AND activo=1 ORDER BY dia_semana,hora_inicio');$q->execute([$id]);$rows=$q->fetchAll();
        $q=$pdo->prepare('SELECT DISTINCT especialidad_id FROM profesional_especialidades WHERE profesional_id=?');$q->execute([$id]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        respond(['id'=>(int)$pro['id'],'nombre'=>$pro['nombre'],'horarios'=>$rows,'especialidad_ids'=>$ids,'intervalo_minutos'=>(int)($rows[0]['duracion_minutos']??30)]);
    }
    if ($action === 'editar_agenda_profesional' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();$d=inputData();$id=(int)($d['id']??0);$nombre=trim((string)($d['nombre']??''));$ids=array_values(array_unique(array_filter(array_map('intval',(array)($d['especialidad_ids']??[])))));$horarios=(array)($d['horarios']??[]);$intervalo=(int)($d['intervalo_minutos']??30);
        if($id<1||$nombre===''||!$ids||!$horarios)respond(['error'=>'Completá nombre, especialidades y al menos un horario.'],422);
        if($intervalo<10||$intervalo>120)respond(['error'=>'La duración de consulta no es válida.'],422);
        $days=[];foreach($horarios as $h){$day=(int)($h['dia']??0);$a=trim((string)($h['hora_inicio']??''));$b=trim((string)($h['hora_fin']??''));if($day<1||$day>7||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$a)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$b)||$a>=$b)respond(['error'=>'Revisá los días y rangos horarios; cada franja debe tener un horario válido.'],422);$days[$day][]=[$a,$b];}foreach($days as $day=>$ranges){if(count($ranges)>2)respond(['error'=>'Se permiten como máximo dos franjas por día.'],422);usort($ranges,fn($a,$b)=>strcmp($a[0],$b[0]));for($i=1;$i<count($ranges);$i++)if($ranges[$i][0]<$ranges[$i-1][1])respond(['error'=>'Los horarios del mismo día no pueden superponerse.'],422);$days[$day]=$ranges;}
        $marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id FROM especialidades WHERE activa=1 AND id IN ($marks)");$q->execute($ids);if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($ids))respond(['error'=>'Una de las especialidades no está disponible.'],422);
        $pdo->beginTransaction();$q=$pdo->prepare('UPDATE profesionales SET nombre=?,especialidad_id=? WHERE id=? AND activo=1');$q->execute([$nombre,$ids[0],$id]);$pdo->prepare('DELETE FROM profesional_especialidades WHERE profesional_id=?')->execute([$id]);$link=$pdo->prepare('INSERT INTO profesional_especialidades (profesional_id,especialidad_id) VALUES (?,?)');foreach($ids as $sid)$link->execute([$id,$sid]);$pdo->prepare('DELETE FROM horarios_profesionales WHERE profesional_id=?')->execute([$id]);$agenda=$pdo->prepare('INSERT INTO horarios_profesionales (profesional_id,especialidad_id,dia_semana,hora_inicio,hora_fin,duracion_minutos,activo) VALUES (?,?,?,?,?,?,1)');foreach($ids as $sid)foreach($days as $day=>$ranges)foreach($ranges as $range)$agenda->execute([$id,$sid,$day,$range[0],$range[1],$intervalo]);$pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Agenda profesional actualizada: '.$nombre]);$pdo->commit();respond(['ok'=>true]);
    }
    if ($action === 'admin_data') {
        requireAdmin();
        $appointments = $pdo->query("SELECT t.id, pa.dni AS dni_paciente, CONCAT(pa.nombre,' ',pa.apellido) AS paciente, e.nombre AS especialidad, p.nombre AS profesional, DATE_FORMAT(t.fecha,'%d/%m/%Y') AS fecha, TIME_FORMAT(t.hora,'%H:%i') AS hora, t.estado, CONCAT(LEFT(pa.nombre,1),LEFT(pa.apellido,1)) AS iniciales FROM turnos t JOIN pacientes pa ON pa.id=t.paciente_id JOIN especialidades e ON e.id=t.especialidad_id LEFT JOIN profesionales p ON p.id=t.profesional_id ORDER BY t.fecha DESC,t.hora DESC")->fetchAll();
        $patients = $pdo->query("SELECT pa.id,pa.nombre,pa.apellido,pa.dni,pa.telefono,pa.email,pa.obra_social,pa.obra_social_otra, (SELECT DATE_FORMAT(MAX(t.fecha),'%d/%m/%Y') FROM turnos t WHERE t.paciente_id=pa.id) AS ultimo_turno, (SELECT t.id FROM turnos t WHERE t.paciente_id=pa.id ORDER BY t.fecha DESC,t.hora DESC,t.id DESC LIMIT 1) AS ultimo_turno_id, (SELECT t.estado FROM turnos t WHERE t.paciente_id=pa.id ORDER BY t.fecha DESC,t.hora DESC,t.id DESC LIMIT 1) AS estado_turno FROM pacientes pa ORDER BY pa.apellido,pa.nombre")->fetchAll();
        $professionals = $pdo->query("SELECT p.id,p.nombre,GROUP_CONCAT(DISTINCT e.nombre ORDER BY e.nombre SEPARATOR ', ') AS especialidad, (SELECT GROUP_CONCAT(DISTINCT CONCAT(CASE h.dia_semana WHEN 1 THEN 'Lun' WHEN 2 THEN 'Mar' WHEN 3 THEN 'Mié' WHEN 4 THEN 'Jue' WHEN 5 THEN 'Vie' WHEN 6 THEN 'Sáb' ELSE 'Dom' END,' ',TIME_FORMAT(h.hora_inicio,'%H:%i'),'-',TIME_FORMAT(h.hora_fin,'%H:%i')) ORDER BY h.dia_semana SEPARATOR ' · ') FROM horarios_profesionales h WHERE h.profesional_id=p.id AND h.activo=1) AS agenda, CONCAT(LEFT(p.nombre,1),LEFT(SUBSTRING_INDEX(p.nombre,' ',-1),1)) AS iniciales FROM profesionales p JOIN profesional_especialidades pe ON pe.profesional_id=p.id JOIN especialidades e ON e.id=pe.especialidad_id WHERE p.activo=1 GROUP BY p.id,p.nombre ORDER BY p.nombre")->fetchAll();
        $specialties = $pdo->query('SELECT id,nombre,descripcion FROM especialidades WHERE activa=1 ORDER BY nombre')->fetchAll();
        $activity = $pdo->query("SELECT descripcion, DATE_FORMAT(creado_en, '%d/%m/%Y %H:%i') AS fecha FROM actividad ORDER BY id DESC LIMIT 20")->fetchAll();
        $notifications = $pdo->query("SELECT n.id,n.turno_id,n.tipo,n.mensaje,n.leida,DATE_FORMAT(n.creado_en,'%d/%m/%Y %H:%i') AS fecha FROM notificaciones n ORDER BY n.id DESC LIMIT 100")->fetchAll();
        $stats = [
            'today' => (int)$pdo->query("SELECT COUNT(*) FROM turnos WHERE fecha=CURDATE() AND estado <> 'Cancelado'")->fetchColumn(),
            'pending' => (int)$pdo->query("SELECT COUNT(*) FROM turnos WHERE estado='Confirmado' AND fecha>=CURDATE()")->fetchColumn(),
            'patients' => count($patients),
            'professionals' => count($professionals)
        ];
        respond(compact('appointments','patients','professionals','specialties','stats','activity','notifications'));
    }
    if ($action === 'marcar_notificacion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData();$id=(int)($d['id']??0);if($id<1)respond(['error'=>'Notificación inválida.'],422);$q=$pdo->prepare('UPDATE notificaciones SET leida=1 WHERE id=?');$q->execute([$id]);if(!$q->rowCount()){ $check=$pdo->prepare('SELECT id FROM notificaciones WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())respond(['error'=>'No se encontró la notificación.'],404);}respond(['ok'=>true]);
    }
    if ($action === 'marcar_todas_notificaciones' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $pdo->exec('UPDATE notificaciones SET leida=1 WHERE leida=0');respond(['ok'=>true]);
    }
    if ($action === 'cambiar_estado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $data = inputData();
        if (!in_array($data['estado'] ?? '', ['Confirmado','Atendido'], true)) respond(['error'=>'Elegí un estado válido. Los turnos cancelados se gestionan desde la opción de cancelación.'],422);
        $update = $pdo->prepare('UPDATE turnos SET estado=? WHERE id=?');
        $update->execute([$data['estado'], (int)($data['id'] ?? 0)]);
        if (!$update->rowCount()) respond(['error'=>'No se encontró el turno o el estado no cambió.'],404);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Turno #' . (int)$data['id'] . ' actualizado a ' . $data['estado']]);
        respond(['ok'=>true]);
    }
    if ($action === 'agregar_paciente' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData();
        foreach(['nombre','apellido','dni'] as $f) if(empty(trim((string)($d[$f]??'')))) respond(['error'=>'Nombre, apellido y DNI son obligatorios.'],422);
        if (!preg_match('/^[0-9]{7,8}$/', (string)$d['dni'])) respond(['error'=>'El DNI debe contener únicamente números (7 u 8 dígitos).'],422);
        if (isset($d['telefono']) && trim((string)$d['telefono']) !== '' && !preg_match('/^[0-9]{8,15}$/', (string)$d['telefono'])) respond(['error'=>'El teléfono debe contener únicamente números (entre 8 y 15 dígitos).'],422);
        if (trim((string)($d['email']??'')) !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) respond(['error'=>'Ingresá un correo electrónico válido.'],422);
        $obra=trim((string)($d['obra_social']??''));$otra=trim((string)($d['obra_social_otra']??''));
        if(!in_array($obra,['','IOSCOR','OSECAC','ODFE','PAMI','Otras','Ninguna'],true))respond(['error'=>'Elegí una obra social válida.'],422);
        if($obra==='Otras'&&$otra==='')respond(['error'=>'Indicá el nombre de la obra social elegida en “Otras”.'],422);if($obra!=='Otras')$otra='';
        $q=$pdo->prepare('INSERT INTO pacientes (nombre,apellido,dni,telefono,email,obra_social,obra_social_otra) VALUES (?,?,?,?,?,?,?)');
        $q->execute([trim($d['nombre']),trim($d['apellido']),trim($d['dni']),trim($d['telefono']??''),trim($d['email']??''),$obra!==''?$obra:null,$otra!==''?$otra:null]);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Paciente agregado: ' . trim($d['nombre']) . ' ' . trim($d['apellido'])]);
        respond(['ok'=>true],201);
    }
    if ($action === 'editar_paciente' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData(); $id=(int)($d['id']??0);
        foreach(['nombre','apellido','dni'] as $f) if(trim((string)($d[$f]??''))==='') respond(['error'=>'Nombre, apellido y DNI son obligatorios.'],422);
        if(!preg_match('/^[0-9]{7,8}$/',(string)$d['dni'])) respond(['error'=>'El DNI debe contener únicamente números (7 u 8 dígitos).'],422);
        if(trim((string)($d['telefono']??''))!=='' && !preg_match('/^[0-9]{8,15}$/',(string)$d['telefono'])) respond(['error'=>'El teléfono debe contener únicamente números (entre 8 y 15 dígitos).'],422);
        if(trim((string)($d['email']??''))!=='' && !filter_var($d['email'],FILTER_VALIDATE_EMAIL)) respond(['error'=>'Ingresá un correo electrónico válido.'],422);
        $obra=trim((string)($d['obra_social']??''));$otra=trim((string)($d['obra_social_otra']??''));
        if(!in_array($obra,['','IOSCOR','OSECAC','ODFE','PAMI','Otras','Ninguna'],true))respond(['error'=>'Elegí una obra social válida.'],422);
        if($obra==='Otras'&&$otra==='')respond(['error'=>'Indicá el nombre de la obra social elegida en “Otras”.'],422);if($obra!=='Otras')$otra='';
        $q=$pdo->prepare('UPDATE pacientes SET nombre=?,apellido=?,dni=?,telefono=?,email=?,obra_social=?,obra_social_otra=? WHERE id=?'); $q->execute([trim($d['nombre']),trim($d['apellido']),trim($d['dni']),trim((string)($d['telefono']??'')),trim((string)($d['email']??'')),$obra!==''?$obra:null,$otra!==''?$otra:null,$id]);
        if(!$q->rowCount()) { $c=$pdo->prepare('SELECT id FROM pacientes WHERE id=?');$c->execute([$id]);if(!$c->fetchColumn())respond(['error'=>'No se encontró el paciente.'],404); }
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Datos de paciente actualizados #' . $id]); respond(['ok'=>true]);
    }
    if ($action === 'eliminar_paciente' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData();$id=(int)($d['id']??0);$c=$pdo->prepare('SELECT id FROM pacientes WHERE id=?');$c->execute([$id]);if(!$c->fetchColumn())respond(['error'=>'No se encontró el paciente.'],404);
        $t=$pdo->prepare('SELECT COUNT(*) FROM turnos WHERE paciente_id=?');$t->execute([$id]);if((int)$t->fetchColumn()>0)respond(['error'=>'No se puede eliminar porque el paciente tiene turnos asociados. Conservá su historial y modificá sus datos si es necesario.'],409);
        $pdo->prepare('DELETE FROM pacientes WHERE id=?')->execute([$id]);$pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Paciente eliminado #' . $id]);respond(['ok'=>true]);
    }
    if ($action === 'agregar_especialidad' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData(); $nombre=trim((string)($d['nombre']??''));
        if($nombre==='') respond(['error'=>'El nombre es obligatorio.'],422);
        $q=$pdo->prepare('INSERT INTO especialidades (nombre,descripcion) VALUES (?,?)'); $q->execute([$nombre,trim((string)($d['descripcion']??''))]);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Especialidad agregada: '.$nombre]);
        respond(['ok'=>true],201);
    }
    if ($action === 'editar_especialidad' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData();$id=(int)($d['id']??0);$nombre=trim((string)($d['nombre']??''));if(!$id||$nombre==='')respond(['error'=>'El nombre de la especialidad es obligatorio.'],422);
        $q=$pdo->prepare('UPDATE especialidades SET nombre=?,descripcion=? WHERE id=? AND activa=1');$q->execute([$nombre,trim((string)($d['descripcion']??'')),$id]);
        $c=$pdo->prepare('SELECT id FROM especialidades WHERE id=? AND activa=1');$c->execute([$id]);if(!$c->fetchColumn())respond(['error'=>'No se encontró la especialidad.'],404);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Especialidad actualizada: '.$nombre]);respond(['ok'=>true]);
    }
    if ($action === 'agregar_profesional' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d=inputData(); $nombre=trim((string)($d['nombre']??''));
        $ids=array_values(array_unique(array_filter(array_map('intval', (array)($d['especialidad_ids']??[])))));
        $names=$d['especialidades']??[]; if(is_string($names)) $names=preg_split('/\s*,\s*/', trim($names), -1, PREG_SPLIT_NO_EMPTY);
        if (!$ids && is_array($names) && $names) { $marks=implode(',',array_fill(0,count($names),'?')); $nq=$pdo->prepare("SELECT id FROM especialidades WHERE activa=1 AND nombre IN ($marks)"); $nq->execute(array_map('trim',$names)); $ids=array_map('intval',$nq->fetchAll(PDO::FETCH_COLUMN)); }
        if (!$ids && !empty($d['especialidad'])) { $q=$pdo->prepare('SELECT id FROM especialidades WHERE nombre=? AND activa=1'); $q->execute([trim((string)$d['especialidad'])]); $legacyId=(int)$q->fetchColumn(); if($legacyId) $ids=[$legacyId]; }
        if($nombre===''||!$ids) respond(['error'=>'Completá el nombre y elegí al menos una especialidad.'],422);
        $marks=implode(',',array_fill(0,count($ids),'?')); $q=$pdo->prepare("SELECT id FROM especialidades WHERE activa=1 AND id IN ($marks)"); $q->execute($ids); $validIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if(count($validIds)!==count($ids)) respond(['error'=>'Una de las especialidades no existe o está desactivada.'],422);
        $intervalo=(int)($d['intervalo_minutos']??30);$horarios=(array)($d['horarios']??[]);$days=[];
        foreach($horarios as $h){$day=(int)($h['dia']??0);$inicio=trim((string)($h['hora_inicio']??''));$fin=trim((string)($h['hora_fin']??''));if($day<1||$day>7||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$inicio)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$fin)||$inicio >= $fin)respond(['error'=>'Revisá los horarios: cada franja debe tener un rango válido.'],422);$days[$day][]=[$inicio,$fin];}
        foreach($days as $day=>$ranges){if(count($ranges)>2)respond(['error'=>'Se permiten como máximo dos franjas por día.'],422);usort($ranges,fn($a,$b)=>strcmp($a[0],$b[0]));for($i=1;$i<count($ranges);$i++)if($ranges[$i][0]<$ranges[$i-1][1])respond(['error'=>'Los horarios del mismo día no pueden superponerse.'],422);$days[$day]=$ranges;}
        if (!$days) respond(['error'=>'Seleccioná al menos un día de atención.'],422);
        if($intervalo<10||$intervalo>120)respond(['error'=>'La duración de la consulta debe estar entre 10 y 120 minutos.'],422);
        $pdo->beginTransaction();
        $q=$pdo->prepare('INSERT INTO profesionales (nombre,especialidad_id) VALUES (?,?)'); $q->execute([$nombre,$validIds[0]]); $professionalId=(int)$pdo->lastInsertId();
        $link=$pdo->prepare('INSERT INTO profesional_especialidades (profesional_id,especialidad_id) VALUES (?,?)');
        foreach($validIds as $sid) { $link->execute([$professionalId,$sid]); }
        $agenda=$pdo->prepare('INSERT INTO horarios_profesionales (profesional_id,especialidad_id,dia_semana,hora_inicio,hora_fin,duracion_minutos,activo) VALUES (?,?,?,?,?,?,1)');
        foreach($validIds as $sid) foreach($days as $day=>$ranges) foreach($ranges as $range) $agenda->execute([$professionalId,$sid,$day,$range[0],$range[1],$intervalo]);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Profesional agregado: '.$nombre.' · agenda configurada']); $pdo->commit();
        respond(['ok'=>true,'id'=>$professionalId],201);
    }
    if ($action === 'eliminar_especialidad' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d = inputData();
        $id = (int)($d['id'] ?? 0);
        if (!$id) respond(['error' => 'Elegí una especialidad válida.'], 422);
        // Se desactiva en lugar de borrar físicamente para conservar el historial de turnos.
        $q = $pdo->prepare('UPDATE especialidades SET activa=0 WHERE id=? AND activa=1');
        $q->execute([$id]);
        if (!$q->rowCount()) respond(['error' => 'No se encontró la especialidad o ya estaba desactivada.'], 404);
        $pdo->prepare('DELETE FROM profesional_especialidades WHERE especialidad_id=?')->execute([$id]);
        $pdo->exec("UPDATE profesionales p SET p.activo=0 WHERE NOT EXISTS (SELECT 1 FROM profesional_especialidades pe WHERE pe.profesional_id=p.id)");
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Especialidad desactivada #' . $id]);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_profesional' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $d = inputData();
        $id = (int)($d['id'] ?? 0);
        if (!$id) respond(['error' => 'Elegí un profesional válido.'], 422);
        // Se desactiva para que los turnos anteriores sigan mostrando su profesional.
        $q = $pdo->prepare('UPDATE profesionales SET activo=0 WHERE id=? AND activo=1');
        $q->execute([$id]);
        if (!$q->rowCount()) respond(['error' => 'No se encontró el profesional o ya estaba desactivado.'], 404);
        $pdo->prepare('INSERT INTO actividad (descripcion) VALUES (?)')->execute(['Profesional desactivado #' . $id]);
        respond(['ok' => true]);
    }
    respond(['error'=>'Acción no reconocida.'],404);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') respond(['error'=>'Ya existe un registro con esos datos o ese horario.'],409);
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = in_array($host, ['localhost', '127.0.0.1', 'localhost:80', '127.0.0.1:80'], true);
    $message = 'No se pudo guardar en la base de datos. Revisá la instalación y las migraciones SQL.';
    if ($isLocal) $message .= ' Detalle técnico: ' . $error->getMessage();
    respond(['error'=>$message],500);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error'=>'No se pudo completar la operación.'],500);
}
