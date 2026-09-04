<?php require __DIR__.'/../app/bootstrap.php'; session_destroy(); header('Location: '.app_url('login')); exit;
