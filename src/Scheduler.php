<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline;

use Co\System;
use common\Exception\InvalidArgumentException;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use rabbit\App;
use rabbit\contract\InitInterface;
use rabbit\core\ObjectFactory;
use rabbit\exception\InvalidConfigException;
use rabbit\helper\ArrayHelper;
use rabbit\helper\ExceptionHelper;
use rabbit\memory\atomic\LockInterface;
use rabbit\memory\atomic\LockManager;
use rabbit\redis\Redis;
use rabbit\server\Server;

/**
 * Class Scheduler
 * @package Rabbit\Data\Pipeline
 */
class Scheduler implements SchedulerInterface, InitInterface
{
    /** @var array */
    protected $targets = [];
    /** @var ConfigParserInterface */
    protected $parser;
    /** @var Redis */
    public $redis;
    /** @var string */
    protected $name = 'scheduler';
    /** @var int */
    protected $waitTimes = 3;
    /** @var LockInterface */
    public $atomicLock;

    /**
     * Scheduler constructor.
     * @param ConfigParserInterface $parser
     */
    public function __construct(ConfigParserInterface $parser, LockManager $manager, string $lockName)
    {
        $this->parser = $parser;
        $this->atomicLock = $manager->getLock($lockName);
    }

    /**
     * @return mixed|void
     * @throws DependencyException
     * @throws InvalidConfigException
     * @throws NotFoundException
     */
    public function init()
    {
        $this->redis = getDI('redis');
        $this->targets = $this->build($this->parser->parse());
    }

    /**
     * @param string $taskName
     * @param string $name
     * @return AbstractPlugin|null
     * @throws Exception
     */
    public function getTarget(string $taskName, string $name): AbstractPlugin
    {
        $waitTime = 0;
        while ((empty($this->targets) || !isset($this->targets[$taskName]) || !isset($this->targets[$taskName][$name]) || !$this->targets[$taskName][$name] instanceof AbstractPlugin) && (++$waitTime <= $this->waitTimes)) {
            App::warning("The $taskName is building wait {$this->waitTimes}s");
            System::sleep($waitTime * 3);
        }
        if ($this->targets[$taskName][$name] instanceof AbstractSingletonPlugin) {
            return $this->targets[$taskName][$name];
        }
        return clone $this->targets[$taskName][$name];
    }

    /**
     * @param string|null $key
     * @param array $params
     * @throws InvalidArgumentException
     */
    public function run(string $key = null, array $params = []): void
    {
        $this->waitTasksBuild();
        $server = App::getServer();
        if ($key === null) {
            foreach (array_keys($this->targets) as $key) {
                rgo(function () use ($key, $params) {
                    $this->process((string)$key, $params);
                });
            }
        } elseif (isset($this->targets[$key])) {
            rgo(function () use ($key, $params) {
                $this->process((string)$key, $params);
            });
        } else {
            throw new InvalidArgumentException("No such target $key");
        }
    }

    /**
     * @param array $configs
     * @throws DependencyException
     * @throws InvalidConfigException
     * @throws NotFoundException
     */
    public function build(array $configs): array
    {
        $targets = [];
        foreach ($configs as $name => $config) {
            foreach ($config as $key => $params) {
                $class = ArrayHelper::remove($params, 'type');
                if (!$class) {
                    throw new InvalidConfigException("The type must be set in $key");
                }
                $output = ArrayHelper::remove($params, 'output', []);
                $start = ArrayHelper::remove($params, 'start', false);
                $wait = ArrayHelper::remove($params, 'wait', false);
                if (is_string($output)) {
                    $output = [$output => true];
                }
                $lockEx = ArrayHelper::remove($params, 'lockEx', 30);
                $pluginName = ArrayHelper::remove($params, 'name', uniqid());
                $targets[$name][$key] = ObjectFactory::createObject(
                    $class,
                    [
                        'scheduler' => $this,
                        'config' => $params,
                        'key' => $key,
                        'output' => $output,
                        'start' => $start,
                        'taskName' => $name,
                        'pluginName' => $pluginName,
                        'lockEx' => $lockEx,
                        'wait' => $wait,
                    ],
                    false
                );
            }
        }
        return $targets;
    }

    /**
     * @param string $task
     * @param array|null $params
     */
    public function process(string $task, array $params = []): void
    {
        $this->waitTasksBuild();
        /** @var AbstractPlugin $target */
        foreach ($this->targets[$task] as $tmp) {
            if ($tmp->getStart()) {
                if ($tmp instanceof AbstractSingletonPlugin) {
                    $target = $tmp;
                } else {
                    $target = clone $tmp;
                }
                $target->setTaskId((string)getDI('idGen')->create());
                $target->setRequest($params);
                $target->process();
            }
        }
    }

