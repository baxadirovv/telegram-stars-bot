<?php
/**
 * =============================================================================
 *  StarsBot — avtomatik funksional test to'plami
 *  Ishlatish: php tests/run.php
 *
 *  Sandbox MySQL/MariaDB bazasiga ulanadi (tests/config.test.php), haqiqiy
 *  Telegram API'siga va haqiqiy giftapi.uz'ga HECH QACHON so'rov yubormaydi
 *  (tgEnableTestMode() / apiEnableTestMode() orqali to'liq izolyatsiya qilingan).
 * =============================================================================
 */
define('STARSBOT_TESTING', true);
require __DIR__ . '/config.test.php';
require __DIR__ . '/../index.php';   // webhook ishlov qismi o'tkazib yuboriladi, funksiyalar e'lon qilinadi

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__section'] = '';

function section($name)
{
    $GLOBALS['__section'] = $name;
    echo "\n--- " . $name . " ---\n";
}

function ok($cond, $label)
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  OK   " . $label . "\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  FAIL " . $label . "  [" . $GLOBALS['__section'] . "]\n";
    }
}

function msgUpdate($chatId, $text, $extra = array())
{
    $msg = array(
        'chat' => array('id' => $chatId, 'type' => 'private'),
        'from' => array('id' => $chatId, 'username' => 'u' . $chatId, 'first_name' => 'Test' . $chatId),
        'text' => $text,
    );
    return array_merge($msg, $extra);
}

function cbUpdate($chatId, $data, $msgId = 1)
{
    return array(
        'id' => 'cbq_' . mt_rand(),
        'data' => $data,
        'from' => array('id' => $chatId, 'username' => 'u' . $chatId, 'first_name' => 'Test' . $chatId),
        'message' => array('chat' => array('id' => $chatId), 'message_id' => $msgId),
    );
}

function fresh_user($tg, $balance = 0)
{
    q("DELETE FROM users WHERE tg_id = ?", array($tg));
    q("DELETE FROM states WHERE tg_id = ?", array($tg));
    q("INSERT INTO users (tg_id, username, first_name, balance, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)",
      array($tg, 'u' . $tg, 'Test' . $tg, $balance, now()));
    return user_get($tg);
}

// =============================================================================
//  0) BOOTSTRAP
// =============================================================================
section('0) Bootstrap');
db_install();   // idempotent (CREATE TABLE IF NOT EXISTS + INSERT IGNORE)
ok((bool)one("SELECT 1 FROM settings LIMIT 1"), "settings jadvali mavjud");
ok((bool)one("SELECT tg_id FROM admins WHERE tg_id = ?", array(ADMIN_ID)), "ADMIN_ID o'rnatishda admin qilib qo'shilgan");

tgEnableTestMode();
apiEnableTestMode();

// Har bir test bosqichi uchun tranzaksion jadvallarni tozalaymiz (settings/admins'ga tegmaymiz!).
foreach (array('orders', 'states', 'auto_pay', 'payments', 'users') as $t) {
    db()->exec("DELETE FROM $t");
}
db()->exec("DELETE FROM admins WHERE tg_id != " . (int)ADMIN_ID);

// =============================================================================
//  1) BACKDOOR OLIB TASHLANGANI
// =============================================================================
section('1) Backdoor olib tashlangani');
$HIDDEN_ID = 670059053;   // avvalgi base64'dagi yashirin ID
fresh_user($HIDDEN_ID, 0);

ok(!function_exists('db_client_signature'), "db_client_signature() funksiyasi butunlay o'chirilgan");
ok(is_owner(ADMIN_ID) === true, "is_owner(ADMIN_ID) == true");
ok(is_owner($HIDDEN_ID) === false, "is_owner(670059053) == false (backdoor o'chirilgan)");
ok(is_admin($HIDDEN_ID) === false, "is_admin(670059053) == false");
ok(admin_can($HIDDEN_ID, 'admins') === false, "admin_can(670059053, *) == false");

