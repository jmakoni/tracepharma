<?php

namespace App\Support\Mail;

class MailTemplateDefinition
{
    /**
     * @param  list<string>  $variables
     * @param  list<string>  $recipients
     * @param  array<string, string>  $fixtures
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $variables,
        public readonly array $recipients,
        public readonly string $defaultSubject,
        public readonly string $defaultGreeting,
        public readonly string $defaultBody,
        public readonly ?string $defaultSalutation,
        public readonly ?string $defaultActionLabel = null,
        public readonly ?string $defaultActionUrl = null,
        public readonly array $fixtures = [],
    ) {}
}
