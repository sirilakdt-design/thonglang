/* ดาวน์โหลดสรุปเข้าเยี่ยมให้ตรงกับหน้ากระดาษ A4 (ข้อมูลชุดเดียวกัน)
   ฟอนต์ไทยจาก browser canvas, PDF ไม่พึ่ง CDN และไม่สร้างหน้าว่างซ้ำ */
(function (global) {
  'use strict';
  const W = 1240, H = 1754, LEFT = 84, RIGHT = 1156;
  const utf8 = new TextEncoder();
  const bytes = text => utf8.encode(text);

  function pdfFromJpegs(jpegs) {
    const chunks = [], offsets = [0]; let length = 0;
    const add = data => { chunks.push(data); length += data.length; };
    const addText = text => add(bytes(text));
    const begin = n => { offsets[n] = length; addText(n + ' 0 obj\n'); };
    const count = jpegs.length;
    addText('%PDF-1.4\n%\x80\x81\x82\x83\n');
    begin(1); addText('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');
    begin(2); addText('<< /Type /Pages /Kids [' + jpegs.map((_, i) => (3 + 3*i) + ' 0 R').join(' ') + '] /Count ' + count + ' >>\nendobj\n');
    jpegs.forEach((url, i) => {
      const p = 3 + i*3, c = p+1, im = p+2;
      const stream = 'q\n595 0 0 842 0 0 cm\n/Im0 Do\nQ\n';
      const raw = atob(url.split(',')[1]);
      const jpg = new Uint8Array(raw.length);
      for (let j = 0; j < raw.length; j++) jpg[j] = raw.charCodeAt(j);
      begin(p); addText('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 ' + im + ' 0 R >> >> /Contents ' + c + ' 0 R >>\nendobj\n');
      begin(c); addText('<< /Length ' + bytes(stream).length + ' >>\nstream\n' + stream + 'endstream\nendobj\n');
      begin(im); addText('<< /Type /XObject /Subtype /Image /Width '+W+' /Height '+H+' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '+jpg.length+' >>\nstream\n');
      add(jpg); addText('\nendstream\nendobj\n');
    });
    const xref = length, total = 3 + 3*count;
    addText('xref\n0 ' + total + '\n0000000000 65535 f \n');
    for (let i=1;i<total;i++) addText(String(offsets[i]).padStart(10,'0') + ' 00000 n \n');
    addText('trailer\n<< /Size '+total+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF');
    return new Blob(chunks, {type:'application/pdf'});
  }

  function tokenize(text) {
    const t = String(text ?? '');
    if (global.Intl && Intl.Segmenter) {
      return Array.from(new Intl.Segmenter('th', {granularity:'word'}).segment(t), part => part.segment);
    }
    return Array.from(t);
  }
  function wrap(ctx, text, width) {
    const lines=[];
    for (const para of String(text ?? '').split(/\r?\n/)) {
      let line='';
      for (const word of tokenize(para)) {
        if (ctx.measureText(line+word).width <= width) { line += word; continue; }
        if (line) { lines.push(line.trim()); line=''; }
        if (ctx.measureText(word).width <= width) { line=word.trimStart(); continue; }
        let part='';
        for (const ch of Array.from(word)) {
          if (part && ctx.measureText(part+ch).width > width) { lines.push(part);part=''; }
          part+=ch;
        }
        line=part;
      }
      lines.push(line.trim() || ' ');
    }
    return lines;
  }

  async function downloadVisitReportPdf(report) {
    if (!report || !report.preview) throw new Error('ไม่พบข้อมูลสรุปสำหรับสร้าง PDF');
    if (document.fonts && document.fonts.ready) await document.fonts.ready;
    const p = report.preview, images=[];
    let canvas, ctx, y=0;
    const ink='#111111', muted='#444444', thin='#444444';
    function startPage(first) {
      canvas=document.createElement('canvas');canvas.width=W;canvas.height=H;
      ctx=canvas.getContext('2d',{alpha:false});
      ctx.fillStyle='#fff';ctx.fillRect(0,0,W,H);ctx.textBaseline='top';ctx.textAlign='left';
      y=first?94:115;
      if (!first) {
        center(report.title||'รายงานสรุปการเข้าเยี่ยมผู้สูงอายุ',y,29,true);y+=58;
        stroke(y);y+=28;
      }
    }
    function pushPage(){images.push(canvas.toDataURL('image/jpeg',.94));}
    function lineFont(size=21,bold=false){ctx.font=(bold?'700 ':'400 ')+size+'px "Leelawadee UI","Noto Sans Thai",Tahoma,sans-serif';}
    function text(s,x,yy,size=21,bold=false,color=ink){lineFont(size,bold);ctx.fillStyle=color;ctx.fillText(String(s??''),x,yy);}
    function center(s,yy,size=21,bold=false){ctx.textAlign='center';text(s,W/2,yy,size,bold);ctx.textAlign='left';}
    function stroke(yy){ctx.fillStyle=thin;ctx.fillRect(LEFT,yy,RIGHT-LEFT,1);}
    function space(height) {
      if (y + height > 1510) { pushPage();startPage(false); }
    }
    function linesFor(s,w,size=21,bold=false){lineFont(size,bold);return wrap(ctx,s,w);}
    function drawLines(lines,x,size=21,lh=31,bold=false,color=ink){
      for(const ln of lines){space(lh);text(ln,x,y,size,bold,color);y+=lh;}
    }
    function section(title){space(92);y+=24;text(title,LEFT,y,32,true);y+=46;stroke(y);y+=17;}
    function doubleRows(rows){
      for(const row of rows){
        const [l1,v1,l2,v2]=row;
        const a=linesFor(l1,180,20,true), b=linesFor(v1,330,20),
              c=linesFor(l2,160,20,true), d=linesFor(v2,286,20);
        const height=Math.max(a.length,b.length,c.length,d.length)*32+5;
        space(height);
        const base=y;
        const draw=(arr,x,bold)=>arr.forEach((line,i)=>text(line,x,base+32*i,20,bold));
        draw(a,LEFT,true);draw(b,LEFT+185,false);
        draw(c,LEFT+554,true);draw(d,LEFT+722,false);
        y+=height;
      }
    }
    function simpleRows(rows){
      for (const [label,value] of rows){
        const a=linesFor(label,250,20,true),b=linesFor(value,780,20);
        const height=Math.max(a.length,b.length)*32+6;
        space(height);
        const base=y;
        a.forEach((ln,i)=>text(ln,LEFT,base+i*32,20,true));
        b.forEach((ln,i)=>text(ln,LEFT+260,base+i*32,20));
        y+=height;
      }
    }
    function paragraph(value) {
      const lines=linesFor(value,RIGHT-LEFT,20,false);
      drawLines(lines,LEFT,20,32,false);y+=5;
    }
    function signatures(){
      // Reserve the bottom signature band on the current A4 page when it fits.
      // The old room(180) check incorrectly pushed an otherwise complete report to page 2.
      if (y > 1490) { pushPage(); startPage(false); }
      const lineY=Math.max(1575,y+55);
      const entries=[{x:LEFT+260,name:report.doctor||'หมอ',role:'หมอ'},
                     {x:RIGHT-260,name:report.director||'ผู้อำนวยการ',role:'ผู้อำนวยการ'}];
      for(const e of entries){
        ctx.fillStyle='#333';ctx.fillRect(e.x-185,lineY,370,1);
        ctx.textAlign='center';text(e.name,e.x,lineY+13,20,true);text(e.role,e.x,lineY+47,20);ctx.textAlign='left';
      }
    }
    startPage(true);
    // หัวเรื่องใช้ลำดับเดียวกับหน้าก่อนพิมพ์: ชื่อรายงาน แล้วจึงชื่อระบบ (ไม่มีบรรทัดวันที่ซ้ำ)
    center(report.title || 'รายงานสรุปการเข้าเยี่ยมผู้สูงอายุ',y,37,true);y+=51;
    center(report.org || 'ระบบบันทึกสุขภาพผู้สูงอายุ Thonglang',y,22);y+=42;
    text(p.visit_date,LEFT,y,18);ctx.textAlign='right';text(p.report_date,RIGHT,y,18);ctx.textAlign='left';y+=40;
    section(p.patient_title||'ข้อมูลผู้สูงอายุ');
    doubleRows(p.patient_rows||[]);
    section(p.visit_title||'สรุปผลการเข้าเยี่ยม');
    simpleRows(p.visit_rows||[]);
    if(p.summary){y+=7;paragraph(p.summary);}
    section(p.highlight_title||'ประเด็นสำคัญจากการเข้าเยี่ยม');
    simpleRows(p.highlight_rows||[]);
    section(p.follow_title||'ข้อเสนอแนะและแนวทางติดตาม');
    simpleRows(p.follow_rows||[]);
    for(const item of (p.follow_bullets||[])){
      const wrapped=linesFor('• '+item,RIGHT-LEFT-10,20);
      drawLines(wrapped,LEFT+6,20,30);y+=3;
    }
    signatures();pushPage();
    const blob=pdfFromJpegs(images),url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download=String(report.filename||'รายงานเข้าเยี่ยม.pdf').replace(/[\\/:*?"<>|]/g,'_');
    document.body.appendChild(a);a.click();a.remove();global.setTimeout(()=>URL.revokeObjectURL(url),45000);
    return blob;
  }
  global.downloadVisitReportPdf=downloadVisitReportPdf;
})(window);
