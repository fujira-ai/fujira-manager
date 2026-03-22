<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$uid = trim((string) ($_GET['uid'] ?? ''));
$checkoutUrl = '';

if ($uid !== '') {
    $checkoutUrl = rtrim((string) ($config['app']['base_url'] ?? ''), '/')
        . '/stripe/checkout.php?uid=' . urlencode($uid);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LINE AI秘書 — フジラマネージャー</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  html { scroll-behavior: smooth; }
</style>
</head>
<body class="bg-white text-gray-800 font-sans antialiased">

<?php if ($uid === ''): ?>
<!-- uid なしエラー -->
<div class="min-h-screen flex items-center justify-center px-6">
  <div class="text-center">
    <p class="text-gray-500 text-sm">LINEからアクセスしてください</p>
  </div>
</div>

<?php else: ?>

<!-- ========== HERO ========== -->
<section class="bg-gradient-to-b from-blue-50 to-white px-6 pt-16 pb-14 text-center">
  <p class="text-blue-500 text-sm font-semibold tracking-widest uppercase mb-4">LINE AI秘書</p>
  <h1 class="text-3xl font-bold leading-snug text-gray-900 mb-3">
    考えなくても<br>タスク管理できる
  </h1>
  <p class="text-xl font-semibold text-gray-700 mb-2">抜け漏れゼロへ</p>
  <p class="text-gray-500 text-sm mb-8">月額980円（1日約32円）</p>
  <a href="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') ?>"
     class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-bold text-lg px-10 py-4 rounded-full shadow-md transition">
    30秒でタスク無制限にする
  </a>
  <p class="text-sm text-gray-500 mt-4">※いつでも解約できます</p>
</section>

<!-- ========== BENEFIT ========== -->
<section class="px-6 py-14 max-w-sm mx-auto">
  <h2 class="text-lg font-bold text-center text-gray-700 mb-8">できること</h2>
  <ul class="space-y-5">
    <li class="flex items-start gap-4">
      <span class="text-blue-400 text-xl mt-0.5">✓</span>
      <div>
        <p class="font-semibold text-gray-800">LINEだけで完結</p>
        <p class="text-gray-500 text-sm mt-0.5">アプリ不要。普段使いのLINEで管理できる</p>
      </div>
    </li>
    <li class="flex items-start gap-4">
      <span class="text-blue-400 text-xl mt-0.5">✓</span>
      <div>
        <p class="font-semibold text-gray-800">タスクを自動整理</p>
        <p class="text-gray-500 text-sm mt-0.5">AIが期限・優先度を自動で分類する</p>
      </div>
    </li>
    <li class="flex items-start gap-4">
      <span class="text-blue-400 text-xl mt-0.5">✓</span>
      <div>
        <p class="font-semibold text-gray-800">毎日リマインド</p>
        <p class="text-gray-500 text-sm mt-0.5">今日やることを毎朝通知してくれる</p>
      </div>
    </li>
    <li class="flex items-start gap-4">
      <span class="text-blue-400 text-xl mt-0.5">✓</span>
      <div>
        <p class="font-semibold text-gray-800">抜け漏れ防止</p>
        <p class="text-gray-500 text-sm mt-0.5">期限前に自動でアラートを送る</p>
      </div>
    </li>
  </ul>
</section>

<!-- ========== BEFORE / AFTER ========== -->
<section class="bg-blue-50 px-6 py-14">
  <h2 class="text-lg font-bold text-center text-gray-700 mb-8">使う前・使った後</h2>
  <div class="max-w-sm mx-auto grid grid-cols-2 gap-4">

    <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
      <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">Before</p>
      <ul class="space-y-3 text-sm text-gray-600">
        <li class="flex items-start gap-2">
          <span class="text-red-300 mt-0.5">✗</span>
          <span>やること<br>忘れる</span>
        </li>
        <li class="flex items-start gap-2">
          <span class="text-red-300 mt-0.5">✗</span>
          <span>管理が<br>続かない</span>
        </li>
      </ul>
    </div>

    <div class="bg-blue-500 rounded-2xl p-5 shadow-sm">
      <p class="text-xs font-bold text-blue-200 uppercase tracking-wider mb-4">After</p>
      <ul class="space-y-3 text-sm text-white">
        <li class="flex items-start gap-2">
          <span class="text-blue-200 mt-0.5">✓</span>
          <span>勝手に<br>整理される</span>
        </li>
        <li class="flex items-start gap-2">
          <span class="text-blue-200 mt-0.5">✓</span>
          <span>何も考え<br>なくていい</span>
        </li>
      </ul>
    </div>

  </div>
</section>

<!-- ========== CTA（再掲） ========== -->
<section class="px-6 py-16 text-center">
  <p class="text-gray-500 text-sm mb-2">月額980円 / いつでも解約可</p>
  <h2 class="text-2xl font-bold text-gray-900 mb-8">今日から始めてみませんか？</h2>
  <a href="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') ?>"
     class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-bold text-lg px-10 py-4 rounded-full shadow-md transition">
    30秒でタスク無制限にする
  </a>
  <p class="text-sm text-gray-500 mt-4">※いつでも解約できます</p>
</section>

<!-- ========== FOOTER ========== -->
<footer class="border-t border-gray-100 px-6 py-8 text-center">
  <p class="text-gray-400 text-xs">© Fujira Manager</p>
</footer>

<?php endif; ?>
</body>
</html>
