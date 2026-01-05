<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;

class SolrBackupCommand extends Command
{
    protected $signature = 'solr:backup 
        {engine? : The Solr engine to backup (default: local)} 
        {--format=json : Export format (json|csv|xml)} 
        {--query=*:* : Filter query for selective backup} 
        {--output= : Custom output filename} 
        {--batch=1000 : Batch size for cursor pagination} 
        {--compress=1 : Compress output with gzip} 
        {--exclude-version=1 : Exclude _version_ field from JSON}
        {--fields=* : Comma-separated field list (default: all except _version_)}';

    protected $description = 'Backup Solr data with cursor pagination and multiple export formats';

    private $client;
    private $engineConfig;
    private $backupStats;

    public function handle()
    {
        try {
            $this->backupStats = [
                'start_time' => microtime(true),
                'documents_processed' => 0,
                'batches_processed' => 0,
                'errors' => []
            ];

            // Load configuration
            if (!$this->loadEngineConfiguration()) {
                return 1;
            }

            // Create backup directory structure
            $this->createBackupDirectories();

            // Generate output filename
            $outputFile = $this->generateOutputFilename();

            // Execute backup based on format
            $totalDocuments = $this->executeBackup($outputFile);

            // Generate backup metadata
            $this->generateBackupMetadata($outputFile, $totalDocuments);

            // Display final statistics
            $this->displayFinalStatistics($totalDocuments);

            return 0;

        } catch (\Exception $e) {
            $this->error("✗ Backup failed: " . $e->getMessage());
            return 1;
        }
    }

    private function loadEngineConfiguration()
    {
        $engine = $this->argument('engine') ?? 'local';
        $configPath = storage_path('app/private/solr_engines.json');

        if (!file_exists($configPath)) {
            $this->error("Solr engines configuration file not found: {$configPath}");
            return false;
        }

        $config = json_decode(file_get_contents($configPath), true);

        if (!isset($config[$engine])) {
            $this->error("Solr engine '{$engine}' not found in configuration");
            $this->info("Available engines: " . implode(', ', array_keys($config)));
            return false;
        }

        $this->engineConfig = $config[$engine];
        $this->engineConfig['name'] = $engine;

        // Initialize HTTP client
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $this->info("Using Solr engine: {$engine}");
        $this->info("Host: {$this->engineConfig['host']}:{$this->engineConfig['port']}/{$this->engineConfig['core']}");

        return true;
    }

    private function createBackupDirectories()
    {
        $directories = [
            'backups/json',
            'backups/csv', 
            'backups/xml',
            'backups/metadata'
        ];

        foreach ($directories as $dir) {
            if (!Storage::exists($dir)) {
                Storage::makeDirectory($dir);
            }
        }
    }

    private function generateOutputFilename()
    {
        $engine = $this->engineConfig['name'];
        $format = $this->option('format');
        $timestamp = now()->format('Ymd_His');
        
        $customOutput = $this->option('output');
        if ($customOutput) {
            $filename = $customOutput;
            if (!str_ends_with($filename, '.' . $format)) {
                $filename .= '.' . $format;
            }
        } else {
            $filename = "backup_{$engine}_{$timestamp}.{$format}";
        }

        return "backups/{$format}/{$filename}";
    }

