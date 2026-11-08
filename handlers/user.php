<?php
/**
 * =============================================================================
 *  FOYDALANUVCHI QISMI
 * =============================================================================
 */

function u_start($chat, $user)
{
    state_clear($chat);
    say($chat,
        "👋 <b>Assalomu alaykum, " . esc($user['first_name']) . "!</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "⭐️ Telegram <b>Stars</b>, 🌟 <b>Premium</b> va 🎁 <b>Giftlar</b>ni "
        . "eng qulay narxlarda xarid qiling.\n\n"
        . "Quyidagi menyudan kerakli bo'limni tanlang 👇",
        kb_main($chat));
}

// -----------------------------------------------------------------------------
//  ⭐️ STARS
// -----------------------------------------------------------------------------
function u_stars_menu($chat)
{
    list($pmin, $pmax) = post_limits();
    list($fmin, $fmax) = profile_limits();
    say($chat,
        "⭐️ <b>STARS XARIDI</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💵 1 ta Stars narxi: <b>" . money(star_price()) . " so'm</b>\n\n"
        . "💫 <b>Postga Stars</b> — " . $pmin . " dan " . money($pmax) . " tagacha\n"
        . "⭐️ <b>Profilga Stars</b> — " . $fmin . " dan " . money($fmax) . " tagacha\n\n"
        . "Qaysi turini tanlaysiz?",
        ikb(array(
            array(btn('💫 Post uchun', 'st_post'), btn('⭐️ Profil uchun', 'st_profile')),
        )));
}

function u_stars_pick($chat, $mid, $kind)
{
    if ($kind === 'post') { list($min, $max) = post_limits(); $t = 'Postga'; }
    else { list($min, $max) = profile_limits(); $t = 'Profilga'; }

    state_set($chat, 'stars_amount', array('kind' => $kind));
    edit($chat, $mid,
        "⭐️ <b>" . $t . " Stars</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Nechta Stars kerak?\n\n"
        . "📏 Chegara: <b>" . money($min) . "</b> — <b>" . money($max) . "</b> ta\n"
        . "💵 1 ta narxi: <b>" . money(star_price()) . " so'm</b>\n\n"
        . "Faqat raqam yuboring. Masalan: <code>100</code>");
    say($chat, "🔢 Miqdorni yuboring:", kb_cancel());
}

function u_stars_amount($chat, $text, $st)
{
    $kind = $st['data']['kind'];
    if ($kind === 'post') { list($min, $max) = post_limits(); }
    else { list($min, $max) = profile_limits(); }

    $n = (int)preg_replace('/[^\d]/', '', $text);
    if ($n < $min || $n > $max) {
        say($chat, "⚠️ Miqdor <b>" . money($min) . "</b> dan <b>" . money($max) . "</b> gacha bo'lishi kerak.\n"
                   . "Qaytadan yuboring:", kb_cancel());
        return;
    }
    $price = $n * star_price();
    $u = user_get($chat);
    if ((float)$u['balance'] < $price) {
        state_clear($chat);
        say($chat,
            "❌ <b>Balansingiz yetarli emas</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "💵 Kerak: <b>" . money($price) . " so'm</b>\n"
            . "💰 Balans: <b>" . money($u['balance']) . " so'm</b>\n"
            . "➖ Yetishmayapti: <b>" . money($price - (float)$u['balance']) . " so'm</b>\n\n"
            . "«💳 Balans To'ldirish» bo'limidan hisobingizni to'ldiring.",
            kb_main($chat));
        return;
    }

    state_set($chat, 'stars_target', array('kind' => $kind, 'amount' => $n, 'price' => $price));
    if ($kind === 'post') {
        say($chat, "🔗 Endi <b>post havolasini</b> yuboring.\n\n"
                   . "Masalan: <code>https://t.me/kanal/348</code>", kb_cancel());
    } else {
        say($chat, "👤 Endi <b>username</b> yuboring.\n\n"
                   . "Masalan: <code>@durov</code>", kb_cancel());
    }
}

