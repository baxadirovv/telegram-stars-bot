<?php
/**
 * =============================================================================
 *  Ma'lumotlar bazasi qatlami
 *  • PDO ulanish (MySQL) · jadvallarni avtomatik yaratish
 *  • Sozlamalar, foydalanuvchilar, holat (FSM)
 * =============================================================================
 */

// --- PHP 8 funksiyalari uchun moslik (PHP 7.4 da yo'q) ---------------------
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
}

// `mbstring` kengaytmasi o'chirilgan serverlar uchun (UTF-8 bilan ishlaydi)
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        $a = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        $r = ($len === null) ? array_slice($a, $start) : array_slice($a, $start, $len);
        return implode('', $r);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return (int)preg_match_all('/./us', (string)$s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); }
    catch (Exception $e) { try { $pdo->exec("SET NAMES utf8mb4"); } catch (Exception $e2) {} }
    return $pdo;
}

function q($sql, $params = array())
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function one($sql, $params = array()) { $r = q($sql, $params)->fetch(); return $r ? $r : null; }
function all_rows($sql, $params = array()) { return q($sql, $params)->fetchAll(); }
function col($sql, $params = array(), $def = 0)
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r ? $r[0] : $def;
}

// -----------------------------------------------------------------------------
//  JADVALLARNI AVTOMATIK YARATISH
// -----------------------------------------------------------------------------
function db_install()
{
    $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $t = array();

    $t[] = "CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tg_id BIGINT NOT NULL UNIQUE,
        username VARCHAR(64) DEFAULT '',
        first_name VARCHAR(128) DEFAULT '',
        balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_deposit DECIMAL(14,2) NOT NULL DEFAULT 0,
        status ENUM('active','blocked') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        INDEX(created_at), INDEX(status)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS admins (
        tg_id BIGINT NOT NULL PRIMARY KEY,
        perms TEXT,
        created_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS states (
        tg_id BIGINT NOT NULL PRIMARY KEY,
        state VARCHAR(64) NOT NULL DEFAULT '',
        data TEXT, updated_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS gift_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS gifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id INT NOT NULL,
        name VARCHAR(128) NOT NULL,
        price DECIMAL(14,2) NOT NULL DEFAULT 0,
        service_id VARCHAR(32) NOT NULL,
        description TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        INDEX(section_id)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        card_number VARCHAR(64) NOT NULL,
        holder VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        method VARCHAR(64) DEFAULT '',
        amount DECIMAL(14,2) NOT NULL,
        file_id VARCHAR(255) DEFAULT '',
        file_type VARCHAR(16) DEFAULT '',
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        INDEX(user_id), INDEX(status)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS auto_pay (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        method VARCHAR(32) DEFAULT '',
        status ENUM('pending','completed','expired') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        INDEX(status), INDEX(amount)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        type ENUM('stars_post','stars_profile','premium','gift') NOT NULL,
        service_id VARCHAR(32) DEFAULT '',
        title VARCHAR(190) DEFAULT '',
        target VARCHAR(255) DEFAULT '',
        quantity INT NOT NULL DEFAULT 1,
        price DECIMAL(14,2) NOT NULL DEFAULT 0,
        status ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
        api_response TEXT,
        source VARCHAR(16) NOT NULL DEFAULT 'bot',
        created_at DATETIME NOT NULL,
        INDEX(user_id), INDEX(type), INDEX(status), INDEX(created_at)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS channels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        title VARCHAR(190) DEFAULT '',
        created_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS guides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        body TEXT, created_at DATETIME NOT NULL) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_chat_id BIGINT NOT NULL,
        message_id BIGINT NOT NULL,
        status ENUM('active','done') NOT NULL DEFAULT 'active',
        last_user_id BIGINT NOT NULL DEFAULT 0,
        sent_ok INT NOT NULL DEFAULT 0,
        sent_fail INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX(status)) $E";

    $t[] = "CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        api_key VARCHAR(64) NOT NULL UNIQUE,
        requests INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        INDEX(user_id)) $E";

    foreach ($t as $sql) { db()->exec($sql); }

    $defaults = array(
        'star_price'       => '250',
        'premium_3_price'  => '150000',
        'premium_6_price'  => '250000',
        'premium_12_price' => '400000',
        'post_stars_min'   => '1',
        'post_stars_max'   => '10000',
        'autopay_card'     => '',
        'autopay_holder'   => '',
        'autopay_enabled'  => '0',
        'api_key'          => '',
        'api_domain'       => 'https://giftapi.uz',
        'orders_channel'   => '',
        'support_username' => '',
        'news_channel'     => '',
        'maintenance'      => '0',
        'installed_at'     => date('Y-m-d H:i:s'),
    );
    foreach ($defaults as $k => $v) {
        q("INSERT IGNORE INTO settings (k, v) VALUES (?, ?)", array($k, $v));
    }
    if (defined('ADMIN_ID') && ADMIN_ID) {
        q("INSERT IGNORE INTO admins (tg_id, perms, created_at) VALUES (?, 'all', ?)",
          array(ADMIN_ID, date('Y-m-d H:i:s')));
    }
}

