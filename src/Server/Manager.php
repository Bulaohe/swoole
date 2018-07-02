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
     * @var \HuangYi\Http\Server\Application
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

        $this->btable = new Table(8);
        $this->btable->column('val', Table::TYPE_INT, 8);
        $this->btable->create();
        
        $this->btable->set('worker_counter', ['val'=>0]);
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

                    $this->container['events']->fire($event, func_get_args());
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

        $this->container['events']->fire('http.start', func_get_args());
    }

    /**
     * "onWorkerStart" listener.
     */
    public function onWorkerStart($serv, $worker_id)
    {
        $this->clearCache();
        $this->setProcessName('worker process');

        $this->container['events']->fire('http.workerStart', func_get_args());

        $this->createApplication();
        
        //below exec the micro service register
        $work_num = env('HTTP_SERVER_OPTIONS_WORKERNUM', 1);
        
        $this->btable->incr('worker_counter', 'val');
        if($work_num == $this->btable->get('worker_counter')['val']){
            $this->preProcessServiceRegister();
            echo 'worker ' . $worker_id . ': started the register' . "\n";
        }
    }

    /**
     * "onRequest" listener.
     *
     * @param \Swoole\Http\Request $swooleRequest
     * @param \Swoole\Http\Response $swooleResponse
     */
    public function onRequest($swooleRequest, $swooleResponse)
    {
        // Reset user-customized providers
        $this->getApplication()->resetProviders();

        $illuminateRequest = Request::make($swooleRequest)->toIlluminate();
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
        $this->preProcessServiceLogoff();
        echo 'start to stop the eureka service' . "\n";
        
        $this->removePidFile();

        $this->container['events']->fire('http.showdown', func_get_args());
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
     * @return \HuangYi\Http\Server\Application
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
    
    /**
     *
     * @param array $eureka
     */
    public function preProcessServiceRegister()
    {
        echo 'start register' . "\n";
        
        $register_host = config('eureka.register_host');
        if($register_host === null){
            return false;
        }
        
        $eureka = config('eureka');
        $data = $eureka['register_data'];
        
        if($eureka['service_port_pre'] != ''){
            $port = $eureka['service_port_pre'] . $this->port;
        }else{
            $port = $this->port;
        }
        
        if($eureka['service_host'] != ''){
            $host = $eureka['service_host'];
        }else{
            $host = $this->host;
        }
        
        $data['instance']['instanceId'] = $eureka['service_name'] . '_' . $host . ':' . $port;
        $data['instance']['hostName'] = $host;
        $data['instance']['app'] = $eureka['service_name'];
        $data['instance']['ipAddr'] = $host;
        $data['instance']['port']['$'] = $port;
        $data['instance']['leaseInfo']['registrationTimestamp'] = round(microtime(true) * 1000);
        $data['instance']['leaseInfo']['lastRenewalTimestamp'] = round(microtime(true) * 1000);
        $data['instance']['leaseInfo']['serviceUpTimestamp'] = round(microtime(true) * 1000);
        $data['instance']['homePageUrl'] = 'http://' . $host . ':' . $port . '/';
        $data['instance']['statusPageUrl'] = 'http://' . $host . ':' . $port . '/info';
        $data['instance']['healthCheckUrl'] = 'http://' . $host . ':' . $port . '/health';
        $data['instance']['vipAddress'] = $eureka['service_name'];
        $data['instance']['secureVipAddress'] = $eureka['service_name'];
        $data['instance']['lastUpdatedTimestamp'] = round(microtime(true) * 1000) . '';
        $data['instance']['lastDirtyTimestamp'] = round(microtime(true) * 1000) . '';
        
        $rhosts = explode(',', $register_host);
        foreach ($rhosts as $rhost) {
            $path = $this->getEurekaPath($eureka['register_path'], $eureka['service_name']);
            $this->serviceRegister($rhost, $eureka['register_port'], $path, $data);
        }
    }
    
    /**
     * get url
     * 
     * @param string $host 
     * @param string $port
     * @param string $path
     * @param string $service_name
     * @return string
     */
    protected function getEurekaUrl($host, $port, $path, $service_name)
    {
        return 'http://' . $host . ':' . $port . $path . $service_name . '/';
    }
    
    /**
     * get url path
     * 
     * @param string $path
     * @param string $service_name
     * @return stringe
     */
    protected function getEurekaPath($path, $service_name)
    {
        return $path . $service_name . '/';
    }
    
    /**
     * auto register micro service
     * @param string $url
     * @param array $data
     * @return boolean|mixed
     */
    protected function serviceRegister($host, $port, $path, $data = [])
    {
        if(empty($data)){
            return false;
        }
        
        $data = json_encode($data);
        
        $cli = new \swoole_http_client($host, $port);
        $cli->set(['timeout' => 2]);
        $cli->setHeaders([
            'Content-Type'=>'application/json',
        ]);
        $cli->post($path, $data, function ($cli) {
            echo "register request statusCode: " . $cli->statusCode . "\n";
            $cli->close();
        });
    }
    
    /**
     * auto log off micro service
     */
    /**
     * auto log off micro service
     */
    protected function preProcessServiceLogoff()
    {
        echo 'log off service' . "\n";
        
        $logoff_host = config('eureka.register_host');
        if($logoff_host === null){
            return false;
        }
        
        $eureka = config('eureka');
        if($eureka['service_port_pre'] != ''){
            $port = $eureka['service_port_pre'] . $this->port;
        }else{
            $port = $this->port;
        }
        
        if($eureka['service_host'] != ''){
            $host = $eureka['service_host'];
        }else{
            $host = $this->host;
        }
        
        $rhosts = explode(',', $logoff_host);
        foreach ($rhosts as $rhost) {
            $url = $this->getEurekaUrl($rhost, $eureka['register_port'], $eureka['register_path'], $eureka['service_name']) . $eureka['service_name'] . '_' . $host . ':' . $port;;
            $res = $this->serviceLogoff($url);
            echo $res['res'];
            if($res['status'] == 1){
                break;
            }
        }
    }
    
    protected function serviceLogoff($url = ''){
        $ch = curl_init();
        // Set default options.
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILETIME, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, false);
        
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        
        $res = curl_exec($ch);
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        if($res === false){
            return [
                'res' => 'curl request fail http code ' . $http_code,
                'code' => $http_code,
                'status' => 0,
            ];
        }
        
        if($http_code >= 400){
            return [
                'res' => 'curl request fail http code ' . $http_code,
                'code' => $http_code,
                'status' => 0,
            ];
        }
        
        return [
            'res' => $res,
            'code' => $http_code,
            'status' => 1,
        ];
    }
}
