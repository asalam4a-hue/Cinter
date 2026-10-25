/**
 * نظام إدارة ومتابعة العقود - نسخة Google Apps Script (البيانات في Google Sheets)
 * الخطوات: أنشئ Google Sheet جديداً -> الإضافات/Extensions -> Apps Script -> الصق هذا الملف
 * وأضف ملف HTML باسم Index (الصق محتوى Index.html) -> Deploy > New deployment > Web app.
 * المستخدم الافتراضي: admin / admin123  (غيّر كلمة المرور بعد أول دخول)
 */
var TZ = 'Africa/Cairo';
var SESSION_TTL = 6 * 3600; // أقصى مدة للكاش في Apps Script = 6 ساعات
var STAGES = ['--', 'جارى التصنيع', 'تم التصنيع', 'الجودة', 'تسليم مخازن', 'تسليم التجاريه', 'استلام العقد من العميل'];
var SHEETS = {
  Users: ['id', 'full_name', 'username', 'password', 'birth_date', 'created_at', 'photo', 'is_admin', 'perms', 'units'],
  Contracts: ['id', 'created_at', 'contract_no', 'description', 'qty', 'balance', 'required', 'unit', 'receive_date', 'delivery_date', 'stage', 'stage_date', 'project_name', 'created_by', 'updated_by', 'updated_at'],
  History: ['id', 'contract_id', 'contract_no', 'stage', 'stage_date', 'changed_by', 'created_at'],
  Settings: ['k', 'v']
};
var NUM_COLS = { id: 1, qty: 1, balance: 1, required: 1, is_admin: 1, contract_id: 1 };

function ApiError_(msg) { this.message = msg; }

/* ---------------------------------------------------------------- web app */
function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle('نظام إدارة ومتابعة العقود')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

/** نقطة الدخول الوحيدة من الواجهة: google.script.run.handle(json) */
function handle(payload) {
  var lock = null;
  try {
    var body = JSON.parse(payload || '{}');
    var action = body.action, data = body.data || {};
    setup_();
    if (action === 'publicInfo') { var st = getSettings_(); return { name: st.name, logo: st.logo }; }
    if (action === 'login') { lock = LockService.getScriptLock(); lock.waitLock(20000); return login_(data); }
    var u = auth_(body.token);
    var fn = ACTIONS[action];
    if (!fn) throw new ApiError_('إجراء غير معروف');
    if (action !== 'bootstrap') { lock = LockService.getScriptLock(); lock.waitLock(20000); }
    return fn(u, data);
  } catch (e) {
    if (e instanceof ApiError_) return { error: e.message };
    console.error(e && e.stack || e);
    return { error: 'خطأ داخلي: ' + (e && e.message ? e.message : e) };
  } finally {
    if (lock) { try { lock.releaseLock(); } catch (x) {} }
  }
}

/* ---------------------------------------------------------------- storage */
function ss_() {
  var s = null;
  try { s = SpreadsheetApp.getActiveSpreadsheet(); } catch (e) {}
  if (s) return s;
  var p = PropertiesService.getScriptProperties(), id = p.getProperty('SHEET_ID');
  if (id) return SpreadsheetApp.openById(id);
  s = SpreadsheetApp.create('نظام العقود - البيانات');
  p.setProperty('SHEET_ID', s.getId());
  return s;
}
function today_() { return Utilities.formatDate(new Date(), TZ, 'yyyy-MM-dd'); }
function now_() { return Utilities.formatDate(new Date(), TZ, 'yyyy-MM-dd HH:mm:ss'); }
function textFmt_(name) { return SHEETS[name].map(function (h) { return NUM_COLS[h] ? '0.######' : '@'; }); }

/** يشغَّل مرة واحدة تلقائياً: ينشئ الأوراق والمستخدم الافتراضي والإعدادات */
function setup_() {
  var props = PropertiesService.getScriptProperties();
  if (props.getProperty('INIT') === '3') return;
  var s = ss_();
  Object.keys(SHEETS).forEach(function (name) {
    var sh = s.getSheetByName(name) || s.insertSheet(name);
    var h = SHEETS[name];
    sh.getRange(1, 1, 1, h.length).setNumberFormat('@').setValues([h]).setFontWeight('bold');
    sh.setFrozenRows(1);
    sh.setRightToLeft(true);
    var fm = textFmt_(name);
    sh.getRange(2, 1, Math.max(sh.getMaxRows() - 1, 1), h.length).setNumberFormats(
      Array.apply(null, Array(Math.max(sh.getMaxRows() - 1, 1))).map(function () { return fm; }));
  });
  if (!readAll_('Users').length) {
    insert_('Users', { full_name: 'مدير النظام', username: 'admin', password: hash_('admin123'), birth_date: '', created_at: today_(), photo: '', is_admin: 1, perms: '[]' });
  }
  if (!readAll_('Settings').length) {
    insert_('Settings', { k: 'name', v: 'نظام إدارة ومتابعة العقود' });
    insert_('Settings', { k: 'logo', v: '' });
    insert_('Settings', { k: 'units', v: JSON.stringify(['الوحدة الأولى', 'الوحدة الثانية', 'الوحدة الثالثة']) });
    insert_('Settings', { k: 'wa_phone', v: '' });
  }
  var def = s.getSheetByName('Sheet1') || s.getSheetByName('ورقة1');
  if (def && s.getSheets().length > 1) { try { s.deleteSheet(def); } catch (e) {} }
  props.setProperty('INIT', '3');
}

