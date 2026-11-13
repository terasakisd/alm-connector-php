<?php
/**
 * ALM コネクタ PHP版 — APIエンドポイント
 *
 * 順位管理ツールから次の形で呼ばれる（WordPress 版と同じルート・同じ応答形）:
 *   GET/POST  /alm/api.php?rest_route=/alm/v1/<route>
 *
 * 認証: リクエストヘッダ X-ALM-Token が config.php の token と一致すること（HTTPS必須）。
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function alm_json($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function alm_error(string $code, string $message, int $status): void {
    alm_json(['code' => $code, 'message' => $message, 'data' => ['status' => $status]], $status);
}

/* ---------- 認証 ---------- */

$token = alm_config()['token'];
$given = $_SERVER['HTTP_X_ALM_TOKEN'] ?? '';
if (!is_string($token) || $token === '' ) {
    alm_error('alm_no_token', 'コネクタのトークンが未設定です（alm/config.php を設置してください）', 500);
}
if (!is_string($given) || !hash_equals($token, $given)) {
    alm_error('alm_forbidden', 'トークンが一致しません', 403);
}

/* ---------- ルーティング ---------- */

$route = '';
if (isset($_GET['rest_route'])) {
    $route = preg_replace('#^/alm/v1/#', '', '/' . ltrim((string) $_GET['rest_route'], '/'));
    $route = trim($route, '/');
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) $body = [];
}

function alm_param(array $body, string $key) {
    return $body[$key] ?? ($_GET[$key] ?? null);
}

/** url パラメータから LP を特定（必須） */
function alm_require_lp(array $body): array {
    $url = trim((string) alm_param($body, 'url'));
    if ($url === '') alm_error('alm_bad_request', 'url が必要です', 400);
    $lp = alm_resolve_lp($url);
    if (!$lp) alm_error('alm_not_found', 'URLからLPを特定できませんでした', 404);
    return $lp;
}

function alm_lp_title(string $html, string $key): string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($m[1])));
        if ($t !== '') return $t;
    }
    return $key;
}

/** 案件の brand を解決（name/brand どちらでも） */
function alm_campaign_matches(array $c, string $name, string $brand): bool {
    if ($name !== '' && strcasecmp((string) ($c['name'] ?? ''), $name) === 0) return true;
    if ($brand !== '' && strcasecmp((string) ($c['brand'] ?? ''), $brand) === 0) return true;
    return false;
}

/* ---------- 各ルート ---------- */

