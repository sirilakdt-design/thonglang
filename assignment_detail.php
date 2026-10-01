<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

function detailThaiDate(?string $value, bool $withTime = false): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $text = (int)date('j',$ts).' '.$months[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
    if ($withTime) $text .= ' '.date('H:i',$ts).' น.';
    return $text;
}

function detailAdlGroup($score): string
{
    if ($score === null || $score === '') return '-';
    $score = (int)$score;
    if ($score >= 12) return 'ติดสังคม';
    if ($score >= 5) return 'ติดบ้าน';
    return 'ติดเตียง';
}

$assignmentId = (int)($_GET['id'] ?? 0);
$currentDoctorId = (int)($_SESSION['user_id'] ?? 0);
$row = null;
$error = '';

if ($assignmentId <= 0) {
    $error = 'ไม่พบรหัสรายการการมอบหมาย';
} else {
    $sql = "SELECT
                a.assignment_id,
                a.patient_id,
                a.caregiver_user_id,
                a.doctor_user_id,
                a.assigned_at,
                a.care_status,
                a.care_pause_reason,
                a.care_end_reason,
                a.care_status_note,
                a.care_status_updated_at,
                a.next_visit_date,
                p.Fullname,
                p.Age,
                p.Gender,
                p.Disease,
                p.Phone,
                p.Photo,
                cg.username AS caregiver_username,
                cg.display_name AS caregiver_name,
                dr.username AS doctor_username,
                dr.display_name AS doctor_name,
                adl.adl_id,
                adl.assessment_date,
                adl.total_score AS doctor_adl_score,
                adl.caregiver_total_score AS caregiver_adl_score,
                adl.result_returned_to_doctor,
                adl.result_returned_at,
                adl.regular_caregiver,
                adl.welfare_status,
                adl.club_membership,
                adl.note AS adl_note
            FROM patient_caregiver_assignment a
            JOIN patient p ON p.Patient_id=a.patient_id
            JOIN users cg ON cg.user_id=a.caregiver_user_id AND cg.role='caregiver'
            LEFT JOIN users dr ON dr.user_id=a.doctor_user_id AND dr.role='doctor'
            LEFT JOIN adl_assessment adl ON adl.adl_id=(
                SELECT MAX(a2.adl_id)
                FROM adl_assessment a2
                WHERE a2.patient_id=a.patient_id
                  AND a2.doctor_user_id=a.doctor_user_id
            )
            WHERE a.assignment_id=? AND a.doctor_user_id=?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $assignmentId, $currentDoctorId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if (!$row) $error = 'ไม่พบรายการการมอบหมาย หรือบัญชีนี้ไม่มีสิทธิ์เข้าถึงข้อมูลดังกล่าว';
}

$photoUrl = $row ? thonglangUploadedImageUrl($row['Photo'] ?? '') : '';
$doctorDisplay = '-';
$caregiverDisplay = '-';
if ($row) {
    $doctorDisplay = trim((string)($row['doctor_name'] ?? '')) ?: trim((string)($row['doctor_username'] ?? '')) ?: '-';
    $caregiverDisplay = trim((string)($row['caregiver_name'] ?? '')) ?: trim((string)($row['caregiver_username'] ?? '')) ?: '-';
}

