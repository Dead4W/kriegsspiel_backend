<?php

namespace App\Console\Commands;

use App\Socket\Callbacks\SocketOnCloseCallback;
use App\Socket\Callbacks\SocketOnMessageCallback;
use App\Socket\Callbacks\SocketOnOpenCallback;
use App\Socket\Callbacks\SocketOnStartCallback;
use Illuminate\Console\Command;
use OpenSwoole\Constant;
use OpenSwoole\Server as ServerAlias;
use OpenSwoole\WebSocket\Server;

class SocketStartCommand extends Command
{
    public $signature = 'socket:start
                    {--host= : The IP address the server should bind to [default: "0.0.0.0"]]}
                    {--port= : The port the server should be available on [default: "9501"]}
                    {--workers= : Number of OpenSwoole worker processes [default: CPU count, minimum 2]}
    ';

    public $description = 'Start the Socket server';

    public function handle() {
        ini_set('memory_limit', '512M');

        $workerNum = $this->getWorkerNum();

        $server = new Server(
            host: $this->option('host') ?? "0.0.0.0",
            port: $this->option('port') ?? 9501,
            mode: ServerAlias::POOL_MODE,
            sockType: Constant::SOCK_TCP
        );

        $server->set([
            'worker_num' => $workerNum,
            'open_cpu_affinity' => true,

            'max_request' => 100,

            'input_buffer_size' => 128 * 1024 * 1024,
            'buffer_output_size' => 128 * 1024 * 1024,
            'socket_buffer_size' => 128 * 1024 *1024,

            'kernel_socket_send_buffer_size' => 128 * 1024 * 1024,
            'kernel_socket_recv_buffer_size' => 128 * 1024 * 1024,

            'package_max_length' => 128 * 1024 * 1024,

            'websocket_compression' => true,
        ]);

        $this->info("Starting WebSocket server with {$workerNum} workers...");

        $server->on("Start", new SocketOnStartCallback($this->output));

        $server->on("Open", new SocketOnOpenCallback($this->output));
        $server->on("Message", new SocketOnMessageCallback($this->output));

        $server->on('Close', new SocketOnCloseCallback($this->output));
        $server->on('Disconnect', new SocketOnCloseCallback($this->output));

        $server->start();
    }

    private function getWorkerNum(): int
    {
        $option = $this->option('workers');
        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        $cpuNum = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 2;

        return max(2, (int) $cpuNum);
    }

}
