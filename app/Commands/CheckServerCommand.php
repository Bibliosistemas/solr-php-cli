<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class CheckServerCommand extends Command
{
    protected $signature = 'server:check {url : The URL of the server to check} {--timeout=5 : Request timeout in seconds}';
    protected $description = 'Check if an HTTP server is up and responding';

    public function handle()
    {
        $url = $this->argument('url');
        $timeout = $this->option('timeout');

        $this->info("Checking server: {$url}");
        
        $client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
        ]);

        try {
            $startTime = microtime(true);
            
            $response = $client->get($url);
            
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->info("✓ Server is UP");
                $this->info("  Status Code: {$statusCode}");
                $this->info("  Response Time: {$responseTime}ms");
                return 0;
            } else {
                $this->warn("⚠ Server responded but with unexpected status");
                $this->warn("  Status Code: {$statusCode}");
                $this->warn("  Response Time: {$responseTime}ms");
                return 1;
            }
            
        } catch (RequestException $e) {
            $this->error("✗ Server is DOWN or unreachable");
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $this->error("  Status Code: {$statusCode}");
            } else {
                $this->error("  Error: " . $e->getMessage());
            }
            
            return 1;
            
        } catch (\Exception $e) {
            $this->error("✗ Error checking server: " . $e->getMessage());
            return 1;
        }
    }

    public function schedule(Schedule $schedule): void
    {
        
    }
}