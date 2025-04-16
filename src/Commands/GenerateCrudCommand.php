<?php

namespace Sabbir268\InduxiaCurd\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\Artisan;

class GenerateCrudCommand extends Command
{
    protected $signature = 'make:crud {name} {--yml= : Path to the YAML file}';
    protected $description = 'Generate a full CRUD for a resource based on a YAML file';

    public function handle()
    {
        $name = $this->argument('name');
        $ymlPath = $this->option('yml');

        if (!file_exists($ymlPath)) {
            $this->error("YAML file not found: {$ymlPath}");
            return;
        }

        $config = Yaml::parseFile($ymlPath);

        // Generate Model and Migration
        $this->generateModel($name, $config);
        $this->generateMigration($name, $config);

        // Generate Controller
        $this->generateController($name, $config);

        // Generate Views
        $this->generateViews($name, $config);

        // Generate Routes
        $this->generateRoutes($name);

        $this->info("CRUD for {$name} generated successfully.");
    }

    // src/Commands/GenerateCrudCommand.php

    protected function generateModel($name, $config)
    {
        $modelName = ucfirst($name);
        $modelPath = app_path("Models/{$modelName}.php");

        $fillable = [];
        $relationships = [];

        $fileCasts = [];

        foreach ($config['columns'] as $column => $details) {
            // ignore id
            if ($column == 'id') {
                continue;
            }

            if (isset($details['fillable']) && $details['fillable'] == false) {
                continue;
            }
            $fillable[] = "'{$column}'";


            if ($details['input_type'] == 'file') {
                $fileCasts[] = "'{$column}' => 'Filecast'";
            }

            if (isset($details['relation'])) {
                $relation = $details['relation'];
                $relationType = $relation['type'];
                $relatedModel = $relation['model'];
                $foreignKey = $relation['foreign_key'] ?? null;
                $localKey = $relation['local_key'] ?? null;

                $methodName = lcfirst($relatedModel);
                $relatedModelName = ucfirst($relatedModel);

                switch ($relationType) {
                    case 'hasOne':
                        $relationships[] = <<<PHP
    public function {$methodName}()
    {
        return \$this->hasOne({$relatedModelName}::class);
    }
PHP;
                        break;

                    case 'hasMany':
                        $relationships[] = <<<PHP
    public function {$methodName}()
    {
        return \$this->hasMany({$relatedModelName}::class);
    }
PHP;
                        break;

                    case 'belongsTo':
                        $relationships[] = <<<PHP
    public function {$methodName}()
    {
        return \$this->belongsTo({$relatedModelName}::class);
    }
PHP;
                        break;

                    case 'belongsToMany':
                        $pivotTable = $relation['pivot_table'];
                        $relationships[] = <<<PHP
    public function {$methodName}()
    {
        return \$this->belongsToMany({$relatedModelName}::class, '{$pivotTable}', '{$foreignKey}', '{$localKey}');
    }
PHP;
                        break;

                        // Add more relationship types as needed
                }
            }
        }

        $fillableString = implode(', ', $fillable);
        $relationshipsString = implode("\n\n", $relationships);

        $modelStub = file_get_contents(__DIR__ . '/../Stubs/model.stub');
        $modelStub = str_replace(
            ['{{ modelName }}', '{{ fillable }}', '{{ relationships }}', '{{ fileCasts }}'],
            [$modelName, $fillableString, $relationshipsString, implode(', ', $fileCasts)],
            $modelStub
        );

        file_put_contents($modelPath, $modelStub);

        $this->info("Model created successfully at: {$modelPath}");
    }


    protected function generateMigration($name, $config)
    {
        $table = strtolower(Str::plural($name));
        $migrationName = 'create_' . $table . '_table';
        $migrationPath = database_path('migrations') . '/' . date('Y_m_d_His') . "_{$migrationName}.php";

        $columns = '';
        foreach ($config['columns'] as $column => $details) {
            $type = $details['type'];
            $nullable = isset($details['nullable']) && $details['nullable'] ? '->nullable()' : '';
            $default = isset($details['default']) ? "->default('{$details['default']}')" : '';
            $columns .= "\$table->{$type}('{$column}'){$nullable}{$default};\n\t\t\t";
        }

        $migrationStub = file_get_contents(__DIR__ . '/../Stubs/migration.stub');
        $migrationStub = str_replace(
            ['{{ table }}', '{{ columns }}'],
            [$table, $columns],
            $migrationStub
        );

        file_put_contents($migrationPath, $migrationStub);

        $this->info("Migration created successfully at: {$migrationPath}");
    }

