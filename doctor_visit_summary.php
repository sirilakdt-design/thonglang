<?php
require_once __DIR__ . '/connect.php';
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

function dvsTableExists(mysqli $conn, string $table): bool {
    $safe = mysqli_real_escape_string($conn, $table);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $r && mysqli_num_rows($r) > 0;
}
function dvsH($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function dvsDate(?string $v, bool $time=false): string {
    if (!$v) return '-';
    $ts = strtotime($v); if (!$ts) return dvsH($v);
    $m=[1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $s=(int)date('j',$ts).' '.$m[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
    if ($time) $s.=' '.date('H:i',$ts).' น.';
    return $s;
}
function dvsScalar(mysqli $conn, string $sql, array $params=[], string $types=''): int {
    if (!$params) { $r=mysqli_query($conn,$sql); if(!$r) return 0; $row=mysqli_fetch_row($r); return (int)($row[0]??0); }
    $s=mysqli_prepare($conn,$sql); if(!$s) return 0; mysqli_stmt_bind_param($s,$types,...$params); mysqli_stmt_execute($s); $r=mysqli_stmt_get_result($s); $row=$r?mysqli_fetch_row($r):null; mysqli_stmt_close($s); return (int)($row[0]??0);
}
function dvsPageUrl(int $page): string {
    $query = $_GET;
    $query['page'] = max(1, $page);
    return '?' . http_build_query($query);
}

/** Page numbers with ellipses; keep the first three pages visible on page one. */
function dvsPaginationPages(int $page, int $totalPages): array {
    if ($totalPages <= 7) return range(1, $totalPages);
    if ($page <= 4) return array_merge(range(1, 5), ['...'], [$totalPages]);
    if ($page >= $totalPages - 3) return array_merge([1, '...'], range($totalPages - 4, $totalPages));
    return [1, '...', $page - 1, $page, $page + 1, '...', $totalPages];
}

$doctorId=(int)($_SESSION['user_id']??0);
$q=trim((string)($_GET['q']??''));
$caregiverFilter=(int)($_GET['caregiver_id']??0);
$referralFilter=trim((string)($_GET['referral']??''));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$currentMonth=date('Y-m');
$month=trim((string)($_GET['month']??$currentMonth));
if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=$currentMonth;
if($month>$currentMonth) $month=$currentMonth;
$monthStart=$month.'-01';
$monthEnd=date('Y-m-d',strtotime($monthStart.' +1 month'));
$monthTs=strtotime($monthStart);
$prevMonth=date('Y-m',strtotime('-1 month',$monthTs));
$nextMonth=date('Y-m',strtotime('+1 month',$monthTs));
$canGoNextMonth=$month<$currentMonth;
$thaiMonths=[1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$monthLabel=$thaiMonths[(int)date('n',$monthTs)].' '.((int)date('Y',$monthTs)+543);
$page=max(1,(int)($_GET['page']??1));
$perPage=10;
$filteredTotal=0;
$totalPages=1;

$hasVisits=dvsTableExists($conn,'caregiver_visit_record');
$hasAssignments=dvsTableExists($conn,'patient_caregiver_assignment');
$hasAdlAssessments=dvsTableExists($conn,'adl_assessment');

$totalVisits=$todayVisits=$referralVisits=$patientVisited=0;
$rows=[];$caregivers=[];

if ($hasVisits && $hasAssignments) {
    $totalVisits=dvsScalar($conn,"SELECT COUNT(*) FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id WHERE a.doctor_user_id=?",[$doctorId],'i');
    $todayVisits=dvsScalar($conn,"SELECT COUNT(*) FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id WHERE a.doctor_user_id=? AND vr.visit_date=CURDATE()",[$doctorId],'i');
    $referralVisits=dvsScalar($conn,"SELECT COUNT(*) FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id WHERE a.doctor_user_id=? AND vr.doctor_referral=1",[$doctorId],'i');
    $patientVisited=dvsScalar($conn,"SELECT COUNT(DISTINCT vr.patient_id) FROM caregiver_visit_record vr JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id WHERE a.doctor_user_id=?",[$doctorId],'i');

    $s=mysqli_prepare($conn,"SELECT DISTINCT u.user_id,u.display_name,u.username FROM patient_caregiver_assignment a LEFT JOIN users u ON u.user_id=a.caregiver_user_id WHERE a.doctor_user_id=? AND a.caregiver_user_id IS NOT NULL ORDER BY COALESCE(NULLIF(u.display_name,''),u.username)");
    if($s){mysqli_stmt_bind_param($s,'i',$doctorId);mysqli_stmt_execute($s);$r=mysqli_stmt_get_result($s);while($x=mysqli_fetch_assoc($r))$caregivers[]=$x;mysqli_stmt_close($s);}    

    $where=['a.doctor_user_id=?','vr.visit_date>=?','vr.visit_date<?']; $params=[$doctorId,$monthStart,$monthEnd]; $types='iss';
    if($q!==''){ $where[]="(p.Fullname LIKE ? OR cg.display_name LIKE ? OR cg.username LIKE ? OR vr.symptom_notes LIKE ? OR vr.problem_notes LIKE ?)"; $like='%'.$q.'%'; for($i=0;$i<5;$i++)$params[]=$like; $types.='sssss'; }
    if($caregiverFilter>0){$where[]='vr.caregiver_user_id=?';$params[]=$caregiverFilter;$types.='i';}
    if($referralFilter==='yes'){$where[]='vr.doctor_referral=1';}
    elseif($referralFilter==='no'){$where[]='vr.doctor_referral=0';}
    if($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)){$where[]='vr.visit_date>=?';$params[]=$dateFrom;$types.='s';}
    if($dateTo!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)){$where[]='vr.visit_date<=?';$params[]=$dateTo;$types.='s';}

    $countSql="SELECT COUNT(*)
          FROM caregiver_visit_record vr
          JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
          JOIN patient p ON p.Patient_id=vr.patient_id
          LEFT JOIN users cg ON cg.user_id=vr.caregiver_user_id
          WHERE ".implode(' AND ',$where);
    $filteredTotal=dvsScalar($conn,$countSql,$params,$types);
    $totalPages=max(1,(int)ceil($filteredTotal/$perPage));
    if($page>$totalPages)$page=$totalPages;
    $offset=($page-1)*$perPage;

    $adlCompletionSql=$hasAdlAssessments ? "EXISTS(SELECT 1 FROM adl_assessment ad WHERE ad.patient_id=p.Patient_id LIMIT 1)" : "0";
    $sql="SELECT vr.*,p.Fullname,p.Age,p.Gender,p.Phone,p.Disease,p.Address,\n". "                 {$adlCompletionSql} AS has_adl_assessment,
                 cg.display_name caregiver_name,cg.username caregiver_username,
                 a.next_visit_date assignment_next_visit
          FROM caregiver_visit_record vr
          JOIN patient_caregiver_assignment a ON a.assignment_id=vr.assignment_id
          JOIN patient p ON p.Patient_id=vr.patient_id
          LEFT JOIN users cg ON cg.user_id=vr.caregiver_user_id
          WHERE ".implode(' AND ',$where)."
          ORDER BY vr.visit_date DESC,COALESCE(vr.visit_time,'00:00:00') DESC,vr.visit_id DESC
          LIMIT ? OFFSET ?";
    $pageParams=$params; $pageTypes=$types.'ii'; $pageParams[]=$perPage; $pageParams[]=$offset;
    $s=mysqli_prepare($conn,$sql);
    if($s){mysqli_stmt_bind_param($s,$pageTypes,...$pageParams);mysqli_stmt_execute($s);$r=mysqli_stmt_get_result($s);while($x=mysqli_fetch_assoc($r))$rows[]=$x;mysqli_stmt_close($s);}    
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>สรุปการเข้าเยี่ยม | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.visit-summary-page{display:grid;gap:18px;padding-bottom:18px}
.visit-summary-head{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center;padding:28px 30px;border:1px solid #d4ebe8;border-radius:28px;background:linear-gradient(135deg,#eef9f7 0%,#f6fcfb 48%,#eaf7fb 100%);box-shadow:0 18px 44px rgba(46,112,116,.08);overflow:hidden}
.visit-summary-head:after{content:'';position:absolute;right:-70px;top:-90px;width:270px;height:270px;border-radius:50%;background:radial-gradient(circle,rgba(88,191,192,.18) 0%,rgba(88,191,192,0) 70%)}
.visit-summary-copy,.visit-summary-head-side{position:relative;z-index:1}.visit-summary-eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:9px;font-size:11px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#6e8f8b}.visit-summary-head h1{margin:0;font-size:30px;line-height:1.2;color:#1e555a}.visit-summary-head p{max-width:860px;margin:8px 0 0;color:#718987;line-height:1.65}.visit-summary-head-side{display:grid;gap:10px;justify-items:end}.visit-summary-chip{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:999px;background:rgba(255,255,255,.88);border:1px solid #d6e9e6;color:#31676a;font-size:12px;font-weight:900;box-shadow:0 8px 18px rgba(46,112,116,.05)}.visit-summary-status{display:flex;align-items:center;gap:8px;color:#5e807b;font-size:12px;font-weight:800}.visit-summary-status-dot{width:8px;height:8px;border-radius:50%;background:#54bda8;box-shadow:0 0 0 5px rgba(84,189,168,.12)}
.visit-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.visit-kpi{padding:20px 20px 18px;border-radius:22px;background:#fff;border:1px solid #d9ebe9;box-shadow:0 12px 30px rgba(46,112,116,.06);position:relative;overflow:hidden}.visit-kpi:before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#5fc8c2,#91ddd7)}.visit-kpi:nth-child(2):before{background:linear-gradient(90deg,#7bb9da,#a9d7ed)}.visit-kpi.warn:before{background:linear-gradient(90deg,#d9b35f,#f1d39a)}.visit-kpi.danger:before{background:linear-gradient(90deg,#cf8585,#e6b1b1)}.visit-kpi-label{font-size:11px;color:#7a918d;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.visit-kpi-value{font-size:34px;line-height:1;font-weight:900;color:#20585d;margin-top:10px}.visit-kpi-note{font-size:12px;color:#819591;margin-top:8px;line-height:1.45}
.filter-card,.visit-list-card{background:#fff;border:1px solid #d8ebe8;border-radius:24px;box-shadow:0 14px 34px rgba(46,112,116,.06);padding:22px}.filter-card{padding-top:20px}.visit-search-outside{display:flex;justify-content:space-between;align-items:end;gap:14px;flex-wrap:wrap;padding:0 2px 2px}.visit-search-form{display:flex;align-items:end;gap:10px;flex:1;max-width:760px}.visit-search-field{flex:1}.visit-search-field label{display:block;margin:0 0 7px;color:#315f62;font-size:13px;font-weight:900}.visit-search-field input{width:100%;min-height:46px;padding:0 14px;border:1px solid #cfe3e0;border-radius:14px;background:#fff;color:#244f52;font:inherit;outline:none;box-shadow:0 7px 18px rgba(46,112,116,.04)}.visit-search-field input:focus{border-color:#59bfc0;box-shadow:0 0 0 3px rgba(89,191,192,.12)}.visit-search-btn,.visit-search-clear-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border-radius:14px;font:inherit;font-size:13px;font-weight:900;text-decoration:none;cursor:pointer}.visit-search-btn{border:1px solid #53b7b9;background:linear-gradient(135deg,#5fc4c3,#49aaae);color:#fff}.visit-search-clear-btn{border:1px solid #d6e7e4;background:#fff;color:#356768}.visit-search-result{padding-bottom:10px;color:#738b87;font-size:12px;font-weight:800;white-space:nowrap}.section-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}.section-card-head h2{margin:0;color:#24575b;font-size:20px}.section-card-head p{margin:5px 0 0;color:#81938f;font-size:12px}.result-count-chip{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 12px;border-radius:999px;background:#f2faf8;border:1px solid #d9ebe7;color:#346868;font-size:12px;font-weight:900}
.filter-grid{display:grid;grid-template-columns:1.3fr .9fr .8fr .75fr .75fr auto;gap:12px;align-items:end}.field{display:grid;gap:7px}.field label{font-size:12px;font-weight:900;color:#385e60}.field input,.field select{min-height:44px;border:1px solid #d6e7e4;border-radius:13px;padding:9px 11px;font:inherit;background:#fff;box-shadow:0 5px 14px rgba(46,112,116,.03)}.field input:focus,.field select:focus{outline:none;border-color:#65c1c0;box-shadow:0 0 0 4px rgba(101,193,192,.12)}.filter-actions{display:flex;gap:8px}.btn-search,.btn-reset{min-height:44px;border-radius:13px;padding:9px 15px;font:inherit;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.btn-search{border:0;background:linear-gradient(135deg,#5fc4c3,#49aaae);color:#fff;box-shadow:0 10px 20px rgba(88,191,192,.18)}.btn-search:hover{filter:brightness(.98);transform:translateY(-1px)}.btn-reset{border:1px solid #d7e9e6;background:#f7fbfb;color:#35696a}
.visit-table-wrap{overflow:auto;border:1px solid #dcebe9;border-radius:18px;background:#fff}.visit-table{width:100%;border-collapse:separate;border-spacing:0;min-width:720px}.visit-table th{position:sticky;top:0;z-index:2;background:linear-gradient(180deg,#edf8f7,#e6f3f4);color:#285b5f;text-align:left;padding:13px 12px;font-size:12px;font-weight:900;white-space:nowrap;border-bottom:1px solid #d9e9e8}.visit-table td{padding:14px 12px;border-bottom:1px solid #edf3f2;font-size:13px;color:#2d5051;vertical-align:middle;background:#fff}.visit-table tbody tr:nth-child(even) td{background:#fcfefe}.visit-table tbody tr:hover td{background:#f6fbfb}.visit-table tbody tr:last-child td{border-bottom:0}.patient-name{font-weight:900;color:#24575b}.meta{font-size:11px;color:#849793;margin-top:4px;line-height:1.45}.badge{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:900;background:#eef9f7;color:#28655f;border:1px solid #d4ebe5}.badge.ref{background:#fff1f1;color:#9b5555;border-color:#f0d1d1}.vital-stack{display:grid;gap:4px}.vital-line{font-size:12px;color:#446969;white-space:nowrap}.btn-detail{border:1px solid #cfe7e4;background:linear-gradient(135deg,#f2faf8,#eef7fb);color:#28615f;border-radius:11px;padding:8px 11px;font:inherit;font-size:12px;font-weight:900;cursor:pointer}.btn-detail:hover{background:#e7f6f3;transform:translateY(-1px)}.empty{padding:42px;text-align:center;color:#80938f;background:#fbfefe}
.visit-detail{display:none;position:fixed;inset:0;background:rgba(37,66,68,.30);backdrop-filter:blur(3px);z-index:5000;padding:22px;align-items:center;justify-content:center}.visit-detail.open{display:flex}.visit-detail-card{width:min(1020px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:26px;border:1px solid #d8ebe8;box-shadow:0 26px 70px rgba(30,73,76,.20)}.detail-head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;padding:22px 24px;border-bottom:1px solid #e6f0ee;background:linear-gradient(135deg,#f7fcfb,#eef8fa);position:sticky;top:0;z-index:2}.detail-head h2{margin:0;color:#24575b}.detail-close{width:38px;height:38px;border-radius:50%;border:1px solid #d7e7e4;background:#fff;color:#486d6c;font-size:22px;cursor:pointer;box-shadow:0 6px 14px rgba(46,112,116,.06)}.detail-body{padding:22px 24px}.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.detail-box{padding:12px 13px;border:1px solid #e0ecea;border-radius:13px;background:#fbfefd}.detail-box span{display:block;font-size:11px;color:#829592;font-weight:800}.detail-box strong{display:block;margin-top:5px;color:#2b5355;font-size:13px;white-space:pre-wrap;line-height:1.5}.detail-box.full{grid-column:1/-1}.section-label{margin:20px 0 10px;padding:9px 11px;border-radius:11px;background:#eef9f7;border-left:4px solid #5fc3c1;font-size:13px;font-weight:900;color:#346365}.doctor-alert{padding:13px 14px;border-radius:13px;background:#fff2f2;border:1px solid #efd1d1;color:#995858;font-weight:900;line-height:1.5}.page-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:8px 14px;border-radius:12px;background:#58bfc0;color:#fff;text-decoration:none;font-weight:800}.visit-pagination{display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap;margin-top:18px}.visit-page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 10px;border-radius:11px;border:1px solid #d5e7e4;background:#fff;color:#2f6668;text-decoration:none;font-size:13px;font-weight:900;box-shadow:0 5px 12px rgba(46,112,116,.04)}.visit-page-btn:hover{background:#eef8f6}.visit-page-btn.active{background:linear-gradient(135deg,#5fc4c3,#49aaae);border-color:#53b5b6;color:#fff}.visit-page-btn.disabled{opacity:.38;pointer-events:none}.visit-page-dots{display:inline-flex;align-items:center;justify-content:center;min-width:30px;color:#78908c;font-weight:900}
@media(max-width:1150px){.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.visit-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions{grid-column:1/-1}}
@media(max-width:700px){.visit-kpis,.filter-grid,.detail-grid{grid-template-columns:1fr}.visit-summary-head{grid-template-columns:1fr}.visit-summary-head-side{justify-items:start}.detail-box.full{grid-column:auto}.filter-actions{grid-column:auto;display:grid;grid-template-columns:1fr 1fr}.visit-summary-head{padding:22px 20px}.visit-summary-head h1{font-size:26px}.filter-card,.visit-list-card{padding:18px}.visit-search-form{display:grid;grid-template-columns:1fr 1fr;width:100%;max-width:none}.visit-search-field{grid-column:1/-1}.visit-search-btn,.visit-search-clear-btn{width:100%}.visit-search-result{padding:0}}
.doctor-month-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:2px;padding:0;border:none;border-radius:0;background:transparent}.doctor-month-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.doctor-month-btn{display:inline-flex;align-items:center;justify-content:center;min-width:42px;min-height:42px;padding:0 14px;border:1px solid #cfe4e1;border-radius:13px;background:#fff;color:#2e6467;text-decoration:none;font-weight:900;box-shadow:0 6px 14px rgba(46,112,116,.04)}.doctor-month-current{font-size:16px;font-weight:900;color:#214f54}.doctor-month-picker{margin:0}.doctor-month-picker select{appearance:none;-webkit-appearance:none;-moz-appearance:none;min-width:240px;height:54px;padding:0 52px 0 18px;border:1px solid #cfe4e1;border-radius:16px;background-color:#fff;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%232b3b3b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cline x1='16' y1='3' x2='16' y2='7'/%3E%3Cline x1='8' y1='3' x2='8' y2='7'/%3E%3Cline x1='3' y1='11' x2='21' y2='11'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;background-size:18px 18px;color:#244f52;font:inherit;font-size:16px;font-weight:500;outline:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,112,116,.03)}.doctor-month-picker select:focus{border-color:#83cbc8;box-shadow:0 0 0 3px rgba(89,191,192,.10)}.doctor-month-form{display:flex;gap:8px;align-items:center}.doctor-month-form input{height:40px;border:1px solid #cfe4e1;border-radius:12px;padding:0 12px;font:inherit}.doctor-month-form button{height:40px;border:0;border-radius:12px;padding:0 14px;background:#59bfc0;color:#fff;font:inherit;font-weight:900}


.month-calendar-form{display:flex;gap:12px;align-items:center}
/* Thai month picker */
.visit-print-btn{display:inline-flex;align-items:center;justify-content:center;height:52px;padding:0 20px;border-radius:16px;background:#fff;border:1px solid #cfe4e1;color:#285f62;text-decoration:none;font-size:14px;font-weight:900;box-shadow:0 6px 14px rgba(46,112,116,.04)}.visit-print-btn:hover{background:#f4fbfa;border-color:#8dceca}
.thai-month-picker{position:relative;margin:0;z-index:60}
.thai-month-picker-toggle{display:flex;align-items:center;justify-content:space-between;gap:14px;min-width:250px;height:58px;padding:0 18px 0 20px;border:1px solid #cfe4e1;border-radius:20px;background:#fff;color:#244f52;font:inherit;font-size:18px;font-weight:800;cursor:pointer;box-shadow:0 6px 14px rgba(46,112,116,.04)}
.thai-month-picker-toggle:focus,.thai-month-picker.open .thai-month-picker-toggle{border-color:#75c7c5;box-shadow:0 0 0 3px rgba(89,191,192,.12);outline:none}
.thai-month-picker-icon{width:19px;height:19px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto}
.thai-month-picker-icon svg{width:18px;height:18px;display:block}
.thai-month-picker-popover{position:absolute;right:0;top:calc(100% + 8px);width:320px;padding:14px;background:#fff;border:1px solid #d7e5e3;border-radius:16px;box-shadow:0 18px 44px rgba(29,68,71,.18);display:none;z-index:999}
.thai-month-picker.open .thai-month-picker-popover{display:block}
.thai-month-picker-yearbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:2px 2px 12px;border-bottom:1px solid #e6efed;margin-bottom:12px}
.thai-month-picker-year{font-size:17px;font-weight:900;color:#244f52}
.thai-month-year-controls{display:flex;gap:6px}
.thai-month-year-btn{width:32px;height:32px;border:1px solid #d8e8e5;border-radius:9px;background:#f9fcfb;color:#5d7777;font:inherit;font-weight:900;cursor:pointer}
.thai-month-year-btn:disabled{opacity:.32;cursor:not-allowed}.thai-month-year-text{width:auto;min-width:78px;padding:0 10px;font-size:11px}
.thai-month-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.thai-month-option{height:42px;border:1px solid transparent;border-radius:10px;background:transparent;color:#294f53;font:inherit;font-size:14px;font-weight:700;cursor:pointer}
.thai-month-option:hover{background:#eef8f7;border-color:#d8ebe8}
.thai-month-option.selected{background:#3f4a4d;color:#fff;border-color:#3f4a4d}
.thai-month-option:disabled{color:#b7c0bf;background:transparent;cursor:not-allowed}
.thai-month-picker-footer{display:flex;justify-content:flex-end;margin-top:12px;padding-top:10px;border-top:1px solid #e8f0ef}
.thai-month-today{border:0;background:transparent;color:#2f7e82;font:inherit;font-size:13px;font-weight:900;cursor:pointer;padding:5px 4px}
@media(max-width:620px){.thai-month-picker{width:100%}.thai-month-picker-toggle{width:100%;min-width:0}.thai-month-picker-popover{right:auto;left:0;width:min(320px,calc(100vw - 44px))}}


.visit-detail-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.visit-row-print{text-decoration:none;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}

/* FINAL: ปุ่มสรุปตามสถานะการประเมิน และสรุปแบบเดียวกับหน้าพิมพ์ */
.btn-detail.is-disabled{display:inline-flex;align-items:center;justify-content:center;min-height:34px;opacity:.55;cursor:not-allowed;background:#f4f6f6;color:#7d8a88;border-color:#e0e6e5}
.visit-summary-report-card{width:min(1180px,calc(100vw - 34px));height:min(92vh,940px);padding:0!important;overflow:hidden!important}
.visit-summary-report-head{padding:16px 18px;margin:0;border-bottom:1px solid #dce9e7;background:#fff}
.visit-summary-frame-wrap{height:calc(100% - 76px);background:#edf2f2}
.visit-summary-frame{display:block;width:100%;height:100%;border:0;background:#fff}

</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<div class="main">
<?php renderUserTopbar(); ?>
<div class="visit-summary-page">
<div class="doctor-month-toolbar">
    <form class="thai-month-picker" method="get" action="doctor_visit_summary.php">
        <?php if($q!==''): ?><input type="hidden" name="q" value="<?=dvsH($q)?>"><?php endif; ?>
        <input type="hidden" name="month" value="<?=dvsH($month)?>">
        <button type="button" class="thai-month-picker-toggle" aria-haspopup="dialog" aria-expanded="false">
            <span class="thai-month-picker-label"><?=dvsH($monthLabel)?></span>
            <span class="thai-month-picker-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="11" x2="21" y2="11"/></svg></span>
        </button>
        <div class="thai-month-picker-popover" role="dialog" aria-label="เลือกเดือน">
            <div class="thai-month-picker-yearbar"><div class="thai-month-picker-year"></div><div class="thai-month-year-controls"><button type="button" class="thai-month-year-btn thai-month-year-text" data-year-prev>ปีก่อนหน้า</button><button type="button" class="thai-month-year-btn thai-month-year-text" data-year-next>ปีถัดไป</button></div></div>
            <div class="thai-month-grid"></div>
            <div class="thai-month-picker-footer"><button type="button" class="thai-month-today">เดือนปัจจุบัน</button></div>
        </div>
    </form>
</div>
<div class="visit-search-outside">
    <form class="visit-search-form" method="get" action="doctor_visit_summary.php"><input type="hidden" name="month" value="<?=dvsH($month)?>">
        <div class="visit-search-field">
            
            <input id="visitSearch" type="search" name="q" value="<?=dvsH($q)?>" placeholder="ค้นหาชื่อผู้สูงอายุ ชื่อแคร์กิฟเวอร์ หรือข้อมูลการเยี่ยม">
        </div>
        <button class="visit-search-btn" type="submit">ค้นหา</button>
        <?php if($q!==''): ?>
            <a class="visit-search-clear-btn" href="doctor_visit_summary.php">ล้างค้นหา</a>
        <?php endif; ?>
    </form>
</div>
<section class="visit-list-card"><div class="section-card-head"><div><h2>รายการเข้าเยี่ยม</h2></div><div class="result-count-chip" aria-label="จำนวนรายการเข้าเยี่ยม"><?=number_format($filteredTotal)?> รายการ</div></div>
<div class="visit-table-wrap"><table class="visit-table"><thead><tr><th>วันที่เยี่ยม</th><th>ผู้สูงอายุ</th><th>แคร์กิฟเวอร์</th><th>นัดครั้งถัดไป</th><th>รายละเอียด</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="5" class="empty"><?= $hasVisits ? 'ยังไม่พบบันทึกการเข้าเยี่ยมตามเงื่อนไข' : 'ยังไม่มีตารางบันทึกการเข้าเยี่ยม กรุณาให้แคร์กิฟเวอร์เปิดหน้าบันทึกการเข้าเยี่ยมก่อน' ?></td></tr><?php endif;?>
<?php foreach($rows as $r): $cg=trim((string)($r['caregiver_name']??''))?:trim((string)($r['caregiver_username']??'')); $vitals=[]; if($r['bp_systolic']!==null&&$r['bp_diastolic']!==null)$vitals[]=$r['bp_systolic'].'/'.$r['bp_diastolic'].' mmHg'; if($r['pulse']!==null)$vitals[]='P '.$r['pulse']; if($r['temperature']!==null)$vitals[]='T '.$r['temperature'].'°C'; ?>
<tr><td><strong><?=dvsDate($r['visit_date'])?></strong><div class="meta"><?=dvsH($r['visit_time']?substr($r['visit_time'],0,5).' น.':'-')?></div></td><td><div class="patient-name"><?=dvsH($r['Fullname'])?></div><div class="meta">อายุ <?=dvsH($r['Age'])?> ปี</div></td><td><?=dvsH($cg?:'-')?></td><td><?=dvsDate($r['next_visit_date']??null)?></td><td><div class="visit-detail-actions"><?php if(!empty($r['has_adl_assessment'])): ?><button type="button" class="btn-detail" onclick="openVisitSummary(<?= (int)$r['visit_id'] ?>)">ดูสรุป</button><?php else: ?><span class="btn-detail is-disabled" aria-disabled="true">ยังไม่ประเมิน</span><?php endif; ?><a class="btn-detail visit-row-print" target="_blank" rel="noopener" href="doctor_visit_print.php?visit_id=<?= (int)$r['visit_id'] ?>">พิมพ์</a></div></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if($filteredTotal>$perPage): ?>
<nav class="visit-pagination" aria-label="แบ่งหน้ารายการเข้าเยี่ยม">
    <?php if($page>1): ?>
        <a class="visit-page-btn" aria-label="หน้าก่อนหน้า" href="<?=dvsH(dvsPageUrl($page-1))?>">&lt;</a>
    <?php else: ?>
        <span class="visit-page-btn disabled" aria-disabled="true" aria-label="หน้าก่อนหน้า">&lt;</span>
    <?php endif; ?>
    <?php foreach(dvsPaginationPages($page,$totalPages) as $pg): ?>
        <?php if($pg==='...'): ?>
            <span class="visit-page-dots" aria-hidden="true">...</span>
        <?php else: ?>
            <a class="visit-page-btn <?= (int)$pg===$page?'active':'' ?>" <?= (int)$pg===$page?'aria-current="page"':'' ?> aria-label="หน้าที่ <?= (int)$pg ?>" href="<?=dvsH(dvsPageUrl((int)$pg))?>"><?= (int)$pg ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if($page<$totalPages): ?>
        <a class="visit-page-btn" aria-label="หน้าถัดไป" href="<?=dvsH(dvsPageUrl($page+1))?>">&gt;</a>
    <?php else: ?>
        <span class="visit-page-btn disabled" aria-disabled="true" aria-label="หน้าถัดไป">&gt;</span>
    <?php endif; ?>
</nav>
<?php endif; ?>
</section>
</div></div>

<div class="visit-detail" id="visitDetail">
    <div class="visit-detail-card visit-summary-report-card">
        <div class="detail-head visit-summary-report-head">
            <div>
                <h2>สรุปรายงานการเข้าเยี่ยม</h2>
                <div class="meta">รูปแบบเดียวกับหน้าพิมพ์รายงาน</div>
            </div>
            <button class="detail-close" type="button" onclick="closeVisitSummary()">×</button>
        </div>
        <div class="visit-summary-frame-wrap">
            <iframe id="visitSummaryFrame" class="visit-summary-frame" title="สรุปรายงานการเข้าเยี่ยม"></iframe>
        </div>
    </div>
</div>
<script>
function openVisitSummary(visitId){
    const modal=document.getElementById('visitDetail');
    const frame=document.getElementById('visitSummaryFrame');
    frame.src='doctor_visit_print.php?visit_id='+encodeURIComponent(visitId)+'&embed=1';
    modal.classList.add('open');
}
function closeVisitSummary(){
    const modal=document.getElementById('visitDetail');
    const frame=document.getElementById('visitSummaryFrame');
    modal.classList.remove('open');
    frame.src='about:blank';
}
document.getElementById('visitDetail').addEventListener('click',e=>{
    if(e.target.id==='visitDetail')closeVisitSummary();
});
</script>

<script>
(function(){
  const THAI_MONTHS=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  const THAI_SHORT=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  function initPicker(root){
    const toggle=root.querySelector('.thai-month-picker-toggle');
    const label=root.querySelector('.thai-month-picker-label');
    const pop=root.querySelector('.thai-month-picker-popover');
    const yearEl=root.querySelector('.thai-month-picker-year');
    const grid=root.querySelector('.thai-month-grid');
    const hidden=root.querySelector('input[name="month"]');
    const prevYear=root.querySelector('[data-year-prev]');
    const nextYear=root.querySelector('[data-year-next]');
    const todayBtn=root.querySelector('.thai-month-today');
    if(!toggle||!pop||!yearEl||!grid||!hidden)return;
    const now=new Date();
    const maxY=now.getFullYear(), maxM=now.getMonth()+1;
    let [selY,selM]=(hidden.value||'').split('-').map(Number);
    if(!selY||!selM){selY=maxY;selM=maxM;}
    let viewY=selY;
    function close(){root.classList.remove('open');toggle.setAttribute('aria-expanded','false');}
    function render(){
      yearEl.textContent=String(viewY+543);
      grid.innerHTML='';
      THAI_SHORT.forEach((m,i)=>{
        const month=i+1;
        const b=document.createElement('button');b.type='button';b.className='thai-month-option';b.textContent=m;
        const future=viewY>maxY||(viewY===maxY&&month>maxM);
        b.disabled=future;
        if(viewY===selY&&month===selM)b.classList.add('selected');
        b.addEventListener('click',()=>{selY=viewY;selM=month;hidden.value=selY+'-'+String(selM).padStart(2,'0');label.textContent=THAI_MONTHS[selM-1]+' '+(selY+543);close();hidden.form.submit();});
        grid.appendChild(b);
      });
      if(nextYear) nextYear.disabled=viewY>=maxY;
    }
    toggle.addEventListener('click',e=>{e.stopPropagation();const isOpen=root.classList.toggle('open');toggle.setAttribute('aria-expanded',isOpen?'true':'false');if(isOpen){viewY=selY;render();}});
    prevYear&&prevYear.addEventListener('click',()=>{viewY--;render();});
    nextYear&&nextYear.addEventListener('click',()=>{if(viewY<maxY){viewY++;render();}});
    todayBtn&&todayBtn.addEventListener('click',()=>{selY=maxY;selM=maxM;hidden.value=selY+'-'+String(selM).padStart(2,'0');label.textContent=THAI_MONTHS[selM-1]+' '+(selY+543);close();hidden.form.submit();});
    document.addEventListener('click',e=>{if(!root.contains(e.target))close();});
    render();
  }
  document.querySelectorAll('.thai-month-picker').forEach(initPicker);
})();
</script>

</body></html>
