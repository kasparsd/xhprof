ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache \
    autoconf \
    gcc \
    g++ \
    make \
    libc-dev \
    linux-headers \
    bash \
    git \
    valgrind \
    gdb \
    curl \
    pcre-dev

WORKDIR /xhprof

# Set up test environment
ENV TEST_PHP_EXECUTABLE=/usr/local/bin/php
ENV REPORT_EXIT_STATUS=1