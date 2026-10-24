<?php
declare(strict_types=1);

// Adjust these values for the local XAMPP installation.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'kapetbahay';
const DB_USER = 'root';
const DB_PASS = '';
const BASE_URL = '/kapetbahay';

date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    exit('Database connection failed. Check config/db.php.');
}
