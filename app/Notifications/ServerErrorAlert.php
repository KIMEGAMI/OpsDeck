<?php

namespace App\Notifications;

use App\Mail\ResendIdempotentTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

class ServerErrorAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $errorReference,
        public readonly int $statusCode,
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('OpsDeckでサーバーエラーが発生しました')
            ->greeting('OpsDeck 管理者様')
            ->line('本番環境でHTTP 5xxエラーが発生しました。')
            ->line('エラーID: '.$this->errorReference)
            ->line('詳細な調査は、サーバーのログでエラーIDを検索してください。')
            ->salutation('OpsDeck')
            ->withSymfonyMessage(function (Email $email): void {
                if ($this->idempotencyKey !== null) {
                    $email->getHeaders()->addTextHeader(ResendIdempotentTransport::IDEMPOTENCY_HEADER, $this->idempotencyKey);
                }
            });
    }
}
