FROM php:8.3-cli

# 安装 PDO MySQL 扩展
RUN docker-php-ext-install pdo pdo_mysql

# 复制应用文件
COPY . /app

# 设置工作目录
WORKDIR /app

# 暴露端口（Railway 会自动设置 $PORT）
EXPOSE 8080

# 使用 PHP 内置服务器
CMD php -S 0.0.0.0:${PORT:-8080} -t /app
