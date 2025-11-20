<?php
// logout.php
require_once "config.php";

$_SESSION = array();
session_destroy();

header("Location: login_form.php");
exit;
?>
