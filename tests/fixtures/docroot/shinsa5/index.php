<?php require $_SERVER['DOCUMENT_ROOT'] . '/alm/render.php'; ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>審査が不安な人向けカードローン比較（テスト用LP）</title>
</head>
<body>

<!-- ピックアップ（迷ったらココ！）: ブランドごとのバリアントを並べておくと表示順1位のものだけ出る -->
<section class="best3">
<div class="best3__first" id="pick-promise">
<p class="best3__first-catch">迷ったらココ！</p>
<div class="best3__first-head"><span class="best3__first-head-name">プロミス</span></div>
<div class="best3__first-box"><a href="https://rank-guide.com/ad/promise.html?shinsa5_re_bn" target="_blank" rel="nofollow noopener" class="best3__first-bnr"><img src="./assets/img/promise_bnr.png" alt="プロミス"></a></div>
<div class="best3__first-check">
<p class="best3__first-check-ttl">CHECK</p>
<ul class="best3__first-check-list">
<li class="best3__first-check-list-item"><span class="red">最短3分</span>審査が可能</li>
<li class="best3__first-check-list-item">スマホ完結で<span class="red">郵便物なし</span></li>
<li class="best3__first-check-list-item">初回借入の翌日から<span class="red">30日間無利息</span></li>
</ul>
</div>
</div>
<div class="best3__first" id="pick-lake">
<p class="best3__first-catch">迷ったらココ！</p>
<div class="best3__first-head"><span class="best3__first-head-name">レイク</span></div>
<div class="best3__first-box"><a href="https://rank-guide.com/ad/lake.html?shinsa5_re_bn" target="_blank" rel="nofollow noopener" class="best3__first-bnr"><img src="./assets/img/lake_bnr.png" alt="レイク"></a></div>
<ul class="best3__first-check-list"><li class="best3__first-check-list-item">最短25分</li></ul>
</div>
<div class="best3__first" id="pick-mobit">
<p class="best3__first-catch">迷ったらココ！</p>
<div class="best3__first-head"><span class="best3__first-head-name">SMBCモビット</span></div>
<div class="best3__first-box"><a href="https://rank-guide.com/ad/mobit.html?shinsa5_re_bn" target="_blank" rel="nofollow noopener" class="best3__first-bnr"><img src="./assets/img/mobit_bnr.png" alt="SMBCモビット"></a></div>
<ul class="best3__first-check-list"><li class="best3__first-check-list-item">WEB完結</li></ul>
</div>

<!-- BEST3帯 -->
<ul class="best3__list">
<li class="best3__list-item" id="b3-promise"><a href="/ad/promise.html?shinsa5_b3" class="best3__list-item-link">プロミス</a></li>
<li class="best3__list-item" id="b3-lake"><a href="/ad/lake.html?shinsa5_b3" class="best3__list-item-link">レイク</a></li>
<li class="best3__list-item" id="b3-mobit"><a href="/ad/mobit.html?shinsa5_b3" class="best3__list-item-link">SMBCモビット</a></li>
</ul>
</section>

<!-- 再掲の「迷ったらココ！」（lead__content）: ブランド1つ分しか無い型 -->
<div class="lead">
<div class="lead__content">
<p class="lead__catch">
最短3分ですぐに審査結果がわかる！<br>
<span class="lead__catch-name">プロミス</span>
</p>
<div class="lead__content-wrap">
<a href="https://rank-guide.com/ad/promise.html?shinsa5_recap_bn" target="_blank" rel="nofollow noopener" class="lead__bnr"><img src="./assets/img/promise_bnr.png" alt="プロミス"></a>
<div class="lead__box">
<ul class="lead__box-list">
<li class="lead__box-list-item">最短3分審査<span class="notes">※2</span>が可能</li>
<li class="lead__box-list-item">スマホ完結で原則電話連絡・郵便物なし</li>
<li class="lead__box-list-item">初回借入の翌日から30日間無利息</li>
</ul>
</div>
</div>
<a href="https://rank-guide.com/ad/promise.html?shinsa5_recap_btn" target="_blank" rel="nofollow noopener" class="btn btn--arrow">詳細を見る</a>
</div>
</div>

