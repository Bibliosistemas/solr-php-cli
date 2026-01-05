<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class SolrPingCommand extends Command
{
    protected $signature = 'solr:ping {engine? : The Solr engine to check (default: local)}';
    protected $description = 'Check if Solr server is up using ping endpoint';

    public function handle()
    {
        $engine = $this->argument('engine') ?? 'local';
        
        $configPath = storage_path('app/private/solr_engines.json');
        
        if (!file_exists($configPath)) {
            $this->error("Solr engines configuration file not found: {$configPath}");
            return 1;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        
        if (!isset($config[$engine])) {
            $this->error("Solr engine '{$engine}' not found in configuration");
            $this->info("Available engines: " . implode(', ', array_keys($config)));
            return 1;
        }
        
        $engineConfig = $config[$engine];
        $host = rtrim($engineConfig['host'], '/');
        $port = $engineConfig['port'];
        $core = $engineConfig['core'];
        
        $pingUrl = "{$host}:{$port}/solr/{$core}/admin/ping";
        
        $this->info("Pinging Solr engine: {$engine}");
        $this->info("URL: {$pingUrl}");
        
        $client = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
        
        try {
            $startTime = microtime(true);
            
            $options = [];
            if ($engineConfig['auth']) {
                $options['auth'] = $engineConfig['auth'];
            }
            
            $response = $client->get($pingUrl, $options);
            
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);
            
            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            
            if ($statusCode === 200 && isset($body['status']) && $body['status'] === 'OK') {
                $this->info("✓ Solr server is UP");
                $this->info("  Status: " . $body['status']);
                $this->info("  Response Time: {$responseTime}ms");
                if (isset($body['responseHeader']['QTime'])) {
                    $this->info("  Query Time: " . $body['responseHeader']['QTime'] . "ms");
                }
                return 0;
            } else {
                $this->warn("⚠ Solr server responded but status is not OK");
                $this->warn("  Status Code: {$statusCode}");
                $this->warn("  Response Time: {$responseTime}ms");
                if (isset($body['status'])) {
                    $this->warn("  Status: " . $body['status']);
                }
                return 1;
            }
            
        } catch (RequestException $e) {
            $this->error("✗ Solr server is DOWN or unreachable");
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $this->error("  Status Code: {$statusCode}");
            } else {
                $this->error("  Error: " . $e->getMessage());
            }
            
            return 1;
            
        } catch (\Exception $e) {
            $this->error("✗ Error checking Solr server: " . $e->getMessage());
            return 1;
        }
    }

    public function schedule(Schedule $schedule): void
    {
        
    }
}