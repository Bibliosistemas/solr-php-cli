<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class SolrQueryCommand extends Command
{
    protected $signature = 'solr:query {engine? : The Solr engine to query (default: local)} {--query= *:* : The query parameter} {--rows=10 : Number of records to retrieve} {--table : Show results as table with id, title, topic, publishDate fields}';
    protected $description = 'Query Solr server and return records';

    public function handle()
    {
        $engine = $this->argument('engine') ?? 'local';
        $query = $this->option('query');
      if(is_Array($query)) $query = array_first($query); 
        $rows = $this->option('rows');
        $showTable = $this->option('table');
        
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
        
        $queryUrl = "{$host}:{$port}/solr/{$core}/select";
        
        $this->info("Querying Solr engine: {$engine}");
        $this->info("URL: {$queryUrl}");
        $this->info("Query: {$query}");
        $this->info("Rows: {$rows}");
        
        $client = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
        
        try {
            $startTime = microtime(true);
            
            $params = [
                'q' => $query,
                'rows' => $rows,
                'wt' => 'json',
                'indent' => 'true'
            ];
            
            $options = [
                'query' => $params
            ];
            
            if ($engineConfig['auth']) {
                $options['auth'] = $engineConfig['auth'];
            }
            
            $response = $client->get($queryUrl, $options);
            
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                $this->info("✓ Query successful");
                $this->info("  Response Time: {$responseTime}ms");
                $this->info("  Status Code: {$statusCode}");
                
                if (isset($data['response'])) {
                    $numFound = $data['response']['numFound'];
                    $start = $data['response']['start'];
                    $docs = $data['response']['docs'];
                    
                    $this->info("  Results found: {$numFound}");
                    $this->info("  Documents returned: " . count($docs));
                    
                    if (isset($data['responseHeader']['QTime'])) {
                        $this->info("  Query Time: " . $data['responseHeader']['QTime'] . "ms");
                    }
                    
                    if ($showTable) {
                        $this->displayResultsAsTable($docs);
                    } else {
                        $this->newLine();
                        $this->info("JSON Result:");
                        $this->newLine();
                        
                        $jsonOutput = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $this->line($jsonOutput);
                    }
                    
                } else {
                    $this->warn("⚠ Unexpected response format");
                    $this->warn(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                
                return 0;
                
            } else {
                $this->warn("⚠ Solr server responded with unexpected status");
                $this->warn("  Status Code: {$statusCode}");
                $this->warn("  Response Time: {$responseTime}ms");
                return 1;
            }
            
        } catch (RequestException $e) {
            $this->error("✗ Failed to query Solr server");
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $this->error("  Status Code: {$statusCode}");
                $body = $e->getResponse()->getBody()->getContents();
                if ($body) {
                    $this->error("  Response: " . substr($body, 0, 200) . "...");
                }
            } else {
                $this->error("  Error: " . $e->getMessage());
            }
            
            return 1;
            
        } catch (\Exception $e) {
            $this->error("✗ Error querying Solr server: " . $e->getMessage());
            return 1;
        }
    }

    private function displayResultsAsTable($docs)
    {
        if (empty($docs)) {
            $this->info("No documents to display.");
            return;
        }

        $headers = ['ID', 'Title', 'Topic', 'Publish Date'];
        $rows = [];

        foreach ($docs as $doc) {
            $id = $doc['id'] ?? 'N/A';
            $title = $doc['title'] ?? $doc['title_t'] ?? 'N/A';
            $topic = $doc['topic'] ?? $doc['topic_s'] ?? 'N/A';
            $publishDate = $doc['publishDate'] ?? $doc['publishDate_dt'] ?? $doc['publish_date'] ?? 'N/A';

            if (is_array($title)) {
                $title = implode(', ', $title);
            }
            if (is_array($topic)) {
                $topic = implode(', ', $topic);
            }
            if (is_array($publishDate)) {
                $publishDate = implode(', ', $publishDate);
            }

            $title = $this->truncateText($title, 50);
            $topic = $this->truncateText($topic, 30);

            $rows[] = [$id, $title, $topic, $publishDate];
        }

        $this->table($headers, $rows);
    }

    private function truncateText($text, $length)
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    public function schedule(Schedule $schedule): void
    {
        
    }
}