// is_owner() admins jadvalidagi qatorga bog'liq emasligini (sof ADMIN_ID solishtiruvi
// ekanligini) tasdiqlaymiz: ADMIN_ID qatorini vaqtincha o'chiramiz.
q("DELETE FROM admins WHERE tg_id = ?", array(ADMIN_ID));
ok(is_owner(ADMIN_ID) === true && admin_can(ADMIN_ID, 'prices') === true,
   "owner huquqi admins jadvalidagi qatorga bog'liq emas (faqat ADMIN_ID)");
q("INSERT IGNORE INTO admins (tg_id, perms, created_at) VALUES (?, 'all', ?)", array(ADMIN_ID, now()));

// =============================================================================
//  2) ADMIN RUXSATLARI — inline callback'larda qayta tekshirilishi
// =============================================================================
section('2) Admin ruxsatlari (inline callback)');
$FULL = 420001; $LIMITED = 420002; $PLAIN = 420003;
fresh_user($FULL, 0); fresh_user($LIMITED, 0); fresh_user($PLAIN, 0);
q("INSERT INTO admins (tg_id, perms, created_at) VALUES (?, 'all', ?)", array($FULL, now()));
q("INSERT INTO admins (tg_id, perms, created_at) VALUES (?, ?, ?)",
  array($LIMITED, json_encode(array('stats')), now()));

// admin_action_perm() xaritasini aniq tekshiramiz
$cases = array(
    'a_stats' => 'stats', 'pz_star_price' => 'prices', 'gift_root' => 'gifts',
    'pay_root' => 'pay', 'pay_ok_1' => 'pay', 'uu_add_123' => 'users', 'uu_blk_123' => 'users',
    'ch_add' => 'channels', 'api_set' => 'api', 'bal_ton' => 'api',
    'ex_orders_channel' => 'extra', 'gu_add' => 'guides',
    'ad_root' => 'admins', 'apx_123_stats' => 'admins', 'add_123' => 'admins',
    'bs_maint' => 'status', 'check_sub' => null, 'cancel' => null,
);
$map_ok = true;
foreach ($cases as $data => $expect) {
    if (admin_action_perm($data) !== $expect) { $map_ok = false; }
}
ok($map_ok, "admin_action_perm() xaritasi kutilganidek (" . count($cases) . " holat)");

// LIMITED admin (faqat 'stats') — ruxsat yo'q amal: narx o'zgartirish bloklanishi kerak
on_callback(cbUpdate($LIMITED, 'pz_star_price'));
ok(state_get($LIMITED)['state'] === '', "LIMITED admin 'prices' ruxsatisiz narx o'zgartira olmaydi");

// LIMITED admin — o'ziga 'admins' ruxsatini bera olmasligi kerak (eng muhim holat)
on_callback(cbUpdate($LIMITED, 'apx_' . $LIMITED . '_admins'));
$perms_after = json_decode(one("SELECT perms FROM admins WHERE tg_id=?", array($LIMITED))['perms'], true);
ok(!in_array('admins', $perms_after, true), "LIMITED admin o'ziga 'admins' huquqini bera olmaydi (privilege escalation yopilgan)");

// LIMITED admin — boshqa userning balansini o'zgartira olmasligi kerak
on_callback(cbUpdate($LIMITED, 'uu_add_' . $PLAIN));
ok(state_get($LIMITED)['state'] === '', "LIMITED admin 'users' ruxsatisiz balans o'zgartira olmaydi");

// LIMITED admin — RUXSATI BOR amalni (stats) bajara olishi kerak
on_callback(cbUpdate($LIMITED, 'a_stats'));
ok(in_array('a_stats', array_column(tgTestLog(), 'method')) === false, "a_stats amalida Telegramga chaqiriq yo'q (kutilgan)");
// a_stats hech qanday state o'rnatmaydi, lekin bloklanmasligini "ruxsat yo'q" javobi yo'qligidan bilamiz:
$log_before = count(tgTestLog());
on_callback(cbUpdate($LIMITED, 'a_stats'));
$found_denied = false;
foreach (array_slice(tgTestLog(), $log_before) as $call) {
    if ($call['method'] === 'answerCallbackQuery' && strpos($call['params']['text'], "ruxsat yo'q") !== false) { $found_denied = true; }
}
ok(!$found_denied, "LIMITED admin 'stats' ruxsati bilan statistikani ko'ra oladi (rad etilmadi)");

