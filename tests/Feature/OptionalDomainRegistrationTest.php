<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Services\FeaturesService;

class OptionalDomainRegistrationTest extends TestCase
{
    public function test_existing_commands_are_registered_regardless_of_domains(): void
    {
        config(['wm-package.features' => []]);

        $commands = Artisan::all();

        $this->assertArrayHasKey('wm-package:publish-missing-migrations', $commands);
        $this->assertArrayHasKey('wm-package:publish-migration', $commands);
    }

    /**
     * Nova::resourcesIn() scandisce src/Nova in modo ricorsivo e registra tutto
     * cio' che ci trova, a prescindere dall'interruttore del dominio. Una
     * risorsa Nova di un dominio collocata li' sarebbe visibile a ogni consumer,
     * che e' esattamente il difetto che questo meccanismo esiste per evitare.
     *
     * Questo test e' il presidio del vincolo: fallisce se qualcuno crea
     * src/Nova/<Dominio>/, invece di lasciare che il problema si manifesti nel
     * menu Nova di un altro cliente.
     */
    public function test_no_declared_domain_has_a_folder_under_src_nova(): void
    {
        $novaDir = base_path('wm-package/src/Nova');

        foreach (FeaturesService::declaredDomains() as $domain) {
            foreach ([$domain, Str::studly($domain)] as $candidate) {
                $this->assertDirectoryDoesNotExist(
                    $novaDir.'/'.$candidate,
                    "Le risorse Nova del dominio \"{$domain}\" non possono stare in src/Nova: "
                    .'sarebbero registrate anche a dominio spento. Dichiararle in '
                    ."config('wm-package.features.{$domain}.nova_resources') e collocarle altrove.",
                );
            }
        }
    }

    public function test_disabled_domain_registers_neither_commands_nor_nova_resources(): void
    {
        config(['wm-package.features' => [
            'fake_domain' => [
                'enabled' => false,
                'commands' => ['Wm\WmPackage\Commands\WmPackageCommand'],
                'nova_resources' => ['Wm\WmPackage\Nova\App'],
            ],
        ]]);

        $this->assertFalse(FeaturesService::isEnabled('fake_domain'));
        $this->assertSame([], FeaturesService::enabledDomains());
    }
}
