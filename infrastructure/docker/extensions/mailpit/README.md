# Mailpit

The `mailpit` service, which catches the emails sent by the application in dev, and
shows them on `https://mailpit.<root_domain>`.

In the application:

```
MAILER_DSN="smtp://mailpit:1025"
```

Mailpit only exists in dev: there is no mail server in the production stack.
