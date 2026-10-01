/* Direct one-page A4 PDF. Layout is deliberately spread across the full A4 page. */
(function () {
  'use strict';
  const WIDTH = 1240, HEIGHT = 1754;
  const dark = '#111111', muted = '#444444', line = '#666666';
  const fontStack = '"Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
  const fmt = n => Number(n || 0).toLocaleString('en-US');

  function font(ctx, size, bold) {
    ctx.font = (bold ? '800 ' : '500 ') + size + 'px ' + fontStack;
  }
  function txt(ctx, value, x, y, size = 20, color = dark, bold = false, align = 'left') {
    ctx.fillStyle = color;
    font(ctx, size, bold);
    ctx.textAlign = align;
    ctx.textBaseline = 'top';
    ctx.fillText(String(value ?? ''), x, y);
  }
  function cut(ctx, value, maxWidth) {
    let v = String(value ?? '');
    if (ctx.measureText(v).width <= maxWidth) return v;
    while (v.length > 1 && ctx.measureText(v + '…').width > maxWidth) v = v.slice(0, -1);
    return v + '…';
  }
  function wrap(ctx, text, maxWidth, size = 20, bold = false) {
    font(ctx, size, bold);
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';
    for (const word of words) {
      const test = current ? current + ' ' + word : word;
      if (!current || ctx.measureText(test).width <= maxWidth) current = test;
      else { lines.push(current); current = word; }
    }
    if (current) lines.push(current);
    return lines;
  }
  function hr(ctx, x1, y, x2, width = 1.25) {
    ctx.strokeStyle = line;
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(x1, y);
    ctx.lineTo(x2, y);
    ctx.stroke();
  }
  function section(ctx, no, title, y, L, R) {
    txt(ctx, no + '. ' + title, L, y, 27, dark, true);
    hr(ctx, L, y + 41, R, 1.5);
  }
  function kvPair(ctx, label, value, unit, x, y, width) {
    txt(ctx, label, x, y, 22, dark, true);
    txt(ctx, fmt(value), x + width - 68, y, 24, dark, true, 'right');
    txt(ctx, unit, x + width, y + 2, 19, dark, false, 'right');
  }
  function summaryRow(ctx, label, detail, x, y, width) {
    const labelWidth = 205;
    txt(ctx, label, x, y, 19, dark, true);
    const detailX = x + labelWidth;
    const detailWidth = width - labelWidth;
    const lines = wrap(ctx, detail, detailWidth, 18, false);
    lines.forEach((lineText, i) => txt(ctx, lineText, detailX, y + i * 26, 18, dark, false));
    return Math.max(32, lines.length * 26 + 4);
  }

  function makeCanvas(data) {
    const canvas = document.createElement('canvas');
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    const ctx = canvas.getContext('2d', { alpha: false });
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, WIDTH, HEIGHT);

    const L = 70, R = WIDTH - 70, centerX = WIDTH / 2;
    const leftX = L, rightX = 650, blockWidth = 500;

    // Header
    txt(ctx, cut(ctx, data.org || 'Thonglang', 520), centerX, 44, 26, dark, true, 'center');
    txt(ctx, cut(ctx, data.title || 'รายงานสรุปข้อมูลผู้สูงอายุ', 1030), centerX, 92, 45, dark, true, 'center');
    if (data.showDate) txt(ctx, 'ข้อมูล ณ วันที่ ' + (data.date || ''), centerX, 153, 19, muted, false, 'center');
    hr(ctx, L, 193, R, 2.1);

    // 1. Overview
    section(ctx, 1, 'ภาพรวมข้อมูลผู้สูงอายุ', 224, L, R);
    kvPair(ctx, 'ผู้สูงอายุทั้งหมด', data.totalPatients, 'คน', leftX, 286, blockWidth);
    kvPair(ctx, 'หมอในระบบ', data.doctors, 'คน', rightX, 286, blockWidth);
    kvPair(ctx, 'หมู่บ้านในระบบ', data.totalVillages, 'แห่ง', leftX, 343, blockWidth);
    kvPair(ctx, 'แคร์กิฟเวอร์', data.caregivers, 'คน', rightX, 343, blockWidth);

    // 2. Assignment and assessment
    section(ctx, 2, 'สถานะการมอบหมายและการประเมิน', 418, L, R);
    kvPair(ctx, 'มอบหมายผู้ดูแลแล้ว', data.totalAssigned, 'คน', leftX, 480, blockWidth);
    kvPair(ctx, 'ยังรอมอบหมาย', data.unassigned, 'คน', rightX, 480, blockWidth);
    kvPair(ctx, 'ประเมิน ADL แล้ว', data.totalAssessed, 'คน', leftX, 537, blockWidth);
    kvPair(ctx, 'ยังไม่ประเมิน ADL', data.unassessed, 'คน', rightX, 537, blockWidth);

    // 3. ADL summary
    section(ctx, 3, 'สรุปผลการประเมิน ADL ล่าสุด', 612, L, R);
    let adlY = 672;
    (data.adl || []).slice(0, 4).forEach(item => {
      const pct = data.totalPatients > 0 ? (100 * Number(item.value || 0) / data.totalPatients) : 0;
      txt(ctx, item.label, L + 6, adlY, 22, dark, true);
      txt(ctx, fmt(item.value) + ' คน', centerX + 120, adlY, 22, dark, true, 'right');
      txt(ctx, pct.toFixed(1) + '%', R - 8, adlY, 22, dark, true, 'right');
      adlY += 48;
    });

    // 4. Villages
    section(ctx, 4, 'จำนวนผู้สูงอายุจำแนกตามหมู่บ้าน', 890, L, R);
    const villages = (data.villages || []).slice(0, 10);
    const left = villages.slice(0, 5), right = villages.slice(5, 10);
    const villageRightX = 650, rowStep = 46, villageStartY = 954;
    left.forEach((v, i) => {
      const yy = villageStartY + i * rowStep;
      txt(ctx, String(i + 1), L + 6, yy, 20, dark, true);
      txt(ctx, cut(ctx, v.name, 340), L + 46, yy, 20, dark, true);
      txt(ctx, fmt(v.total) + ' คน', 566, yy, 20, dark, true, 'right');
    });
    right.forEach((v, i) => {
      const yy = villageStartY + i * rowStep;
      txt(ctx, String(i + 6), villageRightX + 6, yy, 20, dark, true);
      txt(ctx, cut(ctx, v.name, 340), villageRightX + 46, yy, 20, dark, true);
      txt(ctx, fmt(v.total) + ' คน', R, yy, 20, dark, true, 'right');
    });
    if (!villages.length) txt(ctx, 'ยังไม่มีข้อมูลหมู่บ้าน', L + 6, villageStartY, 20, muted, false);

    // 5. Results summary - aligned as readable rows rather than a dense paragraph.
    section(ctx, 5, 'สรุปผลการดำเนินงาน', 1204, L, R);
    let summaryY = 1266;
    const rows = Array.isArray(data.summaryRows) && data.summaryRows.length
      ? data.summaryRows
      : [{ label: 'สรุป', text: data.summaryNarrative || '' }];
    rows.forEach(row => {
      summaryY += summaryRow(ctx, row.label || '', row.text || '', L + 6, summaryY, R - L - 12) + 6;
    });

    if (data.dataWarnings && data.dataWarnings.length) {
      txt(ctx, 'หมายเหตุ: ข้อมูลบางส่วนยังไม่ครบ กรุณาตรวจสอบข้อมูลในระบบก่อนใช้รายงานนี้', L + 6, summaryY + 6, 15, muted);
      summaryY += 30;
    }

    // Signatures near the bottom, after the summary, making the entire A4 page feel balanced.
    const signatures = Array.isArray(data.signatureRoles) && data.signatureRoles.length ? data.signatureRoles : ['หมอ', 'ผู้อำนวยการ'];
    const sigY = Math.max(1510, Math.min(1580, summaryY + 48));
    const gap = signatures.length === 3 ? 24 : 44;
    const sigW = (R - L - gap * (signatures.length - 1)) / signatures.length;
    signatures.forEach((role, i) => {
      const x = L + i * (sigW + gap);
      hr(ctx, x + 34, sigY + 42, x + sigW - 34, 1.2);
      txt(ctx, role, x + sigW / 2, sigY + 56, 18, dark, true, 'center');
      txt(ctx, 'วันที่ ........../........../..........', x + sigW / 2, sigY + 84, 15, muted, false, 'center');
    });

    hr(ctx, L, 1685, R, 1);
    txt(ctx, 'เอกสารสรุปจากข้อมูลที่บันทึกในระบบ ณ วันที่ ' + (data.date || ''), L, 1699, 14, muted);
    txt(ctx, 'หน้า 1 / 1', R, 1699, 14, muted, false, 'right');
    return canvas;
  }

  function pdfFromJpeg(jpegUrl) {
    const encoded = jpegUrl.slice(jpegUrl.indexOf(',') + 1);
    const raw = atob(encoded), jpeg = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) jpeg[i] = raw.charCodeAt(i);
    const encoder = new TextEncoder(), encode = s => encoder.encode(s);
    const parts = [], offsets = [0]; let position = 0;
    const add = bytes => { parts.push(bytes); position += bytes.length; };
    const start = id => { offsets[id] = position; add(encode(id + ' 0 obj\n')); };
    const stream = 'q\n595.276 0 0 841.89 0 0 cm\n/Im0 Do\nQ\n';
    add(encode('%PDF-1.4\n%PDFIMAGE\n'));
    start(1); add(encode('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'));
    start(2); add(encode('<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'));
    start(3); add(encode('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.276 841.89] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>\nendobj\n'));
    start(4); add(encode('<< /Length ' + encode(stream).length + ' >>\nstream\n' + stream + 'endstream\nendobj\n'));
    start(5); add(encode('<< /Type /XObject /Subtype /Image /Width ' + WIDTH + ' /Height ' + HEIGHT + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length + ' >>\nstream\n'));
    add(jpeg); add(encode('\nendstream\nendobj\n'));
    const xref = position; add(encode('xref\n0 6\n0000000000 65535 f \n'));
    for (let i = 1; i <= 5; i++) add(encode(String(offsets[i]).padStart(10, '0') + ' 00000 n \n'));
    add(encode('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF'));
    return new Blob(parts, { type: 'application/pdf' });
  }

  async function download() {
    const btn = document.getElementById('download-statistics-pdf');
    if (!btn) return;
    btn.disabled = true;
    try {
      const raw = document.getElementById('statistics-pdf-data');
      if (!raw) throw new Error('ไม่พบข้อมูลสำหรับออกรายงาน');
      const data = JSON.parse(raw.textContent);
      if (document.fonts && document.fonts.ready) await document.fonts.ready;
      const canvas = makeCanvas(data);
      const blob = pdfFromJpeg(canvas.toDataURL('image/jpeg', .96));
      const name = 'รายงานสรุปผู้สูงอายุ_' + new Date().toISOString().slice(0, 10) + '.pdf';
      const url = URL.createObjectURL(blob), link = document.createElement('a');
      link.href = url;
      link.download = name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (err) {
      // Keep the page usable even if PDF creation fails.
    } finally {
      btn.disabled = false;
    }
  }

  document.getElementById('download-statistics-pdf')?.addEventListener('click', download);
})();
