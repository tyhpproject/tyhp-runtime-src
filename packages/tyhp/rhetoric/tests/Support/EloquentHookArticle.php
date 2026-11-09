<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric\Support;

use Illuminate\Database\Eloquent\Model;

final class EloquentHookArticle extends Model
{
    protected $table = 'articles';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
    ];

    public string $title {
        get {
            $value = $this->getAttribute('title');
            if (!\is_string($value)) {
                throw new \TypeError('Attribute title is not a string.');
            }

            return $value;
        }
        set(string $value) {
            $this->setAttribute('title', $value);
        }
    }

    public int $rating {
        get {
            $value = $this->getAttribute('rating');
            if (!\is_int($value)) {
                throw new \TypeError('Attribute rating is not an int.');
            }

            return $value;
        }
        set(int $value) {
            $this->setAttribute('rating', $value);
        }
    }
}
