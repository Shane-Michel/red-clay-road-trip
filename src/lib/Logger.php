<?php

declare(strict_types=1);

final class Logger
{
    private const LOG_DIRECTORY = '/data/logs';
    private const LOG_FILENAME = 'app.log';

    /** @var bool */
    private static $initialized = false;

    public static function setup(): void
    {
        if (self::$initialized) {
            return;
        }

        $rootPath = dirname(__DIR__, 2);
        $logDirectory = $rootPath . self::LOG_DIRECTORY;

        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
            error_log('Logger setup failed: unable to create log directory at ' . $logDirectory);
            self::$initialized = true;
            return;
        }

        $logPath = $logDirectory . '/' . self::LOG_FILENAME;

        if (!file_exists($logPath)) {
            if (@file_put_contents($logPath, '') === false) {
                error_log('Logger setup failed: unable to create log file at ' . $logPath);
                self::$initialized = true;
                return;
            }
        }

        ini_set('log_errors', '1');
        ini_set('error_log', $logPath);

        self::$initialized = true;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::setup();
        }

        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $message .= ' ' . $encoded;
            }
        }

        error_log($message);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function logThrowable(\Throwable $throwable, array $context = []): void
    {
        $context['exception'] = [
            'type' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile() . ':' . $throwable->getLine(),
        ];

        self::log('Unhandled exception', $context);
    }
}
