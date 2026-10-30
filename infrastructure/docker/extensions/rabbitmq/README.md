# RabbitMQ

The `rabbitmq` service (user: `app`, password: `app`), its dashboard on
`https://rabbitmq.<root_domain>`, and the `amqp` PHP extension in the images.

In the application, e.g. with Symfony Messenger:

```
MESSENGER_TRANSPORT_DSN="amqp://app:app@rabbitmq:5672/%2f/messages"
```

The version of RabbitMQ is in `docker-compose.version.yml`, which belongs to the
project: `castor starter:sync` never changes it.
