# Chrome

The `chrome` service, a headless Chrome ([Browserless](https://docs.browserless.io/))
for the end-to-end tests or the screenshots of the application, in dev only.

In the application, its DevTools endpoint:

```
CHROME_URL="ws://chrome:3000"
```

Chrome reaches the application on its root domain, through the router (e.g.
`https://app.test`), with the self-signed certificate of the dev stack: ignore the
certificate errors in the tests.
