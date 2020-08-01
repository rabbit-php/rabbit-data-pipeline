<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sinks;

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
 * @package Rabbit\Data\Pipeline\Sinks
 */
class Amqp extends AbstractPlugin
{
    protected string $name;
    protected array $properties = [
        'content_type' => 'text/plain',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
    ];

    /**
     * @return mixed|void
     * @throws Throwable
     */
    public function init(): void
    {
        parent::init();
        [
            $queue,
            $exchange,
            $connParams,
            $queueDeclare,
            $exchangeDeclare,
            $this->properties
        ] = ArrayHelper::getValueByArray($this->config, [
            'queue',
            'exchange',
            'connParams',
            'queueDeclare',
            'exchangeDeclare',
            'properties'
        ], [
            '',
            '',
            '',
            [],
            [],
            [],
            $this->properties
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
    }

    /**
     * @param Message $msg
     * @throws Throwable
     */
    public function run(Message $msg):void
    {
        /** @var BaseManager $amqp */
        $amqp = getDI('amqp');
        $conn = $amqp->get($this->name);
        $conn->basic_publish(new AMQPMessage($msg->data, $this->properties));
    }
}