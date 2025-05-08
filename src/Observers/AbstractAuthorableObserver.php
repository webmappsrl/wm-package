<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

abstract class AbstractAuthorableObserver extends AbstractObserver
{
    /**
     * Handle the Model "created" event.
     *
     * @return void
     */
    public function created(Model $model)
    {
        $user = $this->determineUser($model);
        $this->assignUserToModel($model, $user);
    }

    /**
     * Determine the user to associate with the model
     */
    protected function determineUser(Model $model): ?User
    {
        // Try to get authenticated user first
        $user = auth()->user();

        // If no authenticated user and model has app_id, try to get user from the app
        if (! $user && ! empty($model->app_id)) {
            $app = App::find($model->app_id);

            if ($app && isset($app->user_id)) {
                $user = User::find($app->user_id);
            } else {
                // Fallback to webmapp team user
                $user = User::where('email', '=', 'team@webmapp.it')->first();
            }
        }

        return $user;
    }

    /**
     * Assign the user to the model
     */
    protected function assignUserToModel(Model $model, ?User $user): void
    {
        if (method_exists($model, 'author')) {
            $model->author()->associate($user);
        } elseif (property_exists($model, 'user_id') || isset($model->user_id)) {
            $model->updateQuietly(['user_id' => $user->id]);
        }
    }
}
