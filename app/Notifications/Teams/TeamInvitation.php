<?php

namespace App\Notifications\Teams;

use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitationModel $invitation)
    {
        //
    }

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
        $team = $this->invitation->team;
        $inviter = $this->invitation->inviter;
        $hasAccount = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($this->invitation->email)])
            ->exists();

        return (new MailMessage)
            ->subject(__("You've been invited to the course :teamName", ['teamName' => $team->name]))
            ->line(__(':inviterName has invited you to teach the :teamName course on :app.', [
                'inviterName' => $inviter->name,
                'teamName' => $team->name,
                'app' => config('app.name'),
            ]))
            ->line($hasAccount
                ? __('Log in to accept or decline the invitation.')
                : __('Create your instructor account to get started.'))
            ->action(
                $hasAccount ? __('Log in') : __('Create account'),
                route($hasAccount ? 'login' : 'register', ['invitation' => $this->invitation->code]),
            )
            ->line(__('This invitation expires :date.', [
                'date' => $this->invitation->expires_at?->diffForHumans() ?? __('never'),
            ]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
