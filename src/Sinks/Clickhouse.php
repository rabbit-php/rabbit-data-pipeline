<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sinks;

use DI\DependencyException;
use DI\NotFoundException;
use rabbit\core\Context;
use Rabbit\Data\Pipeline\AbstractPlugin;
use rabbit\db\clickhouse\BatchInsert;
use rabbit\db\clickhouse\BatchInsertJsonRows;
use rabbit\db\clickhouse\Connection;
use rabbit\db\clickhouse\MakeCKConnection;
use rabbit\db\ConnectionInterface;
use rabbit\db\Expression;
use rabbit\exception\InvalidConfigException;
use rabbit\helper\ArrayHelper;

/**
 * Class Clickhouse
 * @package Rabbit\Data\Pipeline\Sinks
 */
class Clickhouse extends AbstractPlugin
{
    /** @var Connection */
    protected $db;
    /** @var string */
    protected $tableName;
    /** @var array */
    protected $primaryKey;
    /** @var string */
    protected $flagField;

    /**
     * @return mixed|void
     * @throws DependencyException
     * @throws InvalidConfigException
     * @throws NotFoundException
     */
    public function init()
    {
        parent::init();
        [
            $class,
            $dsn,
            $config,
            $this->tableName,
            $this->flagField,
            $this->primaryKey
        ] = ArrayHelper::getValueByArray(
            $this->config,
            ['class', 'dsn', 'config', 'tableName', 'flagField', 'primaryKey'],
            null,
            [
                'config' => [],
                'flagField' => 'flag'
            ]
        );
        if ($dsn === null || $class === null || $this->primaryKey === null) {
            throw new InvalidConfigException("class, dsn, primaryKey must be set in $this->key");
        }
        $dbName = md5($dsn);
        $driver = MakeCKConnection::addConnection($class, $dbName, $dsn, $config);
        $this->db = getDI($driver)->getConnection($dbName);
    }

    /**
     * @throws \Exception
     */
    public function run()
    {
        if (empty($this->tableName) && isset($this->opt['tableName'])) {
            $this->tableName = $this->opt['tableName'];
        }
        if (isset($this->input['columns'])) {
            $ids = $this->saveWithLine();
        } else {
            $ids = $this->saveWithRows();
        }
        if ($this->primaryKey && $ids) {
            $this->updateFlag($ids);
        }
        $this->output($ids);
    }

    /**
     * @return array
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \rabbit\db\Exception
     */
    protected function saveWithLine(): array
    {
        if ($this->db instanceof Connection) {
            $batch = new BatchInsert($this->tableName, $this->db);
        } else {
            $batch = new \rabbit\db\click\BatchInsert($this->tableName, $this->db);
        }
        $batch->addColumns($this->input['columns']);
        if (!ArrayHelper::isIndexed($this->input['data'])) {
            $this->input['data'] = [$this->input['data']];
        }
        foreach ($this->input['data'] as $item) {
            $batch->addRow($item);
        }
        $result = [];
        if ($batch->execute()) {
            if (is_array($this->primaryKey)) {
                foreach ($this->primaryKey as $key => $type) {
                    $result[$key] = array_unique(ArrayHelper::getColumn($this->input['data'], array_search($key, $this->input['columns']), []));
                }
            } else {
                $result[$this->primaryKey] = array_unique(ArrayHelper::getColumn($this->input['data'], array_search($this->primaryKey, $this->input['columns']), []));
            }
        }
        return $result;
    }

    /**
     * @return array
     */
    protected function saveWithRows(): array
    {
        $batch = new BatchInsertJsonRows($this->tableName, $this->db);
        $batch->addColumns($this->input['columns']);
        if (!ArrayHelper::isIndexed($this->input['data'])) {
            $this->input['data'] = [$this->input['data']];
        }
        foreach ($this->input['data'] as $item) {
            $batch->addRow($item);
        }
        if ($batch->execute()) {
            return ArrayHelper::getColumn($this->input['data'], $this->primaryKey, []);
        }
        return [];
    }

    /**
     * @param array $ids
     * @throws \rabbit\db\Exception
     */
    protected function updateFlag(array $ids): void
    {
        if ($this->db instanceof Connection) {
            $model = new class($this->tableName, $this->db) extends \rabbit\db\clickhouse\ActiveRecord {
                /**
                 *  constructor.
                 * @param string $tableName
                 * @param string $dbName
                 */
                public function __construct(string $tableName, ConnectionInterface $db)
                {
                    Context::set(md5(get_called_class() . 'tableName'), $tableName);
                    Context::set(md5(get_called_class() . 'db'), $db);
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
                    return Context::get(md5(get_called_class() . 'db'));
                }
            };
        } else {
            $model = new class($this->tableName, $this->db) extends \rabbit\db\click\ActiveRecord {
                /**
                 *  constructor.
                 * @param string $tableName
                 * @param string $dbName
                 */
                public function __construct(string $tableName, ConnectionInterface $db)
                {
                    Context::set(md5(get_called_class() . 'tableName'), $tableName);
                    Context::set(md5(get_called_class() . 'db'), $db);
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
                    return Context::get(md5(get_called_class() . 'db'));
                }
            };
        }

        $res = $model::updateAll([$this->flagField => new Expression("{$this->flagField}+1")], array_merge([
            $this->flagField => [0, 1]
        ], $ids));
        if (!empty($res)) {
            throw new Exception($res);
        }
    }
}