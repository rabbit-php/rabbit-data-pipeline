<?php

declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Common;

use Rabbit\Base\Exception\InvalidConfigException;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\Data\Pipeline\AbstractPlugin;

abstract class BaseSyncData extends AbstractPlugin
{
    protected ?string $equal;
    protected ?string $from;
    protected ?string $to;
    protected ?string $field;
    protected string $db = 'default';
    protected ?string $primary;
    protected bool $onlyInsert = false;
    protected ?int $batch;
    protected ?string $sql = null;

    public function init(): void
    {
        [
            $this->from,
            $this->to,
            $this->db,
            $this->field,
            $this->equal,
            $this->primary,
            $this->onlyInsert,
            $this->batch,
            $this->sql,
        ] = ArrayHelper::getValueByArray($this->config, ['from', 'to', 'db', 'field', 'equal', 'primary', 'onlyInsert', 'batch', 'sql'], ['db' => $this->db, 'onlyInsert' => $this->onlyInsert]);
        if (($this->from === null || $this->to === null || $this->field === null) && $this->sql === null) {
            throw new InvalidConfigException('(from or to or field) & sql is empty!');
        }
    }
}
