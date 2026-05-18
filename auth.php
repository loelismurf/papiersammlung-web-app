<?php
function auth_check(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php'); exit;
    }
}

function auth_admin(): void {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header('Location: index.php'); exit;
    }
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function me_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function me_name(): string { return $_SESSION['username'] ?? ''; }
function me_role(): string { return $_SESSION['role'] ?? 'user'; }
