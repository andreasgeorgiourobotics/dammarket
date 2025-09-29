<?php
/**
 * my-child — DAM Market series loader (XLSX-first with JSON fallback)
 *
 * REST:
 *   /wp-json/my/v1/series?date=YYYY-MM-DD&source=xlsx|json|auto
 *
 * XLSX:
 *   Dir  : wp-content/uploads/power-xlsx
 *   Rows : begin at Excel row 4 (skip headers at rows 1–3)
 *   Cols : [0]=Time, [1]=Category (keep ONLY "ALL"), [2]=Price, [4]=Volume
 *
 * JSON (fallback):
 *   Dir  : wp-content/uploads/power-json
 *   File : YYYYMMDD.json
 *   Shape: [ { Time, Price, Volume }, ... ]  OR { "rows": [ ... ] }
 *
 * Output (both sources):
 *   {
 *     "date": "YYYY-MM-DD",
 *     "labels": ["HH:MM", ...],
 *     "PRICE":  [ ... VWAP € ],
 *     "VOLUME": [ ... MWh ],
 *     "source": "xlsx|json",
 *     "file":   "basefile.ext"
 *   }
 */

/* =========================
 * CONFIG
 * =======================*/
if (!defined('MY_SERIES_PAGE_SLUG')) {
    define('MY_SERIES_PAGE_SLUG', 'dam-market');  // the page that renders the chart
}
if (!defined('MY_XLSX_DIR')) {
    define('MY_XLSX_DIR', WP_CONTENT_DIR . '/uploads/power-xlsx'); // your XLSX drop folder
}
if (!defined('MY_JSON_DIR')) {
    define('MY_JSON_DIR', WP_CONTENT_DIR . '/uploads/power-json'); // optional JSON fallback
}
if (!defined('MY_SERIES_SOURCE_DEFAULT')) {
    define('MY_SERIES_SOURCE_DEFAULT', 'auto'); // xlsx|json|auto   (auto tries XLSX then JSON)
}

/* Prefer cURL for wp_remote_* (harmless) */
add_filter('use_curl_transport', '__return_true');

/* =========================
 * HELPERS (shared)
 * =======================*/

/** Safe numeric cast; returns null when not numeric and $def is null */
function my_num($v, $def = 0.0) {
    if ($v === null || $v === '') return $def;
    $s = str_replace(',', '.', (string)$v);
    if (is_numeric($s)) return (float)$s;
    return $def;
}

/** Normalize to half-hour label "HH:MM", snapping to :00 or :30. Supports 1..48, HH:MM, generic date/time. */
function my_to_hhmm_0030($val, int $slotIndex): string {
    // 1..48 index support
    if ($val !== null && preg_match('/^\d{1,2}$/', (string)$val)) {
        $idx = max(0, min(47, (int)$val - 1));
        $h = intdiv($idx, 2);
        $m = $idx % 2 ? 30 : 0;
        return sprintf('%02d:%02d', $h, $m);
    }

    // HH:MM parsing with snap
    if ($val && preg_match('/\b(\d{1,2}):(\d{2})\b/u', (string)$val, $m)) {
        $h = max(0, min(23, (int)$m[1]));
        $mmO = (int)$m[2];
        $mm  = ($mmO < 15) ? 0 : (($mmO < 45) ? 30 : 0);
        if ($mm === 0 && $mmO >= 45) $h = ($h + 1) % 24;
        return sprintf('%02d:%02d', $h, $mm);
    }

    // Any datetime string → UTC snap
    if ($val) {
        $ts = strtotime((string)$val);
        if ($ts !== false) {
            $h = (int)gmdate('H', $ts);
            $mmO = (int)gmdate('i', $ts);
            $mm  = ($mmO < 15) ? 0 : (($mmO < 45) ? 30 : 0);
            if ($mm === 0 && $mmO >= 45) $h = ($h + 1) % 24;
            return sprintf('%02d:%02d', $h, $mm);
        }
    }

    // Fallback from slot index
    $h = intdiv($slotIndex, 2);
    $m = $slotIndex % 2 ? 30 : 0;
    return sprintf('%02d:%02d', $h, $m);
}

/* =========================
 * JSON SOURCE
 * =======================*/

