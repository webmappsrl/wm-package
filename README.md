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

You can publish the config file with:

```bash
php artisan vendor:publish --tag="wm-package-config"
```

This is the contents of the published config file:

```php
return [
    'hoqu_url' => env('HOQU_URL', 'https://hoqu2.webmapp.it')
];
```

## Usage

```php
$wmPackage = new Wm\WmPackage();
echo $wmPackage->echoPhrase('Hello, Wm!');
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

If you need to test the package on full laravel instance clone this repository in the same folder of the laravel dir, then add a new composer path repository in the laravel `composer.json` file:

```json
"repositories": [
        {
            "type": "path",
            "url": "../wm-package"
        }
    ]
```

then you can install the package with `compose require wm/wm-package`

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
    -   `POST /register`
        registra gli utenti fornendo `name`,`email` e `password`. L'accesso a questa api è consentito solo tramite Bearer token con ability `create-users`. Per registrare un nuovo token legato all'utente con `id = 1` utilizzare `php artisan tinker`:
        ```php
        \App\User\find(1)->createToken('artisan-token', ['create-users'])->plainTextToken
        ```
