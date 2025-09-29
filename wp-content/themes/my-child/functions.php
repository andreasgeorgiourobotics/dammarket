<?php
/**
 * my-child — JSON-based series loader (Time, Price, Volume)
 *
 * REST:   /wp-json/my/v1/series?date=YYYY-MM-DD
 * Files:  wp-content/uploads/power-json/YYYYMMDD.json
 * Output: { date, labels["HH:MM"...], PRICE[...], VOLUME[...], source }
 */

/* =========================
 * CONFIG
 * =======================*/
if (!defined('MY_SERIES_PAGE_SLUG')) {
    define('MY_SERIES_PAGE_SLUG', 'dam-market');
}
if (!defined('MY_JSON_DIR')) {
    define('MY_JSON_DIR', WP_CONTENT_DIR . '/uploads/power-json');
}

/* Prefer cURL for wp_remote_* (harmless to keep) */
add_filter('use_curl_transport', '__return_true');

/* =========================
 * HELPERS
 * =======================*/

/** Resolve YYYY-MM-DD -> absolute /uploads/power-json/YYYYMMDD.json */
function my_find_json_for_date(string $date): ?string {
    $ymd = preg_replace('/[^0-9]/', '', $date); // YYYYMMDD
    $file = trailingslashit(MY_JSON_DIR) . "{$ymd}.json";
    return is_file($file) ? $file : null;
}

/** Safe numeric cast; returns null when not numeric and $def is null */
function my_num($v, $def = 0.0) {
    if ($v === null || $v === '') return $def;
    $s = str_replace(',', '.', (string)$v);
    if (is_numeric($s)) return (float)$s;
    return $def;
}

/** Normalize to 48 half-hour slots labels "HH:MM", snapping to :00/:30 */
function my_to_hhmm_0030($val, int $slotIndex): string {
    // 1..48 index support
    if ($val !== null && preg_match('/^\d{1,2}$/', (string)$val)) {
        $idx = max(0, min(47, (int)$val - 1));
        $h = intdiv($idx, 2);
        $m = $idx % 2 ? 30 : 0;
        return sprintf('%02d:%02d', $h, $m);
    }

    // HH:MM parsing with snap
    if ($val && preg_match('/\b(\d{1,2}):(\d{2})\b/', (string)$val, $m)) {
        $h = max(0, min(23, (int)$m[1]));
        $mmO = (int)$m[2];
        $mm  = ($mmO < 15) ? 0 : (($mmO < 45) ? 30 : 0);
        if ($mm === 0 && $mmO >= 45) $h = ($h + 1) % 24;
        return sprintf('%02d:%02d', $h, $mm);
    }

    // Generic date/time string -> snap in UTC
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

    // Fallback: derive from slot index
    $h = intdiv($slotIndex, 2);
    $m = $slotIndex % 2 ? 30 : 0;
    return sprintf('%02d:%02d', $h, $m);
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
        ],
    ]);
});

/**
 * Handler: read JSON of rows { Time, Price, Volume } (or { "rows": [...] })
 * Returns combined series for ALL (VWAP by slot).
 */
function my_rest_series_handler(\WP_REST_Request $req) {
    $date = sanitize_text_field($req->get_param('date')) ?: gmdate('Y-m-d');

    // fast cache per date (60s)
    $cache_key = 'my_series_json_' . md5($date);
    $cached = get_transient($cache_key);
    if ($cached) return $cached;

    if (!is_dir(MY_JSON_DIR)) {
        return new \WP_Error('no_dir', 'Data directory not found', ['status' => 500]);
    }

    $file = my_find_json_for_date($date);
    if (!$file) {
        return new \WP_Error('no_file', 'Not found', ['status' => 404]);
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return new \WP_Error('read_error', 'Failed to read JSON', ['status' => 500]);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return new \WP_Error('json_error', 'Invalid JSON', ['status' => 500]);
    }

    // Accept: [ {Time, Price, Volume}, ... ]  OR  { rows: [...] }
    $rows = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : $json;
    if (!is_array($rows) || !count($rows)) {
        return new \WP_Error('empty_rows', 'Empty', ['status' => 500]);
    }

    // Aggregate by HH:MM -> sum volume; VWAP = sum(p*v)/sum(v)
    $agg = [];  // label => ['vsum'=>float, 'pv'=>float]
    $slot = 0;

    foreach ($rows as $r) {
        if (!is_array($r)) { $slot++; continue; }
        // case-insensitive keys
        $km = [];
        foreach ($r as $k => $v) $km[strtolower(trim((string)$k))] = $v;

        $label = my_to_hhmm_0030($km['time'] ?? null, $slot);
        $p     = my_num($km['price']  ?? null, null);   // allow null
        $v     = my_num($km['volume'] ?? null, null);   // allow null

        if ($v === null) { $slot++; continue; } // require volume to include slot

        if (!isset($agg[$label])) $agg[$label] = ['vsum' => 0.0, 'pv' => 0.0];
        $agg[$label]['vsum'] += (float)$v;
        if ($p !== null) $agg[$label]['pv'] += (float)$p * (float)$v;

        $slot++;
    }

    if (!$agg) {
        return new \WP_Error('no_points', 'No usable rows', ['status' => 500]);
    }

    ksort($agg, SORT_NATURAL); // order by HH:MM

    $labels = array_keys($agg);
    $VOLUME = [];
    $PRICE  = [];
    foreach ($labels as $lab) {
        $vsum = $agg[$lab]['vsum'];
        $pv   = $agg[$lab]['pv'];
        $VOLUME[] = round($vsum, 2);
        $PRICE[]  = $vsum > 0 ? round($pv / $vsum, 2) : 0.0; // VWAP per slot
    }

    $out = [
        'date'   => $date,
        'labels' => $labels,
        'PRICE'  => $PRICE,
        'VOLUME' => $VOLUME,
        'source' => basename($file),
    ];

    set_transient($cache_key, $out, 60);
    return $out;
}

/* =========================
 * ENQUEUE (About page only)
 * =======================*/
add_action('wp_enqueue_scripts', function () {
    // base styles
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

    // Page CSS
    $rel_css = '/assets/dam-market-page.css';
    $abs_css = get_stylesheet_directory() . $rel_css;
    if (file_exists($abs_css)) {
        wp_enqueue_style('dam-market-page', get_stylesheet_directory_uri() . $rel_css, ['child-style'], filemtime($abs_css));
    }

    // Inject REST endpoint + optional hint
    $hint = trailingslashit(str_replace(ABSPATH, '/', MY_JSON_DIR)) . 'YYYYMMDD.json';
    wp_localize_script('dam-market-page', 'PowerAPI', [
        'endpoint' => esc_url_raw(rest_url('my/v1/series')),
        'jsonHint' => $hint,
    ]);
});
