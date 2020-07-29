<?php

declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sources;

use ErrorException;
use PhpAmqpLib\Message\AMQPMessage;
use Rabbit\Amqp\Connection;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\Data\Pipeline\AbstractPlugin;
use Rabbit\Data\Pipeline\Message;
use Rabbit\Pool\BaseManager;
use Rabbit\Pool\BasePool;
use Rabbit\Pool\BasePoolProperties;
use Throwable;

/**
 * Class Amqp
 * @package Rabbit\Data\Pipeline\Sources
 */
class Amqp extends AbstractPlugin
{
    /** @var Connection */
    protected Connection $conn;
    /** @var string */
    protected string $consumerTag;

    /**
     * @return mixed|void
     * @throws Throwable
     */
    public function init(): void
    {
        parent::init();
        array_walk($this->output, function (&$value) {
            $value = false;
        });
        [
            $this->consumerTag,
            $queue,
            $exchange,
            $connParams,
            $queueDeclare,
            $exchangeDeclare,
        ] = ArrayHelper::getValueByArray($this->config, [
            'consumerTag',
            'queue',
            'exchange',
            'connParams',
            'queueDeclare',
            'exchangeDeclare'
        ], [
            '',
            '',
            '',
            [],
            [],
            []
        ]);
        $name = uniqid();
        /** @var BaseManager $amqp */
        $amqp = getDI('amqp');
        $amqp->add([
            $name => create([
                'class' => BasePool::class,
                'poolConfig' => create([
                    'class' => BasePoolProperties::class,
                    'config' => [
                        'queue' => $queue,
                        'exchange' => $exchange,
                        'connParams' => $connParams,
                        'queueDeclare' => $queueDeclare,
                        'exchangeDeclare' => $exchangeDeclare
                    ]
                ]),
                'objClass' => Connection::class
            ])
        ]);
        $this->conn = $amqp->get($name)->get();
    }

    /**
     * @param Message $msg
     * @throws ErrorException
     */
    public function run(Message $msg): void
    {
        $this->conn->consume($this->consumerTag, false, false, false, false
            , function (AMQPMessage $message) use ($msg) {
                $tmp = clone $msg;
                $tmp->data = $message->body;
                $this->sink($tmp);
            });
    }
}