FROM wordpress:6.7-php8.2-fpm-alpine

LABEL maintainer="Platform Engineering <devops@example.com>"
LABEL description="Production-hardened WordPress PHP-FPM Alpine image with OpenTelemetry W3C Trace & SQL Instrumentation"

# Copy PHP production tuning, OPcache settings, and FPM worker pool limits
COPY php-optimization.ini /usr/local/etc/php/conf.d/99-production-optimization.ini
COPY zz-fpm-tuning.conf /usr/local/etc/php-fpm.d/zz-fpm-tuning.conf

# Copy lightweight W3C OpenTelemetry PHP & SQL tracer
COPY otel-wordpress-tracer.php /usr/local/lib/php/otel-wordpress-tracer.php
RUN chmod 644 /usr/local/lib/php/otel-wordpress-tracer.php
