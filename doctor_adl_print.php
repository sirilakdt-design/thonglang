<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

$doctorId = (int)($_SESSION['user_id'] ?? 0);
$adlId = (int)($_GET['adl_id'] ?? 0);
$record = null;
$sql = "SELECT a.*,p.Fullname,p.Age,p.Gender,p.Weight_kg,p.Height_cm,p.Disease,p.Phone,p.Address,
               v.villagename,COALESCE(NULLIF(u.display_name,''),u.username,'-') AS doctor_name
        FROM adl_assessment a
        JOIN patient p ON p.Patient_id=a.patient_id
        LEFT JOIN village v ON v.village_id=p.Village_id
        LEFT JOIN users u ON u.user_id=a.doctor_user_id
        WHERE a.adl_id=? AND a.doctor_user_id=? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ii', $adlId, $doctorId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $record = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}

function prDate(?string $value): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    if (!$ts) return (string)$value;
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}
function prAssessmentDateTime(?string $day, ?string $saved): string
{
    $recorded = adlAssessmentRecordedDateTime($day, $saved);
    if ($recorded === '') return '-';
    $date = prDate(substr($recorded, 0, 10));
    return strlen($recorded) > 10 ? $date . ' ' . substr($recorded, 11, 5) . ' น.' : $date;
}
function prVal($value): string { $text = trim((string)($value ?? '')); return $text === '' ? '-' : $text; }
function prGroup(int $score): string { return $score >= 12 ? 'กลุ่มติดสังคม' : ($score >= 5 ? 'กลุ่มติดบ้าน' : 'กลุ่มติดเตียง'); }
function prDependency(int $score): string
{
    if ($score >= 12) return 'ช่วยเหลือตนเองได้เป็นส่วนใหญ่';
    if ($score >= 5) return 'ต้องการความช่วยเหลือบางส่วน';
    return 'ต้องพึ่งพาผู้อื่นในระดับสูง';
}
function prConclusion(int $score): string
{
    if ($score >= 12) return 'ผู้สูงอายุสามารถทำกิจวัตรประจำวันได้ค่อนข้างดี มีศักยภาพในการช่วยเหลือตนเองในหลายด้าน ควรส่งเสริมให้คงความสามารถเดิมและติดตามตามรอบการดูแล';
    if ($score >= 5) return 'ผู้สูงอายุยังสามารถทำกิจวัตรประจำวันได้บางส่วน แต่ยังต้องการความช่วยเหลือในบางกิจกรรม ควรวางแผนดูแลเฉพาะด้านและติดตามต่อเนื่อง';
    return 'ผู้สูงอายุต้องการความช่วยเหลือในการดำเนินกิจวัตรประจำวันในระดับสูง ควรมีผู้ดูแลอย่างใกล้ชิดและมีการติดตามสม่ำเสมอ';
}
function prFollowup(int $score): string
{
    if ($score >= 12) return 'ส่งเสริมการเคลื่อนไหว การทำกิจวัตรประจำวันด้วยตนเอง และประเมิน ADL ซ้ำตามรอบที่หน่วยบริการกำหนด';
    if ($score >= 5) return 'ติดตามกิจกรรมที่ยังต้องช่วยเหลือ ให้คำแนะนำผู้ดูแล และประเมิน ADL ซ้ำตามแผนการดูแล';
    return 'จัดให้มีผู้ดูแลช่วยเหลือใกล้ชิด เฝ้าระวังภาวะแทรกซ้อน และประเมิน ADL ซ้ำตามแผนอย่างต่อเนื่อง';
}

