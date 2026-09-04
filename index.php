<?php
require __DIR__.'/app/bootstrap.php';
header('Location: ' . app_url(!empty($_SESSION['user_id']) ? 'dashboard' : 'login'));
exit;
