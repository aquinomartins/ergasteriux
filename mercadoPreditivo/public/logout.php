<?php
// public/logout.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

session_unset();
session_destroy();

header('Location: /public/login.php');
exit;
