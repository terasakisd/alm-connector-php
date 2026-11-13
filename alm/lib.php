<?php
/**
 * ALM コネクタ PHP版 — 共通ライブラリ
 *
 * WordPress を使わないリスティングLP（rank-guide.com 等）向けに、
 * 順位管理ツールと同じ API（/alm/v1/*）と配信時の並べ替えを提供する。
 *
 * 順位ユニットの検出（LPテンプレ対応）:
 *   - ブロック: class に ranking__box / best3__list-item を含む要素、または data-rank 属性を持つ要素
 *   - 行:      /ad/○○.html リンクを含む <tr>（列型テーブル内は除く）
 *   - 列型テーブル: thead の1行に /ad/○○.html リンク入り <td> が2つ以上あるテーブル → 列ごと入れ替え
 *   - ピックアップ: class に best3__first を含む要素が複数あれば、1位ブランドのものだけ表示
 *   - ブランド: ユニット内の /ad(〜)/○○.html のファイル名（WP版の /lp/ と同じ考え方）
 */

const ALM_PHP_VERSION = '1.1.0';

/** ブランド検出用（/ad/promise.html, /ad-column67/promise.html などに対応） */
const ALM_BRAND_RE = '#/(?:ad|ad-[a-z0-9_\-]+)/([a-z0-9_\-]+)\.html#i';

/* ---------- 設定・状態 ---------- */

function alm_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/config.php';
    $cfg = is_file($file) ? (array) (require $file) : [];
    $cfg += [
        'token'       => '',
        'docroot'     => $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__),
        'unit_blocks' => ['ranking__box', 'best3__list-item'],
        'pick_class'  => 'best3__first',
        // ピックアップを1位ブランドの商標コンテンツから組み替えるときの部品（class名）
        'pick_parts'  => [
            'name'  => 'best3__first-head-name',   // ブランド名
            'catch' => 'best3__first-catch',       // キャッチコピー
            'bnr'   => 'best3__first-bnr',         // バナー（<a> の中身を差し替え）
            'list'  => 'best3__first-check-list',  // CHECK 項目の <ul>
            'check' => 'best3__first-check',       // CHECK 枠（項目を作れないときは丸ごと外す）
        ],
        'unit_parts'  => [
            'name'         => 'ranking__box-head-name',
            'catch'        => 'ranking__box-catch',
            'bnr'          => 'ranking__box-bnr',
            'item'         => 'ranking__box-list-item',
            'item_head'    => 'ranking__box-list-item-head',
            'item_content' => 'ranking__box-list-item-content',
        ],
        'disabled_all'=> false, // 緊急停止: true で全LPの変換を止める（元の表示に戻る）
    ];
    return $cfg;
}

function alm_state_file(): string {
    return __DIR__ . '/data/state.php';
}

/**
 * 状態（LPごとの順位・除外・案件）を読む。
 * ファイルは「PHPガード行 + JSON」形式：直接アクセスされても中身が漏れず、
 * require/OPcache を通らないので常に最新の値を読める。
 */
function alm_state_load(): array {
    $file = alm_state_file();
    if (!is_file($file)) return [];
    clearstatcache(true, $file);
    $raw = @file_get_contents($file);
    if (!is_string($raw)) return [];
    $nl = strpos($raw, "\n");
    if ($nl === false) return [];
    $state = json_decode(substr($raw, $nl + 1), true);
    return is_array($state) ? $state : [];
}

/** 状態を保存する */
function alm_state_save(array $state): bool {
    $dir = dirname(alm_state_file());
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $php  = "<?php exit; // ALMコネクタの保存データ（自動生成・直接編集しない） ?>\n" . $json . "\n";
    return (bool) @file_put_contents(alm_state_file(), $php, LOCK_EX);
}

/* ---------- LPの解決 ---------- */

/**
 * URL または パスから LP を特定する。
 * 戻り値: ['key' => '/cardloan', 'file' => 絶対パス] / 見つからなければ null
 * key は LPのディレクトリ（1ディレクトリ＝1LP）。
 * index.php と search-result.php（診断結果）が同じ順位設定を共有する。
 */
