<?php
/**
 * walkin_modal.php  — included by subadmins.php
 * Requires: $allDirs, $allZones, $todayISO, $tomorrowISO, csrfToken()
 */
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     LOOKUP MODAL  (step 1 — name + phone check)
══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="lookupModal">
  <div class="modal" style="max-width:420px">

    <div class="wi-modal-header">
      <div class="wm-icon">🔍</div>
      <div>
        <div class="wm-title">Customer Lookup</div>
        <div class="wm-sub">Enter name &amp; phone to check existing records</div>
      </div>
    </div>

    <div style="padding:4px 2px 8px">

      <!-- lookup form -->
      <div id="lk-form-wrap">
        <div class="wi-field" style="margin-bottom:12px" id="lkf_name">
          <div class="wf-label">Customer Name <span style="color:#dc2626">*</span></div>
          <input type="text" id="lk_name" placeholder="Full name of owner" autocomplete="off" oninput="lkClearErr()">
        </div>
        <div class="wi-field" style="margin-bottom:16px" id="lkf_phone">
          <div class="wf-label">Phone Number <span style="color:#dc2626">*</span></div>
          <input type="tel" id="lk_phone" placeholder="+91 XXXXX XXXXX" maxlength="15" autocomplete="off" oninput="lkClearErr()" onkeydown="if(event.key==='Enter'){event.preventDefault();lkCheck();}">
        </div>
        <div id="lk_error" style="display:none;color:#dc2626;font-size:.81rem;margin-bottom:10px;padding:8px 12px;background:#fff5f5;border-radius:7px;border:1px solid #fca5a5"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('lookupModal')">Cancel</button>
          <button type="button" class="btn btn-primary" style="flex:2" id="lk_check_btn" onclick="lkCheck()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Check &amp; Continue
          </button>
        </div>
      </div>

      <!-- existing customer result -->
      <div id="lk-existing-wrap" style="display:none">
        <div class="alert alert-warn" style="font-size:.83rem;margin-bottom:12px">
          ⚠️ <strong>Customer already exists</strong> — record found with this phone number.
        </div>
        <div id="lk_existing_card" style="border:1.5px solid var(--c-warn,#f59e0b);border-radius:10px;overflow:hidden;margin-bottom:14px"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn btn-ghost" style="flex:1" onclick="lkReset()">← Search Again</button>
          <button type="button" class="btn btn-primary" style="flex:2" onclick="closeModal('lookupModal')">Close</button>
        </div>
      </div>

      <!-- spinner -->
      <div id="lk-spinner" style="display:none;text-align:center;padding:24px 0;color:var(--c-gray-500);font-size:.88rem">
        <svg style="animation:spin 1s linear infinite;margin-bottom:8px" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg><br>
        Checking…
      </div>

    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     WALK-IN MODAL  (step 2 — full form, opened after lookup confirms new)
