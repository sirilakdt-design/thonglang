<?php
require_once __DIR__.'/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('admin');
mysqli_set_charset($conn,'utf8mb4');
// Ensure the users.role column supports director accounts even if the SQL upgrade was not imported yet.
$roleCol = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'");
if ($roleCol && ($roleInfo = mysqli_fetch_assoc($roleCol))) {
    $roleType = strtolower((string)($roleInfo['Type'] ?? ''));
    if (strpos($roleType, "'director'") === false) {
        @mysqli_query($conn, "ALTER TABLE users MODIFY role ENUM('admin','doctor','caregiver','director') NOT NULL");
    }
}
$message='';$error='';
function splitExecutiveDisplayName(string $displayName): array
{
  $displayName = trim($displayName);
  if ($displayName === '') return ['prefix' => '', 'firstname' => '', 'lastname' => ''];
  $parts = preg_split('/\s+/u', $displayName) ?: [];
  $prefix = $parts[0] ?? '';
  $firstname = $parts[1] ?? '';
  $lastname = count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : '';
  if ($firstname === '' && $prefix !== '') {
    $firstname = $prefix;
    $prefix = '';
  }
  return ['prefix' => $prefix, 'firstname' => $firstname, 'lastname' => $lastname];
}

function joinExecutiveDisplayName(string $prefix, string $firstname, string $lastname): string
{
  return trim(implode(' ', array_filter([$prefix, $firstname, $lastname], static fn($value) => trim((string)$value) !== '')));
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';
 if($action==='save'){
  $id=(int)($_POST['user_id']??0);$editMode=(($_POST['edit_mode']??'0')==='1');$username=trim($_POST['username']??'');$prefix=trim($_POST['prefix']??'');$firstname=trim($_POST['firstname']??'');$lastname=trim($_POST['lastname']??'');$name=joinExecutiveDisplayName($prefix,$firstname,$lastname);$phone=trim($_POST['phone_number']??'');$role='director';$password=(string)($_POST['password']??'');
  if($username===''||$prefix===''||$firstname===''||$lastname==='')$error='กรุณากรอกชื่อนำหน้า ชื่อ นามสกุล และชื่อผู้ใช้ให้ครบถ้วน';
  elseif(isRemovedUsername($username))$error='ชื่อผู้ใช้งานนี้ถูกยกเลิกแล้ว กรุณาใช้ชื่ออื่น';
  elseif($id===0&&$password==='')$error='กรุณากำหนดรหัสผ่านสำหรับผู้ใช้ใหม่';
  else{
   $ck=mysqli_prepare($conn,'SELECT user_id FROM users WHERE username=? AND user_id<>? LIMIT 1');mysqli_stmt_bind_param($ck,'si',$username,$id);mysqli_stmt_execute($ck);$dup=mysqli_fetch_assoc(mysqli_stmt_get_result($ck));mysqli_stmt_close($ck);
   if($dup)$error='ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว';
   elseif($id>0){
    if($password!==''){$hash=password_hash($password,PASSWORD_DEFAULT);$st=mysqli_prepare($conn,"UPDATE users SET username=?,password_hash=?,role=?,display_name=?,phone_number=? WHERE user_id=? AND role='director'");mysqli_stmt_bind_param($st,'sssssi',$username,$hash,$role,$name,$phone,$id);}
    else{$st=mysqli_prepare($conn,"UPDATE users SET username=?,role=?,display_name=?,phone_number=? WHERE user_id=? AND role='director'");mysqli_stmt_bind_param($st,'ssssi',$username,$role,$name,$phone,$id);}
    if(mysqli_stmt_execute($st)){saveSystemSetting($conn,'director_name',$name);mysqli_stmt_close($st);header('Location: executive_users.php?edit_success=1');exit;}else{$error='บันทึกไม่สำเร็จ: '.mysqli_stmt_error($st);mysqli_stmt_close($st);}
   }else{
    $hash=password_hash($password,PASSWORD_DEFAULT);$active=1;$st=mysqli_prepare($conn,'INSERT INTO users(username,password_hash,role,display_name,phone_number,is_active,created_at) VALUES(?,?,?,?,?,?,NOW())');mysqli_stmt_bind_param($st,'sssssi',$username,$hash,$role,$name,$phone,$active);if(mysqli_stmt_execute($st)){saveSystemSetting($conn,'director_name',$name);mysqli_stmt_close($st);if($editMode){header('Location: executive_users.php?edit_success=1');exit;}$message='เพิ่มข้อมูลผู้อำนวยการเรียบร้อยแล้ว';}else{$error='เพิ่มข้อมูลไม่สำเร็จ: '.mysqli_stmt_error($st);mysqli_stmt_close($st);}
   }
  }
 }elseif($action==='delete'){$id=(int)($_POST['user_id']??0);$st=mysqli_prepare($conn,"DELETE FROM users WHERE user_id=? AND role='director'");mysqli_stmt_bind_param($st,'i',$id);mysqli_stmt_execute($st);mysqli_stmt_close($st);$cnt=0;$cr=mysqli_query($conn,"SELECT COUNT(*) AS c FROM users WHERE role='director'");if($cr&&($cx=mysqli_fetch_assoc($cr)))$cnt=(int)$cx['c'];if($cnt===0)saveSystemSetting($conn,'director_name','');$message='ลบข้อมูลผู้อำนวยการเรียบร้อยแล้ว';}elseif($action==='clear_director'){saveSystemSetting($conn,'director_name','');$message='ลบข้อมูลผู้อำนวยการเรียบร้อยแล้ว';}
}
$edit=null;if(isset($_GET['edit'])){$id=(int)$_GET['edit'];$st=mysqli_prepare($conn,"SELECT user_id,username,role,display_name,phone_number FROM users WHERE user_id=? AND role='director' LIMIT 1");mysqli_stmt_bind_param($st,'i',$id);mysqli_stmt_execute($st);$edit=mysqli_fetch_assoc(mysqli_stmt_get_result($st));mysqli_stmt_close($st);}
$configuredDirectorName=trim(systemSetting($conn,'director_name',''));
$editConfig=isset($_GET['edit_config']);
if($edit){
  $nameParts = splitExecutiveDisplayName((string)($edit['display_name'] ?? ''));
  $edit['prefix'] = $nameParts['prefix'];
  $edit['firstname'] = $nameParts['firstname'];
  $edit['lastname'] = $nameParts['lastname'];
}
if($editConfig&&!$edit){
  $defaultDirectorName = $configuredDirectorName ?: 'นาย รัศมี แก้วเนตร';
  $nameParts = splitExecutiveDisplayName($defaultDirectorName);
  $edit=['user_id'=>0,'username'=>'admin','display_name'=>$defaultDirectorName,'phone_number'=>'','prefix'=>$nameParts['prefix'],'firstname'=>$nameParts['firstname'],'lastname'=>$nameParts['lastname']];
}
$isEditing=(bool)$edit||$editConfig;
$search=trim($_GET['search']??'');
$showForm=$isEditing||isset($_GET['show_form']);
$rows=[];
if($search!==''){
 $like='%'.$search.'%';
 $st=mysqli_prepare($conn,"SELECT user_id,username,role,display_name,phone_number,is_active,created_at FROM users WHERE role='director' AND (display_name LIKE ? OR username LIKE ? OR phone_number LIKE ?) ORDER BY display_name, user_id");
 mysqli_stmt_bind_param($st,'sss',$like,$like,$like);
 mysqli_stmt_execute($st);
 $res=mysqli_stmt_get_result($st);
 while($res && $x=mysqli_fetch_assoc($res))$rows[]=$x;
 mysqli_stmt_close($st);
}else{
 $r=mysqli_query($conn,"SELECT user_id,username,role,display_name,phone_number,is_active,created_at FROM users WHERE role='director' ORDER BY display_name, user_id");
 if($r){while($x=mysqli_fetch_assoc($r))$rows[]=$x;}elseif(!$error){$error='ไม่สามารถโหลดรายชื่อผู้ใช้งานได้: '.mysqli_error($conn);}
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>จัดการข้อมูลผู้อำนวยการ | <?= e(appName()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/role_pages.css">
<style>
.sidebar{background:#CFEFED!important;color:#26484C!important;border-right:1px solid #D6ECEA!important;box-shadow:2px 0 10px rgba(0,0,0,.06)!important}
.sidebar .brand h2,.sidebar .brand small{color:#26484C!important}
.sidebar .menu a{background:rgba(255,255,255,.34)!important;border-color:rgba(255,255,255,.58)!important;color:#26484C!important}
.sidebar .menu a:hover{background:rgba(255,255,255,.55)!important;border-color:#B9DFDC!important}
.sidebar .menu a.active{background:#AEE1DE!important;border-color:#9AD6D2!important;color:#26484C!important}
body.role-page{background:var(--bg)!important}
.page{margin-left:285px;padding:20px 28px 40px;min-height:100vh;background:transparent}
.wrap{max-width:1600px;margin:0 auto}
.msg{padding:12px 15px;border-radius:12px;margin-bottom:16px;font-size:14px}
.ok{background:#eaf8f2;color:#267354}
.err{background:#fff0f0;color:#9a4545}
.search-bar{display:flex;gap:14px;align-items:center;margin-bottom:18px}
.search-input{flex:1;height:52px;border:1px solid #c9dcdd;border-radius:16px;padding:0 16px;background:#fff;font:inherit;color:#2d5053}
.search-input::placeholder{color:#a0b3b6}
.search-btn{height:52px;padding:0 26px;border:1px solid #c9dcdd;border-radius:14px;background:#edf5f5;color:#1f5d67;font:inherit;font-weight:700;cursor:pointer}
.search-btn:hover{background:#e1efee}
.panel{background:#fff;border:1px solid #d7e8e8;border-radius:24px;padding:22px 24px;box-shadow:0 8px 24px rgba(52,103,107,.05)}
.list-panel{margin-top:4px}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.panel-head h2{margin:0;color:#123f45;font-size:24px;font-weight:700}
.add-btn{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 22px;border:0;border-radius:14px;background:#61c3c0;color:#fff;text-decoration:none;font-weight:700;white-space:nowrap;box-shadow:0 5px 14px rgba(88,184,181,.18)}
.add-btn:hover{background:#52b5b2;transform:translateY(-1px)}
.table-shell{overflow:auto;border:1px solid #cfe0de;border-radius:18px;background:#fff}
.tbl{width:100%;min-width:980px;border-collapse:separate;border-spacing:0}
.tbl thead th{background:#d7e7e8;color:#135b63;padding:15px 14px;font-size:15px;font-weight:700;text-align:left;border-bottom:1px solid #cfe0de;white-space:nowrap}
.tbl thead th:first-child{border-top-left-radius:16px}
.tbl thead th:last-child{border-top-right-radius:16px}
.tbl tbody td{padding:13px 14px;border-bottom:1px solid #e8efef;color:#163d42;font-size:14px;vertical-align:middle;background:#fff}
.tbl tbody tr:last-child td{border-bottom:0}
.tbl tbody tr:hover td{background:#fbfefe}
.col-no{width:78px}
.col-name{width:24%}
.col-phone{width:17%}
.col-user{width:16%}
.col-date{width:15%}
.col-action{width:160px;text-align:center}
.name-cell strong{font-size:16px;color:#143e43}
.row-actions{display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:nowrap;white-space:nowrap}
.row-actions .btn{min-width:auto;height:auto;padding:10px 15px;border-radius:10px}
.action-form{display:inline;margin:0}
.empty{text-align:center;color:#6f8588;padding:24px 16px}
.form-layout{display:flex;justify-content:center;padding-top:18px}
.form-panel{width:min(100%,1180px);background:#fff;border:1px solid #d7e8e8;border-radius:24px;padding:28px 32px;box-shadow:0 10px 28px rgba(52,103,107,.06)}
.form-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:16px;border-bottom:1px solid #dfeaea;margin-bottom:20px}
.form-head h2{margin:0;color:#163f44;font-size:24px;font-weight:700}
.back-btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 20px;border:1px solid #c9dcdd;border-radius:12px;background:#edf5f5;color:#1f5d67;text-decoration:none;font-weight:700}
.back-btn:hover{background:#e1efee}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 20px}
.name-grid{grid-column:1 / -1;display:grid;grid-template-columns:180px minmax(0,1fr) minmax(0,1fr);gap:18px 20px}
.name-grid .field{margin:0}
.centered-dash{text-align:center;color:#6f8588}
.field label{display:block;margin-bottom:8px;color:#143e43;font-weight:700}
.req{color:#e65c5c}
.field input{width:100%;height:46px;border:1px solid #cfe0de;border-radius:12px;padding:0 14px;background:#fff;font:inherit;color:#183d42}
.field input::placeholder{color:#a3b2b4}
.field input[readonly]{background:#f9fbfb}
.password-wrap{position:relative}
.password-wrap input{padding-right:48px}
.password-toggle{position:absolute;right:9px;top:50%;transform:translateY(-50%);width:32px;height:32px;border:0;background:transparent;border-radius:10px;color:#567277;display:flex;align-items:center;justify-content:center;cursor:pointer}
.password-toggle:hover{background:#eef6f6}
.password-toggle svg{width:18px;height:18px}
.password-toggle .eye-off{display:none}
.password-toggle.is-visible .eye-on{display:none}
.password-toggle.is-visible .eye-off{display:block}
.form-actions{display:flex;gap:10px;align-items:center;padding-top:20px;margin-top:24px;border-top:1px solid #dfeaea}
.btn{height:42px;padding:0 18px;border-radius:12px;border:1px solid transparent;font:inherit;font-weight:700;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none}
.btn-primary{background:#61c3c0;color:#fff;box-shadow:0 5px 14px rgba(88,184,181,.18)}
.btn-primary:hover{background:#52b5b2}
.btn-light{background:#edf5f5;color:#1f5d67;border-color:#d4e4e4}
.btn-light:hover{background:#e1efee}
.helper-text{font-size:12px;color:#6a8387;margin-top:6px}
@media(max-width:900px){
  .page{margin-left:0;padding:14px}
  .search-bar{flex-wrap:wrap}
  .search-btn{width:100%}
  .panel-head,.form-head{flex-direction:column;align-items:flex-start}
  .add-btn,.back-btn{width:100%}
  .form-panel{padding:22px 18px}
  .form-grid{grid-template-columns:1fr}
  .name-grid{grid-template-columns:1fr}
}

.success-modal-overlay{position:fixed;inset:0;background:rgba(59,89,92,.38);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;z-index:99999;padding:20px}
.success-modal-overlay.show{display:flex}
.success-modal{width:min(520px,92vw);background:#fff;border-radius:30px;padding:38px 34px 36px;text-align:center;box-shadow:0 24px 70px rgba(31,73,77,.25);animation:successPop .22s ease-out}
.success-icon{width:86px;height:86px;border-radius:50%;margin:0 auto 22px;background:#55bdbb;color:#fff;display:flex;align-items:center;justify-content:center;font-size:52px;font-weight:400;line-height:1}
.success-modal h2{margin:0;color:#294e52;font-size:31px;font-weight:800;line-height:1.25}
.success-modal p{margin:16px 0 30px;color:#789093;font-size:17px;line-height:1.6}
.success-modal button{min-width:176px;height:56px;border:0;border-radius:16px;background:#55bdbb;color:#fff;font:inherit;font-size:18px;font-weight:800;cursor:pointer;box-shadow:0 8px 18px rgba(85,189,187,.22)}
.success-modal button:hover{background:#49aaa8;transform:translateY(-1px)}
.success-modal button:focus{outline:3px solid rgba(85,189,187,.25);outline-offset:3px}
@keyframes successPop{from{opacity:0;transform:scale(.94) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}
@media(max-width:600px){.success-modal{padding:32px 22px 28px;border-radius:24px}.success-modal h2{font-size:26px}.success-modal p{font-size:15px}.success-icon{width:76px;height:76px;font-size:46px}}

</style>
</head>
<body class="role-page">
<?php renderSidebar(); renderUserTopbar(); ?>
<main class="page">
  <div class="wrap">
    <?php if($message): ?><div class="msg ok"><?= e($message) ?></div><?php endif; ?>
    <?php if($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>

    <?php if($showForm): ?>
      <div class="form-layout">
        <section class="form-panel" id="director-form">
          <div class="form-head">
            <h2><?= $isEditing ? 'แก้ไขข้อมูลผู้อำนวยการ' : 'เพิ่มข้อมูลผู้อำนวยการ' ?></h2>
            <a class="back-btn" href="executive_users.php">กลับหน้าหลัก</a>
          </div>

          <form class="editor-form" method="post">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= e($edit['user_id'] ?? 0) ?>">
            <input type="hidden" name="edit_mode" value="<?= $isEditing ? '1' : '0' ?>">

            <div class="form-grid">
              <div class="name-grid">
                <div class="field">
                  <label>ชื่อนำหน้า <span class="req">*</span></label>
                  <input name="prefix" value="<?= e($edit['prefix'] ?? ($_POST['prefix'] ?? 'นาย')) ?>" placeholder="ตัวอย่าง นาย" required>
                </div>
                <div class="field">
                  <label>ชื่อ <span class="req">*</span></label>
                  <input name="firstname" value="<?= e($edit['firstname'] ?? ($_POST['firstname'] ?? 'รัศมี')) ?>" placeholder="กรอกชื่อ" required>
                </div>
                <div class="field">
                  <label>นามสกุล <span class="req">*</span></label>
                  <input name="lastname" value="<?= e($edit['lastname'] ?? ($_POST['lastname'] ?? 'แก้วเนตร')) ?>" placeholder="กรอกนามสกุล" required>
                </div>
              </div>

              <div class="field">
                <label><?= $isEditing ? 'แก้ไขชื่อผู้ใช้สำหรับเข้าสู่ระบบ' : 'ชื่อผู้ใช้สำหรับเข้าสู่ระบบ' ?> <span class="req">*</span></label>
                <input name="username" value="<?= e($edit['username'] ?? ($_POST['username'] ?? 'admin')) ?>" placeholder="กรอกชื่อผู้ใช้" required>
              </div>

              <div class="field">
                <label><?= $isEditing ? 'แก้ไขเบอร์โทรศัพท์' : 'เบอร์โทรศัพท์' ?> <span class="req">*</span></label>
                <input name="phone_number" value="<?= e($edit['phone_number'] ?? ($_POST['phone_number'] ?? '')) ?>" placeholder="ตัวอย่าง 0812345678" required>
              </div>

              <div class="field">
                <label><?= $isEditing ? 'แก้ไขรหัสผ่านสำหรับเข้าสู่ระบบ' : 'รหัสผ่านสำหรับเข้าสู่ระบบ' ?> <?= ($edit && (int)($edit['user_id']??0)>0) ? '' : '<span class="req">*</span>' ?></label>
                <div class="password-wrap">
                  <input id="director-password" type="password" name="password" <?= ($edit && (int)($edit['user_id']??0)>0) ? '' : 'required' ?> placeholder="<?= $isEditing ? 'กรอกรหัสผ่านใหม่ หรือเว้นว่างหากไม่เปลี่ยน' : 'อย่างน้อย 4 ตัวอักษร' ?>">
                  <button type="button" class="password-toggle" id="password-toggle" aria-label="แสดงรหัสผ่าน" title="แสดง/ซ่อนรหัสผ่าน">
                    <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.6 10.6 0 0 1 12 4c6.5 0 10 8 10 8a18.2 18.2 0 0 1-2.2 3.3"/><path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a10.2 10.2 0 0 0 4.1-.9"/></svg>
                  </button>
                </div>
                <?php if($isEditing && $edit && (int)($edit['user_id']??0)>0): ?><div class="helper-text">เว้นว่างหากไม่ต้องการแก้ไขรหัสผ่านเดิม</div><?php elseif($isEditing): ?><div class="helper-text">กรอกรหัสผ่านเพื่อบันทึกการแก้ไขและสร้างบัญชีเข้าสู่ระบบ</div><?php endif; ?>
              </div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary"><?= $isEditing ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล' ?></button>
              <a class="btn btn-light" href="executive_users.php"><?= $isEditing ? 'ยกเลิกการแก้ไข' : 'ยกเลิก' ?></a>
            </div>
          </form>
        </section>
      </div>
    <?php else: ?>
      <form class="search-bar" method="get">
        <input class="search-input" type="text" name="search" value="<?= e($search) ?>" placeholder="ค้นหา">
        <button class="search-btn" type="submit">ค้นหา</button>
      </form>

      <section class="panel list-panel">
        <div class="panel-head">
          <h2>ข้อมูลผู้อำนวยการ</h2>
          <a class="add-btn" href="executive_users.php?show_form=1#director-form">เพิ่มข้อมูลผู้อำนวยการ</a>
        </div>

        <div class="table-shell">
          <table class="tbl">
            <thead>
              <tr>
                <th class="col-no">ลำดับ</th>
                <th class="col-name">ชื่อ-นามสกุล</th>
                <th class="col-phone">เบอร์โทรศัพท์</th>
                <th class="col-user">ชื่อผู้ใช้งาน</th>
                <th class="col-date">วันที่เพิ่มข้อมูล</th>
                <th class="col-action">การดำเนินการ</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$rows): ?>
                <?php if($configuredDirectorName !== ''): ?>
                <tr>
                  <td class="col-no">1</td>
                  <td class="name-cell"><strong><?= e($configuredDirectorName) ?></strong></td>
                  <td class="centered-dash">-</td>
                  <td>admin</td>
                  <td class="centered-dash">-</td>
                  <td class="col-action">
                    <div class="row-actions">
                      <a class="btn btn-secondary" href="?edit_config=1#director-form">แก้ไข</a>
                      <form class="action-form" method="post" onsubmit="return confirm('ยืนยันการลบข้อมูลผู้อำนวยการนี้?')">
                        <input type="hidden" name="action" value="clear_director">
                        <button type="submit" class="btn btn-danger">ลบ</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php else: ?>
                <tr><td colspan="6" class="empty">ไม่พบข้อมูลผู้อำนวยการ</td></tr>
                <?php endif; ?>
              <?php else: $n=1; foreach($rows as $u): ?>
                <tr>
                  <td class="col-no"><?= $n++ ?></td>
                  <td class="name-cell"><strong><?= e($u['display_name'] ?: (directorName() ?: 'นาย รัศมี แก้วเนตร')) ?></strong></td>
                  <td class="<?= trim((string)($u['phone_number'] ?? '')) === '' ? 'centered-dash' : '' ?>"><?= e($u['phone_number'] ?: '-') ?></td>
                  <td><?= e($u['username'] ?: 'admin') ?></td>
                  <td class="<?= !empty($u['created_at']) ? '' : 'centered-dash' ?>"><?= !empty($u['created_at']) ? e(date('d/m/Y', strtotime($u['created_at']))) : '-' ?></td>
                  <td class="col-action">
                    <div class="row-actions">
                      <a class="btn btn-secondary" href="?edit=<?= (int)$u['user_id'] ?>#director-form">แก้ไข</a>
                      <form class="action-form" method="post" onsubmit="return confirm('ยืนยันการลบข้อมูลผู้อำนวยการนี้?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <button type="submit" class="btn btn-danger">ลบ</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </div>
</main>

<div class="success-modal-overlay" id="edit-success-modal" role="dialog" aria-modal="true" aria-labelledby="edit-success-title" aria-describedby="edit-success-desc">
  <div class="success-modal">
    <div class="success-icon" aria-hidden="true">✓</div>
    <h2 id="edit-success-title">แก้ไขข้อมูลสำเร็จ</h2>
    <p id="edit-success-desc">ระบบบันทึกข้อมูลผู้อำนวยการเรียบร้อยแล้ว</p>
    <button type="button" id="edit-success-ok">ตกลง</button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const params = new URLSearchParams(window.location.search);
  const successModal = document.getElementById('edit-success-modal');
  const successOk = document.getElementById('edit-success-ok');
  if(params.get('edit_success') === '1' && successModal){
    successModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    if(successOk){
      successOk.focus();
      successOk.addEventListener('click', function(){
        successModal.classList.remove('show');
        document.body.style.overflow = '';
        window.location.href = 'executive_users.php';
      });
    }
  }

  const input = document.getElementById('director-password');
  const button = document.getElementById('password-toggle');
  if(!input || !button) return;
  button.addEventListener('click', function(){
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    button.classList.toggle('is-visible', !visible);
    button.setAttribute('aria-label', visible ? 'แสดงรหัสผ่าน' : 'ซ่อนรหัสผ่าน');
  });
});
</script>
</body>
</html>
