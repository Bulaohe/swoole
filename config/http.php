<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP server configurations.
    |--------------------------------------------------------------------------
    |
    | @see https://wiki.swoole.com/wiki/page/274.html
    |
    */

    'server' => [

        'host' => env('HTTP_SERVER_HOST', '0.0.0.0'),

        'port' => env('HTTP_SERVER_PORT', '9901'),
        
        'name' => env('HTTP_SERVER_NAME', 'default_server'),

        'name' => env('HTTP_SERVER_NAME', 'default_server'),

        'options' => [

            'pid_file' => env('HTTP_SERVER_OPTIONS_PID_FILE', base_path('storage/logs/http.pid')),

            'log_file' => env('HTTP_SERVER_OPTIONS_LOG_FILE', base_path('storage/logs/http.log')),

            'daemonize' => env('HTTP_SERVER_OPTIONS_DAEMONIZE', 1),
            
            'worker_num' => env('HTTP_SERVER_OPTIONS_WORKERNUM', 4),
            
            'reactor_num' => env('HTTP_SERVER_OPTIONS_REACTOR_NUM', 4),
            
            'max_request' => env('HTTP_SERVER_OPTIONS_MAX_REQUEST', 4),
            
            'dispatch_mode' => env('HTTP_SERVER_OPTIONS_DISPATCH_MODE', 3),
            
            'log_level' => env('HTTP_SERVER_OPTIONS_Log_level', 5),
            
            'open_http2_protocol' => env('HTTP_SERVER_OPTIONS_HTTP2', false),
            'package_max_length' => env('HTTP_SERVER_OPTIONS_PACKAGE_MAXLENGTH', 20480),//bytes need use with http2
            
            //系统需要开启ptrace  kernel.yama.ptrace_scope=0
            'request_slowlog_timeout' => env('HTTP_SERVER_OPTIONS_SLOW_TIMEOUT', 2),//second
            
            'request_slowlog_file' => env('HTTP_SERVER_OPTIONS_SLOW_FILE', base_path('storage/logs/httpslow.log')),//
            
            'trace_event_worker' => true,//跟踪 Task 和 Worker 进程

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reset providers configurations.
    |--------------------------------------------------------------------------
    |
    */

    'providers' => [
        // App\Providers\AuthServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | File watcher configurations.
    |--------------------------------------------------------------------------
    |
    */

    'watcher' => [

        /*
        |--------------------------------------------------------------------------
        | The watched directories.
        |--------------------------------------------------------------------------
        |
        | Configure directories that need to be watched.
        |
        */

        'directories' => [
            base_path(),
        ],

        /*
         |--------------------------------------------------------------------------
         | The excluded directories.
         |--------------------------------------------------------------------------
         |
         | Configure directories that need to be excluded.
         |
         */

        'excluded_directories' => [
            base_path('storage/'),
        ],

        /*
         |--------------------------------------------------------------------------
         | The file suffixes.
         |--------------------------------------------------------------------------
         |
         | Configure file suffixes that need to be watched.
         |
         */

        'suffixes' => [
            '.php', '.env',
        ],
    ],

];
