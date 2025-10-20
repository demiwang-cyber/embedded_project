FROM dunglas/frankenphp:php8.3

# 安装 PDO MySQL 扩展
RUN install-php-extensions pdo pdo_mysql

# 复制应用文件
COPY . /app

# 设置工作目录
WORKDIR /app

# 暴露端口
EXPOSE 8080

# 启动命令
CMD ["frankenphp", "run", "--listen", ":8080"]
