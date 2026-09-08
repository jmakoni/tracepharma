<?php

namespace App\Actions\Menus;

use App\Models\AppMenuLink;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class SyncAppMenuLinks
{
    public function handle(): int
    {
        $panel = Filament::getPanel('app');
        Filament::setCurrentPanel($panel);

        $rows = [];
        $sort = 0;

        foreach ($panel->getPages() as $pageClass) {
            $row = $this->fromPage($pageClass, $sort);
            if ($row !== null) {
                $rows[$row['key']] = $row;
                $sort++;
            }
        }

        foreach ($panel->getResources() as $resourceClass) {
            $row = $this->fromResource($resourceClass, $sort);
            if ($row !== null) {
                $rows[$row['key']] = $row;
                $sort++;
            }
        }

        $keepKeys = [];

        foreach ($rows as $row) {
            AppMenuLink::query()->updateOrCreate(
                ['key' => $row['key']],
                $row,
            );
            $keepKeys[] = $row['key'];
        }

        if ($keepKeys !== []) {
            AppMenuLink::query()->whereNotIn('key', $keepKeys)->delete();
        } else {
            AppMenuLink::query()->delete();
        }

        return count($keepKeys);
    }

    /**
     * @param  class-string<Page>  $pageClass
     * @return array<string, mixed>|null
     */
    private function fromPage(string $pageClass, int $sort): ?array
    {
        try {
            $label = $pageClass::getNavigationLabel();
            $path = $this->pathFromUrl($pageClass::getUrl(panel: 'app'));
        } catch (Throwable) {
            return null;
        }

        if ($path === null || $path === '' || $path === '/') {
            return null;
        }

        return [
            'key' => 'page:'.Str::of($pageClass)->classBasename()->kebab()->toString(),
            'label' => $label !== '' ? $label : class_basename($pageClass),
            'path' => $path,
            'icon' => $this->iconToString($pageClass::getNavigationIcon()),
            'navigation_group' => $this->enumToString($pageClass::getNavigationGroup()),
            'source_class' => $pageClass,
            'sort' => $sort,
            'enabled' => true,
        ];
    }

    /**
     * @param  class-string<Resource>  $resourceClass
     * @return array<string, mixed>|null
     */
    private function fromResource(string $resourceClass, int $sort): ?array
    {
        try {
            $label = $resourceClass::getNavigationLabel();
            $path = $this->pathFromUrl($resourceClass::getUrl(panel: 'app'));
        } catch (Throwable) {
            return null;
        }

        if ($path === null || $path === '' || $path === '/') {
            return null;
        }

        return [
            'key' => 'resource:'.Str::of($resourceClass)->classBasename()->kebab()->toString(),
            'label' => $label !== '' ? $label : class_basename($resourceClass),
            'path' => $path,
            'icon' => $this->iconToString($resourceClass::getNavigationIcon()),
            'navigation_group' => $this->enumToString($resourceClass::getNavigationGroup()),
            'source_class' => $resourceClass,
            'sort' => $sort,
            'enabled' => true,
        ];
    }

    private function pathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }

    private function iconToString(mixed $icon): ?string
    {
        if ($icon instanceof BackedEnum) {
            return (string) $icon->value;
        }

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    private function enumToString(mixed $value): ?string
    {
        if ($value instanceof UnitEnum) {
            return $value instanceof BackedEnum ? (string) $value->value : $value->name;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