function sheet_(name) { return ss_().getSheetByName(name); }
function readAll_(name) {
  var sh = sheet_(name), last = sh.getLastRow(), hdr = SHEETS[name];
  if (last < 2) return [];
  var vals = sh.getRange(2, 1, last - 1, hdr.length).getValues(), out = [];
  for (var i = 0; i < vals.length; i++) {
    var o = { _row: i + 2 }, empty = true;
    for (var j = 0; j < hdr.length; j++) {
      var v = vals[i][j];
      if (v instanceof Date) v = Utilities.formatDate(v, TZ, 'yyyy-MM-dd');
      if (NUM_COLS[hdr[j]]) v = (v === '' || v === null) ? 0 : Number(v);
      else v = (v === null || v === undefined) ? '' : String(v);
      if (v !== '' && v !== 0) empty = false;
      o[hdr[j]] = v;
    }
    if (!empty) out.push(o);
  }
  return out;
}
function rowArr_(name, obj) { return SHEETS[name].map(function (h) { var v = obj[h]; return v === undefined || v === null ? '' : v; }); }
function writeRow_(name, row, obj) {
  var sh = sheet_(name), n = SHEETS[name].length;
  sh.getRange(row, 1, 1, n).setNumberFormats([textFmt_(name)]).setValues([rowArr_(name, obj)]);
}
function insert_(name, obj) {
  var sh = sheet_(name);
  if (SHEETS[name][0] === 'id') {
    var max = 0;
    readAll_(name).forEach(function (r) { if (r.id > max) max = r.id; });
    obj.id = max + 1;
  }
  var row = sh.getLastRow() + 1;
  writeRow_(name, row, obj);
  obj._row = row;
  return obj;
}
function update_(name, row, obj) { writeRow_(name, row, obj); }
function delete_(name, row) { sheet_(name).deleteRow(row); }

/* ---------------------------------------------------------------- security */
function hash_(pw, salt) {
  salt = salt || Utilities.getUuid().replace(/-/g, '').slice(0, 16);
  var b = salt + ':' + pw;
  for (var i = 0; i < 300; i++) b = Utilities.base64Encode(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, salt + b, Utilities.Charset.UTF_8));
  return salt + '$' + b;
}
function verify_(pw, stored) { var salt = String(stored || '').split('$')[0]; return !!salt && hash_(pw, salt) === stored; }
function unitsOf_(u) { try { var x = JSON.parse(u.units || '[]'); return Array.isArray(x) ? x : []; } catch (e) { return []; } }
function perms_(u) { try { var p = JSON.parse(u.perms || '[]'); return Array.isArray(p) ? p : []; } catch (e) { return []; } }
function can_(u, p) { return !!u.is_admin || perms_(u).indexOf(p) >= 0; }
function need_(u, p) { if (!can_(u, p)) throw new ApiError_('ليس لديك صلاحية لتنفيذ هذا الإجراء'); }
function anyPerm_(u, ps) { return ps.some(function (p) { return can_(u, p); }); }
function auth_(token) {
  if (!token) throw new ApiError_('AUTH');
  var id = CacheService.getScriptCache().get('t_' + token);
  if (!id) throw new ApiError_('AUTH');
  var u = readAll_('Users').filter(function (x) { return String(x.id) === String(id); })[0];
  if (!u) throw new ApiError_('AUTH');
  return u;
}
function login_(d) {
  var uname = s_(d.username);
  var u = readAll_('Users').filter(function (x) { return x.username === uname; })[0];
  if (!u || !verify_(String(d.password || ''), u.password)) throw new ApiError_('اسم المستخدم أو كلمة المرور غير صحيحة');
  var token = Utilities.getUuid().replace(/-/g, '') + Utilities.getUuid().replace(/-/g, '');
  CacheService.getScriptCache().put('t_' + token, String(u.id), SESSION_TTL);
  return { token: token, user: userOut_(u) };
}

