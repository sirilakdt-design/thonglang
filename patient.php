<?php

require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);

requireRole('admin', 'doctor');

$message = "";
$message_type = "";
$successAction = (string)($_GET['success'] ?? '');
if ($successAction === 'add') { $message = 'เพิ่มข้อมูลผู้สูงอายุเรียบร้อยแล้ว'; $message_type = 'success'; }
elseif ($successAction === 'edit') { $message = 'แก้ไขข้อมูลผู้สูงอายุเรียบร้อยแล้ว'; $message_type = 'success'; }
elseif ($successAction === 'delete') { $message = 'ลบข้อมูลผู้สูงอายุเรียบร้อยแล้ว'; $message_type = 'success'; }

function buildAddress($house_no, $moo, $subdistrict, $district, $province, $zipcode)
{
    $parts = [];
    if ($house_no !== "") $parts[] = "บ้านเลขที่ " . $house_no;
    if ($moo !== "") $parts[] = "หมู่ที่ " . $moo;
    if ($subdistrict !== "") $parts[] = "ตำบล " . $subdistrict;
    if ($district !== "") $parts[] = "อำเภอ " . $district;
    if ($province !== "") $parts[] = "จังหวัด " . $province;
    if ($zipcode !== "") $parts[] = "รหัสไปรษณีย์ " . $zipcode;
    return implode(" ", $parts);
}

function parseAddress($address)
{
    $data = [
        "House_no" => "",
        "Moo" => "",
        "Subdistrict" => "",
        "District_address" => "",
        "Province_address" => "",
        "Zipcode" => ""
    ];
    $address = trim((string)$address);
    if ($address === "") return $data;

    $patterns = [
        "House_no" => '/บ้านเลขที่\s*([^\s]+)(?=\s+หมู่ที่|\s+ตำบล|\s+อำเภอ|\s+จังหวัด|\s+รหัสไปรษณีย์|$)/u',
        "Moo" => '/หมู่ที่\s*([^\s]+)(?=\s+ตำบล|\s+อำเภอ|\s+จังหวัด|\s+รหัสไปรษณีย์|$)/u',
        "Subdistrict" => '/ตำบล\s*(.+?)(?=\s+อำเภอ|\s+จังหวัด|\s+รหัสไปรษณีย์|$)/u',
        "District_address" => '/อำเภอ\s*(.+?)(?=\s+จังหวัด|\s+รหัสไปรษณีย์|$)/u',
        "Province_address" => '/จังหวัด\s*(.+?)(?=\s+รหัสไปรษณีย์|$)/u',
        "Zipcode" => '/รหัสไปรษณีย์\s*(\d{5})/u'
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $address, $matches)) {
            $data[$key] = trim($matches[1]);
        }
    }
    return $data;
}

function patientPageUrl(int $page, string $keyword = '', int $villageId = 0): string
{
    $params = [];
    if ($keyword !== '') $params['q'] = $keyword;
    if ($villageId > 0) $params['village_id'] = $villageId;
    if ($page > 1) $params['page'] = $page;
    return 'patient.php' . ($params ? '?' . http_build_query($params) : '');
}



