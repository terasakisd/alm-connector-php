<?php
/**
 * ALM コネクタ PHP版 — 自動テスト
 *
 *   sh tests/run.sh          （docker の php:8.2-cli で実行）
 *   php tests/test.php       （ローカルに php がある場合）
 *
 * tests/.tmp/docroot に「alm/ + 見本LP」を組み立て、
 *   1) lib.php の解析・変換を直接検証
 *   2) php -S で立てたサーバに対して API と配信（render.php）をエンドツーエンドで検証
 */
declare(strict_types=1);

$ROOT    = dirname(__DIR__);
$DOCROOT = $ROOT . '/tests/.tmp/docroot';
$TOKEN   = 'test-token-0123456789abcdef';
$PORT    = (int) (getenv('ALM_TEST_PORT') ?: 8099);

/* ---------- テスト用ヘルパ ---------- */

$pass = 0; $fail = 0; $section = '';
function section(string $name): void { global $section; $section = $name; echo "\n## {$name}\n"; }
function ok($cond, string $name): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$name}\n"; }
    else       { $fail++; echo "  FAIL {$name}\n"; }
}
function eq($actual, $expected, string $name): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   {$name}\n"; return; }
    $fail++;
    echo "  FAIL {$name}\n       expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
       . "\n       actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
/** 正規表現の1番目のグループを出現順に集める */
function ids(string $html, string $re): array { preg_match_all($re, $html, $m); return $m[1]; }
function brands_in(string $html): array { return array_map('strtolower', ids($html, ALM_BRAND_RE)); }
function box_ids(string $html): array { return ids($html, '/<div class="ranking__box[^"]*" id="([^"]+)"/'); }
function thead_of(string $html): string { return preg_match('/<thead>.*?<\/thead>/s', $html, $m) ? $m[0] : ''; }
function tbody_rows(string $html): array {
    if (!preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $m)) return [];
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $m[1], $r);
    return $r[1];
}
function cell_texts(string $rowInner): array {
    preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $rowInner, $m);
    return array_map(function ($c) { return trim(strip_tags($c)); }, $m[1]);
}
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) && !is_link($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}
function copy_tree(string $src, string $dst): void {
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$src/$f") ? copy_tree("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
    }
}
function write_config(string $docroot, string $token, array $extra = []): void {
    $cfg = array_merge(['token' => $token, 'docroot' => $docroot], $extra);
    file_put_contents("$docroot/alm/config.php", "<?php return " . var_export($cfg, true) . ";\n");
}

/* ---------- docroot を組み立てる ---------- */

rrmdir($ROOT . '/tests/.tmp');
copy_tree($ROOT . '/alm', "$DOCROOT/alm");
@unlink("$DOCROOT/alm/config.php");
foreach (glob("$DOCROOT/alm/data/*.php") ?: [] as $f) unlink($f);
copy_tree($ROOT . '/tests/fixtures/docroot', $DOCROOT);
write_config($DOCROOT, $TOKEN);
$DOCROOT = realpath($DOCROOT);

$_SERVER['DOCUMENT_ROOT'] = $DOCROOT;
require "$DOCROOT/alm/lib.php";

/* ====================================================================== */
section('LPの解決（URL → ディレクトリ）');
/* ====================================================================== */

eq(alm_resolve_lp('https://rank-guide.com/shinsa5/index.php')['key'] ?? null, '/shinsa5', 'index.php');
eq(alm_resolve_lp('https://rank-guide.com/shinsa5/')['key'] ?? null, '/shinsa5', '末尾スラッシュ');
eq(alm_resolve_lp('https://rank-guide.com/shinsa5')['key'] ?? null, '/shinsa5', 'ディレクトリ名のみ');
eq(alm_resolve_lp('https://rank-guide.com/shinsa5/search-result.php?q=1')['key'] ?? null, '/shinsa5', '診断結果ページも同じLP');
eq(alm_resolve_lp('https://rank-guide.com/nope/index.php'), null, '存在しないLP → null');
eq(alm_resolve_lp('https://rank-guide.com/alm/lib.php'), null, 'コネクタ自身のファイルはLPとして扱わない');
eq(alm_resolve_lp('https://rank-guide.com/shinsa5/../alm/api.php'), null, 'パストラバーサルで alm/ に到達できない');
eq(alm_resolve_lp('https://rank-guide.com/shinsa5/index.html'), null, 'PHP以外は対象外');