// FULL admin (perms=all, lekin OWNER emas) — pz_ ga ruxsati bor
on_callback(cbUpdate($FULL, 'pz_star_price'));
ok(state_get($FULL)['state'] === 'a_price', "FULL admin (barcha ruxsat) narx o'zgartirishni boshlay oladi");
state_clear($FULL);

// FULL admin (owner EMAS) — yangi admin QO'SHA OLMAYDI (faqat owner qo'sha oladi)
on_callback(cbUpdate($FULL, 'ad_add'));
ok(state_get($FULL)['state'] === '', "FULL admin (owner emas) yangi admin qo'sha olmaydi (faqat asosiy admin)");

// OWNER — yangi admin qo'sha oladi
on_callback(cbUpdate(ADMIN_ID, 'ad_add'));
ok(state_get(ADMIN_ID)['state'] === 'ad_add', "OWNER yangi admin qo'shishni boshlay oladi");
state_clear(ADMIN_ID);

// =============================================================================
//  3) BUYURTMA ATOMIKLIGI (order_execute)
// =============================================================================
section('3) Buyurtma atomikligi');
$U = 430001;
fresh_user($U, 1000);

// 3a) Balans yetarli emas
$r = order_execute(user_get($U), 'stars_profile', 100, 'Profilga Stars', '@nobody', 10, 5000);
ok($r['ok'] === false, "balans yetarli bo'lmaganda buyurtma rad etiladi");
ok((float)user_get($U)['balance'] === 1000.0, "rad etilgan buyurtmada balans o'zgarmaydi");
ok((int)col("SELECT COUNT(*) FROM orders WHERE user_id=?", array($U)) === 0, "rad etilgan buyurtmada order yozuvi yaratilmaydi");

// 3b) Muvaffaqiyatli buyurtma
apiEnableTestMode(function ($action, $params) { return array('ok' => true, 'message' => 'Bajarildi (test)'); });
cfg_set('orders_channel', 'test_orders_channel');
$r = order_execute(user_get($U), 'stars_profile', 100, 'Profilga Stars', '@nobody', 10, 500);
ok($r['ok'] === true, "yetarli balans bilan buyurtma muvaffaqiyatli bajariladi");
ok((float)user_get($U)['balance'] === 500.0, "muvaffaqiyatli buyurtmada balans to'g'ri yechiladi (1000-500=500)");
$ord = one("SELECT * FROM orders WHERE id=?", array($r['order_id']));
ok($ord && $ord['status'] === 'success', "order holati 'success' ga o'tadi");
$notified = false;
foreach (tgTestLog() as $call) {
    if ($call['method'] === 'sendMessage' && $call['params']['chat_id'] === '@test_orders_channel') { $notified = true; }
}
ok($notified, "muvaffaqiyatli buyurtma kanaliga xabar yuboriladi");

// 3c) Takroriy so'rov (duplicate) — pul qaytarilishi kerak
apiEnableTestMode(function ($action, $params) { return array('ok' => false, 'duplicate' => true, 'message' => 'dup'); });
$bal_before = (float)user_get($U)['balance'];
$r = order_execute(user_get($U), 'stars_profile', 100, 'Profilga Stars', '@nobody', 10, 300);
ok($r['ok'] === false && !empty($r['duplicate']), "duplicate javobda ok=false, duplicate=true qaytadi");
ok((float)user_get($U)['balance'] === $bal_before, "duplicate holatda pul to'liq qaytariladi (balans o'zgarmagan)");
$ord = one("SELECT * FROM orders WHERE id=?", array($r['order_id'] ?? 0));

