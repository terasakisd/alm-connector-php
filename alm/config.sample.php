<?php
/**
 * ALM コネクタ PHP版 — 設定ファイルの見本
 *
 * このファイルを config.php という名前でコピーし、token を設定してください。
 * token は順位管理ツールの「記事設定」に貼る共有トークンです（64桁推奨）。
 * 生成例:  php -r "echo bin2hex(random_bytes(32));"
 */
return [
    'token' => '',  // ← 必ず設定する（空のままだとAPIは動きません）

    // 以下は通常は変更不要
    // 'docroot'      => '/var/www/html',           // ドキュメントルート（自動検出が外れる場合のみ）
    // 'unit_blocks'  => ['ranking__box', 'best3__list-item'],  // 並べ替え対象ブロックのclass
    // 'pick_class'   => 'best3__first',            // ピックアップ（1位連動）ブロックのclass
    // 'disabled_all' => false,                     // true で全LPの変換を一時停止（元の表示に戻す）
];
