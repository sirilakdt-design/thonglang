<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentRole(): string
{
    return strtolower(trim((string)($_SESSION['role'] ?? '')));
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0
        && currentRole() !== 'deputy_director'
        && !in_array(strtolower(trim((string)($_SESSION['username'] ?? ''))), ['deputy director','g1','g2','g3','g4','d1','gee'], true);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function isAdmin(): bool
{
    return currentRole() === 'admin';
}

function isDoctor(): bool
{
    return currentRole() === 'doctor';
}

function isCaregiver(): bool
{
    return currentRole() === 'caregiver';
}

function requireRole(string ...$roles): void
{
    requireLogin();

    $allowed = array_map(
        static fn($role) => strtolower(trim((string)$role)),
        $roles
    );

    if (!in_array(currentRole(), $allowed, true)) {
        http_response_code(403);
        echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ไม่มีสิทธิ์เข้าถึง</title><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>body{font-family:"Noto Sans Thai",sans-serif;background:#FFF8DC;color:#000000;display:grid;place-items:center;min-height:100vh;margin:0}.box{background:#fff;padding:32px;border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.08);text-align:center;max-width:520px}.box h1{color:#000000}.box a{display:inline-block;margin-top:12px;padding:10px 18px;border-radius:10px;background:#00CC00;color:#000000;border:1px solid #00CC00;text-decoration:none}</style></head><body><div class="box"><h1>ไม่มีสิทธิ์เข้าถึงหน้านี้</h1><p>บัญชีที่กำลังเข้าสู่ระบบไม่มีสิทธิ์ใช้งานหน้านี้</p><a href="home.php">กลับหน้าแรก</a></div></body></html>';
        exit;
    }
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
