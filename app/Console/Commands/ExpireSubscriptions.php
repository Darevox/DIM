<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Illuminate\Support\Facades\Mail;
use App\Mail\SubscriptionExpiredNotice;
use App\Mail\SubscriptionExpirationNotice;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';
    protected $description = 'Send expiration notices and expire subscriptions';

    public function handle()
    {
        // Fetch subscriptions that are about to expire within the next 3 days
        $expiringSubscriptions = Subscription::where('status', 'active')
            ->whereDate('subscription_expiredDate', '>=', now())
            ->whereDate('subscription_expiredDate', '<=', now()->addDays(3))
            ->get();

        foreach ($expiringSubscriptions as $subscription) {
            \Log::info("Processing expiring subscription: {$subscription->id} for team {$subscription->team_id}");

            try {
                // Get the team associated with the subscription
                $team = $subscription->team;

                // Get the first user associated with the team
                $user = $team->users()->first();

                if ($user) {
                    // Calculate the number of days remaining until expiration
                    $daysRemaining = now()->diffInDays($subscription->subscription_expiredDate, false);

                    \Log::info("Sending expiration notice to: {$user->email}");

                    // Send the expiration notice email
                    Mail::to($user->email)->send(new SubscriptionExpirationNotice($subscription, $daysRemaining));

                    \Log::info("Expiration notice successfully sent to: {$user->email}");
                } else {
                    \Log::warning("No user found for team {$team->id}");
                }
            } catch (\Exception $e) {
                \Log::error("Error processing expiration notice for team {$subscription->team_id}: " . $e->getMessage());
            }
        }

        // Fetch subscriptions that have already expired
        $expiredSubscriptions = Subscription::where('status', 'active')
            ->whereDate('subscription_expiredDate', '<', now())
            ->get();

        foreach ($expiredSubscriptions as $subscription) {
            \Log::info("Processing expired subscription: {$subscription->id} for team {$subscription->team_id}");

            try {
                // Get the team associated with the subscription
                $team = $subscription->team;

                // Get the user associated with the team
                $user = $team->users()->first();

                if ($user) {
                    // Send expired subscription notice
                    Mail::to($user->email)->send(new SubscriptionExpiredNotice($subscription));

                    \Log::info("Expired notice successfully sent to: {$user->email}");
                } else {
                    \Log::warning("No user found for team {$team->id}");
                }

                // Update subscription status to 'expired' and deactivate the team
                $subscription->markAsExpired();
            } catch (\Exception $e) {
                \Log::error("Error processing expired subscription for team {$subscription->team_id}: " . $e->getMessage());
            }
        }

        $this->info("Processed {$expiringSubscriptions->count()} expiring subscriptions.");
        $this->info("Processed {$expiredSubscriptions->count()} expired subscriptions.");

        \Log::info("Processed {$expiringSubscriptions->count()} expiring subscriptions.");
        \Log::info("Processed {$expiredSubscriptions->count()} expired subscriptions.");

        return Command::SUCCESS;
    }
}