    protected function generateController($name, $config)
    {
        $controllerName = ucfirst($name) . 'Controller';
        $controllerPath = app_path("Http/Controllers/{$controllerName}.php");
        $modelName = ucfirst($name);
        $modelVariable = lcfirst($name);
        $modelVariablePlural = Str::plural($modelVariable);
        $modelNamePlural = Str::plural($modelName);

        // Generate validation rules
        $validationRules = [];
        foreach ($config['columns'] as $column => $details) {
            if (isset($details['validation'])) {
                $validationRules[] = "'$column' => '{$details['validation']}'";
            }
        }
        $validationRulesString = implode(",\n            ", $validationRules);

        // Generate table columns
        $tableColumns = [];
        $tableModifyData = [];
        foreach ($config['columns'] as $column => $details) {
            $label = isset($details['label']) ? $details['label'] : ucfirst($column);
            if (!isset($details['show_at_table']) || $details['show_at_table'] !== false) {
                if ($details['input_type'] == 'file' && strpos($column, 'image') !== false) {
                    $tableColumns[] = "'_image' => '" . $label . "'";
                    $tableModifyData[] = "->modifyData('_image', function (\$record) {
                        return '<img src=\"' . \$record->image->url('100') . '\" alt=\"' . \$record->title . '\" class=\"img-fluid\" width=\"100\" height=\"100\">';
                    })";
                } else if ($details['input_type'] == 'file') {
                    $tableColumns[] = "'_$column' => '" . $label . "'";
                    $tableModifyData[] = "->modifyData('_$column', function (\$record) {
                        return '<a href=\"#\" target=\"_blank\">View</a>';
                    })";
                } else if ($details['type'] == 'boolean') {
                    $tableColumns[] = "'_$column' => '" . $label . "'";

                    $tableModifyData[] = "->modifyData('_$column', function (\$record) {
                        return '<input type=\"checkbox\" class=\"js-switch status-change\" data-url=\"#\" data-id=\"' . \$record->id . '\" data-model=\"project\" data-color=\"#009efb\"' . (\$record->$column ? 'checked' : '') . ' />';
                    })";
                } else {
                    $tableColumns[] = "'$column' => '" . $label . "'";
                }
            }
        }
        $tableColumnsString = implode(",\n            ", $tableColumns);


        $controllerStub = file_get_contents(__DIR__ . '/../Stubs/controller.stub');
        $controllerStub = str_replace(
            [
                '{{ controllerName }}',
                '{{ modelName }}',
                '{{ modelVariable }}',
                '{{ modelVariablePlural }}',
                '{{ modelNamePlural }}',
                '{{ validationRules }}',
                '{{ tableColumns }}',
                '{{ tableModifyData }}'
            ],
            [
                $controllerName,
                $modelName,
                $modelVariable,
                $modelVariablePlural,
                $modelNamePlural,
                $validationRulesString,
                $tableColumnsString,
                implode("\n\t\t\t\t\t", $tableModifyData)
            ],
            $controllerStub
        );

        file_put_contents($controllerPath, $controllerStub);

        $this->info("Controller created successfully at: {$controllerPath}");
    }






    protected function generateRoutes($name)
    {
        $path = 'App\Http\Controllers\\' . ucfirst($name) . 'Controller::class';
        $name = strtolower(Str::snake($name));
        $route = "Route::resource('{$name}', $path); \n";
        file_put_contents(base_path('routes/web.php'), $route, FILE_APPEND);
    }



    protected function generateViews($name, $config)
    {
        $views = ['create', 'edit', 'index'];
        $namePath = strtolower(Str::snake($name));
        // check if pages directory exists
        if (!file_exists(resource_path('views/pages'))) {
            mkdir(resource_path('views/pages'), 0755, true);
        }
        $path = resource_path("views/pages/{$namePath}");

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        // Generate _form.blade.php dynamically
        $formStub = $this->generateFormStub($name, $config);
        file_put_contents("{$path}/_form.blade.php", $formStub);

        // Generate create, edit, and index views
        foreach ($views as $view) {
            $viewContent = file_get_contents(__DIR__ . "/../Stubs/views/{$view}.blade.php.stub");
            $viewContent = str_replace('{{ $name }}', ucfirst($name), $viewContent);
            // replace {{ strtolower($name) }}
            $viewContent = str_replace('{{ strtolower($name) }}', $namePath, $viewContent);

            file_put_contents("{$path}/{$view}.blade.php", $viewContent);
        }
    }



    protected function generateFormStub($name, $config)
    {
        $form = '<div class="row">';
        foreach ($config['columns'] as $column => $details) {
            $label = isset($details['label']) ? $details['label'] : ucfirst($column);
            $inputType = $details['input_type'];
            $validationClass = "{{ \$errors->has('{$column}') ? 'is-invalid' : '' }}";
            $value = "{{ inputValue(\$data, '{$column}') }}";
            $error = "{!! showErr('{$column}') !!}";

            switch ($inputType) {
                case 'text':
                case 'email':
                case 'number':
                case 'password':
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="{$column}">{$label}</label>
                        <input type="{$inputType}" class="form-control {$validationClass}"
                               id="{$column}" name="{$column}"
                               value="{$value}">
                        {$error}
                    </div>
                </div>
HTML;
                    break;

                case 'textarea':
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="{$column}">{$label}</label>
                        <textarea class="form-control {$validationClass}"
                                  id="{$column}" name="{$column}">{$value}</textarea>
                        {$error}
                    </div>
                </div>
HTML;
                    break;

                case 'select':
                    $options = '';
                    if (isset($details['options'])) {
                        foreach ($details['options'] as $option) {
                            $options .= "<option value=\"{$option}\">{$option}</option>";
                        }
                    }
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="{$column}">{$label}</label>
                        <select class="form-control {$validationClass}" id="{$column}" name="{$column}">
                            <option value="">Select {$label}</option>
                            {$options}
                        </select>
                        {$error}
                    </div>
                </div>
HTML;
                    break;

                case 'checkbox':
                    $checked = "{{ inputValue(\$data, '{$column}') ? 'checked' : '' }}";
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input {$validationClass}"
                                   id="{$column}" name="{$column}" {$checked}>
                            <label class="form-check-label" for="{$column}">{$label}</label>
                        </div>
                        {$error}
                    </div>
                </div>
HTML;
                    break;

                case 'radio':
                    $options = '';
                    foreach ($details['options'] as $option) {
                        $checked = "{{ inputValue(\$data, '{$column}') == '{$option}' ? 'checked' : '' }}";
                        $options .= <<<HTML
                    <div class="form-check">
                        <input class="form-check-input {$validationClass}" type="radio"
                               id="{$column}_{$option}" name="{$column}" value="{$option}" {$checked}>
                        <label class="form-check-label" for="{$column}_{$option}">{$option}</label>
                    </div>
HTML;
                    }
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <label>{$label}</label>
                        {$options}
                        {$error}
                    </div>
                </div>
HTML;
                    break;

                case 'file':
                    $form .= <<<HTML
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="{$column}">{$label}</label>
                                <input type="file" class="form-control {$validationClass}" id="{$column}"
                                       name="{$column}" placeholder="Enter {$label}">
                                @if (\$errors->has('{$column}'))
                                    <span class="text-danger">{{ \$errors->first('{$column}') }}</span>
                                @endif
                            </div>
                            @if (isset(\$data?->{$column}) && \$data?->{$column}?->exists)
                                <div class="pb-2 mb-4 border-bottom">
                                    <button type="button" class="btn btn-sm btn-danger mb-2 delete-img" data-id="{{ \$data->id }}"
                                            data-model="{$name}" data-field="{$column}">
                                        <i class="fa fa-trash"></i> {{ __('pages.Delete') }}
                                    </button>
                                    <br>
                                    <img src="{{ \$data?->{$column}?->url }}" alt="{{ \$data?->{$column}->name }}"
                                         style="height:250px;max-width: 100%;">
                                </div>
                            @endif
                        </div>
        HTML;
                    break;
                    // Add more cases for other input types if needed

                default:
                    $form .= <<<HTML
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="{$column}">{$label}</label>
                        <input type="text" class="form-control {$validationClass}"
                               id="{$column}" name="{$column}"
                               value="{$value}">
                        {$error}
                    </div>
                </div>
HTML;
                    break;
            }
        }
        $form .= '</div>';
        return $form;
    }
}