$lp    = alm_require_lp($body);
$key   = $lp['key'];
$state = alm_state_load();
$lpState = (array) ($state[$key] ?? []);
$postId  = alm_post_id($key);
$html    = alm_lp_html($lp['file']);
$permalink = (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST']) : '') . rtrim($key, '/') . '/index.php';

// 案件はLPのブランドから自動補完（取得系・更新系の双方で常に揃える）
$lpState = alm_ensure_campaigns($lpState, alm_detect_brands($html));

switch ($method . ' ' . $route) {

    case 'GET ranking': {
        $units = alm_find_units($html);
        $items = [];
        $seen  = [];
        foreach ($units as $u) {
            if (!$u['is_source']) continue;
            if ($u['key'] === '' || isset($seen[$u['key']])) continue;
            $seen[$u['key']] = true;
            $items[] = [
                'key'      => $u['key'],
                'brand'    => $u['brand'],
                'id'       => $u['key'],
                'heading'  => $u['heading'],
                'has_link' => $u['brand'] !== '',
            ];
        }
        $hidden = array_map('strval', (array) ($lpState['hidden'] ?? []));
        $hiddenSet = array_fill_keys(array_map('strtolower', $hidden), true);
        foreach ($items as &$it) $it['hidden'] = isset($hiddenSet[$it['key']]);
        unset($it);

        $saved = (array) ($lpState['order'] ?? []);
        $hasSaved = !empty($saved);
        $visible  = array_values(array_filter($items, function ($it) { return empty($it['hidden']); }));
        $excluded = array_values(array_filter($items, function ($it) { return !empty($it['hidden']); }));
        if ($hasSaved) {
            $rank = array_flip($saved);
            usort($visible, function ($a, $b) use ($rank) {
                $ra = $rank[$a['key']] ?? PHP_INT_MAX;
                $rb = $rank[$b['key']] ?? PHP_INT_MAX;
                return $ra <=> $rb;
            });
        }
        alm_json([
            'post_id' => $postId,
            'title'   => alm_lp_title($html, $key),
            'items'   => array_merge($visible, $excluded),
            'saved'   => $hasSaved,
        ]);
    }

    case 'POST ranking': {
        $order = alm_param($body, 'order');
        if (!is_array($order)) alm_error('alm_bad_request', 'order（配列）が必要です', 400);
        $clean = [];
        foreach ($order as $k) { $k = strtolower(trim((string) $k)); if ($k !== '') $clean[] = $k; }
        $lpState['order'] = $clean;

        $hidden = alm_param($body, 'hidden');
        $cleanHidden = [];
        if (is_array($hidden)) {
            foreach ($hidden as $k) { $k = strtolower(trim((string) $k)); if ($k !== '') $cleanHidden[] = $k; }
        }
        $lpState['hidden'] = $cleanHidden;

        $state[$key] = $lpState;
        if (!alm_state_save($state)) alm_error('alm_save_failed', '状態の保存に失敗しました（alm/data の書き込み権限を確認）', 500);
        alm_json(['post_id' => $postId, 'order' => $clean, 'hidden' => $cleanHidden]);
    }

    case 'GET article': {
        $out = [];
        foreach ((array) $lpState['campaigns'] as $i => $c) {
            $out[] = [
                'index'    => $i,
                'name'     => (string) ($c['name'] ?? ''),
                'disabled' => !empty($c['disabled']),
                'bts'      => [],
                'brands'   => !empty($c['brand']) ? [strtolower((string) $c['brand'])] : [],
            ];
        }
        alm_json([
            'post_id'    => $postId,
            'permalink'  => $permalink,
            'title'      => alm_lp_title($html, $key),
            'campaigns'  => $out,
            'bt_map_set' => false,
        ]);
    }

    case 'POST comment-out': {
        $name     = trim((string) alm_param($body, 'name'));
        $brand    = trim((string) alm_param($body, 'brand'));
        $disabled = (bool) alm_param($body, 'disabled');
        if ($name === '' && $brand === '') alm_error('alm_bad_request', 'name か brand のどちらかは必須です', 400);
        $hit = 0;
        foreach ($lpState['campaigns'] as &$c) {
            if (alm_campaign_matches($c, $name, $brand)) { $c['disabled'] = $disabled; $hit++; }
        }
        unset($c);
        if ($hit === 0) alm_error('alm_no_campaign', '案件「' . ($name !== '' ? $name : $brand) . '」が見つかりません', 404);
        $state[$key] = $lpState;
        if (!alm_state_save($state)) alm_error('alm_save_failed', '状態の保存に失敗しました', 500);
        alm_json(['post_id' => $postId, 'matched' => $name !== '' ? "name:{$name}" : "brand:{$brand}", 'disabled' => $disabled, 'updated' => $hit]);
    }

    case 'POST article/sync': {
        $items = alm_param($body, 'items');
        if (!is_array($items)) alm_error('alm_bad_request', 'items は配列で渡してください', 400);
        $applied = [];
        $unmatched = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name  = trim((string) ($it['name'] ?? ''));
            $brand = trim((string) ($it['brand'] ?? ''));
            $disabled = !empty($it['disabled']);
            if ($name === '' && $brand === '') continue;
            $hit = 0;
            foreach ($lpState['campaigns'] as &$c) {
                if (alm_campaign_matches($c, $name, $brand)) { $c['disabled'] = $disabled; $hit++; }
            }
            unset($c);
            $label = $name !== '' ? $name : $brand;
            if ($hit > 0) $applied[] = ['label' => $label, 'disabled' => $disabled, 'updated' => $hit];
            else $unmatched[] = $label;
        }
        $state[$key] = $lpState;
        if (!alm_state_save($state)) alm_error('alm_save_failed', '状態の保存に失敗しました', 500);
        alm_json(['post_id' => $postId, 'applied' => $applied, 'unmatched' => $unmatched]);
    }

    case 'GET campaigns': {
        $out = [];
        foreach ((array) $lpState['campaigns'] as $c) {
            $out[] = [
                'name'     => (string) ($c['name'] ?? ''),
                'disabled' => !empty($c['disabled']),
                'values'   => array_values((array) ($c['values'] ?? [])),
            ];
        }
        alm_json([
            'post_id'   => $postId,
            'title'     => alm_lp_title($html, $key),
            'permalink' => $permalink,
            'match'     => 'keyword',
            'bt_map'    => (object) [],
            'campaigns' => $out,
        ]);
    }

    case 'POST campaigns': {
        $raw = alm_param($body, 'campaigns');
        if (!is_array($raw)) alm_error('alm_bad_request', 'campaigns は配列で渡してください', 400);
        $clean = [];
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            $values = [];
            foreach ((array) ($c['values'] ?? []) as $v) {
                if (!is_array($v)) continue;
                $values[] = [
                    'bt'               => trim((string) ($v['bt'] ?? '')),
                    'replacement'      => (string) ($v['replacement'] ?? ''),
                    'parent'           => !empty($v['parent']),
                    'parent_selectors' => array_values(array_filter(array_map('strval', (array) ($v['parent_selectors'] ?? [])))),
                ];
            }
            // brand はキーワード（○○.html）から復元
            $brand = '';
            foreach ($values as $v) {
                if (preg_match('/([a-z0-9_\-]+)\.html/i', $v['bt'], $m)) { $brand = strtolower($m[1]); break; }
            }
            $clean[] = [
                'name'     => trim((string) ($c['name'] ?? '')),
                'brand'    => $brand,
                'disabled' => !empty($c['disabled']),
                'values'   => $values,
            ];
        }
        $lpState['campaigns'] = $clean;
        $state[$key] = $lpState;
        if (!alm_state_save($state)) alm_error('alm_save_failed', '状態の保存に失敗しました', 500);
        alm_json(['post_id' => $postId, 'campaigns' => array_map(function ($c) {
            return ['name' => $c['name'], 'disabled' => $c['disabled'], 'values' => $c['values']];
        }, $clean)]);
    }

    case 'POST campaign/delete': {
        $name  = trim((string) alm_param($body, 'name'));
        $brand = trim((string) alm_param($body, 'brand'));
        if ($name === '' && $brand === '') alm_error('alm_bad_request', 'name か brand のどちらかは必須です', 400);
        $before = count($lpState['campaigns']);
        $lpState['campaigns'] = array_values(array_filter($lpState['campaigns'], function ($c) use ($name, $brand) {
            return !alm_campaign_matches($c, $name, $brand);
        }));
        $removed = $before - count($lpState['campaigns']);
        if ($removed === 0) alm_error('alm_no_campaign', '案件が見つかりません', 404);
        $state[$key] = $lpState;
        if (!alm_state_save($state)) alm_error('alm_save_failed', '状態の保存に失敗しました', 500);
        alm_json(['post_id' => $postId, 'removed' => $removed, 'remaining' => count($lpState['campaigns'])]);
    }

    case 'GET scan-links': {
        $links = [];
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $a[1], $hm)) continue;
                $href = $hm[1];
                if (!preg_match(ALM_BRAND_RE, $href)) continue; // 広告リンクだけ
                $id = '';
                if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $a[1], $im)) $id = $im[1];
                $links[] = [
                    'href' => $href,
                    'id'   => $id,
                    'text' => alm_strip_tags_text($a[2]),
                    'host' => (string) (parse_url($href, PHP_URL_HOST) ?: ''),
                ];
            }
        }
        alm_json(['links' => $links, 'permalink' => $permalink, 'slug' => trim($key, '/')]);
    }

    default:
        alm_error('alm_no_route', 'ルートが見つかりません: ' . $method . ' /' . $route, 404);
}
