<?php

namespace Wm\WmPackage\Http\Controllers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppClassificationService;
use Workbench\App\Models\User;

class RankingController extends Controller
{
    public function showTopTen(App $app)
    {
        $topTen = AppClassificationService::make()->getRankedUsersNearPois($app);

        return view('wm-package::top-ten', ['topTen' => $topTen, 'app' => $app]);
    }

    public function showUserRanking(App $app, User $user)
    {

        $rankings = AppClassificationService::make()->getRankedUsersNearPois($app);
        $userIds = array_keys($rankings);

        // Find the position of the user
        $position = array_search($user->id, $userIds);

        // Get the three users before and three users after
        $start = max(0, $position - 3);
        $end = min(count($userIds) - 1, $position + 3);
        $subset = array_slice($rankings, $start, $end - $start + 1, true);

        return view('wm-package::user-ranking', [
            'rankings' => $subset,
            'position' => $position + 1, // Convert to 1-based index
            'userId' => $user->id,
            'app' => $app,
        ]);
    }
}
