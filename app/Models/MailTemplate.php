<?php

namespace App\Models;

use App\Support\Mail\MailTemplateCatalog;
use App\Support\Mail\MailTemplateDefinition;
use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $fillable = [
        'key',
        'subject',
        'greeting',
        'body',
        'salutation',
        'action_label',
        'action_url',
        'recipients',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function definition(): MailTemplateDefinition
    {
        return MailTemplateCatalog::get($this->key);
    }

    public static function syncFromCatalog(): void
    {
        foreach (MailTemplateCatalog::definitions() as $definition) {
            if (self::query()->where('key', $definition->key)->exists()) {
                continue;
            }

            self::query()->create([
                'key' => $definition->key,
                'subject' => $definition->defaultSubject,
                'greeting' => $definition->defaultGreeting,
                'body' => $definition->defaultBody,
                'salutation' => $definition->defaultSalutation,
                'action_label' => $definition->defaultActionLabel,
                'action_url' => $definition->defaultActionUrl,
                'recipients' => $definition->recipients,
                'is_active' => true,
            ]);
        }
    }
}
