<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Domain\Epcis\Data\ObjectEventData;
use App\Domain\Epcis\EpcisEventFactory;
use App\Domain\Epcis\Enums\EpcisAction;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pre-persist hard gate for authored ObjectEvent XML (commission / decommission / return).
 */
final class AssertAuthoredObjectEventCandidate
{
    public function __construct(
        private readonly EpcisEventFactory $factory,
        private readonly RunDomainEpcisHardGate $hardGate,
    ) {}

    /**
     * @param  list<string>  $epcList
     */
    public function handle(
        array $epcList,
        EpcisAction $action,
        string $bizStep,
        string $disposition,
        ?DateTimeImmutable $eventTimeUtc = null,
    ): ObjectEventData {
        $eventTimeUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $normalized = [];
        foreach ($epcList as $uri) {
            $uri = trim((string) $uri);
            if ($uri !== '') {
                $normalized[] = $uri;
            }
        }

        $data = $this->factory->objectEvent(
            epcList: $normalized,
            action: $action,
            bizStep: $bizStep,
            disposition: $disposition,
            eventTimeUtc: $eventTimeUtc,
        );

        $result = $this->hardGate->validateCandidate([
            [
                'event_type' => 'ObjectEvent',
                'action' => $action->value,
                'event_time' => $eventTimeUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'epc_list' => $data->epcList,
                'biz_step' => $data->bizStep,
                'disposition' => $data->disposition,
            ],
        ]);

        if ($result->isFailed()) {
            $failure = $result->failure;
            throw new InvalidArgumentException(
                $failure !== null
                    ? "[{$failure->stage}] {$failure->code}: {$failure->message}"
                    : 'Authored ObjectEvent failed Domain hard-gate validation.',
            );
        }

        return $data;
    }
}