$resultStatus = '-';
if ($row) {
    if ((int)($row['result_returned_to_doctor'] ?? 0) === 1) {
        $resultStatus = 'รับผลการประเมินกลับแล้ว';
    } elseif ($row['adl_id'] !== null) {
        $resultStatus = 'รอรับผลจากแคร์กิฟเวอร์';
    } else {
        $resultStatus = 'ยังไม่มีการประเมิน ADL';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ข้อมูลการมอบหมายโดยละเอียด | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* Assignment detail: follows the Thonglang mint palette; text-first layout, without field cards. */
.detail-page{padding-bottom:42px;color:#183b38}
.detail-toolbar,.detail-hero,.detail-report{max-width:1200px;margin-left:auto;margin-right:auto}
.detail-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:12px;margin-bottom:16px}
.detail-back{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;border:1px solid #d0e5e1;background:#fff;color:#24575d;text-decoration:none;font-weight:750;transition:background .16s,border-color .16s}
.detail-back:hover,.detail-back:focus-visible{background:#eaf8f5;border-color:#20afa6}
.detail-id{color:#647d80;font-size:12px;font-weight:650}
.detail-hero{display:grid;grid-template-columns:120px minmax(0,1fr);align-items:center;gap:24px;padding:23px 27px;margin-bottom:18px;background:#eaf8f5;border:1px solid #d5ebe7;border-radius:19px}
.detail-photo{width:120px;height:120px;border-radius:15px;border:1px solid #cbe4e0;background:#f7fcfb;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#7b9295;font-size:13px;text-align:center;line-height:1.65}
.detail-photo img{width:100%;height:100%;object-fit:cover;display:block}
.detail-hero h1{margin:0 0 12px;color:#183b38;font-size:clamp(23px,2.15vw,30px);font-weight:800;line-height:1.35;overflow-wrap:anywhere}
.detail-profile-info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px 24px}
.detail-profile-line{min-width:0;color:#31575b;font-size:14px;line-height:1.6;overflow-wrap:anywhere}
.detail-profile-line .profile-label{display:block;color:#617d80;font-size:12px;font-weight:600;margin-bottom:1px}
.detail-profile-line strong{display:block;color:#183b38;font-size:15px;font-weight:750}
.detail-profile-line .profile-status{color:#137f72}
.detail-profile-line.profile-wide{grid-column:span 2}
/* One quiet report surface; sections and fields are text + thin separators, not individual boxes. */
.detail-report{background:#fff;border-radius:19px;padding:8px 28px 10px;border:1px solid #e0eeeb}
.detail-section{margin:0;padding:18px 0 24px;background:transparent;border:0;border-radius:0;box-shadow:none}
.detail-section + .detail-section{border-top:1px solid #e5eeed}
.detail-section-head{display:flex;align-items:center;gap:11px;margin:0 0 16px;padding:0;background:transparent;border:0;color:#1d4c50;font-size:19px;font-weight:800;line-height:1.5}
.detail-section-head::before{content:'';flex:none;width:4px;height:21px;border-radius:4px;background:#20afa6}
.detail-section-body{padding:0}
.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:38px;row-gap:0}
.detail-item{display:grid;grid-template-columns:minmax(130px,.88fr) minmax(0,1.12fr);align-items:baseline;gap:10px 14px;min-width:0;min-height:0;margin:0;padding:12px 0;border:0;border-bottom:1px solid #edf3f2;border-radius:0;background:transparent}
.detail-item .label{display:block;min-width:0;color:#607b7d;font-size:14px;font-weight:550;line-height:1.55;overflow-wrap:anywhere}
.detail-item strong{display:block;min-width:0;color:#173e43;font-size:15px;font-weight:750;line-height:1.55;overflow-wrap:anywhere}
.detail-item.full{grid-column:1 / -1;grid-template-columns:minmax(130px,calc((100% - 38px)*.44)) minmax(0,1fr)}
.detail-item.full strong{white-space:pre-wrap}
.detail-item--score strong,.detail-item--status strong{color:#137f72}
.detail-section:last-child{padding-bottom:10px}
@media(min-width:801px){.detail-grid>.detail-item:nth-last-child(-n+2):not(.full){border-bottom-color:transparent}.detail-grid>.detail-item.full:last-child{border-bottom-color:transparent}}
@media(max-width:1140px){.detail-grid{column-gap:24px}.detail-item{grid-template-columns:minmax(108px,.8fr) minmax(0,1.2fr)}.detail-item.full{grid-template-columns:minmax(108px,34%) minmax(0,1fr)}}
@media(max-width:800px){.detail-profile-info{grid-template-columns:repeat(2,minmax(0,1fr))}.detail-grid{grid-template-columns:1fr}.detail-item.full{grid-column:auto;grid-template-columns:minmax(130px,.88fr) minmax(0,1.12fr)}.detail-item:last-child{border-bottom:0}}
@media(max-width:560px){.detail-toolbar{flex-wrap:wrap}.detail-hero{grid-template-columns:1fr;gap:14px;padding:18px}.detail-photo{width:95px;height:95px}.detail-profile-info{column-gap:18px}.detail-profile-line.profile-wide{grid-column:1 / -1}.detail-report{padding:4px 17px 10px}.detail-section{padding:18px 0 21px}.detail-section-head{font-size:17px}.detail-item,.detail-item.full{grid-template-columns:1fr;gap:2px;padding:9px 0}.detail-item .label{font-size:13px}.detail-item strong{font-size:15px}}
@media print{.detail-toolbar{display:none}.detail-hero,.detail-report{max-width:none;box-shadow:none}.detail-hero{-webkit-print-color-adjust:exact;print-color-adjust:exact}.detail-item{break-inside:avoid}.detail-section{break-inside:avoid}}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<main class="main detail-page">
<?php renderUserTopbar(); ?>

<div class="detail-toolbar">
    <a class="detail-back" href="assign_patient.php">ย้อนกลับ</a>
    <?php if ($assignmentId > 0): ?><div class="detail-id">รหัสรายการ #<?= number_format($assignmentId) ?></div><?php endif; ?>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php else: ?>
    <section class="detail-hero">
        <div class="detail-photo">
            <?php if ($photoUrl !== ''): ?>
                <img src="<?= e($photoUrl) ?>" alt="รูปผู้สูงอายุ <?= e($row['Fullname']) ?>">
            <?php else: ?>
                <div>ยังไม่มีรูปภาพ<br>ผู้สูงอายุ</div>
            <?php endif; ?>
        </div>
        <div>
            <h1><?= e($row['Fullname']) ?></h1>
            <div class="detail-profile-info">
                <div class="detail-profile-line"><span class="profile-label">อายุ</span> <strong><?= !empty($row['Age']) ? number_format((int)$row['Age']).' ปี' : 'ไม่ระบุ' ?></strong></div>
                <div class="detail-profile-line"><span class="profile-label">แพทย์ผู้มอบหมาย</span> <strong><?= e($doctorDisplay) ?></strong></div>
                <div class="detail-profile-line"><span class="profile-label">ผู้ดูแล</span> <strong><?= e($caregiverDisplay) ?></strong></div>
                <div class="detail-profile-line"><span class="profile-label">สถานะการดูแล</span> <strong class="profile-status"><?= e($row['care_status'] ?: '-') ?></strong></div>
                <div class="detail-profile-line"><span class="profile-label">วันนัดถัดไป</span> <strong><?= e(detailThaiDate($row['next_visit_date'] ?? null)) ?></strong></div>
                <div class="detail-profile-line"><span class="profile-label">ผล ADL ล่าสุด</span> <strong><?= $row['caregiver_adl_score'] !== null ? e(detailAdlGroup($row['caregiver_adl_score'])) : ($row['doctor_adl_score'] !== null ? e(detailAdlGroup($row['doctor_adl_score'])) : '-') ?></strong></div>
                <div class="detail-profile-line profile-wide"><span class="profile-label">สถานะการประเมิน</span> <strong><?= e($resultStatus) ?></strong></div>
            </div>
        </div>
    </section>

    <div class="detail-report">
    <section class="detail-section">
        <div class="detail-section-head">ข้อมูลบุคคลและการมอบหมาย</div>
        <div class="detail-section-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="label">ชื่อผู้สูงอายุ</span><strong><?= e($row['Fullname']) ?></strong></div>
                <div class="detail-item"><span class="label">อายุ</span><strong><?= !empty($row['Age']) ? number_format((int)$row['Age']).' ปี' : '-' ?></strong></div>
                <div class="detail-item"><span class="label">เพศ</span><strong><?= e($row['Gender'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">โรคประจำตัว</span><strong><?= e($row['Disease'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">หมายเลขโทรศัพท์</span><strong><?= e($row['Phone'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">แพทย์ผู้มอบหมาย</span><strong><?= e($doctorDisplay) ?></strong></div>
                <div class="detail-item"><span class="label">ผู้ดูแลที่ได้รับมอบหมาย</span><strong><?= e($caregiverDisplay) ?></strong></div>
                <div class="detail-item"><span class="label">วันที่มอบหมาย</span><strong><?= e(detailThaiDate($row['assigned_at'], true)) ?></strong></div>
                <div class="detail-item"><span class="label">วันนัดเยี่ยมครั้งถัดไป</span><strong><?= e(detailThaiDate($row['next_visit_date'] ?? null)) ?></strong></div>
                <div class="detail-item"><span class="label">สถานะการดูแล</span><strong><?= e($row['care_status'] ?: '-') ?></strong></div>
            </div>
        </div>
    </section>

    <section class="detail-section">
        <div class="detail-section-head">ผลการประเมิน ADL</div>
        <div class="detail-section-body">
            <div class="detail-grid">
                <div class="detail-item detail-item--score"><span class="label">ผลการประเมินครั้งที่ 1 โดยแพทย์</span><strong><?= $row['doctor_adl_score'] !== null ? number_format((int)$row['doctor_adl_score']).'/20 — '.e(detailAdlGroup($row['doctor_adl_score'])) : 'ยังไม่มีผลการประเมิน' ?></strong></div>
                <div class="detail-item detail-item--score"><span class="label">ผลการประเมินครั้งที่ 2 โดยแคร์กิฟเวอร์</span><strong><?= $row['caregiver_adl_score'] !== null ? number_format((int)$row['caregiver_adl_score']).'/20 — '.e(detailAdlGroup($row['caregiver_adl_score'])) : 'รอการประเมินครั้งที่ 2' ?></strong></div>
                <div class="detail-item"><span class="label">ผลสรุปกลุ่ม</span><strong><?= $row['caregiver_adl_score'] !== null ? e(detailAdlGroup($row['caregiver_adl_score'])) : '-' ?></strong></div>
                <div class="detail-item"><span class="label">สถานะการประเมิน</span><strong><?= e($resultStatus) ?></strong></div>
                <div class="detail-item"><span class="label">ผู้ดูแลประจำ</span><strong><?= e($row['regular_caregiver'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">สิทธิเงินสงเคราะห์</span><strong><?= e($row['welfare_status'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">สมาชิกชมรม</span><strong><?= e($row['club_membership'] ?: '-') ?></strong></div>
                <div class="detail-item full"><span class="label">หมายเหตุการประเมิน</span><strong><?= e($row['adl_note'] ?: '-') ?></strong></div>
            </div>
        </div>
    </section>

    <section class="detail-section">
        <div class="detail-section-head">สถานะการดูแลและการสิ้นสุดการดูแล</div>
        <div class="detail-section-body">
            <div class="detail-grid">
                <div class="detail-item detail-item--status"><span class="label">สถานะการดูแลปัจจุบัน</span><strong><?= e($row['care_status'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">วันที่ปรับปรุงสถานะล่าสุด</span><strong><?= e(detailThaiDate($row['care_status_updated_at'] ?? null, true)) ?></strong></div>
                <div class="detail-item"><span class="label">เหตุผลการสิ้นสุดการดูแล</span><strong><?= e($row['care_end_reason'] ?: '-') ?></strong></div>
                <div class="detail-item"><span class="label">เหตุผลการพักการดูแล</span><strong><?= e($row['care_pause_reason'] ?: '-') ?></strong></div>
                <div class="detail-item full"><span class="label">หมายเหตุเพิ่มเติมเกี่ยวกับสถานะการดูแล</span><strong><?= e($row['care_status_note'] ?: '-') ?></strong></div>
            </div>
        </div>
    </section>
    </div><!-- /detail-report -->
<?php endif; ?>
</main>
</body>
</html>