══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="walkinModal">
  <div class="modal" style="max-width:760px;max-height:94vh;overflow-y:auto">

    <div class="wi-modal-header">
      <div class="wm-icon">🚶</div>
      <div>
        <div class="wm-title">Walk-in Customer</div>
        <div class="wm-sub">Submit a new record directly as Office Admin</div>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="walkinForm">
      <input type="hidden" name="csrf"                       value="<?= csrfToken() ?>">
      <input type="hidden" name="action"                     value="walkin_submit">
      <input type="hidden" name="walkin_sub_direction_id"    id="wi_sd_id" value="">
      <input type="hidden" name="walkin_latitude"            id="wi_lat">
      <input type="hidden" name="walkin_longitude"           id="wi_lng">
      <input type="hidden" name="walkin_device_submitted_at" id="wi_dev_time">
      <input type="hidden" name="walkin_force_submit"        id="wi_force" value="0">
      <input type="hidden" name="walkin_webcam_landmark"     id="wi_webcam_lm_data">
      <input type="hidden" name="walkin_webcam_additional"   id="wi_webcam_ap_data">
      <input type="hidden" name="walkin_webcam_tile"         id="wi_webcam_tile_data">

      <!-- ZONE PICKER -->
      <div class="wi-section">
        <div class="wi-sec-title">Select Zone / Sub-Direction <span style="color:#dc2626">*</span></div>
        <input type="text" class="wi-zone-search" id="wi_zone_search" placeholder="🔍 Search zone code, direction, or place…" oninput="wiFilterZones(this.value)">
        <div class="wi-zone-picker" id="wi_zone_picker">
          <?php foreach ($allDirs as $dir):
            $dirZones = array_filter($allZones, fn($z) => $z['dir_code'] === $dir['code']);
            if (!$dirZones) continue;
            foreach ($dirZones as $z):
          ?>
          <div class="wi-zone-card"
               data-zone-id="<?= (int)$z['id'] ?>"
               data-search="<?= strtolower($z['dir_code'].' '.$z['code'].' '.$z['from_location'].' '.$z['to_location'].' '.$dir['label']) ?>"
               onclick="wiSelectZone(this,<?= (int)$z['id'] ?>,'<?= htmlspecialchars($z['dir_code'],ENT_QUOTES) ?> › <?= htmlspecialchars($z['code'],ENT_QUOTES) ?>')">
            <div><span class="wi-zone-dir-badge"><?= htmlspecialchars($dir['code']) ?></span> <span style="font-size:.68rem;color:var(--c-gray-500)"><?= htmlspecialchars($dir['label']) ?></span></div>
            <div class="wi-zone-code"><?= htmlspecialchars($z['code']) ?></div>
            <div class="wi-zone-route">
              <strong><?= htmlspecialchars($z['from_location']) ?></strong>
              <span>→</span>
              <strong><?= htmlspecialchars($z['to_location']) ?></strong>
            </div>
          </div>
          <?php endforeach; endforeach; ?>
        </div>
        <div id="wi_zone_selected_display" style="margin-top:10px;font-size:.82rem;color:var(--c-primary);font-weight:600;min-height:18px"></div>
      </div>

      <!-- CUSTOMER DETAILS -->
      <div class="wi-section">
        <div class="wi-sec-title">Customer Details</div>
        <div class="wi-grid">
          <div class="wi-field" id="wif_owner_name">
            <div class="wf-label">Owner Name <span style="color:#dc2626">*</span></div>
            <input type="text" name="walkin_owner_name" id="wi_owner_name" placeholder="Full name of property owner">
          </div>
          <div class="wi-field" id="wif_owner_number">
            <div class="wf-label">Owner Phone <span style="color:#dc2626">*</span></div>
            <input type="tel" name="walkin_owner_number" id="wi_owner_number" placeholder="+91 XXXXX XXXXX" maxlength="15">
          </div>
          <div class="wi-field" id="wif_rep_name">
            <div class="wf-label">Influencer / Rep Name <span style="color:#dc2626">*</span></div>
            <input type="text" name="walkin_representative_name" id="wi_rep_name" placeholder="Representative name">
          </div>
          <div class="wi-field" id="wif_rep_number">
            <div class="wf-label">Influencer Phone <span style="color:#dc2626">*</span></div>
            <input type="tel" name="walkin_representative_number" id="wi_rep_number" placeholder="+91 XXXXX XXXXX" maxlength="15">
          </div>
          <div class="wi-field" id="wif_village">
            <div class="wf-label">Village / City / Town <span style="color:#dc2626">*</span></div>
            <input type="text" name="walkin_village_city_town" id="wi_village" placeholder="e.g. Gandhi Nagar">
          </div>
          <div class="wi-field" id="wif_street">
            <div class="wf-label">Street <span style="color:#dc2626">*</span></div>
            <input type="text" name="walkin_street" id="wi_street" placeholder="Street / Road name">
          </div>
        </div>
      </div>

      <!-- NEXT VISIT -->
      <div class="wi-visit-section">
        <div class="wi-visit-title">📅 Date of Next Visit <span style="font-size:.67rem;font-weight:400;color:#7dd3fc;text-transform:none;letter-spacing:0">(optional)</span></div>
        <input type="date" class="wi-visit-input" name="walkin_next_visit_date" id="wi_next_visit" min="<?= $todayISO ?>" onchange="wiVisitPreview()">
        <div class="wi-shortcuts">
          <button type="button" class="wi-shortcut" onclick="wiSetVisit('<?= $tomorrowISO ?>')">Tomorrow</button>
          <button type="button" class="wi-shortcut" onclick="wiSetVisit('<?= date('Y-m-d',strtotime('+2 days')) ?>')">+2 Days</button>
          <button type="button" class="wi-shortcut" onclick="wiSetVisit('<?= date('Y-m-d',strtotime('+3 days')) ?>')">+3 Days</button>
          <button type="button" class="wi-shortcut" onclick="wiSetVisit('<?= date('Y-m-d',strtotime('+7 days')) ?>')">+1 Week</button>
          <button type="button" class="wi-shortcut" onclick="wiSetVisit('<?= date('Y-m-d',strtotime('+1 month')) ?>')">+1 Month</button>
          <button type="button" class="wi-shortcut" style="border-color:#f87171;color:#dc2626" onclick="wiClearVisit()">✕ Clear</button>
        </div>
        <div id="wi_visit_preview" style="margin-top:7px;font-size:.79rem;color:#0369a1;font-weight:600;min-height:18px"></div>
      </div>

      <!-- AREA DETAILS -->
      <div class="wi-section">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div class="wi-sec-title" style="margin:0">Area Details</div>
          <span style="font-size:.72rem;color:#dc2626;font-weight:600">* All fields required</span>
        </div>
        <div id="wi-area-rows"></div>
        <button type="button" class="wi-add-area" onclick="wiAddArea()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Add Another Area
        </button>
      </div>

      <!-- GPS -->
      <div class="wi-section">
        <div class="wi-sec-title">GPS Location <span style="color:var(--c-gray-400);text-transform:none;font-size:.65rem;font-weight:500">(optional)</span></div>
        <div class="wi-gps-box" id="wi_gps_box">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <div class="wi-coord-box" id="wi_coord_display">No coordinates yet</div>
            <button type="button" class="btn btn-ghost btn-sm" onclick="wiFetchGPS()" id="wi_gps_btn">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/></svg>Fetch GPS
            </button>
          </div>
          <div id="wi_gps_status" style="font-size:.77rem;color:var(--c-gray-500);margin-top:5px"></div>
        </div>
      </div>

      <!-- PHOTOS -->
      <div class="wi-section">
        <div class="wi-sec-title">Photos</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">

          <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Landmark Picture <span style="color:#dc2626">*</span></label>
            <div class="wi-cam-tabs">
              <button type="button" class="wi-cam-tab active" onclick="wiSwitchTab('wi_lm','file',this)">📁 File</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_lm','cam',this)">📷 Webcam</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_lm','mobile',this)">📱 Camera</button>
            </div>
            <div id="wi_lm-file"><input class="form-control" type="file" name="walkin_landmark_picture" id="wi_lm_file" accept="image/*" onchange="wiPreviewImg(this,'wi_lm_prev');clearWiWebcam('lm')"><img id="wi_lm_prev" class="wi-cam-preview"></div>
            <div id="wi_lm-cam" style="display:none">
              <div class="wi-cam-box"><video id="wi_lm_video" autoplay playsinline muted></video><canvas id="wi_lm_canvas" style="display:none"></canvas></div>
              <div class="wi-cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="wiStartCam('wi_lm')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="wiSnapCam('wi_lm','lm')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="wiRetakeCam('wi_lm','lm')">↩ Retake</button></div>
              <img id="wi_lm_snap" class="wi-cam-preview">
            </div>
            <div id="wi_lm-mobile" style="display:none"><input class="form-control" type="file" name="walkin_landmark_picture_cam" accept="image/*" capture="environment" onchange="wiPreviewImg(this,'wi_lm_prev_m');clearWiWebcam('lm')"><img id="wi_lm_prev_m" class="wi-cam-preview"></div>
          </div>

          <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Additional Picture <span style="color:var(--c-gray-400);font-weight:400">(optional)</span></label>
            <div class="wi-cam-tabs">
              <button type="button" class="wi-cam-tab active" onclick="wiSwitchTab('wi_ap','file',this)">📁 File</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_ap','cam',this)">📷 Webcam</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_ap','mobile',this)">📱 Camera</button>
            </div>
            <div id="wi_ap-file"><input class="form-control" type="file" name="walkin_additional_picture" id="wi_ap_file" accept="image/*" onchange="wiPreviewImg(this,'wi_ap_prev');clearWiWebcam('ap')"><img id="wi_ap_prev" class="wi-cam-preview"></div>
            <div id="wi_ap-cam" style="display:none">
              <div class="wi-cam-box"><video id="wi_ap_video" autoplay playsinline muted></video><canvas id="wi_ap_canvas" style="display:none"></canvas></div>
              <div class="wi-cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="wiStartCam('wi_ap')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="wiSnapCam('wi_ap','ap')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="wiRetakeCam('wi_ap','ap')">↩ Retake</button></div>
              <img id="wi_ap_snap" class="wi-cam-preview">
            </div>
            <div id="wi_ap-mobile" style="display:none"><input class="form-control" type="file" name="walkin_additional_picture_cam" accept="image/*" capture="environment" onchange="wiPreviewImg(this,'wi_ap_prev_m');clearWiWebcam('ap')"><img id="wi_ap_prev_m" class="wi-cam-preview"></div>
          </div>

          <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">🪟 Tile Image <span style="color:var(--c-gray-400);font-weight:400">(optional)</span></label>
            <div class="wi-cam-tabs">
              <button type="button" class="wi-cam-tab active" onclick="wiSwitchTab('wi_ti','file',this)">📁 File</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_ti','cam',this)">📷 Webcam</button>
              <button type="button" class="wi-cam-tab" onclick="wiSwitchTab('wi_ti','mobile',this)">📱 Camera</button>
            </div>
            <div id="wi_ti-file"><input class="form-control" type="file" name="walkin_tile_image" id="wi_ti_file" accept="image/*" onchange="wiPreviewImg(this,'wi_ti_prev')"><img id="wi_ti_prev" class="wi-cam-preview"></div>
            <div id="wi_ti-cam" style="display:none">
              <div class="wi-cam-box"><video id="wi_ti_video" autoplay playsinline muted></video><canvas id="wi_ti_canvas" style="display:none"></canvas></div>
              <div class="wi-cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="wiStartCam('wi_ti')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="wiSnapCam('wi_ti','tile')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="wiRetakeCam('wi_ti','tile')">↩ Retake</button></div>
              <img id="wi_ti_snap" class="wi-cam-preview">
            </div>
            <div id="wi_ti-mobile" style="display:none"><input class="form-control" type="file" name="walkin_tile_image_cam" accept="image/*" capture="environment" onchange="wiPreviewImg(this,'wi_ti_prev_m')"><img id="wi_ti_prev_m" class="wi-cam-preview"></div>
          </div>

        </div>
      </div>

      <!-- STATUS -->
      <div class="wi-section">
        <div class="wi-sec-title">Lead Status</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <?php
          $statDef=['hot'=>['🟢','Hot','High interest'],'warm'=>['🟡','Warm','Moderate interest'],'cold'=>['🔴','Cold','No interest']];
          $sColors=['hot'=>'var(--c-success)','warm'=>'var(--c-warn)','cold'=>'var(--c-danger)'];
          $sBgs=['hot'=>'var(--c-success-light)','warm'=>'var(--c-warn-light)','cold'=>'var(--c-danger-light)'];
          foreach ($statDef as $val=>[$em,$lb,$sb]):
            $chk=$val==='warm'?'checked':'';
            $bs=$val==='warm'?"border:2px solid {$sColors[$val]};background:{$sBgs[$val]}":'border:2px solid var(--c-gray-200)';
          ?>
          <label style="flex:1;min-width:100px;cursor:pointer">
            <input type="radio" name="walkin_status" value="<?= $val ?>" <?= $chk ?> class="wi-status-radio" style="display:none">
            <div class="wi-status-opt" data-val="<?= $val ?>" data-color="<?= $sColors[$val] ?>" data-bg="<?= $sBgs[$val] ?>"
                 style="<?= $bs ?>;border-radius:8px;padding:11px;text-align:center;transition:all .15s;cursor:pointer">
              <div style="font-size:1.2rem"><?= $em ?></div>
              <div style="font-size:.84rem;font-weight:600;color:<?= $sColors[$val] ?>"><?= $lb ?></div>
              <div style="font-size:.74rem;color:var(--c-gray-500)"><?= $sb ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Duplicate override -->
      <div style="margin-bottom:14px">
        <label style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--c-gray-600);cursor:pointer">
          <input type="checkbox" id="wi_force_chk" onchange="document.getElementById('wi_force').value=this.checked?1:0">
          Submit even if phone number already exists (duplicate override)
        </label>
      </div>

      <div class="alert alert-info" style="font-size:.82rem">
        💡 Submission will be assigned to the sub-admin linked to the selected zone. If no sub-admin is assigned, you (Office Admin) become the submitter.
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('walkinModal');wiStopAllCams()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="wi_submit_btn">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          Submit Walk-in
        </button>
      </div>
    </form>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     LOOKUP + WALK-IN JS
