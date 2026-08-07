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

