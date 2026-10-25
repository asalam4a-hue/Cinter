<?php
/* نظام إدارة ومتابعة العقود - نسخة PHP (PDO: SQLite أو MySQL)
 * المستخدم الافتراضي: admin / admin123  (غيّر كلمة المرور بعد أول دخول) */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['timezone']);
const STAGES = ['--', 'جارى التصنيع', 'تم التصنيع', 'الجودة', 'تسليم مخازن', 'تسليم التجاريه', 'استلام العقد من العميل'];

class ApiError extends Exception {}

function out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function today() { return date('Y-m-d'); }
function nowts() { return date('Y-m-d H:i:s'); }

/* ------------------------------------------------ database */
function pdo() {
    global $CFG; static $pdo = null;
    if ($pdo) return $pdo;
    if ($CFG['driver'] === 'mysql') {
        $m = $CFG['mysql'];
        $pdo = new PDO("mysql:host={$m['host']};port={$m['port']};dbname={$m['dbname']};charset=utf8mb4", $m['user'], $m['pass']);
    } else {
        $dir = dirname($CFG['sqlite']);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $pdo = new PDO('sqlite:' . $CFG['sqlite']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    init_db($pdo);
    return $pdo;
}
function init_db(PDO $db) {
    global $CFG;
    $my = $CFG['driver'] === 'mysql';
    $id = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $my ? 'LONGTEXT' : 'TEXT';
    $sfx = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $v = $my ? 'VARCHAR(190)' : 'TEXT';
    $db->exec("CREATE TABLE IF NOT EXISTS `users`(`id` $id, `full_name` $v NOT NULL, `username` $v NOT NULL UNIQUE, `password` $v NOT NULL, `birth_date` $v DEFAULT '', `created_at` $v, `photo` $txt, `is_admin` INT DEFAULT 0, `perms` $txt)$sfx");
    $db->exec("CREATE TABLE IF NOT EXISTS `contracts`(`id` $id, `created_at` $v, `contract_no` $v NOT NULL UNIQUE, `project_name` $v DEFAULT '', `description` $txt, `qty` DOUBLE DEFAULT 0, `balance` DOUBLE DEFAULT 0, `required` DOUBLE DEFAULT 0, `unit` $v DEFAULT '', `receive_date` $v DEFAULT '', `delivery_date` $v DEFAULT '', `stage` $v DEFAULT '--', `stage_date` $v DEFAULT '')$sfx");
    $db->exec("CREATE TABLE IF NOT EXISTS `history`(`id` $id, `contract_id` INT, `contract_no` $v, `stage` $v, `stage_date` $v, `changed_by` $v, `created_at` $v)$sfx");
    $db->exec("CREATE TABLE IF NOT EXISTS `settings`(`k` VARCHAR(100) PRIMARY KEY, `v` $txt)$sfx");
    $db->exec("CREATE TABLE IF NOT EXISTS `sessions`(`token` VARCHAR(100) PRIMARY KEY, `user_id` INT, `created` BIGINT)$sfx");
    foreach ([['contracts', 'project_name', $v], ['contracts', 'created_by', $v], ['contracts', 'updated_by', $v], ['contracts', 'updated_at', $v], ['users', 'units', $txt]] as $mc) {
        try { $db->query("SELECT {$mc[1]} FROM {$mc[0]} LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE {$mc[0]} ADD COLUMN {$mc[1]} {$mc[2]}"); }
    }
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $db->prepare('INSERT INTO users(full_name,username,password,created_at,photo,is_admin,perms) VALUES(?,?,?,?,?,1,?)')
           ->execute(['مدير النظام', 'admin', password_hash('admin123', PASSWORD_DEFAULT), today(), '', '[]']);
    }
    if ((int)$db->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0) {
        $ins = $db->prepare('INSERT INTO settings(k,v) VALUES(?,?)');
        foreach (['name' => 'نظام إدارة ومتابعة العقود', 'logo' => '', 'units' => json_encode(['الوحدة الأولى', 'الوحدة الثانية', 'الوحدة الثالثة'], JSON_UNESCAPED_UNICODE), 'wa_phone' => ''] as $k => $x) $ins->execute([$k, $x]);
    }
}
function q($sql, $p = []) { $st = pdo()->prepare($sql); $st->execute($p); return $st; }

/* ------------------------------------------------ helpers */
function nn($v) { $f = is_numeric($v) ? (float)$v : 0.0; return floor($f) == $f ? (int)$f : $f; }
function s($v, $lim = 2000) { return mb_substr(trim((string)($v ?? '')), 0, $lim); }
function units_of($u) { $x = json_decode($u['units'] ?? '[]', true); return is_array($x) ? $x : []; }
function perms_of($u) { $p = json_decode($u['perms'] ?: '[]', true); return is_array($p) ? $p : []; }
function user_out($r) {
    return ['id' => (int)$r['id'], 'full_name' => $r['full_name'], 'username' => $r['username'], 'birth_date' => $r['birth_date'] ?: '',
        'created_at' => $r['created_at'] ?: '', 'photo' => $r['photo'] ?: '', 'is_admin' => (bool)$r['is_admin'], 'perms' => perms_of($r), 'units' => units_of($r)];
}
function contract_out($r) {
    return ['id' => (int)$r['id'], 'created_at' => $r['created_at'] ?: '', 'contract_no' => $r['contract_no'], 'project_name' => $r['project_name'] ?? '', 'description' => $r['description'] ?: '',
        'qty' => nn($r['qty']), 'balance' => nn($r['balance']), 'required' => nn($r['required']), 'unit' => $r['unit'] ?: '',
        'receive_date' => $r['receive_date'] ?: '', 'delivery_date' => $r['delivery_date'] ?: '', 'stage' => $r['stage'] ?: '--', 'stage_date' => $r['stage_date'] ?: '',
        'created_by' => $r['created_by'] ?? '', 'updated_by' => $r['updated_by'] ?? '', 'updated_at' => $r['updated_at'] ?? ''];
}
function get_settings() {
    $d = [];
    foreach (q('SELECT k,v FROM settings') as $r) $d[$r['k']] = $r['v'];
    $units = json_decode($d['units'] ?? '[]', true);
    return ['name' => $d['name'] ?: 'نظام إدارة ومتابعة العقود', 'logo' => $d['logo'] ?? '', 'units' => is_array($units) ? $units : [], 'wa_phone' => $d['wa_phone'] ?? ''];
}
function can($u, $p) { return (bool)$u['is_admin'] || in_array($p, perms_of($u), true); }
function need($u, $p) { if (!can($u, $p)) throw new ApiError('ليس لديك صلاحية لتنفيذ هذا الإجراء'); }
function any_perm($u, $ps) { foreach ($ps as $p) if (can($u, $p)) return true; return false; }
function auth($token) {
    global $CFG;
    if (!$token) throw new ApiError('AUTH');
    $r = q('SELECT u.*, s.created FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?', [$token])->fetch();
    if (!$r || time() - (int)$r['created'] > $CFG['session_ttl']) throw new ApiError('AUTH');
    return $r;
}
function add_history($cid, $no, $stage, $date, $by) {
    q('INSERT INTO history(contract_id,contract_no,stage,stage_date,changed_by,created_at) VALUES(?,?,?,?,?,?)', [$cid, $no, $stage, $date, $by, nowts()]);
}
function clean_contract($d) {
    $no = s($d['contract_no'] ?? '', 100);
    if ($no === '') throw new ApiError('رقم العقد مطلوب');
    $stage = in_array($d['stage'] ?? '', STAGES, true) ? $d['stage'] : '--';
    return ['contract_no' => $no, 'project_name' => s($d['project_name'] ?? '', 190), 'description' => s($d['description'] ?? ''), 'qty' => nn($d['qty'] ?? 0), 'balance' => nn($d['balance'] ?? 0),
        'required' => nn($d['required'] ?? 0), 'unit' => s($d['unit'] ?? '', 190), 'receive_date' => s($d['receive_date'] ?? '', 10),
        'delivery_date' => s($d['delivery_date'] ?? '', 10), 'stage' => $stage, 'stage_date' => s($d['stage_date'] ?? '', 10) ?: today()];
}
function admins_count() { return (int)q('SELECT COUNT(*) FROM users WHERE is_admin=1')->fetchColumn(); }

/* ------------------------------------------------ actions */
function a_public($u, $d) { $st = get_settings(); return ['name' => $st['name'], 'logo' => $st['logo']]; }
function a_login($d) {
    $r = q('SELECT * FROM users WHERE username=?', [s($d['username'] ?? '')])->fetch();
    if (!$r || !password_verify((string)($d['password'] ?? ''), $r['password'])) throw new ApiError('اسم المستخدم أو كلمة المرور غير صحيحة');
    global $CFG;
    $t = bin2hex(random_bytes(24));
    q('DELETE FROM sessions WHERE created<?', [time() - $CFG['session_ttl']]);
    q('INSERT INTO sessions(token,user_id,created) VALUES(?,?,?)', [$t, $r['id'], time()]);
    return ['token' => $t, 'user' => user_out($r)];
}
function a_logout($u, $d) { q('DELETE FROM sessions WHERE user_id=?', [$u['id']]); return ['ok' => true]; }
function uscope($u) { return $u['is_admin'] ? null : units_of($u); }
function unit_ok($u, $unit) { $sc = uscope($u); return $sc === null || in_array((string)$unit, $sc, true); }
function need_unit($u, $unit) { if (!unit_ok($u, $unit)) throw new ApiError('هذا العقد خارج الوحدة الإنتاجية المخصصة لك'); }
function stamp($cid, $u, $created = false) {
    if ($created) q('UPDATE contracts SET created_by=?,updated_by=?,updated_at=? WHERE id=?', [$u['full_name'], $u['full_name'], nowts(), $cid]);
    else q('UPDATE contracts SET updated_by=?,updated_at=? WHERE id=?', [$u['full_name'], nowts(), $cid]);
}
const INS_SQL = 'INSERT INTO contracts(created_at,contract_no,project_name,description,qty,balance,required,unit,receive_date,delivery_date,stage,stage_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)';
function ins_args($created, $c) { return [$created, $c['contract_no'], $c['project_name'], $c['description'], $c['qty'], $c['balance'], $c['required'], $c['unit'], $c['receive_date'], $c['delivery_date'], $c['stage'], $c['stage_date']]; }

function a_bootstrap($u, $d) {
    $sc = uscope($u);
    $st = get_settings();
    if ($sc !== null) $st['units'] = array_values(array_filter($st['units'], function ($x) use ($sc) { return in_array($x, $sc, true); }));
    $o = ['me' => user_out($u), 'settings' => $st];
    if (any_perm($u, ['dashboard.view', 'contracts.view', 'follow.view', 'reports.view'])) {
        $rows = array_map('contract_out', q('SELECT * FROM contracts ORDER BY id DESC')->fetchAll());
        if ($sc !== null) $rows = array_values(array_filter($rows, function ($r) use ($sc) { return in_array($r['unit'], $sc, true); }));
        $ids = array_flip(array_column($rows, 'id'));
        $o['contracts'] = $rows;
        $o['history'] = [];
        foreach (q('SELECT * FROM history ORDER BY id')->fetchAll() as $r) {
            if (isset($ids[(int)$r['contract_id']])) $o['history'][] = ['id' => (int)$r['id'], 'contract_id' => (int)$r['contract_id'], 'contract_no' => $r['contract_no'], 'stage' => $r['stage'], 'stage_date' => $r['stage_date'], 'changed_by' => $r['changed_by']];
        }
    }
    if (can($u, 'users.view')) $o['users'] = array_map('user_out', q('SELECT * FROM users ORDER BY id')->fetchAll());
    return $o;
}
function a_saveContract($u, $d) {
    $c = clean_contract($d);
    $cid = $d['id'] ?? null;
    need_unit($u, $c['unit']);
    if ($cid) {
        need($u, 'contracts.edit');
        $old = q('SELECT * FROM contracts WHERE id=?', [$cid])->fetch();
        if (!$old) throw new ApiError('العقد غير موجود');
        need_unit($u, $old['unit']);
        if (q('SELECT 1 FROM contracts WHERE contract_no=? AND id<>?', [$c['contract_no'], $cid])->fetch()) throw new ApiError('رقم العقد مستخدم من قبل');
        $changed = $c['stage'] !== $old['stage'];
        if ($changed) { need($u, 'contracts.stage'); $c['stage_date'] = today(); }   // تلقائي
        else $c['stage_date'] = $old['stage_date'] ?: today();
        q('UPDATE contracts SET contract_no=?,project_name=?,description=?,qty=?,balance=?,required=?,unit=?,receive_date=?,delivery_date=?,stage=?,stage_date=? WHERE id=?',
            [$c['contract_no'], $c['project_name'], $c['description'], $c['qty'], $c['balance'], $c['required'], $c['unit'], $c['receive_date'], $c['delivery_date'], $c['stage'], $c['stage_date'], $cid]);
        q('UPDATE history SET contract_no=? WHERE contract_id=?', [$c['contract_no'], $cid]);
        if ($changed) add_history($cid, $c['contract_no'], $c['stage'], $c['stage_date'], $u['full_name']);
        stamp($cid, $u);
    } else {
        need($u, 'contracts.add');
        if (q('SELECT 1 FROM contracts WHERE contract_no=?', [$c['contract_no']])->fetch()) throw new ApiError('رقم العقد مستخدم من قبل');
        $c['stage_date'] = today();
        q(INS_SQL, ins_args(today(), $c));
        $cid = pdo()->lastInsertId();
        add_history($cid, $c['contract_no'], $c['stage'], $c['stage_date'], $u['full_name']);
        stamp($cid, $u, true);
    }
    return ['id' => (int)$cid];
}
function a_deleteContract($u, $d) {
    need($u, 'contracts.delete');
    $r = q('SELECT * FROM contracts WHERE id=?', [$d['id'] ?? 0])->fetch();
    if ($r) {
        need_unit($u, $r['unit']);
        q('DELETE FROM history WHERE contract_id=?', [$r['id']]);
        q('DELETE FROM contracts WHERE id=?', [$r['id']]);
    }
    return ['ok' => true];
}
function a_changeStage($u, $d) {
    need($u, 'contracts.stage');
    $r = q('SELECT * FROM contracts WHERE id=?', [$d['id'] ?? 0])->fetch();
    if (!$r) throw new ApiError('العقد غير موجود');
    need_unit($u, $r['unit']);
    if (!in_array($d['stage'] ?? '', STAGES, true)) throw new ApiError('مرحلة غير صالحة');
    if ($d['stage'] !== $r['stage']) {
        $date = today();   // تلقائي
        q('UPDATE contracts SET stage=?,stage_date=? WHERE id=?', [$d['stage'], $date, $r['id']]);
        add_history($r['id'], $r['contract_no'], $d['stage'], $date, $u['full_name']);
        stamp($r['id'], $u);
    }
    return ['ok' => true];
}
function a_bulkContracts($u, $d) {
    need($u, 'contracts.add');
    $added = 0;
    foreach (array_slice($d['list'] ?? [], 0, 500) as $it) {
        try { $c = clean_contract($it); } catch (ApiError $e) { continue; }
        if (!unit_ok($u, $c['unit'])) continue;
        if (q('SELECT 1 FROM contracts WHERE contract_no=?', [$c['contract_no']])->fetch()) continue;
        q(INS_SQL, ins_args(s($it['created_at'] ?? '', 10) ?: today(), $c));
        $nid = pdo()->lastInsertId();
        add_history($nid, $c['contract_no'], $c['stage'], $c['stage_date'], $u['full_name']);
        stamp($nid, $u, true);
        $added++;
    }
    return ['added' => $added];
}
function a_saveUser($u, $d) {
    $uid = $d['id'] ?? null;
    $uname = s($d['username'] ?? '', 60); $full = s($d['full_name'] ?? '', 120);
    if ($uname === '' || $full === '') throw new ApiError('الاسم الكامل واسم المستخدم مطلوبان');
    $perms = array_values(array_filter($d['perms'] ?? [], 'is_string'));
    $units = array_values(array_filter(array_map(function ($x) { return s($x, 190); }, is_array($d['units'] ?? null) ? $d['units'] : []), function ($x) { return $x !== ''; }));
    if (!$u['is_admin']) {
        $perms = array_values(array_intersect($perms, perms_of($u)));
        $units = array_values(array_intersect($units, units_of($u)));
    }
    $unitsJson = json_encode($units, JSON_UNESCAPED_UNICODE);
    $wantAdmin = !empty($d['is_admin']);
    if ($wantAdmin && !$u['is_admin']) throw new ApiError('فقط مدير النظام يستطيع منح صلاحية المدير');
    $photo = s($d['photo'] ?? '', 400000);
    $pw = (string)($d['password'] ?? '');
    if ($uid) {
        need($u, 'users.edit');
        $old = q('SELECT * FROM users WHERE id=?', [$uid])->fetch();
        if (!$old) throw new ApiError('المستخدم غير موجود');
        if ($old['is_admin'] && !$u['is_admin']) throw new ApiError('لا يمكنك تعديل حساب مدير النظام');
        if (q('SELECT 1 FROM users WHERE username=? AND id<>?', [$uname, $uid])->fetch()) throw new ApiError('اسم المستخدم مستخدم من قبل');
        if ($old['is_admin'] && !$wantAdmin && admins_count() <= 1) throw new ApiError('لا يمكن إزالة آخر مدير للنظام');
        if (!$u['is_admin']) $wantAdmin = (bool)$old['is_admin'];
        if ($pw !== '' && mb_strlen($pw) < 4) throw new ApiError('كلمة المرور قصيرة (4 أحرف على الأقل)');
        q('UPDATE users SET full_name=?,username=?,birth_date=?,photo=?,is_admin=?,perms=?,units=? WHERE id=?', [$full, $uname, s($d['birth_date'] ?? '', 10), $photo, $wantAdmin ? 1 : 0, json_encode($perms), $unitsJson, $uid]);
        if ($pw !== '') q('UPDATE users SET password=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
        q('DELETE FROM sessions WHERE user_id=? AND user_id<>?', [$uid, $u['id']]);
    } else {
        need($u, 'users.add');
        if (mb_strlen($pw) < 4) throw new ApiError('كلمة المرور مطلوبة (4 أحرف على الأقل)');
        if (q('SELECT 1 FROM users WHERE username=?', [$uname])->fetch()) throw new ApiError('اسم المستخدم مستخدم من قبل');
        q('INSERT INTO users(full_name,username,password,birth_date,created_at,photo,is_admin,perms,units) VALUES(?,?,?,?,?,?,?,?,?)',
            [$full, $uname, password_hash($pw, PASSWORD_DEFAULT), s($d['birth_date'] ?? '', 10), today(), $photo, $wantAdmin ? 1 : 0, json_encode($perms), $unitsJson]);
    }
    return ['ok' => true];
}
function a_deleteUser($u, $d) {
    need($u, 'users.delete');
    $uid = $d['id'] ?? 0;
    if ((string)$uid === (string)$u['id']) throw new ApiError('لا يمكنك حذف حسابك الحالي');
    $t = q('SELECT * FROM users WHERE id=?', [$uid])->fetch();
    if (!$t) throw new ApiError('المستخدم غير موجود');
    if ($t['is_admin'] && (!$u['is_admin'] || admins_count() <= 1)) throw new ApiError('لا يمكن حذف هذا المدير');
    q('DELETE FROM sessions WHERE user_id=?', [$uid]);
    q('DELETE FROM users WHERE id=?', [$uid]);
    return ['ok' => true];
}
function a_changePassword($u, $d) {
    if (!password_verify((string)($d['old'] ?? ''), $u['password'])) throw new ApiError('كلمة المرور الحالية غير صحيحة');
    $new = (string)($d['new'] ?? '');
    if (mb_strlen($new) < 4) throw new ApiError('كلمة المرور الجديدة قصيرة (4 أحرف على الأقل)');
    q('UPDATE users SET password=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    return ['ok' => true];
}
function a_saveSettings($u, $d) {
    need($u, 'settings.edit');
    $units = array_values(array_filter(array_map(function ($x) { return s($x, 190); }, $d['units'] ?? []), function ($x) { return $x !== ''; }));
    if (!$u['is_admin']) $units = get_settings()['units'];
    $vals = ['name' => s($d['name'] ?? '', 200) ?: 'نظام إدارة ومتابعة العقود', 'logo' => s($d['logo'] ?? '', 400000), 'units' => json_encode($units, JSON_UNESCAPED_UNICODE), 'wa_phone' => s($d['wa_phone'] ?? '', 30)];
    foreach ($vals as $k => $v) { q('DELETE FROM settings WHERE k=?', [$k]); q('INSERT INTO settings(k,v) VALUES(?,?)', [$k, $v]); }
    return ['ok' => true];
}

/* ------------------------------------------------ dispatcher */
try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $data = $body['data'] ?? [];
    if (!is_array($data)) $data = [];
    if ($action === 'publicInfo') out(a_public(null, $data));
    if ($action === 'login') out(a_login($data));
    $u = auth($body['token'] ?? '');
    $fn = 'a_' . $action;
    if (!preg_match('/^[A-Za-z]+$/', $action) || !function_exists($fn) || $action === 'public' || $action === 'login') throw new ApiError('إجراء غير معروف');
    out($fn($u, $data));
} catch (ApiError $e) {
    out(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log($e);
    out(['error' => 'خطأ داخلي في الخادم']);
}