══════════════════════════════════════════════════════════════════════════ -->
<script>
/* ─── Lookup ──────────────────────────────────────────────────────────────── */
const STATUS_LABELS = {hot:'🟢 Hot', warm:'🟡 Warm', cold:'🔴 Cold'};
const STATUS_COLORS = {hot:'var(--c-success)', warm:'var(--c-warn)', cold:'var(--c-danger)'};

function openLookupModal() {
    lkReset();
    openModal('lookupModal');
    setTimeout(() => document.getElementById('lk_name').focus(), 200);
}

function lkClearErr() {
    document.getElementById('lk_error').style.display = 'none';
    ['lkf_name','lkf_phone'].forEach(id => document.getElementById(id)?.classList.remove('wi-err'));
}

function lkReset() {
    document.getElementById('lk_name').value  = '';
    document.getElementById('lk_phone').value = '';
    document.getElementById('lk_error').style.display    = 'none';
    document.getElementById('lk-form-wrap').style.display     = '';
    document.getElementById('lk-existing-wrap').style.display = 'none';
    document.getElementById('lk-spinner').style.display       = 'none';
    document.getElementById('lk_check_btn').disabled          = false;
    document.getElementById('lk_check_btn').innerHTML         = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Check &amp; Continue';
    ['lkf_name','lkf_phone'].forEach(id => document.getElementById(id)?.classList.remove('wi-err'));
}

