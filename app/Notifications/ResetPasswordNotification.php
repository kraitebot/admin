<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: true);

        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 15);

        $greetingName = filled($notifiable->name) ? $notifiable->name : null;

        $message = (new MailMessage)
            ->from(config('mail.from.address'), 'Kraite')
            ->subject('Reset your Kraite password');

        if ($greetingName) {
            $message->greeting("Hi {$greetingName},");
        } else {
            $message->greeting('Hi there,');
        }

        return $message
            ->line('You requested a password reset for your Kraite account. Click the button below to set a new password.')
            ->line("This link will expire in {$expireMinutes} minutes.")
            ->action('Reset password', $url)
            ->line("If you didn't request this, you can safely ignore this email — your password won't change.")
            ->salutation('— The Kraite team');
    }
}
