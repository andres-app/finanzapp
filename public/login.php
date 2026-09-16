<?php
require __DIR__.'/../app/bootstrap.php';
if (!empty($_SESSION['user_id'])) { header('Location: '.app_url('dashboard')); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $st=db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $st->execute([trim($_POST['email']??'')]); $u=$st->fetch();
  if ($u && password_verify($_POST['password']??'', $u['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['user_id']=(int)$u['id'];
    unset($_SESSION['csrf']);
    csrf_token();

    // Mantener iniciada la sesión en este dispositivo hasta que el usuario
    // pulse explícitamente "Cerrar sesión".
    try { AuthPersistence::issue((int)$u['id']); } catch (Throwable $e) {
      error_log('Finanzapp persistent auth login: '.$e->getMessage());
    }
    AuthPersistence::refreshSessionCookie();

    header('Location: '.app_url('dashboard')); exit;
  }
  $error='Correo o contraseña incorrectos.';
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#0f172a">
  <title>Finanzapp · Acceso</title>
  <link rel="stylesheet" href="<?=e(app_url('assets/css/app.css'))?>?v=20260915-login2">
  <style>
  /* Login Finanzapp 2026: estilos críticos embebidos para no depender de caché del CSS global */
  :root{--ink:#0b1220;--muted:#7b8798;--line:#e6eaf0;--green:#8ff3ca;--green2:#57ddb0;--panel:#fbfcfd}
  *{box-sizing:border-box}
  html,body{margin:0;min-height:100%;font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:#f6f8fb}
  body.login-2026{min-height:100vh;overflow-x:hidden}
  .auth-stage{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.12fr) minmax(420px,.88fr);background:#f7f9fb}
  .auth-visual{position:relative;overflow:hidden;min-height:100vh;padding:clamp(34px,4.2vw,70px);display:flex;flex-direction:column;color:#fff;background:radial-gradient(circle at 18% 14%,rgba(83,255,190,.17),transparent 31%),radial-gradient(circle at 85% 78%,rgba(102,126,255,.20),transparent 34%),linear-gradient(145deg,#08111f 0%,#101a2d 52%,#0b1425 100%)}
  .auth-visual:before{content:"";position:absolute;inset:0;opacity:.38;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:38px 38px;mask-image:linear-gradient(to bottom,#000,transparent 86%)}
  .auth-visual:after{content:"";position:absolute;width:460px;height:460px;border:1px solid rgba(255,255,255,.06);border-radius:50%;right:-190px;top:8%;box-shadow:0 0 0 72px rgba(255,255,255,.012),0 0 0 145px rgba(255,255,255,.009)}
  .auth-visual-glow{position:absolute;border-radius:999px;filter:blur(75px);pointer-events:none}.auth-visual-glow-a{width:280px;height:280px;background:rgba(83,255,189,.11);left:-90px;top:25%}.auth-visual-glow-b{width:330px;height:330px;background:rgba(111,122,255,.12);right:-90px;bottom:-70px}
  .auth-brand{position:relative;z-index:2;display:flex;align-items:center;gap:11px;font-size:19px}.auth-brand strong{letter-spacing:-.04em}.auth-brand-chip{margin-left:6px;padding:7px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.055);font-size:10px;font-weight:800;letter-spacing:.05em;color:rgba(255,255,255,.66);text-transform:uppercase}
  .logo-symbol{display:grid;grid-template-columns:repeat(2,7px);grid-template-rows:repeat(2,7px);gap:2px;transform:rotate(45deg);width:16px;height:16px;flex:0 0 16px}.logo-symbol i{display:block;background:#0f172a;border-radius:2px}.logo-symbol-light i{background:#fff}
  .auth-visual-copy{position:relative;z-index:2;margin:auto 0 34px;max-width:730px}.auth-kicker{display:block;margin-bottom:18px;color:#7ef1c5;font-size:11px;font-weight:850;letter-spacing:.16em}.auth-visual-copy h1{max-width:680px;margin:0;font-size:clamp(44px,5vw,78px);line-height:.98;letter-spacing:-.065em;font-weight:780}.auth-visual-copy h1 span{color:#9affd5}.auth-visual-copy p{max-width:570px;margin:26px 0 0;color:rgba(234,240,248,.66);font-size:15px;line-height:1.66}
  .auth-preview{position:relative;z-index:2;width:min(690px,94%);padding:22px;border:1px solid rgba(255,255,255,.10);border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.085),rgba(255,255,255,.035));box-shadow:0 28px 80px rgba(0,0,0,.26);backdrop-filter:blur(18px)}
  .auth-preview-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.auth-preview-top>div{display:grid;gap:5px}.auth-preview-top span,.auth-preview-grid span{font-size:10px;color:rgba(255,255,255,.54)}.auth-preview-top strong{font-size:31px;letter-spacing:-.05em;color:#a0ffd7}.auth-live-dot{display:inline-flex!important;align-items:center;gap:7px;padding:6px 9px;border-radius:999px;background:rgba(105,246,191,.08);border:1px solid rgba(105,246,191,.13);color:#9ff7d2!important;font-weight:750}.auth-live-dot i{width:6px;height:6px;border-radius:50%;background:#72f2bd;box-shadow:0 0 0 4px rgba(114,242,189,.10)}
  .auth-preview-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}.auth-preview-grid div{display:grid;gap:5px;padding:12px 13px;border-radius:13px;background:rgba(8,15,28,.34);border:1px solid rgba(255,255,255,.05)}.auth-preview-grid b{font-size:13px;color:#f6f8fb}.auth-preview-chart{height:72px;margin-top:18px;display:flex;align-items:end;gap:6px;padding-top:10px;border-top:1px solid rgba(255,255,255,.06)}.auth-preview-chart span{flex:1;height:var(--h);min-height:10px;border-radius:5px 5px 2px 2px;background:linear-gradient(to top,rgba(107,248,194,.18),rgba(107,248,194,.78));opacity:.9}
  .auth-visual-footer{position:relative;z-index:2;display:flex;justify-content:space-between;gap:16px;margin-top:25px;color:rgba(255,255,255,.4);font-size:10px}.auth-visual-footer span:first-child{color:rgba(137,246,205,.64)}
  .auth-panel{min-height:100vh;display:flex;flex-direction:column;padding:clamp(30px,4vw,60px);background:linear-gradient(180deg,#fdfefe 0%,#f7f9fb 100%);border-left:1px solid #e8ecf1}.auth-mobile-brand{display:none}.auth-panel-inner{width:min(430px,100%);margin:auto}.auth-heading .eyebrow{display:block;margin-bottom:13px;font-size:10px;font-weight:850;letter-spacing:.17em;color:#7d8999}.auth-heading h2{margin:0;color:#0c1422;font-size:38px;line-height:1.05;letter-spacing:-.055em}.auth-heading p{margin:11px 0 31px;color:#8490a0;font-size:13px;line-height:1.5}
  .auth-error{display:flex;gap:11px;align-items:flex-start;margin:0 0 18px;padding:13px 14px;border:1px solid #f4c7c7;border-radius:13px;background:#fff4f4;color:#9d2b2b}.auth-error>span{display:grid;place-items:center;width:22px;height:22px;flex:0 0 22px;border-radius:50%;background:#e5484d;color:#fff;font-size:12px;font-weight:800}.auth-error div{display:grid;gap:2px}.auth-error b{font-size:11px}.auth-error small{font-size:10px;color:#a95858}
  .auth-form{display:grid;gap:20px}.auth-field{display:grid;gap:8px}.auth-field label{font-size:11px;font-weight:750;color:#303b4a}.auth-label-row{display:flex;align-items:center;justify-content:space-between;gap:12px}.auth-label-row span{font-size:9px;color:#9aa3af}.auth-input-wrap{position:relative}.auth-input-wrap input{appearance:none;width:100%;height:56px;padding:0 48px 0 46px;border:1px solid #dfe4eb;border-radius:15px;background:#fff;color:#101828;font:inherit;font-size:14px;outline:none;box-shadow:0 1px 1px rgba(16,24,40,.02);transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}.auth-input-wrap input::placeholder{color:#b0b7c2}.auth-input-wrap input:focus{border-color:#96a5b9;box-shadow:0 0 0 4px rgba(36,54,82,.07),0 6px 22px rgba(15,23,42,.05)}.auth-input-icon{position:absolute;z-index:2;left:16px;top:50%;width:18px;height:18px;transform:translateY(-50%);color:#8290a1}.auth-input-icon svg,.auth-password-toggle svg,.auth-submit svg,.auth-trust-icon svg{width:100%;height:100%;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
  .auth-password-toggle{position:absolute;right:10px;top:50%;width:34px;height:34px;transform:translateY(-50%);display:grid;place-items:center;border:0;border-radius:10px;background:transparent;color:#8995a5;cursor:pointer}.auth-password-toggle:hover{background:#f1f3f6;color:#29374a}.auth-password-toggle svg{width:18px;height:18px}.auth-password-toggle .eye-closed{display:none}.auth-password-toggle[aria-pressed="true"] .eye-open{display:none}.auth-password-toggle[aria-pressed="true"] .eye-closed{display:block}
  .auth-submit{height:57px;margin-top:3px;padding:0 18px;display:flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:15px;background:#0f172a;color:#fff;font-size:12px;font-weight:800;letter-spacing:-.01em;cursor:pointer;box-shadow:0 12px 26px rgba(15,23,42,.16);transition:transform .18s ease,box-shadow .18s ease,background .18s ease}.auth-submit:hover{background:#162239;transform:translateY(-1px);box-shadow:0 15px 32px rgba(15,23,42,.20)}.auth-submit:active{transform:translateY(0)}.auth-submit svg{width:17px;height:17px}
  .auth-trust{display:flex;align-items:flex-start;gap:12px;margin-top:26px;padding:14px 15px;border:1px solid #e6e9ee;border-radius:15px;background:rgba(255,255,255,.72)}.auth-trust-icon{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:10px;background:#eafaf3;color:#16815d}.auth-trust-icon svg{width:16px;height:16px}.auth-trust>div:last-child{display:grid;gap:3px}.auth-trust b{font-size:10px;color:#3a4655}.auth-trust span{font-size:9px;line-height:1.45;color:#929ba8}.auth-panel-footer{text-align:center;color:#a0a8b4;font-size:9px;margin-top:28px}
  @media (max-width:980px){.auth-stage{grid-template-columns:1fr}.auth-visual{min-height:330px;padding:28px 34px 42px}.auth-visual-copy{margin:70px 0 25px}.auth-visual-copy h1{font-size:clamp(36px,8vw,58px);max-width:740px}.auth-preview{display:none}.auth-visual-footer{margin-top:auto}.auth-panel{min-height:auto;padding:38px 26px 44px;border-left:0}.auth-panel-inner{width:min(520px,100%)}}
  @media (max-width:640px){body.login-2026{background:#f8fafc}.auth-stage{display:block}.auth-visual{display:none}.auth-panel{min-height:100dvh;padding:24px 20px 28px}.auth-mobile-brand{display:flex;align-items:center;gap:10px;font-size:17px;margin-bottom:72px}.auth-mobile-brand strong{letter-spacing:-.04em}.auth-panel-inner{margin:0 auto}.auth-heading h2{font-size:32px}.auth-heading p{margin-bottom:27px}.auth-input-wrap input{height:56px;font-size:16px}.auth-submit{height:57px}.auth-panel-footer{margin-top:auto;padding-top:42px}.auth-trust{margin-top:22px}}
  @media (prefers-reduced-motion:reduce){.auth-submit,.auth-input-wrap input{transition:none}}
  </style>
</head>
<body class="login-bg login-2026">
  <main class="auth-stage">
    <section class="auth-visual" aria-label="Finanzapp">
      <div class="auth-visual-glow auth-visual-glow-a"></div>
      <div class="auth-visual-glow auth-visual-glow-b"></div>

      <div class="auth-brand auth-brand-light">
        <span class="logo-symbol logo-symbol-light" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
        <strong>Finanzapp</strong>
        <span class="auth-brand-chip">Finanzas familiares</span>
      </div>

      <div class="auth-visual-copy">
        <span class="auth-kicker">TU DINERO, SIN COMPLICARLO</span>
        <h1>Todo lo importante de tus finanzas, <span>en un solo lugar.</span></h1>
        <p>Controla saldos, compromisos, ahorro y movimientos con una vista clara para toda la familia.</p>
      </div>

      <div class="auth-preview" aria-hidden="true">
        <div class="auth-preview-top">
          <div>
            <span>Saldo disponible</span>
            <strong>S/ 4,551.10</strong>
          </div>
          <span class="auth-live-dot"><i></i> En línea</span>
        </div>
        <div class="auth-preview-grid">
          <div><span>Por pagar</span><b>S/ 3,740.00</b></div>
          <div><span>En fondos</span><b>S/ 0.00</b></div>
          <div><span>En ahorro</span><b>S/ 0.00</b></div>
        </div>
        <div class="auth-preview-chart">
          <span style="--h:44%"></span><span style="--h:63%"></span><span style="--h:38%"></span><span style="--h:75%"></span><span style="--h:52%"></span><span style="--h:84%"></span><span style="--h:68%"></span><span style="--h:92%"></span><span style="--h:76%"></span>
        </div>
      </div>

      <div class="auth-visual-footer">
        <span>● Actualización en tiempo real</span>
        <span>Privado para tu hogar</span>
      </div>
    </section>

    <section class="auth-panel">
      <div class="auth-mobile-brand">
        <span class="logo-symbol" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
        <strong>Finanzapp</strong>
      </div>

      <div class="auth-panel-inner">
        <div class="auth-heading">
          <span class="eyebrow">ACCESO SEGURO</span>
          <h2>Bienvenido de nuevo</h2>
          <p>Ingresa a tu espacio financiero familiar.</p>
        </div>

        <?php if($error):?>
          <div class="auth-error" role="alert">
            <span aria-hidden="true">!</span>
            <div><b>No pudimos iniciar sesión</b><small><?=e($error)?></small></div>
          </div>
        <?php endif;?>

        <form method="post" class="auth-form" autocomplete="on">
          <div class="auth-field">
            <label for="auth-email">Correo</label>
            <div class="auth-input-wrap">
              <span class="auth-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M4 6.5h16v11H4z"/><path d="m5 7.5 7 5 7-5"/></svg>
              </span>
              <input id="auth-email" type="email" name="email" autocomplete="email" inputmode="email" placeholder="tu@correo.com" value="<?=e($_POST['email']??'')?>" required autofocus>
            </div>
          </div>

          <div class="auth-field">
            <div class="auth-label-row"><label for="auth-password">Contraseña</label><span>Sesión persistente</span></div>
            <div class="auth-input-wrap">
              <span class="auth-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
              </span>
              <input id="auth-password" type="password" name="password" autocomplete="current-password" placeholder="Tu contraseña" required>
              <button class="auth-password-toggle" type="button" aria-label="Mostrar contraseña" aria-pressed="false">
                <svg class="eye-open" viewBox="0 0 24 24" fill="none"><path d="M2.5 12s3.4-5 9.5-5 9.5 5 9.5 5-3.4 5-9.5 5-9.5-5-9.5-5Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                <svg class="eye-closed" viewBox="0 0 24 24" fill="none"><path d="m4 4 16 16"/><path d="M10.6 7.2A9.8 9.8 0 0 1 12 7c6.1 0 9.5 5 9.5 5a14 14 0 0 1-2.2 2.6M6.5 6.6C3.8 8.3 2.5 12 2.5 12s3.4 5 9.5 5a10 10 0 0 0 3.1-.5"/></svg>
              </button>
            </div>
          </div>

          <button class="auth-submit" type="submit">
            <span>Ingresar a Finanzapp</span>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg>
          </button>
        </form>

        <div class="auth-trust">
          <div class="auth-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"><path d="M12 3 5 6v5c0 4.6 2.8 7.8 7 10 4.2-2.2 7-5.4 7-10V6l-7-3Z"/><path d="m9.5 12 1.7 1.7 3.6-4"/></svg>
          </div>
          <div><b>Tu sesión permanece activa en este dispositivo</b><span>Se cerrará únicamente cuando pulses “Cerrar sesión”.</span></div>
        </div>
      </div>

      <footer class="auth-panel-footer">Finanzapp · Control privado de tus finanzas</footer>
    </section>
  </main>

  <script>
  (() => {
    const btn = document.querySelector('.auth-password-toggle');
    const input = document.getElementById('auth-password');
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
      const visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
      btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
    });
  })();
  </script>
</body>
</html>
