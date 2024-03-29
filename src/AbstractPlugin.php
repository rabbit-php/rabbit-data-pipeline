<?php

declare(strict_types=1);

namespace Rabbit\Data\Pipeline;

use Closure;
use Psr\SimpleCache\CacheInterface;
use Rabbit\Base\App;
use Rabbit\Base\Contract\InitInterface;
use Rabbit\Base\Core\BaseObject;
use Throwable;

/**
 * Interface AbstractPlugin
 * @package Rabbit\Data\Pipeline
 * @property Scheduler scheduler
 */
abstract class AbstractPlugin extends BaseObject implements InitInterface
{
    public string $taskName;
    public string $key;
    public array $output = [];
    protected bool $start = false;
    protected ?CacheInterface $cache;
    const CACHE_KEY = 'cache';
    const CALL_PREFIX = 'outputs';
    protected ?array $errHandler;
    protected ?array $lockKey = [];
    protected bool $canEmpty = false;
    private ?string $callKey = null;
    private Closure $func;

    public ?string $alarm = null;
    /**
     * AbstractPlugin constructor.
     * @param string $scName
     * @param array $config
     */
    final public function __construct(protected readonly string $scName, protected readonly array $config)
    {
        $this->func = function (string $output, Message $msg): void {
            if (empty($msg->data)) {
                $log = "「{$this->taskName}」 $this->key -> $output; data is empty, %s";
                if (!$this->canEmpty) {
                    App::info(sprintf($log, 'canEmpty is false so not sink next'));
                    return;
                }
                App::info(sprintf($log, 'canEmpty is true so continue sink next'));
            } else {
                App::info("「{$this->taskName}」 $this->key -> $output;");
            }
            $this->getScheduler()->next($msg, $output);
        };
    }

    public function getCallKey(): ?string
    {
        return $this->callKey;
    }

    /**
     * @return mixed|void
     * @throws Throwable
     */
    public function init(): void
    {
        $this->cache = create(self::CACHE_KEY);
        $this->lockKey = $this->config['lockKey'] ?? [];
        $this->callKey = self::CALL_PREFIX . '.' . $this->key;
    }

    /**
     * @return SchedulerInterface
     * @throws Throwable
     */
    public function getScheduler(): SchedulerInterface
    {
        return service($this->scName);
    }

    /**
     * @return bool
     */
    public function getStart(): bool
    {
        return $this->start;
    }

    /**
     * @param Message $msg
     * @throws Throwable
     */
    public function process(Message $msg): void
    {
        try {
            $this->run($msg);
        } catch (Throwable $exception) {
            if (empty($this->errHandler)) {
                throw $exception;
            }
            if (!is_array($this->errHandler)) {
                $this->errHandler = [$this->errHandler];
            }
            //删除锁
            $msg->deleteAllLock();

            $errHandler = $this->errHandler;
            $this->dealException($errHandler, $exception);
        }
    }

    /**
     * @param array $errHandler
     * @param Throwable $exception
     * @throws Throwable
     */
    public function dealException(array &$errHandler, Throwable $exception)
    {
        while (!empty($errHandler)) {
            try {
                $handle = array_shift($errHandler);
                if (is_callable($handle)) {
                    $handle($this, $exception);
                } else {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                throw $exception;
            }
        }
    }

    abstract public function run(Message $msg): void;

    /**
     * @param Message $msg
     * @throws Throwable
     */
    public function sink(Message $msg): void
    {
        $func = $msg->opt[$this->callKey] ?? null;
        if ($func !== null && is_callable($func)) {
            $outputs = $func($msg);
        } else {
            $outputs = $this->output;
        }

        $num = count($outputs);
        $func = $this->func;
        if ($num > 1) {
            wgeach($outputs, function (string $output, bool|int|float $wait) use ($msg, $func): void {
                $func($output, $msg);
            });
        } elseif ($num === 1) {
            $func(current(array_keys($outputs)), $msg);
        }
    }
}
