<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin('office_admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>3D Tile Room</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;font-family:Arial,sans-serif;background:#1a1a2e;color:#e2e8f0;overflow:hidden;}
body{display:flex;height:100%;}

#viewer{
  flex:1 1 66%;position:relative;overflow:hidden;
  background:#16213e;display:flex;justify-content:center;align-items:center;
  cursor:grab;
}
#viewer:active{cursor:grabbing;}
#viewer.draw-mode{cursor:crosshair;}
#viewer.erase-mode{cursor:cell;}
#viewer.span-mode{cursor:crosshair;}

/* Screenshot button overlay */
#screenshotBtn{
  position:absolute;top:12px;right:12px;z-index:100;
  padding:7px 14px;background:rgba(15,23,42,0.85);border:1px solid #334155;
  border-radius:6px;color:#94a3b8;cursor:pointer;font-size:12px;
  backdrop-filter:blur(4px);transition:all .15s;
}
#screenshotBtn:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}

/* Span selection overlay */
#spanOverlay{
  position:absolute;pointer-events:none;border:2px dashed #f59e0b;
  background:rgba(245,158,11,0.12);display:none;z-index:50;
}

.scene-wrap{transform-origin:center center;will-change:transform;}
.scene{perspective:2600px;display:flex;justify-content:center;align-items:center;width:560px;height:480px;}
.room{position:relative;transform-style:preserve-3d;}