/* =========================================================
   เพิ่มข้อมูล
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add") {

    $firstname  = trim($_POST["Firstname"] ?? "");
    $lastname   = trim($_POST["Lastname"] ?? "");
    $fullname   = trim($firstname . " " . $lastname);
    $gender     = trim($_POST["Gender"] ?? "");
    $age        = (int)($_POST["Age"] ?? 0);
    $weight_kg  = trim($_POST["Weight_kg"] ?? "");
    $height_cm  = trim($_POST["Height_cm"] ?? "");
    $weightValue = $weight_kg === "" ? null : (float)$weight_kg;
    $heightValue = $height_cm === "" ? null : (float)$height_cm;
    $house_no = trim($_POST["House_no"] ?? "");
    $moo = trim($_POST["Moo"] ?? "");
    $subdistrict = trim($_POST["Subdistrict"] ?? "");
    $district_address = trim($_POST["District_address"] ?? "");
    $province_address = trim($_POST["Province_address"] ?? "");
    $zipcode = trim($_POST["Zipcode"] ?? "");
    $address = buildAddress($house_no, $moo, $subdistrict, $district_address, $province_address, $zipcode);
    $phone      = trim($_POST["Phone"] ?? "");
    $disease    = diseaseTextFromPost();
    $village_id = (int)($_POST["Village_id"] ?? 0);
    $latitude_raw = trim($_POST["Latitude"] ?? "");
    $longitude_raw = trim($_POST["Longitude"] ?? "");
    $latitude = $latitude_raw === "" ? null : (float)$latitude_raw;
    $longitude = $longitude_raw === "" ? null : (float)$longitude_raw;
    $photoUpload = thonglangHandleImageUpload('PhotoFile', 'patients');
    $photoPath = $photoUpload['uploaded'] ? (string)($photoUpload['path'] ?? '') : null;

    if ($firstname === "" || $lastname === "" || $gender === "" || $age <= 0 || $village_id <= 0) {

        $message = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";
        $message_type = "error";

    } elseif (!empty($photoUpload['error'])) {

        $message = (string)$photoUpload['error'];
        $message_type = "error";

    } else {

        $sql = "
            INSERT INTO patient
            (
                Firstname,
                Lastname,
                Fullname,
                Gender,
                Age,
                Weight_kg,
                Height_cm,
                Address,
                Phone,
                Disease,
                Photo,
                Village_id,
                Latitude,
                Longitude
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            die("เกิดข้อผิดพลาด: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ssssiddssssidd",
            $firstname,
            $lastname,
            $fullname,
            $gender,
            $age,
            $weightValue,
            $heightValue,
            $address,
            $phone,
            $disease,
            $photoPath,
            $village_id,
            $latitude,
            $longitude
        );

        if (mysqli_stmt_execute($stmt)) {

            mysqli_stmt_close($stmt);

            header("Location: patient.php?success=add");
            exit;

        } else {

            if ($photoPath) {
                thonglangDeleteUploadedFile($photoPath);
            }
            $message = "เพิ่มข้อมูลไม่สำเร็จ: " . mysqli_stmt_error($stmt);
            $message_type = "error";

            mysqli_stmt_close($stmt);
        }
    }
}


/* =========================================================
   แก้ไขข้อมูล
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "edit") {

    $patient_id = (int)($_POST["Patient_id"] ?? 0);

    $firstname  = trim($_POST["Firstname"] ?? "");
    $lastname   = trim($_POST["Lastname"] ?? "");
    $fullname   = trim($firstname . " " . $lastname);
    $gender     = trim($_POST["Gender"] ?? "");
    $age        = (int)($_POST["Age"] ?? 0);
    $weight_kg  = trim($_POST["Weight_kg"] ?? "");
    $height_cm  = trim($_POST["Height_cm"] ?? "");
    $weightValue = $weight_kg === "" ? null : (float)$weight_kg;
    $heightValue = $height_cm === "" ? null : (float)$height_cm;
    $house_no = trim($_POST["House_no"] ?? "");
    $moo = trim($_POST["Moo"] ?? "");
    $subdistrict = trim($_POST["Subdistrict"] ?? "");
    $district_address = trim($_POST["District_address"] ?? "");
    $province_address = trim($_POST["Province_address"] ?? "");
    $zipcode = trim($_POST["Zipcode"] ?? "");
    $address = buildAddress($house_no, $moo, $subdistrict, $district_address, $province_address, $zipcode);
    $phone      = trim($_POST["Phone"] ?? "");
    $disease    = diseaseTextFromPost();
    $village_id = (int)($_POST["Village_id"] ?? 0);
    $latitude_raw = trim($_POST["Latitude"] ?? "");
    $longitude_raw = trim($_POST["Longitude"] ?? "");
    $latitude = $latitude_raw === "" ? null : (float)$latitude_raw;
    $longitude = $longitude_raw === "" ? null : (float)$longitude_raw;
    $existingPhoto = trim((string)($_POST['Current_photo'] ?? ''));
    $removePhoto = (string)($_POST['Remove_photo'] ?? '') === '1';
    $photoUpload = thonglangHandleImageUpload('PhotoFile', 'patients');
    $photoPath = $existingPhoto;
    if ($photoUpload['uploaded']) {
        $photoPath = (string)($photoUpload['path'] ?? '');
    } elseif ($removePhoto) {
        $photoPath = null;
    }

    if (
        $patient_id <= 0 ||
        $firstname === "" ||
        $lastname === "" ||
        $gender === "" ||
        $age <= 0 ||
        $village_id <= 0
    ) {

        $message = "กรุณากรอกข้อมูลให้ครบ";
        $message_type = "error";

    } elseif (!empty($photoUpload['error'])) {

        $message = (string)$photoUpload['error'];
        $message_type = "error";

    } else {

        $sql = "
            UPDATE patient
            SET
                Firstname = ?,
                Lastname = ?,
                Fullname = ?,
                Gender = ?,
                Age = ?,
                Weight_kg = ?,
                Height_cm = ?,
                Address = ?,
                Phone = ?,
                Disease = ?,
                Photo = ?,
                Village_id = ?,
                Latitude = ?,
                Longitude = ?
            WHERE Patient_id = ?
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            die("เกิดข้อผิดพลาด: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ssssiddssssiddi",
            $firstname,
            $lastname,
            $fullname,
            $gender,
            $age,
            $weightValue,
            $heightValue,
            $address,
            $phone,
            $disease,
            $photoPath,
            $village_id,
            $latitude,
            $longitude,
            $patient_id
        );

        if (mysqli_stmt_execute($stmt)) {

            mysqli_stmt_close($stmt);

            if ($photoUpload['uploaded'] && $existingPhoto !== '' && $existingPhoto !== $photoPath) {
                thonglangDeleteUploadedFile($existingPhoto);
            } elseif ($removePhoto && !$photoUpload['uploaded'] && $existingPhoto !== '') {
                thonglangDeleteUploadedFile($existingPhoto);
            }

            header("Location: patient.php?success=edit");
            exit;

        } else {

            if ($photoUpload['uploaded'] && $photoPath) {
                thonglangDeleteUploadedFile($photoPath);
            }
            $message = "แก้ไขข้อมูลไม่สำเร็จ: " . mysqli_stmt_error($stmt);
            $message_type = "error";

            mysqli_stmt_close($stmt);
        }
    }
}


if (isset($_GET['delete'])) {

    $patient_id = (int) $_GET['delete'];

    if ($patient_id > 0) {

        $patientPhotoToDelete = '';
        $photoStmt = mysqli_prepare($conn, "SELECT Photo FROM patient WHERE Patient_id = ? LIMIT 1");
        if ($photoStmt) {
            mysqli_stmt_bind_param($photoStmt, "i", $patient_id);
            mysqli_stmt_execute($photoStmt);
            $photoRes = mysqli_stmt_get_result($photoStmt);
            if ($photoRow = mysqli_fetch_assoc($photoRes)) {
                $patientPhotoToDelete = trim((string)($photoRow['Photo'] ?? ''));
            }
            mysqli_stmt_close($photoStmt);
        }

        $patientTableExists = static function (mysqli $connection, string $table): bool {
            $safe = mysqli_real_escape_string($connection, $table);
            $result = mysqli_query($connection, "SHOW TABLES LIKE '{$safe}'");
            return $result && mysqli_num_rows($result) > 0;
        };

        $deletePatientRows = static function (mysqli $connection, string $table, string $column, int $patientId): void {
            $sql = "DELETE FROM `{$table}` WHERE `{$column}` = ?";
            $statement = mysqli_prepare($connection, $sql);
            if (!$statement) {
                throw new Exception("ไม่สามารถเตรียมคำสั่งลบข้อมูลจาก {$table} ได้: " . mysqli_error($connection));
            }
            mysqli_stmt_bind_param($statement, "i", $patientId);
            if (!mysqli_stmt_execute($statement)) {
                $error = mysqli_stmt_error($statement);
                mysqli_stmt_close($statement);
                throw new Exception("ไม่สามารถลบข้อมูลจาก {$table} ได้: " . $error);
            }
            mysqli_stmt_close($statement);
        };

        mysqli_begin_transaction($conn);

        try {
            /*
             * ลบข้อมูลลูกที่อ้างถึงผู้สูงอายุก่อน แล้วจึงลบข้อมูลหลัก
             * ใช้เฉพาะตารางที่มีอยู่จริง เพื่อรองรับฐานข้อมูลแต่ละเวอร์ชัน
             */
            $relatedTables = [
                ['caregiver_visit_record', 'patient_id'],
                ['caregiver_adl_history', 'patient_id'],
                ['health_assessment', 'patient_id'],
                ['adl_assessment', 'patient_id'],
                ['patient_caregiver_assignment', 'patient_id'],
            ];

            foreach ($relatedTables as [$tableName, $patientColumn]) {
                if ($patientTableExists($conn, $tableName)) {
                    $deletePatientRows($conn, $tableName, $patientColumn, $patient_id);
                }
            }

            $stmt = mysqli_prepare($conn, "DELETE FROM patient WHERE Patient_id = ?");
            if (!$stmt) {
                throw new Exception("ไม่สามารถเตรียมคำสั่งลบข้อมูลผู้สูงอายุได้: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "i", $patient_id);

            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("ไม่สามารถลบข้อมูลผู้สูงอายุได้: " . $error);
            }

            mysqli_stmt_close($stmt);
            mysqli_commit($conn);

            if ($patientPhotoToDelete !== '') {
                thonglangDeleteUploadedFile($patientPhotoToDelete);
            }

            header("Location: patient.php?success=delete");
            exit;

        } catch (Throwable $e) {

            mysqli_rollback($conn);

            echo "
            <script>
                alert('ไม่สามารถลบข้อมูลได้: " .
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
                "');
                window.location.href='patient.php';
            </script>
            ";

            exit;
        }
    }

    header("Location: patient.php");
    exit;
}


/* =========================================================
   ข้อมูลสำหรับแก้ไข
========================================================= */
$editData = null;

if (isset($_GET["edit"])) {

    $patient_id = (int)$_GET["edit"];

    if ($patient_id > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                Patient_id,
                Firstname,
                Lastname,
                Fullname,
                Gender,
                Age,
                Weight_kg,
                Height_cm,
                Address,
                Phone,
                Disease,
                Photo,
                Village_id,
                Latitude,
                Longitude
            FROM patient
            WHERE Patient_id = ?
            "
        );

        if (!$stmt) {
            die("เกิดข้อผิดพลาด: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $patient_id
        );

        mysqli_stmt_execute($stmt);

        $result_edit = mysqli_stmt_get_result($stmt);

        $editData = mysqli_fetch_assoc($result_edit);

        if ($editData) {
            if (trim((string)($editData['Firstname'] ?? '')) === '' && trim((string)($editData['Fullname'] ?? '')) !== '') {
                $nameParts = preg_split('/\s+/u', trim((string)$editData['Fullname']), 2);
                $editData['Firstname'] = $nameParts[0] ?? '';
                $editData['Lastname'] = $nameParts[1] ?? '';
            }
        }

        if ($editData) {
            $editData = array_merge($editData, parseAddress($editData["Address"] ?? ""));
        }

        mysqli_stmt_close($stmt);
    }
}


/* =========================================================
   ดึงหมู่บ้าน
   ใช้ villagename ตามฐานข้อมูลจริง
========================================================= */
$village_sql = "
    SELECT
        village_id,
        villagename,
        district,
        province
    FROM village
    ORDER BY village_id ASC
";

$villageResult = mysqli_query($conn, $village_sql);

if (!$villageResult) {
    die(
        "ไม่สามารถดึงข้อมูลหมู่บ้านได้: "
        . mysqli_error($conn)
    );
}


/* =========================================================
   ดึงข้อมูลผู้สูงอายุ
   ใช้ชื่อฟิลด์ตามฐานข้อมูลจริง
========================================================= */
$filterKeyword = trim((string)($_GET["q"] ?? ""));
$filterVillage = (int)($_GET["village_id"] ?? 0);

$where = [];

if ($filterKeyword !== "") {
    $safeKeyword = mysqli_real_escape_string($conn, $filterKeyword);
    $where[] = "(
        p.Fullname LIKE '%{$safeKeyword}%'
        OR p.Phone LIKE '%{$safeKeyword}%'
        OR p.Disease LIKE '%{$safeKeyword}%'
    )";
}

if ($filterVillage > 0) {
    $where[] = "p.Village_id = " . $filterVillage;
}