async function lkCheck() {
    const name  = document.getElementById('lk_name').value.trim();
    const phone = document.getElementById('lk_phone').value.trim();

    let errs = [];
    if (!name)  { document.getElementById('lkf_name').classList.add('wi-err');  errs.push('Name'); }
    if (!phone) { document.getElementById('lkf_phone').classList.add('wi-err'); errs.push('Phone'); }
    if (errs.length) {
        const e = document.getElementById('lk_error');
        e.textContent = '⚠ Required: ' + errs.join(', ');
        e.style.display = 'block';
        return;
    }

    // Show spinner
    document.getElementById('lk-form-wrap').style.display = 'none';
    document.getElementById('lk-spinner').style.display   = '';
    document.getElementById('lk_check_btn').disabled = true;

    try {
        const fd = new FormData();
        fd.append('csrf', '<?= csrfToken() ?>');
        fd.append('name',  name);
        fd.append('phone', phone);

        const res  = await fetch('walkin_lookup.php', { method: 'POST', body: fd });
        const data = await res.json();

        document.getElementById('lk-spinner').style.display = 'none';

        if (data.found) {
            // Show existing customer card
            document.getElementById('lk-form-wrap').style.display     = 'none';
            document.getElementById('lk-existing-wrap').style.display = '';
            renderExistingCard(data.record, data.detail, data.areas);
        } else {
            // Not found → pre-fill walk-in form and open it
            closeModal('lookupModal');
            wiPrefill(data.prefill || { owner_name: name, owner_number: phone });
            openModal('walkinModal');
        }
    } catch (err) {
        document.getElementById('lk-spinner').style.display = 'none';
        document.getElementById('lk-form-wrap').style.display = '';
        const e = document.getElementById('lk_error');
        e.textContent = '❌ Network error. Please try again.';
        e.style.display = 'block';
        document.getElementById('lk_check_btn').disabled = false;
    }
}

