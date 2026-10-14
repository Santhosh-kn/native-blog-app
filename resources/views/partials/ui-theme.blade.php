<style>
:root { color-scheme: light; --primary:#087f79; --primary-dark:#06645f; --primary-soft:#e8f5f2; --bg:#f4f7f8; --surface:#fff; --text:#183334; --text-muted:#5a6f72; --border:#dfe8e8; --danger:#b93650; --radius:20px; --shadow:0 4px 18px rgb(24 51 52 / 4%); }
*,*::before,*::after { box-sizing:border-box; }
[hidden] { display:none !important; }
html { scroll-padding-top:110px; }
body { margin:0; font:15px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg); color:var(--text); -webkit-tap-highlight-color:transparent; }
body.has-nav { padding-bottom:calc(102px + env(safe-area-inset-bottom)); }
a { color:var(--primary); text-underline-offset:3px; }
button,input,select,textarea { font:inherit; }
:focus-visible { outline:3px solid var(--primary); outline-offset:4px; }
.skip-link { position:fixed; top:-100px; left:16px; z-index:100; padding:12px 18px; background:var(--text); color:white; border-radius:12px; }
.skip-link:focus { top:12px; }
.ui-icon { width:22px; height:22px; display:inline-block; flex-shrink:0; vertical-align:middle; }
.app-bar { position:sticky; top:0; z-index:30; background:var(--bg); border-bottom:1px solid var(--border); padding-top:env(safe-area-inset-top); }
.app-bar-inner { max-width:980px; margin:auto; min-height:78px; display:flex; align-items:center; gap:12px; padding:12px 22px; }
.brand-mark { width:42px; height:42px; border-radius:14px; display:grid; place-items:center; background:var(--text); color:#bce9df; flex-shrink:0; }
.app-bar-heading { min-width:0; flex:1; }
.app-bar-heading p { margin:0; color:var(--text-muted); font-size:10px; font-weight:800; letter-spacing:.15em; }
.app-bar h1 { margin:1px 0 0; font-size:17px; line-height:1.3; font-weight:750; overflow-wrap:anywhere; }
.app-bar-action { display:flex; gap:8px; align-items:center; }
.icon-button { display:inline-flex; width:44px; height:44px; min-height:44px; padding:0; justify-content:center; align-items:center; border:1px solid var(--border); background:var(--surface); color:var(--text); border-radius:14px; text-decoration:none; }
.content { max-width:980px; margin:auto; padding:24px 22px 36px; }
.page-intro { display:flex; align-items:flex-start; gap:16px; padding:2px 0 24px; }
.page-intro .feature-symbol { width:52px; height:52px; border-radius:17px; margin-top:5px; }
.eyebrow { margin:0 0 6px; color:var(--primary); font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
.page-intro h2 { margin:0; font-size:clamp(24px,5vw,32px); line-height:1.25; letter-spacing:-.04em; }
.page-intro p:last-child { margin:8px 0 0; color:var(--text-muted); font-size:14px; max-width:600px; }
.card { min-width:0; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:22px; }
.card h2,.card h3 { margin:0 0 10px; color:var(--text); font-size:17px; line-height:1.4; letter-spacing:-.02em; }
.card-heading { display:flex; align-items:center; gap:10px; }
.card-heading .ui-icon { color:var(--primary); width:20px; height:20px; }
.card p { overflow-wrap:anywhere; }
.card > p { color:var(--text-muted); font-size:14px; }
.card > p:first-of-type { margin-top:0; }
.card > p:last-child { margin-bottom:0; }
.stack { display:grid; gap:16px; }
.field { margin-bottom:20px; }
label,legend { display:block; color:var(--text); font-size:13px; font-weight:650; margin-bottom:8px; }
input:not([type='checkbox']):not([type='radio']):not([type='hidden']),select,textarea { width:100%; min-width:0; min-height:48px; padding:12px 14px; font-size:16px; border:1px solid #ccdada; border-radius:12px; background:#fbfdfd; color:var(--text); }
input::placeholder,textarea::placeholder { color:#6f8385; }
input:focus,select:focus,textarea:focus { border-color:var(--primary); background:white; }
input:disabled,select:disabled,textarea:disabled { background:#edf2f2; color:var(--text-muted); opacity:.8; }
input[type='checkbox'],input[type='radio'] { width:20px; height:20px; min-height:0; margin:0 8px 0 0; accent-color:var(--primary); vertical-align:middle; flex-shrink:0; }
textarea { min-height:180px; resize:vertical; }
button,.btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:48px; padding:12px 18px; border:1px solid transparent; border-radius:13px; font-size:14px; line-height:1.4; font-weight:700; cursor:pointer; text-decoration:none; text-align:center; transition:background .15s,transform .15s; overflow-wrap:anywhere; }
button:active:not(:disabled),.btn:active { transform:translateY(1px); }
button:disabled { cursor:not-allowed; opacity:.55; }
.btn-primary { background:var(--primary); color:white; box-shadow:0 3px 8px rgb(8 127 121 / 12%); }
.btn-primary:hover:not(:disabled) { background:var(--primary-dark); }
.btn-secondary { background:#eef4f3; border-color:#e0eae8; color:#335455; }
.btn-secondary:hover:not(:disabled) { background:#e1eeea; }
.btn-danger { background:#fff0f3; color:var(--danger); border-color:#f3d8df; }
.btn-block { width:100%; }
.btn-sm { min-height:44px; padding:10px 13px; font-size:13px; }
.actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.actions form { margin:0; }
.error { color:var(--danger); font-size:13px; margin:6px 0 0; }
.status-banner,.error-banner { padding:15px 18px; border:1px solid #b8ded0; border-radius:14px; font-size:14px; margin-bottom:18px; overflow-wrap:anywhere; }
.status-banner { background:#edf8f2; color:#246449; }
.error-banner { border-color:#edc6d0; background:#fff0f3; color:#9f3047; }
.bottom-nav { position:fixed; bottom:0; left:0; right:0; z-index:40; padding:0 14px calc(10px + env(safe-area-inset-bottom)); pointer-events:none; }
.bottom-nav-inner { display:flex; max-width:520px; margin:auto; padding:8px; background:#fff; border:1px solid var(--border); border-radius:23px; box-shadow:0 8px 36px rgb(18 52 52 / 12%); pointer-events:auto; }
.bottom-nav a { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; min-height:56px; padding:7px 2px; color:var(--text-muted); border-radius:16px; text-decoration:none; font-size:10px; font-weight:650; }
.bottom-nav a.active { color:var(--primary-dark); background:var(--primary-soft); }
.bottom-nav .ui-icon { width:21px; height:21px; }
.fab { position:fixed; bottom:calc(100px + env(safe-area-inset-bottom)); right:max(22px,calc((100vw - 936px) / 2)); min-height:52px; width:52px; height:52px; display:grid; place-items:center; background:var(--primary); color:white; border-radius:18px; box-shadow:0 6px 22px rgb(8 127 121 / 25%); text-decoration:none; z-index:35; }
.empty-state { padding:38px 22px; text-align:center; color:var(--text-muted); border:1px dashed #cbdcda; border-radius:var(--radius); background:#fff; }
.empty-state .feature-symbol { margin:0 auto 14px; }
.empty-state h3 { color:var(--text); margin:0 0 6px; font-size:17px; }
.empty-state p { font-size:14px; margin:0 0 18px; }
.feature-symbol { display:grid; place-items:center; width:42px; height:42px; border-radius:13px; background:var(--primary-soft); color:var(--primary); flex-shrink:0; }
.feature-symbol.violet { color:#6b57a1; background:#f0edfa; }
.feature-symbol.blue { color:#326692; background:#edf4fc; }
.feature-symbol.amber { color:#8b662a; background:#faf3e6; }
.home-hero { position:relative; overflow:hidden; padding:28px; border-radius:24px; background:#183f3e; color:white; margin-bottom:22px; }
.home-hero::after { content:''; position:absolute; width:190px; height:190px; border:36px solid rgb(158 223 201 / 6%); border-radius:50%; right:-80px; top:-70px; pointer-events:none; }
.home-hero .eyebrow { color:#bddfd6; }
.home-hero h2 { margin:6px 0 10px; font-size:clamp(26px,6vw,38px); line-height:1.2; letter-spacing:-.04em; overflow-wrap:anywhere; position:relative; z-index:1; }
.home-hero p { color:#d0e6e0; max-width:640px; font-size:14px; margin:0 0 20px; overflow-wrap:anywhere; position:relative; z-index:1; }
.home-hero .btn { background:#c8ecdf; color:#183f3e; box-shadow:none; }
.home-hero .hero-secondary { border-color:#47736b; background:transparent; color:white; }
.section-heading { display:flex; gap:12px; align-items:center; justify-content:space-between; margin:26px 0 12px; }
.section-heading h2 { margin:0; font-size:17px; letter-spacing:-.025em; }
.section-heading p { margin:2px 0 0; font-size:12px; color:var(--text-muted); }
.text-link { color:var(--primary); font-size:13px; font-weight:700; text-decoration:none; min-height:44px; display:inline-flex; gap:6px; align-items:center; }
.feature-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.feature-card { display:flex; flex-direction:column; align-items:flex-start; padding:18px; background:white; border:1px solid var(--border); border-radius:18px; text-decoration:none; color:var(--text); min-width:0; box-shadow:var(--shadow); transition:border-color .15s,transform .15s; }
.feature-card:hover { border-color:#94c6bc; transform:translateY(-2px); }
.feature-card strong { margin:13px 0 3px; font-size:14px; line-height:1.4; overflow-wrap:anywhere; }
.feature-card > span:last-child { font-size:12px; color:var(--text-muted); line-height:1.45; }
.home-sync { margin:20px 0 0; color:var(--text-muted); font-size:12px; }
.recent-post { display:flex; align-items:flex-start; gap:14px; padding:18px; margin-bottom:10px; }
.recent-post .post-copy { flex:1; min-width:0; }
.recent-post h3 { font-size:15px; margin:0 0 3px; overflow-wrap:anywhere; }
.recent-post p { margin:0; font-size:13px; color:var(--text-muted); }
.auth-panel { max-width:440px; margin:12px auto; }
.auth-heading { text-align:center; margin-bottom:24px; }
.auth-heading .feature-symbol { width:64px; height:64px; border-radius:22px; margin:0 auto 18px; }
.auth-heading .ui-icon { width:30px; height:30px; }
.auth-heading h2 { margin:0 0 8px; font-size:28px; line-height:1.3; letter-spacing:-.04em; }
.auth-heading p,.auth-footer { font-size:14px; color:var(--text-muted); }
.auth-footer { text-align:center; margin:22px 0 0; }
.auth-footer a { font-weight:700; }
.divider { display:flex; align-items:center; gap:14px; margin:22px 0; font-size:12px; color:var(--text-muted); }
.divider::before,.divider::after { content:''; height:1px; flex:1; background:var(--border); }
.form-panel { max-width:680px; margin:auto; }
.photo-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:18px; }
.post-image { width:100%; display:block; border-radius:14px; max-height:480px; object-fit:contain; background:var(--bg); margin-bottom:18px; }
.user-card { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; }
.avatar { width:44px; height:44px; display:grid; place-items:center; border-radius:15px; background:var(--primary-soft); color:var(--primary-dark); font-weight:750; flex-shrink:0; }
.user-copy { flex:1; min-width:120px; overflow-wrap:anywhere; }
.user-copy h2 { font-size:15px; margin:0 0 2px; }
.user-copy p { color:var(--text-muted); font-size:13px; margin:0; }
.user-card .actions { width:100%; border-top:1px solid var(--border); padding-top:14px; }
.pagination { display:flex; flex-wrap:wrap; gap:12px; justify-content:space-between; align-items:center; margin-top:22px; font-size:13px; color:var(--text-muted); }
.center-card { max-width:500px; margin:16px auto; text-align:center; }
.center-card .feature-symbol { margin:0 auto 18px; width:64px; height:64px; border-radius:20px; }
.spinner { width:48px; height:48px; border:4px solid var(--border); border-top-color:var(--primary); border-radius:50%; margin:8px auto 24px; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
/* Shared native-screen styles preserve all existing operation and status hooks. */
.contacts-grid,.calendar-grid,.media-demo,.rc-demo,.printing-grid,.passkeys-grid,.document-picker-page,.background-transfer-page { display:grid; gap:16px; min-width:0; }
.contacts-actions,.calendar-actions,.media-actions,.rc-actions { display:flex; flex-wrap:wrap; gap:10px; margin:16px 0; }
.contacts-actions .btn,.calendar-actions .btn,.media-actions .btn,.rc-actions .btn { flex:1 1 140px; }
.media-fields,.rc-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px 12px; margin:16px 0; }
.media-fields label,.rc-fields label { display:flex; flex-direction:column; gap:7px; margin:0; }
.media-fields label > input:not([type='checkbox']),.media-fields label > select,.rc-fields label > input,.rc-fields label > select { margin-top:auto; }
pre { padding:15px; margin:16px 0 0; border:1px solid var(--border); background:#f5f8f8; border-radius:12px; white-space:pre-wrap; overflow-wrap:anywhere; max-height:300px; overflow:auto; font:12px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace; color:#3c585a; }
.card dl { margin:18px 0 0; }
dt { font-size:12px; color:var(--text-muted); font-weight:650; }
dd { margin:0; font-size:14px; overflow-wrap:anywhere; }
.rc-demo dl { display:grid; grid-template-columns:minmax(110px,.8fr) minmax(0,1fr); gap:0 16px; }
.rc-demo dt,.rc-demo dd { padding:10px 0; border-bottom:1px solid var(--border); }
.rc-table { overflow:auto; max-width:100%; border:1px solid var(--border); border-radius:12px; margin:18px 0; }
.rc-table table { border-collapse:collapse; width:100%; min-width:560px; font-size:13px; }
.rc-table td,.rc-table th { padding:14px; text-align:left; vertical-align:top; border-bottom:1px solid var(--border); overflow-wrap:anywhere; max-width:260px; }
.rc-table thead { background:var(--primary-soft); }
.rc-table small { color:var(--text-muted); font-weight:400; }
.rc-table p { margin:6px 0 0; font-size:12px; color:var(--danger); }
.rc-table [data-rc-reason]:empty { display:none; }
.media-job { margin-top:18px; padding:18px; border:1px solid var(--border); border-radius:14px; background:#fbfdfd; }
.media-job h3 { font-size:15px; }
progress { width:100%; height:12px; accent-color:var(--primary); }
.media-job img,.media-job video { display:block; max-width:100%; max-height:380px; border-radius:12px; margin-top:16px; }
.printing-copy,.passkeys-copy,.background-transfer-copy { margin:0; color:var(--text-muted); font-size:14px; line-height:1.65; }
.printing-file-name { padding:14px; margin-top:16px; border-radius:12px; background:var(--primary-soft); color:var(--primary-dark); overflow-wrap:anywhere; font-size:14px; font-weight:650; }
.printing-capabilities,.passkeys-capabilities { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-top:18px; }
.passkeys-capabilities { grid-template-columns:repeat(3,minmax(0,1fr)); }
.printing-capability,.passkeys-capability { padding:16px 10px; border:1px solid var(--border); border-radius:14px; text-align:center; background:#f8fbfa; }
.printing-capability strong,.passkeys-capability strong { font-size:14px; display:block; overflow-wrap:anywhere; }
.printing-capability span,.passkeys-capability span { display:block; margin-top:5px; font-size:11px; color:var(--text-muted); }
.printing-actions { display:grid; gap:12px; }
.background-transfer-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.full-width { grid-column:1/-1; }
.passkeys-requirements { margin:16px 0 0; padding-left:20px; font-size:14px; line-height:1.8; color:var(--text-muted); }
.passkeys-warning { padding:18px; border:1px solid #e9d7ae; background:#fff9ec; border-radius:16px; color:#75571f; font-size:14px; }
.document-picker-status,.background-transfer-status,.printing-status-panel,.passkeys-status { padding:20px; border:1px solid var(--border); border-radius:16px; background:white; }
[data-tone] strong { display:block; font-size:15px; }
[data-tone] p { margin:6px 0 0; font-size:14px; color:var(--text-muted); overflow-wrap:anywhere; white-space:pre-line; }
[data-tone='success'] { border-color:#b4dbc6; background:#eef9f2; color:#276446; }
[data-tone='error'] { border-color:#efc6d0; background:#fff1f4; color:var(--danger); }
[data-tone='warning'] { border-color:#e9d7ae; background:#fff9ec; color:#75571f; }
[data-tone='info'] { border-color:#b7dcd5; background:#eef9f6; }
.background-transfer-progress { height:9px; margin-top:16px; overflow:hidden; border-radius:999px; background:var(--border); }
.background-transfer-progress-bar { width:0; height:100%; border-radius:inherit; background:var(--primary); transition:width .18s; }
.background-transfer-list { display:grid; gap:10px; margin-top:16px; }
.background-transfer-item { padding:16px; border:1px solid var(--border); border-radius:14px; }
.background-transfer-item strong { display:block; font-size:14px; }
.background-transfer-item span { display:block; margin-top:6px; font-size:12px; overflow-wrap:anywhere; color:var(--text-muted); }
.background-transfer-empty { color:var(--text-muted); font-size:14px; }
.background-transfer-meta,.document-picker-metadata { display:grid; gap:0; }
.background-transfer-meta div,.document-picker-metadata div { display:grid; grid-template-columns:minmax(85px,.4fr) minmax(0,1fr); gap:14px; padding:12px 0; border-bottom:1px solid var(--border); }
.document-picker-badge { display:inline-flex; margin-top:16px; padding:6px 10px; border-radius:8px; background:var(--primary-soft); color:var(--primary-dark); font-size:11px; font-weight:650; }
.document-picker-fieldset { padding:0; border:0; margin:20px 0; min-width:0; }
.document-picker-types { display:grid; gap:10px; }
.document-picker-type { display:flex; align-items:center; margin:0; padding:14px; min-height:48px; border:1px solid var(--border); border-radius:12px; cursor:pointer; }
.document-picker-type:has(input:checked) { border-color:#95c6ba; background:var(--primary-soft); }
.document-picker-type code { font-size:12px; overflow-wrap:anywhere; min-width:0; }
.document-picker-size-row { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:12px; }
.document-picker-size-unit { color:var(--text-muted); font-weight:650; }
.document-picker-help { color:var(--text-muted); font-size:13px; }
.document-picker-submit { margin-top:12px; }
#rc-home-status,#rc-message,#media-message,#calendar-message,#contacts-message { overflow-wrap:anywhere; }
#calendar-message,#contacts-message { margin:0; color:var(--text-muted); font-size:13px; }
@media(max-width:599px) { .rc-fields { grid-template-columns:minmax(0,1fr); } }
@media(min-width:720px) { .feature-grid { grid-template-columns:repeat(4,minmax(0,1fr)); } .content { padding-top:32px; } .home-hero { padding:36px; } .user-card .actions { width:auto; border:0; padding:0; } }
@media(max-width:359px) { .content { padding:20px 14px 32px; } .app-bar-inner { padding-left:14px; padding-right:14px; } .card { padding:18px; } .feature-grid,.media-fields,.rc-fields,.photo-actions { grid-template-columns:minmax(0,1fr); } .feature-card { flex-direction:row; flex-wrap:wrap; align-items:center; gap:10px; } .feature-card strong { margin:0; flex:1; } .feature-card > span:last-child { width:100%; } .home-hero { padding:24px; } .passkeys-capabilities { grid-template-columns:minmax(0,1fr); } .rc-demo dl { grid-template-columns:minmax(85px,.65fr) minmax(0,1fr); gap:0 10px; } }
@media(prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; } }
</style>