function u_stars_target($chat, $text, $st)
{
    $d = $st['data'];
    $target = trim($text);

    if ($d['kind'] === 'post') {
        if (!preg_match('~^https?://t\.me/[^/\s]+/\d+~i', $target)) {
            say($chat, "⚠️ Havola noto'g'ri.\n\nTo'g'ri ko'rinish: <code>https://t.me/kanal/348</code>\n"
                       . "Qaytadan yuboring:", kb_cancel());
            return;
        }
    } else {
        if (!preg_match('/^@?[A-Za-z][A-Za-z0-9_]{4,31}$/', $target)) {
            say($chat, "⚠️ Username noto'g'ri.\n\nTo'g'ri ko'rinish: <code>@durov</code>\n"
                       . "Qaytadan yuboring:", kb_cancel());
            return;
        }
        if ($target[0] !== '@') { $target = '@' . $target; }
    }

    $d['target'] = $target;
    state_set($chat, 'stars_confirm', $d);
    $tur = $d['kind'] === 'post' ? '💫 Postga Stars' : '⭐️ Profilga Stars';
    say($chat,
        "🧾 <b>BUYURTMA MA'LUMOTNOMASI</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📦 Xizmat: <b>" . $tur . "</b>\n"
        . "🔢 Miqdor: <b>" . money($d['amount']) . "</b> ta ⭐️\n"
        . "🎯 Manzil: <code>" . esc($target) . "</code>\n"
        . "💵 To'lov: <b>" . money($d['price']) . " so'm</b>\n\n"
        . "Buyurtmani tasdiqlaysizmi?",
        ikb(array(array(btn('✅ Tasdiqlash', 'ok_stars'), btn('❌ Bekor qilish', 'cancel')))));
}

// -----------------------------------------------------------------------------
//  🌟 PREMIUM
// -----------------------------------------------------------------------------
function u_premium_menu($chat)
{
    $rows = array();
    foreach (premium_plans() as $m => $p) {
        if ($p['price'] <= 0) { continue; }
        $rows[] = array(btn('🌟 ' . $m . ' oy — ' . money($p['price']) . " so'm", 'pr_' . $m));
    }
    if (!$rows) {
        say($chat, "⚠️ Premium tariflari hali sozlanmagan. Keyinroq urinib ko'ring.", kb_main($chat));
        return;
    }
    say($chat,
        "🌟 <b>TELEGRAM PREMIUM</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Kerakli muddatni tanlang 👇",
        ikb($rows));
}

function u_premium_pick($chat, $mid, $months)
{
    $plans = premium_plans();
    if (!isset($plans[$months]) || $plans[$months]['price'] <= 0) {
        answer_cb_safe($chat, "Bu tarif mavjud emas.");
        return;
    }
    $price = $plans[$months]['price'];
    $u = user_get($chat);
    if ((float)$u['balance'] < $price) {
        edit($chat, $mid,
            "❌ <b>Balansingiz yetarli emas</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "💵 Kerak: <b>" . money($price) . " so'm</b>\n"
            . "💰 Balans: <b>" . money($u['balance']) . " so'm</b>\n\n"
            . "«💳 Balans To'ldirish» bo'limidan to'ldiring.");
        return;
    }
    state_set($chat, 'premium_target', array('months' => $months, 'price' => $price));
    edit($chat, $mid, "🌟 <b>" . $months . " oylik Premium</b> — " . money($price) . " so'm");
    say($chat, "👤 Premium kimga yuborilsin?\n\nUsername yuboring. Masalan: <code>@durov</code>", kb_cancel());
}