$countSql = "SELECT COUNT(*) AS total FROM patient AS p";
if ($where) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}
$countResult = mysqli_query($conn, $countSql);
if (!$countResult) {
    die(
        "ไม่สามารถนับข้อมูลผู้สูงอายุได้: "
        . mysqli_error($conn)
    );
}
$countRow = mysqli_fetch_assoc($countResult);
$filteredPatientCount = (int)($countRow['total'] ?? 0);

$perPage = 20; // แสดงผู้สูงอายุหน้าละ 20 คน และเริ่มแบ่งหน้าเมื่อมีมากกว่า 20 คน
$totalPages = max(1, (int)ceil($filteredPatientCount / $perPage));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $perPage;

$sql = "
    SELECT
        p.Patient_id,
        p.Firstname,
        p.Lastname,
        p.Fullname,
        p.Gender,
        p.Age,
        p.Weight_kg,
        p.Height_cm,
        p.Address,
        p.Phone,
        p.Disease,
        p.Photo,
        p.Village_id,
        p.Latitude,
        p.Longitude,
        v.villagename,
        v.district,
        v.province
    FROM patient AS p
    LEFT JOIN village AS v
        ON p.Village_id = v.village_id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY p.Patient_id ASC LIMIT {$offset}, {$perPage}";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die(
        "ไม่สามารถดึงข้อมูลผู้สูงอายุได้: "
        . mysqli_error($conn)
    );
}

$totalPatientsAll = 0;
$patientsWithCoordinates = 0;
$patientsWithoutCoordinates = 0;
$villageTotalCount = mysqli_num_rows($villageResult);

$countRes = mysqli_query($conn, "SELECT COUNT(*) AS total_patients, SUM(CASE WHEN Latitude IS NOT NULL AND Latitude <> '' AND Longitude IS NOT NULL AND Longitude <> '' THEN 1 ELSE 0 END) AS total_with_coordinates FROM patient");
if ($countRes) {
    $countRow = mysqli_fetch_assoc($countRes);
    $totalPatientsAll = (int)($countRow['total_patients'] ?? 0);
    $patientsWithCoordinates = (int)($countRow['total_with_coordinates'] ?? 0);
    $patientsWithoutCoordinates = max(0, $totalPatientsAll - $patientsWithCoordinates);
}

?>