$lp   = alm_resolve_lp('https://rank-guide.com/shinsa5/index.php');
$html = alm_lp_html($lp['file']);
ok(strpos($html, '<title>審査が不安な人向けカードローン比較（テスト用LP）</title>') !== false, '素のHTMLを取得（render.php は無効化される）');
ok(strpos($html, '<?php') === false, '素のHTMLに PHP コードが残らない');

/* ====================================================================== */
section('順位ユニットの検出');
/* ====================================================================== */

$units = alm_find_units($html);
$src   = array_values(array_filter($units, function ($u) { return $u['is_source']; }));
eq(array_column($src, 'key'), ['promise', 'lake', 'mobit'], '順位の元（ranking__box）は3つ・出現順');
eq(array_column($src, 'heading'), ['プロミス', 'レイク', 'SMBCモビット'], '見出しは ranking__box-head-name から');
eq(count($units), 9, 'ユニット総数 = BEST3帯3 + 行3 + 商標3（列テーブル・派生クラスは含まない）');
eq(array_column($units, 'tag'), ['li', 'li', 'li', 'tr', 'tr', 'tr', 'div', 'div', 'div'], 'ユニットのタグ');
$ct = alm_find_column_tables($html);
eq(count($ct), 1, '列型テーブルは1つ');
eq($ct[0]['brands'] ?? null, ['promise', 'lake', 'mobit'], '列型テーブルの列ブランド');
eq(alm_detect_brands($html), ['promise', 'lake', 'mobit'], 'ブランド自動検出');

/* ====================================================================== */
section('並べ替え（order）');
/* ====================================================================== */

$out = alm_transform($html, ['order' => ['lake', 'mobit', 'promise'], 'hidden' => [], 'campaigns' => []]);
eq(box_ids($out), ['lake', 'mobit', 'promise'], '商標コンテンツの順');
eq(ids($out, '/<li class="best3__list-item" id="b3-([^"]+)"/'), ['lake', 'mobit', 'promise'], 'BEST3帯の順');
eq(ids($out, '/<tr id="row-([^"]+)"/'), ['lake', 'mobit', 'promise'], '行型テーブルの順');
eq(brands_in(thead_of($out)), ['lake', 'mobit', 'promise'], '列型テーブル thead の列順');
$rows = tbody_rows($out);
eq(cell_texts($rows[0] ?? ''), ['最短25分', '最短15分', '最短3分'], '列型テーブル tbody も列ごと追従');
ok(strpos($out, '<td colspan="3">colspan行は触らない</td>') !== false, 'colspan 行はそのまま');
eq(ids($out, '/<div class="best3__first" id="pick-([^"]+)"/'), ['lake'], 'ピックアップは1位ブランドのバリアントだけ残る');
eq(substr_count($out, 'best3__first-catch'), 1, 'ピックアップ内側の派生クラス要素（best3__first-catch）は消えない');
eq(substr_count($out, 'best3__first-check-list-item'), 1, 'ピックアップ内側のリストも残る');
ok(strpos($out, 'class="ranking__box-note"') !== false, '派生クラス（ranking__box-note）はユニット扱いしない');
eq(substr_count($out, '<!-- 1位 -->'), 1, '順位コメントは残る');
eq(substr_count($out, 'id="promise"'), 1, '商標ブロックが重複しない');

$out = alm_transform($html, ['order' => ['promise', 'lake', 'mobit'], 'hidden' => [], 'campaigns' => []]);
eq(box_ids($out), ['promise', 'lake', 'mobit'], '元と同じ順でも壊れない');
eq(ids($out, '/<div class="best3__first" id="pick-([^"]+)"/'), ['promise'], '1位=promise のときピックアップは promise');
eq(substr_count($out, 'best3__first-catch'), 1, '1位=promise でもピックアップ内側は消えない（v1.0.0 の不具合）');

