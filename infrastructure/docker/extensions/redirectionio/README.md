# redirection.io

The `redirectionio` service, the [redirection.io](https://redirection.io/) agent as a
reverse proxy in front of the application, in dev only: the router sends it the
requests of the application, and it applies the rules of the project (redirections,
headers...) before it forwards them (see "Reverse proxies" in the README of the demo
extension). The traffic of the dev stack is not logged in the manager.

Give it the key of the project (see the manager of redirection.io), in
`docker-compose.project.yml`:

```yaml
services:
    redirectionio:
        environment:
            - "REDIRECTIONIO_PROJECT_KEY=AAAAAAAAAAAAAAAA:BBBBBBBBBBBBBBBB"
```

or in the `REDIRECTIONIO_PROJECT_KEY` environment variable, when Castor runs. Without
a key, the agent forwards everything, and logs an error.