<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        จัดการข้อมูลผู้สูงอายุ
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIINfQ3yn5HaRdWCJeZ7" crossorigin="">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green: #188c22;
            --green-dark: #11691a;
            --green-soft: #edf8ef;
            --green-line: #d6ead9;
            --text: #263238;
            --muted: #6f7b72;
            --white: #ffffff;
            --bg: #FFF8DC;
            --danger: #d64545;
            --warning: #e89b28;
            --shadow: 0 8px 28px rgba(26, 84, 35, .08);
        }

        body {
            font-family: "Noto Sans Thai", sans-serif;
            background: var(--bg);
            color:#000000;
            min-height: 100vh;
        }

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 270px;
            height: 100vh;
            background: var(--green);
            color:#000000;
            padding: 25px 12px;
            z-index: 100;
        }

        .brand { text-align: center; margin-bottom: 38px; }
        .logo {
            width: 82px; height: 82px; margin: 0 auto 12px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            overflow: hidden; background: rgba(255,255,255,.08);
        }
        .logo img { width: 78px; height: 78px; object-fit: contain; display: block; }
        .brand h2 { font-size: 24px; font-weight: 700; color:#000000; }
        .brand small { display: none; }

        .menu { list-style: none; }
        .menu li { margin-bottom: 9px; }
        .menu a {
            display: flex; align-items: center; width: 100%; height: 51px;
            padding: 0 16px; border: 2px solid rgba(255,255,255,.92); border-radius: 14px;
            color:#000000; text-decoration: none; font-size: 14px; font-weight: 500;
            transition: .2s ease;
        }
        .menu a:hover { background: rgba(255,255,255,.13); transform: translateX(3px); }
        .menu a.active { background: rgba(255,255,255,.14); }
        .menu-text { white-space: nowrap; }

        .main {
            margin-left: 270px;
            min-height: 100vh;
            padding: 30px 34px 44px;
            background: var(--bg);
        }

        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            margin: 18px 0 22px;
        }
        .title { font-size: 28px; font-weight: 700; color:#000000; }
        .subtitle { color:#000000; font-size: 14px; margin-top: 4px; }

        .patient-top-action{
            display:flex;
            justify-content:flex-end;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-top:10px;
            margin-bottom:20px;
        }

        .patient-top-action-clean{
            padding:2px 4px;
            display:flex;
            justify-content:flex-end;
            width:100%;
        }

        .patient-top-summary{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
        }

        .patient-inline-chip{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:38px;
            padding:8px 14px;
            border-radius:999px;
            background:#f4fbfb;
            border:1px solid #d9ece9;
            color:#285d60;
            font-size:12px;
            font-weight:800;
            white-space:nowrap;
        }

        .patient-inline-chip.success{
            background:#edf8f4;
            border-color:#d5eadf;
            color:#23614d;
        }

        .patient-add-btn{
            min-height:44px;
            padding:10px 18px;
            border:0;
            border-radius:12px;
            background:#61c2c6;
            color:#fff;
            font:inherit;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
            box-shadow:0 6px 16px rgba(56,161,166,.18);
            transition:.18s ease;
        }

        .patient-add-btn:hover{
            transform:translateY(-1px);
            filter:brightness(.98);
        }

        .patient-search-card{
            padding:18px 22px;
        }

        .patient-search-grid{
            display:grid;
            grid-template-columns:minmax(0,1.25fr) minmax(240px,.75fr) auto;
            gap:14px;
            align-items:end;
        }

        .patient-search-action{
            display:flex;
            align-items:flex-end;
        }

        .patient-search-btn{
            min-height:46px;
            padding:10px 20px;
            border:0;
            border-radius:11px;
            background:#61c2c6;
            color:#fff;
            font:inherit;
            font-weight:700;
            cursor:pointer;
        }

        .patient-list-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .patient-list-head h3{
            margin-bottom:0;
        }

        .patient-count{
            color:#71857c;
            font-size:12px;
        }

        .patient-modal{
            display:none;
            position:fixed;
            inset:0;
            z-index:5000;
            background:rgba(24,45,38,.34);
            padding:24px;
            align-items:center;
            justify-content:center;
        }

        .patient-modal.is-open{
            display:flex;
        }

        .patient-modal-dialog{
            width:min(1200px,100%);
            max-height:calc(100vh - 48px);
            overflow:auto;
            position:relative;
        }

        .patient-modal .card{
            margin:0;
        }

        .patient-modal-close{
            position:absolute;
            right:14px;
            top:12px;
            z-index:2;
            width:38px;
            height:38px;
            border:1px solid #d6e7df;
            border-radius:50%;
            background:#fff;
            color:#416457;
            font-size:24px;
            line-height:1;
            cursor:pointer;
        }

        .card {
            background: #fff;
            border: 1px solid rgba(24,140,34,.08);
            border-radius: 20px;
            padding: 26px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .card h3 {
            color:#000000;
            margin-bottom: 22px;
            font-size: 20px;
            font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .card h3::before {
            content: ""; width: 5px; height: 24px; border-radius: 6px; background: var(--green);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px 20px;
        }
        .field { display: flex; flex-direction: column; min-width: 0; }
        .field label { margin-bottom: 8px; font-weight: 600; font-size: 14px; color:#000000; }
        .field input, .field select {
            width: 100%; height: 46px; padding: 0 14px;
            border: 1px solid #d9e3da; border-radius: 11px;
            background: #fff; color:#000000; font: inherit; font-size: 14px; outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .field input::placeholder { color:#000000; }
        .field input:hover, .field select:hover { border-color: #b7d7bc; }
        .field input:focus, .field select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(24,140,34,.10);
            background: #fcfffc;
        }

        .field input[type="file"]{
            height:auto;
            padding:10px 12px;
            background:#fbfefe;
        }
        .field small{
            margin-top:6px;
            color:#6d8077;
            font-size:12px;
            line-height:1.5;
        }
        .photo-upload-card{
            grid-column:1 / -1;
            display:grid;
            grid-template-columns:140px minmax(0,1fr);
            gap:16px;
            align-items:start;
            padding:18px;
            border:1px dashed #cfe4db;
            border-radius:16px;
            background:#fbfefe;
        }
        .patient-photo-preview{
            width:120px;
            height:120px;
            border-radius:18px;
            border:1px solid #dceae4;
            overflow:hidden;
            background:linear-gradient(135deg,#eef9fb 0%,#f3faf8 100%);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#6f8782;
            font-size:12px;
            font-weight:700;
            text-align:center;
            line-height:1.45;
        }
        .patient-photo-preview img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .photo-upload-actions{
            display:flex;
            flex-direction:column;
            gap:10px;
        }
        .photo-remove-check{
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:#5c726b;
            font-size:13px;
            font-weight:600;
        }
        .photo-remove-check input{
            width:auto!important;
            height:auto!important;
        }
        .patient-thumb-cell{
            width:86px;
        }
        .patient-thumb{
            width:56px;
            height:56px;
            border-radius:16px;
            overflow:hidden;
            border:1px solid #dceae4;
            background:linear-gradient(135deg,#eef9fb 0%,#f3faf8 100%);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#6f8782;
            font-size:11px;
            font-weight:700;
            text-align:center;
            line-height:1.35;
        }
        .patient-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }


        .actions { display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .btn {
            border: 0; min-height: 38px; padding: 9px 16px; border-radius: 10px;
            text-decoration: none; font: inherit; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.10); }
        .btn-green { background: var(--green); color:#000000; }
        .btn-green:hover { background: var(--green-dark); }
        .btn-gray { background: #758077; color:#000000; }
        .btn-yellow { background: #fff4df; color:#000000; border: 1px solid #f3d094; }
        .btn-red { background: #fff0f0; color:#000000; border: 1px solid #f0bcbc; }

        .table-wrap {
            width: 100%; overflow-x: auto; border: 1px solid #DDF5DD;
            border-radius: 14px; background: #fff;
        }
        .table { width: 100%; border-collapse: collapse; min-width: 1530px; }
        .table th {
            background: var(--green); color:#000000; padding: 13px 12px;
            text-align: left; white-space: nowrap; font-size: 13px; font-weight: 600;
        }
        .table td {
            padding: 13px 12px; border-bottom: 1px solid #edf1ed;
            vertical-align: middle; font-size: 13px; color:#000000;
        }
        .table tbody tr:nth-child(even) td { background: #FFFEF8; }
        .table tbody tr:hover td { background: var(--green-soft); }
        .table tbody tr:last-child td { border-bottom: 0; }
        .table .btn { padding: 7px 12px; margin-right: 5px; min-height: 32px; }
        .table th:last-child, .table td:last-child { position: sticky; right: 0; }
        .table th:last-child { z-index: 2; }
        .table td:last-child { background: #fff; white-space: nowrap; box-shadow: -5px 0 12px rgba(0,0,0,.025); }
        .table tbody tr:nth-child(even) td:last-child { background: #FFFEF8; }
        .table tbody tr:hover td:last-child { background: var(--green-soft); }

        .empty { text-align: center; padding: 38px !important; color:#000000; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fff0f0; color:#000000; border: 1px solid #f0caca; }
        .alert-success { background: #ecf9ee; color:#000000; border: 1px solid #cde8d1; }

        @media (max-width: 1200px) {
            .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .sidebar { position: relative; width: 100%; height: auto; }
            .main { margin-left: 0; padding: 20px 14px 36px; }
            .form-grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; }
            .patient-search-grid { grid-template-columns: 1fr; }
            .patient-search-btn { width:100%; }
            .patient-modal { padding:12px; }
        }
        @media (max-width: 500px) {
            .card { padding: 18px; border-radius: 16px; }
            .title { font-size: 24px; }
            .menu a { height: 52px; font-size: 16px; }
        }
    
        .btn-map-search {
            width: 100%;
            border: 1px solid #b9ddd7;
            background: #eef9f7;
            color: #1f5f5d;
            min-height: 48px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-map-search:hover { background:#e1f4f1; }
        .map-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .btn-map-current { background:#5fc5c8; color:#fff; border:1px solid #5fc5c8; }
        .btn-map-current:hover { background:#4db7bb; }
        .map-status { margin-top:7px; min-height:18px; font-size:12px; font-weight:600; color:#357268; }
        .map-status.is-error { color:#a44444; }
        .map-link { display:inline-flex; align-items:center; justify-content:center; padding:7px 11px; border-radius:9px; background:#eef9f7; color:#216661; border:1px solid #cbe7e2; text-decoration:none; font-weight:600; white-space:nowrap; }
        .map-link:hover { background:#ddf3ef; }
        .patient-map-card{grid-column:1 / -1;padding:18px;border:1px solid #d8ebe8;border-radius:18px;background:#fbfefe}
        .patient-map-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px}
        .patient-map-head strong{display:block;color:#23474a;font-size:14px}
        .patient-map-head span{display:block;margin-top:4px;color:#718681;font-size:12px;line-height:1.5}
        .patient-map-actions{display:flex;gap:8px;flex-wrap:wrap}
        .patient-map-btn{min-height:38px;padding:8px 13px;border-radius:10px;border:1px solid #cbe5e1;background:#eef9f7;color:#216661;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
        .patient-map-btn.primary{background:#5fc5c8;border-color:#5fc5c8;color:#fff}
        #patientLocationMap{width:100%;height:300px;border:1px solid #d7e8e5;border-radius:14px;overflow:hidden;background:#eef7f6}
        .patient-coordinate-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
        .patient-coordinate-field{display:flex;flex-direction:column;gap:8px;padding:14px 16px;border:1px solid #d8ebe8;border-radius:14px;background:#f7fbfb}
        .patient-coordinate-field label{margin:0;font-size:13px;font-weight:800;color:#2b5559}
        .patient-coordinate-input{width:100%;height:48px;padding:0 14px;border:1px solid #c9e0dd;border-radius:12px;background:#fff;color:#244846;font:inherit;font-size:14px;outline:none;box-shadow:0 4px 12px rgba(36,108,115,.04);transition:border-color .18s,box-shadow .18s,background .18s}
        .patient-coordinate-input::placeholder{color:#9aadaa}
        .patient-coordinate-input:hover{border-color:#a9d2cd}
        .patient-coordinate-input:focus{border-color:#62bfc0;box-shadow:0 0 0 3px rgba(88,191,192,.14);background:#fff}
        @media(max-width:700px){#patientLocationMap{height:240px}.patient-coordinate-row{grid-template-columns:1fr}}


/* ===== Premium patient registry refresh ===== */
.patient-hero-shell{display:grid;gap:18px;margin:4px 0 22px}
.patient-hero-card{display:grid;grid-template-columns:minmax(0,1.35fr) auto;gap:22px;align-items:center;padding:26px 28px;border-radius:28px;border:1px solid #d6ece9;background:linear-gradient(135deg,#eef9fb 0%,#f6fcfc 48%,#eaf8f5 100%);box-shadow:0 20px 48px rgba(36,108,115,.08);overflow:hidden;position:relative}
.patient-hero-card:before{content:'';position:absolute;right:-60px;top:-50px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle at center,rgba(88,191,192,.16) 0%,rgba(88,191,192,0) 68%)}
.patient-hero-copy,.patient-hero-actions{position:relative;z-index:1}
.patient-hero-kicker,.patient-section-kicker{display:inline-block;font-size:12px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#6b8b86;margin-bottom:10px}
.patient-hero-copy h2{margin:0 0 8px;color:#173f45;font-size:34px;line-height:1.12;letter-spacing:-.4px}
.patient-hero-copy p{margin:0;color:#68817d;font-size:15px;line-height:1.65;max-width:760px}
.patient-hero-tags{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.patient-hero-tag{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:#fff;border:1px solid #d7ebe8;color:#285d60;font-size:12px;font-weight:800;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.patient-hero-tag.success{background:#eef9f4;color:#24624e;border-color:#d3eadf}
.patient-hero-actions{display:grid;gap:12px;justify-items:end}
.patient-coverage-box{min-width:220px;padding:18px 18px 16px;border-radius:22px;border:1px solid #d6ebe7;background:rgba(255,255,255,.88);box-shadow:0 10px 24px rgba(36,108,115,.06);text-align:left}
.patient-coverage-label{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#75908c}
.patient-coverage-value{margin-top:6px;font-size:38px;line-height:1;font-weight:900;color:#1c5960}
.patient-coverage-sub{margin-top:7px;font-size:13px;color:#6b8580;line-height:1.45}
.patient-add-btn{min-height:50px!important;padding:12px 22px!important;border-radius:16px!important;background:linear-gradient(135deg,#59bfc0 0%,#44a9aa 100%)!important;box-shadow:0 16px 30px rgba(88,191,192,.24)!important}
.patient-add-btn:hover{transform:translateY(-1px);background:linear-gradient(135deg,#4eb5b6 0%,#3f9ea0 100%)!important;filter:none!important}
.patient-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.patient-kpi-card{position:relative;padding:18px 18px 16px;border-radius:22px;background:#fff;border:1px solid #d8ece8;box-shadow:0 12px 28px rgba(36,108,115,.06);overflow:hidden}
.patient-kpi-card:before{content:'';position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#58bfc0,#9adfdc)}
.patient-kpi-card.active:before{background:linear-gradient(90deg,#4bb9a7,#7ad4be)}
.patient-kpi-card.info:before{background:linear-gradient(90deg,#7db9df,#9fd4ed)}
.patient-kpi-card.waiting:before{background:linear-gradient(90deg,#f0b25e,#f6d39d)}
.patient-kpi-label{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#77908b}
.patient-kpi-value{margin-top:8px;font-size:34px;line-height:1;font-weight:900;color:#1a565c}
.patient-kpi-sub{margin-top:8px;font-size:13px;color:#6f8782;line-height:1.45}
.patient-search-card,.patient-table-card{border:1px solid #d8ece8!important;border-radius:30px!important;box-shadow:0 20px 48px rgba(36,108,115,.08)!important;padding:24px 24px 22px!important;background:linear-gradient(180deg,#ffffff 0%,#fcfefe 100%)!important}
.patient-search-head,.patient-list-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}
.patient-search-head{margin-bottom:16px}
.patient-search-head h3,.patient-list-head h3{margin:0 0 8px!important;color:#173f45!important;font-size:28px!important;letter-spacing:-.35px}
.patient-list-copy p,.patient-search-head p{margin:0;color:#738a86;font-size:14px;line-height:1.65;max-width:760px}
.patient-head-chip{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 16px;border-radius:999px;background:#f4fbfb;border:1px solid #d9ece9;color:#285d60;font-size:12px;font-weight:900;white-space:nowrap;box-shadow:0 6px 16px rgba(36,108,115,.05)}
.patient-head-chip.success{background:#edf8f4;border-color:#d5eadf;color:#23614d}
.patient-head-chip.soft{background:#f8fbff;border-color:#d8e7f5;color:#416c8b}
.patient-list-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.patient-search-outside{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:14px 0 16px;padding:0 2px}
.patient-search-outside .search-field{display:grid;gap:7px;min-width:220px;flex:1 1 280px}
.patient-search-outside .search-field.village{max-width:300px;flex:0 1 300px}
.patient-search-outside label{font-size:12px;font-weight:800;color:#56736f;padding-left:3px}
.patient-search-outside input,.patient-search-outside select{width:100%;height:46px;border:1px solid #cfe4e1;border-radius:14px;background:#fff;padding:0 14px;font:inherit;font-size:13px;color:#244846;outline:none;box-shadow:0 8px 20px rgba(36,108,115,.05)}
.patient-search-outside input:focus,.patient-search-outside select:focus{border-color:#74c6c6;box-shadow:0 0 0 3px rgba(88,191,192,.12)}
.patient-search-actions{display:flex;gap:8px;align-items:center;flex:0 0 auto}
.patient-search-submit,.patient-search-reset{height:46px;padding:0 18px;border-radius:14px;font:inherit;font-size:13px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer}
.patient-search-submit{border:1px solid #4fb3b5;background:linear-gradient(135deg,#59bfc0,#48a9ab);color:#fff;box-shadow:0 10px 20px rgba(88,191,192,.2)}
.patient-search-reset{border:1px solid #d7e6e3;background:#fff;color:#466966}
.patient-count{font-size:13px!important;color:#6f8782!important;font-weight:700}
.patient-pagination{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:18px}
.patient-page-link,.patient-page-dots{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;padding:0 12px;border-radius:12px;border:1px solid #d7e6e3;background:#fff;color:#466966;text-decoration:none;font-size:13px;font-weight:800;box-shadow:0 8px 18px rgba(36,108,115,.05)}
.patient-page-link:hover{background:#f3fbfb;border-color:#bfe1df}
.patient-page-link.active{background:linear-gradient(135deg,#59bfc0,#48a9ab);border-color:#4fb3b5;color:#fff;box-shadow:0 12px 22px rgba(88,191,192,.2)}
.patient-page-link.disabled{pointer-events:none;opacity:.45;background:#f7fbfb}
.patient-page-dots{background:transparent;border-color:transparent;box-shadow:none;min-width:auto;padding:0 2px}
.patient-search-grid{gap:14px!important;padding-top:4px}.patient-search-grid .field label{font-size:13px;font-weight:800;color:#23474a;margin-bottom:8px}.patient-search-grid input,.patient-search-grid select{min-height:46px!important;border-radius:14px!important;border:1px solid #d6e8e5!important;background:#fff!important;box-shadow:0 6px 16px rgba(36,108,115,.04)!important}.patient-search-btn{min-height:46px!important;padding:10px 20px!important;border-radius:14px!important;font-weight:900!important;background:linear-gradient(135deg,#59bfc0 0%,#4aaeb0 100%)!important;box-shadow:0 12px 22px rgba(88,191,192,.18)!important}.patient-search-btn:hover{background:linear-gradient(135deg,#4db5b6 0%,#419fa1 100%)!important}
.table-wrap{border:1px solid #dcecea!important;border-radius:26px!important;overflow:auto!important;background:linear-gradient(180deg,#f7fbfb 0%,#f1f8f8 100%)!important;padding:10px!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.7)}
.table{width:100%!important;min-width:960px!important;border-collapse:separate!important;border-spacing:0 10px!important}
.table th{position:sticky;top:0;z-index:2;background:linear-gradient(180deg,#dff0f2 0%,#d4e8eb 100%)!important;color:#204c50!important;font-size:13px!important;font-weight:900!important;border:none!important;padding:14px 14px!important}
.table th:first-child{border-radius:18px 0 0 18px}
.table th:last-child{border-radius:0 18px 18px 0}
.table td{padding:16px 14px!important;border:none!important;color:#244846!important;vertical-align:middle!important;background:#ffffff!important}
.table tbody tr td:first-child{border-radius:20px 0 0 20px}
.table tbody tr td:last-child{border-radius:0 20px 20px 0;box-shadow:none!important}
.table tbody tr{filter:drop-shadow(0 8px 18px rgba(36,108,115,.06))}
.table tbody tr:hover td{background:#f5fbfb!important}
.table .btn{padding:10px 14px!important;border-radius:14px!important;font-weight:900!important;box-shadow:0 10px 18px rgba(36,108,115,.08);min-height:42px}
.btn-info{background:linear-gradient(135deg,#f5f9ff 0%,#e9f1ff 100%)!important;border:0!important;color:#315e88!important;box-shadow:none!important}
.btn-yellow{background:linear-gradient(135deg,#eefcf8 0%,#e2f7f2 100%)!important;border-color:#cbe8df!important;color:#27655f!important}
.btn-red{background:linear-gradient(135deg,#fff6f6 0%,#fff0f0 100%)!important;border-color:#f0cece!important;color:#a05a5a!important}
.btn-info:hover,.btn-yellow:hover,.btn-red:hover{transform:translateY(-1px);filter:brightness(.99)}
.action-group{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.action-group .btn{box-shadow:none!important}
.name-cell strong{display:block;font-size:15px;color:#173f45;line-height:1.2}
.name-cell small{display:block;margin-top:6px;color:#7a918d;font-size:12px}
.age-pill,.village-pill,.disease-pill{display:inline-flex;align-items:center;min-height:34px;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid transparent;line-height:1.35}
.age-pill{background:#f6fafb;color:#325f65}
.village-pill{background:#eef8f5;color:#2d655e}
.disease-pill{background:#fff7ee;color:#8a642d}
.disease-pill.empty{background:#f6f8f8;color:#7e8f8b}
.disease-pill-list{display:flex;flex-direction:column;align-items:flex-start;gap:8px}
.disease-pill-list .disease-pill{width:max-content;max-width:100%;white-space:normal}
.age-pill,.village-pill,.disease-pill{border:0!important;box-shadow:none!important}
.disease-pill.empty{display:flex!important;width:100%!important;min-height:34px!important;align-items:center!important;justify-content:center!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:#8a642d!important}
.table .btn-info,.table .btn-yellow,.table .btn-red{border:0!important}
.disease-field{grid-column:1/-1}.disease-list{display:grid;gap:9px}.disease-input-row{display:grid;grid-template-columns:minmax(0,1fr) 42px;gap:8px;align-items:center}.disease-remove-btn{width:42px;height:42px;border:1px solid #f1caca;border-radius:12px;background:#fff4f4;color:#bb4b4b;font-size:22px;line-height:1;cursor:pointer}.disease-remove-btn:hover{background:#ffeaea}.disease-add-btn{margin-top:9px;min-height:42px;border:1px dashed #83cbc7;border-radius:12px;background:#f3fbfa;color:#266c6a;font:inherit;font-weight:800;cursor:pointer}.disease-add-btn:hover{background:#eaf8f6}

.map-link{padding:8px 12px!important;border-radius:12px!important;background:#eef9f7!important;color:#216661!important;border:1px solid #cbe7e2!important;font-weight:800!important;box-shadow:0 6px 14px rgba(36,108,115,.05)!important}
.empty{padding:46px 16px!important;font-size:14px!important;color:#748782!important;background:#fbfefe;border-radius:18px}
.patient-modal-dialog .card{border-radius:28px!important;box-shadow:0 24px 60px rgba(24,58,61,.16)!important}.patient-modal-close{box-shadow:0 8px 18px rgba(36,108,115,.08)}
@media (max-width:1200px){.patient-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.patient-hero-card{grid-template-columns:1fr}.patient-hero-actions{justify-items:start}.form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }}
@media (max-width:900px){.patient-search-outside{align-items:stretch}.patient-search-outside .search-field,.patient-search-outside .search-field.village{max-width:none;flex:1 1 100%}.patient-search-actions{width:100%}.patient-search-submit,.patient-search-reset{flex:1}.sidebar { position: relative; width: 100%; height: auto; }.main { margin-left: 0; padding: 20px 14px 36px; }.form-grid { grid-template-columns: 1fr; }.topbar { align-items: flex-start; }.patient-search-grid { grid-template-columns: 1fr; }.patient-search-btn { width:100%; }.patient-modal { padding:12px; }.patient-kpi-grid{grid-template-columns:1fr}.patient-hero-copy h2{font-size:28px}.patient-hero-card{padding:22px 20px;border-radius:24px}.patient-list-meta{justify-content:flex-start}.table{min-width:860px!important}}
@media (max-width:500px){.card { padding: 18px; border-radius: 16px; }.title { font-size: 24px; }.menu a { height: 52px; font-size: 16px; }.patient-hero-tag,.patient-head-chip{width:100%;justify-content:center}}



        @media (max-width:700px){
            .patient-top-action{align-items:stretch;}
            .patient-top-summary{width:100%;}
            .patient-inline-chip{flex:1 1 100%;}
            .patient-add-btn{width:100%;}
        }


/* FINAL: remove visual frames from age/village/disease labels only */
.age-pill,.village-pill,.disease-pill{
    display:inline!important;
    min-height:0!important;
    padding:0!important;
    margin:0!important;
    border:0!important;
    border-radius:0!important;
    background:transparent!important;
    box-shadow:none!important;
    color:#315f62!important;
    font-size:13px!important;
    font-weight:800!important;
    line-height:1.55!important;
}
.disease-pill-list{display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:2px!important}
.disease-pill.empty{display:block!important;width:100%!important;min-height:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;text-align:center!important;color:#6f8581!important}
.table .btn-info{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-height:44px!important;
    padding:0 18px!important;
    border:1px solid #cfe1dc!important;
    border-radius:14px!important;
    background:#fff!important;
    box-shadow:none!important;
    color:#1f5760!important;
    font-weight:900!important;
    text-decoration:none!important;
}
.table .btn-info:hover{background:#f5fbfb!important;transform:none!important;filter:none!important;color:#1b4d54!important;border-color:#bfd7d1!important}
</style>

<?php renderPastelTheme(); ?>
</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->
<?php renderSidebar(); ?>

<div class="main">
    <?php renderUserTopbar(); ?>


   
    <?php if ($message !== ""): ?>

        <div class="alert <?= $message_type === "success" ? "alert-success" : "alert-error" ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <div class="patient-modal" id="patientModal" aria-hidden="true">
        <div class="patient-modal-dialog">
            <button
                type="button"
                class="patient-modal-close"
                id="closePatientModal"
                aria-label="ปิด"
            >×</button>

<div class="card">

        <h3>

            <?= $editData
                ? " แก้ไขข้อมูลผู้สูงอายุ"
                : " เพิ่มข้อมูลผู้สูงอายุ"
            ?>

        </h3>


        <form
            method="POST"
            action="patient.php"
            enctype="multipart/form-data"
        >

            <?php if ($editData): ?>

                <input
                    type="hidden"
                    name="action"
                    value="edit"
                >

                <input
                    type="hidden"
                    name="Patient_id"
                    value="<?= (int)$editData["Patient_id"] ?>"
                >

            <?php else: ?>

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >

            <?php endif; ?>


            <div class="form-grid">

                <div class="field">
                    <label>ชื่อ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input
                        type="text"
                        name="Firstname"
                        required
                        value="<?= htmlspecialchars($editData["Firstname"] ?? "") ?>"
                        placeholder="กรอกชื่อ"
                    >
                </div>

                <div class="field">
                    <label>นามสกุล <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input
                        type="text"
                        name="Lastname"
                        required
                        value="<?= htmlspecialchars($editData["Lastname"] ?? "") ?>"
                        placeholder="กรอกนามสกุล"
                    >
                </div>

                <div class="field">
                    <label>เพศ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <select name="Gender" required>
                        <option value="">-- เลือกเพศ --</option>
                        <option value="ชาย" <?= (($editData["Gender"] ?? "") === "ชาย") ? "selected" : "" ?>>ชาย</option>
                        <option value="หญิง" <?= (($editData["Gender"] ?? "") === "หญิง") ? "selected" : "" ?>>หญิง</option>
                    </select>
                </div>

                <div class="field">
                    <label>อายุ <span class="required-star" style="color:#d93025!important">*</span></label>
                    <input
                        type="number"
                        name="Age"
                        min="1"
                        max="150"
                        required
                        value="<?= htmlspecialchars($editData["Age"] ?? "") ?>"
                        placeholder="กรอกอายุ"
                    >
                </div>


                <div class="field">
                    <label>น้ำหนัก (กก.)</label>
                    <input
                        type="number"
                        name="Weight_kg"
                        min="1"
                        max="500"
                        step="0.01"
                        value="<?= htmlspecialchars($editData["Weight_kg"] ?? "") ?>"
                        placeholder="กรอกน้ำหนัก"
                    >
                </div>

                <div class="field">
                    <label>ส่วนสูง (ซม.)</label>
                    <input
                        type="number"
                        name="Height_cm"
                        min="1"
                        max="300"
                        step="0.01"
                        value="<?= htmlspecialchars($editData["Height_cm"] ?? "") ?>"
                        placeholder="กรอกส่วนสูง"
                    >
                </div>

                <div class="field">
                    <label>บ้านเลขที่</label>
                    <input type="text" name="House_no" value="<?= htmlspecialchars($editData["House_no"] ?? "") ?>" placeholder="กรอกบ้านเลขที่">
                </div>

                <div class="field">
                    <label>หมู่ที่</label>
                    <input type="text" name="Moo" value="<?= htmlspecialchars($editData["Moo"] ?? "") ?>" placeholder="กรอกหมู่ที่">
                </div>

                <div class="field">
                    <label>หมู่บ้าน <span class="required-star" style="color:#d93025!important">*</span></label>
                    <select name="Village_id" required>
                    <option value="">-- เลือกหมู่บ้าน --</option>

                <?php
                    mysqli_data_seek($villageResult, 0);
                    while ($village = mysqli_fetch_assoc($villageResult)):
                ?>
                    <option
                        value="<?= (int)$village["village_id"] ?>"
                     <?= (
                    (int)($editData["Village_id"] ?? 0)
                    ===
                    (int)$village["village_id"]
                    ) ? "selected" : "" ?>
                >
                <?= htmlspecialchars($village["villagename"]) ?>
            </option>
        <?php endwhile; ?>

    </select>
</div>

                <div class="field">
                    <label>ตำบล</label>
                    <input type="text" name="Subdistrict" value="<?= htmlspecialchars($editData["Subdistrict"] ?? "") ?>" placeholder="กรอกตำบล">
                </div>

                <div class="field">
                    <label>อำเภอ</label>
                    <input type="text" name="District_address" value="<?= htmlspecialchars($editData["District_address"] ?? "") ?>" placeholder="กรอกอำเภอ">
                </div>

                <div class="field">
                    <label>จังหวัด</label>
                    <input type="text" name="Province_address" value="<?= htmlspecialchars($editData["Province_address"] ?? "") ?>" placeholder="กรอกจังหวัด">
                </div>

                <div class="field">
                    <label>รหัสไปรษณีย์</label>
                    <input type="text" name="Zipcode" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" value="<?= htmlspecialchars($editData["Zipcode"] ?? "") ?>" placeholder="กรอกรหัสไปรษณีย์">
                </div>

                <div class="field disease-field">
                    <label>โรคประจำตัว</label>
                    <input type="text" name="Disease" value="<?= e($_POST['Disease'] ?? ($editData['Disease'] ?? '')) ?>" placeholder="เช่น ความดัน, เบาหวาน" autocomplete="off">
                </div>

                <div class="field">
                    <label>เบอร์โทรศัพท์</label>
                    <input type="text" name="Phone" maxlength="10" inputmode="numeric" value="<?= htmlspecialchars($editData["Phone"] ?? "") ?>" placeholder="กรอกเบอร์โทรศัพท์">
                </div>

                <div class="patient-map-card">
                    <div class="patient-map-head">
                        <div>
                            <strong>ตำแหน่งบ้านของผู้สูงอายุ</strong>
                        </div>
                        <div class="patient-map-actions">
                            <button type="button" class="patient-map-btn primary" id="useCurrentPatientLocation">ใช้ตำแหน่งปัจจุบัน</button>
                            <button type="button" class="patient-map-btn" id="clearPatientLocation">ล้างตำแหน่ง</button>
                        </div>
                    </div>
                    <div id="patientLocationMap" aria-label="เลือกตำแหน่งบ้านผู้สูงอายุ"></div>
                    <div class="patient-coordinate-row">
                        <div class="patient-coordinate-field">
                            <label for="Latitude">ละติจูด</label>
                            <input class="patient-coordinate-input" type="text" inputmode="decimal" name="Latitude" id="Latitude" value="<?= htmlspecialchars($editData["Latitude"] ?? "") ?>" placeholder="เช่น 14.9799000" autocomplete="off">
                        </div>
                        <div class="patient-coordinate-field">
                            <label for="Longitude">ลองจิจูด</label>
                            <input class="patient-coordinate-input" type="text" inputmode="decimal" name="Longitude" id="Longitude" value="<?= htmlspecialchars($editData["Longitude"] ?? "") ?>" placeholder="เช่น 102.3157000" autocomplete="off">
                        </div>
                    </div>
                </div>

                <div class="photo-upload-card">
                    <div class="patient-photo-preview">
                        <?php $currentPhotoUrl = thonglangUploadedImageUrl($editData['Photo'] ?? ''); ?>
                        <?php if ($currentPhotoUrl !== ''): ?>
                            <img src="<?= e($currentPhotoUrl) ?>" alt="รูปผู้สูงอายุ">
                        <?php else: ?>
                            <span>ยังไม่มีรูปภาพ</span>
                        <?php endif; ?>
                    </div>
                    <div class="photo-upload-actions">
                        <div class="field" style="margin-bottom:0;">
                            <label>รูปภาพผู้สูงอายุ</label>
                            <input type="file" name="PhotoFile" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small>รองรับไฟล์ JPG, PNG, GIF หรือ WEBP ขนาดไม่เกิน 5 MB</small>
                        </div>
                        <?php if (!empty($editData['Photo'])): ?>
                            <label class="photo-remove-check">
                                <input type="checkbox" name="Remove_photo" value="1">
                                ลบรูปภาพปัจจุบัน
                            </label>
                        <?php endif; ?>
                        <input type="hidden" name="Current_photo" value="<?= e($editData['Photo'] ?? '') ?>">
                    </div>
                </div>

            </div>

            <div class="actions">

                <button
                    type="submit"
                    class="btn btn-green"
                >

                    <?= $editData
                        ? " บันทึกการแก้ไข"
                        : " เพิ่มข้อมูล"
                    ?>

                </button>


                <?php if ($editData): ?>

                    <a
                        href="patient.php"
                        class="btn btn-gray"
                    >
                        ยกเลิก
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </div>
        </div>
    </div>

    <div class="patient-top-action patient-top-action-clean">
        <button type="button" class="patient-add-btn" id="openPatientModal">+ เพิ่มผู้สูงอายุ</button>
    </div>

    <!-- =================================================
         SEARCH
    ================================================= -->
    <form method="get" class="patient-search-outside" action="patient.php">
        <div class="search-field">

            <input id="patientSearchKeyword" type="text" name="q" value="<?= e($filterKeyword) ?>" placeholder="ค้นหาชื่อ นามสกุล โรคประจำตัว หรือเบอร์โทร">
        </div>
        <div class="search-field village">

            <select id="patientSearchVillage" name="village_id">
                <option value="0">ทุกหมู่บ้าน</option>
                <?php mysqli_data_seek($villageResult, 0); ?>
                <?php while ($searchVillage = mysqli_fetch_assoc($villageResult)): ?>
                    <option value="<?= (int)$searchVillage['village_id'] ?>" <?= $filterVillage === (int)$searchVillage['village_id'] ? 'selected' : '' ?>><?= e($searchVillage['villagename']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="patient-search-actions">
            <button type="submit" class="patient-search-submit">ค้นหา</button>
            <?php if ($filterKeyword !== '' || $filterVillage > 0): ?>
                <a href="patient.php" class="patient-search-reset">ล้างค้นหา</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="card patient-table-card">

        <div class="patient-list-head">
            <div class="patient-list-copy">
                <h3>รายการผู้สูงอายุ</h3>
            </div>

            <div class="patient-list-meta">
                <div class="patient-head-chip soft">พบ <?= number_format($filteredPatientCount) ?> รายการ<?php if ($filterVillage > 0 || $filterKeyword !== ""): ?> จากตัวกรองที่เลือก<?php endif; ?></div>
                <div class="patient-head-chip success">มีพิกัดแล้ว <?= number_format($patientsWithCoordinates) ?> ราย</div>
            </div>
        </div>

        <div class="table-wrap">

            <table class="table">

                <thead>

                    <tr>
                        <th>รูปภาพ</th>
                        <th>ชื่อ</th>
                        <th>สกุล</th>
                        <th>อายุ</th>
                        <th>หมู่บ้าน</th>
                        <th>โรคประจำตัว</th>
                        <th>จัดการ</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if (
                        mysqli_num_rows($result) > 0
                    ): ?>

                        <?php while (
                            $row =
                            mysqli_fetch_assoc($result)
                        ): ?>

                            <tr>
                                <td class="patient-thumb-cell">
                                    <?php $patientPhotoUrl = thonglangUploadedImageUrl($row['Photo'] ?? ''); ?>
                                    <div class="patient-thumb">
                                        <?php if ($patientPhotoUrl !== ''): ?>
                                            <img src="<?= e($patientPhotoUrl) ?>" alt="รูปผู้สูงอายุ">
                                        <?php else: ?>
                                            <span>ไม่มีรูป</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="name-cell"><strong><?= htmlspecialchars(trim((string)($row["Firstname"] ?? "")) !== "" ? $row["Firstname"] : $row["Fullname"]) ?></strong></td>
                                <td class="name-cell"><strong><?= htmlspecialchars(trim((string)($row["Lastname"] ?? "")) !== "" ? $row["Lastname"] : "-") ?></strong></td>
                                <td><span class="age-pill"><?= (int)$row["Age"] ?> ปี</span></td>
                                <td>
                                    <span class="village-pill"><?= !empty($row["villagename"])
                                        ? htmlspecialchars($row["villagename"])
                                        : "ไม่ระบุ" ?></span>
                                </td>
                                <td>
                                    <?php
                                        $diseaseRaw = trim((string)($row["Disease"] ?? ""));
                                        $diseaseItems = $diseaseRaw !== ''
                                            ? preg_split('/\s*[,，;；\r\n]+\s*/u', $diseaseRaw, -1, PREG_SPLIT_NO_EMPTY)
                                            : [];
                                    ?>
                                    <div class="disease-pill-list">
                                        <?php if ($diseaseItems): ?>
                                            <?php foreach ($diseaseItems as $diseaseItem): ?>
                                                <span class="disease-pill"><?= htmlspecialchars(trim((string)$diseaseItem)) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="disease-pill empty">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <a href="patient_history.php?patient_id=<?= (int)$row["Patient_id"] ?>" class="btn btn-info">ดูประวัติ</a>
                                        <a href="patient.php?edit=<?= (int)$row["Patient_id"] ?>" class="btn btn-yellow" data-label="แก้ไข">แก้ไข</a>
                                        <a
                                            href="patient.php?delete=<?= (int)$row["Patient_id"] ?>"
                                            class="btn btn-red" data-label="ลบ"
                                            onclick="return confirm('ต้องการลบข้อมูลนี้หรือไม่?');"
                                        >ลบ</a>
                                    </div>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="7"
                                class="empty"
                            >
                                ไม่พบข้อมูลผู้สูงอายุตามเงื่อนไขที่ค้นหา
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($filteredPatientCount > $perPage): ?>
            <div class="patient-pagination" aria-label="pagination">
                <?php $prevPage = max(1, $currentPage - 1); ?>
                <a class="patient-page-link <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= e(patientPageUrl($prevPage, $filterKeyword, $filterVillage)) ?>">&lt;</a>

                <?php
                $paginationItems = [];
                if ($totalPages <= 7) {
                    for ($i = 1; $i <= $totalPages; $i++) $paginationItems[] = $i;
                } else {
                    $paginationItems[] = 1;
                    if ($currentPage > 3) $paginationItems[] = '...';
                    $startPage = max(2, $currentPage - 1);
                    $endPage = min($totalPages - 1, $currentPage + 1);
                    if ($currentPage <= 3) $endPage = 4;
                    if ($currentPage >= $totalPages - 2) $startPage = $totalPages - 3;
                    for ($i = $startPage; $i <= $endPage; $i++) {
                        if ($i > 1 && $i < $totalPages) $paginationItems[] = $i;
                    }
                    if ($currentPage < $totalPages - 2) $paginationItems[] = '...';
                    $paginationItems[] = $totalPages;
                }
                $lastRendered = null;
                foreach ($paginationItems as $item):
                    if ($item === '...' && $lastRendered === '...') continue;
                    $lastRendered = $item;
                ?>
                    <?php if ($item === '...'): ?>
                        <span class="patient-page-dots">...</span>
                    <?php else: ?>
                        <a class="patient-page-link <?= $currentPage === (int)$item ? 'active' : '' ?>" href="<?= e(patientPageUrl((int)$item, $filterKeyword, $filterVillage)) ?>"><?= (int)$item ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php $nextPage = min($totalPages, $currentPage + 1); ?>
                <a class="patient-page-link <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= e(patientPageUrl($nextPage, $filterKeyword, $filterVillage)) ?>">&gt;</a>
            </div>
        <?php endif; ?>

    </div>

</div>




<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function () {
    const modal = document.getElementById('patientModal');
    const openButton = document.getElementById('openPatientModal');
    const closeButton = document.getElementById('closePatientModal');
    const latitudeInput = document.getElementById('Latitude');
    const longitudeInput = document.getElementById('Longitude');
    const currentLocationButton = document.getElementById('useCurrentPatientLocation');
    const clearLocationButton = document.getElementById('clearPatientLocation');
    let patientMap = null;
    let patientMarker = null;

    if (!modal || !closeButton) return;

    function setPatientCoordinate(lat, lng, moveMap = true) {
        const latValue = Number(lat);
        const lngValue = Number(lng);
        if (!Number.isFinite(latValue) || !Number.isFinite(lngValue)) return;
        const latFixed = latValue.toFixed(7);
        const lngFixed = lngValue.toFixed(7);
        if (latitudeInput) latitudeInput.value = latFixed;
        if (longitudeInput) longitudeInput.value = lngFixed;
        if (patientMap && typeof L !== 'undefined') {
            if (!patientMarker) {
                patientMarker = L.marker([latValue, lngValue], {draggable:true}).addTo(patientMap);
                patientMarker.on('dragend', function (event) {
                    const pos = event.target.getLatLng();
                    setPatientCoordinate(pos.lat, pos.lng, false);
                });
            } else {
                patientMarker.setLatLng([latValue, lngValue]);
            }
            if (moveMap) patientMap.setView([latValue, lngValue], 16);
        }
    }

    function syncPatientCoordinateFromInputs() {
        if (!latitudeInput || !longitudeInput) return;
        const latValue = parseFloat(latitudeInput.value);
        const lngValue = parseFloat(longitudeInput.value);

        if (latitudeInput.value === '' && longitudeInput.value === '') {
            if (patientMarker && patientMap) {
                patientMap.removeLayer(patientMarker);
                patientMarker = null;
            }
            return;
        }

        if (!Number.isFinite(latValue) || !Number.isFinite(lngValue)) return;
        if (latValue < -90 || latValue > 90 || lngValue < -180 || lngValue > 180) return;
        setPatientCoordinate(latValue, lngValue, true);
    }

    if (latitudeInput) {
        latitudeInput.addEventListener('change', syncPatientCoordinateFromInputs);
        latitudeInput.addEventListener('blur', syncPatientCoordinateFromInputs);
    }
    if (longitudeInput) {
        longitudeInput.addEventListener('change', syncPatientCoordinateFromInputs);
        longitudeInput.addEventListener('blur', syncPatientCoordinateFromInputs);
    }

    function initPatientMap() {
        if (patientMap || typeof L === 'undefined' || !document.getElementById('patientLocationMap')) return;
        const defaultCenter = [<?= json_encode((float)systemSetting($conn, 'map_default_lat', '15.045')) ?>, <?= json_encode((float)systemSetting($conn, 'map_default_lng', '102.330')) ?>];
        const defaultZoom = <?= (int)systemSetting($conn, 'map_default_zoom', '13') ?>;
        patientMap = L.map('patientLocationMap', {zoomControl:true, minZoom:11, maxZoom:19}).setView(defaultCenter, defaultZoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19,
            attribution:'&copy; OpenStreetMap contributors'
        }).addTo(patientMap);
        patientMap.on('click', function (event) {
            setPatientCoordinate(event.latlng.lat, event.latlng.lng, false);
        });
        const initialLat = latitudeInput ? parseFloat(latitudeInput.value) : NaN;
        const initialLng = longitudeInput ? parseFloat(longitudeInput.value) : NaN;
        if (Number.isFinite(initialLat) && Number.isFinite(initialLng)) {
            setPatientCoordinate(initialLat, initialLng, true);
        }
    }

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        initPatientMap();
        window.setTimeout(function(){ if (patientMap) patientMap.invalidateSize(); }, 120);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (openButton) openButton.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    if (currentLocationButton) {
        currentLocationButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('เบราว์เซอร์นี้ไม่รองรับการระบุตำแหน่งปัจจุบัน');
                return;
            }
            currentLocationButton.disabled = true;
            const originalText = currentLocationButton.textContent;
            currentLocationButton.textContent = 'กำลังค้นหาตำแหน่ง...';
            navigator.geolocation.getCurrentPosition(function (position) {
                setPatientCoordinate(position.coords.latitude, position.coords.longitude, true);
                currentLocationButton.disabled = false;
                currentLocationButton.textContent = originalText;
            }, function () {
                currentLocationButton.disabled = false;
                currentLocationButton.textContent = originalText;
                alert('ไม่สามารถอ่านตำแหน่งปัจจุบันได้ กรุณาอนุญาตการเข้าถึงตำแหน่งหรือคลิกเลือกบนแผนที่');
            }, {enableHighAccuracy:true, timeout:12000, maximumAge:30000});
        });
    }

    if (clearLocationButton) {
        clearLocationButton.addEventListener('click', function () {
            if (latitudeInput) latitudeInput.value = '';
            if (longitudeInput) longitudeInput.value = '';
            if (latText) latText.textContent = '-';
            if (lngText) lngText.textContent = '-';
            if (patientMap && patientMarker) {
                patientMap.removeLayer(patientMarker);
                patientMarker = null;
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });



    <?php if ($editData || ($_SERVER["REQUEST_METHOD"] === "POST" && $message !== "")): ?>
    openModal();
    <?php endif; ?>
})();
</script>

</body>
</html>