/** Resolve YYYY-MM-DD -> absolute /uploads/power-json/YYYYMMDD.json */
function my_find_json_for_date(string $date): ?string {
    $ymd  = preg_replace('/[^0-9]/', '', $date); // YYYYMMDD
    $file = trailingslashit(MY_JSON_DIR) . "{$ymd}.json";
    return is_file($file) ? $file : null;
}

/** Read & normalize JSON: {rows:[{Time,Price,Volume},...]} OR plain array */
function my_read_series_from_json(string $date) {
    if (!is_dir(MY_JSON_DIR)) {
        return new \WP_Error('no_json_dir', 'JSON directory not found: ' . MY_JSON_DIR, ['status' => 500]);
    }

    $file = my_find_json_for_date($date);
    if (!$file) {
        return new \WP_Error('json_no_file', "No JSON file for {$date}", ['status' => 404]);
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return new \WP_Error('json_read_error', 'Failed to read JSON file', ['status' => 500, 'file' => basename($file)]);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return new \WP_Error('json_error', 'Invalid JSON', ['status' => 500, 'file' => basename($file)]);
    }

    $rows = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : $json;
    if (!is_array($rows) || !count($rows)) {
        return new \WP_Error('json_empty_rows', 'JSON contains no rows', ['status' => 500, 'file' => basename($file)]);
    }

    // Aggregate by HH:MM
    $agg  = []; // label => ['vsum'=>..., 'pv'=>...]
    $slot = 0;

    foreach ($rows as $r) {
        if (!is_array($r)) { $slot++; continue; }
        $km = [];
        foreach ($r as $k => $v) $km[strtolower(trim((string)$k))] = $v;

        $label = my_to_hhmm_0030($km['time'] ?? null, $slot);
        $p     = my_num($km['price']  ?? null, null);
        $v     = my_num($km['volume'] ?? null, null);

        if ($v === null) { $slot++; continue; } // require volume

        if (!isset($agg[$label])) $agg[$label] = ['vsum'=>0.0, 'pv'=>0.0];
        $agg[$label]['vsum'] += (float)$v;
        if ($p !== null) $agg[$label]['pv'] += (float)$p * (float)$v;

        $slot++;
    }

    if (!$agg) return new \WP_Error('json_no_points', 'No usable rows', ['status' => 500]);

    ksort($agg, SORT_NATURAL);
    $labels = array_keys($agg);
    $VOLUME = [];
    $PRICE  = [];
    foreach ($labels as $lab) {
        $vsum = $agg[$lab]['vsum'];
        $pv   = $agg[$lab]['pv'];
        $VOLUME[] = round($vsum, 2);
        $PRICE[]  = $vsum > 0 ? round($pv / $vsum, 2) : 0.0; // VWAP
    }

    return [
        'date'   => $date,
        'labels' => $labels,
        'PRICE'  => $PRICE,
        'VOLUME' => $VOLUME,
        'source' => 'json',
        'file'   => basename($file),
    ];
}

/* =========================
 * XLSX SOURCE (your layout)
 * =======================*/

/** Find an XLSX for a given date; we match on YYYYMMDD inside the filename OR exact YYYY-MM-DD.xlsx */
function my_find_xlsx_for_date(string $date): ?string {
    if (!is_dir(MY_XLSX_DIR)) return null;

    $ymd  = preg_replace('/[^0-9]/', '', $date); // YYYYMMDD
    $dir  = trailingslashit(MY_XLSX_DIR);

    // 1) any file containing YYYYMMDD and ending with .xlsx
    foreach (glob($dir . '*'. $ymd . '*.xlsx') as $f) {
        if (is_file($f)) return $f;
    }
    // 2) exact fallback: YYYY-MM-DD.xlsx
    $explicit = $dir . $date . '.xlsx';
    if (is_file($explicit)) return $explicit;

    return null;
}

