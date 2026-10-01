<?php
require_once __DIR__ . '/connect.php';
ensureThonglangCoreSchema($conn);
requireRole('admin');
mysqli_set_charset($conn, 'utf8mb4');

$patients = [];
$sql = "SELECT p.Patient_id, p.Fullname, p.Age, p.Gender, p.Phone, p.Disease,
               p.Address, p.Latitude, p.Longitude, p.Village_id,
               v.villagename
        FROM patient p
        LEFT JOIN village v ON v.village_id = p.Village_id
        ORDER BY COALESCE(v.villagename,''), p.Fullname, p.Patient_id";
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $patients[] = $row;
    }
}

$thonglangBoundary = [
    ['lat' => 15.0008, 'lng' => 102.3228],
    ['lat' => 15.0060, 'lng' => 102.3152],
    ['lat' => 15.0136, 'lng' => 102.3042],
    ['lat' => 15.0218, 'lng' => 102.2928],
    ['lat' => 15.0284, 'lng' => 102.2873],
    ['lat' => 15.0361, 'lng' => 102.2838],
    ['lat' => 15.0442, 'lng' => 102.2796],
    ['lat' => 15.0510, 'lng' => 102.2821],
    ['lat' => 15.0547, 'lng' => 102.2758],
    ['lat' => 15.0659, 'lng' => 102.2729],
    ['lat' => 15.0725, 'lng' => 102.2765],
    ['lat' => 15.0739, 'lng' => 102.2831],
    ['lat' => 15.0798, 'lng' => 102.2915],
    ['lat' => 15.0825, 'lng' => 102.3011],
    ['lat' => 15.0824, 'lng' => 102.3144],
    ['lat' => 15.0865, 'lng' => 102.3228],
    ['lat' => 15.0872, 'lng' => 102.3303],
    ['lat' => 15.0919, 'lng' => 102.3387],
    ['lat' => 15.0927, 'lng' => 102.3471],
    ['lat' => 15.0880, 'lng' => 102.3587],
    ['lat' => 15.0869, 'lng' => 102.3697],
    ['lat' => 15.0818, 'lng' => 102.3748],
    ['lat' => 15.0784, 'lng' => 102.3818],
    ['lat' => 15.0708, 'lng' => 102.3827],
    ['lat' => 15.0488, 'lng' => 102.3817],
    ['lat' => 15.0329, 'lng' => 102.3761],
    ['lat' => 15.0116, 'lng' => 102.3730],
    ['lat' => 15.0008, 'lng' => 102.3552],
    ['lat' => 14.9970, 'lng' => 102.3381],
    ['lat' => 15.0008, 'lng' => 102.3228],
];

function thonglangPointInPolygon(float $lat, float $lng, array $polygon): bool
{
    $inside = false;
    $count = count($polygon);
    if ($count < 3) {
        return false;
    }

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = (float)$polygon[$i]['lng'];
        $yi = (float)$polygon[$i]['lat'];
        $xj = (float)$polygon[$j]['lng'];
        $yj = (float)$polygon[$j]['lat'];

        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);

        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}

$mapPatients = array_values(array_filter($patients, static function ($row) use ($thonglangBoundary) {
    if ($row['Latitude'] === null || $row['Latitude'] === '' || $row['Longitude'] === null || $row['Longitude'] === '') {
        return false;
    }
    if (!is_numeric($row['Latitude']) || !is_numeric($row['Longitude'])) {
        return false;
    }

    return thonglangPointInPolygon((float)$row['Latitude'], (float)$row['Longitude'], $thonglangBoundary);
}));

$villages = [];
foreach ($mapPatients as $row) {
    $id = (int)($row['Village_id'] ?? 0);
    if ($id > 0 && !isset($villages[$id])) {
        $villages[$id] = trim((string)($row['villagename'] ?? '')) ?: ('หมู่บ้าน #' . $id);
    }
}
asort($villages, SORT_NATURAL | SORT_FLAG_CASE);