function u_premium_target($chat, $text, $st)
{
    $target = trim($text);
    if (!preg_match('/^@?[A-Za-z][A-Za-z0-9_]{4,31}$/', $target)) {
        say($chat, "⚠️ Username noto'g'ri. Masalan: <code>@durov</code>\nQaytadan yuboring:", kb_cancel());
        return;
    }
    if ($target[0] !== '@') { $target = '@' . $target; }
    $d = $st['data'];
    $d['target'] = $target;
    state_set($chat, 'premium_confirm', $d);
    say($chat,
        "🧾 <b>BUYURTMA MA'LUMOTNOMASI</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📦 Xizmat: <b>🌟 Telegram Premium</b>\n"
        . "📅 Muddat: <b>" . (int)$d['months'] . " oy</b>\n"
        . "👤 Qabul qiluvchi: <code>" . esc($target) . "</code>\n"
        . "💵 To'lov: <b>" . money($d['price']) . " so'm</b>\n\n"
        . "Buyurtmani tasdiqlaysizmi?",
        ikb(array(array(btn('✅ Tasdiqlash', 'ok_premium'), btn('❌ Bekor qilish', 'cancel')))));
}

// -----------------------------------------------------------------------------
//  🎁 GIFTLAR
// -----------------------------------------------------------------------------
function u_gift_sections($chat, $mid = 0)
{
    $secs = all_rows("SELECT s.*, (SELECT COUNT(*) FROM gifts g WHERE g.section_id = s.id AND g.is_active = 1) c
                      FROM gift_sections s ORDER BY s.id");
    $rows = array();
    foreach ($secs as $s) {
        if ((int)$s['c'] === 0) { continue; }
        $rows[] = array(btn($s['name'] . ' (' . (int)$s['c'] . ')', 'gs_' . $s['id']));
    }
    if (!$rows) {
        say($chat, "🎁 Hozircha giftlar qo'shilmagan. Tez orada!", kb_main($chat));
        return;
    }
    $txt = "🎁 <b>GIFTLAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\nKerakli bo'limni tanlang 👇";
    if ($mid) { edit($chat, $mid, $txt, ikb($rows)); } else { say($chat, $txt, ikb($rows)); }
}

function u_gift_list($chat, $mid, $section_id)
{
    $sec = one("SELECT * FROM gift_sections WHERE id = ?", array($section_id));
    if (!$sec) { return; }
    $gifts = all_rows("SELECT * FROM gifts WHERE section_id = ? AND is_active = 1 ORDER BY price",
                      array($section_id));
    $rows = array();
    $txt = "🎁 <b>" . esc($sec['name']) . "</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    foreach ($gifts as $g) {
        $txt .= "• <b>" . esc($g['name']) . "</b> — " . money($g['price']) . " so'm\n";
        $rows[] = array(btn($g['name'] . ' — ' . money($g['price']) . " so'm", 'gf_' . $g['id']));
    }
    $rows[] = array(btn('🔙 Bo\'limlar', 'gs_back'));
    edit($chat, $mid, $txt . "\nKerakli giftni tanlang 👇", ikb($rows));
}

function u_gift_pick($chat, $mid, $gift_id)
{
    $g = one("SELECT * FROM gifts WHERE id = ? AND is_active = 1", array($gift_id));
    if (!$g) { return; }
    $u = user_get($chat);
    if ((float)$u['balance'] < (float)$g['price']) {
        edit($chat, $mid,
            "❌ <b>Balansingiz yetarli emas</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "🎁 " . esc($g['name']) . "\n"
            . "💵 Kerak: <b>" . money($g['price']) . " so'm</b>\n"
            . "💰 Balans: <b>" . money($u['balance']) . " so'm</b>");
        return;
    }
    state_set($chat, 'gift_target', array('gift_id' => (int)$g['id'], 'price' => (float)$g['price']));
    edit($chat, $mid,
        "🎁 <b>" . esc($g['name']) . "</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . ($g['description'] !== '' ? esc($g['description']) . "\n\n" : '')
        . "💵 Narxi: <b>" . money($g['price']) . " so'm</b>");
    say($chat, "👤 Gift kimga yuborilsin?\n\nUsername yuboring. Masalan: <code>@durov</code>", kb_cancel());
}

