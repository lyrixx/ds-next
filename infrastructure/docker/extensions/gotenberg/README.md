# Gotenberg

The `gotenberg` service, which converts HTML, URLs and office documents to PDF, with
Chromium and LibreOffice (e.g. instead of wkhtmltopdf, which Debian 13 no longer
packages). It is in the production stack too.

In the application (e.g. with `sensiolabs/gotenberg-bundle`):

```
GOTENBERG_DSN="http://gotenberg:3000"
```

If Chromium only renders what the application sends it, keep it from reaching the
private network, as in production, in `docker-compose.project.yml`:

```yaml
services:
    gotenberg:
        command: ["gotenberg", "--chromium-deny-private-ips"]
```