/** Extract "HH:MM" from the XLSX Time column (first HH:MM found), snapped; fallback by slot index */
function my_time_label_from_col1($val, int $slotIndex = 0): string {
    $s = trim((string)$val);
    if (preg_match('/\b(\d{1,2}):(\d{2})\b/u', $s, $m)) {
        $h   = max(0, min(23, (int)$m[1]));
        $mmO = (int)$m[2];
        $mm  = ($mmO < 15) ? 0 : (($mmO < 45) ? 30 : 0);
        if ($mm === 0 && $mmO >= 45) $h = ($h + 1) % 24;
        return sprintf('%02d:%02d', $h, $mm);
    }
    // fallback
    $hh = (int) floor($slotIndex / 2);
    $mm = ($slotIndex % 2 === 0) ? 0 : 30;
    return sprintf('%02d:%02d', $hh, $mm);
}

/** Read & normalize XLSX with strict positions and row offset */
function my_read_series_from_xlsx(string $date) {
    // Ensure library is loaded (place SimpleXLSX.php in your theme: /inc/SimpleXLSX.php)
    if (!class_exists('\Shuchkin\SimpleXLSX')) {
        $lib = get_stylesheet_directory() . '/inc/SimpleXLSX.php';
        if (file_exists($lib)) {
            require_once $lib;
        }
    }
    if (!class_exists('\Shuchkin\SimpleXLSX')) {
        return new \WP_Error('xlsx_missing', 'SimpleXLSX library not loaded (put inc/SimpleXLSX.php in theme).', ['status' => 500]);
    }
    if (!is_dir(MY_XLSX_DIR)) {
        return new \WP_Error('no_xlsx_dir', 'XLSX directory not found: ' . MY_XLSX_DIR, ['status' => 500]);
    }

    $file = my_find_xlsx_for_date($date);
    if (!$file) {
        return new \WP_Error('xlsx_no_file', "No XLSX file for {$date}", ['status' => 404]);
    }

    $xlsx = \Shuchkin\SimpleXLSX::parse($file);
    if (!$xlsx) {
        return new \WP_Error('xlsx_error', \Shuchkin\SimpleXLSX::parseError() ?: 'Failed to parse XLSX', ['status' => 500, 'file' => basename($file)]);
    }

    // First worksheet
    $rows = $xlsx->rows(0);
    if (!$rows || !is_array($rows) || !count($rows)) {
        return new \WP_Error('xlsx_empty', 'No rows in XLSX', ['status' => 500, 'file' => basename($file)]);
    }

    // Excel row 4 → 0-based index 3
    $startIndex = 3;

    // Column indices
    $COL_TIME   = 0;
    $COL_CAT    = 1;
    $COL_PRICE  = 2;
    $COL_VOLUME = 4;

    $agg  = []; // label => ['vsum'=>..., 'pv'=>...]
    $slot = 0;

    for ($i = $startIndex; $i < count($rows); $i++) {
        $r = $rows[$i];

        // Skip completely empty rows
        if (!is_array($r) || !count(array_filter($r, fn($x) => (string)$x !== ''))) {
            $slot++;
            continue;
        }

        $catRaw = $r[$COL_CAT] ?? '';
        $cat    = mb_strtoupper(trim((string)$catRaw), 'UTF-8');
        if ($cat !== 'ALL') { // keep ONLY ALL
            $slot++;
            continue;
        }

        $label  = my_time_label_from_col1($r[$COL_TIME] ?? '', $slot);
        $price  = my_num($r[$COL_PRICE]  ?? null, null);
        $volume = my_num($r[$COL_VOLUME] ?? null, null);

        if ($volume === null) { // require volume
            $slot++;
            continue;
        }

        if (!isset($agg[$label])) $agg[$label] = ['vsum'=>0.0, 'pv'=>0.0];
        $agg[$label]['vsum'] += (float)$volume;
        if ($price !== null) $agg[$label]['pv'] += (float)$price * (float)$volume;

        $slot++;
    }

    if (!count($agg)) {
        return new \WP_Error('xlsx_no_all_rows', 'No ALL category rows found after row 4.', ['status' => 500, 'file' => basename($file)]);
    }

    ksort($agg, SORT_NATURAL);
    $labels = array_keys($agg);
    $VOLUME = [];
    $PRICE  = [];
    foreach ($labels as $lab) {
        $vsum = $agg[$lab]['vsum'];
        $pv   = $agg[$lab]['pv'];
        $VOLUME[] = round($vsum, 2);
        $PRICE[]  = $vsum > 0 ? round($pv / $vsum, 2) : 0.0; // VWAP
    }

    return [
        'date'   => $date,
        'labels' => $labels,
        'PRICE'  => $PRICE,
        'VOLUME' => $VOLUME,
        'source' => 'xlsx',
        'file'   => basename($file),
    ];
}

