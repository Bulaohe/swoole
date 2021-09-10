<?php

/*
 * This file is part of the huang-yi/laravel-swoole-http package.
 *
 * (c) Huang Yi <coodeer@163.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bulaohe\Swoole\Server;

use Illuminate\Contracts\Container\Container;
use Swoole\Http\Server;
use Swoole\Table;
use Illuminate\Support\Facades\Facade;

class Manager
{
    const MAC_OSX = 'Darwin';

    /**
     * @var \Swoole\Http\Server
     */
    protected $server;

    /**
     * Container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * @var \Bulaohe\Swoole\Server\Application
     */
    protected $application;

    /**
     * @var string
     */
    protected $framework;

    /**
     * @var string
     */
    protected $basePath;
    
    /**
     * port
     * @var string
     */
    protected $port;
    
    /**
     * pid file
     * @var string
     */
    protected $pid_file;
    
    /**
     * swoole host
     * @var string
     */
    protected $host;
    
    /**
     * swoole server name
     * @var string
     */
    protected $server_name = '';
    
    /**
     * swoole table
     * @var object
     */
    protected $btable;

    /**
     * Server events.
     *
     * @var array
     */
    protected $events = [
        'start', 'shutDown', 'workerStart', 'workerStop', 'packet', 'close',
        'bufferFull', 'bufferEmpty', 'task', 'finish', 'pipeMessage',
        'workerError', 'managerStart', 'managerStop', 'request',
    ];

    /**
     * HTTP server manager constructor.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param string $framework
     * @param string $basePath
     */
    public function __construct(Container $container, $framework, $basePath = null)
    {
        $this->container = $container;
        $this->framework = $framework;
        $this->basePath = $basePath;
    }

    /**
     * Run swoole_http_server.
     */
    public function run($port, $pid_file, $host, $server_name)
    {
        $this->port = $port;
        $this->pid_file = $pid_file;
        $this->host = $host;
        $this->server_name = $server_name;
        
        $this->initialize();
        
        $this->server->start();
    }

    /**
     * Stop swoole_http_server.
     */
    public function stop()
    {
        $this->server->shutdown();
    }

    /**
     * Reload swoole_http_server.
     */
    public function reload()
    {
        $this->server->reload();
    }

    /**
     * Initialize.
     */
    protected function initialize()
    {
        $this->setProcessName('manager process');

        $this->createSwooleHttpServer();
        $this->configureSwooleHttpServer();
        $this->setSwooleHttpServerListeners();
    }

    /**
     * Creates swoole_http_server.
     */
    protected function createSwooleHttpServer()
    {
        $this->server = new Server($this->host, $this->port);
    }

    /**
     * Sets swoole_http_server configurations.
     */
    protected function configureSwooleHttpServer()
    {
        $config = config('http.server.options');
        $config['pid_file'] = $this->pid_file;

        $this->server->set($config);
    }

    /**
     * Sets swoole_http_server listeners.
     */
    protected function setSwooleHttpServerListeners()
    {
        foreach ($this->events as $event) {
            $listener = 'on' . ucfirst($event);

            if (method_exists($this, $listener)) {
                $this->server->on($event, [$this, $listener]);
            } else {
                $this->server->on($event, function () use ($event) {
                    $event = sprintf('http.%s', $event);

                    $this->container['events']->dispatch($event, func_get_args());
                });
            }
        }
    }

    /**
     * "onStart" listener.
     */
    public function onStart()
    {
        $this->setProcessName('master process');
        $this->createPidFile();

        $this->container['events']->dispatch('http.start', func_get_args());
    }

    /**
     * "onWorkerStart" listener.
     */
    public function onWorkerStart($serv, $worker_id)
    {
        $this->clearCache();
        $this->setProcessName('worker process');

        $this->container['events']->dispatch('http.workerStart', func_get_args());

        $this->createApplication();
    }

    /**
     * "onRequest" listener.
     *
     * @param \Swoole\Http\Request $swooleRequest
     * @param \Swoole\Http\Response $swooleResponse
     */
    public function onRequest($swooleRequest, $swooleResponse)
    {
        //Reset facade static request
        Facade::clearResolvedInstance('request');
        // Reset user-customized providers
        $this->getApplication()->resetProviders();

        if ($this->framework == 'laravel') {
            $illuminateRequest = Request::make($swooleRequest)->toIlluminate();
        } else {
            $illuminateRequest = LumenRequest::make($swooleRequest)->toIlluminate();
        }
        $illuminateResponse = $this->getApplication()->run($illuminateRequest);

        Response::make($illuminateResponse, $swooleResponse)->send();

        // Unset request and response.
        $swooleRequest = null;
        $swooleResponse = null;
        $illuminateRequest = null;
        $illuminateResponse = null;
    }

    /**
     * Set onShutdown listener.
     */
    public function onShutdown()
    {
        $this->removePidFile();

        $this->container['events']->dispatch('http.showdown', func_get_args());
    }

    /**
     * Create application.
     */
    protected function createApplication()
    {
        return $this->application = Application::make($this->framework, $this->basePath);
    }

    /**
     * Get application.
     *
     * @return \Bulaohe\Swoole\Server\Application
     */
    protected function getApplication()
    {
        if (! $this->application instanceof Application) {
            $this->createApplication();
        }

        return $this->application;
    }

    /**
     * Gets pid file path.
     *
     * @return string
     */
    protected function getPidFile()
    {
        return $this->pid_file;
    }

    /**
     * Create pid file.
     */
    protected function createPidFile()
    {
        $pidFile = $this->getPidFile();
        $pid = $this->server->master_pid;

        file_put_contents($pidFile, $pid);
    }

    /**
     * Remove pid file.
     */
    protected function removePidFile()
    {
        unlink($this->getPidFile());
    }

    /**
     * Clear APC or OPCache.
     */
    protected function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Sets process name.
     *
     * @param $process
     */
    protected function setProcessName($process)
    {
        // Mac OS doesn't support this function
        if (PHP_OS === static::MAC_OSX) {
            return;
        }

        $sn = $this->server_name ? '-' . $this->server_name : '';
        
        $serverName = 'swoole_http_server' . $sn;
        $appName = $this->container['config']->get('app.name', 'Laravel');

        $name = sprintf('%s: %s for %s', $serverName, $process, $appName);

        swoole_set_process_name($name);
    }
}
