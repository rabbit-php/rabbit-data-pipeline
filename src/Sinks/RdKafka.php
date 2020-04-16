<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline\Sinks;

use Rabbit\Data\Pipeline\AbstractSingletonPlugin;
use rabbit\helper\ArrayHelper;
use Rabbit\Rdkafka\KafkaManager;
use RdKafka\Producer;
use RdKafka\Topic;

/**
 * Class RdKafka
 * @package Rabbit\Data\Pipeline\Sinks
 */
class RdKafka extends AbstractSingletonPlugin
{
    /** @var Topic */
    protected $topic;
    /** @var Producer */
    protected $producer;

    /**
     * @return mixed|void
     * @throws Exception
     */
    public function init()
    {
        parent::init();
        [
            $dsn,
            $topic,
            $options,
            $topicSet,
        ] = ArrayHelper::getValueByArray($this->config, [
            'dsn',
            'topic',
            'options',
            'topicSet'
        ], null, [
            'options' => [],
            'topicSet' => []
        ]);
        if (empty($dsn) || empty($topic)) {
            throw new InvalidConfigException("dsn & topic must be set!");
        }
        $name = md5($dsn);
        /** @var KafkaManager $manager */
        $manager = getDI('kafka');
        $manager->add([
            $name => [
                'type' => 'producer',
                'dsn' => $dsn,
                'set' => $options
            ]
        ]);
        $manager->init();
        $this->producer = $manager->getProducer($name);
        $this->topic = $manager->getProducerTopic($name, $topic, $topicSet);
    }

    public function run()
    {
        $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $this->getInput());
        $this->producer->poll(0);
        $this->producer->flush(1000);
    }
}