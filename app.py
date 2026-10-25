# -*- coding: utf-8 -*-
"""نظام إدارة ومتابعة العقود - نسخة Python (Flask + SQLite)
التشغيل:  pip install -r requirements.txt  ثم  python app.py
المستخدم الافتراضي: admin / admin123  (غيّر كلمة المرور بعد أول دخول)
"""
import os, json, sqlite3, secrets, time, datetime
from flask import Flask, request, jsonify, send_from_directory, g
from werkzeug.security import generate_password_hash, check_password_hash

BASE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.environ.get('CONTRACTS_DB', os.path.join(BASE, 'contracts.db'))
SESSION_TTL = 12 * 3600
STAGES = ['--', 'جارى التصنيع', 'تم التصنيع', 'الجودة', 'تسليم مخازن', 'تسليم التجاريه', 'استلام العقد من العميل']

try:
    from zoneinfo import ZoneInfo
    TZ = ZoneInfo('Africa/Cairo')
except Exception:  # pragma: no cover
    TZ = None


def now():
    return datetime.datetime.now(TZ) if TZ else datetime.datetime.now()


def today():
    return now().strftime('%Y-%m-%d')


app = Flask(__name__, static_folder=None)
app.config['MAX_CONTENT_LENGTH'] = 20 * 1024 * 1024


class ApiError(Exception):
    pass


# ---------------------------------------------------------------- database
def db():
    if 'db' not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
    return g.db


@app.teardown_appcontext
def close_db(_):
    d = g.pop('db', None)
    if d is not None:
        d.close()


SCHEMA = """
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, username TEXT UNIQUE NOT NULL,
  password TEXT NOT NULL, birth_date TEXT DEFAULT '', created_at TEXT, photo TEXT DEFAULT '',
  is_admin INTEGER DEFAULT 0, perms TEXT DEFAULT '[]');
CREATE TABLE IF NOT EXISTS contracts(
  id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, contract_no TEXT UNIQUE NOT NULL, project_name TEXT DEFAULT '',
  description TEXT DEFAULT '', qty REAL DEFAULT 0, balance REAL DEFAULT 0, required REAL DEFAULT 0,
  unit TEXT DEFAULT '', receive_date TEXT DEFAULT '', delivery_date TEXT DEFAULT '',
  stage TEXT DEFAULT '--', stage_date TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS history(
  id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER, contract_no TEXT, stage TEXT,
  stage_date TEXT, changed_by TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INTEGER, created REAL);
CREATE INDEX IF NOT EXISTS ix_hist_c ON history(contract_id);
"""


def init_db():
    con = sqlite3.connect(DB_PATH)
    con.executescript(SCHEMA)
    for tbl, col, ddl in [('contracts', 'project_name', "TEXT DEFAULT ''"), ('contracts', 'created_by', "TEXT DEFAULT ''"),
                          ('contracts', 'updated_by', "TEXT DEFAULT ''"), ('contracts', 'updated_at', "TEXT DEFAULT ''"),
                          ('users', 'units', "TEXT DEFAULT '[]'")]:
        if col not in [r[1] for r in con.execute('PRAGMA table_info(%s)' % tbl)]:
            con.execute('ALTER TABLE %s ADD COLUMN %s %s' % (tbl, col, ddl))
    if con.execute('SELECT COUNT(*) FROM users').fetchone()[0] == 0:
        con.execute('INSERT INTO users(full_name,username,password,created_at,is_admin,perms) VALUES(?,?,?,?,1,?)',
                    ('مدير النظام', 'admin', generate_password_hash('admin123'), today(), '[]'))
    if con.execute('SELECT COUNT(*) FROM settings').fetchone()[0] == 0:
        for k, v in {'name': 'نظام إدارة ومتابعة العقود', 'logo': '', 'units': json.dumps(['الوحدة الأولى', 'الوحدة الثانية', 'الوحدة الثالثة'], ensure_ascii=False), 'wa_phone': ''}.items():
            con.execute('INSERT INTO settings(k,v) VALUES(?,?)', (k, v))
    con.commit()
    con.close()


# ---------------------------------------------------------------- helpers
def n(v):
    try:
        f = float(v or 0)
    except (TypeError, ValueError):
        f = 0.0
    return int(f) if f.is_integer() else f


