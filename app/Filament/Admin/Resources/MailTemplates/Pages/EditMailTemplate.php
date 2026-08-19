<?php

namespace App\Filament\Admin\Resources\MailTemplates\Pages;

use App\Filament\Admin\Resources\MailTemplates\MailTemplateResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Admin;
use App\Models\MailTemplate;
use App\Notifications\MailTemplateTestSend;
use App\Support\Mail\ComposeDatabaseMail;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class EditMailTemplate extends EditRecord
{
    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->color('gray')
                ->modalHeading('Preview with sample data')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->fillForm(fn (): array => [
                    'preview_body' => $this->previewText(),
                ])
                ->schema([
                    Textarea::make('preview_body')
                        ->hiddenLabel()
                        ->disabled()
                        ->rows(16),
                ]),
            Action::make('sendTest')
                ->label('Send test to my email')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test email?')
                ->modalDescription(fn (): string => 'Sends this template with sample data to '.$this->adminEmail().'.')
                ->action(function (): void {
                    $email = $this->adminEmail();
                    $record = $this->getRecord();

                    if ($email === '' || ! $record instanceof MailTemplate) {
                        Notification::make()
                            ->title('No admin email on this account')
                            ->danger()
                            ->send();

                        return;
                    }

                    NotificationFacade::route('mail', $email)
                        ->notify(new MailTemplateTestSend($record->key));

                    Notification::make()
                        ->title('Test email sent')
                        ->body('Sent to '.$email.' with sample merge data.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function previewText(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof MailTemplate) {
            return '';
        }

        return app(ComposeDatabaseMail::class)->previewPlainText($record->key);
    }

    private function adminEmail(): string
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin ? (string) $admin->email : '';
    }
}
