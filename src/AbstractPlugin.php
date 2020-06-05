<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline;

use common\Exception\IgnoreException;
use Exception;
use Psr\SimpleCache\CacheInterface;
use rabbit\App;
use rabbit\contract\InitInterface;
use rabbit\core\BaseObject;
use rabbit\exception\InvalidArgumentException;
use rabbit\exception\InvalidCallException;
use rabbit\helper\ArrayHelper;
use rabbit\helper\ExceptionHelper;
use rabbit\helper\VarDumper;
use rabbit\redis\Redis;

/**
 * Interface AbstractPlugin
 * @package Rabbit\Data\Pipeline
 */
abstract class AbstractPlugin extends BaseObject implements InitInterface
{
    const LOG_SIMPLE = 0;
    const LOG_INFO = 1;
    /** @var string */
    public $taskName;
    /** @var string */
    private $taskId;
    /** @var string */
    public $key;
    /** @var array */
    protected $config = [];
    /** @var mixed */
    private $input;
    /** @var array */
    private $request = [];
    /** @var array */
    private $opt = [];
    /** @var array */
    public $locks = [];
    /** @var array */
    protected $output = [];
    /** @var bool */
    protected $start = false;
    /** @var int */
    protected $lockEx = 0;
    /** @var CacheInterface */
    protected $cache;
    /** @var string */
    const CACHE_KEY = 'cache';
    /** @var string */
    const LOCK_KEY = 'Plugin';
    /** @var SchedulerInterface */
    protected $scheduler;
    /** @var int */
    protected $logInfo = self::LOG_SIMPLE;
    /** @var callable */
    protected $errHandler;
    /** @var bool */
    protected $wait = false;
    /** @var string */
    protected $pluginName;
    /** @var array */
    protected $lockKey = [];
    /** @var AbstractPlugin[] */
    protected $inPlugin = [];

    /**
     * AbstractPlugin constructor.
     * @param array $config
     * @throws Exception
     */
    public function __construct(SchedulerInterface $scheduler, array $config)
    {
        $this->config = $config;
        $this->scheduler = $scheduler;
    }

