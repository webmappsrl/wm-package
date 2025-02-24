<?php

namespace Tests\Feature;

use Wm\WmPackage\Tests\TestCase;

class WmFileSystemTest extends TestCase
{
    const DEFAULT_FILESYSTEMS = ['local', 'public'];
    const PACKAGE_FILESYSTEMS = [
        'backups',
        'importer',
        'mapping',
        'pois',
        'conf',
        'osm2cai',
        'importer-osfmedia',
        's3',
        'wmdumps',
        'wmfe',
        's3-osfmedia',
    ];

    /** @test */
    public function wm_package_filesystems_are_registered()
    {
        $disks = config('filesystems.disks');

        // Controlla che i filesystem del package siano registrati
        foreach (self::PACKAGE_FILESYSTEMS as $fs) {
            $this->assertArrayHasKey($fs, $disks, "Il filesystem '{$fs}' non è stato registrato.");
        }

        // Controlla che i filesystem standard dell'applicazione non siano stati sovrascritti
        foreach (self::DEFAULT_FILESYSTEMS as $fs) {
            $this->assertArrayHasKey($fs, $disks, "Il filesystem standard '{$fs}' è stato sovrascritto.");
        }
    }
}
