# swoole
Add Swoole to Laravel 5.5 and Lumen 5.5

# This pacakge is a rewrite of huang-yi/laravel-swoole-http, Thanks to Huang Yi <coodeer@163.com>.

#Notice
You should reset the　instances of Singleton mode Facades Classes in the method register of your self-defined ServiceProvider like below:
1. add clear code in the register function
Facade::clearResolvedInstance('your-service-alias-name');

2. add config/http.providers your service provider
App\Providers\{YourProvider}::class,

# start command
php artisan swoole:http --host=0.0.0.0 --port=9807 --pid_file=/tmp/swoole1.pid start/stop/reload/restart

php artisan swoole:http --host=0.0.0.0 --port=9808 --pid_file=/tmp/swoole2.pid start/stop/reload/restart

# nginx conf

server {
    listen 80;
    server_name your_server_name;
    root /var/www/logistics/public;
    index index.php;

    location = /index.php {
        # Ensure that there is no such file named "not_exists" in your "public" directory.
        try_files /not_exists @swoole;
    }

    location / {
        try_files $uri $uri/ @swoole;
    }

    location @swoole {
        set $suffix "";

        if ($uri = /index.php) {
            set $suffix "/";
        }

        proxy_set_header Host $host;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # IF https
        # proxy_set_header HTTPS "on";

        proxy_pass http://127.0.0.1:9807$suffix;
    }
}
