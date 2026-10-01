<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$username = trim((string)($_SESSION['username'] ?? ''));
$fullname = trim((string)($_SESSION['fullname'] ?? ''));
$role     = trim((string)($_SESSION['role'] ?? ''));
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userAvatarPath = trim((string)($_SESSION['user_photo'] ?? $_SESSION['photo'] ?? $_SESSION['profile_photo'] ?? $_SESSION['avatar'] ?? $_SESSION['avatar_path'] ?? ''));
$userAvatarUrl = '';

if ($userId > 0) {
    require_once __DIR__ . '/connect.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $avatarColumn = '';
        $avatarCandidates = ['photo', 'profile_photo', 'avatar', 'avatar_path', 'image', 'image_path', 'user_photo'];
        $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM users");
        if ($columnResult) {
            $availableColumns = [];
            while ($columnRow = mysqli_fetch_assoc($columnResult)) {
                $availableColumns[] = strtolower((string)($columnRow['Field'] ?? ''));
            }
            mysqli_free_result($columnResult);
            foreach ($avatarCandidates as $candidate) {
                if (in_array(strtolower($candidate), $availableColumns, true)) {
                    $avatarColumn = $candidate;
                    break;
                }
            }
        }

        $selectFields = 'display_name, username, role';
        if ($avatarColumn !== '') {
            $selectFields .= ', ' . $avatarColumn . ' AS user_avatar';
        }

        $nameStmt = mysqli_prepare($conn, "SELECT {$selectFields} FROM users WHERE user_id = ? LIMIT 1");
        if ($nameStmt) {
            mysqli_stmt_bind_param($nameStmt, 'i', $userId);
            mysqli_stmt_execute($nameStmt);
            $nameResult = mysqli_stmt_get_result($nameStmt);
            $currentUser = mysqli_fetch_assoc($nameResult);
            mysqli_stmt_close($nameStmt);
            if ($currentUser) {
                $databaseName = trim((string)($currentUser['display_name'] ?? ''));
                if ($databaseName !== '') {
                    $fullname = $databaseName;
                    $_SESSION['fullname'] = $databaseName;
                }
                if (!empty($currentUser['username'])) {
                    $username = trim((string)$currentUser['username']);
                    $_SESSION['username'] = $username;
                }
                if (!empty($currentUser['role'])) {
                    $role = trim((string)$currentUser['role']);
                    $_SESSION['role'] = $role;
                }
                if ($avatarColumn !== '') {
                    $avatarValue = trim((string)($currentUser['user_avatar'] ?? ''));
                    if ($avatarValue !== '') {
                        $userAvatarPath = $avatarValue;
                        $_SESSION['user_photo'] = $avatarValue;
                    }
                }
            }
        }
    }
}

if ($fullname === '') {
    $fullname = $username !== '' ? $username : 'ผู้ใช้งาน';
}

$roleText = 'ผู้ใช้งาน';
if ($role === 'admin') $roleText = 'ผู้ดูแลระบบ';
elseif ($role === 'caregiver') $roleText = 'ผู้ดูแลผู้สูงอายุ';
elseif ($role === 'doctor') $roleText = 'หมอ';

$loginIdentity = $username !== '' ? strtoupper($username) : '-';
$primaryText = $fullname;
$secondaryText = '';
if ($userAvatarPath !== '') {
    if (function_exists('thonglangUploadedImageUrl')) {
        $userAvatarUrl = thonglangUploadedImageUrl($userAvatarPath);
    }
    if ($userAvatarUrl === '') {
        $userAvatarUrl = $userAvatarPath;
    }
}

