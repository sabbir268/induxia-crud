# Induxia CRUD Generator

The Induxia CRUD Generator is a Laravel package designed to simplify the process of generating full CRUD operations for a resource. It generates models, migrations, controllers, views, and database CRUD operations based on a YAML configuration file.

## Installation

1. Install the package via Composer:

    ```bash
    composer require sabbir268/induxia-crud
    ```

2. Publish the package configuration:

    ```bash
    php artisan vendor:publish --provider="Sabbir268\InduxiaCrud\InduxiaCrudServiceProvider"
    ```

## Usage

### Step 1: Generate Initial YAML File

```bash
php artisan make:yaml {ResourceName}

# Example
php artisan make:yaml User

### Step 2: Edit the YAML File

Edit the generated YAML file located in the `resources/yaml` directory.


### Step 3: Generate CRUD

```bash
php artisan make:crud {ResourceName} --yaml=resources/yaml/{ResourceName}.yaml


# License
This package is open-sourced software licensed under the [MIT license](LICENSE.md).
