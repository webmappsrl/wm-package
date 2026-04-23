<?php

namespace Wm\WmPackage\Nova\Fields\OrderList\src\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Events\OrderListReorderedEvent;
use Wm\WmPackage\Nova\Fields\OrderList\src\OrderList;

class OrderListController extends Controller
{
    public function reorder(
        Request $request,
        string $model,
        string $scopeColumn,
        string $scopeValue,
        string $orderColumn
    ): JsonResponse {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        /** @var array<int> $ids */
        $ids = array_values($payload['ids']);

        $modelClass = OrderList::decodeModel($model);
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            abort(400, 'Invalid model');
        }

        if ($scopeColumn === '_' || $scopeValue === '_') {
            abort(400, 'OrderList reorder requires a concrete scope');
        }

        // Autorizzazione opzionale (passata nel signed URL).
        $authorizeAbility = $request->query('authorizeAbility');
        $authorizeModel = $request->query('authorizeModel');
        $authorizeId = $request->query('authorizeId');
        if (is_string($authorizeAbility) && is_string($authorizeModel) && is_string($authorizeId)) {
            $authModelClass = OrderList::decodeModel($authorizeModel);
            if (class_exists($authModelClass) && is_subclass_of($authModelClass, Model::class)) {
                /** @var Model|null $authModel */
                $authModel = $authModelClass::query()->find($authorizeId);
                if ($authModel) {
                    Gate::authorize($authorizeAbility, $authModel);
                }
            }
        }

        DB::transaction(function () use ($modelClass, $scopeColumn, $scopeValue, $orderColumn, $ids) {
            $existingIds = $modelClass::query()
                ->where($scopeColumn, $scopeValue)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            sort($existingIds);
            $expected = $ids;
            sort($expected);

            if ($existingIds !== $expected) {
                abort(422, 'Invalid ids for this scope');
            }

            foreach ($ids as $idx => $id) {
                $modelClass::query()
                    ->where('id', $id)
                    ->where($scopeColumn, $scopeValue)
                    ->update([$orderColumn => $idx + 1]);
            }
        });

        Event::dispatch(new OrderListReorderedEvent(
            modelClass: $modelClass,
            scopeColumn: $scopeColumn,
            scopeValue: $scopeValue,
            orderColumn: $orderColumn,
            ids: $ids
        ));

        return response()->json(['ok' => true]);
    }
}