    /**
     * @param $name
     */
    public function &__get($name)
    {
        if (isset($this->$name)) {
            return $this->$name;
        }
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            $value = $this->$getter();
            return $value;
        }
        return null;
    }

    public function init()
    {
        $this->cache = getDI(self::CACHE_KEY);
        $this->errHandler = ArrayHelper::getValue($this->config, 'errHandler');
        $this->lockKey = ArrayHelper::getValue($this->config, 'lockKey', []);
    }

    /**
     * @return string
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * @param string $taskId
     */
    public function setTaskId(string $taskId): void
    {
        $this->taskId = $taskId;
    }

    /**
     * @return array
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * @param array $opt
     */
    public function setRequest(array &$request): void
    {
        $this->request = $request;
    }

    public function getInput()
    {
        return $this->input;
    }

    /**
     * @param $input
     */
    public function setInput(&$input)
    {
        $this->input = $input;
    }

    /**
     * @return array
     */
    public function getOpt(): array
    {
        return $this->opt;
    }

    /**
     * @param array $opt
     */
    public function setOpt(array &$opt): void
    {
        $this->opt = $opt;
    }

    /**
     * @return bool
     */
    public function getStart(): bool
    {
        return $this->start;
    }

    /**
     * @return int
     */
    public function getLock(string $key = null, $ext = null): bool
    {
        empty($ext) && $ext = $this->lockEx;
        if (($key || $key = $this->taskId) && $this->scheduler->getLock($key, $ext)) {
            $this->opt['Locks'][] = $key;
            return true;
        }
        return false;
    }

    /**
     * @param $key
     * @return string
     */
    public function makeLockKey($key): string
    {
        is_array($key) && $key = implode('_', $key);
        if (!is_string($key)) {
            throw new Exception("lockKey Must be string or array");
        }
        return 'Locks:' . $key;
    }

    public function deleteAllLock(): void
    {
        $this->scheduler->deleteAllLock($this->opt, $this->taskName);
    }

    /**
     * @param string $lockKey
     * @return bool
     */
    public function deleteLock(string $key = null): int
    {
        ($key === null) && $key = $this->taskId;
        return $this->scheduler->deleteLock($key, $this->taskName);
    }

    /**
     * @param \Closure $function
     * @param array $params
     * @throws Exception
     */
    public function redisLock(string $key, \Closure $function, array $params)
    {
        try {
            if ($this->scheduler->redis->setnx($key, true)) {
                return call_user_func_array($function, $params);
            }
            return null;
        } catch (\Throwable $exception) {
            App::error(ExceptionHelper::dumpExceptionToString($exception));
        } finally {
            $this->scheduler->redis->del($key);
        }
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function getFromInput(string $key)
    {
        return ArrayHelper::getValue($this->input, $key);
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function getFromOpt(string $key)
    {
        return ArrayHelper::getValue($this->opt, $key);
    }

    /**
     * @param array $data
     * @param array $input
     * @param array $opt
     * @param string $key
     * @param $item
     */
    public function makeOptions(array &$data, array &$input, array &$opt, string $key, $item): void
    {
        if (is_array($item)) {
            [$method, $params] = ArrayHelper::getValueByArray($item, ['method', 'params'], null, ['params' => []]);
            if (empty($method)) {
                throw new InvalidArgumentException("method must be set!");
            }
            if (!is_callable($method)) {
                throw new InvalidCallException("$method does not exists");
            }
            call_user_func_array($method, [$key, $params, &$input, &$opt, &$data]);
        }
        if (is_string($item)) {
            if (strtolower($item) === 'input') {
                $data[$key] = $input;
            } elseif (strtolower($item) === 'opt') {
                $data[$key] = $opt;
            } else {
                $pos = strpos($item, '.') ? strpos($item, '.') : strlen($item);
                $from = strtolower(substr($item, 0, $pos));
                switch ($from) {
                    case 'input':
                        $data[$key] = ArrayHelper::getValue($input, substr($item, $pos + 1));
                        break;
                    case 'opt':
                        $data[$key] = ArrayHelper::getValue($opt, substr($item, $pos + 1));
                        break;
                    default:
                        $data[$key] = $item;
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public function process(): void
    {
        try {
            $this->run();
        } catch (\Throwable $exception) {
            if (empty($this->errHandler)) {
                throw $exception;
            }
            if (!is_array($this->errHandler)) {
                $this->errHandler = [$this->errHandler];
            }
            //删除锁
            $this->deleteAllLock();

            $errerrHandler = $this->errHandler;
            self::dealException($errerrHandler, $exception);
        }
    }

    public function dealException(&$errerrHandler, $exception)
    {
        while (!empty($errerrHandler)) {
            try {
                $handle = array_shift($errerrHandler);
                if (is_callable($handle)) {
                    call_user_func($handle, $this, $exception);
                } else {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                throw $exception;
            }
        }
    }

    abstract public function run();

    /**
     * @param $data
     * @throws Exception
     */
    public function output(&$data): void
    {
        foreach ($this->output as $output => $transfer) {
            if ($transfer === 'wait') {
                if (!isset($this->inPlugin[$output])) {
                    $plugin = $this->scheduler->getTarget($this->taskName, $output);
                    $this->inPlugin[$output] = $plugin;
                } else {
                    $plugin = $this->inPlugin[$output];
                }
                $plugin->taskId = $this->taskId;
                $plugin->input =& $data;
                $plugin->opt = &$this->opt;
                $plugin->request =& $this->request;
                $plugin->process();
                return;
            }
            if (empty($data)) {
                App::warning("「{$this->taskName}」 $this->key -> $output; data is empty", 'Data');
            } elseif ($this->logInfo === self::LOG_SIMPLE) {
                App::info("「{$this->taskName}」 $this->key -> $output;", 'Data');
            } else {
                App::info("「{$this->taskName}」 $this->key -> $output; data: " . VarDumper::getDumper()->dumpAsString($data), 'Data');
            }
            $this->scheduler->send($this->taskName, $output, $this->taskId, $data, (bool)$transfer, $this->opt, $this->request, $this->wait);
        }
    }
}
