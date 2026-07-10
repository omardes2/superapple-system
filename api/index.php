<?php
/**
 * نظام سوبر آبل — API الرئيسي (PHP + MySQL)
 * كل الطلبات تمر من هون عبر ?action=...
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bodyInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function currentUserRow($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = $pdo->prepare("SELECT id, name, email, role, department, phone, work_start AS workStart, work_end AS workEnd, join_date AS joinDate FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function requireLogin($pdo) {
    $u = currentUserRow($pdo);
    if (!$u) respond(['error' => 'يجب تسجيل الدخول'], 401);
    return $u;
}

function requireAdmin($pdo) {
    $u = requireLogin($pdo);
    if ($u['role'] !== 'admin') respond(['error' => 'صلاحية المدير مطلوبة'], 403);
    return $u;
}

/* ============ واتساب — Meta Cloud API الرسمي ============ */
function sendWhatsAppCloud($pdo, $phone, $bodyText) {
    if (empty($phone)) return;
    if (!function_exists('curl_init')) return; // امتداد curl غير مفعّل على هذه الاستضافة
    $s = $pdo->query("SELECT whatsapp_phone_id, whatsapp_token, whatsapp_template FROM settings WHERE id = 1")->fetch();
    if (empty($s['whatsapp_phone_id']) || empty($s['whatsapp_token'])) return; // غير مفعّل بعد

    $template = $s['whatsapp_template'] ?: 'hello_world';
    $isHelloWorld = ($template === 'hello_world');

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => preg_replace('/\D/', '', $phone),
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => ['code' => $isHelloWorld ? 'en_US' : 'ar'],
        ],
    ];
    if (!$isHelloWorld) {
        $payload['template']['components'] = [[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => $bodyText]],
        ]];
    }

    $ch = curl_init("https://graph.facebook.com/v20.0/{$s['whatsapp_phone_id']}/messages");
    if ($ch === false) return; // curl غير متاح على هذه الاستضافة
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $s['whatsapp_token']],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// يسجّل إشعارًا داخل النظام + يبعت رسالة واتساب حقيقية بنفس الوقت
function pushNotification($pdo, $userId, $message, $type) {
    $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)")->execute([$userId, $message, $type]);
    $stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u && $u['phone']) sendWhatsAppCloud($pdo, $u['phone'], $message);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    /* ============ حالة النظام العامة (تُستدعى دائمًا عند التحميل) ============ */
    case 'bootstrap': {
        $hasUsers = (bool) $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        $currentUser = currentUserRow($pdo);
        $settingsRow = $pdo->query("SELECT work_start AS workStart, grace_minutes AS graceMinutes,
            points_on_time AS pointsOnTime, points_early_bonus AS pointsEarlyBonus, early_bonus_hours AS earlyBonusHours,
            points_attendance AS pointsAttendance, penalty_late AS penaltyLate, penalty_absent AS penaltyAbsent,
            whatsapp_phone_id AS whatsappPhoneId, whatsapp_token AS whatsappToken, whatsapp_template AS whatsappTemplate
            FROM settings WHERE id = 1")->fetch();

        $payload = ['hasUsers' => $hasUsers, 'currentUser' => $currentUser, 'settings' => $settingsRow ?: null];

        if ($currentUser) {
            $isAdmin = $currentUser['role'] === 'admin';

            $payload['users'] = $pdo->query("SELECT id, name, email, role, department, phone, work_start AS workStart, work_end AS workEnd, join_date AS joinDate FROM users")->fetchAll();

            if ($isAdmin) {
                $payload['clients'] = $pdo->query("SELECT id, name, contact_name AS contactName, phone, email, notes, created_at AS createdAt FROM clients ORDER BY name")->fetchAll();
                $payload['departments'] = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
            }

            $tasks = $pdo->query("SELECT id, title, description, priority, deadline, client_id AS clientId, created_at AS createdAt FROM tasks ORDER BY created_at DESC")->fetchAll();
            $aStmt = $pdo->prepare("SELECT user_id AS userId, done, completed_at AS completedAt FROM task_assignees WHERE task_id = ?");
            foreach ($tasks as &$t) {
                $aStmt->execute([$t['id']]);
                $rows = $aStmt->fetchAll();
                foreach ($rows as &$r) $r['done'] = (bool) $r['done'];
                $t['assignees'] = $rows;
            }
            unset($t);
            if (!$isAdmin) {
                $tasks = array_values(array_filter($tasks, function ($t) use ($currentUser) {
                    foreach ($t['assignees'] as $a) if ($a['userId'] == $currentUser['id']) return true;
                    return false;
                }));
            }
            $payload['tasks'] = $tasks;

            if ($isAdmin) {
                $payload['attendance'] = $pdo->query("SELECT id, user_id AS userId, date, check_in AS checkIn, check_out AS checkOut, status FROM attendance")->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT id, user_id AS userId, date, check_in AS checkIn, check_out AS checkOut, status FROM attendance WHERE user_id = ?");
                $stmt->execute([$currentUser['id']]);
                $payload['attendance'] = $stmt->fetchAll();
            }

            $payload['points'] = $pdo->query("SELECT id, user_id AS userId, points, reason, created_at AS date FROM points")->fetchAll();

            if ($isAdmin) {
                $payload['notifications'] = $pdo->query("SELECT id, user_id AS userId, message, type, created_at AS createdAt FROM notifications ORDER BY created_at DESC")->fetchAll();
                $payload['leaveRequests'] = $pdo->query("SELECT id, user_id AS userId, start_date AS startDate, end_date AS endDate, reason, status, created_at AS createdAt FROM leave_requests ORDER BY created_at DESC")->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT id, user_id AS userId, message, type, created_at AS createdAt FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$currentUser['id']]);
                $payload['notifications'] = $stmt->fetchAll();
                $stmt = $pdo->prepare("SELECT id, user_id AS userId, start_date AS startDate, end_date AS endDate, reason, status, created_at AS createdAt FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$currentUser['id']]);
                $payload['leaveRequests'] = $stmt->fetchAll();
            }
        }
        respond($payload);
    }

    /* ============ الإعداد الأول: إنشاء حساب المدير ============ */
    case 'setupAdmin': {
        $hasUsers = (bool) $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        if ($hasUsers) respond(['error' => 'تم إعداد النظام مسبقًا'], 400);
        $b = bodyInput();
        if (empty($b['name']) || empty($b['email']) || empty($b['password'])) respond(['error' => 'كل الحقول مطلوبة'], 400);
        $hash = password_hash($b['password'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department, join_date) VALUES (?, ?, ?, 'admin', 'الإدارة', CURDATE())");
        $stmt->execute([trim($b['name']), strtolower(trim($b['email'])), $hash]);
        $_SESSION['user_id'] = $pdo->lastInsertId();
        respond(['success' => true]);
    }

    /* ============ تسجيل الدخول / الخروج ============ */
    case 'login': {
        $b = bodyInput();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
        $stmt->execute([strtolower(trim($b['email'] ?? ''))]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) {
            respond(['error' => 'invalid'], 401);
        }
        $_SESSION['user_id'] = $u['id'];
        respond(['success' => true]);
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        respond(['success' => true]);
    }

    /* ============ إدارة الموظفين (مدير فقط) ============ */
    case 'addEmployee': {
        $admin = requireAdmin($pdo);
        $b = bodyInput();
        if (empty($b['name']) || empty($b['email']) || empty($b['password']) || empty($b['phone'])) {
            respond(['error' => 'كل الحقول مطلوبة (بما فيها رقم الواتساب)'], 400);
        }
        $deptName = trim($b['department']) ?: 'عام';
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$deptName]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO departments (name) VALUES (?)")->execute([$deptName]);
        }

        $hash = password_hash($b['password'], PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department, phone, work_start, work_end, join_date) VALUES (?, ?, ?, 'employee', ?, ?, ?, ?, CURDATE())");
            $stmt->execute([
                trim($b['name']), strtolower(trim($b['email'])), $hash,
                $deptName, trim($b['phone']), $b['workStart'] ?: '08:00', $b['workEnd'] ?: '16:00'
            ]);
        } catch (PDOException $e) {
            respond(['error' => 'البريد الإلكتروني مستخدم مسبقًا'], 400);
        }
        $newId = $pdo->lastInsertId();
        pushNotification($pdo, $newId, "مرحبًا " . trim($b['name']) . "! تم إنشاء حسابك في نظام سوبر آبل.", 'welcome');
        respond(['success' => true]);
    }

    case 'updateEmployeeSchedule': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE users SET work_start = ?, work_end = ? WHERE id = ? AND role = 'employee'")
            ->execute([$b['workStart'] ?: '08:00', $b['workEnd'] ?: '16:00', $b['id'] ?? 0]);
        respond(['success' => true]);
    }

    case 'removeEmployee': {
        requireAdmin($pdo);
        $b = bodyInput();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    /* ============ المهام ============ */
    case 'createTask': {
        $admin = requireAdmin($pdo);
        $b = bodyInput();
        if (empty($b['title']) || empty($b['assignees'])) respond(['error' => 'العنوان والموظفون المسندون مطلوبون'], 400);

        // ابحث عن الشركة بالاسم، وإذا ما كانت موجودة أنشئها تلقائيًا
        $clientId = null;
        $clientName = trim($b['clientName'] ?? '');
        if ($clientName !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$clientName]);
            $existing = $stmt->fetch();
            if ($existing) {
                $clientId = $existing['id'];
            } else {
                $pdo->prepare("INSERT INTO clients (name) VALUES (?)")->execute([$clientName]);
                $clientId = $pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, priority, deadline, created_by, client_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([trim($b['title']), trim($b['description'] ?? ''), $b['priority'] ?: 'medium', $b['deadline'] ?: null, $admin['id'], $clientId]);
        $taskId = $pdo->lastInsertId();
        $insA = $pdo->prepare("INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)");
        $deadlineTxt = $b['deadline'] ? date('d/m', strtotime($b['deadline'])) : 'غير محدد';
        foreach ($b['assignees'] as $uid) {
            $insA->execute([$taskId, $uid]);
            pushNotification($pdo, $uid, "مهمة جديدة: \"" . trim($b['title']) . "\" — الموعد النهائي {$deadlineTxt}.", 'task');
        }
        respond(['success' => true]);
    }

    case 'toggleAssignee': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $userId = $b['userId'] ?? 0;
        if ($user['role'] !== 'admin' && $user['id'] != $userId) respond(['error' => 'غير مسموح'], 403);

        $stmt = $pdo->prepare("SELECT done FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $userId]);
        $row = $stmt->fetch();
        if (!$row) respond(['error' => 'المهمة غير موجودة'], 404);
        $newDone = $row['done'] ? 0 : 1;
        $completedAt = $newDone ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE task_assignees SET done = ?, completed_at = ? WHERE task_id = ? AND user_id = ?")
            ->execute([$newDone, $completedAt, $taskId, $userId]);

        if ($newDone) {
            $stmt = $pdo->prepare("SELECT title, deadline FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            $onTime = !$task['deadline'] || strtotime($completedAt) <= strtotime($task['deadline'] . ' 23:59:59');
            if ($onTime) {
                $s = $pdo->query("SELECT points_on_time, points_early_bonus, early_bonus_hours FROM settings WHERE id = 1")->fetch();
                $pts = (int) $s['points_on_time'];
                if ($task['deadline']) {
                    $hoursEarly = (strtotime($task['deadline'] . ' 23:59:59') - strtotime($completedAt)) / 3600;
                    if ($hoursEarly >= (int) $s['early_bonus_hours']) $pts += (int) $s['points_early_bonus'];
                }
                $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, ?)")->execute([$userId, $pts, "إنجاز مهمة: {$task['title']}"]);
                pushNotification($pdo, $userId, "أحسنت! أنجزت \"{$task['title']}\" وحصلت على {$pts} نقطة.", 'points');
            } else {
                pushNotification($pdo, $userId, "أنجزت \"{$task['title']}\" بعد الموعد النهائي.", 'task');
            }
        }
        respond(['success' => true]);
    }

    /* ============ الدوام ============ */
    case 'checkIn': {
        $user = requireLogin($pdo);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        if ($stmt->fetch()) respond(['error' => 'تم تسجيل حضورك اليوم بالفعل']);
        $b = bodyInput();
        $lat = is_numeric($b['lat'] ?? null) ? $b['lat'] : null;
        $lng = is_numeric($b['lng'] ?? null) ? $b['lng'] : null;

        $time = date('H:i:s');
        $s = $pdo->query("SELECT grace_minutes, points_attendance, penalty_late FROM settings WHERE id = 1")->fetch();
        $workStart = $user['workStart'] ?: '08:00:00';
        $isLate = (strtotime($time) > strtotime($workStart) + ((int) $s['grace_minutes'] * 60));
        $status = $isLate ? 'late' : 'present';

        $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, status, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$user['id'], $today, $time, $status, $lat, $lng]);

        if ($isLate) {
            $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, 'تأخير عن موعد الدوام')")->execute([$user['id'], -(int) $s['penalty_late']]);
            pushNotification($pdo, $user['id'], "تم تسجيل حضورك الساعة {$time} (متأخر). تم خصم {$s['penalty_late']} نقطة.", 'late');
        } else {
            $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, 'حضور في الوقت المحدد')")->execute([$user['id'], (int) $s['points_attendance']]);
            pushNotification($pdo, $user['id'], "تم تسجيل حضورك الساعة {$time}. +{$s['points_attendance']} نقطة.", 'checkin');
        }
        respond(['success' => true]);
    }

    case 'checkOut': {
        $user = requireLogin($pdo);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        $rec = $stmt->fetch();
        if (!$rec || $rec['check_out']) respond(['error' => 'لا يوجد تسجيل حضور مفتوح']);
        $time = date('H:i:s');
        $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?")->execute([$time, $rec['id']]);

        $workedHours = round((strtotime($time) - strtotime($rec['check_in'])) / 3600, 1);
        $expectedHours = round((strtotime($user['workEnd'] ?: '16:00:00') - strtotime($user['workStart'] ?: '08:00:00')) / 3600, 1);
        $hoursMsg = $workedHours >= $expectedHours
            ? "أكملت {$workedHours} ساعة من أصل {$expectedHours} المطلوبة ✅"
            : "سجّلت {$workedHours} ساعة فقط من أصل {$expectedHours} المطلوبة (ناقص " . round($expectedHours - $workedHours, 1) . " ساعة)";

        pushNotification($pdo, $user['id'], "تم تسجيل انصرافك الساعة {$time}. {$hoursMsg}", 'checkout');
        respond(['success' => true]);
    }

    case 'markAbsent': {
        requireAdmin($pdo);
        $b = bodyInput();
        $userId = $b['userId'] ?? 0;
        $date = $b['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        $stmt->execute([$userId, $date]);
        if ($stmt->fetch()) respond(['error' => 'يوجد سجل لهذا اليوم بالفعل']);
        $pdo->prepare("INSERT INTO attendance (user_id, date, status) VALUES (?, ?, 'absent')")->execute([$userId, $date]);
        $s = $pdo->query("SELECT penalty_absent FROM settings WHERE id = 1")->fetch();
        $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, 'غياب بدون تسجيل حضور')")->execute([$userId, -(int) $s['penalty_absent']]);
        pushNotification($pdo, $userId, "تم تسجيلك غائبًا بتاريخ {$date}. تم خصم {$s['penalty_absent']} نقطة.", 'absent');
        respond(['success' => true]);
    }

    /* ============ الإجازات ============ */
    case 'requestLeave': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        if (empty($b['startDate']) || empty($b['endDate'])) respond(['error' => 'حدد تاريخ البداية والنهاية'], 400);
        if (strtotime($b['endDate']) < strtotime($b['startDate'])) respond(['error' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية'], 400);
        $pdo->prepare("INSERT INTO leave_requests (user_id, start_date, end_date, reason) VALUES (?, ?, ?, ?)")
            ->execute([$user['id'], $b['startDate'], $b['endDate'], trim($b['reason'] ?? '')]);
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($admins as $a) {
            pushNotification($pdo, $a['id'], "{$user['name']} طلب إجازة من {$b['startDate']} إلى {$b['endDate']}.", 'leave');
        }
        respond(['success' => true]);
    }

    case 'reviewLeave': {
        requireAdmin($pdo);
        $b = bodyInput();
        $status = ($b['status'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("SELECT user_id, start_date, end_date FROM leave_requests WHERE id = ?");
        $stmt->execute([$b['id'] ?? 0]);
        $lr = $stmt->fetch();
        if (!$lr) respond(['error' => 'الطلب غير موجود'], 404);
        $pdo->prepare("UPDATE leave_requests SET status = ?, reviewed_at = NOW() WHERE id = ?")->execute([$status, $b['id']]);
        $msg = $status === 'approved'
            ? "تمت الموافقة على طلب إجازتك من {$lr['start_date']} إلى {$lr['end_date']} ✅"
            : "تم رفض طلب إجازتك من {$lr['start_date']} إلى {$lr['end_date']}.";
        pushNotification($pdo, $lr['user_id'], $msg, 'leave');
        respond(['success' => true]);
    }

    /* ============ إعدادات النقاط ============ */
    case 'updateSettings': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE settings SET work_start=?, grace_minutes=?, points_on_time=?, points_early_bonus=?, points_attendance=?, penalty_late=?, penalty_absent=? WHERE id = 1")
            ->execute([
                $b['workStart'] ?: '08:00', (int) ($b['graceMinutes'] ?? 15), (int) ($b['pointsOnTime'] ?? 20),
                (int) ($b['pointsEarlyBonus'] ?? 10), (int) ($b['pointsAttendance'] ?? 5),
                (int) ($b['penaltyLate'] ?? 5), (int) ($b['penaltyAbsent'] ?? 10)
            ]);
        respond(['success' => true]);
    }

    case 'updateWhatsappUrl': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE settings SET whatsapp_phone_id = ?, whatsapp_token = ?, whatsapp_template = ? WHERE id = 1")
            ->execute([trim($b['phoneId'] ?? ''), trim($b['token'] ?? ''), trim($b['template'] ?? '') ?: 'hello_world']);
        respond(['success' => true]);
    }

    case 'testWhatsapp': {
        requireAdmin($pdo);
        $b = bodyInput();
        $phone = trim($b['phone'] ?? '');
        if (!$phone) respond(['error' => 'رقم الهاتف مطلوب'], 400);
        sendWhatsAppCloud($pdo, $phone, 'هذه رسالة تجربة من نظام سوبر آبل ✅');
        respond(['success' => true]);
    }

    /* ============ الأقسام ============ */
    case 'addDepartment': {
        requireAdmin($pdo);
        $b = bodyInput();
        if (empty($b['name'])) respond(['error' => 'اسم القسم مطلوب'], 400);
        try {
            $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([trim($b['name'])]);
        } catch (PDOException $e) {
            respond(['error' => 'هذا القسم موجود مسبقًا'], 400);
        }
        respond(['success' => true]);
    }

    case 'removeDepartment': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    /* ============ العملاء ============ */
    case 'addClient': {
        requireAdmin($pdo);
        $b = bodyInput();
        if (empty($b['name'])) respond(['error' => 'اسم العميل مطلوب'], 400);
        $stmt = $pdo->prepare("INSERT INTO clients (name, contact_name, phone, email, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([trim($b['name']), trim($b['contactName'] ?? ''), trim($b['phone'] ?? ''), trim($b['email'] ?? ''), trim($b['notes'] ?? '')]);
        respond(['success' => true]);
    }

    case 'removeClient': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    default:
        respond(['error' => 'إجراء غير معروف'], 404);
}
