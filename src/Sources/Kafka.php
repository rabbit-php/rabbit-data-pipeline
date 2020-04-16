<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sources;

use rabbit\core\ObjectFactory;
use Rabbit\Data\Pipeline\AbstractSingletonPlugin;
use rabbit\helper\ArrayHelper;
use rabbit\kafka\Broker;
use rabbit\kafka\Consumer\Consumer;
use rabbit\kafka\Consumer\ConsumerConfig;
use rabbit\kafka\Consumer\Process;
use rabbit\kafka\ConsumerModel;

/**
 * Class Kafak
 * @package Rabbit\Data\Pipeline\Sources
 */
class Kafka extends AbstractSingletonPlugin
{
    /** @var ConsumerModel */
    protected $consumer;

    /**
     * @return mixed|void
     * @throws Exception
     */
    public function init()
    {
        parent::init();
        array_walk($this->output, function (&$value) {
            $value = false;
        });
        [
            $dsn,
            $topic,
            $options
        ] = ArrayHelper::getValueByArray($this->config, [
            'dsn',
            'topic',
            'options'
        ]);
        if (empty($dsn) || empty($topic)) {
            throw new InvalidConfigException("dsn & topic must be set!");
        }
        $params = [
            'setMetadataBrokerList()' => [$dsn]
        ];
        foreach ($options as $param => $value) {
            $params['set' . lcfirst($param) . '()'] = [$value];
        }
        $this->consumer = new ConsumerModel([
            'consumer' => ObjectFactory::createObject([
                'class' => Consumer::class,
                'process' => ObjectFactory::createObject([
                    'class' => Process::class,
                    'broker' => ObjectFactory::createObject([
                        'class' => Broker::class,
                        'config' => ObjectFactory::createObject([
                            'class' => ConsumerConfig::class
                        ], $params, false)
                    ], [], false)
                ], [], false)
            ], [], false),
            'topic' => $topic
        ]);
    }

    public function run()
    {
        $this->consumer->consume(function (int $part, array $message) {
            $this->output($message['message']['value']);
        });
    }
}