# Mercure

The `mercure` service, a [Mercure](https://mercure.rocks/) hub, on the domains of the
application (`https://<root_domain>/.well-known/mercure`): the browser subscribes
without CORS, and with the cookies of the application. Its debugger is on
`https://<root_domain>/.well-known/mercure/debug/`.

In the application (e.g. with `symfony/mercure-bundle`):

```
MERCURE_URL="http://mercure/.well-known/mercure"
MERCURE_PUBLIC_URL="https://app.test/.well-known/mercure"
MERCURE_JWT_SECRET="!ChangeThisMercureHubJWTSecretKey!"
```

With the Mercure protocol 1.0 (the default of MercureBundle 0.6+), the tokens need an
`iss` claim: the hub trusts `https://<root_domain>`.

```yaml
# config/packages/mercure.yaml
mercure:
    hubs:
        default:
            url: '%env(MERCURE_URL)%'
            public_url: '%env(MERCURE_PUBLIC_URL)%'
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
                publish: '*'
                claims:
                    # https:// + the root_domain of castor.php
                    iss: 'https://app.test'
                    sub: 'app'
                    client_id: 'app'
```

The hub also accepts the tokens of the protocol 0.x (MercureBundle < 0.6), without
`iss`.

The secret is the one of the documentation of Mercure: in production, the hub has its
own.