def s(v, limit=2000):
    return str(v if v is not None else '').strip()[:limit]


def user_out(r):
    return {'id': r['id'], 'full_name': r['full_name'], 'username': r['username'], 'birth_date': r['birth_date'] or '',
            'created_at': r['created_at'] or '', 'photo': r['photo'] or '', 'is_admin': bool(r['is_admin']),
            'perms': json.loads(r['perms'] or '[]'), 'units': json.loads(r['units'] or '[]')}


def contract_out(r):
    return {'id': r['id'], 'created_at': r['created_at'] or '', 'contract_no': r['contract_no'], 'project_name': r['project_name'] or '', 'description': r['description'] or '',
            'qty': n(r['qty']), 'balance': n(r['balance']), 'required': n(r['required']), 'unit': r['unit'] or '',
            'receive_date': r['receive_date'] or '', 'delivery_date': r['delivery_date'] or '',
            'stage': r['stage'] or '--', 'stage_date': r['stage_date'] or '',
            'created_by': r['created_by'] or '', 'updated_by': r['updated_by'] or '', 'updated_at': r['updated_at'] or ''}


def get_settings():
    d = {r['k']: r['v'] for r in db().execute('SELECT k,v FROM settings')}
    try:
        units = json.loads(d.get('units') or '[]')
    except ValueError:
        units = []
    return {'name': d.get('name') or 'نظام إدارة ومتابعة العقود', 'logo': d.get('logo') or '', 'units': units, 'wa_phone': d.get('wa_phone') or ''}


def can(u, p):
    return bool(u['is_admin']) or p in json.loads(u['perms'] or '[]')


def need(u, p):
    if not can(u, p):
        raise ApiError('ليس لديك صلاحية لتنفيذ هذا الإجراء')


def any_perm(u, ps):
    return any(can(u, p) for p in ps)


def auth(token):
    if not token:
        raise ApiError('AUTH')
    r = db().execute('SELECT u.*, s.created FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?', (token,)).fetchone()
    if not r or time.time() - r['created'] > SESSION_TTL:
        raise ApiError('AUTH')
    return r


def add_history(cid, no, stage, date, by):
    db().execute('INSERT INTO history(contract_id,contract_no,stage,stage_date,changed_by,created_at) VALUES(?,?,?,?,?,?)',
                 (cid, no, stage, date, by, now().strftime('%Y-%m-%d %H:%M:%S')))


# ---------------------------------------------------------------- actions
def public_info():
    st = get_settings()
    return {'name': st['name'], 'logo': st['logo']}


def login(d):
    r = db().execute('SELECT * FROM users WHERE username=?', (s(d.get('username')),)).fetchone()
    if not r or not check_password_hash(r['password'], str(d.get('password') or '')):
        raise ApiError('اسم المستخدم أو كلمة المرور غير صحيحة')
    token = secrets.token_hex(24)
    db().execute('DELETE FROM sessions WHERE created<?', (time.time() - SESSION_TTL,))
    db().execute('INSERT INTO sessions(token,user_id,created) VALUES(?,?,?)', (token, r['id'], time.time()))
    db().commit()
    return {'token': token, 'user': user_out(r)}


def a_logout(u, d):
    db().execute('DELETE FROM sessions WHERE user_id=?', (u['id'],))
    db().commit()
    return {'ok': True}


def uscope(u):
    """None = كل الوحدات (مدير النظام)، وإلا مجموعة الوحدات المسموحة للمستخدم"""
    if u['is_admin']:
        return None
    return set(json.loads(u['units'] or '[]'))


def unit_ok(u, unit):
    sc = uscope(u)
    return sc is None or (unit or '') in sc


def need_unit(u, unit):
    if not unit_ok(u, unit):
        raise ApiError('هذا العقد خارج الوحدة الإنتاجية المخصصة لك')


def stamp(cid, u, created=False):
    ts = now().strftime('%Y-%m-%d %H:%M:%S')
    if created:
        db().execute('UPDATE contracts SET created_by=?,updated_by=?,updated_at=? WHERE id=?', (u['full_name'], u['full_name'], ts, cid))
    else:
        db().execute('UPDATE contracts SET updated_by=?,updated_at=? WHERE id=?', (u['full_name'], ts, cid))


