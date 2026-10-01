<?php
require_once __DIR__ . '/connect.php';
requireRole('director');
mysqli_set_charset($conn, 'utf8mb4');

function dirTableExists(mysqli $conn, string $table): bool {
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function dirColumnExists(mysqli $conn, string $table, string $column): bool {
    if (!dirTableExists($conn, $table)) return false;
    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function dirScalar(mysqli $conn, string $sql, array $params = [], string $types = ''): int {
    if (!$params) {
        $res = mysqli_query($conn, $sql);
        if (!$res) return 0;
        $row = mysqli_fetch_row($res);
        return (int)($row[0] ?? 0);
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row[0] ?? 0);
}

function dirRows(mysqli $conn, string $sql, array $params = [], string $types = ''): array {
    $rows = [];
    if (!$params) {
        $res = mysqli_query($conn, $sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}

function dirH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dirThaiDate(?string $value, bool $withTime = false): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return dirH($value);
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $text = (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
    if ($withTime) $text .= ' ' . date('H:i', $ts) . ' น.';
    return $text;
}

function dirAdlGroup(?int $score): array {
    if ($score === null) return ['ยังไม่ประเมิน', 'neutral'];
    if ($score >= 12) return ['ติดสังคม', 'social'];
    if ($score >= 5) return ['ติดบ้าน', 'home'];
    return ['ติดเตียง', 'bed'];
}

function dirRoleCount(mysqli $conn, string $role): int {
    if (!dirTableExists($conn, 'users')) return 0;
    return dirScalar($conn, "SELECT COUNT(*) FROM users WHERE role=?", [$role], 's');
}

function dirVillageExpr(mysqli $conn): string {
    $parts = [];
    if (dirColumnExists($conn, 'village', 'villagename')) $parts[] = "NULLIF(TRIM(v.villagename),'')";
    foreach (['villagename','Village_name','VillageName','Village','village_name','Address'] as $col) {
        if (dirColumnExists($conn, 'patient', $col)) {
            $parts[] = "NULLIF(TRIM(p.`{$col}`),'')";
            break;
        }
    }
    if (!$parts) return "'-'";
    return 'COALESCE(' . implode(',', $parts) . ", '-')";
}

function dirPatientMetrics(mysqli $conn): array {
    $hasPatient = dirTableExists($conn, 'patient');
    $hasAssign = dirTableExists($conn, 'patient_caregiver_assignment');
    $hasVisit = dirTableExists($conn, 'caregiver_visit_record');
    $hasAdl = dirTableExists($conn, 'adl_assessment');

    $patients = $hasPatient ? dirScalar($conn, 'SELECT COUNT(*) FROM patient') : 0;
    $villages = dirTableExists($conn, 'village') ? dirScalar($conn, 'SELECT COUNT(*) FROM village') : 0;
    $caregivers = dirRoleCount($conn, 'caregiver');
    $doctors = dirRoleCount($conn, 'doctor');
    $assigned = $hasAssign ? dirScalar($conn, 'SELECT COUNT(DISTINCT patient_id) FROM patient_caregiver_assignment') : 0;
    $visited = $hasVisit ? dirScalar($conn, 'SELECT COUNT(DISTINCT patient_id) FROM caregiver_visit_record') : 0;
    $visits = $hasVisit ? dirScalar($conn, 'SELECT COUNT(*) FROM caregiver_visit_record') : 0;
    $pendingVisits = max(0, $patients - $visited);
    $adlDone = 0;
    $adlPending = $patients;
    $avgScore = 0;
    $adlGroups = ['ติดสังคม'=>0,'ติดบ้าน'=>0,'ติดเตียง'=>0,'ยังไม่ประเมิน'=>0];

    if ($hasAdl) {
        $latest = dirRows($conn, "SELECT a.patient_id, a.total_score
            FROM adl_assessment a
            INNER JOIN (
                SELECT patient_id, MAX(adl_id) latest_id
                FROM adl_assessment
                GROUP BY patient_id
            ) x ON x.latest_id = a.adl_id");
        $adlDone = count($latest);
        $adlPending = max(0, $patients - $adlDone);
        $sum = 0; $cnt = 0;
        foreach ($latest as $row) {
            $score = isset($row['total_score']) && $row['total_score'] !== '' ? (int)$row['total_score'] : null;
            [$group] = dirAdlGroup($score);
            if (!isset($adlGroups[$group])) $adlGroups[$group] = 0;
            $adlGroups[$group]++;
            if ($score !== null) { $sum += $score; $cnt++; }
        }
        $avgScore = $cnt > 0 ? (int)round($sum / $cnt) : 0;
    } else {
        $adlGroups['ยังไม่ประเมิน'] = $patients;
    }

    return [
        'patients' => $patients,
        'villages' => $villages,
        'caregivers' => $caregivers,
        'doctors' => $doctors,
        'assigned' => $assigned,
        'visits' => $visits,
        'visited' => $visited,
        'pending_visits' => $pendingVisits,
        'adl_done' => $adlDone,
        'adl_pending' => $adlPending,
        'avg_score' => $avgScore,
        'adl_groups' => $adlGroups,
    ];
}

function dirFetchPatientRows(mysqli $conn, string $search = ''): array {
    if (!dirTableExists($conn, 'patient')) return [];
    $hasAssign = dirTableExists($conn, 'patient_caregiver_assignment');
    $hasVisit = dirTableExists($conn, 'caregiver_visit_record');
    $hasAdl = dirTableExists($conn, 'adl_assessment');
    $hasVillage = dirTableExists($conn, 'village') && dirColumnExists($conn, 'patient', 'Village_id') && dirColumnExists($conn, 'village', 'village_id');
    $villageExpr = dirVillageExpr($conn) . ' AS village_name';

    $sql = "SELECT p.Patient_id, p.Fullname, ".
           (dirColumnExists($conn, 'patient', 'Age') ? "p.Age" : "NULL") . " AS Age, " .
           (dirColumnExists($conn, 'patient', 'Gender') ? "p.Gender" : "NULL") . " AS Gender, " .
           (dirColumnExists($conn, 'patient', 'Disease') ? "p.Disease" : "NULL") . " AS Disease, " .
           $villageExpr . ",\n" .
           ($hasAssign ? "cg.display_name AS caregiver_name, doc.display_name AS doctor_name," : "NULL AS caregiver_name, NULL AS doctor_name,") . "\n" .
           ($hasVisit ? "lv.visit_date AS last_visit_date, lv.next_visit_date AS next_visit_date," : "NULL AS last_visit_date, NULL AS next_visit_date,") . "\n" .
           ($hasAdl ? "la.total_score AS latest_score, la.assessment_date AS assessment_date" : "NULL AS latest_score, NULL AS assessment_date") . "\n" .
           "FROM patient p\n" .
           ($hasVillage ? "LEFT JOIN village v ON v.village_id = p.Village_id\n" : "") .
           ($hasAssign ? "LEFT JOIN patient_caregiver_assignment a ON a.patient_id = p.Patient_id\nLEFT JOIN users cg ON cg.user_id = a.caregiver_user_id\nLEFT JOIN users doc ON doc.user_id = a.doctor_user_id\n" : "") .
           ($hasVisit ? "LEFT JOIN (SELECT vr1.patient_id, vr1.visit_date, vr1.next_visit_date FROM caregiver_visit_record vr1 INNER JOIN (SELECT patient_id, MAX(visit_id) max_id FROM caregiver_visit_record GROUP BY patient_id) vr2 ON vr2.max_id = vr1.visit_id) lv ON lv.patient_id = p.Patient_id\n" : "") .
           ($hasAdl ? "LEFT JOIN (SELECT a1.patient_id, a1.total_score, a1.assessment_date FROM adl_assessment a1 INNER JOIN (SELECT patient_id, MAX(adl_id) max_id FROM adl_assessment GROUP BY patient_id) a2 ON a2.max_id = a1.adl_id) la ON la.patient_id = p.Patient_id\n" : "") .
           "WHERE 1=1";

    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= " AND (p.Fullname LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
        if (dirColumnExists($conn, 'patient', 'Disease')) { $sql .= " OR p.Disease LIKE ?"; $params[] = "%{$search}%"; $types .= 's'; }
        $sql .= ")";
    }
    $sql .= " ORDER BY p.Patient_id DESC LIMIT 200";
    return dirRows($conn, $sql, $params, $types);
}

function dirFetchVisitRows(mysqli $conn, string $month = '', string $search = ''): array {
    if (!dirTableExists($conn, 'caregiver_visit_record')) return [];
    $start = null; $end = null;
    if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        $start = $m[1] . '-' . $m[2] . '-01';
        $end = date('Y-m-t', strtotime($start));
    }
    $sql = "SELECT vr.visit_id, vr.patient_id, vr.visit_date, vr.visit_time, vr.visit_no, vr.visit_type, vr.purpose, vr.general_condition,
            vr.next_visit_date, vr.referral_type, vr.referral_reason,
            p.Fullname,
            cg.display_name caregiver_name, doc.display_name doctor_name
        FROM caregiver_visit_record vr
        LEFT JOIN patient p ON p.Patient_id = vr.patient_id
        LEFT JOIN patient_caregiver_assignment a ON a.assignment_id = vr.assignment_id
        LEFT JOIN users cg ON cg.user_id = vr.caregiver_user_id
        LEFT JOIN users doc ON doc.user_id = a.doctor_user_id
        WHERE 1=1";
    $params = [];$types='';
    if ($start && $end) {
        $sql .= " AND vr.visit_date BETWEEN ? AND ?";
        $params[] = $start; $params[] = $end; $types .= 'ss';
    }
    if ($search !== '') {
        $sql .= " AND (p.Fullname LIKE ? OR cg.display_name LIKE ? OR vr.visit_type LIKE ?)";
        $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $types .= 'sss';
    }
    $sql .= " ORDER BY vr.visit_date DESC, vr.visit_time DESC, vr.visit_id DESC LIMIT 200";
    return dirRows($conn, $sql, $params, $types);
}

function dirVisitMetrics(mysqli $conn, string $month = ''): array {
    $patients = dirTableExists($conn, 'patient') ? dirScalar($conn, 'SELECT COUNT(*) FROM patient') : 0;
    if (!dirTableExists($conn, 'caregiver_visit_record')) {
        return ['total'=>0,'monthTotal'=>0,'pending'=>$patients,'referrals'=>0,'followups'=>0];
    }
    $total = dirScalar($conn, 'SELECT COUNT(*) FROM caregiver_visit_record');
    $monthTotal = $total;
    if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        $start = $m[1] . '-' . $m[2] . '-01';
        $end = date('Y-m-t', strtotime($start));
        $monthTotal = dirScalar($conn, 'SELECT COUNT(*) FROM caregiver_visit_record WHERE visit_date BETWEEN ? AND ?', [$start, $end], 'ss');
    }
    $visited = dirScalar($conn, 'SELECT COUNT(DISTINCT patient_id) FROM caregiver_visit_record');
    $pending = max(0, $patients - $visited);
    $referrals = dirScalar($conn, "SELECT COUNT(*) FROM caregiver_visit_record WHERE COALESCE(NULLIF(TRIM(referral_type),''),'ไม่ส่งต่อ') <> 'ไม่ส่งต่อ'");
    $followups = dirScalar($conn, 'SELECT COUNT(*) FROM caregiver_visit_record WHERE next_visit_date IS NOT NULL AND next_visit_date <> "0000-00-00"');
    return compact('total','monthTotal','pending','referrals','followups');
}

function dirFetchAssessmentRows(mysqli $conn, string $search = ''): array {
    if (!dirTableExists($conn, 'adl_assessment')) return [];

    $hasAssessmentDate = dirColumnExists($conn, 'adl_assessment', 'assessment_date');
    $hasAssessedAt = dirColumnExists($conn, 'adl_assessment', 'assessed_at');
    $hasCreatedAt = dirColumnExists($conn, 'adl_assessment', 'created_at');
    $hasDoctorUser = dirColumnExists($conn, 'adl_assessment', 'doctor_user_id');
    $hasCaregiverUser = dirColumnExists($conn, 'adl_assessment', 'caregiver_user_id');

    $assessmentDateSelect = $hasAssessmentDate ? 'a.assessment_date AS assessment_date' : 'NULL AS assessment_date';
    if ($hasAssessedAt) {
        $recordedAtSelect = 'a.assessed_at AS assessed_at';
        $recordedAtOrder = 'a.assessed_at';
    } elseif ($hasCreatedAt) {
        $recordedAtSelect = 'a.created_at AS assessed_at';
        $recordedAtOrder = 'a.created_at';
    } else {
        $recordedAtSelect = 'NULL AS assessed_at';
        $recordedAtOrder = 'NULL';
    }

    $sql = "SELECT a.adl_id, a.patient_id, a.total_score, {$assessmentDateSelect}, {$recordedAtSelect},
            p.Fullname,
            " . ($hasDoctorUser ? "doc.display_name doctor_name" : "NULL AS doctor_name") . ",
            " . ($hasCaregiverUser ? "cg.display_name caregiver_name" : "NULL AS caregiver_name") . "
        FROM adl_assessment a
        JOIN patient p ON p.Patient_id = a.patient_id
        " . ($hasDoctorUser ? "LEFT JOIN users doc ON doc.user_id = a.doctor_user_id
" : "") .
        ($hasCaregiverUser ? "LEFT JOIN users cg ON cg.user_id = a.caregiver_user_id
" : "") .
        "INNER JOIN (SELECT patient_id, MAX(adl_id) max_id FROM adl_assessment GROUP BY patient_id) x ON x.max_id = a.adl_id
        WHERE 1=1";
    $params=[];$types='';
    if ($search !== '') {
        $sql .= ' AND p.Fullname LIKE ?';
        $params[] = "%{$search}%"; $types .= 's';
    }
    if ($hasAssessmentDate && ($hasAssessedAt || $hasCreatedAt)) {
        $sql .= " ORDER BY COALESCE(a.assessment_date, DATE({$recordedAtOrder})) DESC, {$recordedAtOrder} DESC, a.adl_id DESC LIMIT 200";
    } elseif ($hasAssessmentDate) {
        $sql .= ' ORDER BY a.assessment_date DESC, a.adl_id DESC LIMIT 200';
    } elseif ($hasAssessedAt || $hasCreatedAt) {
        $sql .= " ORDER BY {$recordedAtOrder} DESC, a.adl_id DESC LIMIT 200";
    } else {
        $sql .= ' ORDER BY a.adl_id DESC LIMIT 200';
    }
    return dirRows($conn, $sql, $params, $types);
}

function dirAssessmentMetrics(mysqli $conn): array {
    $metrics = dirPatientMetrics($conn);
    return [
        'done' => $metrics['adl_done'],
        'pending' => $metrics['adl_pending'],
        'avg_score' => $metrics['avg_score'],
        'groups' => $metrics['adl_groups'],
    ];
}

function dirPageHeader(string $eyebrow, string $title, string $desc): void {
    echo '<section class="director-hero">';
    echo '<div class="director-hero__eyebrow">' . dirH($eyebrow) . '</div>';
    echo '<div class="director-hero__main">';
    echo '<div><h1>' . dirH($title) . '</h1><p>' . dirH($desc) . '</p></div>';
    echo '<div class="director-date">ข้อมูล ณ ' . dirH(dirThaiDate(date('Y-m-d'))) . '</div>';
    echo '</div></section>';
}