function db_ensure()
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try { db()->query("SELECT 1 FROM settings LIMIT 1"); }
    catch (Exception $e) { db_install(); }
}

// -----------------------------------------------------------------------------
//  SOZLAMALAR
// -----------------------------------------------------------------------------
function cfg($key, $default = '')
{
    static $cache = array();
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    $r = one("SELECT v FROM settings WHERE k = ?", array($key));
    $cache[$key] = $r ? $r['v'] : $default;
    return $cache[$key];
}

function cfg_set($key, $value)
{
    q("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
      array($key, (string)$value));
}

// -----------------------------------------------------------------------------
//  FOYDALANUVCHILAR
// -----------------------------------------------------------------------------
function user_get($tg_id) { return one("SELECT * FROM users WHERE tg_id = ?", array($tg_id)); }

function user_touch($from)
{
    $tg = (int)$from['id'];
    $u  = user_get($tg);
    $un = isset($from['username']) ? (string)$from['username'] : '';
    $fn = isset($from['first_name']) ? (string)$from['first_name'] : '';
    if (!$u) {
        q("INSERT INTO users (tg_id, username, first_name, created_at) VALUES (?, ?, ?, ?)",
          array($tg, $un, $fn, date('Y-m-d H:i:s')));
        return user_get($tg);
    }
    if ($u['username'] !== $un || $u['first_name'] !== $fn) {
        q("UPDATE users SET username = ?, first_name = ? WHERE tg_id = ?", array($un, $fn, $tg));
        $u['username'] = $un; $u['first_name'] = $fn;
    }
    return $u;
}

function balance_add($tg_id, $amount, $is_deposit = false)
{
    if ($is_deposit) {
        q("UPDATE users SET balance = balance + ?, total_deposit = total_deposit + ? WHERE tg_id = ?",
          array($amount, $amount, $tg_id));
    } else {
        q("UPDATE users SET balance = balance + ? WHERE tg_id = ?", array($amount, $tg_id));
    }
}

// -----------------------------------------------------------------------------
//  ADMIN HUQUQLARI
// -----------------------------------------------------------------------------
function is_owner($tg_id)
{
    $tg_id = (string)$tg_id;
    return defined('ADMIN_ID') && ADMIN_ID && $tg_id === (string)ADMIN_ID;
}

function is_admin($tg_id)
{
    if (is_owner($tg_id)) { return true; }
    return (bool)one("SELECT tg_id FROM admins WHERE tg_id = ?", array($tg_id));
}

function admin_can($tg_id, $perm)
{
    if (is_owner($tg_id)) { return true; }
    $r = one("SELECT perms FROM admins WHERE tg_id = ?", array($tg_id));
    if (!$r) { return false; }
    $p = (string)$r['perms'];
    if ($p === 'all' || $p === '') { return true; }
    $list = json_decode($p, true);
    if (!is_array($list)) { return true; }
    return in_array($perm, $list, true);
}

// -----------------------------------------------------------------------------
//  HOLAT (FSM)
// -----------------------------------------------------------------------------
function state_set($tg_id, $state, $data = array())
{
    q("INSERT INTO states (tg_id, state, data, updated_at) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE state=VALUES(state), data=VALUES(data), updated_at=VALUES(updated_at)",
      array($tg_id, $state, json_encode($data, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')));
}

function state_get($tg_id)
{
    $r = one("SELECT state, data FROM states WHERE tg_id = ?", array($tg_id));
    if (!$r) { return array('state' => '', 'data' => array()); }
    $d = json_decode((string)$r['data'], true);
    return array('state' => $r['state'], 'data' => is_array($d) ? $d : array());
}

function state_clear($tg_id) { q("DELETE FROM states WHERE tg_id = ?", array($tg_id)); }

// -----------------------------------------------------------------------------
//  YORDAMCHILAR
// -----------------------------------------------------------------------------
function money($n) { return number_format((float)$n, 0, '.', ' '); }
function now() { return date('Y-m-d H:i:s'); }

function logg($msg)
{
    $file = __DIR__ . '/../bot.log';
    $max = (defined('LOG_MAX_MB') ? LOG_MAX_MB : 5) * 1024 * 1024;
    clearstatcache(true, $file);
    if (is_file($file) && @filesize($file) > $max) {
        $lines = @file($file);
        $keep = $lines ? array_slice($lines, -200) : array();
        @file_put_contents($file, "--- jurnal tozalandi ---\n" . implode('', $keep), LOCK_EX);
    }
    @file_put_contents($file, date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