$out = alm_transform($html, ['order' => ['zzz'], 'hidden' => [], 'campaigns' => []]);
eq(box_ids($out), ['promise', 'lake', 'mobit'], '未知のキーだけの order → 元の順');
eq(substr_count($out, 'class="best3__first"'), 3, '1位ブランドのピックアップが無ければ全部残す（安全側）');

eq(alm_transform($html, ['order' => [], 'hidden' => [], 'campaigns' => []]), $html, '設定なしなら完全に同一');

/* ====================================================================== */
section('記事から外す（hidden）');
/* ====================================================================== */

$out = alm_transform($html, ['order' => ['lake', 'promise', 'mobit'], 'hidden' => ['mobit'], 'campaigns' => []]);
eq(box_ids($out), ['lake', 'promise'], '商標コンテンツから mobit が消える');
eq(ids($out, '/<li class="best3__list-item" id="b3-([^"]+)"/'), ['lake', 'promise'], 'BEST3帯からも消える');
eq(ids($out, '/<tr id="row-([^"]+)"/'), ['lake', 'promise'], '行型テーブルからも消える');
eq(brands_in(thead_of($out)), ['lake', 'promise'], '列型テーブルの列も落ちる');
$rows = tbody_rows($out);
eq(cell_texts($rows[0] ?? ''), ['最短25分', '最短3分'], 'tbody の列数も揃う');
eq(ids($out, '/<div class="best3__first" id="pick-([^"]+)"/'), ['lake'], 'ピックアップは hidden を除いた1位');
ok(strpos($out, 'id="mobit"') === false, 'mobit ブロック自体が出力に無い');

$out = alm_transform($html, ['order' => [], 'hidden' => ['promise'], 'campaigns' => []]);
eq(box_ids($out), ['lake', 'mobit'], 'order 無しでも hidden だけで除外できる');

/* ====================================================================== */
section('リンク停止（campaigns.disabled）');
/* ====================================================================== */

$promiseAnchors = preg_match_all('/<a\b[^>]*promise\.html/', $html);
$lakeAnchors    = preg_match_all('/<a\b[^>]*lake\.html/', $html);
$camps = [[
    'name' => 'プロミス', 'brand' => 'promise', 'disabled' => true,
    'values' => [['bt' => 'promise.html', 'replacement' => '<掲載終了>', 'parent' => false, 'parent_selectors' => []]],
]];
$out = alm_transform($html, ['order' => [], 'hidden' => [], 'campaigns' => $camps]);
eq(preg_match_all('/<a\b[^>]*promise\.html/', $out), 0, 'promise のアンカーが全部消える');
eq(substr_count($out, '<!-- alm:off -->'), $promiseAnchors, '消した数だけ alm:off マーカーが入る');
eq(preg_match_all('/<a\b[^>]*lake\.html/', $out), $lakeAnchors, '他ブランドのアンカーは残る');
ok(strpos($out, '&lt;掲載終了&gt;') !== false, '置換テキストは HTML エスケープされる');
eq(box_ids($out), ['promise', 'lake', 'mobit'], 'リンク停止だけならブロックの並びは変わらない');

$camps[0]['disabled'] = false;
eq(alm_transform($html, ['order' => [], 'hidden' => [], 'campaigns' => $camps]), $html, 'disabled=false なら何もしない');

/* ====================================================================== */
section('状態ファイル');
/* ====================================================================== */

ok(alm_state_save(['/shinsa5' => ['order' => ['lake'], 'hidden' => []]]), '保存できる');
eq(alm_state_load(), ['/shinsa5' => ['order' => ['lake'], 'hidden' => []]], '読み戻せる');
ok(strncmp((string) file_get_contents(alm_state_file()), '<?php exit;', 11) === 0, 'PHPガード付き（直接アクセスで中身が出ない）');
alm_state_save([]);

