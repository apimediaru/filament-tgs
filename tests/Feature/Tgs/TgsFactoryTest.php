<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Blocks\DoubleColumn;
use ApiMedia\Tgs\Blocks\Gallery;
use ApiMedia\Tgs\Blocks\Picture;
use ApiMedia\Tgs\Blocks\TabItem;
use ApiMedia\Tgs\Blocks\Tabs;
use ApiMedia\Tgs\Data\Blocks\PictureData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockMedia;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Tests\Fixtures\SeedArticle;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class TgsFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_default_factory_and_empty_recipes_leave_both_canvases_empty(): void
    {
        $articles = [
            SeedArticle::factory()->create(),
            SeedArticle::factory()->withTgs('summary')->withTgs('content', Content::seed()->count(0))->create(),
        ];
        foreach ($articles as $article) {
            $this->assertSame(['content', 'summary'], $article->canvasRecords()->orderBy('group')->pluck('group')->all());
            $this->assertNull($article->getContent('content'));
            $this->assertNull($article->getContent('summary'));
        }
        $this->assertCount(0, SeedArticle::factory()->count(0)->withTgs('content', Content::seed())->create());
        $this->assertSame(0, Block::count());
    }

    #[Test]
    public function a_factory_fills_independent_canvases_in_call_order_for_each_article(): void
    {
        $number = 0;
        $paragraph = BlockSpec::of(Content::blockKey(), function () use (&$number): array {
            return ['content' => 'Текст '.++$number];
        });

        $factory = SeedArticle::factory()
            ->withTgs('summary', Content::seed('Кратко'))
            ->withTgs('content', $paragraph->count(2))
            ->withTgs('content', Content::seed('Конец'))
            ->count(5);

        $this->assertCount(5, $factory->make());
        $this->assertSame(0, $number);
        $this->assertSame(0, SeedArticle::count());
        $this->assertSame(0, Block::count());

        $articles = $factory->create();

        $ids = [];
        foreach ($articles as $index => $article) {
            $summary = $article->getContent('summary');
            $content = $article->getContent('content');
            $this->assertSame(['Кратко'], array_column($summary, 'content'));
            $this->assertSame(['Текст '.($index * 2 + 1), 'Текст '.($index * 2 + 2), 'Конец'], array_column($content, 'content'));
            $ids = [...$ids, ...array_column([...$summary, ...$content], 'id')];
        }
        $this->assertCount(20, array_unique($ids));
    }

    #[Test]
    public function nested_specs_use_the_registered_descriptors_and_appear_in_the_public_tree(): void
    {
        app(TgsBlockRegistry::class)->blocks([SeedCounter::class]);

        $article = SeedArticle::factory()->withTgs('content',
            Tabs::seed(TabItem::seed('Обзор', DoubleColumn::seed(
                left: [Content::seed('Слева'), Content::seed('Ещё')],
                right: SeedCounter::seed(initial: 7),
            ))),
        )->create(['name' => 'Две колонки']);

        $column = json_decode(json_encode($article->getContent('content'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)[0]['items'][0]['content'][0];
        $this->assertSame('Две колонки', $article->name);
        $this->assertSame('double_column', $column['type']);
        $this->assertSame(['Слева', 'Ещё'], array_column($column['left'], 'content'));
        $this->assertSame('counter', $column['right'][0]['type']);
        $this->assertSame(7, $column['right'][0]['count']);
        $this->assertNull($article->getContent('summary'));
    }

    #[Test]
    #[TestWith(['unknown_group'])]
    #[TestWith(['unknown_slot'])]
    #[TestWith(['unknown_type'])]
    #[TestWith(['forbidden_host'])]
    #[TestWith(['disabled'])]
    #[TestWith(['invalid_child'])]
    #[TestWith(['raw_children'])]
    public function an_invalid_recipe_is_rejected_before_writing_any_canvas(string $scenario): void
    {
        $registry = app(TgsBlockRegistry::class)->blocks([RestrictedSeedCounter::class]);
        Event::fake([TgsContentChanged::class]);

        [$group, $spec, $error] = match ($scenario) {
            'unknown_group' => ['typo', Content::seed(), 'Unknown canvas group'],
            'unknown_slot' => ['content', BlockSpec::of('double_column', slots: ['lef' => Content::seed()]), 'lef'],
            'unknown_type' => ['content', BlockSpec::of('unknown_key'), 'unknown_key'],
            'forbidden_host' => ['content', DoubleColumn::seed(right: SeedCounter::seed()), 'counter'],
            'disabled' => ['content', Content::seed(), 'content'],
            'invalid_child' => ['content', BlockSpec::of('tabs', slots: ['items' => Content::seed()]), 'content'],
            'raw_children' => ['content', BlockSpec::of('double_column', ['lef' => [['type' => 'content', 'data' => []]]]), 'slots'],
            default => $this->fail("Unknown scenario [{$scenario}]."),
        };
        if ($scenario === 'disabled') {
            $registry->withoutBlocks(['content']);
        }

        try {
            SeedArticle::factory()
                ->withTgs('summary', Content::seed('Первый канвас'))
                ->withTgs($group, $spec)
                ->create();
            $this->fail('The recipe should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($error, $exception->getMessage());
        }
        Event::assertNotDispatched(TgsContentChanged::class);
        $this->assertSame(0, SeedArticle::count());
        $this->assertSame(0, Block::count());
    }

    #[Test]
    public function nested_media_use_the_block_disk_and_are_independent_between_articles(): void
    {
        Storage::fake('public');
        config(['filesystems.disks.seed-banners' => ['driver' => 'local']]);
        Storage::fake('seed-banners');
        app(TgsBlockRegistry::class)->blocks([SeedBanner::class]);
        $first = $this->image(100, 65);
        $second = $this->image(80, 60);

        try {
            $articles = SeedArticle::factory()->count(2)->withTgs('content',
                SeedBanner::seed(image: $second, title: 'Акция'),
                DoubleColumn::seed(right: Gallery::seed($first, $second)),
            )->create();

            $tokens = [];
            foreach ($articles as $article) {
                [$banner, $column] = json_decode(json_encode($article->getContent('content'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('seed_banner', $banner['type']);
                $this->assertSame('Акция', $banner['title']);
                $this->assertSame(80, $banner['image']['original']['width']);
                $gallery = $column['right'][0];
                $this->assertSame([100, 80], array_column(array_column($gallery['images'], 'original'), 'width'));

                $bannerBlock = Block::findOrFail($banner['id']);
                $galleryBlock = Block::findOrFail($gallery['id']);
                $this->assertSame('seed-banners', $bannerBlock->media->sole()->disk);
                $this->assertSame([1, 2], $galleryBlock->media->pluck('order_column')->all());
                $tokens = [...$tokens, $bannerBlock->data['uuid'], $galleryBlock->data['uuid']];
            }
            $this->assertCount(4, array_unique($tokens));
            $this->assertFileExists($first);
            $this->assertFileExists($second);
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    #[Test]
    public function a_failure_after_copying_the_second_file_rolls_back_only_that_article(): void
    {
        Storage::fake('public');
        config(['media-library.prefix' => '/media']);
        $file = $this->image();

        // The fifth article's second file has reached the disk when the event is dispatched.
        $files = 0;
        Event::listen(MediaHasBeenAddedEvent::class, function () use (&$files): void {
            if (++$files === 10) {
                throw new RuntimeException('second file failed');
            }
        });

        $factory = SeedArticle::factory()
            ->sequence(['name' => 'Первая'], ['name' => 'Вторая'], ['name' => 'Третья'], ['name' => 'Четвёртая'], ['name' => 'Пятая'])
            ->count(6)
            ->withTgs('summary', Content::seed('Кратко'))
            ->withTgs('content', Gallery::seed($file, $file));
        try {
            $factory->create();
            $this->fail('The second file should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('second file failed', $exception->getMessage());
        } finally {
            unlink($file);
        }

        $this->assertSame(['Первая', 'Вторая', 'Третья', 'Четвёртая'], SeedArticle::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(8, Canvas::query()->where('host_type', (new SeedArticle)->getMorphClass())->count());
        $this->assertSame(8, Block::count());
        $this->assertSame(8, BlockPlacement::count());
        $this->assertSame(8, BlockMedia::count());
        $expectedFiles = BlockMedia::all()->map(fn (BlockMedia $media): string => ltrim($media->getPathRelativeToRoot(), '/'))->all();
        $originals = array_values(array_filter(Storage::disk('public')->allFiles(), fn (string $path): bool => ! str_contains($path, '/conversions/')));
        $this->assertEqualsCanonicalizing($expectedFiles, $originals);
        $this->assertCount(40, Storage::disk('public')->allFiles());
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function file_cleanup_follows_the_actual_database_outcome(bool $committed): void
    {
        Storage::fake('public');
        $file = $this->image();

        try {
            DB::transaction(function () use ($committed, $file): void {
                SeedArticle::factory()->withTgs('content', Picture::seed($file))->create();
                if ($committed) {
                    DB::afterCommit(fn () => throw new RuntimeException('after commit'));
                } else {
                    throw new RuntimeException('outer rollback');
                }
            });
            $this->fail('The transaction should have propagated the exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($committed ? 'after commit' : 'outer rollback', $exception->getMessage());
        } finally {
            unlink($file);
        }

        $this->assertSame((int) $committed, SeedArticle::count());
        $this->assertSame((int) $committed, BlockMedia::count());
        $this->assertCount($committed ? 5 : 0, Storage::disk('public')->allFiles());
    }

    private function image(int $width = 16, int $height = 16): string
    {
        $image = UploadedFile::fake()->image('source.png', $width, $height);
        $source = $image->getPathname();
        $file = $source.'.png';
        copy($source, $file);

        return $file;
    }
}

class SeedCounter extends TgsBlock
{
    public static function blockType(): string
    {
        return 'counter';
    }

    public static function dataClass(): string
    {
        return SeedCounterData::class;
    }

    public static function seed(int $initial = 0): BlockSpec
    {
        return BlockSpec::of(static::blockKey(), ['count' => $initial]);
    }
}

class SeedCounterData extends Data implements ResolvesFromStored
{
    public string $type = 'counter';

    public function __construct(public int $count) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static($raw['count']);
    }
}

class RestrictedSeedCounter extends SeedCounter
{
    public static function allowedHosts(): array
    {
        return ['news_only'];
    }
}

class SeedBanner extends TgsBlock
{
    public static function blockType(): string
    {
        return 'seed_banner';
    }

    public static function dataClass(): string
    {
        return SeedBannerData::class;
    }

    public static function seed(?string $image = null, ?string $title = null): BlockSpec
    {
        return BlockSpec::of(static::blockKey(), ['title' => $title], files: $image === null ? [] : [$image]);
    }

    public static function contentMediaDisk(): ?string
    {
        return 'seed-banners';
    }
}

class SeedBannerData extends PictureData
{
    public ContentBlockType|string $type = 'seed_banner';
}
