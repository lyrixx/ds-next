# Elasticsearch with plugins, when the project needs some: see README.md. The image and
# the plugins come from docker-compose.version.yml, which belongs to the project.

ARG ELASTICSEARCH_IMAGE=elasticsearch:9.5.5
# hadolint ignore=DL3006
FROM ${ELASTICSEARCH_IMAGE}

# e.g. "analysis-icu analysis-phonetic"
ARG ELASTICSEARCH_PLUGINS=""
RUN for plugin in ${ELASTICSEARCH_PLUGINS}; do \
        elasticsearch-plugin install --batch "${plugin}"; \
    done
