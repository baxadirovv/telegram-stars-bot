<?php
/**
 * =============================================================================
 *  REKLAMA TARQATGICH  (1 daqiqalik cron)
 *    * * * * * php /path/to/send.php
 *  Har chaqiruvda 100 ta foydalanuvchiga yuboriladi.
 * =============================================================================
 */
@ini_set('display_errors', '0');
date_default_timezone_set('Asia/Tashkent');
@set_time_limit(0);

require __DIR__ . '/config.php';
require __DIR__ . '/core/db.php';
require __DIR__ . '/core/tg.php';

define('BATCH', 100);

try {
    db_ensure();
} catch (Exception $e) {
    exit("Baza xatosi: " . $e->getMessage() . "\n");
}

// Bir vaqtda faqat bitta jarayon ishlashi uchun qulf
$lock = fopen(__DIR__ . '/send.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Avvalgi jarayon hali tugamagan.\n");
}

$b = one("SELECT * FROM broadcasts WHERE status = 'active' ORDER BY id DESC LIMIT 1");
if (!$b) {
    flock($lock, LOCK_UN);
    exit("Faol reklama yo'q.\n");
}

$users = all_rows(
    "SELECT tg_id FROM users
     WHERE status = 'active' AND tg_id > ?
     ORDER BY tg_id ASC LIMIT " . BATCH,
    array((int)$b['last_user_id']));

if (!$users) {
    q("UPDATE broadcasts SET status = 'done' WHERE id = ?", array($b['id']));
    // Adminlarga hisobot
    foreach (all_rows("SELECT tg_id FROM admins") as $a) {
        say($a['tg_id'],
            "✅ <b>REKLAMA TARQATILDI</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "📨 Yetkazildi: <b>" . money($b['sent_ok']) . "</b>\n"
            . "🚫 Yetkazilmadi: <b>" . money($b['sent_fail']) . "</b>");
    }
    flock($lock, LOCK_UN);
    exit("Tarqatish yakunlandi.\n");
}

$ok = 0; $fail = 0; $last = (int)$b['last_user_id'];
foreach ($users as $u) {
    $last = (int)$u['tg_id'];
    $r = tg('copyMessage', array(
        'chat_id'      => $last,
        'from_chat_id' => (int)$b['from_chat_id'],
        'message_id'   => (int)$b['message_id'],
    ));
    if (!empty($r['ok'])) { $ok++; } else { $fail++; }
    usleep(60000);   // ~16 xabar/soniya — Telegram cheklovidan pastda
}

q("UPDATE broadcasts SET last_user_id = ?, sent_ok = sent_ok + ?, sent_fail = sent_fail + ?
   WHERE id = ?", array($last, $ok, $fail, $b['id']));

flock($lock, LOCK_UN);
echo "Yuborildi: $ok, xato: $fail\n";
