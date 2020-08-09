<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sinks;

use DI\DependencyException;
use DI\NotFoundException;
use Rabbit\ActiveRecord\ActiveRecord;
use Rabbit\ActiveRecord\ARHelper;
use Rabbit\ActiveRecord\BaseActiveRecord;
use Rabbit\Base\App;
use Rabbit\Base\Core\Context;
use Rabbit\Base\Exception\InvalidConfigException;
use Rabbit\Base\Exception\NotSupportedException;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\Data\Pipeline\AbstractPlugin;
use Rabbit\Data\Pipeline\Message;
use Rabbit\DB\ConnectionInterface;
use Rabbit\DB\Exception;
use Rabbit\DB\MakePdoConnection;
use Throwable;

/**
 * Class Pdo
 * @package Rabbit\Data\Pipeline\Sinks
 */
class Pdo extends AbstractPlugin
{
    protected ?string $tableName;
    protected string $dbName;
    protected string $driver = 'db';

    /**
     * @param string $class
     * @param string $dsn
     * @param array $pool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Throwable
     */
    private function createConnection(string $class, string $dsn, array $pool): void
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
        MakePdoConnection::addConnection($class, $this->dbName, $dsn, $poolConfig);
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
            $this->dbName,
            $dsn,
            $pool,
            $this->tableName
        ] = ArrayHelper::getValueByArray(
            $this->config,
            ['dbName', 'class', 'dsn', 'pool', 'tableName'],
            [
                'pool' => []
            ]
        );
        if ($this->tableName === null) {
            throw new InvalidConfigException("taskName must be set in $this->key");
        }
        if ($dsn === null || $class === null) {
            throw new InvalidConfigException("$this->key must need prams: class, dsn");
        }
        if (!$this->dbName) {
            $this->dbName = md5($dsn);
            $this->createConnection($class, $dsn, $pool);
        }
    }

    /**
     * @param Message $msg
     * @throws Throwable
     */
    public function run(Message $msg): void
    {
        if (empty($this->tableName) && isset($msg->opt['tableName'])) {
            $this->tableName = $msg->opt['tableName'];
        }
        [
            $condition,
            $updates
        ] = ArrayHelper::getValueByArray($msg->data, ['where', 'updates']);
        if (isset($msg->data['columns'])) {
            $this->saveWithLine($msg);
        } elseif ($condition && $updates) {
            $this->saveWithCondition($condition, $updates);
        } else {
            $this->saveWithModel($msg);
        }
    }

    /**
     * @param Message $msg
     * @throws Throwable
     */
    protected function saveWithLine(Message $msg): void
    {
        $db = getDI($this->driver)->get($this->dbName);
        $msg->data = $db->createCommand()->batchInsert($this->tableName, $msg->data['columns'], $msg->data['data'])->execute();
        $this->sink($msg);
    }

    /**
     * @param Message $msg
     * @param array $updates
     * @param array $condition
     * @throws NotSupportedException
     * @throws Throwable
     */
    protected function saveWithCondition(Message $msg, array $updates, array $condition): void
    {
        $model = $this->getModel();
        $msg->data = $model::updateAll($updates, $condition);
        if (!$msg->data) {
            App::warning("$this->tableName update failed");
        }
        $this->sink($msg);
    }

    /**
     * @param Message $msg
     * @throws Exception
     * @throws Throwable
     * @throws NotSupportedException
     */
    protected function saveWithModel(Message $msg): void
    {
        $model = $this->getModel();
        $msg->data = ARHelper::create($model, $msg->data);
        if (empty($msg->data)) {
            throw new Exception("save to " . $model::tableName() . ' failed!');
        }
        $this->sink($msg);
    }

    /**
     * @return BaseActiveRecord
     */
    protected function getModel(): BaseActiveRecord
    {
        return new class($this->tableName, $this->dbName) extends ActiveRecord {
            /**
             *  constructor.
             * @param string $tableName
             * @param string $dbName
             */
            public function __construct(string $tableName, string $dbName)
            {
                Context::set(md5(get_called_class() . 'tableName'), $tableName);
                Context::set(md5(get_called_class() . 'dbName'), $dbName);
            }

            /**
             * @return mixed|string
             */
            public static function tableName(): string
            {
                return Context::get(md5(get_called_class() . 'tableName'));
            }

            /**
             * @return ConnectionInterface
             * @throws Throwable
             */
            public static function getDb(): ConnectionInterface
            {
                return getDI('db')->get(Context::get(md5(get_called_class() . 'dbName')));
            }
        };
    }
}