    private function executeBackup($outputFile)
    {
        $format = strtolower($this->option('format'));
        $query = $this->option('query');
        
        if(\is_Array($query)) $query = array_first($query);
        if ($query==':*' ) $query='*:*' ;   //avoid bug in recover default
        $batchSize = (int) $this->option('batch');

        $this->info("Starting backup in {$format} format...");
        $this->info("Query: {$query}");
        $this->info("Batch size: {$batchSize}");

        switch ($format) {
            case 'json':
                return $this->executeJsonBackup($outputFile, $query, $batchSize);
            case 'csv':
                return $this->executeCsvBackup($outputFile, $query, $batchSize);
            case 'xml':
                return $this->executeXmlBackup($outputFile, $query, $batchSize);
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    private function buildQueryParams($query, $batchSize, $cursorMark = '*')
    {
        $excludeVersion = $this->option('exclude-version');
        $fields = $this->option('fields');

        $params = [
            'q' => $query,
            'rows' => $batchSize,
            'wt' => 'json',
            'indent' => 'false',
            'cursorMark' => $cursorMark,
            'sort' => 'id asc'
        ];

        // Build field list
        if ($fields !== '*') {
            $params['fl'] = $fields;
        } elseif ($excludeVersion) {
            $params['fl'] = '*, -_version_';
        }

        return $params;
    }

    private function getSolrUrl()
    {
        $host = rtrim($this->engineConfig['host'], '/');
        $port = $this->engineConfig['port'];
        $core = $this->engineConfig['core'];
        return "{$host}:{$port}/solr/{$core}/select";
    }

    private function makeRequest($params)
    {
        $url = $this->getSolrUrl();
        $options = ['query' => $params];

        if ($this->engineConfig['auth']) {
            $options['auth'] = $this->engineConfig['auth'];
        }

        return $this->client->get($url, $options);
    }

    private function executeJsonBackup($outputFile, $query, $batchSize)
    {
        $filePath = storage_path('app/private/' . $outputFile);
        $fileHandle = fopen($filePath, 'w');

        if (!$fileHandle) {
            throw new \RuntimeException("Cannot create output file: {$filePath}");
        }

        // Start JSON structure with metadata
        $metadata = [
            'backup_metadata' => [
                'engine' => $this->engineConfig['name'],
                'timestamp' => now()->toISOString(),
                'query' => $query,
                'format' => 'json',
                'batch_size' => $batchSize,
                'fields_excluded' => $this->option('exclude-version') ? ['_version_'] : []
            ]
        ];

        fwrite($fileHandle, json_encode($metadata, JSON_PRETTY_PRINT));
        fwrite($fileHandle, ',"response":{"docs":[');

        $firstDoc = true;
        $cursorMark = '*';
        $totalDocuments = 0;

        do {
            $params = $this->buildQueryParams($query, $batchSize, $cursorMark);
            
            try {
                $response = $this->makeRequest($params);
                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['response']['docs'])) {
                    throw new \RuntimeException('Invalid response format from Solr');
                }

                $docs = $data['response']['docs'];

                foreach ($docs as $doc) {
                    // Remove _version_ field if exclude-version is enabled
                    if ($this->option('exclude-version')) {
                        unset($doc['_version_']);
                    }
                    
                    if (!$firstDoc) {
                        fwrite($fileHandle, ',');
                    }
                    fwrite($fileHandle, json_encode($doc));
                    $firstDoc = false;
                    $totalDocuments++;
                }

                $this->backupStats['documents_processed'] = $totalDocuments;
                $this->backupStats['batches_processed']++;

                // Show progress
                if ($this->backupStats['batches_processed'] % 10 === 0) {
                    $this->line("Documents processed: {$totalDocuments}");
                }

                $nextCursorMark = $data['nextCursorMark'] ?? null;
                $hasMore = $nextCursorMark && $nextCursorMark !== $cursorMark;
                $cursorMark = $nextCursorMark;

            } catch (RequestException $e) {
                $this->backupStats['errors'][] = "Batch request failed: " . $e->getMessage();
                throw $e;
            }

        } while ($hasMore);

        // Close JSON structure
        fwrite($fileHandle, ']}}');
        fclose($fileHandle);

        // Compress if requested
        if ($this->option('compress')) {
            $this->compressFile($filePath);
            $outputFile .= '.gz';
        }

        return $totalDocuments;
    }

