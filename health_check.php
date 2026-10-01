<?php
require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

$message = '';
$error = '';
$doctorId = (int)($_SESSION['user_id'] ?? 0);

$create = "CREATE TABLE IF NOT EXISTS health_assessment (
    health_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_user_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    height_cm DECIMAL(6,2) NULL,
    weight_kg DECIMAL(7,2) NULL,
    waist_cm DECIMAL(7,2) NULL,
    hip_cm DECIMAL(7,2) NULL,
    waist_hip_ratio DECIMAL(7,3) NULL,
    body_fat_percent DECIMAL(6,2) NULL,
    visceral_fat_level DECIMAL(6,2) NULL,
    bmr DECIMAL(9,2) NULL,
    bmi DECIMAL(6,2) NULL,
    body_age DECIMAL(6,2) NULL,
    subcutaneous_whole DECIMAL(7,2) NULL,
    subcutaneous_trunk DECIMAL(7,2) NULL,
    subcutaneous_arms DECIMAL(7,2) NULL,
    subcutaneous_legs DECIMAL(7,2) NULL,
    muscle_whole DECIMAL(7,2) NULL,
    muscle_trunk DECIMAL(7,2) NULL,
    muscle_arms DECIMAL(7,2) NULL,
    muscle_legs DECIMAL(7,2) NULL,
    fat_mass DECIMAL(8,2) NULL,
    fat_free_mass DECIMAL(8,2) NULL,
    dtx DECIMAL(8,2) NULL,
    blood_pressure VARCHAR(30) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_health_patient (patient_id),
    KEY idx_health_doctor (doctor_user_id),
    KEY idx_health_date (assessment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $create)) {
    $error = 'ไม่สามารถเตรียมตารางแบบประเมินสุขภาพได้: ' . mysqli_error($conn);
}

function postNumber(string $name): ?float {
    $value = trim((string)($_POST[$name] ?? ''));
    if ($value === '' || !is_numeric($value)) return null;
    return (float)$value;
}

