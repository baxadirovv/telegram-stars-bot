<?php
/**
 * =============================================================================
 *  SOZLAMALAR — faqat shu faylni to'ldirasiz, qolgan hamma narsa admin paneldan
 * =============================================================================
 */

// --- Telegram bot tokeni (@BotFather dan) ---
define('BOT_TOKEN', '');

// --- Asosiy admin Telegram ID si (@userinfobot dan bilib olasiz) ---
define('ADMIN_ID', 0);

// --- MySQL ---
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'telegram_bot');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Webhook maxfiy so'zi (ixtiyoriy, lekin tavsiya etiladi) ---
define('WEBHOOK_SECRET', '');

// --- AvtoPay maxfiy tokeni (AvtoPay ishlatilsa MAJBURIY) ---
// Uzun, tasodifiy qiymat qo'ying (masalan: bin2hex(random_bytes(16)) natijasi) va
// AYNAN SHU qiymatni CardXabarBot/HUMOcardbot/@LockAvtoPayBot sozlamalarida
// webhook manzilining oxiriga qo'shing: .../AvtoPay/pay_config.php?...&token=SHU_QIYMAT
// Bo'sh qoldirilsa, AvtoPay so'rovlari butunlay rad etiladi (xavfsizlik uchun).
define('AVTOPAY_TOKEN', '');

// --- Jurnal fayli 5 MB dan oshganda avtomatik tozalanadi ---
define('LOG_MAX_MB', 5);
