# ALM コネクタ PHP版（alm-connector-php）

WordPress を使わないリスティングLP（rank-guide.com 等の素のPHPサイト）を、
既存の【SD】案件順位管理ツールから操作できるようにするコネクタ。
WordPress プラグイン「ALM コネクタ」と同じ API（`/alm/v1/*`）と応答形を PHP 単体で提供する。

## できること

- **掲載順の入れ替え**（ツールの画面からドラッグ → 保存）
  - 商標コンテンツ（`ranking__box`、診断結果ページ含む）
  - BEST3帯（`best3__list-item`）
  - 比較テーブル（行型・列型の両方。列型は列ごと入れ替え、1〜3位の装飾はCSSが位置基準なので自動で追従）
- **リンク停止**（ブランド単位で `/ad/○○.html` へのアンカーをコメントアウト）
- **記事から外す**（ブランドのブロック・列を表示から除外。戻すと再表示）
- **ピックアップ（迷ったらココ！）の1位連動**
  （`best3__first` ブロックをブランドごとに複数用意しておくと、表示順1位のものだけが出る。
  1つしか無いLPでは従来表示のまま）

元のPHP/HTMLファイルは一切書き換えず、配信時の表示だけを変える。
設定が無いLP・解析に失敗した場合は元の表示をそのまま出す。

## サーバへの設置（1回だけ）

1. `alm/` フォルダをドキュメントルート直下にアップロード（例: `https://rank-guide.com/alm/`）
2. `alm/config.sample.php` を `alm/config.php` にコピーし、`token` を設定
   （生成例: `php -r "echo bin2hex(random_bytes(32));"`）
3. `alm/data/` に Web サーバの書き込み権限を付与（例: `chmod 775 alm/data`）
4. 管理したい各LPの `index.php`（診断結果がある場合は `search-result.php` も）の**先頭**に1行追加:

   ```php
   <?php require $_SERVER['DOCUMENT_ROOT'] . '/alm/render.php'; ?>
   ```

## ツール側の登録

記事設定に次を登録するだけ（ツール v0.49.0 以降は接続方式を自動判別）:

| 項目 | 値 |
|---|---|
| サイトURL（baseUrl） | `https://rank-guide.com` |
| 記事URL | 各LPのURL（例: `https://rank-guide.com/shinsa5/index.php`） |
| 共有トークン（token） | `alm/config.php` に設定した値 |

## 仕組み・前提

- **1ディレクトリ＝1LP**。`index.php` と `search-result.php`（診断結果）は同じ順位設定を共有する
- ブランドは各ブロック内の `/ad/○○.html`（`/ad-xxx/○○.html` も可）のファイル名から自動判別
- 順位データは `alm/data/state.php` に保存（PHPガード付きJSON。直接アクセスしても中身は出ない）
- 緊急時は `config.php` の `'disabled_all' => true` で全LPの変換を止められる（元の表示に戻る）
- `data-rank="topic_◯◯"` / `data-rank="◯◯"`（WP版と同じ記法）での明示指定にも対応

## ローカルでの動作テスト

```bash
# docroot/ に alm/ とLP（index.php）を置いて:
docker run --rm -p 8099:8099 -v $(pwd)/docroot:/app -w /app php:8.2-cli php -S 0.0.0.0:8099 -t /app

# 順位の取得
curl "http://localhost:8099/alm/api.php?rest_route=/alm/v1/ranking&url=http://localhost:8099/shinsa5/index.php" \
  -H "X-ALM-Token: <token>"

# 順位の保存
curl -X POST "http://localhost:8099/alm/api.php?rest_route=/alm/v1/ranking" \
  -H "X-ALM-Token: <token>" -H "Content-Type: application/json" \
  -d '{"url":"http://localhost:8099/shinsa5/index.php","order":["mobit","lake","promise"],"hidden":[]}'
```

## APIルート（WP版と同一）

`GET/POST /alm/api.php?rest_route=/alm/v1/<route>`（認証: `X-ALM-Token` ヘッダ）

| ルート | メソッド | 役割 |
|---|---|---|
| `ranking` | GET / POST | 順位の取得／保存（order・hidden） |
| `article` | GET | 案件一覧（ブランド自動検出） |
| `comment-out` | POST | 1案件のリンク停止切替 |
| `article/sync` | POST | リンク停止の一括反映 |
| `campaigns` | GET / POST | 詳細設定の取得／保存 |
| `campaign/delete` | POST | 案件の削除 |
| `scan-links` | GET | 記事内広告リンクの一覧 |
