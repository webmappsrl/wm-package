<?php

namespace Tests\Unit\Observers;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
abstract class AbstractUgcObserverTest extends TestCase
{
    use DatabaseTransactions;
    protected User $authUser;
    protected User $webmappUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->authUser = $this->createUserWithoutFactory([
            'name' => 'Auth User',
            'email' => 'auth@example.com',
        ]);
        $this->webmappUser = $this->createUserWithoutFactory([
            'name' => 'Webmapp User',
            'email' => 'team@webmapp.it',
        ]);
    }

    protected function createUserWithoutFactory(array $attributes = []): User
    {
        $user = new User();
        $user->fill(array_merge($attributes, [
            'password' => 'password'
        ]));
        $user->saveQuietly();
        
        return $user;
    }
}

class TestModel extends Model
{
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}