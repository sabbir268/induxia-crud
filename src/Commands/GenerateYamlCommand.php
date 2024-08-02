<?php

namespace Sabbir268\InduxiaCurd\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class GenerateYamlCommand extends Command
{
    protected $signature = 'make:yaml {name}';
    protected $description = 'Generate an initial YAML file for a resource';

    public function handle()
    {
        $name = $this->argument('name');
        $config = [
            'title' => ucfirst($name),
            'columns' => [
                'name' => [
                    'type' => 'string',
                    'input_type' => 'text',
                    'validation' => 'required|string|max:255'
                ],
                // Add more columns as needed
            ]
        ];

        $yaml = Yaml::dump($config, 4);

        if (!file_exists(base_path("database/yaml"))) {
            mkdir(base_path("database/yaml"));
        }

        $filePath = base_path("database/yaml/{$name}.yml");
        file_put_contents($filePath, $yaml);


        $this->info("YAML file created at: {$filePath}");
    }
}
