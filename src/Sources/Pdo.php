<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sources;

use DI\DependencyException;
use DI\NotFoundException;
use Rabbit\Base\Exception\InvalidConfigException;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\Data\Pipeline\AbstractPlugin;
use Rabbit\Data\Pipeline\Message;
use Rabbit\DB\DBHelper;
use Rabbit\DB\MakePdoConnection;
use Rabbit\DB\Query;
use ReflectionException;
use Throwable;
use function Swoole\Coroutine\batch;

/**
 * Class Pdo
 * @package Rabbit\Data\Pipeline\Sources
 */
class Pdo extends AbstractPlugin
{
    protected $sql;
    protected string $dbName;
    protected int $duration;
    protected string $query;
    protected ?int $each = null;
    protected array $params = [];
    protected string $cacheDriver = 'memory';

    /**
     * @param string $class
     * @param string $dsn
     * @param array $pool
     * @param array $retryHandler
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Throwable
     */
    protected function createConnection(string $class, string $dsn, array $pool, array $retryHandler): void
    {
        [
            $poolConfig['min'],
            $poolConfig['max'],
            $poolConfig['wait'],
            $poolConfig['retry']
        ] = ArrayHelper::getValueByArray(
            $pool,
            ['min', 'max', 'wait', 'retry'],
            [10, 12, 0, 3]
        );
        MakePdoConnection::addConnection($class, $this->dbName, $dsn, $poolConfig, $retryHandler);
    }

    /**
     * @return mixed|void
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Throwable
     */
    public function init(): void
    {
        parent::init();
        [
            $class,
            $dsn,
            $pool,
            $retryHandler,
            $this->cacheDriver,
            $this->sql,
            $this->duration,
            $this->query,
            $this->each,
            $this->params
        ] = ArrayHelper::getValueByArray(
            $this->config,
            ['class', 'dsn', 'pool', 'retryHandler', self::CACHE_KEY, 'sql', 'duration', 'query', 'each', 'params'],
            [
                self::CACHE_KEY => 'memory',
                'query' => 'queryAll',
                'duration' => -1,
                'pool' => [],
                'retryHandler' => [],
                'each' => false,
                'params' => []
            ]
        );
        if ($dsn === null || $class === null || $this->sql === null) {
            throw new InvalidConfigException("class, dsn and sql must be set in $this->key");
        }
        if (empty($this->dbName)) {
            $this->dbName = md5($dsn);
            $this->createConnection($class, $dsn, $pool, $retryHandler);
        }
    }

    /**
     * @param Message $msg
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Throwable
     * @throws ReflectionException
     */
    public function run(Message $msg): void
    {
        if (is_array($this->sql)) {
            $batchNum = ArrayHelper::remove($this->sql, 'batch');
            $this->sql = ArrayHelper::merge([self::CACHE_KEY => $this->duration], $this->sql);
            if ($batchNum) {
                foreach (DBHelper::Search(new Query(getDI('db')->get($this->dbName)), $this->sql)->batch($batchNum) as $list) {
                    $batchCo = [];
                    foreach ($list as $msg->data) {
                        $tmp = clone $msg;
                        $batchCo[] = fn() => $this->send($tmp);
                    }
                    batch($batchCo);
                }
            } else {
                $msg->data = DBHelper::PubSearch(new Query(getDI('db')->get($this->dbName)), $this->sql, $this->query);
                $this->send($msg);
            }
        } else {
            $params = $this->makeParams($msg);
            $msg->data = getDI('db')->get($this->dbName)->createCommand($this->sql, $params)->cache($this->duration, $this->cache->getDriver($this->cacheDriver))->{$this->query}();
            $this->send($msg);
        }
    }

    /**
     * @param Message $msg
     * @return array
     */
    protected function makeParams(Message $msg): array
    {
        $params = [];
        foreach ($this->params as $key => $value) {
            switch ($value) {
                case 'getFromInput':
                    $params[] = ArrayHelper::getValue($msg->data, $key);
                    break;
                case 'input':
                    $params[] = json_encode($msg->data, JSON_UNESCAPED_UNICODE);
                    break;
                default:
                    if (method_exists($this, $value)) {
                        $params[] = $this->$value();
                    } else {
                        $params[] = $value;
                    }
            }
        }
        return $params;
    }

    /**
     * @param Message $msg
     * @throws Throwable
     */
    protected function send(Message $msg): void
    {
        if (ArrayHelper::isIndexed($msg->data) && $this->each) {
            foreach ($msg->data as $item) {
                $tmp = clone $msg;
                $tmp->data = $item;
                $this->sink($tmp);
            }
        } else {
            $this->sink($msg);
        }
    }
}
