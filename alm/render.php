<?php
/**
 * ALM コネクタ PHP版 — 配信時の並べ替え・リンク停止
 *
 * 各LPの index.php（および search-result.php 等）の先頭に次の1行を追加する:
 *
 *   <?php require $_SERVER['DOCUMENT_ROOT'] . '/alm/render.php'; ?>
 *
 * 保存済みの順位・リンク停止を表示時に適用する（元のPHP/HTMLファイルは書き換えない）。
 * 設定が無いLP・何かに失敗した場合は、元の表示をそのまま出す。
 */

if (defined('ALM_RAW')) return;           // API からの素のレンダリング時は何もしない
if (defined('ALM_RENDER_HOOKED')) return; // 二重 require 防止
define('ALM_RENDER_HOOKED', true);

require_once __DIR__ . '/lib.php';

(function () {
    try {
        if (!empty(alm_config()['disabled_all'])) return; // 緊急停止スイッチ

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $lp = alm_resolve_lp_path((string) $script);
        if (!$lp) return;

        $state = alm_state_load();
        $lpState = $state[$lp['key']] ?? null;
        if (!is_array($lpState)) return; // このLPの設定なし → 素通し

        $hasWork = !empty($lpState['order']) || !empty($lpState['hidden']);
        foreach ((array) ($lpState['campaigns'] ?? []) as $c) {
            if (!empty($c['disabled'])) { $hasWork = true; break; }
        }
        if (!$hasWork) return;

        ob_start(function (string $buffer) use ($lpState): string {
            $out = alm_transform($buffer, $lpState);
            return $out !== '' ? $out : $buffer; // 失敗したら元の表示
        });
    } catch (\Throwable $e) {
        // 表示は絶対に壊さない
    }
})();
