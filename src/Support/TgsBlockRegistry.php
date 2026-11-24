<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Blocks;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use BackedEnum;
use InvalidArgumentException;
use ReflectionClass;

use function array_key_exists;
use function array_values;
use function is_a;
use function is_subclass_of;
use function preg_match;

/** One registry per request/Octane scope. Hidden types remain resolvable. */
final class TgsBlockRegistry
{
    /** @var array<string, class-string<TgsBlock>> */
    private array $blocks = [];

    /** @var array<string, class-string<ResolvesFromStored>|null> */
    private array $baseDtos = [];

    /** @var array<string, bool> */
    private array $structural = [];

    /** @var array<string, true> */
    private array $hidden = [];

    /** @var array<string, TgsCategory> */
    private array $categories = [];

    /** @var array<string, true> */
    private array $hiddenCategories = [];

    public function __construct()
    {
        $this->categories([TgsCategory::content(), TgsCategory::media(), TgsCategory::layout()]);
        $this->blocks([
            Blocks\Content::class, Blocks\Picture::class, Blocks\Gallery::class,
            Blocks\Embedded::class, Blocks\Column::class, Blocks\DoubleColumn::class,
            Blocks\Accordion::class, Blocks\Collapse::class, Blocks\Tabs::class,
            Blocks\Code::class, Blocks\Popup::class, Blocks\TabItem::class, Blocks\AccordionItem::class,
        ]);
    }

    /** @internal */
    public static function keyForDescriptor(string $class): string
    {
        if (! is_subclass_of($class, TgsBlock::class)) {
            throw new InvalidArgumentException('TGS descriptor must extend the block base: '.$class);
        }

        return $class::blockKey();
    }

    /** @param list<class-string<TgsBlock>> $classes */
    public function blocks(array $classes): self
    {
        $next = $this->blocks;
        $bases = $this->baseDtos;
        $structural = $this->structural;
        foreach ($classes as $class) {
            $key = self::keyForDescriptor($class);
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $key)) {
                throw new InvalidArgumentException('Invalid TGS block key: '.$key);
            }
            $dto = $class::dataClass();
            if (! $class::make()->isChildOnlyBlock() && ($dto === null || ! is_subclass_of($dto, ResolvesFromStored::class))) {
                throw new InvalidArgumentException('TGS block needs a ResolvesFromStored DTO: '.$key);
            }
            $isStructural = $class::make()->isChildOnlyBlock();
            if (array_key_exists($key, $structural) && $structural[$key] !== $isStructural) {
                throw new InvalidArgumentException('Cannot change structural output for '.$key);
            }
            $baseDto = $bases[$key] ?? null;
            if ($baseDto !== null && ($dto === null || ! is_a($dto, $baseDto, true))) {
                throw new InvalidArgumentException('Replacement DTO must extend '.$baseDto);
            }
            if (! $isStructural && $dto !== null) {
                $type = (new ReflectionClass($dto))->getDefaultProperties()['type'] ?? null;
                $type = $type instanceof BackedEnum ? $type->value : $type;
                if ($type !== $key) {
                    throw new InvalidArgumentException('DTO type must match descriptor key: '.$key);
                }
            }

            $bases[$key] ??= $dto;
            $structural[$key] = $isStructural;
            $next[$key] = $class;
        }
        $this->blocks = $next;
        $this->baseDtos = $bases;
        $this->structural = $structural;

        return $this;
    }

    /** @param list<string> $keys */
    public function withoutBlocks(array $keys): self
    {
        foreach ($keys as $key) {
            $this->hidden[$key] = true;
        }

        return $this;
    }

    /** @return list<class-string<TgsBlock>> */
    public function all(): array
    {
        return array_values($this->blocks);
    }

    /** @return class-string<TgsBlock>|null */
    public function classFor(string $key): ?string
    {
        return $this->blocks[$key] ?? null;
    }

    public function get(string $key): ?TgsBlock
    {
        $class = $this->classFor($key);

        return $class !== null ? $class::make() : null;
    }

    public function isAvailable(string $key): bool
    {
        $class = $this->classFor($key);
        if ($class === null || isset($this->hidden[$key]) || ! $class::isAvailable()) {
            return false;
        }
        $category = $class::make()->blockCategory()?->key;

        return $category === null || ! isset($this->hiddenCategories[$category]);
    }

    /** @param list<TgsCategory> $categories */
    public function categories(array $categories): self
    {
        foreach ($categories as $category) {
            $this->categories[$category->key] = $category;
        }

        return $this;
    }

    public function category(string $key): ?TgsCategory
    {
        return $this->categories[$key] ?? null;
    }

    /** @param list<string> $keys */
    public function withoutCategories(array $keys): self
    {
        foreach ($keys as $key) {
            $this->hiddenCategories[$key] = true;
        }

        return $this;
    }

    /** @return array<string, class-string<ResolvesFromStored>> */
    public function dataClasses(): array
    {
        $map = [];
        foreach ($this->blocks as $key => $class) {
            if (! $class::make()->isChildOnlyBlock() && $class::dataClass() !== null) {
                $map[$key] = $class::dataClass();
            }
        }

        return $map;
    }
}
