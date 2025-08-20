<?php

namespace Wm\WmPackage\Nova\Metrics;

use App\Models\User as AppUser;
use App\Nova\User as UserResource;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\MetricTableRow;
use Laravel\Nova\Metrics\Table;
use Laravel\Nova\Nova;

class TopUgcCreators extends Table
{
    /**
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected string $ugcModelClass;

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $ugcModelClass
     */
    public function __construct(string $ugcModelClass)
    {
        parent::__construct();
        $this->ugcModelClass = $ugcModelClass;
    }

    /**
     * Calculate the value of the metric.
     *
     * @return array<int, \Laravel\Nova\Metrics\MetricTableRow>
     */
    public function calculate(NovaRequest $request): array
    {
        $ugcTable = (new $this->ugcModelClass)->getTable();
        $userTable = (new AppUser)->getTable();

        $bestUserIds = DB::table("{$ugcTable}")
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('user_id');

        // passo 2: join solo per quei 5 utenti
        $topUsers = DB::table("{$ugcTable} as ugc")
            ->select(
                'ugc.user_id',
                DB::raw('COUNT(*) as total'),
                'u.name',
                'u.email'
            )
            ->join("{$userTable} as u", 'u.id', '=', 'ugc.user_id')
            ->whereIn('ugc.user_id', $bestUserIds)
            ->groupBy('ugc.user_id', 'u.name', 'u.email')
            ->orderByDesc('total')
            ->get();

        if ($topUsers->isEmpty()) {
            return [];
        }

        $userResourceUri = UserResource::uriKey();
        $baseUrl = url(Nova::path()."/resources/{$userResourceUri}");

        $rows = [];
        foreach ($topUsers->values() as $index => $row) {
            $rank = $index + 1;
            $iconClass = match ($rank) {
                1 => 'text-yellow-500',
                2 => 'text-gray-400',
                3 => 'text-yellow-600',
                default => 'none',
            };

            $title = ($row->name).($row->email ? ' ('.$row->email.') ' : '').' - '.$row->total.' Ugc';

            $rows[] = MetricTableRow::make()
                ->icon($iconClass == 'none' ? 'minus' : 'trophy')
                ->iconClass($iconClass)
                ->title($title)
                ->actions(function () use ($baseUrl, $row) {
                    return [
                        [
                            'name' => __('Open User Details: :name', ['name' => $row->name]),
                            'path' => "{$baseUrl}/{$row->user_id}",
                            'external' => true,
                            'target' => '_blank',
                            'method' => 'GET',
                        ],
                    ];
                });
        }

        return $rows;
    }

    /**
     * Determine the amount of time the results of the metric should be cached.
     */
    public function cacheFor(): ?DateTimeInterface
    {
        return null;
    }
}
