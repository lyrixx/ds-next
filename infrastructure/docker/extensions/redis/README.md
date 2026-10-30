# Redis

The `redis` service, its dashboard Redis Insight on `https://redis.<root_domain>`, and
the `redis` PHP extension in the images.

In the application:

```
REDIS_URL="redis://redis:6379"
```

To add the database in Redis Insight, use the host `redis` and the port `6379`.
