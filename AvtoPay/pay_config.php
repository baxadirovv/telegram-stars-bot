<?php
/**
 * =============================================================================
 *  AVTO TO'LOV QABUL QILGICH
 *
 *  So'rov ko'rinishi:
 *    https://domen.uz/AvtoPay/pay_config.php?miqdor=1000.00&method=CardXabarBot
 *
 *  Ishlash tartibi:
 *    1) `auto_pay` jadvalidan holati `pending` bo'lgan va summasi AYNAN mos
 *       keladigan to'lov qidiriladi (eng eskisidan boshlab).
 *    2) Topilsa — foydalanuvchi balansiga pul qo'shiladi, holat `completed`.
 *    3) Foydalanuvchiga darhol Telegram xabari yuboriladi.
 * =============================================================================
 */
@ini_set('display_errors', '0');
date_default_timezone_set('Asia/Tashkent');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';
require __DIR__ . '/../core/db.php';
require __DIR__ . '/../core/tg.php';

function out($a, $code = 200)
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Maxfiy token tekshiruvi -------------------------------------------
// Token yo'q/bo'sh bo'lsa, so'rov HAR DOIM rad etiladi (fail-closed) — aks holda
// istalgan odam shu manzilni to'g'ridan-to'g'ri ochib, haqiqiy to'lov qilmasdan
// balansini "avtomatik to'ldirilgan" deb ko'rsatib qo'yishi mumkin edi.
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (!defined('AVTOPAY_TOKEN') || AVTOPAY_TOKEN === '' || !hash_equals(AVTOPAY_TOKEN, $token)) {
    out(array('ok' => false, 'message' => 'Ruxsat etilmagan so\'rov (token noto\'g\'ri yoki sozlanmagan).'), 403);
}

try { db_ensure(); }
catch (Exception $e) { out(array('ok' => false, 'message' => 'Baza xatosi.'), 500); }

$miqdor = isset($_GET['miqdor']) ? (float)str_replace(array(' ', ','), array('', '.'), $_GET['miqdor']) : 0;
$method = isset($_GET['method']) ? trim($_GET['method']) : '';

$ruxsat = array('CardXabarBot', 'HUMOcardbot');
if ($method === '' || !in_array($method, $ruxsat, true)) {
    out(array('ok' => false, 'message' => "method noto'g'ri. Ruxsat: " . implode(', ', $ruxsat)), 400);
}
if ($miqdor <= 0) {
    out(array('ok' => false, 'message' => 'miqdor noto\'g\'ri.'), 400);
}

// Eskirgan arizalarni yopamiz (24 soatdan oshgan)
q("UPDATE auto_pay SET status = 'expired'
   WHERE status = 'pending' AND created_at < ?", array(date('Y-m-d H:i:s', strtotime('-24 hours'))));

// Aynan mos keladigan to'lovni qidiramiz — eng eskisi birinchi
$row = one("SELECT * FROM auto_pay
            WHERE status = 'pending' AND amount = ?
            ORDER BY id ASC LIMIT 1", array($miqdor));

if (!$row) {
    logg('AvtoPay: mos to\'lov topilmadi — ' . $miqdor . ' (' . $method . ')');
    out(array('ok' => false, 'message' => 'Mos keladigan kutilayotgan to\'lov topilmadi.',
              'miqdor' => $miqdor, 'method' => $method), 404);
}

// Poyga holatidan himoya: faqat hali `pending` bo'lsa yangilaymiz
$upd = q("UPDATE auto_pay SET status = 'completed', method = ?, completed_at = ?
          WHERE id = ? AND status = 'pending'", array($method, now(), $row['id']));
if ($upd->rowCount() === 0) {
    out(array('ok' => false, 'message' => 'Bu to\'lov allaqachon tasdiqlangan.'), 409);
}

balance_add($row['user_id'], $row['amount'], true);
$u = user_get($row['user_id']);

say($row['user_id'],
    "⚡️ <b>TO'LOV AVTOMATIK TASDIQLANDI</b>\n"
    . "━━━━━━━━━━━━━━━━━━━━\n\n"
    . "💵 Qo'shildi: <b>" . money($row['amount']) . " so'm</b>\n"
    . "💰 Joriy balans: <b>" . money($u ? $u['balance'] : 0) . " so'm</b>\n\n"
    . "Xaridingiz uchun rahmat! 🎉");

logg('AvtoPay OK: user=' . $row['user_id'] . ' summa=' . $row['amount'] . ' method=' . $method);

out(array('ok' => true, 'message' => 'To\'lov tasdiqlandi.',
    'payment_id' => (int)$row['id'], 'user_id' => (int)$row['user_id'],
    'miqdor' => (float)$row['amount'], 'method' => $method,
    'balance' => $u ? (float)$u['balance'] : 0));