function u_gift_target($chat, $text, $st)
{
    $target = trim($text);
    if (!preg_match('/^@?[A-Za-z][A-Za-z0-9_]{4,31}$/', $target)) {
        say($chat, "⚠️ Username noto'g'ri. Masalan: <code>@durov</code>\nQaytadan yuboring:", kb_cancel());
        return;
    }
    if ($target[0] !== '@') { $target = '@' . $target; }
    $d = $st['data'];
    $g = one("SELECT * FROM gifts WHERE id = ?", array($d['gift_id']));
    if (!$g) { state_clear($chat); say($chat, "Gift topilmadi.", kb_main($chat)); return; }
    $d['target'] = $target;
    state_set($chat, 'gift_confirm', $d);
    say($chat,
        "🧾 <b>BUYURTMA MA'LUMOTNOMASI</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📦 Xizmat: <b>🎁 " . esc($g['name']) . "</b>\n"
        . "👤 Qabul qiluvchi: <code>" . esc($target) . "</code>\n"
        . "💵 To'lov: <b>" . money($g['price']) . " so'm</b>\n\n"
        . "Buyurtmani tasdiqlaysizmi?",
        ikb(array(array(btn('✅ Tasdiqlash', 'ok_gift'), btn('❌ Bekor qilish', 'cancel')))));
}

// -----------------------------------------------------------------------------
//  BUYURTMANI BAJARISH (tasdiqlashdan keyin)
// -----------------------------------------------------------------------------
function u_do_order($chat, $mid, $kind)
{
    $st = state_get($chat);
    $d  = $st['data'];

    // Kutilayotgan holat mos kelmasa — ikki marta bosilgan yoki eskirgan
    // tasdiqlash tugmasi. Bo'sh/noto'g'ri ma'lumot bilan buyurtma yaratib
    // yubormaymiz, shunchaki xabar beramiz.
    $expect = array('stars' => 'stars_confirm', 'premium' => 'premium_confirm', 'gift' => 'gift_confirm');
    if ($st['state'] !== $expect[$kind]) {
        edit($chat, $mid, "⚠️ <b>Bu buyurtma muddati tugagan yoki allaqachon yuborilgan.</b>\n\nIltimos, qaytadan boshlang.");
        return;
    }
    state_clear($chat);
    $u = user_get($chat);
    if (!$u) { return; }

    edit($chat, $mid, "⏳ <b>Buyurtma bajarilmoqda…</b>\n\nBu 30 soniyagacha davom etishi mumkin, kuting.");

    if ($kind === 'stars') {
        $type = $d['kind'] === 'post' ? 'stars_post' : 'stars_profile';
        $sid  = $d['kind'] === 'post' ? 200 : 100;
        $r = order_execute($u, $type, $sid, type_label($type), $d['target'], (int)$d['amount'], (float)$d['price']);
    } elseif ($kind === 'premium') {
        $r = order_execute($u, 'premium', (int)$d['months'], $d['months'] . " oylik Premium",
                           $d['target'], 1, (float)$d['price']);
    } else {
        $g = one("SELECT * FROM gifts WHERE id = ?", array($d['gift_id']));
        if (!$g) { say($chat, "Gift topilmadi.", kb_main($chat)); return; }
        $r = order_execute($u, 'gift', $g['service_id'], $g['name'], $d['target'], 1, (float)$g['price']);
    }

    $u2 = user_get($chat);
    if (!empty($r['ok'])) {
        say($chat,
            "✅ <b>BUYURTMA BAJARILDI</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . esc($r['message']) . "\n\n"
            . "🆔 Buyurtma raqami: <code>#" . (int)$r['order_id'] . "</code>\n"
            . "💰 Qolgan balans: <b>" . money($u2['balance']) . " so'm</b>",
            kb_main($chat));
    } else {
        say($chat,
            "❌ <b>BUYURTMA BAJARILMADI</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . esc($r['message']) . "\n\n"
            . "💰 Balans: <b>" . money($u2['balance']) . " so'm</b>",
            kb_main($chat));
    }
}