$totalPatients = count($patients);
$totalOnMap = count($mapPatients);
$totalMissing = max(0, $totalPatients - $totalOnMap);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>แผนที่ผู้สูงอายุ | <?= e(appName()) ?></title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIINfQ3ynNHDNwEwOQX9xkMZ5IhFQmIYkGc=" crossorigin="">
<?php renderPastelTheme(); ?>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
body.role-page .map-page{display:grid;gap:12px}
.map-head{padding:12px 16px;border:1px solid #d9e6e4;border-radius:18px;background:#fbfdfd}
.map-head h1{margin:0;color:#173f45;font-size:24px;line-height:1.2}
.map-head p{margin:6px 0 0;color:#708582;font-size:13px;line-height:1.45}
.map-summary{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.map-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #d7e8e5;border-radius:999px;background:#fff;color:#36585b;font-size:12px;font-weight:700}
.map-pill strong{font-size:13px;color:#173f45}
.map-shell{border:1px solid #d7e8e5;border-radius:22px;background:#fff;overflow:hidden;box-shadow:none}
.map-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid #e6efee;background:#fbfefe}
.map-toolbar input,.map-toolbar select{min-height:42px;border:1px solid #d2e4e1;border-radius:12px;background:#fff;padding:8px 12px;color:#24464a;font-family:"Noto Sans Thai",sans-serif;outline:none}
.map-toolbar input{flex:1;min-width:260px}
.map-toolbar select{min-width:170px}
.map-toolbar input:focus,.map-toolbar select:focus{border-color:#73c4c1;box-shadow:0 0 0 3px rgba(115,196,193,.14)}
.map-toolbar button{min-height:42px;padding:8px 14px;border:1px solid #cfe3e0;border-radius:12px;background:#eef8f7;color:#1f625f;font-weight:800;cursor:pointer}
.map-canvas-wrap{padding:12px;background:#f7fbfa}
#elderlyMap{width:100%;height:620px;border-radius:18px;overflow:hidden;background:#e7ecec}
#elderlyMap .leaflet-container{width:100%;height:100%}
.leaflet-container{font-family:"Noto Sans Thai",sans-serif;background:#e7ecec}
.leaflet-control-attribution{font-size:11px}
.leaflet-control-attribution a{color:#456c70!important}
.leaflet-control-zoom a{color:#2d5f66!important}
.leaflet-control-layers,.leaflet-bar{border:0!important;box-shadow:0 6px 16px rgba(23,63,69,.15)!important}
.leaflet-bar a{border-bottom:1px solid #e5eceb!important}
.map-note{padding:0 14px 14px;color:#768784;font-size:11px;line-height:1.45}
.popup-name{font-size:16px;font-weight:800;color:#173f45;margin-bottom:4px}.popup-meta{font-size:12px;color:#647b77;margin-top:3px}.popup-link{display:inline-block;margin-top:9px;padding:7px 10px;border-radius:9px;background:#ebf7f5;color:#196c67;text-decoration:none;font-size:12px;font-weight:800}
.elderly-div-icon{background:transparent;border:0}
.elderly-pin{position:relative;width:22px;height:22px;border-radius:50% 50% 50% 0;background:#2a6fbb;transform:rotate(-45deg);box-shadow:0 4px 10px rgba(22,71,117,.30);border:2px solid rgba(255,255,255,.96)}
.elderly-pin:after{content:'';position:absolute;width:6px;height:6px;border-radius:50%;background:#fff;left:6px;top:6px}
@media(max-width:700px){.map-head{padding:12px 14px}.map-head h1{font-size:21px}.map-toolbar input,.map-toolbar select,.map-toolbar button{width:100%}#elderlyMap{height:500px}}

.map-toolbar-outside{margin:0 0 14px;background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important}.map-toolbar-outside input{background:#fff!important}
</style>
</head>
<body class="role-page">
<?php renderSidebar(); ?>
<div class="main">
    <?php renderUserTopbar(); ?>
    <div class="map-page">
        <section class="map-head">
            <h1>แผนที่ผู้สูงอายุ</h1>
            <p>รูปแบบแผนที่โฟกัสเฉพาะตำบลทองหลาง อำเภอจักราช จังหวัดนครราชสีมา พร้อมแสดงหมุดผู้สูงอายุภายในพื้นที่</p>
            <div class="map-summary">
                <span class="map-pill">ทั้งหมด <strong><?= $totalPatients ?></strong></span>
                <span class="map-pill">แสดงบนแผนที่ <strong><?= $totalOnMap ?></strong></span>
                <span class="map-pill">ไม่มีพิกัด/นอกเขต <strong><?= $totalMissing ?></strong></span>
            </div>
        </section>

        <div class="map-toolbar map-toolbar-outside">
            <input type="search" id="mapSearch" placeholder="ค้นหาชื่อผู้สูงอายุ..." autocomplete="off">
            <select id="villageFilter">
                <option value="">ทุกหมู่บ้าน</option>
                <?php foreach ($villages as $villageId => $villageName): ?>
                    <option value="<?= (int)$villageId ?>"><?= e($villageName) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="resetMap">แสดงทั้งหมด</button>
        </div>

        <section class="map-shell">
            <div class="map-canvas-wrap">
                <div id="elderlyMap" aria-label="แผนที่ตำแหน่งผู้สูงอายุ"></div>
            </div>
            <div class="map-note">หมายเหตุ: แผนที่จะแสดงเฉพาะผู้สูงอายุที่มีพิกัดและอยู่ภายในขอบเขตตำบลทองหลาง</div>
        </section>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function(){
    const patients = <?= json_encode(array_map(static function($r){
        return [
            'id'=>(int)$r['Patient_id'],
            'name'=>trim((string)($r['Fullname'] ?? '')) ?: ('ผู้สูงอายุ #'.(int)$r['Patient_id']),
            'age'=>$r['Age'] ?? '',
            'gender'=>$r['Gender'] ?? '',
            'phone'=>$r['Phone'] ?? '',
            'disease'=>$r['Disease'] ?? '',
            'address'=>$r['Address'] ?? '',
            'village_id'=>(int)($r['Village_id'] ?? 0),
            'village'=>$r['villagename'] ?? '',
            'lat'=>(float)$r['Latitude'],
            'lng'=>(float)$r['Longitude']
        ];
    }, $mapPatients), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const thonglangBoundary = <?= json_encode(array_map(static function($point){ return [(float)$point['lat'], (float)$point['lng']]; }, $thonglangBoundary), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const search = document.getElementById('mapSearch');
    const village = document.getElementById('villageFilter');
    const reset = document.getElementById('resetMap');
    const mapWrap = document.getElementById('elderlyMap');

    if (typeof L === 'undefined') {
        mapWrap.innerHTML = '<div style="padding:30px;text-align:center;color:#6f8581">ไม่สามารถโหลดแผนที่ได้ กรุณาตรวจสอบการเชื่อมต่ออินเทอร์เน็ต</div>';
        return;
    }

    const map = L.map('elderlyMap', {
        zoomControl: true,
        minZoom: 11,
        maxZoom: 18,
        preferCanvas: true
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        subdomains: 'abcd',
        maxZoom: 20,
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
    }).addTo(map);

    const tambonPolygon = L.polygon(thonglangBoundary, {
        stroke: false,
        fill: false,
        interactive: false
    });
    const tambonBounds = tambonPolygon.getBounds();

    const layer = L.layerGroup().addTo(map);
    const markers = new Map();
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const markerIcon = L.divIcon({
        className: 'elderly-div-icon',
        html: '<div class="elderly-pin"></div>',
        iconSize: [22,22],
        iconAnchor: [11,21],
        popupAnchor: [0,-20]
    });

    patients.forEach(p => {
        const popup = '<div class="popup-name">'+escapeHtml(p.name)+'</div>'+
            '<div class="popup-meta">อายุ '+escapeHtml(p.age || '-')+' ปี'+(p.village ? ' · '+escapeHtml(p.village) : '')+'</div>'+
            (p.phone ? '<div class="popup-meta">โทร. '+escapeHtml(p.phone)+'</div>' : '')+
            (p.disease ? '<div class="popup-meta">โรคประจำตัว: '+escapeHtml(p.disease)+'</div>' : '')+
            (p.address ? '<div class="popup-meta">'+escapeHtml(p.address)+'</div>' : '')+
            '<a class="popup-link" target="_blank" rel="noopener" href="https://www.google.com/maps?q='+encodeURIComponent(p.lat+','+p.lng)+'">เปิดใน Google Maps</a>';
        const marker = L.marker([p.lat, p.lng], {icon: markerIcon}).bindPopup(popup);
        markers.set(p.id, marker);
    });

    function showTambonView(){
        map.fitBounds(tambonBounds, {padding:[18,18], maxZoom:14});
    }

    function refit(visiblePatients){
        layer.clearLayers();
        visiblePatients.forEach(p => {
            const marker = markers.get(p.id);
            if (marker) marker.addTo(layer);
        });

        if (visiblePatients.length === 1) {
            map.setView([visiblePatients[0].lat, visiblePatients[0].lng], 15);
            return;
        }

        if (visiblePatients.length >= 2 && visiblePatients.length <= 6) {
            const bounds = L.latLngBounds(visiblePatients.map(p => [p.lat, p.lng]));
            map.fitBounds(bounds.pad(0.18), {padding:[18,18], maxZoom:15});
            return;
        }

        showTambonView();
    }

    function applyFilter(){
        const q = (search.value || '').trim().toLocaleLowerCase('th-TH');
        const v = village.value;
        const visible = patients.filter(p => (!q || p.name.toLocaleLowerCase('th-TH').includes(q)) && (!v || String(p.village_id) === v));
        refit(visible);
    }

    function ensureMapSize(){
        map.invalidateSize();
        applyFilter();
    }

    search.addEventListener('input', applyFilter);
    village.addEventListener('change', applyFilter);
    reset.addEventListener('click', function(){
        search.value = '';
        village.value = '';
        showTambonView();
        refit(patients);
    });

    window.addEventListener('load', ensureMapSize);
    window.addEventListener('resize', ensureMapSize);

    showTambonView();
    refit(patients);
    requestAnimationFrame(ensureMapSize);
    setTimeout(ensureMapSize, 180);
    setTimeout(ensureMapSize, 500);
})();
</script>
</body>
</html>