def a_bootstrap(u, d):
    sc = uscope(u)
    st = get_settings()
    if sc is not None:
        st['units'] = [x for x in st['units'] if x in sc]
    out = {'me': user_out(u), 'settings': st}
    if any_perm(u, ['dashboard.view', 'contracts.view', 'follow.view', 'reports.view']):
        rows = [contract_out(r) for r in db().execute('SELECT * FROM contracts ORDER BY id DESC')]
        if sc is not None:
            rows = [r for r in rows if r['unit'] in sc]
        ids = {r['id'] for r in rows}
        out['contracts'] = rows
        out['history'] = [{'id': r['id'], 'contract_id': r['contract_id'], 'contract_no': r['contract_no'], 'stage': r['stage'],
                           'stage_date': r['stage_date'], 'changed_by': r['changed_by']}
                          for r in db().execute('SELECT * FROM history ORDER BY id') if r['contract_id'] in ids]
    if can(u, 'users.view'):
        out['users'] = [user_out(r) for r in db().execute('SELECT * FROM users ORDER BY id')]
    return out


def clean_contract(d):
    no = s(d.get('contract_no'), 100)
    if not no:
        raise ApiError('رقم العقد مطلوب')
    stage = d.get('stage') if d.get('stage') in STAGES else '--'
    return {'contract_no': no, 'project_name': s(d.get('project_name'), 200), 'description': s(d.get('description')), 'qty': n(d.get('qty')),
            'balance': n(d.get('balance')), 'required': n(d.get('required')), 'unit': s(d.get('unit'), 200),
            'receive_date': s(d.get('receive_date'), 10), 'delivery_date': s(d.get('delivery_date'), 10), 'stage': stage,
            'stage_date': s(d.get('stage_date'), 10) or today()}


INS = ('INSERT INTO contracts(created_at,contract_no,project_name,description,qty,balance,required,unit,receive_date,delivery_date,stage,stage_date) '
       'VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')


def ins_args(created, c):
    return (created, c['contract_no'], c['project_name'], c['description'], c['qty'], c['balance'], c['required'], c['unit'],
            c['receive_date'], c['delivery_date'], c['stage'], c['stage_date'])


def a_saveContract(u, d):
    c = clean_contract(d)
    cid = d.get('id')
    con = db()
    need_unit(u, c['unit'])
    if cid:
        need(u, 'contracts.edit')
        old = con.execute('SELECT * FROM contracts WHERE id=?', (cid,)).fetchone()
        if not old:
            raise ApiError('العقد غير موجود')
        need_unit(u, old['unit'])
        if con.execute('SELECT 1 FROM contracts WHERE contract_no=? AND id<>?', (c['contract_no'], cid)).fetchone():
            raise ApiError('رقم العقد مستخدم من قبل')
        changed = c['stage'] != old['stage']
        if changed:
            need(u, 'contracts.stage')
            c['stage_date'] = today()          # تاريخ تغيير المرحلة تلقائي
        else:
            c['stage_date'] = old['stage_date'] or today()
        con.execute('UPDATE contracts SET contract_no=?,project_name=?,description=?,qty=?,balance=?,required=?,unit=?,receive_date=?,delivery_date=?,stage=?,stage_date=? WHERE id=?',
                    (c['contract_no'], c['project_name'], c['description'], c['qty'], c['balance'], c['required'], c['unit'], c['receive_date'], c['delivery_date'], c['stage'], c['stage_date'], cid))
        con.execute('UPDATE history SET contract_no=? WHERE contract_id=?', (c['contract_no'], cid))
        if changed:
            add_history(cid, c['contract_no'], c['stage'], c['stage_date'], u['full_name'])
        stamp(cid, u)
    else:
        need(u, 'contracts.add')
        if con.execute('SELECT 1 FROM contracts WHERE contract_no=?', (c['contract_no'],)).fetchone():
            raise ApiError('رقم العقد مستخدم من قبل')
        c['stage_date'] = today()
        cur = con.execute(INS, ins_args(today(), c))
        cid = cur.lastrowid
        add_history(cid, c['contract_no'], c['stage'], c['stage_date'], u['full_name'])
        stamp(cid, u, True)
    con.commit()
    return {'id': cid}


