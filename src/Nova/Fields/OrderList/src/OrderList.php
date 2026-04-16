<?php

namespace Wm\WmPackage\Nova\Fields\OrderList\src;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;

class OrderList extends Field
{
    public $component = 'order-list';

    protected ?string $modelClass = null;

    protected ?string $scopeColumn = null;

    /** @var int|string|callable|null */
    protected mixed $scopeValue = null;

    /** @var (callable(mixed):Builder)|null */
    protected mixed $queryCallback = null;

    protected string $orderColumn = 'rank';

    protected string $labelColumn = 'name';

    /** @var (callable(Model):?string)|null */
    protected mixed $colorResolver = null;

    protected ?string $authorizeAbility = null;

    protected ?string $authorizeModelClass = null;

    /** @var int|string|callable|null */
    protected mixed $authorizeModelId = null;

    public function model(string $modelClass): static
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    /**
     * Query custom per determinare gli item (override di scope()).
     *
     * @param  callable(mixed):Builder  $callback
     */
    public function query(callable $callback): static
    {
        $this->queryCallback = $callback;

        return $this;
    }

    public function scope(string $column, int|string|callable $value): static
    {
        $this->scopeColumn = $column;
        $this->scopeValue = $value;

        return $this;
    }

    public function orderColumn(string $column): static
    {
        $this->orderColumn = $column;

        return $this;
    }

    public function labelColumn(string $column): static
    {
        $this->labelColumn = $column;

        return $this;
    }

    /**
     * Resolver opzionale per il colore mostrato accanto al label.
     * La callback riceve il model e deve ritornare una stringa CSS valida
     * (tipicamente hex `#RRGGBB`) oppure `null` per nessun colore.
     *
     * @param  callable(Model):?string  $resolver
     */
    public function color(callable $resolver): static
    {
        $this->colorResolver = $resolver;

        return $this;
    }

    public function gate(string $ability, Model|callable $model): static
    {
        $this->authorizeAbility = $ability;
        if ($model instanceof Model) {
            $this->authorizeModelClass = $model::class;
            $this->authorizeModelId = $model->getKey();
        } else {
            // callable(mixed $resource): Model|scalar
            $this->authorizeModelClass = null;
            $this->authorizeModelId = $model;
        }

        return $this;
    }

    public function resolve($resource, ?string $attribute = null): void
    {
        parent::resolve($resource, $attribute);

        if (! $this->modelClass) {
            return;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass;

        $builder = null;
        $scopeValue = null;

        if (is_callable($this->queryCallback)) {
            /** @var Builder $builder */
            $builder = call_user_func($this->queryCallback, $resource);
        } else {
            if (! $this->scopeColumn || is_null($this->scopeValue)) {
                return;
            }

            $scopeValue = is_callable($this->scopeValue)
                ? (string) call_user_func($this->scopeValue, $resource)
                : (string) $this->scopeValue;

            $builder = $modelClass::query()->where($this->scopeColumn, $scopeValue);
        }

        /** @var Collection<int, array{id:int,label:string,color?:string}> $items */
        $items = $builder
            ->orderBy($this->orderColumn)
            ->orderBy('id')
            ->get()
            ->map(function (Model $m) {
                $label = data_get($m, $this->labelColumn);

                $item = [
                    'id' => (int) $m->getKey(),
                    'label' => is_null($label) ? ('#'.$m->getKey()) : (string) $label,
                ];

                if (is_callable($this->colorResolver)) {
                    $color = call_user_func($this->colorResolver, $m);
                    if (is_string($color) && trim($color) !== '') {
                        $item['color'] = trim($color);
                    }
                }

                return $item;
            });

        $authorizeModelClass = $this->authorizeModelClass;
        $authorizeModelId = $this->authorizeModelId;

        if (is_callable($authorizeModelId)) {
            $resolvedAuth = call_user_func($authorizeModelId, $resource);
            if ($resolvedAuth instanceof Model) {
                $authorizeModelClass = $resolvedAuth::class;
                $authorizeModelId = $resolvedAuth->getKey();
            } else {
                $authorizeModelId = $resolvedAuth;
            }
        }

        $reorderUrl = URL::signedRoute('order-list.reorder', [
            'model' => self::encodeModel($this->modelClass),
            'scopeColumn' => $this->scopeColumn ?? '_',
            'scopeValue' => $scopeValue ?? '_',
            'orderColumn' => $this->orderColumn,
            'authorizeAbility' => $this->authorizeAbility,
            'authorizeModel' => $authorizeModelClass ? self::encodeModel($authorizeModelClass) : null,
            'authorizeId' => is_null($authorizeModelId) ? null : (string) $authorizeModelId,
        ]);

        $this->withMeta([
            'items' => $items->values()->all(),
            'reorderUrl' => $reorderUrl,
        ]);
    }

    private static function encodeModel(string $modelClass): string
    {
        // URL-safe base64, senza padding.
        $b64 = base64_encode($modelClass);

        return rtrim(strtr($b64, '+/', '-_'), '=');
    }

    public static function decodeModel(string $encoded): string
    {
        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);
        if (! is_string($decoded) || $decoded === '') {
            abort(400, 'Invalid model');
        }

        // Extra hardening: evita caratteri strani.
        if (! Str::of($decoded)->match('/^[A-Za-z0-9_\\\\]+$/')->toString()) {
            abort(400, 'Invalid model');
        }

        return $decoded;
    }
}

