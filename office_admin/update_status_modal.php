<?php
/*
 * update_status_modal.php  — v4.1
 *
 * CHANGES IN v4.0:
 * ─────────────────────────────────────────────────────────────────
 * 1. LIGHT THEME — complete rewrite from dark to clean light UI
 *
 * 2. L × B × H MULTI-ROW INPUTS (floor / wall / both only):
 *    • Each row calculates Length × Breadth × Height = row sqft
 *    • "Add More" adds another L × B × H row
 *    • Overall total adds all row sqft values
 *
 * 3. STEP / RISER — step now supports landing calc like riser
 *
 * 4. BATHROOM WALL MODE:
 *    • Optional bathroom-only formula per row = ((Length × 2) + (Breadth × 2)) × Height
 *    • Visible only when bathroom mode is enabled
 *
 * 5. All v3.0 tile options + company logic carried forward unchanged.
 *
 * PHP handler reads (new in v4):
 *   edit_lengths[sk]   — JSON array of length values  e.g. [3.5, 2.0]
 *   edit_breadths[sk]  — JSON array of breadth values
 *   edit_heights[sk]   — JSON array of height values
 *   new_lengths[sk]    — same for new areas
 *   new_breadths[sk]
 *   new_heights[sk]
 *   (replaces edit_area_dimensions / new_area_dimensions from v3)
 */

$_csrfTok = csrfToken();
?>

<!-- ══════════════════════════════════════════════════════════════════
     STYLES — LIGHT THEME
════════════════════════════════════════════════════════════════════ -->
<style>
@import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=DM+Mono:wght@400;500&display=swap');

/* ── Base vars (light) ─────────────────────────────────────────── */
:root{
  --c-bg:       #f4f6fa;
  --c-surface:  #ffffff;
  --c-surface2: #f8f9fc;
  --c-border:   #e2e6ef;
  --c-border2:  #d0d6e4;
  --c-text:     #1a2035;
  --c-text2:    #4a5470;
  --c-text3:    #8a93b0;
  --c-accent:   #f5a623;
  --c-accent2:  #e8941a;
  --c-blue:     #3b82f6;
  --c-green:    #22c55e;
  --c-pink:     #ec4899;
  --c-orange:   #f97316;
  --c-purple:   #8b5cf6;
  --c-red:      #ef4444;
  --c-shadow:   0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
  --c-shadow-md:0 4px 20px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.05);
}

/* ── Modal overlay ─────────────────────────────────────────────── */
.modal-overlay{
  position:fixed;inset:0;z-index:5000;
  background:rgba(30,40,70,.45);backdrop-filter:blur(6px);
  display:none;align-items:flex-start;justify-content:center;
  padding:16px 12px 32px;overflow-y:auto;
}
.modal-overlay.open{display:flex}

/* ── Modal shell ───────────────────────────────────────────────── */
.usm{
  background:var(--c-bg);border:1px solid var(--c-border);
  border-radius:16px;width:min(1040px,100%);
  box-shadow:var(--c-shadow-md);
  animation:usmIn .22s cubic-bezier(.34,1.4,.64,1) both;
  margin:auto;font-family:'Nunito',sans-serif;
}
@keyframes usmIn{from{opacity:0;transform:scale(.95) translateY(14px)}to{opacity:1;transform:none}}

