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

    // التحقق من موقع الشركة — يتم بالسيرفر حصرًا، فما فيه طريقة يتحايل عليه من المتصفح
    $geo = checkGeofence($pdo, $lat, $lng);
    if (!$geo['allowed']) return ['error' => $geo['error']];

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
    $stmt = $pdo->prepare("SELECT id, check_in, check_out, status FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user['id'], $today]);
    $rec = $stmt->fetch();
    if (!$rec || $rec['check_out']) return ['error' => 'لا يوجد تسجيل حضور مفتوح'];
    $time = date('H:i:s');
    $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?")->execute([$time, $rec['id']]);

    $workStart = $user['workStart'] ?: '08:00:00';
    $workEnd = $user['workEnd'] ?: '16:00:00';
    $workedHours = round((strtotime($time) - strtotime($rec['check_in'])) / 3600, 1);
    $expectedHours = round((strtotime($workEnd) - strtotime($workStart)) / 3600, 1);
    $hoursMsg = $workedHours >= $expectedHours
        ? "أكملت {$workedHours} ساعة من أصل {$expectedHours} المطلوبة ✅"
        : "سجّلت {$workedHours} ساعة فقط من أصل {$expectedHours} المطلوبة (ناقص " . round($expectedHours - $workedHours, 1) . " ساعة)";

    // تعويض التأخير: لو حضر متأخر بس بقي بعد نهاية دوامه بنفس مقدار تأخيره (أو أكثر)، يُلغى خصم النقاط
    if ($rec['status'] === 'late') {
        $latenessSeconds = strtotime($rec['check_in']) - strtotime($workStart);
        $extraStaySeconds = strtotime($time) - strtotime($workEnd);
        if ($latenessSeconds > 0 && $extraStaySeconds >= $latenessSeconds) {
            $s = $pdo->query("SELECT penalty_late FROM settings WHERE id = 1")->fetch();
            $penalty = (int) $s['penalty_late'];
            if ($penalty > 0) {
                $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, ?)")
                    ->execute([$user['id'], $penalty, 'تعويض التأخير بالبقاء وقتًا إضافيًا بعد الدوام']);
            }
            $lateMin = round($latenessSeconds / 60);
            pushNotification(
                $pdo, $user['id'],
                "لاحظنا إنك تأخرت الصبح {$lateMin} دقيقة، بس عوّضتها بالبقاء بعد انتهاء دوامك اليوم 👏 — لهيك ما رح يتم خصم أي نقطة عليك هالمرة. خلّينا نحافظ على الالتزام بموعد الحضور قدر الإمكان بالمرات الجايّة.",
                'points'
            );
        }
    }

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
            whatsapp_verify_token AS whatsappVerifyToken, whatsapp_app_secret AS whatsappAppSecret,
            geofence_enabled AS geofenceEnabled, office_latitude AS officeLatitude,
            office_longitude AS officeLongitude, geofence_radius AS geofenceRadius,
            motivation_enabled AS motivationEnabled, motivation_delay_minutes AS motivationDelayMinutes, motivation_daily_count AS motivationDailyCount
            FROM settings WHERE id = 1")->fetch();

        $payload = ['hasUsers' => $hasUsers, 'currentUser' => $currentUser, 'settings' => $settingsRow ?: null];

        if ($currentUser) {
            $isAdmin = $currentUser['role'] === 'admin';

            $payload['users'] = $pdo->query("SELECT id, name, email, role, department, phone, work_start AS workStart, work_end AS workEnd, join_date AS joinDate, can_send_claims AS canSendClaims FROM users")->fetchAll();

            $payload['clients'] = $pdo->query("SELECT id, name, contact_name AS contactName, phone, email, notes, created_at AS createdAt FROM clients ORDER BY name")->fetchAll();

            $allProjects = $pdo->query("SELECT p.id, p.client_id AS clientId, p.manager_id AS managerId, p.name, p.description, p.status, p.start_date AS startDate, p.due_date AS dueDate, p.default_requires_review AS defaultRequiresReview, p.progress_manual AS progressManual, p.notes, p.created_at AS createdAt, c.name AS clientName, u.name AS managerName FROM projects p LEFT JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = p.manager_id ORDER BY p.created_at DESC")->fetchAll();
            foreach ($allProjects as &$pr) $pr['progress'] = computeProjectProgress($pdo, $pr['id'], $pr['progress_manual']);
            unset($pr);
            $payload['projects'] = $isAdmin ? $allProjects : array_values(array_filter($allProjects, fn($pr) => canAccessProject($pdo, $currentUser, $pr['id'])));
            $payload['prompts'] = $pdo->query("
                SELECT p.id, p.name, p.category, p.prompt_text AS promptText, p.image_path AS imagePath, p.created_by AS createdBy, u.name AS creatorName, p.created_at AS createdAt
                FROM prompts p LEFT JOIN users u ON u.id = p.created_by
                ORDER BY p.created_at DESC
            ")->fetchAll();
            if ($isAdmin) {
                $payload['departments'] = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
            }
            if ($isAdmin || $currentUser['canSendClaims']) {
                $payload['claims'] = $pdo->query("SELECT id, debtor_name AS debtorName, debtor_phone AS debtorPhone, amount, paid_amount AS paidAmount, description, due_date AS dueDate, created_at AS createdAt FROM financial_claims ORDER BY due_date IS NULL, due_date ASC")->fetchAll();
            }

            $tasks = $pdo->query("SELECT id, title, description, priority, status, category, deadline, client_id AS clientId, project_id AS projectId, requires_review AS requiresReview, review_note AS reviewNote, created_by AS createdBy, created_at AS createdAt FROM tasks ORDER BY created_at DESC")->fetchAll();
            // عدد التعليقات لكل المهام باستعلام مجمّع واحد (تفاديًا لاستعلام منفصل لكل مهمة)
            $commentCounts = [];
            foreach ($pdo->query("SELECT task_id, COUNT(*) c FROM task_comments GROUP BY task_id")->fetchAll() as $row) {
                $commentCounts[$row['task_id']] = (int) $row['c'];
            }
            $aStmt = $pdo->prepare("SELECT user_id AS userId, accepted, accepted_at AS acceptedAt, done, completed_at AS completedAt FROM task_assignees WHERE task_id = ?");
            foreach ($tasks as &$t) {
                $aStmt->execute([$t['id']]);
                $rows = $aStmt->fetchAll();
                foreach ($rows as &$r) { $r['done'] = (bool) $r['done']; $r['accepted'] = (bool) $r['accepted']; }
                $t['assignees'] = $rows;
                $t['commentCount'] = $commentCounts[$t['id']] ?? 0;
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
                $payload['notifications'] = $pdo->query("SELECT id, user_id AS userId, message, title, type, entity_type AS entityType, entity_id AS entityId, is_read AS isRead, created_at AS createdAt FROM notifications ORDER BY created_at DESC LIMIT 300")->fetchAll();
                $payload['leaveRequests'] = $pdo->query("SELECT id, user_id AS userId, start_date AS startDate, end_date AS endDate, reason, status, created_at AS createdAt FROM leave_requests ORDER BY created_at DESC")->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT id, user_id AS userId, message, title, type, entity_type AS entityType, entity_id AS entityId, is_read AS isRead, created_at AS createdAt FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 150");
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

        // ربط اختياري بمشروع (لازم صلاحية وصول للمشروع)، مع أخذ إعداد المراجعة الافتراضي منه ما لم يُحدَّد يدويًا
        $projectId = null;
        $requiresReview = 0;
        if (!empty($b['projectId'])) {
            if (!canAccessProject($pdo, $admin, $b['projectId'])) respond(['error' => 'غير مسموح لك بإضافة مهام لهذا المشروع'], 403);
            $projectId = $b['projectId'];
            $stmt = $pdo->prepare("SELECT default_requires_review FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            $proj = $stmt->fetch();
            $requiresReview = $proj ? (int) $proj['default_requires_review'] : 0;
        }
        if (isset($b['requiresReview'])) $requiresReview = (int) (bool) $b['requiresReview'];

        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, priority, deadline, created_by, client_id, category, project_id, requires_review) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([trim($b['title']), trim($b['description'] ?? ''), $b['priority'] ?: 'medium', $b['deadline'] ?: null, $admin['id'], $clientId, trim($b['category'] ?? '') ?: null, $projectId, $requiresReview]);
        $taskId = $pdo->lastInsertId();
        if ($projectId) logProjectActivity($pdo, $projectId, $admin['id'], 'task_created', "أُنشئت مهمة: " . trim($b['title']));
        $insA = $pdo->prepare("INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)");
        $deadlineTxt = $b['deadline'] ? date('d/m', strtotime($b['deadline'])) : 'غير محدد';
        foreach ($b['assignees'] as $uid) {
            $insA->execute([$taskId, $uid]);
            pushNotification($pdo, $uid, "مهمة جديدة: \"" . trim($b['title']) . "\" — الموعد النهائي {$deadlineTxt}.", 'task', ['title' => 'مهمة جديدة', 'entityType' => 'task', 'entityId' => $taskId]);
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
            $upd = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ? AND status = 'new'");
            $upd->execute([$taskId]);
            if ($upd->rowCount() > 0) logTaskStatusChange($pdo, $taskId, 'new', 'in_progress', $user['id']);
            $stmt = $pdo->prepare("SELECT title, created_by FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if ($task['created_by']) pushNotification($pdo, $task['created_by'], "{$user['name']} استلم المهمة \"{$task['title']}\" وبدأ العمل عليها.", 'task', ['title' => 'استلام مهمة', 'entityType' => 'task', 'entityId' => $taskId]);
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

        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        // مهمة تتطلب مراجعة: لا يجوز احتساب النقاط قبل الاعتماد الفعلي — استخدم submitForReview بدل هذا الإجراء
        if ($task['requires_review']) {
            respond(['error' => 'هذه المهمة تتطلب مراجعة من المدير — استخدم "إرسال للمراجعة" بدل الإكمال المباشر'], 400);
        }

        $result = finalizeTaskAssigneeCompletion($pdo, $task, $userId);
        if ($result['onTime']) {
            pushNotification($pdo, $userId, "أحسنت! أنجزت \"{$task['title']}\" وحصلت على {$result['points']} نقطة.", 'points', ['title' => 'إنجاز مهمة', 'entityType' => 'task', 'entityId' => $taskId]);
        } else {
            pushNotification($pdo, $userId, "أنجزت \"{$task['title']}\" بعد الموعد النهائي.", 'task', ['title' => 'إنجاز مهمة', 'entityType' => 'task', 'entityId' => $taskId]);
        }

        // إغلاق المهمة تلقائيًا لو كل المسندين خلصوا — فقط للمهام التي لا تحتاج مراجعة (سلوك قديم بدون تغيير)
        if (!$task['requires_review']) {
            $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(done) doneCount FROM task_assignees WHERE task_id = ?");
            $stmt->execute([$taskId]);
            $cnt = $stmt->fetch();
            if ($cnt['total'] > 0 && $cnt['total'] == $cnt['doneCount']) {
                $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?")->execute([$taskId]);
                logTaskStatusChange($pdo, $taskId, $task['status'], 'done', $userId);
            }
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
        pushNotification($pdo, $newUserId, "تمت إضافتك لمهمة: \"{$task['title']}\" — الموعد النهائي {$deadlineTxt}.", 'task', ['title' => 'أُضفت لمهمة', 'entityType' => 'task', 'entityId' => $taskId]);
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
        $commentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT title, created_by FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        // استخراج المنشن: الأولوية لـ user IDs الصريحة القادمة من اختيار الواجهة (mentionedUserIds)
        // — مو تحليل نص فقط. بكل الأحوال، كل ID (سواء من الواجهة أو fallback تحليل النص) يُتحقق منه
        // Server-side ضد قائمة المسموح ذكرهم فعليًا بهذه المهمة تحديدًا، بدون أي ثقة عمياء بالواجهة.
        $mentionable = getMentionableUsers($pdo, $taskId);
        $allowedIds = array_map(fn($u) => (int) $u['id'], $mentionable);

        if (!empty($b['mentionedUserIds']) && is_array($b['mentionedUserIds'])) {
            $mentionedIds = array_values(array_intersect(array_map('intval', $b['mentionedUserIds']), $allowedIds));
        } else {
            // fallback للتوافق: تحليل نصي لو الواجهة ما بعثت IDs صريحة لأي سبب
            $mentionedIds = extractMentionedUserIds($message, $mentionable);
        }
        $mentionedIds = array_filter($mentionedIds, fn($uid) => $uid != $user['id']); // ما تذكر نفسك
        foreach ($mentionedIds as $mid) {
            try {
                $pdo->prepare("INSERT INTO comment_mentions (comment_id, user_id) VALUES (?, ?)")->execute([$commentId, $mid]);
            } catch (\Throwable $e) { /* منشن مكرر بنفس التعليق — تجاهل بأمان */ }
            pushNotification($pdo, $mid, "{$user['name']} ذكرك بتعليق على مهمة \"{$task['title']}\"", 'mention',
                ['title' => 'تم ذكرك', 'entityType' => 'task', 'entityId' => $taskId, 'sendWhatsapp' => false]);
        }

        // باقي المشاركين بالمهمة (غير المُذكورين تحديدًا، تفاديًا لإشعار مزدوج لنفس الشخص عن نفس التعليق)
        $notifyIds = [];
        if ($task['created_by'] && $task['created_by'] != $user['id']) $notifyIds[] = $task['created_by'];
        $stmt = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id = ?");
        $stmt->execute([$taskId]);
        foreach ($stmt->fetchAll() as $r) if ($r['user_id'] != $user['id']) $notifyIds[] = $r['user_id'];
        foreach (array_unique($notifyIds) as $nid) {
            if (in_array($nid, $mentionedIds)) continue; // تفادي إشعار مزدوج
            pushNotification($pdo, $nid, "{$user['name']} علّق على مهمة \"{$task['title']}\": " . mb_substr($message, 0, 80), 'task',
                ['title' => 'تعليق جديد', 'entityType' => 'task', 'entityId' => $taskId, 'sendWhatsapp' => false]);
        }
        respond(['success' => true]);
    }

    case 'mentionableUsers': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        respond(['users' => getMentionableUsers($pdo, $b['taskId'] ?? 0)]);
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
        $newLeaveId = $pdo->lastInsertId();
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($admins as $a) {
            pushNotification($pdo, $a['id'], "{$user['name']} طلب إجازة من {$b['startDate']} إلى {$b['endDate']}.", 'leave', ['title' => 'طلب إجازة جديد', 'entityType' => 'leave', 'entityId' => $newLeaveId, 'sendWhatsapp' => false]);
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
        pushNotification($pdo, $lr['user_id'], $msg, 'leave', ['title' => $status === 'approved' ? 'تمت الموافقة على إجازتك' : 'تم رفض طلب إجازتك', 'entityType' => 'leave', 'entityId' => (int) $b['id']]);
        respond(['success' => true]);
    }

    /* ============ إعدادات النقاط ============ */
    /* ============ الرسائل التحفيزية ============ */
    case 'motivationMessages': {
        requireAdmin($pdo);
        $rows = $pdo->query("SELECT id, message, is_active AS isActive, created_at AS createdAt FROM motivation_messages ORDER BY id DESC")->fetchAll();
        $sentToday = $pdo->query("SELECT COUNT(*) c FROM motivation_log WHERE sent_date = CURDATE() AND success = 1")->fetch();
        respond(['messages' => $rows, 'sentToday' => (int) $sentToday['c']]);
    }

    case 'addMotivationMessages': {
        requireAdmin($pdo);
        $b = bodyInput();
        $raw = trim($b['messages'] ?? '');
        if ($raw === '') respond(['error' => 'اكتب رسالة واحدة على الأقل'], 400);
        // كل سطر = رسالة منفصلة (يسهّل إضافة دفعة كبيرة مرة وحدة)
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($l) => $l !== '');
        if (empty($lines)) respond(['error' => 'ما في رسائل صالحة'], 400);
        $ins = $pdo->prepare("INSERT INTO motivation_messages (message) VALUES (?)");
        $count = 0;
        foreach ($lines as $line) { $ins->execute([mb_substr($line, 0, 900)]); $count++; }
        respond(['success' => true, 'added' => $count]);
    }

    case 'toggleMotivationMessage': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE motivation_messages SET is_active = ? WHERE id = ?")
            ->execute([!empty($b['active']) ? 1 : 0, $b['id'] ?? 0]);
        respond(['success' => true]);
    }

    case 'removeMotivationMessage': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM motivation_messages WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    case 'updateMotivationSettings': {
        requireAdmin($pdo);
        $b = bodyInput();
        $enabled = !empty($b['enabled']) ? 1 : 0;
        $delay = max(0, min(600, (int) ($b['delayMinutes'] ?? 60)));
        $dailyCount = max(1, min(50, (int) ($b['dailyCount'] ?? 2)));
        if ($enabled) {
            $has = $pdo->query("SELECT COUNT(*) c FROM motivation_messages WHERE is_active = 1")->fetch();
            if (!(int) $has['c']) respond(['error' => 'أضف رسالة واحدة مفعّلة على الأقل قبل التفعيل'], 400);
        }
        $pdo->prepare("UPDATE settings SET motivation_enabled = ?, motivation_delay_minutes = ?, motivation_daily_count = ? WHERE id = 1")
            ->execute([$enabled, $delay, $dailyCount]);
        respond(['success' => true]);
    }

    case 'updateGeofence': {
        requireAdmin($pdo);
        $b = bodyInput();
        $enabled = !empty($b['enabled']) ? 1 : 0;
        $lat = ($b['latitude'] === '' || $b['latitude'] === null) ? null : (float) $b['latitude'];
        $lng = ($b['longitude'] === '' || $b['longitude'] === null) ? null : (float) $b['longitude'];
        $radius = max(20, min(5000, (int) ($b['radius'] ?? 200))); // حد أدنى معقول وحد أعلى للحماية من خطأ إدخال

        // منع تفعيل القيد بدون تحديد موقع — وإلا رح يمنع كل الموظفين من التسجيل
        if ($enabled && ($lat === null || $lng === null)) {
            respond(['error' => 'لازم تحدد موقع الشركة أولًا قبل تفعيل القيد'], 400);
        }

        $pdo->prepare("UPDATE settings SET geofence_enabled=?, office_latitude=?, office_longitude=?, geofence_radius=? WHERE id = 1")
            ->execute([$enabled, $lat, $lng, $radius]);
        respond(['success' => true]);
    }

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

    /* ============ محادثة الفريق الجماعية ============ */
    case 'teamChatMessages': {
        requireLogin($pdo);
        $rows = $pdo->query("
            SELECT tm.id, tm.user_id AS userId, u.name AS userName, tm.message, tm.created_at AS createdAt
            FROM team_messages tm JOIN users u ON u.id = tm.user_id
            ORDER BY tm.id DESC LIMIT 60
        ")->fetchAll();
        respond(['messages' => array_reverse($rows)]);
    }

    case 'sendTeamChatMessage': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $message = trim($b['message'] ?? '');
        if ($message === '') respond(['error' => 'اكتب رسالة'], 400);
        $pdo->prepare("INSERT INTO team_messages (user_id, message) VALUES (?, ?)")->execute([$user['id'], $message]);
        respond(['success' => true]);
    }

    /* ============ مكتبة البرومبتات ============ */
    case 'addPrompt': {
        $user = requireLogin($pdo);
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'other';
        $promptText = trim($_POST['promptText'] ?? '');
        if (!in_array($category, ['image', 'video', 'other'], true)) $category = 'other';
        if ($name === '' || $promptText === '') respond(['error' => 'اسم البرومبت ونصه مطلوبان'], 400);

        $imagePath = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mime = @mime_content_type($_FILES['image']['tmp_name']);
            if (isset($allowed[$mime]) && $_FILES['image']['size'] <= 6 * 1024 * 1024) {
                $destDir = __DIR__ . '/../uploads/prompts/';
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                $filename = 'prompt_' . uniqid() . '.' . $allowed[$mime];
                if (@move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $filename)) {
                    $imagePath = 'uploads/prompts/' . $filename;
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO prompts (name, category, prompt_text, image_path, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $promptText, $imagePath, $user['id']]);
        respond(['success' => true]);
    }

    case 'removePrompt': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $stmt = $pdo->prepare("SELECT created_by, image_path FROM prompts WHERE id = ?");
        $stmt->execute([$b['id'] ?? 0]);
        $p = $stmt->fetch();
        if (!$p) respond(['error' => 'البرومبت غير موجود'], 404);
        if ($user['role'] !== 'admin' && $p['created_by'] != $user['id']) respond(['error' => 'غير مسموح'], 403);
        $pdo->prepare("DELETE FROM prompts WHERE id = ?")->execute([$b['id']]);
        if ($p['image_path']) {
            $full = __DIR__ . '/../' . $p['image_path'];
            if (file_exists($full)) @unlink($full);
        }
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

    case 'removeTask': {
        requireAdmin($pdo);
        $b = bodyInput();
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$b['id'] ?? 0]);
        respond(['success' => true]);
    }

    /* ============ المشاريع ============ */
    case 'createProject': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $name = trim($b['name'] ?? '');
        if ($name === '') respond(['error' => 'اسم المشروع مطلوب'], 400);
        $managerId = !empty($b['managerId']) ? $b['managerId'] : $user['id'];
        $defaultReview = array_key_exists('defaultRequiresReview', $b) ? (int) (bool) $b['defaultRequiresReview'] : 1;

        $stmt = $pdo->prepare("INSERT INTO projects (client_id, manager_id, name, description, status, start_date, due_date, default_requires_review, notes) VALUES (?, ?, ?, ?, 'new', ?, ?, ?, ?)");
        $stmt->execute([
            $b['clientId'] ?: null, $managerId, $name, trim($b['description'] ?? ''),
            $b['startDate'] ?: null, $b['dueDate'] ?: null, $defaultReview, trim($b['notes'] ?? '')
        ]);
        $projectId = $pdo->lastInsertId();

        $pdo->prepare("INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?, ?)")->execute([$projectId, $managerId]);
        if (!empty($b['memberIds']) && is_array($b['memberIds'])) {
            $insM = $pdo->prepare("INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?, ?)");
            foreach ($b['memberIds'] as $mid) $insM->execute([$projectId, $mid]);
        }
        logProjectActivity($pdo, $projectId, $user['id'], 'created', 'تم إنشاء المشروع');
        respond(['success' => true, 'id' => $projectId]);
    }

    case 'updateProject': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $projectId = $b['id'] ?? 0;
        if (!canManageProject($pdo, $user, $projectId)) respond(['error' => 'غير مسموح لك بإدارة هذا المشروع'], 403);

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $old = $stmt->fetch();
        if (!$old) respond(['error' => 'المشروع غير موجود'], 404);

        $newStatus = $b['status'] ?? $old['status'];
        $pdo->prepare("UPDATE projects SET name=?, description=?, status=?, client_id=?, manager_id=?, start_date=?, due_date=?, default_requires_review=?, progress_manual=?, notes=? WHERE id=?")
            ->execute([
                trim($b['name'] ?? $old['name']), array_key_exists('description', $b) ? trim($b['description']) : $old['description'],
                $newStatus, array_key_exists('clientId', $b) ? ($b['clientId'] ?: null) : $old['client_id'],
                array_key_exists('managerId', $b) ? $b['managerId'] : $old['manager_id'],
                array_key_exists('startDate', $b) ? ($b['startDate'] ?: null) : $old['start_date'],
                array_key_exists('dueDate', $b) ? ($b['dueDate'] ?: null) : $old['due_date'],
                array_key_exists('defaultRequiresReview', $b) ? (int) (bool) $b['defaultRequiresReview'] : $old['default_requires_review'],
                array_key_exists('progressManual', $b) ? ($b['progressManual'] === null || $b['progressManual'] === '' ? null : (int) $b['progressManual']) : $old['progress_manual'],
                array_key_exists('notes', $b) ? trim($b['notes']) : $old['notes'],
                $projectId
            ]);

        if ($newStatus !== $old['status']) {
            logProjectActivity($pdo, $projectId, $user['id'], 'status_changed', "الحالة تغيّرت من {$old['status']} إلى {$newStatus}");
            $statusLabels = ['new' => 'جديد', 'active' => 'قيد التنفيذ', 'on_hold' => 'متوقف مؤقتًا', 'completed' => 'مكتمل', 'cancelled' => 'ملغي'];
            $members = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
            $members->execute([$projectId]);
            foreach ($members->fetchAll() as $m) {
                if ($m['user_id'] == $user['id']) continue;
                pushNotification($pdo, $m['user_id'],
                    "تغيّرت حالة مشروع \"{$old['name']}\" إلى " . ($statusLabels[$newStatus] ?? $newStatus),
                    'project', ['title' => 'تغيير بحالة المشروع', 'entityType' => 'project', 'entityId' => $projectId, 'sendWhatsapp' => false]
                );
            }
        } else {
            logProjectActivity($pdo, $projectId, $user['id'], 'updated', 'تم تحديث بيانات المشروع');
        }
        respond(['success' => true]);
    }

    case 'removeProject': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $projectId = $b['id'] ?? 0;
        if (!canManageProject($pdo, $user, $projectId)) respond(['error' => 'غير مسموح لك بحذف هذا المشروع'], 403);

        // مرفقات المشروع بدون FK مباشر (Polymorphic) — لازم تنضيف يدويًا (قاعدة بيانات + ملفات فعلية) قبل الحذف
        $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE entity_type = 'project' AND entity_id = ?");
        $stmt->execute([$projectId]);
        $uploadsRoot = realpath(__DIR__ . '/../uploads/');
        foreach ($stmt->fetchAll() as $att) {
            if (!$att['file_path']) continue;
            $full = realpath(__DIR__ . '/../' . $att['file_path']);
            if ($full && $uploadsRoot && strpos($full, $uploadsRoot) === 0 && file_exists($full)) @unlink($full);
        }
        $pdo->prepare("DELETE FROM attachments WHERE entity_type = 'project' AND entity_id = ?")->execute([$projectId]);

        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);
        respond(['success' => true]);
    }

    case 'addProjectMember': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $projectId = $b['projectId'] ?? 0;
        $memberId = $b['userId'] ?? 0;
        if (!canManageProject($pdo, $user, $projectId)) respond(['error' => 'غير مسموح'], 403);
        try {
            $pdo->prepare("INSERT INTO project_members (project_id, user_id) VALUES (?, ?)")->execute([$projectId, $memberId]);
        } catch (\Throwable $e) {
            respond(['error' => 'هذا العضو مضاف أصلًا للمشروع'], 400);
        }
        logProjectActivity($pdo, $projectId, $user['id'], 'member_added', null);
        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $p = $stmt->fetch();
        pushNotification($pdo, $memberId, "تمت إضافتك لفريق مشروع \"" . ($p['name'] ?? '') . "\"", 'project', ['title' => 'أُضفت لمشروع', 'entityType' => 'project', 'entityId' => $projectId]);
        respond(['success' => true]);
    }

    case 'removeProjectMember': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $projectId = $b['projectId'] ?? 0;
        $memberId = $b['userId'] ?? 0;
        if (!canManageProject($pdo, $user, $projectId)) respond(['error' => 'غير مسموح'], 403);
        $pdo->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?")->execute([$projectId, $memberId]);
        logProjectActivity($pdo, $projectId, $user['id'], 'member_removed', null);
        if ($memberId != $user['id']) {
            $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            $p = $stmt->fetch();
            pushNotification($pdo, $memberId, "تمت إزالتك من فريق مشروع \"" . ($p['name'] ?? '') . "\"", 'project', ['title' => 'أُزلت من مشروع', 'entityType' => 'project', 'entityId' => $projectId, 'sendWhatsapp' => false]);
        }
        respond(['success' => true]);
    }

    case 'projectDetail': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $projectId = $b['id'] ?? 0;
        if (!canAccessProject($pdo, $user, $projectId)) respond(['error' => 'غير مسموح لك بالوصول لهذا المشروع'], 403);

        $stmt = $pdo->prepare("SELECT p.id, p.client_id AS clientId, p.manager_id AS managerId, p.name, p.description, p.status, p.start_date AS startDate, p.due_date AS dueDate, p.default_requires_review AS defaultRequiresReview, p.progress_manual AS progressManual, p.notes, p.created_at AS createdAt, c.name AS clientName, u.name AS managerName FROM projects p LEFT JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = p.manager_id WHERE p.id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        if (!$project) respond(['error' => 'المشروع غير موجود'], 404);
        $project['progress'] = computeProjectProgress($pdo, $projectId, $project['progress_manual']);

        $stmt = $pdo->prepare("SELECT pm.user_id AS userId, u.name AS userName FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ?");
        $stmt->execute([$projectId]);
        $members = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT id, title, status, priority, deadline, requires_review AS requiresReview FROM tasks WHERE project_id = ? ORDER BY created_at DESC");
        $stmt->execute([$projectId]);
        $tasks = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT id, file_name AS fileName, file_path AS filePath, file_type AS fileType, file_size AS fileSize, link_url AS linkUrl, version_group AS versionGroup, version_label AS versionLabel, created_at AS createdAt, uploaded_by AS uploadedBy FROM attachments WHERE entity_type = 'project' AND entity_id = ? ORDER BY created_at DESC");
        $stmt->execute([$projectId]);
        $attachments = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT pa.action, pa.description, pa.created_at AS createdAt, u.name AS userName FROM project_activity pa LEFT JOIN users u ON u.id = pa.user_id WHERE pa.project_id = ? ORDER BY pa.id DESC LIMIT 40");
        $stmt->execute([$projectId]);
        $activity = $stmt->fetchAll();

        respond(['project' => $project, 'members' => $members, 'tasks' => $tasks, 'attachments' => $attachments, 'activity' => $activity]);
    }

    /* ============ Phase 2 Batch 1: Workload Management ============ */
    case 'workloadReport': {
        // ضغط العمل بيانات إدارية عن كل الفريق — للمدير فقط حسب الصلاحيات الحالية بالنظام
        requireAdmin($pdo);
        respond(['workload' => getTeamWorkload($pdo)]);
    }

    /* ============ Phase 2 Batch 2: Notification read-state ============ */
    case 'notificationsUnreadCount': {
        $user = requireLogin($pdo);
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        respond(['count' => (int) $stmt->fetch()['c']]);
    }

    case 'markNotificationRead': {
        // user_id يُستخرج من الجلسة حصرًا — ما فيه أي طريقة يقرأ فيها مستخدم إشعارات غيره
        $user = requireLogin($pdo);
        $b = bodyInput();
        $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([$b['id'] ?? 0, $user['id']]);
        respond(['success' => true]);
    }

    case 'markAllNotificationsRead': {
        $user = requireLogin($pdo);
        $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0")
            ->execute([$user['id']]);
        respond(['success' => true]);
    }

    /* ============ Phase 2 Batch 3: Executive Dashboard ============ */
    case 'executiveDashboard': {
        $user = requireAdmin($pdo);
        respond(getExecutiveDashboard($pdo, $user));
    }

    /* ============ سير مراجعة المهام (State Machine محكوم) ============ */
    case 'submitForReview': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) respond(['error' => 'المهمة غير موجودة'], 404);

        $stmt = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $user['id']]);
        if ($user['role'] !== 'admin' && !$stmt->fetch()) respond(['error' => 'أنت لست مسندًا لهذه المهمة'], 403);

        if (!isValidTaskTransition($task['status'], 'ready_for_review', $task['requires_review'])) {
            respond(['error' => "لا يمكن إرسال المهمة للمراجعة من حالتها الحالية ({$task['status']})"], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tasks SET status = 'ready_for_review' WHERE id = ?")->execute([$taskId]);
            logTaskStatusChange($pdo, $taskId, $task['status'], 'ready_for_review', $user['id']);
            if ($task['project_id']) logProjectActivity($pdo, $task['project_id'], $user['id'], 'submitted_for_review', "أُرسلت للمراجعة: {$task['title']}");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            respond(['error' => 'فشلت العملية، لم يتم حفظ أي تغيير'], 500);
        }

        if ($task['project_id']) {
            $stmt = $pdo->prepare("SELECT manager_id FROM projects WHERE id = ?");
            $stmt->execute([$task['project_id']]);
            $mgr = $stmt->fetch();
            if ($mgr && $mgr['manager_id']) pushNotification($pdo, $mgr['manager_id'], "مهمة \"{$task['title']}\" جاهزة للمراجعة.", 'task', ['title' => 'مهمة بانتظار مراجعتك', 'entityType' => 'task', 'entityId' => $taskId]);
        } elseif ($task['created_by']) {
            pushNotification($pdo, $task['created_by'], "مهمة \"{$task['title']}\" جاهزة للمراجعة.", 'task', ['title' => 'مهمة بانتظار مراجعتك', 'entityType' => 'task', 'entityId' => $taskId]);
        }
        respond(['success' => true]);
    }

    case 'approveTask': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        if (!canReviewTask($pdo, $user, $taskId)) respond(['error' => 'غير مسموح لك بمراجعة هذه المهمة'], 403);

        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) respond(['error' => 'المهمة غير موجودة'], 404);
        if (!isValidTaskTransition($task['status'], 'done', $task['requires_review'])) {
            respond(['error' => "لا يمكن اعتماد المهمة من حالتها الحالية ({$task['status']})"], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?")->execute([$taskId]);
            logTaskStatusChange($pdo, $taskId, $task['status'], 'done', $user['id'], trim($b['note'] ?? '') ?: null);

            // احتساب نقاط أي مسند لسا ما احتُسبت له (نفس آلية النقاط الحالية بالضبط، دون تغيير)
            $aStmt = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id = ? AND done = 0");
            $aStmt->execute([$taskId]);
            $pendingAssignees = $aStmt->fetchAll();
            foreach ($pendingAssignees as $a) {
                finalizeTaskAssigneeCompletion($pdo, $task, $a['user_id']);
            }

            if ($task['project_id']) logProjectActivity($pdo, $task['project_id'], $user['id'], 'task_approved', "تم اعتماد مهمة: {$task['title']}");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            respond(['error' => 'فشل اعتماد المهمة، لم يتم حفظ أي تغيير'], 500);
        }

        $aStmt = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id = ?");
        $aStmt->execute([$taskId]);
        foreach ($aStmt->fetchAll() as $a) {
            pushNotification($pdo, $a['user_id'], "تم اعتماد مهمتك \"{$task['title']}\" ✅", 'points', ['title' => 'تم اعتماد المهمة', 'entityType' => 'task', 'entityId' => $taskId]);
        }
        respond(['success' => true]);
    }

    case 'requestChanges': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $note = trim($b['note'] ?? '');
        if ($note === '') respond(['error' => 'لازم تكتب ملاحظة توضح التعديلات المطلوبة'], 400);
        if (!canReviewTask($pdo, $user, $taskId)) respond(['error' => 'غير مسموح لك بمراجعة هذه المهمة'], 403);

        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) respond(['error' => 'المهمة غير موجودة'], 404);
        if (!isValidTaskTransition($task['status'], 'changes_requested', $task['requires_review'])) {
            respond(['error' => "لا يمكن طلب تعديل من حالة المهمة الحالية ({$task['status']})"], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tasks SET status = 'changes_requested' WHERE id = ?")->execute([$taskId]);
            logTaskStatusChange($pdo, $taskId, $task['status'], 'changes_requested', $user['id'], $note);
            if ($task['project_id']) logProjectActivity($pdo, $task['project_id'], $user['id'], 'changes_requested', "طُلب تعديل على: {$task['title']}");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            respond(['error' => 'فشلت العملية، لم يتم حفظ أي تغيير'], 500);
        }

        $aStmt = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id = ?");
        $aStmt->execute([$taskId]);
        foreach ($aStmt->fetchAll() as $a) {
            pushNotification($pdo, $a['user_id'], "طُلب تعديل على مهمة \"{$task['title']}\": {$note}", 'task', ['title' => 'طُلب تعديل على مهمتك', 'entityType' => 'task', 'entityId' => $taskId]);
        }
        respond(['success' => true]);
    }

    case 'resumeTask': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) respond(['error' => 'المهمة غير موجودة'], 404);

        $stmt = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $user['id']]);
        if ($user['role'] !== 'admin' && !$stmt->fetch()) respond(['error' => 'غير مسموح'], 403);

        if (!isValidTaskTransition($task['status'], 'in_progress', $task['requires_review'])) {
            respond(['error' => "لا يمكن استئناف العمل من حالة المهمة الحالية ({$task['status']})"], 400);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ?")->execute([$taskId]);
            logTaskStatusChange($pdo, $taskId, $task['status'], 'in_progress', $user['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            respond(['error' => 'فشلت العملية'], 500);
        }
        respond(['success' => true]);
    }

    case 'taskStatusLog': {
        requireLogin($pdo);
        $b = bodyInput();
        $taskId = $b['taskId'] ?? 0;
        $stmt = $pdo->prepare("SELECT tsl.from_status AS fromStatus, tsl.to_status AS toStatus, tsl.note, tsl.created_at AS createdAt, u.name AS userName FROM task_status_log tsl LEFT JOIN users u ON u.id = tsl.changed_by WHERE tsl.task_id = ? ORDER BY tsl.id ASC");
        $stmt->execute([$taskId]);
        respond(['log' => $stmt->fetchAll()]);
    }

    /* ============ المرفقات (Polymorphic: تحقق يدوي بدل FK مباشر) ============ */
    case 'uploadAttachment': {
        $user = requireLogin($pdo);
        $entityType = $_POST['entityType'] ?? '';
        $entityId = (int) ($_POST['entityId'] ?? 0);
        if (!in_array($entityType, ['task', 'project', 'client'], true)) respond(['error' => 'نوع العنصر غير صالح'], 400);
        if (!validateAttachmentEntity($pdo, $user, $entityType, $entityId)) respond(['error' => 'العنصر غير موجود أو غير مسموح لك بالوصول له'], 403);

        $versionGroup = trim($_POST['versionGroup'] ?? '') ?: null;
        $versionLabel = trim($_POST['versionLabel'] ?? '') ?: null;
        $linkUrl = trim($_POST['linkUrl'] ?? '');

        if ($linkUrl !== '') {
            if (!filter_var($linkUrl, FILTER_VALIDATE_URL)) respond(['error' => 'رابط غير صالح'], 400);
            $displayName = trim($_POST['name'] ?? '') ?: $linkUrl;
            $stmt = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, link_url, version_group, version_label, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$entityType, $entityId, $displayName, $linkUrl, $versionGroup, $versionLabel, $user['id']]);
            respond(['success' => true]);
        }

        if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            respond(['error' => 'لم يتم اختيار ملف أو رابط صالح'], 400);
        }

        $allowed = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
            'application/zip' => 'zip',
        ];
        $maxSize = 15 * 1024 * 1024;

        $mime = @mime_content_type($_FILES['file']['tmp_name']);
        if (!isset($allowed[$mime])) respond(['error' => 'نوع الملف غير مدعوم'], 400);
        if ($_FILES['file']['size'] > $maxSize) respond(['error' => 'حجم الملف أكبر من الحد المسموح (15 ميجا)'], 400);

        $destDir = __DIR__ . '/../uploads/attachments/';
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        // اسم داخلي عشوائي بالكامل (لا علاقة له بالاسم الأصلي) — يمنع Path Traversal نهائيًا
        $internalName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $destPath = $destDir . $internalName;

        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            respond(['error' => 'فشل رفع الملف على السيرفر'], 500);
        }

        $originalName = basename(trim($_FILES['file']['name']));
        $stmt = $pdo->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, version_group, version_label, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$entityType, $entityId, $originalName, 'uploads/attachments/' . $internalName, $mime, (int) $_FILES['file']['size'], $versionGroup, $versionLabel, $user['id']]);

        if ($entityType === 'project') {
            logProjectActivity($pdo, $entityId, $user['id'], 'file_uploaded', "تم رفع ملف: {$originalName}");
        } elseif ($entityType === 'task') {
            $stmt = $pdo->prepare("SELECT project_id FROM tasks WHERE id = ?");
            $stmt->execute([$entityId]);
            $t = $stmt->fetch();
            if ($t && $t['project_id']) logProjectActivity($pdo, $t['project_id'], $user['id'], 'file_uploaded', "تم رفع ملف على مهمة: {$originalName}");
        }
        respond(['success' => true]);
    }

    case 'removeAttachment': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $id = $b['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
        $stmt->execute([$id]);
        $att = $stmt->fetch();
        if (!$att) respond(['error' => 'المرفق غير موجود'], 404);

        $canDelete = ($user['role'] === 'admin') || ($att['uploaded_by'] == $user['id']);
        if (!$canDelete && $att['entity_type'] === 'project') $canDelete = canManageProject($pdo, $user, $att['entity_id']);
        if (!$canDelete && $att['entity_type'] === 'task') {
            $stmt = $pdo->prepare("SELECT project_id FROM tasks WHERE id = ?");
            $stmt->execute([$att['entity_id']]);
            $t = $stmt->fetch();
            if ($t && $t['project_id']) $canDelete = canManageProject($pdo, $user, $t['project_id']);
        }
        if (!$canDelete) respond(['error' => 'غير مسموح لك بحذف هذا المرفق'], 403);

        $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$id]);
        if ($att['file_path']) {
            $full = realpath(__DIR__ . '/../' . $att['file_path']);
            $uploadsRoot = realpath(__DIR__ . '/../uploads/');
            // حماية إضافية: احذف فقط لو المسار الفعلي جوّا مجلد uploads فعلًا (منع Path Traversal حتى لو تلاعب أحد بالمسار المخزَّن)
            if ($full && $uploadsRoot && strpos($full, $uploadsRoot) === 0 && file_exists($full)) {
                @unlink($full);
            }
        }
        respond(['success' => true]);
    }

    case 'entityAttachments': {
        $user = requireLogin($pdo);
        $b = bodyInput();
        $entityType = $b['entityType'] ?? '';
        $entityId = $b['entityId'] ?? 0;
        if (!validateAttachmentEntity($pdo, $user, $entityType, $entityId)) respond(['error' => 'غير مسموح'], 403);
        $stmt = $pdo->prepare("SELECT a.id, a.file_name AS fileName, a.file_path AS filePath, a.file_type AS fileType, a.file_size AS fileSize, a.link_url AS linkUrl, a.version_group AS versionGroup, a.version_label AS versionLabel, a.created_at AS createdAt, a.uploaded_by AS uploadedBy, u.name AS uploaderName FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.entity_type = ? AND a.entity_id = ? ORDER BY a.created_at DESC");
        $stmt->execute([$entityType, $entityId]);
        respond(['attachments' => $stmt->fetchAll()]);
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
