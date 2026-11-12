<?php

use ApiMedia\Tgs\Data\BlockCollectionData;
use ApiMedia\Tgs\Data\UnknownBlockData;
use Illuminate\Contracts\Debug\ExceptionHandler;

it('falls back to UnknownBlockData for unregistered types, preserving data', function () {
    $blocks = BlockCollectionData::fromStored(
        [['type' => 'legacy_widget', 'data' => ['id' => 'g1', 'x' => 1]]],
        fakeModel(),
    );

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toBeInstanceOf(UnknownBlockData::class)
        ->and($blocks[0]->type)->toBe('legacy_widget')
        ->and($blocks[0]->data)->toBe(['id' => 'g1', 'x' => 1]);
});

it('skips malformed entries that are not builder blocks', function () {
    $blocks = BlockCollectionData::fromStored(['not-a-block', ['no_type' => true]], fakeModel());
    expect($blocks)->toBeEmpty();
});

it('reports skipped malformed entries for observability, but not an empty blocks array', function () {
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')
        ->twice()
        ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'skipped a malformed block entry'));
    app()->instance(ExceptionHandler::class, $handler);

    BlockCollectionData::fromStored(['not-a-block', ['no_type' => true]], fakeModel());

    // A legitimately empty blocks array has nothing to skip — no report expected.
    $emptyHandler = Mockery::mock(ExceptionHandler::class);
    $emptyHandler->shouldNotReceive('report');
    app()->instance(ExceptionHandler::class, $emptyHandler);

    BlockCollectionData::fromStored([], fakeModel());
});

// Deferred from Task 7: proves risk #13 — a registered block whose fromStored() throws must
// degrade to UnknownBlockData instead of 500ing the whole page. Column is registered; its
// fromStored() calls BlockCollectionData::fromStored($raw['content'] ?? [], $model), whose
// $rawBlocks parameter is typed `array`. Feeding a string where an array is expected is a genuine
// TypeError at the PHP type-coercion boundary — not a contrived mock — and BlockCollectionData's
// try/catch(Throwable) must catch it.
// (Picture and Gallery moved off the raw image/images-map shape in the Final cleanup — see
// PictureRawResolutionTest / GalleryRawResolutionTest for their own fromStored robustness coverage.)
it('degrades a registered block whose fromStored throws (TypeError) to UnknownBlockData', function () {
    $blocks = BlockCollectionData::fromStored(
        [['type' => 'column', 'data' => ['id' => 'c1', 'content' => 'NOT_AN_ARRAY']]],
        fakeModel(),
    );

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toBeInstanceOf(UnknownBlockData::class)
        ->and($blocks[0]->type)->toBe('column');
});