    private function executeCsvBackup($outputFile, $query, $batchSize)
    {
        $filePath = storage_path('app/private/' . $outputFile);
        $fileHandle = fopen($filePath, 'w');

        if (!$fileHandle) {
            throw new \RuntimeException("Cannot create output file: {$filePath}");
        }

        $cursorMark = '*';
        $totalDocuments = 0;
        $headersWritten = false;

        do {
            $params = $this->buildQueryParams($query, $batchSize, $cursorMark);
            
            try {
                $response = $this->makeRequest($params);
                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['response']['docs'])) {
                    throw new \RuntimeException('Invalid response format from Solr');
                }

                $docs = $data['response']['docs'];

                foreach ($docs as $doc) {
                    // Remove _version_ field if exclude-version is enabled
                    if ($this->option('exclude-version')) {
                        unset($doc['_version_']);
                    }
                    
                    // Write headers on first document
                    if (!$headersWritten) {
                        $headers = array_keys($this->flattenDocument($doc));
                        fputcsv($fileHandle, $headers);
                        $headersWritten = true;
                    }

                    // Flatten and write document
                    $flatDoc = $this->flattenDocument($doc);
                    $row = array_values($flatDoc);
                    fputcsv($fileHandle, $row);
                    $totalDocuments++;
                }

                $this->backupStats['documents_processed'] = $totalDocuments;
                $this->backupStats['batches_processed']++;

                if ($this->backupStats['batches_processed'] % 10 === 0) {
                    $this->line("Documents processed: {$totalDocuments}");
                }

                $nextCursorMark = $data['nextCursorMark'] ?? null;
                $hasMore = $nextCursorMark && $nextCursorMark !== $cursorMark;
                $cursorMark = $nextCursorMark;

            } catch (RequestException $e) {
                $this->backupStats['errors'][] = "Batch request failed: " . $e->getMessage();
                throw $e;
            }

        } while ($hasMore);

        fclose($fileHandle);

        // Compress if requested
        if ($this->option('compress')) {
            $this->compressFile($filePath);
            $outputFile .= '.gz';
        }

        return $totalDocuments;
    }

    private function executeXmlBackup($outputFile, $query, $batchSize)
    {
        $filePath = storage_path('app/private/' . $outputFile);
        $fileHandle = fopen($filePath, 'w');

        if (!$fileHandle) {
            throw new \RuntimeException("Cannot create output file: {$filePath}");
        }

        // Write XML header and root
        fwrite($fileHandle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fileHandle, '<backup>' . "\n");

        // Write metadata
        $metadata = [
            'engine' => $this->engineConfig['name'],
            'timestamp' => now()->toISOString(),
            'query' => $query,
            'format' => 'xml',
            'batch_size' => $batchSize
        ];

        fwrite($fileHandle, '  <metadata>' . "\n");
        foreach ($metadata as $key => $value) {
            fwrite($fileHandle, "    <{$key}>{$value}</{$key}>\n");
        }
        fwrite($fileHandle, '  </metadata>' . "\n");
        fwrite($fileHandle, '  <documents>' . "\n");

        $cursorMark = '*';
        $totalDocuments = 0;

        do {
            $params = $this->buildQueryParams($query, $batchSize, $cursorMark);
            
            try {
                $response = $this->makeRequest($params);
                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['response']['docs'])) {
                    throw new \RuntimeException('Invalid response format from Solr');
                }

                $docs = $data['response']['docs'];

                foreach ($docs as $doc) {
                    // Remove _version_ field if exclude-version is enabled
                    if ($this->option('exclude-version')) {
                        unset($doc['_version_']);
                    }
                    
                    fwrite($fileHandle, '    <doc>' . "\n");
                    foreach ($doc as $key => $value) {
                        if (is_array($value)) {
                            $value = implode(', ', $value);
                        }
                        $escapedValue = htmlspecialchars((string)$value, ENT_XML1, 'UTF-8');
                        fwrite($fileHandle, "      <{$key}>{$escapedValue}</{$key}>\n");
                    }
                    fwrite($fileHandle, '    </doc>' . "\n");
                    $totalDocuments++;
                }

                $this->backupStats['documents_processed'] = $totalDocuments;
                $this->backupStats['batches_processed']++;

                if ($this->backupStats['batches_processed'] % 10 === 0) {
                    $this->line("Documents processed: {$totalDocuments}");
                }

                $nextCursorMark = $data['nextCursorMark'] ?? null;
                $hasMore = $nextCursorMark && $nextCursorMark !== $cursorMark;
                $cursorMark = $nextCursorMark;

            } catch (RequestException $e) {
                $this->backupStats['errors'][] = "Batch request failed: " . $e->getMessage();
                throw $e;
            }

        } while ($hasMore);

        // Close XML structure
        fwrite($fileHandle, '  </documents>' . "\n");
        fwrite($fileHandle, '</backup>' . "\n");
        fclose($fileHandle);

        // Compress if requested
        if ($this->option('compress')) {
            $this->compressFile($filePath);
            $outputFile .= '.gz';
        }

        return $totalDocuments;
    }

    private function flattenDocument($doc, $prefix = '')
    {
        $flat = [];
        foreach ($doc as $key => $value) {
            $newKey = $prefix ? $prefix . '.' . $key : $key;
            
            if (is_array($value)) {
                if (count($value) === 1 && !is_numeric(key($value))) {
                    $flat = array_merge($flat, $this->flattenDocument($value, $newKey));
                } else {
                    $flat[$newKey] = implode(', ', array_map('strval', $value));
                }
            } else {
                $flat[$newKey] = $value;
            }
        }
        return $flat;
    }

    private function compressFile($filePath)
    {
        $compressedPath = $filePath . '.gz';
        $fileHandle = fopen($filePath, 'rb');
        $compressedHandle = gzopen($compressedPath, 'wb9');

        if (!$fileHandle || !$compressedHandle) {
            throw new \RuntimeException("Cannot create compressed file");
        }

        while (!feof($fileHandle)) {
            $chunk = fread($fileHandle, 1024 * 1024); // 1MB chunks
            gzwrite($compressedHandle, $chunk);
        }

        fclose($fileHandle);
        gzclose($compressedHandle);
        unlink($filePath); // Remove original file
    }

    private function generateBackupMetadata($outputFile, $totalDocuments)
    {
        $endTime = microtime(true);
        $executionTime = round(($endTime - $this->backupStats['start_time']) * 1000, 2);

        $metadata = [
            'backup_file' => $outputFile,
            'engine' => $this->engineConfig['name'],
            'format' => $this->option('format'),
            'query' => $this->option('query'),
            'total_documents' => $totalDocuments,
            'batches_processed' => $this->backupStats['batches_processed'],
            'execution_time_ms' => $executionTime,
            'compressed' => $this->option('compress'),
            'fields_excluded' => $this->option('exclude-version') ? ['_version_'] : [],
            'created_at' => now()->toISOString(),
            'errors' => $this->backupStats['errors']
        ];

        $metadataFile = 'backups/metadata/backup_' . $this->engineConfig['name'] . '_' . now()->format('Ymd_His') . '_meta.json';
        Storage::put($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function displayFinalStatistics($totalDocuments)
    {
        $endTime = microtime(true);
        $executionTime = round(($endTime - $this->backupStats['start_time']) * 1000, 2);
        $format = $this->option('format');
        $outputFile = $this->generateOutputFilename();

        $this->newLine();
        $this->info("✓ Backup completed successfully!");
        $this->info("  Format: {$format}");
        $this->info("  Total documents: {$totalDocuments}");
        $this->info("  Batches processed: {$this->backupStats['batches_processed']}");
        $this->info("  Execution time: {$executionTime}ms");
        
        if ($this->option('compress')) {
            $this->info("  Compressed: Yes");
        }

        if (!empty($this->backupStats['errors'])) {
            $this->warn("  Errors encountered: " . count($this->backupStats['errors']));
        }

        $this->info("  Output file: storage/app/private/{$outputFile}");
    }

    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->daily();
    }
}