$maxScores = ['feeding'=>2,'grooming'=>1,'transfer'=>3,'toilet_use'=>2,'mobility'=>3,'dressing'=>2,'stairs'=>2,'bathing'=>1,'bowels'=>2,'bladder'=>2];
$thaiItems = ['feeding'=>'การรับประทานอาหาร','grooming'=>'การดูแลความสะอาดส่วนบุคคล','transfer'=>'การลุก/เคลื่อนย้าย','toilet_use'=>'การใช้ห้องน้ำ','mobility'=>'การเคลื่อนไหว','dressing'=>'การแต่งตัว','stairs'=>'การขึ้นลงบันได','bathing'=>'การอาบน้ำ','bowels'=>'การควบคุมอุจจาระ','bladder'=>'การควบคุมปัสสาวะ'];
$score = (int)($record['total_score'] ?? 0);
$group = prGroup($score);
$dependency = prDependency($score);
$needHelp = [];
$fullAbility = [];
if ($record) {
    foreach ($maxScores as $key => $maxScore) {
        $value = (int)($record[$key] ?? 0);
        if ($value < $maxScore) $needHelp[] = $thaiItems[$key];
        else $fullAbility[] = $thaiItems[$key];
    }
}
$needHelpText = $needHelp ? implode(', ', $needHelp) : 'ไม่พบกิจกรรมที่ได้คะแนนต่ำกว่าคะแนนเต็ม';
$fullAbilityText = $fullAbility ? implode(', ', $fullAbility) : '-';
$director = directorName();
$systemName = appName();
$summaryLead = 'จากการประเมิน ADL ครั้งนี้ ผู้สูงอายุมีคะแนนรวม '.$score.'/20 คะแนน จัดอยู่ใน'.$group.' และอยู่ในระดับ'.$dependency;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(appDocumentTitle('สรุปรายงานผลการประเมิน ADL')) ?></title>
<style>
@page{size:A4 portrait;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#eef2f2;color:#111;font-family:"Leelawadee UI","TH Sarabun New","Segoe UI",Tahoma,sans-serif;font-size:14px}
.toolbar{width:210mm;margin:12px auto 8px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.toolbar .left,.toolbar .right{display:flex;gap:8px;align-items:center}
.toolbar a,.toolbar button{border:1px solid #c8d8d6;border-radius:10px;background:#fff;color:#274b4b;padding:9px 14px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}
.toolbar .primary{background:#3daea9;border-color:#3daea9;color:#fff}
.sheet{position:relative;width:210mm;min-height:297mm;height:297mm;margin:0 auto 18px;background:#fff;padding:10mm 10mm 34mm;box-shadow:0 5px 22px rgba(0,0,0,.13);overflow:hidden}
.header{text-align:center;margin-bottom:14px}
.header .org{font-size:16px;font-weight:700;line-height:1.45}
.header .title{font-size:31px;font-weight:800;line-height:1.28;margin-top:2px}
.header .meta{margin-top:6px;font-size:15px;line-height:1.5}
.header .meta-row{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-top:12px;text-align:left;font-size:14px}
.section{margin-top:16px}
.section-title{display:block;font-size:21px;font-weight:800;line-height:1.25;padding-bottom:6px;margin-bottom:10px;border-bottom:1px solid #333}.section-title::after{content:none}
.info-table{width:100%;border-collapse:collapse;font-size:14px}
.info-table td{padding:2px 8px 4px 0;vertical-align:top;line-height:1.72}
.info-table .label{width:122px;font-weight:700;white-space:nowrap}
.info-table .value{width:auto}
.summary-paragraph{font-size:14px;line-height:1.82;text-align:justify}
.bullets{margin:0;padding-left:24px;font-size:14px;line-height:1.82}
.note-area{min-height:84px;border:0;padding:4px 0;line-height:1.82;margin-top:6px}
.sign-section{position:absolute;left:14mm;right:14mm;bottom:14mm;margin-top:0}
.sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:44px;margin-top:0}
.sign-box{text-align:center;padding-top:40px}
.sign-line{border-top:1px solid #333;padding-top:6px;min-height:0}
.sign-name{font-size:14px;font-weight:700;line-height:1.7}
.sign-role{font-size:14px;line-height:1.7}

@media screen{body.print-preview-active::after{content:"";position:fixed;inset:0;z-index:2147483647;background:#fff;pointer-events:none}}
@media print{html,body{width:210mm;height:297mm;background:#fff;margin:0!important;padding:0!important}.toolbar{display:none!important}.sheet{margin:0!important;box-shadow:none!important;width:210mm!important;min-height:297mm!important;height:297mm!important;padding:10mm 10mm 34mm!important;overflow:hidden!important}}
</style>
</head>
<body>
<div class="toolbar">
    <div class="left"><a href="doctor_adl_round_detail.php?adl_id=<?= (int)$adlId ?>">ย้อนกลับ</a></div>
    <div class="right"><button type="button" id="downloadAdlPdf">ดาวน์โหลด PDF</button><button type="button" class="primary" id="printAdlBtn">พิมพ์</button></div>
</div>
<main class="sheet">
<?php if (!$record): ?>
    <div class="header"><div class="title">ไม่พบข้อมูลผลการประเมิน</div></div>
<?php else: ?>
    <div class="header">
        <div class="org">ระบบบันทึกสุขภาพผู้สูงอายุ <?= e($systemName) ?></div>
        <div class="title">สรุปรายงานผลการประเมิน ADL</div>
        <div class="meta">เอกสารสรุปเพื่อประกอบการติดตามดูแลผู้สูงอายุ</div>
        <div class="meta-row">
            <div>วันที่ประเมิน <?= e(prAssessmentDateTime($record['assessment_date'] ?? null, $record['created_at'] ?? null)) ?></div>
            <div>วันที่ออกรายงาน <?= e(prDate(date('Y-m-d'))) ?></div>
        </div>
    </div>

    <section class="section">
        <div class="section-title">ข้อมูลผู้สูงอายุ</div>
        <table class="info-table">
            <tr><td class="label">ชื่อ–นามสกุล</td><td class="value"><?= e(prVal($record['Fullname'])) ?></td><td class="label">อายุ</td><td class="value"><?= e(prVal($record['Age'])) ?> ปี</td></tr>
            <tr><td class="label">เพศ</td><td class="value"><?= e(prVal($record['Gender'])) ?></td><td class="label">เบอร์โทร</td><td class="value"><?= e(prVal($record['Phone'])) ?></td></tr>
            <tr><td class="label">หมู่บ้าน</td><td class="value"><?= e(prVal($record['villagename'])) ?></td><td class="label">น้ำหนัก / ส่วนสูง</td><td class="value"><?= e(prVal($record['Weight_kg'])) ?> กก. / <?= e(prVal($record['Height_cm'])) ?> ซม.</td></tr>
            <tr><td class="label">โรคประจำตัว</td><td class="value" colspan="3"><?= e(prVal($record['Disease'])) ?></td></tr>
            <tr><td class="label">ผู้ประเมิน</td><td class="value" colspan="3"><?= e(prVal($record['doctor_name'])) ?></td></tr>
        </table>
    </section>

    <section class="section">
        <div class="section-title">สรุปผลการประเมิน</div>
        <table class="info-table">
            <tr><td class="label">คะแนนรวม</td><td class="value"><?= $score ?> / 20 คะแนน</td></tr>
            <tr><td class="label">กลุ่ม ADL</td><td class="value"><?= e($group) ?></td></tr>
            <tr><td class="label">ระดับการช่วยเหลือ</td><td class="value"><?= e($dependency) ?></td></tr>
        </table>
        <div class="summary-paragraph" style="margin-top:8px;"><?= e($summaryLead) ?> <?= e(prConclusion($score)) ?></div>
    </section>

    <section class="section">
        <div class="section-title">ประเด็นสำคัญจากการประเมิน</div>
        <div class="summary-paragraph"><strong>กิจกรรมที่ยังต้องได้รับการช่วยเหลือ:</strong> <?= e($needHelpText) ?></div>
        <div class="summary-paragraph"><strong>กิจกรรมที่ยังสามารถทำได้ดี:</strong> <?= e($fullAbilityText) ?></div>
    </section>

    <section class="section">
        <div class="section-title">ข้อเสนอแนะและแนวทางติดตาม</div>
        <div class="note-area">
            <ul class="bullets">
                <li><?= e(prFollowup($score)) ?></li>
                <li>ติดตามอาการ การเคลื่อนไหว และความสามารถในการทำกิจวัตรประจำวันอย่างต่อเนื่อง</li>
                <li>หากมีการเปลี่ยนแปลงด้านสุขภาพหรือการช่วยเหลือตนเอง ควรประเมินซ้ำและปรับแผนการดูแลให้เหมาะสม</li>
                <?php if (trim((string)($record['note'] ?? '')) !== ''): ?><li>หมายเหตุจากผู้ประเมิน: <?= nl2br(e($record['note'])) ?></li><?php endif; ?>
            </ul>
        </div>
    </section>

    <section class="sign-section">
        <div class="sign-grid">
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= e(prVal($record['doctor_name'])) ?><br>หมอ</div>
            </div>
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= e($director) ?><br>ผู้อำนวยการ</div>
            </div>
        </div>
    </section>

<?php endif; ?>
</main>
<script src="report_pdf_download.js?v=20260929sharp"></script>
<script>
const doctorAdlPdfData = <?= json_encode([
    'org' => 'ระบบบันทึกสุขภาพผู้สูงอายุ '.$systemName,
    'title' => 'สรุปรายงานผลการประเมิน ADL',
    'description' => 'เอกสารสรุปเพื่อประกอบการติดตามดูแลผู้สูงอายุ',
    'assessment_datetime' => 'วันที่ประเมิน '.prAssessmentDateTime($record['assessment_date'] ?? null, $record['created_at'] ?? null),
    'report_date' => 'วันที่ออกรายงาน '.prDate(date('Y-m-d')),
    'fullname' => prVal($record['Fullname']),
    'age' => prVal($record['Age']).' ปี',
    'gender' => prVal($record['Gender']),
    'phone' => prVal($record['Phone']),
    'village' => prVal($record['villagename']),
    'weight_height' => prVal($record['Weight_kg']).' กก. / '.prVal($record['Height_cm']).' ซม.',
    'disease' => prVal($record['Disease']),
    'doctor' => prVal($record['doctor_name']),
    'director' => $director,
    'score_text' => $score.' / 20 คะแนน',
    'group' => $group,
    'dependency' => $dependency,
    'overall_summary' => $summaryLead.' '.prConclusion($score),
    'need_help' => $needHelpText,
    'full_ability' => $fullAbilityText,
    'followups' => array_values(array_filter([
        prFollowup($score),
        'ติดตามอาการ การเคลื่อนไหว และความสามารถในการทำกิจวัตรประจำวันอย่างต่อเนื่อง',
        'หากมีการเปลี่ยนแปลงด้านสุขภาพหรือการช่วยเหลือตนเอง ควรประเมินซ้ำและปรับแผนการดูแลให้เหมาะสม',
        trim((string)($record['note'] ?? '')) !== '' ? 'หมายเหตุจากผู้ประเมิน: '.$record['note'] : ''
    ])),
    'filename' => 'สรุป_ADL_'.(int)$adlId.'.pdf'
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
// Keep the original in-tab print preview, but cover the background page while it is open.
(function(){
    const button=document.getElementById('printAdlBtn');
    const hideBackground=()=>document.body.classList.add('print-preview-active');
    const restoreBackground=()=>document.body.classList.remove('print-preview-active');
    window.addEventListener('afterprint',restoreBackground);
    if(button) button.addEventListener('click',function(){
        hideBackground();
        try { window.print(); }
        catch(error) { restoreBackground(); throw error; }
    });
})();
const adlPdfBtn=document.getElementById('downloadAdlPdf');
if(adlPdfBtn)adlPdfBtn.addEventListener('click',async function(){
    this.disabled=true;
    try{
        await downloadReportPdf(doctorAdlPdfData);
    }catch(err){
        alert('ไม่สามารถดาวน์โหลด PDF ได้: '+err.message);
    }finally{
        this.disabled=false;
    }
});
</script>
</body>
</html>
