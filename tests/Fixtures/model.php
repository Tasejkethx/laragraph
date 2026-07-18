<?php

declare(strict_types=1);

namespace Laragraph\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

class WidgetRepo
{
    public function activeWidgets(): mixed
    {
        // Larastan types Widget::query() as Builder<Widget>; ->active() is a
        // local scope → the edge should point at Widget::scopeActive.
        return Widget::query()->active()->get();
    }
}
