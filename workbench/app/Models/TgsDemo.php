<?php

namespace Workbench\App\Models;

use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Concerns\HasCanvasContent;
use ApiMedia\Tgs\Models\Concerns\HasCanvases;
use ApiMedia\Tgs\Models\Contracts\HasCanvasContent as HasCanvasContentContract;
use ApiMedia\Tgs\Models\Contracts\HasCanvases as HasCanvasesContract;
use ApiMedia\Tgs\Support\Concerns\HasProvisionalRecordTrait;
use ApiMedia\Tgs\Support\Concerns\HasTgsHostLabel;
use ApiMedia\Tgs\Support\Contracts\HasProvisionalRecord;
use ApiMedia\Tgs\Support\Contracts\TgsHost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Workbench\App\Filament\Resources\TgsDemoResource;
use Workbench\Database\Factories\TgsDemoFactory;

/**
 * Минимальный demo-хост для TgsCanvas (plan B2 fix): использует ТОЛЬКО
 * HasCanvases — без HasContentBuilderTrait/старого Builder-поля, — так что
 * конфликт save()-хука со старым JSON-конструктором структурно невозможен
 * (см. `docs/superpowers/specs/2026-07-17-tgs-custom-canvas-editor-design.md`).
 *
 * @property int $id
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $provisional_at
 * @property-read Collection<int, Canvas> $canvasRecords
 * @property-read int|null $canvas_records_count
 * @property-read array<int, mixed>|null $content
 * @property-read string|null $text_content
 *
 * @method static \Workbench\Database\Factories\TgsDemoFactory factory($count = null, $state = [])
 * @method static Builder<static>|TgsDemo newModelQuery()
 * @method static Builder<static>|TgsDemo newQuery()
 * @method static Builder<static>|TgsDemo query()
 * @method static Builder<static>|TgsDemo whereCreatedAt($value)
 * @method static Builder<static>|TgsDemo whereId($value)
 * @method static Builder<static>|TgsDemo whereName($value)
 * @method static Builder<static>|TgsDemo whereProvisionalAt($value)
 * @method static Builder<static>|TgsDemo whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class TgsDemo extends Model implements HasCanvasContentContract, HasCanvasesContract, HasProvisionalRecord, TgsHost
{
    use HasCanvasContent;
    use HasCanvases;

    /** @use HasFactory<TgsDemoFactory> */
    use HasFactory;

    use HasProvisionalRecordTrait;
    use HasTgsHostLabel;

    protected $table = 'tgs_demos';

    protected static function newFactory(): TgsDemoFactory
    {
        return TgsDemoFactory::new();
    }

    protected $fillable = [
        'name',
    ];

    public static function tgsHostKey(): string
    {
        return 'tgs_demo';
    }

    public function tgsEditUrl(): ?string
    {
        return TgsDemoResource::getUrl('edit', ['record' => $this]);
    }

    /** @return list<string> */
    public static function canvasGroups(): array
    {
        return ['content'];
    }
}