// -----------------------------------------------------------------------------
//  🛒 BUYURTMALARIM
// -----------------------------------------------------------------------------
function u_orders_menu($chat)
{
    say($chat,
        "🛒 <b>BUYURTMALARIM</b>\n━━━━━━━━━━━━━━━━━━━━\n\nQaysi turini ko'rasiz?",
        ikb(array(
            array(btn('⭐️ Stars', 'my_stars')),
            array(btn('🌟 Premium', 'my_premium')),
            array(btn('🎁 Giftlar', 'my_gift')),
        )));
}

function u_orders_list($chat, $mid, $kind)
{
    if ($kind === 'stars') { $where = "type IN ('stars_post','stars_profile')"; $t = '⭐️ Stars'; }
    elseif ($kind === 'premium') { $where = "type = 'premium'"; $t = '🌟 Premium'; }
    else { $where = "type = 'gift'"; $t = '🎁 Gift'; }

    $rows = all_rows("SELECT * FROM orders WHERE user_id = ? AND $where ORDER BY id DESC LIMIT 15",
                     array($chat));
    $txt = "🛒 <b>" . $t . " buyurtmalarim</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$rows) {
        $txt .= "Hozircha buyurtma yo'q.";
    } else {
        foreach ($rows as $o) {
            $ic = $o['status'] === 'success' ? '✅' : ($o['status'] === 'pending' ? '⏳' : '❌');
            $txt .= $ic . " <b>#" . (int)$o['id'] . "</b> · " . esc($o['title']) . "\n"
                 . "   🎯 " . esc($o['target']) . "\n"
                 . "   💵 " . money($o['price']) . " so'm · " . date('d.m.Y H:i', strtotime($o['created_at'])) . "\n\n";
        }
    }
    edit($chat, $mid, $txt, ikb(array(array(btn('🔙 Orqaga', 'my_back')))));
}

// -----------------------------------------------------------------------------
//  💰 HISOBIM
// -----------------------------------------------------------------------------
function u_account($chat)
{
    $u = user_get($chat);
    $cnt = (int)col("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'success'", array($chat));
    $spent = (float)col("SELECT COALESCE(SUM(price),0) FROM orders WHERE user_id = ? AND status = 'success'", array($chat));
    say($chat,
        "💰 <b>HISOBIM</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🆔 ID: <code>" . (int)$u['tg_id'] . "</code>\n"
        . "👤 Ism: <b>" . esc($u['first_name']) . "</b>\n"
        . ($u['username'] !== '' ? "🔗 Username: @" . esc($u['username']) . "\n" : '')
        . "\n💰 Joriy balans: <b>" . money($u['balance']) . " so'm</b>\n"
        . "📥 Jami kiritilgan: <b>" . money($u['total_deposit']) . " so'm</b>\n"
        . "📤 Jami sarflangan: <b>" . money($spent) . " so'm</b>\n"
        . "🛒 Buyurtmalar: <b>" . $cnt . " ta</b>\n\n"
        . "📅 Ro'yxatdan o'tgan: " . date('d.m.Y', strtotime($u['created_at'])),
        kb_main($chat));
}

// -----------------------------------------------------------------------------
//  💳 BALANS TO'LDIRISH
// -----------------------------------------------------------------------------
function u_topup_menu($chat)
{
    $rows = array();
    if (cfg('autopay_enabled') === '1' && trim(cfg('autopay_card')) !== '') {
        $rows[] = array(btn('⚡️ Avto to\'lov ( Tezkor )', 'tp_auto'));
    }
    foreach (all_rows("SELECT * FROM payment_methods ORDER BY id") as $m) {
        $rows[] = array(btn('💳 ' . $m['name'], 'tp_m_' . $m['id']));
    }
    if (!$rows) {
        say($chat, "⚠️ Hozircha to'lov usullari sozlanmagan. Administrator bilan bog'laning.", kb_main($chat));
        return;
    }
    $u = user_get($chat);
    say($chat,
        "💳 <b>BALANS TO'LDIRISH</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💰 Joriy balans: <b>" . money($u['balance']) . " so'm</b>\n\n"
        . "To'lov usulini tanlang 👇",
        ikb($rows));
}

