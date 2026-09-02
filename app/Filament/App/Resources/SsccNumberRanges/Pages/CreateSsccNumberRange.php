<?php

namespace App\Filament\App\Resources\SsccNumberRanges\Pages;

use App\Enums\SsccNumberRangeScope;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Filament\Notifications\Notification;
use App\Support\Labeling\SsccNumberRangeValidator;
use App\Support\Receiving\EligibleReceiveSites;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CreateSsccNumberRange extends CreateRecord
{
    protected static string $resource = SsccNumberRangeResource::class;

    public function mount(): void
    {
        parent::mount();

        $defaults = $this->defaultsFromQuery();
        if ($defaults === []) {
            return;
        }

        $this->form->fill(array_merge(
            is_array($this->form->getRawState()) ? $this->form->getRawState() : [],
            $defaults,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsFromQuery(): array
    {
        $scope = request()->query('scope');
        $siteId = request()->query('site_id');

        if ($scope !== SsccNumberRangeScope::Site->value || blank($siteId)) {
            return [];
        }

        $siteId = (int) $siteId;
        if ($siteId <= 0) {
            return [];
        }

        if (! EligibleReceiveSites::forOrganization()->whereKey($siteId)->exists()) {
            return [];
        }

        return [
            'scope' => SsccNumberRangeScope::Site->value,
            'site_id' => $siteId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data): Model {
                $validated = SsccNumberRangeValidator::normalizeAndValidate($data);

                return static::getModel()::query()->create($validated);
            });
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Invalid number range')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw new Halt;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not create number range')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw new Halt;
        }
    }
}