function alm_resolve_lp(string $url): ?array {
    $path = parse_url(trim($url), PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = '/';
    return alm_resolve_lp_path($path);
}

function alm_resolve_lp_path(string $path): ?array {
    $docroot = rtrim(alm_config()['docroot'], '/');
    if ($docroot === '' || !is_dir($docroot)) return null;

    $path = '/' . ltrim($path, '/');
    // ディレクトリ指定は index.php
    if (substr($path, -1) === '/') $path .= 'index.php';
    if (!preg_match('/\.php$/i', $path)) $path .= '/index.php';

    $file = realpath($docroot . $path);
    if ($file === false) return null;
    // docroot の外・PHP以外は拒否（パストラバーサル防止）
    $root = realpath($docroot);
    if ($root === false || strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) return null;
    if (!preg_match('/\.php$/i', $file)) return null;
    // コネクタ自身（alm/ 配下）のファイルはLPとして扱わない
    $self = realpath(__DIR__);
    if ($self !== false && strncmp($file, $self . DIRECTORY_SEPARATOR, strlen($self) + 1) === 0) return null;

    $rel = str_replace('\\', '/', substr($file, strlen($root)));
    $key = str_replace('\\', '/', dirname($rel));
    if ($key === '.' || $key === '') $key = '/';
    return ['key' => $key, 'file' => $file];
}

/** LPごとの安定ID（WP の post_id 相当） */
function alm_post_id(string $key): int {
    return crc32($key) & 0x7fffffff;
}

/* ---------- LPのHTMLを得る（変換なしの素の出力） ---------- */

function alm_lp_html(string $file): string {
    if (!defined('ALM_RAW')) define('ALM_RAW', true); // render.php に変換スキップを指示
    $cwd = getcwd();
    chdir(dirname($file));
    ob_start();
    try {
        include $file;
    } catch (\Throwable $e) {
        // 実行に失敗したら素のファイル内容で代用（純HTMLに近いLP向け）
    }
    $html = ob_get_clean();
    if ($cwd !== false) chdir($cwd);
    if (!is_string($html) || trim($html) === '') {
        $html = (string) @file_get_contents($file);
        // 先頭の require 行（コネクタ読み込み）を除いた素のHTML
        $html = preg_replace('/^<\?php\s+require[^;]+;\s*\?>\s*/', '', $html, 1);
    }
    return $html;
}

/* ---------- タグ解析（WP版から移植） ---------- */

/** 開始タグ位置 $from から、$tag の対応する閉じタグ直後の位置を返す（入れ子対応）。無ければ null。 */
function alm_match_element_end(string $content, string $tag, int $from): ?int {
    $depth   = 1;
    $offset  = $from;
    $pattern = '/<(\/?)' . preg_quote($tag, '/') . '\b[^>]*?(\/?)>/i';
    while ($depth > 0 && preg_match($pattern, $content, $mm, PREG_OFFSET_CAPTURE, $offset)) {
        $whole     = $mm[0][0];
        $pos       = $mm[0][1];
        $isClose   = ($mm[1][0] === '/');
        $selfClose = ($mm[2][0] === '/');
        $offset    = $pos + strlen($whole);
        if ($isClose) {
            $depth--;
            if ($depth === 0) return $offset;
        } elseif (!$selfClose) {
            $depth++;
        }
    }
    return null;
}

function alm_strip_tags_text(string $html): string {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    if (function_exists('mb_substr') && mb_strlen($t) > 40) $t = mb_substr($t, 0, 40) . '…';
    return $t;
}

/** ユニットHTML内からブランドを導く */
function alm_brand_of(string $html): string {
    if (preg_match(ALM_BRAND_RE, $html, $m)) return strtolower($m[1]);
    return '';
}

/**
 * class 属性の中で、指定クラス名が「1トークンとして」含まれることを見る正規表現片。
 * \b だと ranking__box-head / best3__first-catch などの派生クラスにも一致してしまうため、
 * 前後が英数字・_・- でないことを条件にする。
 */
function alm_class_token_re(array $classes): string {
    $alt = implode('|', array_map(function ($c) { return preg_quote((string) $c, '/'); }, $classes));
    return '(?<![\w-])(?:' . $alt . ')(?![\w-])';
}

/* ---------- 順位ユニットの検出 ---------- */

/**
 * ブロック/行ユニットを検出する。
 * 戻り値: [['tag','start','end','html','brand','key','heading','is_source'], ...]（位置順・入れ子は外側のみ）
 */
function alm_find_units(string $content): array {
    $cfg = alm_config();

    // 列型テーブルの範囲は行ユニット検出から除外する
    $colTables = alm_find_column_tables($content);
    $inColTable = function (int $pos) use ($colTables): bool {
        foreach ($colTables as $t) {
            if ($pos >= $t['start'] && $pos < $t['end']) return true;
        }
        return false;
    };

    $units = [];

    // (1) class 指定ブロック or data-rank 持ち要素
    $pattern = '/<([a-z][a-z0-9]*)\b(?=[^>]*(?:\bdata-rank\s*=\s*["\']|\bclass\s*=\s*["\'][^"\']*' . alm_class_token_re($cfg['unit_blocks']) . '))[^>]*>/i';
    if (preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $full  = $m[0][$i][0];
            $start = $m[0][$i][1];
            $tag   = strtolower($m[1][$i][0]);
            $end   = alm_match_element_end($content, $tag, $start + strlen($full));
            if ($end === null) continue;

            $html  = substr($content, $start, $end - $start);
            $brand = alm_brand_of($html);

            $id = '';
            if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $full, $im)) $id = strtolower($im[1]);
            $dataRank = '';
            if (preg_match('/\bdata-rank\s*=\s*["\']([^"\']*)["\']/i', $full, $dm)) $dataRank = strtolower(trim($dm[1]));

            // 「順位の元」（管理画面に出す）= ランキング詳細ブロック or data-rank="topic_◯◯"
            $isRankingBox = (bool) preg_match('/\bclass\s*=\s*["\'][^"\']*' . alm_class_token_re(['ranking__box']) . '/i', $full);
            if ($dataRank !== '') {
                $isTopic = (strncmp($dataRank, 'topic_', 6) === 0);
                $key = $isTopic ? substr($dataRank, 6) : $dataRank;
                $isSource = $isTopic;
            } else {
                $key = $brand !== '' ? $brand : $id;
                $isSource = $isRankingBox;
            }
            if ($key === '') continue;

            // 見出しテキスト（管理画面表示用）
            $heading = '';
            if (preg_match('/class\s*=\s*["\'][^"\']*ranking__box-head-name[^"\']*["\'][^>]*>(.*?)<\/a>/is', $html, $hm)) {
                $heading = alm_strip_tags_text($hm[1]);
            }
            if ($heading === '') $heading = $key;

            $units[] = [
                'tag' => $tag, 'start' => $start, 'end' => $end, 'html' => $html,
                'brand' => $brand, 'key' => strtolower($key), 'heading' => $heading,
                'is_source' => $isSource,
            ];
        }
    }

    // (2) 行ユニット: /ad/リンクを含む <tr>（列型テーブル外・ブロックユニット外）
    if (preg_match_all('/<tr\b[^>]*>/i', $content, $tm, PREG_OFFSET_CAPTURE)) {
        foreach ($tm[0] as $t) {
            $start = $t[1];
            if ($inColTable($start)) continue;
            $end = alm_match_element_end($content, 'tr', $start + strlen($t[0]));
            if ($end === null) continue;
            $html  = substr($content, $start, $end - $start);
            $brand = alm_brand_of($html);
            if ($brand === '') continue;
            $units[] = [
                'tag' => 'tr', 'start' => $start, 'end' => $end, 'html' => $html,
                'brand' => $brand, 'key' => $brand, 'heading' => $brand,
                'is_source' => false,
            ];
        }
    }

    // 位置順に整列し、入れ子・重複は外側だけ残す
    usort($units, function ($a, $b) { return $a['start'] <=> $b['start']; });
    $result = [];
    $lastEnd = -1;
    foreach ($units as $u) {
        if ($u['start'] < $lastEnd) continue;
        $result[] = $u;
        $lastEnd = $u['end'];
    }
    return $result;
}

