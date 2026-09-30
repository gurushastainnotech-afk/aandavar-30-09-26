<?php
// index.php — SHOWROOM VERSION WITH FIXED JOYSTICK
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>3D Tile Room – Showroom Version</title>

<style>
:root{
  --primary:#2563eb;
  --panel:#1e293b;
  --border:#334155;
  --text:#f1f5f9;
}

*{box-sizing:border-box;margin:0;padding:0;}
html,body{
  height:100%;
  font-family:Arial, sans-serif;
  background:#0f172a;
  color:var(--text);
}
body{
  display:flex;
  height:100%;
  overflow:hidden;
}

/* VIEWER */
#viewer{
  flex:65%;
  background:#111827;
  display:flex;
  flex-direction: column;
  justify-content:center;
  align-items:center;
  overflow:hidden;
}
#viewer .section{
    z-index: 999;
}
.scene{
  width:95%;
  height:95%;
  perspective:2500px;
  display:flex;
  justify-content:center;
  align-items:center;
}
.room{
  width:80%;
  height:80%;
  max-width:700px;
  max-height:700px;
  position:relative;
  transform-style:preserve-3d;
  transform:rotateX(-15deg) rotateY(25deg) scale(1);
  transition:transform .05s linear;
}

/* SURFACES */
.surface{
  position:absolute;
  display:grid;
  background:#1f2937;
}
#back{width:70%;height:70%;left:20%;top:15%;}
#left-surface{width:70%;height:70%;left:20%;top:15%;transform-origin:left center;transform:rotateY(270deg);}
#right-surface{width:70%;height:70%;right:10%;top:15%;transform-origin:right center;transform:rotateY(-270deg);}
#floor{width:70%;height:70%;left:20%;top:85%;transform-origin:top center;transform:rotateX(90deg);}
.active-wall{outline:4px solid #38bdf8;}

/* CELLS */
.cell{position:relative;background:#0f172a;}
.cell img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}

/* CONTROLS */
#controls-wrapper{
  flex:35%;
  background:var(--panel);
  padding:20px;
  overflow-y:auto;
}
.section{
  background:#0f172a;
  border:1px solid var(--border);
  border-radius:10px;
  padding:15px;
  margin-bottom:20px;
}
.section h3{margin-bottom:10px;font-size:15px;}
input{width:70px;height:35px;text-align:center;background:#020617;border:1px solid var(--border);color:white;border-radius:6px;margin:5px;}
button{padding:8px 14px;border:none;border-radius:6px;background:var(--primary);color:white;cursor:pointer;margin:5px 5px 0 0;}

/* JOYSTICK */
#joystick{
  display:flex;
  flex-direction:column;
  align-items:center;
  margin-top:10px;
}
#joystick-row{display:flex;justify-content:center;margin-top:5px;}
#joystick button{
  width:45px;height:45px;margin:3px;font-size:18px;background:#2563eb;border:none;border-radius:6px;color:white;cursor:pointer;
}

/* RESPONSIVE */
@media(max-width:900px){
  body{flex-direction:column;}
  #viewer{flex:50%;}
  #controls-wrapper{flex:50%;}
}
</style>
</head>

<body>

<div id="viewer">
  <div class="scene">
    <div class="room" id="room">
      <div id="back" class="surface"></div>
      <div id="left-surface" class="surface"></div>
      <div id="right-surface" class="surface"></div>
      <div id="floor" class="surface"></div>
    </div>
  </div>
  
  <div class="section">
    <h3>Joystick Controls</h3>
    <div id="joystick">
      <button id="joy-up">▲</button>
      <div id="joystick-row">
        <button id="joy-left">◀</button>
        <button id="joy-down">▼</button>
        <button id="joy-right">▶</button>
      </div>
    </div>
  </div>
  
  
</div>