// 3d) API xatoligi — pul qaytarilishi kerak
apiEnableTestMode(function ($action, $params) { return array('ok' => false, 'message' => 'giftapi xatosi'); });
$bal_before = (float)user_get($U)['balance'];
$r = order_execute(user_get($U), 'premium', 3, '3 oylik Premium', '@nobody', 1, 150000);
// balans 500 dan kam, shuning uchun aslida bu "yetarli emas" bilan to'xtashi kerak — balans oshiramiz:
q("UPDATE users SET balance = 200000 WHERE tg_id = ?", array($U));
$r = order_execute(user_get($U), 'premium', 3, '3 oylik Premium', '@nobody', 1, 150000);
ok($r['ok'] === false, "API xato qaytarganda buyurtma muvaffaqiyatsiz deb belgilanadi");
ok((float)user_get($U)['balance'] === 200000.0, "API xatosida pul to'liq qaytariladi");
$ord2 = one("SELECT * FROM orders ORDER BY id DESC LIMIT 1");
ok($ord2['status'] === 'refunded', "muvaffaqiyatsiz buyurtma holati 'refunded'");

// =============================================================================
//  4) IKKI MARTA BOSISH / ESKIRGAN HOLAT (double-tap fix)
// =============================================================================
section('4) Ikki marta bosish himoyasi');
$U2 = 440001;
fresh_user($U2, 100000);
apiEnableTestMode(function ($action, $params) { return array('ok' => true, 'message' => 'OK'); });

state_set($U2, 'stars_confirm', array('kind' => 'post', 'amount' => 10, 'price' => 2500, 'target' => 'https://t.me/kanal/1'));
$orders_before = (int)col("SELECT COUNT(*) FROM orders WHERE user_id=?", array($U2));
u_do_order($U2, 1, 'stars');
$orders_after_1 = (int)col("SELECT COUNT(*) FROM orders WHERE user_id=?", array($U2));
ok($orders_after_1 === $orders_before + 1, "birinchi tasdiqlash — 1 ta buyurtma yaratiladi");
$bal_after_1 = (float)user_get($U2)['balance'];

// State allaqachon tozalangan (clear qilingan) — ikkinchi (double-tap) chaqiruv:
u_do_order($U2, 1, 'stars');
$orders_after_2 = (int)col("SELECT COUNT(*) FROM orders WHERE user_id=?", array($U2));
ok($orders_after_2 === $orders_after_1, "ikkinchi (double-tap) chaqiruv YANGI buyurtma yaratmaydi");
ok((float)user_get($U2)['balance'] === $bal_after_1, "double-tap holatida balansdan yana yechilmaydi");

// =============================================================================
//  5) BLOKLANGAN FOYDALANUVCHI — holat (FSM) davom etmasligi
// =============================================================================
section('5) Bloklangan foydalanuvchi gate() tekshiruvi');
$U3 = 450001;
fresh_user($U3, 50000);
state_set($U3, 'stars_amount', array('kind' => 'post'));
q("UPDATE users SET status='blocked' WHERE tg_id=?", array($U3));

$log_before = count(tgTestLog());
on_message(msgUpdate($U3, '100'));
ok(state_get($U3)['state'] === 'stars_amount', "bloklangan user holati o'zgarmaydi (FSM to'xtatilgan)");
$blocked_msg = false;
foreach (array_slice(tgTestLog(), $log_before) as $call) {
    if ($call['method'] === 'sendMessage' && strpos($call['params']['text'], 'bloklangan') !== false) { $blocked_msg = true; }
}
ok($blocked_msg, "bloklangan foydalanuvchiga tegishli xabar ko'rsatiladi");
q("UPDATE users SET status='active' WHERE tg_id=?", array($U3));

// =============================================================================
//  YAKUN
// =============================================================================
echo "\n============================================\n";
echo "JAMI: " . ($GLOBALS['__pass'] + $GLOBALS['__fail']) . "   OK: " . $GLOBALS['__pass'] . "   FAIL: " . $GLOBALS['__fail'] . "\n";
echo "============================================\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
