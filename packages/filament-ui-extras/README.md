# Filament UI Extras

First-party Filament v5 panel UI enhancements for TracePharma. Idiomatic Filament APIs — not a fork or copy of any third-party plugin.

## Requirements

- PHP 8.2+
- Laravel 11+ / 13
- Filament 5
- Tailwind CSS v4 panel theme (`resources/css/filament/{panel}/theme.css`)

## Install

Path package (already wired in this repo):

```bash
composer require tracepharma/filament-ui-extras:*
```

Register on a panel:

```php
use Tracepharma\FilamentUiExtras\FilamentUiExtrasPlugin;

$panel->plugin(FilamentUiExtrasPlugin::make());
```

Theme CSS (after Filament `theme.css`):

```css
@import '../../../../packages/filament-ui-extras/resources/css/filament-ui-extras.css';
@source '../../../../packages/filament-ui-extras/resources/views/**/*.blade.php';
```

Then `npm run build`.

Publish translations (optional):

```bash
php artisan vendor:publish --tag=filament-ui-extras-translations
```

Publish the dual-nav page override only if you need to customize it further:

```bash
php artisan vendor:publish --tag=filament-ui-extras-views
```

## Plugin config

```php
FilamentUiExtrasPlugin::make()
    ->loadingBar(true)            // default on
    ->defaultBackAction(true)     // default on (pages using HasBeforeHeaderActions)
    ->stickyTableActions(false)   // opt-in
    ->faviconSpinner(false);      // opt-in
```

When `stickyTableActions(true)` is enabled, the plugin injects sticky-table CSS in `HEAD_END` and JS applies `.fi-uie-sticky-table-actions` to tables. You can also import the stylesheet in the theme if you prefer Vite bundling:

```css
@import '../../../../packages/filament-ui-extras/resources/css/filament-ui-extras-sticky-table.css';
```

## Features

### 1. Dual sub-navigation

```php
use Tracepharma\FilamentUiExtras\Concerns\Pages\HasDualSubNavigation;
use Tracepharma\FilamentUiExtras\Enums\DualSubNavigationPosition;
use Filament\Navigation\NavigationItem;

class MyPage extends Page
{
    use HasDualSubNavigation;

    protected function getDualSubNavigationPosition(): DualSubNavigationPosition
    {
        return DualSubNavigationPosition::Start; // Start | Top | End
    }

    protected function getDualSubNavigationItems(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('…')),
        ];
    }
}
```

Does not replace Filament cluster/resource sub-navigation.

### 2. Before-header actions

```php
use Tracepharma\FilamentUiExtras\Concerns\Pages\HasBeforeHeaderActions;

class EditOrder extends EditRecord
{
    use HasBeforeHeaderActions;

    protected function getBeforeHeaderActions(): array
    {
        return [
            // optional extras; default Back is included unless plugin->defaultBackAction(false)
        ];
    }
}
```

### 3. Action separator

```php
use Tracepharma\FilamentUiExtras\Actions\ActionSeparator;

protected function getHeaderActions(): array
{
    return [
        Action::make('export'),
        ActionSeparator::make(),
        Action::make('delete'),
    ];
}
```

### 4–6 / 9 / 11. CSS / JS polish

Animated sidebar + chevron, notification bell swing, table `deferLoading()` skeleton, top loading bar, disabled-button shake — active when matching DOM exists.

### 7. Stats overview skeleton

```php
use Tracepharma\FilamentUiExtras\Concerns\Widgets\HasStatsOverviewSkeleton;
use Filament\Widgets\StatsOverviewWidget;

class StatsOverview extends StatsOverviewWidget
{
    use HasStatsOverviewSkeleton;

    protected function getPlaceholderStatCount(): int
    {
        return 4;
    }
}
```

Requires the widget to be lazy-loaded (`protected static bool $isLazy = true` or Filament lazy defaults).

### 8 / 10. Opt-in sticky table actions + favicon spinner

Enable on the plugin (see config above).

### 12. Select filter label controls

```php
SelectFilter::make('status')
    ->options([...])
    ->hiddenLabel()
    // or
    ->inlineLabel();
```

### 13. Inline label prefix (form fields)

```php
Select::make('status')->label('Status')->inlineLabelPrefix();
TextInput::make('q')->inlineLabelPrefix();
DatePicker::make('from')->inlineLabelPrefix();
```

Not the same as Filament’s side-column `inlineLabel()`.

## Upgrade-safety table

| Feature | Method | View override? |
|---------|--------|----------------|
| Dual sub-nav | Trait + prepended `filament-panels` page index | **Yes (isolated)** |
| Before-header actions | Trait + `PAGE_HEADER_HEADING_BEFORE` + CSS | No |
| Action separator | `ActionSeparator` | No |
| Animated sidebar + chevron | Theme CSS | No |
| Bell swing | CSS | No |
| Table skeleton | CSS on `.fi-ta.fi-loading` | No |
| Stats skeleton | Widget trait + Blade | No |
| Sticky table actions + scroll | Opt-in CSS/JS (FilamentAsset) | No |
| Loading bar | Render hook + JS (BODY hooks) | No |
| Favicon spinner | Opt-in JS (BODY_END) | No |
| Disabled button shake | JS (BODY_END) | No |
| SelectFilter labels | Macros + CSS | No |
| Inline label prefix | Macros + CSS | No |

## Filament 5 notes

- Dual sub-nav requires a page view override because Filament’s sub-nav render hooks only fire when *its* sub-nav is present.
- Before-header uses `PAGE_HEADER_HEADING_BEFORE` with flex CSS so actions sit left of the heading.
- Coexists with `zeeshantariq/filament-sticky-columns`; sticky actions here are a separate opt-in.
