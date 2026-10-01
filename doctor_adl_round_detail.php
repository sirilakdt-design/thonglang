<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');
$doctorId=(int)($_SESSION['user_id']??0);
$adlId=(int)($_GET['adl_id']??0);
$adlItems=[
'feeding'=>['title'=>'1. การรับประทานอาหาร','subtitle'=>'รับประทานอาหารเมื่อเตรียมสำรับไว้ให้เรียบร้อยต่อหน้า'],
'grooming'=>['title'=>'2. การดูแลตนเอง','subtitle'=>'ล้างหน้า หวีผม แปรงฟัน โกนหนวด'],
'transfer'=>['title'=>'3. การลุกนั่งและเคลื่อนย้าย','subtitle'=>'ลุกนั่งจากที่นอน หรือจากเตียงไปยังเก้าอี้'],
'toilet_use'=>['title'=>'4. การใช้ห้องน้ำ','subtitle'=>'ใช้ห้องน้ำ'],
'mobility'=>['title'=>'5. การเคลื่อนที่','subtitle'=>'การเคลื่อนที่ภายในห้องหรือบ้าน'],
'dressing'=>['title'=>'6. การสวมใส่เสื้อผ้า','subtitle'=>'การสวมใส่เสื้อผ้า'],
'stairs'=>['title'=>'7. การขึ้นลงบันได','subtitle'=>'การขึ้นลงบันได 1 ชั้น'],
'bathing'=>['title'=>'8. การอาบน้ำ','subtitle'=>'การอาบน้ำ'],
'bowels'=>['title'=>'9. การกลั้นอุจจาระ','subtitle'=>'การกลั้นการถ่ายอุจจาระในระยะ 1 สัปดาห์ที่ผ่านมา'],
'bladder'=>['title'=>'10. การกลั้นปัสสาวะ','subtitle'=>'การกลั้นปัสสาวะในระยะ 1 สัปดาห์ที่ผ่านมา'],
];
function doctorAdlGroup(int $score): string { if($score>=12)return 'กลุ่มติดสังคม'; if($score>=5)return 'กลุ่มติดบ้าน'; return 'กลุ่มติดเตียง'; }
function doctorAdlThaiDateTime(?string $value): string { if(!$value)return '-'; $dateOnly=(bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',trim($value)); $ts=strtotime($value); if(!$ts)return (string)$value; $m=[1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.']; return (int)date('j',$ts).' '.$m[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543).($dateOnly?'':' '.date('H:i',$ts).' น.'); }
function doctorSummaryValue($value,string $suffix=''): string { $t=trim((string)($value??'')); return $t===''?'-':$t.$suffix; }
$record=null;
if($adlId>0){
$sql="SELECT a.*,p.Fullname,p.Age,p.Gender,p.Weight_kg,p.Height_cm,p.Disease,v.villagename,COALESCE(NULLIF(u.display_name,''),u.username,'-') doctor_name FROM adl_assessment a JOIN patient p ON p.Patient_id=a.patient_id LEFT JOIN village v ON v.village_id=p.Village_id LEFT JOIN users u ON u.user_id=a.doctor_user_id WHERE a.adl_id=? AND a.doctor_user_id=? LIMIT 1";
$stmt=mysqli_prepare($conn,$sql); if($stmt){mysqli_stmt_bind_param($stmt,'ii',$adlId,$doctorId);mysqli_stmt_execute($stmt);$res=mysqli_stmt_get_result($stmt);$record=$res?mysqli_fetch_assoc($res):null;mysqli_stmt_close($stmt);} }
$score=(int)($record['total_score']??0); $group=doctorAdlGroup($score);
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>สรุปผลการประเมินผู้สูงอายุ | <?= e(appName()) ?></title><?php renderPastelTheme(); ?><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet"><style>
.summary-view-shell{display:grid;gap:18px;padding-bottom:24px}.top-actions{display:flex;justify-content:flex-start}.summary-panel{background:transparent;border:0;border-radius:0;box-shadow:none;overflow:visible}.panel-section{padding:0;border-top:0;margin-top:18px}.panel-section:first-child{margin-top:0}.report-heading{text-align:center;padding-bottom:10px}.report-title{margin:0;color:#173f3d;font-size:24px;font-weight:900;letter-spacing:.2px}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.summary-card{border:1px solid #d7e1de;border-radius:10px;background:#fff;padding:16px 18px;box-shadow:none}.summary-card span{display:block;color:#6e817d;font-size:12px;font-weight:700;margin-bottom:6px}.summary-card strong{display:block;color:#173f3d;font-size:18px;line-height:1.35;font-weight:800}.summary-inline-text{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline}.summary-inline-text .inline-label{display:inline;color:#6e817d;font-size:12px;font-weight:700;margin:0}.summary-inline-text .inline-value{display:inline;color:#173f3d;font-size:18px;font-weight:800}.assessment-block{background:transparent}.assessment-section-card{background:#fff;border:1px solid #d3dfdb;border-radius:12px;padding:18px 20px;box-shadow:none}.assessment-head{display:block;margin-bottom:10px}.assessment-head h3{margin:0;color:#183f3d;font-size:18px;font-weight:900}.badge{display:inline-flex;align-items:center;padding:5px 9px;border:1px solid #d3dfdb;border-radius:6px;background:#fff;color:#355752;font-size:11px;font-weight:800}.section-panel{background:transparent;border:0;border-radius:0;box-shadow:none;padding:0}.adl-item-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.adl-item-card{border:1px solid #dbe5e2;border-radius:10px;background:#fff;padding:14px 16px}.adl-item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.adl-item-title-wrap{min-width:0}.adl-item-title{color:#173f3d;font-size:14px;font-weight:800;line-height:1.4}.adl-item-subtitle{margin-top:4px;color:#748985;font-size:11px;line-height:1.45;font-weight:600}.adl-item-score{flex:0 0 auto;min-width:34px;text-align:center;padding:4px 8px;border-radius:6px;background:#f8faf9;color:#173f3d;font-size:13px;font-weight:800;border:1px solid #d8e2df}.body-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.body-card{border:1px solid #dbe5e2;border-radius:10px;background:#fff;padding:14px 16px}.body-card span{display:block;color:#748985;font-size:11px;font-weight:700;margin-bottom:5px}.body-card strong{display:block;color:#173f3d;font-size:15px;font-weight:800;line-height:1.35}.footer-actions{display:flex;justify-content:flex-end;padding:4px 0 0}.print-btn,.secondary-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:10px 16px;border-radius:10px;font:inherit;font-weight:800;cursor:pointer}.print-btn{border:0;background:#4f9fa5;color:#fff}.secondary-btn{border:1px solid #cfd9d6;background:#fff;color:#315d50}.empty-card{background:#fff;border:1px solid #d9e8e4;border-radius:16px;padding:34px 28px;color:#6d8480;text-align:center}.section-chip{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0;background:transparent;border:0;color:#173f3d;font-size:18px;font-weight:800}.section-chip .section-text{display:block}.section-chip small{font-size:11px;font-weight:700;color:#58706b;border:1px solid #cfdad7;padding:4px 8px;border-radius:6px;background:#fff;white-space:nowrap}.section-divider{height:1px;background:#e4ece9;margin:6px 0 2px}.adl-summary-bottom{margin-top:14px;gap:12px}.body-grid{margin-top:14px}.body-card strong{word-break:break-word}
@media(max-width:1050px){.summary-grid,.body-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.summary-grid,.body-grid,.adl-item-grid{grid-template-columns:1fr}.assessment-section-card{padding:16px}.footer-actions{padding-top:4px}.assessment-head{margin-bottom:12px}.section-chip{flex-direction:column;align-items:flex-start}.report-title{font-size:21px}}@page{size:A4 portrait;margin:3mm}@media print{html,body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.sidebar,.user-topbar,.top-actions,.footer-actions{display:none!important}.main{margin:0!important;padding:0!important;width:100%!important;max-width:none!important}.summary-view-shell{gap:8px!important;padding:0!important}.summary-panel{box-shadow:none!important;border:0!important;border-radius:0!important;background:#fff!important;overflow:visible!important}.panel-section{margin-top:8px!important;padding:0!important}.report-heading{padding-bottom:12px!important}.report-title{font-size:19px!important;font-weight:800!important;letter-spacing:0!important}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}.summary-card{padding:4px 4px 6px!important;border-radius:0!important;box-shadow:none!important;border:0!important;background:transparent!important;break-inside:avoid}.summary-card span{font-size:9.6px!important;margin-bottom:2px!important;color:#5d706b!important}.summary-card strong{font-size:14px!important;line-height:1.26!important;font-weight:700!important}.summary-inline-text{gap:8px!important}.summary-inline-text .inline-label{font-size:9.6px!important;color:#5d706b!important;font-weight:700!important}.summary-inline-text .inline-value{font-size:14px!important;color:#173f3d!important;font-weight:700!important}.assessment-section-card{padding:11px 12px!important;border-radius:0!important;box-shadow:none!important;border:1px solid #b8c9c4!important;break-inside:avoid}.assessment-head{margin-bottom:6px!important}.section-chip{display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:space-between!important;font-size:12px!important;font-weight:800!important;color:#173f3d!important}.section-chip small{font-size:8px!important;color:#5f726d!important;border:0!important;padding:0!important;border-radius:0!important;background:transparent!important}.section-divider{margin:5px 0 0!important;background:#dce5e2!important}.adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:6px!important;margin-top:7px!important}.adl-item-card{padding:7px 8px!important;border-radius:0!important;break-inside:avoid;border:1px solid #c9d7d3!important;min-height:34px!important}.adl-item-title{font-size:10.2px!important;line-height:1.2!important;font-weight:700!important}.adl-item-subtitle{font-size:7.6px!important;line-height:1.18!important;margin-top:1px!important;color:#627771!important}.adl-item-score{min-width:22px!important;padding:2px 4px!important;border-radius:0!important;font-size:10px!important;background:#fff!important;border:1px solid #c9d7d3!important;color:#173f3d!important}.adl-summary-bottom{margin-top:6px!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}.body-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:6px!important;margin-top:7px!important}.body-card{padding:7px 8px!important;border-radius:0!important;break-inside:avoid;border:1px solid #c9d7d3!important;min-height:34px!important}.body-card span{font-size:7.6px!important;margin-bottom:2px!important;color:#627771!important}.body-card strong{font-size:9.6px!important;line-height:1.2!important;font-weight:700!important}.print-btn,.secondary-btn{display:none!important}}

/* Thonglang 16 — unboxed ADL detail, in the existing green/teal system style. */
.summary-view-shell{gap:14px;max-width:1480px}
.summary-panel{background:transparent!important;border:0!important;box-shadow:none!important}
.report-heading{text-align:left;padding:6px 0 2px}
.report-title{font-size:23px;line-height:1.4}
.panel-section{margin-top:21px}
.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:38px;row-gap:17px}
.summary-card,.body-card{
    background:transparent!important;border:0!important;border-radius:0!important;
    box-shadow:none!important;padding:2px 0!important;min-width:0
}
.summary-inline-text{display:flex;flex-direction:column;align-items:flex-start;gap:2px}
.summary-inline-text .inline-label,.summary-card>span,.body-card>span{
    display:block;color:#728883;font-size:12px;font-weight:650;line-height:1.5;margin:0 0 2px
}
.summary-inline-text .inline-value,.summary-card>strong,.body-card>strong{
    color:#224c4b;font-size:16px;line-height:1.5;font-weight:800;overflow-wrap:anywhere
}
.assessment-block{background:transparent!important}
.assessment-section-card{
    border:0!important;border-radius:0!important;box-shadow:none!important;
    background:transparent!important;padding:3px 0 8px!important
}
.assessment-head{margin:0 0 11px}
.section-chip{font-size:19px;line-height:1.45;gap:16px}
.section-chip small{border:0!important;background:transparent!important;border-radius:0!important;padding:0!important;color:#718b84;font-size:12px}
.section-divider{height:1px;background:#dbe9e5;margin:10px 0 3px}
.adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr));column-gap:34px;row-gap:0;margin-top:4px}
.adl-item-card{
    border:0!important;border-bottom:1px solid #e4eeeb!important;
    background:transparent!important;border-radius:0!important;box-shadow:none!important;
    min-width:0;padding:13px 0!important
}
.adl-item-top{align-items:center;gap:17px}
.adl-item-title{font-size:15px;line-height:1.4;font-weight:800;color:#234b4a}
.adl-item-subtitle{font-size:12px;line-height:1.55;margin-top:3px;color:#748a85;font-weight:500}
.adl-item-score{
    border:0!important;border-radius:0!important;background:transparent!important;
    color:#236c68!important;font-size:18px!important;line-height:1.3;font-weight:850;
    min-width:24px;padding:0!important;text-align:right
}
.adl-summary-bottom{margin-top:17px;padding:15px 0 0;border-top:1px solid #dbe9e5;row-gap:10px}
.adl-summary-bottom .summary-card>strong{font-size:20px}
.body-grid{grid-template-columns:repeat(3,minmax(0,1fr));column-gap:30px;row-gap:20px;margin-top:18px}
.body-card{align-self:start}
.body-card>strong{font-size:16px}
@media(max-width:1050px){
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .body-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
    .summary-grid,.body-grid,.adl-item-grid{grid-template-columns:1fr;gap:12px}
    .adl-item-grid{gap:0}
    .adl-item-card{padding:12px 0!important}
    .assessment-section-card{padding:2px 0 8px!important}
    .section-chip{align-items:flex-start;flex-direction:column;gap:3px}
    .report-title{font-size:21px}
    .adl-summary-bottom{grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
}
/* Prevent the older print stylesheet from bringing the boxes back. */
@media print{
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:7px 15px!important}
    .summary-card,.body-card{border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;padding:2px 0!important;min-height:0!important}
    .summary-inline-text{display:flex!important;flex-direction:column!important;gap:1px!important}
    .assessment-section-card{border:0!important;border-radius:0!important;background:transparent!important;padding:7px 0!important;box-shadow:none!important}
    .adl-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:15px!important;row-gap:0!important}
    .adl-item-card{border:0!important;border-bottom:1px solid #e4eeeb!important;background:transparent!important;padding:5px 0!important;min-height:0!important}
    .adl-item-score{border:0!important;background:transparent!important;padding:0!important;min-width:14px!important;color:#234b4a!important}
    .body-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:7px 12px!important}
    .section-chip small{border:0!important;background:transparent!important;padding:0!important}
    .adl-summary-bottom{border-top:1px solid #dbe9e5!important;padding-top:8px!important}
}


/* Thonglang 17 — bring back a subtle outer frame behind the text layout. */
.summary-view-shell{gap:18px!important}
.summary-panel{background:#fff!important;border:1px solid #d8e6e1!important;border-radius:18px!important;box-shadow:0 8px 24px rgba(40,93,86,.05)!important;padding:18px 22px 22px!important}
.report-heading{padding:2px 0 0!important}
.report-title{font-size:24px!important;font-weight:900!important;color:#173f3d!important}
.panel-section{margin-top:18px!important}
.summary-panel>.panel-section:not(.assessment-block) .summary-grid{
    background:#f9fcfb!important;
    border:1px solid #e1ece8!important;
    border-radius:16px!important;
    padding:18px 22px!important;
}
.summary-card,.body-card{padding:4px 0!important}
.assessment-section-card{
    background:#fbfdfc!important;
    border:1px solid #dfeae6!important;
    border-radius:16px!important;
    box-shadow:none!important;
    padding:18px 22px 16px!important;
}
.assessment-head{margin:0 0 12px!important}
.section-chip{font-size:20px!important;line-height:1.4!important}
.section-chip small{color:#6f8681!important;font-size:12px!important}
.section-divider{background:#dfe9e6!important;margin:10px 0 4px!important}
.adl-item-grid{column-gap:40px!important;margin-top:6px!important}
.adl-item-card{padding:13px 0!important;border-bottom:1px solid #e1ece8!important}
.adl-summary-bottom{margin-top:16px!important;padding:16px 0 0!important;border-top:1px solid #dfe9e6!important}
.body-grid{margin-top:16px!important;column-gap:32px!important;row-gap:18px!important}
.footer-actions{padding-top:6px!important}
@media(max-width:700px){
    .summary-panel{padding:16px 16px 18px!important;border-radius:16px!important}
    .summary-panel>.panel-section:not(.assessment-block) .summary-grid,
    .assessment-section-card{padding:14px 14px 12px!important;border-radius:14px!important}
    .report-title{font-size:22px!important}
}
@media print{
    .summary-panel{border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;background:#fff!important}
    .summary-panel>.panel-section:not(.assessment-block) .summary-grid,
    .assessment-section-card{background:transparent!important;border:1px solid #b8c9c4!important;border-radius:0!important;padding:10px 12px!important;box-shadow:none!important}
}

</style></head><body class="role-page"><?php renderSidebar(); ?><main class="main"><?php renderUserTopbar(); ?><div class="summary-view-shell"><div class="top-actions"><a class="secondary-btn" href="adl_history.php?patient_id=<?=urlencode((string)($record['patient_id']??''))?>">ย้อนกลับ</a></div><?php if(!$record): ?><div class="empty-card">ไม่พบข้อมูลผลการประเมิน</div><?php else: ?><section class="summary-panel"><div class="panel-section report-heading"><h1 class="report-title">สรุปผลการประเมินผู้สูงอายุ</h1></div><div class="panel-section"><div class="summary-grid"><div class="summary-card"><div class="summary-inline-text"><span class="inline-label">ชื่อ สกุล</span><span class="inline-value"><?=e($record['Fullname']??'-')?></span></div></div><div class="summary-card"><div class="summary-inline-text"><span class="inline-label">รอบการประเมิน</span><span class="inline-value">ครั้งที่ 1 โดยหมอ</span></div></div><div class="summary-card"><div class="summary-inline-text"><span class="inline-label">ผู้ประเมิน</span><span class="inline-value"><?=e($record['doctor_name']??'-')?></span></div></div><div class="summary-card"><div class="summary-inline-text"><span class="inline-label">วันที่ประเมิน</span><span class="inline-value"><?=e(doctorAdlThaiDateTime(adlAssessmentRecordedDateTime($record['assessment_date']??null, $record['created_at']??null)))?></span></div></div></div></div><div class="panel-section assessment-block"><div class="assessment-section-card"><div class="assessment-head"><div class="section-chip"><span class="section-text">ส่วนที่ 1  สรุปผลการประเมิน ADL</span><small>แบบประเมินที่ 1</small></div></div><div class="section-divider"></div><div class="adl-item-grid"><?php foreach($adlItems as $key=>$item): ?><div class="adl-item-card"><div class="adl-item-top"><div class="adl-item-title-wrap"><div class="adl-item-title"><?=e($item['title'])?></div><div class="adl-item-subtitle"><?=e($item['subtitle'])?></div></div><div class="adl-item-score"><?=isset($record[$key])&&$record[$key]!==null?(int)$record[$key]:'-'?></div></div></div><?php endforeach; ?></div><div class="summary-grid adl-summary-bottom"><div class="summary-card"><span>คะแนนรวม</span><strong><?=$score?>/20</strong></div><div class="summary-card"><span>อยู่ในกลุ่ม</span><strong><?=e($group)?></strong></div></div></div></div><div class="panel-section assessment-block"><div class="assessment-section-card"><div class="assessment-head"><div class="section-chip"><span class="section-text">ส่วนที่ 2  ข้อมูลประกอบการประเมิน</span><small>ข้อมูลผู้สูงอายุ</small></div></div><div class="section-divider"></div><div class="body-grid"><div class="body-card"><span>อายุ</span><strong><?=e(doctorSummaryValue($record['Age']??null,' ปี'))?></strong></div><div class="body-card"><span>เพศ</span><strong><?=e(doctorSummaryValue($record['Gender']??null))?></strong></div><div class="body-card"><span>หมู่บ้าน</span><strong><?=e(doctorSummaryValue($record['villagename']??null))?></strong></div><div class="body-card"><span>น้ำหนัก</span><strong><?=e(doctorSummaryValue($record['Weight_kg']??null,' กก.'))?></strong></div><div class="body-card"><span>ส่วนสูง</span><strong><?=e(doctorSummaryValue($record['Height_cm']??null,' ซม.'))?></strong></div><div class="body-card"><span>โรคประจำตัว</span><strong><?=e(doctorSummaryValue($record['Disease']??null))?></strong></div><div class="body-card"><span>ผู้ดูแลประจำ</span><strong><?=e(doctorSummaryValue($record['regular_caregiver']??null))?></strong></div><div class="body-card"><span>สิทธิ/สวัสดิการ</span><strong><?=e(doctorSummaryValue($record['welfare_status']??null))?></strong></div><div class="body-card"><span>สมาชิกชมรม</span><strong><?=e(doctorSummaryValue($record['club_membership']??null))?></strong></div></div></div></div><div class="footer-actions"><a class="print-btn" href="doctor_adl_print.php?adl_id=<?=(int)($record['adl_id']??0)?>" target="_blank" rel="noopener">ดูสรุปรายงาน PDF</a></div></section><?php endif; ?></div></main></body></html>
