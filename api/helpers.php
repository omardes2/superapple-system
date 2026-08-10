<?php
/**
 * دوال مشتركة بين api/index.php و api/cron.php
 */

function sendWhatsAppCloud($pdo, $phone, $bodyText) {
    if (empty($phone)) return ['success' => false, 'error' => 'لا يوجد رقم هاتف'];
    if (!function_exists('curl_init')) return ['success' => false, 'error' => 'امتداد curl غير مفعّل على هذه الاستضافة'];
    $s = $pdo->query("SELECT whatsapp_phone_id, whatsapp_token, whatsapp_template FROM settings WHERE id = 1")->fetch();
    if (empty($s['whatsapp_phone_id']) || empty($s['whatsapp_token'])) {
        return ['success' => false, 'error' => 'واتساب غير مفعّل بعد (Phone Number ID أو Access Token فاضي)'];
    }

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
    if ($ch === false) return ['success' => false, 'error' => 'curl غير متاح على هذه الاستضافة'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $s['whatsapp_token']],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);
    $responseText = $curlErr ?: $raw;

    try {
        $pdo->prepare("INSERT INTO whatsapp_log (phone, message, success, response) VALUES (?, ?, ?, ?)")
            ->execute([$phone, $bodyText, $success ? 1 : 0, $responseText]);
    } catch (\Throwable $e) { /* جدول السجل قد لا يكون موجودًا بعد على استضافات لم تحدّث قاعدة البيانات */ }

    if ($success) {
        try {
            $waMessageId = null;
            $decoded = json_decode($raw, true);
            if (isset($decoded['messages'][0]['id'])) $waMessageId = $decoded['messages'][0]['id'];
            $pdo->prepare("INSERT INTO whatsapp_messages (phone, direction, message, wa_message_id) VALUES (?, 'out', ?, ?)")
                ->execute([preg_replace('/\D/', '', $phone), $bodyText, $waMessageId]);
        } catch (\Throwable $e) { /* جدول صندوق الرسائل قد لا يكون موجودًا بعد */ }
    }

    if (!$success) {
        $decoded = json_decode($raw, true);
        $friendly = $decoded['error']['message'] ?? ($curlErr ?: 'خطأ غير معروف');
        return ['success' => false, 'error' => $friendly, 'httpCode' => $httpCode];
    }
    return ['success' => true];
}

