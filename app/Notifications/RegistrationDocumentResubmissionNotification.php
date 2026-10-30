<?php

namespace App\Notifications;

use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationDocumentResubmissionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public RegistrationApplication $application,
        public ApplicationDocument $document,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = strtoupper((string) $this->application->user?->account_type);
        $loginRoute = in_array($type, [User::TYPE_LOGISTICS, User::TYPE_RIDER], true)
            ? 'logistics.login'
            : 'login';
        $documentName = str($this->document->document_type)->replace('_', ' ')->headline()->toString();

        return (new MailMessage)
            ->subject('Action needed: replace a LIKHAE registration document')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('A document in your LIKHAE registration needs to be replaced.')
            ->line('Document: '.$documentName)
            ->line('Reason: '.$this->document->rejection_reason)
            ->line('Sign in to upload a replacement. Your application will return to the review queue after you submit it.')
            ->action('Sign in to update your application', route($loginRoute));
    }
}
