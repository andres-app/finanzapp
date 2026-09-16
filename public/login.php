<?php
require __DIR__.'/../app/bootstrap.php';
if (!empty($_SESSION['user_id'])) { header('Location: '.app_url('dashboard')); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $st=db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $st->execute([trim($_POST['email']??'')]); $u=$st->fetch();
  if ($u && password_verify($_POST['password']??'', $u['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=(int)$u['id']; unset($_SESSION['csrf']); csrf_token(); header('Location: '.app_url('dashboard')); exit; }
  $error='Correo o contraseña incorrectos.';
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finanzapp</title><link rel="stylesheet" href="<?=e(app_url('assets/css/app.css'))?>"></head><body class="login-bg">
<div class="login-shell"><div class="login-brand"><span class="logo-symbol"><i></i><i></i><i></i><i></i></span><b>Finanzapp</b></div><div class="login-card"><span class="eyebrow">FINANZAS FAMILIARES</span><h1>Bienvenido</h1><p>Ingresa para revisar tus ingresos, gastos y metas en tiempo real.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post"><label>Correo</label><input type="email" name="email" autocomplete="email" required><label>Contraseña</label><input type="password" name="password" autocomplete="current-password" required><button class="btn primary block">Ingresar</button></form></div><small class="login-foot">Control privado de tus finanzas.</small></div></body></html>