function renderExistingCard(r, detail, areas) {
    const d = detail || {};
    const status = r.status || 'warm';
    const color  = STATUS_COLORS[status] || '#6b7280';
    const label  = STATUS_LABELS[status]  || status;

    const areaHtml = (areas && areas.length) ? areas.map(a => {
        const sqft = a.total_sqft || a.area_sqft || '';
        const dim  = (a.area_length && a.area_breadth) ? ` (${a.area_length}×${a.area_breadth} ft)` : '';
        const tile = a.tile_chosen ? `<span style="display:inline-block;background:#ecfdf5;color:#15803d;padding:1px 7px;border-radius:3px;font-size:.72rem;font-weight:600;margin-left:4px">🪟 ${escH(a.tile_chosen)}</span>` : '';
        return `<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin:2px 0"><span style="background:var(--c-gray-100);color:var(--c-gray-700);padding:2px 8px;border-radius:4px;font-size:.78rem;font-weight:600">${escH(a.area_type)}${sqft?' — '+parseFloat(sqft).toLocaleString()+' sqft'+dim:''}</span>${tile}</div>`;
    }).join('') : '<span style="font-size:.78rem;color:var(--c-gray-400)">No area data</span>';

    const dealHtml = d.deal_result
        ? `<div style="margin-top:7px;padding:7px 10px;background:var(--c-gray-50);border-radius:6px;font-size:.79rem;color:var(--c-gray-600)">Deal: <strong>${escH(d.deal_result)}</strong></div>`
        : '';

    const visitHtml = r.next_visit_date
        ? `<div style="font-size:.78rem;color:#0369a1;margin-top:5px">📅 Next Visit: <strong>${escH(r.next_visit_date)}</strong></div>`
        : '';

    const lockedBanner = parseInt(r.order_completed)
        ? `<div style="background:#16a34a;color:#fff;font-size:.72rem;font-weight:700;padding:5px 14px;text-align:center">✅ Order Completed${r.purchase_date ? ' — ' + r.purchase_date : ''}</div>`
        : '';

    document.getElementById('lk_existing_card').innerHTML = `
        ${lockedBanner}
        <div style="padding:13px 15px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:10px">
                <div>
                    <div style="font-weight:700;font-size:.97rem">${escH(r.owner_name)}</div>
                    <div style="font-size:.78rem;color:var(--c-gray-500)">${escH(r.owner_number)}</div>
                    <div style="font-size:.71rem;margin-top:3px;color:#15803d">👤 ${escH(r.sub_name || '—')}</div>
                </div>
                <span style="background:${color};color:#fff;padding:2px 10px;border-radius:99px;font-size:.75rem;font-weight:700;white-space:nowrap;flex-shrink:0">${label}</span>
            </div>
            <div style="font-size:.8rem;color:var(--c-gray-600);line-height:1.7">
                <div>📍 ${escH(r.village_city_town)}, ${escH(r.street)}</div>
                <div>👥 Rep: ${escH(r.representative_name)} · ${escH(r.representative_number)}</div>
                <div style="margin-top:2px;color:var(--c-gray-400);font-size:.74rem">🏘 Zone: ${escH((r.dir_code||'')+'›'+(r.zone||''))}</div>
            </div>
            <div style="margin-top:8px">${areaHtml}</div>
            ${visitHtml}
            ${dealHtml}
        </div>`;
}

