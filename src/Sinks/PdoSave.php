<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sinks;

use DI\DependencyException;
use DI\NotFoundException;
use rabbit\activerecord\ActiveRecord;
use rabbit\core\Context;
use Rabbit\Data\Pipeline\AbstractPlugin;
use rabbit\db\Connection;
use rabbit\db\ConnectionInterface;
use rabbit\db\Exception;
use rabbit\db\MakePdoConnection;
use rabbit\db\mysql\CreateExt;
use rabbit\exception\InvalidConfigException;
use rabbit\helper\ArrayHelper;

/**
 * Class Pdo
 * @package Rabbit\Data\Pipeline\Sinks
 */
class PdoSave extends AbstractPlugin
{
    /** @var string */
    protected $tableName;
    /** @var string */
    protected $dbName;
    /** @var string */
    protected $driver = 'db';

    /**
     * @param string $class
     * @param string $dsn
     * @param array $pool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
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
            null,
            [10, 12, 0, 3]
        );
        MakePdoConnection::addConnection($class, $this->dbName, $dsn, $poolConfig);
    }

    /**
     * @return mixed|void
     * @throws Exception
     */
    public function init()
    {
        parent::init();
        [
            $mysql,
            $class,
            $dsn,
            $pool,
            $this->tableName
        ] = ArrayHelper::getValueByArray(
            $this->config,
            ['mysql', 'class', 'dsn', 'pool', 'tableName'],
            null,
            [
                'pool' => [],
            ]
        );
        if ($this->taskName === null) {
            throw new InvalidConfigException("taskName must be set in $this->key");
        }
        if ($mysql === null && ($dsn === null || $class === null)) {
            throw new InvalidConfigException("$this->key must need prams: mysql or class, dsn");
        }
        if (!empty($mysql)) {
            [$this->driver, $this->dbName] = explode('.', trim($mysql));
        } else {
            $this->dbName = md5($dsn);
            $this->createConnection($class, $dsn, $pool, $retryHandler);
        }
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        if (empty($this->tableName) && isset($this->opt['tableName'])) {
            $this->tableName = $this->opt['tableName'];
        }
        if (isset($this->input['columns'])) {
            $this->saveWithLine();
        } else {
            $this->saveWithModel();
        }
    }

    /**
     * @throws Exception
     */
    protected function saveWithLine(): void
    {
        /** @var Connection $db */
        $db = getDI($this->driver)->get($this->dbName);
        $res = $db->createCommand()->batchInsert($this->tableName, $this->input['columns'], $this->input['data'])->execute();
        $this->output($res);
    }

    /**
     * @throws Exception
     */
    protected function saveWithModel(): void
    {
        $model = new class($this->tableName, $this->dbName) extends ActiveRecord {
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
            public static function tableName()
            {
                return Context::get(md5(get_called_class() . 'tableName'));
            }

            /**
             * @return ConnectionInterface
             */
            public static function getDb(): ConnectionInterface
            {
                return getDI('db')->get(Context::get(md5(get_called_class() . 'dbName')));
            }
        };

        $res = CreateExt::create($model, $this->input);
        if (empty($res)) {
            throw new Exception("save to " . $model::tableName() . ' failed!');
        }
        $this->output($res);
    }
}