/* ====================================================================== */
section('API + 配信（php -S）');
/* ====================================================================== */

$server = proc_open(
    ['php', '-S', "127.0.0.1:{$PORT}", '-t', $DOCROOT],
    [0 => ['pipe', 'r'], 1 => ['file', $ROOT . '/tests/.tmp/server.log', 'a'], 2 => ['file', $ROOT . '/tests/.tmp/server.log', 'a']],
    $pipes
);
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $PORT, $en, $es, 0.2);
    if ($s) { fclose($s); $ready = true; break; }
    usleep(100000);
}
if (!$ready) { echo "  FAIL php -S が起動しませんでした\n"; exit(1); }

$BASE = "http://127.0.0.1:{$PORT}";
$LPURL = "{$BASE}/shinsa5/index.php";

/** HTTP リクエスト。戻り値: [status, body(string), json|null] */
function http(string $method, string $url, ?array $json = null, array $headers = []): array {
    $h = $headers;
    $ctx = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10]];
    if ($json !== null) { $h[] = 'Content-Type: application/json'; $ctx['http']['content'] = json_encode($json, JSON_UNESCAPED_UNICODE); }
    $ctx['http']['header'] = implode("\r\n", $h);
    $body = @file_get_contents($url, false, stream_context_create($ctx));
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
    }
    $decoded = json_decode((string) $body, true);
    return [$status, (string) $body, is_array($decoded) ? $decoded : null];
}
function api(string $method, string $route, array $query = [], ?array $json = null, ?string $token = null): array {
    global $BASE, $TOKEN;
    $url = "{$BASE}/alm/api.php?rest_route=/alm/v1/{$route}" . ($query ? '&' . http_build_query($query) : '');
    $headers = ['X-ALM-Token: ' . ($token ?? $TOKEN)];
    return http($method, $url, $json, $headers);
}
function page(string $path): string { global $BASE; return http('GET', $BASE . $path)[1]; }

// 認証・ルーティング
[$st, , $js] = api('GET', 'ranking', ['url' => $LPURL], null, '');
eq([$st, $js['code'] ?? null], [403, 'alm_forbidden'], 'トークン無し → 403');
[$st, , $js] = api('GET', 'ranking', ['url' => $LPURL], null, 'wrong');
eq([$st, $js['code'] ?? null], [403, 'alm_forbidden'], 'トークン不一致 → 403');
[$st, , $js] = api('GET', 'ranking');
eq([$st, $js['code'] ?? null], [400, 'alm_bad_request'], 'url 無し → 400');
[$st, , $js] = api('GET', 'ranking', ['url' => "{$BASE}/nope/index.php"]);
eq([$st, $js['code'] ?? null], [404, 'alm_not_found'], '存在しないLP → 404 alm_not_found');
[$st, , $js] = api('GET', 'unknown-route', ['url' => $LPURL]);
eq([$st, $js['code'] ?? null], [404, 'alm_no_route'], '未知のルート → 404 alm_no_route');
[$st, $body] = http('GET', "{$BASE}/alm/data/state.php");
ok($st === 404 || trim($body) === '', '状態ファイルへ直接アクセスしても中身は出ない');

// GET ranking（初期）
[$st, , $js] = api('GET', 'ranking', ['url' => $LPURL]);
eq($st, 200, 'GET ranking → 200');
eq(array_column($js['items'] ?? [], 'key'), ['promise', 'lake', 'mobit'], '初期の並びは出現順');
eq($js['saved'] ?? null, false, '未保存なら saved=false');
eq($js['title'] ?? null, '審査が不安な人向けカードローン比較（テスト用LP）', 'title は LP の <title>');
eq(array_column($js['items'] ?? [], 'hidden'), [false, false, false], 'hidden フラグ');
eq(array_column($js['items'] ?? [], 'heading'), ['プロミス', 'レイク', 'SMBCモビット'], 'heading');
ok(is_int($js['post_id'] ?? null) && $js['post_id'] > 0, 'post_id は正の整数');
$postId = $js['post_id'] ?? 0;

