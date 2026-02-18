<?php
$host = 'localhost';
$db = 'sakila';
$user = 'root';
$pass = 'root';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
