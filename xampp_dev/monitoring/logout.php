<?php
// Выход из системы
session_start();
session_destroy();
header('Location: login.php');
exit;