// 配信（未設定 → 素通し）
eq(box_ids(page('/shinsa5/index.php')), ['promise', 'lake', 'mobit'], '未設定のLPは元の表示');
eq(box_ids(page('/plain/index.php')), ['promise', 'lake'], '設定の無い別LPも元の表示');

// POST ranking → GET ranking / 配信
[$st, , $js] = api('POST', 'ranking', [], ['url' => $LPURL, 'order' => ['lake', 'promise', 'mobit'], 'hidden' => ['mobit']]);
eq([$st, $js['order'] ?? null, $js['hidden'] ?? null], [200, ['lake', 'promise', 'mobit'], ['mobit']], 'POST ranking');
[$st, , $js] = api('GET', 'ranking', ['url' => $LPURL]);
eq(array_column($js['items'] ?? [], 'key'), ['lake', 'promise', 'mobit'], '保存後の GET は order 順（hidden は末尾）');
eq(array_column($js['items'] ?? [], 'hidden'), [false, false, true], 'hidden が反映');
eq($js['saved'] ?? null, true, 'saved=true');
eq($js['post_id'] ?? null, $postId, 'post_id は安定');
[$st, , $js] = api('GET', 'ranking', ['url' => "{$BASE}/shinsa5/search-result.php"]);
eq(array_column($js['items'] ?? [], 'key'), ['lake', 'promise'], '診断結果ページは同じ設定を共有');

$p = page('/shinsa5/index.php');
eq(box_ids($p), ['lake', 'promise'], '配信: 商標コンテンツが並べ替わり mobit は非表示');
eq(brands_in(thead_of($p)), ['lake', 'promise'], '配信: 列型テーブル');
eq(ids($p, '/<div class="best3__first" id="pick-([^"]+)"/'), ['lake'], '配信: ピックアップ');
eq(substr_count($p, 'best3__first-catch'), 1, '配信: ピックアップ内側は無事');
eq(box_ids(page('/shinsa5/search-result.php')), ['lake', 'promise'], '配信: 診断結果ページも同じ順');
eq(box_ids(page('/plain/index.php')), ['promise', 'lake'], '配信: 他LPには影響しない');

// GET article（案件はブランドから自動補完）
[$st, , $js] = api('GET', 'article', ['url' => $LPURL]);
eq($st, 200, 'GET article → 200');
eq(array_column($js['campaigns'] ?? [], 'name'), ['promise', 'lake', 'mobit'], '案件はブランドから自動生成');
eq(array_column($js['campaigns'] ?? [], 'disabled'), [false, false, false], '初期は全部 disabled=false');
eq($js['permalink'] ?? null, "https://127.0.0.1:{$PORT}/shinsa5/index.php", 'permalink');

// comment-out
[$st, , $js] = api('POST', 'comment-out', [], ['url' => $LPURL, 'brand' => 'promise', 'disabled' => true]);
eq([$st, $js['updated'] ?? null], [200, 1], 'POST comment-out (brand)');
$p = page('/shinsa5/index.php');
eq(preg_match_all('/<a\b[^>]*promise\.html/', $p), 0, '配信: promise のリンクが消える');
ok(strpos($p, '<!-- alm:off -->') !== false, '配信: alm:off マーカー');
eq(box_ids($p), ['lake', 'promise'], '配信: ブロック自体は残る');
[$st, , $js] = api('GET', 'campaigns', ['url' => $LPURL]);
eq([$st, $js['match'] ?? null], [200, 'keyword'], 'GET campaigns');
eq(array_column($js['campaigns'] ?? [], 'disabled'), [true, false, false], 'campaigns の disabled');
eq($js['campaigns'][0]['values'][0]['bt'] ?? null, 'promise.html', 'values.bt はキーワード形式');
[$st, , $js] = api('POST', 'comment-out', [], ['url' => $LPURL, 'name' => 'nobody', 'disabled' => true]);
eq([$st, $js['code'] ?? null], [404, 'alm_no_campaign'], '無い案件 → 404');