function u_topup_auto($chat, $mid)
{
    state_set($chat, 'topup_auto_amount', array());
    edit($chat, $mid,
        "⚡️ <b>AVTO TO'LOV ( Tezkor )</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "To'lov <b>avtomatik</b> tasdiqlanadi — kutish shart emas.\n\n"
        . "💳 Karta: <code>" . esc(cfg('autopay_card')) . "</code>\n"
        . "👤 Egasi: <b>" . esc(cfg('autopay_holder')) . "</b>\n\n"
        . "Hisobingizni necha so'mga to'ldirmoqchisiz?\n"
        . "Eng kami: <b>1 000 so'm</b>");
    say($chat, "🔢 Miqdorni yuboring:", kb_cancel());
}

function u_topup_auto_amount($chat, $text)
{
    $n = (int)preg_replace('/[^\d]/', '', $text);
    if ($n < 1000) {
        say($chat, "⚠️ Eng kam miqdor — <b>1 000 so'm</b>. Qaytadan yuboring:", kb_cancel());
        return;
    }
    state_clear($chat);
    // .00 gacha aniq summa — pay_config.php aynan shu qiymatni qidiradi
    q("INSERT INTO auto_pay (user_id, amount, status, created_at) VALUES (?, ?, 'pending', ?)",
      array($chat, $n, now()));
    say($chat,
        "⚡️ <b>TO'LOVNI AMALGA OSHIRING</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💳 Karta: <code>" . esc(cfg('autopay_card')) . "</code>\n"
        . "👤 Egasi: <b>" . esc(cfg('autopay_holder')) . "</b>\n\n"
        . "💵 Aynan shu summani o'tkazing:\n<b>" . money($n) . " so'm</b>\n\n"
        . "⚠️ <b>Diqqat:</b> summa <u>aynan</u> mos kelishi kerak — aks holda "
        . "avtomatik tasdiqlanmaydi.\n\n"
        . "✅ To'lov o'tgach balansingiz <b>bir necha soniyada</b> avtomatik to'ldiriladi.",
        kb_main($chat));
}

function u_topup_manual($chat, $mid, $method_id)
{
    $m = one("SELECT * FROM payment_methods WHERE id = ?", array($method_id));
    if (!$m) { return; }
    state_set($chat, 'topup_manual_amount', array('method_id' => (int)$m['id'], 'method' => $m['name']));
    edit($chat, $mid,
        "💳 <b>" . esc($m['name']) . "</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "💳 Karta raqami:\n<code>" . esc($m['card_number']) . "</code>\n"
        . "👤 Karta egasi: <b>" . esc($m['holder']) . "</b>\n\n"
        . "Ushbu kartaga <b>istalgan miqdorda</b> to'lovni amalga oshiring va "
        . "pastdagi tugmani bosing.",
        ikb(array(array(btn("✅ To'lov qildim", 'tp_paid_' . $m['id'])))));
}

function u_topup_paid($chat, $mid, $method_id)
{
    $m = one("SELECT * FROM payment_methods WHERE id = ?", array($method_id));
    if (!$m) { return; }
    state_set($chat, 'topup_manual_amount', array('method_id' => (int)$m['id'], 'method' => $m['name']));
    edit($chat, $mid, "💳 <b>" . esc($m['name']) . "</b> orqali to'lov");
    say($chat, "🔢 <b>Qancha to'lov qildingiz?</b>\n\nFaqat raqam yuboring. Masalan: <code>50000</code>",
        kb_cancel());
}

