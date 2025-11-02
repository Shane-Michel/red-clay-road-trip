<?php

declare(strict_types=1);

final class Auth
{
    private const DB_FILE = '/data/users.sqlite';

    private static ?\PDO $connection = null;
    private static bool $sessionInitialized = false;

    public static function ensureSession(): void
    {
        if (self::$sessionInitialized) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            return;
        }

        self::$sessionInitialized = true;

        if (session_status() === PHP_SESSION_NONE) {
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $params = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function currentUser(): ?array
    {
        self::ensureSession();
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            return null;
        }

        $user = $_SESSION['user'];
        $required = ['id', 'email', 'scope_key', 'created_at'];
        foreach ($required as $field) {
            if (!isset($user[$field])) {
                return null;
            }
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'scope_key' => (string) $user['scope_key'],
            'created_at' => (string) $user['created_at'],
        ];
    }

    public static function currentScopeKey(): string
    {
        $user = self::currentUser();
        return $user ? (string) $user['scope_key'] : 'public';
    }

    public static function register(string $email, string $password): array
    {
        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail === '') {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long.');
        }

        $pdo = self::getConnection();

        $existing = self::findUserByEmail($normalizedEmail, $pdo);
        if ($existing) {
            throw new \RuntimeException('An account with this email already exists. Sign in instead.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('Unable to hash password. Please try again.');
        }

        $scopeKey = self::generateScopeKey($pdo);
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, scope_key, created_at) VALUES (:email, :password_hash, :scope_key, :created_at)');
        $stmt->execute([
            ':email' => $normalizedEmail,
            ':password_hash' => $passwordHash,
            ':scope_key' => $scopeKey,
            ':created_at' => $createdAt,
        ]);

        $user = [
            'id' => (int) $pdo->lastInsertId(),
            'email' => $normalizedEmail,
            'scope_key' => $scopeKey,
            'created_at' => $createdAt,
        ];

        self::storeInSession($user);

        return $user;
    }

    public static function login(string $email, string $password): array
    {
        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail === '') {
            throw new \InvalidArgumentException('Email is required.');
        }

        if ($password === '') {
            throw new \InvalidArgumentException('Password is required.');
        }

        $pdo = self::getConnection();
        $user = self::findUserByEmail($normalizedEmail, $pdo);
        if (!$user) {
            throw new \RuntimeException('Invalid email or password.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new \RuntimeException('Invalid email or password.');
        }

        $userData = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'scope_key' => (string) $user['scope_key'],
            'created_at' => (string) $user['created_at'],
        ];

        self::storeInSession($userData);

        return $userData;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, [
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => $params['secure'] ?? false,
                    'httponly' => $params['httponly'] ?? true,
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }
            session_destroy();
        }
        self::$sessionInitialized = false;
    }

    private static function storeInSession(array $user): void
    {
        self::ensureSession();
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'scope_key' => (string) $user['scope_key'],
            'created_at' => (string) $user['created_at'],
        ];
    }

    private static function normalizeEmail(string $email): string
    {
        $trimmed = trim($email);
        if ($trimmed === '') {
            return '';
        }

        $lowercased = strtolower($trimmed);
        return filter_var($lowercased, FILTER_VALIDATE_EMAIL) ? $lowercased : '';
    }

    private static function generateScopeKey(\PDO $pdo): string
    {
        do {
            $key = bin2hex(random_bytes(20));
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE scope_key = :key LIMIT 1');
            $exists->execute([':key' => $key]);
            $found = $exists->fetchColumn();
        } while ($found);

        return $key;
    }

    private static function findUserByEmail(string $email, ?\PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? self::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, scope_key, created_at FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'password_hash' => (string) $user['password_hash'],
            'scope_key' => (string) $user['scope_key'],
            'created_at' => (string) $user['created_at'],
        ];
    }

    private static function getConnection(): \PDO
    {
        if (self::$connection instanceof \PDO) {
            return self::$connection;
        }

        $root = dirname(__DIR__, 2);
        $path = $root . self::DB_FILE;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            scope_key TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        )');

        self::$connection = $pdo;
        return $pdo;
    }
}
