<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\UgcMedia;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Model;

class UgcMediaObserver extends AbstractObserver
{
    /**
     * Handle the UcgMedia "creating" event.
     *
     * @return void
     */
    public function creating(Model $ugcMedia)
    {
        parent::creating($ugcMedia);
        $app = App::where('id', $ugcMedia->app_id)->first();
        if ($app && $app->classification_show) {
            $ugcMedia->beforeCount = count($app->getRankedUsersNearPoisQuery($ugcMedia->user_id));
        }
    }

    /**
     * Handle the UcgMedia "created" event.
     *
     * @return void
     */
    public function created(UgcMedia $ugcMedia)
    {
        $app = App::where('id', $ugcMedia->app_id)->first();
        if ($app && $app->classification_show) {
            $afterCount = count($app->getRankedUsersNearPoisQuery($ugcMedia->user_id));
            if ($afterCount > $ugcMedia->beforeCount) {
                $user = User::find($ugcMedia->user_id);
                if (! is_null($user)) {
                    $position = $app->getRankedUserPositionNearPoisQuery($user->id);
                    Mail::send('wm-package::mails.gamification.rankingIncreased', ['user' => $user, 'position' => $position, 'app' => $app], function ($message) use ($user, $app) {
                        $message->to($user->email);
                        $message->subject($app->name . ': Your Ranking Has Increased');
                    });
                }
            }
        }
    }
}
