# Amqp插件

## 介绍

* <big>RabbitMQ插件</big>

## 依赖

* `rabbit/amqp`

## 配置

* Amqp组件配置

```php
return [
    'amqp' => 'amqp' => [
        '{}' => BaseManager::class,
        '()' => [
            arrdef([
                'default' => [
                    '{}' => BasePool::class,
                    '()' => [
                        [
                            '{}' => BasePoolProperties::class,
                            'minActive' => 5,
                            'maxActive' => 6,
                            'config' => [
                                'dsn' => config('amqp.dsn'),
                                'queue' => 'msgs',
                                'exchange' => 'router',
                                'queueDeclare' => [
                                    false,
                                    true,
                                    false,
                                    false
                                ],
                                'exchangeDeclare' => [
                                    AMQPExchangeType::DIRECT,
                                    false,
                                    true,
                                    false
                                ]
                            ]
                        ]
                    ],
                    'objClass' => Connection::class
                ]
            ])
        ]
    ]
];
```

* 插件配置

```yaml
---
source_amqp:
  type: Rabbit\Data\Pipeline\Sources\Amqp
  start: true
  consumerTag: consumer
  queue: msgs
  exchange: router
  connParams:
    - 'localhost'
    - 5672
    - admin
    - admin
    - /
  queueDeclare:
    - 'false'
    - 'true'
    - 'false'
    - 'false'
  exchangeDeclare:
    - direct
    - 'false'
    - 'true'
    - 'false'

sink_amqp:
  properties:
    content_type: text/plain
    delivery_mode: 2
```

* __参考文档__: <https://github.com/php-amqplib/php-amqplib>