def a_deleteContract(u, d):
    need(u, 'contracts.delete')
    con = db()
    r = con.execute('SELECT * FROM contracts WHERE id=?', (d.get('id'),)).fetchone()
    if r:
        need_unit(u, r['unit'])
        con.execute('DELETE FROM history WHERE contract_id=?', (r['id'],))
        con.execute('DELETE FROM contracts WHERE id=?', (r['id'],))
        con.commit()
    return {'ok': True}


def a_changeStage(u, d):
    need(u, 'contracts.stage')
    con = db()
    r = con.execute('SELECT * FROM contracts WHERE id=?', (d.get('id'),)).fetchone()
    if not r:
        raise ApiError('العقد غير موجود')
    need_unit(u, r['unit'])
    stage = d.get('stage') if d.get('stage') in STAGES else None
    if not stage:
        raise ApiError('مرحلة غير صالحة')
    if stage != r['stage']:
        date = today()                          # تلقائي
        con.execute('UPDATE contracts SET stage=?,stage_date=? WHERE id=?', (stage, date, r['id']))
        add_history(r['id'], r['contract_no'], stage, date, u['full_name'])
        stamp(r['id'], u)
        con.commit()
    return {'ok': True}


def a_bulkContracts(u, d):
    need(u, 'contracts.add')
    con = db()
    added = 0
    for item in (d.get('list') or [])[:500]:
        try:
            c = clean_contract(item)
        except ApiError:
            continue
        if not unit_ok(u, c['unit']):
            continue
        if con.execute('SELECT 1 FROM contracts WHERE contract_no=?', (c['contract_no'],)).fetchone():
            continue
        cur = con.execute(INS, ins_args(s(item.get('created_at'), 10) or today(), c))
        add_history(cur.lastrowid, c['contract_no'], c['stage'], c['stage_date'], u['full_name'])
        stamp(cur.lastrowid, u, True)
        added += 1
    con.commit()
    return {'added': added}


def admins_count():
    return db().execute('SELECT COUNT(*) FROM users WHERE is_admin=1').fetchone()[0]


def a_saveUser(u, d):
    con = db()
    uid = d.get('id')
    uname = s(d.get('username'), 60)
    full = s(d.get('full_name'), 120)
    if not uname or not full:
        raise ApiError('الاسم الكامل واسم المستخدم مطلوبان')
    perms = [p for p in (d.get('perms') or []) if isinstance(p, str)]
    units = [s(x, 200) for x in (d.get('units') or []) if s(x, 200)]
    if not u['is_admin']:
        mine = set(json.loads(u['perms'] or '[]'))
        perms = [p for p in perms if p in mine]
        sc = uscope(u) or set()
        units = [x for x in units if x in sc]
    units_json = json.dumps(units, ensure_ascii=False)
    want_admin = bool(d.get('is_admin'))
    if want_admin and not u['is_admin']:
        raise ApiError('فقط مدير النظام يستطيع منح صلاحية المدير')
    photo = s(d.get('photo'), 400000)
    pw = str(d.get('password') or '')
    if uid:
        need(u, 'users.edit')
        old = con.execute('SELECT * FROM users WHERE id=?', (uid,)).fetchone()
        if not old:
            raise ApiError('المستخدم غير موجود')
        if old['is_admin'] and not u['is_admin']:
            raise ApiError('لا يمكنك تعديل حساب مدير النظام')
        if con.execute('SELECT 1 FROM users WHERE username=? AND id<>?', (uname, uid)).fetchone():
            raise ApiError('اسم المستخدم مستخدم من قبل')
        if old['is_admin'] and not want_admin and admins_count() <= 1:
            raise ApiError('لا يمكن إزالة آخر مدير للنظام')
        if not u['is_admin']:
            want_admin = bool(old['is_admin'])
        if pw and len(pw) < 4:
            raise ApiError('كلمة المرور قصيرة (4 أحرف على الأقل)')
        con.execute('UPDATE users SET full_name=?,username=?,birth_date=?,photo=?,is_admin=?,perms=?,units=? WHERE id=?',
                    (full, uname, s(d.get('birth_date'), 10), photo, 1 if want_admin else 0, json.dumps(perms), units_json, uid))
        if pw:
            con.execute('UPDATE users SET password=? WHERE id=?', (generate_password_hash(pw), uid))
        con.execute('DELETE FROM sessions WHERE user_id=? AND user_id<>?', (uid, u['id']))
    else:
        need(u, 'users.add')
        if len(pw) < 4:
            raise ApiError('كلمة المرور مطلوبة (4 أحرف على الأقل)')
        if con.execute('SELECT 1 FROM users WHERE username=?', (uname,)).fetchone():
            raise ApiError('اسم المستخدم مستخدم من قبل')
        con.execute('INSERT INTO users(full_name,username,password,birth_date,created_at,photo,is_admin,perms,units) VALUES(?,?,?,?,?,?,?,?,?)',
                    (full, uname, generate_password_hash(pw), s(d.get('birth_date'), 10), today(), photo, 1 if want_admin else 0, json.dumps(perms), units_json))
    con.commit()
    return {'ok': True}


