# Webmapp Laravel wm-package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/wm/wm-package.svg?style=flat-square)](https://packagist.org/packages/wm/wm-package)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/wm/wm-package/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/wm/wm-package/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/wm/wm-package/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/wm/wm-package/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/wm/wm-package.svg?style=flat-square)](https://packagist.org/packages/wm/wm-package)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require wm/wm-package
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="wm-package-migrations"
php artisan migrate
```

You can publish the config file with :

```bash
php artisan vendor:publish --tag="wm-package-config"
```

## Usage

You can use Services with:

ServiceClass::make()->method()
eg: `GeometryComputationService::make()->convertToPoint($model)`

You can use Models with or without extending them:
`class MyEcTrackModel extends Wm\WmPackage\Models\EcTrack {...}`

You can use Nova resources extending them:
`class MyEcTrackNovaResource extends Wm\WmPackage\Nova\EcTrack {...}`

You can use all package APIs with related controllers, see them with `php artisan route:list`

You can use all clients with dependency injection or with other instanciation methods:
`app( Wm\WmPackage\Http\Clients\DemClient::class)->getTechData($geojson);`

## Update

You can update the package via composer:

```bash
composer update wm/wm-package
```

or updating it as submodule

## Developing

To use docker containers you need to run `docker compose up -d` and enter inside with `docker compose exec -it php bash` or directly `docker compose exec -it php composer test` (check permissions on files before run it, if you have problems use the `-u` param on exec command with the id of the user who owns project files and directories, to check your current user id you can use the command `id`).

Docker has the following containers:

-   php
-   postgres
-   redis
-   elasticsearch

You can use them with testbench to run a complete Laravel instance with this package (see testing section for more details). Eg, you can use tesbench to run artisan commands:
`./vendor/bin/testbench migrate`

We use conventional commits for commit's messages (https://www.conventionalcommits.org/en/v1.0.0/). Create a feature/fix branch from main then ask a PR to merge it into develop branch.

### On a laravel instance

If you need this package on full laravel instance you have to add this repository as submodule in the root path of Laravel with `git submodule add {git repo}`, then add a new composer path repository in the laravel `composer.json` file:

```json
"repositories": [
        {
            "type": "path",
            "url": "./wm-package"
        }
    ]
```

at last you can install the package with `compose require wm/wm-package`

## JWT

JWT verrà installato automaticamente come dipendenza. Gli utenti dovranno solo configurare le variabili d'ambiente JWT nel file .env utilizzando il comando `php artisan jwt:secret`

Il pacchetto JWT sarà gestito come dipendenza del wm-package invece che dover essere installato separatamente nell'applicazione principale.

## Elasticsearch

https://laravel.com/docs/11.x/scout
https://github.com/matchish/laravel-scout-elasticsearch

elasticsearch mapping and settings:
config/wm-elasticsearch.php

elasticsearch controller:
src/Http/Controllers/Api/ElasticsearchController.php

## Testing

```bash
composer test
```

These tools are used to test the stand alone instance of wm-package: https://packages.tools/

Execute these commands to runs tests:

`./vendor/bin/testbench vendor:publish --tag="wm-package-migrations"`
`./vendor/bin/testbench migrate`
It migrates workbench tables on a postgres database.

`composer test`
To run tests.

If an evaluation of testbench env I suggest to use the `config()` function (eg: `config('database.connections')`) with the testbench implementation of tinker `./vendor/bin/testbench tinker`, it is also useful to understand which things are loaded on the testbench env.

Testbench reference: https://packages.tools/testbench.html
Workbench reference: https://packages.tools/workbench.html

Also a simple php docker container is available to run tests, you can start it using `docker compose up -d` and enter inside with `docker compose exec -it php bash` or directly `docker compose exec -it php composer test` (check permissions on files before run it, if you have problems use the `-u` param on exec command with the id of the user who owns project files and directories, to check your current user id you can use the command `id`).

## Pushing

We use convetional commits (https://www.conventionalcommits.org/en/v1.0.0/) to add commits to this repo. Please create a new branch then push it and ask a pull request via github interface from your feature/fix branch to develop.

Run `./vendor/bin/phpstan` before push to evaluate phpstan suggestions

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Docs

https://github.com/spatie/laravel-package-tools

https://laravel.com/docs/9.x/facades#facades-vs-dependency-injection

https://pestphp.com/

https://packages.tools
