<?php

namespace App\Mail;

use App\Models\Petition;
use App\Models\User;
use App\Support\Locale;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PetitionDigestMail extends Mailable implements ShouldQueue
{
    use GatedByMailQuota, Queueable, SerializesModels, WithListUnsubscribe;

    public function __construct(
        public User $user,
        public Collection $petitions
    ) {}

    public function build()
    {
        $locale = Locale::toShort($this->user->locale ?: default_locale());
        $defaultLocale = default_locale();
        $firstName = $this->user->first_name ?: $this->user->name;
        $token = $this->user->ensureUnsubscribeToken();

        $unsubscribeUrl = lroute('preferences.unsubscribe', ['locale' => $locale, 'token' => $token]);

        $items = $this->petitions->take(5)->map(function (Petition $petition) use ($locale, $defaultLocale) {
            $translation = $petition->translationOrFallback($locale, $defaultLocale);

            return [
                'id' => $petition->id,
                'title' => $translation?->title,
                'slug' => $translation?->slug,
                'locale' => $translation?->locale ?? $locale,
                'signature_count' => (int) $petition->signature_count,
            ];
        })->values();

        return $this->subject(MailText::get('petition_digest_subject', [], $locale))
            ->view('emails.petition-digest')
            ->with([
                'firstName' => $firstName,
                'items' => $items,
                'locale' => $locale,
                'emailLocale' => $locale,
                'preheader' => MailText::get('petition_digest_preheader', [], $locale),
                'eyebrow' => MailText::get('petition_digest_eyebrow', [], $locale),
                'heading' => MailText::get('petition_digest_heading', [], $locale),
                'greeting' => MailText::get('petition_digest_greeting', ['name' => $firstName], $locale),
                'intro' => MailText::get('petition_digest_intro', [], $locale),
                'signaturesLabel' => MailText::get('petition_digest_signatures', [], $locale),
                'actionLabel' => MailText::get('petition_digest_action', [], $locale),
                'footer' => MailText::get('petition_digest_footer', [], $locale),
                'unsubscribeLabel' => MailText::get('petition_digest_unsubscribe', [], $locale),
                'manageLabel' => MailText::get('common_manage_preferences', [], $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
                'preferencesUrl' => lroute('preferences.show', ['locale' => $locale]),
            ])
            ->listUnsubscribe($unsubscribeUrl);
    }
}