// يسجّل إشعارًا داخل النظام + يبعت رسالة واتساب حقيقية بنفس الوقت
function pushNotification($pdo, $userId, $message, $type, $opts = []) {
    // $opts اختياري بالكامل — أي استدعاء قديم بأربع بارامترات يشتغل بالضبط زي ما كان (صفر كسر توافق)
    $title = $opts['title'] ?? null;
    $entityType = $opts['entityType'] ?? null;
    $entityId = $opts['entityId'] ?? null;
    // افتراضيًا نُبقي إرسال واتساب مفعّل (نفس السلوك القديم)، إلا لو طُلب تعطيله صراحة
    // (نستخدم هذا لأنواع الإشعارات الجديدة كثيرة التكرار زي المنشن والتعليقات لتفادي إزعاج واتساب)
    $sendWhatsapp = $opts['sendWhatsapp'] ?? true;

    $pdo->prepare("INSERT INTO notifications (user_id, message, title, type, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $message, $title, $type, $entityType, $entityId]);

    if ($sendWhatsapp) {
        $stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if ($u && $u['phone']) sendWhatsAppCloud($pdo, $u['phone'], $message);
    }
}

/* =========================================================
   صلاحيات المشاريع المركزية — لا تُكرَّر شروطها داخل أي endpoint
   ========================================================= */

// هل يقدر يشوف المشروع؟ مدير عام: كل شي. موظف: مديره، أو عضو فيه، أو له مهمة مرتبطة فيه
function canAccessProject($pdo, $user, $projectId) {
    if ($user['role'] === 'admin') return true;
    $stmt = $pdo->prepare("SELECT manager_id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $p = $stmt->fetch();
    if (!$p) return false;
    if ($p['manager_id'] == $user['id']) return true;
    $stmt = $pdo->prepare("SELECT id FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    if ($stmt->fetch()) return true;
    $stmt = $pdo->prepare("SELECT ta.id FROM task_assignees ta JOIN tasks t ON t.id = ta.task_id WHERE t.project_id = ? AND ta.user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    return (bool) $stmt->fetch();
}

// هل يقدر يدير المشروع (تعديل/حذف/أعضاء)؟ مدير عام، أو مدير هذا المشروع تحديدًا
function canManageProject($pdo, $user, $projectId) {
    if ($user['role'] === 'admin') return true;
    $stmt = $pdo->prepare("SELECT manager_id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $p = $stmt->fetch();
    return $p && $p['manager_id'] == $user['id'];
}

// هل يقدر يراجع (يعتمد/يرجّع) مهمة معيّنة؟ مدير عام، أو مدير مشروعها، أو منشئها لو بلا مشروع
function canReviewTask($pdo, $user, $taskId) {
    if ($user['role'] === 'admin') return true;
    $stmt = $pdo->prepare("SELECT project_id, created_by FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $t = $stmt->fetch();
    if (!$t) return false;
    if ($t['project_id']) return canManageProject($pdo, $user, $t['project_id']);
    return $t['created_by'] == $user['id'];
}

// تحقق وجود العنصر المرتبط بالمرفق فعليًا + صلاحية المستخدم عليه (لأن جدول attachments بدون FK مباشر)
function validateAttachmentEntity($pdo, $user, $entityType, $entityId) {
    if ($entityType === 'task') {
        $stmt = $pdo->prepare("SELECT id, project_id, created_by FROM tasks WHERE id = ?");
        $stmt->execute([$entityId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        if ($user['role'] === 'admin') return true;
        if ($row['project_id'] && canAccessProject($pdo, $user, $row['project_id'])) return true;
        if ($row['created_by'] == $user['id']) return true;
        $stmt = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$entityId, $user['id']]);
        return (bool) $stmt->fetch();
    }
    if ($entityType === 'project') {
        $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ?");
        $stmt->execute([$entityId]);
        if (!$stmt->fetch()) return false;
        return canAccessProject($pdo, $user, $entityId);
    }
    if ($entityType === 'client') {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ?");
        $stmt->execute([$entityId]);
        return (bool) $stmt->fetch();
    }
    return false;
}

/* =========================================================
   آلة انتقال حالات المهمة (State Machine) — لا انتقال عشوائي مسموح
   ========================================================= */
function isValidTaskTransition($fromStatus, $toStatus, $requiresReview) {
    $allowed = [
        'new' => ['in_progress'],
        'in_progress' => $requiresReview ? ['ready_for_review'] : ['done'],
        'ready_for_review' => ['done', 'changes_requested'],
        'changes_requested' => ['in_progress'],
        'done' => [],
    ];
    return isset($allowed[$fromStatus]) && in_array($toStatus, $allowed[$fromStatus], true);
}

// يسجّل انتقال الحالة بجدول task_status_log (المصدر التاريخي الرسمي) + يحدّث آخر ملاحظة للعرض السريع فقط
function logTaskStatusChange($pdo, $taskId, $fromStatus, $toStatus, $userId, $note = null) {
    $pdo->prepare("INSERT INTO task_status_log (task_id, from_status, to_status, changed_by, note) VALUES (?, ?, ?, ?, ?)")
        ->execute([$taskId, $fromStatus, $toStatus, $userId, $note]);
    if ($note !== null) {
        $pdo->prepare("UPDATE tasks SET review_note = ? WHERE id = ?")->execute([$note, $taskId]);
    }
}

/* =========================================================
   سجل نشاط المشروع + حساب نسبة الإنجاز
   ========================================================= */
function logProjectActivity($pdo, $projectId, $userId, $action, $description = null) {
    $pdo->prepare("INSERT INTO project_activity (project_id, user_id, action, description) VALUES (?, ?, ?, ?)")
        ->execute([$projectId, $userId, $action, $description]);
}

function computeProjectProgress($pdo, $projectId, $manualProgress) {
    if ($manualProgress !== null) return (int) $manualProgress;
    $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(status='done') doneCount FROM tasks WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $r = $stmt->fetch();
    if (!$r['total']) return 0; // تفادي القسمة على صفر لمشروع بدون مهام
    return (int) round(((int) $r['doneCount'] / (int) $r['total']) * 100);
}

// إكمال مهمة موظف واحتساب نقاطه — نفس الآلية الحالية بالضبط (مستخرجة كدالة مشتركة لإعادة استخدامها
// من completeTaskAssignee ومن approveTask بدون تكرار أو تغيير بمنطق احتساب النقاط)
function finalizeTaskAssigneeCompletion($pdo, $task, $userId) {
    $completedAt = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE task_assignees SET done = 1, completed_at = ?, accepted = 1, accepted_at = COALESCE(accepted_at, ?) WHERE task_id = ? AND user_id = ?")
        ->execute([$completedAt, $completedAt, $task['id'], $userId]);

    $onTime = !$task['deadline'] || strtotime($completedAt) <= strtotime($task['deadline'] . ' 23:59:59');
    $pts = 0;
    if ($onTime) {
        $s = $pdo->query("SELECT points_on_time, points_early_bonus, early_bonus_hours FROM settings WHERE id = 1")->fetch();
        $pts = (int) $s['points_on_time'];
        if ($task['deadline']) {
            $hoursEarly = (strtotime($task['deadline'] . ' 23:59:59') - strtotime($completedAt)) / 3600;
            if ($hoursEarly >= (int) $s['early_bonus_hours']) $pts += (int) $s['points_early_bonus'];
        }
        $pdo->prepare("INSERT INTO points (user_id, points, reason) VALUES (?, ?, ?)")->execute([$userId, $pts, "إنجاز مهمة: {$task['title']}"]);
    }
    return ['onTime' => $onTime, 'points' => $pts];
}

/* =========================================================
   Workload Management — Phase 2 Batch 1
   معادلة الحساب مركزية هون فقط. الواجهة تعرض النتيجة الجاهزة
   (score + level) ولا تُعيد حسابها إطلاقًا.
   ========================================================= */

// أوزان معادلة ضغط العمل — عدّلها هون بس، ما تكرّرها بمكان تاني
const WORKLOAD_WEIGHTS = [
    'activeTask' => 2,       // كل مهمة نشطة (غير منجزة)
    'overdueTask' => 5,      // كل مهمة متأخرة (وزن أعلى — الأخطر)
    'dueToday' => 3,         // كل مهمة موعدها اليوم
    'dueThisWeek' => 1,      // كل مهمة مستحقة خلال 7 أيام (غير اليوم وغير متأخرة)
    'changesRequested' => 3, // كل مهمة تحتاج إعادة عمل بعد رفض المراجعة
    'activeProject' => 2,    // كل مشروع نشط يشارك فيه
];
const WORKLOAD_THRESHOLD_MEDIUM = 9;  // score >= 9  → متوسط
const WORKLOAD_THRESHOLD_HIGH = 21;   // score >= 21 → مرتفع

function computeWorkloadScore($m) {
    $w = WORKLOAD_WEIGHTS;
    $score = $m['activeTasks'] * $w['activeTask']
        + $m['overdueTasks'] * $w['overdueTask']
        + $m['dueToday'] * $w['dueToday']
        + $m['dueThisWeek'] * $w['dueThisWeek']
        + $m['changesRequested'] * $w['changesRequested']
        + $m['activeProjects'] * $w['activeProject'];
    $level = $score >= WORKLOAD_THRESHOLD_HIGH ? 'high' : ($score >= WORKLOAD_THRESHOLD_MEDIUM ? 'medium' : 'low');
    return ['score' => $score, 'level' => $level];
}

// يرجع Workload لكل الموظفين بضربتين استعلام مجمّعتين فقط (بدون أي حلقة استعلامات لكل موظف)
function getTeamWorkload($pdo) {
    // استعلام 1: مقاييس المهام (نشطة/متأخرة/اليوم/الأسبوع/تحتاج تعديل) مجمّعة لكل موظف دفعة وحدة
    $taskStats = $pdo->query("
        SELECT
            u.id AS userId, u.name AS userName, u.department,
            COUNT(t.id) AS activeTasks,
            SUM(CASE WHEN t.deadline IS NOT NULL AND t.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdueTasks,
            SUM(CASE WHEN t.deadline = CURDATE() THEN 1 ELSE 0 END) AS dueToday,
            SUM(CASE WHEN t.deadline IS NOT NULL AND t.deadline > CURDATE() AND t.deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS dueThisWeek,
            SUM(CASE WHEN t.status = 'ready_for_review' THEN 1 ELSE 0 END) AS readyForReview,
            SUM(CASE WHEN t.status = 'changes_requested' THEN 1 ELSE 0 END) AS changesRequested
        FROM users u
        LEFT JOIN task_assignees ta ON ta.user_id = u.id AND ta.done = 0
        LEFT JOIN tasks t ON t.id = ta.task_id AND t.status != 'done'
        WHERE u.role = 'employee'
        GROUP BY u.id, u.name, u.department
        ORDER BY u.name
    ")->fetchAll();

    // استعلام 2: عدد المشاريع النشطة لكل موظف (منفصل لتفادي ضرب النتائج الديكارتي مع استعلام المهام)
    $projStats = $pdo->query("
        SELECT pm.user_id AS userId, COUNT(*) AS activeProjects
        FROM project_members pm
        JOIN projects p ON p.id = pm.project_id AND p.status = 'active'
        GROUP BY pm.user_id
    ")->fetchAll();
    $projMap = [];
    foreach ($projStats as $p) $projMap[$p['userId']] = (int) $p['activeProjects'];

    $result = [];
    foreach ($taskStats as $row) {
        $metrics = [
            'activeTasks' => (int) $row['activeTasks'],
            'overdueTasks' => (int) $row['overdueTasks'],
            'dueToday' => (int) $row['dueToday'],
            'dueThisWeek' => (int) $row['dueThisWeek'],
            'readyForReview' => (int) $row['readyForReview'],
            'changesRequested' => (int) $row['changesRequested'],
            'activeProjects' => $projMap[$row['userId']] ?? 0,
        ];
        $scoreInfo = computeWorkloadScore($metrics);
        $result[] = array_merge(
            ['userId' => (int) $row['userId'], 'userName' => $row['userName'], 'department' => $row['department']],
            $metrics,
            $scoreInfo
        );
    }
    return $result;
}

/* =========================================================
   @Mentions — Phase 2 Batch 2
   ========================================================= */

// من يجوز ذكره بتعليقات مهمة معيّنة: المسندين لها + منشئها + أعضاء مشروعها ومديره (مو كل موظفي الشركة)
function getMentionableUsers($pdo, $taskId) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name FROM users u WHERE u.id IN (
            SELECT ta.user_id FROM task_assignees ta WHERE ta.task_id = ?
            UNION SELECT t.created_by FROM tasks t WHERE t.id = ? AND t.created_by IS NOT NULL
            UNION SELECT pm.user_id FROM project_members pm JOIN tasks t2 ON t2.project_id = pm.project_id WHERE t2.id = ?
            UNION SELECT p.manager_id FROM projects p JOIN tasks t3 ON t3.project_id = p.id WHERE t3.id = ? AND p.manager_id IS NOT NULL
        )
        ORDER BY u.name
    ");
    $stmt->execute([$taskId, $taskId, $taskId, $taskId]);
    return $stmt->fetchAll();
}

// يستخرج user_ids المذكورين فعليًا بنص التعليق (مطابقة @الاسم_الكامل، الأسماء الأطول أولاً لتفادي تطابق جزئي خاطئ)
function extractMentionedUserIds($message, $mentionableUsers) {
    $sorted = $mentionableUsers;
    usort($sorted, fn($a, $b) => mb_strlen($b['name']) - mb_strlen($a['name']));
    $found = [];
    $remaining = $message;
    foreach ($sorted as $u) {
        if (mb_strpos($remaining, '@' . $u['name']) !== false) {
            $found[] = (int) $u['id'];
            $remaining = str_replace('@' . $u['name'], '', $remaining); // تفادي إعادة مطابقة جزء من اسم أطول اتطابق أصلًا
        }
    }
    return array_unique($found);
}

/* =========================================================
   Executive Dashboard — Phase 2 Batch 3
   استعلامات مجمّعة بدون N+1، وتحترم صلاحيات كل قسم
   ========================================================= */
function getExecutiveDashboard($pdo, $user) {
    $today = date('Y-m-d');
    $isFriday = (date('N') == 5);
    $s = $pdo->query("SELECT grace_minutes FROM settings WHERE id = 1")->fetch();
    $grace = (int) $s['grace_minutes'];

    /* ---- الحضور: المتأخرون اليوم + الغائبون حتى الآن (يحترمون فترة السماح، الجمعة، والإجازات المعتمدة) ---- */
    $late = $pdo->prepare("
        SELECT u.id AS userId, u.name AS userName, a.check_in AS checkIn
        FROM attendance a JOIN users u ON u.id = a.user_id
        WHERE a.date = ? AND a.status = 'late'
        ORDER BY a.check_in
    ");
    $late->execute([$today]);
    $lateList = $late->fetchAll();

    $absentList = [];
    if (!$isFriday) {
        $absentList = $pdo->prepare("
            SELECT u.id AS userId, u.name AS userName, u.work_start AS workStart
            FROM users u
            WHERE u.role = 'employee'
              AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.user_id = u.id AND a.date = ?)
              AND NOT EXISTS (
                    SELECT 1 FROM leave_requests lr
                    WHERE lr.user_id = u.id AND lr.status = 'approved'
                      AND ? BETWEEN lr.start_date AND lr.end_date
              )
              AND ADDTIME(u.work_start, SEC_TO_TIME(? * 60)) < CURTIME()
        ");
        $absentList->execute([$today, $today, $grace]);
        $absentList = $absentList->fetchAll();
    }

    /* ---- المهام ---- */
    $overdueTasks = $pdo->query("SELECT id, title FROM tasks WHERE status != 'done' AND deadline IS NOT NULL AND deadline < CURDATE() ORDER BY deadline LIMIT 50")->fetchAll();
    $dueTodayTasks = $pdo->query("SELECT id, title FROM tasks WHERE status != 'done' AND deadline = CURDATE() LIMIT 50")->fetchAll();
    $readyForReviewTasks = $pdo->query("SELECT id, title FROM tasks WHERE status = 'ready_for_review' LIMIT 50")->fetchAll();
    $changesRequestedTasks = $pdo->query("SELECT id, title FROM tasks WHERE status = 'changes_requested' LIMIT 50")->fetchAll();

    /* ---- المشاريع ---- */
    $overdueProjects = $pdo->query("SELECT id, name, due_date AS dueDate FROM projects WHERE status NOT IN ('completed','cancelled') AND due_date IS NOT NULL AND due_date < CURDATE() ORDER BY due_date")->fetchAll();
    $dueTodayProjects = $pdo->query("SELECT id, name FROM projects WHERE status NOT IN ('completed','cancelled') AND due_date = CURDATE()")->fetchAll();
    $lowProgressProjects = [];
    $nearDeadline = $pdo->query("SELECT id, name, due_date AS dueDate, progress_manual AS progressManual FROM projects WHERE status NOT IN ('completed','cancelled') AND due_date IS NOT NULL AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchAll();
    foreach ($nearDeadline as $p) {
        $progress = computeProjectProgress($pdo, $p['id'], $p['progressManual']);
        if ($progress < 50) $lowProgressProjects[] = ['id' => $p['id'], 'name' => $p['name'], 'dueDate' => $p['dueDate'], 'progress' => $progress];
    }

    /* ---- الإجازات المعلّقة ---- */
    $pendingLeaves = $pdo->query("
        SELECT lr.id, u.name AS userName, lr.start_date AS startDate, lr.end_date AS endDate
        FROM leave_requests lr JOIN users u ON u.id = lr.user_id
        WHERE lr.status = 'pending' ORDER BY lr.id DESC
    ")->fetchAll();

    $result = [
        'attendance' => ['late' => $lateList, 'absent' => $absentList],
        'tasks' => ['overdue' => $overdueTasks, 'dueToday' => $dueTodayTasks, 'readyForReview' => $readyForReviewTasks, 'changesRequested' => $changesRequestedTasks],
        'projects' => ['overdue' => $overdueProjects, 'dueToday' => $dueTodayProjects, 'lowProgressNearDeadline' => $lowProgressProjects],
        'leaveRequests' => ['pending' => $pendingLeaves],
    ];

    /* ---- المطالبات المالية: تُرجع فقط لمن يملك الصلاحية أصلًا — وإلا ما تُرجع إطلاقًا ---- */
    if ($user['role'] === 'admin' || $user['canSendClaims']) {
        $dueToday = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(amount - paid_amount),0) total FROM financial_claims WHERE due_date = CURDATE() AND (amount - paid_amount) > 0")->fetch();
        $overdue = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(amount - paid_amount),0) total FROM financial_claims WHERE due_date < CURDATE() AND (amount - paid_amount) > 0")->fetch();
        $result['financial'] = [
            'dueTodayCount' => (int) $dueToday['c'], 'dueTodayAmount' => (float) $dueToday['total'],
            'overdueCount' => (int) $overdue['c'], 'overdueAmount' => (float) $overdue['total'],
        ];
    }

    /* ---- واتساب: عدد المحادثات اللي آخر رسالة فيها واردة (تحتاج متابعة) — مقياس دقيق فقط، بدون تخمين ---- */
    try {
        $needsReply = $pdo->query("
            SELECT COUNT(*) c FROM (
                SELECT phone, SUBSTRING_INDEX(GROUP_CONCAT(direction ORDER BY id DESC), ',', 1) AS lastDirection
                FROM whatsapp_messages GROUP BY phone
            ) x WHERE x.lastDirection = 'in'
        ")->fetch();
        $result['whatsapp'] = ['conversationsNeedingReply' => (int) $needsReply['c']];
    } catch (\Throwable $e) {
        // جدول واتساب قد لا يكون موجودًا على استضافات لم تُحدَّث بعد — لا نخترع رقمًا، نتخطى القسم بأمان
    }

    return $result;
}


/* =========================================================
   تقييد تسجيل الدوام بموقع الشركة (Geofencing)
   التحقق كله هون بالسيرفر — الواجهة ما بتقرر شي، بس بتبعت الإحداثيات.
   ========================================================= */

// المسافة بالأمتار بين نقطتين على سطح الأرض (معادلة Haversine)
function distanceInMeters($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371000; // نصف قطر الأرض بالمتر
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/**
 * يتحقق هل الموظف داخل نطاق الشركة المسموح.
 * يرجع: ['allowed' => bool, 'error' => string|null, 'distance' => int|null]
 */
function checkGeofence($pdo, $lat, $lng) {
    $s = $pdo->query("SELECT geofence_enabled, office_latitude, office_longitude, geofence_radius FROM settings WHERE id = 1")->fetch();

    // القيد غير مفعّل، أو المدير ما حدّد موقع الشركة بعد → نسمح عادي (سلوك النظام القديم)
    if (!$s || !(int) $s['geofence_enabled']) return ['allowed' => true, 'error' => null, 'distance' => null];
    if ($s['office_latitude'] === null || $s['office_longitude'] === null) {
        return ['allowed' => true, 'error' => null, 'distance' => null];
    }

    // القيد مفعّل لكن الجهاز ما بعث إحداثيات (رفض إذن الموقع أو GPS مطفي)
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return [
            'allowed' => false,
            'error' => 'لازم تفعّل خدمة الموقع (GPS) وتسمح للموقع بالوصول إليها عشان تقدر تسجّل حضورك من مقر الشركة.',
            'distance' => null,
        ];
    }

    $distance = distanceInMeters(
        (float) $lat, (float) $lng,
        (float) $s['office_latitude'], (float) $s['office_longitude']
    );
    $radius = (int) $s['geofence_radius'];

    if ($distance > $radius) {
        $km = $distance >= 1000 ? round($distance / 1000, 1) . ' كم' : round($distance) . ' متر';
        return [
            'allowed' => false,
            'error' => "ما تقدر تسجّل من هون — أنت على بُعد {$km} تقريبًا عن مقر الشركة. التسجيل متاح فقط من داخل مقر العمل.",
            'distance' => (int) round($distance),
        ];
    }

    return ['allowed' => true, 'error' => null, 'distance' => (int) round($distance)];
}

/* =========================================================
   قالب رسالة تذكير المطالبات المالية
   القالب قابل للتعديل من لوحة الإدارة، ويدعم متغيرات تُستبدل تلقائيًا.
   ========================================================= */

const DEFAULT_CLAIM_TEMPLATE = "مرحبًا {الاسم}،\nتذكير من *سوبر آبل*: لديك مبلغ مستحق قدره *{المبلغ}*{الوصف}{تاريخ_الاستحقاق}.\nيرجى التواصل معنا لتسوية الحساب. شكرًا لتعاونك 🌟";

/**
 * يبني نص التذكير من القالب المحفوظ + بيانات المطالبة.
 * المتغيرات الاختيارية (الوصف/التاريخ) تُستبدل بفراغ تام لو مش موجودة،
 * فما تظهر أبدًا كعلامات فاضية أو "غير محدد".
 */
function buildClaimReminderMessage($pdo, $claim) {
    $s = $pdo->query("SELECT claim_reminder_template FROM settings WHERE id = 1")->fetch();
    $template = (!empty($s['claim_reminder_template'])) ? $s['claim_reminder_template'] : DEFAULT_CLAIM_TEMPLATE;

    $remaining = round($claim['amount'] - $claim['paid_amount'], 2);
    $name = trim($claim['debtor_name'] ?? '');
    $desc = trim($claim['description'] ?? '');
    $due = !empty($claim['due_date']) ? date('d/m/Y', strtotime($claim['due_date'])) : '';

    $replacements = [
        '{الاسم}' => $name !== '' ? $name : 'عميلنا الكريم',
        '{المبلغ}' => number_format($remaining, 2, '.', ',') ,
        '{المبلغ_الكلي}' => number_format((float) $claim['amount'], 2, '.', ','),
        '{المدفوع}' => number_format((float) $claim['paid_amount'], 2, '.', ','),
        // الحقول الاختيارية: تشمل صياغتها الكاملة داخلها، أو فراغ تام لو غير موجودة
        '{الوصف}' => $desc !== '' ? " بخصوص \"{$desc}\"" : '',
        '{تاريخ_الاستحقاق}' => $due !== '' ? " (تاريخ الاستحقاق {$due})" : '',
    ];

    $msg = strtr($template, $replacements);
    // تنظيف أي فراغات مزدوجة نتجت عن استبدال متغير فارغ
    $msg = preg_replace('/[ \t]{2,}/', ' ', $msg);
    $msg = preg_replace('/\n{3,}/', "\n\n", $msg);
    return trim($msg);
}