/* =========================
 * REST: /my/v1/series
 * =======================*/
add_action('rest_api_init', function () {
    register_rest_route('my/v1', '/series', [
        'methods'             => 'GET',
        'callback'            => 'my_rest_series_handler',
        'permission_callback' => '__return_true',
        'args' => [
            'date' => [
                'required' => false,
                'validate_callback' => static function ($p) {
                    return !$p || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p);
                },
            ],
            'source' => [
                'required' => false,
                'validate_callback' => static function ($p) {
                    return !$p || in_array(strtolower((string)$p), ['auto','xlsx','json'], true);
                },
            ],
        ],
    ]);
});

/** Main handler (XLSX-first by default) */
function my_rest_series_handler(\WP_REST_Request $req) {
    $date   = sanitize_text_field($req->get_param('date')) ?: gmdate('Y-m-d');
    $source = strtolower(sanitize_text_field($req->get_param('source') ?: MY_SERIES_SOURCE_DEFAULT));

    // Cache per date+source (60s)
    $cache_key = 'my_series_' . md5($date . '|' . $source);
    if ($cached = get_transient($cache_key)) return $cached;

    $try_xlsx = function() use ($date) {
        return my_read_series_from_xlsx($date);
    };
    $try_json = function() use ($date) {
        return my_read_series_from_json($date);
    };

    if ($source === 'xlsx') {
        $res = $try_xlsx();
        if (is_wp_error($res)) return $res;
    } elseif ($source === 'json') {
        $res = $try_json();
        if (is_wp_error($res)) return $res;
    } else { // auto → XLSX first, then JSON
        $res = $try_xlsx();
        if (is_wp_error($res)) {
            // only fall back when XLSX is truly absent
            if ($res->get_error_code() === 'xlsx_no_file' || $res->get_error_code() === 'no_xlsx_dir') {
                $res = $try_json();
                if (is_wp_error($res)) return $res;
            } else {
                return $res;
            }
        }
    }

    set_transient($cache_key, $res, 60);
    return $res;
}

/* =========================
 * ENQUEUE (dam-market page)
 * =======================*/
add_action('wp_enqueue_scripts', function () {
    // theme styles
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css', [], null);
    $child_style_path = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style(
        'child-style',
        get_stylesheet_uri(),
        ['parent-style'],
        file_exists($child_style_path) ? filemtime($child_style_path) : null
    );

    if (!is_page(MY_SERIES_PAGE_SLUG)) return;

    // Chart.js
    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        [],
        '4.4.1',
        true
    );

    // Page JS
    $rel_js = '/assets/dam-market-page.js';
    $abs_js = get_stylesheet_directory() . $rel_js;
    $url_js = get_stylesheet_directory_uri() . $rel_js;
    wp_enqueue_script('dam-market-page', $url_js, ['chartjs'], file_exists($abs_js) ? filemtime($abs_js) : null, true);

    // Optional page CSS
    $rel_css = '/assets/dam-market-page.css';
    $abs_css = get_stylesheet_directory() . $rel_css;
    if (file_exists($abs_css)) {
        wp_enqueue_style('dam-market-page', get_stylesheet_directory_uri() . $rel_css, ['child-style'], filemtime($abs_css));
    }

    // Inject REST endpoint + hints for both sources
    $jsonHint = trailingslashit(str_replace(ABSPATH, '/', MY_JSON_DIR)) . 'YYYYMMDD.json';
    $xlsxHint = trailingslashit(str_replace(ABSPATH, '/', MY_XLSX_DIR)) . '*YYYYMMDD*.xlsx';

    wp_localize_script('dam-market-page', 'PowerAPI', [
        'endpoint' => esc_url_raw(rest_url('my/v1/series')),
        'jsonHint' => $jsonHint,
        'xlsxHint' => $xlsxHint,
        'defaultSource' => MY_SERIES_SOURCE_DEFAULT,
    ]);
});