function u_topup_amount($chat, $text, $st)
{
    $n = (int)preg_replace('/[^\d]/', '', $text);
    if ($n < 1000) {
        say($chat, "⚠️ Eng kam miqdor — <b>1 000 so'm</b>. Qaytadan yuboring:", kb_cancel());
        return;
    }
    $d = $st['data'];
    $d['amount'] = $n;
    state_set($chat, 'topup_receipt', $d);
    say($chat,
        "🧾 <b>Endi to'lov chekini yuboring</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📸 Skrinshot (rasm) yoki 📄 PDF fayl yuborishingiz mumkin.",
        kb_cancel());
}

function u_topup_receipt($chat, $msg, $st)
{
    $file_id = ''; $ftype = '';
    if (isset($msg['photo'])) {
        $p = end($msg['photo']);
        $file_id = $p['file_id']; $ftype = 'photo';
    } elseif (isset($msg['document'])) {
        $file_id = $msg['document']['file_id']; $ftype = 'document';
    } else {
        say($chat, "⚠️ Chek topilmadi. Iltimos <b>rasm</b> yoki <b>PDF fayl</b> yuboring.", kb_cancel());
        return;
    }

    $d = $st['data'];
    state_clear($chat);
    q("INSERT INTO payments (user_id, method, amount, file_id, file_type, status, created_at)
       VALUES (?, ?, ?, ?, ?, 'pending', ?)",
      array($chat, $d['method'], $d['amount'], $file_id, $ftype, now()));
    $pid = (int)db()->lastInsertId();

    say($chat,
        "✅ <b>Chek qabul qilindi</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🆔 Ariza: <code>#" . $pid . "</code>\n"
        . "💵 Summa: <b>" . money($d['amount']) . " so'm</b>\n\n"
        . "⏳ Administrator tekshirgach balansingiz to'ldiriladi.",
        kb_main($chat));

    // Adminga yuboramiz
    $u = user_get($chat);
    $cap = "🧾 <b>YANGI TO'LOV ARIZASI</b>\n"
         . "━━━━━━━━━━━━━━━━━━━━\n\n"
         . "🆔 Ariza: <code>#" . $pid . "</code>\n"
         . "💵 Summa: <b>" . money($d['amount']) . " so'm</b>\n"
         . "💳 Usul: <b>" . esc($d['method']) . "</b>\n\n"
         . "👤 " . esc($u['first_name']) . ($u['username'] !== '' ? " (@" . esc($u['username']) . ")" : '')
         . "\n🆔 <code>" . (int)$chat . "</code>\n"
         . "💰 Balans: <b>" . money($u['balance']) . " so'm</b>";
    $kb = ikb(array(array(btn('✅ Tasdiqlash', 'pay_ok_' . $pid), btn('❌ Rad etish', 'pay_no_' . $pid))));

    foreach (admin_ids() as $aid) {
        if ($ftype === 'photo') {
            tg('sendPhoto', array('chat_id' => $aid, 'photo' => $file_id,
                'caption' => $cap, 'parse_mode' => 'HTML', 'reply_markup' => $kb));
        } else {
            tg('sendDocument', array('chat_id' => $aid, 'document' => $file_id,
                'caption' => $cap, 'parse_mode' => 'HTML', 'reply_markup' => $kb));
        }
    }
}

// -----------------------------------------------------------------------------
//  📚 QO'LLANMA · ☎️ YORDAM
// -----------------------------------------------------------------------------
function u_guides($chat)
{
    $g = all_rows("SELECT id, title FROM guides ORDER BY id");
    if (!$g) {
        say($chat, "📚 Hozircha qo'llanmalar qo'shilmagan.", kb_main($chat));
        return;
    }
    $rows = array();
    foreach ($g as $x) { $rows[] = array(btn('📖 ' . $x['title'], 'gd_' . $x['id'])); }
    say($chat, "📚 <b>QO'LLANMALAR</b>\n━━━━━━━━━━━━━━━━━━━━\n\nKerakli mavzuni tanlang 👇", ikb($rows));
}

