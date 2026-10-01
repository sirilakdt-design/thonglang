<?php
require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('doctor');
mysqli_set_charset($conn, 'utf8mb4');

$patients = [];
$adlTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'adl_assessment'");
$hasAdlTable = $adlTableCheck && mysqli_num_rows($adlTableCheck) > 0;
if ($adlTableCheck) mysqli_free_result($adlTableCheck);
$patientSql = $hasAdlTable
    ? "SELECT p.Patient_id, p.Fullname, p.Age, EXISTS(SELECT 1 FROM adl_assessment a WHERE a.patient_id=p.Patient_id LIMIT 1) AS has_assessment FROM patient p ORDER BY p.Fullname ASC"
    : "SELECT Patient_id, Fullname, Age, 0 AS has_assessment FROM patient ORDER BY Fullname ASC";
$res = mysqli_query($conn, $patientSql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $patients[] = $row;
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>การประเมิน ADL | <?= e(appName()) ?></title>
<?php renderPastelTheme(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
.adl-entry-wrap{max-width:860px;margin:0 auto;padding:18px 0 34px}
.adl-entry-card{border:1px solid #d8ebe8;border-radius:26px;background:linear-gradient(180deg,#ffffff 0%,#f8fcfb 100%);box-shadow:0 14px 34px rgba(36,108,115,.07);padding:24px}
.adl-entry-head{text-align:center;margin-bottom:20px}.adl-entry-head h1{margin:0;color:#1d4f53;font-size:28px;line-height:1.2}
.adl-patient-select{max-width:580px;margin:0 auto 20px;padding:16px 18px;border:1px solid #d9ebe8;border-radius:20px;background:#f8fcfc}
.adl-patient-select label{display:block;margin-bottom:8px;color:#254f53;font-size:14px;font-weight:900}
.adl-search-box{position:relative}
.adl-search-input{width:100%;min-height:48px;padding:0 48px 0 14px;border:1px solid #cfe3e0;border-radius:14px;background:#fff;color:#214e50;font:inherit;font-weight:700;outline:none}
.adl-search-input:focus{border-color:#59bfc0;box-shadow:0 0 0 3px rgba(89,191,192,.12)}
.adl-search-toggle{position:absolute;top:50%;right:10px;transform:translateY(-50%);width:32px;height:32px;border:none;border-radius:10px;background:#f1f9f8;color:#2b686a;font-size:15px;cursor:pointer}
.adl-search-clear{position:absolute;top:50%;right:46px;transform:translateY(-50%);width:28px;height:28px;border:none;border-radius:999px;background:transparent;color:#7b918d;font-size:17px;cursor:pointer;display:none}
.adl-search-clear.is-visible{display:inline-flex;align-items:center;justify-content:center}
.adl-search-options{position:absolute;left:0;right:0;top:calc(100% + 8px);padding:8px;border:1px solid #d9ebe8;border-radius:16px;background:#fff;box-shadow:0 14px 28px rgba(36,108,115,.10);display:none;max-height:260px;overflow:auto;z-index:50}
.adl-search-options.is-open{display:block}
.adl-search-option{display:block;width:100%;padding:11px 12px;border:none;border-radius:12px;background:transparent;text-align:left;color:#214e50;font:inherit;font-weight:700;cursor:pointer}
.adl-search-option:hover,.adl-search-option.is-active{background:#eef8f7}
.adl-search-empty{padding:11px 12px;border-radius:12px;color:#7a908d;font-size:12px;background:#fbfefe;display:none}
.adl-search-empty.is-visible{display:block}
.adl-native-select{display:none}
.adl-select-note{margin-top:9px;color:#7a908d;font-size:12px}
.adl-entry-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,260px));justify-content:center;gap:14px;max-width:560px;margin:0 auto}
.adl-entry-link{display:flex;align-items:center;justify-content:center;min-height:74px;padding:12px 16px;border-radius:16px;border:1px solid #d9ebe8;background:#fff;text-decoration:none;transition:.18s ease;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.adl-entry-link:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(36,108,115,.10);border-color:#bfe2dd}
.adl-entry-link.primary{background:linear-gradient(135deg,#63c4c6 0%,#56b7ba 100%);border-color:#56b7ba}
.adl-entry-link.history{background:linear-gradient(135deg,#f4f9ff 0%,#fbfdff 100%)}
.adl-entry-label{font-size:18px;font-weight:900;color:#fff;text-align:center;line-height:1.3}.adl-entry-link.history .adl-entry-label{color:#21585c}
.adl-entry-link.is-disabled{opacity:.46;pointer-events:none;filter:grayscale(.15)}
@media(max-width:760px){.adl-entry-wrap{padding:14px 0 28px}.adl-entry-card{padding:18px;border-radius:22px}.adl-entry-grid{grid-template-columns:1fr;max-width:100%}.adl-entry-link{min-height:68px;padding:12px 16px}.adl-entry-label{font-size:17px}.adl-entry-head h1{font-size:25px}.adl-patient-select{padding:16px}}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<main class="main">
<?php renderUserTopbar(); ?>

<div class="adl-entry-wrap">
    <section class="adl-entry-card">
        <div class="adl-entry-head">
            <h1>การประเมิน ADL</h1>
        </div>

        <div class="adl-patient-select">
            <label for="adlPatientSearch">เลือกผู้สูงอายุ</label>
            <div class="adl-search-box" id="adlPatientBox">
                <input type="text" id="adlPatientSearch" class="adl-search-input" placeholder="พิมพ์เพื่อค้นหาและเลือกผู้สูงอายุ..." autocomplete="off">
                <button type="button" class="adl-search-clear" id="adlPatientClear" aria-label="ล้างคำค้น">×</button>
                
                <div class="adl-search-options" id="adlPatientOptions">
                    <?php foreach ($patients as $patient): $patientLabel = e($patient['Fullname']) . (!empty($patient['Age']) ? ' (' . (int)$patient['Age'] . ' ปี)' : ''); ?>
                        <button type="button" class="adl-search-option" data-value="<?= (int)$patient['Patient_id'] ?>" data-label="<?= $patientLabel ?>" data-search="<?= mb_strtolower(trim((string)$patient['Fullname']) . ' ' . trim((string)($patient['Age'] ?? '')), 'UTF-8') ?>" data-has-result="<?= !empty($patient['has_assessment']) ? '1' : '0' ?>"><?= $patientLabel ?></button>
                    <?php endforeach; ?>
                    <div class="adl-search-empty" id="adlPatientEmpty">ไม่พบรายชื่อผู้สูงอายุ</div>
                </div>
                <select id="adlPatientSelect" class="adl-native-select">
                    <option value="">-- เลือกผู้สูงอายุ --</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?= (int)$patient['Patient_id'] ?>" data-has-result="<?= !empty($patient['has_assessment']) ? '1' : '0' ?>"><?= e($patient['Fullname']) ?><?= !empty($patient['Age']) ? ' (' . (int)$patient['Age'] . ' ปี)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="adl-entry-grid">
            <a id="adlAssessmentLink" class="adl-entry-link primary is-disabled" href="adl_assessment.php">
                <div class="adl-entry-label">ทำการประเมิน</div>
            </a>

            <a id="adlResultLink" class="adl-entry-link history is-disabled" href="adl_history.php" aria-disabled="true" tabindex="-1">
                <div class="adl-entry-label">ผลการประเมิน</div>
            </a>
        </div>
    </section>
</div>
<script>
(function(){
    const select = document.getElementById('adlPatientSelect');
    const searchInput = document.getElementById('adlPatientSearch');
    const clearBtn = document.getElementById('adlPatientClear');
    const toggleBtn = document.getElementById('adlPatientToggle');
    const optionsWrap = document.getElementById('adlPatientOptions');
    const emptyState = document.getElementById('adlPatientEmpty');
    const optionNodes = Array.from(document.querySelectorAll('.adl-search-option'));
    const assessmentLink = document.getElementById('adlAssessmentLink');
    const resultLink = document.getElementById('adlResultLink');

    function updateLinks(){
        const patientId = select ? select.value : '';
        const hasPatient = patientId !== '';
        if (assessmentLink) {
            assessmentLink.href = hasPatient ? ('adl_assessment.php?patient_id=' + encodeURIComponent(patientId)) : 'adl_assessment.php';
            assessmentLink.classList.toggle('is-disabled', !hasPatient);
        }
        if (resultLink) {
            const selectedOption = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            const hasResult = !!(hasPatient && selectedOption && selectedOption.dataset.hasResult === '1');
            resultLink.href = hasResult ? ('adl_history.php?patient_id=' + encodeURIComponent(patientId)) : 'adl_history.php';
            resultLink.classList.toggle('is-disabled', !hasResult);
            resultLink.setAttribute('aria-disabled', hasResult ? 'false' : 'true');
            resultLink.tabIndex = hasResult ? 0 : -1;
        }
    }

    function openOptions(){
        if (optionsWrap) optionsWrap.classList.add('is-open');
    }

    function closeOptions(){
        if (optionsWrap) optionsWrap.classList.remove('is-open');
        optionNodes.forEach(node => node.classList.remove('is-active'));
    }

    function syncClearButton(){
        if (clearBtn) clearBtn.classList.toggle('is-visible', !!(searchInput && searchInput.value.trim() !== ''));
    }

    function filterOptions(){
        const keyword = (searchInput ? searchInput.value : '').trim().toLocaleLowerCase('th-TH');
        let shown = 0;
        optionNodes.forEach((node, index) => {
            const hay = ((node.dataset.search || '') + ' ' + (node.dataset.label || '')).toLocaleLowerCase('th-TH');
            const matched = keyword === '' || hay.indexOf(keyword) !== -1;
            node.style.display = matched ? 'block' : 'none';
            node.classList.toggle('is-active', matched && shown === 0);
            if (matched) shown += 1;
        });
        if (emptyState) emptyState.classList.toggle('is-visible', shown === 0);
        syncClearButton();
    }

    function selectOption(node){
        if (!node || !select || !searchInput) return;
        select.value = node.dataset.value || '';
        searchInput.value = node.dataset.label || '';
        syncClearButton();
        closeOptions();
        updateLinks();
    }

    if (searchInput) {
        searchInput.addEventListener('focus', function(){ openOptions(); filterOptions(); });
        searchInput.addEventListener('click', function(){ openOptions(); filterOptions(); });
        searchInput.addEventListener('input', function(){
            if (select) select.value = '';
            updateLinks();
            openOptions();
            filterOptions();
        });
        searchInput.addEventListener('keydown', function(event){
            const visible = optionNodes.filter(node => node.style.display !== 'none');
            if (!visible.length) return;
            let currentIndex = visible.findIndex(node => node.classList.contains('is-active'));
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                currentIndex = currentIndex < visible.length - 1 ? currentIndex + 1 : 0;
                visible.forEach(node => node.classList.remove('is-active'));
                visible[currentIndex].classList.add('is-active');
                visible[currentIndex].scrollIntoView({block:'nearest'});
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                currentIndex = currentIndex > 0 ? currentIndex - 1 : visible.length - 1;
                visible.forEach(node => node.classList.remove('is-active'));
                visible[currentIndex].classList.add('is-active');
                visible[currentIndex].scrollIntoView({block:'nearest'});
            } else if (event.key === 'Enter') {
                const target = currentIndex >= 0 ? visible[currentIndex] : visible[0];
                if (target) {
                    event.preventDefault();
                    selectOption(target);
                }
            } else if (event.key === 'Escape') {
                closeOptions();
            }
        });
    }

    optionNodes.forEach(node => {
        node.addEventListener('click', function(){ selectOption(node); });
    });

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(){
            if (optionsWrap && optionsWrap.classList.contains('is-open')) {
                closeOptions();
            } else {
                openOptions();
                filterOptions();
                if (searchInput) searchInput.focus();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(){
            if (searchInput) searchInput.value = '';
            if (select) select.value = '';
            syncClearButton();
            updateLinks();
            openOptions();
            filterOptions();
            if (searchInput) searchInput.focus();
        });
    }

    document.addEventListener('click', function(event){
        const within = event.target.closest('#adlPatientBox');
        if (!within) closeOptions();
    });

    if (select && select.value) {
        const current = optionNodes.find(node => node.dataset.value === select.value);
        if (current && searchInput) searchInput.value = current.dataset.label || '';
    }
    syncClearButton();
    updateLinks();
})();
</script>
</main>
</body>
</html>
