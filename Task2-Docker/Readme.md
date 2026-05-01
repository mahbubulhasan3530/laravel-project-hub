## Dockerize your Laravel app

#### step-1:Create Dockerfile (root project)
```bash
FROM php:8.3-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage \
    && chown -R www-data:www-data bootstrap/cache \
    && chmod -R 755 storage \
    && chmod -R 755 bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```
#### Step-2: Create .dockerignore (root project)

```bash
# Git
.git
.gitignore

# Laravel specific
/vendor
/node_modules
.env
.env.backup
/storage/*.key
/public/storage
.phpunit.result.cache

# Docker specific
Dockerfile
.dockerignore
docker/

# OS/IDE files
.DS_Store
.vscode
.idea
*.log
```
#### Step-3: Build Docker Image with full tag 
```bash
docker build -t satabun3530/laravel:v1 .
```
**This already sets**
```bash
Docker Hub username = satabun3530
repo = laravel
tag = v1
```
#### Step-4: Login to Docker Hub
```bash
docker login 
```

#### Step-5: Push image 
```bash
docker push satabun3530/laravel:v1
```

### To test the Docker-pushed image, we need to run it with an Nginx container and inject the .env file at runtime.

#### Step-1: create a nginx default.conf file
```bash
server {
    listen 80;
    server_name localhost;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass <laravel-app-ip>:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### Step-2: Create a compose file for nignx
```bash
services:
  nginx:
    image: nginx:alpine
    container_name: laravel-nginx
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./default.conf:/etc/nginx/conf.d/default.conf
    networks:
      - laravel-net

networks:
  laravel-net:
    driver: bridge
```
#### Step-3: Deploy nginx container
```bash
docker compose up -d 
```


## Now deploy the container for testing 
```
docker run -d -p 9000:9000 \
  --env-file .env \
  -v $(pwd)/.env:/var/www/html/.env \
  --name app satabun3530/laravel:v1
```

#### My image rule is 
```bash
https://hub.docker.com/r/satabun3530/laravel/tags
```