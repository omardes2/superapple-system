<?php
/**
 * نظام سوبر آبل — API الرئيسي (PHP + MySQL)
 * كل الطلبات تمر من هون عبر ?action=...
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/webauthn-lib/autoload.php';

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
    $stmt = $pdo->prepare("SELECT id, name, email, role, department, phone, work_start AS workStart, work_end AS workEnd, join_date AS joinDate, can_send_claims AS canSendClaims FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if ($u) {
        $u['canSendClaims'] = (bool) $u['canSendClaims'];
        $stmt2 = $pdo->prepare("SELECT COUNT(*) c FROM webauthn_credentials WHERE user_id = ?");
        $stmt2->execute([$u['id']]);
        $u['hasWebauthn'] = (bool) $stmt2->fetch()['c'];
    }
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

function requireClaimsAccess($pdo) {
    $u = requireLogin($pdo);
    if ($u['role'] !== 'admin' && !$u['canSendClaims']) respond(['error' => 'ما عندك صلاحية الوصول لصفحة المطالبات المالية'], 403);
    return $u;
}

/* ============ واتساب — Meta Cloud API الرسمي ============ */
require_once __DIR__ . '/helpers.php';

function getWebAuthn() {
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return new \lbuchs\WebAuthn\WebAuthn('سوبر آبل', $host, ['none'], true);
}

function doCheckIn($pdo, $user, $lat, $lng) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user['id'], $today]);
    if ($stmt->fetch()) return ['error' => 'تم تسجيل حضورك اليوم بالفعل'];

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
    return ['success' => true];
}