/* ── Modal header ──────────────────────────────────────────────── */
.usm-head{
  padding:16px 22px;border-bottom:1px solid var(--c-border);
  display:flex;align-items:center;gap:12px;
  background:var(--c-surface);border-radius:16px 16px 0 0;
  position:sticky;top:0;z-index:10;
}
.usm-head-title{font-size:1.05rem;font-weight:900;color:var(--c-text);flex:1}
.usm-head-sub{font-size:.75rem;color:var(--c-text3);margin-top:2px}
.usm-close{
  background:var(--c-surface2);border:1px solid var(--c-border);
  color:var(--c-text2);border-radius:8px;padding:6px 12px;
  cursor:pointer;font-size:.8rem;font-weight:700;
  transition:all .15s;font-family:'Nunito',sans-serif;
}
.usm-close:hover{background:#fef2f2;border-color:var(--c-red);color:var(--c-red)}

/* ── Modal body ────────────────────────────────────────────────── */
.usm-body{padding:20px 22px;background:var(--c-bg)}
.usm-sect-label{
  font-size:.64rem;letter-spacing:.18em;text-transform:uppercase;
  color:var(--c-accent2);font-weight:800;margin:18px 0 10px;
  display:flex;align-items:center;gap:8px;
}
.usm-sect-label::after{content:'';flex:1;height:1.5px;background:var(--c-border)}

/* ── Status radio pills ────────────────────────────────────────── */
.status-pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.status-pill{
  display:flex;align-items:center;gap:6px;padding:8px 16px;
  border-radius:10px;border:1.5px solid var(--c-border);
  background:var(--c-surface);cursor:pointer;
  font-size:.82rem;font-weight:700;color:var(--c-text2);
  transition:all .15s;user-select:none;
}
.status-pill input{display:none}
.status-pill:has(input:checked){border-color:var(--c-accent);background:#fffbf0;color:var(--c-text)}
.status-pill.enquiry:has(input:checked){border-color:var(--c-blue);background:#eff6ff;color:#1d4ed8}
.status-pill.billed:has(input:checked){border-color:var(--c-green);background:#f0fdf4;color:#15803d}
.status-pill.outside:has(input:checked){border-color:var(--c-orange);background:#fff7ed;color:#c2410c}
.status-pill.others:has(input:checked){border-color:var(--c-purple);background:#faf5ff;color:#6d28d9}

/* ── Form controls ─────────────────────────────────────────────── */
.usm-field{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.usm-field label{
  font-size:.63rem;letter-spacing:.1em;text-transform:uppercase;
  color:var(--c-text3);font-weight:700;
}
.usm-field input[type=text],
.usm-field input[type=date],
.usm-field input[type=number],
.usm-field input[type=tel],
.usm-field select,
.usm-field textarea{
  background:var(--c-surface);color:var(--c-text);
  border:1.5px solid var(--c-border);border-radius:8px;
  padding:9px 12px;font-size:.84rem;outline:none;width:100%;
  transition:border-color .15s,box-shadow .15s;
  font-family:'Nunito',sans-serif;
}
.usm-field input:focus,.usm-field select:focus,.usm-field textarea:focus{
  border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(245,166,35,.15)
}
.usm-field select{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='7' viewBox='0 0 10 7'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238a93b0' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 10px center;
  padding-right:28px;appearance:none;
}
.usm-field textarea{resize:vertical;min-height:72px}
.usm-grid{display:grid;gap:10px}
.usm-grid.g2{grid-template-columns:1fr 1fr}
.usm-grid.g3{grid-template-columns:1fr 1fr 1fr}
.usm-grid.g4{grid-template-columns:1fr 1fr 1fr 1fr}
@media(max-width:600px){
  .usm-grid.g2,.usm-grid.g3,.usm-grid.g4{grid-template-columns:1fr 1fr}
}
@media(max-width:400px){
  .usm-grid.g3,.usm-grid.g4{grid-template-columns:1fr}
}

/* ── Room card ─────────────────────────────────────────────────── */
.room-card{
  background:var(--c-surface);border:1.5px solid var(--c-border);
  border-radius:14px;margin-bottom:14px;overflow:hidden;
  box-shadow:var(--c-shadow);
  transition:border-color .2s,box-shadow .2s;
}
.room-card:hover{border-color:#c8d0e0;box-shadow:var(--c-shadow-md)}
.room-card-head{
  display:flex;align-items:center;gap:10px;padding:13px 16px;
  background:var(--c-surface2);cursor:pointer;user-select:none;
  border-bottom:1px solid var(--c-border);
}
.room-card-head .rc-num{
  width:28px;height:28px;border-radius:7px;
  background:#fff7e6;color:var(--c-accent2);border:1.5px solid #fde68a;
  display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:900;flex-shrink:0;
}
.room-card-head .rc-info{flex:1;min-width:0}
.room-card-head .rc-name{
  font-size:.9rem;font-weight:800;color:var(--c-text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.room-card-head .rc-brand{
  font-size:.68rem;color:var(--c-accent2);margin-top:1px;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.room-card-head .rc-sqft{
  font-size:.72rem;color:var(--c-text3);flex-shrink:0;
  background:var(--c-bg);padding:3px 8px;border-radius:5px;
  border:1px solid var(--c-border);font-family:'DM Mono',monospace;
}
.room-card-head .rc-toggle{color:var(--c-text3);font-size:.8rem;transition:transform .2s;flex-shrink:0}
.room-card-head.collapsed .rc-toggle{transform:rotate(-90deg)}
.room-card-body{padding:16px;background:var(--c-surface)}

/* ── Company + Design row ──────────────────────────────────────── */
.tile-company-row{
  background:#fffbf0;border:1.5px solid #fde68a;
  border-radius:10px;padding:12px;margin-bottom:10px;
}
.tile-company-row-title{
  font-size:.62rem;text-transform:uppercase;letter-spacing:.14em;
  color:var(--c-accent2);font-weight:800;margin-bottom:8px;
}

/* ── Other company wrapper ─────────────────────────────────────── */
.other-company-wrap{
  background:#eff6ff;border:1.5px solid #bfdbfe;
  border-radius:8px;padding:10px;margin-top:6px;display:none;
}
.other-company-wrap.show{display:block}
.other-company-wrap label{font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:var(--c-blue)}

/* ── Calc result chips ─────────────────────────────────────────── */
.calc-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.calc-chip{
  background:var(--c-surface2);border:1.5px solid var(--c-border);
  border-radius:9px;padding:8px 14px;text-align:center;min-width:90px;
}
.calc-chip .cv{
  font-size:1.05rem;font-weight:800;color:var(--c-text);
  font-family:'DM Mono',monospace;
}
.calc-chip .cl{font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--c-text3);margin-top:2px;font-weight:700}
.calc-chip.highlight{background:#fffbf0;border-color:#fde68a}
.calc-chip.highlight .cv{color:var(--c-accent2)}
.calc-chip.wall{background:#eff6ff;border-color:#bfdbfe}
.calc-chip.wall .cv{color:var(--c-blue)}
.calc-chip.floor{background:#f0fdf4;border-color:#bbf7d0}
.calc-chip.floor .cv{color:#16a34a}
.calc-chip.step-clr{background:#fdf2f8;border-color:#fbcfe8}
.calc-chip.step-clr .cv{color:var(--c-pink)}
.calc-chip.riser-clr{background:#fff7ed;border-color:#fed7aa}
.calc-chip.riser-clr .cv{color:var(--c-orange)}

/* ── Custom size entry ─────────────────────────────────────────── */
.custom-size-wrap{
  background:#eff6ff;border:1.5px solid #bfdbfe;
  border-radius:8px;padding:10px;margin-top:6px;display:none;
}
.custom-size-wrap.show{display:block}
.custom-size-wrap label{font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:var(--c-blue)}
.custom-size-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:6px}
.custom-size-summary{margin-top:8px;color:var(--c-text3);font-size:.78rem;line-height:1.4;}

/* ── Bathroom partition ────────────────────────────────────────── */
.bath-section{
  background:#faf5ff;border:1.5px solid #ddd6fe;
  border-radius:10px;padding:14px;margin-top:10px;
}
.bath-section-title{
  font-size:.72rem;font-weight:800;color:var(--c-purple);
  text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px;
  display:flex;align-items:center;gap:6px;
}
.bath-part-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}
@media(max-width:600px){.bath-part-cards{grid-template-columns:1fr}}
.bath-part-card{
  background:var(--c-surface);border:1.5px solid var(--c-border);
  border-radius:9px;padding:10px 12px;
}
.bath-part-card .bpc-name{
  font-size:.65rem;font-weight:800;text-transform:uppercase;
  letter-spacing:.12em;margin-bottom:8px;
}
.bath-part-card.light .bpc-name{color:#92400e}
.bath-part-card.high  .bpc-name{color:var(--c-accent2)}
.bath-part-card.dark  .bpc-name{color:#78716c}
.bath-part-card .bpc-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px}
.bath-part-card .bpc-k{font-size:.6rem;color:var(--c-text3);font-family:'DM Mono',monospace}
.bath-part-card .bpc-v{font-size:.82rem;color:var(--c-text);font-family:'DM Mono',monospace;font-weight:600}
.bath-part-card .bpc-v.amber{color:var(--c-accent2);font-weight:800}
.bath-chips-total{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.bath-note{
  margin-top:10px;padding:10px 12px;border-radius:9px;
  background:#fff7ed;border:1.5px solid #fed7aa;
  color:#9a3412;font-size:.78rem;line-height:1.45;
}
.bath-note.error{
  background:#fef2f2;border-color:#fecaca;color:#b91c1c;
}
.bath-note .mult-red{color:var(--c-red);font-weight:900}

/* ── Surface mode tabs ─────────────────────────────────────────── */
.surf-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.surf-tab{
  flex:1;min-width:80px;padding:8px 6px;border-radius:8px;text-align:center;
  border:1.5px solid var(--c-border);background:var(--c-surface2);
  color:var(--c-text3);font-size:.74rem;font-weight:800;cursor:pointer;
  transition:all .15s;font-family:'Nunito',sans-serif;
}
.surf-tab.active-floor {border-color:var(--c-green);background:#f0fdf4;color:#16a34a}
.surf-tab.active-wall  {border-color:var(--c-blue);background:#eff6ff;color:#1d4ed8}
.surf-tab.active-both  {border-color:var(--c-accent);background:#fffbf0;color:var(--c-accent2)}
.surf-tab.active-step  {border-color:var(--c-pink);background:#fdf2f8;color:#be185d}
.surf-tab.active-riser {border-color:var(--c-orange);background:#fff7ed;color:#c2410c}
.surf-tab.active-step_riser{border-color:var(--c-purple);background:#faf5ff;color:#6d28d9}

/* ══════════════════════════════════════════════════════════════════
   DIMENSION SECTION — separate L / B / H columns
════════════════════════════════════════════════════════════════════ */
.dim-section{
  background:linear-gradient(135deg,#f0fdf4 0%,#eff6ff 100%);
  border:1.5px solid #bbf7d0;
  border-radius:12px;padding:14px;margin-bottom:12px;
}
.dim-section-title{
  font-size:.62rem;text-transform:uppercase;letter-spacing:.16em;
  font-weight:800;color:#16a34a;margin-bottom:12px;
  display:flex;justify-content:space-between;align-items:center;
}

/* Three-column grid for L, B, H */
.dim-cols-grid{
  display:grid;
  grid-template-columns:1fr 1fr 1fr;
  gap:12px;
}
@media(max-width:540px){
  .dim-cols-grid{grid-template-columns:1fr}
  .dim-val-row{grid-template-columns:1fr 1fr}
  .dim-row-total{grid-column:1 / span 2}
}

.dim-col{
  background:var(--c-surface);border:1.5px solid var(--c-border);
  border-radius:10px;padding:10px;
}
.dim-col-head{
  font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;
  font-weight:800;margin-bottom:8px;display:flex;
  justify-content:space-between;align-items:center;
}
.dim-col.col-l .dim-col-head{color:#16a34a}
.dim-col.col-b .dim-col-head{color:var(--c-blue)}
.dim-col.col-h .dim-col-head{color:var(--c-purple)}

.dim-val-row{
  display:grid;grid-template-columns:auto auto 1fr auto 1fr auto 1fr minmax(88px,auto) auto;
  align-items:center;gap:6px;margin-bottom:6px;
}
.dim-row-pick{
  display:inline-flex;align-items:center;gap:4px;
  background:var(--c-surface2);border:1.5px solid var(--c-border);
  border-radius:999px;padding:6px 8px;font-size:.68rem;
  color:var(--c-text2);font-weight:800;white-space:nowrap;
}
.dim-row-pick input{margin:0}
.dim-row-pick.floor{color:#166534}
.dim-row-pick.wall{color:#1d4ed8}
.dim-input-hide{display:none!important}
.dim-op{font-weight:900;color:var(--c-text3)}
.dim-val-row input{
  flex:1;background:var(--c-surface2);color:var(--c-text);
  border:1.5px solid var(--c-border);border-radius:7px;
  padding:7px 10px;font-size:.84rem;outline:none;
  font-family:'DM Mono',monospace;
  transition:border-color .15s;
}
.dim-val-row input:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(245,166,35,.12)}
.dim-del-val-btn{
  background:#fef2f2;border:1.5px solid #fecaca;
  color:var(--c-red);border-radius:6px;padding:6px 8px;
  cursor:pointer;font-size:.68rem;font-weight:700;
  transition:all .15s;line-height:1;flex-shrink:0;
}
.dim-del-val-btn:hover{background:#fee2e2;border-color:var(--c-red)}
.add-val-btn{
  display:inline-flex;align-items:center;gap:4px;padding:5px 10px;
  border-radius:6px;border:1.5px dashed var(--c-border2);
  background:var(--c-surface2);color:var(--c-text3);
  font-size:.7rem;font-weight:700;cursor:pointer;
  transition:all .15s;margin-top:2px;font-family:'Nunito',sans-serif;
}
.dim-col.col-l .add-val-btn:hover{border-color:var(--c-green);color:#16a34a;background:#f0fdf4}
.dim-col.col-b .add-val-btn:hover{border-color:var(--c-blue);color:#1d4ed8;background:#eff6ff}
.dim-col.col-h .add-val-btn:hover{border-color:var(--c-purple);color:#6d28d9;background:#faf5ff}

.dim-col-total{
  margin-top:8px;padding-top:8px;border-top:1.5px dashed var(--c-border);
  font-family:'DM Mono',monospace;font-size:.8rem;font-weight:700;
  text-align:center;
}
.dim-col.col-l .dim-col-total{color:#16a34a}
.dim-col.col-b .dim-col-total{color:var(--c-blue)}
.dim-col.col-h .dim-col-total{color:var(--c-purple)}

.dim-totals-bar{
  display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;
  padding-top:12px;border-top:1.5px dashed #bbf7d0;
}
.dim-multiple-wrap{
  margin-top:12px;padding-top:12px;border-top:1.5px dashed #bbf7d0;
  display:grid;grid-template-columns:minmax(180px,220px) 1fr;gap:10px;align-items:end;
}
.dim-multiple-note{
  font-size:.76rem;line-height:1.45;color:var(--c-text3);
}
.dim-multiple-note .mult-red{color:var(--c-red);font-weight:900}
.dim-total-chip{
  background:var(--c-surface);border:1.5px solid var(--c-border);
  border-radius:7px;padding:6px 12px;font-size:.78rem;
  display:flex;gap:5px;align-items:baseline;
}
.dim-total-chip .dtv{
  color:var(--c-text);font-family:'DM Mono',monospace;font-weight:800;
}
.dim-total-chip .dtl{color:var(--c-text3);font-size:.65rem}

/* ── Step / Riser section ──────────────────────────────────────── */
.step-riser-wrap{
  background:#fdf2f8;border:1.5px solid #fbcfe8;
  border-radius:10px;padding:12px;margin-bottom:10px;
}
.step-riser-title{
  font-size:.62rem;text-transform:uppercase;letter-spacing:.14em;
  font-weight:800;margin-bottom:8px;
}
.step-riser-title.step-t{color:var(--c-pink)}
.step-riser-title.riser-t{color:var(--c-orange)}
.step-riser-title.both-t{color:var(--c-purple)}

/* ── Image upload zone ─────────────────────────────────────────── */
.img-zone{
  border:2px dashed var(--c-border2);border-radius:10px;
  padding:14px;background:var(--c-surface2);
  transition:border-color .2s,background .2s;
}
.img-zone:hover{border-color:rgba(245,166,35,.5);background:#fffbf0}
.img-thumbs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.img-thumb{
  position:relative;width:66px;height:66px;border-radius:8px;
  overflow:hidden;border:1.5px solid var(--c-border);
  background:var(--c-surface);flex-shrink:0;
}
.img-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.img-thumb-del{
  position:absolute;top:2px;right:2px;background:rgba(239,68,68,.9);
  color:#fff;border:none;border-radius:4px;width:17px;height:17px;
  font-size:.55rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.img-add-btn{
  display:inline-flex;align-items:center;gap:5px;padding:7px 13px;
  border-radius:7px;border:1.5px solid var(--c-border);
  background:var(--c-surface);color:var(--c-text2);
  font-size:.75rem;font-weight:700;cursor:pointer;
  transition:all .15s;font-family:'Nunito',sans-serif;
}
.img-add-btn:hover{border-color:var(--c-accent);color:var(--c-accent2)}
.cam-video{width:100%;max-height:160px;object-fit:cover;border-radius:8px;
  background:#000;display:none;margin-top:8px}
.cam-canvas{display:none}

/* ── Add room button ───────────────────────────────────────────── */
.add-room-btn{
  width:100%;padding:13px;border-radius:10px;
  border:2px dashed #fde68a;
  background:#fffbf0;color:var(--c-accent2);
  font-size:.85rem;font-weight:800;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  transition:all .18s;margin-top:4px;font-family:'Nunito',sans-serif;
}
.add-room-btn:hover{border-color:var(--c-accent);background:#fff7e0;box-shadow:0 2px 12px rgba(245,166,35,.15)}

/* ── Footer ────────────────────────────────────────────────────── */
.usm-foot{
  padding:14px 22px;border-top:1px solid var(--c-border);
  display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;
  background:var(--c-surface);border-radius:0 0 16px 16px;
  position:sticky;bottom:0;z-index:10;
}

/* ── Toggle switch ─────────────────────────────────────────────── */
.toggle-wrap{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.toggle-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0;position:absolute}
.toggle-slider{
  position:absolute;inset:0;background:#d1d5db;border-radius:24px;
  cursor:pointer;transition:background .2s;
}
.toggle-slider::before{
  content:'';position:absolute;width:18px;height:18px;
  left:3px;bottom:3px;background:#fff;border-radius:50%;
  transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.toggle-switch input:checked + .toggle-slider{background:var(--c-purple)}
.toggle-switch input:checked + .toggle-slider::before{transform:translateX(18px)}
.toggle-label{font-size:.8rem;color:var(--c-text2);font-weight:700}

/* ── Alt mobile badge ──────────────────────────────────────────── */
.alt-mobile-badge{
  display:inline-flex;align-items:center;gap:5px;padding:2px 8px;
  background:#f0fdf4;border:1.5px solid #bbf7d0;
  border-radius:5px;font-size:.65rem;color:#16a34a;font-weight:800;
}

/* ── Generic btns ──────────────────────────────────────────────── */
.btn{
  padding:9px 18px;border-radius:9px;border:none;
  font-size:.84rem;font-weight:800;cursor:pointer;
  font-family:'Nunito',sans-serif;transition:all .15s;
}
.btn-ghost{
  background:var(--c-surface2);border:1.5px solid var(--c-border);
  color:var(--c-text2);
}
.btn-ghost:hover{border-color:var(--c-border2);background:var(--c-bg)}
.btn-primary{
  background:linear-gradient(135deg,#f5a623,#e8941a);
  color:#fff;box-shadow:0 2px 10px rgba(245,166,35,.3);border:none;
}
.btn-primary:hover{box-shadow:0 4px 16px rgba(245,166,35,.45);transform:translateY(-1px)}
.btn-danger-sm{
  background:#fef2f2;border:1.5px solid #fecaca;
  color:var(--c-red);border-radius:7px;padding:4px 10px;
  font-size:.7rem;font-weight:800;cursor:pointer;
  font-family:'Nunito',sans-serif;transition:all .15s;
}
.btn-danger-sm:hover{background:#fee2e2;border-color:var(--c-red)}
@media(max-width:900px){
  .modal-overlay{padding:10px 8px 22px}
  .usm{width:100%;border-radius:14px}
  .usm-head,.usm-body{padding:14px}
.room-card-head{flex-wrap:wrap;align-items:flex-start}
  .room-card-head .rc-info{min-width:0}
  .room-card-head .rc-name,.room-card-head .rc-brand{white-space:normal}
  .room-card-body,.tile-company-row,.dim-section,.bath-section,.step-riser-wrap,.img-zone{min-width:0;overflow-x:hidden}
}
@media(max-width:720px){
  .status-pills,.surf-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
  .status-pill,.surf-tab{min-width:0;justify-content:center}
  .custom-size-grid{grid-template-columns:1fr}
  .dim-section-title{flex-direction:column;align-items:flex-start;gap:8px}
  .dim-val-row{grid-template-columns:1fr 1fr;gap:8px}
  .dim-val-row > span{display:none}
  .dim-row-total{grid-column:1 / -1;min-width:0}
  .dim-del-val-btn{width:100%}
  .calc-chip,.dim-total-chip{min-width:0;max-width:100%}
}
@media(max-width:540px){
  .usm-head{gap:8px}
  .usm-head-title{font-size:.96rem}
  .status-pills,.surf-tabs{grid-template-columns:1fr}
  .room-card-head .rc-sqft,.room-card-head .btn-danger-sm{width:100%;text-align:center}
  .img-zone{padding:12px}
  .img-add-btn{width:100%;justify-content:center}
  .usm-foot .btn{width:100%}
}
</style>

<!-- ══════════════════════════════════════════════════════════════════
     EDIT STATUS MODAL
════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
<div class="usm">

  <div class="usm-head">
    <div>
      <div class="usm-head-title">✏️ Update Submission</div>
      <div class="usm-head-sub" id="editModalSub">—</div>
    </div>
    <button class="usm-close" onclick="closeModal('editModal')">✕ Close</button>
  </div>

  <form method="POST" action="subadmins.php?phone_lookup=<?= urlencode($phoneLookup ?? '') ?>"
        enctype="multipart/form-data" id="editStatusForm">
    <input type="hidden" name="csrf"          value="<?= $_csrfTok ?>">
    <input type="hidden" name="action"        value="edit_status">
    <input type="hidden" name="submission_id" id="esSubId">
    <input type="hidden" name="lookup_phone"  value="<?= htmlspecialchars($phoneLookup ?? '') ?>">

    <div class="usm-body">

      <!-- STATUS ──────────────────────────────────────────── -->
      <div class="usm-sect-label">Status</div>
      <div class="status-pills">
        <label class="status-pill enquiry">
          <input type="radio" name="status" value="enquiry"> 🔵 Enquiry
        </label>
        <label class="status-pill billed">
          <input type="radio" name="status" value="billed"> ✅ Billed
        </label>
        <label class="status-pill outside">
          <input type="radio" name="status" value="outside_purchase"> 🏪 Outside Purchase
        </label>
        <label class="status-pill others">
          <input type="radio" name="status" value="others"> 📝 Others
        </label>
      </div>

      <div id="esOtherReasonWrap" style="display:none" class="usm-field">
        <label>Reason (Others)</label>
        <textarea name="other_status_reason" id="esOtherReason" rows="2"
          placeholder="Enter reason…"></textarea>
      </div>
      <div id="esPurchaseWrap" style="display:none">
        <div class="usm-grid g2">
          <div class="usm-field">
            <label>Purchase Date</label>
            <input type="date" name="purchase_date" id="esPurchaseDate">
          </div>
          <div class="usm-field">
            <label>Purchase Notes</label>
            <input type="text" name="purchase_notes" id="esPurchaseNotes"
              placeholder="Optional notes…">
          </div>
        </div>
      </div>

      <div class="usm-sect-label">Visit Follow-Up</div>
      <div class="usm-grid g2">
        <div class="usm-field">
          <label>Next Visit Date</label>
          <input type="date" name="next_visit_date" id="esNextVisitDate"
            aria-describedby="esNextVisitHelp">
          <small id="esNextVisitHelp" style="color:var(--c-text3);font-size:.72rem">
            Stored as YYYY-MM-DD. Displayed as DD Mon YYYY.
          </small>
        </div>
        <div class="usm-field">
          <label>Scheduled Visit</label>
          <div id="esNextVisitPreview" style="min-height:39px;padding:9px 12px;border:1.5px solid var(--c-border);border-radius:8px;background:var(--c-surface2);color:var(--c-text2);font-size:.84rem">
            No next visit scheduled
          </div>
        </div>
      </div>

      <!-- CUSTOMER CONTACT ────────────────────────────────── -->
      <div class="usm-sect-label">Customer Contact</div>
      <div class="usm-grid g2">
        <div class="usm-field">
          <label>Property Type (from form)</label>
          <input type="text" id="esPropertyType" readonly
            style="opacity:.75;cursor:not-allowed;background:#f8f9fc">
        </div>
        <div class="usm-field">
          <label>Primary Mobile</label>
          <input type="tel" name="owner_number" id="esOwnerNumber"
            placeholder="Primary mobile" maxlength="15" readonly
            style="opacity:.6;cursor:not-allowed;background:#f8f9fc">
        </div>
        <div class="usm-field">
          <label>Alternate Mobile <span class="alt-mobile-badge">New</span></label>
          <input type="tel" name="alt_mobile" id="esAltMobile"
            placeholder="Alternate number (optional)" maxlength="15"
            pattern="[0-9+\-\s()]{7,15}">
        </div>
      </div>

      <!-- AREAS / ROOMS ────────────────────────────────────── -->
      <div class="usm-sect-label">Areas / Rooms</div>
      <div id="roomCardsContainer"></div>
      <button type="button" class="add-room-btn" onclick="addRoomCard()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
             viewBox="0 0 24 24">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Add Room / Area
      </button>

    </div><!-- /.usm-body -->

    <div class="usm-foot">
      <button type="button" class="btn btn-ghost"
        onclick="closeModal('editModal')">Cancel</button>
      <button type="submit" class="btn btn-primary"
        id="esSaveBtn">💾 Save Changes</button>
    </div>
  </form>

</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     UNLOCK REQUEST MODAL
════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="unlockModal">
<div class="usm" style="max-width:520px">
  <div class="usm-head">
    <div>
      <div class="usm-head-title">🔓 Request Unlock</div>
      <div class="usm-head-sub" id="unlockModalSub">—</div>
    </div>
    <button class="usm-close" onclick="closeModal('unlockModal')">✕</button>
  </div>
  <form method="POST"
        action="subadmins.php?phone_lookup=<?= urlencode($phoneLookup ?? '') ?>"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf"          value="<?= $_csrfTok ?>">
    <input type="hidden" name="action"        value="request_unlock">
    <input type="hidden" name="submission_id" id="unlockSubId">
    <input type="hidden" name="lookup_phone"
      value="<?= htmlspecialchars($phoneLookup ?? '') ?>">
    <div class="usm-body">
      <div class="usm-field">
        <label>Reason for Unlock</label>
        <textarea name="unlock_reason" rows="3"
          placeholder="Explain why you need to edit this locked record…"
          required style="min-height:90px"></textarea>
      </div>
      <p style="font-size:.76rem;color:var(--c-text3);margin-top:-4px">
        Your request will be sent to the Main Admin for approval.
      </p>
    </div>
    <div class="usm-foot">
      <button type="button" class="btn btn-ghost"
        onclick="closeModal('unlockModal')">Cancel</button>
      <button type="submit" class="btn btn-primary">🔓 Send Unlock Request</button>
    </div>
  </form>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════════════ -->
<script>
/* ════════════════════════════════════════════════════════════════
   TILE COMPANIES
════════════════════════════════════════════════════════════════ */
const TILE_COMPANIES = [
  'Kajaria Tiles','Somany Tiles', 'AGL Tiles', 'NITCO Tiles', 'JOHNSON Tiles',
  'ITACA Tiles', 'Pollen Granite Tiles', 'SILK TOUCH Tiles', 'Nucera Tiles', 'ITALAKE Tiles',
  'Parryware', 'Jaquar', 'JOHNSON Bathware', 'WELLVIT', 'HARDWORKER', 'ROFF', 'KERAKOLL', 'FUSION', 'MEPCRETE', 'RENACON'
];

/* ════════════════════════════════════════════════════════════════
   TILE SIZE OPTIONS
════════════════════════════════════════════════════════════════ */
const FLOOR_TILE_OPTIONS = [
  {v:'1|1|9',       l:'1.00 × 1.00 ft — 9/box  (300×300)'},
  {v:'1|1|10',      l:'1.00 × 1.00 ft — 10/box (300×300)'},
  {v:'1.32|1.32|5', l:'1.32 × 1.32 ft — 5/box  (400×400)'},
  {v:'2|2|4',       l:'2.00 × 2.00 ft — 4/box  (600×600)'},
  {v:'2|2|3',       l:'2.00 × 2.00 ft — 3/box  (600×600)'},
  {v:'2.63|2.63|3', l:'2.63 × 2.63 ft — 3/box  (800×800)'},
  {v:'2.63|4|2',    l:'2.63 × 4.00 ft — 2/box  (800×1200)'},
  {v:'2.63|5.25|2', l:'2.63 × 5.25 ft — 2/box  (800×1600)'},
  {v:'4|6|2',       l:'4.00 × 6.00 ft — 2/box  (1200×1800)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];
const WALL_TILE_OPTIONS = [
  {v:'1|1.5|6',     l:'1.00 × 1.50 ft — 6/box  (300×450)'},
  {v:'1|1.5|5',     l:'1.00 × 1.50 ft — 5/box  (300×450)'},
  {v:'2|1|5',       l:'2.00 × 1.00 ft — 5/box  (600×300)'},
  {v:'2|1|6',       l:'2.00 × 1.00 ft — 6/box  (600×300)'},
  {v:'2|3|1',       l:'2.00 × 3.00 ft — 1/box  (600×900)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];
const FLOOR_WALL_TILE_OPTIONS = [
  {v:'1|1|8',       l:'1.00 × 1.00 ft — 8/box  (300×300)'},
  {v:'4|2|2',       l:'4.00 × 2.00 ft — 2/box  (1200×600)'},
  {v:'2.63|8|1',    l:'2.63 × 8.00 ft — 1/box  (800×2400)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];
const STEP_TILE_OPTIONS = [
  {v:'3|1|1',       l:'3.00 × 1.00 ft — 1/box  (900×300)'},
  {v:'3.25|1|1',    l:'3.25 × 1.00 ft — 1/box  (1000×300)'},
  {v:'4|1|1',       l:'4.00 × 1.00 ft — 1/box  (1200×300)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];
const RISER_TILE_OPTIONS = [
  {v:'3|0.8|1',     l:'3.00 × 0.80 ft — 1/box  (900×200)'},
  {v:'3.25|0.8|1',  l:'3.25 × 0.80 ft — 1/box  (1000×200)'},
  {v:'4|0.8|1',     l:'4.00 × 0.80 ft — 1/box  (1200×200)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];
const STEP_RISER_TILE_OPTIONS = [
  {v:'1|1|8',       l:'1.00 × 1.00 ft — 8/box  (300×300)'},
  {v:'custom',      l:'⚙ Custom Size…'},
];

function mergeTileOptions(...groups){
  const seen = new Set();
  const out = [];
  groups.flat().forEach(o=>{
    if(!o || seen.has(o.v)) return;
    seen.add(o.v);
    out.push(o);
  });
  return out;
}
const AREA_TILE_OPTIONS = mergeTileOptions(FLOOR_TILE_OPTIONS, WALL_TILE_OPTIONS, FLOOR_WALL_TILE_OPTIONS);
const STAIR_TILE_OPTIONS = mergeTileOptions(STEP_TILE_OPTIONS, RISER_TILE_OPTIONS, STEP_RISER_TILE_OPTIONS);

const WASTE_OPTIONS = [0,5,10,15,20];

let _roomSeq = 0;
const _camStreams = {};

/* ── Utility ─────────────────────────────────────────────────────── */
function _escHtml(s){
  return String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function parseTile(key){
  if(!key||key==='custom') return {w:0,h:0,ppb:1};
  const p=key.split('|').map(Number);
  return {w:p[0]||0,h:p[1]||0,ppb:p[2]||1};
}
function calcTiles(areaSqft,tileKey,wastePct,customW,customH,customPPB){
  let t;
  if(tileKey==='custom'){
    t={w:parseFloat(customW)||0,h:parseFloat(customH)||0,ppb:parseInt(customPPB)||1};
  } else {
    t=parseTile(tileKey);
  }
  const tileArea=t.w*t.h;
  if(!tileArea||!areaSqft) return {needed:0,extra:0,total:0,boxes:0,tileArea};
  const needed=Math.ceil(areaSqft/tileArea);
  const extra =Math.ceil(needed*(wastePct/100));
  const total =needed+extra;
  const boxes =Math.ceil(total/t.ppb);
  return {needed,extra,total,boxes,tileArea};
}
function calcTilesByCount(count,tileKey,wastePct,customW,customH,customPPB){
  let ppb=1;
  if(tileKey==='custom'){ ppb=parseInt(customPPB)||1; }
  else { ppb=parseTile(tileKey).ppb||1; }
  const extra=Math.ceil(count*(wastePct/100));
  const total=count+extra;
  const boxes=Math.ceil(total/ppb);
  return {needed:count,extra,total,boxes};
}

function calcLandingTiles(card,prefix,tileKey){
  const length = parseFloat(card.querySelector(`[data-field="${prefix}landingl"]`)?.value)||0;
  const breadth = parseFloat(card.querySelector(`[data-field="${prefix}landingb"]`)?.value)||0;
  const area = +(length*breadth).toFixed(4);
  let tile = tileKey === 'custom' ? _getCustomTile(card,prefix) : parseTile(tileKey);
  const tileArea = (parseFloat(tile.w)||0) * (parseFloat(tile.h)||0);
  const tiles = area && tileArea ? Math.ceil(area / tileArea) : 0;
  return {length,breadth,area,tileArea,tiles};
}

function _getWasteVal(card,field){
  const sel=card.querySelector(`[data-field="${field}"]`);
  if(!sel) return 0;
  if(sel.value==='custom'){
    const cust=card.querySelector(`[data-field="${field}custom"]`);
    return parseFloat(cust?.value)||0;
  }
  return parseFloat(sel.value)||0;
}
function _getCustomTile(card,prefix){
  return {
    w:   card.querySelector(`[data-field="${prefix}custw"]`)?.value||0,
    h:   card.querySelector(`[data-field="${prefix}custh"]`)?.value||0,
    ppb: card.querySelector(`[data-field="${prefix}custppb"]`)?.value||1,
  };
}

/* ════════════════════════════════════════════════════════════════
   DIMENSION COLUMN HELPERS  (separate L / B / H arrays)
════════════════════════════════════════════════════════════════ */

/** Read all values for one dimension input type for POST serialization */
function _readDimCol(card, colRole){
  const selector = colRole === 'l' ? '.dim-row-length'
    : colRole === 'b' ? '.dim-row-breadth'
    : '.dim-row-height';
  const inputs = card.querySelectorAll(`.dim-val-row ${selector}`);
  let sum = 0;
  const vals = [];
  inputs.forEach(inp => {
    const v = parseFloat(inp.value)||0;
    vals.push(v);
    sum += v;
  });
  return { sum: +sum.toFixed(4), vals };
}

function _readDimRows(card){
  const rows = card.querySelectorAll('.dim-val-row');
  const vals = [];
  let total = 0, floorTotal = 0, wallTotal = 0;
  rows.forEach(row=>{
    const l = parseFloat(row.querySelector('.dim-row-length')?.value)||0;
    const b = parseFloat(row.querySelector('.dim-row-breadth')?.value)||0;
    const h = parseFloat(row.querySelector('.dim-row-height')?.value)||0;
    const floor = !!row.querySelector('.dim-row-floor')?.checked;
    const wall  = !!row.querySelector('.dim-row-wall')?.checked;
    const floorArea = floor ? +(l*b).toFixed(4) : 0;
    const wallArea  = wall  ? +(l*h).toFixed(4) : 0;
    const rowTotal = +(floorArea + wallArea).toFixed(4);
    vals.push({l,b,h,floor,wall,floorArea,wallArea,total:rowTotal});
    total += rowTotal;
    floorTotal += floorArea;
    wallTotal  += wallArea;
    const out = row.querySelector('.dim-row-total');
    if(out) out.textContent = rowTotal ? `${rowTotal.toFixed(2)} ft²` : '—';
  });
  return {
    vals,
    total:+total.toFixed(4),
    floorTotal:+floorTotal.toFixed(4),
    wallTotal:+wallTotal.toFixed(4),
  };
}

/** Update the totals bar (floor sqft, wall sqft) */
function _updateDimTotalsBar(card){
  const d = _getDimSqft(card);

  const bar = card.querySelector('.dim-totals-bar');
  if(!bar) return;
  bar.innerHTML =
    chipSmall('Rows', d.rows.length, '#7c3aed')
   +chipSmall('Floor / room', d.floorSingle.toFixed(2)+' ft²', '#16a34a', true)
   +chipSmall('Wall / room', d.wallSingle.toFixed(2)+' ft²', '#2563eb', true)
   +chipSmall('Overall', d.overallSingle.toFixed(2)+' ft²', '#f59e0b', true)
   +chipSmall('Multiple', '×'+d.multiplier, '#dc2626', true);
}

function chipSmall(label, val, color, bold=false){
  return `<div class="dim-total-chip">
    <span class="dtv" style="color:${color};${bold?'font-size:.88rem':''}">${val}</span>
    <span class="dtl">${label}</span>
  </div>`;
}

function _syncDimRowMode(row){
  if(!row) return;
  const floor = !!row.querySelector('.dim-row-floor')?.checked;
  const wall  = !!row.querySelector('.dim-row-wall')?.checked;
  const breadthInput = row.querySelector('.dim-row-breadth');
  const heightInput  = row.querySelector('.dim-row-height');
  const breadthOp    = row.querySelector('.dim-op-breadth');
  const heightOp     = row.querySelector('.dim-op-height');

  if(breadthInput) breadthInput.classList.toggle('dim-input-hide', wall && !floor);
  if(heightInput)  heightInput.classList.toggle('dim-input-hide', floor && !wall);
  if(breadthOp)    breadthOp.classList.toggle('dim-input-hide', wall && !floor);
  if(heightOp)     heightOp.classList.toggle('dim-input-hide', floor && !wall);

  if(!floor && !wall){
    if(breadthInput) breadthInput.classList.remove('dim-input-hide');
    if(heightInput)  heightInput.classList.remove('dim-input-hide');
    if(breadthOp)    breadthOp.classList.remove('dim-input-hide');
    if(heightOp)     heightOp.classList.remove('dim-input-hide');
  }
}

function _syncAllDimRows(card){
  card?.querySelectorAll('.dim-val-row').forEach(_syncDimRowMode);
}

function _syncCalcHiddenFields(card, payload){
  const pairs = {
    area_length: payload.L ?? '',
    area_breadth: payload.B ?? '',
    area_height: payload.H ?? '',
    total_sqft: payload.totalSqft ?? '',
    floor_sqft: payload.floorSqft ?? '',
    wall_sqft: payload.wallSqft ?? '',
    calc_summary: payload.calcSummary ?? '',
  };
  Object.entries(pairs).forEach(([field,val])=>{
    const input = card.querySelector(`[data-field="${field}"]`);
    if(input) input.value = val === null || typeof val === 'undefined' ? '' : val;
  });
}

/** Get computed floor + wall sqft from current dim inputs */
function _getDimSqft(card){
  const rowsData = _readDimRows(card);
  const firstL = rowsData.vals.find(v=>v.l>0)?.l || rowsData.vals[0]?.l || 0;
  const firstB = rowsData.vals.find(v=>v.b>0)?.b || rowsData.vals[0]?.b || 0;
  const firstH = rowsData.vals.find(v=>v.h>0)?.h || rowsData.vals[0]?.h || 0;
  const wallLength = rowsData.vals.reduce((sum, row)=> sum + (row.wall ? row.l : 0), 0);
  const multiplier = Math.max(1, parseInt(card.querySelector('[data-field="areacount"]')?.value, 10)||1);
  return {
    L: firstL||0,
    B: firstB||0,
    H: firstH||0,
    wallLength: +wallLength.toFixed(4),
    rows: rowsData.vals,
    multiplier,
    overallSingle: +(rowsData.floorTotal + rowsData.wallTotal).toFixed(4),
    floorSingle: rowsData.floorTotal,
    wallSingle: rowsData.wallTotal,
    floorSqft: +(rowsData.floorTotal * multiplier).toFixed(4),
    wallSqft:  +(rowsData.wallTotal  * multiplier).toFixed(4),
  };
}

function _syncBathHeightInputs(card, roomHeight){
  card.querySelectorAll('[data-field="blighth"],[data-field="bhighh"],[data-field="bhigh2h"],[data-field="bdarkh"],[data-field="bfallh"]')
    .forEach(input=>{
      if(roomHeight > 0){
        input.max = roomHeight.toFixed(2);
        input.setAttribute('title', `Max ${roomHeight.toFixed(2)} ft`);
        const current = parseFloat(input.value);
        if(current > roomHeight) input.value = roomHeight.toFixed(2);
      } else {
        input.removeAttribute('max');
        input.removeAttribute('title');
      }
    });
}

/** Called whenever any dim input changes */
function onDimChange(input, sk){
  const card = document.getElementById('roomcard-'+sk);
  if(!card) return;
  const row = input?.closest?.('.dim-val-row');
  if(row) _syncDimRowMode(row);
  _updateDimTotalsBar(card);
  _recalcCard(card);
}

/** Add one L × B × H row */
function addDimVal(sk){
  const card = document.getElementById('roomcard-'+sk);
  if(!card) return;
  const list = card.querySelector('.dim-val-list');
  if(!list)  return;
  const np   = card.dataset.isnew==='1'?'new':'edit';
  const surf = card.querySelector('[data-field="surf"]')?.value||'floor';
  const rowMode = { floor: surf!=='wall', wall: surf==='wall' || surf==='both' };
  const div  = document.createElement('div');
  div.innerHTML = _dimValRowHtml(sk, np, '', '', '', rowMode);
  const row = div.firstElementChild;
  list.appendChild(row);
  _syncDimRowMode(row);
  _updateDimTotalsBar(card);
  _recalcCard(card);
}

/** Remove one L × B × H row */
function removeDimVal(btn, sk){
  const card = document.getElementById('roomcard-'+sk);
  const list = card?.querySelector('.dim-val-list');
  if(!list) return;
  const rows = list.querySelectorAll('.dim-val-row');
  if(rows.length <= 1){ alert('At least one dimension row is required.'); return; }
  btn.closest('.dim-val-row').remove();
  _updateDimTotalsBar(card);
  _recalcCard(card);
}

/** HTML for one L × B × H row */
function _dimValRowHtml(sk, np, lVal='', bVal='', hVal='', rowMode={}){
  const floorChecked = rowMode.floor !== false;
  const wallChecked = !!rowMode.wall;
  return `<div class="dim-val-row">
    <label class="dim-row-pick floor">
      <input class="dim-row-floor" type="checkbox"${floorChecked?' checked':''}
        onchange="onDimChange(this,'${sk}')"> Floor
    </label>
    <label class="dim-row-pick wall">
      <input class="dim-row-wall" type="checkbox"${wallChecked?' checked':''}
        onchange="onDimChange(this,'${sk}')"> Wall
    </label>
    <input class="dim-row-length" type="number" step="0.01" min="0" placeholder="Length"
      name="${np}_lengths[${sk}][]" value="${_escHtml(lVal)}"
      onchange="onDimChange(this,'${sk}')">
    <span class="dim-op dim-op-breadth">×</span>
    <input class="dim-row-breadth" type="number" step="0.01" min="0" placeholder="Breadth"
      name="${np}_breadths[${sk}][]" value="${_escHtml(bVal)}"
      onchange="onDimChange(this,'${sk}')">
    <span class="dim-op dim-op-height">×</span>
    <input class="dim-row-height" type="number" step="0.01" min="0" placeholder="Height"
      name="${np}_heights[${sk}][]" value="${_escHtml(hVal)}"
      onchange="onDimChange(this,'${sk}')">
    <span class="dim-row-total" style="min-width:88px;font-family:'DM Mono',monospace;font-weight:800;color:var(--c-accent2)">—</span>
    <button type="button" class="dim-del-val-btn"
      onclick="removeDimVal(this,'${sk}')">✕</button>
  </div>`;
}

/** Build the full dimension section HTML */
function buildDimSectionHtml(sk, isNew, savedL, savedB, savedH, opts={}){
  const np = isNew?'new':'edit';
  let calcSummary = {};
  try{
    calcSummary = typeof opts.calc_summary === 'string'
      ? (JSON.parse(opts.calc_summary||'{}')||{})
      : (opts.calc_summary||{});
  }catch(e){ calcSummary = {}; }
  const savedRowModes = Array.isArray(calcSummary?.meta?.row_modes) ? calcSummary.meta.row_modes : [];
  const areaCount = Math.max(1, parseInt(opts.area_count, 10)||1);
  const surf = opts.tile_surface||'floor';
  const defaultRowMode = {
    floor: surf!=='wall',
    wall:  surf==='wall' || surf==='both',
  };

  /* Fall back to at least one empty dimension row */
  if(!savedL||!savedL.length) savedL=[''];
  if(!savedB||!savedB.length) savedB=[''];
  if(!savedH||!savedH.length) savedH=[''];
  const rowCount = Math.max(savedL.length, savedB.length, savedH.length, 1);
  const rows = Array.from({length:rowCount}, (_,i)=>
    _dimValRowHtml(sk, np, savedL[i] ?? '', savedB[i] ?? '', savedH[i] ?? '', savedRowModes[i]||defaultRowMode)
  ).join('');

  let floorTotal = 0, wallTotal = 0;
  for(let i=0;i<rowCount;i++){
    const l = parseFloat(savedL[i])||0;
    const b = parseFloat(savedB[i])||0;
    const h = parseFloat(savedH[i])||0;
    const mode = savedRowModes[i] || defaultRowMode;
    if(mode.floor !== false) floorTotal += l*b;
    if(mode.wall) wallTotal += l*h;
  }
  const floorSqft = +(floorTotal * areaCount).toFixed(2);
  const wallSqft = +(wallTotal * areaCount).toFixed(2);
  const overallSqft = +(floorSqft + wallSqft).toFixed(2);

  return `<div class="dim-section">
    <div class="dim-section-title">
      <span>📐 Area Dimensions</span>
      <button type="button" class="add-val-btn" onclick="addDimVal('${sk}')">+ Add More</button>
    </div>
    <div class="dim-help-text" style="font-size:.85rem;color:var(--c-gray-500);margin:8px 0 10px">
      Choose Floor and/or Wall for each row, then enter Length, Breadth and Height. Use Multiple when the same room dimensions repeat.
    </div>
    <div class="dim-val-list">${rows}</div>
    <div class="dim-totals-bar">
      ${chipSmall('Rows', rowCount, '#7c3aed')}
      ${chipSmall('Floor / room', floorTotal.toFixed(2)+' ft²', '#16a34a', true)}
      ${chipSmall('Wall / room', wallTotal.toFixed(2)+' ft²', '#2563eb', true)}
      ${chipSmall('Overall', (floorTotal + wallTotal).toFixed(2)+' ft²', '#f59e0b', true)}
      ${chipSmall('Multiple', '×'+areaCount, '#dc2626', true)}
    </div>
    <div class="dim-multiple-wrap">
      <div class="usm-field" style="margin-bottom:0">
        <label>Multiple Rooms</label>
        <input type="number" min="1" step="1"
          name="${np}_area_count[${sk}]" data-field="areacount"
          value="${areaCount}" onchange="onDimChange(this,'${sk}')">
      </div>
      <div class="dim-multiple-note">
        Floor total: <span class="mult-red">${floorSqft.toFixed(2)} ft²</span> and wall total:
        <span class="mult-red">${wallSqft.toFixed(2)} ft²</span> after multiplying by ${areaCount}.
        Overall total: <span class="mult-red">${overallSqft.toFixed(2)} ft²</span>.
        Bathroom partition stays as a single room and shows multiplied values only as a note.
      </div>
    </div>
  </div>`;
}

/* ════════════════════════════════════════════════════════════════
   RECALC CARD
════════════════════════════════════════════════════════════════ */
function _recalcCard(card){
  const surf = card.querySelector('[data-field="surf"]')?.value||'floor';
  const isBath = card.querySelector('[data-field="isbath"]')?.checked;
  const isStepRiser = ['step','riser','step_riser'].includes(surf);
  const dimData = !isStepRiser ? _getDimSqft(card) : {
    L:0,B:0,H:0,wallLength:0,rows:[],multiplier:1,overallSingle:0,floorSingle:0,wallSingle:0,floorSqft:0,wallSqft:0
  };
  _syncBathHeightInputs(card, dimData.H || 0);
  let floorCalcMeta = null, wallCalcMeta = null, bathCalcMeta = null;

  let floorSqft = dimData.floorSqft;
  let wallSqft  = dimData.wallSqft;
  let floorSingleBoxes = 0, wallSingleBoxes = 0, bathSingleBoxes = 0;

  /* Header sqft badge */
  const sqftEl = card.querySelector('.rc-sqft');
  if(sqftEl && !isStepRiser){
    const show = surf==='wall' ? wallSqft : (surf==='both' ? (floorSqft + wallSqft) : floorSqft);
    sqftEl.textContent = show ? show+' ft²' : '';
  } else if(sqftEl){ sqftEl.textContent=''; }

  /* Header name */
  const nameInput = card.querySelector('[data-field="atype"]');
  const nameEl    = card.querySelector('.rc-name');
  if(nameEl && nameInput) nameEl.textContent = nameInput.value||'Room';

  /* Header brand */
  const desSel  = card.querySelector('[data-field="tdesign"]');
  const brandEl = card.querySelector('.rc-brand');
  if(brandEl){
    const co  = _getCompanyDisplay(card);
    const des = desSel?.value||'';
    brandEl.textContent = [co,des].filter(Boolean).join(' — ');
  }

  const chipsEl = card.querySelector('.calc-chips');
  if(!chipsEl) return;
  chipsEl.innerHTML='';

  /* ══ STEP ══ */
  if(surf==='step'){
    const count  = parseInt(card.querySelector('[data-field="stepcount"]')?.value)||0;
    const sKey   = card.querySelector('[data-field="stilekey"]')?.value||STEP_TILE_OPTIONS[0].v;
    const sWaste = _getWasteVal(card,'swaste');
    const landing = calcLandingTiles(card,'s',sKey);
    const overallCount = count + landing.tiles;
    if(overallCount){
      const sc=calcTilesByCount(overallCount,sKey,sWaste,
        card.querySelector('[data-field="scustw"]')?.value,
        card.querySelector('[data-field="scusth"]')?.value,
        card.querySelector('[data-field="scustppb"]')?.value);
      const t=parseTile(sKey);
      chipsEl.innerHTML+=chipHtml('Steps',count,'step-clr')
        +chipHtml('Landing Area',landing.area.toFixed(2)+' ft²','step-clr')
        +chipHtml('Landing Tiles',landing.tiles,'step-clr')
        +chipHtml('Overall Step',overallCount,'highlight')
        +chipHtml('Tile Size',`${t.w}×${t.h} ft`,'step-clr')
        +chipHtml('Wastage',sc.extra+` (${sWaste}%)`,'step-clr')
        +chipHtml('Total Tiles',sc.total,'step-clr')
        +chipHtml('📦 Boxes',sc.boxes,'highlight');
      if(sKey==='custom') _updateCustomSizeSummary(card,'s',`${t.w}×${t.h} ft (${t.ppb}/box)`,sc.boxes);
      else _updateCustomSizeSummary(card,'s','',0);
    }
    _syncCalcHiddenFields(card,{L:'',B:'',H:'',totalSqft:'',floorSqft:'',wallSqft:'',calcSummary:''});
    return;
  }

  /* ══ RISER ══ */
  if(surf==='riser'){
    const count  = parseInt(card.querySelector('[data-field="risercount"]')?.value)||0;
    const rKey   = card.querySelector('[data-field="rtilekey"]')?.value||RISER_TILE_OPTIONS[0].v;
    const rWaste = _getWasteVal(card,'rwaste');
    const landing = calcLandingTiles(card,'r',rKey);
    const overallCount = count + landing.tiles;
    if(overallCount){
      const rc=calcTilesByCount(overallCount,rKey,rWaste,
        card.querySelector('[data-field="rcustw"]')?.value,
        card.querySelector('[data-field="rcusth"]')?.value,
        card.querySelector('[data-field="rcustppb"]')?.value);
      const t=parseTile(rKey);
      chipsEl.innerHTML+=chipHtml('Risers',count,'riser-clr')
        +chipHtml('Landing Area',landing.area.toFixed(2)+' ft²','riser-clr')
        +chipHtml('Landing Tiles',landing.tiles,'riser-clr')
        +chipHtml('Overall Riser',overallCount,'highlight')
        +chipHtml('Tile Size',`${t.w}×${t.h} ft`,'riser-clr')
        +chipHtml('Wastage',rc.extra+` (${rWaste}%)`,'riser-clr')
        +chipHtml('Total Tiles',rc.total,'riser-clr')
        +chipHtml('📦 Boxes',rc.boxes,'highlight');
      if(rKey==='custom') _updateCustomSizeSummary(card,'r',`${t.w}×${t.h} ft (${t.ppb}/box)`,rc.boxes);
      else _updateCustomSizeSummary(card,'r','',0);
    }
    _syncCalcHiddenFields(card,{L:'',B:'',H:'',totalSqft:'',floorSqft:'',wallSqft:'',calcSummary:''});
    return;
  }

  /* ══ STEP + RISER ══ */
  if(surf==='step_riser'){
    const sCount = parseInt(card.querySelector('[data-field="stepcount"]')?.value)||0;
    const rCount = parseInt(card.querySelector('[data-field="risercount"]')?.value)||0;
    const srKey  = card.querySelector('[data-field="srtilekey"]')?.value||STEP_RISER_TILE_OPTIONS[0].v;
    const srW    = _getWasteVal(card,'srwaste');
    const landing = calcLandingTiles(card,'sr',srKey);
    const total  = sCount+rCount+landing.tiles;
    if(total){
      const src=calcTilesByCount(total,srKey,srW,
        card.querySelector('[data-field="srcustw"]')?.value,
        card.querySelector('[data-field="srcusth"]')?.value,
        card.querySelector('[data-field="srcustppb"]')?.value);
      const t=parseTile(srKey);
      chipsEl.innerHTML+=chipHtml('Steps',sCount,'step-clr')
        +chipHtml('Risers',rCount,'riser-clr')
        +chipHtml('Landing Area',landing.area.toFixed(2)+' ft²','riser-clr')
        +chipHtml('Landing Tiles',landing.tiles,'riser-clr')
        +chipHtml('Total Pieces',total,'highlight')
        +chipHtml('Tile Size',`${t.w}×${t.h} ft`,'')
        +chipHtml('Wastage',src.extra+` (${srW}%)`,'')
        +chipHtml('Total Tiles',src.total,'')
        +chipHtml('📦 Boxes',src.boxes,'highlight');
    }
    _syncCalcHiddenFields(card,{L:'',B:'',H:'',totalSqft:'',floorSqft:'',wallSqft:'',calcSummary:''});
    return;
  }

  /* ══ FLOOR ══ */
  if(surf==='floor'||surf==='both'){
    const fKey   = card.querySelector('[data-field="ftilekey"]')?.value||FLOOR_TILE_OPTIONS[3].v;
    const fWaste = _getWasteVal(card,'fwaste');
    let fc, fcSingle, tileDimF;
    if(fKey==='custom'){
      const c=_getCustomTile(card,'f');
      fc=calcTiles(floorSqft,fKey,fWaste,c.w,c.h,c.ppb);
      fcSingle=calcTiles(dimData.floorSingle,fKey,fWaste,c.w,c.h,c.ppb);
      tileDimF=`${c.w}×${c.h} ft (${c.ppb}/box)`;
      _updateCustomSizeSummary(card,'f',tileDimF,fc.boxes);
    } else {
      fc=calcTiles(floorSqft,fKey,fWaste);
      fcSingle=calcTiles(dimData.floorSingle,fKey,fWaste);
      const t=parseTile(fKey);
      tileDimF=`${t.w}×${t.h} ft (${t.ppb}/box)`;
      _updateCustomSizeSummary(card,'f','',0);
    }
    floorSingleBoxes = fcSingle.boxes;
    floorCalcMeta = {
      tile_size: tileDimF,
      waste_pct: fWaste,
      tiles_single: fcSingle.total,
      tiles_total: fc.total,
      boxes_single: fcSingle.boxes,
      boxes_total: fc.boxes
    };
    chipsEl.innerHTML+=chipHtml('Floor / room',dimData.floorSingle.toFixed(2)+' ft²','floor')
      +chipHtml('Total Area',floorSqft.toFixed(2)+' ft²','floor')
      +chipHtml('Tile Size',tileDimF,'floor')
      +chipHtml('Tiles Needed',fcSingle.needed.toLocaleString(),'floor')
      +chipHtml('Wastage',fc.extra.toLocaleString()+` (${fWaste}%)`,'floor')
      +chipHtml('📦 Boxes / room',fcSingle.boxes.toLocaleString(),'floor')
      +chipHtml('📦 Total Boxes',fc.boxes.toLocaleString(),'highlight');
  }

  /* ══ WALL ══ */
  if(surf==='wall'||surf==='both'){
    const wKey   = card.querySelector('[data-field="wtilekey"]')?.value||WALL_TILE_OPTIONS[0].v;
    const wWaste = _getWasteVal(card,'wwaste');
    let wc, wcSingle, tileDimW;
    if(wKey==='custom'){
      const c=_getCustomTile(card,'w');
      wc=calcTiles(wallSqft,wKey,wWaste,c.w,c.h,c.ppb);
      wcSingle=calcTiles(dimData.wallSingle,wKey,wWaste,c.w,c.h,c.ppb);
      tileDimW=`${c.w}×${c.h} ft (${c.ppb}/box)`;
      _updateCustomSizeSummary(card,'w',tileDimW,wc.boxes);
    } else {
      wc=calcTiles(wallSqft,wKey,wWaste);
      wcSingle=calcTiles(dimData.wallSingle,wKey,wWaste);
      const t=parseTile(wKey);
      tileDimW=`${t.w}×${t.h} ft (${t.ppb}/box)`;
      _updateCustomSizeSummary(card,'w','',0);
    }
    wallSingleBoxes = wcSingle.boxes;
    wallCalcMeta = {
      tile_size: tileDimW,
      waste_pct: wWaste,
      tiles_single: wcSingle.total,
      tiles_total: wc.total,
      boxes_single: wcSingle.boxes,
      boxes_total: wc.boxes
    };
    chipsEl.innerHTML+=chipHtml('Wall / room',dimData.wallSingle.toFixed(2)+' ft²','wall')
      +chipHtml('Total Area',wallSqft.toFixed(2)+' ft²','wall')
      +chipHtml('Tile Size',tileDimW,'wall')
      +chipHtml('Tiles Needed',wcSingle.needed.toLocaleString(),'wall')
      +chipHtml('Wastage',wc.extra.toLocaleString()+` (${wWaste}%)`,'wall')
      +chipHtml('📦 Boxes / room',wcSingle.boxes.toLocaleString(),'wall')
      +chipHtml('📦 Total Boxes',wc.boxes.toLocaleString(),'highlight');
  }

  if(!isStepRiser && (surf==='floor'||surf==='wall'||surf==='both')){
    const grandBoxes = (surf==='floor' ? floorSingleBoxes : 0)
      + (surf==='wall' ? wallSingleBoxes : 0)
      + (surf==='both' ? floorSingleBoxes + wallSingleBoxes : 0);
    const totalBoxes = grandBoxes * dimData.multiplier;
    chipsEl.innerHTML+=chipHtml('Rooms',dimData.multiplier,'')
      +chipHtml('Overall / room',dimData.overallSingle.toFixed(2)+' ft²','')
      +chipHtml('📦 Single Room Total',grandBoxes.toLocaleString(),'')
      +chipHtml('📦 Grand Total Boxes',totalBoxes.toLocaleString(),'highlight');
  }

  /* ══ BATHROOM PARTITION ══ */
  if(isBath && !isStepRiser){
    const wallLength = dimData.wallLength || 0;
    const roomHeight = dimData.H||0;
    const wallTileKey = card.querySelector('[data-field="wtilekey"]')?.value||WALL_TILE_OPTIONS[0].v;
    const wallWastePct = _getWasteVal(card,'wwaste');
    let wallTileCustom = null;
    let wallTileDim = '';
    if(wallTileKey === 'custom'){
      wallTileCustom = _getCustomTile(card,'w');
      wallTileDim = `${wallTileCustom.w}×${wallTileCustom.h} ft (${wallTileCustom.ppb}/box)`;
    } else {
      const wt = parseTile(wallTileKey);
      wallTileDim = `${wt.w}×${wt.h} ft (${wt.ppb}/box)`;
    }

    const pLight = parseFloat(card.querySelector('[data-field="blighth"]')?.value)||0;
    const pHigh1 = parseFloat(card.querySelector('[data-field="bhighh"]')?.value)||0;
    const pHigh2 = parseFloat(card.querySelector('[data-field="bhigh2h"]')?.value)||0;
    const pDark  = parseFloat(card.querySelector('[data-field="bdarkh"]')?.value)||0;
    const pFall  = parseFloat(card.querySelector('[data-field="bfallh"]')?.value)||0;
    let usedHeight = 0;
    let overHeight = false;
    const parts  = [
      {label:'Light', inputH:pLight, cls:'light'},
      {label:'Highlight 1', inputH:pHigh1, cls:'high'},
      {label:'Highlight 2', inputH:pHigh2, cls:'high'},
      {label:'Dark', inputH:pDark, cls:'dark'},
      {label:'Fall', inputH:pFall, cls:'dark'},
    ].map(p=>{
      const remaining = Math.max(0, roomHeight - usedHeight);
      const appliedH = Math.min(p.inputH, remaining);
      if(p.inputH > appliedH) overHeight = true;
      usedHeight += appliedH;
      return {...p, h:appliedH};
    });
    const partCardsEl = card.querySelector('.bath-part-cards');
    if(partCardsEl){
      partCardsEl.innerHTML = parts.map(p=>{
        if(!p.inputH||!wallLength) return `<div class="bath-part-card ${p.cls}">
          <div class="bpc-name">${p.label}</div>
          <div class="bpc-row"><span class="bpc-k">Area</span><span class="bpc-v">—</span></div>
          <div class="bpc-row"><span class="bpc-k">Tiles Needed</span><span class="bpc-v">—</span></div>
          <div class="bpc-row"><span class="bpc-k">📦 Boxes</span><span class="bpc-v amber">—</span></div>
        </div>`;
        const area=wallLength*p.h;
        const bc=calcTiles(area,wallTileKey,wallWastePct,
          wallTileCustom ? wallTileCustom.w : undefined,
          wallTileCustom ? wallTileCustom.h : undefined,
          wallTileCustom ? wallTileCustom.ppb : undefined
        );
        return `<div class="bath-part-card ${p.cls}">
          <div class="bpc-name">${p.label}</div>
          <div class="bpc-row"><span class="bpc-k">Height</span><span class="bpc-v">${p.h.toFixed(2)} ft</span></div>
          ${p.inputH!==p.h?`<div class="bpc-row"><span class="bpc-k">Requested</span><span class="bpc-v">${p.inputH.toFixed(2)} ft</span></div>`:''}
          <div class="bpc-row"><span class="bpc-k">Wall Length</span><span class="bpc-v">${wallLength.toFixed(2)} ft</span></div>
          <div class="bpc-row"><span class="bpc-k">Area</span><span class="bpc-v">${area.toFixed(2)} ft²</span></div>
          <div class="bpc-row"><span class="bpc-k">Calc</span><span class="bpc-v">${wallLength.toFixed(2)} × ${p.h.toFixed(2)}</span></div>
          <div class="bpc-row"><span class="bpc-k">Wall Tile</span><span class="bpc-v">${wallTileDim}</span></div>
          <div class="bpc-row"><span class="bpc-k">Tiles Needed</span><span class="bpc-v">${bc.needed.toLocaleString()}</span></div>
          <div class="bpc-row"><span class="bpc-k">Total Tiles</span><span class="bpc-v">${bc.total.toLocaleString()}</span></div>
          <div class="bpc-row"><span class="bpc-k">Wastage</span><span class="bpc-v">${bc.extra.toLocaleString()} (${wallWastePct}%)</span></div>
          <div class="bpc-row"><span class="bpc-k">📦 Boxes</span><span class="bpc-v amber">${bc.boxes.toLocaleString()}</span></div>
        </div>`;
      }).join('');
    }
    const bathTotalEl = card.querySelector('.bath-chips-total');
    if(bathTotalEl){
      let tA=0,tB=0;
      parts.forEach(p=>{
        if(!p.h||!wallLength) return;
        const area=wallLength*p.h;
        const bc=calcTiles(area,wallTileKey,wallWastePct,
          wallTileCustom ? wallTileCustom.w : undefined,
          wallTileCustom ? wallTileCustom.h : undefined,
          wallTileCustom ? wallTileCustom.ppb : undefined
        );
        tA+=area;
        tB+=bc.boxes;
      });
      bathCalcMeta = {
        area_single: +tA.toFixed(4),
        wall_length: +wallLength.toFixed(4),
        tile_size: wallTileDim,
        waste_pct: wallWastePct,
        boxes_single: tB,
        parts: parts.filter(p=>p.inputH>0).map(p=>({
          area:+(wallLength*p.h).toFixed(4),
          ...(()=>{
            const bc=calcTiles(wallLength*p.h,wallTileKey,wallWastePct,
              wallTileCustom ? wallTileCustom.w : undefined,
              wallTileCustom ? wallTileCustom.h : undefined,
              wallTileCustom ? wallTileCustom.ppb : undefined
            );
            return {
              tiles_needed: bc.needed,
              tiles_total: bc.total,
              boxes: bc.boxes
            };
          })(),
          label:p.label,
          requested_height:+p.inputH.toFixed(4),
          height:+p.h.toFixed(4)
        }))
      };
      bathTotalEl.innerHTML = tA>0
        ? chipHtml('Partition Area',tA.toFixed(1)+' ft²','wall')
        + chipHtml('Wall Length',wallLength.toFixed(2)+' ft','wall')
        + chipHtml('Room Height',roomHeight.toFixed(2)+' ft','wall')
        + chipHtml('📦 Boxes / room',tB.toLocaleString(),'highlight')
        : '';
    }
    const bathNoteEl = card.querySelector('.bath-note');
    if(bathNoteEl){
      const noteBits = [];
      if(overHeight && roomHeight>0){
        noteBits.push(`Bathroom partition heights are capped by the room height ${roomHeight.toFixed(2)} ft, so any extra combined height was ignored.`);
        bathNoteEl.className = 'bath-note error';
      } else {
        bathNoteEl.className = 'bath-note';
      }
      if(dimData.multiplier > 1 && bathCalcMeta && bathCalcMeta.area_single > 0){
        noteBits.push(`Multiplied value only: <span class="mult-red">x${dimData.multiplier}</span> means ${bathCalcMeta.area_single.toFixed(2)} ft² and ${bathCalcMeta.boxes_single.toLocaleString()} boxes for one room, and ${(bathCalcMeta.area_single * dimData.multiplier).toFixed(2)} ft² with ${(bathCalcMeta.boxes_single * dimData.multiplier).toLocaleString()} boxes for ${dimData.multiplier} same rooms.`);
      }
      bathNoteEl.innerHTML = noteBits.join(' ');
      bathNoteEl.style.display = noteBits.length ? 'block' : 'none';
    }
  }

  const calcSummary = JSON.stringify({
    sqft:{
      single_room_floor: +dimData.floorSingle.toFixed(4),
      single_room_wall: +dimData.wallSingle.toFixed(4),
      single_room_total: +dimData.overallSingle.toFixed(4),
      floor_total: +floorSqft.toFixed(4),
      wall_total: +wallSqft.toFixed(4),
      overall_total: +(floorSqft + wallSqft).toFixed(4),
      room_height: +dimData.H.toFixed(4),
    },
    surf,
    floor:floorCalcMeta || {boxes_single:floorSingleBoxes, boxes_total:floorSingleBoxes * dimData.multiplier},
    wall:wallCalcMeta || {boxes_single:wallSingleBoxes, boxes_total:wallSingleBoxes * dimData.multiplier},
    bath:bathCalcMeta || {boxes_single:bathSingleBoxes},
    meta:{
      multiplier: dimData.multiplier,
      grand_total_boxes: (floorSingleBoxes + wallSingleBoxes) * dimData.multiplier,
      row_modes: dimData.rows.map(r=>({floor:r.floor, wall:r.wall})),
      rows: dimData.rows.map((r, idx)=>({
        row: idx + 1,
        length: +r.l.toFixed(4),
        breadth: +r.b.toFixed(4),
        height: +r.h.toFixed(4),
        floor: !!r.floor,
        wall: !!r.wall,
        floor_formula: r.floor ? `${r.l} × ${r.b}` : '',
        wall_formula: r.wall ? `${r.l} × ${r.h}` : '',
        floor_area: +r.floorArea.toFixed(4),
        wall_area: +r.wallArea.toFixed(4),
        total_area: +r.total.toFixed(4)
      }))
    }
  });
  _syncCalcHiddenFields(card,{
    L: dimData.L,
    B: dimData.B,
    H: dimData.H,
    totalSqft: +(floorSqft + wallSqft).toFixed(4),
    floorSqft: +floorSqft.toFixed(4),
    wallSqft: +wallSqft.toFixed(4),
    calcSummary
  });
}

function calcRoom(el){ const c=el?.closest('.room-card'); if(c) _recalcCard(c); }

function _updateCustomSizeSummary(card,prefix,label,boxes){
  const el = card.querySelector(`#customsize-summary-${prefix}-${card.dataset.sk}`);
  if(!el) return;
  el.textContent = label ? `${label} · 📦 ${boxes.toLocaleString()} boxes` : '';
}

function chipHtml(label,val,cls=''){
  const fv=(typeof val==='number')?val.toLocaleString():val;
  return `<div class="calc-chip ${cls}">
    <div class="cv">${fv}</div><div class="cl">${label}</div>
  </div>`;
}

/* ════════════════════════════════════════════════════════════════
   TILE SIZE SELECT HTML
════════════════════════════════════════════════════════════════ */
function tileSizeSelectHtml(selectName,dataField,options,selectedVal,sk,prefix,np,existingCustom){
  const opts=options.map(o=>
    `<option value="${o.v}"${o.v===selectedVal?' selected':''}>${o.l}</option>`
  ).join('');
  const isCustom=selectedVal==='custom';
  return `<select name="${selectName}" data-field="${dataField}"
    onchange="onTileSizeChange(this,'${sk}','${prefix}')">
    ${opts}
  </select>
  <div class="custom-size-wrap${isCustom?' show':''}" id="customsize-${prefix}-${sk}">
    <label>Custom Size &amp; Box Count</label>
    <div class="custom-size-grid">
      <div class="usm-field" style="margin-bottom:0">
        <label>Width (ft)</label>
        <input type="number" step="0.01" placeholder="2.00"
          name="${np}_${prefix}_custw[${sk}]" data-field="${prefix}custw"
          value="${existingCustom && existingCustom.w?existingCustom.w:''}"
          onchange="calcRoom(this)">
      </div>
      <div class="usm-field" style="margin-bottom:0">
        <label>Height (ft)</label>
        <input type="number" step="0.01" placeholder="2.00"
          name="${np}_${prefix}_custh[${sk}]" data-field="${prefix}custh"
          value="${existingCustom && existingCustom.h?existingCustom.h:''}"
          onchange="calcRoom(this)">
      </div>
      <div class="usm-field" style="margin-bottom:0">
        <label>Pieces/Box</label>
        <input type="number" step="1" placeholder="4"
          name="${np}_${prefix}_custppb[${sk}]" data-field="${prefix}custppb"
          value="${existingCustom && existingCustom.ppb?existingCustom.ppb:''}"
          onchange="calcRoom(this)">
      </div>
    </div>
    <div class="custom-size-summary" id="customsize-summary-${prefix}-${sk}"></div>
  </div>`;
}

function onTileSizeChange(sel,sk,prefix){
  const wrap=document.getElementById(`customsize-${prefix}-${sk}`);
  if(wrap) wrap.classList.toggle('show',sel.value==='custom');
  calcRoom(sel);
}

function wasteSelectHtml(selectedVal){
  return WASTE_OPTIONS.map(w=>
    `<option value="${w}"${w==selectedVal?' selected':''}>${w}%</option>`
  ).join('')
  +`<option value="custom"${!WASTE_OPTIONS.includes(+selectedVal)?' selected':''}>Custom…</option>`;
}

/* ════════════════════════════════════════════════════════════════
   RENDER TILE SECTION
════════════════════════════════════════════════════════════════ */
function renderTileSection(sk,surf,opts,isNew){
  const np=isNew?'new':'edit';
  // opts may contain existing custom JSON strings: floor_tile_custom, wall_tile_custom
  let fCustom=null, wCustom=null;
  try { fCustom = opts && opts.floor_tile_custom ? (typeof opts.floor_tile_custom==='string'? JSON.parse(opts.floor_tile_custom) : opts.floor_tile_custom) : null; } catch(e){ fCustom=null; }
  try { wCustom = opts && opts.wall_tile_custom ? (typeof opts.wall_tile_custom==='string'? JSON.parse(opts.wall_tile_custom) : opts.wall_tile_custom) : null; } catch(e){ wCustom=null; }
  const fKey = opts && opts.floor_tile_size_key ? opts.floor_tile_size_key : FLOOR_TILE_OPTIONS[3].v;
  const fWaste = opts && (opts.floor_wastage_pct??0);
  const wKey = opts && opts.wall_tile_size_key ? opts.wall_tile_size_key : WALL_TILE_OPTIONS[0].v;
  const wWaste = opts && (opts.wall_wastage_pct??10);
  let html='';
  if(surf==='floor'||surf==='both'){
    html+=`<div class="usm-grid g2" style="margin-top:10px">
      <div class="usm-field">
        <label>Floor Tile Size</label>
        ${tileSizeSelectHtml(`${np}_floor_tile_size_key[${sk}]`,'ftilekey',AREA_TILE_OPTIONS,fKey,sk,'f',np,fCustom)}
      </div>
      <div class="usm-field">
        <label>Floor Wastage %</label>
        <select name="${np}_floor_wastage_pct[${sk}]" data-field="fwaste"
          onchange="onWasteChange(this,'fwaste')">
          ${wasteSelectHtml(fWaste)}
        </select>
        <input type="number" name="${np}_floor_wastage_custom[${sk}]"
          data-field="fwastecustom" placeholder="%" min="0" max="50"
          style="margin-top:4px;display:${!WASTE_OPTIONS.includes(+fWaste)?'block':'none'}"
          value="${!WASTE_OPTIONS.includes(+fWaste)?fWaste:''}"
          onchange="calcRoom(this)">
      </div>
    </div>`;
  }
  if(surf==='wall'||surf==='both'){
    html+=`<div class="usm-grid g2" style="margin-top:${surf==='both'?'6':'10'}px">
      <div class="usm-field">
        <label>Wall Tile Size</label>
        ${tileSizeSelectHtml(`${np}_wall_tile_size_key[${sk}]`,'wtilekey',AREA_TILE_OPTIONS,wKey,sk,'w',np,wCustom)}
      </div>
      <div class="usm-field">
        <label>Wall Wastage %</label>
        <select name="${np}_wall_wastage_pct[${sk}]" data-field="wwaste"
          onchange="onWasteChange(this,'wwaste')">
          ${wasteSelectHtml(wWaste)}
        </select>
        <input type="number" name="${np}_wall_wastage_custom[${sk}]"
          data-field="wwastecustom" placeholder="%" min="0" max="50"
          style="margin-top:4px;display:${!WASTE_OPTIONS.includes(+wWaste)?'block':'none'}"
          value="${!WASTE_OPTIONS.includes(+wWaste)?wWaste:''}"
          onchange="calcRoom(this)">
      </div>
    </div>`;
  }
  return html;
}

function onWasteChange(sel,field){
  const card=sel.closest('.room-card');
  const cust=card.querySelector(`[data-field="${field}custom"]`);
  if(cust) cust.style.display=sel.value==='custom'?'block':'none';
  calcRoom(sel);
}

/* ════════════════════════════════════════════════════════════════
   RENDER STEP / RISER SECTION
════════════════════════════════════════════════════════════════ */
function renderStepRiserSection(sk,surf,opts_in,isNew){
  const np=isNew?'new':'edit';
  const opts=opts_in||{};
  // parse custom values if present (step: s/sc, riser: r, sr: sr/src)
  let sCustom=null, rCustom=null, srCustom=null;
  try { sCustom = opts && opts.step_tile_custom ? (typeof opts.step_tile_custom==='string'? JSON.parse(opts.step_tile_custom) : opts.step_tile_custom) : null; } catch(e){ sCustom=null; }
  try { rCustom = opts && opts.riser_tile_custom ? (typeof opts.riser_tile_custom==='string'? JSON.parse(opts.riser_tile_custom) : opts.riser_tile_custom) : null; } catch(e){ rCustom=null; }
  try { srCustom = opts && opts.step_riser_tile_custom ? (typeof opts.step_riser_tile_custom==='string'? JSON.parse(opts.step_riser_tile_custom) : opts.step_riser_tile_custom) : null; } catch(e){ srCustom=null; }
  let html='';

  if(surf==='step'||surf==='step_riser'){
    const sKey  = opts.step_tile_size_key||(surf==='step_riser'?STEP_RISER_TILE_OPTIONS[0].v:STEP_TILE_OPTIONS[0].v);
    const sW    = opts.step_wastage_pct??10;
    const sPfx  = surf==='step_riser'?'sr':'s';
    const sOpts = STAIR_TILE_OPTIONS;
    html+=`<div class="step-riser-wrap">
      <div class="step-riser-title ${surf==='step_riser'?'both-t':'step-t'}">🪜 Step Tiles</div>
      <div class="usm-grid g3">
        <div class="usm-field">
          <label>No. of Steps</label>
          <input type="number" step="1" placeholder="0" min="0"
            name="${np}_step_count[${sk}]" data-field="stepcount"
            value="${opts.step_count||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Extra Boxes (±)</label>
          <input type="number" step="1" placeholder="0"
            name="${np}_extra_box_step[${sk}]" data-field="extraboxstep"
            value="${opts.extra_box_step||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Step Tile Size</label>
          ${tileSizeSelectHtml(`${np}_step_tile_size_key[${sk}]`,
            surf==='step_riser'?'srtilekey':'stilekey',
            sOpts,sKey,sk,sPfx,np,(surf==='step_riser'?srCustom:sCustom))}
        </div>
        <div class="usm-field">
          <label>Wastage %</label>
          <select name="${np}_step_wastage_pct[${sk}]"
            data-field="${surf==='step_riser'?'srwaste':'swaste'}"
            onchange="onWasteChange(this,'${surf==='step_riser'?'srwaste':'swaste'}')">
            ${wasteSelectHtml(sW)}
          </select>
        </div>
      </div>
      ${surf==='step' ? `<div class="usm-grid g2" style="margin-top:8px">
        <div class="usm-field">
          <label>Landing Length (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_length[${sk}]" data-field="slandingl"
            value="${opts.riser_landing_length||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Landing Breadth (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_breadth[${sk}]" data-field="slandingb"
            value="${opts.riser_landing_breadth||''}" onchange="calcRoom(this)">
        </div>
      </div>` : ''}
    </div>`;
  }

  if(surf==='riser'){
    const rKey  = opts.riser_tile_size_key||RISER_TILE_OPTIONS[0].v;
    const rW    = opts.riser_wastage_pct??10;
    const landingL = opts.riser_landing_length||'';
    const landingB = opts.riser_landing_breadth||'';
    html+=`<div class="step-riser-wrap" style="background:#fff7ed;border-color:#fed7aa">
      <div class="step-riser-title riser-t">📐 Riser Tiles</div>
      <div class="usm-grid g3">
        <div class="usm-field">
          <label>No. of Risers</label>
          <input type="number" step="1" placeholder="0" min="0"
            name="${np}_riser_count[${sk}]" data-field="risercount"
            value="${opts.riser_count||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Riser Tile Size</label>
          ${tileSizeSelectHtml(`${np}_riser_tile_size_key[${sk}]`,'rtilekey',
            STAIR_TILE_OPTIONS,rKey,sk,'r',np,rCustom)}
        </div>
        <div class="usm-field">
          <label>Wastage %</label>
          <select name="${np}_riser_wastage_pct[${sk}]"
            data-field="rwaste" onchange="onWasteChange(this,'rwaste')">
            ${wasteSelectHtml(rW)}
          </select>
        </div>
      </div>
      <div class="usm-grid g2" style="margin-top:8px">
        <div class="usm-field">
          <label>Landing Length (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_length[${sk}]" data-field="rlandingl"
            value="${landingL}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Landing Breadth (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_breadth[${sk}]" data-field="rlandingb"
            value="${landingB}" onchange="calcRoom(this)">
        </div>
      </div>
    </div>`;
  }

  if(surf==='step_riser'){
    const rW=opts.riser_wastage_pct??10;
    const landingL = opts.riser_landing_length||'';
    const landingB = opts.riser_landing_breadth||'';
    html+=`<div class="step-riser-wrap" style="background:#fff7ed;border-color:#fed7aa;margin-top:8px">
      <div class="step-riser-title riser-t">📐 Riser Count</div>
      <div class="usm-grid g2">
        <div class="usm-field">
          <label>No. of Risers</label>
          <input type="number" step="1" placeholder="0" min="0"
            name="${np}_riser_count[${sk}]" data-field="risercount"
            value="${opts.riser_count||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Extra Boxes (±)</label>
          <input type="number" step="1" placeholder="0"
            name="${np}_extra_box_riser[${sk}]" data-field="extraboxriser"
            value="${opts.extra_box_riser||''}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field" style="opacity:.55">
          <label>Tile Size</label>
          <input type="text" disabled value="Same as Step tile"
            style="cursor:not-allowed;background:#f8f9fc">
        </div>
      </div>
      <div class="usm-grid g2" style="margin-top:8px">
        <div class="usm-field">
          <label>Landing Length (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_length[${sk}]" data-field="srlandingl"
            value="${landingL}" onchange="calcRoom(this)">
        </div>
        <div class="usm-field">
          <label>Landing Breadth (ft)</label>
          <input type="number" step="0.01" min="0" placeholder="0"
            name="${np}_riser_landing_breadth[${sk}]" data-field="srlandingb"
            value="${landingB}" onchange="calcRoom(this)">
        </div>
      </div>
    </div>`;
  }

  return html;
}

/* ── Company helpers ────────────────────────────────────────────── */
function _getCompanyDisplay(card){
  const sel   = card.querySelector('[data-field="tcompany"]');
  const other = card.querySelector('[data-field="tcompanyother"]');
  if(!sel) return '';
  if(sel.value==='OTHER') return (other?.value||'').trim();
  return sel.value;
}

function onCompanyChange(sel,sk){
  const wrap=document.getElementById('othercompanywrap-'+sk);
  if(wrap) wrap.classList.toggle('show',sel.value==='OTHER');
  calcRoom(sel);
}

function _parseTileOpts(opts){
  let tileCompanyValue=(opts.tile_company||'').trim();
  let design=(opts.tile_design_name||'').trim();
  if(!tileCompanyValue&&!design&&opts.tile_chosen){
    const parts=opts.tile_chosen.split(' — ');
    tileCompanyValue=(parts[0]||'').trim();
    design=(parts[1]||'').trim();
  }
  let isOtherCompany=false,otherCompanyText='';
  if(tileCompanyValue.startsWith('OTHER:')){
    isOtherCompany=true;
    otherCompanyText=tileCompanyValue.substring(6).trim();
    tileCompanyValue='OTHER';
  } else if(tileCompanyValue&&!TILE_COMPANIES.includes(tileCompanyValue)){
    isOtherCompany=true;
    otherCompanyText=tileCompanyValue;
    tileCompanyValue='OTHER';
  }
  return {tileCompanyValue,isOtherCompany,otherCompanyText,design};
}

/* ════════════════════════════════════════════════════════════════
   BUILD ROOM CARD HTML
════════════════════════════════════════════════════════════════ */
function buildRoomCardHtml(sk,isNew,opts={}){
  const surf   = opts.tile_surface||'floor';
  const isBath = +opts.is_bathroom===1;
  const fKey   = opts.floor_tile_size_key||FLOOR_TILE_OPTIONS[3].v;
  const fWaste = opts.floor_wastage_pct??0;
  const wKey   = opts.wall_tile_size_key||WALL_TILE_OPTIONS[0].v;
  const wWaste = opts.wall_wastage_pct??10;
  const isStepRiser = ['step','riser','step_riser'].includes(surf);

  const {tileCompanyValue,isOtherCompany,otherCompanyText,design} = _parseTileOpts(opts);
  const np = isNew?'new':'edit';

  /* Company select */
  const coOpts=`<option value="">— Select Company —</option>`
    +TILE_COMPANIES.map(c=>
      `<option value="${_escHtml(c)}"${c===tileCompanyValue?' selected':''}>${_escHtml(c)}</option>`
    ).join('')
    +`<option value="OTHER"${tileCompanyValue==='OTHER'?' selected':''}>⚙ Other (Custom)</option>`;

  /* Existing images */
  let existingImgsHtml='';
  (opts.existingImgs||[]).forEach(img=>{
    const url=(typeof _EMUPURL!=='undefined'?_EMUPURL:'')+'/'+img.image_filename;
    existingImgsHtml+=`<div class="img-thumb" id="imgth-${img.id}">
      <img src="${_escHtml(url)}" loading="lazy">
      <button type="button" class="img-thumb-del"
        onclick="deleteExistingImg(${img.id},this)">✕</button>
    </div>`;
  });

  /* Surface tabs */
  const surfDefs=[
    {v:'floor', icon:'🪵', lbl:'Floor',     cls:'active-floor'},
    {v:'wall',  icon:'🧱', lbl:'Wall',       cls:'active-wall'},
    {v:'both',  icon:'🏠', lbl:'Both',       cls:'active-both'},
    {v:'step',  icon:'🪜', lbl:'Step',       cls:'active-step'},
    {v:'riser', icon:'📐', lbl:'Riser',      cls:'active-riser'},
    {v:'step_riser',icon:'🔢',lbl:'Step+Riser',cls:'active-step_riser'},
  ];
  const surfTabs=surfDefs.map(s=>
    `<div class="surf-tab${s.v===surf?' '+s.cls:''}" data-surf="${s.v}"
      onclick="setSurface(this,'${sk}')">${s.icon} ${s.lbl}</div>`
  ).join('');

  /* Tile fields */
  let tileSection='';
  if(isStepRiser){
    tileSection=renderStepRiserSection(sk,surf,opts,isNew);
  } else {
    tileSection=renderTileSection(sk,surf,opts,isNew);
  }

  /* Dimension section — parse saved L/B/H arrays */
  let savedL = opts.lengths  || (opts.area_length  ? [opts.area_length]  : ['']);
  let savedB = opts.breadths || (opts.area_breadth ? [opts.area_breadth] : ['']);
  let savedH = opts.heights  || (opts.area_height  ? [opts.area_height]  : ['']);
  const dimSectionHtml = !isStepRiser ? buildDimSectionHtml(sk,isNew,savedL,savedB,savedH,opts) : '';

  /* Bathroom partition */
  const bathSection=!isStepRiser?`<div class="bath-section" id="bathsect-${sk}"
    style="display:${isBath?'block':'none'}">
    <div class="bath-section-title">
      🛁 Bathroom Partition Calculator
    </div>
    <div class="dim-help-text" style="font-size:.8rem;color:var(--c-text3);margin:0 0 10px">
      This section splits the overall wall sqft only. Wall tile size and wastage are taken from the main wall calculation above.
    </div>
    <div class="usm-grid g3">
      <div class="usm-field">
        <label style="color:#92400e">Light Height (ft)</label>
        <input type="number" step="0.01" min="0" placeholder="2.5"
          name="${np}_bath_light_h[${sk}]" data-field="blighth"
          value="${opts.bath_light_h||''}" oninput="calcRoom(this)" onchange="calcRoom(this)">
      </div>
      <div class="usm-field">
        <label style="color:var(--c-accent2)">Highlight 1 (ft)</label>
        <input type="number" step="0.01" min="0" placeholder="3.0"
          name="${np}_bath_high_h[${sk}]" data-field="bhighh"
          value="${opts.bath_high_h||''}" oninput="calcRoom(this)" onchange="calcRoom(this)">
      </div>
      <div class="usm-field">
        <label style="color:#b45309">Highlight 2 (ft)</label>
        <input type="number" step="0.01" min="0" placeholder="1.0"
          name="${np}_bath_high2_h[${sk}]" data-field="bhigh2h"
          value="${opts.bath_high2_h||''}" oninput="calcRoom(this)" onchange="calcRoom(this)">
      </div>
    </div>
    <div class="usm-grid g2" style="margin-top:8px">
      <div class="usm-field">
        <label style="color:#78716c">Dark Height (ft)</label>
        <input type="number" step="0.01" min="0" placeholder="2.5"
          name="${np}_bath_dark_h[${sk}]" data-field="bdarkh"
          value="${opts.bath_dark_h||''}" oninput="calcRoom(this)" onchange="calcRoom(this)">
      </div>
      <div class="usm-field">
        <label style="color:#0f766e">Fall Height (ft)</label>
        <input type="number" step="0.01" min="0" placeholder="0.5"
          name="${np}_bath_fall_h[${sk}]" data-field="bfallh"
          value="${opts.bath_fall_h||''}" oninput="calcRoom(this)" onchange="calcRoom(this)">
      </div>
    </div>
    <div class="bath-part-cards" style="margin-top:10px"></div>
    <div class="bath-chips-total"></div>
    <div class="bath-note" style="display:none"></div>
  </div>` : '';

  const hiddenIdInput=isNew?''
    :`<input type="hidden" name="edit_area_id[${sk}]" value="${opts.id||''}">
      <input type="hidden" name="img_area_sk[]" value="${sk}">`;

  const imgFileInputName=isNew
    ?`new_area_img_files[${sk}][]`
    :`edit_area_img_files[${sk}][]`;
  const camDataName=isNew
    ?`new_img_cam_data[${sk}]`
    :`img_cam_data[${sk}]`;

  const headerBrand=isOtherCompany?otherCompanyText:(tileCompanyValue||'');

  return `<div class="room-card" data-sk="${sk}" data-isnew="${isNew?1:0}" id="roomcard-${sk}">

  <div class="room-card-head" onclick="toggleRoomCard('${sk}')">
    <div class="rc-num" id="rcnum-${sk}">—</div>
    <div class="rc-info">
      <div class="rc-name">${_escHtml(opts.area_type||'Room')}</div>
      <div class="rc-brand">${_escHtml(headerBrand)}${design?' — '+_escHtml(design):''}</div>
    </div>
    <div class="rc-sqft"></div>
    <button type="button" class="btn-danger-sm"
      onclick="removeRoomCard('${sk}',event)">✕ Remove</button>
    <span class="rc-toggle">▾</span>
  </div>

  <div class="room-card-body" id="rcbody-${sk}">
    ${hiddenIdInput}

    <div class="usm-grid g2" style="margin-bottom:8px">
      <div class="usm-field">
        <label>Area / Name</label>
        <input type="text" placeholder="e.g. Hall, Bedroom…"
          name="${np}_area_type[${sk}]" data-field="atype"
          value="${_escHtml(opts.area_type||'')}" oninput="calcRoom(this)">
      </div>
    </div>

    <!-- Surface tabs -->
    <div style="margin:10px 0 10px">
      <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;
        color:var(--c-text3);font-weight:700;margin-bottom:6px">Tile Surface</div>
      <div class="surf-tabs" id="surftabs-${sk}">${surfTabs}</div>
      <input type="hidden" name="${np}_tile_surface[${sk}]"
        data-field="surf" value="${surf}">
    </div>

    <!-- Tile Brand & Design -->
    <div class="tile-company-row">
      <div class="tile-company-row-title">🏷️ Tile Brand &amp; Design</div>
      <div class="usm-grid g2">
        <div class="usm-field" style="margin-bottom:0">
          <label>Tile Company</label>
          <select name="${np}_tile_company[${sk}]"
            data-field="tcompany" onchange="onCompanyChange(this,'${sk}')">
            ${coOpts}
          </select>
          <div class="other-company-wrap${isOtherCompany?' show':''}"
               id="othercompanywrap-${sk}">
            <label>Custom Company Name</label>
            <input type="text" placeholder="Enter tile company name…"
              name="${np}_tile_company_other[${sk}]" data-field="tcompanyother"
              value="${_escHtml(otherCompanyText)}"
              oninput="calcRoom(this)"
              style="margin-top:6px">
          </div>
        </div>
        <div class="usm-field" style="margin-bottom:0">
          <label>Design / Collection Name</label>
          <input type="text" placeholder="e.g. Imperial Beige…"
            name="${np}_tile_design_name[${sk}]" data-field="tdesign"
            value="${_escHtml(design)}" oninput="calcRoom(this)">
        </div>
      </div>
    </div>

    <input type="hidden" name="${np}_tile_chosen[${sk}]"
      data-field="tchosen" value="${_escHtml(opts.tile_chosen||'')}">

    <!-- Tile dimensions / wastage fields -->
    <div id="tilefields-${sk}">${tileSection}</div>

    <!-- Dimension section (floor/wall/both only) -->
    ${dimSectionHtml}

    <input type="hidden" name="${np}_area_length[${sk}]" data-field="area_length" value="${_escHtml(opts.area_length||'')}">
    <input type="hidden" name="${np}_area_breadth[${sk}]" data-field="area_breadth" value="${_escHtml(opts.area_breadth||'')}">
    <input type="hidden" name="${np}_area_height[${sk}]" data-field="area_height" value="${_escHtml(opts.area_height||'')}">
    <input type="hidden" name="${np}_floor_sqft[${sk}]" data-field="floor_sqft" value="${_escHtml(opts.floor_sqft||'')}">
    <input type="hidden" name="${np}_wall_sqft[${sk}]" data-field="wall_sqft" value="${_escHtml(opts.wall_sqft||'')}">
    <input type="hidden" name="${np}_total_sqft[${sk}]" data-field="total_sqft" value="${_escHtml(opts.total_sqft||opts.area_sqft||'')}">
    <input type="hidden" name="${np}_calc_summary[${sk}]" data-field="calc_summary" value="${_escHtml(opts.calc_summary||'')}">

    <!-- Calc chips -->
    <div class="calc-chips" id="calcchips-${sk}"></div>

    <!-- Bathroom toggle -->
    ${!isStepRiser?`
    <div class="toggle-wrap" style="margin-top:14px">
      <label class="toggle-switch">
        <input type="checkbox" name="${np}_is_bathroom[${sk}]"
          data-field="isbath" value="1" ${isBath?'checked':''}
          onchange="toggleBathSection(this,'${sk}')">
        <span class="toggle-slider"></span>
      </label>
      <span class="toggle-label">🛁 Enable Bathroom Partition Calculator</span>
    </div>
    ${bathSection}
    `:''}

    <!-- Area photos -->
    <div style="margin-top:14px">
      <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.1em;
        color:var(--c-text3);font-weight:700;margin-bottom:8px">Area Photos (max 4)</div>
      <div class="img-zone" id="imgzone-${sk}">
        <div class="img-thumbs" id="imgthumbs-${sk}">${existingImgsHtml}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <label class="img-add-btn">
            <svg width="13" height="13" fill="none" stroke="currentColor"
                 stroke-width="2" viewBox="0 0 24 24">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            Upload Photo
            <input type="file" name="${imgFileInputName}"
              accept="image/*" multiple style="display:none"
              onchange="onImgFileChange(this,'${sk}')">
          </label>
          <button type="button" class="img-add-btn" onclick="startCam('${sk}')">
            <svg width="13" height="13" fill="none" stroke="currentColor"
                 stroke-width="2" viewBox="0 0 24 24">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8
                       a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            Camera
          </button>
          <button type="button" class="img-add-btn"
            id="camsnap-${sk}" style="display:none;border-color:var(--c-green);color:#16a34a"
            onclick="snapCam('${sk}')">📸 Capture</button>
          <button type="button" class="img-add-btn"
            id="camstop-${sk}" style="display:none;border-color:var(--c-red);color:var(--c-red)"
            onclick="stopCam('${sk}')">✕ Stop</button>
        </div>
        <video class="cam-video" id="camvid-${sk}" autoplay playsinline muted></video>
        <canvas class="cam-canvas" id="camcan-${sk}"></canvas>
        <input type="hidden" name="${camDataName}" data-field="camdata" id="camdata-${sk}">
      </div>
    </div>

  </div><!-- /.room-card-body -->
</div>`;
}

/* ── Surface tab change ─────────────────────────────────────────── */
function setSurface(tab,sk){
  const card  = document.getElementById('roomcard-'+sk);
  const surf  = tab.dataset.surf;
  const hidden= card.querySelector('[data-field="surf"]');
  if(hidden) hidden.value=surf;

  card.querySelectorAll('.surf-tab').forEach(t=>
    t.className=t.className.replace(/active-\S+/g,'').trim()
  );
  tab.classList.add('active-'+surf);

  const isNew       = card.dataset.isnew==='1';
  const isStepRiser = ['step','riser','step_riser'].includes(surf);

  /* Show/hide dim section */
  const dimSect = card.querySelector('.dim-section');
  if(dimSect) dimSect.style.display=isStepRiser?'none':'block';

  /* Show/hide bathroom toggle+section */
  const bathToggle = card.querySelector('[data-field="isbath"]')?.closest('.toggle-wrap');
  const bathSect   = card.querySelector('.bath-section');
  if(bathToggle) bathToggle.style.display=isStepRiser?'none':'';
  if(bathSect&&isStepRiser) bathSect.style.display='none';

  const fKey = card.querySelector('[data-field="ftilekey"]')?.value||FLOOR_TILE_OPTIONS[3].v;
  const fW   = _getWasteVal(card,'fwaste');
  const wKey = card.querySelector('[data-field="wtilekey"]')?.value||WALL_TILE_OPTIONS[0].v;
  const wW   = _getWasteVal(card,'wwaste');

  if(isStepRiser){
    const stepKey = card.querySelector('[data-field="stilekey"]')?.value
      || card.querySelector('[data-field="srtilekey"]')?.value
      || STEP_TILE_OPTIONS[0].v;
    const riserKey = card.querySelector('[data-field="rtilekey"]')?.value||RISER_TILE_OPTIONS[0].v;
    const stairOpts = {
      step_count: card.querySelector('[data-field="stepcount"]')?.value||'',
      riser_count: card.querySelector('[data-field="risercount"]')?.value||'',
      step_tile_size_key: stepKey,
      riser_tile_size_key: riserKey,
      step_wastage_pct: _getWasteVal(card,'swaste') || _getWasteVal(card,'srwaste') || 10,
      riser_wastage_pct: _getWasteVal(card,'rwaste') || 10,
      riser_landing_length: card.querySelector('[data-field="slandingl"]')?.value
        || card.querySelector('[data-field="rlandingl"]')?.value
        || card.querySelector('[data-field="srlandingl"]')?.value || '',
      riser_landing_breadth: card.querySelector('[data-field="slandingb"]')?.value
        || card.querySelector('[data-field="rlandingb"]')?.value
        || card.querySelector('[data-field="srlandingb"]')?.value || '',
      step_tile_custom: null,
      riser_tile_custom: null,
      step_riser_tile_custom: null
    };
    document.getElementById('tilefields-'+sk).innerHTML=
      renderStepRiserSection(sk,surf,stairOpts,isNew);
  } else {
    // collect existing custom values from card inputs (if any)
    const optsForTile = {
      floor_tile_size_key: fKey,
      floor_wastage_pct: fW,
      wall_tile_size_key: wKey,
      wall_wastage_pct: wW,
      floor_tile_custom: null,
      wall_tile_custom: null
    };
    try {
      const fcw = card.querySelector('[data-field="fcustw"]')?.value||'';
      const fch = card.querySelector('[data-field="fcusth"]')?.value||'';
      const fcpp = card.querySelector('[data-field="fcustppb"]')?.value||'';
      if(fcw||fch||fcpp) optsForTile.floor_tile_custom = {w:fcw, h:fch, ppb:fcpp};
    } catch(e){}
    try {
      const wcw = card.querySelector('[data-field="wcustw"]')?.value||'';
      const wch = card.querySelector('[data-field="wcusth"]')?.value||'';
      const wcpp = card.querySelector('[data-field="wcustppb"]')?.value||'';
      if(wcw||wch||wcpp) optsForTile.wall_tile_custom = {w:wcw, h:wch, ppb:wcpp};
    } catch(e){}
    document.getElementById('tilefields-'+sk).innerHTML=
      renderTileSection(sk,surf,optsForTile,isNew);
  }

  _syncAllDimRows(card);
  _recalcCard(card);
}

/* ── Bathroom toggle ─────────────────────────────────────────────── */
function toggleBathSection(cb,sk){
  const sect=document.getElementById('bathsect-'+sk);
  if(sect) sect.style.display=cb.checked?'block':'none';
  const card=document.getElementById('roomcard-'+sk);
  if(card){
    _updateDimTotalsBar(card);
    _recalcCard(card);
  }
}

/* ── Enforce Bathroom Partition Height Cap ───────────────────────── */
function _enforceBathHeightCap(card) {
  const isBath = card.querySelector('[data-field="isbath"]')?.checked;
  if (!isBath) return;

  // Total room height
  const tH = parseFloat(card.querySelector('.tot-h')?.textContent) || 0;
  if (tH <= 0) return;

  const bl  = card.querySelector('[data-field="blighth"]');
  const bh1 = card.querySelector('[data-field="bhighh"]');
  const bh2 = card.querySelector('[data-field="bhigh2h"]');
  const bd  = card.querySelector('[data-field="bdarkh"]');
  const bf  = card.querySelector('[data-field="bfallh"]');
  
  if(!bl||!bh1||!bh2||!bd||!bf) return;

  const sum = (parseFloat(bl.value)||0) + (parseFloat(bh1.value)||0) + 
              (parseFloat(bh2.value)||0) + (parseFloat(bd.value)||0) + 
              (parseFloat(bf.value)||0);

  const warnEl = card.querySelector('.bath-note');
  if (sum > tH) {
    if (warnEl) {
      warnEl.style.display = 'block';
      warnEl.innerHTML = `⚠️ <b>Height Exceeded:</b> Partition sum (${sum.toFixed(2)} ft) exceeds room height (${tH.toFixed(2)} ft). Please adjust values.`;
      warnEl.style.color = '#dc2626';
      warnEl.style.background = '#fef2f2';
      warnEl.style.padding = '8px';
      warnEl.style.borderRadius = '6px';
      warnEl.style.marginTop = '10px';
      warnEl.style.fontSize = '0.8rem';
      warnEl.style.border = '1px solid #fca5a5';
    }
    // Hard block on save (add a class we can check during save)
    card.classList.add('bath-height-error');
  } else {
    if (warnEl) warnEl.style.display = 'none';
    card.classList.remove('bath-height-error');
  }
}

/* ── Collapse/expand ─────────────────────────────────────────────── */
function toggleRoomCard(sk){
  const head=document.querySelector(`#roomcard-${sk} .room-card-head`);
  const body=document.getElementById('rcbody-'+sk);
  if(!body) return;
  const collapsed=body.style.display==='none';
  body.style.display=collapsed?'':'none';
  if(head) head.classList.toggle('collapsed',!collapsed);
}

/* ── Add / Remove rooms ──────────────────────────────────────────── */
function addRoomCard(opts={}){
  const sk='n'+_roomSeq++;
  const container=document.getElementById('roomCardsContainer');
  const div=document.createElement('div');
  div.innerHTML=buildRoomCardHtml(sk,true,opts);
  container.appendChild(div.firstElementChild);
  renumberCards();
  const card=document.getElementById('roomcard-'+sk);
  if(card){
    _syncAllDimRows(card);
    _recalcCard(card);
  }
}

function removeRoomCard(sk,evt){
  evt.stopPropagation();
  const form = document.getElementById('editStatusForm');
  const card = document.getElementById('roomcard-'+sk);
  if (card) {
    const idInput = card.querySelector(`input[name="edit_area_id[${sk}]"]`);
    if (idInput && idInput.value && form) {
      const existing = form.querySelector(`input[name="delete_area_ids[]"][value="${idInput.value}"]`);
      if (!existing) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'delete_area_ids[]';
        hidden.value = idInput.value;
        form.appendChild(hidden);
      }
    }
    stopCam(sk);
    card.remove();
  }
  renumberCards();
}

function renumberCards(){
  document.querySelectorAll('#roomCardsContainer .room-card').forEach((c,i)=>{
    const n=c.querySelector('.rc-num');
    if(n) n.textContent=i+1;
  });
}

/* ── Delete existing image ──────────────────────────────────────── */
function deleteExistingImg(imgId,btn){
  if(!confirm('Delete this image?')) return;
  const fd=new FormData();
  fd.append('csrf',document.querySelector('#editStatusForm [name="csrf"]').value);
  fd.append('action','delete_area_image');
  fd.append('img_id',imgId);
  fd.append('lookup_phone','<?= htmlspecialchars($phoneLookup ?? '') ?>');
  fetch('subadmins.php',{method:'POST',body:fd})
    .then(()=>{ const t=document.getElementById('imgth-'+imgId); if(t) t.remove(); })
    .catch(()=>alert('Could not delete image.'));
}

/* ── Image file change ───────────────────────────────────────────── */
function onImgFileChange(input,sk){
  const thumbs=document.getElementById('imgthumbs-'+sk);
  if(!thumbs) return;
  const ec=thumbs.querySelectorAll('.img-thumb').length;
  let added=0;
  Array.from(input.files).forEach(file=>{
    if((ec+added)>=4) return;
    if(!file.type.startsWith('image/')) return;
    const reader=new FileReader();
    reader.onload=e=>{
      const div=document.createElement('div');
      div.className='img-thumb';
      div.innerHTML=`<img src="${e.target.result}" loading="lazy">`;
      thumbs.appendChild(div);
    };
    reader.readAsDataURL(file);
    added++;
  });
}

/* ── Camera ──────────────────────────────────────────────────────── */
async function startCam(sk){
  try{
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
    _camStreams[sk]=stream;
    const vid=document.getElementById('camvid-'+sk);
    vid.srcObject=stream;vid.style.display='block';
    document.getElementById('camsnap-'+sk).style.display='inline-flex';
    document.getElementById('camstop-'+sk).style.display='inline-flex';
  }catch(e){ alert('Camera error: '+e.message); }
}
function snapCam(sk){
  const vid=document.getElementById('camvid-'+sk);
  const can=document.getElementById('camcan-'+sk);
  can.width=vid.videoWidth;can.height=vid.videoHeight;
  can.getContext('2d').drawImage(vid,0,0);
  const url=can.toDataURL('image/jpeg',.88);
  document.getElementById('camdata-'+sk).value=url;
  const thumbs=document.getElementById('imgthumbs-'+sk);
  if(thumbs&&thumbs.querySelectorAll('.img-thumb').length<4){
    const div=document.createElement('div');
    div.className='img-thumb';
    div.innerHTML=`<img src="${url}">`;
    thumbs.appendChild(div);
  }
  stopCam(sk);
}
function stopCam(sk){
  if(_camStreams[sk]){ _camStreams[sk].getTracks().forEach(t=>t.stop()); delete _camStreams[sk]; }
  ['camvid-','camsnap-','camstop-'].forEach(p=>{
    const el=document.getElementById(p+sk); if(el) el.style.display='none';
  });
}

/* ── Open edit modal ─────────────────────────────────────────────── */
function openEdit(sub,detail,areas,aImgMap,vizSnaps){
  document.getElementById('editModalSub').textContent=
    (sub.owner_name||'—')+' — '+(sub.owner_number||'');
  document.getElementById('esSubId').value=sub.id;

  document.querySelectorAll('#editStatusForm input[name="delete_area_ids[]"]').forEach(el=>el.remove());

  document.querySelectorAll('#editStatusForm input[name="status"]').forEach(r=>{
    r.checked=(r.value===(sub.status||'enquiry'));
  });
  onStatusChange(sub.status||'enquiry');

  document.getElementById('esOtherReason').value  = sub.other_status_reason||'';
  document.getElementById('esPurchaseDate').value  = sub.purchase_date||'';
  document.getElementById('esPurchaseNotes').value = sub.purchase_notes||'';
  document.getElementById('esNextVisitDate').value = sub.next_visit_date||'';
  updateNextVisitPreview();
  document.getElementById('esOwnerNumber').value   = sub.owner_number||'';
  document.getElementById('esAltMobile').value     = sub.alt_mobile||'';
  document.getElementById('esPropertyType').value   = sub.property_type||'';

  const container=document.getElementById('roomCardsContainer');
  container.innerHTML=''; _roomSeq=0;

  (areas||[]).forEach((a,i)=>{
    const sk='e'+i;
    const imgs=(aImgMap&&aImgMap[a.id])?aImgMap[a.id]:[];

    /* Parse L / B / H arrays from DB */
    let savedL=[],savedB=[],savedH=[];
    if(a.lengths){
      try{ savedL=JSON.parse(a.lengths); }catch(e){ savedL=[]; }
    }
    if(a.breadths){
      try{ savedB=JSON.parse(a.breadths); }catch(e){ savedB=[]; }
    }
    if(a.heights){
      try{ savedH=JSON.parse(a.heights); }catch(e){ savedH=[]; }
    }
    /* Fallback to legacy single-value columns */
    if(!savedL.length && a.area_length)  savedL=[a.area_length];
    if(!savedB.length && a.area_breadth) savedB=[a.area_breadth];
    if(!savedH.length && a.area_height)  savedH=[a.area_height];

    const div=document.createElement('div');
    div.innerHTML=buildRoomCardHtml(sk,false,{
      ...a, lengths:savedL, breadths:savedB, heights:savedH, existingImgs:imgs
    });
    container.appendChild(div.firstElementChild);
  });

  renumberCards();
  container.querySelectorAll('.room-card').forEach(c=>{
    _syncAllDimRows(c);
    _recalcCard(c);
  });

  document.querySelectorAll('#editStatusForm input[name="status"]').forEach(r=>{
    r.addEventListener('change',()=>onStatusChange(r.value));
  });

  openModal('editModal');
}

function onStatusChange(val){
  document.getElementById('esOtherReasonWrap').style.display=val==='others'?'block':'none';
  document.getElementById('esPurchaseWrap').style.display=val==='billed'?'block':'none';
}

function updateNextVisitPreview(){
  const input=document.getElementById('esNextVisitDate');
  const preview=document.getElementById('esNextVisitPreview');
  if(!input?.value){
    preview.textContent='No next visit scheduled';
    return;
  }
  const date=new Date(input.value+'T00:00:00');
  preview.textContent=Number.isNaN(date.getTime())
    ? 'Invalid date'
    : new Intl.DateTimeFormat('en-GB',{day:'2-digit',month:'short',year:'numeric',weekday:'short'}).format(date);
}

document.getElementById('esNextVisitDate').addEventListener('change',updateNextVisitPreview);

document.querySelectorAll('#editStatusForm input[name="status"]').forEach(r=>{
  r.addEventListener('change',()=>onStatusChange(r.value));
});

/* ── Open unlock modal ────────────────────────────────────────────── */
function openUnlockModal(subId,ownerName){
  document.getElementById('unlockSubId').value=subId;
  document.getElementById('unlockModalSub').textContent=ownerName||'—';
  openModal('unlockModal');
}

/* ════════════════════════════════════════════════════════════════
   FORM SUBMIT — serialize L/B/H arrays + fix tile_company
════════════════════════════════════════════════════════════════ */
document.getElementById('editStatusForm').addEventListener('submit',function(){
  document.querySelectorAll('.room-card').forEach(c=>{
    const sk=c.dataset.sk;
    if(sk) stopCam(sk);

    const isNew = c.dataset.isnew==='1';
    const np    = isNew?'new':'edit';

    /* ── Serialize L / B / H arrays ── */
    ['l','b','h'].forEach(role=>{
      const {vals} = _readDimCol(c, role);
      /* Create hidden inputs with the JSON values */
      const nameMap={l:'lengths',b:'breadths',h:'heights'};
      let hidden = c.querySelector(`[data-dimjson="${role}"]`);
      if(!hidden){
        hidden=document.createElement('input');
        hidden.type='hidden';
        hidden.dataset.dimjson=role;
        hidden.name=`${np}_${nameMap[role]}_json[${sk}]`;
        c.appendChild(hidden);
      }
      hidden.value=JSON.stringify(vals);
    });

    /* ── Fix tile_company post value ── */
    const compSel    = c.querySelector('[data-field="tcompany"]');
    const customInput= c.querySelector('[data-field="tcompanyother"]');
    const desSel     = c.querySelector('[data-field="tdesign"]');
    const hidChosen  = c.querySelector('[data-field="tchosen"]');
    let finalCompany='';
    if(compSel){
      if(compSel.value==='OTHER'){
        const t=(customInput?.value||'').trim();
        if(t){ compSel.value='OTHER:'+t; finalCompany=t; }
      } else { finalCompany=compSel.value; }
    }
    const des=(desSel?.value||'').trim();
    if(hidChosen) hidChosen.value=[finalCompany,des].filter(Boolean).join(' — ');
  });

  const btn=document.getElementById('esSaveBtn');
  if(btn){ btn.disabled=true; btn.textContent='⏳ Saving…'; }
});
</script>

<?php
/*
 * ════════════════════════════════════════════════════════════════════
 * DATABASE SCHEMA — v4.0
 * ════════════════════════════════════════════════════════════════════
 *
 * ALTER TABLE submission_areas
 *   ADD COLUMN lengths      JSON          NULL  COMMENT 'Array of length values [3.5, 2.0]',
 *   ADD COLUMN breadths     JSON          NULL  COMMENT 'Array of breadth values',
 *   ADD COLUMN heights      JSON          NULL  COMMENT 'Array of height values',
 *   ADD COLUMN step_count   SMALLINT UNSIGNED NULL DEFAULT 0,
 *   ADD COLUMN riser_count  SMALLINT UNSIGNED NULL DEFAULT 0,
 *   ADD COLUMN step_tile_size_key  VARCHAR(30) NULL,
 *   ADD COLUMN riser_tile_size_key VARCHAR(30) NULL,
 *   ADD COLUMN step_wastage_pct    DECIMAL(5,2) NULL DEFAULT 10,
 *   ADD COLUMN riser_wastage_pct   DECIMAL(5,2) NULL DEFAULT 10;
 *
 * PHP handler reads:
 *
 *   // Lengths / breadths / heights (JSON arrays from form)
 *   $lengths_json  = $_POST['edit_lengths_json'][$sk]  ?? '[]';
 *   $breadths_json = $_POST['edit_breadths_json'][$sk] ?? '[]';
 *   $heights_json  = $_POST['edit_heights_json'][$sk]  ?? '[]';
 *   $lengths  = array_map('floatval', json_decode($lengths_json,  true) ?: []);
 *   $breadths = array_map('floatval', json_decode($breadths_json, true) ?: []);
 *   $heights  = array_map('floatval', json_decode($heights_json,  true) ?: []);
 *
 *   $area['lengths']  = json_encode($lengths);
 *   $area['breadths'] = json_encode($breadths);
 *   $area['heights']  = json_encode($heights);
 *
 *   // Legacy single columns (first value, backward compat):
 *   $area['area_length']  = $lengths[0]  ?? 0;
 *   $area['area_breadth'] = $breadths[0] ?? 0;
 *   $area['area_height']  = $heights[0]  ?? 0;
 *
 *   // Computed totals (store for easy querying):
 *   // For every row: length × breadth × height = row sqft.
 *   // Overall sqft is the sum of all row sqft values.
 *   $overallSqft = 0;
 *   $maxRows = max(count($lengths), count($breadths), count($heights));
 *   for ($i = 0; $i < $maxRows; $i++) {
 *     $overallSqft += (float)($lengths[$i] ?? 0)
 *                   * (float)($breadths[$i] ?? 0)
 *                   * (float)($heights[$i] ?? 0);
 *   }
 *   $area['floor_sqft'] = round($overallSqft, 4);
 *   $area['wall_sqft']  = round($overallSqft, 4);
 *
 *   // Tile surface (step|riser|step_riser also accepted):
 *   $allowed = ['floor','wall','both','step','riser','step_riser'];
 *   $area['tile_surface'] = in_array($_POST['edit_tile_surface'][$sk] ?? '', $allowed)
 *     ? $_POST['edit_tile_surface'][$sk] : 'floor';
 *
 *   // Step / Riser:
 *   $area['step_count']  = (int)($_POST['edit_step_count'][$sk]  ?? 0);
 *   $area['riser_count'] = (int)($_POST['edit_riser_count'][$sk] ?? 0);
 *
 * ════════════════════════════════════════════════════════════════════
 */
?>