function escH(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ─── Walk-in prefill ─────────────────────────────────────────────────────── */
function wiPrefill(data) {
    if (!data) return;
    if (data.owner_name)   document.getElementById('wi_owner_name').value  = data.owner_name;
    if (data.owner_number) document.getElementById('wi_owner_number').value = data.owner_number;
}

/* ─── Zone picker ─────────────────────────────────────────────────────────── */
function wiFilterZones(q){
    const lq = q.toLowerCase().trim();
    document.querySelectorAll('.wi-zone-card').forEach(c => {
        c.style.display = (!lq || c.dataset.search.includes(lq)) ? '' : 'none';
    });
}
function wiSelectZone(el, zoneId, label){
    document.querySelectorAll('.wi-zone-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('wi_sd_id').value = zoneId;
    document.getElementById('wi_zone_selected_display').innerHTML =
        '✅ Selected: <strong>' + label + '</strong>';
}

/* ─── Visit date ──────────────────────────────────────────────────────────── */
const DN = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function wiSetVisit(iso)  { document.getElementById('wi_next_visit').value = iso; wiVisitPreview(); }
function wiClearVisit()   { document.getElementById('wi_next_visit').value = ''; document.getElementById('wi_visit_preview').innerHTML = ''; }
function wiVisitPreview() {
    const val = document.getElementById('wi_next_visit').value;
    const el  = document.getElementById('wi_visit_preview');
    if (!val) { el.innerHTML = ''; return; }
    const p = val.split('-'), d = new Date(+p[0], +p[1]-1, +p[2]);
    const today = new Date(); today.setHours(0,0,0,0);
    const diff  = Math.round((d - today) / 86400000);
    const lbl   = diff === 0 ? 'today' : diff === 1 ? 'tomorrow' : diff > 0 ? 'in ' + diff + ' days' : Math.abs(diff) + ' days ago';
    el.innerHTML = '📅 ' + DN[d.getDay()] + ', ' + d.getDate() + ' ' + MN[d.getMonth()] + ' ' + d.getFullYear() +
        ' <span style="display:inline-block;background:#0284c7;color:#fff;font-size:.7rem;padding:1px 8px;border-radius:12px;font-weight:700;margin-left:5px">' + lbl + '</span>';
}

/* ─── Area rows ───────────────────────────────────────────────────────────── */
let wiAreaIdx = 0;
const WI_ROOM_TYPES = [
    'Hall / Living Room','Master Bedroom','Bedroom','Kitchen','Bathroom','Toilet',
    'Dining Room','Balcony','Corridor / Passage','Staircase','Pooja Room',
    'Store Room','Garage','Office Room','Commercial Space','Other'
];
const WI_TILE_OPTIONS = [
    'Kajaria','Somany','RAK Ceramics','Johnson Tiles','Nitco',
    'Asian Granito','Orient Bell','Simpolo','Sunhearrt','Lavish',
    'Millennium Tiles','H&R Johnson','Morbi Local','Imported Tiles','Other'
];
function wiAddArea(){
    const idx  = wiAreaIdx++;
    const roomOpts  = WI_ROOM_TYPES.map(t  => `<option value="${t}">${t}</option>`).join('');
    const tileOpts  = WI_TILE_OPTIONS.map(t => `<option value="${t}">${t}</option>`).join('');
    const div  = document.createElement('div');
    div.className = 'wi-area-row'; div.id = 'wi-area-' + idx;
    div.innerHTML = `
        <div class="wi-area-header">
          <span class="wi-area-num">Area ${idx+1}</span>
          <button type="button" class="wi-remove-area" onclick="wiRemoveArea(${idx})">✕ Remove</button>
        </div>
        <div class="wi-area-fields">
          <div class="form-group" style="margin:0;grid-column:1/-1"><label class="form-label" style="font-size:.74rem">Area / Room Name <span style="color:#dc2626">*</span></label><input class="form-control wi-area-req" type="text" name="walkin_area_type[]" id="wi_atype_${idx}" placeholder="e.g. Master Bedroom, Hall, Kitchen…" list="wi_room_list_${idx}"><datalist id="wi_room_list_${idx}">${roomOpts.replace(/<option value="([^"]+)">[^<]+<\/option>/g,'<option value="$1">')}</datalist></div>
          <div class="form-group" style="margin:0"><label class="form-label" style="font-size:.74rem">Length (ft) <span style="color:#dc2626">*</span></label><input class="form-control wi-area-req" type="number" name="walkin_area_length[]" id="wi_alen_${idx}" min="0" step="0.01" placeholder="e.g. 40" oninput="wiCalc(${idx})"></div>
          <div class="form-group" style="margin:0"><label class="form-label" style="font-size:.74rem">Breadth (ft) <span style="color:#dc2626">*</span></label><input class="form-control wi-area-req" type="number" name="walkin_area_breadth[]" id="wi_abreadth_${idx}" min="0" step="0.01" placeholder="e.g. 60" oninput="wiCalc(${idx})"></div>
          <div class="form-group" style="margin:0"><label class="form-label" style="font-size:.74rem">Manual Sq Ft <span style="color:var(--c-gray-400);font-weight:400">(if no L×B)</span></label><input class="form-control" type="number" name="walkin_area_sqft[]" id="wi_sqft_${idx}" min="0" step="0.01" placeholder="auto-filled" oninput="wiCalc(${idx})" readonly></div>
          <div class="form-group" style="margin:0"><label class="form-label" style="font-size:.74rem">Units / Count</label><input class="form-control" type="number" name="walkin_area_count[]" id="wi_acount_${idx}" min="0" step="1" placeholder="optional"></div>
        </div>
        <div id="wi_calc_${idx}" class="wi-area-calc" style="display:none">
          <div><div style="font-size:.72rem;color:#15803d;font-weight:600">Total Area</div><div class="wi-calc-result" id="wi_calc_res_${idx}">0</div><div style="font-size:.74rem;color:#4ade80" id="wi_calc_fml_${idx}"></div></div>
        </div>
        <div class="wi-tile-row">
          <label class="form-label" style="font-size:.74rem;margin-bottom:4px">🪟 Tile Chosen <span style="color:var(--c-gray-400);font-weight:400">(optional)</span></label>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select class="form-control" name="walkin_tile_chosen[]" id="wi_tile_${idx}" style="flex:1;min-width:160px" onchange="wiTileSelectChange(${idx})">
              <option value="">— Select Tile Brand —</option>
              ${tileOpts}
            </select>
            <input class="form-control" type="text" name="walkin_tile_custom[]" id="wi_tile_custom_${idx}" placeholder="Or type custom tile name…" style="flex:1;min-width:160px" oninput="wiTileCustomInput(${idx})">
          </div>
          <div id="wi_tile_preview_${idx}" style="display:none;margin-top:5px;padding:5px 10px;background:#f0fdf4;border-radius:6px;font-size:.78rem;color:#15803d;font-weight:600"></div>
        </div>`;
    document.getElementById('wi-area-rows').appendChild(div);
    wiRenumber();
}
function wiRemoveArea(idx){ const el = document.getElementById('wi-area-'+idx); if(el) el.remove(); wiRenumber(); }
function wiRenumber(){ document.querySelectorAll('.wi-area-row').forEach((el,i)=>{ const n=el.querySelector('.wi-area-num'); if(n) n.textContent='Area '+(i+1); }); }
function wiCalc(idx){
    const len    = parseFloat(document.getElementById('wi_alen_'+idx)?.value)||0;
    const breadth= parseFloat(document.getElementById('wi_abreadth_'+idx)?.value)||0;
    const sqftEl = document.getElementById('wi_sqft_'+idx);
    const box=document.getElementById('wi_calc_'+idx), res=document.getElementById('wi_calc_res_'+idx), fml=document.getElementById('wi_calc_fml_'+idx);
    if(!box) return;
    if(len>0&&breadth>0){
        const t=(len*breadth).toFixed(2);
        if(sqftEl){sqftEl.value=t;}
        box.style.display='flex';
        res.textContent=parseFloat(t).toLocaleString()+' sq ft';
        fml.textContent=len+' × '+breadth+' ft';
    } else {
        if(sqftEl) sqftEl.value='';
        box.style.display='none';
    }
}
function wiTileSelectChange(idx){
    const sel=document.getElementById('wi_tile_'+idx);
    const cus=document.getElementById('wi_tile_custom_'+idx);
    const prev=document.getElementById('wi_tile_preview_'+idx);
    if(sel.value){ cus.value=''; prev.textContent='🪟 '+sel.value; prev.style.display='block'; }
    else { prev.style.display='none'; }
}
function wiTileCustomInput(idx){
    const sel=document.getElementById('wi_tile_'+idx);
    const cus=document.getElementById('wi_tile_custom_'+idx);
    const prev=document.getElementById('wi_tile_preview_'+idx);
    if(cus.value.trim()){ sel.value=''; prev.textContent='🪟 '+cus.value.trim(); prev.style.display='block'; }
    else { prev.style.display='none'; }
}
wiAddArea(); // add first area row on load

/* ─── GPS ─────────────────────────────────────────────────────────────────── */
function wiFetchGPS(){
    const st=document.getElementById('wi_gps_status'), btn=document.getElementById('wi_gps_btn'), box=document.getElementById('wi_gps_box');
    st.textContent='Fetching GPS…'; st.style.color='var(--c-gray-500)'; btn.disabled=true; btn.textContent='Locating…';
    if(!navigator.geolocation){st.textContent='Not supported.'; btn.disabled=false; return;}
    navigator.geolocation.getCurrentPosition(pos=>{
        const lat=pos.coords.latitude.toFixed(7), lng=pos.coords.longitude.toFixed(7);
        document.getElementById('wi_lat').value=lat; document.getElementById('wi_lng').value=lng;
        document.getElementById('wi_coord_display').innerHTML='<a href="https://maps.google.com/?q='+lat+','+lng+'" target="_blank" style="color:var(--c-primary)">📍 Lat: '+lat+' / Lng: '+lng+'</a>';
        st.textContent='✓ Captured'; st.style.color='var(--c-success)'; box.classList.add('gps-ok'); btn.disabled=false; btn.textContent='Re-fetch GPS';
    }, e=>{st.textContent='Error: '+e.message; st.style.color='var(--c-danger)'; btn.disabled=false; btn.textContent='Retry GPS';}, {enableHighAccuracy:true,timeout:12000,maximumAge:0});
}

/* ─── Status radio ────────────────────────────────────────────────────────── */
document.querySelectorAll('.wi-status-radio').forEach(r=>{
    r.addEventListener('change', ()=>{
        document.querySelectorAll('.wi-status-opt').forEach(o=>{o.style.border='2px solid var(--c-gray-200)'; o.style.background='';});
        const opt=document.querySelector(`.wi-status-opt[data-val="${r.value}"]`);
        if(opt){opt.style.border=`2px solid ${opt.dataset.color}`; opt.style.background=opt.dataset.bg;}
    });
});

/* ─── Webcam helpers ──────────────────────────────────────────────────────── */
const wiCamStreams={};
async function wiStartCam(prefix){
    try{
        const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
        wiCamStreams[prefix]=stream;
        const vid=document.getElementById(prefix+'_video'); vid.srcObject=stream; vid.style.display='block';
        document.getElementById(prefix+'_snap').style.display='none';
    }catch(e){alert('Camera error: '+e.message);}
}
function wiSnapCam(prefix,key){
    const vid=document.getElementById(prefix+'_video'), can=document.getElementById(prefix+'_canvas'), snap=document.getElementById(prefix+'_snap');
    can.width=vid.videoWidth; can.height=vid.videoHeight; can.getContext('2d').drawImage(vid,0,0);
    const url=can.toDataURL('image/jpeg',.85); snap.src=url; snap.style.display='block'; vid.style.display='none';
    const fm={lm:'wi_webcam_lm_data',ap:'wi_webcam_ap_data',tile:'wi_webcam_tile_data'};
    const df=document.getElementById(fm[key]||''); if(df) df.value=url;
    wiStopCam(prefix);
}
function wiRetakeCam(prefix,key){
    document.getElementById(prefix+'_snap').style.display='none';
    const fm={lm:'wi_webcam_lm_data',ap:'wi_webcam_ap_data',tile:'wi_webcam_tile_data'};
    const df=document.getElementById(fm[key]||''); if(df) df.value='';
    wiStartCam(prefix);
}
function wiStopCam(prefix){
    if(wiCamStreams[prefix]){wiCamStreams[prefix].getTracks().forEach(t=>t.stop()); delete wiCamStreams[prefix];}
    const vid=document.getElementById(prefix+'_video'); if(vid) vid.style.display='none';
}
function wiStopAllCams(){ ['wi_lm','wi_ap','wi_ti'].forEach(p=>wiStopCam(p)); }
function wiSwitchTab(prefix,mode,btn){
    ['file','cam','mobile'].forEach(m=>{const el=document.getElementById(prefix+'-'+m); if(el) el.style.display=m===mode?'':'none';});
    btn.closest('.wi-cam-tabs').querySelectorAll('.wi-cam-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    if(mode!=='cam') wiStopCam(prefix);
}
function wiPreviewImg(input,id){
    const el=document.getElementById(id); if(!el||!input.files?.[0]) return;
    const r=new FileReader(); r.onload=e=>{el.src=e.target.result; el.style.display='block';}; r.readAsDataURL(input.files[0]);
}
function clearWiWebcam(key){ const fm={lm:'wi_webcam_lm_data',ap:'wi_webcam_ap_data'}; const df=document.getElementById(fm[key]||''); if(df) df.value=''; }

/* ─── Submit validation ───────────────────────────────────────────────────── */
function pad(n){return String(n).padStart(2,'0');}
function istParts(){
    const fmt=new Intl.DateTimeFormat('en-CA',{
        timeZone:'Asia/Kolkata',
        year:'numeric',
        month:'2-digit',
        day:'2-digit',
        hour:'2-digit',
        minute:'2-digit',
        second:'2-digit',
        hour12:false
    });
    const out={};
    for(const part of fmt.formatToParts(new Date())){
        if(part.type!=='literal') out[part.type]=part.value;
    }
    return out;
}
function nowISO(){
    const n=istParts();
    return n.year+'-'+n.month+'-'+n.day+'T'+n.hour+':'+n.minute+':'+n.second;
}

document.getElementById('walkinForm').addEventListener('submit', function(e){
    document.getElementById('wi_dev_time').value = nowISO();

    // Merge tile_chosen select + custom input → write final value into select
    document.querySelectorAll('.wi-area-row').forEach(row=>{
        const sel    = row.querySelector('select[name="walkin_tile_chosen[]"]');
        const custom = row.querySelector('input[name="walkin_tile_custom[]"]');
        if(!sel || !custom) return;
        const cv = custom.value.trim();
        if(cv){
            sel.value = '';
            const opt = document.createElement('option');
            opt.value = cv; opt.textContent = cv; opt.selected = true;
            sel.appendChild(opt);
        }
    });

    const missing = [];

    if (!document.getElementById('wi_sd_id').value) missing.push('Zone');

    const fields = [
        ['wi_owner_name','Owner Name'],['wi_owner_number','Owner Phone'],
        ['wi_rep_name','Rep Name'],['wi_rep_number','Rep Phone'],
        ['wi_village','Village/City'],['wi_street','Street']
    ];
    fields.forEach(([id,label])=>{
        const el=document.getElementById(id);
        if(!(el?.value||'').trim()){missing.push(label); el?.closest('.wi-field')?.classList.add('wi-err');}
        else el?.closest('.wi-field')?.classList.remove('wi-err');
    });

    let areaOk=true;
    document.querySelectorAll('.wi-area-row').forEach(row=>{
        row.querySelectorAll('.wi-area-req').forEach(inp=>{
            if(!(inp.value||'').trim()){inp.style.borderColor='#dc2626'; areaOk=false;}
            else inp.style.borderColor='';
        });
    });
    if(!areaOk) missing.push('Area fields');

    const lmFile = document.getElementById('wi_lm_file')?.files?.length;
    const lmData = document.getElementById('wi_webcam_lm_data')?.value;
    if(!lmFile && !lmData) missing.push('Landmark Picture');

    if(missing.length){
        e.preventDefault();
        wiToast('⚠ Required: ' + missing[0]);
        if(document.querySelector('.wi-err')) document.querySelector('.wi-err').scrollIntoView({behavior:'smooth',block:'center'});
        else if(!document.getElementById('wi_sd_id').value) document.getElementById('wi_zone_picker').scrollIntoView({behavior:'smooth',block:'center'});
        return;
    }
    const btn=document.getElementById('wi_submit_btn'); btn.disabled=true; btn.innerHTML='⏳ Submitting…';
});
/* tile row */
.wi-tile-row{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-top:8px}
</script>