// article/sync
[$st, , $js] = api('POST', 'article/sync', [], ['url' => $LPURL, 'items' => [
    ['brand' => 'promise', 'disabled' => false], ['name' => 'lake', 'disabled' => true], ['name' => 'zzz', 'disabled' => true],
]]);
eq([$st, count($js['applied'] ?? []), $js['unmatched'] ?? null], [200, 2, ['zzz']], 'POST article/sync');
$p = page('/shinsa5/index.php');
eq(preg_match_all('/<a\b[^>]*promise\.html/', $p) > 0, true, '配信: promise のリンクが戻る');
eq(preg_match_all('/<a\b[^>]*lake\.html/', $p), 0, '配信: lake のリンクが消える');

// campaigns（フル保存・置換テキスト）
[$st, , $js] = api('POST', 'campaigns', [], ['url' => $LPURL, 'campaigns' => [
    ['name' => 'プロミス', 'disabled' => true, 'values' => [['bt' => 'promise.html', 'replacement' => '掲載終了', 'parent' => false, 'parent_selectors' => []]]],
    ['name' => 'レイク',   'disabled' => false, 'values' => [['bt' => 'lake.html', 'replacement' => '', 'parent' => false, 'parent_selectors' => []]]],
]]);
eq([$st, count($js['campaigns'] ?? [])], [200, 2], 'POST campaigns');
$p = page('/shinsa5/index.php');
eq(preg_match_all('/<a\b[^>]*promise\.html/', $p), 0, '配信: 保存した案件で promise 停止');
ok(strpos($p, '<!-- alm:off -->掲載終了') !== false, '配信: 置換テキスト');
eq(preg_match_all('/<a\b[^>]*lake\.html/', $p) > 0, true, '配信: lake は復活');
[$st, , $js] = api('GET', 'article', ['url' => $LPURL]);
eq(array_column($js['campaigns'] ?? [], 'name'), ['プロミス', 'レイク', 'mobit'], '名前付き案件 + 不足ブランドは自動補完');
eq(array_column($js['campaigns'] ?? [], 'brands'), [['promise'], ['lake'], ['mobit']], 'brand はキーワードから復元');

// campaign/delete
[$st, , $js] = api('POST', 'campaign/delete', [], ['url' => $LPURL, 'name' => 'プロミス']);
eq([$st, $js['removed'] ?? null], [200, 1], 'POST campaign/delete');
[$st, , $js] = api('GET', 'article', ['url' => $LPURL]);
eq(array_column($js['campaigns'] ?? [], 'disabled'), [false, false, false], '削除後はブランドから再生成され停止も解除');
eq(preg_match_all('/<a\b[^>]*promise\.html/', page('/shinsa5/index.php')) > 0, true, '配信: promise のリンクが戻る');

// scan-links
[$st, , $js] = api('GET', 'scan-links', ['url' => $LPURL]);
eq([$st, $js['slug'] ?? null], [200, 'shinsa5'], 'GET scan-links');
eq(count($js['links'] ?? []), preg_match_all(ALM_BRAND_RE, $html), 'links は広告リンクの数');
eq($js['links'][0]['host'] ?? null, 'rank-guide.com', 'links[].host');

// 緊急停止スイッチ
write_config($DOCROOT, $TOKEN, ['disabled_all' => true]);
eq(box_ids(page('/shinsa5/index.php')), ['promise', 'lake', 'mobit'], 'disabled_all=true で元の表示に戻る');
write_config($DOCROOT, $TOKEN);
eq(box_ids(page('/shinsa5/index.php')), ['lake', 'promise'], '解除すると再び反映');

// 全解除
[$st] = api('POST', 'ranking', [], ['url' => $LPURL, 'order' => [], 'hidden' => []]);
eq($st, 200, 'order/hidden を空で保存');
eq(box_ids(page('/shinsa5/index.php')), ['promise', 'lake', 'mobit'], '設定を空にすると元の表示');

proc_terminate($server);
proc_close($server);

/* ---------- 結果 ---------- */

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
