<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use function Laravel\Prompts\text;
use function Laravel\Prompts\password;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use Illuminate\Support\Facades\Storage;

class ConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:config-command {--edit= : Edit existing configuration by name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure Solr server connections';

    public function handle()
    {
        $configPath = 'solr_engines.json';
        $allConfigs = Storage::exists($configPath) 
            ? json_decode(Storage::get($configPath), true) 
            : [];

        $editMode = $this->option('edit');
        
        if ($editMode) {
            return $this->editConfig($editMode, $allConfigs, $configPath);
        } else {
            return $this->createOrSelectConfig($allConfigs, $configPath);
        }
    }

    private function editConfig($name, $allConfigs, $configPath)
    {
        if (!isset($allConfigs[$name])) {
            info("Configuration '$name' not found.");
            info("Available configurations: " . implode(', ', array_keys($allConfigs)));
            return 1;
        }

        $existingConfig = $allConfigs[$name];
        
        info("Editing configuration: $name");
        info("Current values:");
        info("  Host: " . $existingConfig['host']);
        info("  Port: " . $existingConfig['port']);
        info("  Core: " . $existingConfig['core']);
        info("  Auth: " . ($existingConfig['auth'] ? 'Yes' : 'No'));

        $host = text("Host de Solr", default: $existingConfig['host'], required: true);
        $port = text("Puerto", default: $existingConfig['port'], required: true);
        $core = text("Core/Collection", default: $existingConfig['core'], required: true);
        
        $auth = $existingConfig['auth'];
        $useAuth = confirm("Use authentication?", default: $auth ? true : false);
        
        if ($useAuth) {
            $user = text("Usuario", default: $auth ? $auth[0] : '');
            $pass = password("Contraseña");
            $auth = $user ? [$user, $pass] : null;
        } else {
            $auth = null;
        }

        $config = [
            'host' => $host,
            'port' => $port,
            'core' => $core,
            'auth' => $auth
        ];

        $allConfigs[$name] = $config;
        Storage::put($configPath, json_encode($allConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        info("Configuration '$name' updated successfully.");
        return 0;
    }

    private function createOrSelectConfig($allConfigs, $configPath)
    {
        if (!empty($allConfigs)) {
            $action = select(
                "What would you like to do?",
                [
                    'create' => 'Create new configuration',
                    'edit' => 'Edit existing configuration',
                    'list' => 'List existing configurations'
                ]
            );

            match ($action) {
                'create' => $this->createNewConfig($allConfigs, $configPath),
                'edit' => $this->selectAndEditConfig($allConfigs, $configPath),
                'list' => $this->listConfigs($allConfigs)
            };
        } else {
            $this->createNewConfig($allConfigs, $configPath);
        }
        
        return 0;
    }

    private function createNewConfig($allConfigs, $configPath)
    {
        $name = text("Nombre de la conexión (ej: local, produccion)", required: true);
        
        if (isset($allConfigs[$name])) {
            if (!confirm("Configuration '$name' already exists. Overwrite?")) {
                info("Operation cancelled.");
                return;
            }
        }

        $host = text("Host de Solr", "http://localhost", required: true);
        $port = text("Puerto", "8983", required: true);
        $core = text("Core/Collection", "gettingstarted", required: true);
        
        $useAuth = confirm("Use authentication?");
        
        if ($useAuth) {
            $user = text("Usuario");
            $pass = password("Contraseña");
            $auth = $user ? [$user, $pass] : null;
        } else {
            $auth = null;
        }

        $config = [
            'host' => $host,
            'port' => $port,
            'core' => $core,
            'auth' => $auth
        ];

        $allConfigs[$name] = $config;
        Storage::put($configPath, json_encode($allConfigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        info("Configuration '$name' saved successfully.");
    }

    private function selectAndEditConfig($allConfigs, $configPath)
    {
        if (empty($allConfigs)) {
            info("No configurations found.");
            return;
        }

        $name = select(
            "Select configuration to edit:",
            array_keys($allConfigs)
        );

        $this->editConfig($name, $allConfigs, $configPath);
    }

    private function listConfigs($allConfigs)
    {
        if (empty($allConfigs)) {
            info("No configurations found.");
            return;
        }

        info("Existing configurations:");
        foreach ($allConfigs as $name => $config) {
            info("  $name:");
            info("    Host: " . $config['host']);
            info("    Port: " . $config['port']);
            info("    Core: " . $config['core']);
            info("    Auth: " . ($config['auth'] ? 'Yes' : 'No'));
            info("");
        }
    }
    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
