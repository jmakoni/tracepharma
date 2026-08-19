<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Devices\Schemas\DeviceForm;
use App\Filament\App\Resources\LocationDevices\Schemas\LocationDeviceForm;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceFormCatalogPickerRemovedTest extends TestCase
{
    #[Test]
    public function device_form_has_identity_fields_and_no_catalog_pick(): void
    {
        $schema = DeviceForm::configure(Schema::make());
        $names = $this->fieldNames($schema->getComponents());

        $this->assertContains('name', $names);
        $this->assertContains('device_type', $names);
        $this->assertNotContains('_catalog_pick', $names);
        $this->assertNotContains('catalog_device_id', $names);
    }

    #[Test]
    public function location_device_form_has_placement_fields_and_no_catalog_pick(): void
    {
        $schema = LocationDeviceForm::configure(Schema::make());
        $names = $this->fieldNames($schema->getComponents());

        $this->assertContains('name', $names);
        $this->assertContains('site_id', $names);
        $this->assertNotContains('_catalog_pick', $names);
        $this->assertNotContains('catalog_location_device_id', $names);
    }

    /**
     * @param  array<int, mixed>  $components
     * @return list<string>
     */
    private function fieldNames(array $components): array
    {
        return $this->collectComponents($components)
            ->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $components
     * @return Collection<int, mixed>
     */
    private function collectComponents(array $components): Collection
    {
        return collect($components)->flatMap(function ($component) {
            $self = [$component];

            if (method_exists($component, 'getDefaultChildComponents')) {
                $children = $component->getDefaultChildComponents();
                $childArray = is_array($children) ? $children : $children->getComponents();

                return array_merge($self, $this->collectComponents($childArray)->all());
            }

            return $self;
        });
    }
}
