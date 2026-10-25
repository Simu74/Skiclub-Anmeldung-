<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$_SESSION = [];
session_destroy();
redirect('login.php');
