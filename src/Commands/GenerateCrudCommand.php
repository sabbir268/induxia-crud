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

        $multilangColumns = collect($config['columns'])->filter(fn($col) => $col['is_multilang'] ?? false);

        $this->generateModel($name, $config);
        $this->generateMigration($name, $config);



        if ($multilangColumns->isNotEmpty()) {
            $this->generateTranslationModelAndMigration($name, $config);
            $resource = strtolower(Str::snake($name));
            $this->generateMultilangView($resource, $multilangColumns->toArray());
            $this->generateRequest($resource, $config);
            $this->generateController($name, $config, true);
        }else{
            $this->generateController($name, $config);
        }



        $this->generateViews($name, $config);
        $this->generateRoutes($name);

        $this->info("CRUD for {$name} generated successfully.");
    }

    protected function generateModel($name, $config)
    {
        $modelName = ucfirst($name);
        $modelPath = app_path("Models/{$modelName}.php");

        $fillable = [];
        $relationships = [];
        $fileCasts = [];
        $translatedAttributes = [];

        foreach ($config['columns'] as $column => $details) {
            if ($column == 'id') continue;
            if (isset($details['fillable']) && $details['fillable'] == false) continue;

            if (!isset($details['is_multilang']) || !$details['is_multilang']) {
                $fillable[] = "'{$column}'";
            } else {
                $translatedAttributes[] = "'{$column}'";
            }

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
                }
            }
        }

        $fillableString = implode(', ', $fillable);
        $translatedString = implode(', ', $translatedAttributes);
        $relationshipsString = implode("\n\n", $relationships);

        $useTraits = "use HasFactory, SoftDeletes";
        $useTraits .= $translatedAttributes ? ", Translatable, SaveTranslations, ModelHelper, SaveSlugTranslations" : "";

        $implements = $translatedAttributes ? " implements TranslatableContract" : "";

        $traitsUse = $translatedAttributes ? "use HasFactory, SoftDeletes, Translatable, SaveTranslations, ModelHelper, SaveSlugTranslations;" : "use HasFactory, SoftDeletes;";

        $namespaceUses = [
            "use Illuminate\\Database\\Eloquent\\Model;",
            "use Illuminate\\Database\\Eloquent\\SoftDeletes;",
            "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;"
        ];

        if ($translatedAttributes) {
            $namespaceUses[] = "use App\\Helpers\\Traits\\ModelHelper;";
            $namespaceUses[] = "use App\\Helpers\\Traits\\SaveSlugTranslations;";
            $namespaceUses[] = "use App\\Induxia\\Traits\\SaveTranslations;";
            $namespaceUses[] = "use Astrotomic\\Translatable\\Translatable;";
            $namespaceUses[] = "use Astrotomic\\Translatable\\Contracts\\Translatable as TranslatableContract;";
        }

        $namespaceUsesString = implode("\n", $namespaceUses);
        $fileCastsString = implode(",\n        ", $fileCasts);

        $translatedAttributesString = $translatedAttributes ? "\n    protected \$translatedAttributes = [{$translatedString}];" : "";

        $modelContent = <<<PHP
<?php

namespace App\Models;

{$namespaceUsesString}

class {$modelName} extends Model{$implements}
{
    {$traitsUse}

    protected \$fillable = [{$fillableString}];

    protected \$casts = [
        {$fileCastsString}
    ];

    {$translatedAttributesString}