    /**
     * @param string $taskName
     * @param string $key
     * @param string|null $task_id
     * @param $data
     * @param int|null $transfer
     * @param array $opt
     * @throws Exception
     */
    public function send(string $taskName, string $key, ?string $task_id, &$data, ?int $transfer, array $opt = [], array $request = [], bool $wait = false): void
    {
        try {
            if ($transfer === null) {
                App::info("Data do not transfrom", 'Data');
                /** @var AbstractPlugin $target */
                $target = $this->getTarget($taskName, $key);
                $target->setTaskId($task_id);
                $target->setInput($data);
                $target->setOpt($opt);
                $target->setRequest($request);
                $target->process();
            } else {
                $this->transSend($taskName, $key, $task_id, $data, $transfer, $opt, $request, $wait);
            }
            if (end($this->targets[$taskName])->key === $key) {
                App::info("「{$taskName}」 finished!");
            }
        } catch (\Throwable $exception) {
            App::error("「{$taskName}」「{$key}」" . ExceptionHelper::dumpExceptionToString($exception));
            $this->deleteAllLock($opt, $taskName);
        }
    }

    /**
     * @param string $taskName
     * @param string $key
     * @param string|null $task_id
     * @param $data
     * @param int|null $transfer
     * @param array $opt
     * @throws Exception
     */
    protected function transSend(string $taskName, string $key, ?string $task_id, &$data, ?int $transfer, array &$opt = [], array &$request = [], bool $wait = false): void
    {
        $server = App::getServer();
        $params = ["{$this->name}->send", [$taskName, $key, $task_id, $data, null, $opt, $request]];
        if ($server === null || $server instanceof CoServer) {
            if ($server === null) {
                $socket = getDI('socketHandle');
            } else {
                $socket = $server->getProcessSocket();
            }
            $ids = $socket->getWorkerIds();
            if ($transfer > -1) {
                $workerId = $transfer % count($ids);
            } else {
                unset($ids[$socket->workerId]);
                $workerId = empty($ids) ? null : array_rand($ids);
            }
            if ($workerId !== null) {
                App::info("Data from worker $socket->workerId to $workerId", 'Data');
                $socket->send($params, $workerId, $wait);
            } else {
                $target = $this->getTarget($taskName, $key);
                $target->setTaskId($task_id);
                $target->setInput($data);
                $target->setOpt($opt);
                $target->setRequest($request);
                $target->process();
            }
        } elseif ($server instanceof Server) {
            $swooleServer = $server->getSwooleServer();
            if ($transfer > -1) {
                $workerId = $transfer % $swooleServer->setting['worker_num'];
            } else {
                $ids = range(0, $swooleServer->setting['worker_num'] - 1);
                unset($ids[$swooleServer->worker_id]);
                $workerId = empty($ids) ? null : array_rand($ids);
            }
            if (!$wait && $workerId !== null) {
                App::info("Data from worker $swooleServer->worker_id to $workerId", 'Data');
                $server->pipeHandler->sendMessage($params, $workerId);
            } else {
                $target = $this->getTarget($taskName, $key);
                $target->setTaskId($task_id);
                $target->setInput($data);
                $target->setOpt($opt);
                $target->setRequest($request);
                $target->process();
            }
        } else {
            throw new NotSupportedException("Do not support Swoole\Server");
        }
    }

    /**
     * @return int
     */
    public function getLock(string $key = null, $lockEx = 60): bool
    {
        return (bool)$this->redis->set($key, true, ['NX', 'EX' => $lockEx]);
    }

    /**
     * @param array $opt
     */
    public function deleteAllLock(array $opt = [], string $taskName = ''): void
    {
        $locks = isset($opt['Locks']) ? $opt['Locks'] : [];
        foreach ($locks as $lock) {
            !is_string($lock) && $lock = strval($lock);
            $this->deleteLock($lock, $taskName);
        }
    }

    /**
     * @param string|null $key
     * @param string $taskName
     * @return int
     * @throws Exception
     */
    public function deleteLock(string $key = null, string $taskName = ''): int
    {
        if ($flag = $this->redis->del($key)) {
            App::warning("「{$taskName}」 Delete Lock: " . $key);
        }
        return (int)$flag;

    }

    /**
     * @throws Exception
     */
    private function waitTasksBuild(): void
    {
        $waitTime = 0;
        while (empty($this->targets) && (++$waitTime <= $this->waitTimes)) {
            App::warning("The targets is building wait {$this->waitTimes}s");
            System::sleep($waitTime * 3);
        }
    }
}
