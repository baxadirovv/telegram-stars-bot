<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../core/db.php';
db_ensure();
$base = rtrim(cfg('base_url', ''), '/') . '/api/';
$sp = number_format((float)cfg('star_price', 250), 0, '.', ' ');
?><!DOCTYPE html>
<html lang="uz"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API hujjatlari</title>
<style>
:root{--bg:#0b0e17;--card:#141a29;--b:#232c43;--tx:#e8ecf7;--mu:#8c97b5;--ac:#7b61ff;--gr:#34e0b0;--go:#ffc24b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);
font:15px/1.65 -apple-system,Segoe UI,Roboto,sans-serif;padding:24px 16px}
.w{max-width:840px;margin:0 auto}h1{font-size:26px;margin:0 0 6px}
h2{font-size:19px;margin:34px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--b)}
.mu{color:var(--mu)}.card{background:var(--card);border:1px solid var(--b);border-radius:14px;padding:16px;margin:12px 0}
code,pre{font-family:ui-monospace,Menlo,Consolas,monospace}
pre{background:#080b12;border:1px solid var(--b);border-radius:10px;padding:12px;overflow-x:auto;font-size:13px}
code{background:#080b12;padding:2px 6px;border-radius:5px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:14px;margin-top:8px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--b)}
th{color:var(--mu);font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.b{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600}
.g{background:rgba(52,224,176,.15);color:var(--gr)}.o{background:rgba(255,194,75,.15);color:var(--go)}
.v{background:rgba(123,97,255,.15);color:#a995ff}
</style></head><body><div class="w">

<h1>📘 API hujjatlari</h1>
<p class="mu">Bitta so'rov bilan Stars, Premium va Giftlarni avtomatik soting.</p>

<div class="card">
  <b>🌐 Manzil</b><pre><?= htmlspecialchars($base) ?></pre>
  <b>📡 Usul</b> — <span class="b v">GET</span>, javob <span class="b v">JSON</span><br>
  <b>🔑 Autentifikatsiya</b> — har bir so'rovda <code>api_key</code><br>
  <b>💵 To'lov</b> — bot balansingizdan yechiladi
</div>

<h2>1 · balance — balansni bilish</h2>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=balance

{"ok":true,"action":"balance","balance":125000,"currency":"UZS"}</pre>

<h2>2 · services — xizmatlar ro'yxati</h2>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=services

{"ok":true,"services":[
  {"service_id":100,"name":"Profilga Stars","price":<?= (int)cfg('star_price',250) ?>,"min":50,"max":10000000},
  {"service_id":200,"name":"Postga Stars","price":<?= (int)cfg('star_price',250) ?>,"min":1,"max":10000},
  {"service_id":3,"name":"3 oylik Premium","price":150000,"min":1,"max":1},
  {"service_id":"gift_1","name":"🧸 Ayiqcha","price":3000,"min":1,"max":1}
]}</pre>

<h2>3 · order — buyurtma berish</h2>
<table>
<tr><th>Parametr</th><th>Majburiy</th><th>Izoh</th></tr>
<tr><td><code>api_key</code></td><td><span class="b o">Ha</span></td><td>API kalitingiz</td></tr>
<tr><td><code>action</code></td><td><span class="b o">Ha</span></td><td><code>order</code></td></tr>
<tr><td><code>service_id</code></td><td><span class="b o">Ha</span></td><td><code>100</code> profil stars · <code>200</code> post stars · <code>3/6/12</code> premium · <code>gift_ID</code></td></tr>
<tr><td><code>target</code></td><td><span class="b o">Ha</span></td><td>Stars/Gift/Premium uchun <code>@username</code>, post uchun havola</td></tr>
<tr><td><code>amount</code></td><td>Yo'q</td><td>Faqat Stars uchun (nechta)</td></tr>
</table>

<p><b>Namuna — profilga 100 ta Stars:</b></p>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=order&service_id=100&target=@durov&amount=100

{"ok":true,"order_id":57,"status":"success","charged":<?= 100*(int)cfg('star_price',250) ?>,"balance":100000}</pre>

<p><b>Namuna — postga Stars:</b></p>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=order&service_id=200&target=https://t.me/kanal/348&amount=10</pre>

<p><b>Namuna — 3 oylik Premium:</b></p>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=order&service_id=3&target=@durov</pre>

<h2>4 · status — buyurtma holati</h2>
<pre>GET <?= htmlspecialchars($base) ?>?api_key=KALIT&action=status&order_id=57</pre>

<h2>🧯 Xatolik kodlari</h2>
<table>
<tr><th>error</th><th>HTTP</th><th>Ma'nosi</th></tr>
<tr><td><code>no_key</code></td><td>401</td><td>api_key yuborilmagan</td></tr>
<tr><td><code>invalid_key</code></td><td>401</td><td>Kalit noto'g'ri yoki bekor qilingan</td></tr>
<tr><td><code>blocked</code></td><td>403</td><td>Hisobingiz bloklangan</td></tr>
<tr><td><code>insufficient_funds</code></td><td>402</td><td>Balans yetarli emas</td></tr>
<tr><td><code>bad_amount</code></td><td>400</td><td>amount chegaradan tashqarida</td></tr>
<tr><td><code>bad_target</code></td><td>400</td><td>target formati noto'g'ri</td></tr>
<tr><td><code>not_found</code></td><td>404</td><td>service_id yoki buyurtma topilmadi</td></tr>
<tr><td><code>duplicate</code></td><td>200</td><td>Aynan shu buyurtma yaqinda yuborilgan</td></tr>
<tr><td><code>failed</code></td><td>200</td><td>Bajarilmadi — pul qaytarildi</td></tr>
</table>

<div class="card" style="margin-top:26px">
  <b>ℹ️ Muhim</b><br>
  <span class="mu">
  ▫️ Buyurtma bajarilmasa pul <b>avtomatik qaytariladi</b>.<br>
  ▫️ Premium va profil Stars 20–30 soniya davom etishi mumkin — timeout'ni
     kamida 120 soniya qo'ying.<br>
  ▫️ 1 ta Stars narxi hozir: <b><?= $sp ?> so'm</b>.
  </span>
</div>

<p class="mu" style="text-align:center;margin:30px 0 10px;font-size:13px">
  Kalitni botdagi <b>🔑 Hamkorlik ( API )</b> bo'limidan olasiz.
</p>
</div></body></html>
