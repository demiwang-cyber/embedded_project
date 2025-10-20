FROM dunglas/frankenphp:php8.3

# 安装 PDO MySQL 扩展
RUN install-php-extensions pdo pdo_mysql

# 复制应用文件
COPY . /app

# 设置工作目录
WORKDIR /app

# 设置环境变量 - FrankenPHP 通过 SERVER_NAME 配置监听
ENV SERVER_NAME=":8080"
ENV FRANKENPHP_CONFIG="worker ./index.php"

# 启动 FrankenPHP
CMD ["frankenphp", "run"]