$patients = [];
$patientSql = "SELECT Patient_id, Fullname, Gender, Age, Address, Phone, Disease FROM patient ORDER BY Fullname ASC";
$patientResult = mysqli_query($conn, $patientSql);
if ($patientResult) {
    while ($row = mysqli_fetch_assoc($patientResult)) $patients[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $date = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));
    $height = postNumber('height_cm');
    $weight = postNumber('weight_kg');
    $waist = postNumber('waist_cm');
    $hip = postNumber('hip_cm');
    $whr = postNumber('waist_hip_ratio');
    $fat = postNumber('body_fat_percent');
    $visceral = postNumber('visceral_fat_level');
    $bmr = postNumber('bmr');
    $bmi = postNumber('bmi');
    $bodyAge = postNumber('body_age');
    $subWhole = postNumber('subcutaneous_whole');
    $subTrunk = postNumber('subcutaneous_trunk');
    $subArms = postNumber('subcutaneous_arms');
    $subLegs = postNumber('subcutaneous_legs');
    $muscleWhole = postNumber('muscle_whole');
    $muscleTrunk = postNumber('muscle_trunk');
    $muscleArms = postNumber('muscle_arms');
    $muscleLegs = postNumber('muscle_legs');
    $fatMass = postNumber('fat_mass');
    $fatFree = postNumber('fat_free_mass');
    $dtx = postNumber('dtx');
    $bp = trim((string)($_POST['blood_pressure'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($patientId <= 0) {
        $error = 'กรุณาเลือกผู้สูงอายุ';
    } elseif ($date === '') {
        $error = 'กรุณาระบุวันที่ประเมิน';
    } else {
        if ($whr === null && $waist !== null && $hip !== null && $hip > 0) $whr = $waist / $hip;
        if ($bmi === null && $height !== null && $height > 0 && $weight !== null) {
            $heightM = $height / 100;
            $bmi = $weight / ($heightM * $heightM);
        }
        if ($fatMass === null && $fat !== null && $weight !== null) $fatMass = ($fat / 100) * $weight;
        if ($fatFree === null && $weight !== null && $fatMass !== null) $fatFree = $weight - $fatMass;

        $sql = "INSERT INTO health_assessment (
            patient_id, doctor_user_id, assessment_date, height_cm, weight_kg, waist_cm, hip_cm,
            waist_hip_ratio, body_fat_percent, visceral_fat_level, bmr, bmi, body_age,
            subcutaneous_whole, subcutaneous_trunk, subcutaneous_arms, subcutaneous_legs,
            muscle_whole, muscle_trunk, muscle_arms, muscle_legs, fat_mass, fat_free_mass,
            dtx, blood_pressure, note
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'iisdddddddddddddddddddddss',
                $patientId, $doctorId, $date, $height, $weight, $waist, $hip,
                $whr, $fat, $visceral, $bmr, $bmi, $bodyAge,
                $subWhole, $subTrunk, $subArms, $subLegs,
                $muscleWhole, $muscleTrunk, $muscleArms, $muscleLegs, $fatMass, $fatFree,
                $dtx, $bp, $note
            );
            if (mysqli_stmt_execute($stmt)) {
                $message = 'บันทึกแบบประเมินภาวะสุขภาพเรียบร้อยแล้ว';
                $_POST = [];
            } else {
                $error = 'บันทึกไม่สำเร็จ: ' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = 'ไม่สามารถเตรียมคำสั่งบันทึกได้: ' . mysqli_error($conn);
        }
    }
}

$records = [];
$stmt = mysqli_prepare($conn, "SELECT h.health_id,h.assessment_date,h.weight_kg,h.bmi,h.body_fat_percent,h.fat_free_mass,h.dtx,h.blood_pressure,h.created_at,p.Fullname
    FROM health_assessment h JOIN patient p ON p.Patient_id=h.patient_id
    WHERE h.doctor_user_id=? ORDER BY h.assessment_date DESC,h.health_id DESC LIMIT 100");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $doctorId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $records[] = $row;
    mysqli_stmt_close($stmt);
}

function oldValue(string $name): string {
    return htmlspecialchars((string)($_POST[$name] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(appDocumentTitle('ตรวจสุขภาพ')) ?></title>
<link rel="stylesheet" href="assets/pastel_theme.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>

<div class="page-head">
    <div>
        <h1>แบบประเมินภาวะสุขภาพ</h1>
        <p>บันทึกข้อมูลตามแบบประเมิน Body composition ของ <?= e(appName()) ?></p>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <h2>ข้อมูลการประเมิน</h2>
    <form method="post" id="healthForm">
        <div class="grid-3">
            <div class="field">
                <label>ผู้สูงอายุ <span class="required-star" style="color:#d93025!important">*</span></label>
                <select name="patient_id" id="patientSelect" required>
                    <option value="">-- เลือกผู้สูงอายุ --</option>
                    <?php foreach ($patients as $p): ?>
                        <option
                            value="<?= (int)$p['Patient_id'] ?>"
                            data-name="<?= e($p['Fullname']) ?>"
                            data-gender="<?= e($p['Gender'] ?? '') ?>"
                            data-age="<?= e((string)($p['Age'] ?? '')) ?>"
                            data-address="<?= e($p['Address'] ?? '') ?>"
                            data-phone="<?= e($p['Phone'] ?? '') ?>"
                            data-disease="<?= e($p['Disease'] ?? '') ?>"
                            <?= ((string)($_POST['patient_id'] ?? '') === (string)$p['Patient_id']) ? 'selected' : '' ?>
                        ><?= e($p['Fullname']) ?><?= !empty($p['Age']) ? ' (' . (int)$p['Age'] . ' ปี)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>วันที่ประเมิน <span class="required-star" style="color:#d93025!important">*</span></label>
                <input type="date" name="assessment_date" value="<?= oldValue('assessment_date') ?: e(date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label>ส่วนสูง (cm)</label>
                <input type="number" min="0" step="0.01" name="height_cm" id="height_cm" value="<?= oldValue('height_cm') ?>" placeholder="เช่น 160">
            </div>
        </div>

        <div class="patient-summary" id="patientSummary">
            <div class="summary-item"><span>ชื่อ-สกุล</span><strong id="summaryName">-</strong></div>
            <div class="summary-item"><span>อายุ</span><strong id="summaryAge">-</strong></div>
            <div class="summary-item"><span>เพศ</span><strong id="summaryGender">-</strong></div>
            <div class="summary-item"><span>เบอร์โทร</span><strong id="summaryPhone">-</strong></div>
            <div class="summary-item full"><span>ที่อยู่</span><strong id="summaryAddress">-</strong></div>
            <div class="summary-item full"><span>โรคประจำตัว/ภาวะการเจ็บป่วย</span><strong id="summaryDisease">-</strong></div>
        </div>

        <div class="table-wrap">
            <table class="measurement-table">
                <thead>
                    <tr><th>ลำดับ</th><th>รายการวัด</th><th>หน่วยวัด</th><th>ค่าปกติ/เกณฑ์อ้างอิง</th><th>ค่าที่ประเมิน</th></tr>
                </thead>
                <tbody>
                    <tr><td class="measure-no">1</td><td>น้ำหนัก</td><td class="measure-unit">กิโลกรัม</td><td class="normal">-</td><td><input type="number" min="0" step="0.01" name="weight_kg" id="weight_kg" value="<?= oldValue('weight_kg') ?>"></td></tr>
                    <tr><td class="measure-no">2</td><td>รอบเอว</td><td class="measure-unit">เซนติเมตร</td><td class="normal">ชาย &lt; 90 / หญิง &lt; 80</td><td><input type="number" min="0" step="0.01" name="waist_cm" id="waist_cm" value="<?= oldValue('waist_cm') ?>"></td></tr>
                    <tr><td class="measure-no">3</td><td>รอบสะโพก</td><td class="measure-unit">เซนติเมตร</td><td class="normal">-</td><td><input type="number" min="0" step="0.01" name="hip_cm" id="hip_cm" value="<?= oldValue('hip_cm') ?>"></td></tr>
                    <tr><td class="measure-no">4</td><td>อัตราส่วนเอวสะโพก</td><td class="measure-unit">-</td><td class="normal">ชาย &lt; 0.8 / หญิง &lt; 0.7</td><td><input type="number" step="0.001" name="waist_hip_ratio" id="waist_hip_ratio" value="<?= oldValue('waist_hip_ratio') ?>" placeholder="คำนวณอัตโนมัติได้"></td></tr>
                    <tr><td class="measure-no">5</td><td>ระดับเปอร์เซ็นต์ไขมันร่างกาย</td><td class="measure-unit">%</td><td class="normal">ชาย 10-19% / หญิง 20-29%</td><td><input type="number" min="0" step="0.01" name="body_fat_percent" id="body_fat_percent" value="<?= oldValue('body_fat_percent') ?>"></td></tr>
                    <tr><td class="measure-no">6</td><td>ระดับไขมันช่องท้อง</td><td class="measure-unit">ระดับ</td><td class="normal">1-5</td><td><input type="number" min="0" step="0.01" name="visceral_fat_level" value="<?= oldValue('visceral_fat_level') ?>"></td></tr>
                    <tr><td class="measure-no">7</td><td>อัตราการเผาผลาญขณะพัก (BMR)</td><td class="measure-unit">กิโลแคลอรี่</td><td class="normal">-</td><td><input type="number" min="0" step="0.01" name="bmr" value="<?= oldValue('bmr') ?>"></td></tr>
                    <tr><td class="measure-no">8</td><td>ค่าดัชนีมวลกาย (BMI)</td><td class="measure-unit">กิโลกรัม/ตารางเมตร</td><td class="normal">18.5-22.9</td><td><input type="number" min="0" step="0.01" name="bmi" id="bmi" value="<?= oldValue('bmi') ?>" placeholder="คำนวณอัตโนมัติได้"></td></tr>
                    <tr><td class="measure-no">9</td><td>อายุร่างกาย (Body Age: อายุโดยประมาณจากองค์ประกอบร่างกาย ไม่ใช่อายุจริง)</td><td class="measure-unit">ปี</td><td class="normal">อายุจริงของผู้สูงอายุ (ใช้เป็นค่าอ้างอิงเพื่อเปรียบเทียบกับค่า Body Age ที่เครื่องวัดได้)</td><td><input type="number" min="0" step="0.01" name="body_age" value="<?= oldValue('body_age') ?>"></td></tr>

                    <tr class="group-row"><td colspan="5">ระดับเปอร์เซ็นต์ไขมันใต้ผิวหนัง — ช่วงที่เครื่องวัดได้ 5.0–60.0% | ระดับ: ต่ำ / ปกติ / สูง / สูงมาก</td></tr>
                    <tr><td class="measure-no">10</td><td>ทั้งตัว</td><td class="measure-unit">%</td><td class="normal">0,-1,-2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, -1 = ต่ำ, -2 = ต่ำมาก)</td><td><input type="number" step="0.01" name="subcutaneous_whole" value="<?= oldValue('subcutaneous_whole') ?>"></td></tr>
                    <tr><td class="measure-no">11</td><td>ช่วงลำตัว</td><td class="measure-unit">%</td><td class="normal">0,-1,-2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, -1 = ต่ำ, -2 = ต่ำมาก)</td><td><input type="number" step="0.01" name="subcutaneous_trunk" value="<?= oldValue('subcutaneous_trunk') ?>"></td></tr>
                    <tr><td class="measure-no">12</td><td>ช่วงแขน</td><td class="measure-unit">%</td><td class="normal">0,-1,-2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, -1 = ต่ำ, -2 = ต่ำมาก)</td><td><input type="number" step="0.01" name="subcutaneous_arms" value="<?= oldValue('subcutaneous_arms') ?>"></td></tr>
                    <tr><td class="measure-no">13</td><td>ช่วงขา</td><td class="measure-unit">%</td><td class="normal">0,-1,-2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, -1 = ต่ำ, -2 = ต่ำมาก)</td><td><input type="number" step="0.01" name="subcutaneous_legs" value="<?= oldValue('subcutaneous_legs') ?>"></td></tr>

                    <tr class="group-row"><td colspan="5">ระดับเปอร์เซ็นต์กล้ามเนื้อ — ค่าปกติอายุ 60–80 ปี: ชาย 32.9–38.9% / หญิง 23.9–29.9%</td></tr>
                    <tr><td class="measure-no">14</td><td>ทั้งตัว</td><td class="measure-unit">%</td><td class="normal">0,+1,+2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, +1 = สูง, +2 = สูงมาก)</td><td><input type="number" step="0.01" name="muscle_whole" value="<?= oldValue('muscle_whole') ?>"></td></tr>
                    <tr><td class="measure-no">15</td><td>ช่วงลำตัว</td><td class="measure-unit">%</td><td class="normal">0,+1,+2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, +1 = สูง, +2 = สูงมาก)</td><td><input type="number" step="0.01" name="muscle_trunk" value="<?= oldValue('muscle_trunk') ?>"></td></tr>
                    <tr><td class="measure-no">16</td><td>ช่วงแขน</td><td class="measure-unit">%</td><td class="normal">0,+1,+2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, +1 = สูง, +2 = สูงมาก)</td><td><input type="number" step="0.01" name="muscle_arms" value="<?= oldValue('muscle_arms') ?>"></td></tr>
                    <tr><td class="measure-no">17</td><td>ช่วงขา</td><td class="measure-unit">%</td><td class="normal">0,+1,+2 (เครื่องนำค่าที่วัดได้ไปเทียบกับช่วงปกติ: 0 = ปกติ, +1 = สูง, +2 = สูงมาก)</td><td><input type="number" step="0.01" name="muscle_legs" value="<?= oldValue('muscle_legs') ?>"></td></tr>

                    <tr class="group-row"><td colspan="5">มวลกายไร้ไขมัน</td></tr>
                    <tr><td class="measure-no">18</td><td>ค่ามวลกายไร้ไขมัน</td><td class="measure-unit">กิโลกรัม</td><td class="normal">-</td><td><input type="number" step="0.01" name="fat_free_mass" id="fat_free_mass" value="<?= oldValue('fat_free_mass') ?>" placeholder="คำนวณอัตโนมัติได้"></td></tr>
                    <tr><td class="measure-no">19</td><td>ค่าน้ำตาลปลายนิ้ว (DTX)</td><td class="measure-unit">mg/dL</td><td class="normal">เก็บเป็นค่าตัวเลขจากเครื่องตรวจน้ำตาลปลายนิ้ว</td><td><input type="number" min="0" step="0.01" inputmode="decimal" name="dtx" value="<?= oldValue('dtx') ?>" placeholder="เช่น 105"></td></tr>
                    <tr><td class="measure-no">20</td><td>ค่าความดันโลหิต (BP)</td><td class="measure-unit">-</td><td class="normal">-</td><td><input type="text" name="blood_pressure" value="<?= oldValue('blood_pressure') ?>" placeholder="เช่น 120/80"></td></tr>
                </tbody>
            </table>
        </div>

        <div class="section-title yellow">การคำนวณมวลกายไร้ไขมัน</div>
        <div class="grid">
            <div class="field">
                <label>ขั้นที่ 1: มวลไขมัน = เปอร์เซ็นต์ไขมันร่างกาย × น้ำหนัก ÷ 100</label>
                <input type="number" step="0.01" name="fat_mass" id="fat_mass" value="<?= oldValue('fat_mass') ?>" placeholder="คำนวณอัตโนมัติได้">
            </div>
            <div class="field">
                <label>ขั้นที่ 2: มวลกายไร้ไขมัน = น้ำหนัก - มวลไขมัน</label>
                <input type="text" id="leanPreview" readonly value="-">
            </div>
        </div>

        <div class="field" style="margin-top:15px">
            <label>หมายเหตุ</label>
            <textarea name="note" placeholder="บันทึกข้อมูลเพิ่มเติม (ถ้ามี)"><?= oldValue('note') ?></textarea>
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">บันทึกผลการประเมิน</button></div>
    </form>
