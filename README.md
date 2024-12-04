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

Available migrations are:

-   `create_jobs_table`, for default laravel job with an extra column
-   `create_hoqu_caller_jobs`, HoquCallerJob model table, necessary for processor/caller instances

You can publish the config file with :

```bash
php artisan vendor:publish --tag="wm-package-config"
```

This is the contents of the published config file :

```php
return [
    'hoqu_url' => env('HOQU_URL', 'https://hoqu2.webmapp.it'),
    'hoqu_register_username' => env('HOQU_REGISTER_USERNAME'),
    'hoqu_register_password' => env('HOQU_REGISTER_PASSWORD ')
];
```

## Usage

```php
use Wm\WmPackage\Facades\HoquClient;
/** Start store call to hoqu (1)**/
HoquClient::store(['name' => 'test','input' => '{ ... }' ]);
...
/** It logins (to retrieve a token) as an user that can create processors/callers on hoqu **/
HoquClient::registerLogin()
...
/** Register a new processor/caller on hoqu **/
HoquClient::register()

```

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

`./vendor/bin/testbench migrate`
It migrates workbench tables on a sqlite database located in a vendor folder. Phpunit will use this database as tests memory support.

`./vendor/bin/pest`
To run tests.

If an evaluation of testbench env I suggest to use the `config()` function (eg: `config('database.connections.sqlite')`) with the testbench implementation of tinker `./vendor/bin/testbench tinker`, it is also useful to understand which things are loaded on the testbench env.

Testbench reference: https://packages.tools/testbench.html
Workbench reference: https://packages.tools/workbench.html

Also a simple php docker container is available to run tests, you can start it using `docker compose up -d` and enter inside with `docker compose exec -it php bash` or directly `docker compose exec -it php ./vendor/bin/pest` (check permissions on files before run it, if you have problems use the `-u` param on exec command with the id of the user who owns project files and directories, to check your current user id you can use the command `id`).

## Pushing

We use git flow to add features to this repo. Please create a new feature then push it and ask a pull request via github interface from your feature branch to develop.

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

-   `hoqu:register-user`. Create a new user on Hoqu instance based on credetials provided in `.env` file. Options:
    -   `--R|role`: required, the role of this instance: "caller" , "processor" or "caller,processor"
    -   `--endpoint=false` : the endpoint of this instance, default is `APP_URL` in .env file
-   `hoqu:store`. Performs a call to Hoqu to store a job, saves a new `HoquCallerModel` in the database with the hoqu response. Options:
    -   `--class` : required, the class that will execute job on processor}
    -   `--featureId` : required, the feature id to update after completed job}
    -   `--field` : required, the field to update after completed job}
    -   `--input` : required, the input to send to processor}
-   `db:upload_db_aws`. Uploads the given sql file and the last-dump of the database to AWS. only from production Arguments:
    -   `dumpname?` : the name of the sql zip file to upload
-   `db:download`. download a dump.sql from server in storage/app/database folder. Has no arguments:

## JWT

JWT verrà installato automaticamente come dipendenza. Gli utenti dovranno solo:

1. Eseguire `composer require wm/wm-package`
2. Pubblicare la configurazione JWT con `php artisan vendor:publish --tag="wm-package-jwt-config"`
3. Configurare le variabili d'ambiente JWT nel file .env utilizzando il comando `php artisan jwt:secret`

Il pacchetto JWT sarà gestito come dipendenza del wm-package invece che dover essere installato separatamente nell'applicazione principale.
