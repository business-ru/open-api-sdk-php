<?php

namespace Open\Api\Adapter\Log;

use Monolog\DateTimeImmutable;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Open\Api\Exception\SimpleLogException;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;

class Logger implements LoggerInterface
{
    /**
     * @var MonologLogger
     */
    private MonologLogger $logger;

    /**
     * Директория логов
     * @var string
     */
    private string $logPath;

    /**
     * The Log levels.
     *
     * @var array
     */
    private array $levels = [
        'debug' => Level::Debug,
        'info' => Level::Info,
        'notice' => Level::Notice,
        'warning' => Level::Warning,
        'error' => Level::Error,
        'critical' => Level::Critical,
        'alert' => Level::Alert,
        'emergency' => Level::Emergency,
    ];

    /**
     * Parse the string level into a Monolog constant.
     *
     * @param array $config
     * @return int
     *
     * @throws InvalidArgumentException
     */
    private function level(array $config): int
    {
        $level = $config['level'] ?? 'debug';

        if (isset($this->levels[$level])) {
            return $this->levels[$level]->value;
        }

        throw new InvalidArgumentException('Invalid log level.');
    }

    private function writeLog(string $level, string $message, array $context = []): void
    {
        $formatter = new LogstashFormatter('OpenApiSDK');
        $record = new LogRecord(
            datetime: new DateTimeImmutable(true),
            channel: 'daily',
            level: MonologLogger::toMonologLevel($level),
            message: $message,
            context: $context
        );
        $formatter->format($record);
        $logFileDaily = $level . '-' . date("Y-m-d");
        $handler = new StreamHandler($this->logPath . "/$logFileDaily.log", $this->level(['level' => $level]));
        $handler->setFormatter($formatter);

        $this->logger->pushHandler($handler);
        $this->logger->$level($message, $context);
    }

    public function __construct()
    {
        $this->logPath = getenv('PROJECT_LOGS_DIR');
        if (!$this->logPath) {
            $this->logPath = __DIR__ . '/../../../logs';
        }
        if (!is_dir($this->logPath)
            && !mkdir($concurrentDirectory = $this->logPath)
            && !is_dir($concurrentDirectory)) {
            throw new SimpleLogException('Невозможно создать директорию для хранения логов logs');
        }
        $this->logger = new MonologLogger('OpenApiSDK');
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('info', $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('error', $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('warning', $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('critical', $message, $context);
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('emergency', $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('alert', $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('notice', $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->writeLog('debug', $message, $context);
    }
}