/* ---------------------------------------------------------------- helpers */
function s_(v, lim) { return String(v === undefined || v === null ? '' : v).trim().slice(0, lim || 2000); }
function n_(v) { var f = Number(v); return isFinite(f) ? f : 0; }
function userOut_(r) { return { id: r.id, full_name: r.full_name, username: r.username, birth_date: r.birth_date || '', created_at: r.created_at || '', photo: r.photo || '', is_admin: !!r.is_admin, perms: perms_(r), units: unitsOf_(r) }; }
function contractOut_(r) {
  return { id: r.id, created_at: r.created_at, contract_no: r.contract_no, project_name: r.project_name || '', description: r.description, qty: r.qty, balance: r.balance, required: r.required,
    unit: r.unit, receive_date: r.receive_date, delivery_date: r.delivery_date, stage: r.stage || '--', stage_date: r.stage_date,
    created_by: r.created_by || '', updated_by: r.updated_by || '', updated_at: r.updated_at || '' };
}
function getSettings_() {
  var d = {};
  readAll_('Settings').forEach(function (r) { d[r.k] = r.v; });
  var units = []; try { units = JSON.parse(d.units || '[]'); } catch (e) {}
  return { name: d.name || 'نظام إدارة ومتابعة العقود', logo: d.logo || '', units: units, wa_phone: d.wa_phone || '' };
}
function addHistory_(cid, no, stage, date, by) {
  insert_('History', { contract_id: cid, contract_no: no, stage: stage, stage_date: date, changed_by: by, created_at: now_() });
}
function cleanContract_(d) {
  var no = s_(d.contract_no, 100);
  if (!no) throw new ApiError_('رقم العقد مطلوب');
  return { contract_no: no, project_name: s_(d.project_name, 200), description: s_(d.description), qty: n_(d.qty), balance: n_(d.balance), required: n_(d.required), unit: s_(d.unit, 200),
    receive_date: s_(d.receive_date, 10), delivery_date: s_(d.delivery_date, 10), stage: STAGES.indexOf(d.stage) >= 0 ? d.stage : '--', stage_date: s_(d.stage_date, 10) || today_() };
}
function adminsCount_() { return readAll_('Users').filter(function (x) { return x.is_admin; }).length; }
function limitCell_(v) { v = String(v === undefined || v === null ? '' : v); if (v.length > 49000) throw new ApiError_('الصورة كبيرة جداً، اختر صورة أصغر'); return v; }

/* ---------------------------------------------------------------- actions */
function a_logout(u) { return { ok: true }; }
function uscope_(u) { return u.is_admin ? null : unitsOf_(u); }
function unitOk_(u, unit) { var sc = uscope_(u); return sc === null || sc.indexOf(String(unit || '')) >= 0; }
function needUnit_(u, unit) { if (!unitOk_(u, unit)) throw new ApiError_('هذا العقد خارج الوحدة الإنتاجية المخصصة لك'); }

