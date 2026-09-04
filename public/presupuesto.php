<?php
require __DIR__.'/../app/bootstrap.php';
require_auth();
header('Location: '.app_url('ahorro'), true, 302);
exit;
