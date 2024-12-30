<?php

namespace Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Wm\WmPackage\Models\User as ModelsUser;

/**
 * Undocumented class
 * @phpstan-require-extends Wm\WmPackage\Models\User
 */
class User extends ModelsUser {}
