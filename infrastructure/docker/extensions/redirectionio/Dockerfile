# The redirection.io agent, built by "castor build" for the redirectionio extension (see
# README.md). Updated by "castor starter:sync".

FROM alpine:3.24 AS download

ARG REDIRECTIONIO_AGENT_VERSION=3.4.0
# Set by BuildKit: amd64 or arm64
ARG TARGETARCH

# hadolint ignore=DL3018
RUN apk add --no-cache ca-certificates curl \
    && arch="$([ "${TARGETARCH}" = arm64 ] && echo aarch64 || echo amd64)" \
    && curl -fsSLo /tmp/agent.tar.gz "https://packages.redirection.io/dist/stable/3/any/${REDIRECTIONIO_AGENT_VERSION}/redirectionio-agent_${REDIRECTIONIO_AGENT_VERSION}-1_any_${arch}.tar.gz" \
    && tar -xzf /tmp/agent.tar.gz -C /tmp

FROM scratch

COPY --from=download /tmp/redirection-agent/redirectionio-agent /usr/local/bin/redirectionio-agent
# The agent fetches the rules of the project over HTTPS
COPY --from=download /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt

EXPOSE 80

CMD ["/usr/local/bin/redirectionio-agent", "--config", "/etc/redirectionio/agent.yml"]
