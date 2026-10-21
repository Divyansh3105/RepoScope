# Production image: Apache + PHP 8.5, serving only public/.
#   docker build -t reposcope .
#   docker run --rm -p 8080:80 -e GITHUB_TOKEN=... reposcope
FROM php:8.5-apache

# production php.ini (errors logged, not shown), and no version numbers in response headers
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/reposcope.ini" \
    && sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' \
        /etc/apache2/conf-available/security.conf \
    && printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    AllowOverride FileInfo Indexes' \
        '</Directory>' \
        > /etc/apache2/conf-enabled/reposcope.conf

# public/ is the web root, so includes/ and config/ can't be requested.
# AllowOverride above lets public/.htaccess set the 404 page.
RUN sed -i 's!/var/www/html!/var/www/html/public!' /etc/apache2/sites-available/000-default.conf

# Render tells the app which port to use through $PORT (10000 there); Apache reads it at startup
ENV PORT=80
RUN sed -i 's/80/${PORT}/' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
