<?php

namespace App\Notifications;

use App\Models\Subscription;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly int $daysRemaining,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $planName = $this->subscription->plan?->name ?? 'Paket aktif';
        $periodEnd = $this->subscription->current_period_end;

        return FilamentNotification::make()
            ->title('Masa aktif paket akan berakhir')
            ->body(sprintf(
                '%s akan berakhir dalam %d hari. Perpanjang sebelum %s.',
                $planName,
                $this->daysRemaining,
                $periodEnd?->format('d M Y H:i') ?? '-',
            ))
            ->icon('heroicon-o-clock')
            ->iconColor('warning')
            ->viewData([
                'type' => 'subscription_renewal_reminder',
                'subscription_id' => $this->subscription->getKey(),
                'days_remaining' => $this->daysRemaining,
                'period_end' => $periodEnd?->toIso8601String(),
            ])
            ->getDatabaseMessage();
    }
}
