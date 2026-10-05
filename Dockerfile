FROM python:3.12-slim-bookworm AS python_runtime
FROM postgres:18-bookworm AS postgres_tools
FROM node:24-bookworm-slim AS frontend
WORKDIR /frontend
COPY backend/package.json backend/package-lock.json ./
RUN npm ci
COPY backend/vite.config.js ./vite.config.js
COPY backend/resources ./resources
RUN npm run build
FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libonig-dev libxml2-dev libbz2-1.0 libexpat1 libffi8 libgdbm6 libgdbm-compat4 liblzma5 libreadline8 libsqlite3-0 libssl3 liblz4-1 libzstd1 unzip git fonts-dejavu-core && docker-php-ext-install pdo_pgsql zip gd mbstring dom && rm -rf /var/lib/apt/lists/*
COPY --from=postgres_tools /usr/lib/postgresql/18/bin/pg_dump /usr/lib/postgresql/18/bin/pg_restore /usr/lib/postgresql/18/bin/createdb /usr/lib/postgresql/18/bin/dropdb /usr/local/bin/
COPY --from=postgres_tools /usr/lib/*-linux-gnu/libpq.so.5* /usr/local/lib/
COPY --from=python_runtime /usr/local/bin/python3.12 /usr/local/bin/python3.12
COPY --from=python_runtime /usr/local/lib/python3.12 /usr/local/lib/python3.12
COPY --from=python_runtime /usr/local/lib/libpython3.12.so* /usr/local/lib/
RUN ldconfig
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./backend/
WORKDIR /app/backend
RUN composer install --no-dev --prefer-dist --no-scripts --no-interaction
WORKDIR /app
COPY optimizer/requirements.lock.txt /app/optimizer/requirements.lock.txt
RUN /usr/local/bin/python3.12 -m venv /app/optimizer/.venv && /app/optimizer/.venv/bin/pip install --no-cache-dir -r /app/optimizer/requirements.lock.txt
COPY backend /app/backend
COPY --from=frontend /frontend/public/build /app/backend/public/build
COPY optimizer/runner.py /app/optimizer/runner.py
WORKDIR /app/backend
RUN composer dump-autoload --no-dev --optimize && mkdir -p storage/app/private storage/app/backups storage/logs storage/framework/cache storage/framework/sessions storage/framework/views && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8080
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8080"]
