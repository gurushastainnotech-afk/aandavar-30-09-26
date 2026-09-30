<?php
// ============================================================
// admin/estimate_modal.php — v4.0
//
// PER-ROW PRICING MODEL (matches print_submission.php v7.1):
//   mrp_per_box   → user enters
//   mop_per_box   → user enters
//   mrp_total     = box_count × mrp_per_box   (auto)
//   mop_total     = box_count × mop_per_box   (auto)
//   discount_pct  = 100 − ((mop_per_box / mrp_per_box) × 100)  (auto)
//   offer         = text stored as offer% string e.g. "10"
//   surface       = floor | wall | bath | step | riser  (row type identifier)
//
// ROWS derived from submission_areas using same logic as
// print_submission.php v7.1:
//   - floor surface  → 1 row using floor_tile_size_key
//   - wall surface   → 1 row using wall_tile_size_key
//   - both           → 2 rows (floor + wall)
//   - is_bathroom    → up to 3 bath partition rows
//   - step/riser     → count-based rows using step_count/riser_count
//
// GET  ?submission_id=N  → JSON for modal
// POST ?submission_id=N  → save estimate (FormData)
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db   = getDB();
$user = currentUser();

$subId = (int)($_GET['submission_id'] ?? $_POST['submission_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

if (!$subId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing submission_id']);
    exit;
}

// ── Ensure tables exist ──────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS submission_estimates (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        submission_id INT UNSIGNED NOT NULL,
        created_by    INT UNSIGNED NULL,
        updated_by    INT UNSIGNED NULL,
        notes         TEXT NULL,
        is_finalised  TINYINT(1) NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_est_submission (submission_id),
        KEY idx_est_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS submission_estimate_rooms (
        id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        estimate_id      INT UNSIGNED      NOT NULL,
        submission_id    INT UNSIGNED      NOT NULL,
        area_id          INT UNSIGNED      NULL,
        area_name        VARCHAR(200)      NOT NULL DEFAULT '',
        surface          VARCHAR(10)       NOT NULL DEFAULT 'floor',
        total_sqft       DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
        tile_size_label  VARCHAR(100)      NOT NULL DEFAULT '',
        box_count        INT UNSIGNED      NOT NULL DEFAULT 0,
        extra_box        INT               NOT NULL DEFAULT 0,
        item_count       INT UNSIGNED      NOT NULL DEFAULT 0,
        mrp_per_box      DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
        mop_per_box      DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
        discount_pct     DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
        mrp_total        DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
        mop_total        DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
        offer            TEXT              NULL,
        include_in_print TINYINT(1)        NOT NULL DEFAULT 1,
        sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ser_estimate   (estimate_id),
        KEY idx_ser_submission (submission_id),
        KEY idx_ser_area       (area_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Patch missing columns on existing tables
    $patchCols = [
        ['submission_estimate_rooms', 'mrp_per_box',  'DECIMAL(10,2) NOT NULL DEFAULT 0.00'],
        ['submission_estimate_rooms', 'mop_per_box',  'DECIMAL(10,2) NOT NULL DEFAULT 0.00'],
        ['submission_estimate_rooms', 'extra_box',    'INT NOT NULL DEFAULT 0'],
        ['submission_estimate_rooms', 'item_count',   'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['submission_estimate_rooms', 'discount_pct', 'DECIMAL(5,2)  NOT NULL DEFAULT 0.00'],
        ['submission_estimate_rooms', 'mrp_total',    'DECIMAL(12,2) NOT NULL DEFAULT 0.00'],
        ['submission_estimate_rooms', 'mop_total',    'DECIMAL(12,2) NOT NULL DEFAULT 0.00'],
        ['submission_estimate_rooms', 'surface',      "VARCHAR(10) NOT NULL DEFAULT 'floor'"],
    ];
    foreach ($patchCols as [$tbl, $col, $def]) {
        $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'")->fetchColumn();
        if (!$exists) {
            $db->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
        }
    }
} catch (Throwable $e) { /* tables already exist */ }

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Convert tile size key "W|H|PPB" to human label
 */
function estCustomTile($custom): ?array {
    if (is_string($custom) && $custom !== '') {
        $decoded = json_decode($custom, true);
        $custom = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($custom)) return null;
    $w = (float)($custom['w'] ?? $custom['width'] ?? $custom['custw'] ?? $custom['custom_w'] ?? 0);
    $h = (float)($custom['h'] ?? $custom['height'] ?? $custom['custh'] ?? $custom['custom_h'] ?? 0);
    $ppb = (int)($custom['ppb'] ?? $custom['pieces_per_box'] ?? $custom['custppb'] ?? $custom['custom_ppb'] ?? 0);
    if ($w <= 0 || $h <= 0 || $ppb <= 0) return null;
    return ['w' => $w, 'h' => $h, 'ppb' => $ppb, 'area' => $w * $h];
}

function estTileSizeLabel(?string $key, $custom = null): string {
    static $labels = [
        '1.5|1|6'     => '1.50×1.00 ft · 6/box',
        '1|1|8'       => '1.00×1.00 ft · 8/box',
        '2|1|5'       => '2.00×1.00 ft · 5/box',
        '2|2|4'       => '2.00×2.00 ft · 4/box',
        '1.25|1.25|5' => '1.25×1.25 ft · 5/box',
        '4|2|2'       => '4.00×2.00 ft · 2/box',
        '2.75|5.5|2'  => '2.75×5.50 ft · 2/box',
        '6|4|2'       => '6.00×4.00 ft · 2/box',
        '2.75|8|1'    => '2.75×8.00 ft · 1/box',
    ];
    $customTile = estCustomTile($custom);
    if ($customTile) {
        return number_format($customTile['w'], 2) . '×' . number_format($customTile['h'], 2) . ' ft · ' . $customTile['ppb'] . '/box';
    }
    if (!$key) return '—';
    if ($key === 'custom') return 'Custom';
    if (isset($labels[$key])) return $labels[$key];
    $p = explode('|', $key);
    $w = (float)($p[0] ?? 0); $h = (float)($p[1] ?? 0); $ppb = (int)($p[2] ?? 1);
    if (!$w || !$h) return $key;
    return "{$w}×{$h} ft · {$ppb}/box";
}

/**
 * Calculate box count from sqft, tile key, wastage%
 * Matches calcBoxes() in print_submission.php exactly
 */
function estBoxCount(float $sqft, ?string $key, float $wastePct, $custom = null): int {
    if ($sqft <= 0) return 0;
    $customTile = estCustomTile($custom);
    if ($customTile) {
        $tileArea = $customTile['area'];
        $ppb = max(1, (int)$customTile['ppb']);
    } else {
        if (!$key || $key === 'custom') return 0;
        $p = explode('|', $key);
        $tileArea = (float)($p[0] ?? 0) * (float)($p[1] ?? 0);
        $ppb = max(1, (int)($p[2] ?? 1));
    }
    if ($tileArea <= 0) return 0;
    $needed = (int)ceil($sqft / $tileArea);
    $extra  = (int)ceil($needed * ($wastePct / 100));
    return (int)ceil(($needed + $extra) / $ppb);
}

// NEW STEP/RISER SUPPORT: Calculate box count by item count (for steps/risers)
/**
 * For step/riser surfaces: calculate boxes needed for N items
 * Each item = 1 tile, apply wastage%, then divide by pieces per box
 */
function estBoxCountByItem(int $itemCount, ?string $key, float $wastePct, $custom = null): int {
    if ($itemCount <= 0) return 0;
    $customTile = estCustomTile($custom);
    if ($customTile) {
        $ppb = max(1, (int)$customTile['ppb']);
    } else {
        if (!$key || $key === 'custom') return 0;
        $p = explode('|', $key);
        $ppb = max(1, (int)($p[2] ?? 1));
    }
    if ($ppb <= 0) return 0;
    $needed = $itemCount;
    $extra  = (int)ceil($needed * ($wastePct / 100));
    return (int)ceil(($needed + $extra) / $ppb);
}

function calcTotalBoxes(int $baseBoxes, int $extraBox): int {
    return max(0, $baseBoxes + $extraBox);
}

/**
 * MRP Total = box_count × mrp_per_box
 */
function calcMrpTotal(int $boxes, float $mrpPerBox): float {
    return round($boxes * $mrpPerBox, 2);
}

/**
 * MOP Total = box_count × mop_per_box
 */
function calcMopTotal(int $boxes, float $mopPerBox): float {
    return round($boxes * $mopPerBox, 2);
}

/**
 * Discount% = 100 - ((mop_per_box / mrp_per_box) × 100)
 * i.e. how much % cheaper than MRP the MOP is
 */
function calcDiscountPct(float $mrpPerBox, float $mopPerBox): float {
    if ($mrpPerBox <= 0) return 0.0;
    return round(max(0, 100.0 - ($mopPerBox / $mrpPerBox * 100.0)), 2);
}

/**
 * Derive all pricing rows from a submission_area row.
 * Mirrors print_submission.php v7.1 area loop exactly.
 * NEW STEP/RISER SUPPORT: Now handles floor, wall, bath, step, riser
 * and combined step_riser surfaces with count-based calculations.
 */
function deriveAreaRows(array $a): array {
    $rows    = [];
    $surface = $a['tile_surface'] ?? 'floor';
    $L       = (float)($a['area_length']  ?? 0);
    $B       = (float)($a['area_breadth'] ?? 0);
    $H       = (float)($a['area_height']  ?? 0);
    $sqft    = (float)($a['floor_sqft'] ?? $a['total_sqft'] ?? $a['area_sqft'] ?? 0);
    $wallSqftSaved = (float)($a['wall_sqft'] ?? $a['total_sqft'] ?? $a['area_sqft'] ?? 0);
    $isBath  = (int)($a['is_bathroom'] ?? 0) === 1;
    
    // NEW STEP/RISER SUPPORT: Get step/riser counts from submission_areas
    $stepCount = (int)($a['step_count'] ?? 0);
    $riserCount = (int)($a['riser_count'] ?? 0);

    // Floor row
    if ($surface === 'floor' || $surface === 'both') {
        $fKey   = $a['floor_tile_size_key'] ?? $a['tile_size_key'] ?? '2|2|4';
        $fWaste = (float)($a['floor_wastage_pct'] ?? $a['wastage_pct'] ?? 10);
        $fCustom = $a['floor_tile_custom'] ?? null;
        $rows[] = [
            'area_id'        => (int)$a['id'],
            'area_name'      => ($a['area_type'] ?: 'Room') . ($surface === 'both' ? ' — Floor' : ''),
            'surface'        => 'floor',
            'total_sqft'     => $sqft,
            'tile_size_key'  => $fKey,
            'tile_size_label'=> estTileSizeLabel($fKey, $fCustom),
            'box_count'      => estBoxCount($sqft, $fKey, $fWaste, $fCustom),
        ];
    }

    // Wall row
    if ($surface === 'wall' || $surface === 'both') {
        $wSqft  = $wallSqftSaved;
        $wKey   = $a['wall_tile_size_key'] ?? '1|1|8';
        $wWaste = (float)($a['wall_wastage_pct'] ?? 10);
        $wCustom = $a['wall_tile_custom'] ?? null;
        $rows[] = [
            'area_id'        => (int)$a['id'],
            'area_name'      => ($a['area_type'] ?: 'Room') . ($surface === 'both' ? ' — Wall' : ''),
            'surface'        => 'wall',
            'total_sqft'     => $wSqft,
            'tile_size_key'  => $wKey,
            'tile_size_label'=> estTileSizeLabel($wKey, $wCustom),
            'box_count'      => estBoxCount($wSqft, $wKey, $wWaste, $wCustom),
        ];
    }

    // Bathroom partition rows
    if ($isBath) {
        $perim  = ($L && $B) ? 2 * ($L + $B) : 0;
        $bKey   = $a['bath_tile_size_key'] ?? $a['bath_tile_size'] ?? '1|1|8';
        $bWaste = (float)($a['bath_wastage_pct'] ?? 10);
        $bCustom = $a['bath_tile_custom'] ?? null;
        $parts  = [
            'Light'     => (float)($a['bath_light_h'] ?? 0),
            'Highlight' => (float)($a['bath_high_h']  ?? 0),
            'Dark'      => (float)($a['bath_dark_h']  ?? 0),
        ];
        foreach ($parts as $pName => $pH) {
            if (!$pH || !$perim) continue;
            $pSqft = round($perim * $pH, 2);
            $rows[] = [
                'area_id'        => (int)$a['id'],
                'area_name'      => ($a['area_type'] ?: 'Room') . " — Bath {$pName}",
                'surface'        => 'bath',
                'total_sqft'     => $pSqft,
                'tile_size_key'  => $bKey,
                'tile_size_label'=> estTileSizeLabel($bKey, $bCustom),
                'box_count'      => estBoxCount($pSqft, $bKey, $bWaste, $bCustom),
            ];
        }
    }

    // NEW STEP/RISER SUPPORT: Handle step surface (count-based, not area-based)
    // Always include a step row when a step count is present.
    if ($stepCount > 0) {
        $sKey   = $a['step_tile_size_key'] ?? $a['step_riser_tile_size_key'] ?? '3|1|1';
        $sWaste = (float)($a['step_wastage_pct'] ?? $a['step_riser_wastage_pct'] ?? 10);
        $sCustom = $a['step_tile_custom'] ?? $a['step_riser_tile_custom'] ?? null;
        $sBoxes = estBoxCountByItem($stepCount, $sKey, $sWaste, $sCustom);
        $rows[] = [
            'area_id'        => (int)$a['id'],
            'area_name'      => ($a['area_type'] ?: 'Room') . ' — Steps',
            'surface'        => 'step',
            'total_sqft'     => 0.0,
            'tile_size_key'  => $sKey,
            'tile_size_label'=> estTileSizeLabel($sKey, $sCustom),
            'box_count'      => $sBoxes,
            'item_count'     => $stepCount,
        ];
    }

    // NEW STEP/RISER SUPPORT: Handle riser surface (count-based, not area-based)
    // Always include a riser row when a riser count is present.
    $riserCount += (int)($a['riser_landing_tiles'] ?? 0);
    if ($riserCount > 0) {
        $rKey   = $a['riser_tile_size_key'] ?? $a['step_riser_tile_size_key'] ?? '3|0.8|1';
        $rWaste = (float)($a['riser_wastage_pct'] ?? $a['step_riser_wastage_pct'] ?? 10);
        $rCustom = $a['riser_tile_custom'] ?? $a['step_riser_tile_custom'] ?? null;
        $rBoxes = estBoxCountByItem($riserCount, $rKey, $rWaste, $rCustom);
        $rows[] = [
            'area_id'        => (int)$a['id'],
            'area_name'      => ($a['area_type'] ?: 'Room') . ' — Risers',
            'surface'        => 'riser',
            'total_sqft'     => (float)($a['riser_landing_sqft'] ?? 0),
            'tile_size_key'  => $rKey,
            'tile_size_label'=> estTileSizeLabel($rKey, $rCustom),
            'box_count'      => $rBoxes,
            'item_count'     => $riserCount,
        ];
    }

    return $rows;
}

// ── Load submission ───────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT s.*, u.username AS submitted_by_name,
           sd.code AS zone_code, d.code AS dir_code
    FROM   submissions s
    LEFT JOIN users          u  ON s.submitted_by     = u.id
    LEFT JOIN sub_directions sd ON s.sub_direction_id = sd.id
    LEFT JOIN directions     d  ON sd.direction_id    = d.id
    WHERE  s.id = ?
");
$stmt->execute([$subId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    http_response_code(404);
    echo json_encode(['error' => 'Submission not found']);
    exit;
}

// ── Load submission areas ─────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM submission_areas WHERE submission_id = ? ORDER BY sort_order, id");
$stmt->execute([$subId]);
$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Load estimate header ──────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT se.*, u.username AS created_by_name
    FROM submission_estimates se
    LEFT JOIN users u ON se.created_by = u.id
    WHERE se.submission_id = ? LIMIT 1
");
$stmt->execute([$subId]);
$estimateRow = $stmt->fetch(PDO::FETCH_ASSOC);
$estimateId  = $estimateRow ? (int)$estimateRow['id'] : null;

// ── Load saved estimate rooms keyed by area_id + surface ─────────────────────
$savedRooms = [];
if ($estimateId) {
    $stmt = $db->prepare("SELECT * FROM submission_estimate_rooms WHERE estimate_id = ? ORDER BY sort_order, id");
    $stmt->execute([$estimateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $key = ((int)($er['area_id'] ?? 0)) . '|' . ($er['surface'] ?? 'floor');
        $savedRooms[$key] = $er;
    }
}

// ════════════════════════════════════════════════════════════
// POST — Save Estimate
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rooms = $_POST['rooms'] ?? [];

    $db->beginTransaction();
    try {
        if ($estimateId) {
            $db->prepare("UPDATE submission_estimates SET updated_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $estimateId]);
        } else {
            $db->prepare("INSERT INTO submission_estimates (submission_id, created_by) VALUES (?,?)")
               ->execute([$subId, $user['id']]);
            $estimateId = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM submission_estimate_rooms WHERE estimate_id=?")->execute([$estimateId]);

        $ins = $db->prepare(
            "INSERT INTO submission_estimate_rooms
                (estimate_id, submission_id, area_id, area_name,
                 surface, total_sqft, tile_size_label, box_count,
                 item_count, extra_box, mrp_per_box, mop_per_box, discount_pct,
                 mrp_total, mop_total,
                 offer, include_in_print, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($rooms as $idx => $rm) {
            $baseBoxes = (int)($rm['box_count'] ?? 0);
            $itemCount = (int)($rm['item_count'] ?? 0);
            $extraBox  = (int)($rm['extra_box'] ?? 0);
            $totalBoxes = calcTotalBoxes($baseBoxes, $extraBox);
            $mrpPerBox = (float)($rm['mrp_per_box'] ?? 0);
            $mopPerBox = (float)($rm['mop_per_box'] ?? 0);
            $mrpTotal  = calcMrpTotal($totalBoxes, $mrpPerBox);
            $mopTotal  = calcMopTotal($totalBoxes, $mopPerBox);
            $discPct   = calcDiscountPct($mrpPerBox, $mopPerBox);
            $offerRaw  = trim($rm['offer'] ?? '');

            $ins->execute([
                $estimateId,
                $subId,
                ($rm['area_id'] ? (int)$rm['area_id'] : null),
                trim($rm['area_name'] ?? ''),
                trim($rm['surface'] ?? 'floor'),
                (float)($rm['total_sqft'] ?? 0),
                trim($rm['tile_size_label'] ?? ''),
                $baseBoxes,
                $itemCount,
                $extraBox,
                $mrpPerBox,
                $mopPerBox,
                $discPct,
                $mrpTotal,
                $mopTotal,
                ($offerRaw !== '' ? $offerRaw : null),
                isset($rm['include_in_print']) && $rm['include_in_print'] ? 1 : 0,
                (int)$idx,
            ]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'estimate_id' => $estimateId]);
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// GET — Build unified rows and return JSON
// ════════════════════════════════════════════════════════════

$roomRows = [];
$sortIdx  = 0;

foreach ($areas as $area) {
    $derived = deriveAreaRows($area);
    foreach ($derived as $row) {
        $lookupKey = $row['area_id'] . '|' . $row['surface'];
        $saved     = $savedRooms[$lookupKey] ?? null;

        $offerRaw  = $saved ? trim($saved['offer'] ?? '') : '';
        $offerPct  = is_numeric($offerRaw) ? (float)$offerRaw : 0.0;

        $roomRows[] = [
            'id'               => $saved ? (int)($saved['id'] ?? 0) : null,
            'area_id'          => $row['area_id'],
            'area_name'        => $row['area_name'],
            'surface'          => $row['surface'],
            'total_sqft'       => $saved ? (float)($saved['total_sqft'] ?? $row['total_sqft']) : $row['total_sqft'],
            'tile_size_label'  => $saved && trim((string)($saved['tile_size_label'] ?? '')) !== ''
                                  && !in_array(strtolower(trim((string)$saved['tile_size_label'])), ['custom', 'custom size'], true)
                                     ? trim($saved['tile_size_label'])
                                     : $row['tile_size_label'],
            'box_count'        => $saved ? (int)($saved['box_count'] ?? $row['box_count']) : $row['box_count'],
            'item_count'       => $saved ? (int)($saved['item_count'] ?? $row['item_count'] ?? 0) : ($row['item_count'] ?? 0),
            'extra_box'        => $saved ? (int)($saved['extra_box'] ?? 0) : 0,
            'total_box_count'  => calcTotalBoxes($saved ? (int)($saved['box_count'] ?? $row['box_count']) : (int)$row['box_count'], $saved ? (int)($saved['extra_box'] ?? 0) : 0),
            'mrp_per_box'      => $saved ? (float)($saved['mrp_per_box'] ?? 0) : 0.0,
            'mop_per_box'      => $saved ? (float)($saved['mop_per_box'] ?? 0) : 0.0,
            'discount_pct'     => $saved ? (float)($saved['discount_pct'] ?? 0) : 0.0,
            'mrp_total'        => $saved ? (float)($saved['mrp_total'] ?? 0) : 0.0,
            'mop_total'        => $saved ? (float)($saved['mop_total'] ?? 0) : 0.0,
            'offer_pct'        => $offerPct,
            'offer'            => $offerRaw,
            'include_in_print' => $saved ? (bool)(int)($saved['include_in_print'] ?? 1) : true,
            'sort_order'       => $sortIdx,
        ];
        $sortIdx++;
    }
}

echo json_encode([
    'submission' => [
        'id'           => (int)$submission['id'],
        'owner_name'   => $submission['owner_name']        ?? '',
        'owner_number' => $submission['owner_number']      ?? '',
        'location'     => $submission['village_city_town'] ?? '',
        'zone'         => ($submission['dir_code'] ?? '') . ' › ' . ($submission['zone_code'] ?? ''),
    ],
    'estimate_id'      => $estimateId,
    'created_by_name'  => $estimateRow['created_by_name'] ?? '',
    'areas'            => $areas,
    'rooms'            => $roomRows,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