.surf{position:absolute;top:0;left:0;display:grid;transform-style:preserve-3d;transform-origin:0 0 0;}
.cell{background:#fff;background-size:cover;background-position:center;cursor:inherit;transition:filter .08s;position:relative;}
.cell:hover{filter:brightness(0.85);}
.cell.sel-cell{outline:2px solid #f59e0b;outline-offset:-2px;background-color:rgba(245,158,11,0.18)!important;}
.hl-surf{box-shadow:inset 0 0 0 3px #f59e0b;}

/* Span image overlay inside a cell */
.span-img{
  position:absolute;
  pointer-events:none;
  background-size:cover;
  background-position:center;
  z-index:10;
}

#panel{
  flex:0 0 264px;background:#0f172a;
  overflow-y:auto;padding:10px;
  display:flex;flex-direction:column;gap:7px;
}
.sec{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px;}
.sec h3{font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;}
.row{display:flex;align-items:center;gap:5px;margin-bottom:4px;flex-wrap:wrap;}
.lbl{font-size:11px;color:#94a3b8;min-width:56px;}
input[type=number]{width:52px;height:25px;text-align:center;background:#0f172a;border:1px solid #334155;color:#e2e8f0;border-radius:4px;font-size:12px;}
input[type=number]:focus{outline:none;border-color:#3b82f6;}
input[type=range]{flex:1;accent-color:#3b82f6;}
.v{font-size:11px;color:#60a5fa;min-width:32px;text-align:right;}

button.btn{padding:5px 9px;border:1px solid #334155;border-radius:5px;background:#1e293b;color:#94a3b8;cursor:pointer;font-size:11px;margin:2px 2px 0 0;transition:all .15s;}
button.btn:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
button.btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
button.btn.primary:hover{background:#2563eb;}
button.btn.warn{background:#6d28d9;color:#fff;border-color:#6d28d9;}
button.btn.warn:hover{background:#7c3aed;}
button.btn.danger{background:#991b1b;color:#fff;border-color:#991b1b;}
button.btn.danger:hover{background:#dc2626;}
button.btn.sm{padding:3px 7px;font-size:10px;}

.tool-row{display:flex;gap:4px;margin-bottom:6px;flex-wrap:wrap;}
.tool-btn{flex:1;padding:6px 3px;border:1px solid #334155;border-radius:5px;background:#1e293b;color:#94a3b8;cursor:pointer;font-size:11px;text-align:center;transition:all .15s;min-width:50px;}
.tool-btn:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
.tool-btn.active{background:#0e7490;color:#fff;border-color:#0e7490;}

.surf-tabs{display:flex;gap:3px;flex-wrap:wrap;margin-bottom:8px;}
.surf-tab{padding:4px 9px;border:1px solid #334155;border-radius:4px;background:#1e293b;color:#94a3b8;cursor:pointer;font-size:11px;transition:all .15s;}
.surf-tab:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
.surf-tab.active{background:#f59e0b;color:#000;border-color:#f59e0b;font-weight:600;}

.adj-row{display:flex;align-items:center;gap:5px;margin-bottom:5px;}
.adj-lbl{font-size:10px;color:#64748b;min-width:48px;}
.adj-btns{display:flex;gap:3px;}
.adj-btns button{
  width:28px;height:24px;border:1px solid #334155;border-radius:4px;
  background:#1e293b;color:#94a3b8;cursor:pointer;font-size:13px;line-height:1;
  transition:all .12s;
}
.adj-btns button:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
.adj-btns button:disabled{background:#111827;color:#374151;cursor:not-allowed;border-color:#1f2937;}
.adj-val{font-size:10px;color:#60a5fa;min-width:42px;text-align:center;background:#0f172a;border-radius:3px;padding:2px 4px;border:1px solid #1e3a5f;}

.lock-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:6px 8px;background:#0f172a;border-radius:6px;border:1px solid #1e3a5f;}
.lock-bar span{font-size:11px;color:#94a3b8;}
.lock-btn{padding:4px 10px;border:none;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;transition:all .15s;}
.lock-btn.unlocked{background:#065f46;color:#6ee7b7;}
.lock-btn.unlocked:hover{background:#047857;}
.lock-btn.locked{background:#7f1d1d;color:#fca5a5;}
.lock-btn.locked:hover{background:#991b1b;}

.vr{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.vr span{font-size:11px;color:#94a3b8;}
.tog{display:flex;align-items:center;gap:5px;cursor:pointer;}
.tog input{display:none;}
.tog-tr{width:30px;height:16px;background:#334155;border-radius:8px;position:relative;transition:.2s;}
.tog-th{width:12px;height:12px;background:#64748b;border-radius:50%;position:absolute;top:2px;left:2px;transition:.2s;}
.tog input:checked~.tog-tr{background:#0e7490;}
.tog input:checked~.tog-tr .tog-th{left:16px;background:#38bdf8;}
.tog-tx{font-size:10px;color:#64748b;min-width:20px;}

#logoSlot{
  width:100%;height:70px;
  border:2px dashed #334155;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;position:relative;overflow:hidden;
  background:#0f172a;margin-bottom:8px;transition:border-color .2s;
}
#logoSlot:hover{border-color:#3b82f6;}
#logoSlot img{width:100%;height:100%;object-fit:contain;}
#logoSlot .placeholder{font-size:11px;color:#475569;text-align:center;pointer-events:none;}
#logoInput{display:none;}

#carousel{display:flex;gap:5px;overflow-x:auto;padding:3px 0;scrollbar-width:thin;}
#carousel img{height:48px;min-width:48px;object-fit:cover;border-radius:4px;cursor:pointer;border:2px solid transparent;}
#carousel img.sel{border-color:#38bdf8;}
#status{font-size:11px;color:#60a5fa;padding:3px 0;min-height:14px;}
#dimInfo{font-size:10px;color:#475569;margin-top:3px;}

/* span mode info box */
.span-info{font-size:10px;color:#f59e0b;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:5px;padding:6px 8px;margin-top:4px;line-height:1.5;}
</style>
</head>
<body>

<div id="viewer">
  <button id="screenshotBtn" onclick="takeScreenshot()" title="Screenshot viewer only">📷 Screenshot</button>
  <div id="spanOverlay"></div>
  <div class="scene-wrap" id="sceneWrap">
    <div class="scene">
      <div class="room" id="room"></div>
    </div>
  </div>
</div>

<div id="panel">

  <!-- TOOLS -->
  <div class="sec">
    <h3>Tools</h3>
    <div class="tool-row">
      <button class="tool-btn active" id="tOrbit" onclick="setTool('orbit')">⟳ Orbit</button>
      <button class="tool-btn" id="tDraw"  onclick="setTool('draw')">✏ Draw</button>
      <button class="tool-btn" id="tSpan"  onclick="setTool('span')">⊞ Span</button>
      <button class="tool-btn" id="tErase" onclick="setTool('erase')">✕ Erase</button>
    </div>
    <div id="status">Orbit — drag to rotate</div>
    <div class="span-info" id="spanInfo" style="display:none;">
      <b>Span mode:</b> Drag across cells to select a region, then the chosen tile fills all selected cells as one image. Works per surface.
    </div>
  </div>

  <!-- VIEW -->
  <div class="sec">
    <h3>View</h3>
    <div class="row">
      <span class="lbl">Zoom</span>
      <input type="range" id="zoomR" min="30" max="180" step="1" value="85">
      <span class="v" id="zoomV">85%</span>
    </div>
    <div class="row">
      <span class="lbl">Rotate</span>
      <input type="range" id="rotR" min="-135" max="135" step="1" value="32">
      <span class="v" id="rotV">32°</span>
    </div>
    <button class="btn sm" onclick="resetView()" style="margin-top:2px;">Reset view</button>
  </div>

  <!-- ADJUST SURFACE -->
  <div class="sec">
    <h3>Adjust surface</h3>
    <div class="lock-bar">
      <span id="lockLabel">Adjustments: Unlocked</span>
      <button class="lock-btn unlocked" id="lockBtn" onclick="toggleLock()">🔓 Unlock</button>
    </div>
    <div class="surf-tabs">
      <button class="surf-tab active" onclick="selectAdjSurf('back',this)">Back</button>
      <button class="surf-tab" onclick="selectAdjSurf('left',this)">Left</button>
      <button class="surf-tab" onclick="selectAdjSurf('right',this)">Right</button>
      <button class="surf-tab" onclick="selectAdjSurf('floor',this)">Floor</button>
    </div>
    <div class="adj-row">
      <span class="adj-lbl">Move X</span>
      <div class="adj-btns">
        <button id="adj-tx-m" onclick="nudge('tx',-5)">←</button>
        <button id="adj-tx-p" onclick="nudge('tx', 5)">→</button>
      </div>
      <span class="adj-val" id="av-tx">0px</span>
    </div>
    <div class="adj-row">
      <span class="adj-lbl">Move Y</span>
      <div class="adj-btns">
        <button id="adj-ty-m" onclick="nudge('ty',-5)">↑</button>
        <button id="adj-ty-p" onclick="nudge('ty', 5)">↓</button>
      </div>
      <span class="adj-val" id="av-ty">0px</span>
    </div>
    <div class="adj-row">
      <span class="adj-lbl">Move Z</span>
      <div class="adj-btns">
        <button id="adj-tz-m" onclick="nudge('tz',-5)">↙</button>
        <button id="adj-tz-p" onclick="nudge('tz', 5)">↗</button>
      </div>
      <span class="adj-val" id="av-tz">0px</span>
    </div>
    <div class="adj-row">
      <span class="adj-lbl">Rot X</span>
      <div class="adj-btns">
        <button id="adj-rx-m" onclick="nudge('rx',-5)">-5°</button>
        <button id="adj-rx-p" onclick="nudge('rx', 5)">+5°</button>
      </div>
      <span class="adj-val" id="av-rx">0°</span>
    </div>
    <div class="adj-row">
      <span class="adj-lbl">Rot Y</span>
      <div class="adj-btns">
        <button id="adj-ry-m" onclick="nudge('ry',-5)">-5°</button>
        <button id="adj-ry-p" onclick="nudge('ry', 5)">+5°</button>
      </div>
      <span class="adj-val" id="av-ry">0°</span>
    </div>
    <div style="display:flex;gap:4px;margin-top:6px;">
      <button class="btn sm" id="resetSurfBtn" onclick="resetSurf()">Reset surface</button>
      <button class="btn sm" id="resetAllBtn"  onclick="resetAllSurfs()">Reset all</button>
    </div>
  </div>

  <!-- TILE GRID -->
  <div class="sec">
    <h3>Tile grid</h3>
    <div class="row"><span class="lbl">Wall rows</span><input type="number" id="wR" value="4" min="1" max="20"></div>
    <div class="row"><span class="lbl">Wall cols</span><input type="number" id="wC" value="21" min="3" max="80"></div>
    <div style="font-size:10px;color:#475569;margin-bottom:4px;">Cols split by wall width ratio</div>
    <div class="row"><span class="lbl">Floor rows</span><input type="number" id="fR" value="4" min="1" max="20"></div>
    <div class="row"><span class="lbl">Floor cols</span><input type="number" id="fC" value="5" min="1" max="40"></div>
    <button class="btn primary" onclick="buildRoom()">Apply</button>
    <div id="dimInfo"></div>
  </div>

  <!-- SPACER -->
  <div class="sec">
    <h3>Spacer</h3>
    <div class="row">
      <input type="range" id="spacer" min="0" max="16" step="1" value="3">
      <span class="v" id="spacerV">3px</span>
    </div>
  </div>

  <!-- PAINT SURFACE -->
  <div class="sec">
    <h3>Paint surface</h3>
    <div class="row">
      <button class="btn" onclick="sel('floor')">Floor</button>
      <button class="btn" onclick="sel('back')">Back</button>
      <button class="btn" onclick="sel('left')">Left</button>
      <button class="btn" onclick="sel('right')">Right</button>
    </div>
    <div class="row" style="margin-top:3px;">
      <button class="btn primary" onclick="fillSurf()">Fill surface</button>
      <button class="btn danger"  onclick="clearSurf()">Clear</button>
    </div>
  </div>

  <!-- WALL VISIBILITY -->
  <div class="sec">
    <h3>Wall visibility</h3>
    <div class="vr"><span>Back wall</span>
      <label class="tog"><input type="checkbox" id="vBack" checked onchange="toggleVis('back',this.checked)">
        <div class="tog-tr"><div class="tog-th"></div></div><span class="tog-tx" id="lblBack">ON</span></label>
    </div>
    <div class="vr"><span>Left wall</span>
      <label class="tog"><input type="checkbox" id="vLeft" checked onchange="toggleVis('left',this.checked)">
        <div class="tog-tr"><div class="tog-th"></div></div><span class="tog-tx" id="lblLeft">ON</span></label>
    </div>
    <div class="vr"><span>Right wall</span>
      <label class="tog"><input type="checkbox" id="vRight" checked onchange="toggleVis('right',this.checked)">
        <div class="tog-tr"><div class="tog-th"></div></div><span class="tog-tx" id="lblRight">ON</span></label>
    </div>
  </div>

  <!-- TILES -->
  <div class="sec">
    <h3>Tiles</h3>
    <div id="logoSlot" onclick="document.getElementById('logoInput').click()" title="Click to upload your logo">
      <span class="placeholder" id="logoPlaceholder">+ Upload logo / brand tile</span>
      <img id="logoImg" style="display:none;">
    </div>
    <input type="file" id="logoInput" accept="image/*" onchange="loadLogo(event)">
    <label style="display:inline-block;padding:5px 12px;background:#334155;border-radius:5px;cursor:pointer;font-size:11px;color:#e2e8f0;margin-bottom:6px;">
      + Add tiles<input type="file" multiple accept="image/*" onchange="loadImgs(event)" style="display:none;">
    </label>
    <div id="carousel"></div>
  </div>

</div>

<!-- html2canvas from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
const $=id=>document.getElementById(id);
const roomEl=$('room');
const sceneWrap=$('sceneWrap');
const viewerEl=$('viewer');
const spanOverlay=$('spanOverlay');

const L=320, B=240, H=200;

const BASE={
  back:  'translateZ(0px)',
  left:  'rotateY(90deg)',
  right: `translateX(${L}px) translateZ(${B}px) rotateY(-90deg)`,
  floor: `translateY(${H}px) rotateX(-90deg)`
};

function defaultOffset(name){
  if(name==='left')  return {tx:0,ty:0,tz:240,rx:0,ry:0};
  if(name==='right') return {tx:0,ty:0,tz:-240,rx:0,ry:0};
  if(name==='floor') return {tx:0,ty:0,tz:240,rx:0,ry:0};
  return {tx:0,ty:0,tz:0,rx:0,ry:0};
}

const offsets={
  back:  defaultOffset('back'),
  left:  defaultOffset('left'),
  right: defaultOffset('right'),
  floor: defaultOffset('floor')
};

let adjSurfName='back', adjLocked=false;
let selImg=null, activeSurf='floor', tool='orbit';
let rotX=-25, rotY=32, zoom=0.85;
let surfs={}, cellStore={}, spanStore={}, painting=false;

/* Span drag state */
let spanDragging=false, spanStart=null, spanSurf=null;
let spanCells=[];

function gv(id){return Math.max(1,parseInt($(id).value)||1);}
function spacerPx(){return parseInt($('spacer').value)||0;}

function splitCols(total){
  const p=L+B+B;
  const bC=Math.max(1,Math.round(total*L/p));
  const lC=Math.max(1,Math.round(total*B/p));
  const rC=Math.max(1,total-bC-lC);
  return{bC,lC,rC};
}

function composeTransform(name){
  const o=offsets[name];
  return `translate3d(${o.tx}px,${o.ty}px,${o.tz}px) rotateX(${o.rx}deg) rotateY(${o.ry}deg) `+BASE[name];
}
function applyTransform(name){
  if(surfs[name]) surfs[name].style.transform=composeTransform(name);
}

function getCellKey(r,c){return r+'_'+c;}

function makeGrid(el,rows,cols,W,Ht,sName){
  el.innerHTML='';
  el.style.width=W+'px'; el.style.height=Ht+'px';
  el.style.gridTemplateRows=`repeat(${rows},1fr)`;
  el.style.gridTemplateColumns=`repeat(${cols},1fr)`;
  const g=spacerPx();
  el.style.gap=g+'px';
  el.style.background=g>0?'#d1d5db':'#fff';
  if(!cellStore[sName])cellStore[sName]={};
  if(!spanStore[sName])spanStore[sName]=[];
  for(let r=0;r<rows;r++){
    for(let c=0;c<cols;c++){
      const d=document.createElement('div');
      d.className='cell'; d.dataset.r=r; d.dataset.c=c; d.dataset.s=sName;
      const k=getCellKey(r,c);
      if(cellStore[sName][k]){
        d.style.backgroundImage=cellStore[sName][k];
        d.style.backgroundSize='cover'; d.style.backgroundPosition='center';
      } else { d.style.background='#fff'; }
      el.appendChild(d);
    }
  }
  // Re-render spans
  renderSpans(sName);
}

function buildRoom(){
  const wR=gv('wR'),tWC=gv('wC'),fR=gv('fR'),fC=gv('fC');
  const{bC,lC,rC}=splitCols(tWC);
  $('dimInfo').innerHTML=`Back:${bC} | Left:${lC} | Right:${rC}`;

  roomEl.innerHTML=''; surfs={};
  roomEl.style.width=L+'px'; roomEl.style.height=H+'px';
  roomEl.style.transformStyle='preserve-3d';

  function addSurf(name,rows,cols,W,Ht){
    const d=document.createElement('div');
    d.className='surf'; d.id='surf-'+name;
    d.style.transformOrigin='0 0 0';
    makeGrid(d,rows,cols,W,Ht,name);
    d.style.transform=composeTransform(name);
    roomEl.appendChild(d); surfs[name]=d;
  }
  addSurf('back', wR,bC,L,H);
  addSurf('left', wR,lC,B,H);
  addSurf('right',wR,rC,B,H);
  addSurf('floor',fR,fC,L,B);

  ['back','left','right'].forEach(n=>{
    const cb=$('v'+n[0].toUpperCase()+n.slice(1));
    if(cb) setVis(n,cb.checked,false);
  });
  applySpacerCol();
  highlightSurf(activeSurf);
  updateView();
  updateAdjUI();
}

/* ── SPAN IMAGE across multiple cells ── */
/*
  spanStore[surfName] = array of {r1,c1,r2,c2,img}
  We render each span as an absolutely positioned div layered on top of the grid.
*/
function renderSpans(sName){
  const el=surfs[sName]; if(!el)return;
  // Remove old span overlays
  el.querySelectorAll('.span-img').forEach(s=>s.remove());
  const spans=spanStore[sName]||[];
  const rows=parseInt(el.style.gridTemplateRows.match(/\d+/)||1);
  const cols=parseInt(el.style.gridTemplateColumns.match(/\d+/)||1);
  const W=parseFloat(el.style.width);
  const H=parseFloat(el.style.height);
  const g=spacerPx();
  const cw=(W - g*(cols-1))/cols;
  const ch=(H - g*(rows-1))/rows;

  spans.forEach(sp=>{
    const x=sp.c1*(cw+g);
    const y=sp.r1*(ch+g);
    const w=(sp.c2-sp.c1+1)*cw + (sp.c2-sp.c1)*g;
    const h=(sp.r2-sp.r1+1)*ch + (sp.r2-sp.r1)*g;
    const div=document.createElement('div');
    div.className='span-img';
    div.style.left=x+'px';
    div.style.top=y+'px';
    div.style.width=w+'px';
    div.style.height=h+'px';
    div.style.backgroundImage=`url(${sp.img})`;
    el.style.position='relative';
    el.appendChild(div);
  });
}

function addSpan(sName,r1,c1,r2,c2,img){
  if(!spanStore[sName])spanStore[sName]=[];
  // Remove overlapping spans
  spanStore[sName]=spanStore[sName].filter(sp=>{
    return sp.r2<r1||sp.r1>r2||sp.c2<c1||sp.c1>c2;
  });
  // Also clear individual cell paints under region
  const cs=cellStore[sName]||{};
  for(let r=r1;r<=r2;r++) for(let c=c1;c<=c2;c++){
    const k=getCellKey(r,c);
    delete cs[k];
    const cell=surfs[sName]&&surfs[sName].querySelector(`[data-r="${r}"][data-c="${c}"]`);
    if(cell){cell.style.backgroundImage='';cell.style.background='#fff';}
  }
  spanStore[sName].push({r1,c1,r2,c2,img});
  renderSpans(sName);
}

function eraseSpansAt(sName,r,c){
  if(!spanStore[sName])return;
  spanStore[sName]=spanStore[sName].filter(sp=>{
    return !(r>=sp.r1&&r<=sp.r2&&c>=sp.c1&&c<=sp.c2);
  });
  renderSpans(sName);
}

/* Cell from mouse position within a surface */
function cellFromPoint(el,px,py){
  // px,py relative to el top-left
  const rows=parseInt(el.style.gridTemplateRows.match(/\d+/)||1);
  const cols=parseInt(el.style.gridTemplateColumns.match(/\d+/)||1);
  const W=parseFloat(el.style.width);
  const H=parseFloat(el.style.height);
  const g=spacerPx();
  const cw=(W - g*(cols-1))/cols;
  const ch=(H - g*(rows-1))/rows;
  const c=Math.floor(px/(cw+g));
  const r=Math.floor(py/(ch+g));
  return {r:Math.max(0,Math.min(rows-1,r)), c:Math.max(0,Math.min(cols-1,c))};
}

/* ── SPAN DRAG ── */
viewerEl.addEventListener('pointerdown',e=>{
  if(tool!=='span') return;
  const cell=e.target.closest('.cell');
  if(!cell) return;
  const sName=cell.dataset.s;
  spanDragging=true; spanSurf=sName;
  spanStart={r:parseInt(cell.dataset.r),c:parseInt(cell.dataset.c)};
  spanCells=[];
  highlightSpanCells(sName,spanStart,spanStart);
  e.preventDefault();
});

viewerEl.addEventListener('pointermove',e=>{
  if(!spanDragging) return;
  const el=document.elementFromPoint(e.clientX,e.clientY);
  if(!el) return;
  const cell=el.closest?el.closest('.cell'):null;
  if(cell && cell.dataset.s===spanSurf){
    const r2=parseInt(cell.dataset.r),c2=parseInt(cell.dataset.c);
    const r1=Math.min(spanStart.r,r2), rc2=Math.max(spanStart.r,r2);
    const c1=Math.min(spanStart.c,c2), cc2=Math.max(spanStart.c,c2);
    highlightSpanCells(spanSurf,{r:r1,c:c1},{r:rc2,c:cc2});
  }
});

viewerEl.addEventListener('pointerup',e=>{
  if(!spanDragging) return;
  spanDragging=false;
  // Collect highlighted cells to determine bounding box
  const sel=surfs[spanSurf]&&surfs[spanSurf].querySelectorAll('.sel-cell');
  if(sel&&sel.length>0){
    let minR=999,maxR=-1,minC=999,maxC=-1;
    sel.forEach(c=>{
      const r=parseInt(c.dataset.r),cc=parseInt(c.dataset.c);
      minR=Math.min(minR,r);maxR=Math.max(maxR,r);
      minC=Math.min(minC,cc);maxC=Math.max(maxC,cc);
    });
    sel.forEach(c=>c.classList.remove('sel-cell'));
    if(selImg){
      addSpan(spanSurf,minR,minC,maxR,maxC,selImg);
      $('status').textContent=`Spanned ${(maxR-minR+1)}r×${(maxC-minC+1)}c on ${spanSurf}`;
    } else {
      $('status').textContent='Pick a tile first!';
    }
  }
  spanSurf=null; spanStart=null;
});

function highlightSpanCells(sName,from,to){
  const el=surfs[sName]; if(!el)return;
  el.querySelectorAll('.sel-cell').forEach(c=>c.classList.remove('sel-cell'));
  for(let r=from.r;r<=to.r;r++){
    for(let c=from.c;c<=to.c;c++){
      const cell=el.querySelector(`[data-r="${r}"][data-c="${c}"]`);
      if(cell) cell.classList.add('sel-cell');
    }
  }
}

/* ── ADJUST SURFACE ── */
function selectAdjSurf(name,btn){
  adjSurfName=name;
  document.querySelectorAll('.surf-tab').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  updateAdjUI();
}

function nudge(axis,delta){
  if(adjLocked) return;
  offsets[adjSurfName][axis]=(offsets[adjSurfName][axis]||0)+delta;
  applyTransform(adjSurfName);
  updateAdjUI();
}

function updateAdjUI(){
  const o=offsets[adjSurfName];
  $('av-tx').textContent=o.tx+'px';
  $('av-ty').textContent=o.ty+'px';
  $('av-tz').textContent=o.tz+'px';
  $('av-rx').textContent=o.rx+'°';
  $('av-ry').textContent=o.ry+'°';
  ['tx','ty','tz','rx','ry'].forEach(k=>{
    const bm=$('adj-'+k+'-m'), bp=$('adj-'+k+'-p');
    if(bm){bm.disabled=adjLocked; bp.disabled=adjLocked;}
  });
  $('resetSurfBtn').disabled=adjLocked;
  $('resetAllBtn').disabled=adjLocked;
}

function resetSurf(){
  if(adjLocked)return;
  offsets[adjSurfName]=defaultOffset(adjSurfName);
  applyTransform(adjSurfName); updateAdjUI();
}
function resetAllSurfs(){
  if(adjLocked)return;
  ['back','left','right','floor'].forEach(n=>{
    offsets[n]=defaultOffset(n); applyTransform(n);
  }); updateAdjUI();
}
function toggleLock(){
  adjLocked=!adjLocked;
  const btn=$('lockBtn'), lbl=$('lockLabel');
  if(adjLocked){
    btn.textContent='🔒 Locked'; btn.className='lock-btn locked';
    lbl.textContent='Adjustments: Locked';
  } else {
    btn.textContent='🔓 Unlock'; btn.className='lock-btn unlocked';
    lbl.textContent='Adjustments: Unlocked';
  }
  updateAdjUI();
}

/* ── VIEW ── */
function updateView(){
  roomEl.style.transform=`rotateX(${rotX}deg) rotateY(${rotY}deg)`;
  sceneWrap.style.transform=`scale(${zoom})`;
}
$('zoomR').addEventListener('input',()=>{
  zoom=parseInt($('zoomR').value)/100;
  $('zoomV').textContent=Math.round(zoom*100)+'%';
  updateView();
});
$('rotR').addEventListener('input',()=>{
  rotY=parseInt($('rotR').value);
  $('rotV').textContent=rotY+'°';
  updateView();
});
viewerEl.addEventListener('wheel',e=>{
  e.preventDefault();
  zoom=Math.min(1.8,Math.max(0.3,zoom-e.deltaY*0.001));
  $('zoomR').value=Math.round(zoom*100);
  $('zoomV').textContent=Math.round(zoom*100)+'%';
  updateView();
},{passive:false});
function resetView(){
  rotX=-25; rotY=32; zoom=0.85;
  $('rotR').value=rotY; $('rotV').textContent=rotY+'°';
  $('zoomR').value=Math.round(zoom*100); $('zoomV').textContent=Math.round(zoom*100)+'%';
  updateView();
}

/* ── ORBIT DRAG ── */
let drag=false,lx=0,ly=0;
viewerEl.addEventListener('mousedown',e=>{
  if(tool!=='orbit')return;
  drag=true; lx=e.clientX; ly=e.clientY;
});
document.addEventListener('mouseup',()=>{drag=false; painting=false;});
document.addEventListener('mousemove',e=>{
  if(drag&&tool==='orbit'){
    rotY=Math.max(-135,Math.min(135,rotY+(e.clientX-lx)*0.5));
    $('rotR').value=Math.round(rotY); $('rotV').textContent=Math.round(rotY)+'°';
    lx=e.clientX; ly=e.clientY;
    updateView();
  }
});

/* ── TOOL ── */
function setTool(t){
  tool=t;
  ['orbit','draw','span','erase'].forEach(n=>{
    const btn=$('t'+n[0].toUpperCase()+n.slice(1));
    if(btn) btn.classList.toggle('active',n===t);
  });
  viewerEl.className=t==='draw'?'draw-mode':t==='erase'?'erase-mode':t==='span'?'span-mode':'';
  const msgs={
    orbit:'Orbit — drag to rotate (270°)',
    draw:'Draw — click/drag cells to paint tiles',
    span:'Span — drag across cells to fit one image',
    erase:'Erase — click/drag to clear'
  };
  $('status').textContent=msgs[t];
  $('spanInfo').style.display=t==='span'?'block':'none';
}

/* ── PAINT / ERASE ── */
document.addEventListener('pointerdown',e=>{
  if((tool==='draw'||tool==='erase')&&e.target.classList.contains('cell')){
    painting=true; actOnCell(e.target);
  }
});
document.addEventListener('pointermove',e=>{
  if(painting&&(tool==='draw'||tool==='erase')){
    const el=document.elementFromPoint(e.clientX,e.clientY);
    if(el&&el.classList.contains('cell')) actOnCell(el);
  }
});
document.addEventListener('pointerup',()=>{painting=false;});

function actOnCell(cell){
  if(tool==='draw'){if(!selImg){$('status').textContent='Pick a tile first!';return;}applyImg(cell);}
  else if(tool==='erase') eraseCell(cell);
}

/* ── SURFACES ── */
function highlightSurf(n){
  document.querySelectorAll('.surf').forEach(s=>s.classList.remove('hl-surf'));
  if(surfs[n]) surfs[n].classList.add('hl-surf');
}
function sel(n){ activeSurf=n; highlightSurf(n); $('status').textContent='Paint: '+n+(tool==='orbit'?' (switch to Draw)':''); }
function fillSurf(){
  if(!selImg){$('status').textContent='Pick a tile first!';return;}
  const el=surfs[activeSurf]; if(!el)return;
  el.querySelectorAll('.cell').forEach(c=>applyImg(c));
}
function clearSurf(){
  const el=surfs[activeSurf]; if(!el)return;
  el.querySelectorAll('.cell').forEach(c=>eraseCell(c));
  spanStore[activeSurf]=[];
  renderSpans(activeSurf);
}
function applyImg(cell){
  const s=cell.dataset.s,r=parseInt(cell.dataset.r),c=parseInt(cell.dataset.c);
  eraseSpansAt(s,r,c);
  cell.style.background='';
  cell.style.backgroundImage=`url(${selImg})`;
  cell.style.backgroundSize='cover'; cell.style.backgroundPosition='center';
  storeCell(cell);
}
function eraseCell(cell){
  const s=cell.dataset.s,r=parseInt(cell.dataset.r),c=parseInt(cell.dataset.c);
  eraseSpansAt(s,r,c);
  cell.style.backgroundImage=''; cell.style.background='#fff';
  const k=getCellKey(r,c);
  if(cellStore[s]) delete cellStore[s][k];
}
function storeCell(cell){
  const s=cell.dataset.s,k=getCellKey(cell.dataset.r,cell.dataset.c);
  if(!cellStore[s])cellStore[s]={};
  cellStore[s][k]=cell.style.backgroundImage;
}

/* ── SPACER ── */
function applySpacerCol(){
  const g=spacerPx(),col=g>0?'#d1d5db':'#fff';
  Object.values(surfs).forEach(s=>{s.style.gap=g+'px';s.style.background=col;});
}
$('spacer').addEventListener('input',()=>{
  $('spacerV').textContent=$('spacer').value+'px';
  applySpacerCol();
  // Re-render spans since cell sizes changed
  ['back','left','right','floor'].forEach(n=>renderSpans(n));
});

/* ── VISIBILITY ── */
function setVis(name,show,updateLabel=true){
  const el=surfs[name]; if(!el)return;
  el.style.display=show?'grid':'none';
  if(updateLabel){$('lbl'+name[0].toUpperCase()+name.slice(1)).textContent=show?'ON':'OFF';}
}
function toggleVis(name,checked){setVis(name,checked);}

/* ── LOGO ── */
function loadLogo(e){
  const file=e.target.files[0]; if(!file)return;
  const url=URL.createObjectURL(file);
  $('logoImg').src=url; $('logoImg').style.display='block';
  $('logoPlaceholder').style.display='none';
  selImg=url;
  $('status').textContent='Logo set: '+file.name.slice(0,20);
  if(tool==='orbit') setTool('draw');
}

/* ── ADDITIONAL TILES ── */
function loadImgs(e){
  $('status').textContent='Tiles added';
  [...e.target.files].forEach(file=>{
    const img=document.createElement('img');
    img.src=URL.createObjectURL(file); img.title=file.name;
    img.onclick=()=>{
      selImg=img.src;
      document.querySelectorAll('#carousel img').forEach(i=>i.classList.remove('sel'));
      $('logoSlot').style.outline='none';
      img.classList.add('sel');
      $('status').textContent='Tile: '+file.name.slice(0,22);
      if(tool==='orbit') setTool('draw');
    };
    $('carousel').appendChild(img);
  });
}

$('logoSlot').addEventListener('click',e=>{
  if($('logoImg').style.display==='block'){
    selImg=$('logoImg').src;
    document.querySelectorAll('#carousel img').forEach(i=>i.classList.remove('sel'));
    $('logoSlot').style.outline='2px solid #38bdf8';
    $('status').textContent='Logo tile selected';
    if(tool==='orbit') setTool('draw');
    e.stopPropagation();
  }
});

/* ── SCREENSHOT ── */
function takeScreenshot(){
  const btn=$('screenshotBtn');
  const origText=btn.textContent;
  btn.textContent='Capturing...'; btn.disabled=true;

  // Temporarily hide the screenshot button itself
  btn.style.display='none';

  html2canvas(viewerEl,{
    backgroundColor:'#16213e',
    scale: window.devicePixelRatio||2,
    useCORS: true,
    allowTaint: true,
    logging: false
  }).then(canvas=>{
    btn.style.display=''; btn.textContent=origText; btn.disabled=false;
    const link=document.createElement('a');
    link.download='3d-tile-room.png';
    link.href=canvas.toDataURL('image/png');
    link.click();
    $('status').textContent='Screenshot saved!';
  }).catch(err=>{
    btn.style.display=''; btn.textContent=origText; btn.disabled=false;
    $('status').textContent='Screenshot failed: '+err.message;
  });
}

/* ── INIT ── */
buildRoom();
</script>
<?php
$sessionTimeoutRole = $_SESSION['role'] ?? '';
require __DIR__ . '/../includes/session_timeout_assets.php';
?>
</body>
</html>