/**
 * 列型テーブル（thead の1行に /ad/リンク入り <td> が2つ以上）を検出する。
 * 戻り値: [['start','end','html','brands'=>[列順のブランド]], ...]
 */
function alm_find_column_tables(string $content): array {
    $tables = [];
    if (!preg_match_all('/<table\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE)) return $tables;
    foreach ($m[0] as $t) {
        $start = $t[1];
        $end = alm_match_element_end($content, 'table', $start + strlen($t[0]));
        if ($end === null) continue;
        $html = substr($content, $start, $end - $start);

        // thead の最初の行の <td> 群からブランド列を読む
        if (!preg_match('/<thead\b[^>]*>(.*?)<\/thead>/is', $html, $hm)) continue;
        if (!preg_match('/<tr\b[^>]*>(.*?)<\/tr>/is', $hm[1], $rm)) continue;
        $cells = alm_split_cells($rm[1]);
        $brands = [];
        $adCells = 0;
        foreach ($cells as $c) {
            if ($c['tag'] !== 'td') continue;
            $b = alm_brand_of($c['html']);
            if ($b !== '') $adCells++;
            $brands[] = $b;
        }
        if ($adCells < 2) continue;
        $tables[] = ['start' => $start, 'end' => $end, 'html' => $html, 'brands' => $brands];
    }
    return $tables;
}

/** 行HTML（<tr>の中身）を <th>/<td> セルに分割する */
function alm_split_cells(string $rowInner): array {
    $cells = [];
    $offset = 0;
    while (preg_match('/<(th|td)\b[^>]*>/i', $rowInner, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $tag   = strtolower($m[1][0]);
        $start = $m[0][1];
        $end   = alm_match_element_end($rowInner, $tag, $start + strlen($m[0][0]));
        if ($end === null) break;
        $cells[] = ['tag' => $tag, 'start' => $start, 'end' => $end, 'html' => substr($rowInner, $start, $end - $start)];
        $offset = $end;
    }
    return $cells;
}

/* ---------- 並べ替えの適用 ---------- */

/**
 * ブロック/行ユニットを「連続したまとまり(run)」ごとに order の順へ並べ替え、
 * hidden のキーは出力から除外する（WP版 apply_order_inplace と同じ考え方）。
 * run の区切り判定では HTMLコメント（<!-- 1位 --> 等）だけの隙間は連続とみなす。
 */
function alm_apply_order(string $content, array $units, array $order, array $hidden): string {
    if (empty($units)) return $content;

    $hiddenSet = [];
    foreach ($hidden as $h) { $h = strtolower(trim((string) $h)); if ($h !== '') $hiddenSet[$h] = true; }

    $rank = [];
    $r = 0;
    foreach ($order as $k) {
        $k = strtolower(trim((string) $k));
        if ($k !== '' && !isset($rank[$k])) $rank[$k] = $r++;
    }

    // 連続（間が空白・コメントのみ）のユニットを run にまとめる
    $runs = [];
    $cur = [];
    $count = count($units);
    for ($i = 0; $i < $count; $i++) {
        if ($i > 0) {
            $gapStart = $units[$i - 1]['end'];
            $gap = substr($content, $gapStart, $units[$i]['start'] - $gapStart);
            $gap = preg_replace('/<!--.*?-->/s', '', $gap);
            if (trim($gap) !== '') { $runs[] = $cur; $cur = []; }
        }
        $cur[] = $i;
    }
    if (!empty($cur)) $runs[] = $cur;

    $replacements = [];
    foreach ($runs as $run) {
        $visible = array_values(array_filter($run, function ($i) use ($units, $hiddenSet) {
            return !isset($hiddenSet[$units[$i]['key']]);
        }));
        usort($visible, function ($a, $b) use ($units, $rank) {
            $ra = $rank[$units[$a]['key']] ?? PHP_INT_MAX;
            $rb = $rank[$units[$b]['key']] ?? PHP_INT_MAX;
            if ($ra !== $rb) return $ra <=> $rb;
            return $a <=> $b;
        });
        $runCount = count($run);
        for ($s = 0; $s < $runCount; $s++) {
            $slot = $units[$run[$s]];
            $html = ($s < count($visible)) ? $units[$visible[$s]]['html'] : '';
            $replacements[] = ['start' => $slot['start'], 'len' => $slot['end'] - $slot['start'], 'html' => $html];
        }
    }

    usort($replacements, function ($a, $b) { return $b['start'] <=> $a['start']; });
    foreach ($replacements as $rp) {
        $content = substr_replace($content, $rp['html'], $rp['start'], $rp['len']);
    }
    return $content;
}

/** 列型テーブルの列を order の順に並べ替え、hidden の列は落とす */
function alm_apply_column_tables(string $content, array $order, array $hidden): string {
    $hiddenSet = [];
    foreach ($hidden as $h) { $h = strtolower(trim((string) $h)); if ($h !== '') $hiddenSet[$h] = true; }
    $rank = [];
    $r = 0;
    foreach ($order as $k) {
        $k = strtolower(trim((string) $k));
        if ($k !== '' && !isset($rank[$k])) $rank[$k] = $r++;
    }

    $tables = alm_find_column_tables($content);
    // 後ろから置換
    usort($tables, function ($a, $b) { return $b['start'] <=> $a['start']; });

    foreach ($tables as $t) {
        $brands = $t['brands']; // <td> 列ごとのブランド（'' あり）
        $colCount = count($brands);
        if ($colCount < 2) continue;

        // 新しい列順（元の列index の並び）: 表示列を順位順に、hidden列は落とす
        $cols = range(0, $colCount - 1);
        $visible = array_values(array_filter($cols, function ($i) use ($brands, $hiddenSet) {
            return $brands[$i] === '' || !isset($hiddenSet[$brands[$i]]);
        }));
        usort($visible, function ($a, $b) use ($brands, $rank) {
            $ra = $brands[$a] !== '' ? ($rank[$brands[$a]] ?? PHP_INT_MAX) : PHP_INT_MAX;
            $rb = $brands[$b] !== '' ? ($rank[$brands[$b]] ?? PHP_INT_MAX) : PHP_INT_MAX;
            if ($ra !== $rb) return $ra <=> $rb;
            return $a <=> $b;
        });
        if ($visible === $cols) continue; // 変化なし

        // テーブル内の全 <tr> について <td> 群を新しい列順に差し替える
        $newTable = preg_replace_callback('/(<tr\b[^>]*>)(.*?)(<\/tr>)/is', function ($m) use ($visible, $colCount) {
            $cells = alm_split_cells($m[2]);
            $tds = array_values(array_filter($cells, function ($c) { return $c['tag'] === 'td'; }));
            if (count($tds) !== $colCount) return $m[0]; // 列数が合わない行（colspan等）は触らない
            $inner = $m[2];
            // 後ろから: 各<td>スロットへ新しい列のHTMLを差し込む
            for ($slot = $colCount - 1; $slot >= 0; $slot--) {
                $srcHtml = ($slot < count($visible)) ? $tds[$visible[$slot]]['html'] : '';
                $dst = $tds[$slot];
                $inner = substr_replace($inner, $srcHtml, $dst['start'], $dst['end'] - $dst['start']);
            }
            return $m[1] . $inner . $m[3];
        }, $t['html']);

        if (is_string($newTable) && $newTable !== '') {
            $content = substr_replace($content, $newTable, $t['start'], $t['end'] - $t['start']);
        }
    }
    return $content;
}

/**
 * ピックアップ（迷ったらココ！等）を表示順1位のブランドに連動させる。
 *  - LP に pick_class の要素（バリアント）がブランドごとに複数あれば、1位ブランドのものだけ残す
 *  - バリアントが1つだけなら、その枠を 1位ブランドの商標コンテンツ（ranking__box）の内容で組み替える
 *    （リンク先・ブランド名・キャッチ・バナー・CHECK項目）。1位ブランドの商標コンテンツが無ければ何もしない
 */
function alm_apply_pick(string $content, array $order, array $hidden, array $units = []): string {
    $cfg = alm_config();
    $pattern = '/<([a-z][a-z0-9]*)\b[^>]*\bclass\s*=\s*["\'][^"\']*' . alm_class_token_re([$cfg['pick_class']]) . '[^"\']*["\'][^>]*>/i';
    if (!preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) return $content;

    $hiddenSet = [];
    foreach ($hidden as $h) { $h = strtolower(trim((string) $h)); if ($h !== '') $hiddenSet[$h] = true; }
    $top = '';
    foreach ($order as $k) {
        $k = strtolower(trim((string) $k));
        if ($k !== '' && !isset($hiddenSet[$k])) { $top = $k; break; }
    }
    if ($top === '') return $content;

    $picks = [];
    $n = count($m[0]);
    for ($i = 0; $i < $n; $i++) {
        $start = $m[0][$i][1];
        $tag   = strtolower($m[1][$i][0]);
        $end   = alm_match_element_end($content, $tag, $start + strlen($m[0][$i][0]));
        if ($end === null) continue;
        $html = substr($content, $start, $end - $start);
        $picks[] = ['start' => $start, 'end' => $end, 'html' => $html, 'brand' => alm_brand_of($html)];
    }
    if (empty($picks)) return $content;

    // バリアントが1つだけ → 1位ブランドの商標コンテンツで組み替える
    if (count($picks) === 1) {
        $p = $picks[0];
        if ($p['brand'] === $top) return $content; // すでに1位ブランド
        $unit = alm_unit_for_brand($units, $top);
        if ($unit === null) return $content;       // 組み替え元が無い（安全側）
        $nameCls = (string) (((array) $cfg['unit_parts'])['name'] ?? '');
        if ($nameCls !== '' && alm_inner_of($unit['html'], $nameCls) === null) return $content; // ブランド名を取れない要素（行・帯）からは作らない
        $new = alm_build_pick($p['html'], $unit['html'], $top);
        if ($new === '' || $new === $p['html']) return $content;
        return substr_replace($content, $new, $p['start'], $p['end'] - $p['start']);
    }

    // 複数バリアント → 1位ブランドのものだけ残す（無ければ何もしない）
    $hasTop = false;
    foreach ($picks as $p) { if ($p['brand'] === $top) { $hasTop = true; break; } }
    if (!$hasTop) return $content;

    usort($picks, function ($a, $b) { return $b['start'] <=> $a['start']; });
    foreach ($picks as $p) {
        if ($p['brand'] === $top) continue;
        $content = substr_replace($content, '', $p['start'], $p['end'] - $p['start']);
    }
    return $content;
}

/** ユニット一覧からブランドの商標コンテンツを返す（順位の元 = ranking__box を優先） */
function alm_unit_for_brand(array $units, string $brand): ?array {
    foreach ($units as $u) { if (!empty($u['is_source']) && $u['brand'] === $brand) return $u; }
    foreach ($units as $u) { if ($u['brand'] === $brand) return $u; }
    return null;
}

/** class を持つ最初の要素の位置（start / inner_start / inner_end / end）。無ければ null */
function alm_element_by_class(string $html, string $class, int $offset = 0): ?array {
    $re = '/<([a-z][a-z0-9]*)\b[^>]*\bclass\s*=\s*["\'][^"\']*' . alm_class_token_re([$class]) . '[^"\']*["\'][^>]*>/i';
    if (!preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $offset)) return null;
    $start = $m[0][1];
    $tag   = strtolower($m[1][0]);
    $innerStart = $start + strlen($m[0][0]);
    $end = alm_match_element_end($html, $tag, $innerStart);
    if ($end === null) return null;
    $innerEnd = strrpos(substr($html, 0, $end), '<');
    if ($innerEnd === false || $innerEnd < $innerStart) return null;
    return ['tag' => $tag, 'start' => $start, 'inner_start' => $innerStart, 'inner_end' => $innerEnd, 'end' => $end];
}

/** class を持つ最初の要素の内側HTML。無ければ null */
function alm_inner_of(string $html, string $class): ?string {
    $e = alm_element_by_class($html, $class);
    return $e === null ? null : substr($html, $e['inner_start'], $e['inner_end'] - $e['inner_start']);
}

/** class を持つ最初の要素の内側HTMLを差し替える（要素が無ければそのまま） */
function alm_replace_inner(string $html, string $class, string $inner): string {
    $e = alm_element_by_class($html, $class);
    if ($e === null) return $html;
    return substr_replace($html, $inner, $e['inner_start'], $e['inner_end'] - $e['inner_start']);
}

/** タグを除いた素のテキスト（空白を詰める。切り詰めない） */
function alm_text(string $html): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

/**
 * ピックアップ枠（$pick）を、ブランド $top の商標コンテンツ（$unit）の内容で組み替える。
 * 部品のクラス名は config の pick_parts / unit_parts。
 */
function alm_build_pick(string $pick, string $unit, string $top): string {
    $cfg = alm_config();
    $pp  = (array) $cfg['pick_parts'];
    $up  = (array) $cfg['unit_parts'];
    $old = alm_brand_of($pick);

    // 旧ブランド名（最後にテキストとして残っている分を置き換えるために控える）
    $oldName = '';
    if (!empty($pp['name'])) { $v = alm_inner_of($pick, $pp['name']); if ($v !== null) $oldName = alm_text($v); }

    // 1) リンク先: /ad/旧.html → /ad/新.html（計測用のクエリはそのまま）
    if ($old !== '') {
        $pick = preg_replace('#(/(?:ad|ad-[a-z0-9_\-]+)/)' . preg_quote($old, '#') . '(\.html)#i', '${1}' . $top . '$2', $pick);
    }

    // 2) ブランド名
    $newName = '';
    if (!empty($up['name'])) { $v = alm_inner_of($unit, $up['name']); if ($v !== null) $newName = alm_text($v); }
    if ($newName !== '' && !empty($pp['name'])) $pick = alm_replace_inner($pick, $pp['name'], $newName);

    // 3) キャッチコピー
    if (!empty($up['catch']) && !empty($pp['catch'])) {
        $v = alm_inner_of($unit, $up['catch']);
        if ($v !== null && alm_text($v) !== '') $pick = alm_replace_inner($pick, $pp['catch'], trim($v));
    }

    // 4) バナー: 商標コンテンツのバナー（<picture>/<img>）をそのまま使う。無ければファイル名の 旧_bnr → 新_bnr だけ置換
    $bnrDone = false;
    if (!empty($up['bnr']) && !empty($pp['bnr'])) {
        $v = alm_inner_of($unit, $up['bnr']);
        if ($v !== null && preg_match('/<(?:img|picture)\b/i', $v)) { $pick = alm_replace_inner($pick, $pp['bnr'], trim($v)); $bnrDone = true; }
    }
    if (!$bnrDone && $old !== '') {
        $pick = preg_replace('/\b' . preg_quote($old, '/') . '(_bnr\.)/i', $top . '$1', $pick);
    }

    // 5) CHECK 項目: 商標コンテンツの「項目名 / 値」から作る（good → normal → その他 の順）
    if (!empty($pp['list'])) {
        $list = alm_element_by_class($pick, $pp['list']);
        if ($list !== null) {
            $listInner = substr($pick, $list['inner_start'], $list['inner_end'] - $list['inner_start']);
            // 既存の <li> の書き方（開始タグ・強調 span）をテンプレとして使う
            $liOpen = '<li>';
            if (preg_match('/<li\b[^>]*>/i', $listInner, $lm)) $liOpen = $lm[0];
            $liCount = max(1, preg_match_all('/<li\b/i', $listInner));
            $spanOpen = '';
            if (preg_match('/<span\b[^>]*>/i', $listInner, $sm)) $spanOpen = $sm[0];

            $items = alm_unit_spec_items($unit, $up);
            usort($items, function ($a, $b) { return $a['rank'] <=> $b['rank']; });
            $items = array_slice($items, 0, $liCount);

            if (!empty($items)) {
                $lis = [];
                foreach ($items as $it) {
                    $val = htmlspecialchars($it['content'], ENT_QUOTES, 'UTF-8');
                    $val = $spanOpen !== '' ? $spanOpen . $val . '</span>' : $val;
                    $lis[] = $liOpen . htmlspecialchars($it['head'], ENT_QUOTES, 'UTF-8') . 'は' . $val . '</li>';
                }
                $pick = substr_replace($pick, "\n" . implode("\n", $lis) . "\n", $list['inner_start'], $list['inner_end'] - $list['inner_start']);
            } elseif (!empty($pp['check']) && ($chk = alm_element_by_class($pick, $pp['check'])) !== null) {
                $pick = substr_replace($pick, '', $chk['start'], $chk['end'] - $chk['start']); // 項目を作れない → CHECK 枠ごと外す
            } else {
                $pick = substr_replace($pick, '', $list['inner_start'], $list['inner_end'] - $list['inner_start']);
            }
        }
    }

    // 6) テキストとして残っている旧ブランド名を新ブランド名に（alt など）
    if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
        $pick = str_replace($oldName, $newName, $pick);
    }
    return $pick;
}

/** 商標コンテンツのスペック表（項目名・値・評価クラス）を取り出す。rank: good=0, normal=1, その他=2 */
function alm_unit_spec_items(string $unit, array $up): array {
    $items = [];
    if (empty($up['item'])) return $items;
    $offset = 0;
    while (($e = alm_element_by_class($unit, $up['item'], $offset)) !== null) {
        $offset = $e['end'];
        $li = substr($unit, $e['start'], $e['end'] - $e['start']);
        $head = !empty($up['item_head']) ? alm_inner_of($li, $up['item_head']) : null;
        $cont = !empty($up['item_content']) ? alm_element_by_class($li, $up['item_content']) : null;
        if ($head === null || $cont === null) continue;
        $headText = alm_text($head);
        $contHtml = substr($li, $cont['inner_start'], $cont['inner_end'] - $cont['inner_start']);
        $contText = alm_text($contHtml);
        if ($headText === '' || $contText === '') continue;
        $open = substr($li, $cont['start'], $cont['inner_start'] - $cont['start']);
        $rank = 2;
        if (preg_match('/\bclass\s*=\s*["\'][^"\']*(?<![\w-])good(?![\w-])/i', $open)) $rank = 0;
        elseif (preg_match('/\bclass\s*=\s*["\'][^"\']*(?<![\w-])normal(?![\w-])/i', $open)) $rank = 1;
        $items[] = ['head' => $headText, 'content' => $contText, 'rank' => $rank];
    }
    return $items;
}
/** リンク停止: 停止ブランドの /ad/○○.html アンカーをコメントアウト（置換テキスト対応） */
function alm_apply_disabled(string $content, array $campaigns): string {
    foreach ($campaigns as $c) {
        if (empty($c['disabled'])) continue;
        $keywords = [];
        foreach ((array) ($c['values'] ?? []) as $v) {
            $kw = trim((string) ($v['bt'] ?? ''));
            if ($kw !== '') $keywords[] = $kw;
        }
        if (empty($keywords) && !empty($c['brand'])) $keywords[] = $c['brand'] . '.html';
        $replacement = '';
        foreach ((array) ($c['values'] ?? []) as $v) {
            if (!empty($v['replacement'])) { $replacement = (string) $v['replacement']; break; }
        }
        foreach ($keywords as $kw) {
            $kwq = preg_quote($kw, '/');
            $new = preg_replace_callback(
                '/<a\b[^>]*href\s*=\s*["\'][^"\']*' . $kwq . '[^"\']*["\'][^>]*>.*?<\/a>/is',
                function ($m) use ($replacement) {
                    $out = '<!-- alm:off -->';
                    if ($replacement !== '') $out .= htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8');
                    return $out;
                },
                $content
            );
            if (is_string($new)) $content = $new; // 置換失敗（上限超過等）時は元のまま
        }
    }
    return $content;
}

/* ---------- 状態→配信反映（render.php から使う） ---------- */

/** LPキーの状態を取り、HTMLへ全変換を適用する */
function alm_transform(string $content, array $lpState): string {
    $order  = (array) ($lpState['order'] ?? []);
    $hidden = (array) ($lpState['hidden'] ?? []);
    $campaigns = (array) ($lpState['campaigns'] ?? []);

    try {
        $before = strlen($content);
        if (!empty($order) || !empty($hidden)) {
            $units = alm_find_units($content);
            $content = alm_apply_order($content, $units, $order, $hidden);
            $content = alm_apply_column_tables($content, $order, $hidden);
            $content = alm_apply_pick($content, $order, $hidden, $units);
        }
        $content = alm_apply_disabled($content, $campaigns);
        // 安全策: 出力が空になったら変換を破棄
        if (!is_string($content) || trim($content) === '') return '';
        return $content;
    } catch (\Throwable $e) {
        return ''; // 呼び出し側で元のHTMLに戻す
    }
}

/* ---------- 案件（キャンペーン）の自動構築 ---------- */

/** LPのHTMLからブランド一覧（出現順・ランキング詳細優先）を得る */
function alm_detect_brands(string $content): array {
    $units = alm_find_units($content);
    $brands = [];
    foreach ($units as $u) {
        if (!$u['is_source'] || $u['brand'] === '') continue;
        if (!in_array($u['brand'], $brands, true)) $brands[] = $u['brand'];
    }
    // ランキング詳細が無いLPは全ユニットから
    if (empty($brands)) {
        foreach ($units as $u) {
            if ($u['brand'] !== '' && !in_array($u['brand'], $brands, true)) $brands[] = $u['brand'];
        }
    }
    // 列型テーブルからも補完
    foreach (alm_find_column_tables($content) as $t) {
        foreach ($t['brands'] as $b) {
            if ($b !== '' && !in_array($b, $brands, true)) $brands[] = $b;
        }
    }
    return $brands;
}

/** state に無い案件をブランドから自動補完する */
function alm_ensure_campaigns(array $lpState, array $brands): array {
    $existing = [];
    foreach ((array) ($lpState['campaigns'] ?? []) as $c) {
        if (!empty($c['brand'])) $existing[strtolower($c['brand'])] = true;
    }
    foreach ($brands as $b) {
        if (isset($existing[$b])) continue;
        $lpState['campaigns'][] = [
            'name'     => $b,
            'brand'    => $b,
            'disabled' => false,
            'values'   => [['bt' => $b . '.html', 'replacement' => '', 'parent' => false, 'parent_selectors' => []]],
        ];
    }
    return $lpState;
}