function doCheckOut($pdo, $user) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user['id'], $today]);
    $rec = $stmt->fetch();
    if (!$rec || $rec['check_out']) return ['error' => 'لا يوجد تسجيل حضور مفتوح'];
    $time = date('H:i:s');
    $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?")->execute([$time, $rec['id']]);

    $workedHours = round((strtotime($time) - strtotime($rec['check_in'])) / 3600, 1);
    $expectedHours = round((strtotime($user['workEnd'] ?: '16:00:00') - strtotime($user['workStart'] ?: '08:00:00')) / 3600, 1);
    $hoursMsg = $workedHours >= $expectedHours
        ? "أكملت {$workedHours} ساعة من أصل {$expectedHours} المطلوبة ✅"
        : "سجّلت {$workedHours} ساعة فقط من أصل {$expectedHours} المطلوبة (ناقص " . round($expectedHours - $workedHours, 1) . " ساعة)";

    pushNotification($pdo, $user['id'], "تم تسجيل انصرافك الساعة {$time}. {$hoursMsg}", 'checkout');
    return ['success' => true];
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
            whatsapp_phone_id AS whatsappPhoneId, whatsapp_token AS whatsappToken, whatsapp_template AS whatsappTemplate,
            whatsapp_verify_token AS whatsappVerifyToken, whatsapp_app_secret AS whatsappAppSecret
            FROM settings WHERE id = 1")->fetch();

        $payload = ['hasUsers' => $hasUsers, 'currentUser' => $currentUser, 'settings' => $settingsRow ?: null];

        if ($currentUser) {
            $isAdmin = $currentUser['role'] === 'admin';

            $payload['users'] = $pdo->query("SELECT id, name, email, role, department, phone, work_start AS workStart, work_end AS workEnd, join_date AS joinDate, can_send_claims AS canSendClaims FROM users")->fetchAll();

            $payload['clients'] = $pdo->query("SELECT id, name, contact_name AS contactName, phone, email, notes, created_at AS createdAt FROM clients ORDER BY name")->fetchAll();
            if ($isAdmin) {
                $payload['departments'] = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
            }
            if ($isAdmin || $currentUser['canSendClaims']) {
                $payload['claims'] = $pdo->query("SELECT id, debtor_name AS debtorName, debtor_phone AS debtorPhone, amount, paid_amount AS paidAmount, description, due_date AS dueDate, created_at AS createdAt FROM financial_claims ORDER BY due_date IS NULL, due_date ASC")->fetchAll();
            }

            $tasks = $pdo->query("SELECT id, title, description, priority, status, category, deadline, client_id AS clientId, created_by AS createdBy, created_at AS createdAt FROM tasks ORDER BY created_at DESC")->fetchAll();
            $aStmt = $pdo->prepare("SELECT user_id AS userId, accepted, accepted_at AS acceptedAt, done, completed_at AS completedAt FROM task_assignees WHERE task_id = ?");
            foreach ($tasks as &$t) {
                $aStmt->execute([$t['id']]);
                $rows = $aStmt->fetchAll();
                foreach ($rows as &$r) { $r['done'] = (bool) $r['done']; $r['accepted'] = (bool) $r['accepted']; }
                $t['assignees'] = $rows;
            }
            unset($t);
            if (!$isAdmin) {
                $tasks = array_values(array_filter($tasks, function ($t) use ($currentUser) {
                    if ($t['createdBy'] == $currentUser['id']) return true;
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

    case 'toggleEmployeePermission': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE users SET can_send_claims = ? WHERE id = ? AND role = 'employee'")
            ->execute([!empty($b['value']) ? 1 : 0, $b['id'] ?? 0]);
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
        $admin = requireLogin($pdo);
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

        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, priority, deadline, created_by, client_id, category) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([trim($b['title']), trim($b['description'] ?? ''), $b['priority'] ?: 'medium', $b['deadline'] ?: null, $admin['id'], $clientId, trim($b['category'] ?? '') ?: null]);
        $taskId = $pdo->lastInsertId();
        $insA = $pdo->prepare("INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)");
        $deadlineTxt = $b['deadline'] ? date('d/m', strtotime($b['deadline'])) : 'غير محدد';
        foreach ($b['assignees'] as $uid) {
            $insA->execute([$taskId, $uid]);
            pushNotification($pdo, $uid, "مهمة جديدة: \"" . trim($b['title']) . "\" — الموعد النهائي {$deadlineTxt}.", 'task');
        }
        respond(['success' => true]);
    }

    case 'acceptTask': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $stmt = $pdo->prepare("SELECT accepted FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(['error' => 'أنت لست مسندًا لهذه المهمة'], 403);
        if (!$row['accepted']) {
            $pdo->prepare("UPDATE task_assignees SET accepted = 1, accepted_at = NOW() WHERE task_id = ? AND user_id = ?")->execute([$taskId, $user['id']]);
            $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ? AND status = 'new'")->execute([$taskId]);
            $stmt = $pdo->prepare("SELECT title, created_by FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if ($task['created_by']) pushNotification($pdo, $task['created_by'], "{$user['name']} استلم المهمة \"{$task['title']}\" وبدأ العمل عليها.", 'task');
        }
        respond(['success' => true]);
    }

    case 'completeTaskAssignee': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $userId = $b['userId'] ?? $user['id'];
        if ($user['role'] !== 'admin' && $user['id'] != $userId) respond(['error' => 'غير مسموح'], 403);

        $stmt = $pdo->prepare("SELECT done FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $userId]);
        $row = $stmt->fetch();
        if (!$row) respond(['error' => 'المهمة غير موجودة'], 404);
        if ($row['done']) respond(['success' => true]);

        $completedAt = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE task_assignees SET done = 1, completed_at = ?, accepted = 1, accepted_at = COALESCE(accepted_at, ?) WHERE task_id = ? AND user_id = ?")
            ->execute([$completedAt, $completedAt, $taskId, $userId]);

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

        $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(done) doneCount FROM task_assignees WHERE task_id = ?");
        $stmt->execute([$taskId]);
        $cnt = $stmt->fetch();
        if ($cnt['total'] > 0 && $cnt['total'] == $cnt['doneCount']) {
            $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?")->execute([$taskId]);
        }
        respond(['success' => true]);
    }

    case 'addAssigneeToTask': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $newUserId = $b['userId'] ?? 0;
        $stmt = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $taskRow = $stmt->fetch();
        $isOwner = $taskRow && $taskRow['created_by'] == $user['id'];
        if ($user['role'] !== 'admin' && !$isOwner) {
            $stmt = $pdo->prepare("SELECT accepted FROM task_assignees WHERE task_id = ? AND user_id = ?");
            $stmt->execute([$taskId, $user['id']]);
            $row = $stmt->fetch();
            if (!$row) respond(['error' => 'غير مسموح'], 403);
            if (!$row['accepted']) respond(['error' => 'يجب استلام المهمة أولًا قبل إضافة زميل عليها'], 403);
        }
        try {
            $pdo->prepare("INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)")->execute([$taskId, $newUserId]);
        } catch (\Throwable $e) {
            respond(['error' => 'هذا الموظف مضاف للمهمة أصلًا'], 400);
        }
        $stmt = $pdo->prepare("SELECT title, deadline FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        $deadlineTxt = $task['deadline'] ? date('d/m', strtotime($task['deadline'])) : 'غير محدد';
        pushNotification($pdo, $newUserId, "تمت إضافتك لمهمة: \"{$task['title']}\" — الموعد النهائي {$deadlineTxt}.", 'task');
        respond(['success' => true]);
    }

    case 'addTaskComment': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $message = trim($b['message'] ?? '');
        if ($message === '') respond(['error' => 'اكتب تعليقًا'], 400);
        $stmt = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $taskRow = $stmt->fetch();
        $isOwner = $taskRow && $taskRow['created_by'] == $user['id'];
        if ($user['role'] !== 'admin' && !$isOwner) {
            $stmt = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
            $stmt->execute([$taskId, $user['id']]);
            if (!$stmt->fetch()) respond(['error' => 'غير مسموح'], 403);
        }
        $pdo->prepare("INSERT INTO task_comments (task_id, user_id, message) VALUES (?, ?, ?)")->execute([$taskId, $user['id'], $message]);

        $stmt = $pdo->prepare("SELECT title, created_by FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        $notifyIds = [];
        if ($task['created_by'] && $task['created_by'] != $user['id']) $notifyIds[] = $task['created_by'];
        $stmt = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id = ?");
        $stmt->execute([$taskId]);
        foreach ($stmt->fetchAll() as $r) if ($r['user_id'] != $user['id']) $notifyIds[] = $r['user_id'];
        foreach (array_unique($notifyIds) as $nid) {
            pushNotification($pdo, $nid, "{$user['name']} علّق على مهمة \"{$task['title']}\": " . mb_substr($message, 0, 80), 'task');
        }
        respond(['success' => true]);
    }

    case 'taskComments': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $stmt = $pdo->prepare("SELECT tc.id, tc.user_id AS userId, tc.message, tc.created_at AS createdAt, u.name AS userName FROM task_comments tc JOIN users u ON u.id = tc.user_id WHERE tc.task_id = ? ORDER BY tc.created_at ASC");
        $stmt->execute([$taskId]);
        respond(['comments' => $stmt->fetchAll()]);
    }


    /* ============ الدوام ============ */
    case 'checkIn': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $lat = is_numeric($b['lat'] ?? null) ? $b['lat'] : null;
        $lng = is_numeric($b['lng'] ?? null) ? $b['lng'] : null;
        $result = doCheckIn($pdo, $user, $lat, $lng);
        respond($result, isset($result['error']) ? 400 : 200);
    }

    case 'checkOut': {
        $user = requireLogin($pdo);
        $result = doCheckOut($pdo, $user);
        respond($result, isset($result['error']) ? 400 : 200);
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
        $result = sendWhatsAppCloud($pdo, $phone, 'هذه رسالة تجربة من نظام سوبر آبل ✅');
        if (!$result['success']) respond(['error' => $result['error']], 400);
        respond(['success' => true]);
    }

    case 'whatsappLog': {
        requireAdmin($pdo);
        $rows = $pdo->query("SELECT phone, success, response, created_at AS createdAt FROM whatsapp_log ORDER BY id DESC LIMIT 15")->fetchAll();
        respond(['log' => $rows]);
    }

    case 'waConversations': {
        requireAdmin($pdo);
        $rows = $pdo->query("
            SELECT m.phone,
                   (SELECT message FROM whatsapp_messages m2 WHERE m2.phone = m.phone ORDER BY m2.id DESC LIMIT 1) AS lastMessage,
                   (SELECT created_at FROM whatsapp_messages m3 WHERE m3.phone = m.phone ORDER BY m3.id DESC LIMIT 1) AS lastAt,
                   (SELECT COUNT(*) FROM whatsapp_messages m4 WHERE m4.phone = m.phone) AS total
            FROM whatsapp_messages m
            GROUP BY m.phone
            ORDER BY lastAt DESC
        ")->fetchAll();
        respond(['conversations' => $rows]);
    }

    case 'waThread': {
        requireAdmin($pdo);
        $b = bodyInput();
        $phone = preg_replace('/\D/', '', $b['phone'] ?? '');
        $stmt = $pdo->prepare("SELECT direction, message, created_at AS createdAt FROM whatsapp_messages WHERE phone = ? ORDER BY id ASC");
        $stmt->execute([$phone]);
        respond(['messages' => $stmt->fetchAll()]);
    }

    case 'updateWebhookSettings': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE settings SET whatsapp_verify_token = ?, whatsapp_app_secret = ? WHERE id = 1")
            ->execute([trim($b['verifyToken'] ?? ''), trim($b['appSecret'] ?? '')]);
        respond(['success' => true]);
    }

    /* ============ المطالبات المالية ============ */
    case 'addClaim': {
        $user = requireClaimsAccess($pdo);
        $b = bodyInput();
        if (empty($b['debtorName']) || empty($b['amount'])) respond(['error' => 'اسم المدين والمبلغ مطلوبان'], 400);
        $stmt = $pdo->prepare("INSERT INTO financial_claims (debtor_name, debtor_phone, amount, description, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([trim($b['debtorName']), trim($b['debtorPhone'] ?? ''), $b['amount'], trim($b['description'] ?? ''), $b['dueDate'] ?: null, $user['id']]);
        respond(['success' => true]);
    }

    case 'removeClaim': {
        requireClaimsAccess($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM financial_claims WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    case 'addClaimPayment': {
        requireClaimsAccess($pdo);
        $b = bodyInput();
        $claimId = $b['id'] ?? 0;
        $amount = is_numeric($b['amount'] ?? null) ? (float) $b['amount'] : 0;
        if ($amount <= 0) respond(['error' => 'أدخل مبلغًا صحيحًا'], 400);
        $pdo->prepare("UPDATE financial_claims SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$amount, $claimId]);
        respond(['success' => true]);
    }

    case 'sendClaimReminder': {
        $user = requireClaimsAccess($pdo);
        $b = bodyInput();
        $stmt = $pdo->prepare("SELECT * FROM financial_claims WHERE id = ?");
        $stmt->execute([$b['id'] ?? 0]);
        $claim = $stmt->fetch();
        if (!$claim) respond(['error' => 'المطالبة غير موجودة'], 404);
        if (empty($claim['debtor_phone'])) respond(['error' => 'لا يوجد رقم واتساب مسجّل لهذا المدين'], 400);

        $remaining = round($claim['amount'] - $claim['paid_amount'], 2);
        $dueTxt = $claim['due_date'] ? date('d/m/Y', strtotime($claim['due_date'])) : 'غير محدد';
        $message = "تذكير من سوبر آبل: لديك مبلغ مستحق قدره {$remaining} بخصوص \"{$claim['description']}\"، تاريخ الاستحقاق {$dueTxt}. يرجى التواصل لتسوية الحساب.";
        $result = sendWhatsAppCloud($pdo, $claim['debtor_phone'], $message);
        $pdo->prepare("INSERT INTO claim_reminders (claim_id, success) VALUES (?, ?)")->execute([$claim['id'], $result['success'] ? 1 : 0]);
        if (!$result['success']) respond(['error' => $result['error']], 400);
        respond(['success' => true]);
    }

    case 'claimReminderLog': {
        requireClaimsAccess($pdo);
        $b = bodyInput();
        $stmt = $pdo->prepare("SELECT success, sent_at AS sentAt FROM claim_reminders WHERE claim_id = ? ORDER BY sent_at DESC");
        $stmt->execute([$b['claimId'] ?? 0]);
        respond(['log' => $stmt->fetchAll()]);
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

    case 'removeTask': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    /* ============ بصمة الحضور الحقيقية (WebAuthn) ============ */
    case 'webauthnRegisterStart': {
        $user = requireLogin($pdo);
        $webAuthn = getWebAuthn();
        $stmt = $pdo->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $existing = array_map(function ($r) {
            return \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($r['credential_id']);
        }, $stmt->fetchAll());

        try {
            $args = $webAuthn->getCreateArgs((string) $user['id'], $user['email'], $user['name'], 40, false, 'required', false, $existing);
            $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();
            respond(['publicKey' => $args->publicKey]);
        } catch (\Throwable $e) {
            respond(['error' => 'تعذر بدء التسجيل: ' . $e->getMessage()], 500);
        }
    }

    case 'webauthnRegisterFinish': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        if (empty($_SESSION['webauthn_challenge'])) respond(['error' => 'انتهت صلاحية الطلب، حاول مجددًا'], 400);
        $webAuthn = getWebAuthn();
        try {
            $clientDataJSON = \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b['clientDataJSON'])->getBinaryString();
            $attestationObject = \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b['attestationObject'])->getBinaryString();
            $data = $webAuthn->processCreate($clientDataJSON, $attestationObject, $_SESSION['webauthn_challenge'], 'required', true);
            $credentialId = (new \lbuchs\WebAuthn\Binary\ByteBuffer($data->credentialId))->jsonSerialize();
            $signCount = is_int($data->signatureCounter) ? $data->signatureCounter : 0;
            $pdo->prepare("INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count) VALUES (?, ?, ?, ?)")
                ->execute([$user['id'], $credentialId, $data->credentialPublicKey, $signCount]);
            unset($_SESSION['webauthn_challenge']);
            respond(['success' => true]);
        } catch (\Throwable $e) {
            respond(['error' => 'تعذر إتمام التسجيل: ' . $e->getMessage()], 400);
        }
    }

    case 'webauthnAuthStart': {
        $user = requireLogin($pdo);
        $stmt = $pdo->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) respond(['error' => 'لا يوجد بصمة مسجّلة لحسابك بعد'], 400);
        $ids = array_map(function ($r) {
            return \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($r['credential_id']);
        }, $rows);

        $webAuthn = getWebAuthn();
        try {
            $args = $webAuthn->getGetArgs($ids, 40, true, true, true, true, true, 'required');
            $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();
            respond(['publicKey' => $args->publicKey]);
        } catch (\Throwable $e) {
            respond(['error' => 'تعذر بدء التحقق: ' . $e->getMessage()], 500);
        }
    }

    case 'webauthnAttendance': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        if (empty($_SESSION['webauthn_challenge'])) respond(['error' => 'انتهت صلاحية الطلب، حاول مجددًا'], 400);

        $stmt = $pdo->prepare("SELECT id, credential_id, public_key, sign_count FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?");
        $stmt->execute([$user['id'], $b['id'] ?? '']);
        $cred = $stmt->fetch();
        if (!$cred) respond(['error' => 'بصمة غير معروفة'], 400);

        $webAuthn = getWebAuthn();
        try {
            $clientDataJSON = \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b['clientDataJSON'])->getBinaryString();
            $authenticatorData = \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b['authenticatorData'])->getBinaryString();
            $signature = \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($b['signature'])->getBinaryString();

            $webAuthn->processGet($clientDataJSON, $authenticatorData, $signature, $cred['public_key'], $_SESSION['webauthn_challenge'], (int) $cred['sign_count'], 'required', true);
            unset($_SESSION['webauthn_challenge']);

            $newCount = $webAuthn->getSignatureCounter();
            if ($newCount !== null) {
                $pdo->prepare("UPDATE webauthn_credentials SET sign_count = ? WHERE id = ?")->execute([$newCount, $cred['id']]);
            }
        } catch (\Throwable $e) {
            respond(['error' => 'تعذر التحقق من البصمة: ' . $e->getMessage()], 400);
        }

        // التحقق البيومتري نجح -> نفّذ تسجيل الحضور أو الانصراف تلقائيًا حسب حالة اليوم
        $lat = is_numeric($b['lat'] ?? null) ? $b['lat'] : null;
        $lng = is_numeric($b['lng'] ?? null) ? $b['lng'] : null;
        $todayRec = $pdo->prepare("SELECT id, check_out FROM attendance WHERE user_id = ? AND date = CURDATE()");
        $todayRec->execute([$user['id']]);
        $rec = $todayRec->fetch();
        $result = (!$rec) ? doCheckIn($pdo, $user, $lat, $lng) : ((!$rec['check_out']) ? doCheckOut($pdo, $user) : ['error' => 'تم تسجيل دوامك بالكامل لهذا اليوم']);
        respond($result, isset($result['error']) ? 400 : 200);
    }

    case 'webauthnRemove': {
        $user = requireLogin($pdo);
        $pdo->prepare("DELETE FROM webauthn_credentials WHERE user_id = ?")->execute([$user['id']]);
        respond(['success' => true]);
    }

    default:
        respond(['error' => 'إجراء غير معروف'], 404);
}