<div id="controls-wrapper">

  <div class="section">
    <h3>Wall Grid (Common)</h3>
    Rows <input type="number" id="rows" value="5" min="1">
    Columns <input type="number" id="cols" value="5" min="1">
  </div>

  <div class="section">
    <h3>Floor Grid (Separate)</h3>
    Rows <input type="number" id="frows" value="5" min="1">
    Columns <input type="number" id="fcols" value="5" min="1">
  </div>

  <div class="section">
    <h3>Grout Spacing (px)</h3>
    <input type="range" id="grout" min="0" max="10" value="2">
    <span id="groutVal">2px</span>
  </div>

  <div class="section">
    <h3>Select Wall</h3>
    <button onclick="setWall('back')">Back</button>
    <button onclick="setWall('left-surface')">Left</button>
    <button onclick="setWall('right-surface')">Right</button>
    <button onclick="setWall('floor')">Floor</button>
    <br>
    <button onclick="applyTexture()">Apply Texture</button>
  </div>

  

  <div class="section">
    <h3>Upload Tiles</h3>
    <input type="file" multiple accept="image/*" onchange="loadImages(event)">
    <div id="carousel"></div>
  </div>

</div>

<script>
let selectedImage=null;
let activeWall="back";
let rotateX=-15, rotateY=25;
let zoom=1;
const room=document.getElementById("room");
const walls=["back","left-surface","right-surface"];
const floor=document.getElementById("floor");

/* ===== BUILD GRID ===== */
function buildGrid(el, rows, cols){
  rows=Math.max(1,rows);
  cols=Math.max(1,cols);
  el.innerHTML="";
  el.style.gridTemplateRows=`repeat(${rows},1fr)`;
  el.style.gridTemplateColumns=`repeat(${cols},1fr)`;

  for(let i=0;i<rows*cols;i++){
    const c=document.createElement("div");
    c.className="cell";
    c.onclick=()=>{ if(!selectedImage) return; c.innerHTML=`<img src="${selectedImage}">`; };
    el.appendChild(c);
  }
}

function buildAll(){
  const r=rows.value;
  const c=cols.value;
  walls.forEach(id=>buildGrid(document.getElementById(id),r,c));
  buildGrid(floor,frows.value,fcols.value);
}
rows.oninput=buildAll;
cols.oninput=buildAll;
frows.oninput=buildAll;
fcols.oninput=buildAll;

/* ===== GROUT CONTROL ===== */
grout.oninput=()=>{
  document.querySelectorAll(".surface").forEach(s=>{
    s.style.gap=grout.value+"px";
    s.style.background="#d1d5db";
  });
  groutVal.innerText=grout.value+"px";
};

/* ===== APPLY TEXTURE ===== */
function setWall(id){
  activeWall=id;
  document.querySelectorAll(".surface").forEach(w=>w.classList.remove("active-wall"));
  document.getElementById(id).classList.add("active-wall");
}
function applyTexture(){
  if(!selectedImage) return;
  document.querySelectorAll(`#${activeWall} .cell`).forEach(c=>{
    c.innerHTML=`<img src="${selectedImage}">`;
  });
}

/* ===== ZOOM WITH MOUSE ONLY ===== */
document.getElementById("viewer").addEventListener("wheel",e=>{
  e.preventDefault();
  zoom+= e.deltaY * -0.001;
  zoom=Math.min(Math.max(.6,zoom),2);
  updateTransform();
});

/* ===== IMAGE LOAD ===== */
function loadImages(e){
  [...e.target.files].forEach(f=>{
    const img=document.createElement("img");
    img.src=URL.createObjectURL(f);
    img.style.height="60px";
    img.style.margin="5px";
    img.style.cursor="pointer";
    img.onclick=()=>{ selectedImage=img.src; };
    carousel.appendChild(img);
  });
}

/* ===== ROTATION CONTROL ===== */
const speed = 2;

function updateTransform(){
  room.style.transform=`rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(${zoom})`;
}

// Joystick rotation function
function rotateRoom(dx, dy){ rotateX+=dx; rotateY+=dy; updateTransform(); }

// Map buttons
const controls=[
  {id:'joy-up', dx:-speed, dy:0},
  {id:'joy-down', dx:speed, dy:0},
  {id:'joy-left', dx:0, dy:-speed},
  {id:'joy-right', dx:0, dy:speed}
];

controls.forEach(c=>{
  const btn=document.getElementById(c.id);
  btn.onclick = ()=>rotateRoom(c.dx,c.dy);
  let interval;
  btn.addEventListener('mousedown', ()=>{
    interval = setInterval(()=>rotateRoom(c.dx,c.dy),20);
  });
  btn.addEventListener('mouseup', ()=>clearInterval(interval));
  btn.addEventListener('mouseleave', ()=>clearInterval(interval));
});

/* ===== INITIALIZE ===== */
buildAll();
setWall("back");
updateTransform();
</script>

</body>
</html>