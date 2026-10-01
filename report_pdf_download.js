/* Safe offline A4 PDF downloader for Thonglang ADL reports.
   Uses direct canvas drawing to avoid browser tainted-canvas errors. */
(function(global){
  'use strict';

  const W = 1240;
  const H = 1754;
  const LEFT = 78;
  const RIGHT = 78;
  const encoder = new TextEncoder();
  const bytes = s => encoder.encode(s);

  function encodePdf(jpeg,imgWidth=W,imgHeight=H){
    const base64 = jpeg.split(',')[1];
    const raw = atob(base64);
    const img = new Uint8Array(raw.length);
    for(let i=0;i<raw.length;i++) img[i] = raw.charCodeAt(i);

    const stream = 'q\n595 0 0 842 0 0 cm\n/Im0 Do\nQ\n';
    const parts = [];
    let size = 0;
    const offsets = [0];
    function add(x){ parts.push(x); size += x.length; }
    function start(n){ offsets[n] = size; add(bytes(n + ' 0 obj\n')); }

    add(bytes('%PDF-1.4\n%\x80\x81\x82\x83\n'));
    start(1); add(bytes('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'));
    start(2); add(bytes('<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'));
    start(3); add(bytes('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>\nendobj\n'));
    start(4); add(bytes('<< /Length ' + bytes(stream).length + ' >>\nstream\n' + stream + 'endstream\nendobj\n'));
    start(5); add(bytes('<< /Type /XObject /Subtype /Image /Width ' + W + ' /Height ' + H + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + img.length + ' >>\nstream\n'));
    add(img); add(bytes('\nendstream\nendobj\n'));

    const xref = size;
    add(bytes('xref\n0 6\n0000000000 65535 f \n'));
    for(let i=1;i<=5;i++) add(bytes(String(offsets[i]).padStart(10,'0') + ' 00000 n \n'));
    add(bytes('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF'));
    return new Blob(parts,{type:'application/pdf'});
  }

  function saveBlob(blob, filename){
    const a = document.createElement('a');
    const url = URL.createObjectURL(blob);
    a.href = url;
    a.download = String(filename || 'สรุป_ADL.pdf').replace(/[\\/:*?"<>|]/g,'_');
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 45000);
  }

  function font(ctx,size,bold){
    ctx.font = (bold ? '700 ' : '400 ') + size + 'px "Leelawadee UI", "TH Sarabun New", Tahoma, sans-serif';
  }

  function segmentWords(str){
    str = String(str ?? '');
    if(global.Intl && Intl.Segmenter){
      const segmenter = new Intl.Segmenter('th',{granularity:'word'});
      return [...segmenter.segment(str)].map(x => x.segment);
    }
    return Array.from(str);
  }

  function wrapLines(ctx,text,maxWidth){
    const result = [];
    for(const paragraph of String(text ?? '-').split(/\n/)){
      let line = '';
      for(const word of segmentWords(paragraph)){
        if(line && ctx.measureText(line + word).width > maxWidth){
          result.push(line.trim());
          line = word.trimStart();
        }else{
          line += word;
        }
      }
      result.push(line.trim() || ' ');
    }
    return result;
  }

  function paragraph(ctx,text,x,y,maxWidth,size,lineHeight,bold,maxY){
    font(ctx,size,bold);
    const rows = wrapLines(ctx,text,maxWidth);
    for(const row of rows){
      if(y + lineHeight > maxY) break;
      ctx.fillText(row,x,y);
      y += lineHeight;
    }
    return y;
  }

  function drawSectionTitle(ctx,title,y){
    ctx.fillStyle = '#000000';
    font(ctx,38,true);
    ctx.fillText(String(title),LEFT,y);
    const lineY = y + 40;
    ctx.strokeStyle = '#333333';
    ctx.lineWidth = 1.3;
    ctx.beginPath();
    ctx.moveTo(LEFT,lineY);
    ctx.lineTo(W - RIGHT,lineY);
    ctx.stroke();
    return y + 66;
  }

  function drawLabelValue(ctx,label,value,xLabel,xValue,y,maxWidth){
    ctx.fillStyle = '#000000';
    font(ctx,26,true);
    ctx.fillText(String(label),xLabel,y);
    font(ctx,26,false);
    return paragraph(ctx,String(value || '-'),xValue,y,maxWidth,26,44,false,1540);
  }


  function line(ctx,x1,y1,x2,y2,color,width){
    ctx.strokeStyle=color||'#c8d8d4';ctx.lineWidth=width||1;
    ctx.beginPath();ctx.moveTo(x1,y1);ctx.lineTo(x2,y2);ctx.stroke();
  }

  function drawAssessmentSummary(ctx,data){
    const L=58,R=W-58, contentW=R-L;
    const patient=data.patient||{};
    const adl=Array.isArray(data.adl_items)?data.adl_items:[];
    const health=Array.isArray(data.health_items)?data.health_items:[];
    const follow=Array.isArray(data.followup_items)?data.followup_items:[];
    const title=String(data.title||'รายงานสรุปผลการประเมินผู้สูงอายุ');
    const orgRaw=String(data.org||'').trim();
    const org=orgRaw.startsWith('ระบบ')?orgRaw:('ระบบบันทึกสุขภาพผู้สูงอายุ '+orgRaw).trim();
    const assessed=String(patient.assessed_at||'-').replace(/^วันที่ประเมิน\s*/,'');
    const reportDate=String(data.report_date||'-').replace(/^วันที่ออกรายงาน\s*/,'');

    // Fill the A4 page intentionally: readable type, compact side margins,
    // and vertical spacing distributed down the full sheet (one page only).
    ctx.fillStyle='#ffffff';ctx.fillRect(0,0,W,H);ctx.textBaseline='top';ctx.textAlign='left';
    let y=66;
    ctx.textAlign='center';ctx.fillStyle='#173f3d';font(ctx,36,true);ctx.fillText(title,W/2,y);y+=50;
    ctx.fillStyle='#768784';font(ctx,16,false);ctx.fillText(org,W/2,y);y+=45;
    ctx.textAlign='left';ctx.fillStyle='#536a67';font(ctx,12,false);ctx.fillText('วันที่ประเมิน '+assessed,L,y);
    ctx.textAlign='right';ctx.fillText('วันที่ออกรายงาน '+reportDate,R,y);ctx.textAlign='left';y+=38;

    function sectionTitle(text){
      ctx.fillStyle='#173f3d';font(ctx,21,true);ctx.fillText(text,L,y);y+=33;
      line(ctx,L,y,R,y,'#314746',1.4);y+=16;
    }
    function infoCell(label,value,x,w,yy){
      ctx.fillStyle='#6f817d';font(ctx,11,false);ctx.fillText(label,x,yy);
      ctx.fillStyle='#173f3d';font(ctx,13,true);
      const rows=wrapLines(ctx,String(value||'-'),w);
      rows.slice(0,2).forEach((r,i)=>ctx.fillText(r,x,yy+18+i*15));
    }

    sectionTitle('ข้อมูลผู้สูงอายุ');
    const infoGap=30, infoW=(contentW-infoGap*2)/3;
    const infoRows=[
      [
        ['ชื่อ–นามสกุล',patient.fullname||'-'],['อายุ',(patient.age||'-')+' ปี'],['เพศ',patient.gender||'-']
      ],
      [
        ['หมู่บ้าน',patient.village||'-'],['รอบการประเมิน',patient.round||'-'],['ผู้ประเมิน',patient.assessor||data.caregiver||'-']
      ]
    ];
    infoRows.forEach(row=>{
      row.forEach((it,i)=>infoCell(it[0],it[1],L+i*(infoW+infoGap),infoW,y));
      y+=48;
    });
    y+=10;

    sectionTitle('สรุปผลการประเมิน ADL');
    const adlGap=50, adlW=(contentW-adlGap)/2;
    ctx.fillStyle='#6f817d';font(ctx,11,false);ctx.fillText('คะแนนรวม',L,y);ctx.fillText('ผลการจัดกลุ่ม',L+adlW+adlGap,y);
    ctx.fillStyle='#173f3d';font(ctx,14,true);ctx.fillText(String(data.score??0)+'/20 คะแนน',L+128,y-2);ctx.fillText(String(data.group||'-'),L+adlW+adlGap+145,y-2);y+=39;
    const left=[],right=[];
    adl.forEach((it,i)=>((i%2===0)?left:right).push({label:(i+1)+'. '+String(it.label||'-'),score:it.score??'-'}));
    for(let r=0;r<Math.max(left.length,right.length);r++){
      [left[r],right[r]].forEach((it,side)=>{
        if(!it)return;const x=L+side*(adlW+adlGap);
        ctx.fillStyle='#173f3d';font(ctx,12.5,false);ctx.fillText(it.label,x,y);
        ctx.textAlign='right';font(ctx,13.5,true);ctx.fillText(String(it.score),x+adlW-3,y);ctx.textAlign='left';
        line(ctx,x,y+29,x+adlW,y+29,'#d4dfdc',1);
      });
      y+=45;
    }
    y+=12;

    sectionTitle('สรุปผลการประเมินภาวะสุขภาพ');
    const hc=3,hgap=30,hw=(contentW-hgap*(hc-1))/hc,hrow=67;
    health.forEach((it,i)=>{
      const row=Math.floor(i/hc),col=i%hc,x=L+col*(hw+hgap),yy=y+row*hrow;
      ctx.fillStyle='#72847f';font(ctx,10.5,false);ctx.fillText(String(it.label||'-'),x,yy);
      ctx.fillStyle='#173f3d';font(ctx,12.5,true);
      const vals=wrapLines(ctx,String(it.value||'-'),hw-4);vals.slice(0,2).forEach((v,j)=>ctx.fillText(v,x,yy+19+j*15));
      line(ctx,x,yy+49,x+hw,yy+49,'#d5e0dd',1);
    });
    y+=Math.ceil(health.length/hc)*hrow+12;

    sectionTitle('สรุปผลและข้อมูลประกอบ');
    const fgap=38,fw=(contentW-fgap)/2,frow=56;
    follow.forEach((it,i)=>{
      const row=Math.floor(i/2),col=i%2,x=L+col*(fw+fgap),yy=y+row*frow;
      ctx.fillStyle='#72847f';font(ctx,11,false);ctx.fillText(String(it.label||'-'),x,yy);
      ctx.fillStyle='#173f3d';font(ctx,13,true);
      const vals=wrapLines(ctx,String(it.value||'-'),fw-138);ctx.fillText(vals[0]||'-',x+132,yy);
      line(ctx,x,yy+36,x+fw,yy+36,'#d5e0dd',1);
    });
    y+=Math.ceil(follow.length/2)*frow;

    // Keep a practical signing area while avoiding the large empty gap from the old PDF.
    const sigTop=Math.max(y+42,H-235),sigGap=86,sigW=(contentW-sigGap)/2;
    const people=[[data.caregiver||'','แคร์กิฟเวอร์'],[data.doctor||'','หมอ']];
    people.forEach((p,i)=>{
      const x=L+i*(sigW+sigGap),center=x+sigW/2;
      line(ctx,x+22,sigTop+70,x+sigW-22,sigTop+70,'#263d3c',1.3);
      ctx.textAlign='center';ctx.fillStyle='#173f3d';font(ctx,13,true);ctx.fillText(String(p[0]||''),center,sigTop+83);
      ctx.fillStyle='#6a7c78';font(ctx,11,false);ctx.fillText(p[1],center,sigTop+104);ctx.fillText('วันที่ ........../........../..........',center,sigTop+124);ctx.textAlign='left';
    });
    return sigTop+148;
  }


  function collectPageCss(){
    let css='';
    for(const sheet of Array.from(document.styleSheets||[])){
      try{
        for(const rule of Array.from(sheet.cssRules||[])) css += rule.cssText + '\n';
      }catch(_err){}
    }
    return css;
  }

  async function renderReportElementToCanvas(element){
    if(!element) throw new Error('ไม่พบหน้ารายงานสำหรับส่งออก PDF');
    if(document.fonts && document.fonts.ready) await document.fonts.ready;

    const clone=element.cloneNode(true);
    clone.style.margin='0';
    clone.style.width='794px';
    clone.style.maxWidth='794px';
    clone.style.minHeight='1120px';
    clone.style.boxSizing='border-box';
    clone.style.border='0';
    clone.style.boxShadow='none';
    clone.style.background='#ffffff';

    const css=collectPageCss()+`\nhtml,body{margin:0!important;padding:0!important;background:#fff!important;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision;}\n.a4-report-sheet{width:794px!important;max-width:794px!important;min-height:1120px!important;margin:0!important;border:0!important;box-shadow:none!important;}\n`;
    const serialized=new XMLSerializer().serializeToString(clone);
    // Render the same A4 layout at ~300 DPI instead of screenshot resolution.
    // Keeping the 794x1120 viewBox preserves layout, while the larger SVG raster
    // dimensions make Thai text and thin rules much sharper in the exported PDF.
    const exportW=2480, exportH=3508;
    const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${exportW}" height="${exportH}" viewBox="0 0 794 1120" preserveAspectRatio="xMidYMid meet"><foreignObject x="0" y="0" width="794" height="1120"><div xmlns="http://www.w3.org/1999/xhtml"><style>${css.replace(/<\/style/gi,'<\/style')}</style>${serialized}</div></foreignObject></svg>`;
    const blob=new Blob([svg],{type:'image/svg+xml;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    try{
      const img=new Image();
      await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=()=>reject(new Error('ไม่สามารถสร้างภาพหน้ารายงานได้'));img.src=url;});
      const canvas=document.createElement('canvas');
      canvas.width=exportW; canvas.height=exportH;
      const ctx=canvas.getContext('2d',{alpha:false});
      ctx.fillStyle='#ffffff';ctx.fillRect(0,0,exportW,exportH);
      ctx.imageSmoothingEnabled=true;
      ctx.imageSmoothingQuality='high';
      ctx.drawImage(img,0,0,exportW,exportH);
      return canvas;
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  async function downloadVisibleAssessmentReport(data){
    const report=document.querySelector('.a4-report-sheet');
    const canvas=await renderReportElementToCanvas(report);
    const blob=encodePdf(canvas.toDataURL('image/jpeg',1.0),canvas.width,canvas.height);
    saveBlob(blob,data.filename || 'สรุป_ADL.pdf');
    return blob;
  }

  async function downloadReportPdf(data){
    if(!data) throw new Error('ไม่มีข้อมูลสำหรับส่งออก PDF');
    if(document.fonts && document.fonts.ready) await document.fonts.ready;

    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d',{alpha:false});
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0,0,W,H);
    ctx.fillStyle = '#000000';
    ctx.textBaseline = 'top';

    if(data.layout === 'caregiver_assessment_summary'){
      try{
        return await downloadVisibleAssessmentReport(data);
      }catch(_captureError){
        // Fallback: keep the original canvas renderer so PDF download still works.
        drawAssessmentSummary(ctx,data);
        const assessmentBlob = encodePdf(canvas.toDataURL('image/jpeg',0.97));
        saveBlob(assessmentBlob,data.filename || 'สรุป_ADL.pdf');
        return assessmentBlob;
      }
    }

    let y = 70;
    ctx.textAlign = 'center';
    font(ctx,30,true);
    ctx.fillText(String(data.org || ''),W/2,y);
    y += 52;
    font(ctx,52,true);
    ctx.fillText(String(data.title || ''),W/2,y);
    y += 76;
    font(ctx,27,false);
    ctx.fillText(String(data.description || ''),W/2,y);
    y += 68;

    ctx.textAlign = 'left';
    font(ctx,26,false);
    ctx.fillText(String(data.assessment_datetime || ''),LEFT,y);
    ctx.textAlign = 'right';
    ctx.fillText(String(data.report_date || ''),W-RIGHT,y);
    ctx.textAlign = 'left';
    y += 68;

    y = drawSectionTitle(ctx,'ข้อมูลผู้สูงอายุ',y);
    let rowY = y;
    drawLabelValue(ctx,'ชื่อ-นามสกุล',data.fullname,LEFT,225,rowY,300);
    drawLabelValue(ctx,'อายุ',data.age,650,745,rowY,150);
    rowY += 52;
    drawLabelValue(ctx,'เพศ',data.gender,LEFT,225,rowY,300);
    drawLabelValue(ctx,'เบอร์โทร',data.phone,650,745,rowY,240);
    rowY += 52;
    drawLabelValue(ctx,'หมู่บ้าน',data.village,LEFT,225,rowY,300);
    drawLabelValue(ctx,'น้ำหนัก / ส่วนสูง',data.weight_height,650,860,rowY,260);
    rowY += 52;
    rowY = drawLabelValue(ctx,'โรคประจำตัว',data.disease,LEFT,245,rowY,810) + 10;
    rowY = drawLabelValue(ctx,'ผู้ประเมิน',data.doctor,LEFT,245,rowY,810) + 34;
    y = rowY;

    y = drawSectionTitle(ctx,'สรุปผลการประเมิน',y);
    y = drawLabelValue(ctx,'คะแนนรวม',data.score_text,LEFT,245,y,360) + 10;
    y = drawLabelValue(ctx,'กลุ่ม ADL',data.group,LEFT,245,y,360) + 10;
    y = drawLabelValue(ctx,'ระดับการช่วยเหลือ',data.dependency,LEFT,245,y,745) + 20;
    ctx.fillStyle = '#000000';
    y = paragraph(ctx,String(data.overall_summary || ''),LEFT,y,W-LEFT-RIGHT,26,46,false,1420) + 34;

    y = drawSectionTitle(ctx,'ประเด็นสำคัญจากการประเมิน',y);
    y = drawLabelValue(ctx,'กิจกรรมที่ยังต้องช่วยเหลือ:',data.need_help,LEFT,410,y,665) + 16;
    y = drawLabelValue(ctx,'กิจกรรมที่ยังสามารถทำได้:',data.full_ability,LEFT,410,y,665) + 34;

    y = drawSectionTitle(ctx,'ข้อเสนอแนะและแนวทางติดตาม',y);
    const followups = Array.isArray(data.followups) ? data.followups : [];
    for(const item of followups){
      font(ctx,26,false);
      ctx.fillText('•',LEFT + 12,y);
      y = paragraph(ctx,String(item || ''),LEFT + 46,y,W-LEFT-RIGHT-46,26,46,false,1435) + 13;
    }

    const signatureY = Math.max(1450, Math.min(1510, y + 64));
    const centers = [W * 0.29,W * 0.71];
    const signers = [
      [String(data.doctor || '-'),'หมอ'],
      [String(data.director || '-'),'ผู้อำนวยการ']
    ];

    ctx.strokeStyle = '#333333';
    ctx.lineWidth = 1.4;
    signers.forEach((signer,index) => {
      const center = centers[index];
      const half = 235;
      ctx.beginPath();
      ctx.moveTo(center-half,signatureY);
      ctx.lineTo(center+half,signatureY);
      ctx.stroke();
      ctx.textAlign = 'center';
      font(ctx,27,true);
      ctx.fillText(signer[0],center,signatureY + 14);
      font(ctx,25,true);
      ctx.fillText(signer[1],center,signatureY + 52);
    });

    ctx.textAlign = 'left';
    const blob = encodePdf(canvas.toDataURL('image/jpeg',0.96));
    saveBlob(blob,data.filename || 'สรุป_ADL.pdf');
    return blob;
  }

  global.downloadReportPdf = downloadReportPdf;
})(window);