function a_bootstrap(u) {
  var sc = uscope_(u), st = getSettings_();
  if (sc !== null) st.units = st.units.filter(function (x) { return sc.indexOf(x) >= 0; });
  var o = { me: userOut_(u), settings: st };
  if (anyPerm_(u, ['dashboard.view', 'contracts.view', 'follow.view', 'reports.view'])) {
    var rows = readAll_('Contracts').filter(function (r) { return sc === null || sc.indexOf(r.unit) >= 0; });
    var ids = {}; rows.forEach(function (r) { ids[r.id] = 1; });
    o.contracts = rows.sort(function (a, b) { return b.id - a.id; }).map(contractOut_);
    o.history = readAll_('History').filter(function (r) { return ids[r.contract_id]; }).map(function (r) { return { id: r.id, contract_id: r.contract_id, contract_no: r.contract_no, stage: r.stage, stage_date: r.stage_date, changed_by: r.changed_by }; });
  }
  if (can_(u, 'users.view')) o.users = readAll_('Users').map(userOut_);
  return o;
}
function a_saveContract(u, d) {
  var c = cleanContract_(d), all = readAll_('Contracts');
  needUnit_(u, c.unit);
  if (d.id) {
    need_(u, 'contracts.edit');
    var old = all.filter(function (x) { return String(x.id) === String(d.id); })[0];
    if (!old) throw new ApiError_('العقد غير موجود');
    needUnit_(u, old.unit);
    if (all.some(function (x) { return x.contract_no === c.contract_no && x.id !== old.id; })) throw new ApiError_('رقم العقد مستخدم من قبل');
    var changed = c.stage !== old.stage;
    if (changed) { need_(u, 'contracts.stage'); c.stage_date = today_(); } else { c.stage_date = old.stage_date || today_(); }
    var n = { id: old.id, created_at: old.created_at, created_by: old.created_by, updated_by: u.full_name, updated_at: now_() };
    Object.keys(c).forEach(function (k) { n[k] = c[k]; });
    update_('Contracts', old._row, n);
    if (c.contract_no !== old.contract_no) readAll_('History').forEach(function (h) { if (h.contract_id === old.id) { h.contract_no = c.contract_no; update_('History', h._row, h); } });
    if (changed) addHistory_(old.id, c.contract_no, c.stage, c.stage_date, u.full_name);
    return { id: old.id };
  }
  need_(u, 'contracts.add');
  if (all.some(function (x) { return x.contract_no === c.contract_no; })) throw new ApiError_('رقم العقد مستخدم من قبل');
  c.created_at = today_(); c.stage_date = today_(); c.created_by = u.full_name; c.updated_by = u.full_name; c.updated_at = now_();
  var row = insert_('Contracts', c);
  addHistory_(row.id, c.contract_no, c.stage, c.stage_date, u.full_name);
  return { id: row.id };
}
function a_deleteContract(u, d) {
  need_(u, 'contracts.delete');
  var c = readAll_('Contracts').filter(function (x) { return String(x.id) === String(d.id); })[0];
  if (!c) return { ok: true };
  needUnit_(u, c.unit);
  readAll_('History').filter(function (h) { return String(h.contract_id) === String(c.id); }).map(function (h) { return h._row; }).sort(function (a, b) { return b - a; }).forEach(function (r) { delete_('History', r); });
  delete_('Contracts', c._row);
  return { ok: true };
}
function a_changeStage(u, d) {
  need_(u, 'contracts.stage');
  var c = readAll_('Contracts').filter(function (x) { return String(x.id) === String(d.id); })[0];
  if (!c) throw new ApiError_('العقد غير موجود');
  needUnit_(u, c.unit);
  if (STAGES.indexOf(d.stage) < 0) throw new ApiError_('مرحلة غير صالحة');
  if (d.stage !== c.stage) {
    var date = today_();   // تلقائي
    c.stage = d.stage; c.stage_date = date; c.updated_by = u.full_name; c.updated_at = now_();
    update_('Contracts', c._row, c);
    addHistory_(c.id, c.contract_no, d.stage, date, u.full_name);
  }
  return { ok: true };
}
function a_bulkContracts(u, d) {
  need_(u, 'contracts.add');
  var have = {}, cid = 0, hid = 0;
  readAll_('Contracts').forEach(function (x) { have[x.contract_no] = 1; if (x.id > cid) cid = x.id; });
  readAll_('History').forEach(function (x) { if (x.id > hid) hid = x.id; });
  var list = (d.list || []).slice(0, 500), added = 0, crow = [], hrow = [];
  list.forEach(function (it) {
    var c; try { c = cleanContract_(it); } catch (e) { return; }
    if (have[c.contract_no] || !unitOk_(u, c.unit)) return;
    have[c.contract_no] = 1; cid++; hid++; added++;
    c.id = cid; c.created_at = s_(it.created_at, 10) || today_(); c.created_by = u.full_name; c.updated_by = u.full_name; c.updated_at = now_();
    crow.push(rowArr_('Contracts', c));
    hrow.push(rowArr_('History', { id: hid, contract_id: cid, contract_no: c.contract_no, stage: c.stage, stage_date: c.stage_date, changed_by: u.full_name, created_at: now_() }));
  });
  if (crow.length) {
    var sh = sheet_('Contracts'), hs = sheet_('History');
    sh.getRange(sh.getLastRow() + 1, 1, crow.length, SHEETS.Contracts.length).setNumberFormats(crow.map(function () { return textFmt_('Contracts'); })).setValues(crow);
    hs.getRange(hs.getLastRow() + 1, 1, hrow.length, SHEETS.History.length).setNumberFormats(hrow.map(function () { return textFmt_('History'); })).setValues(hrow);
  }
  return { added: added };
}
function a_saveUser(u, d) {
  var uname = s_(d.username, 60), full = s_(d.full_name, 120);
  if (!uname || !full) throw new ApiError_('الاسم الكامل واسم المستخدم مطلوبان');
  var perms = (d.perms || []).filter(function (p) { return typeof p === 'string'; });
  var units = (d.units || []).map(function (x) { return s_(x, 200); }).filter(Boolean);
  if (!u.is_admin) {
    var mine = perms_(u), mu = unitsOf_(u);
    perms = perms.filter(function (p) { return mine.indexOf(p) >= 0; });
    units = units.filter(function (x) { return mu.indexOf(x) >= 0; });
  }
  var wantAdmin = !!d.is_admin;
  if (wantAdmin && !u.is_admin) throw new ApiError_('فقط مدير النظام يستطيع منح صلاحية المدير');
  var photo = limitCell_(d.photo), pw = String(d.password || ''), all = readAll_('Users');
  if (d.id) {
    need_(u, 'users.edit');
    var old = all.filter(function (x) { return String(x.id) === String(d.id); })[0];
    if (!old) throw new ApiError_('المستخدم غير موجود');
    if (old.is_admin && !u.is_admin) throw new ApiError_('لا يمكنك تعديل حساب مدير النظام');
    if (all.some(function (x) { return x.username === uname && x.id !== old.id; })) throw new ApiError_('اسم المستخدم مستخدم من قبل');
    if (old.is_admin && !wantAdmin && adminsCount_() <= 1) throw new ApiError_('لا يمكن إزالة آخر مدير للنظام');
    if (!u.is_admin) wantAdmin = !!old.is_admin;
    if (pw && pw.length < 4) throw new ApiError_('كلمة المرور قصيرة (4 أحرف على الأقل)');
    old.full_name = full; old.username = uname; old.birth_date = s_(d.birth_date, 10); old.photo = photo;
    old.is_admin = wantAdmin ? 1 : 0; old.perms = JSON.stringify(perms); old.units = JSON.stringify(units);
    if (pw) old.password = hash_(pw);
    update_('Users', old._row, old);
  } else {
    need_(u, 'users.add');
    if (pw.length < 4) throw new ApiError_('كلمة المرور مطلوبة (4 أحرف على الأقل)');
    if (all.some(function (x) { return x.username === uname; })) throw new ApiError_('اسم المستخدم مستخدم من قبل');
    insert_('Users', { full_name: full, username: uname, password: hash_(pw), birth_date: s_(d.birth_date, 10), created_at: today_(), photo: photo, is_admin: wantAdmin ? 1 : 0, perms: JSON.stringify(perms), units: JSON.stringify(units) });
  }
  return { ok: true };
}
function a_deleteUser(u, d) {
  need_(u, 'users.delete');
  if (String(d.id) === String(u.id)) throw new ApiError_('لا يمكنك حذف حسابك الحالي');
  var t = readAll_('Users').filter(function (x) { return String(x.id) === String(d.id); })[0];
  if (!t) throw new ApiError_('المستخدم غير موجود');
  if (t.is_admin && (!u.is_admin || adminsCount_() <= 1)) throw new ApiError_('لا يمكن حذف هذا المدير');
  delete_('Users', t._row);
  return { ok: true };
}
function a_changePassword(u, d) {
  if (!verify_(String(d.old || ''), u.password)) throw new ApiError_('كلمة المرور الحالية غير صحيحة');
  var nw = String(d['new'] || '');
  if (nw.length < 4) throw new ApiError_('كلمة المرور الجديدة قصيرة (4 أحرف على الأقل)');
  u.password = hash_(nw);
  update_('Users', u._row, u);
  return { ok: true };
}
function a_saveSettings(u, d) {
  need_(u, 'settings.edit');
  var units = (d.units || []).map(function (x) { return s_(x, 200); }).filter(Boolean);
  if (!u.is_admin) units = getSettings_().units;
  var vals = { name: s_(d.name, 200) || 'نظام إدارة ومتابعة العقود', logo: limitCell_(d.logo), units: JSON.stringify(units), wa_phone: s_(d.wa_phone, 30) };
  var rows = readAll_('Settings');
  Object.keys(vals).forEach(function (k) {
    var r = rows.filter(function (x) { return x.k === k; })[0];
    if (r) update_('Settings', r._row, { k: k, v: vals[k] }); else insert_('Settings', { k: k, v: vals[k] });
  });
  return { ok: true };
}
var ACTIONS = {
  logout: a_logout, bootstrap: a_bootstrap, saveContract: a_saveContract, deleteContract: a_deleteContract, changeStage: a_changeStage,
  bulkContracts: a_bulkContracts, saveUser: a_saveUser, deleteUser: a_deleteUser, changePassword: a_changePassword, saveSettings: a_saveSettings
};

/** اختياري: شغّلها يدوياً من المحرر لإعادة تهيئة الأوراق (لا تمسح البيانات الموجودة). */
function resetInit() { PropertiesService.getScriptProperties().deleteProperty('INIT'); setup_(); }
