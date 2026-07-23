<?php

class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const LEVELS = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    private string $minLevel;
    private string $channel;
    private static ?Logger $instance = null;

    public function __construct(string $channel = 'dotly')
    {
        $this->channel = $channel;
        $this->minLevel = defined('LOG_LEVEL') ? constant('LOG_LEVEL') : self::INFO;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level]) || !isset(self::LEVELS[$this->minLevel])) {
            return;
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $entry = [
            'timestamp' => date('Y-m-d\TH:i:s.vP'),
            'channel'   => $this->channel,
            'level'     => strtoupper($level),
            'message'   => $message,
        ];

        if (!empty($context)) {
            $entry['context'] = $this->sanitize($context);
        }

        error_log(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sanitize(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value instanceof \Throwable) {
                $result[$key] = [
                    'type'    => get_class($value),
                    'message' => $value->getMessage(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                ];
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || is_null($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