function u_guide_show($chat, $mid, $id)
{
    $g = one("SELECT * FROM guides WHERE id = ?", array($id));
    if (!$g) { return; }
    edit($chat, $mid,
        "📖 <b>" . esc($g['title']) . "</b>\n━━━━━━━━━━━━━━━━━━━━\n\n" . esc($g['body']),
        ikb(array(array(btn('🔙 Orqaga', 'gd_back')))));
}

function u_support($chat)
{
    $s = trim(cfg('support_username'));
    $n = trim(cfg('news_channel'));
    $o = trim(cfg('orders_channel'));
    $txt = "☎️ <b>YORDAM MARKAZI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n";
    $rows = array();
    if ($s !== '') {
        $txt .= "👨‍💻 Administrator: @" . esc(ltrim($s, '@')) . "\n";
        $rows[] = array(url_btn('💬 Adminga yozish', 'https://t.me/' . ltrim($s, '@')));
    }
    if ($n !== '') {
        $txt .= "📰 Yangiliklar: @" . esc(ltrim($n, '@')) . "\n";
        $rows[] = array(url_btn('📰 Yangiliklar kanali', 'https://t.me/' . ltrim($n, '@')));
    }
    if ($o !== '') {
        $txt .= "📦 Buyurtmalar: @" . esc(ltrim($o, '@')) . "\n";
        $rows[] = array(url_btn('📦 Buyurtmalar kanali', 'https://t.me/' . ltrim($o, '@')));
    }
    if (!$rows) { $txt .= "Aloqa ma'lumotlari hali sozlanmagan."; }
    say($chat, $txt, $rows ? ikb($rows) : null);
}

// -----------------------------------------------------------------------------
//  🔑 HAMKORLIK (API)
// -----------------------------------------------------------------------------
function u_partner($chat)
{
    $k = one("SELECT * FROM api_keys WHERE user_id = ? AND is_active = 1", array($chat));
    $base = bot_base_url();
    if ($k) {
        say($chat,
            "🔑 <b>HAMKORLIK ( API )</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "✅ Sizning API kalitingiz:\n<code>" . esc($k['api_key']) . "</code>\n\n"
            . "🌐 Manzil:\n<code>" . esc($base) . "/api/</code>\n"
            . "📊 Yuborilgan so'rovlar: <b>" . (int)$k['requests'] . "</b>\n\n"
            . "Buyurtmalar balansingizdan yechiladi.",
            ikb(array(
                array(url_btn('📘 Hujjatlar', $base . '/api/docs.php')),
                array(btn('♻️ Kalitni yangilash', 'api_new')),
            )));
    } else {
        say($chat,
            "🔑 <b>HAMKORLIK ( API )</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "O'z botingiz yoki saytingizdan turib bizning xizmatlarimizni "
            . "avtomatik sotishingiz mumkin.\n\n"
            . "▫️ Stars (post va profil)\n"
            . "▫️ Telegram Premium\n"
            . "▫️ Giftlar\n\n"
            . "To'lov balansingizdan yechiladi.",
            ikb(array(
                array(btn('🔑 API kalit yaratish', 'api_new')),
                array(url_btn('📘 Hujjatlar', $base . '/api/docs.php')),
            )));
    }
}

function u_api_new($chat, $mid)
{
    $key = 'bk_' . bin2hex(random_bytes(20));
    q("UPDATE api_keys SET is_active = 0 WHERE user_id = ?", array($chat));
    q("INSERT INTO api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)",
      array($chat, $key, now()));
    $base = bot_base_url();
    edit($chat, $mid,
        "✅ <b>API kalit yaratildi</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🔑 Kalitingiz:\n<code>" . esc($key) . "</code>\n\n"
        . "🌐 Manzil:\n<code>" . esc($base) . "/api/</code>\n\n"
        . "⚠️ Kalitni hech kimga bermang. Eski kalit bekor qilindi.",
        ikb(array(array(url_btn('📘 Hujjatlar', $base . '/api/docs.php')))));
}
