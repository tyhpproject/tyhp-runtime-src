<!-- tyhp-readme:start -->
# tyhp/rhetoric

Laravel database access for Tyhp and for PHP. A model is a plain class. A `Context` is the unit of work. `save()` writes the tracked changes in one transaction. Queries are expression trees, translated to SQL.

PHP 8.3–8.5. Require the artifact for the PHP you run (`803`, `804`, or `805`):

```bash
composer require tyhp/rhetoric:805.0.*
```

That also installs `tyhp/core` and `tyhp/lambda`, plus Laravel 13 (`illuminate/database`, `illuminate/support`, `illuminate/console`). The service provider is `Tyhp\Rhetoric\Laravel\RhetoricServiceProvider`.

Guides: [Tyhp](https://tyhplang.com/rhetoric_getting_started.html) and [PHP](https://tyhplang.com/rhetoric_php_getting_started.html).

## Tyhp

```tyhp
<?tyhp

namespace App\Data;

use Tyhp\Rhetoric\Attributes\MaxLength;
use Tyhp\Rhetoric\Attributes\Table;
use Tyhp\Rhetoric\Attributes\Timestamps;
use Tyhp\Rhetoric\Context;
use Tyhp\Rhetoric\Mapping\Mapper;
use Tyhp\Rhetoric\OnDelete;
use Tyhp\Rhetoric\Table as ModelTable;

#[Timestamps]
class Chirp {
    public int $id;
    public int $userId;
    #[MaxLength(255)]
    public string $message;
    public ?Author $user = null;
}

#[Table('users')]
class Author {
    public int $id;
    public string $name;
    public string $email;
}

class ChirpContext extends Context {
    public ModelTable<Chirp> $chirps { get => $this->table<Chirp>(); }

    protected function map(Mapper $map): void {
        $map->model<Chirp>()
            ->belongsTo(fn ($chirp) => $chirp->user)
            ->foreignKey(fn ($chirp) => $chirp->userId)
            ->onDelete(OnDelete::Cascade);
    }
}
```

```tyhp
$db = app(ChirpContext::class);
$feed = $db->chirps
    ->with(fn ($chirp) => $chirp->user)
    ->latest()
    ->take(50)
    ->all();
```

`all()` returns `array<T>`. `get()` returns `Collection<int, T>`. `where` takes a lambda (`fn ($chirp) => $chirp->message->startsWith('Hi')`). `first()` returns `?T`. `save()` returns the number of rows written. `rhetoric:migration`, `rhetoric:scaffold`, and `rhetoric:auto-migrate` are the Artisan commands. Register `Sql` functions with `MethodTranslatorRegistry::shared()->registerFunction`.

## PHP

This section stands on its own. Models and the context are the same classes. Generic methods are bound with `\Tyhp\Generic::bind` or `\_gnrc`. A `Table` is a method on your context. A collection relation names its model with `#[\Tyhp\PropertyType('array<App\\Data\\Chirp>')]`. Predicates are `\Tyhp\Expression::of`.

```php
namespace App\Data;

final class ChirpContext extends \Tyhp\Rhetoric\Context
{
    public function chirps(): \Tyhp\Rhetoric\Table
    {
        return (\Tyhp\Generic::bind($this->table(...), \Tyhp\Type::fromClassName(Chirp::class)))();
    }

    protected function map(\Tyhp\Rhetoric\Mapping\Mapper $map): void
    {
        $model = \Tyhp\Generic::bind($map->model(...), \Tyhp\Type::fromClassName(Chirp::class))();
        $model
            ->belongsTo(\Tyhp\Expression::of(Chirp::class)->user)
            ->foreignKey(\Tyhp\Expression::of(Chirp::class)->userId);
    }
}

$feed = $db->chirps()
    ->where(\Tyhp\Expression::of(\App\Data\Chirp::class)->userId->same($userId))
    ->with(\Tyhp\Expression::of(\App\Data\Chirp::class)->user)
    ->latest()
    ->take(50)
    ->all();
```

`\_gnrc(...)` is the root function with the same signature as `\Tyhp\Generic::bind`. `\Tyhp\Type::int()`, `::string()`, `::fromClassName`, `::nullable`, `::union`, `::generic`, `::struct`, `::parse`, and `\Tyhp\NamedType` are the type factories. A class bind is `\Tyhp\Generic::bind(\Vendor\Box::class, \Tyhp\Type::int())->new(42)` or `(42)`. String methods use `\Tyhp\Expr::param($name, \App\Data\Chirp::class)->message->call('startsWith', 'Hi')`.

`EloquentQuery::query` binds the model, then takes no arguments. `on` binds the model and takes the connection:

```php
$query = \Tyhp\Generic::bind(
    \Tyhp\Rhetoric\EloquentQuery::query(...),
    \Tyhp\Type::fromClassName(\App\Models\Article::class),
)();
$onMysql = \Tyhp\Generic::bind(
    \Tyhp\Rhetoric\EloquentQuery::on(...),
    \Tyhp\Type::fromClassName(\App\Models\Article::class),
)('mysql');
```
<!-- tyhp-readme:end -->