    {$relationshipsString}
}
PHP;

        file_put_contents($modelPath, $modelContent);

        $this->info("Model created successfully at: {$modelPath}");
    }

     protected function generateRequest($name, $config)
    {
        $className = ucfirst($name) . 'Request';
        $filePath = app_path("Http/Requests/{$className}.php");

        $rules = [];
        foreach ($config['columns'] as $column => $details) {
            $rule = $details['validation'] ?? 'nullable';
            if (!empty($details['is_multilang'])) {
                $rules[] = "'%{$column}%' => '{$rule}'";
            } else {
                $rules[] = "'{$column}' => '{$rule}'";
            }
        }

        $rulesString = implode(",\n            ", $rules);

        $template = <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Astrotomic\Translatable\Validation\RuleFactory;

class {$className} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        \$rules = [
            {$rulesString}
        ];

        return RuleFactory::make(\$rules);
    }
}
PHP;


        file_put_contents($filePath, $template);
        $this->info("Request file created: {$filePath}");
    }



    protected function generateTranslationModelAndMigration($name, $config)
    {
        $translationClass = ucfirst($name) . 'Translation';
        $translationTable = strtolower($name) . '_translations';
        $modelPath = app_path("Models/{$translationClass}.php");
        $migrationPath = database_path('migrations') . '/' . date('Y_m_d_His') . "_create_{$translationTable}_table.php";

        $translatableColumns = array_filter($config['columns'], fn($col) => isset($col['is_multilang']) && $col['is_multilang']);

        $fillable = implode(",\n        ", array_map(fn($field) => "'" . $field . "'", array_keys($translatableColumns)));
        $fillable = "'locale',\n        {$fillable}";

        $modelContent = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$translationClass} extends Model
{
    protected \$fillable = [
        {$fillable}
    ];
}
PHP;

        file_put_contents($modelPath, $modelContent);
        $this->info("Translation model created: {$modelPath}");

        $columns = "\$table->id();\n            \$table->foreignId('" . strtolower($name) . "_id')->constrained('" . Str::plural(strtolower($name)) . "')->onDelete('cascade');\n            \$table->string('locale')->index();\n";
        foreach ($translatableColumns as $col => $info) {
            $columns .= "            \$table->string('{$col}')->nullable();\n";
        }
        $columns .= "            \$table->timestamps();\n            \$table->softDeletes();";

        $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$translationTable}', function (Blueprint \$table) {
            {$columns}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$translationTable}');
    }
};
PHP;

        file_put_contents($migrationPath, $migrationContent);
        $this->info("Translation migration created: {$migrationPath}");
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

    protected function generateController($name, $config, $multilang = false)
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


        if($multilang){
            $controllerStub = file_get_contents(__DIR__ . '/../Stubs/translateablecontroller.stub');
        }else{
            $controllerStub = file_get_contents(__DIR__ . '/../Stubs/controller.stub');
        }
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

        if (!file_exists(resource_path('views/pages'))) {
            mkdir(resource_path('views/pages'), 0755, true);
        }
        $path = resource_path("views/pages/{$namePath}");

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $multilangColumns = collect($config['columns'])->filter(fn($col) => $col['is_multilang'] ?? false);
        $regularColumns = collect($config['columns'])->reject(fn($col) => $col['is_multilang'] ?? false)->toArray();

        // Generate _form.blade.php dynamically
        $formStub = '';
        if ($multilangColumns->isNotEmpty()) {
            $formStub .= "@include('pages.{$namePath}.data')\n";
        }
        $formStub .= $this->generateFormStub($name, ['columns' => $regularColumns]);
        file_put_contents("{$path}/_form.blade.php", $formStub);

        foreach ($views as $view) {
            $viewContent = file_get_contents(__DIR__ . "/../Stubs/views/{$view}.blade.php.stub");
            $viewContent = str_replace('{{ $name }}', ucfirst($name), $viewContent);
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

    protected function generateMultilangView($resource, $columns)
    {
        $path = resource_path("views/pages/{$resource}");
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $content = "@include('misc.form-lang-tabs')\n<div class=\"tab-content\">\n";
        $content .= "@foreach (config('app.available_locales') as \$k => \$locale)\n";
        $content .= "    <div class=\"tab-pane {{ \$k == session('lang_tab', 'fr') ? 'active' : '' }}\" id=\"data-conatainer-{{ \$k }}\">\n";
        $content .= "        <div class=\"row mt-3\">\n";

        foreach ($columns as $field => $info) {
            $label = ucfirst(str_replace('_', ' ', $field)) . ' ({{ \$k }})';
            $fieldName = $field . ":{{ \$k }}";
            $inputClass = "{{ \$errors->has('{$field}') ? 'is-invalid' : '' }}";
            $error = "{!! showErr('{$field}', \$k) !!}";
            $value = "{{ inputValue(\$data, '{$field}', \$k) }}";

            switch ($info['input_type']) {
                case 'textarea':
                    $content .= <<<HTML
<div class="col-md-6">
    <div class="form-group">
        <label for="{$field}">{$label}</label>
        <textarea class="form-control editor-minimal {$inputClass}" id="{$field}-{{ \$k }}" name="{$fieldName}">{$value}</textarea>
        {$error}
    </div>
</div>
HTML;
                    break;

                default:
                    $content .= <<<HTML
<div class="col-md-6">
    <div class="form-group">
        <label for="{$field}">{$label}</label>
        <input type="text" class="form-control {$inputClass}" id="{$field}-{{ \$k }}" name="{$fieldName}" value="{$value}">
        {$error}
    </div>
</div>
HTML;
                    break;
            }
        }

        $content .= "        </div>\n    </div>\n@endforeach\n</div>\n";

        file_put_contents("{$path}/data.blade.php", $content);
        $this->info("Multilang view generated at: {$path}/data.blade.php");
    }
}
