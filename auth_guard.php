<?php
// auth_guard.php
session_start();

if (!isset($_SESSION["user_id"])) {
  header("Location: login.php");
  exit;
}