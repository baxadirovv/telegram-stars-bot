<?php
/**
 * =============================================================================
 *  ADMIN PANEL
 * =============================================================================
 */

function admin_ids()
{
    $ids = array();
    if (defined('ADMIN_ID') && ADMIN_ID) { $ids[] = (string)ADMIN_ID; }
    foreach (all_rows("SELECT tg_id FROM admins") as $a) { $ids[] = (string)$a['tg_id']; }
    return array_values(array_unique($ids));
}

function a_panel($chat)
{
    state_clear($chat);
    say($chat, "🧑‍💻 <b>ADMIN PANEL</b>\n━━━━━━━━━━━━━━━━━━━━\n\nKerakli bo'limni tanlang 👇",
        kb_admin());
}

// -----------------------------------------------------------------------------
//  📊 STATISTIKA
// -----------------------------------------------------------------------------
function a_stats($chat, $mid = 0)
{
    $all     = (int)col("SELECT COUNT(*) FROM users");
    $active  = (int)col("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $blocked = (int)col("SELECT COUNT(*) FROM users WHERE status = 'blocked'");
    $d1  = (int)col("SELECT COUNT(*) FROM users WHERE created_at >= ?", array(date('Y-m-d 00:00:00')));
    $d7  = (int)col("SELECT COUNT(*) FROM users WHERE created_at >= ?", array(date('Y-m-d H:i:s', strtotime('-7 days'))));
    $d30 = (int)col("SELECT COUNT(*) FROM users WHERE created_at >= ?", array(date('Y-m-d H:i:s', strtotime('-30 days'))));

    $stars   = (int)col("SELECT COUNT(*) FROM orders WHERE status='success' AND type IN ('stars_post','stars_profile')");
    $starqty = (int)col("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status='success' AND type IN ('stars_post','stars_profile')");
    $gift    = (int)col("SELECT COUNT(*) FROM orders WHERE status='success' AND type='gift'");
    $prem    = (int)col("SELECT COUNT(*) FROM orders WHERE status='success' AND type='premium'");
    $turnover= (float)col("SELECT COALESCE(SUM(price),0) FROM orders WHERE status='success'");
    $deposit = (float)col("SELECT COALESCE(SUM(total_deposit),0) FROM users");

    $txt = "📊 <b>STATISTIKA</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "👥 <b>Foydalanuvchilar</b>\n"
        . "   ▫️ Jami: <b>" . money($all) . "</b>\n"
        . "   ▫️ Faol: <b>" . money($active) . "</b>\n"
        . "   ▫️ Bloklangan: <b>" . money($blocked) . "</b>\n\n"
        . "📈 <b>Qo'shilganlar</b>\n"
        . "   ▫️ Bugun: <b>" . money($d1) . "</b>\n"
        . "   ▫️ 1 hafta: <b>" . money($d7) . "</b>\n"
        . "   ▫️ 1 oy: <b>" . money($d30) . "</b>\n\n"
        . "🛒 <b>Sotuvlar</b>\n"
        . "   ⭐️ Stars: <b>" . money($stars) . "</b> ta buyurtma (" . money($starqty) . " ⭐️)\n"
        . "   🎁 Gift: <b>" . money($gift) . "</b> ta\n"
        . "   🌟 Premium: <b>" . money($prem) . "</b> ta\n\n"
        . "💵 <b>Moliya</b>\n"
        . "   ▫️ Aylanma: <b>" . money($turnover) . " so'm</b>\n"
        . "   ▫️ Kiritilgan: <b>" . money($deposit) . " so'm</b>";

    $kb = ikb(array(array(btn('🏆 Top 10 Xaridorlar', 'a_top10'))));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_top10($chat, $mid)
{
    $rows = all_rows(
        "SELECT u.tg_id, u.username, u.first_name, COUNT(o.id) c, COALESCE(SUM(o.price),0) s
         FROM orders o JOIN users u ON u.tg_id = o.user_id
         WHERE o.status = 'success'
         GROUP BY u.tg_id, u.username, u.first_name
         ORDER BY c DESC, s DESC LIMIT 10");
    $txt = "🏆 <b>TOP 10 XARIDORLAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$rows) {
        $txt .= "Hozircha buyurtmalar yo'q.";
    } else {
        $i = 1;
        foreach ($rows as $r) {
            $nm = $r['username'] !== '' ? '@' . $r['username'] : $r['first_name'];
            $txt .= $i . ". " . esc($nm) . " — <b>" . (int)$r['c'] . "</b> ta xarid ("
                 . money($r['s']) . " so'm)\n";
            $i++;
        }
        $txt .= "\n<i>Premium + Stars + Gift jami</i>";
    }
    edit($chat, $mid, $txt, ikb(array(array(btn('🔙 Statistika', 'a_stats')))));
}

// -----------------------------------------------------------------------------
//  💰 NARXLARNI SOZLASH
// -----------------------------------------------------------------------------
function a_prices($chat, $mid = 0)
{
    list($pmin, $pmax) = post_limits();
    $txt = "💰 <b>NARXLARNI SOZLASH</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "⭐️ 1 ta Stars: <b>" . money(cfg('star_price')) . " so'm</b>\n\n"
        . "🌟 <b>Premium tariflari</b>\n"
        . "   ▫️ 3 oy: <b>" . money(cfg('premium_3_price')) . " so'm</b>\n"
        . "   ▫️ 6 oy: <b>" . money(cfg('premium_6_price')) . " so'm</b>\n"
        . "   ▫️ 12 oy: <b>" . money(cfg('premium_12_price')) . " so'm</b>\n\n"
        . "💫 <b>Postga Stars chegarasi</b>\n"
        . "   ▫️ Eng kam: <b>" . money($pmin) . "</b>\n"
        . "   ▫️ Eng ko'p: <b>" . money($pmax) . "</b>\n\n"
        . "⭐️ Profilga Stars: <b>50 — 10 000 000</b> <i>(o'zgarmas)</i>";
    $kb = ikb(array(
        array(btn('⭐️ Stars narxi', 'pz_star_price')),
        array(btn('🌟 3 oy', 'pz_premium_3_price'), btn('🌟 6 oy', 'pz_premium_6_price'),
              btn('🌟 12 oy', 'pz_premium_12_price')),
        array(btn('💫 Post min', 'pz_post_stars_min'), btn('💫 Post max', 'pz_post_stars_max')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_price_ask($chat, $mid, $key)
{
    $names = array(
        'star_price' => '1 ta Stars narxi (so\'m)',
        'premium_3_price' => '3 oylik Premium narxi (so\'m)',
        'premium_6_price' => '6 oylik Premium narxi (so\'m)',
        'premium_12_price' => '12 oylik Premium narxi (so\'m)',
        'post_stars_min' => 'Postga Stars — eng kam miqdor',
        'post_stars_max' => 'Postga Stars — eng ko\'p miqdor',
    );
    if (!isset($names[$key])) { return; }
    state_set($chat, 'a_price', array('key' => $key));
    edit($chat, $mid, "✏️ <b>" . $names[$key] . "</b>\n\nJoriy qiymat: <b>" . money(cfg($key)) . "</b>");
    say($chat, "🔢 Yangi qiymatni yuboring:", kb_cancel());
}

function a_price_save($chat, $text, $st)
{
    $n = (int)preg_replace('/[^\d]/', '', $text);
    if ($n <= 0) { say($chat, "⚠️ Musbat raqam yuboring:", kb_cancel()); return; }
    cfg_set($st['data']['key'], $n);
    state_clear($chat);
    say($chat, "✅ Saqlandi: <b>" . money($n) . "</b>", kb_admin());
    a_prices($chat);
}

// -----------------------------------------------------------------------------
//  🛍 GIFT SOZLAMALARI
// -----------------------------------------------------------------------------
function a_gift_menu($chat, $mid = 0)
{
    $s = (int)col("SELECT COUNT(*) FROM gift_sections");
    $g = (int)col("SELECT COUNT(*) FROM gifts");
    $txt = "🛍 <b>GIFT SOZLAMALARI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
         . "📂 Bo'limlar: <b>" . $s . "</b> ta\n🎁 Giftlar: <b>" . $g . "</b> ta";
    $kb = ikb(array(
        array(btn("📂 Bo'lim sozlash", 'sec_menu')),
        array(btn('🎁 Gift sozlash', 'gift_menu')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_sec_menu($chat, $mid)
{
    $list = all_rows("SELECT * FROM gift_sections ORDER BY id");
    $txt = "📂 <b>BO'LIMLAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$list) { $txt .= "Hozircha bo'lim yo'q."; }
    foreach ($list as $s) { $txt .= "• " . esc($s['name']) . "\n"; }
    edit($chat, $mid, $txt, ikb(array(
        array(btn("➕ Yangi qo'shish", 'sec_add')),
        array(btn('✏️ Tahrirlash', 'sec_edit'), btn("🗑 O'chirish", 'sec_del')),
        array(btn('🔙 Orqaga', 'gift_root')),
    )));
}

function a_gift_list_menu($chat, $mid)
{
    $list = all_rows("SELECT g.*, s.name sname FROM gifts g LEFT JOIN gift_sections s ON s.id=g.section_id ORDER BY g.id");
    $txt = "🎁 <b>GIFTLAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$list) { $txt .= "Hozircha gift yo'q."; }
    foreach ($list as $g) {
        $txt .= "• <b>" . esc($g['name']) . "</b> — " . money($g['price']) . " so'm\n"
             . "   📂 " . esc($g['sname']) . " · id=" . esc($g['service_id']) . "\n";
    }
    edit($chat, $mid, $txt, ikb(array(
        array(btn("➕ Yangi qo'shish", 'gift_add')),
        array(btn('✏️ Tahrirlash', 'gift_edit'), btn("🗑 O'chirish", 'gift_del')),
        array(btn('🔙 Orqaga', 'gift_root')),
    )));
}

// -----------------------------------------------------------------------------
//  💳 TO'LOV TIZIMLARI
// -----------------------------------------------------------------------------
function a_pay_menu($chat, $mid = 0)
{
    $txt = "💳 <b>TO'LOV TIZIMLARI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
         . "Sozlamoqchi bo'lgan turini tanlang 👇";
    $kb = ikb(array(
        array(btn('🧾 Manual sozlama', 'pm_menu')),
        array(btn('⚡️ Avto pay sozlama', 'ap_menu')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_autopay_menu($chat, $mid)
{
    $on = cfg('autopay_enabled') === '1';
    edit($chat, $mid,
        "⚡️ <b>AVTO PAY SOZLAMA</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💳 Karta: <b>" . (cfg('autopay_card') !== '' ? esc(cfg('autopay_card')) : 'kiritilmagan') . "</b>\n"
        . "👤 Egasi: <b>" . (cfg('autopay_holder') !== '' ? esc(cfg('autopay_holder')) : 'kiritilmagan') . "</b>\n"
        . "🔌 Holati: <b>" . ($on ? '🟢 Yoqilgan' : '🔴 O\'chirilgan') . "</b>\n\n"
        . "<i>Yoqilganda foydalanuvchilarda «⚡️ Avto to'lov ( Tezkor )» usuli paydo bo'ladi.</i>",
        ikb(array(
            array(btn('💳 Karta raqam', 'ap_card')),
            array(btn('👤 Karta egasi', 'ap_holder')),
            array(btn($on ? "🔴 O'chirish" : '🟢 Yoqish', 'ap_toggle')),
            array(btn('🔙 Orqaga', 'pay_root')),
        )));
}

function a_manual_menu($chat, $mid)
{
    $list = all_rows("SELECT * FROM payment_methods ORDER BY id");
    $txt = "🧾 <b>MANUAL TO'LOV USULLARI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$list) { $txt .= "Hozircha usul yo'q."; }
    foreach ($list as $m) {
        $txt .= "• <b>" . esc($m['name']) . "</b>\n   💳 " . esc($m['card_number'])
             . "\n   👤 " . esc($m['holder']) . "\n";
    }
    edit($chat, $mid, $txt, ikb(array(
        array(btn("➕ Qo'shish", 'pm_add')),
        array(btn('✏️ Tahrirlash', 'pm_edit'), btn("🗑 O'chirish", 'pm_del')),
        array(btn('🔙 Orqaga', 'pay_root')),
    )));
}

// -----------------------------------------------------------------------------
//  ⚙️ USERNI BOSHQARISH
// -----------------------------------------------------------------------------
function a_user_ask($chat)
{
    state_set($chat, 'a_user_id', array());
    say($chat, "⚙️ <b>USERNI BOSHQARISH</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
             . "Boshqarmoqchi bo'lgan foydalanuvchining <b>Telegram ID</b> sini kiriting:", kb_cancel());
}

function a_user_card($chat, $tg_id, $mid = 0)
{
    $u = user_get($tg_id);
    if (!$u) {
        say($chat, "❌ Bunday foydalanuvchi botda topilmadi.", kb_admin());
        return;
    }
    $orders = (int)col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status='success'", array($tg_id));
    $spent  = (float)col("SELECT COALESCE(SUM(price),0) FROM orders WHERE user_id = ? AND status='success'", array($tg_id));
    $txt = "👤 <b>FOYDALANUVCHI</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🆔 ID: <code>" . (int)$u['tg_id'] . "</code>\n"
        . "👤 Ism: <b>" . esc($u['first_name']) . "</b>\n"
        . ($u['username'] !== '' ? "🔗 @" . esc($u['username']) . "\n" : '')
        . "\n💰 Balans: <b>" . money($u['balance']) . " so'm</b>\n"
        . "📥 Jami kiritgan: <b>" . money($u['total_deposit']) . " so'm</b>\n"
        . "📤 Jami sarflagan: <b>" . money($spent) . " so'm</b>\n"
        . "🛒 Buyurtmalar: <b>" . $orders . " ta</b>\n\n"
        . "🚦 Holati: <b>" . ($u['status'] === 'active' ? '🟢 Faol' : '🔴 Bloklangan') . "</b>\n"
        . "📅 Qo'shilgan: " . date('d.m.Y', strtotime($u['created_at']));
    $kb = ikb(array(
        array(btn("➕ Pul qo'shish", 'uu_add_' . $u['tg_id']), btn('➖ Pul ayrish', 'uu_sub_' . $u['tg_id'])),
        array(btn($u['status'] === 'active' ? '🚫 Bloklash' : '✅ Blokdan chiqarish', 'uu_blk_' . $u['tg_id'])),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

// -----------------------------------------------------------------------------
//  📣 KANAL SOZLAMALARI
// -----------------------------------------------------------------------------
function a_channels($chat, $mid = 0)
{
    $list = required_channels();
    $txt = "📣 <b>MAJBURIY OBUNA KANALLARI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$list) { $txt .= "Hozircha kanal qo'shilmagan.\n<i>Kanal qo'shilmasa, bot hamma uchun ochiq.</i>"; }
    foreach ($list as $c) { $txt .= "• @" . esc($c['username']) . ($c['title'] !== '' ? " — " . esc($c['title']) : '') . "\n"; }
    $kb = ikb(array(
        array(btn("➕ Yangi qo'shish", 'ch_add')),
        array(btn("🗑 O'chirish", 'ch_del')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

// -----------------------------------------------------------------------------
//  🔑 API SOZLAMALASI
// -----------------------------------------------------------------------------
function a_api($chat, $mid = 0)
{
    $k = trim(cfg('api_key'));
    $txt = "🔑 <b>API SOZLAMALASI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🌐 Domen: <code>" . esc(cfg('api_domain')) . "</code>\n"
        . "🔑 Kalit: " . ($k !== '' ? "<code>" . esc(substr($k, 0, 10)) . "…</code> ✅" : "<b>kiritilmagan</b> ❌");
    $kb = ikb(array(
        array(btn('♻️ Kalitni yangilash', 'api_set')),
        array(btn('💰 Balans tekshirish', 'api_bal')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_api_balance($chat, $mid)
{
    edit($chat, $mid, "💰 <b>BALANS TEKSHIRISH</b>\n━━━━━━━━━━━━━━━━━━━━\n\nQaysi balansni tekshiramiz?",
        ikb(array(
            array(btn('💎 Fragment (TON)', 'bal_ton')),
            array(btn('⭐️ Userbot Stars', 'bal_stars')),
            array(btn('🔙 Orqaga', 'api_root')),
        )));
}

// -----------------------------------------------------------------------------
//  🛠 QO'SHIMCHA SOZLAMA
// -----------------------------------------------------------------------------
function a_extra($chat, $mid = 0)
{
    $txt = "🛠 <b>QO'SHIMCHA SOZLAMA</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📦 Buyurtmalar kanali: <b>" . (cfg('orders_channel') !== '' ? '@' . esc(cfg('orders_channel')) : 'yo\'q') . "</b>\n"
        . "👨‍💻 Admin username: <b>" . (cfg('support_username') !== '' ? '@' . esc(cfg('support_username')) : 'yo\'q') . "</b>\n"
        . "📰 Yangiliklar kanali: <b>" . (cfg('news_channel') !== '' ? '@' . esc(cfg('news_channel')) : 'yo\'q') . "</b>";
    $kb = ikb(array(
        array(btn('📦 Buyurtmalar kanali', 'ex_orders_channel')),
        array(btn('👨‍💻 Admin username', 'ex_support_username')),
        array(btn('📰 Yangiliklar kanali', 'ex_news_channel')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

// -----------------------------------------------------------------------------
//  🔖 QO'LLANMA SOZLASH
// -----------------------------------------------------------------------------
function a_guides($chat, $mid = 0)
{
    $list = all_rows("SELECT * FROM guides ORDER BY id");
    $txt = "🔖 <b>QO'LLANMALAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$list) { $txt .= "Hozircha qo'llanma yo'q."; }
    foreach ($list as $g) { $txt .= "• " . esc($g['title']) . "\n"; }
    $kb = ikb(array(
        array(btn("➕ Yangi qo'shish", 'gu_add')),
        array(btn("🗑 O'chirish", 'gu_del')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

// -----------------------------------------------------------------------------
//  🧑‍💻 ADMIN SOZLAMALARI
// -----------------------------------------------------------------------------
function perm_list()
{
    return array(
        'stats'    => '📊 Statistika',
        'prices'   => '💰 Narxlar',
        'pay'      => "💳 To'lovlar",
        'gifts'    => '🛍 Giftlar',
        'users'    => '⚙️ Userlar',
        'channels' => '📣 Kanallar',
        'api'      => '🔑 API',
        'ads'      => '✉️ Reklama',
        'extra'    => '🛠 Qo\'shimcha',
        'guides'   => '🔖 Qo\'llanma',
        'admins'   => '🧑‍💻 Adminlar',
        'status'   => '🟢 Bot holati',
    );
}

function a_admins($chat, $mid = 0)
{
    $list = all_rows("SELECT * FROM admins ORDER BY created_at");
    $txt = "🧑‍💻 <b>ADMINLAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    foreach ($list as $a) {
        $u = user_get($a['tg_id']);
        $nm = $u ? ($u['username'] !== '' ? '@' . $u['username'] : $u['first_name']) : 'nomaʼlum';
        $own = is_owner($a['tg_id']) ? ' 👑' : '';
        $txt .= "• <code>" . (int)$a['tg_id'] . "</code> — " . esc($nm) . $own . "\n";
    }
    if (!$list) { $txt .= "Adminlar yo'q."; }
    $kb = ikb(array(
        array(btn("➕ Yangi admin", 'ad_add')),
        array(btn('⚙️ Sozlash', 'ad_cfg'), btn("🗑 O'chirish", 'ad_del')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

function a_admin_perms($chat, $mid, $tg)
{
    $a = one("SELECT * FROM admins WHERE tg_id = ?", array($tg));
    if (!$a) { return; }
    $p = (string)$a['perms'];
    $cur = ($p === 'all' || $p === '') ? array_keys(perm_list()) : (json_decode($p, true) ?: array());
    $rows = array(); $line = array();
    foreach (perm_list() as $k => $name) {
        $on = in_array($k, $cur, true);
        $line[] = btn(($on ? '✅ ' : '❌ ') . $name, 'apx_' . $tg . '_' . $k);
        if (count($line) === 2) { $rows[] = $line; $line = array(); }
    }
    if ($line) { $rows[] = $line; }
    $rows[] = array(btn('🔙 Adminlar', 'ad_root'));
    edit($chat, $mid,
        "⚙️ <b>ADMIN RUXSATLARI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🆔 <code>" . (int)$tg . "</code>\n\n"
        . "Tugmani bosib ruxsatni yoqing/o'chiring 👇", ikb($rows));
}

// -----------------------------------------------------------------------------
//  🟢 BOT HOLATI
// -----------------------------------------------------------------------------
function a_status($chat, $mid = 0)
{
    $t0 = microtime(true);
    tg('getMe');
    $ping = round((microtime(true) - $t0) * 1000);
    $maint = cfg('maintenance') === '1';
    $users = (int)col("SELECT COUNT(*) FROM users");
    $pend  = (int)col("SELECT COUNT(*) FROM payments WHERE status='pending'");

    $txt = "🟢 <b>BOT HOLATI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📡 Telegram javob vaqti: <b>" . $ping . " ms</b>\n"
        . "🐘 PHP: <b>" . PHP_VERSION . "</b>\n"
        . "👥 Foydalanuvchilar: <b>" . money($users) . "</b>\n"
        . "🧾 Kutayotgan to'lovlar: <b>" . $pend . "</b>\n\n"
        . "🔧 Ta'mir rejimi: <b>" . ($maint ? '🔴 YOQILGAN' : '🟢 o\'chirilgan') . "</b>";
    $kb = ikb(array(
        array(btn($maint ? "✅ Ta'mirni o'chirish" : "🔧 Ta'mirga qo'yish", 'bs_maint')),
        array(btn('🔄 Yangilash', 'bs_refresh')),
    ));
    if ($mid) { edit($chat, $mid, $txt, $kb); } else { say($chat, $txt, $kb); }
}

// -----------------------------------------------------------------------------
//  ✉️ REKLAMA
// -----------------------------------------------------------------------------
function a_ads($chat)
{
    state_set($chat, 'a_ads', array());
    $act = one("SELECT * FROM broadcasts WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $info = '';
    if ($act) {
        $info = "\n\n⚠️ Hozir faol reklama bor: yuborilgan <b>" . (int)$act['sent_ok']
              . "</b>, xato <b>" . (int)$act['sent_fail'] . "</b>. Yangisi uni almashtiradi.";
    }
    say($chat,
        "✉️ <b>REKLAMA YUBORISH</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Barcha foydalanuvchilarga yuboriladigan xabarni yuboring.\n"
        . "<i>Matn, rasm, video — istalgan ko'rinishda.</i>" . $info,
        kb_cancel());
}

function a_ads_save($chat, $msg)
{
    state_clear($chat);
    q("UPDATE broadcasts SET status = 'done' WHERE status = 'active'");
    q("INSERT INTO broadcasts (from_chat_id, message_id, created_at) VALUES (?, ?, ?)",
      array($chat, (int)$msg['message_id'], now()));
    $total = (int)col("SELECT COUNT(*) FROM users WHERE status = 'active'");
    say($chat,
        "✅ <b>Reklama saqlandi</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "👥 Qamrov: <b>" . money($total) . "</b> foydalanuvchi\n\n"
        . "⚠️ <b>MUHIM:</b> reklama yuborilishi uchun <code>send.php</code> ni "
        . "<b>1 daqiqalik cron</b>ga qo'ygan bo'lishingiz kerak:\n\n"
        . "<code>* * * * * php " . dirname(__DIR__) . "/send.php</code>\n\n"
        . "Har chaqiruvda <b>100 ta</b> foydalanuvchiga yuboriladi.",
        kb_admin());
}