<!-- 列型の比較テーブル（thead の td にブランド） -->
<section class="best3Table">
<table class="best3Table__table table">
<thead>
<tr>
<th></th>
<td><a href="https://rank-guide.com/ad/promise.html?shinsa5_table_bn" target="_blank" rel="nofollow noopener"><span class="best3Table__table-name">プロミス</span></a></td>
<td><a href="https://rank-guide.com/ad/lake.html?shinsa5_table_bn" target="_blank" rel="nofollow noopener"><span class="best3Table__table-name">レイク</span></a></td>
<td><a href="https://rank-guide.com/ad/mobit.html?shinsa5_table_bn" target="_blank" rel="nofollow noopener"><span class="best3Table__table-name">SMBCモビット</span></a></td>
</tr>
</thead>
<tbody>
<tr>
<th>審査<br>スピード</th>
<td><div class="good"></div>最短3分</td>
<td><div class="normal"></div>最短25分</td>
<td><div class="bad"></div>最短15分</td>
</tr>
<tr>
<th>在籍確認</th>
<td>原則電話連絡なし</td>
<td>電話一切連絡なし</td>
<td>原則電話連絡なし</td>
</tr>
<tr>
<th>備考</th>
<td colspan="3">colspan行は触らない</td>
</tr>
</tbody>
</table>
</section>

<!-- 行型の比較テーブル（1行＝1ブランド） -->
<table class="rowTable">
<tbody>
<tr id="row-promise"><td>1</td><td><a href="/ad/promise.html?shinsa5_row">プロミス</a></td><td>最短3分</td></tr>
<tr id="row-lake"><td>2</td><td><a href="/ad/lake.html?shinsa5_row">レイク</a></td><td>最短25分</td></tr>
<tr id="row-mobit"><td>3</td><td><a href="/ad/mobit.html?shinsa5_row">SMBCモビット</a></td><td>最短15分</td></tr>
</tbody>
</table>

<!-- 商標コンテンツ（ranking__box）: 管理画面に出る「順位の元」 -->
<section class="ranking">
<div class="ranking__inner">
<!-- 1位 -->
<div class="ranking__box result-item" id="promise">
<div class="ranking__box-head"><a href="https://rank-guide.com/ad/promise.html?shinsa5_rank_txt" target="_blank" rel="nofollow noopener" class="ranking__box-head-name">プロミス</a></div>
<p class="ranking__box-catch">初めてなら30日間無利息</p>
<div class="ranking__box-wrap"><a href="https://rank-guide.com/ad/promise.html?shinsa5_rank_bn" target="_blank" rel="nofollow noopener" class="ranking__box-bnr"><img src="./assets/img/promise_bnr.png" alt="プロミス"></a></div>
<ul class="ranking__box-list">
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">審査時間</div><div class="ranking__box-list-item-content good">最短3分</div></li>
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">無利息期間</div><div class="ranking__box-list-item-content good">30日間</div></li>
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">実質年率</div><div class="ranking__box-list-item-content normal">4.5~17.8%</div></li>
</ul>
</div>
<!-- 2位 -->
<div class="ranking__box result-item" id="lake">
<div class="ranking__box-head"><a href="https://rank-guide.com/ad/lake.html?shinsa5_rank_txt" target="_blank" rel="nofollow noopener" class="ranking__box-head-name">レイク</a></div>
<p class="ranking__box-catch">選べる無利息</p>
<div class="ranking__box-wrap"><a href="https://rank-guide.com/ad/lake.html?shinsa5_rank_bn" target="_blank" rel="nofollow noopener" class="ranking__box-bnr"><img src="./assets/img/lake_bnr.png" alt="レイク"></a></div>
<ul class="ranking__box-list">
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">実質年率</div><div class="ranking__box-list-item-content bad">4.5~18.0%</div></li>
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">審査時間</div><div class="ranking__box-list-item-content normal">最短25分</div></li>
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">無利息期間</div><div class="ranking__box-list-item-content good">365日</div></li>
</ul>
</div>
<!-- 3位 -->
<div class="ranking__box result-item result-item-mobit" id="mobit">
<div class="ranking__box-head"><a href="https://rank-guide.com/ad/mobit.html?shinsa5_rank_txt" target="_blank" rel="nofollow noopener" class="ranking__box-head-name">SMBCモビット</a></div>
<p class="ranking__box-catch">WEB完結で電話連絡なし</p>
<div class="ranking__box-wrap"><a href="https://rank-guide.com/ad/mobit.html?shinsa5_rank_bn" target="_blank" rel="nofollow noopener" class="ranking__box-bnr"><img src="./assets/img/mobit_bnr.png" alt="SMBCモビット"></a></div>
<ul class="ranking__box-list">
<li class="ranking__box-list-item"><div class="ranking__box-list-item-head">在籍確認</div><div class="ranking__box-list-item-content good">電話連絡なし</div></li>
</ul>
</div>
<p class="ranking__box-note">※ 順位は当サイト独自の基準です。<a href="https://rank-guide.com/ad/promise.html?shinsa5_note" target="_blank" rel="nofollow noopener">公式サイト</a></p>
</div>
</section>

</body>
</html>