def a_deleteUser(u, d):
    need(u, 'users.delete')
    con = db()
    uid = d.get('id')
    if str(uid) == str(u['id']):
        raise ApiError('لا يمكنك حذف حسابك الحالي')
    t = con.execute('SELECT * FROM users WHERE id=?', (uid,)).fetchone()
    if not t:
        raise ApiError('المستخدم غير موجود')
    if t['is_admin'] and (not u['is_admin'] or admins_count() <= 1):
        raise ApiError('لا يمكن حذف هذا المدير')
    con.execute('DELETE FROM sessions WHERE user_id=?', (uid,))
    con.execute('DELETE FROM users WHERE id=?', (uid,))
    con.commit()
    return {'ok': True}


def a_changePassword(u, d):
    if not check_password_hash(u['password'], str(d.get('old') or '')):
        raise ApiError('كلمة المرور الحالية غير صحيحة')
    new = str(d.get('new') or '')
    if len(new) < 4:
        raise ApiError('كلمة المرور الجديدة قصيرة (4 أحرف على الأقل)')
    db().execute('UPDATE users SET password=? WHERE id=?', (generate_password_hash(new), u['id']))
    db().commit()
    return {'ok': True}


def a_saveSettings(u, d):
    need(u, 'settings.edit')
    units = [s(x, 200) for x in (d.get('units') or []) if s(x)]
    if not u['is_admin']:
        units = get_settings()['units']
    logo = s(d.get('logo'), 400000)
    vals = {'name': s(d.get('name'), 200) or 'نظام إدارة ومتابعة العقود', 'logo': logo, 'units': json.dumps(units, ensure_ascii=False), 'wa_phone': s(d.get('wa_phone'), 30)}
    con = db()
    for k, v in vals.items():
        con.execute('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', (k, v))
    con.commit()
    return {'ok': True}


ACTIONS = {'logout': a_logout, 'bootstrap': a_bootstrap, 'saveContract': a_saveContract, 'deleteContract': a_deleteContract,
           'changeStage': a_changeStage, 'bulkContracts': a_bulkContracts, 'saveUser': a_saveUser, 'deleteUser': a_deleteUser,
           'changePassword': a_changePassword, 'saveSettings': a_saveSettings}


@app.route('/api', methods=['POST'])
def api():
    body = request.get_json(silent=True) or {}
    action = body.get('action')
    data = body.get('data') or {}
    try:
        if action == 'publicInfo':
            return jsonify(public_info())
        if action == 'login':
            return jsonify(login(data))
        u = auth(body.get('token'))
        fn = ACTIONS.get(action)
        if not fn:
            raise ApiError('إجراء غير معروف')
        return jsonify(fn(u, data))
    except ApiError as e:
        return jsonify({'error': str(e)})
    except Exception as e:  # pragma: no cover
        app.logger.exception(e)
        return jsonify({'error': 'خطأ داخلي في الخادم'})


@app.route('/')
def index():
    return send_from_directory(BASE, 'index.html')


init_db()

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=int(os.environ.get('PORT', 5000)), debug=False)
