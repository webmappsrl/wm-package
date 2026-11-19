<?php

namespace Tests\Unit\Services\StorageService;

use RuntimeException;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

/**
 * Il nome dello shard entra in ogni percorso su wmfe, e senza non si scrive niente.
 *
 * Il bucket e' lo stesso per tutti gli shard (`config/wm-filesystems.php`, scritto fisso): il
 * prefisso e' l'unica cosa che tiene separati i loro file. Un valore di riserva farebbe scrivere
 * uno shard senza nome dentro quelli di un altro, in silenzio.
 */
class ShardBasePathTest extends TestCase
{
    public function test_shard_base_path_uses_the_configured_name(): void
    {
        config(['wm-package.shard_name' => 'forestas']);

        $this->assertSame('/forestas/', (new StorageService)->getShardBasePath());
        $this->assertSame('/forestas/3/', (new StorageService)->getShardBasePath(3));
    }

    public function test_shard_base_path_fails_loudly_when_the_shard_name_is_missing(): void
    {
        // `SHARD_NAME` e `APP_NAME` assenti lasciano la chiave a null, non assente: il valore di
        // riserva di `config()` non scatta, e prima il tipo di ritorno faceva saltare il job con
        // un errore che della causa non diceva niente.
        config(['wm-package.shard_name' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHARD_NAME');

        (new StorageService)->getShardBasePath();
    }

    public function test_shard_base_path_does_not_accept_a_blank_name(): void
    {
        config(['wm-package.shard_name' => '   ']);

        $this->expectException(RuntimeException::class);

        (new StorageService)->getShardBasePath();
    }
}
