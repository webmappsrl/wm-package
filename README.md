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

## Update

You can update the package via composer:

```bash
composer update wm/wm-package
```

## Testing

```bash
composer test
```

## Developing

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

## Testing

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

We use git flow to add features to this repo. Please create a new feature then push it and ask a pull request via github interface from your feature branch to develop.

Run `./vendor/bin/phpstan` before push to evaluate errors

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Docs

https://github.com/spatie/laravel-package-tools

https://laravel.com/docs/9.x/facades#facades-vs-dependency-injection

https://pestphp.com/

### Default private/public routes (powered by [Sanctum](https://laravel.com/docs/9.x/sanctum) )

-   public routes
    -   `POST /login` :
        consente la login tramite parametri `email` e `password` in formato `x-www-form-urlencoded`
-   private routes
    -   `POST /logout`
        consente la logout tramite Bearer token
    -   `GET /user`
        restituisce i dettagli dell'utente loggato tramite Bearer token

### Artisan commands

-   `db:upload_db_aws`. Uploads the given sql file and the last-dump of the database to AWS. only from production Arguments:
    -   `dumpname?` : the name of the sql zip file to upload
-   `db:download`. download a dump.sql from server in storage/app/database folder. Has no arguments:

## JWT

JWT verrà installato automaticamente come dipendenza. Gli utenti dovranno solo:

1. Eseguire `composer require wm/wm-package`
2. Configurare le variabili d'ambiente JWT nel file .env utilizzando il comando `php artisan jwt:secret`

Il pacchetto JWT sarà gestito come dipendenza del wm-package invece che dover essere installato separatamente nell'applicazione principale.

## Elasticsearch

https://laravel.com/docs/11.x/scout
https://github.com/matchish/laravel-scout-elasticsearch

## Refactor Notes

Horizon needs 1GB memory and infinite time execution due pbf generation

Molti jobs fallivano silenziosamente a causa dei try catch all'interno, nel catch solo un log

JIDO è sempre utilizzato? va fatta pulizia nel caso nel modello App e AppConfigService

## Indicazioni per sviluppatori

### Elasticsearch

Vedi sezione eleasticsearch di questo documento

### Metodi controllers:

https://laravel.com/docs/10.x/controllers#resource-controllers
https://laravel.com/docs/10.x/controllers#actions-handled-by-resource-controllers