if ($primaryText === '' || trim($primaryText) === trim($roleText)) {
    $primaryText = $roleText;
    $secondaryText = '';
}
?>
<div class="user-topbar">
    <div class="user-profile-menu">
        <button type="button" class="user-profile-button" onclick="toggleUserMenu(event)">
            <div class="user-card-shell">
                <div class="user-avatar-circle">
                    <?php if ($userAvatarUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($userAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปผู้ใช้งาน" class="user-avatar-photo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="user-avatar-icon"><path d="M12 12c2.76 0 5-2.46 5-5.5S14.76 1 12 1 7 3.46 7 6.5 9.24 12 12 12Zm0 2c-4.42 0-8 2.91-8 6.5 0 .83.67 1.5 1.5 1.5h13c.83 0 1.5-.67 1.5-1.5C20 16.91 16.42 14 12 14Z" fill="currentColor"/></svg>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                                        <div class="user-primary-text"><?= htmlspecialchars($primaryText, ENT_QUOTES, 'UTF-8') ?></div>
                    
                </div>
                
            </div>
        </button>

        <div class="user-dropdown" id="userDropdown">
            <div class="dropdown-header-box">
                <div class="dropdown-profile-head">
                    <div class="user-avatar-circle dropdown-avatar">
                        <?php if ($userAvatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($userAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="รูปผู้ใช้งาน" class="user-avatar-photo">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="user-avatar-icon"><path d="M12 12c2.76 0 5-2.46 5-5.5S14.76 1 12 1 7 3.46 7 6.5 9.24 12 12 12Zm0 2c-4.42 0-8 2.91-8 6.5 0 .83.67 1.5 1.5 1.5h13c.83 0 1.5-.67 1.5-1.5C20 16.91 16.42 14 12 14Z" fill="currentColor"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-profile-text">
                                                <div class="dropdown-user-name"><?= htmlspecialchars($primaryText, ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                </div>
            </div>

            <div class="dropdown-identity-list">
                <div class="identity-row">
                    <span>ชื่อผู้ใช้งาน</span>
                    <strong><?= htmlspecialchars($loginIdentity, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="identity-row">
                    <span>ชื่อที่แสดง</span>
                    <strong><?= htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            </div>

            <div class="dropdown-divider"></div>
            <a href="logout.php" class="logout-link">ออกจากระบบ</a>
        </div>
    </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
html,body,body *,button,input,select,textarea,table,th,td,label,a,span,div,p,h1,h2,h3,h4,h5,h6,small,strong,b{font-family:"Noto Sans Thai",sans-serif!important}

.user-topbar{width:100%;min-height:54px;display:flex;align-items:center;justify-content:flex-end;padding:8px 4px 6px;background:transparent}
.user-profile-menu{position:relative}
.user-profile-button{min-width:190px;display:block;padding:0;background:transparent!important;border:0!important;box-shadow:none!important;cursor:pointer;font-family:"Noto Sans Thai",sans-serif;color:#183B38!important;text-align:left}
.user-card-shell{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:15px;border:1px solid #D8ECEA;background:linear-gradient(135deg,rgba(255,255,255,.98) 0%,rgba(239,250,247,.96) 100%);box-shadow:0 8px 22px rgba(50,118,121,.09);transition:.18s ease}
.user-profile-button:hover .user-card-shell{transform:translateY(-1px);border-color:#B7DFDB;box-shadow:0 11px 26px rgba(50,118,121,.13)}
.user-avatar-circle{width:34px;height:34px;flex:0 0 34px;border-radius:11px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:linear-gradient(135deg,#7DD3CF 0%,#58BFC0 100%);color:#fff!important;box-shadow:0 6px 14px rgba(88,191,192,.24)}.user-avatar-icon{width:17px;height:17px;display:block;color:#fff}.user-avatar-photo{width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit}
.user-info{min-width:0;flex:1;text-align:left}
.user-primary-text{margin-top:0;font-size:15px;font-weight:800;color:#1F5660!important;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-card-chevron{width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:#F2FBFA;border:1px solid #D7ECE9;position:relative}
.user-card-chevron:before{content:'';position:absolute;top:50%;left:50%;width:8px;height:8px;border-right:2px solid #5EAEB0;border-bottom:2px solid #5EAEB0;transform:translate(-60%,-60%) rotate(45deg)}
.user-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;width:250px;padding:10px;background:#fff!important;border:1px solid #D5E9E6!important;border-radius:15px!important;box-shadow:0 16px 32px rgba(12,86,81,.14)!important;z-index:2000}
.user-dropdown.show{display:block}
.dropdown-header-box{border:1px solid #D8ECEA;border-radius:13px;padding:10px;background:linear-gradient(135deg,#EDF9F7 0%,#F5FBFF 100%)}
.dropdown-profile-head{display:flex;align-items:center;gap:9px}
.dropdown-avatar{width:34px;height:34px;flex-basis:34px;border-radius:11px}.dropdown-avatar .user-avatar-icon{width:17px;height:17px}.dropdown-avatar .user-avatar-photo{border-radius:inherit}
.dropdown-profile-text{min-width:0;flex:1}
.dropdown-title{font-size:11px;font-weight:700;color:#7D9296!important;margin-bottom:4px}
.dropdown-user-name{font-size:13px;font-weight:800;color:#183B38!important;line-height:1.25;word-break:break-word}
.dropdown-role-badge{display:inline-flex;align-items:center;padding:0;border:0;border-radius:0;background:transparent;color:#087F78!important;font-size:11px;font-weight:700;margin-top:5px}
.dropdown-identity-list{display:grid;gap:8px;margin-top:14px}
.identity-row{display:flex;align-items:center;justify-content:space-between;gap:9px;padding:8px 10px;border:1px solid #D5E9E6;border-radius:10px;background:#fff}
.identity-row span{font-size:11px;color:#6B7F7D!important;font-weight:700}
.identity-row strong{font-size:12px;color:#183B38!important;text-align:right;line-height:1.4;word-break:break-word}
.dropdown-divider{height:1px;background:#E2EEEC;margin:10px 0}
.user-dropdown a{display:block;padding:12px;border-radius:10px;color:#A13E3E!important;text-decoration:none;font-size:13px;font-weight:700;text-align:center;border:1px solid #F0CCCC;background:#FFF1F1}
.user-dropdown a:hover{background:#FFE8E8!important}
.logout-link{color:#A13E3E!important}
@media(max-width:700px){.user-topbar{justify-content:stretch;padding-top:8px}.user-profile-menu{width:100%}.user-profile-button{width:100%;min-width:0}.user-card-shell{padding:7px 9px;border-radius:14px}.user-primary-text{font-size:15px}.user-dropdown{width:100%}}
.user-card-chevron{display:none!important}
</style>
<script>
function toggleUserMenu(event){
    event.stopPropagation();
    var menu=document.getElementById('userDropdown');
    if(menu){menu.classList.toggle('show')}
}
document.addEventListener('click',function(){
    var menu=document.getElementById('userDropdown');
    if(menu){menu.classList.remove('show')}
});
</script>


<!-- UNIFIED CRUD UI -->
<style id="unified-crud-ui-style">
/* ปุ่ม เพิ่ม / แก้ไข / ลบ และ popup มาตรฐานสำหรับทุกหน้า */
.unified-crud-add{
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-height:44px!important;padding:0 22px!important;border:0!important;border-radius:14px!important;
    background:#61C3C0!important;color:#fff!important;font-weight:700!important;text-decoration:none!important;
    box-shadow:0 5px 14px rgba(88,184,181,.18)!important;transition:.15s ease!important;
}
.unified-crud-add:hover{background:#52B5B2!important;transform:translateY(-1px)!important}
.unified-crud-save{
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-height:44px!important;padding:0 20px!important;border:0!important;border-radius:13px!important;
    background:#59B9BD!important;color:#fff!important;font-weight:700!important;text-decoration:none!important;
    box-shadow:0 5px 14px rgba(88,184,181,.15)!important;
}
.unified-crud-save:hover{background:#48A9AD!important}
.unified-crud-cancel{
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-height:44px!important;padding:0 18px!important;border:1px solid #D4E4E4!important;border-radius:13px!important;
    background:#EDF5F5!important;color:#245C61!important;font-weight:700!important;text-decoration:none!important;
}
.unified-crud-edit,.unified-crud-delete{
    width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;
    padding:0!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;
    text-decoration:none!important;cursor:pointer!important;transition:.15s ease!important;vertical-align:middle!important;
    font-size:0!important;line-height:1!important;
}
.unified-crud-edit{
    border:2px solid #262626!important;border-radius:14px!important;background:#fff!important;color:#111!important;
    box-shadow:none!important;
}
.unified-crud-edit:hover{background:#F6F6F6!important;transform:translateY(-1px)!important}
.unified-crud-delete{
    border:0!important;border-radius:999px!important;background:#FF4A41!important;color:#fff!important;
    box-shadow:0 6px 14px rgba(255,74,65,.24)!important;
}
.unified-crud-delete:hover{background:#F13D35!important;transform:translateY(-1px)!important}
.unified-crud-edit svg,.unified-crud-delete svg{width:21px!important;height:21px!important;display:block!important;pointer-events:none!important}
.unified-crud-action-row{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;flex-wrap:nowrap!important}

.unified-crud-overlay{
    position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;
    padding:24px;background:rgba(44,70,73,.30);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);
}
.unified-crud-overlay.show{display:flex}
.unified-crud-modal{
    width:min(92vw,525px);background:#fff;border:1px solid #E0ECEB;border-radius:30px;
    padding:36px 34px 30px;text-align:center;box-shadow:0 24px 70px rgba(31,75,78,.20);
    animation:unifiedCrudPop .18s ease-out;
}
@keyframes unifiedCrudPop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
.unified-crud-modal-icon{
    width:86px;height:86px;margin:0 auto 22px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    background:#55BDBA;color:#fff;font-size:54px;font-weight:500;line-height:1;
}
.unified-crud-modal-icon.delete{background:#FF4A41}
.unified-crud-modal-icon svg{width:40px;height:40px}
.unified-crud-modal h2{margin:0;color:#294D50;font-size:30px;font-weight:800;line-height:1.25}
.unified-crud-modal p{margin:14px auto 0;color:#789092;font-size:15px;line-height:1.65;max-width:420px}
.unified-crud-modal-actions{display:flex;align-items:center;justify-content:center;gap:9px;margin-top:28px}
.unified-crud-modal-btn{
    min-width:175px;height:55px;border:0;border-radius:14px;font:inherit;font-size:15px;font-weight:800;cursor:pointer;
}
.unified-crud-modal-btn.ok{background:#55BDBA;color:#fff}
.unified-crud-modal-btn.ok:hover{background:#48ADAA}
.unified-crud-modal-btn.cancel{background:#EDF5F5;color:#315D61;border:1px solid #D5E5E4}
.unified-crud-modal-btn.danger{background:#FF4A41;color:#fff}
.unified-crud-modal-btn.danger:hover{background:#F13D35}
@media(max-width:700px){
 .unified-crud-modal{padding:30px 20px 24px;border-radius:24px}
 .unified-crud-modal h2{font-size:25px}.unified-crud-modal p{font-size:15px}
 .unified-crud-modal-actions{flex-direction:column}.unified-crud-modal-btn{width:100%;min-width:0}
}
</style>
<script id="unified-crud-ui-script">
(function(){
  function norm(v){return (v||'').replace(/\s+/g,' ').trim();}
  function metaText(el){return norm((el.getAttribute('aria-label')||'')+' '+(el.getAttribute('title')||'')+' '+(el.textContent||''));}
  function pencilSvg(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';}
  function trashSvg(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';}

  function makeOverlay(){
    if(document.getElementById('unified-crud-overlay')) return document.getElementById('unified-crud-overlay');
    var overlay=document.createElement('div');
    overlay.className='unified-crud-overlay'; overlay.id='unified-crud-overlay';
    overlay.setAttribute('role','dialog'); overlay.setAttribute('aria-modal','true');
    overlay.innerHTML='<div class="unified-crud-modal"><div class="unified-crud-modal-icon" id="unified-crud-icon">✓</div><h2 id="unified-crud-title">บันทึกสำเร็จแล้ว</h2><p id="unified-crud-desc"></p><div class="unified-crud-modal-actions" id="unified-crud-actions"><button type="button" class="unified-crud-modal-btn ok" id="unified-crud-ok">ตกลง</button></div></div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  function showSuccess(message){
    var overlay=makeOverlay(), icon=document.getElementById('unified-crud-icon'), title=document.getElementById('unified-crud-title'), desc=document.getElementById('unified-crud-desc'), actions=document.getElementById('unified-crud-actions');
    var m=norm(message); icon.className='unified-crud-modal-icon'; icon.textContent='✓';
    if(m.indexOf('แก้ไข')!==-1) title.textContent='แก้ไขข้อมูลสำเร็จ';
    else if(m.indexOf('ลบ')!==-1) title.textContent='ลบข้อมูลสำเร็จ';
    else if(m.indexOf('เพิ่ม')!==-1) title.textContent='เพิ่มข้อมูลสำเร็จ';
    else title.textContent='บันทึกสำเร็จแล้ว';
    desc.textContent=m || 'ระบบบันทึกข้อมูลเรียบร้อยแล้ว';
    actions.innerHTML='<button type="button" class="unified-crud-modal-btn ok" id="unified-crud-ok">ตกลง</button>';
    overlay.classList.add('show'); document.body.style.overflow='hidden';
    var ok=document.getElementById('unified-crud-ok');
    ok.focus();
    ok.onclick=function(){
      overlay.classList.remove('show'); document.body.style.overflow='';
      try{var u=new URL(window.location.href); ['success','saved','deleted','updated','added'].forEach(function(k){u.searchParams.delete(k)}); window.history.replaceState({},'',u.pathname+(u.searchParams.toString()?'?'+u.searchParams.toString():'')+u.hash);}catch(e){}
    };
  }

  function showDeleteConfirm(message,onConfirm){
    var overlay=makeOverlay(), icon=document.getElementById('unified-crud-icon'), title=document.getElementById('unified-crud-title'), desc=document.getElementById('unified-crud-desc'), actions=document.getElementById('unified-crud-actions');
    icon.className='unified-crud-modal-icon delete'; icon.innerHTML=trashSvg();
    title.textContent='ยืนยันการลบข้อมูล';
    desc.textContent=norm(message)||'ต้องการลบข้อมูลนี้หรือไม่?';
    actions.innerHTML='<button type="button" class="unified-crud-modal-btn cancel" id="unified-crud-cancel">ยกเลิก</button><button type="button" class="unified-crud-modal-btn danger" id="unified-crud-confirm">ลบข้อมูล</button>';
    overlay.classList.add('show'); document.body.style.overflow='hidden';
    var cancel=document.getElementById('unified-crud-cancel'), confirmBtn=document.getElementById('unified-crud-confirm');
    cancel.onclick=function(){overlay.classList.remove('show');document.body.style.overflow='';};
    confirmBtn.onclick=function(){overlay.classList.remove('show');document.body.style.overflow='';onConfirm();};
    cancel.focus();
  }

  function extractConfirmMessage(el){
    var raw=(el.getAttribute('onsubmit')||el.getAttribute('onclick')||'');
    var m=raw.match(/confirm\(\s*['\"]([^'\"]+)['\"]\s*\)/i);
    return m?m[1]:'ต้องการลบข้อมูลนี้หรือไม่?';
  }

  function styleCrudControls(){
    var controls=document.querySelectorAll('a,button,input[type="submit"]');
    controls.forEach(function(el){
      if(el.closest('.unified-crud-modal')) return;
      var t=metaText(el), insideTable=!!el.closest('table,td,.table-actions,.row-actions,.actions-cell');
      var isDelete=(/^ลบ($|\s|ข้อมูล)/.test(t)||t.indexOf('ลบข้อมูล')!==-1||el.classList.contains('btn-danger')||el.classList.contains('btn-red')||el.classList.contains('delete'));
      var isEdit=(/^แก้ไข($|\s|ข้อมูล)/.test(t)||t.indexOf('แก้ไขข้อมูล')!==-1||el.classList.contains('edit'));
      if(insideTable && isDelete){
        if(!el.getAttribute('aria-label')) el.setAttribute('aria-label','ลบข้อมูล');
        if(!el.getAttribute('title')) el.setAttribute('title','ลบข้อมูล');
        el.classList.add('unified-crud-delete'); el.innerHTML=trashSvg();
        if(el.parentElement) el.parentElement.classList.add('unified-crud-action-row');
        return;
      }
      if(insideTable && isEdit){
        if(!el.getAttribute('aria-label')) el.setAttribute('aria-label','แก้ไขข้อมูล');
        if(!el.getAttribute('title')) el.setAttribute('title','แก้ไขข้อมูล');
        el.classList.add('unified-crud-edit'); el.innerHTML=pencilSvg();
        if(el.parentElement) el.parentElement.classList.add('unified-crud-action-row');
        return;
      }
      if(!insideTable && (t.indexOf('เพิ่มข้อมูล')!==-1 || /^เพิ่ม$/.test(t))) el.classList.add('unified-crud-add');
      if(!insideTable && (t.indexOf('บันทึกข้อมูล')!==-1 || t.indexOf('บันทึกการแก้ไข')!==-1)) el.classList.add('unified-crud-save');
      if(!insideTable && (t==='ยกเลิก' || t.indexOf('ยกเลิกการแก้ไข')!==-1 || t==='ย้อนกลับ')) el.classList.add('unified-crud-cancel');
    });
  }

  document.addEventListener('DOMContentLoaded',function(){
    styleCrudControls();

    /* เปลี่ยนแถบข้อความสำเร็จของทุกหน้าเป็น popup แบบเดียวกัน */
    var successNodes=Array.prototype.slice.call(document.querySelectorAll('.alert-ok,.alert-success,.msg.ok,.alert.alert-ok'));
    var successNode=successNodes.find(function(n){var t=norm(n.textContent);return t && !/ไม่สำเร็จ|ผิดพลาด|กรุณา/.test(t);});
    if(successNode && !document.querySelector('#edit-success-modal.show')){
      var successText=norm(successNode.textContent); successNode.style.display='none';
      window.setTimeout(function(){showSuccess(successText);},80);
    }

    /* ยืนยันการลบแบบ popup แทน confirm ของเบราว์เซอร์ */
    document.addEventListener('submit',function(e){
      var form=e.target; if(!(form instanceof HTMLFormElement)) return;
      var actionInput=form.querySelector('input[name="action"]');
      var submitter=e.submitter || form.querySelector('button[type="submit"],input[type="submit"]');
      var txt=submitter?metaText(submitter):'';
      var isDelete=(actionInput && /delete|remove|clear_director/i.test(actionInput.value||'')) || /ลบ/.test(txt) || /confirm\(/i.test(form.getAttribute('onsubmit')||'');
      if(!isDelete || form.dataset.unifiedCrudConfirmed==='1') return;
      e.preventDefault(); e.stopImmediatePropagation();
      var msg=extractConfirmMessage(form);
      showDeleteConfirm(msg,function(){form.dataset.unifiedCrudConfirmed='1';HTMLFormElement.prototype.submit.call(form);});
    },true);

    document.addEventListener('click',function(e){
      var a=e.target.closest('a'); if(!a) return;
      var txt=metaText(a), href=a.getAttribute('href')||'', inline=a.getAttribute('onclick')||'';
      var isDelete=/ลบ/.test(txt) && (/[?&](delete|remove)=/i.test(href) || /confirm\(/i.test(inline));
      if(!isDelete) return;
      e.preventDefault(); e.stopImmediatePropagation();
      var msg=extractConfirmMessage(a);
      showDeleteConfirm(msg,function(){window.location.href=a.href;});
    },true);
  });
})();
</script>
