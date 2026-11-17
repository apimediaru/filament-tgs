<?php

namespace ApiMedia\Tgs;

use ApiMedia\Tgs\Filament\Pages\ManageBlockLibrary;
use ApiMedia\Tgs\Filament\Pages\ManageOrphanedBlocks;
use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Support\BlockVersionDiffer;
use ApiMedia\Tgs\Support\TgsAuthorization;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use ApiMedia\Tgs\Support\TgsConfiguration;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

final class TgsPlugin implements Plugin
{
    public array $hostRegistrations = [];

    public array $blockClasses = [];

    /** @var class-string<TgsCommonSettings>|null */
    public ?string $commonSettingsClass = null;

    public array $hiddenBlocks = [];

    public array $categoryDescriptors = [];

    public array $hiddenCategories = [];

    public ?Closure $conversions = null;

    public array $permissionMap = [];

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'tgs';
    }

    public function hosts(array $hosts, bool $reusable = true, bool $staging = false): self
    {
        $this->hostRegistrations[] = [$hosts, $reusable, $staging];

        return $this;
    }

    public function commonSettings(string $class): self
    {
        if (! is_a($class, TgsCommonSettings::class, true)) {
            throw new \InvalidArgumentException('Invalid common settings: '.$class);
        }
        $this->commonSettingsClass = $class;

        return $this;
    }

    public function blocks(array $classes): self
    {
        $this->blockClasses = [...$this->blockClasses, ...$classes];

        return $this;
    }

    public function withoutBlocks(array $keys): self
    {
        $this->hiddenBlocks = [...$this->hiddenBlocks, ...$keys];

        return $this;
    }

    public function categories(array $categories): self
    {
        $this->categoryDescriptors = [...$this->categoryDescriptors, ...$categories];

        return $this;
    }

    public function withoutCategories(array $keys): self
    {
        $this->hiddenCategories = [...$this->hiddenCategories, ...$keys];

        return $this;
    }

    public function mediaConversions(Closure $callback): self
    {
        $this->conversions = $callback;

        return $this;
    }

    public function permissions(array $map): self
    {
        $this->permissionMap = $map;

        return $this;
    }

    public function register(Panel $panel): void
    {
        app(TgsConfiguration::class)->register($panel->getId(), $this);
        TgsAuthorization::permissions($this->permissionMap);
        $panel->discoverClusters(in: __DIR__.'/Filament/Clusters', for: __NAMESPACE__.'\\Filament\\Clusters')
            ->resources([CollectionResource::class])
            ->pages([ManageBlockLibrary::class, ManageOrphanedBlocks::class])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => '<style>'.app(BlockVersionDiffer::class)->styleSheet().'</style>');
    }

    public function boot(Panel $panel): void {}
}