</section>

<section class="card">
    <h2>ประวัติการประเมินล่าสุด</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>วันที่</th><th>ผู้สูงอายุ</th><th>น้ำหนัก</th><th>BMI</th><th>ไขมันร่างกาย</th><th>มวลกายไร้ไขมัน</th><th>DTX (mg/dL)</th><th>BP</th></tr></thead>
            <tbody>
            <?php if (!$records): ?><tr><td colspan="8" class="empty">ยังไม่มีประวัติการประเมิน</td></tr><?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= e($r['assessment_date']) ?></td>
                    <td><strong><?= e($r['Fullname']) ?></strong></td>
                    <td><?= $r['weight_kg'] !== null ? e($r['weight_kg']) . ' กก.' : '-' ?></td>
                    <td><?= $r['bmi'] !== null ? e($r['bmi']) : '-' ?></td>
                    <td><?= $r['body_fat_percent'] !== null ? e($r['body_fat_percent']) . '%' : '-' ?></td>
                    <td><?= $r['fat_free_mass'] !== null ? e($r['fat_free_mass']) . ' กก.' : '-' ?></td>
                    <td><?= $r['dtx'] !== null ? e($r['dtx']) : '-' ?></td>
                    <td><?= e($r['blood_pressure'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</main>

<script>
(function(){
    const patient = document.getElementById('patientSelect');
    const ids = ['weight_kg','waist_cm','hip_cm','body_fat_percent','height_cm'];
    const els = Object.fromEntries(ids.map(id => [id, document.getElementById(id)]));
    const whr = document.getElementById('waist_hip_ratio');
    const bmi = document.getElementById('bmi');
    const fatMass = document.getElementById('fat_mass');
    const fatFree = document.getElementById('fat_free_mass');
    const leanPreview = document.getElementById('leanPreview');

    function setText(id, value, suffix=''){
        document.getElementById(id).textContent = value ? value + suffix : '-';
    }
    function updatePatient(){
        const opt = patient.options[patient.selectedIndex];
        if (!opt || !opt.value) {
            ['summaryName','summaryAge','summaryGender','summaryPhone','summaryAddress','summaryDisease'].forEach(id => setText(id,''));
            return;
        }
        setText('summaryName', opt.dataset.name || '');
        setText('summaryAge', opt.dataset.age || '', opt.dataset.age ? ' ปี' : '');
        setText('summaryGender', opt.dataset.gender || '');
        setText('summaryPhone', opt.dataset.phone || '');
        setText('summaryAddress', opt.dataset.address || '');
        setText('summaryDisease', opt.dataset.disease || 'ไม่มีข้อมูล');
    }
    function num(el){ const v=parseFloat(el && el.value); return Number.isFinite(v)?v:null; }
    function calc(){
        const weight=num(els.weight_kg), waist=num(els.waist_cm), hip=num(els.hip_cm), fat=num(els.body_fat_percent), height=num(els.height_cm);
        if (waist!==null && hip!==null && hip>0 && document.activeElement!==whr) whr.value=(waist/hip).toFixed(3);
        if (weight!==null && height!==null && height>0 && document.activeElement!==bmi) {
            const hm=height/100; bmi.value=(weight/(hm*hm)).toFixed(2);
        }
        if (weight!==null && fat!==null) {
            const fm=(fat/100)*weight;
            if (document.activeElement!==fatMass) fatMass.value=fm.toFixed(2);
            const lean=weight-fm;
            if (document.activeElement!==fatFree) fatFree.value=lean.toFixed(2);
            leanPreview.value=lean.toFixed(2)+' กิโลกรัม';
        } else {
            leanPreview.value='-';
        }
    }
    patient.addEventListener('change', updatePatient);
    ids.forEach(id => els[id] && els[id].addEventListener('input', calc));
    fatMass.addEventListener('input', function(){
        const weight=num(els.weight_kg), fm=num(fatMass);
        if(weight!==null && fm!==null){const lean=weight-fm;fatFree.value=lean.toFixed(2);leanPreview.value=lean.toFixed(2)+' กิโลกรัม';}
    });
    fatFree.addEventListener('input', function(){leanPreview.value=fatFree.value ? fatFree.value+' กิโลกรัม' : '-';});
    updatePatient(); calc();
})();
</script>
</body>
</html>
