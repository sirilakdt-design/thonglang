<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

function vrH($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function vrValue($value): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : 'ไม่ได้บันทึก';
}
function vrThaiDate($value): string {
    if (!$value || !($ts = strtotime((string)$value))) return '-';
    $month = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return (int)date('j', $ts).' '.$month[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
}
function vrPresent($row, string $key): bool { return trim((string)($row[$key] ?? '')) !== ''; }
function vrRef($row): bool {
    $type = trim((string)($row['referral_type'] ?? ''));
    return !empty($row['doctor_referral']) || ($type !== '' && $type !== 'ไม่ส่งต่อ');
}
function vrFollowup($date, string $today): string {
    if (!$date || $date === '0000-00-00') return 'ไม่ได้กำหนดวันติดตาม';
    $formatted = vrThaiDate($date);
    if ($date < $today) return $formatted.' · เลยวันที่กำหนด (ตรวจสอบบันทึกการติดตามในระบบ)';
    if ($date === $today) return $formatted.' · ถึงกำหนดวันนี้';
    return $formatted.' · นัดหมายครั้งถัดไป';
}
function vrMakeSection(string $title, array $items, string $kind = 'rows'): array {
    return ['title'=>$title,'kind'=>$kind,'items'=>$items];
}

$doctorId = (int)($_SESSION['user_id'] ?? 0);
$visitId = max(0, (int)($_GET['visit_id'] ?? 0));
$embed = (string)($_GET['embed'] ?? '') === '1';
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$start = $month.'-01';
$end = date('Y-m-d', strtotime($start.' +1 month'));
$monthNames = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$ts = strtotime($start);
$monthLabel = $monthNames[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
$where = ['a.doctor_user_id=?'];
$types = 'i'; $params = [$doctorId];
if ($visitId) { $where[]='vr.visit_id=?'; $types.='i'; $params[]=$visitId; }
else { $where[]='vr.visit_date>=?'; $where[]='vr.visit_date<?'; $types.='ss'; $params[]=$start; $params[]=$end; }
$sql = 'SELECT vr.*,p.Fullname,p.Age,p.Gender,p.Phone,p.Disease,p.Weight_kg,p.Height_cm,v.villagename AS villagename,cg.display_name caregiver_name,cg.username caregiver_username
        FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
        JOIN patient p ON p.Patient_id=vr.patient_id
        LEFT JOIN village v ON v.village_id=p.Village_id
        LEFT JOIN users cg ON cg.user_id=vr.caregiver_user_id
        WHERE '.implode(' AND ', $where).' ORDER BY vr.visit_date ASC,COALESCE(vr.visit_time,\'00:00:00\') ASC,vr.visit_id ASC';
$visits = []; $st = mysqli_prepare($conn, $sql);
if ($st) {
    mysqli_stmt_bind_param($st,$types,...$params);
    mysqli_stmt_execute($st); $result=mysqli_stmt_get_result($st);
    while ($record = mysqli_fetch_assoc($result)) $visits[]=$record;
    mysqli_stmt_close($st);
}
$doctor = []; $st=mysqli_prepare($conn,'SELECT display_name,username,doctor_code,professional_license_number,medical_position,department FROM users WHERE user_id=? LIMIT 1');
if($st) { mysqli_stmt_bind_param($st,'i',$doctorId);mysqli_stmt_execute($st);$result=mysqli_stmt_get_result($st);$doctor=mysqli_fetch_assoc($result)?:[];mysqli_stmt_close($st); }
$doctorName=trim((string)($doctor['display_name']??'')) ?: trim((string)($doctor['username']??''));
$directorName = directorName();
$today = date('Y-m-d');
$single = $visitId > 0;
$subtitle = $single ? '' : 'ประจำเดือน '.$monthLabel;
$patientIds=[]; $caregivers=[]; $typeCounts=[]; $referrals=0; $followupDates=[];
foreach ($visits as $v) {
    $patientIds[(string)$v['patient_id']] = true;
    $cg = trim((string)($v['caregiver_name']??'')) ?: trim((string)($v['caregiver_username']??''));
    if ($cg !== '') $caregivers[$cg] = true;
    $type = trim((string)($v['visit_type']??'')) ?: 'ไม่ระบุประเภท';
    $typeCounts[$type]=($typeCounts[$type]??0)+1;
    if(vrRef($v)) $referrals++;
    if (!empty($v['next_visit_date']) && $v['next_visit_date'] !== '0000-00-00') $followupDates[$v['next_visit_date']]=true;
}
$visitCount=count($visits);
$sections=[];
$sections[] = vrMakeSection('01  ข้อมูลการรายงาน', [
    ['ผู้จัดทำรายงาน', $doctorName ?: 'ไม่ระบุผู้จัดทำ'],
    ['ตำแหน่ง', vrValue($doctor['medical_position'] ?? '')],
    ['หน่วยงาน', vrValue($doctor['department'] ?? '')],
    ['วันที่ออกรายงาน', vrThaiDate($today)]
]);
$sections[] = vrMakeSection('02  ภาพรวมการเข้าเยี่ยม', [
    ['ครั้งที่เข้าเยี่ยม', (string)$visitCount],
    ['ผู้สูงอายุ (ราย)', (string)count($patientIds)],
    ['รายการส่งต่อ', (string)$referrals],
], 'metrics');
$sections[] = vrMakeSection('รายละเอียดภาพรวม', [
    ['ประเภทการเข้าเยี่ยม', $typeCounts ? implode(' · ',array_map(static fn($key,$n)=>$key.' '.$n.' ครั้ง',array_keys($typeCounts),array_values($typeCounts))) : 'ไม่มีรายการเข้าเยี่ยม'],
    ['ผู้ดูแลที่มีบันทึก', count($caregivers).' คน']
]);

if ($single && $visits) {
    $v=$visits[0];
    $caregiver=trim((string)($v['caregiver_name']??''))?:trim((string)($v['caregiver_username']??''));
    $dateText=vrThaiDate($v['visit_date']);
    if(!empty($v['visit_time'])) $dateText.=' เวลา '.substr($v['visit_time'],0,5).' น.';
    $sections[] = vrMakeSection('03  ผลการเข้าเยี่ยม', [
        ['ผู้รับการเยี่ยม', vrValue($v['Fullname'])],
        ['วันที่เข้าเยี่ยม', $dateText],
        ['ผู้เข้าเยี่ยม', $caregiver ?: 'ไม่ได้บันทึก'],
        ['ประเภทการเยี่ยม', vrValue($v['visit_type'] ?? '')],
        ['สภาพทั่วไป', vrValue($v['general_condition'] ?? '')],
        ['อาการที่บันทึก', vrValue($v['symptom_notes'] ?? '')],
        ['ปัญหาที่พบ', vrValue($v['problem_notes'] ?? '')],
        ['การดูแลที่ดำเนินการ', vrValue($v['care_actions'] ?? '')],
        ['คำแนะนำที่ให้', vrValue($v['advice'] ?? '')]
    ]);
    $followRows = [
        ['กำหนดติดตาม', vrFollowup($v['next_visit_date'] ?? null,$today)],
        ['การส่งต่อ', vrRef($v) ? (vrPresent($v,'referral_type') ? trim((string)$v['referral_type']) : 'มีรายการส่งต่อ (ไม่ได้ระบุประเภท)') : 'ไม่มีรายการแจ้งหรือส่งต่อในบันทึกนี้']
    ];
    if (vrRef($v) && vrPresent($v,'referral_reason')) $followRows[]=['เหตุผลที่ส่งต่อ',trim($v['referral_reason'])];
    $sections[] = vrMakeSection('04  การติดตามและข้อเสนอแนะ', $followRows);
    $summary=[];
    $summary[]='เข้าเยี่ยมผู้สูงอายุ 1 ราย วันที่ '.$dateText;
    if(vrPresent($v,'general_condition')) $summary[]='สภาพทั่วไปที่บันทึก: '.trim($v['general_condition']);
    if(vrPresent($v,'care_actions')) $summary[]='การดูแลที่ดำเนินการ: '.trim($v['care_actions']);
    if(vrPresent($v,'symptom_notes')) $summary[]='อาการที่พบ: '.trim($v['symptom_notes']);
    if(vrRef($v)) $summary[]='มีรายการส่งต่อ'.(vrPresent($v,'referral_reason')?' เนื่องจาก '.trim($v['referral_reason']):'');
    else $summary[]='ไม่มีรายการส่งต่อในบันทึกนี้';
    if (!empty($v['next_visit_date'])) $summary[]='กำหนดติดตาม: '.vrFollowup($v['next_visit_date'],$today);
    if (!vrPresent($v,'general_condition') && !vrPresent($v,'symptom_notes') && !vrPresent($v,'care_actions')) $summary[]='ข้อมูลผลการดูแลยังไม่ครบ ควรตรวจสอบบันทึกการเข้าเยี่ยม';
    $sections[] = vrMakeSection('05  สรุปผลภาพรวม', [['',implode(' · ',$summary)]], 'summary');
} elseif (!$single) {
    $detailRows=[];
    foreach ($visits as $i=>$v) {
        $piece=[];
        foreach (['general_condition'=>'สภาพทั่วไป','symptom_notes'=>'อาการ','care_actions'=>'การดูแล','problem_notes'=>'ปัญหา','advice'=>'คำแนะนำ'] as $field=>$label) {
            if (vrPresent($v,$field)) $piece[]=$label.': '.trim((string)$v[$field]);
        }
        $piece[]='การส่งต่อ: '.(vrRef($v)?'มีรายการส่งต่อ':'ไม่มีรายการส่งต่อในบันทึก');
        $piece[]='ติดตาม: '.vrFollowup($v['next_visit_date']??null,$today);
        $detailRows[]=[($i+1).'. '.vrThaiDate($v['visit_date']).' · '.vrValue($v['Fullname']),implode(' · ',$piece)];
    }
    $sections[] = vrMakeSection('03  ผลการเข้าเยี่ยมรายรายการ', $detailRows ?: [['','ไม่มีบันทึกการเข้าเยี่ยมในเดือนที่เลือก']]);
    $future = array_filter(array_keys($followupDates),static fn($d)=>$d>=$today);
    $past = array_filter(array_keys($followupDates),static fn($d)=>$d<$today);
    $sections[] = vrMakeSection('04  การติดตามและข้อเสนอแนะ', [
        ['วันติดตามที่ระบุ',count($followupDates).' วันที่ (นับวันไม่ซ้ำ)'],
        ['วันนี้หรือวันข้างหน้า',count($future).' วันที่'.($future?' · ใกล้ที่สุด '.vrThaiDate(min($future)): '')],
        ['วันที่ผ่านแล้ว',count($past).' วันที่ · โปรดตรวจสอบผลการติดตามจากบันทึกจริง']
    ]);
    $narrative = $visitCount ? 'มีบันทึกการเข้าเยี่ยม '.$visitCount.' ครั้ง ครอบคลุมผู้สูงอายุ '.count($patientIds).' ราย มีรายการแจ้งหรือส่งต่อ '.$referrals.' ครั้ง' : 'ไม่มีบันทึกการเข้าเยี่ยมในช่วงเวลาที่เลือก';
    $sections[] = vrMakeSection('05  สรุปผลภาพรวม', [['',$narrative]], 'summary');
} else {
    $sections[] = vrMakeSection('03  ผลการเข้าเยี่ยม', [['','ไม่พบรายการเข้าเยี่ยมนี้ หรือไม่มีสิทธิ์เข้าถึงรายการ']]);
}
$singleVisit = ($single && $visits) ? $visits[0] : null;
$backHref = 'doctor_visit_summary.php?month='.rawurlencode($month);
$reportDateText = vrThaiDate($today);
$visitDateText = '-';
$caregiverName = '';
$patientInfoRows = [];
$visitInfoRows = [];
$followupRows = [];
$highlightRows = [];
$summaryLead = '';
$followBullets = [];

if ($singleVisit) {
    $caregiverName = trim((string)($singleVisit['caregiver_name'] ?? '')) ?: trim((string)($singleVisit['caregiver_username'] ?? ''));
    $visitDateText = vrThaiDate($singleVisit['visit_date'] ?? null);
    if (!empty($singleVisit['visit_time'])) $visitDateText .= ' '.substr((string)$singleVisit['visit_time'], 0, 5).' น.';
    $patientInfoRows = [
        ['ชื่อ–นามสกุล', vrValue($singleVisit['Fullname'] ?? ''), 'อายุ', trim((string)($singleVisit['Age'] ?? '')) !== '' ? trim((string)$singleVisit['Age']).' ปี' : '-'],
        ['เพศ', vrValue($singleVisit['Gender'] ?? ''), 'เบอร์โทร', vrValue($singleVisit['Phone'] ?? '')],
        ['หมู่บ้าน', vrValue($singleVisit['villagename'] ?? ''), 'น้ำหนัก / ส่วนสูง', vrValue($singleVisit['Weight_kg'] ?? '').' กก. / '.vrValue($singleVisit['Height_cm'] ?? '').' ซม.'],
        ['โรคประจำตัว', vrValue($singleVisit['Disease'] ?? ''), 'ผู้เข้าเยี่ยม', $caregiverName ?: 'ไม่ได้บันทึก'],
        ['ผู้จัดทำรายงาน', $doctorName ?: 'ไม่ระบุผู้จัดทำ', 'วันที่เข้าเยี่ยม', $visitDateText],
    ];
    $visitInfoRows = [
        ['ประเภทการเยี่ยม', vrValue($singleVisit['visit_type'] ?? '')],
        ['สภาพทั่วไป', vrValue($singleVisit['general_condition'] ?? '')],
        ['อาการที่บันทึก', vrValue($singleVisit['symptom_notes'] ?? '')],
        ['ปัญหาที่พบ', vrValue($singleVisit['problem_notes'] ?? '')],
        ['การดูแลที่ดำเนินการ', vrValue($singleVisit['care_actions'] ?? '')],
        ['คำแนะนำที่ให้', vrValue($singleVisit['advice'] ?? '')],
    ];
    $followupRows = [
        ['กำหนดติดตาม', vrFollowup($singleVisit['next_visit_date'] ?? null, $today)],
        ['การส่งต่อ', vrRef($singleVisit) ? (vrPresent($singleVisit,'referral_type') ? trim((string)$singleVisit['referral_type']) : 'มีรายการส่งต่อ') : 'ไม่มีรายการส่งต่อ'],
    ];
    if (vrRef($singleVisit) && vrPresent($singleVisit, 'referral_reason')) $followupRows[] = ['เหตุผลที่ส่งต่อ', trim((string)$singleVisit['referral_reason'])];

    $highlightRows[] = ['ประเด็นสำคัญจากการเข้าเยี่ยม', (
        vrPresent($singleVisit,'general_condition') ? 'สภาพทั่วไป: '.trim((string)$singleVisit['general_condition']) : 'ไม่มีการบันทึกสภาพทั่วไป'
    )];
    $highlightRows[] = ['สิ่งที่ได้ดำเนินการ', vrPresent($singleVisit,'care_actions') ? trim((string)$singleVisit['care_actions']) : 'ไม่ได้บันทึกการดูแลที่ดำเนินการ'];
    $summaryParts = [];
    $summaryParts[] = 'เข้าเยี่ยมผู้สูงอายุ 1 ราย วันที่ '.$visitDateText;
    if (vrPresent($singleVisit,'visit_type')) $summaryParts[] = 'ประเภทการเยี่ยม: '.trim((string)$singleVisit['visit_type']);
    if (vrPresent($singleVisit,'advice')) $summaryParts[] = 'ให้คำแนะนำ: '.trim((string)$singleVisit['advice']);
    $summaryParts[] = vrRef($singleVisit) ? 'มีรายการส่งต่อ'.(vrPresent($singleVisit,'referral_reason') ? ' เนื่องจาก '.trim((string)$singleVisit['referral_reason']) : '') : 'ไม่มีรายการส่งต่อ';
    $summaryLead = implode(' ', $summaryParts);
    $followBullets = array_values(array_filter([
        vrPresent($singleVisit,'advice') ? 'ติดตามการปฏิบัติตามคำแนะนำที่ให้แก่ผู้สูงอายุและผู้ดูแล' : '',
        !empty($singleVisit['next_visit_date']) && $singleVisit['next_visit_date'] !== '0000-00-00' ? 'กำหนดติดตามครั้งถัดไป: '.vrFollowup($singleVisit['next_visit_date'], $today) : 'หากมีอาการเปลี่ยนแปลง ควรนัดติดตามและบันทึกผลการดูแลเพิ่มเติม',
        vrRef($singleVisit) ? 'ประสานการส่งต่อและติดตามผลจากหน่วยบริการที่เกี่ยวข้อง' : 'ติดตามอาการทั่วไปและประเมินซ้ำเมื่อจำเป็น'
    ]));
} else {
    $patientInfoRows = [
        ['ผู้จัดทำรายงาน', $doctorName ?: 'ไม่ระบุผู้จัดทำ', 'ตำแหน่ง', vrValue($doctor['medical_position'] ?? '')],
        ['หน่วยงาน', vrValue($doctor['department'] ?? ''), 'วันที่ออกรายงาน', $reportDateText],
        ['ช่วงข้อมูล', 'ประจำเดือน '.$monthLabel, 'จำนวนรายการเข้าเยี่ยม', (string)$visitCount.' ครั้ง'],
        ['ผู้สูงอายุที่ครอบคลุม', (string)count($patientIds).' ราย', 'รายการส่งต่อ', (string)$referrals.' ครั้ง'],
    ];
    $visitInfoRows = [
        ['ประเภทการเข้าเยี่ยม', $typeCounts ? implode(' · ',array_map(static fn($key,$n)=>$key.' '.$n.' ครั้ง',array_keys($typeCounts),array_values($typeCounts))) : 'ไม่มีรายการเข้าเยี่ยม'],
        ['ผู้ดูแลที่มีบันทึก', count($caregivers).' คน'],
        ['วันติดตามที่ระบุ', count($followupDates).' วันที่ (นับวันไม่ซ้ำ)'],
    ];
    $highText = $visitCount ? 'มีบันทึกการเข้าเยี่ยม '.$visitCount.' ครั้ง ครอบคลุมผู้สูงอายุ '.count($patientIds).' ราย' : 'ไม่มีบันทึกการเข้าเยี่ยมในช่วงเวลาที่เลือก';
    $highlightRows = [['สรุปภาพรวม', $highText]];
    $summaryLead = $visitCount ? 'สรุปผลภาพรวม: มีบันทึกการเข้าเยี่ยม '.$visitCount.' ครั้ง ครอบคลุมผู้สูงอายุ '.count($patientIds).' ราย และมีรายการส่งต่อ '.$referrals.' ครั้ง' : 'ไม่มีบันทึกการเข้าเยี่ยมในช่วงเวลาที่เลือก';
    $detailNames = [];
    foreach ($visits as $i => $v) {
        if ($i >= 3) break;
        $detailNames[] = vrThaiDate($v['visit_date']).' · '.vrValue($v['Fullname']);
    }
    $followBullets = array_values(array_filter([
        $detailNames ? 'รายการเข้าเยี่ยมล่าสุด: '.implode(' / ', $detailNames) : '',
        count($followupDates) ? 'มีวันติดตามที่ระบุ '.count($followupDates).' วัน ควรตรวจสอบความครบถ้วนของการติดตาม' : 'ยังไม่มีการระบุวันติดตามในบันทึกของเดือนนี้',
        $referrals ? 'มีรายการส่งต่อ '.$referrals.' ครั้ง ควรติดตามผลการรักษาหรือการประสานต่อเนื่อง' : 'ไม่พบรายการส่งต่อในช่วงเวลานี้'
    ]));
}

$pdfData = [
    'org'=>'ระบบบันทึกสุขภาพผู้สูงอายุ '.appName(),
    'title'=>'รายงานสรุปการเข้าเยี่ยมผู้สูงอายุ',
    'subtitle'=>'ระบบบันทึกสุขภาพผู้สูงอายุ '.appName(),
    'doctor'=>$doctorName?:'หมอ', 'director'=>$directorName,
    // ข้อมูลชุดเดียวกับที่แสดงบนกระดาษหน้าเว็บ ไม่ใช้ sections ของรายงานแบบเก่า
    'preview'=>[
        'single'=>$singleVisit !== null,
        'report_date'=>'วันที่ออกรายงาน '.$reportDateText,
        'visit_date'=>$singleVisit ? 'วันที่เข้าเยี่ยม '.$visitDateText : 'ช่วงข้อมูล ประจำเดือน '.$monthLabel,
        'patient_title'=>$singleVisit ? 'ข้อมูลผู้สูงอายุ' : 'ข้อมูลการรายงาน',
        'patient_rows'=>$patientInfoRows,
        'visit_title'=>$singleVisit ? 'สรุปผลการเข้าเยี่ยม' : 'สรุปภาพรวมการเข้าเยี่ยม',
        'visit_rows'=>$visitInfoRows,
        'summary'=>$summaryLead,
        'highlight_title'=>$singleVisit ? 'ประเด็นสำคัญจากการเข้าเยี่ยม' : 'ประเด็นสำคัญจากข้อมูลเดือนนี้',
        'highlight_rows'=>$highlightRows,
        'follow_title'=>$singleVisit ? 'ข้อเสนอแนะและแนวทางติดตาม' : 'ข้อเสนอแนะและการติดตาม',
        'follow_rows'=>$singleVisit ? $followupRows : [],
        'follow_bullets'=>$followBullets,
    ],
    'filename'=>'รายงานเข้าเยี่ยม_'.($visitId?:$month).'_ปรับหัวรายงาน.pdf'
];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=vrH($pdfData['title'].' '.$subtitle)?></title>
<style>
@page{size:A4 portrait;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#eef2f2;color:#111;font-family:"Leelawadee UI","TH Sarabun New","Segoe UI",Tahoma,sans-serif;font-size:14px}
.toolbar{width:210mm;margin:12px auto 8px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.toolbar .left,.toolbar .right{display:flex;gap:8px;align-items:center}
.toolbar a,.toolbar button{border:1px solid #c8d8d6;border-radius:10px;background:#fff;color:#274b4b;padding:9px 14px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}
.toolbar .primary{background:#3daea9;border-color:#3daea9;color:#fff}
.sheet{position:relative;width:210mm;min-height:297mm;height:297mm;margin:0 auto 18px;background:#fff;padding:16mm 14mm 40mm;box-shadow:0 5px 22px rgba(0,0,0,.13);overflow:hidden}
.header{text-align:center;margin-bottom:14px}
.header .org{font-size:16px;font-weight:700;line-height:1.45}
.header .title{font-size:31px;font-weight:800;line-height:1.28;margin-top:2px}
.header .meta{margin-top:6px;font-size:15px;line-height:1.5}
.header .meta-row{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-top:12px;text-align:left;font-size:14px}
.section{margin-top:16px}
.section-title{display:block;font-size:21px;font-weight:800;line-height:1.25;padding-bottom:6px;margin-bottom:10px;border-bottom:1px solid #333}
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
.empty{font-size:14px;line-height:1.7;color:#444}
@media screen{body.print-preview-active::after{content:"";position:fixed;inset:0;z-index:2147483647;background:#fff;pointer-events:none}}
@media print{html,body{width:210mm;height:297mm;background:#fff;margin:0!important;padding:0!important}.toolbar{display:none!important}.sheet{margin:0!important;box-shadow:none!important;width:210mm!important;min-height:297mm!important;height:297mm!important;padding:16mm 14mm 40mm!important;overflow:hidden!important}}
</style>
</head>
<body class="<?= $embed ? 'embed-mode' : '' ?>">
<?php if (!$embed): ?>
<div class="toolbar">
    <div class="left"><a href="<?= vrH($backHref) ?>">ย้อนกลับ</a></div>
    <div class="right"><button type="button" id="visitDownload">ดาวน์โหลด PDF</button><button type="button" class="primary" id="printVisitBtn">พิมพ์</button></div>
</div>
<?php endif; ?>
<main class="sheet">
<?php if (!$visits): ?>
    <div class="header"><div class="title">ไม่พบข้อมูลการเข้าเยี่ยม</div></div>
<?php else: ?>
    <div class="header">
        <div class="title">รายงานสรุปการเข้าเยี่ยมผู้สูงอายุ</div>
        <div class="meta">ระบบบันทึกสุขภาพผู้สูงอายุ <?= vrH(appName()) ?></div>
        <div class="meta-row">
            <div><?= $singleVisit ? 'วันที่เข้าเยี่ยม '.vrH($visitDateText) : 'ช่วงข้อมูล ประจำเดือน '.vrH($monthLabel) ?></div>
            <div>วันที่ออกรายงาน <?= vrH($reportDateText) ?></div>
        </div>
    </div>

    <section class="section">
        <div class="section-title"><?= $singleVisit ? 'ข้อมูลผู้สูงอายุ' : 'ข้อมูลการรายงาน' ?></div>
        <table class="info-table">
            <?php foreach($patientInfoRows as $row): ?>
            <tr>
                <td class="label"><?= vrH($row[0]) ?></td><td class="value"><?= vrH($row[1]) ?></td>
                <td class="label"><?= vrH($row[2]) ?></td><td class="value"><?= vrH($row[3]) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="section">
        <div class="section-title"><?= $singleVisit ? 'สรุปผลการเข้าเยี่ยม' : 'สรุปภาพรวมการเข้าเยี่ยม' ?></div>
        <table class="info-table">
            <?php foreach($visitInfoRows as $row): ?>
            <tr>
                <td class="label"><?= vrH($row[0]) ?></td><td class="value" colspan="3"><?= vrH($row[1]) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($summaryLead !== ''): ?><div class="summary-paragraph" style="margin-top:8px;"><?= vrH($summaryLead) ?></div><?php endif; ?>
    </section>

    <section class="section">
        <div class="section-title"><?= $singleVisit ? 'ประเด็นสำคัญจากการเข้าเยี่ยม' : 'ประเด็นสำคัญจากข้อมูลเดือนนี้' ?></div>
        <table class="info-table">
            <?php foreach($highlightRows as $row): ?>
            <tr>
                <td class="label"><?= vrH($row[0]) ?></td><td class="value" colspan="3"><?= vrH($row[1]) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="section">
        <div class="section-title"><?= $singleVisit ? 'ข้อเสนอแนะและแนวทางติดตาม' : 'ข้อเสนอแนะและการติดตาม' ?></div>
        <?php if ($singleVisit && $followupRows): ?>
        <table class="info-table">
            <?php foreach($followupRows as $row): ?>
            <tr>
                <td class="label"><?= vrH($row[0]) ?></td><td class="value" colspan="3"><?= vrH($row[1]) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <div class="note-area">
            <?php if ($followBullets): ?>
            <ul class="bullets">
                <?php foreach($followBullets as $line): ?><li><?= vrH($line) ?></li><?php endforeach; ?>
            </ul>
            <?php else: ?><div class="empty">-</div><?php endif; ?>
        </div>
    </section>

    <section class="sign-section">
        <div class="sign-grid">
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= vrH($doctorName ?: 'หมอ') ?><br>หมอ</div>
            </div>
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= vrH($directorName) ?><br>ผู้อำนวยการ</div>
            </div>
        </div>
    </section>
<?php endif; ?>
</main>
<?php if (!$embed): ?>
<script src="visit_report_pdf.js?v=20260925-header-final"></script>
<script>
const visitReportData=<?=json_encode($pdfData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
(function(){
  const button=document.getElementById('printVisitBtn');
  const hideBackground=()=>document.body.classList.add('print-preview-active');
  const restoreBackground=()=>document.body.classList.remove('print-preview-active');
  window.addEventListener('afterprint',restoreBackground);
  if(button) button.addEventListener('click',function(){
    hideBackground();
    try { window.print(); }
    catch(error) { restoreBackground(); throw error; }
  });
})();
document.getElementById('visitDownload').addEventListener('click',async function(){
  const btn=this;btn.disabled=true;const original=btn.textContent;btn.textContent='กำลังสร้าง PDF…';
  try{await window.downloadVisitReportPdf(visitReportData)}catch(error){alert('ไม่สามารถดาวน์โหลด PDF ได้: '+error.message)}
  finally{btn.disabled=false;btn.textContent=original}
});
</script>
<?php endif; ?>
</body></html>
