<?php

namespace Wm\WmPackage\Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Points the "geohub" DB connection at the same PDO as the default connection, so test data
 * inserted through the default connection is visible to GeohubImportService (which always
 * reads via the geohub connection) without a second real database. DatabaseTransactions only
 * wraps the default connection in a transaction; sharing the same PDO is what lets the geohub
 * connection see that transaction's uncommitted rows.
 */
trait SharesGeohubConnectionWithLocal
{
    protected function shareGeohubConnectionWithLocal(): void
    {
        $default = config('database.default');
        config(['database.connections.geohub' => config("database.connections.{$default}")]);
        DB::purge('geohub');

        $defaultConn = DB::connection($default);
        $geohubConn = DB::connection('geohub');
        $geohubConn->setPdo($defaultConn->getPdo());
        $geohubConn->setReadPdo($defaultConn->getReadPdo());
    }
}
