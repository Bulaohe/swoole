<?php

/*
 * This file is part of the huang-yi/laravel-swoole-http package.
 *
 * (c) Huang Yi <coodeer@163.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bulaohe\Swoole\Commands;

use Bulaohe\Swoole\Watcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Swoole\Process;
use Symfony\Component\Console\Input\InputOption;

class HttpServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swoole:http {action : start|stop|restart|reload|watch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Swoole HTTP Server controller.';

    /**
     * The console command action.
     *
     * @var string
     */
    protected $action;

    /**
     *
     * The pid.
     *
     * @var int
     */
    protected $pid;
    
    /**
     * pid file
     * @var string
     */
    protected $pid_file;
    
    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->setName('swoole:http')
            ->setDescription('Start the swoole server.')
            ->setHelp("You can use it to start the swoole http service.")
            ->addOption('pid_file', 'pfi', InputOption::VALUE_REQUIRED, 'The http server pid file.', config('http.server.options.pid_file'))
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'The http server host.', config('http.server.host'))
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'The http server port.', config('http.server.port'));
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->initAction();
        $this->runAction();
    }

    /**
     * Run action.
     */
    protected function runAction()
    {
        $this->detectSwoole();

        $this->{$this->action}();
    }

    /**
     * Run swoole_http_server.
     */
    protected function start()
    {
        if ($this->isRunning($this->getPid())) {
            $this->error('Failed! swoole_http_server process is already running.');
            exit(1);
        }

        $this->info('Starting swoole http server...');

        $this->info('> (You can run this command to ensure the ' .
            'swoole_http_server process is running: ps aux|grep "swoole")');

        $port = $this->input->getOption('port');
        $pid_file = $this->input->getOption('pid_file');
        $host = $this->input->getOption('host');
        $this->pid_file = $pid_file;
        
        $this->laravel->make('swoole.http')->run($port, $pid_file, $host);
    }

    /**
     * Stop swoole_http_server.
     */
    protected function stop()
    {
        $pid_file = $this->input->getOption('pid_file');
        $this->pid_file = $pid_file;
        
        $pid = $this->getPid();

        if (! $this->isRunning($pid)) {
            $this->error("Failed! There is no swoole_http_server process running.");
            exit(1);
        }

        $this->info('Stopping swoole_http_server...');

        $isRunning = $this->killProcess($pid, SIGTERM, 15);

        if ($isRunning) {
            $this->error('Unable to stop the swoole_http_server process.');
            exit(1);
        }

        // I don't known why Swoole didn't trigger "onShutdown" after sending SIGTERM.
        // So we should manually remove the pid file.
        $this->removePidFile();

        $this->info('> success');
    }

    /**
     * Restart swoole http server.
     */
    protected function restart()
    {
        $pid_file = $this->input->getOption('pid_file');
        $this->pid_file = $pid_file;
        
        $pid = $this->getPid();

        if ($this->isRunning($pid)) {
            $this->stop();
        }

        $this->start();
    }

    /**
     * Reload.
     */
    protected function reload()
    {
        $pid_file = $this->input->getOption('pid_file');
        $this->pid_file = $pid_file;
        
        $pid = $this->getPid();

        if (! $this->isRunning($pid)) {
            $this->error("Failed! There is no swoole_http_server process running.");
            exit(1);
        }

        $this->info('Reloading swoole_http_server...');

        $isRunning = $this->killProcess($pid, SIGUSR1);

        if (! $isRunning) {
            $this->error('> failure');
            exit(1);
        }

        $this->info('> success');
    }

    /**
     * Watch.
     */
    public function watch()
    {
        $pid_file = $this->input->getOption('pid_file');
        $this->pid_file = $pid_file;
        
        if ($this->isRunning($this->getPid())) {
            $this->stop();
        }

        if ($this->isWatched()) {
            $this->removeWatchedFile();
        }

        config('http.server.options.daemonize', 0);

        Event::listen('http.workerStart', function () {
            if ($this->createWatchedFile()) {
                $watcher = $this->createWatcher();
                $watcher->watch();
            }
        });

        Event::listen('http.workerStop', function () {
            $this->removeWatchedFile();
        });

        $this->start();
    }

    /**
     * Initialize command action.
     */
    protected function initAction()
    {
        $this->action = $this->argument('action');

        if (! in_array($this->action, ['start', 'stop', 'restart', 'reload', 'watch'])) {
            $this->error('Unexpected argument "' . $this->action . '".');
            exit(1);
        }
    }

    /**
     * If Swoole process is running.
     *
     * @param int $pid
     * @return bool
     */
    protected function isRunning($pid)
    {
        if (! $pid) {
            return false;
        }

        Process::kill($pid, 0);

        return ! swoole_errno();
    }

    /**
     * Kill process.
     *
     * @param int $pid
     * @param int $sig
     * @param int $wait
     * @return bool
     */
    protected function killProcess($pid, $sig, $wait = 0)
    {
        Process::kill($pid, $sig);

        if ($wait) {
            $start = time();

            do {
                if (! $this->isRunning($pid)) {
                    break;
                }

                usleep(100000);
            } while (time() < $start + $wait);
        }

        return $this->isRunning($pid);
    }

    /**
     * Get pid.
     *
     * @return int|null
     */
    protected function getPid()
    {
        if ($this->pid) {
            return $this->pid;
        }

        $pid = null;
        $path = $this->getPidPath();

        if (file_exists($path)) {
            $pid = (int) file_get_contents($path);

            if (! $pid) {
                $this->removePidFile();
            } else {
                $this->pid = $pid;
            }
        }

        return $this->pid;
    }

    /**
     * Get Pid file path.
     *
     * @return string
     */
    protected function getPidPath()
    {
        return $this->pid_file;
    }

    /**
     * Remove Pid file.
     */
    protected function removePidFile()
    {
        if (file_exists($this->getPidPath())) {
            unlink($this->getPidPath());
        }
    }

    /**
     * Extension swoole is required.
     */
    protected function detectSwoole()
    {
        if (! extension_loaded('swoole')) {
            $this->error('Extension swoole is required!');

            exit(1);
        }
    }

    /**
     * Create watcher.
     *
     * @return \HuangYi\Watcher\Watcher
     */
    protected function createWatcher()
    {
        $config = config('http.watcher');
        $directories = $config['directories'];
        $excludedDirectories = $config['excluded_directories'];
        $suffixes = $config['suffixes'];

        $watcher = new Watcher($directories, $excludedDirectories, $suffixes);

        return $watcher->setHandler(function () {
            $this->info('Reload swoole_http_server.');

            $this->laravel['swoole.http']->reload();
        });
    }

    /**
     * If watcher is running.
     *
     * @return bool
     */
    protected function isWatched()
    {
        return file_exists($this->getWatchedFile());
    }

    /**
     * Create watched flag file.
     *
     * @return bool
     */
    protected function createWatchedFile()
    {
        if (! $this->isWatched()) {
            return touch($this->getWatchedFile());
        }

        return false;
    }

    /**
     * Remove watched flag file.
     *
     * @return bool
     */
    protected function removeWatchedFile()
    {
        if ($this->isWatched()) {
            return unlink($this->getWatchedFile());
        }

        return false;
    }

    /**
     * Get watched flag file.
     *
     * @return string
     */
    protected function getWatchedFile()
    {
        return base_path('storage/logs/.watched');
    }
}
